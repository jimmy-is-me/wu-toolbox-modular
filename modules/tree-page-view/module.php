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
    }
    unset($wutm_tree_page_view_file);
}
