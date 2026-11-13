<?php
/** Native WordPress CRUD, actual sitemap providers and safe HTTP fixtures. */
if ( ! defined( 'WP_CLI' ) || getenv( 'WUTM_MEDIA_INTEGRATION' ) !== '1' || DB_NAME !== 'wci_fixture' || $GLOBALS['wpdb']->prefix !== 'wme_wp_' ) { throw new RuntimeException( 'Disposable fixture only' ); }
if ( ! defined( 'WUTM_VERSION' ) ) { define( 'WUTM_VERSION', 'fixture' ); define( 'WUTM_URL', 'https://fixture.example.test/' ); }
require_once ABSPATH . 'wp-admin/includes/screen.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
require dirname(__DIR__) . '/core/admin-design.php';
require dirname(__DIR__) . '/modules/seo-core/module.php';
function seo401_assert( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } }
function seo401_private( $name, ...$args ) { return ( new ReflectionMethod( Wumetax_SEO_Core_v120::class, $name ) )->invoke( null, ...$args ); }
$ids = []; $terms = []; $uid = get_current_user_id(); $old = get_option( Wumetax_SEO_Core_v120::OPTION_KEY ); $old_home = get_option( 'home' ); $old_permalink = get_option( 'permalink_structure' );
$requests = []; $mode = 'xml';
$mock = static function( $pre, $args, $url ) use ( &$requests, &$mode ) {
    $requests[] = $url;
    if ( 'loop' === $mode ) { return [ 'headers' => [ 'location' => $url ], 'body' => '', 'response' => [ 'code' => 302 ] ]; }
    if ( 'foreign' === $mode ) { return [ 'headers' => [ 'location' => 'https://attacker.invalid/' ], 'body' => '', 'response' => [ 'code' => 301 ] ]; }
    if ( 'challenge' === $mode ) { return [ 'headers' => [ 'content-type' => 'text/html', 'cf-ray' => 'fixture' ], 'body' => '<html>challenge-platform</html>', 'response' => [ 'code' => 403 ] ]; }
    return [ 'headers' => [ 'content-type' => 'application/xml; charset=UTF-8' ], 'body' => '<?xml version="1.0" encoding="UTF-8"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><sitemap><loc>' . esc_xml( home_url( '/wp-sitemap-posts-page-1.xml' ) ) . '</loc></sitemap></sitemapindex>', 'response' => [ 'code' => 200 ] ];
};
try {
    update_option( Wumetax_SEO_Core_v120::OPTION_KEY, [ 'avoid_seo_conflicts' => 1 ] );
    ( new ReflectionProperty( Wumetax_SEO_Core_v120::class, 'settings' ) )->setValue( null, null );
    register_post_type( 'seo401_item', [ 'public' => true, 'show_ui' => true, 'supports' => [ 'title', 'editor', 'custom-fields' ] ] );
    register_taxonomy( 'seo401_category', 'seo401_item', [ 'public' => true ] );
    Wumetax_SEO_Core_v120::register_post_meta_fields(); Wumetax_SEO_Core_v120::seo_list_hooks();
    foreach ( [ 'public', 'noindex', 'canonical', 'self', 'protected', 'draft' ] as $kind ) {
        $ids[ $kind ] = wp_insert_post( [ 'post_type' => 'seo401_item', 'post_status' => 'draft' === $kind ? 'draft' : 'publish', 'post_title' => 'SEO ' . $kind, 'post_content' => 'Original body unchanged', 'post_password' => 'protected' === $kind ? 'secret' : '' ] );
    }
    update_post_meta( $ids['noindex'], '_wu_seo_noindex', 1 );
    update_post_meta( $ids['canonical'], '_wu_seo_canonical', 'https://elsewhere.example.test/canonical/' );
    update_post_meta( $ids['self'], '_wu_seo_canonical', get_permalink( $ids['self'] ) );
    delete_transient( 'wu_seo_excluded_' . md5( 'seo401_item' ) );
    $provider = new WP_Sitemaps_Posts(); $list = $provider->get_url_list( 1, 'seo401_item' ); $urls = array_column( $list, 'loc' );
    seo401_assert( in_array( get_permalink( $ids['public'] ), $urls ) && in_array( get_permalink( $ids['self'] ), $urls ), 'Actual native sitemap includes normal and self canonical posts' );
    foreach ( [ 'noindex', 'canonical', 'protected', 'draft' ] as $kind ) { seo401_assert( ! in_array( get_permalink( $ids[ $kind ] ), $urls ), 'Native sitemap excludes ' . $kind ); }
    foreach ( [ 'public', 'noindex', 'canonical' ] as $kind ) {
        $term = wp_insert_term( 'SEO ' . $kind, 'seo401_category' ); $terms[ $kind ] = $term['term_id'];
        wp_set_object_terms( $ids['public'], $terms[ $kind ], 'seo401_category', true );
    }
    update_term_meta( $terms['noindex'], '_wu_seo_noindex', 1 ); update_term_meta( $terms['canonical'], '_wu_seo_canonical', 'https://elsewhere.example.test/term/' );
    $tax = new WP_Sitemaps_Taxonomies(); $term_urls = array_column( $tax->get_url_list( 1, 'seo401_category' ), 'loc' );
    seo401_assert( in_array( get_term_link( $terms['public'], 'seo401_category' ), $term_urls ), 'Public custom taxonomy remains supported' );
    seo401_assert( ! in_array( get_term_link( $terms['noindex'], 'seo401_category' ), $term_urls ) && ! in_array( get_term_link( $terms['canonical'], 'seo401_category' ), $term_urls ), 'Term noindex/canonical rules match sitemap' );
    $cols = apply_filters( 'manage_seo401_item_posts_columns', [ 'title' => 'Title' ] ); seo401_assert( count( $cols ) === 8 && isset( $cols['wu_seo_topic'] ), 'Native CPT list columns registered' );
    $id = $ids['public'];
    $result = Wumetax_SEO_Core_v120::write_seo_data( $id, [ 'title' => 'Edited SEO', 'description' => 'A useful description', 'topic' => 'one，two', 'noindex' => 0 ] );
    seo401_assert( ! is_wp_error( $result ) && get_post_meta( $id, '_wu_seo_topic', true ) === 'one, two', 'Shared CRUD uses registered sanitizer' );
    $history = get_post_meta( $id, '_wu_seo_history', true ); seo401_assert( count( $history ) >= 3 && $history[0]['before'] === '' && $history[0]['user'] === $uid, 'History captures before/after and actual actor' );
    Wumetax_SEO_Core_v120::write_seo_data( $id, [ 'title' => 'Edited twice' ] );
    $history = get_post_meta( $id, '_wu_seo_history', true ); $last = end( $history ); seo401_assert( $last['before'] === 'Edited SEO' && $last['after'] === 'Edited twice', 'History snapshot before update is accurate' );
    Wumetax_SEO_Core_v120::write_seo_data( $id, [ $last['field'] => $last['before'] ] ); seo401_assert( get_post( $id )->post_content === 'Original body unchanged', 'Restore changes only metadata' );
    $preview = Wumetax_SEO_Core_v120::build_seo_preview( [ [ 'id' => $id, 'title' => 'Do not replace', 'noindex' => 1 ] ], true );
    seo401_assert( ! $preview[0]['data'], 'Only-empty preserves custom text and explicit zero robots choice' );
    $preview = Wumetax_SEO_Core_v120::build_seo_preview( [ [ 'id' => $id, 'title' => 'Reviewed title' ] ], false );
    update_post_meta( $id, '_wu_seo_description', 'Concurrent change' );
    $committed = Wumetax_SEO_Core_v120::commit_seo_preview( $preview ); seo401_assert( get_post_meta( $id, '_wu_seo_title', true ) !== 'Reviewed title', 'Optimistic check preserves concurrent edits' );
    $preview = Wumetax_SEO_Core_v120::build_seo_preview( [ [ 'id' => $id, 'title' => 'Reviewed title' ] ], false );
    Wumetax_SEO_Core_v120::commit_seo_preview( $preview ); seo401_assert( get_post_meta( $id, '_wu_seo_title', true ) === 'Reviewed title', 'Reviewed bulk commit applied' );
    $preview = Wumetax_SEO_Core_v120::build_seo_preview( [ [ 'id' => $id, 'title' => "'=SUM(A1)", '_wu_csv_encoding' => 'spreadsheet-quoted-v1' ] ], false );
    seo401_assert( $preview[0]['data']['title'] === '=SUM(A1)', 'Spreadsheet-safe export marker round trips' );
    $low = wp_create_user( 'seo401_subscriber', 'fixture-password', 'seo401@example.test' ); wp_set_current_user( $low );
    seo401_assert( is_wp_error( Wumetax_SEO_Core_v120::write_seo_data( $id, [ 'title' => 'Unauthorized' ] ) ), 'Unauthorized metadata edits denied' );
    wp_set_current_user( $uid );
    add_filter( 'pre_http_request', $mock, 99, 3 );
    $r = Wumetax_SEO_Core_v120::run_sitemap_diagnostic(); $n = count( $requests );
    Wumetax_SEO_Core_v120::run_sitemap_diagnostic(); seo401_assert( count( $requests ) === $n && $r['xml']['valid'] && $r['xml']['count'] === 1, 'Diagnostic parses actual HTTP body and caches without auto fetching children' );
    update_option( WUTM_SEO_Sitemap_Review::OPTION, [ 'source'=>home_url('/fixture.xml'), 'xml_checked_at'=>time(), 'xml_count'=>1, 'urls'=>[get_permalink($id)], 'results'=>[], 'cursor'=>0, 'last_request'=>0 ], false );
    $review = WUTM_SEO_Sitemap_Review::step(); seo401_assert( ! is_wp_error($review) && $review['cursor'] === 1 && count($review['results']) === 1, 'Manual sitemap review persists one bounded result' );
    seo401_assert( is_wp_error(WUTM_SEO_Sitemap_Review::step()), 'Completed review performs no extra request' );
    delete_option( WUTM_SEO_Sitemap_Review::OPTION );
    $mode = 'loop'; $r = Wumetax_SEO_Core_v120::diagnostic_request( home_url( '/loop/' ) ); seo401_assert( isset( $r['error'] ) && count( $r['chain'] ) === 1, 'Redirect cycle stopped' );
    $mode = 'foreign'; $r = Wumetax_SEO_Core_v120::diagnostic_request( home_url( '/foreign/' ) ); seo401_assert( isset( $r['error'] ) && ! in_array( 'https://attacker.invalid/', $requests ), 'External redirect not requested' );
    $mode = 'challenge'; $r = Wumetax_SEO_Core_v120::diagnostic_request( home_url( '/challenge/' ) ); seo401_assert( $r['status'] === 403 && $r['challenge'] && $r['cloudflare'], 'Challenge/403 reported as failure, not SEO success' );
    update_option( 'permalink_structure', '' ); $GLOBALS['wp_rewrite']->init(); seo401_assert( strpos( Wumetax_SEO_Core_v120::sitemap_url(), 'sitemap=index' ) !== false, 'Plain permalink uses native query sitemap URL' );
    update_option( 'home', 'https://subdirectory.example.test/site' ); seo401_assert( strpos( Wumetax_SEO_Core_v120::sitemap_url(), '/site/' ) !== false, 'Subdirectory sitemap uses current home URL' );
    update_option( 'permalink_structure', '/%postname%/' ); $GLOBALS['wp_rewrite']->init(); seo401_assert( Wumetax_SEO_Core_v120::sitemap_url() === 'https://subdirectory.example.test/site/wp-sitemap.xml', 'Pretty subdirectory URL' );
    $mode = 'xml'; $before_count = count( $requests ); $provider->get_url_list( 1, 'seo401_item' ); seo401_assert( count( $requests ) === $before_count, 'Generating sitemap performs zero HTTP requests' );
    $cipher = seo401_private( 'google_secret', 'sensitive-fixture' ); seo401_assert( $cipher && strpos( $cipher, 'sensitive' ) === false && seo401_private( 'google_secret', $cipher, true ) === 'sensitive-fixture', 'OAuth secrets encrypted with site-bound authenticated cipher' );
    seo401_assert( seo401_private( 'google_secret', substr( $cipher, 0, -4 ) . 'xxxx', true ) === '', 'Tampered OAuth ciphertext rejected' );
    $_POST = []; ob_start(); Wumetax_SEO_Core_v120::seo_tools_page(); $html = ob_get_clean(); seo401_assert( strpos( $html, 'wutm-ui-root' ) !== false && strpos( $html, '尚未驗證' ) !== false, 'Management page renders shared UI and truthful external status' );
    update_post_meta( $ids['noindex'], '_wu_seo_title', 'Custom title on a noindex page' );
    ob_start(); seo401_private( 'content_batch_report', 1 ); $report = ob_get_clean();
    $snapshot = get_option( 'wu_seo_analysis_snapshot' ); seo401_assert( $snapshot['complete'] && $snapshot['counts']['已自訂'] >= 2 && $snapshot['counts']['noindex'] >= 1, 'Batch analysis counts explicit SEO independently of indexing policy' );
    echo "SEO v4.0.1 native checks passed: CPT/taxonomy native providers, metadata permissions, revision history, bulk concurrency, safe diagnostics, cache, subdirectory and plain URL, OAuth encryption.\n";
} finally {
    remove_filter( 'pre_http_request', $mock, 99 ); wp_set_current_user( $uid ); $_POST = $_REQUEST = [];
    update_option( 'home', $old_home ); update_option( 'permalink_structure', $old_permalink );
    if ( false === $old ) { delete_option( Wumetax_SEO_Core_v120::OPTION_KEY ); } else { update_option( Wumetax_SEO_Core_v120::OPTION_KEY, $old ); }
    foreach ( $ids as $id ) { wp_delete_post( $id, true ); } foreach ( $terms as $id ) { wp_delete_term( $id, 'seo401_category' ); }
    if ( ! empty( $low ) ) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $low ); }
}
