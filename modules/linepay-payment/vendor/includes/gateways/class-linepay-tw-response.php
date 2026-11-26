<?php
/**
 * LINEPay_TW_Response class file
 *
 * @package linepay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Receive response from LINE Pay.
 */
class LINEPay_TW_Response {

	/**
	 * Class instance
	 *
	 * @var LINEPay_TW_Response
	 */
	private static $instance;

	/**
	 * Init the instance
	 *
	 * @return void
	 */
	public static function init() {
		self::get_instance();

		// handling callback from LINE Pay.
		add_action( 'woocommerce_api_linepay_payment', array( self::get_instance(), 'receive_payment_response' ) );
	}

	/**
	 * Verify the two values issued for this specific WooCommerce order.
	 *
	 * Treat credentials and the provider transaction ID as exact strings.
	 *
	 * @param array $query Callback parameters, already unslashed.
	 * @return WC_Order|null
	 */
	private static function validated_order( array $query ) {
		$order_id       = $query['order_id'] ?? '';
		$request_type   = $query['request_type'] ?? '';
		$order_key      = $query['key'] ?? '';
		$transaction_id = $query['transactionId'] ?? '';

		if ( ! is_string( $order_id ) || ! ctype_digit( $order_id ) ||
			! in_array( $request_type, array( WPBR_LINEPay_Const::REQUEST_TYPE_CONFIRM, WPBR_LINEPay_Const::REQUEST_TYPE_CANCEL ), true ) ||
			! is_string( $order_key ) || '' === $order_key ||
			! is_string( $transaction_id ) || ! ctype_digit( $transaction_id ) ) {
			return null;
		}

		$id = filter_var( $order_id, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
		$order = $id ? wc_get_order( $id ) : false;
		if ( ! $order instanceof WC_Order || WPBR_LINEPay_Const::ID !== $order->get_payment_method() ||
			! hash_equals( (string) $order->get_order_key(), $order_key ) ||
			! hash_equals( (string) $order->get_meta( '_linepay_reserved_transaction_id' ), $transaction_id ) ) {
			return null;
		}

		return $order;
	}

	/**
	 * Handle a verified return from LINE Pay.
	 *
	 * @return void
	 */
	public function receive_payment_response() {
		nocache_headers();
		header( 'Referrer-Policy: no-referrer' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- External callbacks use the two stored credentials.
		$query = wp_unslash( $_GET );
		$order = ( $_SERVER['REQUEST_METHOD'] ?? '' ) === 'GET' ? self::validated_order( $query ) : null;

		if ( ! $order ) {
			LINEPay_TW::log( '[callback] Verification failed.' );
			self::reject();
		}

		$payment_status = $order->get_meta( '_linepay_payment_status' );
		if ( $order->is_paid() && WPBR_LINEPay_Const::PAYMENT_STATUS_CONFIRMED === $payment_status ) {
			wp_safe_redirect( $order->get_checkout_order_received_url() );
			exit;
		}

		if ( ! LINEPay_TW::order_awaits_payment( $order ) || WPBR_LINEPay_Const::PAYMENT_STATUS_RESERVED !== $payment_status ) {
			self::reject();
		}

		try {
			$request = new LINEPay_TW_Request( new LINEPay_TW_Payment() );
			if ( WPBR_LINEPay_Const::REQUEST_TYPE_CONFIRM === $query['request_type'] ) {
				$request->confirm( $order->get_id() );
			} else {
				$request->cancel( $order->get_id() );
			}
		} catch ( Exception $e ) {
			LINEPay_TW::log( '[callback] Unable to process the verified payment return.', 'error' );
		}

		self::reject();
	}

	/**
	 * Send the same generic response for every rejected callback, so it neither leaks
	 * the order key nor reveals whether an order exists or what state it is in.
	 *
	 * @return void
	 */
	private static function reject() {
		wp_safe_redirect( wc_get_cart_url() );
		exit;
	}

	/**
	 * Get instance
	 *
	 * @return LINEPay_TW_Response
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}
}
