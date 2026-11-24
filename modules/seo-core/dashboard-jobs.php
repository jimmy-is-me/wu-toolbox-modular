<?php
defined( 'ABSPATH' ) || exit;

trait WUTM_SEO_Dashboard_Jobs {
    const DASHBOARD_QUEUE = 'wu_seo_dashboard_active';
    const DASHBOARD_WORK = 'wu_seo_dashboard_work';

    private static function dashboard_init(): void {
        add_action( self::DASHBOARD_WORK, [ __CLASS__, 'dashboard_worker' ] );
        add_action( 'wu_seo_publication_check', [ __CLASS__, 'dashboard_check_publication' ] );
        add_action( 'transition_post_status', [ __CLASS__, 'dashboard_publication' ], 20, 3 );
        add_action( 'wp_ajax_wu_seo_dashboard', [ __CLASS__, 'dashboard_ajax' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'dashboard_assets' ] );
        add_filter( 'page_row_actions', [ __CLASS__, 'dashboard_row_action' ], 20, 2 );
        add_filter( 'post_row_actions', [ __CLASS__, 'dashboard_row_action' ], 20, 2 );
    }

    private static function dashboard_lock( $name ) {
        global $wpdb;
        $value = ( time() + 90 ) . ':' . wp_generate_uuid4();
        if ( add_option( $name, $value, '', false ) ) return $value;
        $ok = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value=%s WHERE option_name=%s AND CAST(SUBSTRING_INDEX(option_value, ':', 1) AS UNSIGNED)<%d", $value, $name, time() ) );
        return 1 === $ok ? $value : '';
    }

    private static function dashboard_unlock( $name, $value ): void {
        global $wpdb;
        $wpdb->delete( $wpdb->options, [ 'option_name'=>$name,'option_value'=>$value ] );
        wp_cache_delete( $name, 'options' );
        wp_cache_delete( 'notoptions', 'options' );
    }

    private static function dashboard_job_id( $scope, $kind, array $params ): string {
        return substr( hash( 'sha256', $scope . $kind . wp_json_encode( $params ) ), 0, 36 );
    }

    private static function dashboard_schedule( $id, $delay = 1 ): bool {
        $args = [ $id ];
        return (bool) wp_next_scheduled( self::DASHBOARD_WORK, $args ) || (bool) wp_schedule_single_event( time() + $delay, self::DASHBOARD_WORK, $args );
    }

    private static function dashboard_queue( $scope, $kind, array $params, array $steps, bool $force = false ) {
        if ( ! $scope && $kind !== 'sitemaps' ) return new WP_Error( 'auth', '尚未授權或未選擇 Search Console 資源。' );
        $id = self::dashboard_job_id( $scope, $kind, $params );
        $key = 'wu_seo_job_' . $id;
        $current = get_transient( $key );
        if ( is_array( $current ) && ( $current['state'] === 'pending' || ( ! $force && time() - $current['time'] < 12 * HOUR_IN_SECONDS ) || time() - $current['time'] < 30 ) ) {
            if ( $current['state'] === 'pending' ) self::dashboard_schedule( $id );
            return $id;
        }
        $lock = self::dashboard_lock( 'wu_seo_queue_lock' );
        if ( ! $lock ) return new WP_Error( 'busy', '同步佇列正在更新，請稍後再試。' );
        try {
            $active = array_filter( (array) get_transient( self::DASHBOARD_QUEUE ), static function ( $until, $key ) {
                $job = get_transient( 'wu_seo_job_' . $key );
                return $until >= time() && ( $job['state'] ?? '' ) === 'pending';
            }, ARRAY_FILTER_USE_BOTH );
            if ( isset( $active[$id] ) ) return $id;
            if ( count( $active ) >= 10 ) return new WP_Error( 'busy', '目前已有多筆同步，請稍後再試。' );
            $job = [ 'scope'=>$scope,'kind'=>$kind,'params'=>$params,'steps'=>$steps,'data'=>[], 'errors'=>[],
                'tries'=>0, 'state'=>'pending','queued'=>time(),'time'=>0 ];
            set_transient( $key, $job, 2 * DAY_IN_SECONDS );
            if ( ! self::dashboard_schedule( $id ) ) {
                delete_transient( $key );
                return new WP_Error( 'cron', 'WordPress 無法排入同步工作。' );
            }
            $active[$id] = time() + 30 * MINUTE_IN_SECONDS;
            set_transient( self::DASHBOARD_QUEUE, $active, HOUR_IN_SECONDS );
            return $id;
        } finally {
            self::dashboard_unlock( 'wu_seo_queue_lock', $lock );
        }
    }

    public static function dashboard_worker( $id ): void {
        if ( ! is_string( $id ) || ! preg_match( '/^[a-f0-9]{36}$/D', $id ) ) return;
        $name = 'wu_seo_lock_' . $id;
        $lock = self::dashboard_lock( $name );
        if ( ! $lock ) { self::dashboard_schedule( $id, 30 ); return; }
        try {
            self::dashboard_run( $id );
        } finally {
            self::dashboard_unlock( $name, $lock );
        }
    }

    private static function dashboard_run( string $id ): void {
        $key = 'wu_seo_job_' . $id;
        $job = get_transient( $key );
        if ( ! is_array( $job ) || $job['state'] !== 'pending' ) return;
        $failure = null;
        if ( $job['scope'] !== self::google_scope() ) $failure = new WP_Error( 'auth', '授權已變更。' );
        if ( ! $failure && time() - $job['queued'] > 30 * MINUTE_IN_SECONDS ) $failure = new WP_Error( 'timeout', '同步已逾時，請重新更新。' );
        $cooldown = (int) get_transient( 'wu_seo_google_cooldown' );
        if ( ! $failure && $cooldown > time() ) { self::dashboard_schedule( $id, $cooldown - time() + 1 ); return; }

        $deadline = microtime( true ) + 11;
        $delay = 2;
        for ( $i = 0; $job['steps'] && $i < 4 && microtime( true ) < $deadline; $i++ ) {
            $step = $job['steps'][0];
            $result = $failure ?: self::dashboard_step( $job, $step );
            if ( is_wp_error( $result ) ) {
                $info = (array) $result->get_error_data();
                if ( ! empty( $info['retry'] ) && ++$job['tries'] <= 2 ) {
                    $delay = ( $result->get_error_code() === 'quota' ? 60 : 10 ) * $job['tries'];
                    if ( $result->get_error_code() === 'quota' ) set_transient( 'wu_seo_google_cooldown', time() + $delay, $delay );
                    break;
                }
                $job['errors'][$step] = [ 'code'=>$result->get_error_code(), 'message'=>$result->get_error_message(),
                    'http'=>$info['http'] ?? null,'reason'=>$info['reason'] ?? '' ];
                if ( in_array( $result->get_error_code(), [ 'auth','quota','timeout' ], true ) ) $failure = $result;
            } else {
                $job['data'][$step] = $result;
            }

            if ( $job['kind'] === 'inspect' ) {
                $post = self::dashboard_post( (int) $step );
                if ( $post && $job['scope'] === self::google_scope() ) {
                    $url = get_permalink( $post );
                    if ( is_wp_error( $result ) || ( $result['url'] ?? '' ) === $url ) {
                        $record = is_wp_error( $result ) ? [ 'state'=>'failed','time'=>time(),'detail'=>$job['errors'][$step] ] : $result;
                        update_post_meta( $post->ID, '_wu_seo_google_inspection', [ 'scope'=>$job['scope'],'url'=>$url,'record'=>$record ] );
                    } else {
                        unset( $job['data'][$step] );
                        $job['errors'][$step] = [ 'code'=>'post_changed', 'message'=>'網址在查詢期間已變更，請重新查詢。' ];
                    }
                }
            }
            array_shift( $job['steps'] );
            $job['tries'] = 0;
        }
        if ( $job['steps'] && $failure ) {
            foreach ( $job['steps'] as $step ) $job['errors'][$step] = [ 'code'=>$failure->get_error_code(), 'message'=>$failure->get_error_message() ];
            $job['steps'] = [];
        }
        if ( ! $job['steps'] ) {
            $job['state'] = $job['errors'] ? ( $job['data'] ? 'partial' : 'failed' ) : 'ready';
            $job['time'] = time();
        }
        set_transient( $key, $job, 2 * DAY_IN_SECONDS );
        if ( $job['steps'] ) self::dashboard_schedule( $id, $delay );
    }

    private static function dashboard_step( array $job, $step ) {
        if ( $job['kind'] === 'inspect' ) return self::dashboard_inspect( (int) $step );
        if ( $job['kind'] === 'sitemaps' ) {
            return $step === 'local' ? self::dashboard_sitemap_health( self::run_sitemap_diagnostic() ) : self::dashboard_google_sitemaps( $step === 'children' );
        }
        $params = $job['params'];
        $data = self::google_call( '/searchAnalytics/query', self::dashboard_query_body( $params['range'], $step, $params['type'], $params['value'] ) );
        return is_wp_error( $data ) ? $data : self::dashboard_metrics( (array) ( $data['rows'] ?? [] ) );
    }

    private static function dashboard_sitemap_health( array $data ): array {
        return [ 'time'=>$data['checked_at'] ?? 0,'http'=>$data['status'] ?? null,'error'=>$data['error'] ?? '',
            'challenge'=>!empty( $data['challenge'] ), 'valid'=>!empty( $data['xml']['valid'] ),
            'count'=>$data['xml']['count'] ?? null,'issues'=>$data['xml']['issues'] ?? [],
            'children'=>!empty( $data['xml']['index'] ) ? array_slice( $data['xml']['urls'] ?? [], 0, 75 ) : [] ];
    }
}
