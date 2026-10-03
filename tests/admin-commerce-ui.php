<?php
/** Regression checks for product UI data preservation and opt-in admin assets. */
$wutm_sp_preview = true;
require __DIR__ . '/shipping-progress.php';
class WC_Shipping_Method {}
function wp_enqueue_media() {}
function wp_editor($value, $id, $args) { echo '<textarea id="' . esc_attr($id) . '" name="' . esc_attr($args['textarea_name']) . '" style="width:100%;min-height:120px">' . esc_html($value) . '</textarea>'; }
function wp_get_attachment_image($id, ...$args) { return '<img alt="規格示意圖" width="300" height="150" src="data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22300%22 height=%22150%22%3E%3Crect width=%22300%22 height=%22150%22 fill=%22%23e8f1f8%22/%3E%3C/svg%3E">'; }
function wp_is_post_autosave($id) { return false; }
function wp_is_post_revision($id) { return false; }
function wp_attachment_is_image($id) { return $id === 18; }
function update_post_meta($id, $key, $value) { $GLOBALS['product_meta'][$id][$key] = $value; }
function delete_post_meta($id, $key) { unset($GLOBALS['product_meta'][$id][$key]); }
function esc_html__($value, $domain) { return esc_html($value); }
require dirname(__DIR__) . '/modules/product-size-chart/module.php';
require dirname(__DIR__) . '/core/woocommerce-tools.php';
require dirname(__DIR__) . '/core/module-registry.php';
$screen = array('id' => 'dashboard'); $styles = array(); $scripts = array();
wutm_product_size_chart_admin_assets();
check(!$styles && !$scripts, 'Size chart adds no assets to unrelated screens');
$screen = array('id' => 'product', 'post_type' => 'product', 'base' => 'post');
wutm_product_size_chart_admin_assets();
check(in_array('wutm-size-chart-admin', $scripts, true), 'Size chart assets scoped to product editor');
$caps = array('edit_post');
$product_meta[5]['_custom_size_chart'] = array(array('size' => 'S', 'chest' => '50'));
$_POST = array('wutm_product_size_chart_nonce' => 'wrong', 'size_chart_columns' => array('size' => array('label' => '尺寸')));
wutm_product_size_chart_save_data(5);
check($product_meta[5]['_custom_size_chart'][0]['chest'] === '50', 'Invalid nonce preserves chart');
$_POST['wutm_product_size_chart_nonce'] = array('malformed'); wutm_product_size_chart_save_data(5);
check($product_meta[5]['_custom_size_chart'][0]['chest'] === '50', 'Malformed nonce rejected safely');
$_POST = array('wutm_product_size_chart_nonce' => 'valid', 'size_chart_columns' => array('size' => array('label' => '尺寸'), 'chest' => array('label' => '胸圍')), 'size_chart_rows' => array(3 => array('size' => 'S', 'chest' => '50'), 8 => array('size' => 'M', 'chest' => '60'), 9 => array('size' => '', 'chest' => '')), 'wutm_size_chart_note' => '<p>平量 ±2cm</p>', 'wutm_size_chart_image_id' => 18);
wutm_product_size_chart_save_data(5);
check(count($product_meta[5]['_custom_size_chart']) === 2 && $product_meta[5]['_custom_size_chart'][1] === array('size' => 'M', 'chest' => '60'), 'Existing chart keys and values preserved; empty rows omitted');
check($product_meta[5]['_custom_size_chart_image_id'] === 18 && strpos($product_meta[5]['_custom_size_chart_note'], '±2cm') !== false, 'Notes and media preserved');
$caps = array(); $_POST['size_chart_rows'] = array(); wutm_product_size_chart_save_data(5);
check(count($product_meta[5]['_custom_size_chart']) === 2, 'Chart permission check preserves data');
ob_start(); wutm_product_size_chart_render_meta_box(new WP_Post(5)); $chart_html = ob_get_clean();
check(strpos($chart_html, 'wutm-sc-row') !== false && strpos($chart_html, 'size_chart_rows[1][chest]') !== false && strpos($chart_html, '50') !== false, 'Card editor renders saved inputs with compatible field names');
$optimizer = new WU_WooCommerce_Optimizer('visibility');
$styles = array(); $scripts = array(); $optimizer->product_visibility_assets();
check(!$scripts && strpos($optimizer->visibility_css(), 'advanced_product_data') === false, 'New hiding options default off');
foreach (array('advanced', 'grouped', 'shipping', 'linked') as $key) $options['wu_woo_hide_product_' . $key] = 1;
$css = $optimizer->visibility_css(); $optimizer->product_visibility_assets();
foreach (array('advanced_product_data', 'shipping_product_data', 'linked_product_data') as $panel) check(strpos($css, $panel) !== false, 'Configured product panel hidden: ' . $panel);
check(strpos($css, 'option[value="grouped"]:not(:checked)') !== false, 'Selected grouped product type exempt from hiding');
check(in_array('wutm-wc-product-visibility', $scripts, true), 'Lightweight fallback tab script scoped to editor');
$screen['base'] = 'edit'; $scripts = array(); $optimizer->product_visibility_assets();
check(!$scripts && strpos($optimizer->visibility_css(), 'advanced_product_data') === false, 'No product hiding assets or selectors on product list');
check(wutm_modules()['woocommerce-optimizer']['group'] === '電商優化工具', 'Visibility module belongs to commerce optimization category');
$_POST = array();
if (empty($wutm_admin_preview)) echo "Admin commerce UI checks passed.\n";
