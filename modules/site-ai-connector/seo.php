<?php
defined( 'ABSPATH' ) || exit;

function sac_has_wumetax_seo_core() {
    return class_exists( 'Wumetax_SEO_Core_v120' );
}

function sac_seo_adapter() {
    if ( sac_has_wumetax_seo_core() ) return [
        'source' => 'wumetax_seo_core', 'title' => '_wu_seo_title', 'description' => '_wu_seo_description',
        'canonical' => '_wu_seo_canonical', 'noindex' => '_wu_seo_noindex', 'nofollow' => '_wu_seo_nofollow',
        'focus_keyword' => '_sac_focus_keyword', 'og_title' => '_wu_seo_og_title', 'og_description' => '_wu_seo_og_desc', 'og_image_id' => '_wu_seo_og_image',
    ];
    if ( defined( 'WPSEO_VERSION' ) ) return [
        'source' => 'yoast', 'title' => '_yoast_wpseo_title', 'description' => '_yoast_wpseo_metadesc',
        'canonical' => '_yoast_wpseo_canonical', 'noindex' => '_yoast_wpseo_meta-robots-noindex', 'nofollow' => '_yoast_wpseo_meta-robots-nofollow',
        'focus_keyword' => '_yoast_wpseo_focuskw', 'og_title' => '_yoast_wpseo_opengraph-title', 'og_description' => '_yoast_wpseo_opengraph-description',
        'og_image_id' => '_yoast_wpseo_opengraph-image-id', 'social_image_url' => '_yoast_wpseo_opengraph-image',
    ];
    if ( class_exists( 'RankMath' ) ) return [
        'source' => 'rank_math', 'title' => 'rank_math_title', 'description' => 'rank_math_description',
        'canonical' => 'rank_math_canonical_url', 'robots' => 'rank_math_robots', 'focus_keyword' => 'rank_math_focus_keyword',
        'og_title' => 'rank_math_facebook_title', 'og_description' => 'rank_math_facebook_description',
        'og_image_id' => 'rank_math_facebook_image_id', 'social_image_url' => 'rank_math_facebook_image',
    ];
    if ( defined( 'SEOPRESS_VERSION' ) ) return [
        'source' => 'seopress', 'title' => '_seopress_titles_title', 'description' => '_seopress_titles_desc',
        'canonical' => '_seopress_robots_canonical', 'noindex' => '_seopress_robots_index', 'nofollow' => '_seopress_robots_follow',
        'og_title' => '_seopress_social_fb_title', 'og_description' => '_seopress_social_fb_desc',
        'social_image_url' => '_seopress_social_fb_img',
    ];
    return [];
}

function sac_get_seo_fields( $post_id ) {
    $adapter = sac_seo_adapter();
    $result = [ 'post_id' => $post_id, 'source' => $adapter['source'] ?? null ];
    foreach ( array_diff_key( $adapter, array_flip( [ 'source', 'robots', 'social_image_url' ] ) ) as $field => $key ) {
        $value = get_post_meta( $post_id, $key, true );
        if ( in_array( $field, [ 'noindex', 'nofollow' ], true ) ) {
            $value = $value === '' ? null : in_array( (string) $value, [ '1', 'yes' ], true );
        }
        $result[ $field ] = $field === 'og_image_id' ? (int) $value : $value;
    }
    if ( isset( $adapter['robots'] ) ) {
        $robots = (array) get_post_meta( $post_id, $adapter['robots'], true );
        $result['noindex'] = in_array( 'noindex', $robots, true ) ? true : ( in_array( 'index', $robots, true ) ? false : null );
        $result['nofollow'] = in_array( 'nofollow', $robots, true ) ? true : null;
    }
    if ( isset( $adapter['social_image_url'] ) ) $result['og_image_url'] = get_post_meta( $post_id, $adapter['social_image_url'], true );
    return $result;
}

function sac_update_seo_fields( $post_id, $fields ) {
    $adapter = sac_seo_adapter();
    if ( ! $adapter ) return sac_oauth_error( 'sac_seo_unavailable', '本站沒有啟用受支援的 SEO 外掛。', 409 );
    if ( ! is_array( $fields ) || ! $fields ) return sac_content_error( 'seo', '請提供至少一個 SEO 欄位。' );
    $updates = [];
    foreach ( $fields as $field => $value ) {
        if ( in_array( $field, [ 'noindex', 'nofollow' ], true ) ) {
            if ( ! is_bool( $value ) ) return sac_content_error( $field, '請提供布林值。' );
            if ( isset( $adapter['robots'] ) ) {
                $key = $adapter['robots'];
                $robots = array_values( array_filter( (array) ( $updates[ $key ] ?? get_post_meta( $post_id, $key, true ) ) ) );
                $robots = array_values( array_diff( $robots, $field === 'noindex' ? [ 'index', 'noindex' ] : [ 'follow', 'nofollow' ] ) );
                if ( $value || $field === 'noindex' ) $robots[] = $value ? $field : 'index';
                $updates[ $key ] = $robots;
                continue;
            }
            $updates[ $adapter[ $field ] ] = $adapter['source'] === 'seopress' ? ( $value ? 'yes' : '' )
                : ( $value ? 1 : ( $field === 'noindex' && $adapter['source'] === 'yoast' ? 2 : 0 ) );
            continue;
        }
        if ( ! isset( $adapter[ $field ] ) || in_array( $field, [ 'source', 'robots', 'social_image_url' ], true ) ) {
            if ( $field !== 'og_image_id' || ! isset( $adapter['social_image_url'] ) ) return sac_content_error( $field, '目前的 SEO 外掛未開放此欄位。' );
        }
        if ( $field === 'og_image_id' ) {
            if ( ! is_int( $value ) || $value < 0 || ( $value && ( ! wp_attachment_is_image( $value ) || ! current_user_can( 'read_post', $value ) ) ) ) {
                return sac_content_error( $field, '找不到可使用的圖片。' );
            }
            if ( isset( $adapter[ $field ] ) ) $updates[ $adapter[ $field ] ] = $value ?: '';
            if ( isset( $adapter['social_image_url'] ) ) $updates[ $adapter['social_image_url'] ] = $value ? wp_get_attachment_url( $value ) : '';
            continue;
        }
        if ( ! is_string( $value ) || strlen( $value ) > 4500 ) return sac_content_error( $field, '文字格式或長度錯誤。' );
        if ( $field === 'canonical' ) {
            $clean = esc_url_raw( $value, [ 'https', 'http' ] );
            if ( $value !== '' && ( ! $clean || ! wp_parse_url( $clean, PHP_URL_HOST ) ) ) return sac_content_error( $field, '請提供絕對 HTTP 或 HTTPS 網址。' );
        } else {
            $clean = $field === 'description' || $field === 'og_description' ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
        }
        $updates[ $adapter[ $field ] ] = $clean;
    }
    foreach ( $updates as $key => $value ) {
        if ( $value === '' ) delete_post_meta( $post_id, $key );
        else update_post_meta( $post_id, $key, wp_slash( $value ) );
        $stored = get_post_meta( $post_id, $key, true );
        if ( is_array( $value ) ? $stored !== $value : (string) $stored !== (string) $value ) {
            return new WP_Error( 'sac_partial_save', '部分 SEO 欄位可能已寫入，請到後台核對。', [ 'status' => 500, 'id' => $post_id ] );
        }
    }
    clean_post_cache( $post_id );
    do_action( 'sac_content_seo_updated', $post_id, $adapter['source'] );
    return true;
}
