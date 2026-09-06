<?php

/**
 * My Account: Rewards endpoint and menu integration
 */
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Add "Rewards" item to WooCommerce My Account menu.
 * Respects the setting spar_options[show_rewards_in_my_account].
 */
add_filter( 'woocommerce_account_menu_items', 'spar_add_rewards_menu_item' );
function spar_add_rewards_menu_item(  $items  ) {
    // Ensure options exist with defaults
    $options = get_option( 'spar_options', ( function_exists( 'spar_settings_default' ) ? spar_settings_default() : array() ) );
    $show = !empty( $options['show_rewards_in_my_account'] );
    // Do not show menu item for banned users
    if ( is_user_logged_in() ) {
        $status = get_user_meta( get_current_user_id(), 'spar_user_status', true );
        if ( 'banned' === $status ) {
            $show = false;
        }
    }
    // Remove existing to avoid duplicates (if any)
    if ( isset( $items['rewards'] ) ) {
        unset($items['rewards']);
    }
    if ( !$show ) {
        return $items;
        // Setting disabled → do not add
    }
    // Determine menu label
    $label = ( isset( $options['rewards_label'] ) && $options['rewards_label'] !== '' ? $options['rewards_label'] : esc_html__( 'Rewards', 'simple-points-and-rewards' ) );
    // Allow filtering of the menu label
    $label = sanitize_text_field( apply_filters( 'spar_rewards_menu_label', $label, $items ) );
    // Insert before the logout item when possible
    $new_items = array();
    foreach ( $items as $key => $text ) {
        if ( 'customer-logout' === $key ) {
            $new_items['rewards'] = esc_html( $label );
        }
        $new_items[$key] = $text;
    }
    // If no logout item was found, append at end
    if ( !isset( $new_items['rewards'] ) ) {
        $new_items['rewards'] = esc_html( $label );
    }
    // Give developers a chance to adjust the final menu set
    $filtered_items = apply_filters( 'spar_account_menu_items', $new_items );
    // Sanitize labels defensively; keys come from Woo and remain unchanged
    if ( is_array( $filtered_items ) ) {
        foreach ( $filtered_items as $k => $text ) {
            if ( is_string( $text ) ) {
                $filtered_items[$k] = sanitize_text_field( $text );
            }
        }
    } else {
        $filtered_items = $new_items;
    }
    return $filtered_items;
}

/**
 * Returns the icon emoji and CSS colour class for a given history action_id.
 *
 * @param string $action_id
 * @return array { icon: string, color_class: string }
 */
function spar_get_history_timeline_icon(  $action_id  ) {
    $map = [
        'signup'             => [
            'icon'        => '🎉',
            'color_class' => 'spar-tl-purple',
        ],
        'order'              => [
            'icon'        => '🛍️',
            'color_class' => 'spar-tl-green',
        ],
        'first_order'        => [
            'icon'        => '🛍️',
            'color_class' => 'spar-tl-green',
        ],
        'nth_order'          => [
            'icon'        => '🛍️',
            'color_class' => 'spar-tl-green',
        ],
        'order_refund'       => [
            'icon'        => '↩️',
            'color_class' => 'spar-tl-red',
        ],
        'order_cancelled'    => [
            'icon'        => '✖️',
            'color_class' => 'spar-tl-red',
        ],
        'order_failed'       => [
            'icon'        => '✖️',
            'color_class' => 'spar-tl-red',
        ],
        'referral'           => [
            'icon'        => '🔗',
            'color_class' => 'spar-tl-blue',
        ],
        'referral_refund'    => [
            'icon'        => '↩️',
            'color_class' => 'spar-tl-red',
        ],
        'referral_cancelled' => [
            'icon'        => '✖️',
            'color_class' => 'spar-tl-red',
        ],
        'referral_failed'    => [
            'icon'        => '✖️',
            'color_class' => 'spar-tl-red',
        ],
        'review'             => [
            'icon'        => '⭐',
            'color_class' => 'spar-tl-amber',
        ],
        'redeem'             => [
            'icon'        => '🎁',
            'color_class' => 'spar-tl-indigo',
        ],
        'redeem_refund'      => [
            'icon'        => '↩️',
            'color_class' => 'spar-tl-gray',
        ],
        'admin_adjustment'   => [
            'icon'        => '⚙️',
            'color_class' => 'spar-tl-gray',
        ],
        'admin_set_balance'  => [
            'icon'        => '⚙️',
            'color_class' => 'spar-tl-gray',
        ],
        'spin_wheel'         => [
            'icon'        => '🎡',
            'color_class' => 'spar-tl-purple',
        ],
        'birthday'           => [
            'icon'        => '🎂',
            'color_class' => 'spar-tl-pink',
        ],
        'daily_login'        => [
            'icon'        => '✅',
            'color_class' => 'spar-tl-teal',
        ],
        'link_click'         => [
            'icon'        => '🔗',
            'color_class' => 'spar-tl-blue',
        ],
    ];
    return $map[$action_id] ?? [
        'icon'        => '💰',
        'color_class' => 'spar-tl-green',
    ];
}

/**
 * Builds a single timeline item HTML string for the points history.
 *
 * @param array  $log          Log entry with keys: action, type, action_id, points, date.
 * @param string $points_label Human-readable points label (e.g. "Points").
 * @return string
 */
function spar_build_history_timeline_item(  $log, $points_label  ) {
    $action_id = ( isset( $log['action_id'] ) ? $log['action_id'] : '' );
    $icon_data = spar_get_history_timeline_icon( $action_id );
    $icon = $icon_data['icon'];
    $color_class = $icon_data['color_class'];
    $log_type = ( isset( $log['type'] ) ? (string) $log['type'] : 'add' );
    $is_pending = 'pending' === $log_type;
    $is_add = 'add' === $log_type || $is_pending;
    $points_sign = ( $is_add ? '+' : '-' );
    $points_abs = abs( (int) $log['points'] );
    $badge_class = ( $is_pending ? 'spar-tl-badge spar-tl-badge--pending' : (( $is_add ? 'spar-tl-badge spar-tl-badge--add' : 'spar-tl-badge spar-tl-badge--remove' )) );
    $time_str = date_i18n( 'g:i A', strtotime( $log['date'] ) );
    if ( $is_pending ) {
        $icon = '⏳';
        $color_class = 'spar-tl-amber';
    }
    $action_display = ( 'spin_wheel' === $action_id ? esc_html__( 'Prize Wheel', 'simple-points-and-rewards' ) . ': ' . esc_html( $log['action'] ) : esc_html( $log['action'] ) );
    $available_html = '';
    if ( $is_pending && !empty( $log['available_at'] ) ) {
        $available_ts = strtotime( $log['available_at'] );
        $available_at = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) . ' (T)', $available_ts );
        $available_html = '<span class="spar-tl-available spar-pending-countdown" data-available-at="' . esc_attr( (string) $available_ts ) . '" title="' . esc_attr( $available_at ) . '">Available in …</span>';
    }
    /* translators: %s: points label */
    $pts_label = esc_html( $points_label );
    // For order-based entries, parse the order ID from the action note and
    // fetch order stats (subtotal, discount, total) to display in the card.
    $order_stats_html = '';
    $order_action_ids = [
        'order',
        'first_order',
        'nth_order',
        'order_refund',
        'order_cancelled',
        'order_failed',
        'referral',
        'referral_refund',
        'referral_cancelled',
        'referral_failed'
    ];
    if ( in_array( $action_id, $order_action_ids, true ) && function_exists( 'wc_get_order' ) ) {
        if ( preg_match( '/#(\\d+)/i', $log['action'], $matches ) ) {
            $order = wc_get_order( (int) $matches[1] );
            if ( $order ) {
                $subtotal = (float) $order->get_subtotal();
                $discount = (float) $order->get_discount_total();
                $total = (float) $order->get_total();
                $currency = ( method_exists( $order, 'get_currency' ) ? $order->get_currency() : '' );
                $fmt_subtotal = ( function_exists( 'wc_price' ) ? wc_price( $subtotal, [
                    'currency' => $currency,
                ] ) : number_format( $subtotal, 2 ) );
                $fmt_total = ( function_exists( 'wc_price' ) ? wc_price( $total, [
                    'currency' => $currency,
                ] ) : number_format( $total, 2 ) );
                $order_stats_html = '<div class="spar-tl-order-stats">';
                $order_stats_html .= '<span class="spar-tl-stat">';
                $order_stats_html .= '<span class="spar-tl-stat-label">' . esc_html__( 'Subtotal', 'simple-points-and-rewards' ) . '</span>';
                $order_stats_html .= '<span class="spar-tl-stat-value">' . wp_kses_post( $fmt_subtotal ) . '</span>';
                $order_stats_html .= '</span>';
                if ( $discount > 0 ) {
                    $fmt_discount = ( function_exists( 'wc_price' ) ? wc_price( $discount, [
                        'currency' => $currency,
                    ] ) : number_format( $discount, 2 ) );
                    $order_stats_html .= '<span class="spar-tl-stat spar-tl-stat--discount">';
                    $order_stats_html .= '<span class="spar-tl-stat-label">' . esc_html__( 'Discount', 'simple-points-and-rewards' ) . '</span>';
                    $order_stats_html .= '<span class="spar-tl-stat-value">-' . wp_kses_post( $fmt_discount ) . '</span>';
                    $order_stats_html .= '</span>';
                }
                $order_stats_html .= '<span class="spar-tl-stat spar-tl-stat--total">';
                $order_stats_html .= '<span class="spar-tl-stat-label">' . esc_html__( 'Total', 'simple-points-and-rewards' ) . '</span>';
                $order_stats_html .= '<span class="spar-tl-stat-value">' . wp_kses_post( $fmt_total ) . '</span>';
                $order_stats_html .= '</span>';
                $order_stats_html .= '</div>';
            }
        }
    }
    $html = '<div class="spar-tl-item">';
    $html .= '<div class="spar-tl-dot ' . esc_attr( $color_class ) . '">';
    $html .= '<span class="spar-tl-icon">' . $icon . '</span>';
    $html .= '</div>';
    $html .= '<div class="spar-tl-card' . (( $order_stats_html ? ' spar-tl-card--has-stats' : '' )) . '">';
    $html .= '<div class="spar-tl-card-left">';
    $html .= '<span class="spar-tl-title">' . $action_display . '</span>';
    $html .= '<span class="spar-tl-time">' . esc_html( $time_str ) . '</span>';
    if ( $available_html ) {
        $html .= $available_html;
    }
    if ( $order_stats_html ) {
        $html .= $order_stats_html;
    }
    $html .= '</div>';
    $html .= '<span class="' . esc_attr( $badge_class ) . '">' . spar_get_points_icon_html( 'inline' ) . esc_html( $points_sign . spar_format_points_value( $points_abs ) ) . ' ' . $pts_label . '</span>';
    $html .= '</div>';
    $html .= '</div>';
    return $html;
}

add_action( 'woocommerce_account_rewards_endpoint', 'spar_rewards_tab_content' );
function spar_rewards_tab_content() {
    $user_id = get_current_user_id();
    $is_guest = 0 === (int) $user_id;
    // Block banned users from viewing the rewards dashboard
    if ( !$is_guest ) {
        $status = get_user_meta( $user_id, 'spar_user_status', true );
        if ( 'banned' === $status ) {
            echo '<div class="spar-rewards-banned"><p>' . esc_html__( 'Your account is banned from the rewards program.', 'simple-points-and-rewards' ) . '</p></div>';
            return;
        }
    }
    if ( !$is_guest ) {
        $last_active_raw = get_user_meta( $user_id, 'spar_last_active', true );
        if ( '' === $last_active_raw || null === $last_active_raw || false === $last_active_raw ) {
            update_user_meta( $user_id, 'spar_last_active', current_time( 'timestamp' ) );
        }
    }
    // Open wrapper for dashboard to increase CSS specificity
    // Apply dark mode classes server-side if default is enabled (prevents flicker)
    $dashboard_classes = array('spar-rewards-dashboard');
    $options = get_option( 'spar_options', spar_settings_default() );
    $dark_mode_default = !empty( $options['dashboard_dark_mode_default'] );
    $dark_mode_header = !empty( $options['dashboard_dark_mode_header'] );
    if ( $dark_mode_default ) {
        $dashboard_classes[] = 'spar-dashboard--dark';
        if ( $dark_mode_header ) {
            $dashboard_classes[] = 'spar-dashboard--dark-header';
        }
    }
    echo '<div class="' . esc_attr( implode( ' ', $dashboard_classes ) ) . '">';
    // Points and totals
    $points = ( $is_guest ? 0 : spar_get_user_points( $user_id ) );
    if ( !$is_guest && spar_fs()->is__premium_only() ) {
        if ( function_exists( 'spar_maybe_award_birthday_points' ) ) {
            // Fallback check: if cron missed, award if birthday occurred within the last 7 days
            spar_maybe_award_birthday_points(
                $user_id,
                null,
                null,
                7
            );
        }
    }
    $points_label = ( isset( $options['points_label'] ) ? $options['points_label'] : '' );
    $points_label_html = ( $points_label !== '' ? esc_html( $points_label ) : esc_html__( 'Points', 'simple-points-and-rewards' ) );
    $points_label_attr = ( $points_label !== '' ? esc_attr( $points_label ) : esc_attr__( 'Points', 'simple-points-and-rewards' ) );
    // Theme colors (not directly used here; kept for compatibility)
    $theme_color_1 = ( isset( $options['rewards_theme_color_1'] ) && is_string( $options['rewards_theme_color_1'] ) && $options['rewards_theme_color_1'] !== '' ? $options['rewards_theme_color_1'] : '#667eea' );
    $theme_color_2 = ( isset( $options['rewards_theme_color_2'] ) && is_string( $options['rewards_theme_color_2'] ) && $options['rewards_theme_color_2'] !== '' ? $options['rewards_theme_color_2'] : '#764ba2' );
    // Load levels functions if needed
    if ( !function_exists( 'spar_get_all_levels' ) ) {
        $levels_functions_path = SPAR_PLUGIN_PATH . 'includes/functions-levels.php';
        if ( file_exists( $levels_functions_path ) ) {
            require_once $levels_functions_path;
        }
    }
    // Derived values
    $levels_enabled = !empty( $options['levels_enabled'] );
    $total_points_earned = ( !$is_guest && function_exists( 'spar_get_user_total_points_earned' ) ? spar_get_user_total_points_earned( $user_id ) : 0 );
    $level_points = ( !$is_guest && function_exists( 'spar_get_user_level_points' ) ? spar_get_user_level_points( $user_id ) : 0 );
    $levels_points_type = ( isset( $options['levels_points_type'] ) ? $options['levels_points_type'] : 'total_earned' );
    // Pending points total (delayed points not yet credited to the balance).
    $pending_points = 0;
    // History data (with nonce-protected pagination)
    if ( $is_guest ) {
        $page = 1;
        $history_per_page = (int) apply_filters( 'spar_points_history_per_page', 10, 0 );
        $logs = array();
        $pagination = array(
            'current_page' => 1,
            'total_pages'  => 1,
            'total_count'  => 0,
            'has_previous' => false,
            'has_next'     => false,
        );
    } else {
        $history_page = filter_input( INPUT_GET, 'history_page', FILTER_VALIDATE_INT );
        $history_nonce = filter_input( INPUT_GET, 'spar_history_nonce', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
        $page = ( $history_nonce && wp_verify_nonce( $history_nonce, 'spar_history_page' ) && $history_page && $history_page > 0 ? (int) $history_page : 1 );
        $history_per_page = (int) apply_filters( 'spar_points_history_per_page', 10, (int) $user_id );
        $history_data = spar_get_user_points_history( $user_id, $page, $history_per_page );
        $logs = $history_data['logs'];
        $pagination = $history_data['pagination'];
    }
    // Voucher redeemed success notice
    $voucher_code = filter_input( INPUT_GET, 'redeemed', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
    $redeem_nonce = filter_input( INPUT_GET, 'redeemed_nonce', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
    if ( !$is_guest && !empty( $voucher_code ) && $redeem_nonce && wp_verify_nonce( $redeem_nonce, 'spar_redeemed_notice' ) ) {
        if ( function_exists( 'sparp_maybe_load_cart_for_rewards' ) ) {
            sparp_maybe_load_cart_for_rewards();
        }
        $cart = ( function_exists( 'spar_get_wc_cart' ) ? spar_get_wc_cart() : null );
        $cart_url = ( function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/cart/' ) );
        $is_applied_to_cart = false;
        if ( $cart && !$cart->is_empty() ) {
            $applied_coupons = $cart->get_applied_coupons();
            $is_applied_to_cart = in_array( $voucher_code, $applied_coupons, true );
        }
        if ( !empty( $voucher_code ) && !$is_applied_to_cart ) {
            $cart_url = add_query_arg( array(
                'apply_coupon' => $voucher_code,
                'spar_nonce'   => wp_create_nonce( 'spar_apply_coupon' ),
            ), $cart_url );
        }
        echo '<div class="spar-voucher-success-notice">';
        echo '<div class="spar-voucher-content">';
        echo '<h3><span class="spar-success-icon">🎉</span> ' . esc_html__( 'Voucher Redeemed Successfully!', 'simple-points-and-rewards' ) . '</h3>';
        echo '<p>' . esc_html__( 'Your voucher code is ready to use:', 'simple-points-and-rewards' ) . '</p>';
        echo '<div class="spar-voucher-code-container">';
        echo '<code class="spar-voucher-code" id="voucher-code">' . esc_html( $voucher_code ) . '</code>';
        echo '<button type="button" class="spar-copy-btn" title="' . esc_attr__( 'Copy to clipboard', 'simple-points-and-rewards' ) . '">📋</button>';
        echo '</div>';
        if ( $is_applied_to_cart ) {
            echo '<div class="spar-voucher-actions">';
            echo '<div class="spar-voucher-already-applied">';
            echo '<span class="spar-applied-icon"><i class="fa-solid fa-circle-check" aria-hidden="true"></i></span> ' . esc_html__( 'Already applied to your cart', 'simple-points-and-rewards' );
            echo '</div>';
            echo '<a href="' . esc_url( $cart_url ) . '" class="spar-go-to-cart-btn">' . esc_html__( 'View Cart', 'simple-points-and-rewards' ) . '</a>';
            echo '</div>';
        } else {
            echo '<div class="spar-voucher-actions">';
            echo '<button type="button" class="spar-apply-to-cart-btn" data-voucher="' . esc_attr( $voucher_code ) . '">' . esc_html__( 'Apply to Cart', 'simple-points-and-rewards' ) . '</button>';
            echo '<a href="' . esc_url( $cart_url ) . '" class="spar-go-to-cart-btn">' . esc_html__( 'Go to Cart', 'simple-points-and-rewards' ) . '</a>';
            echo '</div>';
        }
        echo '</div>';
        echo '</div><br/>';
    }
    // Overview header/cards (kept in PHP to avoid short tag parsing issues)
    $overview_html = '<div class="spar-rewards-overview' . (( $is_guest ? ' spar-guest-mode' : '' )) . '">';
    // Dark mode toggle button (if enabled)
    $dark_mode_toggle_enabled = !empty( $options['dashboard_dark_mode_toggle'] );
    $dark_mode_default = !empty( $options['dashboard_dark_mode_default'] );
    $dark_mode_hide_when_default = !empty( $options['dashboard_dark_mode_hide_toggle_when_default'] );
    if ( $dark_mode_toggle_enabled && !($dark_mode_default && $dark_mode_hide_when_default) ) {
        $overview_html .= '<button type="button" class="spar-dashboard-theme-toggle" aria-pressed="false" title="' . esc_attr__( 'Enable dark mode', 'simple-points-and-rewards' ) . '" aria-label="' . esc_attr__( 'Toggle dark mode', 'simple-points-and-rewards' ) . '">🌙</button>';
    }
    $header_text_tpl = ( isset( $options['dashboard_header_text'] ) ? $options['dashboard_header_text'] : esc_html__( '{points_label} Dashboard', 'simple-points-and-rewards' ) );
    $header_text = str_replace( '{points_label}', $points_label_html, $header_text_tpl );
    $subheader_text_tpl = ( isset( $options['dashboard_subheader_text'] ) ? $options['dashboard_subheader_text'] : esc_html__( 'Track your rewards, level up, and claim exciting benefits!', 'simple-points-and-rewards' ) );
    $subheader_text = str_replace( '{points_label}', $points_label_html, $subheader_text_tpl );
    $overview_html .= '<h2>' . esc_html( $header_text ) . '</h2>';
    $overview_html .= '<p class="spar-overview-subtitle">' . esc_html( $subheader_text ) . '</p>';
    if ( $is_guest ) {
        $overview_html .= '<div class="spar-guest-note">' . esc_html__( 'Log in or create an account to start earning and tracking your points.', 'simple-points-and-rewards' );
        // Show signup bonus hint to guests if enabled
        if ( !empty( $options['earn']['signup']['enabled'] ) && !empty( $options['earn']['signup']['show_guest_message'] ) ) {
            $signup_points = ( isset( $options['earn']['signup']['points'] ) ? (int) $options['earn']['signup']['points'] : 0 );
            if ( $signup_points > 0 ) {
                $overview_html .= ' ' . sprintf( esc_html__( 'Earn %1$s free %2$s instantly!', 'simple-points-and-rewards' ), spar_format_points_display( $signup_points, 'inline' ), esc_html( strtolower( ( $points_label !== '' ? $points_label : esc_html__( 'Points', 'simple-points-and-rewards' ) ) ) ) ) . '';
            }
        }
        $overview_html .= '</div>';
    }
    $overview_html .= '<div class="spar-rewards-stats">';
    // Available points card
    $overview_html .= '<div class="spar-rewards-stat">';
    $overview_html .= '<div class="spar-rewards-stat-number-row">';
    $overview_html .= '<span id="spar-stat-available-points" class="spar-rewards-stat-number">' . spar_format_points_display( (int) $points, 'prominent' ) . '</span>';
    $overview_html .= '</div>';
    $overview_html .= '<div class="spar-rewards-stat-label">' . sprintf( esc_html__( 'Available %s', 'simple-points-and-rewards' ), esc_html( $points_label_html ) ) . '</div>';
    $overview_html .= '<div class="spar-rewards-stat-sublabel">' . esc_html__( 'Ready to spend', 'simple-points-and-rewards' ) . '</div>';
    $overview_html .= '</div>';
    // Total earned card
    $overview_html .= '<div class="spar-rewards-stat">';
    $overview_html .= '<span id="spar-stat-total-earned" class="spar-rewards-stat-number">' . spar_format_points_display( (int) $total_points_earned, 'prominent' ) . '</span>';
    $overview_html .= '<div class="spar-rewards-stat-label">' . esc_html__( 'Total Earned', 'simple-points-and-rewards' ) . '</div>';
    $overview_html .= '<div class="spar-rewards-stat-sublabel">' . esc_html__( 'Lifetime achievement', 'simple-points-and-rewards' ) . '</div>';
    $overview_html .= '</div>';
    // Current level card (if enabled and available)
    if ( !$is_guest && $levels_enabled && function_exists( 'spar_get_user_level' ) ) {
        $current_level = spar_get_user_level( $user_id );
        if ( $current_level ) {
            $level_fallback_icon = ( function_exists( 'spar_get_level_default_badge_icon' ) ? spar_get_level_default_badge_icon( $current_level ) : '🏆' );
            $badge_markup = spar_get_level_badge_markup( array_merge( $current_level, array(
                'name' => $current_level['name'] ?? '',
            ) ), array(
                'icon_class'    => 'spar-rewards-stat-icon',
                'image_class'   => 'spar-rewards-stat-img',
                'fallback_icon' => $level_fallback_icon,
            ) );
            if ( '' === $badge_markup ) {
                $badge_markup = '<span class="spar-rewards-stat-icon">' . esc_html( $level_fallback_icon ) . '</span>';
            }
            $overview_html .= '<div class="spar-rewards-stat">';
            $overview_html .= '<span class="spar-rewards-stat-number spar-font-24">' . $badge_markup . '</span>';
            $overview_html .= '<div class="spar-rewards-stat-label">' . esc_html( $current_level['name'] ) . '</div>';
            $overview_html .= '<div class="spar-rewards-stat-sublabel">' . esc_html__( 'Current level', 'simple-points-and-rewards' ) . '</div>';
            $overview_html .= '</div>';
        }
    }
    $overview_html .= '</div></div>';
    // Let developers customize or inject content into the overview header/cards
    $overview_html = apply_filters(
        'spar_rewards_overview_html',
        $overview_html,
        (int) $user_id,
        $points,
        $options
    );
    echo wp_kses_post( $overview_html );
    $inactivity_notice_html = '';
    if ( !$is_guest && !empty( $options['points_inactivity_dashboard_notice_enabled'] ) && function_exists( 'spar_points_expiry_is_enabled' ) && spar_points_expiry_is_enabled() && $points > 0 ) {
        $settings = ( function_exists( 'spar_points_expiry_get_settings' ) ? spar_points_expiry_get_settings() : array() );
        $expiry_days = ( isset( $settings['expiry_days'] ) ? (int) $settings['expiry_days'] : 0 );
        if ( $expiry_days > 0 ) {
            $now = current_time( 'timestamp' );
            $last_active_raw = get_user_meta( $user_id, 'spar_last_active', true );
            $last_active_ts = 0;
            if ( '' === $last_active_raw || null === $last_active_raw || false === $last_active_raw ) {
                $last_active_ts = $now;
                update_user_meta( $user_id, 'spar_last_active', $last_active_ts );
            } elseif ( function_exists( 'spar_points_expiry_normalize_timestamp' ) ) {
                $last_active_ts = spar_points_expiry_normalize_timestamp( $last_active_raw );
            } elseif ( is_numeric( $last_active_raw ) ) {
                $last_active_ts = (int) $last_active_raw;
            } else {
                $parsed = strtotime( (string) $last_active_raw );
                $last_active_ts = ( $parsed ? (int) $parsed : 0 );
            }
            if ( $last_active_ts <= 0 ) {
                $last_active_ts = $now;
                update_user_meta( $user_id, 'spar_last_active', $last_active_ts );
            }
            $expiry_timestamp = $last_active_ts + $expiry_days * DAY_IN_SECONDS;
            $days_until_expiry = (int) ceil( ($expiry_timestamp - $now) / DAY_IN_SECONDS );
            $days_until_expiry = max( 0, $days_until_expiry );
            $max_days = ( isset( $options['points_inactivity_dashboard_notice_max_days'] ) ? (int) $options['points_inactivity_dashboard_notice_max_days'] : 0 );
            if ( $max_days > 0 && $days_until_expiry > $max_days ) {
                // Notice is suppressed until within the threshold window.
            } else {
                $expiry_days_i18n = number_format_i18n( $expiry_days );
                $days_remaining_i18n = number_format_i18n( $days_until_expiry );
                $custom_notice_template = ( isset( $options['points_inactivity_dashboard_notice_message'] ) ? trim( (string) $options['points_inactivity_dashboard_notice_message'] ) : '' );
                $notice_text = '';
                if ( '' === $custom_notice_template ) {
                    $phrases = array();
                    if ( $days_until_expiry > 0 ) {
                        $phrases[] = sprintf( _n(
                            'You have %s day left before your points balance resets.',
                            'You have %s days left before your points balance resets.',
                            $days_until_expiry,
                            'simple-points-and-rewards'
                        ), $days_remaining_i18n );
                    } else {
                        $phrases[] = esc_html__( 'Your points are due to expire today unless you earn new points.', 'simple-points-and-rewards' );
                    }
                    $phrases[] = esc_html__( 'Earn new points to reset the inactivity timer.', 'simple-points-and-rewards' );
                    $phrases = array_map( 'esc_html', $phrases );
                    $notice_text = implode( ' ', $phrases );
                } else {
                    $replacements = array(
                        '{days}'        => $days_remaining_i18n,
                        '{expiry_days}' => $expiry_days_i18n,
                    );
                    $notice_text = strtr( $custom_notice_template, $replacements );
                    $notice_text = esc_html( $notice_text );
                }
                if ( '' !== $notice_text ) {
                    $inactivity_notice_html = '<div class="spar-inactivity-notice" role="status"><span class="spar-inactivity-icon" aria-hidden="true">&#9203;</span><p>' . $notice_text . '</p></div>';
                }
            }
            // end else (within max_days threshold).
        }
    }
    if ( '' !== $inactivity_notice_html ) {
        echo wp_kses_post( $inactivity_notice_html );
    }
    // Feature flags: vouchers enabled and individual points discounts enabled
    $vouchers_enabled = true;
    if ( function_exists( 'spar_rewards_vouchers_enabled' ) ) {
        $vouchers_enabled = (bool) spar_rewards_vouchers_enabled();
    }
    $redeem_enabled = (bool) ($options['redeem_individual_enabled'] ?? false);
    // Resolve custom button text for "Add Discount to Cart" used across rewards UIs
    $default_redeem_button_label = esc_html__( 'Add Discount to Cart', 'simple-points-and-rewards' );
    $redeem_button_text_option = ( isset( $options['redeem_button_text'] ) ? $options['redeem_button_text'] : '' );
    $redeem_button_label = ( '' !== $redeem_button_text_option ? wp_kses_post( $redeem_button_text_option ) : $default_redeem_button_label );
    // Build tabs from setting order, applying feature visibility
    $available_tabs = array(
        'earn'     => esc_html__( 'Earn Points', 'simple-points-and-rewards' ),
        'claim'    => esc_html__( 'Claim Rewards', 'simple-points-and-rewards' ),
        'levels'   => esc_html__( 'Levels', 'simple-points-and-rewards' ),
        'history'  => esc_html__( 'History', 'simple-points-and-rewards' ),
        'vouchers' => esc_html__( 'Your Vouchers', 'simple-points-and-rewards' ),
    );
    if ( $is_guest ) {
        // Add a Get Started tab for logged-out users (append as last by default)
        $available_tabs['get-started'] = esc_html__( 'Get Started', 'simple-points-and-rewards' );
    }
    // Custom labels from settings (if provided)
    $saved_labels = array();
    if ( isset( $options['dashboard_tab_labels'] ) && is_array( $options['dashboard_tab_labels'] ) ) {
        $saved_labels = $options['dashboard_tab_labels'];
    }
    $saved_order = ( isset( $options['dashboard_tabs_order'] ) && is_array( $options['dashboard_tabs_order'] ) ? array_values( array_unique( array_filter( $options['dashboard_tabs_order'], 'strlen' ) ) ) : array(
        'earn',
        'claim',
        'vouchers',
        'levels',
        'history'
    ) );
    if ( $is_guest ) {
        // For guests, show main tabs first and place Get Started at the end
        $saved_order = array(
            'earn',
            'claim',
            'levels',
            'get-started'
        );
    }
    $saved_order = array_values( array_intersect( $saved_order, array_keys( $available_tabs ) ) );
    if ( empty( $saved_order ) ) {
        $saved_order = array(
            'earn',
            'claim',
            'vouchers',
            'levels',
            'history'
        );
    }
    $visible_tabs = array();
    foreach ( $saved_order as $key ) {
        if ( 'levels' === $key && !$levels_enabled ) {
            continue;
        }
        if ( $is_guest && in_array( $key, array('history', 'vouchers'), true ) ) {
            continue;
        }
        // Hide Vouchers tab when vouchers feature is disabled
        if ( 'vouchers' === $key && !$vouchers_enabled ) {
            continue;
        }
        // Hide Claim tab only when BOTH vouchers and points discounts are disabled
        if ( 'claim' === $key && (!$vouchers_enabled && !$redeem_enabled) ) {
            continue;
        }
        $visible_tabs[] = $key;
    }
    if ( empty( $visible_tabs ) ) {
        $visible_tabs = array('earn', 'claim', 'history');
    }
    // Allow developers to adjust which tabs are visible
    $visible_tabs = (array) apply_filters(
        'spar_rewards_visible_tabs',
        $visible_tabs,
        (int) $user_id,
        $available_tabs
    );
    // Sanitize the list of tabs to only allow known keys
    $visible_tabs = array_values( array_intersect( array_map( 'sanitize_key', $visible_tabs ), array_keys( $available_tabs ) ) );
    if ( empty( $visible_tabs ) ) {
        $visible_tabs = array('earn', 'claim', 'history');
    }
    // Render tab navigation (buttons + mobile select)
    echo '<div class="spar-tabs">';
    echo '<div class="spar-tabs-nav" role="tablist" aria-label="' . esc_attr__( 'Rewards Sections', 'simple-points-and-rewards' ) . '">';
    $first = true;
    foreach ( $visible_tabs as $key ) {
        $active_class = ( $first ? ' is-active' : '' );
        $label = ( isset( $saved_labels[$key] ) && '' !== $saved_labels[$key] ? $saved_labels[$key] : $available_tabs[$key] );
        $panel_id = 'spar-tab-' . $key;
        echo '<button type="button" class="spar-tab-nav' . esc_attr( $active_class ) . '" data-tab="' . esc_attr( $panel_id ) . '" role="tab" aria-controls="' . esc_attr( $panel_id ) . '" aria-selected="' . (( $first ? 'true' : 'false' )) . '">' . esc_html( $label ) . '</button>';
        $first = false;
    }
    echo '</div>';
    // Mobile dropdown (hidden via CSS on larger screens)
    echo '<div class="spar-tabs-mobile">';
    echo '<label class="screen-reader-text" for="spar-tabs-select">' . esc_html__( 'Select Rewards Section', 'simple-points-and-rewards' ) . '</label>';
    echo '<select id="spar-tabs-select" class="spar-tabs-select" aria-label="' . esc_attr__( 'Rewards Sections', 'simple-points-and-rewards' ) . '">';
    $first = true;
    foreach ( $visible_tabs as $key ) {
        $label = ( isset( $saved_labels[$key] ) && '' !== $saved_labels[$key] ? $saved_labels[$key] : $available_tabs[$key] );
        $panel_id = 'spar-tab-' . $key;
        echo '<option value="' . esc_attr( $panel_id ) . '"' . (( $first ? ' selected' : '' )) . '>' . esc_html( $label ) . '</option>';
        $first = false;
    }
    echo '</select>';
    echo '</div>';
    // Before rendering tab content
    do_action( 'spar_before_rewards_tab', (int) $user_id, $visible_tabs );
    ?>
	<div class="spar-tabs-content">
	<?php 
    $first = true;
    foreach ( $visible_tabs as $key ) {
        $active_class = ( $first ? ' is-active' : '' );
        echo '<div class="spar-tab-panel' . esc_attr( $active_class ) . '" id="spar-tab-' . esc_attr( $key ) . '">';
        /**
         * Fires before rendering a specific rewards tab panel.
         *
         * Developers can hook into 'spar_render_tab_{key}' to inject/replace content.
         */
        $safe_hook_key = sanitize_key( $key );
        do_action( 'spar_render_tab_' . $safe_hook_key, (int) $user_id );
        switch ( $key ) {
            case 'get-started':
                if ( $is_guest ) {
                    echo '<div class="spar-section">';
                    // Woo login/register forms
                    if ( function_exists( 'woocommerce_output_all_notices' ) ) {
                        woocommerce_output_all_notices();
                    }
                    if ( function_exists( 'wc_get_template' ) ) {
                        // This template renders both login and registration (if enabled in settings)
                        wc_get_template( 'myaccount/form-login.php' );
                    } else {
                        // Fallback: link to account page
                        $login_url = ( function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : wp_login_url( get_permalink() ) );
                        echo '<p>' . sprintf( 
                            /* translators: %s: login url */
                            esc_html__( 'Please %s to create an account or log in.', 'simple-points-and-rewards' ),
                            '<a href="' . esc_url( $login_url ) . '">' . esc_html__( 'click here', 'simple-points-and-rewards' ) . '</a>'
                         ) . '</p>';
                    }
                    echo '</div>';
                }
                break;
            case 'levels':
                if ( $levels_enabled && function_exists( 'spar_get_all_levels' ) ) {
                    $current_level = ( $is_guest ? null : spar_get_user_level( $user_id ) );
                    $all_levels = spar_get_all_levels();
                    if ( !empty( $all_levels ) ) {
                        ?>
						<div class="spar-section">
							<h3 class="spar-redeem-title"><?php 
                        esc_html_e( 'Your Level & Progress', 'simple-points-and-rewards' );
                        ?></h3>
							<?php 
                        if ( $current_level ) {
                            $next_level = spar_get_user_next_level( $user_id );
                            $progress = spar_get_level_progress( $user_id );
                            ?>

								<div class="spar-current-level-card">
									<div class="spar-current-level-top">
										<div class="spar-flex-center-gap12">
											<?php 
                            $curr_level_fallback_icon = ( function_exists( 'spar_get_level_default_badge_icon' ) ? spar_get_level_default_badge_icon( $current_level ) : '🏆' );
                            $curr_badge_markup = spar_get_level_badge_markup( array_merge( $current_level, array(
                                'name' => $current_level['name'] ?? '',
                            ) ), array(
                                'icon_class'    => 'spar-current-level-icon',
                                'image_class'   => 'spar-current-level-icon-img',
                                'fallback_icon' => $curr_level_fallback_icon,
                            ) );
                            if ( '' !== $curr_badge_markup ) {
                                echo wp_kses_post( $curr_badge_markup );
                            }
                            ?>
											<div>
												<h4 class="spar-current-level-title"><?php 
                            echo esc_html( $current_level['name'] );
                            ?></h4>
												<p class="spar-current-level-sub"><?php 
                            esc_html_e( 'Current Level', 'simple-points-and-rewards' );
                            ?></p>
											</div>
										</div>
										<div class="spar-text-right">
											<div class="spar-level-chip">
												<?php 
                            if ( 'available_balance' === $levels_points_type ) {
                                ?>
													<?php 
                                /* translators: %s: current points balance */
                                printf( esc_html__( '%s points available', 'simple-points-and-rewards' ), spar_format_points_display( (int) $level_points, 'inline' ) );
                                ?>
												<?php 
                            } else {
                                ?>
													<?php 
                                /* translators: %s: total points earned */
                                printf( esc_html__( '%s points earned', 'simple-points-and-rewards' ), spar_format_points_display( (int) $level_points, 'inline' ) );
                                ?>
												<?php 
                            }
                            ?>
											</div>
										</div>
									</div>

									<?php 
                            if ( !empty( $current_level['description'] ) ) {
                                ?>
										<p class="spar-current-level-desc">
											<?php 
                                echo esc_html( $current_level['description'] );
                                ?>
										</p>
									<?php 
                            }
                            ?>

									<?php 
                            if ( $next_level ) {
                                ?>
										<div class="spar-progress-wrap">
											<div class="spar-progress-header">
												<span class="spar-progress-title"><?php 
                                /* translators: %s: next level name */
                                printf( esc_html__( 'Progress to %s', 'simple-points-and-rewards' ), esc_html( $next_level['name'] ) );
                                ?></span>
												<span class="spar-progress-percent"><?php 
                                /* translators: %d: percent complete */
                                printf( esc_html__( '%d%% Complete', 'simple-points-and-rewards' ), (int) round( $progress ) );
                                ?></span>
											</div>
											<div class="spar-level-progress-bar">
												<div class="spar-level-progress-fill" style="width: <?php 
                                echo esc_attr( $progress );
                                ?>%"></div>
											</div>
											<div class="spar-progress-footer">
												<span><?php 
                                /* translators: %s: points needed to reach next level */
                                printf( esc_html__( '%s points needed', 'simple-points-and-rewards' ), spar_format_points_display( (int) ($next_level['required_points'] - $level_points), 'inline' ) );
                                ?></span>
												<span><?php 
                                /* translators: %s: target points for next level */
                                printf( esc_html__( 'Target: %s', 'simple-points-and-rewards' ), spar_format_points_display( (int) $next_level['required_points'], 'inline' ) );
                                ?></span>
											</div>
										</div>
									<?php 
                            } else {
                                ?>

									<?php 
                            }
                            ?>
								</div>
							<?php 
                        } else {
                            ?>
								<div class="spar-empty-levels">
									<div class="spar-empty-icon">🌱</div>
									<h4><?php 
                            echo ( $is_guest ? esc_html__( 'Join to Level Up', 'simple-points-and-rewards' ) : esc_html__( 'Ready to Level Up?', 'simple-points-and-rewards' ) );
                            ?></h4>
									<p><?php 
                            echo ( $is_guest ? esc_html__( 'Create an account to start earning points and unlock your first level.', 'simple-points-and-rewards' ) : esc_html__( 'Start earning points to unlock your first level!', 'simple-points-and-rewards' ) );
                            ?></p>
									<div class="spar-empty-chip">
										<span>
										<?php 
                            if ( 'available_balance' === $levels_points_type ) {
                                ?>
											<?php 
                                /* translators: %s: current points balance */
                                printf( esc_html__( '%s points available', 'simple-points-and-rewards' ), spar_format_points_display( (int) $level_points, 'inline' ) );
                                ?>
										<?php 
                            } else {
                                ?>
											<?php 
                                /* translators: %s: total points earned so far */
                                printf( esc_html__( '%s points earned so far', 'simple-points-and-rewards' ), spar_format_points_display( (int) $level_points, 'inline' ) );
                                ?>
										<?php 
                            }
                            ?>
									</span>
									</div>
								</div>
							<?php 
                        }
                        ?>

							<br/>
							<div class="spar-all-levels">
								<h4 class="spar-all-levels-title"><span>🎯</span> <?php 
                        esc_html_e( 'All Levels', 'simple-points-and-rewards' );
                        ?></h4>
								<div class="spar-levels-grid">
									<?php 
                        $count = 0;
                        foreach ( $all_levels as $level ) {
                            $count++;
                            $is_current = $current_level && $current_level['id'] === $level['id'];
                            $is_unlocked = $level_points >= $level['required_points'];
                            $card_classes = 'spar-level-card' . (( $is_current ? ' is-current' : (( $is_unlocked ? ' is-unlocked' : ' is-locked' )) ));
                            ?>

										<?php 
                            ?>
											<?php 
                            if ( $count > 2 ) {
                                break;
                            }
                            ?>
										<?php 
                            ?>

										<div class="<?php 
                            echo esc_attr( $card_classes );
                            ?>">
											<?php 
                            if ( $is_current ) {
                                ?>
												<div class="spar-badge-current"><?php 
                                esc_html_e( 'Current', 'simple-points-and-rewards' );
                                ?></div>
											<?php 
                            } elseif ( $is_unlocked ) {
                                ?>
												<div class="spar-badge-check"><i class="fa-solid fa-circle-check" aria-hidden="true"></i></div>
											<?php 
                            }
                            ?>
											<div class="spar-flex-center-gap12 spar-mb-15">
												<?php 
                            $level_fallback_icon = ( function_exists( 'spar_get_level_default_badge_icon' ) ? spar_get_level_default_badge_icon( $level ) : '🏆' );
                            $level_badge_markup = spar_get_level_badge_markup( array_merge( $level, array(
                                'name' => $level['name'] ?? '',
                            ) ), array(
                                'icon_class'    => 'spar-current-level-icon',
                                'image_class'   => 'spar-current-level-icon-img',
                                'fallback_icon' => $level_fallback_icon,
                            ) );
                            if ( '' !== $level_badge_markup ) {
                                echo wp_kses_post( $level_badge_markup );
                            }
                            ?>
												<div>
													<h5 class="spar-level-title <?php 
                            echo ( $is_current ? 'is-current' : '' );
                            ?>"><?php 
                            echo esc_html( $level['name'] );
                            ?></h5>
													<p class="spar-level-requirement"><?php 
                            printf( esc_html__( '%s points required', 'simple-points-and-rewards' ), spar_format_points_display( (int) $level['required_points'], 'inline' ) );
                            ?></p>
												</div>
											</div>
											<div class="spar-level-benefits">
												<h6 class="spar-level-benefits-title <?php 
                            echo ( $is_current ? 'is-current' : '' );
                            ?>"><?php 
                            esc_html_e( 'Benefits:', 'simple-points-and-rewards' );
                            ?></h6>
												<ul class="spar-level-benefits-list">
													<?php 
                            if ( !empty( $level['benefits'] ) ) {
                                foreach ( $level['benefits'] as $benefit ) {
                                    ?>
														<li><?php 
                                    echo esc_html( $benefit );
                                    ?></li>
													<?php 
                                }
                            } else {
                                ?>
														<li><?php 
                                echo esc_html( $level['description'] ?? sprintf( esc_html__( '%sx points multiplier', 'simple-points-and-rewards' ), spar_format_multiplier( $level['points_multiplier'] ?? 1.0 ) ) );
                                ?></li>
													<?php 
                            }
                            ?>
												</ul>
											</div>
										</div>
									<?php 
                        }
                        ?>
								</div>
							</div>
						</div>
						<?php 
                    }
                }
                break;
            case 'history':
                if ( $is_guest ) {
                    echo '</div>';
                    $first = false;
                    continue 2;
                }
                ?>
				<div class="spar-section">
					<div class="spar-history-section-header">
						<h3 class="spar-redeem-title"><?php 
                /* translators: %s: points label */
                printf( esc_html__( '%s History', 'simple-points-and-rewards' ), esc_html( $points_label_html ) );
                ?></h3>
						<div class="spar-history-filter">
							<label for="spar-history-status-filter" class="screen-reader-text"><?php 
                esc_html_e( 'Filter by status', 'simple-points-and-rewards' );
                ?></label>
							<select id="spar-history-status-filter">
								<option value=""><?php 
                esc_html_e( 'All Statuses', 'simple-points-and-rewards' );
                ?></option>
								<option value="earned"><?php 
                esc_html_e( 'Earned', 'simple-points-and-rewards' );
                ?></option>
								<option value="spent"><?php 
                esc_html_e( 'Spent', 'simple-points-and-rewards' );
                ?></option>
								<?php 
                ?>
							</select>
						</div>
					</div>
					<div id="spar-points-history-container">
						<div id="spar-points-history-tbody" class="spar-history-timeline">
							<?php 
                if ( !empty( $logs ) ) {
                    $current_date_group = '';
                    foreach ( $logs as $log ) {
                        $date_group = date_i18n( 'Y-m-d', strtotime( $log['date'] ) );
                        if ( $date_group !== $current_date_group ) {
                            $current_date_group = $date_group;
                            ?>
										<div class="spar-tl-date-sep">
											<span><?php 
                            echo esc_html( date_i18n( 'M j', strtotime( $log['date'] ) ) );
                            ?></span>
										</div>
										<?php 
                        }
                        echo wp_kses_post( spar_build_history_timeline_item( $log, $points_label_html ) );
                    }
                } else {
                    ?>
								<div class="spar-tl-empty">
									<span class="spar-tl-empty-icon">📋</span>
									<span class="spar-tl-empty-text"><?php 
                    printf( esc_html__( 'No %s history yet. Start earning points by making purchases!', 'simple-points-and-rewards' ), esc_html( strtolower( ( $points_label !== '' ? $points_label : esc_html__( 'Points', 'simple-points-and-rewards' ) ) ) ) );
                    ?></span>
								</div>
							<?php 
                }
                ?>
						</div>
						<div id="spar-points-history-pagination" class="spar-pagination"<?php 
                if ( $pagination['total_pages'] <= 1 ) {
                    ?> style="display:none"<?php 
                }
                ?>>
							<?php 
                if ( $pagination['total_pages'] > 1 ) {
                    ?>
								<div class="spar-pagination-controls">
									<button class="spar-pagination-btn spar-pagination-prev" data-page="<?php 
                    echo esc_attr( $pagination['current_page'] - 1 );
                    ?>" <?php 
                    disabled( !$pagination['has_previous'] );
                    ?>><?php 
                    esc_html_e( '← Previous', 'simple-points-and-rewards' );
                    ?></button>
									<?php 
                    $current_page = (int) $pagination['current_page'];
                    $total_pages = (int) $pagination['total_pages'];
                    $start_page = max( 1, $current_page - 2 );
                    $end_page = min( $total_pages, $current_page + 2 );
                    if ( 1 === $start_page ) {
                        $end_page = min( $total_pages, 5 );
                    } elseif ( $end_page === $total_pages ) {
                        $start_page = max( 1, $total_pages - 4 );
                    }
                    for ($i = $start_page; $i <= $end_page; $i++) {
                        $is_current = $i === $current_page;
                        $btn_class = ( $is_current ? 'spar-pagination-btn spar-pagination-current' : 'spar-pagination-btn spar-pagination-page' );
                        ?>
										<button class="<?php 
                        echo esc_attr( $btn_class );
                        ?>" data-page="<?php 
                        echo esc_attr( $i );
                        ?>" <?php 
                        disabled( $is_current );
                        ?>><?php 
                        echo esc_html( $i );
                        ?></button>
									<?php 
                    }
                    ?>
									<button class="spar-pagination-btn spar-pagination-next" data-page="<?php 
                    echo esc_attr( $pagination['current_page'] + 1 );
                    ?>" <?php 
                    disabled( !$pagination['has_next'] );
                    ?>><?php 
                    esc_html_e( 'Next →', 'simple-points-and-rewards' );
                    ?></button>
								</div>
								<div class="spar-pagination-loading"><span><?php 
                    esc_html_e( 'Loading...', 'simple-points-and-rewards' );
                    ?></span></div>
							<?php 
                }
                ?>
						</div>
					</div>
				</div>
				<?php 
                break;
            case 'vouchers':
                if ( $is_guest ) {
                    echo '</div>';
                    $first = false;
                    continue 2;
                }
                ?>
				<div class="spar-section"><?php 
                include SPAR_PLUGIN_PATH . 'templates/redeemed-vouchers.php';
                ?></div>
				<?php 
                break;
            case 'earn':
                ?>
				<div class="spar-section">
					<?php 
                $multiplier = 1.0;
                $earn_type_multipliers = array();
                if ( !$is_guest && function_exists( 'spar_get_user_points_multiplier' ) ) {
                    $multiplier = spar_get_user_points_multiplier( $user_id );
                    // Pre-compute per-earn-type multipliers for display.
                    $_earn_types = array(
                        'signup',
                        'first_order',
                        'nth_order',
                        'order_fixed',
                        'order',
                        'review',
                        'birthday',
                        'daily_login',
                        'referral'
                    );
                    foreach ( $_earn_types as $_et ) {
                        $earn_type_multipliers[$_et] = max( 0.0, (float) spar_get_user_points_multiplier( $user_id, $_et ) );
                    }
                }
                $earn_points_text_enabled = spar_get_single_option( 'earn_points_text_enabled' );
                $earn_points_text = spar_get_single_option( 'earn_points_text' );
                if ( ($earn_points_text_enabled === null || (bool) $earn_points_text_enabled) && !empty( $earn_points_text ) ) {
                    echo '<p class="spar-earn-intro-text">' . esc_html( $earn_points_text ) . '</p>';
                }
                $ways_to_earn_path = SPAR_PLUGIN_PATH . 'templates/ways-to-earn.php';
                if ( file_exists( $ways_to_earn_path ) ) {
                    ob_start();
                    include $ways_to_earn_path;
                    $ways_to_earn_html = ob_get_clean();
                    if ( !$is_guest && !empty( $earn_type_multipliers ) ) {
                        // Apply per-earn-type multipliers to each block identified by data-earn-type.
                        // Split the HTML at each data-earn-type boundary so each segment gets its own multiplier.
                        $points_pattern = '/(\\d+(?:\\.\\d+)?)\\s*(' . preg_quote( strtolower( $points_label ), '/' ) . ')/i';
                        $segments = preg_split( '/(?=<div\\s[^>]*data-earn-type=")/is', $ways_to_earn_html );
                        $rebuilt = '';
                        foreach ( $segments as $segment ) {
                            // Detect the earn type from the opening tag of this segment.
                            if ( preg_match( '/^<div\\s[^>]*data-earn-type="([a-z_]+)"/i', $segment, $type_match ) ) {
                                $earn_type = $type_match[1];
                                $_m = ( isset( $earn_type_multipliers[$earn_type] ) ? $earn_type_multipliers[$earn_type] : $multiplier );
                                if ( $_m > 1.0 ) {
                                    $segment = preg_replace_callback( $points_pattern, function ( $matches ) use($_m) {
                                        $base = (float) $matches[1];
                                        $new = $base * $_m;
                                        $display = ( $new == floor( $new ) ? number_format_i18n( $new, 0 ) : number_format_i18n( $new, 2 ) );
                                        return esc_html( $display ) . ' ' . $matches[2] . ' <span class="spar-points-multiplier">(x' . esc_html( spar_format_multiplier( $_m ) ) . ')</span>';
                                    }, $segment );
                                }
                            }
                            $rebuilt .= $segment;
                        }
                        $ways_to_earn_html = $rebuilt;
                    }
                    // Attributes shared by the dialog markup (e.g. the review products modal) so
                    // wp_kses does not strip the accessibility semantics from those elements.
                    $aria_attrs = array(
                        'role'             => true,
                        'aria-modal'       => true,
                        'aria-hidden'      => true,
                        'aria-label'       => true,
                        'aria-labelledby'  => true,
                        'aria-describedby' => true,
                        'aria-haspopup'    => true,
                        'aria-controls'    => true,
                        'aria-expanded'    => true,
                        'aria-live'        => true,
                    );
                    $allowed_tags = array(
                        'div'    => array(
                            'class'          => true,
                            'id'             => true,
                            'style'          => true,
                            'data-earn-type' => true,
                            'data-next-spin' => true,
                            'data-server-ts' => true,
                        ) + $aria_attrs,
                        'span'   => array(
                            'class'              => true,
                            'id'                 => true,
                            'style'              => true,
                            'data-spar-bp-endts' => true,
                        ) + $aria_attrs,
                        'strong' => array(),
                        'p'      => array(
                            'class' => true,
                            'style' => true,
                        ),
                        'h3'     => array(
                            'class' => true,
                            'id'    => true,
                        ),
                        'h4'     => array(
                            'class' => true,
                            'id'    => true,
                        ),
                        'h5'     => array(
                            'class' => true,
                            'id'    => true,
                        ),
                        'ul'     => array(
                            'class' => true,
                            'id'    => true,
                        ),
                        'ol'     => array(
                            'class' => true,
                        ),
                        'li'     => array(
                            'class' => true,
                        ),
                        'table'  => array(
                            'class' => true,
                        ),
                        'thead'  => array(),
                        'tbody'  => array(
                            'id'    => true,
                            'class' => true,
                        ),
                        'tr'     => array(
                            'class' => true,
                        ),
                        'th'     => array(
                            'class' => true,
                        ),
                        'td'     => array(
                            'class'      => true,
                            'data-label' => true,
                            'colspan'    => true,
                        ),
                        'a'      => array(
                            'href'             => true,
                            'target'           => true,
                            'rel'              => true,
                            'class'            => true,
                            'id'               => true,
                            'title'            => true,
                            'data-spar-tab'    => true,
                            'data-spar-anchor' => true,
                        ) + $aria_attrs,
                        'button' => array(
                            'type'             => true,
                            'class'            => true,
                            'id'               => true,
                            'disabled'         => true,
                            'title'            => true,
                            'data-page'        => true,
                            'data-product-ids' => true,
                        ) + $aria_attrs,
                        'input'  => array(
                            'type'        => true,
                            'id'          => true,
                            'class'       => true,
                            'name'        => true,
                            'value'       => true,
                            'placeholder' => true,
                            'readonly'    => true,
                            'style'       => true,
                        ),
                        'i'      => array(
                            'class'       => true,
                            'aria-hidden' => true,
                        ),
                        'img'    => array(
                            'src'      => true,
                            'srcset'   => true,
                            'sizes'    => true,
                            'width'    => true,
                            'height'   => true,
                            'alt'      => true,
                            'class'    => true,
                            'loading'  => true,
                            'decoding' => true,
                            'title'    => true,
                            'style'    => true,
                        ),
                        'br'     => array(),
                    );
                    if ( function_exists( 'wp_kses' ) ) {
                        echo wp_kses( $ways_to_earn_html, $allowed_tags );
                    }
                }
                ?>
				</div>
				<?php 
                break;
            case 'claim':
                ?>
				<div class="spar-section">
					<?php 
                // If vouchers are enabled, show the standard rewards grid first
                if ( $vouchers_enabled ) {
                    $ways_to_redeem_path = SPAR_PLUGIN_PATH . 'templates/ways-to-redeem.php';
                    if ( file_exists( $ways_to_redeem_path ) ) {
                        include $ways_to_redeem_path;
                    }
                }
                // If individual points discount is enabled and the dashboard section is not disabled, also show the redemption section below
                $pd_section_enabled = ( isset( $options['dashboard_points_discount_enabled'] ) ? (bool) $options['dashboard_points_discount_enabled'] : true );
                if ( $redeem_enabled && $pd_section_enabled ) {
                    // Show Points Discounts information and in-tab redemption UI when possible
                    $store_currency = ( function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : (( defined( 'WC_VERSION' ) ? get_option( 'woocommerce_currency' ) : 'USD' )) );
                    $currency_symbol = ( function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol( $store_currency ) : $store_currency );
                    // Resolve currency-aware rate using helper
                    $rate = ( function_exists( 'spar_get_redeem_rate_for_currency' ) ? spar_get_redeem_rate_for_currency( $store_currency ) : array(
                        'points' => (float) ($options['redeem_points_per_points'] ?? 100),
                        'amount' => (float) ($options['redeem_points_per_amount'] ?? 1),
                    ) );
                    $rate_points = max( 0, (float) ($rate['points'] ?? 0) );
                    $rate_amount = max( 0, (float) ($rate['amount'] ?? 0) );
                    $rate_amount_disp = spar_format_currency_amount( $rate_amount );
                    $redeem_points_min = 0;
                    $redeem_points_max = 0;
                    $user_points_bal = (int) (( $is_guest ? 0 : spar_get_user_points( $user_id ) ));
                    $current_value = 0.0;
                    if ( function_exists( 'spar_calculate_redemption_amount' ) ) {
                        $current_value = (float) spar_calculate_redemption_amount( $user_points_bal, $store_currency );
                    }
                    // Heading and explanation
                    $pd_heading_classes = 'spar-pd-heading spar-redeem-title' . (( $vouchers_enabled ? ' has-vouchers' : '' ));
                    echo '<h3 class="' . esc_attr( $pd_heading_classes ) . '">' . esc_html__( 'Points Discounts', 'simple-points-and-rewards' ) . '</h3>';
                    $custom_pd_message = ( isset( $options['redeem_individual_message'] ) ? sanitize_text_field( $options['redeem_individual_message'] ) : '' );
                    $pd_intro = ( $custom_pd_message !== '' ? $custom_pd_message : esc_html__( 'Use your points to get an instant discount on your next order.', 'simple-points-and-rewards' ) );
                    echo '<p class="spar-redeem-intro">' . esc_html( $pd_intro ) . '</p>';
                    if ( $rate_points > 0 && $rate_amount > 0 ) {
                        /* translators: 1: currency symbol/code, 2: amount, 3: points */
                        printf(
                            '<p class="spar-redeem-rate">' . esc_html__( 'Current rate: %1$s%2$s per %3$s points', 'simple-points-and-rewards' ) . '</p>',
                            esc_html( $currency_symbol ),
                            esc_html( $rate_amount_disp ),
                            spar_format_points_display( (float) $rate_points, 'inline', 0 )
                        );
                    }
                    /* translators: 1: points balance, 2: currency value */
                    printf(
                        '<p class="spar-redeem-worth">' . esc_html__( 'Your %1$s points are worth approximately %2$s%3$s.', 'simple-points-and-rewards' ) . '</p>',
                        spar_format_points_display( (int) $user_points_bal, 'inline' ),
                        esc_html( $currency_symbol ),
                        esc_html( spar_format_currency_amount( $current_value ) )
                    );
                    // If the cart has items, render the compact redemption UI
                    $cart = ( function_exists( 'spar_get_wc_cart' ) ? spar_get_wc_cart() : null );
                    $can_apply_now = $cart && !$cart->is_empty();
                    if ( $can_apply_now && !$is_guest && $user_points_bal > 0 ) {
                        // Enqueue assets and localize minimal data for the compact UI
                        if ( !wp_style_is( 'spar-cart-checkout-rewards', 'registered' ) ) {
                            wp_register_style(
                                'spar-cart-checkout-rewards',
                                SPAR_PLUGIN_URL . 'assets/css/cart-checkout-rewards.css',
                                array(),
                                SPAR_VERSION
                            );
                        }
                        wp_enqueue_style( 'spar-cart-checkout-rewards' );
                        if ( !wp_script_is( 'spar-cart-checkout-rewards', 'registered' ) ) {
                            wp_register_script(
                                'spar-cart-checkout-rewards',
                                SPAR_PLUGIN_URL . 'assets/js/cart-checkout-rewards.js',
                                array('jquery'),
                                SPAR_VERSION,
                                true
                            );
                        }
                        wp_enqueue_script( 'spar-cart-checkout-rewards' );
                        $current_redemption = array();
                        $session = ( function_exists( 'spar_get_wc_session' ) ? spar_get_wc_session() : null );
                        if ( $session ) {
                            $current_redemption = (array) $session->get( 'spar_points_redemption', array() );
                        }
                        $limits_enabled = function_exists( 'spar_fs' ) && spar_fs()->can_use_premium_code__premium_only();
                        $min_limit_value = ( $limits_enabled ? max( 0, (int) $redeem_points_min ) : 0 );
                        $max_limit_value = ( $limits_enabled ? max( 0, (int) $redeem_points_max ) : 0 );
                        $max_points_attr = (int) $user_points_bal;
                        if ( $max_limit_value > 0 ) {
                            $max_points_attr = min( (int) $max_limit_value, (int) $user_points_bal );
                        }
                        $min_points_attr = 0;
                        if ( $min_limit_value > 0 && $user_points_bal >= $min_limit_value ) {
                            $min_points_attr = (int) min( $min_limit_value, ( $max_points_attr > 0 ? $max_points_attr : $min_limit_value ) );
                        }
                        $price_decimals = ( function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2 );
                        $price_decimal_sep = ( function_exists( 'wc_get_price_decimal_separator' ) ? wc_get_price_decimal_separator() : '.' );
                        $price_thousand_sep = ( function_exists( 'wc_get_price_thousand_separator' ) ? wc_get_price_thousand_separator() : ',' );
                        $_tax_info = ( function_exists( 'spar_get_redeem_discount_tax_info' ) ? spar_get_redeem_discount_tax_info( $options ) : array(
                            'multiplier'   => 1.0,
                            'rate'         => 0.0,
                            'display_mode' => '',
                        ) );
                        $tax_multiplier = $_tax_info['multiplier'];
                        $tax_rate = $_tax_info['rate'];
                        $tax_display_mode = $_tax_info['display_mode'];
                        wp_localize_script( 'spar-cart-checkout-rewards', 'sparCartRewards', array(
                            'ajaxUrl'                => admin_url( 'admin-ajax.php' ),
                            'nonce'                  => wp_create_nonce( 'spar_cart_rewards' ),
                            'pointsRedeemNonce'      => wp_create_nonce( 'spar_points_redeem' ),
                            'priceDecimals'          => $price_decimals,
                            'priceDecimalSeparator'  => $price_decimal_sep,
                            'priceThousandSeparator' => $price_thousand_sep,
                            'userPoints'             => (int) $user_points_bal,
                            'pointsLabel'            => $points_label_html,
                            'pointsPrefix'           => spar_get_points_prefix(),
                            'pointsIconProminent'    => spar_get_points_icon_html( 'prominent' ),
                            'isBlockCheckout'        => false,
                            'isCart'                 => false,
                            'isOrderReceived'        => false,
                            'taxMultiplier'          => $tax_multiplier,
                            'taxRate'                => $tax_rate,
                            'taxDisplayMode'         => $tax_display_mode,
                            'redeem'                 => array(
                                'enabled' => true,
                                'base'    => array(
                                    'points'   => (float) ($options['redeem_points_per_points'] ?? 100),
                                    'amount'   => (float) ($options['redeem_points_per_amount'] ?? 1),
                                    'currency' => $store_currency,
                                ),
                                'rates'   => (array) ($options['redeem_currency_rates'] ?? array()),
                                'current' => $current_redemption,
                                'limits'  => array(
                                    'min' => $min_limit_value,
                                    'max' => $max_limit_value,
                                ),
                            ),
                            'strings'                => array(
                                'add'       => esc_html__( 'Add Discount to Cart', 'simple-points-and-rewards' ),
                                'remove'    => esc_html__( 'Remove', 'simple-points-and-rewards' ),
                                'value'     => esc_html__( 'Discount Value', 'simple-points-and-rewards' ),
                                'error'     => esc_html__( 'Something went wrong. Please try again.', 'simple-points-and-rewards' ),
                                'taxSuffix' => esc_html__( 'tax', 'simple-points-and-rewards' ),
                            ),
                        ) );
                        // Current session points applied (if any)
                        $current_points = 0;
                        if ( $session ) {
                            $sess = (array) $session->get( 'spar_points_redemption', array() );
                            $current_points = ( isset( $sess['points'] ) ? (int) $sess['points'] : 0 );
                        }
                        if ( $current_points > 0 ) {
                            if ( $min_points_attr > 0 && $current_points < $min_points_attr ) {
                                $current_points = $min_points_attr;
                            }
                            if ( $max_points_attr > 0 && $current_points > $max_points_attr ) {
                                $current_points = $max_points_attr;
                            }
                        } else {
                            $current_points = $min_points_attr;
                        }
                        // Compact redemption UI (account tab variant)
                        ?>
							<div class="spar-redeem-compact spar-redeem-compact--account" data-currency="<?php 
                        echo esc_attr( $store_currency );
                        ?>">
								<h4 class="spar-redeem-header"><?php 
                        esc_html_e( 'Redeem Points:', 'simple-points-and-rewards' );
                        ?></h4>
								<div class="spar-panel-row">
									<input type="range" class="spar-redeem-slider" min="<?php 
                        echo esc_attr( $min_points_attr );
                        ?>" step="1" value="<?php 
                        echo esc_attr( $current_points );
                        ?>" max="<?php 
                        echo esc_attr( $max_points_attr );
                        ?>" />
									<div class="spar-panel-right">
										<input type="number" class="spar-redeem-input" min="<?php 
                        echo esc_attr( $min_points_attr );
                        ?>" step="1" value="<?php 
                        echo esc_attr( $current_points );
                        ?>" max="<?php 
                        echo esc_attr( $max_points_attr );
                        ?>" />
										<div class="spar-discount-box">
											<span class="spar-redeem-value-label"><?php 
                        esc_html_e( 'Discount Value:', 'simple-points-and-rewards' );
                        ?></span>
											<span class="spar-redeem-value" data-ppp="<?php 
                        echo esc_attr( (float) $rate_points );
                        ?>" data-ppa="<?php 
                        echo esc_attr( (float) $rate_amount );
                        ?>" data-currency-symbol="<?php 
                        echo esc_attr( $currency_symbol );
                        ?>" data-tax-multiplier="<?php 
                        echo esc_attr( $tax_multiplier );
                        ?>" data-tax-rate="<?php 
                        echo esc_attr( $tax_rate );
                        ?>">0</span>
										</div>
										<button type="button" class="button spar-redeem-apply-btn" data-nonce="<?php 
                        echo esc_attr( wp_create_nonce( 'spar_points_redeem' ) );
                        ?>" <?php 
                        disabled( $current_points > 0, false );
                        ?>><?php 
                        echo wp_kses_post( $redeem_button_label );
                        ?></button>
									</div>
								</div>
								<?php 
                        if ( $current_points > 0 ) {
                            ?>
									<div class="spar-redeem-applied-status">
										<span class="spar-redeem-applied-text"><?php 
                            /* translators: %s: points count (localized) */
                            printf( esc_html__( 'Points applied to cart: %s', 'simple-points-and-rewards' ), spar_format_points_display( (int) $current_points, 'inline' ) );
                            ?></span>
										<span class="spar-sep"> - </span>
										<a href="#" class="link-button spar-redeem-remove-btn" data-nonce="<?php 
                            echo esc_attr( wp_create_nonce( 'spar_points_redeem' ) );
                            ?>"><?php 
                            esc_html_e( 'Remove', 'simple-points-and-rewards' );
                            ?></a>
									</div>
								<?php 
                        }
                        ?>
							</div>
							<?php 
                    } else {
                        // Cart empty or user has no points: show a helpful note
                        echo '<div class="spar-cart-empty-state spar-cart-empty-state-dash"><div class="spar-cart-empty-icon spar-cart-empty-icon-dash">🛒</div><div class="spar-cart-empty-title spar-cart-empty-title-dash">' . esc_html__( 'Add items to your cart to redeem points at checkout.', 'simple-points-and-rewards' ) . '</div></div>';
                    }
                }
                ?>
				</div>
				<?php 
                break;
        }
        echo '</div>';
        $first = false;
    }
    ?>
	</div>
	</div>
	<?php 
    // After rendering all tabs
    do_action( 'spar_after_rewards_tab', (int) $user_id );
    // Terms and Conditions link and popup
    $terms_enabled = !empty( $options['terms_enabled'] );
    $terms_show_dashboard = ( isset( $options['terms_show_dashboard'] ) ? (bool) $options['terms_show_dashboard'] : true );
    if ( $terms_enabled && $terms_show_dashboard ) {
        $terms_content = ( !empty( $options['terms_content'] ) ? $options['terms_content'] : '' );
        if ( !empty( $terms_content ) ) {
            $terms_link_text = ( isset( $options['terms_link_text'] ) && '' !== $options['terms_link_text'] ? $options['terms_link_text'] : __( 'Terms and Conditions', 'simple-points-and-rewards' ) );
            echo '<div class="spar-terms-bar">';
            echo '<a href="#" class="spar-terms-link" role="button" aria-haspopup="dialog" aria-controls="spar-terms-modal">';
            echo esc_html( $terms_link_text );
            echo '</a>';
            echo '</div>';
            echo '<div id="spar-terms-modal" class="spar-terms-modal" role="dialog" aria-modal="true" aria-labelledby="spar-terms-modal-title" aria-hidden="true">';
            echo '<div class="spar-terms-modal-overlay"></div>';
            echo '<div class="spar-terms-modal-dialog">';
            echo '<div class="spar-terms-modal-header">';
            echo '<h3 id="spar-terms-modal-title" class="spar-terms-modal-title">' . esc_html__( 'Terms and Conditions', 'simple-points-and-rewards' ) . '</h3>';
            echo '<button type="button" class="spar-terms-modal-close" aria-label="' . esc_attr__( 'Close terms and conditions', 'simple-points-and-rewards' ) . '">&times;</button>';
            echo '</div>';
            echo '<div class="spar-terms-modal-body">';
            echo wp_kses_post( $terms_content );
            echo '</div>';
            echo '</div>';
            echo '</div><br/>';
        }
    }
    // Close dashboard wrapper
    echo '</div>';
}
