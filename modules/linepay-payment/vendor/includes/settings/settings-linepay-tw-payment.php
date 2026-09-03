<?php
/**
 * LINE Pay payment settings file
 *
 * @package linepay_tw
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings for LINE Pay Taiwan Payment Gateway
 */
return array(

	'enabled'     => array(
		'title'   => __( 'Enable/Disable', 'wpbr-linepay-tw' ),
		'type'    => 'checkbox',
		'label'   => __( 'Enable', 'wpbr-linepay-tw' ),
		'default' => 'no',
	),
	'title'       => array(
		'title'       => __( 'Title', 'wpbr-linepay-tw' ),
		'type'        => 'text',
		'description' => __( 'This controls the title which the user sees during checkout.', 'wpbr-linepay-tw' ),
		'default'     => __( 'LINE Pay', 'wpbr-linepay-tw' ),
		'desc_tip'    => true,
	),
	'description' => array(
		'title'       => __( 'Description', 'wpbr-linepay-tw' ),
		'type'        => 'textarea',
		'description' => __( 'This controls the description which the user sees during checkout.', 'wpbr-linepay-tw' ),
		'desc_tip'    => true,
	),

);
