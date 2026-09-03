<?php
/**
 * Module: media-sync
 * 掃描 uploads 目錄中尚未建立媒體附件的檔案，並由管理員選擇匯入。
 */
defined('ABSPATH') || exit;

final class WUTM_Media_Sync {
    private const SLUG = 'wu-media-sync';
    private const MAX_RESULTS = 500;

    public static function boot(): void {
        add_action('admin_menu', [__CLASS__, 'menu'], 20);
        add_action('admin_post_wutm_media_sync_import', [__CLASS__, 'import']);
    }

    public static function menu(): void {
        add_submenu_page(
            'wu-toolbox-modular',
            '媒體掃描匯入',
            '媒體掃描匯入',
            'upload_files',
            self::SLUG,
            [__CLASS__, 'page']
        );
    }

    private static function uploads(): array {
        return wp_get_upload_dir();
    }

    private static function relative_path(string $absolute): string {
        $uploads = self::uploads();
        return ltrim(str_replace(wp_normalize_path($uploads['basedir']), '', wp_normalize_path($absolute)), '/');
    }

    private static function valid_file(string $relative): string {
        $uploads = self::uploads();
        $base = realpath($uploads['basedir']);
        $candidate = realpath(trailingslashit($uploads['basedir']) . ltrim(str_replace('..', '', $relative), '/\\'));
        if (!$base || !$candidate || strpos(wp_normalize_path($candidate), trailingslashit(wp_normalize_path($base))) !== 0 || !is_file($candidate) || is_link($candidate)) {
            return '';
        }
        return $candidate;
    }

    private static function registered_files(): array {
        global $wpdb;
        $values = $wpdb->get_col("SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file'");
        return array_fill_keys(array_map(static function ($value): string {
            return ltrim(wp_normalize_path((string) $value), '/');
        }, (array) $values), true);
    }

    private static function scan(): array {
        $uploads = self::uploads();
        $base = realpath($uploads['basedir']);
        if (!$base || !is_dir($base) || !is_readable($base)) {
            return [];
        }

        $registered = self::registered_files();
        $files = [];
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->isLink()) {
                    continue;
                }
                $relative = self::relative_path($file->getPathname());
                if ($relative === '' || isset($registered[$relative])) {
                    continue;
                }
                $type = wp_check_filetype($file->getFilename());
                if (empty($type['type'])) {
                    continue;
                }
                $files[] = [
                    'path' => $relative,
                    'size' => (int) $file->getSize(),
                    'modified' => (int) $file->getMTime(),
                    'type' => $type['type'],
                ];
                if (count($files) >= self::MAX_RESULTS) {
                    break;
                }
            }
        } catch (UnexpectedValueException $exception) {
            return [];
        }

        usort($files, static function (array $a, array $b): int {
            return strcmp($a['path'], $b['path']);
        });
        return $files;
    }

    public static function import(): void {
        if (!current_user_can('upload_files')) {
            wp_die('權限不足。', 403);
        }
        check_admin_referer('wutm_media_sync_import');

        $requested = array_unique(array_map('sanitize_text_field', (array) wp_unslash($_POST['files'] ?? [])));
        $requested = array_slice($requested, 0, 50);
        $success = 0;
        $skipped = 0;

        foreach ($requested as $relative) {
            $file = self::valid_file($relative);
            if ($file === '') {
                $skipped++;
                continue;
            }

            $relative = self::relative_path($file);
            global $wpdb;
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1",
                $relative
            ));
            if ($exists) {
                $skipped++;
                continue;
            }

            $type = wp_check_filetype(basename($file));
            if (empty($type['type'])) {
                $skipped++;
                continue;
            }

            $uploads = self::uploads();
            $attachment_id = wp_insert_attachment([
                'guid' => trailingslashit($uploads['baseurl']) . $relative,
                'post_mime_type' => $type['type'],
                'post_title' => sanitize_file_name(pathinfo($file, PATHINFO_FILENAME)),
                'post_status' => 'inherit',
            ], $file, 0, true);

            if (is_wp_error($attachment_id)) {
                $skipped++;
                continue;
            }

            require_once ABSPATH . 'wp-admin/includes/image.php';
            $metadata = wp_generate_attachment_metadata($attachment_id, $file);
            if (is_array($metadata)) {
                wp_update_attachment_metadata($attachment_id, $metadata);
            }
            $success++;
        }

        wp_safe_redirect(add_query_arg([
            'page' => self::SLUG,
            'imported' => $success,
            'skipped' => $skipped,
        ], admin_url('admin.php')));
        exit;
    }

    public static function page(): void {
        if (!current_user_can('upload_files')) {
            wp_die('權限不足。', 403);
        }
        $uploads = self::uploads();
        $files = self::scan();
        ?>
        <div class="wrap wutm-module-wrap">
            <h1>媒體掃描匯入</h1>
            <p class="wutm-module-subtitle">掃描 <code><?php echo esc_html($uploads['basedir']); ?></code> 中尚未登錄到媒體庫的檔案，再由您勾選匯入。此功能不會移動、重新命名或刪除實體檔案。</p>

            <?php if (isset($_GET['imported'])) : ?>
                <div class="notice notice-success is-dismissible"><p>已匯入 <?php echo (int) $_GET['imported']; ?> 個檔案，略過 <?php echo (int) ($_GET['skipped'] ?? 0); ?> 個無效、重複或不支援的檔案。</p></div>
            <?php endif; ?>

            <div class="notice notice-warning inline"><p><strong>匯入前請先備份。</strong>匯入會為選取檔案建立 WordPress 媒體附件與縮圖資訊；單次最多處理 50 個檔案，避免耗盡伺服器資源。</p></div>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="wutm_media_sync_import">
                <?php wp_nonce_field('wutm_media_sync_import'); ?>
                <p><button type="submit" class="button button-primary" <?php disabled(empty($files)); ?>>匯入已勾選的檔案</button> <a class="button" href="<?php echo esc_url(add_query_arg('page', self::SLUG, admin_url('admin.php'))); ?>">重新掃描</a></p>

                <?php if (empty($files)) : ?>
                    <div class="card"><p>找不到尚未匯入的可支援檔案。</p></div>
                <?php else : ?>
                    <table class="widefat striped">
                        <thead><tr><td class="check-column"><input type="checkbox" id="wutm-select-all"></td><th>檔案</th><th>類型</th><th>大小</th><th>最後修改</th></tr></thead>
                        <tbody>
                        <?php foreach ($files as $file) : ?>
                            <tr>
                                <th class="check-column"><input type="checkbox" name="files[]" value="<?php echo esc_attr($file['path']); ?>"></th>
                                <td><code><?php echo esc_html($file['path']); ?></code></td>
                                <td><?php echo esc_html($file['type']); ?></td>
                                <td><?php echo esc_html(size_format($file['size'])); ?></td>
                                <td><?php echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $file['modified'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if (count($files) >= self::MAX_RESULTS) : ?><p class="description">為保護後台效能，目前僅顯示前 <?php echo self::MAX_RESULTS; ?> 個結果；請先匯入或整理 uploads 資料夾後再掃描。</p><?php endif; ?>
                    <script>document.getElementById('wutm-select-all').addEventListener('change',function(){document.querySelectorAll('input[name="files[]"]').forEach(function(el){el.checked=this.checked;},this);});</script>
                <?php endif; ?>
            </form>
        </div>
        <?php
    }
}

WUTM_Media_Sync::boot();
