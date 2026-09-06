<?php
/**
 * Shortcode handlers and helpers
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detect if current request context has the Rewards shortcode on the page.
 * Used to conditionally enqueue frontend assets when showing Rewards outside My Account.
 */
function spar_page_has_rewards_shortcode() {
	// Frontend only and only for singular content
	if ( is_admin() ) {
		return false;
	}

	if ( is_singular() ) {
		$post = get_post();
		if ( $post && function_exists( 'has_shortcode' ) ) {
			// Check both new and legacy shortcode tags
			return (
				(
					function_exists( 'has_shortcode' ) &&
					(
						has_shortcode( $post->post_content, 'spar_points_rewards' )
					)
				)
			);
		}
	}

	return false;
}

/**
 * Shortcodes: User data helpers
 *
 * Supported display values for [simple_points_rewards]:
 * - points
 * - total_earned
 * - level_name
 * - level_id
 * - level_multiplier
 * - next_level_name
 * - next_level_points
 * - points_to_next_level
 * - level_progress_percent
 * - points_label
 * - referral_code
 * - referral_link
 * - referral_clicks
 * - referral_referrals
 * - referral_points_earned
 * - points_value
 */
function spar_get_shortcode_user_id( $atts = [] ) {
	$requested_user_id = isset( $atts['user_id'] ) ? absint( $atts['user_id'] ) : 0;
	$current_user_id   = get_current_user_id();

	if ( $requested_user_id && $requested_user_id !== $current_user_id ) {
		if ( current_user_can( 'manage_options' ) || current_user_can( 'list_users' ) ) {
			return $requested_user_id;
		}
	}

	return (int) $current_user_id;
}

function spar_get_shortcode_user_data( $user_id, $atts = [] ) {
	static $cache = [];
	$user_id = absint( $user_id );
	if ( $user_id <= 0 ) {
		return [];
	}

	if ( isset( $cache[ $user_id ] ) ) {
		return $cache[ $user_id ];
	}

	$points_label = spar_get_option( 'general', 'points_label' ) ?: esc_html__( 'Points', 'simple-points-and-rewards' );
	$total_earned = function_exists( 'spar_get_user_total_points_earned' ) ? spar_get_user_total_points_earned( $user_id ) : 0;
	$level        = function_exists( 'spar_get_user_level' ) ? spar_get_user_level( $user_id ) : null;
	$next_level   = function_exists( 'spar_get_user_next_level' ) ? spar_get_user_next_level( $user_id ) : null;
	$multiplier   = function_exists( 'spar_get_user_points_multiplier' ) ? spar_get_user_points_multiplier( $user_id ) : 1.0;
	$points       = spar_get_user_points( $user_id );
	$earned_total = (int) $total_earned;

	$points_to_next = 0;
	$progress_pct   = 0.0;
	$next_level_points = 0;
	if ( is_array( $next_level ) ) {
		$next_level_points = isset( $next_level['required_points'] ) ? (int) $next_level['required_points'] : 0;
		if ( $next_level_points > 0 ) {
			$points_to_next = max( 0, $next_level_points - (int) $earned_total );
			$progress_pct   = min( 100, max( 0, ( $earned_total / $next_level_points ) * 100 ) );
		}
	} else {
		$progress_pct = 100;
	}

	$referral_code = function_exists( 'spar_get_user_referral_code' ) ? spar_get_user_referral_code( $user_id ) : '';
	$referral_link = '';
	$base_url      = isset( $atts['base_url'] ) ? esc_url_raw( $atts['base_url'] ) : '';
	if ( function_exists( 'spar_generate_referral_url' ) ) {
		$referral_link = spar_generate_referral_url( $user_id, $base_url );
	}
	$referral_stats = function_exists( 'spar_get_user_referral_stats' ) ? spar_get_user_referral_stats( $user_id ) : [];

	$currency       = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '';
	$points_value   = function_exists( 'spar_calculate_redemption_amount' ) ? spar_calculate_redemption_amount( $points, $currency ) : 0.0;
	$currency_symbol = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol( $currency ) : '';

	$cache[ $user_id ] = [
		'points' => (int) $points,
		'total_earned' => (int) $earned_total,
		'level' => $level,
		'next_level' => $next_level,
		'points_to_next_level' => (int) $points_to_next,
		'level_progress_percent' => (float) $progress_pct,
		'level_multiplier' => (float) $multiplier,
		'points_label' => (string) $points_label,
		'referral_code' => (string) $referral_code,
		'referral_link' => (string) $referral_link,
		'referral_stats' => is_array( $referral_stats ) ? $referral_stats : [],
		'points_value' => (float) $points_value,
		'currency_symbol' => (string) $currency_symbol,
		'next_level_points' => (int) $next_level_points,
	];

	return $cache[ $user_id ];
}

function spar_format_shortcode_value( $value, $atts = [], $type = 'text', $currency_symbol = '' ) {
	$format   = isset( $atts['format'] ) ? sanitize_key( $atts['format'] ) : '';
	$decimals = isset( $atts['decimals'] ) ? (int) $atts['decimals'] : 0;

	if ( 'raw' === $format ) {
		return is_scalar( $value ) ? (string) $value : '';
	}

	switch ( $type ) {
		case 'currency':
			$decimals = max( 0, $decimals );
			if ( function_exists( 'wc_price' ) && '' === $format ) {
				return wp_kses_post( wc_price( (float) $value ) );
			}
			$currency_symbol = $currency_symbol ? $currency_symbol : '';
			return esc_html( $currency_symbol . number_format_i18n( (float) $value, $decimals ) );
		case 'percent':
			$decimals = max( 0, $decimals );
			return esc_html( number_format_i18n( (float) $value, $decimals ) . '%' );
		case 'points':
			$decimals = max( 0, $decimals );
			return spar_format_points_display( (float) $value, 'inline', $decimals );
		case 'number':
			$decimals = max( 0, $decimals );
			return esc_html( number_format_i18n( (float) $value, $decimals ) );
		case 'text':
		default:
			return esc_html( (string) $value );
	}
}

function spar_shortcode_user_data_handler( $atts = [] ) {
	$atts = shortcode_atts(
		[
			'display' => 'points',
			'user_id' => 0,
			'format' => '',
			'decimals' => '',
			'before' => '',
			'after' => '',
			'empty' => '',
			'base_url' => '',
		],
		$atts,
		'simple_points_rewards'
	);

	$user_id = spar_get_shortcode_user_id( $atts );
	if ( $user_id <= 0 ) {
		return wp_kses_post( $atts['empty'] );
	}

	$data = spar_get_shortcode_user_data( $user_id, $atts );
	if ( empty( $data ) ) {
		return wp_kses_post( $atts['empty'] );
	}

	$display = sanitize_key( $atts['display'] );
	$value   = '';
	$type    = 'text';

	if ( 'points' === $display ) {
		$value = $data['points'] ?? 0;
		$type  = 'points';
	} elseif ( 'total_earned' === $display ) {
		$value = $data['total_earned'] ?? 0;
		$type  = 'points';
	} elseif ( 'level_name' === $display ) {
		$value = is_array( $data['level'] ) ? ( $data['level']['name'] ?? '' ) : '';
		$type  = 'text';
	} elseif ( 'level_id' === $display ) {
		$value = is_array( $data['level'] ) ? ( $data['level']['id'] ?? '' ) : '';
		$type  = 'text';
	} elseif ( 'level_multiplier' === $display ) {
		$value = $data['level_multiplier'] ?? 1.0;
		$type  = 'number';
	} elseif ( 'next_level_name' === $display ) {
		$value = is_array( $data['next_level'] ) ? ( $data['next_level']['name'] ?? '' ) : '';
		$type  = 'text';
	} elseif ( 'next_level_points' === $display ) {
		$value = $data['next_level_points'] ?? 0;
		$type  = 'points';
	} elseif ( 'points_to_next_level' === $display ) {
		$value = $data['points_to_next_level'] ?? 0;
		$type  = 'points';
	} elseif ( 'level_progress_percent' === $display ) {
		$value = $data['level_progress_percent'] ?? 0.0;
		$type  = 'percent';
	} elseif ( 'points_label' === $display ) {
		$value = $data['points_label'] ?? '';
		$type  = 'text';
	} elseif ( 'referral_code' === $display ) {
		$value = $data['referral_code'] ?? '';
		$type  = 'text';
	} elseif ( 'referral_link' === $display ) {
		$value = $data['referral_link'] ?? '';
		$type  = 'text';
	} elseif ( 'referral_clicks' === $display ) {
		$value = isset( $data['referral_stats']['total_clicks'] ) ? (int) $data['referral_stats']['total_clicks'] : 0;
		$type  = 'number';
	} elseif ( 'referral_referrals' === $display ) {
		$value = isset( $data['referral_stats']['successful_referrals'] ) ? (int) $data['referral_stats']['successful_referrals'] : 0;
		$type  = 'number';
	} elseif ( 'referral_points_earned' === $display ) {
		$value = isset( $data['referral_stats']['total_points_earned'] ) ? (int) $data['referral_stats']['total_points_earned'] : 0;
		$type  = 'points';
	} elseif ( 'points_value' === $display ) {
		$value = $data['points_value'] ?? 0.0;
		$type  = 'currency';
	}

	if ( '' === $value || null === $value ) {
		return wp_kses_post( $atts['empty'] );
	}

	$formatted = spar_format_shortcode_value( $value, $atts, $type, $data['currency_symbol'] ?? '' );
	$before = $atts['before'] ? wp_kses_post( $atts['before'] ) : '';
	$after  = $atts['after'] ? wp_kses_post( $atts['after'] ) : '';

	return $before . $formatted . $after;
}

add_shortcode( 'simple_points_rewards', 'spar_shortcode_user_data_handler' );

/**
 * Ensure rewards dashboard assets are available when rendered via shortcode.
 *
 * Some page builders and dynamic templates render shortcodes after `wp_head`,
 * which means the normal frontend enqueue pass may miss the page context or
 * enqueue styles too late to be printed automatically.
 */
function spar_enqueue_rewards_shortcode_assets() {
	static $assets_localized = false;

	$options = get_option( 'spar_options', array() );

	if ( ! wp_style_is( 'spar-styles', 'registered' ) ) {
		wp_register_style( 'spar-styles', SPAR_PLUGIN_URL . 'assets/css/reward-points.css', array(), SPAR_VERSION );
	}

	if ( ! wp_style_is( 'spar-font-awesome', 'registered' ) ) {
		wp_register_style( 'spar-font-awesome', SPAR_PLUGIN_URL . 'assets/fonts/font-awesome/css/all.min.css', array(), '6.6.0' );
	}

	if ( ! wp_style_is( 'spar-account-rewards', 'registered' ) ) {
		wp_register_style( 'spar-account-rewards', SPAR_PLUGIN_URL . 'assets/css/account-rewards.css', array( 'spar-styles' ), SPAR_VERSION );
	}

	if ( ! wp_style_is( 'spar-dashboard-dark-mode', 'registered' ) ) {
		wp_register_style( 'spar-dashboard-dark-mode', SPAR_PLUGIN_URL . 'assets/css/dashboard-dark-mode.css', array( 'spar-account-rewards' ), SPAR_VERSION );
	}

	if ( ! wp_style_is( 'spar-referral-system-css', 'registered' ) ) {
		wp_register_style( 'spar-referral-system-css', SPAR_PLUGIN_URL . 'assets/css/referral-system.css', array( 'spar-styles', 'spar-font-awesome' ), SPAR_VERSION );
	}

	if ( ! wp_script_is( 'spar-referral-system', 'registered' ) ) {
		wp_register_script( 'spar-referral-system', SPAR_PLUGIN_URL . 'assets/js/referral-system.js', array( 'jquery' ), SPAR_VERSION, true );
	}

	if ( ! wp_script_is( 'spar-rewards-tabs', 'registered' ) ) {
		wp_register_script( 'spar-rewards-tabs', SPAR_PLUGIN_URL . 'assets/js/rewards-tabs.js', array(), SPAR_VERSION, true );
	}

	if ( ! $assets_localized ) {
		$theme_color_1_raw      = isset( $options['rewards_theme_color_1'] ) && is_string( $options['rewards_theme_color_1'] ) && '' !== $options['rewards_theme_color_1'] ? $options['rewards_theme_color_1'] : '#667eea';
		$theme_color_2_raw      = isset( $options['rewards_theme_color_2'] ) && is_string( $options['rewards_theme_color_2'] ) && '' !== $options['rewards_theme_color_2'] ? $options['rewards_theme_color_2'] : '#764ba2';
		$theme_accent_color_raw = isset( $options['rewards_theme_accent_color'] ) && is_string( $options['rewards_theme_accent_color'] ) && '' !== $options['rewards_theme_accent_color'] ? $options['rewards_theme_accent_color'] : '#2ca58d';
		$theme_color_1          = sanitize_hex_color( $theme_color_1_raw ) ?: '#667eea';
		$theme_color_2          = sanitize_hex_color( $theme_color_2_raw ) ?: '#764ba2';
		$theme_accent_color     = sanitize_hex_color( $theme_accent_color_raw ) ?: '#2ca58d';
		$vars_css               = ':root{--spar-theme-color-1:' . $theme_color_1 . ';--spar-theme-color-2:' . $theme_color_2 . ';--spar-theme-accent-color:' . $theme_accent_color . ';}';
		wp_add_inline_style( 'spar-styles', $vars_css );

		$referral_options       = isset( $options['earn']['referral'] ) && is_array( $options['earn']['referral'] ) ? $options['earn']['referral'] : array();
		$social_sharing         = isset( $options['earn']['social_sharing'] ) && is_array( $options['earn']['social_sharing'] ) ? $options['earn']['social_sharing'] : array();
		$tracking_mode          = isset( $referral_options['coupon_tracking_mode'] ) ? $referral_options['coupon_tracking_mode'] : 'always_track';
		$offer_enabled          = ! empty( $referral_options['offer_enabled'] );
		$referral_offer_enabled = $offer_enabled && in_array( $tracking_mode, array( 'always_track', 'flexible' ), true );
		$social_points_enabled  = ! empty( $social_sharing['referral_social_points_enabled'] );
		$social_points          = isset( $social_sharing['social_points'] ) ? (int) $social_sharing['social_points'] : 10;
		$social_limit_mode      = isset( $social_sharing['social_limit_mode'] ) ? (string) $social_sharing['social_limit_mode'] : 'per_social';

		wp_localize_script(
			'spar-referral-system',
			'sparReferralSystem',
			array(
				'strings' => array(
					'copy'      => esc_html__( 'Copy', 'simple-points-and-rewards' ),
					'copied'    => esc_html__( 'Copied!', 'simple-points-and-rewards' ),
					'copyError' => esc_html__( 'Copy Failed', 'simple-points-and-rewards' ),
				),
				'ajaxurl'              => admin_url( 'admin-ajax.php' ),
				'referralNonce'        => wp_create_nonce( 'spar_referral_nonce' ),
				'referralOfferEnabled' => (bool) $referral_offer_enabled,
				'socialShare'          => array(
					'enabled'   => (bool) $social_points_enabled,
					'points'    => $social_points,
					'limitMode' => $social_limit_mode,
					'nonce'     => wp_create_nonce( 'spar_social_share_nonce' ),
				),
			)
		);

		$dashboard_dark_mode_toggle                   = ! empty( $options['dashboard_dark_mode_toggle'] );
		$dashboard_dark_mode_default                  = ! empty( $options['dashboard_dark_mode_default'] );
		$dashboard_dark_mode_header                   = ! empty( $options['dashboard_dark_mode_header'] );
		$dashboard_dark_mode_hide_toggle_when_default = ! empty( $options['dashboard_dark_mode_hide_toggle_when_default'] );

		wp_localize_script(
			'spar-rewards-tabs',
			'sparDashboard',
			array(
				'darkModeToggle'                => $dashboard_dark_mode_toggle,
				'darkModeDefault'               => $dashboard_dark_mode_default,
				'darkModeHeader'                => $dashboard_dark_mode_header,
				'darkModeHideToggleWhenDefault' => $dashboard_dark_mode_hide_toggle_when_default,
				'strings'                       => array(
					'darkMode'  => esc_html__( 'Enable dark mode', 'simple-points-and-rewards' ),
					'lightMode' => esc_html__( 'Enable light mode', 'simple-points-and-rewards' ),
				),
			)
		);

		$assets_localized = true;
	}

	wp_enqueue_style( 'spar-styles' );
	wp_enqueue_style( 'spar-font-awesome' );
	wp_enqueue_style( 'spar-account-rewards' );
	wp_enqueue_style( 'spar-dashboard-dark-mode' );
	wp_enqueue_style( 'spar-referral-system-css' );
	wp_enqueue_script( 'spar-referral-system' );
	wp_enqueue_script( 'spar-rewards-tabs' );

	if ( did_action( 'wp_head' ) && ! did_action( 'wp_footer' ) && function_exists( 'wp_print_styles' ) ) {
		wp_print_styles(
			array(
				'spar-styles',
				'spar-font-awesome',
				'spar-account-rewards',
				'spar-dashboard-dark-mode',
				'spar-referral-system-css',
			)
		);
	}
}

/**
 * Shortcodes: [spar_points_rewards]
 * Renders the same Rewards content as the My Account tab on any page.
 */
add_shortcode( 'spar_points_rewards', 'spar_rewards_shortcode_handler' );
function spar_rewards_shortcode_handler( $atts = [] ) {
	spar_enqueue_rewards_shortcode_assets();

	ob_start();
	spar_rewards_tab_content();
	return ob_get_clean();
}

// Shortcode to render compact redeem UI
add_shortcode( 'spar_redeem_discount_compact', 'spar_render_compact_redeem_totals_row_shortcode' );
function spar_render_compact_redeem_totals_row_shortcode() {
	global $spar_compact_redeem_shortcode_used;
	$spar_compact_redeem_shortcode_used = true;
	if ( function_exists( 'sparp_maybe_load_cart_for_rewards' ) ) {
		sparp_maybe_load_cart_for_rewards();
	}
	if ( function_exists( 'spar_enqueue_compact_redeem_assets' ) ) {
		spar_enqueue_compact_redeem_assets();
	}
	if ( function_exists( 'did_action' ) && did_action( 'wp_head' ) ) {
		if ( function_exists( 'wp_print_styles' ) ) {
			wp_print_styles( 'spar-cart-checkout-rewards' );
		}
	}
	ob_start();
	spar_render_compact_redeem_totals_row( 'shortcode' );
	return ob_get_clean();
}

/**
 * Shortcode: Render checkout rewards box on any page.
 * Usage: [spar_checkout_rewards_box]
 */
add_shortcode( 'spar_checkout_rewards_box', 'spar_render_checkout_rewards_box_shortcode' );
function spar_render_checkout_rewards_box_shortcode() {
	if ( function_exists( 'sparp_maybe_load_cart_for_rewards' ) ) {
		sparp_maybe_load_cart_for_rewards();
	}
	if ( function_exists( 'spar_enqueue_compact_redeem_assets' ) ) {
		spar_enqueue_compact_redeem_assets();
	}

	ob_start();
	spar_render_rewards_box( 'checkout' );
	return ob_get_clean();
}

/**
 * Shortcode: Render the non-compact redemption tool.
 * Usage: [spar_redeem_discount_tool]
 */
add_shortcode( 'spar_redeem_discount_tool', 'spar_render_redeem_discount_tool_shortcode' );
function spar_render_redeem_discount_tool_shortcode() {
	if ( function_exists( 'sparp_maybe_load_cart_for_rewards' ) ) {
		sparp_maybe_load_cart_for_rewards();
	}
	if ( function_exists( 'spar_enqueue_compact_redeem_assets' ) ) {
		spar_enqueue_compact_redeem_assets();
	}

	if ( ! is_user_logged_in() ) {
		return '';
	}
	$status = get_user_meta( get_current_user_id(), 'spar_user_status', true );
	if ( 'banned' === $status ) {
		return '';
	}

	$options = get_option( 'spar_options', [] );
	if ( empty( $options['redeem_individual_enabled'] ) ) {
		return '';
	}

	$points_label = spar_get_option( 'general', 'points_label' ) ?: esc_html__( 'Points', 'simple-points-and-rewards' );
	$default_redeem_button_label = esc_html__( 'Add Discount to Cart', 'simple-points-and-rewards' );
	$redeem_button_text_option   = isset( $options['redeem_button_text'] ) ? $options['redeem_button_text'] : '';
	$redeem_button_label         = '' !== $redeem_button_text_option ? wp_kses_post( $redeem_button_text_option ) : $default_redeem_button_label;

	$user_id = get_current_user_id();

	return spar_get_checkout_points_discount_panel_html( $user_id, $options, $points_label, $redeem_button_label, 'regular' );
}