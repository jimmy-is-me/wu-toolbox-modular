<?php
defined('ABSPATH') || exit;
$wutm_badges_legacy = class_exists('WCRM_Product_Marks', false);
require_once __DIR__ . '/product-badges.php';
// Native taxonomy management remains under Products; Toolbox gets an ordered link.
add_action('admin_menu', static function () {
    if (!taxonomy_exists(WCRM_Product_Marks::TAX)) return;
    add_submenu_page('wu-toolbox-modular', '商品徽章', '商品徽章', 'manage_woocommerce',
        'edit-tags.php?taxonomy=wcrm_product_mark&post_type=product');
}, 30);
if ($wutm_badges_legacy) {
    add_action('admin_notices', static function () {
        if (current_user_can('manage_woocommerce')) echo '<div class="notice notice-warning"><p>請停用原本的 WC 商品徽章外掛／程式碼片段，再使用 WU Toolbox 商品徽章，避免沿用舊版程式。</p></div>';
    });
}
unset($wutm_badges_legacy);
