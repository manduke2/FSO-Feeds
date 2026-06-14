    <?php

    if ( ! defined( 'GITHUB_REPOSITORY_DISPATCH_MAX_RETRIES' ) ) {
        define( 'GITHUB_REPOSITORY_DISPATCH_MAX_RETRIES', 3 );
    }

    if ( ! defined( 'GITHUB_REPOSITORY_DISPATCH_RETRY_DELAY' ) ) {
        define( 'GITHUB_REPOSITORY_DISPATCH_RETRY_DELAY', 300 );
    }

    if ( ! defined( 'GITHUB_REPOSITORY_DISPATCH_RETRY_BACKOFF_MULTIPLIER' ) ) {
        define( 'GITHUB_REPOSITORY_DISPATCH_RETRY_BACKOFF_MULTIPLIER', 2 );
    }

    // Hook into a native WordPress action to send outbound data
    add_action( 'publish_post', 'send_post', 10, 2 );
    add_action( 'github_repository_dispatch_retry', 'github_repository_dispatch_retry', 10, 1 );

    function send_post( $post_id, $post ) {
        $post = get_post( $post_id );
        if ( ! $post || wp_is_post_revision( $post->ID ) ) {
            return;
        }

        github_repository_dispatch_send( $post );
    }

    function github_repository_dispatch_retry( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post || wp_is_post_revision( $post->ID ) ) {
            return;
        }

        github_repository_dispatch_send( $post );
    }

    function github_repository_dispatch_send( $post ) {
        if ( wp_is_post_revision( $post->ID ) ) {
            return;
        }

        $GH_PAT = pantheon_get_secret( 'GITHUB_PAT' );
        if ( ! $GH_PAT ) {
            error_log( sprintf( 'GitHub repository dispatch missing PAT for post %d', $post->ID ) );
            return;
        }

        $dispatch_version = $post->post_modified_gmt ?: $post->post_date_gmt;
        if ( ! $dispatch_version ) {
            return;
        }

        $last_dispatched = get_post_meta( $post->ID, '_github_repository_dispatch_last_modified', true );
        if ( $last_dispatched && $last_dispatched === $dispatch_version ) {
            return;
        }

        $target_url = 'https://api.github.com/repos/manduke2/fso-feeds/dispatches'; // Destination URL
        $category_names = wp_get_post_categories( $post->ID, array( 'fields' => 'names' ) );
        $payload = array(
            'event_type'    => 'publish_post',
            'client_payload' => array(
                'title'      => get_the_title( $post->ID ),
                'url'        => get_permalink( $post->ID ),
                'author'     => get_the_author_meta( 'display_name', $post->post_author ),
                'excerpt'    => get_the_excerpt( $post->ID ),
                'content'    => $post->post_content,
                'date'       => get_post_time( 'c', true, $post->ID ),
                'categories' => $category_names,
            )
        );

        // Utilize WordPress core HTTP APIs for reliable delivery
        $response = wp_remote_post( $target_url, array(
            'method'    => 'POST',
            'timeout'   => 15,
            'headers'   => array(
                'Content-Type'     => 'application/json',
                'X-Source-Website' => get_bloginfo( 'url' ),
                'Authorization'    => 'Bearer ' . $GH_PAT,
                'Accept'           => 'application/vnd.github+json'
            ),
            'body'      => wp_json_encode( $payload ),
            'data_format' => 'body'
        ) );

        $response_code = wp_remote_retrieve_response_code( $response );
        if ( ! is_wp_error( $response ) && $response_code >= 200 && $response_code < 300 ) {
            update_post_meta( $post->ID, '_github_repository_dispatch_last_modified', $dispatch_version );
            delete_post_meta( $post->ID, '_github_repository_dispatch_retry_count' );
            delete_post_meta( $post->ID, '_github_repository_dispatch_last_failure_note' );
            return;
        }

        if ( is_wp_error( $response ) ) {
            $failure_note = $response->get_error_message();
        } else {
            $failure_note = sprintf( 'HTTP %s: %s', $response_code, wp_remote_retrieve_body( $response ) );
        }
        $failure_note = wp_trim_words( $failure_note, 80, '...' );
        update_post_meta( $post->ID, '_github_repository_dispatch_last_failure_note', $failure_note );

        $retry_count = (int) get_post_meta( $post->ID, '_github_repository_dispatch_retry_count', true );
        $should_retry = github_repository_dispatch_should_retry( $response, $response_code );

        if ( $should_retry && $retry_count < GITHUB_REPOSITORY_DISPATCH_MAX_RETRIES ) {
            $retry_count++;
            update_post_meta( $post->ID, '_github_repository_dispatch_retry_count', $retry_count );

            $retry_delay = (int) ( GITHUB_REPOSITORY_DISPATCH_RETRY_DELAY * pow( GITHUB_REPOSITORY_DISPATCH_RETRY_BACKOFF_MULTIPLIER, $retry_count - 1 ) );

            if ( ! wp_next_scheduled( 'github_repository_dispatch_retry', array( $post->ID ) ) ) {
                wp_schedule_single_event( time() + $retry_delay, 'github_repository_dispatch_retry', array( $post->ID ) );
                error_log( sprintf( 'GitHub repository dispatch retry #%d scheduled for post %d in %d seconds: %s', $retry_count, $post->ID, $retry_delay, $failure_note ) );
                return;
            }

            error_log( sprintf( 'GitHub repository dispatch retry already scheduled for post %d: %s', $post->ID, $failure_note ) );
            return;
        }

        error_log( sprintf( 'GitHub repository dispatch final failure for post %d: %s', $post->ID, $failure_note ) );
    }

    function github_repository_dispatch_should_retry( $response, $response_code ) {
        if ( is_wp_error( $response ) ) {
            return true;
        }

        if ( 429 === $response_code ) {
            return true;
        }

        if ( $response_code >= 500 && $response_code < 600 ) {
            return true;
        }

        return false;
    }
