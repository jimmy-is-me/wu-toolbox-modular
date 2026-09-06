<?php

/**
 * Earning points hooks
 */
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Apply the store's configured points rounding mode to a raw float value.
 *
 * @param float $value Raw (float) points value.
 * @return int
 */
function spar_round_points(  $value  ) {
    $options = get_option( 'spar_options', [] );
    $mode = $options['earn']['order']['rounding_mode'] ?? 'floor';
    if ( 'ceil' === $mode ) {
        return (int) ceil( $value );
    }
    if ( 'round' === $mode ) {
        return (int) round( $value );
    }
    return (int) floor( $value );
}

/**
 * Whether order earning should be blocked because the customer redeemed points on the order.
 *
 * Controlled by the "Do not award points on orders where points were redeemed at checkout"
 * setting under Earn > Place an Order (default enabled). Applies to both spend-based
 * and fixed-tier order earning.
 */
function spar_order_earning_blocked_by_redemption(  $order  ) {
    if ( !$order || !method_exists( $order, 'get_meta' ) ) {
        return false;
    }
    $options = get_option( 'spar_options', [] );
    $enabled = ( array_key_exists( 'skip_if_redeemed', (array) ($options['earn']['order'] ?? []) ) ? (bool) $options['earn']['order']['skip_if_redeemed'] : false );
    if ( !$enabled ) {
        return false;
    }
    $redeemed_points = (int) $order->get_meta( '_spar_points_redeemed' );
    $redeemed_value = (float) $order->get_meta( '_spar_points_redeemed_value' );
    $blocked = $redeemed_points > 0 || $redeemed_value > 0;
    return (bool) apply_filters( 'spar_order_earning_blocked_by_redemption', $blocked, $order );
}

/**
 * Cart-side equivalent: blocks the displayed potential points when a redemption is active in the cart.
 */
function spar_cart_earning_blocked_by_redemption(  $cart = null  ) {
    $options = get_option( 'spar_options', [] );
    $enabled = ( array_key_exists( 'skip_if_redeemed', (array) ($options['earn']['order'] ?? []) ) ? (bool) $options['earn']['order']['skip_if_redeemed'] : false );
    if ( !$enabled ) {
        return false;
    }
    $amount = 0.0;
    $session = ( function_exists( 'spar_get_wc_session' ) ? spar_get_wc_session() : null );
    if ( !$session && function_exists( 'WC' ) ) {
        $woocommerce = WC();
        $session = ( $woocommerce && !empty( $woocommerce->session ) ? $woocommerce->session : null );
    }
    if ( $session ) {
        $data = $session->get( 'spar_points_redemption' );
        if ( is_array( $data ) && isset( $data['amount'] ) ) {
            $amount = (float) $data['amount'];
        }
    }
    if ( $amount <= 0 && $cart && method_exists( $cart, 'get_fees' ) ) {
        $fees = $cart->get_fees();
        if ( is_array( $fees ) ) {
            foreach ( $fees as $fee ) {
                $name = '';
                $amt = 0.0;
                if ( is_object( $fee ) ) {
                    if ( isset( $fee->name ) ) {
                        $name = (string) $fee->name;
                    } elseif ( method_exists( $fee, 'get_name' ) ) {
                        $name = (string) $fee->get_name();
                    }
                    if ( isset( $fee->amount ) ) {
                        $amt = (float) $fee->amount;
                    } elseif ( method_exists( $fee, 'get_amount' ) ) {
                        $amt = (float) $fee->get_amount();
                    } elseif ( method_exists( $fee, 'get_total' ) ) {
                        $amt = (float) $fee->get_total();
                    }
                } elseif ( is_array( $fee ) ) {
                    $name = ( isset( $fee['name'] ) ? (string) $fee['name'] : '' );
                    $amt = ( isset( $fee['amount'] ) ? (float) $fee['amount'] : (( isset( $fee['total'] ) ? (float) $fee['total'] : 0.0 )) );
                }
                if ( $name && stripos( $name, 'points redemption' ) !== false && (float) $amt < 0 ) {
                    $amount += abs( (float) $amt );
                }
            }
        }
    }
    $blocked = $amount > 0;
    return (bool) apply_filters(
        'spar_cart_earning_blocked_by_redemption',
        $blocked,
        $cart,
        $amount
    );
}

/**
 * Calculate default order points rate and base points.
 *
 * Rate is derived from either legacy points_per (points per 1 unit)
 * or the new split fields: points_per_points / points_per_amount.
 * A filter allows premium code to override the rate per currency.
 */
function spar_get_order_points_rate(  $currency_code = ''  ) {
    $options = get_option( 'spar_options', [] );
    $order_opts = $options['earn']['order'] ?? [];
    // New split fields
    $points = ( isset( $order_opts['points_per_points'] ) ? (float) $order_opts['points_per_points'] : null );
    $amount = ( isset( $order_opts['points_per_amount'] ) ? (float) $order_opts['points_per_amount'] : null );
    // Fallbacks
    if ( $points === null ) {
        $points = ( isset( $order_opts['points_per'] ) ? (float) $order_opts['points_per'] : 0.0 );
        // legacy
    }
    if ( $amount === null || $amount <= 0 ) {
        $amount = 1.0;
    }
    $rate = ( $amount > 0 ? (float) $points / (float) $amount : 0.0 );
    /**
     * Filter: allows premium to override the rate based on currency or other context
     *
     * @param float  $rate          Calculated default rate (points per 1.0 currency unit)
     * @param string $currency_code Order/store currency code
     */
    $rate = apply_filters( 'spar_order_points_rate', $rate, $currency_code );
    return max( 0.0, (float) $rate );
}

function spar_get_points_calculation_preferences(  $earn_key = 'order'  ) {
    static $cache = [];
    $earn_key = ( is_string( $earn_key ) ? $earn_key : 'order' );
    if ( isset( $cache[$earn_key] ) ) {
        return $cache[$earn_key];
    }
    $defaults = spar_settings_default();
    $options = get_option( 'spar_options', $defaults );
    $options = array_merge( $defaults, $options );
    $earn_defaults = $defaults['earn'][$earn_key] ?? [];
    $earn_current = ( isset( $options['earn'][$earn_key] ) && is_array( $options['earn'][$earn_key] ) ? $options['earn'][$earn_key] : [] );
    $prefs_source = ( is_array( $earn_defaults ) ? array_merge( $earn_defaults, $earn_current ) : $earn_current );
    $mode = ( isset( $prefs_source['calculation_total_mode'] ) ? (string) $prefs_source['calculation_total_mode'] : 'subtotal_after_discount' );
    $valid_modes = ['total_before_discounts', 'subtotal_after_discount'];
    if ( !in_array( $mode, $valid_modes, true ) ) {
        $mode = 'subtotal_after_discount';
    }
    $include_shipping = ( array_key_exists( 'calculation_include_shipping', $prefs_source ) ? (bool) $prefs_source['calculation_include_shipping'] : false );
    $include_taxes = ( array_key_exists( 'calculation_include_taxes', $prefs_source ) ? (bool) $prefs_source['calculation_include_taxes'] : false );
    $cache[$earn_key] = [
        'mode'             => $mode,
        'include_shipping' => $include_shipping,
        'include_taxes'    => $include_taxes,
    ];
    return $cache[$earn_key];
}

function spar_resolve_points_calculation_total(
    $mode,
    $include_shipping,
    $include_taxes,
    $before_discount,
    $after_discount,
    $shipping_total = 0.0,
    $shipping_tax_total = 0.0,
    $tax_total = 0.0
) {
    $valid_modes = ['total_before_discounts', 'subtotal_after_discount'];
    if ( !in_array( $mode, $valid_modes, true ) ) {
        $mode = 'subtotal_after_discount';
    }
    $before_discount = ( is_numeric( $before_discount ) ? (float) $before_discount : 0.0 );
    $has_after_discount = null !== $after_discount && is_numeric( $after_discount );
    $after_discount = ( $has_after_discount ? (float) $after_discount : $before_discount );
    $base = ( 'total_before_discounts' === $mode ? $before_discount : $after_discount );
    if ( $include_shipping ) {
        $base += (float) $shipping_total;
        if ( $include_taxes ) {
            $base += (float) $shipping_tax_total;
        }
    }
    if ( $include_taxes ) {
        $base += max( 0.0, (float) $tax_total );
    }
    return max( 0.0, (float) $base );
}

function spar_get_points_calculation_total_from_order(  $order, $earn_key = 'order'  ) {
    if ( !$order ) {
        return 0.0;
    }
    if ( spar_order_earning_blocked_by_redemption( $order ) ) {
        return 0.0;
    }
    $prefs = spar_get_points_calculation_preferences( $earn_key );
    $before = ( method_exists( $order, 'get_subtotal' ) ? (float) $order->get_subtotal() : 0.0 );
    $after = null;
    $sale_before = 0.0;
    $sale_after = 0.0;
    $sale_tax = 0.0;
    $exclude_sale = !empty( get_option( 'spar_options', [] )['earn'][$earn_key]['exclude_sale_items'] );
    foreach ( $order->get_items() as $item ) {
        $item_total = ( method_exists( $item, 'get_total' ) ? (float) $item->get_total() : null );
        if ( null !== $item_total ) {
            if ( null === $after ) {
                $after = 0.0;
            }
            $after += $item_total;
        }
        if ( $exclude_sale ) {
            $product = ( method_exists( $item, 'get_product' ) ? $item->get_product() : null );
            if ( $product && method_exists( $product, 'is_on_sale' ) && $product->is_on_sale() ) {
                $sale_before += ( method_exists( $item, 'get_subtotal' ) ? (float) $item->get_subtotal() : 0.0 );
                $sale_after += ( null !== $item_total ? $item_total : 0.0 );
                $sale_tax += ( method_exists( $item, 'get_total_tax' ) ? (float) $item->get_total_tax() : 0.0 );
            }
        }
    }
    if ( null === $after ) {
        $discount_total = 0.0;
        if ( method_exists( $order, 'get_discount_total' ) ) {
            $discount_total = (float) $order->get_discount_total();
        } elseif ( method_exists( $order, 'get_total_discount' ) ) {
            $discount_total = (float) $order->get_total_discount();
        }
        $after = max( 0.0, $before - $discount_total );
    }
    $shipping_total = ( method_exists( $order, 'get_shipping_total' ) ? (float) $order->get_shipping_total() : 0.0 );
    $shipping_tax = ( method_exists( $order, 'get_shipping_tax' ) ? (float) $order->get_shipping_tax() : 0.0 );
    $total_tax = ( method_exists( $order, 'get_total_tax' ) ? (float) $order->get_total_tax() : 0.0 );
    $item_tax_total = max( 0.0, $total_tax - $shipping_tax );
    $total = spar_resolve_points_calculation_total(
        $prefs['mode'],
        $prefs['include_shipping'],
        $prefs['include_taxes'],
        $before,
        $after,
        $shipping_total,
        $shipping_tax,
        $item_tax_total
    );
    // Exclude the value of on-sale items when the "exclude sale items" option is enabled.
    if ( $exclude_sale ) {
        $sale_deduction = ( 'total_before_discounts' === $prefs['mode'] ? $sale_before : $sale_after );
        if ( $prefs['include_taxes'] ) {
            $sale_deduction += $sale_tax;
        }
        if ( $sale_deduction > 0 ) {
            $total = max( 0.0, (float) $total - (float) $sale_deduction );
        }
    }
    // Ensure we do not award points on the portion discounted by points redemption
    $points_discount = 0.0;
    // Prefer explicit meta captured at checkout
    if ( method_exists( $order, 'get_meta' ) ) {
        $points_discount = (float) $order->get_meta( '_spar_points_redeemed_value' );
    }
    // Fallback: scan fee line items for a negative "Points Redemption" amount
    if ( $points_discount <= 0 && method_exists( $order, 'get_items' ) ) {
        $fees = $order->get_items( 'fee' );
        if ( is_array( $fees ) ) {
            foreach ( $fees as $fee ) {
                $name = ( method_exists( $fee, 'get_name' ) ? (string) $fee->get_name() : '' );
                $amt = ( method_exists( $fee, 'get_total' ) ? (float) $fee->get_total() : 0.0 );
                if ( $name && stripos( $name, 'points redemption' ) !== false && $amt < 0 ) {
                    $points_discount += abs( (float) $amt );
                }
            }
        }
    }
    if ( $points_discount > 0 ) {
        $total = max( 0.0, (float) $total - (float) $points_discount );
    }
    // Subtract refunded amounts so partial refunds reduce points earned total.
    $refunded_total = 0.0;
    if ( method_exists( $order, 'get_total_refunded' ) ) {
        $refunded_total = (float) $order->get_total_refunded();
        $refunded_tax = ( method_exists( $order, 'get_total_tax_refunded' ) ? (float) $order->get_total_tax_refunded() : 0.0 );
        $refunded_shipping = ( method_exists( $order, 'get_total_shipping_refunded' ) ? (float) $order->get_total_shipping_refunded() : 0.0 );
        $refunded_shipping_tax = ( method_exists( $order, 'get_total_shipping_tax_refunded' ) ? (float) $order->get_total_shipping_tax_refunded() : 0.0 );
        if ( !$prefs['include_shipping'] ) {
            $refunded_total -= (float) $refunded_shipping;
            if ( $prefs['include_taxes'] ) {
                $refunded_total -= (float) $refunded_shipping_tax;
            }
        }
        if ( !$prefs['include_taxes'] ) {
            $refunded_total -= (float) $refunded_tax;
        }
        $refunded_total = max( 0.0, (float) $refunded_total );
        $refunded_total = (float) apply_filters(
            'spar_points_calculation_total_refunded_from_order',
            $refunded_total,
            $order,
            $earn_key,
            $prefs
        );
        if ( $refunded_total > 0 ) {
            $total = max( 0.0, (float) $total - (float) $refunded_total );
        }
    }
    return (float) apply_filters(
        'spar_points_calculation_total_from_order',
        $total,
        $order,
        $earn_key,
        $prefs
    );
}

function spar_get_points_calculation_total_from_cart(  $earn_key = 'order', $cart = null  ) {
    if ( null === $cart ) {
        $cart = ( function_exists( 'spar_get_wc_cart' ) ? spar_get_wc_cart() : null );
        if ( !$cart && function_exists( 'WC' ) ) {
            $woocommerce = WC();
            $cart = ( $woocommerce && !empty( $woocommerce->cart ) ? $woocommerce->cart : null );
        }
    }
    if ( !$cart ) {
        return 0.0;
    }
    if ( spar_cart_earning_blocked_by_redemption( $cart ) ) {
        return 0.0;
    }
    $prefs = spar_get_points_calculation_preferences( $earn_key );
    $before = null;
    $after = null;
    $sale_before = 0.0;
    $sale_after = 0.0;
    $sale_tax = 0.0;
    $exclude_sale = !empty( get_option( 'spar_options', [] )['earn'][$earn_key]['exclude_sale_items'] );
    if ( method_exists( $cart, 'get_cart' ) ) {
        $cart_contents = $cart->get_cart();
        if ( is_array( $cart_contents ) ) {
            foreach ( $cart_contents as $cart_item ) {
                if ( array_key_exists( 'line_subtotal', $cart_item ) ) {
                    if ( null === $before ) {
                        $before = 0.0;
                    }
                    $before += (float) $cart_item['line_subtotal'];
                }
                if ( array_key_exists( 'line_total', $cart_item ) ) {
                    if ( null === $after ) {
                        $after = 0.0;
                    }
                    $after += (float) $cart_item['line_total'];
                }
                if ( $exclude_sale ) {
                    $product = ( isset( $cart_item['data'] ) && is_object( $cart_item['data'] ) ? $cart_item['data'] : null );
                    if ( $product && method_exists( $product, 'is_on_sale' ) && $product->is_on_sale() ) {
                        $sale_before += ( isset( $cart_item['line_subtotal'] ) ? (float) $cart_item['line_subtotal'] : 0.0 );
                        $sale_after += ( isset( $cart_item['line_total'] ) ? (float) $cart_item['line_total'] : 0.0 );
                        $sale_tax += ( isset( $cart_item['line_tax'] ) ? (float) $cart_item['line_tax'] : 0.0 );
                    }
                }
            }
        }
    }
    if ( null === $before && method_exists( $cart, 'get_subtotal' ) ) {
        $before = (float) $cart->get_subtotal();
    }
    if ( null === $after && method_exists( $cart, 'get_cart_contents_total' ) ) {
        $cart_contents_total = $cart->get_cart_contents_total();
        if ( is_numeric( $cart_contents_total ) ) {
            $after = (float) $cart_contents_total;
        }
    }
    if ( null === $after && null !== $before ) {
        $discount_total = ( method_exists( $cart, 'get_discount_total' ) ? (float) $cart->get_discount_total() : 0.0 );
        $after = max( 0.0, (float) $before - max( 0.0, $discount_total ) );
    }
    if ( null === $before ) {
        $before = 0.0;
    }
    if ( null === $after ) {
        $after = $before;
    }
    $shipping_total = 0.0;
    if ( method_exists( $cart, 'get_shipping_total' ) ) {
        $shipping_total = (float) $cart->get_shipping_total();
    } elseif ( isset( $cart->shipping_total ) ) {
        $shipping_total = (float) $cart->shipping_total;
    }
    $shipping_tax = 0.0;
    if ( method_exists( $cart, 'get_shipping_tax_total' ) ) {
        $shipping_tax = (float) $cart->get_shipping_tax_total();
    } elseif ( isset( $cart->shipping_tax_total ) ) {
        $shipping_tax = (float) $cart->shipping_tax_total;
    }
    $total_tax = 0.0;
    if ( method_exists( $cart, 'get_total_tax' ) ) {
        $total_tax = (float) $cart->get_total_tax();
    } elseif ( isset( $cart->tax_total ) ) {
        $total_tax = (float) $cart->tax_total;
    }
    $item_tax_total = max( 0.0, $total_tax - $shipping_tax );
    $total = spar_resolve_points_calculation_total(
        $prefs['mode'],
        $prefs['include_shipping'],
        $prefs['include_taxes'],
        $before,
        $after,
        $shipping_total,
        $shipping_tax,
        $item_tax_total
    );
    // Exclude the value of on-sale items when the "exclude sale items" option is enabled.
    if ( $exclude_sale ) {
        $sale_deduction = ( 'total_before_discounts' === $prefs['mode'] ? $sale_before : $sale_after );
        if ( $prefs['include_taxes'] ) {
            $sale_deduction += $sale_tax;
        }
        if ( $sale_deduction > 0 ) {
            $total = max( 0.0, (float) $total - (float) $sale_deduction );
        }
    }
    // Ensure we do not award points on the portion discounted by points redemption
    $points_discount = 0.0;
    // Prefer session data set when redemption applied
    $session = ( function_exists( 'spar_get_wc_session' ) ? spar_get_wc_session() : null );
    if ( !$session && function_exists( 'WC' ) ) {
        $woocommerce = WC();
        $session = ( $woocommerce && !empty( $woocommerce->session ) ? $woocommerce->session : null );
    }
    if ( $session ) {
        $data = $session->get( 'spar_points_redemption' );
        if ( is_array( $data ) ) {
            $points_discount = ( isset( $data['amount'] ) ? (float) $data['amount'] : 0.0 );
        }
    }
    // Fallback: inspect fees on the cart
    if ( $points_discount <= 0 && method_exists( $cart, 'get_fees' ) ) {
        $fees = $cart->get_fees();
        if ( is_array( $fees ) ) {
            foreach ( $fees as $fee ) {
                $name = '';
                $amt = 0.0;
                if ( is_object( $fee ) ) {
                    // WC 3.x+: WC_Fee object
                    if ( isset( $fee->name ) ) {
                        $name = (string) $fee->name;
                    } elseif ( method_exists( $fee, 'get_name' ) ) {
                        $name = (string) $fee->get_name();
                    }
                    if ( isset( $fee->amount ) ) {
                        $amt = (float) $fee->amount;
                    } elseif ( method_exists( $fee, 'get_amount' ) ) {
                        $amt = (float) $fee->get_amount();
                    } elseif ( method_exists( $fee, 'get_total' ) ) {
                        $amt = (float) $fee->get_total();
                    }
                } elseif ( is_array( $fee ) ) {
                    $name = ( isset( $fee['name'] ) ? (string) $fee['name'] : '' );
                    $amt = ( isset( $fee['amount'] ) ? (float) $fee['amount'] : (( isset( $fee['total'] ) ? (float) $fee['total'] : 0.0 )) );
                }
                if ( $name && stripos( $name, 'points redemption' ) !== false && (float) $amt < 0 ) {
                    $points_discount += abs( (float) $amt );
                }
            }
        }
    }
    if ( $points_discount > 0 ) {
        $total = max( 0.0, (float) $total - (float) $points_discount );
    }
    return (float) apply_filters(
        'spar_points_calculation_total_from_cart',
        $total,
        $cart,
        $earn_key,
        $prefs
    );
}

function spar_calculate_order_base_points(  $order  ) {
    if ( !$order ) {
        return 0;
    }
    // Check for custom potential points
    $custom_points = $order->get_meta( 'custom_potential_points' );
    if ( '' !== $custom_points ) {
        return (int) $custom_points;
    }
    $total = spar_get_points_calculation_total_from_order( $order, 'order' );
    $currency = ( method_exists( $order, 'get_currency' ) ? $order->get_currency() : '' );
    $rate = spar_get_order_points_rate( $currency );
    $base = spar_round_points( $total * $rate );
    $base = spar_apply_order_spend_caps( $base, $total, $currency );
    /**
     * Filter base order points calculated from spend-based settings before multipliers/tiers.
     *
     * @param int        $base   Base points (int).
     * @param WC_Order   $order  Order object.
     * @param string     $currency Currency code.
     */
    return (int) apply_filters(
        'spar_order_base_points',
        $base,
        $order,
        $currency
    );
}

/**
 * Apply the "minimum spend to earn" gate configured for the spend-based (order)
 * earning type. The "maximum points per order" and "maximum percentage of total"
 * caps are NOT applied here; they cap the FINAL points earned (after multipliers
 * and fixed tiers) via spar_apply_order_points_total_cap().
 *
 * @param int|float $base_points       Base points calculated from spend.
 * @param float     $calculation_total The spend total the points were calculated from.
 * @param string    $currency          Currency code (unused, kept for BC).
 * @return int Adjusted base points.
 */
function spar_apply_order_spend_caps(  $base_points, $calculation_total, $currency = ''  ) {
    $options = get_option( 'spar_options', [] );
    $order_opts = ( isset( $options['earn']['order'] ) && is_array( $options['earn']['order'] ) ? $options['earn']['order'] : [] );
    $min_spend = ( isset( $order_opts['min_spend'] ) ? (float) $order_opts['min_spend'] : 0.0 );
    $base_points = (int) $base_points;
    // Minimum spend gate: award nothing when the qualifying total is below the threshold.
    if ( $min_spend > 0 && (float) $calculation_total < $min_spend ) {
        return 0;
    }
    return max( 0, $base_points );
}

/**
 * Apply the total-order caps ("Maximum points per order" and "Maximum percentage
 * of total") to the FINAL points earned from an order, after the level multiplier,
 * fixed tiers and any conditional adjustments have been applied.
 *
 * @param int|float $points            Final points to be awarded for the order.
 * @param float     $calculation_total The spend total the order was calculated from.
 * @param string    $currency          Currency code used for the percentage cap conversion.
 * @return int Capped points.
 */
function spar_apply_order_points_total_cap(  $points, $calculation_total, $currency = ''  ) {
    $options = get_option( 'spar_options', [] );
    $order_opts = ( isset( $options['earn']['order'] ) && is_array( $options['earn']['order'] ) ? $options['earn']['order'] : [] );
    $max_points = ( isset( $order_opts['max_points'] ) ? (int) $order_opts['max_points'] : 0 );
    $max_percent = ( isset( $order_opts['max_percent'] ) ? (float) $order_opts['max_percent'] : 0.0 );
    $points = (int) $points;
    // Maximum percentage cap: never award points worth more than X% of the order
    // total, using the redemption rate to convert the points value to currency.
    if ( $max_percent > 0 && (float) $calculation_total > 0 && function_exists( 'spar_get_redeem_rate_for_currency' ) ) {
        if ( '' === $currency && function_exists( 'get_woocommerce_currency' ) ) {
            $currency = get_woocommerce_currency();
        }
        $rate = spar_get_redeem_rate_for_currency( $currency );
        $rate_points = ( isset( $rate['points'] ) ? (float) $rate['points'] : 0.0 );
        $rate_amount = ( isset( $rate['amount'] ) ? (float) $rate['amount'] : 0.0 );
        if ( $rate_points > 0 && $rate_amount > 0 ) {
            $max_value = $max_percent / 100 * (float) $calculation_total;
            $max_points_by_percent = (int) floor( $max_value * $rate_points / $rate_amount );
            if ( $points > $max_points_by_percent ) {
                $points = $max_points_by_percent;
            }
        }
    }
    // Maximum points cap: never award more than the configured cap (0 = unlimited).
    if ( $max_points > 0 && $points > $max_points ) {
        $points = $max_points;
    }
    return max( 0, $points );
}

/**
 * Apply the total-order caps ("Maximum points per order" and "Maximum percentage
 * of total") to ONLY the points earned from "Points for Spending" (the spend-based
 * portion of an order), leaving fixed tier points and bonuses (first-order, signup,
 * etc.) untouched.
 *
 * The caps are evaluated against the spend points after the level multiplier, and
 * any overflow is subtracted from the combined total so fixed points and bonuses
 * are preserved.
 *
 * @param int|float $total_points      Combined final points (spend + fixed + bonuses).
 * @param int|float $base_points       Spend-based points BEFORE the multiplier.
 * @param float     $multiplier        Level multiplier applied to the spend points.
 * @param float     $calculation_total The spend total the order was calculated from.
 * @param string    $currency          Currency code used for the percentage cap conversion.
 * @return int Total points with the spend portion capped.
 */
function spar_apply_order_spend_only_cap(
    $total_points,
    $base_points,
    $multiplier,
    $calculation_total,
    $currency = ''
) {
    $total_points = (int) $total_points;
    if ( !function_exists( 'spar_apply_order_points_total_cap' ) ) {
        return max( 0, $total_points );
    }
    $spend_after_mult = (int) floor( max( 0.0, (float) $base_points ) * max( 0.0, (float) $multiplier ) );
    if ( $spend_after_mult <= 0 ) {
        return max( 0, $total_points );
    }
    $spend_capped = (int) spar_apply_order_points_total_cap( $spend_after_mult, $calculation_total, $currency );
    $overflow = max( 0, $spend_after_mult - $spend_capped );
    return max( 0, $total_points - $overflow );
}

/**
 * Calculate fixed order points from configured tiers for a given total and currency.
 * Returns array: [ 'points' => int, 'next_delta' => float|null, 'next_points' => int|null ]
 */
function spar_calculate_fixed_order_points_for_total(  $total, $currency  ) {
    $result = [
        'points'      => 0,
        'next_delta'  => null,
        'next_points' => null,
    ];
    if ( $total <= 0 ) {
        return $result;
    }
    $options = get_option( 'spar_options', [] );
    $earn = ( isset( $options['earn'] ) ? $options['earn'] : [] );
    $fixed = ( isset( $earn['order_fixed'] ) ? $earn['order_fixed'] : [] );
    if ( empty( $fixed['enabled'] ) ) {
        return $result;
    }
    $tiers = ( isset( $fixed['tiers'] ) && is_array( $fixed['tiers'] ) ? $fixed['tiers'] : [] );
    if ( empty( $tiers ) ) {
        return $result;
    }
    $store_currency = ( function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '' );
    $applicable = [];
    foreach ( $tiers as $tier ) {
        $points = ( isset( $tier['points'] ) ? (int) $tier['points'] : 0 );
        if ( $points <= 0 ) {
            continue;
        }
        $threshold = null;
        if ( $currency && $store_currency && strtoupper( $currency ) === strtoupper( $store_currency ) ) {
            if ( isset( $tier['default_threshold'] ) && (float) $tier['default_threshold'] > 0 ) {
                $threshold = (float) $tier['default_threshold'];
            }
        }
        if ( $threshold === null && !empty( $tier['currencies'] ) && is_array( $tier['currencies'] ) ) {
            foreach ( $tier['currencies'] as $row ) {
                $code = ( isset( $row['currency'] ) ? strtoupper( (string) $row['currency'] ) : '' );
                $thr = ( isset( $row['threshold'] ) ? (float) $row['threshold'] : 0.0 );
                if ( $code && $thr > 0 && strtoupper( $currency ) === $code ) {
                    $threshold = $thr;
                    break;
                }
            }
        }
        if ( $threshold !== null ) {
            $applicable[] = [
                'threshold' => (float) $threshold,
                'points'    => $points,
            ];
        }
    }
    if ( empty( $applicable ) ) {
        return $result;
    }
    usort( $applicable, function ( $a, $b ) {
        if ( $a['threshold'] == $b['threshold'] ) {
            return 0;
        }
        return ( $a['threshold'] < $b['threshold'] ? -1 : 1 );
    } );
    $current_points = 0;
    $next_delta = null;
    $next_points = null;
    foreach ( $applicable as $row ) {
        if ( $total >= (float) $row['threshold'] ) {
            $current_points = (int) $row['points'];
        } elseif ( $next_delta === null ) {
            $next_delta = max( 0.0, (float) $row['threshold'] - (float) $total );
            $next_points = max( 0, (int) $row['points'] - (int) $current_points );
            break;
        }
    }
    $result['points'] = (int) $current_points;
    $result['next_delta'] = $next_delta;
    $result['next_points'] = $next_points;
    return $result;
}

// Award on registration
add_action( 'user_register', 'spar_award_signup_points' );
function spar_award_signup_points(  $user_id  ) {
    $options = get_option( 'spar_options', [] );
    if ( !empty( $options['earn']['signup']['enabled'] ) ) {
        $signup_points = (int) $options['earn']['signup']['points'];
        if ( function_exists( 'spar_apply_level_multiplier' ) ) {
            $signup_points = spar_apply_level_multiplier( $user_id, $signup_points, 'signup' );
        }
        spar_update_user_points(
            $user_id,
            $signup_points,
            'add',
            esc_html__( 'Signup Bonus', 'simple-points-and-rewards' ),
            'signup'
        );
        // Set user meta _spar_rewards_earned['signup'] to true
        $user_rewards_earned = get_user_meta( $user_id, '_spar_rewards_earned', true );
        if ( !is_array( $user_rewards_earned ) ) {
            $user_rewards_earned = [];
        }
        $user_rewards_earned['signup'] = true;
        update_user_meta( $user_id, '_spar_rewards_earned', $user_rewards_earned );
    }
}

add_action( 'template_redirect', 'spar_maybe_award_rewards_page_points' );
function spar_maybe_award_rewards_page_points() {
    if ( !is_account_page() ) {
        return;
    }
    global $wp;
    if ( !isset( $wp->query_vars['rewards'] ) ) {
        return;
    }
    $user_id = get_current_user_id();
    if ( !$user_id ) {
        return;
    }
    $options = get_option( 'spar_options', [] );
    if ( empty( $options['earn']['signup']['enabled'] ) ) {
        return;
    }
    $user_rewards_earned = get_user_meta( $user_id, '_spar_rewards_earned', true );
    if ( !is_array( $user_rewards_earned ) || empty( $user_rewards_earned['signup'] ) ) {
        $signup_points = (int) $options['earn']['signup']['points'];
        if ( function_exists( 'spar_apply_level_multiplier' ) ) {
            $signup_points = spar_apply_level_multiplier( $user_id, $signup_points, 'signup' );
        }
        spar_update_user_points(
            $user_id,
            $signup_points,
            'add',
            esc_html__( 'Signup Bonus', 'simple-points-and-rewards' ),
            'signup'
        );
        if ( !is_array( $user_rewards_earned ) ) {
            $user_rewards_earned = [];
        }
        $user_rewards_earned['signup'] = true;
        update_user_meta( $user_id, '_spar_rewards_earned', $user_rewards_earned );
    }
}

// Award on order create or completion based on settings
add_action( 'woocommerce_thankyou', 'spar_award_order_points' );
add_action( 'woocommerce_order_status_completed', 'spar_award_order_points' );
add_action( 'init', 'spar_register_order_points_status_hook' );
function spar_register_order_points_status_hook() {
    $options = get_option( 'spar_options', [] );
    $completed_status = ( isset( $options['earn']['order']['completed_status'] ) ? sanitize_key( $options['earn']['order']['completed_status'] ) : 'completed' );
    if ( empty( $completed_status ) ) {
        $completed_status = 'completed';
    }
    if ( 'completed' !== $completed_status ) {
        add_action( 'woocommerce_order_status_' . $completed_status, 'spar_award_order_points' );
    }
}

function spar_calculate_order_award_points(  $order, $user_id = 0  ) {
    if ( !$order ) {
        return array(
            'points'       => 0,
            'base_points'  => 0,
            'fixed_points' => 0,
            'multiplier'   => 1.0,
        );
    }
    $order_id = ( method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0 );
    $user_id = ( $user_id ? absint( $user_id ) : (( method_exists( $order, 'get_user_id' ) ? absint( $order->get_user_id() ) : 0 )) );
    $base_points = spar_calculate_order_base_points( $order );
    $currency = ( method_exists( $order, 'get_currency' ) ? $order->get_currency() : '' );
    $fixed_total = spar_get_points_calculation_total_from_order( $order, 'order_fixed' );
    $fixed_info = spar_calculate_fixed_order_points_for_total( $fixed_total, $currency );
    $fixed_points = ( isset( $fixed_info['points'] ) ? (int) $fixed_info['points'] : 0 );
    $fixed_points = (int) apply_filters(
        'spar_order_fixed_points',
        $fixed_points,
        $order,
        $fixed_info
    );
    $multiplier = 1.0;
    if ( function_exists( 'spar_get_user_points_multiplier' ) ) {
        $multiplier = spar_get_user_points_multiplier( $user_id, 'order' );
    }
    $multiplier = (float) apply_filters(
        'spar_order_points_multiplier',
        $multiplier,
        $user_id,
        $order_id
    );
    $points_raw = ((int) $base_points + (int) $fixed_points) * $multiplier;
    $points = (int) apply_filters(
        'spar_order_awarded_points',
        spar_round_points( $points_raw ),
        $order_id,
        $user_id,
        (int) $base_points,
        (int) $fixed_points,
        (float) $multiplier
    );
    if ( function_exists( 'sparp_cr_calculate_conditionally_adjusted_points__premium_only' ) ) {
        $calc = sparp_cr_calculate_conditionally_adjusted_points__premium_only(
            (int) $base_points,
            (int) $fixed_points,
            (float) $multiplier,
            (int) $user_id,
            'order',
            $order
        );
        if ( is_array( $calc ) && isset( $calc['adjusted'] ) ) {
            $points = max( 0, (int) $calc['adjusted'] );
        }
    }
    // Apply the total-order caps ("Maximum points per order" / "Maximum percentage
    // of total") to ONLY the spend-based points, after the multiplier. Fixed tier
    // points and bonuses are left untouched.
    if ( function_exists( 'spar_apply_order_spend_only_cap' ) ) {
        $order_total = spar_get_points_calculation_total_from_order( $order, 'order' );
        $points = spar_apply_order_spend_only_cap(
            $points,
            (int) $base_points,
            (float) $multiplier,
            $order_total,
            $currency
        );
    }
    $calculation = array(
        'points'       => max( 0, (int) $points ),
        'base_points'  => (int) $base_points,
        'fixed_points' => (int) $fixed_points,
        'multiplier'   => (float) $multiplier,
    );
    /**
     * Filter the final order points calculation. Used both for awarding and for
     * potential-points displays, so adjustments here stay consistent everywhere.
     *
     * @param array    $calculation Keys: points, base_points, fixed_points, multiplier.
     * @param WC_Order $order       Order object.
     * @param int      $user_id     User ID (may be 0 for guests).
     */
    $calculation = apply_filters(
        'spar_order_award_points_calculation',
        $calculation,
        $order,
        $user_id
    );
    $calculation['points'] = max( 0, (int) ($calculation['points'] ?? 0) );
    return $calculation;
}

function spar_award_order_points(  $order_id, $force_award = false  ) {
    $options = get_option( 'spar_options', [] );
    $has_spend = !empty( $options['earn']['order']['enabled'] );
    $has_fixed = !empty( $options['earn']['order_fixed']['enabled'] );
    if ( !$has_spend && !$has_fixed ) {
        return;
    }
    $order = wc_get_order( $order_id );
    if ( !$order ) {
        return;
    }
    $user_id = $order->get_user_id();
    if ( !$user_id ) {
        return;
    }
    /**
     * Filter whether this call may award points for the order. Integrations can
     * block a specific award path (e.g. subscription renewals award via their
     * own timing-aware hook instead of the core thank-you/status hooks).
     *
     * @param bool     $allowed     Whether awarding may proceed.
     * @param WC_Order $order       Order object.
     * @param bool     $force_award Whether this is a forced award (admin "Grant points now", integrations).
     */
    if ( !apply_filters(
        'spar_award_order_points_allowed',
        true,
        $order,
        $force_award
    ) ) {
        return;
    }
    // Check if points have already been awarded for this order
    $points_already_awarded = $order->get_meta( 'points_earned' );
    $points_already_deducted = $order->get_meta( 'points_deducted' );
    if ( $points_already_awarded || $points_already_deducted ) {
        return;
    }
    // Skip awarding entirely when the customer redeemed points on this order
    // (controlled by the "skip_if_redeemed" setting under Earn > Place an Order).
    if ( spar_order_earning_blocked_by_redemption( $order ) ) {
        return;
    }
    // Check timing setting
    $award_timing = $options['earn']['order']['award_timing'] ?? 'thankyou';
    $completed_status = ( isset( $options['earn']['order']['completed_status'] ) ? sanitize_key( $options['earn']['order']['completed_status'] ) : 'completed' );
    if ( empty( $completed_status ) ) {
        $completed_status = 'completed';
    }
    $completed_hook = 'woocommerce_order_status_' . $completed_status;
    $current_hook = current_action();
    // Only award points if the current hook matches the setting (unless forced).
    if ( !$force_award ) {
        if ( $award_timing === 'thankyou' && $current_hook !== 'woocommerce_thankyou' || $award_timing === 'completed' && $current_hook !== $completed_hook ) {
            return;
        }
    }
    // Serialise concurrent award attempts (the thankyou and status hooks can
    // fire in parallel requests) and re-check the order meta while holding
    // the lock, so the awarded/deducted flags above cannot be read as empty
    // by two requests at once.
    $lock_key = 'order_points_' . $order_id;
    if ( !spar_acquire_db_lock( $lock_key ) ) {
        return;
        // Another request is already awarding for this order.
    }
    $order->read_meta_data( true );
    if ( $order->get_meta( 'points_earned' ) || $order->get_meta( 'points_deducted' ) ) {
        spar_release_db_lock( $lock_key );
        return;
    }
    /**
     * Fires before calculating and awarding order points for a given order.
     *
     * @param int      $order_id Order ID.
     * @param int|null $user_id  Customer user ID (may be 0 for guests).
     */
    do_action( 'spar_before_award_order_points', $order_id, $user_id );
    $calculation = spar_calculate_order_award_points( $order, $user_id );
    $points = (int) $calculation['points'];
    $multiplier = (float) $calculation['multiplier'];
    if ( $points > 0 ) {
        $action_text = esc_html__( 'Points Earned for Order', 'simple-points-and-rewards' ) . ': #' . $order_id;
        if ( $multiplier > 1.0 ) {
            $action_text .= sprintf( ' (%sx)', spar_format_multiplier( $multiplier ) );
        }
        /**
         * Filter the log action text stored for order-earned points.
         *
         * @param string   $action_text Default log text.
         * @param int      $order_id    Order ID.
         * @param int      $user_id     User ID.
         * @param int      $points      Final points awarded.
         * @param float    $multiplier  Effective multiplier.
         */
        $action_text = apply_filters(
            'spar_order_points_action_text',
            $action_text,
            $order_id,
            $user_id,
            $points,
            (float) $multiplier
        );
        // Sanitize for logging/email downstream
        $action_text = sanitize_text_field( (string) $action_text );
        spar_update_user_points(
            $user_id,
            $points,
            'add',
            $action_text,
            'order',
            array(
                'reference_type' => 'order',
                'reference_id'   => $order_id,
            )
        );
        // Include first-order bonus in points_earned meta if it exists
        $first_order_bonus = (int) $order->get_meta( 'points_first_order_bonus' );
        $total_points_earned = $points + $first_order_bonus;
        // Store the points earned as order meta (includes first-order bonus if applicable)
        $order->update_meta_data( 'points_earned', $total_points_earned );
        $order->save();
        /**
         * Fires after awarding order points and persisting order meta.
         *
         * @param int $order_id Order ID.
         * @param int $user_id  User ID.
         * @param int $points   Points awarded.
         */
        do_action(
            'spar_after_award_order_points',
            $order_id,
            $user_id,
            $points
        );
    }
    spar_release_db_lock( $lock_key );
}

// Deduct points on order refund or cancellation
add_action( 'woocommerce_order_status_refunded', 'spar_deduct_refunded_order_points' );
add_action( 'woocommerce_order_status_cancelled', 'spar_deduct_refunded_order_points' );
add_action( 'woocommerce_order_status_failed', 'spar_deduct_refunded_order_points' );
// Also respond to refund events in cases where status does not change (e.g., partial refunds)
add_action(
    'woocommerce_order_refunded',
    'spar_maybe_deduct_order_points_on_refund_event',
    10,
    2
);
add_action(
    'woocommerce_order_fully_refunded',
    'spar_maybe_deduct_order_points_on_refund_event',
    10,
    2
);
function spar_deduct_refunded_order_points(  $order_id  ) {
    static $orders_being_deducted = [];
    $order_id = absint( $order_id );
    if ( $order_id <= 0 ) {
        return;
    }
    if ( isset( $orders_being_deducted[$order_id] ) ) {
        return;
    }
    $order = wc_get_order( $order_id );
    if ( !$order ) {
        return;
    }
    $pending_cancelled_points = 0;
    $points_earned_after_pending = max( 0, (int) $order->get_meta( 'points_earned' ) - (int) $pending_cancelled_points );
    if ( $pending_cancelled_points > 0 ) {
        if ( function_exists( 'spar_points_delay_adjust_order_points_earned_meta' ) ) {
            $points_earned_after_pending = spar_points_delay_adjust_order_points_earned_meta( $order_id, -$pending_cancelled_points );
            $order = wc_get_order( $order_id );
            if ( !$order ) {
                return;
            }
        } elseif ( $points_earned_after_pending > 0 ) {
            $order->update_meta_data( 'points_earned', $points_earned_after_pending );
            $order->save();
        } else {
            $order->delete_meta_data( 'points_earned' );
            $order->save();
        }
    }
    $options = get_option( 'spar_options', [] );
    // Check if points deduction on refund is enabled (consider either setting, kept in sync)
    $deduct_spend = !empty( $options['earn']['order']['deduct_on_refund'] );
    $deduct_fixed = !empty( $options['earn']['order_fixed']['deduct_on_refund'] );
    if ( !$deduct_spend && !$deduct_fixed ) {
        return;
    }
    $user_id = $order->get_user_id();
    if ( !$user_id ) {
        return;
    }
    // Check if points were previously awarded for this order
    $points_awarded = max( 0, (int) $points_earned_after_pending );
    if ( $points_awarded <= 0 ) {
        return;
    }
    // Serialise concurrent deduction attempts (status hooks and refund events
    // can fire in parallel requests) and check the deducted flag fresh while
    // holding the lock. Shares the award lock key so awarding and deducting
    // for the same order also serialise against each other.
    $lock_key = 'order_points_' . $order_id;
    if ( !spar_acquire_db_lock( $lock_key ) ) {
        return;
        // Another request is already processing this order.
    }
    $order->read_meta_data( true );
    $points_deducted = (int) $order->get_meta( 'points_deducted' );
    if ( $points_deducted > 0 ) {
        spar_release_db_lock( $lock_key );
        return;
    }
    // Deduct the points
    $points_label = ( spar_get_option( '', 'points_label' ) ?: esc_html__( 'Points', 'simple-points-and-rewards' ) );
    $order_status = $order->get_status();
    $status_text = '';
    switch ( $order_status ) {
        case 'refunded':
            $status_text = esc_html__( 'Refunded', 'simple-points-and-rewards' );
            $status_id = 'order_refund';
            break;
        case 'cancelled':
            $status_text = esc_html__( 'Cancelled', 'simple-points-and-rewards' );
            $status_id = 'order_cancelled';
            break;
        case 'failed':
            $status_text = esc_html__( 'Failed', 'simple-points-and-rewards' );
            $status_id = 'order_failed';
            break;
        default:
            $status_text = esc_html__( 'Refunded/Cancelled', 'simple-points-and-rewards' );
            $status_id = 'order_refund';
    }
    // translators: 1: Points label (e.g., "Points"), 2: Order status text (e.g., "Refunded"), 3: Order ID number.
    $action_text = sprintf(
        esc_html__( '%1$s Deducted for %2$s Order: #%3$d', 'simple-points-and-rewards' ),
        $points_label,
        $status_text,
        $order_id
    );
    /**
     * Filter the number of points to deduct for a refunded/cancelled/failed order.
     * Note: points_awarded already includes first-order bonus if it was part of this order.
     *
     * @param int       $points_awarded Points previously awarded for the order.
     * @param int       $order_id       Order ID.
     * @param int       $user_id        User ID.
     * @param string    $order_status   Current order status slug.
     */
    $orders_being_deducted[$order_id] = true;
    $points_to_deduct = (int) apply_filters(
        'spar_order_points_to_deduct',
        (int) $points_awarded,
        (int) $order_id,
        (int) $user_id,
        (string) $order_status
    );
    if ( $points_to_deduct <= 0 ) {
        unset($orders_being_deducted[$order_id]);
        spar_release_db_lock( $lock_key );
        return;
    }
    /**
     * Filter the action_id used for the deduction log.
     *
     * @param string $status_id   Default action id (order_refund/order_cancelled/order_failed).
     * @param int    $order_id    Order ID.
     * @param int    $user_id     User ID.
     */
    $status_id = apply_filters(
        'spar_order_deduction_action_id',
        $status_id,
        (int) $order_id,
        (int) $user_id
    );
    $status_id = ( is_string( $status_id ) ? sanitize_key( $status_id ) : 'order_refund' );
    /**
     * Filter the log text stored for the deduction event.
     *
     * @param string $action_text Default message.
     * @param int    $order_id    Order ID.
     * @param int    $user_id     User ID.
     */
    $action_text = apply_filters(
        'spar_order_deduction_action_text',
        $action_text,
        (int) $order_id,
        (int) $user_id
    );
    $action_text = sanitize_text_field( (string) $action_text );
    spar_update_user_points(
        $user_id,
        $points_to_deduct,
        'remove',
        $action_text,
        $status_id,
        array(
            'reference_type' => 'order',
            'reference_id'   => $order_id,
        )
    );
    // Keep points_earned as historical award metadata and mark that points have been deducted.
    $order->update_meta_data( 'points_deducted', $points_to_deduct );
    $order->save();
    /**
     * Fires after deducting points tied to an order status change.
     *
     * @param int    $order_id Order ID.
     * @param int    $user_id  User ID.
     * @param int    $points   Points deducted.
     * @param string $status   Order status slug.
     */
    do_action(
        'spar_after_deduct_order_points',
        (int) $order_id,
        (int) $user_id,
        (int) $points_to_deduct,
        (string) $order_status
    );
    unset($orders_being_deducted[$order_id]);
    spar_release_db_lock( $lock_key );
}

/**
 * On refund events, only deduct earned order points if the order is now fully
 * refunded or has transitioned to a terminal status (refunded/cancelled/failed).
 * This avoids deducting the full amount on partial refunds.
 *
 * @param int $order_id Order ID.
 */
function spar_maybe_deduct_order_points_on_refund_event(  $order_id, $refund_id = 0  ) {
    $order_id = absint( $order_id );
    if ( $order_id <= 0 ) {
        return;
    }
    $order = wc_get_order( $order_id );
    if ( !$order ) {
        return;
    }
    if ( $order->has_status( array('refunded', 'cancelled', 'failed') ) ) {
        spar_deduct_refunded_order_points( $order_id );
        return;
    }
    $order_total = (float) $order->get_total();
    $total_refunded = (float) (( method_exists( $order, 'get_total_refunded' ) ? $order->get_total_refunded() : 0 ));
    if ( $order_total > 0 && $order_total - $total_refunded <= 0.01 ) {
        spar_deduct_refunded_order_points( $order_id );
    }
}
