<?php

namespace WPBrewer\ESun\Payment\Gateways;

use WPBrewer\ESun\Payment\Api\ESunPaymentRequest;
use WPBrewer\ESun\Payment\Lib\Esunacq\TxnType;
use WPBrewer\ESun\Payment\Utils\OrderMeta;

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}
/**
 *
 * @link       http://wpbrewer.com/
 * @since      1.0.0
 *
 * @package   wpbr-esun-payment
 * @subpackage wpbr-esun-payment/src
 */
/*
 * @since      1.0.0
 * @package    wpbr-esun-payment
 * @subpackage wpbr-esun-payment/src
 * @author     Yu Cheng Wang <ucheng.wang@gmail.com>
 */


class ESunPaymentCredit extends AbstractESunPaymentGateway {

    protected $api_helper;

    const GATEWAY_ID = 'esun-credit';
    
    const TID = TxnType::GENERAL;

    public function __construct() {

		parent::__construct();

        //woo
        $this->id                 = self::GATEWAY_ID;
        $this->method_title       = __( 'ESUN Credit Card', 'wpbr-esun-payment' );
        $this->method_description = __( 'ESUN Credit Card Payment Gateway', 'wpbr-esun-payment');
        $this->order_button_text  = $this->get_option( 'order_button_text' );

        //woo
        $this->init_form_fields();
        $this->init_settings();

        // Define user set variables
        $this->title       = $this->get_option( 'title' );
        $this->description = $this->get_option( 'description' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'esun_payment_detail_after_order_table'), 10, 1 );

    }

    public function init_form_fields() {
        $this->form_fields = include ESUN_PLUGIN_DIR . 'includes/settings/settings-esun-credit.php';
    }

    /**
     * redirect to esun payment page
     *
     * @access public
     * @return void
     */
    function receipt_page( $order ) {
        WC()->cart->empty_cart();
		$request = new ESunPaymentRequest();
		$request->set_gateway( $this );
        $request->build_request_form( $order );
    }

    public function esun_payment_detail_after_order_table( $order ) {

        if ( $order->get_payment_method() == $this->id ) {

            echo '<header><h2>'.__( 'ESUN Payment Detail', 'wpbr-esun-payment' ).'</h2></header><table class="shop_table esun_payment_details"><tbody>';

            echo '<tr><td><strong>' . __( 'Transaction Status', 'wpbr-esun-payment' ) . '</strong></td>';
            echo '<td>' . $order->get_meta( OrderMeta::TRANS_STATUS ) . '</td></tr>';

            echo '<tr><td><strong>' . __( 'Auth Code', 'wpbr-esun-payment' ) . '</strong></td>';
            echo '<td>' . $order->get_meta( OrderMeta::AIR ) . '</td></tr>'; //授權碼

            echo '<tr><td><strong>' . __( 'Transaction Date', 'wpbr-esun-payment' ) . '</strong></td>';
            echo '<td>' . $order->get_meta( OrderMeta::TRADE_DATE ) . '</td></tr>';

            echo '<tr><td><strong>' . __( 'Transaction Time', 'wpbr-esun-payment' ) . '</strong></td>';
            echo '<td>' . $order->get_meta( OrderMeta::TRADE_TIME ) . '</td></tr>';

            $errorcode = $order->get_meta( OrderMeta::ERROR_NO );
            if ( ! empty( $errorcode ) ) {
                echo '<tr><td><strong>' . __( 'Error Code', 'wpbr-esun-payment' ) . '</strong></td>';
                echo '<td>' . $order->get_meta( OrderMeta::ERROR_NO ) . '</td></tr>';

                echo '<tr><td><strong>' . __( 'Error Description', 'wpbr-esun-payment' ) . '</strong></td>';
                echo '<td>' . $order->get_meta( OrderMeta::ERROR_DESC ) . '</td></tr>';
            }

            echo '</tbody></table>';

        }

    }

}
