<?php

namespace WuDigital\Payuni\Shipping\Settings;
/**
 * PAYUNi Shipping setting class.
 *
 * @package payuni
 */

defined( 'ABSPATH' ) || exit;

/**
 * SettingsTab class.
 */
class SettingsTab extends \WC_Settings_Page {
    /** Detect the payment companion without depending on its vendor prefix. */
    private static function is_payment_active() {
        $plugins = (array) get_option( 'active_plugins', array() );
        if ( is_multisite() ) {
            $plugins = array_merge( $plugins, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
        }
        foreach ( $plugins as $plugin ) {
            if ( preg_match( '~(?:^|/)[^/]*payuni-payment\.php$~i', $plugin ) ) {
                return true;
            }
        }
        return false;
    }


	private static $sections;
	/**
	 * Setting constructor.
	 */
	public function __construct() {

		$this->id    = 'payuni';
		$this->label = __( 'PAYUNi', 'wu-payuni-shipping' );

		self::$sections = array(
			'shipping' => __( 'Shipping Settings', 'wu-payuni-shipping' ),
		);

		add_action( 'woocommerce_settings_' . $this->id, array( $this, 'output' ) );
		add_action( 'woocommerce_settings_save_' . $this->id, array( $this, 'save' ) );

		add_action( 'admin_init', array( $this, 'payuni_shipping_redirect_default_tab' ) );

		add_filter( 'woocommerce_get_sections_' . $this->id, array( $this, 'payuni_shipping_sections' ), 10, 1 );

		parent::__construct();
	}

	/**
	 * Add shipping sections tab
	 *
	 * @param array $sections The settings section.
	 * @return array
	 */
	public function payuni_shipping_sections( $sections ) {

		if ( is_array( $sections ) && ! array_key_exists( 'shipping', $sections ) ) {
			$sections['shipping'] = __( 'Shipping Settings', 'wu-payuni-shipping' );
		}
		return $sections;
	}

	/**
	 * Get setting sections
	 *
	 * @return array
	 */
	public function get_sections() {

		// 如果 PAYUNi payment 沒有啟用，才回傳 shipping array.
		if ( ! self::is_payment_active() ) {
			$sections = self::$sections;
			return apply_filters( 'woocommerce_get_sections_' . $this->id, $sections );
		}

	}


	/**
	 * Get all the settings for this plugin for @see woocommerce_admin_fields() function.
	 *
	 * @return array Array of settings for @see woocommerce_admin_fields() function.
	 */
	public function get_settings( $current_section = '' ) {

		$settings = array();
		if ( 'shipping' === $current_section ) {
			$settings = apply_filters(
				'payuni_shipping_settings',
				array(
					array(
						'title' => __( 'General Shipping Settings', 'wu-payuni-shipping' ),
						'type'  => 'title',
						'id'    => 'shipping_general_setting',
					),
					array(
						'title'   => __( 'Debug Log', 'wu-payuni-shipping' ),
						'type'    => 'checkbox',
						'label'   => __( 'Enable logging', 'wu-payuni-shipping' ),
						'default' => 'no',
						'desc'    => sprintf( __( 'Log PAYUNi Shipping message, inside <code>%s</code>', 'wu-payuni-shipping' ), wc_get_log_file_path( 'wu-payuni-shipping' ) ),
						'id'      => 'payuni_shipping_debug_log_enabled',
					),
                    array(
                        'title'   => __( '7-11 建立物流單', 'wu-payuni-shipping' ),
                        'type'    => 'checkbox',
                        'desc'    => __( '啟用 7-11 物流單建立', 'wu-payuni-shipping' ),
                        'default' => 'no',
                        'id'      => 'payuni_shipping_711_create_order_enabled',
                    ),
					array(
						'type' => 'sectionend',
						'id'   => 'shipping_general_setting',
					),
					array(
						'title' => __( 'Store Settings', 'wu-payuni-shipping' ),
						'type'  => 'title',
						'desc'  => __( 'Enter your store information', 'wu-payuni-shipping' ),
						'id'    => 'payuni_shipping_store_settings',
					),
					array(
						'title'    => __( 'Sender Name', 'wu-payuni-shipping' ),
						'type'     => 'text',
						'desc'     => __( 'Please enter the sender name. It may be used when the order is returned.', 'wu-payuni-shipping' ),
						'desc_tip' => true,
						'id'       => 'payuni_shipping_sender_name',
					),
					array(
						'title' => __( 'Sender Phone', 'wu-payuni-shipping' ),
						'type'  => 'text',
						'id'    => 'payuni_shipping_sender_phone',
					),
					array(
						'type' => 'sectionend',
						'id'   => 'shipping_store_setting',
					),
					array(
						'title' => __( 'Shipping Order Status Settings', 'wu-payuni-shipping' ),
						'type'  => 'title',
						'desc'  => __( 'Manage your shipping order status', 'wu-payuni-shipping' ),
						'id'    => 'payuni_shipping_shipping_settings',
					),
					array(
						'title'   => __( 'When ShipNo are generated (ShipStatus=21), change order status to', 'wu-payuni-shipping' ),
						'type'    => 'select',
						'options' => self::payuni_get_order_status(),
						'id'      => 'payuni_shipping_order_status_ready_shipping',
					),
					array(
						'title'   => __( 'When products are located at logistic center (ShipStatus=22), change order status to', 'wu-payuni-shipping' ),
						'type'    => 'select',
						'options' => self::payuni_get_order_status(),
						'id'      => 'payuni_shipping_order_status_at_logistic_center',
					),
					array(
						'title'   => __( 'When products are delivering (ShipStatus=31), change order status to', 'wu-payuni-shipping' ),
						'type'    => 'select',
						'options' => self::payuni_get_order_status(),
						'id'      => 'payuni_shipping_order_status_delivering',
					),
					array(
						'title'   => __( 'When products are at located receiver CVS store (ShipStatus=32), change order status to', 'wu-payuni-shipping' ),
						'type'    => 'select',
						'options' => self::payuni_get_order_status(),
						'id'      => 'payuni_shipping_order_status_at_receiver_cvs',
					),
					array(
						'title'   => __( 'When customer pickuped or received products (ShipStatus=11), change order status to', 'wu-payuni-shipping' ),
						'type'    => 'select',
						'options' => self::payuni_get_order_status(),
						'id'      => 'payuni_shipping_order_status_pickuped',
					),
					array(
						'type' => 'sectionend',
						'id'   => 'shipping_order_setting',
					),
					array(
						'title' => __( 'TCat Shipping Settings', 'wu-payuni-shipping' ),
						'type'  => 'title',
						'id'    => 'shipping_TCat_setting',
					),
					array(
						'title'    => __( 'Delivery Time', 'wu-payuni-shipping' ),
						'type'     => 'select',
						'options'  => array(
							'01' => __( 'Before 13:00', 'wu-payuni-shipping' ),
							'02' => __( '14:00~18:00', 'wu-payuni-shipping' ),
							'04' => __( 'Not Set', 'wu-payuni-shipping' ),
							),
						'default' => '04',
						'id'      => 'payuni_shipping_tcat_delivery_time',
					),
					array(
						'title'    => __( 'Estimate shipping date', 'wu-payuni-shipping' ),
						'type'     => 'number',
						'default'  => 1,
						'desc'     => __( 'When will the estimate shipping date. Default is 1, which means the estimate shipping date is the the next day of the shipping order created.', 'wu-payuni-shipping' ),
						'desc_tip' => true,
						'id'       => 'payuni_shipping_tcat_estimate_shipping_date',
					),
					array(
						'type' => 'sectionend',
						'id'   => 'shipping_TCat_setting',
					),
					array(
						'title' => __( 'API Settings', 'wu-payuni-shipping' ),
						'type'  => 'title',
						'desc'  => __( 'Enter your PAYUNi shipping user account and API Code', 'wu-payuni-shipping' ),
						'id'    => 'payuni_shipping_api_settings',
					),
					array(
						'title'   => __( 'Test Mode', 'wu-payuni-shipping' ),
						'type'    => 'checkbox',
						'label'   => __( 'Enable Test Mode', 'wu-payuni-shipping' ),
						'default' => 'yes',
						'desc'    => __( 'When enabled, you need to use the test-only User Account and API Code.', 'wu-payuni-shipping' ),
						'id'      => 'payuni_shipping_testmode_enabled',
					),
					array(
						'title'    => __( 'MerchantID', 'wu-payuni-shipping' ),
						'type'     => 'text',
						'desc'     => __( 'This is the MerchantID when you apply PAYUNi API', 'wu-payuni-shipping' ),
						'desc_tip' => true,
						'id'       => 'payuni_payment_merchant_id',
					),
					array(
						'title'    => __( 'HashKey', 'wu-payuni-shipping' ),
						'type'     => 'text',
						'desc'     => __( 'This is the HashKey when you apply PAYUNi API', 'wu-payuni-shipping' ),
						'desc_tip' => true,
						'id'       => 'payuni_payment_hashkey',
					),
					array(
						'title'    => __( 'HashIV', 'wu-payuni-shipping' ),
						'type'     => 'text',
						'desc'     => __( 'This is the HashIV when you apply PAYUNi API', 'wu-payuni-shipping' ),
						'desc_tip' => true,
						'id'       => 'payuni_payment_hashiv',
					),
					array(
						'type' => 'sectionend',
						'id'   => 'payuni_shipping_api_settings',
					),
				)
			);
		}

		return apply_filters( 'woocommerce_get_settings_' . $this->id, $settings, $current_section );
	}

	/**
	 * Get order status
	 *
	 * @return array
	 */
	private static function payuni_get_order_status() {
		$order_statuses = array(
			'' => __( 'No action', 'wu-payuni-shipping' ),
		);

		foreach ( wc_get_order_statuses() as $slug => $name ) {
			if ( $slug == 'wc-cancelled' || $slug == 'wc-refunded' || $slug == 'wc-failed' ) {
				continue;
			}
			$order_statuses[ str_replace( 'wc-', '', $slug ) ] = $name;
		}


		return $order_statuses;
	}

	/**
	 * Redirect to shipping tab if payuni payment plugin is not activated.
	 *
	 * @return void
	 */
	public function payuni_shipping_redirect_default_tab() {

		global $pagenow;

		if ( 'admin.php' !== $pagenow ) {
			return;
		}

		if ( self::is_payment_active() ) {
			return;
		}

		$page    = ( array_key_exists('page', $_GET) )? wp_unslash( $_GET['page'] ) : '';
		$tab     = ( array_key_exists('tab', $_GET) )? wp_unslash( $_GET['tab'] ) : '';

		if ( 'wc-settings' === $page && 'payuni' === $tab ) {

			if ( empty( $_GET['section'] ) ) {
				wp_redirect( admin_url( 'admin.php?page=wc-settings&tab=payuni&section=shipping' ) );
				exit;
			}
		}

	}

	/**
	 * Output setting tab
	 *
	 * @return void
	 */
	public function output() {

		global $current_section;

		if ( 'shipping' !== $current_section ) {
			return;
		}

		$settings = $this->get_settings( $current_section );
		\WC_Admin_Settings::output_fields( $settings );
	}

	/**
	 * Save settings
	 *
	 * @return void
	 */
	public function save() {

		global $current_section;

		if ( 'shipping' !== $current_section ) {
			return;
		}

		$settings = $this->get_settings( $current_section );
		\WC_Admin_Settings::save_fields( $settings );
	}
}

