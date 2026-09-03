<?php
/**
 * OrderMetaBoxes class file
 *
 * @package payuni
 */

namespace WPBrewer\Payuni\Payment\Admin;

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;
use WPBrewer\Payuni\Payment\Gateways\Atm;
use WPBrewer\Payuni\Payment\Gateways\Cvs;
use WPBrewer\Payuni\Payment\PayuniPayment;
use WPBrewer\Payuni\Payment\Utils\OrderMeta;
use WPBrewer\Payuni\Payment\Utils\AuthType;
use WPBrewer\Payuni\Payment\Utils\BankType;
use WPBrewer\Payuni\Payment\Utils\TradeStatus;
use WPBrewer\Payuni\Payment\Utils\SingletonTrait;

/**
 * OrderMetaBoxes main class for handling all checkout related process.
 */
class OrderMetaBoxes {

	use SingletonTrait;

	/**
	 * Initialize class andd add hooks
	 *
	 * @return void
	 */
	public static function init() {
		self::get_instance();

		add_action( 'add_meta_boxes', array( self::get_instance(), 'payuni_add_meta_boxes' ), 10, 2 );
	}

	/**
	 * Add meta box
	 *
	 * @param string $post_type            The post type.
	 * @param object $post_or_order_object The post object.
	 * 
	 * @return void
	 */
	public function payuni_add_meta_boxes( $post_type, $post_or_order_object ) {

		$order = ( $post_or_order_object instanceof \WP_Post ) ? wc_get_order( $post_or_order_object->ID ) : $post_or_order_object;

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		if ( ! array_key_exists( $order->get_payment_method(), PayuniPayment::get_allowed_payments( $order ) ) ) {
			return;
		}

		$screen = wc_get_container()->get( CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
		? wc_get_page_screen_id( 'shop-order' )
		: 'shop_order';

		add_meta_box(
			'payuni-order-meta-boxes',
			__( 'PAYUNi Payment Detail', 'wpbr-payuni-payment' ),
			array(
				self::get_instance(),
				'payuni_order_admin_meta_box',
			),
			$screen,
			'side',
			'high'
		);
	}

	/**
	 * Meta box ouput
	 *
	 * @param  object $post_or_order_object The post object.
	 * @return void
	 */
	public function payuni_order_admin_meta_box( $post_or_order_object ) {

		$order = ( $post_or_order_object instanceof \WP_Post ) ? wc_get_order( $post_or_order_object->ID ) : $post_or_order_object;

		if ( ! $order ) {
			return;
		}

		$payment_method   = $order->get_payment_method();
		$allowed_payments = PayuniPayment::get_allowed_payments( $order );
		$gateway          = $allowed_payments[ $payment_method ];

		echo '<table>';

		$payuni_order_no_key = PayuniPayment::get_order_meta_key( $order, OrderMeta::PAYUNI_ORDER_NO );
		echo '<tr><td><strong>' . esc_html__( 'Order No', 'wpbr-payuni-payment' ) . '</strong></td><td>' . esc_html( $order->get_meta( $payuni_order_no_key ) ) . '</td></tr>';
		foreach ( $gateway::get_order_metas() as $key => $value ) {
			// for backward compatibility.
			$key = PayuniPayment::get_order_meta_key( $order, $key );
			if ( $key === OrderMeta::CREDIT_AUTH_TYPE ) {
				echo '<tr><td><strong>' . esc_html( $value ) . '</strong></td><td>' . esc_html( AuthType::get_type( $order->get_meta( $key ) ) ) . ' (' . esc_html( $order->get_meta( $key ) ) . ')</td></tr>';
			} elseif ( $key === OrderMeta::AMT_BANK_TYPE ) {
				echo '<tr><td><strong>' . esc_html( $value ) . '</strong></td><td>' . esc_html( $order->get_meta( $key ) ) . ' (' . esc_html( BankType::get_name( $order->get_meta( $key ) ) ) . ')</td></tr>';
			} elseif ( $key === OrderMeta::TRADE_STATUS ) {
				$trade_status = $order->get_meta( $key );
				if ( isset( $trade_status ) ) {
					echo '<tr><td><strong>' . esc_html( $value ) . '</strong></td><td><span class="payuni-trade-status-' . esc_attr( $trade_status ) . '">' . esc_html( TradeStatus::get_name( $trade_status, $payment_method ) ) . '</span></td></tr>';
				} else {
					echo '<tr><td><strong>' . esc_html( $value ) . '</strong></td><td></td></tr>';
				}
			} elseif ( $key === OrderMeta::MESSAGE ) {
				echo '<tr><td><strong>' . esc_html( $value ) . '</strong></td><td>' . esc_html( $order->get_meta( $key ) ) . ' (' . esc_html( $order->get_meta( OrderMeta::STATUS ) ) . ')</td></tr>';
			} else {
				echo '<tr><td><strong>' . esc_html( $value ) . '</strong></td><td>' . esc_html( $order->get_meta( $key ) ) . '</td></tr>';
			}
			
		}

		if ( PayuniPayment::$einvoice_enabled ) {

			echo '<tr><td><strong>' . esc_html__( 'E-Invoice No', 'wpbr-payuni-payment' ) . '</strong></td><td>' . esc_html( $order->get_meta( OrderMeta::EINVOICE_NO ) ) . '</td></tr>';
			echo '<tr><td><strong>' . esc_html__( 'E-Invoice Amount', 'wpbr-payuni-payment' ) . '</strong></td><td>' . esc_html( $order->get_meta( OrderMeta::EINVOICE_AMT ) ) . '</td></tr>';
			echo '<tr><td><strong>' . esc_html__( 'E-Invoice Time', 'wpbr-payuni-payment' ) . '</strong></td><td>' . esc_html( $order->get_meta( OrderMeta::EINVOICE_TIME ) ) . '</td></tr>';

			$einvoice_type = $order->get_meta( OrderMeta::EINVOICE_TYPE );
			if ( $einvoice_type === 'C0401' ) {
				$einvoice_type_desc =  _x( 'Issue', 'Issue Type', 'wpbr-payuni-payment' );
			} elseif ( $einvoice_type === 'C0501' ) {
				$einvoice_type_desc = _x( 'Void', 'Issue Type', 'wpbr-payuni-payment' );
			} else {
				$einvoice_type_desc = _x( 'Unknown Issue Type', 'Issue Type', 'wpbr-payuni-payment' );
			}
			echo '<tr><td><strong>' . esc_html__( 'E-Invoice Type', 'wpbr-payuni-payment' ) . '</strong></td><td>' . esc_html( $einvoice_type . ' (' . $einvoice_type_desc . ')' ) . '</td></tr>';

			$einvoice_info = $order->get_meta( OrderMeta::EINVOICE_INFO );
			if ( $einvoice_info === '3J0002' ) {
				$einvoice_info_desc = __( 'Mobile Code', 'wpbr-payuni-payment' )	;
			} elseif ( $einvoice_info === 'CQ0001' ) {
				$einvoice_info_desc = __( 'CDC Code', 'wpbr-payuni-payment' );
			} elseif ( $einvoice_info === 'amego' ) {
				$einvoice_info_desc = __( 'Amego Member', 'wpbr-payuni-payment' );
			} elseif ( $einvoice_info === 'Donate' ) {
				$einvoice_info_desc = __( 'Donation', 'wpbr-payuni-payment' );
			} elseif ( $einvoice_info === 'Company' ) {
				$einvoice_info_desc = __( 'Company', 'wpbr-payuni-payment' );
			} else {
				$einvoice_info_desc = __( 'Unknown Issue Info', 'wpbr-payuni-payment' );
			}
			echo '<tr><td><strong>' . esc_html__( 'Issue Info', 'wpbr-payuni-payment' ) . '</strong></td><td>' . esc_html( $einvoice_info . ' (' . $einvoice_info_desc . ')' ) . '</td></tr>';

			$einvoice_status = $order->get_meta( OrderMeta::EINVOICE_STATUS );
			if ( $einvoice_status === '1' ) {
				$einvoice_status_desc = __( 'Issued', 'wpbr-payuni-payment' );
			} elseif ( $einvoice_status === '2' ) {
				$einvoice_status_desc = __( 'Failed', 'wpbr-payuni-payment' );
			} elseif ( $einvoice_status === '5' ) {
				$einvoice_status_desc = __( 'Voided', 'wpbr-payuni-payment' );
			} else {
				$einvoice_status_desc = __( 'Unknown Issue Status', 'wpbr-payuni-payment' );
			}
			echo '<tr><td><strong>' . esc_html__( 'Issue Status', 'wpbr-payuni-payment' ) . '</strong></td><td>' . esc_html( $einvoice_status . ' (' . $einvoice_status_desc . ')' ) . '</td></tr>';
		}// end einvoice enabled

		// 排程自動取消（僅 ATM / CVS，且啟用自動取消功能）.
		if ( PayuniPayment::$auto_cancel_expired_order_enabled && in_array( $payment_method, array( Atm::GATEWAY_ID, Cvs::GATEWAY_ID ), true ) ) {
			$scheduled = as_next_scheduled_action( 'payuni_cancel_expired_order', array( 'order_id' => $order->get_id() ), 'payuni' );
			echo '<tr><td><strong>' . esc_html__( 'Auto Cancel', 'wpbr-payuni-payment' ) . '</strong></td><td>';
			if ( $scheduled ) {
				$dt = new \DateTime( '@' . $scheduled );
				$dt->setTimezone( new \DateTimeZone( wp_timezone_string() ) );
				echo esc_html( $dt->format( 'Y-m-d H:i:s' ) );
			} elseif ( in_array( $order->get_status(), array( 'pending', 'on-hold' ), true ) ) {
				echo '<button id="payuni-schedule-cancel-btn" class="button" data-id="' . esc_attr( $order->get_id() ) . '">' . esc_html__( 'Enable', 'wpbr-payuni-payment' ) . '</button>';
			} else {
				echo '—';
			}
			echo '</td></tr>';
		}

		echo '<tr id="payuni-action"><td colspan="2"><button id="payuni-query-btn" class="button" data-id="' . esc_html( $order->get_id() ) . '">查詢</button></td></tr>';
		echo '</table>';

		
	}

}
