<?php
use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Class LINEPay_Block
 */
final class LINEPay_Block extends AbstractPaymentMethodType {


	/**
	 * The payment method ID
	 *
	 * @var string
	 */
	protected $name = WPBR_LINEPay_Const::ID;

	/**
	 * Initialize the payment method
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_linepay-tw_settings', array() );
	}

	/**
	 * Is the payment method available?
	 */
	public function is_active() {
		return filter_var( $this->get_setting( 'enabled', false ), FILTER_VALIDATE_BOOLEAN );
	}

	public function get_payment_method_script_handles() {

		wp_register_script(
			'wpbr-linepay-block',
			WUTM_LINEPAY_PLUGIN_URL . 'assets/js/blocks/linepay.js',
			array(
				'wc-blocks-registry',
				'wc-settings',
				'wp-element',
				'wp-html-entities',
			),
			null,
			true
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'wpbr-linepay-block' );

		}

		return array( 'wpbr-linepay-block' );
	}

	/**
	 * Get the payment method data and settings.
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		return array(
			'title'        => $this->get_setting( 'title' ),
			'description'  => $this->get_setting( 'description' ),
		);
	}
}
