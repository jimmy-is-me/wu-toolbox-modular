<?php
/**
 * LINEPay_TW class file
 *
 * @package linepay
 */

defined( 'ABSPATH' ) || exit;

/**
 * LINEPay_TW main class for handling all checkout related process.
 */
class LINEPay_TW {

	/**
	 * Class instance
	 *
	 * @var LINEPay_TW
	 */
	private static $instance;

	/**
	 * The order status when payment is failed.
	 *
	 * @var string
	 */
	public static $fail_order_status;

	/**
	 * Whether or not add detail payment status note in order note.
	 *
	 * @var boolean
	 */
	public static $detail_payment_status_note_enabled;

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

	/**
	 * Suppoeted payment gateways
	 *
	 * @var array
	 * */
	public static $allowed_payments;

	/**
	 * Enable sandbox mode
	 *
	 * @var boolean
	 */
	public static $enable_sandbox;

	/**
	 * LINE Pay API environment
	 *
	 * @var string
	 */
	public static $env_status;

	/**
	 * LINE Pay API channel id and channel secret
	 *
	 * @var array
	 */
	public static $channel_info;

	/**
	 * The currency scale of supported currencies.
	 *
	 * @var array
	 */
	public static $currency_scales;

	/**
	 * Initialize class and add hooks
	 *
	 * @return void
	 */
	public static function init() {

		self::get_instance();

		require_once WUTM_LINEPAY_PLUGIN_DIR . 'includes/gateways/class-linepay-tw-payment.php';
		require_once WUTM_LINEPAY_PLUGIN_DIR . 'includes/gateways/class-linepay-tw-request.php';
		require_once WUTM_LINEPAY_PLUGIN_DIR . 'includes/gateways/class-linepay-tw-response.php';
		require_once WUTM_LINEPAY_PLUGIN_DIR . 'includes/utils/class-wpbr-linepay-const.php';
		require_once WUTM_LINEPAY_PLUGIN_DIR . 'includes/utils/class-wpbr-linepay-status-code.php';
		require_once WUTM_LINEPAY_PLUGIN_DIR . 'includes/admin/meta-boxes/class-linepay-tw-order-meta-boxes.php';

		self::$fail_order_status = get_option( 'linepay_tw_payment_fail_order_status', 'wc-failed' );

		self::$detail_payment_status_note_enabled = wc_string_to_bool( get_option( 'linepay_tw_detail_status_note_enabled' ) );

		self::$log_enabled = 'yes' === get_option( 'linepay_tw_debug_log_enabled', 'no' );

		self::$enable_sandbox = wc_string_to_bool( get_option( 'linepay_tw_sandboxmode_enabled' ) );

		self::$env_status = ( self::$enable_sandbox ) ? WPBR_LINEPay_Const::ENV_SANDBOX : WPBR_LINEPay_Const::ENV_REAL;

		self::$channel_info = array(
			WPBR_LINEPay_Const::ENV_REAL    => array(
				'channel_id'     => get_option( 'linepay_tw_channel_id' ),
				'channel_secret' => get_option( 'linepay_tw_channel_secret' ),
			),
			WPBR_LINEPay_Const::ENV_SANDBOX => array(
				'channel_id'     => get_option( 'linepay_tw_sandbox_channel_id' ),
				'channel_secret' => get_option( 'linepay_tw_sandbox_channel_secret' ),
			),
		);

		self::$currency_scales = array(
			'TWD' => 0,
		);

		self::$allowed_payments = array(
			'linepay-tw' => 'LINEPay_TW_Payment',
		);

		LINEPay_TW_Response::init();
		LINEPay_TW_Order_Meta_Boxes::init();

		load_plugin_textdomain( 'wpbr-linepay-tw', false, trailingslashit( dirname( WUTM_LINEPAY_BASENAME ) . '/languages' ) );

		add_filter( 'woocommerce_get_settings_pages', array( self::get_instance(), 'linepay_tw_add_settings' ) );
		add_filter( 'woocommerce_payment_gateways', array( self::get_instance(), 'add_linepay_tw_payment_gateway' ) );
		add_action( 'wp_enqueue_scripts', array( self::get_instance(), 'linepay_tw_enqueue_scripts' ), 9 );
		add_action( 'admin_enqueue_scripts', array( self::get_instance(), 'linepay_tw_admin_scripts' ), 9 );

		add_action( 'wp_ajax_linepay_confirm', array( self::get_instance(), 'linepay_tw_ajax_confirm_payment' ) );
		add_filter( 'plugin_action_links_' . WUTM_LINEPAY_BASENAME, array( self::get_instance(), 'linepay_tw_add_action_links' ) );
	}

	/**
	 * Handle of confirm payment request via wp-admin.
	 *
	 * @return void
	 */
	public function linepay_tw_ajax_confirm_payment() {

		$posted = wp_unslash( $_POST );

		if ( ! array_key_exists( 'security', $posted ) || ! wp_verify_nonce( $posted['security'], 'linepay-confirm' ) ) {
			$return = array(
				'success' => false,
				'message' => __( 'Unsecure AJAX call', 'wpbr-linepay-tw' ),
			);
			wp_send_json( $return );
		}

		$order_id = $posted['post_id'];
		$order    = wc_get_order( $order_id );

		if ( ! $order ) {
			$return = array(
				'success' => false,
				'message' => __( 'No such order id', 'wpbr-linepay-tw' ),
			);
			wp_send_json( $return );
		}

		$reserved_transaction_id = $order->get_transaction_id();
		$gateway                 = new LINEPay_TW_Payment();
		$request                 = new LINEPay_TW_Request( $gateway );
		try {

			if ( $request->confirm( $order->get_id(), false ) ) {
				$order->add_order_note( __( 'LINE Pay Confirm Succeed!', 'wpbr-linepay-tw' ) );
				$return = array(
					'success' => true,
					'message' => __( 'Confirm succeed', 'wpbr-linepay-tw' ),
				);
				wp_send_json( $return );
			}
		} catch ( Exception $e ) {

			$order->add_order_note( __( 'LINE Pay Confirm Failed!', 'wpbr-linepay-tw' ) . $e->getMessage() );
			$return = array(
				'success' => false,
				'message' => $e->getMessage(),
			);
			wp_send_json( $return );

		}
	}
	/**
	 * Returns channel information that matches the environment information of LINEPay Gateway.
	 *
	 * @return array
	 */
	public static function get_channel_info() {
		return self::$channel_info[ self::$env_status ];
	}

	/**
	 * Add payment gateways
	 *
	 * @param array $methods LINE Pay Payment gateways.
	 * @return array
	 */
	public function add_linepay_tw_payment_gateway( $methods ) {
		$merged_methods = array_merge( $methods, self::$allowed_payments );
		return $merged_methods;
	}

	/**
	 * Enqueue public css style.
	 *
	 * @return void
	 */
	public function linepay_tw_enqueue_scripts() {
		wp_enqueue_style( 'wpbr-linepay-tw', WUTM_LINEPAY_PLUGIN_URL . 'assets/css/wpbr-linepay-tw-public.css', array(), '1.0' );
	}

	/**
	 * Enqueue admin scripts.
	 *
	 * @return void
	 */
	public function linepay_tw_admin_scripts() {

		wp_enqueue_script( 'wpbr-linepay-tw', WUTM_LINEPAY_PLUGIN_URL . 'assets/js/wpbr-linepay-tw-admin.js', array(), '1.0', true );
		wp_enqueue_style( 'wpbr-linepay-tw', WUTM_LINEPAY_PLUGIN_URL . 'assets/css/wpbr-linepay-tw-admin.css', array(), '1.0' );

		wp_localize_script(
			'wpbr-linepay-tw',
			'linepay_object',
			array(
				'ajax_url'      => admin_url( 'admin-ajax.php' ),
				'confirm_nonce' => wp_create_nonce( 'linepay-confirm' ),
			)
		);
	}

	/**
	 * Plugin action links
	 *
	 * @param array $links The action links array.
	 * @return array
	 */
	public function linepay_tw_add_action_links( $links ) {

		$setting_links['general'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=wc-settings&tab=linepay-tw' ) ),
			esc_html__( 'General Settings', 'wpbr-linepay-tw' )
		);

		$setting_links['payment'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout' ) ),
			esc_html__( 'Payment Settings', 'wpbr-linepay-tw' )
		);

		return array_merge( $links, $setting_links );
	}


	/**
	 * Add settings tab
	 *
	 * @return WC_Settings_Tab_LINEPay_TW
	 */
	public function linepay_tw_add_settings( $settings ) {
		require_once WUTM_LINEPAY_PLUGIN_DIR . 'includes/settings/class-linepay-tw-settings-tab.php';

		if ( is_array( $settings ) ) {
			$settings[] = new WC_Settings_Tab_LINEPay_TW();
		} else {
			// backward compatibility for WPBrewer plugins.
			if ( is_a( $settings, 'WC_Settings_Page' ) ) {
				$settings = array( $settings, new WC_Settings_Tab_LINEPay_TW() );
			}
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
		if ( self::$log_enabled ) {
			if ( empty( self::$log ) ) {
				self::$log = new WC_Logger();
			}
			self::$log->log( $level, $message, array( 'source' => 'wpbr-linepay-tw' ) );
		}
	}

	/**
	 * Constructor
	 */
	public function __construct() {
		// do nothing.
	}

	/**
	 * Returns the single instance of the LINEPay_TW object
	 *
	 * @return LINEPay_TW
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}
}
