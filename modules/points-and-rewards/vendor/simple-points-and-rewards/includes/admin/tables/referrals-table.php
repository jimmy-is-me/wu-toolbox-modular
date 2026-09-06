<?php
/**
 * Referrals Clicks List Table
 */
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class SPAR_Referrals_Clicks_List_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct([
			'singular' => 'referral_click',
			'plural'   => 'referral_clicks',
			'ajax'     => false
		]);
	}

	public function get_columns() {
		return [
			'cb'           => '<input type="checkbox" />',
			'created_at'   => esc_html__( 'Date', 'simple-points-and-rewards' ),
			'referral_code'=> esc_html__( 'Referral Code', 'simple-points-and-rewards' ),
			'referrer'     => esc_html__( 'Referrer', 'simple-points-and-rewards' ),
			'referree'     => esc_html__( 'Customer', 'simple-points-and-rewards' ),
			'landing_url'  => esc_html__( 'Landing Page', 'simple-points-and-rewards' ),
			'referring_domain' => esc_html__( 'Referring Domain', 'simple-points-and-rewards' ),
			'converted'    => esc_html__( 'Converted', 'simple-points-and-rewards' ),
		];
	}

	public function get_sortable_columns() {
		return [
			'created_at'        => [ 'created_at', true ],
			'referral_code'     => [ 'referral_code', false ],
			'referring_domain'  => [ 'referring_domain', false ],
			'converted'         => [ 'converted', false ],
		];
	}

	public function prepare_items() {
		global $wpdb;
		$table = $wpdb->prefix . 'spar_referral_clicks';

		$per_page     = 20;
		$current_page = $this->get_pagenum();
		$offset       = ( $current_page - 1 ) * $per_page;

		$orderby = filter_input( INPUT_GET, 'orderby', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		$order   = filter_input( INPUT_GET, 'order', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		$orderby = $orderby ? $orderby : 'created_at';
		$order   = $order && strtoupper( $order ) === 'ASC' ? 'ASC' : 'DESC';

		$allowed_orderby = [ 'created_at', 'referral_code', 'referring_domain', 'converted' ];
		if ( ! in_array( $orderby, $allowed_orderby, true ) ) {
			$orderby = 'created_at';
		}

		// Filters (read-only; nonce not required)
	// Read filters from request (supports GET and POST)
	$search     = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
	$status     = isset( $_REQUEST['converted'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['converted'] ) ) : '';
	$date_from  = isset( $_REQUEST['date_from'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['date_from'] ) ) : '';
	$date_to    = isset( $_REQUEST['date_to'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['date_to'] ) ) : '';
	$referrer_q = isset( $_REQUEST['referrer'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['referrer'] ) ) : '';
	$customer_q = isset( $_REQUEST['customer'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['customer'] ) ) : '';
	$ref_domain = isset( $_REQUEST['referring_domain'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['referring_domain'] ) ) : '';

		$conditions = array();
		$params    = array();
		// Search across multiple columns
		if ( $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$conditions[] = ' AND (c.referral_code LIKE %s OR c.landing_url LIKE %s OR c.referral_link LIKE %s OR c.referring_domain LIKE %s)';
			$params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
		}
		// Converted status (static fragments)
		if ( 'yes' === $status ) {
			$conditions[] = ' AND c.converted = 1';
		} elseif ( 'no' === $status ) {
			$conditions[] = ' AND c.converted = 0';
		}
		// Date range
		if ( $date_from && strtotime( $date_from ) ) {
			$conditions[] = ' AND c.created_at >= %s';
			$params[]     = $date_from . ' 00:00:00';
		}
		if ( $date_to && strtotime( $date_to ) ) {
			$conditions[] = ' AND c.created_at <= %s';
			$params[]     = $date_to . ' 23:59:59';
		}
		// Referring domain
		if ( $ref_domain ) {
			$like = '%' . $wpdb->esc_like( $ref_domain ) . '%';
			$conditions[] = ' AND c.referring_domain LIKE %s';
			$params[]     = $like;
		}
		// Referrer (ID or LIKE)
		if ( $referrer_q ) {
			$maybe_id = absint( $referrer_q );
			if ( $maybe_id > 0 ) {
				$conditions[] = ' AND c.referrer_user_id = %d';
				$params[]     = $maybe_id;
			} else {
				$like = '%' . $wpdb->esc_like( $referrer_q ) . '%';
				$conditions[] = ' AND (u1.display_name LIKE %s OR u1.user_email LIKE %s)';
				$params[]     = $like; $params[] = $like;
			}
		}
		// Customer (ID or LIKE)
		if ( $customer_q ) {
			$maybe_id = absint( $customer_q );
			if ( $maybe_id > 0 ) {
				$conditions[] = ' AND c.referree_user_id = %d';
				$params[]     = $maybe_id;
			} else {
				$like = '%' . $wpdb->esc_like( $customer_q ) . '%';
				$conditions[] = ' AND (u2.display_name LIKE %s OR u2.user_email LIKE %s)';
				$params[]     = $like; $params[] = $like;
			}
		}
		$where_dynamic = implode( '', $conditions );
		// Count query
		$count_sql_base = 'SELECT COUNT(*) FROM %i c WHERE 1=1' . $where_dynamic;
		$count_args     = array_merge( array( $count_sql_base, $table ), $params );
		$count_sql      = call_user_func_array( array( $wpdb, 'prepare' ), $count_args );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$total_items    = (int) $wpdb->get_var( $count_sql );
		// Data query
		$data_sql_base = 'SELECT c.*, u1.display_name AS referrer_name, u2.display_name AS referree_name FROM %i c LEFT JOIN %i u1 ON c.referrer_user_id = u1.ID LEFT JOIN %i u2 ON c.referree_user_id = u2.ID WHERE 1=1' . $where_dynamic . ' ORDER BY c.' . $orderby . ' ' . $order . ' LIMIT %d OFFSET %d';
		$data_args      = array_merge( array( $data_sql_base, $table, $wpdb->users, $wpdb->users ), $params, array( $per_page, $offset ) );
		$data_sql       = call_user_func_array( array( $wpdb, 'prepare' ), $data_args );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows           = $wpdb->get_results( $data_sql, ARRAY_A );

		$this->items = $rows;
		$this->set_pagination_args([
			'total_items' => $total_items,
			'per_page'    => $per_page,
			'total_pages' => ceil( $total_items / $per_page ),
		]);

		$this->_column_headers = [ $this->get_columns(), [], $this->get_sortable_columns() ];
	}

	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'created_at':
				return esc_html( $item['created_at'] );
			case 'referral_code':
				return esc_html( $item['referral_code'] );
			case 'referrer':
				if ( ! empty( $item['referrer_user_id'] ) ) {
					$url = get_edit_user_link( (int) $item['referrer_user_id'] );
					$name = $item['referrer_name'] ? $item['referrer_name'] : sprintf( '#%d', (int) $item['referrer_user_id'] );
					return '<a href="' . esc_url( $url ) . '">' . esc_html( $name ) . '</a>';
				}
				return esc_html__( 'Unknown', 'simple-points-and-rewards' );
			case 'referree':
				if ( ! empty( $item['referree_user_id'] ) ) {
					$url = get_edit_user_link( (int) $item['referree_user_id'] );
					$name = $item['referree_name'] ? $item['referree_name'] : sprintf( '#%d', (int) $item['referree_user_id'] );
					return '<a href="' . esc_url( $url ) . '">' . esc_html( $name ) . '</a>';
				}
				return esc_html__( 'Guest', 'simple-points-and-rewards' );
			case 'landing_url':
				return $this->format_landing_page_text( $item['landing_url'] );
			case 'referring_domain':
				if ( empty( $item['referring_domain'] ) ) {
					return '&mdash;';
				}
				return esc_html( $item['referring_domain'] );
			case 'converted':
				if ( ! empty( $item['converted'] ) ) {
					$output = '<span class="status-success">' . esc_html__( 'Yes', 'simple-points-and-rewards' ) . '</span>';
					$details = array();
					if ( ! empty( $item['order_id'] ) ) {
						$order = wc_get_order( (int) $item['order_id'] );
						if ( $order ) {
							$link = admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' );
							$details[] = sprintf( '<a href="%s">%s</a>', esc_url( $link ), '#' . esc_html( $order->get_id() ) );
						}
					}
					if ( ! empty( $item['gift_coupon_code'] ) ) {
						$coupon_text = esc_html( $item['gift_coupon_code'] );
						$coupon_id = function_exists( 'wc_get_coupon_id_by_code' ) ? wc_get_coupon_id_by_code( $item['gift_coupon_code'] ) : 0;
						if ( $coupon_id ) {
							$link = admin_url( 'post.php?post=' . absint( $coupon_id ) . '&action=edit' );
							$coupon_text = '<a href="' . esc_url( $link ) . '">' . esc_html( $item['gift_coupon_code'] ) . '</a>';
						}
						$details[] = $coupon_text;
					}
					if ( $details ) {
						$output .= '<br /><small>' . esc_html__( 'Order/Coupon:', 'simple-points-and-rewards' ) . ' ' . wp_kses_post( implode( ' &middot; ', $details ) ) . '</small>';
					}
					return $output;
				}
				return esc_html__( 'No', 'simple-points-and-rewards' );
		}
		return '';
	}

	private function format_url_cell( $url ) {
		if ( empty( $url ) ) return '&mdash;';
		$short = ( strlen( $url ) > 60 ) ? substr( $url, 0, 57 ) . '…' : $url;
		return '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $short ) . '</a>';
	}

	/**
	 * Show landing page as plain text without scheme or query params.
	 */
	private function format_landing_page_text( $url ) {
		if ( empty( $url ) ) {
			return '&mdash;';
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			// Fallback to raw with no scheme trimming
			$raw = preg_replace( '#^https?://#i', '', $url );
			$raw = rtrim( (string) $raw, '/' );
			return esc_html( $raw );
		}
		$host = isset( $parts['host'] ) ? $parts['host'] : '';
		$path = isset( $parts['path'] ) ? $parts['path'] : '';
		$display = $host . $path;
		// Remove any trailing slash
		$display = function_exists( 'untrailingslashit' ) ? untrailingslashit( $display ) : rtrim( (string) $display, '/' );
		if ( $display === '' ) {
			$display = isset( $parts['path'] ) ? ltrim( (string) $parts['path'], '/' ) : '';
			$display = rtrim( $display, '/' );
		}
		return esc_html( $display );
	}

	/**
	 * Checkbox column for bulk actions.
	 */
	public function column_cb( $item ) {
		$id = isset( $item['id'] ) ? (int) $item['id'] : 0;
		return '<input type="checkbox" name="id[]" value="' . esc_attr( $id ) . '" />';
	}

	/**
	 * Bulk actions for the table.
	 */
	protected function get_bulk_actions() {
		return [
			'delete' => esc_html__( 'Delete', 'simple-points-and-rewards' ),
		];
	}

	/**
	 * Handle bulk delete action.
	 */
	public function process_bulk_action() {
		if ( 'delete' !== $this->current_action() ) {
			return;
		}
		check_admin_referer( 'bulk-' . $this->_args['plural'] );
		$raw_ids = array();
		if ( isset( $_REQUEST['id'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above via check_admin_referer.
			$raw_ids = $_REQUEST['id'];
			if ( function_exists( 'wp_unslash' ) ) {
				$raw_ids = call_user_func( 'wp_unslash', $raw_ids );
			}
		}
		$ids = array_filter( array_map( 'absint', (array) $raw_ids ) );
		if ( empty( $ids ) ) {
			return;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'spar_referral_clicks';
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$sql = 'DELETE FROM %i WHERE id IN (' . $placeholders . ')';
		$delete_args = array_merge( array( $sql, $table ), $ids );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query( call_user_func_array( array( $wpdb, 'prepare' ), $delete_args ) );
	}
}
