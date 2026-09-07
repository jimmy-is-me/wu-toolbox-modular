<?php
/**
 * CMS Tree Page View bundled as an on-demand WU module.
 */
defined('ABSPATH') || exit;

if (!defined('CMS_TPV_VERSION')) {
    $wutm_tree_page_view_file = __DIR__ . '/vendor/cms-tree-page-view/index.php';
    if (is_readable($wutm_tree_page_view_file)) {
        require_once $wutm_tree_page_view_file;
        // Embedded modules do not receive the source plugin's activation hook.
        // Repair v1.8.6 installations where the version exists but options do not.
        add_action('init', static function (): void {
            if (get_option('cms_tpv_options', null) === null) {
                update_option('cms_tpv_options', ['dashboard' => ['page'], 'menu' => ['page']]);
            }
            if (!get_option('wutm_tree_page_view_initialized', false)) {
                \CMS_Tree_Page_View\Admin\Capabilities::setup();
                update_option('wutm_tree_page_view_initialized', 1, false);
            }
        }, 2);

        // Register the settings screen from the WU wrapper itself.  Relying on
        // the bundled plugin to add this submenu can leave admin.php without a
        // registered page when WordPress resolves modular plugins in a
        // different hook order, which results in the generic permission error.
        add_action('admin_menu', static function (): void {
            add_submenu_page(
                'wu-toolbox-modular',
                '樹狀頁面視圖',
                '樹狀頁面視圖',
                'manage_options',
                'wu-tree-page-view',
                static function (): void {
                    if (!current_user_can('manage_options')) {
                        wp_die(esc_html__('很抱歉，目前的登入身分沒有存取這個頁面的權限。'));
                    }
                    \CMS_Tree_Page_View\Settings\Options::render_settings_page();
                }
            );
        }, 999);
    }
    unset($wutm_tree_page_view_file);
}
