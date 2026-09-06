<?php

namespace WPBrewer\ESun\Payment;

use WPBrewer\ESun\Payment\Admin\OrderMetaBoxes;
use WPBrewer\ESun\Payment\Api\ESunPaymentResponse;
use WPBrewer\ESun\Payment\Gateways\ESunPaymentCredit;
use WPBrewer\ESun\Payment\Gateways\ESunPaymentInstallment;
use WPBrewer\ESun\Payment\Settings\InstallmentTable;
use WPBrewer\ESun\Payment\Settings\ESunPaymentSettingTab;
use WPBrewer\ESun\Payment\Utils\OrderMeta;
use WPBrewer\ESun\Payment\Utils\SingletonTrait;

/**
 * ESunPayment class file
 *
 * @package wpbr-esun-payment
 */

defined( 'ABSPATH' ) || exit;
/**
 * ESunPayment main class for handling all checkout related process.
 */
class ESunPayment {

	use SingletonTrait;

	/**
	 * Whether or not logging is enabled.
	 *
	 * @var boolean
	 */
	public static $log_enabled = false;

	/**
	 * WC_Logger instance.
	 *
	 * @var WC_Logger Logger instance
	 * */
	public static $log = false;

	public static $testmode;

	public static $MID;

	public static $MAC;

	public static $api_url;

	/**
	 * Suppoeted payment gateways
	 *
	 * @var array
	 * */
	public static $allowed_payments;

	/**
	 * Initialize class and add hooks
	 *
	 * @return void
	 */
	public static function init() {

		self::get_instance();

		self::$log_enabled = 'yes' === get_option( 'esun_payment_debug_log_enabled', 'no' );

		self::$MID = get_option( 'esun_payment_mid' );
		self::$MAC = get_option( 'esun_payment_mac' );

		self::$testmode = wc_string_to_bool( get_option( 'esun_payment_testmode_enabled' ) );
		if ( self::$testmode ) {
		   self::$api_url = ( wp_is_mobile() ) ? 'https://acqtest.esunbank.com.tw/ACQTrans/esuncard/txnf014m' : 'https://acqtest.esunbank.com.tw/ACQTrans/esuncard/txnf014s';
	   } else {
		   self::$api_url = ( wp_is_mobile() ) ? 'https://acq.esunbank.com.tw/ACQTrans/esuncard/txnf014m' : 'https://acq.esunbank.com.tw/ACQTrans/esuncard/txnf014s';
	   }
		
		OrderMetaBoxes::init();
		ESunPaymentResponse::init();
		InstallmentTable::register_ajax_handlers();

		self::$allowed_payments = array(
			ESunPaymentCredit::GATEWAY_ID      => '\\WPBrewer\\ESun\\Payment\\Gateways\\ESunPaymentCredit',
			ESunPaymentInstallment::GATEWAY_ID => '\\WPBrewer\\ESun\\Payment\\Gateways\\ESunPaymentInstallment',
		);

		load_plugin_textdomain( 'wpbr-esun-payment', false, dirname( ESUN_BASENAME ) . '/languages/' );

		add_filter( 'woocommerce_get_settings_pages', array( self::get_instance(), 'esun_add_settings' ), 15 );
		
		add_filter( 'woocommerce_payment_gateways', array( self::get_instance(), 'add_esun_payment_gateway' ) );

		// 新增自訂分期付款期數設定欄位
		add_action( 'woocommerce_admin_field_esun_payment_installs', array( self::get_instance(), 'esun_add_installs_settings' ) );

		add_filter( 'woocommerce_order_get_payment_method_title', array( self::get_instance(), 'installment_payment_method_title' ), 10, 2 );

		add_action( 'admin_enqueue_scripts', array( self::get_instance(), 'enqueue_admin_assets' ) );

	}

	public function installment_payment_method_title( $title, $order ) {
		if ( $order->get_payment_method() == ESunPaymentInstallment::GATEWAY_ID ) {
			return $title . sprintf( __( ' (%d installments)', 'wpbr-esun-payment' ), $order->get_meta( OrderMeta::INSTALLMENT_NUMBER ) );
		}
		return $title;
	}

	public function esun_add_installs_settings() {
		$GLOBALS['hide_save_button'] = true;
		echo '<div id="esun_payment_installs"></div>';
		$installs_table = new InstallmentTable();
		$installs_table->prepare_items();
		$installs_table->display();
	}

	//玉山訂單編號 = woo訂單id + 3位數字(開始於101)
    public static function build_esun_order_no( $order_id ) {

        $esun_order_no = $order_id;
		$order = wc_get_order( $order_id );

		$esun_trans_no = $order->get_meta( OrderMeta::TRANS_SERIAL_NO );
        if ($esun_trans_no) {
            $esun_trans_no += 1;
            $esun_order_no = $esun_order_no . $esun_trans_no ;
        } else {
            $esun_trans_no = 101;
            $esun_order_no = $esun_order_no . $esun_trans_no ;
        }

        $order->update_meta_data( OrderMeta::TRANS_SERIAL_NO, $esun_trans_no );
        $order->save();

        return $esun_order_no;

    }


    //將玉山的訂單編號轉成woo的訂單編號
    public static function parse_esun_order_no_to_woo_order_id( $esun_order_no ) {
        $real_woo_order_id = substr($esun_order_no, 0, -3);
        return $real_woo_order_id;
    }

	/**
	 * Add payment gateways
	 *
	 * @param array $methods ESUN Payment gateways.
	 * @return array
	 */
	public function add_esun_payment_gateway( $methods ) {
		$merged_methods = array_merge( $methods, self::$allowed_payments );
		return $merged_methods;
	}

	/**
	 * Plugin action links
	 *
	 * @param array $links The action links array.
	 * @return array
	 */
	public function esun_add_action_links( $links ) {
		$action_links = array(
			'settings' => 
			'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=esun_payment' ) . '">' . __( 'Settings', 'wpbr-esun-payment' ) . '</a>',
		);

		return array_merge( $action_links, $links );
	}


	/**
	 * Add settings tab
	 *
	 * @return ESunPaymentSettingTab
	 */
	public function esun_add_settings( $settings ) {

		if ( is_array( $settings ) ) {
			$settings[] = new ESunPaymentSettingTab();
		} else {
			$other_settings = $settings;
			$settings       = array( new ESunPaymentSettingTab(), $other_settings );
		}

		return $settings;
	}

	/**
	 * Log method.
	 *
	 * @param string $message The message to be logged.
	 * @param string $level The log level. Optional. Default 'info'. Possible values: emergency|alert|critical|error|warning|notice|info|debug.
	 * @return void
	 */
	public static function log( $message, $level = 'info' ) {
		if ( empty( self::$log ) ) {
			self::$log = wc_get_logger();
		}

		self::$log->log( $level, $message, array( 'source' => 'wpbr-esun-payment' ) );
	}

	/**
	 * Enqueue admin assets
	 */
	public function enqueue_admin_assets( $hook ) {

		wp_enqueue_style(
			'wpbr-esun-payment-admin',
			plugins_url( 'assets/css/admin.css', dirname( __FILE__ ) ),
			array(),
			WPBR_ESUN_PAYMENT_VERSION
		);

		if ( isset( $_GET['section'] ) && 'installment' === $_GET['section'] && !isset( $_GET['edit-installment'] ) ) {
			wp_enqueue_script(
				'wpbr-esun-payment-admin',
				plugins_url( 'assets/js/installments.js', dirname( __FILE__ ) ),
				array( 'jquery' ),
				WPBR_ESUN_PAYMENT_VERSION,
				true
			);
		}
	}

}
