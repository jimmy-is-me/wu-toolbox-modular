<?php
return [
	[
		'title' => __( '藍新金流設定', 'wu-newebpay-tools' ),
		'id'    => 'base_options',
		'type'  => 'title',
	],
	// [
	// 'title' => __('Enable/Disable', 'woocommerce'),
	// 'id' => WU_NP_WT::$option_prefix . 'newebpay_gateway',
	// 'type' => 'checkbox',
	// 'default' => 'no',
	// 'desc' => __('Enable NewebPay gateway method', 'wu-newebpay-tools')
	// ],
	[
		'title'   => __( 'Debug log', 'woocommerce' ),
		'id'      => WU_NP_WT::$option_prefix . 'newebpay_gateway_log',
		'type'    => 'checkbox',
		'default' => 'no',
		'desc'    => __( 'Enable logging', 'woocommerce' ) . '<br>'
			. sprintf(
				/* translators: %s: Path of log file */
				__( 'Log NewebPay gateway events/message, inside %s', 'wu-newebpay-tools' ),
				'<code>' . WC_Log_Handler_File::get_log_file_path( 'wu_np_newebpay_gateway' ) . '</code>'
			),
	],
	[
		'title'    => __( 'Order no prefix', 'wu-newebpay-tools' ),
		'id'       => WU_NP_WT::$option_prefix . 'newebpay_gateway_order_prefix',
		'type'     => 'text',
		'desc'     => __( 'The prefix string of order no. Only letters and numbers allowed allowed.', 'wu-newebpay-tools' ),
		'desc_tip' => true,
	],
	[
		'title'   => '信用卡分期設定',
		'id'      => 'wu_np_newebpay_credit_installment',
		'type'    => 'checkbox',
		'default' => 'no',
		'desc'    => '勾選後會將分期期數獨立為個別的付款方式',
	],
	[
		'id'   => 'base_options',
		'type' => 'sectionend',
	],
	[
		'title' => __( 'API credentials', 'wu-newebpay-tools' ),
		'id'    => 'api_options',
		'type'  => 'title',
	],
	[
		'title'   => __( 'NewebPay gateway sandbox', 'wu-newebpay-tools' ),
		'id'      => WU_NP_WT::$option_prefix . 'newebpay_gateway_testmode',
		'type'    => 'checkbox',
		'default' => 'yes',
		'desc'    => __( 'Enable NewebPay gateway sandbox', 'wu-newebpay-tools' ),
	],
	[
		'title'   => __( 'MerchantID', 'NewebPay', 'wu-newebpay-tools' ),
		'id'      => WU_NP_WT::$option_prefix . 'newebpay_gateway_MerchantID',
		'type'    => 'text',
		'default' => '',
	],
	[
		'title'   => __( 'HashKey', 'NewebPay', 'wu-newebpay-tools' ),
		'id'      => WU_NP_WT::$option_prefix . 'newebpay_gateway_HashKey',
		'type'    => 'text',
		'default' => '',
	],
	[
		'title'   => __( 'HashIV', 'NewebPay', 'wu-newebpay-tools' ),
		'id'      => WU_NP_WT::$option_prefix . 'newebpay_gateway_HashIV',
		'type'    => 'text',
		'default' => '',
	],
	[
		'id'   => 'api_options',
		'type' => 'sectionend',
	],
];
