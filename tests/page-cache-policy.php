<?php
/** Policy, permissions, hook timing and scoped admin assets. No WordPress needed. */
define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/unused-wutm-cache-policy' );
define( 'WUTM_URL', '/plugin/' );
define( 'WUTM_VERSION', 'test' );
$admin = false; $capable = false; $nonce_ok = false; $styles = array(); $actions = array();
$options = array( 'wutm_page_cache_settings' => array( 'auto_invalidate' => 0 ) );
function get_option( $key, $default = false ) { return $GLOBALS['options'][$key] ?? $default; }
function wp_parse_args( $value, $defaults ) { return array_merge( $defaults, $value ); }
function add_action( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS['actions'][$hook][] = array( $callback, $priority ); }
function is_admin() { return $GLOBALS['admin']; }
function current_user_can( $cap ) { return $GLOBALS['capable']; }
function wp_die( $message ) { throw new RuntimeException( 'denied' ); }
function esc_html__( $message, $domain ) { return $message; }
function check_admin_referer( $nonce ) { if ( ! $GLOBALS['nonce_ok'] ) throw new RuntimeException( 'nonce' ); }
function wp_enqueue_style( ...$args ) { $GLOBALS['styles'][] = $args; }
function admin_url( $path ) { return '/wp-admin/' . $path; }
function wp_nonce_url( $url, $nonce ) { return $url . '&_wpnonce=test'; }
class WP_Admin_Bar { public array $nodes = array(); public function add_node( $node ) { $this->nodes[] = $node; } }
require dirname( __DIR__ ) . '/modules/page-cache/module.php';
function check( $condition, $message ) { if ( ! $condition ) throw new RuntimeException( $message ); }
check( $actions['template_redirect'][0][1] === PHP_INT_MAX, 'Cache reads must run after privacy/canonical hooks.' );
check( ! isset( $actions['shutdown'] ), 'No snapshot of the top output buffer at shutdown.' );
check( ! isset( $actions['save_post'] ), 'Disabled automatic content invalidation does not register content hooks.' );
foreach ( array( 'upgrader_process_complete', 'activated_plugin', 'deactivated_plugin' ) as $hook ) check( isset( $actions[$hook] ), 'Preserve plugin update safety: ' . $hook );
$bar = new WP_Admin_Bar(); WUTM_Page_Cache::register_admin_bar( $bar );
check( ! $bar->nodes, 'No toolbar management links without permission.' );
WUTM_Page_Cache::admin_assets( 'wu-toolbox_page_wu-page-cache' );
check( ! $styles, 'No CSS without permission.' );
$capable = true;
WUTM_Page_Cache::admin_assets( 'edit.php' ); check( ! $styles, 'No CSS on unrelated admin screens.' );
WUTM_Page_Cache::admin_assets( 'wu-toolbox_page_wu-page-cache' ); check( count( $styles ) === 1, 'One settings-only CSS asset; no frontend script.' );
WUTM_Page_Cache::register_admin_bar( $bar );
check( $bar->nodes[0]['href'] === '/wp-admin/admin.php?page=wu-page-cache', 'Main toolbar item reaches settings.' );
check( count( $bar->nodes ) === 3 && str_contains( $bar->nodes[2]['href'], '_wpnonce=' ), 'Clear action remains nonce protected.' );
$authorize = new ReflectionMethod( 'WUTM_Page_Cache', 'authorize_request' );
$capable = false;
try { $authorize->invoke( null ); throw new RuntimeException( 'Permission unexpectedly allowed.' ); } catch ( RuntimeException $e ) { check( $e->getMessage() === 'denied', 'Unauthorized clear/save denied.' ); }
$capable = true;
try { $authorize->invoke( null ); throw new RuntimeException( 'Nonce unexpectedly allowed.' ); } catch ( RuntimeException $e ) { check( $e->getMessage() === 'nonce', 'CSRF denied.' ); }
$nonce_ok = true; $authorize->invoke( null );
$complete = new ReflectionMethod( 'WUTM_Page_Cache', 'is_complete_html' );
check( $complete->invoke( null, '<html><body>safe</body></html>' ), 'Complete HTML accepted.' );
check( ! $complete->invoke( null, '<html>partial' ), 'Partial HTML refused.' );
check( ! $complete->invoke( null, '<html>' . str_repeat( 'x', 4194304 ) . '</html>' ), 'Oversized HTML refused.' );
// The companion disk cleaner preserves the generation token and shared lock.
require dirname( __DIR__ ) . '/modules/disk-space-manager/module.php';
$owned = sys_get_temp_dir() . '/wutm-cache-cleaner-' . bin2hex( random_bytes( 8 ) );
mkdir( $owned, 0700 );
file_put_contents( $owned . '/.wutm-cache.lock', '' );
file_put_contents( $owned . '/.wutm-generation', 'new-generation' );
file_put_contents( $owned . '/fixture.html.gz', 'fixture' );
$cleaner = (new ReflectionClass( 'DSM_Disk_Space_Manager' ))->newInstanceWithoutConstructor();
(new ReflectionMethod( 'DSM_Disk_Space_Manager', 'delete_dir_contents' ))->invoke( $cleaner, $owned );
check( file_get_contents( $owned . '/.wutm-generation' ) === 'new-generation', 'Disk cleanup preserves the invalidation generation.' );
check( is_file( $owned . '/.wutm-cache.lock' ) && ! is_file( $owned . '/fixture.html.gz' ), 'Disk cleanup keeps its lock and removes cached files.' );
unlink( $owned . '/.wutm-cache.lock' ); unlink( $owned . '/.wutm-generation' ); rmdir( $owned );
echo "Page-cache policy checks passed: hook timing, permissions, CSRF, automatic invalidation, bounded memory and settings-only assets.\n";
