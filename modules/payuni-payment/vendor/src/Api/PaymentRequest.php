<?php
/**
 * PaymentRequest class file
 *
 * @package payuni
 */

namespace WPBrewer\Payuni\Payment\Api;

use WPBrewer\Payuni\Payment\PayuniPayment;
use WPBrewer\Payuni\Payment\Utils\CloseStatus;
use WPBrewer\Payuni\Payment\Utils\OrderMeta;
use WPBrewer\Payuni\Payment\Utils\TradeStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Generates payment form and redirect to PAYUNi
 */
class PaymentRequest {

	/**
	 * The gateway instance
	 *
	 * @var \WC_Payment_Gateway
	 */
	protected $gateway;

	/**
	 * Build transaction args.
	 *
	 * @param  WC_Order $order The order object.
	 * @return array
	 */
	public function get_transaction_args( $order ) {

		$prod_desc = array();
		$items     = $order->get_items();
		foreach ( $items as $item ) {
			$prod_desc[] = $item->get_name() . ' * ' . $item->get_quantity();
		}

		$encrypt_info = apply_filters(
			'payuni_upp_transaction_args_' . $this->gateway->id,
			array(
				'MerID'        => $this->gateway->get_merchant_id(),
				'MerTradeNo'   => PayuniPayment::build_payuni_order_no( $order->get_id() ),
				'TradeAmt'     => (int) $order->get_total(),
				'ProdDesc'     => implode( ';', $prod_desc ),
				'ReturnURL'    => $this->gateway->return_url, // 前景通知網址付款完成返回指定網址 (感謝頁面).
				'NotifyURL'    => $this->gateway->notify_url, // 幕後.
				'UsrMail'      => $order->get_billing_email(), // 付款頁帶入 email.
				'UsrMailFix'   => '1', // 不可修改 email.
				'Timestamp'    => time(),
				'Lang'         => get_option( 'payuni_payment_language', 'zh-tw' ),
			),
			$order
		);

		if ( PayuniPayment::$einvoice_enabled ) {
			$encrypt_info['TradeInvoice'] = 1;
		}

		$encrypt_info = apply_filters( 'payuni_upp_transaction_args_data', $encrypt_info, $order );

		PayuniPayment::log( 'request encrypt info:' . wc_print_r( $encrypt_info, true ) );

		$encrypted_info = PayuniPayment::encrypt( $encrypt_info );

		$args = array(
			'MerID'       => $this->gateway->get_merchant_id(),
			'Version'     => '1.0',
			'EncryptInfo' => $encrypted_info,
			'HashInfo'    => PayuniPayment::hash_info( $encrypted_info ),
		);

		return $args;
	}

	/**
	 * Generate the form and redirect to PAYUNi
	 *
	 * @param  WC_Order $order The order object.
	 * @return void
	 */
	public function build_request_form( $order ) {

		$order = wc_get_order( $order );

		try {
			?>
			<div><?php esc_html_e( 'Redirecting...', 'wpbr-payuni-payment' ); ?></div>
			<form method="post" id="payuni-form" action="<?php echo esc_url( $this->gateway->get_api_url() ); ?>" accept="UTF-8" accept-charset="UTF-8">
			<?php
			$fields = $this->get_transaction_args( $order );

			PayuniPayment::log( 'request transaction args:' . wc_print_r( $fields, true ) );

			foreach ( $fields as $key => $value ) {
				echo '<input type="hidden" name="' . esc_html( $key ) . '" value="' . esc_html( $value ) . '">';
			}
			?>
			</form>
			<?php

		} catch ( \Exception $e ) {
			PayuniPayment::log( $e->getMessage() . ' ' . $e->getTraceAsString() );
		}
	}

	/**
	 * Process refund request
	 *
	 * @param  int    $order_id The order id.
	 * @param  float  $amount   The refund amount.
	 * @param  string $reason   The refund reason.
	 *
	 * @return bool|\WP_Error
	 * @throws \Exception When the refund failed.
	 */
	public function refund( $order_id, $amount, $reason ) {
		$order = wc_get_order( $order_id );

		if ( false === $order ) {
			return new \WP_Error(
				'process_refund_request',
				/* translators:  %s is the order id */
				sprintf( __( 'Unable to find order #%s', 'wpbr-payuni-payment' ), $order_id ),
				array(
					'order_id'      => $order_id,
					'refund_amount' => $amount,
				)
			);
		}

		$payment_method           = $order->get_payment_method();
		$allowed_install_payments = PayuniPayment::get_allowed_install_payments( $order );
		if ( array_key_exists( $payment_method, $allowed_install_payments ) ) {
			if ( $order->get_total() !== $amount ) {
				return new \WP_Error(
					'process_refund_request',
					/* translators:  %s is the order id */
					sprintf( __( 'The refund amount for order #%s should be the same as the order total for installment payment.', 'wpbr-payuni-payment' ), $order_id ),
					array(
						'order_id' => $order_id,
					)
				);
			}
		}

		$test_mode      = wc_string_to_bool( get_option( 'payuni_payment_testmode_enabled' ) );
		$mer_id         = $test_mode ? get_option( 'payuni_payment_merchant_id_test' ) : get_option( 'payuni_payment_merchant_id' );
		$transaction_id = $order->get_transaction_id();

		if ( empty( $transaction_id ) ) {
			return new \WP_Error(
				'process_refund_request',
				/* translators:  %s is the order id */
				sprintf( __( 'Unable to find transaction id for order #%s', 'wpbr-payuni-payment' ), $order_id ),
				array(
					'order_id'      => $order_id,
					'refund_amount' => $amount,
				)
			);
		}

		$query_result = self::query( $order_id, false );
		if ( false === $query_result ) {
			return new \WP_Error(
				'process_refund_request',
				/* translators:  %s is the order id */
				sprintf( __( 'Unable to Query Order status before refund', 'wpbr-payuni-payment' ), $order_id ),
				array(
					'order_id'      => $order_id,
					'refund_amount' => $amount,
				)
			);
		}

		$trade_status = $query_result['TradeStatus'];

		if ( TradeStatus::PAID === $trade_status ) {

			// 請款狀態.
			$close_status = $query_result['CloseStatus'];
			// 只有在請款成功時，才能退款.
			if ( self::is_refundable( $close_status ) ) {
				// 2=請款成功
				$encrypt_info = array(
					'MerID'     => $mer_id,
					'TradeNo'   => $transaction_id,
					'Timestamp' => time(),
					'CloseType' => 2, // 2=退款.
					'TradeAmt'  => $amount,
				);
				$url          = ( wc_string_to_bool( get_option( 'payuni_payment_testmode_enabled' ) ) ) ? 'https://sandbox-api.payuni.com.tw/api/trade/close' : 'https://api.payuni.com.tw/api/trade/close';
			} else {
				$order->add_order_note( sprintf( __( 'CloseStatus is not refundable. CloseStatus: %s', 'wpbr-payuni-payment' ), $close_status ) );
				return new \WP_Error(
					'process_refund_request',
					/* translators:  %s is the TradeStatus of the order */
					sprintf( __( 'Unable to Refund this Order. TradeStatus: %1$s, CloseStatus: %2$s', 'wpbr-payuni-payment' ), $trade_status, $close_status ),
					array(
						'order_id'      => $order_id,
						'refund_amount' => $amount,
					)
				);
			}	

			PayuniPayment::log( 'refund url:' . $url );
		} else {
			return new \WP_Error(
				'process_refund_request',
				/* translators:  %s is the TradeStatus of the order */
				sprintf( __( 'Unable to Refund this Order. TradeStatus:%s', 'wpbr-payuni-payment' ), $trade_status ),
				array(
					'order_id'      => $order_id,
					'refund_amount' => $amount,
				)
			);
		}

		PayuniPayment::log( 'encrypt_info:' . wc_print_r( $encrypt_info, true ) );

		$encrypted_info = PayuniPayment::encrypt( $encrypt_info );
		$form_data      = array(
			'MerID'       => $mer_id,
			'Version'     => '1.0',
			'EncryptInfo' => $encrypted_info,
			'HashInfo'    => PayuniPayment::hash_info( $encrypted_info ),
		);
		PayuniPayment::log( 'form data:' . wc_print_r( $form_data, true ) );

		$request_args = array(
			'httpversion' => '1.1',
			'timeout'     => '30',
			'body'        => $form_data,
		);

		$response = wp_remote_post( $url, $request_args );
		if ( is_wp_error( $response ) ) {
			PayuniPayment::log( 'refund error:' . $response->get_error_message() );
			return false;
		}

		$response_body = wp_remote_retrieve_body( $response );
		PayuniPayment::log( 'refund response body:' . wc_print_r( $response_body, true ) );

		$result    = json_decode( $response_body, true );
		$decrypted = PayuniPayment::decrypt( $result['EncryptInfo'] );
		if ( 'SUCCESS' === $result['Status'] ) {
			/* translators: %1$s is the status, %2$s is the message, %3$s is the refund amount */
			$order->add_order_note( sprintf( __( 'PAYUNi payment refund success. Status: %1$s, Message: %2$s, Refund Amount: %3$s', 'wpbr-payuni-payment' ), $result['Status'], $decrypted['Message'], $amount ) );
			return true;
		} else {
			$order->add_order_note( 'PAYUNi payment refund failed. Status:' . $result['Status'] . ', Message:' . $decrypted['Message'] );
			throw new \Exception( 'PAYUNi refund failed. Status:' . esc_html( $result['Status'] ) );
		}
	}//end refund()

	/**
	 * Query payment status and result
	 *
	 * @param int  $order_id The order id.
	 * @param bool $add_note Add order note.
	 */
	public static function query( $order_id, $add_note = true ) {

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			PayuniPayment::log( 'PAYUNi query faied. No such order_id:' . $order_id );
			return false;
		}

		$test_mode = wc_string_to_bool( get_option( 'payuni_payment_testmode_enabled' ) );
		$mer_id    = $test_mode ? get_option( 'payuni_payment_merchant_id_test' ) : get_option( 'payuni_payment_merchant_id' );

		$payuni_order_no_key = PayuniPayment::get_order_meta_key( $order, OrderMeta::PAYUNI_ORDER_NO );
		$payuni_order_no     = $order->get_meta( $payuni_order_no_key );

		$encrypt_info    = array(
			'MerID'      => $mer_id,
			'MerTradeNo' => $payuni_order_no,
			'Timestamp'  => time(),
		);
		PayuniPayment::log( 'query encrypt info:' . wc_print_r( $encrypt_info, true ) );

		$encrypted_info = PayuniPayment::encrypt( $encrypt_info );

		// Set the form data as an array
		$form_data = array(
			'MerID'       => $mer_id,
			'Version'     => '2.0',
			'EncryptInfo' => $encrypted_info,
			'HashInfo'    => PayuniPayment::hash_info( $encrypted_info ),
		);

		$request_args = array(
			'httpversion' => '1.1',
			'timeout'     => '30',
			'body'        => $form_data,
		);

		$url = ( $test_mode ) ? 'https://sandbox-api.payuni.com.tw/api/trade/query' : 'https://api.payuni.com.tw/api/trade/query';
		PayuniPayment::log( 'query url:' . $url );
		$response = wp_remote_post( $url, $request_args );

		if ( is_wp_error( $response ) ) {
			PayuniPayment::log( 'query error:' . $response->get_error_message() );
			return false;
		}

		$response_body = wp_remote_retrieve_body( $response );
		PayuniPayment::log( 'query response body:' . wc_print_r( $response_body, true ) );

		$result    = json_decode( $response_body, true );
		$decrypted = PayuniPayment::decrypt( $result['EncryptInfo'] );
		PayuniPayment::log( 'query decrypted info:' . wc_print_r( $decrypted, true ) );

		if ( 'SUCCESS' === $result['Status'] ) {
			// Check if Result array exists and has data
			if ( ! isset( $decrypted['Result'] ) || ! is_array( $decrypted['Result'] ) || empty( $decrypted['Result'] ) ) {
				PayuniPayment::log( 'PAYUNi query returned SUCCESS but no Result data found.' );
				if ( $add_note ) {
					$order->add_order_note( __( 'PAYUNi query returned SUCCESS but no order result found.', 'wpbr-payuni-payment' ) );
				}
				return false;
			}

			$query_result                = array();
			$query_result['MerTradeNo']  = $decrypted['Result'][0]['MerTradeNo'];
			$query_result['TradeNo']     = $decrypted['Result'][0]['TradeNo'];
			$query_result['TradeStatus'] = $decrypted['Result'][0]['TradeStatus'];
			$query_result['PaymentDay']  = $decrypted['Result'][0]['PaymentDay'];
			$query_result['CreateDay']   = $decrypted['Result'][0]['CreateDay'];
			$query_result['PaymentType'] = $decrypted['Result'][0]['PaymentType'];
			
			if ( isset( $decrypted['Result'][0]['TradeAmt'] ) ) {
				$query_result['TradeAmt'] = $decrypted['Result'][0]['TradeAmt'];
			}

			// 信用卡.
			if ( '1' === $query_result['PaymentType'] ) {
				$query_result['CloseStatus'] = $decrypted['Result'][0]['CloseStatus'];
			}

			// 線下交易 (ATM, CVS 等).
			if ( isset( $decrypted['Result'][0]['OffChannel'] ) ) {
				$query_result['OffChannel'] = $decrypted['Result'][0]['OffChannel'];
			}
			if ( isset( $decrypted['Result'][0]['OffPayChannel'] ) ) {
				$query_result['OffPayChannel'] = $decrypted['Result'][0]['OffPayChannel'];
			}
			if ( isset( $decrypted['Result'][0]['OffPayNo'] ) ) {
				$query_result['OffPayNo'] = $decrypted['Result'][0]['OffPayNo'];
			}
			if ( isset( $decrypted['Result'][0]['OffExpireTime'] ) ) {
				$query_result['OffExpireTime'] = $decrypted['Result'][0]['OffExpireTime'];
			}

			$woo_order_id = PayuniPayment::parse_payuni_order_no_to_woo_order_id( $query_result['MerTradeNo'] );

			if ( $add_note ) {
				$order = wc_get_order( $woo_order_id );
				/* translators:  %s is the decrypted result */
				$order->add_order_note( sprintf( __( 'PAYUNi query succeed. Query result: %s', 'wpbr-payuni-payment' ), wc_print_r( $decrypted, true ) ) );
			}
			PayuniPayment::log( 'PAYUNi query success. Status:' . $result['Status'] . ', Message:' . $decrypted['Message'] . ', Trade Status:' . $query_result['TradeStatus'] );
			return $query_result;
		} else {
			if ( $add_note ) {
				/* translators:  %s is the decrypted result */
				$order->add_order_note( sprintf( __( 'PAYUNi query failed. Query result: %s', 'wpbr-payuni-payment' ), wc_print_r( $decrypted, true ) ) );
			}
			PayuniPayment::log( 'PAYUNi query failed. Status:' . $result['Status'] . ', Message:' . $decrypted['Message'] );
			return false;
		}
	}

	/**
	 * Check if the payment transaction auth is cancellable (取消授權)
	 *
	 * @param  int $close_status The close status of the order transaction.
	 * @return bool
	 */
	private static function is_cancellable( $close_status ) {
		if ( CloseStatus::CAPTURE_APPLING === $close_status || CloseStatus::CAPTURE_CANCELLED === $close_status || CloseStatus::CAPTURE_UNAPPLY === $close_status ) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * Check if the payment transaction is closeable (退款)
	 *
	 * @param  int $close_status The close status of the order transaction.
	 * @return bool
	 */
	private static function is_refundable( $close_status ) {
		if ( CloseStatus::CAPTURE_OK === $close_status ) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * Set payment gateway instance
	 *
	 * @param WC_Payment_Gateway $gateway The payment gateway instance.
	 */
	public function set_gateway( $gateway ) {
		$this->gateway = $gateway;
	}
}
