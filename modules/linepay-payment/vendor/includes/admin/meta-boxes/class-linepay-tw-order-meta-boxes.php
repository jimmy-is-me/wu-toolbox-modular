<?php
/**
 * LINEPay_TW_Order_Meta_Boxes class file
 *
 * @package linepay_tw
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;
use Automattic\WooCommerce\Utilities\OrderUtil;

/**
 * LINEPay_TW_Order_Meta_Boxes class
 */
class LINEPay_TW_Order_Meta_Boxes {

	/**
	 * The singleton instance
	 *
	 * @var LINEPay_TW_Order_Meta_Boxes
	 */
	private static $instance;

	/**
	 * Init the class
	 *
	 * @return void
	 */
	public static function init() {
		self::get_instance();

		add_action( 'add_meta_boxes', array( self::get_instance(), 'linepay_add_meta_boxes' ), 10, 2 );
	}

	/**
	 * Add admin meta box
	 *
	 * @param object $post The post object.
	 * @return void
	 */
	public function linepay_add_meta_boxes( $post_type, $post_or_order_object ) {

		$order = ( $post_or_order_object instanceof WP_Post ) ? wc_get_order( $post_or_order_object->ID ) : $post_or_order_object;

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( ! array_key_exists( $order->get_payment_method(), LINEPay_TW::$allowed_payments ) ) {
			return;
		}

		$screen = wc_get_container()->get( CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
		? wc_get_page_screen_id( 'shop-order' )
		: 'shop_order';

		add_meta_box(
			'woocommerce-linepay-meta-boxes',
			__( 'LINE Pay Details', 'wpbr-linepay-tw' ),
			array(
				self::get_instance(),
				'linepay_admin_meta',
			),
			$screen,
			'side',
			'high'
		);
	}


	/**
	 * Display metabox
	 *
	 * @param object $post Post object.
	 * @return void
	 */
	public function linepay_admin_meta( $post_or_order_object ) {

		$order = ( $post_or_order_object instanceof WP_Post ) ? wc_get_order( $post_or_order_object->ID ) : $post_or_order_object;

		if ( array_key_exists( $order->get_payment_method(), LINEPay_TW::$allowed_payments ) ) {

			echo '<table>';
			echo '<tr><th><div id="order-id" data-order-id="' . esc_html( $order->get_id() ) . '">' . esc_html__( 'Transaction ID', 'wpbr-linepay-tw' ) . '</div></th><td>' . esc_html( $order->get_meta( '_linepay_reserved_transaction_id' ) ) . '</td></tr>';
			echo '<tr><th><div>' . esc_html__( 'Payment Status', 'wpbr-linepay-tw' ) . '</div></th><td>' . esc_html( $order->get_meta( '_linepay_payment_status' ) ) . '</td></tr>';

			if ( $order->get_meta( '_linepay_payment_status' ) === WPBR_LINEPay_Const::PAYMENT_STATUS_AUTHED ) {
				echo '<tr id="linepay-action"><th>' . esc_html__( 'Payment Action', 'wpbr-linepay-tw' ) . '</th><td><button class="button linepay-confirm-btn" data-id=' . esc_html( $order->get_id() ) . '>' . esc_html__( 'Confirm Payment', 'wpbr-linepay-tw' ) . '</button></tr>';
			}

			echo '</table>';
		}
	}

	/**
	 * Get instance
	 *
	 * @return LINEPay_TW_Order_Meta_Boxes
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * The constructor
	 */
	public function __construct() {
		// do nothing.
	}
}
