<?php
/**
 * Core functions
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Null-safe accessor for the WooCommerce cart object.
 * Returns null when WC is unavailable or the cart has not been initialised yet
 * (e.g. on admin pages or early-AJAX requests), preventing fatal errors on
 * any code path that runs before WC bootstraps the cart.
 *
 * @return WC_Cart|null
 */
if ( ! function_exists( 'spar_get_wc_cart' ) ) {
	function spar_get_wc_cart() {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}
		$woocommerce = WC();
		if ( ! $woocommerce || empty( $woocommerce->cart ) ) {
			return null;
		}
		return $woocommerce->cart;
	}
}

/**
 * Null-safe accessor for the WooCommerce session object.
 * Returns null when WC is unavailable or the session has not been started yet.
 *
 * @return WC_Session|null
 */
if ( ! function_exists( 'spar_get_wc_session' ) ) {
	function spar_get_wc_session() {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}
		$woocommerce = WC();
		if ( ! $woocommerce || empty( $woocommerce->session ) ) {
			return null;
		}
		return $woocommerce->session;
	}
}

/**
 * Persist WooCommerce cart totals and session data after AJAX/redirect cart mutations.
 *
 * @param WC_Cart|null    $cart    Cart object.
 * @param WC_Session|null $session Session object.
 * @return void
 */
if ( ! function_exists( 'spar_save_wc_cart_session' ) ) {
	function spar_save_wc_cart_session( $cart = null, $session = null ) {
		if ( ! $cart ) {
			$cart = spar_get_wc_cart();
		}

		if ( ! $cart ) {
			return;
		}

		if ( method_exists( $cart, 'calculate_totals' ) ) {
			$cart->calculate_totals();
		}

		if ( method_exists( $cart, 'set_session' ) ) {
			$cart->set_session();
		}

		if ( ! $session ) {
			$session = spar_get_wc_session();
		}

		if ( $session && method_exists( $session, 'save_data' ) ) {
			$session->save_data();
		}
	}
}

/**
 * Check if a database table exists, with per-request static cache.
 *
 * Avoids running SHOW TABLES LIKE on every call. Once confirmed to exist
 * within a request it will not be checked again.
 *
 * @param string $table Full table name including prefix.
 * @return bool
 */
function spar_table_exists( $table ) {
	static $cache = [];
	if ( isset( $cache[ $table ] ) ) {
		return $cache[ $table ];
	}

	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );
	$cache[ $table ] = $exists;
	return $exists;
}

/**
 * Register account endpoint early in plugin load.
 */
// Always register endpoint; visibility handled by menu filter and template
add_action( 'init', 'spar_register_account_endpoint', 1 );
function spar_register_account_endpoint() {
	add_rewrite_endpoint( 'rewards', EP_PAGES );
}

/**
 * Get user points
 */
function spar_get_user_points( $user_id = 0 ) {
	$user_id = $user_id ? $user_id : get_current_user_id();
	$points = (int) get_user_meta( $user_id, '_spar_points', true );
	/**
	 * Filter the returned points balance for a user.
	 *
	 * Useful for virtual balances, preview modes, or external adjustments.
	 * Should only affect display/derived behavior; storage remains canonical.
	 *
	 * @param int $points  Current stored balance.
	 * @param int $user_id User ID.
	 */
	return (int) apply_filters( 'spar_user_points', $points, $user_id );
}

if ( ! function_exists( 'spar_get_configured_points_label' ) ) {
	/**
	 * Get the configured plural/display points label.
	 *
	 * @return string
	 */
	function spar_get_configured_points_label() {
		$label = '';

		if ( function_exists( 'spar_get_option' ) ) {
			$label = spar_get_option( '', 'points_label' );
		} else {
			$options = get_option( 'spar_options', array() );
			$label   = isset( $options['points_label'] ) ? $options['points_label'] : '';
		}

		$label = is_scalar( $label ) ? trim( wp_strip_all_tags( (string) $label ) ) : '';
		if ( '' === $label ) {
			$label = esc_html__( 'Points', 'simple-points-and-rewards' );
		}

		$label = translate( $label, 'simple-points-and-rewards' );

		return (string) apply_filters( 'spar_configured_points_label', $label );
	}
}

if ( ! function_exists( 'spar_get_configured_points_label_singular' ) ) {
	/**
	 * Get the optional configured singular points label.
	 *
	 * @return string
	 */
	function spar_get_configured_points_label_singular() {
		$label = '';

		if ( function_exists( 'spar_get_option' ) ) {
			$label = spar_get_option( '', 'points_label_singular' );
		} else {
			$options = get_option( 'spar_options', array() );
			$label   = isset( $options['points_label_singular'] ) ? $options['points_label_singular'] : '';
		}

		$label = is_scalar( $label ) ? trim( wp_strip_all_tags( (string) $label ) ) : '';
		if ( '' !== $label ) {
			$label = translate( $label, 'simple-points-and-rewards' );
		}

		return (string) apply_filters( 'spar_configured_points_label_singular', $label );
	}
}

if ( ! function_exists( 'spar_get_points_prefix' ) ) {
	/**
	 * Get the optional prefix shown before points amounts (e.g. "$").
	 *
	 * Configured under the Points Label settings tab. Empty string when unset.
	 *
	 * @return string
	 */
	function spar_get_points_prefix() {
		$prefix = '';

		if ( function_exists( 'spar_get_option' ) ) {
			$prefix = spar_get_option( '', 'points_prefix' );
		} else {
			$options = get_option( 'spar_options', array() );
			$prefix  = isset( $options['points_prefix'] ) ? $options['points_prefix'] : '';
		}

		$prefix = is_scalar( $prefix ) ? (string) $prefix : '';

		return (string) apply_filters( 'spar_points_prefix', $prefix );
	}
}

if ( ! function_exists( 'spar_format_points_value' ) ) {
	/**
	 * Format a points amount for customer-facing display, applying the
	 * configured points prefix (e.g. a balance of 100 shows as "$100").
	 *
	 * Drop-in replacement for number_format_i18n() on storefront/email output.
	 * Negative amounts keep the sign outside the prefix, e.g. "-$100".
	 *
	 * @param int|float $amount   Points amount to display.
	 * @param int       $decimals Number of decimal places (default 0).
	 * @return string
	 */
	function spar_format_points_value( $amount, $decimals = 0 ) {
		$amount    = is_numeric( $amount ) ? $amount + 0 : 0;
		$negative  = $amount < 0;
		$formatted = number_format_i18n( abs( $amount ), (int) $decimals );

		return ( $negative ? '-' : '' ) . spar_get_points_prefix() . $formatted;
	}
}

if ( ! function_exists( 'spar_get_points_icon_presets' ) ) {
	/**
	 * Bundled points-icon presets shown next to points values.
	 *
	 * @return array
	 */
	function spar_get_points_icon_presets() {
		$base = ( defined( 'SPAR_PLUGIN_URL' ) ? SPAR_PLUGIN_URL : plugin_dir_url( __FILE__ ) . '../' ) . 'assets/images/points-icons/';

		$presets = array(
			'coin'   => array( 'label' => esc_html__( 'Gold Coin', 'simple-points-and-rewards' ),   'file' => 'coin.svg' ),
			'dollar' => array( 'label' => esc_html__( 'Dollar Coin', 'simple-points-and-rewards' ), 'file' => 'dollar.svg' ),
			'star'   => array( 'label' => esc_html__( 'Star', 'simple-points-and-rewards' ),         'file' => 'star.svg' ),
			'gem'    => array( 'label' => esc_html__( 'Gem', 'simple-points-and-rewards' ),          'file' => 'gem.svg' ),
			'trophy' => array( 'label' => esc_html__( 'Trophy', 'simple-points-and-rewards' ),       'file' => 'trophy.svg' ),
			'gift'   => array( 'label' => esc_html__( 'Gift', 'simple-points-and-rewards' ),         'file' => 'gift.svg' ),
		);

		foreach ( $presets as $key => $data ) {
			$presets[ $key ]['url'] = $base . $data['file'];
		}

		return (array) apply_filters( 'spar_points_icon_presets', $presets );
	}
}

if ( ! function_exists( 'spar_get_points_icon_url' ) ) {
	/**
	 * Resolve the configured points icon to an image URL ('' when none).
	 *
	 * @return string
	 */
	function spar_get_points_icon_url() {
		if ( function_exists( 'spar_get_option' ) ) {
			$icon   = spar_get_option( '', 'points_icon' );
			$custom = spar_get_option( '', 'points_icon_url' );
		} else {
			$options = get_option( 'spar_options', array() );
			$icon    = isset( $options['points_icon'] ) ? $options['points_icon'] : '';
			$custom  = isset( $options['points_icon_url'] ) ? $options['points_icon_url'] : '';
		}

		$icon = is_scalar( $icon ) ? (string) $icon : '';
		$url  = '';

		if ( 'custom' === $icon ) {
			$url = is_scalar( $custom ) ? (string) $custom : '';
		} elseif ( '' !== $icon ) {
			$presets = spar_get_points_icon_presets();
			if ( isset( $presets[ $icon ]['url'] ) ) {
				$url = (string) $presets[ $icon ]['url'];
			}
		}

		return (string) apply_filters( 'spar_points_icon_url', $url, $icon );
	}
}

if ( ! function_exists( 'spar_points_icon_display_mode' ) ) {
	/**
	 * Where the points icon is shown: 'prominent' (headline amounts) or 'all'.
	 *
	 * @return string
	 */
	function spar_points_icon_display_mode() {
		if ( function_exists( 'spar_get_option' ) ) {
			$mode = spar_get_option( '', 'points_icon_display' );
		} else {
			$options = get_option( 'spar_options', array() );
			$mode    = isset( $options['points_icon_display'] ) ? $options['points_icon_display'] : 'prominent';
		}

		return ( 'all' === $mode ) ? 'all' : 'prominent';
	}
}

if ( ! function_exists( 'spar_get_points_icon_html' ) ) {
	/**
	 * Icon <img> markup shown next to a points value, or '' when not applicable.
	 *
	 * @param string $context 'prominent' (headline balances/amounts) or 'inline' (in-sentence).
	 * @return string
	 */
	function spar_get_points_icon_html( $context = 'inline' ) {
		$url = spar_get_points_icon_url();
		if ( '' === $url ) {
			return '';
		}

		$context = ( 'prominent' === $context ) ? 'prominent' : 'inline';
		$show    = ( 'all' === spar_points_icon_display_mode() ) || ( 'prominent' === $context );
		if ( ! $show ) {
			return '';
		}

		// Inline sizing so the icon renders consistently on every storefront
		// surface without depending on a particular stylesheet being loaded.
		// Sizes are in em so the icon scales with the surrounding text.
		$em    = ( 'prominent' === $context ) ? '1.05em' : '1em';
		$style = 'display:inline-block;width:' . $em . ';height:' . $em . ';vertical-align:-0.15em;margin-right:0.28em;object-fit:contain;';

		$html = '<img src="' . esc_url( $url ) . '" alt="" role="presentation" class="spar-points-icon spar-points-icon--' . esc_attr( $context ) . '" style="' . esc_attr( $style ) . '" />';

		return (string) apply_filters( 'spar_points_icon_html', $html, $context, $url );
	}
}

if ( ! function_exists( 'spar_format_points_display' ) ) {
	/**
	 * Points value for HTML output: icon (when applicable) + prefix + formatted number.
	 *
	 * Drop-in for `esc_html( spar_format_points_value( $x ) )` at HTML call sites.
	 * Plain-text contexts (email subjects, values injected into JS strings) should
	 * keep spar_format_points_value(); the text prefix already covers those.
	 *
	 * @param int|float $amount   Points amount.
	 * @param string    $context  'prominent' or 'inline'.
	 * @param int       $decimals Decimal places.
	 * @return string Safe HTML.
	 */
	function spar_format_points_display( $amount, $context = 'inline', $decimals = 0 ) {
		return spar_get_points_icon_html( $context ) . esc_html( spar_format_points_value( $amount, $decimals ) );
	}
}

if ( ! function_exists( 'spar_get_points_label_for_count' ) ) {
	/**
	 * Resolve the best points label for a displayed points amount.
	 *
	 * @param int|float|null $points       Points amount being displayed.
	 * @param string         $points_label Optional plural/display label override.
	 * @param string         $context      Display context for filters.
	 * @return string
	 */
	function spar_get_points_label_for_count( $points = null, $points_label = '', $context = '' ) {
		$plural_label = is_scalar( $points_label ) ? trim( wp_strip_all_tags( (string) $points_label ) ) : '';
		if ( '' === $plural_label ) {
			$plural_label = spar_get_configured_points_label();
		} else {
			$plural_label = translate( $plural_label, 'simple-points-and-rewards' );
		}

		$singular_label = spar_get_configured_points_label_singular();
		$is_singular    = null !== $points && 1 === abs( (int) $points );

		if ( $is_singular && '' === $singular_label ) {
			if ( 'Reward Points' === $plural_label || translate( 'Reward Points', 'simple-points-and-rewards' ) === $plural_label ) {
				$singular_label = esc_html__( 'Reward Point', 'simple-points-and-rewards' );
			} elseif ( 'Points' === $plural_label || translate( 'Points', 'simple-points-and-rewards' ) === $plural_label ) {
				$singular_label = esc_html__( 'Point', 'simple-points-and-rewards' );
			}
		}

		$label = ( $is_singular && '' !== $singular_label ) ? $singular_label : $plural_label;

		return (string) apply_filters( 'spar_points_label_for_count', $label, $points, $plural_label, $singular_label, $context );
	}
}

if ( ! function_exists( 'spar_strtolower' ) ) {
	/**
	 * Lowercase text with multibyte support where available.
	 *
	 * @param string $text Text to lowercase.
	 * @return string
	 */
	function spar_strtolower( $text ) {
		$text = (string) $text;
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text ) : strtolower( $text );
	}
}

if ( ! function_exists( 'spar_format_voucher_value_display' ) ) {
    /**
     * Build a human-friendly voucher value label that combines amount/percentage
     * discounts with optional free-shipping benefits.
     *
     * @param string $discount_type  WooCommerce discount type (percent/fixed_cart/etc).
     * @param float  $amount         Discount amount associated with the voucher/reward.
     * @param bool   $free_shipping  Whether the voucher also grants free shipping.
     * @return string Sanitized HTML/text describing the voucher value.
     */
	function spar_format_voucher_value_display( $discount_type = '', $amount = 0, $free_shipping = false ) {
		$parts         = array();
		$amount        = is_numeric( $amount ) ? (float) $amount : 0.0;
		$discount_type = is_string( $discount_type ) ? strtolower( $discount_type ) : '';
		$free_shipping = (bool) $free_shipping;

		if ( $amount > 0 ) {
			if ( 'percent' === $discount_type ) {
				$parts[] = sprintf(
					'%1$s%% %2$s',
					esc_html( number_format_i18n( $amount ) ),
					esc_html__( 'Off', 'simple-points-and-rewards' )
				);
			} else {
				$value_display = function_exists( 'wc_price' )
					? wc_price( $amount )
					: esc_html( spar_format_currency_amount( $amount ) );
				$parts[] = sprintf(
					'%1$s %2$s',
					$value_display,
					esc_html__( 'Off', 'simple-points-and-rewards' )
				);
			}
		}

		if ( $free_shipping ) {
			$parts[] = esc_html__( 'Free Shipping', 'simple-points-and-rewards' );
		}

		if ( empty( $parts ) ) {
			return '';
		}

		$display = implode( ' + ', $parts );

		return apply_filters( 'spar_voucher_value_display', $display, $discount_type, $amount, $free_shipping, $parts );
	}
}

if ( ! function_exists( 'spar_get_coupon_limitations' ) ) {
	/**
	 * Build an array of human-readable limitation notes for a WooCommerce coupon.
	 *
	 * @param int $coupon_id Post ID of the shop_coupon.
	 * @return array List of plain-text limitation strings.
	 */
	function spar_get_coupon_limitations( $coupon_id ) {
		$notes = [];

		// Detect product vouchers: SPAR reward vouchers that grant a specific free product.
		// For these, product_ids is the free item itself, not a purchase restriction.
		$is_product_voucher = '1' === get_post_meta( $coupon_id, '_spar_reward_voucher', true )
			&& ! empty( get_post_meta( $coupon_id, 'product_ids', true ) );

		$minimum_amount = get_post_meta( $coupon_id, 'minimum_amount', true );
		if ( is_numeric( $minimum_amount ) && (float) $minimum_amount > 0 ) {
			/* translators: %s: formatted minimum spend amount */
			$notes[] = sprintf( wp_kses_post( __( 'Min. spend: %s', 'simple-points-and-rewards' ) ), html_entity_decode( wp_strip_all_tags( wc_price( (float) $minimum_amount ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		}

		$maximum_amount = get_post_meta( $coupon_id, 'maximum_amount', true );
		if ( is_numeric( $maximum_amount ) && (float) $maximum_amount > 0 ) {
			/* translators: %s: formatted maximum spend amount */
			$notes[] = sprintf( wp_kses_post( __( 'Max. spend: %s', 'simple-points-and-rewards' ) ), html_entity_decode( wp_strip_all_tags( wc_price( (float) $maximum_amount ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		}

		// Only show product restriction note for non-product-vouchers; for product vouchers
		// the product_ids field identifies the free item, not a purchase restriction.
		if ( ! $is_product_voucher ) {
			$product_ids = get_post_meta( $coupon_id, 'product_ids', true );
			if ( ! empty( $product_ids ) ) {
				$ids   = array_filter( array_map( 'intval', explode( ',', $product_ids ) ) );
				$names = [];
				foreach ( array_slice( $ids, 0, 3 ) as $pid ) {
					$p = function_exists( 'wc_get_product' ) ? wc_get_product( $pid ) : null;
					if ( $p ) {
						$names[] = $p->get_name();
					}
				}
				if ( ! empty( $names ) ) {
					$label = implode( ', ', $names );
					if ( count( $ids ) > 3 ) {
						/* translators: %d: number of additional products */
						$label .= sprintf( esc_html__( ' +%d more', 'simple-points-and-rewards' ), count( $ids ) - 3 );
					}
					/* translators: %s: list of product names */
					$notes[] = sprintf( esc_html__( 'Products only: %s', 'simple-points-and-rewards' ), $label );
				}
			}
		}

		$exclude_product_ids = get_post_meta( $coupon_id, 'exclude_product_ids', true );
		if ( ! empty( $exclude_product_ids ) ) {
			$notes[] = esc_html__( 'Some products excluded', 'simple-points-and-rewards' );
		}

		$product_categories = get_post_meta( $coupon_id, 'product_categories', true );
		if ( ! empty( $product_categories ) && is_array( $product_categories ) ) {
			$cat_names = [];
			foreach ( array_slice( $product_categories, 0, 3 ) as $cat_id ) {
				$term = get_term( (int) $cat_id, 'product_cat' );
				if ( $term && ! is_wp_error( $term ) ) {
					$cat_names[] = $term->name;
				}
			}
			if ( ! empty( $cat_names ) ) {
				/* translators: %s: list of category names */
				$notes[] = sprintf( esc_html__( 'Categories: %s', 'simple-points-and-rewards' ), implode( ', ', $cat_names ) );
			}
		}

		$exclude_product_categories = get_post_meta( $coupon_id, 'exclude_product_categories', true );
		if ( ! empty( $exclude_product_categories ) && is_array( $exclude_product_categories ) ) {
			$notes[] = esc_html__( 'Some categories excluded', 'simple-points-and-rewards' );
		}

		$exclude_sale_items = get_post_meta( $coupon_id, 'exclude_sale_items', true );
		if ( 'yes' === $exclude_sale_items ) {
			$notes[] = esc_html__( 'Excludes sale items', 'simple-points-and-rewards' );
		}

		/**
		 * Filter the coupon limitation notes displayed in the voucher list.
		 *
		 * @param array $notes     List of limitation strings.
		 * @param int   $coupon_id Post ID of the coupon.
		 */
		return apply_filters( 'spar_coupon_limitation_notes', $notes, $coupon_id );
	}
}

if ( ! function_exists( 'spar_format_currency_amount' ) ) {
	/**
	 * Format a numeric amount using WooCommerce price settings when available.
	 *
	 * @param float|int|string $amount   Numeric amount to format.
	 * @param int|null         $decimals Optional override for decimal places.
	 * @return string
	 */
	function spar_format_currency_amount( $amount, $decimals = null ) {
		$amount = is_numeric( $amount ) ? (float) $amount : 0.0;
		if ( function_exists( 'wc_get_price_decimals' ) && function_exists( 'wc_get_price_decimal_separator' ) && function_exists( 'wc_get_price_thousand_separator' ) ) {
			$price_decimals = is_numeric( $decimals ) ? (int) $decimals : (int) wc_get_price_decimals();
			$decimal_sep    = (string) wc_get_price_decimal_separator();
			$thousand_sep   = (string) wc_get_price_thousand_separator();
			return number_format( $amount, $price_decimals, $decimal_sep, $thousand_sep );
		}
		$price_decimals = is_numeric( $decimals ) ? (int) $decimals : 2;
		return number_format_i18n( $amount, $price_decimals );
	}
}

if ( ! function_exists( 'spar_format_multiplier' ) ) {
	/**
	 * Format a points multiplier for display (e.g. 1.5, 1.75, 2).
	 *
	 * Shows up to 2 decimal places but strips unnecessary trailing zeros,
	 * so 2.0 displays as "2", 1.5 as "1.5", and 1.75 as "1.75".
	 *
	 * @param float $multiplier The multiplier value.
	 * @return string Formatted multiplier string.
	 */
	function spar_format_multiplier( $multiplier ) {
		$multiplier = (float) $multiplier;

		if ( $multiplier === floor( $multiplier ) ) {
			return number_format_i18n( $multiplier, 0 );
		}

		$one_decimal  = round( $multiplier, 1 );
		$two_decimals = round( $multiplier, 2 );

		if ( abs( $one_decimal - $two_decimals ) < 0.001 ) {
			return number_format_i18n( $multiplier, 1 );
		}

		return number_format_i18n( $multiplier, 2 );
	}
}

/**
 * Acquire a MySQL named lock scoped to this site.
 *
 * Used to serialise short critical sections (order awards, spins, absolute
 * balance overrides) across concurrent requests. Locks are released
 * automatically by MySQL when the request's DB connection closes, so a
 * fatal error mid-section cannot leave the lock stuck.
 *
 * Callers must not hold two of these locks at once: on MySQL < 5.7.5,
 * acquiring a second named lock on the same connection silently releases
 * the first.
 *
 * @param string $key     Short lock identifier, e.g. "order_points_123".
 * @param int    $timeout Seconds to wait for the lock. 0 returns immediately.
 * @return bool True when acquired. Also true when the server cannot take
 *              named locks at all (GET_LOCK returned NULL), so callers
 *              degrade to the previous unlocked behaviour instead of
 *              failing hard.
 */
function spar_acquire_db_lock( $key, $timeout = 5 ) {
	global $wpdb;
	// MySQL lock names are limited to 64 chars; hash with the table prefix so
	// separate sites sharing one DB server never contend on the same name.
	$name = 'spar_' . md5( $wpdb->prefix . (string) $key );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$result = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, max( 0, (int) $timeout ) ) );
	if ( null === $result ) {
		return true;
	}
	return '1' === (string) $result;
}

/**
 * Release a named lock previously acquired with spar_acquire_db_lock().
 *
 * @param string $key Lock identifier used when acquiring.
 */
function spar_release_db_lock( $key ) {
	global $wpdb;
	$name = 'spar_' . md5( $wpdb->prefix . (string) $key );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
}

/**
 * Atomically adjust the stored `_spar_points` balance by a signed delta.
 *
 * The change runs as a single conditional UPDATE so concurrent requests
 * cannot lose updates the way a read-modify-write does. Deductions clamp at
 * zero unless $require_sufficient is set, in which case the update is
 * refused outright when the stored balance no longer covers it.
 *
 * Low-level primitive: does not log, fire hooks, or touch lifetime totals.
 * Use spar_update_user_points() unless those side effects are unwanted.
 *
 * @param int  $user_id            User ID.
 * @param int  $delta              Signed points delta.
 * @param bool $require_sufficient Refuse (instead of clamp) a deduction that
 *                                 exceeds the stored balance.
 * @return int|false New balance, or false when $require_sufficient blocked
 *                   the deduction.
 */
function spar_adjust_user_points_balance( $user_id, $delta, $require_sufficient = false ) {
	global $wpdb;
	$user_id = absint( $user_id );
	$delta   = (int) $delta;

	// Ensure the meta row exists so the UPDATE below always has a target.
	if ( '' === (string) get_user_meta( $user_id, '_spar_points', true ) ) {
		add_user_meta( $user_id, '_spar_points', 0, true );
	}

	if ( 0 !== $delta ) {
		if ( $require_sufficient && $delta < 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$updated = $wpdb->query( $wpdb->prepare(
				"UPDATE {$wpdb->usermeta} SET meta_value = CAST(meta_value AS SIGNED) + %d WHERE user_id = %d AND meta_key = '_spar_points' AND CAST(meta_value AS SIGNED) >= %d",
				$delta,
				$user_id,
				-$delta
			) );
			wp_cache_delete( $user_id, 'user_meta' );
			if ( ! $updated ) {
				return false;
			}
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$wpdb->usermeta} SET meta_value = GREATEST( 0, CAST(meta_value AS SIGNED) + %d ) WHERE user_id = %d AND meta_key = '_spar_points'",
				$delta,
				$user_id
			) );
			wp_cache_delete( $user_id, 'user_meta' );
		}
	}

	return (int) get_user_meta( $user_id, '_spar_points', true );
}

/**
 * Atomically adjust the lifetime `_spar_total_earned_points` meta by a
 * signed delta, backfilling the total from the activity log first when the
 * meta has never been initialised.
 *
 * Low-level primitive: does not log or fire hooks.
 *
 * @param int $user_id User ID.
 * @param int $delta   Signed delta. Pass 0 to only ensure the meta exists.
 * @return int New lifetime total.
 */
function spar_adjust_user_lifetime_earned( $user_id, $delta ) {
	global $wpdb;
	$user_id  = absint( $user_id );
	$delta    = (int) $delta;
	$meta_key = '_spar_total_earned_points';

	// Backfill from the activity log (which persists) or initialise to 0, so
	// the delta below applies on top of the true lifetime total.
	$exists = get_user_meta( $user_id, $meta_key, true );
	if ( '' === $exists || null === $exists ) {
		if ( function_exists( 'spar_get_user_total_points_earned' ) ) {
			spar_get_user_total_points_earned( $user_id );
		}
		if ( '' === (string) get_user_meta( $user_id, $meta_key, true ) ) {
			add_user_meta( $user_id, $meta_key, 0, true );
		}
	}

	if ( 0 !== $delta ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->usermeta} SET meta_value = GREATEST( 0, CAST(meta_value AS SIGNED) + %d ) WHERE user_id = %d AND meta_key = %s",
			$delta,
			$user_id,
			$meta_key
		) );
		wp_cache_delete( $user_id, 'user_meta' );
	}

	return (int) get_user_meta( $user_id, $meta_key, true );
}

/**
 * Set a user's points balance to an absolute value through the canonical
 * writer, so the change is logged and the usual hooks fire.
 *
 * The delta is computed under a per-user lock so a concurrent earn/redeem
 * cannot slip between the read and the write.
 *
 * @param int    $user_id     User ID.
 * @param int    $new_balance Target balance (clamped to >= 0).
 * @param string $note        Log note. Defaults to a generic admin note.
 * @param string $action_id   Log action identifier. Defaults to
 *                            'admin_set_balance' so the resulting log row is
 *                            distinguishable from a relative 'admin_adjustment'
 *                            add: because this is an absolute override that does
 *                            not touch the lifetime "Total Earned" figure, the
 *                            lifetime backfill and log undo/delete must not treat
 *                            its 'add' row as a real earning.
 * @return array Canonical writer result (status/new_balance).
 */
function spar_set_user_points_balance( $user_id, $new_balance, $note = '', $action_id = 'admin_set_balance' ) {
	$user_id     = absint( $user_id );
	$new_balance = max( 0, (int) $new_balance );
	if ( '' === $note ) {
		$note = esc_html__( 'Points balance set by admin', 'simple-points-and-rewards' );
	}

	$lock_key = 'user_points_' . $user_id;
	$locked   = spar_acquire_db_lock( $lock_key );

	// Raw stored balance (not the filtered display value) for the delta.
	$current = (int) get_user_meta( $user_id, '_spar_points', true );
	$delta   = $new_balance - $current;

	if ( 0 === $delta ) {
		if ( $locked ) {
			spar_release_db_lock( $lock_key );
		}
		return array(
			'status'      => 'applied',
			'new_balance' => $new_balance,
		);
	}

	$result = spar_update_user_points(
		$user_id,
		abs( $delta ),
		$delta > 0 ? 'add' : 'remove',
		$note,
		$action_id,
		array(),
		array(
			// Manual absolute overrides apply to banned users too and must
			// not count towards the lifetime "Total Earned" figure.
			'allow_banned'  => true,
			'skip_lifetime' => true,
		)
	);

	if ( $locked ) {
		spar_release_db_lock( $lock_key );
	}

	return $result;
}

/**
 * Add or remove points
 *
 * Parameters:
 * - $user_id   (int)    User ID.
 * - $points    (int)    Number of points to add/remove.
 * - $action    (string) 'add' or 'remove'.
 * - $note      (string) Human-friendly context shown in logs/UI.
 * - $action_id (string) Machine-friendly action identifier for analytics/filtering.
 * - $context   (array)  Optional machine-readable context for integrations.
 * - $options   (array)  Optional behaviour flags:
 *                       'require_sufficient' (bool) Refuse a 'remove' that exceeds the
 *                       current balance instead of clamping at zero. Use for
 *                       user-initiated spends (redemptions, purchases) so two
 *                       concurrent requests cannot both spend the same points.
 *                       'allow_banned' (bool) Permit an 'add' for banned users
 *                       (manual admin overrides).
 *                       'skip_lifetime' (bool) Do not touch the lifetime
 *                       "Total Earned" meta (caller manages it explicitly).
 *
 * Common $action_id values used across the plugin:
 * - 'signup'          – signup bonus
 * - 'order'           – points earned for an order
 * - 'first_order'     – first order bonus
 * - 'nth_order'       – n-th order bonus
 * - 'order_refund'    – deduction for refunded order
 * - 'order_cancelled' – deduction for cancelled order
 * - 'order_failed'    – deduction for failed order
 * - 'referral'        – referral rewards
 * - 'review'          – product review rewards (PRO)
 * - 'redeem'          – points spent on rewards redemption
 * - 'redeem_refund'   – refund of points after failed redemption
 * - 'admin_adjustment'– manual admin points change
 */
function spar_update_user_points( $user_id, $points, $action = 'add', $note = '', $action_id = '', $context = array(), $options = array() ) {
	// Allow developers to adjust the update arguments before applying.
	$args = apply_filters( 'spar_update_user_points_args', [
		'user_id'   => (int) $user_id,
		'points'    => (int) $points,
		'action'    => (string) $action,
		'note'      => (string) $note,
		'action_id' => is_string( $action_id ) ? sanitize_key( $action_id ) : '',
		'context'   => is_array( $context ) ? $context : array(),
		'options'   => is_array( $options ) ? $options : array(),
	] );

	$user_id   = (int) ( $args['user_id'] ?? $user_id );
	$points    = (int) ( $args['points'] ?? $points );
	$action    = (string) ( $args['action'] ?? $action );
	// Constrain action to expected values
	$action    = ( 'remove' === $action ) ? 'remove' : 'add';
	// Sanitize note for storage/logging and downstream hooks
	$note      = sanitize_text_field( (string) ( $args['note'] ?? $note ) );
	$action_id = (string) ( $args['action_id'] ?? $action_id );
	$options   = ( isset( $args['options'] ) && is_array( $args['options'] ) ) ? $args['options'] : array();
	$context   = array();
	if ( isset( $args['context'] ) && is_array( $args['context'] ) ) {
		foreach ( $args['context'] as $context_key => $context_value ) {
			$context_key = sanitize_key( $context_key );
			if ( '' === $context_key ) {
				continue;
			}

			if ( is_bool( $context_value ) ) {
				$context[ $context_key ] = $context_value;
			} elseif ( is_numeric( $context_value ) ) {
				$context[ $context_key ] = 0 + $context_value;
			} elseif ( is_scalar( $context_value ) ) {
				$context[ $context_key ] = sanitize_text_field( (string) $context_value );
			}
		}
	}

	// Prevent earning points for banned users (do not block deductions)
	$user_status = get_user_meta( $user_id, 'spar_user_status', true );
	if ( 'banned' === $user_status && 'add' === $action && $points > 0 && empty( $options['allow_banned'] ) ) {
		/**
		 * Fires when a banned user attempts to earn points (blocked).
		 *
		 * @param int    $user_id User ID.
		 * @param int    $points  Points that were attempted.
		 * @param string $note    Context note.
		 * @param string $action_id Action identifier.
		 */
		do_action( 'spar_banned_user_points_blocked', $user_id, (int) $points, $note, $action_id, $context );
		return array(
			'status' => 'blocked',
			'reason' => 'banned_user',
		); // Bail early – no changes applied.
	}

	/**
	 * Allows integrations to short-circuit a points update before balances change.
	 *
	 * Return null to continue with the normal update. Return any non-null value to
	 * stop the update and return that value to the original caller.
	 *
	 * @param mixed  $pre_update Null to continue, or a custom return value.
	 * @param int    $user_id    User ID.
	 * @param int    $points     Points amount.
	 * @param string $action     'add' or 'remove'.
	 * @param string $note       Human-friendly note.
	 * @param string $action_id  Machine-friendly action identifier.
	 * @param array  $context    Optional machine-readable context.
	 */
	$pre_update = apply_filters( 'spar_pre_update_user_points', null, $user_id, $points, $action, $note, $action_id, $context );
	if ( null !== $pre_update ) {
		return $pre_update;
	}

	/**
	 * Fires right before a user's points balance is updated.
	 *
	 * @param int    $user_id   User ID.
	 * @param int    $points    Positive integer amount being added/removed.
	 * @param string $action    'add' or 'remove'.
	 * @param string $note      Human-friendly note.
	 * @param string $action_id Machine-friendly short identifier.
	 */
	do_action( 'spar_before_points_update', $user_id, $points, $action, $note, $action_id, $context );

	// Track level before change (if feature available) to detect transitions.
	$prev_level = null;
	if ( function_exists( 'spar_get_user_level' ) ) {
		$prev_level = spar_get_user_level( $user_id );
	}

	// Apply the balance change as a single atomic UPDATE so concurrent
	// requests cannot lose updates the way a read-modify-write would. When
	// the caller requires a sufficient balance (user-initiated spends), a
	// deduction that no longer fits is refused instead of clamped, closing
	// the window where two parallel requests could spend the same points.
	$require_sufficient = ( 'remove' === $action ) && ! empty( $options['require_sufficient'] );
	$delta = ( 'add' === $action ) ? $points : -$points;
	$new   = spar_adjust_user_points_balance( $user_id, $delta, $require_sufficient );
	if ( false === $new ) {
		return array(
			'status' => 'blocked',
			'reason' => 'insufficient_points',
		);
	}

	spar_log_points_event( $user_id, $note, $action, $points, $action_id );

	// Maintain lifetime "Total Earned" as user meta for fast reads.
	// By definition, lifetime total tracks only positive earnings (previously sum of 'add' logs);
	// order-related deductions (refunds/cancellations/failures) are subtracted back out.
	// A zero delta still ensures the meta exists so reads don't backfill repeatedly.
	if ( empty( $options['skip_lifetime'] ) ) {
		$order_deduction_actions = [ 'order_refund', 'order_cancelled', 'order_failed' ];
		if ( 'add' === $action && $points > 0 ) {
			spar_adjust_user_lifetime_earned( $user_id, $points );
		} elseif ( 'remove' === $action && $points > 0 && in_array( $action_id, $order_deduction_actions, true ) ) {
			spar_adjust_user_lifetime_earned( $user_id, -$points );
		} else {
			spar_adjust_user_lifetime_earned( $user_id, 0 );
		}
	}

	// Trigger notifications/actions for this update
	if ( $action === 'add' && $points > 0 ) {
		do_action( 'spar_points_added', $user_id, $points, $note, $action_id, $context );
	} elseif ( $action === 'remove' && $points > 0 ) {
		/**
		 * Fires when points are removed from a user.
		 *
		 * @param int    $user_id   User ID.
		 * @param int    $points    Amount removed.
		 * @param string $note      Context note.
		 * @param string $action_id Short action identifier.
		 */
		do_action( 'spar_points_removed', $user_id, $points, $note, $action_id, $context );
	}

	/**
	 * Fires after a user's points balance is updated.
	 *
	 * @param int    $user_id     User ID.
	 * @param int    $new_balance New points balance.
	 * @param int    $delta       Positive integer amount that was added/removed.
	 * @param string $action      'add' or 'remove'.
	 * @param string $note        Context note.
	 * @param string $action_id   Short action identifier.
	 */
	do_action( 'spar_after_points_update', $user_id, (int) $new, (int) $points, $action, $note, $action_id, $context );

	// Detect and announce level changes if levels feature is present.
	if ( function_exists( 'spar_get_user_level' ) ) {
		$new_level = spar_get_user_level( $user_id );
		$prev_id   = is_array( $prev_level ) && isset( $prev_level['id'] ) ? $prev_level['id'] : '';
		$new_id    = is_array( $new_level ) && isset( $new_level['id'] ) ? $new_level['id'] : '';
		if ( $new_id && $new_id !== $prev_id ) {
			/**
			 * Fires when a user's level changes.
			 *
			 * @param int   $user_id     User ID.
			 * @param array $new_level   New level array.
			 * @param array $previous    Previous level array (or null).
			 * @param int   $total_earned Lifetime total earned points after update.
			 */
			$total_earned = function_exists( 'spar_get_user_total_points_earned' ) ? spar_get_user_total_points_earned( $user_id ) : 0;
			do_action( 'spar_user_level_changed', $user_id, $new_level, $prev_level, (int) $total_earned );
		}
	}

	return array(
		'status'      => 'applied',
		'new_balance' => (int) $new,
	);
}

/*
 * Create points log table on plugin activation
 * Note: The activation hook is registered from the main plugin file to comply with WP.org guidelines.
 */
function spar_create_points_log_table() {
	global $wpdb;

	$table_name = $wpdb->prefix . 'spar_points_logs';
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE $table_name (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		user_id BIGINT UNSIGNED NOT NULL,
		action TEXT NOT NULL,
		type VARCHAR(100) NOT NULL,
		action_id VARCHAR(64) NULL,
		points INT NOT NULL,
		date DATETIME NOT NULL,
		PRIMARY KEY  (id),
		KEY user_id (user_id),
		KEY type (type),
		KEY action_id (action_id),
		KEY date (date)
	) $charset_collate;";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
}

/**
 * Log history
 *
 * $action_id is an optional short identifier describing the origin of the change
 * (e.g., 'order', 'referral', 'redeem'). It is sanitized with sanitize_key()
 * and stored in the spar_points_logs table for filtering/analytics.
 */
function spar_log_points_event( $user_id, $note, $type, $points, $action_id = '' ) {
	global $wpdb;
	$table = $wpdb->prefix . 'spar_points_logs';

	/**
	 * Short-circuit the activity log insert.
	 *
	 * Return true from this filter to silently skip writing this log row.
	 *
	 * @param bool   $skip      Whether to skip logging. Default false.
	 * @param int    $user_id   User ID.
	 * @param string $note      Log note.
	 * @param string $type      'add' or 'remove'.
	 * @param string $action_id Short action identifier.
	 */
	if ( (bool) apply_filters( 'spar_skip_log_points_event', false, $user_id, $note, $type, $action_id ) ) {
		return;
	}

	// Create table if it doesn't exist (cached check avoids SHOW TABLES on every insert).
	if ( ! spar_table_exists( $table ) ) {
		spar_create_points_log_table();
	}

	// $action_id is optional string identifier for the action source (e.g., 'referral', 'order')
	$action_id = is_string( $action_id ) ? sanitize_key( $action_id ) : '';

	$row = [
		'user_id'   => (int) $user_id,
		'action'    => sanitize_text_field( $note ),
		'type'      => sanitize_text_field( $type ),
		'action_id' => $action_id,
		'points'    => (int) $points,
		'date'      => current_time( 'mysql' ),
	];

	/**
	 * Filter the row to be inserted into the points log table.
	 *
	 * @param array $row Row array with keys: user_id, action, type, action_id, points, date.
	 */
	$row = apply_filters( 'spar_points_log_row', $row );
	// Re-sanitize defensively in case filters modified values
	$row = [
		'user_id'   => isset( $row['user_id'] ) ? (int) $row['user_id'] : (int) $user_id,
		'action'    => isset( $row['action'] ) ? sanitize_text_field( $row['action'] ) : sanitize_text_field( $note ),
		'type'      => ( isset( $row['type'] ) && 'remove' === $row['type'] ) ? 'remove' : 'add',
		'action_id' => isset( $row['action_id'] ) ? sanitize_key( (string) $row['action_id'] ) : $action_id,
		'points'    => isset( $row['points'] ) ? (int) $row['points'] : (int) $points,
		'date'      => isset( $row['date'] ) ? sanitize_text_field( $row['date'] ) : current_time( 'mysql' ),
	];

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Insert uses proper placeholders; WPDB handles sanitization by formats.
	$wpdb->insert( $table, $row, [ '%d', '%s', '%s', '%s', '%d', '%s' ] );

	/**
	 * Fires after a points log row was inserted.
	 *
	 * @param int   $insert_id Insert ID.
	 * @param array $row       The row data inserted.
	 */
	do_action( 'spar_points_log_inserted', (int) $wpdb->insert_id, $row );
}

/**
 * Maybe upgrade points log table to include new columns/indexes.
 * - Adds action_id column if missing.
 *
 * Uses a stored DB version option to avoid running expensive
 * SHOW TABLES / SHOW COLUMNS queries on every page load.
 */
function spar_maybe_upgrade_points_log_table() {

	// The current DB schema version. Bump this when adding new upgrade steps.
	$current_db_version = '1.1.0';

	// Bail early if the DB is already up to date — no queries needed.
	$installed_db_version = get_option( 'spar_db_version', '0' );
	if ( version_compare( $installed_db_version, $current_db_version, '>=' ) ) {
		return;
	}

	global $wpdb;
	$table = $wpdb->prefix . 'spar_points_logs';

	// Bail if table doesn't exist yet
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
		return;
	}

	// v1.1.0: Add action_id column if missing.
	if ( version_compare( $installed_db_version, '1.1.0', '<' ) ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$has_action_id = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'action_id' ) );
		if ( ! $has_action_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN action_id VARCHAR(64) NULL AFTER type', $table ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD INDEX action_id (action_id)', $table ) );
			/**
			 * Fires after the points log table structure has been upgraded.
			 *
			 * @param string $change Identifier of the change performed (e.g., 'action_id').
			 */
			do_action( 'spar_points_log_table_upgraded', 'action_id' );
		}
	}

	// All upgrades applied — store the current version so we skip next time.
	update_option( 'spar_db_version', $current_db_version, false );
}

// Run DB upgrade check only on admin requests.
add_action( 'admin_init', 'spar_maybe_upgrade_points_log_table' );

/**
 * Utility function to safely declare functions
 * Prevents redeclaration errors
 */
if ( ! function_exists( 'spar_function_exists' ) ) {
	function spar_function_exists( $function_name ) {
		return function_exists( $function_name );
	}
}

/**
 * Get user points history with pagination
 */
function spar_get_user_points_history( $user_id, $page = 1, $per_page = 10, $args = array() ) {
	global $wpdb;
	$table = $wpdb->prefix . 'spar_points_logs';

	// Optional type filter: 'add' (earned), 'remove' (spent), or '' (all).
	$type_filter = ( isset( $args['type_filter'] ) && in_array( $args['type_filter'], array( 'add', 'remove' ), true ) )
		? $args['type_filter']
		: '';

	// Calculate offset
	$per_page = (int) apply_filters( 'spar_points_history_per_page', (int) $per_page, (int) $user_id );
	$offset   = ( $page - 1 ) * $per_page;

	// Cache keys include the type filter so filtered and unfiltered results are stored separately.
	$cache_group     = 'spar';
	$filter_suffix   = '' !== $type_filter ? '_' . $type_filter : '';
	$count_cache_key = 'spar_points_history_count_' . absint( $user_id ) . $filter_suffix;
	$total_count     = wp_cache_get( $count_cache_key, $cache_group );
	if ( false === $total_count ) {
		if ( '' !== $type_filter ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$total_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE user_id = %d AND type = %s AND ( action_id IS NULL OR action_id != 'guest_migration' )", $table, $user_id, $type_filter ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$total_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE user_id = %d AND ( action_id IS NULL OR action_id != 'guest_migration' )", $table, $user_id ) );
		}
		wp_cache_set( $count_cache_key, $total_count, $cache_group, 60 );
	}

	$logs_cache_key = 'spar_points_history_logs_' . absint( $user_id ) . '_' . absint( $page ) . '_' . absint( $per_page ) . $filter_suffix;
	$logs           = wp_cache_get( $logs_cache_key, $cache_group );
	if ( false === $logs ) {
		if ( '' !== $type_filter ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$logs = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE user_id = %d AND type = %s AND ( action_id IS NULL OR action_id != 'guest_migration' ) ORDER BY date DESC LIMIT %d OFFSET %d", $table, $user_id, $type_filter, $per_page, $offset ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$logs = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE user_id = %d AND ( action_id IS NULL OR action_id != 'guest_migration' ) ORDER BY date DESC LIMIT %d OFFSET %d", $table, $user_id, $per_page, $offset ), ARRAY_A );
		}
		wp_cache_set( $logs_cache_key, $logs, $cache_group, 60 );
	}

	// Calculate pagination info
	$total_pages = ceil( $total_count / $per_page );

	$result = [
		'logs' => $logs,
		'pagination' => [
			'current_page' => $page,
			'per_page' => $per_page,
			'total_count' => $total_count,
			'total_pages' => $total_pages,
			'has_previous' => $page > 1,
			'has_next' => $page < $total_pages
		]
	];

	/**
	 * Filter the assembled points history response for a user.
	 *
	 * Only applied when showing all entries so that integrations (e.g. pending
	 * delay rows) are not injected into a type-filtered view.
	 *
	 * @param array $result  Array with 'logs' and 'pagination'.
	 * @param int   $user_id User ID.
	 */
	if ( '' === $type_filter ) {
		return apply_filters( 'spar_user_points_history', $result, (int) $user_id );
	}
	return $result;
}

/**
 * Get user referral clicks history with pagination
 */
function spar_get_user_referral_clicks_history( $user_id, $page = 1, $per_page = 5 ) {
	global $wpdb;
	$table = $wpdb->prefix . 'spar_referral_clicks';

	$per_page = (int) apply_filters( 'spar_referral_clicks_per_page', (int) $per_page, (int) $user_id );
	$offset   = ( $page - 1 ) * $per_page;

	// Ensure table exists (cached check).
	if ( ! spar_table_exists( $table ) ) {
		return [
			'logs' => [],
			'pagination' => [
				'current_page' => 1,
				'per_page' => $per_page,
				'total_count' => 0,
				'total_pages' => 0,
				'has_previous' => false,
				'has_next' => false,
			],
		];
	}

	$cache_group     = 'spar';
	$count_cache_key = 'spar_referral_clicks_count_' . absint( $user_id );
	$total_count     = wp_cache_get( $count_cache_key, $cache_group );
	if ( false === $total_count ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE referrer_user_id = %d', $table, $user_id ) );
		wp_cache_set( $count_cache_key, $total_count, $cache_group, 60 );
	}

	$logs_cache_key = 'spar_referral_clicks_logs_' . absint( $user_id ) . '_' . absint( $page ) . '_' . absint( $per_page );
	$logs           = wp_cache_get( $logs_cache_key, $cache_group );
	if ( false === $logs ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$logs = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, created_at, landing_url, referring_domain, converted FROM %i WHERE referrer_user_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d',
				$table,
				$user_id,
				$per_page,
				$offset
			),
			ARRAY_A
		);
		wp_cache_set( $logs_cache_key, $logs, $cache_group, 60 );
	}

	$total_pages = $per_page > 0 ? (int) ceil( $total_count / $per_page ) : 0;

	$result = [
		'logs' => $logs,
		'pagination' => [
			'current_page' => $page,
			'per_page' => $per_page,
			'total_count' => $total_count,
			'total_pages' => $total_pages,
			'has_previous' => $page > 1,
			'has_next' => $page < $total_pages,
		],
	];

	return apply_filters( 'spar_user_referral_clicks_history', $result, (int) $user_id );
}

/**
 * Get user referral clicks history with full fields (admin detail view).
 */
function spar_get_user_referral_clicks_history_full( $user_id, $page = 1, $per_page = 10 ) {
	global $wpdb;
	$table = $wpdb->prefix . 'spar_referral_clicks';

	$per_page = (int) apply_filters( 'spar_referral_clicks_per_page', (int) $per_page, (int) $user_id );
	$offset   = ( $page - 1 ) * $per_page;

	// Ensure table exists (cached check).
	if ( ! spar_table_exists( $table ) ) {
		return [
			'logs' => [],
			'pagination' => [
				'current_page' => 1,
				'per_page' => $per_page,
				'total_count' => 0,
				'total_pages' => 0,
				'has_previous' => false,
				'has_next' => false,
			],
		];
	}

	$cache_group     = 'spar';
	$count_cache_key = 'spar_referral_clicks_count_' . absint( $user_id );
	$total_count     = wp_cache_get( $count_cache_key, $cache_group );
	if ( false === $total_count ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE referrer_user_id = %d', $table, $user_id ) );
		wp_cache_set( $count_cache_key, $total_count, $cache_group, 60 );
	}

	$logs_cache_key = 'spar_referral_clicks_logs_full_' . absint( $user_id ) . '_' . absint( $page ) . '_' . absint( $per_page );
	$logs           = wp_cache_get( $logs_cache_key, $cache_group );
	if ( false === $logs ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$logs = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, created_at, landing_url, referring_domain, converted, referree_user_id, order_id FROM %i WHERE referrer_user_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d',
				$table,
				$user_id,
				$per_page,
				$offset
			),
			ARRAY_A
		);
		wp_cache_set( $logs_cache_key, $logs, $cache_group, 60 );
	}

	$total_pages = $per_page > 0 ? (int) ceil( $total_count / $per_page ) : 0;

	$result = [
		'logs' => $logs,
		'pagination' => [
			'current_page' => $page,
			'per_page' => $per_page,
			'total_count' => $total_count,
			'total_pages' => $total_pages,
			'has_previous' => $page > 1,
			'has_next' => $page < $total_pages,
		],
	];

	return apply_filters( 'spar_user_referral_clicks_history_full', $result, (int) $user_id );
}

/**
 * Format a referral click landing URL for display.
 */
function spar_format_referral_click_landing_url( $url ) {
	if ( empty( $url ) ) {
		return esc_html__( '—', 'simple-points-and-rewards' );
	}
	$parts = wp_parse_url( $url );
	if ( ! is_array( $parts ) ) {
		$raw = preg_replace( '#^https?://#i', '', $url );
		$raw = rtrim( (string) $raw, '/' );
		return esc_html( $raw );
	}
	$host = isset( $parts['host'] ) ? (string) $parts['host'] : '';
	$path = isset( $parts['path'] ) ? (string) $parts['path'] : '';
	$display = $host . $path;
	$display = function_exists( 'untrailingslashit' ) ? untrailingslashit( $display ) : rtrim( $display, '/' );
	if ( '' === $display ) {
		$display = isset( $parts['path'] ) ? ltrim( (string) $parts['path'], '/' ) : '';
		$display = rtrim( $display, '/' );
	}
	return esc_html( $display );
}

/**
 * Build referral clicks table rows HTML.
 */
function spar_build_referral_clicks_table_rows( $logs, $context = 'dashboard' ) {
	$context = in_array( $context, [ 'dashboard', 'widget' ], true ) ? $context : 'dashboard';
	$columns = ( 'widget' === $context ) ? 2 : 4;
	$table_html = '';

	if ( ! empty( $logs ) ) {
		foreach ( $logs as $index => $log ) {
			$row_class = ( $index % 2 === 0 ) ? 'spar-row-even' : 'spar-row-odd';
			$date = '';
			if ( ! empty( $log['created_at'] ) ) {
				$date_format = ( 'widget' === $context ) ? 'M j, Y \\a\\t g:i A' : 'M j, Y \\a\\t g:i A';
				$date = date_i18n( $date_format, strtotime( $log['created_at'] ) );
			}
			$landing = spar_format_referral_click_landing_url( $log['landing_url'] ?? '' );
			if ( 'widget' === $context ) {
				$converted = ! empty( $log['converted'] ) ? esc_html__( 'Yes', 'simple-points-and-rewards' ) : esc_html__( 'No', 'simple-points-and-rewards' );
				$table_html .= sprintf(
					'<tr class="%s">'
					. '<td data-label="%s">%s</td>'
					. '<td data-label="%s">%s</td>'
					. '</tr>',
					esc_attr( $row_class ),
					esc_attr__( 'Date', 'simple-points-and-rewards' ),
					esc_html( $date ),
					esc_attr__( 'Converted', 'simple-points-and-rewards' ),
					esc_html( $converted )
				);
			} else {
				$converted = ! empty( $log['converted'] ) ? esc_html__( 'Yes', 'simple-points-and-rewards' ) : esc_html__( 'No', 'simple-points-and-rewards' );
				$referring_domain = ! empty( $log['referring_domain'] ) ? esc_html( $log['referring_domain'] ) : '&mdash;';
				$table_html .= sprintf(
					'<tr class="%s">'
					. '<td data-label="%s">%s</td>'
					. '<td data-label="%s">%s</td>'
					. '<td data-label="%s">%s</td>'
					. '<td class="spar-td-center" data-label="%s">%s</td>'
					. '</tr>',
					esc_attr( $row_class ),
					esc_attr__( 'Date', 'simple-points-and-rewards' ),
					esc_html( $date ),
					esc_attr__( 'Landing Page', 'simple-points-and-rewards' ),
					$landing,
					esc_attr__( 'Referring Domain', 'simple-points-and-rewards' ),
					$referring_domain,
					esc_attr__( 'Converted', 'simple-points-and-rewards' ),
					esc_html( $converted )
				);
			}
		}
	} else {
		$table_html = sprintf(
			'<tr class="spar-empty"><td colspan="%d">%s</td></tr>',
			(int) $columns,
			esc_html__( 'No referral clicks yet.', 'simple-points-and-rewards' )
		);
	}

	return $table_html;
}

/**
 * Build referral clicks pagination HTML.
 */
function spar_build_referral_clicks_pagination_html( $pagination, $context = 'dashboard' ) {
	$context = in_array( $context, [ 'dashboard', 'widget' ], true ) ? $context : 'dashboard';
	if ( empty( $pagination['total_pages'] ) || $pagination['total_pages'] <= 1 ) {
		return '';
	}

	$pagination_html = '';

	$pagination_html .= '<div class="spar-pagination-controls">';
	$prev_disabled = ! $pagination['has_previous'] ? 'disabled' : '';
	$pagination_html .= sprintf(
		'<button class="spar-pagination-btn spar-pagination-prev" data-page="%d" %s>%s</button>',
		$pagination['current_page'] - 1,
		$prev_disabled,
		esc_html__( '← Previous', 'simple-points-and-rewards' )
	);

	if ( 'widget' === $context ) {
		$current_page = (int) $pagination['current_page'];
		$total_pages  = (int) $pagination['total_pages'];
		$start_page   = max( 1, $current_page - 1 );
		$end_page     = min( $total_pages, $current_page + 1 );
		if ( 1 === $start_page ) {
			$end_page = min( $total_pages, 3 );
		} elseif ( $end_page === $total_pages ) {
			$start_page = max( 1, $total_pages - 2 );
		}
		for ( $i = $start_page; $i <= $end_page; $i++ ) {
			$is_current  = $i === $current_page;
			$btn_class   = $is_current ? 'spar-pagination-btn spar-pagination-current' : 'spar-pagination-btn spar-pagination-page';
			$btn_disabled = $is_current ? 'disabled' : '';
			$pagination_html .= sprintf(
				'<button class="%s" data-page="%d" %s>%d</button>',
				esc_attr( $btn_class ),
				$i,
				$btn_disabled,
				$i
			);
		}
	} else {
		$current_page = (int) $pagination['current_page'];
		$total_pages  = (int) $pagination['total_pages'];
		$start_page   = max( 1, $current_page - 2 );
		$end_page     = min( $total_pages, $current_page + 2 );
		if ( 1 === $start_page ) {
			$end_page = min( $total_pages, 5 );
		} elseif ( $end_page === $total_pages ) {
			$start_page = max( 1, $total_pages - 4 );
		}
		for ( $i = $start_page; $i <= $end_page; $i++ ) {
			$is_current = $i === $current_page;
			$btn_class = $is_current ? 'spar-pagination-btn spar-pagination-current' : 'spar-pagination-btn spar-pagination-page';
			$btn_disabled = $is_current ? 'disabled' : '';
			$pagination_html .= sprintf(
				'<button class="%s" data-page="%d" %s>%d</button>',
				esc_attr( $btn_class ),
				$i,
				$btn_disabled,
				$i
			);
		}
	}

	$next_disabled = ! $pagination['has_next'] ? 'disabled' : '';
	$pagination_html .= sprintf(
		'<button class="spar-pagination-btn spar-pagination-next" data-page="%d" %s>%s</button>',
		$pagination['current_page'] + 1,
		$next_disabled,
		esc_html__( 'Next →', 'simple-points-and-rewards' )
	);
	$pagination_html .= '</div>';

	if ( 'dashboard' === $context ) {
		$pagination_html .= '<div class="spar-pagination-loading"><span>' . esc_html__( 'Loading...', 'simple-points-and-rewards' ) . '</span></div>';
	}

	return $pagination_html;
}

/**
 * Create referral clicks log table on plugin activation
 */
function spar_create_referral_clicks_table() {
	global $wpdb;

	$table_name      = $wpdb->prefix . 'spar_referral_clicks';
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE $table_name (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		created_at DATETIME NOT NULL,
		referral_code VARCHAR(64) NOT NULL,
		referrer_user_id BIGINT UNSIGNED NULL,
		referree_user_id BIGINT UNSIGNED NULL,
		landing_url TEXT NULL,
		referral_link TEXT NULL,
		referring_domain VARCHAR(191) NULL,
		converted TINYINT(1) NOT NULL DEFAULT 0,
		order_id BIGINT UNSIGNED NULL,
		gift_coupon_code VARCHAR(200) NULL,
		conversion_at DATETIME NULL,
		PRIMARY KEY  (id),
		KEY created_at (created_at),
		KEY referrer_user_id (referrer_user_id),
		KEY converted (converted),
		KEY order_id (order_id),
		KEY referral_code (referral_code)
	) $charset_collate;";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
}

/**
 * Create spins activity log table on plugin activation.
 */
function spar_create_spins_log_table() {
	global $wpdb;

	$table_name      = $wpdb->prefix . 'spar_spins_log';
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE $table_name (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		user_id BIGINT UNSIGNED NOT NULL,
		event_type VARCHAR(50) NOT NULL,
		description TEXT NOT NULL,
		spins INT NOT NULL DEFAULT 1,
		date DATETIME NOT NULL,
		PRIMARY KEY  (id),
		KEY user_id (user_id),
		KEY event_type (event_type),
		KEY date (date)
	) $charset_collate;";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
}

/**
 * Log a spins activity event.
 *
 * @param int    $user_id    User ID.
 * @param string $event_type 'spin' or 'bonus_earned'.
 * @param string $description Human-readable description of the event.
 * @param int    $spins      Number of spins involved (default 1).
 */
function spar_log_spin_event( $user_id, $event_type, $description, $spins = 1 ) {
	global $wpdb;

	$table = $wpdb->prefix . 'spar_spins_log';

	// Create table if it doesn't exist (cached check).
	if ( ! spar_table_exists( $table ) ) {
		spar_create_spins_log_table();
	}

	$row = [
		'user_id'    => (int) $user_id,
		'event_type' => in_array( $event_type, [ 'spin', 'bonus_earned' ], true ) ? $event_type : 'spin',
		'description'=> sanitize_text_field( $description ),
		'spins'      => (int) $spins,
		'date'       => current_time( 'mysql' ),
	];

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	$wpdb->insert( $table, $row, [ '%d', '%s', '%s', '%d', '%s' ] );
	// Flush all cached spins-log queries so pages reflect the new entry immediately.
	wp_cache_flush_group( 'spar' );
}

/**
 * Preview points for an order without awarding them.
 */
function spar_calculate_order_points_preview( $order, $user_id ) {
	if ( ! $order || ! $user_id ) {
		return 0;
	}

	$options = get_option( 'spar_options', [] );
	$has_spend = ! empty( $options['earn']['order']['enabled'] );
	$has_fixed = ! empty( $options['earn']['order_fixed']['enabled'] );
	if ( ! $has_spend && ! $has_fixed ) {
		return 0;
	}

	if ( ! function_exists( 'spar_calculate_order_base_points' ) ) {
		return 0;
	}

	$base_points = spar_calculate_order_base_points( $order );

	$currency     = method_exists( $order, 'get_currency' ) ? $order->get_currency() : '';
	$fixed_total  = function_exists( 'spar_get_points_calculation_total_from_order' ) ? spar_get_points_calculation_total_from_order( $order, 'order_fixed' ) : (float) $order->get_total();
	$fixed_info   = function_exists( 'spar_calculate_fixed_order_points_for_total' ) ? spar_calculate_fixed_order_points_for_total( $fixed_total, $currency ) : [ 'points' => 0 ];
	$fixed_points = isset( $fixed_info['points'] ) ? (int) $fixed_info['points'] : 0;
	$fixed_points = (int) apply_filters( 'spar_order_fixed_points', $fixed_points, $order, $fixed_info );

	$multiplier = 1.0;
	if ( function_exists( 'spar_get_user_points_multiplier' ) ) {
		$multiplier = spar_get_user_points_multiplier( $user_id, 'order' );
	}
	$multiplier = (float) apply_filters( 'spar_order_points_multiplier', $multiplier, (int) $user_id, (int) $order->get_id() );

	$points_raw = ( (int) $base_points + (int) $fixed_points ) * $multiplier;
	$points = (int) apply_filters( 'spar_order_awarded_points', spar_round_points( $points_raw ), (int) $order->get_id(), (int) $user_id, (int) $base_points, (int) $fixed_points, (float) $multiplier );

	if ( function_exists( 'sparp_cr_calculate_conditionally_adjusted_points__premium_only' ) ) {
		$calc = sparp_cr_calculate_conditionally_adjusted_points__premium_only( (int) $base_points, (int) $fixed_points, (float) $multiplier, (int) $user_id, 'order', $order );
		if ( is_array( $calc ) && isset( $calc['adjusted'] ) ) {
			$points = max( 0, (int) $calc['adjusted'] );
		}
	}

	// Apply the same final-calculation filter as spar_calculate_order_award_points()
	// so previews match what will actually be awarded (e.g. subscription renewal rates).
	$calculation = apply_filters(
		'spar_order_award_points_calculation',
		array(
			'points'       => max( 0, (int) $points ),
			'base_points'  => (int) $base_points,
			'fixed_points' => (int) $fixed_points,
			'multiplier'   => (float) $multiplier,
		),
		$order,
		(int) $user_id
	);

	return max( 0, (int) ( $calculation['points'] ?? 0 ) );
}

/**
 * Whether WooCommerce HPOS (custom orders table) is the authoritative order storage.
 *
 * Use this before querying the wc_orders tables directly — on stores still using
 * legacy post storage those tables can be empty or stale, so direct COUNT queries
 * must target the posts tables instead.
 *
 * @return bool
 */
function spar_hpos_orders_table_enabled() {
	if ( ! function_exists( 'wc_get_container' ) || ! class_exists( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class ) ) {
		return false;
	}

	try {
		return (bool) wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled();
	} catch ( \Throwable $e ) {
		return false;
	}
}