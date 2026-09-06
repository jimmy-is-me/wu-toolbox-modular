<?php

/**
 * Earn Points Settings Tab
 */
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
function spar_settings_tab_earn() {
    $defaults = spar_settings_default();
    $options = get_option( 'spar_options', $defaults );
    // Merge with defaults and ensure earn options exist
    $options = array_merge( $defaults, $options );
    if ( isset( $options['earn'] ) && isset( $defaults['earn'] ) ) {
        foreach ( $defaults['earn'] as $earn_key => $earn_defaults ) {
            if ( isset( $options['earn'][$earn_key] ) ) {
                $options['earn'][$earn_key] = array_merge( $earn_defaults, $options['earn'][$earn_key] );
            } else {
                $options['earn'][$earn_key] = $earn_defaults;
            }
        }
    } else {
        $options['earn'] = $defaults['earn'];
    }
    $earn_options = $options['earn'];
    $points_label = $options['points_label'];
    $rewards_label = $options['rewards_label'] ?? esc_html__( 'Rewards', 'simple-points-and-rewards' );
    // Use direct Freemius check inline in template
    ?>
	<h3><?php 
    /* translators: %s: points label (lowercase) */
    printf( esc_html__( 'Ways to Earn %s', 'simple-points-and-rewards' ), esc_html( strtolower( $points_label ) ) );
    ?></h3>
	
	<p><?php 
    /* translators: %s: points label (lowercase) */
    printf( esc_html__( 'Users can earn %s by completing the following actions. Enable, re-order and customise them as needed.', 'simple-points-and-rewards' ), esc_html( strtolower( $points_label ) ) );
    ?></p>

	<div class="spar-settings-section spar-settings-section--list">
	<div id="spar-earn-accordion-list">

    <!-- Signup Bonus -->
	<div class="spar-accordion spar-earn-accordion" data-key="signup">
		<div class="spar-accordion-header">
			<span class="spar-drag-handle dashicons dashicons-move" title="<?php 
    esc_attr_e( 'Drag to reorder', 'simple-points-and-rewards' );
    ?>"></span>
			<input type="hidden" name="earn_ways_order[]" value="signup" />
			<label>
				<div class="spar-toggle-switch">
					<input type="checkbox" name="signup_enabled" <?php 
    checked( !empty( $earn_options['signup']['enabled'] ) );
    ?> />
					<span class="spar-toggle-slider"></span>
				</div>
				<span class="spar-earn-type-badge"><i class="fa-solid fa-user-plus" aria-hidden="true"></i></span>
				<span class="spar-earn-head-text">
					<span class="spar-earn-head-title"><?php 
    esc_html_e( 'Signup Bonus', 'simple-points-and-rewards' );
    ?></span>
					<span class="spar-earn-head-desc"><?php 
    esc_html_e( 'Points awarded when a user signs up.', 'simple-points-and-rewards' );
    ?></span>
				</span>
			</label>
			<div class="spar-earn-actions">
				<button type="button" class="button spar-toggle-earn"><?php 
    esc_html_e( 'Edit', 'simple-points-and-rewards' );
    ?></button>
			</div>
		</div>
		<div class="spar-accordion-body spar-earn-body">
			<p class="spar-earn-body__intro">
				<?php 
    esc_html_e( 'Points awarded when a user signs up.', 'simple-points-and-rewards' );
    ?>
				<?php 
    esc_html_e( 'This will be granted to existing users too, the first time they visit the rewards page after enabling this option.', 'simple-points-and-rewards' );
    ?>
			</p>

			<div class="spar-earn-columns">

			<!-- Section: Bonus details -->
			<div class="spar-earn-section">
				<h4 class="spar-earn-section__title"><?php 
    esc_html_e( 'Bonus details', 'simple-points-and-rewards' );
    ?></h4>
				<!-- Name of way to earn -->
				<label class="spar-inline-block spar-label-title"><?php 
    esc_html_e( 'Name:', 'simple-points-and-rewards' );
    ?></label>
				<input type="text" name="signup_name" value="<?php 
    echo esc_attr( $earn_options['signup']['name'] );
    ?>" placeholder="<?php 
    esc_attr_e( 'Signup Bonus', 'simple-points-and-rewards' );
    ?>" />
				<br/>
				<!-- Amount of points -->
				<label class="spar-inline-block spar-label-title"><?php 
    esc_html_e( 'Points:', 'simple-points-and-rewards' );
    ?></label>
				<input type="number" name="signup_points" value="<?php 
    echo esc_attr( $earn_options['signup']['points'] );
    ?>" />
			</div>

			<!-- Section: Display options -->
			<div class="spar-earn-section">
				<h4 class="spar-earn-section__title"><?php 
    esc_html_e( 'Display options', 'simple-points-and-rewards' );
    ?></h4>
				<!-- Show guest signup message -->
				<label class="spar-inline-block">
					<input type="checkbox" name="signup_show_guest_message" <?php 
    checked( !empty( $earn_options['signup']['show_guest_message'] ) );
    ?> />
					<?php 
    esc_html_e( '(Logged out users) Show signup bonus points message', 'simple-points-and-rewards' );
    ?>
					<br/><i style="font-size: 12px;"><?php 
    esc_html_e( 'This message appears on the rewards page to inform users about the signup bonus they will receive upon registering.', 'simple-points-and-rewards' );
    ?></i>
				</label>
				<!-- Hide after rewarded -->
				<label class="spar-inline-block" style="margin-top:8px;">
					<input type="checkbox" name="signup_hide_after_rewarded" <?php 
    checked( !empty( $earn_options['signup']['hide_after_rewarded'] ) );
    ?> />
					<?php 
    esc_html_e( 'Hide from the ways to earn list once rewarded', 'simple-points-and-rewards' );
    ?>
					<br/><i style="font-size: 12px;"><?php 
    esc_html_e( 'When enabled, the Signup Bonus will be hidden from the ways to earn list for users who have already received it.', 'simple-points-and-rewards' );
    ?></i>
				</label>
			</div>

			</div><!-- /.spar-earn-columns -->
		</div>
	</div>

		<!-- Place an Order -->
	<div class="spar-accordion spar-earn-accordion" data-key="order">
		<div class="spar-accordion-header">
			<span class="spar-drag-handle dashicons dashicons-move" title="<?php 
    esc_attr_e( 'Drag to reorder', 'simple-points-and-rewards' );
    ?>"></span>
			<input type="hidden" name="earn_ways_order[]" value="order" />
			<label>
				<div class="spar-toggle-switch">
					<input type="checkbox" name="order_enabled" <?php 
    checked( !empty( $earn_options['order']['enabled'] ) );
    ?> />
					<span class="spar-toggle-slider"></span>
				</div>
				<span class="spar-earn-type-badge"><i class="fa-solid fa-bag-shopping" aria-hidden="true"></i></span>
				<span class="spar-earn-head-text">
					<span class="spar-earn-head-title"><?php 
    esc_html_e( 'Points for Spending', 'simple-points-and-rewards' );
    ?></span>
					<span class="spar-earn-head-desc"><?php 
    esc_html_e( 'Points awarded based on the exact amount spent.', 'simple-points-and-rewards' );
    ?></span>
				</span>
			</label>
			<div class="spar-earn-actions">
				<button type="button" class="button spar-toggle-earn"><?php 
    esc_html_e( 'Edit', 'simple-points-and-rewards' );
    ?></button>
			</div>
		</div>
		<div class="spar-accordion-body spar-earn-body">
			<p class="spar-earn-body__intro">
				<?php 
    esc_html_e( 'Points awarded for spending based on the exact amount spent.', 'simple-points-and-rewards' );
    ?>
			</p>

			<!-- Section: Display name -->
			<div class="spar-earn-section spar-earn-section--full">
				<h4 class="spar-earn-section__title"><?php 
    esc_html_e( 'Display name', 'simple-points-and-rewards' );
    ?></h4>
				<div class="spar-earn-field">
					<label class="spar-earn-field__label" for="spar-order-name"><?php 
    esc_html_e( 'Name shown to customers', 'simple-points-and-rewards' );
    ?></label>
					<input id="spar-order-name" type="text" name="order_name" value="<?php 
    echo esc_attr( $earn_options['order']['name'] );
    ?>" placeholder="<?php 
    esc_attr_e( 'Points for Spending', 'simple-points-and-rewards' );
    ?>" />
				</div>
			</div>

			<!-- Section: Earning rate -->
			<div class="spar-earn-section spar-earn-section--full">
				<h4 class="spar-earn-section__title"><?php 
    esc_html_e( 'Points earning rate', 'simple-points-and-rewards' );
    ?></h4>
			<?php 
    // Default currency context
    $store_currency = ( function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD' );
    $store_currency_name = ( function_exists( 'get_woocommerce_currencies' ) ? get_woocommerce_currencies()[$store_currency] ?? $store_currency : $store_currency );
    $store_symbol = ( function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol( $store_currency ) : '$' );
    $points_per_points = ( isset( $earn_options['order']['points_per_points'] ) ? (float) $earn_options['order']['points_per_points'] : (float) ($earn_options['order']['points_per'] ?? 0) );
    $points_per_amount = ( isset( $earn_options['order']['points_per_amount'] ) && (float) $earn_options['order']['points_per_amount'] > 0 ? (float) $earn_options['order']['points_per_amount'] : 1.0 );
    $order_calc_mode = $earn_options['order']['calculation_total_mode'] ?? 'subtotal_after_discount';
    $order_include_shipping = !empty( $earn_options['order']['calculation_include_shipping'] );
    $order_include_taxes = !empty( $earn_options['order']['calculation_include_taxes'] );
    $all_currencies = ( function_exists( 'get_woocommerce_currencies' ) ? get_woocommerce_currencies() : [] );
    $order_completed_status = ( isset( $earn_options['order']['completed_status'] ) ? sanitize_key( $earn_options['order']['completed_status'] ) : 'completed' );
    if ( empty( $order_completed_status ) ) {
        $order_completed_status = 'completed';
    }
    $core_statuses = [
        'wc-pending',
        'wc-processing',
        'wc-on-hold',
        'wc-completed',
        'wc-cancelled',
        'wc-refunded',
        'wc-failed'
    ];
    $completed_status_options = [];
    if ( function_exists( 'wc_get_order_statuses' ) ) {
        $all_statuses = wc_get_order_statuses();
        foreach ( $all_statuses as $status_key => $status_label ) {
            $status_slug = str_replace( 'wc-', '', $status_key );
            if ( 'checkout-draft' === $status_slug ) {
                continue;
            }
            if ( 'completed' === $status_slug || !in_array( $status_key, $core_statuses, true ) ) {
                $completed_status_options[$status_slug] = $status_label;
            }
        }
    }
    if ( empty( $completed_status_options ) ) {
        $completed_status_options = [
            'completed' => esc_html__( 'Completed', 'simple-points-and-rewards' ),
        ];
    }
    ?>
			<!-- Default currency rate: X points per Y amount (single row) -->
			<div class="spar-default-currency-rate spar-flex-center-gap8 spar-mb-10">
				<label class="spar-inline-block"><?php 
    esc_html_e( 'Currency:', 'simple-points-and-rewards' );
    ?></label>
				<select class="spar-w-200" disabled="disabled">
					<option value="<?php 
    echo esc_attr( $store_currency );
    ?>"><?php 
    echo esc_html( $store_currency_name . ' (' . $store_currency . ')' );
    ?></option>
				</select>
				<label class="spar-inline-block"><?php 
    esc_html_e( 'Number of points', 'simple-points-and-rewards' );
    ?>:</label>
				<input class="spar-w-150" type="number" step="0.1" min="0" name="order_points_per_points" value="<?php 
    echo esc_attr( $points_per_points );
    ?>" />
				<label class="spar-inline-block spar-earned-per-label" data-role="default"><?php 
    printf( esc_html__( 'Earned per %s spent', 'simple-points-and-rewards' ), esc_html( $store_currency ) );
    ?>:</label>
				<input id="spar-order-default-amount" class="spar-w-150" type="number" step="0.01" min="0.01" name="order_points_per_amount" value="<?php 
    echo esc_attr( $points_per_amount );
    ?>" />
			</div>

			<?php 
    ?>
			<button type="button" 
				style="margin: 5px 0 10px 0;"
				class="button spar-add-currency-rate" disabled="disabled"><?php 
    esc_html_e( 'Add New Currency', 'simple-points-and-rewards' );
    ?> (PRO)</button>
			<?php 
    ?>
			</div><!-- /Earning rate section -->

			<div class="spar-earn-columns">

			<!-- Section: Calculation -->
			<div class="spar-earn-section">
				<h4 class="spar-earn-section__title"><?php 
    esc_html_e( 'Calculation', 'simple-points-and-rewards' );
    ?></h4>
				<div class="spar-earn-field">
					<label class="spar-earn-field__label" for="spar-order-calc-mode"><?php 
    esc_html_e( 'Base points on', 'simple-points-and-rewards' );
    ?></label>
					<select id="spar-order-calc-mode" name="order_calculation_total_mode">
						<option value="total_before_discounts" <?php 
    selected( $order_calc_mode, 'total_before_discounts' );
    ?>>
							<?php 
    esc_html_e( 'Total before discounts', 'simple-points-and-rewards' );
    ?>
						</option>
						<option value="subtotal_after_discount" <?php 
    selected( $order_calc_mode, 'subtotal_after_discount' );
    ?>>
							<?php 
    esc_html_e( 'Subtotal after discount (default)', 'simple-points-and-rewards' );
    ?>
						</option>
					</select>
				</div>
				<div class="spar-earn-field">
					<label class="spar-earn-check">
						<input type="checkbox" name="order_calculation_include_shipping" <?php 
    checked( $order_include_shipping );
    ?> />
						<span><?php 
    esc_html_e( 'Include shipping in calculation total', 'simple-points-and-rewards' );
    ?></span>
					</label>
					<label class="spar-earn-check">
						<input type="checkbox" name="order_calculation_include_taxes" <?php 
    checked( $order_include_taxes );
    ?> />
						<span><?php 
    esc_html_e( 'Include taxes in calculation total', 'simple-points-and-rewards' );
    ?></span>
					</label>
				</div>
			</div><!-- /Calculation section -->

			<?php 
    $order_min_spend = ( isset( $earn_options['order']['min_spend'] ) ? (float) $earn_options['order']['min_spend'] : 0 );
    $order_max_points = ( isset( $earn_options['order']['max_points'] ) ? (int) $earn_options['order']['max_points'] : 0 );
    $order_max_percent = ( isset( $earn_options['order']['max_percent'] ) ? (float) $earn_options['order']['max_percent'] : 0 );
    $order_exclude_sale = !empty( $earn_options['order']['exclude_sale_items'] );
    ?>
			<!-- Section: Limits -->
			<div class="spar-earn-section">
				<h4 class="spar-earn-section__title"><?php 
    esc_html_e( 'Limits', 'simple-points-and-rewards' );
    ?></h4>
				<div class="spar-earn-field">
					<label class="spar-earn-field__label" for="spar-order-min-spend"><?php 
    esc_html_e( 'Minimum spend to earn', 'simple-points-and-rewards' );
    ?><span class="spar-help-tip" tabindex="0" role="img" aria-label="<?php 
    echo esc_attr__( 'Only award points when the qualifying order total is at least this amount. Set to 0 to always award points.', 'simple-points-and-rewards' );
    ?>" data-spar-tip="<?php 
    echo esc_attr__( 'Only award points when the qualifying order total is at least this amount. Set to 0 to always award points.', 'simple-points-and-rewards' );
    ?>"></span></label>
					<div class="spar-earn-input-affix">
						<span class="spar-earn-input-affix__prefix"><?php 
    echo esc_html( $store_symbol );
    ?></span>
						<input id="spar-order-min-spend" class="spar-w-150" type="number" step="0.01" min="0" name="order_min_spend" value="<?php 
    echo esc_attr( $order_min_spend );
    ?>" />
					</div>
				</div>
				<div class="spar-earn-field">
					<label class="spar-earn-field__label" for="spar-order-max-percent"><?php 
    esc_html_e( 'Maximum percentage of total', 'simple-points-and-rewards' );
    ?><span class="spar-help-tip" tabindex="0" role="img" aria-label="<?php 
    echo esc_attr__( 'Never award points worth more than this percentage of the order total (using your redemption rate). Only applies to points earned from spending; fixed points and bonuses are not affected. Set to 0 for no percentage cap.', 'simple-points-and-rewards' );
    ?>" data-spar-tip="<?php 
    echo esc_attr__( 'Never award points worth more than this percentage of the order total (using your redemption rate). Only applies to points earned from spending; fixed points and bonuses are not affected. Set to 0 for no percentage cap.', 'simple-points-and-rewards' );
    ?>"></span></label>
					<div class="spar-earn-input-affix">
						<input id="spar-order-max-percent" class="spar-w-150" type="number" step="0.01" min="0" max="100" name="order_max_percent" value="<?php 
    echo esc_attr( $order_max_percent );
    ?>" />
						<span class="spar-earn-input-affix__prefix">%</span>
					</div>
				</div>
				<div class="spar-earn-field">
					<label class="spar-earn-field__label" for="spar-order-max-points"><?php 
    esc_html_e( 'Maximum points per order', 'simple-points-and-rewards' );
    ?><span class="spar-help-tip" tabindex="0" role="img" aria-label="<?php 
    echo esc_attr__( 'Never award more than this many points from spending on a single order. Only applies to points earned from spending; fixed points and bonuses are not affected. Set to 0 for unlimited.', 'simple-points-and-rewards' );
    ?>" data-spar-tip="<?php 
    echo esc_attr__( 'Never award more than this many points from spending on a single order. Only applies to points earned from spending; fixed points and bonuses are not affected. Set to 0 for unlimited.', 'simple-points-and-rewards' );
    ?>"></span></label>
					<input id="spar-order-max-points" class="spar-w-150" type="number" step="1" min="0" name="order_max_points" value="<?php 
    echo esc_attr( $order_max_points );
    ?>" />
				</div>
				<div class="spar-earn-field">
					<label class="spar-earn-check">
						<input type="checkbox" name="order_exclude_sale_items" <?php 
    checked( $order_exclude_sale );
    ?> />
						<span><?php 
    esc_html_e( 'Exclude on-sale / discounted products', 'simple-points-and-rewards' );
    ?></span>
						<span class="spar-help-tip" tabindex="0" role="img" aria-label="<?php 
    echo esc_attr__( 'When enabled, items purchased on sale do not count towards points earned.', 'simple-points-and-rewards' );
    ?>" data-spar-tip="<?php 
    echo esc_attr__( 'When enabled, items purchased on sale do not count towards points earned.', 'simple-points-and-rewards' );
    ?>"></span>
					</label>
				</div>
			</div><!-- /Limits section -->

			<!-- Section: Timing -->
			<div class="spar-earn-section">
				<h4 class="spar-earn-section__title"><?php 
    esc_html_e( 'Award timing', 'simple-points-and-rewards' );
    ?></h4>
				<div class="spar-earn-field">
					<label class="spar-earn-field__label" for="spar-order-award-timing"><?php 
    esc_html_e( 'Award points', 'simple-points-and-rewards' );
    ?></label>
					<select id="spar-order-award-timing" name="order_award_timing">
						<option value="thankyou" <?php 
    selected( $earn_options['order']['award_timing'], 'thankyou' );
    ?>><?php 
    esc_html_e( 'When order is placed', 'simple-points-and-rewards' );
    ?></option>
						<option value="completed" <?php 
    selected( $earn_options['order']['award_timing'], 'completed' );
    ?>><?php 
    esc_html_e( 'When order is completed', 'simple-points-and-rewards' );
    ?></option>
					</select>
				</div>
				<div class="spar-earn-field">
					<label class="spar-earn-field__label" for="spar-order-completed-status"><?php 
    esc_html_e( 'Completed status', 'simple-points-and-rewards' );
    ?></label>
					<select id="spar-order-completed-status" name="order_completed_status">
						<?php 
    foreach ( $completed_status_options as $status_slug => $status_label ) {
        ?>
							<option value="<?php 
        echo esc_attr( $status_slug );
        ?>" <?php 
        selected( $order_completed_status, $status_slug );
        ?>><?php 
        echo esc_html( $status_label );
        ?></option>
						<?php 
    }
    ?>
					</select>
					<p class="spar-earn-field__hint"><?php 
    esc_html_e( 'Used when awarding points after completion.', 'simple-points-and-rewards' );
    ?></p>
				</div>
			</div><!-- /Timing section -->

			<!-- Section: Refunds & redemption -->
			<div class="spar-earn-section">
				<h4 class="spar-earn-section__title"><?php 
    esc_html_e( 'Refunds &amp; redemption', 'simple-points-and-rewards' );
    ?></h4>
				<div class="spar-earn-field">
					<label class="spar-earn-check">
						<input type="checkbox" name="order_deduct_on_refund" <?php 
    checked( !empty( $earn_options['order']['deduct_on_refund'] ) );
    ?> />
						<span><?php 
    esc_html_e( 'Deduct points when order is refunded or cancelled', 'simple-points-and-rewards' );
    ?></span>
					</label>
				</div>
				<div class="spar-earn-field">
					<label class="spar-earn-check">
						<input type="checkbox" name="order_skip_if_redeemed" <?php 
    checked( !empty( $earn_options['order']['skip_if_redeemed'] ) );
    ?> />
						<span><?php 
    esc_html_e( 'Do not award ANY points on orders where points were redeemed at checkout', 'simple-points-and-rewards' );
    ?></span>
					</label>
					<p class="spar-earn-field__hint"><?php 
    esc_html_e( 'When disabled, points are still awarded on the remaining non-redeemed order balance.', 'simple-points-and-rewards' );
    ?></p>
				</div>
			</div><!-- /Refunds & redemption section -->

			<!-- Section: Rounding -->
			<div class="spar-earn-section">
				<h4 class="spar-earn-section__title"><?php 
    esc_html_e( 'Rounding', 'simple-points-and-rewards' );
    ?></h4>
				<div class="spar-earn-field">
					<label class="spar-earn-field__label" for="spar-order-rounding"><?php 
    esc_html_e( 'Points rounding', 'simple-points-and-rewards' );
    ?></label>
					<select id="spar-order-rounding" name="order_rounding_mode">
						<option value="floor" <?php 
    selected( $earn_options['order']['rounding_mode'] ?? 'floor', 'floor' );
    ?>><?php 
    esc_html_e( 'Round down (default)', 'simple-points-and-rewards' );
    ?></option>
						<option value="round" <?php 
    selected( $earn_options['order']['rounding_mode'] ?? 'floor', 'round' );
    ?>><?php 
    esc_html_e( 'Round to nearest', 'simple-points-and-rewards' );
    ?></option>
						<option value="ceil" <?php 
    selected( $earn_options['order']['rounding_mode'] ?? 'floor', 'ceil' );
    ?>><?php 
    esc_html_e( 'Round up', 'simple-points-and-rewards' );
    ?></option>
					</select>
				</div>
			</div><!-- /Rounding section -->
			</div><!-- /.spar-earn-columns -->
		</div>
	</div>

	<!-- Points for Orders (Fixed, with Tiers) -->
	<div class="spar-accordion spar-earn-accordion" data-key="order_fixed">
		<div class="spar-accordion-header">
			<span class="spar-drag-handle dashicons dashicons-move" title="<?php 
    esc_attr_e( 'Drag to reorder', 'simple-points-and-rewards' );
    ?>"></span>
			<input type="hidden" name="earn_ways_order[]" value="order_fixed" />
			<label>
				<div class="spar-toggle-switch">
					<input type="checkbox" name="order_fixed_enabled" <?php 
    checked( !empty( $earn_options['order_fixed']['enabled'] ) );
    ?> />
					<span class="spar-toggle-slider"></span>
				</div>
				<span class="spar-earn-type-badge"><i class="fa-solid fa-tags" aria-hidden="true"></i></span>
				<span class="spar-earn-head-text">
					<span class="spar-earn-head-title"><?php 
    esc_html_e( 'Points for Orders', 'simple-points-and-rewards' );
    ?></span>
					<span class="spar-earn-head-desc"><?php 
    esc_html_e( 'Fixed points when spend reaches a tier threshold.', 'simple-points-and-rewards' );
    ?></span>
				</span>
			</label>
			<div class="spar-earn-actions">
				<button type="button" class="button spar-toggle-earn"><?php 
    esc_html_e( 'Edit', 'simple-points-and-rewards' );
    ?></button>
			</div>
		</div>
		<div class="spar-accordion-body spar-earn-body">
			<p class="spar-earn-body__intro">
				<?php 
    esc_html_e( 'Offer a fixed amount of points for an order when the spend reaches a tier threshold.', 'simple-points-and-rewards' );
    ?>
				<?php 
    esc_html_e( 'This will sell an upsell to customers to spend more to reach the next tier and earn more points!', 'simple-points-and-rewards' );
    ?>
			</p>
			<?php 
    $store_currency = ( function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD' );
    $store_currency_name = ( function_exists( 'get_woocommerce_currencies' ) ? get_woocommerce_currencies()[$store_currency] ?? $store_currency : $store_currency );
    $store_symbol = ( function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol( $store_currency ) : '$' );
    $all_currencies = ( function_exists( 'get_woocommerce_currencies' ) ? get_woocommerce_currencies() : [] );
    $tiers = ( isset( $earn_options['order_fixed']['tiers'] ) && is_array( $earn_options['order_fixed']['tiers'] ) ? $earn_options['order_fixed']['tiers'] : [] );
    $order_fixed_calc_mode = $earn_options['order_fixed']['calculation_total_mode'] ?? 'subtotal_after_discount';
    $order_fixed_include_shipping = !empty( $earn_options['order_fixed']['calculation_include_shipping'] );
    $order_fixed_include_taxes = !empty( $earn_options['order_fixed']['calculation_include_taxes'] );
    ?>

			<!-- Section: Details -->
			<div class="spar-earn-section spar-earn-section--full">
				<h4 class="spar-earn-section__title"><?php 
    esc_html_e( 'Details', 'simple-points-and-rewards' );
    ?></h4>
				<!-- Name of way to earn -->
				<label class="spar-inline-block spar-label-title"><?php 
    esc_html_e( 'Name:', 'simple-points-and-rewards' );
    ?></label>
				<input type="text" name="order_fixed_name" value="<?php 
    echo esc_attr( $earn_options['order_fixed']['name'] ?? '' );
    ?>" placeholder="<?php 
    esc_attr_e( 'Points for Orders', 'simple-points-and-rewards' );
    ?>" />
			</div>

			<!-- Section: Tiers -->
			<div class="spar-earn-section spar-earn-section--full">
				<h4 class="spar-earn-section__title"><?php 
    esc_html_e( 'Define tiers', 'simple-points-and-rewards' );
    ?></h4>
			<div id="spar-order-fixed-tiers">
				<?php 
    if ( empty( $tiers ) ) {
        $tiers = [[
            'points'            => 10,
            'default_threshold' => 0,
            'currencies'        => [],
        ]];
    }
    foreach ( $tiers as $i => $tier ) {
        $points = ( isset( $tier['points'] ) ? (int) $tier['points'] : 10 );
        $default_threshold = ( isset( $tier['default_threshold'] ) ? (float) $tier['default_threshold'] : 0.0 );
        $currs = ( isset( $tier['currencies'] ) && is_array( $tier['currencies'] ) ? $tier['currencies'] : [] );
        ?>
				<div class="spar-order-tier" data-tier-index="<?php 
        echo esc_attr( (int) $i );
        ?>" style="border:1px solid #ddd; padding:10px; margin:10px 0; border-radius:4px;">
					<div class="spar-flex-center-gap8" style="justify-content:space-between; align-items:center; margin-bottom:8px;">
						<strong><?php 
        /* translators: %d: tier index */
        printf( esc_html__( 'Tier %d', 'simple-points-and-rewards' ), (int) $i + 1 );
        ?></strong>
						<?php 
        if ( $i > 0 ) {
            ?>
							<button type="button" class="button button-secondary spar-remove-order-tier">&times;</button>
						<?php 
        }
        ?>
					</div>

					<div class="spar-flex-center-gap8" style="flex-wrap:wrap; gap:8px;">
						<label class="spar-inline-block"><?php 
        esc_html_e( 'Points Awarded:', 'simple-points-and-rewards' );
        ?></label>
						<input class="spar-w-150" type="number" min="1" name="order_fixed_tiers[<?php 
        echo esc_attr( (int) $i );
        ?>][points]" value="<?php 
        echo esc_attr( $points );
        ?>" />
						<label class="spar-inline-block"><?php 
        /* translators: 1: currency name 2: code */
        printf( esc_html__( 'Spend threshold %1$s:', 'simple-points-and-rewards' ), esc_html( '(' . $store_currency . ')' ) );
        ?></label>
						<input class="spar-w-150" type="number" step="0.01" min="0.01" name="order_fixed_tiers[<?php 
        echo esc_attr( (int) $i );
        ?>][default_threshold]" value="<?php 
        echo esc_attr( $default_threshold );
        ?>" />
					</div>

					<?php 
        ?>
						<button type="button" class="button spar-add-tier-currency" disabled="disabled" style="margin:4px 0 10px 0;"><?php 
        esc_html_e( 'Add New Currency', 'simple-points-and-rewards' );
        ?> (PRO)</button>
					<?php 
        ?>
				</div>
				<?php 
    }
    ?>
			</div>

			<button type="button" class="button button-primary spar-add-order-tier" style="margin-top:6px;">+ <?php 
    esc_html_e( 'Add New Tier', 'simple-points-and-rewards' );
    ?></button>
			</div><!-- /.spar-earn-section (Tiers) -->

			<div class="spar-earn-columns">

			<!-- Section: Calculation -->
			<div class="spar-earn-section">
				<h4 class="spar-earn-section__title"><?php 
    esc_html_e( 'Calculation', 'simple-points-and-rewards' );
    ?></h4>
				<label class="spar-inline-block spar-label-title">
					<?php 
    esc_html_e( 'Points calculation uses:', 'simple-points-and-rewards' );
    ?>
				</label>
				<select name="order_fixed_calculation_total_mode">
					<option value="total_before_discounts" <?php 
    selected( $order_fixed_calc_mode, 'total_before_discounts' );
    ?>>
						<?php 
    esc_html_e( 'Total before discounts', 'simple-points-and-rewards' );
    ?>
					</option>
					<option value="subtotal_after_discount" <?php 
    selected( $order_fixed_calc_mode, 'subtotal_after_discount' );
    ?>>
						<?php 
    esc_html_e( 'Subtotal after discount (default)', 'simple-points-and-rewards' );
    ?>
					</option>
				</select>
				<br/>
				<label class="spar-inline-block" style="margin-top:6px;">
					<input type="checkbox" name="order_fixed_calculation_include_shipping" <?php 
    checked( $order_fixed_include_shipping );
    ?> />
					<?php 
    esc_html_e( 'Include shipping in calculation total', 'simple-points-and-rewards' );
    ?>
				</label>
				<label class="spar-inline-block" style="margin-top:6px;">
					<input type="checkbox" name="order_fixed_calculation_include_taxes" <?php 
    checked( $order_fixed_include_taxes );
    ?> />
					<?php 
    esc_html_e( 'Include taxes in calculation total', 'simple-points-and-rewards' );
    ?>
				</label>
			</div>

			<!-- Section: Refunds -->
			<div class="spar-earn-section">
				<h4 class="spar-earn-section__title"><?php 
    esc_html_e( 'Order refunds', 'simple-points-and-rewards' );
    ?></h4>
				<label class="spar-inline-block">
					<input type="checkbox" name="order_fixed_deduct_on_refund" <?php 
    checked( !empty( $earn_options['order_fixed']['deduct_on_refund'] ) );
    ?> />
					<?php 
    esc_html_e( 'Deduct points when order is refunded or cancelled', 'simple-points-and-rewards' );
    ?>
				</label>
			</div>

			</div><!-- /.spar-earn-columns -->

			<!-- Templates (use script tags to avoid form submission of placeholders) -->
			<script type="text/template" id="spar-order-tier-template">
				<div class="spar-order-tier" data-tier-index="__TIER_INDEX__" style="border:1px solid #ddd; padding:10px; margin:10px 0; border-radius:4px;">
					<div class="spar-flex-center-gap8" style="justify-content:space-between; align-items:center; margin-bottom:8px;">
						<strong><?php 
    esc_html_e( 'Tier', 'simple-points-and-rewards' );
    ?> __TIER_NUMBER__</strong>
						<button type="button" class="button button-secondary spar-remove-order-tier">&times;</button>
					</div>
					<div class="spar-flex-center-gap8" style="flex-wrap:wrap; gap:8px;">
						<label class="spar-inline-block"><?php 
    esc_html_e( 'Points Awarded:', 'simple-points-and-rewards' );
    ?></label>
						<input class="spar-w-150" type="number" min="1" name="order_fixed_tiers[__TIER_INDEX__][points]" value="10" />
						<label class="spar-inline-block"><?php 
    /* translators: %s: currency code */
    printf( esc_html__( 'Spend threshold (%s):', 'simple-points-and-rewards' ), esc_html( $store_currency ) );
    ?></label>
						<input class="spar-w-150" type="number" step="0.01" min="0.01" name="order_fixed_tiers[__TIER_INDEX__][default_threshold]" value="0" />
					</div>
					<?php 
    ?>
						<button type="button" class="button spar-add-tier-currency" disabled="disabled" style="margin:4px 0 10px 0;"><?php 
    esc_html_e( 'Add New Currency', 'simple-points-and-rewards' );
    ?> (PRO)</button>
					<?php 
    ?>
				</div>
			</script>

			<?php 
    ?>
		</div>
	</div>
	<!-- Referral System -->
	<div class="spar-accordion spar-earn-accordion" data-key="referral">
		<div class="spar-accordion-header">
			<span class="spar-drag-handle dashicons dashicons-move" title="<?php 
    esc_attr_e( 'Drag to reorder', 'simple-points-and-rewards' );
    ?>"></span>
			<input type="hidden" name="earn_ways_order[]" value="referral" />
			<label>
				<div class="spar-toggle-switch">
					<input type="checkbox" name="referral_enabled" <?php 
    checked( !empty( $earn_options['referral']['enabled'] ) );
    ?> />
					<span class="spar-toggle-slider"></span>
				</div>
				<span class="spar-earn-type-badge"><i class="fa-solid fa-user-group" aria-hidden="true"></i></span>
				<span class="spar-earn-head-text">
					<span class="spar-earn-head-title"><?php 
    esc_html_e( 'Referral System', 'simple-points-and-rewards' );
    ?></span>
					<span class="spar-earn-head-desc"><?php 
    esc_html_e( 'Earn points when referred friends make purchases.', 'simple-points-and-rewards' );
    ?></span>
				</span>
			</label>
			<div class="spar-earn-actions">
				<button type="button" class="button spar-toggle-earn"><?php 
    esc_html_e( 'Edit', 'simple-points-and-rewards' );
    ?></button>
			</div>
		</div>
		<div class="spar-accordion-body spar-earn-body">
			<p class="spar-earn-body__intro">
				<?php 
    esc_html_e( 'Users earn points when their referred friends make purchases through their referral link.', 'simple-points-and-rewards' );
    ?>
			</p>

			<!-- Section: Referral bonus -->
			<div class="spar-earn-section spar-earn-section--full">
				<h4 class="spar-earn-section__title"><?php 
    esc_html_e( 'Referral bonus', 'simple-points-and-rewards' );
    ?></h4>
				<!-- Name of way to earn -->
				<label class="spar-inline-block spar-label-title"><?php 
    esc_html_e( 'Name:', 'simple-points-and-rewards' );
    ?></label>
				<input type="text" name="referral_name" value="<?php 
    echo esc_attr( $earn_options['referral']['name'] );
    ?>" placeholder="<?php 
    esc_attr_e( 'Referral Bonus', 'simple-points-and-rewards' );
    ?>" />
				<br/>
				<!-- Earning Type -->
				<label class="spar-inline-block spar-label-title"><?php 
    esc_html_e( 'Earning Type:', 'simple-points-and-rewards' );
    ?></label>
				<select name="referral_earning_type" id="referral_earning_type">
					<option value="fixed" <?php 
    selected( $earn_options['referral']['earning_type'] ?? 'fixed', 'fixed' );
    ?>><?php 
    esc_html_e( 'Fixed Amount per Referral', 'simple-points-and-rewards' );
    ?></option>
					<option value="percentage" <?php 
    selected( $earn_options['referral']['earning_type'] ?? 'fixed', 'percentage' );
    ?>><?php 
    esc_html_e( 'Percentage of Order Total', 'simple-points-and-rewards' );
    ?></option>
				</select>
				<br/>

				<!-- Fixed Amount Settings -->
				<div id="referral_fixed_settings" class="spar-hidden">
					<label class="spar-inline-block spar-label-title"><?php 
    esc_html_e( 'Fixed points per referral:', 'simple-points-and-rewards' );
    ?></label>
					<input type="number" style="margin: 0;"
					name="referral_fixed_points" value="<?php 
    echo esc_attr( $earn_options['referral']['fixed_points'] ?? 100 );
    ?>" />
					<small><?php 
    esc_html_e( 'Referrer will earn this exact amount of points for each successful referral, regardless of order value.', 'simple-points-and-rewards' );
    ?></small>
				</div>

				<div id="referral_percentage_settings" class="spar-hidden">
					<label class="spar-inline-block spar-label-title"><?php 
    esc_html_e( 'Percentage of order total:', 'simple-points-and-rewards' );
    ?></label>
					<input type="number" step="0.1" name="referral_percentage" value="<?php 
    echo esc_attr( $earn_options['referral']['percentage'] );
    ?>" />%
					<br/>
					<label class="spar-inline-block spar-label-title"><?php 
    esc_html_e( 'Points per $1.00 of referral value:', 'simple-points-and-rewards' );
    ?></label>
					<input type="number" step="0.1" name="referral_points_per" value="<?php 
    echo esc_attr( $earn_options['referral']['points_per'] );
    ?>" />
					<small><?php 
    esc_html_e( 'Example: If order is $100, percentage is 10%, and points per $1 is 5, referrer gets floor($10 × 5) = 50 points', 'simple-points-and-rewards' );
    ?></small>
				</div>
			</div>

			<div class="spar-earn-columns">

			<!-- Section: Attribution & rules -->
			<div class="spar-earn-section">
				<h4 class="spar-earn-section__title"><?php 
    esc_html_e( 'Attribution & Fraud Settings', 'simple-points-and-rewards' );
    ?></h4>
				<!-- Attribution Model -->
				<label class="spar-inline-block spar-label-title"><?php 
    esc_html_e( 'Attribution Model:', 'simple-points-and-rewards' );
    ?></label>
				<select name="referral_gift_attribution_model" style="margin: 0;">
					<option value="first_click" <?php 
    selected( $earn_options['referral']['gift_attribution_model'] ?? 'first_click', 'first_click' );
    ?>><?php 
    esc_html_e( 'First Click Attribution', 'simple-points-and-rewards' );
    ?></option>
					<option value="last_click" <?php 
    selected( $earn_options['referral']['gift_attribution_model'] ?? 'first_click', 'last_click' );
    ?>><?php 
    esc_html_e( 'Last Click Attribution', 'simple-points-and-rewards' );
    ?></option>
				</select>
				<small><?php 
    esc_html_e( 'First Click: The first referral link clicked gets credit. Last Click: The most recent referral link gets credit.', 'simple-points-and-rewards' );
    ?></small>

				<!-- Block Self Referral -->
				<label class="spar-inline-block">
					<input type="checkbox" name="referral_block_self_referral" <?php 
    checked( !empty( $earn_options['referral']['block_self_referral'] ) );
    ?> />
					<?php 
    esc_html_e( 'Block users from using their own referral link', 'simple-points-and-rewards' );
    ?>
				</label>
				<small><?php 
    esc_html_e( 'When enabled, users cannot earn points or receive gift offers by using their own referral links. Orders whose billing email matches the referrer\'s account or billing email are also blocked, so logging out or checking out as a guest cannot bypass this.', 'simple-points-and-rewards' );
    ?></small>

				<!-- New customers only -->
				<label class="spar-inline-block">
					<input type="checkbox" name="referral_new_customer_only" <?php 
    checked( !empty( $earn_options['referral']['new_customer_only'] ) );
    ?> />
					<?php 
    esc_html_e( 'Only award referral points for new customers', 'simple-points-and-rewards' );
    ?>
				</label>
				<small><?php 
    esc_html_e( 'Recommended. Only pay the referral bonus on the referred customer\'s first order, so a referred customer\'s repeat orders don\'t keep rewarding the referrer.', 'simple-points-and-rewards' );
    ?></small>

				<!-- Block same IP -->
				<label class="spar-inline-block">
					<input type="checkbox" name="referral_block_same_ip" <?php 
    checked( !empty( $earn_options['referral']['block_same_ip'] ) );
    ?> />
					<?php 
    esc_html_e( 'Block referral points when the order IP matches the referrer', 'simple-points-and-rewards' );
    ?>
				</label>
				<small><?php 
    esc_html_e( 'Skip the referral bonus when the referred order was placed from the same IP address as one of the referrer\'s own recent orders. Note: people sharing a household or office connection can legitimately refer each other, so enable this only if self-referral abuse is a problem for your store.', 'simple-points-and-rewards' );
    ?></small>

				<!-- Daily cap per referrer -->
				<label class="spar-inline-block spar-label-title"><?php 
    esc_html_e( 'Maximum referral bonuses per referrer per day:', 'simple-points-and-rewards' );
    ?></label>
				<input type="number" min="0" step="1" name="referral_referrer_daily_cap" value="<?php 
    echo esc_attr( $earn_options['referral']['referrer_daily_cap'] ?? 0 );
    ?>" />
				<small><?php 
    esc_html_e( 'Limits how many referred orders can earn a bonus for the same referrer each day. Set to 0 for no limit.', 'simple-points-and-rewards' );
    ?></small>
			</div>

			<!-- Section: Timing & refunds -->
			<div class="spar-earn-section">
				<h4 class="spar-earn-section__title"><?php 
    esc_html_e( 'Timing & refunds', 'simple-points-and-rewards' );
    ?></h4>
				<!-- When to award referral points -->
				<label class="spar-inline-block spar-label-title"><?php 
    esc_html_e( 'Award referral bonus:', 'simple-points-and-rewards' );
    ?></label>
				<select name="referral_award_timing">
					<option value="completed" <?php 
    selected( $earn_options['referral']['award_timing'] ?? 'completed', 'completed' );
    ?>><?php 
    esc_html_e( 'When order is completed', 'simple-points-and-rewards' );
    ?></option>
					<option value="processing" <?php 
    selected( $earn_options['referral']['award_timing'] ?? 'completed', 'processing' );
    ?>><?php 
    esc_html_e( 'When order is processing', 'simple-points-and-rewards' );
    ?></option>
				</select>
				<br/>
				<!-- Deduct referral points on refund -->
				<label class="spar-inline-block">
					<input type="checkbox" name="referral_deduct_on_refund" <?php 
    checked( !empty( $earn_options['referral']['deduct_on_refund'] ?? true ) );
    ?> />
					<?php 
    esc_html_e( 'Deduct referral points when referred order is refunded', 'simple-points-and-rewards' );
    ?>
				</label>
			</div>

			</div><!-- /.spar-earn-columns -->

			<?php 
    // Load referral options with defaults for Social Sharing display controls.
    $defaults = ( function_exists( 'spar_settings_default' ) ? spar_settings_default() : array() );
    $options = get_option( 'spar_options', $defaults );
    $options = ( is_array( $options ) ? array_merge( $defaults, $options ) : $defaults );
    $referral_options = ( isset( $options['earn']['referral'] ) && is_array( $options['earn']['referral'] ) ? $options['earn']['referral'] : array() );
    $social_enabled = ( array_key_exists( 'social_enabled', $referral_options ) ? !empty( $referral_options['social_enabled'] ) : true );
    ?>
			<div class="spar-referral-social-sharing spar-earn-section">
				<h4 style="margin-bottom: 5px;"><?php 
    esc_html_e( 'Social Sharing Buttons', 'simple-points-and-rewards' );
    ?></h4>
				<label class="spar-inline-block">
					<div class="spar-toggle-switch"><input type="checkbox" name="referral_social_enabled" value="1" <?php 
    checked( $social_enabled );
    ?> /><span class="spar-toggle-slider"></span></div>
					<?php 
    esc_html_e( 'Show social sharing buttons', 'simple-points-and-rewards' );
    ?>
				</label>
				<div class="spar-social-sharing-options" style="margin-top:10px;display:flex;flex-wrap:wrap;gap:15px;align-items:center;">
					<label style="display:flex;align-items:center;gap:6px;background-color:#fff;padding:6px 10px;border-radius:4px;">
						<input type="checkbox" name="referral_social_email_enabled" value="1" <?php 
    checked( ( array_key_exists( 'social_email_enabled', $referral_options ) ? !empty( $referral_options['social_email_enabled'] ) : true ) );
    ?> />
						<span><?php 
    esc_html_e( 'Email', 'simple-points-and-rewards' );
    ?></span>
					</label>
					<label style="display:flex;align-items:center;gap:6px;background-color:#fff;padding:6px 10px;border-radius:4px;">
						<input type="checkbox" name="referral_social_facebook_enabled" value="1" <?php 
    checked( ( array_key_exists( 'social_facebook_enabled', $referral_options ) ? !empty( $referral_options['social_facebook_enabled'] ) : true ) );
    ?> />
						<span><?php 
    esc_html_e( 'Facebook', 'simple-points-and-rewards' );
    ?></span>
					</label>
					<label style="display:flex;align-items:center;gap:6px;background-color:#fff;padding:6px 10px;border-radius:4px;">
						<input type="checkbox" name="referral_social_twitter_enabled" value="1" <?php 
    checked( ( array_key_exists( 'social_twitter_enabled', $referral_options ) ? !empty( $referral_options['social_twitter_enabled'] ) : true ) );
    ?> />
						<span><?php 
    esc_html_e( 'X', 'simple-points-and-rewards' );
    ?></span>
					</label>
					<label style="display:flex;align-items:center;gap:6px;background-color:#fff;padding:6px 10px;border-radius:4px;">
						<input type="checkbox" name="referral_social_whatsapp_enabled" value="1" <?php 
    checked( ( array_key_exists( 'social_whatsapp_enabled', $referral_options ) ? !empty( $referral_options['social_whatsapp_enabled'] ) : true ) );
    ?> />
						<span><?php 
    esc_html_e( 'WhatsApp', 'simple-points-and-rewards' );
    ?></span>
					</label>
					<label style="display:flex;align-items:center;gap:6px;background-color:#fff;padding:6px 10px;border-radius:4px;">
						<input type="checkbox" name="referral_social_telegram_enabled" value="1" <?php 
    checked( ( array_key_exists( 'social_telegram_enabled', $referral_options ) ? !empty( $referral_options['social_telegram_enabled'] ) : false ) );
    ?> />
						<span><?php 
    esc_html_e( 'Telegram', 'simple-points-and-rewards' );
    ?></span>
					</label>
					<label style="display:flex;align-items:center;gap:6px;background-color:#fff;padding:6px 10px;border-radius:4px;">
						<input type="checkbox" name="referral_social_discord_enabled" value="1" <?php 
    checked( ( array_key_exists( 'social_discord_enabled', $referral_options ) ? !empty( $referral_options['social_discord_enabled'] ) : false ) );
    ?> />
						<span><?php 
    esc_html_e( 'Discord', 'simple-points-and-rewards' );
    ?></span>
					</label>
					<label style="display:flex;align-items:center;gap:6px;background-color:#fff;padding:6px 10px;border-radius:4px;">
						<input type="checkbox" name="referral_social_tiktok_enabled" value="1" <?php 
    checked( ( array_key_exists( 'social_tiktok_enabled', $referral_options ) ? !empty( $referral_options['social_tiktok_enabled'] ) : false ) );
    ?> />
						<span><?php 
    esc_html_e( 'TikTok', 'simple-points-and-rewards' );
    ?></span>
					</label>
				</div>
				<div style="margin-top:12px;">
					<label style="display:block;margin-bottom:4px;font-weight:600;"><?php 
    esc_html_e( 'Share text (X, WhatsApp, Telegram)', 'simple-points-and-rewards' );
    ?></label>
					<input type="text" name="referral_social_share_text" value="<?php 
    echo esc_attr( ( isset( $referral_options['social_share_text'] ) ? $referral_options['social_share_text'] : esc_html__( 'Check out this great store!', 'simple-points-and-rewards' ) ) );
    ?>" style="width:100%;max-width:480px;" />
					<p class="description"><?php 
    esc_html_e( 'This text is prefilled in the share window for networks that support it. Facebook, Discord, and TikTok do not support share text.', 'simple-points-and-rewards' );
    ?></p>
				</div>
			</div>

			<?php 
    $show_clicks_log = !empty( $referral_options['show_clicks_log'] );
    ?>
			<div class="spar-referral-clicks-log spar-earn-section">
				<h4 style="margin-bottom: 5px;"><?php 
    esc_html_e( 'Referral Link Clicks', 'simple-points-and-rewards' );
    ?></h4>
				<label class="spar-inline-block">
					<div class="spar-toggle-switch"><input type="checkbox" name="referral_show_clicks_log" value="1" <?php 
    checked( $show_clicks_log );
    ?> /><span class="spar-toggle-slider"></span></div>
					<?php 
    esc_html_e( 'Show link clicks log on the rewards dashboard and widget', 'simple-points-and-rewards' );
    ?>
				</label>
				<small style="margin-bottom: 0px;"><?php 
    esc_html_e( 'Displays a paginated table of referral link clicks for each customer.', 'simple-points-and-rewards' );
    ?></small>
			</div>
			
			<!-- Referral Gift Offers Section -->
			<?php 
    ?>
			
			<!-- Gift Widget Navigation -->
			<?php 
    ?>
		</div>
	</div>
		<?php 
    ?>
		<!-- Points for Social Sharing (PRO) -->
		<div class="spar-accordion spar-premium-disabled">
			<div class="spar-accordion-header">
				<label>
					<div class="spar-toggle-switch">
						<input type="checkbox" name="social_sharing_referral_social_points_enabled" disabled="disabled" />
						<span class="spar-toggle-slider"></span>
					</div>
					<span class="spar-earn-type-badge"><i class="fa-solid fa-share-nodes" aria-hidden="true"></i></span>
					<span class="spar-earn-head-text">
						<span class="spar-earn-head-title"><?php 
    esc_html_e( 'Points for Social Sharing (PRO)', 'simple-points-and-rewards' );
    ?></span>
						<span class="spar-earn-head-desc"><?php 
    esc_html_e( 'Reward customers for sharing their referral link.', 'simple-points-and-rewards' );
    ?></span>
					</span>
				</label>
			</div>
		</div>
	<?php 
    ?>
	<?php 
    ?>
		<!-- First Order Bonus (Disabled) -->
		<div class="spar-accordion spar-premium-disabled">
			<div class="spar-accordion-header">
				<label>
					<div class="spar-toggle-switch">
						<input type="checkbox" name="first_order_enabled" disabled="disabled" />
						<span class="spar-toggle-slider"></span>
					</div>
					<span class="spar-earn-type-badge"><i class="fa-solid fa-cart-shopping" aria-hidden="true"></i></span>
					<span class="spar-earn-head-text">
						<span class="spar-earn-head-title"><?php 
    esc_html_e( 'First Order Bonus (PRO)', 'simple-points-and-rewards' );
    ?></span>
						<span class="spar-earn-head-desc"><?php 
    esc_html_e( 'A one-time bonus on a customer\'s first order.', 'simple-points-and-rewards' );
    ?></span>
					</span>
				</label>
			</div>
		</div>

		<!-- Nth Order Bonus (Disabled) -->
		<div class="spar-accordion spar-premium-disabled">
			<div class="spar-accordion-header">
				<label>
					<div class="spar-toggle-switch">
						<input type="checkbox" name="nth_order_enabled" disabled="disabled" />
						<span class="spar-toggle-slider"></span>
					</div>
					<span class="spar-earn-type-badge"><i class="fa-solid fa-trophy" aria-hidden="true"></i></span>
					<span class="spar-earn-head-text">
						<span class="spar-earn-head-title"><?php 
    esc_html_e( 'Nth Order Bonus (PRO)', 'simple-points-and-rewards' );
    ?></span>
						<span class="spar-earn-head-desc"><?php 
    esc_html_e( 'A bonus when a customer reaches an order milestone.', 'simple-points-and-rewards' );
    ?></span>
					</span>
				</label>
			</div>
		</div>
	<?php 
    ?>

	<?php 
    ?>
	<!-- Write a Review (Disabled) -->
	<div class="spar-accordion spar-premium-disabled">
		<div class="spar-accordion-header">
			<label>
				<div class="spar-toggle-switch">
					<input type="checkbox" name="review_enabled" disabled="disabled" />
					<span class="spar-toggle-slider"></span>
				</div>
				<span class="spar-earn-type-badge"><i class="fa-regular fa-star" aria-hidden="true"></i></span>
				<span class="spar-earn-head-text">
					<span class="spar-earn-head-title"><?php 
    esc_html_e( 'Write a Review (PRO)', 'simple-points-and-rewards' );
    ?></span>
					<span class="spar-earn-head-desc"><?php 
    esc_html_e( 'Award points when a customer writes a product review.', 'simple-points-and-rewards' );
    ?></span>
				</span>
			</label>
		</div>
	</div>
	<?php 
    ?>

	<?php 
    ?>
	<!-- Points for Birthdays (Disabled) -->
	<div class="spar-accordion spar-premium-disabled">
		<div class="spar-accordion-header">
			<label>
				<div class="spar-toggle-switch">
					<input type="checkbox" name="birthday_enabled" disabled="disabled" />
					<span class="spar-toggle-slider"></span>
				</div>
				<span class="spar-earn-type-badge"><i class="fa-solid fa-cake-candles" aria-hidden="true"></i></span>
				<span class="spar-earn-head-text">
					<span class="spar-earn-head-title"><?php 
    esc_html_e( 'Points for Birthdays (PRO)', 'simple-points-and-rewards' );
    ?></span>
					<span class="spar-earn-head-desc"><?php 
    esc_html_e( 'Award points to customers on their birthday each year.', 'simple-points-and-rewards' );
    ?></span>
				</span>
			</label>
		</div>
	</div>
	<?php 
    ?>

	<!-- Daily Login Bonus -->
	<?php 
    ?>
		<div class="spar-accordion spar-premium-disabled">
			<div class="spar-accordion-header">
				<label>
					<div class="spar-toggle-switch">
						<input type="checkbox" name="daily_login_enabled" disabled="disabled" />
						<span class="spar-toggle-slider"></span>
					</div>
					<span class="spar-earn-type-badge"><i class="fa-solid fa-calendar-check" aria-hidden="true"></i></span>
					<span class="spar-earn-head-text">
						<span class="spar-earn-head-title"><?php 
    esc_html_e( 'Daily Login Bonus (PRO)', 'simple-points-and-rewards' );
    ?></span>
						<span class="spar-earn-head-desc"><?php 
    esc_html_e( 'Points awarded once per day when a user visits the site.', 'simple-points-and-rewards' );
    ?></span>
					</span>
				</label>
			</div>
		</div>
	<?php 
    ?>

	<!-- Daily Prize Wheel -->
	<?php 
    ?>
		<div class="spar-accordion spar-premium-disabled">
			<div class="spar-accordion-header">
				<label>
					<div class="spar-toggle-switch">
						<input type="checkbox" name="spin_wheel_enabled" disabled="disabled" />
						<span class="spar-toggle-slider"></span>
					</div>
					<span class="spar-earn-type-badge"><i class="fa-solid fa-dharmachakra" aria-hidden="true"></i></span>
					<span class="spar-earn-head-text">
						<span class="spar-earn-head-title"><?php 
    esc_html_e( 'Daily Prize Wheel (PRO)', 'simple-points-and-rewards' );
    ?></span>
						<span class="spar-earn-head-desc"><?php 
    esc_html_e( 'Let customers spin a wheel for a chance to win prizes.', 'simple-points-and-rewards' );
    ?></span>
					</span>
				</label>
			</div>
		</div>
	<?php 
    ?>

	<?php 
    // Build saved order for JS to reorder accordions on load.
    $defaults_order = spar_settings_default();
    $options_order = get_option( 'spar_options', $defaults_order );
    $options_order = ( is_array( $options_order ) ? array_merge( $defaults_order, $options_order ) : $defaults_order );
    $default_earn_order = [
        'signup',
        'order',
        'order_fixed',
        'referral',
        'first_order',
        'nth_order',
        'review',
        'birthday',
        'daily_login',
        'spin_wheel',
        'buy_products'
    ];
    $saved_earn_order = ( isset( $options_order['earn_ways_order'] ) && is_array( $options_order['earn_ways_order'] ) && !empty( $options_order['earn_ways_order'] ) ? $options_order['earn_ways_order'] : $default_earn_order );
    foreach ( $default_earn_order as $way_key ) {
        if ( !in_array( $way_key, $saved_earn_order, true ) ) {
            $saved_earn_order[] = $way_key;
        }
    }
    // Append any custom way-to-earn keys (PRO) so they keep their sort position.
    if ( function_exists( 'sparp_cwe_get_way_keys__premium_only' ) ) {
        foreach ( sparp_cwe_get_way_keys__premium_only() as $cwe_key ) {
            if ( !in_array( $cwe_key, $saved_earn_order, true ) ) {
                $saved_earn_order[] = $cwe_key;
            }
        }
    }
    // Ensure the Product Offers & Bonuses key keeps its sort position (PRO).
    if ( function_exists( 'sparp_bp_is_enabled__premium_only' ) && !in_array( 'buy_products', $saved_earn_order, true ) ) {
        $saved_earn_order[] = 'buy_products';
    }
    ?>

	<?php 
    // Render saved custom ways-to-earn accordions (PRO) inside the list.
    if ( function_exists( 'sparp_cwe_render_admin_accordions__premium_only' ) ) {
        sparp_cwe_render_admin_accordions__premium_only();
    }
    ?>

	<?php 
    // Render the Product Offers & Bonuses accordion (PRO) inside the list.
    if ( function_exists( 'sparp_bp_render_admin_accordion__premium_only' ) ) {
        sparp_bp_render_admin_accordion__premium_only();
    } elseif ( !spar_fs()->can_use_premium_code__premium_only() ) {
        ?>
		<!-- Product Offers & Bonuses (Disabled) -->
		<div class="spar-accordion spar-premium-disabled">
			<div class="spar-accordion-header">
				<label>
					<div class="spar-toggle-switch">
						<input type="checkbox" disabled="disabled" />
						<span class="spar-toggle-slider"></span>
					</div>
					<span class="spar-earn-type-badge"><i class="fa-solid fa-bag-shopping" aria-hidden="true"></i></span>
					<span class="spar-earn-head-text">
						<span class="spar-earn-head-title"><?php 
        esc_html_e( 'Product Offers & Bonuses (PRO)', 'simple-points-and-rewards' );
        ?></span>
						<span class="spar-earn-head-desc"><?php 
        esc_html_e( 'Reward customers for buying specific products, bundles or categories.', 'simple-points-and-rewards' );
        ?></span>
					</span>
				</label>
			</div>
		</div>
		<?php 
    }
    ?>

	<?php 
    // Custom Ways to Earn locked placeholder (free version only) — shown last.
    if ( !function_exists( 'sparp_cwe_render_admin_accordions__premium_only' ) && !spar_fs()->can_use_premium_code__premium_only() ) {
        ?>
		<!-- Custom Ways to Earn (Disabled) -->
		<div class="spar-accordion spar-premium-disabled">
			<div class="spar-accordion-header">
				<label>
					<div class="spar-toggle-switch">
						<input type="checkbox" disabled="disabled" />
						<span class="spar-toggle-slider"></span>
					</div>
					<span class="spar-earn-type-badge"><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i></span>
					<span class="spar-earn-head-text">
						<span class="spar-earn-head-title"><?php 
        esc_html_e( 'Custom Ways to Earn (PRO)', 'simple-points-and-rewards' );
        ?></span>
						<span class="spar-earn-head-desc"><?php 
        esc_html_e( 'Register your own ways to earn points from code.', 'simple-points-and-rewards' );
        ?></span>
					</span>
				</label>
			</div>
		</div>
		<?php 
    }
    ?>

	</div><!-- /#spar-earn-accordion-list -->

	<?php 
    // Developer instructions for registering custom ways to earn from code (PRO).
    // Rendered outside the accordion list so it is not treated as a sortable item.
    if ( function_exists( 'sparp_cwe_render_admin_instructions__premium_only' ) ) {
        sparp_cwe_render_admin_instructions__premium_only();
    }
    ?>
	</div>
	<script>window.sparEarnOrder = <?php 
    echo wp_json_encode( array_values( $saved_earn_order ) );
    ?>;</script>

	<?php 
}
