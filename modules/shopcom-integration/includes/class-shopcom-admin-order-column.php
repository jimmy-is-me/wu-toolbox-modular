<?php
/**
 * 後台訂單列表模組 - 在訂單列表標示美安訂單，並在訂單編輯頁顯示
 * 销售金額與佣金資訊，方便管理者一眼辨識與核對。
 *
 * @package WooShopcomIntegration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ShopCom_Admin_Order_Column {

	/** @var ShopCom_Settings */
	private $settings;

	public function __construct( ShopCom_Settings $settings ) {
		$this->settings = $settings;

		// 相容 HPOS 與傳統 post-based 訂單列表兩種畫面。
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_order_list_column' ) );
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_order_list_column' ) );

		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render_order_list_column' ), 10, 2 );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_order_list_column_legacy' ), 10, 2 );

		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'render_order_meta_box' ) );
	}

	public function add_order_list_column( $columns ) {
		$columns['shopcom'] = __( '美安', 'woo-shopcom' );
		return $columns;
	}

	private function render_column_content( $order ) {
		if ( ! $order ) {
			return;
		}
		$rid = $order->get_meta( '_shopcom_rid' );
		if ( empty( $rid ) ) {
			echo '&mdash;';
			return;
		}

		$status = $order->get_meta( '_shopcom_status' );
		$badges = array(
			'pending'   => array( '#e6a23c', __( '待送出', 'woo-shopcom' ) ),
			'sent'      => array( '#2a8f43', __( '已成立', 'woo-shopcom' ) ),
			'cancelled' => array( '#b32d2e', __( '已取消', 'woo-shopcom' ) ),
		);
		$badge = isset( $badges[ $status ] ) ? $badges[ $status ] : array( '#999', __( '未知', 'woo-shopcom' ) );

		printf(
			'<span style="color:%s;font-weight:600;" title="RID: %s">● %s</span>',
			esc_attr( $badge[0] ),
			esc_attr( $rid ),
			esc_html( $badge[1] )
		);
	}

	public function render_order_list_column( $column, $order ) {
		if ( 'shopcom' === $column ) {
			$this->render_column_content( $order );
		}
	}

	public function render_order_list_column_legacy( $column, $post_id ) {
		if ( 'shopcom' === $column ) {
			$this->render_column_content( wc_get_order( $post_id ) );
		}
	}

	public function render_order_meta_box( $order ) {
		$rid = $order->get_meta( '_shopcom_rid' );
		if ( empty( $rid ) ) {
			return;
		}

		$click_id   = $order->get_meta( '_shopcom_click_id' );
		$amount     = $order->get_meta( '_shopcom_order_amount' );
		$commission = $order->get_meta( '_shopcom_commission' );
		$status     = $order->get_meta( '_shopcom_status' );

		echo '<div class="shopcom-order-meta" style="margin-top:12px;padding:10px;background:#f8f8f8;border:1px solid #e2e2e2;">';
		echo '<h4 style="margin:0 0 8px;">' . esc_html__( '美安（Shop.com）串接資訊', 'woo-shopcom' ) . '</h4>';
		echo '<p><strong>RID：</strong>' . esc_html( $rid ) . '</p>';
		echo '<p><strong>Click_ID：</strong>' . esc_html( $click_id ? $click_id : '—' ) . '</p>';
		echo '<p><strong>' . esc_html__( '狀態', 'woo-shopcom' ) . '：</strong>' . esc_html( $status ) . '</p>';

		if ( '' !== $amount ) {
			echo '<p><strong>' . esc_html__( '銷售金額', 'woo-shopcom' ) . '：</strong>' . wp_kses_post( wc_price( $amount ) ) . '</p>';
		}
		if ( '' !== $commission ) {
			echo '<p><strong>' . esc_html__( '預估佣金', 'woo-shopcom' ) . '：</strong>' . wp_kses_post( wc_price( $commission ) ) . '</p>';
		}

		// 手動取消訂單時，退款金額改為唯讀顯示（目前不支援部分退款重新計算）。
		if ( 'sent' === $status ) {
			echo '<p style="color:#b32d2e;font-size:12px;">' .
				esc_html__( '注意：手動取消訂單時，退款金額為唯讀，暫不支援部分退款重新計算。', 'woo-shopcom' ) .
				'</p>';
		}
		echo '</div>';
	}
}
