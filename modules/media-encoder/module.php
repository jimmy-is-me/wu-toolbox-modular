<?php
/**
 * Module: media-encoder
 * Loaded only when enabled in WU Toolbox Modular.
 */

if (!defined('ABSPATH')) exit;

/* === 媒體編碼器:選單 === */
function media_encoder_menu() {
    add_submenu_page(
        'wumetax-toolkit',
        '媒體編碼器',
        '媒體編碼器',
        'manage_options',
        'wu-media-encoder',
        'media_encoder_settings_page'
    );
}
add_action('admin_menu', 'media_encoder_menu', 100);

/* === 取得與預設設定 === */
function media_encoder_get_settings() {
    return array(
        'enabled' => get_option('media_encoder_enabled', 'off'),
        'quality' => intval(get_option('media_encoder_quality', 82)),
        'replace_original' => 'on',
        'enable_logging' => get_option('media_encoder_enable_logging', 'off'),
        'enable_webp_fallback' => get_option('media_encoder_enable_webp_fallback', 'on'),
        'disabled_sizes' => (array) get_option('media_encoder_disabled_sizes', array()),
    );
}

/* === 錯誤日誌記錄 === */
function media_encoder_log_error($message, $context = array()) {
    $settings = media_encoder_get_settings();
    if ($settings['enable_logging'] !== 'on') return;

    $log_message = '[Media Encoder] ' . $message;
    if (!empty($context)) {
        $log_message .= ' Context: ' . wp_json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    error_log($log_message);
}

/* === 一般日誌 === */
function media_encoder_log_info($message, $context = array()) {
    $settings = media_encoder_get_settings();
    if ($settings['enable_logging'] !== 'on') return;

    $log_message = '[Media Encoder] ' . $message;
    if (!empty($context)) {
        $log_message .= ' Context: ' . wp_json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    error_log($log_message);
}

/* === 取得所有縮圖尺寸 === */
function media_encoder_all_image_sizes() {
    global $_wp_additional_image_sizes;
    $sizes = array();
    $builtins = array('thumbnail', 'medium', 'medium_large', 'large', '1536x1536', '2048x2048');

    foreach ($builtins as $s) {
        $w = intval(get_option("{$s}_size_w"));
        $h = intval(get_option("{$s}_size_h"));
        $crop = (bool) get_option("{$s}_crop");
        if ($w || $h) {
            $sizes[$s] = array(
                'width'  => $w,
                'height' => $h,
                'crop'   => $crop,
            );
        }
    }

    if (is_array($_wp_additional_image_sizes)) {
        foreach ($_wp_additional_image_sizes as $k => $v) {
            $sizes[$k] = array(
                'width'  => intval($v['width']),
                'height' => intval($v['height']),
                'crop'   => !empty($v['crop']),
            );
        }
    }

    return $sizes;
}

/* === 儲存設定 === */
function media_encoder_save_settings() {
    if (!current_user_can('manage_options')) return;
    if (!isset($_POST['media_encoder_save']) || !check_admin_referer('media_encoder_save', 'media_encoder_nonce')) return;

    update_option('media_encoder_enabled', isset($_POST['media_encoder_enabled']) ? sanitize_text_field($_POST['media_encoder_enabled']) : 'off');

    $quality = isset($_POST['media_encoder_quality']) ? max(1, min(100, intval($_POST['media_encoder_quality']))) : 82;
    update_option('media_encoder_quality', $quality);

    update_option('media_encoder_enable_logging', isset($_POST['media_encoder_enable_logging']) ? 'on' : 'off');
    update_option('media_encoder_enable_webp_fallback', isset($_POST['media_encoder_enable_webp_fallback']) ? 'on' : 'off');

    $all_sizes = media_encoder_all_image_sizes();
    $disabled = isset($_POST['media_encoder_disabled_sizes']) ? (array) $_POST['media_encoder_disabled_sizes'] : array();
    $disabled = array_values(array_intersect($disabled, array_keys($all_sizes)));
    update_option('media_encoder_disabled_sizes', $disabled);

    echo '<div class="updated"><p>媒體編碼器設定已更新 ✅</p></div>';
}

/* === 設定頁面 === */
function media_encoder_settings_page() {
    media_encoder_save_settings();
    $settings = media_encoder_get_settings();
    $quality = $settings['quality'];
    ?>
    <div class="wrap">
        <h1>媒體編碼器 (JPEG/PNG → WebP)</h1>
        <p>自動將上傳的圖像轉換為 WebP，以獲得更佳效能與更小檔案。系統會自動替換原圖以節省主機容量。</p>

        <div style="background:#fff3cd;border:1px solid #ffc107;padding:10px;border-radius:4px;margin:10px 0;">
            <strong>⚠️ 重要說明:</strong>
            當您將所有圖片轉換為 WebP 後，原本網站中引用 PNG 或 JPG 的地方可能無法顯示圖片。
            請啟用 WebP 自動回退功能，讓網站能自動將圖片請求重新導向到 WebP 版本，同時也會自動生成所需的縮圖。
        </div>

        <div style="display:flex;gap:40px;flex-wrap:wrap;align-items:flex-start;">
            <form method="post" style="flex:1;min-width:320px;max-width:560px;">
                <?php wp_nonce_field('media_encoder_save', 'media_encoder_nonce'); ?>

                <h2>設定</h2>

                <p>
                    <label>
                        <input type="checkbox" name="media_encoder_enabled" value="on" <?php checked($settings['enabled'], 'on'); ?>>
                        啟用媒體編碼器
                    </label><br>
                    <small>啟用後，系統會在圖片上傳時自動轉換為 WebP 並替換原檔案。</small>
                </p>

                <p>
                    <label>
                        品質 (1–100):
                        <input type="number" name="media_encoder_quality" min="1" max="100" value="<?php echo esc_attr($quality); ?>" style="width:90px;">
                    </label>
                    <br><small>建議 75–90。數值越高品質越好、檔案越大。</small>
                </p>

                <div style="background:#e7f3ff;border:1px solid #0073aa;padding:10px;border-radius:4px;margin:10px 0;">
                    <strong>📁 檔案處理模式:</strong> 自動替換原圖為 WebP 格式以節省主機容量
                </div>

                <p>
                    <label>
                        <input type="checkbox" name="media_encoder_enable_webp_fallback" <?php checked($settings['enable_webp_fallback'], 'on'); ?>>
                        啟用 WebP 自動回退功能
                    </label><br>
                    <small>啟用後，當網站請求 PNG/JPG 圖片但只有 WebP 存在時，自動重新導向到 WebP 版本並生成所需縮圖。</small>
                </p>

                <p>
                    <label>
                        <input type="checkbox" name="media_encoder_enable_logging" <?php checked($settings['enable_logging'], 'on'); ?>>
                        啟用錯誤日誌記錄
                    </label><br>
                    <small>啟用後會將轉換錯誤記錄到 WordPress 錯誤日誌中。</small>
                </p>

                <p>
                    <input type="submit" class="button-primary" name="media_encoder_save" value="儲存設定">
                </p>

                <h2>縮圖尺寸管理</h2>
                <p style="color:#b32d2e;font-weight:600;">建議：打勾代表關閉未使用的縮圖尺寸（請僅關閉確定不會用到的尺寸）。</p>
                <p>關閉網站未使用的縮圖尺寸，可節省空間與生成時間：</p>

                <fieldset style="max-height:180px;overflow:auto;border:1px solid #ddd;padding:8px;border-radius:6px;">
                    <?php
                    $sizes = media_encoder_all_image_sizes();
                    $disabled = (array) get_option('media_encoder_disabled_sizes', array());

                    foreach ($sizes as $size_key => $info):
                        $is_disabled = in_array($size_key, $disabled, true);
                        $width = intval($info['width']);
                        $height = intval($info['height']);
                        $size_desc = ($width > 0 && $height > 0) ? "{$width}×{$height}" : (($width > 0) ? "寬{$width}px" : (($height > 0) ? "高{$height}px" : "原尺寸"));
                        $crop_desc = $info['crop'] ? '裁切' : '不裁切';
                    ?>
                        <label style="display:flex;align-items:center;gap:8px;margin:6px 0;">
                            <input type="checkbox" name="media_encoder_disabled_sizes[]" value="<?php echo esc_attr($size_key); ?>" <?php checked($is_disabled); ?>>
                            <span><strong><?php echo esc_html($size_key); ?></strong> (<?php echo esc_html($size_desc); ?>, <?php echo esc_html($crop_desc); ?>)</span>
                        </label>
                    <?php endforeach; ?>
                </fieldset>

                <p class="description">被停用的尺寸將不會再生成；已存在檔案不會自動刪除，可使用下方清理工具。</p>

                <h2>預覽模式</h2>
                <p>在啟動全域轉換前，先對單一影像進行測試壓縮。</p>
                <p>
                    <select id="media-encoder-preview-attachment" style="min-width:260px;">
                        <option value="">選擇媒體庫影像…（僅 JPEG/PNG）</option>
                        <?php
                        $imgs = get_posts(array(
                            'post_type'      => 'attachment',
                            'posts_per_page' => 5,
                            'post_mime_type' => array('image/jpeg', 'image/png'),
                            'orderby'        => 'date',
                            'order'          => 'DESC',
                        ));
                        foreach ($imgs as $img) {
                            $label = get_the_title($img->ID);
                            if (!$label) {
                                $label = basename(get_attached_file($img->ID));
                            }
                            echo '<option value="' . esc_attr($img->ID) . '">' . esc_html($label) . ' (#' . intval($img->ID) . ')</option>';
                        }
                        ?>
                    </select>
                    <button type="button" class="button" id="media-encoder-run-preview">開始預覽</button>
                </p>

                <div id="media-encoder-preview-result" style="display:none;border:1px solid #ddd;padding:12px;border-radius:8px;"></div>

                <h2>智能批次轉換（舊有圖片 → WebP）</h2>
                <p>將目前媒體庫中的 JPEG/PNG 批量轉換為 WebP。系統會<strong>自動調整處理速度</strong>以避免影響網站效能。</p>

                <div style="background:#f0f6fc;border:1px solid #0969da;padding:12px;border-radius:4px;margin:10px 0;">
                    <p><strong>🧠 智能處理模式:</strong></p>
                    <ul style="margin:5px 0 5px 20px;font-size:14px;">
                        <li>✅ 自動偵測系統負載，調整處理速度</li>
                        <li>✅ 根據伺服器效能動態調整批次大小</li>
                        <li>✅ 智能延遲機制，避免影響網站訪問</li>
                        <li>✅ 可隨時暫停、繼續或取消處理</li>
                    </ul>
                </div>

                <p>
                    <button type="button" class="button button-primary" id="media-encoder-bulk-start">開始智能轉換</button>
                    <button type="button" class="button" id="media-encoder-bulk-pause" style="display:none;">暫停</button>
                    <button type="button" class="button" id="media-encoder-bulk-resume" style="display:none;">繼續</button>
                    <button type="button" class="button button-secondary" id="media-encoder-bulk-cancel" style="display:none;">取消</button>
                </p>

                <div id="media-encoder-progress-container" style="display:none;background:#f9f9f9;border:1px solid #ddd;padding:15px;border-radius:6px;margin:15px 0;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                        <strong>轉換進度</strong>
                        <div id="media-encoder-status" style="font-weight:bold;color:#0073aa;"></div>
                    </div>

                    <div style="background:#e0e0e0;height:20px;border-radius:10px;overflow:hidden;margin-bottom:10px;">
                        <div id="media-encoder-progress-bar" style="background:linear-gradient(90deg, #00a0d2, #0073aa);height:100%;width:0%;transition:width 0.3s ease;"></div>
                    </div>

                    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(140px, 1fr));gap:10px;margin-bottom:10px;font-size:13px;">
                        <div><strong>處理模式:</strong> <span id="processing-mode">智能偵測中</span></div>
                        <div><strong>當前批次:</strong> <span id="current-batch-size">-</span> 張</div>
                        <div><strong>處理間隔:</strong> <span id="processing-delay">-</span> 秒</div>
                        <div><strong>系統負載:</strong> <span id="system-load">偵測中</span></div>
                    </div>

                    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(120px, 1fr));gap:10px;margin-bottom:10px;">
                        <div><strong>已處理:</strong> <span id="stats-processed">0</span></div>
                        <div><strong>已轉換:</strong> <span id="stats-converted">0</span></div>
                        <div><strong>已略過:</strong> <span id="stats-skipped">0</span></div>
                        <div><strong>錯誤:</strong> <span id="stats-errors">0</span></div>
                        <div><strong>節省空間:</strong> <span id="stats-saved-space">0 KB</span></div>
                    </div>

                    <div id="media-encoder-current-files" style="max-height:200px;overflow-y:auto;background:#fff;border:1px solid #e0e0e0;border-radius:4px;padding:8px;"></div>
                </div>
            </form>

            <div style="flex:1;min-width:320px;">
                <h2>系統資訊</h2>

                <div style="background:#f9f9f9;padding:12px;border-radius:4px;margin-bottom:20px;">
                    <h4>WebP 支援狀態</h4>
                    <p>
                        Imagick: <?php echo class_exists('Imagick') ? '<span style="color:green;">✅ 可用</span>' : '<span style="color:red;">❌ 不可用</span>'; ?><br>
                        GD WebP: <?php echo function_exists('imagewebp') ? '<span style="color:green;">✅ 可用</span>' : '<span style="color:red;">❌ 不可用</span>'; ?>
                    </p>

                    <?php if (!media_encoder_can_convert()): ?>
                        <p style="color:red;"><strong>⚠️ 警告:</strong> 您的伺服器不支援 WebP 轉換。請聯繫主機商啟用 Imagick 或 GD WebP 支援。</p>
                    <?php endif; ?>

                    <h4 style="margin-top:15px;">伺服器效能參考</h4>
                    <p style="font-size:13px;">
                        PHP 記憶體限制: <?php echo esc_html(ini_get('memory_limit')); ?><br>
                        最大執行時間: <?php echo esc_html(ini_get('max_execution_time')); ?> 秒<br>
                        <?php if (function_exists('sys_getloadavg')): ?>
                            系統負載: <?php $load = sys_getloadavg(); echo esc_html(round($load[0], 2)); ?><br>
                        <?php endif; ?>
                    </p>
                </div>

                <h2>檔案管理說明</h2>
                <div style="background:#fff3cd;border:1px solid #ffeaa7;padding:12px;border-radius:4px;">
                    <h4>💾 節省空間模式</h4>
                    <p>系統採用<strong>替換原檔案</strong>模式運作，所有 JPEG/PNG 檔案轉換後會直接替換為 WebP 格式，有效節省主機儲存空間。</p>
                    <ul style="margin:10px 0 10px 20px;">
                        <li>✅ 原檔案會被 WebP 完全取代</li>
                        <li>✅ 所有縮圖尺寸同步轉換</li>
                        <li>✅ 媒體庫資訊自動更新</li>
                        <li>✅ 最大化節省儲存空間</li>
                    </ul>

                    <h4 style="margin-top:15px;">🧠 智能處理特色</h4>
                    <ul style="margin:10px 0 10px 20px;">
                        <li>🔄 動態調整批次大小（1-20張）</li>
                        <li>⏱️ 智能延遲控制（1-10秒）</li>
                        <li>📊 即時系統負載監控</li>
                        <li>🛑 隨時可暫停、繼續或取消</li>
                    </ul>
                </div>

                <h2 style="margin-top:24px;">縮圖與清理工具</h2>
                <div style="background:#f9f9f9;padding:12px;border-radius:6px;border:1px solid #e0e0e0;">
                    <p>
                        <button type="button" class="button" id="media-encoder-regenerate-thumbs">重新產生所需縮圖</button>
                        <span id="media-encoder-regenerate-status" style="margin-left:10px;color:#666;"></span>
                    </p>

                    <div id="media-encoder-regenerate-list" style="display:none;max-height:220px;overflow:auto;border:1px solid #e0e0e0;background:#fff;border-radius:6px;padding:8px;margin-top:8px;"></div>

                    <p>
                        <button type="button" class="button" id="media-encoder-scan-unused">掃描未使用的圖像</button>
                        <button type="button" class="button button-danger" id="media-encoder-delete-unused" style="display:none;">刪除選取的未使用圖像</button>
                    </p>

                    <div id="media-encoder-unused-list" style="display:none;max-height:240px;overflow:auto;border:1px solid #ddd;border-radius:6px;padding:8px;background:#fff;"></div>
                </div>
            </div>
        </div>
    </div>

    <script>
    jQuery(function($){
        const nonce = '<?php echo esc_js(wp_create_nonce('media_encoder_ajax')); ?>';
        let bulkRunning = false;
        let bulkPaused = false;
        let bulkCancelled = false;
        let totalImages = 0;
        let processedImages = 0;
        let currentBatchSize = 5;
        let currentDelay = 2;

        $('#media-encoder-run-preview').on('click', function(){
            const id = $('#media-encoder-preview-attachment').val();
            if (!id) {
                alert('請先選擇一張圖片');
                return;
            }

            const $result = $('#media-encoder-preview-result');
            $result.show().text('處理中…');

            $.post(ajaxurl, {
                action: 'media_encoder_preview',
                _wpnonce: nonce,
                id: id
            }, function(res){
                if (!res || !res.success) {
                    const errorMsg = res && res.data ? res.data : '預覽失敗';
                    $result.html('<div style="color:red;">❌ ' + errorMsg + '</div>');
                    return;
                }

                const d = res.data;
                let html = '<div style="color:green;">✅ 轉換成功</div>';
                html += '<div>原圖: ' + d.original_size_human + ' → WebP: ' + d.webp_size_human;
                if (d.saving_percent !== null) {
                    html += ' <strong style="color:green;">(節省 ' + d.saving_percent + '%)</strong>';
                }
                html += '</div>';

                if (d.preview_url) {
                    html += '<div style="margin-top:8px;"><img src="' + d.preview_url + '" style="max-width:100%;height:auto;border:1px solid #eee;padding:4px;border-radius:6px;"></div>';
                }

                $result.html(html);
            }).fail(function(){
                $result.html('<div style="color:red;">❌ 網路錯誤，請重試</div>');
            });
        });

        function formatBytes(bytes) {
            if (bytes === 0) return '0 B';
            const k = 1024;
            const sizes = ['B', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

        function updateSystemStatus(data) {
            if (data.suggested_batch_size) {
                currentBatchSize = data.suggested_batch_size;
                $('#current-batch-size').text(currentBatchSize);
            }
            if (data.suggested_delay) {
                currentDelay = data.suggested_delay;
                $('#processing-delay').text(currentDelay);
            }
            if (data.load_level) {
                let loadColor = '#00aa00';
                if (data.load_level === 'medium') loadColor = '#ff8800';
                if (data.load_level === 'high') loadColor = '#ff0000';
                $('#system-load').html('<span style="color:' + loadColor + ';">' + data.load_level.toUpperCase() + '</span>');
            }
            if (data.processing_mode) {
                $('#processing-mode').text(data.processing_mode);
            }
        }

        function updateProgress() {
            if (totalImages === 0) return;

            const percentage = Math.round((processedImages / totalImages) * 100);
            $('#media-encoder-progress-bar').css('width', percentage + '%');

            if (bulkRunning && !bulkPaused && !bulkCancelled) {
                const remaining = totalImages - processedImages;
                const estimatedTime = Math.ceil(remaining / currentBatchSize) * currentDelay;
                $('#media-encoder-status').text('處理中... (' + percentage + '% - 約剩 ' + estimatedTime + ' 秒)');
            }
        }

        function addFileResult(fileData) {
            const $container = $('#media-encoder-current-files');
            let html = '<div style="border-bottom:1px solid #eee;padding:8px;margin-bottom:8px;font-size:13px;">';
            html += '<div style="font-weight:bold;color:#333;">' + fileData.filename + '</div>';

            if (fileData.converted) {
                html += '<div style="color:green;">✅ 轉換成功: ' + fileData.original_size + ' → ' + fileData.webp_size;
                if (fileData.saving_percent) {
                    html += ' <span style="font-weight:bold;">(節省 ' + fileData.saving_percent + '%)</span>';
                }
                html += '</div>';
            } else if (fileData.skipped) {
                html += '<div style="color:orange;">⚠️ 已略過: ' + (fileData.reason || '已是 WebP 格式') + '</div>';
            } else if (fileData.error) {
                html += '<div style="color:red;">❌ 轉換失敗: ' + fileData.error + '</div>';
            }

            html += '</div>';

            $container.prepend(html);

            const $items = $container.children();
            if ($items.length > 20) {
                $items.slice(20).remove();
            }

            $container.scrollTop(0);
        }

        $('#media-encoder-bulk-start').on('click', function(){
            if (bulkRunning) return;

            let offset = 0;
            let processed = 0;
            let converted = 0;
            let skipped = 0;
            let errors = 0;
            let totalSavedSpace = 0;

            bulkRunning = true;
            bulkPaused = false;
            bulkCancelled = false;

            const $startBtn = $(this);
            const $pauseBtn = $('#media-encoder-bulk-pause');
            const $resumeBtn = $('#media-encoder-bulk-resume');
            const $cancelBtn = $('#media-encoder-bulk-cancel');
            const $progressContainer = $('#media-encoder-progress-container');

            $startBtn.prop('disabled', true).text('智能分析中...');
            $pauseBtn.show();
            $cancelBtn.show();
            $progressContainer.show();

            $('#media-encoder-current-files').empty();
            $('#media-encoder-status').text('正在分析系統狀態...');

            $.post(ajaxurl, {
                action: 'media_encoder_get_total_count',
                _wpnonce: nonce
            }, function(countRes) {
                if (countRes && countRes.success) {
                    totalImages = countRes.data.total;
                    processedImages = 0;

                    if (totalImages === 0) {
                        $('#media-encoder-current-files').html('<div style="text-align:center;color:#666;padding:20px;">沒有需要轉換的 JPEG/PNG 圖片</div>');
                        resetBulkUI();
                        return;
                    }

                    $('#media-encoder-current-files').html('<div style="text-align:center;color:#0073aa;padding:10px;">找到 ' + totalImages + ' 張圖片需要轉換，正在啟動智能處理模式...</div>');
                    $startBtn.text('處理中...');

                    setTimeout(step, 1000);
                } else {
                    resetBulkUI();
                    alert('無法獲取圖片總數，請重試');
                }
            });

            function step() {
                if (!bulkRunning || bulkCancelled) return;
                if (bulkPaused) {
                    setTimeout(step, 1000);
                    return;
                }

                $.post(ajaxurl, {
                    action: 'media_encoder_bulk',
                    _wpnonce: nonce,
                    offset: offset,
                    limit: currentBatchSize
                }, function(res){
                    if (!res || !res.success) {
                        const errorMsg = res && res.data ? res.data : '批次失敗';
                        $('#media-encoder-current-files').prepend('<div style="color:red;padding:10px;background:#ffe6e6;border-radius:4px;margin-bottom:10px;">❌ ' + errorMsg + '</div>');
                        resetBulkUI();
                        return;
                    }

                    processed += res.data.processed;
                    converted += res.data.converted;
                    skipped += res.data.skipped;
                    errors += res.data.errors || 0;
                    totalSavedSpace += res.data.saved_space || 0;
                    processedImages = processed;
                    offset += currentBatchSize;

                    $('#stats-processed').text(processed);
                    $('#stats-converted').text(converted);
                    $('#stats-skipped').text(skipped);
                    $('#stats-errors').text(errors);
                    $('#stats-saved-space').text(formatBytes(totalSavedSpace));

                    if (res.data.system_status) {
                        updateSystemStatus(res.data.system_status);
                    }

                    updateProgress();

                    if (res.data.files && res.data.files.length > 0) {
                        res.data.files.forEach(function(file) {
                            addFileResult(file);
                        });
                    }

                    if (res.data.done) {
                        $('#media-encoder-status').text('✅ 處理完成!');
                        let completionMsg = '<div style="color:green;padding:15px;background:#e8f5e8;border-radius:4px;margin:10px 0;text-align:center;">';
                        completionMsg += '<h4 style="margin:0 0 10px 0;">🎉 智能批次轉換完成!</h4>';
                        completionMsg += '<div>總共處理 ' + processed + ' 張圖片</div>';
                        completionMsg += '<div>成功轉換 ' + converted + ' 張</div>';
                        completionMsg += '<div>略過 ' + skipped + ' 張</div>';
                        if (errors > 0) completionMsg += '<div>錯誤 ' + errors + ' 張</div>';
                        if (totalSavedSpace > 0) completionMsg += '<div><strong>總共節省空間: ' + formatBytes(totalSavedSpace) + '</strong></div>';
                        completionMsg += '</div>';

                        $('#media-encoder-current-files').prepend(completionMsg);
                        resetBulkUI();
                    } else {
                        setTimeout(step, currentDelay * 1000);
                    }
                }).fail(function(){
                    $('#media-encoder-current-files').prepend('<div style="color:red;padding:10px;background:#ffe6e6;border-radius:4px;margin-bottom:10px;">❌ 網路錯誤，請重試</div>');
                    resetBulkUI();
                });
            }
        });

        $('#media-encoder-bulk-pause').on('click', function() {
            bulkPaused = true;
            $(this).hide();
            $('#media-encoder-bulk-resume').show();
            $('#media-encoder-status').text('⏸️ 已暫停');
        });

        $('#media-encoder-bulk-resume').on('click', function() {
            bulkPaused = false;
            $(this).hide();
            $('#media-encoder-bulk-pause').show();
            $('#media-encoder-status').text('▶️ 繼續處理中...');
        });

        $('#media-encoder-bulk-cancel').on('click', function() {
            if (confirm('確定要取消批次轉換嗎？已處理的檔案不會回復。')) {
                bulkCancelled = true;
                bulkRunning = false;
                $('#media-encoder-status').text('❌ 已取消');
                $('#media-encoder-current-files').prepend('<div style="color:orange;padding:10px;background:#fff3cd;border-radius:4px;margin-bottom:10px;">⚠️ 批次轉換已被使用者取消</div>');
                resetBulkUI();
            }
        });

        function resetBulkUI() {
            bulkRunning = false;
            bulkPaused = false;
            $('#media-encoder-bulk-start').prop('disabled', false).text('開始智能轉換');
            $('#media-encoder-bulk-pause').hide();
            $('#media-encoder-bulk-resume').hide();
            $('#media-encoder-bulk-cancel').hide();
        }

        $('#media-encoder-regenerate-thumbs').on('click', function(){
            const $status = $('#media-encoder-regenerate-status');
            const $list = $('#media-encoder-regenerate-list');
            $status.text('準備中…');
            $list.empty().show().append('<div>開始背景處理，將逐步列出已處理的媒體項目…</div>');

            $.post(ajaxurl, {
                action: 'media_encoder_regenerate_thumbnails',
                _wpnonce: nonce
            }, function(res){
                if (!res || !res.success) {
                    $status.text((res && res.data) ? res.data : '啟動失敗');
                    return;
                }
                $status.text('已開始背景處理…');
                startRegenPolling($status, $list);
            }).fail(function(){
                $status.text('網路錯誤');
            });
        });

        let regenPollingTimer = null;
        function startRegenPolling($status, $list) {
            if (regenPollingTimer) clearInterval(regenPollingTimer);

            regenPollingTimer = setInterval(function(){
                $.post(ajaxurl, {
                    action: 'media_encoder_get_regen_progress',
                    _wpnonce: nonce
                }, function(res){
                    if (!res || !res.success) return;

                    const data = res.data || {};
                    if (Array.isArray(data.items)) {
                        $list.empty();
                        data.items.forEach(function(it){
                            $list.append(
                                '<div style="display:flex;justify-content:space-between;border-bottom:1px dashed #eee;padding:4px 0;">' +
                                '<span>#' + it.id + ' ' + it.file + '</span>' +
                                '<span style="color:#2271b1;">' + it.status + '</span>' +
                                '</div>'
                            );
                        });
                    }

                    if (data.done) {
                        clearInterval(regenPollingTimer);
                        $status.text('完成');
                    }
                });
            }, 4000);
        }

        $('#media-encoder-scan-unused').on('click', function(){
            const $list = $('#media-encoder-unused-list');
            $list.show().html('掃描中…');

            $.post(ajaxurl, {
                action: 'media_encoder_scan_unused',
                _wpnonce: nonce
            }, function(res){
                if (!res || !res.success) {
                    $list.html('<div style="color:red;">掃描失敗</div>');
                    return;
                }

                const items = res.data || [];
                if (items.length === 0) {
                    $list.html('<div>沒有找到未使用的圖像。</div>');
                    $('#media-encoder-delete-unused').hide();
                    return;
                }

                let html = '<table class="widefat"><thead><tr><th style="width:32px;"><input type="checkbox" id="wu-unused-all"></th><th>檔名</th><th>上傳者</th><th>大小</th></tr></thead><tbody>';
                items.forEach(function(it){
                    html += '<tr>' +
                        '<td><input type="checkbox" class="wu-unused-item" value="' + it.id + '"></td>' +
                        '<td>' + it.file + '</td>' +
                        '<td>' + it.uploader + '</td>' +
                        '<td>' + it.size_human + '</td>' +
                    '</tr>';
                });
                html += '</tbody></table>';

                $list.html(html);
                $('#media-encoder-delete-unused').show();

                $('#wu-unused-all').on('change', function(){
                    $('.wu-unused-item').prop('checked', this.checked);
                });
            }).fail(function(){
                $list.html('<div style="color:red;">網路錯誤</div>');
            });
        });

        $('#media-encoder-delete-unused').on('click', function(){
            const ids = $('.wu-unused-item:checked').map(function(){
                return this.value;
            }).get();

            if (ids.length === 0) {
                alert('請先選擇要刪除的圖像');
                return;
            }

            if (!confirm('確定刪除選取的 ' + ids.length + ' 個圖像？此動作無法復原。')) return;

            $.post(ajaxurl, {
                action: 'media_encoder_delete_unused',
                _wpnonce: nonce,
                ids: ids
            }, function(res){
                if (res && res.success) {
                    $('#media-encoder-scan-unused').click();
                } else {
                    alert(res && res.data ? res.data : '刪除失敗');
                }
            }).fail(function(){
                alert('網路錯誤');
            });
        });
    });
    </script>
    <?php
}

/* === 條件式註冊:僅啟用時才掛鉤 === */
function media_encoder_maybe_register_hooks() {
    $settings = media_encoder_get_settings();
    if ($settings['enabled'] !== 'on') return;

    add_filter('wp_generate_attachment_metadata', 'media_encoder_convert_on_upload', 20, 2);
}
add_action('init', 'media_encoder_maybe_register_hooks');

/* === 避免重入 === */
function media_encoder_lock_key($attachment_id) {
    return '_media_encoder_processing_' . intval($attachment_id);
}

function media_encoder_is_processing($attachment_id) {
    return (bool) get_transient(media_encoder_lock_key($attachment_id));
}

function media_encoder_set_processing($attachment_id, $ttl = 120) {
    set_transient(media_encoder_lock_key($attachment_id), 1, $ttl);
}

function media_encoder_clear_processing($attachment_id) {
    delete_transient(media_encoder_lock_key($attachment_id));
}

/* === 安全路徑工具 === */
function media_encoder_is_supported_raster($path) {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return in_array($ext, array('jpg', 'jpeg', 'png'), true);
}

function media_encoder_webp_path_from_source($src_path) {
    return preg_replace('/\.(jpe?g|png)$/i', '.webp', $src_path);
}

function media_encoder_temp_webp_path($dest_path) {
    $dir = dirname($dest_path);
    $base = wp_generate_password(12, false, false);
    return trailingslashit($dir) . '.' . $base . '-' . basename($dest_path) . '.tmp';
}

function media_encoder_safe_unlink($path) {
    if ($path && file_exists($path)) {
        @unlink($path);
    }
}

function media_encoder_safe_replace($tmp_path, $dest_path) {
    if (!file_exists($tmp_path)) {
        return new WP_Error('tmp_missing', '暫存檔不存在');
    }

    if (file_exists($dest_path)) {
        @unlink($dest_path);
    }

    if (!@rename($tmp_path, $dest_path)) {
        @copy($tmp_path, $dest_path);
        @unlink($tmp_path);
    }

    if (!file_exists($dest_path)) {
        return new WP_Error('replace_failed', '無法寫入最終檔案');
    }

    @chmod($dest_path, 0644);
    return true;
}

/* === 轉換工具:GD 或 Imagick === */
function media_encoder_can_convert() {
    return (function_exists('imagewebp') || class_exists('Imagick'));
}

function media_encoder_convert_file_to_webp($src_path, $quality = 82) {
    $quality = max(1, min(100, intval($quality)));

    if (!$src_path || !file_exists($src_path)) {
        media_encoder_log_error('來源檔案不存在', array('path' => $src_path));
        return new WP_Error('missing_file', '來源檔案不存在');
    }

    if (!is_readable($src_path)) {
        media_encoder_log_error('來源檔案無法讀取', array('path' => $src_path));
        return new WP_Error('unreadable_file', '來源檔案無法讀取');
    }

    if (!media_encoder_is_supported_raster($src_path)) {
        return new WP_Error('bad_type', '僅支援 JPEG/PNG');
    }

    $dest_path = media_encoder_webp_path_from_source($src_path);
    if (!$dest_path || $dest_path === $src_path) {
        media_encoder_log_error('目標路徑異常', array('src' => $src_path, 'dest' => $dest_path));
        return new WP_Error('bad_dest', '目標路徑異常');
    }

    $dest_dir = dirname($dest_path);
    if (!is_dir($dest_dir) || !is_writable($dest_dir)) {
        media_encoder_log_error('目標目錄無法寫入', array('dir' => $dest_dir));
        return new WP_Error('unwritable_dir', '目標目錄無法寫入');
    }

    $tmp_path = media_encoder_temp_webp_path($dest_path);

    if (class_exists('Imagick')) {
        try {
            $im = new Imagick($src_path);
            $im->setImageFormat('webp');
            $im->setImageCompressionQuality($quality);

            if (strtolower(pathinfo($src_path, PATHINFO_EXTENSION)) === 'png') {
                $im->setImageAlphaChannel(Imagick::ALPHACHANNEL_ACTIVATE);
            }

            $write_result = $im->writeImage($tmp_path);
            $im->clear();
            $im->destroy();

            if (!$write_result || !file_exists($tmp_path)) {
                media_encoder_log_error('Imagick 寫入失敗', array('src' => $src_path, 'tmp' => $tmp_path));
                return new WP_Error('imagick_write_failed', 'Imagick 寫入失敗');
            }

            $replace = media_encoder_safe_replace($tmp_path, $dest_path);
            if (is_wp_error($replace)) {
                media_encoder_log_error('Imagick 取代目標檔失敗', array('src' => $src_path, 'dest' => $dest_path, 'error' => $replace->get_error_message()));
                return $replace;
            }

            return array(
                'path' => $dest_path,
                'mime' => 'image/webp',
            );
        } catch (Exception $e) {
            media_encoder_safe_unlink($tmp_path);
            media_encoder_log_error('Imagick 轉換錯誤', array('error' => $e->getMessage(), 'src' => $src_path));
        }
    }

    if (!function_exists('imagewebp')) {
        return new WP_Error('no_encoder', '伺服器未啟用 WebP 編碼（缺少 Imagick 或 GD imagewebp）');
    }

    try {
        $ext = strtolower(pathinfo($src_path, PATHINFO_EXTENSION));

        if (in_array($ext, array('jpg', 'jpeg'), true)) {
            $img = @imagecreatefromjpeg($src_path);
        } else {
            $img = @imagecreatefrompng($src_path);
            if ($img) {
                imagepalettetotruecolor($img);
                imagealphablending($img, true);
                imagesavealpha($img, true);
            }
        }

        if (!$img) {
            media_encoder_log_error('GD 影像解碼失敗', array('src' => $src_path));
            return new WP_Error('decode_failed', '影像解碼失敗');
        }

        $result = imagewebp($img, $tmp_path, $quality);
        imagedestroy($img);

        if (!$result || !file_exists($tmp_path)) {
            media_encoder_safe_unlink($tmp_path);
            media_encoder_log_error('GD WebP 編碼失敗', array('src' => $src_path, 'tmp' => $tmp_path));
            return new WP_Error('encode_failed', 'WebP 編碼失敗');
        }

        $replace = media_encoder_safe_replace($tmp_path, $dest_path);
        if (is_wp_error($replace)) {
            media_encoder_log_error('GD 取代目標檔失敗', array('src' => $src_path, 'dest' => $dest_path, 'error' => $replace->get_error_message()));
            return $replace;
        }

        return array(
            'path' => $dest_path,
            'mime' => 'image/webp',
        );
    } catch (Exception $e) {
        media_encoder_safe_unlink($tmp_path);
        media_encoder_log_error('GD 轉換錯誤', array('error' => $e->getMessage(), 'src' => $src_path));
        return new WP_Error('gd_error', $e->getMessage());
    }
}

/* === 刪除來源檔（保守處理） === */
function media_encoder_delete_source_if_needed($src_path, $new_path) {
    if (!$src_path || !$new_path) return false;
    if ($src_path === $new_path) return false;
    if (!file_exists($src_path)) return false;

    $deleted = @unlink($src_path);
    if (!$deleted) {
        media_encoder_log_error('無法刪除舊檔', array('src' => $src_path, 'new' => $new_path));
    }
    return $deleted;
}

/* === 單一檔案安全轉換後更新檔名 === */
function media_encoder_convert_single_path_and_cleanup($src_path, $quality = 82) {
    $result = media_encoder_convert_file_to_webp($src_path, $quality);
    if (is_wp_error($result)) {
        return $result;
    }

    $new_path = $result['path'];
    if (!file_exists($new_path)) {
        return new WP_Error('webp_missing', '轉換完成但找不到 WebP 檔案');
    }

    media_encoder_delete_source_if_needed($src_path, $new_path);

    return array(
        'old_path' => $src_path,
        'new_path' => $new_path,
        'file'     => basename($new_path),
        'mime'     => 'image/webp',
    );
}

/* === 附件上傳時自動轉 WebP（修正版） === */
function media_encoder_convert_on_upload($metadata, $attachment_id) {
    $settings = media_encoder_get_settings();

    if ($settings['enabled'] !== 'on') {
        return $metadata;
    }

    if (!media_encoder_can_convert()) {
        return $metadata;
    }

    if (empty($attachment_id) || media_encoder_is_processing($attachment_id)) {
        return $metadata;
    }

    $mime = get_post_mime_type($attachment_id);
    if (!in_array($mime, array('image/jpeg', 'image/png'), true)) {
        return $metadata;
    }

    $src_path = get_attached_file($attachment_id);
    if (!$src_path || !file_exists($src_path)) {
        media_encoder_log_error('上傳轉換失敗：找不到原始附件檔', array(
            'attachment_id' => $attachment_id,
            'path' => $src_path,
        ));
        return $metadata;
    }

    if (!media_encoder_is_supported_raster($src_path)) {
        return $metadata;
    }

    media_encoder_set_processing($attachment_id, 180);

    try {
        $quality = intval($settings['quality']);
        $upload_dir = wp_get_upload_dir();
        $basedir = isset($upload_dir['basedir']) ? trailingslashit($upload_dir['basedir']) : '';

        media_encoder_log_info('開始處理上傳圖片', array(
            'attachment_id' => $attachment_id,
            'path' => $src_path,
        ));

        $main = media_encoder_convert_single_path_and_cleanup($src_path, $quality);
        if (is_wp_error($main)) {
            media_encoder_log_error('原圖轉換失敗', array(
                'attachment_id' => $attachment_id,
                'error' => $main->get_error_message(),
                'path' => $src_path,
            ));
            return $metadata;
        }

        $new_main_path = $main['new_path'];

        if ($basedir && strpos($new_main_path, $basedir) === 0) {
            $metadata['file'] = ltrim(str_replace($basedir, '', $new_main_path), '/\\');
        } else {
            $metadata['file'] = _wp_relative_upload_path($new_main_path);
        }

        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            $main_dir = dirname($new_main_path);

            foreach ($metadata['sizes'] as $size_name => $size_data) {
                if (empty($size_data['file'])) {
                    continue;
                }

                $size_src_path = trailingslashit($main_dir) . wp_basename($size_data['file']);

                if (!file_exists($size_src_path)) {
                    media_encoder_log_error('找不到縮圖來源檔', array(
                        'attachment_id' => $attachment_id,
                        'size' => $size_name,
                        'path' => $size_src_path,
                    ));
                    continue;
                }

                if (!media_encoder_is_supported_raster($size_src_path)) {
                    if (strtolower(pathinfo($size_src_path, PATHINFO_EXTENSION)) === 'webp') {
                        $metadata['sizes'][$size_name]['mime-type'] = 'image/webp';
                    }
                    continue;
                }

                $size_result = media_encoder_convert_single_path_and_cleanup($size_src_path, $quality);

                if (is_wp_error($size_result)) {
                    media_encoder_log_error('縮圖轉換失敗', array(
                        'attachment_id' => $attachment_id,
                        'size' => $size_name,
                        'path' => $size_src_path,
                        'error' => $size_result->get_error_message(),
                    ));
                    continue;
                }

                $metadata['sizes'][$size_name]['file'] = basename($size_result['new_path']);
                $metadata['sizes'][$size_name]['mime-type'] = 'image/webp';
            }
        }

        update_attached_file($attachment_id, $new_main_path);
        wp_update_post(array(
            'ID' => $attachment_id,
            'post_mime_type' => 'image/webp',
        ));

        media_encoder_log_info('上傳圖片轉換完成', array(
            'attachment_id' => $attachment_id,
            'new_path' => $new_main_path,
        ));

        return $metadata;
    } catch (Throwable $e) {
        media_encoder_log_error('上傳轉換例外', array(
            'attachment_id' => $attachment_id,
            'error' => $e->getMessage(),
        ));
        return $metadata;
    } finally {
        media_encoder_clear_processing($attachment_id);
    }
}

/* === AJAX:預覽單一圖片轉換 === */
add_action('wp_ajax_media_encoder_preview', function() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('權限不足');
    }

    check_ajax_referer('media_encoder_ajax');

    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    if (!$id) {
        wp_send_json_error('缺少附件 ID');
    }

    $mime = get_post_mime_type($id);
    if (!in_array($mime, array('image/jpeg', 'image/png'), true)) {
        wp_send_json_error('僅支援 JPEG/PNG');
    }

    $src_path = get_attached_file($id);
    if (!$src_path || !file_exists($src_path)) {
        wp_send_json_error('找不到原始檔案');
    }

    $settings = media_encoder_get_settings();
    $quality = intval($settings['quality']);

    $original_size = @filesize($src_path);
    $res = media_encoder_convert_file_to_webp($src_path, $quality);

    if (is_wp_error($res)) {
        wp_send_json_error($res->get_error_message());
    }

    $webp_path = $res['path'];
    $webp_size = @filesize($webp_path);
    $preview_url = str_replace(wp_get_upload_dir()['basedir'], wp_get_upload_dir()['baseurl'], $webp_path);

    $saving_percent = null;
    if ($original_size && $webp_size !== false && $original_size > 0) {
        $saving_percent = round((1 - ($webp_size / $original_size)) * 100, 1);
    }

    media_encoder_safe_unlink($webp_path);

    wp_send_json_success(array(
        'original_size_human' => size_format((int) $original_size),
        'webp_size_human'     => size_format((int) $webp_size),
        'saving_percent'      => $saving_percent,
        'preview_url'         => $preview_url,
    ));
});

/* === 取得總數 === */
add_action('wp_ajax_media_encoder_get_total_count', function() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('權限不足');
    }

    check_ajax_referer('media_encoder_ajax');

    $q = new WP_Query(array(
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'post_mime_type' => array('image/jpeg', 'image/png'),
        'posts_per_page' => 1,
        'fields'         => 'ids',
    ));

    wp_send_json_success(array(
        'total' => intval($q->found_posts),
    ));
});

/* === 取得系統負載狀態 === */
function media_encoder_get_system_status() {
    $status = array(
        'load_level'           => 'low',
        'processing_mode'      => '一般',
        'suggested_batch_size' => 5,
        'suggested_delay'      => 2,
    );

    $memory_limit = wp_convert_hr_to_bytes(ini_get('memory_limit'));
    $memory_usage = memory_get_usage(true);
    $memory_ratio = ($memory_limit > 0) ? ($memory_usage / $memory_limit) : 0;

    $load_avg = null;
    if (function_exists('sys_getloadavg')) {
        $load = sys_getloadavg();
        $load_avg = isset($load[0]) ? floatval($load[0]) : null;
    }

    if ($memory_ratio > 0.8 || ($load_avg !== null && $load_avg > 4)) {
        $status['load_level'] = 'high';
        $status['processing_mode'] = '高負載保護';
        $status['suggested_batch_size'] = 1;
        $status['suggested_delay'] = 8;
    } elseif ($memory_ratio > 0.6 || ($load_avg !== null && $load_avg > 2)) {
        $status['load_level'] = 'medium';
        $status['processing_mode'] = '平衡模式';
        $status['suggested_batch_size'] = 3;
        $status['suggested_delay'] = 4;
    } else {
        $status['load_level'] = 'low';
        $status['processing_mode'] = '高速模式';
        $status['suggested_batch_size'] = 5;
        $status['suggested_delay'] = 2;
    }

    return $status;
}

/* === AJAX:批次轉換 === */
add_action('wp_ajax_media_encoder_bulk', function() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('權限不足');
    }

    check_ajax_referer('media_encoder_ajax');

    $offset = isset($_POST['offset']) ? max(0, intval($_POST['offset'])) : 0;
    $limit  = isset($_POST['limit']) ? max(1, min(20, intval($_POST['limit']))) : 5;

    $settings = media_encoder_get_settings();
    $quality = intval($settings['quality']);

    $q = new WP_Query(array(
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'post_mime_type' => array('image/jpeg', 'image/png'),
        'posts_per_page' => $limit,
        'offset'         => $offset,
        'orderby'        => 'ID',
        'order'          => 'ASC',
        'fields'         => 'ids',
    ));

    $processed = 0;
    $converted = 0;
    $skipped = 0;
    $errors = 0;
    $saved_space = 0;
    $files = array();

    if (empty($q->posts)) {
        wp_send_json_success(array(
            'processed'     => 0,
            'converted'     => 0,
            'skipped'       => 0,
            'errors'        => 0,
            'saved_space'   => 0,
            'files'         => array(),
            'done'          => true,
            'system_status' => media_encoder_get_system_status(),
        ));
    }

    foreach ($q->posts as $attachment_id) {
        $processed++;

        if (media_encoder_is_processing($attachment_id)) {
            $skipped++;
            $files[] = array(
                'filename' => 'attachment #' . $attachment_id,
                'skipped'  => true,
                'reason'   => '檔案處理中',
            );
            continue;
        }

        $src_path = get_attached_file($attachment_id);
        if (!$src_path || !file_exists($src_path)) {
            $errors++;
            $files[] = array(
                'filename' => 'attachment #' . $attachment_id,
                'error'    => '找不到檔案',
            );
            continue;
        }

        if (!media_encoder_is_supported_raster($src_path)) {
            $skipped++;
            $files[] = array(
                'filename' => basename($src_path),
                'skipped'  => true,
                'reason'   => '非 JPEG/PNG',
            );
            continue;
        }

        $original_size = @filesize($src_path);
        media_encoder_set_processing($attachment_id, 180);

        try {
            $result = media_encoder_convert_single_path_and_cleanup($src_path, $quality);
            if (is_wp_error($result)) {
                $errors++;
                $files[] = array(
                    'filename' => basename($src_path),
                    'error'    => $result->get_error_message(),
                );
                continue;
            }

            $new_path = $result['new_path'];
            $webp_size = @filesize($new_path);

            update_attached_file($attachment_id, $new_path);
            wp_update_post(array(
                'ID' => $attachment_id,
                'post_mime_type' => 'image/webp',
            ));

            $metadata = wp_get_attachment_metadata($attachment_id);
            if (!empty($metadata) && is_array($metadata)) {
                $upload_dir = wp_get_upload_dir();
                $basedir = trailingslashit($upload_dir['basedir']);

                if (strpos($new_path, $basedir) === 0) {
                    $metadata['file'] = ltrim(str_replace($basedir, '', $new_path), '/\\');
                } else {
                    $metadata['file'] = _wp_relative_upload_path($new_path);
                }

                if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
                    $main_dir = dirname($new_path);
                    foreach ($metadata['sizes'] as $size_name => $size_data) {
                        if (empty($size_data['file'])) continue;

                        $size_src_path = trailingslashit($main_dir) . wp_basename($size_data['file']);
                        if (!file_exists($size_src_path) || !media_encoder_is_supported_raster($size_src_path)) {
                            continue;
                        }

                        $size_result = media_encoder_convert_single_path_and_cleanup($size_src_path, $quality);
                        if (is_wp_error($size_result)) {
                            continue;
                        }

                        $metadata['sizes'][$size_name]['file'] = basename($size_result['new_path']);
                        $metadata['sizes'][$size_name]['mime-type'] = 'image/webp';
                    }
                }

                wp_update_attachment_metadata($attachment_id, $metadata);
            }

            $converted++;
            if ($original_size && $webp_size !== false && $original_size > $webp_size) {
                $saved_space += ($original_size - $webp_size);
            }

            $saving_percent = null;
            if ($original_size && $webp_size !== false && $original_size > 0) {
                $saving_percent = round((1 - ($webp_size / $original_size)) * 100, 1);
            }

            $files[] = array(
                'filename'       => basename($src_path),
                'converted'      => true,
                'original_size'  => size_format((int) $original_size),
                'webp_size'      => size_format((int) $webp_size),
                'saving_percent' => $saving_percent,
            );
        } catch (Throwable $e) {
            $errors++;
            $files[] = array(
                'filename' => basename($src_path),
                'error'    => $e->getMessage(),
            );
        } finally {
            media_encoder_clear_processing($attachment_id);
        }
    }

    $done = (count($q->posts) < $limit);

    wp_send_json_success(array(
        'processed'     => $processed,
        'converted'     => $converted,
        'skipped'       => $skipped,
        'errors'        => $errors,
        'saved_space'   => $saved_space,
        'files'         => $files,
        'done'          => $done,
        'system_status' => media_encoder_get_system_status(),
    ));
});

/* === AJAX:重新產生縮圖(分批背景處理)=== */
add_action('wp_ajax_media_encoder_regenerate_thumbnails', function() {
    if (!current_user_can('manage_options')) wp_send_json_error('權限不足');
    check_ajax_referer('media_encoder_ajax');

    if (!wp_next_scheduled('media_encoder_cron_regen_batch')) {
        wp_schedule_single_event(time() + 1, 'media_encoder_cron_regen_batch', array('offset' => 0));
    }

    update_option('media_encoder_regen_progress', array(
        'items' => array(),
        'done'  => false,
    ));

    wp_send_json_success(true);
});

add_action('media_encoder_cron_regen_batch', function($offset) {
    $batch = 25;

    $q = new WP_Query(array(
        'post_type'      => 'attachment',
        'post_mime_type' => array('image/jpeg', 'image/png', 'image/webp'),
        'posts_per_page' => $batch,
        'offset'         => intval($offset),
        'fields'         => 'ids',
        'orderby'        => 'ID',
        'order'          => 'ASC',
    ));

    $progress = get_option('media_encoder_regen_progress', array(
        'items' => array(),
        'done'  => false,
    ));

    if (empty($q->posts)) {
        $progress['done'] = true;
        update_option('media_encoder_regen_progress', $progress);
        return;
    }

    foreach ($q->posts as $aid) {
        $path = get_attached_file($aid);
        if (!$path || !file_exists($path)) continue;

        remove_filter('wp_generate_attachment_metadata', 'media_encoder_convert_on_upload', 20);
        $meta = wp_generate_attachment_metadata($aid, $path);
        add_filter('wp_generate_attachment_metadata', 'media_encoder_convert_on_upload', 20, 2);

        if ($meta) {
            wp_update_attachment_metadata($aid, $meta);
        }

        $progress['items'][] = array(
            'id'     => $aid,
            'file'   => basename($path),
            'status' => '已重新產生縮圖',
        );
    }

    wp_schedule_single_event(time() + 15, 'media_encoder_cron_regen_batch', array('offset' => intval($offset) + $batch));
    update_option('media_encoder_regen_progress', $progress);
}, 10, 1);

add_action('wp_ajax_media_encoder_get_regen_progress', function() {
    if (!current_user_can('manage_options')) wp_send_json_error();
    check_ajax_referer('media_encoder_ajax');

    $progress = get_option('media_encoder_regen_progress', array(
        'items' => array(),
        'done'  => false,
    ));

    $items = array_slice($progress['items'], -50);

    wp_send_json_success(array(
        'items' => $items,
        'done'  => !empty($progress['done']),
    ));
});

/* === AJAX:掃描未使用圖像 === */
add_action('wp_ajax_media_encoder_scan_unused', function() {
    if (!current_user_can('manage_options')) wp_send_json_error('權限不足');
    check_ajax_referer('media_encoder_ajax');

    $results = array();
    $attachments = get_posts(array(
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'posts_per_page' => 500,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ));

    foreach ($attachments as $att) {
        $attached = get_post_field('post_parent', $att->ID);
        $file = get_attached_file($att->ID);

        if (!$file || !file_exists($file)) continue;

        $filesize = @filesize($file);
        $in_use = false;
        $url = wp_get_attachment_url($att->ID);

        $search = new WP_Query(array(
            's'              => esc_url_raw($url),
            'posts_per_page' => 1,
            'post_status'    => 'any',
        ));

        if ($attached || ($search && $search->have_posts())) {
            $in_use = true;
        }

        if ($in_use) continue;

        $author = get_user_by('id', $att->post_author);
        $results[] = array(
            'id'         => $att->ID,
            'file'       => basename($file),
            'uploader'   => $author ? $author->display_name : '未知',
            'size'       => $filesize,
            'size_human' => size_format($filesize),
        );

        if (count($results) >= 200) break;
    }

    wp_send_json_success($results);
});

/* === AJAX:刪除未使用圖像 === */
add_action('wp_ajax_media_encoder_delete_unused', function() {
    if (!current_user_can('manage_options')) wp_send_json_error('權限不足');
    check_ajax_referer('media_encoder_ajax');

    $ids = isset($_POST['ids']) ? array_map('intval', (array) $_POST['ids']) : array();
    if (empty($ids)) wp_send_json_error('沒有選取項目');

    $deleted = 0;
    foreach ($ids as $aid) {
        if (wp_delete_attachment($aid, true)) {
            $deleted++;
        }
    }

    wp_send_json_success(array(
        'deleted' => $deleted,
    ));
});

/* === 過濾:停用選取的縮圖尺寸 === */
add_filter('intermediate_image_sizes_advanced', function($sizes) {
    $disabled = (array) get_option('media_encoder_disabled_sizes', array());
    if (empty($disabled)) return $sizes;

    foreach ($disabled as $d) {
        unset($sizes[$d]);
    }

    return $sizes;
}, 10, 1);

/* === WebP 回退：PNG/JPG 請求自動改成 WebP === */
function media_encoder_try_webp_fallback($file) {
    $settings = media_encoder_get_settings();
    if ($settings['enable_webp_fallback'] !== 'on') {
        return $file;
    }

    if (!$file) {
        return $file;
    }

    if (file_exists($file)) {
        return $file;
    }

    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if (!in_array($ext, array('jpg', 'jpeg', 'png'), true)) {
        return $file;
    }

    $webp = media_encoder_webp_path_from_source($file);
    if ($webp && file_exists($webp)) {
        return $webp;
    }

    return $file;
}

add_filter('load_image_to_edit_path', 'media_encoder_try_webp_fallback', 10, 1);

/* === 修正附件 URL 輸出 === */
add_filter('wp_get_attachment_url', function($url, $attachment_id) {
    $file = get_attached_file($attachment_id);
    if (!$file || !file_exists($file)) {
        $fallback = media_encoder_try_webp_fallback($file);
        if ($fallback && $fallback !== $file && file_exists($fallback)) {
            $uploads = wp_get_upload_dir();
            return str_replace($uploads['basedir'], $uploads['baseurl'], $fallback);
        }
    }
    return $url;
}, 10, 2);
