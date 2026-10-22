<?php

namespace WuDigital\Payuni\Shipping\Api;

use WuDigital\Payuni\Shipping\Utils\SingletonTrait;
use WuDigital\Payuni\Shipping\PayuniShipping;
use WuDigital\Payuni\Shipping\Utils\OrderMeta;
use WuDigital\Payuni\Shipping\Utils\ShippingStatus;

class ShippingResponse {

    use SingletonTrait;

    /**
	 * Initialize class and add hooks.
	 *
	 * @return void
	 */
	public static function init() {
		self::get_instance();

		// 選擇超商後，接收超商資訊.
		add_action( 'woocommerce_api_payuni_choose_cvs_callback', array( self::get_instance(), 'payuni_choose_cvs_callback' ) );

		// 7-11 物流貨態回傳.
		add_action( 'woocommerce_api_payuni_shipping_711_notify', array( self::get_instance(), 'payuni_711_receive_update' ) );

		// TCAT 物流貨態回傳.
		add_action( 'woocommerce_api_payuni_shipping_tcat_notify', array( self::get_instance(), 'payuni_tcat_receive_update' ) );

        add_action( 'woocommerce_api_payuni_shipping_711_callback', array( self::get_instance(), 'payuni_711_receive_update' ) );
        add_action( 'woocommerce_api_payuni_shipping_tcat_callback', array( self::get_instance(), 'payuni_tcat_receive_update' ) );
		// 根據貨態更新訂單狀態.
		add_action( 'payuni_update_shipping_order_status', array( self::get_instance(), 'update_order_status_after_received_update' ), 10, 2 );

	}

    /**
	 * Choose CVS callback when use return from CVS map.
	 *
	 * @return void
	 */
	public static function payuni_choose_cvs_callback() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing

		$posted = wc_clean( wp_unslash( $_POST ) );
		PayuniShipping::log( 'receive cvs callback: ' . wc_print_r( $posted, true ) );

		$cvs_info = array();

		if ( ! empty( $posted ) ) {

			$merid        = $posted['MerID'];
			$status       = array_key_exists( 'Status', $posted )? $posted['Status'] : '';
			$encrypt_info = array_key_exists( 'EncryptInfo', $posted )? $posted['EncryptInfo']: '';
			$hash_info    = array_key_exists( 'HashInfo', $posted )? $posted['HashInfo'] : '';


			if ( $status === 'SUCCESS') {
				$decrypted_info = PayuniShipping::decrypt( $encrypt_info );
				PayuniShipping::log( 'PAYUNi CVS NotifyURL response decrypted:' . wc_print_r( $decrypted_info, true ) );
				$store_data = json_decode( $decrypted_info['MapJson'], true );
			}

			$cvs_info[ 'payuni_storetype' ] = ( isset( $store_data[ 'StoreType' ] ) )? $store_data[ 'StoreType' ] : '';
			$cvs_info[ 'payuni_storeid' ] = ( isset( $store_data[ 'StoreID' ] ) )? $store_data[ 'StoreID' ] : '';
			$cvs_info[ 'payuni_storename' ] = ( isset( $store_data[ 'StoreName' ] ) )? $store_data[ 'StoreName' ] : '';
			$cvs_info[ 'payuni_storeaddress' ] = ( isset( $store_data[ 'Address' ] ) )? $store_data[ 'Address' ] : '';
			

			// May received additional data for CVS Family Frozen shipping.
			$cvs_info = apply_filters( 'payuni_shipping_cvs_callback', $cvs_info, $posted );

			// redirect to admin order edit screen
			if ( isset( $_REQUEST['order_id'] ) ) {
				$order = wc_get_order( $_REQUEST['order_id'] );
				if ( $order ) {
					PayuniShipping::payuni_save_order_shipping_meta( $order, $cvs_info );
					$order->add_order_note( sprintf( __( 'CVS store has changed to %1$s. The PAYUNi shipping order will be revoked and recreated.', 'wu-payuni-shipping' ), $cvs_info['payuni_storename'] ) );
					do_action( 'payuni_after_admin_changed_cvs_store', $order );
					wp_redirect( admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' ) );
					die();
				}
			}
		}

		$payuni_checkout_url = ( array_key_exists( 'payuni_checkout_url', $_COOKIE ) ) ? wp_unslash( $_COOKIE['payuni_checkout_url'] ) : wc_get_page_permalink( 'checkout' );
		$checkout_page_url   = ( empty( $payuni_checkout_url ) ) ? wc_get_page_permalink( 'checkout' ) : $payuni_checkout_url;

		// post to checkout page, so the cvs field can be saved.
		$html  = '<!doctype html><html ' . get_language_attributes( 'html' ) . '><head><meta charset="' . get_bloginfo( 'charset', 'display' ) . '"><title>AutoSubmitForm</title></head><body>';
		$html .= '<form method="post" id="payuni-map-redirect" action="' . esc_url( $checkout_page_url ) . '" style="display:none;">';
		foreach ( $cvs_info as $key => $value ) {
			$html .= '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '">';
		}
		$html .= '</form>';
		$html .= '<script type="text/javascript">document.getElementById("payuni-map-redirect").submit();</script>';
		$html .= '</body></html>';

		echo $html;
		die();
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	public static function payuni_711_receive_update() {
		$posted = wc_clean( wp_unslash( $_POST ) );
		PayuniShipping::log( 'payuni_711_receive_update: ' . wc_print_r( $posted, true ) );

		$encrypt_info = array_key_exists( 'EncryptInfo', $posted )? $posted['EncryptInfo']: '';

		$decrypted_info = PayuniShipping::decrypt( $encrypt_info );
		PayuniShipping::log( 'PAYUNi NotifyURL response decrypted:' . wc_print_r( $decrypted_info, true ) );

		/** 
		 * response example:
		 * [ShipTradeNo] => S001171111222238713093
		 * [LgsType] => C2C
		 * [GoodsType] => 1
		 * [ShipType] => 1
		 * [ShipStatus] => 92
		 * [ShipStatusDesc] => 處理中，等待商店寄貨
		 * [ShipStatusTime] => 2023-12-07 16:07:58
		 * [MerID] => S01234567
		 * [PartnerId] => 8B3
		 * [ApiType] => ShipStatus
		 * [Status] => SUCCESS
		 * [Message] => (測試環境)貨態狀態處理成功(92)
		 * 
		 * */ 
		

		if ( 'SUCCESS' === $decrypted_info['Status'] ) {
			$orders = wc_get_orders(
				array(
					'meta_query' => array(
						array(
							'key' => OrderMeta::ShipTradeNo,
							'value' => $decrypted_info['ShipTradeNo'],
							'compare' => '=',
						),
					),
				)
			);

			if ( ! empty( $orders ) ) {
				$order = $orders[0];

				if ( array_key_exists( 'Odno', $decrypted_info ) ) {
					$order->update_meta_data( OrderMeta::PartnerId, $decrypted_info['PartnerId'] );
					//物流查詢編號 Odno+ValidationNo
					$order->update_meta_data( OrderMeta::ShipNo, $decrypted_info['Odno'] . $decrypted_info['ValidationNo'] );
					$order->update_meta_data( OrderMeta::Odno, $decrypted_info['Odno'] );
					$order->update_meta_data( OrderMeta::ValidationNo, $decrypted_info['ValidationNo'] );
					$order->save();
				}

				if ( array_key_exists( 'ShipStatus', $decrypted_info ) ) {
					$order->update_meta_data( OrderMeta::ShipStatus, $decrypted_info['ShipStatus'] );
					$order->update_meta_data( OrderMeta::ShipStatusDesc, $decrypted_info['ShipStatusDesc'] );
					$order->update_meta_data( OrderMeta::ShipStatusTime, $decrypted_info['ShipStatusTime'] );
					$order->save();
					$order->add_order_note( 'Shipping status update from PAYUNi: ShipStatus:' . $decrypted_info['ShipStatus'] . ', ShipStatusDesc:' . $decrypted_info['ShipStatusDesc'] . ', ShipStatusTime:' . $decrypted_info['ShipStatusTime'] );

					// change order status based on ShipStatus.
				do_action( 'payuni_update_shipping_order_status', $order, $decrypted_info['ShipStatus'] );
				}
				

				
			}
		}
	}

	/**
	 * Handling TCat shipping status update from PAYUNi.
	 *
	 * @return void
	 */
	public static function payuni_tcat_receive_update() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$posted = wc_clean( wp_unslash( $_POST ) );
		PayuniShipping::log( 'payuni_tcat_receive_update: ' . wc_print_r( $posted, true ) );

		$encrypt_info = array_key_exists( 'EncryptInfo', $posted )? $posted['EncryptInfo']: '';

		$decrypted_info = PayuniShipping::decrypt( $encrypt_info );
		PayuniShipping::log( 'PAYUNi TCat NotifyURL response decrypted:' . wc_print_r( $decrypted_info, true ) );

		// [ShipTradeNo] => SC170141125758007251
		// [TradeType] => 1
		// [OBTNumber] => 620006439963 //宅配單號
		// [LgsType] => HOME
		// [GoodsType] => 1
		// [ShipType] => 2
		// [FileNo] => WwXCoTgL2x48q56web5GiHSMI6lF9Lbn
		// [ShipStatus] => 21
		// [ShipStatusDesc] => 已產宅配單號
		// [ShipStatusTime] => 2023-12-01 14:14:45
		// [MerID] => S12345678
		// [ApiType] => ShipStatus
		// [Status] => SUCCESS
		// [Message] => (測試環境)貨態狀態處理成功(21)

		if ( 'SUCCESS' === $decrypted_info['Status'] ) {
			$orders = wc_get_orders(
				array(
					'meta_query' => array(
						array(
							'key' => OrderMeta::ShipTradeNo,
							'value' => $decrypted_info['ShipTradeNo'],
							'compare' => '=',
						),
					),
				)
			);
			
			if ( ! empty( $orders ) ) {
				$order = $orders[0];
				if ( $decrypted_info['ApiType'] === 'ShipStatus' ) {
					$order->update_meta_data( OrderMeta::ShipNo, $decrypted_info['OBTNumber'] );
					$order->update_meta_data( OrderMeta::ShipStatus	, $decrypted_info['ShipStatus'] );
					$order->update_meta_data( OrderMeta::ShipStatusDesc	, $decrypted_info['ShipStatusDesc'] );
					$order->update_meta_data( OrderMeta::ShipStatusTime	, $decrypted_info['ShipStatusTime'] );
				}

				if ( array_key_exists( 'FileNo', $decrypted_info ) ) {
					$order->update_meta_data( OrderMeta::FileNo, $decrypted_info['FileNo'] );
				}
				$order->save();
	
				if (  array_key_exists( 'ShipStatus', $decrypted_info ) ) {
					$order->add_order_note( 'Shipping status update from PAYUNi: ShipStatus:' . $decrypted_info['ShipStatus'] . ', ShipStatusDesc:' . $decrypted_info['ShipStatusDesc'] . ', ShipStatusTime:' . $decrypted_info['ShipStatusTime'] );
					// change order status based on ShipStatus.
					do_action( 'payuni_update_shipping_order_status', $order, $decrypted_info['ShipStatus'] );
				}

			} else {
				PayuniShipping::log( 'PAYUNi NotifyURL response fail: can not find order by ShipTradeNo:' . $decrypted_info['ShipTradeNo'] );
			}
	
		} else {
			PayuniShipping::log( 'PAYUNi NotifyURL response fail:' . wc_print_r( $decrypted_info, true ) );
		}

		
		return;
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Update order status when received update from PAYUNi.
	 *
	 * @param \WC_Order $order The order object.
	 * @param string   $ship_status The ship_status of PAYUNi shipping.
	 * @return void
	 */
	public function update_order_status_after_received_update( \WC_Order $order, $ship_status ) {

		PayuniShipping::log( 'Update order stauts. Order id:' . $order->get_id() . ', ship_status:' . $ship_status );

		if ( ShippingStatus::READY_SHIPPING_HD === $ship_status || ShippingStatus::READY_SHIPPING_C2C === $ship_status ) {
			if ( ! empty( PayuniShipping::$order_status_ready_shipping ) ) {
				$order->update_status( PayuniShipping::$order_status_ready_shipping );
			}
		} elseif ( ShippingStatus::AT_LOGISTIC_CENTER === $ship_status ) {
			if ( ! empty( PayuniShipping::$order_status_at_logistic_center ) ) {
				$order->update_status( PayuniShipping::$order_status_at_logistic_center );
			}
		} elseif ( ShippingStatus::DELIVERING === $ship_status ) {
			if ( ! empty( PayuniShipping::$order_status_delivering ) ) {
				$order->update_status( PayuniShipping::$order_status_delivering );
			}
		} elseif ( ShippingStatus::AT_RECEIVER_CVS === $ship_status ) {
			if ( ! empty( PayuniShipping::$order_status_at_receiver_cvs ) ) {
				$order->update_status( PayuniShipping::$order_status_at_receiver_cvs );
			}
		} elseif ( ShippingStatus::CUSTOMER_PICKUP === $ship_status ) {
			if ( ! empty( PayuniShipping::$order_status_pickuped ) ) {
				$order->update_status( PayuniShipping::$order_status_pickuped );
			}
		}
	}


}
