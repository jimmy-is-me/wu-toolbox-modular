<?php

/**
 * WooCommerce 即時營運分析 2.4.1
 * 完整替換舊版檔案；不要同時啟用兩版或重複貼入 functions.php。
 * WordPress 6.2+ / WooCommerce 8.2+ / PHP 7.4+。
 * 直接讀取核心訂單、退款、品項與商品資料。
 * 頁面只載入介面；報表由有權限及 nonce 保護的 AJAX 按需查詢。
 * 不輪詢、不建立統計排程、不快取報表。即時查詢仍有負載，設定範圍與時間上限。
 * 商品成本在新訂單保存快照；舊訂單以目前成本回推時明確標示估算。
 */
defined( 'ABSPATH' ) || exit;
if ( ! class_exists( 'JMI_WC_Admin_Insights', false ) ) {
final class JMI_WC_Admin_Insights {
    const VERSION = '2.4.1';
    const SLUG = 'jmi-wc-insights';
    const CAP = 'manage_options';
    const PER_PAGE = 25;
    const MAX_DAYS = 366;
    private static $hook = '';

    public static function boot() {
        add_action('admin_menu',array(__CLASS__,'menu'),40);
        add_action('wp_ajax_jmi_wci_live',array(__CLASS__,'ajax'));
        add_action('woocommerce_product_options_general_product_data',array(__CLASS__,'cost_field'));
        add_action('woocommerce_admin_process_product_object',array(__CLASS__,'save_cost'));
        add_action('woocommerce_variation_options_pricing',array(__CLASS__,'variation_cost_field'),10,3);
        add_action('woocommerce_admin_process_variation_object',array(__CLASS__,'save_variation_cost'),10,2);
        add_action('woocommerce_checkout_create_order_line_item',array(__CLASS__,'snapshot_cost'),10,4);
        add_action('woocommerce_new_order_item',array(__CLASS__,'snapshot_saved_item'),10,2);
    }

    public static function menu() {
        if(!class_exists('WooCommerce')||!current_user_can(self::CAP)){return;}
        self::$hook=add_menu_page('營運管理','營運管理',self::CAP,self::SLUG,array(__CLASS__,'render'),'dashicons-chart-area',3);
        add_action('load-'.self::$hook,array(__CLASS__,'save_context'));
    }
    private static function input( $key, $default = '' ) {
        return isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] )
            ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : $default;
    }

    private static function choice( $key, $allowed, $default ) {
        $value = self::input( $key, $default );
        return in_array( $value, $allowed, true ) ? $value : $default;
    }

    private static function date( $value ) {
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
            return false;
        }
        $d = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, wp_timezone() );
        return $d && $d->format( 'Y-m-d' ) === $value ? $d : false;
    }

    private static function state() {
        $today = new DateTimeImmutable( 'today', wp_timezone() );
        $from = self::date( self::input( 'from', $today->modify( '-29 days' )->format( 'Y-m-d' ) ) );
        $to = self::date( self::input( 'to', $today->format( 'Y-m-d' ) ) );
        if ( ! $from || ! $to || $from > $to || $to > $today ) {
            throw new RuntimeException( '請選擇有效日期，起日不得晚於迄日，迄日不得晚於今天。' );
        }
        $days = (int) $from->diff( $to )->days + 1;
        if ( $days > self::MAX_DAYS ) {
            throw new RuntimeException( '單次最多查詢 366 天；請縮小期間以減少資料庫負載。' );
        }
        return array(
            'tab' => self::choice( 'tab', array( 'overview', 'products', 'customers', 'stock', 'abc', 'finance' ), 'overview' ),
            'from' => $from->format( 'Y-m-d' ), 'to' => $to->format( 'Y-m-d' ),
            'days' => $days,
            'pg' => min( 10000, max( 1, absint( self::input( 'pg', '1' ) ) ) ),
            'q' => function_exists( 'mb_substr' ) ? mb_substr( self::input( 'q' ), 0, 80 ) : substr( self::input( 'q' ), 0, 80 ),
            'sort' => self::choice( 'sort', array( 'revenue', 'quantity', 'name' ), 'revenue' ),
            'sales' => self::choice( 'sales', array( 'all', 'sold', 'zero' ), 'all' ),
            'stock' => self::choice( 'stock', array( 'all', 'low', 'outofstock', 'onbackorder', 'unmanaged' ), 'all' ),
        );
    }

    private static function statuses() {
        // 退款原單仍須納入，否則全額退款會被低估。
        $statuses = apply_filters( 'jmi_wc_insights_sales_statuses', array_merge( wc_get_is_paid_statuses(), array( 'refunded' ) ) );
        $registered = wc_get_order_statuses();
        $clean = array();
        foreach ( (array) $statuses as $status ) {
            if ( ! is_string( $status ) ) { continue; }
            $status = 'wc-' . preg_replace( '/^wc-/', '', sanitize_key( $status ) );
            if ( isset( $registered[ $status ] ) ) { $clean[] = $status; }
        }
        return array_values( array_unique( $clean ?: array( 'wc-processing', 'wc-completed', 'wc-refunded' ) ) );
    }

    private static function url( $s, $changes = array() ) {
        return add_query_arg( array_merge( array( 'page' => self::SLUG ), $s, $changes ), admin_url( 'admin.php' ) );
    }

    private static $started = 0;
    const QUERY_MS = 8000;
    const REQUEST_SECONDS = 25;
    const MAX_ORDERS = 20000;
    const MAX_PRODUCT_GROUPS = 5000;

    private static function hpos() {
        return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    }

    private static function bounds( $s ) {
        $from = self::date( $s['from'] );
        $end = self::date( $s['to'] )->modify( '+1 day' );
        if ( self::hpos() ) {
            $utc = new DateTimeZone( 'UTC' );
            return array( $from->setTimezone( $utc )->format( 'Y-m-d H:i:s' ), $end->setTimezone( $utc )->format( 'Y-m-d H:i:s' ) );
        }
        return array( $from->format( 'Y-m-d H:i:s' ), $end->format( 'Y-m-d H:i:s' ) );
    }

    private static function local_time_sql( $column, $s ) {
        // 不依賴 MySQL timezone tables；處理查詢期間內的夏令時間切換。
        $tz = wp_timezone(); $from = self::date( $s['from'] ); $end = self::date( $s['to'] )->modify( '+1 day' );
        $offset = (int) $tz->getOffset( $from );
        $transitions = $tz->getTransitions( $from->getTimestamp(), $end->getTimestamp() );
        if ( ! $transitions || count( $transitions ) < 2 ) { return "DATE_ADD($column, INTERVAL $offset SECOND)"; }
        global $wpdb;
        $case = 'CASE';
        for ( $i = count( $transitions ) - 1; $i > 0; $i-- ) {
            $case .= $wpdb->prepare( " WHEN $column >= %s THEN %d", gmdate( 'Y-m-d H:i:s', $transitions[$i]['ts'] ), $transitions[$i]['offset'] );
        }
        $case .= " ELSE $offset END";
        return "DATE_ADD($column, INTERVAL ($case) SECOND)";
    }

    private static function status_sql( $column ) {
        global $wpdb;
        $values = self::statuses();
        return $wpdb->prepare( "$column IN (" . implode( ',', array_fill( 0, count( $values ), '%s' ) ) . ')', $values );
    }

    private static function anchor( $s, $refund = false, $eligible = true, $identity = null ) {
        global $wpdb;
        $a = $refund ? 'a' : 'p';
        if ( self::hpos() ) {
            $from = "{$wpdb->prefix}wc_orders p";
            if ( $refund ) { $from .= " INNER JOIN {$wpdb->prefix}wc_orders a ON a.id=p.parent_order_id"; }
            $where = "$a.type='shop_order' AND ($a.parent_order_id=0 OR $a.parent_order_id IS NULL) AND $a.status NOT IN ('trash','wc-trash','auto-draft','wc-auto-draft','wc-checkout-draft','checkout-draft')";
            if ( $refund ) { $where .= " AND p.type='shop_order_refund' AND COALESCE(p.status,'') NOT IN ('trash','wc-trash')"; }
            $date = "$a.date_created_gmt"; $status = "$a.status";
            if ( $identity ) {
                $where .= $identity['email'] !== '' ? $wpdb->prepare( " AND $a.billing_email=%s", $identity['email'] )
                    : $wpdb->prepare( " AND $a.customer_id=%d AND COALESCE($a.billing_email,'')=''", $identity['user_id'] );
            }
            $where .= $wpdb->prepare( " AND $a.currency=%s", get_woocommerce_currency() );
        } else {
            $from = "{$wpdb->posts} p";
            if ( $refund ) { $from .= " INNER JOIN {$wpdb->posts} a ON a.ID=p.post_parent"; }
            $where = "$a.post_type='shop_order' AND $a.post_parent=0 AND $a.post_status NOT IN ('trash','auto-draft','wc-checkout-draft','checkout-draft')";
            if ( $refund ) { $where .= " AND p.post_type='shop_order_refund' AND p.post_status NOT IN ('trash','auto-draft')"; }
            $date = "$a.post_date"; $status = "$a.post_status";
            $where .= $wpdb->prepare( " AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} cur WHERE cur.post_id=$a.ID AND cur.meta_key='_order_currency' AND cur.meta_value=%s)", get_woocommerce_currency() );
            if ( $identity ) {
                if ( $identity['email'] !== '' ) {
                    $where .= $wpdb->prepare( " AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} ident WHERE ident.post_id=$a.ID AND ident.meta_key='_billing_email' AND ident.meta_value=%s)", $identity['email'] );
                } else {
                    $where .= $wpdb->prepare( " AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} ident WHERE ident.post_id=$a.ID AND ident.meta_key='_customer_user' AND ident.meta_value=%s)", (string) $identity['user_id'] );
                    $where .= " AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} ident_email WHERE ident_email.post_id=$a.ID AND ident_email.meta_key='_billing_email' AND ident_email.meta_value<>'')";
                }
            }
        }
        $where .= self::batch_ids_sql(self::hpos()?"$a.id":"$a.ID");
        if ( $s ) { $bounds = self::bounds( $s ); $where .= $wpdb->prepare( " AND $date >= %s AND $date < %s", $bounds[0], $bounds[1] ); }
        if ( $eligible ) { $where .= ' AND ' . self::status_sql( $status ); }
        return array( $from, $where );
    }

    private static function headers( $s, $refund = false, $eligible = true, $identity = null ) {
        global $wpdb;
        list( $from, $where ) = self::anchor( $s, $refund, $eligible, $identity );
        if ( self::hpos() ) {
            $local = $s ? self::local_time_sql( 'p.date_created_gmt', $s ) : 'p.date_created_gmt';
            $from .= " LEFT JOIN {$wpdb->prefix}wc_order_operational_data op ON op.order_id=p.id";
            if ( ! $refund ) { $from .= " LEFT JOIN {$wpdb->prefix}wc_order_addresses bill ON bill.order_id=p.id AND bill.address_type='billing'"; }
            $customer = $refund ? "0 AS user_id, '' AS email, '' AS first_name, '' AS last_name" : "COALESCE(p.customer_id,0) AS user_id, LOWER(TRIM(COALESCE(p.billing_email,''))) AS email, COALESCE(bill.first_name,'') AS first_name, COALESCE(bill.last_name,'') AS last_name";
            return "SELECT p.id AS order_id, p.parent_order_id AS parent_id, $local AS date_created, p.date_created_gmt AS date_utc,
                p.status, p.currency, $customer, COALESCE(p.total_amount,0) AS total_sales,
                COALESCE(p.tax_amount,0)+COALESCE(op.shipping_tax_amount,0) AS tax_total, COALESCE(op.shipping_total_amount,0) AS shipping_total,
                COALESCE(op.discount_total_amount,0) AS discount_total FROM $from WHERE $where";
        }
        $keys = array( 'total_sales' => '_order_total', 'tax_total' => '_order_tax', 'shipping_tax' => '_order_shipping_tax',
            'shipping_total' => '_order_shipping', 'discount_total' => '_cart_discount', 'currency' => '_order_currency' );
        if ( $refund ) { $keys['refund_amount'] = '_refund_amount'; }
        else { $keys += array( 'user_id' => '_customer_user', 'email' => '_billing_email', 'first_name' => '_billing_first_name', 'last_name' => '_billing_last_name' ); }
        $select = array();
        foreach ( $keys as $alias => $key ) { $select[] = "MAX(CASE WHEN m.meta_key='$key' THEN m.meta_value END) AS $alias"; }
        $in = "'" . implode( "','", array_values( $keys ) ) . "'";
        $pivot = "SELECT p.ID AS order_id, p.post_parent AS parent_id, p.post_date AS date_created, p.post_date_gmt AS date_utc,
            p.post_status AS status, " . implode( ',', $select ) . " FROM $from LEFT JOIN {$wpdb->postmeta} m ON m.post_id=p.ID AND m.meta_key IN ($in)
            WHERE $where GROUP BY p.ID, p.post_parent, p.post_date, p.post_date_gmt, p.post_status";
        $total = $refund ? 'CASE WHEN refund_amount IS NOT NULL THEN -ABS(CAST(refund_amount AS DECIMAL(26,8))) ELSE COALESCE(CAST(total_sales AS DECIMAL(26,8)),0) END' : 'COALESCE(CAST(total_sales AS DECIMAL(26,8)),0)';
        $customer = $refund ? "0 AS user_id, '' AS email, '' AS first_name, '' AS last_name" : "COALESCE(CAST(user_id AS UNSIGNED),0) AS user_id, LOWER(TRIM(COALESCE(email,''))) AS email, COALESCE(first_name,'') AS first_name, COALESCE(last_name,'') AS last_name";
        return "SELECT order_id, parent_id, date_created, date_utc, status, currency, $customer, $total AS total_sales,
            COALESCE(CAST(tax_total AS DECIMAL(26,8)),0)+COALESCE(CAST(shipping_tax AS DECIMAL(26,8)),0) AS tax_total,
            COALESCE(CAST(shipping_total AS DECIMAL(26,8)),0) AS shipping_total,
            COALESCE(CAST(discount_total AS DECIMAL(26,8)),0) AS discount_total FROM ($pivot) h";
    }

    private static function cohort( $s, $identity = null ) {
        $orders = self::headers( $s, false, true, $identity ); $refunds = self::headers( $s, true, true, $identity );
        return "SELECT o.*, o.total_sales-o.tax_total-o.shipping_total AS net_total,
            COALESCE(r.refund_total,0) AS refund_total, COALESCE(r.refund_tax,0) AS refund_tax,
            COALESCE(r.refund_shipping,0) AS refund_shipping, COALESCE(r.refund_records,0) AS refund_records
            FROM ($orders) o LEFT JOIN (SELECT parent_id, SUM(ABS(total_sales)) AS refund_total,
                SUM(ABS(tax_total)) AS refund_tax, SUM(ABS(shipping_total)) AS refund_shipping, COUNT(*) AS refund_records
                FROM ($refunds) f GROUP BY parent_id) r ON r.parent_id=o.order_id";
    }

    private static function identity_sql( $alias ) {
        return "CASE WHEN $alias.email<>'' THEN CONCAT('e:', $alias.email) WHEN $alias.user_id>0 THEN CONCAT('u:',$alias.user_id) ELSE CONCAT('o:',$alias.order_id) END";
    }

    private static function guard_orders( $s, $identity = null ) {
        global $wpdb;
        list( $from, $where ) = self::anchor( $s, false, false, $identity );
        $id = self::hpos() ? 'p.id' : 'p.ID';
        // 查到門檻就停止；不先掃完整店鋪計算 COUNT(*)。
        $r = self::rows( 'SELECT COUNT(*) AS n FROM (SELECT ' . $id . " FROM $from WHERE $where LIMIT " . ( self::MAX_ORDERS + 1 ) . ') bounded' );
        if ( $r[0]['n'] > self::MAX_ORDERS ) { throw new RuntimeException( '此範圍超過 20,000 張訂單。請縮短日期；顧客累積消費請改用外部大型報表。未顯示部分合計。' ); }
    }

    private static function check_deadline() {
        if ( self::$started && microtime( true ) - self::$started > self::REQUEST_SECONDS ) { throw new RuntimeException( '查詢時間已達限制，請縮短期間或改用較小範圍。' ); }
    }

    private static function rows( $sql ) {
        global $wpdb;
        self::check_deadline();
        if ( ! preg_match( '/^\s*SELECT\b/i', $sql ) ) { throw new RuntimeException( '僅允許唯讀統計查詢。' ); }
        $version = method_exists( $wpdb, 'db_server_info' ) ? $wpdb->db_server_info() : '';
        if ( stripos( $version, 'MariaDB' ) !== false ) {
            $sql = 'SET STATEMENT max_statement_time=' . ( self::QUERY_MS / 1000 ) . ' FOR ' . $sql;
        } elseif ( $version && version_compare( preg_replace( '/[^0-9.].*$/', '', $version ), '5.7.8', '>=' ) ) {
            $sql = preg_replace( '/^\s*SELECT\b/i', 'SELECT /*+ MAX_EXECUTION_TIME(' . self::QUERY_MS . ') */', $sql, 1 );
        } else {
            throw new RuntimeException('目前資料庫無法確認支援查詢時間限制，已停止報表讀取。請主機商確認 MySQL／MariaDB 版本。');
        }
        $r = $wpdb->get_results( $sql, ARRAY_A );
        if ( $wpdb->last_error ) {
            // 管理員可以看到必要的資料庫診斷，不直接顯示 SQL／帳單資料。
            $error = trim( wp_strip_all_tags( (string) $wpdb->last_error ) );
            $reason = preg_match('/timeout|time exceeded|interrupted|max_statement_time|execution time/i',$error) ? '資料庫執行時間超出上限' : '資料庫查詢錯誤';
            error_log( '[WUMETAX insights] ' . $reason . ': ' . substr( $error, 0, 480 ) );
            throw new RuntimeException( $reason . '（' . esc_html( function_exists('mb_substr') ? mb_substr($error,0,170) : substr($error,0,170) ) . '），可重試或查看主機錯誤紀錄。' );
        }
        return $r ?: array();
    }

    private static function summary( $s ) {
        $c = self::cohort( $s ); $key = self::identity_sql( 'c' );
        $r = self::rows( "SELECT COUNT(*) AS orders, COALESCE(SUM(total_sales),0) AS gross,
            COALESCE(SUM(refund_total),0) AS refunds, COALESCE(SUM(total_sales-refund_total),0) AS collected,
            COALESCE(SUM(net_total-refund_total+refund_tax+refund_shipping),0) AS net,
            COALESCE(SUM(tax_total-refund_tax),0) AS tax, COALESCE(SUM(shipping_total-refund_shipping),0) AS shipping,
            COALESCE(SUM(discount_total),0) AS discounts,
            COALESCE(SUM(CASE WHEN refund_total>0 OR status='wc-refunded' THEN 1 ELSE 0 END),0) AS refunded_orders,
            COALESCE(SUM(CASE WHEN status='wc-refunded' AND refund_records=0 THEN 1 ELSE 0 END),0) AS missing_refunds,
            COUNT(DISTINCT $key) AS customers,
            COALESCE(SUM(CASE WHEN user_id>0 THEN total_sales-refund_total ELSE 0 END),0) AS member_revenue
            FROM ($c) c" );
        return $r[0];
    }

    private static function item_lines( $s, $refund = false ) {
        global $wpdb;
        // 商品彙總只需要訂單 ID 與日期，避免把帳單、稅、運費的彙總重複巢狀查詢。
        list($from,$where)=self::anchor($s,$refund);
        if(self::hpos()){$local=self::local_time_sql('p.date_created_gmt',$s);$h="SELECT p.id AS order_id,p.parent_order_id AS parent_id,$local AS date_created FROM $from WHERE $where";}
        else{$h="SELECT p.ID AS order_id,p.post_parent AS parent_id,p.post_date AS date_created FROM $from WHERE $where";}
        $items = $wpdb->prefix . 'woocommerce_order_items'; $meta = $wpdb->prefix . 'woocommerce_order_itemmeta';
        $keys = array( 'product_id' => '_product_id', 'variation_id' => '_variation_id', 'qty' => '_qty', 'revenue' => '_line_total',
            'unit_cost' => '_jmi_unit_cost_snapshot', 'native_cost' => '_cogs_value' );
        if ( $refund ) { $keys['refunded_item_id'] = '_refunded_item_id'; }
        $select = array(); foreach ( $keys as $alias => $key ) { $select[] = "MAX(CASE WHEN m.meta_key='$key' THEN m.meta_value END) AS $alias"; }
        $original = $refund ? 'o.parent_id' : 'o.order_id';
        $pivot = "SELECT i.order_item_id, i.order_item_name AS item_name, o.order_id, $original AS original_order, o.date_created,
            " . implode( ',', $select ) . " FROM ($h) o INNER JOIN $items i ON i.order_id=o.order_id AND i.order_item_type='line_item'
            LEFT JOIN $meta m ON m.order_item_id=i.order_item_id AND m.meta_key IN ('" . implode( "','", $keys ) . "')
            GROUP BY i.order_item_id, i.order_item_name, o.order_id, $original, o.date_created";
        $snapshot = "NULLIF(x.unit_cost,'')";
        $native = "NULLIF(x.native_cost,'')";
        $extra = '';
        if ( $refund ) {
            // WooCommerce 退款會複製原品項資料；不可把複製的整列 COGS 當成部分退貨成本。
            $extra = " LEFT JOIN $items oi ON oi.order_item_id=CAST(x.refunded_item_id AS UNSIGNED) AND oi.order_id=x.original_order
                LEFT JOIN $meta ou ON ou.order_item_id=oi.order_item_id AND ou.meta_key='_jmi_unit_cost_snapshot'
                LEFT JOIN $meta oq ON oq.order_item_id=oi.order_item_id AND oq.meta_key='_qty'
                LEFT JOIN $meta oc ON oc.order_item_id=oi.order_item_id AND oc.meta_key='_cogs_value'";
            $snapshot = "COALESCE(NULLIF(x.unit_cost,''),NULLIF(ou.meta_value,''))";
            $native = "CASE WHEN CAST(oq.meta_value AS DECIMAL(26,8))>0 THEN NULLIF(oc.meta_value,'') ELSE NULL END";
        }
        $unit = "COALESCE($snapshot,NULLIF(cost.meta_value,''),NULLIF(parent_cost.meta_value,''))";
        $is_refund = $refund ? 1 : 0;
        $signed_native = $refund ? "CAST(x.qty AS DECIMAL(26,8))*ABS(CAST(oc.meta_value AS DECIMAL(26,8)))/NULLIF(CAST(oq.meta_value AS DECIMAL(26,8)),0)" : 'ABS(CAST(x.native_cost AS DECIMAL(26,8)))';
        return "SELECT CAST(COALESCE(x.product_id,0) AS UNSIGNED) AS product_id,
            CAST(COALESCE(x.variation_id,0) AS UNSIGNED) AS variation_id, x.original_order, x.date_created, x.item_name,
            CAST(COALESCE(x.qty,0) AS DECIMAL(26,8)) AS qty, CAST(COALESCE(x.revenue,0) AS DECIMAL(26,8)) AS revenue,
            CASE WHEN CAST(x.qty AS DECIMAL(26,8))=0 THEN 0
                WHEN $snapshot IS NOT NULL THEN CAST(x.qty AS DECIMAL(26,8))*CAST($snapshot AS DECIMAL(26,8))
                WHEN ($native) IS NOT NULL THEN $signed_native
                WHEN $unit IS NOT NULL THEN CAST(x.qty AS DECIMAL(26,8))*CAST($unit AS DECIMAL(26,8)) ELSE NULL END AS cogs,
            CASE WHEN CAST(x.qty AS DECIMAL(26,8))=0 THEN 'quantity_zero'
                WHEN $snapshot IS NOT NULL OR ($native) IS NOT NULL THEN 'snapshot'
                WHEN $unit IS NOT NULL THEN 'current' ELSE 'missing' END AS cost_basis, $is_refund AS is_refund
            FROM ($pivot) x $extra LEFT JOIN {$wpdb->postmeta} cost
                ON cost.post_id=CASE WHEN CAST(COALESCE(x.variation_id,0) AS UNSIGNED)>0 THEN CAST(x.variation_id AS UNSIGNED) ELSE CAST(x.product_id AS UNSIGNED) END AND cost.meta_key='_jmi_unit_cost'
            LEFT JOIN {$wpdb->postmeta} parent_cost ON parent_cost.post_id=CAST(x.product_id AS UNSIGNED)
                AND CAST(COALESCE(x.variation_id,0) AS UNSIGNED)>0 AND parent_cost.meta_key='_jmi_unit_cost'";
    }

    private static function sales_sql( $s ) {
        $originals = self::item_lines( $s ); $refunds = self::item_lines( $s, true );
        return "SELECT product_id, MAX(item_name) AS historical_name, SUM(qty) AS qty, SUM(revenue) AS revenue,
            SUM(CASE WHEN is_refund=0 THEN qty ELSE 0 END) AS sold_qty,
            SUM(CASE WHEN is_refund=1 THEN ABS(qty) ELSE 0 END) AS refund_qty,
            SUM(CASE WHEN is_refund=1 THEN ABS(revenue) ELSE 0 END) AS refund_amount,
            COUNT(DISTINCT CASE WHEN is_refund=0 THEN original_order END) AS orders,
            SUM(COALESCE(cogs,0)) AS cogs,
            SUM(CASE WHEN cost_basis='missing' THEN 1 ELSE 0 END) AS missing_cost,
            SUM(CASE WHEN cost_basis='current' THEN 1 ELSE 0 END) AS estimated_cost,
            MAX(CASE WHEN is_refund=0 THEN date_created ELSE NULL END) AS last_sale
            FROM ($originals UNION ALL $refunds) lines GROUP BY product_id";
    }

    private static function overview( $s ) {
        global $wpdb;
        $p = $s; $p['to'] = self::date( $s['from'] )->modify( '-1 day' )->format( 'Y-m-d' );
        $p['from'] = self::date( $s['from'] )->modify( '-' . $s['days'] . ' days' )->format( 'Y-m-d' );
        self::guard_orders( $s ); self::guard_orders( $p );
        $c = self::cohort( $s ); $all = self::headers( $s, false, false ); $key = self::identity_sql( 'c' );
        $repeat = self::rows( "SELECT COUNT(*) AS customers, COALESCE(SUM(CASE WHEN n>=2 THEN 1 ELSE 0 END),0) AS repeat_customers
            FROM (SELECT $key AS identity_key, COUNT(*) AS n FROM ($c) c GROUP BY $key) customer_groups" )[0];
        return array( 'current' => self::summary( $s ), 'previous' => self::summary( $p ), 'previous_from' => $p['from'], 'previous_to' => $p['to'],
            'repeat' => $repeat,
            'daily' => self::rows( "SELECT DATE(date_created) AS day, COUNT(*) AS orders, SUM(total_sales-refund_total) AS collected,
                SUM(net_total-refund_total+refund_tax+refund_shipping) AS net FROM ($c) c GROUP BY DATE(date_created) ORDER BY day" ),
            'statuses' => self::rows( "SELECT status, COUNT(*) AS orders FROM ($all) a GROUP BY status ORDER BY orders DESC" ) );
    }

    private static function customers( $s ) {
        global $wpdb;
        self::guard_orders( $s ); $c = self::cohort( $s ); $key = self::identity_sql( 'c' ); $having = '';
        if ( $s['q'] !== '' ) { $like = '%' . $wpdb->esc_like( $s['q'] ) . '%'; $having = $wpdb->prepare( ' HAVING MAX(CASE WHEN c.first_name LIKE %s OR c.last_name LIKE %s OR c.email LIKE %s THEN 1 ELSE 0 END)=1', $like, $like, $like ); }
        $r = self::rows( $wpdb->prepare( "SELECT $key AS identity_key, MAX(user_id) AS user_id, MAX(email) AS email,
            MAX(first_name) AS first_name, MAX(last_name) AS last_name, COUNT(*) AS orders,
            SUM(total_sales-refund_total) AS collected, SUM(refund_total) AS refunds,
            SUM(CASE WHEN refund_total>0 OR status='wc-refunded' THEN 1 ELSE 0 END) AS refunded_orders,
            MIN(date_created) AS first_order, MAX(date_created) AS last_order
            FROM ($c) c GROUP BY $key $having ORDER BY collected DESC, identity_key ASC LIMIT %d OFFSET %d",
            self::PER_PAGE + 1, ( $s['pg'] - 1 ) * self::PER_PAGE ) );
        return self::page_data( $r );
    }

    private static function lifetime( $identity ) {
        if ( $identity['email'] === '' && $identity['user_id'] < 1 ) { throw new RuntimeException( '此訂單沒有可辨識的 Email 或會員 ID，無法可靠歸戶。' ); }
        self::guard_orders( null, $identity ); $c = self::cohort( null, $identity );
        return self::rows( "SELECT COUNT(*) AS orders, COALESCE(SUM(total_sales-refund_total),0) AS collected,
            COALESCE(SUM(refund_total),0) AS refunds, MIN(date_created) AS first_order, MAX(date_created) AS last_order FROM ($c) c" )[0];
    }

    private static function abc( $s ) {
        global $wpdb;
        self::guard_orders( $s ); $sales = self::sales_sql( $s );
        $rows = self::rows( "SELECT a.*, COALESCE(p.post_title,a.historical_name) AS name FROM ($sales) a
            LEFT JOIN {$wpdb->posts} p ON p.ID=a.product_id ORDER BY a.revenue DESC, a.product_id ASC LIMIT " . ( self::MAX_PRODUCT_GROUPS + 1 ) );
        if ( count( $rows ) > self::MAX_PRODUCT_GROUPS ) { throw new RuntimeException( '商品群組超過 5,000 個，請縮小期間。未以不完整資料計算商品貢獻。' ); }
        $total = 0; $cogs = 0; $missing = 0; $estimated = 0; $units = 0;
        foreach ( $rows as $r ) { $total += max( 0, (float) $r['revenue'] ); $cogs += (float) $r['cogs']; $missing += (int) $r['missing_cost']; $estimated += (int) $r['estimated_cost']; $units += (float) $r['qty']; }
        $cumulative = 0; $counts = array( 'A' => 0, 'B' => 0, 'C' => 0 );
        foreach ( $rows as &$r ) {
            $r['share'] = self::rate( max( 0, (float) $r['revenue'] ), $total );
            // 用加入本商品前的累計比率分類，跨過門檻的商品留在較高級別。
            $r['abc'] = $r['revenue'] > 0 && $total > 0 ? ( $cumulative < 80 ? 'A' : ( $cumulative < 95 ? 'B' : 'C' ) ) : 'C';
            $cumulative += $r['share']; $r['cumulative'] = $cumulative; $counts[$r['abc']]++;
        } unset( $r );
        $paged = array_slice( $rows, ( $s['pg'] - 1 ) * self::PER_PAGE, self::PER_PAGE + 1 );
        $d = self::page_data( $paged ); $d += array( 'counts' => $counts, 'cogs' => $cogs, 'missing_cost' => $missing, 'estimated_cost' => $estimated, 'units' => $units );
        return $d;
    }

    private static function field_definitions() {
        return array(
            'cogs' => '本期實際銷貨成本（選填，覆蓋商品成本估算）', 'ad_spend' => '廣告／行銷費',
            'shipping_cost' => '實際配送／退貨物流成本', 'platform_fees' => '平台抽成', 'payment_fees' => '金流手續費',
            'payroll' => '人事費用', 'overhead' => '其他營運／損耗費用',
            'sessions' => '網站工作階段數（sessions）', 'converted_sessions' => '完成購買的工作階段數',
            'new_customers' => '行銷歸因的新客人數', 'attributed_revenue' => '廣告歸因營收',
            'attributed_contribution' => '廣告歸因貢獻毛利（扣廣告費前）',
            'cart_sessions' => '加入購物車的工作階段數', 'abandoned_sessions' => '觀察期結束後仍未購買的購物車工作階段數',
            'contribution_margin' => '廣告費前的貢獻毛利率（%，非單純商品毛利率）',
            'average_inventory_cost' => '本期間平均庫存成本（非售價）',
        );
    }

    private static function context( $s ) {
        $all = get_option( 'jmi_wci_period_contexts_v2', array() );
        return $all[$s['from'] . ':' . $s['to'] . ':' . get_woocommerce_currency()] ?? array();
    }

    private static function finance( $s ) {
        self::guard_orders( $s ); $summary = self::summary( $s ); $sales = self::sales_sql( $s );
        $cost = self::rows( "SELECT COALESCE(SUM(cogs),0) AS cogs, COALESCE(SUM(missing_cost),0) AS missing_cost,
            COALESCE(SUM(estimated_cost),0) AS estimated_cost, COUNT(*) AS groups FROM ($sales) a" )[0];
        return self::finance_values($s,$summary,$cost);
    }

    private static function finance_values( $s, $summary, $cost ) {
        $context = self::context( $s );
        $value = function( $k ) use ( $context ) { return array_key_exists( $k, $context ) && $context[$k] !== null ? (float) $context[$k] : null; };
        $manual = $value( 'cogs' );
        $cogs = $manual !== null ? $manual : ( $cost['missing_cost'] == 0 ? (float) $cost['cogs'] : null );
        $gross_profit = $cogs !== null ? (float) $summary['net'] - $cogs : null;
        $expense_keys = array( 'ad_spend', 'shipping_cost', 'platform_fees', 'payment_fees', 'payroll', 'overhead' );
        $expenses = 0; $complete = ! empty( $context['expenses_complete'] );
        foreach ( $expense_keys as $k ) { $v = $value( $k ); if ( $v === null ) { $complete = false; } else { $expenses += $v; } }
        // 淨營收不含客戶支付的運費，因此淨利應再加回淨運費收入後扣實際配送成本。
        $net_profit = $gross_profit !== null && $complete ? $gross_profit + (float) $summary['shipping'] - $expenses : null;
        $divide = function( $a, $b ) { return $a !== null && $b !== null && $b > 0 ? $a / $b : null; };
        return array( 'summary' => $summary, 'cost' => $cost, 'context' => $context, 'cogs' => $cogs,
            'estimated' => $manual === null && $cost['estimated_cost'] > 0,
            'gross_profit' => $gross_profit, 'gross_margin' => $divide( $gross_profit, (float) $summary['net'] ),
            'net_profit' => $net_profit, 'expenses' => $expenses,
            'cvr' => $divide( $value( 'converted_sessions' ), $value( 'sessions' ) ),
            'cac' => $divide( $value( 'ad_spend' ), $value( 'new_customers' ) ),
            'roas' => $divide( $value( 'attributed_revenue' ), $value( 'ad_spend' ) ),
            'roi' => $divide( $value( 'attributed_contribution' ) !== null && $value( 'ad_spend' ) !== null ? $value( 'attributed_contribution' ) - $value( 'ad_spend' ) : null, $value( 'ad_spend' ) ),
            'cart_abandonment' => $divide( $value( 'abandoned_sessions' ), $value( 'cart_sessions' ) ),
            'break_even_roas' => $divide( 100, $value( 'contribution_margin' ) ),
            'turnover' => $divide( $cogs, $value( 'average_inventory_cost' ) ),
            'inventory_days' => $divide( $s['days'] * ( $value( 'average_inventory_cost' ) ?? 0 ), $value( 'average_inventory_cost' ) !== null ? $cogs : null ),
        );
    }

    private static function with_lock( $callback ) {
        global $wpdb;
        $name = 'jmi_wci_v2_' . md5( DB_NAME . $wpdb->prefix );
        $quiet = $wpdb->suppress_errors( true );
        $acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $name ) );
        if ( (string) $acquired !== '1' ) { $wpdb->suppress_errors( $quiet ); throw new RuntimeException( '另一份即時報表正在查詢，請稍後按「重新讀取」。' ); }
        self::$started = microtime( true );
        try { return $callback(); }
        finally {
            $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
            $wpdb->suppress_errors( $quiet ); self::$started = 0;
        }
    }

    public static function ajax() {
        if ( ! current_user_can( self::CAP ) ) { wp_send_json_error( array( 'message' => '沒有存取權限。' ), 403 ); }
        check_ajax_referer( 'jmi_wci_live', 'nonce' );
        if ( ! class_exists( 'WooCommerce' ) ) { wp_send_json_error( array( 'message' => '請先啟用 WooCommerce。' ), 400 ); }
        $ob = ob_get_level();
        try {
            if(isset($_POST['operation'])&&$_POST['operation']==='orders_page'){
                $page=isset($_POST['order_page'])?min(10000,max(1,absint($_POST['order_page']))):1;
                wp_send_json_success(self::orders_page($page));
            }
            if(isset($_POST['operation'])&&$_POST['operation']==='report_step'){
                $token=isset($_POST['token'])&&is_string($_POST['token'])?sanitize_text_field(wp_unslash($_POST['token'])):'';
                $seq=isset($_POST['seq'])&&is_scalar($_POST['seq'])?absint($_POST['seq']):0;
                wp_send_json_success(self::job_step($token,$seq));
            }
            if ( isset( $_POST['operation'] ) && $_POST['operation'] === 'lifetime' ) {
                $email = isset( $_POST['email'] ) && is_string( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
                $user = isset( $_POST['user_id'] ) && is_scalar( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
                if ( strlen( $email ) > 320 ) { throw new RuntimeException( 'Email 長度不正確。' ); }
                $r = self::with_lock( function() use ( $email, $user ) { return self::lifetime( array( 'email' => $email, 'user_id' => $user ) ); } );
                // 歷史日期保持 UTC；由 PHP 依商店時區轉換。
                if ( self::hpos() ) { foreach ( array( 'first_order', 'last_order' ) as $k ) { if ( $r[$k] ) { $r[$k] = ( new DateTimeImmutable( $r[$k], new DateTimeZone( 'UTC' ) ) )->setTimezone( wp_timezone() )->format( 'Y-m-d H:i:s' ); } } }
                wp_send_json_success( array( 'lifetime' => $r, 'read_at' => wp_date( 'Y-m-d H:i:s' ), 'currency' => get_woocommerce_currency() ) );
            }
            $_GET = array();
            foreach ( array( 'tab', 'from', 'to', 'pg', 'q', 'sort', 'sales', 'stock' ) as $k ) { if ( isset( $_POST[$k] ) ) { $_GET[$k] = $_POST[$k]; } }
            $s = self::state();
            wp_send_json_success(self::job_start($s));
        } catch ( Throwable $e ) {
            while ( ob_get_level() > $ob ) { ob_end_clean(); }
            wp_send_json_error( array( 'message'=>$e->getMessage() ), 400 );
        }
    }

    // 僅在面板開啟期間分批讀取；暫存只是未完成工作狀態，完成報表不跨請求快取。
    const BATCH_ORDERS = 40;
    const JOB_TTL = 600;
    const JOB_BYTES = 4194304;
    private static $batch_ids = null;

    private static function batch_ids_sql( $column ) {
        if ( self::$batch_ids === null ) { return ''; }
        return ' AND '.$column.' IN ('.implode(',',array_map('absint',self::$batch_ids ?: array(0))).')';
    }

    private static function job_key( $token ) { return 'jmi_wci_job_'.get_current_user_id().'_'.$token; }

    /** 將分批進度壓縮到 transient，不再儲存幾千到幾萬筆訂單 ID。 */
    private static function job_store( $token, $job ) {
        $serialized = serialize( $job );
        if (strlen($serialized) > 32 * 1024 * 1024) { throw new RuntimeException('讀取進度超過記憶體限制，請縮短期間。'); }
        $data = function_exists( 'gzcompress' ) ? gzcompress( $serialized, 3 ) : false;
        $record = is_string( $data ) ? array( '_jmi_job_v3' => 1, 'compressed' => true, 'data' => base64_encode( $data ) )
            : array( '_jmi_job_v3' => 1, 'compressed' => false, 'data' => $job );
        if ( strlen( serialize( $record ) ) > self::JOB_BYTES ) {
            throw new RuntimeException( '目前資料量超過暫存記憶體限制，請縮短日期區間，避免主機負載過高。' );
        }
        if ( ! set_transient( self::job_key( $token ), $record, self::JOB_TTL ) ) {
            throw new RuntimeException( '無法保存分批讀取進度，請確認 WordPress transient／物件快取設定。' );
        }
    }

    private static function job_load( $token ) {
        $record = get_transient( self::job_key( $token ) );
        if ( ! is_array( $record ) || empty( $record['_jmi_job_v3'] ) ) { return false; }
        if ( ! $record['compressed'] ) { return is_array( $record['data'] ) ? $record['data'] : false; }
        $binary = base64_decode( $record['data'], true );
        if ( $binary === false || ! function_exists( 'gzuncompress' ) ) { return false; }
        $serialized = @gzuncompress( $binary, 32 * 1024 * 1024 );
        if ( $serialized === false || strlen( $serialized ) > 32 * 1024 * 1024 ) { return false; }
        $job = @unserialize( $serialized, array( 'allowed_classes' => false ) );
        return is_array( $job ) ? $job : false;
    }

    /** 以 ID 游標逐頁讀取，不做 OFFSET，也不預先取得整段歷史 ID 清單。 */
    private static function next_order_batch( $s, $cursor ) {
        global $wpdb;
        list( $from, $where ) = self::anchor( $s, false, false );
        $column = self::hpos() ? 'p.id' : 'p.ID';
        $where .= $wpdb->prepare( " AND $column > %d", absint( $cursor ) );
        $rows = self::rows( "SELECT $column AS id FROM $from WHERE $where ORDER BY $column ASC LIMIT " . ( self::BATCH_ORDERS + 1 ) );
        $more = count( $rows ) > self::BATCH_ORDERS;
        return array( 'ids' => array_map( 'intval', array_column( array_slice( $rows, 0, self::BATCH_ORDERS ), 'id' ) ), 'more' => $more );
    }

    private static function summary_empty() {
        return array_fill_keys( array( 'orders','gross','refunds','collected','net','tax','shipping','discounts','refunded_orders','missing_refunds','customers','member_revenue' ), 0 );
    }

    private static function job_start( $s ) {
        return self::with_lock( function() use ( $s ) {
            $active = 'jmi_wci_active_' . get_current_user_id();
            $old = get_transient( $active );
            if ( is_string( $old ) ) { delete_transient( self::job_key( $old ) ); }
            delete_transient( $active );
            if ( $s['tab'] === 'stock' ) { return self::report_payload( $s, self::stock( $s ) ); }
            $token = str_replace( '-', '', wp_generate_uuid4() );
            $job = array( 's'=>$s, 'hpos'=>self::hpos(), 'currency'=>get_woocommerce_currency(),
                'cursor'=>0, 'phase'=>'current', 'done'=>0, 'seq'=>0,
                'current'=>self::summary_empty(), 'previous'=>self::summary_empty(),
                'previous_s'=>null, 'customers'=>array(), 'daily'=>array(), 'statuses'=>array(), 'categories'=>array(), 'sales'=>array() );
            if ( $s['tab'] === 'overview' ) {
                $p = $s;
                $p['to'] = self::date( $s['from'] )->modify( '-1 day' )->format( 'Y-m-d' );
                $p['from'] = self::date( $s['from'] )->modify( '-' . $s['days'] . ' days' )->format( 'Y-m-d' );
                $job['previous_s'] = $p;
            }
            self::job_store( $token, $job );
            set_transient( $active, $token, self::JOB_TTL );
            return array( 'pending'=>true, 'token'=>$token, 'seq'=>0, 'done'=>0, 'total'=>null, 'phase'=>'current' );
        } );
    }

    /** 以 WooCommerce CRUD 讀取本批訂單；HPOS、傳統儲存及自訂狀態共用同一讀取路徑。 */
    private static function job_accumulate_orders( &$job, $orders, $previous ) {
        $s = $job['s'];
        $target = $previous ? 'previous' : 'current';
        $eligible = self::statuses();
        foreach ( $orders as $order ) {
            self::check_deadline();
            if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund ) { continue; }
            $status = 'wc-' . $order->get_status();
            if ( ! $previous && $s['tab'] === 'overview' ) {
                $job['statuses'][$status] = ( $job['statuses'][$status] ?? 0 ) + 1;
            }
            if ( ! in_array( $status, $eligible, true ) ) { continue; }
            $gross = (float) $order->get_total();
            $refund = abs( (float) $order->get_total_refunded() );
            $tax = (float) $order->get_total_tax();
            $shipping = (float) $order->get_shipping_total();
            $refund_tax = method_exists( $order, 'get_total_tax_refunded' ) ? abs( (float) $order->get_total_tax_refunded() ) : 0;
            $refund_shipping = method_exists( $order, 'get_total_shipping_refunded' ) ? abs( (float) $order->get_total_shipping_refunded() ) : 0;
            $collected = $gross - $refund;
            $net = $gross - $tax - $shipping - $refund + $refund_tax + $refund_shipping;
            $refunded = $refund > 0 || $status === 'wc-refunded';
            $sum = &$job[$target];
            $sum['orders']++; $sum['gross'] += $gross; $sum['refunds'] += $refund;
            $sum['collected'] += $collected; $sum['net'] += $net;
            $sum['tax'] += $tax - $refund_tax; $sum['shipping'] += $shipping - $refund_shipping;
            $sum['discounts'] += (float) $order->get_discount_total();
            $sum['refunded_orders'] += (int) $refunded;
            $sum['missing_refunds'] += (int) ( $status === 'wc-refunded' && count( $order->get_refunds() ) === 0 );
            $user_id = (int) $order->get_customer_id();
            if ( $user_id > 0 ) { $sum['member_revenue'] += $collected; }
            unset( $sum );
            if ( $previous ) { continue; }
            $email = strtolower( trim( (string) $order->get_billing_email() ) );
            $order_id = (int) $order->get_id();
            $key = $email !== '' ? 'e:' . $email : ( $user_id > 0 ? 'u:' . $user_id : 'o:' . $order_id );
            $date = $order->get_date_created();
            $when = $date ? $date->date_i18n( 'Y-m-d H:i:s' ) : wp_date( 'Y-m-d H:i:s' );
            if ( $s['tab'] === 'overview' ) {
                $job['customers'][$key] = ( $job['customers'][$key] ?? 0 ) + 1;
                $day = substr( $when, 0, 10 );
                if ( ! isset( $job['daily'][$day] ) ) { $job['daily'][$day] = array( 'day'=>$day, 'orders'=>0, 'collected'=>0, 'net'=>0 ); }
                $job['daily'][$day]['orders']++;
                $job['daily'][$day]['collected'] += $collected;
                $job['daily'][$day]['net'] += $net;
                self::job_accumulate_categories_for_order( $job, $order );
            } elseif ( $s['tab'] === 'customers' ) {
                if ( ! isset( $job['customers'][$key] ) ) {
                    $job['customers'][$key] = array( 'identity_key'=>$key, 'user_id'=>0, 'email'=>$email,
                        'first_name'=>'', 'last_name'=>'', 'orders'=>0, 'collected'=>0, 'refunds'=>0,
                        'refunded_orders'=>0, 'first_order'=>$when, 'last_order'=>$when, 'matched'=>$s['q']==='' );
                }
                $c = &$job['customers'][$key];
                $c['orders']++; $c['collected'] += $collected; $c['refunds'] += $refund;
                $c['refunded_orders'] += (int) $refunded;
                $c['user_id'] = max( $c['user_id'], $user_id );
                $c['first_name'] = (string) $order->get_billing_first_name() ?: $c['first_name'];
                $c['last_name'] = (string) $order->get_billing_last_name() ?: $c['last_name'];
                $c['first_order'] = min( $c['first_order'], $when );
                $c['last_order'] = max( $c['last_order'], $when );
                if ( $s['q'] !== '' && self::text_contains( $c['first_name'].' '.$c['last_name'].' '.$c['email'], $s['q'] ) ) { $c['matched'] = true; }
                unset( $c );
            }
        }
    }

    private static function text_contains( $text, $needle ) {
        return function_exists( 'mb_stripos' ) ? mb_stripos( $text, $needle ) !== false : stripos( $text, $needle ) !== false;
    }

    private static function job_accumulate_categories_for_order( &$job, $order ) {
        static $term_ids = array();
        $add = function( $item, $negative ) use ( &$job, &$term_ids ) {
            if ( ! $item instanceof WC_Order_Item_Product ) { return; }
            $product_id = (int) $item->get_product_id();
            if ( ! isset( $term_ids[$product_id] ) ) {
                $terms = $product_id ? wp_get_post_terms( $product_id, 'product_cat', array( 'fields'=>'ids' ) ) : array();
                $term_ids[$product_id] = ! is_wp_error( $terms ) && $terms ? min( array_map( 'intval', $terms ) ) : 0;
            }
            $category = $term_ids[$product_id];
            $value = $negative ? -abs( (float) $item->get_total() ) : (float) $item->get_total();
            $job['categories'][$category] = ( $job['categories'][$category] ?? 0 ) + $value;
        };
        foreach ( $order->get_items( 'line_item' ) as $item ) { $add( $item, false ); }
        foreach ( $order->get_refunds() as $refund ) {
            foreach ( $refund->get_items( 'line_item' ) as $item ) { $add( $item, true ); }
        }
    }

    /** 以商品行項目累加銷售數據，避免對整份 order_itemmeta 做大型多層 JOIN。 */
    private static function job_accumulate_sales( &$job, $orders ) {
        $eligible = self::statuses();
        foreach ( $orders as $order ) {
            self::check_deadline();
            if ( ! $order instanceof WC_Order || ! in_array( 'wc-' . $order->get_status(), $eligible, true ) ) { continue; }
            $sold = array(); $seen = array();
            foreach ( $order->get_items( 'line_item' ) as $item_id=>$item ) {
                if ( $item instanceof WC_Order_Item_Product ) {
                    $sold[$item_id] = $item;
                    self::accumulate_product_line( $job, $item, false, null );
                    $id = (int) $item->get_product_id();
                    if ( ! isset( $seen[$id] ) && isset( $job['sales'][$id] ) ) {
                        $job['sales'][$id]['orders']++; $seen[$id] = true;
                    }
                }
            }
            foreach ( $order->get_refunds() as $refund ) {
                foreach ( $refund->get_items( 'line_item' ) as $item ) {
                    if ( ! $item instanceof WC_Order_Item_Product ) { continue; }
                    $original_id = (int) $item->get_meta( '_refunded_item_id', true );
                    self::accumulate_product_line( $job, $item, true, $sold[$original_id] ?? null );
                }
            }
        }
        if ( count( $job['sales'] ) > self::MAX_PRODUCT_GROUPS ) {
            throw new RuntimeException( '本次商品群組超過 5,000 個，請縮短日期或使用較細的報表範圍。' );
        }
    }

    private static function accumulate_product_line( &$job, $item, $is_refund, $original ) {
        self::check_deadline();
        $pid = (int) $item->get_product_id();
        $qty = (float) $item->get_quantity();
        $qty = $is_refund ? -abs( $qty ) : $qty;
        $revenue = (float) $item->get_total();
        if ( $is_refund ) { $revenue = -abs( $revenue ); }
        if ( ! isset( $job['sales'][$pid] ) ) {
            $job['sales'][$pid] = array( 'product_id'=>$pid, 'historical_name'=>(string)$item->get_name(),
                'qty'=>0, 'revenue'=>0, 'sold_qty'=>0, 'refund_qty'=>0, 'refund_amount'=>0,
                'orders'=>0, 'cogs'=>0, 'missing_cost'=>0, 'estimated_cost'=>0, 'last_sale'=>null );
        }
        $r = &$job['sales'][$pid];
        $r['qty'] += $qty; $r['revenue'] += $revenue;
        if ( $is_refund ) { $r['refund_qty'] += abs( $qty ); $r['refund_amount'] += abs( $revenue ); }
        else { $r['sold_qty'] += $qty; }
        $cost_item = $original instanceof WC_Order_Item_Product ? $original : $item;
        $snapshot = $cost_item->get_meta( '_jmi_unit_cost_snapshot', true );
        $native = $cost_item->get_meta( '_cogs_value', true );
        $basis = '';
        if ( $qty == 0 ) { $basis = 'quantity_zero'; $cost = 0; }
        elseif ( $snapshot !== '' && is_numeric( $snapshot ) ) { $basis = 'snapshot'; $cost = $qty * (float) $snapshot; }
        elseif ( $native !== '' && is_numeric( $native ) && (float)$cost_item->get_quantity() != 0 ) {
            $basis = 'snapshot';
            $cost = $qty * abs( (float)$native ) / abs( (float)$cost_item->get_quantity() );
        } else {
            $vid = (int) $cost_item->get_variation_id();
            $unit = $vid > 0 ? get_post_meta( $vid, '_jmi_unit_cost', true ) : '';
            if ( $unit === '' ) { $unit = get_post_meta( $pid, '_jmi_unit_cost', true ); }
            if ( $unit !== '' && is_numeric( $unit ) ) { $basis = 'current'; $cost = $qty * (float) $unit; }
            else { $basis = 'missing'; $cost = 0; }
        }
        $r['cogs'] += $cost;
        if ( $basis === 'missing' ) { $r['missing_cost']++; }
        if ( $basis === 'current' ) { $r['estimated_cost']++; }
        if ( ! $is_refund ) {
            $when = $item->get_order() ? $item->get_order()->get_date_created() : false;
            if ( $when ) { $ts = $when->date_i18n( 'Y-m-d H:i:s' ); $r['last_sale'] = $r['last_sale'] ? max( $r['last_sale'], $ts ) : $ts; }
        }
        unset($r);
    }

    private static function job_step( $token, $seq ) {
        if ( ! preg_match( '/^[a-zA-Z0-9-]{12,64}$/', $token ) ) { throw new RuntimeException( '讀取進度無效，請重新讀取。' ); }
        return self::with_lock( function() use ( $token, $seq ) {
            $job = self::job_load( $token );
            if ( ! $job || (int)$job['seq'] !== $seq ) { throw new RuntimeException( '讀取進度已過期，或目前頁面重複送出讀取請求，請重新讀取。' ); }
            if ( $job['hpos'] !== self::hpos() || $job['currency'] !== get_woocommerce_currency() ) {
                delete_transient( self::job_key( $token ) );
                throw new RuntimeException( '商店訂單儲存方式或幣別已變更，請重新讀取。' );
            }
            $previous = $job['phase'] === 'previous';
            $s = $previous ? $job['previous_s'] : $job['s'];
            $batch = self::next_order_batch( $s, (int) $job['cursor'] );
            $ids = $batch['ids'];
            if ( $ids ) {
                $phase_done = (int)($job['phase_done'] ?? 0) + count($ids);
                if ($phase_done > self::MAX_ORDERS) { throw new RuntimeException('此期間超過 20,000 張訂單，請缩短日期；未顯示不完整統計。'); }
                $job['phase_done'] = $phase_done;
                $orders = array();
                foreach ( $ids as $id ) {
                    self::check_deadline();
                    $order = wc_get_order( $id );
                    if ( $order instanceof WC_Order ) { $orders[] = $order; }
                }
                if ( in_array( $job['s']['tab'], array( 'overview','customers','finance' ), true ) ) {
                    self::job_accumulate_orders( $job, $orders, $previous );
                }
                if ( ! $previous && in_array( $job['s']['tab'], array( 'products','abc','finance' ), true ) ) {
                    self::job_accumulate_sales( $job, $orders );
                }
                $job['cursor'] = (int) end( $ids );
                $job['done'] += count( $ids );
            }
            $job['seq']++;
            if ( ! $batch['more'] ) {
                if ( ! $previous && $job['s']['tab'] === 'overview' ) {
                    $job['phase'] = 'previous';
                    $job['cursor'] = 0;
                    $job['phase_done'] = 0;
                } else {
                    $payload = self::report_payload( $job['s'], self::job_finish( $job ) );
                    delete_transient( self::job_key( $token ) );
                    delete_transient( 'jmi_wci_active_' . get_current_user_id() );
                    return $payload;
                }
            }
            self::job_store( $token, $job );
            return array( 'pending'=>true, 'token'=>$token, 'seq'=>$job['seq'],
                'done'=>$job['done'], 'total'=>null, 'phase'=>$job['phase'] );
        } );
    }

    private static function catalog_rows( $s, $ids_only = false ) {
        global $wpdb;
        $where="p.post_type='product' AND p.post_status IN ('publish','private','draft','pending','future')";
        if($ids_only){$where='p.ID IN ('.implode(',',array_map('absint',$ids_only)).')';}
        elseif($s['q']!==''){
            $like='%'.$wpdb->esc_like($s['q']).'%';$where.=$wpdb->prepare(" AND (p.post_title LIKE %s OR sku.meta_value LIKE %s OR EXISTS (SELECT 1 FROM {$wpdb->posts} v INNER JOIN {$wpdb->postmeta} vs ON vs.post_id=v.ID AND vs.meta_key='_sku' WHERE v.post_parent=p.ID AND v.post_type='product_variation' AND v.post_status NOT IN ('trash','auto-draft') AND vs.meta_value LIKE %s))",$like,$like,$like);
        }
        $rows=self::rows("SELECT p.ID AS product_id,p.post_title AS name,p.post_status,sku.meta_value AS sku,unit_cost.meta_value AS configured_cost FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} sku ON sku.post_id=p.ID AND sku.meta_key='_sku' LEFT JOIN {$wpdb->postmeta} unit_cost ON unit_cost.post_id=p.ID AND unit_cost.meta_key='_jmi_unit_cost' WHERE $where ORDER BY p.ID LIMIT ".(self::MAX_PRODUCT_GROUPS+1));
        if(count($rows)>self::MAX_PRODUCT_GROUPS){throw new RuntimeException('符合篩選的商品超過 5,000 個，請使用名稱／SKU 縮小範圍。');}return $rows;
    }

    private static function job_finish( $job ) {
        $s=$job['s'];$tab=$s['tab'];$sales=$job['sales'];
        if($tab==='overview'){
            $job['current']['customers']=count($job['customers']);$repeat=0;foreach($job['customers'] as $n){$repeat+=(int)($n>=2);}
            $statuses=array();foreach($job['statuses'] as $status=>$n){$statuses[]=array('status'=>$status,'orders'=>$n);}
            usort($statuses,function($a,$b){return $b['orders']<=>$a['orders'] ?: strcmp($a['status'],$b['status']);});
            $category_rows=array();
            foreach($job['categories'] as $term_id=>$revenue){
                $term=$term_id>0?get_term((int)$term_id,'product_cat'):null;
                $name=$term&&!is_wp_error($term)?$term->name:'其他／未分類';
                $category_rows[]=array('id'=>(int)$term_id,'name'=>$name,'revenue'=>(float)$revenue);
            }
            usort($category_rows,function($a,$b){return $b['revenue']<=>$a['revenue'] ?: strcmp($a['name'],$b['name']);});
            return array('current'=>$job['current'],'previous'=>$job['previous'],'previous_from'=>$job['previous_s']['from'],'previous_to'=>$job['previous_s']['to'],'repeat'=>array('customers'=>count($job['customers']),'repeat_customers'=>$repeat),'daily'=>array_values($job['daily']),'statuses'=>$statuses,'categories'=>$category_rows);
        }
        if($tab==='customers'){
            $rows=array_values(array_filter($job['customers'],function($r){return $r['matched'];}));
            usort($rows,function($a,$b){return $b['collected']<=>$a['collected'] ?: strcmp($a['identity_key'],$b['identity_key']);});
            return self::page_data(array_slice($rows,($s['pg']-1)*self::PER_PAGE,self::PER_PAGE+1));
        }
        if($tab==='finance'){
            $cost=array('cogs'=>0,'missing_cost'=>0,'estimated_cost'=>0,'groups'=>count($sales));foreach($sales as $r){foreach(array('cogs','missing_cost','estimated_cost') as $field){$cost[$field]+=$r[$field];}}
            return self::finance_values($s,$job['current'],$cost);
        }
        if($tab==='products'){
            $rows=array();$defaults=array('qty'=>0,'revenue'=>0,'sold_qty'=>0,'refund_qty'=>0,'refund_amount'=>0,'orders'=>0,'cogs'=>0,'missing_cost'=>0,'estimated_cost'=>0,'last_sale'=>null);
            foreach(self::catalog_rows($s) as $r){$r+=($sales[$r['product_id']]??$defaults);if($s['sales']==='sold'&&$r['sold_qty']<=0||$s['sales']==='zero'&&$r['sold_qty']!=0){continue;}$rows[]=$r;}
            usort($rows,function($a,$b)use($s){if($s['sort']==='name'){$cmp=strcmp($a['name'],$b['name']);}else{$key=$s['sort']==='quantity'?'qty':'revenue';$cmp=$b[$key]<=>$a[$key];}return $cmp ?: ($a['product_id']<=>$b['product_id']);});
            return self::page_data(array_slice($rows,($s['pg']-1)*self::PER_PAGE,self::PER_PAGE+1));
        }
        $names=array();if($sales){foreach(self::catalog_rows($s,array_keys($sales)) as $r){$names[$r['product_id']]=$r['name'];}}
        $rows=array_values($sales);usort($rows,function($a,$b){return $b['revenue']<=>$a['revenue'] ?: ($a['product_id']<=>$b['product_id']);});
        $total=0;foreach($rows as $r){$total+=max(0,(float)$r['revenue']);}$cumulative=0;$counts=array('A'=>0,'B'=>0,'C'=>0);
        foreach($rows as &$r){$r['name']=$names[$r['product_id']]??$r['historical_name'];$r['share']=self::rate(max(0,(float)$r['revenue']),$total);$r['abc']=$r['revenue']>0&&$total>0?($cumulative<80?'A':($cumulative<95?'B':'C')):'C';$cumulative+=$r['share'];$r['cumulative']=$cumulative;$counts[$r['abc']]++;}unset($r);
        return self::page_data(array_slice($rows,($s['pg']-1)*self::PER_PAGE,self::PER_PAGE+1))+array('counts'=>$counts);
    }

    private static function report_payload( $s, $data ) {
        $views=array('overview'=>'view_overview','products'=>'view_products','customers'=>'view_customers','stock'=>'view_stock','abc'=>'view_abc','finance'=>'view_finance');
        ob_start();try{$method=$views[$s['tab']];$csv=self::$method($s,$data);$html=ob_get_contents();}finally{ob_end_clean();}
        return array('html'=>$html,'csv'=>$csv,'read_at'=>wp_date('Y-m-d H:i:s'),'currency'=>get_woocommerce_currency());
    }

    private static function products( $s ) {
        global $wpdb;
        $sales=self::sales_sql($s);
        $where="p.post_type='product' AND p.post_status IN ('publish','private','draft','pending','future')";
        if($s['q']!==''){
            $like='%'.$wpdb->esc_like($s['q']).'%';
            $where.=$wpdb->prepare(" AND (p.post_title LIKE %s OR sku.meta_value LIKE %s OR EXISTS (
                SELECT 1 FROM {$wpdb->posts} v INNER JOIN {$wpdb->postmeta} vs ON vs.post_id=v.ID AND vs.meta_key='_sku'
                WHERE v.post_parent=p.ID AND v.post_type='product_variation' AND v.post_status NOT IN ('trash','auto-draft') AND vs.meta_value LIKE %s))",$like,$like,$like);
        }
        if($s['sales']==='sold'){$where.=' AND COALESCE(a.sold_qty,0)>0';}
        if($s['sales']==='zero'){$where.=' AND COALESCE(a.sold_qty,0)=0';}
        $sorts=array('revenue'=>'revenue DESC','quantity'=>'qty DESC','name'=>'p.post_title ASC');
        $rows=self::rows($wpdb->prepare("SELECT p.ID AS product_id,p.post_title AS name,p.post_status,sku.meta_value AS sku,
            unit_cost.meta_value AS configured_cost,COALESCE(a.qty,0) AS qty,COALESCE(a.revenue,0) AS revenue,
            COALESCE(a.sold_qty,0) AS sold_qty,COALESCE(a.refund_qty,0) AS refund_qty,
            COALESCE(a.refund_amount,0) AS refund_amount,COALESCE(a.orders,0) AS orders,
            COALESCE(a.missing_cost,0) AS missing_cost,COALESCE(a.estimated_cost,0) AS estimated_cost,
            a.last_sale FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} sku ON sku.post_id=p.ID AND sku.meta_key='_sku'
            LEFT JOIN {$wpdb->postmeta} unit_cost ON unit_cost.post_id=p.ID AND unit_cost.meta_key='_jmi_unit_cost'
            LEFT JOIN ($sales) a ON a.product_id=p.ID
            WHERE $where ORDER BY {$sorts[$s['sort']]},p.ID ASC LIMIT %d OFFSET %d",self::PER_PAGE+1,($s['pg']-1)*self::PER_PAGE));
        return self::page_data($rows);
    }

    private static function view_products( $s, $d ) {
        $headers=array('商品／SKU','上架狀態','原銷售量','退貨量','淨銷售量','淨商品銷售額','商品退款額','購買訂單','目前主商品單位成本／設定');
        $csv=array(array('商品 ID','商品','SKU','上架狀態','原銷售量','退貨量','淨銷售量','淨商品銷售額','商品退款額','購買訂單','目前主商品單位成本'));
        echo '<div class="jmi-panel"><h2>商品銷售明細與成本入口</h2><p>包含現存商品與零銷售商品；變體合併主商品，可搜尋變體 SKU。點擊商品進入編輯，在「營運分析：單位成本」填寫成本；變體可個別填寫並覆蓋主商品預設。</p><p>淨商品金額不含稅、運費與訂單費用。此期間無銷售而仍有庫存，是待檢查的滯銷候選；不代表庫齡已超過此期間。可搭配近 90／180 天查看。</p>';
        self::table_start($headers);
        foreach($d['rows'] as $r){$status=get_post_status_object($r['post_status']);$label=$status?$status->label:$r['post_status'];$cost=$r['configured_cost'];
            echo '<tr><td>'.self::product_name($r).'<small class="jmi-sku">#'.absint($r['product_id']).' · '.esc_html($r['sku']?:'未設定 SKU').'</small></td><td>'.esc_html($label).'</td>';
            foreach(array('sold_qty','refund_qty','qty') as $field){echo '<td>'.self::num($r[$field],2).'</td>';}
            echo '<td>'.self::money($r['revenue']).'</td><td>'.self::money($r['refund_amount']).'</td><td>'.self::num($r['orders']).'</td><td>'.($cost===null||$cost===''?'未填寫':self::money($cost)).'<small class="jmi-sku">'.self::product_name(array('product_id'=>$r['product_id'],'name'=>'前往編輯成本')).'</small></td></tr>';
            $csv[]=array((int)$r['product_id'],$r['name'],$r['sku'],$label,(float)$r['sold_qty'],(float)$r['refund_qty'],(float)$r['qty'],(float)$r['revenue'],(float)$r['refund_amount'],(int)$r['orders'],$cost===null||$cost===''?'未填寫':(float)$cost);
        }
        self::table_end(!$d['rows'],count($headers));self::pager($s,$d);echo '</div>';return $csv;
    }

    private static function stock( $s ) {
        global $wpdb;
        $low = max( 0, (float) get_option( 'woocommerce_notify_low_stock_amount', 2 ) );
        $no_stock = max( 0, (float) get_option( 'woocommerce_notify_no_stock_amount', 0 ) );
        // 變體低庫存門檻：變體設定 → 主商品設定 → 全站設定。
        $threshold = $wpdb->prepare( "CAST(COALESCE(NULLIF(ls.meta_value,''),NULLIF(pls.meta_value,''),%s) AS DECIMAL(20,4))", (string) $low );
        $joins = "FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} sku ON sku.post_id=p.ID AND sku.meta_key='_sku'
            LEFT JOIN {$wpdb->postmeta} quantity ON quantity.post_id=p.ID AND quantity.meta_key='_stock'
            LEFT JOIN {$wpdb->postmeta} stock_status ON stock_status.post_id=p.ID AND stock_status.meta_key='_stock_status'
            LEFT JOIN {$wpdb->posts} parent ON parent.ID=p.post_parent
            LEFT JOIN {$wpdb->postmeta} ms ON ms.post_id=p.ID AND ms.meta_key='_manage_stock'
            LEFT JOIN {$wpdb->postmeta} pms ON pms.post_id=parent.ID AND pms.meta_key='_manage_stock'
            LEFT JOIN {$wpdb->postmeta} ls ON ls.post_id=p.ID AND ls.meta_key='_low_stock_amount'
            LEFT JOIN {$wpdb->postmeta} pls ON pls.post_id=parent.ID AND pls.meta_key='_low_stock_amount'";
        $variable = "EXISTS (SELECT 1 FROM {$wpdb->term_relationships} tr
            INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id
            INNER JOIN {$wpdb->terms} t ON t.term_id=tt.term_id
            WHERE tr.object_id=p.ID AND tt.taxonomy='product_type' AND t.slug='variable')";
        // 只列庫存管理單位。共享父商品庫存的變體不重複列出。
        $base = "p.post_status IN ('publish','private') AND (
            (p.post_type='product' AND (COALESCE(ms.meta_value,'no')='yes' OR NOT $variable))
            OR (p.post_type='product_variation' AND parent.post_status IN ('publish','private')
                AND (COALESCE(ms.meta_value,'no')='yes' OR COALESCE(pms.meta_value,'no')<>'yes')) )";
        $is_low = $wpdb->prepare( "ms.meta_value='yes' AND CAST(NULLIF(quantity.meta_value,'') AS DECIMAL(26,8)) IS NOT NULL
            AND CAST(NULLIF(quantity.meta_value,'') AS DECIMAL(26,8))<= $threshold AND CAST(NULLIF(quantity.meta_value,'') AS DECIMAL(26,8))>%s AND COALESCE(stock_status.meta_value,'instock')='instock'", (string) $no_stock );
        if ( 'yes' !== get_option( 'woocommerce_manage_stock', 'yes' ) ) { $is_low = '1=0'; }
        $where = $base;
        if ( $s['q'] !== '' ) {
            $like = '%' . $wpdb->esc_like( $s['q'] ) . '%';
            $where .= $wpdb->prepare( ' AND (p.post_title LIKE %s OR parent.post_title LIKE %s OR sku.meta_value LIKE %s)', $like, $like, $like );
        }
        $summary = self::rows( "SELECT COUNT(*) AS total,
            COALESCE(SUM(CASE WHEN COALESCE(stock_status.meta_value,'instock')='outofstock' THEN 1 ELSE 0 END),0) AS outofstock,
            COALESCE(SUM(CASE WHEN ($is_low) THEN 1 ELSE 0 END),0) AS low,
            COALESCE(SUM(CASE WHEN COALESCE(stock_status.meta_value,'instock')='onbackorder' THEN 1 ELSE 0 END),0) AS onbackorder,
            COALESCE(SUM(CASE WHEN COALESCE(ms.meta_value,'no')<>'yes' THEN 1 ELSE 0 END),0) AS unmanaged
            $joins WHERE $where" );
        if ( 'low' === $s['stock'] ) { $where .= " AND ($is_low)"; }
        if ( in_array( $s['stock'], array( 'outofstock', 'onbackorder' ), true ) ) {
            $where .= $wpdb->prepare( " AND COALESCE(stock_status.meta_value,'instock')=%s", $s['stock'] );
        }
        if ( 'unmanaged' === $s['stock'] ) { $where .= " AND COALESCE(ms.meta_value,'no')<>'yes'"; }
        $offset = ( $s['pg'] - 1 ) * self::PER_PAGE;
        $rows = self::rows( $wpdb->prepare( "SELECT p.ID AS product_id, p.post_parent, p.post_type, p.post_title AS name,
            sku.meta_value AS sku, CAST(NULLIF(quantity.meta_value,'') AS DECIMAL(26,8)) AS stock_quantity, COALESCE(stock_status.meta_value,'instock') AS stock_status, COALESCE(ms.meta_value,'no') AS managed,
            $threshold AS threshold, CASE WHEN ($is_low) THEN 1 ELSE 0 END AS is_low
            $joins WHERE $where ORDER BY
                CASE WHEN COALESCE(stock_status.meta_value,'instock')='outofstock' THEN 0 WHEN ($is_low) THEN 1 WHEN COALESCE(stock_status.meta_value,'instock')='onbackorder' THEN 2 ELSE 3 END,
                p.ID ASC LIMIT %d OFFSET %d", self::PER_PAGE + 1, $offset ) );
        $data = self::page_data( $rows );
        $data['summary'] = $summary[0];
        return $data;
    }

    private static function page_data( $rows ) {
        return array( 'more' => count( $rows ) > self::PER_PAGE, 'rows' => array_slice( $rows, 0, self::PER_PAGE ) );
    }

    private static function money( $value ) {
        return wp_kses_post( wc_price( (float) $value, array( 'currency' => get_woocommerce_currency() ) ) );
    }

    private static function num( $value, $decimals = 0 ) {
        return esc_html( number_format_i18n( (float) $value, $decimals ) );
    }

    private static function rate( $a, $b ) {
        return $b > 0 ? (float) $a / (float) $b * 100 : 0;
    }

    private static function card( $label, $value, $note = '' ) {
        echo '<div class="jmi-card"><span>' . esc_html( $label ) . '</span><strong>' . wp_kses_post( $value ) . '</strong><small>' . esc_html( $note ) . '</small></div>';
    }

    private static function comparison( $now, $before ) {
        if ( (float) $before <= 0 ) { return '前期為零或負值，不計算增減率'; }
        $change = ( (float) $now - (float) $before ) / (float) $before * 100;
        return '較前期 ' . ( $change >= 0 ? '+' : '' ) . number_format_i18n( $change, 1 ) . '%';
    }

    private static function table_start( $headers ) {
        echo '<div class="jmi-scroll"><table class="widefat striped"><thead><tr>';
        foreach ( $headers as $h ) { echo '<th scope="col">' . esc_html( $h ) . '</th>'; }
        echo '</tr></thead><tbody>';
    }

    private static function table_end( $empty, $columns ) {
        if ( $empty ) { echo '<tr><td colspan="' . absint( $columns ) . '">沒有符合條件的資料。</td></tr>'; }
        echo '</tbody></table></div>';
    }

    private static function product_name( $row ) {
        $id = (int) $row['product_id'];
        $edit_id = ! empty( $row['post_parent'] ) ? (int) $row['post_parent'] : $id;
        $link = get_edit_post_link( $edit_id, 'raw' );
        $name = esc_html( $row['name'] ?: '商品 #' . $id );
        return $link ? '<a href="' . esc_url( $link ) . '">' . $name . '</a>' : $name;
    }

    private static function pager( $s, $data ) {
        echo '<div class="jmi-pager"><span>第 ' . absint( $s['pg'] ) . ' 頁 · 每頁最多 25 筆</span><div>';
        if ( $s['pg'] > 1 ) { echo '<a class="button" href="' . esc_url( self::url( $s, array( 'pg' => $s['pg'] - 1 ) ) ) . '">上一頁</a> '; }
        if ( $data['more'] && $s['pg'] < 10000 ) { echo '<a class="button" href="' . esc_url( self::url( $s, array( 'pg' => $s['pg'] + 1 ) ) ) . '">下一頁</a>'; }
        echo '</div></div>';
    }

    private static function select( $name, $label, $options, $value ) {
        echo '<label>' . esc_html( $label ) . '<select name="' . esc_attr( $name ) . '">';
        foreach ( $options as $key => $text ) {
            echo '<option value="' . esc_attr( $key ) . '" ' . selected( $key, $value, false ) . '>' . esc_html( $text ) . '</option>';
        }
        echo '</select></label>';
    }

    private static function filters( $s ) {
        echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="jmi-filter">';
        echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><input type="hidden" name="tab" value="' . esc_attr( $s['tab'] ) . '">';
        if ( 'stock' !== $s['tab'] ) {
            echo '<label>開始日期<input required type="date" name="from" value="' . esc_attr( $s['from'] ) . '"></label>';
            echo '<label>結束日期<input required type="date" name="to" max="' . esc_attr( wp_date( 'Y-m-d' ) ) . '" value="' . esc_attr( $s['to'] ) . '"></label>';
        }
        if ( in_array($s['tab'],array('products','customers','stock'),true) ) {
            echo '<label>' . ( 'customers' === $s['tab'] ? '姓名／Email' : '商品名稱／SKU' ) . '<input type="search" maxlength="80" name="q" value="' . esc_attr( $s['q'] ) . '"></label>';
        }
        if ( 'products' === $s['tab'] ) {
            self::select( 'sort', '排序', array( 'revenue' => '淨商品銷售額', 'quantity' => '淨銷售量', 'name' => '商品名稱' ), $s['sort'] );
            self::select( 'sales', '銷售篩選', array( 'all' => '全部商品', 'sold' => '期間有銷售', 'zero' => '期間零銷售' ), $s['sales'] );
        }
        if ( 'stock' === $s['tab'] ) {
            self::select( 'stock', '庫存狀態', array( 'all' => '全部', 'low' => '低庫存', 'outofstock' => '缺貨', 'onbackorder' => '待補貨／預購', 'unmanaged' => '未管理數量' ), $s['stock'] );
        }
        echo '<button class="button button-primary" type="submit">套用篩選</button></form>';
        if ( 'stock' !== $s['tab'] ) {
            $today = new DateTimeImmutable( 'today', wp_timezone() );
            $ranges = array( '今天' => $today, '近 7 天' => $today->modify( '-6 days' ), '近 30 天' => $today->modify( '-29 days' ), '本月' => $today->modify( 'first day of this month' ), '近 90 天' => $today->modify('-89 days'), '近 180 天' => $today->modify('-179 days') );
            echo '<div class="jmi-quick">';
            foreach ( $ranges as $label => $from ) {
                echo '<a class="button" href="' . esc_url( self::url( $s, array( 'from' => $from->format( 'Y-m-d' ), 'to' => $today->format( 'Y-m-d' ), 'pg' => 1 ) ) ) . '">' . esc_html( $label ) . '</a> ';
            }
            echo '</div>';
        }
    }

    private static function trend( $s, $rows ) {
        $map = array();
        foreach ( $rows as $r ) { $map[ $r['day'] ] = $r; }
        $series = array();
        $day = self::date( $s['from'] );
        for ( $i = 0; $i < $s['days']; $i++ ) {
            $date = $day->format( 'Y-m-d' );
            $series[] = $map[ $date ] ?? array( 'day' => $date, 'orders' => 0, 'collected' => 0, 'net' => 0 );
            $day = $day->modify( '+1 day' );
        }
        $values = array_map( 'floatval', array_column( $series, 'collected' ) );
        $peak = max( $values );
        $min = min( 0, min( $values ) ); $max = max( 0, $peak );
        $span = max( 1, $max - $min );
        $points = array();
        foreach ( $values as $i => $value ) {
            $x = 25 + 950 * $i / max( 1, count( $values ) - 1 );
            $y = 180 - 155 * ( $value - $min ) / $span;
            $points[] = sprintf( '%.2F,%.2F', $x, $y );
        }
        $zero = 180 - 155 * ( 0 - $min ) / $span;
        echo '<div class="jmi-panel"><h2>每日訂單建立趨勢</h2><p>以含稅、含運費的訂單金額扣除已記錄退款；退款回歸原訂單日期。</p>';
        echo '<svg viewBox="0 0 1000 205" role="img" aria-label="每日訂單淨額趨勢" class="jmi-chart"><title>每日訂單淨額；下方提供完整數據表</title>';
        echo '<line x1="25" x2="975" y1="' . esc_attr( sprintf( '%.2F', $zero ) ) . '" y2="' . esc_attr( sprintf( '%.2F', $zero ) ) . '" stroke="#cbd5e1"/>';
        echo '<polyline points="' . esc_attr( implode( ' ', $points ) ) . '" fill="none" stroke="#2563eb" stroke-width="3"/>';
        foreach ( $points as $point ) {
            list( $x, $y ) = explode( ',', $point );
            echo '<circle cx="' . esc_attr( $x ) . '" cy="' . esc_attr( $y ) . '" r="2" fill="#2563eb"/>';
        }
        echo '</svg><div class="jmi-axis"><span>' . esc_html( $s['from'] ) . '</span><span>區間最高 ' . self::money( $peak ) . '</span><span>' . esc_html( $s['to'] ) . '</span></div>';
        echo '<details><summary>查看每日數據</summary>';
        self::table_start( array( '日期', '有效訂單', '訂單淨額（含稅／運費）', '淨銷售額（不含稅／運費）' ) );
        foreach ( $series as $r ) {
            echo '<tr><td>' . esc_html( $r['day'] ) . '</td><td>' . self::num( $r['orders'] ) . '</td><td>' . self::money( $r['collected'] ) . '</td><td>' . self::money( $r['net'] ) . '</td></tr>';
        }
        self::table_end( false, 4 );
        echo '</details></div>';
        return $series;
    }

    private static function view_stock( $s, $d ) {
        $c = $d['summary'];
        if ( 'yes' !== get_option('woocommerce_manage_stock','yes') ) { echo '<div class="notice notice-warning inline"><p>全站庫存數量管理目前關閉。以下顯示商品已儲存的設定值，不代表系統正在自動扣庫存；低庫存提醒已停用。</p></div>'; }
        echo '<div class="jmi-cards">';
        self::card( '庫存單位', self::num( $c['total'] ), '依目前商品名稱／SKU 搜尋結果' );
        self::card( '低庫存', self::num( $c['low'] ), '有數量管理，達低庫存門檻且仍為有貨' );
        self::card( '缺貨', self::num( $c['outofstock'] ), '依 WooCommerce 庫存狀態' );
        self::card( '待補貨／預購', self::num( $c['onbackorder'] ), '依 WooCommerce 庫存狀態' );
        self::card( '未管理數量', self::num( $c['unmanaged'] ), '仍可能有有貨／缺貨狀態' );
        echo '</div><div class="jmi-panel"><h2>目前庫存報表</h2><p>庫存是本次查詢時的商品紀錄，不受日期篩選影響。共用主商品庫存的變體只顯示主商品；自行管理庫存的變體各列一筆。僅列已發佈／私人商品；數量不扣除結帳暫時預留，亦非庫存成本。</p>';
        $headers = array( '庫存管理商品', 'SKU', '類型', '庫存數量', '低庫存門檻', '庫存狀態' );
        $csv = array( array( '商品 ID', '商品', 'SKU', '類型', '庫存數量', '低庫存門檻', '庫存狀態' ) );
        self::table_start( $headers );
        $labels = array( 'instock' => '有貨', 'outofstock' => '缺貨', 'onbackorder' => '待補貨／預購' );
        foreach ( $d['rows'] as $r ) {
            $managed = 'yes' === $r['managed'];
            $type = 'product_variation' === $r['post_type'] ? '獨立變體' : '主商品／一般商品';
            $status = $r['is_low'] ? '低庫存' : ( $labels[$r['stock_status']] ?? $r['stock_status'] );
            echo '<tr><td>' . self::product_name( $r ) . '<small class="jmi-sku">#' . absint( $r['product_id'] ) . '</small></td><td>' . esc_html( $r['sku'] ?: '—' ) . '</td><td>' . esc_html( $type ) . '</td><td>' . ( $managed && null !== $r['stock_quantity'] ? self::num( $r['stock_quantity'], 2 ) : '未管理數量' ) . '</td><td>' . ( $managed ? self::num( $r['threshold'], 2 ) : '—' ) . '</td><td><span class="jmi-badge">' . esc_html( $status ) . '</span></td></tr>';
            $csv[] = array( (int) $r['product_id'], $r['name'], $r['sku'], $type, $managed && null !== $r['stock_quantity'] ? (float) $r['stock_quantity'] : '未管理數量', $managed ? (float) $r['threshold'] : '—', $status );
        }
        self::table_end( ! $d['rows'], count( $headers ) );
        self::pager( $s, $d ); echo '</div>';
        echo '<p>真實庫齡需要入庫批次／剩餘批次資料，本版不以商品建立日期推估。可到商品銷售頁查看期間零銷售，再核對庫存。</p>';
        return $csv;
    }

    private static function styles() {
        // 僅本面板輸出；不使用 CDN、圖表套件或全後台 enqueue。
        echo '<style>
        .jmi-wrap{max-width:1500px;color:#1e293b}.jmi-wrap h1{font-weight:700}.jmi-sub{color:#64748b}
        .jmi-filter{display:flex;gap:14px;align-items:end;flex-wrap:wrap;background:#fff;padding:20px;margin:20px 0 10px;border:1px solid #dbe3ec;border-radius:10px}
        .jmi-filter label{display:flex;flex-direction:column;gap:6px;font-weight:600}.jmi-filter input,.jmi-filter select{min-height:36px}.jmi-quick{margin-bottom:18px}
        .jmi-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:14px;margin:18px 0}
        .jmi-card{background:#fff;border:1px solid #dbe3ec;border-radius:12px;padding:20px}.jmi-card>span{display:block;color:#475569;font-size:13px}
        .jmi-card strong{display:block;font-size:25px;line-height:1.3;margin:12px 0;overflow-wrap:anywhere}.jmi-card small{display:block;color:#64748b;line-height:1.6}
        .jmi-panel{padding:20px;background:#fff;border:1px solid #dbe3ec;border-radius:12px;margin:18px 0}.jmi-panel h2{margin-top:0}.jmi-panel p{color:#475569}
        .jmi-columns{display:grid;grid-template-columns:1.25fr 1fr;gap:18px}.jmi-columns .jmi-panel{margin-top:0}.jmi-scroll{overflow:auto;max-height:620px}
        .jmi-wrap th{white-space:nowrap;font-weight:600}.jmi-wrap td{vertical-align:middle}.jmi-sku{display:block;margin-top:5px;color:#64748b}.jmi-pager{display:flex;justify-content:space-between;align-items:center;margin-top:16px;gap:10px}
        .jmi-chart{width:100%;height:auto;max-height:260px}.jmi-axis{display:flex;justify-content:space-between;gap:10px;color:#64748b}.jmi-wrap details{margin-top:20px}.jmi-wrap summary{cursor:pointer;font-weight:600}
        .jmi-toolbar{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin:18px 0}.jmi-toolbar form{margin:0}.jmi-badge{background:#eef2ff;padding:4px 9px;border-radius:20px;white-space:nowrap}
        .jmi-help{line-height:1.8}.jmi-export{display:none}.jmi-wrap.jmi-js .jmi-export{display:inline-block}
        @media(max-width:900px){.jmi-columns{grid-template-columns:1fr}.jmi-card strong{font-size:22px}.jmi-axis{font-size:11px}}
        /* 營運總覽 2.2 - 純 CSS / SVG、不載入外部 CDN */
        .jmi-wrap{max-width:none;margin:0 16px 0 0;padding:12px 10px 34px 0;box-sizing:border-box;font-family:-apple-system,BlinkMacSystemFont,"Noto Sans TC","Microsoft JhengHei",sans-serif;color:#202127}
        .jmi-wrap h1{font-size:23px;letter-spacing:-.4px}.jmi-wrap .jmi-sub{margin-top:7px;font-size:12px;color:#999ba9}
        .jmi-wrap .jmi-filter{background:#fff;border:1px solid #e7e7ea;border-radius:13px;margin:16px 0 10px;padding:13px 16px;box-shadow:0 1px 2px #00000003}
        .jmi-wrap .jmi-filter label{color:#686a78;font-size:12px}.jmi-wrap .jmi-filter input,.jmi-wrap .jmi-filter select{border:1px solid #e3e4e9;border-radius:7px;background:#fff;color:#2b2b34}
        .jmi-wrap .jmi-quick .button{border-color:#e5e5ec;border-radius:8px;font-size:12px;background:#fff;color:#666978}
        .jmi-pulse-overview{background:#f8f9fb;border-radius:16px;padding:14px 0 6px;margin-top:10px}
        .jmi-pulse-context{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:0 3px 12px;color:#9698a5;font-size:12px}
        .jmi-pulse-period{color:#626573;font-weight:650}.jmi-live-dot,.jmi-mini-live i{height:7px;width:7px;border-radius:50%;background:#22ae5c;display:inline-block;flex:none}
        .jmi-pulse-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}
        .jmi-kpi,.jmi-pulse-panel{background:#fff;border:1px solid #e3e4e9;border-radius:13px;box-shadow:0 1px 2px rgba(20,20,30,.025)}
        .jmi-kpi{min-height:135px;padding:18px 20px 15px;box-sizing:border-box;transition:box-shadow .2s ease,transform .2s ease}
        .jmi-kpi:hover,.jmi-pulse-panel:hover{box-shadow:0 7px 24px rgba(24,25,55,.045)}
        .jmi-kpi-top{display:flex;justify-content:space-between;align-items:start;gap:12px;color:#5d606b;font-size:13px}
        .jmi-spark{width:66px;height:30px;flex-shrink:0;margin-top:-4px}
        .jmi-kpi-number{font-weight:750;font-size:28px;letter-spacing:-.8px;line-height:1.2;margin:8px 0 9px;color:#222229;white-space:nowrap}
        .jmi-kpi-number .woocommerce-Price-currencySymbol{font-size:13px;font-weight:450;color:#9395a3;margin-right:2px}
        .jmi-kpi-number .jmi-unit{font-size:14px;color:#9799a6;font-weight:500;letter-spacing:0}
        .jmi-kpi-foot{font-size:12px;line-height:1.5;white-space:nowrap}.jmi-muted{color:#a2a3af}.jmi-delta{font-weight:700}.jmi-delta.is-up{color:#16874a}.jmi-delta.is-down{color:#d44343}
        .jmi-kpi-description{font-size:11px;color:#a4a4b1;margin-top:5px;line-height:1.25}
        .jmi-pulse-two{display:grid;grid-template-columns:minmax(0,1.9fr) minmax(310px,.95fr);gap:15px;margin-top:15px;align-items:stretch}
        .jmi-pulse-panel{min-width:0;overflow:hidden}.jmi-pulse-panel-head{padding:19px 20px 12px;display:flex;justify-content:space-between;align-items:start;gap:12px}
        .jmi-pulse-panel h2{font-size:15px;font-weight:750;letter-spacing:-.2px;margin:0;color:#282831;line-height:1.5}
        .jmi-pulse-panel-head p{margin:3px 0 0;font-size:12px;color:#999ba8;line-height:1.7}.jmi-pulse-panel-head .woocommerce-Price-amount{color:#8d90a2}
        .jmi-range-tabs{display:flex;align-items:center;gap:0;white-space:nowrap;border:1px solid #e6e7ed;border-radius:8px;padding:3px;background:#fafafb}
        .jmi-range-tabs a{display:inline-block;border-radius:6px;padding:5px 11px;line-height:1.25;text-decoration:none;color:#9799a7;font-size:12px}
        .jmi-range-tabs a.active{background:#fff;color:#2f3039;box-shadow:0 0 0 1px #dfe0e6,0 1px 2px #0001}
        .jmi-mini-live{display:flex;gap:6px;align-items:center;font-size:11px;color:#8c8e99;margin-right:10px;padding-right:10px;border-right:1px solid #e2e3e8}
        .jmi-pulse-chart{padding:5px 12px 13px 5px}.jmi-pulse-chart svg{width:100%;height:auto;display:block;min-height:240px;max-height:340px}
        .jmi-daily-table{margin:0!important;border-top:1px solid #eee;padding:12px 18px 16px;font-size:12px;color:#8d8f9e}
        .jmi-daily-table summary{font-size:12px!important;font-weight:500!important}.jmi-daily-table .jmi-scroll{margin-top:10px;max-height:340px}
        .jmi-categories{display:flex;flex-direction:column}.jmi-categories .jmi-pulse-panel-head{padding-bottom:17px}
        .jmi-category-item{padding:0 20px;margin:0 0 17px}.jmi-category-meta{display:flex;justify-content:space-between;align-items:start;gap:6px;margin-bottom:10px;font-size:12px;color:#636573}
        .jmi-category-name{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:47%}.jmi-category-amount{font-weight:650;color:#353641;white-space:nowrap}.jmi-category-amount small{font-size:11px;font-weight:400;color:#a0a2af;margin-left:5px}
        .jmi-category-track{height:6px;border-radius:20px;background:#f1f1f4;overflow:hidden}.jmi-category-track span{display:block;height:100%;border-radius:20px;background:#6250eb;transition:width .35s ease}
        .jmi-category-caption{border-top:1px solid #eeeff2;padding:13px 20px;color:#a2a4b1;font-size:11px;line-height:1.5;margin-top:auto}
        .jmi-empty{text-align:center;color:#999baa;padding:28px 12px!important}
        .jmi-trend-point{cursor:pointer}.jmi-trend-point:hover,.jmi-trend-point:focus{opacity:1;stroke-width:4;outline:none}.jmi-trend-detail{margin:4px 12px 0;padding:10px 14px;border-radius:8px;background:#f4f2ff;color:#4033b7;font-weight:600}.jmi-recent{margin-top:15px}.jmi-recent .jmi-pulse-panel-head{align-items:center;padding:19px 20px 17px}
        .jmi-order-actions{display:flex;gap:8px;align-items:center}.jmi-pulse-btn.button{background:#fff;border:1px solid #e3e4ea;border-radius:8px;color:#555862;min-height:31px;display:inline-flex;align-items:center;justify-content:center;font-size:12px;padding:0 12px;text-decoration:none}
        .jmi-pulse-btn.button:hover{background:#f6f6fa;color:#38374b}.jmi-order-filterbox{display:flex;gap:16px;padding:13px 20px;border-top:1px solid #edeef1;flex-wrap:wrap}
        .jmi-order-filterbox[hidden]{display:none}.jmi-order-filterbox label{font-size:12px;color:#787987;display:flex;gap:7px;align-items:center}.jmi-order-filterbox input,.jmi-order-filterbox select{font-size:12px;border-color:#e1e2e9;border-radius:7px;min-height:34px}
        .jmi-order-scroll{overflow-x:auto}.jmi-order-table{border:0;border-collapse:collapse;width:100%;font-size:12px;color:#444550;text-align:left}
        .jmi-order-table thead{border-top:1px solid #e8e9ed;background:#fafafa}.jmi-order-table th{padding:12px 17px!important;font-weight:500!important;color:#a1a2ae!important;font-size:11px;border-bottom:1px solid #e7e8ec;white-space:nowrap}
        .jmi-order-table td{padding:12px 17px;border-bottom:1px solid #ecedf0;font-size:12px;line-height:1.5;white-space:nowrap}
        .jmi-order-table tbody tr:hover{background:#fbfbfe}.jmi-order-link{color:#27272f;text-decoration:none;font-weight:650}.jmi-order-link:hover{color:#5546fa}.jmi-order-product{max-width:310px;overflow:hidden;text-overflow:ellipsis;color:#646673}.jmi-order-muted{color:#999aa7}.jmi-order-money{font-weight:650;color:#282930}.jmi-right{text-align:right}.jmi-order-table tr[hidden]{display:none}
        .jmi-status-badge{font-weight:650;padding:4px 10px;border-radius:30px;display:inline-flex;white-space:nowrap;font-size:11px}.jmi-status-processing{background:#fff1e4;color:#bf641f}.jmi-status-completed{background:#e6f6ec;color:#168449}.jmi-status-cancelled{background:#fbe9e9;color:#c63b3b}.jmi-status-pending{background:#f0f0f2;color:#636471}
        .jmi-recent-bottom{display:flex;justify-content:space-between;align-items:center;padding:15px 18px;color:#a4a5b0;font-size:11px}.jmi-recent-bottom a{text-decoration:none;color:#636576;font-weight:600}
        .jmi-pulse-extra{border:1px solid #e3e4e9;border-radius:12px;background:#fff;padding:18px 20px;margin:15px 0 0!important}.jmi-pulse-extra>summary{display:flex;align-items:center;gap:14px}.jmi-pulse-extra>summary span{font-size:12px;font-weight:400;color:#a3a4af}.jmi-pulse-extra .jmi-cards{grid-template-columns:repeat(4,minmax(170px,1fr));gap:11px}
        .jmi-pulse-extra .jmi-card{padding:16px}.jmi-pulse-extra .jmi-card strong{font-size:19px}.jmi-note{font-size:12px;color:#999aa7;line-height:1.8}
        @media(max-width:1450px){.jmi-pulse-two{grid-template-columns:minmax(0,1.6fr) minmax(290px,1fr)}.jmi-kpi{padding:17px 15px}.jmi-kpi-number{font-size:25px}.jmi-mini-live{display:none}}
        @media(max-width:1160px){.jmi-pulse-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.jmi-pulse-two{grid-template-columns:1fr}.jmi-pulse-extra .jmi-cards{grid-template-columns:repeat(2,minmax(0,1fr))}.jmi-categories{min-height:300px}}
        @media(max-width:782px){.jmi-wrap{margin-right:10px;padding-right:0}.jmi-pulse-panel-head{padding:16px}.jmi-pulse-chart svg{min-height:180px}.jmi-order-table{min-width:780px}.jmi-wrap .jmi-filter{padding:13px}}
        @media(max-width:620px){.jmi-pulse-kpis{gap:8px}.jmi-kpi{min-height:130px;padding:12px}.jmi-kpi-number{font-size:21px}.jmi-kpi-top{font-size:12px}.jmi-kpi-foot{font-size:11px;white-space:normal}.jmi-kpi-description{font-size:10px}.jmi-spark{width:42px}.jmi-pulse-panel-head{flex-wrap:wrap}.jmi-trend-panel .jmi-pulse-panel-head{flex-direction:column;align-items:stretch}.jmi-range-tabs{align-self:flex-start}.jmi-pulse-extra .jmi-cards{grid-template-columns:1fr}.jmi-pulse-extra>summary{display:block}.jmi-order-actions{width:100%}.jmi-pulse-context{font-size:11px}}
        @media(prefers-reduced-motion:reduce){.jmi-kpi,.jmi-pulse-panel,.jmi-category-track span{transition:none}}
        </style>';
    }

    public static function cost_field() {
        echo '<div class="options_group">';
        woocommerce_wp_text_input( array( 'id'=>'_jmi_unit_cost', 'label'=>'營運分析：單位成本', 'type'=>'number',
            'desc_tip'=>true, 'description'=>'每件商品的成本，以商店基礎幣別、不含可扣抵稅額填寫。留空表示未知，0 表示成本確實為零。',
            'custom_attributes'=>array( 'min'=>'0','step'=>'0.000001' ) ) );
        echo '</div>';
    }

    private static function cost_input( $value ) {
        if ( ! is_scalar( $value ) ) { throw new RuntimeException( '成本格式不正確。' ); }
        $value = trim( wp_unslash( (string) $value ) );
        if ( $value === '' ) { return ''; }
        if ( ! preg_match( '/^\d{1,12}(?:\.\d{1,6})?$/', $value ) || ! is_finite( (float) $value ) ) { throw new RuntimeException( '成本／數值須為非負數，最多 6 位小數。' ); }
        return $value;
    }

    public static function save_cost( $product ) {
        if ( ! current_user_can( 'edit_post', $product->get_id() ) || ! isset( $_POST['_jmi_unit_cost'] ) ) { return; }
        try {
            $v = self::cost_input( $_POST['_jmi_unit_cost'] );
            if ( $v === '' ) { $product->delete_meta_data( '_jmi_unit_cost' ); } else { $product->update_meta_data( '_jmi_unit_cost', $v ); }
        } catch ( Throwable $e ) { if ( class_exists( 'WC_Admin_Meta_Boxes' ) ) { WC_Admin_Meta_Boxes::add_error( $e->getMessage() ); } }
        // 沿用 WooCommerce 商品儲存權限／nonce 及原本的 save，不另做全店更新。
    }

    public static function variation_cost_field( $loop, $data, $variation ) {
        woocommerce_wp_text_input( array( 'id'=>'jmi_variation_cost_' . $loop, 'name'=>'jmi_variation_cost[' . $loop . ']',
            'value'=>get_post_meta( $variation->ID, '_jmi_unit_cost', true ), 'label'=>'營運分析：單位成本（留空繼承主商品）',
            'type'=>'number','wrapper_class'=>'form-row form-row-full', 'custom_attributes'=>array( 'min'=>'0','step'=>'0.000001' ) ) );
    }

    public static function save_variation_cost( $product, $loop ) {
        if ( ! current_user_can( 'edit_post', $product->get_parent_id() ) || ! isset( $_POST['jmi_variation_cost'][$loop] ) ) { return; }
        try {
            $v = self::cost_input( $_POST['jmi_variation_cost'][$loop] );
            if ( $v === '' ) { $product->delete_meta_data( '_jmi_unit_cost' ); } else { $product->update_meta_data( '_jmi_unit_cost', $v ); }
        } catch ( Throwable $e ) { if ( class_exists( 'WC_Admin_Meta_Boxes' ) ) { WC_Admin_Meta_Boxes::add_error( $e->getMessage() ); } }
    }

    public static function snapshot_cost( $item, $cart_key, $values, $order ) {
        if ( $item->meta_exists( '_jmi_unit_cost_snapshot' ) ) { return; }
        $product = $values['data'] ?? null;
        if ( ! $product instanceof WC_Product ) { return; }
        $cost = $product->get_meta( '_jmi_unit_cost', true );
        if ( $cost === '' && $product->get_parent_id() ) { $cost = get_post_meta( $product->get_parent_id(), '_jmi_unit_cost', true ); }
        if ( $cost !== '' && is_numeric( $cost ) && (float) $cost >= 0 ) {
            $item->add_meta_data( '_jmi_unit_cost_snapshot', (string) $cost, true );
        }
        // 傳統與 Store API 結帳都使用既有 item save；不執行統計查詢。
    }

    public static function snapshot_saved_item( $item_id, $item ) {
        // 包含後台建立／編輯的訂單商品；更新金額時保留既有快照。
        if ( ! $item instanceof WC_Order_Item_Product || $item->meta_exists( '_jmi_unit_cost_snapshot' ) ) { return; }
        if ( $item->get_meta( '_refunded_item_id', true ) ) { return; }
        $product = $item->get_product();
        if ( ! $product ) { return; }
        $cost = $product->get_meta( '_jmi_unit_cost', true );
        if ( $cost === '' && $product->get_parent_id() ) { $cost = get_post_meta( $product->get_parent_id(), '_jmi_unit_cost', true ); }
        if ( $cost !== '' && is_numeric( $cost ) && (float) $cost >= 0 ) {
            $item->update_meta_data( '_jmi_unit_cost_snapshot', (string) $cost ); $item->save_meta_data();
        }
    }

    public static function save_context() {
        if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' || ! isset( $_POST['jmi_save_context'] ) ) { return; }
        if ( ! current_user_can( self::CAP ) ) { wp_die( '沒有存取權限。' ); }
        check_admin_referer( 'jmi_wci_save_context' );
        try {
            $s = self::state(); $entry = array();
            foreach ( self::field_definitions() as $key => $label ) {
                $v = self::cost_input( $_POST['context'][$key] ?? '' ); $entry[$key] = $v === '' ? null : (float) $v;
            }
            foreach ( array( 'sessions','converted_sessions','new_customers','cart_sessions','abandoned_sessions' ) as $k ) {
                if ( $entry[$k] !== null && floor( $entry[$k] ) !== $entry[$k] ) { throw new RuntimeException( '人數／工作階段數須為整數。' ); }
            }
            if ( $entry['contribution_margin'] !== null && $entry['contribution_margin'] > 100 ) { throw new RuntimeException( '貢獻毛利率不得超過 100%。' ); }
            foreach ( array( array('converted_sessions','sessions'),array('abandoned_sessions','cart_sessions') ) as $pair ) {
                if ( $entry[$pair[0]] !== null && $entry[$pair[1]] !== null && $entry[$pair[0]] > $entry[$pair[1]] ) { throw new RuntimeException( '購買／放棄工作階段數不得大於對應的總工作階段數。' ); }
            }
            $entry['expenses_complete'] = ! empty( $_POST['expenses_complete'] );
            $entry['updated'] = wp_date( 'Y-m-d H:i:s' ); $entry['source'] = isset( $_POST['context_source'] ) && is_string( $_POST['context_source'] ) ? sanitize_text_field( wp_unslash( $_POST['context_source'] ) ) : '';
            $entry['source'] = function_exists('mb_substr') ? mb_substr($entry['source'],0,200) : substr($entry['source'],0,200);
            $all = get_option( 'jmi_wci_period_contexts_v2', array() ); $key = $s['from'].':'.$s['to'].':'.get_woocommerce_currency();
            unset( $all[$key] ); $all[$key] = $entry;
            while ( count( $all ) > 48 ) { array_shift( $all ); }
            update_option( 'jmi_wci_period_contexts_v2', $all, false );
            wp_safe_redirect( self::url( $s, array( 'tab'=>'finance' ) ) ); exit;
        } catch ( Throwable $e ) { wp_die( esc_html( $e->getMessage() ) ); }
    }

    private static function overview_series( $s, $daily ) {
        $map = array();
        foreach ( $daily as $r ) { $map[ $r['day'] ] = $r; }
        $rows = array(); $day = self::date( $s['from'] );
        for ( $i=0; $i<$s['days']; $i++ ) {
            $key=$day->format('Y-m-d');
            $rows[]=$map[$key]??array('day'=>$key,'orders'=>0,'collected'=>0,'net'=>0);
            $day=$day->modify('+1 day');
        }
        return $rows;
    }

    private static function overview_spark( $series, $field ) {
        $values=array_map(function($row)use($field){
            if($field==='average'){return $row['orders']>0?(float)$row['collected']/(float)$row['orders']:0;}
            return (float)$row[$field];
        },$series);
        if(count($values)<2){return '';}
        $high=max($values);$low=min($values);$span=max(1,$high-$low);
        $pts=array();foreach($values as $idx=>$v){$pts[]=sprintf('%.1F,%.1F',2+74*$idx/(count($values)-1),25-19*($v-$low)/$span);}
        $last=explode(',',end($pts));
        return '<svg class="jmi-spark" viewBox="0 0 80 30" aria-hidden="true"><polyline fill="none" stroke="#c4c6d0" stroke-width="1.6" points="'.esc_attr(implode(' ',$pts)).'"/><circle cx="'.esc_attr($last[0]).'" cy="'.esc_attr($last[1]).'" r="2" fill="#898da2"/></svg>';
    }

    private static function overview_change( $current, $previous, $reverse=false, $points=false ) {
        if ( $points ) {
            $delta=(float)$current-(float)$previous;
            $value=($delta>=0?'+':'').number_format_i18n($delta,1).' pt';
        } else {
            if ( (float)$previous<=0 ) {return '<span class="jmi-muted">前期無可比較資料</span>';}
            $delta=((float)$current-(float)$previous)/(float)$previous*100;
            $value=($delta>=0?'+':'').number_format_i18n($delta,1).'%';
        }
        $better=$reverse ? ($delta<=0) : ($delta>=0);
        return '<span class="jmi-delta '.($better?'is-up':'is-down').'">'.($delta>=0?'↗ ':'↘ ').esc_html($value).'</span> <span class="jmi-muted">較前期</span>';
    }

    private static function overview_kpi( $title, $value_html, $comparison_html, $spark='', $helper='' ) {
        echo '<div class="jmi-kpi"><div class="jmi-kpi-top"><span>'.esc_html($title).'</span>'.$spark.'</div>';
        echo '<div class="jmi-kpi-number">'.wp_kses_post($value_html).'</div><div class="jmi-kpi-foot">'.wp_kses_post($comparison_html).'</div>';
        if($helper!==''){echo '<div class="jmi-kpi-description">'.esc_html($helper).'</div>';}
        echo '</div>';
    }

    private static function overview_chart( $s, $series, $c ) {
        $nums=array_map(function($r){return (float)$r['collected'];},$series);
        $max=max(1.0,max($nums));$min=min(0.0,min($nums));
        $top=$max>0 ? $max*1.16 : 1.0; $bottom=$min<0 ? $min*1.10 : 0.0;
        $span=max(1.0,$top-$bottom);
        $plot_l=66;$plot_r=970;$plot_t=30;$plot_b=270;
        $xy=array();foreach($nums as $idx=>$v){
            $x=$plot_l+($plot_r-$plot_l)*$idx/max(1,count($nums)-1);
            $y=$plot_b-($plot_b-$plot_t)*($v-$bottom)/$span;
            $xy[]=array($x,$y);
        }
        $coordinates=array_map(function($p){return sprintf('%.2F,%.2F',$p[0],$p[1]);},$xy);
        $line=implode(' ',$coordinates);
        $first=$xy[0];$last=$xy[count($xy)-1];
        $area=sprintf('%.2F,%.2F ', $first[0],$plot_b).$line.sprintf(' %.2F,%.2F',$last[0],$plot_b);
        echo '<div class="jmi-pulse-chart" role="region" aria-label="營運營收折線圖">';
        echo '<svg viewBox="0 0 990 310" role="img" aria-label="選定期間每日訂單淨額折線圖，以下可展開每日數據表">';
        echo '<defs><linearGradient id="jmi-trend-fill" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#6355ed" stop-opacity=".19"/><stop offset="100%" stop-color="#6355ed" stop-opacity=".005"/></linearGradient></defs>';
        for($i=0;$i<=4;$i++){
            $v=$bottom+($top-$bottom)*(4-$i)/4;$y=$plot_t+($plot_b-$plot_t)*$i/4;
            $mark=abs($v)>=10000?number_format_i18n($v/10000,1).'萬':(abs($v)>=1000?number_format_i18n($v/1000,1).'千':number_format_i18n($v,0));
            echo '<line x1="66" x2="970" y1="'.esc_attr($y).'" y2="'.esc_attr($y).'" stroke="#e8e9ee" stroke-width="1"/>';
            echo '<text x="53" y="'.esc_attr($y+4).'" text-anchor="end" font-size="12" fill="#989bab">'.esc_html($mark).'</text>';
        }
        echo '<polygon points="'.esc_attr($area).'" fill="url(#jmi-trend-fill)"/>';
        echo '<polyline points="'.esc_attr($line).'" fill="none" stroke="#5546fa" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>';
        foreach($xy as $idx=>$p){
            $v=$nums[$idx];$date=$series[$idx]['day'];
            $tooltip=$date.'｜'.strip_tags(self::money($v));
            $highlight=$idx===count($xy)-1||$v===$max;
            echo '<circle class="jmi-trend-point" tabindex="0" data-day="'.esc_attr($date).'" data-total="'.esc_attr(strip_tags(self::money($v))).'" cx="'.esc_attr(sprintf('%.2F',$p[0])).'" cy="'.esc_attr(sprintf('%.2F',$p[1])).'" r="'.($highlight?'4.5':'3').'" fill="#ffffff" stroke="#5546fa" stroke-width="2" opacity="'.($highlight?'1':'0.55').'"><title>'.esc_html($tooltip).'</title></circle>';
        }
        $marks=array_unique(array(0,(int)floor((count($series)-1)/4),(int)floor((count($series)-1)/2),(int)floor(3*(count($series)-1)/4),count($series)-1));
        foreach($marks as $idx){$x=$xy[$idx][0];$date=self::date($series[$idx]['day']);echo '<text x="'.esc_attr($x).'" y="302" text-anchor="middle" font-size="12" fill="#989bab">'.esc_html($date->format('n/j')).'</text>';}
        echo '</svg><div class="jmi-trend-detail" aria-live="polite">將滑鼠移到折線上的日期點，查看當日營收</div></div>';
    }

    private static function overview_categories( $data ) {
        $rows=$data['categories']??array();
        echo '<section class="jmi-pulse-panel jmi-categories"><header class="jmi-pulse-panel-head"><div><h2>分類營收</h2><p>所選期間 · 商品品項淨銷售額</p></div></header>';
        if(!$rows){echo '<div class="jmi-empty">此期間尚無可呈現的商品分類銷售資料</div>';}else{
            $positive=0;foreach($rows as $r){$positive+=max(0,(float)$r['revenue']);}
            $display=array_slice($rows,0,5);
            if(count($rows)>5){$rest=0;foreach(array_slice($rows,5) as $r){$rest+=(float)$r['revenue'];}$display[]=array('name'=>'其他分類','revenue'=>$rest);}
            foreach($display as $idx=>$r){
                $amount=(float)$r['revenue'];$share=$positive>0?max(0,$amount)/$positive*100:0;
                echo '<div class="jmi-category-item"><div class="jmi-category-meta"><span class="jmi-category-name">'.esc_html($r['name']).'</span><span class="jmi-category-amount">'.self::money($amount).' <small>'.self::num($share,1).'%</small></span></div><div class="jmi-category-track"><span style="width:'.esc_attr(min(100,max(0,$share))).'%;opacity:'.esc_attr(max(.38,1-$idx*.13)).'"></span></div></div>';
            }
        }
        echo '<div class="jmi-category-caption">單一商品若設定多個分類，依分類 ID 最小者歸類；使用商品目前的分類，不是歷史分類快照。分類合計可能與含運費的訂單淨額不同。</div></section>';
    }

    private static function orders_page( $page ) {
        $limit=15;
        $result=wc_get_orders(array('type'=>'shop_order','status'=>array_keys(wc_get_order_statuses()),'limit'=>$limit,'page'=>$page,'paginate'=>true,'orderby'=>'date','order'=>'DESC','return'=>'objects'));
        $orders=isset($result->orders)?$result->orders:array();
        ob_start();
        foreach($orders as $order){
            if(!$order instanceof WC_Order){continue;}
            $names=array();$items=$order->get_items('line_item');foreach($items as $item){$names[]=$item->get_name().((float)$item->get_quantity()>1?' × '.$item->get_quantity():'');if(count($names)>=2){break;}}
            $full=trim((string)$order->get_formatted_billing_full_name());$name=$full!==''?$full:($order->get_billing_email()?:'訪客');
            $product=implode('、',$names);if(count($items)>2){$product.=' 等';}if($product===''){$product='—';}
            $status='wc-'.$order->get_status();$label=wc_get_order_status_name($status);
            $group=$status==='wc-completed'?'completed':(in_array($status,array('wc-processing','wc-on-hold'),true)?'processing':(in_array($status,array('wc-cancelled','wc-failed','wc-refunded'),true)?'cancelled':'pending'));
            $time=$order->get_date_created();$formatted=$time?$time->date_i18n('n/j H:i'):'—';
            $money=wc_price($order->get_total(),array('currency'=>$order->get_currency()));
            $search=trim($order->get_order_number().' '.$name.' '.$product.' '.$order->get_payment_method_title());
            echo '<tr data-status="'.esc_attr($status).'" data-search="'.esc_attr(function_exists('mb_strtolower')?mb_strtolower($search):strtolower($search)).'">';
            echo '<td><a class="jmi-order-link" href="'.esc_url($order->get_edit_order_url()).'">#'.esc_html($order->get_order_number()).'</a></td>';
            echo '<td>'.esc_html($name).'</td><td class="jmi-order-product" title="'.esc_attr($product).'">'.esc_html($product).'</td>';
            echo '<td class="jmi-order-muted">'.esc_html($order->get_payment_method_title()?:'—').'</td>';
            echo '<td class="jmi-right jmi-order-money">'.wp_kses_post($money).'</td>';
            echo '<td><span class="jmi-status-badge jmi-status-'.esc_attr($group).'">'.esc_html($label).'</span></td>';
            echo '<td class="jmi-right jmi-order-muted">'.esc_html($formatted).'</td></tr>';
        }
        if(!$orders){echo '<tr><td colspan="7" class="jmi-empty">此頁沒有訂單</td></tr>';}
        return array('html'=>ob_get_clean(),'page'=>$page,'pages'=>max(1,(int)$result->max_num_pages),'total'=>(int)$result->total);
    }

    private static function overview_orders() {
        $href=self::hpos()?admin_url('admin.php?page=wc-orders'):admin_url('edit.php?post_type=shop_order');
        echo '<section class="jmi-pulse-panel jmi-recent"><header class="jmi-pulse-panel-head"><div><h2 id="jmi-order-heading">所有訂單</h2><p id="jmi-order-description">全站正式訂單 · 每頁 15 筆 · 不受上方日期範圍限制</p></div><div class="jmi-order-actions"><button type="button" class="button jmi-pulse-btn" id="jmi-filter-orders" aria-expanded="false">☷ 篩選</button></div></header>';
        echo '<div class="jmi-order-filterbox" hidden><label>搜尋本頁 <input type="search" id="jmi-order-q" placeholder="訂單編號、顧客或商品" autocomplete="off"></label><label>訂單狀態 <select id="jmi-order-status"><option value="">全部狀態</option>';
        foreach(wc_get_order_statuses() as $key=>$label){echo '<option value="'.esc_attr($key).'">'.esc_html($label).'</option>';}
        echo '</select></label></div><div class="jmi-order-scroll"><table class="jmi-order-table"><thead><tr><th>訂單編號</th><th>客戶</th><th>商品</th><th>付款方式</th><th class="jmi-right">金額</th><th>狀態</th><th class="jmi-right">時間</th></tr></thead><tbody id="jmi-order-rows">';
        try{$initial=self::orders_page(1);echo $initial['html'];$pages=$initial['pages'];$total=$initial['total'];}catch(Throwable $e){echo '<tr><td colspan="7" class="jmi-empty">訂單暫時無法讀取，請由訂單管理檢視</td></tr>';$pages=1;$total=0;}
        echo '</tbody></table></div><div class="jmi-recent-bottom"><span id="jmi-order-count">共 '.self::num($total).' 筆 · 第 1 / '.self::num($pages).' 頁</span><div class="jmi-order-actions"><button type="button" class="button" id="jmi-order-prev" disabled>上一頁</button><button type="button" class="button" id="jmi-order-next" '.($pages<=1?'disabled':'').'>下一頁</button><a href="'.esc_url($href).'">前往訂單管理 <span aria-hidden="true">→</span></a></div></div><p id="jmi-order-error" role="alert" hidden></p></section>';
    }

    private static function view_overview( $s, $d ) {
        $c=$d['current'];$p=$d['previous'];$all=0;$cancelled=0;
        foreach($d['statuses'] as $row){$all+=(int)$row['orders'];if($row['status']==='wc-cancelled'){$cancelled+=(int)$row['orders'];}}
        $current_avg=$c['orders']>0?$c['collected']/$c['orders']:0;
        $previous_avg=$p['orders']>0?$p['collected']/$p['orders']:0;
        $current_refund=self::rate($c['refunded_orders'],$c['orders']);
        $previous_refund=self::rate($p['refunded_orders'],$p['orders']);
        $series=self::overview_series($s,$d['daily']);
        echo '<div class="jmi-pulse-overview"><div class="jmi-pulse-context"><span class="jmi-live-dot"></span><span>已讀取的即時報表</span><span class="jmi-pulse-period">'.esc_html($s['from'].' — '.$s['to']).'</span><span>與 '.esc_html($d['previous_from'].' — '.$d['previous_to']).' 比較</span></div>';
        echo '<div class="jmi-pulse-kpis">';
        self::overview_kpi('期間營收',self::money($c['collected']),self::overview_change($c['collected'],$p['collected']),self::overview_spark($series,'collected'),'有效訂單淨額，已扣退款');
        self::overview_kpi('期間訂單',self::num($c['orders']).'<span class="jmi-unit"> 筆</span>',self::overview_change($c['orders'],$p['orders']),self::overview_spark($series,'orders'),'目前有效的正式訂單');
        self::overview_kpi('平均客單價',self::money($current_avg),self::overview_change($current_avg,$previous_avg),self::overview_spark($series,'average'),'訂單淨額 ÷ 有效訂單數');
        self::overview_kpi('訂單退款率',self::num($current_refund,2).'<span class="jmi-unit">%</span>',self::overview_change($current_refund,$previous_refund,true,true),'','含部分退款，非結帳轉換率');
        echo '</div><div class="jmi-pulse-two">';
        echo '<section class="jmi-pulse-panel jmi-trend-panel"><header class="jmi-pulse-panel-head"><div><h2>營收趨勢</h2><p>所選 '.self::num($s['days']).' 天總計 '.self::money($c['collected']).'　·　日均 '.self::money($c['collected']/max(1,$s['days'])).'</p></div>';
        $today=self::date(wp_date('Y-m-d'));
        echo '<div class="jmi-range-tabs" role="group" aria-label="營收日期快捷篩選"><span class="jmi-mini-live"><i></i> 已更新</span>';
        foreach(array(7=>'7 天',30=>'30 天',90=>'90 天') as $days=>$label){
            $from=$today->modify('-'.($days-1).' days')->format('Y-m-d');
            $active=$s['from']===$from&&$s['to']===$today->format('Y-m-d');
            echo '<a class="'.($active?'active':'').'" href="'.esc_url(self::url($s,array('from'=>$from,'to'=>$today->format('Y-m-d'),'pg'=>1))).'">'.esc_html($label).'</a>';
        }
        echo '</div></header>';self::overview_chart($s,$series,$c);
        echo '<details class="jmi-daily-table"><summary>查看每日營收數據</summary>';
        self::table_start(array('日期','有效訂單','訂單淨額（含稅／運費）','淨銷售額（不含稅／運費）'));
        foreach($series as $r){echo '<tr><td>'.esc_html($r['day']).'</td><td>'.self::num($r['orders']).'</td><td>'.self::money($r['collected']).'</td><td>'.self::money($r['net']).'</td></tr>';}
        self::table_end(false,4);echo '</details></section>';
        self::overview_categories($d);echo '</div>';
        self::overview_orders();
        if($c['missing_refunds']>0){echo '<div class="notice notice-warning inline"><p>有 '.self::num($c['missing_refunds']).' 張已退款狀態訂單沒有退款金額紀錄，未自行推估，請核對訂單</p></div>';}
        echo '<details class="jmi-pulse-extra"><summary><strong>更多營運指標</strong><span>淨銷售額、退款、會員、取消率與訂單狀態</span></summary><div class="jmi-cards">';
        self::card('淨銷售額（不含稅／運費）',self::money($c['net']),self::comparison($c['net'],$p['net']));
        self::card('退款金額',self::money($c['refunds']),'此期間原單已記錄退款');
        self::card('金額退款率',self::num(self::rate($c['refunds'],$c['gross']),1).'％','退款額 ÷ 退款前原單總額');
        self::card('取消率',self::num(self::rate($cancelled,$all),1).'％','目前取消原單 ÷ 全部正式原單');
        self::card('本期消費顧客',self::num($c['customers']),'優先依帳單 Email 歸戶');
        self::card('多次購買顧客占比',self::num(self::rate($d['repeat']['repeat_customers'],$d['repeat']['customers']),1).'％','本期有兩筆以上有效原單');
        self::card('會員營收占比',self::num(self::rate($c['member_revenue'],$c['collected']),1).'％','下單時具有會員 ID');
        self::card('原單折扣金額',self::money($c['discounts']),'退款前商品折扣');
        echo '</div><h3>期間訂單狀態</h3>';self::table_start(array('狀態','原單數'));
        foreach($d['statuses'] as $r){echo '<tr><td>'.esc_html(wc_get_order_status_name($r['status'])).'</td><td>'.self::num($r['orders']).'</td></tr>';}
        self::table_end(!$d['statuses'],2);
        echo '<p class="jmi-note">轉換率需要有效的訪客工作階段與購買工作階段數，請到「獲利與行銷」補錄後檢視；此處不從訂單數推估。退款依原單建立日歸期，所有訂單列表不受報表日期限制。</p></details></div>';
        return array_merge(array(array('日期','有效訂單','訂單淨額（含稅／運費）','淨銷售額（不含稅／運費）')),array_map(function($r){return array($r['day'],(int)$r['orders'],(float)$r['collected'],(float)$r['net']);},$series));
    }

    private static function view_customers( $s, $d ) {
        $headers=array('顧客','訂單類型','有效訂單','淨消費額','客單價（退款後）','退款金額','退款率','本期最近訂單','新舊客／累積消費');$csv=array($headers);
        echo '<div class="jmi-panel"><h2>顧客消費排行</h2><p>以帳單 Email 歸戶，包含會員與訪客；Email 改變視為不同身份。累積消費按顧客點擊讀取，避免開啟列表就查詢整店歷史。累積淨消費是歷史觀察值，非預測 LTV、利潤或留存率。</p>';
        self::table_start($headers);
        foreach($d['rows'] as $r){
            $name=trim($r['first_name'].' '.$r['last_name'])?:'未提供姓名';$type=$r['user_id']?'包含會員訂單':'訪客訂單';$avg=$r['orders']>0?$r['collected']/$r['orders']:0;$rate=self::rate($r['refunded_orders'],$r['orders']);
            echo '<tr><td>'.esc_html($name).'<small class="jmi-sku">'.esc_html($r['email']?:'無 Email').'</small></td><td>'.esc_html($type).'</td><td>'.self::num($r['orders']).'</td><td>'.self::money($r['collected']).'</td><td>'.self::money($avg).'</td><td>'.self::money($r['refunds']).'</td><td>'.self::num($rate,1).'％</td><td>'.esc_html($r['last_order']).'</td><td><button class="button jmi-lifetime" type="button" data-email="'.esc_attr($r['email']).'" data-user="'.absint($r['user_id']).'">讀取顧客歷史</button><small class="jmi-lifetime-result"></small></td></tr>';
            $csv[]=array($name.' / '.$r['email'],$type,(int)$r['orders'],(float)$r['collected'],(float)$avg,(float)$r['refunds'],round($rate,2).'%', $r['last_order'],'按需另查');
        }
        self::table_end(!$d['rows'],count($headers));self::pager($s,$d);echo '</div>';return $csv;
    }

    private static function view_abc( $s, $d ) {
        echo '<div class="jmi-cards">';foreach(array('A','B','C') as $group){self::card(array('A'=>'主力商品','B'=>'次要商品','C'=>'低貢獻商品')[$group],self::num($d['counts'][$group]),$group==='A'?'優先保障供貨':($group==='B'?'持續觀察與優化':'檢查低貢獻與庫存占用'));}echo '</div>';
        echo '<div class="jmi-panel"><h2>商品貢獻排行</h2><p>依商品帶來的營收排列，幫助決定補貨與促銷優先順序，不是商品品質評分。主力：累計貢獻約前 80% 營收，優先確保供貨；次要：接續至約 95%，持續觀察；低貢獻：其餘商品，可檢查庫存與促銷。跨過門檻的商品留在較高類別；零／負營收歸低貢獻，排除於占比分母。</p>';
        $headers=array('商品','營收分級','營收占比','累計占比','淨商品銷售額','原銷售量','淨銷售量','本期最近銷售','成本狀態');$csv=array($headers);self::table_start($headers);
        foreach($d['rows'] as $r){$basis=$r['missing_cost']>0?'缺成本':($r['estimated_cost']>0?'含目前成本估算':'訂單成本快照');
            echo '<tr><td>'.self::product_name($r).'</td><td><strong>'.esc_html(array('A'=>'主力','B'=>'次要','C'=>'低貢獻')[$r['abc']]).'</strong></td><td>'.self::num($r['share'],1).'％</td><td>'.self::num($r['cumulative'],1).'％</td><td>'.self::money($r['revenue']).'</td><td>'.self::num($r['sold_qty'],2).'</td><td>'.self::num($r['qty'],2).'</td><td>'.esc_html($r['last_sale']).'</td><td>'.esc_html($basis).'</td></tr>';
            $csv[]=array($r['name'],array('A'=>'主力','B'=>'次要','C'=>'低貢獻')[$r['abc']],round($r['share'],2).'%',round($r['cumulative'],2).'%',(float)$r['revenue'],(float)$r['sold_qty'],(float)$r['qty'],$r['last_sale'],$basis);}
        self::table_end(!$d['rows'],count($headers));self::pager($s,$d);echo '</div>';return $csv;
    }

    private static function metric_value( $v, $type='money' ) {
        if($v===null){return '資料不足';}if($type==='percent'){return self::num($v*100,1).'％';}if($type==='ratio'){return self::num($v,2).' 倍';}if($type==='days'){return self::num($v,1).' 天';}return self::money($v);
    }

    private static function view_finance( $s, $d ) {
        $context=$d['context'];$estimated=$d['estimated']?'估算':'';
        echo '<div class="jmi-cards">';
        self::card('商品銷貨成本',self::metric_value($d['cogs']),isset($context['cogs'])?'按期間手動填寫':($d['estimated']?'含目前商品成本回推':'依訂單成本快照'));
        self::card($estimated.'毛利',self::metric_value($d['gross_profit']),'淨銷售額（含訂單費用，不含稅／運費）－銷貨成本');
        self::card($estimated.'毛利率',self::metric_value($d['gross_margin'],'percent'),'毛利 ÷ 淨銷售額；毛利不代表可支用現金');
        self::card($estimated.'營運淨利（稅前）',self::metric_value($d['net_profit']),'毛利＋淨運費收入－已確認完整的營運費用');
        self::card('電商轉換率',self::metric_value($d['cvr'],'percent'),'完成購買工作階段 ÷ 同來源期間的全部工作階段');
        self::card('CAC',self::metric_value($d['cac']),'行銷支出 ÷ 同歸因口徑的新客人數');
        self::card('廣告 ROAS',self::metric_value($d['roas'],'ratio'),'廣告歸因營收 ÷ 行銷支出；不是全店營收除以廣告費');
        self::card('行銷 ROI',self::metric_value($d['roi'],'percent'),'（廣告歸因貢獻毛利－行銷支出）÷ 行銷支出');
        self::card('購物車放棄率',self::metric_value($d['cart_abandonment'],'percent'),'完成觀察期的未購買購物車工作階段占比');
        self::card('保本 ROAS 估算',self::metric_value($d['break_even_roas'],'ratio'),'1 ÷ 填寫的廣告費前貢獻毛利率；未涵蓋固定成本');
        self::card('期間庫存周轉率',self::metric_value($d['turnover'],'ratio'),'本期銷貨成本 ÷ 本期平均庫存成本');
        self::card('庫存周轉天數',self::metric_value($d['inventory_days'],'days'),'期間天數 ÷ 期間庫存周轉率；非庫齡');echo '</div>';
        echo '<div class="jmi-panel"><h2>資料完整度</h2><p>缺成本品項紀錄：'.self::num($d['cost']['missing_cost']).'；使用目前商品成本回推的品項紀錄：'.self::num($d['cost']['estimated_cost']).'。成本可在「商品銷售」點擊商品前往編輯，填寫單位成本。</p><p>成本沒有填齊時，毛利顯示資料不足；費用沒有逐項確認且勾選完整時，淨利顯示資料不足。退貨的實物是否可再次銷售、庫存損耗與不可扣抵費用，請反映在本期實際成本／其他費用中。</p></div>';
        echo '<div class="jmi-panel"><h2>補錄此期間的財務／行銷資料</h2><p>'.esc_html($s['from'].' ～ '.$s['to'].' · '.get_woocommerce_currency()).'。金額採相同幣別；空白＝未知，0＝確認沒有。資料只屬於這個精確日期區間，不會自動分攤或重複加總至其他期間。行銷數字由你提供，非本面板自動追蹤。</p><form method="post" action="'.esc_url(self::url($s)).'">';wp_nonce_field('jmi_wci_save_context');
        $groups=array(
            '成本與營運費用'=>array('cogs','ad_spend','shipping_cost','platform_fees','payment_fees','payroll','overhead'),
            '流量與購物車'=>array('sessions','converted_sessions','cart_sessions','abandoned_sessions'),
            '廣告歸因與新客'=>array('new_customers','attributed_revenue','attributed_contribution','contribution_margin'),
            '庫存周轉'=>array('average_inventory_cost')
        );$definitions=self::field_definitions();
        foreach($groups as $title=>$keys){echo '<fieldset class="jmi-fieldset"><legend>'.esc_html($title).'</legend><div class="jmi-context-grid">';foreach($keys as $key){$step=in_array($key,array('sessions','converted_sessions','cart_sessions','abandoned_sessions','new_customers'),true)?'1':'0.000001';echo '<label>'.esc_html($definitions[$key]).'<input type="number" min="0" step="'.$step.'" name="context['.esc_attr($key).']" value="'.esc_attr($context[$key]??'').'"></label>';}echo '</div></fieldset>';}
        echo '<p><label>資料來源／歸因口徑 <input type="text" maxlength="200" name="context_source" value="'.esc_attr($context['source']??'').'" placeholder="例如：GA4、廣告平台、本期會計費用"></label></p><p><label><input type="checkbox" name="expenses_complete" value="1" '.checked(!empty($context['expenses_complete']),true,false).'>已填寫所有列出的費用，沒有的項目明確填 0；這是完整的本期營運費用。</label></p><button type="submit" class="button button-primary" name="jmi_save_context" value="1">儲存本期補錄資料</button></form>';
        if(!empty($context['updated'])){echo '<p>補錄更新：'.esc_html($context['updated']).' · '.esc_html($context['source']??'').'</p>';}echo '</div>';
        $csv=array(array('指標','數值','幣別／單位','成本是否含估算'));foreach(array('cogs'=>'銷貨成本','gross_profit'=>'毛利','gross_margin'=>'毛利率','net_profit'=>'營運淨利（稅前）','cvr'=>'轉換率','cac'=>'CAC','roas'=>'ROAS','roi'=>'ROI','cart_abandonment'=>'購物車放棄率','break_even_roas'=>'保本ROAS','turnover'=>'庫存周轉率','inventory_days'=>'周轉天數') as $key=>$label){$csv[]=array($label,$d[$key]===null?'資料不足':(float)$d[$key],in_array($key,array('cogs','gross_profit','net_profit','cac'),true)?get_woocommerce_currency():'比率為小數；天數為天',$d['estimated']?'是':'否');}return $csv;
    }

    public static function render() {
        if(!current_user_can(self::CAP)){wp_die('沒有存取權限。');}
        if(!class_exists('WooCommerce')){echo '<div class="notice notice-error"><p>請先啟用 WooCommerce。</p></div>';return;}
        self::styles();echo '<style>.jmi-wrap{overflow-wrap:anywhere}.jmi-filter input[type=search]{max-width:260px}.jmi-context-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px}.jmi-context-grid label{display:flex;flex-direction:column;gap:6px}.jmi-context-grid input{width:100%;min-height:38px;box-sizing:border-box}.jmi-fieldset{border:1px solid #dbe3ec;border-radius:10px;margin:20px 0;padding:18px}.jmi-fieldset legend{padding:0 8px;font-weight:700;color:#334155}.jmi-lifetime-result{display:block;max-width:270px;line-height:1.7}.jmi-wrap .nav-tab-wrapper{display:flex;flex-wrap:wrap;gap:8px;border:0;margin:18px 0;padding:0}.jmi-wrap .nav-tab{float:none;margin:0;background:#fff;border:1px solid #dbe3ec;border-radius:8px;padding:10px 16px;color:#334155;text-decoration:none}.jmi-wrap .nav-tab-active{background:#2563eb;border-color:#2563eb;color:#fff}.jmi-load-status{padding:14px;background:#fff;border-left:4px solid #2563eb}.jmi-load-status[data-error="true"]{border-color:#b91c1c}.jmi-wrap .jmi-toolbar{justify-content:flex-start}@media(max-width:480px){.jmi-context-grid{grid-template-columns:1fr}.jmi-fieldset{padding:12px}.jmi-wrap .nav-tab{padding:8px 12px}}</style>';
        echo '<div class="wrap jmi-wrap" id="jmi-insights"><h1>WUMETAX · 電商營運分析</h1><p class="jmi-sub">直接讀取 WooCommerce 訂單、退款、商品與庫存 · '.esc_html(get_woocommerce_currency()).' · 商店時區 '.esc_html(wp_timezone_string()).'</p>';
        try{$s=self::state();echo '<nav class="nav-tab-wrapper" aria-label="報表分類">';foreach(array('overview'=>'營運總覽','products'=>'商品銷售','customers'=>'顧客消費','stock'=>'庫存報表','abc'=>'商品貢獻排行','finance'=>'獲利與行銷') as $tab=>$label){echo '<a class="nav-tab'.($s['tab']===$tab?' nav-tab-active':'').'" href="'.esc_url(self::url($s,array('tab'=>$tab,'pg'=>1,'q'=>''))).'">'.esc_html($label).'</a>';}echo '</nav>';self::filters($s);
            echo '<p>按訂單 ID 分頁讀取（每批最多 40 張），數千張訂單也無須一次載入。完成後才顯示完整統計；系統不會在背景自動刷新。</p><div class="jmi-toolbar"><button type="button" class="button button-primary" id="jmi-reload">重新讀取即時資料</button><button type="button" class="button jmi-export" disabled>匯出目前報表 CSV</button><span id="jmi-read-at"></span></div><p class="jmi-load-status" role="status" aria-live="polite">準備讀取報表…</p><div id="jmi-report" aria-busy="true"></div><noscript><p>請開啟 JavaScript；本面板採按需非同步讀取，避免統計拖慢後台頁面開啟。</p></noscript>';
            echo '<details class="jmi-help"><summary>統計定義與資料來源</summary><ul>';foreach(array(
                '資料來自商店目前已儲存的訂單、退款與商品庫存。每次重新讀取都重新計算。',
                '只合計目前商店基礎幣別的訂單，其他幣別不混加；並非多幣別換算工具。',
                '日期為訂單建立日期與商店時區。有效狀態為已付款狀態加已退款。取消率分母包含待付款、失敗等正式原單，排除垃圾桶與結帳草稿。',
                '退款依原單歸戶，包括該原單之後才發生的退款。退款狀態不等於實際退款金額；沒有退款紀錄時不自行推估。',
                '訂單淨額包含稅／運費；淨銷售額不含稅／運費，含訂單費用。這些是訂單記錄，不表示銀行或金流撥款。未分項退款會影響稅與運費拆分。',
                '商品銷售與退貨來自實際訂單品項；未分配至商品的退款不會自行拆分。退款率不是物流退貨率。',
                '顧客以帳單 Email 優先歸戶，無 Email 用會員 ID，兩者皆無則每單獨立；相同 Email 視為同一身份，Email 改變則分開。',
                '累積淨消費是歷史觀察值，並非預測終身價值。新客表示第一張目前有效的原單位於本期；舊客表示在本期開始前已有目前有效的原單。',
                '訂單報表透過 ID 游標分頁，每批最多 40 張原單，不會一次載入數千張訂單；各批分開請求，統計完整完成後才顯示。',
                '毛利不等於現金；淨利為依補錄費用計算的稅前營運估算。CVR／ROAS／CAC／ROI／放棄率的時間、幣別、歸因與工作階段定義須一致。',
                '庫存周轉率需要本期平均庫存成本。商品建立日、最近售出日與最後補貨日都不等於現存批次庫齡；未提供入庫批次資料時不顯示真實庫齡。',
                'CSV 匯出已載入的報表：總覽每日數據、獲利指標或目前頁明細；不為匯出重新查詢。',
            ) as $note){echo '<li>'.esc_html($note).'</li>';}echo '</ul></details>';
            self::live_script($s);
        }catch(Throwable $e){echo '<div class="notice notice-error"><p>'.esc_html($e->getMessage()).'</p></div><a class="button" href="'.esc_url(admin_url('admin.php?page='.self::SLUG)).'">回到預設報表</a>';}
        echo '</div>';
    }

    private static function live_script( $s ) {
        $config=array('state'=>$s,'url'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('jmi_wci_live'));
        echo '<script type="application/json" id="jmi-live-config">'.wp_json_encode($config,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).'</script>';
        echo <<<'JMI_JS'
<script>(function(){
    'use strict';
    const root=document.getElementById('jmi-insights');if(!root)return;
    root.classList.add('jmi-js');const config=JSON.parse(document.getElementById('jmi-live-config').textContent);
    const panel=root.querySelector('#jmi-report'),status=root.querySelector('.jmi-load-status'),reload=root.querySelector('#jmi-reload'),exportButton=root.querySelector('.jmi-export'),readAt=root.querySelector('#jmi-read-at');
    let busy=false,csv=null,successfulRead='';
    async function request(fields){
        const body=new URLSearchParams(Object.assign({action:'jmi_wci_live',nonce:config.nonce},fields));
        const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),35000);let response;try{response=await fetch(config.url,{method:'POST',credentials:'same-origin',body,cache:'no-store',signal:controller.signal});}catch(e){throw new Error(e.name==='AbortError'?'本批讀取超時，請稍後重新讀取。':'連線未完成，請稍後重新讀取。');}finally{clearTimeout(timer);}
        let result;try{result=await response.json();}catch(e){throw new Error('伺服器沒有回傳有效資料，請重新登入或檢查網站錯誤紀錄。');}
        if(!result.success)throw new Error(result.data&&result.data.message?result.data.message:'請求失敗；請重新登入確認權限。');return result.data;
    }
    async function load(){
        if(busy)return;busy=true;reload.disabled=true;exportButton.disabled=true;panel.setAttribute('aria-busy','true');status.dataset.error='false';status.textContent='正在讀取即時資料…';
        try{let data=await request(Object.assign({},config.state,{operation:'report_start'}));while(data.pending){if(document.hidden){status.textContent='頁面在背景，已暫停整理；回到此頁繼續。';await new Promise(resolve=>document.addEventListener('visibilitychange',resolve,{once:true}));continue;}status.textContent='分頁讀取中：已處理 '+data.done+' 張訂單'+(data.phase==='previous'?'（正在整理前期比較）':'')+'，每批最多 40 張，完成後顯示完整統計…';await new Promise(resolve=>setTimeout(resolve,180));if(document.hidden)continue;data=await request({operation:'report_step',token:data.token,seq:data.seq});}panel.innerHTML=data.html;orderPage=1;orderPages=1;csv=data.csv;successfulRead=data.read_at;readAt.textContent='本次讀取：'+successfulRead+' · '+data.currency;status.textContent='已讀取本次資料。新訂單或退款後可按「重新讀取」，不會自動輪詢。';exportButton.disabled=false;}
        catch(e){status.dataset.error='true';status.textContent=e.message+(successfulRead?' 以下保留 '+successfulRead+' 的上次結果，更新未完成。':'');exportButton.disabled=!csv;}
        finally{busy=false;reload.disabled=false;panel.setAttribute('aria-busy','false');}
    }
    function filterRecent(){
        const q=(panel.querySelector('#jmi-order-q')?.value||'').trim().toLocaleLowerCase();
        const selected=panel.querySelector('#jmi-order-status')?.value||'';
        let shown=0;panel.querySelectorAll('.jmi-order-table tbody tr[data-status]').forEach(function(row){
            const ok=(!selected||row.dataset.status===selected)&&(!q||(row.dataset.search||'').toLocaleLowerCase().includes(q));
            row.hidden=!ok;if(ok)shown++;
        });
        let empty=panel.querySelector('.jmi-search-empty');if(!empty){empty=document.createElement('tr');empty.className='jmi-search-empty';empty.innerHTML='<td colspan="7" class="jmi-empty">本頁沒有符合條件的訂單</td>';panel.querySelector('#jmi-order-rows')?.appendChild(empty);}empty.hidden=shown>0;
    }
    panel.addEventListener('click',function(e){const b=e.target.closest('#jmi-filter-orders');if(!b)return;
        const filters=panel.querySelector('.jmi-order-filterbox');if(!filters)return;
        const next=filters.hidden;filters.hidden=!next;b.setAttribute('aria-expanded',next?'true':'false');
        if(next)panel.querySelector('#jmi-order-q')?.focus();
    });
    panel.addEventListener('input',function(e){if(e.target.matches('#jmi-order-q'))filterRecent();});
    panel.addEventListener('change',function(e){if(e.target.matches('#jmi-order-status'))filterRecent();});
    let orderPage=1,orderPages=1;
    async function showOrderPage(page){
        const rows=panel.querySelector('#jmi-order-rows'),err=panel.querySelector('#jmi-order-error');if(!rows)return;
        const prev=panel.querySelector('#jmi-order-prev'),next=panel.querySelector('#jmi-order-next');prev.disabled=true;next.disabled=true;err.hidden=true;rows.setAttribute('aria-busy','true');
        try{const data=await request({operation:'orders_page',order_page:page});rows.innerHTML=data.html;orderPage=data.page;orderPages=data.pages;
            panel.querySelector('#jmi-order-count').textContent='共 '+Number(data.total).toLocaleString()+' 筆 · 第 '+orderPage+' / '+orderPages+' 頁';
            const q=panel.querySelector('#jmi-order-q');if(q)q.value='';const state=panel.querySelector('#jmi-order-status');if(state)state.value='';
            prev.disabled=orderPage<=1;next.disabled=orderPage>=orderPages;
        }catch(e){err.textContent=e.message;err.hidden=false;prev.disabled=orderPage<=1;next.disabled=orderPage>=orderPages;}
        finally{rows.removeAttribute('aria-busy');}
    }
    panel.addEventListener('click',function(e){if(e.target.closest('#jmi-order-prev')){showOrderPage(orderPage-1);}else if(e.target.closest('#jmi-order-next')){showOrderPage(orderPage+1);}});
    function trendDetail(e){const point=e.target.closest('.jmi-trend-point');if(!point)return;const detail=point.closest('.jmi-pulse-chart').querySelector('.jmi-trend-detail');detail.textContent=point.dataset.day+' · 當日營收 '+point.dataset.total;}
    panel.addEventListener('pointerover',trendDetail);panel.addEventListener('focusin',trendDetail);
    reload.addEventListener('click',load);
    exportButton.addEventListener('click',function(){if(!csv)return;function cell(v){let s=v==null?'':String(v);if(typeof v!=='number'&&(/^[\s\u0000-\u001f]*[=+@-]/.test(s)||/^[\t\r\n]/.test(s)))s="'"+s;return '"'+s.replace(/"/g,'""')+'"';}
        const output='\ufeff'+csv.map(r=>r.map(cell).join(',')).join('\r\n'),url=URL.createObjectURL(new Blob([output],{type:'text/csv;charset=utf-8;'})),a=document.createElement('a');a.href=url;a.download='wc-live-'+config.state.tab+'-'+config.state.from+'-'+config.state.to+'-page-'+config.state.pg+'.csv';document.body.appendChild(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(url),1000);
    });
    panel.addEventListener('click',async function(event){const button=event.target.closest('.jmi-lifetime');if(!button||busy)return;busy=true;button.disabled=true;reload.disabled=true;const result=button.parentNode.querySelector('.jmi-lifetime-result');result.textContent='讀取顧客歷史中…';
        try{const data=await request({operation:'lifetime',email:button.dataset.email,user_id:button.dataset.user});const h=data.lifetime;const kind=!h.first_order?'無有效訂單':(h.first_order.slice(0,10)<config.state.from?'本期舊客':'本期新客');result.textContent=kind+' · 累積淨消費 '+data.currency+' '+Number(h.collected).toLocaleString(undefined,{maximumFractionDigits:2})+' · '+h.orders+' 張有效原單 · 第一單 '+(h.first_order||'—')+' · 讀取 '+data.read_at;}
        catch(e){result.textContent=e.message;}finally{busy=false;button.disabled=false;reload.disabled=false;}
    });
    load();
})();</script>
JMI_JS;
    }

}
JMI_WC_Admin_Insights::boot();
}
