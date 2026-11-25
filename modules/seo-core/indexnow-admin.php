<?php
defined( 'ABSPATH' ) || exit;

trait WUTM_SEO_IndexNow_Admin {
    private static function indexnow_admin_url( array $args = [] ): string {
        return add_query_arg( $args + ['page'=>'wu-seo-tools','seo_tab'=>'indexnow'], admin_url( 'admin.php' ) );
    }

    public static function indexnow_admin(): void {
        if ( ! current_user_can( 'manage_options' ) || ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) wp_die( '無法執行此操作。', '', ['response'=>403] );
        check_admin_referer( 'wu_seo_indexnow' );
        $input = static function ( $key ) {
            return is_string( $_POST[$key] ?? null ) ? trim( wp_unslash( $_POST[$key] ) ) : '';
        };
        $operation = $input( 'operation' );
        $settings = self::indexnow_settings();
        $message = '';
        if ( $operation === 'save_key' || $operation === 'generate' ) {
            $next = $settings;
            $next['key'] = $operation === 'generate' ? str_replace( '-', '', wp_generate_uuid4() ) : $input( 'key' );
            $next['location'] = $operation === 'generate' ? '' : $input( 'location' );
            if ( ! self::indexnow_location( $next ) ) {
                $message = '請輸入有效金鑰及同來源的 .txt 驗證檔網址。';
            } elseif ( self::indexnow_proof_id( $settings ) !== self::indexnow_proof_id( $next ) ) {
                $next['enabled'] = false;
                update_option( self::INDEXNOW_OPTION, $next, false );
                delete_option( self::INDEXNOW_PROOF );
                wp_clear_scheduled_hook( self::INDEXNOW_CRON );
                $message = '已儲存金鑰。請驗證公開檔案，再開啟自動通知。';
            } else {
                $message = '目前金鑰與驗證檔網址已儲存。';
            }
        } elseif ( $operation === 'verify' ) {
            $proof = self::indexnow_verify( $settings ) + ['proof'=>self::indexnow_proof_id( $settings )];
            update_option( self::INDEXNOW_PROOF, $proof, false );
            $message = $proof['detail'];
        } elseif ( $operation === 'automation' ) {
            $types = array_intersect( array_filter( (array) ($_POST['types'] ?? []), 'is_string' ), array_keys( self::indexnow_types() ) );
            $requested = $input( 'enabled' ) === '1';
            if ( $requested && !$types ) {
                $message = '至少選擇一種公開內容類型。';
            } elseif ( !$requested ) {
                $settings['enabled'] = false;
                $settings['types'] = array_values( $types );
                update_option( self::INDEXNOW_OPTION, $settings, false );
                wp_clear_scheduled_hook( self::INDEXNOW_CRON );
                $message = '自動通知已關閉，既有佇列與紀錄已保留。';
            } else {
                $proof = self::indexnow_verify( $settings ) + ['proof'=>self::indexnow_proof_id( $settings )];
                update_option( self::INDEXNOW_PROOF, $proof, false );
                if ( empty( $proof['ok'] ) ) $message = $proof['detail'];
                elseif ( ! self::indexnow_install() ) $message = '無法建立 IndexNow 佇列資料表。請檢查 WordPress 資料庫權限。';
                elseif ( ! self::indexnow_schedule() ) {
                    $message = 'WordPress 無法安排背景工作，請檢查 WP-Cron。';
                } else {
                    $settings['enabled'] = true;
                    $settings['types'] = array_values( $types );
                    update_option( self::INDEXNOW_OPTION, $settings, false );
                    $message = '自動通知與發布健檢已啟用。';
                }
            }
        } elseif ( $operation === 'test' ) {
            $url = $input( 'url' );
            $post = self::indexnow_in_scope( $url, $settings ) ? get_post( url_to_postid( $url ) ) : null;
            $entry = $post ? self::indexnow_snapshot( $post, $settings ) : [];
            if ( ! $settings['enabled'] || get_option( 'wu_seo_indexnow_schema' ) !== '1' ) $message = '請先啟用自動通知。';
            elseif ( ! self::indexnow_has_proof( $settings ) ) $message = '請先完成金鑰驗證。';
            elseif ( !$entry || $entry['url'] !== $url || $entry['excluded'] ) $message = '請輸入已選類型的正式公開網址，且須在金鑰範圍內並允許索引。';
            elseif ( get_transient( 'wu_seo_indexnow_test_limit' ) ) $message = '請等待一分鐘再測試。';
            else {
                $row = self::indexnow_find( hash( 'sha256', $url ) );
                if ( $row && in_array( $row['state'], ['queued','retry'], true ) ) $message = '此網址已在佇列，會合併送出。';
                elseif ( self::indexnow_enqueue( $url, $post->ID, $post->post_type, 'test' ) ) {
                    set_transient( 'wu_seo_indexnow_test_limit', 1, 60 );
                    $message = '測試提交已排入背景工作，請稍後查看最新狀態。';
                } else $message = '測試沒有排入佇列，請稍後再試。';
            }
        } elseif ( $operation === 'retry' || $operation === 'health' ) {
            $message = self::indexnow_admin_queue( $operation, $input( 'row' ), $settings );
        } else {
            wp_die( '操作無效。', '', ['response'=>400] );
        }
        set_transient( 'wu_seo_indexnow_notice_' . get_current_user_id(), $message, 60 );
        wp_safe_redirect( self::indexnow_admin_url() );
        exit;
    }

    private static function indexnow_has_proof( array $settings ): bool {
        $proof = (array) get_option( self::INDEXNOW_PROOF, [] );
        return ! empty( $proof['ok'] ) && ( $proof['proof'] ?? '' ) === self::indexnow_proof_id( $settings );
    }

    private static function indexnow_find( string $hash ): ?array {
        global $wpdb;
        $table = self::indexnow_table();
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE url_hash=%s", $hash ), ARRAY_A ) ?: null;
    }

    private static function indexnow_admin_queue( string $operation, string $hash, array $settings ): string {
        if ( ! $settings['enabled'] || get_option( 'wu_seo_indexnow_schema' ) !== '1' ) return '請先啟用自動通知。';
        if ( ! preg_match( '/^[a-f0-9]{64}$/D', $hash ) ) return '網址紀錄不存在。';
        $row = self::indexnow_find( $hash );
        if ( ! $row ) return '網址紀錄不存在。';
        if ( $operation === 'retry' && ! in_array( $row['state'], ['failed','held','retry'], true ) ) return '目前狀態無須重試。';
        if ( $operation === 'retry' && ! self::indexnow_in_scope( $row['url'], $settings ) ) return '此網址超出目前金鑰驗證範圍。';
        $changes = ['revision'=>wp_generate_uuid4(),'health'=>'','health_code'=>'pending','health_due'=>time()];
        if ( $operation === 'retry' ) {
            $changes += ['state'=>'queued','ready'=>(int) in_array( $row['kind'], ['moved','removed','excluded'], true ),
                'attempts'=>0,'due'=>max( time(), (int) get_option( 'wu_seo_indexnow_cooldown', 0 ) )];
        }
        if ( ! self::indexnow_write( $row, $changes ) ) return '此網址正在更新，請重新整理後再試。';
        return $operation === 'retry' ? '已排入重試。' : '已排入發布健檢。';
    }

    private static function indexnow_admin_data(): array {
        global $wpdb;
        $counts = array_fill_keys( ['delivered','queued','retry','accepted','failed','held'], 0 );
        $issues = $rows = [];
        $all = ( $_GET['indexnow_view'] ?? '' ) === 'all';
        if ( get_option( 'wu_seo_indexnow_schema' ) !== '1' ) return [$counts,$issues,$rows,false];
        $table = self::indexnow_table();
        $attention = "state IN ('failed','held','retry','accepted') OR health_code IN ('missing','sitemap_error','http','excluded','old_public')";
        $summary = $wpdb->get_results( "SELECT state,COUNT(*) AS total FROM $table GROUP BY state", ARRAY_A );
        foreach ( (array) $summary as $row ) $counts[$row['state']] = (int) $row['total'];
        $counts['attention'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE state IN ('failed','held') OR
            health_code IN ('missing','sitemap_error','http','excluded','old_public')" );
        $distribution = $wpdb->get_results( "SELECT health_code,COUNT(*) AS total FROM $table WHERE
            health_code IN ('missing','sitemap_error','http','excluded','old_public') GROUP BY health_code", ARRAY_A );
        foreach ( (array) $distribution as $row ) $issues[$row['health_code']] = (int) $row['total'];
        $where = $all ? '' : "WHERE $attention";
        $rows = (array) $wpdb->get_results( "SELECT * FROM $table $where ORDER BY updated DESC LIMIT 50", ARRAY_A );
        return [$counts,$issues,$rows,$all];
    }

    private static function indexnow_chart_days( int $duration ): array {
        $totals = (array) get_option( 'wu_seo_indexnow_days', [] );
        if ( !$totals ) return [];
        $today = new DateTimeImmutable( 'today', wp_timezone() );
        $first = min( array_keys( $totals ) );
        $days = [];
        for ( $i = $duration - 1; $i >= 0; $i-- ) {
            $day = $today->modify( "-$i days" )->format( 'Y-m-d' );
            $days[$day] = $day >= $first ? (int) ( $totals[$day] ?? 0 ) : null;
        }
        return $days;
    }

    private static function indexnow_panel(): void {
        $settings = self::indexnow_settings();
        $verified = self::indexnow_has_proof( $settings );
        $proof = (array) get_option( self::INDEXNOW_PROOF, [] );
        $notice_key = 'wu_seo_indexnow_notice_' . get_current_user_id();
        $notice = get_transient( $notice_key );
        if ( $notice !== false ) delete_transient( $notice_key );
        $location = self::indexnow_location( $settings );
        [$counts,$issues,$rows,$all] = self::indexnow_admin_data();
        $days = ( $_GET['indexnow_days'] ?? '' ) === '30' ? 30 : 7;
        $chart = self::indexnow_chart_days( $days );
        $health_labels = ['missing'=>'找不到，原因待查','sitemap_error'=>'Sitemap 讀取失敗','http'=>'公開 HTTP 異常',
            'excluded'=>'索引設定排除','old_public'=>'舊網址待確認'];
        $kind_labels = ['published'=>'發布','new_url'=>'新網址','updated'=>'更新','removed'=>'下架','moved'=>'舊網址',
            'excluded'=>'索引條件變更','test'=>'測試提交','check'=>'發布健檢'];
        $states = [
            'delivered'=>['已送達','yes-alt','wus-indexed'],'accepted'=>['已接收，等待金鑰驗證','clock',''],
            'queued'=>['待處理','clock',''],'retry'=>['等待重試','update',''],
            'failed'=>['提交失敗','warning','wus-failed'],'held'=>['待確認公開狀態','warning','wus-failed'],
            'observed'=>['只執行健檢','visibility',''],
        ];
        require __DIR__ . '/indexnow-template.php';
    }
}
