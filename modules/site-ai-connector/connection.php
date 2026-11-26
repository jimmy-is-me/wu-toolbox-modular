<?php
defined( 'ABSPATH' ) || exit;

function sac_connection_tool_definitions() {
    return [ [
        'name' => 'verify_connection', 'group' => '網站連線', 'method' => 'GET', 'path' => '/verify',
        'summary' => '測試連線', 'description' => '由 AI 平台呼叫以驗證本站金鑰。填入後台提供的驗證碼可回報驗證結果。此工具不讀取或修改網站內容。',
        'scenario' => '新增連線或更換金鑰之後', 'prompt' => '請呼叫 verify_connection 並回報連線結果。',
        'need_write' => false, 'available' => true, 'params' => [
            'challenge' => [ 'type' => 'string', 'in' => 'query', 'description' => '從後台取得的短效驗證碼。' ],
        ],
    ] ];
}

function sac_handle_connection_check( WP_REST_Request $request ) {
    $challenge = $request->get_param( 'challenge' );
    $id = sac_audit_key_id( sac_request_key( $request ) );
    if ( $challenge !== null ) {
        $pending = get_transient( 'sac_verify_' . $id );
        if ( ! is_string( $challenge ) || ! is_array( $pending ) || ! hash_equals( $pending['challenge'], $challenge ) ) {
            return new WP_Error( 'sac_verification_expired', '驗證碼錯誤或已過期。請回「連線設定」取得新驗證碼，並確認平台使用所選的金鑰。', [ 'status' => 400 ] );
        }
        $verified = get_option( 'sac_platform_verification', [] );
        $verified[ $id ][ $pending['platform'] ] = current_time( 'mysql' );
        update_option( 'sac_platform_verification', $verified, false );
        delete_transient( 'sac_verify_' . $id );
    }
    return [ 'connected' => true, 'site' => get_bloginfo( 'name' ), 'level' => sac_authenticate( $request ), 'verified' => $challenge !== null ];
}

add_action( 'rest_api_init', static function () {
    register_rest_route( SAC_NAMESPACE, '/verify', [
        'methods' => 'GET', 'permission_callback' => 'sac_permission_read', 'callback' => 'sac_handle_connection_check',
    ] );
} );
