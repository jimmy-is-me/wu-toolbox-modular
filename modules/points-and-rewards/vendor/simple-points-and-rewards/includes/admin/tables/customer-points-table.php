<?php

/**
 * Customer Points List Table
 */
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
if ( !class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}
class SPAR_Customer_Points_List_Table extends WP_List_Table {
    /**
     * Whether inactivity expiry is active.
     *
     * @var bool
     */
    protected $points_expiry_enabled = false;

    /**
     * Configured inactivity expiry window (days).
     *
     * @var int
     */
    protected $points_expiry_days = 0;

    public function __construct( $args = array() ) {
        $args = wp_parse_args( $args, array(
            'points_expiry_enabled' => false,
            'points_expiry_days'    => 0,
        ) );
        $this->points_expiry_enabled = (bool) $args['points_expiry_enabled'];
        $this->points_expiry_days = max( 0, (int) $args['points_expiry_days'] );
        parent::__construct( [
            'singular' => 'customer_points',
            'plural'   => 'customer_points',
            'ajax'     => false,
        ] );
    }

    public function prepare_items() {
        global $wpdb;
        $per_page = (int) apply_filters( 'spar_customer_points_per_page', 20 );
        if ( $per_page < 1 ) {
            $per_page = 20;
        }
        $current_page = $this->get_pagenum();
        $offset = ($current_page - 1) * $per_page;
        $search_term = ( isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '' );
        $points_filter = ( isset( $_GET['points_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['points_filter'] ) ) : '' );
        // Verify nonce for filter changes (top table nav adds nonce field)
        $filters_nonce = ( isset( $_GET['spar_customer_points_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['spar_customer_points_nonce'] ) ) : '' );
        $filters_nonce_valid = $filters_nonce && wp_verify_nonce( $filters_nonce, 'spar_customer_points_filters' );
        // Dynamic WHERE conditions & params for scanner compliance
        $conds = array();
        $params = array();
        $points_join_sql = ' LEFT JOIN %i pm ON u.ID = pm.user_id AND pm.meta_key = %s';
        $points_join_args = array($wpdb->usermeta, '_spar_points');
        $points_filter_sql = '';
        // Search functionality
        if ( !empty( $search_term ) ) {
            $search_like = '%' . $wpdb->esc_like( $search_term ) . '%';
            $conds[] = ' AND (u.user_login LIKE %s OR u.user_email LIKE %s OR u.display_name LIKE %s)';
            $params[] = $search_like;
            $params[] = $search_like;
            $params[] = $search_like;
        }
        // Points filter (static fragments)
        if ( $filters_nonce_valid && !empty( $points_filter ) ) {
            if ( 'no_points' === $points_filter ) {
                $points_filter_sql = " AND (pm.meta_value IS NULL OR pm.meta_value = '0')";
            } elseif ( 'has_points' === $points_filter ) {
                $points_join_sql = ' INNER JOIN %i pm ON u.ID = pm.user_id AND pm.meta_key = %s';
                $points_filter_sql = ' AND pm.meta_value > 0';
            } elseif ( 'high_points' === $points_filter ) {
                $points_join_sql = ' INNER JOIN %i pm ON u.ID = pm.user_id AND pm.meta_key = %s';
                $points_filter_sql = ' AND pm.meta_value >= 1000';
            }
        }
        if ( '' !== $points_filter_sql ) {
            $conds[] = $points_filter_sql;
        }
        $where_sql_dynamic = implode( '', $conds );
        // Get total count with simple caching (prepared if args exist)
        $cache_group = 'spar';
        $count_key = 'spar_customer_points_count_' . md5( $where_sql_dynamic );
        $total_items = wp_cache_get( $count_key, $cache_group );
        if ( false === $total_items ) {
            if ( $filters_nonce_valid && !empty( $points_filter ) ) {
                $count_sql_base = ' SELECT COUNT(DISTINCT u.ID) FROM %i u' . $points_join_sql . ' WHERE 1=1' . $where_sql_dynamic;
                $count_args = array_merge( array($count_sql_base, $wpdb->users), $points_join_args, $params );
            } else {
                $count_sql_base = ' SELECT COUNT(*) FROM %i u WHERE 1=1' . $where_sql_dynamic;
                $count_args = array_merge( array($count_sql_base, $wpdb->users), $params );
            }
            $count_sql = call_user_func_array( array($wpdb, 'prepare'), $count_args );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $total_items = (int) $wpdb->get_var( $count_sql );
            wp_cache_set(
                $count_key,
                $total_items,
                $cache_group,
                60
            );
        }
        // Get sort parameters
        $default_orderby = apply_filters( 'spar_customer_points_default_orderby', 'points' );
        $orderby = ( isset( $_GET['orderby'] ) ? sanitize_text_field( wp_unslash( $_GET['orderby'] ) ) : $default_orderby );
        $order = ( isset( $_GET['order'] ) ? sanitize_text_field( wp_unslash( $_GET['order'] ) ) : 'DESC' );
        // Validate orderby
        $allowed_orderby = [
            'display_name',
            'user_login',
            'points',
            'total_referrals'
        ];
        if ( !in_array( $orderby, $allowed_orderby ) ) {
            $orderby = 'display_name';
        }
        // Validate order
        $order = ( strtoupper( $order ) === 'DESC' ? 'DESC' : 'ASC' );
        // Build ORDER BY clause
        $order_clause = '';
        switch ( $orderby ) {
            case 'points':
                $order_clause = "ORDER BY CAST(COALESCE(pm.meta_value, 0) AS UNSIGNED) {$order}";
                break;
            default:
                $order_clause = "ORDER BY u.{$orderby} {$order}";
                break;
        }
        // Main query (prepared)
        $results_key = 'spar_customer_points_results_' . md5( wp_json_encode( array(
            'where_sql' => $where_sql_dynamic,
            'orderby'   => $orderby,
            'order'     => $order,
            'per'       => $per_page,
            'off'       => $offset,
        ) ) );
        $results = wp_cache_get( $results_key, $cache_group );
        if ( false === $results ) {
            $data_sql_base = ' SELECT u.ID, u.user_login, u.user_email, u.display_name, u.user_registered, COALESCE(pm.meta_value, 0) as points FROM %i u' . $points_join_sql . ' WHERE 1=1' . $where_sql_dynamic . ' ' . $order_clause . ' LIMIT %d OFFSET %d';
            $data_args = array_merge(
                array($data_sql_base, $wpdb->users),
                $points_join_args,
                $params,
                array($per_page, $offset)
            );
            $prepared_sql = call_user_func_array( array($wpdb, 'prepare'), $data_args );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $prepared_sql is already prepared using $wpdb->prepare() above, direct query needed for custom table joins.
            $results = $wpdb->get_results( $prepared_sql );
            wp_cache_set(
                $results_key,
                $results,
                $cache_group,
                60
            );
        }
        // Enhance results with additional data
        $points_expiry_enabled = $this->points_expiry_enabled;
        $points_expiry_days = $this->points_expiry_days;
        $now = ( $points_expiry_enabled && $points_expiry_days > 0 ? current_time( 'timestamp' ) : null );
        $user_ids = wp_list_pluck( $results, 'ID' );
        if ( !empty( $user_ids ) ) {
            update_meta_cache( 'user', $user_ids );
        }
        $this->items = array_map( function ( $user ) use($points_expiry_enabled, $points_expiry_days, $now) {
            $referral_code = get_user_meta( $user->ID, 'spar_referral_code', true );
            if ( empty( $referral_code ) ) {
                $referral_code = spar_get_user_referral_code( $user->ID );
            }
            $referral_stats = get_user_meta( $user->ID, 'spar_referral_stats', true );
            if ( !is_array( $referral_stats ) ) {
                $referral_stats = array(
                    'successful_referrals' => 0,
                    'total_points_earned'  => 0,
                    'total_clicks'         => 0,
                );
            }
            if ( !isset( $referral_stats['total_clicks'] ) ) {
                $referral_stats['total_clicks'] = 0;
            }
            $referral_url = ( $referral_code ? add_query_arg( 'ref', $referral_code, home_url() ) : '' );
            $activity_meta = array(
                'spar_last_active_ts'    => 0,
                'spar_expiry_timestamp'  => 0,
                'spar_days_until_expiry' => null,
            );
            $points_balance = ( isset( $user->points ) ? (float) $user->points : 0.0 );
            $last_active_raw = get_user_meta( $user->ID, 'spar_last_active', true );
            $last_active_ts = 0;
            $missing_last_active = '' === $last_active_raw || null === $last_active_raw || false === $last_active_raw;
            if ( $missing_last_active ) {
                $last_active_ts = current_time( 'timestamp' );
                update_user_meta( $user->ID, 'spar_last_active', $last_active_ts );
            } elseif ( function_exists( 'spar_points_expiry_normalize_timestamp' ) ) {
                $last_active_ts = spar_points_expiry_normalize_timestamp( $last_active_raw );
            } elseif ( is_numeric( $last_active_raw ) ) {
                $last_active_ts = (int) $last_active_raw;
            } else {
                $parsed = strtotime( (string) $last_active_raw );
                $last_active_ts = ( $parsed ? (int) $parsed : 0 );
            }
            $activity_meta['spar_last_active_ts'] = $last_active_ts;
            if ( $points_expiry_enabled && $points_expiry_days > 0 && $last_active_ts > 0 && $points_balance > 0 ) {
                $expiry_timestamp = $last_active_ts + $points_expiry_days * DAY_IN_SECONDS;
                $activity_meta['spar_expiry_timestamp'] = $expiry_timestamp;
                if ( null !== $now ) {
                    $activity_meta['spar_days_until_expiry'] = (int) ceil( ($expiry_timestamp - $now) / DAY_IN_SECONDS );
                }
            }
            return (object) array_merge( (array) $user, array(
                'referral_code'                => $referral_code,
                'referral_stats'               => $referral_stats,
                'referral_url'                 => $referral_url,
                'total_referrals'              => $referral_stats['successful_referrals'],
                'total_clicks'                 => $referral_stats['total_clicks'],
                'points_earned_from_referrals' => $referral_stats['total_points_earned'],
            ), $activity_meta );
        }, $results );
        $this->set_pagination_args( [
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil( $total_items / $per_page ),
        ] );
        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns()];
    }

    public function get_columns() {
        $columns = array(
            'cb'           => '<input type="checkbox" />',
            'display_name' => esc_html__( 'Customer', 'simple-points-and-rewards' ),
        );
        $columns['referral_stats'] = esc_html__( 'Referral Stats', 'simple-points-and-rewards' );
        $columns['referral_url'] = esc_html__( 'Referral Link', 'simple-points-and-rewards' );
        if ( $this->points_expiry_enabled ) {
            $columns['last_active'] = esc_html__( 'Activity', 'simple-points-and-rewards' );
        }
        $columns['points'] = esc_html__( 'Points', 'simple-points-and-rewards' );
        $columns['actions'] = esc_html__( 'Manage Points', 'simple-points-and-rewards' );
        $columns['status'] = esc_html__( 'Status', 'simple-points-and-rewards' );
        $columns['customer_actions'] = esc_html__( 'Actions', 'simple-points-and-rewards' );
        return $columns;
    }

    public function get_sortable_columns() {
        return [
            'display_name'    => ['display_name', true],
            'user_login'      => ['user_login', false],
            'points'          => ['points', false],
            'total_referrals' => ['total_referrals', false],
        ];
    }

    protected function column_cb( $item ) {
        return sprintf( '<input type="checkbox" name="customer[]" value="%d" />', absint( $item->ID ) );
    }

    protected function column_display_name( $item ) {
        $user_id = absint( $item->ID );
        $edit_link = get_edit_user_link( $user_id );
        $view_nonce = wp_create_nonce( 'spar_customer_detail_view_' . $user_id );
        $view_link = add_query_arg( array(
            'page'    => 'spar-customer',
            'user_id' => $user_id,
            'nonce'   => $view_nonce,
        ), admin_url( 'admin.php' ) );
        $actions = [
            'edit' => sprintf( '<a href="%s">%s</a>', esc_url( $edit_link ), esc_html__( 'Edit User', 'simple-points-and-rewards' ) ),
        ];
        $avatar = get_avatar( $user_id, 32 );
        $user_info = sprintf(
            '%s<br/><strong><a href="%s">%s</a></strong><br><small>%s</small>',
            wp_kses_post( $avatar ),
            esc_url( $view_link ),
            esc_html( $item->display_name ),
            esc_html( $item->user_email )
        );
        return sprintf( '%s%s', $user_info, $this->row_actions( $actions ) );
    }

    protected function column_referral_voucher( $item ) {
    }

    protected function column_points( $item ) {
        $points_label = ( spar_get_option( '', 'points_label' ) ?: esc_html__( 'Points', 'simple-points-and-rewards' ) );
        $total_earned = ( function_exists( 'spar_get_user_total_points_earned' ) ? spar_get_user_total_points_earned( $item->ID ) : 0 );
        return sprintf(
            '<span class="spar-points-display" data-user-id="%d"><strong>%s:</strong><br><span class="spar-points-value">%s</span></span><br><br><small style="color:#666;" class="spar-total-earned-display" data-user-id="%d"><strong>%s:</strong> <span class="spar-total-earned-value">%s</span></small>',
            absint( $item->ID ),
            esc_html__( 'Reward Points', 'simple-points-and-rewards' ),
            number_format_i18n( (float) $item->points ),
            absint( $item->ID ),
            esc_html__( 'Total Earned', 'simple-points-and-rewards' ),
            number_format_i18n( (int) $total_earned )
        );
    }

    protected function column_status( $item ) {
        $user_id = absint( $item->ID );
        $current = get_user_meta( $user_id, 'spar_user_status', true );
        $current = ( $current === 'banned' ? 'banned' : 'active' );
        $nonce = wp_create_nonce( 'spar_update_user_status' );
        $return = '<div class="spar-user-status-wrapper" data-user-id="' . esc_attr( $user_id ) . '" data-nonce="' . esc_attr( $nonce ) . '">';
        $return .= '<select class="spar-user-status" style="min-width:110px;">';
        $return .= '<option value="active"' . selected( $current, 'active', false ) . '>' . esc_html__( 'Active', 'simple-points-and-rewards' ) . '</option>';
        $return .= '<option value="banned"' . selected( $current, 'banned', false ) . '>' . esc_html__( 'Banned', 'simple-points-and-rewards' ) . '</option>';
        $return .= '</select> ';
        $return .= '<span class="spar-status-feedback" style="display:none; margin-left:4px;">✓</span>';
        $return .= '</div>';
        return $return;
    }

    protected function column_last_active( $item ) {
        $points_balance = ( isset( $item->points ) ? (float) $item->points : 0.0 );
        $last_active_ts = ( isset( $item->spar_last_active_ts ) ? (int) $item->spar_last_active_ts : 0 );
        $expiry_ts = ( isset( $item->spar_expiry_timestamp ) ? (int) $item->spar_expiry_timestamp : 0 );
        $days_until = ( isset( $item->spar_days_until_expiry ) ? $item->spar_days_until_expiry : null );
        $lines = array();
        if ( $last_active_ts > 0 ) {
            $date_format = get_option( 'date_format' );
            $last_active_display = ( function_exists( 'wp_date' ) ? wp_date( $date_format, $last_active_ts ) : date_i18n( $date_format, $last_active_ts ) );
            $days_diff = (int) floor( (current_time( 'timestamp' ) - $last_active_ts) / DAY_IN_SECONDS );
            if ( $days_diff === 0 ) {
                $last_active_display_days = esc_html__( 'Today', 'simple-points-and-rewards' );
            } elseif ( $days_diff === 1 ) {
                $last_active_display_days = esc_html__( 'Yesterday', 'simple-points-and-rewards' );
            } else {
                /* translators: %s: number of days */
                $last_active_display_days = sprintf( _n(
                    '%s day ago',
                    '%s days ago',
                    $days_diff,
                    'simple-points-and-rewards'
                ), number_format_i18n( $days_diff ) );
            }
            $lines[] = sprintf( '<span>%s</span>', sprintf( 
                /* translators: %s: formatted date */
                esc_html__( 'Last active: %s', 'simple-points-and-rewards' ),
                esc_html( $last_active_display_days )
             ) );
        } else {
            $lines[] = '<span class="description">' . esc_html__( 'No activity recorded', 'simple-points-and-rewards' ) . '</span>';
        }
        if ( $last_active_ts > 0 ) {
            if ( $points_balance <= 0 || $expiry_ts <= 0 || null === $days_until ) {
                $lines[] = '<span class="description">' . esc_html__( 'No points set to expire', 'simple-points-and-rewards' ) . '</span>';
            } else {
                $date_format = get_option( 'date_format' );
                $expiry_date_display = ( function_exists( 'wp_date' ) ? wp_date( $date_format, $expiry_ts ) : date_i18n( $date_format, $expiry_ts ) );
                $expiry_date_display = esc_html( $expiry_date_display );
                $days_until = (int) $days_until;
                if ( $days_until > 0 ) {
                    $expiry_line = sprintf( _n(
                        'Points expire: %1$s day',
                        'Points expire: %1$s days',
                        $days_until,
                        'simple-points-and-rewards'
                    ), number_format_i18n( $days_until ), $expiry_date_display );
                } elseif ( 0 === $days_until ) {
                    $expiry_line = sprintf( esc_html__( 'Expires today (%s)', 'simple-points-and-rewards' ), $expiry_date_display );
                } else {
                    $expiry_line = sprintf( esc_html__( 'Expired on %s', 'simple-points-and-rewards' ), $expiry_date_display );
                }
                $lines[] = '<span class="description">' . esc_html( $expiry_line ) . '</span>';
            }
        }
        // Birthday Logic
        $options = get_option( 'spar_options', array() );
        if ( !empty( $options['earn']['birthday']['enabled'] ) ) {
            $birthday = get_user_meta( $item->ID, '_spar_birthday_date', true );
            if ( $birthday ) {
                $bday_ts = strtotime( $birthday );
                if ( $bday_ts ) {
                    $bday_display = gmdate( 'M j', $bday_ts );
                    // Calculate days until next birthday
                    try {
                        $today_str = current_time( 'Y-m-d' );
                        $today = new DateTime($today_str);
                        $bday_date = new DateTime($birthday);
                        $next_bday = new DateTime($today->format( 'Y' ) . '-' . $bday_date->format( 'm-d' ));
                        if ( $next_bday < $today ) {
                            $next_bday->modify( '+1 year' );
                        }
                        $diff = $today->diff( $next_bday );
                        $days_until_bday = $diff->days;
                        $bday_line = sprintf( 
                            /* translators: 1: birthday date, 2: days until */
                            esc_html__( 'Birthday: %2$d days (%1$s)', 'simple-points-and-rewards' ),
                            $bday_display,
                            $days_until_bday
                         );
                        $lines[] = '<span class="description">' . $bday_line . '</span>';
                    } catch ( Exception $e ) {
                        // Invalid date, ignore
                    }
                }
            }
        }
        return '<div class="spar-activity-meta">' . implode( '<br>', $lines ) . '</div>';
    }

    protected function column_referral_stats( $item ) {
        $stats = $item->referral_stats;
        return sprintf(
            '<div class="spar-referral-stats">
				<div><strong>%s</strong> %s</div>
				<div><strong>%s</strong> %s</div>
				<div><strong>%s</strong> %s %s</div>
			</div>',
            number_format_i18n( (int) $stats['total_clicks'] ),
            esc_html__( 'clicks', 'simple-points-and-rewards' ),
            number_format_i18n( (int) $stats['successful_referrals'] ),
            esc_html__( 'referrals', 'simple-points-and-rewards' ),
            number_format_i18n( (float) $stats['total_points_earned'] ),
            esc_html__( 'points earned', 'simple-points-and-rewards' ),
            esc_html__( 'from referrals', 'simple-points-and-rewards' )
        );
    }

    protected function column_referral_url( $item ) {
        if ( empty( $item->referral_url ) ) {
            return '-';
        }
        return sprintf(
            '<div class="spar-referral-url">
				<input type="text" readonly class="spar-referral-url-input" value="%s" style="font-size: 11px;" />
				<button type="button" class="button button-small spar-copy-url" data-url="%s">%s</button>
			</div>',
            esc_attr( $item->referral_url ),
            esc_attr( $item->referral_url ),
            esc_html__( 'Copy', 'simple-points-and-rewards' )
        );
    }

    protected function column_actions( $item ) {
        // Use a consistent nonce action that matches the AJAX handler verification
        $nonce = wp_create_nonce( 'spar_update_points_nonce' );
        return sprintf(
            '<div class="spar-points-actions" data-user-id="%d" data-nonce="%s">
				<div class="spar-points-controls" style="display:flex; gap:6px; align-items:center; flex-wrap:wrap;">
					<input type="number" class="spar-points-input" placeholder="0" min="1" step="1" style="width: 100&#37;" />
					<input type="text" class="spar-points-reason" placeholder="%s" style="width: 100&#37;" />
					<button type="button" class="button button-small spar-add-points" title="%s">' . esc_html__( 'Add', 'simple-points-and-rewards' ) . '</button>
					<button type="button" class="button button-small spar-remove-points" title="%s">' . esc_html__( 'Remove', 'simple-points-and-rewards' ) . '</button>
				</div>
				<div class="spar-points-loading" style="display: none;">
					<span class="spinner is-active" style="float: none; margin: 0;"></span>
				</div>
			</div>',
            absint( $item->ID ),
            esc_attr( $nonce ),
            esc_attr__( 'Reason (optional)', 'simple-points-and-rewards' ),
            esc_attr__( 'Add Points', 'simple-points-and-rewards' ),
            esc_attr__( 'Remove Points', 'simple-points-and-rewards' )
        );
    }

    protected function column_customer_actions( $item ) {
        $user_id = absint( $item->ID );
        $nonce = wp_create_nonce( 'spar_customer_detail_view_' . $user_id );
        $url = add_query_arg( array(
            'page'    => 'spar-customer',
            'user_id' => $user_id,
            'nonce'   => $nonce,
        ), admin_url( 'admin.php' ) );
        return sprintf( '<a class="button button-primary button" href="%s">%s</a>', esc_url( $url ), esc_html__( 'View Customer', 'simple-points-and-rewards' ) );
    }

    protected function get_table_classes() {
        $classes = parent::get_table_classes();
        $column_count = 0;
        $columns = $this->get_columns();
        if ( is_array( $columns ) ) {
            $column_count = count( $columns );
        }
        $classes[] = 'widefat';
        $classes[] = 'fixed';
        $classes[] = 'striped';
        $classes[] = 'spar-customer-points-table';
        if ( $column_count > 0 ) {
            $classes[] = 'spar-customer-points-columns-' . $column_count;
        }
        return $classes;
    }

    public function extra_tablenav( $which ) {
        if ( 'top' === $which ) {
            ?>
			<div class="alignleft actions">
				<select name="points_filter" id="points_filter">
					<option value=""><?php 
            esc_html_e( 'All customers', 'simple-points-and-rewards' );
            ?></option>
					<?php 
            $nonce = ( isset( $_GET['spar_customer_points_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['spar_customer_points_nonce'] ) ) : '' );
            $nonce_valid = $nonce && wp_verify_nonce( $nonce, 'spar_customer_points_filters' );
            $current_filter = ( $nonce_valid && isset( $_GET['points_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['points_filter'] ) ) : '' );
            ?>
					<option value="no_points" <?php 
            selected( $current_filter, 'no_points' );
            ?>><?php 
            esc_html_e( 'No points', 'simple-points-and-rewards' );
            ?></option>
					<option value="has_points" <?php 
            selected( $current_filter, 'has_points' );
            ?>><?php 
            esc_html_e( 'Has points', 'simple-points-and-rewards' );
            ?></option>
					<option value="high_points" <?php 
            selected( $current_filter, 'high_points' );
            ?>><?php 
            esc_html_e( '1000+ points', 'simple-points-and-rewards' );
            ?></option>
				</select>
				<?php 
            wp_nonce_field( 'spar_customer_points_filters', 'spar_customer_points_nonce' );
            ?>
				<?php 
            submit_button(
                esc_html__( 'Filter', 'simple-points-and-rewards' ),
                '',
                'filter_action',
                false,
                array(
                    'id' => 'post-query-submit',
                )
            );
            ?>
			</div>
			<?php 
        }
    }

    public function no_items() {
        esc_html_e( 'No customers found.', 'simple-points-and-rewards' );
    }

}

function spar_customer_points_admin_page_callback() {
    $expiry_enabled = function_exists( 'spar_points_expiry_is_enabled' ) && spar_points_expiry_is_enabled();
    $expiry_days = 0;
    if ( $expiry_enabled && function_exists( 'spar_points_expiry_get_settings' ) ) {
        $expiry_settings = spar_points_expiry_get_settings();
        $expiry_days = ( isset( $expiry_settings['expiry_days'] ) ? max( 0, (int) $expiry_settings['expiry_days'] ) : 0 );
    }
    $roles = spar_customer_points_get_roles();
    $table = new SPAR_Customer_Points_List_Table(array(
        'points_expiry_enabled' => $expiry_enabled,
        'points_expiry_days'    => $expiry_days,
    ));
    $manual_result_message = '';
    if ( $expiry_enabled && isset( $_GET['spar_expiry_checked'] ) ) {
        $expired_count = ( isset( $_GET['spar_expired_count'] ) ? absint( $_GET['spar_expired_count'] ) : 0 );
        $notified_count = ( isset( $_GET['spar_notified_count'] ) ? absint( $_GET['spar_notified_count'] ) : 0 );
        $initialised_count = ( isset( $_GET['spar_initialised_count'] ) ? absint( $_GET['spar_initialised_count'] ) : 0 );
        $manual_result_message = sprintf(
            esc_html__( 'Points expiry check complete. Expired: %1$d, Reminders sent: %2$d, Profiles initialised: %3$d.', 'simple-points-and-rewards' ),
            $expired_count,
            $notified_count,
            $initialised_count
        );
    }
    ?>
	<div class="wrap">
		<?php 
    spar_render_admin_header( esc_html__( 'Customer Points', 'simple-points-and-rewards' ) );
    ?>

		<a href="<?php 
    echo esc_url( wp_nonce_url( add_query_arg( 'action', 'spar_export_customer_points', admin_url( 'admin-post.php' ) ), 'spar_export_customer_points' ) );
    ?>" class="button" style="float: right; margin: 12px 0 0 12px;" title="<?php 
    esc_attr_e( 'Download all customer points balances and lifetime earned totals as a CSV file.', 'simple-points-and-rewards' );
    ?>">
			<?php 
    esc_html_e( 'Export CSV', 'simple-points-and-rewards' );
    ?>
		</a>

		<?php 
    spar_customer_points_render_notice();
    ?>
		<?php 
    if ( $manual_result_message ) {
        ?>
		<div class="notice notice-success is-dismissible">
			<p><?php 
        echo esc_html( $manual_result_message );
        ?></p>
		</div>
		<?php 
    }
    ?>
		<p><?php 
    esc_html_e( 'Manage customer points and view referral statistics for all customers.', 'simple-points-and-rewards' );
    ?></p>
		<?php 
    require dirname( __DIR__ ) . '/templates/add-remove-points-panel.php';
    ?>
		<?php 
    if ( $expiry_enabled ) {
        ?>
		<form method="post" action="<?php 
        echo esc_url( admin_url( 'admin-post.php' ) );
        ?>" class="spar-expiry-actions" style="margin: 1em 0;">
			<?php 
        wp_nonce_field( 'spar_manual_points_expiry', 'spar_manual_points_expiry_nonce' );
        ?>
			<input type="hidden" name="action" value="spar_run_points_expiry" />
			<button type="submit" class="button">
				<?php 
        esc_html_e( 'Check Points Expiry Now', 'simple-points-and-rewards' );
        ?>
			</button>
			<span class="description" style="margin-left: 8px;">
			<small>
				<?php 
        esc_html_e( 'Run the inactivity expiry process immediately instead of waiting for the daily cron.', 'simple-points-and-rewards' );
        ?>
			</small>
			</span>
		</form>
		<?php 
    }
    ?>
		
		<form method="get">
			<input type="hidden" name="page" value="spar-customer-points" />
			<?php 
    $table->prepare_items();
    $table->search_box( esc_html__( 'Search customers', 'simple-points-and-rewards' ), 'customer_search' );
    $table->display();
    ?>
		</form>
	</div>
	<?php 
}

/**
 * Get selectable WordPress roles for the customer points adjustment form.
 *
 * @return array<string,string>
 */
function spar_customer_points_get_roles() {
    $roles = array();
    if ( function_exists( 'wp_roles' ) && wp_roles() ) {
        foreach ( wp_roles()->roles as $role_key => $role_def ) {
            $role_name = ( is_array( $role_def ) && isset( $role_def['name'] ) ? $role_def['name'] : $role_key );
            $roles[sanitize_key( $role_key )] = translate_user_role( $role_name );
        }
    }
    return $roles;
}

/**
 * Render notices from customer point adjustment redirects.
 */
function spar_customer_points_render_notice() {
    $notice = ( isset( $_GET['spar_points_notice'] ) ? sanitize_key( wp_unslash( $_GET['spar_points_notice'] ) ) : '' );
    if ( '' === $notice ) {
        return;
    }
    $type = 'success';
    $message = '';
    if ( 'specific_success' === $notice ) {
        $user_id = ( isset( $_GET['spar_points_user_id'] ) ? absint( $_GET['spar_points_user_id'] ) : 0 );
        $previous = ( isset( $_GET['spar_points_previous'] ) ? (int) sanitize_text_field( wp_unslash( $_GET['spar_points_previous'] ) ) : 0 );
        $new = ( isset( $_GET['spar_points_new'] ) ? (int) sanitize_text_field( wp_unslash( $_GET['spar_points_new'] ) ) : 0 );
        $user = ( $user_id ? get_userdata( $user_id ) : false );
        $name = ( $user ? $user->display_name : sprintf( esc_html__( 'User #%d', 'simple-points-and-rewards' ), $user_id ) );
        $message = sprintf(
            /* translators: 1: user display name, 2: previous balance, 3: new balance. */
            esc_html__( 'Points updated for %1$s. Previous balance: %2$s. New balance: %3$s.', 'simple-points-and-rewards' ),
            esc_html( $name ),
            number_format_i18n( $previous ),
            number_format_i18n( $new )
        );
    } elseif ( 'no_change' === $notice ) {
        $type = 'warning';
        $message = esc_html__( 'No balance change was needed for that user.', 'simple-points-and-rewards' );
    } elseif ( 'blocked' === $notice ) {
        $type = 'warning';
        $message = esc_html__( 'The points could not be added because the user is banned.', 'simple-points-and-rewards' );
    } elseif ( 'invalid_points' === $notice ) {
        $type = 'error';
        $message = esc_html__( 'Please enter a non-zero points amount.', 'simple-points-and-rewards' );
    } elseif ( 'missing_user' === $notice ) {
        $type = 'error';
        $message = esc_html__( 'Please enter a username.', 'simple-points-and-rewards' );
    } elseif ( 'user_not_found' === $notice ) {
        $type = 'error';
        $message = esc_html__( 'No user was found with that username.', 'simple-points-and-rewards' );
    } elseif ( 'invalid_role' === $notice ) {
        $type = 'error';
        $message = esc_html__( 'Please select a valid user role.', 'simple-points-and-rewards' );
    } elseif ( 'invalid_request' === $notice ) {
        $type = 'error';
        $message = esc_html__( 'Invalid points adjustment request.', 'simple-points-and-rewards' );
    }
    if ( '' === $message ) {
        return;
    }
    printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $type ), esc_html( $message ) );
}

/**
 * Redirect back to the customer points page with a notice code.
 *
 * @param string $notice Notice code.
 * @param array  $args   Additional query args.
 */
function spar_customer_points_redirect_with_notice(  $notice, $args = array()  ) {
    $args = array_merge( array(
        'page'               => 'spar-customer-points',
        'spar_points_notice' => sanitize_key( $notice ),
    ), $args );
    wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
    exit;
}

/**
 * Parse a signed points value from a request field.
 *
 * @param string $field Request field name.
 * @return int
 */
function spar_customer_points_get_signed_request_value(  $field  ) {
    $raw = ( isset( $_POST[$field] ) ? sanitize_text_field( wp_unslash( $_POST[$field] ) ) : '' );
    if ( '' === $raw || !preg_match( '/^-?\\d+$/', $raw ) ) {
        return 0;
    }
    return (int) $raw;
}

/**
 * Return the transient key for a batch job.
 *
 * @param string $job_id Job identifier.
 * @return string
 */
function spar_customer_points_batch_transient_key(  $job_id  ) {
    return 'spar_points_batch_' . sanitize_key( $job_id );
}

/**
 * Return the batch size for customer points batch adjustment requests.
 *
 * @return int
 */
function spar_customer_points_batch_size() {
    $size = (int) apply_filters( 'spar_customer_points_batch_size', 50 );
    return ( $size > 0 ? $size : 50 );
}

/**
 * Fetch a stored customer points batch job.
 *
 * @param string $job_id Job identifier.
 * @return array|null
 */
function spar_customer_points_get_batch_job(  $job_id  ) {
    $job_id = sanitize_key( $job_id );
    if ( '' === $job_id ) {
        return null;
    }
    $job = get_transient( spar_customer_points_batch_transient_key( $job_id ) );
    return ( is_array( $job ) ? $job : null );
}

/**
 * Persist a customer points batch job.
 *
 * @param string $job_id Job identifier.
 * @param array  $job    Job data.
 */
function spar_customer_points_save_batch_job(  $job_id, $job  ) {
    set_transient( spar_customer_points_batch_transient_key( $job_id ), $job, 12 * HOUR_IN_SECONDS );
}

/**
 * Count batch updates that can still be undone.
 *
 * @param array $job Job data.
 * @return int
 */
function spar_customer_points_count_undoable_updates(  $job  ) {
    if ( empty( $job['updates'] ) || !is_array( $job['updates'] ) ) {
        return 0;
    }
    $count = 0;
    foreach ( $job['updates'] as $update ) {
        if ( empty( $update['undone'] ) ) {
            $count++;
        }
    }
    return $count;
}

/**
 * Apply a signed points adjustment and return the before/after state.
 *
 * @param int    $user_id       User ID.
 * @param int    $points_change Signed points change.
 * @param string $reason        Log note.
 * @param string $action_id     Log action ID.
 * @param array  $context       Optional update context.
 * @param bool   $log           Whether to write to the activity log. Default true.
 * @return array
 */
function spar_customer_points_apply_signed_adjustment(
    $user_id,
    $points_change,
    $reason,
    $action_id,
    $context = array(),
    $log = true
) {
    $user_id = absint( $user_id );
    $points_change = (int) $points_change;
    $previous = spar_get_user_points( $user_id );
    $action = ( $points_change < 0 ? 'remove' : 'add' );
    $amount = absint( $points_change );
    if ( $amount <= 0 || !get_userdata( $user_id ) ) {
        return array(
            'previous' => (int) $previous,
            'new'      => (int) $previous,
            'change'   => 0,
            'status'   => 'skipped',
            'message'  => esc_html__( 'Invalid points adjustment.', 'simple-points-and-rewards' ),
        );
    }
    if ( 'remove' === $action ) {
        $amount = min( $amount, max( 0, (int) $previous ) );
        if ( $amount <= 0 ) {
            return array(
                'previous' => (int) $previous,
                'new'      => (int) $previous,
                'change'   => 0,
                'status'   => 'unchanged',
                'message'  => esc_html__( 'No points were available to remove.', 'simple-points-and-rewards' ),
            );
        }
    }
    if ( !$log ) {
        add_filter( 'spar_skip_log_points_event', '__return_true' );
    }
    $result = spar_update_user_points(
        $user_id,
        $amount,
        $action,
        $reason,
        $action_id,
        $context
    );
    $new = spar_get_user_points( $user_id );
    if ( !$log ) {
        remove_filter( 'spar_skip_log_points_event', '__return_true' );
    }
    $change = (int) $new - (int) $previous;
    $status = ( 0 === $change ? 'unchanged' : 'updated' );
    $message = '';
    if ( is_array( $result ) && isset( $result['status'] ) && 'blocked' === $result['status'] ) {
        $status = 'skipped';
        $message = esc_html__( 'Skipped because the user is banned.', 'simple-points-and-rewards' );
    } elseif ( 0 === $change ) {
        $message = esc_html__( 'No balance change was needed.', 'simple-points-and-rewards' );
    }
    return array(
        'previous' => (int) $previous,
        'new'      => (int) $new,
        'change'   => $change,
        'status'   => $status,
        'message'  => $message,
    );
}

add_action( 'admin_post_spar_customer_points_adjustment', 'spar_handle_customer_points_adjustment_submit' );
/**
 * Handle the Add New Points form submission.
 */
function spar_handle_customer_points_adjustment_submit() {
    if ( !current_user_can( 'manage_woocommerce' ) ) {
        wp_die( esc_html__( 'Insufficient permissions.', 'simple-points-and-rewards' ) );
    }
    $nonce = ( isset( $_POST['spar_customer_points_adjustment_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['spar_customer_points_adjustment_nonce'] ) ) : '' );
    if ( !$nonce || !wp_verify_nonce( $nonce, 'spar_customer_points_adjustment' ) ) {
        spar_customer_points_redirect_with_notice( 'invalid_request' );
    }
    $target = ( isset( $_POST['spar_points_target'] ) ? sanitize_key( wp_unslash( $_POST['spar_points_target'] ) ) : 'specific' );
    $points_change = spar_customer_points_get_signed_request_value( 'spar_points_change' );
    $log_activity = isset( $_POST['spar_points_log_activity'] ) && '1' === sanitize_key( wp_unslash( $_POST['spar_points_log_activity'] ) );
    if ( 0 === $points_change ) {
        spar_customer_points_redirect_with_notice( 'invalid_points' );
    }
    if ( 'specific' === $target ) {
        $username = ( isset( $_POST['spar_points_username'] ) ? sanitize_user( wp_unslash( $_POST['spar_points_username'] ), true ) : '' );
        if ( '' === $username ) {
            spar_customer_points_redirect_with_notice( 'missing_user' );
        }
        $user = get_user_by( 'login', $username );
        if ( !$user ) {
            spar_customer_points_redirect_with_notice( 'user_not_found' );
        }
        $result = spar_customer_points_apply_signed_adjustment(
            $user->ID,
            $points_change,
            esc_html__( 'Admin adjustment', 'simple-points-and-rewards' ),
            'admin_adjustment',
            array(
                'source' => 'customer_points_add_new',
                'target' => 'specific_user',
            ),
            $log_activity
        );
        if ( 'skipped' === $result['status'] ) {
            spar_customer_points_redirect_with_notice( 'blocked' );
        }
        if ( 0 === (int) $result['change'] ) {
            spar_customer_points_redirect_with_notice( 'no_change' );
        }
        spar_customer_points_redirect_with_notice( 'specific_success', array(
            'spar_points_user_id'  => absint( $user->ID ),
            'spar_points_previous' => (int) $result['previous'],
            'spar_points_new'      => (int) $result['new'],
        ) );
    }
    if ( 'all' !== $target ) {
        spar_customer_points_redirect_with_notice( 'invalid_request' );
    }
    $role = ( isset( $_POST['spar_points_user_role'] ) ? sanitize_key( wp_unslash( $_POST['spar_points_user_role'] ) ) : '' );
    $roles = spar_customer_points_get_roles();
    if ( '' !== $role && !isset( $roles[$role] ) ) {
        spar_customer_points_redirect_with_notice( 'invalid_role' );
    }
    $job_id = ( function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : wp_generate_password( 32, false, false ) );
    $job_id = sanitize_key( $job_id );
    $job = array(
        'id'            => $job_id,
        'points_change' => (int) $points_change,
        'role'          => $role,
        'reason'        => esc_html__( 'Admin points adjustment', 'simple-points-and-rewards' ),
        'log_activity'  => $log_activity,
        'created_by'    => get_current_user_id(),
        'created_at'    => time(),
        'batch_size'    => spar_customer_points_batch_size(),
        'status'        => 'pending',
        'processed_ids' => array(),
        'updates'       => array(),
    );
    spar_customer_points_save_batch_job( $job_id, $job );
    $url = add_query_arg( array(
        'page'     => 'spar-customer-points-bulk',
        'job_id'   => $job_id,
        '_wpnonce' => wp_create_nonce( 'spar_customer_points_batch_' . $job_id ),
    ), admin_url( 'admin.php' ) );
    wp_safe_redirect( $url );
    exit;
}

/**
 * Render the bulk customer points adjustment page.
 */
function spar_customer_points_bulk_admin_page_callback() {
    if ( !current_user_can( 'manage_woocommerce' ) ) {
        wp_die( esc_html__( 'You do not have permission to access this page.', 'simple-points-and-rewards' ) );
    }
    $job_id = ( isset( $_GET['job_id'] ) ? sanitize_key( wp_unslash( $_GET['job_id'] ) ) : '' );
    $nonce = ( isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '' );
    $job = ( $job_id && $nonce && wp_verify_nonce( $nonce, 'spar_customer_points_batch_' . $job_id ) ? spar_customer_points_get_batch_job( $job_id ) : null );
    echo '<div class="wrap spar-admin-page spar-migration-page spar-customer-points-bulk-page">';
    if ( function_exists( 'spar_render_admin_header' ) ) {
        spar_render_admin_header( esc_html__( 'Bulk Points Update', 'simple-points-and-rewards' ) );
    } else {
        echo '<h1>' . esc_html__( 'Bulk Points Update', 'simple-points-and-rewards' ) . '</h1>';
    }
    if ( !$job ) {
        echo '<div class="notice notice-error"><p>' . esc_html__( 'This bulk points job could not be found or has expired.', 'simple-points-and-rewards' ) . '</p></div>';
        echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=spar-customer-points' ) ) . '">' . esc_html__( 'Back to Customer Points', 'simple-points-and-rewards' ) . '</a></p>';
        echo '</div>';
        return;
    }
    $roles = spar_customer_points_get_roles();
    $role = ( isset( $job['role'] ) ? sanitize_key( $job['role'] ) : '' );
    $role_label = ( '' === $role ? esc_html__( 'All Roles', 'simple-points-and-rewards' ) : (( isset( $roles[$role] ) ? $roles[$role] : $role )) );
    $change = ( isset( $job['points_change'] ) ? (int) $job['points_change'] : 0 );
    echo '<div class="spar-admin-content">';
    echo '<div id="spar-customer-points-bulk-app" class="spar-customer-points-bulk-app" data-job-id="' . esc_attr( $job_id ) . '">';
    echo '<div class="spar-customer-points-bulk-summary">';
    echo '<p><strong>' . esc_html__( 'Target role:', 'simple-points-and-rewards' ) . '</strong> ' . esc_html( $role_label ) . '</p>';
    echo '<p><strong>' . esc_html__( 'Points change:', 'simple-points-and-rewards' ) . '</strong> ' . esc_html( number_format_i18n( $change ) ) . '</p>';
    echo '</div>';
    echo '<div class="spar-customer-points-bulk-controls">';
    echo '<button type="button" class="button button-secondary" id="spar-customer-points-bulk-stop">' . esc_html__( 'Stop', 'simple-points-and-rewards' ) . '</button> ';
    echo '<button type="button" class="button button-primary" id="spar-customer-points-bulk-undo" hidden disabled>' . esc_html__( 'Undo Processed Updates', 'simple-points-and-rewards' ) . '</button> ';
    echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=spar-customer-points' ) ) . '">' . esc_html__( 'Back to Customer Points', 'simple-points-and-rewards' ) . '</a>';
    echo '<span class="spinner is-active" id="spar-customer-points-bulk-spinner" style="float:none;"></span>';
    echo '</div>';
    echo '<div class="spar-customer-points-bulk-progress" aria-live="polite">';
    echo '<p id="spar-customer-points-bulk-status">' . esc_html__( 'Preparing bulk update...', 'simple-points-and-rewards' ) . '</p>';
    echo '<progress id="spar-customer-points-bulk-progress" value="0" max="100"></progress>';
    echo '</div>';
    echo '<div class="spar-customer-points-bulk-table-wrap">';
    echo '<table class="widefat striped spar-customer-points-bulk-table">';
    echo '<thead><tr>';
    echo '<th scope="col">' . esc_html__( 'User', 'simple-points-and-rewards' ) . '</th>';
    echo '<th scope="col">' . esc_html__( 'Username', 'simple-points-and-rewards' ) . '</th>';
    echo '<th scope="col">' . esc_html__( 'Previous Balance', 'simple-points-and-rewards' ) . '</th>';
    echo '<th scope="col">' . esc_html__( 'Change', 'simple-points-and-rewards' ) . '</th>';
    echo '<th scope="col">' . esc_html__( 'New Balance', 'simple-points-and-rewards' ) . '</th>';
    echo '<th scope="col">' . esc_html__( 'Status', 'simple-points-and-rewards' ) . '</th>';
    echo '<th scope="col">' . esc_html__( 'Undo', 'simple-points-and-rewards' ) . '</th>';
    echo '</tr></thead>';
    echo '<tbody id="spar-customer-points-bulk-results"></tbody>';
    echo '</table>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
}

add_action( 'wp_ajax_spar_customer_points_batch_process', 'spar_customer_points_batch_process_ajax' );
/**
 * Process a batch of users for a bulk points adjustment.
 */
function spar_customer_points_batch_process_ajax() {
    if ( !current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( array(
            'message' => esc_html__( 'Insufficient permissions.', 'simple-points-and-rewards' ),
        ), 403 );
    }
    $job_id = ( isset( $_POST['job_id'] ) ? sanitize_key( wp_unslash( $_POST['job_id'] ) ) : '' );
    if ( '' === $job_id ) {
        wp_send_json_error( array(
            'message' => esc_html__( 'Missing batch job.', 'simple-points-and-rewards' ),
        ), 400 );
    }
    check_ajax_referer( 'spar_customer_points_batch_' . $job_id, 'nonce' );
    $job = spar_customer_points_get_batch_job( $job_id );
    if ( !$job ) {
        wp_send_json_error( array(
            'message' => esc_html__( 'This bulk points job could not be found or has expired.', 'simple-points-and-rewards' ),
        ), 404 );
    }
    if ( isset( $job['status'] ) && 'undone' === $job['status'] ) {
        wp_send_json_error( array(
            'message' => esc_html__( 'This bulk points job has already been undone.', 'simple-points-and-rewards' ),
        ), 400 );
    }
    $offset = ( isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0 );
    $batch_size = ( isset( $job['batch_size'] ) ? max( 1, (int) $job['batch_size'] ) : spar_customer_points_batch_size() );
    $role = ( isset( $job['role'] ) ? sanitize_key( $job['role'] ) : '' );
    $log_activity = !isset( $job['log_activity'] ) || (bool) $job['log_activity'];
    $args = array(
        'number'      => $batch_size,
        'offset'      => $offset,
        'fields'      => 'ids',
        'orderby'     => 'ID',
        'order'       => 'ASC',
        'count_total' => true,
    );
    if ( '' !== $role ) {
        $args['role__in'] = array($role);
    }
    $query = new WP_User_Query($args);
    $user_ids = $query->get_results();
    $total = (int) $query->get_total();
    $entries = array();
    if ( !isset( $job['processed_ids'] ) || !is_array( $job['processed_ids'] ) ) {
        $job['processed_ids'] = array();
    }
    if ( !isset( $job['updates'] ) || !is_array( $job['updates'] ) ) {
        $job['updates'] = array();
    }
    foreach ( $user_ids as $user_id ) {
        $user_id = absint( $user_id );
        if ( isset( $job['processed_ids'][$user_id] ) ) {
            continue;
        }
        $user = get_userdata( $user_id );
        if ( !$user ) {
            $job['processed_ids'][$user_id] = 1;
            continue;
        }
        $result = spar_customer_points_apply_signed_adjustment(
            $user_id,
            (int) $job['points_change'],
            ( isset( $job['reason'] ) ? (string) $job['reason'] : esc_html__( 'Admin points adjustment', 'simple-points-and-rewards' ) ),
            'admin_bulk_adjustment',
            array(
                'source'      => 'customer_points_bulk',
                'bulk_job_id' => $job_id,
            ),
            $log_activity
        );
        $job['processed_ids'][$user_id] = 1;
        if ( 0 !== (int) $result['change'] ) {
            $job['updates'][$user_id] = array(
                'user_id'  => $user_id,
                'previous' => (int) $result['previous'],
                'new'      => (int) $result['new'],
                'change'   => (int) $result['change'],
                'undone'   => false,
            );
        }
        $entries[] = array(
            'user_id'      => $user_id,
            'display_name' => $user->display_name,
            'user_login'   => $user->user_login,
            'user_email'   => $user->user_email,
            'previous'     => (int) $result['previous'],
            'change'       => (int) $result['change'],
            'new'          => (int) $result['new'],
            'status'       => $result['status'],
            'message'      => $result['message'],
            'can_undo'     => 0 !== (int) $result['change'],
        );
    }
    $next_offset = $offset + count( $user_ids );
    $complete = $next_offset >= $total;
    $job['status'] = ( $complete ? 'complete' : 'running' );
    spar_customer_points_save_batch_job( $job_id, $job );
    wp_send_json_success( array(
        'entries'        => $entries,
        'complete'       => $complete,
        'next_offset'    => $next_offset,
        'processed'      => count( $job['processed_ids'] ),
        'total'          => $total,
        'updated_count'  => count( $job['updates'] ),
        'undoable_count' => spar_customer_points_count_undoable_updates( $job ),
    ) );
}

add_action( 'wp_ajax_spar_customer_points_batch_stop', 'spar_customer_points_batch_stop_ajax' );
/**
 * Mark a customer points batch job as stopped.
 */
function spar_customer_points_batch_stop_ajax() {
    if ( !current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( array(
            'message' => esc_html__( 'Insufficient permissions.', 'simple-points-and-rewards' ),
        ), 403 );
    }
    $job_id = ( isset( $_POST['job_id'] ) ? sanitize_key( wp_unslash( $_POST['job_id'] ) ) : '' );
    if ( '' === $job_id ) {
        wp_send_json_error( array(
            'message' => esc_html__( 'Missing batch job.', 'simple-points-and-rewards' ),
        ), 400 );
    }
    check_ajax_referer( 'spar_customer_points_batch_' . $job_id, 'nonce' );
    $job = spar_customer_points_get_batch_job( $job_id );
    if ( !$job ) {
        wp_send_json_error( array(
            'message' => esc_html__( 'This bulk points job could not be found or has expired.', 'simple-points-and-rewards' ),
        ), 404 );
    }
    if ( empty( $job['status'] ) || !in_array( $job['status'], array('complete', 'undone'), true ) ) {
        $job['status'] = 'stopped';
        spar_customer_points_save_batch_job( $job_id, $job );
    }
    wp_send_json_success( array(
        'undoable_count' => spar_customer_points_count_undoable_updates( $job ),
    ) );
}

add_action( 'wp_ajax_spar_customer_points_batch_undo', 'spar_customer_points_batch_undo_ajax' );
/**
 * Undo one or more processed updates for a customer points batch job.
 */
function spar_customer_points_batch_undo_ajax() {
    if ( !current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( array(
            'message' => esc_html__( 'Insufficient permissions.', 'simple-points-and-rewards' ),
        ), 403 );
    }
    $job_id = ( isset( $_POST['job_id'] ) ? sanitize_key( wp_unslash( $_POST['job_id'] ) ) : '' );
    if ( '' === $job_id ) {
        wp_send_json_error( array(
            'message' => esc_html__( 'Missing batch job.', 'simple-points-and-rewards' ),
        ), 400 );
    }
    check_ajax_referer( 'spar_customer_points_batch_' . $job_id, 'nonce' );
    $job = spar_customer_points_get_batch_job( $job_id );
    if ( !$job || empty( $job['updates'] ) || !is_array( $job['updates'] ) ) {
        wp_send_json_error( array(
            'message' => esc_html__( 'There are no processed updates to undo.', 'simple-points-and-rewards' ),
        ), 404 );
    }
    $user_id = ( isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0 );
    $limit = ( $user_id ? 1 : spar_customer_points_batch_size() );
    $undo_rows = array();
    $processed = 0;
    $undo_note = esc_html__( 'Undo: Admin points adjustment', 'simple-points-and-rewards' );
    $log_activity = !isset( $job['log_activity'] ) || (bool) $job['log_activity'];
    $updates = $job['updates'];
    foreach ( $updates as $update_user_id => $update ) {
        $update_user_id = absint( $update_user_id );
        if ( $user_id && $user_id !== $update_user_id ) {
            continue;
        }
        if ( !empty( $update['undone'] ) ) {
            continue;
        }
        $change = ( isset( $update['change'] ) ? (int) $update['change'] : 0 );
        if ( 0 === $change ) {
            $job['updates'][$update_user_id]['undone'] = true;
            continue;
        }
        $undo_result = spar_customer_points_apply_signed_adjustment(
            $update_user_id,
            -1 * $change,
            $undo_note,
            'admin_undo',
            array(
                'source'      => 'customer_points_bulk_undo',
                'bulk_job_id' => $job_id,
            ),
            $log_activity
        );
        $did_change = 0 !== (int) $undo_result['change'];
        if ( $did_change ) {
            $job['updates'][$update_user_id]['undone'] = true;
        }
        $undo_rows[] = array(
            'user_id'      => $update_user_id,
            'previous'     => (int) $undo_result['previous'],
            'new'          => (int) $undo_result['new'],
            'change'       => (int) $undo_result['change'],
            'status'       => ( $did_change ? 'undone' : $undo_result['status'] ),
            'message'      => ( $did_change ? esc_html__( 'Undone', 'simple-points-and-rewards' ) : $undo_result['message'] ),
            'undo_success' => $did_change,
        );
        $processed++;
        if ( $processed >= $limit ) {
            break;
        }
    }
    $undoable_count = spar_customer_points_count_undoable_updates( $job );
    if ( 0 === $undoable_count ) {
        $job['status'] = 'undone';
    }
    spar_customer_points_save_batch_job( $job_id, $job );
    wp_send_json_success( array(
        'rows'           => $undo_rows,
        'complete'       => 0 === $undoable_count,
        'undoable_count' => $undoable_count,
    ) );
}

/**
 * Enqueue styles for customer points admin page
 */
function spar_enqueue_customer_points_styles(  $hook  ) {
    $current_page = ( isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '' );
    $is_bulk_page = 'spar-customer-points-bulk' === $current_page || false !== strpos( $hook, 'spar-customer-points-bulk' );
    $is_customer_points_page = !$is_bulk_page && ('spar-customer-points' === $current_page || false !== strpos( $hook, 'spar-customer-points' ));
    $is_log_page = !$is_bulk_page && ('spar-log' === $current_page || false !== strpos( $hook, 'spar-log' ));
    if ( !$is_customer_points_page && !$is_bulk_page && !$is_log_page ) {
        return;
    }
    $plugin_assets_url = trailingslashit( SPAR_PLUGIN_URL );
    if ( $is_bulk_page ) {
        wp_enqueue_style(
            'spar-admin-migration',
            $plugin_assets_url . 'assets/css/admin-migration.css',
            array(),
            SPAR_VERSION
        );
    }
    wp_enqueue_style(
        'spar-customer-points-table',
        $plugin_assets_url . 'assets/css/customer-points-table.css',
        ( $is_bulk_page ? array('spar-admin-migration') : array() ),
        SPAR_VERSION
    );
    if ( $is_customer_points_page ) {
        wp_add_inline_style( 'spar-customer-points-table', '.spar-customer-points-table thead th.column-points{ text-align:center !important; }' . '.spar-customer-points-table thead th.column-points a{ text-align:center !important; display:inline-flex; justify-content:center; align-items:center; gap:4px; width:100%; }' );
    }
    // On the log page the panel styles are all we need; skip the table-width stylesheets.
    // Enqueue small stylesheet for column widths; choose variant by option (not needed on log page).
    if ( $is_customer_points_page ) {
        $options = get_option( 'spar_options', array() );
        $gift_offers_enabled = !empty( $options['earn']['referral']['enabled'] ) && !empty( $options['earn']['referral']['offer_enabled'] );
        $variant = ( $gift_offers_enabled ? 'with-gift' : 'no-gift' );
        wp_enqueue_style(
            'spar-customer-points-widths',
            $plugin_assets_url . 'assets/css/customer-points-table-' . $variant . '.css',
            array('spar-customer-points-table'),
            SPAR_VERSION . '.5'
        );
    }
}

add_action( 'admin_enqueue_scripts', 'spar_enqueue_customer_points_styles' );
/**
 * Enqueue scripts for customer points admin page
 */
function spar_enqueue_customer_points_scripts(  $hook  ) {
    // Load assets if on the customer points page (match via hook OR page query param)
    $current_page = ( isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '' );
    $is_bulk_page = 'spar-customer-points-bulk' === $current_page || false !== strpos( $hook, 'spar-customer-points-bulk' );
    $is_customer_points_page = !$is_bulk_page && ('spar-customer-points' === $current_page || false !== strpos( $hook, 'spar-customer-points' ));
    $is_log_page = !$is_bulk_page && ('spar-log' === $current_page || false !== strpos( $hook, 'spar-log' ));
    if ( !$is_customer_points_page && !$is_bulk_page && !$is_log_page ) {
        return;
        // Not our page
    }
    if ( !isset( $plugin_assets_url ) ) {
        $plugin_assets_url = trailingslashit( SPAR_PLUGIN_URL );
    }
    if ( $is_bulk_page ) {
        $job_id = ( isset( $_GET['job_id'] ) ? sanitize_key( wp_unslash( $_GET['job_id'] ) ) : '' );
        wp_enqueue_script(
            'spar-customer-points-bulk',
            $plugin_assets_url . 'assets/js/customer-points-bulk.js',
            array('jquery'),
            SPAR_VERSION,
            true
        );
        wp_localize_script( 'spar-customer-points-bulk', 'sparCustomerPointsBulk', array(
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'jobId'     => $job_id,
            'nonce'     => ( $job_id ? wp_create_nonce( 'spar_customer_points_batch_' . $job_id ) : '' ),
            'batchSize' => spar_customer_points_batch_size(),
            'strings'   => array(
                'processing'        => esc_html__( 'Processing %1$d of %2$d users...', 'simple-points-and-rewards' ),
                'processingNoTotal' => esc_html__( 'Processing users...', 'simple-points-and-rewards' ),
                'complete'          => esc_html__( 'Bulk points update complete.', 'simple-points-and-rewards' ),
                'stopped'           => esc_html__( 'Bulk points update stopped.', 'simple-points-and-rewards' ),
                'failed'            => esc_html__( 'Bulk points update failed: %s', 'simple-points-and-rewards' ),
                'undoing'           => esc_html__( 'Undoing processed updates...', 'simple-points-and-rewards' ),
                'undoComplete'      => esc_html__( 'Processed updates have been undone.', 'simple-points-and-rewards' ),
                'undoFailed'        => esc_html__( 'Undo failed: %s', 'simple-points-and-rewards' ),
                'updated'           => esc_html__( 'Updated', 'simple-points-and-rewards' ),
                'unchanged'         => esc_html__( 'Unchanged', 'simple-points-and-rewards' ),
                'skipped'           => esc_html__( 'Skipped', 'simple-points-and-rewards' ),
                'undone'            => esc_html__( 'Undone', 'simple-points-and-rewards' ),
                'undo'              => esc_html__( 'Undo', 'simple-points-and-rewards' ),
                'confirmStop'       => esc_html__( 'Stop after the current batch finishes?', 'simple-points-and-rewards' ),
                'confirmUndoAll'    => esc_html__( 'Undo all processed updates for this batch?', 'simple-points-and-rewards' ),
            ),
        ) );
        return;
    }
    wp_enqueue_script(
        'spar-customer-points-table',
        $plugin_assets_url . 'assets/js/customer-points-table.js',
        array('jquery'),
        SPAR_VERSION,
        true
    );
    wp_localize_script( 'spar-customer-points-table', 'sparCustomerPoints', array(
        'ajaxurl' => admin_url( 'admin-ajax.php' ),
        'strings' => array(
            'confirmRemove' => esc_html__( 'Are you sure you want to remove these points?', 'simple-points-and-rewards' ),
            'processing'    => esc_html__( 'Processing...', 'simple-points-and-rewards' ),
            'success'       => esc_html__( 'Points updated successfully!', 'simple-points-and-rewards' ),
            'error'         => esc_html__( 'Error updating points. Please try again.', 'simple-points-and-rewards' ),
            'copied'        => esc_html__( 'Copied to clipboard!', 'simple-points-and-rewards' ),
            'copyFailed'    => esc_html__( 'Failed to copy. Please copy manually.', 'simple-points-and-rewards' ),
            'invalidAmount' => esc_html__( 'Please enter a valid points amount.', 'simple-points-and-rewards' ),
        ),
    ) );
    // Enqueue status management script
    wp_enqueue_script(
        'spar-customer-points-status',
        ( defined( 'SPAR_PLUGIN_URL' ) ? SPAR_PLUGIN_URL . 'assets/js/customer-points-status.js' : plugin_dir_url( dirname( __FILE__, 3 ) ) . 'assets/js/customer-points-status.js' ),
        array('jquery'),
        SPAR_VERSION,
        true
    );
    wp_localize_script( 'spar-customer-points-status', 'sparCustomerStatus', array(
        'ajaxurl' => admin_url( 'admin-ajax.php' ),
        'strings' => array(
            'updating' => esc_html__( 'Updating status...', 'simple-points-and-rewards' ),
            'success'  => esc_html__( 'Status updated.', 'simple-points-and-rewards' ),
            'error'    => esc_html__( 'Error updating status.', 'simple-points-and-rewards' ),
        ),
    ) );
}

add_action( 'admin_enqueue_scripts', 'spar_enqueue_customer_points_scripts' );