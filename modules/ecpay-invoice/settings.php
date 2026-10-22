<?php
namespace WuStandaloneEcpay;
defined( 'ABSPATH' ) || exit;

class Settings extends \WC_Settings_Page {
    public function __construct() {
        $this->id = 'wu-ecpay-invoice';
        $this->label = '綠界電子發票';
        parent::__construct();
    }
    public function get_settings( $current_section = '' ) {
        $settings = [
						[
							'title' => '綠界電子發票設定',
							'type'  => 'title',
							'id'    => 'wc_woomp_general_setting',
						],
						[
							'title'   => '除錯資訊',
							'type'    => 'checkbox',
							'default' => 'no',
							'desc'    => sprintf( '紀錄日誌於以下路徑：<code>%s</code>', wc_get_log_file_path( 'wu-ecpay-invoice' ) ),
							'id'      => 'wc_woomp_ecpay_invoice_debug_log_enabled',
						],
						[
							'title'    => __( 'Order number prefix', 'wu' ),
							'id'       => 'wc_woomp_ecpay_invoice_order_prefix',
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
							'desc'     => __( 'You can issue the e-invoice manually even if you choose Automatic mode' ),
							'class'    => 'wc-enhanced-select',
							'desc_tip' => true,
							'id'       => 'wc_woomp_ecpay_invoice_issue_mode',
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
							'desc'     => __( 'When order status changes to the status, the e-invoice will be issued automatically.' ),
							'id'       => 'wc_woomp_ecpay_invoice_issue_at',
							'desc_tip' => true,
							'options'  => wc_get_order_statuses(),
						],
						[
							'name'     => __( 'Invalid mode', 'wu' ),
							'type'     => 'select',
							'desc'     => __( 'You can issue the e-invoice manually even if you choose Automatic mode' ),
							'class'    => 'wc-enhanced-select',
							'desc_tip' => true,
							'id'       => 'wc_woomp_ecpay_invoice_invalid_mode',
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
							'desc'     => __( 'When order status changes to the status, the e-invoice will be invalid automatically.' ),
							'id'       => 'wc_woomp_ecpay_invoice_invalid_at',
							'desc_tip' => true,
							'options'  => [
								'wc-refunded' => __( 'Refunded', 'woocommerce' ),
								'wc-failed'   => __( 'Failed', 'woocommerce' ),
							],
						],
						[
							'name'    => __( 'Carrier Type', 'paynow-einvoice' ),
							'desc'    => __( 'Allowed invoice carrier type', 'wu' ),
							'id'      => 'wc_woomp_ecpay_invoice_carrier_type',
							'class'   => 'wc-enhanced-select',
							'type'    => 'multiselect',
							'options' => [
								__( 'Cloud Invoice', 'wu' )  => __( 'Cloud Invoice', 'wu' ),
								__( 'Mobile Code', 'paynow-einvoice' ) => __( 'Mobile Code', 'paynow-einvoice' ),
								__( 'Citizen Digital Certificate', 'paynow-einvoice' )    => __( 'Citizen Digital Certificate', 'paynow-einvoice' ),
								__( 'Paper Invoice', 'wu' )  => __( 'Paper Invoice', 'wu' ),
							],
						],

						[
							'name'        => __( 'Donated Organization', 'paynow-einvoice' ),
							'type'        => 'textarea',
							'desc'        => '輸入捐增機構(每行一筆)，格式為：愛心碼|社福團體名稱，預設為伊甸社會福利基金會',
							'desc_tip'    => true,
							'id'          => 'wc_woomp_ecpay_invoice_donate_org',
							'placeholder' => '25885|伊甸社會福利基金會',
						],
						[
							'id'   => 'invoice_options',
							'type' => 'sectionend',
						],
						[
							'title' => '商家資料設定',
							'type'  => 'title',
							'id'    => 'wc_woomp_ecpay_invoice_api_settings',
						],
						[
							'title' => '測試模式',
							'type'  => 'checkbox',
							'desc'  => '請勾選時會測試模式，未勾選則會使用下方資料作為正式交易環境',
							'id'    => 'wc_woomp_ecpay_invoice_testmode_enabled',
						],
						[
							'title' => '商家編號',
							'type'  => 'text',
							'desc'  => '請輸入正式商家代號',
							'id'    => 'wc_woomp_ecpay_invoice_merchant_id',
						],
						[
							'title' => '正式 HashKey',
							'type'  => 'text',
							'desc'  => '請輸入 HashKey',
							'id'    => 'wc_woomp_ecpay_invoice_hashkey',
						],
						[
							'title' => '正式 HashIV',
							'type'  => 'text',
							'desc'  => '請輸入 HashIV',
							'id'    => 'wc_woomp_ecpay_invoice_hashiv',
						],
						[
							'type' => 'sectionend',
							'id'   => 'wc_woomp_ecpay_invoice_api_settings',
						],
					];
        array_splice( $settings, 1, 0, [ [
            'title' => '啟用發票功能',
            'id' => 'wc_woomp_enabled_ecpay_invoice',
            'type' => 'checkbox',
            'default' => 'yes',
            'desc' => '啟用結帳發票欄位及訂單發票操作',
        ] ] );
        return $settings;
    }
}
return new Settings();
