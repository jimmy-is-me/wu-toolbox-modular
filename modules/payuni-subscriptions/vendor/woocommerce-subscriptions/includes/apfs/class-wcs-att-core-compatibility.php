<?php
/**
 * WCS_ATT_Core_Compatibility class
 *
 * @package  WooCommerce All Products for Subscriptions
 * @since    APFS 1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WC Core compatibility functions.
 *
 * @class    WCS_ATT_Core_Compatibility
 * @version  6.0.0
 */
class WCS_ATT_Core_Compatibility {

	/**
	 * Cache 'gte' comparison results.
	 *
	 * @var array
	 */
	private static $is_wc_version_gte = array();


	/**
	 * Current REST request stack.
	 * An array containing WP_REST_Request instances.
	 *
	 * @since APFS 5.0.3
	 *
	 * @var array
	 */
	private static $requests = array();

	/**
	 * Constructor.
	 */
	public static function init() {
		// Save current rest request. Is there a better way to get it?
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'save_rest_request' ), 10, 3 );
		add_filter( 'woocommerce_hydration_dispatch_request', array( __CLASS__, 'save_hydration_request' ), 10, 2 );
		add_filter( 'rest_request_after_callbacks', array( __CLASS__, 'pop_rest_request' ), PHP_INT_MAX );
		add_filter( 'woocommerce_hydration_request_after_callbacks', array( __CLASS__, 'pop_rest_request' ), PHP_INT_MAX );
	}

	/*
	|--------------------------------------------------------------------------
	| Callbacks.
	|--------------------------------------------------------------------------
	*/

	/**
	 * Pops the current request from the execution stack.
	 *
	 * @since  APFS 5.0.3
	 *
	 * @param  WP_REST_Response     $response
	 * @param  WP_REST_Server|array $handler
	 * @param  WP_REST_Request      $request
	 * @return mixed
	 */
	public static function pop_rest_request( $response ) {
		if ( ! empty( self::$requests ) && is_array( self::$requests ) ) {
			array_pop( self::$requests );
		}

		return $response;
	}

	/**
	 * Saves the current hydration request.
	 *
	 * @since  APFS 5.0.3
	 *
	 * @param  mixed           $result
	 * @param  WP_REST_Request $request
	 * @return mixed
	 */
	public static function save_hydration_request( $result, $request ) {
		if ( ! is_array( self::$requests ) ) {
			self::$requests = array();
		}

		self::$requests[] = $request;
		return $result;
	}

	/**
	 * Saves the current rest request.
	 *
	 * @since  APFS 3.3.2
	 *
	 * @param  mixed           $result
	 * @param  WP_REST_Server  $server
	 * @param  WP_REST_Request $request
	 * @return mixed
	 */
	public static function save_rest_request( $result, $server, $request ) {
		if ( ! is_array( self::$requests ) ) {
			self::$requests = array();
		}

		self::$requests[] = $request;
		return $result;
	}

	/*
	|--------------------------------------------------------------------------
	| WC version getters.
	|--------------------------------------------------------------------------
	*/

	/**
	 * Helper method to get the version of the currently installed WooCommerce
	 *
	 * @since  APFS 1.0.0
	 * @return string woocommerce version number or null
	 */
	private static function get_wc_version() {
		return defined( 'WC_VERSION' ) && WC_VERSION ? WC_VERSION : null;
	}

	/**
	 * Returns true if the installed version of WooCommerce is greater than or equal to $version.
	 *
	 * @since  APFS 2.0.0
	 *
	 * @param  string $version
	 * @return boolean
	 */
	public static function is_wc_version_gte( $version ) {
		if ( ! isset( self::$is_wc_version_gte[ $version ] ) ) {
			self::$is_wc_version_gte[ $version ] = self::get_wc_version() && version_compare( self::get_wc_version(), $version, '>=' );
		}
		return self::$is_wc_version_gte[ $version ];
	}

	/*
	|--------------------------------------------------------------------------
	| Utilities.
	|--------------------------------------------------------------------------
	*/

	/**
	 * Wrapper for 'get_parent_id' with fallback to 'get_id'.
	 *
	 * @since  APFS 2.0.0
	 *
	 * @param  WC_Product $product
	 * @return mixed
	 */
	public static function get_product_id( $product ) {
		$parent_id = $product->get_parent_id();
		return $parent_id ? $parent_id : $product->get_id();
	}

	/**
	 * Wrapper for 'WC_Product_Factory::get_product_type'.
	 *
	 * @since  APFS 2.0.0
	 *
	 * @param  mixed $product_id
	 * @return mixed
	 */
	public static function get_product_type( $product_id ) {
		$product_type = false;
		if ( $product_id ) {
			$product_type = WC_Product_Factory::get_product_type( $product_id );
		}
		return $product_type;
	}

	/**
	 * Get formatted screen id.
	 *
	 * Returns the given screen id unchanged. This was a correct shim when introduced
	 * (APFS 3.1.20, Nov 2020): WC built its admin page hooks from the translated
	 * 'WooCommerce' menu title, so this method translated the 'woocommerce_' prefix
	 * to match. WooCommerce 7.3.0 pinned the hook name to the untranslated
	 * 'woocommerce' prefix (see WC_Admin_Menus::admin_menu() and
	 * https://github.com/woocommerce/woocommerce/issues/35677) — below the minimum
	 * WooCommerce version Subscriptions supports — so screen ids like
	 * 'woocommerce_page_wc-settings' are locale-independent and can be compared
	 * directly, which is what the plugin now does everywhere.
	 *
	 * @since  APFS 3.1.20
	 * @deprecated 9.1.0 Compare against the literal screen id directly. Kept as an identity for backwards compatibility.
	 *
	 * @param  string $screen_id
	 * @return string
	 */
	public static function get_formatted_screen_id( $screen_id ) {
		wc_deprecated_function( __METHOD__, '9.1.0' );
		return $screen_id;
	}


	/**
	 * Returns the current Store/REST API request or false.
	 *
	 * @since  APFS 3.3.2
	 *
	 * @return WP_REST_Request|false
	 */
	public static function get_api_request() {
		if ( empty( self::$requests ) || ! is_array( self::$requests ) ) {
			return false;
		}

		return end( self::$requests );
	}

	/**
	 * Whether this is a Store API request.
	 *
	 * @since  APFS 3.3.2
	 *
	 * @param  string $route
	 * @return boolean
	 */
	public static function is_store_api_request( $route = '' ) {

		// Check the request URI.
		$request = self::get_api_request();

		if ( false !== $request && strpos( $request->get_route(), 'wc/store' ) !== false ) {
			if ( '' === $route || strpos( $request->get_route(), $route ) !== false ) {
				return true;
			}
		}

		return false;
	}
}
