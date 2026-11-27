<?php
defined( 'ABSPATH' ) || exit;

function sac_mcp_diagnostic_context( $request, $data = [] ) {
    static $contexts;
    $contexts ??= new WeakMap();
    if ( $data ) $contexts[ $request ] = array_replace( $contexts[ $request ] ?? [], $data );
    return $contexts[ $request ] ?? [];
}

function sac_mcp_diagnostic_field( $path ) {
    if ( ! is_string( $path ) ) return null;
    $parts = explode( '.', $path, 3 );
    $top = [ 'content', 'seo', 'media', 'faqs', 'expected_status', 'block_edits', 'idempotency_key' ];
    if ( ! in_array( $parts[0], $top, true ) ) return null;
    $public = [ 'status', 'title', 'excerpt', 'content', 'slug', 'parent_id', 'author_id', 'terms', 'custom_fields', 'featured_image_id',
        'description', 'focus_keyword', 'canonical', 'noindex', 'nofollow', 'og_title', 'og_description', 'og_image_id', 'id', 'alt_text', 'caption' ];
    if ( isset( $parts[1] ) && ( in_array( $parts[1], $public, true ) || ( $parts[0] === 'faqs' && preg_match( '/^\d{1,3}$/D', $parts[1] ) ) ) ) {
        return $parts[0] . '.' . $parts[1] . ( isset( $parts[2] ) ? '.*' : '' );
    }
    return $parts[0];
}

function sac_mcp_diagnostic_dispatch( $request ) {
    $started = microtime( true );
    $request_id = wp_generate_uuid4();
    try {
        $response = rest_ensure_response( sac_handle_mcp_request( $request ) );
    } catch ( Throwable $error ) {
        sac_mcp_diagnostic_context( $request, [ 'exception_type' => $error instanceof TypeError ? 'TypeError' : ( $error instanceof Error ? 'Error' : 'Exception' ) ] );
        $response = rest_convert_error_to_response( sac_oauth_error( 'sac_internal_error', '無法處理 MCP 請求。請以請求編號核對診斷。', 500 ) );
    }
    if ( is_wp_error( $response ) ) $response = rest_convert_error_to_response( $response );
    $response->header( 'X-SAC-Request-Id', $request_id );
    $data = (array) $response->get_data();
    $context = sac_mcp_diagnostic_context( $request );
    $rpc_result = (array) ( $data['result'] ?? [] );
    $tool_result = (array) ( $rpc_result['structuredContent'] ?? [] );
    $tool_data = (array) ( $tool_result['data'] ?? [] );
    $public_field = $tool_result['field'] ?? '';
    $status = $response->get_status();
    $code = $context['code'] ?? $tool_result['code'] ?? $data['code'] ?? '';
    $scope_challenge = $status === 401 || ( $status === 403 && $code === 'insufficient_scope' );
    if ( $status < 400 && ! $code && empty( $data['error'] ) && empty( $context['tool'] ) ) return $response;
    if ( get_option( 'sac_oauth_schema' ) !== '1' || ! sac_oauth_rate( 'mcp_diagnostics', 90, HOUR_IN_SECONDS ) ) return $response;
    $record = [
        'request_id' => $request_id, 'time' => gmdate( DATE_ATOM ), 'tool' => $context['tool'] ?? null,
        'operation_id' => isset( $tool_result['operation_id'] ) ? absint( $tool_result['operation_id'] ) : null,
        'grant_id' => $context['grant_id'] ?? null, 'authorization' => $scope_challenge ? 'challenge' : ( isset( $context['grant_id'] ) ? 'token_verified' : 'request_rejected' ),
        'http_status' => $status, 'tool_status' => $context['tool_status'] ?? null, 'wordpress_code' => sanitize_key( $code ),
        'rpc_code' => isset( $data['error']['code'] ) ? (int) $data['error']['code'] : null,
        'field' => sac_mcp_diagnostic_field( $public_field ),
        'request_bytes' => strlen( $request->get_body() ), 'duration_ms' => (int) ( ( microtime( true ) - $started ) * 1000 ),
        'exception_type' => $context['exception_type'] ?? null, 'oauth_challenge' => $scope_challenge,
        'approval_status' => $tool_data['approval_status'] ?? null,
        'tool_declared_scopes' => $context['tool_declared_scopes'] ?? [],
    ];
    $scope_fields = array_flip( [ 'client_id', 'site_allowed_scopes', 'client_registered_scopes', 'client_allowed_scopes',
        'grant_scopes', 'access_scopes', 'required_scopes', 'missing_scopes', 'site_disabled_scopes', 'client_blocked_scopes', 'next_action' ] );
    $record += array_intersect_key( $tool_data, $scope_fields ) + ( $context['scope_report'] ?? [] );
    sac_oauth_insert( 'mcpdiag', sac_oauth_random(), time() + 3 * DAY_IN_SECONDS, $record );
    return $response;
}

function sac_mcp_diagnostic_rows() {
    global $wpdb;
    if ( ! current_user_can( 'manage_options' ) || get_option( 'sac_oauth_schema' ) !== '1' ) return [];
    $table = sac_oauth_table();
    $rows = $wpdb->get_col( $wpdb->prepare( "SELECT payload FROM $table WHERE kind='mcpdiag' AND expires>%d ORDER BY id DESC LIMIT 40", time() ) );
    return array_map( static fn( $row ) => json_decode( $row, true ), $rows ?: [] );
}
