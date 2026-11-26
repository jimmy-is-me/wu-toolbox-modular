<?php
/**
 * Run against a disposable WordPress instance, with the AI connector enabled.
 * php tests/site-ai-connector-wordpress.php /path/to/wordpress
 */
if ( ! defined( 'ABSPATH' ) ) {
    $_SERVER['HTTPS'] = 'on';
    require rtrim( $argv[1], '/\\' ) . '/wp-load.php';
}

function sac_fixture_assert( $condition, $message ) {
    if ( ! $condition ) throw new RuntimeException( $message );
}

function sac_fixture_request( $path, $method = 'GET', $token = '', $params = [], $status = 200, $headers = [] ) {
    $request = new WP_REST_Request( $method, '/' . SAC_NAMESPACE . $path );
    if ( $token ) $request->set_header( 'Authorization', 'Bearer ' . $token );
    foreach ( $headers as $key => $value ) $request->set_header( $key, $value );
    if ( $method === 'GET' ) {
        $request->set_query_params( $params );
    } elseif ( in_array( $path, [ '/oauth/token', '/oauth/revoke' ], true ) ) {
        $request->set_header( 'Content-Type', 'application/x-www-form-urlencoded' );
        $request->set_body( http_build_query( $params ) );
        $request->set_body_params( $params );
    } else {
        $request->set_header( 'Content-Type', 'application/json' );
        $request->set_body( wp_json_encode( (object) $params ) );
    }
    $response = rest_do_request( $request );
    $data = (array) $response->get_data();
    sac_fixture_assert( $response->get_status() === $status, "$method $path expected $status, got " . $response->get_status() . ' ' . ( $data['error'] ?? $data['code'] ?? '' ) . ' ' . ( $data['message'] ?? $data['error_description'] ?? '' ) );
    return $data;
}

function sac_fixture_mcp( $method, $token, $params = [], $status = 200 ) {
    return sac_fixture_request( '/mcp', 'POST', $token, [ 'jsonrpc' => '2.0', 'id' => 'fixture-1', 'method' => $method, 'params' => (object) $params ], $status );
}

function sac_fixture_tool( $name, $params, $token ) {
    $result = sac_fixture_mcp( 'tools/call', $token, [ 'name' => $name, 'arguments' => (object) $params ] );
    return $result['result'];
}

function sac_fixture_authorize( $client, $user, $scopes, $redirect ) {
    $verifier = sac_oauth_random();
    $params = [ 'resource' => sac_oauth_resource(), 'redirect_uri' => $redirect,
        'code_challenge' => rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ) ];
    $row = sac_oauth_row( 'client', $client );
    $code = sac_oauth_create_grant( $row, $user, $params, $scopes, sac_oauth_consent_types( $user, $scopes ), sac_oauth_random() );
    sac_fixture_assert( is_string( $code ), 'Consent persisted an authorization code' );
    return [
        'grant_type' => 'authorization_code', 'code' => $code, 'resource' => sac_oauth_resource(),
        'redirect_uri' => $redirect, 'client_id' => $client, 'code_verifier' => $verifier,
    ];
}

$fixture_host = wp_parse_url( home_url(), PHP_URL_HOST );
sac_fixture_assert( in_array( $fixture_host, [ '127.0.0.1', 'localhost' ], true )
    || ( is_string( $fixture_host ) && str_ends_with( $fixture_host, '.test' ) )
    || getenv( 'WUTM_SAC_DISPOSABLE_FIXTURE' ) === '1', 'Use a disposable WordPress site. Confirm custom hosts with WUTM_SAC_DISPOSABLE_FIXTURE=1.' );
sac_fixture_assert( function_exists( 'sac_oauth_transport' ) && sac_oauth_transport(), 'Enable the connector on a disposable HTTPS site, or configure its explicit localhost test mode.' );
$admin = get_users( [ 'role' => 'administrator', 'number' => 1 ] )[0];
$original_user = get_current_user_id();
$previous = [];
foreach ( [ 'sac_oauth_scopes', 'sac_oauth_dcr', 'sac_oauth_redirects', SAC_OPTION_CONTENT_TYPES, SAC_OPTION_ALLOW_PUBLISH, SAC_OPTION_IP_ALLOWLIST ] as $option ) {
    $previous[ $option ] = get_option( $option, null );
}
$posts = $terms = $clients = $users = [];
$tag = 'SAC fixture ' . wp_generate_password( 8, false );
$redirect = 'https://chatgpt.com/connector_platform_oauth_redirect';
try {
    wp_set_current_user( $admin->ID );
    $_SERVER['REMOTE_ADDR'] = '192.0.2.' . random_int( 1, 254 );
    $scopes = array_keys( sac_oauth_scope_labels() );
    update_option( 'sac_oauth_scopes', $scopes, false );
    update_option( 'sac_oauth_dcr', true, false );
    update_option( 'sac_oauth_redirects', [ $redirect ], false );
    update_option( SAC_OPTION_IP_ALLOWLIST, [ 'enabled' => false, 'ips' => [] ], false );
    update_option( SAC_OPTION_ALLOW_PUBLISH, true, false );
    $all = [ 'read', 'create', 'update', 'publish' ];
    update_option( SAC_OPTION_CONTENT_TYPES, array_fill_keys( [ 'post', 'page', 'field_report', 'work_sample', 'wumetax_lab' ], $all ), false );

    register_post_type( 'field_report', [ 'label' => '現場紀錄', 'public' => true, 'show_in_rest' => false, 'supports' => [ 'title', 'editor', 'excerpt', 'thumbnail' ] ] );
    register_post_type( 'work_sample', [ 'label' => '作品案例', 'public' => true, 'show_in_rest' => true, 'supports' => [ 'title', 'editor' ] ] );
    register_taxonomy( 'report_region', 'field_report', [ 'label' => '地區', 'show_ui' => true, 'hierarchical' => true ] );
    register_post_meta( 'field_report', 'fixture_rating', [
        'type' => 'integer', 'single' => true, 'show_in_rest' => true, 'auth_callback' => '__return_true',
    ] );
    add_filter( 'sac_oauth_registered_meta', static function ( $keys, $type ) {
        return $type === 'field_report' ? [ 'fixture_rating' ] : $keys;
    }, 10, 2 );
    if ( function_exists( 'acf_add_local_field_group' ) ) {
        acf_add_local_field_group( [
            'key' => 'group_sac_fixture_extra', 'title' => 'Connector ACF fixture',
            'fields' => [ [ 'key' => 'field_sac_fixture_extra', 'label' => 'ACF example', 'name' => 'acf_fixture_extra', 'type' => 'text' ] ],
            'location' => [ [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'field_report' ] ] ],
        ] );
    }
    $term = wp_insert_term( $tag, 'report_region' )['term_id'];
    $terms['report_region'] = $term;
    add_filter( 'sac_content_field_definitions', static function ( $fields, $type ) {
        if ( $type !== 'field_report' ) return $fields;
        $fields['verified_source'] = [
            'type' => 'string', 'required' => true, 'authorize' => '__return_true',
            'validate' => 'sanitize_text_field',
            'read' => static function ( $post ) { return get_post_meta( $post->ID, 'verified_source', true ); },
            'write' => static function ( $id, $value ) {
                if ( $value === 'fixture adapter failure' ) return false;
                update_post_meta( $id, 'verified_source', $value );
                return get_post_meta( $id, 'verified_source', true ) === $value;
            },
        ];
        return $fields;
    }, 10, 2 );

    sac_fixture_request( '/mcp', 'POST', '', [], 401 );
    sac_fixture_request( '/mcp', 'POST', '', [], 403, [ 'Origin' => 'https://untrusted.example' ] );
    $legacy = bin2hex( random_bytes( 32 ) );
    update_option( 'sac_api_keys', [ $legacy => 'write' ], false );
    sac_fixture_request( '/posts', 'GET', '', [], 401, [ 'X-SAC-Key' => $legacy ] );
    sac_fixture_request( '/posts', 'GET', $legacy, [], 401 );
    delete_option( 'sac_api_keys' );

    $protected = sac_fixture_request( '/oauth/resource-metadata' );
    sac_fixture_assert( $protected['resource'] === sac_oauth_resource() && $protected['authorization_servers'] === [ sac_oauth_issuer() ], 'Resource metadata is consistent' );
    $metadata = sac_oauth_metadata();
    sac_fixture_assert( $metadata['code_challenge_methods_supported'] === [ 'S256' ] && ! isset( $metadata['client_id_metadata_document_supported'] ), 'Metadata advertises only implemented methods' );
    $registration = [ 'client_name' => 'OAuth fixture', 'redirect_uris' => [ $redirect ], 'token_endpoint_auth_method' => 'none',
        'grant_types' => [ 'authorization_code', 'refresh_token' ], 'response_types' => [ 'code' ] ];
    sac_fixture_request( '/oauth/register', 'POST', '', array_replace( $registration, [ 'redirect_uris' => [ 'https://example.test/callback' ] ] ), 400 );
    $client = sac_fixture_request( '/oauth/register', 'POST', '', $registration, 201 )['client_id'];
    $clients[] = $client;
    $exchange = sac_fixture_authorize( $client, $admin, $scopes, $redirect );
    sac_fixture_request( '/oauth/token', 'POST', '', array_replace( $exchange, [ 'resource' => 'https://other-site.example/mcp' ] ), 400 );
    sac_fixture_request( '/oauth/token', 'POST', '', array_replace( $exchange, [ 'client_id' => 'not-the-client' ] ), 401 );
    sac_fixture_request( '/oauth/token', 'POST', '', array_replace( $exchange, [ 'redirect_uri' => $redirect . '?different=1' ] ), 400 );
    sac_fixture_request( '/oauth/token', 'POST', '', array_replace( $exchange, [ 'code_verifier' => sac_oauth_random() ] ), 400 );
    sac_fixture_request( '/oauth/token', 'POST', '', $exchange + [ 'scope' => 'unavailable:scope' ], 400 );
    $issued = sac_fixture_request( '/oauth/token', 'POST', '', $exchange );
    $access = $issued['access_token'];
    sac_fixture_request( '/oauth/token', 'POST', '', $exchange, 400 );

    $rpc = sac_fixture_mcp( 'initialize', $access, [ 'protocolVersion' => '2025-11-25', 'clientInfo' => [ 'name' => 'fixture', 'version' => '1' ] ] );
    sac_fixture_assert( $rpc['id'] === 'fixture-1' && $rpc['result']['protocolVersion'] === '2025-11-25', 'MCP negotiates version and preserves ID' );
    sac_fixture_mcp( 'ping', $access );
    $tools = array_column( sac_fixture_mcp( 'tools/list', $access )['result']['tools'], null, 'name' );
    sac_fixture_assert( $tools['get_content']['annotations']['readOnlyHint'] && ! $tools['create_content']['annotations']['readOnlyHint']
        && $tools['create_content']['securitySchemes'][0]['scopes'] === [ 'content:create' ], 'Tool risk and security schemes' );
    sac_fixture_assert( isset( $tools['list_products'] ) === class_exists( 'WooCommerce' ), 'Shop tools depend on WooCommerce' );
    sac_fixture_assert( $tools['upload_media']['_meta']['openai/fileParams'] === [ 'file' ]
        && $tools['upload_media']['inputSchema']['properties']->file['required'] === [ 'download_url', 'file_id' ], 'ChatGPT file input matches official schema' );
    $found = sac_fixture_request( '/content-types', 'GET', $access )['types'];
    sac_fixture_assert( ! array_diff( [ 'post', 'page', 'field_report', 'work_sample' ], array_column( $found, 'post_type' ) ), 'Discover multiple custom types' );
    sac_fixture_assert( sac_fixture_request( '/content-types/field_report/terms/report_region', 'GET', $access, [ 'search' => $tag ] )['items'][0]['id'] === $term, 'Discover existing taxonomy term' );

    foreach ( [ 'post', 'page', 'field_report', 'work_sample' ] as $type ) {
        $fields = [ 'title' => "$tag $type", 'content' => "<!-- wp:paragraph -->\n<p>Block content</p>\n<!-- /wp:paragraph -->" ];
        if ( $type === 'field_report' ) {
            sac_fixture_request( '/content/field_report', 'POST', $access, $fields, 400 );
            $fields += [ 'terms' => [ 'report_region' => [ $term ] ], 'custom_fields' => [ 'verified_source' => 'checked' ] ];
        }
        $created = sac_fixture_tool( 'create_content', [ 'post_type' => $type ] + $fields, $access )['structuredContent'];
        $id = $created['data']['id'];
        $posts[] = $id;
        sac_fixture_assert( get_post_status( $id ) === 'draft' && get_post_field( 'post_author', $id ) === (string) $admin->ID, 'Draft owned by consenting user' );
        sac_fixture_assert( boolval( $created['operation_id'] ) && sac_fixture_request( '/operations/' . $created['operation_id'], 'GET', $access )['target_id'] === (string) $id, 'Operation link and result' );
        $result = sac_fixture_request( "/content/$type/$id", 'POST', $access, [ 'title' => "$tag updated $type" ] );
        sac_fixture_assert( $result['title'] === "$tag updated $type" && has_blocks( get_post( $id ) ), 'REST update preserves Gutenberg content' );
        sac_fixture_assert( sac_fixture_tool( 'update_content', [ 'post_type' => $type, 'id' => $id, 'slug' => sanitize_title( $tag . '-' . $type ) ], $access )['structuredContent']['data']['id'] === $id, 'MCP update' );
        if ( $type === 'field_report' ) $report = $id;
        if ( $type === 'post' ) $post = $id;
    }
    sac_fixture_request( '/content/post/' . $post, 'POST', $access, [ 'custom_fields' => [ '_arbitrary_secret' => 'denied' ] ], 400 );
    sac_fixture_request( '/content/field_report/' . $report, 'POST', $access, [ 'custom_fields' => [ 'fixture_rating' => 8 ] ] );
    $detail = sac_fixture_request( '/content/field_report/' . $report, 'GET', $access );
    sac_fixture_assert( $detail['custom_fields']['fixture_rating'] === 8, 'Explicitly opted-in registered Meta round trip' );
    if ( function_exists( 'acf_add_local_field_group' ) ) {
        $schema = sac_fixture_request( '/content-types/field_report', 'GET', $access );
        sac_fixture_assert( ! empty( $schema['custom_fields']->acf_fixture_extra['help'] ) && ! $schema['custom_fields']->acf_fixture_extra['write'], 'Unbound ACF fields are discovered and require an adapter' );
    }
    sac_fixture_request( '/posts/' . $post, 'GET', $access );
    $partial = sac_fixture_tool( 'update_content', [ 'post_type' => 'field_report', 'id' => $report, 'title' => "$tag partial", 'custom_fields' => [ 'verified_source' => 'fixture adapter failure' ] ], $access );
    sac_fixture_assert( $partial['isError'] && $partial['structuredContent']['id'] === $report && get_the_title( $report ) === "$tag partial", 'Partial write retains target ID' );

    $faq = [ 'faqs' => [ [ 'question' => 'What was checked?', 'answer' => '<p>WordPress integration.</p>' ] ] ];
    sac_fixture_request( '/content/post/' . $post . '/faq', 'POST', $access, $faq );
    sac_fixture_assert( sac_fixture_request( '/content/post/' . $post . '/faq', 'GET', $access )['faqs'][0]['question'] === 'What was checked?', 'FAQ round trip' );
    if ( sac_seo_adapter() ) {
        sac_fixture_request( '/content/post/' . $post . '/seo', 'POST', $access, [ 'title' => 'Fixture SEO', 'canonical' => home_url( '/fixture-content/' ), 'noindex' => true ] );
        sac_fixture_assert( sac_fixture_request( '/content/post/' . $post . '/seo', 'GET', $access )['noindex'] === true, 'Active SEO adapter writes metadata' );
    } else {
        sac_fixture_request( '/content/post/' . $post . '/seo', 'POST', $access, [ 'title' => 'Fixture SEO' ], 400 );
        sac_fixture_assert( ! get_post_meta( $post, 'rank_math_title', true ) && ! get_post_meta( $post, '_yoast_wpseo_title', true ), 'Inactive SEO tools write no metadata' );
    }
    if ( function_exists( 'imagecreatetruecolor' ) ) {
        ob_start();
        imagepng( imagecreatetruecolor( 16, 16 ) );
        $png = ob_get_clean();
        $media = sac_fixture_request( '/media/upload', 'POST', $access, [ 'base64' => base64_encode( $png ), 'filename' => 'fixture.png', 'alt_text' => 'Fixture image' ] );
        $posts[] = $media['attachment_id'];
        sac_fixture_request( '/content/field_report/' . $report . '/featured-image', 'POST', $access, [ 'featured_image_id' => $media['attachment_id'] ] );
        sac_fixture_assert( (int) get_post_thumbnail_id( $report ) === $media['attachment_id'], 'Validated image and featured metadata' );
    }
    sac_fixture_request( '/media/upload', 'POST', $access, [ 'base64' => base64_encode( 'not an image' ) ], 415 );
    sac_fixture_request( '/media/upload', 'POST', $access, [ 'url' => 'https://localhost/private-file' ], 400 );
    foreach ( [ '127.0.0.1', '192.168.10.1', '169.254.169.254', '100.64.1.1', '192.0.0.1', '198.19.0.1', '203.0.113.4' ] as $address ) {
        sac_fixture_assert( ! sac_media_public_ipv4( $address ), 'Import rejects private and special-use IPv4 addresses' );
    }
    sac_fixture_assert( sac_media_public_ipv4( '192.0.77.48' ), 'Public IPv4 outside the special-use subnet remains eligible' );
    sac_fixture_request( '/content/post/' . $post, 'POST', $access, [ 'status' => 'publish' ], 400 );
    $review = sac_fixture_request( '/content/post/' . $post . '/publish', 'POST', $access, [], 409 );
    sac_fixture_assert( $review['code'] === 'sac_confirmation_required' && get_post_status( $post ) === 'draft', 'Publish requires WordPress approval' );
    $proof = $review['data']['confirmation_token'];
    sac_fixture_request( '/content/post/' . $post . '/publish', 'POST', $access, [], 409, [ 'X-SAC-Confirmation' => $proof ] );
    $pending = sac_oauth_row( 'confirm', $proof );
    sac_oauth_change( $pending['id'], [ 'state' => 1 ] ); // Browser test exercises the real approval screen and nonce.
    sac_fixture_request( '/content/post/' . $post . '/publish', 'POST', $access, [], 200, [ 'X-SAC-Confirmation' => $proof ] );
    sac_fixture_assert( get_post_status( $post ) === 'publish', 'Approved publish completed' );
    sac_fixture_request( '/content/post/' . $post, 'POST', $access, [ 'title' => "$tag requires review" ], 409 );

    $subscriber_id = wp_insert_user( [ 'user_login' => sanitize_title( $tag ) . '-subscriber', 'user_pass' => sac_oauth_random(), 'role' => 'subscriber' ] );
    sac_fixture_assert( ! is_wp_error( $subscriber_id ), 'Fixture subscriber created' );
    $users[] = $subscriber_id;
    $subscriber = get_userdata( $subscriber_id );
    $restricted = sac_fixture_request( '/oauth/token', 'POST', '', sac_fixture_authorize( $client, $subscriber, [ 'content:read', 'content:create', 'content:publish' ], $redirect ) )['access_token'];
    sac_fixture_request( '/content/post', 'POST', $restricted, [ 'title' => 'Blocked by WordPress role' ], 403 );
    sac_fixture_request( '/content/post/' . $post . '/publish', 'POST', $restricted, [], 403 );

    $other_resource = static function ( $url, $path ) {
        return ltrim( $path, '/' ) === SAC_NAMESPACE . '/mcp' ? 'https://other.example/wp-json/site-ai/v1/mcp' : $url;
    };
    add_filter( 'rest_url', $other_resource, 10, 2 );
    sac_fixture_request( '/verify', 'GET', $access, [], 401 );
    remove_filter( 'rest_url', $other_resource, 10 );

    $admin_subdir = static function () { return 'https://subdir.example/wordpress'; };
    add_filter( 'pre_option_home', $admin_subdir );
    sac_fixture_assert( sac_oauth_metadata_url() === 'https://subdir.example/.well-known/oauth-authorization-server/wordpress/wutm-site-ai', 'RFC 8414 root-relative path in subdirectory installation' );
    remove_filter( 'pre_option_home', $admin_subdir );
    $read = sac_fixture_request( '/oauth/token', 'POST', '', sac_fixture_authorize( $client, $admin, [ 'content:read' ], $redirect ) )['access_token'];
    sac_fixture_request( '/content/post', 'POST', $read, [ 'title' => 'Blocked' ], 403 );
    sac_fixture_mcp( 'tools/call', $read, [ 'name' => 'create_content', 'arguments' => [ 'title' => 'Blocked', 'post_type' => 'post' ] ], 403 );
    sac_oauth_change( sac_oauth_row( 'access', $read )['id'], [ 'expires' => time() - 1 ] );
    sac_fixture_request( '/verify', 'GET', $read, [], 401 );
    add_filter( 'sac_oauth_account_allowed', '__return_false' );
    sac_fixture_request( '/verify', 'GET', $access, [], 401 );
    remove_filter( 'sac_oauth_account_allowed', '__return_false' );

    $refresh = [ 'grant_type' => 'refresh_token', 'resource' => sac_oauth_resource(), 'refresh_token' => $issued['refresh_token'], 'client_id' => $client ];
    $rotated = sac_fixture_request( '/oauth/token', 'POST', '', $refresh );
    sac_fixture_assert( $rotated['refresh_token'] !== $issued['refresh_token'], 'Refresh rotation' );
    sac_fixture_request( '/oauth/token', 'POST', '', $refresh, 400 );
    sac_fixture_request( '/verify', 'GET', $rotated['access_token'], [], 401 );
    $extra = sac_fixture_request( '/oauth/token', 'POST', '', sac_fixture_authorize( $client, $admin, [ 'content:read' ], $redirect ) );
    sac_fixture_request( '/oauth/revoke', 'POST', '', [ 'client_id' => $client, 'token' => $extra['access_token'] ] );
    sac_fixture_request( '/verify', 'GET', $extra['access_token'], [], 401 );
    sac_fixture_assert( get_current_user_id() === $admin->ID, 'REST restores the original WordPress user' );
    echo 'OAuth, MCP, scoped CPT writes, confirmation, media and audit passed (WooCommerce ' . ( class_exists( 'WooCommerce' ) ? 'on' : 'off' ) . ").\n";
} finally {
    foreach ( $clients as $client_id ) {
        $client_row = sac_oauth_row( 'client', $client_id );
        if ( $client_row ) sac_oauth_revoke( 'client', (int) $client_row['id'] );
    }
    foreach ( array_reverse( $posts ) as $post_id ) {
        if ( get_post_type( $post_id ) === 'attachment' ) wp_delete_attachment( $post_id, true );
        else wp_delete_post( $post_id, true );
    }
    foreach ( $terms as $taxonomy => $term_id ) wp_delete_term( $term_id, $taxonomy );
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ( $users as $user_id ) wp_delete_user( $user_id );
    foreach ( $previous as $option => $value ) {
        if ( $value === null ) delete_option( $option );
        else update_option( $option, $value, false );
    }
    wp_set_current_user( $original_user );
}
