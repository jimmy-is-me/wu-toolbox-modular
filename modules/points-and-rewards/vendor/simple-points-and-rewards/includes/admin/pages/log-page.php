<?php
/**
 * Points log admin page
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// Include the WP_List_Table class
if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}

class SPAR_Points_Log_Table extends WP_List_Table {
    public function __construct() {
        parent::__construct( [
            'singular' => 'log',
            'plural'   => 'logs',
            'ajax'     => false
        ] );
    }

    public function get_columns() {
        return [
            'cb'          => '<input type="checkbox" />',
            'user'        => esc_html__( 'User', 'simple-points-and-rewards' ),
            'action'      => esc_html__( 'Action', 'simple-points-and-rewards' ),
            'type'        => esc_html__( 'Type', 'simple-points-and-rewards' ),
            'points'      => esc_html__( 'Points', 'simple-points-and-rewards' ),
            'date'        => esc_html__( 'Date', 'simple-points-and-rewards' ),
            'row_actions' => esc_html__( 'Actions', 'simple-points-and-rewards' ),
        ];
    }

    public function get_sortable_columns() {
        return [
            'user'   => [ 'user', false ],
            'type'   => [ 'type', false ],
            'points' => [ 'points', false ],
            'date'   => [ 'date', true ]
        ];
    }

    public function get_bulk_actions() {
        return [
            'undo_update'  => esc_html__( 'Undo + Update Log', 'simple-points-and-rewards' ),
            'undo_delete'  => esc_html__( 'Undo + Delete', 'simple-points-and-rewards' ),
            'delete'       => esc_html__( 'Delete', 'simple-points-and-rewards' ),
            'grant_now'    => esc_html__( 'Grant Now (Pending)', 'simple-points-and-rewards' ),
        ];
    }

    public function column_cb( $item ) {
        if ( isset( $item['type'] ) && 'pending' === $item['type'] ) {
            $pending_id = (int) str_replace( 'pending-', '', $item['id'] );
            return $pending_id > 0
                ? sprintf( '<input type="checkbox" name="pending_ids[]" value="%d" class="spar-pending-cb" />', $pending_id )
                : '';
        }

        // Check if this is an undo log
        $is_undo_log = ( isset( $item['action_id'] ) && 'admin_undo' === $item['action_id'] ) || ( strpos( $item['action'], 'Undo:' ) === 0 );
        $is_undone   = ( isset( $item['action_id'] ) && 'undone' === $item['action_id'] );
        
        $class = ( $is_undo_log || $is_undone ) ? 'spar-undone-cb' : '';

        return sprintf(
            '<input type="checkbox" name="log_ids[]" value="%s" class="%s" />',
            esc_attr( $item['id'] ),
            esc_attr( $class )
        );
    }

    public function process_bulk_action() {
        if ( $this->current_action() ) {
            check_admin_referer( 'bulk-' . $this->_args['plural'] );
            
            if ( ! current_user_can( 'manage_woocommerce' ) ) {
                return;
            }

            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $log_ids     = isset( $_POST['log_ids'] )     ? array_map( 'absint', (array) wp_unslash( $_POST['log_ids'] ) )     : [];
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $pending_ids = isset( $_POST['pending_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['pending_ids'] ) ) : [];

            if ( empty( $log_ids ) && empty( $pending_ids ) ) {
                return;
            }

            global $wpdb;
            $table = $wpdb->prefix . 'spar_points_logs';

            foreach ( $log_ids as $log_id ) {
                $log = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $log_id ), ARRAY_A );
                if ( ! $log ) {
                    continue;
                }

                $user_id = (int) $log['user_id'];
                $points  = absint( $log['points'] );
                $type    = $log['type'];
                $action  = $log['action'];
                $is_undone = ( isset( $log['action_id'] ) && 'undone' === $log['action_id'] );
                $is_undo_log = ( isset( $log['action_id'] ) && 'admin_undo' === $log['action_id'] ) || ( strpos( $log['action'], 'Undo:' ) === 0 );

                switch ( $this->current_action() ) {
                    case 'undo_update':
                        if ( $is_undone || $is_undo_log ) {
                            continue 2;
                        }
                        // Skip undo for guest entries — no user account to revert points for.
                        if ( 0 === $user_id ) {
                            continue 2;
                        }
                        // Revert points and log it
                        $reverse_action = ( 'add' === $type ) ? 'remove' : 'add';
                        $note = sprintf( __( 'Undo: %s', 'simple-points-and-rewards' ), $action );
                        spar_update_user_points( $user_id, $points, $reverse_action, $note, 'admin_undo' );
                        
                        // Mark original log as undone
                        $wpdb->update( 
                            $table, 
                            [ 'action_id' => 'undone' ], 
                            [ 'id' => $log_id ], 
                            [ '%s' ], 
                            [ '%d' ] 
                        );
                        break;

                    case 'undo_delete':
                        // Skip point-revert for guest entries — no user account to adjust.
                        if ( 0 === $user_id ) {
                            $wpdb->delete( $table, [ 'id' => $log_id ], [ '%d' ] );
                            break;
                        }
                        if ( ! $is_undone && ! $is_undo_log ) {
                            // Revert points atomically without logging
                            if ( 'add' === $type ) {
                                // Original was add, so we remove. Only decrement
                                // lifetime "Total Earned" when the original add
                                // actually contributed to it — absolute admin
                                // balance overrides ('admin_set_balance') are
                                // flagged skip_lifetime and never did.
                                $skipped_lifetime = ( isset( $log['action_id'] ) && 'admin_set_balance' === $log['action_id'] );
                                spar_adjust_user_points_balance( $user_id, -$points );
                                if ( ! $skipped_lifetime ) {
                                    spar_adjust_user_lifetime_earned( $user_id, -$points );
                                }
                            } else {
                                // Original was remove, so we add
                                spar_adjust_user_points_balance( $user_id, $points );
                            }
                        }
                        
                        // Delete the log
                        $wpdb->delete( $table, [ 'id' => $log_id ], [ '%d' ] );
                        break;

                    case 'delete':
                        // Just delete the log
                        $wpdb->delete( $table, [ 'id' => $log_id ], [ '%d' ] );
                        break;
                }
            }

            // Bulk-delete pending delayed-point rows.
            if ( 'delete' === $this->current_action() && ! empty( $pending_ids ) && function_exists( 'spar_points_delay_table_name' ) ) {
                if ( function_exists( 'spar_points_delay_ensure_table' ) ) {
                    spar_points_delay_ensure_table();
                }
                $pending_table = spar_points_delay_table_name();
                foreach ( $pending_ids as $pending_id ) {
                    if ( $pending_id > 0 ) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                        $wpdb->delete( $pending_table, [ 'id' => $pending_id ], [ '%d' ] );
                    }
                }
            }

            // Bulk-grant pending delayed-point rows immediately.
            if ( 'grant_now' === $this->current_action() && ! empty( $pending_ids ) && function_exists( 'spar_points_delay_table_name' ) && function_exists( 'spar_points_delay_apply_pending_row' ) ) {
                if ( function_exists( 'spar_points_delay_ensure_table' ) ) {
                    spar_points_delay_ensure_table();
                }
                $pending_table = spar_points_delay_table_name();
                foreach ( $pending_ids as $pending_id ) {
                    if ( $pending_id > 0 ) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d AND status = %s', $pending_table, $pending_id, 'pending' ), ARRAY_A );
                        if ( $row ) {
                            spar_points_delay_apply_pending_row( $row, $pending_table );
                        }
                    }
                }
            }
        }
    }

    public function prepare_items() {
        global $wpdb;

        $per_page = 20;
        $current_page = $this->get_pagenum();
        $offset = ( $current_page - 1 ) * $per_page;

        // Get sort parameters without direct superglobals
        $orderby = filter_input( INPUT_GET, 'orderby', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
        $order   = filter_input( INPUT_GET, 'order', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
        $orderby = $orderby ? $orderby : 'date';
        $order   = $order ? $order : 'DESC';

        // Validate orderby
        $allowed_orderby = [ 'user', 'type', 'points', 'date' ];
        if ( ! in_array( $orderby, $allowed_orderby ) ) {
            $orderby = 'date';
        }

        // Validate order
        $order = strtoupper( $order ) === 'ASC' ? 'ASC' : 'DESC';

        $table = $wpdb->prefix . 'spar_points_logs';

        // Build dynamic WHERE conditions & parameters (avoid concatenated prepared fragments for scanner compliance)
        $conditions = array();
        $params     = array();
        $pending_conditions = array( ' AND p.status = %s' );
        $pending_params     = array( 'pending' );
        $type_filter        = '';
        $nonce      = filter_input( INPUT_GET, 'spar_log_nonce', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
        $nonce_valid = $nonce ? wp_verify_nonce( $nonce, 'spar_log_filters' ) : false;

        if ( $nonce_valid ) {
            // Date range filters
            $date_from = filter_input( INPUT_GET, 'date_from', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
            if ( $date_from && strtotime( $date_from ) ) {
                $conditions[] = ' AND l.date >= %s';
                $params[]     = $date_from . ' 00:00:00';
                $pending_conditions[] = ' AND p.earned_at >= %s';
                $pending_params[]     = $date_from . ' 00:00:00';
            }
            $date_to = filter_input( INPUT_GET, 'date_to', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
            if ( $date_to && strtotime( $date_to ) ) {
                $conditions[] = ' AND l.date <= %s';
                $params[]     = $date_to . ' 23:59:59';
                $pending_conditions[] = ' AND p.earned_at <= %s';
                $pending_params[]     = $date_to . ' 23:59:59';
            }

            // Username filter (LIKE against 3 columns)
            $username = filter_input( INPUT_GET, 'username', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
            if ( $username ) {
                $search_term  = '%' . $wpdb->esc_like( $username ) . '%';
                $conditions[] = ' AND (u.display_name LIKE %s OR u.user_login LIKE %s OR u.user_email LIKE %s)';
                $params[]     = $search_term; $params[] = $search_term; $params[] = $search_term;
                $pending_conditions[] = ' AND (u.display_name LIKE %s OR u.user_login LIKE %s OR u.user_email LIKE %s)';
                $pending_params[]     = $search_term; $pending_params[] = $search_term; $pending_params[] = $search_term;
            }

            // Action text filter
            $action_filter = filter_input( INPUT_GET, 'action_filter', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
            if ( $action_filter ) {
                $conditions[] = ' AND l.action LIKE %s';
                $params[]     = '%' . $wpdb->esc_like( $action_filter ) . '%';
                $pending_conditions[] = ' AND p.note LIKE %s';
                $pending_params[]     = '%' . $wpdb->esc_like( $action_filter ) . '%';
            }

            // Points range
            $min_points = filter_input( INPUT_GET, 'min_points', FILTER_SANITIZE_NUMBER_INT );
            if ( '' !== $min_points && is_numeric( $min_points ) ) {
                $conditions[] = ' AND ABS(l.points) >= %d';
                $params[]     = (int) $min_points;
                $pending_conditions[] = ' AND ABS(p.points) >= %d';
                $pending_params[]     = (int) $min_points;
            }
            $max_points = filter_input( INPUT_GET, 'max_points', FILTER_SANITIZE_NUMBER_INT );
            if ( '' !== $max_points && is_numeric( $max_points ) ) {
                $conditions[] = ' AND ABS(l.points) <= %d';
                $params[]     = (int) $max_points;
                $pending_conditions[] = ' AND ABS(p.points) <= %d';
                $pending_params[]     = (int) $max_points;
            }

            // Type filter (strict whitelist)
            $type_filter = filter_input( INPUT_GET, 'type_filter', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
            if ( in_array( $type_filter, array( 'add', 'remove' ), true ) ) {
                $conditions[] = ' AND l.type = %s';
                $params[]     = $type_filter;
            } elseif ( 'pending' === $type_filter ) {
                $conditions[] = ' AND 1=0';
            } else {
                $type_filter = '';
            }
        }

        $where_dynamic         = implode( '', $conditions );
        $pending_where_dynamic = implode( '', $pending_conditions );
        $pending_table         = '';
        $include_pending_logs  = false;

        if ( in_array( $type_filter, array( '', 'pending' ), true ) && function_exists( 'spar_points_delay_table_name' ) && function_exists( 'spar_points_delay_ensure_table' ) ) {
            spar_points_delay_ensure_table();
            $pending_table        = spar_points_delay_table_name();
            $include_pending_logs = true;
        }

        // Build COUNT query with dynamic parameters
        $count_sql = ' SELECT COUNT(*) FROM %i l LEFT JOIN ' . $wpdb->users . ' u ON l.user_id = u.ID WHERE 1=1' . $where_dynamic;
        $count_args = array_merge( array( $count_sql, $table ), $params );
        $count_sql_prepared = call_user_func_array( array( $wpdb, 'prepare' ), $count_args );
        $cache_group = 'spar';
        $count_key   = 'spar_log_count_' . md5( $count_sql_prepared );
        $total_items = wp_cache_get( $count_key, $cache_group );
        if ( false === $total_items ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $total_items = (int) $wpdb->get_var( $count_sql_prepared );
            wp_cache_set( $count_key, $total_items, $cache_group, 60 );
        }

        if ( $include_pending_logs ) {
            $pending_count_sql      = ' SELECT COUNT(*) FROM %i p LEFT JOIN ' . $wpdb->users . ' u ON p.user_id = u.ID WHERE 1=1' . $pending_where_dynamic;
            $pending_count_args     = array_merge( array( $pending_count_sql, $pending_table ), $pending_params );
            $pending_count_prepared = call_user_func_array( array( $wpdb, 'prepare' ), $pending_count_args );
            $pending_count_key      = 'spar_log_pending_count_' . md5( $pending_count_prepared );
            $pending_total_items    = wp_cache_get( $pending_count_key, $cache_group );
            if ( false === $pending_total_items ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
                $pending_total_items = (int) $wpdb->get_var( $pending_count_prepared );
                wp_cache_set( $pending_count_key, $pending_total_items, $cache_group, 60 );
            }
            $total_items += (int) $pending_total_items;
        }

        // Build ORDER BY clause
        $order_clause = '';
        if ( $orderby === 'user' ) {
            $order_clause = "ORDER BY u.display_name $order";
        } else {
            $order_clause = "ORDER BY l.$orderby $order";
        }

        // Get data rows (build dynamic prepared statement)
        if ( $include_pending_logs ) {
            $union_orderby = ( 'user' === $orderby ) ? 'user_name' : $orderby;
            $order_clause  = "ORDER BY $union_orderby $order";
            $data_sql      = ' SELECT CAST(l.id AS CHAR) AS id, l.user_id, l.action, l.type, l.points, l.date, l.action_id, NULL AS available_at, u.display_name as user_name FROM %i l LEFT JOIN ' . $wpdb->users . ' u ON l.user_id = u.ID WHERE 1=1' . $where_dynamic . ' UNION ALL SELECT CONCAT(%s, p.id) AS id, p.user_id, p.note AS action, %s AS type, p.points, p.earned_at AS date, p.action_id, p.award_at AS available_at, u.display_name as user_name FROM %i p LEFT JOIN ' . $wpdb->users . ' u ON p.user_id = u.ID WHERE 1=1' . $pending_where_dynamic . ' ' . $order_clause . ' LIMIT %d OFFSET %d';
            $data_args     = array_merge( array( $data_sql, $table ), $params, array( 'pending-', 'pending', $pending_table ), $pending_params, array( $per_page, $offset ) );
        } else {
            $data_sql  = ' SELECT l.*, NULL AS available_at, u.display_name as user_name FROM %i l LEFT JOIN ' . $wpdb->users . ' u ON l.user_id = u.ID WHERE 1=1' . $where_dynamic . ' ' . $order_clause . ' LIMIT %d OFFSET %d';
            $data_args = array_merge( array( $data_sql, $table ), $params, array( $per_page, $offset ) );
        }
        $data_sql_prepared = call_user_func_array( array( $wpdb, 'prepare' ), $data_args );
        $results_key = 'spar_log_results_' . md5( $data_sql_prepared );
        $results     = wp_cache_get( $results_key, $cache_group );
        if ( false === $results ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $results = $wpdb->get_results( $data_sql_prepared, ARRAY_A );
            wp_cache_set( $results_key, $results, $cache_group, 60 );
        }

        $this->items = $results;

        $this->set_pagination_args( [
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil( $total_items / $per_page )
        ] );

        $this->_column_headers = [ $this->get_columns(), [], $this->get_sortable_columns() ];
    }

    public function column_default( $item, $column_name ) {
        switch ( $column_name ) {
            case 'user':
                if ( empty( $item['user_id'] ) || 0 === (int) $item['user_id'] ) {
                    // Guest entry — try to get the email from the linked order.
                    $guest_email = '';
                    $action_text = isset( $item['action'] ) ? $item['action'] : '';
                    if ( preg_match( '/#(\d+)/', $action_text, $m ) ) {
                        $log_order = wc_get_order( (int) $m[1] );
                        if ( $log_order ) {
                            $guest_email = $log_order->get_billing_email();
                        }
                    }
                    if ( $guest_email ) {
                        return esc_html__( 'Guest', 'simple-points-and-rewards' ) . ' (' . esc_html( $guest_email ) . ')';
                    }
                    return esc_html__( 'Guest', 'simple-points-and-rewards' );
                }
                return esc_html( $item['user_name'] ?: esc_html__( 'Unknown user', 'simple-points-and-rewards' ) );

            case 'action':
                $action = isset( $item['action'] ) ? $item['action'] : '';

                // Prepend "Prize Wheel:" prefix for spin wheel log entries
                if ( isset( $item['action_id'] ) && 'spin_wheel' === $item['action_id'] ) {
                    $action = esc_html__( 'Prize Wheel', 'simple-points-and-rewards' ) . ': ' . $action;
                }

                // If "Points Earned for Order" get the order ID after and link it
                // Handle both formats: "Points Earned for Order: #123" and "Points Earned for Order #123"
                if ( preg_match( '/Points Earned for Order[:\s]+#?(\d+)/', $action, $order_match ) ) {
                    $order_id_num = (int) $order_match[1];
                    $order = wc_get_order( $order_id_num );
                    if ( $order ) {
                        $order_edit_url = $order->get_edit_order_url();
                        $order_link = '<a href="' . esc_url( $order_edit_url ) . '">#' . esc_html( $order_id_num ) . '</a>';
                        $action = preg_replace( '/#?' . $order_id_num . '/', $order_link, $action, 1 );
                    }
                }

                // If "Claimed Voucher:" get the coupon after and link it
                if ( strpos( $action, 'Claimed Voucher:' ) !== false ) {
                    $parts = explode( 'Claimed Voucher:', $action );
                    if ( isset( $parts[1] ) ) {
                        $coupon_code = trim( $parts[1] );
                        $coupon_id = function_exists( 'wc_get_coupon_id_by_code' ) ? wc_get_coupon_id_by_code( $coupon_code ) : 0;
                        if ( $coupon_id ) {
                            $action = str_replace(
                                $coupon_code,
                                '<a href="' . esc_url( admin_url( 'post.php?post=' . absint( $coupon_id ) . '&action=edit' ) ) . '">' . esc_html( $coupon_code ) . '</a>',
                                $action
                            );
                        }
                    }
                }

                // If "Claimed Free Product Voucher:" get the product ID after and link it
                if ( strpos( $action, 'Claimed Free Product Voucher:' ) !== false ) {
                    $parts = explode( 'Claimed Free Product Voucher:', $action );
                    if ( isset( $parts[1] ) ) {
                        $product_id = trim( $parts[1] );
                        $product_id_trim = ltrim( $product_id, '#' );
                        $product = wc_get_product( $product_id_trim );
                        if ( $product ) {
                            $action = str_replace(
                                $product_id,
                                '<a href="' . esc_url( admin_url( 'post.php?post=' . $product->get_id() . '&action=edit' ) ) . '">' . esc_html( $product_id ) . '</a>',
                                $action
                            );
                        }
                    }
                }

                // If "Referral Bonus:" get the order ID and link it
                if ( strpos( $action, 'Referral Bonus:' ) !== false ) {
                    if ( preg_match( '/\\(Order #(\\d+)\\)/', $action, $matches ) ) {
                        $order_id = (int) $matches[1];
                        $order = wc_get_order( $order_id );
                        if ( $order ) {
                            $order_edit_url = $order->get_edit_order_url();
                            $action = str_replace(
                                '(Order #' . $order_id . ')',
                                '(<a href="' . esc_url( $order_edit_url ) . '">Order #' . $order_id . '</a>)',
                                $action
                            );

                            // Add referral stats context
                            $referrer_code = $order->get_meta( 'referrer_code' );
                            if ( $referrer_code ) {
                                $referrer_id = spar_get_user_by_referral_code( $referrer_code );
                                if ( $referrer_id ) {
                                    $referrer_stats = spar_get_user_referral_stats( $referrer_id );
                                    $clicks   = isset( $referrer_stats['total_clicks'] ) ? (int) $referrer_stats['total_clicks'] : 0;
                                    $refers   = isset( $referrer_stats['successful_referrals'] ) ? (int) $referrer_stats['successful_referrals'] : 0;
                                    $action  .= '<br><small>'
                                        . esc_html__( 'Referrer:', 'simple-points-and-rewards' ) . ' '
                                        . esc_html( $referrer_code ) . ' ('
                                        . esc_html__( 'Clicks:', 'simple-points-and-rewards' ) . ' ' . number_format_i18n( $clicks ) . ', '
                                        . esc_html__( 'Referrals:', 'simple-points-and-rewards' ) . ' ' . number_format_i18n( $refers )
                                        . ')</small>';
                                }
                            }
                        }
                    }
                }

                // If action contains referral coupon codes, link them
                if ( preg_match( '/ref-[A-Z0-9]+-\d+/', $action, $matches ) ) {
                    $coupon_code = $matches[0];
                    $coupon_id = function_exists( 'wc_get_coupon_id_by_code' ) ? wc_get_coupon_id_by_code( $coupon_code ) : 0;
                    if ( $coupon_id ) {
                        $action = str_replace(
                            $coupon_code,
                            '<a href="' . esc_url( admin_url( 'post.php?post=' . absint( $coupon_id ) . '&action=edit' ) ) . '">' . esc_html( $coupon_code ) . '</a>',
                            $action
                        );
                    }
                }

                return wp_kses_post( $action );

            case 'type':
                if ( isset( $item['type'] ) && 'pending' === $item['type'] ) {
                    $type = esc_html__( 'Pending', 'simple-points-and-rewards' );
                    if ( ! empty( $item['available_at'] ) ) {
                        $available_ts = strtotime( $item['available_at'] );
                        if ( $available_ts ) {
                            $available_date = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $available_ts );
                            $type          .= '<br><small>' . esc_html( sprintf( __( 'Available: %s', 'simple-points-and-rewards' ), $available_date ) ) . '</small>';
                        }
                    }

                    return $type;
                }

                return ucfirst( esc_html( $item['type'] ) );

            case 'points':
                $points = (int) $item['points'];
                if ( 'pending' === $item['type'] ) {
                    $points = '+' . $points;
                    $class = 'spar-pending';
                } elseif ( 'add' === $item['type'] ) {
                    $points = '+' . $points;
                    $class = 'spar-positive';
                } else {
                    $points = '-' . absint( $points );
                    $class = 'spar-negative';
                }
                return '<span class="' . esc_attr( $class ) . '">' . esc_html( $points ) . '</span>';

            case 'date':
                // Use site formats and WordPress time i18n
                $ts = strtotime( $item['date'] );
                return $ts ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ) : '';

            case 'row_actions':
                $nonce = wp_create_nonce( 'spar_log_row_action' );

                if ( isset( $item['type'] ) && 'pending' === $item['type'] ) {
                    $pending_id = (int) str_replace( 'pending-', '', $item['id'] );
                    if ( $pending_id <= 0 ) {
                        return '';
                    }
                    return '<form method="get" class="spar-row-action-form">'
                        . '<input type="hidden" name="page" value="spar-log" />'
                        . '<input type="hidden" name="pending_id" value="' . esc_attr( $pending_id ) . '" />'
                        . '<input type="hidden" name="spar_row_nonce" value="' . esc_attr( $nonce ) . '" />'
                        . '<select name="spar_row_action">'
                        . '<option value="">&#8212; ' . esc_html__( 'Select', 'simple-points-and-rewards' ) . ' &#8212;</option>'
                        . '<option value="pending_grant" data-confirm="' . esc_attr( __( 'Grant these points to the user immediately?', 'simple-points-and-rewards' ) ) . '">' . esc_html__( 'Grant Now', 'simple-points-and-rewards' ) . '</option>'
                        . '<option value="pending_delete" data-confirm="' . esc_attr( __( 'Delete this pending entry?', 'simple-points-and-rewards' ) ) . '">' . esc_html__( 'Delete', 'simple-points-and-rewards' ) . '</option>'
                        . '</select>'
                        . ' <button type="submit" class="button button-small">' . esc_html__( 'Apply', 'simple-points-and-rewards' ) . '</button>'
                        . '</form>';
                }

                $log_id      = absint( $item['id'] );
                $is_undone   = isset( $item['action_id'] ) && 'undone' === $item['action_id'];
                $is_undo_log = ( isset( $item['action_id'] ) && 'admin_undo' === $item['action_id'] )
                               || ( strpos( (string) $item['action'], 'Undo:' ) === 0 );
                $can_undo    = ! $is_undone && ! $is_undo_log && (int) ( $item['user_id'] ?? 0 ) > 0;

                $options  = '<option value="">&#8212; ' . esc_html__( 'Select', 'simple-points-and-rewards' ) . ' &#8212;</option>';
                if ( $can_undo ) {
                    $options .= '<option value="log_undo_update">' . esc_html__( 'Undo + Update Log', 'simple-points-and-rewards' ) . '</option>';
                    $options .= '<option value="log_undo_delete">' . esc_html__( 'Undo + Delete', 'simple-points-and-rewards' ) . '</option>';
                }
                $options .= '<option value="log_delete" data-confirm="' . esc_attr( __( 'Delete this log entry?', 'simple-points-and-rewards' ) ) . '">' . esc_html__( 'Delete', 'simple-points-and-rewards' ) . '</option>';

                return '<form method="get" class="spar-row-action-form">'
                    . '<input type="hidden" name="page" value="spar-log" />'
                    . '<input type="hidden" name="log_id" value="' . esc_attr( $log_id ) . '" />'
                    . '<input type="hidden" name="spar_row_nonce" value="' . esc_attr( $nonce ) . '" />'
                    . '<select name="spar_row_action">' . $options . '</select>'
                    . ' <button type="submit" class="button button-small">' . esc_html__( 'Apply', 'simple-points-and-rewards' ) . '</button>'
                    . '</form>';

            default:
                return '';
        }
    }

    public function no_items() {
        esc_html_e( 'No points log entries found.', 'simple-points-and-rewards' );
    }
}

function spar_points_log_page() {
    // Handle single-row actions (GET-based, nonce-protected).
    $spar_row_action = filter_input( INPUT_GET, 'spar_row_action', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
    $spar_row_nonce  = filter_input( INPUT_GET, 'spar_row_nonce', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
    if ( $spar_row_action && $spar_row_nonce && wp_verify_nonce( $spar_row_nonce, 'spar_log_row_action' ) ) {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'simple-points-and-rewards' ) );
        }

        global $wpdb;
        $logs_table = $wpdb->prefix . 'spar_points_logs';

        if ( 'log_delete' === $spar_row_action ) {
            $log_id = absint( filter_input( INPUT_GET, 'log_id', FILTER_SANITIZE_NUMBER_INT ) );
            if ( $log_id > 0 ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                $wpdb->delete( $logs_table, [ 'id' => $log_id ], [ '%d' ] );
            }
        } elseif ( in_array( $spar_row_action, [ 'log_undo', 'log_undo_update' ], true ) ) {
            $log_id = absint( filter_input( INPUT_GET, 'log_id', FILTER_SANITIZE_NUMBER_INT ) );
            if ( $log_id > 0 ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                $log = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $logs_table, $log_id ), ARRAY_A );
                if ( $log ) {
                    $user_id     = (int) $log['user_id'];
                    $points      = absint( $log['points'] );
                    $type        = $log['type'];
                    $action      = $log['action'];
                    $is_undone   = isset( $log['action_id'] ) && 'undone' === $log['action_id'];
                    $is_undo_log = ( isset( $log['action_id'] ) && 'admin_undo' === $log['action_id'] )
                                   || ( strpos( $action, 'Undo:' ) === 0 );

                    if ( ! $is_undone && ! $is_undo_log && $user_id > 0 ) {
                        $reverse_action = ( 'add' === $type ) ? 'remove' : 'add';
                        $note           = sprintf( __( 'Undo: %s', 'simple-points-and-rewards' ), $action );
                        spar_update_user_points( $user_id, $points, $reverse_action, $note, 'admin_undo' );
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                        $wpdb->update( $logs_table, [ 'action_id' => 'undone' ], [ 'id' => $log_id ], [ '%s' ], [ '%d' ] );
                    }
                }
            }
        } elseif ( 'log_undo_delete' === $spar_row_action ) {
            $log_id = absint( filter_input( INPUT_GET, 'log_id', FILTER_SANITIZE_NUMBER_INT ) );
            if ( $log_id > 0 ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                $log = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $logs_table, $log_id ), ARRAY_A );
                if ( $log ) {
                    $user_id     = (int) $log['user_id'];
                    $points      = absint( $log['points'] );
                    $type        = $log['type'];
                    $is_undone   = isset( $log['action_id'] ) && 'undone' === $log['action_id'];
                    $is_undo_log = ( isset( $log['action_id'] ) && 'admin_undo' === $log['action_id'] )
                                   || ( strpos( (string) $log['action'], 'Undo:' ) === 0 );

                    if ( ! $is_undone && ! $is_undo_log && $user_id > 0 ) {
                        // Revert points atomically without logging
                        if ( 'add' === $type ) {
                            // Only decrement lifetime "Total Earned" when the
                            // original add contributed to it — absolute admin
                            // balance overrides ('admin_set_balance') are flagged
                            // skip_lifetime and never did.
                            $skipped_lifetime = ( isset( $log['action_id'] ) && 'admin_set_balance' === $log['action_id'] );
                            spar_adjust_user_points_balance( $user_id, -$points );
                            if ( ! $skipped_lifetime ) {
                                spar_adjust_user_lifetime_earned( $user_id, -$points );
                            }
                        } else {
                            spar_adjust_user_points_balance( $user_id, $points );
                        }
                    }
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                    $wpdb->delete( $logs_table, [ 'id' => $log_id ], [ '%d' ] );
                }
            }
        } elseif ( 'pending_grant' === $spar_row_action && function_exists( 'spar_points_delay_table_name' ) && function_exists( 'spar_points_delay_apply_pending_row' ) ) {
            $pending_id = absint( filter_input( INPUT_GET, 'pending_id', FILTER_SANITIZE_NUMBER_INT ) );
            if ( $pending_id > 0 ) {
                if ( function_exists( 'spar_points_delay_ensure_table' ) ) {
                    spar_points_delay_ensure_table();
                }
                $pending_table = spar_points_delay_table_name();
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d AND status = %s', $pending_table, $pending_id, 'pending' ), ARRAY_A );
                if ( $row ) {
                    spar_points_delay_apply_pending_row( $row, $pending_table );
                }
            }
        } elseif ( 'pending_delete' === $spar_row_action && function_exists( 'spar_points_delay_table_name' ) ) {
            $pending_id = absint( filter_input( INPUT_GET, 'pending_id', FILTER_SANITIZE_NUMBER_INT ) );
            if ( $pending_id > 0 ) {
                if ( function_exists( 'spar_points_delay_ensure_table' ) ) {
                    spar_points_delay_ensure_table();
                }
                $pending_table = spar_points_delay_table_name();
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                $wpdb->delete( $pending_table, [ 'id' => $pending_id ], [ '%d' ] );
            }
        }

        wp_safe_redirect( admin_url( 'admin.php?page=spar-log' ) );
        exit;
    }

    $log_table = new SPAR_Points_Log_Table();
    $log_table->process_bulk_action();
    $log_table->prepare_items();

    // Collect sanitized inputs using filter_input to avoid direct superglobals and gate by nonce
    $spar_nonce           = filter_input( INPUT_GET, 'spar_log_nonce', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
    $spar_nonce_valid     = $spar_nonce ? wp_verify_nonce( $spar_nonce, 'spar_log_filters' ) : false;
    $spar_page_param      = filter_input( INPUT_GET, 'page', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
    $spar_date_from       = $spar_nonce_valid ? filter_input( INPUT_GET, 'date_from', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) : '';
    $spar_date_to         = $spar_nonce_valid ? filter_input( INPUT_GET, 'date_to', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) : '';
    $spar_username        = $spar_nonce_valid ? filter_input( INPUT_GET, 'username', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) : '';
    $spar_action_filter   = $spar_nonce_valid ? filter_input( INPUT_GET, 'action_filter', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) : '';
    $spar_min_points      = $spar_nonce_valid ? filter_input( INPUT_GET, 'min_points', FILTER_SANITIZE_NUMBER_INT ) : '';
    $spar_max_points      = $spar_nonce_valid ? filter_input( INPUT_GET, 'max_points', FILTER_SANITIZE_NUMBER_INT ) : '';
    $spar_type_filter_val = $spar_nonce_valid ? filter_input( INPUT_GET, 'type_filter', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) : '';
    ?>
    <div class="wrap">
    <?php spar_render_admin_header( esc_html__( 'Activity Log', 'simple-points-and-rewards' ) ); ?>

        <?php if ( function_exists( 'spar_export_points_log_url' ) ) :
            $spar_export_url = spar_export_points_log_url( array(
                'date_from'     => (string) $spar_date_from,
                'date_to'       => (string) $spar_date_to,
                'username'      => (string) $spar_username,
                'action_filter' => (string) $spar_action_filter,
                'min_points'    => (string) $spar_min_points,
                'max_points'    => (string) $spar_max_points,
                'type_filter'   => (string) $spar_type_filter_val,
            ) );
        ?>
        <a href="<?php echo esc_url( $spar_export_url ); ?>" class="button" style="float: right; margin: 12px 0 0 12px;" title="<?php esc_attr_e( 'Download the log entries matching the current filters as a CSV file.', 'simple-points-and-rewards' ); ?>">
            <?php esc_html_e( 'Export CSV', 'simple-points-and-rewards' ); ?>
        </a>
        <?php endif; ?>

        <p><?php esc_html_e( 'This page shows the history of points earned and redeemed by users.', 'simple-points-and-rewards' ); ?></p>
        <?php if ( function_exists( 'spar_customer_points_render_notice' ) ) { spar_customer_points_render_notice(); } ?>
        <?php require dirname( __DIR__ ) . '/templates/add-remove-points-panel.php'; ?>

        <!-- Filter Form -->
        <div class="spar-log-filters" style="background: #fff; border: 1px solid #ccd0d4; padding: 15px; margin: 20px 0; border-radius: 4px;">
            <h3 style="margin-top: 0;"><?php esc_html_e( 'Filter Logs', 'simple-points-and-rewards' ); ?></h3>
            <form method="get" id="spar-log-filter-form">
                <input type="hidden" name="page" value="<?php echo esc_attr( $spar_page_param ); ?>" />
                <?php wp_nonce_field( 'spar_log_filters', 'spar_log_nonce' ); ?>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 15px;">
                    <!-- Date Range -->
                    <div>
                        <label for="date_from"><?php esc_html_e( 'Date From:', 'simple-points-and-rewards' ); ?></label>
                        <input type="date" id="date_from" name="date_from" value="<?php echo esc_attr( $spar_date_from ); ?>" style="width: 100%;" />
                    </div>

                    <div>
                        <label for="date_to"><?php esc_html_e( 'Date To:', 'simple-points-and-rewards' ); ?></label>
                        <input type="date" id="date_to" name="date_to" value="<?php echo esc_attr( $spar_date_to ); ?>" style="width: 100%;" />
                    </div>

                    <!-- Username -->
                    <div>
                        <label for="username"><?php esc_html_e( 'Username/Email:', 'simple-points-and-rewards' ); ?></label>
                        <input type="text" id="username" name="username" value="<?php echo esc_attr( $spar_username ); ?>" placeholder="<?php esc_attr_e( 'Search by name or email', 'simple-points-and-rewards' ); ?>" style="width: 100%;" />
                    </div>

                    <!-- Action -->
                    <div>
                        <label for="action_filter"><?php esc_html_e( 'Action Contains:', 'simple-points-and-rewards' ); ?></label>
                        <input type="text" id="action_filter" name="action_filter" value="<?php echo esc_attr( $spar_action_filter ); ?>" placeholder="<?php esc_attr_e( 'e.g. Order, Voucher, Signup', 'simple-points-and-rewards' ); ?>" style="width: 100%;" />
                    </div>

                    <!-- Points Range -->
                    <div>
                        <label for="min_points"><?php esc_html_e( 'Min Points:', 'simple-points-and-rewards' ); ?></label>
                        <input type="number" id="min_points" name="min_points" value="<?php echo esc_attr( $spar_min_points ); ?>" placeholder="0" min="0" style="width: 100%;" />
                    </div>

                    <div>
                        <label for="max_points"><?php esc_html_e( 'Max Points:', 'simple-points-and-rewards' ); ?></label>
                        <input type="number" id="max_points" name="max_points" value="<?php echo esc_attr( $spar_max_points ); ?>" placeholder="1000" min="0" style="width: 100%;" />
                    </div>

                    <!-- Type Filter -->
                    <div>
                        <label for="type_filter"><?php esc_html_e( 'Type:', 'simple-points-and-rewards' ); ?></label>
                        <select id="type_filter" name="type_filter" style="width: 100%;">
                            <option value=""><?php esc_html_e( 'All Types', 'simple-points-and-rewards' ); ?></option>
                            <option value="add" <?php selected( $spar_type_filter_val, 'add' ); ?>><?php esc_html_e( 'Points Added', 'simple-points-and-rewards' ); ?></option>
                            <option value="remove" <?php selected( $spar_type_filter_val, 'remove' ); ?>><?php esc_html_e( 'Points Removed', 'simple-points-and-rewards' ); ?></option>
                            <option value="pending" <?php selected( $spar_type_filter_val, 'pending' ); ?>><?php esc_html_e( 'Pending', 'simple-points-and-rewards' ); ?></option>
                        </select>
                    </div>
                </div>

                <div style="display: flex; gap: 10px; align-items: center;">
                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Apply Filters', 'simple-points-and-rewards' ); ?></button>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=spar-log' ) ); ?>" class="button"><?php esc_html_e( 'Clear Filters', 'simple-points-and-rewards' ); ?></a>

                    <?php 
                    // Show active filter count
                    $active_filters  = 0;
                    $active_filters += ( '' !== $spar_date_from ) ? 1 : 0;
                    $active_filters += ( '' !== $spar_date_to ) ? 1 : 0;
                    $active_filters += ( '' !== $spar_username ) ? 1 : 0;
                    $active_filters += ( '' !== $spar_action_filter ) ? 1 : 0;
                    $active_filters += ( '' !== (string) $spar_min_points ) ? 1 : 0;
                    $active_filters += ( '' !== (string) $spar_max_points ) ? 1 : 0;
                    $active_filters += ( '' !== $spar_type_filter_val ) ? 1 : 0;
                    if ($active_filters > 0) {
                        /* translators: %d: number of active filters */
                        $filters_text = sprintf( esc_html__( '%d filter(s) active', 'simple-points-and-rewards' ), number_format_i18n( (int) $active_filters ) );
                        printf( '<span style="color: #135e96; font-weight: 600;">%s</span>', esc_html( $filters_text ) );
                    }
                    ?>
                </div>
            </form>
        </div>

        <form method="post">
            <?php 
            $spar_page_param2 = filter_input( INPUT_GET, 'page', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
            $spar_nonce_val   = $spar_nonce ? $spar_nonce : wp_create_nonce( 'spar_log_filters' );
            ?>
            <input type="hidden" name="page" value="<?php echo esc_attr( $spar_page_param2 ); ?>" />
            <input type="hidden" name="spar_log_nonce" value="<?php echo esc_attr( $spar_nonce_val ); ?>" />

            <?php 
            // Pass through filter parameters to maintain them during table operations
            if ( '' !== $spar_date_from ) {
                echo '<input type="hidden" name="date_from" value="' . esc_attr( $spar_date_from ) . '" />';
            }
            if ( '' !== $spar_date_to ) {
                echo '<input type="hidden" name="date_to" value="' . esc_attr( $spar_date_to ) . '" />';
            }
            if ( '' !== $spar_username ) {
                echo '<input type="hidden" name="username" value="' . esc_attr( $spar_username ) . '" />';
            }
            if ( '' !== $spar_action_filter ) {
                echo '<input type="hidden" name="action_filter" value="' . esc_attr( $spar_action_filter ) . '" />';
            }
            if ( '' !== (string) $spar_min_points ) {
                echo '<input type="hidden" name="min_points" value="' . esc_attr( $spar_min_points ) . '" />';
            }
            if ( '' !== (string) $spar_max_points ) {
                echo '<input type="hidden" name="max_points" value="' . esc_attr( $spar_max_points ) . '" />';
            }
            if ( '' !== $spar_type_filter_val ) {
                echo '<input type="hidden" name="type_filter" value="' . esc_attr( $spar_type_filter_val ) . '" />';
            }
            ?>

            <?php $log_table->display(); ?>
        </form>
    </div>
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        // Handle row action "Apply" button clicks.
        // Row action forms are nested inside the bulk POST form (invalid HTML), so browsers
        // ignore the inner <form> tags and adopt all inputs into the outer form. Clicking
        // the button would submit the outer form rather than navigating via GET. We intercept
        // the click and build the correct GET URL manually.
        $(document).on('click', '.spar-row-action-form button[type="submit"]', function(e) {
            e.preventDefault();
            e.stopPropagation();

            var $form    = $(this).closest('.spar-row-action-form');
            var action   = $form.find('select[name="spar_row_action"]').val();

            if ( ! action ) {
                return;
            }

            var confirmMsg = $form.find('select[name="spar_row_action"] option:selected').data('confirm');
            if ( confirmMsg && ! window.confirm( confirmMsg ) ) {
                return;
            }

            var query = {
                page:            $form.find('input[name="page"]').val() || 'spar-log',
                spar_row_action: action,
                spar_row_nonce:  $form.find('input[name="spar_row_nonce"]').val()
            };

            var logId = $form.find('input[name="log_id"]').val();
            if ( logId )     { query.log_id = logId; }

            var pendingId = $form.find('input[name="pending_id"]').val();
            if ( pendingId ) { query.pending_id = pendingId; }

            var queryString = Object.keys( query ).map( function( k ) {
                return encodeURIComponent( k ) + '=' + encodeURIComponent( query[ k ] );
            } ).join( '&' );

            window.location.href = 'admin.php?' + queryString;
        });

        function toggleUndoneCheckboxes() {
            var actionTop    = $('#bulk-action-selector-top').val();
            var actionBottom = $('#bulk-action-selector-bottom').val();
            var isDelete   = ( actionTop === 'delete'      || actionBottom === 'delete' );
            var isUndoOnly = ( actionTop === 'undo_update' || actionBottom === 'undo_update' ||
                               actionTop === 'undo_delete' || actionBottom === 'undo_delete' );

            if ( isDelete ) {
                $('.spar-undone-cb').show();
            } else {
                $('.spar-undone-cb').hide().prop('checked', false);
            }

            if ( isDelete ) {
                $('.spar-pending-cb').show();
            } else {
                $('.spar-pending-cb').hide().prop('checked', false);
            }
        }

        $('#bulk-action-selector-top, #bulk-action-selector-bottom').on('change', toggleUndoneCheckboxes);
        
        // Run on load
        toggleUndoneCheckboxes();
    });
    </script>
    <?php
}
