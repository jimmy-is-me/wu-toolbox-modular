<?php
/** CLI regression fixture: no WordPress/site/database writes. */
if (PHP_SAPI !== 'cli') exit;
define('ABSPATH', __DIR__ . '/');
$options = []; $hooks = []; $current = 1; $allowed = true; $menu = []; $removed = [];
$users = [1 => (object) ['ID' => 1], 2 => (object) ['ID' => 2]];
function add_action($name, $callback, ...$args) { $GLOBALS['hooks'][$name][] = $callback; }
function add_filter($name, $callback, ...$args) { add_action($name, $callback, ...$args); }
function get_option($name, $default = false) { return $GLOBALS['options'][$name] ?? $default; }
function update_option($name, $value, ...$args) { $GLOBALS['options'][$name] = $value; }
function add_option($name,$value,...$args) {if(array_key_exists($name,$GLOBALS['options']))return false;$GLOBALS['options'][$name]=$value;return true;}
function delete_option($name) {unset($GLOBALS['options'][$name]);}
class WP_Error {function __construct(public $code, public $message){} function get_error_message(){return $this->message;}}
function is_wp_error($value){return $value instanceof WP_Error;}
function current_user_can($cap) { return $GLOBALS['allowed']; }
function user_can($user, $cap) { return isset($GLOBALS['users'][$user->ID]); }
function get_current_user_id() { return $GLOBALS['current']; }
function get_userdata($id) { return $GLOBALS['users'][$id] ?? false; }
function get_user_by($by, $value) { return $GLOBALS['users'][1]; }
function get_users($args) { return array_values($GLOBALS['users']); }
function absint($value) { return abs((int) $value); }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_-]/', '', $value); }
function wp_unslash($v) { return $v; }
function esc_attr($v) { return htmlspecialchars($v, ENT_QUOTES); }
function remove_menu_page($slug) { $GLOBALS['removed'][] = $slug; }
function check_ajax_referer(...$args) { if (!empty($GLOBALS['bad_nonce'])) throw new RuntimeException('nonce'); }
function check_admin_referer(...$args) {check_ajax_referer(...$args);}
function wp_die(...$args) {throw new RuntimeException('denied');}
function wp_send_json_error($data, $status = 400) { throw new RuntimeException('error:' . $status); }
function wp_send_json_success($data) { throw new RuntimeException('success:' . (int) $data['enabled']); }
function check($ok, $message) { if (!$ok) throw new RuntimeException($message); }
require dirname(__DIR__) . '/core/module-registry.php';
require dirname(__DIR__) . '/core/module-access.php';
check(!wutm_toolbox_hidden() && !wutm_toolbox_owner_ids(), 'Default open without implicit owner');
$before=$options;$bad_nonce=true;
try {wutm_toolbox_visibility_request();throw new LogicException('Nonce accepted');}catch(RuntimeException $error){check($error->getMessage()==='nonce','Visibility nonce enforced');}
check($options===$before,'Invalid nonce makes no writes');$bad_nonce=false;
$allowed=false;check(is_wp_error(wutm_toolbox_save_visibility(true))&&$options===$before,'Nonadmin cannot elect owner');$allowed=true;
$options['wu_hide_wumetax_toolkit_admin_id']=1;$options['wutm_admin_menu_editor_owner_ids']=[1];$options['wutm_hide_toolbox_other_admins']=1;
$current=2;check(wutm_toolbox_can_manage()&&!wutm_toolbox_is_owner(),'Old flags and menu editor cannot elect ID 1');
check(wutm_toolbox_save_visibility(true)===true&&wutm_toolbox_owner_ids()===[2],'Manual enabling administrator becomes sole owner');
$current=1;check(!wutm_toolbox_can_manage()&&is_wp_error(wutm_toolbox_save_visibility(true)),'ID 1 cannot steal active ownership');
$current=2;check(wutm_toolbox_save_visibility(false)===true&&!wutm_toolbox_owner_ids(),'Uncheck and save revokes explicit owner');
$current=1;wutm_toolbox_save_visibility(true);
check(wutm_toolbox_is_owner() && wutm_toolbox_can_manage(), 'Explicit owner retains access');
$current = 2;
check(!wutm_toolbox_is_owner() && !wutm_toolbox_can_manage(), 'Other administrator cannot open hidden dashboard');
$options['wutm_hide_toolbox_other_admins'] = 0;
check(wutm_toolbox_can_manage(), 'Explicitly disabling hide restores dashboard');
$options['wutm_hide_toolbox_other_admins'] = 1;
function share($key, $enabled, $expected) {
    $_POST = ['module' => $key, 'enabled' => $enabled];
    try { $GLOBALS['hooks']['wp_ajax_wutm_share_module'][0](); throw new RuntimeException('No response'); }
    catch (RuntimeException $e) { check($e->getMessage() === $expected, 'Share boundary: ' . $e->getMessage()); }
}
share('custom-cursor', 1, 'error:403');
$current = 1;
share('missing-key', 1, 'error:400');
share('admin-menu-editor', 1, 'error:400');
share('wordfence', 1, 'error:400');
share('ai-content-tools', 1, 'error:400');
share('custom-cursor', 1, 'error:400');
$options[wutm_module_option('wc-confetti')] = 1;
share('wc-confetti', 1, 'error:400');
$options[wutm_module_option('knowledge-base-ai')] = 1;
share('knowledge-base-ai', 1, 'error:400');
$options[wutm_module_option('custom-cursor')] = 1;
$bad_nonce = true;
share('custom-cursor', 1, 'nonce');
$bad_nonce = false;
share('custom-cursor', 1, 'success:1');
check(wutm_module_is_shared('custom-cursor'), 'Preference persisted');
check(wutm_is_shared_menu_target('wu-custom-cursor') && !wutm_is_shared_menu_target('unrelated'), 'Only explicitly shared route survives old menu-editor hiding');
ob_start(); wutm_render_module_share('custom-cursor', wutm_get_module('custom-cursor'), true); $html = ob_get_clean();
check(strpos($html, '開放給其他管理員使用-出現在主選單') !== false && strpos($html, 'checked') !== false, 'Card control matches saved state');
$options[wutm_module_option('admin-bar-cleaner')] = 1;
$menu = [['Existing', 'manage_options', 'existing.php'], ['Toolbox', 'manage_options', 'wu-toolbox-modular']];
$submenu['existing.php'] = [['Cleaner', 'manage_options', 'admin.php?page=wu-admin-bar-cleaner'], ['Native', 'read', 'native.php']];
function wutm_menu_slug_matches($entry, $needle) { return $entry === $needle || $entry === 'admin.php?page=' . $needle; }
$submenu['wu-toolbox-modular'] = [['Toolbox','manage_options','wu-toolbox-modular'],['Cursor','manage_options','wu-custom-cursor'],['Other','manage_options','wu-media-encoder']];
$current = 2;
check(wutm_toolbox_can_view() && !wutm_toolbox_can_manage(), 'Shared administrator can view, not manage');
$hooks['admin_menu'][0]();
check(count($menu) === 2 && !$removed, 'One existing Toolbox menu retained; no individual module menu added');
check(count($submenu['wu-toolbox-modular']) === 2, 'Only dashboard and shared submenu retained');
check(array_column($submenu['existing.php'], 2) === ['native.php'], 'A relocated unshared module is hidden and native entries remain');
$submenu['wu-toolbox-modular'][] = ['Restored unshared', 'manage_options', 'wu-admin-bar-cleaner'];
$submenu['wu-toolbox-modular'][] = ['Restored heading', 'read', 'wutm-group-admin'];
check($hooks['submenu_file'][0]('wu-custom-cursor') === 'wu-custom-cursor', 'Late check preserves current page selection');
check(array_column($submenu['wu-toolbox-modular'], 2) === ['wu-toolbox-modular', 'wu-custom-cursor'], 'Render-time filtering removes reinserted links and empty headings');
check(array_keys(wutm_toolbox_shared_modules()) === ['custom-cursor'], 'Shared dashboard only contains explicitly shared enabled modules');
$options[wutm_module_option('custom-cursor')] = 0; $hooks['admin_menu'][0]();
check(!wutm_toolbox_can_view() && $removed === ['wu-toolbox-modular'], 'No enabled shares hides Toolbox');
$allowed = false; $removed = []; $hooks['admin_menu'][0]();
check(!$removed && !wutm_toolbox_can_manage(), 'No privileges granted to non-administrators');
$allowed = true; $current = 1;
share('custom-cursor', 0, 'success:0');
check(!wutm_module_is_shared('custom-cursor'), 'Revocation persisted');
$options['wu_hide_wumetax_toolkit_admin_id'] = 2;
$current = 2; check(!wutm_toolbox_is_owner(), 'Legacy visibility owner is not automatically authorized');
$options['wutm_toolbox_primary_admin_id']=2;
check(wutm_toolbox_is_owner(), 'Explicit manual owner recognized');
unset($users[2]); check(!wutm_toolbox_is_owner(), 'Deleted/demoted owner not authorized');
$options['wutm_admin_menu_editor_owner_ids'] = [999]; $current = 1;
check(!wutm_toolbox_is_owner()&&!wutm_toolbox_hidden()&&wutm_toolbox_can_manage(), 'Missing owner reopens access without electing oldest administrator');
$cleaner = file_get_contents(dirname(__DIR__) . '/modules/admin-bar-cleaner/module.php');
check(strpos($cleaner, "'wu_hide_wumetax_toolkit',") === false && strpos($cleaner, "delete_option( 'wu_hide_wumetax_toolkit_admin_id'") === false, 'Cleaner saves cannot clear independent visibility or legacy owner');
require dirname(__DIR__) . '/core/wp-booking-installer.php';
$groups = array_keys(wutm_grouped_modules());
check(array_search('獨立外掛', $groups, true) === array_search('網頁工具', $groups, true) + 1, 'Standalone plugin group follows web tools');
$booking_asset = ['name' => 'wp-booking.zip', 'browser_download_url' => 'https://github.com/jimmy-is-me/WP-Booking/releases/download/v1.5.1/wp-booking.zip', 'digest' => 'sha256:' . str_repeat('a', 64)];
$booking_release = ['tag_name' => 'v1.5.1', 'assets' => [$booking_asset]];
check(wutm_wp_booking_release_asset($booking_release)['sha256'] === str_repeat('a', 64), 'Booking accepts the exact release asset and digest');
check(!wutm_wp_booking_release_asset($booking_release + ['prerelease' => true]), 'Booking skips prereleases');
$booking_release['assets'][0]['browser_download_url'] = 'https://example.test/wp-booking.zip';
check(!wutm_wp_booking_release_asset($booking_release), 'Booking rejects unexpected download sources');
echo "Module access passed: default visibility, owners, recovery, nonce/capability, sharing/revocation, disabled modules, direct links and native menu preservation.\n";
