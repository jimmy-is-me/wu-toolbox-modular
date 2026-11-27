<?php
defined( 'ABSPATH' ) || exit;

function sac_mcp_definitions() {
    $tools = array_column( sac_get_tool_definitions(), null, 'name' );
    $prepare = $tools['prepare_content_changes'];
    $validate = $prepare;
    $validate['name'] = 'validate_content_changes';
    $validate['summary'] = $validate['description'] = '唯讀驗證整合式變更與缺少的權限，不建立審核。';
    $validate['need_write'] = false;
    unset( $validate['params']['dry_run'] );
    $tools[$validate['name']] = $validate;

    $terms = $prepare;
    $terms['name'] = 'prepare_content_terms_changes';
    $terms['summary'] = $terms['description'] = '準備文章或自訂內容的分類變更，交由 WordPress 人工審核。整合式變更請使用 prepare_content_changes。';
    $terms['params'] = array_intersect_key( $prepare['params'], array_flip( [ 'post_type', 'id', 'expected_status', 'idempotency_key', 'purpose', 'content' ] ) );
    $terms['params']['idempotency_key']['required'] = true;
    $terms['params']['content']['required'] = true;
    $terms['params']['content']['properties'] = array_intersect_key( $prepare['params']['content']['properties'], [ 'terms' => true ] );
    $terms['params']['content']['required_properties'] = [ 'terms' ];
    $tools[$terms['name']] = $terms;
    return $tools;
}

function sac_mcp_tools_list( $grant, $definitions = null ) {
    $tools = [];
    foreach ( $definitions ?? sac_mcp_definitions() as $tool ) {
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
        if ( $tool['name'] === 'prepare_content_changes' ) $scopes = sac_change_scope_policy()['base'];
        if ( $tool['name'] === 'prepare_content_terms_changes' ) {
            $policy = sac_change_scope_policy();
            $scopes = array_merge( $policy['base'], $policy['by_field']['content.terms'] );
            $properties['content']['required'] = $tool['params']['content']['required_properties'];
            unset( $properties['content']['required_properties'] );
        }
        if ( array_diff( $scopes, sac_oauth_scopes() ) ) continue;
        $security = [ [ 'type' => 'oauth2', 'scopes' => $scopes ] ];
        $meta = [ 'securitySchemes' => $security ];
        if ( $tool['name'] === 'upload_media' ) $meta['openai/fileParams'] = [ 'file' ];
        $tools[] = [
            'name' => $tool['name'], 'description' => $tool['description'] . ( $tool['name'] === 'prepare_content_changes' ? ' 正式準備需要 content:read、content:update，依提交欄位追加分類、SEO、FAQ 與媒體 Scope。先用 dry_run=true 檢查。若需追加授權，應用程式應以原權限加上授權挑戰要求的 Scope，重新取得使用者明確同意後再重試。' : '' ),
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

function sac_mcp_validation_schema( $tool, $name, $parameter ) {
    // The Change Set preflight collects nested failures with complete paths.
    if ( in_array( $tool['name'], [ 'prepare_content_changes', 'validate_content_changes' ], true ) && in_array( $name, [ 'content', 'seo', 'media', 'faqs' ], true ) ) return [ 'type' => $parameter['type'] ];
    $schema = sac_parameter_schema( $parameter );
    if ( isset( $parameter['required_properties'] ) ) {
        $schema['required'] = $parameter['required_properties'];
        unset( $schema['required_properties'] );
    }
    return $schema;
}

function sac_mcp_call_tool( $tool, $args, WP_REST_Request $outer ) {
    if ( ! is_array( $args ) || ( $args && array_keys( $args ) === range( 0, count( $args ) - 1 ) ) ) return sac_oauth_error( 'sac_invalid_arguments', '工具參數須為物件。' );
    $confirm = $args['confirmation_token'] ?? '';
    if ( isset( $args['confirmation_token'] ) && ( ! $tool['need_write'] || ! is_string( $confirm ) ) ) return sac_oauth_error( 'sac_invalid_arguments', '確認欄位格式錯誤。' );
    unset( $args['confirmation_token'] );
    if ( $unknown = array_diff_key( $args, $tool['params'] ) ) return sac_content_error( (string) array_key_first( $unknown ), '包含未知工具參數。', 'sac_invalid_arguments' );
    $path = $tool['path'];
    $path_keys = [];
    foreach ( $tool['params'] as $key => $parameter ) {
        if ( ! array_key_exists( $key, $args ) ) {
            if ( ( $parameter['required'] ?? false ) === true ) return sac_content_error( $key, '缺少工具必填參數。', 'sac_required_field' );
            continue;
        }
        $valid = rest_validate_value_from_schema( $args[ $key ], sac_mcp_validation_schema( $tool, $key, $parameter ), $key );
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
    sac_authenticate( $request, sac_authenticate( $outer ) );
    if ( $confirm !== '' ) $request->set_header( 'x-sac-confirmation', $confirm );
    $params = array_diff_key( $args, $path_keys );
    if ( $tool['name'] === 'validate_content_changes' ) $params['dry_run'] = true;
    if ( $tool['method'] === 'GET' ) {
        $request->set_query_params( $params );
    } else {
        $request->set_header( 'content-type', 'application/json' );
        $encoded = sac_review_json( (object) $params );
        if ( $encoded === false ) return sac_oauth_error( 'sac_invalid_arguments', '無法編碼工具參數。' );
        $request->set_body( $encoded );
    }
    $actor = get_current_user_id();
    try {
        $result = rest_do_request( $request );
    } catch ( Throwable $error ) {
        wp_set_current_user( $actor );
        $lock = sac_legacy_review_lock( $request );
        if ( $lock ) sac_post_write_unlock( $lock );
        return new WP_Error( 'sac_internal_error', 'WordPress 無法完成工具操作。請以請求編號檢查診斷。', [
            'status' => 500, 'exception_type' => $error instanceof TypeError ? 'TypeError' : ( $error instanceof Error ? 'Error' : 'Exception' ),
        ] );
    }
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
    sac_mcp_diagnostic_context( $request, [ 'grant_id' => $grant['id'], 'scope_report' => $grant['scope_report'] ?? [] ] );
    if ( $request->get_method() !== 'POST' ) {
        $response = sac_oauth_response( [ 'code' => 'sac_no_stream', 'message' => '此端點採用無狀態 Streamable HTTP JSON。' ], 405 );
        $response->header( 'Allow', 'POST' );
        return $response;
    }
    $supported = [ '2025-03-26', '2025-06-18', '2025-11-25' ];
    $version = $request->get_header( 'mcp-protocol-version' );
    if ( strlen( $request->get_body() ) > 12 * MB_IN_BYTES ) return new WP_Error( 'sac_request_size', 'MCP 請求過大。', [ 'status' => 413, 'max_request_bytes' => 12 * MB_IN_BYTES ] );
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
    if ( $method === 'tools/list' ) return sac_mcp_rpc( $id, [ 'tools' => sac_mcp_tools_list( $grant ) ] + ( $modern ? [ 'resultType' => 'complete' ] : [] ), false, $modern );
    if ( $method !== 'tools/call' ) return sac_mcp_rpc( $id, [ 'code' => -32601, 'message' => 'MCP 方法不存在。' ], true, $modern, $modern ? 404 : 200 );
    $name = $params['name'] ?? '';
    if ( ! is_string( $name ) ) return sac_mcp_rpc( $id, [ 'code' => -32602, 'message' => '工具名稱格式錯誤。' ], true );
    $definitions = sac_mcp_definitions();
    if ( empty( $definitions[ $name ]['available'] ) ) return sac_mcp_rpc( $id, [ 'code' => -32602, 'message' => '工具不存在或已停用。' ], true );
    $listed = sac_mcp_tools_list( $grant, [ $definitions[$name] ] );
    sac_mcp_diagnostic_context( $request, [ 'tool' => $name, 'tool_declared_scopes' => $listed[0]['securitySchemes'][0]['scopes'] ?? [] ] );
    $permission = sac_oauth_require_scope( $grant, sac_oauth_request_tool_scope( $definitions[ $name ], $params['arguments']['dry_run'] ?? null ) );
    $result = is_wp_error( $permission ) ? $permission : sac_mcp_call_tool( $definitions[ $name ], $params['arguments'] ?? [], $request );
    if ( is_wp_error( $result ) ) {
        $scope_report = $result->get_error_data()['scope_report'] ?? [];
        if ( $scope_report ) sac_mcp_diagnostic_context( $request, [ 'scope_report' => $scope_report + ( $grant['scope_report'] ?? [] ) ] );
        if ( $result->get_error_code() === 'insufficient_scope' ) {
            $scope = $result->get_error_data()['scope'] ?? '';
            $challenge = 'Bearer resource_metadata="' . esc_url_raw( sac_oauth_metadata_url( true ) )
                . '", error="insufficient_scope", error_description="Additional OAuth scopes are required", scope="' . $scope . '"';
            $response = sac_mcp_rpc( $id, [
                'content' => [ [ 'type' => 'text', 'text' => $result->get_error_message() ] ],
                'structuredContent' => [ 'code' => 'insufficient_scope', 'scope_report' => $scope_report ],
                'isError' => true, '_meta' => [ 'mcp/www_authenticate' => [ $challenge ] ],
            ], false, $modern, 403 );
            $response->header( 'WWW-Authenticate', $challenge );
            return $response;
        }
        if ( $result->get_error_code() === 'invalid_token' ) return $result;
        $details = [ 'success' => false, 'code' => $result->get_error_code(), 'message' => $result->get_error_message(), 'data' => null, 'warnings' => [], 'operation_id' => null ];
        $extra = $result->get_error_data();
        foreach ( [ 'field', 'id', 'review_url', 'confirmation_url', 'confirmation_token', 'confirmation_expires_at', 'confirmation_required', 'approval_status', 'change_set_id', 'max_request_bytes', 'actual_status', 'scope_report' ] as $key ) {
            if ( isset( $extra[ $key ] ) ) $details[ $key ] = $extra[ $key ];
        }
        $details['tool_status'] = $extra['status'] ?? 400;
        sac_mcp_diagnostic_context( $request, $details + [ 'exception_type' => $extra['exception_type'] ?? null ] );
        $details['next_action'] = $scope_report['next_action']
            ?? ( $details['code'] === 'sac_confirmation_required' ? 'open_wordpress_review' : ( ( $extra['status'] ?? 0 ) === 429 ? 'retry_later' : 'check_error' ) );
        return sac_mcp_rpc( $id, [ 'content' => [ [ 'type' => 'text', 'text' => wp_json_encode( $details, JSON_UNESCAPED_UNICODE ) ] ], 'structuredContent' => $details, 'isError' => true ] + ( $modern ? [ 'resultType' => 'complete' ] : [] ), false, $modern );
    }
    $value = $result['value'];
    $details = [ 'success' => ( $value['success'] ?? true ) !== false, 'code' => $value['error_code'] ?? null,
        'message' => null, 'data' => $value, 'warnings' => $value['warnings'] ?? [], 'operation_id' => $value['operation_id'] ?? ( $result['operation_id'] ?: null ),
        'next_action' => $value['next_action'] ?? ( ! empty( $value['confirmation_required'] ) ? 'open_wordpress_review' : null ) ];
    return sac_mcp_rpc( $id, [ 'content' => [ [ 'type' => 'text', 'text' => wp_json_encode( $details, JSON_UNESCAPED_UNICODE ) ] ], 'structuredContent' => $details, 'isError' => ! $details['success'] ] + ( $modern ? [ 'resultType' => 'complete' ] : [] ), false, $modern );
}

add_action( 'rest_api_init', static function () {
    register_rest_route( SAC_NAMESPACE, '/mcp', [
        'methods' => [ 'GET', 'POST', 'DELETE' ], 'permission_callback' => '__return_true', 'callback' => 'sac_mcp_diagnostic_dispatch',
    ] );
} );

add_filter( 'rest_pre_serve_request', static function ( $served, $result, $request ) {
    return $served || ( $request->get_route() === '/' . SAC_NAMESPACE . '/mcp' && $result->get_status() === 202 );
}, 10, 3 );
