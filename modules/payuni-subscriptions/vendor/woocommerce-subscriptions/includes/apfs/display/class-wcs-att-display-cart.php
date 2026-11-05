<?php
/**
 * WCS_ATT_Display_Cart class
 *
 * @package  WooCommerce All Products for Subscriptions
 * @since    APFS 1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cart template modifications.
 *
 * @class    WCS_ATT_Display_Cart
 * @version  6.0.0
 */
class WCS_ATT_Display_Cart {

	/**
	 * Runtime cache.
	 *
	 * @var bool
	 */
	private static $display_prices_incl_tax;

	/**
	 * Initialize.
	 */
	public static function init() {
		self::add_hooks();
	}

	/**
	 * Hook-in.
	 */
	private static function add_hooks() {

		// Use radio buttons to mark a cart item as a one-time sale or as a subscription.
		add_filter( 'woocommerce_cart_item_price', array( __CLASS__, 'show_cart_item_subscription_options' ), 1000, 3 );
	}

	/*
	|--------------------------------------------------------------------------
	| Functions
	|--------------------------------------------------------------------------
	*/

	/**
	 * Back-compat wrapper for 'WC_Cart::display_price_including_tax'.
	 *
	 * @since  APFS 3.1.15
	 *
	 * @return string
	 */
	public static function display_prices_including_tax() {

		if ( is_null( self::$display_prices_incl_tax ) ) {
			self::$display_prices_incl_tax = WC()->cart->display_prices_including_tax();
		}

		return self::$display_prices_incl_tax;
	}

	/*
	|--------------------------------------------------------------------------
	| Filters
	|--------------------------------------------------------------------------
	*/

	/**
	 * Previously rendered an in-cart plan switcher in the cart item Price column.
	 *
	 * As of WOOSUBS-1738 the classic cart & checkout match the block presentation, which deliberately does not allow
	 * changing a line item's subscription plan in the cart — they simply reflect the plan chosen on the product page.
	 * The switcher is therefore no longer rendered and the price is returned unchanged.
	 *
	 * This callback stays registered on 'woocommerce_cart_item_price' (prio 1000) so integrations that detect it — e.g.
	 * WCS_ATT_Integration_PB_CP's container price formatting, which calls has_filter() for this method — keep working.
	 *
	 * @param  string $price
	 * @param  array  $cart_item
	 * @param  string $cart_item_key
	 * @return string
	 */
	public static function show_cart_item_subscription_options( $price, $cart_item, $cart_item_key ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return $price;
	}
}
