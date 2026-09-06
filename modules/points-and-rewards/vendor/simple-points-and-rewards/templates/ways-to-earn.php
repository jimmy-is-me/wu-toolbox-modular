<?php

/**
 * Ways to earn points - frontend display
 */
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
if ( !function_exists( 'spar_render_ways_to_earn_template' ) ) {
    /**
     * Render the ways to earn points template while keeping variables scoped locally.
     */
    function spar_render_ways_to_earn_template() {
        $is_guest = !is_user_logged_in();
        $options_earn = spar_get_options( 'earn' );
        $options_account = spar_get_options( 'account' );
        $points_label = spar_get_option( 'general', 'points_label' );
        // Resolve the admin-configured display order for ways to earn
        $default_earn_order = [
            'signup',
            'order',
            'order_fixed',
            'referral',
            'first_order',
            'nth_order',
            'review',
            'birthday',
            'daily_login',
            'spin_wheel'
        ];
        $all_options = get_option( 'spar_options', [] );
        $earn_ways_order = ( isset( $all_options['earn_ways_order'] ) && is_array( $all_options['earn_ways_order'] ) && !empty( $all_options['earn_ways_order'] ) ? $all_options['earn_ways_order'] : $default_earn_order );
        // Append any default keys not present in saved order
        foreach ( $default_earn_order as $_way ) {
            if ( !in_array( $_way, $earn_ways_order, true ) ) {
                $earn_ways_order[] = $_way;
            }
        }
        // User Meta
        $user_id = get_current_user_id();
        $hidden_ways = [];
        if ( function_exists( 'sparp_cr_get_hidden_ways_for_user__premium_only' ) ) {
            $hidden_ways = sparp_cr_get_hidden_ways_for_user__premium_only( $user_id );
            if ( !is_array( $hidden_ways ) ) {
                $hidden_ways = [];
            }
        }
        $user_rewards_earned = get_user_meta( $user_id, '_spar_rewards_earned', true );
        if ( !is_array( $user_rewards_earned ) ) {
            $user_rewards_earned = [];
        }
        $earned_signup = ( isset( $user_rewards_earned['signup'] ) ? (int) $user_rewards_earned['signup'] : 0 );
        $earned_first_order = ( isset( $user_rewards_earned['first_order'] ) ? (int) $user_rewards_earned['first_order'] : 0 );
        // Pre-compute order count ONCE (used by nth_order checks AND spend progress).
        $spar_wte_order_count = 0;
        if ( $user_id && function_exists( 'wc_get_orders' ) ) {
            $spar_wte_order_count = (int) wp_cache_get( 'spar_wte_order_count_' . $user_id, 'spar' );
            if ( !$spar_wte_order_count ) {
                global $wpdb;
                if ( function_exists( 'wc_get_container' ) ) {
                    // HPOS: use wc_orders table directly.
                    $spar_wte_order_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders WHERE customer_id = %d AND status IN ('wc-completed','wc-processing') AND type = 'shop_order'", $user_id ) );
                } else {
                    // Legacy: count from posts.
                    $spar_wte_order_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'shop_order' AND post_status IN ('wc-completed','wc-processing') AND ID IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_customer_user' AND meta_value = %d)", $user_id ) );
                }
                wp_cache_set(
                    'spar_wte_order_count_' . $user_id,
                    $spar_wte_order_count,
                    'spar',
                    300
                );
            }
        }
        // Nth order milestone check key depends on configured X, and recurring affects the meta key format
        $configured_nth = ( isset( $options_earn['nth_order']['order_count'] ) ? (int) $options_earn['nth_order']['order_count'] : 0 );
        $is_nth_recurring = !empty( $options_earn['nth_order']['recurring'] );
        $earned_nth_current = 0;
        if ( $configured_nth > 0 ) {
            if ( $is_nth_recurring ) {
                // For recurring, award keys are saved like 'nth_order_X_Y' where Y is the milestone order count reached
                $prefix = 'nth_order_' . $configured_nth . '_';
                foreach ( $user_rewards_earned as $k => $v ) {
                    if ( is_string( $k ) && strpos( $k, $prefix ) === 0 && !empty( $v ) ) {
                        $earned_nth_current = 1;
                        break;
                    }
                }
            } else {
                // One-time milestone uses 'nth_order_X'
                $earned_nth_key = 'nth_order_' . $configured_nth;
                $earned_nth_current = ( !empty( $user_rewards_earned[$earned_nth_key] ) ? 1 : 0 );
                // If not yet marked by meta, also consider current order count: show tick once they have reached X orders
                if ( !$earned_nth_current ) {
                    if ( $spar_wte_order_count >= $configured_nth ) {
                        $earned_nth_current = 1;
                    }
                }
            }
        }
        // Reviews: count how many review bonuses have been awarded to this user
        $review_awarded_count = (int) get_user_meta( $user_id, '_spar_review_awarded_count', true );
        // Birthday: last year awarded
        $user_id = get_current_user_id();
        $birthday_last_awarded_year = (int) get_user_meta( $user_id, '_spar_birthday_last_granted_year', true );
        $birthday_saved_ymd = (string) get_user_meta( $user_id, '_spar_birthday_date', true );
        $spar_current_year = (int) (( function_exists( 'wp_date' ) ? wp_date( 'Y', null, wp_timezone() ) : date( 'Y' ) ));
        // Capture each way-to-earn block into an array keyed by slug so we can output in saved order.
        $earn_blocks = [];
        // ---- signup ----
        ob_start();
        $_signup_hide_after_rewarded = !empty( $options_earn['signup']['hide_after_rewarded'] ) && $earned_signup > 0;
        if ( !empty( $options_earn['signup']['enabled'] ) && !in_array( 'signup', $hidden_ways, true ) && !$_signup_hide_after_rewarded ) {
            ?>
		<div class="spar-box-full" data-earn-type="signup">
			<div>
				<span class="spar-earn-fa-icon"><i class="fa-solid fa-user-plus" aria-hidden="true"></i></span>
				<strong><?php 
            echo esc_html( $options_earn['signup']['name'] ?? esc_html__( 'Signup Bonus', 'simple-points-and-rewards' ) );
            ?></strong>
				<?php 
            if ( $earned_signup > 0 ) {
                ?>
					<span style="color: green; margin-left: 10px;"><i class="fa-solid fa-circle-check" aria-hidden="true"></i></span>
				<?php 
            }
            ?>
			</div>
			<div class="spar-points-info">
				<?php 
            echo esc_html( spar_get_points_prefix() . $options_earn['signup']['points'] );
            ?> <?php 
            echo esc_html( $points_label );
            ?>
			</div>
		</div>
		<?php 
        }
        $earn_blocks['signup'] = ob_get_clean();
        // ---- first_order ----
        ob_start();
        if ( spar_fs()->can_use_premium_code__premium_only() && !empty( $options_earn['first_order']['enabled'] ) && !in_array( 'first_order', $hidden_ways, true ) ) {
            ?>
			<div class="spar-box-full" data-earn-type="first_order">
				<div>
					<span class="spar-earn-fa-icon"><i class="fa-solid fa-bag-shopping" aria-hidden="true"></i></span>
					<strong><?php 
            echo esc_html( $options_earn['first_order']['name'] ?? esc_html__( 'First Order', 'simple-points-and-rewards' ) );
            ?></strong>
					<?php 
            if ( $earned_first_order > 0 ) {
                ?>
						<span style="color: green; margin-left: 10px;"><i class="fa-solid fa-circle-check" aria-hidden="true"></i></span>
					<?php 
            }
            ?>
				</div>
				<div class="spar-points-info">
					<?php 
            echo esc_html( spar_get_points_prefix() . $options_earn['first_order']['points'] );
            ?> <?php 
            echo esc_html( $points_label );
            ?>
				</div>
			</div>
		<?php 
        }
        $earn_blocks['first_order'] = ob_get_clean();
        // ---- nth_order ----
        ob_start();
        if ( spar_fs()->can_use_premium_code__premium_only() && !empty( $options_earn['nth_order']['enabled'] ) && !in_array( 'nth_order', $hidden_ways, true ) ) {
            ?>
			<div class="spar-box-full" data-earn-type="nth_order">
				<div>
					<span class="spar-earn-fa-icon"><i class="fa-solid <?php 
            echo esc_attr( ( $is_nth_recurring ? 'fa-arrows-rotate' : 'fa-trophy' ) );
            ?>" aria-hidden="true"></i></span>
					<strong><?php 
            $default_nth_title = ( $is_nth_recurring ? esc_html__( 'Bonus every X Orders', 'simple-points-and-rewards' ) : esc_html__( 'Bonus after X Orders', 'simple-points-and-rewards' ) );
            echo esc_html( $options_earn['nth_order']['name'] ?? $default_nth_title );
            ?></strong>
				</div>
				<div class="spar-points-info">
					<?php 
            echo esc_html( spar_get_points_prefix() . (int) ($options_earn['nth_order']['points'] ?? 500) );
            ?> <?php 
            echo esc_html( $points_label );
            ?>
					<?php 
            if ( $configured_nth > 0 ) {
                ?>
						<?php 
                // translators: %d: order count
                $phrase = ( $is_nth_recurring ? esc_html__( 'every %d orders', 'simple-points-and-rewards' ) : esc_html__( 'after %d orders', 'simple-points-and-rewards' ) );
                echo ' - ' . esc_html( sprintf( $phrase, (int) $configured_nth ) );
                ?>
						<?php 
                if ( $is_nth_recurring ) {
                    ?>
							<?php 
                    // Show current order total and remaining for next bonus (recurring only)
                    $order_count = $spar_wte_order_count;
                    $next_in = (int) $configured_nth;
                    if ( $configured_nth > 0 ) {
                        $mod = (int) ($order_count % $configured_nth);
                        $next_in = ( $mod === 0 ? (int) $configured_nth : (int) $configured_nth - $mod );
                    }
                    // Show progress using concatenated translated segments to avoid sprintf issues in translations
                    $progress_text = esc_html__( 'Orders so far:', 'simple-points-and-rewards' ) . ' ' . (int) $order_count . ' • ' . esc_html__( 'Next bonus in', 'simple-points-and-rewards' ) . ' ' . (int) $next_in . ' ' . esc_html__( 'orders', 'simple-points-and-rewards' );
                    ?>
							<div class="spar-nth-order-progress" style="font-size: 12px; opacity: 0.8; margin-top: 2px;">
								<?php 
                    echo esc_html( $progress_text );
                    ?>
							</div>
						<?php 
                } else {
                    ?>
							<?php 
                    // Non-recurring: show current total orders and how many needed to reach X
                    $order_count = $spar_wte_order_count;
                    $remaining = ( $configured_nth > 0 ? max( 0, (int) $configured_nth - (int) $order_count ) : 0 );
                    $progress_text = esc_html__( 'Orders so far:', 'simple-points-and-rewards' ) . ' ' . (int) $order_count . ' • ' . esc_html__( 'Need', 'simple-points-and-rewards' ) . ' ' . (int) $remaining . ' ' . esc_html__( 'more orders', 'simple-points-and-rewards' );
                    ?>
							<div class="spar-nth-order-progress" style="font-size: 12px; opacity: 0.8; margin-top: 2px;">
								<?php 
                    echo esc_html( $progress_text );
                    ?>
							</div>
						<?php 
                }
                ?>
					<?php 
            }
            ?>
				</div>
			</div>
		<?php 
        }
        $earn_blocks['nth_order'] = ob_get_clean();
        // ---- order_fixed ----
        ob_start();
        if ( !empty( $options_earn['order_fixed']['enabled'] ) && !empty( $options_earn['order_fixed']['tiers'] ) && is_array( $options_earn['order_fixed']['tiers'] ) && !in_array( 'order_fixed', $hidden_ways, true ) ) {
            ?>
		<div class="spar-box-full" data-earn-type="order_fixed">
			<div>
				<span class="spar-earn-fa-icon"><i class="fa-solid fa-tags" aria-hidden="true"></i></span>
				<strong><?php 
            /* translators: %s: points label */
            echo esc_html( $options_earn['order_fixed']['name'] ?? sprintf( esc_html__( '%s for Orders', 'simple-points-and-rewards' ), esc_html( $points_label ) ) );
            ?></strong>
			</div>
			<div class="spar-points-info">
				<?php 
            $currency_code = ( function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : (( defined( 'WC_VERSION' ) ? get_option( 'woocommerce_currency' ) : 'GBP' )) );
            $symbol = ( function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol( $currency_code ) : '£' );
            $tier_lines = [];
            $tiers = $options_earn['order_fixed']['tiers'];
            if ( is_array( $tiers ) ) {
                foreach ( $tiers as $tier ) {
                    $points = ( isset( $tier['points'] ) ? (int) $tier['points'] : 0 );
                    $threshold = ( isset( $tier['default_threshold'] ) ? (float) $tier['default_threshold'] : 0.0 );
                    if ( function_exists( 'spar_fs' ) && spar_fs()->can_use_premium_code__premium_only() ) {
                        $currencies = ( isset( $tier['currencies'] ) && is_array( $tier['currencies'] ) ? $tier['currencies'] : [] );
                        if ( !empty( $currencies ) ) {
                            foreach ( $currencies as $row ) {
                                $code = ( isset( $row['currency'] ) ? strtoupper( sanitize_text_field( $row['currency'] ) ) : '' );
                                if ( $code && strtoupper( (string) $currency_code ) === $code ) {
                                    $threshold = ( isset( $row['threshold'] ) ? (float) $row['threshold'] : $threshold );
                                    break;
                                }
                            }
                        }
                    }
                    if ( $points > 0 && $threshold > 0 ) {
                        $amount_display = spar_format_currency_amount( $threshold );
                        /* translators: 1: points number, 2: points label (lowercase), 3: currency symbol, 4: amount */
                        $tier_lines[] = sprintf(
                            esc_html__( '%1$s %2$s when you spend %3$s%4$s', 'simple-points-and-rewards' ),
                            esc_html( spar_get_points_prefix() . $points ),
                            esc_html( strtolower( (string) $points_label ) ),
                            esc_html( $symbol ),
                            esc_html( $amount_display )
                        );
                    }
                }
            }
            if ( !empty( $tier_lines ) ) {
                // Join with line breaks for readability
                echo wp_kses_post( implode( '<br />', array_map( 'wp_kses_post', $tier_lines ) ) );
            }
            ?>
			</div>
		</div>
		<?php 
        }
        $earn_blocks['order_fixed'] = ob_get_clean();
        // ---- order ----
        ob_start();
        if ( !empty( $options_earn['order']['enabled'] ) && !in_array( 'order', $hidden_ways, true ) ) {
            ?>
		<div class="spar-box-full" data-earn-type="order">
			<div>
				<span class="spar-earn-fa-icon"><i class="fa-solid fa-cart-shopping" aria-hidden="true"></i></span>
				<strong><?php 
            /* translators: %s: points label */
            echo esc_html( $options_earn['order']['name'] ?? sprintf( esc_html__( '%s for Orders', 'simple-points-and-rewards' ), esc_html( $points_label ) ) );
            ?></strong>
			</div>
			<div class="spar-points-info">
				<?php 
            // Determine points/amount for the CURRENT currency (premium-aware)
            $pp_points = ( isset( $options_earn['order']['points_per_points'] ) ? (float) $options_earn['order']['points_per_points'] : (float) ($options_earn['order']['points_per'] ?? 0) );
            $pp_amount = ( isset( $options_earn['order']['points_per_amount'] ) ? (float) $options_earn['order']['points_per_amount'] : 1.0 );
            $currency_code = ( function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : (( defined( 'WC_VERSION' ) ? get_option( 'woocommerce_currency' ) : 'GBP' )) );
            if ( $pp_amount <= 0 ) {
                $pp_amount = 1.0;
            }
            $symbol = ( function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol( $currency_code ) : '£' );
            $amount_display = spar_format_currency_amount( $pp_amount );
            // translators: 1: points number, 2: points label, 3: currency symbol, 4: amount
            echo wp_kses_post( sprintf(
                esc_html__( '%1$s %2$s per %3$s%4$s', 'simple-points-and-rewards' ),
                esc_html( spar_get_points_prefix() . $pp_points ),
                esc_html( $points_label ),
                esc_html( $symbol ),
                esc_html( $amount_display )
            ) );
            // For logged-in users, display total spent and total points earned from spending (base points only)
            if ( is_user_logged_in() ) {
                $uid = get_current_user_id();
                $spent_total = 0.0;
                $points_from_spend = 0;
                // Use a single aggregate SQL query instead of loading every order object.
                if ( $uid && function_exists( 'wc_get_orders' ) ) {
                    global $wpdb;
                    $spend_cache_key = 'spar_wte_spend_' . $uid;
                    $cached_spend = wp_cache_get( $spend_cache_key, 'spar' );
                    if ( false === $cached_spend ) {
                        if ( function_exists( 'wc_get_container' ) ) {
                            // HPOS: aggregate from wc_orders table.
                            $spent_total = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(total_amount),0) FROM {$wpdb->prefix}wc_orders WHERE customer_id = %d AND status IN ('wc-completed','wc-processing') AND type = 'shop_order'", $uid ) );
                        } else {
                            // Legacy: aggregate from postmeta.
                            $spent_total = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(pm.meta_value),0)\r\n\t\t\t\t\t\t\t\t\t\t FROM {$wpdb->postmeta} pm\r\n\t\t\t\t\t\t\t\t\t\t INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id\r\n\t\t\t\t\t\t\t\t\t\t INNER JOIN {$wpdb->postmeta} cu ON cu.post_id = p.ID AND cu.meta_key = '_customer_user' AND cu.meta_value = %d\r\n\t\t\t\t\t\t\t\t\t\t WHERE p.post_type = 'shop_order' AND p.post_status IN ('wc-completed','wc-processing') AND pm.meta_key = '_order_total'", $uid ) );
                        }
                        wp_cache_set(
                            $spend_cache_key,
                            $spent_total,
                            'spar',
                            300
                        );
                    } else {
                        $spent_total = (float) $cached_spend;
                    }
                    // Sum points from the activity log for orders to include tier and level multipliers
                    $table = $wpdb->prefix . 'spar_points_logs';
                    $pts_cache_key = 'spar_wte_pts_spend_' . $uid;
                    $cached_pts = wp_cache_get( $pts_cache_key, 'spar' );
                    if ( false === $cached_pts ) {
                        $sum_sql = $wpdb->prepare(
                            'SELECT COALESCE(SUM(points),0) FROM %i WHERE user_id = %d AND action_id = %s AND type = %s',
                            $table,
                            $uid,
                            'order',
                            'add'
                        );
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                        $points_from_spend = (int) $wpdb->get_var( $sum_sql );
                        wp_cache_set(
                            $pts_cache_key,
                            $points_from_spend,
                            'spar',
                            300
                        );
                    } else {
                        $points_from_spend = (int) $cached_pts;
                    }
                }
                if ( $spent_total > 0 ) {
                    $spent_display = spar_format_currency_amount( $spent_total );
                    $line = esc_html__( "You've spent", 'simple-points-and-rewards' ) . ' ' . esc_html( (string) $symbol ) . esc_html( (string) $spent_display ) . ' ' . esc_html__( 'so far', 'simple-points-and-rewards' ) . ' • ' . esc_html__( 'Earned', 'simple-points-and-rewards' ) . ' ' . (int) $points_from_spend . ' ' . esc_html__( 'from spending', 'simple-points-and-rewards' );
                    echo '<div class="spar-spend-progress" style="font-size:12px; opacity:0.8; margin-top:2px;">' . esc_html( $line ) . '</div>';
                }
            }
            ?>
			</div>
		</div>
		<?php 
        }
        $earn_blocks['order'] = ob_get_clean();
        // ---- review ----
        ob_start();
        if ( spar_fs()->can_use_premium_code__premium_only() && !empty( $options_earn['review']['enabled'] ) && !in_array( 'review', $hidden_ways, true ) ) {
            $limit_per_product = ( isset( $options_earn['review']['limit_per_product'] ) ? (int) $options_earn['review']['limit_per_product'] : 1 );
            $review_require_purchase = !empty( $options_earn['review']['require_purchase'] );
            $show_products_popup = $review_require_purchase && !empty( $options_earn['review']['show_unreviewed_products'] ) && function_exists( 'spar_review_get_purchased_products_status' );
            $purchased_products_status = ( $show_products_popup && $user_id ? spar_review_get_purchased_products_status( $user_id, $limit_per_product ) : array() );
            ?>
			<div class="spar-box-full" data-earn-type="review">
				<div>
					<span class="spar-earn-fa-icon"><i class="fa-regular fa-star" aria-hidden="true"></i></span>
					<strong><?php 
            echo esc_html( $options_earn['review']['name'] ?? esc_html__( 'Write a Review', 'simple-points-and-rewards' ) );
            ?></strong>
					<?php 
            $review_limit = ( isset( $options_earn['review']['limit'] ) ? (int) $options_earn['review']['limit'] : 1 );
            $reached_review_limit = $review_limit > 0 && $review_awarded_count >= $review_limit;
            if ( $reached_review_limit ) {
                ?>
						<span style="color: green; margin-left: 10px;"><i class="fa-solid fa-circle-check" aria-hidden="true"></i></span>
					<?php 
            }
            ?>
					<?php 
            if ( !empty( $purchased_products_status ) ) {
                ?>
						<button type="button" class="spar-review-products-link spar-review-products-btn" aria-haspopup="dialog" aria-controls="spar-review-products-modal"><?php 
                esc_html_e( 'View Products', 'simple-points-and-rewards' );
                ?></button>
					<?php 
            }
            ?>
				</div>
				<div class="spar-points-info">
					<?php 
            echo esc_html( spar_get_points_prefix() . (int) ($options_earn['review']['points'] ?? 100) );
            ?> <?php 
            echo esc_html( $points_label );
            ?>
					<?php 
            $limit = ( isset( $options_earn['review']['limit'] ) ? (int) $options_earn['review']['limit'] : 1 );
            if ( $limit > 0 ) {
                /* translators: %d: limit number */
                echo ' - ' . sprintf( esc_html__( 'Up to %d times', 'simple-points-and-rewards' ), (int) $limit );
            }
            if ( $limit_per_product > 0 ) {
                if ( $review_require_purchase ) {
                    $product_limit_text = sprintf( 
                        /* translators: %d: product review limit number */
                        _n(
                            'Up to %d time per product you have purchased',
                            'Up to %d times per product you have purchased',
                            (int) $limit_per_product,
                            'simple-points-and-rewards'
                        ),
                        (int) $limit_per_product
                     );
                } else {
                    $product_limit_text = sprintf( 
                        /* translators: %d: product review limit number */
                        _n(
                            'Up to %d time per product',
                            'Up to %d times per product',
                            (int) $limit_per_product,
                            'simple-points-and-rewards'
                        ),
                        (int) $limit_per_product
                     );
                }
                echo '<div class="spar-review-progress">' . esc_html( $product_limit_text ) . '</div>';
            }
            ?>
					<?php 
            // Small progress line: show pending and approved reviews separately
            $pending_reviews = 0;
            $approved_reviews = (int) $review_awarded_count;
            // Count pending reviews (not yet approved, awaiting moderation)
            $pending_comments = get_comments( array(
                'user_id'    => $user_id,
                'post_type'  => 'product',
                'status'     => 'hold',
                'count'      => true,
                'meta_query' => array(array(
                    'key'     => '_spar_review_points_awarded',
                    'compare' => 'NOT EXISTS',
                )),
            ) );
            $pending_reviews = (int) $pending_comments;
            // Build the status message
            $reviews_line = esc_html__( 'Your reviews:', 'simple-points-and-rewards' ) . ' ';
            if ( $pending_reviews > 0 && $approved_reviews > 0 ) {
                // translators: 1: number of approved reviews, 2: number of pending reviews
                $reviews_line .= sprintf( esc_html__( '%1$d Approved, %2$d Pending', 'simple-points-and-rewards' ), $approved_reviews, $pending_reviews );
            } elseif ( $pending_reviews > 0 ) {
                // translators: %d: number of pending reviews
                $reviews_line .= sprintf( esc_html__( '%d Pending approval', 'simple-points-and-rewards' ), $pending_reviews );
            } elseif ( $approved_reviews > 0 ) {
                // translators: %d: number of approved reviews
                $reviews_line .= sprintf( esc_html__( '%d Approved', 'simple-points-and-rewards' ), $approved_reviews );
            } else {
                $reviews_line .= esc_html__( 'No reviews yet', 'simple-points-and-rewards' );
            }
            if ( $limit > 0 ) {
                $total_written = $approved_reviews + $pending_reviews;
                $reviews_line .= ' (' . $total_written . ' / ' . (int) $limit . ')';
            }
            echo '<div class="spar-review-progress">' . esc_html( $reviews_line ) . '</div>';
            if ( !empty( $purchased_products_status ) ) {
                ?>
								<div id="spar-review-products-modal" class="spar-review-products-modal" role="dialog" aria-modal="true" aria-labelledby="spar-review-products-modal-title" aria-hidden="true">
									<div class="spar-review-products-modal-overlay"></div>
									<div class="spar-review-products-modal-dialog">
										<div class="spar-review-products-modal-header">
											<h3 id="spar-review-products-modal-title" class="spar-review-products-modal-title"><?php 
                esc_html_e( 'Products to Review', 'simple-points-and-rewards' );
                ?></h3>
											<button type="button" class="spar-review-products-modal-close" aria-label="<?php 
                esc_attr_e( 'Close', 'simple-points-and-rewards' );
                ?>">&times;</button>
										</div>
										<div class="spar-review-products-modal-body">
											<ul class="spar-review-products-list">
												<?php 
                foreach ( $purchased_products_status as $entry ) {
                    $product = ( isset( $entry['product'] ) ? $entry['product'] : null );
                    if ( !$product || !is_a( $product, 'WC_Product' ) ) {
                        continue;
                    }
                    $status = ( isset( $entry['status'] ) ? $entry['status'] : 'available' );
                    $review_url = get_permalink( $product->get_id() ) . '#tab-reviews';
                    ?>
													<li class="spar-review-product spar-review-product--<?php 
                    echo esc_attr( $status );
                    ?>">
														<span class="spar-review-product-name"><?php 
                    echo esc_html( $product->get_name() );
                    ?></span>
														<?php 
                    if ( 'earned' === $status ) {
                        ?>
															<span class="spar-review-product-status spar-review-product-status--earned"><?php 
                        esc_html_e( 'Points earned', 'simple-points-and-rewards' );
                        ?> <i class="fa-solid fa-circle-check" aria-hidden="true"></i></span>
														<?php 
                    } elseif ( 'pending' === $status ) {
                        ?>
															<span class="spar-review-product-status spar-review-product-status--pending"><?php 
                        esc_html_e( 'Review pending approval', 'simple-points-and-rewards' );
                        ?></span>
														<?php 
                    } else {
                        ?>
															<a class="spar-review-product-status spar-review-product-status--available" href="<?php 
                        echo esc_url( $review_url );
                        ?>"><?php 
                        esc_html_e( 'Write a review', 'simple-points-and-rewards' );
                        ?></a>
														<?php 
                    }
                    ?>
													</li>
													<?php 
                }
                ?>
											</ul>
										</div>
									</div>
								</div>
								<?php 
            }
            ?>
				</div>
			</div>
		<?php 
        }
        $earn_blocks['review'] = ob_get_clean();
        // ---- birthday ----
        ob_start();
        if ( spar_fs()->can_use_premium_code__premium_only() && !empty( $options_earn['birthday']['enabled'] ) && !in_array( 'birthday', $hidden_ways, true ) ) {
            ?>
			<div class="spar-box-full" data-earn-type="birthday">
				<div>
					<span class="spar-earn-fa-icon"><i class="fa-solid fa-cake-candles" aria-hidden="true"></i></span>
					<strong><?php 
            echo esc_html( $options_earn['birthday']['name'] ?? esc_html__( 'Birthday Bonus', 'simple-points-and-rewards' ) );
            ?></strong>
					<?php 
            if ( !$is_guest ) {
                ?>
						<br/>
						<input type="date" id="spar-birthday-date" class="spar-birthday-date"
						value="<?php 
                echo esc_attr( $birthday_saved_ymd );
                ?>" />
						<span class="spar-birthday-status" aria-live="polite"></span>
					<?php 
            }
            ?>
				</div>
				<div class="spar-points-info">
					<?php 
            echo esc_html( spar_get_points_prefix() . (int) ($options_earn['birthday']['points'] ?? 100) );
            ?> <?php 
            echo esc_html( $points_label );
            ?> - <?php 
            esc_html_e( 'once per year', 'simple-points-and-rewards' );
            ?>
					<?php 
            // Show last rewarded date only if we have the exact stored date from the award time
            $birthday_last_awarded_date = (string) get_user_meta( $user_id, '_spar_birthday_last_granted_date', true );
            if ( !empty( $birthday_last_awarded_date ) && strlen( $birthday_last_awarded_date ) >= 10 ) {
                $timestamp = strtotime( substr( $birthday_last_awarded_date, 0, 10 ) . ' 00:00:00' );
                if ( $timestamp ) {
                    $date_format = get_option( 'date_format', 'F j, Y' );
                    $formatted = ( function_exists( 'wp_date' ) ? wp_date( $date_format, $timestamp, wp_timezone() ) : date_i18n( $date_format, $timestamp ) );
                    echo '<div class="spar-birthday-last-rewarded" style="font-size:12px; opacity:0.8; margin-top:2px;">' . sprintf( esc_html__( 'Last rewarded on %s', 'simple-points-and-rewards' ), esc_html( $formatted ) ) . '</div>';
                }
            } elseif ( empty( $birthday_last_awarded_year ) ) {
                // No previous awards
                echo '<div class="spar-birthday-last-rewarded" style="font-size:12px; opacity:0.8; margin-top:2px;">' . esc_html__( 'Not rewarded yet.', 'simple-points-and-rewards' ) . '</div>';
            }
            ?>
				</div>
			</div>
		<?php 
        }
        $earn_blocks['birthday'] = ob_get_clean();
        // ---- daily_login ----
        ob_start();
        if ( spar_fs()->can_use_premium_code__premium_only() && !empty( $options_earn['daily_login']['enabled'] ) && !in_array( 'daily_login', $hidden_ways, true ) && !in_array( 'daily_login_streak', $hidden_ways, true ) ) {
            ?>
			<div class="spar-box-full" data-earn-type="daily_login">
				<div>
					<span class="spar-earn-fa-icon"><i class="fa-solid fa-calendar-check" aria-hidden="true"></i></span>
					<strong><?php 
            echo esc_html( $options_earn['daily_login']['name'] ?? esc_html__( 'Daily Login Bonus', 'simple-points-and-rewards' ) );
            ?></strong>
					<?php 
            $last_login_award = get_user_meta( $user_id, '_spar_last_daily_login_award', true );
            $today = current_time( 'Y-m-d' );
            if ( $last_login_award === $today ) {
                ?>
						<span style="color: green; margin-left: 10px;"><i class="fa-solid fa-circle-check" aria-hidden="true"></i></span>
					<?php 
            }
            ?>
				</div>
				<div class="spar-points-info">
					<?php 
            echo esc_html( spar_get_points_prefix() . (int) ($options_earn['daily_login']['points'] ?? 10) );
            ?> <?php 
            echo esc_html( $points_label );
            ?> - <?php 
            esc_html_e( 'once per day', 'simple-points-and-rewards' );
            ?>
					<?php 
            if ( !empty( $options_earn['daily_login']['streak_enabled'] ) ) {
                $streak_days = (int) ($options_earn['daily_login']['streak_days'] ?? 7);
                $streak_points = (int) ($options_earn['daily_login']['streak_points'] ?? 50);
                $current_streak = (int) get_user_meta( $user_id, '_spar_daily_login_streak', true );
                echo '<br/><span class="spar-streak-info" style="font-size: 12px; opacity: 0.8;">';
                printf(
                    esc_html__( 'Current Streak: %d days. Reach %d days for %s bonus points!', 'simple-points-and-rewards' ),
                    (int) $current_streak,
                    (int) $streak_days,
                    esc_html( spar_get_points_prefix() . (int) $streak_points )
                );
                echo '</span>';
            }
            ?>
				</div>
			</div>
		<?php 
        }
        $earn_blocks['daily_login'] = ob_get_clean();
        // ---- referral ----
        ob_start();
        if ( !empty( $options_earn['referral']['enabled'] ) && !in_array( 'referral', $hidden_ways, true ) ) {
            ?>
		<div class="spar-box-full" data-earn-type="referral">
			<div>
				<span class="spar-earn-fa-icon"><i class="fa-solid fa-user-group" aria-hidden="true"></i></span>
				<strong><?php 
            echo esc_html( $options_earn['referral']['name'] ?? esc_html__( 'Refer a Friend', 'simple-points-and-rewards' ) );
            ?></strong>
			</div>
			<div class="spar-points-info">
				<?php 
            $earning_type = $options_earn['referral']['earning_type'] ?? 'fixed';
            if ( $earning_type === 'fixed' ) {
                $fixed_points = (int) ($options_earn['referral']['fixed_points'] ?? 100);
                echo esc_html( spar_get_points_prefix() . $fixed_points ) . ' ' . esc_html( $points_label ) . ' ' . esc_html__( 'per referral', 'simple-points-and-rewards' );
            } else {
                echo esc_html( $options_earn['referral']['percentage'] );
                ?>% <?php 
                esc_html_e( 'of order total', 'simple-points-and-rewards' );
            }
            ?>
			</div>
		</div>
		
		<?php 
            // Referral system details in a separate section
            $options = get_option( 'spar_options', [] );
            $referral_enabled = !empty( $options['earn']['referral']['enabled'] );
            if ( $referral_enabled ) {
                $user_id = get_current_user_id();
                $referral_stats = spar_get_user_referral_stats( $user_id );
                $points_label = $options['points_label'] ?? esc_html__( 'Points', 'simple-points-and-rewards' );
                $percentage = (float) ($options['earn']['referral']['percentage'] ?? 10);
                $points_per = (float) ($options['earn']['referral']['points_per'] ?? 5);
                $referral_url = spar_generate_referral_url( $user_id );
                $referral_options = ( isset( $options['earn']['referral'] ) && is_array( $options['earn']['referral'] ) ? $options['earn']['referral'] : [] );
                $social_enabled = ( array_key_exists( 'social_enabled', $referral_options ) ? !empty( $referral_options['social_enabled'] ) : true );
                $social_email_enabled = ( array_key_exists( 'social_email_enabled', $referral_options ) ? !empty( $referral_options['social_email_enabled'] ) : true );
                $social_fb_enabled = ( array_key_exists( 'social_facebook_enabled', $referral_options ) ? !empty( $referral_options['social_facebook_enabled'] ) : true );
                $social_tw_enabled = ( array_key_exists( 'social_twitter_enabled', $referral_options ) ? !empty( $referral_options['social_twitter_enabled'] ) : true );
                $social_wa_enabled = ( array_key_exists( 'social_whatsapp_enabled', $referral_options ) ? !empty( $referral_options['social_whatsapp_enabled'] ) : true );
                $social_tg_enabled = ( array_key_exists( 'social_telegram_enabled', $referral_options ) ? !empty( $referral_options['social_telegram_enabled'] ) : false );
                $social_dc_enabled = ( array_key_exists( 'social_discord_enabled', $referral_options ) ? !empty( $referral_options['social_discord_enabled'] ) : false );
                $social_tt_enabled = ( array_key_exists( 'social_tiktok_enabled', $referral_options ) ? !empty( $referral_options['social_tiktok_enabled'] ) : false );
                $social_share_text = ( isset( $referral_options['social_share_text'] ) && '' !== $referral_options['social_share_text'] ? $referral_options['social_share_text'] : esc_html__( 'Check out this great store!', 'simple-points-and-rewards' ) );
                ?>

				<?php 
                if ( !$is_guest ) {
                    ?>
				<div class="spar-referral-stats">
					<div class="spar-referral-stat">
						<span class="spar-stat-number"><?php 
                    echo esc_html( $referral_stats['total_clicks'] ?? 0 );
                    ?></span>
						<span class="spar-stat-label"><?php 
                    esc_html_e( 'Link Clicks', 'simple-points-and-rewards' );
                    ?></span>
					</div>
					<div class="spar-referral-stat">
						<span class="spar-stat-number"><?php 
                    echo esc_html( $referral_stats['successful_referrals'] );
                    ?></span>
						<span class="spar-stat-label"><?php 
                    esc_html_e( 'Friends Referred', 'simple-points-and-rewards' );
                    ?></span>
					</div>
					<div class="spar-referral-stat">
						<span class="spar-stat-number"><?php 
                    $clicks = $referral_stats['total_clicks'] ?? 0;
                    $conversions = $referral_stats['successful_referrals'];
                    $conversion_rate = ( $clicks > 0 ? round( $conversions / $clicks * 100, 1 ) : 0 );
                    echo esc_html( $conversion_rate . '%' );
                    ?></span>
						<span class="spar-stat-label"><?php 
                    esc_html_e( 'Conversion Rate', 'simple-points-and-rewards' );
                    ?></span>
					</div>
					<div class="spar-referral-stat">
						<span class="spar-stat-number"><?php 
                    echo esc_html( spar_format_points_value( (int) $referral_stats['total_points_earned'] ) );
                    ?></span>
						<span class="spar-stat-label"><?php 
                    /* translators: %s: points label */
                    printf( esc_html__( '%s', 'simple-points-and-rewards' ), esc_html( $points_label ) );
                    ?></span>
					</div>
				</div>
				
				<div class="spar-referral-how-it-works">
					<h4><?php 
                    esc_html_e( 'How it works:', 'simple-points-and-rewards' );
                    ?></h4>
					<ol class="spar-how-it-works-list">
						<li><?php 
                    esc_html_e( 'Share your unique referral link with friends', 'simple-points-and-rewards' );
                    ?></li>
						<?php 
                    if ( !empty( $options['earn']['referral']['offer_enabled'] ) ) {
                        $offer_type = $options['earn']['referral']['offer_type'] ?? 'discount';
                        $offer_value = (float) ($options['earn']['referral']['offer_value'] ?? 10);
                        $offer_text = $options['earn']['referral']['offer_text'] ?? esc_html__( 'Get {value} off your order!', 'simple-points-and-rewards' );
                        if ( $offer_type === 'discount' ) {
                            $display_value = $offer_value . '%';
                        } else {
                            $display_value = esc_html__( 'Free Shipping', 'simple-points-and-rewards' );
                        }
                        $formatted_offer_text = str_replace( '{value}', $display_value, $offer_text );
                        // Check if personalization is enabled
                        $personalize_enabled = !empty( $options['earn']['referral']['gift_widget_personalize'] );
                        $gift_message = ( $personalize_enabled ? esc_html__( 'They get access to your gift discount', 'simple-points-and-rewards' ) : esc_html__( 'They then get a gift coupon to use', 'simple-points-and-rewards' ) );
                        ?>
							<li><?php 
                        /* translators: 1: gift message, 2: formatted offer text */
                        $gift_line = sprintf( 
                            /* translators: 1: gift message, 2: formatted offer text */
                            esc_html__( '%1$s: %2$s', 'simple-points-and-rewards' ),
                            esc_html( $gift_message ),
                            '<strong>' . esc_html( $formatted_offer_text ) . '</strong>'
                         );
                        echo wp_kses_post( $gift_line );
                        ?></li>
						<?php 
                    }
                    ?>
						<li><?php 
                    esc_html_e( 'When they make a purchase, you earn points!', 'simple-points-and-rewards' );
                    ?></li>
					</ol>
				</div>
				
				<div class="spar-referral-fields-row">
					<div class="spar-referral-link-section spar-referral-field-col">
						<h4><?php 
                    esc_html_e( 'Your Referral Link:', 'simple-points-and-rewards' );
                    ?></h4>
						
						<div class="spar-referral-link-container">
							<input type="text" id="spar-referral-link" class="spar-referral-input" value="<?php 
                    echo esc_attr( $referral_url );
                    ?>" readonly />
							<button type="button" id="spar-copy-referral-link" class="spar-copy-button"><?php 
                    esc_html_e( 'Copy', 'simple-points-and-rewards' );
                    ?></button>
						</div>
					</div>

				<?php 
                    ?>
				</div><!-- /.spar-referral-fields-row -->

				<div class="spar-referral-share-section">
					<?php 
                    $social_points_enabled = false;
                    $social_points = 0;
                    $social_limit_mode = 'per_social';
                    $social_rewards_exhausted = false;
                    if ( function_exists( 'spar_has_exhausted_social_share_rewards' ) ) {
                        $social_rewards_exhausted = spar_has_exhausted_social_share_rewards( get_current_user_id(), $social_limit_mode );
                    }
                    // For per_social mode, the global check always returns false; check all enabled networks individually
                    if ( !$social_rewards_exhausted && 'per_social' === $social_limit_mode && function_exists( 'spar_has_exhausted_social_share_rewards' ) ) {
                        $_enabled_networks = [];
                        if ( $social_email_enabled ) {
                            $_enabled_networks[] = 'email';
                        }
                        if ( $social_fb_enabled ) {
                            $_enabled_networks[] = 'facebook';
                        }
                        if ( $social_tw_enabled ) {
                            $_enabled_networks[] = 'twitter';
                        }
                        if ( $social_wa_enabled ) {
                            $_enabled_networks[] = 'whatsapp';
                        }
                        if ( $social_tg_enabled ) {
                            $_enabled_networks[] = 'telegram';
                        }
                        if ( $social_dc_enabled ) {
                            $_enabled_networks[] = 'discord';
                        }
                        if ( $social_tt_enabled ) {
                            $_enabled_networks[] = 'tiktok';
                        }
                        if ( !empty( $_enabled_networks ) ) {
                            $_all_exhausted = true;
                            $_current_uid = get_current_user_id();
                            foreach ( $_enabled_networks as $_net ) {
                                if ( !spar_has_exhausted_social_share_rewards( $_current_uid, $social_limit_mode, $_net ) ) {
                                    $_all_exhausted = false;
                                    break;
                                }
                            }
                            if ( $_all_exhausted ) {
                                $social_rewards_exhausted = true;
                            }
                        }
                    }
                    ?>
					<?php 
                    if ( $social_enabled ) {
                        ?>
						<div class="spar-share-buttons">
							<div class="spar-share-header-row" style="display:flex; justify-content:space-between; align-items:center;">
								<h5><?php 
                        esc_html_e( 'Share via:', 'simple-points-and-rewards' );
                        ?></h5>
								<?php 
                        if ( $social_points_enabled && !$social_rewards_exhausted && $social_points > 0 ) {
                            ?>
									<div class="spar-points-info"><?php 
                            printf( esc_html__( 'Earn %s Points instantly for sharing', 'simple-points-and-rewards' ), esc_html( spar_get_points_prefix() . (int) $social_points ) );
                            ?></div>
								<?php 
                        }
                        ?>
							</div>
							<div class="spar-share-buttons-grid">
								<?php 
                        if ( $social_email_enabled ) {
                            $is_email_exhausted = $social_rewards_exhausted;
                            if ( !$is_email_exhausted && function_exists( 'spar_has_exhausted_social_share_rewards' ) ) {
                                $is_email_exhausted = spar_has_exhausted_social_share_rewards( get_current_user_id(), $social_limit_mode, 'email' );
                            }
                            ?>
									<a href="mailto:hi@xample.com?subject=<?php 
                            echo esc_attr( urlencode( esc_attr__( 'Check out this great store!', 'simple-points-and-rewards' ) ) );
                            ?>&body=<?php 
                            echo esc_attr( urlencode( $referral_url ) );
                            ?>" class="spar-share-btn spar-share-email" data-network="email">
										<span class="spar-share-icon"><i class="fas fa-envelope"></i></span>
										<span class="spar-share-label"><?php 
                            esc_html_e( 'Email', 'simple-points-and-rewards' );
                            ?></span>
										<?php 
                            if ( $social_points_enabled && !$is_email_exhausted && $social_points > 0 ) {
                                ?>
											<span class="spar-share-pill">+<?php 
                                echo esc_html( spar_get_points_prefix() . (int) $social_points );
                                ?></span>
										<?php 
                            }
                            ?>
									</a>
								<?php 
                        }
                        ?>
								<?php 
                        if ( $social_fb_enabled ) {
                            $is_fb_exhausted = $social_rewards_exhausted;
                            if ( !$is_fb_exhausted && function_exists( 'spar_has_exhausted_social_share_rewards' ) ) {
                                $is_fb_exhausted = spar_has_exhausted_social_share_rewards( get_current_user_id(), $social_limit_mode, 'facebook' );
                            }
                            ?>
									<a href="https://www.facebook.com/sharer/sharer.php?u=<?php 
                            echo esc_attr( urlencode( $referral_url ) );
                            ?>" target="_blank" rel="noopener" class="spar-share-btn spar-share-facebook" data-network="facebook">
										<span class="spar-share-icon"><i class="fab fa-facebook-f"></i></span>
										<span class="spar-share-label"><?php 
                            esc_html_e( 'Facebook', 'simple-points-and-rewards' );
                            ?></span>
										<?php 
                            if ( $social_points_enabled && !$is_fb_exhausted && $social_points > 0 ) {
                                ?>
											<span class="spar-share-pill">+<?php 
                                echo esc_html( spar_get_points_prefix() . (int) $social_points );
                                ?></span>
										<?php 
                            }
                            ?>
									</a>
								<?php 
                        }
                        ?>
								<?php 
                        if ( $social_tw_enabled ) {
                            $is_tw_exhausted = $social_rewards_exhausted;
                            if ( !$is_tw_exhausted && function_exists( 'spar_has_exhausted_social_share_rewards' ) ) {
                                $is_tw_exhausted = spar_has_exhausted_social_share_rewards( get_current_user_id(), $social_limit_mode, 'twitter' );
                            }
                            ?>
									<a href="https://twitter.com/intent/tweet?url=<?php 
                            echo esc_attr( urlencode( $referral_url ) );
                            ?>&text=<?php 
                            echo esc_attr( urlencode( $social_share_text ) );
                            ?>" target="_blank" rel="noopener" class="spar-share-btn spar-share-twitter" data-network="twitter">
										<span class="spar-share-icon"><i class="fab fa-x-twitter"></i></span>
										<span class="spar-share-label"><?php 
                            esc_html_e( 'X', 'simple-points-and-rewards' );
                            ?></span>
										<?php 
                            if ( $social_points_enabled && !$is_tw_exhausted && $social_points > 0 ) {
                                ?>
											<span class="spar-share-pill">+<?php 
                                echo esc_html( spar_get_points_prefix() . (int) $social_points );
                                ?></span>
										<?php 
                            }
                            ?>
									</a>
								<?php 
                        }
                        ?>
								<?php 
                        if ( $social_wa_enabled ) {
                            $is_wa_exhausted = $social_rewards_exhausted;
                            if ( !$is_wa_exhausted && function_exists( 'spar_has_exhausted_social_share_rewards' ) ) {
                                $is_wa_exhausted = spar_has_exhausted_social_share_rewards( get_current_user_id(), $social_limit_mode, 'whatsapp' );
                            }
                            ?>
									<a href="https://wa.me/?text=<?php 
                            echo esc_attr( urlencode( $social_share_text . ' ' . $referral_url ) );
                            ?>" target="_blank" rel="noopener" class="spar-share-btn spar-share-whatsapp" data-network="whatsapp">
										<span class="spar-share-icon"><i class="fab fa-whatsapp"></i></span>
										<span class="spar-share-label"><?php 
                            esc_html_e( 'WhatsApp', 'simple-points-and-rewards' );
                            ?></span>
										<?php 
                            if ( $social_points_enabled && !$is_wa_exhausted && $social_points > 0 ) {
                                ?>
											<span class="spar-share-pill">+<?php 
                                echo esc_html( spar_get_points_prefix() . (int) $social_points );
                                ?></span>
										<?php 
                            }
                            ?>
									</a>
								<?php 
                        }
                        ?>
								<?php 
                        if ( $social_tg_enabled ) {
                            $is_tg_exhausted = $social_rewards_exhausted;
                            if ( !$is_tg_exhausted && function_exists( 'spar_has_exhausted_social_share_rewards' ) ) {
                                $is_tg_exhausted = spar_has_exhausted_social_share_rewards( get_current_user_id(), $social_limit_mode, 'telegram' );
                            }
                            ?>
									<a href="<?php 
                            echo esc_url( add_query_arg( array(
                                'url'  => $referral_url,
                                'text' => $social_share_text,
                            ), 'https://t.me/share/url' ) );
                            ?>" target="_blank" rel="noopener" class="spar-share-btn spar-share-telegram" data-network="telegram">
										<span class="spar-share-icon"><i class="fab fa-telegram"></i></span>
										<span class="spar-share-label"><?php 
                            esc_html_e( 'Telegram', 'simple-points-and-rewards' );
                            ?></span>
										<?php 
                            if ( $social_points_enabled && !$is_tg_exhausted && $social_points > 0 ) {
                                ?>
											<span class="spar-share-pill">+<?php 
                                echo esc_html( spar_get_points_prefix() . (int) $social_points );
                                ?></span>
										<?php 
                            }
                            ?>
									</a>
								<?php 
                        }
                        ?>
								<?php 
                        if ( $social_dc_enabled ) {
                            $is_dc_exhausted = $social_rewards_exhausted;
                            if ( !$is_dc_exhausted && function_exists( 'spar_has_exhausted_social_share_rewards' ) ) {
                                $is_dc_exhausted = spar_has_exhausted_social_share_rewards( get_current_user_id(), $social_limit_mode, 'discord' );
                            }
                            ?>
									<a href="https://discord.com/channels/@me" target="_blank" rel="noopener" class="spar-share-btn spar-share-discord" data-network="discord">
										<span class="spar-share-icon"><i class="fab fa-discord"></i></span>
										<span class="spar-share-label"><?php 
                            esc_html_e( 'Discord', 'simple-points-and-rewards' );
                            ?></span>
										<?php 
                            if ( $social_points_enabled && !$is_dc_exhausted && $social_points > 0 ) {
                                ?>
											<span class="spar-share-pill">+<?php 
                                echo esc_html( spar_get_points_prefix() . (int) $social_points );
                                ?></span>
										<?php 
                            }
                            ?>
									</a>
								<?php 
                        }
                        ?>
								<?php 
                        if ( $social_tt_enabled ) {
                            $is_tt_exhausted = $social_rewards_exhausted;
                            if ( !$is_tt_exhausted && function_exists( 'spar_has_exhausted_social_share_rewards' ) ) {
                                $is_tt_exhausted = spar_has_exhausted_social_share_rewards( get_current_user_id(), $social_limit_mode, 'tiktok' );
                            }
                            ?>
									<a href="https://www.tiktok.com/upload" target="_blank" rel="noopener" class="spar-share-btn spar-share-tiktok" data-network="tiktok">
										<span class="spar-share-icon"><i class="fab fa-tiktok"></i></span>
										<span class="spar-share-label"><?php 
                            esc_html_e( 'TikTok', 'simple-points-and-rewards' );
                            ?></span>
										<?php 
                            if ( $social_points_enabled && !$is_tt_exhausted && $social_points > 0 ) {
                                ?>
											<span class="spar-share-pill">+<?php 
                                echo esc_html( spar_get_points_prefix() . (int) $social_points );
                                ?></span>
										<?php 
                            }
                            ?>
									</a>
								<?php 
                        }
                        ?>
							</div>
						</div>
					<?php 
                    }
                    ?>

					<?php 
                    $show_clicks_log = !empty( $referral_options['show_clicks_log'] );
                    if ( $show_clicks_log && function_exists( 'spar_get_user_referral_clicks_history' ) ) {
                        $clicks_history = spar_get_user_referral_clicks_history( $user_id, 1, 5 );
                        $clicks_logs = $clicks_history['logs'] ?? [];
                        $clicks_pagination = $clicks_history['pagination'] ?? [];
                        $clicks_rows_html = ( function_exists( 'spar_build_referral_clicks_table_rows' ) ? spar_build_referral_clicks_table_rows( $clicks_logs, 'dashboard' ) : '' );
                        $clicks_pagination_html = ( function_exists( 'spar_build_referral_clicks_pagination_html' ) ? spar_build_referral_clicks_pagination_html( $clicks_pagination, 'dashboard' ) : '' );
                        ?>
						<div class="spar-referral-clicks-log">
							<h4><?php 
                        esc_html_e( 'Link Clicks', 'simple-points-and-rewards' );
                        ?>:</h4>
							<div id="spar-referral-clicks-container">
								<div class="spar-table-scroll">
									<table class="spar-points-log widefat">
										<thead>
											<tr>
												<th><?php 
                        esc_html_e( 'Date', 'simple-points-and-rewards' );
                        ?></th>
												<th><?php 
                        esc_html_e( 'Landing Page', 'simple-points-and-rewards' );
                        ?></th>
												<th><?php 
                        esc_html_e( 'Referring Domain', 'simple-points-and-rewards' );
                        ?></th>
												<th class="spar-th-center"><?php 
                        esc_html_e( 'Converted', 'simple-points-and-rewards' );
                        ?></th>
											</tr>
										</thead>
										<tbody id="spar-referral-clicks-tbody">
											<?php 
                        echo wp_kses_post( $clicks_rows_html );
                        ?>
										</tbody>
									</table>
								</div>
								<div id="spar-referral-clicks-pagination" class="spar-pagination">
									<?php 
                        echo wp_kses_post( $clicks_pagination_html );
                        ?>
								</div>
							</div>
						</div>
					<?php 
                    }
                    ?>
				</div>
				<?php 
                }
                ?>
			<?php 
            }
            ?>
		<?php 
        }
        $earn_blocks['referral'] = ob_get_clean();
        // ---- spin_wheel ----
        ob_start();
        if ( spar_fs()->can_use_premium_code__premium_only() && function_exists( 'spar_get_spin_wheel_settings' ) ) {
            $spin_settings = spar_get_spin_wheel_settings();
            if ( !empty( $spin_settings['enabled'] ) && !in_array( 'spin_wheel', $hidden_ways, true ) ) {
                $spin_can = ( is_user_logged_in() ? spar_user_can_spin_wheel( $user_id ) : false );
                $spin_available = true === $spin_can;
                $spin_message = ( true !== $spin_can && is_string( $spin_can ) ? $spin_can : '' );
                $spin_server_ts = time();
                $spin_next_ts = false;
                $bonus_spins = ( is_user_logged_in() ? (int) get_user_meta( $user_id, '_spar_bonus_spins', true ) : 0 );
                // Compute the next-spin timestamp for the live countdown.
                // Always compute this — even when bonus spins make the button enabled — so the
                // passive "Free spin available in…" countdown can be shown alongside the active button.
                if ( is_user_logged_in() ) {
                    $spin_last = get_user_meta( $user_id, '_spar_last_spin_wheel', true );
                    if ( !empty( $spin_last ) ) {
                        $spin_cd = ( isset( $spin_settings['cooldown'] ) ? $spin_settings['cooldown'] : 'daily' );
                        if ( 'daily' === $spin_cd ) {
                            $spin_next_ts = (int) strtotime( 'tomorrow', $spin_server_ts );
                        } elseif ( 'hours' === $spin_cd ) {
                            $spin_cd_hours = ( isset( $spin_settings['cooldown_hours'] ) ? max( 1, (int) $spin_settings['cooldown_hours'] ) : 24 );
                            $spin_next_ts = (int) $spin_last + $spin_cd_hours * HOUR_IN_SECONDS;
                        } elseif ( 'weekly' === $spin_cd ) {
                            $spin_next_ts = (int) strtotime( 'next monday', $spin_server_ts );
                        }
                        // For 'once' cooldown there is no future timestamp.
                        if ( $spin_next_ts && $spin_next_ts <= $spin_server_ts ) {
                            $spin_next_ts = false;
                        }
                    }
                }
                ?>
			<div class="spar-box-full spar-spin-wheel-earn-card" data-earn-type="spin_wheel">
				<div>
					<span class="spar-earn-fa-icon"><i class="fa-solid fa-dharmachakra" aria-hidden="true"></i></span>
					<?php 
                $spin_name = ( !empty( $spin_settings['name'] ) ? $spin_settings['name'] : __( 'Daily Prize Wheel', 'simple-points-and-rewards' ) );
                ?>
					<strong><?php 
                echo esc_html( $spin_name );
                ?></strong>
					<?php 
                $spin_button_label = ( !empty( $spin_settings['button_text'] ) ? $spin_settings['button_text'] : esc_html__( 'Spin Wheel', 'simple-points-and-rewards' ) );
                ?>
				<?php 
                if ( !$is_guest ) {
                    ?>
					<?php 
                    if ( $spin_available ) {
                        ?>
						<button type="button" class="spar-spin-open-btn"><?php 
                        echo esc_html( $spin_button_label );
                        ?></button>
					<?php 
                    } else {
                        ?>
						<button type="button" class="spar-spin-open-btn" disabled><?php 
                        echo esc_html( $spin_button_label );
                        ?></button>
					<?php 
                    }
                    ?>
				<?php 
                }
                ?>
				</div>
				<div class="spar-points-info">
					<?php 
                $spin_description = ( !empty( $spin_settings['description'] ) ? $spin_settings['description'] : __( 'Spin for a chance to win prizes!', 'simple-points-and-rewards' ) );
                echo esc_html( $spin_description );
                ?>
					<?php 
                if ( !$is_guest ) {
                    ?>
						<?php 
                    if ( $spin_next_ts ) {
                        ?>
							<div class="spar-spin-cooldown-note spar-spin-countdown spar-spin-countdown--passive" data-next-spin="<?php 
                        echo (int) $spin_next_ts;
                        ?>" data-server-ts="<?php 
                        echo (int) $spin_server_ts;
                        ?>"></div>
						<?php 
                    }
                    ?>
						<?php 
                    // Build a list of other ways the user can earn bonus spins.
                    $other_spin_ways = [];
                    if ( !empty( $spin_settings['order_spins_enabled'] ) ) {
                        $amount_per_spin = (float) ($spin_settings['order_spins_amount'] ?? 0);
                        if ( $amount_per_spin > 0 ) {
                            $currency_symbol = ( function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '' );
                            /* translators: 1: currency symbol, 2: amount required per spin */
                            $other_spin_ways[] = sprintf( esc_html__( 'Earn 1 spin per %1$s%2$s spent in an order', 'simple-points-and-rewards' ), esc_html( $currency_symbol ), esc_html( number_format_i18n( $amount_per_spin ) ) );
                        } else {
                            $other_spin_ways[] = esc_html__( 'Earn 1 spin per order', 'simple-points-and-rewards' );
                        }
                    }
                    if ( !empty( $spin_settings['level_up_spins_enabled'] ) ) {
                        $level_up_spins = max( 1, (int) ($spin_settings['level_up_spins_amount'] ?? 1) );
                        $other_spin_ways[] = sprintf( 
                            /* translators: %d: number of spins awarded on level up */
                            _n(
                                'Earn %d spin on level up',
                                'Earn %d spins on level up',
                                $level_up_spins,
                                'simple-points-and-rewards'
                            ),
                            $level_up_spins
                         );
                    }
                    ?>
							<?php 
                    if ( !empty( $other_spin_ways ) ) {
                        ?>
								<?php 
                        foreach ( $other_spin_ways as $way ) {
                            ?>
									<div class="spar-spin-cooldown-note spar-spin-countdown--passive"><?php 
                            echo esc_html( $way );
                            ?></div>
								<?php 
                        }
                        ?>
							<?php 
                    }
                    ?>
							<?php 
                    if ( !empty( $spin_settings['buy_spins_enabled'] ) ) {
                        $buy_cost = max( 1, (int) ($spin_settings['buy_spins_points_cost'] ?? 100) );
                        $_pts_label = strtolower( ( spar_get_single_option( 'points_label' ) ?: esc_html__( 'Points', 'simple-points-and-rewards' ) ) );
                        // Wrap the cost number in a <span> so the multiplier regex (which uses \s* between
                        // number and label) cannot match across the tag boundary, preventing incorrect
                        // multiplication of the spin cost in the Earn Points tab.
                        // The link sends users to the Claim Rewards tab's buy-spins section.
                        $buy_spins_earn_html = sprintf(
                            /* translators: 1: opening anchor tag, 2: closing anchor tag, 3: cost number span, 4: points label */
                            __( '%1$sClaim spins%2$s for <span class="spar-spin-buy-cost">%3$d</span>&#32;%4$s each', 'simple-points-and-rewards' ),
                            '<a href="#" class="spar-earn-buy-spins-link" data-spar-tab="claim" data-spar-anchor="spar-buy-spins-anchor">',
                            '</a>',
                            $buy_cost,
                            esc_html( $_pts_label )
                        );
                        ?>
							<div class="spar-spin-cooldown-note spar-spin-countdown--passive"><?php 
                        echo wp_kses( $buy_spins_earn_html, array(
                            'a'    => array(
                                'href'             => true,
                                'class'            => true,
                                'data-spar-tab'    => true,
                                'data-spar-anchor' => true,
                            ),
                            'span' => array(
                                'class' => true,
                            ),
                        ) );
                        ?></div>
						<?php 
                    }
                    ?>
						<?php 
                    $total_spins = (int) get_user_meta( $user_id, '_spar_spin_wheel_count', true );
                    // Total available = order bonus spins + 1 if the free scheduled spin is ready.
                    $has_free_scheduled_spin = function_exists( 'spar_user_has_free_scheduled_spin' ) && spar_user_has_free_scheduled_spin( $user_id );
                    $available_spins = $bonus_spins + (( $has_free_scheduled_spin ? 1 : 0 ));
                    ?>
						<div class="spar-spin-total-count" style="font-size: 12px; margin-top: 2px;">
							<span id="spar-spin-available-badge"><?php 
                    echo (int) $available_spins;
                    ?> <?php 
                    echo esc_html( ( $available_spins === 1 ? __( 'spin available', 'simple-points-and-rewards' ) : __( 'spins available', 'simple-points-and-rewards' ) ) );
                    ?></span>
							<?php 
                    if ( $total_spins > 0 ) {
                        ?>
								&middot; <?php 
                        /* translators: %d: number of total spins */
                        printf( esc_html__( 'Total spins: %d', 'simple-points-and-rewards' ), (int) $total_spins );
                        ?>
							<?php 
                    }
                    ?>
						</div>
					<?php 
                }
                ?>
				</div>
			</div>
		<?php 
            }
        }
        $earn_blocks['spin_wheel'] = ob_get_clean();
        // ---- Custom ways to earn (PRO) ----
        if ( function_exists( 'sparp_cwe_get_frontend_blocks__premium_only' ) ) {
            $cwe_blocks = sparp_cwe_get_frontend_blocks__premium_only( $user_id, $is_guest, $points_label );
            foreach ( $cwe_blocks as $cwe_id => $cwe_html ) {
                $earn_blocks[$cwe_id] = $cwe_html;
                if ( !in_array( $cwe_id, $earn_ways_order, true ) ) {
                    $earn_ways_order[] = $cwe_id;
                }
            }
        }
        // ---- Product Offers & Bonuses (PRO) ----
        if ( function_exists( 'sparp_bp_get_frontend_blocks__premium_only' ) ) {
            $bp_blocks = sparp_bp_get_frontend_blocks__premium_only( $user_id, $is_guest, $points_label );
            foreach ( $bp_blocks as $bp_id => $bp_html ) {
                $earn_blocks[$bp_id] = $bp_html;
                if ( !in_array( $bp_id, $earn_ways_order, true ) ) {
                    $earn_ways_order[] = $bp_id;
                }
            }
        }
        // ---- Render grid in saved order ----
        ?>
<div class="spar-earn-grid">
		<?php 
        foreach ( $earn_ways_order as $_way_key ) {
            if ( !empty( $earn_blocks[$_way_key] ) ) {
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped in each block
                echo $earn_blocks[$_way_key];
            }
        }
        ?>
</div>

<div style="clear: both;"></div>
<?php 
    }

}
spar_render_ways_to_earn_template();