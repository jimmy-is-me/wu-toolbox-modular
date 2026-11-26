<?php
defined( 'ABSPATH' ) || exit;

function sac_confirm_request( $request, $grant, $summary, $revision = '', $before = [], $post_id = 0 ) {
    $data = $request->get_params();
    unset( $data['rest_route'] );
    ksort( $data );
    $hash = hash( 'sha256', wp_json_encode( [ $request->get_route(), $data, $revision ] ) );
    $token = $request->get_header( 'x-sac-confirmation' );
    if ( $token ) {
        $row = preg_match( '/^[a-f0-9]{64}$/D', $token ) ? sac_oauth_row( 'confirm', $token ) : null;
        if ( $row && (int) $row['state'] === 1 && (int) $row['expires'] > time()
            && (int) $row['family'] === $grant['id'] && hash_equals( $row['payload']['hash'], $hash ) ) {
            global $wpdb;
            $table = sac_oauth_table();
            if ( $wpdb->query( $wpdb->prepare( "UPDATE $table SET state = 2 WHERE id = %d AND state = 1 AND expires > %d", $row['id'], time() ) ) === 1 ) return true;
        }
        return sac_oauth_error( 'sac_confirmation_invalid', '確認尚未完成、已使用，或內容已變更。請重新產生確認。', 409 );
    }
    $token = sac_oauth_random();
    // Keep a bounded plain-text preview. The hash binds every submitted value.
    $preview = wp_json_encode( [ '目前內容' => $before, '準備送出的變更' => $data ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
    if ( strlen( $preview ) > 32768 ) return sac_oauth_error( 'sac_review_too_large', '此變更超過可完整審核的長度，請拆分變更或使用 WordPress 編輯器。', 413 );
    if ( ! sac_oauth_insert( 'confirm', $token, time() + 10 * MINUTE_IN_SECONDS, [
        'hash' => $hash, 'summary' => $summary, 'preview' => $preview, 'post_id' => $post_id,
    ], $grant['client'], $grant['id'], $grant['user_id'] ) ) return sac_oauth_error( 'server_error', '無法建立確認。', 500 );
    return new WP_Error( 'sac_confirmation_required', '請由授權者在 WordPress 確認，再送出相同變更。', [
        'status' => 409, 'confirmation_token' => $token,
        'review_url' => add_query_arg( [ 'action' => 'sac_confirm_change', 'request' => $token ], admin_url( 'admin-post.php' ) ),
    ] );
}

function sac_review_lookup( $kind, $token, $require_unused = false ) {
    if ( ! is_string( $token ) || ! preg_match( '/^[a-f0-9]{64}$/D', $token ) ) return null;
    $row = sac_oauth_row( $kind, $token );
    if ( ! $row || (int) $row['expires'] <= time() || (int) $row['owner'] !== get_current_user_id()
        || ( $require_unused && (int) $row['state'] !== 0 )
        || ! sac_oauth_valid_grant( sac_oauth_row( 'grant', $row['family'], true ) )
        || ! sac_oauth_live( sac_oauth_row( 'client', $row['client'], true ) ) ) return null;
    return $row;
}

function sac_review_page_start() {
    nocache_headers();
    header( 'Referrer-Policy: no-referrer' );
    header( "Content-Security-Policy: frame-ancestors 'none'; base-uri 'none'" );
    header( 'X-Frame-Options: DENY' );
    $ready = sac_oauth_ready();
    if ( is_wp_error( $ready ) ) wp_die( esc_html( $ready->get_error_message() ), '', [ 'response' => 403 ] );
    $token = is_string( $_GET['request'] ?? null ) ? wp_unslash( $_GET['request'] ) : '';
    if ( ! preg_match( '/^[a-f0-9]{64}$/D', $token ) ) wp_die( '連結無效。', '', [ 'response' => 404 ] );
    if ( ! is_user_logged_in() ) {
        if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'GET' ) wp_die( '請重新登入。', '', [ 'response' => 403 ] );
        $action = sanitize_key( (string) ( $_GET['action'] ?? '' ) );
        wp_safe_redirect( wp_login_url( add_query_arg( [ 'action' => $action, 'request' => $token ], admin_url( 'admin-post.php' ) ) ) );
        exit;
    }
    return $token;
}

function sac_confirm_page() {
    $token = sac_review_page_start();
    $row = sac_review_lookup( 'confirm', $token, true );
    if ( ! $row ) wp_die( '此確認連結已過期、已使用，或不屬於目前帳號。', '', [ 'response' => 403 ] );
    if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) === 'POST' ) {
        check_admin_referer( 'sac_confirm_' . $token );
        global $wpdb;
        $table = sac_oauth_table();
        $decision = ( $_POST['decision'] ?? '' ) === 'approve' ? 1 : 2;
        $saved = $wpdb->query( $wpdb->prepare( "UPDATE $table SET state = %d, payload = %s WHERE id = %d AND state = 0 AND expires > %d",
            $decision, wp_json_encode( [ 'hash' => $row['payload']['hash'] ] ), $row['id'], time() ) );
        if ( $saved !== 1 ) wp_die( '確認連結無法使用。', '', [ 'response' => 409 ] );
        wp_die( $decision === 1 ? '已確認。請回到原對話繼續執行。' : '已拒絕此變更。', 'AI 連接器', [ 'response' => 200 ] );
    }
    $edit = $row['payload']['post_id'] ? get_edit_post_link( (int) $row['payload']['post_id'], 'raw' ) : '';
    $preview = '<p>' . esc_html( $row['payload']['summary'] ) . '</p>';
    if ( $edit ) $preview .= '<p><a href="' . esc_url( $edit ) . '">查看目前的 WordPress 內容</a></p>';
    $preview .= '<pre style="white-space:pre-wrap">' . esc_html( $row['payload']['preview'] ) . '</pre>';
    ob_start();
    ?>
    <form method="post">
        <?php wp_nonce_field( 'sac_confirm_' . $token ); ?>
        <button class="button" name="decision" value="deny">拒絕變更</button>
        <button class="button button-primary" name="decision" value="approve">確認變更</button>
    </form>
    <?php
    wp_die( $preview . ob_get_clean(), '確認 AI 操作', [ 'response' => 200 ] );
}
add_action( 'admin_post_sac_confirm_change', 'sac_confirm_page' );
add_action( 'admin_post_nopriv_sac_confirm_change', 'sac_confirm_page' );

function sac_content_review( $request, $target ) {
    $post = $target['post'];
    if ( ! $post ) return true;
    $publish = str_ends_with( $request->get_route(), '/publish' );
    if ( ! $publish && ! in_array( $post->post_status, [ 'publish', 'future', 'private' ], true ) ) return true;
    $grant = sac_authenticate( $request );
    if ( is_wp_error( $grant ) ) return $grant;
    $label = ( $publish ? '發佈內容' : '修改已發佈或排程內容' ) . ' · ' . $post->post_title . ' #' . $post->ID;
    $route = $request->get_route();
    $fields = $request->get_params();
    $current = [ 'status' => $post->post_status, 'title' => $post->post_title, 'slug' => $post->post_name,
        'excerpt' => $post->post_excerpt, 'content' => $post->post_content, 'parent_id' => $post->post_parent, 'author_id' => $post->post_author,
        'featured_image_id' => (int) get_post_thumbnail_id( $post ) ];
    if ( str_ends_with( $route, '/seo' ) ) {
        $current = sac_get_seo_fields( $post->ID );
    } elseif ( str_ends_with( $route, '/faq' ) ) {
        $current = [ 'faqs' => (array) get_post_meta( $post->ID, '_sac_faq_list', true ) ];
    } elseif ( array_intersect( [ 'terms', 'custom_fields' ], array_keys( $fields ) ) ) {
        $details = sac_content_result( $post, $target['user'], true );
        foreach ( [ 'terms', 'custom_fields' ] as $field ) {
            if ( is_array( $fields[ $field ] ?? null ) ) $current[ $field ] = array_intersect_key( $details[ $field ] ?? [], $fields[ $field ] );
        }
    }
    $before = $publish ? [ 'title' => $post->post_title, 'status' => $post->post_status ] : array_intersect_key( $current, $fields + [ 'status' => true ] );
    $revision = hash( 'sha256', serialize( [ $post->post_modified_gmt, $post->post_status, $current ] ) );
    return sac_confirm_request( $request, $grant, $label, $revision, $before, $post->ID );
}
