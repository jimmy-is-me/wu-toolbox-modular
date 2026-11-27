<?php
defined( 'ABSPATH' ) || exit;

function sac_oauth_touch( $id ) {
    global $wpdb;
    $table = sac_oauth_table();
    $wpdb->query( $wpdb->prepare( "UPDATE $table SET touched = %d WHERE id = %d AND touched < %d", time(), $id, time() - 30 ) );
}

function sac_audit_operation( $response, $server, $request ) {
    if ( $response->get_status() === 429 || ! sac_oauth_is_route( $request->get_route() ) ) return $response;
    if ( str_starts_with( $request->get_route(), '/' . SAC_NAMESPACE . '/oauth/' ) || $request->get_route() === '/' . SAC_NAMESPACE . '/mcp' ) return $response;
    $tool = sac_oauth_route_tool( $request );
    if ( ! $tool || $tool['name'] === 'get_operation_result' ) return $response;
    $grant = sac_authenticate( $request );
    if ( is_wp_error( $grant ) ) return $response;
    $data = $response->get_data();
    $success = ! $response->is_error() && ( $data['success'] ?? true ) !== false;
    $code = $success ? '' : sanitize_key( $data['code'] ?? 'request_failed' );
    $params = $request->get_url_params();
    $id = $params['id'] ?? ( $success ? ( $data['post_id'] ?? $data['id'] ?? $data['media_id'] ?? $data['product_id'] ?? 0 ) : ( $data['data']['id'] ?? 0 ) );
    $type = $params['post_type'] ?? '';
    if ( ! $type && str_starts_with( $tool['path'], '/posts' ) ) $type = 'post';
    if ( ! $type && str_starts_with( $tool['path'], '/media' ) ) $type = 'attachment';
    if ( ! $type && str_starts_with( $tool['path'], '/wc/products' ) ) $type = 'product';
    $record = [
        'time' => current_time( 'mysql' ), 'tool' => $tool['name'], 'target_type' => $type, 'target_id' => (string) absint( $id ),
        'success' => $success, 'failure_reason' => $code, 'grant_id' => $grant['id'], 'user_id' => $grant['user_id'],
    ];
    if ( $success ) {
        sac_oauth_touch( $grant['id'] );
        if ( $tool['need_write'] ) sac_invalidate_cache();
    }
    if ( $code === 'sac_partial_save' ) sac_invalidate_cache();
    $operation = sac_oauth_insert( 'audit', sac_oauth_random(), time() + 30 * DAY_IN_SECONDS, $record, $grant['client'], $grant['id'], $grant['user_id'] );
    if ( $operation ) $response->header( 'X-SAC-Operation-Id', (string) $operation );
    return $response;
}
add_filter( 'rest_post_dispatch', 'sac_audit_operation', 20, 3 );

function sac_oauth_operation_log() {
    global $wpdb;
    if ( get_option( 'sac_oauth_schema' ) !== '1' ) return [];
    $table = sac_oauth_table();
    $rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,payload FROM $table WHERE kind = 'audit' AND expires > %d ORDER BY id DESC LIMIT %d",
        time(), SAC_LOG_MAX ), ARRAY_A );
    return array_map( static function ( $row ) {
        return [ 'operation_id' => (int) $row['id'] ] + ( json_decode( $row['payload'], true ) ?: [] );
    }, $rows ?: [] );
}

function sac_connection_tool_definitions() {
    return [
        [
            'name' => 'verify_connection', 'group' => '網站連線', 'method' => 'GET', 'path' => '/verify',
            'summary' => '測試授權連線', 'description' => '確認網站、目前授權使用者與有效 OAuth 權限。',
            'scenario' => '完成 OAuth 授權後', 'prompt' => '請呼叫 verify_connection 並回報有效權限。',
            'need_write' => false, 'available' => true, 'params' => [],
        ],
        [
            'name' => 'get_capabilities', 'group' => '網站連線', 'method' => 'GET', 'path' => '/capabilities',
            'summary' => '查看連接器工具與版本', 'description' => '查看本站能力、工具清單雜湊及既有 OAuth 授權。',
            'scenario' => '更新後核對工具清單', 'prompt' => '請呼叫 get_capabilities 核對 MCP 能力。',
            'need_write' => false, 'available' => true, 'params' => [ 'client_tool_hash' => [ 'type' => 'string', 'in' => 'query' ] ],
        ],
        [
            'name' => 'get_operation_result', 'group' => '網站連線', 'method' => 'GET', 'path' => '/operations/{id}',
            'summary' => '查詢操作結果', 'description' => '依操作 ID 查詢目前授權下的操作結果。',
            'scenario' => '核對先前操作', 'prompt' => '使用上一次回應提供的操作 ID 查詢結果。',
            'need_write' => false, 'available' => true, 'params' => [
                'id' => [ 'type' => 'integer', 'in' => 'path', 'required' => true ],
            ],
        ],
    ];
}

add_action( 'rest_api_init', static function () {
    register_rest_route( SAC_NAMESPACE, '/capabilities', [
        'methods' => 'GET', 'permission_callback' => 'sac_permission_read',
        'callback' => static function ( $request ) {
            $grant = sac_authenticate( $request );
            $tools = sac_mcp_tools_list( $grant );
            $hash = hash( 'sha256', wp_json_encode( $tools ) );
            $client = $request->get_param( 'client_tool_hash' );
            $content = [];
            foreach ( sac_content_types() as $type ) {
                $operations = sac_content_operations( $type, $grant );
                if ( $operations ) $content[ $type->name ] = $operations;
            }
            return [ 'plugin_version' => WUTM_VERSION, 'tool_list_hash' => $hash, 'client_tool_list_current' => $client ? hash_equals( $hash, $client ) : null,
                'transport' => 'streamable_http', 'tools' => array_column( $tools, 'name' ), 'scopes' => $grant['scopes'], 'content_types' => $content,
                'features' => [ 'change_sets' => true, 'gutenberg' => true, 'frontend_comparison' => true, 'related_page_cache' => is_callable( [ 'WUTM_Page_Cache', 'purge_content' ] ) ],
                'media_transports' => [ 'https', 'base64', 'multipart_rest', 'mcp_file_reference', 'base64_chunks', 'browser_upload_link' ],
                'seo' => sac_seo_provider_info(), 'gsc' => [ 'supported' => sac_has_wumetax_seo_core(), 'enabled' => (bool) get_option( 'sac_gsc_read', false ) ],
            ];
        },
    ] );
    register_rest_route( SAC_NAMESPACE, '/verify', [
        'methods' => 'GET', 'permission_callback' => 'sac_permission_read',
        'callback' => static function ( $request ) {
            $grant = sac_authenticate( $request );
            return [
                'connected' => true, 'site' => get_bloginfo( 'name' ), 'resource' => sac_oauth_resource(),
                'user_id' => $grant['user_id'], 'scopes' => $grant['scopes'], 'grant_id' => $grant['id'],
            ];
        },
    ] );
    register_rest_route( SAC_NAMESPACE, '/operations/(?P<id>\d+)', [
        'methods' => 'GET', 'permission_callback' => 'sac_permission_read',
        'callback' => static function ( $request ) {
            $grant = sac_authenticate( $request );
            $row = sac_oauth_row( 'audit', (int) $request->get_url_params()['id'], true );
            return sac_oauth_live( $row ) && (int) $row['family'] === $grant['id'] ? $row['payload']
                : sac_oauth_error( 'sac_not_found', '找不到此授權可查閱的操作。', 404 );
        },
    ] );
} );
