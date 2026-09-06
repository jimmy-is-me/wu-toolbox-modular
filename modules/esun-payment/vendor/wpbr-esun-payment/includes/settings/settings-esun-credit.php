<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Settings for esun Payment Gateway
 */
return array(

    'enabled' => array(
        'title'       => __( 'Enable/Disable', 'wpbr-esun-payment' ),
        'type'        => 'checkbox',
        'label'       => __( 'Enable', 'wpbr-esun-payment' ),
        'default'     => 'no'
    ),
    'title' => array(
        'title'       => __( 'Title', 'wpbr-esun-payment' ),
        'type'        => 'text',
        'description' => __( 'This controls the title which the user sees during checkout.', 'wpbr-esun-payment'),
        'default'     => __( 'ESUN Credit Card Payment Gateway', 'wpbr-esun-payment' ),
        'desc_tip'    => true,
    ),
    'description' => array(
        'title'       => __( 'Description', 'wpbr-esun-payment' ),
        'type'        => 'textarea',
        'description' => __('This controls the description which the user sees during checkout.', 'wpbr-esun-payment'),
        'desc_tip'    => true,
    ),
    'order_button_text' => array(
        'title'       => __( 'Order Button Text', 'wpbr-esun-payment' ),
        'type'        => 'text',
        'default'     => __( 'Proceed to ESUN', 'wpbr-esun-payment' ),
        'desc_tip'    => true,
    ),

);
