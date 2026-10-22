<?php

namespace WuDigital\Payuni\Shipping\Admin;

use WuDigital\Payuni\Shipping\Api\ShippingRequest;
use WuDigital\Payuni\Shipping\PayuniShipping;
use WuDigital\Payuni\Shipping\Utils\OrderMeta;
use WuDigital\Payuni\Shipping\Utils\SingletonTrait;
/**
 * Class OrderEdit file.
 *
 * @package wu-payuni-shipping
 */

defined( 'ABSPATH' ) || exit;

/**
 * The admin order edit related screen and functions.
 */
class OrderEdit {

    use SingletonTrait;

	/**
	 * Initialize the class and add hooks.
	 *
	 * @return void
	 */
	public static function init() {

			self::get_instance();
		
			add_filter( 'woocommerce_admin_shipping_fields', array( self::get_instance(), 'payuni_shipping_cvs_fields' ), 10, 1 );
			
			// 建立 PAYUNi 物流單.
			add_filter( 'woocommerce_order_actions', array( self::get_instance(), 'payuni_order_actions' ) );
			
			add_filter( 'woocommerce_order_action_create_payuni_shipping_order', array( ShippingRequest::get_instance(), 'payuni_create_shipping' ) );

			add_action( 'add_meta_boxes', array( __NAMESPACE__. '\\OrderMetaBox', 'add_meta_box' ), 40, 2 );
		
	}

	/**
	 * Admin shipping fields for PAYUNi shipping order
	 *
	 * @param array $shipping_fields The admin shipping fields.
	 * @return array
	 */
	public static function payuni_shipping_cvs_fields( $shipping_fields ) {
		global $theorder;
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( empty( $theorder ) ) {
			if ( isset( $_POST['post_ID'] ) ) {
				$theorder = wc_get_order( wc_clean( wp_unslash( $_POST['post_ID'] ) ) );
			} else {
				return $shipping_fields;
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$shipping_method = false;

		$items_shipping = $theorder->get_items( 'shipping' );
		$items_shipping = array_shift( $items_shipping );

		if ( $items_shipping ) {
			$shipping_method = PayuniShipping::is_payuni_shipping_cvs( $items_shipping->get_method_id() );
		}

		if ( false !== $shipping_method ) {

			$shipping_fields['payuni_storeid']      = array(
				'label' => __( 'Store ID', 'wu-payuni-shipping' ),
				'show'  => false,
			);
			$shipping_fields['payuni_storename']    = array(
				'label' => __( 'Store Name', 'wu-payuni-shipping' ),
				'show'  => false,
			);
			$shipping_fields['payuni_storeaddress'] = array(
				'label' => __( 'Store Address', 'wu-payuni-shipping' ),
				'show'  => false,
			);

			$shipping_fields['phone'] = array(
				'label' => __( 'Shipping Phone', 'wu-payuni-shipping' ),
			);
			
		} else {
			if ( $items_shipping ) {
				if ( PayuniShipping::is_payuni_shipping_hd( $items_shipping->get_method_id() ) ) {
					$shipping_fields['phone'] = array(
						'label' => __( 'Shipping Phone', 'wu-payuni-shipping' ),
					);
				}
			}
		}

		return $shipping_fields;
	}

	/**
	 * The action for get PAYUNi Logistic Number.
	 *
	 * @param array $order_actions The order actions.
	 * @return array
	 */
	public static function payuni_order_actions( $order_actions ) {
		global $theorder;

		foreach ( $theorder->get_items( 'shipping' ) as $item_id => $item ) {
			if ( PayuniShipping::is_payuni_shipping( $item->get_method_id() ) !== false ) {
				if ( ShippingRequest::can_create_order( $theorder ) && empty( $theorder->get_meta( OrderMeta::ShipTradeNo ) ) && empty( $theorder->get_meta( OrderMeta::ShipNo ) ) ) {
					$order_actions['create_payuni_shipping_order'] = __( 'Create PAYUNi Shipping Order', 'wu-payuni-shipping' );
				}
			}
		}
		return $order_actions;
	}

}
