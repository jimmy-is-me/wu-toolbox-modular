<?php
require_once dirname(__DIR__) . '/modules/member-loyalty/promotion-policy.php';

function check_same($expected, $actual, string $message): void {
    if ($expected !== $actual) {
        fwrite(STDERR, $message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

class PromotionPolicyCart {
    private $subtotal;
    private $discount;
    private $fees;
    public function __construct(float $subtotal, float $discount, array $fees = []) { $this->subtotal = $subtotal; $this->discount = $discount; $this->fees = $fees; }
    public function get_subtotal(): float { return $this->subtotal; }
    public function get_discount_total(): float { return $this->discount; }
    public function get_fees(): array { return $this->fees; }
}

class PromotionPolicyOrderFee {
    private $name;
    private $total;
    public function __construct(string $name, float $total) { $this->name = $name; $this->total = $total; }
    public function get_name(): string { return $this->name; }
    public function get_total(): float { return $this->total; }
}

class PromotionPolicyOrder {
    private $subtotal;
    private $discount;
    private $fees;
    public function __construct(float $subtotal, float $discount, array $fees = []) { $this->subtotal = $subtotal; $this->discount = $discount; $this->fees = $fees; }
    public function get_subtotal(): float { return $this->subtotal; }
    public function get_discount_total(): float { return $this->discount; }
    public function get_fees(): array { return $this->fees; }
}

$fees = [
    (object) ['name' => 'Gold 專屬折扣', 'amount' => -90],
    (object) ['name' => '新會員優惠', 'amount' => -100],
    (object) ['name' => '點數折抵', 'amount' => -200],
];
$cart = new PromotionPolicyCart(1000, 100, $fees);
check_same(710.0, wutm_loyalty_discountable_amount($cart), 'points must be excluded while calculating their own fee');
check_same(510.0, wutm_loyalty_discountable_amount($cart, false), 'all discounts must be counted for final net merchandise');
check_same(true, wutm_loyalty_has_tier_discount_fee($cart), 'tier fee should suppress the new-member fee');

check_same(6, wutm_loyalty_whole_discount(1000, 1000 * 0.0055), 'percentage discounts round to the nearest whole unit');
check_same(21, wutm_loyalty_whole_discount(209.5, 20.95), 'rounded discount remains whole-dollar');
check_same(209, wutm_loyalty_whole_discount(209.5, 500), 'discount cannot exceed remaining goods value');
check_same(0, wutm_loyalty_whole_discount(200, -5), 'negative configuration cannot create a discount');

$order = new PromotionPolicyOrder(1000, 100, [
    new PromotionPolicyOrderFee('Gold 專屬折扣', -90),
    new PromotionPolicyOrderFee('新會員優惠', -100),
    new PromotionPolicyOrderFee('點數折抵', -200),
]);
check_same(510.0, wutm_loyalty_order_discountable_amount($order), 'earned points use order value net of every promotion');
check_same(710.0, wutm_loyalty_order_discountable_amount($order, true), 'redemption cap uses value before subtracting its own points fee');

fwrite(STDOUT, "Member loyalty promotion policy checks passed.\n");
