<?php
/**
 * Checkout Box Settings Tab
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function spar_settings_tab_checkout_box() {
	$defaults = spar_settings_default();
	$options = get_option( 'spar_options', $defaults );
	$options = array_merge( $defaults, $options );
	$primary_color = sanitize_hex_color( $options['checkout_box_primary_color'] ?? '#667eea' ) ?: '#667eea';
	$secondary_color = sanitize_hex_color( $options['checkout_box_secondary_color'] ?? '#28a745' ) ?: '#28a745';
	?>
	<h3><?php esc_html_e( 'Checkout Rewards Box', 'simple-points-and-rewards' ); ?></h3>
	<p><?php esc_html_e( 'The checkout rewards box allows customers to view the points they will earn on their order, claim rewards, and redeem vouchers.', 'simple-points-and-rewards' ); ?></p>

	<div class="spar-settings-section">
		<div class="spar-settings-section-header">
			<h4><?php esc_html_e( 'Display Settings', 'simple-points-and-rewards' ); ?></h4>
			<p><?php esc_html_e( 'Choose where the checkout rewards box appears and which actions it supports.', 'simple-points-and-rewards' ); ?></p>
		</div>

	<p><label><div class="spar-toggle-switch"><input type="checkbox" name="show_on_cart" <?php checked( ! empty( $options['show_on_cart'] ) ); ?> /><span class="spar-toggle-slider"></span></div> <?php esc_html_e( 'Show rewards box on cart page', 'simple-points-and-rewards' ); ?></label></p>
	<p><label><div class="spar-toggle-switch"><input type="checkbox" name="show_on_checkout" <?php checked( ! empty( $options['show_on_checkout'] ) ); ?> /><span class="spar-toggle-slider"></span></div> <?php esc_html_e( 'Show rewards box on checkout page', 'simple-points-and-rewards' ); ?></label></p>
	<p><label><div class="spar-toggle-switch"><input type="checkbox" name="show_on_thankyou" <?php checked( ! empty( $options['show_on_thankyou'] ) ); ?> /><span class="spar-toggle-slider"></span></div> <?php esc_html_e( 'Show rewards box on thank you page', 'simple-points-and-rewards' ); ?></label></p>
	<p><label><div class="spar-toggle-switch"><input type="checkbox" name="show_for_guests" <?php checked( ! empty( $options['show_for_guests'] ) ); ?> /><span class="spar-toggle-slider"></span></div> <?php esc_html_e( 'Show rewards box for guest users', 'simple-points-and-rewards' ); ?></label></p>
	<p><label><div class="spar-toggle-switch"><input type="checkbox" name="checkout_box_show_points_summary" <?php checked( ! empty( $options['checkout_box_show_points_summary'] ) ); ?> /><span class="spar-toggle-slider"></span></div> <?php esc_html_e( 'Show points summary tooltip in checkout box', 'simple-points-and-rewards' ); ?></label></p>
	<p><label><div class="spar-toggle-switch"><input type="checkbox" name="checkout_box_enable_confetti" <?php checked( ! empty( $options['checkout_box_enable_confetti'] ) ); ?> /><span class="spar-toggle-slider"></span></div> <?php esc_html_e( 'Enable confetti animation on redemption', 'simple-points-and-rewards' ); ?></label></p>
	<?php
	$redeem_button_text = isset( $options['checkout_box_redeem_button_text'] ) ? sanitize_text_field( $options['checkout_box_redeem_button_text'] ) : '';
	$redeem_button_default = esc_html__( 'Redeem Rewards', 'simple-points-and-rewards' );
	?>
	<p style="margin-top: 12px;">
		<label for="spar_checkout_box_redeem_button_text" style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
			<span style="min-width:160px; font-weight:600; display:inline-block;"><?php esc_html_e( 'Redeem button text', 'simple-points-and-rewards' ); ?></span>
			<br/>
			<input type="text" id="spar_checkout_box_redeem_button_text" name="checkout_box_redeem_button_text" value="<?php echo esc_attr( $redeem_button_text ); ?>" placeholder="<?php echo esc_attr( $redeem_button_default ); ?>" style="min-width:280px;" />
		</label>
	</p>
	</div>

	<div class="spar-settings-section">
		<div class="spar-settings-section-header">
			<h4><?php esc_html_e( 'Theme Settings', 'simple-points-and-rewards' ); ?></h4>
			<p><?php esc_html_e( 'Set the visual style and accent colours used by the checkout rewards box.', 'simple-points-and-rewards' ); ?></p>
		</div>

	<p style="margin: 0;">
		<label for="spar_rewards_box_theme"><?php esc_html_e( 'Checkout Rewards Box Theme:', 'simple-points-and-rewards' ); ?></label>
		<select id="spar_rewards_box_theme" name="rewards_box_theme">
			<option value="default" <?php selected( ( $options['rewards_box_theme'] ?? 'default' ), 'default' ); ?>><?php esc_html_e( 'Default (Regular)', 'simple-points-and-rewards' ); ?></option>
			<option value="medium" <?php selected( ( $options['rewards_box_theme'] ?? 'default' ), 'medium' ); ?>><?php esc_html_e( 'Medium', 'simple-points-and-rewards' ); ?></option>
			<option value="compact" <?php selected( ( $options['rewards_box_theme'] ?? 'default' ), 'compact' ); ?>><?php esc_html_e( 'Compact', 'simple-points-and-rewards' ); ?></option>
		</select>
	</p>
	<p style="margin: 12px 0 0;">
		<label for="spar_checkout_box_theme_style"><?php esc_html_e( 'Theme Style:', 'simple-points-and-rewards' ); ?></label>
		<select id="spar_checkout_box_theme_style" name="checkout_box_theme_style">
			<option value="light" <?php selected( ( $options['checkout_box_theme_style'] ?? 'light' ), 'light' ); ?>><?php esc_html_e( 'Light Mode', 'simple-points-and-rewards' ); ?></option>
			<option value="dark" <?php selected( ( $options['checkout_box_theme_style'] ?? 'light' ), 'dark' ); ?>><?php esc_html_e( 'Dark Mode', 'simple-points-and-rewards' ); ?></option>
		</select>
	</p>
	<p style="margin-top: 16px;">
		<label for="spar_checkout_box_primary_color" style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
			<span style="min-width:160px; font-weight:600; display:inline-block;"><?php esc_html_e( 'Primary Theme Color', 'simple-points-and-rewards' ); ?></span>
			<br/>
			<input type="color" id="spar_checkout_box_primary_color" name="checkout_box_primary_color" value="<?php echo esc_attr( $primary_color ); ?>" />
		</label>
		<span class="description" style="display:block; margin-top:6px;">
			<?php esc_html_e( 'Controls the accent color used for points totals, buttons, and highlights in the checkout rewards box.', 'simple-points-and-rewards' ); ?>
		</span>
	</p>
	<p style="margin-top: 16px;">
		<label for="spar_checkout_box_secondary_color" style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
			<span style="min-width:160px; font-weight:600; display:inline-block;"><?php esc_html_e( 'Secondary Accent Color', 'simple-points-and-rewards' ); ?></span>
			<br/>
			<input type="color" id="spar_checkout_box_secondary_color" name="checkout_box_secondary_color" value="<?php echo esc_attr( $secondary_color ); ?>" />
		</label>
		<span class="description" style="display:block; margin-top:6px;">
			<?php esc_html_e( 'Controls the success accents used for earning messages, reward values, and redeem buttons.', 'simple-points-and-rewards' ); ?>
		</span>
	</p>
	</div>
	<?php
}
