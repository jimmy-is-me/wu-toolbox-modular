<?php
namespace WPBrewer\ESun\Payment\Admin;

use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;
use WPBrewer\ESun\Payment\ESunPayment;
use WPBrewer\ESun\Payment\Gateways\ESunPaymentInstallment;
use WPBrewer\ESun\Payment\Utils\OrderMeta;
use WPBrewer\ESun\Payment\Utils\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/**
 * OrderMetaBoxes class
 */
class OrderMetaBoxes {

	use SingletonTrait;

	/**
	 * Init the class
	 *
	 * @return void
	 */
	public static function init() {
		self::get_instance();

		add_action( 'add_meta_boxes', array( self::get_instance(), 'esun_add_meta_boxes' ), 10, 2 );

	}

	/**
	 * Add meta box
	 *
	 * @param string $post_type            The post type.
	 * @param object $post_or_order_object The post object.
	 * 
	 * @return void
	 */
	public function esun_add_meta_boxes( $post_type, $post_or_order_object ) {

		$order = ( $post_or_order_object instanceof \WP_Post ) ? wc_get_order( $post_or_order_object->ID ) : $post_or_order_object;

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		if ( ! array_key_exists( $order->get_payment_method(), ESunPayment::$allowed_payments ) ) {
			return;
		}

		$screen = wc_get_container()->get( CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
		? wc_get_page_screen_id( 'shop-order' )
		: 'shop_order';

		add_meta_box(
			'woocommerce-esun-meta-boxes',
			__( 'ESUN Payment Details', 'wpbr-esun-payment' ),
			array(
				self::get_instance(),
				'esun_admin_meta',
			),
			$screen,
			'side',
			'default'
		);

	}

	/**
	 * Meta box ouput
	 *
	 * @param  object $post_or_order_object The post object.
	 * @return void
	 */
	public function esun_admin_meta( $post_or_order_object ) {

		$order = ( $post_or_order_object instanceof \WP_Post ) ? wc_get_order( $post_or_order_object->ID ) : $post_or_order_object;

		if ( ! $order ) {
			return;
		}

		echo '<table>';

		echo '<tr id="order-id" data-order-id="' . $order->get_id() . '"><td><strong>'.__( 'Credit Card RRN', 'wpbr-esun-payment' ) .'</strong></td><td>'. $order->get_meta( OrderMeta::RRN ) . '</td></tr>';
		echo '<tr><td><strong>' . __( 'Auth Code', 'wpbr-esun-payment' ) . '</strong></td><td>' . $order->get_meta( OrderMeta::AIR ) . '</td></tr>';
		echo '<tr><td><strong>' . __( 'Credit Card No', 'wpbr-esun-payment' ) . '</strong></td><td>' . $order->get_meta( OrderMeta::AN ) . '</td></tr>';
		echo '<tr><td><strong>' . __( 'Transaction Date', 'wpbr-esun-payment' ) . '</strong></td><td>' . $order->get_meta( OrderMeta::TRADE_DATE ) . '</td></tr>';
		echo '<tr><td><strong>' . __( 'Transaction Time', 'wpbr-esun-payment' ) . '</strong></td><td>' . $order->get_meta( OrderMeta::TRADE_TIME ) . '</td></tr>';
		echo '<tr><td><strong>' . __( 'Transaction Status', 'wpbr-esun-payment' ) . '</strong></td><td>' . $order->get_meta( OrderMeta::TRANS_STATUS ) . '</td></tr>';

		if ( $order->get_payment_method() == ESunPaymentInstallment::GATEWAY_ID ) {
			echo '<tr><td><strong>' . __( 'Installment Number', 'wpbr-esun-payment' ) . '</strong></td><td>' . $order->get_meta( OrderMeta::INSTALLMENT_NUMBER ) . '</td></tr>';
			echo '<tr><td><strong>' . __( 'Installment Amount', 'wpbr-esun-payment' ) . '</strong></td><td>' . $order->get_meta( OrderMeta::INSTALLMENT_TOTAL_AMOUNT ) . '</td></tr>';
			echo '<tr><td><strong>' . __( 'Installment Each Amount', 'wpbr-esun-payment' ) . '</strong></td><td>' . $order->get_meta( OrderMeta::INSTALLMENT_EACH_AMOUNT ) . '</td></tr>';
			echo '<tr><td><strong>' . __( 'Installment First Amount', 'wpbr-esun-payment' ) . '</strong></td><td>' . $order->get_meta( OrderMeta::INSTALLMENT_FIRST_AMOUNT ) . '</td></tr>';
		}

		$errorcode = $order->get_meta( OrderMeta::ERROR_NO );
		if ( ! empty( $errorcode ) ) {
			echo '<tr><td><strong>' . __( 'Error Code', 'wpbr-esun-payment' ) . '</strong></td><td>' . $order->get_meta( OrderMeta::ERROR_NO ) . '</td></tr>';
			echo '<tr><td><strong>' . __( 'Error Description', 'wpbr-esun-payment' ) . '</strong></td><td>' . $order->get_meta( OrderMeta::ERROR_DESC ) . '</td></tr>';
		}

		echo '</table>';

    }

}
