<?php
/**
 * PaymentResponse class file
 *
 * @package payuni
 */

namespace WPBrewer\Payuni\Payment\Api;

use WPBrewer\Payuni\Payment\Gateways\Aftee;
use WPBrewer\Payuni\Payment\Gateways\Atm;
use WPBrewer\Payuni\Payment\Gateways\Cvs;
use WPBrewer\Payuni\Payment\PayuniPayment;
use WPBrewer\Payuni\Payment\Utils\OrderMeta;
use WPBrewer\Payuni\Payment\Utils\PayType;
use WPBrewer\Payuni\Payment\Utils\SingletonTrait;
use WPBrewer\Payuni\Payment\Utils\TradeStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Receive response from PAYUNi.
 */
class PaymentResponse {

	use SingletonTrait;

	/**
	 * Initialize and add hooks
	 *
	 * @return void
	 */
	public static function init() {
		self::get_instance();
		add_action( 'woocommerce_api_payuni_payment', array( self::get_instance(), 'payuni_receive_notify' ), 10 );
		add_action( 'woocommerce_api_payuni_return', array( self::get_instance(), 'payuni_receive_response_frontend' ), 20 );
	}

	/**
	 * Receive backend notification from PAYUNi NotifyURL
	 *
	 * @return void
	 */
	public static function payuni_receive_notify() {
        // phpcs:disable WordPress.Security.NonceVerification.Missing

		$test_mode     = wc_string_to_bool( get_option( 'payuni_payment_testmode_enabled' ) );
		$mer_id        = $test_mode ? get_option( 'payuni_payment_merchant_id_test' ) : get_option( 'payuni_payment_merchant_id' );
		$decrypted_info = self::get_verified_callback_data( $mer_id );
		if ( false === $decrypted_info ) {
			return;
		}
		if ( ! self::has_required_payment_fields( $decrypted_info ) ) {
			PayuniPayment::log( 'PAYUNi Notify response rejected: required transaction fields are missing.' );
			return;
		}
		PayuniPayment::log( 'PAYUNi NotifyURL response decrypted:' . wc_print_r( $decrypted_info, true ) );

		$status          = $decrypted_info['Status']; // SUCESS = 成功，OK = 審核通過.
		$trade_status    = $decrypted_info['TradeStatus']; // 訂單狀態 0=取號成功 or 信用審查成功,  1 = 已付款, 2 = 付款失敗, 3 = 付款取消.
		$payuni_order_no = $decrypted_info['MerTradeNo'];
		$message         = $decrypted_info['Message'];
		$pay_type        = $decrypted_info['PaymentType'];
		$trade_no        = $decrypted_info['TradeNo'];// UNi序號

		$text_log        = __( 'PAYUNi Notify', 'wpbr-payuni-payment' );
		$text_code       = __( 'Status code:', 'wpbr-payuni-payment' );
		$text_message    = __( 'Transaction message:', 'wpbr-payuni-payment' );
		$text_mertradeno = __( 'MerTradeNo:', 'wpbr-payuni-payment' );
		$text_number     = __( 'UNi number:', 'wpbr-payuni-payment' );
		$text_paytype    = __( 'Payment type:', 'wpbr-payuni-payment' );

		$woo_order_id = PayuniPayment::parse_payuni_order_no_to_woo_order_id( $payuni_order_no );
		$order        = wc_get_order( $woo_order_id );
		if ( ! $order ) {
			PayuniPayment::log( 'Cant find order by id:' . $woo_order_id );
			return;
		}
		if ( ! self::matches_order_transaction( $order, $decrypted_info ) ) {
			PayuniPayment::log( 'PAYUNi Notify response rejected: order transaction or amount mismatch.' );
			return;
		}

		// 電子發票的通知
		if ( array_key_exists( 'InvoiceNo', $decrypted_info ) ) {
			if ( ! isset( $decrypted_info['InvoiceNo'], $decrypted_info['InvoiceStatus'], $decrypted_info['InvoiceTime'], $decrypted_info['InvoiceNotifyType'], $decrypted_info['InvoiceInfo'], $decrypted_info['TradeAmt'] ) ) {
				PayuniPayment::log( 'PAYUNi e-invoice notification rejected: required invoice fields are missing.' );
				return;
			}
			self::save_einvoice_data( $order, $decrypted_info );
			$order->add_order_note( 'PAYUNi E-Invoice Notify. InvoiceStatus:' . $decrypted_info['InvoiceStatus'] . ', InvoiceNo:' . $decrypted_info['InvoiceNo'] );
			return;
		}

		$order->add_order_note( "<strong>{$text_log}</strong><br>{$text_code} {$status}<br>{$text_message} {$message}<br>{$text_mertradeno} {$payuni_order_no}<br>{$text_number} {$trade_no}<br>{$text_paytype} " . PayType::get_name( $pay_type ) );


		if ( $order->is_paid() || $order->get_meta( OrderMeta::TRADE_STATUS ) === TradeStatus::PAID ) {
			PAYUNiPayment::log( sprintf( 'PAYUNi Notify: Order %s already paid or transaction status has already set as success. Just add note and log.', $woo_order_id ) );
		} else {
			self::update_order_meta_and_order_status( $order, $decrypted_info );
		}
     // phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Receive return from PAYUNi ReturnURL
	 */
	public static function payuni_receive_response_frontend() {
     // phpcs:disable WordPress.Security.NonceVerification.Missing	
		$test_mode     = wc_string_to_bool( get_option( 'payuni_payment_testmode_enabled' ) );
		$mer_id        = $test_mode ? get_option( 'payuni_payment_merchant_id_test' ) : get_option( 'payuni_payment_merchant_id' );
		$decrypted_info = self::get_verified_callback_data( $mer_id );
		if ( false === $decrypted_info ) {
			return;
		}
		if ( ! self::has_required_payment_fields( $decrypted_info ) ) {
			PayuniPayment::log( 'PAYUNi Return response rejected: required transaction fields are missing.' );
			return;
		}
		PayuniPayment::log( 'PAYUNi ReturnURL response decrypted:' . wc_print_r( $decrypted_info, true ) );

		$status       = $decrypted_info['Status']; // SUCESS = 成功，OK = 審核通過.
		$trade_status = $decrypted_info['TradeStatus']; // 訂單狀態 0=取號成功or信用審查成功,  1 = 已付款, 2 = 付款失敗, 3 = 付款取消.
		$order_id     = $decrypted_info['MerTradeNo'];
		$message      = $decrypted_info['Message'];
		$pay_type     = $decrypted_info['PaymentType'];
		$trade_no     = $decrypted_info['TradeNo'];
		
		$text_log        = __( 'PAYUNi Return', 'wpbr-payuni-payment' );
		$text_code       = __( 'Status code:', 'wpbr-payuni-payment' );
		$text_message    = __( 'Transaction message:', 'wpbr-payuni-payment' );
		$text_mertradeno = __( 'MerTradeNo:', 'wpbr-payuni-payment' );
		$text_number     = __( 'UNi number:', 'wpbr-payuni-payment' );
		$text_paytype    = __( 'Payment type:', 'wpbr-payuni-payment' );

		$woo_order_id = PayuniPayment::parse_payuni_order_no_to_woo_order_id( $order_id );
		$order        = wc_get_order( $woo_order_id );
		if ( ! $order ) {
			PayuniPayment::log( 'Cant find order by id:' . $woo_order_id );
			return;
		}
		if ( ! self::matches_order_transaction( $order, $decrypted_info ) ) {
			PayuniPayment::log( 'PAYUNi Return response rejected: order transaction or amount mismatch.' );
			return;
		}

		$order->add_order_note( "<strong>{$text_log}</strong><br>{$text_code} {$status}<br>{$text_message} {$message}<br>{$text_mertradeno} {$order_id}<br>{$text_number} {$trade_no}<br>{$text_paytype} " . PayType::get_name( $pay_type ) );

		if ( $order->is_paid() || $order->get_meta( OrderMeta::TRADE_STATUS ) === TradeStatus::PAID ) {
			PAYUNiPayment::log( sprintf( 'PAYUNi Return: Order %s already paid or transaction status has already set as success. Just add note and log.', $woo_order_id ) );
		} else {
			self::update_order_meta_and_order_status( $order, $decrypted_info );
		}

		wp_redirect( $order->get_checkout_order_received_url() );// 訂單感謝頁面.
		exit;

     // phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Validate the response envelope before decrypting any callback payload.
	 *
	 * @param string $expected_merchant_id Merchant ID configured for the active environment.
	 * @return array|false
	 */
	private static function get_verified_callback_data( $expected_merchant_id ) {
		if ( empty( $_POST ) || ! is_scalar( $expected_merchant_id ) || '' === (string) $expected_merchant_id ) {
			PayuniPayment::log( 'PAYUNi callback rejected: request or configured merchant ID is missing.' );
			return false;
		}

		$posted_merchant_id = isset( $_POST['MerID'] ) && is_scalar( $_POST['MerID'] ) ? wc_clean( wp_unslash( $_POST['MerID'] ) ) : '';
		$payload            = array(
			'EncryptInfo' => isset( $_POST['EncryptInfo'] ) && is_scalar( $_POST['EncryptInfo'] ) ? wc_clean( wp_unslash( $_POST['EncryptInfo'] ) ) : '',
			'HashInfo'    => isset( $_POST['HashInfo'] ) && is_scalar( $_POST['HashInfo'] ) ? wc_clean( wp_unslash( $_POST['HashInfo'] ) ) : '',
		);

		if ( (string) $expected_merchant_id !== (string) $posted_merchant_id || ! PayuniPayment::has_valid_response_hash( $payload ) ) {
			PayuniPayment::log( 'PAYUNi callback rejected: merchant ID mismatch or missing/invalid HashInfo.' );
			return false;
		}

		$decrypted_info = PayuniPayment::decrypt( $payload['EncryptInfo'] );
		if ( empty( $decrypted_info ) || ! is_array( $decrypted_info ) ) {
			PayuniPayment::log( 'PAYUNi callback rejected: EncryptInfo could not be decrypted.' );
			return false;
		}

		if ( isset( $decrypted_info['MerID'] ) && (string) $decrypted_info['MerID'] !== (string) $expected_merchant_id ) {
			PayuniPayment::log( 'PAYUNi callback rejected: encrypted merchant ID mismatch.' );
			return false;
		}

		return $decrypted_info;
	}

	/**
	 * Check that a payment or e-invoice callback includes its required fields.
	 *
	 * @param array $data Decrypted PAYUNi response.
	 * @return bool
	 */
	private static function has_required_payment_fields( array $data ): bool {
		if ( isset( $data['InvoiceNo'] ) ) {
			$required_invoice_fields = array( 'InvoiceNo', 'InvoiceStatus', 'InvoiceTime', 'InvoiceNotifyType', 'InvoiceInfo', 'MerTradeNo', 'TradeAmt' );
			foreach ( $required_invoice_fields as $required_invoice_field ) {
				if ( ! isset( $data[ $required_invoice_field ] ) || ! is_scalar( $data[ $required_invoice_field ] ) || '' === (string) $data[ $required_invoice_field ] ) {
					return false;
				}
			}

			return true;
		}

		$required = array( 'Status', 'TradeStatus', 'MerTradeNo', 'TradeAmt', 'Message', 'PaymentType' );
		foreach ( $required as $key ) {
			if ( ! isset( $data[ $key ] ) || ! is_scalar( $data[ $key ] ) || '' === (string) $data[ $key ] ) {
				return false;
			}
		}

		if ( ! in_array( (string) $data['Status'], array( 'SUCCESS', 'SUCESS', 'OK' ), true ) || ! in_array( (string) $data['TradeStatus'], array( TradeStatus::CREDIT_VALID_OR_GET_NUMBER_SUCCESS, TradeStatus::PAID, TradeStatus::FAIL, TradeStatus::CANCEL, TradeStatus::EXPIRED, TradeStatus::TBC, TradeStatus::UNPAID ), true ) ) {
			return false;
		}

		if ( '1' === (string) $data['PaymentType'] && ! isset( $data['AuthType'] ) ) {
			return false;
		}

		return TradeStatus::PAID !== (string) $data['TradeStatus'] || ( isset( $data['TradeNo'] ) && is_scalar( $data['TradeNo'] ) && '' !== (string) $data['TradeNo'] );
	}

	/**
	 * Ensure a callback belongs to the latest transaction and exact order total.
	 *
	 * @param \WC_Order $order WooCommerce order.
	 * @param array     $data  Decrypted PAYUNi response.
	 * @return bool
	 */
	private static function matches_order_transaction( $order, array $data ): bool {
		$expected_trade_no = PayuniPayment::get_current_order_transaction_no( $order );
		if ( '' === $expected_trade_no || ! isset( $data['MerTradeNo'], $data['TradeAmt'] ) || ! is_scalar( $data['MerTradeNo'] ) || ! is_scalar( $data['TradeAmt'] ) || (string) $data['MerTradeNo'] !== $expected_trade_no ) {
			return false;
		}

		$expected_amount = PayuniPayment::amount_to_minor_units( $order->get_total() );
		$received_amount = PayuniPayment::amount_to_minor_units( $data['TradeAmt'] );
		return '' !== $expected_amount && '' !== $received_amount && $expected_amount === $received_amount;
	}

	/**
	 * Save PAYUNi order data to WooCommerce order meta
	 *
	 * @param WC_Order $order          The order object.
	 * @param array    $decrypted_info The decrypted info from PAYUNi.
	 */
	private static function save_payuni_order_data( $order, $decrypted_info ) {

		$pay_type = $decrypted_info['PaymentType'];

		$order->update_meta_data( OrderMeta::STATUS, $decrypted_info['Status'] );
		$order->update_meta_data( OrderMeta::MESSAGE, $decrypted_info['Message'] );
		$order->update_meta_data( OrderMeta::PAYUNI_ORDER_NO, $decrypted_info['MerTradeNo'] );
		$order->update_meta_data( OrderMeta::UNI_NO, $decrypted_info['TradeNo'] );
		$order->update_meta_data( OrderMeta::TRADE_STATUS, $decrypted_info['TradeStatus'] );
		$order->update_meta_data( OrderMeta::TRADE_AMOUNT, $decrypted_info['TradeAmt'] );
		$order->update_meta_data( OrderMeta::PAY_TYPE, $pay_type );

		self::update_order_meta( $order, $decrypted_info, OrderMeta::CREDIT_REST_CODE, 'ResCode' );
		self::update_order_meta( $order, $decrypted_info, OrderMeta::CREDIT_REST_CODE_MSG, 'ResCodeMsg' );

		if ( '1' === $pay_type ) {
			// 信用卡.
			self::update_order_meta( $order, $decrypted_info, OrderMeta::CREDIT_AUTH_TYPE, 'AuthType' );
			self::update_order_meta( $order, $decrypted_info, OrderMeta::CREDIT_CARD_4NO, 'Card4No' );
			self::update_order_meta( $order, $decrypted_info, OrderMeta::CREDIT_AUTH_DAY, 'AuthDay' );
			self::update_order_meta( $order, $decrypted_info, OrderMeta::CREDIT_AUTH_TIME, 'AuthTime' );

			if ( '2' === $decrypted_info['AuthType'] ) {
				// 分期.
				self::update_order_meta( $order, $decrypted_info, OrderMeta::CREDIT_INSTALL, 'CardInst' );
				self::update_order_meta( $order, $decrypted_info, OrderMeta::CREDIT_FIRST_AMT, 'FirstAmt' );
				self::update_order_meta( $order, $decrypted_info, OrderMeta::CREDIT_EACH_AMT, 'EachAmt' );

			}
		} elseif ( '2' === $pay_type ) {
			// ATM虛擬帳號.
			self::update_order_meta( $order, $decrypted_info, OrderMeta::AMT_PAY_NO, 'PayNo' );
			self::update_order_meta( $order, $decrypted_info, OrderMeta::AMT_BANK_TYPE, 'BankType' );
			self::update_order_meta( $order, $decrypted_info, OrderMeta::AMT_PAY_TIME, 'PayTime' );
			self::update_order_meta( $order, $decrypted_info, OrderMeta::AMT_ACCOUNT_5NO, 'Account5No' );
			self::update_order_meta( $order, $decrypted_info, OrderMeta::AMT_PAY_SET, 'PaySet' );
			self::update_order_meta( $order, $decrypted_info, OrderMeta::AMT_EXPIRE_DATE, 'ExpireDate' );

		} elseif ( '3' === $pay_type ) {
			// 超商代碼.
			self::update_order_meta( $order, $decrypted_info, OrderMeta::CVS_PAY_NO, 'PayNo' );
			self::update_order_meta( $order, $decrypted_info, OrderMeta::CVS_STORE, 'Store' );
			self::update_order_meta( $order, $decrypted_info, OrderMeta::CVS_EXPIRE_DATE, 'ExpireDate' );
			self::update_order_meta( $order, $decrypted_info, OrderMeta::CVS_PAY_TIME, 'PayTime' );

		} elseif ( '7' === $pay_type ) {
			// AFTEE.
			self::update_order_meta( $order, $decrypted_info, OrderMeta::AFTEE_PAY_NO, 'PayNo' );
			self::update_order_meta( $order, $decrypted_info, OrderMeta::AFTEE_PAY_TIME, 'PayTime' );

		} elseif ( '9' === $pay_type ) {
			// LINE Pay.
			self::update_order_meta( $order, $decrypted_info, OrderMeta::LINE_PAY_NO, 'PayNo' );
		}

		$order->update_meta_data( OrderMeta::PLUGIN_VERSION, WUTM_PAYUNI_PAYMENT_VERSION );

		$order->save();
	}

	private static function update_order_meta_and_order_status( $order, $decrypted_info ) {

		$trade_status = $decrypted_info['TradeStatus'];

		self::save_payuni_order_data( $order, $decrypted_info );

		if ( TradeStatus::PAID === $trade_status ) {
			$order->payment_complete( $decrypted_info['TradeNo'] );
		} elseif ( TradeStatus::EXPIRED === $trade_status ) {
			// 交易逾期失效 (AFTEE, ATM, CVS)
			$order->update_status( 'failed' );
		} elseif ( TradeStatus::CANCEL === $trade_status || TradeStatus::FAIL === $trade_status ) {
			// 付款取消或付款失敗
			$order->update_status( 'failed' );
		}

		do_action( 'wpbr_payuni_on_order_status_updated', $order );
	}

	private static function save_einvoice_data( $order, $decrypted_info ) {
		$order->update_meta_data( OrderMeta::EINVOICE_NO, $decrypted_info['InvoiceNo'] );
		$order->update_meta_data( OrderMeta::EINVOICE_AMT, $decrypted_info['TradeAmt'] );
		$order->update_meta_data( OrderMeta::EINVOICE_TIME, $decrypted_info['InvoiceTime'] );
		$order->update_meta_data( OrderMeta::EINVOICE_TYPE, $decrypted_info['InvoiceNotifyType'] );
		$order->update_meta_data( OrderMeta::EINVOICE_INFO, $decrypted_info['InvoiceInfo'] );
		$order->update_meta_data( OrderMeta::EINVOICE_STATUS, $decrypted_info['InvoiceStatus'] );
		$order->save();
	}

	/**
	 * A wrapper function to save received post data from PAYUNi
	 *
	 * @param WC_Order $order    The order object.
	 * @param array    $data     The post data received from PAYUNi.
	 * @param string   $meta_key The meta key to save.
	 * @param string   $data_key The data key to get.
	 */
	private static function update_order_meta( $order, $data, $meta_key, $data_key ) {
		if ( ! empty( $data[ $data_key ] ) ) {

			$value = ( 'Store' === $data_key && 'SEVEN' === $data[ $data_key ] ) ? '7-11' : $data[ $data_key ];
			$order->update_meta_data( $meta_key, $value );
		}
	}
}
