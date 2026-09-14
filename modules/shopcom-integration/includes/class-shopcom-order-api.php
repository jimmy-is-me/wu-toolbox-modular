<?php
/**
 * 訂單 API 模組 - 負責在訂單状態改变時，呼叫美安建立訂單 / 取消訂單 API，
 * 並記錄销售金額與佣金資訊。所有邏輯僅在訂單状態改变時觸發，不影響一般頁面載入效能。
 *
 * @package WooShopcomIntegration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ShopCom_Order_API {

	/** @var ShopCom_Settings */
	private $settings;

	public function __construct( ShopCom_Settings $settings ) {
		$this->settings = $settings;

		$created_status = $this->settings->get( 'order_created_status' );
		$cancel_status   = $this->settings->get( 'order_cancel_status' );

		add_action( 'woocommerce_order_status_' . $created_status, array( $this, 'send_order_created' ), 20, 1 );

		if ( 'yes' === $this->settings->get( 'auto_cancel' ) ) {
			add_action( 'woocommerce_order_status_' . $cancel_status, array( $this, 'send_order_cancelled' ), 20, 1 );
		}

		// 手動觸發按鈕（訂單編輯頁）
		add_action( 'woocommerce_order_actions', array( $this, 'add_manual_order_action' ) );
		add_action( 'woocommerce_order_action_shopcom_manual_send', array( $this, 'send_order_created' ) );
		add_action( 'woocommerce_order_action_shopcom_manual_cancel', array( $this, 'send_order_cancelled' ) );

		// 訂單改变状態時（非取消），清空美安記錄避免重複觸發（沙用舊版邏輯精神）。
		add_action( 'woocommerce_order_status_changed', array( $this, 'guard_against_duplicate' ), 5, 4 );
	}

	private function is_shopcom_order( $order ) {
		return $order && ! empty( $order->get_meta( '_shopcom_rid' ) );
	}

	private function is_already_sent( $order ) {
		return 'yes' === $order->get_meta( '_shopcom_api_sent' );
	}

	private function is_already_cancelled( $order ) {
		return 'cancelled' === $order->get_meta( '_shopcom_status' );
	}

	public function guard_against_duplicate( $order_id, $from, $to, $order ) {
		if ( ! $this->is_shopcom_order( $order ) ) {
			return;
		}
		// 由「取消」變回其他状態時，允許重新送單（扣除已退款部分金額）。
		if ( 'cancelled' === $from && 'cancelled' !== $to ) {
			$order->update_meta_data( '_shopcom_api_sent', 'no' );
			$order->update_meta_data( '_shopcom_status', 'pending' );
			$order->save();
		}
	}

	/**
	 * 計算销售金額：依美安規則排除運費與折扣影響，並扣除已存在的部分退款金額。
	 */
	private function calculate_order_amount( $order ) {
		$amount = (float) $order->get_subtotal();
		$amount -= (float) $order->get_total_discount();

		$refunded = (float) $order->get_total_refunded();
		if ( $refunded > 0 ) {
			$amount -= $refunded;
		}

		return max( 0, round( $amount, 2 ) );
	}

	private function calculate_commission( $amount ) {
		$rate = (float) $this->settings->get( 'commission_rate' );
		return round( $amount * ( $rate / 100 ), 2 );
	}

	private function request_args( $body ) {
		$args = array(
			'body'    => wp_json_encode( $body ),
			'headers' => array( 'Content-Type' => 'application/json' ),
			'timeout' => 15,
		);

		if ( 'yes' === $this->settings->get( 'force_ipv4' ) && function_exists( 'stream_context_create' ) ) {
			// 強制 IPv4：透過自訂 transport 選項避免 IPv6 連線失敗（部分主機環境常見問題）。
			add_filter( 'http_api_curl', array( $this, 'force_curl_ipv4' ), 10, 1 );
		}

		return $args;
	}

	public function force_curl_ipv4( $handle ) {
		if ( defined( 'CURL_IPRESOLVE_V4' ) ) {
			curl_setopt( $handle, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4 );
		}
		return $handle;
	}

	public function send_order_created( $order_id ) {
		$order = $order_id instanceof WC_Order ? $order_id : wc_get_order( $order_id );
		if ( ! $order || ! $this->is_shopcom_order( $order ) || $this->is_already_sent( $order ) ) {
			return;
		}
		if ( ! $this->settings->is_enabled() ) {
			return;
		}

		$api_url = trailingslashit( $this->settings->get( 'api_base_url' ) ) . 'order/create';
		if ( empty( $this->settings->get( 'api_base_url' ) ) ) {
			$order->add_order_note( __( '美安 API 傳送失敗：API Base URL 尚未設定。', 'woo-shopcom' ) );
			return;
		}

		$amount     = $this->calculate_order_amount( $order );
		$commission = $this->calculate_commission( $amount );

		$payload = array(
			'Offer_ID'      => $this->settings->get( 'offer_id' ),
			'Advertiser_ID' => $this->settings->get( 'advertiser_id' ),
			'RID'           => $order->get_meta( '_shopcom_rid' ),
			'Click_ID'      => $order->get_meta( '_shopcom_click_id' ),
			'Order_ID'      => $order->get_order_number(),
			'Order_Date'    => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d' ) : gmdate( 'Y-m-d' ),
			'Order_Amount'  => $amount,
			'Commission'    => $commission,
			'Currency'      => $order->get_currency(),
		);

		$response = wp_remote_post( $api_url, $this->request_args( $payload ) );

		if ( is_wp_error( $response ) ) {
			$order->add_order_note(
				sprintf( __( '美安訂單成立 API 傳送失敗：%s', 'woo-shopcom' ), $response->get_error_message() )
			);
			do_action( 'shopcom_api_failed', $order, $response->get_error_message(), 'create' );
			return;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			$order->update_meta_data( '_shopcom_api_sent', 'yes' );
			$order->update_meta_data( '_shopcom_status', 'sent' );
			$order->update_meta_data( '_shopcom_order_amount', $amount );
			$order->update_meta_data( '_shopcom_commission', $commission );
			$order->add_order_note(
				sprintf(
					__( '美安訂單成立 API 傳送成功。銷售金額：%1$s，預估佣金：%2$s', 'woo-shopcom' ),
					wc_price( $amount ),
					wc_price( $commission )
				)
			);
		} else {
			$body = wp_remote_retrieve_body( $response );
			$order->add_order_note(
				sprintf( __( '美安訂單成立 API 回應異常（HTTP %1$s）：%2$s', 'woo-shopcom' ), $code, $body )
			);
			do_action( 'shopcom_api_failed', $order, $body, 'create' );
		}

		$order->save();
	}

	public function send_order_cancelled( $order_id ) {
		$order = $order_id instanceof WC_Order ? $order_id : wc_get_order( $order_id );
		if ( ! $order || ! $this->is_shopcom_order( $order ) || $this->is_already_cancelled( $order ) ) {
			return;
		}
		if ( ! $this->settings->is_enabled() ) {
			return;
		}

		$api_url = trailingslashit( $this->settings->get( 'api_base_url' ) ) . 'order/cancel';

		// 手動取消訂單時，退款金額為唯讀顯示，不支援部分退款重新計算（沙用舊版限制，避免金額誤算）。
		$refund_amount = (float) $order->get_meta( '_shopcom_order_amount' );

		$payload = array(
			'Offer_ID'      => $this->settings->get( 'offer_id' ),
			'Advertiser_ID' => $this->settings->get( 'advertiser_id' ),
			'RID'           => $order->get_meta( '_shopcom_rid' ),
			'Click_ID'      => $order->get_meta( '_shopcom_click_id' ),
			'Order_ID'      => $order->get_order_number(),
			'Refund_Amount' => $refund_amount,
		);

		$response = wp_remote_post( $api_url, $this->request_args( $payload ) );

		if ( is_wp_error( $response ) ) {
			$order->add_order_note(
				sprintf( __( '美安取消訂單 API 傳送失敗：%s', 'woo-shopcom' ), $response->get_error_message() )
			);
			do_action( 'shopcom_api_failed', $order, $response->get_error_message(), 'cancel' );
			return;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			$order->update_meta_data( '_shopcom_status', 'cancelled' );
			$order->add_order_note( __( '美安取消訂單 API 傳送成功。', 'woo-shopcom' ) );
		} else {
			$body = wp_remote_retrieve_body( $response );
			$order->add_order_note(
				sprintf( __( '美安取消訂單 API 回應異常（HTTP %1$s）：%2$s', 'woo-shopcom' ), $code, $body )
			);
			do_action( 'shopcom_api_failed', $order, $body, 'cancel' );
		}

		$order->save();
	}

	public function add_manual_order_action( $actions ) {
		global $theorder;
		if ( $theorder && $this->is_shopcom_order( $theorder ) ) {
			$actions['shopcom_manual_send']   = __( '手動：呼叫美安建立訂單 API', 'woo-shopcom' );
			$actions['shopcom_manual_cancel'] = __( '手動：呼叫美安取消訂單 API', 'woo-shopcom' );
		}
		return $actions;
	}
}
