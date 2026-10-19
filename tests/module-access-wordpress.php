<?php
/** Run only with WP-CLI against the disposable CI WordPress database. */
if (!defined('WP_CLI') || !WP_CLI || getenv('WUTM_MEDIA_INTEGRATION') !== '1') exit;
$root = dirname(__DIR__) . '/';
if (!defined('WUTM_PATH')) define('WUTM_PATH', $root);
if (!defined('WUTM_URL')) define('WUTM_URL', 'https://fixture.test/plugin/');
if (!defined('WUTM_VERSION')) define('WUTM_VERSION', '3.7.1');
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once $root . 'core/module-registry.php';
require_once $root . 'core/module-access.php';
require_once $root . 'modules/custom-cursor/module.php';
require_once $root . 'modules/admin-menu-editor/module.php';
$owner = get_current_user_id();
$other = wp_insert_user(['user_login' => 'sharing-fixture', 'user_pass' => wp_generate_password(), 'user_email' => 'sharing-fixture@example.test', 'role' => 'administrator']);
if (is_wp_error($other)) throw new RuntimeException($other->get_error_message());
$saved = [];
foreach (['wutm_admin_menu_editor_owner_ids','wu_hide_wumetax_toolkit_admin_id','wutm_hide_toolbox_other_admins','wutm_shared_module_menus','wutm_admin_menu_default',wutm_module_option('custom-cursor')] as $key) $saved[$key] = get_option($key, null);
try {
    update_option('wutm_admin_menu_editor_owner_ids', [$owner]);
    delete_option('wu_hide_wumetax_toolkit_admin_id');
    update_option('wutm_hide_toolbox_other_admins', 1);
    update_option('wutm_shared_module_menus', ['custom-cursor' => 1]);
    update_option('wutm_admin_menu_default', ['items' => ['s:' . sha1('wu-toolbox-modular' . "\0" . 'wu-custom-cursor') => ['hidden' => true]]]);
    update_option(wutm_module_option('custom-cursor'), 1);
    wp_set_current_user($other);
    global $menu, $submenu, $admin_page_hooks, $_registered_pages, $_wp_real_parent_file, $_wp_menu_nopriv, $_wp_submenu_nopriv, $pagenow, $plugin_page, $parent_file, $typenow;
    $menu = $submenu = $admin_page_hooks = $_registered_pages = $_wp_real_parent_file = $_wp_menu_nopriv = $_wp_submenu_nopriv = [];
    $pagenow = 'admin.php'; $plugin_page = 'wu-custom-cursor'; $parent_file = ''; $typenow = '';
    add_menu_page('Toolbox', 'Toolbox', 'manage_options', 'wu-toolbox-modular', '__return_null');
    do_action('admin_menu');
    $hook = get_plugin_page_hook('wu-custom-cursor', 'admin.php');
    if (!$hook || !has_action($hook, 'wutm_custom_cursor_settings_page') || !user_can_access_admin_page()) throw new RuntimeException('Original WordPress page hook/capability routing failed after hiding parent');
    if (!array_filter($menu, static fn($entry) => $entry[2] === 'admin.php?page=wu-custom-cursor')) throw new RuntimeException('Shared link missing');
    if (array_filter($menu, static fn($entry) => $entry[2] === 'wu-toolbox-modular')) throw new RuntimeException('Toolbox should be hidden');
    ob_start(); do_action($hook); $html = ob_get_clean();
    if (strpos($html, 'wutm_custom_cursor_options[ring_color]') === false || strpos($html, 'options.php') === false) throw new RuntimeException('Original settings callback/form did not render');
    echo "Real WordPress shared-menu routing passed: hidden parent, original registered hook, capability check and settings form.\n";
} finally {
    wp_set_current_user($owner);
    wp_delete_user($other);
    foreach ($saved as $key => $value) { if ($value === null) delete_option($key); else update_option($key, $value); }
}
