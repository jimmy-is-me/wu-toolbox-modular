<?php
/**
 * Points Label Settings Tab
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function spar_settings_tab_points_label() {
	$defaults = spar_settings_default();
	$options = get_option( 'spar_options', $defaults );
	$options = array_merge( $defaults, $options );
	?>
	<h3><?php esc_html_e( 'Points Label', 'simple-points-and-rewards' ); ?></h3>
	<p><?php esc_html_e( 'Customise the reward and points wording used throughout the plugin.', 'simple-points-and-rewards' ); ?></p>
	<div class="spar-settings-section spar-settings-section--master">
		<div class="spar-settings-section-header">
			<h4><?php esc_html_e( 'Labels', 'simple-points-and-rewards' ); ?></h4>
			<p><?php esc_html_e( 'Customise the reward and points wording used throughout the plugin.', 'simple-points-and-rewards' ); ?></p>
		</div>
		<p>
			<label><?php esc_html_e( 'Rewards Label:', 'simple-points-and-rewards' ); ?> <input type="text" name="rewards_label" value="<?php echo esc_attr( $options['rewards_label'] ); ?>" /></label>
		</p>
		<p>
			<label><?php esc_html_e( 'Points Label:', 'simple-points-and-rewards' ); ?> <input type="text" name="points_label" value="<?php echo esc_attr( $options['points_label'] ); ?>" /></label>
		</p>
		<p>
			<label><?php esc_html_e( 'Singular Points Label:', 'simple-points-and-rewards' ); ?> <input type="text" name="points_label_singular" value="<?php echo esc_attr( $options['points_label_singular'] ); ?>" /></label>
		</p>
		<p>
			<label><?php esc_html_e( 'Points Value Prefix:', 'simple-points-and-rewards' ); ?> <input type="text" name="points_prefix" value="<?php echo esc_attr( $options['points_prefix'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'e.g. $', 'simple-points-and-rewards' ); ?>" /></label>
		</p>
		<?php
		$points_icon         = $options['points_icon'] ?? '';
		$points_icon_url     = $options['points_icon_url'] ?? '';
		$points_icon_display = $options['points_icon_display'] ?? 'prominent';
		$points_icon_presets = function_exists( 'spar_get_points_icon_presets' ) ? spar_get_points_icon_presets() : array();
		$points_icon_has     = ( 'custom' === $points_icon && '' !== $points_icon_url ) || ( '' !== $points_icon && 'custom' !== $points_icon && isset( $points_icon_presets[ $points_icon ] ) );
		$points_icon_has_custom_img = ( 'custom' === $points_icon && '' !== $points_icon_url );
		?>
		<div class="spar-points-icon-field" style="margin-bottom: -10px;">
			<label class="spar-points-icon-field-label"><strong><?php esc_html_e( 'Points Icon:', 'simple-points-and-rewards' ); ?></strong></label>
			<p class="description" style="margin:2px 0 8px;"><?php esc_html_e( 'Show a small icon next to points values (e.g. a coin). Pick a preset or upload your own image.', 'simple-points-and-rewards' ); ?></p>
			<div class="spar-points-icon-picker" role="radiogroup" style="margin-bottom: 10px;">
				<label class="spar-points-icon-option" title="<?php esc_attr_e( 'No icon', 'simple-points-and-rewards' ); ?>">
					<input type="radio" name="points_icon" value="" <?php checked( $points_icon, '' ); ?> />
					<span class="spar-points-icon-swatch spar-points-icon-swatch--none"><?php esc_html_e( 'None', 'simple-points-and-rewards' ); ?></span>
				</label>
				<?php foreach ( $points_icon_presets as $preset_key => $preset ) : ?>
					<label class="spar-points-icon-option" title="<?php echo esc_attr( $preset['label'] ?? $preset_key ); ?>">
						<input type="radio" name="points_icon" value="<?php echo esc_attr( $preset_key ); ?>" <?php checked( $points_icon, $preset_key ); ?> />
						<span class="spar-points-icon-swatch"><img src="<?php echo esc_url( $preset['url'] ?? '' ); ?>" alt="<?php echo esc_attr( $preset['label'] ?? $preset_key ); ?>" /></span>
					</label>
				<?php endforeach; ?>
				<label class="spar-points-icon-option" title="<?php esc_attr_e( 'Custom image', 'simple-points-and-rewards' ); ?>">
					<input type="radio" name="points_icon" value="custom" <?php checked( $points_icon, 'custom' ); ?> />
					<span class="spar-points-icon-swatch spar-points-icon-swatch--custom">
						<img src="<?php echo esc_url( $points_icon_url ); ?>" alt="" class="spar-points-icon-custom-preview"<?php echo $points_icon_has_custom_img ? '' : ' style="display:none;"'; ?> />
						<span class="spar-points-icon-custom-text"<?php echo $points_icon_has_custom_img ? ' style="display:none;"' : ''; ?>><?php esc_html_e( 'Custom', 'simple-points-and-rewards' ); ?></span>
					</span>
				</label>
			</div>
			<div class="spar-media-upload-field spar-points-icon-custom-row"<?php echo ( 'custom' === $points_icon ) ? '' : ' style="display:none;"'; ?>>
				<input type="url" name="points_icon_url" value="<?php echo esc_attr( $points_icon_url ); ?>" placeholder="https://example.com/coin.png" class="regular-text spar-points-icon-url-input" />
				<button type="button" class="button spar-media-upload-button"><?php esc_html_e( 'Upload / Select', 'simple-points-and-rewards' ); ?></button>
			</div>
			<p class="spar-points-icon-scope-row" style="margin-top:10px;<?php echo $points_icon_has || $points_icon_has_custom_img ? '' : 'display:none;'; ?>">
				<label><?php esc_html_e( 'Show icon on:', 'simple-points-and-rewards' ); ?>
					<select name="points_icon_display">
						<option value="prominent" <?php selected( $points_icon_display, 'prominent' ); ?>><?php esc_html_e( 'Prominent amounts (balances, product & cart totals)', 'simple-points-and-rewards' ); ?></option>
						<option value="all" <?php selected( $points_icon_display, 'all' ); ?>><?php esc_html_e( 'Every points value shown to customers', 'simple-points-and-rewards' ); ?></option>
					</select>
				</label>
			</p>
		</div>
	</div>
	<?php
}
