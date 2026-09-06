<?php
/**
 * CMS Tree Page View bundled as an on-demand WU module.
 */
defined('ABSPATH') || exit;

if (!defined('CMS_TPV_VERSION')) {
    $wutm_tree_page_view_file = __DIR__ . '/vendor/cms-tree-page-view/index.php';
    if (is_readable($wutm_tree_page_view_file)) {
        require_once $wutm_tree_page_view_file;
    }
    unset($wutm_tree_page_view_file);
}
