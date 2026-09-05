<?php
define('ABSPATH', __DIR__);
class WooCommerce {}
class WC_Shipping_Method {}
$options = array();
$screen = (object) array('id'=>'woocommerce_page_wc-settings', 'post_type'=>'');
function get_option($key, $default = false) { global $options; return $options[$key] ?? $default; }
function get_current_screen() { global $screen; return $screen; }
function add_action(...$args) {}
function add_filter(...$args) {}
function remove_submenu_page(...$args) { throw new RuntimeException('Must not remove page/menu registration'); }
require dirname(__DIR__) . '/modules/woocommerce-optimizer/module.php';
function verify($condition, $message) { if (!$condition) throw new RuntimeException($message); }
$optimizer = new WU_WooCommerce_Optimizer();
verify($optimizer->visibility_css() === '', 'Hide options default off');
verify($optimizer->footer_text('Original') === 'Woocommerce X Wumetax', 'Footer default on');
$tabs = array('general'=>'General','integration'=>'Integration','point-of-sale'=>'POS','advanced'=>'Advanced','checkout'=>'Payments');
foreach (array('pos'=>'point-of-sale','advanced'=>'advanced','integration'=>'integration') as $key=>$tab) {
    $options = array('wu_woo_hide_'.$key=>1);
    $expected = $tabs; unset($expected[$tab]);
    verify($optimizer->hide_settings_tabs($tabs) === $expected, 'Independent tab '.$key);
}
foreach (array('home','extensions','status','reports','marketing','payments_menu','embedded_primary','more_payments','official_payments') as $key) {
    $options = array('wu_woo_hide_'.$key=>1);
    verify(strpos($optimizer->visibility_css(), 'display:none!important') !== false, 'CSS option '.$key);
}
$options = array('wu_woo_custom_footer'=>0);
verify($optimizer->footer_text('Original') === 'Original', 'Footer opt out');
$options = array('wu_woo_hide_embedded_primary'=>1,'wu_woo_hide_more_payments'=>1,'wu_woo_hide_official_payments'=>1);
$screen = (object) array('id'=>'dashboard','post_type'=>'');
verify($optimizer->visibility_css() === '', 'Do not hide non-WooCommerce content');
verify($optimizer->footer_text('Original') === 'Original', 'Do not alter other footers');
$options = array();
verify($optimizer->hide_settings_tabs($tabs) === $tabs, 'Restore tabs');
$source = file_get_contents(dirname(__DIR__).'/modules/woocommerce-optimizer/module.php');
verify(strpos($source, 'remove_submenu_page(') === false, 'Keep WordPress page access registrations intact');
echo "WooCommerce visibility regression tests passed.\n";
