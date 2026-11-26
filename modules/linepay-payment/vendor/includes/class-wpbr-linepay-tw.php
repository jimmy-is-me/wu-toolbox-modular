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
		add_action( 'wp_ajax_linepay_check', array( self::get_instance(), 'linepay_tw_ajax_check_payment' ) );
		add_filter( 'plugin_action_links_' . WUTM_LINEPAY_BASENAME, array( self::get_instance(), 'linepay_tw_add_action_links' ) );

		add_action( WPBR_LINEPay_Const::AS_HOOK_CONFIRM_RETRY, array( self::get_instance(), 'handle_confirm_retry' ), 10, 1 );
	}

	/**
	 * Resolve a LINE Pay order for the admin confirm and status actions.
	 *
	 * @param string $nonce_action Action used to issue the admin nonce.
	 * @return WC_Order
	 */
	private static function get_ajax_order( $nonce_action ) {
		$security = $_POST['security'] ?? '';
		if ( ! is_string( $security ) || ! wp_verify_nonce( wp_unslash( $security ), $nonce_action ) ) {
			wp_send_json( array( 'success' => false, 'message' => __( 'Unsecure AJAX call', 'wpbr-linepay-tw' ) ) );
		}

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json( array( 'success' => false, 'message' => __( 'You do not have permission to check this payment.', 'wpbr-linepay-tw' ) ) );
		}

		$id = $_POST['post_id'] ?? '';
		$id = is_string( $id ) && ctype_digit( $id ) ? absint( $id ) : 0;
		$order = $id ? wc_get_order( $id ) : false;
		if ( ! $order instanceof WC_Order || ! array_key_exists( $order->get_payment_method(), self::$allowed_payments ) ) {
			wp_send_json( array( 'success' => false, 'message' => __( 'No such order id', 'wpbr-linepay-tw' ) ) );
		}

		if ( '' === (string) $order->get_meta( '_linepay_reserved_transaction_id' ) ) {
			wp_send_json( array( 'success' => false, 'message' => __( 'This order has no LINE Pay transaction ID.', 'wpbr-linepay-tw' ) ) );
		}

		return $order;
	}

	/**
	 * Check WooCommerce order status before changing a pending LINE Pay payment.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return bool
	 */
	public static function order_awaits_payment( $order ) {
		return ! $order->is_paid() && ( $order->needs_payment() || $order->has_status( 'on-hold' ) );
	}

	/**
	 * Confirm the payment from the WooCommerce order editor.
	 *
	 * @return void
	 */
	public function linepay_tw_ajax_confirm_payment() {
		$order = self::get_ajax_order( 'linepay-confirm' );
		if ( ! self::order_awaits_payment( $order ) ||
			WPBR_LINEPay_Const::PAYMENT_STATUS_AUTHED !== $order->get_meta( '_linepay_payment_status' ) ) {
			wp_send_json( array( 'success' => false, 'message' => __( 'LINE Pay Confirm Failed!', 'wpbr-linepay-tw' ) ) );
		}

		try {
			$request = new LINEPay_TW_Request( new LINEPay_TW_Payment() );
			if ( $request->confirm( $order->get_id(), false ) ) {
				if ( function_exists( 'as_unschedule_all_actions' ) ) {
					as_unschedule_all_actions( WPBR_LINEPay_Const::AS_HOOK_CONFIRM_RETRY, array( $order->get_id() ), WPBR_LINEPay_Const::AS_GROUP_CONFIRM_RETRY );
				}
				$order->add_order_note( __( 'LINE Pay Confirm Succeed!', 'wpbr-linepay-tw' ) );
				wp_send_json( array( 'success' => true, 'message' => __( 'Confirm succeed', 'wpbr-linepay-tw' ) ) );
			}
		} catch ( Exception $e ) {
			$order->add_order_note( __( 'LINE Pay Confirm Failed!', 'wpbr-linepay-tw' ) . ' ' . $e->getMessage() );
			wp_send_json( array( 'success' => false, 'message' => $e->getMessage() ) );
		}

		wp_send_json( array( 'success' => false, 'message' => __( 'LINE Pay Confirm Failed!', 'wpbr-linepay-tw' ) ) );
	}

	/**
	 * Query and sync the provider status without requesting confirmation.
	 *
	 * @return void
	 */
	public function linepay_tw_ajax_check_payment() {
		$order = self::get_ajax_order( 'linepay-check' );

		try {
			$request = new LINEPay_TW_Request( new LINEPay_TW_Payment() );
			wp_send_json( array( 'success' => true, 'message' => $request->sync_payment_status( $order ) ) );
		} catch ( Exception $e ) {
			self::log( sprintf( '[sync][order_id:%s] check payment status failed: %s', $order->get_id(), $e->getMessage() ), 'error' );
			wp_send_json( array( 'success' => false, 'message' => $e->getMessage() ) );
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

		wp_enqueue_script( 'wpbr-linepay-tw', WUTM_LINEPAY_PLUGIN_URL . 'assets/js/wpbr-linepay-tw-admin.js', array(), WUTM_LINEPAY_VERSION, true );
		wp_enqueue_style( 'wpbr-linepay-tw', WUTM_LINEPAY_PLUGIN_URL . 'assets/css/wpbr-linepay-tw-admin.css', array(), '1.0' );

		wp_localize_script(
			'wpbr-linepay-tw',
			'linepay_object',
			array(
				'ajax_url'      => admin_url( 'admin-ajax.php' ),
				'confirm_nonce' => wp_create_nonce( 'linepay-confirm' ),
				'check_nonce'   => wp_create_nonce( 'linepay-check' ),
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
	 * Handle confirm retry for an order.
	 *
	 * Uses chained single actions: on failure, schedules the next single
	 * action. On success or exhaustion, simply does not schedule — the
	 * chain stops naturally without needing to unschedule anything.
	 *
	 * @param int $order_id The order ID.
	 * @return void
	 */
	public function handle_confirm_retry( $order_id ) {

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order || WPBR_LINEPay_Const::ID !== $order->get_payment_method() ||
			! self::order_awaits_payment( $order ) || '' === (string) $order->get_meta( '_linepay_reserved_transaction_id' ) ) {
			self::log( sprintf( '[confirm-retry][order_id:%s] Order no longer qualifies for confirmation.', $order_id ) );
			return;
		}

		$payment_status = $order->get_meta( '_linepay_payment_status' );
		if ( WPBR_LINEPay_Const::PAYMENT_STATUS_AUTHED !== $payment_status ) {
			self::log( sprintf( '[confirm-retry][order_id:%s] Payment status is "%s", skipping.', $order_id, $payment_status ) );
			return;
		}

		$max_retries = apply_filters( 'linepay_tw_max_retry_count', WPBR_LINEPay_Const::DEFAULT_MAX_RETRY_COUNT, $order_id );
		$retry_count = (int) $order->get_meta( WPBR_LINEPay_Const::META_CONFIRM_RETRY_COUNT );

		if ( $retry_count >= $max_retries ) {
			self::log( sprintf( '[confirm-retry][order_id:%s] Retries already exhausted (%d/%d), skipping.', $order_id, $retry_count, $max_retries ) );
			return;
		}

		$retry_count++;
		$order->update_meta_data( WPBR_LINEPay_Const::META_CONFIRM_RETRY_COUNT, $retry_count );
		$order->save();

		self::log( sprintf( '[confirm-retry][order_id:%s] Attempting confirm retry #%d/%d.', $order_id, $retry_count, $max_retries ) );

		$gateway = new LINEPay_TW_Payment();
		$request = new LINEPay_TW_Request( $gateway );

		try {
			if ( $request->confirm( $order_id, false ) ) {
				/* translators: %d is the retry attempt number */
				$order->add_order_note( sprintf( __( 'LINE Pay Confirm succeeded on auto-retry #%d.', 'wpbr-linepay-tw' ), $retry_count ) );
				self::log( sprintf( '[confirm-retry][order_id:%s] Confirm succeeded on retry #%d.', $order_id, $retry_count ) );
				// Success — do not schedule next action, chain stops.
				return;
			}
		} catch ( Exception $e ) {
			/* translators: %1$d is the retry attempt number, %2$s is the error message */
			$order->add_order_note( sprintf( __( 'LINE Pay auto-retry #%1$d failed: %2$s', 'wpbr-linepay-tw' ), $retry_count, $e->getMessage() ) );
			self::log( sprintf( '[confirm-retry][order_id:%s] Retry #%d failed: %s', $order_id, $retry_count, $e->getMessage() ), 'error' );
		}

		// Decide: schedule next retry or send notification.
		if ( $retry_count >= $max_retries ) {
			self::send_retry_failed_notification( $order );
			/* translators: %d is the max retry count */
			$order->add_order_note( sprintf( __( 'LINE Pay auto-retry exhausted after %d attempts. Admin has been notified.', 'wpbr-linepay-tw' ), $retry_count ) );
			self::log( sprintf( '[confirm-retry][order_id:%s] All %d retries exhausted. Notification sent.', $order_id, $retry_count ), 'error' );
			// Exhausted — do not schedule next action, chain stops.
		} else {
			// Schedule next single action.
			self::schedule_confirm_retry( $order_id );
		}
	}

	/**
	 * Schedule a single confirm retry action for an order.
	 *
	 * Uses chained single actions instead of a recurring action.
	 * Each callback schedules the next one if retries remain.
	 *
	 * @param int $order_id The order ID.
	 * @return void
	 */
	public static function schedule_confirm_retry( $order_id ) {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		$interval = apply_filters( 'linepay_tw_retry_interval', WPBR_LINEPay_Const::DEFAULT_RETRY_INTERVAL, $order_id );

		as_schedule_single_action(
			time() + ( $interval * MINUTE_IN_SECONDS ),
			WPBR_LINEPay_Const::AS_HOOK_CONFIRM_RETRY,
			array( (int) $order_id ),
			WPBR_LINEPay_Const::AS_GROUP_CONFIRM_RETRY
		);

		self::log( sprintf( '[confirm-retry][order_id:%s] Scheduled next retry in %d minute(s).', $order_id, $interval ) );
	}

	/**
	 * Send email notification when all retries are exhausted.
	 *
	 * @param WC_Order $order The order object.
	 * @return void
	 */
	private static function send_retry_failed_notification( $order ) {

		$to = get_option( 'linepay_tw_retry_notification_email' );
		if ( empty( $to ) ) {
			$to = get_option( 'admin_email' );
		}

		$site_name      = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
		$order_id       = $order->get_id();
		$transaction_id = $order->get_meta( '_linepay_reserved_transaction_id' );
		$amount         = $order->get_total();
		$retry_count    = (int) $order->get_meta( WPBR_LINEPay_Const::META_CONFIRM_RETRY_COUNT );
		$order_url      = admin_url( 'post.php?post=' . $order_id . '&action=edit' );

		if ( function_exists( 'wc_get_container' ) ) {
			try {
				$order_url = wc_get_container()->get( \Automattic\WooCommerce\Internal\Admin\Orders\PageController::class )->get_edit_url( $order_id );
			} catch ( Exception $e ) {
				// fallback to default URL above.
			}
		}

		$subject = sprintf(
			/* translators: %1$s is the site name, %2$s is the order ID */
			__( '[%1$s] LINE Pay Confirm Failed - Order #%2$s', 'wpbr-linepay-tw' ),
			$site_name,
			$order_id
		);

		$body = sprintf(
			/* translators: %1$s is order ID, %2$s is transaction ID, %3$s is amount, %4$d is retry count, %5$s is order admin URL */
			__(
				"LINE Pay Confirm API has failed after all retry attempts.\n\n" .
				"Order ID: #%1\$s\n" .
				"Transaction ID: %2\$s\n" .
				"Amount: %3\$s\n" .
				"Retry Attempts: %4\$d\n\n" .
				"Please check the order and take manual action:\n%5\$s",
				'wpbr-linepay-tw'
			),
			$order_id,
			$transaction_id,
			$amount,
			$retry_count,
			$order_url
		);

		wp_mail( $to, $subject, $body );
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
	 * Mask a LINE Pay transaction id for the log. Only the last 6 digits are kept.
	 *
	 * @param string|int $transaction_id The transaction id.
	 * @return string
	 */
	public static function mask_transaction_id( $transaction_id ) {
		$transaction_id = (string) $transaction_id;
		if ( strlen( $transaction_id ) <= 6 ) {
			return $transaction_id;
		}
		return '***' . substr( $transaction_id, -6 );
	}

	/**
	 * Mask the transaction id inside a LINE Pay API url, for the log.
	 * Covers /payments/{id}/..., /payments/requests/{id}/... and ?transactionId={id}.
	 *
	 * @param string $text The url, or a message that contains the url.
	 * @return string
	 */
	public static function mask_transaction_id_in_url( $text ) {
		return preg_replace_callback(
			'#(/payments/(?:requests/)?|transactionId=)(\d+)#',
			function ( $matches ) {
				return $matches[1] . self::mask_transaction_id( $matches[2] );
			},
			(string) $text
		);
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
