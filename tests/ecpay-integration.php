<?php
$root = dirname(__DIR__) . '/modules/ecpay-tools/vendor/ecpay-ecommerce-for-woocommerce';

function ecpay_verify($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}

function ecpay_source($root, $path) {
    $contents = file_get_contents($root . '/' . $path);
    ecpay_verify($contents !== false, 'Missing ECPay file: ' . $path);
    return $contents;
}

$main = ecpay_source($root, 'ecpay-ecommerce-for-woocommerce.php');
foreach (['payment/class-wooecpay-gateway.php', 'logistic/class-wooecpay-logistic.php', 'invoice/class-wooecpay-invoice.php'] as $loader) {
    ecpay_verify(strpos($main, $loader) !== false, 'Main loader missing ' . $loader);
}

$settings = ecpay_source($root, 'admin/settings/class-wooecpay-setting-main.php');
foreach (['ecpay_main', 'ecpay_payment', 'ecpay_logistic', 'ecpay_invoice'] as $section) {
    ecpay_verify(strpos($settings, "'" . $section . "'") !== false, 'Settings section missing: ' . $section);
}

$payment = ecpay_source($root, 'includes/services/payment/class-wooecpay-gateway.php');
ecpay_verify(strpos($payment, "add_filter('woocommerce_payment_gateways'") !== false, 'Payment gateways are not registered');
ecpay_verify(strpos($payment, 'Wooecpay_Gateway_Credit') !== false, 'Credit-card gateway is missing');

$logistic = ecpay_source($root, 'includes/services/logistic/class-wooecpay-logistic.php');
ecpay_verify(strpos($logistic, "add_filter('woocommerce_shipping_methods'") !== false, 'Shipping methods are not registered');
ecpay_verify(strpos($logistic, 'Wooecpay_Logistic_CVS_711') !== false, '7-11 shipping is missing');
ecpay_verify(strpos($logistic, 'Wooecpay_Logistic_Home_Tcat') !== false, 'Home delivery is missing');

$invoice = ecpay_source($root, 'includes/services/invoice/class-wooecpay-invoice.php');
foreach (['invoice_type', 'invoice_carruer_type', 'invoice_customer_identifier', 'invoice_carruer_num'] as $field) {
    ecpay_verify(strpos($invoice, $field) !== false, 'Invoice field missing: ' . $field);
}

$blocks = ecpay_source($root, 'includes/services/invoice/class-blocks-integration.php');
ecpay_verify(strpos($blocks, "WOOECPAY_PLUGIN_DIR . 'build/index.asset.php'") !== false, 'Block editor asset must use a filesystem path');
ecpay_verify(is_file($root . '/build/index.js'), 'Invoice block editor script is missing');
ecpay_verify(is_file($root . '/build/checkout-block-frontend.js'), 'Invoice checkout script is missing');

echo "ECPay payment, shipping and invoice integration checks passed.\n";
