<?php
defined( 'ABSPATH' ) || exit;

function sac_media_max_bytes() {
    return min( 8 * MB_IN_BYTES, wp_max_upload_size() );
}

function sac_media_image( $path ) {
    $length = is_file( $path ) ? filesize( $path ) : 0;
    if ( ! $length || $length > sac_media_max_bytes() ) return sac_oauth_error( 'sac_image_size', '圖片超出檔案大小限制。', 413 );
    $image = @getimagesize( $path );
    $formats = [ IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp' ];
    if ( ! $image || empty( $formats[ $image[2] ] ) ) return sac_oauth_error( 'sac_image_type', '請提供有效的 PNG、JPG 或 WebP。', 415 );
    if ( ! $image[0] || ! $image[1] || $image[0] > 6000 || $image[1] > 6000 || $image[0] * $image[1] > 16000000 ) {
        return sac_oauth_error( 'sac_image_dimensions', '圖片最大為 6,000 像素／邊及 1,600 萬總像素。', 413 );
    }
    return [ 'extension' => $formats[ $image[2] ], 'mime' => $image['mime'] ];
}

function sac_media_public_ipv4( $ip ) {
    $flags = FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
    if ( defined( 'FILTER_FLAG_GLOBAL_RANGE' ) ) $flags |= FILTER_FLAG_GLOBAL_RANGE;
    return (bool) filter_var( $ip, FILTER_VALIDATE_IP, $flags )
        && ! preg_match( '/^(?:0\.|100\.(?:6[4-9]|[7-9]\d|1[01]\d|12[0-7])\.|192\.(?:0\.(?:0|2)|88\.99)\.|198\.(?:18\.|19\.|51\.100\.)|203\.0\.113\.|(?:22[4-9]|23\d|24\d|25\d)\.)/', $ip );
}

function sac_media_download( $url, $target ) {
    $reject = sac_oauth_error( 'sac_image_url', '請提供可直接下載圖片的公開 HTTPS 網址。', 400 );
    if ( ! is_string( $url ) || strlen( $url ) > 1800 || ! function_exists( 'curl_init' ) || ! function_exists( 'dns_get_record' )
        || ! filter_var( $url, FILTER_VALIDATE_URL ) ) return $reject;
    $parts = wp_parse_url( $url );
    $host = strtolower( $parts['host'] ?? '' );
    if ( ( $parts['scheme'] ?? '' ) !== 'https' || ( $parts['port'] ?? 443 ) !== 443 || isset( $parts['user'] )
        || isset( $parts['pass'] ) || isset( $parts['fragment'] ) || ! preg_match( '/^[a-z0-9][a-z0-9.-]*\.[a-z][a-z0-9-]*$/D', $host ) ) return $reject;
    // Restrict to global IPv4, and pin the checked address for the entire TLS connection.
    $records = @dns_get_record( $host, DNS_A );
    if ( ! $records ) return $reject;
    $ips = array_column( $records, 'ip' );
    foreach ( $ips as $ip ) {
        if ( ! sac_media_public_ipv4( $ip ) ) return $reject;
    }
    if ( ! $ips ) return $reject;
    $file = fopen( $target, 'wb' );
    if ( ! $file ) return $reject;
    $curl = curl_init( $url );
    $size = 0;
    $max = sac_media_max_bytes();
    try {
        $protocol = defined( 'CURLOPT_PROTOCOLS_STR' ) ? [ CURLOPT_PROTOCOLS_STR => 'HTTPS' ] : [ CURLOPT_PROTOCOLS => CURLPROTO_HTTPS ];
        $ca_bundle = ABSPATH . WPINC . '/certificates/ca-bundle.crt';
        if ( is_readable( $ca_bundle ) ) $protocol[ CURLOPT_CAINFO ] = $ca_bundle;
        $configured = curl_setopt_array( $curl, $protocol + [ CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROXY => '', CURLOPT_RESOLVE => [ $host . ':443:' . $ips[0] ], CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_WRITEFUNCTION => static function ( $handle, $chunk ) use ( $file, &$size, $max ) {
                $size += strlen( $chunk );
                return $size > $max ? 0 : (int) fwrite( $file, $chunk );
            },
        ] );
        $ok = $configured && curl_exec( $curl ) && (int) curl_getinfo( $curl, CURLINFO_RESPONSE_CODE ) === 200;
        return $ok ? true : ( $size > $max ? sac_oauth_error( 'sac_image_size', '遠端圖片超出大小限制。', 413 ) : $reject );
    } finally {
        if ( PHP_VERSION_ID < 80500 ) curl_close( $curl );
        fclose( $file );
    }
}

function sac_media_item( $id ) {
    $post = get_post( $id );
    return [
        'attachment_id' => $id, 'url' => wp_get_attachment_url( $id ), 'edit_url' => get_edit_post_link( $id, 'raw' ) ?: '',
        'title' => $post->post_title, 'description' => $post->post_content, 'alt_text' => get_post_meta( $id, '_wp_attachment_image_alt', true ),
    ];
}

function sac_media_store_image( $path, $fields ) {
    $type = sac_media_image( $path );
    if ( is_wp_error( $type ) ) return $type;
    foreach ( [ 'title', 'description', 'alt_text', 'filename' ] as $key ) {
        if ( isset( $fields[ $key ] ) && ( ! is_string( $fields[ $key ] ) || strlen( $fields[ $key ] ) > 4000 ) ) return sac_content_error( $key, '欄位格式或長度不符。' );
    }
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $editor = wp_get_image_editor( $path );
    if ( is_wp_error( $editor ) ) return sac_oauth_error( 'sac_image_decode', '無法解碼此圖片或主機不支援此格式。', 415 );
    $basename = sanitize_file_name( pathinfo( $fields['filename'] ?? 'image', PATHINFO_FILENAME ) ) ?: 'image';
    $basename = sanitize_file_name( substr( $basename, 0, 100 ) ) ?: 'image';
    $tmp = wp_tempnam( 'sac-image' );
    if ( ! $tmp ) return sac_oauth_error( 'server_error', '無法建立暫存檔。', 500 );
    $clean = $tmp . '.' . $type['extension'];
    try {
        $saved = $editor->save( $clean, $type['mime'] );
        if ( is_wp_error( $saved ) ) return sac_oauth_error( 'sac_image_decode', '無法安全儲存圖片。', 415 );
        $validated = sac_media_image( $clean );
        if ( is_wp_error( $validated ) ) return $validated;
        $id = media_handle_sideload( [
            'name' => $basename . '.' . $type['extension'], 'tmp_name' => $clean,
            'type' => $type['mime'], 'error' => UPLOAD_ERR_OK, 'size' => filesize( $clean ),
        ], 0, null, [
            'post_title' => sanitize_text_field( $fields['title'] ?? $basename ),
            'post_content' => sanitize_textarea_field( $fields['description'] ?? '' ),
        ] );
        if ( is_wp_error( $id ) ) return sac_oauth_error( 'sac_media_save', 'WordPress 無法將圖片加入媒體庫。', 500 );
        if ( isset( $fields['alt_text'] ) ) update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $fields['alt_text'] ) );
        return sac_media_item( $id );
    } finally {
        foreach ( [ $tmp, $clean ] as $file ) if ( is_file( $file ) ) wp_delete_file( $file );
    }
}

function sac_media_upload( $request ) {
    $fields = $request->get_json_params() ?: $request->get_body_params();
    $files = $request->get_file_params();
    $direct = $fields['file'] ?? null;
    $remote = $fields['url'] ?? null;
    if ( $direct !== null ) {
        if ( $remote !== null || ! is_array( $direct ) || ! is_string( $direct['download_url'] ?? null ) || ! is_string( $direct['file_id'] ?? null ) || $direct['file_id'] === '' ) {
            return sac_oauth_error( 'sac_image_source', '檔案參照格式錯誤。' );
        }
        $remote = $direct['download_url'];
        if ( is_string( $direct['file_name'] ?? null ) ) $fields += [ 'filename' => $direct['file_name'] ];
    }
    $base64 = $fields['base64'] ?? null;
    $file = $files['image'] ?? null;
    if ( (int) ( $remote !== null ) + (int) ( $base64 !== null ) + (int) ( $file !== null ) !== 1 ) {
        return sac_oauth_error( 'sac_image_source', '每次請提供一個圖片來源。' );
    }
    if ( $file !== null ) {
        if ( ( $file['error'] ?? null ) !== UPLOAD_ERR_OK || ! is_string( $file['tmp_name'] ?? null ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
            return sac_oauth_error( 'sac_image_file', '圖片上傳失敗。' );
        }
        return sac_media_store_image( $file['tmp_name'], $fields + [ 'filename' => $file['name'] ] );
    }
    require_once ABSPATH . 'wp-admin/includes/file.php';
    $tmp = wp_tempnam( 'sac-upload' );
    if ( ! $tmp ) return sac_oauth_error( 'server_error', '無法建立暫存檔。', 500 );
    try {
        if ( $remote !== null ) {
            $download = sac_media_download( $remote, $tmp );
            if ( is_wp_error( $download ) ) return $download;
        } else {
            if ( ! is_string( $base64 ) || strlen( $base64 ) > 4 * (int) ceil( sac_media_max_bytes() / 3 ) ) {
                return sac_oauth_error( 'sac_image_size', 'Base64 圖片超出限制。', 413 );
            }
            $bytes = base64_decode( $base64, true );
            if ( $bytes === false || file_put_contents( $tmp, $bytes ) !== strlen( $bytes ) ) return sac_oauth_error( 'sac_image_base64', '無法讀取 Base64 圖片。' );
        }
        return sac_media_store_image( $tmp, $fields );
    } finally {
        wp_delete_file( $tmp );
    }
}

function sac_media_upload_link( $request ) {
    $grant = sac_authenticate( $request );
    $token = sac_oauth_random();
    if ( ! sac_oauth_insert( 'upload', $token, time() + 10 * MINUTE_IN_SECONDS, [], $grant['client'], $grant['id'], $grant['user_id'] ) ) {
        return sac_oauth_error( 'server_error', '無法建立圖片上傳連結。', 500 );
    }
    return [ 'upload_url' => add_query_arg( [ 'action' => 'sac_upload_image', 'request' => $token ], admin_url( 'admin-post.php' ) ),
        'token' => $token, 'expires_in_seconds' => 600, 'max_files' => 1 ];
}

function sac_media_upload_page() {
    $token = sac_review_page_start();
    $row = sac_review_lookup( 'upload', $token, true );
    if ( ! $row || ! current_user_can( 'upload_files' ) ) wp_die( '上傳連結已失效或不屬於目前帳號。', '', [ 'response' => 403 ] );
    $grant = sac_oauth_row( 'grant', $row['family'], true );
    if ( ! in_array( 'media:upload', array_intersect( $grant['payload']['scopes'], sac_oauth_scopes() ), true ) ) wp_die( '上傳權限已撤銷。', '', [ 'response' => 403 ] );
    if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) === 'POST' ) {
        check_admin_referer( 'sac_upload_' . $token );
        $image = $_FILES['image'] ?? [];
        if ( ( $image['error'] ?? null ) !== UPLOAD_ERR_OK || ! is_string( $image['tmp_name'] ?? null ) || ! is_uploaded_file( $image['tmp_name'] ) ) {
            wp_die( '請選擇要上傳的圖片。', '', [ 'response' => 400 ] );
        }
        global $wpdb;
        $table = sac_oauth_table();
        if ( $wpdb->query( $wpdb->prepare( "UPDATE $table SET state = 1 WHERE id = %d AND state = 0 AND expires > %d", $row['id'], time() ) ) !== 1 ) {
            wp_die( '此上傳連結已使用。', '', [ 'response' => 409 ] );
        }
        $fields = array_intersect_key( wp_unslash( $_POST ), array_flip( [ 'title', 'description', 'alt_text' ] ) );
        $saved = sac_media_store_image( $image['tmp_name'], $fields + [ 'filename' => $image['name'] ] );
        if ( is_wp_error( $saved ) ) {
            sac_oauth_change( $row['id'], [ 'payload' => wp_json_encode( [ 'status' => 'failed', 'code' => $saved->get_error_code() ] ) ] );
            wp_die( esc_html( $saved->get_error_message() ), '上傳失敗', [ 'response' => 400 ] );
        }
        sac_oauth_change( $row['id'], [ 'payload' => wp_json_encode( [ 'status' => 'uploaded' ] + $saved ) ] );
        sac_oauth_insert( 'audit', sac_oauth_random(), time() + 30 * DAY_IN_SECONDS, [
            'time' => current_time( 'mysql' ), 'tool' => 'upload_media', 'target_type' => 'attachment', 'target_id' => $saved['attachment_id'],
            'success' => true, 'grant_id' => (int) $row['family'], 'user_id' => get_current_user_id(),
        ], $row['client'], $row['family'], $row['owner'] );
        sac_invalidate_cache();
        wp_die( '已上傳圖片。媒體 ID ' . (int) $saved['attachment_id'] . '。請回到 AI 對話繼續。', '圖片上傳', [ 'response' => 200 ] );
    }
    ob_start();
    ?>
    <form method="post" enctype="multipart/form-data">
        <?php wp_nonce_field( 'sac_upload_' . $token ); ?>
        <p><label>圖片 <input type="file" name="image" accept="image/png,image/jpeg,image/webp" required></label></p>
        <p><label>標題 <input name="title" maxlength="200"></label></p>
        <p><label>替代文字 <input name="alt_text" maxlength="300"></label></p>
        <p><label>圖片說明 <textarea name="description" maxlength="1500"></textarea></label></p>
        <button class="button button-primary">上傳圖片</button>
    </form>
    <?php
    wp_die( ob_get_clean(), '一次性圖片上傳', [ 'response' => 200 ] );
}
add_action( 'admin_post_sac_upload_image', 'sac_media_upload_page' );
add_action( 'admin_post_nopriv_sac_upload_image', 'sac_media_upload_page' );

function sac_media_definitions() {
    $body = [];
    foreach ( [ 'filename', 'title', 'description', 'alt_text', 'url', 'base64' ] as $name ) $body[ $name ] = [ 'type' => 'string', 'in' => 'body' ];
    $file_properties = array_fill_keys( [ 'download_url', 'file_id', 'mime_type', 'file_name' ], [ 'type' => 'string' ] );
    $file = [ 'file' => [ 'type' => 'object', 'in' => 'body', 'properties' => $file_properties,
        'required' => [ 'download_url', 'file_id' ], 'additionalProperties' => false ] ];
    $id = [ 'id' => [ 'type' => 'integer', 'in' => 'path', 'required' => true ] ];
    return [
        [ 'name' => 'upload_media', 'path' => '/media/upload', 'method' => 'POST', 'need_write' => true,
            'summary' => '上傳圖片', 'description' => '提供實際可取得的 HTTPS 圖片或 Base64。若平台只有內部檔案引用，使用一次性上傳連結。', 'params' => $body + $file ],
        [ 'name' => 'get_media', 'path' => '/media/{id}', 'method' => 'GET', 'need_write' => false,
            'summary' => '查閱圖片', 'description' => '查閱媒體 ID、網址、標題、說明及替代文字。', 'params' => $id ],
        [ 'name' => 'update_media', 'path' => '/media/{id}', 'method' => 'POST', 'need_write' => true,
            'summary' => '更新圖片資料', 'description' => '更新此帳號有權編輯的圖片資料。', 'params' => $id + array_intersect_key( $body, array_flip( [ 'title', 'description', 'alt_text' ] ) ) ],
    ];
}

add_action( 'rest_api_init', static function () {
    register_rest_route( SAC_NAMESPACE, '/media/upload', [ 'methods' => 'POST', 'permission_callback' => 'sac_permission_write', 'callback' => 'sac_media_upload' ] );
    foreach ( [ 'GET', 'POST' ] as $method ) {
        register_rest_route( SAC_NAMESPACE, '/media/(?P<id>\d+)', [
            'methods' => $method, 'permission_callback' => 'sac_permission_read',
            'callback' => static function ( $request ) use ( $method ) {
                $id = (int) $request->get_url_params()['id'];
                if ( ! wp_attachment_is_image( $id ) || ! current_user_can( $method === 'GET' ? 'read_post' : 'edit_post', $id ) ) {
                    return sac_oauth_error( 'sac_not_found', '找不到可操作的圖片。', 404 );
                }
                if ( $method === 'GET' ) return sac_media_item( $id );
                $data = $request->get_json_params() ?: $request->get_body_params();
                $changed = [ 'ID' => $id ];
                foreach ( [ 'title' => 'post_title', 'description' => 'post_content', 'alt_text' => null ] as $field => $column ) {
                    if ( ! array_key_exists( $field, $data ) ) continue;
                    if ( ! is_string( $data[ $field ] ) ) return sac_content_error( $field, '請提供文字。' );
                    if ( $column ) $changed[ $column ] = sanitize_textarea_field( $data[ $field ] );
                }
                if ( count( $changed ) > 1 && is_wp_error( wp_update_post( wp_slash( $changed ), true ) ) ) return sac_oauth_error( 'sac_media_save', '無法更新媒體資料。', 500 );
                if ( isset( $data['alt_text'] ) ) update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $data['alt_text'] ) );
                return sac_media_item( $id );
            },
        ] );
    }
} );
