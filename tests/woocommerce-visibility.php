<?php
define('ABSPATH', __DIR__);
class WooCommerce {}
class WC_Shipping_Method {}
$options = array();
function get_option($key, $default = false) { global $options; return $options[$key] ?? $default; }
function add_action(...$args) {}
function add_filter(...$args) {}
function remove_submenu_page($parent, $slug) {
    global $submenu;
    foreach ($submenu[$parent] as $key => $item) {
        if ($item[2] === $slug) unset($submenu[$parent][$key]);
    }
}
require dirname(__DIR__) . '/modules/woocommerce-optimizer/module.php';
function verify($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}
$optimizer = new WU_WooCommerce_Optimizer();
$original = array(
    array('Home', 'manage_woocommerce', 'wc-admin'),
    array('Home route', 'manage_woocommerce', 'admin.php?page=wc-admin&path=%2F'),
    array('Extensions', 'manage_woocommerce', 'wc-admin&path=%2Fextensions'),
    array('Legacy extensions', 'manage_woocommerce', 'wc-addons'),
    array('Status', 'manage_woocommerce', 'wc-status'),
    array('Orders', 'manage_woocommerce', 'wc-orders'),
    array('Analytics', 'manage_woocommerce', 'wc-admin&path=%2Fanalytics%2Foverview'),
    array('Settings', 'manage_woocommerce', 'wc-settings')
);
$tabs = array('general'=>'General', 'point-of-sale'=>'POS', 'advanced'=>'Advanced', 'payments'=>'Payments');
$submenu = array('woocommerce'=>$original);
$optimizer->hide_admin_menus();
verify($submenu['woocommerce'] === $original, 'Defaults must preserve menus');
verify($optimizer->hide_settings_tabs($tabs) === $tabs, 'Defaults must preserve tabs');
foreach (array('home'=>2, 'extensions'=>2, 'status'=>1) as $key=>$removed) {
    $options = array('wu_woo_hide_'.$key=>1);
    $submenu = array('woocommerce'=>$original);
    $optimizer->hide_admin_menus();
    verify(count($submenu['woocommerce']) === count($original)-$removed, 'Independent menu: '.$key);
    verify($optimizer->hide_settings_tabs($tabs) === $tabs, 'Menu option must not affect tabs');
}
foreach (array('pos'=>'point-of-sale', 'advanced'=>'advanced') as $key=>$tab) {
    $options = array('wu_woo_hide_'.$key=>1);
    $expected = $tabs;
    unset($expected[$tab]);
    verify($optimizer->hide_settings_tabs($tabs) === $expected, 'Independent tab: '.$key);
}
$options = array();
verify($optimizer->hide_settings_tabs($tabs) === $tabs, 'Disable must restore tabs');
$submenu = array();
$optimizer->hide_admin_menus();
echo "WooCommerce visibility regression tests passed.\n";
