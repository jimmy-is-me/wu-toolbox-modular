<?php
/**
 * Terms and Conditions Settings Tab
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function spar_settings_tab_terms() {
	$defaults = spar_settings_default();
	$options = get_option( 'spar_options', $defaults );
	$options = array_merge( $defaults, $options );
	?>
	<h3><?php esc_html_e( 'Terms and Conditions', 'simple-points-and-rewards' ); ?></h3>
	<p><?php esc_html_e( 'Add a dashboard terms link and customise the popup content.', 'simple-points-and-rewards' ); ?></p>
	<div class="spar-settings-section">
	<p><label><div class="spar-toggle-switch"><input type="checkbox" name="terms_enabled" id="spar_terms_enabled" <?php checked( ! empty( $options['terms_enabled'] ) ); ?> /><span class="spar-toggle-slider"></span></div> <?php esc_html_e( 'Enable Terms and Conditions', 'simple-points-and-rewards' ); ?></label></p>
	<p class="description"><?php esc_html_e( 'When enabled, a "Terms and Conditions" link will be displayed at the bottom of the rewards dashboard that opens a popup with your custom terms.', 'simple-points-and-rewards' ); ?></p>
	<div class="spar-toggle-extra-settings<?php echo ! empty( $options['terms_enabled'] ) ? '' : ' spar-hidden'; ?>" data-toggle-input="terms_enabled">
		<h4 style="margin-top: 0; margin-bottom: 10px;"><?php esc_html_e( 'Terms Display Settings', 'simple-points-and-rewards' ); ?></h4>
		<p>
			<label for="terms_link_text"><?php esc_html_e( 'Link Text:', 'simple-points-and-rewards' ); ?></label>
			<input type="text" name="terms_link_text" id="terms_link_text" value="<?php echo esc_attr( $options['terms_link_text'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Terms and Conditions', 'simple-points-and-rewards' ); ?>" class="regular-text" />
			<small class="description"><?php esc_html_e( 'Leave blank to use the default localised text.', 'simple-points-and-rewards' ); ?></small>
		</p>
		<p style="margin-top: 0px;">
			<label><div class="spar-toggle-switch"><input type="checkbox" name="terms_show_dashboard" <?php checked( isset( $options['terms_show_dashboard'] ) ? (bool) $options['terms_show_dashboard'] : true ); ?> /><span class="spar-toggle-slider"></span></div> <?php esc_html_e( 'Show on rewards dashboard', 'simple-points-and-rewards' ); ?></label>
		</p>
		<p style="margin-top: -10px;">
			<label><div class="spar-toggle-switch"><input type="checkbox" name="terms_show_widget" <?php checked( isset( $options['terms_show_widget'] ) ? (bool) $options['terms_show_widget'] : true ); ?> /><span class="spar-toggle-slider"></span></div> <?php esc_html_e( 'Show on rewards widget', 'simple-points-and-rewards' ); ?></label>
		</p>
		<h4 style="margin-top: 20px;"><?php esc_html_e( 'Terms Content', 'simple-points-and-rewards' ); ?></h4>
		<p class="spar-terms-generate-row">
			<button type="button" id="spar-generate-terms-btn" class="button button-secondary">
				✨ <?php esc_html_e( 'Generate from Current Settings', 'simple-points-and-rewards' ); ?>
			</button>
			<span id="spar-generate-terms-spinner" class="spinner" style="float:none;visibility:hidden;margin:0 6px;vertical-align:middle;"></span>
			<span id="spar-generate-terms-msg" style="vertical-align:middle;"></span>
		</p>
		<p class="description" style="margin-top:-4px;margin-bottom:10px;"><?php esc_html_e( 'This generates a basic template based on your current plugin settings. Please review and adapt the content to suit your specific business requirements before publishing, and seek legal advice if required.', 'simple-points-and-rewards' ); ?></p>
		<?php
		$saved_terms_content = isset( $options['terms_content'] ) && '' !== $options['terms_content'] ? $options['terms_content'] : '';
		if ( '' === $saved_terms_content ) {
			$terms_default = '<h3>' . esc_html__( 'Points & Rewards Program – Terms and Conditions', 'simple-points-and-rewards' ) . '</h3>

<p>' . esc_html__( 'By participating in our Points & Rewards Program, you agree to the following terms and conditions.', 'simple-points-and-rewards' ) . '</p>

<h4>' . esc_html__( '1. Eligibility', 'simple-points-and-rewards' ) . '</h4>
<p>' . esc_html__( 'The rewards program is available to registered customers only. Participation is free and open to all registered account holders.', 'simple-points-and-rewards' ) . '</p>

<h4>' . esc_html__( '2. Earning Points', 'simple-points-and-rewards' ) . '</h4>
<p>' . esc_html__( 'Points are awarded for qualifying actions such as purchases, account sign-up, referrals, and other activities as outlined on the Earn Points section of your rewards dashboard. Points are credited to your account once the qualifying action is confirmed.', 'simple-points-and-rewards' ) . '</p>

<h4>' . esc_html__( '3. Redeeming Points', 'simple-points-and-rewards' ) . '</h4>
<p>' . esc_html__( 'Accumulated points may be redeemed for rewards such as discount vouchers as listed in the Claim Rewards section. Points have no cash value and cannot be exchanged for cash.', 'simple-points-and-rewards' ) . '</p>

<h4>' . esc_html__( '4. Points Validity', 'simple-points-and-rewards' ) . '</h4>
<p>' . esc_html__( 'Points remain valid while your account is active. We reserve the right to introduce expiry policies with advance notice to participants.', 'simple-points-and-rewards' ) . '</p>

<h4>' . esc_html__( '5. Account Suspension', 'simple-points-and-rewards' ) . '</h4>
<p>' . esc_html__( 'We reserve the right to suspend or terminate a customer\'s participation in the rewards program, and to void accumulated points, in cases of suspected fraud, misuse, or breach of these terms.', 'simple-points-and-rewards' ) . '</p>

<h4>' . esc_html__( '6. Program Changes', 'simple-points-and-rewards' ) . '</h4>
<p>' . esc_html__( 'We reserve the right to modify, suspend, or discontinue the rewards program at any time. Any significant changes will be communicated to participants in advance where reasonably possible.', 'simple-points-and-rewards' ) . '</p>

<h4>' . esc_html__( '7. Governing Law', 'simple-points-and-rewards' ) . '</h4>
<p>' . esc_html__( 'These terms are governed by applicable local laws. By participating in the program, you agree to these terms and any future amendments.', 'simple-points-and-rewards' ) . '</p>

<p><em>' . esc_html__( 'Last updated: ', 'simple-points-and-rewards' ) . date_i18n( get_option( 'date_format' ) ) . '</em></p>';
		} // end if no saved content
		$terms_content = '' !== $saved_terms_content ? $saved_terms_content : $terms_default;
		wp_editor(
			$terms_content,
			'terms_content',
			array(
				'textarea_name' => 'terms_content',
				'media_buttons' => false,
				'textarea_rows' => 40,
				'editor_height' => 400,
				'teeny'         => false,
				'quicktags'     => true,
			)
		);
		?>
	</div>
	</div>
	<?php
}
