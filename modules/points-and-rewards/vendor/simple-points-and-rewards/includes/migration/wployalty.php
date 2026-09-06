<?php
if ( ! defined( 'ABSPATH' ) ) {
	return;
}

/**
 * Get WPLoyalty user data including points and birthday
 * 
 * @param int $user_id WordPress user ID
 * @return array|false Array of user data or false if user not found
 */
function spar_migrate_wployalty_get_user_data( $user_id ) {
    // Validate user ID
    if ( empty( $user_id ) || $user_id <= 0 ) {
        return false;
    }
    
    // Get WordPress user
    $wp_user = get_userdata( $user_id );
    if ( ! $wp_user ) {
        return false;
    }
    
    $user_email = $wp_user->user_email;

    // Check class existence
    if ( ! class_exists( '\Wlr\App\Helpers\EarnCampaign' ) ) {
        return false;
    }
    
    $earn_campaign_helper = \Wlr\App\Helpers\EarnCampaign::getInstance();
    $user = $earn_campaign_helper->getPointUserByEmail( $user_email );
    
    if ( ! $user ) {
        return false;
    }

    // Check Woocommerce helper class
    if ( ! class_exists( '\Wlr\App\Helpers\Woocommerce' ) ) {
        return false;
    }
    
    $woocommerce_helper = \Wlr\App\Helpers\Woocommerce::getInstance();
    
    // Get birthday (handles both new and legacy fields)
    $birthday_date = '';
    if ( ! empty( $user->birthday_date ) && $user->birthday_date != '0000-00-00' ) {
        $birthday_date = $user->birthday_date; // Already in Y-m-d format
    } elseif ( ! empty( $user->birth_date ) ) {
        $birthday_date = $woocommerce_helper->beforeDisplayDate( $user->birth_date, 'Y-m-d' );
    }
    
    // Return user data array
    return [
        'user_id'             => $user_id,
        'user_email'          => $user_email,
        'available_points'    => isset( $user->points ) ? (int) $user->points : 0,
        'total_earned_points' => isset( $user->earn_total_point ) ? (int) $user->earn_total_point : 0,
        'total_used_points'   => isset( $user->used_total_points ) ? (int) $user->used_total_points : 0,
        'birthday'            => $birthday_date,
        'level_id'            => isset( $user->level_id ) ? (int) $user->level_id : 0,
        'refer_code'          => isset( $user->refer_code ) ? trim( (string) $user->refer_code ) : '',
        'is_banned_user'      => isset( $user->is_banned_user ) ? (int) $user->is_banned_user : 0,
    ];
}