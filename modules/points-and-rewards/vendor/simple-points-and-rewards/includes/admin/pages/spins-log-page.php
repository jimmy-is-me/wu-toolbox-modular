<?php
/**
 * Spins Activity Log admin page
 *
 * Tracks every spin wheel event (spin result) and every time a user earns
 * bonus spins (order bonus, future sources).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class SPAR_Spins_Log_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct( [
			'singular' => 'spin_log',
			'plural'   => 'spin_logs',
			'ajax'     => false,
		] );
	}

	public function get_columns() {
		return [
			'cb'         => '<input type="checkbox" />',
			'user'       => esc_html__( 'User', 'simple-points-and-rewards' ),
			'event_type' => esc_html__( 'Event', 'simple-points-and-rewards' ),
			'description'=> esc_html__( 'Description', 'simple-points-and-rewards' ),
			'spins'      => esc_html__( 'Spins', 'simple-points-and-rewards' ),
			'date'       => esc_html__( 'Date', 'simple-points-and-rewards' ),
		];
	}

	public function get_sortable_columns() {
		return [
			'user'       => [ 'user', false ],
			'event_type' => [ 'event_type', false ],
			'spins'      => [ 'spins', false ],
			'date'       => [ 'date', true ],
		];
	}

	public function get_bulk_actions() {
		return [
			'delete' => esc_html__( 'Delete', 'simple-points-and-rewards' ),
		];
	}

	public function column_cb( $item ) {
		return sprintf(
			'<input type="checkbox" name="log_ids[]" value="%s" />',
			esc_attr( $item['id'] )
		);
	}

	public function process_bulk_action() {
		if ( ! $this->current_action() ) {
			return;
		}

		// WP_List_Table::display() outputs wp_nonce_field( "bulk-{$plural}" ) automatically.
		check_admin_referer( 'bulk-' . $this->_args['plural'] );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer.
		$log_ids = isset( $_POST['log_ids'] ) ? array_map( 'absint', (array) $_POST['log_ids'] ) : [];
		if ( empty( $log_ids ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'spar_spins_log';

		if ( 'delete' === $this->current_action() ) {
			foreach ( $log_ids as $log_id ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->delete( $table, [ 'id' => $log_id ], [ '%d' ] );
			}
			// Flush cached results so the table reflects the deletion.
			wp_cache_flush_group( 'spar' );
		}
	}

	public function prepare_items() {
		global $wpdb;

		$per_page     = 20;
		$current_page = $this->get_pagenum();
		$offset       = ( $current_page - 1 ) * $per_page;

		$orderby = filter_input( INPUT_GET, 'orderby', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) ?: 'date';
		$order   = filter_input( INPUT_GET, 'order',   FILTER_SANITIZE_FULL_SPECIAL_CHARS ) ?: 'DESC';

		$allowed_orderby = [ 'user', 'event_type', 'spins', 'date' ];
		if ( ! in_array( $orderby, $allowed_orderby, true ) ) {
			$orderby = 'date';
		}
		$order = strtoupper( $order ) === 'ASC' ? 'ASC' : 'DESC';

		$table = $wpdb->prefix . 'spar_spins_log';

		$conditions = [];
		$params     = [];

		// The filter form uses method="get", so read the nonce from $_GET.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified below.
		$nonce_raw   = isset( $_GET['spar_spins_log_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['spar_spins_log_nonce'] ) ) : '';
		$nonce_valid = $nonce_raw ? (bool) wp_verify_nonce( $nonce_raw, 'spar_spins_log_filters' ) : false;

		if ( $nonce_valid ) {
			$date_from = filter_input( INPUT_GET, 'date_from', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
			// Validate that the value is a real date before using it.
			if ( $date_from && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) && strtotime( $date_from ) ) {
				$conditions[] = ' AND l.date >= %s';
				$params[]     = $date_from . ' 00:00:00';
			}

			$date_to = filter_input( INPUT_GET, 'date_to', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
			if ( $date_to && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) && strtotime( $date_to ) ) {
				$conditions[] = ' AND l.date <= %s';
				$params[]     = $date_to . ' 23:59:59';
			}

			$username = filter_input( INPUT_GET, 'username', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
			if ( $username ) {
				$search_term  = '%' . $wpdb->esc_like( $username ) . '%';
				$conditions[] = ' AND (u.display_name LIKE %s OR u.user_login LIKE %s OR u.user_email LIKE %s)';
				$params[]     = $search_term;
				$params[]     = $search_term;
				$params[]     = $search_term;
			}

			$event_type_filter = filter_input( INPUT_GET, 'event_type_filter', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
			$allowed_events    = [ 'spin', 'bonus_earned' ];
			if ( in_array( $event_type_filter, $allowed_events, true ) ) {
				$conditions[] = ' AND l.event_type = %s';
				$params[]     = $event_type_filter;
			}
		}

		$where_dynamic = implode( '', $conditions );

		// COUNT query.
		$count_sql  = 'SELECT COUNT(*) FROM %i l LEFT JOIN ' . $wpdb->users . ' u ON l.user_id = u.ID WHERE 1=1' . $where_dynamic;
		$count_args = array_merge( [ $count_sql, $table ], $params );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$count_prepared = call_user_func_array( [ $wpdb, 'prepare' ], $count_args );

		$cache_group = 'spar';
		$count_key   = 'spar_spins_log_count_' . md5( $count_prepared );
		$total_items = wp_cache_get( $count_key, $cache_group );
		if ( false === $total_items ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$total_items = (int) $wpdb->get_var( $count_prepared );
			wp_cache_set( $count_key, $total_items, $cache_group, 60 );
		}

		// ORDER BY clause.
		$order_clause = ( 'user' === $orderby )
			? "ORDER BY u.display_name $order"
			: "ORDER BY l.$orderby $order";

		// Data query.
		$data_sql     = 'SELECT l.*, u.display_name AS user_name FROM %i l LEFT JOIN ' . $wpdb->users . ' u ON l.user_id = u.ID WHERE 1=1' . $where_dynamic . ' ' . $order_clause . ' LIMIT %d OFFSET %d';
		$data_args    = array_merge( [ $data_sql, $table ], $params, [ $per_page, $offset ] );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$data_prepared = call_user_func_array( [ $wpdb, 'prepare' ], $data_args );

		$results_key = 'spar_spins_log_results_' . md5( $data_prepared );
		$results     = wp_cache_get( $results_key, $cache_group );
		if ( false === $results ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$results = $wpdb->get_results( $data_prepared, ARRAY_A );
			wp_cache_set( $results_key, $results, $cache_group, 60 );
		}

		$this->items = $results ?: [];

		$this->set_pagination_args( [
			'total_items' => $total_items,
			'per_page'    => $per_page,
			'total_pages' => ceil( $total_items / $per_page ),
		] );

		$this->_column_headers = [ $this->get_columns(), [], $this->get_sortable_columns() ];
	}

	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'user':
				$display = esc_html( $item['user_name'] ?: __( 'Unknown user', 'simple-points-and-rewards' ) );
				if ( ! empty( $item['user_id'] ) ) {
					$detail_url = admin_url( 'admin.php?page=spar-customer&user_id=' . absint( $item['user_id'] ) );
					return '<a href="' . esc_url( $detail_url ) . '">' . $display . '</a>';
				}
				return $display;

			case 'event_type':
				if ( 'spin' === $item['event_type'] ) {
					return '<span class="spar-badge spar-badge--spin">' . esc_html__( 'Spin', 'simple-points-and-rewards' ) . '</span>';
				}
				return '<span class="spar-badge spar-badge--bonus">' . esc_html__( 'Bonus Earned', 'simple-points-and-rewards' ) . '</span>';

			case 'description':
				return esc_html( $item['description'] ?? '' );

			case 'spins':
				$spins = (int) ( $item['spins'] ?? 0 );
				if ( 'spin' === $item['event_type'] ) {
					// A spin uses one spin — show as negative (consumed).
					return '<span class="spar-negative">−1</span>';
				}
				return '<span class="spar-positive">+' . esc_html( (string) $spins ) . '</span>';

			case 'date':
				$ts = strtotime( $item['date'] );
				return $ts ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ) : '';

			default:
				return '';
		}
	}

	public function no_items() {
		esc_html_e( 'No spins activity found.', 'simple-points-and-rewards' );
	}
}

/**
 * Render the Spins Activity Log admin page.
 */
function spar_spins_log_page() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'simple-points-and-rewards' ) );
	}

	$log_table = new SPAR_Spins_Log_Table();
	$log_table->process_bulk_action();
	$log_table->prepare_items();

	// Collect filter values — nonce is in $_GET because the filter form uses method="get".
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified below.
	$nonce_raw         = isset( $_GET['spar_spins_log_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['spar_spins_log_nonce'] ) ) : '';
	$nonce_valid       = $nonce_raw ? (bool) wp_verify_nonce( $nonce_raw, 'spar_spins_log_filters' ) : false;
	$date_from         = $nonce_valid ? filter_input( INPUT_GET, 'date_from',         FILTER_SANITIZE_FULL_SPECIAL_CHARS ) : '';
	$date_to           = $nonce_valid ? filter_input( INPUT_GET, 'date_to',           FILTER_SANITIZE_FULL_SPECIAL_CHARS ) : '';
	$username          = $nonce_valid ? filter_input( INPUT_GET, 'username',          FILTER_SANITIZE_FULL_SPECIAL_CHARS ) : '';
	$event_type_filter = $nonce_valid ? filter_input( INPUT_GET, 'event_type_filter', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) : '';
	// Restrict event_type_filter to known values.
	if ( ! in_array( $event_type_filter, [ 'spin', 'bonus_earned' ], true ) ) {
		$event_type_filter = '';
	}
	$page_param = filter_input( INPUT_GET, 'page', FILTER_SANITIZE_FULL_SPECIAL_CHARS );

	$active_filters  = 0;
	$active_filters += ( '' !== (string) $date_from ) ? 1 : 0;
	$active_filters += ( '' !== (string) $date_to ) ? 1 : 0;
	$active_filters += ( '' !== (string) $username ) ? 1 : 0;
	$active_filters += ( '' !== (string) $event_type_filter ) ? 1 : 0;
	?>
	<div class="wrap">
		<?php spar_render_admin_header( esc_html__( 'Spins Activity Log', 'simple-points-and-rewards' ) ); ?>

		<p><?php esc_html_e( 'This page tracks every prize wheel spin and every time a customer earns bonus spins.', 'simple-points-and-rewards' ); ?></p>

		<!-- Filter Form -->
		<div class="spar-log-filters" style="background:#fff; border:1px solid #ccd0d4; padding:15px; margin:20px 0; border-radius:4px;">
			<h3 style="margin-top:0;"><?php esc_html_e( 'Filter Logs', 'simple-points-and-rewards' ); ?></h3>
			<form method="get" id="spar-spins-log-filter-form">
				<input type="hidden" name="page" value="<?php echo esc_attr( $page_param ); ?>" />
				<input type="hidden" name="spar_spins_log_nonce" value="<?php echo esc_attr( wp_create_nonce( 'spar_spins_log_filters' ) ); ?>" />

				<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:15px; margin-bottom:15px;">
					<div>
						<label for="date_from"><?php esc_html_e( 'Date From:', 'simple-points-and-rewards' ); ?></label>
						<input type="date" id="date_from" name="date_from" value="<?php echo esc_attr( $date_from ); ?>" style="width:100%;" />
					</div>
					<div>
						<label for="date_to"><?php esc_html_e( 'Date To:', 'simple-points-and-rewards' ); ?></label>
						<input type="date" id="date_to" name="date_to" value="<?php echo esc_attr( $date_to ); ?>" style="width:100%;" />
					</div>
					<div>
						<label for="username"><?php esc_html_e( 'Username/Email:', 'simple-points-and-rewards' ); ?></label>
						<input type="text" id="username" name="username" value="<?php echo esc_attr( $username ); ?>" placeholder="<?php esc_attr_e( 'Search by name or email', 'simple-points-and-rewards' ); ?>" style="width:100%;" />
					</div>
					<div>
						<label for="event_type_filter"><?php esc_html_e( 'Event Type:', 'simple-points-and-rewards' ); ?></label>
						<select id="event_type_filter" name="event_type_filter" style="width:100%;">
							<option value=""><?php esc_html_e( 'All Events', 'simple-points-and-rewards' ); ?></option>
							<option value="spin" <?php selected( $event_type_filter, 'spin' ); ?>><?php esc_html_e( 'Spin', 'simple-points-and-rewards' ); ?></option>
							<option value="bonus_earned" <?php selected( $event_type_filter, 'bonus_earned' ); ?>><?php esc_html_e( 'Bonus Earned', 'simple-points-and-rewards' ); ?></option>
						</select>
					</div>
				</div>

				<div style="display:flex; gap:10px; align-items:center;">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Apply Filters', 'simple-points-and-rewards' ); ?></button>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=spar-spins-log' ) ); ?>" class="button"><?php esc_html_e( 'Clear Filters', 'simple-points-and-rewards' ); ?></a>
					<?php if ( $active_filters > 0 ) : ?>
						<span style="color:#135e96; font-weight:600;">
							<?php
							printf(
								/* translators: %d: number of active filters */
								esc_html__( '%d filter(s) active', 'simple-points-and-rewards' ),
								(int) $active_filters
							);
							?>
						</span>
					<?php endif; ?>
				</div>
			</form>
		</div>

		<!-- Log Table -->
		<form method="post">
			<input type="hidden" name="page" value="<?php echo esc_attr( $page_param ); ?>" />
			<?php
			// Pass active filter values into the bulk-action POST form so pagination
			// and sorting keep context after a bulk delete.
			if ( '' !== (string) $date_from ) {
				echo '<input type="hidden" name="date_from" value="' . esc_attr( $date_from ) . '" />';
			}
			if ( '' !== (string) $date_to ) {
				echo '<input type="hidden" name="date_to" value="' . esc_attr( $date_to ) . '" />';
			}
			if ( '' !== (string) $username ) {
				echo '<input type="hidden" name="username" value="' . esc_attr( $username ) . '" />';
			}
			if ( '' !== (string) $event_type_filter ) {
				echo '<input type="hidden" name="event_type_filter" value="' . esc_attr( $event_type_filter ) . '" />';
			}
			?>
			<?php $log_table->display(); ?>
		</form>
	</div>
	<style>
		.spar-badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:600; }
		.spar-badge--spin   { background:#667eea; color:#fff; }
		.spar-badge--bonus  { background:#2ca58d; color:#fff; }
		.spar-positive { color:#2ca58d; font-weight:600; }
		.spar-negative { color:#e74c3c; font-weight:600; }
	</style>
	<?php
}
