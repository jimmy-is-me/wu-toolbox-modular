<?php
return [
	[
		'title' => __( '藍新物流設定', 'wu-newebpay-tools' ),
		'id'    => 'base_options',
		'type'  => 'title',
		'desc'  => __( 'Because NewebPay limit, the shipping note no or shipping status can not show in site admin.', 'wu-newebpay-tools' ),
	],
	// [
	// 'title' => __('Enable/Disable', 'woocommerce'),
	// 'id' => WU_NP_WT::$option_prefix . 'newebpay_shipping',
	// 'type' => 'checkbox',
	// 'default' => 'no',
	// 'desc' => __('Enable NewebPay shipping method', 'wu-newebpay-tools')
	// ],
	// [
	// 'title' => __('cvs remove billing address', 'wu-newebpay-tools-pro'),
	// 'id' => WU_NP_WTP::$option_prefix . 'newebpay_cvs_billing_address',
	// 'type' => 'checkbox',
	// 'default' => 'no',
	// 'desc' => __('Remove billing address when shipping mode is cvs.', 'wu-newebpay-tools-pro') . '<br>'
	// . __('The billing address still will show in order details.', 'wu-newebpay-tools-pro')
	// ],
	[
		'id'   => 'base_options',
		'type' => 'sectionend',
	],
];
