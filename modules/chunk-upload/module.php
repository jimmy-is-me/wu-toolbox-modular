<?php
/** Chunked MP4 uploads for the WordPress media library. */
defined('ABSPATH') || exit;
if (!is_admin()) return;

require_once __DIR__ . '/includes/class-upload-storage.php';
require_once __DIR__ . '/includes/class-uploader.php';

final class WUTM_Chunk_Upload_Module {
    const SLUG = 'wu-chunk-upload';
    private static $hook = '';

    public static function can_access(): bool {
        if (!current_user_can('upload_files')) return false;
        if (!current_user_can('manage_options') || !wutm_toolbox_hidden()) return true;
        return wutm_toolbox_is_owner() || wutm_module_is_shared('chunk-upload');
    }

    public static function menu(): void {
        self::$hook = add_submenu_page('wu-toolbox-modular', '分段上傳', '分段上傳', 'upload_files', self::SLUG, [__CLASS__, 'render']);
    }

    public static function assets(string $hook): void {
        if (self::$hook !== $hook || !self::can_access()) return;
        $base = WUTM_URL . 'modules/chunk-upload/assets/';
        wp_enqueue_style('wutm-chunk-upload', $base . 'upload.css', [], WUTM_VERSION);
        wp_enqueue_script('wutm-chunk-upload', $base . 'upload.js', [], WUTM_VERSION, true);
    }

    public static function render(): void {
        if (!self::can_access()) wp_die('沒有媒體上傳權限。', '', ['response'=>403]);
        $max = WUTM_Chunk_Uploader::max_bytes();
        $part = 0;
        $warning = '';
        try {
            new WUTM_Chunk_Upload_Storage(false);
            $part = WUTM_Chunk_Upload_Storage::part_size();
        } catch (WUTM_Chunk_Upload_Error $error) {
            $warning = $error->getMessage();
        }
        if ($max <= 0) $warning = '本站未開放影片上傳。';
        wutm_admin_page_open('分段上傳', '將 MP4 影片分成小段傳送，完成後自動加入 WordPress 媒體庫。');
        ?>
        <section class="wutm-ui-panel" id="wutm-chunk-upload"
            data-ajax="<?php echo esc_url(admin_url('admin-ajax.php', 'relative')); ?>"
            data-action="<?php echo esc_attr(WUTM_Chunk_Uploader::ACTION); ?>"
            data-nonce="<?php echo esc_attr(wp_create_nonce(WUTM_Chunk_Uploader::ACTION)); ?>"
            data-max="<?php echo esc_attr($max); ?>"
            data-scope="<?php echo esc_attr(get_current_blog_id() . '-' . get_current_user_id()); ?>">
            <div class="wutm-ui-panel-body">
                <h2>MP4 影片</h2>
                <?php if ($warning !== '') : ?>
                    <p role="status"><?php echo esc_html($warning); ?></p>
                <?php else : ?>
                    <p>檔案上限 <?php echo esc_html(size_format($max)); ?>，每段最多 <?php echo esc_html(size_format($part)); ?>。暫停或重新整理後，可選取同一支影片續傳。</p>
                    <form data-upload-form>
                        <div class="wutm-chunk-file">
                            <label for="wutm-chunk-file">選取影片</label>
                            <input type="file" id="wutm-chunk-file" accept=".mp4,video/mp4" required>
                        </div>
                        <div class="wutm-chunk-actions">
                            <button type="submit" class="button button-primary" data-start>開始上傳</button>
                            <button type="button" class="button" data-pause disabled>暫停</button>
                            <button type="button" class="button" data-cancel disabled>取消上傳</button>
                        </div>
                    </form>
                    <div class="wutm-chunk-progress" hidden>
                        <progress value="0" max="100" aria-label="影片上傳進度"></progress><span data-percent>0%</span>
                    </div>
                    <p data-status role="status" aria-live="polite"></p>
                    <div class="wutm-chunk-actions" data-result hidden></div>
                    <noscript><p>請啟用 JavaScript 以使用分段上傳。</p></noscript>
                <?php endif; ?>
            </div>
        </section>
        <?php
        wutm_admin_page_close();
    }
}
add_action('admin_menu', ['WUTM_Chunk_Upload_Module','menu']);
add_action('admin_enqueue_scripts', ['WUTM_Chunk_Upload_Module','assets']);
add_action('wp_ajax_' . WUTM_Chunk_Uploader::ACTION, ['WUTM_Chunk_Uploader','request']);
