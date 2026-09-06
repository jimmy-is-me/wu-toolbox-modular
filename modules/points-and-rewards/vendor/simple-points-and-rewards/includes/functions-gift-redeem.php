<?php

/**
 * Display points information on frontend pages
 */
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
// Include cart and checkout rewards functionality
require_once SPAR_PLUGIN_PATH . 'includes/frontend/cart-checkout.php';
// Display points on single product page using configurable hook
add_action( 'init', 'spar_register_product_points_display_hook' );
function spar_register_product_points_display_hook() {
    $fallback_hook = 'woocommerce_single_product_summary';
    $fallback_priority = 25;
    if ( !function_exists( 'add_action' ) ) {
        return;
    }
    if ( !function_exists( 'spar_get_option' ) ) {
        add_action( $fallback_hook, 'spar_display_product_points', $fallback_priority );
        return;
    }
    $selected_location = spar_get_option( '', 'product_points_location' );
    $locations = ( function_exists( 'spar_get_product_points_location_options' ) ? spar_get_product_points_location_options() : array() );
    if ( empty( $locations ) ) {
        add_action( $fallback_hook, 'spar_display_product_points', $fallback_priority );
        return;
    }
    if ( empty( $selected_location ) || !isset( $locations[$selected_location] ) ) {
        if ( isset( $locations['summary_after_price'] ) ) {
            $selected_location = 'summary_after_price';
        } else {
            $location_keys = array_keys( $locations );
            $selected_location = ( !empty( $location_keys ) ? reset( $location_keys ) : '' );
        }
    }
    $chosen = $locations[$selected_location] ?? array();
    $hook = ( isset( $chosen['hook'] ) && is_string( $chosen['hook'] ) ? $chosen['hook'] : $fallback_hook );
    $priority = ( isset( $chosen['priority'] ) ? (int) $chosen['priority'] : $fallback_priority );
    $priority = ( $priority > 0 ? $priority : $fallback_priority );
    add_action( $hook, 'spar_display_product_points', $priority );
}

// Display points on catalog/shop loops with configurable hook
add_action( 'init', 'spar_register_product_loop_points_display_hook' );
function spar_register_product_loop_points_display_hook() {
    $fallback_hook = 'woocommerce_after_shop_loop_item_title';
    $fallback_priority = 12;
    if ( !function_exists( 'add_action' ) || !function_exists( 'spar_get_option' ) ) {
        return;
    }
    if ( !spar_get_option( '', 'show_on_product_loop' ) ) {
        return;
    }
    $selected_location = spar_get_option( '', 'product_loop_points_location' );
    $locations = ( function_exists( 'spar_get_product_loop_points_location_options' ) ? spar_get_product_loop_points_location_options() : array() );
    if ( empty( $locations ) ) {
        add_action( $fallback_hook, 'spar_display_product_loop_points', $fallback_priority );
        return;
    }
    if ( empty( $selected_location ) || !isset( $locations[$selected_location] ) ) {
        if ( isset( $locations['loop_after_title'] ) ) {
            $selected_location = 'loop_after_title';
        } else {
            $location_keys = array_keys( $locations );
            $selected_location = ( !empty( $location_keys ) ? reset( $location_keys ) : '' );
        }
    }
    if ( empty( $selected_location ) || !isset( $locations[$selected_location] ) ) {
        add_action( $fallback_hook, 'spar_display_product_loop_points', $fallback_priority );
        return;
    }
    $chosen = $locations[$selected_location] ?? array();
    $hook = ( isset( $chosen['hook'] ) && is_string( $chosen['hook'] ) ? $chosen['hook'] : $fallback_hook );
    $priority = ( isset( $chosen['priority'] ) ? (int) $chosen['priority'] : $fallback_priority );
    $priority = ( $priority > 0 ? $priority : $fallback_priority );
    add_action( $hook, 'spar_display_product_loop_points', $priority );
}

function spar_display_product_points() {
    spar_render_product_points_block( 'single' );
}

function spar_display_product_loop_points() {
    spar_render_product_points_block( 'loop' );
}

function spar_render_product_points_block(  $context = 'single'  ) {
    $context = ( 'loop' === $context ? 'loop' : 'single' );
    // Check if points display is enabled for requested context
    $option_key = ( 'loop' === $context ? 'show_on_product_loop' : 'show_on_product' );
    if ( !spar_get_option( '', $option_key ) ) {
        return;
    }
    // Hide product points display for banned users
    if ( is_user_logged_in() ) {
        $current_user_id = get_current_user_id();
        $user_status = get_user_meta( $current_user_id, 'spar_user_status', true );
        if ( 'banned' === $user_status ) {
            return;
            // Do not show points info to banned users
        }
    }
    // Check if order points earning is enabled
    $earn_options = spar_get_options( 'earn' );
    if ( empty( $earn_options['order']['enabled'] ) ) {
        return;
    }
    global $product;
    if ( !$product || !$product->get_price() ) {
        return;
    }
    $prefs = ( function_exists( 'spar_get_points_calculation_preferences' ) ? spar_get_points_calculation_preferences( 'order' ) : [
        'mode'             => 'subtotal_after_discount',
        'include_shipping' => false,
        'include_taxes'    => false,
    ] );
    // Use new currency-aware rate for product price
    // When taxes are excluded from the calculation, use the tax-exclusive price so
    // the product page matches the cart/checkout display (which always uses excl-tax line totals).
    $use_excl_tax = empty( $prefs['include_taxes'] ) && function_exists( 'wc_get_price_excluding_tax' );
    if ( isset( $prefs['mode'] ) && 'total_before_discounts' === $prefs['mode'] ) {
        $regular_price = $product->get_regular_price();
        if ( '' !== $regular_price && null !== $regular_price ) {
            if ( $use_excl_tax ) {
                $product_price = (float) wc_get_price_excluding_tax( $product, [
                    'price' => $regular_price,
                ] );
            } else {
                $product_price = (float) $regular_price;
            }
        } else {
            $product_price = ( $use_excl_tax ? (float) wc_get_price_excluding_tax( $product ) : (float) $product->get_price() );
        }
    } else {
        $product_price = ( $use_excl_tax ? (float) wc_get_price_excluding_tax( $product ) : (float) $product->get_price() );
    }
    $currency = ( function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : (( defined( 'WC_VERSION' ) ? get_option( 'woocommerce_currency' ) : 'USD' )) );
    $rate = ( function_exists( 'spar_get_order_points_rate' ) ? (float) spar_get_order_points_rate( $currency ) : (float) ($earn_options['order']['points_per'] ?? 0) );
    $base_points = spar_round_points( $product_price * max( 0, $rate ) );
    // Apply user level multiplier if logged in (use helper for consistency)
    $current_user_id = get_current_user_id();
    $level_multiplier = 1.0;
    if ( is_user_logged_in() && function_exists( 'spar_get_user_points_multiplier' ) ) {
        $level_multiplier = max( 0.0, (float) spar_get_user_points_multiplier( $current_user_id, 'order' ) );
    }
    $points_earned = spar_round_points( (float) $base_points * $level_multiplier );
    if ( function_exists( 'sparp_cr_get_product_points_with_rules__premium_only' ) ) {
        $points_earned = sparp_cr_get_product_points_with_rules__premium_only(
            $base_points,
            $level_multiplier,
            (int) $product->get_id(),
            $current_user_id,
            'order'
        );
    }
    /**
     * Filter the points shown in the product page / catalog "earn points" message.
     * Return 0 to hide the message entirely (e.g. for products excluded from earning).
     *
     * @param int        $points_earned Points to display.
     * @param WC_Product $product       Product object.
     * @param string     $context       'single' or 'loop'.
     */
    $points_earned = (int) apply_filters(
        'spar_product_points_display',
        $points_earned,
        $product,
        $context
    );
    if ( $points_earned <= 0 ) {
        return;
    }
    $options = get_option( 'spar_options', spar_settings_default() );
    $points_label = $options['points_label'] ?? esc_html__( 'Points', 'simple-points-and-rewards' );
    // Check if linking is enabled
    $link_to_rewards = !empty( $options['link_to_rewards'] );
    $rewards_url = '';
    if ( $link_to_rewards ) {
        $rewards_url = wc_get_page_permalink( 'myaccount' ) . 'rewards/';
    }
    $message_template = spar_get_points_message_template( $context, $options );
    $message_output = spar_format_points_message(
        $message_template,
        $points_earned,
        $points_label,
        $link_to_rewards,
        $rewards_url
    );
    $classes = array('spar-product-points');
    if ( 'loop' === $context ) {
        $classes[] = 'spar-product-points--loop';
    }
    $classes = array_map( 'sanitize_html_class', array_filter( $classes ) );
    $class_attr = ( !empty( $classes ) ? implode( ' ', $classes ) : 'spar-product-points' );
    // Text color CSS variable
    $text_color_key = ( 'loop' === $context ? 'product_loop_points_text_color' : 'product_points_text_color' );
    $text_color = ( isset( $options[$text_color_key] ) ? sanitize_hex_color( $options[$text_color_key] ) : '' );
    // Background color CSS variable
    $bg_color_key = ( 'loop' === $context ? 'product_loop_points_bg_color' : 'product_points_bg_color' );
    $bg_color = ( isset( $options[$bg_color_key] ) ? sanitize_hex_color( $options[$bg_color_key] ) : '' );
    $style_parts = array();
    if ( $text_color ) {
        $style_parts[] = '--spar-product-points-text-color:' . esc_attr( $text_color );
    }
    if ( $bg_color ) {
        $style_parts[] = '--spar-product-points-bg-color:' . esc_attr( $bg_color );
    }
    $style_attr = ( !empty( $style_parts ) ? implode( ';', $style_parts ) . ';' : '' );
    // Set link color to text color
    if ( $text_color ) {
        $message_output = str_replace( '<a ', '<a style="text-decoration: underline; color:' . esc_attr( $text_color ) . ';" ', $message_output );
    }
    echo '<div class="' . esc_attr( $class_attr ) . '"' . (( $style_attr ? ' style="' . esc_attr( $style_attr ) . '"' : '' )) . '>';
    echo '<span class="spar-points-message">' . wp_kses_post( $message_output ) . '</span>';
    // Show referral bonus info if enabled
    $referral_options = $earn_options;
    // Read-only parameter; sanitize safely
    $ref_param = filter_input( INPUT_GET, 'ref', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
    if ( !empty( $referral_options['referral']['enabled'] ) && !empty( $ref_param ) ) {
        $earning_type = $referral_options['referral']['earning_type'] ?? 'fixed';
        if ( $earning_type === 'fixed' ) {
            $ref_points = (int) ($referral_options['referral']['fixed_points'] ?? 100);
        } else {
            $percentage = (float) $referral_options['referral']['percentage'];
            $ref_points_per = (float) $referral_options['referral']['points_per'];
            $ref_value = $product_price * $percentage / 100;
            $ref_points = floor( $ref_value * $ref_points_per );
        }
        echo '<br>';
    }
    echo '</div>';
}

if ( !function_exists( 'spar_get_points_message_option_key' ) ) {
    function spar_get_points_message_option_key(  $context  ) {
        return ( 'loop' === $context ? 'product_loop_points_message' : 'product_points_message' );
    }

}
if ( !function_exists( 'spar_get_default_points_message' ) ) {
    function spar_get_default_points_message(  $context  ) {
        $defaults = [
            'single' => esc_html__( 'Earn {points} {points_label_lower} by purchasing this product', 'simple-points-and-rewards' ),
            'loop'   => esc_html__( 'Earn {points} reward points from this product', 'simple-points-and-rewards' ),
        ];
        return $defaults[$context] ?? $defaults['single'];
    }

}
if ( !function_exists( 'spar_get_points_message_template' ) ) {
    function spar_get_points_message_template(  $context, $options  ) {
        $option_key = spar_get_points_message_option_key( $context );
        $template = '';
        if ( isset( $options[$option_key] ) ) {
            $template = (string) $options[$option_key];
        }
        $template = trim( $template );
        if ( '' === $template ) {
            $template = spar_get_default_points_message( $context );
        }
        return $template;
    }

}
if ( !function_exists( 'spar_format_points_message' ) ) {
    function spar_format_points_message(
        $template,
        $points,
        $points_label,
        $link_to_rewards = false,
        $rewards_url = ''
    ) {
        $points_value = spar_format_points_value( (int) $points );
        $label_text = spar_get_points_label_for_count( $points, wp_strip_all_tags( $points_label ), 'product_points_message' );
        $label_lower = spar_strtolower( $label_text );
        $plain_label_display = esc_html( $label_text );
        $plain_label_lower_display = esc_html( $label_lower );
        $points_display = spar_format_points_display( (int) $points, 'prominent' );
        $label_display = $plain_label_display;
        $label_lower_display = $plain_label_lower_display;
        $link_open = '';
        $link_close = '';
        $rewards_url_sanitized = '';
        if ( $link_to_rewards && !empty( $rewards_url ) ) {
            $rewards_url_sanitized = esc_url( $rewards_url );
            if ( !empty( $rewards_url_sanitized ) ) {
                $link_open = '<a href="' . $rewards_url_sanitized . '" target="_blank" rel="noopener">';
                $link_close = '</a>';
                $label_display = $link_open . $label_display . $link_close;
                $label_lower_display = $link_open . $label_lower_display . $link_close;
            }
        }
        // Calculate the monetary value of the points using the Points Discount checkout rate.
        $points_monetary_value = '';
        if ( false !== strpos( $template, '{points_value}' ) && function_exists( 'spar_get_redeem_rate_for_currency' ) && function_exists( 'get_woocommerce_currency' ) ) {
            $options_check = get_option( 'spar_options', [] );
            if ( !empty( $options_check['redeem_individual_enabled'] ) ) {
                $currency = get_woocommerce_currency();
                $rate = spar_get_redeem_rate_for_currency( $currency );
                $rate_pts = (float) $rate['points'];
                $rate_amt = (float) $rate['amount'];
                if ( $rate_pts > 0 && $rate_amt > 0 ) {
                    $value = (float) $points * $rate_amt / $rate_pts;
                    $points_monetary_value = wp_strip_all_tags( wc_price( $value ) );
                }
            }
        }
        $replacements = [
            '{points}'             => $points_display,
            '{points_label}'       => $label_display,
            '{points_label_lower}' => $label_lower_display,
            '{points_value}'       => esc_html( $points_monetary_value ),
        ];
        $message = strtr( esc_html( $template ), $replacements );
        if ( $link_open && false === strpos( $message, '<a ' ) ) {
            $label_variants = array_unique( array_filter( [$plain_label_display, $plain_label_lower_display] ) );
            foreach ( $label_variants as $variant ) {
                $pattern = '/' . preg_quote( $variant, '/' ) . '/i';
                if ( preg_match( $pattern, $message ) ) {
                    $message = preg_replace(
                        $pattern,
                        $link_open . '$0' . $link_close,
                        $message,
                        1
                    );
                    break;
                }
            }
        }
        return apply_filters(
            'spar_formatted_points_message',
            $message,
            $template,
            $points,
            $points_label,
            $link_to_rewards,
            $rewards_url
        );
    }

}
// Remove the separate cart and checkout points display functions since they're now integrated
// into the rewards box in cart-checkout.php
// Display points on cart page - REMOVED (now in rewards box)
// add_action( 'woocommerce_before_cart_totals', 'spar_display_cart_points' );
// add_action( 'woocommerce_cart_collaterals', 'spar_display_cart_points_alt', 5 );
// Display points on checkout page - REMOVED (now in rewards box)
// add_action( 'woocommerce_before_checkout_form', 'spar_display_checkout_points', 5 );
// Keep the unified function for other potential uses but don't hook it to cart/checkout
// function spar_display_points_notice( $context = 'cart', $variant = '' ) {
//     // Function kept for potential other uses but not hooked to cart/checkout
// }
// Enqueue scripts and styles for block support
add_action( 'wp_enqueue_scripts', 'spar_enqueue_display_assets' );
function spar_enqueue_display_assets() {
    $show_on_product_loop = ( function_exists( 'spar_get_option' ) ? spar_get_option( '', 'show_on_product_loop' ) : false );
    $is_shop_page = function_exists( 'is_shop' ) && is_shop();
    $is_product_taxonomy = function_exists( 'is_product_taxonomy' ) && is_product_taxonomy();
    $is_product_archive = is_post_type_archive( 'product' );
    $is_product_search = is_search() && isset( $_GET['post_type'] ) && 'product' === sanitize_text_field( wp_unslash( $_GET['post_type'] ) );
    $should_enqueue_styles = is_product() || is_checkout() || $show_on_product_loop && ($is_shop_page || $is_product_taxonomy || $is_product_archive || $is_product_search);
    if ( !$should_enqueue_styles ) {
        return;
    }
    // Enqueue JS for product page variations
    if ( is_product() ) {
        $product = wc_get_product( get_queried_object_id() );
        if ( $product && $product->is_type( 'variable' ) ) {
            wp_enqueue_script(
                'spar-product-points',
                SPAR_PLUGIN_URL . 'assets/js/product-points.js',
                ['jquery'],
                SPAR_VERSION,
                true
            );
            $earn_options = ( function_exists( 'spar_get_options' ) ? spar_get_options( 'earn' ) : array() );
            $currency = ( function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : (( defined( 'WC_VERSION' ) ? get_option( 'woocommerce_currency' ) : 'USD' )) );
            $rate = ( function_exists( 'spar_get_order_points_rate' ) ? (float) spar_get_order_points_rate( $currency ) : (float) ($earn_options['order']['points_per'] ?? 0) );
            $multiplier = 1.0;
            if ( is_user_logged_in() && function_exists( 'spar_get_user_points_multiplier' ) ) {
                $current_user_id = get_current_user_id();
                $multiplier = (float) spar_get_user_points_multiplier( $current_user_id, 'order' );
            }
            $options = get_option( 'spar_options', spar_settings_default() );
            $points_label = $options['points_label'] ?? esc_html__( 'Points', 'simple-points-and-rewards' );
            $link_to_rewards = !empty( $options['link_to_rewards'] );
            $rewards_url = '';
            if ( $link_to_rewards ) {
                $rewards_url = wc_get_page_permalink( 'myaccount' ) . 'rewards/';
            }
            $label_text = wp_strip_all_tags( $points_label );
            $label_plural_text = spar_get_points_label_for_count( 2, $label_text, 'product_points_variation' );
            $label_singular_text = spar_get_points_label_for_count( 1, $label_text, 'product_points_variation' );
            $label_plural_lower = spar_strtolower( $label_plural_text );
            $label_singular_lower = spar_strtolower( $label_singular_text );
            $label_display = esc_html( $label_plural_text );
            $label_singular_display = esc_html( $label_singular_text );
            $label_lower_display = esc_html( $label_plural_lower );
            $label_lower_singular_display = esc_html( $label_singular_lower );
            if ( $link_to_rewards && !empty( $rewards_url ) ) {
                $rewards_url_sanitized = esc_url( $rewards_url );
                if ( !empty( $rewards_url_sanitized ) ) {
                    $link_open = '<a href="' . $rewards_url_sanitized . '" target="_blank" rel="noopener">';
                    $link_close = '</a>';
                    $label_display = $link_open . $label_display . $link_close;
                    $label_singular_display = $link_open . $label_singular_display . $link_close;
                    $label_lower_display = $link_open . $label_lower_display . $link_close;
                    $label_lower_singular_display = $link_open . $label_lower_singular_display . $link_close;
                }
            }
            $message_template = spar_get_points_message_template( 'single', $options );
            $prefs = ( function_exists( 'spar_get_points_calculation_preferences' ) ? spar_get_points_calculation_preferences( 'order' ) : [
                'mode' => 'subtotal_after_discount',
            ] );
            $conditional_multiplier = 1.0;
            $conditional_min_points = 0;
            $conditional_max_points = 0;
            $conditional_fixed_delta = 0;
            if ( function_exists( 'sparp_cr_get_best_rule_for_product__premium_only' ) ) {
                $best = sparp_cr_get_best_rule_for_product__premium_only( (int) $product->get_id(), get_current_user_id(), 'order' );
                $rule = ( isset( $best['rule'] ) && is_array( $best['rule'] ) ? $best['rule'] : null );
                if ( $rule ) {
                    $conditional_multiplier = max( 0.0, (float) ($best['multiplier'] ?? 1.0) );
                    $conditional_min_points = ( isset( $rule['min_points'] ) ? absint( $rule['min_points'] ) : 0 );
                    $conditional_max_points = ( isset( $rule['max_points'] ) ? absint( $rule['max_points'] ) : 0 );
                    $conditional_fixed_delta = ( isset( $rule['fixed_points_delta'] ) ? (int) $rule['fixed_points_delta'] : 0 );
                    if ( $conditional_min_points > 0 && $conditional_max_points > 0 && $conditional_max_points < $conditional_min_points ) {
                        $conditional_max_points = $conditional_min_points;
                    }
                }
            }
            // Determine whether the displayed variation price includes tax.
            // WooCommerce sets display_price based on woocommerce_tax_display_shop.
            $shop_display_incl_tax = 'incl' === get_option( 'woocommerce_tax_display_shop', 'excl' );
            $calc_include_taxes = !empty( $prefs['include_taxes'] );
            // Points Discount rate for {points_value} placeholder in JS.
            $redeem_rate_data = array(
                'points'         => 0,
                'amount'         => 0,
                'enabled'        => false,
                'currencySymbol' => '',
                'currencyPos'    => 'left',
                'decimals'       => 2,
                'decimalSep'     => '.',
                'thousandSep'    => ',',
            );
            if ( !empty( $options['redeem_individual_enabled'] ) && function_exists( 'spar_get_redeem_rate_for_currency' ) ) {
                $redeem_rate = spar_get_redeem_rate_for_currency( $currency );
                $redeem_rate_data = array(
                    'enabled'        => true,
                    'points'         => (float) $redeem_rate['points'],
                    'amount'         => (float) $redeem_rate['amount'],
                    'currencySymbol' => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
                    'currencyPos'    => get_option( 'woocommerce_currency_pos', 'left' ),
                    'decimals'       => absint( get_option( 'woocommerce_price_num_decimals', 2 ) ),
                    'decimalSep'     => get_option( 'woocommerce_price_decimal_sep', '.' ),
                    'thousandSep'    => get_option( 'woocommerce_price_thousand_sep', ',' ),
                );
            }
            wp_localize_script( 'spar-product-points', 'sparProductPoints', [
                'rate'                     => $rate,
                'multiplier'               => $multiplier,
                'messageTemplate'          => $message_template,
                'pointsPrefix'             => spar_get_points_prefix(),
                'pointsIconProminent'      => spar_get_points_icon_html( 'prominent' ),
                'pointsLabel'              => $label_display,
                'pointsLabelSingular'      => $label_singular_display,
                'pointsLabelLower'         => $label_lower_display,
                'pointsLabelLowerSingular' => $label_lower_singular_display,
                'mode'                     => $prefs['mode'] ?? 'subtotal_after_discount',
                'includeTaxes'             => $calc_include_taxes,
                'displayPriceInclTax'      => $shop_display_incl_tax,
                'conditional'              => [
                    'multiplier' => $conditional_multiplier,
                    'minPoints'  => $conditional_min_points,
                    'maxPoints'  => $conditional_max_points,
                    'fixedDelta' => $conditional_fixed_delta,
                ],
                'redeemRate'               => $redeem_rate_data,
            ] );
        }
    }
    // Enqueue CSS
    wp_enqueue_style(
        'spar-display-styles',
        SPAR_PLUGIN_URL . 'assets/css/display.css',
        [],
        SPAR_VERSION
    );
    // Enqueue JS for checkout block support
    if ( is_checkout() ) {
        $show_on_checkout = spar_get_option( '', 'show_on_checkout' );
        // Only load if enabled on checkout
        if ( is_checkout() && $show_on_checkout ) {
            // Skip all checkout points assets for banned users
            if ( is_user_logged_in() ) {
                $ban_status = get_user_meta( get_current_user_id(), 'spar_user_status', true );
                if ( 'banned' === $ban_status ) {
                    return;
                    // Do not enqueue or localize points display assets
                }
            }
            $earn_options = spar_get_options( 'earn' );
            if ( !empty( $earn_options['order']['enabled'] ) ) {
                wp_enqueue_script(
                    'spar-block-support',
                    SPAR_PLUGIN_URL . 'assets/js/block-support.js',
                    ['jquery'],
                    SPAR_VERSION,
                    true
                );
                // Get current cart totals for JavaScript
                $cart_total = spar_get_points_calculation_total_from_cart( 'order' );
                $cart_total_fixed = spar_get_points_calculation_total_from_cart( 'order_fixed' );
                // Use new currency-aware rate for cart
                $currency = ( function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : (( defined( 'WC_VERSION' ) ? get_option( 'woocommerce_currency' ) : 'USD' )) );
                $rate = ( function_exists( 'spar_get_order_points_rate' ) ? (float) spar_get_order_points_rate( $currency ) : (float) ($earn_options['order']['points_per'] ?? 0) );
                // Base percentage points
                $base_points = spar_round_points( (float) $cart_total * max( 0, $rate ) );
                if ( function_exists( 'spar_apply_order_spend_caps' ) ) {
                    $base_points = spar_apply_order_spend_caps( $base_points, (float) $cart_total );
                }
                // Include fixed tier points if feature exists
                $fixed_points = 0;
                if ( !empty( $earn_options['order_fixed']['enabled'] ) && function_exists( 'spar_calculate_fixed_order_points_for_total' ) ) {
                    $fixed_info = spar_calculate_fixed_order_points_for_total( (float) $cart_total_fixed, $currency );
                    $fixed_points = (int) ($fixed_info['points'] ?? 0);
                }
                $base_points = (int) $base_points;
                $fixed_points = (int) $fixed_points;
                $points_earned = $base_points + $fixed_points;
                $current_user_id = get_current_user_id();
                $level_multiplier = 1.0;
                if ( is_user_logged_in() && function_exists( 'spar_get_user_points_multiplier' ) ) {
                    $level_multiplier = max( 0.0, (float) spar_get_user_points_multiplier( $current_user_id, 'order' ) );
                }
                $points_earned = spar_round_points( (float) ($base_points + $fixed_points) * $level_multiplier );
                $cart_object = ( function_exists( 'spar_get_wc_cart' ) ? spar_get_wc_cart() : null );
                if ( !$cart_object && function_exists( 'WC' ) ) {
                    $woocommerce = WC();
                    $cart_object = ( $woocommerce && !empty( $woocommerce->cart ) ? $woocommerce->cart : null );
                }
                if ( function_exists( 'sparp_cr_calculate_conditionally_adjusted_points__premium_only' ) && $cart_object ) {
                    $calc = sparp_cr_calculate_conditionally_adjusted_points__premium_only(
                        $base_points,
                        $fixed_points,
                        $level_multiplier,
                        $current_user_id,
                        'cart',
                        $cart_object
                    );
                    if ( is_array( $calc ) && isset( $calc['adjusted'] ) ) {
                        $points_earned = max( 0, (int) $calc['adjusted'] );
                    }
                }
                // Cap ONLY the spend-based points (after multiplier) to the total-order
                // limits. Fixed tier points and bonuses are left untouched.
                if ( function_exists( 'spar_apply_order_spend_only_cap' ) ) {
                    $points_earned = spar_apply_order_spend_only_cap(
                        $points_earned,
                        (int) $base_points,
                        (float) $level_multiplier,
                        (float) $cart_total,
                        $currency
                    );
                }
                $options = get_option( 'spar_options', spar_settings_default() );
                $points_label = $options['points_label'] ?? esc_html__( 'Points', 'simple-points-and-rewards' );
                // Check if linking is enabled and get rewards URL for JavaScript
                $link_to_rewards = !empty( $options['link_to_rewards'] );
                $rewards_url = ( $link_to_rewards ? wc_get_page_permalink( 'myaccount' ) . 'rewards/' : '' );
                // Localize script with data
                wp_localize_script( 'spar-block-support', 'sparDisplay', [
                    'pointsPerPound' => $rate,
                    'pointsEarned'   => $points_earned,
                    'pointsLabel'    => esc_js( spar_get_points_label_for_count( $points_earned, $points_label, 'checkout_block_earning' ) ),
                    'message'        => wp_kses_decode_entities( esc_html__( 'You will earn %1$d %2$s from this order', 'simple-points-and-rewards' ) ),
                    'showOnCheckout' => $show_on_checkout,
                    'linkToRewards'  => $link_to_rewards,
                    'rewardsUrl'     => esc_url( $rewards_url ),
                ] );
            }
        }
    }
}

// Remove old hooks that are no longer needed
remove_action( 'wp_footer', 'spar_add_block_script' );
remove_action( 'woocommerce_cart_totals_after_order_total', 'spar_display_cart_points' );
remove_action( 'woocommerce_cart_totals_before_shipping', 'spar_display_cart_points_before_shipping' );
remove_action( 'woocommerce_cart_collaterals', 'spar_display_cart_points_collaterals' );
remove_action( 'woocommerce_review_order_after_order_total', 'spar_display_checkout_points' );
remove_action( 'woocommerce_checkout_order_review', 'spar_display_checkout_points_review' );
remove_action( 'woocommerce_blocks_enqueue_checkout_block_scripts_after', 'spar_enqueue_block_checkout_script' );
remove_action( 'woocommerce_store_api_checkout_update_order_from_request', 'spar_add_block_checkout_notice' );