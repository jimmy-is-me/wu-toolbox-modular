<?php
/**
 * LINEPay_TW_Request class file
 *
 * @package linepay_tw
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * LINE Pay payment request related API function.
 */
class LINEPay_TW_Request {

	/**
	 * The gateway instance
	 *
	 * @var WC_Payment_Gateway
	 */
	protected $gateway;

	/**
	 * The constructor
	 *
	 * @param WC_Payment_Gateway $gateway the payment gateway.
	 */
	public function __construct( $gateway ) {
		$this->gateway = $gateway;
		add_action( 'linepay_process_confirm_failed', array( $this, 'on_process_confirm_failed' ), 10, 1 );
	}

	/**
	 * Call LINE Pay's request-api and return the result.
	 * Change the order status according to the api call result.
	 *
	 * Request successful
	 * -post-meta fixes
	 *
	 * Request failed
	 * -fix order_status
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public function request( $order_id ) {

		try {

			$order        = wc_get_order( $order_id );
			$product_info = array( 'packages' => $this->get_product_info( $order ) );
			$order_id     = $order->get_id();
			$currency     = $order->get_currency();
			$std_amount   = $this->get_standardized( $order->get_total(), $currency );

			// Check if the currency is the accuracy of the $amount that can be expressed.
			if ( ! $this->valid_currency_scale( $std_amount ) ) {
				throw new Exception( sprintf( WPBR_LINEPay_Const::LOG_TEMPLATE_RESERVE_UNVALID_CURRENCY_SCALE, $order_id, $std_amount, $currency, $this->get_currency_scale( $currency ), $this->get_amount_precision( $amount ) ) );
			}

			$body = array(
				'orderId'  => $order_id,
				'amount'   => $std_amount,
				'currency' => $currency,
			);

			$redirect_urls = array(
				'redirectUrls' => array(
					'confirmUrl'     => esc_url_raw(
						add_query_arg(
							array(
								'request_type' => WPBR_LINEPay_Const::REQUEST_TYPE_CONFIRM,
								'order_id'     => $order_id,
							),
							WC()->api_request_url( 'linepay_payment' )
						)
					),
					'confirmUrlType' => WPBR_LINEPay_Const::CONFIRM_URLTYPE_CLIENT, // 使用者的畫面跳轉到商家 confirmUrl，完成付款流程.
					'cancelUrl'      => esc_url_raw(
						add_query_arg(
							array(
								'request_type' => WPBR_LINEPay_Const::REQUEST_TYPE_CANCEL,
								'order_id'     => $order_id,
							),
							WC()->api_request_url( 'linepay_payment' )
						)
					),
				),
			);

			$options = array(
				'options' => array(
					'payment' => array(
						'payType' => strtoupper( $this->gateway->payment_type ),
						'capture' => apply_filters( 'linepay_tw_payment_capture', true ),
					),
					'extra'   => array(
						'branchName' => '',
					),
				),
			);

			$url = $this->get_request_url( WPBR_LINEPay_Const::REQUEST_TYPE_REQUEST );
			LINEPay_TW::log( sprintf( '[request][order_id:%s] http request url : %s', $order_id, $url ) );

			$body         = array_merge( $body, $product_info, $redirect_urls, $options );
			$request_args = $this->build_execute_request_args( $url, $body );
			LINEPay_TW::log( sprintf( '[request][order_id:%s] execute request_args: %s', $order_id, wc_print_r( $request_args, true ) ) );

			$result = $this->execute( $url, $request_args );
			LINEPay_TW::log( '[request] execute result: ' . wc_print_r( $result, true ) );

			// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( '0000' !== $result->returnCode ) {
				throw new Exception( sprintf( 'Execute LINE Pay Request API failed. Return code: %s. Response message: %s', $result->returnCode, $result->returnMessage ) );
			}

			$order->update_meta_data( '_linepay_payment_status', WPBR_LINEPay_Const::PAYMENT_STATUS_RESERVED );
			$order->update_meta_data( '_linepay_reserved_transaction_id', $result->info->transactionId );
			$order->save();

			$this->check_payment_and_update_order_note( $order, 'Check payment status after requested' );

			// 回傳 paymentUrl 導向 LINE Pay 付款頁面.
			return array(
				'result'   => 'success',
				'redirect' => $result->info->paymentUrl->web,
			);

		} catch ( Exception $e ) {

			LINEPay_TW::log( 'process payment request error:' . $e->getMessage(), 'error' );

			// display error on checkout order pay page
			wc_add_wp_error_notices( new WP_Error( 'process_payment_request', __( '[LINE Pay] Order Received but unable to process payment request. Please try to pay again.', 'wpbr-linepay-tw' ) ) );

			// 回傳導向 checkout order pay 頁面.
			return array(
				'result'   => 'success',
				'redirect' => $order->get_checkout_payment_url( false ),
			);

		}
	}

	/**
	 * Call confirm-api of LINE Pay and move to the page that matches the result.
	 *
	 * If request API is successfully called, according to the registered confirmUrl
	 * Call handle_callback of woocommerce.
	 *
	 * Request successful
	 * -Go to order result page
	 *
	 * Request failed
	 * -Send failure message
	 * -Go to order detail page
	 *
	 * @see LINEPay_TW_Response->receive_payment_response()
	 *
	 * @param int     $order_id The Order ID.
	 * @param boolean $is_checkout Whether the request is from checkout process.
	 *
	 * @throws Exception Throw exception when confirm failed.
	 */
	public function confirm( $order_id, $is_checkout = true ) {

		try {

			$order = wc_get_order( $order_id );

			if ( ! $order ) {
				throw new Exception( 'Cant find order by order_id:' . $order_id );
			}

			// Check if already confirmed using dedicated flag AND payment status
			if ( wc_string_to_bool($order->get_meta( '_wpbr_linepay_confirmed' )) ) {
				$payment_status = $order->get_meta( '_linepay_payment_status' );
				if ( WPBR_LINEPay_Const::PAYMENT_STATUS_CONFIRMED === $payment_status ) {
					// Both flag AND status confirmed - safe to skip
					LINEPay_TW::log( sprintf( '[confirm][order_id:%s] Already confirmed, skipping duplicate call', $order_id ) );
					if ( $is_checkout ) {
						wp_safe_redirect( $this->get_return_url( $order ) );
						exit;
					}
					return true;
				}
				// Flag is set but payment not confirmed yet - continue to process/wait
				LINEPay_TW::log( sprintf( '[confirm][order_id:%s] Confirmation in progress, waiting...', $order_id ) );
			}

			$amount   = $order->get_total();
			$currency = $order->get_currency();

			// Direct access to DB to check whether order price information is altered.
			$reserved_std_amount = $this->get_standardized( $order->get_total(), $currency );
			$std_amount          = $this->get_standardized( $amount );

			// 1st verification of the amount, confirm the requested amount Confirm the reserved amount
			if ( $std_amount !== $reserved_std_amount ) {
				throw new Exception( sprintf( WPBR_LINEPay_Const::LOG_TEMPLATE_CONFIRM_FAILURE_MISMATCH_ORDER_AMOUNT, $std_amount, $reserved_std_amount ) );
			}

			// Set confirmation flag to prevent concurrent requests
			$order->update_meta_data( '_wpbr_linepay_confirmed', true );
			$order->save();

			// api call.
			$reserved_transaction_id = $order->get_meta( '_linepay_reserved_transaction_id' );
			$url                     = $this->get_request_url( WPBR_LINEPay_Const::REQUEST_TYPE_CONFIRM, array( 'transaction_id' => $reserved_transaction_id ) );
			LINEPay_TW::log( sprintf( '[confirm][order_id:%s] http request url : %s', $order_id, $url ) );

			$body = array(
				'amount'   => $std_amount,
				'currency' => $currency,
			);

			$request_args = $this->build_execute_request_args( $url, $body );
			LINEPay_TW::log( sprintf( '[confirm][order_id:%s] http_request request_args:', $order_id, wc_print_r( $request_args, true ) ) );

			$this->check_payment_and_update_order_note( $order, 'Check payment status before confirm' );

			$result = $this->execute( $url, $request_args, 40 );

			if ( '0000' !== $result->returnCode ) {
				throw new Exception( sprintf( 'Execute LINE Pay Confirm API failed. Return code: %s. Return Message: %s', $result->returnCode, $result->returnMessage ) );
			}

			$confirmed_amount = 0;
			foreach ( $result->info->payInfo as $item ) {
				$confirmed_amount += $item->amount;
			}

			$order->payment_complete( $result->info->transactionId );

			$std_confirmed_amount = $this->get_standardized( $confirmed_amount );
			$order->update_meta_data( '_linepay_transaction_balanced_amount', $std_confirmed_amount );
			$order->update_meta_data( '_linepay_payment_status', WPBR_LINEPay_Const::PAYMENT_STATUS_CONFIRMED );
			$order->save();

			$this->check_payment_and_update_order_note( $order, 'Check payment status when confirmed' );

			if ( $is_checkout ) {
				WC()->cart->empty_cart();
				wp_safe_redirect( $this->get_return_url( $order ) );
				exit;
			} else {
				return true;
			}
		} catch ( Exception $e ) {

			LINEPay_TW::log( 'process payment confirm error:' . $e->getMessage() );

			// Clear confirmation flag if not actually a duplicate (1172-Already confirmed transaction)
			if ( strpos( $e->getMessage(), '1172' ) === false ) {
				$order->update_meta_data( '_wpbr_linepay_confirmed', false );
				$order->save();
			}

			if ( $is_checkout ) {
				/**
				 * Do action when confirm failed.
				 *
				 * @since 1.0.0
				 *
				 * @param WC_Order $order The order object.
				 */
				do_action( 'linepay_process_confirm_failed', $order );
			} else {
				// just throw the exception.
				throw $e;
			}
		}
	}

	/**
	 * Get order payment detail after confirm failed, the order status will be set to on-hold.
	 *
	 * @param WC_Order $order The order object.
	 * @return void
	 */
	public function on_process_confirm_failed( $order ) {

		// Initialize order stored in session.
		WC()->session->set( 'order_awaiting_payment', false );

		try {

			$check_status = $this->check( $order );
			$check_code   = $check_status->returnCode;
			$check_msg    = $check_status->returnMessage;
			$check_info   = sprintf( '[confirm][order_id:%s] Check payment status when confirm failed, return code:%s, return message:%s', $order->get_id(), $check_code, $check_msg );
			LINEPay_TW::log( $check_info );
			$order->add_order_note( $check_info );

			if ( LINEPayStatusCode::COMPLETED === $check_code ) {
				// Payment already completed - mark order as successful
				$url            = $this->get_request_url( WPBR_LINEPay_Const::REQUEST_TYPE_DETAILS, array( 'transaction_id' => $order->get_meta( '_linepay_reserved_transaction_id' ) ) );
				$request_args   = $this->build_execute_request_args( $url, null, 20, 'GET' );
				$payment_detail = $this->execute( $url, $request_args, 20 );
				if ( $payment_detail ) {
					$order->update_meta_data( '_wpbr_linepay_confirmed', true );
					$order->update_meta_data( '_linepay_payment_status', WPBR_LINEPay_Const::PAYMENT_STATUS_CONFIRMED );
					$order->update_meta_data( '_linepay_transaction_balanced_amount', $payment_detail->info->amount );
					$order->save();
					$order->payment_complete( $payment_detail->info->transactionId );
				}
				wp_safe_redirect( $this->get_return_url( $order ) );
				exit;
			} elseif ( LINEPayStatusCode::AUTHED === $check_code ) {
				// Completed authorization - Able to call the Confirm API.
				$order->update_meta_data( '_linepay_payment_status', WPBR_LINEPay_Const::PAYMENT_STATUS_AUTHED );
				$order->save();
				$order->update_status( 'on-hold' );
				$order->add_order_note( __( 'Order payment is authed, but need to be confirmed。', 'wpbr-linepay-tw' ) );

			} elseif ( LINEPayStatusCode::CANCELLED_EXPIRED === $check_code ) {

				$order->update_meta_data( '_linepay_payment_status', WPBR_LINEPay_Const::PAYMENT_STATUS_CANCELLED );
				$order->save();
				$order->update_status( LINEPay_TW::$fail_order_status );
				$order->add_order_note( __( 'Payment is cancelled or expired。', 'wpbr-linepay-tw' ) );

			} elseif ( LINEPayStatusCode::FAILED === $check_code ) {

				$order->update_meta_data( '_linepay_payment_status', WPBR_LINEPay_Const::PAYMENT_STATUS_FAILED );
				$order->save();
				$order->update_status( LINEPay_TW::$fail_order_status );
				$order->add_order_note( __( 'LINE Pay Transaction failed。', 'wpbr-linepay-tw' ) );

			} else {
				$order->update_status( 'on-hold' );
				$order->add_order_note( sprintf( __( 'Payment confirm failed, Status Code: %s。', 'wpbr-linepay-tw' ), $check_code ) );
			}
		} catch ( Exception $e ) {

			LINEPay_TW::log( 'check status failed, error:' . $e->getMessage(), 'error' );

		} finally {

			wp_safe_redirect( $this->get_return_url( $order ) );
			exit;

		}
	}

	/**
	 * When canceling after payment request, the information used for payment is initialized.
	 *
	 * If you cancel after calling reserve-api, according to the registered cancelUrl
	 * Call receive_payment_response of woocommerce.
	 *
	 * @see     LINEPay_TW_Response->receive_payment_response()
	 * @param   int $order_id The order ID.
	 */
	public function cancel( $order_id ) {

		$order                   = wc_get_order( $order_id );
		$reserved_transaction_id = $order->get_meta( '_linepay_reserved_transaction_id' );

		// Initialize order stored in session.
		WC()->session->set( 'order_awaiting_payment', false );

		LINEPay_TW::log( sprintf( WPBR_LINEPay_Const::LOG_TEMPLATE_PAYMENT_CANCEL, $order_id, $reserved_transaction_id ) );

		// redirect to thank you page, order status is pending.
		wp_safe_redirect( $this->get_return_url( $order ) );
		exit;
	}

	/**
	 * Request LINEPay's refund-api and return the result.
	 *
	 * @param int    $order_id The order id.
	 * @param string $refund_amount => wc_format_decimal().
	 * @param string $reason The reason of refund.
	 *
	 * @return boolean(true)|WP_Error
	 */
	public function refund( $order_id, $refund_amount, $reason = '' ) {

		$order             = wc_get_order( $order_id );
		$std_refund_amount = $this->get_standardized( $refund_amount );

		if ( false === $order ) {

			return new WP_Error(
				'process_refund_request',
				sprintf( __( 'Unable to find order #%s', 'wpbr-linepay-tw' ), $order_id ),
				array(
					'order_id'      => $order_id,
					'refund_amount' => $std_refund_amount,
				)
			);

		}

		$transaction_id = $order->get_transaction_id();

		$remaining_refund_amount = $order->get_remaining_refund_amount();
		LINEPay_TW::log( 'remaining refund:' . $remaining_refund_amount );
		$is_partial_refund = ( $remaining_refund_amount > 0 ) ? true : false;

		$result = $this->do_refund( $order, $transaction_id, $std_refund_amount, $is_partial_refund );

		return $result;
	}

	/**
	 * LINE Pay check payment status function
	 *
	 * @param WC_Order $order the order object.
	 * @return mixed|WP_Error
	 *
	 * @throws Exception When the request is failed.
	 */
	public function check( $order ) {

		$reserved_transaction_id = $order->get_meta( '_linepay_reserved_transaction_id' );

		if ( empty( $reserved_transaction_id ) ) {
			throw new Exception( __( 'no transaction_id is found', 'wpbr-linepay-tw' ) );
		}

		$url = $this->get_request_url( WPBR_LINEPay_Const::REQUEST_TYPE_CHECK, array( 'transaction_id' => $reserved_transaction_id ) );
		LINEPay_TW::log( sprintf( '[check][order_id:%s] http request url : %s', $order->get_id(), $url ) );

		$request_args = $this->build_execute_request_args( $url, null, 20, 'GET' );
		LINEPay_TW::log( sprintf( '[check][order_id:%s] execute request_args: %s', $order->get_id(), wc_print_r( $request_args, true ) ) );

		$check_result = $this->execute( $url, $request_args, 20 );

		return $check_result;
	}

	/**
	 * Build request arguments for LINE Pay API.
	 *
	 * @param string $url The request url.
	 * @param array  $body The request body.
	 * @param int    $timeout The request timeout.
	 * @param string $method The request method.
	 *
	 * @return array
	 */
	private function build_execute_request_args( $url, $body = null, $timeout = 20, $method = 'POST' ) {

		$channel_info = LINEPay_TW::get_channel_info();
		$request_time = self::generate_request_time();

		$request_body = '';
		if ( ! is_null( $body ) ) {
			if ( is_array( $body ) ) {
				$request_body = wp_json_encode( $body );
			} else {
				$request_body = $body;
			}
		}

		$headers = array(
			'content-type'               => 'application/json; charset=UTF-8',
			'X-LINE-ChannelId'           => $channel_info['channel_id'],
			'X-LINE-Authorization-Nonce' => $request_time,
			'X-LINE-Authorization'       => self::generate_signature( $channel_info['channel_secret'], $url, $request_body, $request_time ),
		);

		$request_args = array(
			'httpversion' => '1.1',
			'timeout'     => $timeout,
			'headers'     => $headers,
			'method'      => $method,
		);

		if ( is_array( $body ) ) {
			$request_args = array_merge( $request_args, array( 'body' => wp_json_encode( $body ) ) );
		}

		return $request_args;
	}

	/**
	 * Call the refund API and store the information DB according to the result.
	 * 1. Save refund information in the form of serialized array
	 * 2. After refund, the balance of the transaction amount is stored in string form.
	 *
	 * @param WC_Order      $order The order object.
	 * @param string        $transaction_id The transaction id of the order.
	 * @param number|string $refund_amount The refund amount.
	 * @param boolean       $is_partial_refund Whether the refund is partial or fully.
	 *
	 * @return boolean(true)|WP_Error
	 */
	private function do_refund( $order, $transaction_id, $refund_amount, $is_partial_refund = false ) {

		$order_id          = $order->get_id();
		$std_refund_amount = $this->get_standardized( $refund_amount );

		$url  = $this->get_request_url( WPBR_LINEPay_Const::REQUEST_TYPE_REFUND, array( 'transaction_id' => $transaction_id ) );
		$body = array(
			'refundAmount' => $std_refund_amount,
		);

		$request_args = $this->build_execute_request_args( $url, $body );
		LINEPay_TW::log( sprintf( '[refund][order_id:%s] request_args:%s', $order_id, wc_print_r( $request_args, true ) ) );

		try {
			$resp = $this->execute( $url, $request_args );

			if ( '0000' !== $resp->returnCode ) {
				return new WP_Error( 'error', sprintf( 'Execute LINE Pay Refund API failed. Return code: %s. Return Message: %s', $resp->returnCode, $resp->returnMessage ) );
			}
		} catch ( Exception $e ) {
			LINEPay_TW::log( sprintf( '[refund][order_id:%s] refund error:%s', $order_id, $e->getMessage() ) );
			return new WP_Error( 'error', $e->getMessage() );
		}

		if ( '0000' === $resp->returnCode ) {

			// get meta always return '' or array().
			$refund_ids = $order->get_meta( '_linepay_refund_transaction_id' );
			if ( empty( $refund_ids ) ) {
				$refund_ids = array();
			}

			LINEPay_TW::log( sprintf( '[refund][order_id:%s] refund transaction ids:%s', $order_id, wc_print_r( $refund_ids, true ) ) );
			$refund_ids[] = $resp->info->refundTransactionId;
			$order->update_meta_data( '_linepay_refund_transaction_id', $refund_ids );

			if ( ! $is_partial_refund ) {
				$order->update_meta_data( '_linepay_payment_status', WPBR_LINEPay_Const::PAYMENT_STATUS_REFUNDED );
			}

			$order->save();

			$order->add_order_note( sprintf( __( 'Refund via LINE Pay successfully. Refund transaction id: %s', 'wpbr-linepay-tw' ), $resp->info->refundTransactionId ) );

			$this->check_payment_and_update_order_note( $order, 'Check payment status after refunded' );

		} else {
			$order->add_order_note( sprintf( __( 'Refund via LINE Pay failed. Refund transaction id: %1$s, returnCode: %2$s, returnMessage: %3$s', 'wpbr-linepay-tw' ), $resp->info->refundTransactionId, $resp->returnCode, $resp->returnMessage ) );
		}

		return true;
	}

	/**
	 * Sends a request based on the transmitted information and returns the result.
	 * When requesting LINEPay, create a header to be used in common.
	 *
	 * @param string $url The API URL.
	 * @param array  $request_args The request arguments.
	 * @param int    $timeout Timeout in seconds.
	 * @return mixed|WP_Error Return respond body or WP_Error
	 *
	 * @throws Exception When the request failed.
	 */
	private function execute( $url, $request_args = null, $timeout = 20 ) {

		$response = wp_remote_request( $url, $request_args );

		if ( is_wp_error( $response ) ) {
			throw new Exception( 'Execute remote request error:' . $response->get_error_message() );
		}

		$http_status   = (int) $response['response']['code'];
		$response_body = self::json_custom_decode( wp_remote_retrieve_body( $response ) );
		$return_code   = $response_body->returnCode;

		LINEPay_TW::log( '[execute] http response code: ' . $http_status . ', response body: ' . wc_print_r( $response_body, true ) );

		if ( 200 !== $http_status ) {
			throw new Exception( sprintf( 'Execute API http response not success. http response code: %s. url: $s', $http_status, $url ) );
		}

		return $response_body;
	}

	/**
	 * Returns the array to be transferred to reserve-api based on the order information.
	 * The array contains productName and productImageUrl.
	 *
	 * @param WC_Order $order The order object.
	 * @return array
	 */
	private function get_product_info( $order ) {
		$packages     = array();
		$items        = $order->get_items();
		$order_amount = 0;

		// item_lines.
		if ( count( $items ) > 0 ) {

			$products     = array();
			$total_amount = 0;

			$first_item = $items[ array_key_first( $items ) ];
			$wc_product = wc_get_product( $first_item->get_product_id() );

			$order_name = $wc_product->get_name();

			if ( count( $items ) > 1 ) {
				/**
				 * Filter allow to change the product name displayed in LINE Pay.
				 *
				 * @since 1.0.0
				 *
				 * @param strng  $order_name               The product name.
				 * @param string $order->get_item_count()  Number of the order item count.
				 */
				$order_name = apply_filters(
					'linepay_tw_checkout_product_name',
					sprintf(
						/* translators:  %1$s is product name, %2$s is the order item count */
						__( '%1$s and total %2$s products', 'wpbr-linepay-tw' ),
						$order_name,
						$order->get_item_count()
					)
				);
			}

			$product = array(
				'id'       => $first_item->get_product_id(),
				'name'     => sanitize_text_field( $order_name ),
				'quantity' => 1,
				'price'    => $this->get_standardized( $order->get_total() ),
			);

			// 取第一個商品的圖案.
			$thumbnail_image_urls = wp_get_attachment_image_src( get_post_thumbnail_id( $first_item->get_product_id() ) );

			if ( isset( $thumbnail_image_urls[0] ) ) {
				/**
				 * Filter allow to change the product image displayed in LINE Pay.
				 *
				 * @since 1.0.0
				 *
				 * @param string $thumbnail_image_urls[0] The product name.
				 */
				$product['imageUrl'] = apply_filters( 'linepay_tw_checkout_product_image', $thumbnail_image_urls[0] );
			}

			array_push( $products, $product );

			array_push(
				$packages,
				array(
					'id'       => 'WC-ITEMS||' . $order->get_id(),
					'name'     => sanitize_text_field( 'WC_ITEMS' ),
					'amount'   => $this->get_standardized( $order->get_total() ),
					'products' => $products,
				)
			);
		} //end items.

		return $packages;
	}

	/**
	 * Call LINE Pay payment status check API and add note to order.
	 *
	 * @param WC_Order $order   The order object.
	 * @param string   $context The context of the call.
	 * @return void
	 */
	private function check_payment_and_update_order_note( $order, $context ) {
		/**
		 * Filter allow to check the payment status and add detail note to order.
		 *
		 * @since 1.0.0
		 *
		 * @param WC_Order $order The order object.
		 * @param boolean  true Enable to check the payment status and add detail note to order.
		 *
		 * @return void
		 */
		if ( LINEPAY_TW::$detail_payment_status_note_enabled || apply_filters( 'linepay_tw_enable_detail_note', false ) ) {
			$check_status = $this->check( $order );
			$check_code   = $check_status->returnCode;
			$check_msg    = $check_status->returnMessage;
			$check_info   = sprintf( '[check][order_id:%s] %s, return code:%s, return message:%s', $order->get_id(), $context, $check_code, $check_msg );
			LINEPay_TW::log( $check_info );
			$order->add_order_note( $check_info );
		}
	}

	/**
	 * Returns the number_format for the currency
	 *
	 * @param number|string $amount   The amount.
	 * @param string        $currency The currency.
	 * @return string
	 */
	private function get_standardized( $amount, $currency = null ) {
		$scale = $this->get_currency_scale();

		if ( is_string( $amount ) ) {
			$amount = floatval( $amount );
		}

		return number_format( $amount, $scale, '.', '' );
	}

	/**
	 * Returns the scale of the received currency code
	 * Use BaseCurrencyCode when there is no information received
	 *
	 * @param string $currency_code The currency_code.
	 * @return number
	 */
	private function get_currency_scale( $currency_code = null ) {

		if ( null === $currency_code ) {
			$currency_code = get_woocommerce_currency();
		}

		$currency_code = strtoupper( $currency_code );

		if ( in_array( $currency_code, LINEPay_TW::$currency_scales, true ) ) {
			return LINEPay_TW::$currency_scales[ $currency_code ];
		} else {
			// Scale of unset currency is set to 0.
			return 0;
		}
	}

	/**
	 * Check if the scale of the $amount received based on the basic currency code is appropriate.
	 *
	 * @param number $amount The amount.
	 * @param sting  $currency_code The currency code.
	 *
	 * @return boolean
	 */
	private function valid_currency_scale( $amount, $currency_code = null ) {
		return ( $this->get_currency_scale( $currency_code ) >= $this->get_amount_precision( $amount ) );
	}

	/**
	 * Returns the URL that matches the request type.
	 *
	 * @param string $type const:WPBR_LINEPay_Const::REQUEST_TYPE_REQUEST|CONFIRM|CANCEL|REFUND|CHECK.
	 * @param array  $args The request arguments.
	 * @return string
	 */
	private function get_request_url( $type, $args = array() ) {
		$host = $this->get_request_host();
		$uri  = $this->get_request_uri( $type, $args );

		return $host . $uri;
	}

	/**
	 * Returns HOST information that matches the environmental information of LINEPay Gateway.
	 *
	 * @return string
	 */
	private function get_request_host() {
		$host = '';

		switch ( LINEPay_TW::$env_status ) {
			case WPBR_LINEPay_Const::ENV_SANDBOX:
				$host = WPBR_LINEPay_Const::HOST_SANDBOX;
				break;

			case WPBR_LINEPay_Const::ENV_REAL:
			default:
				$host = WPBR_LINEPay_Const::HOST_REAL;
				break;
		}

		return $host;
	}

	/**
	 * Returns the uri that matches the request type.
	 * If the uri contains variables, it is combined with args to create a new uri.
	 *
	 * @param string $type const:WPBR_LINEPay_Const::REQUEST_TYPE_REQUEST|CONFIRM|CANCEL|REFUND.
	 * @param array  $args The request arguments.
	 * @return string
	 */
	private function get_request_uri( $type, $args ) {
		$uri = '';

		switch ( $type ) {
			case WPBR_LINEPay_Const::REQUEST_TYPE_REQUEST:
				$uri = WPBR_LINEPay_Const::URI_REQUEST;
				break;
			case WPBR_LINEPay_Const::REQUEST_TYPE_CONFIRM:
				$uri = WPBR_LINEPay_Const::URI_CONFIRM;
				break;
			case WPBR_LINEPay_Const::REQUEST_TYPE_DETAILS:
				$uri = WPBR_LINEPay_Const::URI_DETAILS;
				break;
			case WPBR_LINEPay_Const::REQUEST_TYPE_CHECK:
				$uri = WPBR_LINEPay_Const::URI_CHECK;
				break;
			case WPBR_LINEPay_Const::REQUEST_TYPE_REFUND:
				$uri = WPBR_LINEPay_Const::URI_REFUND;
				break;
		}

		$new_uri = $uri;
		foreach ( $args as $key => $value ) {
			$new_uri = str_replace( '{' . $key . '}', $value, $new_uri );
		}

		return $new_uri;
	}

	/**
	 * Returns the decimal point accuracy of the passed $amount
	 *
	 * @param number $amount The amount.
	 * @return number
	 */
	private function get_amount_precision( $amount = 0 ) {
		if ( is_string( $amount ) ) {
			$amount = (float) $amount;
		}
		$strl = strlen( $amount );

		$strp = strpos( $amount, '.' );
		$strp = ( false !== $strp ) ? $strp + 1 : $strl;

		return ( $strl - $strp );
	}

	/**
	 * Get the return url (thank you page).
	 *
	 * @param WC_Order|null $order Order object.
	 * @return string
	 */
	public function get_return_url( $order = null ) {
		if ( $order ) {
			$return_url = $order->get_checkout_order_received_url();
		} else {
			$return_url = wc_get_endpoint_url( 'order-received', '', wc_get_checkout_url() );
		}

		return apply_filters( 'woocommerce_get_return_url', $return_url, $order );
	}

	/**
	 * Hange large integer to json's string format.
	 *
	 * @param String $json The json string.
	 * @return mixed
	 */
	private static function json_custom_decode( $json ) {
		if ( version_compare( PHP_VERSION, '5.4.0', '>=' ) ) {
			return json_decode( $json, false, 512, JSON_BIGINT_AS_STRING );
		} else {
			return json_decode( preg_replace( '/:\s?(\d{14,})/', ': "${1}"', $json ) );
		}
	}

	/**
	 * Generate signature
	 *
	 * @param string $channel_secret The channel secret.
	 * @param string $url The request url.
	 * @param string $request_body The request body.
	 * @param string $nonce The nonce.
	 *
	 * @return string
	 */
	private static function generate_signature( $channel_secret, $url, $request_body, $nonce ) {
		$url_path = wp_parse_url( $url, PHP_URL_PATH );
		$data     = $channel_secret . $url_path . $request_body . $nonce;
		return base64_encode( hash_hmac( WPBR_LINEPay_Const::AUTH_ALGRO, $data, $channel_secret, true ) );
	}

	/**
	 * Generate request timestamp.
	 *
	 * @return string
	 */
	private static function generate_request_time() {
		return date( WPBR_LINEPay_Const::REQUEST_TIME_FORMAT ) . '' . ( explode( '.', microtime( true ) )[1] );
	}
}
