<?php
/**
 * Shared payment-method meta handling for the subscription REST controllers.
 *
 * @package WooCommerce Subscriptions
 * @since   9.1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds a subscription's payment-method meta from the fields a gateway declares.
 *
 * Shared by the v2 and v3 subscription REST controllers, which extend different WC order controller
 * bases and so have no common subscription ancestor to host this. Kept out of the global function
 * namespace deliberately - it is an internal detail of these controllers, not a store-wide API.
 *
 * @since 9.1.0
 */
trait WCS_REST_Payment_Method_Meta {

	/**
	 * Overlay request-supplied values onto the payment-method fields a gateway declares.
	 *
	 * Only the table/meta_key slots the gateway declares via the 'woocommerce_subscription_payment_meta'
	 * filter are populated; a value for any other key the request names is ignored. Malformed gateway
	 * declarations - a non-array method entry, table, or field - are left untouched rather than written
	 * to, so a request value can never turn one into a fatal. Mirrors the handling the v1 controller and
	 * the admin metabox already apply.
	 *
	 * Returns an empty array when the request supplies no payment meta, leaving the gateways unqueried.
	 *
	 * @since 9.1.0
	 *
	 * @param WC_Subscription $subscription   The subscription being updated.
	 * @param string          $payment_method The payment method (gateway) id.
	 * @param mixed           $requested_meta The request-supplied meta, keyed [ table ][ meta_key ] => value.
	 * @return array Payment method meta in the { table => { meta_key => array( 'value' => ... ) } } shape gateways expect.
	 */
	protected function build_gateway_payment_method_meta( $subscription, $payment_method, $requested_meta ) {
		// A request that supplies no payment meta has nothing to overlay, so there is no reason to ask the
		// gateways what they declare. Returning first also keeps this out of the way on create, where the
		// subscription has not been saved yet and would reach the filter with an ID of 0.
		if ( ! is_array( $requested_meta ) || empty( $requested_meta ) ) {
			return array();
		}

		$gateway_payment_meta = apply_filters( 'woocommerce_subscription_payment_meta', array(), $subscription );
		$payment_method_meta  = ( is_array( $gateway_payment_meta ) && isset( $gateway_payment_meta[ $payment_method ] ) && is_array( $gateway_payment_meta[ $payment_method ] ) )
			? $gateway_payment_meta[ $payment_method ]
			: array();

		foreach ( $payment_method_meta as $table => &$meta ) {
			if ( ! is_array( $meta ) ) {
				continue;
			}

			foreach ( $meta as $meta_key => &$meta_data ) {
				if ( ! is_array( $meta_data ) ) {
					continue;
				}

				if ( isset( $requested_meta[ $table ][ $meta_key ] ) ) {
					$meta_data['value'] = $requested_meta[ $table ][ $meta_key ];
				}
			}
		}
		unset( $meta, $meta_data );

		return $payment_method_meta;
	}
}
