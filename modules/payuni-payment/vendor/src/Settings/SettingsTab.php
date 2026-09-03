<?php
/**
 * PAYUNi setting class.
 *
 * @package payuni
 */

namespace WPBrewer\Payuni\Payment\Settings;

use WPBrewer\Payuni\Payment\Gateways\CreditInstallment12;
use WPBrewer\Payuni\Payment\Gateways\CreditInstallment18;
use WPBrewer\Payuni\Payment\Gateways\CreditInstallment24;
use WPBrewer\Payuni\Payment\Gateways\CreditInstallment3;
use WPBrewer\Payuni\Payment\Gateways\CreditInstallment30;
use WPBrewer\Payuni\Payment\Gateways\CreditInstallment6;
use WPBrewer\Payuni\Payment\Gateways\CreditInstallment9;

defined( 'ABSPATH' ) || exit;

/**
 * Settings class.
 */
class SettingsTab extends \WC_Settings_Page {

	/**
	 * Setting constructor.
	 */
	public function __construct() {

		$this->id    = 'payuni';
		$this->label = __( 'PAYUNi', 'wpbr-payuni-payment' );

		add_action( 'woocommerce_settings_' . $this->id, array( $this, 'output' ) );
		add_action( 'woocommerce_settings_save_' . $this->id, array( $this, 'save' ) );

		add_action( 'admin_init', array( $this, 'payuni_redirect_default_tab' ) );

		add_filter( 'woocommerce_get_sections_' . $this->id, array( $this, 'payuni_payment_sections' ), 10, 1 );

		parent::__construct();
	}

	/**
	 * Get settings array.
	 *
	 * @param  mixed $sections The sections.
	 * @return mixed The sections.
	 */
	public function payuni_payment_sections( $sections ) {

		unset( $sections[''] );
		if ( is_array( $sections ) && ! array_key_exists( 'payment', $sections ) ) {
			$sections['payment'] = __( 'Payment Settings', 'wpbr-payuni-payment' );
		}
		return $sections;
	}

	/**
	 * The settings for payment section.
	 *
	 * @return array The settings.
	 */
	public function get_settings_for_payment_section() {
		/**
		 * Hooks to filter the settings.
		 *
		 * @since 1.0.0
		 */
		$settings = apply_filters(
			'payuni_payment_settings',
			array(
				array(
					'title' => __( 'Payment Settings', 'wpbr-payuni-payment' ),
					'type'  => 'title',
					'id'    => 'payment_general_setting',
				),
				array(
					'title'    => __( 'Debug Log', 'wpbr-payuni-payment' ),
					'type'     => 'checkbox',
					'default'  => 'yes',
					'desc'     => __( 'Log PAYUNi payment message', 'wpbr-payuni-payment' ),
					/* translators:  %s is the WooCommerce log page url */
					'desc_tip' => sprintf( __( 'You Can find logs with source name <strong>wpbr-payuni-payment</strong> at <strong>WooCommerce -> Status -> Logs</strong>. %s', 'wpbr-payuni-payment' ), $this->get_log_link() ),
					'id'       => 'payuni_payment_debug_log_enabled',
				),
				array(
					'title'   => __( 'Number of Payments', 'wpbr-payuni-payment' ),
					'type'    => 'multiselect',
					'class'   => 'wc-enhanced-select',
					'css'     => 'width: 400px;',
					'options' => array(
						CreditInstallment3::GATEWAY_ID  => 3,
						CreditInstallment6::GATEWAY_ID  => 6,
						CreditInstallment9::GATEWAY_ID  => 9,
						CreditInstallment12::GATEWAY_ID => 12,
						CreditInstallment18::GATEWAY_ID => 18,
						CreditInstallment24::GATEWAY_ID => 24,
						CreditInstallment30::GATEWAY_ID => 30,
					),
					/* translators:  %s is the WooCommerce payment setting page url */
					'desc'    => sprintf( __( 'The number of payments display on Payments section, after setting you still need to eanble each payment in Payments section. %s', 'wpbr-payuni-payment' ), $this->get_woo_payment_settings_url() ),
					'id'      => 'payuni_payment_installment_number_of_payments',
				),
				array(
					'title'   => __( 'Language', 'wpbr-payuni-payment' ),
					'type'    => 'select',
					'css'     => 'width: 400px;',
					'options' => array(
						'zh-tw' => __( 'Traditional Chinese', 'wpbr-payuni-payment' ),
						'en'    => __( 'English', 'wpbr-payuni-payment' ),
					),
					'default' => 'zh-tw',
					'desc'    => __( 'The language of the PAYUNi checkout page.', 'wpbr-payuni-payment' ),
					'id'      => 'payuni_payment_language',
				),
				array(
					'title'   => __( 'E-Invoice', 'wpbr-payuni-payment' ),
					'type'    => 'checkbox',
					'default' => 'no',
					'desc'    => __( 'Enable E-Invoice', 'wpbr-payuni-payment' ),
					'desc_tip' => __( 'You need to register Amego e-invoice and enable e-invoice feature at PAYUNi website.', 'wpbr-payuni-payment' ),
					'id'      => 'payuni_payment_einvoice_enabled',
				),
				array(
					'title'    => __( 'Auto Cancel Expired ATM/CVS Orders', 'wpbr-payuni-payment' ),
					'type'     => 'checkbox',
					'default'  => 'no',
					'desc'     => __( 'Enable auto-cancellation for expired ATM/CVS orders', 'wpbr-payuni-payment' ),
					'desc_tip' => __( 'Automatically cancel ATM and CVS orders when the payment deadline has passed.', 'wpbr-payuni-payment' ),
					'id'       => 'payuni_payment_auto_cancel_enabled',
				),
				array(
					'title'             => __( 'Auto Cancel Delay (minutes)', 'wpbr-payuni-payment' ),
					'type'              => 'number',
					'default'           => '10',
					'desc'              => __( 'Delay in minutes after the payment deadline before auto-cancelling the order.', 'wpbr-payuni-payment' ),
					'id'                => 'payuni_payment_auto_cancel_delay_minutes',
					'css'               => 'width: 80px;',
					'custom_attributes' => array(
						'min'  => '0',
						'step' => '1',
					),
				),
				array(
					'type' => 'sectionend',
					'id'   => 'payment_general_setting',
				),
				array(
					'title' => __( 'API Settings', 'wpbr-payuni-payment' ),
					'type'  => 'title',
					'desc'  => __( 'Enter your PAYUNi API credentials', 'wpbr-payuni-payment' ),
					'id'    => 'payuni_payment_api_settings',
				),
				array(
					'title'   => __( 'Test Mode', 'wpbr-payuni-payment' ),
					'type'    => 'checkbox',
					'default' => 'yes',
					'desc'    => __( 'When enabled, you need to use the test-only data below.', 'wpbr-payuni-payment' ),
					'id'      => 'payuni_payment_testmode_enabled',
				),
				array(
					'title'    => __( 'Test MerchantID', 'wpbr-payuni-payment' ),
					'type'     => 'text',
					'desc'     => __( 'This is the test MerchantID when you apply PAYUNi API', 'wpbr-payuni-payment' ),
					'desc_tip' => true,
					'id'       => 'payuni_payment_merchant_id_test',
				),
				array(
					'title'    => __( 'Test Hash Key', 'wpbr-payuni-payment' ),
					'type'     => 'text',
					'desc'     => __( 'This is the test Hash Key when you apply PAYUNi API', 'wpbr-payuni-payment' ),
					'desc_tip' => true,
					'id'       => 'payuni_payment_hashkey_test',
				),
				array(
					'title'    => __( 'Test Hash IV', 'wpbr-payuni-payment' ),
					'type'     => 'text',
					'desc'     => __( 'This is the test Hash IV when you apply PAYUNi API', 'wpbr-payuni-payment' ),
					'desc_tip' => true,
					'id'       => 'payuni_payment_hashiv_test',
				),
				array(
					'title'    => __( 'MerchantID', 'wpbr-payuni-payment' ),
					'type'     => 'text',
					'desc'     => __( 'This is the MerchantID when you apply PAYUNi API', 'wpbr-payuni-payment' ),
					'desc_tip' => true,
					'id'       => 'payuni_payment_merchant_id',
				),
				array(
					'title'    => __( 'Hash Key', 'wpbr-payuni-payment' ),
					'type'     => 'text',
					'desc'     => __( 'This is the Hash Key when you apply PAYUNi API', 'wpbr-payuni-payment' ),
					'desc_tip' => true,
					'id'       => 'payuni_payment_hashkey',
				),
				array(
					'title'    => __( 'Hash IV', 'wpbr-payuni-payment' ),
					'type'     => 'text',
					'desc'     => __( 'This is the Hash IV when you apply PAYUNi API', 'wpbr-payuni-payment' ),
					'desc_tip' => true,
					'id'       => 'payuni_payment_hashiv',
				),
				array(
					'type' => 'sectionend',
					'id'   => 'payuni_payment_api_settings',
				),
			)
		);

		return $settings;
	}

	/**
	 * Redirect to the default tab.
	 *
	 * @return void
	 */
	public function payuni_redirect_default_tab() {

		global $pagenow;

		if ( 'admin.php' !== $pagenow ) {
			return;
		}

     // phpcs:disable WordPress.Security.NonceVerification.Recommended
		$page    = ( array_key_exists( 'page', $_GET ) ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		$tab     = ( array_key_exists( 'tab', $_GET ) ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : '';
		$section = ( array_key_exists( 'section', $_GET ) ) ? sanitize_text_field( wp_unslash( $_GET['section'] ) ) : '';

		if ( 'wc-settings' === $page && 'payuni' === $tab ) {

			if ( empty( $section ) ) {
				wp_safe_redirect( admin_url( 'admin.php?page=wc-settings&tab=payuni&section=payment' ) );
				exit;
			}
		}
	}

	/**
	 * Output the setting tab
	 *
	 * @return void
	 */
	public function output() {
		global $current_section;

		if ( 'payment' !== $current_section ) {
			return;
		}

		$settings = $this->get_settings( $current_section );
		\WC_Admin_Settings::output_fields( $settings );
	}

	/**
	 * Save the settings
	 *
	 * @return void
	 */
	public function save() {
		global $current_section;

		if ( 'payment' !== $current_section ) {
			return;
		}

		$settings = $this->get_settings( $current_section );
		\WC_Admin_Settings::save_fields( $settings );
	}

	/**
	 * Get debug logs url.
	 */
	private function get_log_link() {
		return '<a href="' . esc_url( admin_url( 'admin.php?page=wc-status&tab=logs' ) ) . '" target="_blank">' . __( 'View logs', 'wpbr-payuni-payment' ) . '</a>';
	}

	/**
	 * Get woo Payment sections url.
	 */
	private function get_woo_payment_settings_url() {
		return '<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout' ) ) . '" target="_blank">' . __( 'Go to Payment Settings', 'wpbr-payuni-payment' ) . '</a>';
	}
}
