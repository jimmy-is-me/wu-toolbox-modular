<?php
defined( 'ABSPATH' ) || exit;

function sac_oauth_tool_scope( $tool ) {
    $name = $tool['name'];
    $path = $tool['path'];
    $write = $tool['need_write'];
    if ( str_starts_with( $path, '/wc/' ) ) return $write ? 'woo:write' : 'woo:read';
    if ( str_contains( $name, 'faq' ) ) return $write ? 'faq:write' : 'faq:read';
    if ( str_contains( $name, 'seo' ) || $name === 'get_content_sitemap_status' ) return $write ? 'seo:write' : 'seo:read';
    if ( $name === 'list_content_terms' ) return 'taxonomy:read';
    if ( in_array( $name, [ 'set_featured_image', 'set_content_featured_image' ], true ) || $name === 'update_media_alt' || $name === 'update_media' ) return 'media:update';
    if ( $name === 'upload_media' || $name === 'create_upload_link' ) return 'media:upload';
    if ( str_starts_with( $path, '/media' ) || $name === 'check_upload_link' ) return 'media:read';
    if ( in_array( explode( '/', $path )[1], [ 'menu', 'redirects', 'form-submissions' ], true ) ) return $write ? 'site:write' : 'site:read';
    if ( $name === 'publish_content' ) return 'content:publish';
    if ( ! $write ) return 'content:read';
    return str_starts_with( $name, 'create_' ) ? 'content:create' : 'content:update';
}

function sac_oauth_route_tool( $request ) {
    $path = substr( $request->get_route(), strlen( '/' . SAC_NAMESPACE ) );
    $method = $request->get_method() === 'HEAD' ? 'GET' : $request->get_method();
    foreach ( sac_get_tool_definitions() as $tool ) {
        if ( $tool['method'] !== $method ) continue;
        $parts = preg_split( '/\{[^}]+\}/', $tool['path'] );
        if ( preg_match( '~^' . implode( '[^/]+', array_map( static function ( $part ) { return preg_quote( $part, '~' ); }, $parts ) ) . '$~D', $path ) ) return $tool;
    }
    return null;
}

function sac_oauth_tool_capability( $scope ) {
    if ( str_starts_with( $scope, 'woo:' ) ) return 'manage_woocommerce';
    if ( str_starts_with( $scope, 'site:' ) ) return 'manage_options';
    if ( str_starts_with( $scope, 'media:' ) ) return 'upload_files';
    return 'read';
}

function sac_oauth_require_scope( $grant, $scope ) {
    if ( ! in_array( $scope, sac_oauth_scopes(), true ) ) {
        return new WP_Error( 'sac_scope_disabled', '本站尚未開放此操作。請由管理員查核設定。', [ 'status' => 403, 'scope' => $scope ] );
    }
    if ( ! in_array( $scope, $grant['scopes'], true ) ) {
        return new WP_Error( 'insufficient_scope', '此授權缺少操作所需的權限。', [ 'status' => 403, 'scope' => $scope ] );
    }
    if ( ! user_can( $grant['user_id'], sac_oauth_tool_capability( $scope ) ) ) {
        return sac_oauth_error( 'sac_capability', '登入帳號沒有執行這項操作的權限。', 403 );
    }
    return true;
}

/** Every REST entry shares this authorization, including internal MCP dispatch. */
function sac_authenticate( WP_REST_Request $request, $verified_browser_grant = null ) {
    static $cache;
    $cache ??= new WeakMap();
    if ( $verified_browser_grant !== null ) return $cache[ $request ] = $verified_browser_grant;
    if ( isset( $cache[ $request ] ) ) return $cache[ $request ];
    $ready = sac_oauth_ready();
    if ( is_wp_error( $ready ) ) return $ready;
    if ( ! sac_check_ip_allowlist() ) return sac_oauth_error( 'sac_ip_blocked', '來源 IP 未列入本站允許清單。', 403 );
    return $cache[ $request ] = sac_oauth_access( $request );
}

function sac_permission_read( WP_REST_Request $request ) {
    $grant = sac_authenticate( $request );
    if ( is_wp_error( $grant ) ) return $grant;
    $limit = str_ends_with( $request->get_route(), '/media/upload' ) ? 12 * MB_IN_BYTES : sac_review_max_bytes();
    if ( strlen( $request->get_body() ) > $limit ) {
        return new WP_Error( 'sac_request_too_large', '請求超過大小上限，請拆分變更。', [ 'status' => 413, 'max_request_bytes' => $limit ] );
    }
    $tool = sac_oauth_route_tool( $request );
    if ( ! $tool || empty( $tool['available'] ) ) return sac_oauth_error( 'sac_tool_disabled', '此工具目前不可用。', 403 );
    if ( ! sac_oauth_rate( 'tool', 180, MINUTE_IN_SECONDS, (string) $grant['id'] ) ) {
        return sac_oauth_error( 'sac_rate_limit', '請求頻率超過限制。', 429 );
    }
    $scope = sac_oauth_tool_scope( $tool );
    $allowed = sac_oauth_require_scope( $grant, $scope );
    if ( is_wp_error( $allowed ) ) return $allowed;
    $path = (array) $request->get_url_params();
    foreach ( $path as $key => $expected ) {
        $received = $request->get_param( $key );
        if ( ! is_scalar( $received ) || (string) $received !== (string) $expected ) {
            return sac_oauth_error( 'sac_content_type_mismatch', '網址與請求欄位的目標不符。', 409 );
        }
    }
    foreach ( $tool['params'] as $name => $parameter ) {
        if ( $parameter['in'] === 'path' || ! $request->has_param( $name ) ) continue;
        $valid = rest_validate_value_from_schema( $request->get_param( $name ), sac_mcp_validation_schema( $tool, $name, $parameter ), $name );
        if ( is_wp_error( $valid ) ) return sac_content_error( $name, $valid->get_error_message() );
    }
    if ( ! $tool['need_write'] ) return true;
    if ( str_starts_with( $scope, 'woo:' ) && isset( $request->get_url_params()['id'] ) ) {
        $id = (int) $request->get_url_params()['id'];
        $expected = str_starts_with( $tool['path'], '/wc/coupons' ) ? 'shop_coupon' : 'product';
        $cap = str_ends_with( $tool['path'], '/delete' ) ? 'delete_post' : 'edit_post';
        if ( $cap === 'delete_post' && ! EMPTY_TRASH_DAYS ) return sac_oauth_error( 'sac_trash_disabled', '本站未啟用垃圾桶，請從後台處理。', 403 );
        if ( get_post_type( $id ) !== $expected || ! user_can( $grant['user_id'], $cap, $id ) ) {
            return sac_oauth_error( 'sac_capability', '授權帳號無法操作此項目。', 403 );
        }
    }
    if ( $scope === 'woo:write' || $scope === 'site:write' ) {
        return sac_confirm_request( $request, $grant, $tool['summary'] );
    }
    return true;
}

function sac_permission_write( WP_REST_Request $request ) {
    return sac_permission_read( $request );
}

function sac_oauth_is_route( $route ) {
    return str_starts_with( $route, '/' . SAC_NAMESPACE . '/' );
}

add_filter( 'rest_request_before_callbacks', static function ( $response, $handler, $request ) {
    if ( $response !== null || ! sac_oauth_is_route( $request->get_route() )
        || $request->get_route() === '/' . SAC_NAMESPACE . '/mcp'
        || str_starts_with( $request->get_route(), '/' . SAC_NAMESPACE . '/oauth/' ) ) return $response;
    $grant = sac_authenticate( $request );
    if ( is_wp_error( $grant ) ) return $grant;
    // A REST cookie, if present, must never lend its capabilities to the token.
    $original = get_current_user_id();
    wp_set_current_user( $grant['user_id'] );
    sac_oauth_request_actor( $request, $original );
    return $response;
}, 10, 3 );

function sac_oauth_request_actor( $request, $original = null ) {
    static $actors;
    $actors ??= new WeakMap();
    if ( $original !== null ) return $actors[ $request ] = $original;
    return $actors[ $request ] ?? null;
}

add_filter( 'rest_request_after_callbacks', static function ( $response, $handler, $request ) {
    $original = sac_oauth_request_actor( $request );
    if ( $original !== null ) wp_set_current_user( $original );
    return $response;
}, 100, 3 );

add_filter( 'rest_post_dispatch', static function ( $response, $server, $request ) {
    if ( ! sac_oauth_is_route( $request->get_route() ) ) return $response;
    $response->header( 'Cache-Control', 'no-store, private' );
    $response->header( 'Pragma', 'no-cache' );
    $response->header( 'X-Content-Type-Options', 'nosniff' );
    if ( $response->get_status() === 429 ) $response->header( 'Retry-After', '60' );
    if ( str_starts_with( $request->get_route(), '/' . SAC_NAMESPACE . '/oauth/' ) ) return $response;
    $data = $response->get_data();
    if ( $response->get_status() === 401 || ( $response->get_status() === 403 && ( $data['code'] ?? '' ) === 'insufficient_scope' ) ) {
        $header = 'Bearer resource_metadata="' . esc_url_raw( sac_oauth_metadata_url( true ) ) . '"';
        $code = $data['code'] ?? '';
        if ( in_array( $code, [ 'invalid_token', 'insufficient_scope' ], true ) ) $header .= ', error="' . $code . '"';
        $scope = $data['data']['scope'] ?? ( in_array( 'content:read', sac_oauth_scopes(), true ) ? 'content:read' : '' );
        if ( $code === 'insufficient_scope' ) {
            $grant = sac_authenticate( $request );
            if ( ! is_wp_error( $grant ) ) $scope = implode( ' ', array_unique( array_merge( $grant['scopes'], explode( ' ', $scope ) ) ) );
        }
        if ( $scope ) $header .= ', scope="' . $scope . '"';
        $response->header( 'WWW-Authenticate', $header );
    }
    return $response;
}, 10, 3 );
