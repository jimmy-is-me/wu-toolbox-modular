<?php
/**
 * A Renewal Cart Stock Manager class.
 *
 * Contains functions which assists in overriding WC core functionality to allow renewal carts to bypass stock validation.
 *
 * @package  WooCommerce Subscriptions
 * @category Class
 * @author   Prospress
 * @since    1.0.0 - Migrated from WooCommerce Subscriptions v2.6.0
 */

defined( 'ABSPATH' ) || exit;

class WCS_Renewal_Cart_Stock_Manager {

	/**
	 * Bootstraps the class and hooks required actions & filters.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.6.0
	 */
	public static function attach_callbacks() {
		add_action( 'wcs_before_renewal_setup_cart_subscription', array( get_called_class(), 'maybe_adjust_stock_cart' ), 10, 2 );
		add_action( 'woocommerce_check_cart_items', array( get_called_class(), 'maybe_adjust_stock_checkout' ), 0 );
		// The Store API validates cart item stock before 'woocommerce_check_cart_items' runs, so the
		// overrides have to be in place from the moment the cart is loaded for the block checkout.
		add_action( 'woocommerce_cart_loaded_from_session', array( get_called_class(), 'maybe_adjust_stock_checkout' ), 10 );
		add_action( 'woocommerce_checkout_create_order', array( get_called_class(), 'remove_filters' ) );
		add_action( 'woocommerce_check_cart_items', array( get_called_class(), 'remove_filters' ), 20 );
		add_filter( 'woocommerce_order_hold_stock_minutes', array( get_called_class(), 'maybe_skip_stock_reservation' ), 10, 2 );
	}

	/**
	 * Attaches filters that allow a manual renewal to add to the cart an otherwise out of stock product.
	 *
	 * Hooked onto 'wcs_before_renewal_setup_cart_subscription'.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.6.0
	 *
	 * @param WC_Subscription $subscription The subscription object. This param is unused. It is the first parameter of the hook.
	 * @param WC_Order $order               The renewal order object.
	 */
	public static function maybe_adjust_stock_cart( $subscription, $order ) {
		static::maybe_attach_stock_filters( $order );
	}

	/**
	 * Attaches filters that allow manual renewal carts to pass checkout validity checks for an otherwise out of stock product.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.6.0
	 */
	public static function maybe_adjust_stock_checkout() {
		$renewal_order = static::get_order_from_cart();

		// Get the order from query vars if the cart isn't loaded yet.
		if ( ! $renewal_order ) {
			$renewal_order = static::get_order_from_query_vars();
		}

		if ( $renewal_order ) {
			static::maybe_attach_stock_filters( $renewal_order );
		}
	}

	/**
	 * Attaches stock override filters for out of stock renewal products.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.6.0
	 * @param WC_Order $order Renewal order.
	 */
	protected static function maybe_attach_stock_filters( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( static::order_requires_stock_override( $order ) ) {
			add_filter( 'woocommerce_product_is_in_stock', array( get_called_class(), 'adjust_is_in_stock' ), 10, 2 );
			add_filter( 'woocommerce_product_backorders_allowed', array( get_called_class(), 'adjust_backorder_status' ), 10, 3 );
		}
	}

	/**
	 * Determines if an order contains a product without enough stock to cover it.
	 *
	 * @since 9.3.0
	 * @param WC_Order $order The order to check.
	 * @return bool Whether any of the order's products is out of stock or short on stock.
	 */
	protected static function order_requires_stock_override( $order ) {
		foreach ( $order->get_items() as $line_item ) {
			$product = $line_item->get_product();

			if ( ! $product ) {
				continue;
			}

			if ( ! $product->is_in_stock() ) {
				return true;
			}

			// Use the stock managed product in case we have a variation product which is managed on the variable (parent level)
			$stock_managed_product = wc_get_product( $product->get_stock_managed_by_id() );

			// A product which doesn't manage stock has no quantity to run short of, and WooCommerce
			// doesn't reserve stock for one which allows backorders.
			if ( ! $stock_managed_product || ! $stock_managed_product->managing_stock() || $product->backorders_allowed() ) {
				continue;
			}

			// Account for stock which is being held by other unpaid orders.
			$held_stock     = ( (int) get_option( 'woocommerce_hold_stock_minutes', 0 ) > 0 ) ? wc_get_held_stock_quantity( $product, $order->get_id() ) : 0;
			$required_stock = wcs_get_total_line_item_product_quantity( $order, $stock_managed_product );

			if ( ( $required_stock + $held_stock ) > $stock_managed_product->get_stock_quantity() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Prevents WooCommerce from reserving stock at checkout for renewal orders which bypass stock validation.
	 *
	 * The stock override filters are removed before WooCommerce reserves stock for the order, and
	 * reserving stock fails for products which aren't in stock. A hold of 0 minutes skips the reservation.
	 *
	 * Hooked onto 'woocommerce_order_hold_stock_minutes'.
	 *
	 * @since 9.3.0
	 * @param int      $minutes The number of minutes to hold stock for.
	 * @param WC_Order $order   The order stock is being reserved for.
	 * @return int The number of minutes to hold stock for.
	 */
	public static function maybe_skip_stock_reservation( $minutes, $order ) {
		if ( $minutes && $order instanceof WC_Order && wcs_order_contains_renewal( $order ) && static::order_requires_stock_override( $order ) ) {
			$minutes = 0;
		}

		return $minutes;
	}

	/**
	 * Adjusts the stock status of a product that is an out-of-stock renewal.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.6.0
	 *
	 * @param bool $is_in_stock   Whether the product is in stock or not
	 * @param WC_Product $product The product which stock is being checked
	 *
	 * @return bool $is_in_stock
	 */
	public static function adjust_is_in_stock( $is_in_stock, $product ) {
		if ( ! $is_in_stock ) {
			$is_in_stock = static::cart_contains_renewal_to_product( $product );
		}

		return $is_in_stock;

	}

	/**
	 * Adjusts whether backorders are allowed so out-of-stock renewal item products bypass stock validation.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.6.0
	 *
	 * @param bool $backorders_allowed  If the product has backorders enabled.
	 * @param int $product_id           The product ID.
	 * @param WC_Product $product       The product on which stock management is being changed.
	 *
	 * @return bool $backorders_allowed Whether backorders are allowed.
	 */
	public static function adjust_backorder_status( $backorders_allowed, $product_id, $product ) {
		if ( ! $backorders_allowed ) {
			$backorders_allowed = static::cart_contains_renewal_to_product( $product );
		}

		return $backorders_allowed;
	}

	/**
	 * Removes the filters that adjust stock on out of stock renewals items.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.6.0
	 */
	public static function remove_filters() {
		remove_filter( 'woocommerce_product_is_in_stock', array( get_called_class(), 'adjust_is_in_stock' ) );
		remove_filter( 'woocommerce_product_backorders_allowed', array( get_called_class(), 'adjust_backorder_status' ) );
	}

	/**
	 * Determines if the cart contains a renewal order with a specific product.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.6.0
	 * @param WC_Product $product The product object to look for.
	 * @return bool               Whether the cart contains a renewal order to the given product.
	 */
	protected static function cart_contains_renewal_to_product( $product ) {
		$cart_contains_renewal_to_product = false;
		$renewal_order = static::get_order_from_cart();

		if ( ! $renewal_order ) {
			$renewal_order = static::get_order_from_query_vars();
		}

		if ( $renewal_order && wcs_order_contains_product( $renewal_order, $product ) ) {
			$cart_contains_renewal_to_product = true;
		}

		return $cart_contains_renewal_to_product;
	}

	/**
	 * Gets the renewal order from the cart.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.6.0
	 * @return WC_Order|bool Renewal order obtained from the cart contents or false if the cart doesn't contain a renewal order.
	 */
	protected static function get_order_from_cart() {
		$renewal_order = false;
		$cart_item     = wcs_cart_contains_renewal();

		if ( false !== $cart_item && isset( $cart_item['subscription_renewal']['renewal_order_id'] ) ) {
			$renewal_order = wc_get_order( $cart_item['subscription_renewal']['renewal_order_id'] );
		}

		return $renewal_order;
	}

	/**
	 * Gets the renewal order from order-pay query vars.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.6.0
	 * @return WC_Order|bool Renewal order obtained from query vars or false if not set.
	 */
	protected static function get_order_from_query_vars() {
		global $wp;
		$renewal_order = false;

		if ( isset( $wp->query_vars['order-pay'] ) ) {
			$order = wc_get_order( $wp->query_vars['order-pay'] );

			if ( wcs_order_contains_renewal( $order ) ) {
				$renewal_order = $order;
			}
		}

		return $renewal_order;
	}
}
