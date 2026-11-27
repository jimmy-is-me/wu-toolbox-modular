<?php
defined( 'ABSPATH' ) || exit;

function sac_diagnostic_definitions() {
    $target = [ 'post_type' => [ 'type' => 'string', 'in' => 'path', 'required' => true ], 'id' => [ 'type' => 'integer', 'in' => 'path', 'required' => true ] ];
    $tools = [];
    $rows = [
        [ 'analyze_content', 'analysis', '整合文章、欄位、區塊與選用的前台診斷', [ 'include' => [ 'type' => 'array', 'in' => 'query', 'items' => [
            'type' => 'string', 'enum' => [ 'content', 'fields', 'outline', 'seo', 'faq', 'media', 'frontend', 'sitemap', 'gsc' ],
        ] ], 'days' => [ 'type' => 'integer', 'in' => 'query', 'enum' => [ 7, 28, 90 ] ],
            'country' => [ 'type' => 'string', 'in' => 'query' ], 'device' => [ 'type' => 'string', 'in' => 'query' ], ] ],
        [ 'audit_content_seo', 'rendered-seo', '查詢公開頁面的 SEO 與實際 HTML 輸出', [] ],
        [ 'get_content_sitemap_status', 'sitemap', '檢查公開 Sitemap 是否列出此內容', [] ],
    ];
    foreach ( $rows as [ $name, $route, $summary, $params ] ) {
        $tools[] = [ 'name' => $name, 'method' => 'GET', 'path' => '/content/{post_type}/{id}/' . $route, 'group' => 'SEO', 'summary' => $summary,
            'description' => $summary, 'scenario' => $summary, 'prompt' => $summary, 'params' => $target + $params, 'need_write' => false, 'available' => true ];
    }
    return $tools;
}

function sac_public_fetch( $url ) {
    if ( sac_has_wumetax_seo_core() ) return Wumetax_SEO_Core_v120::diagnostic_request( $url, 3 );
    $origin = sac_oauth_origin( home_url( '/' ) );
    $chain = [];
    for ( $i = 0; $i < 4; $i++ ) {
        $parts = wp_parse_url( $url );
        if ( sac_oauth_origin( $url ) !== $origin || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
            return [ 'url' => $url, 'chain' => $chain, 'error' => '目標或轉址超出本站範圍。' ];
        }
        $response = wp_safe_remote_get( $url, [ 'timeout' => 3, 'redirection' => 0, 'limit_response_size' => 2 * MB_IN_BYTES, 'cookies' => [] ] );
        if ( is_wp_error( $response ) ) return [ 'url' => $url, 'chain' => $chain, 'error' => $response->get_error_message() ];
        $status = wp_remote_retrieve_response_code( $response );
        $chain[] = [ 'url' => $url, 'status' => $status ];
        $next = wp_remote_retrieve_header( $response, 'location' );
        if ( is_string( $next ) && $next !== '' && in_array( $status, [ 301, 302, 303, 307, 308 ], true ) ) {
            $url = WP_Http::make_absolute_url( $next, $url );
            continue;
        }
        $body = wp_remote_retrieve_body( $response );
        return [ 'url' => $url, 'status' => $status, 'chain' => $chain, 'body' => $body,
            'type' => (string) wp_remote_retrieve_header( $response, 'content-type' ),
            'robots' => implode( ', ', (array) wp_remote_retrieve_header( $response, 'x-robots-tag' ) ),
            'challenge' => 'challenge' === strtolower( trim( (string) wp_remote_retrieve_header( $response, 'cf-mitigated' ) ) ),
            'error' => strlen( $body ) >= 2 * MB_IN_BYTES ? '公開回應超過 2 MiB。' : null ];
    }
    return [ 'url' => $url, 'chain' => $chain, 'error' => '公開網址轉址次數超過上限。' ];
}

function sac_public_post_url( $post ) {
    return is_post_publicly_viewable( $post ) && $post->post_password === '' ? get_permalink( $post ) : null;
}

function sac_public_seo( $post ) {
    $url = sac_public_post_url( $post );
    if ( ! $url ) return [ 'state' => 'unavailable', 'code' => 'sac_post_not_public' ];
    $key = 'sac_rendered_' . md5( $url . ':' . $post->post_modified_gmt );
    $cached = get_transient( $key );
    if ( is_array( $cached ) ) return $cached;
    $response = sac_public_fetch( $url );
    $observed = array_intersect_key( $response, array_flip( [ 'status', 'url', 'chain', 'robots', 'challenge', 'error' ] ) );
    $observed['checked_at'] = gmdate( DATE_ATOM );
    if ( ! empty( $response['error'] ) || ! empty( $response['challenge'] ) || ( $response['status'] ?? 0 ) !== 200
        || ! preg_match( '~^(text/html|application/xhtml\+xml)(?:;|$)~i', $response['type'] ?? '' ) || ! class_exists( 'DOMDocument' ) ) {
        $result = [ 'state' => 'unavailable', 'code' => 'frontend_fetch_failed' ] + $observed;
        set_transient( $key, $result, MINUTE_IN_SECONDS );
        return $result;
    }
    $document = new DOMDocument();
    $loaded = $document->loadHTML( '<?xml encoding="UTF-8">' . $response['body'], LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
    if ( ! $loaded ) return [ 'state' => 'unavailable', 'code' => 'frontend_parse_failed' ] + $observed;
    $xpath = new DOMXPath( $document );
    $meta = [];
    foreach ( $xpath->query( '//head/meta[@content]' ) as $tag ) {
        $name = strtolower( $tag->getAttribute( 'name' ) ?: $tag->getAttribute( 'property' ) );
        if ( in_array( $name, [ 'description', 'robots', 'googlebot', 'bingbot' ], true ) || str_starts_with( $name, 'og:' ) || str_starts_with( $name, 'twitter:' ) ) {
            if ( count( $meta ) < 80 && count( $meta[ $name ] ?? [] ) < 10 ) $meta[ $name ][] = wp_html_excerpt( $tag->getAttribute( 'content' ), 1800 );
        }
    }
    $canonicals = [];
    foreach ( $xpath->query( '//head/link[@href]' ) as $tag ) {
        if ( strtolower( trim( $tag->getAttribute( 'rel' ) ) ) === 'canonical' ) $canonicals[] = wp_html_excerpt( $tag->getAttribute( 'href' ), 1800 );
    }
    $headings = [];
    foreach ( $xpath->query( '//h1|//h2|//h3|//h4' ) as $tag ) {
        if ( count( $headings ) >= 120 ) break;
        $headings[] = [ 'level' => (int) substr( $tag->tagName, 1 ), 'text' => wp_html_excerpt( trim( $tag->textContent ), 500 ) ];
    }
    $schemas = [];
    foreach ( $xpath->query( '//script[@type="application/ld+json"]' ) as $tag ) {
        if ( count( $schemas ) >= 20 ) break;
        $decoded = json_decode( $tag->textContent, true, 32 );
        $types = [];
        if ( is_array( $decoded ) ) {
            array_walk_recursive( $decoded, static function ( $value, $key ) use ( &$types ) {
                if ( $key === '@type' && is_string( $value ) && count( $types ) < 100 ) $types[] = wp_html_excerpt( $value, 160 );
            } );
        }
        $schemas[] = [ 'valid' => is_array( $decoded ), 'types' => array_values( array_unique( $types ) ) ];
    }
    $links = [ 'internal' => 0, 'external' => 0, 'sample' => [] ];
    foreach ( $xpath->query( '//a[@href]' ) as $tag ) {
        $href = trim( $tag->getAttribute( 'href' ) );
        if ( $href === '' || $href[0] === '#' ) continue;
        $absolute = WP_Http::make_absolute_url( $href, $response['url'] );
        $parsed = wp_parse_url( $absolute );
        if ( ! in_array( $parsed['scheme'] ?? '', [ 'http', 'https' ], true ) ) continue;
        $kind = sac_oauth_origin( $absolute ) === sac_oauth_origin( $url ) ? 'internal' : 'external';
        $links[ $kind ]++;
        if ( count( $links['sample'] ) < 120 && ! isset( $parsed['user'] ) && ! isset( $parsed['pass'] ) ) {
            $links['sample'][] = [ 'type' => $kind, 'url' => strtok( $absolute, '?#' ) ];
        }
    }
    $result = [ 'state' => 'verified', 'title' => wp_html_excerpt( $xpath->evaluate( 'string(//head/title)' ), 1800 ),
        'metadata' => $meta, 'canonical' => array_slice( $canonicals, 0, 20 ), 'canonical_count' => count( $canonicals ),
        'h1_count' => $xpath->query( '//h1' )->length, 'headings' => $headings, 'schema' => $schemas,
        'images' => [ 'total' => $xpath->query( '//img' )->length, 'missing_alt_attribute' => $xpath->query( '//img[not(@alt)]' )->length ],
        'links' => $links ] + $observed;
    set_transient( $key, $result, 5 * MINUTE_IN_SECONDS );
    return $result;
}

function sac_public_sitemap_document( $url ) {
    if ( sac_has_wumetax_seo_core() ) return Wumetax_SEO_Core_v120::run_sitemap_diagnostic( $url, 3 );
    $response = sac_public_fetch( $url );
    if ( empty( $response['error'] ) && empty( $response['challenge'] ) && ( $response['status'] ?? 0 ) === 200
        && class_exists( 'DOMDocument' ) && ! preg_match( '/<!DOCTYPE|<!ENTITY/i', $response['body'] ) ) {
        $document = new DOMDocument();
        if ( $document->loadXML( $response['body'], LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING ) ) {
            $root = $document->documentElement;
            if ( $root && $root->namespaceURI === 'http://www.sitemaps.org/schemas/sitemap/0.9' && in_array( $root->localName, [ 'sitemapindex', 'urlset' ], true ) ) {
                $response['xml'] = [ 'valid' => true, 'index' => $root->localName === 'sitemapindex', 'urls' => [] ];
                $xpath = new DOMXPath( $document );
                $xpath->registerNamespace( 's', $root->namespaceURI );
                foreach ( $xpath->query( $response['xml']['index'] ? '/s:sitemapindex/s:sitemap/s:loc' : '/s:urlset/s:url/s:loc' ) as $node ) {
                    $response['xml']['urls'][] = trim( $node->textContent );
                }
            }
        }
    }
    unset( $response['body'] );
    return $response;
}

function sac_public_sitemap( $post ) {
    $target = sac_public_post_url( $post );
    if ( ! $target ) return [ 'state' => 'unavailable', 'code' => 'sac_post_not_public' ];
    $provider = sac_seo_provider_info()['provider'];
    $url = sac_has_wumetax_seo_core() ? Wumetax_SEO_Core_v120::sitemap_url() : home_url( [ 'wordpress' => '/wp-sitemap.xml', 'seopress' => '/sitemaps.xml', 'all_in_one_seo' => '/sitemap.xml' ][ $provider ] ?? '/sitemap_index.xml' );
    $url = apply_filters( 'sac_diagnostic_sitemap_url', $url, $provider );
    $key = 'sac_sitemap_' . md5( $url . ':' . $target );
    $cached = get_transient( $key );
    if ( is_array( $cached ) ) return $cached;
    $root = sac_public_sitemap_document( $url );
    $result = [ 'source' => 'public_sitemap', 'root_url' => $url, 'target_url' => $target, 'checked_at' => gmdate( DATE_ATOM ),
        'checked' => [], 'state' => 'unavailable', 'found' => null ];
    if ( empty( $root['xml']['valid'] ) ) {
        return $result + [ 'http_status' => $root['status'] ?? null, 'code' => 'sac_sitemap_fetch_failed', 'reason' => $root['error'] ?? ( $root['xml']['issues'] ?? null ) ];
    }
    $children = ! empty( $root['xml']['index'] ) ? $root['xml']['urls'] : [];
    usort( $children, static function ( $a, $b ) use ( $post ) {
        $type = $post->post_type;
        $priority = static fn( $url ) => str_contains( $url, 'posts-' . $type . '-' ) || str_contains( $url, $type . '-sitemap' ) ? 0 : 1;
        return $priority( $a ) <=> $priority( $b );
    } );
    $failed = false;
    $found = false;
    foreach ( $children ? array_slice( $children, 0, 4 ) : [ $url ] as $source ) {
        $data = $source === $url ? $root : sac_public_sitemap_document( $source );
        $valid = ! empty( $data['xml']['valid'] ) && empty( $data['xml']['index'] );
        $result['checked'][] = [ 'url' => $source, 'status' => $data['status'] ?? null, 'valid' => $valid ];
        if ( ! $valid ) $failed = true;
        if ( $valid && in_array( $target, $data['xml']['urls'], true ) ) {
            $found = true;
            $result['matched_sitemap'] = $source;
            break;
        }
    }
    $complete = ! $failed && count( $children ) <= 4;
    $result['state'] = $found || $complete ? 'verified' : 'warning';
    $result['found'] = $found ? true : ( $complete ? false : null );
    $result['children_total'] = count( $children );
    set_transient( $key, $result, 5 * MINUTE_IN_SECONDS );
    return $result;
}

function sac_search_console_data( $post, $params ) {
    if ( ! get_option( 'sac_gsc_read', false ) ) return [ 'state' => 'unavailable', 'code' => 'gsc_disabled' ];
    if ( ! current_user_can( 'manage_options' ) ) return [ 'state' => 'permission_denied' ];
    if ( ! sac_has_wumetax_seo_core() ) return [ 'state' => 'unavailable', 'code' => 'gsc_not_connected' ];
    return Wumetax_SEO_Core_v120::connector_search_data( $post->ID, $params );
}

function sac_analyze_content( $request, $target ) {
    $grant = sac_authenticate( $request );
    $post = $target['post'];
    $include = $request->get_param( 'include' ) ?? [ 'fields', 'outline', 'seo' ];
    $result = [ 'basic' => sac_content_result( $post, $target['user'] ), 'sections' => [] ];
    foreach ( array_unique( $include ) as $section ) {
        $scope = [ 'seo' => 'seo:read', 'frontend' => 'seo:read', 'sitemap' => 'seo:read', 'gsc' => 'seo:read', 'faq' => 'faq:read', 'media' => 'media:read' ][ $section ] ?? 'content:read';
        if ( is_wp_error( sac_oauth_require_scope( $grant, $scope ) ) ) {
            $result['sections'][ $section ] = [ 'state' => 'permission_denied' ];
            continue;
        }
        switch ( $section ) {
            case 'content':
                $result['sections'][ $section ] = strlen( $post->post_content ) <= sac_review_max_bytes()
                    ? [ 'state' => 'verified', 'data' => $post->post_content ] : [ 'state' => 'unavailable', 'code' => 'sac_content_too_large' ];
                break;
            case 'fields':
                $fields = sac_content_result( $post, $target['user'], true );
                $keys = [ 'custom_fields', 'field_guidance', 'excerpt', 'slug' ];
                if ( in_array( 'taxonomy:read', $grant['scopes'], true ) ) $keys[] = 'terms';
                $result['sections'][ $section ] = [ 'state' => 'verified', 'data' => array_intersect_key( $fields, array_fill_keys( $keys, true ) ),
                    'capabilities' => sac_content_description( $target ) ];
                break;
            case 'outline':
                $blocks = sac_list_blocks( $post );
                $result['sections'][ $section ] = is_wp_error( $blocks ) ? [ 'state' => 'unavailable', 'code' => $blocks->get_error_code() ] : [ 'state' => 'verified', 'data' => $blocks ];
                break;
            case 'faq':
                $result['sections'][ $section ] = [ 'state' => 'verified', 'data' => sac_content_faq( $request, $post->ID, false ) ];
                break;
            case 'seo':
                $result['sections'][ $section ] = [ 'state' => sac_seo_adapter() ? 'verified' : 'unsupported', 'data' => sac_get_seo_fields( $post->ID ) ];
                break;
            case 'media':
                $id = (int) get_post_thumbnail_id( $post );
                $result['sections'][ $section ] = $id && ! user_can( $target['user'], 'read_post', $id )
                    ? [ 'state' => 'permission_denied' ] : [ 'state' => 'verified', 'data' => $id && wp_attachment_is_image( $id ) ? sac_media_item( $id ) : null ];
                break;
            case 'frontend':
                $result['sections'][ $section ] = sac_public_seo( $post );
                break;
            case 'sitemap':
                $result['sections'][ $section ] = sac_public_sitemap( $post );
                break;
            case 'gsc':
                $result['sections'][ $section ] = sac_search_console_data( $post, $request->get_query_params() );
                break;
        }
    }
    return $result;
}

add_action( 'rest_api_init', static function () {
    foreach ( sac_diagnostic_definitions() as $tool ) {
        $route = strtr( $tool['path'], [ '{post_type}' => '(?P<post_type>[a-z0-9_-]+)', '{id}' => '(?P<id>\d+)' ] );
        register_rest_route( SAC_NAMESPACE, $route, [
            'methods' => 'GET', 'permission_callback' => 'sac_permission_read',
            'callback' => static function ( $request ) use ( $tool ) {
                $params = $request->get_url_params();
                $target = sac_content_target( $request, $params['post_type'], 'read', (int) $params['id'] );
                if ( is_wp_error( $target ) ) return $target;
                if ( $tool['name'] === 'audit_content_seo' ) return sac_public_seo( $target['post'] );
                if ( $tool['name'] === 'get_content_sitemap_status' ) return sac_public_sitemap( $target['post'] );
                return sac_analyze_content( $request, $target );
            },
        ] );
    }
} );
