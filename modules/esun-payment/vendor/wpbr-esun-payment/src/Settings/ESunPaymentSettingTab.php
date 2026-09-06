<?php

namespace WPBrewer\ESun\Payment\Settings;

use WPBrewer\ESun\Payment\Settings\InstallmentTable;

/**
 * ESunPaymentSettingTab class file
 *
 * @package wpbr-esun-payment
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings class.
 */
class ESunPaymentSettingTab extends \WC_Settings_Page {
	/**
	 * Setting constructor.
	 */
	public function __construct() {

		$this->id    = 'esun';
		$this->label = __( 'ESUN Payment', 'wpbr-esun-payment' );

		add_action( 'woocommerce_settings_' . $this->id, array( $this, 'output' ) );
		add_action( 'woocommerce_settings_save_' . $this->id, array( $this, 'save' ) );
		add_action( 'woocommerce_sections_' . $this->id, array( $this, 'output_sections' ) );

		parent::__construct();
	}

	/**
	 * Get setting sections
	 *
	 * @return array
	 */
	public function get_sections() {

		$sections = array(
			'' => __( 'Payment Settings', 'wpbr-esun-payment' ),
			'installment' => __( 'Installment Settings', 'wpbr-esun-payment' ),
		);

		return apply_filters( 'woocommerce_get_sections_' . $this->id, $sections );
	}


	/**
	 * Get all the settings for this plugin for @see woocommerce_admin_fields() function.
	 *
	 * @param string $current_section The current section name.
	 * @return array Array of settings for @see woocommerce_admin_fields() function.
	 */
	public function get_settings( $current_section = '' ) {

		if ( '' === $current_section ) {
			$settings = apply_filters(
				'esun_payment_settings',
				array(
					array(
						'title' => __( 'General Payment Settings', 'wpbr-esun-payment' ),
						'type'  => 'title',
						'id'    => 'payment_general_setting',
					),
					array(
						'title'   => __( 'Debug Log', 'wpbr-esun-payment' ),
						'type'    => 'checkbox',
						'default' => 'no',
						'desc'    => sprintf( __( 'Log ESUN Payment info. You Can find logs at WooCommerce -> Status -> Logs with source name "wpbr-esun-payment". %s', 'wpbr-esun-payment' ), $this->get_log_link() ),
						'id'      => 'esun_payment_debug_log_enabled',
					),
					array(
						'type' => 'sectionend',
						'id'   => 'payment_general_setting',
					),
					array(
						'title' => __( 'API Settings', 'wpbr-esun-payment' ),
						'type'  => 'title',
						'desc'  => __( 'Enter your ESUN API credentials', 'wpbr-esun-payment' ),
						'id'    => 'nccc_payment_api_settings',
					),
					array(
						'title'   => __( 'Test Mode', 'wpbr-esun-payment' ),
						'type'    => 'checkbox',
						'default' => 'yes',
						'desc'    => __( 'When enabled, you need to use the test-only data below.', 'wpbr-esun-payment' ),
						'id'      => 'esun_payment_testmode_enabled',
					),
					'mid' => array(
						'title'       => __( 'MID', 'wpbr-esun-payment' ),
						'type'        => 'text',
						'desc' => __('This is the MID when you apply esun API', 'wpbr-esun-payment'),
						'desc_tip'    => true,
						'id' => 'esun_payment_mid'
					),
					'mac' => array(
						'title'       => __( 'MAC', 'wpbr-esun-payment' ),
						'type'        => 'text',
						'desc' => __('This is the MAC when you apply esun API', 'woo-esun-payment'),
						'desc_tip'    => true,
						'id' => 'esun_payment_mac'
					),
					array(
						'type' => 'sectionend',
						'id'   => 'esun_payment_api_settings',
					),
				)
			);
		} elseif ( 'installment' === $current_section ) {
			$settings = apply_filters(
				'esun_payment_installment_settings',
				array(
					array(
						'title' => __( 'Installment Settings', 'wpbr-esun-payment' ),
						'type'  => 'title',
						'id'    => 'installment_setting',
					),
					array(
						'type' => 'esun_payment_installs',
						'id' => 'esun_payment_installs',
					),
					array(
						'type' => 'sectionend',
						'id'   => 'installment_setting',
					),
				)
			);
		}
		return apply_filters( 'woocommerce_get_settings_' . $this->id, $settings, $current_section );
	}

	/**
	 * Output the setting tab
	 *
	 * @return void
	 */
	public function output() {
		global $current_section;

		// Check if we're editing an installment
		if ( isset( $_GET['edit-installment'] ) ) {
			$GLOBALS['hide_save_button'] = true;
			// Force section to installment when editing
			$current_section = 'installment';
			
			$template = ESUN_INCLUDES_DIR . 'views/installment-edit.php';
			if ( file_exists( $template ) ) {
				include $template;
				return;
			}
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

		$settings = $this->get_settings( $current_section );
		\WC_Admin_Settings::save_fields( $settings );
	}

	protected function get_log_link() {
        return '<a href="'. esc_url( admin_url( 'admin.php?page=wc-status&tab=logs&source=wpbr-esun-payment')). '">' . __( 'View logs', 'wpbr-esun-payment' ).'</a>';
    }
}
