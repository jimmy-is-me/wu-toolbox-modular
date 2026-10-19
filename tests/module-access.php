<?php
/** CLI regression fixture: no WordPress/site/database writes. */
if (PHP_SAPI !== 'cli') exit;
define('ABSPATH', __DIR__ . '/');
$options = []; $hooks = []; $current = 1; $allowed = true; $menu = []; $removed = [];
$users = [1 => (object) ['ID' => 1], 2 => (object) ['ID' => 2]];
function add_action($name, $callback, ...$args) { $GLOBALS['hooks'][$name][] = $callback; }
function get_option($name, $default = false) { return $GLOBALS['options'][$name] ?? $default; }
function update_option($name, $value, ...$args) { $GLOBALS['options'][$name] = $value; }
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
function wp_send_json_error($data, $status = 400) { throw new RuntimeException('error:' . $status); }
function wp_send_json_success($data) { throw new RuntimeException('success:' . (int) $data['enabled']); }
function check($ok, $message) { if (!$ok) throw new RuntimeException($message); }
require dirname(__DIR__) . '/core/module-registry.php';
require dirname(__DIR__) . '/core/module-access.php';
check(wutm_toolbox_hidden(), 'Default on without requiring cleaner module');
check(wutm_toolbox_is_owner() && wutm_toolbox_can_manage(), 'Primary owner retains access');
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
$menu = [['Existing', 'manage_options', 'existing.php']];
$current = 2;
$hooks['admin_menu'][0]();
check(count($menu) === 2 && $menu[1][2] === 'admin.php?page=wu-custom-cursor', 'Menu points to original registered page, preserving load hooks/assets');
check($removed === ['wu-toolbox-modular'], 'Toolbox hidden but shared link remains');
check($menu[0][2] === 'existing.php', 'Unrelated menus preserved');
$menu = [['Cursor', 'manage_options', 'wu-custom-cursor']]; $hooks['admin_menu'][0]();
check(count($menu) === 1, 'Existing top-level module not duplicated');
$menu = []; $options[wutm_module_option('custom-cursor')] = 0; $hooks['admin_menu'][0]();
check(!$menu, 'Disabled shared module not loaded or linked');
$allowed = false; $removed = []; $hooks['admin_menu'][0]();
check(!$menu && !$removed && !wutm_toolbox_can_manage(), 'No privileges granted to non-administrators');
$allowed = true; $current = 1;
share('custom-cursor', 0, 'success:0');
check(!wutm_module_is_shared('custom-cursor'), 'Revocation persisted');
$options['wu_hide_wumetax_toolkit_admin_id'] = 2;
$current = 2; check(wutm_toolbox_is_owner(), 'Existing visibility owner preserved');
unset($users[2]); check(!wutm_toolbox_is_owner(), 'Deleted/demoted owner not authorized');
$options['wutm_admin_menu_editor_owner_ids'] = [999]; $current = 1;
check(wutm_toolbox_is_owner(), 'Oldest administrator recovery prevents lockout');
$cleaner = file_get_contents(dirname(__DIR__) . '/modules/admin-bar-cleaner/module.php');
check(strpos($cleaner, "'wu_hide_wumetax_toolkit',") === false && strpos($cleaner, "delete_option( 'wu_hide_wumetax_toolkit_admin_id'") === false, 'Cleaner saves cannot clear independent visibility or legacy owner');
echo "Module access passed: default visibility, owners, recovery, nonce/capability, sharing/revocation, disabled modules, direct links and native menu preservation.\n";
