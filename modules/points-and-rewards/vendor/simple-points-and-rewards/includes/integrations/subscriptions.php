<?php

/**
 * WooCommerce Subscriptions integration.
 *
 * Controls how points are earned on subscription orders:
 * - Renewal orders: enable/disable earning and a dedicated award timing
 *   (renewal orders never reach the thank-you page, so the "Place an Order"
 *   timing alone cannot award them reliably). The renewal earning rate
 *   (percentage of regular points, or a fixed amount per renewal) is a PRO
 *   option; the free version always awards renewals at the full regular rate.
 * - Initial (parent) orders: optionally exclude sign-up fees and/or
 *   subscription product line items from the points calculation.
 * - Order bonuses: optionally exclude renewal orders from the "Bonus after X
 *   Orders" milestone count (the milestone itself is a PRO earning method).
 *
 * All hooks below no-op unless WooCommerce Subscriptions is active.
 */
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Whether WooCommerce Subscriptions (or a fork exposing its order API) is active.
 *
 * @return bool
 */
function spar_subs_active() {
    return function_exists( 'wcs_order_contains_renewal' ) || class_exists( 'WC_Subscriptions' );
}

/**
 * Get the subscriptions settings with defaults applied.
 *
 * Reads the option directly (one cached get_option call) instead of the
 * per-field helpers, because these settings are consulted on hot paths
 * (cart/checkout previews and every order points calculation).
 *
 * The renewal earning rate is a PRO option — without an active PRO licence
 * the rate mode always resolves to 'same' (full regular rate).
 *
 * @return array
 */
function spar_subs_get_settings() {
    $options = get_option( 'spar_options', array() );
    $options = ( is_array( $options ) ? $options : array() );
    $timing = ( isset( $options['subscriptions_renewal_award_timing'] ) ? (string) $options['subscriptions_renewal_award_timing'] : 'follow_order' );
    // The renewal earning rate is a PRO option — the free version always uses
    // the full regular rate ('same').
    $rate_mode = 'same';
    return array(
        'renewal_enabled'     => ( array_key_exists( 'subscriptions_renewal_enabled', $options ) ? (bool) $options['subscriptions_renewal_enabled'] : true ),
        'rate_mode'           => ( in_array( $rate_mode, array('same', 'percentage', 'fixed'), true ) ? $rate_mode : 'same' ),
        'percentage'          => ( isset( $options['subscriptions_renewal_percentage'] ) ? max( 0.0, (float) $options['subscriptions_renewal_percentage'] ) : 100.0 ),
        'fixed_points'        => ( isset( $options['subscriptions_renewal_fixed_points'] ) ? max( 0, (int) $options['subscriptions_renewal_fixed_points'] ) : 0 ),
        'award_timing'        => ( in_array( $timing, array('follow_order', 'payment_complete', 'completed'), true ) ? $timing : 'follow_order' ),
        'include_signup_fee'  => ( array_key_exists( 'subscriptions_signup_fee_include', $options ) ? (bool) $options['subscriptions_signup_fee_include'] : true ),
        'exclude_products'    => !empty( $options['subscriptions_exclude_products'] ),
        'count_order_bonuses' => ( array_key_exists( 'subscriptions_count_order_bonuses', $options ) ? (bool) $options['subscriptions_count_order_bonuses'] : true ),
    );
}

/**
 * Whether an order is a subscription renewal order.
 *
 * @param WC_Order|int $order Order object or ID.
 * @return bool
 */
function spar_subs_is_renewal_order(  $order  ) {
    if ( !function_exists( 'wcs_order_contains_renewal' ) ) {
        return false;
    }
    return (bool) wcs_order_contains_renewal( $order );
}

/**
 * Resolve the effective renewal award timing.
 *
 * 'follow_order' maps the "Place an Order" timing onto its renewal equivalent:
 * immediate (thank-you page) earning becomes "when the renewal payment
 * completes", completed-status earning is kept as-is.
 *
 * @return string 'payment_complete' or 'completed'
 */
function spar_subs_resolve_renewal_timing() {
    $settings = spar_subs_get_settings();
    $timing = $settings['award_timing'];
    if ( 'follow_order' === $timing ) {
        $options = get_option( 'spar_options', array() );
        $order_timing = $options['earn']['order']['award_timing'] ?? 'thankyou';
        $timing = ( 'completed' === $order_timing ? 'completed' : 'payment_complete' );
    }
    return ( in_array( $timing, array('payment_complete', 'completed'), true ) ? $timing : 'payment_complete' );
}

/**
 * Renewal orders are awarded exclusively by the status-transition hook below
 * (always via the force path), so block the core thank-you/status award paths
 * for them. This keeps a single, timing-aware code path for renewals while the
 * admin "Grant points now" button (also forced) continues to work.
 */
add_filter(
    'spar_award_order_points_allowed',
    'spar_subs_block_core_award_path',
    10,
    3
);
function spar_subs_block_core_award_path(  $allowed, $order, $force_award  ) {
    if ( !$allowed || $force_award || !spar_subs_active() ) {
        return $allowed;
    }
    if ( spar_subs_is_renewal_order( $order ) ) {
        return false;
    }
    return $allowed;
}

/**
 * Award renewal order points when the renewal reaches the configured timing.
 */
add_action(
    'woocommerce_order_status_changed',
    'spar_subs_maybe_award_renewal_points',
    20,
    4
);
function spar_subs_maybe_award_renewal_points(
    $order_id,
    $old_status,
    $new_status,
    $order
) {
    if ( !spar_subs_active() ) {
        return;
    }
    $order = ( $order instanceof WC_Order ? $order : wc_get_order( $order_id ) );
    if ( !$order || !spar_subs_is_renewal_order( $order ) ) {
        return;
    }
    $settings = spar_subs_get_settings();
    if ( !$settings['renewal_enabled'] ) {
        return;
    }
    $spar_options = get_option( 'spar_options', array() );
    $completed_status = sanitize_key( $spar_options['earn']['order']['completed_status'] ?? 'completed' );
    if ( '' === $completed_status ) {
        $completed_status = 'completed';
    }
    $timing = spar_subs_resolve_renewal_timing();
    if ( 'completed' === $timing ) {
        $target_statuses = array($completed_status);
    } else {
        // Payment complete: the first paid status the renewal reaches.
        $target_statuses = array_unique( array('processing', 'completed', $completed_status) );
    }
    if ( !in_array( $new_status, $target_statuses, true ) ) {
        return;
    }
    // Force past the core timing check; the points_earned meta + DB lock inside
    // spar_award_order_points() prevent double awards across transitions.
    spar_award_order_points( $order->get_id(), true );
}

/**
 * Apply the configured renewal earning rate to a points amount.
 *
 * @param int   $points   Points calculated at the regular rate.
 * @param array $settings Settings from spar_subs_get_settings().
 * @return int Adjusted points.
 */
function spar_subs_apply_renewal_rate(  $points, $settings  ) {
    if ( !$settings['renewal_enabled'] ) {
        return 0;
    }
    return max( 0, (int) $points );
}

/**
 * Apply the renewal earning settings to the calculated order points.
 *
 * Runs on the final calculation used both for awarding and for "potential
 * points" displays, so admin previews match what will actually be awarded.
 */
add_filter(
    'spar_order_award_points_calculation',
    'spar_subs_adjust_renewal_calculation',
    10,
    2
);
function spar_subs_adjust_renewal_calculation(  $calculation, $order  ) {
    if ( !spar_subs_active() || !is_array( $calculation ) ) {
        return $calculation;
    }
    if ( !spar_subs_is_renewal_order( $order ) ) {
        return $calculation;
    }
    $calculation['points'] = spar_subs_apply_renewal_rate( (int) $calculation['points'], spar_subs_get_settings() );
    return $calculation;
}

/**
 * Keep the admin meta box "Potential Points" in line with the renewal
 * adjustments applied on award (it is calculated from base points, so it does
 * not pass through the final calculation filter above).
 */
add_filter(
    'spar_order_potential_points_display',
    'spar_subs_adjust_potential_points_display',
    10,
    2
);
function spar_subs_adjust_potential_points_display(  $potential_points, $order  ) {
    if ( !spar_subs_active() || !spar_subs_is_renewal_order( $order ) ) {
        return $potential_points;
    }
    return spar_subs_apply_renewal_rate( (int) $potential_points, spar_subs_get_settings() );
}

/**
 * Use a renewal-specific activity log label so customers and admins can tell
 * renewal earnings apart from regular order earnings.
 */
add_filter(
    'spar_order_points_action_text',
    'spar_subs_renewal_action_text',
    10,
    5
);
function spar_subs_renewal_action_text(
    $action_text,
    $order_id,
    $user_id,
    $points,
    $multiplier
) {
    if ( !spar_subs_active() || !spar_subs_is_renewal_order( $order_id ) ) {
        return $action_text;
    }
    $settings = spar_subs_get_settings();
    $text = esc_html__( 'Points Earned for Subscription Renewal', 'simple-points-and-rewards' ) . ': #' . $order_id;
    // The multiplier suffix only makes sense when the multiplier affected the total.
    if ( (float) $multiplier > 1.0 && 'fixed' !== $settings['rate_mode'] && function_exists( 'spar_format_multiplier' ) ) {
        $text .= sprintf( ' (%sx)', spar_format_multiplier( $multiplier ) );
    }
    return $text;
}

/**
 * Adjust the spend total points are calculated from on ORDERS:
 * - Optionally remove sign-up fees (initial/parent orders).
 * - Optionally remove subscription product line items (non-renewal orders only;
 *   renewal orders are governed by the renewal rate settings instead).
 */
add_filter(
    'spar_points_calculation_total_from_order',
    'spar_subs_filter_order_calculation_total',
    10,
    4
);
function spar_subs_filter_order_calculation_total(
    $total,
    $order,
    $earn_key,
    $prefs
) {
    if ( !spar_subs_active() || !$order || $total <= 0 ) {
        return $total;
    }
    // Renewal orders: line items ARE the subscription products, so the initial
    // order exclusions below must not apply — the renewal settings handle them.
    if ( spar_subs_is_renewal_order( $order ) ) {
        return $total;
    }
    $settings = spar_subs_get_settings();
    $deduction = 0.0;
    if ( $settings['exclude_products'] ) {
        foreach ( $order->get_items() as $item ) {
            $product = ( method_exists( $item, 'get_product' ) ? $item->get_product() : null );
            if ( !$product || !spar_subs_product_is_subscription( $product ) ) {
                continue;
            }
            $deduction += ( 'total_before_discounts' === $prefs['mode'] ? (float) $item->get_subtotal() : (float) $item->get_total() );
            if ( !empty( $prefs['include_taxes'] ) ) {
                $deduction += (float) $item->get_total_tax();
            }
        }
    } elseif ( !$settings['include_signup_fee'] && class_exists( 'WC_Subscriptions_Order' ) && function_exists( 'wcs_order_contains_subscription' ) && wcs_order_contains_subscription( $order, 'parent' ) ) {
        // Sign-up fee amounts are returned tax-exclusive by WooCommerce Subscriptions.
        $deduction += max( 0.0, (float) WC_Subscriptions_Order::get_sign_up_fee( $order ) );
    }
    return ( $deduction > 0 ? max( 0.0, (float) $total - $deduction ) : $total );
}

/**
 * Mirror the order-total adjustments on the CART preview so "you will earn X
 * points" messages match the points actually awarded for the initial order.
 */
add_filter(
    'spar_points_calculation_total_from_cart',
    'spar_subs_filter_cart_calculation_total',
    10,
    4
);
function spar_subs_filter_cart_calculation_total(
    $total,
    $cart,
    $earn_key,
    $prefs
) {
    if ( !spar_subs_active() || !$cart || $total <= 0 || !method_exists( $cart, 'get_cart' ) ) {
        return $total;
    }
    $settings = spar_subs_get_settings();
    if ( !$settings['exclude_products'] && $settings['include_signup_fee'] ) {
        return $total;
    }
    $deduction = 0.0;
    $cart_contents = $cart->get_cart();
    if ( !is_array( $cart_contents ) ) {
        return $total;
    }
    foreach ( $cart_contents as $cart_item ) {
        $product = ( isset( $cart_item['data'] ) && is_object( $cart_item['data'] ) ? $cart_item['data'] : null );
        if ( !$product || !spar_subs_product_is_subscription( $product ) ) {
            continue;
        }
        if ( $settings['exclude_products'] ) {
            $deduction += ( 'total_before_discounts' === $prefs['mode'] ? (float) ($cart_item['line_subtotal'] ?? 0) : (float) ($cart_item['line_total'] ?? 0) );
            if ( !empty( $prefs['include_taxes'] ) ) {
                $deduction += (float) ($cart_item['line_tax'] ?? 0);
            }
        } elseif ( class_exists( 'WC_Subscriptions_Product' ) ) {
            $signup_fee = (float) WC_Subscriptions_Product::get_sign_up_fee( $product );
            $quantity = ( isset( $cart_item['quantity'] ) ? max( 1, (int) $cart_item['quantity'] ) : 1 );
            $deduction += max( 0.0, $signup_fee ) * $quantity;
        }
    }
    return ( $deduction > 0 ? max( 0.0, (float) $total - $deduction ) : $total );
}

/**
 * Hide the product page / catalog "earn points" message for subscription
 * products when they are excluded from earning points.
 */
add_filter(
    'spar_product_points_display',
    'spar_subs_filter_product_points_display',
    10,
    2
);
function spar_subs_filter_product_points_display(  $points, $product  ) {
    if ( $points <= 0 || !$product || !spar_subs_active() ) {
        return $points;
    }
    $settings = spar_subs_get_settings();
    if ( $settings['exclude_products'] && spar_subs_product_is_subscription( $product ) ) {
        return 0;
    }
    return $points;
}

/**
 * Whether a product is a subscription product.
 *
 * @param WC_Product $product Product object.
 * @return bool
 */
function spar_subs_product_is_subscription(  $product  ) {
    if ( class_exists( 'WC_Subscriptions_Product' ) ) {
        return (bool) WC_Subscriptions_Product::is_subscription( $product );
    }
    return method_exists( $product, 'is_type' ) && $product->is_type( array('subscription', 'variable-subscription', 'subscription_variation') );
}

/**
 * Optionally exclude renewal orders from the "Bonus after X Orders" count.
 * The milestone itself is a PRO earning method, so this filter only fires
 * when the PRO order-bonuses module is active.
 */
add_filter(
    'spar_nth_order_bonus_order_count',
    'spar_subs_filter_nth_order_count',
    10,
    2
);
function spar_subs_filter_nth_order_count(  $order_count, $user_id  ) {
    if ( !spar_subs_active() || $order_count <= 0 ) {
        return $order_count;
    }
    $settings = spar_subs_get_settings();
    if ( $settings['count_order_bonuses'] ) {
        return $order_count;
    }
    global $wpdb;
    if ( ( function_exists( 'spar_hpos_orders_table_enabled' ) ? spar_hpos_orders_table_enabled() : function_exists( 'wc_get_container' ) ) ) {
        // HPOS: renewal orders carry the _subscription_renewal meta in wc_orders_meta.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Lightweight COUNT against the order tables; no WP API equivalent, evaluated once per milestone check.
        $renewal_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders o\n\t\t\t\t INNER JOIN {$wpdb->prefix}wc_orders_meta m ON m.order_id = o.id AND m.meta_key = '_subscription_renewal'\n\t\t\t\t WHERE o.customer_id = %d AND o.status IN ('wc-completed','wc-processing') AND o.type = 'shop_order'", $user_id ) );
    } else {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Lightweight COUNT against the posts tables; no WP API equivalent, evaluated once per milestone check.
        $renewal_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} p\n\t\t\t\t INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_customer_user' AND pm.meta_value = %d\n\t\t\t\t INNER JOIN {$wpdb->postmeta} pr ON pr.post_id = p.ID AND pr.meta_key = '_subscription_renewal'\n\t\t\t\t WHERE p.post_type = 'shop_order' AND p.post_status IN ('wc-completed','wc-processing')", $user_id ) );
    }
    return max( 0, $order_count - $renewal_count );
}
