<?php
defined('ABSPATH') || exit;

/** Resolve only real, readable files inside this site's upload directory. */
function wutm_media_local_file(string $file): string {
    $uploads = wp_get_upload_dir();
    $root = realpath($uploads['basedir']);
    $real = $file !== '' ? realpath($file) : false;
    if (!$root || !$real || !is_file($real) || !is_readable($real)) return '';
    $root = trailingslashit(wp_normalize_path($root));
    $real = wp_normalize_path($real);
    if (DIRECTORY_SEPARATOR === '\\') return stripos($real, $root) === 0 ? $real : '';
    return strpos($real, $root) === 0 ? $real : '';
}

/** Both conversion entry points share a non-waiting DB lock; no background worker. */
function wutm_media_cleanup_output(string $new, array $meta = []): void {
    $file = wutm_media_local_file($new);
    if (!$file) return;
    $base = basename($file); $stem = pathinfo($base, PATHINFO_FILENAME);
    $names = [$base, basename((string)($meta['file'] ?? '')), (string)($meta['original_image'] ?? '')];
    foreach (array_slice((array)($meta['sizes'] ?? []),0,200) as $size) if (is_array($size)) $names[] = (string)($size['file'] ?? '');
    foreach (array_unique($names) as $name) {
        if ($name === '' || basename($name) !== $name || strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'webp') continue;
        if ($name !== $base && strpos($name, $stem.'-') !== 0) continue;
        $safe = wutm_media_local_file(dirname($file).'/'.$name);
        if ($safe) wp_delete_file($safe);
    }
}

function wutm_media_convert_attachment(int $id, bool $delete_original = false) {
    global $wpdb;
    if (!current_user_can('upload_files') || !current_user_can('edit_post', $id) || get_post_type($id) !== 'attachment') {
        return new WP_Error('media_permission', '沒有轉換此圖片的權限。');
    }
    $lock = 'wutm_media_' . md5(DB_NAME . $wpdb->prefix);
    if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)', $lock)) !== '1') {
        return new WP_Error('media_busy', '另一張圖片正在處理，請稍後再試。');
    }
    $committed = false;
    try {
        $source = wutm_media_local_file((string) get_attached_file($id));
        if (!$source) return new WP_Error('media_file', '找不到可安全處理的本機圖片。');
        $mime = wp_get_image_mime($source);
        if (!in_array($mime, ['image/jpeg', 'image/png'], true)) return new WP_Error('media_format', '手動轉換僅支援 JPEG／PNG；WebP、GIF 與其他格式不會改動。');
        $old_meta = wp_get_attachment_metadata($id);
        $old_mime = get_post_mime_type($id);
        $before = (int) wp_filesize($source);
        $saved = wutm_media_to_webp($source, (int) wutm_media_settings()['quality']);
        if (is_wp_error($saved)) return $saved;
        $new = wutm_media_local_file((string) ($saved['path'] ?? ''));
        if (!$new || $new === $source || wp_get_image_mime($new) !== 'image/webp') {
            return new WP_Error('media_output', 'WebP 輸出無效，原始圖片未刪除。');
        }
        if (!function_exists('wp_generate_attachment_metadata')) require_once ABSPATH . 'wp-admin/includes/image.php';
        // WordPress may create a -scaled variant, so stage the attachment first and rollback on failure.
        update_attached_file($id, $new);
        $updated = wp_update_post(['ID'=>$id, 'post_mime_type'=>'image/webp'], true);
        if (is_wp_error($updated) || !$updated) {
            update_attached_file($id, $source);
            wp_delete_file($new);
            return new WP_Error('media_update', '媒體紀錄更新失敗，原圖已保留。');
        }
        $meta = wp_generate_attachment_metadata($id, $new);
        if (!is_array($meta) || empty($meta['file']) || empty($meta['width']) || empty($meta['height'])) {
            wutm_media_cleanup_output($new, (array)wp_get_attachment_metadata($id));
            update_attached_file($id, $source);
            wp_update_post(['ID'=>$id, 'post_mime_type'=>$old_mime]);
            wp_update_attachment_metadata($id, is_array($old_meta) ? $old_meta : []);
            return new WP_Error('media_metadata', '縮圖或圖片資料建立失敗，原圖已保留。');
        }
        $uploads = wp_get_upload_dir();
        $final = wutm_media_local_file(trailingslashit($uploads['basedir']) . $meta['file']);
        if (!$final || $final === $source || wp_get_image_mime($final) !== 'image/webp') {
            wutm_media_cleanup_output($new, $meta);
            update_attached_file($id, $source);
            wp_update_post(['ID'=>$id, 'post_mime_type'=>$old_mime]);
            wp_update_attachment_metadata($id, is_array($old_meta) ? $old_meta : []);
            return new WP_Error('media_output', '圖片資料指向無效的 WebP，原圖已保留。');
        }
        update_attached_file($id, $final);
        wp_update_attachment_metadata($id, $meta);
        $verified = wp_get_attachment_metadata($id);
        if (get_post_mime_type($id) !== 'image/webp' || get_attached_file($id) !== $final || !is_array($verified) || ($verified['file'] ?? '') !== $meta['file']) {
            wutm_media_cleanup_output($new, $meta);
            update_attached_file($id, $source);
            wp_update_post(['ID'=>$id, 'post_mime_type'=>$old_mime]);
            wp_update_attachment_metadata($id, is_array($old_meta) ? $old_meta : []);
            return new WP_Error('media_update', '無法確認媒體資料更新，原圖已保留。');
        }
        $committed = true;
        if ($delete_original) {
            // Only the original, explicitly confirmed media-edit operation deletes old files.
            $old_files = [$source];
            foreach ((array) ($old_meta['sizes'] ?? []) as $size) {
                $name = $size['file'] ?? '';
                if (is_string($name) && basename($name) === $name) $old_files[] = dirname($source) . '/' . $name;
            }
            foreach (array_unique($old_files) as $old) {
                $safe = wutm_media_local_file($old);
                if ($safe && $safe !== $new && $safe !== $final) wp_delete_file($safe);
            }
        }
        $record = ['id'=>$id, 'name'=>basename($source), 'before'=>$before, 'after'=>(int) wp_filesize($final), 'at'=>current_time('mysql'), 'original_kept'=>!$delete_original];
        update_post_meta($id, '_wutm_media_conversion', $record);
        $recent = array_slice(array_merge([$record], (array) get_option('wutm_media_recent', [])), 0, 12);
        update_option('wutm_media_recent', $recent, false);
        return $record;
    } catch (Throwable $e) {
        // After a verified commit, a third-party cleanup/log hook must not restore a deleted source.
        if ($committed) return new WP_Error('media_cleanup', 'WebP 已完成，但清理或紀錄作業失敗；請重新讀取確認，不會自動重試。');
        if (isset($new) && is_string($new)) wutm_media_cleanup_output($new, (array)wp_get_attachment_metadata($id));
        if (isset($source, $old_mime) && $source !== '') {
            update_attached_file($id, $source);
            wp_update_post(['ID'=>$id, 'post_mime_type'=>$old_mime]);
            wp_update_attachment_metadata($id, is_array($old_meta ?? null) ? $old_meta : []);
        }
        return new WP_Error('media_processing', '圖片處理失敗，原始檔已保留；請查看主機錯誤紀錄後再試。');
    } finally {
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
    }
}

function wutm_media_library_row(int $id): array {
    $meta = (array) wp_get_attachment_metadata($id);
    $mime = get_post_mime_type($id);
    $file = wutm_media_local_file((string) get_attached_file($id));
    $record = get_post_meta($id, '_wutm_media_conversion', true);
    return [
        'id'=>$id, 'name'=>basename((string) get_attached_file($id)),
        'title'=>get_the_title($id), 'mime'=>$mime, 'width'=>(int) ($meta['width'] ?? 0), 'height'=>(int) ($meta['height'] ?? 0),
        'bytes'=>isset($meta['filesize']) ? max(0, (int) $meta['filesize']) : ($file ? (int) wp_filesize($file) : null),
        'thumb'=>(string) (wp_get_attachment_image_url($id, 'thumbnail') ?: ''),
        'edit'=>(string) get_edit_post_link($id, 'raw'),
        'convertible'=>$file !== '' && in_array($mime, ['image/jpeg','image/png'], true) && current_user_can('edit_post', $id),
        'saving'=>is_array($record) ? max(0, (int) ($record['before'] ?? 0) - (int) ($record['after'] ?? 0)) : null,
    ];
}

function wutm_media_admin_ajax(): void {
    if (!current_user_can('manage_options') || !current_user_can('upload_files')) wp_send_json_error(['message'=>'沒有管理媒體編碼器的權限。'], 403);
    check_ajax_referer('wutm_media_admin', 'nonce');
    $operation = isset($_POST['operation']) && is_scalar($_POST['operation']) ? sanitize_key((string) $_POST['operation']) : '';
    if ($operation === 'convert') {
        $id = isset($_POST['id']) && is_scalar($_POST['id']) ? absint($_POST['id']) : 0;
        // The new batch workflow always keeps originals; existing media-edit deletion remains opt-in.
        $record = wutm_media_convert_attachment($id, false);
        if (is_wp_error($record)) wp_send_json_error(['message'=>$record->get_error_message()], 400);
        wp_send_json_success(['row'=>wutm_media_library_row($id), 'record'=>$record]);
    }
    if ($operation !== 'list') wp_send_json_error(['message'=>'不支援的操作。'], 400);
    global $wpdb;
    $cursor = isset($_POST['cursor']) && is_scalar($_POST['cursor']) ? absint($_POST['cursor']) : 0;
    $where = $cursor ? $wpdb->prepare(' AND ID < %d', $cursor) : '';
    // Fixed-size keyset pagination: no full-library COUNT, OFFSET, file crawl or background scan.
    $ids = array_map('intval', $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment' AND post_status IN ('inherit','private','publish') AND post_mime_type IN ('image/jpeg','image/png','image/webp','image/gif') $where ORDER BY ID DESC LIMIT 21"));
    if ($wpdb->last_error) wp_send_json_error(['message'=>'無法讀取媒體，請稍後再試。'], 500);
    $more = count($ids) > 20;
    $ids = array_slice($ids, 0, 20);
    if ($ids) _prime_post_caches($ids, false, true);
    $rows = [];
    foreach ($ids as $id) if (current_user_can('edit_post', $id)) $rows[] = wutm_media_library_row($id);
    wp_send_json_success(['rows'=>$rows, 'cursor'=>$ids ? end($ids) : 0, 'more'=>$more, 'at'=>current_time('mysql')]);
}
add_action('wp_ajax_wutm_media_admin', 'wutm_media_admin_ajax');

add_action('admin_enqueue_scripts', function () {
    if (!current_user_can('manage_options') || !isset($_GET['page']) || !is_string($_GET['page']) || $_GET['page'] !== 'wu-media-encoder') return;
    wp_enqueue_style('wutm-media-admin', plugins_url('admin.css', __FILE__), [], WUTM_VERSION);
    wp_enqueue_script('wutm-media-admin', plugins_url('admin.js', __FILE__), [], WUTM_VERSION, true);
    wp_add_inline_script('wutm-media-admin', 'window.wutmMediaAdmin='.wp_json_encode(['url'=>admin_url('admin-ajax.php'), 'nonce'=>wp_create_nonce('wutm_media_admin')]).';', 'before');
});

function wutm_media_settings_page(): void {
    if (!current_user_can('manage_options')) return;
    $s = wutm_media_settings(); $status = wutm_media_get_system_status(); $sizes = wutm_media_sizes();
    $engine = implode(' / ', array_filter([$status['imagick_webp'] ? 'Imagick' : '', $status['gd_webp'] ? 'GD' : '']));
    ?>
    <div class="wrap wutm-media" id="wutm-media" data-supported="<?php echo $status['supported'] ? '1' : '0'; ?>">
      <header class="wme-top"><div class="wme-brand"><span>W</span> WU <b>/ MEDIA ENCODER</b></div><div class="wme-system"><i></i><?php echo $status['supported'] ? 'WebP 引擎可用' : '尚無可用 WebP 引擎'; ?></div></header>
      <nav class="wme-tabs" role="tablist" aria-label="媒體編碼器">
        <?php foreach (['overview'=>'總覽','library'=>'媒體清單','settings'=>'轉換設定'] as $key=>$label): ?>
          <button type="button" role="tab" id="wme-tab-<?php echo esc_attr($key); ?>" data-tab="<?php echo esc_attr($key); ?>" aria-selected="<?php echo $key==='overview'?'true':'false'; ?>" aria-controls="wme-<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></button>
        <?php endforeach; ?>
      </nav>
      <main class="wme-main">
        <div class="wme-heading"><div><div class="wme-eyebrow">IMAGE OPTIMIZATION STUDIO</div><h1>媒體編碼器</h1><p>更輕盈的圖片，更清楚的媒體管理。</p></div><a class="wme-button" href="<?php echo esc_url(admin_url('upload.php')); ?>">開啟媒體庫 ↗</a></div>
        <?php if (isset($_GET['updated'])): ?><div class="wme-message" role="status">設定已成功儲存。</div><?php endif; ?>
        <div id="wme-message" class="wme-message" role="status" aria-live="polite" hidden></div>
        <section class="wme-job" id="wme-job" hidden aria-label="轉換進度"><div class="wme-row"><strong id="wme-job-label">準備轉換</strong><div class="wme-actions"><button type="button" class="wme-button wme-primary" id="wme-resume" hidden>繼續轉換</button><button type="button" class="wme-button" id="wme-pause">停止後續轉換</button></div></div><progress id="wme-progress" max="100" value="0">0%</progress><p>逐張處理；停止後不再發送下一張，已送出的請求仍可能完成。批次轉換會保留原始檔及舊縮圖。</p></section>
        <section id="wme-overview" role="tabpanel" aria-labelledby="wme-tab-overview">
          <div class="wme-overview-tools"><div class="wme-ring" aria-label="尚未讀取 WebP 比例"><div><strong id="wme-percent">—</strong><span>本頁 WebP 比例</span></div></div><div class="wme-overview-copy"><p id="wme-summary">按需讀取最近 20 張圖片，查看格式與容量；開啟頁面不會掃描整座媒體庫。</p><div class="wme-actions"><button type="button" class="wme-button wme-primary" data-load>讀取最近圖片</button><button type="button" class="wme-button" data-go="settings">調整轉換設定</button></div></div></div>
          <div class="wme-stats"><article><span>LOADED IMAGES</span><strong id="wme-stat-count">—</strong><p>本次載入圖片</p></article><article><span>READY TO CONVERT</span><strong id="wme-stat-ready">—</strong><p>本頁可轉換 JPG／PNG</p></article><article><span>IMAGE FILE SIZE</span><strong id="wme-stat-size">—</strong><p>本頁主圖容量，不含縮圖</p></article><article><span>RECORDED SAVINGS</span><strong id="wme-stat-saving">—</strong><p>本頁有轉換紀錄的已知節省</p></article></div>
          <div class="wme-split"><section class="wme-panel"><header><h3>目前的處理規則</h3><button type="button" class="wme-link" data-go="settings">編輯設定 →</button></header><div class="wme-rule"><div><h3>上傳自動轉換</h3><p><?php echo esc_html(strtoupper(implode(' / ', (array)$s['formats'])) . ' → WEBP'); ?></p></div><span class="wme-badge"><?php echo $s['webp_enabled']?'已啟用':'未啟用'; ?></span></div><div class="wme-rule"><div><h3>壓縮品質與尺寸</h3><p><?php echo esc_html('品質 '.$s['quality'].'% · '.($s['resize_enabled']?'尺寸限制已啟用':'不自動調整尺寸')); ?></p></div><span class="wme-badge"><?php echo esc_html($s['quality']); ?>%</span></div><div class="wme-rule"><div><h3>縮圖產生控制</h3><p>僅影響新上傳；不自動清除舊縮圖。</p></div><span class="wme-badge"><?php echo count((array)$s['disabled_sizes']); ?> 項停用</span></div></section>
          <section class="wme-panel"><header><h3>最近轉換紀錄</h3><span class="wme-caption">最多 12 筆</span></header><div id="wme-recent">
          <?php $recent = array_slice((array)get_option('wutm_media_recent',[]),0,12); if (!$recent): ?><p class="wme-empty">尚無新版轉換紀錄；既有 WebP 不會被當成本工具的壓縮成果。</p><?php endif; ?>
          <?php foreach ($recent as $record): if (!is_array($record)) continue; ?><div class="wme-activity"><strong><?php echo esc_html($record['name']??''); ?> → WEBP</strong><p><?php echo esc_html(size_format((int)($record['before']??0)).' → '.size_format((int)($record['after']??0)).' · '.($record['at']??'')); ?></p></div><?php endforeach; ?>
          </div></section></div><p class="wme-footnote">資料僅代表當前載入的頁面，不是全站總數。保留原檔的轉換可能增加磁碟占用；「已知節省」是主圖傳輸大小差異，不是磁碟釋放量。</p>
        </section>
        <section id="wme-library" role="tabpanel" aria-labelledby="wme-tab-library" hidden>
          <div class="wme-toolbar"><div><h2>媒體清單</h2><p class="wme-caption" id="wme-read-at">尚未讀取圖片</p></div><label class="wme-search">搜尋本頁 <input type="search" id="wme-search" placeholder="檔名或標題"></label></div>
          <div class="wme-toolbar"><div class="wme-filters"><button type="button" data-filter="all" aria-pressed="true">全部</button><button type="button" data-filter="ready" aria-pressed="false">可轉換</button><button type="button" data-filter="webp" aria-pressed="false">WebP</button></div><div class="wme-actions"><button type="button" class="wme-button" data-load>重新讀取</button><button type="button" class="wme-button wme-primary" id="wme-batch" disabled>轉換本頁待辦圖片</button></div></div>
          <div class="wme-panel"><table class="wme-table"><thead><tr><th>檔案 / FILE</th><th>容量 / 格式</th><th>狀態</th><th>操作</th></tr></thead><tbody id="wme-rows"><tr><td colspan="4" class="wme-empty">請按「讀取最近圖片」或「重新讀取」。</td></tr></tbody></table><div class="wme-table-footer"><span id="wme-results">每頁最多 20 張 · 按需讀取</span><div class="wme-actions"><button type="button" class="wme-button" id="wme-prev" disabled>上一頁</button><button type="button" class="wme-button" id="wme-next" disabled>下一頁</button></div></div></div>
          <p class="wme-footnote">手動批次只處理 JPEG／PNG；GIF 保持原格式。批次保留原檔與舊縮圖。媒體編輯頁仍提供原有的「轉換並取代」操作。</p>
        </section>
        <section id="wme-settings" role="tabpanel" aria-labelledby="wme-tab-settings" hidden>
          <div class="wme-toolbar"><div><h2>轉換設定</h2><p class="wme-caption">沿用原有設定；未儲存的變更不會套用。</p></div></div>
          <div class="wme-settings"><form class="wme-panel" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('wutm_save_media_encoder'); ?><input type="hidden" name="action" value="wutm_save_media_encoder">
            <div class="wme-setting"><label class="wme-switch"><span><strong>上傳時自動轉換</strong><small>在上傳圖片時轉換成 WebP；轉換失敗時保留原圖。</small></span><input name="webp_enabled" type="checkbox" value="1" <?php checked($s['webp_enabled']); ?>></label></div>
            <div class="wme-setting"><h3>觸發轉換的來源格式</h3><div class="wme-formats"><?php foreach(['jpeg'=>'JPEG (JPG)','png'=>'PNG','gif'=>'GIF'] as $key=>$label): ?><label><input type="checkbox" name="formats[]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key,(array)$s['formats'],true)); ?>> <?php echo esc_html($label); ?></label><?php endforeach; ?></div><p>不建議勾選 GIF：WordPress 轉檔可能會使動畫變成靜態圖片。</p></div>
            <div class="wme-setting"><label class="wme-row" for="wme-quality"><strong>WebP 壓縮品質</strong><output id="wme-quality-output"><?php echo (int)$s['quality']; ?>%</output></label><input id="wme-quality" type="range" min="1" max="100" name="quality" value="<?php echo (int)$s['quality']; ?>"><p>建議 75–90；數字越低檔案越小，但畫質可能下降。</p></div>
            <div class="wme-setting"><label class="wme-switch"><span><strong>自動轉換後刪除原始檔</strong><small>只控制新上傳的自動轉換；未勾選時，原檔保留在 uploads，但媒體庫使用 WebP。</small></span><input name="replace_original" type="checkbox" value="1" <?php checked($s['replace_original']); ?>></label><p class="wme-warning">刪除原始檔無法透過此工具復原，請先確認備份。</p></div>
            <div class="wme-setting"><label class="wme-switch"><span><strong>上傳時等比例縮小</strong><small>超過最大尺寸才縮小；不放大小圖片。</small></span><input name="resize_enabled" type="checkbox" value="1" <?php checked($s['resize_enabled']); ?>></label><div class="wme-dimensions"><label>最大寬度 / px<input name="max_width" type="number" min="0" max="10000" value="<?php echo (int)$s['max_width']; ?>"></label><label>最大高度 / px<input name="max_height" type="number" min="0" max="10000" value="<?php echo (int)$s['max_height']; ?>"></label></div><p>0 表示該方向不限制。</p></div>
            <div class="wme-setting"><h3>縮圖管理</h3><p><strong>勾選代表停用</strong>該尺寸，只影響新上傳；不會刪除既有縮圖。</p><div class="wme-sizes"><?php foreach($sizes as $name=>$size): ?><label><input name="disabled_sizes[]" type="checkbox" value="<?php echo esc_attr($name); ?>" <?php checked(in_array($name,(array)$s['disabled_sizes'],true)); ?>><span><strong><?php echo esc_html($name); ?></strong><small><?php echo esc_html($size['width'].' × '.$size['height'].($size['crop']?' · 裁切':'')); ?></small></span></label><?php endforeach; ?><label><input name="disabled_sizes[]" type="checkbox" value="scaled" <?php checked(in_array('scaled',(array)$s['disabled_sizes'],true)); ?>><span><strong>scaled · WordPress 大圖縮放</strong><small>停用超過 2560px 圖片產生 -scaled 的行為。</small></span></label></div></div>
            <div class="wme-setting"><button class="wme-button wme-primary" type="submit">儲存設定</button></div>
          </form><aside class="wme-panel wme-engine"><div class="wme-mono">SYSTEM STATUS</div><h3>處理引擎狀態</h3><dl><dt>WebP 轉換支援</dt><dd><?php echo $status['supported']?'支援 ✓':'不支援'; ?></dd><dt>圖片引擎</dt><dd><?php echo esc_html($engine?:'未偵測到'); ?></dd><dt>PHP 記憶體上限</dt><dd><?php echo esc_html($status['memory_limit']); ?></dd><dt>最大上傳大小</dt><dd><?php echo esc_html($status['upload_max']); ?></dd></dl><p>手動轉換沿用此處品質設定；批次會保留原始檔，避免舊網址立即失效。</p></aside></div>
        </section>
      </main>
    </div>
    <?php
}
