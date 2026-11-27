<?php
defined( 'ABSPATH' ) || exit;

function sac_oauth_registered_scopes( $client ) {
    return sac_oauth_scope_array( $client['payload']['scope'] ?? '' );
}

function sac_oauth_client_ceiling( $client ) {
    if ( ( $client['payload']['scope_policy'] ?? '' ) === 'site' ) return sac_oauth_scopes();
    return array_values( array_intersect( sac_oauth_registered_scopes( $client ), sac_oauth_scopes() ) );
}

function sac_oauth_scope_report( $client, $consented = [], $effective = null, $required = [] ) {
    $site = sac_oauth_scopes();
    $ceiling = sac_oauth_client_ceiling( $client );
    $effective ??= array_values( array_intersect( $consented, $ceiling ) );
    $missing = array_values( array_diff( $required, $effective ) );
    $disabled = array_values( array_diff( $required, $site ) );
    $blocked = array_values( array_diff( array_intersect( $required, $site ), $ceiling ) );
    return [
        'client_id' => $client['payload']['client_id'] ?? null, 'site_allowed_scopes' => $site,
        'client_registered_scopes' => sac_oauth_registered_scopes( $client ), 'client_allowed_scopes' => $ceiling,
        'grant_scopes' => $consented, 'access_scopes' => $effective, 'required_scopes' => $required,
        'missing_scopes' => $missing, 'site_disabled_scopes' => $disabled, 'client_blocked_scopes' => $blocked,
        'next_action' => $disabled ? 'check_site_policy' : ( $blocked ? 'update_client_scopes' : ( $missing ? 'authorize_required_scope' : null ) ),
    ];
}

/** Only site admins may update an existing client's registration ceiling. Grant rows are untouched. */
function sac_oauth_add_client_scopes( $id, $requested ) {
    if ( ! current_user_can( 'manage_options' ) ) return sac_oauth_error( 'access_denied', '需要站長權限。', 403 );
    $add = is_array( $requested ) && count( array_filter( $requested, 'is_string' ) ) === count( $requested ) ? array_values( array_intersect( sac_oauth_scopes(), $requested ) ) : [];
    if ( ! $add || count( $add ) !== count( $requested ) ) return sac_oauth_error( 'invalid_scope', '只可追加本站開放的權限。' );
    global $wpdb;
    if ( $wpdb->query( 'START TRANSACTION' ) === false ) return sac_oauth_error( 'server_error', '無法儲存客戶端設定。', 500 );
    $committed = false;
    try {
        $client = sac_oauth_row( 'client', $id, true, true );
        if ( ! sac_oauth_live( $client ) || ( $client['payload']['scope_policy'] ?? '' ) === 'site' ) {
            return sac_oauth_error( 'invalid_client', '此客戶端已失效或已採用本站權限政策。' );
        }
        $payload = $client['payload'];
        $payload['scope'] = implode( ' ', array_unique( array_merge( sac_oauth_registered_scopes( $client ), $add ) ) );
        $payload['scope_policy'] = 'explicit';
        if ( sac_oauth_change( $id, [ 'payload' => wp_json_encode( $payload ) ] ) === false ) {
            return sac_oauth_error( 'server_error', '無法儲存客戶端設定。', 500 );
        }
        $committed = $wpdb->query( 'COMMIT' ) !== false;
        return $committed ? true : sac_oauth_error( 'server_error', '無法完成客戶端設定。', 500 );
    } finally {
        if ( ! $committed ) $wpdb->query( 'ROLLBACK' );
    }
}

/** Store structured outcomes only. Never pass request parameters or credentials here. */
function sac_oauth_log_flow( $stage, $code, $reason, $client = [], $requested = '', $details = [] ) {
    if ( get_option( 'sac_oauth_schema' ) !== '1' || ! sac_oauth_rate( 'oauth_scope_diagnostics', 60, HOUR_IN_SECONDS ) ) return;
    $scope = array_values( array_intersect( array_keys( sac_oauth_scope_labels() ), sac_oauth_scope_array( $requested ) ) );
    $record = [
        'request_id' => $details['request_id'] ?? wp_generate_uuid4(), 'time' => gmdate( DATE_ATOM ), 'stage' => $stage,
        'wordpress_code' => $code, 'reason' => $reason, 'authorization_requested_scopes' => $scope,
        'grant_id' => isset( $details['grant_id'] ) ? (int) $details['grant_id'] : null,
        'http_status' => isset( $details['http_status'] ) ? (int) $details['http_status'] : null,
        'oauth_error' => $code ?: null,
        'logged_in' => is_user_logged_in(),
        'hidden_login' => (bool) apply_filters( 'wutm_hidden_login_active', false ),
        'login_url_filter' => (bool) has_filter( 'login_url' ),
    ] + sac_oauth_scope_report( $client, $details['grant_scopes'] ?? [], null, $scope );
    $record += array_intersect_key( $details, array_flip( [ 'login_method', 'return_verified', 'redirect_validated' ] ) );
    sac_oauth_insert( 'mcpdiag', sac_oauth_random(), time() + 3 * DAY_IN_SECONDS, $record );
}
