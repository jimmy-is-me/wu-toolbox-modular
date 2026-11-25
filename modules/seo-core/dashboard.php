<?php
defined( 'ABSPATH' ) || exit;

trait WUTM_SEO_Dashboard {
    public static function dashboard_assets(): void {
        if ( ( $_GET['page'] ?? '' ) !== 'wu-seo-tools' || ! current_user_can( 'manage_options' ) ) return;
        wp_enqueue_style( 'wu-seo-dashboard', WUTM_URL . 'modules/seo-core/assets/dashboard.css', [], self::VERSION );
        wp_enqueue_script( 'wu-seo-dashboard', WUTM_URL . 'modules/seo-core/assets/dashboard.js', [], self::VERSION, true );
        if ( ( $_GET['seo_tab'] ?? '' ) === 'indexnow' ) {
            wp_enqueue_style( 'wu-seo-indexnow', WUTM_URL . 'modules/seo-core/assets/indexnow.css', ['wu-seo-dashboard'], self::VERSION );
        }
    }

    public static function dashboard_row_action( array $actions, $post ): array {
        if ( current_user_can( 'manage_options' ) && self::dashboard_post( $post->ID ) ) {
            $url = add_query_arg( 'seo_post', $post->ID, admin_url( 'admin.php?page=wu-seo-tools' ) );
            $actions['wu_seo_performance'] = '<a href="' . esc_url( $url ) . '">SEO 搜尋成效</a>';
        }
        return $actions;
    }

    private static function dashboard_snapshot( array $input, string $scope ): array {
        $search = is_string( $input['search'] ?? null ) ? sanitize_text_field( substr( $input['search'], 0, 500 ) ) : '';
        $posts = self::dashboard_posts( $search, absint( $input['post'] ?? 0 ) );
        $rows = [];
        foreach ( $posts as $post ) {
            $url = get_permalink( $post );
            $saved = (array) get_post_meta( $post->ID, '_wu_seo_google_inspection', true );
            $google = $scope && ( $saved['scope'] ?? '' ) === $scope && ( $saved['url'] ?? '' ) === $url ? $saved['record'] : null;
            $public_http = get_transient( 'wu_seo_page_' . md5( $url ) );
            $publication = (array) get_post_meta( $post->ID, '_wu_seo_publication_check', true );
            $rows[] = [ 'id'=>$post->ID,'title'=>get_the_title( $post ),'url'=>$url,'google'=>$google,
                'local'=>[ 'noindex'=>!get_option( 'blog_public' ) || (bool) get_post_meta( $post->ID, '_wu_seo_noindex', true ),
                    'canonical'=>(string) get_post_meta( $post->ID, '_wu_seo_canonical', true ) ],
                'publication'=>$publication ?: null, 'http'=>is_array( $public_http ) ?
                    array_intersect_key( $public_http, array_flip( ['status','challenge','error','checked_at'] ) ) : null ];
        }
        $local = get_option( 'wu_seo_dashboard_local_sitemap', [] );
        $sitemap_id = self::dashboard_job_id( $scope, 'sitemaps', [] );
        $sitemaps = get_transient( 'wu_seo_job_' . $sitemap_id );
        return [
            'posts'=>$rows, 'keywords'=>array_values( (array) get_option( 'wu_seo_keywords_tracked', [] ) ),
            'sitemap'=>self::dashboard_local_sitemap( $local ), 'sitemap_url'=>self::sitemap_url(),
            'sitemap_job'=>is_array( $sitemaps ) ? self::dashboard_public_job( $sitemaps ) : null,
            'connected'=>!!$scope,
        ];
    }

    private static function dashboard_local_sitemap( $local ): array {
        return [
            'enabled'=>function_exists( 'wp_sitemaps_get_server' ) && wp_sitemaps_get_server()->sitemaps_enabled(),
            'http'=>is_array( $local ) && isset( $local['url'], $local['data'] ) && $local['url'] === self::sitemap_url() ? $local['data'] : null,
        ];
    }

    private static function dashboard_public_job( $job ): array {
        return array_intersect_key( $job, array_flip( ['state','time','queued','steps','errors','data'] ) );
    }

    private static function dashboard_analytics_params( array $input ) {
        $range = self::dashboard_range( $input );
        if ( is_wp_error( $range ) ) return $range;
        $type = $input['type'] ?? '';
        $value = is_string( $input['value'] ?? null ) ? sanitize_text_field( $input['value'] ) : '';
        if ( ! in_array( $type, [ '', 'query', 'page' ], true ) || strlen( $value ) > 1500 ) return new WP_Error( 'filter', '搜尋維度無效。' );
        if ( $type === 'page' && ( ! filter_var( $value, FILTER_VALIDATE_URL ) || ! preg_match( '~^https?://~i', $value ) ) ) {
            return new WP_Error( 'filter', '頁面網址無效。' );
        }
        if ( $type && ! $value ) return new WP_Error( 'filter', '請提供可查詢的頁面或關鍵字。' );
        return [ 'range'=>$range,'type'=>$type,'value'=>$type ? $value : '' ];
    }

    public static function dashboard_ajax(): void {
        if ( ! current_user_can( 'manage_options' ) || ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ||
            ! check_ajax_referer( 'wu_seo_dashboard', 'nonce', false ) ) wp_send_json_error( [ 'message'=>'權限驗證失敗，請重新整理。' ], 403 );
        $raw = isset( $_POST['payload'] ) && is_string( $_POST['payload'] ) ? wp_unslash( $_POST['payload'] ) : '{}';
        if ( strlen( $raw ) > 8192 ) wp_send_json_error( [ 'message'=>'查詢太長。' ], 400 );
        $input = json_decode( $raw, true );
        if ( ! is_array( $input ) ) wp_send_json_error( [ 'message'=>'查詢格式不正確。' ], 400 );
        $scope = self::google_scope();
        $op = $input['op'] ?? '';
        $response = null;
        if ( $op === 'snapshot' ) {
            $response = self::dashboard_snapshot( $input, $scope );
        } elseif ( $op === 'job' ) {
            $id = $input['id'] ?? '';
            $job = is_string( $id ) && preg_match( '/^[a-f0-9]{36}$/D', $id ) ? get_transient( 'wu_seo_job_' . $id ) : false;
            if ( ! is_array( $job ) || $job['scope'] !== $scope ) $response = new WP_Error( 'expired', '同步已過期或授權已變更。' );
            else {
                if ( $job['state'] === 'pending' && time() - $job['queued'] > 180 ) self::dashboard_schedule( $id );
                $response = self::dashboard_public_job( $job );
            }
        } elseif ( $op === 'performance' ) {
            $params = self::dashboard_analytics_params( $input );
            if ( is_wp_error( $params ) ) $response = $params;
            else {
                $steps = [ 'total', 'daily' ];
                if ( $params['type'] !== 'query' ) $steps[] = 'queries';
                if ( $params['type'] !== 'page' ) $steps[] = 'pages';
                if ( $params['range']['compare'] ) {
                    $prior = [ 'prior_total', 'prior_daily' ];
                    if ( !$params['type'] ) $prior[] = 'prior_queries';
                    $steps = array_merge( $steps, $prior );
                }
                $response = self::dashboard_queue( $scope, 'performance', $params, $steps, !empty( $input['force'] ) );
            }
        } elseif ( $op === 'inspect' ) {
            $ids = array_slice( array_unique( array_map( 'absint', (array) ( $input['ids'] ?? [] ) ) ), 0, 20 );
            $ids = array_values( array_filter( $ids, [ __CLASS__, 'dashboard_post' ] ) );
            sort( $ids );
            if ( $ids ) $response = self::dashboard_queue( $scope, 'inspect', $ids, $ids, true );
            else $response = new WP_Error( 'selection', '請選擇公開網址。' );
        } elseif ( $op === 'sitemaps' ) {
            $response = self::dashboard_queue( $scope, 'sitemaps', [], $scope ? [ 'local','submitted','children' ] : [ 'local' ], true );
        } elseif ( $op === 'keyword_add' || $op === 'keyword_remove' ) {
            $terms = (array) get_option( 'wu_seo_keywords_tracked', [] );
            $query = is_string( $input['term'] ?? null ) ? sanitize_text_field( $input['term'] ) : '';
            $target = absint( ( $input['target_id'] ?? '' ) ?: ( $input['target'] ?? 0 ) );
            if ( $query === '' || strlen( $query ) > 300 || ( $target && ! self::dashboard_post( $target ) ) ) {
                $response = new WP_Error( 'keyword', '請輸入關鍵字並選擇可公開的目標頁。' );
            } else {
                $id = substr( hash( 'sha256', $query ), 0, 24 );
                if ( $op === 'keyword_remove' ) unset( $terms[$id] );
                elseif ( isset( $terms[$id] ) || count( $terms ) < 20 ) {
                    $terms[$id] = ['term'=>$query,'id'=>$id,'target'=>$target,'target_url'=>$target ? get_permalink( $target ) : '' ];
                } else { wp_send_json_error( [ 'message'=>'最多追蹤 20 個關鍵字。' ], 400 ); }
                update_option( 'wu_seo_keywords_tracked', $terms, false );
                $response = array_values( $terms );
            }
        }
        if ( is_wp_error( $response ) ) wp_send_json_error( [ 'code'=>$response->get_error_code(),'message'=>$response->get_error_message() ], 400 );
        if ( null === $response ) wp_send_json_error( [ 'message'=>'無效的操作。' ], 400 );
        wp_send_json_success( $response );
    }

    private static function render_seo_dashboard( string $tab, int $focused ): void {
        $date = new DateTimeImmutable( 'today', new DateTimeZone( 'America/Los_Angeles' ) );
        $end = $date->modify( '-3 days' );
        $start = $end->modify( '-27 days' );
        $scope = self::google_scope();
        $target = self::dashboard_post( $focused );
        $page = admin_url( 'admin.php?page=wu-seo-tools' );
        require __DIR__ . '/dashboard-template.php';
    }
}
