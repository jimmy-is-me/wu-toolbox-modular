<?php

/**
 * Referral Tracking Functions
 */
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Track referral when page loads
 */
add_action( 'init', 'spar_track_referral', 5 );
// Priority 5 to run early
function spar_track_referral() {
    // Only run if referral feature is enabled
    $options = get_option( 'spar_options', [] );
    if ( empty( $options['earn']['referral']['enabled'] ) ) {
        return;
    }
    // Read-only referral parameter
    $referral_code = filter_input( INPUT_GET, 'ref', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
    if ( !empty( $referral_code ) ) {
        // Validate that the referral code exists
        $referrer_id = spar_get_user_by_referral_code( $referral_code );
        if ( !$referrer_id ) {
            return;
            // Invalid referral code
        }
        // Track the click FIRST before any blocking logic
        spar_increment_referral_clicks( $referrer_id );
        // Insert detailed click log row (always log the visit)
        $click_id = spar_log_referral_click( $referral_code, $referrer_id );
        // Get attribution model setting and self-referral blocking setting
        $options = get_option( 'spar_options', [] );
        $attribution_model = $options['earn']['referral']['gift_attribution_model'] ?? 'first_click';
        $block_self_referral = !empty( $options['earn']['referral']['block_self_referral'] );
        if ( !isset( $options['earn']['referral']['block_self_referral'] ) ) {
            $block_self_referral = true;
            // Default to blocking self-referrals for security
        }
        // Don't track if user is referring themselves and self-referral blocking is enabled
        $current_user_id = get_current_user_id();
        // CRITICAL: Block self-referrals BEFORE setting any cookies or creating coupons
        if ( $block_self_referral && $current_user_id && $current_user_id == $referrer_id ) {
            return;
            // Silently block self-referrals
        }
        // Check if we should honor existing referral (first-click attribution)
        if ( $attribution_model === 'first_click' && isset( $_COOKIE['spar_referrer'] ) ) {
            return;
        }
        // Set or update cookie (30 days)
        setcookie(
            'spar_referrer',
            $referral_code,
            time() + 30 * 24 * 60 * 60,
            '/'
        );
        if ( $click_id ) {
            setcookie(
                'spar_ref_click_id',
                (string) absint( $click_id ),
                time() + 30 * 24 * 60 * 60,
                '/'
            );
        }
    }
}

/**
 * Insert a detailed referral click log row
 */
function spar_log_referral_click(  $referral_code, $referrer_id  ) {
    global $wpdb;
    // Ensure table exists (best-effort)
    $table = $wpdb->prefix . 'spar_referral_clicks';
    // Ensure table exists (cached check).
    if ( !spar_table_exists( $table ) ) {
        if ( function_exists( 'spar_create_referral_clicks_table' ) ) {
            spar_create_referral_clicks_table();
        }
    }
    $landing_url = '';
    $referral_link = '';
    $referring_domain = null;
    if ( isset( $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] ) ) {
        // Best-effort current URL
        $scheme = ( is_ssl() ? 'https://' : 'http://' );
        $referral_link = $scheme . wp_unslash( $_SERVER['HTTP_HOST'] ) . wp_unslash( $_SERVER['REQUEST_URI'] );
        $landing_url = $referral_link;
        $referral_link = esc_url_raw( $referral_link );
        $landing_url = esc_url_raw( $landing_url );
    }
    // Extract referring domain from HTTP referrer header if present
    $raw_ref = wp_get_raw_referer();
    if ( $raw_ref ) {
        $parts = wp_parse_url( $raw_ref );
        if ( is_array( $parts ) && !empty( $parts['host'] ) ) {
            $host = strtolower( (string) $parts['host'] );
            // Normalize: strip leading www.
            $host = preg_replace( '/^www\\./i', '', $host );
            $referring_domain = $host;
        }
    }
    $current_user_id = get_current_user_id();
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    $inserted = $wpdb->insert( $table, [
        'created_at'       => current_time( 'mysql' ),
        'referral_code'    => sanitize_text_field( $referral_code ),
        'referrer_user_id' => (int) $referrer_id,
        'referree_user_id' => ( $current_user_id ? (int) $current_user_id : null ),
        'landing_url'      => esc_url_raw( $landing_url ),
        'referral_link'    => esc_url_raw( $referral_link ),
        'referring_domain' => ( $referring_domain ? sanitize_text_field( $referring_domain ) : null ),
        'converted'        => 0,
    ], [
        '%s',
        '%s',
        '%d',
        '%d',
        '%s',
        '%s',
        '%s',
        '%d'
    ] );
    if ( false === $inserted ) {
        return 0;
    }
    return (int) $wpdb->insert_id;
}

/**
 * Store referrer information on thank you page
 */
add_action( 'woocommerce_thankyou', 'spar_store_referrer_in_order', 10 );
function spar_store_referrer_in_order(  $order_id  ) {
    $options = get_option( 'spar_options', [] );
    if ( empty( $options['earn']['referral']['enabled'] ) ) {
        return;
    }
    $referrer_code = null;
    if ( isset( $_COOKIE['spar_referrer'] ) ) {
        $referrer_code = sanitize_text_field( wp_unslash( $_COOKIE['spar_referrer'] ) );
        setcookie(
            'spar_referrer',
            '',
            time() - 3600,
            '/'
        );
        // Clear click id cookie after thank you page capture
        if ( isset( $_COOKIE['spar_ref_click_id'] ) ) {
            setcookie(
                'spar_ref_click_id',
                '',
                time() - 3600,
                '/'
            );
        }
    } else {
        // If no cookie exists, check for referral coupon usage when always_track is enabled
        $tracking_mode = $options['earn']['referral']['coupon_tracking_mode'] ?? 'always_track';
        if ( $tracking_mode === 'always_track' ) {
            $order = wc_get_order( $order_id );
            if ( $order ) {
                $referrer_id = spar_check_referral_coupon_usage( $order );
                if ( $referrer_id ) {
                    $referrer_code = spar_get_user_referral_code( $referrer_id );
                }
            }
        }
    }
    setcookie(
        'spar_referral_coupon',
        '',
        time() - 3600,
        '/'
    );
    setcookie(
        'spar_referral_offer_expires',
        '',
        time() - 3600,
        '/'
    );
    if ( $referrer_code ) {
        $referrer_id = spar_get_user_by_referral_code( $referrer_code );
        $order = wc_get_order( $order_id );
        if ( $order ) {
            $order->update_meta_data( 'referrer_code', $referrer_code );
            if ( $referrer_id ) {
                $customer_id = (int) $order->get_user_id();
                if ( $customer_id && $customer_id !== (int) $referrer_id ) {
                    update_user_meta( $customer_id, 'spar_referrer_user_id', (int) $referrer_id );
                }
            }
            $order->save();
            // Mark conversion against the click log if possible
            spar_update_referral_click_conversion( $order, $referrer_code );
        }
    }
}

/**
 * Update referral click log to mark conversion
 */
function spar_update_referral_click_conversion(  $order, $referrer_code  ) {
    if ( !$order ) {
        return;
    }
    global $wpdb;
    $table = $wpdb->prefix . 'spar_referral_clicks';
    $order_id = $order->get_id();
    $customer_id = (int) $order->get_user_id();
    // Determine gift coupon used, if any
    $gift_coupon_code = null;
    $coupon_codes = $order->get_coupon_codes();
    foreach ( $coupon_codes as $coupon_code ) {
        if ( preg_match( '/^gift-([A-Z0-9]+)$/i', $coupon_code ) ) {
            $gift_coupon_code = $coupon_code;
            break;
        }
    }
    $now = current_time( 'mysql' );
    // Prefer matching by click ID cookie
    $click_id_cookie = ( isset( $_COOKIE['spar_ref_click_id'] ) ? absint( $_COOKIE['spar_ref_click_id'] ) : 0 );
    if ( $click_id_cookie ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->update(
            $table,
            [
                'converted'        => 1,
                'order_id'         => $order_id,
                'gift_coupon_code' => ( $gift_coupon_code ? sanitize_text_field( $gift_coupon_code ) : null ),
                'conversion_at'    => $now,
                'referree_user_id' => ( $customer_id ?: null ),
            ],
            [
                'id' => $click_id_cookie,
            ],
            [
                '%d',
                '%d',
                '%s',
                '%s',
                '%d'
            ],
            ['%d']
        );
        return;
    }
    // Fallback: match most recent unconverted click for this referral code
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
    $row = $wpdb->get_row( $wpdb->prepare( 'SELECT id FROM %i WHERE referral_code = %s AND converted = 0 ORDER BY created_at DESC LIMIT 1', $table, $referrer_code ), ARRAY_A );
    if ( $row && isset( $row['id'] ) ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->update(
            $table,
            [
                'converted'        => 1,
                'order_id'         => $order_id,
                'gift_coupon_code' => ( $gift_coupon_code ? sanitize_text_field( $gift_coupon_code ) : null ),
                'conversion_at'    => $now,
                'referree_user_id' => ( $customer_id ?: null ),
            ],
            [
                'id' => (int) $row['id'],
            ],
            [
                '%d',
                '%d',
                '%s',
                '%s',
                '%d'
            ],
            ['%d']
        );
    }
}

/**
 * Award referral bonus when order is completed
 */
add_action( 'woocommerce_order_status_completed', 'spar_award_referral_bonus', 20 );
add_action( 'woocommerce_order_status_processing', 'spar_award_referral_bonus', 20 );
function spar_award_referral_bonus(  $order_id  ) {
    $options = get_option( 'spar_options', [] );
    if ( empty( $options['earn']['referral']['enabled'] ) ) {
        return;
    }
    // Respect configured award timing (completed or processing)
    $award_timing = $options['earn']['referral']['award_timing'] ?? 'completed';
    $current_hook = current_action();
    if ( $award_timing === 'completed' && $current_hook !== 'woocommerce_order_status_completed' || $award_timing === 'processing' && $current_hook !== 'woocommerce_order_status_processing' ) {
        return;
    }
    $order = wc_get_order( $order_id );
    if ( !$order ) {
        return;
    }
    // Serialise concurrent award attempts (the completed and processing status
    // hooks can fire in parallel requests) and re-check the awarded flag while
    // holding the lock, so two requests cannot both read it as empty and pay
    // the referrer twice.
    $lock_key = 'referral_points_' . $order->get_id();
    if ( !spar_acquire_db_lock( $lock_key ) ) {
        return;
        // Another request is already awarding for this order.
    }
    $order->read_meta_data( true );
    if ( !$order->get_meta( 'referral_points_awarded' ) ) {
        spar_process_referral_bonus_award( $order, $options );
    }
    spar_release_db_lock( $lock_key );
}

/**
 * Resolve the referrer for an order, run fraud checks, and pay the referral
 * bonus. Must be called with the per-order referral lock held and the
 * awarded flag already confirmed empty.
 *
 * @param WC_Order $order   Referred order.
 * @param array    $options Plugin options.
 */
function spar_process_referral_bonus_award(  $order, $options  ) {
    $referrer_code = $order->get_meta( 'referrer_code' );
    $referrer_id = null;
    // If no referrer code from cookie, check for referral coupon usage
    if ( !$referrer_code ) {
        $referrer_id = spar_check_referral_coupon_usage( $order );
        if ( $referrer_id ) {
            $referrer_code = spar_get_user_referral_code( $referrer_id );
            // Store referrer code for this order
            $order->update_meta_data( 'referrer_code', $referrer_code );
            $order->update_meta_data( 'referral_source', 'coupon' );
            $order->save();
        }
    } else {
        // Get referrer user ID from existing referrer code
        $referrer_id = spar_get_user_by_referral_code( $referrer_code );
    }
    if ( !$referrer_id ) {
        return;
    }
    $referrer_id = absint( $referrer_id );
    // Run fraud/abuse checks (self-referral by account or email, shared IP,
    // repeat-customer gating, per-referrer daily cap) before paying out.
    $block_reason = spar_get_referral_award_block_reason( $order, $referrer_id, $options );
    if ( '' !== $block_reason ) {
        // Record why for admins and let integrations react. The order is not
        // marked as awarded, so a later status transition re-evaluates (e.g.
        // after the merchant relaxes a rule).
        $order->update_meta_data( '_spar_referral_award_blocked', $block_reason );
        $order->save();
        do_action(
            'spar_referral_award_blocked',
            $order->get_id(),
            $referrer_id,
            $block_reason
        );
        return;
    }
    // Calculate referral bonus
    $earning_type = $options['earn']['referral']['earning_type'] ?? 'fixed';
    if ( $earning_type === 'fixed' ) {
        // Fixed amount per referral
        $referral_points = (int) ($options['earn']['referral']['fixed_points'] ?? 100);
    } else {
        // Percentage of order total
        $percentage = (float) ($options['earn']['referral']['percentage'] ?? 10);
        $points_per_pound = (float) ($options['earn']['referral']['points_per'] ?? 5);
        $order_total = $order->get_total();
        $referral_value = $order_total * $percentage / 100;
        $referral_points = floor( $referral_value * $points_per_pound );
    }
    if ( function_exists( 'spar_apply_level_multiplier' ) ) {
        $referral_points = spar_apply_level_multiplier( $referrer_id, (int) $referral_points, 'referral' );
    }
    if ( $referral_points > 0 ) {
        $action_name = $options['earn']['referral']['name'] ?? esc_html__( 'Referral Bonus', 'simple-points-and-rewards' );
        if ( $earning_type === 'fixed' ) {
            $action_description = sprintf( '%s: Fixed amount for referral (Order #%d)', $action_name, $order->get_id() );
        } else {
            // Display the order total with currency (prefer WooCommerce formatter when available)
            $order_currency = ( method_exists( $order, 'get_currency' ) ? $order->get_currency() : '' );
            if ( function_exists( 'wc_price' ) ) {
                $display_total = wc_price( $order_total, array(
                    'currency' => $order_currency,
                ) );
            } else {
                $display_total = (( $order_currency ? $order_currency . ' ' : '' )) . number_format( (float) $order_total, 2 );
            }
            $action_description = sprintf(
                '%s: %s%% of %s (Order #%d)',
                $action_name,
                $percentage,
                $display_total,
                $order->get_id()
            );
        }
        $points_result = spar_update_user_points(
            $referrer_id,
            $referral_points,
            'add',
            $action_description,
            'referral',
            array(
                'reference_type' => 'order',
                'reference_id'   => $order->get_id(),
            )
        );
        // Mark that referral bonus has been awarded
        $order->update_meta_data( 'referral_points_awarded', $referral_points );
        $order->delete_meta_data( '_spar_referral_award_blocked' );
        $order->save();
        // Update referrer stats
        if ( !is_array( $points_result ) || empty( $points_result['status'] ) || 'delayed' !== $points_result['status'] ) {
            spar_update_user_referral_stats( $referrer_id, $referral_points );
        }
    }
}

/**
 * Decide whether the referral bonus for an order should be blocked as
 * suspected fraud/abuse.
 *
 * @param WC_Order $order       Referred order.
 * @param int      $referrer_id Referrer user ID.
 * @param array    $options     Plugin options.
 * @return string Reason slug ('self_referral_user', 'self_referral_email',
 *                'same_ip', 'not_new_customer', 'daily_cap_reached', or a
 *                custom slug added via the filter), or '' to allow the award.
 */
function spar_get_referral_award_block_reason(  $order, $referrer_id, $options  ) {
    $referral_options = ( isset( $options['earn']['referral'] ) && is_array( $options['earn']['referral'] ) ? $options['earn']['referral'] : array() );
    $referrer_id = absint( $referrer_id );
    $customer_id = (int) $order->get_user_id();
    $billing_email = strtolower( trim( (string) $order->get_billing_email() ) );
    $reason = '';
    // Default to blocking self-referrals when the setting has never been saved.
    $block_self_referral = !isset( $referral_options['block_self_referral'] ) || !empty( $referral_options['block_self_referral'] );
    if ( $block_self_referral ) {
        if ( $customer_id && $customer_id === $referrer_id ) {
            $reason = 'self_referral_user';
        }
        // Compare the order billing email against the referrer's account and
        // billing emails, so logging out or checking out as a guest cannot
        // bypass the account check above.
        if ( '' === $reason && '' !== $billing_email ) {
            $referrer_emails = array();
            $referrer_user = get_userdata( $referrer_id );
            if ( $referrer_user && !empty( $referrer_user->user_email ) ) {
                $referrer_emails[] = strtolower( trim( (string) $referrer_user->user_email ) );
            }
            $referrer_billing_email = strtolower( trim( (string) get_user_meta( $referrer_id, 'billing_email', true ) ) );
            if ( '' !== $referrer_billing_email ) {
                $referrer_emails[] = $referrer_billing_email;
            }
            if ( in_array( $billing_email, $referrer_emails, true ) ) {
                $reason = 'self_referral_email';
            }
        }
    }
    // Optional: block when the referred order comes from the same IP address
    // as one of the referrer's own recent orders. Off by default because
    // shared household/office connections can legitimately share an IP.
    if ( '' === $reason && !empty( $referral_options['block_same_ip'] ) && $referrer_id ) {
        $order_ip = trim( (string) $order->get_customer_ip_address() );
        if ( '' !== $order_ip ) {
            $referrer_orders = wc_get_orders( array(
                'customer_id' => $referrer_id,
                'exclude'     => array($order->get_id()),
                'limit'       => 10,
                'return'      => 'objects',
            ) );
            foreach ( $referrer_orders as $referrer_order ) {
                if ( trim( (string) $referrer_order->get_customer_ip_address() ) === $order_ip ) {
                    $reason = 'same_ip';
                    break;
                }
            }
        }
    }
    // Optional: only pay the bonus on the referred customer's first order.
    if ( '' === $reason && !empty( $referral_options['new_customer_only'] ) ) {
        // Query with the raw (not lowercased) billing email so the value
        // matches what WooCommerce stored for the customer's past orders.
        $raw_billing_email = trim( (string) $order->get_billing_email() );
        $base_args = array(
            'status'  => array('completed', 'processing', 'on-hold'),
            'exclude' => array($order->get_id()),
            'limit'   => 1,
            'return'  => 'ids',
        );
        $previous_orders = array();
        if ( $customer_id ) {
            $previous_orders = wc_get_orders( array_merge( $base_args, array(
                'customer_id' => $customer_id,
            ) ) );
        }
        // Also match by billing email so a registered account with earlier
        // guest checkouts (customer_id 0) is not treated as a new customer.
        if ( empty( $previous_orders ) && '' !== $raw_billing_email ) {
            $previous_orders = wc_get_orders( array_merge( $base_args, array(
                'customer' => $raw_billing_email,
            ) ) );
        }
        if ( !empty( $previous_orders ) ) {
            $reason = 'not_new_customer';
        }
    }
    // Optional: cap how many referral bonuses one referrer can earn per day.
    if ( '' === $reason ) {
        $daily_cap = ( isset( $referral_options['referrer_daily_cap'] ) ? absint( $referral_options['referrer_daily_cap'] ) : 0 );
        if ( $daily_cap > 0 && spar_count_referral_awards_today( $referrer_id ) >= $daily_cap ) {
            $reason = 'daily_cap_reached';
        }
    }
    /**
     * Filter the referral fraud decision for an order.
     *
     * Return '' to allow the award, or a reason slug to block it. The reason
     * is stored in the order meta `_spar_referral_award_blocked`.
     *
     * @param string   $reason      Block reason slug, '' when allowed.
     * @param WC_Order $order       Referred order.
     * @param int      $referrer_id Referrer user ID.
     * @param array    $options     Plugin options.
     */
    return (string) apply_filters(
        'spar_referral_award_block_reason',
        $reason,
        $order,
        $referrer_id,
        $options
    );
}

/**
 * Count referral bonuses credited to a referrer today (site timezone),
 * including delayed awards still sitting in the pending points queue so the
 * daily cap cannot be bypassed while awards wait for release.
 *
 * @param int $referrer_id Referrer user ID.
 * @return int Number of referral bonus awards counted for today.
 */
function spar_count_referral_awards_today(  $referrer_id  ) {
    global $wpdb;
    $referrer_id = absint( $referrer_id );
    if ( !$referrer_id ) {
        return 0;
    }
    // Log rows use current_time('mysql'), so compute midnight in site time.
    $today_start = gmdate( 'Y-m-d 00:00:00', current_time( 'timestamp' ) );
    $count = 0;
    $log_table = $wpdb->prefix . 'spar_points_logs';
    if ( spar_table_exists( $log_table ) ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $count += (int) $wpdb->get_var( $wpdb->prepare(
            'SELECT COUNT(*) FROM %i WHERE user_id = %d AND action_id = %s AND type = %s AND date >= %s',
            $log_table,
            $referrer_id,
            'referral',
            'add',
            $today_start
        ) );
    }
    if ( function_exists( 'spar_points_delay_table_name' ) ) {
        $pending_table = spar_points_delay_table_name();
        if ( spar_table_exists( $pending_table ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $count += (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM %i WHERE user_id = %d AND action_id = %s AND action = %s AND status IN ( 'pending', 'processing' ) AND created_at >= %s",
                $pending_table,
                $referrer_id,
                'referral',
                'add',
                $today_start
            ) );
        }
    }
    return $count;
}

/**
 * Deduct previously awarded referral points if the referred order is refunded/cancelled/failed (configurable).
 */
add_action( 'woocommerce_order_status_refunded', 'spar_deduct_refunded_referral_points' );
add_action( 'woocommerce_order_status_cancelled', 'spar_deduct_refunded_referral_points' );
add_action( 'woocommerce_order_status_failed', 'spar_deduct_refunded_referral_points' );
// Also handle refund events where the order status does not change (partial/full refunds)
add_action(
    'woocommerce_order_refunded',
    'spar_maybe_deduct_referral_points_on_refund_event',
    10,
    1
);
add_action(
    'woocommerce_order_fully_refunded',
    'spar_maybe_deduct_referral_points_on_refund_event',
    10,
    1
);
function spar_deduct_refunded_referral_points(  $order_id  ) {
    $order_id = absint( $order_id );
    if ( $order_id <= 0 ) {
        return;
    }
    $order = wc_get_order( $order_id );
    if ( !$order ) {
        return;
    }
    $pending_cancelled_points = 0;
    $options = get_option( 'spar_options', [] );
    // Only run if referral feature is enabled
    if ( empty( $options['earn']['referral']['enabled'] ) ) {
        return;
    }
    // Default to true when unset so deductions still occur even if settings haven't been saved yet
    $deduct_on_refund = $options['earn']['referral']['deduct_on_refund'] ?? true;
    if ( !$deduct_on_refund ) {
        return;
    }
    // Serialise concurrent deduction attempts (refund events and status hooks
    // can fire in parallel requests) and re-read meta fresh while holding the
    // lock, so the deducted flag cannot be read as empty by two requests at
    // once. Shares the award lock key so awarding and deducting for the same
    // order also serialise against each other.
    $lock_key = 'referral_points_' . $order_id;
    if ( !spar_acquire_db_lock( $lock_key ) ) {
        return;
        // Another request is already processing this order's referral points.
    }
    $order->read_meta_data( true );
    // Only proceed if referral points were actually awarded for this order
    $awarded = max( 0, (int) $order->get_meta( 'referral_points_awarded' ) - (int) $pending_cancelled_points );
    if ( $awarded <= 0 ) {
        spar_release_db_lock( $lock_key );
        return;
    }
    // Prevent double-deduction
    $already_deducted = (int) $order->get_meta( 'referral_points_deducted' );
    if ( $already_deducted > 0 ) {
        spar_release_db_lock( $lock_key );
        return;
    }
    // Determine the referrer user ID
    $referrer_id = null;
    $referrer_code = $order->get_meta( 'referrer_code' );
    if ( $referrer_code ) {
        $referrer_id = spar_get_user_by_referral_code( $referrer_code );
    }
    if ( !$referrer_id ) {
        $referrer_id = spar_check_referral_coupon_usage( $order );
    }
    if ( !$referrer_id ) {
        spar_release_db_lock( $lock_key );
        return;
    }
    // Build action text
    $points_label = ( spar_get_option( '', 'points_label' ) ?: esc_html__( 'Points', 'simple-points-and-rewards' ) );
    $status = $order->get_status();
    $status_text = '';
    switch ( $status ) {
        case 'refunded':
            $status_text = esc_html__( 'Refunded', 'simple-points-and-rewards' );
            $action_id = 'referral_refund';
            break;
        case 'cancelled':
            $status_text = esc_html__( 'Cancelled', 'simple-points-and-rewards' );
            $action_id = 'referral_cancelled';
            break;
        case 'failed':
            $status_text = esc_html__( 'Failed', 'simple-points-and-rewards' );
            $action_id = 'referral_failed';
            break;
        default:
            $status_text = esc_html__( 'Refunded/Cancelled', 'simple-points-and-rewards' );
            $action_id = 'referral_refund';
    }
    $action_text = sprintf(
        esc_html__( '%1$s Deducted for %2$s Referred Order: #%3$d', 'simple-points-and-rewards' ),
        $points_label,
        $status_text,
        (int) $order_id
    );
    $action_text = sanitize_text_field( (string) $action_text );
    $action_id = sanitize_key( (string) $action_id );
    // Perform the deduction
    spar_update_user_points(
        $referrer_id,
        (int) $awarded,
        'remove',
        $action_text,
        $action_id,
        array(
            'reference_type' => 'order',
            'reference_id'   => $order_id,
        )
    );
    // Mark as deducted to avoid repeats
    $order->update_meta_data( 'referral_points_deducted', (int) $awarded );
    $order->save();
    spar_release_db_lock( $lock_key );
}

/**
 * On refund events that may not change the status (e.g., partial refunds),
 * only deduct referral points if the order is now fully refunded or moved
 * to a terminal status (refunded/cancelled/failed).
 *
 * @param int $order_id Order ID.
 */
function spar_maybe_deduct_referral_points_on_refund_event(  $order_id  ) {
    // Only run if referral feature is enabled and deductions are allowed
    $options = get_option( 'spar_options', [] );
    if ( empty( $options['earn']['referral']['enabled'] ) ) {
        return;
    }
    $deduct_on_refund = $options['earn']['referral']['deduct_on_refund'] ?? true;
    if ( !$deduct_on_refund ) {
        return;
    }
    $order = wc_get_order( $order_id );
    if ( !$order ) {
        return;
    }
    // If status already indicates a refund/cancellation/failure, run the standard deduction.
    if ( $order->has_status( array('refunded', 'cancelled', 'failed') ) ) {
        spar_deduct_refunded_referral_points( $order_id );
        return;
    }
    // Otherwise, for refund events, only deduct if the order is fully refunded by totals.
    $order_total = (float) $order->get_total();
    $total_refunded = (float) (( method_exists( $order, 'get_total_refunded' ) ? $order->get_total_refunded() : 0 ));
    // Use a small epsilon to account for floating point rounding.
    if ( $order_total > 0 && $order_total - $total_refunded <= 0.01 ) {
        spar_deduct_refunded_referral_points( $order_id );
    }
}

/**
 * Check if order used a referral coupon and return referrer ID
 */
function spar_check_referral_coupon_usage(  $order  ) {
    $options = get_option( 'spar_options', [] );
    // Only run if referral feature is enabled
    if ( empty( $options['earn']['referral']['enabled'] ) ) {
        return null;
    }
    $tracking_mode = $options['earn']['referral']['coupon_tracking_mode'] ?? 'always_track';
    // Only check coupon usage in 'always_track' mode
    if ( $tracking_mode !== 'always_track' ) {
        return null;
    }
    // Check all coupons used in the order
    $coupon_codes = $order->get_coupon_codes();
    foreach ( $coupon_codes as $coupon_code ) {
        // Check if this is a referral gift coupon
        if ( preg_match( '/^gift-([A-Z0-9]+)$/i', $coupon_code, $matches ) ) {
            $referral_code = $matches[1];
            $referrer_id = spar_get_user_by_referral_code( $referral_code );
            if ( $referrer_id ) {
                // Intentionally no side effects here: this lookup runs from the
                // thankyou capture, the award path AND the refund/deduction path,
                // so incrementing referral stats in it double-counted successes.
                // spar_update_user_referral_stats() records the success when the
                // bonus is actually awarded.
                return $referrer_id;
            }
        }
    }
    return null;
}

/**
 * Increment successful referrals (used for coupon-based tracking)
 */
function spar_increment_referral_success(  $referrer_id  ) {
    $stats = spar_get_user_referral_stats( $referrer_id );
    $stats['successful_referrals']++;
    update_user_meta( $referrer_id, 'spar_referral_stats', $stats );
}
