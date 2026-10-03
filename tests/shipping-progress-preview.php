<?php
/** Local-only visual fixture, excluded from the release ZIP with all tests. */
if (PHP_SAPI !== 'cli-server') exit;
$wutm_sp_preview = true;
require __DIR__ . '/shipping-progress.php';
$caps = array('manage_woocommerce', 'edit_shop_orders');
$_POST = array(); $_GET = array();
$product_meta[5]['_wutm_shipping_schedule'] = plan('2026-11-20', '2026-11-25');
$product_meta[5]['_wutm_shipping_schedule']['status'] = 'delayed';
$product_meta[5]['_wutm_shipping_schedule']['message'] = '供應商時程調整，原預計 11/10～11/15 出貨已延後。最新時程如上，造成不便敬請見諒。';
unset($product_meta[6]['_wutm_shipping_schedule']);
$item->meta['_wutm_shipping_schedule_override'] = plan('2026-11-10', '2026-11-15');
$item->meta['_wutm_shipping_schedule_override']['status'] = 'shipped';
$item->meta['_wutm_shipping_schedule_override']['message'] = '此批商品已交付物流，預計到貨日期仍可能因配送狀況調整。';
$demo = new WUTM_Shipping_Progress();
?>
<!doctype html><html lang="zh-Hant"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Shipping progress visual fixture</title><link rel="stylesheet" href="/assets/css/shipping-progress.css"><style>body{margin:0;font-family:system-ui,sans-serif;background:#f4f6f8;color:#182f43}main{max-width:960px;margin:32px auto;padding:0 20px}button,input,select,textarea{font:inherit}.button{display:inline-block;padding:9px 15px;border:1px solid #bbb;border-radius:5px;background:#fff;text-decoration:none;color:#2271b1}.button-primary{background:#2271b1;color:#fff}</style></head><body><main>
<?php echo $demo->shortcode(); $demo->order_details($order); ?>
<div class="wutm-sp-admin"><section class="wutm-sp-admin-panel"><h2>管理員：商品預計時間</h2><?php $demo->product_fields(new WP_Post(5)); ?></section>
<section class="wutm-sp-admin-panel"><h2>管理員：按訂單管理批次</h2><?php $demo->order_fields($order); ?></section></div>
<?php $demo->admin_panel(); ?>
</main><script src="/assets/js/shipping-progress-admin.js"></script></body></html>
