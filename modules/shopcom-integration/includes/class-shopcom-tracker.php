<?php
/**
 * 追踪模組 - 擷取網址上的 RID / Click_ID，依美安規則存放於 Session
 * （直到瀏覽器關閉才清除），結賬建立訂單時寫入訂單 Meta（相容 HPOS）。
 *
 * @package WooShopcomIntegration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ShopCom_Tracker {

	/** @var ShopCom_Settings */
	private $settings;

	const SESSION_KEY_RID      = 'shopcom_rid';
	const SESSION_KEY_CLICK_ID = 'shopcom_click_id';

	public function __construct( ShopCom_Settings $settings ) {
		$this->settings = $settings;

		add_action( 'wp_loaded', array( $this, 'capture_tracking_parameters' ) );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'save_tracking_to_order' ), 10, 2 );
		add_action( 'wp_footer', array( $this, 'render_debug_panel' ) );

		add_action( 'woocommerce_thankyou', array( $this, 'render_fraud_notice' ), 5 );

		add_action( 'woocommerce_account_orders_columns', array( $this, 'add_account_order_column' ) );
		add_action( 'woocommerce_my_account_my_orders_column_shopcom-status', array( $this, 'render_account_order_column' ) );
	}

	private function validate_rid( $rid ) {
		return (bool) preg_match( '/^[A-Za-z0-9\-_]{1,64}$/', $rid );
	}

	private function validate_click_id( $click_id ) {
		return (bool) preg_match( '/^[A-Za-z0-9\-_.]{1,128}$/', $click_id );
	}

	public function capture_tracking_parameters() {
		if ( ! $this->settings->is_enabled() ) {
			return;
		}
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}

		// RID 是首要推薦來源參數，Click_ID 為輔助追踪 ID。
		$rid      = isset( $_GET['RID'] ) ? sanitize_text_field( wp_unslash( $_GET['RID'] ) ) : '';
		$click_id = isset( $_GET['Click_ID'] ) ? sanitize_text_field( wp_unslash( $_GET['Click_ID'] ) ) : '';

		if ( '' !== $rid && $this->validate_rid( $rid ) ) {
			WC()->session->set( self::SESSION_KEY_RID, $rid );
		}

		if ( '' !== $click_id && $this->validate_click_id( $click_id ) ) {
			WC()->session->set( self::SESSION_KEY_CLICK_ID, $click_id );
		}
	}

	private function get_session_rid() {
		return function_exists( 'WC' ) && WC()->session ? WC()->session->get( self::SESSION_KEY_RID ) : '';
	}

	private function get_session_click_id() {
		return function_exists( 'WC' ) && WC()->session ? WC()->session->get( self::SESSION_KEY_CLICK_ID ) : '';
	}

	/**
	 * 結賬時將追踪資料寫入訂單，使用 CRUD 方法確保 HPOS 相容。
	 */
	public function save_tracking_to_order( $order, $data ) {
		$rid      = $this->get_session_rid();
		$click_id = $this->get_session_click_id();

		if ( empty( $rid ) ) {
			return;
		}

		$order->update_meta_data( '_shopcom_rid', $rid );
		$order->update_meta_data( '_shopcom_click_id', $click_id );
		$order->update_meta_data( '_shopcom_api_sent', 'no' );
		$order->update_meta_data( '_shopcom_status', 'pending' );

		$order->add_order_note(
			sprintf(
				/* translators: 1: RID 2: Click_ID */
				__( '此為美安（Shop.com）來源訂單。RID: %1$s，Click_ID: %2$s', 'woo-shopcom' ),
				$rid,
				$click_id ? $click_id : '—'
			)
		);
	}

	public function render_debug_panel() {
		if ( ! $this->settings->is_debug() || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( ! is_cart() && ! is_checkout() ) {
			return;
		}
		$rid      = $this->get_session_rid();
		$click_id = $this->get_session_click_id();
		if ( empty( $rid ) && empty( $click_id ) ) {
			return;
		}
		printf(
			'<div style="position:fixed;bottom:10px;right:10px;z-index:9999;background:#1d2327;color:#fff;padding:8px 12px;font-size:12px;border-radius:4px;">%s RID: %s | Click_ID: %s</div>',
			esc_html__( '[美安除錯]', 'woo-shopcom' ),
			esc_html( $rid ),
			esc_html( $click_id ? $click_id : '—' )
		);
	}

	public function render_fraud_notice( $order_id ) {
		if ( 'yes' !== $this->settings->get( 'show_fraud_notice' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order || ! $order->get_meta( '_shopcom_rid' ) ) {
			return;
		}
		echo '<div class="woocommerce-info shopcom-fraud-notice">' .
			esc_html__( '請注意：本網站不會透過電話或簡訊要求您提供信用卡驗證碼或一次性密碼，如接獲此類要求請提高警覺並向本站客服確認。', 'woo-shopcom' ) .
			'</div>';
	}

	public function add_account_order_column( $columns ) {
		if ( 'yes' !== $this->settings->get( 'show_account_status' ) ) {
			return $columns;
		}
		$new_columns = array();
		foreach ( $columns as $key => $label ) {
			$new_columns[ $key ] = $label;
			if ( 'order-status' === $key ) {
				$new_columns['shopcom-status'] = __( '美安狀態', 'woo-shopcom' );
			}
		}
		return $new_columns;
	}

	public function render_account_order_column( $order ) {
		$rid = $order->get_meta( '_shopcom_rid' );
		if ( empty( $rid ) ) {
			echo '&mdash;';
			return;
		}
		$sent = $order->get_meta( '_shopcom_api_sent' );
		echo 'yes' === $sent
			? '<span style="color:#2a8f43;">' . esc_html__( '已同步', 'woo-shopcom' ) . '</span>'
			: '<span style="color:#b32d2e;">' . esc_html__( '處理中', 'woo-shopcom' ) . '</span>';
	}
}
