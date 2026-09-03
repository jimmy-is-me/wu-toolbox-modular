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
	 *
	 * The callback can only handle the following states:
	 * payment status   : reserved
	 * -> request type  : confirm, cancel
	 *
	 * Payment status   : confirmed
	 *  -> request type : refund
	 *
	 * If it cannot be processed, an error log is left.
	 *
	 * @see woocommerce::action - woocommerce_api_
	 * @throws Exception Throws exception when order id is not found.
	 */
	public function receive_payment_response() {

		try {

			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			$order_id = ( isset( $_GET['order_id'] ) ) ? sanitize_text_field( wp_unslash( $_GET['order_id'] ) ) : '';

			$order = wc_get_order( $order_id );

			if ( ! $order ) {
				throw new Exception( sprintf( WPBR_LINEPay_Const::LOG_TEMPLATE_HANDLE_CALLBANK_NOT_FOUND_ORDER_ID, $order_id, __( 'Unable to process callback.', 'wpbr-linepay-tw' ) ) );
			}

			$request_type   = ( isset( $_GET['request_type'] ) ) ? sanitize_text_field( wp_unslash( $_GET['request_type'] ) ) : '';
			$payment_status = $order->get_meta( '_linepay_payment_status' );

			$gateway = new LINEPay_TW_Payment();
			$request = new LINEPay_TW_Request( $gateway );

			if ( WPBR_LINEPay_Const::PAYMENT_STATUS_RESERVED === $payment_status ) {

				switch ( $request_type ) {
					case WPBR_LINEPay_Const::REQUEST_TYPE_CONFIRM:
						$request->confirm( $order_id );
						break;
					case WPBR_LINEPay_Const::REQUEST_TYPE_CANCEL:
						$request->cancel( $order_id );
						break;
				}
			} else {
				LINEPay_TW::log( sprintf( 'invalid status: %s to handle callback for order id: %s', $payment_status, $order_id ) );
			}
		} catch ( Exception $e ) {
			LINEPay_TW::log( 'receive_payment_response error: ' . $e->getMessage() );
		}
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
