<?php
/**
 * 通知模組 - 當 API 呼叫失敗，或商品 XML 重新產生完成時，
 * 寄送 Email 通知給指定收件人。收件人留空則不寄送（沙用 v3.3.1 設計）。
 *
 * @package WooShopcomIntegration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ShopCom_Notifier {

	/** @var ShopCom_Settings */
	private $settings;

	public function __construct( ShopCom_Settings $settings ) {
		$this->settings = $settings;

		add_action( 'shopcom_api_failed', array( $this, 'notify_api_failure' ), 10, 3 );
		add_action( 'shopcom_xml_generated', array( $this, 'notify_xml_generated' ), 10, 1 );
	}

	private function get_recipients() {
		$raw = $this->settings->get( 'notify_emails' );
		if ( empty( $raw ) ) {
			return array();
		}
		$emails = array_map( 'trim', explode( ',', $raw ) );
		return array_filter( $emails, 'is_email' );
	}

	public function notify_api_failure( $order, $message, $type ) {
		$recipients = $this->get_recipients();
		if ( empty( $recipients ) ) {
			return;
		}

		$action_label = 'cancel' === $type
			? __( '取消訂單', 'woo-shopcom' )
			: __( '建立訂單', 'woo-shopcom' );

		$subject = sprintf(
			/* translators: 1: site name 2: order number */
			__( '[%1$s] 美安 API %2$s 失敗通知 - 訂單 #%3$s', 'woo-shopcom' ),
			get_bloginfo( 'name' ),
			$action_label,
			$order->get_order_number()
		);

		$body = sprintf(
			"訂單編號：#%s\n動作：%s\n錯誤內容：%s\n訂單編輯連結：%s\n",
			$order->get_order_number(),
			$action_label,
			is_string( $message ) ? $message : wp_json_encode( $message ),
			$order->get_edit_order_url()
		);

		wp_mail( $recipients, $subject, $body );
	}

	public function notify_xml_generated( $xml ) {
		$recipients = $this->get_recipients();
		if ( empty( $recipients ) ) {
			return;
		}

		$subject = sprintf(
			/* translators: %s: site name */
			__( '[%s] 美安商品 XML 已重新建立', 'woo-shopcom' ),
			get_bloginfo( 'name' )
		);

		$body = sprintf(
			"商品目錄 XML 已於 %s 重新建立完成。\n目錄網址：%s\n",
			current_time( 'mysql' ),
			home_url( '/?feed=shopcom' )
		);

		wp_mail( $recipients, $subject, $body );
	}
}
