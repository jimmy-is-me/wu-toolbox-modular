<?php
/**
 * Module: auto-upload-images
 *
 * Imports externally hosted images into the WordPress media library when
 * content is saved, then replaces the remote image URL in the post content.
 */
defined('ABSPATH') || exit;

final class WUTM_Auto_Upload_Images {
    private const SLUG = 'wu-auto-upload-images';
    private const OPTION = 'wutm_auto_upload_images_settings';

    private static bool $processing = false;

    public static function boot(): void {
        add_action('admin_menu', [__CLASS__, 'menu'], 20);
        add_action('admin_post_wutm_auto_upload_images_save', [__CLASS__, 'save_settings']);

        if (self::is_enabled()) {
            add_filter('wp_insert_post_data', [__CLASS__, 'import_external_images'], 20, 2);
        }
    }

    private static function settings(): array {
        return wp_parse_args((array) get_option(self::OPTION, []), [
            'enabled' => true,
            'exclude_post_types' => [],
            'exclude_hosts' => '',
        ]);
    }

    private static function is_enabled(): bool {
        $settings = self::settings();
        return !empty($settings['enabled']);
    }

    public static function menu(): void {
        add_submenu_page(
            'wu-toolbox-modular',
            '自動上傳圖片設定',
            '自動上傳圖片',
            'manage_options',
            self::SLUG,
            [__CLASS__, 'page']
        );
    }

    public static function save_settings(): void {
        if (!current_user_can('manage_options')) {
            wp_die('權限不足', 403);
        }

        check_admin_referer('wutm_auto_upload_images_save');

        $available_types = get_post_types([], 'names');
        $raw_types = isset($_POST['exclude_post_types']) && is_array($_POST['exclude_post_types'])
            ? wp_unslash($_POST['exclude_post_types'])
            : [];
        $exclude_types = [];

        foreach ($raw_types as $post_type) {
            $post_type = sanitize_key($post_type);
            if (isset($available_types[$post_type])) {
                $exclude_types[] = $post_type;
            }
        }

        $exclude_hosts = isset($_POST['exclude_hosts'])
            ? sanitize_textarea_field(wp_unslash($_POST['exclude_hosts']))
            : '';

        update_option(self::OPTION, [
            'enabled' => !empty($_POST['enabled']),
            'exclude_post_types' => array_values(array_unique($exclude_types)),
            'exclude_hosts' => $exclude_hosts,
        ], false);

        wp_safe_redirect(add_query_arg([
            'page' => self::SLUG,
            'updated' => '1',
        ], admin_url('admin.php')));
        exit;
    }

    public static function import_external_images($data, $postarr): array {
        if (!is_array($data) || self::$processing || !self::is_enabled()) {
            return is_array($data) ? $data : [];
        }

        $content = isset($data['post_content']) ? wp_unslash((string) $data['post_content']) : '';
        if ($content === '' || stripos($content, '<img') === false) {
            return $data;
        }

        $post_type = sanitize_key((string) ($data['post_type'] ?? ($postarr['post_type'] ?? 'post')));
        if (in_array($post_type, ['attachment', 'revision', 'nav_menu_item'], true)) {
            return $data;
        }

        $settings = self::settings();
        if (in_array($post_type, (array) $settings['exclude_post_types'], true)) {
            return $data;
        }

        $post_id = absint($postarr['ID'] ?? 0);
        if (
            (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
            || ($post_id && wp_is_post_revision($post_id))
            || ($post_id && wp_is_post_autosave($post_id))
        ) {
            return $data;
        }

        if (!current_user_can('upload_files')) {
            return $data;
        }
        if ($post_id && !current_user_can('edit_post', $post_id)) {
            return $data;
        }

        $cache = [];
        self::$processing = true;

        try {
            $processed = preg_replace_callback(
                '/<img\b[^>]*>/is',
                static function (array $match) use ($post_id, &$cache): string {
                    return self::process_image_tag($match[0], $post_id, $cache);
                },
                $content
            );
        } finally {
            self::$processing = false;
        }

        if (is_string($processed) && $processed !== $content) {
            $data['post_content'] = wp_slash($processed);
        }

        return $data;
    }

    private static function process_image_tag(string $tag, int $post_id, array &$cache): string {
        if (!preg_match('/\bsrc\s*=\s*(["\'])(.*?)\1/is', $tag, $source_match)) {
            return $tag;
        }

        $source = html_entity_decode(trim($source_match[2]), ENT_QUOTES, 'UTF-8');
        $url = self::normalize_external_url($source);

        if ($url === '') {
            return $tag;
        }

        if (isset($cache[$url])) {
            $uploaded = $cache[$url];
        } else {
            $alt = '';
            if (preg_match('/\balt\s*=\s*(["\'])(.*?)\1/is', $tag, $alt_match)) {
                $alt = sanitize_text_field(html_entity_decode($alt_match[2], ENT_QUOTES, 'UTF-8'));
            }

            $uploaded = self::sideload_image($url, $post_id, $alt);
            $cache[$url] = $uploaded;
        }

        if (!is_array($uploaded) || empty($uploaded['url'])) {
            return $tag;
        }

        $quote = $source_match[1];
        $replacement = 'src=' . $quote . esc_url($uploaded['url']) . $quote;
        $updated_tag = preg_replace('/\bsrc\s*=\s*(["\']).*?\1/is', $replacement, $tag, 1);

        if (!is_string($updated_tag)) {
            return $tag;
        }

        // Remove stale remote responsive sources. WordPress can regenerate them
        // from the new local attachment when the content is rendered.
        $updated_tag = preg_replace('/\s+srcset\s*=\s*(["\']).*?\1/is', '', $updated_tag);
        $updated_tag = preg_replace('/\s+sizes\s*=\s*(["\']).*?\1/is', '', (string) $updated_tag);

        return is_string($updated_tag) ? $updated_tag : $tag;
    }

    private static function normalize_external_url(string $url): string {
        if (strpos($url, '//') === 0) {
            $url = (is_ssl() ? 'https:' : 'http:') . $url;
        }

        $url = esc_url_raw($url, ['http', 'https']);
        if ($url === '' || !wp_http_validate_url($url)) {
            return '';
        }

        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        $home_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));

        if ($host === '' || self::same_host($host, $home_host)) {
            return '';
        }

        foreach (self::excluded_hosts() as $excluded_host) {
            if (self::same_host($host, $excluded_host) || self::is_subdomain_of($host, $excluded_host)) {
                return '';
            }
        }

        return $url;
    }

    private static function same_host(string $first, string $second): bool {
        return preg_replace('/^www\d*\./i', '', $first) === preg_replace('/^www\d*\./i', '', $second);
    }

    private static function is_subdomain_of(string $host, string $parent): bool {
        $host = preg_replace('/^www\d*\./i', '', $host);
        $parent = preg_replace('/^www\d*\./i', '', $parent);

        if ($parent === '' || strlen($host) <= strlen($parent)) {
            return false;
        }

        return substr($host, -(strlen($parent) + 1)) === '.' . $parent;
    }

    private static function excluded_hosts(): array {
        $settings = self::settings();
        $lines = preg_split('/\r\n|\r|\n/', (string) $settings['exclude_hosts']);
        $hosts = [];

        foreach ((array) $lines as $line) {
            $line = trim(strtolower($line));
            if ($line === '') {
                continue;
            }

            if (strpos($line, '://') === false) {
                $line = 'https://' . ltrim($line, '/');
            }

            $host = (string) wp_parse_url($line, PHP_URL_HOST);
            if ($host !== '') {
                $hosts[] = preg_replace('/^www\d*\./i', '', $host);
            }
        }

        return array_values(array_unique($hosts));
    }

    private static function sideload_image(string $url, int $post_id, string $alt): ?array {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $temporary_file = download_url($url, 20);
        if (is_wp_error($temporary_file)) {
            return null;
        }

        $mime = wp_get_image_mime($temporary_file);
        $extensions = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
            'image/bmp' => 'bmp',
            'image/tiff' => 'tif',
        ];

        if (!$mime || !isset($extensions[$mime])) {
            wp_delete_file($temporary_file);
            return null;
        }

        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        $original_name = sanitize_file_name(wp_basename(rawurldecode($path)));
        $base_name = sanitize_file_name(pathinfo($original_name, PATHINFO_FILENAME));
        if ($base_name === '') {
            $base_name = 'external-image-' . wp_generate_password(8, false, false);
        }

        $file_array = [
            'name' => $base_name . '.' . $extensions[$mime],
            'tmp_name' => $temporary_file,
        ];

        $attachment_id = media_handle_sideload($file_array, $post_id, $alt);
        if (is_wp_error($attachment_id)) {
            wp_delete_file($temporary_file);
            return null;
        }

        if ($alt !== '') {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt);
        }

        $local_url = wp_get_attachment_url($attachment_id);
        if (!$local_url) {
            return null;
        }

        return [
            'id' => (int) $attachment_id,
            'url' => esc_url_raw($local_url),
        ];
    }

    public static function page(): void {
        if (!current_user_can('manage_options')) {
            wp_die('權限不足', 403);
        }

        $settings = self::settings();
        $post_types = get_post_types(['show_ui' => true], 'objects');
        ?>
        <div class="wrap wutm-module-wrap wutm-auto-upload-images">
            <h1>自動上傳圖片設定</h1>
            <p class="wutm-module-subtitle">儲存內容時，將文章中的外部圖片下載至本站媒體庫，並自動替換為本機圖片網址。</p>

            <?php if (!empty($_GET['updated'])) : ?>
                <div class="notice notice-success is-dismissible"><p>自動上傳圖片設定已儲存。</p></div>
            <?php endif; ?>

            <div class="card" style="max-width:100%;margin:20px 0;">
                <h2 style="margin-top:0;">目前狀態</h2>
                <p>
                    <strong><?php echo !empty($settings['enabled']) ? '運作中' : '已暫停'; ?></strong>
                    — 只在內容真正儲存時檢查圖片，不會在前台瀏覽頁面時執行。
                </p>
            </div>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="wutm_auto_upload_images_save">
                <?php wp_nonce_field('wutm_auto_upload_images_save'); ?>

                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row">啟用功能</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="enabled" value="1" <?php checked(!empty($settings['enabled'])); ?>>
                                    儲存內容時自動上傳外部圖片
                                </label>
                                <p class="description">下載成功後會建立媒體庫附件並替換圖片網址；下載失敗時保留原網址，不中斷文章儲存。</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">排除內容類型</th>
                            <td>
                                <fieldset>
                                    <?php foreach ($post_types as $post_type) : ?>
                                        <?php if (in_array($post_type->name, ['attachment', 'revision', 'nav_menu_item'], true)) continue; ?>
                                        <label style="display:inline-block;min-width:180px;margin:0 12px 10px 0;">
                                            <input
                                                type="checkbox"
                                                name="exclude_post_types[]"
                                                value="<?php echo esc_attr($post_type->name); ?>"
                                                <?php checked(in_array($post_type->name, (array) $settings['exclude_post_types'], true)); ?>
                                            >
                                            <?php echo esc_html($post_type->labels->singular_name ?: $post_type->label); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </fieldset>
                                <p class="description">勾選後，該內容類型儲存時不會下載外部圖片。</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wutm-auto-upload-exclude-hosts">排除網域</label></th>
                            <td>
                                <textarea
                                    id="wutm-auto-upload-exclude-hosts"
                                    name="exclude_hosts"
                                    rows="6"
                                    class="large-text code"
                                    placeholder="example.com&#10;cdn.example.net"
                                ><?php echo esc_textarea((string) $settings['exclude_hosts']); ?></textarea>
                                <p class="description">每行輸入一個不下載的網域；其子網域也會一併排除。本站網域永遠不會重複下載。</p>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <?php submit_button('儲存設定'); ?>
            </form>

            <div class="card" style="max-width:100%;margin-top:20px;">
                <h2 style="margin-top:0;">使用說明</h2>
                <ul style="list-style:disc;padding-left:22px;">
                    <li>支援文章、頁面、WooCommerce 商品及其他具備後台介面的自訂內容類型。</li>
                    <li>只處理 HTTP／HTTPS 外部圖片，本站圖片、資料 URI 與無效網址會略過。</li>
                    <li>下載會使用 WordPress 的安全網址驗證與媒體上傳流程，並保留原圖片替代文字。</li>
                    <li>建議第一次使用前先備份網站；大量外部圖片會增加第一次儲存所需時間。</li>
                </ul>
            </div>
        </div>
        <?php
    }
}

WUTM_Auto_Upload_Images::boot();
