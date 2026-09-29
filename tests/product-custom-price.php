<?php
/** Lightweight business-rule checks for customer-entered product prices. */
define('ABSPATH', __DIR__ . '/');
define('WUTM_VERSION', 'test');

class WooCommerce {}
class WC_Product {
    private int $id;
    public float $price = 0;
    public function __construct(int $id) { $this->id = $id; }
    public function get_id(): int { return $this->id; }
    public function set_price(float $price): void { $this->price = $price; }
}
class WC_Cart {
    public function __construct(private array $items) {}
    public function get_cart(): array { return $this->items; }
}

$meta = [
    10 => [
        '_wumetax_custom_price_enabled' => 'yes',
        '_wumetax_custom_price_min' => '100',
        '_wumetax_custom_price_max' => '1000',
        '_wumetax_custom_price_step' => '100',
    ],
];
$parents = [11 => 10];
$notices = [];
$uuid = 0;
function add_action(...$args): void {}
function add_filter(...$args): void {}
function get_post_meta($id, $key, $single = true) { global $meta; return $meta[$id][$key] ?? ''; }
function wp_get_post_parent_id($id): int { global $parents; return $parents[$id] ?? 0; }
function wp_unslash($value) { return $value; }
function wc_format_decimal($value): string { return preg_replace('/[^0-9.\-]/', '', (string) $value); }
function wc_add_notice($message, $type = 'success'): void { global $notices; $notices[] = [$message, $type]; }
function wp_generate_uuid4(): string { global $uuid; return 'test-' . ++$uuid; }
function wc_price($amount): string { return 'NT$' . number_format((float) $amount, 2); }
function is_admin(): bool { return false; }

require dirname(__DIR__) . '/modules/product-custom-price/module.php';

function expect(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$_POST['wumetax_custom_price'] = '1,000';
expect(WUTM_Product_Custom_Price::is_enabled(11), 'Variation must inherit enabled state from parent.');
expect(WUTM_Product_Custom_Price::validate_add_to_cart(true, 10, 1, 11), 'Formatted amount within bounds should pass.');
$first = WUTM_Product_Custom_Price::add_cart_item_data([], 10, 11);
$second = WUTM_Product_Custom_Price::add_cart_item_data([], 10, 11);
expect(($first['wumetax_custom_price'] ?? null) === 1000.0, 'Thousands separator should be accepted.');
expect(($first['wumetax_custom_price_unique'] ?? '') !== ($second['wumetax_custom_price_unique'] ?? ''), 'Each chosen amount must get a distinct cart key.');

$_POST['wumetax_custom_price'] = '1000.01';
expect(!WUTM_Product_Custom_Price::validate_add_to_cart(true, 10, 1, 11), 'Amount above the maximum must fail server validation.');
expect(count($notices) === 1, 'Invalid amount should return a WooCommerce notice.');
$invalid_cart_data = WUTM_Product_Custom_Price::add_cart_item_data([], 10, 11);
expect(!isset($invalid_cart_data['wumetax_custom_price']), 'Invalid amount must not be added to cart data.');

$product = new WC_Product(10);
$cart = new WC_Cart([['wumetax_custom_price' => 500.0, 'data' => $product]]);
WUTM_Product_Custom_Price::apply_cart_prices($cart);
expect($product->price === 500.0, 'Cart totals must use the entered amount.');
$item_data = WUTM_Product_Custom_Price::display_cart_price([], ['wumetax_custom_price' => 500.0]);
expect(($item_data[0]['key'] ?? '') === '自訂金額', 'Cart should display the entered amount.');

$order_item = new class {
    public array $meta = [];
    public function add_meta_data($key, $value, $unique): void { $this->meta[$key] = $value; }
};
WUTM_Product_Custom_Price::save_order_price($order_item, 'cart-key', ['wumetax_custom_price' => 500.0], null);
expect(isset($order_item->meta['自訂金額']) && $order_item->meta['_wumetax_custom_price'] === 500.0, 'Order should retain formatted and numeric price metadata.');
expect(strpos(WUTM_Product_Custom_Price::display_product_price('', $product), 'NT$100.00 ～ NT$1,000.00') !== false, 'Product should show configured price range.');

echo "Custom product price checks passed.\n";
