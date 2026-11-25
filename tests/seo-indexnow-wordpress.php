<?php
if ( !defined( 'WP_CLI' ) || getenv( 'WUTM_MEDIA_INTEGRATION' ) !== '1' || DB_NAME !== 'wci_fixture' ) throw new RuntimeException( 'Disposable WordPress fixture only.' );
require_once dirname( __DIR__ ) . '/modules/seo-core/module.php';

function index426_assert( $test, $message ) { if ( !$test ) throw new RuntimeException( $message ); }
function index426_call( $name, ...$args ) { return ( new ReflectionMethod( Wumetax_SEO_Core_v120::class, $name ) )->invoke( null, ...$args ); }

global $wpdb, $wp_rewrite;
$table = index426_call( 'indexnow_table' );
index426_assert( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table, 'Run with a clean IndexNow fixture.' );
$previous = [];
foreach ( ['wu_seo_indexnow','wu_seo_indexnow_proof','wu_seo_indexnow_schema','wu_seo_indexnow_days','wu_seo_indexnow_cooldown',
    'wu_seo_dashboard_local_sitemap','blog_public','permalink_structure','cron'] as $name ) $previous[$name] = get_option( $name );
update_option( 'blog_public', '1' );
$wp_rewrite->set_permalink_structure( '/%postname%/' );
$root = Wumetax_SEO_Core_v120::sitemap_url();
$child = home_url( '/wp-sitemap-posts-page-1.xml' );
$endpoint = Wumetax_SEO_Core_v120::INDEXNOW_ENDPOINT;
$settings = ['enabled'=>true,'key'=>'test-key-426','location'=>'','types'=>['post','page','lab_fixture']];
$key_url = index426_call( 'indexnow_location', $settings );
$file_status = 200;
$file = $settings['key'];
$api_reply = 200;
$retry_after = '125';
$child_status = 200;
$listed = $pages = $posts = $calls = [];
$regular = static function ( $body, $code = 200, $headers = [] ) { return ['response'=>['code'=>$code],'body'=>$body,'headers'=>$headers]; };
$mock = static function ( $pre, $args, $url ) use ( &$file_status,&$file,&$api_reply,&$retry_after,&$child_status,&$listed,&$pages,&$calls,$key_url,$root,$child,$endpoint,$regular ) {
    $calls[] = [$url,$args];
    if ( $url === $key_url ) return $regular( $file, $file_status, ['cf-cache-status'=>'HIT'] );
    if ( $url === $endpoint ) {
        if ( $api_reply === 0 ) return new WP_Error( 'fixture_timeout', 'Mock network timeout' );
        return $regular( '{"message":"mock"}', $api_reply, ['retry-after'=>$retry_after] );
    }
    if ( $url === $root ) return $regular( '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><sitemap><loc>' . esc_xml( $child ) . '</loc></sitemap></sitemapindex>' );
    if ( $url === $child ) {
        $locs = array_map( static function ( $link ) { return '<url><loc>' . esc_xml( $link ) . '</loc></url>'; }, $listed );
        return $regular( '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . implode( '', $locs ) . '</urlset>', $child_status );
    }
    return $pages[$url] ?? $regular( '<html><head><title>Fixture</title></head><body>Public page</body></html>' );
};
add_filter( 'pre_http_request', $mock, 99, 3 );
$make = static function ( $args ) use ( &$posts ) {
    $id = wp_insert_post( $args + ['post_type'=>'page','post_title'=>'IndexNow fixture','post_content'=>'Real public content'] );
    $posts[] = $id;
    return $id;
};
$row = static function ( $url ) { return index426_call( 'indexnow_find', hash( 'sha256', $url ) ); };
$expire_sitemap = static function () use ( $root,$child ) {
    foreach ( [$root,$child] as $xml ) delete_transient( 'wu_seo_http_' . md5( $xml ) );
};
$api = static function () use ( &$calls,$endpoint ) { return array_values( array_filter( $calls, static function ( $call ) use ( $endpoint ) { return $call[0] === $endpoint; } ) ); };
$advance = static function () use ( $wpdb,$table ) { $wpdb->query( "UPDATE $table SET due=1,health_due=IF(health_due>0,1,0)" ); };
$worker = [ Wumetax_SEO_Core_v120::class, 'indexnow_worker' ];
try {
    $expire_sitemap();
    index426_assert( ! index426_call( 'indexnow_settings' )['enabled'], 'IndexNow is disabled on upgrade.' );
    $off = $make( ['post_status'=>'publish','post_name'=>'indexnow-disabled'] );
    index426_assert( !$api(), 'Publication makes no IndexNow request when disabled.' );

    $scoped = array_replace( $settings, ['location'=>home_url( '/catalog/key.txt' )] );
    index426_assert( index426_call( 'indexnow_in_scope', home_url( '/catalog/item/' ), $scoped ) &&
        ! index426_call( 'indexnow_in_scope', home_url( '/other/item/' ), $scoped ) &&
        ! index426_call( 'indexnow_in_scope', 'https://unrelated.example.test/catalog/item/', $scoped ), 'Alternate keyLocation is restricted to its host and directory.' );
    index426_assert( ! index426_call( 'indexnow_in_scope', home_url( '/catalog/item/?preview=true' ), $scoped ), 'Preview parameters do not enter IndexNow.' );
    $file = $settings['key'] . "\n";
    index426_assert( ! index426_call( 'indexnow_verify', $settings )['ok'], 'Even a trailing newline fails exact key verification.' );
    $file = $settings['key'];
    $file_status = 403;
    $denied = index426_call( 'indexnow_verify', $settings );
    index426_assert( !$denied['ok'] && $denied['http'] === 403 && $denied['headers']['cf-cache-status'] === 'HIT', 'Key failure shows real HTTP and cache evidence.' );
    $file_status = 200;
    index426_assert( index426_call( 'indexnow_verify', $settings )['ok'], 'Key verifies anonymously with exact HTTP body.' );
    index426_assert( index426_call( 'indexnow_install' ), 'WordPress installed the queue table.' );
    update_option( 'wu_seo_indexnow', $settings, false );
    index426_call( 'indexnow_init' );
    register_post_type( 'lab_fixture', ['public'=>true] );

    $draft = $make( ['post_status'=>'draft','post_name'=>'indexnow-draft'] );
    $page = $make( ['post_status'=>'publish','post_name'=>'indexnow-first','meta_input'=>['_wu_seo_title'=>'Launch']] );
    $first = get_permalink( $page );
    $listed = [$first];
    $relative = wp_parse_url( $first, PHP_URL_PATH );
    if ( $query = wp_parse_url( $first, PHP_URL_QUERY ) ) $relative .= '?' . $query;
    $pages[$first] = $regular( '<html><head><link rel="canonical" href="' . esc_attr( $relative ) . '"></head><body>CAPTCHA 字樣</body></html>' );
    // Multiple updates and SEO writes in one request yield one job with the final state.
    wp_update_post( ['ID'=>$page,'post_excerpt'=>'Launch excerpt'] );
    wp_update_post( ['ID'=>$page,'post_excerpt'=>'Launch excerpt'] );
    Wumetax_SEO_Core_v120::indexnow_flush();
    $published = $row( $first );
    index426_assert( $published['kind'] === 'published' && (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ) === 1 && !$api(),
        'First publication is enqueued once, drafts are skipped, no network call occurs while saving.' );
    wp_update_post( ['ID'=>$page,'post_excerpt'=>'Launch excerpt'] );
    update_post_meta( $page, '_edit_lock', 'ignore' );
    Wumetax_SEO_Core_v120::indexnow_flush();
    index426_assert( $row( $first )['revision'] === $published['revision'], 'Unchanged saves and system metadata are ignored.' );
    $advance(); $worker();
    $health = json_decode( $row( $first )['health'], true );
    index426_assert( ( $health['code'] ?? '' ) === 'ok' && ( $health['page']['canonical'] ?? '' ) === $first && ( $health['sitemap']['child'] ?? '' ) === $child,
        'Background health must confirm the final HTTP, relative canonical, and correct child Sitemap. Fixture result: ' .
        wp_json_encode( ['expected'=>[$first,$child],'health'=>$health], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
    $api_reply = 202;
    $advance(); $worker();
    $sent = $api();
    $payload = json_decode( $sent[0][1]['body'], true );
    index426_assert( $payload === ['host'=>wp_parse_url( home_url(), PHP_URL_HOST ),'key'=>$settings['key'],'keyLocation'=>$key_url,'urlList'=>[$first]] &&
        $sent[0][1]['headers']['Content-Type'] === 'application/json; charset=utf-8' && $row( $first )['state'] === 'accepted', 'Batched JSON and 202 pending validation.' );
    $advance(); $worker();
    index426_assert( count( $api() ) === 1, '202 does not cause automatic resubmission.' );

    update_post_meta( $page, '_wu_seo_description', 'Important SEO correction' );
    Wumetax_SEO_Core_v120::indexnow_flush();
    index426_assert( $row( $first )['kind'] === 'updated', 'Important SEO-only edit is captured.' );
    wp_update_post( ['ID'=>$page,'post_name'=>'indexnow-renamed'] );
    Wumetax_SEO_Core_v120::indexnow_flush();
    $second = get_permalink( $page );
    index426_assert( $row( $first )['kind'] === 'moved' && $row( $second )['kind'] === 'new_url', 'Rename preserves both URLs.' );
    $listed = [$second];
    $pages[$first] = $regular( '',301,['location'=>$second] );
    $expire_sitemap(); $api_reply = 429;
    $before_redirect = count( $api() );
    $advance(); $worker();
    index426_assert( count( $api() ) === $before_redirect && $row( $first )['health_code'] === 'retired',
        'Old URL public status is checked before sending.' );
    $advance(); $worker();
    $old = $row( $first );
    index426_assert( $old['state'] === 'retry' && (int) $old['due'] >= time()+122 && $old['health_code'] === 'retired',
        'Redirected old URLs are sent and 429 honors Retry-After.' );
    $count = count( $api() );
    $advance(); $worker();
    index426_assert( count( $api() ) === $count, 'Host cooldown applies across the whole queue.' );
    delete_option( 'wu_seo_indexnow_cooldown' );
    $api_reply = 0;
    $advance(); $worker();
    index426_assert( $row( $first )['state'] === 'retry' && (int) $row( $first )['due'] > time() &&
        str_contains( $row( $first )['response'], 'Mock network timeout' ), 'Network failure is backed off with evidence.' );
    $api_reply = 200;
    $advance(); $worker();
    $last_request = $api();
    $last_payload = json_decode( end( $last_request )[1]['body'], true );
    index426_assert( count( array_unique( $last_payload['urlList'] ) ) === 2 && $row( $first )['state'] === 'delivered' &&
        $row( $second )['state'] === 'delivered', 'Both old and new URL are submitted without duplicates.' );

    update_post_meta( $page, '_wu_seo_noindex', 1 );
    $pages[$second] = $regular( '<html><head><meta name="bingbot" content="noindex"></head></html>',200,['x-robots-tag'=>'noindex'] );
    $listed = [];
    $expire_sitemap();
    Wumetax_SEO_Core_v120::indexnow_flush();
    wp_update_post( ['ID'=>$page,'post_excerpt'=>'Further excluded-content edit'] );
    Wumetax_SEO_Core_v120::indexnow_flush();
    index426_assert( $row( $second )['kind'] === 'excluded', 'Later excluded-content edits preserve the unsubmitted retirement.' );
    $advance(); $worker();
    $advance(); $worker();
    $noindex = $row( $second );
    $observed = json_decode( $noindex['health'], true );
    index426_assert( $noindex['state'] === 'delivered' && $noindex['kind'] === 'excluded' && $noindex['health_code'] === 'excluded' &&
        $observed['page']['header_robots'] === 'noindex' && $observed['page']['meta_robots'] === ['bingbot: noindex'], 'Indexability changes submit the old state and record both robots sources.' );
    wp_delete_post( $page, true );
    $pages[$second] = $regular( '',410 );
    Wumetax_SEO_Core_v120::indexnow_flush();
    $advance(); $worker();
    $advance(); $worker();
    index426_assert( $row( $second )['state'] === 'delivered' && $row( $second )['kind'] === 'removed' &&
        $row( $second )['health_code'] === 'retired', 'Permanent deletion submits the former URL even after HTTP 410.' );
    wp_delete_post( $draft, true );
    Wumetax_SEO_Core_v120::indexnow_flush();
    index426_assert( (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ) === 2, 'Deleting a never-public draft does not add a job.' );

    $future = $make( ['post_status'=>'future','post_date'=>wp_date( 'Y-m-d H:i:s', time()+3600 ),'post_name'=>'indexnow-scheduled'] );
    Wumetax_SEO_Core_v120::indexnow_flush();
    wp_publish_post( $future );
    Wumetax_SEO_Core_v120::indexnow_flush();
    $future_url = get_permalink( $future );
    index426_assert( $row( $future_url )['kind'] === 'published', 'Scheduled publication hook captures first public URL.' );
    $child_status = 503;
    $expire_sitemap(); $advance(); $worker();
    $broken = $row( $future_url );
    index426_assert( $broken['health_code'] === 'sitemap_error' && (int) $broken['health_due'] > time()+300, 'Child Sitemap read failure has delayed recheck.' );
    $child_status = 200;
    $expire_sitemap(); $advance();
    delete_option( 'wu_seo_indexnow_proof' );
    $file_status = 404;
    $count = count( $api() );
    $worker();
    index426_assert( count( $api() ) === $count && get_option( 'wu_seo_indexnow_proof' )['http'] === 404 &&
        $row( $future_url )['state'] === 'queued' && $row( $future_url )['health_code'] === 'missing', 'Unreadable key pauses dispatch, delayed health recheck is independent.' );
    $file_status = 200;
    delete_option( 'wu_seo_indexnow_proof' );
    $api_reply = 422;
    $advance(); $worker();
    index426_assert( $row( $future_url )['state'] === 'failed' && str_contains( $row( $future_url )['response'], '422' ), 'Permanent protocol error is visible.' );
    index426_call( 'indexnow_admin_queue', 'retry', hash( 'sha256', $future_url ), $settings );
    $api_reply = 503;
    for ( $i = 0; $i < 6; $i++ ) { $advance(); $worker(); }
    index426_assert( $row( $future_url )['state'] === 'failed' && (int) $row( $future_url )['attempts'] === 5, 'Transient failures stop after five attempts.' );

    $lab = $make( ['post_type'=>'lab_fixture','post_status'=>'publish','post_name'=>'indexnow-lab'] );
    Wumetax_SEO_Core_v120::indexnow_flush();
    index426_assert( $row( get_permalink( $lab ) )['kind'] === 'published', 'Selected public content types are supported.' );
    ob_start();
    index426_call( 'render_seo_dashboard', 'indexnow', 0 );
    $html = ob_get_clean();
    $render_file = getenv( 'WUTM_INDEXNOW_RENDER_FILE' );
    if ( $render_file ) file_put_contents( $render_file, $html );
    index426_assert( str_contains( $html, '已送達' ) && str_contains( $html, 'wui-bars' ) && str_contains( $html, '重跑健檢' ),
        'Admin renders actual status, submissions and health recovery.' );
    echo "IndexNow publication, scope, retries, deduplication and health checks passed.\n";
} finally {
    update_option( 'wu_seo_indexnow', ['enabled'=>false], false );
    foreach ( $posts as $id ) wp_delete_post( $id, true );
    $expire_sitemap();
    $wp_rewrite->set_permalink_structure( (string) $previous['permalink_structure'] );
    foreach ( $previous as $name=>$old ) {
        if ( $old === false ) delete_option( $name );
        else update_option( $name, $old, false );
    }
    $wpdb->query( "DROP TABLE IF EXISTS $table" );
    remove_filter( 'pre_http_request', $mock, 99 );
}
