<?php
/**
 * Settings for Apple Pay payment.
 *
 * @package payuni
 */

 defined( 'ABSPATH' ) || exit;

/**
 * Settings for PAYUNi Apple Pay payment gateway
 */
return array(

	'enabled'                    => array(
		'title'   => __( 'Enable/Disable', 'wpbr-payuni-payment' ),
		'type'    => 'checkbox',
		'label'   => __( 'Enable', 'wpbr-payuni-payment' ),
		'default' => 'no',
	),
	'title'                      => array(
		'title'       => __( 'Title', 'wpbr-payuni-payment' ),
		'type'        => 'text',
		'description' => __( 'This controls the title which the user sees during checkout.', 'wpbr-payuni-payment' ),
		'default'     => __( 'PAYUNi Google Pay Payment', 'wpbr-payuni-payment' ),
		'desc_tip'    => true,
	),
	'description'                => array(
		'title'       => __( 'Description', 'wpbr-payuni-payment' ),
		'type'        => 'textarea',
		'description' => __( 'This controls the description which the user sees during checkout.', 'wpbr-payuni-payment' ),
		'desc_tip'    => true,
	),
	'incomplete_payment_message' => array(
		'title'       => __( 'Incomplete Payment Message', 'wpbr-payuni-payment' ),
		'type'        => 'textarea',
		'description' => __( 'This controls the message displayed on thank you page when the payment is incomplated.', 'wpbr-payuni-payment' ),
		'desc_tip'    => true,
	),

);
