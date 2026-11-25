<?php
defined( 'ABSPATH' ) || exit;

trait WUTM_SEO_IndexNow_Jobs {
    const INDEXNOW_ENDPOINT = 'https://api.indexnow.org/indexnow';

    private static function indexnow_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'wu_seo_indexnow';
    }

    private static function indexnow_install(): bool {
        if ( get_option( 'wu_seo_indexnow_schema' ) === '1' ) return true;
        global $wpdb;
        $table = self::indexnow_table();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( "CREATE TABLE $table (
            url_hash char(64) NOT NULL,
            revision char(36) NOT NULL,
            url text NOT NULL,
            post_id bigint unsigned NOT NULL,
            post_type varchar(20) NOT NULL,
            kind varchar(16) NOT NULL,
            state varchar(16) NOT NULL,
            ready tinyint unsigned NOT NULL DEFAULT 0,
            attempts smallint unsigned NOT NULL DEFAULT 0,
            due bigint unsigned NOT NULL DEFAULT 0,
            health_due bigint unsigned NOT NULL DEFAULT 0,
            health_code varchar(16) NOT NULL DEFAULT 'pending',
            updated bigint unsigned NOT NULL,
            response text NOT NULL,
            health longtext NOT NULL,
            PRIMARY KEY  (url_hash),
            KEY dispatch (state,ready,due),
            KEY health_due (health_due),
            KEY updated (updated)
        ) " . $wpdb->get_charset_collate() . ';' );
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) return false;
        update_option( 'wu_seo_indexnow_schema', '1', false );
        return true;
    }

    private static function indexnow_enqueue( string $url, int $id, string $type, string $kind ): bool {
        global $wpdb;
        $table = self::indexnow_table();
        $ready = in_array( $kind, ['moved','removed','excluded'], true ) ? 1 : 0;
        $now = time();
        // One row per URL, newest change wins. Worker writes use its claimed revision.
        return false !== $wpdb->query( $wpdb->prepare(
            "INSERT INTO $table (url_hash, revision, url, post_id, post_type, kind, state, ready, due, health_due, updated, response, health)
            VALUES (%s,%s,%s,%d,%s,%s,%s,%d,%d,%d,%d,'','')
            ON DUPLICATE KEY UPDATE
                kind=IF(kind IN ('published','new_url') AND state IN ('queued','retry') AND VALUES(kind)='updated',kind,VALUES(kind)),
                revision=VALUES(revision), post_id=VALUES(post_id), post_type=VALUES(post_type),
                state=VALUES(state), ready=VALUES(ready), attempts=0, due=VALUES(due), health_due=VALUES(health_due),
                updated=VALUES(updated), health='', health_code='pending'",
            hash( 'sha256', $url ), wp_generate_uuid4(), $url, $id, $type, $kind, $kind === 'check' ? 'observed' : 'queued',
            $ready, $now + 25, $now + 20, $now
        ) );
    }

    private static function indexnow_write( array $row, array $changes ): bool {
        global $wpdb;
        $changes['updated'] = time();
        return (bool) $wpdb->update( self::indexnow_table(), $changes, [
            'url_hash'=>$row['url_hash'], 'revision'=>$row['revision'],
        ] );
    }

    private static function indexnow_due_rows( bool $health, int $limit ): array {
        global $wpdb;
        $table = self::indexnow_table();
        $where = $health ? 'health_due > 0 AND health_due <= %d ORDER BY health_due ASC' :
            "state IN ('queued','retry') AND ready=1 AND due <= %d ORDER BY due ASC";
        return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE $where LIMIT %d", time(), $limit ), ARRAY_A );
    }

    public static function indexnow_worker(): void {
        if ( ! self::indexnow_settings()['enabled'] || get_option( 'wu_seo_indexnow_schema' ) !== '1' ) return;
        $lock = self::dashboard_lock( 'wu_seo_indexnow_lock' );
        if ( ! $lock ) return;
        try {
            $settings = self::indexnow_settings();
            // Submit known changes before potentially slow diagnostics.
            self::indexnow_dispatch( $settings );
            $start = microtime( true );
            foreach ( self::indexnow_due_rows( true, 4 ) as $row ) {
                if ( microtime( true ) - $start > 7 ) break;
                self::indexnow_health( $row, $settings );
            }
            self::indexnow_prune();
        } finally {
            self::dashboard_unlock( 'wu_seo_indexnow_lock', $lock );
        }
    }

    private static function indexnow_dispatch( array $settings ): void {
        if ( (int) get_option( 'wu_seo_indexnow_cooldown', 0 ) > time() ) return;
        $rows = self::indexnow_due_rows( false, 100 );
        if ( ! $rows ) return;
        $proof = (array) get_option( self::INDEXNOW_PROOF, [] );
        $signature = self::indexnow_proof_id( $settings );
        if ( ( $proof['proof'] ?? '' ) !== $signature || time() - (int) ( $proof['time'] ?? 0 ) >= ( empty( $proof['ok'] ) ? 600 : HOUR_IN_SECONDS ) ) {
            $proof = self::indexnow_verify( $settings ) + ['proof'=>$signature];
            update_option( self::INDEXNOW_PROOF, $proof, false );
        }
        if ( empty( $proof['ok'] ) ) return;
        $urls = [];
        foreach ( $rows as $i=>$row ) {
            if ( ! self::indexnow_in_scope( $row['url'], $settings ) ) {
                self::indexnow_write( $row, ['state'=>'failed','response'=>wp_json_encode( ['message'=>'網址超出目前金鑰驗證範圍。'] )] );
                unset( $rows[$i] );
                continue;
            }
            $urls[] = $row['url'];
        }
        if ( ! $urls || self::indexnow_settings() !== $settings ) return;
        $location = self::indexnow_location( $settings );
        $payload = [
            'host'=>strtolower( (string) wp_parse_url( $location, PHP_URL_HOST ) ),
            'key'=>$settings['key'], 'keyLocation'=>$location, 'urlList'=>$urls,
        ];
        $response = wp_safe_remote_post( self::INDEXNOW_ENDPOINT, [
            'timeout'=>10, 'redirection'=>0, 'limit_response_size'=>2048,
            'headers'=>['Content-Type'=>'application/json; charset=utf-8'], 'body'=>wp_json_encode( $payload ),
        ] );
        $code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
        $body = is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_body( $response );
        $body = wp_html_excerpt( sanitize_textarea_field( str_replace( $settings['key'], '[key]', $body ) ), 650 );
        $labels = [
            0=>'網路請求失敗。', 200=>'已送達。', 202=>'已接收，等待金鑰驗證。',
            400=>'要求格式無效。請核對 JSON、金鑰格式與網址編碼。',
            403=>'IndexNow 無法驗證金鑰。請檢查公開驗證檔及 WAF。',
            422=>'網址不屬於驗證主機或路徑，或金鑰格式不符。',
            429=>'提交頻率過高，依伺服器指示延後。',
        ];
        $delay = 60;
        if ( $code === 429 ) {
            $value = wp_remote_retrieve_header( $response, 'retry-after' );
            if ( is_string( $value ) && ( $value = trim( $value ) ) !== '' ) {
                if ( ctype_digit( $value ) ) $delay = min( PHP_INT_MAX - time() - 10, max( 1, (int) $value ) );
                elseif ( false !== ( $retry_time = strtotime( $value ) ) ) $delay = max( 1, $retry_time - time() );
            }
            update_option( 'wu_seo_indexnow_cooldown', time() + $delay, false );
        }
        if ( $code === 403 ) {
            $proof['ok'] = false;
            $proof['detail'] = $labels[$code];
            update_option( self::INDEXNOW_PROOF, $proof, false );
        }
        $now = time();
        $detail = wp_json_encode( ['http'=>$code,'message'=>$labels[$code] ?? 'IndexNow HTTP ' . $code . '。','body'=>$body,'time'=>$now,
            'retry_after'=>$code === 429 ? $delay : 0], JSON_INVALID_UTF8_SUBSTITUTE );
        foreach ( $rows as $row ) {
            $attempt = (int) $row['attempts'] + 1;
            $transient = $code === 0 || $code === 429 || ( $code >= 500 && $code < 600 );
            $state = $code === 200 ? 'delivered' : ( $code === 202 ? 'accepted' : ( $transient && $attempt < 5 ? 'retry' : 'failed' ) );
            $due = $state === 'retry' ? $now + ( $code === 429 ? $delay : 60 * ( 2 ** ( $attempt - 1 ) ) ) : 0;
            self::indexnow_write( $row, ['state'=>$state,'attempts'=>$attempt,'due'=>$due,'response'=>$detail] );
        }
        self::indexnow_record_day( count( $urls ) );
    }

    private static function indexnow_record_day( int $count ): void {
        $days = (array) get_option( 'wu_seo_indexnow_days', [] );
        $date = wp_date( 'Y-m-d' );
        $days[$date] = ( $days[$date] ?? 0 ) + $count;
        ksort( $days );
        update_option( 'wu_seo_indexnow_days', array_slice( $days, -90, null, true ), false );
    }

    private static function indexnow_prune(): void {
        global $wpdb;
        // Preserve pending work. Bound the cleanup query per worker run.
        $table = self::indexnow_table();
        $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE updated < %d AND health_due=0 AND state NOT IN ('queued','retry') LIMIT 100",
            time() - 90 * DAY_IN_SECONDS ) );
    }

    private static function indexnow_health( array $row, array $settings ): void {
        if ( ! self::indexnow_in_scope( $row['url'], $settings ) ) {
            $health = ['code'=>'http','message'=>'網址超出目前的網站或金鑰範圍'];
            $changes = ['health_due'=>0,'health_code'=>'http','health'=>wp_json_encode( $health )];
            if ( in_array( $row['state'], ['queued','retry'], true ) ) {
                $changes['state'] = 'failed';
                $changes['response'] = wp_json_encode( ['message'=>$health['message']] );
            }
            self::indexnow_write( $row, $changes );
            return;
        }
        $health = json_decode( $row['health'], true ) ?: [];
        $url = $row['url'];
        $post = get_post( (int) $row['post_id'] );
        $snapshot = self::indexnow_snapshot( $post, $settings );
        $live = ( $snapshot['url'] ?? '' ) === $url;
        $once = ! empty( $health['recheck'] );
        if ( ! empty( $health['rescan'] ) ) unset( $health['rescan'], $health['page'], $health['sitemap'] );
        if ( empty( $health['page'] ) ) {
            $raw = self::diagnostic_request( $url, 4 );
            $page = WUTM_SEO_Sitemap_Review::evidence( $raw );
            $health['page'] = $page + ['error'=>$raw['error'] ?? '', 'challenge'=>!empty( $raw['challenge'] )];
        }
        $page = $health['page'];
        $excluded = $live ? $snapshot['excluded'] : '';
        if ( ! $excluded && $page['noindex'] ) $excluded = '公開頁面或標頭有 noindex';
        $different = $page['canonical'] && untrailingslashit( $page['canonical'] ) !== untrailingslashit( $url );
        if ( ! $excluded && $different ) $excluded = '公開 canonical 指向其他網址';
        $valid_http = $page['status'] === 200 && !$page['error'] && !$page['challenge'];
        $redirect = $page['final_url'] !== $url;
        $health['code'] = 'pending';
        $health['message'] = '正在檢查公開 Sitemap';
        $next = 0;

        if ( $excluded && $live ) {
            $health['code'] = 'excluded';
            $health['message'] = $excluded;
            $health['sitemap'] = self::publication_sitemap_step( $url, $row['post_type'], $health['sitemap'] ?? [] );
        } elseif ( ! $live && in_array( $row['kind'], ['moved','removed'], true ) ) {
            $health['code'] = in_array( $page['status'], [404,410], true ) || ( $redirect && $valid_http ) ? 'retired' : 'old_public';
            $health['message'] = $health['code'] === 'retired' ? '舊網址已轉址或下架' : '舊網址狀態待確認';
        } elseif ( ! $live || ! $valid_http || $redirect ) {
            $health['code'] = 'http';
            $health['message'] = ! $live ? '目前無對應公開內容' : ( $redirect ? '公開網址有轉址' : '公開 HTTP 讀取異常' );
        } else {
            $health['sitemap'] = self::publication_sitemap_step( $url, $row['post_type'], $health['sitemap'] ?? [] );
            $health['code'] = $health['sitemap']['code'];
            $health['message'] = $health['sitemap']['state'];
        }

        if ( ( $health['sitemap']['code'] ?? '' ) === 'pending' ) {
            $next = time() + 15;
        } elseif ( ! $once && $live && in_array( $health['code'], ['missing','sitemap_error','http','excluded'], true ) &&
            ( !$excluded || !$snapshot['excluded'] || !$valid_http ) ) {
            $next = time() + 6 * MINUTE_IN_SECONDS;
            $health['recheck'] = true;
            // Save the evidence for the UI, restart the XML scan during the delayed recheck.
            $health['rescan'] = true;
        }
        $changes = ['health'=>wp_json_encode( $health, JSON_INVALID_UTF8_SUBSTITUTE ),'health_due'=>$next,'health_code'=>$health['code']];
        if ( $row['state'] === 'queued' && ! $row['ready'] ) {
            if ( $live && !$excluded && $valid_http && !$redirect ) {
                $changes['ready'] = 1;
            } elseif ( ! $next && ( ! $live || $excluded || !$valid_http || $redirect ) ) {
                $changes['state'] = 'held';
                $changes['response'] = wp_json_encode( ['message'=>'發現公開網址異常，暫不送出新增／更新通知。修正後可重跑健檢及重試。'] );
            }
        }
        self::indexnow_write( $row, $changes );
    }
}
