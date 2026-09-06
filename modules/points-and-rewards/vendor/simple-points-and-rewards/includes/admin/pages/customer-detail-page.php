<?php
/**
 * Customer detail admin page
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function spar_customer_detail_admin_page_callback() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'simple-points-and-rewards' ) );
	}

	$user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
	$nonce   = isset( $_GET['nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : '';

	if ( ! $user_id || ! $nonce || ! wp_verify_nonce( $nonce, 'spar_customer_detail_view_' . $user_id ) ) {
		wp_die( esc_html__( 'Invalid request.', 'simple-points-and-rewards' ) );
	}

	$user = get_userdata( $user_id );
	if ( ! $user ) {
		wp_die( esc_html__( 'Customer not found.', 'simple-points-and-rewards' ) );
	}

	$points_balance = (float) get_user_meta( $user_id, '_spar_points', true );
	$total_earned   = function_exists( 'spar_get_user_total_points_earned' ) ? (int) spar_get_user_total_points_earned( $user_id ) : 0;

	global $wpdb;
	$points_log_table = $wpdb->prefix . 'spar_points_logs';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$total_redeemed = (int) $wpdb->get_var(
		$wpdb->prepare(
			'SELECT COALESCE(SUM(points),0) FROM %i WHERE user_id = %d AND type = %s AND action_id = %s',
			$points_log_table,
			$user_id,
			'remove',
			'redeem'
		)
	);

	$referral_stats = get_user_meta( $user_id, 'spar_referral_stats', true );
	if ( ! is_array( $referral_stats ) ) {
		$referral_stats = array(
			'successful_referrals' => 0,
			'total_points_earned'  => 0,
			'total_clicks'         => 0,
		);
	}

	$referral_code = get_user_meta( $user_id, 'spar_referral_code', true );
	if ( empty( $referral_code ) ) {
		$referral_code = function_exists( 'spar_get_user_referral_code' ) ? spar_get_user_referral_code( $user_id ) : '';
	}

	$account_status = get_user_meta( $user_id, 'spar_user_status', true );
	$account_status = ( 'banned' === $account_status ) ? 'banned' : 'active';

	$last_active_raw = get_user_meta( $user_id, 'spar_last_active', true );
	$last_active_ts  = 0;
	if ( '' !== $last_active_raw && null !== $last_active_raw && false !== $last_active_raw ) {
		if ( function_exists( 'spar_points_expiry_normalize_timestamp' ) ) {
			$last_active_ts = spar_points_expiry_normalize_timestamp( $last_active_raw );
		} elseif ( is_numeric( $last_active_raw ) ) {
			$last_active_ts = (int) $last_active_raw;
		} else {
			$parsed = strtotime( (string) $last_active_raw );
			$last_active_ts = $parsed ? (int) $parsed : 0;
		}
	}

	$total_spend = 0.0;
	$last_order  = null;
	if ( function_exists( 'wc_get_customer_total_spent' ) ) {
		$total_spend = (float) wc_get_customer_total_spent( $user_id );
	}
	if ( function_exists( 'wc_get_customer_last_order' ) ) {
		$last_order = wc_get_customer_last_order( $user_id );
	}

	$recent_orders = array();
	$orders_pagination = array();
	if ( function_exists( 'wc_get_orders' ) ) {
		$orders_query = wc_get_orders(
			array(
				'customer_id' => $user_id,
				'limit'       => 10,
				'orderby'     => 'date',
				'order'       => 'DESC',
				'paginate'    => true,
				'paged'       => 1,
			)
		);
		if ( is_object( $orders_query ) ) {
			$recent_orders = $orders_query->orders;
			$total_pages  = (int) $orders_query->max_num_pages;
			$orders_pagination = array(
				'current_page' => 1,
				'per_page' => 10,
				'total_count' => (int) $orders_query->total,
				'total_pages' => $total_pages,
				'has_previous' => false,
				'has_next' => $total_pages > 1,
			);
		}
	}

	$points_label = function_exists( 'spar_get_option' ) ? spar_get_option( '', 'points_label' ) : '';
	$points_label = $points_label ? $points_label : esc_html__( 'Points', 'simple-points-and-rewards' );

	$referral_revenue = 0.0;
	$referral_orders  = 0;
	if ( $referral_code && function_exists( 'wc_get_orders' ) ) {
		global $wpdb;
		if ( function_exists( 'wc_get_container' ) ) {
			// HPOS: aggregate from wc_orders + wc_orders_meta.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT COUNT(*) AS cnt, COALESCE(SUM(o.total_amount),0) AS total
				 FROM {$wpdb->prefix}wc_orders o
				 INNER JOIN {$wpdb->prefix}wc_orders_meta om ON om.order_id = o.id AND om.meta_key = 'referrer_code' AND om.meta_value = %s
				 WHERE o.type = 'shop_order'",
				$referral_code
			) );
			if ( $row ) {
				$referral_orders  = (int) $row->cnt;
				$referral_revenue = (float) $row->total;
			}
		} else {
			// Legacy: aggregate from posts + postmeta.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT COUNT(*) AS cnt, COALESCE(SUM(ot.meta_value),0) AS total
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} rc ON rc.post_id = p.ID AND rc.meta_key = 'referrer_code' AND rc.meta_value = %s
				 LEFT JOIN {$wpdb->postmeta} ot ON ot.post_id = p.ID AND ot.meta_key = '_order_total'
				 WHERE p.post_type = 'shop_order'",
				$referral_code
			) );
			if ( $row ) {
				$referral_orders  = (int) $row->cnt;
				$referral_revenue = (float) $row->total;
			}
		}
	}

	$referrer_id = (int) get_user_meta( $user_id, 'spar_referrer_user_id', true );
	$referrer    = $referrer_id ? get_userdata( $referrer_id ) : null;

	$points_history = function_exists( 'spar_get_user_points_history' ) ? spar_get_user_points_history( $user_id, 1, 10 ) : array( 'logs' => array(), 'pagination' => array() );
	$points_logs    = $points_history['logs'] ?? array();
	$points_pagination = $points_history['pagination'] ?? array();

	$referral_history = function_exists( 'spar_get_user_referral_clicks_history_full' )
		? spar_get_user_referral_clicks_history_full( $user_id, 1, 10 )
		: array( 'logs' => array(), 'pagination' => array() );
	$referral_clicks = $referral_history['logs'] ?? array();
	$referral_pagination = $referral_history['pagination'] ?? array();

	$registered_date = $user->user_registered ? date_i18n( get_option( 'date_format' ), strtotime( $user->user_registered ) ) : '';
	$last_active_display = $last_active_ts ? date_i18n( get_option( 'date_format' ), $last_active_ts ) : esc_html__( 'Not recorded', 'simple-points-and-rewards' );
	$last_order_date = '';
	if ( $last_order && $last_order->get_date_created() ) {
		$last_order_date = $last_order->get_date_created()->date_i18n( get_option( 'date_format' ) );
	}

	$back_url = admin_url( 'admin.php?page=spar-customer-points' );
	$edit_url = get_edit_user_link( $user_id );
	$points_nonce = wp_create_nonce( 'spar_update_points_nonce' );
	$status_nonce = wp_create_nonce( 'spar_update_user_status' );

	$orders_url = '';
	if ( function_exists( 'wc_get_page_id' ) ) {
		$orders_url = admin_url( 'edit.php?post_type=shop_order&customer_id=' . $user_id );
	}

	?>
	<div class="wrap spar-customer-detail">
		<?php if ( function_exists( 'spar_render_admin_header' ) ) { spar_render_admin_header( esc_html__( 'Customer Overview', 'simple-points-and-rewards' ) ); } ?>

		<div class="spar-customer-detail__toolbar">
			<a class="button" href="<?php echo esc_url( $back_url ); ?>">&larr; <?php esc_html_e( 'Back to Customer Points', 'simple-points-and-rewards' ); ?></a>
		</div>

		<div class="spar-customer-layout">
			<div class="spar-customer-main">
				<div class="spar-customer-hero">
					<div class="spar-customer-hero__profile">
						<?php echo wp_kses_post( get_avatar( $user_id, 72 ) ); ?>
						<div class="spar-customer-hero__meta">
							<h1><?php echo esc_html( $user->display_name ); ?></h1>
							<p><?php echo esc_html( $user->user_email ); ?></p>
						</div>
					</div>
					<div class="spar-customer-hero__right">
						<div class="spar-user-status-wrapper" data-user-id="<?php echo esc_attr( $user_id ); ?>" data-nonce="<?php echo esc_attr( $status_nonce ); ?>">
							<label class="spar-user-status-label" for="spar-user-status-<?php echo esc_attr( $user_id ); ?>"><?php esc_html_e( 'Status', 'simple-points-and-rewards' ); ?></label>
							<div class="spar-user-status-control">
								<select id="spar-user-status-<?php echo esc_attr( $user_id ); ?>" class="spar-user-status">
									<option value="active" <?php selected( $account_status, 'active' ); ?>><?php esc_html_e( 'Active', 'simple-points-and-rewards' ); ?></option>
									<option value="banned" <?php selected( $account_status, 'banned' ); ?>><?php esc_html_e( 'Banned', 'simple-points-and-rewards' ); ?></option>
								</select>
								<span class="spar-status-feedback" aria-hidden="true">✓</span>
							</div>
						</div>
					</div>
				</div>
				<div class="spar-customer-stats">
					<div class="spar-stat-card">
						<span><i class="fa-solid fa-coins" aria-hidden="true"></i><?php esc_html_e( 'Total Points Earned', 'simple-points-and-rewards' ); ?></span>
							<strong class="spar-total-earned-display" data-user-id="<?php echo esc_attr( $user_id ); ?>"><span class="spar-total-earned-value"><?php echo esc_html( number_format_i18n( $total_earned ) ); ?></span></strong>
					</div>
					<div class="spar-stat-card">
						<span><i class="fa-solid fa-wallet" aria-hidden="true"></i><?php esc_html_e( 'Points Available', 'simple-points-and-rewards' ); ?></span>
							<strong class="spar-points-display" data-user-id="<?php echo esc_attr( $user_id ); ?>"><span class="spar-points-value"><?php echo esc_html( number_format_i18n( $points_balance ) ); ?></span></strong>
					</div>
					<div class="spar-stat-card">
						<span><i class="fa-solid fa-receipt" aria-hidden="true"></i><?php esc_html_e( 'Total Spend', 'simple-points-and-rewards' ); ?></span>
						<strong><?php echo function_exists( 'wc_price' ) ? wp_kses_post( wc_price( $total_spend ) ) : esc_html( spar_format_currency_amount( $total_spend ) ); ?></strong>
					</div>
					<div class="spar-stat-card">
						<span><i class="fa-solid fa-user-group" aria-hidden="true"></i><?php esc_html_e( 'Total Referrals', 'simple-points-and-rewards' ); ?></span>
						<strong><?php echo esc_html( number_format_i18n( (int) $referral_stats['successful_referrals'] ) ); ?></strong>
					</div>
					<div class="spar-stat-card">
						<span><i class="fa-solid fa-chart-line" aria-hidden="true"></i><?php esc_html_e( 'Referral Revenue', 'simple-points-and-rewards' ); ?></span>
						<strong><?php echo function_exists( 'wc_price' ) ? wp_kses_post( wc_price( $referral_revenue ) ) : esc_html( spar_format_currency_amount( $referral_revenue ) ); ?></strong>
					</div>
				</div>

				<div class="spar-panel spar-panel--full">
					<h2><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i><?php esc_html_e( 'Points Activity Log', 'simple-points-and-rewards' ); ?></h2>
					<div class="spar-table-wrap">
						<table class="widefat striped" id="spar-points-log-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Date', 'simple-points-and-rewards' ); ?></th>
									<th><?php esc_html_e( 'Type', 'simple-points-and-rewards' ); ?></th>
									<th><?php esc_html_e( 'Points', 'simple-points-and-rewards' ); ?></th>
									<th><?php esc_html_e( 'Action', 'simple-points-and-rewards' ); ?></th>
									<th><?php esc_html_e( 'Source', 'simple-points-and-rewards' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php echo wp_kses_post( spar_build_customer_detail_points_rows( $points_logs ) ); ?>
							</tbody>
						</table>
					</div>
					<div class="spar-pagination" data-table="points">
						<?php echo wp_kses_post( spar_build_customer_detail_pagination_html( $points_pagination, 'points' ) ); ?>
					</div>
				</div>

				<div class="spar-panel spar-panel--full">
					<h2><i class="fa-solid fa-bag-shopping" aria-hidden="true"></i><?php esc_html_e( 'Recent Orders', 'simple-points-and-rewards' ); ?></h2>
					<div class="spar-table-wrap">
						<table class="widefat striped spar-orders-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Order', 'simple-points-and-rewards' ); ?></th>
									<th><?php esc_html_e( 'Date', 'simple-points-and-rewards' ); ?></th>
									<th><?php esc_html_e( 'Status', 'simple-points-and-rewards' ); ?></th>
									<th><?php esc_html_e( 'Total', 'simple-points-and-rewards' ); ?></th>
									<th><?php echo esc_html( $points_label ); ?></th>
									<th><?php esc_html_e( 'Points Status', 'simple-points-and-rewards' ); ?></th>
								</tr>
							</thead>
							<tbody>
									<?php echo wp_kses_post( spar_build_customer_detail_orders_rows( $recent_orders, $points_label, $user_id ) ); ?>
							</tbody>
						</table>
					</div>
					<div class="spar-pagination" data-table="orders">
						<?php echo wp_kses_post( spar_build_customer_detail_pagination_html( $orders_pagination, 'orders' ) ); ?>
					</div>
				</div>

				<div class="spar-panel spar-panel--full">
					<h2><i class="fa-solid fa-share-nodes" aria-hidden="true"></i><?php esc_html_e( 'Referrals', 'simple-points-and-rewards' ); ?></h2>
					<div class="spar-table-wrap">
						<table class="widefat striped" id="spar-referrals-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Date', 'simple-points-and-rewards' ); ?></th>
									<th><?php esc_html_e( 'Landing Page', 'simple-points-and-rewards' ); ?></th>
									<th><?php esc_html_e( 'Referring Domain', 'simple-points-and-rewards' ); ?></th>
									<th><?php esc_html_e( 'Customer', 'simple-points-and-rewards' ); ?></th>
									<th><?php esc_html_e( 'Converted', 'simple-points-and-rewards' ); ?></th>
									<th><?php esc_html_e( 'Order', 'simple-points-and-rewards' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php echo wp_kses_post( spar_build_customer_detail_referral_rows( $referral_clicks ) ); ?>
							</tbody>
					</table>
					</div>
					<div class="spar-pagination" data-table="referrals">
						<?php echo wp_kses_post( spar_build_customer_detail_pagination_html( $referral_pagination, 'referrals' ) ); ?>
					</div>
				</div>

				<?php do_action( 'spar_customer_detail_after_referrals', $user_id ); ?>

			</div>

			<div class="spar-customer-sidebar">
				<div class="spar-panel spar-points-adjust">
					<h2><i class="fa-solid fa-sliders" aria-hidden="true"></i><?php esc_html_e( 'Adjust Points', 'simple-points-and-rewards' ); ?></h2>
					<div class="spar-points-actions" data-user-id="<?php echo esc_attr( $user_id ); ?>" data-nonce="<?php echo esc_attr( $points_nonce ); ?>">
						<div class="spar-points-controls">
							<input type="number" class="spar-points-input" placeholder="0" min="1" step="1" />
							<input type="text" class="spar-points-reason" placeholder="<?php echo esc_attr__( 'Reason (optional)', 'simple-points-and-rewards' ); ?>" />
							<div class="spar-points-buttons">
								<button type="button" class="button button-primary spar-add-points"><?php esc_html_e( 'Add Points', 'simple-points-and-rewards' ); ?></button>
								<button type="button" class="button spar-remove-points"><?php esc_html_e( 'Remove Points', 'simple-points-and-rewards' ); ?></button>
							</div>
						</div>
						<div class="spar-points-loading">
							<span class="spinner is-active"></span>
						</div>
					</div>
				</div>

				<div class="spar-panel">
					<h2><i class="fa-solid fa-user" aria-hidden="true"></i><?php esc_html_e( 'Account Information', 'simple-points-and-rewards' ); ?></h2>
					<ul class="spar-details-list">
						<li><span><?php esc_html_e( 'Registered', 'simple-points-and-rewards' ); ?></span><strong><?php echo esc_html( $registered_date ); ?></strong></li>
						<li><span><?php esc_html_e( 'Last Active', 'simple-points-and-rewards' ); ?></span><strong><?php echo esc_html( $last_active_display ); ?></strong></li>
						<li><span><?php esc_html_e( 'Last Order', 'simple-points-and-rewards' ); ?></span><strong><?php echo $last_order_date ? esc_html( $last_order_date ) : esc_html__( 'None', 'simple-points-and-rewards' ); ?></strong></li>
						<li><span><?php esc_html_e( 'Referrer', 'simple-points-and-rewards' ); ?></span><strong><?php echo $referrer ? esc_html( $referrer->display_name ) : esc_html__( 'None', 'simple-points-and-rewards' ); ?></strong></li>
						<li><span><?php esc_html_e( 'Referral Code', 'simple-points-and-rewards' ); ?></span><strong><?php echo $referral_code ? esc_html( $referral_code ) : esc_html__( 'Not set', 'simple-points-and-rewards' ); ?></strong></li>
					</ul>
				</div>

				<div class="spar-panel">
					<h2><i class="fa-solid fa-chart-pie" aria-hidden="true"></i><?php esc_html_e( 'Points Summary', 'simple-points-and-rewards' ); ?></h2>
					<div class="spar-points-summary">
						<div>
							<span><?php esc_html_e( 'Total Earned', 'simple-points-and-rewards' ); ?></span>
							<strong class="spar-total-earned-display" data-user-id="<?php echo esc_attr( $user_id ); ?>"><span class="spar-total-earned-value"><?php echo esc_html( number_format_i18n( $total_earned ) ); ?></span></strong>
						</div>
						<div>
							<span><?php esc_html_e( 'Available', 'simple-points-and-rewards' ); ?></span>
							<strong class="spar-points-display" data-user-id="<?php echo esc_attr( $user_id ); ?>"><span class="spar-points-value"><?php echo esc_html( number_format_i18n( $points_balance ) ); ?></span></strong>
						</div>
						<div>
							<span><?php esc_html_e( 'Redeemed', 'simple-points-and-rewards' ); ?></span>
							<strong><?php echo esc_html( number_format_i18n( $total_redeemed ) ); ?></strong>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
	<?php
}

if ( ! function_exists( 'spar_build_customer_detail_points_rows' ) ) {
	function spar_build_customer_detail_points_rows( $logs ) {
		if ( empty( $logs ) ) {
			return '<tr><td colspan="5">' . esc_html__( 'No points log entries found.', 'simple-points-and-rewards' ) . '</td></tr>';
		}

		$rows = '';
		foreach ( $logs as $log ) {
			$date = ! empty( $log['date'] ) ? date_i18n( 'M j, Y g:i A', strtotime( $log['date'] ) ) : '';
			$type = ! empty( $log['type'] ) ? ucfirst( (string) $log['type'] ) : '';
			$points = isset( $log['points'] ) ? number_format_i18n( (int) $log['points'] ) : '0';
			$action = ! empty( $log['action'] ) ? $log['action'] : '';
			$source = ! empty( $log['action_id'] ) ? $log['action_id'] : '—';

			$rows .= sprintf(
				'<tr>'
				. '<td>%s</td>'
				. '<td>%s</td>'
				. '<td>%s</td>'
				. '<td>%s</td>'
				. '<td>%s</td>'
				. '</tr>',
				esc_html( $date ),
				esc_html( $type ),
				esc_html( $points ),
				esc_html( $action ),
				esc_html( $source )
			);
		}

		return $rows;
	}
}

if ( ! function_exists( 'spar_build_customer_detail_referral_rows' ) ) {
	function spar_build_customer_detail_referral_rows( $logs ) {
		if ( empty( $logs ) ) {
			return '<tr><td colspan="6">' . esc_html__( 'No referral clicks yet.', 'simple-points-and-rewards' ) . '</td></tr>';
		}

		$rows = '';
		foreach ( $logs as $click ) {
			$date = ! empty( $click['created_at'] ) ? date_i18n( 'M j, Y g:i A', strtotime( $click['created_at'] ) ) : '';
			$landing = function_exists( 'spar_format_referral_click_landing_url' )
				? spar_format_referral_click_landing_url( $click['landing_url'] ?? '' )
				: ( $click['landing_url'] ?? '' );
			$referring_domain = ! empty( $click['referring_domain'] ) ? $click['referring_domain'] : '—';
			$customer_name = '';
			if ( ! empty( $click['referree_user_id'] ) ) {
				$referree = get_userdata( (int) $click['referree_user_id'] );
				$customer_name = $referree ? $referree->display_name : '';
			}
			$order_link = '';
			if ( ! empty( $click['order_id'] ) ) {
				$order_link = admin_url( 'post.php?post=' . absint( $click['order_id'] ) . '&action=edit' );
			}
			$converted = ! empty( $click['converted'] ) ? esc_html__( 'Yes', 'simple-points-and-rewards' ) : esc_html__( 'No', 'simple-points-and-rewards' );

			$rows .= sprintf(
				'<tr>'
				. '<td>%s</td>'
				. '<td>%s</td>'
				. '<td>%s</td>'
				. '<td>%s</td>'
				. '<td>%s</td>'
				. '<td>%s</td>'
				. '</tr>',
				esc_html( $date ),
				esc_html( $landing ),
				esc_html( $referring_domain ),
				esc_html( $customer_name ? $customer_name : esc_html__( 'Guest', 'simple-points-and-rewards' ) ),
				esc_html( $converted ),
				$order_link ? wp_kses_post( '<a href="' . esc_url( $order_link ) . '">' . esc_html( '#' . (int) $click['order_id'] ) . '</a>' ) : esc_html__( '—', 'simple-points-and-rewards' )
			);
		}

		return $rows;
	}
}

if ( ! function_exists( 'spar_build_customer_detail_pagination_html' ) ) {
	function spar_build_customer_detail_pagination_html( $pagination, $table_key ) {
		if ( empty( $pagination['total_pages'] ) || $pagination['total_pages'] <= 1 ) {
			return '';
		}

		$pagination_html = '<div class="spar-pagination-controls" data-table="' . esc_attr( $table_key ) . '">';

		$prev_disabled = ! $pagination['has_previous'] ? 'disabled' : '';
		$pagination_html .= sprintf(
			'<button class="spar-pagination-btn spar-pagination-prev" data-page="%d" %s>%s</button>',
			$pagination['current_page'] - 1,
			$prev_disabled,
			esc_html__( '← Previous', 'simple-points-and-rewards' )
		);

		$current_page = (int) $pagination['current_page'];
		$total_pages  = (int) $pagination['total_pages'];
		$start_page   = max( 1, $current_page - 2 );
		$end_page     = min( $total_pages, $current_page + 2 );
		if ( 1 === $start_page ) {
			$end_page = min( $total_pages, 5 );
		} elseif ( $end_page === $total_pages ) {
			$start_page = max( 1, $total_pages - 4 );
		}
		for ( $i = $start_page; $i <= $end_page; $i++ ) {
			$is_current = $i === $current_page;
			$btn_class = $is_current ? 'spar-pagination-btn spar-pagination-current' : 'spar-pagination-btn spar-pagination-page';
			$btn_disabled = $is_current ? 'disabled' : '';
			$pagination_html .= sprintf(
				'<button class="%s" data-page="%d" %s>%d</button>',
				esc_attr( $btn_class ),
				$i,
				$btn_disabled,
				$i
			);
		}

		$next_disabled = ! $pagination['has_next'] ? 'disabled' : '';
		$pagination_html .= sprintf(
			'<button class="spar-pagination-btn spar-pagination-next" data-page="%d" %s>%s</button>',
			$pagination['current_page'] + 1,
			$next_disabled,
			esc_html__( 'Next →', 'simple-points-and-rewards' )
		);

		$pagination_html .= '</div>';
		$pagination_html .= '<div class="spar-pagination-loading"><span>' . esc_html__( 'Loading...', 'simple-points-and-rewards' ) . '</span></div>';

		return $pagination_html;
	}
}

if ( ! function_exists( 'spar_build_customer_detail_orders_rows' ) ) {
	function spar_build_customer_detail_orders_rows( $orders, $points_label, $user_id ) {
		if ( empty( $orders ) ) {
			return '<tr><td colspan="6">' . esc_html__( 'No orders found for this customer.', 'simple-points-and-rewards' ) . '</td></tr>';
		}

		$rows = '';
		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			$order_id = $order->get_id();
			$order_link = admin_url( 'post.php?post=' . $order_id . '&action=edit' );
			$order_date = $order->get_date_created();
			$status_label = function_exists( 'wc_get_order_status_name' ) ? wc_get_order_status_name( $order->get_status() ) : $order->get_status();
			$total_display = function_exists( 'wc_price' ) ? wp_kses_post( wc_price( $order->get_total() ) ) : esc_html( number_format_i18n( (float) $order->get_total(), 2 ) );

			$points_earned = (int) $order->get_meta( 'points_earned' );
			$terminal = in_array( $order->get_status(), array( 'refunded', 'cancelled', 'failed' ), true );
			$points_pending = 0;
			$points_status = esc_html__( 'Not earned', 'simple-points-and-rewards' );
			$status_class = 'is-none';
			if ( $points_earned > 0 ) {
				$points_pending = $points_earned;
				$points_status = esc_html__( 'Earned', 'simple-points-and-rewards' );
				$status_class = 'is-earned';
			} elseif ( ! $terminal ) {
				$points_pending = function_exists( 'spar_calculate_order_points_preview' ) ? spar_calculate_order_points_preview( $order, $user_id ) : 0;
				$points_status = esc_html__( 'Pending', 'simple-points-and-rewards' );
				$status_class = 'is-pending';
			}

			$rows .= sprintf(
				'<tr>'
				. '<td><a href="%s">%s</a></td>'
				. '<td>%s</td>'
				. '<td>%s</td>'
				. '<td>%s</td>'
				. '<td class="spar-td-center">%s</td>'
				. '<td><span class="spar-order-points-status %s">%s</span></td>'
				. '</tr>',
				esc_url( $order_link ),
				esc_html( '#' . $order_id ),
				$order_date ? esc_html( $order_date->date_i18n( 'M j, Y' ) ) : esc_html__( '—', 'simple-points-and-rewards' ),
				esc_html( $status_label ),
				$total_display,
				esc_html( number_format_i18n( $points_pending ) ),
				esc_attr( $status_class ),
				esc_html( $points_status )
			);
		}

		return $rows;
	}
}

/**
 * Enqueue styles for customer detail page
 */
function spar_enqueue_customer_detail_styles( $hook ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only check of the current admin screen to conditionally enqueue assets.
	$current_page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	if ( strpos( $hook, 'spar-customer' ) === false && 'spar-customer' !== $current_page ) {
		return;
	}

	$plugin_assets_url = trailingslashit( SPAR_PLUGIN_URL );

	wp_enqueue_style(
		'spar-font-awesome',
		$plugin_assets_url . 'assets/fonts/font-awesome/css/all.min.css',
		array(),
		'6.6.0'
	);

	wp_enqueue_style(
		'spar-customer-detail',
		$plugin_assets_url . 'assets/css/customer-detail.css',
		array( 'spar-styles', 'spar-font-awesome' ),
		SPAR_VERSION
	);

	wp_enqueue_script(
		'spar-customer-points-table',
		$plugin_assets_url . 'assets/js/customer-points-table.js',
		array( 'jquery' ),
		SPAR_VERSION,
		true
	);

	wp_localize_script(
		'spar-customer-points-table',
		'sparCustomerPoints',
		array(
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'strings' => array(
				'confirmRemove' => esc_html__( 'Are you sure you want to remove these points?', 'simple-points-and-rewards' ),
				'processing' => esc_html__( 'Processing...', 'simple-points-and-rewards' ),
				'success' => esc_html__( 'Points updated successfully!', 'simple-points-and-rewards' ),
				'error' => esc_html__( 'Error updating points. Please try again.', 'simple-points-and-rewards' ),
				'copied' => esc_html__( 'Copied to clipboard!', 'simple-points-and-rewards' ),
				'copyFailed' => esc_html__( 'Failed to copy. Please copy manually.', 'simple-points-and-rewards' ),
				'invalidAmount' => esc_html__( 'Please enter a valid points amount.', 'simple-points-and-rewards' ),
			),
		)
	);

	wp_enqueue_script(
		'spar-customer-points-status',
		$plugin_assets_url . 'assets/js/customer-points-status.js',
		array( 'jquery' ),
		SPAR_VERSION,
		true
	);
	wp_localize_script(
		'spar-customer-points-status',
		'sparCustomerStatus',
		array(
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'strings' => array(
				'updating' => esc_html__( 'Updating status...', 'simple-points-and-rewards' ),
				'success'  => esc_html__( 'Status updated.', 'simple-points-and-rewards' ),
				'error'    => esc_html__( 'Error updating status.', 'simple-points-and-rewards' ),
			),
		)
	);

	wp_enqueue_script(
		'spar-customer-detail-pagination',
		$plugin_assets_url . 'assets/js/customer-detail-pagination.js',
		array( 'jquery' ),
		SPAR_VERSION,
		true
	);

	wp_localize_script(
		'spar-customer-detail-pagination',
		'sparCustomerDetail',
		array(
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only value passed to JS; page access is nonce-verified in the page callback.
			'userId' => isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0,
			'nonce' => wp_create_nonce( 'spar_customer_detail_pagination' ),
			'i18n' => array(
				'error' => esc_html__( 'Error loading data. Please try again.', 'simple-points-and-rewards' ),
			),
		)
	);
}
add_action( 'admin_enqueue_scripts', 'spar_enqueue_customer_detail_styles' );
