<?php

namespace WuDigital\Payuni\Shipping\Admin;

use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;


use WuDigital\Payuni\Shipping\PayuniShipping;
use WuDigital\Payuni\Shipping\Utils\ShipType;
use WuDigital\Payuni\Shipping\Utils\GoodsType;
use WuDigital\Payuni\Shipping\Utils\LgsType;
use WuDigital\Payuni\Shipping\Utils\OrderMeta;

/**
 * OrderMetaBox class file.
 *
 * @package payuni
 */

defined( 'ABSPATH' ) || exit;

/**
 * Display shipping info for PAYUNi shipping order.
 */
class OrderMetaBox {

	/**
	 * Add meta box at order edit screen.
	 *
	 * @param string $post_type The post type.
	 * @param object $post_or_order_object The post or order object.
	 * @return void
	 */
	public static function add_meta_box( $post_type, $post_or_order_object ) {

        $order = ( $post_or_order_object instanceof \WP_Post ) ? wc_get_order( $post_or_order_object->ID ) : $post_or_order_object;

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$screen = wc_get_container()->get( CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
		? wc_get_page_screen_id( 'shop-order' )
		: 'shop_order';

        foreach ( $order->get_items( 'shipping' ) as $item_id => $item ) {
            if ( $item instanceof \WC_Order_Item_Shipping && PayuniShipping::is_payuni_shipping( $item->get_method_id() ) ) {
                add_meta_box( 'payuni-shipping-info', __( 'PAYUNi Shipping Info', 'wu-payuni-shipping' ), array( __CLASS__, 'output' ), $screen, 'side', 'high' );
                break;
            }
        }
    
	}

	/**
	 * Output the meta box content.
	 *
	 * @param object $post The post object.
	 * @return void
	 */
	public static function output( $post ) {
		global $theorder;

		if ( ! is_object( $theorder ) ) {
			$theorder = wc_get_order( $post->ID );
		}

		// UNi 物流單號.
		echo '<table>';
		echo '<tr><th><div id="order-id" data-order-id="' . esc_html( $theorder->get_id() ) . '">' . esc_html__( 'ShipTradeNo', 'wu-payuni-shipping' ) . '</div></th><td>' . esc_html( $theorder->get_meta( OrderMeta::ShipTradeNo ) ) . '</td></tr>';

		$ship_type     = $theorder->get_meta( OrderMeta::ShipType );
		$goods_type    = $theorder->get_meta( OrderMeta::GoodsType );
		$lgs_type      = $theorder->get_meta( OrderMeta::LgsType );
		$partner_id    = $theorder->get_meta( OrderMeta::PartnerId );
		$odno          = $theorder->get_meta( OrderMeta::Odno );
		$validation_no = $theorder->get_meta( OrderMeta::ValidationNo );
		$shipping_no   = $theorder->get_meta( OrderMeta::ShipNo ); 

		$status      = $theorder->get_meta( OrderMeta::ShipStatus );
		$status_desc = $theorder->get_meta( OrderMeta::ShipStatusDesc );
		$status_time = $theorder->get_meta( OrderMeta::ShipStatusTime );

		$provider_query_url = ( $ship_type === ShipType::SEVEN ) ? 'https://tracking.shopmore.com.tw/' : 'https://www.t-cat.com.tw/inquire/trace.aspx';
		$provider_query_html = ( empty( $shipping_no ) ) ? '' : '<a href="' . esc_url( $provider_query_url ) . '" target="_blank"><span class="dashicons dashicons-search"></span></a>' ;
		
		//物流查詢編號(使用者查詢用的物流單號)
		echo '<tr><th>' . esc_html__( 'ShipNo', 'wu-payuni-shipping' ) . '</th><td>' . esc_html( $shipping_no ) . $provider_query_html .'</td></tr>';

		if ( $ship_type === ShipType::SEVEN ) {
			echo '<tr><th>' . esc_html__( 'PartnerID', 'wu-payuni-shipping' ) . '</th><td>' . esc_html( $partner_id ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Odon', 'wu-payuni-shipping' ) . '</th><td>' . esc_html( $odno ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'ValidationNo', 'wu-payuni-shipping' ) . '</th><td>' . esc_html( $validation_no ) . '</td></tr>';
		}
		echo '<tr><th>' . esc_html__( 'Ship Type', 'wu-payuni-shipping' ) . '</th><td>' . esc_html( ShipType::get_name( $ship_type ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Logistic Type', 'wu-payuni-shipping' ) . '</th><td>' . esc_html( LgsType::get_name( $lgs_type ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Goods Type', 'wu-payuni-shipping' ) . '</th><td>' . esc_html( GoodsType::get_name( $goods_type ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Status', 'wu-payuni-shipping' ) . '</th><td>' . esc_html( $status ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Status Desc', 'wu-payuni-shipping' ) . '</th><td>' . esc_html( $status_desc ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Status Time', 'wu-payuni-shipping' ) . '</th><td>' . esc_html( $status_time ) . '</td></tr>';
		
		if ( ! empty( $shipping_no ) && $ship_type === ShipType::TCAT ) {
			$label_btn = '<button class="button print-label" data-id=' . esc_attr( $theorder->get_id() ) . ' data-service="' . esc_html( $ship_type ) . '" data-action="payuni_shipping_download_label">下載標籤</button>';
		} else {
			$label_btn = '<button class="button print-label" data-id=' . esc_attr( $theorder->get_id() ) . ' data-service="' . esc_html( $ship_type ) . '" data-action="payuni_shipping_print_label">列印標籤</button>';
		}
		
		echo '<tr id="payuni-action"><th>物流單動作</th><td>' . $label_btn . '<button class="button update-delivery-status" data-id="' . esc_attr( $theorder->get_id() ) . '">查詢</button></td></tr>';
		echo '</table>';
		
		?>

		<?php
		wc_enqueue_js(
			'jQuery(function($) {
$("#payuni-shipping-info .print-label").click(function(event){
    event.preventDefault();
    window.open(ajaxurl + "?" + $.param({
        action: $(this).data("action"), security: payuni_shipping.security,
        orderids: $(this).data("id"),
		service: $(this).data("service"),
    }), "_blank", "toolbar=yes,scrollbars=yes,resizable=yes");
});
});'
		);

	}
}
