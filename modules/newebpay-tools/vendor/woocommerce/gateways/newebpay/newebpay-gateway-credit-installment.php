<?php
class WU_NP_NewebPay_Gateway_Credit_Installment extends WU_NP_NewebPay_Gateway_Base {

	public $payment_type = 'InstFlag';

	public function __construct() {
		$this->id                 = 'wu_np_newebpay_credit_installment';
		$this->has_fields         = false;
		$this->order_button_text  = __( 'Pay via Credit(installment)', 'wu-newebpay-tools' );
		$this->method_title       = __( 'NewebPay Credit(installment)', 'wu-newebpay-tools' );
		$this->method_description = '';

		$this->form_fields = include WU_NP_WT_PLUGIN_DIR . 'woocommerce/gateways/newebpay/includes/settings-newebpay-gateway-credit-installment.php';
		$this->init_settings();

		$this->title             = $this->get_option( 'title' );
		$this->description       = $this->get_option( 'description' );
		$this->min_amount        = (int) $this->get_option( 'min_amount', 0 );
		$this->number_of_periods = $this->get_option( 'number_of_periods', [] );

		parent::__construct();
	}

	public function is_available() {
		if ( 'yes' == $this->enabled && WC()->cart ) {
			if ( empty( $this->number_of_periods ) ) {
				return false;
			}
			$total = $this->get_order_total();

			if ( $total > 0 ) {
				if ( $this->min_amount > 0 and $total < $this->min_amount ) {
					return false;
				}
			}
		}

		return parent::is_available();
	}

	public function payment_fields() {
		parent::payment_fields();
		$description = $this->get_description();
		if ( $description ) {
			echo wpautop( wptexturize( $description ) );
		}
		echo '<p>' . _x( 'Number of periods', 'Checkout info', 'wu-newebpay-tools' );
		echo ' <select name="newebpay_number_of_periods">';
		foreach ( $this->number_of_periods as $number_of_periods ) {
			echo '<option value="' . $number_of_periods . '">' . $number_of_periods . '</option>';
		}
		echo '</select>';
	}

	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		$order->add_order_note( __( 'Pay via NewebPay Credit(installment)', 'wu-newebpay-tools' ) );
		if ( isset( $_POST['newebpay_number_of_periods'] ) ) {
			$order->update_meta_data( '_newebpay_payment_number_of_periods', (int) $_POST['newebpay_number_of_periods'] );
		}
		$order->save();
		wc_maybe_reduce_stock_levels( $order_id );
		wc_release_stock_for_order( $order );

		return [
			'result'   => 'success',
			'redirect' => $order->get_checkout_payment_url( true ),
		];
	}

	public function process_admin_options() {
		parent::process_admin_options();
	}
}
