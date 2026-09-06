<?php

/**
 * Handle redeem actions
 */
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
add_action( 'wp_loaded', 'spar_handle_redeem_request', 20 );
function spar_handle_redeem_request() {
    if ( !is_user_logged_in() || !isset( $_GET['spar_redeem_id'] ) || !isset( $_GET['spar_nonce'] ) ) {
        return;
    }
    $reward_id = ( isset( $_GET['spar_redeem_id'] ) ? sanitize_text_field( wp_unslash( $_GET['spar_redeem_id'] ) ) : '' );
    $nonce = ( isset( $_GET['spar_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['spar_nonce'] ) ) : '' );
    if ( !wp_verify_nonce( $nonce, 'spar_redeem_reward' ) ) {
        wp_die( esc_html__( 'Security check failed.', 'simple-points-and-rewards' ) );
        return;
    }
    $user_id = get_current_user_id();
    // Ensure the WooCommerce cart/session is available this early in the request
    // (wp_loaded fires before WC bootstraps the cart on its own).
    if ( function_exists( 'sparp_maybe_load_cart_for_rewards' ) ) {
        sparp_maybe_load_cart_for_rewards();
    } elseif ( function_exists( 'wc_load_cart' ) ) {
        try {
            wc_load_cart();
        } catch ( Throwable $e ) {
            // Cart availability is checked before cart operations below.
        }
    }
    // Allow hooking before processing the redemption request
    do_action( 'spar_before_handle_redeem_request', (int) $user_id, $reward_id );
    // Process reward redemption using new settings-based system
    $result = spar_process_reward_redemption( $user_id, $reward_id );
    if ( is_wp_error( $result ) ) {
        wp_die( esc_html( $result->get_error_message() ) );
        return;
    }
    // Handle different return types
    if ( $result === 'product_added' ) {
        // Ensure cart is saved before redirecting
        $cart = spar_get_wc_cart();
        $session = spar_get_wc_session();
        if ( $cart && $session ) {
            spar_save_wc_cart_session( $cart, $session );
        }
        // Product was added to cart, redirect to checkout
        $redirect = ( function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/checkout/' ) );
        $redirect = apply_filters(
            'spar_redeem_product_redirect_url',
            $redirect,
            (int) $user_id,
            $reward_id
        );
        $redirect = esc_url_raw( (string) $redirect );
        wp_safe_redirect( $redirect );
        exit;
    } else {
        // Voucher code was returned, redirect to rewards page with success message
        $rewards_url = wc_get_account_endpoint_url( 'rewards' );
        // Include a nonce to authorize showing the success notice (prevents arbitrary GET from triggering UI effects)
        $redirect_url = add_query_arg( array(
            'redeemed'       => rawurlencode( (string) $result ),
            'redeemed_nonce' => wp_create_nonce( 'spar_redeemed_notice' ),
        ), $rewards_url );
        $redirect_url = apply_filters(
            'spar_redeem_success_redirect',
            $redirect_url,
            (int) $user_id,
            $reward_id,
            (string) $result
        );
        $redirect_url = esc_url_raw( (string) $redirect_url );
        wp_safe_redirect( $redirect_url );
        exit;
    }
}

// If ?apply_coupon= is used, add the coupon to the cart (requires nonce)
add_action( 'template_redirect', 'spar_apply_coupon_from_query' );
function spar_apply_coupon_from_query() {
    if ( !isset( $_GET['apply_coupon'], $_GET['spar_nonce'] ) ) {
        return;
    }
    // Verify nonce to prevent CSRF
    $nonce = ( isset( $_GET['spar_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['spar_nonce'] ) ) : '' );
    if ( !wp_verify_nonce( $nonce, 'spar_apply_coupon' ) ) {
        return;
    }
    $coupon_code = ( isset( $_GET['apply_coupon'] ) ? sanitize_text_field( wp_unslash( $_GET['apply_coupon'] ) ) : '' );
    if ( '' === $coupon_code ) {
        return;
    }
    // Check the coupon exists using WooCommerce helper
    $coupon_id = ( function_exists( 'wc_get_coupon_id_by_code' ) ? wc_get_coupon_id_by_code( $coupon_code ) : 0 );
    if ( !$coupon_id ) {
        return;
    }
    $cart = spar_get_wc_cart();
    if ( $cart && !$cart->has_discount( $coupon_code ) ) {
        /** Allow hooking before auto-apply from query */
        do_action( 'spar_before_apply_coupon_from_query', $coupon_code );
        try {
            $applied = $cart->apply_coupon( $coupon_code );
        } catch ( Throwable $e ) {
            error_log( sprintf(
                '[SPAR] apply_coupon_from_query threw %s for coupon "%s": %s in %s on line %d',
                get_class( $e ),
                $coupon_code,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ) );
            $applied = false;
        }
        if ( $applied ) {
            spar_save_wc_cart_session( $cart );
        }
        do_action( 'spar_after_apply_coupon_from_query', $coupon_code, (bool) $applied );
    }
    // Redirect to remove query args from URL
    wp_safe_redirect( remove_query_arg( array('apply_coupon', 'spar_nonce') ) );
    exit;
}

/**
 * Check if current page is using WooCommerce blocks
 */
function spar_is_block_checkout() {
    if ( !is_checkout() ) {
        return false;
    }
    // Check if the checkout block is present
    if ( has_block( 'woocommerce/checkout' ) ) {
        return true;
    }
    // Fallback check for block theme or block editor
    $post = get_post();
    if ( $post && has_blocks( $post->post_content ) ) {
        $blocks = parse_blocks( $post->post_content );
        foreach ( $blocks as $block ) {
            if ( $block['blockName'] === 'woocommerce/checkout' ) {
                return true;
            }
        }
    }
    return false;
}

// AJAX handler for applying vouchers to cart
add_action( 'wp_ajax_spar_apply_voucher_to_cart', 'spar_handle_apply_voucher_ajax' );
add_action( 'wp_ajax_nopriv_spar_apply_voucher_to_cart', 'spar_handle_apply_voucher_ajax' );
function spar_handle_apply_voucher_ajax() {
    // Verify nonce
    check_ajax_referer( 'spar_apply_voucher', 'nonce', true );
    $voucher_code = ( isset( $_POST['voucher_code'] ) ? sanitize_text_field( wp_unslash( $_POST['voucher_code'] ) ) : '' );
    if ( empty( $voucher_code ) ) {
        wp_send_json_error( esc_html__( 'Invalid voucher code.', 'simple-points-and-rewards' ) );
        return;
    }
    if ( function_exists( 'sparp_maybe_load_cart_for_rewards' ) ) {
        sparp_maybe_load_cart_for_rewards();
    }
    $cart = spar_get_wc_cart();
    $session = spar_get_wc_session();
    if ( !$cart ) {
        wp_send_json_error( esc_html__( 'Unable to access cart. Please refresh and try again.', 'simple-points-and-rewards' ) );
        return;
    }
    // Pre-AJAX hook for listeners
    do_action( 'spar_ajax_before_apply_voucher', $voucher_code );
    // Check if this is a product voucher and add product to cart if needed
    if ( $cart ) {
        $coupon_id = ( function_exists( 'wc_get_coupon_id_by_code' ) ? wc_get_coupon_id_by_code( $voucher_code ) : 0 );
        if ( $coupon_id ) {
            $product_ids_str = get_post_meta( $coupon_id, 'product_ids', true );
            if ( !empty( $product_ids_str ) ) {
                $product_ids = explode( ',', $product_ids_str );
                foreach ( $product_ids as $p_id ) {
                    $p_id = (int) trim( $p_id );
                    if ( $p_id > 0 ) {
                        // Check if in cart
                        $in_cart = false;
                        foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
                            if ( $cart_item['product_id'] == $p_id || $cart_item['variation_id'] == $p_id ) {
                                $in_cart = true;
                                break;
                            }
                        }
                        if ( !$in_cart ) {
                            $cart->add_to_cart( $p_id, 1 );
                        }
                    }
                }
            }
        }
    }
    // If WooCommerce cart exists but is empty, defer application until products are added
    if ( $cart && $cart->is_empty() ) {
        // Store pending voucher in session to auto-apply later
        if ( $session ) {
            // Validate coupon exists before storing
            $coupon = new WC_Coupon($voucher_code);
            if ( !$coupon->get_id() ) {
                wp_send_json_error( esc_html__( 'Invalid or expired voucher.', 'simple-points-and-rewards' ) );
                return;
            }
            $session->set( 'spar_pending_voucher', $voucher_code );
            spar_save_wc_cart_session( $cart, $session );
            /**
             * Fires when a voucher application is deferred due to empty cart.
             *
             * @param string $voucher_code Voucher code stored for later.
             */
            do_action( 'spar_voucher_deferred', $voucher_code );
        }
        wp_send_json_success( [
            'message'  => esc_html__( 'Your voucher will be applied as soon as you add products to your cart.', 'simple-points-and-rewards' ),
            'deferred' => true,
            'cart_url' => wc_get_cart_url(),
        ] );
        return;
    }
    // Check the coupon exists before attempting to apply it
    $coupon = new WC_Coupon($voucher_code);
    if ( !$coupon->get_id() ) {
        wp_send_json_error( esc_html__( 'Invalid or expired voucher.', 'simple-points-and-rewards' ) );
        return;
    }
    // Check if coupon is already applied
    if ( $cart->has_discount( $voucher_code ) ) {
        spar_save_wc_cart_session( $cart, $session );
        wp_send_json_success( [
            'message'         => esc_html__( 'Voucher is already applied to your cart.', 'simple-points-and-rewards' ),
            'already_applied' => true,
        ] );
        return;
    }
    // Fix any WooCommerce Brands meta that was copied from a template coupon as a
    // raw string instead of an array. array_intersect() in class-wc-brands-coupons.php
    // throws a TypeError when these values are non-array, crashing the apply.
    $coupon_id_to_fix = wc_get_coupon_id_by_code( $voucher_code );
    if ( $coupon_id_to_fix ) {
        foreach ( ['product_brands', 'exclude_product_brands'] as $brands_key ) {
            $brands_val = get_post_meta( $coupon_id_to_fix, $brands_key, true );
            if ( !is_array( $brands_val ) && !empty( $brands_val ) ) {
                delete_post_meta( $coupon_id_to_fix, $brands_key );
            }
        }
    }
    // Clear any pre-existing notices so we can cleanly read any error WC adds
    wc_clear_notices();
    // Let WooCommerce apply the coupon. It runs its own full validation internally
    // (minimum spend, usage limits, restrictions, etc.) and queues error notices on failure.
    // Wrap in try/catch: third-party plugins hooked into woocommerce_coupon_is_valid
    // (e.g. WooCommerce Brands) can throw uncaught TypeErrors that would otherwise
    // return a 500 and show a generic "Something went wrong" to the user.
    try {
        $result = $cart->apply_coupon( $voucher_code );
    } catch ( Throwable $e ) {
        error_log( sprintf(
            '[SPAR] apply_coupon threw %s for voucher "%s": %s in %s on line %d',
            get_class( $e ),
            $voucher_code,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ) );
        wc_clear_notices();
        wp_send_json_error( esc_html__( 'This voucher could not be applied. It is not valid for your current cart.', 'simple-points-and-rewards' ) );
        return;
    }
    if ( $result ) {
        spar_save_wc_cart_session( $cart, $session );
        do_action( 'spar_ajax_after_apply_voucher', $voucher_code, true );
        wp_send_json_success( [
            'message'  => esc_html__( 'Voucher applied successfully!', 'simple-points-and-rewards' ),
            'cart_url' => wc_get_cart_url(),
        ] );
    } else {
        // WooCommerce's apply_coupon() calls wc_add_notice() with the specific reason
        // (minimum spend, usage limit, restriction, etc.) when it fails.
        $notices = wc_get_notices( 'error' );
        if ( !empty( $notices ) && isset( $notices[0]['notice'] ) ) {
            $error_message = wp_strip_all_tags( (string) $notices[0]['notice'] );
        } else {
            $error_message = esc_html__( 'This voucher could not be applied. It may have usage restrictions or have already been used.', 'simple-points-and-rewards' );
        }
        wc_clear_notices();
        // Prevent notices bleeding into the page response
        do_action( 'spar_ajax_after_apply_voucher', $voucher_code, false );
        wp_send_json_error( $error_message );
    }
}

/**
 * Attempt to apply a pending voucher stored in session when cart has items
 */
function spar_maybe_apply_pending_voucher() {
    $cart = spar_get_wc_cart();
    $session = spar_get_wc_session();
    if ( !$cart || !$session ) {
        return;
    }
    $pending_code = $session->get( 'spar_pending_voucher' );
    if ( empty( $pending_code ) ) {
        return;
    }
    // Only attempt if the cart has items
    if ( $cart->is_empty() ) {
        return;
    }
    // Validate coupon
    $coupon = new WC_Coupon($pending_code);
    if ( !$coupon->get_id() || !$coupon->is_valid() ) {
        // Clear pending if invalid or expired
        $session->__unset( 'spar_pending_voucher' );
        if ( method_exists( $session, 'save_data' ) ) {
            $session->save_data();
        }
        /**
         * Fires when a pending voucher is discarded as invalid/expired.
         *
         * @param string $pending_code Voucher code that was removed.
         */
        $pending_code = sanitize_text_field( (string) $pending_code );
        do_action( 'spar_voucher_pending_invalid', $pending_code );
        return;
    }
    // Do not re-apply if already applied
    if ( $cart->has_discount( $pending_code ) ) {
        $session->__unset( 'spar_pending_voucher' );
        if ( method_exists( $session, 'save_data' ) ) {
            $session->save_data();
        }
        return;
    }
    // Try to apply
    $result = $cart->apply_coupon( $pending_code );
    if ( $result ) {
        $session->__unset( 'spar_pending_voucher' );
        spar_save_wc_cart_session( $cart, $session );
        // Add a success notice (frontend handles display); avoid duplicates on blocks
        if ( !is_admin() ) {
            wc_add_notice( esc_html__( 'Your voucher was applied automatically.', 'simple-points-and-rewards' ), 'success' );
        }
        /** Announce that a pending voucher was auto-applied */
        $pending_code = sanitize_text_field( (string) $pending_code );
        do_action( 'spar_voucher_auto_applied', $pending_code );
    } else {
        // Leave voucher pending if conditions (e.g. min amount) aren\'t met yet
        // Do not add error notices repeatedly
    }
}

// Apply pending voucher once items are present in the cart
add_action( 'woocommerce_add_to_cart', 'spar_maybe_apply_pending_voucher', 20 );
add_action( 'woocommerce_cart_loaded_from_session', 'spar_maybe_apply_pending_voucher', 20 );
/**
 * ==============================
 * Individual Points Redemption
 * ==============================
 */
/**
 * Resolve redemption rate for a currency.
 * Returns array [ 'points' => float, 'amount' => float ].
 */
function spar_get_redeem_rate_for_currency(  $currency  ) {
    $options = get_option( 'spar_options', [] );
    $base_points = ( isset( $options['redeem_points_per_points'] ) ? (float) $options['redeem_points_per_points'] : 100.0 );
    $base_amount = ( isset( $options['redeem_points_per_amount'] ) ? (float) $options['redeem_points_per_amount'] : 1.0 );
    $rate_points = $base_points;
    $rate_amount = $base_amount;
    $rows = ( isset( $options['redeem_currency_rates'] ) && is_array( $options['redeem_currency_rates'] ) ? $options['redeem_currency_rates'] : [] );
    $currency = strtoupper( (string) $currency );
    if ( $currency && !empty( $rows ) ) {
        foreach ( $rows as $row ) {
            $c = ( isset( $row['currency'] ) ? strtoupper( (string) $row['currency'] ) : '' );
            if ( $c === $currency ) {
                $rp = ( isset( $row['points'] ) ? (float) $row['points'] : 0.0 );
                $ra = ( isset( $row['amount'] ) ? (float) $row['amount'] : 0.0 );
                if ( $rp > 0 && $ra > 0 ) {
                    $rate_points = $rp;
                    $rate_amount = $ra;
                }
                break;
            }
        }
    }
    return [
        'points' => max( 0.0, (float) $rate_points ),
        'amount' => max( 0.0, (float) $rate_amount ),
    ];
}

/**
 * Calculate discount amount from points using a currency rate.
 */
function spar_calculate_redemption_amount(  $points, $currency  ) {
    $rate = spar_get_redeem_rate_for_currency( $currency );
    $rp = (float) $rate['points'];
    $ra = (float) $rate['amount'];
    if ( $points <= 0 || $rp <= 0 || $ra <= 0 ) {
        return 0.0;
    }
    $amount = (float) $points * $ra / $rp;
    // Round down to 2 decimals for monetary safety
    $amount = floor( max( 0.0, $amount ) * 100.0 ) / 100.0;
    return (float) $amount;
}

/**
 * Determine a safe cart-based cap for the discount.
 * Uses the same subtotal/tax/shipping prefs as earning calc for consistency.
 */
function spar_get_redemption_discount_cap_from_cart(  $cart = null  ) {
    if ( null === $cart ) {
        $cart = spar_get_wc_cart();
    }
    if ( !$cart ) {
        return 0.0;
    }
    // Use earn 'order' total helper to mirror store preferences
    if ( function_exists( 'spar_get_points_calculation_total_from_cart' ) ) {
        $total = (float) spar_get_points_calculation_total_from_cart( 'order', $cart );
        $existing_discount = 0.0;
        $session = spar_get_wc_session();
        if ( $session ) {
            $data = $session->get( 'spar_points_redemption' );
            if ( is_array( $data ) ) {
                $existing_discount = ( isset( $data['amount'] ) ? (float) $data['amount'] : 0.0 );
            }
        }
        if ( $existing_discount <= 0 && method_exists( $cart, 'get_fees' ) ) {
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
                        $existing_discount += abs( (float) $amt );
                    }
                }
            }
        }
        if ( $existing_discount > 0 ) {
            $total += (float) $existing_discount;
        }
        $total = max( 0.0, (float) $total );
        // Premium: remove the value of excluded products / categories so points
        // cannot be redeemed against them.
        $total = max( 0.0, $total - (float) spar_get_redemption_excluded_cart_value( $cart ) );
        return $total;
    }
    // Fallback: cart contents total before taxes/shipping
    if ( method_exists( $cart, 'get_cart_contents_total' ) ) {
        $fallback_total = max( 0.0, (float) $cart->get_cart_contents_total() );
        $fallback_total = max( 0.0, $fallback_total - (float) spar_get_redemption_excluded_cart_value( $cart ) );
        return $fallback_total;
    }
    return 0.0;
}

/**
 * Sum the cart value of products/categories that are excluded from points
 * redemption (premium "Advanced Settings" options). This amount is removed from
 * the redemption discount cap so bonuses cannot be spent against those items.
 *
 * The value is measured the same way as the earning/redemption total (mode,
 * taxes) so the deduction stays consistent with the cap it is subtracted from.
 *
 * @param WC_Cart|null $cart Optional cart instance.
 * @return float Non-negative excluded value in store currency.
 */
function spar_get_redemption_excluded_cart_value(  $cart = null  ) {
    // Refinement of the redemption cap is a premium feature.
    if ( function_exists( 'spar_fs' ) && !spar_fs()->can_use_premium_code__premium_only() ) {
        return 0.0;
    }
    if ( null === $cart ) {
        $cart = ( function_exists( 'spar_get_wc_cart' ) ? spar_get_wc_cart() : null );
    }
    if ( !$cart || !method_exists( $cart, 'get_cart' ) ) {
        return 0.0;
    }
    $options = get_option( 'spar_options', array() );
    $exclude_products = ( isset( $options['redeem_exclude_product_ids'] ) && is_array( $options['redeem_exclude_product_ids'] ) ? array_values( array_filter( array_map( 'absint', $options['redeem_exclude_product_ids'] ) ) ) : array() );
    $exclude_categories = ( isset( $options['redeem_exclude_category_ids'] ) && is_array( $options['redeem_exclude_category_ids'] ) ? array_values( array_filter( array_map( 'absint', $options['redeem_exclude_category_ids'] ) ) ) : array() );
    if ( empty( $exclude_products ) && empty( $exclude_categories ) ) {
        return 0.0;
    }
    // Mirror how the cap measures value (before/after discounts, taxes).
    $prefs = ( function_exists( 'spar_get_points_calculation_preferences' ) ? spar_get_points_calculation_preferences( 'order' ) : array(
        'mode'          => 'subtotal_after_discount',
        'include_taxes' => false,
    ) );
    $use_before = isset( $prefs['mode'] ) && 'total_before_discounts' === $prefs['mode'];
    $include_taxes = !empty( $prefs['include_taxes'] );
    $cart_contents = $cart->get_cart();
    if ( !is_array( $cart_contents ) ) {
        return 0.0;
    }
    $excluded_value = 0.0;
    foreach ( $cart_contents as $cart_item ) {
        if ( !is_array( $cart_item ) ) {
            continue;
        }
        $product_id = ( isset( $cart_item['product_id'] ) ? absint( $cart_item['product_id'] ) : 0 );
        $variation_id = ( isset( $cart_item['variation_id'] ) ? absint( $cart_item['variation_id'] ) : 0 );
        if ( $product_id <= 0 ) {
            continue;
        }
        $is_excluded = false;
        if ( !empty( $exclude_products ) ) {
            if ( in_array( $product_id, $exclude_products, true ) || $variation_id > 0 && in_array( $variation_id, $exclude_products, true ) ) {
                $is_excluded = true;
            }
        }
        if ( !$is_excluded && !empty( $exclude_categories ) && function_exists( 'has_term' ) ) {
            if ( has_term( $exclude_categories, 'product_cat', $product_id ) ) {
                $is_excluded = true;
            }
        }
        if ( !$is_excluded ) {
            continue;
        }
        if ( $use_before ) {
            $excluded_value += ( isset( $cart_item['line_subtotal'] ) ? (float) $cart_item['line_subtotal'] : 0.0 );
            if ( $include_taxes ) {
                $excluded_value += ( isset( $cart_item['line_subtotal_tax'] ) ? (float) $cart_item['line_subtotal_tax'] : 0.0 );
            }
        } else {
            $excluded_value += ( isset( $cart_item['line_total'] ) ? (float) $cart_item['line_total'] : 0.0 );
            if ( $include_taxes ) {
                $excluded_value += ( isset( $cart_item['line_tax'] ) ? (float) $cart_item['line_tax'] : 0.0 );
            }
        }
    }
    return max( 0.0, (float) $excluded_value );
}

/**
 * Configured minimum cart total required before points can be redeemed.
 *
 * Premium feature. Returns 0.0 when no minimum is set or on the free plan.
 *
 * @return float Non-negative minimum cart total in store currency.
 */
function spar_get_redeem_min_cart_total() {
    if ( function_exists( 'spar_fs' ) && !spar_fs()->can_use_premium_code__premium_only() ) {
        return 0.0;
    }
    $options = get_option( 'spar_options', array() );
    $min = ( isset( $options['redeem_min_cart_total'] ) ? (float) $options['redeem_min_cart_total'] : 0.0 );
    return max( 0.0, $min );
}

/**
 * Whether the cart qualifies for points redemption based on the configured
 * minimum cart total.
 *
 * Measured against the cart subtotal ( WC_Cart::get_subtotal() ) — the value of
 * the goods in the cart. This is deliberately NOT the redemption discount cap:
 * the cap adds back any already-applied points discount, which would inflate the
 * figure and let a below-minimum cart pass once a discount is on it. A
 * points-redemption fee never changes the subtotal, so the gate stays stable
 * whether or not a discount is currently applied.
 *
 * @param WC_Cart|null $cart Optional cart instance.
 * @return bool True when redemption is allowed (or no minimum is configured).
 */
function spar_cart_meets_redeem_min_total(  $cart = null  ) {
    $min = spar_get_redeem_min_cart_total();
    if ( $min <= 0.0 ) {
        return true;
    }
    if ( null === $cart ) {
        $cart = ( function_exists( 'spar_get_wc_cart' ) ? spar_get_wc_cart() : null );
    }
    if ( !$cart || !method_exists( $cart, 'get_subtotal' ) ) {
        return false;
    }
    $subtotal = (float) $cart->get_subtotal();
    /**
     * Filter the cart total measured against the minimum redemption threshold.
     *
     * @param float   $subtotal Cart subtotal used for the comparison.
     * @param WC_Cart $cart     Cart instance.
     * @param float   $min      Configured minimum cart total.
     */
    $measured = (float) apply_filters(
        'spar_redeem_min_cart_measured_total',
        $subtotal,
        $cart,
        $min
    );
    return $measured >= $min;
}

/**
 * Apply the redemption as a negative fee during cart fee calculation.
 */
function spar_apply_points_redemption_fee(  $cart  ) {
    if ( is_admin() && !defined( 'DOING_AJAX' ) ) {
        return;
    }
    $session = spar_get_wc_session();
    if ( !$session ) {
        return;
    }
    $data = $session->get( 'spar_points_redemption' );
    if ( empty( $data ) || !is_array( $data ) ) {
        return;
    }
    $points = ( isset( $data['points'] ) ? (int) $data['points'] : 0 );
    $currency = ( function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : (( defined( 'WC_VERSION' ) ? get_option( 'woocommerce_currency' ) : '' )) );
    if ( $points <= 0 ) {
        return;
    }
    // Premium: if the cart has fallen below the minimum total required to redeem
    // (e.g. products removed after the discount was applied), drop the redemption
    // entirely. Clearing the session prevents it silently re-applying when the
    // cart grows again and stops points being deducted at checkout without a
    // matching discount.
    if ( function_exists( 'spar_cart_meets_redeem_min_total' ) && !spar_cart_meets_redeem_min_total( $cart ) ) {
        $session->__unset( 'spar_points_redemption' );
        return;
    }
    // Ensure the customer still holds the points being redeemed. If they were
    // spent elsewhere (e.g. on a reward or prize wheel) after the discount was
    // applied, drop the redemption so an unfunded discount is never added to
    // the order. Clearing the session also stops it silently re-applying.
    $fee_user_id = get_current_user_id();
    if ( !$fee_user_id || (int) spar_get_user_points( $fee_user_id ) < $points ) {
        $session->__unset( 'spar_points_redemption' );
        return;
    }
    $amount = spar_calculate_redemption_amount( $points, $currency );
    if ( $amount <= 0 ) {
        return;
    }
    // Cap discount so totals cannot go negative
    $cap = spar_get_redemption_discount_cap_from_cart( $cart );
    $discount = min( (float) $amount, (float) $cap );
    if ( $discount <= 0 ) {
        return;
    }
    // Add as non-taxable fee (discount) with points count in label, e.g., "Points Redemption (-100)"
    $points_str = '-' . spar_format_points_value( absint( $points ) );
    $label = sprintf( esc_html__( 'Points Redemption (%s)', 'simple-points-and-rewards' ), $points_str );
    $label = sanitize_text_field( $label );
    $cart->add_fee( $label, -abs( (float) $discount ), false );
}

add_action(
    'woocommerce_cart_calculate_fees',
    'spar_apply_points_redemption_fee',
    25,
    1
);
// AJAX: apply points redemption
add_action( 'wp_ajax_spar_apply_points_redemption', 'spar_ajax_apply_points_redemption' );
function spar_ajax_apply_points_redemption() {
    check_ajax_referer( 'spar_points_redeem', 'nonce', true );
    if ( !is_user_logged_in() ) {
        wp_send_json_error( esc_html__( 'Please log in to redeem points.', 'simple-points-and-rewards' ) );
    }
    $options = get_option( 'spar_options', [] );
    if ( empty( $options['redeem_individual_enabled'] ) ) {
        wp_send_json_error( esc_html__( 'Points redemption is disabled.', 'simple-points-and-rewards' ) );
    }
    $redeem_points_min = 0;
    $redeem_points_max = 0;
    if ( function_exists( 'spar_fs' ) && spar_fs()->can_use_premium_code__premium_only() ) {
        $redeem_points_min = ( isset( $options['redeem_points_min'] ) ? max( 0, (int) $options['redeem_points_min'] ) : 0 );
        $redeem_points_max = ( isset( $options['redeem_points_max'] ) ? max( 0, (int) $options['redeem_points_max'] ) : 0 );
    }
    $points = ( isset( $_POST['points'] ) ? (int) wp_unslash( $_POST['points'] ) : 0 );
    $points = max( 0, (int) sanitize_text_field( (string) $points ) );
    if ( $points <= 0 ) {
        wp_send_json_error( esc_html__( 'Enter a valid number of points.', 'simple-points-and-rewards' ) );
    }
    $user_id = get_current_user_id();
    $balance = (int) spar_get_user_points( $user_id );
    if ( $points > $balance ) {
        $points = $balance;
        // clamp to balance
    }
    if ( $redeem_points_max > 0 && $points > $redeem_points_max ) {
        $points = (int) $redeem_points_max;
    }
    if ( $points <= 0 ) {
        wp_send_json_error( esc_html__( 'Enter a valid number of points.', 'simple-points-and-rewards' ) );
    }
    if ( $redeem_points_min > 0 ) {
        if ( $balance < $redeem_points_min ) {
            wp_send_json_error( sprintf( esc_html__( 'You need at least %s points to redeem.', 'simple-points-and-rewards' ), esc_html( spar_format_points_value( (int) $redeem_points_min ) ) ) );
        }
        if ( $points < $redeem_points_min ) {
            $points = (int) $redeem_points_min;
        }
    }
    if ( function_exists( 'sparp_maybe_load_cart_for_rewards' ) ) {
        sparp_maybe_load_cart_for_rewards();
    }
    $session = spar_get_wc_session();
    $cart = spar_get_wc_cart();
    if ( !$session || !$cart ) {
        wp_send_json_error( esc_html__( 'Cart is not available.', 'simple-points-and-rewards' ) );
    }
    // Premium: enforce the minimum cart total required to redeem points.
    if ( !spar_cart_meets_redeem_min_total( $cart ) ) {
        wp_send_json_error( sprintf( 
            /* translators: %s: minimum cart total formatted as a price. */
            esc_html__( 'Points can only be used on orders of %s or more.', 'simple-points-and-rewards' ),
            wp_strip_all_tags( wc_price( spar_get_redeem_min_cart_total() ) )
         ) );
    }
    // Clear any existing redemption so totals/cap are computed consistently.
    $current_redemption = $session->get( 'spar_points_redemption' );
    if ( is_array( $current_redemption ) && !empty( $current_redemption ) ) {
        $session->__unset( 'spar_points_redemption' );
        $cart->calculate_totals();
    }
    $currency = ( function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : (( defined( 'WC_VERSION' ) ? get_option( 'woocommerce_currency' ) : '' )) );
    $amount = spar_calculate_redemption_amount( $points, $currency );
    if ( $amount <= 0 ) {
        wp_send_json_error( esc_html__( 'Redemption value is too low.', 'simple-points-and-rewards' ) );
    }
    $cap = spar_get_redemption_discount_cap_from_cart( $cart );
    $discount = min( (float) $amount, (float) $cap );
    if ( $discount <= 0 ) {
        wp_send_json_error( esc_html__( 'Nothing to redeem for this cart.', 'simple-points-and-rewards' ) );
    }
    // Persist in session for fee hook
    $session->set( 'spar_points_redemption', [
        'points'   => (int) $points,
        'amount'   => (float) $discount,
        'currency' => (string) $currency,
    ] );
    // Trigger totals recalculation
    $cart->calculate_totals();
    wp_send_json_success( [
        'points'   => (int) $points,
        'amount'   => (float) $discount,
        'currency' => (string) $currency,
    ] );
    /**
     * Fires after points redemption is applied to the cart.
     *
     * @param int    $user_id  User ID.
     * @param int    $points   Points redeemed.
     * @param float  $discount Discount amount applied.
     * @param float  $cap      Discount cap from cart.
     * @param float  $amount   Raw calculated amount from points.
     * @param string $currency Store currency.
     */
    do_action(
        'spar_points_redemption_applied',
        (int) $user_id,
        (int) $points,
        (float) $discount,
        (float) $cap,
        (float) $amount,
        (string) $currency
    );
}

// AJAX: remove points redemption
add_action( 'wp_ajax_spar_remove_points_redemption', 'spar_ajax_remove_points_redemption' );
function spar_ajax_remove_points_redemption() {
    check_ajax_referer( 'spar_points_redeem', 'nonce', true );
    if ( function_exists( 'sparp_maybe_load_cart_for_rewards' ) ) {
        sparp_maybe_load_cart_for_rewards();
    }
    $session = spar_get_wc_session();
    if ( !$session ) {
        wp_send_json_error( esc_html__( 'Cart is not available.', 'simple-points-and-rewards' ) );
    }
    $session->__unset( 'spar_points_redemption' );
    $cart = spar_get_wc_cart();
    if ( $cart ) {
        $cart->calculate_totals();
    }
    wp_send_json_success();
}

/**
 * When an order is placed, persist redeemed points meta and deduct points.
 */
// Internal: capture and deduct points for an order; idempotent via order meta flag
function spar_capture_points_redemption_for_order(  $order  ) {
    if ( !$order ) {
        return;
    }
    $order_id = ( is_object( $order ) && method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0 );
    // If already processed, bail
    if ( (int) $order->get_meta( '_spar_points_redeemed_deducted' ) === 1 ) {
        return;
    }
    $points = 0;
    $amount = 0.0;
    $currency = '';
    // Prefer data from session if available (user just checked out)
    $session = spar_get_wc_session();
    if ( $session ) {
        $data = $session->get( 'spar_points_redemption' );
        if ( is_array( $data ) ) {
            $points = ( isset( $data['points'] ) ? (int) $data['points'] : 0 );
            $amount = ( isset( $data['amount'] ) ? (float) $data['amount'] : 0.0 );
            $currency = ( isset( $data['currency'] ) ? (string) $data['currency'] : '' );
        }
    }
    // Also look for an applied fee on the order to confirm amount
    $applied_discount = 0.0;
    $fees = ( is_callable( [$order, 'get_items'] ) ? $order->get_items( 'fee' ) : [] );
    if ( !empty( $fees ) ) {
        foreach ( $fees as $fee ) {
            $name = ( method_exists( $fee, 'get_name' ) ? (string) $fee->get_name() : '' );
            $total = ( method_exists( $fee, 'get_total' ) ? (float) $fee->get_total() : 0.0 );
            if ( $name && stripos( $name, 'points redemption' ) !== false && $total < 0 ) {
                $applied_discount += (float) abs( $total );
            }
        }
    }
    if ( $applied_discount > 0 ) {
        $amount = (float) $applied_discount;
    }
    // Nothing to do
    if ( $points <= 0 || $amount <= 0.0 ) {
        // Still clear the session if present
        if ( $session ) {
            $session->__unset( 'spar_points_redemption' );
        }
        return;
    }
    $user_id = ( method_exists( $order, 'get_user_id' ) ? (int) $order->get_user_id() : 0 );
    if ( !$user_id ) {
        if ( $session ) {
            $session->__unset( 'spar_points_redemption' );
        }
        return;
    }
    // Serialise with the other checkout hooks (order-created, legacy
    // processed, thankyou fallback) and re-check the deducted flag fresh
    // under the lock so parallel requests cannot deduct twice.
    $lock_key = 'order_redeem_' . $order_id;
    if ( !spar_acquire_db_lock( $lock_key ) ) {
        return;
        // Another request is already capturing this order.
    }
    // Re-check on a separate fresh instance: forcing a meta re-read on the
    // $order object we were handed would discard unsaved meta other plugins
    // may have staged on it during checkout.
    $deducted_flag = (int) $order->get_meta( '_spar_points_redeemed_deducted' );
    if ( $order_id > 0 ) {
        $fresh_order = wc_get_order( $order_id );
        if ( $fresh_order ) {
            $fresh_order->read_meta_data( true );
            $deducted_flag = (int) $fresh_order->get_meta( '_spar_points_redeemed_deducted' );
        }
    }
    if ( 1 === $deducted_flag ) {
        spar_release_db_lock( $lock_key );
        if ( $session ) {
            $session->__unset( 'spar_points_redemption' );
        }
        return;
    }
    // Deduct once. Refuse (rather than clamp) when the balance no longer covers
    // the points — e.g. they were spent elsewhere between applying at the cart
    // and completing checkout — so a discount is never recorded as paid with
    // points the customer does not have. The fee is validated against the live
    // balance in spar_apply_points_redemption_fee(); this is a defence-in-depth
    // net for that path.
    if ( !$order->get_meta( '_spar_points_redeemed_deducted' ) ) {
        $label = sprintf( esc_html__( 'Points Redeemed on Checkout: #%d', 'simple-points-and-rewards' ), (int) $order_id );
        $label = sanitize_text_field( $label );
        $deduction = spar_update_user_points(
            (int) $user_id,
            (int) $points,
            'remove',
            $label,
            'redeem',
            array(),
            array(
                'require_sufficient' => true,
            )
        );
        if ( is_array( $deduction ) && isset( $deduction['status'] ) && 'blocked' === $deduction['status'] ) {
            // Insufficient balance: record nothing and flag the order for the
            // merchant instead of marking an unfunded discount as paid.
            if ( is_callable( array($order, 'add_order_note') ) ) {
                $order->add_order_note( sprintf( 
                    /* translators: %s: points amount. */
                    esc_html__( 'Points redemption of %s could not be captured because the customer no longer had enough points, so the discount was not funded. Please review this order.', 'simple-points-and-rewards' ),
                    spar_format_points_value( (int) $points )
                 ) );
            }
            $order->save();
            spar_release_db_lock( $lock_key );
            if ( $session ) {
                $session->__unset( 'spar_points_redemption' );
            }
            return;
        }
        // Record redemption meta only after a successful deduction so refunds
        // never credit back points that were not actually taken.
        $order->update_meta_data( '_spar_points_redeemed', (int) $points );
        // Also store public-facing meta for easier integrations/reporting.
        $order->update_meta_data( 'points_redeemed', (int) $points );
        $order->update_meta_data( '_spar_points_redeemed_value', (float) $amount );
        if ( $currency ) {
            $order->update_meta_data( '_spar_points_redeemed_currency', (string) $currency );
        }
        $order->update_meta_data( '_spar_points_redeemed_deducted', 1 );
    }
    $order->save();
    spar_release_db_lock( $lock_key );
    // Clear session after processing
    if ( $session ) {
        $session->__unset( 'spar_points_redemption' );
    }
}

// Primary hook (classic + blocks): on order created during checkout
add_action(
    'woocommerce_checkout_order_created',
    function ( $order ) {
        spar_capture_points_redemption_for_order( $order );
    },
    20,
    1
);
// Legacy/back-compat hook
add_action(
    'woocommerce_checkout_order_processed',
    function ( $order_id, $posted_data, $order ) {
        spar_capture_points_redemption_for_order( $order );
    },
    20,
    3
);
// Fallback to ensure session cleared and deduction applied if still pending
add_action(
    'woocommerce_thankyou',
    function ( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( $order ) {
            spar_capture_points_redemption_for_order( $order );
        }
        $session = spar_get_wc_session();
        if ( $session ) {
            $session->__unset( 'spar_points_redemption' );
        }
    },
    20,
    1
);
/**
 * Refund redeemed points on cancellation/failed, or proportionally on partial refunds.
 */
add_action( 'woocommerce_order_status_cancelled', 'spar_refund_redeemed_points_for_order' );
add_action( 'woocommerce_order_status_failed', 'spar_refund_redeemed_points_for_order' );
add_action( 'woocommerce_order_refunded', 'spar_refund_redeemed_points_for_order' );
add_action( 'woocommerce_order_fully_refunded', 'spar_refund_redeemed_points_for_order' );
function spar_refund_redeemed_points_for_order(  $order_id  ) {
    $order = wc_get_order( $order_id );
    if ( !$order ) {
        return;
    }
    $user_id = $order->get_user_id();
    if ( !$user_id ) {
        return;
    }
    $redeemed_points = (int) $order->get_meta( '_spar_points_redeemed' );
    if ( $redeemed_points <= 0 ) {
        return;
    }
    $already_refunded = (int) $order->get_meta( '_spar_points_redeemed_refunded' );
    $remaining = max( 0, (int) $redeemed_points - (int) $already_refunded );
    if ( $remaining <= 0 ) {
        return;
    }
    // If fully refunded/cancelled/failed, refund all remaining
    $status = $order->get_status();
    $full_statuses = ['refunded', 'cancelled', 'failed'];
    if ( in_array( $status, $full_statuses, true ) ) {
        $to_refund = (int) $remaining;
    } else {
        // Partial refund event: determine proportion by refunded amount vs total
        $order_total = (float) $order->get_total();
        $total_refunded = (float) (( method_exists( $order, 'get_total_refunded' ) ? $order->get_total_refunded() : 0 ));
        if ( $order_total <= 0 || $total_refunded <= 0 ) {
            return;
        }
        $ratio = max( 0.0, min( 1.0, $total_refunded / $order_total ) );
        $target_points = (int) floor( $redeemed_points * $ratio );
        $to_refund = max( 0, (int) $target_points - (int) $already_refunded );
        if ( $to_refund <= 0 ) {
            return;
        }
    }
    if ( $to_refund > 0 ) {
        $note = sprintf( esc_html__( 'Refund of Redeemed Points for Order: #%d', 'simple-points-and-rewards' ), (int) $order_id );
        $note = sanitize_text_field( $note );
        spar_update_user_points(
            (int) $user_id,
            (int) $to_refund,
            'add',
            $note,
            'redeem_refund'
        );
        $order->update_meta_data( '_spar_points_redeemed_refunded', (int) ($already_refunded + $to_refund) );
        $order->save();
    }
}
