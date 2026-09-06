<?php

/**
 * Expiry Settings Tab
 */
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
function spar_settings_tab_expiry() {
    $defaults = spar_settings_default();
    $options = get_option( 'spar_options', $defaults );
    $options = array_merge( $defaults, $options );
    // Determine inline style values (store only the value so we can escape properly when rendering)
    $premium_disabled_inline = '';
    $premium_disabled_inline = 'opacity:0.5;pointer-events:none;';
    ?>
	<h3><?php 
    esc_html_e( 'Expiry Settings', 'simple-points-and-rewards' );
    ?></h3>
	<p><?php 
    esc_html_e( 'Control how vouchers, rewards, and points expire or are cleaned up automatically.', 'simple-points-and-rewards' );
    ?></p>

	<div class="spar-advanced-settings"<?php 
    echo ( $premium_disabled_inline ? ' style="' . esc_attr( $premium_disabled_inline ) . '"' : '' );
    ?>>
		<div class="spar-settings-section">
		<div class="spar-settings-section-header">
			<h4><?php 
    esc_html_e( 'Points Inactivity Expiry', 'simple-points-and-rewards' );
    ?></h4>
			<p><?php 
    esc_html_e( 'Reset customer balances after a configurable period of inactivity.', 'simple-points-and-rewards' );
    ?></p>
		</div>
		<p>
			<label>
				<div class="spar-toggle-switch"><input type="checkbox" name="points_inactivity_expiry_enabled" <?php 
    checked( !empty( $options['points_inactivity_expiry_enabled'] ) );
    ?> /><span class="spar-toggle-slider"></span></div>
				<?php 
    esc_html_e( 'Reset points after a period of inactivity', 'simple-points-and-rewards' );
    ?>
			</label>
		</p>
		<p class="description"><?php 
    esc_html_e( 'Customers who do not earn new points within the configured inactivity window will have their balance reset to zero.', 'simple-points-and-rewards' );
    ?></p>
		<p class="description"><?php 
    esc_html_e( 'This process runs on a daily cron schedule. Ensure WP-Cron is working on your site.', 'simple-points-and-rewards' );
    ?></p>
		<?php 
    $expiry_days = ( isset( $options['points_inactivity_expiry_days'] ) ? (int) $options['points_inactivity_expiry_days'] : 0 );
    $expiry_days_value = ( $expiry_days > 0 ? $expiry_days : '' );
    $notice_days = ( isset( $options['points_inactivity_notice_days'] ) ? (int) $options['points_inactivity_notice_days'] : 0 );
    $notice_days_value = ( $notice_days > 0 ? $notice_days : '' );
    // Prepare style attribute values for conditional display (store only CSS value)
    $expiry_settings_display = ( !empty( $options['points_inactivity_expiry_enabled'] ) ? '' : 'display:none;' );
    $dashboard_notice_enabled = !empty( $options['points_inactivity_dashboard_notice_enabled'] );
    $dashboard_notice_display = ( $dashboard_notice_enabled ? '' : 'display:none;' );
    $dashboard_notice_message = ( isset( $options['points_inactivity_dashboard_notice_message'] ) ? (string) $options['points_inactivity_dashboard_notice_message'] : '' );
    $dashboard_notice_max_days = ( isset( $options['points_inactivity_dashboard_notice_max_days'] ) ? (int) $options['points_inactivity_dashboard_notice_max_days'] : 0 );
    $dashboard_notice_max_days_value = ( $dashboard_notice_max_days > 0 ? $dashboard_notice_max_days : '' );
    ?>
		<div class="spar-inactivity-expiry-settings"<?php 
    echo ( $expiry_settings_display ? ' style="' . esc_attr( $expiry_settings_display ) . '"' : '' );
    ?>>
			<p>
				<label>
					<?php 
    esc_html_e( 'Inactivity window (days):', 'simple-points-and-rewards' );
    ?>
					<br/>
                    <input type="number" min="0" name="points_inactivity_expiry_days"
                    style="margin: 0;"
                    value="<?php 
    echo esc_attr( $expiry_days_value );
    ?>" />
				</label>
			</p>
			<p class="description"><?php 
    esc_html_e( 'Customers who do not earn new points within this window will have their balance reset to zero.', 'simple-points-and-rewards' );
    ?></p>
			<p>
				<label>
					<?php 
    esc_html_e( 'Reminder email lead time (days):', 'simple-points-and-rewards' );
    ?>
					<br/>
                    <input type="number" min="0" name="points_inactivity_notice_days"
                    style="margin: 0;"
                    value="<?php 
    echo esc_attr( $notice_days_value );
    ?>" />
				</label>
			</p>
			<p class="description"><?php 
    esc_html_e( 'Send a reminder this many days before the inactivity expiry. Set to 0 to skip reminders.', 'simple-points-and-rewards' );
    ?></p>
			<p class="description"><?php 
    esc_html_e( 'Customise the reminder email from the Email Notifications tab.', 'simple-points-and-rewards' );
    ?></p>
			<p>
				<label>
					<div class="spar-toggle-switch"><input type="checkbox" name="points_inactivity_dashboard_notice_enabled" <?php 
    checked( $dashboard_notice_enabled );
    ?> /><span class="spar-toggle-slider"></span></div>
					<?php 
    esc_html_e( 'Show inactivity notice on Rewards dashboard', 'simple-points-and-rewards' );
    ?>
				</label>
			</p>
			<p class="description"><?php 
    esc_html_e( 'Displays a countdown letting customers know when unused points will expire due to inactivity.', 'simple-points-and-rewards' );
    ?></p>
			<div class="spar-inactivity-dashboard-notice-settings"<?php 
    echo ( $dashboard_notice_display ? ' style="' . esc_attr( $dashboard_notice_display ) . '"' : '' );
    ?>>
				<p>
					<label>
						<?php 
    esc_html_e( 'Custom dashboard notice message', 'simple-points-and-rewards' );
    ?>
						<br/>
                        <input type="text" name="points_inactivity_dashboard_notice_message"
                        style="margin: 0;"
                        value="<?php 
    echo esc_attr( $dashboard_notice_message );
    ?>" placeholder="<?php 
    echo esc_attr__( 'e.g. You have {days} days left before your points expire.', 'simple-points-and-rewards' );
    ?>" style="width:100%; max-width:460px;" />
					</label>
				</p>
				<p class="description"><?php 
    esc_html_e( 'Leave blank to use the default message. Use {expiry_days} for the inactivity window and {days} for the remaining days before points expire.', 'simple-points-and-rewards' );
    ?></p>
			<p>
				<label>
					<?php 
    esc_html_e( 'Only show notice when fewer than X days remain:', 'simple-points-and-rewards' );
    ?>
					<br/>
					<input type="number" min="0" name="points_inactivity_dashboard_notice_max_days"
					style="margin: 0;"
					value="<?php 
    echo esc_attr( $dashboard_notice_max_days_value );
    ?>" />
				</label>
			</p>
			<p class="description"><?php 
    esc_html_e( 'Leave blank or set to 0 to always show the notice. Enter a number to only display the notice when the customer has fewer than that many days before their points expire.', 'simple-points-and-rewards' );
    ?></p>
			</div>
		</div>
		</div>

		<div class="spar-settings-section">
		<div class="spar-settings-section-header">
			<h4><?php 
    esc_html_e( 'Voucher Expiry', 'simple-points-and-rewards' );
    ?></h4>
			<p><?php 
    esc_html_e( 'Set expiry and cleanup rules for generated voucher coupons.', 'simple-points-and-rewards' );
    ?></p>
		</div>
		<p>
			<label>
				<?php 
    esc_html_e( 'Voucher expiry (days):', 'simple-points-and-rewards' );
    ?>
				<input type="number" name="voucher_expiry" value="<?php 
    echo esc_attr( $options['voucher_expiry'] ?? '' );
    ?>" />
			</label>
		</p>
		<p><small><?php 
    esc_html_e( 'Leave blank or set to 0 for vouchers that never expire.', 'simple-points-and-rewards' );
    ?></small></p>
		<p>
			<label>
				<div class="spar-toggle-switch"><input type="checkbox" name="auto_delete_used" <?php 
    checked( !empty( $options['auto_delete_used'] ) );
    ?> /><span class="spar-toggle-slider"></span></div>
				<?php 
    esc_html_e( 'Delete vouchers after use', 'simple-points-and-rewards' );
    ?>
			</label>
		</p>
		<p>
			<label>
				<div class="spar-toggle-switch"><input type="checkbox" name="auto_delete_expired" <?php 
    checked( !empty( $options['auto_delete_expired'] ) );
    ?> /><span class="spar-toggle-slider"></span></div>
				<?php 
    esc_html_e( 'Delete expired vouchers', 'simple-points-and-rewards' );
    ?>
			</label>
		</p>
		</div>

		<div class="spar-settings-section">
		<div class="spar-settings-section-header">
			<h4><?php 
    esc_html_e( 'Reward Expiry', 'simple-points-and-rewards' );
    ?></h4>
			<p><?php 
    esc_html_e( 'Remove expired reward definitions automatically.', 'simple-points-and-rewards' );
    ?></p>
		</div>
		<p>
			<label>
				<div class="spar-toggle-switch"><input type="checkbox" name="auto_delete_expired_rewards" <?php 
    checked( !empty( $options['auto_delete_expired_rewards'] ) );
    ?> /><span class="spar-toggle-slider"></span></div>
				<?php 
    esc_html_e( 'Automatically delete expired rewards', 'simple-points-and-rewards' );
    ?>
			</label>
		</p>
		<p><small><?php 
    esc_html_e( 'When enabled, reward coupons that have passed their expiry date will be removed automatically.', 'simple-points-and-rewards' );
    ?></small></p>
		</div>
	</div>
	<?php 
}
