<?php
/**
 * List claimed voucher codes and usage status
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$spar_current_user = wp_get_current_user();

// Use the same function as cart-checkout for consistency
$spar_claimed_vouchers = spar_get_user_available_vouchers( $spar_current_user->ID );

// Also get used vouchers for this user (bounded query with no_found_rows)
$spar_used_vouchers = get_posts([
	'post_type'      => 'shop_coupon',
	'post_status'    => 'publish',
	'numberposts'    => 200,
	'no_found_rows'  => true,
	'meta_query'     => [
		'relation' => 'AND',
		[
			'relation' => 'OR',
			[
				'key' => '_spar_user_id',
				'value' => $spar_current_user->ID,
				'compare' => '='
			],
			[
				'key' => 'customer_email',
				'value' => $spar_current_user->user_email,
				'compare' => 'LIKE'
			]
		],
		[
			'key' => 'usage_count',
			'value' => 0,
			'compare' => '>'
		]
	],
	'orderby' => 'date',
	'order'   => 'DESC'
]);

// Combine available and used vouchers
$spar_all_vouchers = array_merge( $spar_claimed_vouchers, $spar_used_vouchers );

// Remove duplicates
$spar_unique_vouchers = [];
$spar_seen_voucher_ids = [];
foreach ( $spar_all_vouchers as $spar_voucher ) {
	if ( ! in_array( $spar_voucher->ID, $spar_seen_voucher_ids, true ) ) {
		$spar_unique_vouchers[] = $spar_voucher;
		$spar_seen_voucher_ids[] = $spar_voucher->ID;
	}
}

$spar_claimed_vouchers = $spar_unique_vouchers;
$spar_points_label = spar_get_option( 'general', 'points_label' );

if ( $spar_claimed_vouchers ) :
	?>
	<h3 class="spar-redeem-title"><?php esc_html_e( 'Your Claimed Vouchers', 'simple-points-and-rewards' ); ?></h3>
	<p class="spar-redeem-intro"><?php /* translators: %s: points label (lowercase) */ printf( esc_html__( 'Below are the vouchers you have claimed using your %s.', 'simple-points-and-rewards' ), esc_html( strtolower( $spar_points_label ) ) ); ?></p>
    <p class="spar-redeem-intro"><?php esc_html_e( 'You can use these vouchers at checkout. If a voucher is marked as "Used", it has already been applied to an order.', 'simple-points-and-rewards' ); ?></p>
	<table class="spar-points-log widefat">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Coupon Code', 'simple-points-and-rewards' ); ?></th>
				<th class="spar-voucher-amount-col"><?php esc_html_e( 'Amount', 'simple-points-and-rewards' ); ?></th>
			<th class="spar-w-260 spar-nowrap"><?php esc_html_e( 'Status', 'simple-points-and-rewards' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $spar_claimed_vouchers as $spar_coupon_post ) :
				$spar_coupon_amount = get_post_meta( $spar_coupon_post->ID, 'coupon_amount', true );
				$spar_coupon_type = get_post_meta( $spar_coupon_post->ID, 'discount_type', true );
				$spar_coupon_used_count   = (int) get_post_meta( $spar_coupon_post->ID, 'usage_count', true );
				$spar_coupon_status = $spar_coupon_used_count ? esc_html__( 'Used', 'simple-points-and-rewards' ) : esc_html__( 'Available', 'simple-points-and-rewards' );
				$spar_coupon_code = $spar_coupon_post->post_title;
				
				// Format amount display. Product / bundle vouchers show the product
				// name (or "Bundle Name (Product, Product, ...)" for a bundle).
				$spar_amount_display = function_exists( 'spar_get_voucher_product_display' ) ? spar_get_voucher_product_display( $spar_coupon_post->ID ) : '';
				// The product display is already HTML-escaped; flag it so it is not
				// re-escaped below (product/bundle vouchers are stored as 'percent').
				$spar_is_product_display = ( '' !== $spar_amount_display );

				if ( empty( $spar_amount_display ) ) {
					if ( $spar_coupon_type === 'percent' ) {
						$spar_amount_display = $spar_coupon_amount . '%';
					} else {
						$spar_amount_display = wc_price( $spar_coupon_amount );
					}
				}
				?>
				<tr>
					<td>
							<button type="button" class="spar-copy-voucher" data-code="<?php echo esc_attr( $spar_coupon_code ); ?>" title="<?php esc_attr_e( 'Click to copy', 'simple-points-and-rewards' ); ?>" aria-label="<?php /* translators: %s: voucher code */ echo esc_attr( sprintf( __( 'Copy voucher code %s', 'simple-points-and-rewards' ), $spar_coupon_code ) ); ?>">
								<span class="spar-copy-voucher-code"><?php echo esc_html( $spar_coupon_code ); ?></span>
								<span class="spar-copy-voucher-icon" aria-hidden="true">
									<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
								</span>
							</button>
						</td>
					<td class="spar-voucher-amount-col"><?php echo ( ! $spar_is_product_display && 'percent' === $spar_coupon_type ) ? esc_html( $spar_amount_display ) : wp_kses_post( $spar_amount_display ); ?></td>
					<td>
						<?php echo esc_html( $spar_coupon_status ); ?>
						<?php if ( ! $spar_coupon_used_count ) : ?>
							<button type="button" class="spar-apply-to-cart-btn spar-ml-8" data-voucher="<?php echo esc_attr( $spar_coupon_code ); ?>">
								<?php esc_html_e( 'Apply to Cart', 'simple-points-and-rewards' ); ?>
							</button>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

<?php else : ?>
	<h3 class="spar-redeem-title"><?php esc_html_e( 'Your Claimed Vouchers', 'simple-points-and-rewards' ); ?></h3>
	<p class="spar-redeem-intro"><?php esc_html_e( 'You haven\'t claimed any vouchers yet. Start earning points and redeem them for rewards!', 'simple-points-and-rewards' ); ?></p>
<?php endif; ?>