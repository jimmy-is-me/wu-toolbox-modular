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
        $fields['status'] = [
            'type' => 'string', 'authorize' => '__return_true', 'validate' => 'sanitize_key',
            'read' => static fn( $post ) => get_post_meta( $post->ID, '_fixture_project_status', true ),
            'write' => static function ( $id, $value ) {
                update_post_meta( $id, '_fixture_project_status', $value );
                return get_post_meta( $id, '_fixture_project_status', true ) === $value;
            },
        ];
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
    sac_fixture_assert( $protected['scopes_supported'] === sac_oauth_scopes(), 'Protected metadata advertises all site-enabled scopes' );
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

    $path = '/content/post/' . $post . '/changes';
    $params = [ 'idempotency_key' => sac_oauth_random(), 'content' => [ 'title' => "$tag batched", 'excerpt' => 'Excerpt intentionally excluded' ],
        'faqs' => [ [ 'question' => 'One confirmation?', 'answer' => 'Yes.' ] ] ];
    $prepared = sac_fixture_request( $path, 'POST', $access, $params );
    $operation = $prepared['operation_id'];
    sac_fixture_assert( sac_fixture_request( $path, 'POST', $access, $params )['operation_id'] === $operation, 'Batch idempotency preserves operation ID' );
    sac_fixture_request( $path, 'POST', $access, array_replace( $params, [ 'content' => [ 'title' => 'Conflicting request' ] ] ), 409 );
    $apply = static function ( $id, $fields ) {
        $row = sac_oauth_row( 'changes', $id, true );
        return sac_change_apply( $row, new WP_REST_Request( 'POST', '/' . SAC_NAMESPACE . '/content-changes/' . $id ), $fields );
    };
    $receipt = $apply( $operation, [ 'content.title', 'faqs' ] );
    sac_fixture_assert( ! is_wp_error( $receipt ) && $receipt['approval_status'] === 'applied'
        && get_post( $post )->post_title === "$tag batched" && get_post( $post )->post_excerpt !== 'Excerpt intentionally excluded', 'Selective review executes only approved fields' );
    $readback = sac_fixture_request( '/content-changes/' . $operation . '/verify', 'GET', $access );
    sac_fixture_assert( $readback['verification'] === [ 'content.title' => 'verified', 'faqs' => 'verified' ], 'Reread proves saved values' );
    sac_fixture_assert( is_wp_error( $apply( $operation, [ 'content.title' ] ) ), 'Applying the same operation cannot repeat' );
    $restore = sac_fixture_request( '/content-changes/' . $operation . '/rollback', 'POST', $access, [ 'idempotency_key' => sac_oauth_random() ] );
    $restored = $apply( $restore['operation_id'], [ 'content.title', 'faqs' ] );
    sac_fixture_assert( ! is_wp_error( $restored ) && $restored['approval_status'] === 'applied' && get_post( $post )->post_title !== "$tag batched", 'Rollback uses a separately approved set' );

    $params = [ 'idempotency_key' => sac_oauth_random(), 'content' => [ 'title' => 'Expired' ] ];
    $expired = sac_fixture_request( $path, 'POST', $access, $params )['operation_id'];
    $row = sac_oauth_row( 'changes', $expired, true );
    $row['payload']['approval_expires'] = time() - 1;
    sac_oauth_change( $expired, [ 'payload' => wp_json_encode( $row['payload'] ) ] );
    sac_fixture_assert( is_wp_error( $apply( $expired, [ 'content.title' ] ) )
        && sac_fixture_request( '/content-changes/' . $expired, 'GET', $access )['approval_status'] === 'expired', 'Expired batches cannot write' );

    $original_title = get_post( $report )->post_title;
    $failed = sac_fixture_request( '/content/field_report/' . $report . '/changes', 'POST', $access, [
        'idempotency_key' => sac_oauth_random(), 'content' => [ 'title' => 'Restore this title on failure', 'custom_fields' => [ 'verified_source' => 'fixture adapter failure' ] ],
    ] )['operation_id'];
    $failure = $apply( $failed, [ 'content.title', 'content.custom_fields' ] );
    sac_fixture_assert( ! is_wp_error( $failure ) && $failure['approval_status'] === 'failed' && $failure['recovery'] === 'restored'
        && get_post( $report )->post_title === $original_title, 'Partial adapter failure restores a saved native field and records the failure' );

    $http = static function ( $response, $args, $url ) use ( $post ) {
        if ( $url !== get_permalink( $post ) ) return $response;
        return [ 'headers' => [ 'content-type' => 'text/html; charset=utf-8', 'x-robots-tag' => 'noindex' ], 'response' => [ 'code' => 200, 'message' => 'OK' ],
            'body' => '<html><head><title>Fixture rendered SEO</title><meta name="description" content="Rendered"><link rel="canonical" href="' . esc_url( $url ) . '"></head><body><h1>CAPTCHA tutorial</h1><img src="sample.png"></body></html>' ];
    };
    add_filter( 'pre_http_request', $http, 10, 3 );
    try {
        $rendered = sac_fixture_request( '/content/post/' . $post . '/rendered-seo', 'GET', $access );
        if ( class_exists( 'DOMDocument' ) ) {
            sac_fixture_assert( $rendered['state'] === 'verified' && $rendered['robots'] === 'noindex' && $rendered['h1_count'] === 1
                && $rendered['images']['missing_alt_attribute'] === 1, 'Anonymous HTML diagnostic reads headers, canonical, headings and ALT with a mocked HTTP response' );
        }
    } finally {
        remove_filter( 'pre_http_request', $http, 10 );
    }

    $more = sac_fixture_request( $path, 'POST', $access, [ 'idempotency_key' => sac_oauth_random(),
        'content' => [ 'title' => 'Verified updated heading' ],
        'faqs' => [ [ 'question' => 'New question for preview', 'answer' => 'Current answer' ] ] ] )['operation_id'];
    $result = $apply( $more, [ 'content.title', 'faqs' ] );
    sac_fixture_assert( ! is_wp_error( $result ) && $result['approval_status'] === 'applied', 'Frontend fixture change applied' );
    $old = true;
    $public_url = get_permalink( $post );
    $mock_public = static function ( $response, $args, $url ) use ( &$old, $public_url ) {
        if ( $url !== $public_url ) return new WP_Error( 'sac_fixture_unavailable' );
        $heading = $old ? 'Old cached heading' : 'Verified updated heading';
        $faq = $old ? '' : '<p>New question for preview</p><p>Current answer</p><script type="application/ld+json">{"@type":"FAQPage","mainEntity":[{"@type":"Question","name":"New question for preview","acceptedAnswer":{"text":"Current answer"}}]}</script>';
        return [ 'headers' => [ 'content-type' => 'text/html', 'cf-cache-status' => $old ? 'HIT' : 'MISS' ], 'response' => [ 'code' => 200 ],
            'body' => '<html><body><article><h1>' . $heading . '</h1>' . $faq . '</article></body></html>' ];
    };
    add_filter( 'pre_http_request', $mock_public, 10, 3 );
    try {
        if ( class_exists( 'DOMDocument' ) ) {
            $verify_route = '/content-changes/' . $more . '/verify';
            $stale = sac_fixture_request( $verify_route, 'GET', $access, [ 'frontend' => true ] );
            sac_fixture_assert( $stale['database_verification']['state'] === 'verified' && $stale['frontend_verification']['state'] === 'stale_cache'
                && $stale['structured_data_verification']['state'] === 'mismatch', 'Old cached HTML cannot pass public verification' );
            $old = false;
            $fresh = sac_fixture_request( $verify_route, 'GET', $access, [ 'frontend' => true ] );
            sac_fixture_assert( $fresh['frontend_verification']['state'] === 'verified' && $fresh['structured_data_verification']['state'] === 'verified'
                && $fresh['verification_status'] === 'partial', 'Fresh HTML and FAQ schema pass independently of an unavailable sitemap' );
            sac_fixture_request( '/content-changes/' . $more . '/purge-cache', 'POST', $access );
            sac_fixture_assert( get_post( $post )->post_title === 'Verified updated heading', 'Cache retry does not rewrite content' );
        }
    } finally {
        remove_filter( 'pre_http_request', $mock_public, 10 );
    }

    require_once dirname( __DIR__ ) . '/modules/page-cache/module.php';
    $dir = trailingslashit( WP_CONTENT_DIR ) . 'cache/wutm-page-cache/';
    wp_mkdir_p( $dir );
    $related = $dir . hash( 'sha256', $tag . ' related' ) . '.html.gz';
    $unrelated = $dir . hash( 'sha256', $tag . ' unrelated' ) . '.html.gz';
    try {
        foreach ( [ $related => $post, $unrelated => $report ] as $file => $id ) {
            file_put_contents( $file, gzencode( 'test cache' ) );
            file_put_contents( $file . '.json', wp_json_encode( [ 'post_id' => $id, 'url' => get_permalink( $id ), 'contexts' => [] ] ) );
        }
        $retry = sac_fixture_request( '/content-changes/' . $more . '/purge-cache', 'POST', $access );
        sac_fixture_assert( ! is_file( $related ) && is_file( $unrelated )
            && in_array( get_permalink( $post ), $retry['cache_verification']['purged_urls'], true ), 'Scoped purge removes matching disk entry and reports its URL' );
    } finally {
        foreach ( [ $related, $unrelated ] as $file ) {
            if ( is_file( $file ) ) unlink( $file );
            if ( is_file( $file . '.json' ) ) unlink( $file . '.json' );
        }
    }

    $caps = sac_fixture_request( '/capabilities', 'GET', $access, [ 'client_tool_hash' => 'stale-hash' ] );
    sac_fixture_assert( $caps['plugin_version'] === WUTM_VERSION && $caps['client_tool_list_current'] === false
        && in_array( 'base64_chunks', $caps['media_transports'], true ), 'Capabilities detect stale client tool inventory' );
    if ( isset( $png ) ) {
        $split = str_split( $png, (int) ceil( strlen( $png ) / 2 ) );
        $upload = [ 'upload_id' => sac_oauth_random(), 'sha256' => hash( 'sha256', $png ), 'chunk_count' => 2 ];
        sac_fixture_request( '/media/upload', 'POST', $access, $upload + [ 'chunk_index' => 1, 'base64' => base64_encode( $split[1] ) ] );
        sac_fixture_request( '/media/upload', 'POST', $access, $upload + [ 'complete_upload' => true ], 409 );
        sac_fixture_request( '/media/upload', 'POST', $access, $upload + [ 'chunk_index' => 0, 'base64' => base64_encode( $split[0] ) ] );
        $finish = $upload + [ 'complete_upload' => true, 'license' => 'Fixture licence', 'generated_by_ai' => false ];
        $first = sac_fixture_request( '/media/upload', 'POST', $access, $finish );
        $posts[] = $first['media_id'];
        $again = sac_fixture_request( '/media/upload', 'POST', $access, $finish );
        sac_fixture_assert( $first['media_id'] === $again['media_id'] && $again['reused'] && $first['sha256'] === $upload['sha256']
            && $first['rights']->license === 'Fixture licence', 'Chunk assembly and retry return the same accessible attachment and declared rights' );
        sac_fixture_request( '/media/upload', 'POST', $access, $upload + [ 'chunk_index' => 0, 'base64' => base64_encode( 'different' ) ], 409 );
    }

    $draft_path = '/content/field_report/' . $report . '/changes';
    $draft_target = [ 'id' => $report, 'post_type' => 'field_report' ];
    $draft_status = get_post_status( $report );
    $bad = sac_fixture_tool( 'prepare_content_changes', $draft_target + [ 'dry_run' => true, 'expected_status' => 'publish',
        'content' => [ 'status' => 'publish', 'custom_fields' => [ 'status' => [ 'incorrect' ] ], 'terms' => [ 'report_region' => [ 0 ] ] ] ], $access );
    $bad_fields = array_column( $bad['structuredContent']['data']['errors'], 'field' );
    sac_fixture_assert( $bad['isError'] && ! array_diff( [ 'expected_status', 'content.status', 'content.custom_fields.status', 'content.terms.report_region' ], $bad_fields ),
        'Dry run reports multiple full field paths as an MCP tool error' );
    $protected_status = sac_fixture_request( $draft_path, 'POST', $access, [ 'idempotency_key' => sac_oauth_random(), 'content' => [ 'status' => 'publish' ] ], 400 );
    sac_fixture_assert( $protected_status['data']['field'] === 'content.status', 'Change Set cannot publish content' );
    $data = [ 'expected_status' => $draft_status, 'content' => [
        'status' => $draft_status, 'content' => '<!-- wp:paragraph --><p>' . str_repeat( '測試 HTML 中文 &amp; 特殊字元 ', 800 ) . '</p><!-- /wp:paragraph -->',
        'custom_fields' => [ 'status' => 'live' ], 'terms' => [ 'report_region' => [ $term ] ],
    ], 'faqs' => array_fill( 0, 15, [ 'question' => '問題', 'answer' => '<strong>答案</strong>' ] ) ];
    if ( isset( $media ) ) $data['content']['featured_image_id'] = $media['attachment_id'];
    if ( sac_content_extensions( 'field_report' )['seo'] ) $data['seo'] = [ 'title' => 'SEO 草稿', 'description' => '中文描述' ];
    $dry = sac_fixture_request( $draft_path, 'POST', $access, [ 'dry_run' => true ] + $data );
    sac_fixture_assert( $dry['success'] && $dry['warnings'][0]['field'] === 'content.status' && empty( $dry['operation_id'] )
        && get_post_meta( $report, '_fixture_project_status', true ) === '', 'Dry run never writes or creates approval' );
    $data['idempotency_key'] = sac_oauth_random();
    $prepared = sac_fixture_request( $draft_path, 'POST', $access, $data );
    $renewed = sac_fixture_request( '/oauth/token', 'POST', '', sac_fixture_authorize( $client, $admin, $scopes, $redirect ) )['access_token'];
    $resumed = sac_fixture_request( $draft_path, 'POST', $renewed, array_reverse( $data, true ) );
    sac_fixture_assert( $resumed['operation_id'] === $prepared['operation_id'], 'Same owner and client reuse operation after OAuth reconnect' );
    $selected = [ 'content.content', 'content.custom_fields', 'content.terms', 'faqs' ];
    if ( isset( $media ) ) $selected[] = 'content.featured_image_id';
    if ( isset( $data['seo'] ) ) $selected[] = 'seo';
    $applied_draft = $apply( $prepared['operation_id'], $selected );
    sac_fixture_assert( ! is_wp_error( $applied_draft ) && $applied_draft['approval_status'] === 'applied' && get_post_status( $report ) === $draft_status
        && get_post_meta( $report, '_fixture_project_status', true ) === 'live', 'Integrated approved write preserves draft and allows CPT custom status' );
    $verified_draft = sac_fixture_request( '/content-changes/' . $prepared['operation_id'] . '/verify', 'GET', $renewed, [ 'frontend' => true ] );
    sac_fixture_assert( $verified_draft['database_verification']['state'] === 'verified' && $verified_draft['frontend_verification']['state'] === 'not_applicable',
        'Draft has independent database and public verification' );
    $limited_token = sac_fixture_request( '/oauth/token', 'POST', '', sac_fixture_authorize( $client, $admin, [ 'content:read', 'content:update' ], $redirect ) )['access_token'];
    $limited_request = [ 'name' => 'prepare_content_changes', 'arguments' => $draft_target + [ 'dry_run' => true,
        'faqs' => [ [ 'question' => 'Scope?', 'answer' => 'Required.' ] ], 'content' => [ 'terms' => [ 'report_region' => [ $term ] ] ] ] ];
    $dry_scope = sac_fixture_mcp( 'tools/call', $limited_token, $limited_request )['result'];
    sac_fixture_assert( $dry_scope['isError'] && $dry_scope['structuredContent']['data']['dry_run']
        && in_array( 'taxonomy:write', $dry_scope['structuredContent']['data']['missing_scopes'], true ), 'Conditional scope diagnostics do not issue an OAuth challenge' );
    unset( $limited_request['arguments']['dry_run'] );
    $limited_request['arguments']['idempotency_key'] = sac_oauth_random();
    $challenge = sac_fixture_mcp( 'tools/call', $limited_token, $limited_request, 403 );
    sac_fixture_assert( $challenge['code'] === 'insufficient_scope' && ! array_diff( [ 'faq:read', 'faq:write', 'taxonomy:read', 'taxonomy:write' ],
        explode( ' ', $challenge['data']['scope'] ) ), 'All missing feature scopes are challenged together' );
    update_option( 'sac_oauth_scopes', array_values( array_diff( $scopes, [ 'faq:write' ] ) ), false );
    try {
        $disabled = sac_fixture_mcp( 'tools/call', $limited_token, $limited_request );
        sac_fixture_assert( $disabled['result']['isError'] && $disabled['result']['structuredContent']['code'] === 'sac_scope_disabled', 'Site-disabled scope does not demand reauthorization' );
    } finally {
        update_option( 'sac_oauth_scopes', $scopes, false );
    }

    $without_taxonomy = array_values( array_diff( $scopes, [ 'taxonomy:write' ] ) );
    $legacy_client = sac_fixture_request( '/oauth/register', 'POST', '', $registration + [ 'scope' => implode( ' ', $without_taxonomy ) ], 201 )['client_id'];
    $clients[] = $legacy_client;
    $legacy_row = sac_oauth_row( 'client', $legacy_client );
    $legacy_tokens = sac_fixture_request( '/oauth/token', 'POST', '', sac_fixture_authorize( $legacy_client, $admin, $without_taxonomy, $redirect ) );
    $legacy_access = $legacy_tokens['access_token'];
    $minimal = sac_fixture_tool( 'prepare_content_changes', $draft_target + [ 'dry_run' => true, 'content' => [ 'title' => 'Without terms' ] ], $legacy_access );
    sac_fixture_assert( $minimal['structuredContent']['data']['success'] === true, 'Dry Run without terms succeeds on restricted grant' );
    $with_terms = $draft_target + [ 'dry_run' => true, 'content' => [ 'terms' => [ 'report_region' => [ $term ] ] ] ];
    $limited_dry = sac_fixture_tool( 'prepare_content_changes', $with_terms, $legacy_access )['structuredContent']['data'];
    sac_fixture_assert( $limited_dry['missing_scopes'] === [ 'taxonomy:write' ] && $limited_dry['next_action'] === 'update_client_scopes', 'Legacy client cap is distinguished from missing consent' );
    sac_fixture_assert( sac_oauth_add_client_scopes( (int) $legacy_row['id'], [ 'taxonomy:write' ] ) === true, 'Site admin can extend a legacy registration' );
    sac_fixture_assert( ! in_array( 'taxonomy:write', sac_fixture_request( '/verify', 'GET', $legacy_access )['scopes'], true ), 'Registration update does not widen the grant' );
    $after_admin = sac_fixture_tool( 'prepare_content_changes', $with_terms, $legacy_access )['structuredContent']['data'];
    sac_fixture_assert( $after_admin['missing_scopes'] === [ 'taxonomy:write' ] && $after_admin['next_action'] === 'authorize_required_scope', 'Consent remains required after registration update' );
    $refresh_legacy = [ 'client_id' => $legacy_client, 'grant_type' => 'refresh_token', 'refresh_token' => $legacy_tokens['refresh_token'] ];
    sac_fixture_request( '/oauth/token', 'POST', '', $refresh_legacy + [ 'scope' => 'taxonomy:write' ], 400 );
    $rotated_legacy = sac_fixture_request( '/oauth/token', 'POST', '', $refresh_legacy );
    sac_fixture_assert( ! in_array( 'taxonomy:write', explode( ' ', $rotated_legacy['scope'] ), true ), 'Refresh retains the original grant' );
    $after_consent = sac_fixture_request( '/oauth/token', 'POST', '', sac_fixture_authorize( $legacy_client, $admin, $scopes, $redirect ) )['access_token'];
    sac_fixture_assert( in_array( 'taxonomy:write', sac_fixture_request( '/verify', 'GET', $after_consent )['scopes'], true ), 'The explicitly consented grant includes taxonomy write' );
    sac_fixture_assert( sac_fixture_tool( 'prepare_content_changes', $with_terms, $after_consent )['structuredContent']['data']['success'], 'Taxonomy Dry Run succeeds after consent' );

    $full = $data;
    $full['idempotency_key'] = sac_oauth_random();
    $full['content']['title'] = 'Complete draft after additional consent';
    if ( isset( $full['seo'], $media ) ) $full['seo']['og_image_id'] = $media['attachment_id'];
    $full_prepared = sac_fixture_request( $draft_path, 'POST', $after_consent, $full );
    sac_fixture_assert( get_post( $report )->post_title !== $full['content']['title'], 'Additional consent cannot apply the Change Set' );
    $applied_with_scope = $apply( $full_prepared['operation_id'], array_merge( $selected, [ 'content.title' ] ) );
    sac_fixture_assert( ! is_wp_error( $applied_with_scope ) && $applied_with_scope['approval_status'] === 'applied'
        && get_post( $report )->post_title === $full['content']['title'] && get_post_status( $report ) === 'draft'
        && count( get_post_meta( $report, '_sac_faq_list', true ) ) === 15, 'All authorized changes execute once and preserve the existing draft' );
    sac_fixture_assert( sac_fixture_request( $draft_path, 'POST', $after_consent, $full )['operation_id'] === $full_prepared['operation_id'],
        'Retry after consent reuses the same operation' );

    $oversized = sac_fixture_tool( 'prepare_content_changes', $draft_target + [ 'dry_run' => true, 'content' => [ 'content' => str_repeat( '中', 270000 ) ] ], $access );
    sac_fixture_assert( $oversized['isError'] && $oversized['structuredContent']['max_request_bytes'] === sac_review_max_bytes(), 'Oversized tool call reports its actual limit' );

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
    sac_fixture_request( $path, 'POST', $read, $params, 403 );
    sac_fixture_request( '/content-changes/' . $operation, 'GET', $read, [], 404 );
    sac_fixture_mcp( 'tools/call', $read, [ 'name' => 'create_content', 'arguments' => [ 'title' => 'Blocked', 'post_type' => 'post' ] ], 403 );
    sac_oauth_change( sac_oauth_row( 'access', $read )['id'], [ 'expires' => time() - 1 ] );
    sac_fixture_request( '/verify', 'GET', $read, [], 401 );
    add_filter( 'sac_oauth_account_allowed', '__return_false' );
    sac_fixture_request( '/verify', 'GET', $access, [], 401 );
    remove_filter( 'sac_oauth_account_allowed', '__return_false' );

    $refresh = [ 'grant_type' => 'refresh_token', 'resource' => sac_oauth_resource(), 'refresh_token' => $issued['refresh_token'], 'client_id' => $client ];
    $rotated = sac_fixture_request( '/oauth/token', 'POST', '', array_diff_key( $refresh, [ 'resource' => true ] ) );
    sac_fixture_assert( $rotated['refresh_token'] !== $issued['refresh_token'], 'Refresh rotation' );
    sac_fixture_request( '/oauth/token', 'POST', '', $refresh, 400 );
    sac_fixture_request( '/verify', 'GET', $rotated['access_token'], [], 401 );
    $extra = sac_fixture_request( '/oauth/token', 'POST', '', sac_fixture_authorize( $client, $admin, [ 'content:read' ], $redirect ) );
    sac_fixture_request( '/oauth/revoke', 'POST', '', [ 'client_id' => $client, 'token' => $extra['access_token'] ] );
    sac_fixture_request( '/verify', 'GET', $extra['access_token'], [], 401 );
    sac_fixture_assert( get_current_user_id() === $admin->ID, 'REST restores the original WordPress user' );
    echo 'OAuth, MCP, scoped CPT writes, confirmation, media, batch verification, rollback and frontend diagnostics passed (WooCommerce ' . ( class_exists( 'WooCommerce' ) ? 'on' : 'off' ) . ").\n";
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
