<?php
/**
 * Module: media-encoder
 * Safe WebP conversion, upload resizing and thumbnail-size controls.
 * Loaded only when enabled in WU Toolbox Modular.
 */
defined('ABSPATH') || exit;

const WUTM_MEDIA_OPTION = 'wutm_media_encoder_settings';

function wutm_media_defaults(): array {
    return [
        'webp_enabled' => false,
        'quality' => 82,
        'formats' => ['jpeg', 'png'],
        'replace_original' => false,
        'resize_enabled' => false,
        'max_width' => 2560,
        'max_height' => 0,
        'disabled_sizes' => [],
    ];
}

function wutm_media_settings(): array {
    return wp_parse_args((array) get_option(WUTM_MEDIA_OPTION, []), wutm_media_defaults());
}

// 系統狀態偵測 (新增功能)
function wutm_media_get_system_status(): array {
    $gd_info = function_exists('gd_info') ? gd_info() : [];
    $gd_webp = isset($gd_info['WebP Support']) && $gd_info['WebP Support'];
    $imagick_webp = class_exists('Imagick') && count(preg_grep('/WEBP/i', (array) \Imagick::queryFormats())) > 0;

    return [
        'gd_webp' => $gd_webp,
        'imagick_webp' => $imagick_webp,
        'supported' => $gd_webp || $imagick_webp,
        'memory_limit' => ini_get('memory_limit'),
        'upload_max' => ini_get('upload_max_filesize'),
    ];
}

function wutm_media_convertible(string $mime, array $settings): bool {
    $mime = strtolower($mime);
    $map = ['jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif'];
    return in_array($mime, array_intersect_key($map, array_flip((array) $settings['formats'])), true);
}

function wutm_media_has_memory(string $path): bool {
    $size = @getimagesize($path);
    if (!is_array($size)) return true;
    $limit = wp_convert_hr_to_bytes((string) ini_get('memory_limit'));
    return $limit <= 0 || memory_get_usage(true) + ((int) $size[0] * (int) $size[1] * 8 * 1.5) <= $limit; // 增加 1.5 倍緩衝以策安全
}

function wutm_media_to_webp(string $source, int $quality) {
    if (!is_readable($source) || !wutm_media_has_memory($source)) {
        return new WP_Error('media_encoder_memory', __('圖片太大或可用記憶體不足，無法轉換。', 'wu-toolbox-modular'));
    }
    wp_raise_memory_limit('image');

    $editor = wp_get_image_editor($source);
    if (is_wp_error($editor)) return $editor;

    $editor->set_quality(max(1, min(100, $quality)));
    $info = pathinfo($source);
    $path = $info['dirname'] . '/' . $info['filename'] . '.webp';

    // 避免檔名重複
    if (file_exists($path)) {
        $path = $info['dirname'] . '/' . wp_unique_filename($info['dirname'], $info['filename'] . '.webp');
    }

    $saved = $editor->save($path, 'image/webp');
    if (is_wp_error($saved) || ($saved['mime-type'] ?? '') !== 'image/webp') {
        if (is_array($saved) && !empty($saved['path']) && file_exists($saved['path'])) {
            wp_delete_file($saved['path']);
        }
        return is_wp_error($saved) ? $saved : new WP_Error('media_encoder_webp', __('伺服器處理 WebP 編碼失敗。', 'wu-toolbox-modular'));
    }
    return $saved;
}

function wutm_media_resize_upload($upload) {
    $s = wutm_media_settings();
    if (empty($s['resize_enabled']) || !is_array($upload) || isset($upload['error']) || !in_array($upload['type'] ?? '', ['image/jpeg','image/png','image/webp'], true)) return $upload;

    $file = $upload['file'] ?? '';
    $size = $file ? @getimagesize($file) : false;
    $w = (int) ($s['max_width'] ?? 0); $h = (int) ($s['max_height'] ?? 0);

    if (!$size || ($w <= 0 && $h <= 0) || ($w <= 0 || $size[0] <= $w) && ($h <= 0 || $size[1] <= $h) || !wutm_media_has_memory($file)) return $upload;

    $editor = wp_get_image_editor($file);
    if (is_wp_error($editor)) return $upload;

    $result = $editor->resize($w > 0 ? $w : null, $h > 0 ? $h : null, false);
    if (!is_wp_error($result)) $editor->save($file);

    return $upload;
}

function wutm_media_convert_upload($upload) {
    $s = wutm_media_settings();
    // 強化：若上傳已出錯，或未啟用，或不支援轉換，直接返回原圖
    if (empty($s['webp_enabled']) || !is_array($upload) || isset($upload['error']) || !wutm_media_convertible((string) ($upload['type'] ?? ''), $s)) {
        return $upload;
    }

    $saved = wutm_media_to_webp((string) ($upload['file'] ?? ''), (int) $s['quality']);
    if (is_wp_error($saved)) {
        return $upload; // 轉換失敗時，優雅退回，保留並上傳原圖
    }

    $old_file = $upload['file'];
    if (!empty($s['replace_original']) && file_exists($old_file)) {
        wp_delete_file($old_file);
    }

    $uploads = wp_get_upload_dir();
    $url = str_replace(wp_normalize_path($uploads['basedir']), $uploads['baseurl'], wp_normalize_path($saved['path']));

    return [
        'file' => $saved['path'],
        'url'  => $url,
        'type' => 'image/webp',
        'ext'  => 'webp' // 確保有些外掛檢查 ext 時不會報錯
    ];
}

add_filter('wp_handle_upload', 'wutm_media_resize_upload', 10);
add_filter('wp_handle_upload', 'wutm_media_convert_upload', 20);

// ==========================================
// 單一圖片手動轉換功能 (Media Edit 頁面)
// ==========================================
add_action('attachment_submitbox_misc_actions', 'wutm_add_manual_convert_button');
function wutm_add_manual_convert_button() {
    global $post;
    // 只允許 JPEG/PNG 顯示按鈕
    $allowed_mimes = ['image/jpeg', 'image/png'];
    if (!in_array($post->post_mime_type, $allowed_mimes, true)) return;

    $status = wutm_media_get_system_status();
    if (!$status['supported']) return;
    ?>
    <div class="misc-pub-section">
        <button type="button" class="button button-secondary" id="wutm-convert-btn" style="width:100%; text-align:center;">
            <span class="dashicons dashicons-image-filter" style="margin-top:4px;"></span> 轉換為 WebP 並取代
        </button>
        <span id="wutm-convert-status" style="display:none; color:#2271b1; margin-top:5px; text-align:center; display:block;"></span>
        <script>
        jQuery(document).ready(function($) {
            $('#wutm-convert-btn').on('click', function(e) {
                e.preventDefault();
                if(!confirm('這將會把此圖片轉換為 WebP，並刪除舊的原始檔與縮圖。確定要繼續嗎？')) return;

                var btn = $(this);
                var status = $('#wutm-convert-status');
                btn.prop('disabled', true);
                status.text('處理中，請稍候...').show();

                $.post(ajaxurl, {
                    action: 'wutm_convert_single_webp',
                    attachment_id: <?php echo intval($post->ID); ?>,
                    _ajax_nonce: '<?php echo wp_create_nonce('wutm_convert_single_' . $post->ID); ?>'
                }, function(res) {
                    if(res.success) {
                        status.css('color', 'green').text('轉換成功！頁面即將重整...');
                        setTimeout(function(){ location.reload(); }, 1500);
                    } else {
                        status.css('color', 'red').text('錯誤: ' + (res.data || '轉換失敗'));
                        btn.prop('disabled', false);
                    }
                }).fail(function() {
                    status.css('color', 'red').text('發生未知的伺服器錯誤。');
                    btn.prop('disabled', false);
                });
            });
        });
        </script>
    </div>
    <?php
}

add_action('wp_ajax_wutm_convert_single_webp', 'wutm_ajax_convert_single_webp');
function wutm_ajax_convert_single_webp() {
    $post_id = isset($_POST['attachment_id']) ? intval($_POST['attachment_id']) : 0;
    check_ajax_referer('wutm_convert_single_' . $post_id);
    if (!current_user_can('edit_post', $post_id)) wp_send_json_error('權限不足');

    $file_path = get_attached_file($post_id);
    if (!file_exists($file_path)) wp_send_json_error('找不到原始檔案');
    if (mime_content_type($file_path) === 'image/webp') wp_send_json_error('此圖片已經是 WebP 格式');

    $s = wutm_media_settings();
    $saved = wutm_media_to_webp($file_path, (int) $s['quality']);
    if (is_wp_error($saved)) wp_send_json_error($saved->get_error_message());

    $new_file_path = $saved['path'];
    $old_meta = wp_get_attachment_metadata($post_id);

    // 刪除舊的原始檔案與所有舊縮圖
    $upload_dir = wp_upload_dir();
    $base_dir = trailingslashit($upload_dir['basedir']) . dirname(get_post_meta($post_id, '_wp_attached_file', true)) . '/';

    @unlink($file_path); // 刪原圖
    if (is_array($old_meta) && isset($old_meta['sizes'])) {
        foreach ($old_meta['sizes'] as $size) {
            @unlink($base_dir . $size['file']);
        }
    }

    // 更新資料庫資訊
    $new_relative_path = _wp_relative_upload_path($new_file_path);
    update_attached_file($post_id, $new_file_path);
    wp_update_post(['ID' => $post_id, 'post_mime_type' => 'image/webp']);

    // 重新產生縮圖與 Metadata
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    $new_meta = wp_generate_attachment_metadata($post_id, $new_file_path);
    wp_update_attachment_metadata($post_id, $new_meta);

    wp_send_json_success('轉換完成');
}
// ==========================================

function wutm_media_sizes(): array {
    global $_wp_additional_image_sizes;
    $out = [];
    foreach (get_intermediate_image_sizes() as $name) {
        $extra = $_wp_additional_image_sizes[$name] ?? [];
        $out[$name] = [
            'width' => (int) ($extra['width'] ?? get_option($name . '_size_w')),
            'height' => (int) ($extra['height'] ?? get_option($name . '_size_h')),
            'crop' => !empty($extra['crop'] ?? get_option($name . '_crop'))
        ];
    }
    return $out;
}

add_filter('intermediate_image_sizes_advanced', function ($sizes) {
    $disabled = (array) (wutm_media_settings()['disabled_sizes'] ?? []);
    foreach ($disabled as $size) unset($sizes[$size]);
    return $sizes;
});

add_filter('big_image_size_threshold', function ($threshold) {
    return in_array('scaled', (array) (wutm_media_settings()['disabled_sizes'] ?? []), true) ? false : $threshold;
});

add_action('admin_menu', function () {
    add_submenu_page('wu-toolbox-modular', __('媒體編碼器', 'wu-toolbox-modular'), __('媒體編碼器', 'wu-toolbox-modular'), 'manage_options', 'wu-media-encoder', 'wutm_media_settings_page');
}, 30);

add_action('admin_post_wutm_save_media_encoder', function () {
    if (!current_user_can('manage_options')) wp_die(esc_html__('權限不足。', 'wu-toolbox-modular'));
    check_admin_referer('wutm_save_media_encoder');

    $sizes = array_keys(wutm_media_sizes());
    $formats = array_values(array_intersect(['jpeg','png','gif'], array_map('sanitize_key', (array) ($_POST['formats'] ?? []))));

    $data = [
        'webp_enabled' => !empty($_POST['webp_enabled']),
        'quality' => max(1, min(100, absint($_POST['quality'] ?? 82))),
        'formats' => $formats ?: ['jpeg','png'],
        'replace_original' => !empty($_POST['replace_original']),
        'resize_enabled' => !empty($_POST['resize_enabled']),
        'max_width' => min(10000, absint($_POST['max_width'] ?? 2560)),
        'max_height' => min(10000, absint($_POST['max_height'] ?? 0)),
        'disabled_sizes' => array_values(array_intersect($sizes, array_map('sanitize_key', (array) ($_POST['disabled_sizes'] ?? [])))),
    ];
    update_option(WUTM_MEDIA_OPTION, $data, false);
    wp_safe_redirect(add_query_arg(['page' => 'wu-media-encoder', 'updated' => '1'], admin_url('admin.php')));
    exit;
});

function wutm_media_settings_page(): void {
    if (!current_user_can('manage_options')) return;
    $s = wutm_media_settings();
    $sizes = wutm_media_sizes();
    $status = wutm_media_get_system_status();
    ?>
    <div class="wrap">
        <h1>媒體編碼器</h1>
        <?php if (isset($_GET['updated'])): ?>
            <div class="notice notice-success is-dismissible"><p>設定已成功儲存。</p></div>
        <?php endif; ?>

        <p>優化您的網站圖片，自動進行 WebP 轉換、尺寸限制與縮圖管理。</p>

        <!-- 系統狀態面板 -->
        <div class="postbox" style="margin-top:20px;">
            <h2 class="hndle" style="padding:10px 15px; margin:0;"><span>⚙️ 系統處理狀態與支援度</span></h2>
            <div class="inside" style="margin-bottom:0;">
                <p>
                    <strong>WebP 轉換支援：</strong>
                    <?php if ($status['supported']): ?>
                        <span style="color:green; font-weight:bold;">支援 (✓)</span>
                        <span style="color:#666;">
                            [ 引擎：<?php echo $status['imagick_webp'] ? 'Imagick' : ''; echo ($status['imagick_webp'] && $status['gd_webp']) ? ' / ' : ''; echo $status['gd_webp'] ? 'GD' : ''; ?> ]
                        </span>
                    <?php else: ?>
                        <span style="color:red; font-weight:bold;">不支援 (✗)</span> — 此伺服器未偵測到 Imagick 或 GD 的 WebP 模組，轉檔功能將自動略過。
                    <?php endif; ?>
                </p>
                <p>
                    <strong>PHP 記憶體限制 (Memory Limit)：</strong> <code><?php echo esc_html($status['memory_limit']); ?></code><br>
                    <strong>最大上傳大小 (Upload Max)：</strong> <code><?php echo esc_html($status['upload_max']); ?></code>
                </p>
                <p class="description">提示：現在可以在「媒體庫」點擊單張圖片進入「編輯更多詳細資料」頁面，手動將現有圖片轉換為 WebP！</p>
            </div>
        </div>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('wutm_save_media_encoder'); ?>
            <input type="hidden" name="action" value="wutm_save_media_encoder">

            <div class="postbox">
                <h2 class="hndle" style="padding:10px 15px; margin:0;"><span>WebP 自動轉換設定</span></h2>
                <div class="inside">
                    <table class="form-table">
                        <tbody>
                            <tr>
                                <th scope="row">啟用自動轉換</th>
                                <td>
                                    <label><input name="webp_enabled" type="checkbox" value="1" <?php checked($s['webp_enabled']); ?>> 啟用在上傳時自動轉換圖片為 WebP 格式</label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">觸發轉換的來源格式</th>
                                <td>
                                    <?php foreach (['jpeg'=>'JPEG ( JPG )', 'png'=>'PNG', 'gif'=>'GIF'] as $key => $label): ?>
                                        <label style="margin-right:16px;">
                                            <input name="formats[]" type="checkbox" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key, $s['formats'], true)); ?>> <?php echo esc_html($label); ?>
                                        </label>
                                    <?php endforeach; ?>
                                    <p class="description" style="margin-top:8px;">不建議勾選 GIF，因為 WordPress 內建轉檔可能會使動圖變成靜態圖片。</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">WebP 壓縮品質</th>
                                <td>
                                    <input name="quality" type="number" min="1" max="100" value="<?php echo esc_attr((string) $s['quality']); ?>" class="small-text"> %
                                    <span class="description">建議值：75 – 90。數值越低檔案越小，但畫質越差。</span>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">原始檔處理方式</th>
                                <td>
                                    <label><input name="replace_original" type="checkbox" value="1" <?php checked($s['replace_original']); ?>> 轉換成功後，立刻刪除原始的 JPEG / PNG / GIF 檔案</label>
                                    <p class="description">如果不勾選，原圖將會作為備份保留在 <code>wp-content/uploads</code> 資料夾中，但媒體庫依然會優先使用 WebP。</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="postbox">
                <h2 class="hndle" style="padding:10px 15px; margin:0;"><span>上傳尺寸限制 (防暴漲)</span></h2>
                <div class="inside">
                    <table class="form-table">
                        <tbody>
                            <tr>
                                <th scope="row">上傳前自動縮圖</th>
                                <td>
                                    <label><input name="resize_enabled" type="checkbox" value="1" <?php checked($s['resize_enabled']); ?>> 當上傳的圖片超過下方限制時，自動進行等比例縮小</label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">最大長寬限制</th>
                                <td>
                                    最大寬度：<input name="max_width" type="number" min="0" max="10000" value="<?php echo esc_attr((string) $s['max_width']); ?>" class="small-text"> px &nbsp;&nbsp;&nbsp;
                                    最大高度：<input name="max_height" type="number" min="0" max="10000" value="<?php echo esc_attr((string) $s['max_height']); ?>" class="small-text"> px
                                    <p class="description">填 0 表示該方向不限制。(例如預設 2560px 可應付絕大多數 2K/4K 螢幕需求)</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="postbox">
                <h2 class="hndle" style="padding:10px 15px; margin:0;"><span>無用縮圖清理 (停用特定尺寸)</span></h2>
                <div class="inside">
                    <p>很多佈景主題或外掛會自動產生大量您用不到的縮圖尺寸。<strong>勾選代表「停用」該尺寸</strong>，未來上傳新圖片時就不會再產生該尺寸檔案，為您節省主機空間。</p>
                    <div style="max-width:800px; border:1px solid #c3c4c7; padding:15px; background:#fff; border-radius:4px; display:grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 10px;">
                        <?php foreach ($sizes as $name => $size): ?>
                            <label style="display:flex; align-items:center;">
                                <input name="disabled_sizes[]" type="checkbox" value="<?php echo esc_attr($name); ?>" <?php checked(in_array($name, $s['disabled_sizes'], true)); ?> style="margin-top:0;">
                                <span style="margin-left:5px;">
                                    <strong><?php echo esc_html($name); ?></strong><br>
                                    <span style="color:#666; font-size:12px;"><?php echo esc_html($size['width'] . ' × ' . $size['height'] . ($size['crop'] ? ' (裁切)' : '')); ?></span>
                                </span>
                            </label>
                        <?php endforeach; ?>
                        <label style="display:flex; align-items:center; grid-column: 1 / -1; border-top:1px solid #eee; padding-top:10px; margin-top:5px;">
                            <input name="disabled_sizes[]" type="checkbox" value="scaled" <?php checked(in_array('scaled', $s['disabled_sizes'], true)); ?>>
                            <span style="margin-left:5px;">
                                <strong>scaled (WordPress 大圖預設縮放)</strong><br>
                                <span style="color:#666; font-size:12px;">停用 WordPress 內建將超過 2560px 圖片強制轉為 -scaled 的行為。</span>
                            </span>
                        </label>
                    </div>
                    <p class="description" style="color:#d63638;">注意：此設定僅對「新上傳」的圖片有效。既有的舊縮圖不會被自動刪除。</p>
                </div>
            </div>

            <p class="submit">
                <?php submit_button('儲存設定', 'primary', 'submit', false); ?>
            </p>
        </form>
    </div>
    <?php
}
