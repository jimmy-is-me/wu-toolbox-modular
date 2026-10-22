<?php
namespace WuStandaloneEzpay;
defined( 'ABSPATH' ) || exit;

class Settings extends \WC_Settings_Page {
    public function __construct() {
        $this->id = 'wu-ezpay-invoice';
        $this->label = 'EZPAY 電子發票';
        parent::__construct();
    }
    public function get_settings( $current_section = '' ) {
        $settings = [
						[
							'title' => 'ezPay 電子發票設定',
							'type'  => 'title',
							'id'    => 'wc_woomp_general_setting',
						],
						[
							'title'   => '除錯資訊',
							'type'    => 'checkbox',
							'default' => 'no',
							'desc'    => sprintf( '紀錄日誌於以下路徑：<code>%s</code>', wc_get_log_file_path( 'wu-ezpay-invoice' ) ),
							'id'      => 'wc_woomp_ezpay_invoice_debug_log_enabled',
						],
						[
							'title'    => __( 'Order number prefix', 'wu' ),
							'id'       => 'wc_woomp_ezpay_invoice_order_prefix',
							'type'     => 'text',
							'desc'     => __( 'The prefix string of order number. Only letters and numbers allowed.', 'wu' ),
							'desc_tip' => true,
						],

						[
							'type' => 'sectionend',
							'id'   => 'wc_woomp_general_setting',
						],
						[
							'title' => __( 'Invoice options', 'wu' ),
							'id'    => 'invoice_options',
							'type'  => 'title',
						],
						[
							'name'     => __( 'Issue Mode', 'paynow-einvoice' ),
							'type'     => 'select',
							'desc'     => __( 'You can issue the e-invoice manually even if you choose Automatic mode', 'wu' ),
							'class'    => 'wc-enhanced-select',
							'desc_tip' => true,
							'id'       => 'wc_woomp_ezpay_invoice_issue_mode',
							'options'  => [
								'manual' => __( 'Issue Manual', 'wu' ),
								'auto'   => __( 'Issue automatic', 'wu' ),
							],
							'default'  => 'manual',
						],
						[
							'name'     => __( 'Allowed Order Status for issue', 'wu' ),
							'type'     => 'select',
							'class'    => 'wc-enhanced-select',
							'desc'     => __( 'When order status changes to the status, the e-invoice will be issued automatically.', 'wu' ),
							'id'       => 'wc_woomp_ezpay_invoice_issue_at',
							'desc_tip' => true,
							'options'  => wc_get_order_statuses(),
						],
						[
							'name'     => __( 'Invalid mode', 'wu' ),
							'type'     => 'select',
							'desc'     => '選擇自動作廢後，仍可於訂單中手動作廢發票。',
							'class'    => 'wc-enhanced-select',
							'desc_tip' => true,
							'id'       => 'wc_woomp_ezpay_invoice_invalid_mode',
							'options'  => [
								'manual' => __( 'Invalid manual', 'wu' ),
								'auto'   => __( 'Invalid automatic', 'wu' ),
							],
							'default'  => 'auto',
						],
						[
							'name'     => __( 'Allowed Order Status for invalid', 'wu' ),
							'type'     => 'select',
							'class'    => 'wc-enhanced-select',
							'desc'     => __( 'When order status changes to the status, the e-invoice will be invalid automatically.', 'wu' ),
							'id'       => 'wc_woomp_ezpay_invoice_invalid_at',
							'desc_tip' => true,
							'options'  => [
								'wc-refunded' => __( 'Refunded', 'woocommerce' ),
								'wc-failed'   => __( 'Failed', 'woocommerce' ),
							],
						],
						[
							'name'    => __( 'Carrier Type', 'paynow-einvoice' ),
							'desc'    => __( 'Allowed invoice carrier type', 'wu' ),
							'id'      => 'wc_woomp_ezpay_invoice_carrier_type',
							'class'   => 'wc-enhanced-select',
							'type'    => 'multiselect',
							'options' => [
								__( '雲端電子發票載具', 'wu' )  => __( '雲端電子發票載具', 'wu' ),
								'手機條碼' => __( 'Mobile Code', 'paynow-einvoice' ),
								'自然人憑證'    => __( 'Citizen Digital Certificate', 'paynow-einvoice' ),
							],
						],

						[
							'name'        => __( 'Donated Organization', 'paynow-einvoice' ),
							'type'        => 'textarea',
							'desc'        => '輸入捐贈機構(每行一筆)，格式為：愛心碼|社福團體名稱，預設為伊甸社會福利基金會',
							'desc_tip'    => true,
							'id'          => 'wc_woomp_ezpay_invoice_donate_org',
							'placeholder' => '25885|伊甸社會福利基金會',
						],
						[
							'id'   => 'invoice_options',
							'type' => 'sectionend',
						],
						[
							'title' => '商家資料設定（測試模式）',
							'desc'  => '請於 ezPay 電子發票測試平台 <a href="https://cinv.ezpay.com.tw/">https://cinv.ezpay.com.tw/</a> 申請會員並建立測試商店',
							'type'  => 'title',
							'id'    => 'wc_woomp_ezpay_invoice_api_settings_test',
						],
						[
							'title' => '測試模式',
							'type'  => 'checkbox',
							'desc'  => '勾選時為測試模式',
							'id'    => 'wc_woomp_ezpay_invoice_testmode_enabled',
						],
						[
							'title' => '測試商家編號',
							'type'  => 'text',
							'desc'  => '請輸入測試商家代號',
							'id'    => 'wc_woomp_ezpay_invoice_merchant_id_test',
						],
						[
							'title' => '測試 HashKey',
							'type'  => 'text',
							'desc'  => '請輸入測試 HashKey',
							'id'    => 'wc_woomp_ezpay_invoice_hashkey_test',
						],
						[
							'title' => '測試 HashIV',
							'type'  => 'text',
							'desc'  => '請輸入測試 HashIV',
							'id'    => 'wc_woomp_ezpay_invoice_hashiv_test',
						],
						[
							'type' => 'sectionend',
							'id'   => 'wc_woomp_ezpay_invoice_api_settings_test',
						],
						[
							'title' => '商家資料設定（正式環境）',
							'desc'  => '請於 ezPay 電子發票加值服務平台 <a href="https://inv.ezpay.com.tw/">https://inv.ezpay.com.tw/</a> 申請會員並建立商店',
							'type'  => 'title',
							'id'    => 'wc_woomp_ezpay_invoice_api_settings',
						],
						[
							'title' => '正式商家編號',
							'type'  => 'text',
							'desc'  => '請輸入正式商家代號',
							'id'    => 'wc_woomp_ezpay_invoice_merchant_id',
						],
						[
							'title' => '正式 HashKey',
							'type'  => 'text',
							'desc'  => '請輸入正式 HashKey',
							'id'    => 'wc_woomp_ezpay_invoice_hashkey',
						],
						[
							'title' => '正式 HashIV',
							'type'  => 'text',
							'desc'  => '請輸入正式 HashIV',
							'id'    => 'wc_woomp_ezpay_invoice_hashiv',
						],
						[
							'type' => 'sectionend',
							'id'   => 'wc_woomp_ezpay_invoice_api_settings',
						],
					];
        array_splice( $settings, 1, 0, [ [
            'title' => '啟用發票功能',
            'id' => 'wc_woomp_enabled_ezpay_invoice',
            'type' => 'checkbox',
            'default' => 'no',
            'desc' => '啟用結帳發票欄位及訂單發票操作',
        ] ] );
        return $settings;
    }
}
return new Settings();
