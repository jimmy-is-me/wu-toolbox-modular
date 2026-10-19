<?php
/** CLI-only ownership and rendering fixture for the shared design adapter. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ABSPATH', __DIR__ . '/');
define('WUTM_PATH', dirname(__DIR__) . '/');
define('WUTM_URL', '/');
define('WUTM_VERSION', '3.7.2');
$hooks = $wp_filter = $styles = $scripts = [];
$screen = (object) ['id' => 'renamed-toolbox_page_wu-custom-cursor'];
$admin = true;
function add_action($name, $fn, ...$args) { $GLOBALS['hooks'][$name][] = $fn; }
function add_filter($name, $fn, ...$args) { add_action($name, $fn); }
function is_admin() { return $GLOBALS['admin']; }
function get_current_screen() { return $GLOBALS['screen']; }
function wp_enqueue_style(...$args) { $GLOBALS['styles'][] = $args; }
function wp_enqueue_script(...$args) { $GLOBALS['scripts'][] = $args; }
function current_user_can(...$args) { return true; }
function get_option($key, $default = false) { if (strpos($key, 'wutm_module_') === 0) return strpos($key, 'cursor') !== false || crc32($key) % 3 !== 0; return $default; }
function wp_parse_args($a, $b) { return array_merge($b, $a); }
function esc_html($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
function esc_attr($v) { return esc_html($v); }
function esc_url($v) { return esc_html($v); }
function esc_js($v) { return addslashes($v); }
function wp_create_nonce($v) { return 'fixture'; }
function checked($v) { if ($v) echo 'checked'; }
function disabled($v) { if ($v) echo 'disabled'; }
function admin_url($v) { return '/wp-admin/' . $v; }
function settings_fields($v) { echo '<input type="hidden" name="option_page" value="' . esc_attr($v) . '"><input type="hidden" name="_wpnonce" value="fixture">'; }
function submit_button($v) { echo '<p class="submit"><input type="submit" class="button button-primary" value="' . esc_attr($v) . '"></p>'; }
function get_current_user_id() { return 1; }
function get_users($args) { return [(object) ['ID' => 1]]; }
function user_can(...$args) { return true; }
function is_plugin_active($p) { return false; }
function is_multisite() { return false; }
function wutm_license_is_valid() { return true; }
function check($ok, $message) { if (!$ok) throw new RuntimeException($message); }
class WUTM_License_Manager {
    static function instance() { return new self; }
    function render_panel() { echo '<section class="wutm-license-panel"><h2>授權與更新</h2><p>授權狀態與更新資訊</p></section>'; }
}
require WUTM_PATH . 'core/module-registry.php';
require WUTM_PATH . 'core/module-access.php';
require WUTM_PATH . 'core/admin-page.php';
require WUTM_PATH . 'modules/custom-cursor/module.php';
require WUTM_PATH . 'core/admin-design.php';
function foreign_page() {}
function attach_page($id, $callback) { $GLOBALS['wp_filter'][$id] = (object) ['callbacks' => [10 => [['function' => $callback]]]]; }
attach_page($screen->id, 'wutm_custom_cursor_settings_page');
if (($argv[1] ?? 'policy') === 'policy') {
    check(wutm_admin_design_screen(), 'Own callback works under renamed parent menu');
    foreach ($hooks['admin_enqueue_scripts'] as $fn) $fn();
    check(count($styles) === 2 && count($scripts) === 1, 'Shared base/component styles and one script on an owned page');
    $styles = $scripts = [];
    foreach (['dashboard','product','woocommerce_page_wc-settings','foreign_page_wu-other','toplevel_page_site-ai-foreign'] as $id) {
        $screen->id = $id; $_GET['page'] = 'wu-looks-owned'; attach_page($id, 'foreign_page');
        foreach ($hooks['admin_enqueue_scripts'] as $fn) $fn();
        check(!wutm_admin_design_screen() && !$styles && !$scripts, 'No styling on foreign/native callback: ' . $id);
    }
    $screen->id = 'renamed-toolbox_page_wu-custom-cursor'; $admin = false;
    check(!wutm_admin_design_screen(), 'Frontend never owns an admin design screen'); $admin = true;
    ob_start(); wutm_custom_cursor_settings_page(); $html = ob_get_clean();
    foreach (['ring_color','dot_color','hover_color'] as $key) check(strpos($html, 'name="wutm_custom_cursor_options[' . $key . ']"') !== false, 'Original posted field: ' . $key);
    check(strpos($html, 'action="options.php"') !== false && strpos($html, 'wutm_custom_cursor_group') !== false, 'Original Settings API boundary');
    check(count(wutm_modules()) > 100, 'Actual registry contains all modules');
    echo "Admin design policy passed: callback ownership, renamed menus, foreign/native exclusion, frontend isolation and original form fields.\n";
    exit;
}
echo '<!doctype html><html lang="zh-Hant"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{margin:0;background:#f0f0f1;font:14px system-ui}#wpadminbar{position:fixed;top:0;left:0;right:0;height:32px;background:#1d2327;color:#fff;z-index:20;padding:0 14px;box-sizing:border-box}#adminmenu{position:fixed;top:32px;left:0;bottom:0;width:160px;background:#1d2327;color:#fff;padding-top:20px}#adminmenu p{padding:10px}#wpbody-content{margin:32px 0 0 180px}.notice{padding:10px;background:white;border-left:4px solid orange}.screen-reader-text{position:absolute;width:1px;height:1px;overflow:hidden;clip-path:inset(50%)}.form-table th{text-align:left}button,input{font:inherit}.button{cursor:pointer}.nav-tab{padding:8px}a{color:#2271b1}@media(max-width:782px){#adminmenu{display:none}#wpbody-content{margin-left:10px}#wpadminbar{height:46px}#wpbody-content{margin-top:46px}}</style><link rel="stylesheet" href="/assets/css/admin.css"><body class="wutm-admin-screen"><div id="wpadminbar">WordPress　 /　WUMETAX</div><aside id="adminmenu"><p>控制台</p><p>文章</p><p>媒體</p><p>頁面</p><p>WooCommerce</p><p>WU Toolbox</p></aside><main id="wpbody-content">';
if ($argv[1] === 'dashboard') wutm_render_admin_page(); else wutm_custom_cursor_settings_page();
echo '</main><link rel="stylesheet" href="/assets/css/admin-components.css"><script>var ajaxurl="/wp-admin/admin-ajax.php";</script><script src="/assets/js/admin-design.js"></script></body></html>';
