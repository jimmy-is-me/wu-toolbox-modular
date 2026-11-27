<?php
defined( 'ABSPATH' ) || exit;

function sac_media_rights( $input ) {
    $rights = [];
    foreach ( [ 'creator', 'license', 'attribution', 'source_type' ] as $field ) {
        if ( is_string( $input[ $field ] ?? null ) ) $rights[ $field ] = sanitize_text_field( substr( $input[ $field ], 0, 1200 ) );
    }
    if ( isset( $input['generated_by_ai'] ) ) $rights['generated_by_ai'] = rest_sanitize_boolean( $input['generated_by_ai'] );
    if ( is_string( $input['source_url'] ?? null ) ) {
        $url = esc_url_raw( substr( $input['source_url'], 0, 2000 ), [ 'https', 'http' ] );
        $parts = wp_parse_url( $url );
        if ( isset( $parts['host'] ) && ! isset( $parts['user'] ) && ! isset( $parts['pass'] ) ) $rights['source_url'] = strtok( $url, '?#' );
    }
    return $rights;
}

function sac_media_store_once( $path, $fields, $grant, $key = '' ) {
    $sha = hash_file( 'sha256', $path );
    if ( ! $sha ) return sac_oauth_error( 'sac_upload_read_failed', '無法讀取上傳檔案。', 400 );
    if ( isset( $fields['sha256'] ) && ( ! is_string( $fields['sha256'] ) || ! hash_equals( $sha, strtolower( $fields['sha256'] ) ) ) ) {
        return sac_oauth_error( 'sac_upload_digest_mismatch', '圖片 SHA-256 不符。', 409 );
    }
    if ( $key === '' && ! rest_sanitize_boolean( $fields['deduplicate'] ?? false ) ) return sac_media_store_image( $path, $fields );
    if ( ! is_string( $key ) || ( $key !== '' && ! preg_match( '/^[a-zA-Z0-9_-]{16,80}$/D', $key ) ) ) return sac_content_error( 'idempotency_key', '請提供 16 至 80 字元的識別碼。' );
    $options = array_intersect_key( $fields, array_flip( [ 'filename', 'title', 'description', 'caption', 'alt_text', 'quality', 'max_width', 'max_height', 'convert_webp' ] ) );
    $payload_hash = sac_review_hash( [ $sha, sac_change_value( $options ), sac_change_value( sac_media_rights( $fields ) ) ] );
    $legacy_marker = hash( 'sha256', $grant['id'] . ':' . ( $key ?: $payload_hash ) );
    $marker = hash( 'sha256', 'client:' . $grant['client'] . ':' . $grant['user_id'] . ':' . ( $key ?: $payload_hash ) );
    $lock = sac_post_write_lock( 'media:' . $marker );
    if ( is_wp_error( $lock ) ) return $lock;
    try {
        $posts = get_posts( [ 'post_type' => 'attachment', 'post_status' => 'inherit', 'author' => $grant['user_id'],
            'meta_query' => [ [ 'key' => '_sac_media_receipt', 'value' => [ $marker, $legacy_marker ], 'compare' => 'IN' ] ], 'numberposts' => 1, 'fields' => 'ids' ] );
        if ( $posts ) {
            $id = (int) $posts[0];
            if ( ! wp_attachment_is_image( $id ) || ! current_user_can( 'read_post', $id ) || ! current_user_can( 'edit_post', $id ) ) {
                return sac_oauth_error( 'media_not_found', '無法再次讀取原媒體。', 404 );
            }
            if ( get_post_meta( $id, '_sac_media_payload', true ) !== $payload_hash ) return sac_oauth_error( 'sac_idempotency_conflict', '同一識別碼已用於不同圖片或欄位。', 409 );
            return [ 'reused' => true ] + sac_media_item( $id );
        }
        return sac_media_store_image( $path, $fields, 0, [ '_sac_media_receipt' => $marker, '_sac_media_payload' => $payload_hash ] );
    } finally {
        sac_post_write_unlock( $lock );
    }
}

/** Parts are site-local OAuth rows with short expiry. No public paths or attachment are created until assembly. */
function sac_media_chunk( $fields, $grant ) {
    $key = $fields['upload_id'] ?? '';
    $sha = $fields['sha256'] ?? '';
    $count = $fields['chunk_count'] ?? 0;
    $index = $fields['chunk_index'] ?? null;
    if ( ! is_string( $key ) || ! preg_match( '/^[a-zA-Z0-9_-]{16,80}$/D', $key )
        || ! is_string( $sha ) || ! preg_match( '/^[a-f0-9]{64}$/Di', $sha ) || ! is_int( $count ) || $count < 1 || $count > 24 ) {
        return sac_content_error( 'upload_id', '請提供上傳識別碼、完整檔案 SHA-256 及分段總數（1 至 24）。' );
    }
    $sha = strtolower( $sha );
    $finish = rest_sanitize_boolean( $fields['complete_upload'] ?? false );
    $secret = 'upload:' . $grant['id'] . ':' . $key;
    $lock = sac_post_write_lock( $secret );
    if ( is_wp_error( $lock ) ) return $lock;
    try {
        $session = sac_oauth_row( 'media_parts', $secret );
        if ( ! $session ) {
            if ( $finish ) return sac_oauth_error( 'upload_not_completed', '尚無分段資料。', 409 );
            if ( ! sac_oauth_rate( 'media_parts_new', 6, HOUR_IN_SECONDS, (string) $grant['id'] ) ) return sac_oauth_error( 'sac_rate_limit', '分段工作階段過多，請稍後再試。', 429 );
            $expires = time() + 30 * MINUTE_IN_SECONDS;
            if ( ! sac_oauth_insert( 'media_parts', $secret, $expires, [ 'count' => $count, 'sha' => $sha ], $grant['client'], $grant['id'], $grant['user_id'] ) ) {
                return sac_oauth_error( 'sac_upload_session_failed', '無法建立分段工作階段。', 500 );
            }
            $session = sac_oauth_row( 'media_parts', $secret );
        }
        if ( ! $session || (int) $session['expires'] <= time() ) return sac_oauth_error( 'upload_session_expired', '分段上傳已過期。請使用新的識別碼。', 409 );
        $payload = $session['payload'];
        if ( $payload['count'] !== $count || $payload['sha'] !== $sha ) return sac_oauth_error( 'sac_idempotency_conflict', '分段總數或檔案 SHA-256 已變更。', 409 );
        if ( ! $finish ) {
            $encoded = $fields['base64'] ?? null;
            if ( ! is_int( $index ) || $index < 0 || $index >= $count || ! is_string( $encoded ) || strlen( $encoded ) > 699052 ) {
                return sac_content_error( 'chunk_index', '請提供有效的零起算序號及至多 512 KiB 的分段。' );
            }
            $bytes = base64_decode( $encoded, true );
            if ( $bytes === false || $bytes === '' || strlen( $bytes ) > 512 * KB_IN_BYTES ) return sac_content_error( 'base64', '分段 Base64 無效。' );
            $part = sac_oauth_row( 'media_part', $secret . ':' . $index );
            $encoded = base64_encode( $bytes );
            if ( $part && $part['payload']['base64'] !== $encoded ) return sac_oauth_error( 'sac_idempotency_conflict', '此序號已有不同資料。', 409 );
            if ( ! $part ) {
                global $wpdb;
                $table = sac_oauth_table();
                $used = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(CHAR_LENGTH(payload)),0) FROM $table WHERE kind='media_part' AND family=%d", $session['id'] ) );
                if ( $used + strlen( $encoded ) > 4 * ceil( sac_media_max_bytes() / 3 ) + 768 ) return sac_oauth_error( 'sac_image_size', '組合後的檔案超過上限。', 413 );
                if ( ! sac_oauth_insert( 'media_part', $secret . ':' . $index, (int) $session['expires'], [ 'base64' => $encoded ], $grant['client'], $session['id'], $grant['user_id'] ) ) {
                    return sac_oauth_error( 'sac_upload_chunk_failed', '無法儲存分段。', 500 );
                }
                // Use the payload length below as the authoritative cap, regardless of receipt-write recovery.
            }
            return [ 'status' => 'chunk_received', 'chunk_index' => $index, 'chunk_count' => $count ];
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $tmp = wp_tempnam( 'sac-assembled' );
        if ( ! $tmp ) return sac_oauth_error( 'sac_upload_temp_failed', '無法建立上傳暫存檔。', 500 );
        try {
            $total = 0;
            for ( $part_index = 0; $part_index < $count; $part_index++ ) {
                $part = sac_oauth_row( 'media_part', $secret . ':' . $part_index );
                if ( ! $part ) return new WP_Error( 'upload_not_completed', '尚缺少分段。', [ 'status' => 409, 'chunk_index' => $part_index ] );
                $bytes = base64_decode( $part['payload']['base64'], true );
                $total += strlen( $bytes );
                if ( $total > sac_media_max_bytes() ) return sac_oauth_error( 'sac_image_size', '組合後的檔案超過上限。', 413 );
                if ( file_put_contents( $tmp, $bytes, FILE_APPEND ) !== strlen( $bytes ) ) return sac_oauth_error( 'sac_upload_assemble_failed', '無法組合圖片。', 500 );
            }
            return sac_media_store_once( $tmp, $fields, $grant, $fields['idempotency_key'] ?? $key );
        } finally {
            wp_delete_file( $tmp );
        }
    } finally {
        sac_post_write_unlock( $lock );
    }
}
