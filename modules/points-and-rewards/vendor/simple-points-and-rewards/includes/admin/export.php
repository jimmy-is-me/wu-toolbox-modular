<?php
/**
 * Admin CSV exports.
 *
 * Streams customer points balances and the points activity log as CSV downloads
 * via admin-post.php. Both exports are capability- and nonce-gated and stream in
 * batches so they stay memory-safe on large stores.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Verify capability + nonce for an export request, or die.
 *
 * @param string $nonce_action Nonce action name.
 */
function spar_export_verify_request( $nonce_action ) {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( esc_html__( 'You do not have permission to export this data.', 'simple-points-and-rewards' ) );
	}

	$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, $nonce_action ) ) {
		wp_die( esc_html__( 'Security check failed. Please go back and try the export again.', 'simple-points-and-rewards' ) );
	}
}

/**
 * Send CSV download headers and return a php://output handle.
 *
 * @param string $filename Download filename.
 * @return resource
 */
function spar_export_open_csv_stream( $filename ) {
	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );

	$handle = fopen( 'php://output', 'w' );
	// UTF-8 BOM so Excel detects the encoding correctly.
	fwrite( $handle, "\xEF\xBB\xBF" );

	return $handle;
}

/**
 * Neutralise spreadsheet formula injection for user-influenced text cells.
 *
 * @param string $value Raw cell value.
 * @return string
 */
function spar_export_escape_csv_cell( $value ) {
	$value = (string) $value;
	if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
		$value = "'" . $value;
	}

	return $value;
}

/**
 * Export customer points balances as CSV.
 *
 * Includes every user that has a points balance or lifetime-earned record.
 */
function spar_handle_export_customer_points() {
	spar_export_verify_request( 'spar_export_customer_points' );

	global $wpdb;

	$handle = spar_export_open_csv_stream( 'customer-points-' . gmdate( 'Y-m-d' ) . '.csv' );
	fputcsv( $handle, array( 'user_id', 'username', 'email', 'display_name', 'points_balance', 'lifetime_earned' ) );

	$batch_size = 500;
	$last_id    = 0;

	do {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batched streaming export.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT u.ID, u.user_login, u.user_email, u.display_name,
					MAX(CASE WHEN um.meta_key = %s THEN um.meta_value END) AS balance,
					MAX(CASE WHEN um.meta_key = %s THEN um.meta_value END) AS lifetime
				FROM %i u
				INNER JOIN %i um ON um.user_id = u.ID AND um.meta_key IN (%s, %s)
				WHERE u.ID > %d
				GROUP BY u.ID, u.user_login, u.user_email, u.display_name
				ORDER BY u.ID ASC
				LIMIT %d",
				'_spar_points',
				'_spar_total_earned_points',
				$wpdb->users,
				$wpdb->usermeta,
				'_spar_points',
				'_spar_total_earned_points',
				$last_id,
				$batch_size
			),
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$last_id = (int) $row['ID'];
			fputcsv(
				$handle,
				array(
					(int) $row['ID'],
					spar_export_escape_csv_cell( $row['user_login'] ),
					spar_export_escape_csv_cell( $row['user_email'] ),
					spar_export_escape_csv_cell( $row['display_name'] ),
					(int) $row['balance'],
					(int) $row['lifetime'],
				)
			);
		}
	} while ( count( (array) $rows ) === $batch_size );

	fclose( $handle );
	exit;
}
add_action( 'admin_post_spar_export_customer_points', 'spar_handle_export_customer_points' );

/**
 * Export the points activity log as CSV.
 *
 * Honours the same GET filters as the Points Activity Log screen (date range,
 * username/email, action text, points range, type) so the download matches the
 * filtered view. Pending (delayed) points are not part of the ledger and are
 * not included.
 */
function spar_handle_export_points_log() {
	spar_export_verify_request( 'spar_export_points_log' );

	global $wpdb;

	$table      = $wpdb->prefix . 'spar_points_logs';
	$conditions = array();
	$params     = array();

	$date_from = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '';
	if ( $date_from && strtotime( $date_from ) ) {
		$conditions[] = ' AND l.date >= %s';
		$params[]     = $date_from . ' 00:00:00';
	}

	$date_to = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '';
	if ( $date_to && strtotime( $date_to ) ) {
		$conditions[] = ' AND l.date <= %s';
		$params[]     = $date_to . ' 23:59:59';
	}

	$username = isset( $_GET['username'] ) ? sanitize_text_field( wp_unslash( $_GET['username'] ) ) : '';
	if ( '' !== $username ) {
		$search_term  = '%' . $wpdb->esc_like( $username ) . '%';
		$conditions[] = ' AND (u.display_name LIKE %s OR u.user_login LIKE %s OR u.user_email LIKE %s)';
		$params[]     = $search_term;
		$params[]     = $search_term;
		$params[]     = $search_term;
	}

	$action_filter = isset( $_GET['action_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['action_filter'] ) ) : '';
	if ( '' !== $action_filter ) {
		$conditions[] = ' AND l.action LIKE %s';
		$params[]     = '%' . $wpdb->esc_like( $action_filter ) . '%';
	}

	$min_points = isset( $_GET['min_points'] ) ? sanitize_text_field( wp_unslash( $_GET['min_points'] ) ) : '';
	if ( '' !== $min_points && is_numeric( $min_points ) ) {
		$conditions[] = ' AND ABS(l.points) >= %d';
		$params[]     = (int) $min_points;
	}

	$max_points = isset( $_GET['max_points'] ) ? sanitize_text_field( wp_unslash( $_GET['max_points'] ) ) : '';
	if ( '' !== $max_points && is_numeric( $max_points ) ) {
		$conditions[] = ' AND ABS(l.points) <= %d';
		$params[]     = (int) $max_points;
	}

	$type_filter = isset( $_GET['type_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['type_filter'] ) ) : '';
	if ( in_array( $type_filter, array( 'add', 'remove' ), true ) ) {
		$conditions[] = ' AND l.type = %s';
		$params[]     = $type_filter;
	}

	$where_dynamic = implode( '', $conditions );

	$handle = spar_export_open_csv_stream( 'points-activity-log-' . gmdate( 'Y-m-d' ) . '.csv' );
	fputcsv( $handle, array( 'log_id', 'date', 'user_id', 'username', 'email', 'action', 'type', 'points', 'action_id' ) );

	$batch_size = 1000;
	$last_id    = 0;

	do {
		$sql_base = 'SELECT l.id, l.user_id, l.action, l.type, l.points, l.date, l.action_id, u.user_login, u.user_email FROM %i l LEFT JOIN ' . $wpdb->users . ' u ON l.user_id = u.ID WHERE l.id > %d' . $where_dynamic . ' ORDER BY l.id ASC LIMIT %d';
		$sql_args = array_merge( array( $sql_base, $table, $last_id ), $params, array( $batch_size ) );
		$sql      = call_user_func_array( array( $wpdb, 'prepare' ), $sql_args );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Batched streaming export, fully prepared above.
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		foreach ( (array) $rows as $row ) {
			$last_id = (int) $row['id'];
			fputcsv(
				$handle,
				array(
					(int) $row['id'],
					$row['date'],
					(int) $row['user_id'],
					spar_export_escape_csv_cell( (string) $row['user_login'] ),
					spar_export_escape_csv_cell( (string) $row['user_email'] ),
					spar_export_escape_csv_cell( (string) $row['action'] ),
					$row['type'],
					(int) $row['points'],
					spar_export_escape_csv_cell( (string) $row['action_id'] ),
				)
			);
		}
	} while ( count( (array) $rows ) === $batch_size );

	fclose( $handle );
	exit;
}
add_action( 'admin_post_spar_export_points_log', 'spar_handle_export_points_log' );

/**
 * Build the nonce-protected export URL for the points activity log,
 * carrying over the current filter values.
 *
 * @param array $filters Current filter values keyed by query arg name.
 * @return string
 */
function spar_export_points_log_url( $filters = array() ) {
	$args = array( 'action' => 'spar_export_points_log' );

	foreach ( array( 'date_from', 'date_to', 'username', 'action_filter', 'min_points', 'max_points', 'type_filter' ) as $key ) {
		if ( isset( $filters[ $key ] ) && '' !== (string) $filters[ $key ] ) {
			$args[ $key ] = (string) $filters[ $key ];
		}
	}

	return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), 'spar_export_points_log' );
}
