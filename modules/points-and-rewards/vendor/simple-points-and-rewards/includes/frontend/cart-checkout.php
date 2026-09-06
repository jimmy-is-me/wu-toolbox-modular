<?php

/**
 * Cart and Checkout Rewards Display
 */
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
// Add rewards box to checkout page
add_action( 'woocommerce_before_checkout_form', 'spar_display_checkout_rewards_box' );
// Add rewards box to cart page (classic templates)
add_action( 'woocommerce_before_cart_totals', 'spar_display_cart_rewards_box', 5 );
// Add support for cart/checkout assets
add_action( 'wp_enqueue_scripts', 'spar_enqueue_block_checkout_assets', 20 );
// Also try hooking into block-specific actions
add_action( 'woocommerce_blocks_checkout_block_registration', 'spar_enqueue_block_checkout_assets' );
add_action( 'woocommerce_blocks_enqueue_checkout_block_scripts_after', 'spar_enqueue_block_checkout_assets' );
// Prepend rewards box before content on pages that contain the Checkout Block
add_filter( 'the_content', 'spar_prepend_rewards_before_content_on_checkout_block', 9 );
// Prepend rewards box before content on pages that contain the Cart Block
add_filter( 'the_content', 'spar_prepend_rewards_before_content_on_cart_block', 9 );
// Thank You page: render after points may have been awarded/deducted on 'woocommerce_thankyou'
add_action(
    'woocommerce_thankyou',
    'spar_display_thankyou_rewards_box',
    30,
    1
);
if ( !function_exists( 'sparp_maybe_load_cart_for_rewards' ) ) {
    /**
     * Ensure WC()->cart is initialized before rewards calculations (not auto-loaded on AJAX requests).
     */
    function sparp_maybe_load_cart_for_rewards() {
        if ( !function_exists( 'WC' ) ) {
            return;
        }
        $woocommerce = WC();
        if ( !$woocommerce ) {
            return;
        }
        if ( $woocommerce && isset( $woocommerce->cart ) && $woocommerce->cart ) {
            return;
        }
        if ( function_exists( 'wc_load_cart' ) ) {
            try {
                wc_load_cart();
            } catch ( Throwable $e ) {
                return;
            }
            return;
        }
        if ( $woocommerce && method_exists( $woocommerce, 'initialize_cart' ) ) {
            try {
                $woocommerce->initialize_cart();
            } catch ( Throwable $e ) {
                return;
            }
        }
    }

}
if ( !function_exists( 'spar_get_cart_redeem_points_max' ) ) {
    /**
     * Calculate the maximum redeemable points based on cart cap and rate.
     *
     * @param int    $user_points       User's available points.
     * @param string $store_currency    Store currency.
     * @param int    $redeem_points_max Optional configured max (0 = no limit).
     * @return int
     */
    function spar_get_cart_redeem_points_max(  $user_points, $store_currency, $redeem_points_max = 0  ) {
        $points_max = max( 0, (int) $user_points );
        if ( $redeem_points_max > 0 ) {
            $points_max = min( $points_max, (int) $redeem_points_max );
        }
        $cart_max_points = 0;
        $cart = ( function_exists( 'spar_get_wc_cart' ) ? spar_get_wc_cart() : null );
        if ( $cart && function_exists( 'spar_get_redemption_discount_cap_from_cart' ) && function_exists( 'spar_get_redeem_rate_for_currency' ) ) {
            // Premium: block redemption entirely when the cart is below the
            // minimum total required to redeem points.
            if ( function_exists( 'spar_cart_meets_redeem_min_total' ) && !spar_cart_meets_redeem_min_total( $cart ) ) {
                return 0;
            }
            $cap = (float) spar_get_redemption_discount_cap_from_cart( $cart );
            // Apply maximum cart percentage limit if set (premium feature)
            if ( function_exists( 'spar_fs' ) && spar_fs()->can_use_premium_code__premium_only() ) {
                $defaults = ( function_exists( 'spar_settings_default' ) ? spar_settings_default() : array() );
                $options = get_option( 'spar_options', $defaults );
                $options = array_merge( $defaults, $options );
                $max_percentage = ( isset( $options['redeem_max_cart_percentage'] ) ? max( 0, min( 100, (int) $options['redeem_max_cart_percentage'] ) ) : 0 );
                if ( $max_percentage > 0 && $max_percentage < 100 && $cap > 0 ) {
                    $cap = $cap * $max_percentage / 100;
                }
            }
            if ( $cap > 0 ) {
                $rate = spar_get_redeem_rate_for_currency( $store_currency );
                $rp = ( isset( $rate['points'] ) ? (float) $rate['points'] : 0.0 );
                $ra = ( isset( $rate['amount'] ) ? (float) $rate['amount'] : 0.0 );
                if ( $rp > 0 && $ra > 0 ) {
                    $cart_max_points = (int) floor( $cap * $rp / $ra );
                }
            }
        }
        if ( $cart_max_points > 0 ) {
            $points_max = min( $points_max, (int) $cart_max_points );
        }
        return max( 0, (int) $points_max );
    }

}
if ( !function_exists( 'spar_get_redeem_limits_payload' ) ) {
    /**
     * Build the redemption "limits" payload. Mirrors the 'limits' array in the
     * localized sparCartRewards data and is returned by the AJAX state-refresh
     * endpoint ( spar_ajax_get_redeem_state ) so the injected block compact tool can
     * be rebuilt with matching values when the cart changes.
     *
     * @param int    $user_points    User's available points.
     * @param string $store_currency Store currency code.
     * @param array  $options        Plugin options.
     * @return array{min:int,max:int,maxCartPercentage:int,belowMin:bool,minCartNote:string}
     */
    function spar_get_redeem_limits_payload(  $user_points, $store_currency, array $options  ) {
        $premium = function_exists( 'spar_fs' ) && spar_fs()->can_use_premium_code__premium_only();
        $redeem_points_min = ( $premium && isset( $options['redeem_points_min'] ) ? max( 0, (int) $options['redeem_points_min'] ) : 0 );
        $redeem_points_max = ( $premium && isset( $options['redeem_points_max'] ) ? max( 0, (int) $options['redeem_points_max'] ) : 0 );
        $points_max = spar_get_cart_redeem_points_max( (int) $user_points, $store_currency, $redeem_points_max );
        $min_points_attr = ( $premium && $redeem_points_min > 0 ? (int) $redeem_points_min : 0 );
        if ( $points_max > 0 && $min_points_attr > $points_max ) {
            $min_points_attr = $points_max;
        }
        $min_cart_total = ( function_exists( 'spar_get_redeem_min_cart_total' ) ? (float) spar_get_redeem_min_cart_total() : 0.0 );
        $min_note = ( $min_cart_total > 0 && function_exists( 'wc_price' ) ? sprintf( 
            /* translators: %s: minimum cart total formatted as a price. */
            __( 'A minimum cart total of %s is required to redeem points.', 'simple-points-and-rewards' ),
            html_entity_decode( wp_strip_all_tags( wc_price( $min_cart_total ) ), ENT_QUOTES, 'UTF-8' )
         ) : '' );
        return array(
            'min'               => (int) $min_points_attr,
            'max'               => (int) $points_max,
            'maxCartPercentage' => ( $premium && isset( $options['redeem_max_cart_percentage'] ) ? max( 0, min( 100, (int) $options['redeem_max_cart_percentage'] ) ) : 0 ),
            'belowMin'          => ( function_exists( 'spar_redeem_is_below_min_cart_total' ) ? (bool) spar_redeem_is_below_min_cart_total() : false ),
            'minCartNote'       => $min_note,
        );
    }

}
/**
 * Render compact Redeem Points UI inside the cart/checkout totals area (classic templates).
 * - Cart: before order total
 * - Checkout (shortcode): before order total (fallbacks support custom templates)
 */
add_action( 'woocommerce_cart_totals_before_order_total', 'spar_render_compact_redeem_totals_row', 9 );
$spar_compact_cart_hooks = [
    'woocommerce_cart_totals_before_order_total',
    'woocommerce_before_cart_totals',
    'woocommerce_cart_totals_before_shipping',
    'woocommerce_cart_totals_before_fees',
    'woocommerce_cart_totals_before_discount',
    'woocommerce_cart_totals_after_discount',
    'woocommerce_cart_totals_after_fees',
    'woocommerce_cart_totals_after_shipping',
    'woocommerce_cart_totals_after_order_total',
    'woocommerce_after_cart_totals'
];
$spar_compact_cart_hooks = apply_filters( 'spar_compact_redeem_cart_hooks', $spar_compact_cart_hooks );
if ( is_array( $spar_compact_cart_hooks ) ) {
    foreach ( $spar_compact_cart_hooks as $spar_compact_cart_hook ) {
        if ( is_string( $spar_compact_cart_hook ) && '' !== $spar_compact_cart_hook ) {
            add_action( $spar_compact_cart_hook, 'spar_render_compact_redeem_totals_row', 9 );
        }
    }
}
$spar_compact_checkout_hooks = ['woocommerce_review_order_after_shipping', 'woocommerce_review_order_before_order_total'];
$spar_compact_checkout_hooks = apply_filters( 'spar_compact_redeem_checkout_hooks', $spar_compact_checkout_hooks );
if ( is_array( $spar_compact_checkout_hooks ) ) {
    foreach ( $spar_compact_checkout_hooks as $spar_compact_hook ) {
        if ( is_string( $spar_compact_hook ) && '' !== $spar_compact_hook ) {
            add_action( $spar_compact_hook, 'spar_render_compact_redeem_totals_row', 9 );
        }
    }
}
// Custom Hooks for custom cart/checkout templates
add_action( 'spar_show_redeem_discount_compact', 'spar_render_compact_redeem_totals_row', 9 );
if ( !function_exists( 'spar_get_redeem_discount_tax_info' ) ) {
    /**
     * Return tax display info for the discount value preview.
     *
     * Reads the WooCommerce "Display prices during cart and checkout" option:
     *   - 'incl': multiply the base amount by (1 + rate) so the preview shows
     *             the full tax-inclusive value.
     *   - 'excl': keep the base amount and expose the tax rate so JS can append
     *             a "(+$X tax)" suffix.
     *
     * Returns neutral values when the option is off, the cart is unavailable,
     * or the subtotal is zero.
     *
     * @param array $options Plugin options array.
     * @return array{multiplier: float, rate: float, display_mode: string}
     */
    function spar_get_redeem_discount_tax_info(  array $options  ) {
        $none = [
            'multiplier'   => 1.0,
            'rate'         => 0.0,
            'display_mode' => '',
        ];
        if ( empty( $options['redeem_discount_value_include_tax'] ) ) {
            return $none;
        }
        try {
            $cart = ( function_exists( 'spar_get_wc_cart' ) ? spar_get_wc_cart() : null );
            if ( !$cart || $cart->is_empty() ) {
                return $none;
            }
            $subtotal = (float) $cart->get_subtotal();
            if ( $subtotal <= 0.0 ) {
                return $none;
            }
            $tax = (float) $cart->get_subtotal_tax();
            $rate = $tax / $subtotal;
            $mode = get_option( 'woocommerce_tax_display_cart', 'excl' );
            if ( 'incl' === $mode ) {
                return [
                    'multiplier'   => 1.0 + $rate,
                    'rate'         => $rate,
                    'display_mode' => 'incl',
                ];
            }
            return [
                'multiplier'   => 1.0,
                'rate'         => $rate,
                'display_mode' => 'excl',
            ];
        } catch ( Throwable $e ) {
            return $none;
        }
    }

}
if ( !function_exists( 'spar_get_redeem_discount_tax_multiplier' ) ) {
    /** @deprecated Use spar_get_redeem_discount_tax_info() for full tax data. */
    function spar_get_redeem_discount_tax_multiplier(  array $options  ) {
        return spar_get_redeem_discount_tax_info( $options )['multiplier'];
    }

}
if ( !function_exists( 'spar_redeem_is_below_min_cart_total' ) ) {
    /**
     * Whether the current cart is below the configured minimum total required to
     * redeem points. Returns false when no minimum is set (premium feature).
     *
     * @param WC_Cart|null $cart Optional cart instance.
     * @return bool
     */
    function spar_redeem_is_below_min_cart_total(  $cart = null  ) {
        if ( !function_exists( 'spar_cart_meets_redeem_min_total' ) ) {
            return false;
        }
        return !spar_cart_meets_redeem_min_total( $cart );
    }

}
if ( !function_exists( 'spar_render_redeem_min_cart_note' ) ) {
    /**
     * Render the customer-facing "minimum cart total" notice shown under the
     * redemption tool when the cart is below the required minimum. Mirrors the
     * styling of the "Maximum discount is limited to X%" note. Returns '' when no
     * minimum applies or the requirement is already met.
     *
     * @param WC_Cart|null $cart       Optional cart instance.
     * @param string       $text_color Optional hex color for the note text.
     * @return string
     */
    function spar_render_redeem_min_cart_note(  $cart = null, $text_color = ''  ) {
        $min = ( function_exists( 'spar_get_redeem_min_cart_total' ) ? spar_get_redeem_min_cart_total() : 0.0 );
        if ( $min <= 0 || !spar_redeem_is_below_min_cart_total( $cart ) ) {
            return '';
        }
        $formatted = ( function_exists( 'wc_price' ) ? wp_strip_all_tags( wc_price( $min ) ) : number_format_i18n( $min, 2 ) );
        /* translators: %s: minimum cart total formatted as a price. */
        $message = sprintf( esc_html__( 'A minimum cart total of %s is required to redeem points.', 'simple-points-and-rewards' ), esc_html( $formatted ) );
        $color = ( $text_color ? 'color:' . $text_color . ';' : 'color:#666;' );
        $style = 'font-size:10px;' . $color . 'margin:0 0 0 10px;text-align:center;font-style:italic;width:100%;display:block;clear:both;padding:0;grid-column:1/-1;';
        return '<p class="spar-redeem-min-note" style="' . esc_attr( $style ) . '">' . $message . '</p>';
    }

}
function spar_render_compact_redeem_totals_row(  $context = 'totals'  ) {
    static $spar_compact_rendered = false;
    if ( $spar_compact_rendered ) {
        return;
    }
    // Only logged-in non-banned users
    if ( !is_user_logged_in() ) {
        return;
    }
    $status = get_user_meta( get_current_user_id(), 'spar_user_status', true );
    if ( 'banned' === $status ) {
        return;
    }
    // Do not render on Blocks (handled via JS injection)
    //if ( function_exists( 'has_block' ) && ( has_block( 'woocommerce/checkout' ) || has_block( 'woocommerce/cart' ) ) ) return;
    // Settings: individual redemption toggle and theme colors
    $options = get_option( 'spar_options', [] );
    if ( empty( $options['redeem_individual_enabled'] ) ) {
        return;
    }
    $redeem_display = ( isset( $options['redeem_individual_display'] ) ? sanitize_key( $options['redeem_individual_display'] ) : 'totals' );
    $redeem_display = ( in_array( $redeem_display, ['totals', 'box', 'both'], true ) ? $redeem_display : 'totals' );
    if ( !in_array( $redeem_display, ['totals', 'both'], true ) ) {
        return;
    }
    // Must have points
    $user_id = get_current_user_id();
    $user_points = (int) spar_get_user_points( $user_id );
    if ( $user_points <= 0 ) {
        return;
    }
    $points_label = spar_get_configured_points_label();
    // Primary theme color for checkout/cart compact toggle (fallback to default)
    $theme_color_1_raw = ( isset( $options['rewards_theme_color_1'] ) && is_string( $options['rewards_theme_color_1'] ) && '' !== $options['rewards_theme_color_1'] ? $options['rewards_theme_color_1'] : '#667eea' );
    $theme_color_1 = ( function_exists( 'sanitize_hex_color' ) ? ( sanitize_hex_color( $theme_color_1_raw ) ?: '#667eea' ) : '#667eea' );
    $ppp = ( isset( $options['redeem_points_per_points'] ) ? (float) $options['redeem_points_per_points'] : 100.0 );
    $ppa = ( isset( $options['redeem_points_per_amount'] ) ? (float) $options['redeem_points_per_amount'] : 1.0 );
    $redeem_points_min = 0;
    $redeem_points_max = 0;
    $store_currency = ( function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : (( defined( 'WC_VERSION' ) ? get_option( 'woocommerce_currency' ) : 'USD' )) );
    $currency_symbol = ( function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol( $store_currency ) : $store_currency );
    $default_redeem_button_label = esc_html__( 'Add Discount to Cart', 'simple-points-and-rewards' );
    $redeem_button_text_option = ( isset( $options['redeem_button_text'] ) ? $options['redeem_button_text'] : '' );
    $redeem_button_label = ( '' !== $redeem_button_text_option ? wp_kses_post( $redeem_button_text_option ) : $default_redeem_button_label );
    $current_points = 0;
    $session = ( function_exists( 'spar_get_wc_session' ) ? spar_get_wc_session() : null );
    if ( $session ) {
        $sess = (array) $session->get( 'spar_points_redemption', [] );
        $current_points = ( isset( $sess['points'] ) ? (int) $sess['points'] : 0 );
    }
    $points_max = spar_get_cart_redeem_points_max( $user_points, $store_currency, $redeem_points_max );
    $min_points_attr = ( function_exists( 'spar_fs' ) && spar_fs()->can_use_premium_code__premium_only() && $redeem_points_min > 0 ? (int) $redeem_points_min : 0 );
    if ( $points_max > 0 && $min_points_attr > $points_max ) {
        $min_points_attr = $points_max;
    }
    // Apply min/max limits to the currently applied points (backend safety)
    if ( function_exists( 'spar_fs' ) && spar_fs()->can_use_premium_code__premium_only() ) {
        if ( $redeem_points_min > 0 && $current_points > 0 && $current_points < $redeem_points_min ) {
            $current_points = $redeem_points_min;
        }
        if ( $redeem_points_max > 0 && $current_points > 0 && $current_points > $redeem_points_max ) {
            $current_points = $redeem_points_max;
        }
    }
    if ( $points_max > 0 && $current_points > $points_max ) {
        $current_points = $points_max;
    }
    $_tax_info = spar_get_redeem_discount_tax_info( $options );
    $tax_multiplier = $_tax_info['multiplier'];
    $tax_rate = $_tax_info['rate'];
    // Premium: is the cart below the minimum total required to redeem points?
    $redeem_below_min = spar_redeem_is_below_min_cart_total();
    $spar_compact_rendered = true;
    // Output compact UI similar to Blocks injector markup
    if ( 'shortcode' === $context ) {
        ?>
        <div class="spar-redeem-compact-row spar-redeem-compact-row--shortcode">
            <?php 
        $redeem_text_color_shortcode = ( isset( $options['redeem_text_color'] ) ? sanitize_hex_color( $options['redeem_text_color'] ) : '' );
        $redeem_text_color_shortcode_css = ( $redeem_text_color_shortcode ? ';--spar-redeem-text-color:' . $redeem_text_color_shortcode : '' );
        ?>
            <div id="spar-redeem-compact-shortcode" class="spar-redeem-compact spar-redeem-compact--shortcode<?php 
        echo ( $redeem_below_min ? ' spar-redeem-blocked' : '' );
        ?>" data-currency="<?php 
        echo esc_attr( $store_currency );
        ?>" style="--spar-rewards-primary: <?php 
        echo esc_attr( $theme_color_1 );
        echo esc_attr( $redeem_text_color_shortcode_css );
        ?>;">
                <div class="spar-compact-row">
                    <div class="spar-compact-left"><?php 
        /* translators: 1: points, 2: label */
        printf( esc_html__( 'You have %1$s %2$s', 'simple-points-and-rewards' ), spar_format_points_display( $user_points, 'prominent' ), esc_html( spar_get_points_label_for_count( $user_points, $points_label, 'compact_points_balance' ) ) );
        ?></div>
                    <button type="button" class="button spar-compact-toggle-btn" aria-expanded="false"><?php 
        esc_html_e( 'Redeem Points', 'simple-points-and-rewards' );
        ?><span class="spar-caret" aria-hidden="true"></span></button>
                </div>
                <div class="spar-redeem-panel" style="display:none">
                    <div class="spar-panel-row">
                        <input type="range" class="spar-redeem-slider" min="<?php 
        echo esc_attr( $min_points_attr );
        ?>" step="1" value="<?php 
        echo esc_attr( $current_points );
        ?>" max="<?php 
        echo esc_attr( (int) $points_max );
        ?>" <?php 
        disabled( $redeem_below_min );
        ?> />
                        <div class="spar-panel-right">
                            <input type="number" class="spar-redeem-input" min="<?php 
        echo esc_attr( $min_points_attr );
        ?>" step="1" value="<?php 
        echo esc_attr( $current_points );
        ?>" max="<?php 
        echo esc_attr( (int) $points_max );
        ?>" <?php 
        disabled( $redeem_below_min );
        ?> />
                        </div>
                    </div>
                    <div class="spar-redeem-actions">
                        <div class="spar-discount-box">
                            <span class="spar-redeem-value-label"><?php 
        esc_html_e( 'Discount Value:', 'simple-points-and-rewards' );
        ?></span>
                            <span class="spar-redeem-value" data-ppp="<?php 
        echo esc_attr( $ppp );
        ?>" data-ppa="<?php 
        echo esc_attr( $ppa );
        ?>" data-currency-symbol="<?php 
        echo esc_attr( $currency_symbol );
        ?>" data-tax-multiplier="<?php 
        echo esc_attr( $tax_multiplier );
        ?>" data-tax-rate="<?php 
        echo esc_attr( $tax_rate );
        ?>">0</span>
                        </div>
                        <div class="spar-redeem-buttons">
                            <button type="button" class="button spar-redeem-apply-btn" data-nonce="<?php 
        echo esc_attr( wp_create_nonce( 'spar_points_redeem' ) );
        ?>" <?php 
        disabled( $redeem_below_min || $current_points <= 0 );
        ?>><?php 
        echo wp_kses_post( $redeem_button_label );
        ?></button>
                        </div>
                    </div>
                    <?php 
        // Display max cart percentage note if set
        if ( function_exists( 'spar_fs' ) && spar_fs()->can_use_premium_code__premium_only() ) {
            $max_percentage = ( isset( $options['redeem_max_cart_percentage'] ) ? max( 0, min( 100, (int) $options['redeem_max_cart_percentage'] ) ) : 0 );
            if ( $max_percentage > 0 ) {
                $note_color_shortcode = ( $redeem_text_color_shortcode ? 'color:' . $redeem_text_color_shortcode . ';' : 'color:#666;' );
                $note_style_shortcode = 'font-size:10px;' . $note_color_shortcode . 'margin:0 0 0 10px;text-align:center;font-style:italic;width:100%;display:block;clear:both;padding:0;grid-column:1/-1;';
                echo '<p class="spar-redeem-percentage-note" style="' . esc_attr( $note_style_shortcode ) . '">';
                /* translators: %d: percentage number */
                printf( esc_html__( 'Maximum discount is limited to %d%% of cart total.', 'simple-points-and-rewards' ), (int) $max_percentage );
                echo '</p>';
            }
        }
        // Display minimum cart total note (beside the percentage note) when the cart is below it.
        echo spar_render_redeem_min_cart_note( null, $redeem_text_color_shortcode );
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- returns pre-escaped HTML.
        ?>
                </div>
            </div>
        </div>
    <?php 
    } else {
        ?>
        <?php 
        $redeem_text_color_totals = ( isset( $options['redeem_text_color'] ) ? sanitize_hex_color( $options['redeem_text_color'] ) : '' );
        $redeem_text_color_totals_css = ( $redeem_text_color_totals ? ';--spar-redeem-text-color:' . $redeem_text_color_totals : '' );
        ?>
        <tr class="spar-redeem-compact-row">
            <th colspan="2" style="padding:0; border:0;">
                <div id="spar-redeem-compact-totals" class="spar-redeem-compact spar-redeem-compact--totals<?php 
        echo ( $redeem_below_min ? ' spar-redeem-blocked' : '' );
        ?>" data-currency="<?php 
        echo esc_attr( $store_currency );
        ?>" style="--spar-rewards-primary: <?php 
        echo esc_attr( $theme_color_1 );
        echo esc_attr( $redeem_text_color_totals_css );
        ?>;">
                    <div class="spar-compact-row">
                        <div class="spar-compact-left"><?php 
        /* translators: 1: points, 2: label */
        printf( esc_html__( 'You have %1$s %2$s', 'simple-points-and-rewards' ), spar_format_points_display( $user_points, 'prominent' ), esc_html( spar_get_points_label_for_count( $user_points, $points_label, 'compact_points_balance' ) ) );
        ?></div>
                        <button type="button" class="button spar-compact-toggle-btn" aria-expanded="false"><?php 
        esc_html_e( 'Redeem Points', 'simple-points-and-rewards' );
        ?><span class="spar-caret" aria-hidden="true"></span></button>
                    </div>
                    <div class="spar-redeem-panel" style="display:none">
                        <div class="spar-panel-row">
                            <input type="range" class="spar-redeem-slider" min="<?php 
        echo esc_attr( $min_points_attr );
        ?>" step="1" value="<?php 
        echo esc_attr( $current_points );
        ?>" max="<?php 
        echo esc_attr( (int) $points_max );
        ?>" <?php 
        disabled( $redeem_below_min );
        ?> />
                            <div class="spar-panel-right">
                                <input type="number" class="spar-redeem-input" min="<?php 
        echo esc_attr( $min_points_attr );
        ?>" step="1" value="<?php 
        echo esc_attr( $current_points );
        ?>" max="<?php 
        echo esc_attr( (int) $points_max );
        ?>" <?php 
        disabled( $redeem_below_min );
        ?> />
                            </div>
                        </div>
                        <div class="spar-redeem-actions">
                            <div class="spar-discount-box">
                                <span class="spar-redeem-value-label"><?php 
        esc_html_e( 'Discount Value:', 'simple-points-and-rewards' );
        ?></span>
                                <span class="spar-redeem-value" data-ppp="<?php 
        echo esc_attr( $ppp );
        ?>" data-ppa="<?php 
        echo esc_attr( $ppa );
        ?>" data-currency-symbol="<?php 
        echo esc_attr( $currency_symbol );
        ?>" data-tax-multiplier="<?php 
        echo esc_attr( $tax_multiplier );
        ?>" data-tax-rate="<?php 
        echo esc_attr( $tax_rate );
        ?>">0</span>
                            </div>
                            <div class="spar-redeem-buttons">
                                <button type="button" class="button spar-redeem-apply-btn" data-nonce="<?php 
        echo esc_attr( wp_create_nonce( 'spar_points_redeem' ) );
        ?>" <?php 
        disabled( $redeem_below_min || $current_points <= 0 );
        ?>><?php 
        echo wp_kses_post( $redeem_button_label );
        ?></button>
                            </div>
                        </div>
                        <?php 
        // Display max cart percentage note if set
        if ( function_exists( 'spar_fs' ) && spar_fs()->can_use_premium_code__premium_only() ) {
            $max_percentage = ( isset( $options['redeem_max_cart_percentage'] ) ? max( 0, min( 100, (int) $options['redeem_max_cart_percentage'] ) ) : 0 );
            if ( $max_percentage > 0 ) {
                $note_color_totals = ( $redeem_text_color_totals ? 'color:' . $redeem_text_color_totals . ';' : 'color:#666;' );
                $note_style_totals = 'font-size:10px;' . $note_color_totals . 'margin:10px 0 0 0;text-align:center;font-style:italic;width:100%;display:block;clear:both;padding:0;grid-column:1/-1;';
                echo '<p class="spar-redeem-percentage-note" style="' . esc_attr( $note_style_totals ) . '">';
                /* translators: %d: percentage number */
                printf( esc_html__( 'Maximum discount is limited to %d%% of cart total.', 'simple-points-and-rewards' ), (int) $max_percentage );
                echo '</p>';
            }
        }
        // Display minimum cart total note (beside the percentage note) when the cart is below it.
        echo spar_render_redeem_min_cart_note( null, $redeem_text_color_totals );
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- returns pre-escaped HTML.
        ?>
                    </div>
                </div>
            </th>
        </tr>
    <?php 
    }
}

/**
 * Enqueue compact redeem assets and localize data for shortcode context.
 */
function spar_enqueue_compact_redeem_assets() {
    if ( !function_exists( 'wp_enqueue_script' ) || !function_exists( 'wp_enqueue_style' ) ) {
        return;
    }
    if ( !wp_style_is( 'spar-cart-checkout-rewards', 'registered' ) ) {
        wp_register_style(
            'spar-cart-checkout-rewards',
            SPAR_PLUGIN_URL . 'assets/css/cart-checkout-rewards.css',
            [],
            SPAR_VERSION
        );
    }
    wp_enqueue_style( 'spar-cart-checkout-rewards' );
    if ( !wp_script_is( 'spar-cart-checkout-rewards', 'registered' ) ) {
        wp_register_script(
            'spar-cart-checkout-rewards',
            SPAR_PLUGIN_URL . 'assets/js/cart-checkout-rewards.js',
            ['jquery'],
            SPAR_VERSION,
            true
        );
    }
    wp_enqueue_script( 'spar-cart-checkout-rewards' );
    $session = ( function_exists( 'spar_get_wc_session' ) ? spar_get_wc_session() : null );
    if ( $session ) {
        $current_redemption = (array) $session->get( 'spar_points_redemption', [] );
    } else {
        $current_redemption = [];
    }
    $user_id = get_current_user_id();
    $user_points = ( is_user_logged_in() ? spar_get_user_points( $user_id ) : 0 );
    $points_label = spar_get_configured_points_label();
    $options = get_option( 'spar_options', [] );
    $redeem_enabled = (bool) ($options['redeem_individual_enabled'] ?? false);
    $redeem_display = ( isset( $options['redeem_individual_display'] ) ? sanitize_key( $options['redeem_individual_display'] ) : 'totals' );
    $redeem_display = ( in_array( $redeem_display, ['totals', 'box', 'both'], true ) ? $redeem_display : 'totals' );
    $redeem_ppp = (float) ($options['redeem_points_per_points'] ?? 100);
    $redeem_ppa = (float) ($options['redeem_points_per_amount'] ?? 1);
    $redeem_rates = (array) ($options['redeem_currency_rates'] ?? []);
    $redeem_points_min = ( isset( $options['redeem_points_min'] ) ? max( 0, (int) $options['redeem_points_min'] ) : 0 );
    $redeem_points_max = ( isset( $options['redeem_points_max'] ) ? max( 0, (int) $options['redeem_points_max'] ) : 0 );
    $store_currency = ( function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : (( defined( 'WC_VERSION' ) ? get_option( 'woocommerce_currency' ) : 'USD' )) );
    $points_max = spar_get_cart_redeem_points_max( $user_points, $store_currency, ( function_exists( 'spar_fs' ) && spar_fs()->can_use_premium_code__premium_only() ? $redeem_points_max : 0 ) );
    $min_points_attr = ( function_exists( 'spar_fs' ) && spar_fs()->can_use_premium_code__premium_only() && $redeem_points_min > 0 ? (int) $redeem_points_min : 0 );
    if ( $points_max > 0 && $min_points_attr > $points_max ) {
        $min_points_attr = $points_max;
    }
    $default_redeem_button_label = esc_html__( 'Add Discount to Cart', 'simple-points-and-rewards' );
    $redeem_button_text_option = ( isset( $options['redeem_button_text'] ) ? $options['redeem_button_text'] : '' );
    $redeem_button_label = ( '' !== $redeem_button_text_option ? wp_kses_post( $redeem_button_text_option ) : $default_redeem_button_label );
    $price_decimals = ( function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2 );
    $price_decimal_sep = ( function_exists( 'wc_get_price_decimal_separator' ) ? wc_get_price_decimal_separator() : '.' );
    $price_thousand_sep = ( function_exists( 'wc_get_price_thousand_separator' ) ? wc_get_price_thousand_separator() : ',' );
    $_tax_info_localize = spar_get_redeem_discount_tax_info( $options );
    $tax_multiplier_localize = $_tax_info_localize['multiplier'];
    $tax_rate_localize = $_tax_info_localize['rate'];
    $tax_display_mode_localize = $_tax_info_localize['display_mode'];
    wp_localize_script( 'spar-cart-checkout-rewards', 'sparCartRewards', [
        'ajaxUrl'                => admin_url( 'admin-ajax.php' ),
        'nonce'                  => wp_create_nonce( 'spar_cart_rewards' ),
        'redeemNonce'            => wp_create_nonce( 'spar_redeem_reward' ),
        'pointsRedeemNonce'      => wp_create_nonce( 'spar_points_redeem' ),
        'applyVoucherNonce'      => wp_create_nonce( 'spar_apply_voucher' ),
        'priceDecimals'          => $price_decimals,
        'priceDecimalSeparator'  => $price_decimal_sep,
        'priceThousandSeparator' => $price_thousand_sep,
        'userPoints'             => $user_points,
        'pointsLabel'            => spar_get_points_label_for_count( $user_points, $points_label, 'cart_rewards_localized_balance' ),
        'pointsPrefix'           => spar_get_points_prefix(),
        'pointsIconProminent'    => spar_get_points_icon_html( 'prominent' ),
        'checkoutUrl'            => ( function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : '' ),
        'isBlockCheckout'        => false,
        'isCart'                 => false,
        'isOrderReceived'        => false,
        'taxMultiplier'          => $tax_multiplier_localize,
        'taxRate'                => $tax_rate_localize,
        'taxDisplayMode'         => $tax_display_mode_localize,
        'redeem'                 => [
            'enabled' => $redeem_enabled,
            'base'    => [
                'points'   => $redeem_ppp,
                'amount'   => $redeem_ppa,
                'currency' => $store_currency,
            ],
            'rates'   => $redeem_rates,
            'current' => $current_redemption,
            'limits'  => [
                'min'               => $min_points_attr,
                'max'               => (int) $points_max,
                'maxCartPercentage' => ( function_exists( 'spar_fs' ) && spar_fs()->can_use_premium_code__premium_only() && isset( $options['redeem_max_cart_percentage'] ) ? max( 0, min( 100, (int) $options['redeem_max_cart_percentage'] ) ) : 0 ),
                'belowMin'          => ( function_exists( 'spar_redeem_is_below_min_cart_total' ) ? (bool) spar_redeem_is_below_min_cart_total() : false ),
                'minCartNote'       => ( function_exists( 'spar_get_redeem_min_cart_total' ) && spar_get_redeem_min_cart_total() > 0 && function_exists( 'wc_price' ) ? sprintf( 
                    /* translators: %s: minimum cart total formatted as a price. */
                    __( 'A minimum cart total of %s is required to redeem points.', 'simple-points-and-rewards' ),
                    html_entity_decode( wp_strip_all_tags( wc_price( spar_get_redeem_min_cart_total() ) ), ENT_QUOTES, 'UTF-8' )
                 ) : '' ),
            ],
        ],
        'redeemDisplay'          => $redeem_display,
        'redeemTextColor'        => ( isset( $options['redeem_text_color'] ) ? ( sanitize_hex_color( $options['redeem_text_color'] ) ?: '' ) : '' ),
        'enableConfetti'         => !empty( $options['checkout_box_enable_confetti'] ),
        'strings'                => [
            'loadingRewards'         => esc_html__( 'Loading rewards...', 'simple-points-and-rewards' ),
            'redeemSuccess'          => esc_html__( 'Reward redeemed successfully!', 'simple-points-and-rewards' ),
            'applySuccess'           => esc_html__( 'Voucher applied successfully!', 'simple-points-and-rewards' ),
            'error'                  => esc_html__( 'Something went wrong. Please try again.', 'simple-points-and-rewards' ),
            'redeemPoints'           => esc_html__( 'Redeem', 'simple-points-and-rewards' ),
            'add'                    => $redeem_button_label,
            'remove'                 => esc_html__( 'Remove', 'simple-points-and-rewards' ),
            'value'                  => esc_html__( 'Discount Value', 'simple-points-and-rewards' ),
            'pointsRedemptionPrefix' => esc_html__( 'Points Redemption', 'simple-points-and-rewards' ),
            'redeemRewards'          => esc_html__( 'Redeem Points', 'simple-points-and-rewards' ),
            'close'                  => esc_html__( 'Close', 'simple-points-and-rewards' ),
            'maxPercentageNote'      => esc_html__( 'Maximum discount is limited to %d%% of cart total.', 'simple-points-and-rewards' ),
            'taxSuffix'              => esc_html__( 'tax', 'simple-points-and-rewards' ),
        ],
    ] );
}

/**
 * Append a plain "Remove" link to the Points Redemption fee line in totals (classic cart/checkout).
 */
add_filter(
    'woocommerce_cart_totals_fee_html',
    'spar_append_remove_to_points_redemption_fee',
    10,
    2
);
function spar_append_remove_to_points_redemption_fee(  $fee_html, $fee  ) {
    // Only on cart/checkout screens
    if ( !(function_exists( 'is_cart' ) && is_cart()) && !(function_exists( 'is_checkout' ) && is_checkout()) ) {
        return $fee_html;
    }
    $name = ( is_object( $fee ) && isset( $fee->name ) ? (string) $fee->name : '' );
    $prefix = esc_html__( 'Points Redemption', 'simple-points-and-rewards' );
    if ( $name && false !== stripos( $name, $prefix ) ) {
        $remove = esc_html__( 'Remove', 'simple-points-and-rewards' );
        $nonce = ( function_exists( 'wp_create_nonce' ) ? wp_create_nonce( 'spar_points_redeem' ) : '' );
        $fee_html .= ' <span class="spar-sep">-</span> <a href="#" class="link-button spar-redeem-remove-btn" data-nonce="' . esc_attr( $nonce ) . '">' . $remove . '</a>';
    }
    return $fee_html;
}

/**
 * Display rewards box on checkout page
 */
function spar_display_checkout_rewards_box() {
    // Prevent duplicate rendering when page builders (e.g. Divi) use multiple
    // checkout modules that each fire woocommerce_before_checkout_form.
    static $spar_checkout_rewards_rendered = false;
    if ( $spar_checkout_rewards_rendered ) {
        return;
    }
    if ( !is_checkout() ) {
        return;
    }
    // Respect setting: show on checkout
    if ( !spar_get_option( '', 'show_on_checkout' ) ) {
        return;
    }
    // Don't show on the order received (thank you) page
    if ( function_exists( 'is_order_received_page' ) && is_order_received_page() || function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' ) ) {
        return;
    }
    // Don't show on block checkout - we handle that separately
    if ( has_block( 'woocommerce/checkout' ) ) {
        return;
    }
    $spar_checkout_rewards_rendered = true;
    spar_render_rewards_box( 'checkout' );
}

/**
 * Display rewards box on cart page (classic/cart template)
 */
function spar_display_cart_rewards_box() {
    // Prevent duplicate rendering when page builders (e.g. Divi) use multiple
    // cart modules that each fire woocommerce_before_cart_totals.
    static $spar_cart_rewards_rendered = false;
    if ( $spar_cart_rewards_rendered ) {
        return;
    }
    if ( !function_exists( 'is_cart' ) || !is_cart() ) {
        return;
    }
    // Respect setting: show on cart
    if ( !spar_get_option( '', 'show_on_cart' ) ) {
        return;
    }
    $spar_cart_rewards_rendered = true;
    // Render rewards box for cart context
    spar_render_rewards_box( 'cart' );
}

/**
 * Enqueue assets for block checkout
 */
function spar_enqueue_block_checkout_assets() {
    // Allow enqueue on cart, checkout, and order received (thank you) page
    $is_order_received = function_exists( 'is_order_received_page' ) && is_order_received_page() || function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' );
    $on_checkout = ( function_exists( 'is_checkout' ) ? is_checkout() : false );
    $on_cart = ( function_exists( 'is_cart' ) ? is_cart() : false );
    if ( !$on_checkout && !$on_cart && !$is_order_received ) {
        return;
    }
    // Respect settings to avoid enqueuing when box is hidden
    if ( $on_checkout && !$is_order_received && !spar_get_option( '', 'show_on_checkout' ) ) {
        return;
    }
    if ( $on_cart && !spar_get_option( '', 'show_on_cart' ) ) {
        return;
    }
    if ( $is_order_received && !spar_get_option( '', 'show_on_thankyou' ) ) {
        return;
    }
    if ( !is_user_logged_in() && !spar_get_option( '', 'show_for_guests' ) ) {
        return;
    }
    // Do not enqueue any rewards assets for banned users
    if ( is_user_logged_in() ) {
        $spar_status = get_user_meta( get_current_user_id(), 'spar_user_status', true );
        if ( 'banned' === $spar_status ) {
            return;
        }
    }
    // Only enqueue once to prevent duplicate scripts
    static $assets_enqueued = false;
    if ( $assets_enqueued ) {
        return;
    }
    $assets_enqueued = true;
    // Register then enqueue styles/scripts per WP guidelines
    if ( !wp_style_is( 'spar-cart-checkout-rewards', 'registered' ) ) {
        wp_register_style(
            'spar-cart-checkout-rewards',
            SPAR_PLUGIN_URL . 'assets/css/cart-checkout-rewards.css',
            [],
            SPAR_VERSION
        );
    }
    wp_enqueue_style( 'spar-cart-checkout-rewards' );
    // Enqueue scripts
    if ( !wp_script_is( 'spar-cart-checkout-rewards', 'registered' ) ) {
        wp_register_script(
            'spar-cart-checkout-rewards',
            SPAR_PLUGIN_URL . 'assets/js/cart-checkout-rewards.js',
            ['jquery'],
            SPAR_VERSION,
            true
        );
    }
    wp_enqueue_script( 'spar-cart-checkout-rewards' );
    // Localize script for both cart and checkout
    $user_id = get_current_user_id();
    $user_points = ( is_user_logged_in() ? spar_get_user_points( $user_id ) : 0 );
    $reward_vouchers_enabled = ( function_exists( 'spar_rewards_vouchers_enabled' ) ? spar_rewards_vouchers_enabled() : true );
    if ( null === $reward_vouchers_enabled ) {
        $reward_vouchers_enabled = true;
    }
    $reward_vouchers_enabled = (bool) $reward_vouchers_enabled;
    $points_label = spar_get_configured_points_label();
    $rewards_label = ( spar_get_option( '', 'rewards_label' ) ?: esc_html__( 'Rewards', 'simple-points-and-rewards' ) );
    // Load plugin options
    $options = get_option( 'spar_options', [] );
    $checkout_redeem_button_text = ( isset( $options['checkout_box_redeem_button_text'] ) ? sanitize_text_field( $options['checkout_box_redeem_button_text'] ) : '' );
    $checkout_redeem_button_label = ( $checkout_redeem_button_text !== '' ? $checkout_redeem_button_text : esc_html__( 'Redeem Rewards', 'simple-points-and-rewards' ) );
    // Resolve custom button text for "Add Discount to Cart" (empty => use localized default)
    $default_redeem_button_label = esc_html__( 'Add Discount to Cart', 'simple-points-and-rewards' );
    $redeem_button_text_option = ( isset( $options['redeem_button_text'] ) ? $options['redeem_button_text'] : '' );
    $redeem_button_label = ( '' !== $redeem_button_text_option ? wp_kses_post( $redeem_button_text_option ) : $default_redeem_button_label );
    // Redemption settings
    $redeem_enabled = (bool) ($options['redeem_individual_enabled'] ?? false);
    $redeem_display = ( isset( $options['redeem_individual_display'] ) ? sanitize_key( $options['redeem_individual_display'] ) : 'totals' );
    $redeem_display = ( in_array( $redeem_display, ['totals', 'box', 'both'], true ) ? $redeem_display : 'totals' );
    $redeem_ppp = (float) ($options['redeem_points_per_points'] ?? 100);
    $redeem_ppa = (float) ($options['redeem_points_per_amount'] ?? 1);
    $redeem_rates = (array) ($options['redeem_currency_rates'] ?? []);
    // Optional min/max points per redemption (premium feature). If empty or zero, treated as no limit.
    $redeem_points_min = ( isset( $options['redeem_points_min'] ) ? max( 0, (int) $options['redeem_points_min'] ) : 0 );
    $redeem_points_max = ( isset( $options['redeem_points_max'] ) ? max( 0, (int) $options['redeem_points_max'] ) : 0 );
    $store_currency = ( function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : (( defined( 'WC_VERSION' ) ? get_option( 'woocommerce_currency' ) : 'USD' )) );
    $current_redemption = [];
    $session = ( function_exists( 'spar_get_wc_session' ) ? spar_get_wc_session() : null );
    if ( $session ) {
        $current_redemption = (array) $session->get( 'spar_points_redemption', [] );
    }
    $points_max = spar_get_cart_redeem_points_max( $user_points, $store_currency, ( function_exists( 'spar_fs' ) && spar_fs()->can_use_premium_code__premium_only() ? $redeem_points_max : 0 ) );
    $min_points_attr = ( function_exists( 'spar_fs' ) && spar_fs()->can_use_premium_code__premium_only() && $redeem_points_min > 0 ? (int) $redeem_points_min : 0 );
    if ( $points_max > 0 && $min_points_attr > $points_max ) {
        $min_points_attr = $points_max;
    }
    $price_decimals = ( function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2 );
    $price_decimal_sep = ( function_exists( 'wc_get_price_decimal_separator' ) ? wc_get_price_decimal_separator() : '.' );
    $price_thousand_sep = ( function_exists( 'wc_get_price_thousand_separator' ) ? wc_get_price_thousand_separator() : ',' );
    $_tax_info_localize = spar_get_redeem_discount_tax_info( $options );
    $tax_multiplier_localize = $_tax_info_localize['multiplier'];
    $tax_rate_localize = $_tax_info_localize['rate'];
    $tax_display_mode_localize = $_tax_info_localize['display_mode'];
    wp_localize_script( 'spar-cart-checkout-rewards', 'sparCartRewards', [
        'ajaxUrl'                => admin_url( 'admin-ajax.php' ),
        'nonce'                  => wp_create_nonce( 'spar_cart_rewards' ),
        'redeemNonce'            => wp_create_nonce( 'spar_redeem_reward' ),
        'pointsRedeemNonce'      => wp_create_nonce( 'spar_points_redeem' ),
        'applyVoucherNonce'      => wp_create_nonce( 'spar_apply_voucher' ),
        'priceDecimals'          => $price_decimals,
        'priceDecimalSeparator'  => $price_decimal_sep,
        'priceThousandSeparator' => $price_thousand_sep,
        'userPoints'             => $user_points,
        'pointsLabel'            => spar_get_points_label_for_count( $user_points, $points_label, 'cart_rewards_localized_balance' ),
        'pointsPrefix'           => spar_get_points_prefix(),
        'pointsIconProminent'    => spar_get_points_icon_html( 'prominent' ),
        'checkoutUrl'            => ( function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : '' ),
        'isBlockCheckout'        => $on_checkout,
        'isCart'                 => $on_cart,
        'isOrderReceived'        => $is_order_received,
        'taxMultiplier'          => $tax_multiplier_localize,
        'taxRate'                => $tax_rate_localize,
        'taxDisplayMode'         => $tax_display_mode_localize,
        'redeem'                 => [
            'enabled' => $redeem_enabled,
            'base'    => [
                'points'   => $redeem_ppp,
                'amount'   => $redeem_ppa,
                'currency' => $store_currency,
            ],
            'rates'   => $redeem_rates,
            'current' => $current_redemption,
            'limits'  => [
                'min'               => $min_points_attr,
                'max'               => (int) $points_max,
                'maxCartPercentage' => ( function_exists( 'spar_fs' ) && spar_fs()->can_use_premium_code__premium_only() && isset( $options['redeem_max_cart_percentage'] ) ? max( 0, min( 100, (int) $options['redeem_max_cart_percentage'] ) ) : 0 ),
                'belowMin'          => ( function_exists( 'spar_redeem_is_below_min_cart_total' ) ? (bool) spar_redeem_is_below_min_cart_total() : false ),
                'minCartNote'       => ( function_exists( 'spar_get_redeem_min_cart_total' ) && spar_get_redeem_min_cart_total() > 0 && function_exists( 'wc_price' ) ? sprintf( 
                    /* translators: %s: minimum cart total formatted as a price. */
                    __( 'A minimum cart total of %s is required to redeem points.', 'simple-points-and-rewards' ),
                    html_entity_decode( wp_strip_all_tags( wc_price( spar_get_redeem_min_cart_total() ) ), ENT_QUOTES, 'UTF-8' )
                 ) : '' ),
            ],
        ],
        'redeemDisplay'          => $redeem_display,
        'redeemTextColor'        => ( isset( $options['redeem_text_color'] ) ? ( sanitize_hex_color( $options['redeem_text_color'] ) ?: '' ) : '' ),
        'enableConfetti'         => !empty( $options['checkout_box_enable_confetti'] ),
        'strings'                => [
            'loadingRewards'         => esc_html__( 'Loading rewards...', 'simple-points-and-rewards' ),
            'redeemSuccess'          => esc_html__( 'Reward redeemed successfully!', 'simple-points-and-rewards' ),
            'applySuccess'           => esc_html__( 'Voucher applied successfully!', 'simple-points-and-rewards' ),
            'error'                  => esc_html__( 'Something went wrong. Please try again.', 'simple-points-and-rewards' ),
            'redeemPoints'           => esc_html__( 'Redeem', 'simple-points-and-rewards' ),
            'add'                    => $redeem_button_label,
            'remove'                 => esc_html__( 'Remove', 'simple-points-and-rewards' ),
            'value'                  => esc_html__( 'Discount Value', 'simple-points-and-rewards' ),
            'pointsRedemptionPrefix' => esc_html__( 'Points Redemption', 'simple-points-and-rewards' ),
            'redeemRewards'          => $checkout_redeem_button_label,
            'close'                  => esc_html__( 'Close', 'simple-points-and-rewards' ),
            'maxPercentageNote'      => esc_html__( 'Maximum discount is limited to %d%% of cart total.', 'simple-points-and-rewards' ),
            'taxSuffix'              => esc_html__( 'tax', 'simple-points-and-rewards' ),
        ],
    ] );
    // Enqueue confetti library when checkout box confetti is enabled
    if ( !empty( $options['checkout_box_enable_confetti'] ) ) {
        wp_enqueue_script(
            'spar-confetti',
            SPAR_PLUGIN_URL . 'assets/js/confetti/confetti.browser.js',
            [],
            '1.9.3',
            true
        );
    }
    // Inject helper JS on both Block Checkout and Cart pages for DOM placement tweaks
    $should_inject = $on_checkout && !$is_order_received || $on_cart;
    if ( $should_inject ) {
        $context = ( $on_cart ? 'cart' : 'checkout' );
        $rewards_html = spar_get_rewards_box_html( $context );
        if ( $rewards_html ) {
            // Register small injector script and pass HTML via localization
            wp_register_script(
                'spar-cart-checkout-inject',
                SPAR_PLUGIN_URL . 'assets/js/cart-checkout-inject.js',
                ['jquery', 'spar-cart-checkout-rewards'],
                SPAR_VERSION,
                true
            );
            wp_localize_script( 'spar-cart-checkout-inject', 'sparCheckoutInject', [
                'html'    => $rewards_html,
                'cssHref' => SPAR_PLUGIN_URL . 'assets/css/cart-checkout-rewards.css',
            ] );
            wp_enqueue_script( 'spar-cart-checkout-inject' );
        }
    }
}

/**
 * Inject rewards box into block checkout
 */
// Legacy function no longer outputs <script>; kept for BC if referenced elsewhere.
function spar_inject_block_checkout_rewards() {
    /* No output. Inline JS now added via wp_add_inline_script in spar_enqueue_block_checkout_assets() */
}

/**
 * Prepend rewards box HTML to the Checkout Block output.
 * This mirrors classic checkout placement (top of checkout content) without client-side JS injection.
 */
function spar_prepend_rewards_before_content_on_checkout_block(  $content  ) {
    if ( is_admin() || !is_singular() || !in_the_loop() || !is_main_query() ) {
        return $content;
    }
    if ( !function_exists( 'has_block' ) || !has_block( 'woocommerce/checkout' ) ) {
        return $content;
    }
    // Respect setting: show on checkout
    if ( !spar_get_option( '', 'show_on_checkout' ) ) {
        return $content;
    }
    // Don't show on the order received (thank you) page
    if ( function_exists( 'is_order_received_page' ) && is_order_received_page() || function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' ) ) {
        return $content;
    }
    if ( !is_user_logged_in() && !spar_get_option( '', 'show_for_guests' ) ) {
        return $content;
    }
    // Banned users should not see the rewards box
    if ( is_user_logged_in() ) {
        $spar_status = get_user_meta( get_current_user_id(), 'spar_user_status', true );
        if ( 'banned' === $spar_status ) {
            return $content;
        }
    }
    // Build rewards HTML; internal rendering bails if user has nothing to show
    $rewards_html = spar_get_rewards_box_html( 'checkout' );
    if ( empty( $rewards_html ) ) {
        return $content;
    }
    // Ensure assets are present (styles + JS + inline fallback)
    spar_enqueue_block_checkout_assets();
    // Prepend rewards box before the page content
    return $rewards_html . $content;
}

/**
 * Prepend rewards box HTML to the Cart Block output, mirroring classic cart placement.
 */
function spar_prepend_rewards_before_content_on_cart_block(  $content  ) {
    if ( is_admin() || !is_singular() || !in_the_loop() || !is_main_query() ) {
        return $content;
    }
    if ( !function_exists( 'has_block' ) || !has_block( 'woocommerce/cart' ) ) {
        return $content;
    }
    // Respect setting: show on cart
    if ( !spar_get_option( '', 'show_on_cart' ) ) {
        return $content;
    }
    if ( !is_user_logged_in() && !spar_get_option( '', 'show_for_guests' ) ) {
        return $content;
    }
    // Banned users should not see the rewards box
    if ( is_user_logged_in() ) {
        $spar_status = get_user_meta( get_current_user_id(), 'spar_user_status', true );
        if ( 'banned' === $spar_status ) {
            return $content;
        }
    }
    // Build rewards HTML; internal rendering bails if user has nothing to show
    $rewards_html = spar_get_rewards_box_html( 'cart' );
    if ( empty( $rewards_html ) ) {
        return $content;
    }
    // Ensure assets are present (styles + JS)
    spar_enqueue_block_checkout_assets();
    // Prepend rewards box before the page content
    return $rewards_html . $content;
}

/**
 * Get rewards box HTML as string
 */
function spar_get_rewards_box_html(  $page = 'cart', $args = []  ) {
    ob_start();
    spar_render_rewards_box( $page, $args );
    return ob_get_clean();
}

/**
 * Calculate fixed points delta from conditional rules for the cart summary.
 *
 * @param WC_Cart $cart Cart object.
 * @param int     $user_id Current user ID.
 * @param float   $level_multiplier User level multiplier.
 * @return int Scaled fixed points delta.
 */
function spar_get_cart_fixed_points_delta_from_rules(  $cart, $user_id, $level_multiplier  ) {
    if ( !$cart || !is_object( $cart ) || !function_exists( 'sparp_cr_get_best_rule_for_cart__premium_only' ) ) {
        return 0;
    }
    $best_order = sparp_cr_get_best_rule_for_cart__premium_only( $cart, $user_id, 'order' );
    $best_fixed = sparp_cr_get_best_rule_for_cart__premium_only( $cart, $user_id, 'order_fixed' );
    $rule_order = ( isset( $best_order['rule'] ) && is_array( $best_order['rule'] ) ? $best_order['rule'] : null );
    $rule_fixed = ( isset( $best_fixed['rule'] ) && is_array( $best_fixed['rule'] ) ? $best_fixed['rule'] : null );
    $rule_order_id = ( is_array( $rule_order ) && isset( $rule_order['id'] ) ? sanitize_text_field( (string) $rule_order['id'] ) : '' );
    $rule_fixed_id = ( is_array( $rule_fixed ) && isset( $rule_fixed['id'] ) ? sanitize_text_field( (string) $rule_fixed['id'] ) : '' );
    $fixed_delta = 0;
    if ( $rule_order ) {
        $fixed_delta += ( isset( $rule_order['fixed_points_delta'] ) ? (int) $rule_order['fixed_points_delta'] : 0 );
    }
    if ( $rule_fixed && ('' === $rule_order_id || $rule_fixed_id !== $rule_order_id) ) {
        $fixed_delta += ( isset( $rule_fixed['fixed_points_delta'] ) ? (int) $rule_fixed['fixed_points_delta'] : 0 );
    }
    if ( 0 === $fixed_delta ) {
        return 0;
    }
    $level_multiplier = max( 0.0, (float) $level_multiplier );
    $delta_scaled = (float) $fixed_delta * $level_multiplier;
    return ( $delta_scaled >= 0 ? (int) floor( $delta_scaled ) : (int) ceil( $delta_scaled ) );
}

/**
 * Build a summary table of points sources for the checkout rewards box.
 *
 * @param WC_Cart $cart Cart object.
 * @param int     $user_id Current user ID.
 * @param string  $points_label Points label.
 * @param int     $earning_points Total points shown in message.
 * @param int     $base_points Base spend points.
 * @param int     $fixed_points Fixed tier points.
 * @param float   $level_multiplier User level multiplier.
 * @return array<int, array{label:string,value:string}>
 */
function spar_build_cart_points_summary_rows(
    $cart,
    $user_id,
    $points_label,
    $earning_points,
    $base_points,
    $fixed_points,
    $level_multiplier
) {
    $rows = [];
    if ( !$cart || !is_object( $cart ) || !method_exists( $cart, 'get_cart' ) ) {
        return $rows;
    }
    $prefs = ( function_exists( 'spar_get_points_calculation_preferences' ) ? spar_get_points_calculation_preferences( 'order' ) : [
        'mode'             => 'subtotal_after_discount',
        'include_shipping' => false,
        'include_taxes'    => false,
    ] );
    $mode = ( isset( $prefs['mode'] ) ? (string) $prefs['mode'] : 'subtotal_after_discount' );
    $include_taxes = !empty( $prefs['include_taxes'] );
    $currency = ( function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : (( defined( 'WC_VERSION' ) ? get_option( 'woocommerce_currency' ) : 'USD' )) );
    $rate = ( function_exists( 'spar_get_order_points_rate' ) ? (float) spar_get_order_points_rate( $currency ) : 0.0 );
    $rate = max( 0.0, (float) $rate );
    $items_total_base = 0;
    $items_total_adjusted = 0;
    $item_rows = [];
    foreach ( (array) $cart->get_cart() as $item ) {
        $product_id = ( isset( $item['product_id'] ) ? (int) $item['product_id'] : 0 );
        if ( $product_id <= 0 ) {
            continue;
        }
        $product = ( isset( $item['data'] ) && is_object( $item['data'] ) ? $item['data'] : null );
        if ( !$product && function_exists( 'wc_get_product' ) ) {
            $product = wc_get_product( $product_id );
        }
        $name = ( $product && method_exists( $product, 'get_name' ) ? $product->get_name() : esc_html__( 'Item', 'simple-points-and-rewards' ) );
        $qty = ( isset( $item['quantity'] ) ? (int) $item['quantity'] : 1 );
        $line_before = ( isset( $item['line_subtotal'] ) ? (float) $item['line_subtotal'] : 0.0 );
        $line_after = ( isset( $item['line_total'] ) ? (float) $item['line_total'] : 0.0 );
        $line_tax = ( isset( $item['line_tax'] ) ? (float) $item['line_tax'] : 0.0 );
        $line_total = ( 'total_before_discounts' === $mode ? $line_before : $line_after );
        if ( $include_taxes ) {
            $line_total += $line_tax;
        }
        $base_item_points = (int) floor( max( 0.0, $line_total ) * $rate );
        $default_item_points = (int) floor( (float) $base_item_points * max( 0.0, (float) $level_multiplier ) );
        $item_points = $default_item_points;
        if ( function_exists( 'sparp_cr_get_product_points_with_rules__premium_only' ) ) {
            $item_points = sparp_cr_get_product_points_with_rules__premium_only(
                $base_item_points,
                $level_multiplier,
                $product_id,
                $user_id,
                'order'
            );
        }
        $items_total_base += max( 0, (int) $default_item_points );
        $items_total_adjusted += max( 0, (int) $item_points );
        if ( $default_item_points <= 0 && $item_points <= 0 ) {
            continue;
        }
        $item_rows[] = [
            'label'  => sprintf( '%1$s × %2$d', $name, max( 1, $qty ) ),
            'points' => max( 0, (int) $item_points ),
        ];
    }
    $fixed_after = (int) floor( (float) $fixed_points * max( 0.0, (float) $level_multiplier ) );
    $cart_delta = (int) $earning_points - (int) ($items_total_adjusted + $fixed_after);
    $fixed_delta_scaled = spar_get_cart_fixed_points_delta_from_rules( $cart, $user_id, $level_multiplier );
    if ( 0 !== $fixed_delta_scaled ) {
        $cart_delta -= (int) $fixed_delta_scaled;
    }
    if ( abs( (float) $level_multiplier - 1.0 ) > 0.0001 ) {
        $rows[] = [
            'label' => esc_html__( 'Level multiplier', 'simple-points-and-rewards' ),
            'value' => sprintf( 'x%1$s', spar_format_multiplier( $level_multiplier ) ),
        ];
    }
    if ( $cart_delta !== 0 && $items_total_adjusted > 0 && !empty( $item_rows ) ) {
        $remaining_delta = (int) $cart_delta;
        $weight_total = 0;
        foreach ( $item_rows as $item_row ) {
            $weight_total += (int) $item_row['points'];
        }
        if ( $weight_total > 0 ) {
            foreach ( $item_rows as $index => $item_row ) {
                if ( $index === array_key_last( $item_rows ) ) {
                    $allocation = $remaining_delta;
                } else {
                    $ratio = (float) $item_row['points'] / (float) $weight_total;
                    $allocation = (int) round( (float) $cart_delta * $ratio );
                    $remaining_delta -= $allocation;
                }
                $item_rows[$index]['points'] = max( 0, (int) $item_row['points'] + (int) $allocation );
            }
        }
        $cart_delta = 0;
    }
    $format_points_summary_value = static function ( $points, $context ) use($points_label) {
        return sprintf( '%1$s %2$s', esc_html( spar_format_points_value( (int) $points ) ), esc_html( spar_get_points_label_for_count( $points, $points_label, $context ) ) );
    };
    foreach ( $item_rows as $item_row ) {
        $rows[] = [
            'label' => $item_row['label'],
            'value' => $format_points_summary_value( $item_row['points'], 'cart_points_summary' ),
        ];
    }
    if ( $fixed_after > 0 ) {
        $rows[] = [
            'label' => esc_html__( 'Order fixed points', 'simple-points-and-rewards' ),
            'value' => $format_points_summary_value( $fixed_after, 'cart_points_summary_fixed' ),
        ];
    }
    if ( 0 !== $fixed_delta_scaled ) {
        $rows[] = [
            'label' => esc_html__( 'Fixed points adjustment', 'simple-points-and-rewards' ),
            'value' => $format_points_summary_value( $fixed_delta_scaled, 'cart_points_summary_fixed_adjustment' ),
        ];
    }
    $baseline = (int) $items_total_base + (int) $fixed_after;
    $delta = (int) $earning_points - (int) $baseline;
    $delta -= (int) ($items_total_adjusted - $items_total_base);
    $delta = (int) $cart_delta;
    if ( 0 !== $delta ) {
        $rows[] = [
            'label' => esc_html__( 'Conditional rules adjustment', 'simple-points-and-rewards' ),
            'value' => (( $delta > 0 ? '+' : '' )) . $format_points_summary_value( $delta, 'cart_points_summary_rules_adjustment' ),
        ];
    }
    return $rows;
}

/**
 * Build the Points Discount panel for the checkout rewards box.
 */
function spar_get_checkout_points_discount_panel_html(
    $user_id,
    $options,
    $points_label,
    $redeem_button_label,
    $panel_variant = 'both'
) {
    if ( !is_user_logged_in() ) {
        return '';
    }
    $user_id = (int) $user_id;
    $default_primary_color = '#667eea';
    $primary_option = ( isset( $options['checkout_box_primary_color'] ) ? $options['checkout_box_primary_color'] : '' );
    $primary_color_raw = ( is_string( $primary_option ) ? $primary_option : '' );
    $primary_color = ( function_exists( 'sanitize_hex_color' ) ? ( sanitize_hex_color( $primary_color_raw ) ?: $default_primary_color ) : $default_primary_color );
    $store_currency = ( function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : (( defined( 'WC_VERSION' ) ? get_option( 'woocommerce_currency' ) : 'USD' )) );
    $currency_symbol = ( function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol( $store_currency ) : $store_currency );
    $rate = ( function_exists( 'spar_get_redeem_rate_for_currency' ) ? spar_get_redeem_rate_for_currency( $store_currency ) : array(
        'points' => (float) ($options['redeem_points_per_points'] ?? 100),
        'amount' => (float) ($options['redeem_points_per_amount'] ?? 1),
    ) );
    $rate_points = max( 0, (float) ($rate['points'] ?? 0) );
    $rate_amount = max( 0, (float) ($rate['amount'] ?? 0) );
    $limits_enabled = function_exists( 'spar_fs' ) && spar_fs()->can_use_premium_code__premium_only();
    $redeem_points_min = ( $limits_enabled ? max( 0, (int) ($options['redeem_points_min'] ?? 0) ) : 0 );
    $redeem_points_max = ( $limits_enabled ? max( 0, (int) ($options['redeem_points_max'] ?? 0) ) : 0 );
    $user_points_bal = (int) spar_get_user_points( $user_id );
    $points_max = spar_get_cart_redeem_points_max( $user_points_bal, $store_currency, $redeem_points_max );
    $max_points_attr = (int) $user_points_bal;
    if ( $points_max > 0 ) {
        $max_points_attr = min( (int) $points_max, (int) $user_points_bal );
    }
    $min_points_attr = 0;
    if ( $redeem_points_min > 0 && $user_points_bal >= $redeem_points_min ) {
        $min_points_attr = (int) min( $redeem_points_min, ( $max_points_attr > 0 ? $max_points_attr : $redeem_points_min ) );
    }
    $current_points = 0;
    $session = ( function_exists( 'spar_get_wc_session' ) ? spar_get_wc_session() : null );
    if ( $session ) {
        $sess = (array) $session->get( 'spar_points_redemption', [] );
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
    $cart = ( function_exists( 'spar_get_wc_cart' ) ? spar_get_wc_cart() : null );
    $can_apply_now = $cart && !$cart->is_empty();
    // Premium: is the cart below the minimum total required to redeem points?
    $redeem_below_min = spar_redeem_is_below_min_cart_total( $cart );
    $_tax_info = spar_get_redeem_discount_tax_info( $options );
    $tax_multiplier = $_tax_info['multiplier'];
    $tax_rate = $_tax_info['rate'];
    $panel_variant = ( is_string( $panel_variant ) ? sanitize_key( $panel_variant ) : 'both' );
    $panel_variant = ( in_array( $panel_variant, ['compact', 'regular', 'both'], true ) ? $panel_variant : 'both' );
    $show_compact = in_array( $panel_variant, ['compact', 'both'], true );
    $show_regular = in_array( $panel_variant, ['regular', 'both'], true );
    ob_start();
    if ( $can_apply_now && $user_points_bal > 0 ) {
        if ( $show_compact ) {
            ?>
            <div class="spar-points-discount-panel spar-points-discount-panel--compact">
                <?php 
            $panel_text_color_compact = ( isset( $options['redeem_text_color'] ) ? sanitize_hex_color( $options['redeem_text_color'] ) : '' );
            $panel_text_color_compact_css = ( $panel_text_color_compact ? ';--spar-redeem-text-color:' . $panel_text_color_compact : '' );
            ?>
                <div class="spar-redeem-compact spar-redeem-compact--totals<?php 
            echo ( $redeem_below_min ? ' spar-redeem-blocked' : '' );
            ?>" data-currency="<?php 
            echo esc_attr( $store_currency );
            ?>" data-currency-symbol="<?php 
            echo esc_attr( $currency_symbol );
            ?>" style="--spar-rewards-primary: <?php 
            echo esc_attr( $primary_color );
            echo esc_attr( $panel_text_color_compact_css );
            ?>;">
                    <div class="spar-redeem-panel">
                        <div class="spar-panel-row">
                            <input type="range" class="spar-redeem-slider" min="<?php 
            echo esc_attr( $min_points_attr );
            ?>" step="1" value="<?php 
            echo esc_attr( $current_points );
            ?>" max="<?php 
            echo esc_attr( $max_points_attr );
            ?>" <?php 
            disabled( $redeem_below_min );
            ?> />
                            <div class="spar-panel-right">
                                <input type="number" class="spar-redeem-input" min="<?php 
            echo esc_attr( $min_points_attr );
            ?>" step="1" value="<?php 
            echo esc_attr( $current_points );
            ?>" max="<?php 
            echo esc_attr( $max_points_attr );
            ?>" <?php 
            disabled( $redeem_below_min );
            ?> />
                            </div>
                        </div>
                        <div class="spar-redeem-actions">
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
                            <div class="spar-redeem-buttons">
                                <button type="button" class="button spar-redeem-apply-btn" data-nonce="<?php 
            echo esc_attr( wp_create_nonce( 'spar_points_redeem' ) );
            ?>" <?php 
            disabled( $redeem_below_min || $current_points <= 0 );
            ?>><?php 
            echo wp_kses_post( $redeem_button_label );
            ?></button>
                            </div>
                        </div>
                        <?php 
            // Display max cart percentage note if set
            if ( $limits_enabled ) {
                $max_percentage = ( isset( $options['redeem_max_cart_percentage'] ) ? max( 0, min( 100, (int) $options['redeem_max_cart_percentage'] ) ) : 0 );
                if ( $max_percentage > 0 ) {
                    $note_color_style = ( $panel_text_color_compact ? 'color:' . $panel_text_color_compact . ';' : 'color:#666;' );
                    $note_style = 'font-size:10px;' . $note_color_style . 'margin:10px 0 0 0;text-align:center;font-style:italic;width:100%;display:block;clear:both;padding:0;grid-column:1/-1;';
                    echo '<p class="spar-redeem-percentage-note" style="' . esc_attr( $note_style ) . '">';
                    /* translators: %d: percentage number */
                    printf( esc_html__( 'Maximum discount is limited to %d%% of cart total.', 'simple-points-and-rewards' ), (int) $max_percentage );
                    echo '</p>';
                }
            }
            // Display minimum cart total note if the cart is below it.
            echo spar_render_redeem_min_cart_note( $cart, $panel_text_color_compact );
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- returns pre-escaped HTML.
            ?>
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
                </div>
            </div>
            <?php 
        }
        if ( $show_regular ) {
            ?>
            <div class="spar-points-discount-panel spar-points-discount-panel--regular">
                <?php 
            $panel_text_color_regular = ( isset( $options['redeem_text_color'] ) ? sanitize_hex_color( $options['redeem_text_color'] ) : '' );
            $panel_text_color_regular_css = ( $panel_text_color_regular ? ';--spar-redeem-text-color:' . $panel_text_color_regular : '' );
            ?>
                <div class="spar-redeem-compact spar-redeem-compact--account<?php 
            echo ( $redeem_below_min ? ' spar-redeem-blocked' : '' );
            ?>" data-currency="<?php 
            echo esc_attr( $store_currency );
            ?>" data-currency-symbol="<?php 
            echo esc_attr( $currency_symbol );
            ?>" style="--spar-rewards-primary: <?php 
            echo esc_attr( $primary_color );
            echo esc_attr( $panel_text_color_regular_css );
            ?>;">
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
            ?>" <?php 
            disabled( $redeem_below_min );
            ?> />
                        <div class="spar-panel-right">
                            <input type="number" class="spar-redeem-input" min="<?php 
            echo esc_attr( $min_points_attr );
            ?>" step="1" value="<?php 
            echo esc_attr( $current_points );
            ?>" max="<?php 
            echo esc_attr( $max_points_attr );
            ?>" <?php 
            disabled( $redeem_below_min );
            ?> />
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
            disabled( $redeem_below_min || $current_points <= 0 );
            ?>><?php 
            echo wp_kses_post( $redeem_button_label );
            ?></button>
                        </div>
                    </div>
                    <?php 
            // Display max cart percentage note if set
            if ( $limits_enabled ) {
                $max_percentage = ( isset( $options['redeem_max_cart_percentage'] ) ? max( 0, min( 100, (int) $options['redeem_max_cart_percentage'] ) ) : 0 );
                if ( $max_percentage > 0 ) {
                    $note_color_style_reg = ( $panel_text_color_regular ? 'color:' . $panel_text_color_regular . ';' : 'color:#666;' );
                    $note_style_reg = 'font-size:10px;' . $note_color_style_reg . 'margin:10px 0 0 0;text-align:center;font-style:italic;width:100%;display:block;clear:both;padding:0;grid-column:1/-1;';
                    echo '<p class="spar-redeem-percentage-note" style="' . esc_attr( $note_style_reg ) . '">';
                    /* translators: %d: percentage number */
                    printf( esc_html__( 'Maximum discount is limited to %d%% of cart total.', 'simple-points-and-rewards' ), (int) $max_percentage );
                    echo '</p>';
                }
            }
            // Display minimum cart total note if the cart is below it.
            echo spar_render_redeem_min_cart_note( $cart, $panel_text_color_regular );
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- returns pre-escaped HTML.
            ?>
                    <?php 
            if ( $current_points > 0 ) {
                ?>
                        <div class="spar-redeem-applied-status">
                            <span class="spar-redeem-applied-text"><?php 
                /* translators: %s: points count (localized) */
                printf( esc_html__( 'Points applied to cart: %s', 'simple-points-and-rewards' ), esc_html( spar_format_points_value( (int) $current_points ) ) );
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
            </div>
            <?php 
        }
    } else {
        echo '<div class="spar-cart-empty-state spar-cart-empty-state-dash"><div class="spar-cart-empty-icon spar-cart-empty-icon-dash">🛒</div><div class="spar-cart-empty-title spar-cart-empty-title-dash">' . esc_html__( 'Add items to your cart to redeem points at checkout.', 'simple-points-and-rewards' ) . '</div></div>';
    }
    return ob_get_clean();
}

/**
 * Render the rewards box HTML
 */
function spar_render_rewards_box(  $page = 'cart', $args = []  ) {
    // Respect setting: show for guests
    if ( !is_user_logged_in() && !spar_get_option( '', 'show_for_guests' ) ) {
        return;
    }
    // Hide for banned users
    if ( is_user_logged_in() ) {
        $spar_status = get_user_meta( get_current_user_id(), 'spar_user_status', true );
        if ( 'banned' === $spar_status ) {
            return;
        }
    }
    sparp_maybe_load_cart_for_rewards();
    $cart = ( function_exists( 'spar_get_wc_cart' ) ? spar_get_wc_cart() : null );
    // Never render on the order received (thank you) page unless explicitly requested for thankyou context
    $is_thankyou = 'thankyou' === $page;
    if ( !$is_thankyou && (function_exists( 'is_order_received_page' ) && is_order_received_page() || function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' )) ) {
        return;
    }
    $user_id = get_current_user_id();
    $user_points = spar_get_user_points( $user_id );
    $options = get_option( 'spar_options', [] );
    $reward_vouchers_enabled = ( function_exists( 'spar_rewards_vouchers_enabled' ) ? spar_rewards_vouchers_enabled() : true );
    if ( null === $reward_vouchers_enabled ) {
        $reward_vouchers_enabled = true;
    }
    $reward_vouchers_enabled = (bool) $reward_vouchers_enabled;
    $redeem_enabled = !empty( $options['redeem_individual_enabled'] );
    $redeem_display = ( isset( $options['redeem_individual_display'] ) ? sanitize_key( $options['redeem_individual_display'] ) : 'totals' );
    $redeem_display = ( in_array( $redeem_display, ['totals', 'box', 'both'], true ) ? $redeem_display : 'totals' );
    $show_points_discount_tab = $redeem_enabled && is_user_logged_in() && in_array( $redeem_display, ['box', 'both'], true );
    // For guest users on thank-you page, hide the rewards/voucher dropdown entirely.
    $is_guest_thankyou = 'thankyou' === $page && !is_user_logged_in();
    $show_rewards_dropdown = ($reward_vouchers_enabled || $show_points_discount_tab) && !$is_guest_thankyou;
    $show_dropdown_tabs = $reward_vouchers_enabled && !$is_guest_thankyou;
    $default_redeem_button_label = esc_html__( 'Add Discount to Cart', 'simple-points-and-rewards' );
    $redeem_button_text_option = ( isset( $options['redeem_button_text'] ) ? $options['redeem_button_text'] : '' );
    $redeem_button_label = ( '' !== $redeem_button_text_option ? wp_kses_post( $redeem_button_text_option ) : $default_redeem_button_label );
    // Calculate points message depending on context
    $earning_points = 0;
    $earning_message = '';
    $earning_upsell_message = '';
    $show_summary_tooltip = !empty( $options['checkout_box_show_points_summary'] );
    $earning_points = 0;
    $base_points = 0;
    $fixed_points = 0;
    $level_multiplier = 1.0;
    $summary_rows = [];
    $base_points = 0;
    $fixed_points = 0;
    $level_multiplier = 1.0;
    $points_label = spar_get_configured_points_label();
    if ( $is_thankyou ) {
        // Thank You page: show earned (or pending) points for the specific order
        $order_id = ( isset( $args['order_id'] ) ? (int) $args['order_id'] : 0 );
        $order = ( $order_id ? wc_get_order( $order_id ) : false );
        // Guest order on thank-you page: compute earning message like logged-in users.
        if ( $order && !$order->get_user_id() && !is_user_logged_in() && function_exists( 'spar_is_guest_tracking_enabled' ) && spar_is_guest_tracking_enabled() ) {
            $guest_tracked = (int) $order->get_meta( '_spar_guest_points_tracked' );
            // If points have not been tracked yet (e.g. award on completion), calculate projected points.
            if ( $guest_tracked <= 0 ) {
                $g_base = ( function_exists( 'spar_calculate_order_base_points' ) ? (int) spar_calculate_order_base_points( $order ) : 0 );
                $g_cur = ( method_exists( $order, 'get_currency' ) ? $order->get_currency() : '' );
                $g_ftot = ( function_exists( 'spar_get_points_calculation_total_from_order' ) ? spar_get_points_calculation_total_from_order( $order, 'order_fixed' ) : (float) $order->get_total() );
                $g_finfo = ( function_exists( 'spar_calculate_fixed_order_points_for_total' ) ? spar_calculate_fixed_order_points_for_total( $g_ftot, $g_cur ) : array(
                    'points' => 0,
                ) );
                $g_fixed = (int) ($g_finfo['points'] ?? 0);
                $guest_tracked = max( 0, (int) floor( $g_base + $g_fixed ) );
            }
            if ( $guest_tracked > 0 ) {
                $earning_points = $guest_tracked;
                $options = get_option( 'spar_options', [] );
                $award_timing = $options['earn']['order']['award_timing'] ?? 'thankyou';
                if ( 'thankyou' === $award_timing ) {
                    $earning_message = sprintf( 
                        /* translators: 1: points earned, 2: points label */
                        esc_html__( 'You earned %1$s %2$s from this order!', 'simple-points-and-rewards' ),
                        esc_html( spar_format_points_value( $guest_tracked ) ),
                        esc_html( spar_get_points_label_for_count( $guest_tracked, $points_label, 'thankyou_guest_order_earned' ) )
                     );
                } else {
                    $earning_message = sprintf( 
                        /* translators: 1: points to earn, 2: points label */
                        esc_html__( 'You will earn %1$s %2$s for this order when it is completed.', 'simple-points-and-rewards' ),
                        esc_html( spar_format_points_value( $guest_tracked ) ),
                        esc_html( spar_get_points_label_for_count( $guest_tracked, $points_label, 'thankyou_guest_order_pending' ) )
                     );
                }
            }
        } elseif ( $order && (int) $order->get_user_id() === (int) $user_id ) {
            if ( function_exists( 'sparp_cr_context_has_disabled_earning__premium_only' ) && sparp_cr_context_has_disabled_earning__premium_only( 'order', $order, $user_id ) ) {
                $earning_message = '';
            } else {
                $order_points_are_reversed = (int) $order->get_meta( 'points_deducted' ) > 0 || method_exists( $order, 'has_status' ) && $order->has_status( array('refunded', 'cancelled', 'failed') );
                $earned_points = ( $order_points_are_reversed ? 0 : (int) $order->get_meta( 'points_earned' ) );
                $is_using_stored_points = $earned_points > 0 || $order_points_are_reversed;
                if ( !$order_points_are_reversed && $earned_points <= 0 ) {
                    // Compute projected points using the canonical award calculation so the
                    // projection matches what will actually be awarded (multipliers, caps,
                    // conditional rules, and integration adjustments such as renewal rates).
                    if ( function_exists( 'spar_calculate_order_award_points' ) ) {
                        $projection = spar_calculate_order_award_points( $order, $user_id );
                        $earned_points = (int) ($projection['points'] ?? 0);
                    } else {
                        $base_points = ( function_exists( 'spar_calculate_order_base_points' ) ? (int) spar_calculate_order_base_points( $order ) : 0 );
                        $currency = ( method_exists( $order, 'get_currency' ) ? $order->get_currency() : '' );
                        $fixed_total = ( function_exists( 'spar_get_points_calculation_total_from_order' ) ? spar_get_points_calculation_total_from_order( $order, 'order_fixed' ) : (float) $order->get_total() );
                        $fixed_info = ( function_exists( 'spar_calculate_fixed_order_points_for_total' ) ? spar_calculate_fixed_order_points_for_total( $fixed_total, $currency ) : [
                            'points' => 0,
                        ] );
                        $fixed_pts = (int) ($fixed_info['points'] ?? 0);
                        $multiplier = ( function_exists( 'spar_get_user_points_multiplier' ) ? (float) spar_get_user_points_multiplier( $user_id, 'order' ) : 1.0 );
                        $earned_points = (int) floor( ($base_points + $fixed_pts) * max( 0.0, $multiplier ) );
                    }
                }
                $options = get_option( 'spar_options', [] );
                $award_timing = $options['earn']['order']['award_timing'] ?? 'thankyou';
                // Check first-order bonus
                $first_order_bonus_meta = (int) $order->get_meta( 'points_first_order_bonus' );
                $first_order_timing = $options['earn']['first_order']['award_timing'] ?? 'thankyou';
                $user_rewards_earned = get_user_meta( $user_id, '_spar_rewards_earned', true );
                $first_order_already_awarded = is_array( $user_rewards_earned ) && !empty( $user_rewards_earned['first_order'] );
                // Calculate immediate and completion points separately
                $immediate_points = 0;
                $completion_points = 0;
                if ( 'thankyou' === $award_timing ) {
                    $immediate_points += $earned_points;
                } else {
                    $completion_points += $earned_points;
                }
                // Add first-order bonus manually only if we're calculating projected points
                // When using stored points_earned, it already includes the first-order bonus
                if ( $first_order_bonus_meta > 0 && !$is_using_stored_points ) {
                    if ( 'thankyou' === $first_order_timing && $first_order_already_awarded ) {
                        // Bonus was awarded immediately - include in immediate points
                        $immediate_points += $first_order_bonus_meta;
                    } elseif ( 'completed' === $first_order_timing && !$first_order_already_awarded ) {
                        // Bonus will be awarded on completion - include in completion points
                        $completion_points += $first_order_bonus_meta;
                    }
                }
                // Build message(s)
                $messages = [];
                if ( $immediate_points > 0 ) {
                    $messages[] = sprintf( 
                        /* translators: 1: points amount earned, 2: points label */
                        esc_html__( 'You earned %1$s %2$s from this order!', 'simple-points-and-rewards' ),
                        esc_html( spar_format_points_value( $immediate_points ) ),
                        esc_html( spar_get_points_label_for_count( $immediate_points, $points_label, 'thankyou_order_earned' ) )
                     );
                }
                if ( $completion_points > 0 ) {
                    $messages[] = sprintf( 
                        /* translators: 1: points amount to earn, 2: points label */
                        esc_html__( 'You will earn %1$s %2$s for this order when it is completed.', 'simple-points-and-rewards' ),
                        esc_html( spar_format_points_value( $completion_points ) ),
                        esc_html( spar_get_points_label_for_count( $completion_points, $points_label, 'thankyou_order_pending' ) )
                     );
                }
                $earning_message = implode( ' ', $messages );
            }
        }
        // end logged-in user thankyou
    } elseif ( $cart ) {
        $earn_options = spar_get_options( 'earn' );
        $cart_total = spar_get_points_calculation_total_from_cart( 'order' );
        $cart_total_fixed = spar_get_points_calculation_total_from_cart( 'order_fixed' );
        if ( !empty( $earn_options['order']['enabled'] ) && $cart_total > 0 || !empty( $earn_options['order_fixed']['enabled'] ) && $cart_total_fixed > 0 ) {
            $hide_order_earning = function_exists( 'sparp_cr_context_has_disabled_earning__premium_only' ) && sparp_cr_context_has_disabled_earning__premium_only( 'cart', $cart, $user_id );
            // Determine currency-aware rate using new helpers with safe fallbacks
            $currency = ( function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : (( defined( 'WC_VERSION' ) ? get_option( 'woocommerce_currency' ) : 'USD' )) );
            $base_points = 0;
            if ( !empty( $earn_options['order']['enabled'] ) ) {
                if ( function_exists( 'spar_get_order_points_rate' ) ) {
                    $rate = (float) spar_get_order_points_rate( $currency );
                } else {
                    // Fallback to new split fields if helper not available yet
                    $pp_points = ( isset( $earn_options['order']['points_per_points'] ) ? (float) $earn_options['order']['points_per_points'] : 0.0 );
                    $pp_amount = ( isset( $earn_options['order']['points_per_amount'] ) ? (float) $earn_options['order']['points_per_amount'] : 1.0 );
                    if ( $pp_points > 0 && $pp_amount > 0 ) {
                        $rate = $pp_points / $pp_amount;
                    } else {
                        // Legacy fallback
                        $rate = ( isset( $earn_options['order']['points_per'] ) ? (float) $earn_options['order']['points_per'] : 0.0 );
                    }
                }
                $base_points = floor( $cart_total * max( 0, $rate ) );
            }
            // Fixed tier points (no multiplier yet)
            $fixed_points = 0;
            if ( !$hide_order_earning && !empty( $earn_options['order_fixed']['enabled'] ) && function_exists( 'spar_calculate_fixed_order_points_for_total' ) ) {
                $fixed_info = spar_calculate_fixed_order_points_for_total( $cart_total_fixed, $currency );
                $fixed_points = (int) ($fixed_info['points'] ?? 0);
                if ( isset( $fixed_info['next_delta'], $fixed_info['next_points'] ) && $fixed_info['next_delta'] !== null && $fixed_info['next_points'] !== null && $fixed_info['next_points'] > 0 ) {
                    $earning_upsell_message = sprintf(
                        /* translators: 1: formatted currency amount, 2: additional points amount, 3: points label */
                        wp_kses_post( esc_html__( 'Spend %1$s more to get %2$s additional %3$s.', 'simple-points-and-rewards' ) ),
                        ( function_exists( 'wc_price' ) ? wc_price( $fixed_info['next_delta'] ) : esc_html( spar_format_currency_amount( $fixed_info['next_delta'] ) ) ),
                        spar_format_points_display( (int) $fixed_info['next_points'], 'inline' ),
                        esc_html( spar_get_points_label_for_count( $fixed_info['next_points'], $points_label, 'cart_fixed_points_upsell' ) )
                    );
                }
            }
            $base_points = (int) $base_points;
            $fixed_points = (int) $fixed_points;
            $earning_points = $base_points + $fixed_points;
            $level_multiplier = 1.0;
            if ( function_exists( 'spar_get_user_points_multiplier' ) ) {
                $level_multiplier = max( 0.0, (float) spar_get_user_points_multiplier( $user_id, 'order' ) );
            }
            $earning_points = (int) floor( (float) $earning_points * $level_multiplier );
            if ( function_exists( 'sparp_cr_calculate_conditionally_adjusted_points__premium_only' ) ) {
                $calc = sparp_cr_calculate_conditionally_adjusted_points__premium_only(
                    $base_points,
                    $fixed_points,
                    $level_multiplier,
                    $user_id,
                    'cart',
                    $cart
                );
                if ( is_array( $calc ) && isset( $calc['adjusted'] ) ) {
                    $earning_points = max( 0, (int) $calc['adjusted'] );
                }
            }
            // Include first-order bonus in preview
            if ( !empty( $earn_options['first_order']['enabled'] ) ) {
                $first_order_points = (int) ($earn_options['first_order']['points'] ?? 0);
                if ( $first_order_points > 0 ) {
                    // For logged-in users, only add if they qualify
                    // For guests, show the bonus to encourage sign-up
                    if ( is_user_logged_in() ) {
                        if ( function_exists( 'spar_user_qualifies_for_first_order_bonus' ) && spar_user_qualifies_for_first_order_bonus( $user_id ) ) {
                            $earning_points += $first_order_points;
                        }
                    } else {
                        // Guest users - show first-order bonus to encourage registration
                        $earning_points += $first_order_points;
                    }
                }
            }
            if ( $hide_order_earning ) {
                $earning_upsell_message = '';
                $earning_points = 0;
            }
        }
    }
    // Get rewards and vouchers data
    $rewards = ( $reward_vouchers_enabled ? spar_get_rewards() : [] );
    // Don't show if user has no points, no available vouchers, and won't earn points
    $available_vouchers = spar_get_user_available_vouchers( $user_id );
    // Allow showing the box if we have an upsell message even when currently earning 0 points
    if ( $user_points <= 0 && empty( $available_vouchers ) && $earning_points <= 0 && empty( $earning_upsell_message ) && empty( $rewards ) ) {
        return;
    }
    $points_label = spar_get_configured_points_label();
    $rewards_label = ( spar_get_option( '', 'rewards_label' ) ?: esc_html__( 'Rewards', 'simple-points-and-rewards' ) );
    if ( !$is_thankyou && $earning_points > 0 ) {
        if ( is_user_logged_in() ) {
            $earning_message = sprintf( 
                /* translators: 1: points amount to earn, 2: points label */
                esc_html__( 'You will earn %1$s %2$s from this order', 'simple-points-and-rewards' ),
                esc_html( spar_format_points_value( $earning_points ) ),
                esc_html( spar_get_points_label_for_count( $earning_points, $points_label, 'cart_order_earning' ) )
             ) . '!';
        } elseif ( function_exists( 'spar_is_guest_tracking_enabled' ) && spar_is_guest_tracking_enabled() ) {
            $earning_message = sprintf( 
                /* translators: 1: points amount to earn, 2: points label */
                esc_html__( 'You will earn %1$s %2$s from this order', 'simple-points-and-rewards' ),
                esc_html( spar_format_points_value( $earning_points ) ),
                esc_html( spar_get_points_label_for_count( $earning_points, $points_label, 'cart_guest_order_earning' ) )
             ) . '!';
        } else {
            $earning_message = sprintf( 
                /* translators: 1: points amount to earn, 2: points label */
                esc_html__( 'Login to earn %1$s %2$s from this order!', 'simple-points-and-rewards' ),
                esc_html( spar_format_points_value( $earning_points ) ),
                esc_html( spar_get_points_label_for_count( $earning_points, $points_label, 'cart_login_order_earning' ) )
             );
        }
    }
    $rewards_data = [];
    $count = 0;
    foreach ( $rewards as $reward ) {
        $count++;
        if ( $count > 3 ) {
            break;
        }
        $points_required = (int) ($reward['points'] ?? 0);
        $can_redeem = $user_points >= $points_required;
        $points_needed = max( 0, $points_required - $user_points );
        $reward_data = [
            'id'             => $reward['id'],
            'name'           => $reward['name'] ?? esc_html__( 'Untitled Reward', 'simple-points-and-rewards' ),
            'points'         => $points_required,
            'type'           => $reward['type'] ?? 'voucher',
            'can_redeem'     => $can_redeem,
            'points_needed'  => $points_needed,
            'voucher_amount' => $reward['voucher_amount'] ?? 0,
            'discount_type'  => $reward['discount_type'] ?? 'fixed_cart',
            'free_shipping'  => !empty( $reward['free_shipping'] ),
            'product_id'     => $reward['product_id'] ?? 0,
        ];
        $rewards_data[] = $reward_data;
    }
    // Get vouchers data
    $vouchers_data = [];
    if ( $reward_vouchers_enabled ) {
        foreach ( $available_vouchers as $voucher ) {
            $voucher_data = [
                'code'       => $voucher->post_title,
                'is_applied' => ( $cart ? $cart->has_discount( $voucher->post_title ) : false ),
            ];
            // Get voucher details
            $discount_type = get_post_meta( $voucher->ID, 'discount_type', true );
            $amount = get_post_meta( $voucher->ID, 'coupon_amount', true );
            $free_shipping = get_post_meta( $voucher->ID, 'free_shipping', true );
            $has_free_shipping = 'yes' === $free_shipping;
            // If the coupon meta doesn't indicate free shipping, check the parent reward's
            // current settings as a fallback (handles coupons created before the free
            // shipping option was added, or where a template coupon overrode the value).
            if ( !$has_free_shipping ) {
                $reward_id = get_post_meta( $voucher->ID, '_spar_reward_id', true );
                if ( $reward_id && function_exists( 'spar_get_reward_by_id' ) ) {
                    $parent_reward = spar_get_reward_by_id( $reward_id );
                    if ( $parent_reward && !empty( $parent_reward['free_shipping'] ) ) {
                        $has_free_shipping = true;
                    }
                }
            }
            $voucher_data['value_display'] = spar_format_voucher_value_display( $discount_type, $amount, $has_free_shipping );
            // Override display for product vouchers
            $product_ids = get_post_meta( $voucher->ID, 'product_ids', true );
            if ( !empty( $product_ids ) ) {
                $ids = explode( ',', $product_ids );
                if ( !empty( $ids[0] ) && function_exists( 'wc_get_product' ) ) {
                    $product = wc_get_product( $ids[0] );
                    if ( $product ) {
                        /* translators: %s: Product name */
                        $voucher_data['value_display'] = sprintf( esc_html__( 'Free Product: %s', 'simple-points-and-rewards' ), $product->get_name() );
                    }
                }
            }
            // Get expiry information
            $expiry_date = get_post_meta( $voucher->ID, 'date_expires', true );
            if ( $expiry_date ) {
                $voucher_data['expiry_formatted'] = date_i18n( get_option( 'date_format' ), $expiry_date );
            }
            // Coupon limitation notes (min spend, restrictions, etc.)
            if ( function_exists( 'spar_get_coupon_limitations' ) ) {
                $voucher_data['limitations'] = spar_get_coupon_limitations( $voucher->ID );
            }
            $vouchers_data[] = $voucher_data;
        }
    }
    // Ensure assets are enqueued (this will only enqueue once due to the static check)
    spar_enqueue_block_checkout_assets();
    // Theme class
    $theme = spar_get_option( '', 'rewards_box_theme' );
    if ( $theme === 'compact' ) {
        $theme_class = 'spar-theme-compact';
    } elseif ( $theme === 'medium' ) {
        $theme_class = 'spar-theme-medium';
    } else {
        $theme_class = 'spar-theme-default';
    }
    // Theme style (light / dark)
    $theme_style = spar_get_option( '', 'checkout_box_theme_style' );
    if ( 'dark' === $theme_style ) {
        $theme_class .= ' spar-checkout-dark-mode';
    }
    $default_primary_color = '#667eea';
    $primary_option = spar_get_option( '', 'checkout_box_primary_color' );
    $primary_color = ( is_string( $primary_option ) ? sanitize_hex_color( $primary_option ) : '' );
    if ( !$primary_color ) {
        $primary_color = $default_primary_color;
    }
    $primary_hover = $primary_color;
    if ( function_exists( 'spar_adjust_color_brightness' ) ) {
        $primary_hover_candidate = spar_adjust_color_brightness( $primary_color, -10 );
        $primary_hover = ( sanitize_hex_color( $primary_hover_candidate ) ?: $primary_color );
    }
    $primary_rgb = '102, 126, 234';
    $primary_hex = ltrim( $primary_color, '#' );
    if ( 3 === strlen( $primary_hex ) ) {
        $primary_hex = $primary_hex[0] . $primary_hex[0] . $primary_hex[1] . $primary_hex[1] . $primary_hex[2] . $primary_hex[2];
    }
    if ( 6 === strlen( $primary_hex ) ) {
        $primary_r = hexdec( substr( $primary_hex, 0, 2 ) );
        $primary_g = hexdec( substr( $primary_hex, 2, 2 ) );
        $primary_b = hexdec( substr( $primary_hex, 4, 2 ) );
        $primary_rgb = $primary_r . ', ' . $primary_g . ', ' . $primary_b;
    }
    $default_secondary_color = '#28a745';
    $secondary_option = spar_get_option( '', 'checkout_box_secondary_color' );
    $secondary_color = ( is_string( $secondary_option ) ? sanitize_hex_color( $secondary_option ) : '' );
    if ( !$secondary_color ) {
        $secondary_color = $default_secondary_color;
    }
    $secondary_hover = $secondary_color;
    if ( function_exists( 'spar_adjust_color_brightness' ) ) {
        $secondary_hover_candidate = spar_adjust_color_brightness( $secondary_color, -10 );
        $secondary_hover = ( sanitize_hex_color( $secondary_hover_candidate ) ?: $secondary_color );
    }
    $secondary_rgb = '40, 167, 69';
    $secondary_hex = ltrim( $secondary_color, '#' );
    if ( 3 === strlen( $secondary_hex ) ) {
        $secondary_hex = $secondary_hex[0] . $secondary_hex[0] . $secondary_hex[1] . $secondary_hex[1] . $secondary_hex[2] . $secondary_hex[2];
    }
    if ( 6 === strlen( $secondary_hex ) ) {
        $secondary_r = hexdec( substr( $secondary_hex, 0, 2 ) );
        $secondary_g = hexdec( substr( $secondary_hex, 2, 2 ) );
        $secondary_b = hexdec( substr( $secondary_hex, 4, 2 ) );
        $secondary_rgb = $secondary_r . ', ' . $secondary_g . ', ' . $secondary_b;
    }
    $style_attribute = sprintf(
        '--spar-rewards-primary:%1$s;--spar-rewards-primary-hover:%2$s;--spar-rewards-primary-rgb:%3$s;--spar-rewards-secondary:%4$s;--spar-rewards-secondary-hover:%5$s;--spar-rewards-secondary-rgb:%6$s;',
        $primary_color,
        $primary_hover,
        $primary_rgb,
        $secondary_color,
        $secondary_hover,
        $secondary_rgb
    );
    ?>
    <div class="spar-cart-rewards-box <?php 
    echo esc_attr( $theme_class );
    ?>" style="<?php 
    echo esc_attr( $style_attribute );
    ?>">
        <div class="spar-cart-rewards-header" id="spar-cart-rewards-header">
                <?php 
    // Pass $args so thank you context (order_id) can show points earned for this order
    echo wp_kses_post( spar_get_rewards_header_html( $page, $args ) );
    ?>
            </div>
        
        <?php 
    if ( $show_rewards_dropdown ) {
        ?>
        <div class="spar-cart-rewards-dropdown">
            <?php 
        $active_tab = ( $reward_vouchers_enabled ? 'rewards' : (( $show_points_discount_tab ? 'points-discount' : '' )) );
        ?>
            <?php 
        if ( $show_dropdown_tabs ) {
            ?>
                <div class="spar-dropdown-tabs">
                    <?php 
            if ( $reward_vouchers_enabled ) {
                ?>
                        <button class="spar-dropdown-tab <?php 
                echo esc_attr( ( 'rewards' === $active_tab ? 'active' : '' ) );
                ?>" data-tab="rewards">
                            <?php 
                /* translators: %s: Rewards label */
                printf( esc_html__( 'Available %s', 'simple-points-and-rewards' ), esc_html( $rewards_label ) );
                ?>
                        </button>
                    <?php 
            }
            ?>
                    <?php 
            if ( $show_points_discount_tab ) {
                ?>
                        <button class="spar-dropdown-tab <?php 
                echo esc_attr( ( 'points-discount' === $active_tab ? 'active' : '' ) );
                ?>" data-tab="points-discount">
                            <?php 
                esc_html_e( 'Points Discount', 'simple-points-and-rewards' );
                ?>
                        </button>
                    <?php 
            }
            ?>
                    <?php 
            if ( $reward_vouchers_enabled ) {
                ?>
                        <button class="spar-dropdown-tab <?php 
                echo esc_attr( ( 'vouchers' === $active_tab ? 'active' : '' ) );
                ?>" data-tab="vouchers">
                            <?php 
                esc_html_e( 'My Vouchers', 'simple-points-and-rewards' );
                ?>
                        </button>
                    <?php 
            }
            ?>
                </div>
            <?php 
        }
        ?>
            
            <div class="spar-dropdown-content">
                <?php 
        if ( $reward_vouchers_enabled ) {
            ?>
                    <div class="spar-dropdown-tab-content <?php 
            echo esc_attr( ( 'rewards' === $active_tab ? 'active' : '' ) );
            ?>" data-tab="rewards">
                        <?php 
            if ( !empty( $rewards_data ) ) {
                ?>
                            <div class="spar-rewards-grid">
                                <?php 
                foreach ( $rewards_data as $reward ) {
                    ?>
                                    <div class="spar-reward-item <?php 
                    echo esc_attr( ( $reward['can_redeem'] ? 'can-redeem' : 'disabled' ) );
                    ?>">
                                        <div class="spar-reward-info">
                                            <div class="spar-reward-name"><?php 
                    echo esc_html( $reward['name'] );
                    ?></div>
                                            <div class="spar-reward-cost"><?php 
                    echo esc_html( $reward['points'] . ' ' . spar_get_points_label_for_count( $reward['points'], $points_label, 'reward_cost' ) );
                    ?></div>
                                            <?php 
                    if ( $reward['type'] === 'voucher' ) {
                        $value_display = spar_format_voucher_value_display( $reward['discount_type'], $reward['voucher_amount'], !empty( $reward['free_shipping'] ) );
                        if ( '' !== $value_display ) {
                            ?>
                                                    <div class="spar-reward-value">
                                                        <?php 
                            echo wp_kses_post( $value_display );
                            ?>
                                                    </div>
                                                    <?php 
                        }
                    }
                    ?>
                                        </div>
                        <button class="spar-redeem-btn" 
                            data-reward-id="<?php 
                    echo esc_attr( $reward['id'] );
                    ?>"
                            <?php 
                    echo disabled( !$reward['can_redeem'], true, false );
                    ?>>
                                            <?php 
                    if ( $reward['can_redeem'] ) {
                        esc_html_e( 'Redeem', 'simple-points-and-rewards' );
                    } else {
                        /* translators: %d: points needed */
                        printf( esc_html__( 'Need %s more', 'simple-points-and-rewards' ), spar_format_points_display( (int) $reward['points_needed'], 'inline' ) );
                    }
                    ?>
                                        </button>
                                    </div>
                                <?php 
                }
                ?>
                            </div>
                        <?php 
            } else {
                ?>
                            <div class="spar-cart-empty-state">
                                <div class="spar-cart-empty-icon">🎁</div>
                                <div class="spar-cart-empty-title"><?php 
                /* translators: %s: rewards label (lowercase) */
                printf( esc_html__( 'No %s available', 'simple-points-and-rewards' ), esc_html( strtolower( $rewards_label ) ) );
                ?></div>
                            </div>
                        <?php 
            }
            ?>
                    </div>
                <?php 
        }
        ?>

                <?php 
        if ( $show_points_discount_tab ) {
            ?>
                    <div class="spar-dropdown-tab-content <?php 
            echo esc_attr( ( 'points-discount' === $active_tab ? 'active' : '' ) );
            ?>" data-tab="points-discount">
                        <?php 
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper output is composed of escaped internal markup.
            echo spar_get_checkout_points_discount_panel_html(
                $user_id,
                $options,
                $points_label,
                $redeem_button_label
            );
            ?>
                    </div>
                <?php 
        }
        ?>
                
                <?php 
        if ( $reward_vouchers_enabled ) {
            ?>
                    <div class="spar-dropdown-tab-content <?php 
            echo esc_attr( ( 'vouchers' === $active_tab ? 'active' : '' ) );
            ?>" data-tab="vouchers">
                        <?php 
            if ( !empty( $vouchers_data ) ) {
                ?>
                            <div class="spar-vouchers-list">
                                <?php 
                foreach ( $vouchers_data as $voucher ) {
                    ?>
                                    <div class="spar-voucher-item <?php 
                    echo esc_attr( ( $voucher['is_applied'] ? 'applied' : '' ) );
                    ?>">
                                        <div class="spar-voucher-info">
                                            <div class="spar-voucher-code"><?php 
                    echo esc_html( $voucher['code'] );
                    ?></div>
                                            <div class="spar-voucher-value"><?php 
                    echo wp_kses_post( $voucher['value_display'] );
                    ?></div>
                                            <?php 
                    if ( !empty( $voucher['limitations'] ) ) {
                        ?>
                                                <div class="spar-voucher-limitations">
                                                    <?php 
                        echo esc_html( implode( ' · ', $voucher['limitations'] ) );
                        ?>
                                                </div>
                                            <?php 
                    }
                    ?>
                                            <?php 
                    if ( !empty( $voucher['expiry_formatted'] ) ) {
                        ?>
                                                <div class="spar-voucher-expiry">
                                                    <?php 
                        /* translators: %s: expiry date */
                        printf( esc_html__( 'Expires: %s', 'simple-points-and-rewards' ), esc_html( $voucher['expiry_formatted'] ) );
                        ?>
                                                </div>
                                            <?php 
                    }
                    ?>
                                        </div>
                                        <?php 
                    if ( $voucher['is_applied'] ) {
                        ?>
                                            <div class="spar-voucher-applied">
                                                <span>✓</span>
                                                <?php 
                        esc_html_e( 'Applied', 'simple-points-and-rewards' );
                        ?>
                                            </div>
                                        <?php 
                    } else {
                        ?>
                                            <button class="spar-apply-voucher-btn" data-voucher-code="<?php 
                        echo esc_attr( $voucher['code'] );
                        ?>">
                                                <?php 
                        esc_html_e( 'Apply', 'simple-points-and-rewards' );
                        ?>
                                            </button>
                                        <?php 
                    }
                    ?>
                                    </div>
                                <?php 
                }
                ?>
                            </div>
                        <?php 
            } else {
                ?>
                            <div class="spar-cart-empty-state">
                                <div class="spar-cart-empty-icon">🎟️</div>
                                <div class="spar-cart-empty-title"><?php 
                esc_html_e( 'No vouchers available', 'simple-points-and-rewards' );
                ?></div>
                            </div>
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
    ?>
    </div>
    <?php 
}

/**
 * Render the rewards box on the Thank You page, summarising points earned for the order
 * and providing the same redeem UI as the cart/checkout rewards box.
 *
 * Runs late on woocommerce_thankyou to ensure points have been awarded already when
 * award timing is set to "when order is placed".
 *
 * @param int $order_id WooCommerce order ID.
 */
function spar_display_thankyou_rewards_box(  $order_id  ) {
    // Prevent duplicate render if multiple hooks call this function
    static $spar_thankyou_rewards_rendered = false;
    if ( $spar_thankyou_rewards_rendered ) {
        return;
    }
    // Respect setting: show on thank you
    if ( !spar_get_option( '', 'show_on_thankyou' ) ) {
        return;
    }
    if ( !$order_id ) {
        return;
    }
    // Ensure we're on the order received page
    if ( function_exists( 'is_order_received_page' ) && !is_order_received_page() && (function_exists( 'is_wc_endpoint_url' ) && !is_wc_endpoint_url( 'order-received' )) ) {
        return;
    }
    $order = wc_get_order( $order_id );
    if ( !$order ) {
        return;
    }
    $is_guest_order = !$order->get_user_id();
    if ( is_user_logged_in() ) {
        // Logged-in user: must own the order.
        if ( (int) $order->get_user_id() !== (int) get_current_user_id() ) {
            return;
        }
    } elseif ( $is_guest_order ) {
        // Guest order: only show if guest tracking is active.
        $guest_tracking_on = function_exists( 'spar_is_guest_tracking_enabled' ) && spar_is_guest_tracking_enabled();
        if ( !$guest_tracking_on ) {
            return;
        }
    } else {
        // Not logged in but order belongs to a user — don't show.
        return;
    }
    // Mark as rendered to avoid duplicates
    $spar_thankyou_rewards_rendered = true;
    // Delegate to unified renderer with thankyou context
    spar_render_rewards_box( 'thankyou', [
        'order_id' => (int) $order_id,
    ] );
}

/**
 * Render only the rewards header fragment (title + earning lines + points summary + toggle)
 * This is used for AJAX refresh on cart updates.
 *
 * @param string $page 'cart'|'checkout'|'thankyou'
 * @param array  $args Optional context (e.g., order_id for thankyou)
 * @return string HTML
 */
function spar_get_rewards_header_html(  $page = 'cart', $args = []  ) {
    // Respect setting: show for guests
    if ( !is_user_logged_in() && !spar_get_option( '', 'show_for_guests' ) ) {
        return '';
    }
    // Return empty header for banned users
    if ( is_user_logged_in() ) {
        $spar_status = get_user_meta( get_current_user_id(), 'spar_user_status', true );
        if ( 'banned' === $spar_status ) {
            return '';
        }
    }
    sparp_maybe_load_cart_for_rewards();
    $cart = ( function_exists( 'spar_get_wc_cart' ) ? spar_get_wc_cart() : null );
    $user_id = get_current_user_id();
    $user_points = (int) spar_get_user_points( $user_id );
    $points_label = spar_get_configured_points_label();
    $rewards_label = ( spar_get_option( '', 'rewards_label' ) ?: esc_html__( 'Rewards', 'simple-points-and-rewards' ) );
    $options = get_option( 'spar_options', [] );
    $reward_vouchers_enabled = ( function_exists( 'spar_rewards_vouchers_enabled' ) ? spar_rewards_vouchers_enabled() : true );
    if ( null === $reward_vouchers_enabled ) {
        $reward_vouchers_enabled = true;
    }
    $reward_vouchers_enabled = (bool) $reward_vouchers_enabled;
    $redeem_enabled = !empty( $options['redeem_individual_enabled'] );
    $redeem_display = ( isset( $options['redeem_individual_display'] ) ? sanitize_key( $options['redeem_individual_display'] ) : 'totals' );
    $redeem_display = ( in_array( $redeem_display, ['totals', 'box', 'both'], true ) ? $redeem_display : 'totals' );
    $show_points_discount_tab = $redeem_enabled && is_user_logged_in() && in_array( $redeem_display, ['box', 'both'], true );
    $show_redeem_toggle = $reward_vouchers_enabled || $show_points_discount_tab;
    $checkout_redeem_button_text = ( isset( $options['checkout_box_redeem_button_text'] ) ? sanitize_text_field( $options['checkout_box_redeem_button_text'] ) : '' );
    $checkout_redeem_button_label = ( $checkout_redeem_button_text !== '' ? $checkout_redeem_button_text : esc_html__( 'Redeem Rewards', 'simple-points-and-rewards' ) );
    $earning_message = '';
    $earning_upsell_message = '';
    $show_summary_tooltip = !empty( $options['checkout_box_show_points_summary'] );
    $earning_points = 0;
    $base_points = 0;
    $fixed_points = 0;
    $level_multiplier = 1.0;
    $summary_rows = [];
    // Compute earning messages similarly to spar_render_rewards_box()
    $is_thankyou = 'thankyou' === $page;
    $is_guest_thankyou = false;
    if ( $is_thankyou ) {
        $order_id = ( isset( $args['order_id'] ) ? (int) $args['order_id'] : 0 );
        $order = ( $order_id ? wc_get_order( $order_id ) : false );
        // Guest order on thank-you page: compute earning message like logged-in users.
        if ( $order && !$order->get_user_id() && !is_user_logged_in() && function_exists( 'spar_is_guest_tracking_enabled' ) && spar_is_guest_tracking_enabled() ) {
            $is_guest_thankyou = true;
            $guest_tracked = (int) $order->get_meta( '_spar_guest_points_tracked' );
            // If points have not been tracked yet (e.g. award on completion), calculate projected points.
            if ( $guest_tracked <= 0 ) {
                $g_base = ( function_exists( 'spar_calculate_order_base_points' ) ? (int) spar_calculate_order_base_points( $order ) : 0 );
                $g_cur = ( method_exists( $order, 'get_currency' ) ? $order->get_currency() : '' );
                $g_ftot = ( function_exists( 'spar_get_points_calculation_total_from_order' ) ? spar_get_points_calculation_total_from_order( $order, 'order_fixed' ) : (float) $order->get_total() );
                $g_finfo = ( function_exists( 'spar_calculate_fixed_order_points_for_total' ) ? spar_calculate_fixed_order_points_for_total( $g_ftot, $g_cur ) : array(
                    'points' => 0,
                ) );
                $g_fixed = (int) ($g_finfo['points'] ?? 0);
                $guest_tracked = max( 0, (int) floor( $g_base + $g_fixed ) );
            }
            if ( $guest_tracked > 0 ) {
                $earning_points = $guest_tracked;
                $options = get_option( 'spar_options', [] );
                $award_timing = $options['earn']['order']['award_timing'] ?? 'thankyou';
                if ( 'thankyou' === $award_timing ) {
                    $earning_message = sprintf( 
                        /* translators: 1: points earned, 2: points label */
                        esc_html__( 'You earned %1$s %2$s from this order!', 'simple-points-and-rewards' ),
                        esc_html( spar_format_points_value( $guest_tracked ) ),
                        esc_html( spar_get_points_label_for_count( $guest_tracked, $points_label, 'header_thankyou_guest_order_earned' ) )
                     );
                } else {
                    $earning_message = sprintf( 
                        /* translators: 1: points to earn, 2: points label */
                        esc_html__( 'You will earn %1$s %2$s for this order when it is completed.', 'simple-points-and-rewards' ),
                        esc_html( spar_format_points_value( $guest_tracked ) ),
                        esc_html( spar_get_points_label_for_count( $guest_tracked, $points_label, 'header_thankyou_guest_order_pending' ) )
                     );
                }
            }
            // Hide redeem toggle for guests.
            $show_redeem_toggle = false;
        } elseif ( $order && (int) $order->get_user_id() === (int) $user_id ) {
            if ( function_exists( 'sparp_cr_context_has_disabled_earning__premium_only' ) && sparp_cr_context_has_disabled_earning__premium_only( 'order', $order, $user_id ) ) {
                $earning_message = '';
            } else {
                $order_points_are_reversed = (int) $order->get_meta( 'points_deducted' ) > 0 || method_exists( $order, 'has_status' ) && $order->has_status( array('refunded', 'cancelled', 'failed') );
                $earned_points = ( $order_points_are_reversed ? 0 : (int) $order->get_meta( 'points_earned' ) );
                $is_using_stored_points = $earned_points > 0 || $order_points_are_reversed;
                if ( !$order_points_are_reversed && $earned_points <= 0 ) {
                    // Compute projected points using the canonical award calculation so the
                    // projection matches what will actually be awarded (multipliers, caps,
                    // conditional rules, and integration adjustments such as renewal rates).
                    if ( function_exists( 'spar_calculate_order_award_points' ) ) {
                        $projection = spar_calculate_order_award_points( $order, $user_id );
                        $earned_points = (int) ($projection['points'] ?? 0);
                    } else {
                        $base_points = ( function_exists( 'spar_calculate_order_base_points' ) ? (int) spar_calculate_order_base_points( $order ) : 0 );
                        $currency = ( method_exists( $order, 'get_currency' ) ? $order->get_currency() : '' );
                        $fixed_total = ( function_exists( 'spar_get_points_calculation_total_from_order' ) ? spar_get_points_calculation_total_from_order( $order, 'order_fixed' ) : (float) $order->get_total() );
                        $fixed_info = ( function_exists( 'spar_calculate_fixed_order_points_for_total' ) ? spar_calculate_fixed_order_points_for_total( $fixed_total, $currency ) : [
                            'points' => 0,
                        ] );
                        $fixed_pts = (int) ($fixed_info['points'] ?? 0);
                        $multiplier = ( function_exists( 'spar_get_user_points_multiplier' ) ? (float) spar_get_user_points_multiplier( $user_id, 'order' ) : 1.0 );
                        $earned_points = (int) floor( ($base_points + $fixed_pts) * max( 0.0, $multiplier ) );
                    }
                }
                $options = get_option( 'spar_options', [] );
                $award_timing = $options['earn']['order']['award_timing'] ?? 'thankyou';
                // Check first-order bonus
                $first_order_bonus_meta = (int) $order->get_meta( 'points_first_order_bonus' );
                $first_order_timing = $options['earn']['first_order']['award_timing'] ?? 'thankyou';
                $user_rewards_earned = get_user_meta( $user_id, '_spar_rewards_earned', true );
                $first_order_already_awarded = is_array( $user_rewards_earned ) && !empty( $user_rewards_earned['first_order'] );
                // Calculate immediate and completion points separately
                $immediate_points = 0;
                $completion_points = 0;
                if ( 'thankyou' === $award_timing ) {
                    $immediate_points += $earned_points;
                } else {
                    $completion_points += $earned_points;
                }
                // Add first-order bonus manually only if we're calculating projected points
                // When using stored points_earned, it already includes the first-order bonus
                if ( $first_order_bonus_meta > 0 && !$is_using_stored_points ) {
                    if ( 'thankyou' === $first_order_timing && $first_order_already_awarded ) {
                        // Bonus was awarded immediately - include in immediate points
                        $immediate_points += $first_order_bonus_meta;
                    } elseif ( 'completed' === $first_order_timing && !$first_order_already_awarded ) {
                        // Bonus will be awarded on completion - include in completion points
                        $completion_points += $first_order_bonus_meta;
                    }
                }
                // Build message(s)
                $messages = [];
                if ( $immediate_points > 0 ) {
                    $messages[] = sprintf( 
                        /* translators: 1: points amount earned, 2: points label */
                        esc_html__( 'You earned %1$s %2$s from this order!', 'simple-points-and-rewards' ),
                        esc_html( spar_format_points_value( $immediate_points ) ),
                        esc_html( spar_get_points_label_for_count( $immediate_points, $points_label, 'header_thankyou_order_earned' ) )
                     );
                }
                if ( $completion_points > 0 ) {
                    $messages[] = sprintf( 
                        /* translators: 1: points amount to earn, 2: points label */
                        esc_html__( 'You will earn %1$s %2$s for this order when it is completed.', 'simple-points-and-rewards' ),
                        esc_html( spar_format_points_value( $completion_points ) ),
                        esc_html( spar_get_points_label_for_count( $completion_points, $points_label, 'header_thankyou_order_pending' ) )
                     );
                }
                $earning_message = implode( ' ', $messages );
            }
        }
    } elseif ( $cart ) {
        $earn_options = spar_get_options( 'earn' );
        $cart_total = spar_get_points_calculation_total_from_cart( 'order' );
        $cart_total_fixed = spar_get_points_calculation_total_from_cart( 'order_fixed' );
        if ( !empty( $earn_options['order']['enabled'] ) && $cart_total > 0 || !empty( $earn_options['order_fixed']['enabled'] ) && $cart_total_fixed > 0 ) {
            $hide_order_earning = function_exists( 'sparp_cr_context_has_disabled_earning__premium_only' ) && sparp_cr_context_has_disabled_earning__premium_only( 'cart', $cart, $user_id );
            $currency = ( function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : (( defined( 'WC_VERSION' ) ? get_option( 'woocommerce_currency' ) : 'USD' )) );
            $base_points = 0;
            if ( !empty( $earn_options['order']['enabled'] ) ) {
                if ( function_exists( 'spar_get_order_points_rate' ) ) {
                    $rate = (float) spar_get_order_points_rate( $currency );
                } else {
                    $pp_points = ( isset( $earn_options['order']['points_per_points'] ) ? (float) $earn_options['order']['points_per_points'] : 0.0 );
                    $pp_amount = ( isset( $earn_options['order']['points_per_amount'] ) ? (float) $earn_options['order']['points_per_amount'] : 1.0 );
                    $rate = ( $pp_points > 0 && $pp_amount > 0 ? $pp_points / $pp_amount : (( isset( $earn_options['order']['points_per'] ) ? (float) $earn_options['order']['points_per'] : 0.0 )) );
                }
                $base_points = floor( $cart_total * max( 0, $rate ) );
            }
            $fixed_points = 0;
            if ( !$hide_order_earning && !empty( $earn_options['order_fixed']['enabled'] ) && function_exists( 'spar_calculate_fixed_order_points_for_total' ) ) {
                $fixed_info = spar_calculate_fixed_order_points_for_total( $cart_total_fixed, $currency );
                $fixed_points = (int) ($fixed_info['points'] ?? 0);
                if ( isset( $fixed_info['next_delta'], $fixed_info['next_points'] ) && $fixed_info['next_delta'] !== null && $fixed_info['next_points'] !== null && $fixed_info['next_points'] > 0 ) {
                    $earning_upsell_message = sprintf(
                        /* translators: 1: formatted currency amount, 2: additional points amount, 3: points label */
                        wp_kses_post( esc_html__( 'Spend %1$s more to get %2$s additional %3$s.', 'simple-points-and-rewards' ) ),
                        ( function_exists( 'wc_price' ) ? wc_price( $fixed_info['next_delta'] ) : esc_html( spar_format_currency_amount( $fixed_info['next_delta'] ) ) ),
                        spar_format_points_display( (int) $fixed_info['next_points'], 'inline' ),
                        esc_html( spar_get_points_label_for_count( $fixed_info['next_points'], $points_label, 'header_cart_fixed_points_upsell' ) )
                    );
                }
            }
            $base_points = (int) $base_points;
            $fixed_points = (int) $fixed_points;
            $earning_points = $base_points + $fixed_points;
            $level_multiplier = 1.0;
            if ( function_exists( 'spar_get_user_points_multiplier' ) ) {
                $level_multiplier = max( 0.0, (float) spar_get_user_points_multiplier( $user_id, 'order' ) );
            }
            $earning_points = (int) floor( (float) $earning_points * $level_multiplier );
            if ( function_exists( 'sparp_cr_calculate_conditionally_adjusted_points__premium_only' ) ) {
                $calc = sparp_cr_calculate_conditionally_adjusted_points__premium_only(
                    $base_points,
                    $fixed_points,
                    $level_multiplier,
                    $user_id,
                    'cart',
                    $cart
                );
                if ( is_array( $calc ) && isset( $calc['adjusted'] ) ) {
                    $earning_points = max( 0, (int) $calc['adjusted'] );
                }
            }
            // Include first-order bonus in preview
            if ( !empty( $earn_options['first_order']['enabled'] ) ) {
                $first_order_points = (int) ($earn_options['first_order']['points'] ?? 0);
                if ( $first_order_points > 0 ) {
                    // For logged-in users, only add if they qualify
                    // For guests, show the bonus to encourage sign-up
                    if ( is_user_logged_in() ) {
                        if ( function_exists( 'spar_user_qualifies_for_first_order_bonus' ) && spar_user_qualifies_for_first_order_bonus( $user_id ) ) {
                            $earning_points += $first_order_points;
                        }
                    } else {
                        // Guest users - show first-order bonus to encourage registration
                        $earning_points += $first_order_points;
                    }
                }
            }
            if ( $hide_order_earning ) {
                $earning_upsell_message = '';
                $earning_points = 0;
            }
            if ( $earning_points > 0 ) {
                if ( is_user_logged_in() ) {
                    $earning_message = sprintf( 
                        /* translators: 1: points amount to earn, 2: points label */
                        esc_html__( 'You will earn %1$s %2$s from this order', 'simple-points-and-rewards' ),
                        esc_html( spar_format_points_value( $earning_points ) ),
                        esc_html( spar_get_points_label_for_count( $earning_points, $points_label, 'header_cart_order_earning' ) )
                     ) . '!';
                } elseif ( function_exists( 'spar_is_guest_tracking_enabled' ) && spar_is_guest_tracking_enabled() ) {
                    $earning_message = sprintf( 
                        /* translators: 1: points amount to earn, 2: points label */
                        esc_html__( 'You will earn %1$s %2$s from this order', 'simple-points-and-rewards' ),
                        esc_html( spar_format_points_value( $earning_points ) ),
                        esc_html( spar_get_points_label_for_count( $earning_points, $points_label, 'header_cart_guest_order_earning' ) )
                     ) . '!';
                } else {
                    $earning_message = sprintf( 
                        /* translators: 1: points amount to earn, 2: points label */
                        esc_html__( 'Login to earn %1$s %2$s from this order!', 'simple-points-and-rewards' ),
                        esc_html( spar_format_points_value( $earning_points ) ),
                        esc_html( spar_get_points_label_for_count( $earning_points, $points_label, 'header_cart_login_order_earning' ) )
                     );
                }
            }
        }
    }
    if ( $show_summary_tooltip && !$is_thankyou && ('checkout' === $page || 'cart' === $page) && $cart && $earning_points > 0 ) {
        $summary_rows = spar_build_cart_points_summary_rows(
            $cart,
            $user_id,
            $points_label,
            $earning_points,
            $base_points,
            $fixed_points,
            $level_multiplier
        );
    }
    ob_start();
    ?>
    <div class="spar-cart-rewards-title-section">
        <h3 class="spar-cart-rewards-title"><?php 
    /* translators: %s: points label */
    printf( esc_html__( 'Your %s', 'simple-points-and-rewards' ), esc_html( $points_label ) );
    ?></h3>
        <?php 
    if ( $earning_message ) {
        ?>
            <div class="spar-cart-earning-message">
                <?php 
        if ( !empty( $summary_rows ) ) {
            ?>
                    <?php 
            $amount_parts = [];
            if ( preg_match( '/^(.*?)(\\b\\d+[\\d,]*\\b)(.*)$/', $earning_message, $amount_parts ) ) {
                $amount_before = $amount_parts[1] ?? '';
                $amount_value = $amount_parts[2] ?? '';
                $amount_after = $amount_parts[3] ?? '';
                // Keep the points prefix (e.g. "$") attached to the amount inside the badge
                // instead of stranded at the end of the leading text.
                $points_prefix_str = ( function_exists( 'spar_get_points_prefix' ) ? spar_get_points_prefix() : '' );
                if ( '' !== $points_prefix_str && '' !== $amount_before && substr( $amount_before, -strlen( $points_prefix_str ) ) === $points_prefix_str ) {
                    $amount_before = substr( $amount_before, 0, -strlen( $points_prefix_str ) );
                    $amount_value = $points_prefix_str . $amount_value;
                }
                ?>
                        <?php 
                echo esc_html( $amount_before );
                ?>
                        <span class="spar-points-summary-wrap">
                            <span class="spar-points-summary-trigger">
                                <span class="spar-points-summary-amount" tabindex="0"><?php 
                echo esc_html( $amount_value );
                ?></span>
                                <span class="spar-points-summary-tooltip" role="tooltip">
                                    <span class="spar-points-summary-title"><?php 
                esc_html_e( 'Points Summary', 'simple-points-and-rewards' );
                ?></span>
                                    <table class="spar-points-summary-table">
                                        <tbody>
                                            <?php 
                foreach ( $summary_rows as $row ) {
                    ?>
                                                <tr>
                                                    <td><?php 
                    echo esc_html( $row['label'] );
                    ?></td>
                                                    <td><?php 
                    echo esc_html( $row['value'] );
                    ?></td>
                                                </tr>
                                            <?php 
                }
                ?>
                                        </tbody>
                                    </table>
                                </span>
                            </span>
                        </span>
                        <?php 
                echo esc_html( $amount_after );
                ?>
                    <?php 
            } else {
                ?>
                        <?php 
                echo esc_html( $earning_message );
                ?>
                    <?php 
            }
            ?>
                <?php 
        } else {
            ?>
                    <?php 
            echo esc_html( $earning_message );
            ?>
                <?php 
        }
        ?>
            </div>
        <?php 
    }
    ?>
        <?php 
    if ( !empty( $earning_upsell_message ) ) {
        ?>
            <div class="spar-cart-earning-upsell"><?php 
        echo wp_kses_post( $earning_upsell_message );
        ?></div>
        <?php 
    }
    ?>
        <?php 
    if ( $is_guest_thankyou ) {
        ?>
            <?php 
        $register_url = ( function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/my-account/' ) );
        // Append billing email so the My Account form can be pre-filled.
        if ( isset( $order ) && $order ) {
            $guest_email = $order->get_billing_email();
            if ( $guest_email ) {
                $register_url = add_query_arg( 'spar_email', rawurlencode( $guest_email ), $register_url );
            }
        }
        ?>
            <div class="spar-cart-earning-message spar-guest-register-prompt">
                <?php 
        echo wp_kses_post( sprintf( 
            /* translators: 1: register link (HTML anchor tag), 2: login link (HTML anchor tag) */
            esc_html__( '%1$s or %2$s to claim your points.', 'simple-points-and-rewards' ),
            '<a href="' . esc_url( $register_url ) . '">' . esc_html__( 'Register', 'simple-points-and-rewards' ) . '</a>',
            '<a href="' . esc_url( $register_url ) . '">' . esc_html__( 'login', 'simple-points-and-rewards' ) . '</a>'
         ) );
        ?>
            </div>
        <?php 
    }
    ?>
    </div>
    <div class="spar-cart-rewards-summary">
        <div class="spar-cart-rewards-points">
            <?php 
    $display_points = $user_points;
    if ( $is_guest_thankyou && isset( $order ) && $order ) {
        $guest_email_for_total = $order->get_billing_email();
        if ( $guest_email_for_total && function_exists( 'spar_get_guest_total_points' ) ) {
            $guest_total = (int) spar_get_guest_total_points( $guest_email_for_total );
            // If the current order hasn't been tracked yet (e.g. award on completion),
            // add the projected points so the total includes this order.
            $already_tracked = (int) $order->get_meta( '_spar_guest_points_tracked' );
            if ( $already_tracked <= 0 && $earning_points > 0 ) {
                $guest_total += $earning_points;
            }
            $display_points = max( $guest_total, $earning_points );
        } else {
            $display_points = $earning_points;
        }
    }
    ?>
            <span class="spar-cart-points-number"><?php 
    echo spar_format_points_display( $display_points, 'prominent' );
    ?></span>
            <span class="spar-cart-points-label"><?php 
    echo esc_html( spar_get_points_label_for_count( $display_points, $points_label, 'cart_points_total' ) );
    ?></span>
        </div>
        <?php 
    if ( $show_redeem_toggle ) {
        ?>
        <div class="spar-cart-rewards-actions">
            <button class="spar-redeem-toggle-btn" type="button"><?php 
        echo esc_html( $checkout_redeem_button_label );
        ?></button>
        </div>
        <?php 
    }
    ?>
    </div>
    <?php 
    return ob_get_clean();
}

/**
 * Add JavaScript for rewards box functionality - REMOVED
 * This function is no longer needed as we use wp_add_inline_script instead
 */
/**
 * AJAX handler to get cart rewards data
 */
add_action( 'wp_ajax_spar_get_cart_rewards_data', 'spar_ajax_get_cart_rewards_data' );
function spar_ajax_get_cart_rewards_data() {
    // Verify nonce using core helper (dies or returns error if invalid when $die is true)
    check_ajax_referer( 'spar_cart_rewards', 'nonce', true );
    sparp_maybe_load_cart_for_rewards();
    $cart = ( function_exists( 'spar_get_wc_cart' ) ? spar_get_wc_cart() : null );
    // if ( ! is_user_logged_in() ) {
    // wp_send_json_error( esc_html__( 'Please log in to view rewards.', 'simple-points-and-rewards' ) );
    // }
    $user_id = get_current_user_id();
    $user_points = spar_get_user_points( $user_id );
    $points_label = spar_get_configured_points_label();
    // Get available rewards
    $rewards = spar_get_rewards();
    $rewards_data = [];
    $count = 0;
    foreach ( $rewards as $reward ) {
        $count++;
        if ( $count > 3 ) {
            break;
        }
        $points_required = (int) ($reward['points'] ?? 0);
        $can_redeem = $user_points >= $points_required;
        $points_needed = max( 0, $points_required - $user_points );
        $reward_data = [
            'id'            => $reward['id'],
            'name'          => $reward['name'] ?? esc_html__( 'Untitled Reward', 'simple-points-and-rewards' ),
            'points'        => $points_required,
            'type'          => $reward['type'] ?? 'voucher',
            'can_redeem'    => $can_redeem,
            'points_needed' => $points_needed,
        ];
        // Add type-specific data
        if ( $reward['type'] === 'voucher' ) {
            $reward_data['voucher_amount'] = $reward['voucher_amount'] ?? 0;
            $reward_data['discount_type'] = $reward['discount_type'] ?? 'fixed_cart';
        } elseif ( $reward['type'] === 'product' ) {
            $product_id = $reward['product_id'] ?? 0;
            if ( $product_id ) {
                $product = wc_get_product( $product_id );
                if ( $product ) {
                    $reward_data['product_name'] = $product->get_name();
                }
            }
        }
        $rewards_data[] = $reward_data;
    }
    // Get available vouchers
    $vouchers_data = [];
    $available_vouchers = spar_get_user_available_vouchers( $user_id );
    foreach ( $available_vouchers as $voucher ) {
        $voucher_data = [
            'code'       => $voucher->post_title,
            'is_applied' => ( $cart ? $cart->has_discount( $voucher->post_title ) : false ),
        ];
        // Get voucher details
        $discount_type = get_post_meta( $voucher->ID, 'discount_type', true );
        $amount = get_post_meta( $voucher->ID, 'coupon_amount', true );
        $free_shipping = get_post_meta( $voucher->ID, 'free_shipping', true );
        $has_free_shipping = 'yes' === $free_shipping;
        // If the coupon meta doesn't indicate free shipping, check the parent reward's
        // current settings as a fallback (handles coupons created before the free
        // shipping option was added, or where a template coupon overrode the value).
        if ( !$has_free_shipping ) {
            $reward_id = get_post_meta( $voucher->ID, '_spar_reward_id', true );
            if ( $reward_id && function_exists( 'spar_get_reward_by_id' ) ) {
                $parent_reward = spar_get_reward_by_id( $reward_id );
                if ( $parent_reward && !empty( $parent_reward['free_shipping'] ) ) {
                    $has_free_shipping = true;
                }
            }
        }
        // Format value display (allowing HTML for price formatting)
        $voucher_data['value_display'] = spar_format_voucher_value_display( $discount_type, $amount, $has_free_shipping );
        // Override display for product vouchers
        $product_ids = get_post_meta( $voucher->ID, 'product_ids', true );
        if ( !empty( $product_ids ) ) {
            $ids = explode( ',', $product_ids );
            if ( !empty( $ids[0] ) && function_exists( 'wc_get_product' ) ) {
                $product = wc_get_product( $ids[0] );
                if ( $product ) {
                    /* translators: %s: Product name */
                    $voucher_data['value_display'] = sprintf( esc_html__( 'Free Product: %s', 'simple-points-and-rewards' ), $product->get_name() );
                }
            }
        }
        // Get expiry information
        $expiry_date = get_post_meta( $voucher->ID, 'date_expires', true );
        if ( $expiry_date ) {
            $voucher_data['expiry_formatted'] = date_i18n( get_option( 'date_format' ), $expiry_date );
        }
        // Coupon limitation notes (min spend, restrictions, etc.)
        if ( function_exists( 'spar_get_coupon_limitations' ) ) {
            $voucher_data['limitations'] = spar_get_coupon_limitations( $voucher->ID );
        }
        $vouchers_data[] = $voucher_data;
    }
    wp_send_json_success( [
        'rewards'     => $rewards_data,
        'vouchers'    => $vouchers_data,
        'user_points' => $user_points,
    ] );
}

/**
 * AJAX: return the rewards header fragment HTML
 */
add_action( 'wp_ajax_spar_get_rewards_header', 'spar_ajax_get_rewards_header' );
add_action( 'wp_ajax_nopriv_spar_get_rewards_header', 'spar_ajax_get_rewards_header' );
function spar_ajax_get_rewards_header() {
    check_ajax_referer( 'spar_cart_rewards', 'nonce', true );
    // Page context: default cart
    $page = ( isset( $_POST['page'] ) ? sanitize_text_field( wp_unslash( $_POST['page'] ) ) : 'cart' );
    $args = [];
    if ( 'thankyou' === $page ) {
        $args['order_id'] = ( isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0 );
        // Verify the requester has access to this order to prevent order ID enumeration.
        if ( $args['order_id'] ) {
            $ajax_order = wc_get_order( $args['order_id'] );
            if ( !$ajax_order ) {
                wp_send_json_success( [
                    'html' => '',
                ] );
            }
            if ( is_user_logged_in() ) {
                if ( (int) $ajax_order->get_user_id() !== (int) get_current_user_id() ) {
                    wp_send_json_success( [
                        'html' => '',
                    ] );
                }
            } else {
                // Guest: verify order key from POST matches.
                $ajax_order_key = ( isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : '' );
                if ( empty( $ajax_order_key ) || $ajax_order->get_order_key() !== $ajax_order_key ) {
                    wp_send_json_success( [
                        'html' => '',
                    ] );
                }
            }
        }
    }
    if ( !is_user_logged_in() && !spar_get_option( '', 'show_for_guests' ) ) {
        wp_send_json_success( [
            'html' => '',
        ] );
    }
    sparp_maybe_load_cart_for_rewards();
    $html = spar_get_rewards_header_html( $page, $args );
    wp_send_json_success( [
        'html' => $html,
    ] );
}

/**
 * AJAX: return the current redemption limits/state so the injected block compact
 * tool can refresh itself when the cart total changes (e.g. crossing the minimum
 * cart total threshold) without a full page reload.
 */
add_action( 'wp_ajax_spar_get_redeem_state', 'spar_ajax_get_redeem_state' );
add_action( 'wp_ajax_nopriv_spar_get_redeem_state', 'spar_ajax_get_redeem_state' );
function spar_ajax_get_redeem_state() {
    check_ajax_referer( 'spar_cart_rewards', 'nonce', true );
    if ( function_exists( 'sparp_maybe_load_cart_for_rewards' ) ) {
        sparp_maybe_load_cart_for_rewards();
    }
    // Recalculate so the cart total (and therefore the min-cart-total gate) is current.
    $cart = ( function_exists( 'spar_get_wc_cart' ) ? spar_get_wc_cart() : null );
    if ( $cart && method_exists( $cart, 'calculate_totals' ) ) {
        $cart->calculate_totals();
    }
    $options = get_option( 'spar_options', array() );
    $user_id = get_current_user_id();
    $user_points = ( is_user_logged_in() ? (int) spar_get_user_points( $user_id ) : 0 );
    $store_currency = ( function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : (( defined( 'WC_VERSION' ) ? get_option( 'woocommerce_currency' ) : 'USD' )) );
    $current = array();
    $session = ( function_exists( 'spar_get_wc_session' ) ? spar_get_wc_session() : null );
    if ( $session ) {
        $current = (array) $session->get( 'spar_points_redemption', array() );
    }
    wp_send_json_success( array(
        'limits'     => spar_get_redeem_limits_payload( $user_points, $store_currency, $options ),
        'current'    => $current,
        'userPoints' => $user_points,
    ) );
}

/**
 * AJAX: return full rewards box HTML (used when we need to refresh everything)
 */
add_action( 'wp_ajax_spar_get_rewards_box', 'spar_ajax_get_rewards_box' );
add_action( 'wp_ajax_nopriv_spar_get_rewards_box', 'spar_ajax_get_rewards_box' );
function spar_ajax_get_rewards_box() {
    check_ajax_referer( 'spar_cart_rewards', 'nonce', true );
    $page = ( isset( $_POST['page'] ) ? sanitize_text_field( wp_unslash( $_POST['page'] ) ) : 'cart' );
    $args = [];
    if ( 'thankyou' === $page ) {
        $args['order_id'] = ( isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0 );
        // Verify the requester has access to this order to prevent order ID enumeration.
        if ( $args['order_id'] ) {
            $ajax_order = wc_get_order( $args['order_id'] );
            if ( !$ajax_order ) {
                wp_send_json_success( [
                    'html' => '',
                ] );
            }
            if ( is_user_logged_in() ) {
                if ( (int) $ajax_order->get_user_id() !== (int) get_current_user_id() ) {
                    wp_send_json_success( [
                        'html' => '',
                    ] );
                }
            } else {
                // Guest: verify order key from POST matches.
                $ajax_order_key = ( isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : '' );
                if ( empty( $ajax_order_key ) || $ajax_order->get_order_key() !== $ajax_order_key ) {
                    wp_send_json_success( [
                        'html' => '',
                    ] );
                }
            }
        }
    }
    if ( !is_user_logged_in() && !spar_get_option( '', 'show_for_guests' ) ) {
        wp_send_json_success( [
            'html' => '',
        ] );
    }
    sparp_maybe_load_cart_for_rewards();
    $html = spar_get_rewards_box_html( $page, $args );
    wp_send_json_success( [
        'html' => $html,
    ] );
}

/**
 * AJAX handler to redeem reward from cart
 */
add_action( 'wp_ajax_spar_redeem_reward_cart', 'spar_ajax_redeem_reward_cart' );
function spar_ajax_redeem_reward_cart() {
    // Verify nonce
    check_ajax_referer( 'spar_redeem_reward', 'nonce', true );
    if ( !is_user_logged_in() ) {
        wp_send_json_error( esc_html__( 'Please log in to redeem rewards.', 'simple-points-and-rewards' ) );
    }
    sparp_maybe_load_cart_for_rewards();
    $reward_id = ( isset( $_POST['reward_id'] ) ? sanitize_text_field( wp_unslash( $_POST['reward_id'] ) ) : '' );
    if ( empty( $reward_id ) ) {
        wp_send_json_error( esc_html__( 'Invalid reward selected.', 'simple-points-and-rewards' ) );
    }
    $user_id = get_current_user_id();
    // Get reward
    $reward = spar_get_reward_by_id( $reward_id );
    if ( !$reward ) {
        wp_send_json_error( esc_html__( 'Reward not found.', 'simple-points-and-rewards' ) );
    }
    // Process redemption
    $result = spar_process_reward_redemption( $user_id, $reward_id );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( $result->get_error_message() );
    }
    $new_points = spar_get_user_points( $user_id );
    if ( $reward['type'] === 'product' ) {
        wp_send_json_success( [
            'message'              => esc_html__( 'Product added to cart! Redirecting to checkout...', 'simple-points-and-rewards' ),
            'new_points'           => $new_points,
            'redirect_to_checkout' => true,
            'checkout_url'         => ( function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : '' ),
        ] );
    } else {
        wp_send_json_success( [
            'message'      => sprintf( 
                /* translators: %s: Voucher code (e.g., ABC123). */
                esc_html__( 'Voucher created: %s', 'simple-points-and-rewards' ),
                $result
             ),
            'new_points'   => $new_points,
            'voucher_code' => $result,
        ] );
    }
}

/**
 * Get user's available vouchers.
 *
 * Only retrieves coupons created by this plugin (tagged with
 * `_spar_reward_voucher = 1`) for the given user. Uses a single get_posts()
 * with exact-match meta queries on indexed keys — no LIKE scans.
 *
 * The old approach also ran a LIKE query against `customer_email` across
 * every coupon in the database, which caused full postmeta table scans on
 * stores with tens of thousands of coupons (e.g. 50k+ from AutomateWoo).
 * That query was redundant because spar_create_reward_voucher() always sets
 * `_spar_user_id` on every coupon it creates, so matching by user ID is
 * sufficient.
 *
 * Results are cached in the object cache so multiple callers on the same
 * page (cart box, rewards widget, vouchers template) pay the DB cost once.
 *
 * @param int $user_id WP user ID.
 * @return WP_Post[] Array of available coupon posts.
 */
function spar_get_user_available_vouchers(  $user_id  ) {
    $user_id = absint( $user_id );
    if ( !$user_id ) {
        return [];
    }
    // Return from object cache if already fetched this request.
    $cache_key = 'spar_user_available_vouchers_' . $user_id;
    $cached = wp_cache_get( $cache_key, 'spar' );
    if ( false !== $cached ) {
        return $cached;
    }
    // Single query: plugin coupons for this user (exact meta match, indexed, fast).
    $coupon_posts = get_posts( [
        'post_type'     => 'shop_coupon',
        'post_status'   => 'publish',
        'numberposts'   => 200,
        'no_found_rows' => true,
        'orderby'       => 'date',
        'order'         => 'DESC',
        'meta_query'    => [
            'relation' => 'AND',
            [
                'key'     => '_spar_reward_voucher',
                'value'   => '1',
                'compare' => '=',
            ],
            [
                'relation' => 'OR',
                [
                    'key'     => '_spar_user_id',
                    'value'   => $user_id,
                    'compare' => '=',
                ],
                [
                    'key'     => 'spar_user_id',
                    'value'   => $user_id,
                    'compare' => '=',
                ],
            ],
        ],
    ] );
    // Filter out used / expired coupons in PHP.
    // The result set is small (only this plugin's coupons for one user) so this is cheap.
    $now = time();
    $results = [];
    foreach ( $coupon_posts as $coupon ) {
        // Skip expired coupons.
        $expiry = get_post_meta( $coupon->ID, 'date_expires', true );
        if ( $expiry && (int) $expiry <= $now ) {
            continue;
        }
        // Skip fully-used coupons (referral coupons are exempt from usage limits).
        $is_referral = get_post_meta( $coupon->ID, '_spar_referrer_id', true );
        if ( !$is_referral ) {
            $usage_count = (int) get_post_meta( $coupon->ID, 'usage_count', true );
            $usage_limit = (int) get_post_meta( $coupon->ID, 'usage_limit', true );
            if ( 0 === $usage_limit ) {
                $usage_limit = 1;
            }
            if ( $usage_count >= $usage_limit ) {
                continue;
            }
        }
        $results[] = $coupon;
    }
    wp_cache_set(
        $cache_key,
        $results,
        'spar',
        60
    );
    return $results;
}
