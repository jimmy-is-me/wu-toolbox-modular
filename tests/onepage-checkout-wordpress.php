<?php
/** Real WooCommerce cart/session fixture. Never run against a merchant database. */
if(!defined('WP_CLI')||!WP_CLI||getenv('WUTM_MEDIA_INTEGRATION')!=='1'||DB_NAME!=='wci_fixture'||$GLOBALS['wpdb']->prefix!=='wme_wp_') throw new RuntimeException('Disposable CI only');
define('WUTM_URL','https://fixture.test/plugin/');define('WUTM_VERSION','fixture');
add_filter('pre_http_request',static function(){return new WP_Error('fixture','External transport blocked');},1);
function op_assert($ok,$label){if(!$ok)throw new RuntimeException($label);}
update_option('wutm_wc_onepage_checkout',true);update_option('woocommerce_enable_coupons','yes');
require dirname(__DIR__).'/modules/wc-optimization-tools/custom-features.php';
WC()->initialize_session();WC()->initialize_cart();WC()->cart->empty_cart();
$product=new WC_Product_Simple();$product->set_name('WU checkout fixture');$product->set_regular_price(100);$product->set_manage_stock(true);$product->set_stock_quantity(5);$product->save();
$coupon=new WC_Coupon();$coupon->set_code('wu-onepage-fixture');$coupon->set_discount_type('percent');$coupon->set_amount(10);$coupon->save();
try {
    $key=WC()->cart->add_to_cart($product->get_id(),1);
    op_assert(WC()->cart->apply_coupon($coupon->get_code()),'Native coupon applied');
    op_assert(wutm_onepage_set_quantities([$key=>['qty'=>'3']])===true,'Quantity updated');
    op_assert(WC()->cart->get_cart_item($key)['quantity']===3&&WC()->cart->has_discount($coupon->get_code()),'Quantity update preserves applied coupon');
    op_assert((float)WC()->cart->get_discount_total()===30.0,'Coupon recalculated for new quantity');
    foreach(['-2','bogus',[],100] as $invalid) {
        op_assert(is_wp_error(wutm_onepage_set_quantities([$key=>['qty'=>$invalid]])),'Invalid/overstock rejected');
        op_assert(WC()->cart->get_cart_item($key)['quantity']===3,'Rejected update does not mutate quantity');
    }
    $deny=static function(){return false;};add_filter('woocommerce_update_cart_validation',$deny);
    op_assert(is_wp_error(wutm_onepage_set_quantities([$key=>['qty'=>2]])),'Third party restriction respected');remove_filter('woocommerce_update_cart_validation',$deny);
    ob_start();wutm_wc_custom_render_cart_on_checkout(WC()->checkout());$html=ob_get_clean();
    op_assert(substr_count($html,'id="coupon_code"')===1&&strpos($html,'class="checkout_coupon')!==false,'Classic checkout hook argument renders visible native coupon form');
    op_assert(has_action('woocommerce_before_checkout_form','woocommerce_checkout_coupon_form')===false,'No duplicate native coupon form');
    op_assert(strpos($html,'data-update-url=')!==false&&strpos($html,'<script>')===false,'AJAX endpoint and no duplicate inline listeners');
    ob_start();wutm_wc_custom_render_cart_on_checkout(true);$fragment=ob_get_clean();
    op_assert(strpos($fragment,'coupon_code')===false,'Quantity refresh does not replace coupon form');
    // Exercise endpoint auth and success using WordPress's real JSON/die functions.
    add_filter('wp_die_ajax_handler',static function(){return static function(){throw new RuntimeException('json-finished');};});
    if(!defined('DOING_AJAX'))define('DOING_AJAX',true);
    foreach(['invalid',wp_create_nonce('wutm_onepage_cart')] as $nonce){
        $_POST=['security'=>$nonce,'cart'=>[$key=>['qty'=>2]]];$_REQUEST=$_POST;
        ob_start();try{wutm_onepage_update_cart();throw new LogicException('Missing JSON termination');}catch(RuntimeException $error){if($error->getMessage()!=='json-finished')throw $error;}$response=json_decode(ob_get_clean(),true);
        op_assert($response['success']===($nonce!=='invalid'),'Endpoint nonce boundary');
    }
    op_assert(WC()->cart->get_cart_item($key)['quantity']===2&&WC()->cart->has_discount($coupon->get_code()),'Real AJAX update preserves coupon');
    WC()->cart->remove_coupon($coupon->get_code());WC()->cart->calculate_totals();
    op_assert(!WC()->cart->has_discount($coupon->get_code()),'Native coupon removal preserved');
    op_assert(wutm_onepage_set_quantities([$key=>['qty'=>0]])===true&&WC()->cart->is_empty(),'Zero quantity removes final product');
    echo "Native onepage checkout passed: visible coupon, recalculation, stock/validation, AJAX nonce, removal and empty cart.\n";
} finally {$_POST=$_REQUEST=[];WC()->cart->empty_cart();$coupon->delete(true);$product->delete(true);delete_option('wutm_wc_onepage_checkout');}
