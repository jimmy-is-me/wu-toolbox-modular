<?php

/**
 * Referral Link Core Functions
 */
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Generate or get user's referral code
 */
function spar_get_user_referral_code(  $user_id  ) {
    if ( !$user_id ) {
        return false;
    }
    $referral_code = get_user_meta( $user_id, 'spar_referral_code', true );
    if ( empty( $referral_code ) ) {
        // Generate a new referral code
        $user = get_userdata( $user_id );
        if ( !$user ) {
            return false;
        }
        // Create code with 10 characters random letters and numbers
        $base_code = wp_generate_password( 10, false, false );
        $referral_code = strtoupper( $base_code );
        // Ensure uniqueness
        $attempts = 0;
        while ( spar_referral_code_exists( $referral_code ) && $attempts < 10 ) {
            $base_code = wp_generate_password( 10, false, false );
            $referral_code = strtoupper( $base_code );
            $attempts++;
        }
        update_user_meta( $user_id, 'spar_referral_code', $referral_code );
    }
    return $referral_code;
}

/**
 * Check if referral code already exists
 */
function spar_referral_code_exists(  $code  ) {
    $args = [
        'meta_key'   => 'spar_referral_code',
        'meta_value' => $code,
        'fields'     => 'ID',
    ];
    $user_query = new WP_User_Query($args);
    return !empty( $user_query->results );
}

/**
 * Get user by referral code
 */
function spar_get_user_by_referral_code(  $referral_code  ) {
    $args = [
        'meta_key'   => 'spar_referral_code',
        'meta_value' => $referral_code,
        'fields'     => 'ID',
    ];
    $user_query = new WP_User_Query($args);
    if ( !empty( $user_query->results ) ) {
        return $user_query->results[0];
    }
}

/**
 * Get user's referral statistics
 */
function spar_get_user_referral_stats(  $user_id  ) {
    if ( !$user_id ) {
        return [
            'referral_code'        => '',
            'successful_referrals' => 0,
            'total_points_earned'  => 0,
            'total_clicks'         => 0,
        ];
    }
    $referral_code = spar_get_user_referral_code( $user_id );
    $stats = get_user_meta( $user_id, 'spar_referral_stats', true );
    if ( !is_array( $stats ) ) {
        $stats = [
            'successful_referrals' => 0,
            'total_points_earned'  => 0,
            'total_clicks'         => 0,
        ];
    }
    // Ensure total_clicks exists
    if ( !isset( $stats['total_clicks'] ) ) {
        $stats['total_clicks'] = 0;
    }
    $stats['referral_code'] = $referral_code;
    return $stats;
}

/**
 * Update user's referral statistics
 */
function spar_update_user_referral_stats(  $user_id, $points_earned = 0  ) {
    $stats = get_user_meta( $user_id, 'spar_referral_stats', true );
    if ( !is_array( $stats ) ) {
        $stats = [
            'successful_referrals' => 0,
            'total_points_earned'  => 0,
            'total_clicks'         => 0,
        ];
    }
    // Ensure total_clicks exists
    if ( !isset( $stats['total_clicks'] ) ) {
        $stats['total_clicks'] = 0;
    }
    $stats['successful_referrals']++;
    $stats['total_points_earned'] += $points_earned;
    update_user_meta( $user_id, 'spar_referral_stats', $stats );
}

/**
 * Increment referral link clicks
 */
function spar_increment_referral_clicks(  $user_id  ) {
    $stats = get_user_meta( $user_id, 'spar_referral_stats', true );
    if ( !is_array( $stats ) ) {
        $stats = [
            'successful_referrals' => 0,
            'total_points_earned'  => 0,
            'total_clicks'         => 0,
        ];
    }
    // Ensure total_clicks exists
    if ( !isset( $stats['total_clicks'] ) ) {
        $stats['total_clicks'] = 0;
    }
    $stats['total_clicks']++;
    update_user_meta( $user_id, 'spar_referral_stats', $stats );
}

/**
 * Generate referral URL
 */
function spar_generate_referral_url(  $user_id, $base_url = ''  ) {
    $referral_code = spar_get_user_referral_code( $user_id );
    if ( !$referral_code ) {
        return false;
    }
    if ( empty( $base_url ) ) {
        $base_url = home_url();
    }
    return add_query_arg( 'ref', $referral_code, $base_url );
}

add_action( 'wp_ajax_spar_generate_referral_coupon', 'spar_ajax_generate_referral_coupon' );
function spar_ajax_generate_referral_coupon() {
    wp_send_json_error( esc_html__( 'Referral gift offers are a premium feature.', 'simple-points-and-rewards' ) );
}

function spar_get_user_referral_coupon_code(  $user_id  ) {
    return false;
}

/**
 * AJAX handler to check if user has existing referral coupon
 */
add_action( 'wp_ajax_spar_check_referral_coupon', 'spar_ajax_check_referral_coupon' );
function spar_ajax_check_referral_coupon() {
    // Verify nonce
    check_ajax_referer( 'spar_referral_nonce', 'nonce', true );
    $user_id = get_current_user_id();
    if ( !$user_id ) {
        wp_send_json_error( esc_html__( 'User not logged in.', 'simple-points-and-rewards' ) );
    }
    // Check if user has existing coupon
    $existing_coupon = spar_get_user_referral_coupon_code( $user_id );
    if ( $existing_coupon ) {
        wp_send_json_success( array(
            'coupon_code' => $existing_coupon,
            'has_coupon'  => true,
        ) );
    } else {
        wp_send_json_success( array(
            'has_coupon' => false,
        ) );
    }
}

/**
 * Enqueue scripts and localize AJAX URL for my account page
 */
add_action( 'wp_enqueue_scripts', 'spar_enqueue_referral_scripts' );
function spar_enqueue_referral_scripts() {
    // Only load on my account page
    if ( !is_wc_endpoint_url( 'rewards' ) && !is_account_page() ) {
        return;
    }
    // Ensure the referral system script is enqueued and localize minimal data
    wp_enqueue_script( 'spar-referral-system' );
    // Localize script with AJAX URL and nonce on the plugin handle
    wp_localize_script( 'spar-referral-system', 'spar_ajax', array(
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'spar_referral_nonce' ),
    ) );
}
