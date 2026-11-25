<?php
defined( 'ABSPATH' ) || exit;

trait WUTM_SEO_Dashboard_Data {
    public static function dashboard_range( array $input ) {
        $zone = new DateTimeZone( 'America/Los_Angeles' );
        $today = new DateTimeImmutable( 'today', $zone );
        $date = static function ( $value ) use ( $zone ) {
            if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $value ) ) return null;
            $result = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, $zone );
            return $result && $result->format( 'Y-m-d' ) === $value ? $result : null;
        };
        $start = $date( $input['start'] ?? '' );
        $end = $date( $input['end'] ?? '' );
        if ( ! $start || ! $end || $start > $end || $end > $today || $start->diff( $end )->days > 89 ) {
            return new WP_Error( 'range', '請選擇 1 至 90 天的有效日期區間，日期以 Search Console 太平洋時間為準。' );
        }
        $length = $start->diff( $end )->days + 1;
        $country = is_string( $input['country'] ?? null ) ? strtolower( trim( $input['country'] ) ) : '';
        $device = is_string( $input['device'] ?? null ) ? strtoupper( $input['device'] ) : '';
        if ( ( $country && ! preg_match( '/^[a-z]{3}$/D', $country ) ) || ! in_array( $device, [ '', 'DESKTOP', 'MOBILE', 'TABLET' ], true ) ) {
            return new WP_Error( 'filter', '請使用三碼國家代碼及有效裝置。' );
        }
        return [
            'start'=>$start->format( 'Y-m-d' ), 'end'=>$end->format( 'Y-m-d' ),
            'prior_start'=>$start->modify( "-$length days" )->format( 'Y-m-d' ), 'prior_end'=>$start->modify( '-1 day' )->format( 'Y-m-d' ),
            'compare'=>!empty( $input['compare'] ), 'country'=>$country, 'device'=>$device,
        ];
    }

    private static function dashboard_post( int $id ) {
        if ( !$id ) return null;
        $post = get_post( $id );
        return $post && $post->post_status === 'publish' && in_array( $post->post_type, self::supported_post_types(), true ) &&
            is_post_publicly_viewable( $post ) && ! $post->post_password ? $post : null;
    }

    private static function dashboard_posts( $search = '', $focused = 0 ) {
        $ids = get_posts( [ 'post_type'=>self::supported_post_types(), 'post_status'=>'publish', 'has_password'=>false,
            's'=>$search, 'posts_per_page'=>30, 'fields'=>'ids', 'orderby'=>'date', 'order'=>'DESC', 'no_found_rows'=>true ] );
        if ( $focused && ! in_array( $focused, $ids, true ) ) array_unshift( $ids, $focused );
        return array_values( array_filter( array_map( [ __CLASS__, 'dashboard_post' ], array_slice( $ids, 0, 30 ) ) ) );
    }

    public static function dashboard_query_body( array $range, string $part, string $filter_type = '', string $filter_value = '' ): array {
        $previous = strpos( $part, 'prior_' ) === 0;
        $name = $previous ? substr( $part, 6 ) : $part;
        $dimensions = [ 'total'=>[], 'daily'=>[ 'date' ], 'queries'=>[ 'query' ], 'pages'=>[ 'page' ] ][$name];
        $body = [ 'startDate'=>$range[ $previous ? 'prior_start' : 'start' ], 'endDate'=>$range[ $previous ? 'prior_end' : 'end' ],
            'dimensions'=>$dimensions, 'aggregationType'=>($name === 'pages' || $filter_type === 'page') ? 'auto' : 'byProperty',
            'dataState'=>'final', 'type'=>'web', 'rowLimit'=>$name === 'daily' ? 90 : ( $name === 'total' ? 1 : 25 ) ];
        $filters = [];
        foreach ( [ 'country'=>$range['country'], 'device'=>$range['device'], $filter_type=>$filter_value ] as $field=>$value ) {
            if ( $field && $value ) $filters[] = [ 'dimension'=>$field,'operator'=>'equals','expression'=>$value ];
        }
        if ( $filters ) $body['dimensionFilterGroups'] = [ [ 'groupType'=>'and','filters'=>$filters ] ];
        return $body;
    }

    private static function dashboard_metrics( array $rows ): array {
        $output = [];
        foreach ( array_slice( $rows, 0, 90 ) as $row ) {
            if ( ! is_array( $row ) ) continue;
            $item = [ 'key'=>is_string( $row['keys'][0] ?? null ) ? $row['keys'][0] : '' ];
            foreach ( [ 'impressions','clicks','ctr','position' ] as $field ) {
                $item[$field] = isset( $row[$field] ) && is_numeric( $row[$field] ) && is_finite( (float) $row[$field] ) ? (float) $row[$field] : null;
            }
            $output[] = $item;
        }
        return $output;
    }

    public static function dashboard_index_status( array $index ): string {
        if ( ( $index['verdict'] ?? '' ) === 'PASS' ) return 'indexed';
        if ( in_array( $index['verdict'] ?? '', [ 'FAIL','NEUTRAL' ], true ) ) return 'excluded';
        if ( in_array( $index['indexingState'] ?? '', [ 'BLOCKED_BY_META_TAG','BLOCKED_BY_HTTP_HEADER','BLOCKED_BY_ROBOTS_TXT' ], true ) ) return 'excluded';
        return 'unknown';
    }

    private static function dashboard_inspect( int $id ) {
        $post = self::dashboard_post( $id );
        if ( ! $post ) return new WP_Error( 'post', '內容已不公開或不支援。' );
        $url = (string) get_permalink( $post );
        $config = (array) get_option( 'wu_seo_google', [] );
        $response = self::google_call( '', [ 'inspectionUrl'=>$url,'siteUrl'=>$config['property'] ?? '','languageCode'=>'zh-TW' ], true );
        if ( is_wp_error( $response ) ) return $response;
        $result = (array) ( $response['inspectionResult'] ?? [] );
        $index = (array) ( $result['indexStatusResult'] ?? [] );
        return [ 'state'=>self::dashboard_index_status( $index ), 'time'=>time(), 'url'=>$url,
            'detail'=>array_intersect_key( $index, array_flip( [ 'verdict','coverageState','robotsTxtState','indexingState','lastCrawlTime',
                'pageFetchState','googleCanonical','userCanonical','crawledAs','sitemap' ] ) ),
            'link'=>esc_url_raw( is_string( $result['inspectionResultLink'] ?? null ) ? $result['inspectionResultLink'] : '', [ 'https' ] ) ];
    }

    private static function dashboard_google_sitemaps( $children ) {
        $path = '/sitemaps' . ( $children ? '?sitemapIndex=' . rawurlencode( self::sitemap_url() ) : '' );
        $response = self::google_call( $path );
        if ( is_wp_error( $response ) ) return $response;
        return array_map( static function ( $row ) {
            return array_intersect_key( $row, array_flip( [ 'path','lastSubmitted','lastDownloaded','isPending','isSitemapsIndex','warnings','errors','type' ] ) );
        }, array_slice( array_filter( (array) ( $response['sitemap'] ?? [] ), 'is_array' ), 0, 75 ) );
    }

    public static function dashboard_publication( $new, $old, $post ): void {
        if ( 'publish' !== $new || 'publish' === $old || ! self::dashboard_post( (int) $post->ID ) ) return;
        if ( get_post_meta( $post->ID, '_wu_seo_publication_check', true ) ) return;
        update_post_meta( $post->ID, '_wu_seo_publication_check', [ 'state'=>'pending', 'offset'=>0, 'time'=>time() ] );
        wp_schedule_single_event( time() + 30, 'wu_seo_publication_check', [ (int) $post->ID ] );
    }

    private static function publication_exclusion( $post, string $url ): string {
        if ( ! get_option( 'blog_public' ) ) return '網站設定不允許索引';
        if ( self::output_is_owned_elsewhere() ) return '';
        if ( get_post_meta( $post->ID, '_wu_seo_noindex', true ) ) return '本機 noindex';
        $canonical = (string) get_post_meta( $post->ID, '_wu_seo_canonical', true );
        return $canonical && untrailingslashit( $canonical ) !== untrailingslashit( $url )
            ? '本機 canonical 指向其他網址' : '';
    }

    private static function publication_sitemap_step( string $url, string $type, array $state, int $timeout = 4 ): array {
        $root = self::run_sitemap_diagnostic( '', $timeout );
        $state['time'] = time();
        if ( empty( $root['xml']['valid'] ) || ! empty( $root['challenge'] ) || 200 !== ( $root['status'] ?? 0 ) ) {
            return array_replace( $state, ['code'=>'sitemap_error','state'=>'主 Sitemap HTTP／XML 讀取錯誤',
                'source'=>self::sitemap_url(),'source_time'=>$root['checked_at'] ?? 0,'http'=>$root['status'] ?? null,'detail'=>$root['error'] ?? implode( '；', $root['xml']['issues'] ?? [] )] );
        }
        if ( empty( $root['xml']['index'] ) ) {
            $found = in_array( $url, $root['xml']['urls'], true );
            return array_replace( $state, ['code'=>$found ? 'ok' : 'missing',
                'state'=>$found ? '公開 Sitemap 已列出' : '找不到，原因待查',
                'source'=>self::sitemap_url(),'source_time'=>$root['checked_at'] ?? 0,'http'=>200] );
        }

        $children = array_values( array_unique( array_filter( $root['xml']['urls'], static function ( $child ) use ( $type ) {
            $parts = wp_parse_url( $child ); $params = [];
            parse_str( $parts['query'] ?? '', $params );
            return ( ( $params['sitemap'] ?? '' ) === 'posts' && ( $params['sitemap-subtype'] ?? '' ) === $type ) ||
                preg_match( '~(?:^|/)wp-sitemap-posts-' . preg_quote( $type, '~' ) . '-[0-9]+\.xml$~D', $parts['path'] ?? '' );
        } ) ) );
        $state['children'] = count( $children );
        $signature = hash( 'sha256', implode( "\n", $children ) );
        if ( $signature !== ( $state['signature'] ?? '' ) ) {
            $state = array_intersect_key( $state, array_flip( ['time','children'] ) ) + ['offset'=>0,'signature'=>$signature];
        }
        foreach ( array_slice( $children, $state['offset'], min( 2, 30 - $state['offset'] ) ) as $child ) {
            $r = self::run_sitemap_diagnostic( $child, $timeout );
            $state['offset']++;
            $state['last_child'] = $child;
            $state['last_child_time'] = $r['checked_at'] ?? 0;
            if ( empty( $r['xml']['valid'] ) || ! empty( $r['xml']['index'] ) || ! empty( $r['challenge'] ) || 200 !== ( $r['status'] ?? 0 ) ) {
                $state['child_error'] = true;
                $state['source'] = $child;
                $state['source_time'] = $r['checked_at'] ?? 0;
                $state['http'] = $r['status'] ?? null;
                $state['detail'] = $r['error'] ?? implode( '；', $r['xml']['issues'] ?? [] );
                continue;
            }
            if ( in_array( $url, $r['xml']['urls'], true ) ) {
                return array_replace( $state, ['code'=>'ok','state'=>'公開子 Sitemap 已列出','child'=>$child,'source'=>$child,'source_time'=>$r['checked_at'] ?? 0,'http'=>200,'detail'=>''] );
            }
        }
        if ( $state['offset'] < min( 30, count( $children ) ) ) {
            return array_replace( $state, ['code'=>'pending','state'=>'pending'] );
        }
        if ( ! empty( $state['child_error'] ) ) {
            return array_replace( $state, ['code'=>'sitemap_error','state'=>'子 Sitemap HTTP／XML 讀取錯誤'] );
        }
        $state['source'] = $state['last_child'] ?? self::sitemap_url();
        $state['source_time'] = $state['last_child_time'] ?? $root['checked_at'] ?? 0;
        $state['http'] = 200;
        unset( $state['detail'] );
        return array_replace( $state, ['code'=>'missing','state'=>$state['offset'] < count( $children )
            ? '本次已查 30 個子檔，原因待查' : '找不到，原因待查'] );
    }

    public static function dashboard_check_publication( int $id ): void {
        $post = self::dashboard_post( $id );
        if ( ! $post ) return;
        $state = (array) get_post_meta( $id, '_wu_seo_publication_check', true );
        if ( 'pending' !== ( $state['state'] ?? '' ) ) return;
        $url = (string) get_permalink( $post );
        $excluded = self::publication_exclusion( $post, $url );
        $state = $excluded ? array_replace( $state, ['state'=>$excluded,'time'=>time()] )
            : self::publication_sitemap_step( $url, $post->post_type, $state );
        if ( $state['state'] === 'pending' ) wp_schedule_single_event( time() + 15, 'wu_seo_publication_check', [$id] );
        update_post_meta( $id, '_wu_seo_publication_check', $state );
    }
}
