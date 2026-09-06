<?php
/**
 * Template: Add or Remove Points panel.
 *
 * Renders the collapsible toolbar button and adjustment form.
 * Requires spar_customer_points_get_roles() to be available.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'spar_customer_points_get_roles' ) ) {
	return;
}

$spar_panel_roles = spar_customer_points_get_roles();
?>
<div class="spar-customer-points-toolbar">
	<button type="button" class="button button-primary" id="spar-add-new-points-toggle"
	aria-expanded="false" aria-controls="spar-add-new-points-panel">
		<?php esc_html_e( 'Add or Remove Points', 'simple-points-and-rewards' ); ?> +
	</button>
</div>
<div id="spar-add-new-points-panel" class="spar-add-new-points-panel" hidden>
	<p class="description" style="margin-bottom: 1em;"><?php esc_html_e( 'Manually add or remove points for a specific user, or adjust all users at once (with an optional role filter). Bulk operations open a live progress view with per-row undo controls.', 'simple-points-and-rewards' ); ?></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="spar-add-new-points-form">
		<?php wp_nonce_field( 'spar_customer_points_adjustment', 'spar_customer_points_adjustment_nonce' ); ?>
		<input type="hidden" name="action" value="spar_customer_points_adjustment" />
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><label for="spar_points_target"><?php esc_html_e( 'Apply To', 'simple-points-and-rewards' ); ?></label></th>
					<td>
						<select name="spar_points_target" id="spar_points_target" class="regular-text">
							<option value="specific"><?php esc_html_e( 'Specific User', 'simple-points-and-rewards' ); ?></option>
							<option value="all"><?php esc_html_e( 'All Users', 'simple-points-and-rewards' ); ?></option>
						</select>
					</td>
				</tr>
				<tr class="spar-add-points-specific-row">
					<th scope="row"><label for="spar_points_username"><?php esc_html_e( 'Username', 'simple-points-and-rewards' ); ?></label></th>
					<td>
						<input type="text" name="spar_points_username" id="spar_points_username" class="regular-text" autocomplete="off" />
					</td>
				</tr>
				<tr class="spar-add-points-all-row" hidden>
					<th scope="row"><label for="spar_points_user_role"><?php esc_html_e( 'User Role', 'simple-points-and-rewards' ); ?></label></th>
					<td>
						<select name="spar_points_user_role" id="spar_points_user_role" class="regular-text">
							<option value=""><?php esc_html_e( 'All Roles', 'simple-points-and-rewards' ); ?></option>
							<?php foreach ( $spar_panel_roles as $spar_panel_role_key => $spar_panel_role_name ) : ?>
								<option value="<?php echo esc_attr( $spar_panel_role_key ); ?>"><?php echo esc_html( $spar_panel_role_name ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="spar_points_change"><?php esc_html_e( 'Points Change', 'simple-points-and-rewards' ); ?></label></th>
					<td>
						<input type="number" name="spar_points_change" id="spar_points_change" class="regular-text" step="1" required />
						<p class="description"><?php esc_html_e( 'Enter a positive number to add points or a negative number to remove points.', 'simple-points-and-rewards' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Activity Log', 'simple-points-and-rewards' ); ?></th>
					<td>
						<label for="spar_points_log_activity">
							<input type="checkbox" name="spar_points_log_activity" id="spar_points_log_activity" value="1" checked />
							<?php esc_html_e( 'Show in points activity log', 'simple-points-and-rewards' ); ?>
						</label>
					</td>
				</tr>
			</tbody>
		</table>
		<p class="submit">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Submit', 'simple-points-and-rewards' ); ?></button>
		</p>
	</form>
</div>
