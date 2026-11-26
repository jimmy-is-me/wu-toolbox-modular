<?php
/**
 * Run on a disposable WordPress site with the Toolbox AI connector enabled.
 * php tests/site-ai-connector-wordpress.php /absolute/path/to/wordpress
 * Or: wp eval-file tests/site-ai-connector-wordpress.php
 */
if ( ! defined( 'ABSPATH' ) ) require rtrim( $argv[1], '/' ) . '/wp-load.php';

function sac_fixture_check( $condition, $message ) {
    if ( ! $condition ) throw new RuntimeException( $message );
}

function sac_fixture_call( $path, $method = 'GET', $key = '', $params = [], $expect = 200 ) {
    $request = new WP_REST_Request( $method, '/' . SAC_NAMESPACE . $path );
    $request->set_header( 'x-sac-key', $key );
    if ( $method === 'GET' ) {
        $request->set_query_params( $params );
    } else {
        $request->set_header( 'content-type', 'application/json' );
        $request->set_body( wp_json_encode( (object) $params ) );
    }
    $response = rest_do_request( $request );
    $data = $response->get_data();
    sac_fixture_check( $response->get_status() === $expect, "$method $path expected $expect, got " . $response->get_status() . ' ' . wp_json_encode( $data, JSON_UNESCAPED_UNICODE ) );
    return $data;
}

function sac_fixture_mcp( $tool, $args, $key ) {
    $data = sac_fixture_call( '/mcp', 'POST', $key, [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [ 'name' => $tool, 'arguments' => $args ] ] );
    return $data['result'];
}

sac_fixture_check( function_exists( 'sac_content_types' ), 'Enable the Toolbox AI connector on the fixture site first.' );
$admin = get_users( [ 'role' => 'administrator', 'number' => 1 ] )[0];
$original = get_current_user_id();
wp_set_current_user( $admin->ID );
$previous = [];
foreach ( [ SAC_OPTION_KEYS, SAC_OPTION_CONTENT_TYPES, SAC_OPTION_IP_ALLOWLIST, SAC_OPTION_ALLOW_PUBLISH, SAC_LOG_OPTION, 'sac_platform_verification' ] as $option ) {
    $previous[ $option ] = get_option( $option, null );
}
$created = [];
$legacy = 'fixture-legacy-write';
$read = 'fixture-read';
$write = 'fixture-scoped-write';
$tag = 'sac-' . wp_generate_password( 8, false );
$subscriber = wp_insert_user( [ 'user_login' => $tag, 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ] );
$limited_key = 'fixture-capability-key';
$author = wp_insert_user( [ 'user_login' => $tag . '-author', 'user_pass' => wp_generate_password(), 'role' => 'author' ] );
$author_key = 'fixture-author-key';
try {
    $legacy_post = [ 'read', 'create', 'update' ];
    $all = [ 'post' => $legacy_post, 'page' => $legacy_post, 'field_report' => $legacy_post, 'work_sample' => $legacy_post ];
    delete_option( SAC_OPTION_CONTENT_TYPES );
    update_option( SAC_OPTION_IP_ALLOWLIST, [ 'enabled' => false, 'ips' => [] ] );
    update_option( SAC_OPTION_ALLOW_PUBLISH, false );
    update_option( SAC_OPTION_KEYS, [
        $legacy => 'write',
        $read => [ 'level' => 'read', 'user_id' => $admin->ID, 'content_scope' => $all ],
        $write => [ 'level' => 'write', 'user_id' => $admin->ID, 'content_scope' => $all ],
        $limited_key => [ 'level' => 'write', 'user_id' => $subscriber, 'content_scope' => $all ],
        $author_key => [ 'level' => 'write', 'user_id' => $author, 'content_scope' => $all ],
    ] );
    sac_migrate_key_format();
    sac_bind_legacy_content_keys();
    $migrated = get_option( SAC_OPTION_KEYS );
    sac_migrate_key_format();
    sac_bind_legacy_content_keys();
    sac_fixture_check( get_option( SAC_OPTION_KEYS ) === $migrated, 'Migration is repeatable' );
    sac_fixture_check( array_column( sac_fixture_call( '/content-types', 'GET', $legacy )['types'], 'post_type' ) === [ 'post' ], 'Legacy credential has only article access' );

    update_option( SAC_OPTION_CONTENT_TYPES, array_intersect_key( $all, [ 'post' => true, 'page' => true ] ) );
    foreach ( [ 'post', 'page' ] as $type ) {
        $result = sac_fixture_call( '/content/' . $type, 'POST', $write, [ 'title' => $tag . $type, 'content' => '<p>Draft</p>' ] );
        $created[ $type ] = $result['id'];
        sac_fixture_check( $result['status'] === 'draft' && $result['post_type'] === $type && ! empty( $result['edit_url'] ), 'Native draft result' );
        sac_fixture_check( sac_fixture_call( '/content/' . $type, 'GET', $read, [ 'search' => $tag, 'page' => 1, 'per_page' => 1 ] )['items'][0]['id'] === $result['id'], 'Native list' );
    }
    sac_fixture_check( sac_fixture_call( '/content/post', 'GET', $author_key, [ 'search' => $tag, 'status' => 'draft' ] )['items'] === [], 'Author cannot see drafts belonging to another user' );
    sac_fixture_call( '/content/post/' . $created['post'], 'GET', $author_key, [], 403 );

    register_post_type( 'field_report', [ 'label' => '現場紀錄', 'public' => true, 'show_in_rest' => false, 'supports' => [ 'title', 'editor', 'excerpt', 'thumbnail' ] ] );
    register_post_type( 'work_sample', [ 'label' => '作品成果', 'public' => true, 'show_in_rest' => true, 'supports' => [ 'title', 'editor' ] ] );
    register_taxonomy( 'report_region', 'field_report', [ 'label' => '紀錄區域', 'show_ui' => true ] );
    register_taxonomy( 'work_industry', 'work_sample', [ 'label' => '作品產業', 'show_ui' => true ] );
    add_filter( 'sac_content_field_definitions', static function ( $fields, $type ) {
        if ( $type !== 'field_report' ) return $fields;
        $fields['verified_source'] = [
            'type' => 'string', 'required' => true,
            'authorize' => static function () { return true; },
            'validate' => static function ( $value ) { return sanitize_text_field( $value ); },
            'read' => static function ( $post ) { return get_post_meta( $post->ID, 'verified_source', true ); },
            'write' => static function ( $id, $value ) {
                update_post_meta( $id, 'verified_source', $value );
                return get_post_meta( $id, 'verified_source', true ) === $value;
            },
        ];
        return $fields;
    }, 10, 2 );
    sac_fixture_call( '/content/field_report', 'GET', $write, [], 403 );
    update_option( SAC_OPTION_CONTENT_TYPES, $all );
    sac_fixture_call( '/content/field_report', 'GET', $legacy, [], 403 );
    sac_fixture_call( '/content/post', 'POST', $limited_key, [ 'title' => 'Invalid user' ], 403 );
    foreach ( [ 'field_report' => 'report_region', 'work_sample' => 'work_industry' ] as $type => $tax ) {
        $term = wp_insert_term( $tag, $tax )['term_id'];
        $description = sac_fixture_call( '/content-types/' . $type, 'GET', $read );
        sac_fixture_check( isset( $description['taxonomies']->$tax ) && $description['show_in_rest'] === ( $type === 'work_sample' ), 'CPT discovery and taxonomy binding' );
        $item_path = '/content-types/' . $type . '/terms/';
        sac_fixture_check( sac_fixture_call( $item_path . $tax, 'GET', $read, [ 'search' => $tag ] )['items'][0]['id'] === $term, 'Taxonomy terms discovery' );
        sac_fixture_call( $item_path . 'category', 'GET', $read, [], 400 );
        $data = [ 'title' => $tag . $type, 'content' => '<p>Preserve this</p>', 'terms' => [ $tax => [ $term ] ] ];
        if ( $type === 'field_report' ) {
            sac_fixture_call( '/content/' . $type, 'POST', $write, $data, 400 );
            $data['custom_fields'] = [ 'verified_source' => 'Field evidence' ];
        }
        $result = sac_fixture_call( '/content/' . $type, 'POST', $write, $data );
        $created[ $type ] = $result['id'];
        $path = '/content/' . $type . '/' . $result['id'];
        sac_fixture_call( $path, 'POST', $read, [ 'title' => 'not allowed' ], 403 );
        sac_fixture_call( $path, 'POST', $write, [ 'slug' => $tag . '-updated' ] );
        $detail = sac_fixture_call( $path, 'GET', $read );
        sac_fixture_check( $detail['slug'] === strtolower( $tag ) . '-updated' && $detail['content'] === $data['content'] && $detail['terms'][ $tax ][0]['id'] === $term, 'CPT partial update and taxonomy round trip' );
        if ( $type === 'field_report' ) sac_fixture_check( $detail['custom_fields']['verified_source'] === 'Field evidence', 'Explicit field adapter round trip' );
        foreach ( [ '', '/faq', '/seo' ] as $suffix ) sac_fixture_call( '/posts/' . $result['id'] . $suffix, 'GET', $legacy, [], 409 );
        foreach ( [ '', '/faq', '/seo', '/featured-image' ] as $suffix ) sac_fixture_call( '/posts/' . $result['id'] . $suffix, 'POST', $legacy, [ 'title' => 'unexpected' ], 409 );
    }
    sac_fixture_call( '/content/post/' . $created['page'], 'GET', $write, [], 409 );
    sac_fixture_call( '/content/post/' . $created['post'], 'POST', $write, [ 'id' => $created['page'], 'title' => 'Invalid target' ], 409 );
    sac_fixture_call( '/posts/' . $created['post'], 'POST', $legacy, [ 'id' => $created['page'], 'title' => 'Invalid target' ], 409 );
    sac_fixture_call( '/posts', 'POST', $legacy, [ 'post_type' => 'work_sample', 'title' => 'Invalid type' ], 409 );
    foreach ( [ '/posts', '/content/post' ] as $path ) {
        sac_fixture_call( $path, 'POST', $write, [ 'content' => 'Missing title' ], 400 );
        sac_fixture_call( $path, 'POST', $write, [ 'title' => 'Pending publishing', 'status' => 'publish' ], 403 );
        sac_fixture_call( $path, 'POST', $read, [ 'title' => 'Denied' ], 403 );
    }
    sac_fixture_check( ! ( new WP_Query( [ 'post_type' => 'post', 'title' => 'Pending publishing', 'post_status' => [ 'publish', 'draft' ], 'fields' => 'ids' ] ) )->posts, 'Failed publishing did not create a post' );
    $post = sac_fixture_call( '/posts', 'POST', $legacy, [ 'title' => $tag . 'legacy', 'content' => '<p>Historical caller</p>' ] );
    $created['legacy'] = $post['post_id'];
    sac_fixture_check( (bool) $post['success'] && isset( $post['seo'] ), 'Legacy create response retained' );
    $legacy_path = '/posts/' . $post['post_id'];
    $legacy_mcp = sac_fixture_mcp( 'get_post', [ 'post_id' => $post['post_id'] ], $legacy );
    sac_fixture_check( empty( $legacy_mcp['isError'] ) && json_decode( $legacy_mcp['content'][0]['text'], true )['content'] === '<p>Historical caller</p>', 'Legacy MCP tool and response retained' );
    $faq = [ 'faqs' => [ [ 'question' => 'How?', 'answer' => 'Use this answer.' ] ] ];
    sac_fixture_call( $legacy_path . '/faq', 'POST', $legacy, $faq );
    sac_fixture_check( sac_fixture_call( $legacy_path . '/faq', 'GET', $legacy )['faqs'] === $faq['faqs'], 'Legacy FAQ' );
    sac_fixture_call( $legacy_path . '/seo', 'POST', $legacy, [ 'title' => 'Legacy SEO' ] );
    sac_fixture_check( sac_fixture_call( $legacy_path . '/seo', 'GET', $legacy )['title'] === 'Legacy SEO', 'Legacy SEO' );

    $attachment = wp_insert_attachment( [ 'post_title' => 'Test image', 'post_mime_type' => 'image/png', 'guid' => home_url( '/fixture.png' ) ], 'fixture.png', $post['post_id'] );
    $created['attachment'] = $attachment;
    sac_fixture_call( '/content/post/' . $attachment, 'GET', $write, [], 409 );
    sac_fixture_call( '/posts/' . $attachment . '/seo', 'POST', $legacy, [ 'title' => 'Denied attachment metadata' ], 409 );
    sac_fixture_call( $legacy_path . '/featured-image', 'POST', $read, [ 'media_id' => $attachment ], 403 );
    sac_fixture_call( $legacy_path . '/featured-image', 'POST', $legacy, [ 'media_id' => $attachment ] );
    sac_fixture_check( (int) get_post_thumbnail_id( $post['post_id'] ) === $attachment, 'Authorized legacy thumbnail' );

    $mcp = sac_fixture_mcp( 'create_content', [ 'post_type' => 'page', 'title' => $tag . '-mcp' ], $write );
    sac_fixture_check( empty( $mcp['isError'] ), 'MCP write via caller credential' );
    $mcp_result = json_decode( $mcp['content'][0]['text'], true );
    $created['mcp'] = $mcp_result['id'];
    sac_fixture_check( sac_fixture_mcp( 'create_content', [ 'post_type' => 'page', 'title' => 'Forbidden' ], $legacy )['isError'], 'MCP retains legacy type scope' );
    sac_fixture_check( sac_fixture_mcp( 'update_content', [ 'post_type' => 'page', 'id' => $created['mcp'], 'title' => 'Forbidden' ], $read )['isError'], 'MCP retains readonly scope' );
    $logs = get_option( SAC_LOG_OPTION );
    sac_fixture_check( end( $logs )['key_id'] === sac_audit_key_id( $read ) && ! end( $logs )['success'], 'MCP audit logs denied operations once under original credential' );

    $schema = sac_fixture_call( '/openapi.json' );
    sac_fixture_check( $schema['paths']['/posts/{post_id}']['get']['operationId'] === 'get_post', 'OpenAPI legacy operation retained' );
    sac_fixture_check( $schema['paths']['/content/{post_type}']['post']['operationId'] === 'create_content', 'OpenAPI new operation' );
    sac_fixture_check( in_array( 'create_content', array_column( sac_fixture_call( '/gemini-tools-schema' )['function_declarations'], 'name' ), true ), 'Gemini exposes shared operations' );
    sac_fixture_check( isset( $schema['paths']['/wc/products'] ) === class_exists( 'WooCommerce' ), 'OpenAPI WooCommerce dependency' );
    if ( class_exists( 'WooCommerce' ) ) {
        sac_fixture_call( '/wc/products', 'GET', $read );
    } else {
        sac_fixture_check( sac_fixture_mcp( 'list_products', [], $read )['isError'], 'Missing WooCommerce has a tool error' );
    }
    sac_fixture_call( '/verify', 'GET', 'invalid', [], 403 );
    $challenge = 'fixture-check';
    set_transient( 'sac_verify_' . sac_audit_key_id( $write ), [ 'challenge' => $challenge, 'platform' => 'mcp' ], 60 );
    sac_fixture_check( sac_fixture_call( '/verify', 'GET', $write, [ 'challenge' => $challenge ] )['verified'], 'Connection challenge verified' );
    sac_fixture_call( '/verify', 'GET', $write, [ 'challenge' => $challenge ], 400 );
    update_option( SAC_OPTION_IP_ALLOWLIST, [ 'enabled' => true, 'ips' => [ '192.0.2.90' ] ] );
    sac_fixture_call( '/content-types', 'GET', $write, [], 403 );
    echo 'Verified native drafts, two CPTs (one without core REST), bindings, authorization, legacy routes, MCP, schemas, audit, connection and dependency (WooCommerce ' . ( class_exists( 'WooCommerce' ) ? 'on' : 'off' ) . ").\n";
} finally {
    foreach ( array_reverse( $created ) as $id ) wp_delete_post( $id, true );
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user( $subscriber );
    wp_delete_user( $author );
    foreach ( $previous as $option => $value ) {
        if ( $value === null ) delete_option( $option );
        else update_option( $option, $value );
    }
    wp_set_current_user( $original );
}
