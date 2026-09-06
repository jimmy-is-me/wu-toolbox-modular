<?php
/**
 * FunnelKit Side Cart Integration
 */
if ( ! defined( 'ABSPATH' ) ) {
	return;
}

if ( ! function_exists( 'spar_enqueue_funnelkit_side_cart_assets' ) ) {
	/**
	 * Enqueue assets and inject the points discount panel in FunnelKit side cart.
	 */
	function spar_enqueue_funnelkit_side_cart_assets() {

		if ( ! is_user_logged_in() && ! spar_get_option( '', 'show_for_guests' ) ) {
			return;
		}

		// Banned users should not see rewards.
		if ( is_user_logged_in() ) {
			$spar_status = get_user_meta( get_current_user_id(), 'spar_user_status', true );
			if ( 'banned' === $spar_status ) {
				return;
			}
		}

		$options = get_option( 'spar_options', [] );
		$redeem_enabled = ! empty( $options['redeem_individual_enabled'] );
		$redeem_display = isset( $options['redeem_individual_display'] ) ? sanitize_key( $options['redeem_individual_display'] ) : 'totals';
		$redeem_display = in_array( $redeem_display, [ 'totals', 'box', 'both' ], true ) ? $redeem_display : 'totals';

		if ( ! $redeem_enabled || ! is_user_logged_in() || ! in_array( $redeem_display, [ 'box', 'both' ], true ) ) {
			return;
		}

		sparp_maybe_load_cart_for_rewards();

		// Register then enqueue styles/scripts per WP guidelines
		if ( ! wp_style_is( 'spar-cart-checkout-rewards', 'registered' ) ) {
			wp_register_style( 'spar-cart-checkout-rewards', SPAR_PLUGIN_URL . 'assets/css/cart-checkout-rewards.css', [], SPAR_VERSION );
		}
		wp_enqueue_style( 'spar-cart-checkout-rewards' );

		if ( ! wp_script_is( 'spar-cart-checkout-rewards', 'registered' ) ) {
			wp_register_script( 'spar-cart-checkout-rewards', SPAR_PLUGIN_URL . 'assets/js/cart-checkout-rewards.js', [ 'jquery' ], SPAR_VERSION, true );
		}
		wp_enqueue_script( 'spar-cart-checkout-rewards' );

		if ( ! wp_script_is( 'spar-funnelkit-side-cart', 'registered' ) ) {
			wp_register_script( 'spar-funnelkit-side-cart', SPAR_PLUGIN_URL . 'includes/integrations/funnelkit/funnelkit-side-cart.js', [ 'jquery', 'spar-cart-checkout-rewards' ], SPAR_VERSION, true );
		}
		wp_enqueue_script( 'spar-funnelkit-side-cart' );

		$user_id = get_current_user_id();
		$user_points = is_user_logged_in() ? spar_get_user_points( $user_id ) : 0;
		$points_label = spar_get_option( 'general', 'points_label' ) ?: esc_html__( 'Points', 'simple-points-and-rewards' );

		$checkout_redeem_button_text = isset( $options['checkout_box_redeem_button_text'] ) ? sanitize_text_field( $options['checkout_box_redeem_button_text'] ) : '';
		$checkout_redeem_button_label = $checkout_redeem_button_text !== ''
			? $checkout_redeem_button_text
			: esc_html__( 'Redeem Rewards', 'simple-points-and-rewards' );

		$default_redeem_button_label = esc_html__( 'Add Discount to Cart', 'simple-points-and-rewards' );
		$redeem_button_text_option = isset( $options['redeem_button_text'] ) ? $options['redeem_button_text'] : '';
		$redeem_button_label = '' !== $redeem_button_text_option ? wp_kses_post( $redeem_button_text_option ) : $default_redeem_button_label;

		$redeem_ppp = (float) ( $options['redeem_points_per_points'] ?? 100 );
		$redeem_ppa = (float) ( $options['redeem_points_per_amount'] ?? 1 );
		$redeem_rates = (array) ( $options['redeem_currency_rates'] ?? [] );
		$redeem_points_min = isset( $options['redeem_points_min'] ) ? max( 0, (int) $options['redeem_points_min'] ) : 0;
		$redeem_points_max = isset( $options['redeem_points_max'] ) ? max( 0, (int) $options['redeem_points_max'] ) : 0;
		$store_currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : ( defined( 'WC_VERSION' ) ? get_option( 'woocommerce_currency' ) : 'USD' );
		$price_decimals     = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;
		$price_decimal_sep  = function_exists( 'wc_get_price_decimal_separator' ) ? wc_get_price_decimal_separator() : '.';
		$price_thousand_sep = function_exists( 'wc_get_price_thousand_separator' ) ? wc_get_price_thousand_separator() : ',';
		$current_redemption = [];
		$session = function_exists( 'spar_get_wc_session' ) ? spar_get_wc_session() : null;
		if ( $session ) {
			$current_redemption = (array) $session->get( 'spar_points_redemption', [] );
		}

		$points_max = spar_get_cart_redeem_points_max( $user_points, $store_currency, ( function_exists( 'spar_fs' ) && spar_fs()->can_use_premium_code__premium_only() ) ? $redeem_points_max : 0 );
		$min_points_attr = ( function_exists( 'spar_fs' ) && spar_fs()->can_use_premium_code__premium_only() && $redeem_points_min > 0 ) ? (int) $redeem_points_min : 0;
		if ( $points_max > 0 && $min_points_attr > $points_max ) {
			$min_points_attr = $points_max;
		}

		$side_cart_html = spar_get_checkout_points_discount_panel_html( $user_id, $options, $points_label, $redeem_button_label, 'compact' );
		$applied_points = isset( $current_redemption['points'] ) ? (int) $current_redemption['points'] : 0;
		if ( $applied_points > 0 && ! empty( $side_cart_html ) ) {
			$side_cart_note = esc_html__( 'Points discount will be applied at checkout.', 'simple-points-and-rewards' );
			$side_cart_html = preg_replace(
				'/(<div class="spar-redeem-applied-status">.*?<\/div>)/s',
				'$1<div class="spar-side-cart-note">' . $side_cart_note . '</div>',
				$side_cart_html,
				1
			);
		}
		$side_cart_selectors = apply_filters(
			'spar_side_cart_selectors',
			[ '.fkcart-panel', '.fkcart-drawer', '.fkcart-cart', '.fkcart-mini-cart', '.wfacp-side-cart', '.wfacp-floating-cart', '.wffn-side-cart', '.wffn-cart', '.wfob-side-cart', '.wfob_cart' ]
		);
		$side_cart_target_selectors = apply_filters(
			'spar_side_cart_target_selectors',
			[ '.fkcart_summary_cta', '.fkcart-summary', '.fkcart-totals', '.fkcart-footer', '.wfacp-side-cart__summary', '.wfacp-order-summary', '.wffn-cart-footer', '.wfob_cart_summary' ]
		);

		$spar_cart_rewards_data = [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'spar_cart_rewards' ),
			'redeemNonce' => wp_create_nonce( 'spar_redeem_reward' ),
			'pointsRedeemNonce' => wp_create_nonce( 'spar_points_redeem' ),
			'applyVoucherNonce' => wp_create_nonce( 'spar_apply_voucher' ),
			'priceDecimals' => $price_decimals,
			'priceDecimalSeparator' => $price_decimal_sep,
			'priceThousandSeparator' => $price_thousand_sep,
			'userPoints' => $user_points,
			'pointsLabel' => $points_label,
			'pointsPrefix' => spar_get_points_prefix(),
			'pointsIconProminent' => spar_get_points_icon_html( 'prominent' ),
			'checkoutUrl' => function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : '',
			'isBlockCheckout' => false,
			'isCart' => false,
			'isOrderReceived' => false,
			'redeem' => [
				'enabled' => $redeem_enabled,
				'base' => [ 'points' => $redeem_ppp, 'amount' => $redeem_ppa, 'currency' => $store_currency ],
				'rates' => $redeem_rates,
				'current' => $current_redemption,
				'limits' => [
					'min' => $min_points_attr,
					'max' => (int) $points_max,
				],
			],
			'redeemDisplay' => $redeem_display,
			'strings' => [
				'loadingRewards' => esc_html__( 'Loading rewards...', 'simple-points-and-rewards' ),
				'redeemSuccess' => esc_html__( 'Reward redeemed successfully!', 'simple-points-and-rewards' ),
				'applySuccess' => esc_html__( 'Voucher applied successfully!', 'simple-points-and-rewards' ),
				'error' => esc_html__( 'Something went wrong. Please try again.', 'simple-points-and-rewards' ),
				'redeemPoints' => esc_html__( 'Redeem', 'simple-points-and-rewards' ),
				'add' => $redeem_button_label,
				'remove' => esc_html__( 'Remove', 'simple-points-and-rewards' ),
				'value' => esc_html__( 'Discount Value', 'simple-points-and-rewards' ),
				'pointsRedemptionPrefix' => esc_html__( 'Points Redemption', 'simple-points-and-rewards' ),
				'redeemRewards' => $checkout_redeem_button_label,
				'close' => esc_html__( 'Close', 'simple-points-and-rewards' ),
			],
		];

		wp_add_inline_script(
			'spar-funnelkit-side-cart',
			'window.sparCartRewards = window.sparCartRewards || ' . wp_json_encode( $spar_cart_rewards_data ) . ';',
			'before'
		);

		wp_localize_script( 'spar-funnelkit-side-cart', 'sparFunnelKitSideCart', [
			'enabled' => (bool) $redeem_enabled,
			'html' => $side_cart_html,
			'selectors' => $side_cart_selectors,
			'targetSelectors' => $side_cart_target_selectors,
			'position' => 'before',
			'requireTarget' => true,
		] );
	}
	add_action( 'wp_enqueue_scripts', 'spar_enqueue_funnelkit_side_cart_assets', 25 );
}
