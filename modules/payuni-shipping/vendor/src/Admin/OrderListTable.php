<?php

namespace WuDigital\Payuni\Shipping\Admin;

use Automattic\WooCommerce\Utilities\OrderUtil;
use WuDigital\Payuni\Shipping\Utils\OrderMeta;
use WuDigital\Payuni\Shipping\Utils\ShipType;
use WuDigital\Payuni\Shipping\Utils\SingletonTrait;

class OrderListTable {

	use SingletonTrait;

	/**
	 * Init the class
	 *
	 * @return void
	 */
	public static function init() {

		self::get_instance();

		if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
			add_filter( 'woocommerce_shop_order_list_table_columns', array( self::get_instance(), 'payuni_shipping_columns_head' ) );
			add_action( 'woocommerce_shop_order_list_table_custom_column', array( self::get_instance(), 'payuni_shipping_columns_content'), 10, 2);
		} else {
			add_filter( 'manage_edit-shop_order_columns', array( self::get_instance(), 'payuni_shipping_columns_head' ) );
			add_action( 'manage_shop_order_posts_custom_column', array( self::get_instance(), 'payuni_shipping_columns_content' ), 10, 2 );
		}

	}

	public function payuni_shipping_columns_head( $defaults ) {
		$defaults['payuni_shipping'] = __( 'ShipNo', 'wu-payuni-shipping' );
		return $defaults;
	}

	function payuni_shipping_columns_content( $column_name, $post_id ) {

		$order = wc_get_order( $post_id );
		if ( ! $order ) {
			return;
		}
		if ( $column_name == 'payuni_shipping' ) {
			$ship_no = $order->get_meta( OrderMeta::ShipNo );
			$ship_type = $order->get_meta( OrderMeta::ShipType );
			
			$provider_query_url = ( $ship_type === ShipType::SEVEN ) ? 'https://tracking.shopmore.com.tw/' : 'https://www.t-cat.com.tw/inquire/trace.aspx';
			$provider_query_html = ( empty( $ship_no ) ) ? '' : '<a href="' . esc_url( $provider_query_url ) . '" target="_blank">'.$ship_no.'</a>' ;

            echo !empty( $ship_no )? $provider_query_html : __( 'N/A', 'wu-payuni-shipping' );
		}

	}

}
