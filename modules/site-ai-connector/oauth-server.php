<?php
defined( 'ABSPATH' ) || exit;

function sac_oauth_scope_labels() {
    return [
        'content:read' => '查詢內容', 'content:create' => '建立草稿', 'content:update' => '更新內容',
        'content:publish' => '送交人工確認發佈', 'taxonomy:read' => '查詢分類', 'taxonomy:write' => '指派分類',
        'seo:read' => '查閱 SEO', 'seo:write' => '更新 SEO', 'faq:read' => '查閱 FAQ', 'faq:write' => '更新 FAQ',
        'media:read' => '查詢媒體', 'media:upload' => '上傳圖片', 'media:update' => '編輯圖片與精選圖片',
        'woo:read' => '查詢電商資料', 'woo:write' => '更新電商資料',
        'site:read' => '查詢網站工具', 'site:write' => '更新網站工具',
    ];
}

function sac_oauth_scopes() {
    $default = [ 'content:read', 'content:create', 'content:update', 'taxonomy:read', 'taxonomy:write',
        'seo:read', 'seo:write', 'faq:read', 'faq:write', 'media:read', 'media:upload', 'media:update' ];
    return array_values( array_intersect( array_keys( sac_oauth_scope_labels() ), (array) get_option( 'sac_oauth_scopes', $default ) ) );
}

function sac_oauth_scope_array( $value ) {
    return is_string( $value ) && strlen( $value ) <= 1024
        ? array_values( array_unique( preg_split( '/ +/', trim( $value ), -1, PREG_SPLIT_NO_EMPTY ) ) )
        : [];
}

function sac_oauth_resource() {
    return rest_url( SAC_NAMESPACE . '/mcp' );
}

function sac_oauth_issuer() {
    // A connector-specific issuer avoids taking over another plugin's root issuer.
    return home_url( '/wutm-site-ai' );
}

function sac_oauth_origin( $url ) {
    $parts = wp_parse_url( $url );
    if ( ! isset( $parts['scheme'], $parts['host'] ) ) return '';
    return $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
}

function sac_oauth_metadata_url( $protected = false ) {
    $url = $protected ? sac_oauth_resource() : sac_oauth_issuer();
    $kind = $protected ? 'oauth-protected-resource' : 'oauth-authorization-server';
    if ( $protected && wp_parse_url( $url, PHP_URL_QUERY ) ) {
        return rest_url( SAC_NAMESPACE . '/oauth/resource-metadata' );
    }
    return sac_oauth_origin( $url ) . '/.well-known/' . $kind . wp_parse_url( $url, PHP_URL_PATH );
}

function sac_oauth_url( $endpoint ) {
    if ( $endpoint === 'authorize' ) return add_query_arg( 'action', 'sac_oauth_authorize', admin_url( 'admin-post.php' ) );
    return rest_url( SAC_NAMESPACE . '/oauth/' . $endpoint );
}

function sac_oauth_transport() {
    if ( defined( 'WUTM_SAC_LOCAL_TEST' ) && WUTM_SAC_LOCAL_TEST && wp_get_environment_type() === 'local'
        && in_array( wp_parse_url( sac_oauth_resource(), PHP_URL_HOST ), [ 'localhost', '127.0.0.1' ], true ) ) return true;
    return is_ssl() && wp_parse_url( sac_oauth_issuer(), PHP_URL_SCHEME ) === 'https'
        && wp_parse_url( sac_oauth_resource(), PHP_URL_SCHEME ) === 'https'
        && wp_parse_url( sac_oauth_url( 'authorize' ), PHP_URL_SCHEME ) === 'https';
}

function sac_oauth_ready() {
    if ( ! sac_oauth_transport() ) return sac_oauth_error( 'invalid_request', 'OAuth 需要網站與後台使用 HTTPS。', 400 );
    if ( ! get_option( 'sac_oauth_enabled', true ) ) return sac_oauth_error( 'temporarily_unavailable', '本站已暫停 OAuth 服務。', 503 );
    if ( ! sac_oauth_install() ) return sac_oauth_error( 'temporarily_unavailable', 'OAuth 資料庫尚未就緒。', 503 );
    return true;
}

function sac_oauth_error( $code, $message, $status = 400 ) {
    return new WP_Error( $code, $message, [ 'status' => $status ] );
}

function sac_oauth_response( $data, $status = 200 ) {
    $response = new WP_REST_Response( $data, $status );
    $response->header( 'Cache-Control', 'no-store, private' );
    $response->header( 'Pragma', 'no-cache' );
    return $response;
}

function sac_oauth_endpoint_result( $result ) {
    if ( ! is_wp_error( $result ) ) return $result;
    $code = $result->get_error_code();
    $response = sac_oauth_response( [ 'error' => $code, 'error_description' => $result->get_error_message() ], $result->get_error_data()['status'] );
    if ( $code === 'invalid_client' && $response->get_status() === 401 ) $response->header( 'WWW-Authenticate', 'Basic realm="WU Toolbox OAuth"' );
    if ( $response->get_status() === 429 ) $response->header( 'Retry-After', '60' );
    return $response;
}

function sac_oauth_metadata( $protected = false ) {
    if ( $protected ) return [
        'resource' => sac_oauth_resource(), 'authorization_servers' => [ sac_oauth_issuer() ],
        'scopes_supported' => array_values( array_intersect( [ 'content:read' ], sac_oauth_scopes() ) ),
        'bearer_methods_supported' => [ 'header' ], 'resource_name' => get_bloginfo( 'name' ) . ' AI 連接器',
    ];
    $data = [
        'issuer' => sac_oauth_issuer(), 'authorization_endpoint' => sac_oauth_url( 'authorize' ),
        'token_endpoint' => sac_oauth_url( 'token' ), 'revocation_endpoint' => sac_oauth_url( 'revoke' ),
        'scopes_supported' => sac_oauth_scopes(), 'response_types_supported' => [ 'code' ],
        'grant_types_supported' => [ 'authorization_code', 'refresh_token' ],
        'token_endpoint_auth_methods_supported' => [ 'none', 'client_secret_basic', 'client_secret_post' ],
        'revocation_endpoint_auth_methods_supported' => [ 'none', 'client_secret_basic', 'client_secret_post' ],
        'code_challenge_methods_supported' => [ 'S256' ], 'authorization_response_iss_parameter_supported' => true,
    ];
    if ( get_option( 'sac_oauth_dcr', true ) ) $data['registration_endpoint'] = sac_oauth_url( 'register' );
    return $data;
}

add_action( 'parse_request', static function () {
    $path = wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );
    if ( ! is_string( $path ) || strpos( $path, '/.well-known/oauth-' ) !== 0 ) return;
    $protected = $path === wp_parse_url( sac_oauth_metadata_url( true ), PHP_URL_PATH );
    if ( ! $protected && $path !== wp_parse_url( sac_oauth_metadata_url(), PHP_URL_PATH ) ) return;
    if ( apply_filters( 'sac_oauth_external_metadata', false, $protected ) ) return;
    nocache_headers();
    header( 'X-Content-Type-Options: nosniff' );
    if ( ! in_array( $_SERVER['REQUEST_METHOD'] ?? '', [ 'GET', 'HEAD' ], true ) ) {
        header( 'Allow: GET, HEAD' );
        status_header( 405 );
        exit;
    }
    if ( ! sac_oauth_transport() || ! get_option( 'sac_oauth_enabled', true ) ) {
        status_header( 503 );
        exit;
    }
    if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) === 'HEAD' ) exit;
    wp_send_json( sac_oauth_metadata( $protected ) );
}, 20 );

function sac_oauth_client_registration( $params, $dynamic = true ) {
    $redirects = $params['redirect_uris'] ?? null;
    $name = $params['client_name'] ?? '';
    $method = $params['token_endpoint_auth_method'] ?? 'client_secret_basic';
    $scope = $params['scope'] ?? '';
    if ( ! is_array( $redirects ) || ! $redirects || count( $redirects ) > 5 || array_values( $redirects ) !== $redirects
        || ! is_string( $name ) || strlen( $name ) > 160 || ! is_string( $scope )
        || ! in_array( $method, [ 'none', 'client_secret_basic', 'client_secret_post' ], true ) ) {
        return sac_oauth_error( 'invalid_client_metadata', '客戶端設定不受支援。' );
    }
    $name = sanitize_text_field( $name );
    $allowed = (array) get_option( 'sac_oauth_redirects', [ 'https://chatgpt.com/connector_platform_oauth_redirect' ] );
    foreach ( $redirects as $uri ) {
        $parts = is_string( $uri ) ? wp_parse_url( $uri ) : false;
        $loopback = ( $parts['scheme'] ?? '' ) === 'http' && in_array( $parts['host'] ?? '', [ 'localhost', '127.0.0.1', '[::1]' ], true );
        $secure = ( $parts['scheme'] ?? '' ) === 'https';
        if ( ! $parts || strlen( $uri ) > 1000 || ( ! $secure && ! $loopback ) || empty( $parts['host'] )
            || isset( $parts['fragment'] ) || isset( $parts['user'] ) || isset( $parts['pass'] )
            || preg_match( '/[\x00-\x20\x7f\\\\]/', $uri ) || ! filter_var( $uri, FILTER_VALIDATE_URL )
            || ( $dynamic && ! in_array( $uri, $allowed, true ) ) ) {
            return sac_oauth_error( 'invalid_redirect_uri', '回呼網址未列入允許清單或格式無效。' );
        }
        parse_str( $parts['query'] ?? '', $query );
        if ( array_intersect( [ 'code', 'error', 'error_description', 'state', 'iss' ], array_keys( $query ) ) ) {
            return sac_oauth_error( 'invalid_redirect_uri', '回呼網址包含保留參數。' );
        }
    }
    if ( ( isset( $params['response_types'] ) && $params['response_types'] !== [ 'code' ] )
        || ( isset( $params['grant_types'] ) && ( ! is_array( $params['grant_types'] )
            || ! in_array( 'authorization_code', $params['grant_types'], true )
            || array_diff( $params['grant_types'], [ 'authorization_code', 'refresh_token' ] ) ) ) ) {
        return sac_oauth_error( 'invalid_client_metadata', '僅支援授權碼與更新權杖。' );
    }
    $scopes = $scope === '' ? sac_oauth_scopes() : sac_oauth_scope_array( $scope );
    if ( ! $scopes || array_diff( $scopes, sac_oauth_scopes() ) ) return sac_oauth_error( 'invalid_client_metadata', '客戶端要求的權限未開放。' );
    $client_id = 'wutm_' . sac_oauth_random();
    $secret = $method === 'none' ? '' : sac_oauth_random();
    $public = [ 'client_id' => $client_id, 'client_name' => $name ?: 'OAuth 應用程式', 'redirect_uris' => $redirects,
        'token_endpoint_auth_method' => $method, 'grant_types' => [ 'authorization_code', 'refresh_token' ],
        'response_types' => [ 'code' ], 'scope' => implode( ' ', $scopes ), 'client_id_issued_at' => time() ];
    $stored = $public + [ 'dynamic' => $dynamic, 'secret_hash' => $secret ? hash( 'sha256', $secret ) : '' ];
    $expiry = $dynamic ? time() + 7 * DAY_IN_SECONDS : PHP_INT_MAX;
    if ( ! sac_oauth_insert( 'client', $client_id, $expiry, $stored ) ) return sac_oauth_error( 'server_error', '無法儲存 OAuth 客戶端。', 500 );
    if ( $secret !== '' ) $public += [ 'client_secret' => $secret, 'client_secret_expires_at' => 0 ];
    return $public;
}

function sac_oauth_register( $request ) {
    if ( ! get_option( 'sac_oauth_dcr', true ) ) return sac_oauth_error( 'access_denied', '本站已停用動態註冊。', 403 );
    if ( strlen( $request->get_body() ) > 8192 ) return sac_oauth_error( 'invalid_client_metadata', '客戶端設定超過大小限制。', 413 );
    $ip = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
    if ( ! sac_oauth_rate( 'dcr-address', 10, HOUR_IN_SECONDS, $ip ) || ! sac_oauth_rate( 'dcr-site', 60, HOUR_IN_SECONDS ) ) {
        return sac_oauth_error( 'temporarily_unavailable', '註冊次數已達上限。', 429 );
    }
    global $wpdb;
    $table = sac_oauth_table();
    if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE kind = 'client' AND state = 0 AND expires > %d", time() ) ) >= 250 ) {
        return sac_oauth_error( 'temporarily_unavailable', '本站已達 OAuth 客戶端容量上限。', 429 );
    }
    $params = $request->get_json_params();
    if ( ! is_array( $params ) ) return sac_oauth_error( 'invalid_client_metadata', '請使用 JSON 物件。' );
    $result = sac_oauth_client_registration( $params );
    return is_wp_error( $result ) ? $result : sac_oauth_response( $result, 201 );
}

// OAuth client_secret_basic credentials are for the OAuth client, not WordPress Application Passwords.
add_filter( 'wp_is_application_passwords_available', static function ( $available ) {
    $route = untrailingslashit( $GLOBALS['wp']->query_vars['rest_route'] ?? '' );
    return in_array( $route, [ '/' . SAC_NAMESPACE . '/oauth/token', '/' . SAC_NAMESPACE . '/oauth/revoke' ], true ) ? false : $available;
} );

add_action( 'rest_api_init', static function () {
    foreach ( [
        'register' => 'sac_oauth_register', 'token' => 'sac_oauth_exchange',
        'revoke' => 'sac_oauth_revoke_token',
    ] as $path => $handler ) {
        register_rest_route( SAC_NAMESPACE, '/oauth/' . $path, [
            'methods' => 'POST', 'permission_callback' => '__return_true',
            'callback' => static function ( $request ) use ( $handler ) {
                $ready = sac_oauth_ready();
                return sac_oauth_endpoint_result( is_wp_error( $ready ) ? $ready : $handler( $request ) );
            },
        ] );
    }
    register_rest_route( SAC_NAMESPACE, '/oauth/resource-metadata', [
        'methods' => 'GET', 'permission_callback' => '__return_true',
        'callback' => static function () {
            return sac_oauth_transport() && get_option( 'sac_oauth_enabled', true )
                ? sac_oauth_response( sac_oauth_metadata( true ) )
                : sac_oauth_response( [ 'error' => 'temporarily_unavailable' ], 503 );
        },
    ] );
} );
