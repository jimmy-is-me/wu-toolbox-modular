<?php

/**
 * Redeem functions for settings-based rewards
 */
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
if ( !function_exists( 'spar_render_bundle_image_carousel' ) ) {
    /**
     * Build the bundle product image carousel markup for the rewards dashboard.
     *
     * @param WC_Product[] $products Bundle products.
     * @return string Carousel HTML (empty string when there is nothing to show).
     */
    function spar_render_bundle_image_carousel(  $products  ) {
        $products = ( is_array( $products ) ? array_values( array_filter( $products ) ) : array() );
        if ( empty( $products ) ) {
            return '';
        }
        // A single product needs no carousel chrome.
        if ( 1 === count( $products ) ) {
            return '<div class="spar-bundle-carousel spar-bundle-carousel--single">' . '<div class="spar-bundle-carousel-slide is-active">' . $products[0]->get_image( 'thumbnail', array(
                'style' => 'max-height: 50px; width: auto; margin: 0 auto; border-radius: 5px;',
            ) ) . '</div></div>';
        }
        $slides = '';
        $dots = '';
        foreach ( $products as $i => $product ) {
            $active = ( 0 === $i ? ' is-active' : '' );
            $slides .= '<div class="spar-bundle-carousel-slide' . esc_attr( $active ) . '">' . $product->get_image( 'thumbnail', array(
                'style' => 'max-height: 50px; width: auto; margin: 0 auto; border-radius: 5px;',
            ) ) . '</div>';
            $dots .= '<span class="spar-bundle-carousel-dot' . esc_attr( $active ) . '" data-slide="' . esc_attr( $i ) . '"></span>';
        }
        return '<div class="spar-bundle-carousel" data-spar-bundle-carousel>' . '<div class="spar-bundle-carousel-track">' . $slides . '</div>' . '<div class="spar-bundle-carousel-dots">' . $dots . '</div>' . '</div>';
    }

}
/**
 * Process reward redemption
 */
function spar_process_reward_redemption(  $user_id, $reward_id  ) {
    // Sanitize incoming reward identifier
    $reward_id = ( is_string( $reward_id ) ? sanitize_text_field( $reward_id ) : (string) $reward_id );
    // Allow developers to hook before processing a redemption
    do_action( 'spar_before_reward_redemption', (int) $user_id, $reward_id );
    // Get the reward details
    $reward = spar_get_reward_by_id( $reward_id );
    // Let developers adjust the reward object before proceeding (e.g., dynamic cost)
    $reward = apply_filters(
        'spar_reward_object',
        $reward,
        $reward_id,
        (int) $user_id
    );
    if ( !$reward ) {
        return new WP_Error('invalid_reward', esc_html__( 'Invalid reward.', 'simple-points-and-rewards' ));
    }
    // Check if user has enough points (with filterable cost)
    $user_points = spar_get_user_points( $user_id );
    $cost_points = ( isset( $reward['points'] ) ? (int) $reward['points'] : 0 );
    $cost_points = (int) apply_filters(
        'spar_reward_cost_points',
        $cost_points,
        $reward,
        (int) $user_id
    );
    // Business rules can veto redemption entirely
    if ( false === apply_filters(
        'spar_can_redeem_reward',
        true,
        (int) $user_id,
        $reward
    ) ) {
        return new WP_Error('redeem_blocked', esc_html__( 'You cannot redeem this reward at this time.', 'simple-points-and-rewards' ));
    }
    if ( $user_points < $cost_points ) {
        return new WP_Error('insufficient_points', esc_html__( 'Insufficient points.', 'simple-points-and-rewards' ));
    }
    // Deduct points. The deduction is atomic and refuses (rather than clamps)
    // when the balance no longer covers the cost, so a double-click or two
    // parallel requests cannot both redeem against the same points.
    $deduction = spar_update_user_points(
        $user_id,
        $cost_points,
        'remove',
        /* translators: %s: Reward name */
        sprintf( esc_html__( 'Redeemed: %s', 'simple-points-and-rewards' ), $reward['name'] ),
        'redeem',
        array(),
        array(
            'require_sufficient' => true,
        )
    );
    if ( is_array( $deduction ) && isset( $deduction['status'] ) && 'blocked' === $deduction['status'] ) {
        return new WP_Error('insufficient_points', esc_html__( 'Insufficient points.', 'simple-points-and-rewards' ));
    }
    // Process based on reward type
    if ( $reward['type'] === 'voucher' ) {
        $voucher_code = spar_create_reward_voucher( $user_id, $reward );
        if ( is_wp_error( $voucher_code ) ) {
            // Refund points if voucher creation failed
            spar_update_user_points(
                $user_id,
                $cost_points,
                'add',
                /* translators: %s: Reward name */
                sprintf( esc_html__( 'Refund for failed redemption: %s', 'simple-points-and-rewards' ), $reward['name'] ),
                'redeem_refund'
            );
            // Notify listeners that a refund occurred due to failure
            do_action(
                'spar_reward_redemption_refunded',
                (int) $user_id,
                $reward,
                $voucher_code
            );
            return $voucher_code;
        }
        // Fire a standardized action for claimed vouchers so email and premium hooks can respond
        $voucher_value = ( isset( $reward['voucher_amount'] ) ? $reward['voucher_amount'] : '' );
        // Sanitize code for action listeners (WooCommerce will validate when applied)
        $voucher_code_safe = sanitize_text_field( (string) $voucher_code );
        do_action(
            'spar_voucher_claimed',
            $user_id,
            $voucher_code_safe,
            'voucher',
            $voucher_value
        );
        // Post-success hook
        do_action(
            'spar_after_reward_redemption',
            (int) $user_id,
            $reward,
            $voucher_code_safe
        );
        return $voucher_code;
    } elseif ( $reward['type'] === 'product' ) {
        // Create a 100% discount voucher for this product
        $voucher_reward = $reward;
        $voucher_reward['discount_type'] = 'percent';
        $voucher_reward['voucher_amount'] = 100;
        $voucher_reward['product_ids'] = array($reward['product_id']);
        $voucher_code = spar_create_reward_voucher( $user_id, $voucher_reward );
        if ( is_wp_error( $voucher_code ) ) {
            // Refund points if voucher creation failed
            spar_update_user_points(
                $user_id,
                $cost_points,
                'add',
                /* translators: %s: Reward name */
                sprintf( esc_html__( 'Refund for failed redemption: %s', 'simple-points-and-rewards' ), $reward['name'] ),
                'redeem_refund'
            );
            do_action(
                'spar_reward_redemption_refunded',
                (int) $user_id,
                $reward,
                $voucher_code
            );
            return $voucher_code;
        }
        // Ensure cart is initialised (may not be bootstrapped yet if called from wp_loaded).
        if ( function_exists( 'sparp_maybe_load_cart_for_rewards' ) ) {
            sparp_maybe_load_cart_for_rewards();
        } elseif ( function_exists( 'wc_load_cart' ) ) {
            try {
                wc_load_cart();
            } catch ( Throwable $e ) {
                // Cart availability is checked before cart operations below.
            }
        }
        $cart = ( function_exists( 'spar_get_wc_cart' ) ? spar_get_wc_cart() : null );
        if ( !$cart && function_exists( 'WC' ) ) {
            $woocommerce = WC();
            $cart = ( $woocommerce && !empty( $woocommerce->cart ) ? $woocommerce->cart : null );
        }
        // Add product to cart and apply coupon
        if ( $cart ) {
            $cart->add_to_cart( $reward['product_id'], 1 );
            $cart->apply_coupon( $voucher_code );
            // Post-success hook
            do_action(
                'spar_after_reward_redemption',
                (int) $user_id,
                $reward,
                'product_added'
            );
            return 'product_added';
        } else {
            // Refund points if cart not available
            spar_update_user_points(
                $user_id,
                $cost_points,
                'add',
                /* translators: %s: Reward name */
                sprintf( esc_html__( 'Refund for failed redemption: %s', 'simple-points-and-rewards' ), $reward['name'] ),
                'redeem_refund'
            );
            do_action(
                'spar_reward_redemption_refunded',
                (int) $user_id,
                $reward,
                new WP_Error('cart_unavailable')
            );
            return new WP_Error('cart_unavailable', esc_html__( 'Shopping cart not available.', 'simple-points-and-rewards' ));
        }
    } elseif ( $reward['type'] === 'product_bundle' ) {
    } elseif ( $reward['type'] === 'custom' ) {
        /**
         * Custom reward claimed.
         *
         * Fires immediately after points are deducted for a custom reward.
         * Developers can perform arbitrary side effects here (grant access, mark achievement, etc).
         * IMPORTANT: If you need to communicate failure and refund points, you must add logic in your callback.
         *
         * @param int    $user_id         User ID who claimed the reward.
         * @param string $developer_id    Developer-defined identifier (reward['developer_id'] or empty string).
         * @param array  $reward          Full reward definition array as stored in settings.
         * @param int    $cost_points     Points spent.
         */
        $developer_id = ( isset( $reward['developer_id'] ) ? sanitize_key( $reward['developer_id'] ) : '' );
        do_action(
            'spar_custom_reward_claimed',
            (int) $user_id,
            (string) $developer_id,
            $reward,
            (int) $cost_points
        );
        // Standard post-success hook for consistency
        do_action(
            'spar_after_reward_redemption',
            (int) $user_id,
            $reward,
            'custom_claimed'
        );
        return 'custom_claimed';
    }
    return new WP_Error('unknown_type', esc_html__( 'Unknown reward type.', 'simple-points-and-rewards' ));
}

/**
 * Create voucher for reward redemption
 */
function spar_create_reward_voucher(  $user_id, $reward  ) {
    if ( !class_exists( 'WooCommerce' ) ) {
        return new WP_Error('woocommerce_required', esc_html__( 'WooCommerce is required for vouchers.', 'simple-points-and-rewards' ));
    }
    $user = get_user_by( 'ID', $user_id );
    if ( !$user ) {
        return new WP_Error('invalid_user', esc_html__( 'Invalid user.', 'simple-points-and-rewards' ));
    }
    // Generate unique coupon code
    $coupon_code = 'voucher-' . strtolower( wp_generate_password( 8, false, false ) );
    // Create coupon post
    $coupon_id = wp_insert_post( array(
        'post_title'   => $coupon_code,
        'post_content' => '',
        'post_status'  => 'publish',
        'post_author'  => 1,
        'post_type'    => 'shop_coupon',
    ) );
    if ( is_wp_error( $coupon_id ) ) {
        return $coupon_id;
    }
    // Set coupon meta via filterable map
    $free_shipping_enabled = !empty( $reward['free_shipping'] );
    // Limit usage to the number of free items for product rewards: 1 for a single
    // free product, or the bundle product count for a free product bundle.
    $reward_type = ( isset( $reward['type'] ) ? $reward['type'] : '' );
    if ( 'product' === $reward_type ) {
        $limit_usage = 1;
    } else {
        $limit_usage = '';
    }
    $coupon_meta = array(
        'discount_type'          => $reward['discount_type'] ?? 'fixed_cart',
        'coupon_amount'          => $reward['voucher_amount'],
        'individual_use'         => 'yes',
        'product_ids'            => ( isset( $reward['product_ids'] ) ? implode( ',', (array) $reward['product_ids'] ) : '' ),
        'usage_limit'            => 1,
        'usage_limit_per_user'   => 1,
        'limit_usage_to_x_items' => $limit_usage,
        'usage_count'            => 0,
        'expiry_date'            => '',
        'apply_before_tax'       => 'yes',
        'free_shipping'          => ( $free_shipping_enabled ? 'yes' : 'no' ),
        'customer_email'         => array($user->user_email),
    );
    // Copy settings from template coupon if one is specified on this reward.
    // Template values override the defaults above, except for a small set of
    // keys that the reward must always control (discount type/amount, customer
    // email, usage count).
    $template_coupon_id = absint( $reward['template_coupon_id'] ?? 0 );
    if ( $template_coupon_id > 0 && 'shop_coupon' === get_post_type( $template_coupon_id ) ) {
        $template_coupon = new WC_Coupon($template_coupon_id);
        if ( $template_coupon->get_id() ) {
            $template_meta = get_post_meta( $template_coupon_id );
            // Keys that must always come from the reward settings, never from the template.
            $reward_only_meta = [
                'discount_type',
                'coupon_amount',
                'customer_email',
                'usage_count',
                'date_expires',
                'expiry_date'
            ];
            // Internal / system meta that should never be copied.
            $system_meta = [
                '_edit_last',
                '_edit_lock',
                '_spar_reward_voucher',
                '_spar_reward_id',
                '_spar_user_id',
                'spar_user_id',
                '_spar_referral_coupon',
                '_spar_referrer_code',
                '_spar_referrer_id',
                '_spar_referral_expires',
                '_spar_premium_voucher',
                // WooCommerce Brands stores these as arrays; copying them as raw
                // post meta can produce a string value that causes array_intersect()
                // to throw a TypeError in class-wc-brands-coupons.php.
                'product_brands',
                'exclude_product_brands',
            ];
            foreach ( $template_meta as $meta_key => $meta_values ) {
                // Skip keys the reward must control and internal keys.
                if ( in_array( $meta_key, $reward_only_meta, true ) || in_array( $meta_key, $system_meta, true ) ) {
                    continue;
                }
                // Only copy non-empty meta values so blank template fields
                // don't wipe out the reward defaults.
                if ( isset( $meta_values[0] ) && $meta_values[0] !== '' ) {
                    // get_post_meta( $id ) with no key returns the raw, still-serialized
                    // meta values. Unserialize them so array-based restrictions (e.g.
                    // product_categories / exclude_product_categories, product/exclude
                    // product IDs arrays) are stored as real arrays. Otherwise
                    // update_post_meta() re-serializes the serialized string and
                    // WooCommerce reads back a string instead of an array, silently
                    // dropping the restriction.
                    $coupon_meta[$meta_key] = maybe_unserialize( $meta_values[0] );
                }
            }
        }
    }
    $coupon_meta = apply_filters(
        'spar_reward_voucher_meta',
        $coupon_meta,
        $reward,
        (int) $user_id,
        (int) $coupon_id,
        (string) $coupon_code
    );
    // Sanitize meta defensively based on expected types
    if ( is_array( $coupon_meta ) ) {
        foreach ( $coupon_meta as $meta_key => $meta_value ) {
            switch ( $meta_key ) {
                case 'coupon_amount':
                case 'limit_usage_to_x_items':
                    $meta_value = ( is_numeric( $meta_value ) ? (float) $meta_value : 0 );
                    break;
                case 'usage_limit':
                case 'usage_limit_per_user':
                case 'usage_count':
                    $meta_value = (int) $meta_value;
                    break;
                case 'free_shipping':
                case 'apply_before_tax':
                case 'individual_use':
                    $meta_value = ( $meta_value === 'yes' ? 'yes' : 'no' );
                    break;
                case 'customer_email':
                    $meta_value = ( is_array( $meta_value ) ? array_map( 'sanitize_email', $meta_value ) : array() );
                    break;
                case 'product_ids':
                    $meta_value = sanitize_text_field( (string) $meta_value );
                    break;
                default:
                    $meta_value = ( is_scalar( $meta_value ) ? sanitize_text_field( (string) $meta_value ) : $meta_value );
            }
            update_post_meta( $coupon_id, $meta_key, $meta_value );
        }
    }
    // Mark as reward voucher with multiple meta keys for better compatibility
    update_post_meta( $coupon_id, '_spar_reward_voucher', '1' );
    update_post_meta( $coupon_id, '_spar_reward_id', $reward['id'] );
    update_post_meta( $coupon_id, '_spar_user_id', $user_id );
    update_post_meta( $coupon_id, 'spar_user_id', $user_id );
    // Alternative key without underscore
    // Announce creation
    do_action(
        'spar_reward_voucher_created',
        (int) $coupon_id,
        (string) $coupon_code,
        (int) $user_id,
        $reward
    );
    return $coupon_code;
}

/**
 * Send reward claimed email
 */
function spar_send_reward_claimed_email(  $user_id, $reward, $voucher_code  ) {
    $options = get_option( 'spar_options', array() );
    if ( empty( $options['enable_claimed'] ) ) {
        return;
    }
    $user = get_user_by( 'ID', $user_id );
    if ( !$user ) {
        return;
    }
    $points_label = $options['points_label'] ?? esc_html__( 'Points', 'simple-points-and-rewards' );
    $user_points = spar_get_user_points( $user_id );
    // Prepare email content (defensive sanitization)
    $subject_template = ( isset( $options['subject_claimed'] ) ? (string) $options['subject_claimed'] : esc_html__( 'Your reward is ready! Voucher code: {voucher_code}', 'simple-points-and-rewards' ) );
    $subject_template = wp_strip_all_tags( $subject_template );
    $body_template = ( isset( $options['body_claimed'] ) ? (string) $options['body_claimed'] : '' );
    // Body is already sanitized on save (editor -> wp_kses_post), but sanitize again defensively
    $body_template = wp_kses_post( $body_template );
    // Build replacements for body (HTML context) and subject (plain text context)
    $rewards_url = ( function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( 'rewards' ) : home_url( '/my-account/rewards/' ) );
    $cart_url_safe = ( function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/cart/' ) );
    $apply_coupon_url = add_query_arg( array(
        'apply_coupon' => $voucher_code,
        'spar_nonce'   => wp_create_nonce( 'spar_apply_coupon' ),
    ), $cart_url_safe );
    $replacements_body = array(
        '{user_name}'        => esc_html( $user->display_name ),
        '{voucher_code}'     => esc_html( $voucher_code ),
        '{voucher_type}'     => esc_html( ( isset( $reward['name'] ) ? (string) $reward['name'] : '' ) ),
        '{points}'           => esc_html( spar_format_points_value( (int) ($reward['points'] ?? 0) ) ),
        '{points_label}'     => esc_html( $points_label ),
        '{total_points}'     => esc_html( spar_format_points_value( (int) $user_points ) ),
        '{site_name}'        => esc_html( get_bloginfo( 'name' ) ),
        '{rewards_url}'      => esc_url( $rewards_url ),
        '{apply_coupon_url}' => esc_url( $apply_coupon_url ),
    );
    $replacements_subject = array(
        '{user_name}'        => wp_strip_all_tags( (string) $user->display_name ),
        '{voucher_code}'     => wp_strip_all_tags( (string) $voucher_code ),
        '{voucher_type}'     => wp_strip_all_tags( ( isset( $reward['name'] ) ? (string) $reward['name'] : '' ) ),
        '{points}'           => wp_strip_all_tags( spar_format_points_value( (int) ($reward['points'] ?? 0) ) ),
        '{points_label}'     => wp_strip_all_tags( (string) $points_label ),
        '{total_points}'     => wp_strip_all_tags( spar_format_points_value( (int) $user_points ) ),
        '{site_name}'        => wp_strip_all_tags( get_bloginfo( 'name' ) ),
        '{rewards_url}'      => wp_strip_all_tags( esc_url( $rewards_url ) ),
        '{apply_coupon_url}' => wp_strip_all_tags( esc_url( $apply_coupon_url ) ),
    );
    $subject = str_replace( array_keys( $replacements_subject ), array_values( $replacements_subject ), $subject_template );
    $body = str_replace( array_keys( $replacements_body ), array_values( $replacements_body ), $body_template );
    // Let developers adjust email subject/body before sending
    $subject = apply_filters( 'spar_email_voucher_claimed_subject', $subject, [
        'user_id'      => (int) $user_id,
        'voucher_code' => (string) $voucher_code,
        'reward'       => $reward,
    ] );
    $body = apply_filters( 'spar_email_voucher_claimed_body', $body, [
        'user_id'      => (int) $user_id,
        'voucher_code' => (string) $voucher_code,
        'reward'       => $reward,
    ] );
    // Sanitize filtered email content
    $subject = wp_strip_all_tags( (string) $subject );
    $body = wp_kses_post( (string) $body );
    // Send using WooCommerce email template if available (falls back gracefully)
    $__sent = spar_send_wc_email(
        (string) $user->user_email,
        (string) $subject,
        (string) $subject,
        wpautop( $body )
    );
    // Generic email sent hook for observability
    do_action(
        'spar_email_sent',
        'voucher_claimed',
        (bool) $__sent,
        (string) $user->user_email,
        (string) $subject,
        (int) $user_id,
        (string) $voucher_code
    );
}
