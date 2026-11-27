<?php
defined( 'ABSPATH' ) || exit;

function sac_oauth_account( $id ) {
    $user = get_userdata( (int) $id );
    if ( ! $user || ! is_user_member_of_blog( $user->ID ) || ! user_can( $user, 'read' )
        || ! empty( $user->spam ) || ! empty( $user->deleted )
        || ! apply_filters( 'sac_oauth_account_allowed', true, $user ) ) return null;
    return $user;
}

function sac_oauth_account_stamp( $user ) {
    $caps = $user->allcaps;
    ksort( $caps );
    return hash_hmac( 'sha256', serialize( [ $user->user_pass, $caps ] ), wp_salt( 'auth' ) );
}

function sac_oauth_valid_grant( $grant ) {
    if ( ! sac_oauth_live( $grant ) || ( $grant['payload']['issuer'] ?? '' ) !== sac_oauth_issuer()
        || ( $grant['payload']['resource'] ?? '' ) !== sac_oauth_resource() ) return null;
    $user = sac_oauth_account( $grant['owner'] );
    return $user && hash_equals( $grant['payload']['account'], sac_oauth_account_stamp( $user ) ) ? $user : null;
}

function sac_oauth_auth_client( $request ) {
    $body = $request->get_body_params();
    $auth = (string) $request->get_header( 'authorization' );
    $id = $body['client_id'] ?? '';
    $secret = $body['client_secret'] ?? '';
    $method = isset( $body['client_secret'] ) ? 'client_secret_post' : 'none';
    if ( $auth !== '' ) {
        $basic = preg_match( '/^Basic ([a-zA-Z0-9+\/=]+)$/iD', $auth, $matches ) ? base64_decode( $matches[1], true ) : false;
        $pair = $basic === false ? [] : explode( ':', $basic, 2 );
        if ( count( $pair ) !== 2 || isset( $body['client_secret'] )
            || ( $id !== '' && $id !== urldecode( $pair[0] ) ) ) return sac_oauth_error( 'invalid_client', '客戶端認證失敗。', 401 );
        [ $id, $secret ] = array_map( 'urldecode', $pair );
        $method = 'client_secret_basic';
    }
    $client = is_string( $id ) && preg_match( '/^wutm_[a-f0-9]{64}$/D', $id ) ? sac_oauth_row( 'client', $id ) : null;
    if ( ! sac_oauth_live( $client ) || $client['payload']['token_endpoint_auth_method'] !== $method || ! is_string( $secret )
        || ( $method !== 'none' && ! hash_equals( $client['payload']['secret_hash'], hash( 'sha256', $secret ) ) ) ) {
        return sac_oauth_error( 'invalid_client', '客戶端認證失敗。', 401 );
    }
    return $client;
}

function sac_oauth_issue( $grant, $scopes ) {
    $expires = min( time() + max( 60, min( HOUR_IN_SECONDS, (int) get_option( 'sac_oauth_access_seconds', 900 ) ) ), (int) $grant['expires'] );
    $access = sac_oauth_random();
    $refresh = sac_oauth_random();
    if ( ! sac_oauth_insert( 'access', $access, $expires, [ 'scopes' => $scopes ], $grant['client'], $grant['id'], $grant['owner'] )
        || ! sac_oauth_insert( 'refresh', $refresh, $grant['expires'], [ 'scopes' => $scopes ], $grant['client'], $grant['id'], $grant['owner'] ) ) {
        return sac_oauth_error( 'server_error', '無法建立權杖。', 500 );
    }
    return sac_oauth_response( [
        'access_token' => $access, 'refresh_token' => $refresh, 'token_type' => 'Bearer',
        'expires_in' => max( 0, $expires - time() ), 'scope' => implode( ' ', $scopes ),
    ] );
}

function sac_oauth_exchange( $request ) {
    if ( strlen( $request->get_body() ) > 8192 ) return sac_oauth_error( 'invalid_request', '請求超過大小限制。', 413 );
    if ( ! sac_oauth_rate( 'token', 180, MINUTE_IN_SECONDS, (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) ) ) {
        return sac_oauth_error( 'temporarily_unavailable', '請求次數已達上限。', 429 );
    }
    $body = $request->get_body_params();
    $mode = $body['grant_type'] ?? '';
    if ( ! in_array( $mode, [ 'authorization_code', 'refresh_token' ], true ) ) {
        return sac_oauth_error( 'unsupported_grant_type', '不支援此授權方式。' );
    }
    if ( ( $mode !== 'refresh_token' || array_key_exists( 'resource', $body ) ) && ( $body['resource'] ?? '' ) !== sac_oauth_resource() ) return sac_oauth_error( 'invalid_target', '資源網址不符。' );
    $client = sac_oauth_auth_client( $request );
    if ( is_wp_error( $client ) ) return $client;
    $code_flow = $mode === 'authorization_code';
    $kind = $code_flow ? 'code' : 'refresh';
    $secret = $body[ $code_flow ? 'code' : 'refresh_token' ] ?? '';
    $invalid = sac_oauth_error( 'invalid_grant', '授權碼或更新權杖無效。' );
    if ( ! is_string( $secret ) || ! preg_match( '/^[a-f0-9]{64}$/D', $secret ) ) return $invalid;

    global $wpdb;
    if ( $wpdb->query( 'START TRANSACTION' ) === false ) return sac_oauth_error( 'server_error', '無法驗證授權。', 500 );
    $committed = false;
    try {
        $locked_client = sac_oauth_row( 'client', $client['id'], true, true );
        if ( ! sac_oauth_live( $locked_client ) ) return $invalid;
        $credential = sac_oauth_row( $kind, $secret, false, true );
        if ( ! $credential || $credential['client'] !== $client['id'] || (int) $credential['expires'] <= time() ) return $invalid;
        $grant = sac_oauth_row( 'grant', $credential['family'], true, true );
        if ( ! sac_oauth_valid_grant( $grant ) || $grant['client'] !== $client['id'] ) return $invalid;
        if ( (int) $credential['state'] !== 0 ) {
            if ( ! $code_flow && sac_oauth_change( $grant['id'], [ 'state' => 1 ] ) === 1 ) {
                $committed = $wpdb->query( 'COMMIT' ) !== false;
                if ( ! $committed ) return sac_oauth_error( 'server_error', '無法撤銷重複使用的授權。', 500 );
            }
            return $invalid;
        }
        if ( $code_flow ) {
            $verifier = $body['code_verifier'] ?? '';
            $redirect = $body['redirect_uri'] ?? '';
            if ( ! is_string( $verifier ) || ! preg_match( '/^[a-zA-Z0-9._~-]{43,128}$/D', $verifier )
                || ! hash_equals( $credential['payload']['challenge'], rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ) )
                || $redirect !== $credential['payload']['redirect_uri'] ) return $invalid;
        }
        $scopes = $code_flow ? $grant['payload']['scopes'] : $credential['payload']['scopes'];
        $allowed = array_values( array_intersect( $scopes, sac_oauth_scopes() ) );
        if ( isset( $body['scope'] ) ) {
            $requested = sac_oauth_scope_array( $body['scope'] );
            if ( ! $requested || array_diff( $requested, $allowed ) ) return sac_oauth_error( 'invalid_scope', '無法在交換時擴大權限。' );
            $allowed = $requested;
        }
        if ( ! $allowed ) return sac_oauth_error( 'invalid_scope', '授權範圍已撤銷。' );
        if ( sac_oauth_change( $credential['id'], [ 'state' => 1 ] ) !== 1 ) return sac_oauth_error( 'server_error', '無法消耗憑證。', 500 );
        $result = sac_oauth_issue( $grant, $allowed );
        if ( is_wp_error( $result ) ) return $result;
        $committed = $wpdb->query( 'COMMIT' ) !== false;
        if ( $committed ) update_option( 'sac_oauth_last_exchange', time(), false );
        return $committed ? $result : sac_oauth_error( 'server_error', '無法完成交換。', 500 );
    } finally {
        if ( ! $committed ) $wpdb->query( 'ROLLBACK' );
    }
}

function sac_oauth_revoke_token( $request ) {
    if ( strlen( $request->get_body() ) > 8192 || ! sac_oauth_rate( 'revoke', 120, MINUTE_IN_SECONDS, (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) ) ) {
        return sac_oauth_error( 'temporarily_unavailable', '請求超過限制。', 429 );
    }
    $client = sac_oauth_auth_client( $request );
    if ( is_wp_error( $client ) ) return $client;
    $token = $request->get_body_params()['token'] ?? '';
    if ( ! is_string( $token ) || $token === '' ) return sac_oauth_error( 'invalid_request', '缺少權杖。' );
    $row = sac_oauth_row( 'refresh', $token ) ?: sac_oauth_row( 'access', $token );
    if ( $row && $row['client'] === $client['id'] && sac_oauth_revoke( 'grant', $row['family'] ) === false ) {
        return sac_oauth_error( 'server_error', '無法撤銷授權。', 500 );
    }
    return sac_oauth_response( new stdClass() );
}

function sac_oauth_access( WP_REST_Request $request ) {
    $header = $request->get_header( 'authorization' );
    if ( ! preg_match( '/^Bearer +([a-f0-9]{64})$/iD', trim( (string) $header ), $matches ) ) {
        return sac_oauth_error( $header ? 'invalid_token' : 'sac_authorization_required', '請透過 OAuth 授權連線。', 401 );
    }
    $access = sac_oauth_row( 'access', $matches[1] );
    if ( $access && (int) $access['expires'] <= time() ) {
        return new WP_Error( 'invalid_token', 'Access Token 已到期，請使用 Refresh Token。', [ 'status' => 401, 'reason' => 'access_expired' ] );
    }
    $grant = sac_oauth_live( $access ) ? sac_oauth_row( 'grant', $access['family'], true ) : null;
    $user = sac_oauth_valid_grant( $grant );
    if ( ! $user || $access['client'] !== $grant['client'] || ! sac_oauth_live( sac_oauth_row( 'client', $grant['client'], true ) ) ) {
        return sac_oauth_error( 'invalid_token', '授權已過期或撤銷。', 401 );
    }
    return [
        'id' => (int) $grant['id'], 'client' => (int) $grant['client'], 'user_id' => $user->ID,
        'scopes' => array_values( array_intersect( $access['payload']['scopes'], $grant['payload']['scopes'], sac_oauth_scopes() ) ),
        'content_scope' => $grant['payload']['types'],
    ];
}
