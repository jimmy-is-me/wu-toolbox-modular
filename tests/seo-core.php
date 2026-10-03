<?php
/** SEO regression tests: real module methods with isolated WordPress fixtures. */
define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
$options = [ 'blog_public' => 1 ];
$meta = [];
$term_meta = [];
$state = [];
$hooks = [];
$transients = [];
$post_queries = [];
$allowed = true;
function add_action( ...$args ) { $GLOBALS['hooks'][] = $args; }
function add_filter( ...$args ) { $GLOBALS['hooks'][] = $args; }
function remove_action( ...$args ) {}
function get_option( $key, $default = false ) { return $GLOBALS['options'][ $key ] ?? $default; }
function get_site_option( $key, $default = false ) { return $GLOBALS['options'][ $key ] ?? $default; }
function wp_parse_args( $value, $defaults ) { return array_merge( $defaults, $value ); }
function get_bloginfo( $key ) { return [ 'name' => '測試品牌', 'description' => '實用內容', 'charset' => 'UTF-8' ][ $key ] ?? ''; }
function sanitize_text_field( $value ) { return is_scalar( $value ) ? trim( strip_tags( (string) $value ) ) : ''; }
function sanitize_email( $value ) { return filter_var( $value, FILTER_SANITIZE_EMAIL ); }
function sanitize_textarea_field( $value ) { return sanitize_text_field( $value ); }
function absint( $value ) { return abs( (int) $value ); }
function esc_url_raw( $value, $protocols = null ) { return preg_match( '~^https?://~', $value ) ? $value : ''; }
function esc_url( $value ) { return $value; }
function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function wp_strip_all_tags( $value, $remove_breaks = false ) { return strip_tags( $value ); }
function strip_shortcodes( $value ) { return $value; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function is_admin() { return ! empty( $GLOBALS['state']['admin'] ); }
function is_feed() { return false; }
function is_front_page() { return ! empty( $GLOBALS['state']['front'] ); }
function is_home() { return ! empty( $GLOBALS['state']['home'] ); }
function is_category() { return ! empty( $GLOBALS['state']['category'] ); }
function is_tag() { return ! empty( $GLOBALS['state']['tag'] ); }
function is_tax() { return ! empty( $GLOBALS['state']['tax'] ); }
function is_post_type_archive() { return ! empty( $GLOBALS['state']['archive'] ); }
function is_search() { return ! empty( $GLOBALS['state']['search'] ); }
function is_404() { return ! empty( $GLOBALS['state']['404'] ); }
function is_author() { return ! empty( $GLOBALS['state']['author'] ); }
function is_date() { return ! empty( $GLOBALS['state']['date'] ); }
function is_singular( $type = '' ) { return ! empty( $GLOBALS['state']['singular'] ) && ( ! $type || $type === get_post_type( 10 ) ); }
function post_password_required( $id = 0 ) { return ! empty( $GLOBALS['state']['password'] ); }
function get_queried_object_id() { return 10; }
function get_query_var( $key ) { return $GLOBALS['state'][ $key ] ?? 0; }
function get_queried_object() { return (object) [ 'term_id' => 10, 'taxonomy' => 'product_cat', 'name' => 'category', 'display_name' => '作者' ]; }
function get_post_meta( $id, $key, $single = true ) { return $GLOBALS['meta'][ $key ] ?? ''; }
function get_term_meta( $id, $key, $single = true ) { return $GLOBALS['term_meta'][ $key ] ?? ''; }
function get_the_title( $post ) { return '台灣測試文章'; }
function get_post_type( $id ) { return $GLOBALS['state']['post_type'] ?? 'post'; }
function get_post_field( $key, $id ) { return [ 'post_content' => str_repeat( '繁中內容', 15 ), 'post_excerpt' => '', 'post_author' => 2 ][ $key ] ?? ''; }
function get_permalink( $post ) { return 'https://example.test/article/'; }
function wp_get_canonical_url( $post ) { return 'https://example.test/article/' . ( get_query_var( 'page' ) > 1 ? get_query_var( 'page' ) . '/' : '' ); }
function home_url( $path = '/' ) { return 'https://example.test' . $path; }
function get_pagenum_link( $page ) { return 'https://example.test/category/page/' . $page . '/'; }
function single_term_title( $prefix = '', $display = true ) { return '商品分類'; }
function term_description() { return '分類說明'; }
function get_term_link( $term ) { return 'https://example.test/category/'; }
function is_wp_error( $value ) { return false; }
function get_site_icon_url( $size ) { return ''; }
function get_theme_mod( $key ) { return 0; }
function has_post_thumbnail( $id ) { return false; }
function get_userdata( $id ) { return (object) [ 'display_name' => '真實作者', 'user_url' => 'https://example.test/about/' ]; }
function get_user_meta( $id, $key, $single = true ) { return '實際作者介紹'; }
function get_the_date( $format, $id ) { return '2026-01-01T00:00:00+00:00'; }
function get_the_modified_date( $format, $id ) { return '2026-10-01T00:00:00+00:00'; }
function trailingslashit( $value ) { return rtrim( $value, '/' ) . '/'; }
function get_post( $id ) { return (object) [ 'post_type' => 'page', 'post_status' => $GLOBALS['state']['private_page'] ?? 'publish', 'post_password' => '' ]; }
function get_post_type_object( $type ) { return (object) [ 'has_archive' => false ]; }
function get_post_types( $args = [], $output = 'names' ) {
    return [ 'post' => (object) [ 'show_ui' => true ], 'page' => (object) [ 'show_ui' => true ] ];
}
function is_post_type_viewable( $object ) { return true; }
function apply_filters( $hook, $value ) { return $value; }
function get_posts( $args ) { $GLOBALS['post_queries'][] = $args; return []; }
function get_post_ancestors( $id ) { return [ 9 ]; }
function get_ancestors( $id, $type, $kind ) { return []; }
function get_transient( $key ) { return $GLOBALS['transients'][ $key ] ?? false; }
function set_transient( $key, $value, $ttl ) { $GLOBALS['transients'][ $key ] = $value; }
function delete_transient( $key ) { unset( $GLOBALS['transients'][ $key ] ); }
function wp_unslash( $value ) { return $value; }
function wp_verify_nonce( $value, $action ) { return 'valid' === $value; }
function current_user_can( ...$args ) { return $GLOBALS['allowed']; }
function get_term( $id ) { return (object) [ 'taxonomy' => 'product_cat' ]; }
function get_taxonomy( $name ) { return (object) [ 'cap' => (object) [ 'manage_terms' => 'manage_product_terms' ] ]; }
function update_term_meta( $id, $key, $value ) { $GLOBALS['term_meta'][ $key ] = $value; }
function delete_term_meta( $id, $key ) { unset( $GLOBALS['term_meta'][ $key ] ); }
function wp_get_document_title() { throw new RuntimeException( 'Recursive title filter' ); }
require __DIR__ . '/../modules/seo-core/module.php';

function check( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }
function settings( $value ) {
    $GLOBALS['options'][ Wumetax_SEO_Core_v120::OPTION_KEY ] = $value;
    ( new ReflectionProperty( Wumetax_SEO_Core_v120::class, 'settings' ) )->setValue( null, null );
}
function call_private( $method, ...$args ) { return ( new ReflectionMethod( Wumetax_SEO_Core_v120::class, $method ) )->invoke( null, ...$args ); }
function schema() {
    ob_start(); Wumetax_SEO_Core_v120::output_schema(); $output = ob_get_clean();
    check( 1 === preg_match( '~<script type="application/ld\+json">(.*)</script>~s', $output, $match ), 'Schema must be a single script' );
    return [ json_decode( $match[1], true, 512, JSON_THROW_ON_ERROR )['@graph'], $output ];
}
settings( [] );
check( Wumetax_SEO_Core_v120::filter_document_title( 'theme fallback' ) === 'theme fallback', 'Unknown contexts must not recurse' );
$state = [ 'singular' => true ];
check( Wumetax_SEO_Core_v120::filter_document_title( '' ) === '台灣測試文章｜測試品牌', 'Default title unchanged' );
settings( [ 'title_template_post' => '{title} · {site} · {tagline}' ] );
check( str_contains( Wumetax_SEO_Core_v120::filter_document_title( '' ), ' · 測試品牌 · 實用內容' ), 'Title tokens must resolve' );
$meta['_wu_seo_title'] = '自訂標題';
check( Wumetax_SEO_Core_v120::filter_document_title( '' ) === '自訂標題', 'Manual title wins' );
$meta = [];
check( call_private( 'clean_text', str_repeat( '中文', 50 ), 160 ) === str_repeat( '中文', 50 ), 'UTF-8 text shorter than the character limit must not be byte-truncated' );
check( preg_match( '//u', call_private( 'clean_text', str_repeat( '中文', 100 ), 160 ) ) === 1, 'Truncated Chinese remains valid UTF-8' );
check( call_private( 'clean_text', str_repeat( '中', 160 ), 160 ) === str_repeat( '中', 160 ), 'Exact character limit unchanged' );
check( call_private( 'clean_text', str_repeat( '中', 161 ), 160 ) === str_repeat( '中', 159 ) . '…', 'Long text truncates to character limit' );
check( call_private( 'char_count', '中文字' ) === 3, 'Chinese audit counts characters rather than bytes' );
$state['page'] = 2;
check( call_private( 'canonical_url' ) === 'https://example.test/article/2/', 'Multipage canonical uses the actual page' );
$state = [ 'tax' => true, 'paged' => 2 ];
$term_meta = [ '_wu_seo_title' => '自訂商品分類', '_wu_seo_description' => '有用的分類摘要', '_wu_seo_noindex' => 1 ];
check( Wumetax_SEO_Core_v120::filter_document_title( '' ) === '自訂商品分類｜第 2 頁', 'Taxonomy title and pagination' );
check( call_private( 'seo_description' ) === '有用的分類摘要', 'Taxonomy description' );
check( ! empty( Wumetax_SEO_Core_v120::filter_wp_robots( [] )['noindex'] ), 'Taxonomy noindex' );
$term_meta = [];
foreach ( [ 'author', 'date', 'tag' ] as $archive ) {
    $state = [ $archive => true ]; settings( [ 'noindex_tag_archives' => 1 ] );
    $robots = Wumetax_SEO_Core_v120::filter_wp_robots( [ 'index' => true ] );
    check( ! empty( $robots['noindex'] ) && ! isset( $robots['index'] ), 'Archive indexing setting: ' . $archive );
}
$provider = new stdClass();
check( false === Wumetax_SEO_Core_v120::sitemap_provider( $provider, 'users' ), 'Noindex authors excluded from sitemap' );
check( ! isset( Wumetax_SEO_Core_v120::filter_sitemap_taxonomies( [ 'post_tag' => true, 'product_cat' => true ] )['post_tag'] ), 'Noindex tag sitemap excluded' );
$existing = [ 'relation' => 'OR', [ 'key' => 'old', 'value' => 'a' ], [ 'key' => 'old', 'value' => 'b' ] ];
$query = Wumetax_SEO_Core_v120::sitemap_posts_query( [ 'meta_query' => $existing, 'posts_per_page' => 100 ], 'post' );
check( $query['meta_query']['relation'] === 'AND' && $query['meta_query'][0] === $existing && $query['has_password'] === false && $query['posts_per_page'] === 100, 'Sitemap preserves existing OR groups and pagination' );
$entry = Wumetax_SEO_Core_v120::sitemap_post_entry( [ 'loc' => 'url' ], (object) [ 'post_modified_gmt' => '2026-10-01 00:00:00' ], 'post' );
check( $entry['lastmod'] === '2026-10-01T00:00:00+00:00', 'Sitemap uses actual modification time' );
$state = [ 'tax' => true ]; settings( [] );
[ $graph ] = schema();
$by_type = array_column( $graph, null, '@type' );
check( isset( $by_type['CollectionPage'], $by_type['BreadcrumbList'] ), 'Taxonomy collection and breadcrumbs' );
check( $by_type['CollectionPage']['breadcrumb']['@id'] === $by_type['BreadcrumbList']['@id'], 'Collection linked to breadcrumbs' );
$state = [ 'singular' => true ];
settings( [ 'organization_type' => 'OnlineStore', 'organization_alt_name' => 'Brand', 'organization_email' => 'contact@example.test', 'organization_description' => '</script><script>alert(1)</script>' ] );
[ $graph, $output ] = schema();
$by_type = array_column( $graph, null, '@type' );
check( isset( $by_type['OnlineStore']['email'], $by_type['Person'], $by_type['WebPage'] ), 'Publisher, author and webpage entities' );
check( $by_type['Article']['author']['@id'] === $by_type['Person']['@id'], 'Author connected to article' );
check( $by_type['Article']['mainEntityOfPage']['@id'] === $by_type['WebPage']['@id'], 'Article connected to webpage' );
check( substr_count( $output, '</script>' ) === 1 && ! str_contains( $output, '<script>alert' ), 'JSON-LD must not escape the script element' );
$state = [ 'singular' => true, 'post_type' => 'page' ]; settings( [ 'about_page' => 10 ] );
[ $graph ] = schema();
check( in_array( 'AboutPage', array_column( $graph, '@type' ), true ), 'Published about page schema' );
$by_type = array_column( $graph, null, '@type' );
check( array_column( $by_type['BreadcrumbList']['itemListElement'], 'position' ) === [ 1, 2, 3 ], 'Page breadcrumbs include parent with ordered positions' );
settings( [ 'contact_page' => 10 ] );
[ $graph ] = schema();
check( in_array( 'ContactPage', array_column( $graph, '@type' ), true ), 'Published contact page schema' );
settings( [ 'about_page' => 10 ] );
$state['private_page'] = 'draft';
[ $graph ] = schema();
check( ! in_array( 'AboutPage', array_column( $graph, '@type' ), true ), 'Draft pages not exposed as public about pages' );
$state = [ 'singular' => true, 'password' => true ];
check( call_private( 'seo_description' ) === '', 'Protected content does not leak into descriptions' );
ob_start(); Wumetax_SEO_Core_v120::output_schema(); $output = ob_get_clean();
check( $output === '', 'Protected content does not leak into schema' );
$state = [ 'singular' => true ];
check( Wumetax_SEO_Core_v120::filter_wp_robots( [] )['max-image-preview'] === 'large', 'Large image previews enabled by default' );
settings( [ 'large_image_preview' => 0 ] );
check( Wumetax_SEO_Core_v120::filter_wp_robots( [ 'max-image-preview' => 'large' ] )['max-image-preview'] === 'standard', 'Unchecked setting limits the WordPress default preview' );
$meta['_wu_seo_noindex'] = 1;
$robots = Wumetax_SEO_Core_v120::filter_wp_robots( [ 'index' => true ] );
check( ! isset( $robots['index'], $robots['max-image-preview'] ) && ! empty( $robots['noindex'] ), 'Noindex wins over preview and index directives' );
$meta = [];
$options['active_plugins'] = [ 'wordpress-seo/wp-seo.php' ]; settings( [] );
ob_start(); Wumetax_SEO_Core_v120::output_head_meta(); Wumetax_SEO_Core_v120::output_schema(); $output = ob_get_clean();
check( $output === '' && Wumetax_SEO_Core_v120::filter_document_title( 'Yoast' ) === 'Yoast', 'Competing SEO plugin owns head output' );
check( Wumetax_SEO_Core_v120::sitemap_posts_query( [ 'test' => 1 ], 'post' ) === [ 'test' => 1 ], 'Competing plugin sitemap not modified' );
$options['active_plugins'] = []; $options['active_sitewide_plugins'] = [ 'seo-by-rank-math/rank-math.php' => 1 ]; settings( [] );
check( Wumetax_SEO_Core_v120::filter_document_title( 'Network SEO' ) === 'Network SEO', 'Network-activated SEO conflict detected' );
$options['active_sitewide_plugins'] = []; settings( [] );
$_POST = [ 'wutm_seo_term_nonce' => 'invalid', 'wu_seo_term_title' => 'unsafe' ];
Wumetax_SEO_Core_v120::save_term_fields( 10 );
check( ! isset( $term_meta['_wu_seo_title'] ), 'Term save nonce guard' );
$_POST['wutm_seo_term_nonce'] = 'valid'; $allowed = false;
Wumetax_SEO_Core_v120::save_term_fields( 10 );
check( ! isset( $term_meta['_wu_seo_title'] ), 'Term save capability guard' );
$allowed = true;
Wumetax_SEO_Core_v120::save_term_fields( 10 );
check( $term_meta['_wu_seo_title'] === 'unsafe', 'Authorized term save' );
$sanitized = Wumetax_SEO_Core_v120::sanitize_settings( [ 'organization_type' => 'FakeType', 'organization_phone' => '<b>+886123</b>', 'title_template_post' => '', 'about_page' => '-10', 'large_image_preview' => 1, 'avoid_seo_conflicts' => 1 ] );
check( $sanitized['organization_type'] === 'Organization' && $sanitized['organization_phone'] === '+886123', 'New setting validation and sanitization' );
check( $sanitized['title_template_post'] === '{title}｜{site}' && $sanitized['about_page'] === 10, 'Empty template fallback and page ID validation' );
settings( [] );
$first_audit = call_private( 'content_audit_stats' );
check( count( $post_queries ) === 1 && $post_queries[0]['posts_per_page'] === 200 && $post_queries[0]['update_post_meta_cache'] === true && ! isset( $post_queries[0]['fields'] ), 'Audit bounded and post/meta caches primed' );
check( call_private( 'content_audit_stats' ) === $first_audit && count( $post_queries ) === 1, 'Repeated audit uses cached data without another query' );
Wumetax_SEO_Core_v120::invalidate_audit_meta( 1, 10, '_unrelated_order_value' );
check( isset( $transients['wutm_seo_content_audit_v350'] ), 'Unrelated metadata does not churn audit cache' );
$state['post_type'] = 'shop_order';
Wumetax_SEO_Core_v120::invalidate_audit_post( 10 );
check( isset( $transients['wutm_seo_content_audit_v350'] ), 'Order save does not churn SEO audit cache' );
$state['post_type'] = 'post';
Wumetax_SEO_Core_v120::invalidate_audit_meta( 1, 10, '_wu_seo_title' );
check( ! isset( $transients['wutm_seo_content_audit_v350'] ), 'SEO content changes invalidate audit cache' );
echo "SEO core regression checks passed.\n";
