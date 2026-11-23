<?php
defined('ABSPATH') || exit;

final class WUTM_Chunk_Uploader {
    const ACTION = 'wutm_chunk_upload';
    const MARKER = '_wutm_chunk_upload_id';

    private static function posted(string $key): string {
        return is_string($_POST[$key] ?? null) ? wp_unslash($_POST[$key]) : '';
    }

    private static function number(string $value): int {
        return preg_match('/^(0|[1-9][0-9]{0,8})$/D', $value) ? (int) $value : -1;
    }

    public static function max_bytes(): int {
        $max = WUTM_Chunk_Upload_Storage::MAX_BYTES;
        if (is_multisite()) $max = min($max, max(0, (int) get_site_option('fileupload_maxk', 1500)) * KB_IN_BYTES);
        return $max;
    }

    private static function validate_video(string $name, int $size): void {
        if ($size < 1 || $size > self::max_bytes() || !preg_match('/\.mp4$/iD', $name) ||
            wp_check_filetype($name, get_allowed_mime_types())['type'] !== 'video/mp4') {
            throw new WUTM_Chunk_Upload_Error('只接受未超過本站上限、且允許上傳的 MP4 影片。', 422);
        }
        if (is_multisite() && !get_site_option('upload_space_check_disabled') && get_upload_space_available() < $size) {
            throw new WUTM_Chunk_Upload_Error('此子網站的媒體儲存空間不足。', 507);
        }
    }

    public static function request(): void {
        nocache_headers();
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !WUTM_Chunk_Upload_Module::can_access() ||
            !check_ajax_referer(self::ACTION, 'nonce', false)) {
            wp_send_json_error(['message'=>'無法驗證上傳權限，請重新登入或重新整理。'], 403);
        }
        try {
            $op = self::posted('op');
            $owner = get_current_user_id();
            $store = new WUTM_Chunk_Upload_Storage();
            if ($op === 'start') {
                $key = self::posted('key');
                $name = sanitize_file_name(self::posted('name'));
                $size = self::number(self::posted('size'));
                $digest = self::posted('digest');
                if (!preg_match('/^[a-f0-9]{32}$/D', $key) || strlen($name) > 180 ||
                    ($digest !== '' && !preg_match('/^[a-f0-9]{64}$/D', $digest))) {
                    throw new WUTM_Chunk_Upload_Error('上傳資料格式不正確。', 400);
                }
                self::validate_video($name, $size);
                // Register cleanup before accepting files, including when the module is later switched off.
                if (!wp_next_scheduled(WUTM_Chunk_Upload_Storage::CRON)) {
                    wp_schedule_single_event(time() + 15 * MINUTE_IN_SECONDS, WUTM_Chunk_Upload_Storage::CRON);
                }
                $result = $store->begin($owner, $key, $name, $size, $digest);
            } else {
                $id = self::posted('id');
                $result = $store->session($owner, $id, static function ($dir, $meta) use ($id,$op) {
                    if (!empty($meta['attachment'])) return self::result((int) $meta['attachment']);
                    if ($op === 'part') {
                        self::receive($dir, $meta, self::number(self::posted('index')), $_FILES['chunk'] ?? []);
                        return ['stored'=>true];
                    }
                    if ($op === 'finish') return self::finish($dir, $meta, $id);
                    if ($op === 'cancel') {
                        // A retry after a lost final response must keep any already-created attachment.
                        $attachment = self::find_attachment($id, (int) $meta['owner']);
                        if ($attachment) return self::complete($dir, $meta, $attachment);
                        $meta['cancelled'] = true;
                        WUTM_Chunk_Upload_Storage::save($dir, $meta);
                        WUTM_Chunk_Upload_Storage::drop_parts($dir);
                        return ['cancelled'=>true];
                    }
                    throw new WUTM_Chunk_Upload_Error('無效的上傳操作。', 400);
                });
            }
            wp_send_json_success($result);
        } catch (WUTM_Chunk_Upload_Error $error) {
            wp_send_json_error(['message'=>$error->getMessage()], $error->getCode());
        } catch (Throwable $error) {
            wp_send_json_error(['message'=>'暫時無法完成上傳，請檢查主機或稍後重試。'], 500);
        }
    }

    private static function expected(array $meta, int $index): int {
        if ($index < 0 || $index >= $meta['total']) throw new WUTM_Chunk_Upload_Error('分段編號無效。', 400);
        return min($meta['part'], $meta['size'] - $index * $meta['part']);
    }

    private static function receive(string $dir, array $meta, int $index, $upload): void {
        $expected = self::expected($meta, $index);
        if (is_array($upload) && in_array($upload['error'] ?? null, [UPLOAD_ERR_INI_SIZE,UPLOAD_ERR_FORM_SIZE], true)) {
            throw new WUTM_Chunk_Upload_Error('此分段超過主機的單次請求大小。', 413);
        }
        $temp = $upload['tmp_name'] ?? '';
        if (!is_string($temp) || ($upload['error'] ?? null) !== UPLOAD_ERR_OK ||
            !is_uploaded_file($temp) || filesize($temp) !== $expected) {
            throw new WUTM_Chunk_Upload_Error('分段未完整收到或大小不符。', 422);
        }
        $target = $dir . '/part-' . $index;
        if (is_file($target)) {
            if (hash_file('sha256', $target) !== hash_file('sha256', $temp)) {
                throw new WUTM_Chunk_Upload_Error('此分段與原本的檔案不同，請取消後重新選擇影片。', 422);
            }
        } else {
            $staged = $dir . '/part-new';
            if (!move_uploaded_file($temp, $staged) || !@rename($staged, $target)) {
                @unlink($staged);
                throw new WUTM_Chunk_Upload_Error('無法寫入分段，請檢查主機暫存空間。', 507);
            }
        }
        WUTM_Chunk_Upload_Storage::save($dir, $meta);
    }

    private static function assemble(string $dir, array $meta): string {
        $path = $dir . '/assembled.mp4';
        $output = @fopen($path, 'wb');
        if (!$output) throw new WUTM_Chunk_Upload_Error('無法建立合併用的暫存檔。', 507);
        try {
            for ($i = 0; $i < $meta['total']; $i++) {
                $part = $dir . '/part-' . $i;
                $expected = self::expected($meta, $i);
                clearstatcache(true, $part);
                if (!is_file($part) || is_link($part) || filesize($part) !== $expected) {
                    throw new WUTM_Chunk_Upload_Error('有分段尚未上傳或保存不完整，請重新開始續傳。', 409);
                }
                $input = @fopen($part, 'rb');
                if (!$input) throw new WUTM_Chunk_Upload_Error('無法讀取分段。', 500);
                try {
                    if (stream_copy_to_stream($input, $output, $expected) !== $expected) {
                        throw new WUTM_Chunk_Upload_Error('影片合併未完成，請檢查磁碟空間。', 507);
                    }
                } finally {
                    fclose($input);
                }
            }
            if (!fflush($output)) throw new WUTM_Chunk_Upload_Error('無法寫入完整影片。', 507);
        } catch (Throwable $error) {
            fclose($output);
            @unlink($path);
            throw $error;
        }
        fclose($output);
        clearstatcache(true, $path);
        if (filesize($path) !== $meta['size'] || ($meta['digest'] !== '' && hash_file('sha256',$path) !== $meta['digest'])) {
            @unlink($path);
            throw new WUTM_Chunk_Upload_Error('合併結果與原檔不相符，請取消並重新上傳。', 422);
        }
        return $path;
    }

    private static function find_attachment(string $id, int $owner): int {
        $ids = get_posts(['post_type'=>'attachment','post_status'=>'inherit','author'=>$owner,'fields'=>'ids',
            'posts_per_page'=>1,'meta_key'=>self::MARKER,'meta_value'=>$id,'no_found_rows'=>true]);
        return (int) ($ids[0] ?? 0);
    }

    private static function result(int $id): array {
        $url = wp_get_attachment_url($id);
        if (!$url) throw new WUTM_Chunk_Upload_Error('這筆媒體已不存在。', 410);
        return ['attachment'=>$id,'url'=>esc_url_raw($url,['http','https']),
            'edit_url'=>get_edit_post_link($id, 'raw') ?: ''];
    }

    private static function complete(string $dir, array $meta, int $attachment): array {
        $meta['attachment'] = $attachment;
        WUTM_Chunk_Upload_Storage::save($dir, $meta);
        WUTM_Chunk_Upload_Storage::drop_parts($dir);
        return self::result($attachment);
    }

    private static function mp4_video(string $path, $video): bool {
        if (empty($video['width']) || empty($video['height'])) return false;
        $mime = $video['mime_type'] ?? '';
        if ($mime === 'video/mp4') return true;
        if ($mime !== 'video/quicktime') return false;
        // WordPress may label valid video-only MP4 files as QuickTime, depending on their codec.
        if (class_exists('finfo') && (new finfo(FILEINFO_MIME_TYPE))->file($path) === 'video/mp4') return true;
        $header = file_get_contents($path, false, null, 0, 16);
        return is_string($header) && substr($header, 4, 4) === 'ftyp' &&
            preg_match('/^(?:isom|iso[2-9]|mp4[12]|avc1|M4V |MSNV|hvc1|hev1|dash|cmfc|cmfs|av01)$/D', substr($header, 8, 4));
    }

    public static function finish(string $dir, array $meta, string $id): array {
        $existing = self::find_attachment($id, (int) $meta['owner']);
        if ($existing) return self::complete($dir, $meta, $existing);
        self::validate_video($meta['name'], $meta['size']);
        $path = self::assemble($dir, $meta);
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        try {
            $types = ['mp4'=>'video/mp4'];
            $check = wp_check_filetype_and_ext($path, $meta['name'], $types);
            $video = $check['type'] === 'video/mp4' ? wp_read_video_metadata($path) : false;
            if (!self::mp4_video($path, $video)) {
                throw new WUTM_Chunk_Upload_Error('檔案內容不是可辨識的 MP4 影片。', 422);
            }
            $staged = ['name'=>$meta['name'],'type'=>'video/mp4','tmp_name'=>$path,
                'error'=>UPLOAD_ERR_OK,'size'=>$meta['size']];
            $file = wp_handle_sideload($staged, ['test_form'=>false,'mimes'=>$types]);
            if (empty($file['file']) || empty($file['url']) || !empty($file['error'])) {
                throw new WUTM_Chunk_Upload_Error('WordPress 無法保存影片，請檢查媒體目錄與主機空間。', 500);
            }
            $attachment = wp_insert_attachment([
                'post_mime_type'=>'video/mp4','post_title'=>pathinfo(wp_basename($file['file']), PATHINFO_FILENAME),
                'post_status'=>'inherit','post_author'=>$meta['owner'],'guid'=>$file['url'],
                'meta_input'=>[self::MARKER=>$id],
            ], $file['file'], 0, true);
            if (!$attachment || is_wp_error($attachment)) {
                wp_delete_file($file['file']);
                throw new WUTM_Chunk_Upload_Error('無法註冊媒體庫。', 500);
            }
            $result = self::complete($dir, $meta, $attachment);
            $metadata = wp_generate_attachment_metadata($attachment, $file['file']);
            if (is_array($metadata) && $metadata) {
                $metadata['mime_type'] = 'video/mp4';
                wp_update_attachment_metadata($attachment, $metadata);
            }
            return $result;
        } finally {
            if (is_file($path)) @unlink($path);
        }
    }
}
