<?php
defined('ABSPATH') || exit;

add_action('wp_enqueue_scripts', static function() {
    if (!wutm_wc_custom_is_onepage_checkout_enabled() || !function_exists('is_checkout') || !is_checkout() || is_order_received_page() || is_wc_endpoint_url('order-pay')) return;
    if (function_exists('has_block') && has_block('woocommerce/checkout')) return;
    wp_enqueue_script('wutm-onepage-checkout', WUTM_URL . 'assets/js/onepage-checkout.js', ['jquery','wc-checkout'], WUTM_VERSION, true);
});

/** Validate all changes before mutating the current visitor's cart. */
function wutm_onepage_set_quantities($posted) {
    if (!is_array($posted)) return new WP_Error('quantity', '購物車資料不完整，請重新整理後再試。');
    $changes=[];$totals=WC()->cart->get_cart_item_quantities();
    foreach (WC()->cart->get_cart() as $key=>$item) {
        if (!isset($posted[$key]['qty'])) continue;
        $raw=$posted[$key]['qty'];
        if (!is_scalar($raw) || !preg_match('/^\d+(?:\.\d+)?$/D', (string)$raw) || !is_finite((float)$raw)) return new WP_Error('quantity','請輸入有效的商品數量。');
        $qty=apply_filters('woocommerce_stock_amount_cart_item',wc_stock_amount($raw),$key);
        $product=$item['data'];
        if ($qty<0 || ($product->is_sold_individually() && $qty>1) || ($product->get_max_purchase_quantity()>=0 && $qty>$product->get_max_purchase_quantity()) || ($qty>0 && $qty<$product->get_min_purchase_quantity())) return new WP_Error('quantity','商品數量超過可購買範圍，請調整數量。');
        $stock_id=$product->get_stock_managed_by_id();
        $totals[$stock_id]=($totals[$stock_id]??0)-$item['quantity']+$qty;
        if (!apply_filters('woocommerce_update_cart_validation',true,$key,$item,$qty)) return new WP_Error('quantity','商品數量未通過檢查，請確認商品限制。');
        $changes[$key]=[$qty,$product,$stock_id];
    }
    foreach ($changes as [$qty,$product,$stock_id]) {
        if ($qty>0 && (!$product->is_in_stock() || !$product->has_enough_stock($totals[$stock_id]))) return new WP_Error('stock','商品庫存不足，請減少數量後再試。');
    }
    foreach ($changes as $key=>[$qty]) WC()->cart->set_quantity($key,$qty,false);
    WC()->cart->calculate_totals();
    WC()->cart->set_session();
    return true;
}

add_action('wc_ajax_wutm_onepage_cart', 'wutm_onepage_update_cart');
function wutm_onepage_update_cart(): void {
    if (!wutm_wc_custom_is_onepage_checkout_enabled() || !WC()->cart || !check_ajax_referer('wutm_onepage_cart','security',false)) wp_send_json_error(['message'=>'連線驗證已過期，請重新整理後再試。'],403);
    $result=wutm_onepage_set_quantities(wp_unslash($_POST['cart']??null));
    if (is_wp_error($result)) wp_send_json_error(['message'=>$result->get_error_message()],400);
    if (WC()->cart->is_empty()) wp_send_json_success(['empty'=>true,'redirect'=>wc_get_checkout_url()]);
    ob_start();wutm_wc_custom_render_cart_on_checkout(true);$html=ob_get_clean();
    wp_send_json_success(['html'=>$html]);
}
