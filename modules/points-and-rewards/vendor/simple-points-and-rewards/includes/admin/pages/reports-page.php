<?php
/**
 * Admin Reports page (no Recent Activity section)
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Get reports statistics within an optional date range.
 *
 * Args:
 * - date_from (Y-m-d) optional
 * - date_to   (Y-m-d) optional
 * - limit     (int) for top lists
 */
function spar_get_reports_stats( $args = array() ) {
    global $wpdb;

    $defaults = array(
        'date_from' => '',
        'date_to'   => '',
        'limit'     => 10,
    );
    $args = wp_parse_args( $args, $defaults );

    $table      = $wpdb->prefix . 'spar_points_logs';
    $conditions = array();
    $params     = array();
    $date_from  = isset( $args['date_from'] ) ? sanitize_text_field( $args['date_from'] ) : '';
    $date_to    = isset( $args['date_to'] ) ? sanitize_text_field( $args['date_to'] ) : '';
    if ( $date_from && strtotime( $date_from ) ) {
        $conditions[] = ' AND date >= %s';
        $params[]     = $date_from . ' 00:00:00';
    }
    if ( $date_to && strtotime( $date_to ) ) {
        $conditions[] = ' AND date <= %s';
        $params[]     = $date_to . ' 23:59:59';
    }
    $where_dynamic = implode( '', $conditions );

    $cache_group = 'spar';

    // Totals: consider both sign and legacy type to ensure compatibility with all data
    $totals_sql_base = "SELECT 
        SUM(CASE WHEN (points > 0 OR type = 'add') THEN points ELSE 0 END) AS earned,
        SUM(CASE WHEN (points < 0 OR type = 'remove') THEN ABS(points) ELSE 0 END) AS redeemed,
        COUNT(*) AS activity,
        COUNT(DISTINCT user_id) AS customers
        FROM %i WHERE 1=1" . $where_dynamic;
    $totals_args      = array_merge( array( $totals_sql_base, $table ), $params );
    $totals_sql       = call_user_func_array( array( $wpdb, 'prepare' ), $totals_args );

    $totals_key = 'spar_reports_totals_' . md5( $totals_sql );
    $totals = wp_cache_get( $totals_key, $cache_group );
    if ( false === $totals ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $totals = $wpdb->get_row( $totals_sql, ARRAY_A );
        if ( ! is_array( $totals ) ) {
            $totals = array( 'earned' => 0, 'redeemed' => 0, 'activity' => 0, 'customers' => 0 );
        }
        wp_cache_set( $totals_key, $totals, $cache_group, 60 );
    }

    $earned    = (int) ( $totals['earned'] ?? 0 );
    $redeemed  = (int) ( $totals['redeemed'] ?? 0 );
    $activity  = (int) ( $totals['activity'] ?? 0 );
    $customers = (int) ( $totals['customers'] ?? 0 );

    // Top earners: points positive or legacy type add
    $limit = max( 1, absint( $args['limit'] ) );
    $top_earners_sql_base = 'SELECT user_id, SUM(CASE WHEN (points > 0 OR type = %s) THEN points ELSE 0 END) AS total_points FROM %i WHERE 1=1' . $where_dynamic . ' GROUP BY user_id HAVING total_points > 0 ORDER BY total_points DESC LIMIT %d';
    $top_earners_args      = array_merge( array( $top_earners_sql_base, 'add', $table ), $params, array( $limit ) );
    $top_earners_sql       = call_user_func_array( array( $wpdb, 'prepare' ), $top_earners_args );
    $top_earners_key = 'spar_reports_top_earners_' . md5( $top_earners_sql );
    $top_earners = wp_cache_get( $top_earners_key, $cache_group );
    if ( false === $top_earners ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $top_earners = $wpdb->get_results( $top_earners_sql, ARRAY_A );
        wp_cache_set( $top_earners_key, $top_earners, $cache_group, 60 );
    }

    // Top redeemers: points negative or legacy type remove (ABS for magnitude)
    $top_redeemers_sql_base = 'SELECT user_id, SUM(CASE WHEN (points < 0 OR type = %s) THEN ABS(points) ELSE 0 END) AS total_points FROM %i WHERE 1=1' . $where_dynamic . ' GROUP BY user_id HAVING total_points > 0 ORDER BY total_points DESC LIMIT %d';
    $top_redeemers_args      = array_merge( array( $top_redeemers_sql_base, 'remove', $table ), $params, array( $limit ) );
    $top_redeemers_sql       = call_user_func_array( array( $wpdb, 'prepare' ), $top_redeemers_args );
    $top_redeemers_key = 'spar_reports_top_redeemers_' . md5( $top_redeemers_sql );
    $top_redeemers = wp_cache_get( $top_redeemers_key, $cache_group );
    if ( false === $top_redeemers ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $top_redeemers = $wpdb->get_results( $top_redeemers_sql, ARRAY_A );
        wp_cache_set( $top_redeemers_key, $top_redeemers, $cache_group, 60 );
    }

    return array(
        'earned'          => $earned,
        'redeemed'        => $redeemed,
        'net'             => max( 0, $earned - $redeemed ),
        'activity'        => $activity,
        'customers'       => $customers,
        // Share of earned points that have been redeemed within the range (percentage), null when nothing was earned.
        'redemption_rate' => $earned > 0 ? round( ( $redeemed / $earned ) * 100, 1 ) : null,
        'top_earners'     => $top_earners,
        'top_redeemers'   => $top_redeemers,
    );
}

/**
 * Get referral statistics for the admin reports.
 * - Clicks are aggregated from usermeta (all-time)
 * - Conversions/points/top referrers are derived from logs and respect date range
 */
function spar_get_referral_reports( $args = array() ) {
    global $wpdb;

    $defaults = array(
        'date_from' => '',
        'date_to'   => '',
        'limit'     => 10,
    );
    $args = wp_parse_args( $args, $defaults );

    $table      = $wpdb->prefix . 'spar_points_logs';
    $conds      = array();
    $params     = array();
    $date_from  = isset( $args['date_from'] ) ? sanitize_text_field( $args['date_from'] ) : '';
    $date_to    = isset( $args['date_to'] ) ? sanitize_text_field( $args['date_to'] ) : '';
    if ( $date_from && strtotime( $date_from ) ) {
        $conds[]  = ' AND date >= %s';
        $params[] = $date_from . ' 00:00:00';
    }
    if ( $date_to && strtotime( $date_to ) ) {
        $conds[]  = ' AND date <= %s';
        $params[] = $date_to . ' 23:59:59';
    }
    $where_dynamic = implode( '', $conds );

    $cache_group = 'spar';

    // Identify referral award rows by action prefix using the current configured name.
    $options       = get_option( 'spar_options', array() );
    $action_name   = isset( $options['earn']['referral']['name'] ) && $options['earn']['referral']['name'] !== ''
    ? sanitize_text_field( $options['earn']['referral']['name'] )
    : esc_html__( 'Referral Bonus', 'simple-points-and-rewards' );
    // Ensure a colon suffix is included in the LIKE pattern, as logs are written like "{name}: ..."
    $ref_action_like = $action_name . ':%';
    $default_action_like = esc_html__( 'Referral Bonus', 'simple-points-and-rewards' ) . ':%';
    $use_dual_like = ( $ref_action_like !== $default_action_like );

    // Conversions in range (count referral award log entries)
    if ( $use_dual_like ) {
        $conv_sql_base = 'SELECT COUNT(*) FROM %i WHERE 1=1' . $where_dynamic . ' AND (action LIKE %s OR action LIKE %s) AND (points > 0 OR type = %s)';
        $conv_args     = array_merge( array( $conv_sql_base, $table, $ref_action_like, $default_action_like, 'add' ), $params );
        $conversions_sql = call_user_func_array( array( $wpdb, 'prepare' ), $conv_args );
    } else {
        $conv_sql_base = 'SELECT COUNT(*) FROM %i WHERE 1=1' . $where_dynamic . ' AND action LIKE %s AND (points > 0 OR type = %s)';
        $conv_args     = array_merge( array( $conv_sql_base, $table, $ref_action_like, 'add' ), $params );
        $conversions_sql = call_user_func_array( array( $wpdb, 'prepare' ), $conv_args );
    }
    $conv_key = 'spar_reports_ref_conversions_' . md5( $conversions_sql );
    $conversions = wp_cache_get( $conv_key, $cache_group );
    if ( false === $conversions ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $conversions = (int) $wpdb->get_var( $conversions_sql );
        wp_cache_set( $conv_key, $conversions, $cache_group, 60 );
    }

    // Points from referrals in range
    if ( $use_dual_like ) {
        $points_sql_base = 'SELECT SUM(points) FROM %i WHERE 1=1' . $where_dynamic . ' AND (action LIKE %s OR action LIKE %s) AND (points > 0 OR type = %s)';
        $points_args     = array_merge( array( $points_sql_base, $table, $ref_action_like, $default_action_like, 'add' ), $params );
        $points_sql      = call_user_func_array( array( $wpdb, 'prepare' ), $points_args );
    } else {
        $points_sql_base = 'SELECT SUM(points) FROM %i WHERE 1=1' . $where_dynamic . ' AND action LIKE %s AND (points > 0 OR type = %s)';
        $points_args     = array_merge( array( $points_sql_base, $table, $ref_action_like, 'add' ), $params );
        $points_sql      = call_user_func_array( array( $wpdb, 'prepare' ), $points_args );
    }
    $points_key = 'spar_reports_ref_points_' . md5( $points_sql );
    $points_total = wp_cache_get( $points_key, $cache_group );
    if ( false === $points_total ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $points_total = (int) $wpdb->get_var( $points_sql );
        wp_cache_set( $points_key, $points_total, $cache_group, 60 );
    }

    // Top referrers in range (by points and count)
    $limit = max( 1, absint( $args['limit'] ) );
    if ( $use_dual_like ) {
        $top_sql_base = 'SELECT user_id, COUNT(*) AS referrals, SUM(points) AS total_points FROM %i WHERE 1=1' . $where_dynamic . ' AND (action LIKE %s OR action LIKE %s) AND (points > 0 OR type = %s) GROUP BY user_id HAVING total_points > 0 ORDER BY total_points DESC, referrals DESC LIMIT %d';
        $top_args      = array_merge( array( $top_sql_base, $table, $ref_action_like, $default_action_like, 'add', $limit ), $params );
        $top_sql       = call_user_func_array( array( $wpdb, 'prepare' ), $top_args );
    } else {
        $top_sql_base = 'SELECT user_id, COUNT(*) AS referrals, SUM(points) AS total_points FROM %i WHERE 1=1' . $where_dynamic . ' AND action LIKE %s AND (points > 0 OR type = %s) GROUP BY user_id HAVING total_points > 0 ORDER BY total_points DESC, referrals DESC LIMIT %d';
        $top_args      = array_merge( array( $top_sql_base, $table, $ref_action_like, 'add', $limit ), $params );
        $top_sql       = call_user_func_array( array( $wpdb, 'prepare' ), $top_args );
    }
    $top_key = 'spar_reports_ref_top_' . md5( $top_sql );
    $top_referrers = wp_cache_get( $top_key, $cache_group );
    if ( false === $top_referrers ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $top_referrers = $wpdb->get_results( $top_sql, ARRAY_A );
        wp_cache_set( $top_key, $top_referrers, $cache_group, 60 );
    }

    // Clicks all-time from usermeta (serialized array per user)
    $clicks_all_time = 0;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $meta_rows = $wpdb->get_col( $wpdb->prepare( 'SELECT meta_value FROM %i WHERE meta_key = %s', $wpdb->usermeta, 'spar_referral_stats' ) );
    if ( ! empty( $meta_rows ) ) {
        foreach ( $meta_rows as $val ) {
            $stats = maybe_unserialize( $val );
            if ( is_array( $stats ) && isset( $stats['total_clicks'] ) ) {
                $clicks_all_time += (int) $stats['total_clicks'];
            }
        }
    }

    return array(
        'clicks_all_time'   => (int) $clicks_all_time,
        'conversions_range' => (int) $conversions,
        'points_range'      => (int) max( 0, $points_total ),
        'top_referrers'     => $top_referrers,
    );
}

/**
 * Get total earned points grouped by earning method (action_id) within an optional date range.
 * Falls back to best-effort grouping from legacy action text when action_id is empty.
 *
 * Returns array of rows: [ [ 'method' => 'order', 'label' => 'Orders', 'points' => 1234, 'count' => 56, 'icon' => 'fa-cart-shopping' ], ... ]
 */
function spar_get_points_per_method( $args = array() ) {
    global $wpdb;

    $defaults = array(
        'date_from' => '',
        'date_to'   => '',
        'limit'     => 20, // show many; UI can decide how many to render
    );
    $args = wp_parse_args( $args, $defaults );

    $table      = $wpdb->prefix . 'spar_points_logs';
    $conds      = array();
    $params     = array();
    $date_from  = isset( $args['date_from'] ) ? sanitize_text_field( $args['date_from'] ) : '';
    $date_to    = isset( $args['date_to'] ) ? sanitize_text_field( $args['date_to'] ) : '';
    if ( $date_from && strtotime( $date_from ) ) { $conds[] = ' AND date >= %s'; $params[] = $date_from . ' 00:00:00'; }
    if ( $date_to && strtotime( $date_to ) ) { $conds[] = ' AND date <= %s'; $params[] = $date_to . ' 23:59:59'; }
    $conds[] = " AND (points > 0 OR type = 'add')"; // static fragment
    $where_dynamic = implode( '', $conds );
    $sql_base = "SELECT 
            CASE WHEN action_id IS NULL OR action_id = '' THEN '__legacy__' ELSE action_id END AS method,
            COUNT(*) AS count_events,
            SUM(points) AS total_points
        FROM %i WHERE 1=1" . $where_dynamic . '
        GROUP BY method
        ORDER BY total_points DESC';
    $sql_args = array_merge( array( $sql_base, $table ), $params );
    $sql      = call_user_func_array( array( $wpdb, 'prepare' ), $sql_args );

    $cache_group = 'spar';
    $cache_key   = 'spar_reports_points_per_method_' . md5( $sql );
    $rows = wp_cache_get( $cache_key, $cache_group );
    if ( false === $rows ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows = $wpdb->get_results( $sql, ARRAY_A );
        wp_cache_set( $cache_key, $rows, $cache_group, 60 );
    }

    // Map known methods to labels/icons. Allow filters for customization.
    $points_label = apply_filters( 'spar_points_label', esc_html__( 'Points', 'simple-points-and-rewards' ) );
    $map = array(
    'signup'            => array( 'label' => esc_html__( 'Signups', 'simple-points-and-rewards' ),                'icon' => 'fa-user-plus' ),
    'order'             => array( 'label' => esc_html__( 'Orders', 'simple-points-and-rewards' ),                 'icon' => 'fa-cart-shopping' ),
    'first_order'       => array( 'label' => esc_html__( 'First Order Bonus', 'simple-points-and-rewards' ),      'icon' => 'fa-flag' ),
    'nth_order'         => array( 'label' => esc_html__( 'Nth Order Bonus', 'simple-points-and-rewards' ),        'icon' => 'fa-ranking-star' ),
    'referral'          => array( 'label' => esc_html__( 'Referrals', 'simple-points-and-rewards' ),              'icon' => 'fa-user-group' ),
    'review'            => array( 'label' => esc_html__( 'Reviews', 'simple-points-and-rewards' ),                'icon' => 'fa-star' ),
    'birthday'          => array( 'label' => esc_html__( 'Birthday Bonus', 'simple-points-and-rewards' ),         'icon' => 'fa-cake-candles' ),
    'daily_login'       => array( 'label' => esc_html__( 'Daily Login Bonus', 'simple-points-and-rewards' ),      'icon' => 'fa-calendar-check' ),
    'daily_login_streak'=> array( 'label' => esc_html__( 'Daily Login Streak Bonus', 'simple-points-and-rewards' ), 'icon' => 'fa-fire' ),
    'social_share'      => array( 'label' => esc_html__( 'Social Shares', 'simple-points-and-rewards' ),          'icon' => 'fa-share-nodes' ),
    'spin_wheel'        => array( 'label' => esc_html__( 'Prize Wheel', 'simple-points-and-rewards' ),            'icon' => 'fa-dharmachakra' ),
    'buy_products'      => array( 'label' => esc_html__( 'Product Offers & Bonuses', 'simple-points-and-rewards' ), 'icon' => 'fa-tags' ),
    'admin_adjustment'  => array( 'label' => esc_html__( 'Admin Adjustments', 'simple-points-and-rewards' ),      'icon' => 'fa-screwdriver-wrench' ),
        // Negative actions like 'redeem', 'order_refund' intentionally omitted from earned view
    '__other__'         => array( 'label' => esc_html__( 'Other', 'simple-points-and-rewards' ),                  'icon' => 'fa-gift' ),
    );
    $map = apply_filters( 'spar_points_method_map', $map, $points_label );

    // Determine which methods are enabled in settings
    $options = get_option( 'spar_options', array() );
    $earn    = isset( $options['earn'] ) && is_array( $options['earn'] ) ? $options['earn'] : array();
    $enabled_methods = array();
    if ( ! empty( $earn['signup']['enabled'] ) ) {
        $enabled_methods['signup'] = true;
    }
    if ( ! empty( $earn['order']['enabled'] ) || ! empty( $earn['order_fixed']['enabled'] ) ) {
        $enabled_methods['order'] = true; // both mechanisms contribute to action_id "order"
    }
    if ( ! empty( $earn['first_order']['enabled'] ) ) {
        $enabled_methods['first_order'] = true;
    }
    if ( ! empty( $earn['nth_order']['enabled'] ) ) {
        $enabled_methods['nth_order'] = true;
    }
    if ( ! empty( $earn['referral']['enabled'] ) ) {
        $enabled_methods['referral'] = true;
    }
    if ( ! empty( $earn['review']['enabled'] ) ) { // premium
        $enabled_methods['review'] = true;
    }
    if ( ! empty( $earn['birthday']['enabled'] ) ) { // premium
        $enabled_methods['birthday'] = true;
    }
    if ( ! empty( $earn['daily_login']['enabled'] ) ) { // premium
        $enabled_methods['daily_login'] = true;
        if ( ! empty( $earn['daily_login']['streak_enabled'] ) ) {
            $enabled_methods['daily_login_streak'] = true;
        }
    }
    if ( ! empty( $earn['social_sharing']['referral_social_points_enabled'] ) ) { // premium
        $enabled_methods['social_share'] = true;
    }
    if ( ! empty( $earn['spin_wheel']['enabled'] ) ) { // premium
        $enabled_methods['spin_wheel'] = true;
    }
    if ( isset( $options['buy_products'] ) && is_array( $options['buy_products'] ) && ! empty( $options['buy_products']['enabled'] ) ) { // premium
        $enabled_methods['buy_products'] = true;
    }
    /**
     * Filter the enabled methods map used to decide which cards to show.
     * Keys are method slugs (e.g., 'order', 'signup'). Values are truthy to enable.
     */
    $enabled_methods = apply_filters( 'spar_points_method_enabled_map', $enabled_methods, $options );

    // For legacy rows (no action_id), attempt to infer a method from the action text
    $infer_method_from_action = function( $action_text ) {
        $t = is_string( $action_text ) ? strtolower( $action_text ) : '';
        if ( $t === '' ) { return '__other__'; }
        if ( strpos( $t, 'signup' ) !== false ) { return 'signup'; }
        if ( strpos( $t, 'order' ) !== false ) { return 'order'; }
        if ( strpos( $t, 'referr' ) !== false ) { return 'referral'; }
        if ( strpos( $t, 'review' ) !== false ) { return 'review'; }
        if ( strpos( $t, 'admin' ) !== false ) { return 'admin_adjustment'; }
        return '__other__';
    };

    // If there is a legacy bucket, split it further by inspecting action text.
    $has_legacy = false;
    foreach ( (array) $rows as $r ) {
        if ( isset( $r['method'] ) && '__legacy__' === $r['method'] ) { $has_legacy = true; break; }
    }
    if ( $has_legacy ) {
        // Query all legacy rows for this date range to classify
    $legacy_sql_base = 'SELECT action, COUNT(*) AS cnt, SUM(points) AS pts FROM %i WHERE 1=1' . $where_dynamic . " AND (action_id IS NULL OR action_id = '') GROUP BY action";
    $legacy_sql      = call_user_func_array( array( $wpdb, 'prepare' ), array_merge( array( $legacy_sql_base, $table ), $params ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $legacy_rows = $wpdb->get_results( $legacy_sql, ARRAY_A );
        $buckets = array();
        foreach ( (array) $legacy_rows as $lr ) {
            $method = $infer_method_from_action( $lr['action'] ?? '' );
            if ( ! isset( $buckets[ $method ] ) ) {
                $buckets[ $method ] = array( 'count_events' => 0, 'total_points' => 0 );
            }
            $buckets[ $method ]['count_events'] += (int) ( $lr['cnt'] ?? 0 );
            $buckets[ $method ]['total_points'] += (int) ( $lr['pts'] ?? 0 );
        }
        // Rebuild rows without the __legacy__ placeholder
        $new_rows = array();
        foreach ( (array) $rows as $r ) {
            if ( isset( $r['method'] ) && '__legacy__' === $r['method'] ) {
                continue;
            }
            $new_rows[] = $r;
        }
        foreach ( $buckets as $method => $agg ) {
            $new_rows[] = array(
                'method'       => $method,
                'count_events' => (int) $agg['count_events'],
                'total_points' => (int) $agg['total_points'],
            );
        }
        // Sort desc by points
        usort( $new_rows, function( $a, $b ) {
            $ap = isset( $a['total_points'] ) ? (int) $a['total_points'] : 0;
            $bp = isset( $b['total_points'] ) ? (int) $b['total_points'] : 0;
            if ( $ap === $bp ) { return 0; }
            return ( $ap > $bp ) ? -1 : 1;
        } );
        $rows = $new_rows;
    }

    // Build a lookup of present data by method
    $present = array();
    foreach ( (array) $rows as $r ) {
        $m = sanitize_key( (string) ( $r['method'] ?? '' ) );
        if ( ! $m ) { $m = '__other__'; }
        // Buy Products logs per-rule action_ids like "buy_products_5"; roll them up.
        if ( 0 === strpos( $m, 'buy_products_' ) ) {
            $m = 'buy_products';
        }
        if ( ! isset( $present[ $m ] ) ) {
            $present[ $m ] = array( 'points' => 0, 'count' => 0 );
        }
        $present[ $m ]['points'] += (int) ( $r['total_points'] ?? 0 );
        $present[ $m ]['count']  += (int) ( $r['count_events'] ?? 0 );
    }

    // Normalize into final shape with labels/icons and include zeros for known methods
    $out = array();
    foreach ( $map as $method_key => $meta ) {
        if ( '__other__' === $method_key ) {
            continue; // handle unknowns after
        }
        // Only include if enabled in settings
        if ( empty( $enabled_methods[ $method_key ] ) ) {
            continue;
        }
        $data = $present[ $method_key ] ?? array( 'points' => 0, 'count' => 0 );
        $out[] = array(
            'method' => $method_key,
            'label'  => (string) $meta['label'],
            'icon'   => (string) $meta['icon'],
            'points' => (int) $data['points'],
            'count'  => (int) $data['count'],
        );
    }

    // Optionally append any additional methods not in the map (use generic formatting)
    $show_unknown = (bool) apply_filters( 'spar_points_per_method_show_unknown', false, $present, $map, $args );
    if ( $show_unknown ) {
        foreach ( $present as $method_key => $data ) {
            if ( isset( $map[ $method_key ] ) || '__legacy__' === $method_key ) {
                continue;
            }
            $label_guess = ucwords( str_replace( array( '-', '_' ), ' ', $method_key ) );
            $fallback = $map['__other__'];
            $out[] = array(
                'method' => $method_key,
                'label'  => $label_guess ? $label_guess : (string) $fallback['label'],
                'icon'   => (string) $fallback['icon'],
                'points' => (int) $data['points'],
                'count'  => (int) $data['count'],
            );
        }
    }

    // Sort by points desc so non-zero appear first, but zeros remain visible
    usort( $out, function( $a, $b ) {
        $ap = isset( $a['points'] ) ? (int) $a['points'] : 0;
        $bp = isset( $b['points'] ) ? (int) $b['points'] : 0;
        if ( $ap === $bp ) { return 0; }
        return ( $ap > $bp ) ? -1 : 1;
    } );

    /**
     * Filter the computed points-per-method rows before rendering.
     *
     * @param array $out  Array of rows with method, label, icon, points, count.
     * @param array $args Input args including date range.
     */
    $out = apply_filters( 'spar_points_per_method_rows', $out, $args );
    return $out;
}

/**
 * Get earned/redeemed points bucketed over time for the trend chart.
 *
 * Buckets are daily for ranges up to ~3 months, monthly beyond that. When no
 * range is given the series spans from the first log entry to today.
 *
 * Returns [ 'buckets' => [ [ 'label' => 'Jul 5', 'earned' => 120, 'redeemed' => 40 ], ... ], 'monthly' => bool ]
 */
function spar_get_points_timeseries( $args = array() ) {
    global $wpdb;

    $defaults = array(
        'date_from' => '',
        'date_to'   => '',
    );
    $args = wp_parse_args( $args, $defaults );

    $table       = $wpdb->prefix . 'spar_points_logs';
    $cache_group = 'spar';

    $date_from = sanitize_text_field( (string) $args['date_from'] );
    $date_to   = sanitize_text_field( (string) $args['date_to'] );
    if ( $date_from && ! strtotime( $date_from ) ) {
        $date_from = '';
    }
    if ( $date_to && ! strtotime( $date_to ) ) {
        $date_to = '';
    }
    if ( ! $date_to ) {
        $date_to = date_i18n( 'Y-m-d', current_time( 'timestamp' ) );
    }

    // Without a start date, span from the first log entry (all time).
    if ( ! $date_from ) {
        $min_key  = 'spar_reports_min_log_date';
        $min_date = wp_cache_get( $min_key, $cache_group );
        if ( false === $min_date ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $min_date = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(date) FROM %i', $table ) );
            wp_cache_set( $min_key, $min_date, $cache_group, 60 );
        }
        $date_from = $min_date ? substr( $min_date, 0, 10 ) : $date_to;
    }

    $from_ts = strtotime( $date_from . ' 00:00:00' );
    $to_ts   = strtotime( $date_to . ' 23:59:59' );
    if ( ! $from_ts || ! $to_ts || $from_ts > $to_ts ) {
        return array( 'buckets' => array(), 'monthly' => false );
    }

    $span_days = (int) floor( ( $to_ts - $from_ts ) / DAY_IN_SECONDS ) + 1;
    $monthly   = $span_days > 92;

    // Static SQL fragment — never user input. %% survives prepare() as a literal %.
    $bucket_expr = $monthly ? "DATE_FORMAT(date, '%%Y-%%m')" : 'DATE(date)';

    $sql_base = 'SELECT ' . $bucket_expr . " AS bucket,
            SUM(CASE WHEN (points > 0 OR type = 'add') THEN points ELSE 0 END) AS earned,
            SUM(CASE WHEN (points < 0 OR type = 'remove') THEN ABS(points) ELSE 0 END) AS redeemed
        FROM %i WHERE date >= %s AND date <= %s
        GROUP BY bucket ORDER BY bucket ASC";
    $sql = $wpdb->prepare( $sql_base, $table, $date_from . ' 00:00:00', $date_to . ' 23:59:59' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

    $cache_key = 'spar_reports_timeseries_' . md5( $sql );
    $rows      = wp_cache_get( $cache_key, $cache_group );
    if ( false === $rows ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows = $wpdb->get_results( $sql, ARRAY_A );
        wp_cache_set( $cache_key, $rows, $cache_group, 60 );
    }

    $by_bucket = array();
    foreach ( (array) $rows as $row ) {
        $by_bucket[ (string) $row['bucket'] ] = array(
            'earned'   => (int) $row['earned'],
            'redeemed' => (int) $row['redeemed'],
        );
    }

    // Fill gaps so the chart has a continuous axis.
    $buckets = array();
    try {
        $cursor = new DateTime( gmdate( 'Y-m-d', $from_ts ) );
        $end    = new DateTime( gmdate( 'Y-m-d', $to_ts ) );
        if ( $monthly ) {
            $cursor->modify( 'first day of this month' );
        }
        $guard = 0;
        while ( $cursor <= $end && $guard < 750 ) {
            $guard++;
            $key   = $monthly ? $cursor->format( 'Y-m' ) : $cursor->format( 'Y-m-d' );
            $label = $monthly
                ? date_i18n( 'M Y', $cursor->getTimestamp() )
                : date_i18n( 'M j', $cursor->getTimestamp() );
            $data      = isset( $by_bucket[ $key ] ) ? $by_bucket[ $key ] : array( 'earned' => 0, 'redeemed' => 0 );
            $buckets[] = array(
                'label'    => $label,
                'earned'   => (int) $data['earned'],
                'redeemed' => (int) $data['redeemed'],
            );
            $cursor->modify( $monthly ? 'first day of next month' : '+1 day' );
        }
    } catch ( Exception $e ) {
        return array( 'buckets' => array(), 'monthly' => $monthly );
    }

    return array( 'buckets' => $buckets, 'monthly' => $monthly );
}

/**
 * Get total redeemed/deducted points grouped by method within an optional date range.
 *
 * Mirrors spar_get_points_per_method() for the negative side of the ledger.
 * Only methods with activity in the range are returned.
 */
function spar_get_points_redeemed_per_method( $args = array() ) {
    global $wpdb;

    $defaults = array(
        'date_from' => '',
        'date_to'   => '',
    );
    $args = wp_parse_args( $args, $defaults );

    $table     = $wpdb->prefix . 'spar_points_logs';
    $conds     = array();
    $params    = array();
    $date_from = sanitize_text_field( (string) $args['date_from'] );
    $date_to   = sanitize_text_field( (string) $args['date_to'] );
    if ( $date_from && strtotime( $date_from ) ) { $conds[] = ' AND date >= %s'; $params[] = $date_from . ' 00:00:00'; }
    if ( $date_to && strtotime( $date_to ) ) { $conds[] = ' AND date <= %s'; $params[] = $date_to . ' 23:59:59'; }
    $conds[] = " AND (points < 0 OR type = 'remove')"; // static fragment
    $where_dynamic = implode( '', $conds );

    $sql_base = "SELECT
            CASE WHEN action_id IS NULL OR action_id = '' THEN '__legacy__' ELSE action_id END AS method,
            action,
            COUNT(*) AS count_events,
            SUM(ABS(points)) AS total_points
        FROM %i WHERE 1=1" . $where_dynamic . '
        GROUP BY method, action';
    $sql_args = array_merge( array( $sql_base, $table ), $params );
    $sql      = call_user_func_array( array( $wpdb, 'prepare' ), $sql_args );

    $cache_group = 'spar';
    $cache_key   = 'spar_reports_redeemed_per_method_' . md5( $sql );
    $rows        = wp_cache_get( $cache_key, $cache_group );
    if ( false === $rows ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows = $wpdb->get_results( $sql, ARRAY_A );
        wp_cache_set( $cache_key, $rows, $cache_group, 60 );
    }

    $map = array(
        'redeem'           => array( 'label' => esc_html__( 'Reward Redemptions', 'simple-points-and-rewards' ), 'icon' => 'fa-gift' ),
        'order_refund'     => array( 'label' => esc_html__( 'Order Refunds & Cancellations', 'simple-points-and-rewards' ), 'icon' => 'fa-rotate-left' ),
        'points_expiry'    => array( 'label' => esc_html__( 'Points Expiry', 'simple-points-and-rewards' ), 'icon' => 'fa-hourglass-end' ),
        'admin_adjustment' => array( 'label' => esc_html__( 'Admin Adjustments', 'simple-points-and-rewards' ), 'icon' => 'fa-screwdriver-wrench' ),
        'referral_clawback' => array( 'label' => esc_html__( 'Referral Clawbacks', 'simple-points-and-rewards' ), 'icon' => 'fa-user-minus' ),
        'buy_spins'        => array( 'label' => esc_html__( 'Prize Wheel Spins', 'simple-points-and-rewards' ), 'icon' => 'fa-dharmachakra' ),
        'migration'        => array( 'label' => esc_html__( 'Migration', 'simple-points-and-rewards' ), 'icon' => 'fa-database' ),
        '__other__'        => array( 'label' => esc_html__( 'Other', 'simple-points-and-rewards' ), 'icon' => 'fa-circle-minus' ),
    );
    $map = apply_filters( 'spar_points_redeemed_method_map', $map );

    // Normalise raw action_ids into display buckets.
    $normalise = function( $method, $action_text ) {
        $method = sanitize_key( (string) $method );
        if ( 0 === strpos( $method, 'referral_' ) ) {
            return 'referral_clawback';
        }
        if ( 0 === strpos( $method, 'migration' ) ) {
            return 'migration';
        }
        if ( in_array( $method, array( 'admin_undo', 'undone', 'admin_set_balance' ), true ) ) {
            return 'admin_adjustment';
        }
        if ( '__legacy__' === $method || '' === $method ) {
            $t = strtolower( (string) $action_text );
            if ( strpos( $t, 'voucher' ) !== false || strpos( $t, 'redeem' ) !== false || strpos( $t, 'claim' ) !== false ) { return 'redeem'; }
            if ( strpos( $t, 'refund' ) !== false || strpos( $t, 'cancel' ) !== false ) { return 'order_refund'; }
            if ( strpos( $t, 'expir' ) !== false ) { return 'points_expiry'; }
            if ( strpos( $t, 'undo' ) !== false || strpos( $t, 'admin' ) !== false ) { return 'admin_adjustment'; }
            if ( strpos( $t, 'spin' ) !== false ) { return 'buy_spins'; }
            return '__other__';
        }
        return $method;
    };

    $present = array();
    foreach ( (array) $rows as $row ) {
        $m = $normalise( $row['method'] ?? '', $row['action'] ?? '' );
        if ( ! isset( $map[ $m ] ) ) {
            $m = '__other__';
        }
        if ( ! isset( $present[ $m ] ) ) {
            $present[ $m ] = array( 'points' => 0, 'count' => 0 );
        }
        $present[ $m ]['points'] += (int) ( $row['total_points'] ?? 0 );
        $present[ $m ]['count']  += (int) ( $row['count_events'] ?? 0 );
    }

    $out = array();
    foreach ( $map as $method_key => $meta ) {
        if ( empty( $present[ $method_key ]['points'] ) ) {
            continue;
        }
        $out[] = array(
            'method' => $method_key,
            'label'  => (string) $meta['label'],
            'icon'   => (string) $meta['icon'],
            'points' => (int) $present[ $method_key ]['points'],
            'count'  => (int) $present[ $method_key ]['count'],
        );
    }

    usort( $out, function( $a, $b ) {
        return (int) $b['points'] <=> (int) $a['points'];
    } );

    /**
     * Filter the computed redeemed-points-per-method rows before rendering.
     */
    return apply_filters( 'spar_points_redeemed_per_method_rows', $out, $args );
}

/**
 * Render the earned vs redeemed trend as a dependency-free inline SVG line chart.
 *
 * @param array  $series       Result of spar_get_points_timeseries().
 * @param string $points_label Points label for tooltips.
 */
function spar_reports_render_trend_chart( $series, $points_label ) {
    $buckets = isset( $series['buckets'] ) ? (array) $series['buckets'] : array();
    $n       = count( $buckets );

    $max = 0;
    foreach ( $buckets as $b ) {
        $max = max( $max, (int) $b['earned'], (int) $b['redeemed'] );
    }

    if ( $n < 2 || $max <= 0 ) {
        echo '<div class="spar-chart-empty">' . esc_html__( 'Not enough points activity in this range to draw a trend yet.', 'simple-points-and-rewards' ) . '</div>';
        return;
    }

    // Round the axis maximum up to a "nice" 1/2/5 step.
    $magnitude = pow( 10, floor( log10( $max ) ) );
    foreach ( array( 1, 2, 5, 10 ) as $step ) {
        if ( $max <= $step * $magnitude ) {
            $max = (int) ceil( $step * $magnitude );
            break;
        }
    }

    // Axis values, worked out up front so the left gutter can be sized to fit
    // the widest one instead of reserving a fixed (and usually wasted) margin.
    // Widths are approximate character advances at font-size 12 in the admin font.
    $char_units  = array( '.' => 3.3, ',' => 3.3, 'k' => 6.3, 'M' => 10.4 );
    $axis_labels = array();
    $text_width  = 0;
    for ( $g = 0; $g <= 4; $g++ ) {
        $gv                = (int) round( $max * $g / 4 );
        $axis_labels[ $g ] = function_exists( 'spar_format_compact_number' ) ? spar_format_compact_number( $gv ) : number_format_i18n( $gv );

        $width = 0;
        foreach ( (array) preg_split( '//u', $axis_labels[ $g ], -1, PREG_SPLIT_NO_EMPTY ) as $char ) {
            $width += isset( $char_units[ $char ] ) ? $char_units[ $char ] : 6.7;
        }
        $text_width = max( $text_width, $width );
    }

    $w        = 1000;
    $h        = 250;
    $axis_gap = 6;  // space between an axis value and the plot area
    $x0       = min( 70, max( 26, (int) ceil( $text_width ) + $axis_gap + 2 ) );
    $x1       = 994;
    $y0       = 14;   // top
    $y1       = 222;  // baseline

    $x_for = function( $i ) use ( $x0, $x1, $n ) {
        return $n > 1 ? $x0 + ( $i * ( $x1 - $x0 ) / ( $n - 1 ) ) : ( $x0 + $x1 ) / 2;
    };
    $y_for = function( $v ) use ( $y0, $y1, $max ) {
        return $y1 - ( ( $v / $max ) * ( $y1 - $y0 ) );
    };

    $earned_points   = array();
    $redeemed_points = array();
    foreach ( $buckets as $i => $b ) {
        $earned_points[]   = round( $x_for( $i ), 1 ) . ',' . round( $y_for( (int) $b['earned'] ), 1 );
        $redeemed_points[] = round( $x_for( $i ), 1 ) . ',' . round( $y_for( (int) $b['redeemed'] ), 1 );
    }
    $earned_area = 'M' . $x0 . ',' . $y1 . ' L' . implode( ' L', $earned_points ) . ' L' . $x1 . ',' . $y1 . ' Z';

    // Roughly 6 x-axis labels.
    $label_every = max( 1, (int) ceil( $n / 6 ) );

    /* translators: %s: points label, e.g. "points". */
    $chart_label = sprintf( __( '%s earned vs redeemed over time. Use the left and right arrow keys to read each value.', 'simple-points-and-rewards' ), ucfirst( $points_label ) );
    ?>
    <div class="spar-chart-legend">
        <span class="spar-legend-item"><span class="spar-legend-swatch is-earned"></span><?php esc_html_e( 'Earned', 'simple-points-and-rewards' ); ?></span>
        <span class="spar-legend-item"><span class="spar-legend-swatch is-redeemed"></span><?php esc_html_e( 'Redeemed', 'simple-points-and-rewards' ); ?></span>
    </div>
    <div class="spar-chart-wrap">
    <svg class="spar-trend-chart" viewBox="0 0 <?php echo (int) $w; ?> <?php echo (int) $h; ?>" role="img" preserveAspectRatio="xMidYMid meet" tabindex="0"
        aria-label="<?php echo esc_attr( $chart_label ); ?>">
        <?php for ( $g = 0; $g <= 4; $g++ ) :
            $gy = round( $y1 - ( $g * ( $y1 - $y0 ) / 4 ), 1 );
        ?>
        <line x1="<?php echo (int) $x0; ?>" y1="<?php echo esc_attr( $gy ); ?>" x2="<?php echo (int) $x1; ?>" y2="<?php echo esc_attr( $gy ); ?>" stroke="#e2e4e7" stroke-width="1" />
        <text x="<?php echo (int) ( $x0 - $axis_gap ); ?>" y="<?php echo esc_attr( $gy + 4 ); ?>" text-anchor="end" font-size="12" fill="#50575e"><?php echo esc_html( $axis_labels[ $g ] ); ?></text>
        <?php endfor; ?>

        <path d="<?php echo esc_attr( $earned_area ); ?>" fill="#0a7c3a" opacity="0.07" />
        <polyline points="<?php echo esc_attr( implode( ' ', $earned_points ) ); ?>" fill="none" stroke="#0a7c3a" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" />
        <polyline points="<?php echo esc_attr( implode( ' ', $redeemed_points ) ); ?>" fill="none" stroke="#b12727" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" />

        <?php foreach ( $buckets as $i => $b ) :
            $bx = round( $x_for( $i ), 1 );
            if ( $n <= 62 ) : ?>
        <circle cx="<?php echo esc_attr( $bx ); ?>" cy="<?php echo esc_attr( round( $y_for( (int) $b['earned'] ), 1 ) ); ?>" r="3.5" fill="#0a7c3a" />
        <circle cx="<?php echo esc_attr( $bx ); ?>" cy="<?php echo esc_attr( round( $y_for( (int) $b['redeemed'] ), 1 ) ); ?>" r="3.5" fill="#b12727" />
            <?php endif;
            // Last label is end-anchored so it never clips the right edge; regular
            // labels too close to it are skipped to avoid overlap.
            $is_last      = ( $i === $n - 1 );
            $show_regular = ( 0 === $i % $label_every ) && ( $n - 1 - $i >= max( 2, (int) ceil( $label_every / 2 ) ) );
            if ( $show_regular || $is_last ) : ?>
        <text x="<?php echo esc_attr( $bx ); ?>" y="<?php echo (int) ( $y1 + 22 ); ?>" text-anchor="<?php echo $is_last ? 'end' : 'middle'; ?>" font-size="12" fill="#50575e"><?php echo esc_html( $b['label'] ); ?></text>
            <?php endif;
        endforeach; ?>

        <line x1="<?php echo (int) $x0; ?>" y1="<?php echo (int) $y1; ?>" x2="<?php echo (int) $x1; ?>" y2="<?php echo (int) $y1; ?>" stroke="#c3c4c7" stroke-width="1" />

        <g class="spar-chart-hover" aria-hidden="true">
            <line class="spar-chart-guide" x1="0" y1="<?php echo (int) $y0; ?>" x2="0" y2="<?php echo (int) $y1; ?>" stroke="#787c82" stroke-width="1" stroke-dasharray="3 3" />
            <circle class="spar-chart-marker is-earned" cx="0" cy="0" r="5" fill="#fff" stroke="#0a7c3a" stroke-width="2.5" />
            <circle class="spar-chart-marker is-redeemed" cx="0" cy="0" r="5" fill="#fff" stroke="#b12727" stroke-width="2.5" />
        </g>

        <?php
        // Invisible hover bands tile the plot area so the pointer never has to
        // land on a data point itself. Each carries its bucket's values.
        foreach ( $buckets as $i => $b ) :
            $bx    = $x_for( $i );
            $left  = ( 0 === $i ) ? 0 : ( $bx + $x_for( $i - 1 ) ) / 2;
            $right = ( $i === $n - 1 ) ? $w : ( $bx + $x_for( $i + 1 ) ) / 2;
        ?>
        <rect class="spar-chart-band" x="<?php echo esc_attr( round( $left, 1 ) ); ?>" y="<?php echo (int) $y0; ?>" width="<?php echo esc_attr( round( $right - $left, 1 ) ); ?>" height="<?php echo (int) ( $y1 - $y0 ); ?>" fill="transparent"
            data-label="<?php echo esc_attr( $b['label'] ); ?>"
            data-earned="<?php echo esc_attr( number_format_i18n( (int) $b['earned'] ) ); ?>"
            data-redeemed="<?php echo esc_attr( number_format_i18n( (int) $b['redeemed'] ) ); ?>"
            data-x="<?php echo esc_attr( round( $bx, 1 ) ); ?>"
            data-earned-y="<?php echo esc_attr( round( $y_for( (int) $b['earned'] ), 1 ) ); ?>"
            data-redeemed-y="<?php echo esc_attr( round( $y_for( (int) $b['redeemed'] ), 1 ) ); ?>" />
        <?php endforeach; ?>
    </svg>
    <div class="spar-chart-tooltip" role="status" aria-live="polite" hidden>
        <div class="spar-tooltip-label"></div>
        <div class="spar-tooltip-row">
            <span class="spar-tooltip-swatch is-earned"></span>
            <span class="spar-tooltip-name"><?php esc_html_e( 'Earned', 'simple-points-and-rewards' ); ?></span>
            <span class="spar-tooltip-value" data-role="earned"></span>
        </div>
        <div class="spar-tooltip-row">
            <span class="spar-tooltip-swatch is-redeemed"></span>
            <span class="spar-tooltip-name"><?php esc_html_e( 'Redeemed', 'simple-points-and-rewards' ); ?></span>
            <span class="spar-tooltip-value" data-role="redeemed"></span>
        </div>
    </div>
    </div>
    <?php
}

/**
 * Render the Admin Reports page
 */
function spar_reports_admin_page() {
    // Capability check (defense in depth)
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_die( esc_html__( 'You do not have permission to view this page.', 'simple-points-and-rewards' ) );
    }
    $points_label = apply_filters( 'spar_points_label', esc_html__( 'Points', 'simple-points-and-rewards' ) );

    // Process filters (GET)
    $date_from = isset( $_GET['spar_from'] ) ? sanitize_text_field( wp_unslash( $_GET['spar_from'] ) ) : '';
    $date_to   = isset( $_GET['spar_to'] ) ? sanitize_text_field( wp_unslash( $_GET['spar_to'] ) ) : '';

    // Verify nonce for filters; if invalid, ignore provided dates
    $has_valid_nonce = isset( $_GET['spar_reports_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['spar_reports_nonce'] ) ), 'spar_reports_filter' );
    if ( ! $has_valid_nonce ) {
        $date_from = '';
        $date_to   = '';
    }

    // Basic YYYY-MM-DD validation; else reset
    if ( $date_from && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) ) {
        $date_from = '';
    }
    if ( $date_to && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) ) {
        $date_to = '';
    }

    // Quick-range dropdown: a selected preset overrides the manual date inputs.
    $range = isset( $_GET['spar_range'] ) ? sanitize_key( wp_unslash( $_GET['spar_range'] ) ) : '';
    if ( ! $has_valid_nonce ) {
        $range = '';
    }
    if ( in_array( $range, array( '7', '30', '90', '365' ), true ) ) {
        $date_to   = date_i18n( 'Y-m-d', current_time( 'timestamp' ) );
        $date_from = date_i18n( 'Y-m-d', current_time( 'timestamp' ) - ( ( (int) $range - 1 ) * DAY_IN_SECONDS ) );
    } elseif ( 'all' === $range ) {
        $date_from = '';
        $date_to   = '';
    }

    // Dropdown selection to render (infer when not explicitly provided).
    if ( ! in_array( $range, array( 'all', '7', '30', '90', '365', 'custom' ), true ) ) {
        $range = ( '' === $date_from && '' === $date_to ) ? 'all' : 'custom';
    }

    $stats = spar_get_reports_stats( array(
        'date_from' => $date_from,
        'date_to'   => $date_to,
        'limit'     => 10,
    ) );

    if ( function_exists( 'spar_render_admin_header' ) ) {
    spar_render_admin_header( esc_html__( 'Reports', 'simple-points-and-rewards' ) );
    }
    ?>
    <div class="wrap spar-reports-wrap">
        <form method="get" action="" class="spar-reports-filters" style="margin: 12px 0 16px;">
            <input type="hidden" name="page" value="spar-reports" />
            <?php wp_nonce_field( 'spar_reports_filter', 'spar_reports_nonce', false ); ?>

            <label for="spar_range" class="screen-reader-text"><?php esc_html_e( 'Date range', 'simple-points-and-rewards' ); ?></label>
            <select id="spar_range" name="spar_range" onchange="if ( this.value !== 'custom' ) { this.form.submit(); }">
                <option value="all" <?php selected( $range, 'all' ); ?>><?php esc_html_e( 'All time', 'simple-points-and-rewards' ); ?></option>
                <option value="7" <?php selected( $range, '7' ); ?>><?php esc_html_e( 'Last 7 days', 'simple-points-and-rewards' ); ?></option>
                <option value="30" <?php selected( $range, '30' ); ?>><?php esc_html_e( 'Last 30 days', 'simple-points-and-rewards' ); ?></option>
                <option value="90" <?php selected( $range, '90' ); ?>><?php esc_html_e( 'Last 90 days', 'simple-points-and-rewards' ); ?></option>
                <option value="365" <?php selected( $range, '365' ); ?>><?php esc_html_e( 'Last 12 months', 'simple-points-and-rewards' ); ?></option>
                <option value="custom" <?php selected( $range, 'custom' ); ?>><?php esc_html_e( 'Custom range', 'simple-points-and-rewards' ); ?></option>
            </select>

            <label for="spar_from" class="screen-reader-text"><?php esc_html_e( 'From date', 'simple-points-and-rewards' ); ?></label>
            <input type="date" id="spar_from" name="spar_from" value="<?php echo esc_attr( $date_from ); ?>" onchange="document.getElementById('spar_range').value = 'custom';" />

            <label for="spar_to" class="screen-reader-text"><?php esc_html_e( 'To date', 'simple-points-and-rewards' ); ?></label>
            <input type="date" id="spar_to" name="spar_to" value="<?php echo esc_attr( $date_to ); ?>" onchange="document.getElementById('spar_range').value = 'custom';" />

            <div class="actions">
                <button type="submit" class="button button-primary"><?php esc_html_e( 'Apply', 'simple-points-and-rewards' ); ?></button>
                <a href="<?php echo esc_url( remove_query_arg( array( 'spar_from', 'spar_to', 'spar_reports_nonce', '_wp_http_referer' ) ) ); ?>" class="button"><?php esc_html_e( 'Reset', 'simple-points-and-rewards' ); ?></a>
            </div>
        </form>

        <h2><?php esc_html_e( 'Overview', 'simple-points-and-rewards' ); ?></h2>

        <div class="spar-reports-cards" style="margin-bottom: 16px;">
            <div class="spar-card is-earned">
                <div class="spar-card-icon"><i class="fa-solid fa-arrow-up-right-dots" aria-hidden="true"></i></div>
                <div class="spar-card-content">
                    <div class="spar-card-label"><?php esc_html_e( 'Total Earned', 'simple-points-and-rewards' ); ?></div>
                    <div class="spar-card-value">+<?php echo esc_html( number_format_i18n( (int) $stats['earned'] ) ); ?> <?php echo esc_html( strtolower( $points_label ) ); ?></div>
                </div>
            </div>
            <div class="spar-card is-redeemed">
                <div class="spar-card-icon"><i class="fa-solid fa-arrow-down" aria-hidden="true"></i></div>
                <div class="spar-card-content">
                    <div class="spar-card-label"><?php esc_html_e( 'Total Redeemed', 'simple-points-and-rewards' ); ?></div>
                    <div class="spar-card-value">-<?php echo esc_html( number_format_i18n( (int) $stats['redeemed'] ) ); ?> <?php echo esc_html( strtolower( $points_label ) ); ?></div>
                </div>
            </div>
            <div class="spar-card is-net">
                <div class="spar-card-icon"><i class="fa-solid fa-scale-balanced" aria-hidden="true"></i></div>
                <div class="spar-card-content">
                    <div class="spar-card-label"><?php esc_html_e( 'Net Points', 'simple-points-and-rewards' ); ?></div>
                    <div class="spar-card-value"><?php echo esc_html( number_format_i18n( (int) $stats['net'] ) ); ?> <?php echo esc_html( strtolower( $points_label ) ); ?></div>
                </div>
            </div>
            <div class="spar-card is-activity">
                <div class="spar-card-icon"><i class="fa-solid fa-list-check" aria-hidden="true"></i></div>
                <div class="spar-card-content">
                    <div class="spar-card-label"><?php esc_html_e( 'Activities', 'simple-points-and-rewards' ); ?></div>
                    <div class="spar-card-value"><?php echo esc_html( number_format_i18n( (int) $stats['activity'] ) ); ?></div>
                </div>
            </div>
            <div class="spar-card is-customers">
                <div class="spar-card-icon"><i class="fa-solid fa-users" aria-hidden="true"></i></div>
                <div class="spar-card-content">
                    <div class="spar-card-label"><?php esc_html_e( 'Active Customers', 'simple-points-and-rewards' ); ?></div>
                    <div class="spar-card-value"><?php echo esc_html( number_format_i18n( (int) $stats['customers'] ) ); ?></div>
                </div>
            </div>
            <div class="spar-card is-redeemed" title="<?php esc_attr_e( 'Share of earned points that have been redeemed within the selected date range.', 'simple-points-and-rewards' ); ?>">
                <div class="spar-card-icon"><i class="fa-solid fa-percent" aria-hidden="true"></i></div>
                <div class="spar-card-content">
                    <div class="spar-card-label"><?php esc_html_e( 'Redemption Rate', 'simple-points-and-rewards' ); ?></div>
                    <div class="spar-card-value">
                        <?php
                        if ( null !== $stats['redemption_rate'] ) {
                            /* translators: %s: redemption rate percentage. */
                            echo esc_html( sprintf( __( '%s%%', 'simple-points-and-rewards' ), number_format_i18n( (float) $stats['redemption_rate'], 1 ) ) );
                        } else {
                            echo '&mdash;';
                        }
                        ?>
                    </div>
                </div>
            </div>
            <?php
            $liability = function_exists( 'spar_get_points_liability_snapshot' ) ? spar_get_points_liability_snapshot() : array( 'has_rate' => false );
            if ( ! empty( $liability['has_rate'] ) ) :
                /* translators: 1: number of points, 2: points label. */
                $liability_sub = sprintf(
                    __( 'Value of %1$s %2$s in circulation', 'simple-points-and-rewards' ),
                    number_format_i18n( (int) $liability['points'] ),
                    strtolower( $points_label )
                );
                ?>
            <div class="spar-card is-net" title="<?php esc_attr_e( 'Estimated cost if every outstanding point were redeemed today, based on the configured points redemption rate. Not affected by the date filter.', 'simple-points-and-rewards' ); ?>">
                <div class="spar-card-icon"><i class="fa-solid fa-file-invoice-dollar" aria-hidden="true"></i></div>
                <div class="spar-card-content">
                    <div class="spar-card-label"><?php esc_html_e( 'Points Liability', 'simple-points-and-rewards' ); ?></div>
                    <div class="spar-card-value"><?php echo wp_kses_post( function_exists( 'wc_price' ) ? wc_price( (float) $liability['amount'] ) : number_format_i18n( (float) $liability['amount'], 2 ) ); ?></div>
                    <div class="spar-card-sub"><?php echo esc_html( $liability_sub ); ?></div>
                </div>
            </div>
            <?php endif; ?>
            <div class="spar-card is-earned" title="<?php esc_attr_e( 'Average points earned per customer with activity in the selected date range.', 'simple-points-and-rewards' ); ?>">
                <div class="spar-card-icon"><i class="fa-solid fa-chart-line" aria-hidden="true"></i></div>
                <div class="spar-card-content">
                    <div class="spar-card-label"><?php esc_html_e( 'Avg Earned / Customer', 'simple-points-and-rewards' ); ?></div>
                    <div class="spar-card-value">
                        <?php
                        if ( (int) $stats['customers'] > 0 ) {
                            echo esc_html( number_format_i18n( round( (int) $stats['earned'] / (int) $stats['customers'] ) ) ) . ' ' . esc_html( strtolower( $points_label ) );
                        } else {
                            echo '&mdash;';
                        }
                        ?>
                    </div>
                </div>
            </div>
            <?php $spar_vouchers = function_exists( 'spar_get_outstanding_vouchers_snapshot' ) ? spar_get_outstanding_vouchers_snapshot() : null; ?>
            <?php if ( is_array( $spar_vouchers ) ) : ?>
            <div class="spar-card is-activity" title="<?php esc_attr_e( 'Claimed reward vouchers that have not been used or expired yet. Not affected by the date filter.', 'simple-points-and-rewards' ); ?>">
                <div class="spar-card-icon"><i class="fa-solid fa-ticket" aria-hidden="true"></i></div>
                <div class="spar-card-content">
                    <div class="spar-card-label"><?php esc_html_e( 'Outstanding Vouchers', 'simple-points-and-rewards' ); ?></div>
                    <div class="spar-card-value"><?php echo esc_html( number_format_i18n( (int) $spar_vouchers['count'] ) ); ?></div>
                    <?php if ( (float) $spar_vouchers['fixed_value'] > 0 ) : ?>
                    <div class="spar-card-sub">
                        <?php
                        /* translators: %s: formatted monetary value. */
                        echo wp_kses_post( sprintf( __( '%s in unused fixed-amount vouchers', 'simple-points-and-rewards' ), function_exists( 'wc_price' ) ? wc_price( (float) $spar_vouchers['fixed_value'] ) : number_format_i18n( (float) $spar_vouchers['fixed_value'], 2 ) ) );
                        ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="spar-panel spar-trend-panel">
            <h2><?php esc_html_e( 'Points Trend', 'simple-points-and-rewards' ); ?></h2>
            <?php
            $spar_series = spar_get_points_timeseries( array( 'date_from' => $date_from, 'date_to' => $date_to ) );
            spar_reports_render_trend_chart( $spar_series, $points_label );
            ?>
        </div>

        <?php
        // Points earned per method section
        $ppm_rows = spar_get_points_per_method( array( 'date_from' => $date_from, 'date_to' => $date_to ) ); ?>
        <div class="spar-report-table" style="margin-top:8px; grid-column: 1 / -1;">
            <h2 style="margin-top: 29px;"><?php esc_html_e( 'Points Earned Per Method', 'simple-points-and-rewards' ); ?></h2>
            <div class="spar-reports-cards">
                <?php foreach ( $ppm_rows as $row ) :
                    $label  = isset( $row['label'] ) ? $row['label'] : '';
                    $points = isset( $row['points'] ) ? (int) $row['points'] : 0;
                    $count  = isset( $row['count'] ) ? (int) $row['count'] : 0;
                    $icon   = isset( $row['icon'] ) ? $row['icon'] : 'fa-gift';
                ?>
                <div class="spar-card is-earned">
                    <div class="spar-card-icon"><i class="fa-solid <?php echo esc_attr( $icon ); ?>" aria-hidden="true"></i></div>
                    <div class="spar-card-content">
                        <div class="spar-card-label"><?php echo esc_html( $label ); ?></div>
                        <div class="spar-card-value">+<?php echo esc_html( number_format_i18n( (int) $points ) ); ?> <?php echo esc_html( strtolower( $points_label ) ); ?></div>
                        <?php if ( $count > 0 ) : ?>
                        <div class="spar-card-sub">
                            <?php
                            /* translators: %s: number of award events. */
                            echo esc_html( sprintf( _n( '%s award', '%s awards', $count, 'simple-points-and-rewards' ), number_format_i18n( $count ) ) );
                            ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php
        // Points redeemed/deducted per method section
        $rpm_rows = spar_get_points_redeemed_per_method( array( 'date_from' => $date_from, 'date_to' => $date_to ) );
        if ( ! empty( $rpm_rows ) ) : ?>
        <div class="spar-report-table" style="margin-top:8px; grid-column: 1 / -1;">
            <h2 style="margin-top: 29px;"><?php esc_html_e( 'Points Redeemed Per Method', 'simple-points-and-rewards' ); ?></h2>
            <div class="spar-reports-cards">
                <?php foreach ( $rpm_rows as $row ) : ?>
                <div class="spar-card is-redeemed">
                    <div class="spar-card-icon"><i class="fa-solid <?php echo esc_attr( $row['icon'] ); ?>" aria-hidden="true"></i></div>
                    <div class="spar-card-content">
                        <div class="spar-card-label"><?php echo esc_html( $row['label'] ); ?></div>
                        <div class="spar-card-value">-<?php echo esc_html( number_format_i18n( (int) $row['points'] ) ); ?> <?php echo esc_html( strtolower( $points_label ) ); ?></div>
                        <?php if ( (int) $row['count'] > 0 ) : ?>
                        <div class="spar-card-sub">
                            <?php
                            /* translators: %s: number of deduction events. */
                            echo esc_html( sprintf( _n( '%s deduction', '%s deductions', (int) $row['count'], 'simple-points-and-rewards' ), number_format_i18n( (int) $row['count'] ) ) );
                            ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>


        <div class="spar-reports-tables">
            <div class="spar-report-table">
                <h2><?php esc_html_e( 'Top Earners', 'simple-points-and-rewards' ); ?></h2>
                <div class="spar-table-wrap">
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'User', 'simple-points-and-rewards' ); ?></th>
                                <th style="text-align:right;">+<?php echo esc_html( $points_label ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ( ! empty( $stats['top_earners'] ) ) :
                                foreach ( $stats['top_earners'] as $row ) :
                                    $user = get_userdata( (int) $row['user_id'] );
                                    $user_name = $user ? $user->display_name : esc_html__( 'Unknown', 'simple-points-and-rewards' );
                                    $user_link = '';
                                    if ( $user ) {
                                        $user_link = add_query_arg(
                                            array(
                                                    'page'    => 'spar-customer',
                                                'user_id' => (int) $user->ID,
                                                'nonce'   => wp_create_nonce( 'spar_customer_detail_view_' . (int) $user->ID ),
                                            ),
                                            admin_url( 'admin.php' )
                                        );
                                    }
                                    ?>
                                    <tr>
                                        <td>
                                            <?php if ( $user_link ) : ?>
                                                <a href="<?php echo esc_url( $user_link ); ?>"><?php echo esc_html( $user_name ); ?></a>
                                            <?php else : ?>
                                                <?php echo esc_html( $user_name ); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align:right;">+<?php echo esc_html( number_format_i18n( (int) $row['total_points'] ) ); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else : ?>
                                <tr><td colspan="2"><?php esc_html_e( 'No data found for this range.', 'simple-points-and-rewards' ); ?></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="spar-report-table">
                <h2><?php esc_html_e( 'Top Redeemers', 'simple-points-and-rewards' ); ?></h2>
                <div class="spar-table-wrap">
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'User', 'simple-points-and-rewards' ); ?></th>
                                <th style="text-align:right;">-<?php echo esc_html( $points_label ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ( ! empty( $stats['top_redeemers'] ) ) :
                                foreach ( $stats['top_redeemers'] as $row ) :
                                    $user = get_userdata( (int) $row['user_id'] );
                                    $user_name = $user ? $user->display_name : esc_html__( 'Unknown', 'simple-points-and-rewards' );
                                    $user_link = '';
                                    if ( $user ) {
                                        $user_link = add_query_arg(
                                            array(
                                                    'page'    => 'spar-customer',
                                                'user_id' => (int) $user->ID,
                                                'nonce'   => wp_create_nonce( 'spar_customer_detail_view_' . (int) $user->ID ),
                                            ),
                                            admin_url( 'admin.php' )
                                        );
                                    }
                                    ?>
                                    <tr>
                                        <td>
                                            <?php if ( $user_link ) : ?>
                                                <a href="<?php echo esc_url( $user_link ); ?>"><?php echo esc_html( $user_name ); ?></a>
                                            <?php else : ?>
                                                <?php echo esc_html( $user_name ); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align:right;">-<?php echo esc_html( number_format_i18n( (int) $row['total_points'] ) ); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else : ?>
                                <tr><td colspan="2"><?php esc_html_e( 'No data found for this range.', 'simple-points-and-rewards' ); ?></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php $ref_stats = spar_get_referral_reports( array( 'date_from' => $date_from, 'date_to' => $date_to, 'limit' => 10 ) ); ?>
        <div class="spar-reports-tables" style="margin-top:24px;">
            <div class="spar-report-table" style="grid-column: 1 / -1;">
                <h2><?php esc_html_e( 'Referral Statistics', 'simple-points-and-rewards' ); ?></h2>
                <div class="spar-reports-cards">
                    <div class="spar-card is-activity">
                        <div class="spar-card-icon"><i class="fa-solid fa-mouse-pointer" aria-hidden="true"></i></div>
                        <div class="spar-card-content">
                            <div class="spar-card-label"><?php esc_html_e( 'Referral Clicks (All-time)', 'simple-points-and-rewards' ); ?></div>
                            <div class="spar-card-value"><?php echo esc_html( number_format_i18n( (int) $ref_stats['clicks_all_time'] ) ); ?></div>
                        </div>
                    </div>
                    <div class="spar-card is-earned">
                        <div class="spar-card-icon"><i class="fa-solid fa-user-plus" aria-hidden="true"></i></div>
                        <div class="spar-card-content">
                            <div class="spar-card-label"><?php esc_html_e( 'Successful Referrals (Range)', 'simple-points-and-rewards' ); ?></div>
                            <div class="spar-card-value"><?php echo esc_html( number_format_i18n( (int) $ref_stats['conversions_range'] ) ); ?></div>
                        </div>
                    </div>
                    <div class="spar-card is-net">
                        <div class="spar-card-icon"><i class="fa-solid fa-coins" aria-hidden="true"></i></div>
                        <div class="spar-card-content">
                            <div class="spar-card-label"><?php esc_html_e( 'Referral Points (Range)', 'simple-points-and-rewards' ); ?></div>
                            <div class="spar-card-value">+<?php echo esc_html( number_format_i18n( (int) $ref_stats['points_range'] ) ); ?> <?php echo esc_html( strtolower( $points_label ) ); ?></div>
                        </div>
                    </div>
                </div>

                <h3 style="margin-top:10px;">
                    <?php esc_html_e( 'Top Referrers (by points in range)', 'simple-points-and-rewards' ); ?>
                </h3>
                <div class="spar-table-wrap">
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'User', 'simple-points-and-rewards' ); ?></th>
                                <th style="text-align:right;">+<?php esc_html_e( 'Referral', 'simple-points-and-rewards' ); ?> <?php echo esc_html( strtolower( $points_label ) ); ?></th>
                                <th style="text-align:right;">&nbsp;<?php esc_html_e( 'Referrals', 'simple-points-and-rewards' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ( ! empty( $ref_stats['top_referrers'] ) ) : foreach ( $ref_stats['top_referrers'] as $row ) :
                                $user = get_userdata( (int) $row['user_id'] );
                                $user_name = $user ? $user->display_name : esc_html__( 'Unknown', 'simple-points-and-rewards' );
                                $user_link = '';
                                if ( $user ) {
                                    $user_link = add_query_arg(
                                        array(
                                                'page'    => 'spar-customer',
                                            'user_id' => (int) $user->ID,
                                            'nonce'   => wp_create_nonce( 'spar_customer_detail_view_' . (int) $user->ID ),
                                        ),
                                        admin_url( 'admin.php' )
                                    );
                                }
                                ?>
                                <tr>
                                    <td>
                                        <?php if ( $user_link ) : ?>
                                            <a href="<?php echo esc_url( $user_link ); ?>"><?php echo esc_html( $user_name ); ?></a>
                                        <?php else : ?>
                                            <?php echo esc_html( $user_name ); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:right;">+<?php echo esc_html( number_format_i18n( (int) $row['total_points'] ) ); ?></td>
                                    <td style="text-align:right;"><?php echo esc_html( number_format_i18n( (int) $row['referrals'] ) ); ?></td>
                                </tr>
                            <?php endforeach; else : ?>
                                <tr><td colspan="3"><?php esc_html_e( 'No referral data found for this range.', 'simple-points-and-rewards' ); ?></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
    <?php
}
