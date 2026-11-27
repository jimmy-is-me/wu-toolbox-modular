<?php
defined( 'ABSPATH' ) || exit;

function sac_change_cache_context( $post ) {
    $urls = [ sac_public_post_url( $post ), get_post_type_archive_link( $post->post_type ) ];
    $terms = wp_get_object_terms( $post->ID, get_object_taxonomies( $post->post_type ) );
    foreach ( is_array( $terms ) ? $terms : [] as $term ) {
        $url = get_term_link( $term );
        if ( is_string( $url ) ) $urls[] = $url;
    }
    if ( $post->post_type === 'post' ) $urls[] = get_option( 'page_for_posts' ) ? get_permalink( (int) get_option( 'page_for_posts' ) ) : home_url( '/' );
    return [ 'urls' => array_values( array_unique( array_filter( $urls ) ) ),
        'terms' => is_array( $terms ) ? array_map( static fn( $term ) => (int) $term->term_taxonomy_id, $terms ) : [] ];
}

function sac_change_purge( $post, $previous ) {
    $started = gmdate( DATE_ATOM );
    $current = sac_change_cache_context( $post );
    $urls = array_values( array_unique( array_merge( $previous['urls'] ?? [], $current['urls'] ) ) );
    $local = [ 'state' => 'not_applicable' ];
    try {
        if ( is_callable( [ 'WUTM_Page_Cache', 'purge_content' ] ) ) {
            $local = WUTM_Page_Cache::purge_content( $post, $previous['terms'] ?? [], $urls );
        }
        if ( has_action( 'litespeed_purge_post' ) ) do_action( 'litespeed_purge_post', $post->ID );
    } catch ( Throwable $error ) {
        $local = [ 'state' => 'failed', 'code' => 'cache_adapter_failed' ];
    }
    foreach ( $urls as $url ) {
        delete_transient( 'sac_rendered_' . md5( $url . ':' . $post->post_modified_gmt ) );
        delete_transient( 'wu_seo_page_' . md5( $url ) );
    }
    return [
        'state' => $local['state'] === 'purged' ? 'verified' : $local['state'],
        'cache_purge_status' => $local['state'], 'affected_urls' => $urls,
        'purged_urls' => $local['purged_urls'] ?? [], 'failed_urls' => $local['failed_urls'] ?? [],
        'local_cache_status' => $local['state'], 'cloudflare_cache_status' => 'unavailable',
        'seo_audit_cache_status' => 'invalidated', 'started_at' => $started, 'completed_at' => gmdate( DATE_ATOM ),
        'error_code' => $local['code'] ?? null, 'warnings' => [ 'cloudflare_purge_not_configured' ],
    ];
}

function sac_verify_document( $html ) {
    $doc = new DOMDocument();
    return $doc->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING ) ? new DOMXPath( $doc ) : null;
}

function sac_verify_text( $text ) {
    return trim( preg_replace( '/[\s\p{Z}]+/u', ' ', html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ?? '' );
}

function sac_verify_urls( $url, $base ) {
    if ( ! is_string( $url ) || trim( $url ) === '' ) return '';
    return untrailingslashit( WP_Http::make_absolute_url( trim( $url ), $base ) );
}

function sac_verify_items( $xpath, $root, $base ) {
    $items = [];
    foreach ( $xpath->query( './/h1|.//h2|.//h3|.//h4|.//p|.//li|.//table|.//a[@href]', $root ) as $element ) {
        $text = $element->nodeName === 'a' ? sac_verify_urls( $element->getAttribute( 'href' ), $base ) : sac_verify_text( $element->textContent );
        if ( $text !== '' ) {
            $key = $element->nodeName . ':' . hash( 'sha256', $text );
            $items[ $key ] = ( $items[ $key ] ?? 0 ) + 1;
        }
    }
    return $items;
}

function sac_verify_check( $expected, $actual, $reason = 'value_comparison' ) {
    return [ 'state' => $expected === $actual ? 'verified' : 'mismatch', 'expected' => $expected, 'actual' => $actual, 'reason' => $reason ];
}

function sac_verify_section( $checks ) {
    $states = array_column( $checks, 'state' );
    return [ 'state' => ! $states ? 'not_applicable' : ( in_array( 'mismatch', $states, true ) ? 'mismatch' :
        ( in_array( 'unavailable', $states, true ) ? 'partial' : 'verified' ) ), 'items' => $checks ];
}

function sac_verify_public_change( $post, $payload ) {
    $url = sac_public_post_url( $post );
    $checked = gmdate( DATE_ATOM );
    $unavailable = [ 'state' => 'unavailable', 'checked_at' => $checked ];
    $empty = [ 'structured_data_verification' => $unavailable, 'media_verification' => $unavailable ];
    if ( ! $url ) return [ 'frontend_verification' => [ 'reason' => 'content_not_public' ] + $unavailable ] + $empty;
    $response = sac_public_fetch( $url );
    if ( ! empty( $response['error'] ) || ! empty( $response['challenge'] ) || ( $response['status'] ?? 0 ) !== 200
        || ! class_exists( 'DOMDocument' ) || ! preg_match( '~^(text/html|application/xhtml\+xml)(?:;|$)~i', $response['type'] ?? '' ) ) {
        return [ 'frontend_verification' => [ 'reason' => ! empty( $response['challenge'] ) ? 'cloudflare_challenge' : 'public_response_unavailable',
            'http_status' => $response['status'] ?? null, 'recommended_action' => 'check_public_access' ] + $unavailable ] + $empty;
    }
    $doc = sac_verify_document( $response['body'] );
    if ( ! $doc ) return [ 'frontend_verification' => [ 'reason' => 'html_unreadable' ] + $unavailable ] + $empty;
    $page = $response['url'];
    $values = $payload['values'];
    $expected = $payload['expected'];
    $checks = $schema = $media = [];
    $selectors = [
        'title' => '//head/title',
        'description' => '//head/meta[@name="description"]/@content',
        'canonical' => '//head/link[@rel="canonical"]/@href',
        'og_title' => '//head/meta[@property="og:title"]/@content',
        'og_description' => '//head/meta[@property="og:description"]/@content',
        'og_image_id' => '//head/meta[@property="og:image"]/@content',
    ];
    $planned = $values['seo'] ?? [];
    foreach ( $selectors as $field => $selector ) {
        if ( ! array_key_exists( $field, $planned ) ) continue;
        $nodes = $doc->query( $selector );
        $actual = $nodes->item( 0 )?->textContent ?? '';
        $wanted = $planned[ $field ];
        if ( in_array( $field, [ 'canonical', 'og_image_id' ], true ) ) {
            if ( $field === 'og_image_id' ) $wanted = $planned[ $field ] ? wp_get_attachment_url( $planned[ $field ] ) : '';
            if ( $wanted === '' || $wanted === false ) {
                $checks[ $field ] = [ 'state' => 'unavailable', 'reason' => 'provider_default_or_missing_image' ];
                continue;
            }
            $actual = sac_verify_urls( $actual, $page );
            $wanted = sac_verify_urls( $wanted, $page );
        } else {
            $actual = sac_verify_text( $actual );
            $wanted = sac_verify_text( $wanted );
        }
        $checks[ $field ] = $wanted === '' ? [ 'state' => 'unavailable', 'reason' => 'provider_default' ] : sac_verify_check( $wanted, $actual );
        if ( $field === 'canonical' && $nodes->length !== 1 ) $checks['canonical_count'] = sac_verify_check( 1, $nodes->length );
    }
    $robots = $doc->evaluate( 'string(//head/meta[@name="robots"]/@content)' ) . ',' . ( $response['robots'] ?? '' );
    foreach ( [ 'noindex', 'nofollow' ] as $flag ) {
        if ( array_key_exists( $flag, $planned ) ) $checks[ $flag ] = sac_verify_check( $planned[ $flag ], (bool) preg_match( '/\b' . $flag . '\b/i', $robots ) );
    }
    $root = null;
    foreach ( [ '//main//article', '//article', '//main' ] as $selector ) {
        $root = $doc->query( $selector )->item( 0 );
        if ( $root ) break;
    }
    if ( isset( $expected['content']['content'] ) ) {
        $checks['content'] = [ 'state' => 'unavailable', 'reason' => 'no_comparable_static_content' ];
        $before = sac_verify_document( '<body>' . ( $payload['before']['content']['content'] ?? '' ) . '</body>' );
        $after = sac_verify_document( '<body>' . $expected['content']['content'] . '</body>' );
        if ( $root && $before && $after ) {
            $old = sac_verify_items( $before, $before->query( '//body' )->item( 0 ), $page );
            $new = sac_verify_items( $after, $after->query( '//body' )->item( 0 ), $page );
            $seen = sac_verify_items( $doc, $root, $page );
            $changes = $matched = 0;
            foreach ( array_keys( $old + $new ) as $key ) {
                $n = $new[ $key ] ?? 0;
                if ( ( $old[ $key ] ?? 0 ) === $n ) continue;
                $changes++;
                if ( ( $seen[ $key ] ?? 0 ) === $n ) $matched++;
            }
            if ( $changes ) $checks['content'] = sac_verify_check( $changes, $matched, 'changed_static_headings_tables_text_and_links' );
        }
    }
    foreach ( [ 'title' => 'h1', 'excerpt' => 'p' ] as $field => $tag ) {
        if ( ! isset( $expected['content'][ $field ] ) ) continue;
        $text = sac_verify_text( $expected['content'][ $field ] );
        $found = false;
        if ( $root ) foreach ( $doc->query( './/' . $tag, $root ) as $node ) $found = $found || sac_verify_text( $node->textContent ) === $text;
        $checks[ 'content_' . $field ] = $root && $text !== '' ? sac_verify_check( true, $found ) : [ 'state' => 'unavailable', 'reason' => 'no_comparable_public_field' ];
    }
    if ( array_key_exists( 'faqs', $expected ) ) {
        $visible = $root ? sac_verify_text( $root->textContent ) : '';
        $questions = [];
        foreach ( $doc->query( '//script[@type="application/ld+json"]' ) as $script ) {
            $data = json_decode( $script->textContent, true, 24 );
            if ( ! is_array( $data ) ) continue;
            $visit = static function ( $node ) use ( &$visit, &$questions ) {
                if ( in_array( 'FAQPage', (array) ( $node['@type'] ?? [] ), true ) ) {
                    foreach ( (array) ( $node['mainEntity'] ?? [] ) as $item ) {
                        if ( is_string( $item['name'] ?? null ) && is_string( $item['acceptedAnswer']['text'] ?? null ) ) {
                            $questions[ sac_verify_text( $item['name'] ) ] = sac_verify_text( $item['acceptedAnswer']['text'] );
                        }
                    }
                }
                foreach ( $node as $child ) if ( is_array( $child ) ) $visit( $child );
            };
            $visit( $data );
        }
        $shown = $marked = 0;
        foreach ( $expected['faqs'] as $faq ) {
            $q = sac_verify_text( $faq['question'] );
            $a = sac_verify_text( $faq['answer'] );
            if ( str_contains( $visible, $q ) && str_contains( $visible, $a ) ) $shown++;
            if ( ( $questions[ $q ] ?? null ) === $a ) $marked++;
        }
        if ( $expected['faqs'] ) {
            $checks['faq_visible'] = $root ? sac_verify_check( count( $expected['faqs'] ), $shown ) : [ 'state' => 'unavailable', 'reason' => 'content_region_unknown' ];
            $schema['faq_page'] = sac_verify_check( count( $expected['faqs'] ), $marked );
        } else {
            $schema['faq_page'] = [ 'state' => 'unavailable', 'reason' => 'other_faq_providers_may_render' ];
        }
    }
    $images = [];
    if ( isset( $expected['content']['featured_image_id'] ) ) $images['featured'] = $expected['content']['featured_image_id'];
    if ( isset( $expected['media']['alt_text'] ) ) $images['alt'] = $expected['media']['id'];
    foreach ( $images as $kind => $id ) {
        $media[ $kind ] = [ 'state' => 'unavailable', 'reason' => 'image_or_theme_markup_unknown' ];
        if ( ! $id ) continue;
        $sizes = array_keys( wp_get_attachment_metadata( $id )['sizes'] ?? [] );
        $urls = array_filter( array_map( static fn( $size ) => wp_get_attachment_image_url( $id, $size ), array_merge( [ 'full' ], $sizes ) ) );
        foreach ( $doc->query( '//img[@src]' ) as $img ) {
            $class = $img->getAttribute( 'class' );
            if ( $kind === 'featured' && ! preg_match( '/(?:^|\s)(?:wp-post-image|attachment-post-thumbnail)(?:\s|$)/', $class ) ) continue;
            $matches = in_array( sac_verify_urls( $img->getAttribute( 'src' ), $page ), $urls, true ) || preg_match( '/(?:^|\s)wp-image-' . $id . '(?:\s|$)/', $class );
            if ( $kind === 'featured' ) {
                $media[ $kind ] = sac_verify_check( true, (bool) $matches );
                if ( $matches ) break;
            } elseif ( $matches ) {
                $media[ $kind ] = sac_verify_check( $expected['media']['alt_text'], $img->getAttribute( 'alt' ) );
                if ( $media[ $kind ]['state'] === 'verified' ) break;
            }
        }
    }
    if ( isset( $checks['og_image_id'] ) ) $media['og_image'] = $checks['og_image_id'];
    foreach ( array_intersect( [ 'terms', 'custom_fields', 'slug', 'author_id', 'parent_id' ], array_keys( $expected['content'] ?? [] ) ) as $field ) {
        $checks[ 'content_' . $field ] = [ 'state' => 'unavailable', 'reason' => 'theme_output_not_standardized' ];
    }
    if ( array_intersect( [ 'title', 'caption', 'description' ], array_keys( $expected['media'] ?? [] ) ) ) {
        $media['attachment_text'] = [ 'state' => 'unavailable', 'reason' => 'theme_output_not_standardized' ];
    }
    $section = sac_verify_section( $checks ) + [ 'checked_at' => $checked, 'http_status' => 200, 'url' => $page, 'robots' => trim( $robots, ',' ),
        'cache_headers' => $response['cache_headers'] ?? [] ];
    if ( $section['state'] === 'mismatch' && preg_match( '/\b(?:hit|stale)\b/i', implode( ' ', $section['cache_headers'] ) ) ) {
        $section['state'] = 'stale_cache';
        $section['recommended_action'] = 'retry_cache_purge_and_verify';
    }
    return [ 'frontend_verification' => $section, 'structured_data_verification' => sac_verify_section( $schema ) + [ 'checked_at' => $checked ],
        'media_verification' => sac_verify_section( $media ) + [ 'checked_at' => $checked ] ];
}
