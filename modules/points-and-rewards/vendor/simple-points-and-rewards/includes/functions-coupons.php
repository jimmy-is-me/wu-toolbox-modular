<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// Exclude gift-, voucher- and ref- coupons from default coupons list
add_action( 'pre_get_posts', 'spar_exclude_reward_coupons_from_coupon_list' );
function spar_exclude_reward_coupons_from_coupon_list( $query ) {
	if ( is_admin() && $query->is_main_query() && $query->get('post_type') === 'shop_coupon' ) {
		global $pagenow;
		if ( $pagenow === 'edit.php' ) {
			add_filter('posts_where', function( $where, $q ) {
				global $wpdb;
				if ( $q->get('post_type') === 'shop_coupon' ) {
					// Allow developers to control which prefixes are excluded in the list view
					$prefixes = apply_filters( 'spar_reward_coupon_prefixes', [ 'gift-', 'voucher-', 'ref-', 'spin-' ] );
					// Sanitize prefixes defensively
					$prefixes = is_array( $prefixes ) ? array_map( 'sanitize_text_field', $prefixes ) : [ 'gift-', 'voucher-', 'ref-' ];
					if ( is_array( $prefixes ) && ! empty( $prefixes ) ) {
						foreach ( $prefixes as $prefix ) {
							$like = $wpdb->esc_like( (string) $prefix ) . '%';
							$where .= $wpdb->prepare( " AND {$wpdb->posts}.post_title NOT LIKE %s", $like );
						}
					}
				}
				return $where;
			}, 10, 2);
		}
	}
}
