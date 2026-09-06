<?php
/**
 * Settings sanitization helpers.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function spar_sanitize_currency_code( $currency_code ) {
	$currency_code = strtoupper( sanitize_text_field( (string) $currency_code ) );
	$currency_code = preg_replace( '/[^A-Z]/', '', $currency_code );

	return 3 === strlen( $currency_code ) ? $currency_code : '';
}

function spar_sanitize_currency_rates_rows( $rates ) {
	$sanitized_rates = [];

	if ( ! is_array( $rates ) ) {
		return $sanitized_rates;
	}

	foreach ( $rates as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}

		$currency = spar_sanitize_currency_code( $row['currency'] ?? '' );
		$points   = isset( $row['points'] ) ? max( 0.0, (float) $row['points'] ) : 0.0;
		$amount   = isset( $row['amount'] ) ? max( 0.0, (float) $row['amount'] ) : 0.0;

		if ( '' !== $currency && $points > 0 && $amount > 0 ) {
			$sanitized_rates[] = [
				'currency' => $currency,
				'points'   => $points,
				'amount'   => $amount,
			];
		}
	}

	return $sanitized_rates;
}

function spar_sanitize_order_fixed_tiers( $tiers ) {
	$sanitized_tiers = [];

	if ( ! is_array( $tiers ) ) {
		return $sanitized_tiers;
	}

	foreach ( $tiers as $tier ) {
		if ( ! is_array( $tier ) ) {
			continue;
		}

		$points            = isset( $tier['points'] ) ? absint( $tier['points'] ) : 0;
		$default_threshold = isset( $tier['default_threshold'] ) ? max( 0.0, (float) $tier['default_threshold'] ) : 0.0;
		$currencies        = [];

		if ( isset( $tier['currencies'] ) && is_array( $tier['currencies'] ) ) {
			foreach ( $tier['currencies'] as $currency_row ) {
				if ( ! is_array( $currency_row ) ) {
					continue;
				}

				$currency  = spar_sanitize_currency_code( $currency_row['currency'] ?? '' );
				$threshold = isset( $currency_row['threshold'] ) ? max( 0.0, (float) $currency_row['threshold'] ) : 0.0;

				if ( '' !== $currency && $threshold > 0 ) {
					$currencies[] = [
						'currency'  => $currency,
						'threshold' => $threshold,
					];
				}
			}
		}

		if ( $points > 0 && $default_threshold > 0 ) {
			$sanitized_tiers[] = [
				'points'            => $points,
				'default_threshold' => $default_threshold,
				'currencies'        => $currencies,
			];
		}
	}

	return $sanitized_tiers;
}

function spar_sanitize_spin_wheel_prizes( $prizes ) {
	$sanitized_prizes   = [];
	$allowed_prize_types = [ 'points', 'voucher', 'product' ];

	if ( ! is_array( $prizes ) ) {
		return $sanitized_prizes;
	}

	$prize_index = 0;
	foreach ( $prizes as $prize ) {
		if ( ! is_array( $prize ) || count( $sanitized_prizes ) >= 10 ) {
			continue;
		}

		$type = isset( $prize['type'] ) ? sanitize_key( $prize['type'] ) : 'points';
		$type = in_array( $type, $allowed_prize_types, true ) ? $type : 'points';

		$probability = isset( $prize['probability'] ) ? min( 100.0, max( 0.0, (float) $prize['probability'] ) ) : 0.0;
		if ( $probability <= 0 ) {
			continue;
		}

		$color = isset( $prize['color'] ) ? sanitize_hex_color( $prize['color'] ) : '';
		if ( empty( $color ) && function_exists( 'spar_get_spin_wheel_default_color' ) ) {
			$color = spar_get_spin_wheel_default_color( $prize_index );
		}

		$sanitized_prizes[] = [
			'type'        => $type,
			'value'       => isset( $prize['value'] ) ? max( 0.0, (float) $prize['value'] ) : 0.0,
			'probability' => $probability,
			'label'       => isset( $prize['label'] ) ? sanitize_text_field( $prize['label'] ) : '',
			'product_id'  => isset( $prize['product_id'] ) ? absint( $prize['product_id'] ) : 0,
			'color'       => $color,
		];

		$prize_index++;
	}

	return $sanitized_prizes;
}

function spar_sanitize_order_keys( $value, $allowed_keys, $append_missing = false ) {
	$sanitized_order = [];

	if ( is_array( $value ) ) {
		foreach ( $value as $item ) {
			$item = sanitize_key( $item );
			if ( in_array( $item, $allowed_keys, true ) && ! in_array( $item, $sanitized_order, true ) ) {
				$sanitized_order[] = $item;
			}
		}
	}

	if ( $append_missing ) {
		foreach ( $allowed_keys as $allowed_key ) {
			if ( ! in_array( $allowed_key, $sanitized_order, true ) ) {
				$sanitized_order[] = $allowed_key;
			}
		}
	}

	return $sanitized_order;
}

function spar_sanitize_dashboard_tab_labels( $labels, $defaults = [] ) {
	$allowed_tabs      = [ 'earn', 'claim', 'vouchers', 'levels', 'history', 'settings' ];
	$sanitized_labels = is_array( $defaults ) ? $defaults : [];

	if ( ! is_array( $labels ) ) {
		return $sanitized_labels;
	}

	foreach ( $labels as $tab_key => $label ) {
		$tab_key = sanitize_key( $tab_key );
		if ( ! in_array( $tab_key, $allowed_tabs, true ) ) {
			continue;
		}

		$label = sanitize_text_field( $label );
		if ( '' !== $label ) {
			$sanitized_labels[ $tab_key ] = $label;
		}
	}

	return $sanitized_labels;
}

function spar_sanitize_points_delay_settings( $value, $default = [] ) {
	$fallback = [
		'enabled' => false,
		'global'  => [
			'value' => 0,
			'unit'  => 'days',
		],
		'methods' => [],
	];

	$default = is_array( $default ) ? wp_parse_args( $default, $fallback ) : $fallback;
	if ( ! is_array( $value ) ) {
		return $default;
	}

	$allowed_units = [ 'hours', 'days' ];
	$allowed_methods = [ 'order', 'signup', 'first_order', 'nth_order', 'referral', 'review', 'birthday', 'daily_login', 'daily_login_streak', 'social_share', 'spin_wheel' ];
	if ( function_exists( 'spar_points_delay_get_delayable_methods' ) ) {
		$allowed_methods = array_keys( spar_points_delay_get_delayable_methods() );
	}

	$global_unit = isset( $value['global']['unit'] ) ? sanitize_key( $value['global']['unit'] ) : 'days';
	if ( ! in_array( $global_unit, $allowed_units, true ) ) {
		$global_unit = 'days';
	}

	$sanitized = [
		'enabled' => ! empty( $value['enabled'] ),
		'global'  => [
			'value' => isset( $value['global']['value'] ) ? absint( $value['global']['value'] ) : 0,
			'unit'  => $global_unit,
		],
		'methods' => [],
	];

	if ( isset( $value['methods'] ) && is_array( $value['methods'] ) ) {
		foreach ( $value['methods'] as $method_key => $method_settings ) {
			$method_key = sanitize_key( $method_key );
			if ( ! in_array( $method_key, $allowed_methods, true ) || ! is_array( $method_settings ) ) {
				continue;
			}

			$unit = isset( $method_settings['unit'] ) ? sanitize_key( $method_settings['unit'] ) : 'days';
			if ( ! in_array( $unit, $allowed_units, true ) ) {
				$unit = 'days';
			}

			$sanitized['methods'][ $method_key ] = [
				'no_delay' => ! empty( $method_settings['no_delay'] ),
				'value'    => isset( $method_settings['value'] ) ? absint( $method_settings['value'] ) : 0,
				'unit'     => $unit,
			];
		}
	}

	return $sanitized;
}

function spar_sanitize_text_array_recursive( $value, $depth = 0 ) {
	$sanitized = [];

	if ( ! is_array( $value ) || $depth > 5 ) {
		return $sanitized;
	}

	foreach ( $value as $key => $item ) {
		$key = is_int( $key ) ? $key : sanitize_key( $key );
		if ( is_array( $item ) ) {
			$sanitized[ $key ] = spar_sanitize_text_array_recursive( $item, $depth + 1 );
		} else {
			$sanitized[ $key ] = sanitize_text_field( $item );
		}
	}

	return $sanitized;
}

function spar_sanitize_settings_array_field( $value, $field_key, $default = [] ) {
	switch ( $field_key ) {
		case 'order_currency_rates':
		case 'redeem_currency_rates':
			return spar_sanitize_currency_rates_rows( $value );
		case 'redeem_exclude_product_ids':
		case 'redeem_exclude_category_ids':
			if ( ! is_array( $value ) ) {
				return [];
			}
			return array_values( array_unique( array_filter( array_map( 'absint', $value ) ) ) );
		case 'order_fixed_tiers':
			return spar_sanitize_order_fixed_tiers( $value );
		case 'spin_wheel_prizes':
			return spar_sanitize_spin_wheel_prizes( $value );
		case 'earn_ways_order':
			$earn_ways_allowed = [ 'signup', 'first_order', 'nth_order', 'order_fixed', 'order', 'review', 'birthday', 'daily_login', 'referral', 'spin_wheel', 'buy_products' ];
			if ( function_exists( 'sparp_cwe_get_way_keys__premium_only' ) ) {
				$earn_ways_allowed = array_merge( $earn_ways_allowed, sparp_cwe_get_way_keys__premium_only() );
			}
			return spar_sanitize_order_keys( $value, $earn_ways_allowed, false );
		case 'dashboard_tabs_order':
			return spar_sanitize_order_keys( $value, [ 'earn', 'claim', 'vouchers', 'levels', 'history', 'settings' ], false );
		case 'dashboard_tab_labels':
			return spar_sanitize_dashboard_tab_labels( $value, $default );
		case 'points_delay':
			return spar_sanitize_points_delay_settings( $value, $default );
		default:
			return is_array( $value ) ? spar_sanitize_text_array_recursive( $value ) : $default;
	}
}

function spar_sanitize_field_value( $value, $type, $default, $field_key = '' ) {
	// Basic coercion and sanitization by field type.
	// Anything not matched falls back to a safe text value.
	switch ( $type ) {
		case 'checkbox':
			return ! empty( $value );
		case 'number':
			return absint( $value );
		case 'float':
			$f = (float) $value;
			return is_finite( $f ) ? $f : (float) $default;
		case 'text':
			$value = wp_unslash( $value );
			$value = sanitize_text_field( $value );
			return '' !== $value ? $value : $default;
		case 'textarea':
			$value = wp_unslash( $value );
			$value = sanitize_textarea_field( $value );
			return '' !== $value ? $value : $default;
		case 'editor':
			$value = wp_unslash( $value );
			$value = wp_kses_post( $value );
			return '' !== $value ? $value : $default;
		case 'select':
			$value                 = sanitize_text_field( $value );
			$allowed_values_by_key = [
				// Fields whose saved value drives logic, hooks, or calculations.
				'daily_login_streak_type'            => [ 'one-time', 'recurring' ],
				'spin_wheel_popup_style'             => [ 'light', 'dark' ],
				'spin_wheel_cooldown'                => [ 'daily', 'hours', 'weekly', 'once' ],
				'order_calculation_total_mode'       => [ 'total_before_discounts', 'subtotal_after_discount' ],
				'order_award_timing'                 => [ 'thankyou', 'completed' ],
				'order_rounding_mode'                => [ 'floor', 'round', 'ceil' ],
				'order_fixed_calculation_total_mode' => [ 'total_before_discounts', 'subtotal_after_discount' ],
				'referral_earning_type'              => [ 'fixed', 'percentage' ],
				'referral_award_timing'              => [ 'completed', 'processing' ],
				'referral_offer_type'                => [ 'discount', 'free_shipping' ],
				'referral_gift_widget_position'      => [ 'bottom-left', 'bottom-right', 'bottom-center', 'top-left', 'top-right', 'center-left-vertical', 'center-right-vertical' ],
				'referral_gift_attribution_model'    => [ 'first_click', 'last_click' ],
				'referral_gift_usage_limit_per_user' => [ 'email', 'user', 'unlimited' ],
				'referral_coupon_tracking_mode'      => [ 'cookie_required', 'cookie_tracked', 'always_track' ],
				'social_sharing_social_limit_mode'   => [ 'per_social', 'global_once' ],
				'first_order_award_timing'           => [ 'thankyou', 'completed' ],
				'rewards_box_theme'                  => [ 'default', 'medium', 'compact' ],
				'checkout_box_theme_style'           => [ 'light', 'dark' ],
				'redeem_individual_display'          => [ 'both', 'totals', 'box' ],
				'redeem_individual_page'             => [ 'both', 'cart', 'checkout' ],
				'redeem_discount_fee_tax_class_mode' => [ 'default', 'custom' ],
				'rewards_widget_position'            => [ 'bottom-left', 'bottom-right', 'top-left', 'top-right', 'center-left-vertical', 'center-right-vertical' ],
				'rewards_widget_size'                => [ 'regular', 'large', 'extra-large', 'full-height' ],
					'points_icon_display'                => [ 'prominent', 'all' ],
				'subscriptions_renewal_rate_mode'    => [ 'same', 'percentage', 'fixed' ],
				'subscriptions_renewal_award_timing' => [ 'follow_order', 'payment_complete', 'completed' ],
			];

			if ( 'order_completed_status' === $field_key ) {
				$statuses = [ 'completed', 'processing', 'on-hold', 'pending' ];
				if ( function_exists( 'wc_get_order_statuses' ) ) {
					$statuses = array_merge(
						$statuses,
						array_map(
							function( $status_key ) {
								return preg_replace( '/^wc-/', '', sanitize_key( $status_key ) );
							},
							array_keys( wc_get_order_statuses() )
						)
					);
				}

				$allowed_values = array_values( array_unique( array_filter( $statuses ) ) );
			} elseif ( 'product_points_location' === $field_key && function_exists( 'spar_get_product_points_location_options' ) ) {
				$allowed_values = array_keys( spar_get_product_points_location_options() );
			} elseif ( 'product_loop_points_location' === $field_key && function_exists( 'spar_get_product_loop_points_location_options' ) ) {
				$allowed_values = array_keys( spar_get_product_loop_points_location_options() );
			} elseif ( 'redeem_discount_fee_tax_class' === $field_key && function_exists( 'spar_get_woocommerce_tax_class_options' ) ) {
				$allowed_values = array_keys( spar_get_woocommerce_tax_class_options() );
			} else {
				$allowed_values = $allowed_values_by_key[ $field_key ] ?? [];
			}

			if ( ! empty( $allowed_values ) ) {
				return in_array( $value, $allowed_values, true ) ? $value : $default;
			}

			return $default;
		case 'color':
			$color = sanitize_text_field( $value );
			if ( preg_match( '/^#[a-f0-9]{6}$/i', $color ) ) {
				return $color;
			}
			return $default;
		case 'array':
			return spar_sanitize_settings_array_field( $value, $field_key, $default );
		default:
			$value = sanitize_text_field( $value );
			return '' !== $value ? $value : $default;
	}
}