<?php
defined( 'ABSPATH' ) || exit;

function sac_review_max_bytes() {
    return 768 * KB_IN_BYTES;
}

function sac_review_json( $value ) {
    return wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
}

function sac_review_hash( $value ) {
    return hash( 'sha256', serialize( $value ) );
}

function sac_confirm_request( $request, $grant, $summary, $revision = '', $before = [], $post_id = 0, $legacy_revision = '' ) {
    $data = $request->get_params();
    unset( $data['rest_route'] );
    ksort( $data );
    $hash = hash( 'sha256', wp_json_encode( [ $request->get_route(), $data ] ) );
    $token = $request->get_header( 'x-sac-confirmation' );
    if ( $token ) {
        $row = preg_match( '/^[a-f0-9]{64}$/D', $token ) ? sac_oauth_row( 'confirm', $token ) : null;
        if ( ! $row || (int) $row['family'] !== $grant['id'] || (int) $row['owner'] !== $grant['user_id'] || (int) $row['client'] !== $grant['client'] ) {
            return sac_oauth_error( 'sac_confirmation_invalid', '找不到此授權的確認。', 409 );
        }
        if ( (int) $row['expires'] <= time() ) return sac_oauth_error( 'sac_confirmation_expired', '確認已過期，請重新準備變更。', 409 );
        if ( (int) $row['state'] === 2 ) return sac_oauth_error( 'sac_confirmation_already_used', '確認已使用或拒絕，請先核對目前內容。', 409 );
        if ( (int) $row['state'] !== 1 ) return sac_oauth_error( 'sac_confirmation_not_approved', '請先在 WordPress 完成審核。', 409 );
        $payload = $row['payload'];
        if ( empty( $payload['version'] ) ) $hash = hash( 'sha256', wp_json_encode( [ $request->get_route(), $data, $legacy_revision ] ) );
        if ( ! hash_equals( $payload['hash'] ?? '', $hash ) ) return sac_oauth_error( 'sac_confirmation_payload_mismatch', '送出的變更與審核內容不符。', 409 );
        if ( isset( $payload['revision'] ) && ! hash_equals( $payload['revision'], $revision ) ) {
            return sac_oauth_error( 'sac_confirmation_version_conflict', '審核中的欄位或文章狀態已變更，請重新準備及審核。', 409 );
        }
        global $wpdb;
        $table = sac_oauth_table();
        if ( $wpdb->query( $wpdb->prepare( "UPDATE $table SET state = 2 WHERE id = %d AND state = 1 AND expires > %d", $row['id'], time() ) ) === 1 ) return true;
        return sac_oauth_error( 'sac_confirmation_already_used', '確認已使用或到期。', 409 );
    }
    $token = sac_oauth_random();
    $expires = time() + 10 * MINUTE_IN_SECONDS;
    $preview = sac_review_json( [ $before, $data ] );
    if ( $preview === false || strlen( $preview ) > 2 * sac_review_max_bytes() || strlen( sac_review_json( $data ) ?: '' ) > sac_review_max_bytes() ) {
        return sac_oauth_error( 'sac_review_too_large', '此變更超過審核大小上限，請拆分變更或使用區塊操作。', 413 );
    }
    if ( ! sac_oauth_rate( 'prepare_review', 20, HOUR_IN_SECONDS, (string) $grant['id'] ) ) {
        return sac_oauth_error( 'sac_rate_limit', '審核建立過於頻繁，請稍後重試。', 429 );
    }
    if ( ! sac_oauth_insert( 'confirm', $token, $expires, [
        'version' => 2, 'hash' => $hash, 'revision' => $revision, 'summary' => $summary, 'before' => $before, 'after' => $data, 'post_id' => $post_id,
    ], $grant['client'], $grant['id'], $grant['user_id'] ) ) return sac_oauth_error( 'server_error', '無法建立確認。', 500 );
    $url = add_query_arg( [ 'action' => 'sac_confirm_change', 'request' => $token ], admin_url( 'admin-post.php' ) );
    return new WP_Error( 'sac_confirmation_required', '請由授權者在 WordPress 確認，再送出相同變更。', [
        'status' => 409, 'confirmation_token' => $token, 'review_url' => $url, 'confirmation_url' => $url,
        'confirmation_required' => true, 'confirmation_expires_at' => gmdate( DATE_ATOM, $expires ), 'approval_status' => 'pending',
    ] );
}

/** Serialize connector writes to the same post without holding a transaction open through WP hooks. */
function sac_post_write_lock( $post_id ) {
    global $wpdb;
    $name = 'sac:' . substr( hash( 'sha256', DB_NAME . ':' . $wpdb->posts . ':' . $post_id ), 0, 56 );
    if ( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 1)', $name ) ) !== '1' ) {
        return sac_oauth_error( 'sac_content_busy', '此內容正在套用另一筆變更，請稍後重試。', 409 );
    }
    return $name;
}

function sac_post_write_unlock( $name ) {
    global $wpdb;
    $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
}

function sac_legacy_review_lock( $request, $name = null ) {
    static $locks;
    $locks ??= new WeakMap();
    if ( $name !== null ) $locks[ $request ] = $name;
    return $locks[ $request ] ?? null;
}

add_filter( 'rest_request_after_callbacks', static function ( $response, $handler, $request ) {
    $lock = sac_legacy_review_lock( $request );
    if ( $lock ) sac_post_write_unlock( $lock );
    return $response;
}, 101, 3 );

function sac_review_render_value( $before, $after ) {
    $values = array_map( static function ( $value ) {
        return is_string( $value ) ? $value : wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
    }, [ $before, $after ] );
    if ( $before === $after ) return '<p>' . esc_html__( '值相同', 'wu-toolbox-modular' ) . '</p>';
    if ( max( strlen( $values[0] ), strlen( $values[1] ) ) <= 4000 ) {
        $diff = wp_text_diff( $values[0], $values[1] );
        if ( $diff ) return $diff;
    }
    // For large values, collapse the identical prefix and suffix without dropping intervening changes.
    $prefix = strspn( $values[0] ^ $values[1], "\0" );
    while ( $prefix > 0 && ( isset( $values[0][ $prefix ] ) && ( ord( $values[0][ $prefix ] ) & 0xC0 ) === 0x80
        || isset( $values[1][ $prefix ] ) && ( ord( $values[1][ $prefix ] ) & 0xC0 ) === 0x80 ) ) $prefix--;
    $left = substr( $values[0], $prefix );
    $right = substr( $values[1], $prefix );
    $suffix = strspn( strrev( $left ) ^ strrev( $right ), "\0" );
    while ( $suffix > 0 && ( ord( $left[ strlen( $left ) - $suffix ] ) & 0xC0 ) === 0x80 ) $suffix--;
    if ( $suffix ) {
        $left = substr( $left, 0, -$suffix );
        $right = substr( $right, 0, -$suffix );
    }
    $values = [ $left, $right ];
    $html = $prefix || $suffix ? '<p>' . esc_html__( '省略前後相同內容。以下顯示完整的變更區段。', 'wu-toolbox-modular' ) . '</p>' : '';
    foreach ( [ '移除或變更前', '新增或變更後' ] as $index => $label ) {
        // Split on UTF-8 characters so no part of the proposed value is omitted.
        $parts = preg_split( '/(?<=\G.{6000})/us', (string) $values[ $index ], -1, PREG_SPLIT_NO_EMPTY );
        if ( ! $parts ) $parts = [ (string) $values[ $index ] ];
        foreach ( $parts as $part => $text ) {
            $html .= '<details><summary>' . esc_html( $label . ( count( $parts ) > 1 ? ' ' . ( $part + 1 ) . '/' . count( $parts ) : '' ) )
                . '</summary><pre style="white-space:pre-wrap;overflow-wrap:anywhere;max-height:28rem;overflow:auto">' . _wp_specialchars( $text, ENT_QUOTES, false, true ) . '</pre></details>';
        }
    }
    return $html;

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
            $decision, wp_json_encode( array_intersect_key( $row['payload'], array_flip( [ 'hash', 'version', 'revision' ] ) ) ), $row['id'], time() ) );
        if ( $saved !== 1 ) wp_die( '確認連結無法使用。', '', [ 'response' => 409 ] );
        wp_die( $decision === 1 ? '已確認。請回到原對話繼續執行。' : '已拒絕此變更。', 'AI 連接器', [ 'response' => 200 ] );
    }
    $edit = $row['payload']['post_id'] ? get_edit_post_link( (int) $row['payload']['post_id'], 'raw' ) : '';
    $preview = '<p>' . esc_html( $row['payload']['summary'] ) . '</p>';
    if ( $edit ) $preview .= '<p><a href="' . esc_url( $edit ) . '">查看目前的 WordPress 內容</a></p>';
    if ( isset( $row['payload']['preview'] ) ) $preview .= '<pre style="white-space:pre-wrap">' . esc_html( $row['payload']['preview'] ) . '</pre>';
    foreach ( $row['payload']['after'] ?? [] as $key => $value ) {
        if ( in_array( $key, [ 'id', 'post_type' ], true ) ) continue;
        $preview .= '<details><summary>' . esc_html( $key ) . '</summary>' . sac_review_render_value( $row['payload']['before'][ $key ] ?? null, $value ) . '</details>';
    }
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
    if ( $request->get_header( 'x-sac-confirmation' ) && ! sac_legacy_review_lock( $request ) ) {
        $lock = sac_post_write_lock( $post->ID );
        if ( is_wp_error( $lock ) ) return $lock;
        sac_legacy_review_lock( $request, $lock );
        clean_post_cache( $post->ID );
        $post = get_post( $post->ID );
        if ( ! $post || $post->post_type !== $target['type']->name || ! sac_content_can_access_post( $target['user'], $target['type'], $post, 'update' ) ) {
            return sac_oauth_error( 'sac_confirmation_version_conflict', '此內容已變更或無法編輯。', 409 );
        }
    }
    $publish = str_ends_with( $request->get_route(), '/publish' );
    if ( ! $request->get_header( 'x-sac-confirmation' ) && ! $publish && ! in_array( $post->post_status, [ 'publish', 'future', 'private' ], true ) ) return true;
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
    $legacy_revision = hash( 'sha256', serialize( [ $post->post_modified_gmt, $post->post_status, $current ] ) );
    $selected = $before;
    if ( isset( $fields['media_id'] ) ) $selected['media_id'] = $before['media_id'] = (int) get_post_thumbnail_id( $post );
    if ( str_ends_with( $route, '/seo' ) ) $selected['provider'] = $current['source'] ?? '';
    foreach ( [ 'seo_title' => 'title', 'seo_description' => 'description', 'focus_keyword' => 'focus_keyword' ] as $input => $field ) {
        if ( array_key_exists( $input, $fields ) && ! str_ends_with( $route, '/seo' ) ) {
            $seo = $seo ?? sac_get_seo_fields( $post->ID );
            $selected[ $input ] = $before[ $input ] = $seo[ $field ] ?? null;
        }
    }
    // Publication approves the current content as a whole. Other reviews bind only the fields they touch.
    $revision = sac_review_hash( [ $post->post_status, $publish ? $legacy_revision : $selected ] );
    return sac_confirm_request( $request, $grant, $label, $revision, $before, $post->ID, $legacy_revision );
}
