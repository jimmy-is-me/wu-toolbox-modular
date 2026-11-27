<?php
defined( 'ABSPATH' ) || exit;

function sac_oauth_authorize_parameters( $input ) {
    $params = [];
    foreach ( [ 'client_id', 'redirect_uri', 'response_type', 'resource', 'scope', 'state', 'code_challenge', 'code_challenge_method' ] as $field ) {
        if ( isset( $input[ $field ] ) && ! is_string( $input[ $field ] ) ) return [];
        $params[ $field ] = wp_unslash( $input[ $field ] ?? '' );
        if ( strlen( $params[ $field ] ) > 1400 ) return [];
    }
    return $params;
}

function sac_oauth_callback( $params, $output ) {
    $output['iss'] = sac_oauth_issuer();
    if ( $params['state'] !== '' ) $output['state'] = $params['state'];
    $uri = $params['redirect_uri'];
    $url = $uri . ( strpos( $uri, '?' ) === false ? '?' : '&' ) . http_build_query( $output, '', '&', PHP_QUERY_RFC3986 );
    wp_redirect( $url, 303, 'WU Toolbox OAuth' );
    exit;
}

function sac_oauth_create_grant( $client, $user, $params, $scopes, $types, $confirmation ) {
    global $wpdb;
    if ( $wpdb->query( 'START TRANSACTION' ) === false ) return null;
    $done = false;
    try {
        $locked_client = sac_oauth_row( 'client', $client['id'], true, true );
        if ( ! sac_oauth_live( $locked_client ) || array_diff( $scopes, sac_oauth_client_ceiling( $locked_client ) )
            || sac_oauth_row( 'consent', $confirmation )
            || ! sac_oauth_insert( 'consent', $confirmation, time() + 2 * DAY_IN_SECONDS ) ) return null;
        $grant = sac_oauth_insert( 'grant', sac_oauth_random(), time() + sac_oauth_grant_seconds(), [
            'issuer' => sac_oauth_issuer(), 'resource' => $params['resource'], 'scopes' => $scopes,
            'account' => sac_oauth_account_stamp( $user ), 'types' => $types, 'created' => time(),
        ], $client['id'], 0, $user->ID );
        $code = sac_oauth_random();
        if ( ! $grant || ! sac_oauth_insert( 'code', $code, time() + 90, [
            'challenge' => $params['code_challenge'], 'redirect_uri' => $params['redirect_uri'],
        ], $client['id'], $grant, $user->ID ) ) return null;
        if ( sac_oauth_change( $client['id'], [ 'expires' => PHP_INT_MAX ] ) === false ) return null;
        $done = $wpdb->query( 'COMMIT' ) !== false;
        return $done ? $code : null;
    } finally {
        if ( ! $done ) $wpdb->query( 'ROLLBACK' );
    }
}

function sac_oauth_grant_seconds() {
    return max( 1, min( 90, (int) get_option( 'sac_oauth_grant_days', 30 ) ) ) * DAY_IN_SECONDS;
}

function sac_oauth_consent_types( $user, $scopes ) {
    $policy = sac_site_content_policy();
    $types = [];
    foreach ( sac_content_types() as $slug => $type ) {
        $caps = [ 'read' => $type->cap->edit_posts, 'create' => $type->cap->create_posts, 'update' => $type->cap->edit_posts, 'publish' => $type->cap->publish_posts ];
        $operations = array_filter( $policy[ $slug ] ?? [], static function ( $operation ) use ( $user, $scopes, $caps ) {
            return isset( $caps[ $operation ] ) && in_array( 'content:' . $operation, $scopes, true )
                && user_can( $user, $caps[ $operation ] );
        } );
        if ( $operations ) $types[ $slug ] = array_values( $operations );
    }
    return $types;
}

// Keep the public action available through the Toolbox login guard. The handler
// still requires a registered client, valid callback, login, PKCE and consent.
add_filter( 'wutm_hidden_login_public_admin_post', static function ( $allowed, $action ) {
    return $allowed || $action === 'sac_oauth_authorize';
}, 10, 2 );

function sac_oauth_login_destination( $target ) {
    $login = wp_login_url( $target );
    $safe = is_string( $login ) ? wp_validate_redirect( $login, '' ) : '';
    $origin = $safe ? sac_oauth_origin( $safe ) : '';
    $same_endpoint = $origin === sac_oauth_origin( $target )
        && wp_parse_url( $safe, PHP_URL_PATH ) === wp_parse_url( $target, PHP_URL_PATH );
    $valid = $origin && ! $same_endpoint && ( ! is_ssl() || wp_parse_url( $safe, PHP_URL_SCHEME ) === 'https' );
    return [
        'url' => $valid ? $safe : '',
        'method' => apply_filters( 'wutm_hidden_login_active', false ) ? 'toolbox'
            : ( has_filter( 'login_url' ) ? 'wordpress_filtered' : 'wordpress' ),
    ];
}

function sac_oauth_login_return_signature( $params, $expiry ) {
    return hash_hmac( 'sha256', $expiry . ':' . wp_json_encode( $params ), wp_salt( 'nonce' ) );
}

function sac_oauth_previous_scopes( $client_id, $user_id ) {
    global $wpdb;
    $table = sac_oauth_table();
    $ids = $wpdb->get_col( $wpdb->prepare(
        "SELECT id FROM $table WHERE kind='grant' AND client=%d AND owner=%d AND state=0 AND expires>%d ORDER BY id DESC LIMIT 20",
        $client_id, $user_id, time()
    ) );
    foreach ( $ids as $id ) {
        $grant = sac_oauth_row( 'grant', $id, true );
        if ( sac_oauth_valid_grant( $grant ) ) return $grant['payload']['scopes'];
    }
    return [];
}

function sac_oauth_authorize_page() {
    nocache_headers();
    header( 'Cache-Control: no-store, private' );
    header( 'Referrer-Policy: no-referrer' );
    header( "Content-Security-Policy: frame-ancestors 'none'; base-uri 'none'" );
    header( 'X-Frame-Options: DENY' );
    $ready = sac_oauth_ready();
    if ( is_wp_error( $ready ) ) wp_die( esc_html( $ready->get_error_message() ), '', [ 'response' => $ready->get_error_data()['status'] ] );
    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    if ( ! in_array( $method, [ 'GET', 'POST' ], true ) ) wp_die( '請求方式不受支援。', '', [ 'response' => 405 ] );
    if ( ! sac_oauth_rate( 'authorize', 80, MINUTE_IN_SECONDS, (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) ) ) {
        wp_die( '授權請求次數已達上限。', '', [ 'response' => 429 ] );
    }
    $request_id = wp_generate_uuid4();
    header( 'X-SAC-Request-Id: ' . $request_id );
    $params = sac_oauth_authorize_parameters( $method === 'POST' ? $_POST : $_GET );
    $client_id = $params['client_id'] ?? '';
    $client = preg_match( '/^wutm_[a-f0-9]{64}$/D', $client_id ) ? sac_oauth_row( 'client', $client_id ) : null;
    $log = static function ( $stage, $code, $reason, $status, $details = [] ) use ( $client, $params, $request_id ) {
        sac_oauth_log_flow( $stage, $code, $reason, $client ?? [], $params['scope'] ?? '',
            $details + [ 'http_status' => $status, 'request_id' => $request_id ] );
    };
    if ( ! sac_oauth_live( $client ) || ! in_array( $params['redirect_uri'], $client['payload']['redirect_uris'], true ) ) {
        $log( 'oauth_authorize', 'invalid_request', $client ? 'redirect_uri_mismatch' : 'invalid_client', 400, [ 'redirect_validated' => false ] );
        wp_die( '客戶端或回呼網址無效。', '', [ 'response' => 400 ] );
    }
    if ( $params['response_type'] !== 'code' ) sac_oauth_callback( $params, [ 'error' => 'unsupported_response_type' ] );
    if ( $params['resource'] !== sac_oauth_resource() ) {
        $log( 'oauth_authorize', 'invalid_target', 'resource_mismatch', 303 );
        sac_oauth_callback( $params, [ 'error' => 'invalid_target' ] );
    }
    if ( $params['code_challenge_method'] !== 'S256' || ! preg_match( '/^[a-zA-Z0-9_-]{43}$/D', $params['code_challenge'] ) ) {
        $log( 'oauth_authorize', 'invalid_request', 'pkce_s256_required', 303 );
        sac_oauth_callback( $params, [ 'error' => 'invalid_request', 'error_description' => 'PKCE S256 is required.' ] );
    }
    $client_scopes = sac_oauth_client_ceiling( $client );
    $scopes = $params['scope'] === '' ? array_intersect( [ 'content:read' ], $client_scopes ) : sac_oauth_scope_array( $params['scope'] );
    if ( ! $scopes || array_diff( $scopes, $client_scopes ) ) {
        $reason = ! $scopes || array_diff( $scopes, sac_oauth_scopes() ) ? 'site_scope_restricted' : 'client_scope_restricted';
        $log( 'oauth_authorize', 'invalid_scope', $reason, 303 );
        sac_oauth_callback( $params, [ 'error' => 'invalid_scope', 'error_description' => $reason === 'client_scope_restricted'
            ? 'The site administrator must allow the requested scope for this OAuth client.' : 'The requested scope is unavailable on this site.' ] );
    }
    $return = $method === 'GET' ? ( $_GET['sac_login_return'] ?? null ) : null;
    if ( $return !== null ) {
        $valid_return = is_string( $return ) && preg_match( '/^([0-9]{1,12})\.([a-f0-9]{64})$/D', $return, $parts )
            && (int) $parts[1] >= time() && hash_equals( sac_oauth_login_return_signature( $params, $parts[1] ), $parts[2] );
        if ( ! $valid_return || ! is_user_logged_in() ) {
            $log( 'oauth_login_return', 'access_denied', $valid_return ? 'login_session_missing' : 'return_invalid_or_expired',
                403, [ 'return_verified' => false ] );
            wp_die( '無法繼續 OAuth 登入。請確認瀏覽器允許本站登入 Cookie，並由應用程式重新開始授權。', '', [ 'response' => 403 ] );
        }
        $log( 'oauth_login_return', '', 'login_return_verified', 200, [ 'return_verified' => true ] );
    }
    if ( ! is_user_logged_in() ) {
        if ( $method === 'POST' ) {
            $log( 'oauth_consent', 'access_denied', 'login_session_missing', 403 );
            wp_die( '請重新登入，並由應用程式重新開始授權。', '', [ 'response' => 403 ] );
        }
        $expiry = time() + 15 * MINUTE_IN_SECONDS;
        $return = $expiry . '.' . sac_oauth_login_return_signature( $params, $expiry );
        $target = sac_oauth_url( 'authorize' ) . '&' . http_build_query( $params + [ 'sac_login_return' => $return ], '', '&', PHP_QUERY_RFC3986 );
        $login = sac_oauth_login_destination( $target );
        if ( ! $login['url'] ) {
            $log( 'oauth_login_redirect', 'invalid_request', 'login_destination_rejected', 403, [ 'login_method' => $login['method'], 'redirect_validated' => false ] );
            wp_die( 'WordPress 登入跳轉無法使用。請站長查看「登入與 OAuth 相容性檢查」。', '', [ 'response' => 403 ] );
        }
        $log( 'oauth_login_redirect', '', 'login_redirect_sent', 302, [ 'login_method' => $login['method'], 'redirect_validated' => true ] );
        wp_safe_redirect( $login['url'], 302, 'WU Toolbox OAuth Login' );
        exit;
    }
    $user = sac_oauth_account( get_current_user_id() );
    if ( ! $user ) {
        $log( 'oauth_consent', 'access_denied', 'wordpress_account_restricted', 303 );
        sac_oauth_callback( $params, [ 'error' => 'access_denied' ] );
    }
    $types = sac_oauth_consent_types( $user, $scopes );
    $fingerprint = hash( 'sha256', wp_json_encode( $params ) );
    $action = 'sac_oauth_approve_' . $fingerprint;
    if ( $method === 'POST' ) {
        $nonce = is_string( $_POST['_wpnonce'] ?? null ) ? wp_unslash( $_POST['_wpnonce'] ) : '';
        if ( ! wp_verify_nonce( $nonce, $action ) ) {
            $log( 'oauth_consent', 'access_denied', 'consent_nonce_invalid', 403 );
            wp_die( '授權頁已逾時，請重試。', '', [ 'response' => 403 ] );
        }
        if ( ( $_POST['decision'] ?? '' ) !== 'approve' ) {
            $log( 'oauth_consent', 'access_denied', 'consent_declined', 303 );
            sac_oauth_callback( $params, [ 'error' => 'access_denied' ] );
        }
        $code = sac_oauth_create_grant( $client, $user, $params, $scopes, $types, $user->ID . ':' . $fingerprint );
        if ( ! $code ) {
            $log( 'oauth_code', 'server_error', 'code_not_issued', 303 );
            sac_oauth_callback( $params, [ 'error' => 'server_error', 'error_description' => '無法完成授權，請重新開始。' ] );
        }
        $issued = sac_oauth_row( 'code', $code );
        $log( 'oauth_code', '', 'consent_approved', 303, [
            'grant_id' => $issued['family'] ?? null, 'grant_scopes' => $scopes,
        ] );
        sac_oauth_callback( $params, [ 'code' => $code ] );
    }
    $previous = sac_oauth_previous_scopes( $client['id'], $user->ID );
    $added = array_diff( $scopes, $previous );
    $omitted = array_diff( $previous, $scopes );
    $labels = sac_oauth_scope_labels();
    $log( 'oauth_authorize', '', 'consent_shown', 200, [ 'redirect_validated' => true ] );
    $title = '授權 AI 應用程式';
    header( 'Content-Type: text/html; charset=UTF-8' );
    ?>
    <!doctype html><html lang="zh-Hant"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?php echo esc_html( $title ); ?></title>
    <style>
      body{margin:0;padding:24px;background:#f0f4fa;color:#172941;font:16px/1.55 system-ui,sans-serif}
      main{max-width:640px;margin:4vh auto;padding:30px;background:#fff;border-radius:16px;box-shadow:0 6px 24px #1731531c;overflow-wrap:anywhere}
      h1{margin:0 0 18px;font-size:24px}h2{font-size:17px;margin:20px 0 5px}
      ul{padding-inline-start:22px}button{font:inherit;padding:10px 18px;border:1px solid #244c9a;background:#fff;color:#244c9a;border-radius:8px;margin:0 6px 8px 0}
      button[value=approve]{color:#fff;background:#244c9a}img{width:44px;height:auto;vertical-align:middle;margin-inline-end:8px}
    </style></head><body><main>
    <h1><?php echo esc_html( $title ); ?></h1>
    <p><?php $icon = get_site_icon_url( 64 ); if ( $icon ) : ?><img src="<?php echo esc_url( $icon ); ?>" alt=""><?php endif; ?><?php echo esc_html( get_bloginfo( 'name' ) ); ?></p>
    <p>應用程式 <?php echo esc_html( $client['payload']['client_name'] ); ?>（<?php echo $client['payload']['dynamic'] ? '動態註冊' : '站長預先註冊'; ?>）<br>
    回呼 <?php echo esc_html( $params['redirect_uri'] ); ?><br>
    登入帳號 <?php echo esc_html( $user->user_login ); ?><br>
    授權期限 <?php echo esc_html( (string) ( sac_oauth_grant_seconds() / DAY_IN_SECONDS ) ); ?> 天</p>
    <?php if ( $previous ) : ?>
        <h2>先前已同意的權限</h2>
        <p><?php echo esc_html( implode( '、', array_intersect_key( $labels, array_flip( $previous ) ) ) ); ?></p>
        <?php if ( $omitted ) : ?><p>本次新權杖不包含 <?php echo esc_html( implode( '、', array_intersect_key( $labels, array_flip( $omitted ) ) ) ); ?>。</p><?php endif; ?>
    <?php endif; ?>
    <h2>本次請求的權限</h2>
    <ul><?php foreach ( $scopes as $scope ) : ?>
        <li><?php echo esc_html( $labels[ $scope ] ); ?><?php if ( $previous && in_array( $scope, $added, true ) ) : ?> <strong>本次新增</strong><?php endif; ?></li>
    <?php endforeach; ?></ul>
    <h2>內容類型與可用操作</h2>
    <ul><?php if ( ! $types ) : ?><li>目前帳號沒有適用的內容權限。</li><?php endif; ?>
    <?php foreach ( $types as $slug => $ops ) : ?><li><?php echo esc_html( get_post_type_object( $slug )->label . ' · ' . implode( '、', array_map( static function ( $op ) { return sac_oauth_scope_labels()[ 'content:' . $op ]; }, $ops ) ) ); ?></li><?php endforeach; ?></ul>
    <form method="post" action="<?php echo esc_url( sac_oauth_url( 'authorize' ) ); ?>">
    <?php wp_nonce_field( $action, '_wpnonce', false ); foreach ( $params as $name => $value ) : ?>
        <input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>">
    <?php endforeach; ?>
    <button name="decision" value="deny">拒絕授權</button><button name="decision" value="approve">同意授權</button>
    </form></main></body></html>
    <?php
    exit;
}
add_action( 'admin_post_sac_oauth_authorize', 'sac_oauth_authorize_page' );
add_action( 'admin_post_nopriv_sac_oauth_authorize', 'sac_oauth_authorize_page' );
