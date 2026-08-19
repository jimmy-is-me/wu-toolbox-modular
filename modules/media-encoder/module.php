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
function wutm_media_convertible(string $mime, array $settings): bool {
    $map = ['jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif'];
    return in_array($mime, array_intersect_key($map, array_flip((array) $settings['formats'])), true);
}
function wutm_media_has_memory(string $path): bool {
    $size = @getimagesize($path);
    if (!is_array($size)) return true;
    $limit = wp_convert_hr_to_bytes((string) ini_get('memory_limit'));
    return $limit <= 0 || memory_get_usage(true) + ((int) $size[0] * (int) $size[1] * 8) <= $limit;
}
function wutm_media_to_webp(string $source, int $quality) {
    if (!is_readable($source) || !wutm_media_has_memory($source)) return new WP_Error('media_encoder_memory', __('圖片太大或可用記憶體不足。', 'wu-toolbox-modular'));
    wp_raise_memory_limit('image');
    $editor = wp_get_image_editor($source);
    if (is_wp_error($editor)) return $editor;
    $editor->set_quality(max(1, min(100, $quality)));
    $info = pathinfo($source);
    $path = $info['dirname'] . '/' . $info['filename'] . '.webp';
    if (file_exists($path)) $path = $info['dirname'] . '/' . wp_unique_filename($info['dirname'], $info['filename'] . '.webp');
    $saved = $editor->save($path, 'image/webp');
    if (is_wp_error($saved) || ($saved['mime-type'] ?? '') !== 'image/webp') {
        if (is_array($saved) && !empty($saved['path']) && file_exists($saved['path'])) wp_delete_file($saved['path']);
        return is_wp_error($saved) ? $saved : new WP_Error('media_encoder_webp', __('伺服器不支援 WebP 編碼。', 'wu-toolbox-modular'));
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
    if (empty($s['webp_enabled']) || !is_array($upload) || isset($upload['error']) || !wutm_media_convertible((string) ($upload['type'] ?? ''), $s)) return $upload;
    $saved = wutm_media_to_webp((string) ($upload['file'] ?? ''), (int) $s['quality']);
    if (is_wp_error($saved) || empty($s['replace_original'])) return $upload; // Safe sidecar conversion by default.
    $old = $upload['file'];
    if (file_exists($old)) wp_delete_file($old);
    return ['file' => $saved['path'], 'url' => str_replace(wp_get_upload_dir()['basedir'], wp_get_upload_dir()['baseurl'], $saved['path']), 'type' => 'image/webp'];
}
add_filter('wp_handle_upload', 'wutm_media_resize_upload', 10);
add_filter('wp_handle_upload', 'wutm_media_convert_upload', 20);

function wutm_media_sizes(): array {
    global $_wp_additional_image_sizes;
    $out = [];
    foreach (get_intermediate_image_sizes() as $name) {
        $extra = $_wp_additional_image_sizes[$name] ?? [];
        $out[$name] = ['width' => (int) ($extra['width'] ?? get_option($name . '_size_w')), 'height' => (int) ($extra['height'] ?? get_option($name . '_size_h')), 'crop' => !empty($extra['crop'] ?? get_option($name . '_crop'))];
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
    $s = wutm_media_settings(); $sizes = wutm_media_sizes(); $can = class_exists('Imagick') || function_exists('imagewebp');
    ?>
    <div class="wrap"><h1>媒體編碼器</h1>
    <?php if (isset($_GET['updated'])): ?><div class="notice notice-success is-dismissible"><p>設定已儲存。</p></div><?php endif; ?>
    <p>上傳時可先等比例縮小圖片，再轉換為 WebP。所有破壞性動作預設關閉。</p>
    <?php if (!$can): ?><div class="notice notice-warning"><p>此伺服器未偵測到 Imagick 或 GD WebP 支援；轉檔將被安全略過。</p></div><?php endif; ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('wutm_save_media_encoder'); ?><input type="hidden" name="action" value="wutm_save_media_encoder">
      <h2>WebP 轉換</h2><table class="form-table"><tbody>
        <tr><th>啟用自動轉換</th><td><label><input name="webp_enabled" type="checkbox" value="1" <?php checked($s['webp_enabled']); ?>> 在新圖片上傳時建立 WebP</label></td></tr>
        <tr><th>來源格式</th><td><?php foreach (['jpeg'=>'JPEG','png'=>'PNG','gif'=>'GIF'] as $key=>$label): ?><label style="margin-right:16px"><input name="formats[]" type="checkbox" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key, $s['formats'], true)); ?>> <?php echo esc_html($label); ?></label><?php endforeach; ?><p class="description">GIF 可能含動畫，通常不建議轉換。</p></td></tr>
        <tr><th>品質</th><td><input name="quality" type="number" min="1" max="100" value="<?php echo esc_attr((string) $s['quality']); ?>"> <span class="description">建議 75–90。</span></td></tr>
        <tr><th>取代原檔</th><td><label><input name="replace_original" type="checkbox" value="1" <?php checked($s['replace_original']); ?>> 以 WebP 取代媒體庫原檔</label><p class="description">關閉時僅建立 WebP 副本，最安全；開啟前請先備份媒體庫。</p></td></tr>
      </tbody></table>
      <h2>圖片尺寸</h2><table class="form-table"><tbody>
        <tr><th>上傳前縮小</th><td><label><input name="resize_enabled" type="checkbox" value="1" <?php checked($s['resize_enabled']); ?>> 超過限制時等比例縮小</label></td></tr>
        <tr><th>最大尺寸</th><td>寬 <input name="max_width" type="number" min="0" max="10000" value="<?php echo esc_attr((string) $s['max_width']); ?>"> px　高 <input name="max_height" type="number" min="0" max="10000" value="<?php echo esc_attr((string) $s['max_height']); ?>"> px <p class="description">填 0 表示該方向不限制。</p></td></tr>
      </tbody></table>
      <h2>縮圖尺寸管理</h2><p>勾選代表停用該尺寸；新上傳檔不會再建立它。既有縮圖不會被自動刪除。</p><fieldset style="max-width:640px;border:1px solid #ccd0d4;padding:12px"><?php foreach ($sizes as $name=>$size): ?><label style="display:block;margin:7px 0"><input name="disabled_sizes[]" type="checkbox" value="<?php echo esc_attr($name); ?>" <?php checked(in_array($name, $s['disabled_sizes'], true)); ?>> <strong><?php echo esc_html($name); ?></strong> — <?php echo esc_html($size['width'] . ' × ' . $size['height'] . ($size['crop'] ? '（裁切）' : '')); ?></label><?php endforeach; ?><label style="display:block;margin:7px 0"><input name="disabled_sizes[]" type="checkbox" value="scaled" <?php checked(in_array('scaled', $s['disabled_sizes'], true)); ?>> <strong>scaled</strong> — 停用 WordPress 大圖縮放</label></fieldset>
      <?php submit_button('儲存媒體編碼器設定'); ?>
    </form></div><?php
}
