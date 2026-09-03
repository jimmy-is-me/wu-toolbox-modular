<?php
/**
 * Module: svg-upload
 *
 * Allows SVG uploads only after conservative server-side validation and adds
 * WordPress media metadata/preview fallbacks without rasterising the SVG.
 */
defined('ABSPATH') || exit;

final class WUTM_SVG_Upload {
    private const SLUG = 'wu-svg-upload';
    private const OPTION = 'wutm_svg_upload_settings';
    private const MAX_FILE_SIZE = 2097152;

    public static function boot(): void {
        add_action('admin_menu', [__CLASS__, 'menu'], 20);
        add_action('admin_post_wutm_svg_upload_save', [__CLASS__, 'save']);

        if (!self::is_enabled()) {
            return;
        }

        add_filter('upload_mimes', [__CLASS__, 'allow_mime']);
        add_filter('wp_check_filetype_and_ext', [__CLASS__, 'correct_filetype'], 10, 5);
        add_filter('wp_handle_upload_prefilter', [__CLASS__, 'validate_upload']);
        add_filter('rest_pre_upload_file', [__CLASS__, 'validate_upload'], 10, 2);
        add_filter('wp_generate_attachment_metadata', [__CLASS__, 'generate_metadata'], 20, 3);
        add_filter('wp_prepare_attachment_for_js', [__CLASS__, 'prepare_attachment'], 20, 3);
        add_filter('wp_get_attachment_image_src', [__CLASS__, 'image_src_fallback'], 20, 4);
    }

    private static function settings(): array {
        return wp_parse_args((array) get_option(self::OPTION, []), ['enabled' => true]);
    }

    private static function is_enabled(): bool {
        return !empty(self::settings()['enabled']);
    }

    public static function menu(): void {
        add_submenu_page(
            'wu-toolbox-modular',
            'SVG 上傳設定',
            'SVG 上傳',
            'manage_options',
            self::SLUG,
            [__CLASS__, 'page']
        );
    }

    public static function save(): void {
        if (!current_user_can('manage_options')) {
            wp_die('權限不足。', 403);
        }
        check_admin_referer('wutm_svg_upload_save');
        update_option(self::OPTION, ['enabled' => !empty($_POST['enabled'])], false);
        wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'updated' => 1], admin_url('admin.php')));
        exit;
    }

    public static function allow_mime(array $mimes): array {
        if (current_user_can('upload_files')) {
            $mimes['svg'] = 'image/svg+xml';
        }
        return $mimes;
    }

    public static function correct_filetype(array $data, string $file, string $filename, array $mimes, $real_mime = false): array {
        if (strtolower((string) pathinfo($filename, PATHINFO_EXTENSION)) === 'svg' && current_user_can('upload_files')) {
            $data['ext'] = 'svg';
            $data['type'] = 'image/svg+xml';
            $data['proper_filename'] = false;
        }
        return $data;
    }

    public static function validate_upload(array $file): array {
        $extension = strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if ($extension !== 'svg' || !empty($file['error'])) {
            return $file;
        }

        if (!current_user_can('upload_files')) {
            $file['error'] = '您沒有上傳 SVG 檔案的權限。';
            return $file;
        }

        $temporary_file = (string) ($file['tmp_name'] ?? '');
        $size = isset($file['size']) ? (int) $file['size'] : 0;
        if ($temporary_file === '' || !is_readable($temporary_file) || $size < 1 || $size > self::MAX_FILE_SIZE) {
            $file['error'] = 'SVG 檔案無法讀取，或檔案超過 2 MB 安全限制。';
            return $file;
        }

        $validation = self::validate_svg_file($temporary_file);
        if (is_wp_error($validation)) {
            $file['error'] = $validation->get_error_message();
            return $file;
        }

        $file['type'] = 'image/svg+xml';
        return $file;
    }

    private static function validate_svg_file(string $file) {
        if (!class_exists('DOMDocument')) {
            return new WP_Error('wutm_svg_dom_missing', '伺服器缺少 SVG 安全檢查所需的 DOM 擴充套件，已拒絕上傳。');
        }

        $source = file_get_contents($file);
        if ($source === false || preg_match('/<!DOCTYPE|<!ENTITY/i', $source)) {
            return new WP_Error('wutm_svg_unsafe', 'SVG 含有不允許的文件實體或宣告。');
        }

        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($source, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded || !$dom->documentElement || strtolower($dom->documentElement->localName) !== 'svg') {
            return new WP_Error('wutm_svg_invalid', '檔案不是有效的 SVG。');
        }

        $forbidden = ['script', 'foreignobject', 'iframe', 'object', 'embed', 'audio', 'video', 'canvas'];
        foreach ($dom->getElementsByTagName('*') as $element) {
            if (in_array(strtolower($element->localName), $forbidden, true)) {
                return new WP_Error('wutm_svg_forbidden_element', 'SVG 含有不允許的元素。');
            }
            if (!$element->hasAttributes()) {
                continue;
            }
            foreach ($element->attributes as $attribute) {
                $name = strtolower($attribute->localName);
                $value = trim((string) $attribute->nodeValue);
                if (strpos($name, 'on') === 0) {
                    return new WP_Error('wutm_svg_event', 'SVG 含有不允許的事件程式碼。');
                }
                if (in_array($name, ['href', 'src'], true) && $value !== '' && strpos($value, '#') !== 0) {
                    return new WP_Error('wutm_svg_external', 'SVG 不允許引用外部或嵌入式資源。');
                }
                if ($name === 'style' && preg_match('/url\s*\(|expression\s*\(|javascript\s*:/i', $value)) {
                    return new WP_Error('wutm_svg_style', 'SVG 含有不允許的樣式內容。');
                }
            }
        }

        return true;
    }

    private static function dimensions(string $file): array {
        $width = 0;
        $height = 0;
        if (!is_readable($file)) {
            return ['width' => 1, 'height' => 1];
        }

        $source = (string) file_get_contents($file);
        if (class_exists('DOMDocument') && $source !== '') {
            $dom = new DOMDocument();
            $previous = libxml_use_internal_errors(true);
            $loaded = $dom->loadXML($source, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            if ($loaded && $dom->documentElement) {
                $root = $dom->documentElement;
                $width = (int) preg_replace('/[^0-9.]/', '', (string) $root->getAttribute('width'));
                $height = (int) preg_replace('/[^0-9.]/', '', (string) $root->getAttribute('height'));
                if ((!$width || !$height) && $root->hasAttribute('viewBox')) {
                    $viewbox = preg_split('/[\s,]+/', trim((string) $root->getAttribute('viewBox'))) ?: [];
                    if (count($viewbox) === 4) {
                        $width = $width ?: max(0, (int) round((float) $viewbox[2]));
                        $height = $height ?: max(0, (int) round((float) $viewbox[3]));
                    }
                }
            }
        }
        return ['width' => max(1, $width), 'height' => max(1, $height)];
    }

    public static function generate_metadata($metadata, $attachment_id, $context = 'create') {
        if (get_post_mime_type($attachment_id) !== 'image/svg+xml') {
            return $metadata;
        }

        $file = get_attached_file($attachment_id);
        if (!$file || !is_readable($file)) {
            return is_array($metadata) ? $metadata : [];
        }

        $dimensions = self::dimensions($file);
        $uploads = wp_get_upload_dir();
        $relative = ltrim(str_replace((string) $uploads['basedir'], '', $file), '/\\');
        return [
            'width' => $dimensions['width'],
            'height' => $dimensions['height'],
            'file' => $relative,
            'filesize' => function_exists('wp_filesize') ? (int) wp_filesize($file) : (int) filesize($file),
            'sizes' => [],
        ];
    }

    public static function prepare_attachment(array $response, $attachment, $metadata): array {
        if (($response['mime'] ?? '') !== 'image/svg+xml') {
            return $response;
        }

        $dimensions = self::dimensions((string) get_attached_file($attachment->ID));
        $response['sizes'] = [
            'full' => [
                'url' => $response['url'],
                'width' => $dimensions['width'],
                'height' => $dimensions['height'],
                'orientation' => $dimensions['width'] >= $dimensions['height'] ? 'landscape' : 'portrait',
            ],
        ];
        return $response;
    }

    public static function image_src_fallback($image, $attachment_id, $size, $icon) {
        if (get_post_mime_type($attachment_id) !== 'image/svg+xml') {
            return $image;
        }

        $dimensions = self::dimensions((string) get_attached_file($attachment_id));
        if (!is_array($image)) {
            return [wp_get_attachment_url($attachment_id), $dimensions['width'], $dimensions['height'], false];
        }
        $image[1] = max(1, (int) ($image[1] ?? $dimensions['width']));
        $image[2] = max(1, (int) ($image[2] ?? $dimensions['height']));
        return $image;
    }

    public static function page(): void {
        if (!current_user_can('manage_options')) {
            wp_die('權限不足。', 403);
        }
        $enabled = self::is_enabled();
        ?>
        <div class="wrap wutm-module-wrap">
            <h1>SVG 上傳設定</h1>
            <p class="wutm-module-subtitle">允許具有上傳權限的使用者上傳已通過安全檢查的 SVG，並讓媒體庫、精選圖片與圖片輸出正常取得尺寸。</p>
            <?php if (!empty($_GET['updated'])) : ?><div class="notice notice-success is-dismissible"><p>設定已儲存。</p></div><?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="card" style="max-width:760px;padding:24px;">
                <input type="hidden" name="action" value="wutm_svg_upload_save">
                <?php wp_nonce_field('wutm_svg_upload_save'); ?>
                <h2 style="margin-top:0;">功能設定</h2>
                <label><input type="checkbox" name="enabled" value="1" <?php checked($enabled); ?>> 啟用 SVG 上傳</label>
                <p class="description">僅允許有效 SVG；會拒絕腳本、事件屬性、危險樣式、外部引用與文件實體。SVG 不會建立點陣縮圖，原始向量檔會直接用於顯示。</p>
                <?php submit_button('儲存設定'); ?>
            </form>
        </div>
        <?php
    }
}

WUTM_SVG_Upload::boot();
