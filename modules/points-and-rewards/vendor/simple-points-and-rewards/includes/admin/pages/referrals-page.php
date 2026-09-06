<?php
/**
 * Referrals admin page
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function spar_referrals_admin_page() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'simple-points-and-rewards' ) );
	}

	$list_table = new SPAR_Referrals_Clicks_List_Table();
	// Handle bulk actions (e.g., delete) before preparing items
	$list_table->process_bulk_action();
	$list_table->prepare_items();

	// Read-only current filters for form defaults
	$search    = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
	$converted = isset( $_REQUEST['converted'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['converted'] ) ) : '';
	$date_from = isset( $_REQUEST['date_from'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['date_from'] ) ) : '';
	$date_to   = isset( $_REQUEST['date_to'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['date_to'] ) ) : '';
	?>
	<div class="wrap">
		<?php if ( function_exists( 'spar_render_admin_header' ) ) { spar_render_admin_header( esc_html__( 'Referral Link Clicks', 'simple-points-and-rewards' ) ); } ?>

		<form method="post">
			<input type="hidden" name="page" value="spar-referrals" />
			<p class="search-box">
				<label class="screen-reader-text" for="post-search-input"><?php esc_html_e( 'Search', 'simple-points-and-rewards' ); ?></label>
				<input type="search" id="post-search-input" name="s" value="<?php echo esc_attr( $search ); ?>" />
				<?php submit_button( esc_html__( 'Search', 'simple-points-and-rewards' ), 'button', '', false ); ?>
			</p>

			<div style="margin:8px 0; display:flex; gap:10px; align-items:center; flex-wrap: wrap;">
				<label>
					<?php esc_html_e( 'Converted', 'simple-points-and-rewards' ); ?>
					<select name="converted">
						<option value="">—</option>
						<option value="yes" <?php selected( $converted, 'yes' ); ?>><?php esc_html_e( 'Yes', 'simple-points-and-rewards' ); ?></option>
						<option value="no" <?php selected( $converted, 'no' ); ?>><?php esc_html_e( 'No', 'simple-points-and-rewards' ); ?></option>
					</select>
				</label>
				<label>
					<?php esc_html_e( 'From', 'simple-points-and-rewards' ); ?>
					<input type="date" name="date_from" value="<?php echo esc_attr( $date_from ); ?>" />
				</label>
				<label>
					<?php esc_html_e( 'To', 'simple-points-and-rewards' ); ?>
					<input type="date" name="date_to" value="<?php echo esc_attr( $date_to ); ?>" />
				</label>
				<label>
					<?php esc_html_e( 'Referrer', 'simple-points-and-rewards' ); ?>
					<input type="text" name="referrer" placeholder="<?php esc_attr_e( 'ID, name, or email', 'simple-points-and-rewards' ); ?>" value="<?php echo isset( $_REQUEST['referrer'] ) ? esc_attr( wp_unslash( $_REQUEST['referrer'] ) ) : ''; ?>" />
				</label>
				<label>
					<?php esc_html_e( 'Customer', 'simple-points-and-rewards' ); ?>
					<input type="text" name="customer" placeholder="<?php esc_attr_e( 'ID, name, or email', 'simple-points-and-rewards' ); ?>" value="<?php echo isset( $_REQUEST['customer'] ) ? esc_attr( wp_unslash( $_REQUEST['customer'] ) ) : ''; ?>" />
				</label>
				<label>
					<?php esc_html_e( 'Referring Domain', 'simple-points-and-rewards' ); ?>
					<input type="text" name="referring_domain" placeholder="example.com" value="<?php echo isset( $_REQUEST['referring_domain'] ) ? esc_attr( wp_unslash( $_REQUEST['referring_domain'] ) ) : ''; ?>" />
				</label>
				<?php submit_button( esc_html__( 'Filter', 'simple-points-and-rewards' ), 'secondary', '', false ); ?>
			</div>

			<?php $list_table->display(); ?>
		</form>
	</div>
	<?php
}
