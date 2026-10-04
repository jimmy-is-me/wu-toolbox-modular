<?php
/** Local HTTP regression fixture; never a production endpoint. */
if ( PHP_SAPI !== 'cli-server' || ! getenv( 'WUTM_CACHE_TEST_DIR' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', __DIR__ );
define( 'WP_CONTENT_DIR', getenv( 'WUTM_CACHE_TEST_DIR' ) );
define( 'WEEK_IN_SECONDS', 604800 );
define( 'WUTM_URL', '/' );
define( 'WUTM_VERSION', 'test' );
$mode = trim( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ), '/' );
$hooks = array();
$options = array( 'wutm_page_cache_device_variants_264' => 1, 'wutm_page_cache_output_354' => 1, 'wutm_page_cache_output_355' => 1, 'wutm_page_cache_settings' => array( 'auto_invalidate' => 1, 'ttl' => 60 ), 'trp_settings' => array( 'default-language' => 'zh_TW', 'publish-languages' => array( 'zh_TW', 'en_US' ), 'url-slugs' => array( 'zh_TW' => 'zh', 'en_US' => 'en' ) ) );
class TRP_Translate_Press {}
function get_option( $key, $default = false ) { return $GLOBALS['options'][$key] ?? $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['options'][$key] = $value; }
function add_action( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS['hooks'][$hook][$priority][] = $callback; }
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) { add_action( $hook, $callback, $priority, $args ); }
function __return_true() { return true; }
function apply_filters( $hook, $value ) { $groups = $GLOBALS['hooks'][$hook] ?? array(); ksort( $groups ); foreach ( $groups as $callbacks ) foreach ( $callbacks as $callback ) $value = $callback( $value ); return $value; }
function do_action( $hook ) { $groups = $GLOBALS['hooks'][$hook] ?? array(); ksort( $groups ); foreach ( $groups as $callbacks ) foreach ( $callbacks as $callback ) $callback(); }
function wp_parse_args( $value, $defaults ) { return array_merge( $defaults, $value ); }
function trailingslashit( $value ) { return rtrim( $value, '/\\' ) . '/'; }
function wp_mkdir_p( $path ) { return is_dir( $path ) || @mkdir( $path, 0777, true ); }
function wp_unslash( $value ) { return $value; }
function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $value ) ); }
function absint( $value ) { return abs( (int) $value ); }
function wp_parse_url( $value, $component = -1 ) { return parse_url( $value, $component ); }
function home_url() { return 'http://' . $_SERVER['HTTP_HOST']; }
function is_ssl() { return false; }
function is_admin() { return $GLOBALS['mode'] === 'admin'; }
function is_user_logged_in() { return isset( $_COOKIE['test_logged_in'] ); }
function wp_doing_ajax() { return false; }
function wp_doing_cron() { return false; }
function is_feed() { return false; }
function is_search() { return $GLOBALS['mode'] === 'search'; }
function is_404() { return $GLOBALS['mode'] === 'not-found'; }
function is_preview() { return false; }
function is_trackback() { return false; }
function post_password_required() { return $GLOBALS['mode'] === 'password'; }
function is_cart() { return false; }
function is_checkout() { return $GLOBALS['mode'] === 'checkout'; }
function is_account_page() { return false; }
function is_singular() { return true; }
function get_queried_object_id() { return 42; }
function get_post_type( $id ) { return 'page'; }
function wp_get_object_terms( ...$args ) { return array( 7 ); }
function get_object_taxonomies( $type ) { return array( 'category' ); }
function is_wp_error( $value ) { return false; }
function is_front_page() { return false; }
function is_home() { return false; }
function is_post_type_archive() { return false; }
function is_category() { return false; }
function is_tag() { return false; }
function is_tax() { return false; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_normalize_path( $path ) { return str_replace( '\\', '/', $path ); }
function get_bloginfo( $key ) { return 'UTF-8'; }
function nocache_headers() { header( 'Cache-Control: no-store, private' ); }
function current_user_can( $cap ) { return is_admin(); }
function admin_url( $path ) { return '/wp-admin/' . $path; }
function add_query_arg( $key, $value, $url = '' ) { if ( is_array( $key ) ) { $url = $value; $args = $key; } else { $args = array( $key => $value ); } return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $args ); }
function wp_nonce_url( $url, $nonce ) { return $url . '&_wpnonce=test'; }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
function esc_attr( $value ) { return esc_html( $value ); }
function esc_url( $value ) { return esc_html( $value ); }
function esc_url_raw( $value ) { return $value; }
function esc_textarea( $value ) { return esc_html( $value ); }
function wp_date( $format, $time ) { return date( $format, $time ); }
function size_format( $size, $decimals = 0 ) { return round( $size / 1024, $decimals ) . ' KB'; }
function wp_generate_password( ...$args ) { return bin2hex( random_bytes( 6 ) ); }
function wp_nonce_field( $nonce ) { echo '<input type="hidden" name="_wpnonce" value="test">'; }
function checked( $value ) { if ( $value ) echo 'checked'; }
function submit_button( $label ) { echo '<p><button>' . esc_html( $label ) . '</button></p>'; }
class WP_Admin_Bar { public array $nodes = array(); public function add_node( $node ) { $this->nodes[] = $node; } }
require dirname( __DIR__ ) . '/modules/page-cache/module.php';
register_shutdown_function( function () { do_action( 'shutdown' ); while ( ob_get_level() ) ob_end_flush(); } );
add_action( 'template_redirect', function () {
    if ( $GLOBALS['mode'] === 'late-private' ) define( 'DONOTCACHEPAGE', true );
    if ( $GLOBALS['mode'] === 'private' ) nocache_headers();
    if ( $GLOBALS['mode'] === 'cookie' ) setcookie( 'private_session', 'test' );
}, 10 );
if ( $mode === 'admin' ) {
    echo '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{margin:16px;font:14px Arial;background:#f0f0f1}*{box-sizing:border-box}a{color:#2271b1}button,.button,input,textarea{padding:8px;border:1px solid #ccd0d4}.card{background:white}.widefat{width:100%}td,th{text-align:left;padding:12px}.description{color:#646970}#test-toolbar{margin-bottom:20px;display:flex;flex-wrap:wrap;gap:10px}</style><style>' . file_get_contents( dirname( __DIR__ ) . '/assets/css/admin.css' ) . '</style><style>' . file_get_contents( dirname( __DIR__ ) . '/assets/css/page-cache-admin.css' ) . '</style></head><body class="wu-toolbox_page_wu-page-cache">';
    $bar = new WP_Admin_Bar(); WUTM_Page_Cache::register_admin_bar( $bar );
    echo '<nav id="test-toolbar">'; foreach ( $bar->nodes as $node ) echo '<a data-id="' . esc_attr( $node['id'] ) . '" href="' . esc_url( $node['href'] ) . '">' . $node['title'] . '</a> '; echo '</nav>';
    echo '<main id="wpcontent"><div id="wpbody-content">'; WUTM_Page_Cache::render_page(); echo '</div></main></body></html>'; return;
}
if ( $mode === 'test-control' ) {
    header( 'Content-Type: application/json' );
    $op = $_GET['op'] ?? 'stats';
    if ( $op === 'invalidate' ) {
        (new ReflectionMethod( 'WUTM_Page_Cache', 'invalidate_matching' ))->invoke( null, 42, array( 'page' ), array( 7 ), 'test' );
    } elseif ( $op === 'clear' ) {
        (new ReflectionMethod( 'WUTM_Page_Cache', 'clear_cache' ))->invoke( null );
    }
    echo json_encode( (new ReflectionMethod( 'WUTM_Page_Cache', 'stats' ))->invoke( null ) ); return;
}
if ( $mode === 'not-found' ) http_response_code( 404 );
if ( $mode === 'server-error' ) http_response_code( 500 );
header( 'Content-Type: text/html; charset=UTF-8' );
if ( $mode === 'outer-gzip' ) ob_start( 'ob_gzhandler' );
if ( $mode === 'non-html' ) header( 'Content-Type: application/json' );
if ( $mode === 'vary-cookie' ) header( 'Vary: Cookie' );
if ( $mode === 'vary-language' ) header( 'Vary: Accept-Language' );
$TRP_LANGUAGE = str_starts_with( $mode, 'en/' ) || ( $_COOKIE['trp_language'] ?? '' ) === 'en_US' ? 'en_US' : 'zh_TW';
add_action( 'init', function () {
    if ( is_admin() || is_user_logged_in() || ! empty( $_GET ) || 'GET' !== $_SERVER['REQUEST_METHOD'] ) return;
    ob_start( function ( $html ) {
        if ( apply_filters( 'trp_stop_translating_page', false ) ) return $html;
        if ( str_contains( $html, 'ENGLISH-TRANSLATED' ) ) return str_replace( 'ENGLISH-TRANSLATED', 'WRONG-DOUBLE-TRANSLATION', $html );
        return str_replace( 'before-transform', 'en_US' === $GLOBALS['TRP_LANGUAGE'] ? 'ENGLISH-TRANSLATED' : 'CHINESE-DEFAULT', $html );
    } );
}, 0 );
do_action( 'init' );
if ( $mode === 'translation-buffer-flushed' ) { while ( ob_get_level() ) ob_end_flush(); }
do_action( 'send_headers' ); do_action( 'template_redirect' );
if ( $mode === 'late-cookie' ) setcookie( 'private_session', 'late' );
if ( $mode === 'late-flag' ) define( 'DONOTCACHEPAGE', true );
if ( $mode === 'late-no-cache' ) nocache_headers();
if ( $mode === 'transformed' ) ob_start( fn( $html ) => str_replace( 'before-transform', 'AFTER-TRANSFORM', $html ) );
if ( $mode === 'invalidated-during-render' ) WUTM_Page_Cache::clear_on_global_change();
echo '<!doctype html><html><head><title>Fixture</title></head><body><main>before-transform ' . esc_html( $mode ) . ' ' . microtime( true ) . '</main>';
if ( $mode === 'large' ) echo str_repeat( 'x', 4194400 );
if ( in_array( $mode, array( 'chunked', 'cleaned' ), true ) && ob_get_level() > 1 ) ob_end_flush();
if ( $mode === 'chunked' ) ob_flush();
if ( $mode === 'cleaned' ) ob_clean();
echo '</body></html>';
if ( $mode === 'nested-empty' ) ob_start();
if ( $mode === 'early-flush' ) { while ( ob_get_level() ) ob_end_flush(); }
