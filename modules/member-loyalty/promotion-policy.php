<?php
/** Shared whole-dollar promotion math used by member and first-order discounts. */

/**
 * Return the product amount still available for Toolbox promotions.
 * WooCommerce coupons are first, then the tier discount, new-member discount,
 * and points redemption. Other negative fees also reduce the remaining goods
 * value so Toolbox discounts cannot exceed it.
 */
function wutm_loyalty_discountable_amount($cart, bool $exclude_points = true): float {
    if (!$cart || !method_exists($cart, 'get_subtotal') || !method_exists($cart, 'get_discount_total')) return 0.0;

    $amount = max(0.0, (float) $cart->get_subtotal() - (float) $cart->get_discount_total());
    foreach ((array) $cart->get_fees() as $fee) {
        $fee_amount = isset($fee->amount) ? (float) $fee->amount : (isset($fee->total) ? (float) $fee->total : 0.0);
        if ($fee_amount >= 0) continue;
        $fee_name = isset($fee->name) ? (string) $fee->name : '';
        if ($exclude_points && substr($fee_name, -strlen('折抵')) === '折抵') continue;
        $amount += $fee_amount;
    }

    return max(0.0, $amount);
}

/** A tier benefit wins over the one-time new-member promotion; they never stack. */
function wutm_loyalty_has_tier_discount_fee($cart): bool {
    if (!$cart || !method_exists($cart, 'get_fees')) return false;
    foreach ((array) $cart->get_fees() as $fee) {
        $name = isset($fee->name) ? (string) $fee->name : '';
        $amount = isset($fee->amount) ? (float) $fee->amount : (isset($fee->total) ? (float) $fee->total : 0.0);
        if ($amount < 0 && strpos($name, ' 專屬折扣') !== false) return true;
    }
    return false;
}

/** Net merchandise amount for points earned, including all order-level discount fees. */
function wutm_loyalty_order_discountable_amount($order, bool $exclude_points = false): float {
    if (!$order || !method_exists($order, 'get_subtotal') || !method_exists($order, 'get_discount_total')) return 0.0;
    $amount = max(0.0, (float) $order->get_subtotal() - (float) $order->get_discount_total());
    foreach ((array) $order->get_fees() as $fee) {
        $fee_amount = method_exists($fee, 'get_total') ? (float) $fee->get_total() : (float) ($fee->amount ?? 0);
        if ($fee_amount < 0) $amount += $fee_amount;
        if ($exclude_points && $fee_amount < 0) {
            $fee_name = method_exists($fee, 'get_name') ? (string) $fee->get_name() : (string) ($fee->name ?? '');
            if (substr($fee_name, -strlen('折抵')) === '折抵') $amount -= $fee_amount;
        }
    }
    return max(0.0, $amount);
}

/** Round a requested promotion to whole currency units without exceeding the remaining goods value. */
function wutm_loyalty_whole_discount(float $available_amount, float $requested_amount): int {
    $available = max(0, (int) floor(max(0.0, $available_amount)));
    $requested = max(0, (int) round(max(0.0, $requested_amount), 0));
    return min($available, $requested);
}
