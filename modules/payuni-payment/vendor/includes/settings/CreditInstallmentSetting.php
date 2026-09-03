<?php
/**
 * Settings for credit card payment.
 *
 * @package payuni
 */

 defined( 'ABSPATH' ) || exit;

/**
 * Settings for PAYUNi credit card payment gateway
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
		'default'     => __( 'PAYUNi Installment Payment', 'wpbr-payuni-payment' ),
		'desc_tip'    => true,
	),
	'description'                => array(
		'title'       => __( 'Description', 'wpbr-payuni-payment' ),
		'type'        => 'textarea',
		'description' => __( 'This controls the description which the user sees during checkout.', 'wpbr-payuni-payment' ),
		'desc_tip'    => true,
	),
	'min_amount'                 => array(
		'title'       => __( 'Minimum Amount', 'wpbr-payuni-payment' ),
		'type'        => 'number',
		'description' => __( 'Minimum amount to use this payment gateway.', 'wpbr-payuni-payment' ),
	),
	'incomplete_payment_message' => array(
		'title'       => __( 'Incomplete Payment Message', 'wpbr-payuni-payment' ),
		'type'        => 'textarea',
		'description' => __( 'This controls the message displayed on thank you page when the payment is incomplated.', 'wpbr-payuni-payment' ),
		'desc_tip'    => true,
	),

);
