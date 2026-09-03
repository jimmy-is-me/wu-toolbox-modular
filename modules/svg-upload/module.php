<?php
/**
 * Module: svg-upload
 *
 * Enables SVG uploads for permitted users after conservative server-side
 * validation. Unsafe or externally referenced SVG files are rejected.
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
    }

    private static function settings(): array {
        return wp_parse_args((array) get_option(self::OPTION, []), [
            'enabled' => true,
        ]);
    }

    private static function is_enabled(): bool {
        $settings = self::settings();
        return !empty($settings['enabled']);
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
            wp_die('權限不足', 403);
        }
        check_admin_referer('wutm_svg_upload_save');

        update_option(self::OPTION, [
            'enabled' => !empty($_POST['enabled']),
        ], false);

        wp_safe_redirect(add_query_arg([
            'page' => self::SLUG,
            'updated' => '1',
        ], admin_url('admin.php')));
        exit;
    }

    public static function allow_mime(array $mimes): array {
        if (!current_user_can('upload_files')) {
            return $mimes;
        }

        $mimes['svg'] = 'image/svg+xml';
        return $mimes;
    }

    public static function correct_filetype(array $data, string $file, string $filename, array $mimes, $real_mime = false): array {
        $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        if ($extension === 'svg' && current_user_can('upload_files')) {
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

        if (!class_exists('DOMDocument')) {
            $file['error'] = '伺服器缺少 SVG 安全檢查所需的 DOM 擴充套件，已拒絕上傳。';
            return $file;
        }

        $svg = file_get_contents($temporary_file);
        if (!is_string($svg) || $svg === '' || preg_match('/<!DOCTYPE|<!ENTITY/i', $svg)) {
            $file['error'] = 'SVG 包含不允許的文件宣告或實體，已拒絕上傳。';
            return $file;
        }

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        $loaded = $document->loadXML($svg, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded || !$document->documentElement || strtolower($document->documentElement->localName) !== 'svg') {
            $file['error'] = '這不是有效的 SVG 圖檔，已拒絕上傳。';
            return $file;
        }

        $forbidden_elements = ['script', 'foreignobject', 'iframe', 'object', 'embed', 'audio', 'video', 'canvas'];
        foreach ($document->getElementsByTagName('*') as $element) {
            if (in_array(strtolower($element->localName), $forbidden_elements, true)) {
                $file['error'] = 'SVG 包含不安全的元素，已拒絕上傳。';
                return $file;
            }

            if (!$element->hasAttributes()) {
                continue;
            }

            foreach ($element->attributes as $attribute) {
                $name = strtolower($attribute->localName ?: $attribute->name);
                $value = trim((string) $attribute->value);
                if (strpos($name, 'on') === 0) {
                    $file['error'] = 'SVG 包含事件程式碼，已拒絕上傳。';
                    return $file;
                }
                if (in_array($name, ['href', 'src'], true) && $value !== '' && strpos($value, '#') !== 0) {
                    $file['error'] = 'SVG 不可引用外部或資料網址，已拒絕上傳。';
                    return $file;
                }
                if ($name === 'style' && preg_match('/url\s*\(|expression\s*\(|javascript\s*:/i', $value)) {
                    $file['error'] = 'SVG 樣式包含不安全的內容，已拒絕上傳。';
                    return $file;
                }
            }
        }

        $file['type'] = 'image/svg+xml';
        return $file;
    }

    public static function page(): void {
        if (!current_user_can('manage_options')) {
            wp_die('權限不足', 403);
        }
        $settings = self::settings();
        ?>
        <div class="wrap wutm-module-wrap wutm-svg-upload">
            <h1>SVG 上傳設定</h1>
            <p class="wutm-module-subtitle">允許具備上傳權限的後台使用者上傳經過安全檢查的 SVG 向量圖檔。</p>

            <?php if (!empty($_GET['updated'])) : ?>
                <div class="notice notice-success is-dismissible"><p>SVG 上傳設定已儲存。</p></div>
            <?php endif; ?>

            <div class="card" style="max-width:100%;margin:20px 0;">
                <h2 style="margin-top:0;">目前狀態</h2>
                <p><strong><?php echo !empty($settings['enabled']) ? '運作中' : '已暫停'; ?></strong> — 只在後台上傳 SVG 時執行安全檢查，不影響前台載入速度。</p>
            </div>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="wutm_svg_upload_save">
                <?php wp_nonce_field('wutm_svg_upload_save'); ?>
                <table class="form-table" role="presentation"><tbody>
                    <tr>
                        <th scope="row">啟用 SVG 上傳</th>
                        <td>
                            <label><input type="checkbox" name="enabled" value="1" <?php checked(!empty($settings['enabled'])); ?>> 允許上傳安全的 SVG 圖檔</label>
                            <p class="description">僅允許有上傳媒體權限的使用者使用；每個 SVG 上傳前都會經過伺服器端檢查。</p>
                        </td>
                    </tr>
                </tbody></table>
                <?php submit_button('儲存設定'); ?>
            </form>

            <div class="card" style="max-width:100%;margin-top:20px;">
                <h2 style="margin-top:0;">安全規則</h2>
                <ul style="list-style:disc;padding-left:22px;">
                    <li>檔案大小上限為 2 MB，並必須是有效的 SVG XML 文件。</li>
                    <li>含有腳本、事件處理器、外部引用、資料網址或危險 CSS 的 SVG 會被拒絕。</li>
                    <li>SVG 可能含有程式碼；請只上傳可信來源的圖檔，並定期更新網站與使用者權限。</li>
                </ul>
            </div>
        </div>
        <?php
    }
}

WUTM_SVG_Upload::boot();
