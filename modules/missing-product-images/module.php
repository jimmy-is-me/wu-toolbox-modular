<?php
/** WooCommerce missing description image repair, based on the supplied Wumetax V2. */
defined('ABSPATH') || exit;

final class WUTM_Missing_Product_Images {
    private const OPTION = 'wutm_missing_product_images';
    private const SLUG = 'wu-missing-product-images';
    private const MAX_BYTES = 31457280;
    private const MIMES = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'avif' => 'image/avif', 'bmp' => 'image/bmp'];

    public static function boot(): void {
        add_action('admin_menu', [__CLASS__, 'menu'], 20);
        add_action('admin_post_wutm_missing_images_save', [__CLASS__, 'save']);
        add_action('wp_ajax_wutm_missing_images_batch', [__CLASS__, 'batch']);
    }
    public static function menu(): void {
        add_submenu_page('wu-toolbox-modular', '遺失商品圖片修復', '遺失商品圖片修復', 'manage_woocommerce', self::SLUG, [__CLASS__, 'page']);
    }
    private static function settings(): array {
        return wp_parse_args((array) get_option(self::OPTION, []), ['source_url' => '', 'dest_url' => home_url('/'), 'attachment' => true]);
    }
    private static function site_url($value): string {
        if (!is_string($value)) return '';
        $value = trim($value);
        if ($value === '') return '';
        if (!preg_match('~^https?://~i', $value)) $value = 'https://' . $value;
        $parts = wp_parse_url($value);
        if (!$parts || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) return '';
        return untrailingslashit(esc_url_raw($value, ['http', 'https']));
    }
    public static function save(): void {
        if (!current_user_can('manage_woocommerce') || !current_user_can('upload_files')) wp_die('權限不足', '', ['response' => 403]);
        check_admin_referer('wutm_missing_images_save');
        $source = self::site_url(wp_unslash($_POST['source_url'] ?? ''));
        $dest = self::site_url(wp_unslash($_POST['dest_url'] ?? ''));
        if (!$source || !$dest) wp_die('請填寫有效的 A / B 網站網址。');
        update_option(self::OPTION, ['source_url' => $source, 'dest_url' => $dest, 'attachment' => !empty($_POST['attachment'])], false);
        wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '&saved=1'));
        exit;
    }

    /** Accept decoded uploads-relative raster paths, never executable files or traversal. */
    public static function valid_path(string $path): bool {
        if ($path === '' || preg_match('~[\\\\\x00-\x1f\x7f:%?#]~', $path)) return false;
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.' || $part === '..' || $part[0] === '.' || preg_match('/[. ]$/', $part)) return false;
        }
        return isset(self::MIMES[strtolower(pathinfo($path, PATHINFO_EXTENSION))]);
    }
    public static function extract_paths(string $content, string $dest): array {
        $content = str_replace('\\/', '/', html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        // Consume absolute URLs as a whole: a foreign URL must not be re-matched as relative.
        preg_match_all('~(?:https?://|//)[^\s"\'<>(),]+|(?<![A-Za-z0-9/:])/?wp-content/uploads/[^\s"\'<>(),]+~iu', $content, $matches);
        $base = rtrim((string) wp_parse_url($dest, PHP_URL_PATH), '/') . '/wp-content/uploads/';
        $paths = [];
        foreach ($matches[0] as $url) {
            $absolute = preg_match('~^(?:https?:)?//~i', $url);
            $parts = wp_parse_url(strpos($url, '//') === 0 ? 'https:' . $url : $url);
            if (!$parts) continue;
            if ($absolute && (strtolower($parts['host'] ?? '') !== strtolower((string) wp_parse_url($dest, PHP_URL_HOST)) || isset($parts['user']) || isset($parts['pass']))) continue;
            $path = rawurldecode($parts['path'] ?? '');
            $prefix = $absolute ? $base : '/wp-content/uploads/';
            if (!$absolute) $path = '/' . ltrim($path, '/');
            if (strpos($path, $prefix) !== 0) continue;
            $relative = substr($path, strlen($prefix));
            if (self::valid_path($relative)) $paths[$relative] = true;
        }
        return array_keys($paths);
    }
    private static function source_url(string $relative, array $settings): string {
        return rtrim($settings['source_url'], '/') . '/wp-content/uploads/' . implode('/', array_map('rawurlencode', explode('/', $relative)));
    }
    /** Resolve beneath the actual local uploads root and reject symlink components. */
    public static function local_path(string $relative) {
        if (!self::valid_path($relative)) return new WP_Error('path', '圖片路徑無效');
        $uploads = wp_upload_dir(null, false);
        $root = realpath($uploads['basedir'] ?? '');
        if (!$root || !empty($uploads['error'])) return new WP_Error('uploads', '無法存取本站 uploads 目錄');
        $path = $root;
        foreach (explode('/', $relative) as $part) {
            $path .= DIRECTORY_SEPARATOR . $part;
            if (is_link($path)) return new WP_Error('symlink', '略過含符號連結的路徑');
        }
        return $path;
    }
    private static function probe(string $url): bool {
        $r = wp_safe_remote_head($url, ['timeout' => 6, 'redirection' => 3]);
        if (!is_wp_error($r) && wp_remote_retrieve_response_code($r) === 200 && strpos((string) wp_remote_retrieve_header($r, 'content-type'), 'image/') === 0) return true;
        $r = wp_safe_remote_get($url, ['timeout' => 6, 'redirection' => 3, 'limit_response_size' => 4096, 'headers' => ['Range' => 'bytes=0-4095']]);
        return !is_wp_error($r) && in_array(wp_remote_retrieve_response_code($r), [200, 206], true) && strpos((string) wp_remote_retrieve_header($r, 'content-type'), 'image/') === 0;
    }
    public static function valid_image(string $file, string $relative): bool {
        $mime = wp_get_image_mime($file);
        return self::valid_path($relative) && $mime && $mime === self::MIMES[strtolower(pathinfo($relative, PATHINFO_EXTENSION))];
    }
    private static function repair(string $relative, array $settings) {
        $path = self::local_path($relative);
        if (is_wp_error($path)) return $path;
        if (file_exists($path)) return ['status' => 'exists'];
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $tmp = wp_tempnam(basename($path));
        if (!$tmp) return new WP_Error('temp', '無法建立暫存檔');
        try {
            $r = wp_safe_remote_get(self::source_url($relative, $settings), ['timeout' => 15, 'redirection' => 3, 'stream' => true, 'filename' => $tmp, 'limit_response_size' => self::MAX_BYTES + 1]);
            if (is_wp_error($r)) return $r;
            if (wp_remote_retrieve_response_code($r) !== 200) return new WP_Error('http', '來源回傳 HTTP ' . wp_remote_retrieve_response_code($r));
            $size = filesize($tmp);
            $expected = (int) wp_remote_retrieve_header($r, 'content-length');
            if (!$size || $size > self::MAX_BYTES || ($expected > 0 && $expected !== $size)) return new WP_Error('size', '圖片為空、下載不完整或超過 30 MB');
            if (!self::valid_image($tmp, $relative)) return new WP_Error('image', '來源內容不是符合副檔名的有效圖片');
            if (!wp_mkdir_p(dirname($path))) return new WP_Error('mkdir', '無法建立圖片資料夾');
            $checked = self::local_path($relative);
            if (is_wp_error($checked)) return $checked;
            // Exclusive creation prevents concurrent requests from overwriting a file.
            $out = @fopen($checked, 'xb');
            if (!$out) return file_exists($checked) ? ['status' => 'exists'] : new WP_Error('write', '無法寫入圖片');
            $in = fopen($tmp, 'rb');
            $copied = $in ? stream_copy_to_stream($in, $out) : false;
            if ($in) fclose($in);
            fclose($out);
            if ($copied !== $size) { unlink($checked); return new WP_Error('write', '圖片寫入不完整'); }
            @chmod($checked, 0644);
            $message = '已補回原路徑';
            if (!empty($settings['attachment'])) {
                $id = self::attachment($checked, $relative);
                if (is_wp_error($id)) $message .= '；媒體庫登錄失敗：' . $id->get_error_message();
            }
            return ['status' => 'repaired', 'message' => $message];
        } finally { if (file_exists($tmp)) unlink($tmp); }
    }
    private static function attachment(string $path, string $relative) {
        global $wpdb;
        $existing = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1", $relative));
        if ($existing) return (int) $existing;
        $id = wp_insert_attachment(['post_mime_type' => wp_get_image_mime($path), 'post_title' => sanitize_text_field(pathinfo($path, PATHINFO_FILENAME)), 'post_status' => 'inherit'], $path, 0, true);
        if (is_wp_error($id)) return $id;
        // Register dimensions only, preserving the restored filename and image bytes.
        $size = wp_getimagesize($path);
        if ($size) wp_update_attachment_metadata($id, ['width' => $size[0], 'height' => $size[1], 'file' => $relative, 'sizes' => [], 'filesize' => filesize($path)]);
        return $id;
    }
    public static function batch(): void {
        if (!current_user_can('manage_woocommerce') || !current_user_can('upload_files')) wp_send_json_error(['message' => '權限不足'], 403);
        check_ajax_referer('wutm_missing_images', 'nonce');
        $settings = self::settings();
        if (!self::site_url($settings['source_url']) || !self::site_url($settings['dest_url'])) wp_send_json_error(['message' => '請先儲存 A / B 網站設定。'], 400);
        $mode = $_POST['mode'] ?? '';
        if (!in_array($mode, ['scan', 'repair'], true)) wp_send_json_error(['message' => '執行模式無效'], 400);
        $last = absint($_POST['last'] ?? 0);
        $index = absint($_POST['index'] ?? 0);
        global $wpdb;
        // One product and at most one remote image per request, using an ID cursor.
        $post = $wpdb->get_row($wpdb->prepare("SELECT ID, post_title, post_content, post_excerpt FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status IN ('publish','draft','private','pending','future') AND ID > %d ORDER BY ID ASC LIMIT 1", $last));
        if (!$post) wp_send_json_success(['done' => true]);
        if (!current_user_can('edit_post', $post->ID)) wp_send_json_success(['done' => false, 'last' => (int) $post->ID, 'index' => 0, 'completed' => 1, 'message' => '略過無編輯權限商品 #' . $post->ID]);
        $paths = self::extract_paths($post->post_content . "\n" . $post->post_excerpt, $settings['dest_url']);
        $relative = $paths[$index] ?? '';
        $status = 'no_images'; $message = '沒有符合條件的描述圖片';
        if ($relative !== '') {
            $path = self::local_path($relative);
            if (is_wp_error($path)) { $status = 'failed'; $message = $path->get_error_message(); }
            elseif (file_exists($path)) { $status = 'exists'; $message = '本站檔案已存在，略過'; }
            elseif ($mode === 'scan') { $status = self::probe(self::source_url($relative, $settings)) ? 'available' : 'missing'; $message = $status === 'available' ? '本站缺檔；來源可存取，修復時再驗證圖片內容' : '本站缺檔；來源無法存取'; }
            else {
                $r = self::repair($relative, $settings);
                $status = is_wp_error($r) ? 'failed' : $r['status'];
                $message = is_wp_error($r) ? $r->get_error_message() : ($r['message'] ?? '本站檔案已存在，略過');
            }
        }
        $completed = $index + 1 >= count($paths);
        wp_send_json_success(['done' => false, 'last' => $completed ? (int) $post->ID : $last, 'index' => $completed ? 0 : $index + 1, 'completed' => $completed ? 1 : 0, 'status' => $status, 'message' => '#' . $post->ID . ' ' . $post->post_title . ' — ' . $relative . ' — ' . $message]);
    }
    public static function page(): void {
        if (!current_user_can('manage_woocommerce') || !current_user_can('upload_files')) wp_die('權限不足');
        $s = self::settings();
        wp_enqueue_script('wutm-missing-images', WUTM_URL . 'modules/missing-product-images/admin.js', [], WUTM_VERSION, true);
        wp_localize_script('wutm-missing-images', 'WUTMMissingImages', ['nonce' => wp_create_nonce('wutm_missing_images'), 'ajaxurl' => admin_url('admin-ajax.php')]);
        ?>
        <div class="wrap wutm-module-wrap">
        <h1>遺失商品圖片修復</h1>
        <p>將此工具安裝於 B 目的站。掃描商品完整描述及簡短描述中的圖片，從 A 來源站的相同 uploads 路徑補回缺檔。</p>
        <p>支援完整網址、相對路徑、srcset 與延遲載入屬性；格式：JPG、PNG、GIF、WebP、AVIF、BMP。只處理描述文字中的路徑，不包含獨立的商品精選圖片、相簿或 Elementor 自訂欄位。</p>
        <?php if (!empty($_GET['saved'])) : ?><div class="notice notice-success"><p>設定已儲存。</p></div><?php endif; ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="wutm_missing_images_save">
        <?php wp_nonce_field('wutm_missing_images_save'); ?>
        <table class="form-table"><tr><th><label for="wutm-source">A 來源站</label></th><td><input class="regular-text" id="wutm-source" type="url" name="source_url" required value="<?php echo esc_attr($s['source_url']); ?>"><p class="description">仍保留原始圖片的網站，例如 https://old.example.com 。</p></td></tr>
        <tr><th><label for="wutm-dest">B 目的站</label></th><td><input class="regular-text" id="wutm-dest" type="url" name="dest_url" required value="<?php echo esc_attr($s['dest_url']); ?>"><p class="description">用於比對商品描述中的圖片網址。檔案固定寫入目前這個 WordPress 的 uploads，不會寫入其他網站。</p></td></tr>
        <tr><th>媒體庫</th><td><label><input type="checkbox" name="attachment" value="1" <?php checked(!empty($s['attachment'])); ?>> 補回圖片後登錄媒體庫</label><p class="description">沿用已有媒體紀錄；新紀錄只登錄原圖尺寸，不產生縮圖、不改檔名。</p></td></tr></table>
        <?php submit_button('儲存 A / B 網站設定'); ?></form>
        <div class="card" style="max-width:100%"><h2>掃描與修復</h2><p>請先儲存設定再執行。僅掃描不寫入檔案；修復只補回缺少的檔案，不覆蓋既有圖片，也不修改商品描述。來源需可公開存取，單檔上限 30 MB。</p>
        <p><button type="button" class="button" id="wutm-mir-scan">僅掃描</button> <button type="button" class="button button-primary" id="wutm-mir-repair">開始修復缺圖</button> <button type="button" class="button" id="wutm-mir-stop" disabled>停止</button></p>
        <p id="wutm-mir-progress" role="status" aria-live="polite">尚未執行</p><p id="wutm-mir-summary"></p><pre id="wutm-mir-log" style="max-height:380px;overflow:auto;white-space:pre-wrap;overflow-wrap:anywhere"></pre>
        </div></div>
        <?php
    }
}
WUTM_Missing_Product_Images::boot();

