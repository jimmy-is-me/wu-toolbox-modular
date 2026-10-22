<?php
class WU_NP_NewebPay_Gateway_Base extends WC_Payment_Gateway {

	public static $log_enabled = false;
	public static $log         = false;

	/** @var int|string 最低訂單金額（各子類別建構時由設定載入） */
	public $min_amount;

	/** @var int|string 繳費期限天數（僅 ATM／超商代碼／超商條碼使用） */
	public $expire_date;

	/** @var array|string 信用卡分期期數（僅分期閘道使用；未設定時維持 null，isset() 判斷不變） */
	public $number_of_periods;

	public function __construct() {
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );

		if ( $this->enabled ) {
			add_action( 'woocommerce_receipt_' . $this->id, [ $this, 'receipt_page' ] );
		}
	}

	public function get_icon() {
		$icon_html = '<img src="' . esc_attr( WU_NP_WT_PLUGIN_URL . 'icon/newebpay_logo.png' ) . '" alt="' . esc_attr__( 'NewebPay', 'wu-newebpay-tools' ) . '">';

		return apply_filters( 'woocommerce_gateway_icon', $icon_html, $this->id );
	}

	public function process_admin_options() {
		$filed_name           = 'woocommerce_' . $this->id . '_min_amount';
		$_POST[ $filed_name ] = (int) $_POST[ $filed_name ];
		if ( $_POST[ $filed_name ] < 0 ) {
			$_POST[ $filed_name ] = 0;
			WC_Admin_Settings::add_error( sprintf( __( '%s minimum amount out of range. Set as default value.', 'wu-newebpay-tools' ), $this->method_title ) );
		}

		parent::process_admin_options();
	}

	public function receipt_page( $order_id ) {
		if ( $order = wc_get_order( $order_id ) ) {
			WU_NP_NewebPay_Gateway_Api::checkout_form( $order, $this );

			WC()->cart->empty_cart();
		}
	}
}
