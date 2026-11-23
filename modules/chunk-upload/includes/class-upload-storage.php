<?php
defined('ABSPATH') || exit;

final class WUTM_Chunk_Upload_Error extends RuntimeException {}

final class WUTM_Chunk_Upload_Storage {
    const MAX_BYTES = 50 * 1024 * 1024;
    const SITE_BYTES = 300 * 1024 * 1024;
    const TTL = 3600;
    const CRON = 'wutm_chunk_upload_cleanup';
    private $root;

    public function __construct(bool $create = true) {
        $base = defined('WUTM_CHUNK_UPLOAD_TEMP_DIR') ? WUTM_CHUNK_UPLOAD_TEMP_DIR : sys_get_temp_dir();
        $base = is_string($base) && $base !== '' ? realpath($base) : false;
        if (!$base || !is_dir($base)) throw new WUTM_Chunk_Upload_Error('找不到可用的私人暫存目錄。', 503);

        global $wpdb;
        $key = hash_hmac('sha256', ABSPATH . '|' . $wpdb->prefix, wp_salt('auth'));
        $root = rtrim($base, '/\\') . '/wutm-chunks-' . $key;
        $uploads = wp_upload_dir(null, false);
        foreach ([ABSPATH, WP_CONTENT_DIR, $uploads['basedir'], $_SERVER['DOCUMENT_ROOT'] ?? ''] as $public) {
            $public = $public ? realpath($public) : false;
            if ($public && self::within($root, $public)) {
                throw new WUTM_Chunk_Upload_Error('暫存目錄必須位於網站公開目錄以外。', 503);
            }
        }
        if ($create && !is_dir($root) && !@mkdir($root, 0700) && !is_dir($root)) {
            throw new WUTM_Chunk_Upload_Error('無法建立私人暫存目錄。', 503);
        }
        if (is_link($root) || (is_dir($root) && DIRECTORY_SEPARATOR !== '\\' && ((fileperms($root) & 0077) ||
            (function_exists('posix_geteuid') && fileowner($root) !== posix_geteuid())))) {
            throw new WUTM_Chunk_Upload_Error('暫存目錄權限不安全。', 503);
        }
        $this->root = $root;
    }

    private static function within(string $path, string $parent): bool {
        $path = rtrim(wp_normalize_path($path), '/') . '/';
        $parent = rtrim(wp_normalize_path($parent), '/') . '/';
        if (DIRECTORY_SEPARATOR === '\\') {
            $path = strtolower($path);
            $parent = strtolower($parent);
        }
        return strpos($path, $parent) === 0;
    }

    public static function part_size(): int {
        $size = max(64 * 1024, min(1024 * 1024, (int) apply_filters('wutm_chunk_upload_part_bytes', 1024 * 1024)));
        foreach (['upload_max_filesize'=>0, 'post_max_size'=>16 * 1024] as $setting=>$overhead) {
            $limit = wp_convert_hr_to_bytes((string) ini_get($setting));
            if ($limit > 0) $size = min($size, max(0, $limit - $overhead));
        }
        if ($size < 64 * 1024) throw new WUTM_Chunk_Upload_Error('主機允許的單次上傳大小不足。', 503);
        return $size;
    }

    private function lock(string $path, string $mode) {
        if (is_link($path)) return null;
        $handle = @fopen($path, $mode);
        if (!$handle) return null;
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }
        return $handle;
    }

    private function root_lock() {
        $handle = $this->lock($this->root . '/.lock', 'c+b');
        if (!$handle) throw new WUTM_Chunk_Upload_Error('上傳儲存區忙碌或無法鎖定，請稍後再試。', 409);
        return $handle;
    }

    private static function read_meta(string $dir): ?array {
        if (is_link($dir) || is_link($dir . '/meta.json')) return null;
        $json = @file_get_contents($dir . '/meta.json', false, null, 0, 8192);
        $meta = is_string($json) ? json_decode($json, true) : null;
        return is_array($meta) ? $meta : null;
    }

    public static function save(string $dir, array $meta): void {
        $meta['updated'] = time();
        $path = $dir . '/meta.new';
        $json = wp_json_encode($meta);
        if (!is_string($json) || @file_put_contents($path, $json) !== strlen($json) || !@rename($path, $dir . '/meta.json')) {
            @unlink($path);
            throw new WUTM_Chunk_Upload_Error('無法更新上傳進度，請檢查暫存空間後重試。', 507);
        }
    }

    private static function expired(array $meta): bool {
        return (int) ($meta['updated'] ?? 0) < time() - self::TTL ||
            (int) ($meta['created'] ?? 0) < time() - DAY_IN_SECONDS;
    }

    private function directories(): array {
        return glob($this->root . '/job-*', GLOB_ONLYDIR) ?: [];
    }

    public function begin(int $owner, string $key, string $name, int $size, string $digest): array {
        $id = hash_hmac('sha256', $this->root . '|' . $owner . '|' . $key, wp_salt('nonce'));
        $dir = $this->root . '/job-' . $id;
        $root = $this->root_lock();
        try {
            if (!is_dir($dir)) {
                $used = $count = 0;
                $dirs = $this->directories();
                foreach ($dirs as $candidate) {
                    $meta = self::read_meta($candidate);
                    if (!$meta || !empty($meta['attachment']) || !empty($meta['cancelled'])) continue;
                    $used += (int) $meta['size'];
                    if ((int) $meta['owner'] === $owner) $count++;
                }
                if (count($dirs) >= 256 || $count >= 3 || $used + $size > self::SITE_BYTES) {
                    throw new WUTM_Chunk_Upload_Error('暫存工作已達上限。請繼續或取消之前的工作，或等待逾期清理。', 429);
                }
                $free = @disk_free_space($this->root);
                if ($free !== false && $free < 2 * $size) throw new WUTM_Chunk_Upload_Error('主機暫存空間不足。', 507);
                $part = self::part_size();
                if (!@mkdir($dir, 0700)) throw new WUTM_Chunk_Upload_Error('無法建立上傳工作。', 503);
                try {
                    $lock_file = @fopen($dir . '/.lock', 'x+b');
                    if (!$lock_file) throw new WUTM_Chunk_Upload_Error('無法建立工作鎖定。', 503);
                    fclose($lock_file);
                    self::save($dir, ['owner'=>$owner,'name'=>$name,'size'=>$size,'part'=>$part,
                        'digest'=>$digest,'created'=>time(),'total'=>(int) ceil($size / $part)]);
                } catch (Throwable $error) {
                    self::remove($dir);
                    throw $error;
                }
            }
        } finally {
            fclose($root);
        }

        return $this->session($owner, $id, static function ($dir, $meta) use ($id,$name,$size,$digest) {
            if ($meta['name'] !== $name || $meta['size'] !== $size || $meta['digest'] !== $digest) {
                throw new WUTM_Chunk_Upload_Error('請重新選擇原本的影片。', 409);
            }
            return ['id'=>$id] + self::progress($dir, $meta);
        });
    }

    public function session(int $owner, string $id, callable $callback) {
        if (!preg_match('/^[a-f0-9]{64}$/D', $id)) throw new WUTM_Chunk_Upload_Error('上傳識別碼無效。', 400);
        $dir = $this->root . '/job-' . $id;
        $root = $this->root_lock();
        try {
            if (!is_dir($dir) || is_link($dir)) throw new WUTM_Chunk_Upload_Error('找不到上傳工作，請重新開始。', 404);
            $lock = $this->lock($dir . '/.lock', 'r+b');
            if (!$lock) throw new WUTM_Chunk_Upload_Error('工作正在處理，請稍後再試。', 409);
        } finally {
            fclose($root);
        }

        try {
            $meta = self::read_meta($dir);
            if (!$meta || (int) $meta['owner'] !== $owner) throw new WUTM_Chunk_Upload_Error('找不到上傳工作。', 404);
            if (self::expired($meta) || !empty($meta['cancelled'])) {
                throw new WUTM_Chunk_Upload_Error('上傳工作已到期或取消，請重新開始。', 410);
            }
            return $callback($dir, $meta);
        } finally {
            fclose($lock);
        }
    }

    public static function progress(string $dir, array $meta): array {
        $received = [];
        if (empty($meta['attachment'])) {
            for ($i = 0; $i < $meta['total']; $i++) {
                if (is_file($dir . '/part-' . $i)) $received[] = $i;
            }
        }
        return ['part_size'=>$meta['part'],'total'=>$meta['total'],'received'=>$received,'attachment'=>(int) ($meta['attachment'] ?? 0)];
    }

    public static function drop_parts(string $dir): void {
        foreach (glob($dir . '/part-*') ?: [] as $file) {
            if (!is_dir($file)) @unlink($file);
        }
        @unlink($dir . '/assembled.mp4');
    }

    private static function remove(string $dir): void {
        self::drop_parts($dir);
        foreach (['meta.json','meta.new','.lock'] as $file) @unlink($dir . '/' . $file);
        @rmdir($dir);
    }

    public function cleanup(): bool {
        if (!is_dir($this->root)) return false;
        $root = $this->root_lock();
        try {
            foreach ($this->directories() as $dir) {
                if (is_link($dir) || !preg_match('/^job-[a-f0-9]{64}$/D', basename($dir))) continue;
                $lock = $this->lock($dir . '/.lock', 'r+b');
                if (!$lock && is_file($dir . '/.lock')) continue;
                $meta = self::read_meta($dir);
                $expired = $meta ? self::expired($meta) || !empty($meta['cancelled']) : filemtime($dir) < time() - self::TTL;
                if ($lock) fclose($lock);
                if ($expired) self::remove($dir);
            }
            return (bool) $this->directories();
        } finally {
            fclose($root);
        }
    }
}
