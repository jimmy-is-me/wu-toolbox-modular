<?php
defined( 'ABSPATH' ) || exit;

trait WUTM_SEO_IndexNow {
    const INDEXNOW_OPTION = 'wu_seo_indexnow';
    const INDEXNOW_PROOF = 'wu_seo_indexnow_proof';
    const INDEXNOW_CRON = 'wu_seo_indexnow_work';

    private static $indexnow_before = [];

    private static function indexnow_init(): void {
        add_action( 'admin_post_wu_seo_indexnow', [ __CLASS__, 'indexnow_admin' ] );
        add_action( 'parse_request', [ __CLASS__, 'indexnow_key_file' ], 0 );
        add_filter( 'cron_schedules', [ __CLASS__, 'indexnow_intervals' ] );
        add_action( self::INDEXNOW_CRON, [ __CLASS__, 'indexnow_worker' ] );
        add_action( 'wutm_module_toggled', [ __CLASS__, 'indexnow_module_toggled' ], 10, 2 );
        if ( self::indexnow_settings()['enabled'] && get_option( 'wu_seo_indexnow_schema' ) === '1' ) {
            self::indexnow_schedule();
        }

        add_action( 'pre_post_update', [ __CLASS__, 'indexnow_capture' ], 1 );
        add_action( 'before_delete_post', [ __CLASS__, 'indexnow_capture' ], 1 );
        add_action( 'wp_after_insert_post', [ __CLASS__, 'indexnow_inserted' ], 100, 4 );
        foreach ( ['add', 'update', 'delete'] as $operation ) {
            add_filter( $operation . '_post_metadata', [ __CLASS__, 'indexnow_capture_meta' ], 5, 3 );
        }
    }

    public static function indexnow_module_toggled( string $module, bool $enabled ): void {
        if ( $module === 'seo-core' && ! $enabled ) wp_clear_scheduled_hook( self::INDEXNOW_CRON );
    }

    private static function indexnow_schedule(): bool {
        return wp_next_scheduled( self::INDEXNOW_CRON ) ||
            wp_schedule_event( time() + 10, 'wu_seo_indexnow_minute', self::INDEXNOW_CRON );
    }

    private static function indexnow_settings(): array {
        return wp_parse_args( (array) get_option( self::INDEXNOW_OPTION, [] ), [
            'enabled'=>false, 'key'=>'', 'location'=>'', 'types'=>['post','page'],
        ] );
    }

    private static function indexnow_types(): array {
        return array_filter( get_post_types( ['public'=>true], 'objects' ), static function ( $type ) {
            return $type->name !== 'attachment' && is_post_type_viewable( $type );
        } );
    }

    private static function indexnow_valid_key( string $key ): bool {
        return (bool) preg_match( '/^[A-Za-z0-9-]{8,128}$/D', $key );
    }

    private static function indexnow_url_parts( $url ): array {
        if ( ! is_string( $url ) || strlen( $url ) > 2048 || ! preg_match( '~^https?://~i', $url ) ||
            preg_match( '~[\x00-\x20\x7f\\\\]~', $url ) || preg_match( '/%(?![a-f0-9]{2})/i', $url ) ) return [];
        $parts = wp_parse_url( $url );
        if ( empty( $parts['host'] ) ||
            isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) ) return [];
        $parts['path'] = $parts['path'] ?? '/';
        // Scope comparisons must use an unambiguous path.
        if ( preg_match( '~(?:^|/)\.{1,2}(?:/|$)|%2e|%2f|%5c|%00~i', $parts['path'] ) ) return [];
        return $parts;
    }

    private static function indexnow_origin_matches( array $first, array $second ): bool {
        return $first && $second && strtolower( $first['host'] ) === strtolower( $second['host'] )
            && $first['scheme'] === $second['scheme']
            && ( $first['port'] ?? ( $first['scheme'] === 'https' ? 443 : 80 ) ) ===
                ( $second['port'] ?? ( $second['scheme'] === 'https' ? 443 : 80 ) );
    }

    private static function indexnow_location( array $settings ): string {
        if ( ! self::indexnow_valid_key( $settings['key'] ) ) return '';
        $url = $settings['location'] ?: home_url( '/' . $settings['key'] . '.txt' );
        $parts = self::indexnow_url_parts( $url );
        return self::indexnow_origin_matches( $parts, self::indexnow_url_parts( home_url( '/' ) ) )
            && ! isset( $parts['query'] ) && str_ends_with( $parts['path'], '.txt' ) ? $url : '';
    }

    private static function indexnow_in_scope( $url, array $settings ): bool {
        $parts = self::indexnow_url_parts( $url );
        $key = self::indexnow_url_parts( self::indexnow_location( $settings ) );
        if ( ! self::indexnow_origin_matches( $parts, $key ) ) return false;
        $site = self::indexnow_url_parts( home_url( '/' ) );
        $query = [];
        parse_str( $parts['query'] ?? '', $query );
        foreach ( $query as $name => $value ) {
            if ( ! is_string( $value ) || ! in_array( strtolower( $name ),
                ['p','page_id','post_type','name','pagename','product','lang', ...array_values( array_filter( wp_list_pluck( self::indexnow_types(), 'query_var' ) ) )], true ) ) return false;
        }
        $folder = substr( $key['path'], 0, strrpos( $key['path'], '/' ) + 1 );
        return str_starts_with( $parts['path'], $folder )
            && str_starts_with( $parts['path'], trailingslashit( $site['path'] ) );
    }

    public static function indexnow_key_file(): void {
        $settings = self::indexnow_settings();
        // The default file is available during setup. Custom files are served by the host.
        if ( $settings['location'] || ! in_array( $_SERVER['REQUEST_METHOD'] ?? '', ['GET','HEAD'], true ) ) return;
        $location = self::indexnow_location( $settings );
        if ( ! $location || ( $_SERVER['REQUEST_URI'] ?? '' ) !== wp_parse_url( $location, PHP_URL_PATH ) ) return;
        $host = wp_parse_url( ( is_ssl() ? 'https://' : 'http://' ) . ( $_SERVER['HTTP_HOST'] ?? '' ) );
        if ( strtolower( $host['host'] ?? '' ) !== strtolower( wp_parse_url( $location, PHP_URL_HOST ) ) ) return;
        status_header( 200 );
        nocache_headers();
        header( 'Content-Type: text/plain; charset=UTF-8' );
        header( 'X-Content-Type-Options: nosniff' );
        if ( $_SERVER['REQUEST_METHOD'] === 'GET' ) echo $settings['key']; // Strict token alphabet, no newline.
        exit;
    }

    private static function indexnow_verify( array $settings ): array {
        $url = self::indexnow_location( $settings );
        $result = ['url'=>$url,'time'=>time(),'http'=>0,'ok'=>false,'detail'=>'金鑰或驗證檔網址無效。'];
        if ( ! $url ) return $result;
        $response = wp_safe_remote_get( $url, ['timeout'=>6,'redirection'=>0,'cookies'=>[],'limit_response_size'=>512] );
        if ( is_wp_error( $response ) ) {
            $result['detail'] = $response->get_error_message();
            return $result;
        }
        $result['http'] = (int) wp_remote_retrieve_response_code( $response );
        $result['headers'] = [];
        foreach ( ['content-type','location','age','cf-cache-status','cf-mitigated','x-cache'] as $name ) {
            $value = wp_remote_retrieve_header( $response, $name );
            if ( is_string( $value ) && $value !== '' ) $result['headers'][$name] = substr( $value, 0, 300 );
        }
        $result['ok'] = $result['http'] === 200 && hash_equals( $settings['key'], wp_remote_retrieve_body( $response ) );
        $result['detail'] = $result['ok'] ? 'HTTP 200，內容與金鑰完全相符。'
            : ( $result['http'] === 200 ? 'HTTP 200，但內容不符。請檢查是否為 HTML、舊金鑰、BOM 或換行。'
                : '驗證檔未直接回傳 HTTP 200。請檢查重寫規則、公開存取及快取。' );
        return $result;
    }

    private static function indexnow_proof_id( array $settings ): string {
        return hash( 'sha256', $settings['key'] . '|' . self::indexnow_location( $settings ) );
    }

    public static function indexnow_intervals( array $schedules ): array {
        $schedules['wu_seo_indexnow_minute'] = ['interval'=>60,'display'=>'WU SEO IndexNow'];
        return $schedules;
    }

    private static function indexnow_snapshot( $post, array $settings ): array {
        if ( ! $post instanceof WP_Post || $post->post_status !== 'publish' || $post->post_password ||
            ! in_array( $post->post_type, $settings['types'], true ) || ! isset( self::indexnow_types()[$post->post_type] ) ) return [];
        $url = get_permalink( $post );
        if ( ! self::indexnow_in_scope( $url, $settings ) ) return [];
        $seo = self::get_post_meta_data( $post->ID );
        $excluded = self::publication_exclusion( $post, $url );
        return [
            'url'=>$url,'type'=>$post->post_type,'excluded'=>$excluded,
            'digest'=>hash( 'sha256', serialize( [$post->post_title,$post->post_content,$post->post_excerpt,$seo] ) ),
        ];
    }

    public static function indexnow_capture( $id ): void {
        $blog = get_current_blog_id();
        if ( isset( self::$indexnow_before[$blog][$id] ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) return;
        $settings = self::indexnow_settings();
        $post = get_post( $id );
        if ( ! $settings['enabled'] || ! $post || ! in_array( $post->post_type, $settings['types'], true ) ) return;
        self::$indexnow_before[$blog][$id] = self::indexnow_snapshot( $post, $settings );
        add_action( 'shutdown', [ __CLASS__, 'indexnow_flush' ], 5 );
    }

    public static function indexnow_capture_meta( $check, $id, $key ) {
        if ( in_array( $key, ['_wu_seo_title','_wu_seo_description','_wu_seo_canonical','_wu_seo_noindex','_wu_seo_nofollow',
            '_wu_seo_topic','_wu_seo_og_title','_wu_seo_og_desc','_wu_seo_og_image'], true ) ) self::indexnow_capture( $id );
        return $check;
    }

    public static function indexnow_inserted( $id, $post, $update, $before ): void {
        $blog = get_current_blog_id();
        $settings = self::indexnow_settings();
        if ( ! $settings['enabled'] || ! in_array( $post->post_type, $settings['types'], true ) ||
            ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) return;
        // Meta input can be written before wp_after_insert_post on a new record.
        if ( ! $update || ! isset( self::$indexnow_before[$blog][$id] ) ) {
            self::$indexnow_before[$blog][$id] = self::indexnow_snapshot( $before, $settings );
            add_action( 'shutdown', [ __CLASS__, 'indexnow_flush' ], 5 );
        }
    }

    public static function indexnow_flush(): void {
        $sites = self::$indexnow_before;
        self::$indexnow_before = [];
        foreach ( $sites as $blog=>$changes ) {
            $switched = $blog !== get_current_blog_id();
            if ( $switched ) switch_to_blog( $blog );
            try {
                $settings = self::indexnow_settings();
                if ( $settings['enabled'] && get_option( 'wu_seo_indexnow_schema' ) === '1' ) {
                    foreach ( $changes as $id=>$before ) {
                        $after = self::indexnow_snapshot( get_post( $id ), $settings );
                        self::indexnow_change( (int) $id, $before, $after );
                    }
                }
            } finally {
                if ( $switched ) restore_current_blog();
            }
        }
    }

    private static function indexnow_change( int $id, array $before, array $after ): void {
        if ( ! $before && ! $after ) return;
        $old_url = $before['url'] ?? '';
        $new_url = $after['url'] ?? '';
        if ( $old_url && $old_url !== $new_url ) {
            self::indexnow_enqueue( $old_url, $id, $before['type'], $new_url ? 'moved' : 'removed' );
        }
        if ( ! $new_url ) return;
        if ( $after['excluded'] ) {
            if ( $old_url === $new_url && ! $before['excluded'] ) {
                self::indexnow_enqueue( $new_url, $id, $after['type'], 'excluded' );
            } elseif ( $old_url !== $new_url ) {
                // Further edits to an excluded URL must not replace a pending retirement.
                self::indexnow_enqueue( $new_url, $id, $after['type'], 'check' );
            }
            return;
        }
        if ( $old_url !== $new_url || $before['excluded'] || $before['digest'] !== $after['digest'] ) {
            self::indexnow_enqueue( $new_url, $id, $after['type'], ! $old_url ? 'published' : ( $old_url !== $new_url ? 'new_url' : 'updated' ) );
        }
    }
}
