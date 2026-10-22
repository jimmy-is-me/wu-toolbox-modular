<?php
/**
 * PAYUNi Shipping hd TCAT setting array.
 *
 * @package payuni
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings for PAYUNi Shipping hd TCat
 */
return array(
	'title'                    => array(
		'title'       => __( 'Title', 'wu-payuni-shipping' ),
		'type'        => 'text',
		'description' => __( 'This controls the title which the user sees during checkout.', 'wu-payuni-shipping' ),
		'default'     => __( 'PAYUNi Shipping TCat', 'wu-payuni-shipping' ),
		'desc_tip'    => true,
	),
	'description'              => array(
		'title'       => __( 'Description', 'wu-payuni-shipping' ),
		'type'        => 'textarea',
		'description' => __( 'This controls the description which the user sees during checkout.', 'wu-payuni-shipping' ),
		'desc_tip'    => true,
	),
	'cost'                     => array(
		'title'   => __( 'Shipping Cost', 'wu-payuni-shipping' ),
		'type'    => 'number',
		'default' => 0,
		'min'     => 0,
		'step'    => 1,
	),
	'free_shipping_requires'   => array(
		'title'   => __( 'Free shipping requires', 'wu-payuni-shipping' ),
		'type'    => 'select',
		'class'   => 'wc-enhanced-select',
		'default' => '',
		'options' => array(
			''           => __( 'N/A', 'wu-payuni-shipping' ),
			'coupon'     => __( 'A valid free shipping coupon', 'wu-payuni-shipping' ),
			'min_amount' => __( 'A minimum order amount', 'wu-payuni-shipping' ),
			'either'     => __( 'A minimum order amount OR a coupon', 'wu-payuni-shipping' ),
			'both'       => __( 'A minimum order amount AND a coupon', 'wu-payuni-shipping' ),
		),
	),
	'free_shipping_min_amount' => array(
		'title'       => __( 'Minimum order amount for free shipping', 'wu-payuni-shipping' ),
		'type'        => 'price',
		'default'     => 0,
		'placeholder' => wc_format_localized_price( '0' ),
		'description' => __( 'Users will need to spend this amount to get free shipping.', 'wu-payuni-shipping' ),
		'desc_tip'    => true,
	),
	'package_spec' => array(
		'title'   => __( 'Package Spec', 'wu-payuni-shipping' ),
		'type'    => 'select',
		'class'   => 'wc-enhanced-select',
		'default' => '',
		'options' => array(
			'1'   => '60',
			'2'   => '90',
			'3'   => '120',
		),
	)
);
