<?php
return [
	'enabled'           => [
		'title'   => __( 'Enable/Disable', 'woocommerce' ),
		/* translators: %s: Gateway method title */
		'label'   => sprintf( __( 'Enable %s', 'wu-newebpay-tools' ), $this->method_title ),
		'type'    => 'checkbox',
		'default' => 'no',
	],
	'title'             => [
		'title'       => __( 'Title', 'woocommerce' ),
		'type'        => 'text',
		'default'     => $this->method_title,
		'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce' ),
		'desc_tip'    => true,
	],
	'description'       => [
		'title'       => __( 'Description', 'woocommerce' ),
		'type'        => 'text',
		'default'     => $this->order_button_text,
		'desc_tip'    => true,
		'description' => __( 'This controls the description which the user sees during checkout.', 'woocommerce' ),
	],
	'min_amount'        => [
		'title'             => __( 'Minimum order amount', 'wu-newebpay-tools' ),
		'type'              => 'number',
		'default'           => 0,
		'placeholder'       => 0,
		'description'       => __( '0 to disable minimum amount limit.', 'wu-newebpay-tools' ),
		'custom_attributes' => [
			'min'  => 0,
			'step' => 1,
		],
	],
	'number_of_periods' => [
		'title'             => __( 'Enable number of periods', 'wu-newebpay-tools' ),
		'type'              => 'multiselect',
		'class'             => 'wc-enhanced-select',
		'css'               => 'width: 400px;',
		'default'           => '',
		'description'       => '',
		'options'           => [
			/* translators: %d number of periods */
			3  => sprintf( __( '%d periods', 'wu-newebpay-tools' ), 3 ),
			/* translators: %d number of periods */
			6  => sprintf( __( '%d periods', 'wu-newebpay-tools' ), 6 ),
			/* translators: %d number of periods */
			12 => sprintf( __( '%d periods', 'wu-newebpay-tools' ), 12 ),
			/* translators: %d number of periods */
			18 => sprintf( __( '%d periods', 'wu-newebpay-tools' ), 18 ),
			/* translators: %d number of periods */
			24 => sprintf( __( '%d periods', 'wu-newebpay-tools' ), 24 ),
			/* translators: %d number of periods */
			30 => sprintf( __( '%d periods', 'wu-newebpay-tools' ), 30 ),
		],
		'desc_tip'          => true,
		'custom_attributes' => [
			'data-placeholder' => _x( 'Number of periods', 'Gateway setting', 'wu-newebpay-tools' ),
		],
	],
];
