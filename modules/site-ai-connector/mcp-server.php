<?php
defined( 'ABSPATH' ) || exit;

function sac_mcp_tools_list( $grant ) {
    $tools = [];
    foreach ( sac_get_tool_definitions() as $tool ) {
        $scope = sac_oauth_tool_scope( $tool );
        if ( empty( $tool['available'] ) || ! in_array( $scope, sac_oauth_scopes(), true )
            || ! user_can( $grant['user_id'], sac_oauth_tool_capability( $scope ) ) ) continue;
        $properties = [];
        $required = [];
        foreach ( $tool['params'] as $name => $parameter ) {
            $properties[ $name ] = sac_parameter_schema( $parameter ) + [ 'description' => $tool['summary'] ];
            if ( ( $parameter['required'] ?? false ) === true ) $required[] = $name;
        }
        if ( $tool['need_write'] ) $properties['confirmation_token'] = [ 'type' => 'string', 'description' => '只有 WordPress 回應要求確認、且使用者在後台完成確認時才填入。' ];
        $scopes = [ $scope ];
        if ( str_starts_with( $tool['path'], '/content' ) || str_starts_with( $tool['path'], '/posts' ) ) {
            if ( in_array( $scope, [ 'seo:read', 'faq:read', 'taxonomy:read' ], true ) ) $scopes[] = 'content:read';
            if ( in_array( $scope, [ 'seo:write', 'faq:write', 'media:update' ], true ) ) $scopes[] = 'content:update';
        }
        $security = [ [ 'type' => 'oauth2', 'scopes' => $scopes ] ];
        $meta = [ 'securitySchemes' => $security ];
        if ( $tool['name'] === 'upload_media' ) $meta['openai/fileParams'] = [ 'file' ];
        $tools[] = [
            'name' => $tool['name'], 'description' => $tool['description'],
            'inputSchema' => [ 'type' => 'object', 'properties' => (object) $properties, 'required' => $required, 'additionalProperties' => false ],
            'securitySchemes' => $security, '_meta' => $meta,
            'annotations' => [
                'title' => $tool['summary'], 'readOnlyHint' => ! $tool['need_write'],
                'destructiveHint' => $tool['need_write'] && ! in_array( $tool['name'], [ 'create_content', 'create_post', 'create_upload_link', 'upload_media' ], true ),
                'idempotentHint' => ! $tool['need_write'], 'openWorldHint' => $tool['name'] === 'upload_media',
            ],
        ];
    }
    return $tools;
}

function sac_mcp_call_tool( $tool, $args, WP_REST_Request $outer ) {
    if ( ! is_array( $args ) || ( $args && array_keys( $args ) === range( 0, count( $args ) - 1 ) ) ) return sac_oauth_error( 'sac_invalid_arguments', '工具參數須為物件。' );
    $confirm = $args['confirmation_token'] ?? '';
    if ( isset( $args['confirmation_token'] ) && ( ! $tool['need_write'] || ! is_string( $confirm ) ) ) return sac_oauth_error( 'sac_invalid_arguments', '確認欄位格式錯誤。' );
    unset( $args['confirmation_token'] );
    if ( array_diff_key( $args, $tool['params'] ) ) return sac_oauth_error( 'sac_invalid_arguments', '包含未知工具參數。' );
    $path = $tool['path'];
    $path_keys = [];
    foreach ( $tool['params'] as $key => $parameter ) {
        if ( ! array_key_exists( $key, $args ) ) {
            if ( ( $parameter['required'] ?? false ) === true ) return sac_content_error( $key, '缺少工具必填參數。', 'sac_required_field' );
            continue;
        }
        $valid = rest_validate_value_from_schema( $args[ $key ], sac_parameter_schema( $parameter ), $key );
        if ( is_wp_error( $valid ) ) return sac_content_error( $key, $valid->get_error_message() );
        if ( $parameter['in'] === 'path' ) {
            if ( ! is_scalar( $args[ $key ] ) || ! preg_match( '/^[a-zA-Z0-9_-]+$/D', (string) $args[ $key ] ) ) {
                return sac_oauth_error( 'sac_invalid_path', '路徑參數格式錯誤。' );
            }
            $path = str_replace( '{' . $key . '}', (string) $args[ $key ], $path );
            $path_keys[ $key ] = true;
        }
    }
    $request = new WP_REST_Request( $tool['method'], '/' . SAC_NAMESPACE . $path );
    $request->set_header( 'authorization', $outer->get_header( 'authorization' ) );
    if ( $confirm !== '' ) $request->set_header( 'x-sac-confirmation', $confirm );
    $params = array_diff_key( $args, $path_keys );
    if ( $tool['method'] === 'GET' ) {
        $request->set_query_params( $params );
    } else {
        $request->set_header( 'content-type', 'application/json' );
        $request->set_body( wp_json_encode( (object) $params ) );
    }
    $result = rest_do_request( $request );
    $result = sac_audit_operation( $result, rest_get_server(), $request );
    if ( $result->is_error() ) return $result->as_error();
    return [ 'value' => $result->get_data(), 'operation_id' => (int) ( array_change_key_case( $result->get_headers() )['x-sac-operation-id'] ?? 0 ) ];
}

function sac_mcp_server_info() {
    return [ 'name' => 'wu-toolbox-site-ai', 'title' => get_bloginfo( 'name' ) . ' AI Connector', 'version' => WUTM_VERSION ];
}

function sac_mcp_rpc( $id, $value, $error = false, $modern = false, $status = 200 ) {
    if ( $modern && ! $error ) $value = (array) $value + [ '_meta' => [ 'io.modelcontextprotocol/serverInfo' => sac_mcp_server_info() ] ];
    return sac_oauth_response( [
        'jsonrpc' => '2.0', 'id' => $id, ( $error ? 'error' : 'result' ) => $value,
    ], $status );
}

function sac_handle_mcp_request( WP_REST_Request $request ) {
    $origin = $request->get_header( 'origin' );
    $origins = [ sac_oauth_origin( sac_oauth_resource() ) ];
    foreach ( (array) get_option( 'sac_oauth_redirects', [ 'https://chatgpt.com/connector_platform_oauth_redirect' ] ) as $redirect ) {
        $origins[] = sac_oauth_origin( $redirect );
    }
    if ( $origin && ! in_array( $origin, $origins, true ) ) return sac_oauth_error( 'sac_origin', '請求來源不受信任。', 403 );
    $grant = sac_authenticate( $request );
    if ( is_wp_error( $grant ) ) return $grant;
    if ( $request->get_method() !== 'POST' ) {
        $response = sac_oauth_response( [ 'code' => 'sac_no_stream', 'message' => '此端點採用無狀態 Streamable HTTP JSON。' ], 405 );
        $response->header( 'Allow', 'POST' );
        return $response;
    }
    $supported = [ '2025-03-26', '2025-06-18', '2025-11-25' ];
    $version = $request->get_header( 'mcp-protocol-version' );
    if ( strlen( $request->get_body() ) > 12 * MB_IN_BYTES ) return sac_oauth_error( 'sac_request_size', 'MCP 請求過大。', 413 );
    if ( ! sac_oauth_rate( 'mcp', 180, MINUTE_IN_SECONDS, (string) $grant['id'] ) ) return sac_oauth_error( 'sac_rate_limit', 'MCP 請求頻率超過限制。', 429 );
    $body = json_decode( $request->get_body(), true, 64 );
    if ( json_last_error() !== JSON_ERROR_NONE ) return sac_mcp_rpc( null, [ 'code' => -32700, 'message' => 'JSON 解析失敗。' ], true );
    $method = $body['method'] ?? null;
    $id = $body['id'] ?? null;
    $has_id = is_array( $body ) && array_key_exists( 'id', $body );
    if ( ! is_string( $method ) || ( $body['jsonrpc'] ?? '' ) !== '2.0' || ( $has_id && ! is_int( $id ) && ! is_string( $id ) ) ) {
        return sac_mcp_rpc( null, [ 'code' => -32600, 'message' => 'JSON-RPC 請求無效。' ], true );
    }
    $params = $body['params'] ?? [];
    if ( ! is_array( $params ) || ( $params && array_keys( $params ) === range( 0, count( $params ) - 1 ) ) ) return sac_mcp_rpc( $id, [ 'code' => -32602, 'message' => '參數須為物件。' ], true );
    $body_version = $params['_meta']['io.modelcontextprotocol/protocolVersion'] ?? null;
    $modern = $method === 'server/discover' || $body_version !== null || ( $version && ! in_array( $version, $supported, true ) );
    if ( $modern ) {
        $name_header = (string) $request->get_header( 'mcp-name' );
        if ( preg_match( '/^=\?base64\?([a-zA-Z0-9+\/=]+)\?=$/D', $name_header, $encoded ) ) $name_header = base64_decode( $encoded[1], true );
        if ( ! $version || $version !== $body_version || $method !== $request->get_header( 'mcp-method' )
            || ( in_array( $method, [ 'tools/call', 'prompts/get', 'resources/read' ], true ) && ( ! $name_header || $name_header !== ( $params['name'] ?? $params['uri'] ?? null ) ) ) ) {
            return sac_mcp_rpc( $id, [ 'code' => -32020, 'message' => 'HTTP 標頭與 MCP 訊息不符。' ], true, true, 400 );
        }
        if ( $version !== '2026-07-28' ) {
            return sac_mcp_rpc( $id, [ 'code' => -32022, 'message' => 'MCP 協定版本不受支援。',
                'data' => [ 'supported' => array_merge( [ '2026-07-28' ], $supported ), 'requested' => $version ] ], true, true, 400 );
        }
    }
    if ( ! $has_id ) return sac_oauth_response( null, 202 );
    sac_oauth_touch( $grant['id'] );
    if ( in_array( $method, [ 'server/discover', 'initialize' ], true ) ) update_option( 'sac_oauth_last_mcp', time(), false );
    $instructions = '先查詢目標、欄位及分類。取得使用者確認後才寫入。新增內容使用草稿。遇到 WordPress 確認連結時，請交給使用者審核。';
    if ( $method === 'server/discover' ) return sac_mcp_rpc( $id, [
        'supportedVersions' => array_merge( [ '2026-07-28' ], $supported ),
        'capabilities' => [ 'tools' => new stdClass() ], 'instructions' => $instructions,
    ], false, true );
    if ( $method === 'ping' ) return sac_mcp_rpc( $id, new stdClass(), false, $modern );
    if ( $modern && $method === 'initialize' ) return sac_mcp_rpc( $id, [ 'code' => -32601, 'message' => '請使用 server/discover。' ], true, true, 404 );
    if ( $method === 'initialize' ) {
        $asked = $params['protocolVersion'] ?? null;
        if ( ! is_string( $asked ) || ! is_array( $params['clientInfo'] ?? null ) ) return sac_mcp_rpc( $id, [ 'code' => -32602, 'message' => '缺少初始化資訊。' ], true );
        return sac_mcp_rpc( $id, [
            'protocolVersion' => in_array( $asked, $supported, true ) ? $asked : end( $supported ),
            'capabilities' => [ 'tools' => new stdClass() ],
            'serverInfo' => sac_mcp_server_info(),
            'instructions' => $instructions,
        ] );
    }
    if ( $method === 'tools/list' ) return sac_mcp_rpc( $id, [ 'tools' => sac_mcp_tools_list( $grant ) ], false, $modern );
    if ( $method !== 'tools/call' ) return sac_mcp_rpc( $id, [ 'code' => -32601, 'message' => 'MCP 方法不存在。' ], true, $modern, $modern ? 404 : 200 );
    $name = $params['name'] ?? '';
    if ( ! is_string( $name ) ) return sac_mcp_rpc( $id, [ 'code' => -32602, 'message' => '工具名稱格式錯誤。' ], true );
    $definitions = array_column( sac_get_tool_definitions(), null, 'name' );
    if ( empty( $definitions[ $name ]['available'] ) ) return sac_mcp_rpc( $id, [ 'code' => -32602, 'message' => '工具不存在或已停用。' ], true );
    $permission = sac_oauth_require_scope( $grant, sac_oauth_tool_scope( $definitions[ $name ] ) );
    if ( is_wp_error( $permission ) ) return $permission;
    $result = sac_mcp_call_tool( $definitions[ $name ], $params['arguments'] ?? [], $request );
    if ( is_wp_error( $result ) ) {
        if ( in_array( $result->get_error_data()['status'] ?? 0, [ 401, 403 ], true ) && in_array( $result->get_error_code(), [ 'invalid_token', 'insufficient_scope' ], true ) ) return $result;
        $details = [ 'success' => false, 'code' => $result->get_error_code(), 'message' => $result->get_error_message(), 'data' => null, 'warnings' => [], 'operation_id' => null ];
        $extra = $result->get_error_data();
        foreach ( [ 'field', 'id', 'review_url', 'confirmation_url', 'confirmation_token', 'confirmation_expires_at', 'confirmation_required', 'approval_status', 'change_set_id' ] as $key ) {
            if ( isset( $extra[ $key ] ) ) $details[ $key ] = $extra[ $key ];
        }
        $details['next_action'] = $details['code'] === 'sac_confirmation_required' ? 'open_wordpress_review' : ( ( $extra['status'] ?? 0 ) === 429 ? 'retry_later' : 'check_error' );
        return sac_mcp_rpc( $id, [ 'content' => [ [ 'type' => 'text', 'text' => wp_json_encode( $details, JSON_UNESCAPED_UNICODE ) ] ], 'structuredContent' => $details, 'isError' => true ], false, $modern );
    }
    $value = $result['value'];
    $details = [ 'success' => ( $value['success'] ?? true ) !== false, 'code' => $value['error_code'] ?? null,
        'message' => null, 'data' => $value, 'warnings' => [], 'operation_id' => $value['operation_id'] ?? ( $result['operation_id'] ?: null ),
        'next_action' => ! empty( $value['confirmation_required'] ) ? 'open_wordpress_review' : null ];
    return sac_mcp_rpc( $id, [ 'content' => [ [ 'type' => 'text', 'text' => wp_json_encode( $details, JSON_UNESCAPED_UNICODE ) ] ], 'structuredContent' => $details ], false, $modern );
}

add_action( 'rest_api_init', static function () {
    register_rest_route( SAC_NAMESPACE, '/mcp', [
        'methods' => [ 'GET', 'POST', 'DELETE' ], 'permission_callback' => '__return_true', 'callback' => 'sac_handle_mcp_request',
    ] );
} );

add_filter( 'rest_pre_serve_request', static function ( $served, $result, $request ) {
    return $served || ( $request->get_route() === '/' . SAC_NAMESPACE . '/mcp' && $result->get_status() === 202 );
}, 10, 3 );
