<?php

/**
 * Subscriptions Settings Tab
 *
 * Only registered when WooCommerce Subscriptions is active. The "Renewal
 * earning rate" option is PRO-only; everything else is available in free.
 */
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Render the Subscriptions settings tab.
 */
function spar_settings_tab_subscriptions() {
    $defaults = spar_settings_default();
    $options = get_option( 'spar_options', $defaults );
    $options = array_merge( $defaults, $options );
    $renewal_enabled = !empty( $options['subscriptions_renewal_enabled'] );
    $percentage = ( isset( $options['subscriptions_renewal_percentage'] ) ? (float) $options['subscriptions_renewal_percentage'] : 100 );
    $fixed_points = ( isset( $options['subscriptions_renewal_fixed_points'] ) ? (int) $options['subscriptions_renewal_fixed_points'] : 0 );
    $award_timing = ( in_array( $options['subscriptions_renewal_award_timing'] ?? 'follow_order', array('follow_order', 'payment_complete', 'completed'), true ) ? $options['subscriptions_renewal_award_timing'] : 'follow_order' );
    $include_signup_fee = !empty( $options['subscriptions_signup_fee_include'] );
    $exclude_products = !empty( $options['subscriptions_exclude_products'] );
    $count_order_bonuses = !empty( $options['subscriptions_count_order_bonuses'] );
    // The renewal earning rate is a PRO option — the free version always uses
    // the full regular rate ('same').
    $rate_mode = 'same';
    $renewal_settings_display = ( $renewal_enabled ? '' : 'display:none;' );
    $percentage_row_display = ( 'percentage' === $rate_mode ? '' : 'display:none;' );
    $fixed_row_display = ( 'fixed' === $rate_mode ? '' : 'display:none;' );
    ?>
	<input type="hidden" name="subscriptions_present" value="1" />

	<h3><?php 
    esc_html_e( 'Subscriptions Integration', 'simple-points-and-rewards' );
    ?></h3>
	<p><?php 
    esc_html_e( 'Control how points are earned on subscription orders. This tab is available because WooCommerce Subscriptions is active on your site.', 'simple-points-and-rewards' );
    ?></p>

	<div class="spar-subscriptions-settings">

		<div class="spar-settings-section">
			<div class="spar-settings-section-header">
				<h4><?php 
    esc_html_e( 'Renewal Orders', 'simple-points-and-rewards' );
    ?></h4>
				<p><?php 
    esc_html_e( 'Points earned when a subscription automatically renews or a customer pays a renewal order.', 'simple-points-and-rewards' );
    ?></p>
			</div>
			<p>
				<label>
					<div class="spar-toggle-switch"><input type="checkbox" name="subscriptions_renewal_enabled" <?php 
    checked( $renewal_enabled );
    ?> /><span class="spar-toggle-slider"></span></div>
					<?php 
    esc_html_e( 'Award points on subscription renewal orders', 'simple-points-and-rewards' );
    ?>
				</label>
			</p>
			<p class="description"><?php 
    esc_html_e( 'When disabled, renewal orders never earn points. Initial subscription purchases are treated as regular orders either way.', 'simple-points-and-rewards' );
    ?></p>

			<div class="spar-subscriptions-renewal-settings"<?php 
    echo ( $renewal_settings_display ? ' style="' . esc_attr( $renewal_settings_display ) . '"' : '' );
    ?>>
				<?php 
    ?>
				<div class="spar-premium-disabled">
					<p style="margin-bottom: 0;">
						<label>
							<?php 
    esc_html_e( 'Renewal earning rate (PRO):', 'simple-points-and-rewards' );
    ?>
							<br/>
							<select disabled="disabled" style="margin: 0;">
								<option><?php 
    esc_html_e( 'Same as regular orders', 'simple-points-and-rewards' );
    ?></option>
							</select>
						</label>
					</p>
				</div>
				<p class="description"><?php 
    esc_html_e( 'Renewal orders earn points at the full regular rate. Upgrade to PRO to award a percentage of the regular points (e.g. 50%) or a fixed number of points per renewal instead.', 'simple-points-and-rewards' );
    ?></p>
				<?php 
    ?>

				<p>
					<label>
						<?php 
    esc_html_e( 'Award renewal points:', 'simple-points-and-rewards' );
    ?>
						<br/>
						<select name="subscriptions_renewal_award_timing" style="margin: 0;">
							<option value="follow_order" <?php 
    selected( $award_timing, 'follow_order' );
    ?>><?php 
    esc_html_e( 'Match the "Place an Order" award timing', 'simple-points-and-rewards' );
    ?></option>
							<option value="payment_complete" <?php 
    selected( $award_timing, 'payment_complete' );
    ?>><?php 
    esc_html_e( 'When the renewal payment completes', 'simple-points-and-rewards' );
    ?></option>
							<option value="completed" <?php 
    selected( $award_timing, 'completed' );
    ?>><?php 
    esc_html_e( 'When the renewal order is completed', 'simple-points-and-rewards' );
    ?></option>
						</select>
					</label>
				</p>
				<p class="description"><?php 
    esc_html_e( 'Renewal orders are created in the background and never reach the order "thank you" page, so with the "Match" option an immediate ("Order is placed") timing awards renewal points as soon as the renewal payment completes, and a "Completed" timing awards them when the renewal reaches your configured completed status.', 'simple-points-and-rewards' );
    ?></p>
			</div>
		</div>

		<div class="spar-settings-section">
			<div class="spar-settings-section-header">
				<h4><?php 
    esc_html_e( 'Initial Subscription Orders', 'simple-points-and-rewards' );
    ?></h4>
				<p><?php 
    esc_html_e( 'Points earned when a customer first purchases a subscription at checkout.', 'simple-points-and-rewards' );
    ?></p>
			</div>
			<p>
				<label>
					<div class="spar-toggle-switch"><input type="checkbox" name="subscriptions_signup_fee_include" <?php 
    checked( $include_signup_fee );
    ?> /><span class="spar-toggle-slider"></span></div>
					<?php 
    esc_html_e( 'Include sign-up fees in points calculations', 'simple-points-and-rewards' );
    ?>
				</label>
			</p>
			<p class="description"><?php 
    esc_html_e( 'When disabled, the sign-up fee portion of an initial subscription order is excluded from spend-based points, on both the order itself and cart/checkout previews.', 'simple-points-and-rewards' );
    ?></p>
			<p>
				<label>
					<div class="spar-toggle-switch"><input type="checkbox" name="subscriptions_exclude_products" <?php 
    checked( $exclude_products );
    ?> /><span class="spar-toggle-slider"></span></div>
					<?php 
    esc_html_e( 'Exclude subscription products from earning points', 'simple-points-and-rewards' );
    ?>
				</label>
			</p>
			<p class="description"><?php 
    esc_html_e( 'Removes subscription product line items (including their sign-up fees) from spend-based points on initial orders, cart/checkout previews and product page points messages. Renewal orders are controlled by the Renewal Orders settings above instead.', 'simple-points-and-rewards' );
    ?></p>
		</div>

		<div class="spar-settings-section">
			<div class="spar-settings-section-header">
				<h4><?php 
    esc_html_e( 'Order Bonuses', 'simple-points-and-rewards' );
    ?></h4>
				<p><?php 
    esc_html_e( 'How renewal orders interact with order-count earning methods.', 'simple-points-and-rewards' );
    ?></p>
			</div>
			<p>
				<label>
					<div class="spar-toggle-switch"><input type="checkbox" name="subscriptions_count_order_bonuses" <?php 
    checked( $count_order_bonuses );
    ?> /><span class="spar-toggle-slider"></span></div>
					<?php 
    esc_html_e( 'Count renewal orders toward the "Bonus after X Orders" milestone', 'simple-points-and-rewards' );
    ?>
				</label>
			</p>
			<p class="description"><?php 
    esc_html_e( 'When disabled, only orders placed at checkout count toward the milestone, so subscribers cannot reach it through automatic renewals alone. The First Order Bonus is never triggered by renewal orders.', 'simple-points-and-rewards' );
    ?></p>
			<?php 
    ?>
			<p class="description"><?php 
    esc_html_e( 'Note: the "Bonus after X Orders" earning method is a PRO feature, so this setting takes effect when PRO is active.', 'simple-points-and-rewards' );
    ?></p>
			<?php 
    ?>
		</div>

	</div>

	<script>
	( function() {
		function toggleDisplay( element, show ) {
			if ( element ) {
				element.style.display = show ? '' : 'none';
			}
		}
		function refreshSubscriptionRows() {
			var enabledInput = document.querySelector( 'input[name="subscriptions_renewal_enabled"]' );
			if ( ! enabledInput ) {
				return;
			}
			toggleDisplay( document.querySelector( '.spar-subscriptions-renewal-settings' ), enabledInput.checked );
			var modeSelect = document.querySelector( 'select[name="subscriptions_renewal_rate_mode"]' );
			if ( modeSelect ) {
				toggleDisplay( document.querySelector( '.spar-subscriptions-percentage-row' ), 'percentage' === modeSelect.value );
				toggleDisplay( document.querySelector( '.spar-subscriptions-fixed-row' ), 'fixed' === modeSelect.value );
			}
		}
		document.addEventListener( 'change', function( event ) {
			if ( ! event.target || ! event.target.name ) {
				return;
			}
			if ( 'subscriptions_renewal_enabled' === event.target.name || 'subscriptions_renewal_rate_mode' === event.target.name ) {
				refreshSubscriptionRows();
			}
		} );
		refreshSubscriptionRows();
	} )();
	</script>
	<?php 
}
