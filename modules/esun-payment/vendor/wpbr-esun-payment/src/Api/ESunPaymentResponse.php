<?php

namespace WPBrewer\ESun\Payment\Api;

/**
 * ESunPaymentResponse class file
 *
 * @package wpbr-esun-payment
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WPBrewer\ESun\Payment\ESunPayment;
use WPBrewer\ESun\Payment\Lib\Esunacq\AuthReturnCode;
use WPBrewer\ESun\Payment\Utils\OrderMeta;
use WPBrewer\ESun\Payment\Utils\SingletonTrait;
/**
 * Receive response from ESUN.
 */
class ESunPaymentResponse {

	use SingletonTrait;

	/**
	 * Init the instance
	 *
	 * @return void
	 */
	public static function init() {
		self::get_instance();

		// the result_url, get data from esun.
		add_action( 'woocommerce_api_esun_credit_payment', array( self::get_instance(), 'receive_payment_response' ) );
	}

	public function receive_payment_response() {
        global $woocommerce;

        $data = $_GET['DATA'];
        $p_data = explode(',', $data);
        $params = array();

        foreach ($p_data as $value) {
            list($key, $val) = explode('=', $value);
            $params[$key] =  $val;
        }

		ESunPayment::log('receive response from esun: ' . wc_print_r( $params, true ) );

        // $resp_code = $_GET['RC'];
        // $MID  = $_GET['MID'];
        // $ONO  = $_GET['ONO'];
        // $LTD  = $_GET['LTD'];
        // $LTT  = $_GET['LTT'];
        // $RRN  = $_GET['RRN'];
        // $AIR  = $_GET['AIR'];
        // $AN   = $_GET['AN'];
        // $GET_MACD = $_GET['MACD'];

        //分期付款資料欄位
        //$ITA  = $_GET['ITA'];//分期總金額
        //$IP  = $_GET['IP'];//分期期數
        //$IPA   = $_GET['IPA '];//每期金額
        //$IFPA  = $_GET['IFPA'];//頭期款金額
        

        // $macd = hash( 'sha256', $data.','.$this->MAC );
        // if ( $macd != $GET_MACD ) {
        //     //交易驗證錯誤
        // } else {
        //     die('交易成功');
        // }

        $woo_order_id = ESUNPayment::parse_esun_order_no_to_woo_order_id( $params['ONO'] );

        $order = wc_get_order( $woo_order_id );

        if ( $order == null ) {
            die( __( 'No Such Order ID', 'wpbr-esun-payment' ) );
        }

        $order->update_meta_data( OrderMeta::TRADE_DATE, $params['LTD'] );
        $order->update_meta_data( OrderMeta::TRADE_TIME, $params['LTT'] );
        $order->update_meta_data( OrderMeta::RRN, $params['RRN'] );
        $order->update_meta_data( OrderMeta::AIR, $params['AIR'] );
        $order->update_meta_data( OrderMeta::AN, $params['AN'] );

        //分期付款資料欄位
        if ( array_key_exists( 'ITA', $params ) ) {
            $order->update_meta_data( OrderMeta::INSTALLMENT_TOTAL_AMOUNT, $params['ITA'] );
            $order->update_meta_data( OrderMeta::INSTALLMENT_NUMBER, $params['IP'] );
            $order->update_meta_data( OrderMeta::INSTALLMENT_EACH_AMOUNT, $params['IPA'] );
            $order->update_meta_data( OrderMeta::INSTALLMENT_FIRST_AMOUNT, $params['IFPA'] );
        }

        if ( $params['RC'] == '00' ) {

            $order->update_meta_data( OrderMeta::TRANS_STATUS, __( 'Success', 'wpbr-esun-payment' ) );
            $order->delete_meta_data( OrderMeta::ERROR_NO );
            $order->delete_meta_data( OrderMeta::ERROR_DESC );

            $order->payment_complete( $woo_order_id );
            $woocommerce->cart->empty_cart();

        } else {

            $order->update_meta_data( OrderMeta::TRANS_STATUS, __( 'Fail', 'wpbr-esun-payment' ) );
            $order->update_meta_data( OrderMeta::ERROR_NO, $params['RC'] );
            $order->update_meta_data( OrderMeta::ERROR_DESC, AuthReturnCode::getMessage($params['RC']) );

            $woocommerce->cart->empty_cart();

        }

        $order->save();

        header( "Location: " . $order->get_checkout_order_received_url() );

    }

}