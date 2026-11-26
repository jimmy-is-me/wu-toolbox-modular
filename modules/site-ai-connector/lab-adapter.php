<?php
defined( 'ABSPATH' ) || exit;

// Adapter for the separately installed Lab system. It does not register content types or taxonomies.
add_filter( 'sac_content_field_definitions', static function ( $fields, $slug ) {
    if ( $slug !== 'wumetax_lab' || ! function_exists( 'wulab_project_settings_box' )
        || ! is_object_in_taxonomy( $slug, 'wulab_type' ) || ! is_object_in_taxonomy( $slug, 'wulab_tech' ) ) return $fields;
    $definitions = [
        'title_en_us' => [ '_wulab_title_en_us', '英文標題', 'string', 'text' ],
        'demo_url' => [ '_wulab_demo_url', 'Live Demo URL', 'string', 'url' ],
        'repo_url' => [ '_wulab_repo_url', 'GitHub／原始碼網址', 'string', 'url' ],
        'status' => [ '_wulab_status', '專案狀態', 'string', 'status' ],
        'order' => [ '_wulab_order', '專案排序', 'integer', 'number' ],
        'challenge' => [ '_wulab_challenge', 'Challenge', 'string', 'long' ],
        'approach' => [ '_wulab_approach', 'Approach', 'string', 'long' ],
        'result' => [ '_wulab_result', 'Result', 'string', 'long' ],
    ];
    $statuses = [ 'live', 'building', 'prototype', 'archived' ];
    foreach ( $definitions as $name => [ $key, $label, $type, $format ] ) {
        if ( isset( $fields[ $name ] ) ) continue;
        $fields[ $name ] = [
            'label' => $label, 'type' => $type, 'schema' => $format === 'status' ? [ 'enum' => $statuses ] : [],
            'authorize' => static function ( $operation, $user, $post, $post_type ) {
                if ( ! $post ) return user_can( $user, $post_type->cap->create_posts );
                return user_can( $user, $operation === 'read' ? 'read_post' : 'edit_post', $post->ID );
            },
            'validate' => static function ( $value ) use ( $format, $statuses ) {
                if ( $format === 'number' ) return $value >= 0 && $value <= 1000000 ? (int) $value : new WP_Error( 'sac_lab_order', '排序超出範圍。' );
                if ( $format === 'status' ) return in_array( $value, $statuses, true ) ? $value : new WP_Error( 'sac_lab_status', '請選擇已登錄的專案狀態。' );
                if ( $format === 'url' ) {
                    $clean = esc_url_raw( $value, [ 'http', 'https' ] );
                    return $value === '' || wp_parse_url( $clean, PHP_URL_HOST ) ? $clean : new WP_Error( 'sac_lab_url', '請提供絕對 HTTP 或 HTTPS 網址。' );
                }
                return $format === 'long' ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
            },
            'read' => static function ( $post ) use ( $key, $type ) {
                $value = get_post_meta( $post->ID, $key, true );
                return $type === 'integer' ? ( $value === '' ? 100 : (int) $value ) : $value;
            },
            'write' => static function ( $id, $value ) use ( $key ) {
                if ( $value === '' ) delete_post_meta( $id, $key );
                else update_post_meta( $id, $key, wp_slash( $value ) );
                return (string) get_post_meta( $id, $key, true ) === (string) $value;
            },
        ];
    }
    return $fields;
}, 10, 2 );
