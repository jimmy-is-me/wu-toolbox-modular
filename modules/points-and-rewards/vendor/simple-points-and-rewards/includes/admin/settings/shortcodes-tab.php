<?php
/**
 * Shortcodes settings tab
 */
if ( ! defined( 'ABSPATH' ) ) {
	 exit;
}

function spar_settings_tab_shortcodes() {
	$shortcode_groups = [
		'ui' => [
			'label' => esc_html__( 'Rewards UI', 'simple-points-and-rewards' ),
			'items' => [
				[ 'code' => '[spar_points_rewards]', 'label' => esc_html__( 'Full rewards dashboard (same as My Account "Rewards" tab).', 'simple-points-and-rewards' ) ],
				[ 'code' => '[spar_checkout_rewards_box]', 'label' => esc_html__( 'Checkout rewards box (full widget).', 'simple-points-and-rewards' ) ],
				[ 'code' => '[spar_redeem_discount_tool]', 'label' => esc_html__( 'Non-compact points discount redemption tool.', 'simple-points-and-rewards' ) ],
				[ 'code' => '[spar_redeem_discount_compact]', 'label' => esc_html__( 'Compact points discount redemption tool.', 'simple-points-and-rewards' ) ],
			],
		],
		'points' => [
			'label' => esc_html__( 'Points & Levels', 'simple-points-and-rewards' ),
			'items' => [
				[ 'code' => '[simple_points_rewards display="points"]', 'label' => esc_html__( 'Current points balance.', 'simple-points-and-rewards' ) ],
				[ 'code' => '[simple_points_rewards display="total_earned"]', 'label' => esc_html__( 'Lifetime points earned.', 'simple-points-and-rewards' ) ],
				[ 'code' => '[simple_points_rewards display="level_name"]', 'label' => esc_html__( 'Current level name.', 'simple-points-and-rewards' ) ],
				[ 'code' => '[simple_points_rewards display="next_level_name"]', 'label' => esc_html__( 'Next level name.', 'simple-points-and-rewards' ) ],
				[ 'code' => '[simple_points_rewards display="points_to_next_level"]', 'label' => esc_html__( 'Points needed to reach next level.', 'simple-points-and-rewards' ) ],
				[ 'code' => '[simple_points_rewards display="level_progress_percent"]', 'label' => esc_html__( 'Progress to next level as a percent.', 'simple-points-and-rewards' ) ],
				[ 'code' => '[simple_points_rewards display="level_multiplier"]', 'label' => esc_html__( 'Current level points multiplier.', 'simple-points-and-rewards' ) ],
				[ 'code' => '[simple_points_rewards display="points_value"]', 'label' => esc_html__( 'Approximate cash value of current points.', 'simple-points-and-rewards' ) ],
				[ 'code' => '[simple_points_rewards display="points_label"]', 'label' => esc_html__( 'Points label text.', 'simple-points-and-rewards' ) ],
			],
		],
		'referrals' => [
			'label' => esc_html__( 'Referrals', 'simple-points-and-rewards' ),
			'items' => [
				[ 'code' => '[simple_points_rewards display="referral_code"]', 'label' => esc_html__( 'Customer referral code.', 'simple-points-and-rewards' ) ],
				[ 'code' => '[simple_points_rewards display="referral_link" base_url="https://example.com"]', 'label' => esc_html__( 'Referral link (optional base URL).', 'simple-points-and-rewards' ) ],
				[ 'code' => '[simple_points_rewards display="referral_clicks"]', 'label' => esc_html__( 'Total referral clicks.', 'simple-points-and-rewards' ) ],
				[ 'code' => '[simple_points_rewards display="referral_referrals"]', 'label' => esc_html__( 'Total successful referrals.', 'simple-points-and-rewards' ) ],
				[ 'code' => '[simple_points_rewards display="referral_points_earned"]', 'label' => esc_html__( 'Total points earned from referrals.', 'simple-points-and-rewards' ) ],
			],
		],
	];
	?>
	<div class="spar-shortcodes-settings">
		<h3><?php esc_html_e( 'Shortcodes', 'simple-points-and-rewards' ); ?></h3>
		<p><?php esc_html_e( 'You can use these shortcodes to display points and rewards data anywhere.', 'simple-points-and-rewards' ); ?></p>

		<div class="spar-settings-section">
		<div class="spar-settings-section-header">
			<h4><?php esc_html_e( 'Shortcode Library', 'simple-points-and-rewards' ); ?></h4>
			<p><?php esc_html_e( 'Copy the shortcode for the rewards UI, points data, or referral details you want to display.', 'simple-points-and-rewards' ); ?></p>
		</div>
		<div class="spar-shortcode-tabs" data-default-tab="spar-shortcodes-ui">
			<div class="spar-shortcode-tabs-nav" role="tablist" aria-label="<?php echo esc_attr__( 'Shortcode Categories', 'simple-points-and-rewards' ); ?>">
				<?php
				$tab_index = 0;
				foreach ( $shortcode_groups as $group_key => $group ) :
					$tab_id = 'spar-shortcodes-' . sanitize_key( $group_key );
					$tab_active = 0 === $tab_index ? ' is-active' : '';
					?>
					<button type="button" class="spar-shortcode-tab<?php echo esc_attr( $tab_active ); ?>" data-tab="<?php echo esc_attr( $tab_id ); ?>" role="tab" aria-controls="<?php echo esc_attr( $tab_id ); ?>" aria-selected="<?php echo esc_attr( 0 === $tab_index ? 'true' : 'false' ); ?>">
						<?php echo esc_html( $group['label'] ); ?>
					</button>
				<?php
					$tab_index++;
				endforeach;
				?>
			</div>
			<div class="spar-shortcode-tabs-panels">
				<?php
				$panel_index = 0;
				foreach ( $shortcode_groups as $group_key => $group ) :
					$panel_id = 'spar-shortcodes-' . sanitize_key( $group_key );
					$panel_active = 0 === $panel_index ? ' is-active' : '';
					?>
					<div class="spar-shortcode-panel<?php echo esc_attr( $panel_active ); ?>" id="<?php echo esc_attr( $panel_id ); ?>" role="tabpanel">
						<div class="spar-shortcode-list">
							<?php foreach ( $group['items'] as $index => $shortcode ) :
								$field_id = 'spar-shortcode-' . sanitize_key( $group_key ) . '-' . (int) $index;
								?>
								<div class="spar-shortcode-item">
									<label for="<?php echo esc_attr( $field_id ); ?>" class="spar-shortcode-label">
										<?php echo esc_html( $shortcode['label'] ); ?>
									</label>
									<div class="spar-shortcode-field">
										<input type="text" id="<?php echo esc_attr( $field_id ); ?>" class="regular-text code" value="<?php echo esc_attr( $shortcode['code'] ); ?>" readonly />
										<button type="button" class="button spar-copy-shortcodes" data-copy-target="#<?php echo esc_attr( $field_id ); ?>" data-copied-text="<?php echo esc_attr__( 'Copied!', 'simple-points-and-rewards' ); ?>" data-error-text="<?php echo esc_attr__( 'Copy failed.', 'simple-points-and-rewards' ); ?>">
											<?php esc_html_e( 'Copy', 'simple-points-and-rewards' ); ?>
										</button>
										<span class="spar-copy-status" aria-live="polite"></span>
									</div>
								</div>
							<?php endforeach; ?>
						</div>
					</div>
				<?php
					$panel_index++;
				endforeach;
				?>
			</div>
		</div>
		</div>

		<div class="spar-settings-section">
		<div class="spar-settings-section-header">
			<h4><?php esc_html_e( 'Optional Attributes', 'simple-points-and-rewards' ); ?></h4>
			<p><?php esc_html_e( 'Use these attributes to customise shortcode output when needed.', 'simple-points-and-rewards' ); ?></p>
		</div>
		<ul class="spar-shortcode-list">
			<li><code>before</code>, <code>after</code> — <?php esc_html_e( 'Text or HTML to wrap the output.', 'simple-points-and-rewards' ); ?></li>
			<li><code>empty</code> — <?php esc_html_e( 'Fallback output when nothing is available.', 'simple-points-and-rewards' ); ?></li>
			<li><code>format</code> — <?php esc_html_e( 'Use "raw" to skip formatting.', 'simple-points-and-rewards' ); ?></li>
			<li><code>decimals</code> — <?php esc_html_e( 'Number of decimals for numeric outputs.', 'simple-points-and-rewards' ); ?></li>
			<li><code>user_id</code> — <?php esc_html_e( 'Admins can render a specific user.', 'simple-points-and-rewards' ); ?></li>
			<li><code>base_url</code> — <?php esc_html_e( 'Used by referral_link to build the URL.', 'simple-points-and-rewards' ); ?></li>
		</ul>
		</div>
	</div>
	<?php
}
