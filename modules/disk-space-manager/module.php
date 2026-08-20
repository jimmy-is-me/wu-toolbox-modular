<?php
/** WU Toolbox Modular module: disk space manager. */
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Plugin Name: 磁碟空間管理員
 * Description: 掃描網站佔用空間、找出大檔案來源、可手動或智能刪除清理。
 * Version: 1.0
 * Author: Custom
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DSM_Disk_Space_Manager {

    const CACHE_KEY = 'dsm_scan_cache';
    const NONCE_ACTION = 'dsm_action_nonce';

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'wp_ajax_dsm_scan', [ $this, 'ajax_scan' ] );
        add_action( 'wp_ajax_dsm_delete', [ $this, 'ajax_delete' ] );
        add_action( 'wp_ajax_dsm_smart_clean', [ $this, 'ajax_smart_clean' ] );
    }

    public function add_menu() {
        add_submenu_page(
            'wu-toolbox-modular',
            '磁碟空間管理',
            '磁碟空間管理',
            'manage_options',
            'wu-disk-space-manager',
            [ $this, 'render_page' ]
        );
    }

    public function enqueue_assets( $hook ) {
        if ( $hook !== 'wu-toolbox-modular_page_wu-disk-space-manager' ) return;
        wp_enqueue_script( 'jquery' );
    }

    /* ---------------- 掃描邏輯 ---------------- */

    private function get_wp_content_root() {
        return trailingslashit( WP_CONTENT_DIR );
    }

    private function scan_directory( $dir, &$file_list = [], $base = '' ) {
        $total = 0;
        if ( ! is_dir( $dir ) ) return 0;
        $items = @scandir( $dir );
        if ( ! $items ) return 0;

        foreach ( $items as $item ) {
            if ( $item === '.' || $item === '..' ) continue;
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if ( is_dir( $path ) ) {
                $total += $this->scan_directory( $path, $file_list, $base );
            } elseif ( is_file( $path ) ) {
                $size = @filesize( $path );
                if ( $size === false ) continue;
                $total += $size;
                $file_list[] = [
                    'path'  => $path,
                    'rel'   => str_replace( $this->get_wp_content_root(), 'wp-content/', $path ),
                    'size'  => $size,
                    'mtime' => filemtime( $path ),
                ];
            }
        }
        return $total;
    }

    private function do_full_scan() {
        $root = $this->get_wp_content_root();
        $folders = [
            'uploads' => $root . 'uploads',
            'plugins' => $root . 'plugins',
            'themes'  => $root . 'themes',
            'cache'   => $root . 'cache',
        ];

        $result = [ 'folders' => [], 'top_files' => [], 'db_size' => $this->get_db_size() ];
        $all_files = [];

        foreach ( $folders as $key => $path ) {
            $files = [];
            $size = $this->scan_directory( $path, $files );
            $result['folders'][ $key ] = [
                'path' => $path,
                'size' => $size,
            ];
            $all_files = array_merge( $all_files, $files );
        }

        usort( $all_files, function( $a, $b ) {
            return $b['size'] <=> $a['size'];
        } );

        $result['top_files'] = array_slice( $all_files, 0, 50 );
        $result['scanned_at'] = current_time( 'mysql' );

        set_transient( self::CACHE_KEY, $result, 30 * MINUTE_IN_SECONDS );
        return $result;
    }

    private function get_db_size() {
        global $wpdb;
        $db = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT SUM(data_length + index_length) AS size
                 FROM information_schema.TABLES
                 WHERE table_schema = %s",
                DB_NAME
            )
        );
        return $db ? (int) $db->size : 0;
    }

    public function ajax_scan() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( '權限不足' );

        $force = ! empty( $_POST['force'] );
        $data = $force ? false : get_transient( self::CACHE_KEY );
        if ( ! $data ) {
            $data = $this->do_full_scan();
        }
        wp_send_json_success( $data );
    }

    /* ---------------- 手動刪除 ---------------- */

    public function ajax_delete() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( '權限不足' );

        $paths = isset( $_POST['paths'] ) ? (array) $_POST['paths'] : [];
        $root = realpath( $this->get_wp_content_root() );
        $deleted = 0;
        $freed = 0;
        $errors = [];

        foreach ( $paths as $path ) {
            $real = realpath( $path );
            if ( ! $real || strpos( $real, $root ) !== 0 ) {
                $errors[] = $path . '(路徑不合法)';
                continue;
            }
            if ( is_file( $real ) ) {
                $size = filesize( $real );
                if ( @unlink( $real ) ) {
                    $deleted++;
                    $freed += $size;
                } else {
                    $errors[] = $path . '(刪除失敗)';
                }
            }
        }

        delete_transient( self::CACHE_KEY );
        wp_send_json_success( [
            'deleted' => $deleted,
            'freed'   => $freed,
            'errors'  => $errors,
        ] );
    }

    /* ---------------- 智能處理 ---------------- */

    public function ajax_smart_clean() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( '權限不足' );

        global $wpdb;
        $report = [];

        // 1. 刪除文章修訂版本
        $revisions = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision'" );
        foreach ( $revisions as $id ) {
            wp_delete_post_revision( $id );
        }
        $report['revisions_deleted'] = count( $revisions );

        // 2. 清空垃圾桶文章/頁面
        $trashed = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'trash'" );
        foreach ( $trashed as $id ) {
            wp_delete_post( $id, true );
        }
        $report['trash_deleted'] = count( $trashed );

        // 3. 清除過期 transients
        $expired = $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '\_transient\_timeout\_%'
             AND option_value < UNIX_TIMESTAMP()"
        );
        $wpdb->query(
            "DELETE t FROM {$wpdb->options} t
             LEFT JOIN {$wpdb->options} t2
               ON t2.option_name = REPLACE(t.option_name, '_transient_', '_transient_timeout_')
             WHERE t.option_name LIKE '\_transient\_%'
               AND t.option_name NOT LIKE '\_transient\_timeout\_%'
               AND t2.option_id IS NULL"
        );
        $report['expired_transients'] = (int) $expired;

        // 4. 找出孤立的 uploads 檔案（只回報，不自動刪）
        $upload_dir = wp_upload_dir();
        $orphans = [];
        $files = [];
        $this->scan_directory( $upload_dir['basedir'], $files );
        foreach ( $files as $f ) {
            $filename = basename( $f['path'] );
            if ( preg_match( '/-\d+x\d+\.(jpg|jpeg|png|gif|webp)$/i', $filename ) ) continue;
            $found = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts} WHERE guid LIKE %s LIMIT 1",
                    '%' . $wpdb->esc_like( $filename )
                )
            );
            if ( ! $found ) {
                $orphans[] = [ 'path' => $f['path'], 'size' => $f['size'] ];
            }
        }
        $report['orphan_candidates'] = $orphans;
        $report['orphan_count'] = count( $orphans );

        // 5. 清理快取目錄
        $cache_dirs = [
            $this->get_wp_content_root() . 'cache',
        ];
        $cache_freed = 0;
        foreach ( $cache_dirs as $dir ) {
            if ( is_dir( $dir ) ) {
                $cache_freed += $this->delete_dir_contents( $dir );
            }
        }
        $report['cache_freed'] = $cache_freed;

        delete_transient( self::CACHE_KEY );
        wp_send_json_success( $report );
    }

    private function delete_dir_contents( $dir ) {
        $freed = 0;
        $items = @scandir( $dir );
        if ( ! $items ) return 0;
        foreach ( $items as $item ) {
            if ( $item === '.' || $item === '..' ) continue;
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if ( is_dir( $path ) ) {
                $freed += $this->delete_dir_contents( $path );
                @rmdir( $path );
            } else {
                $size = @filesize( $path );
                if ( @unlink( $path ) ) $freed += $size;
            }
        }
        return $freed;
    }

    /* ---------------- 後台頁面 ---------------- */

    public function render_page() {
        $nonce = wp_create_nonce( self::NONCE_ACTION );
        ?>
        <div class="wrap">
            <h1>網站磁碟空間分析</h1>
            <p>
                <button id="dsm-scan-btn" class="button button-primary">重新掃描</button>
                <button id="dsm-smart-btn" class="button">智能處理清理</button>
                <span id="dsm-loading" style="display:none;">掃描中，請稍候...</span>
            </p>

            <div id="dsm-summary"></div>

            <h2>各目錄大小</h2>
            <table class="widefat striped">
                <thead><tr><th>目錄</th><th>路徑</th><th>大小</th></tr></thead>
                <tbody id="dsm-folder-table"></tbody>
            </table>

            <h2>佔用空間最大的檔案（Top 50）</h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><input type="checkbox" id="dsm-check-all"></th>
                        <th>檔案路徑</th>
                        <th>大小</th>
                        <th>最後修改</th>
                    </tr>
                </thead>
                <tbody id="dsm-file-table"></tbody>
            </table>
            <p>
                <button id="dsm-delete-btn" class="button button-secondary">刪除已勾選檔案</button>
            </p>

            <div id="dsm-smart-report"></div>
        </div>

        <script>
        (function($){
            const nonce = '<?php echo esc_js( $nonce ); ?>';
            const ajaxUrl = ajaxurl;

            function formatBytes(bytes) {
                if (bytes === 0) return '0 B';
                const units = ['B','KB','MB','GB','TB'];
                const i = Math.floor(Math.log(bytes) / Math.log(1024));
                return (bytes / Math.pow(1024, i)).toFixed(2) + ' ' + units[i];
            }

            function renderData(data) {
                let totalFolder = 0;
                let rows = '';
                const labels = { uploads:'上傳檔案(uploads)', plugins:'外掛(plugins)', themes:'佈景主題(themes)', cache:'快取(cache)' };
                $.each(data.folders, function(key, f) {
                    totalFolder += f.size;
                    rows += `<tr><td>${labels[key] || key}</td><td>${f.path}</td><td>${formatBytes(f.size)}</td></tr>`;
                });
                $('#dsm-folder-table').html(rows);

                $('#dsm-summary').html(
                    `<p><strong>資料庫大小：</strong>${formatBytes(data.db_size)}&emsp;` +
                    `<strong>掃描目錄總計：</strong>${formatBytes(totalFolder)}&emsp;` +
                    `<strong>掃描時間：</strong>${data.scanned_at}</p>`
                );

                let fileRows = '';
                data.top_files.forEach(function(f) {
                    const date = new Date(f.mtime * 1000).toLocaleString();
                    fileRows += `<tr>
                        <td><input type="checkbox" class="dsm-file-check" value="${f.path}"></td>
                        <td>${f.rel}</td>
                        <td>${formatBytes(f.size)}</td>
                        <td>${date}</td>
                    </tr>`;
                });
                $('#dsm-file-table').html(fileRows);
            }

            function doScan(force) {
                $('#dsm-loading').show();
                $.post(ajaxUrl, { action: 'dsm_scan', nonce: nonce, force: force ? 1 : 0 }, function(res) {
                    $('#dsm-loading').hide();
                    if (res.success) {
                        renderData(res.data);
                    } else {
                        alert('掃描失敗：' + res.data);
                    }
                });
            }

            $('#dsm-scan-btn').on('click', function() { doScan(true); });

            $('#dsm-check-all').on('change', function() {
                $('.dsm-file-check').prop('checked', $(this).is(':checked'));
            });

            $('#dsm-delete-btn').on('click', function() {
                const paths = $('.dsm-file-check:checked').map(function(){ return $(this).val(); }).get();
                if (!paths.length) { alert('請先勾選要刪除的檔案'); return; }
                if (!confirm('確定要刪除 ' + paths.length + ' 個檔案嗎？此動作無法復原！')) return;

                $.post(ajaxUrl, { action: 'dsm_delete', nonce: nonce, paths: paths }, function(res) {
                    if (res.success) {
                        alert('已刪除 ' + res.data.deleted + ' 個檔案，釋放 ' + formatBytes(res.data.freed) + '\n' +
                              (res.data.errors.length ? '錯誤：\n' + res.data.errors.join('\n') : ''));
                        doScan(true);
                    } else {
                        alert('刪除失敗：' + res.data);
                    }
                });
            });

            $('#dsm-smart-btn').on('click', function() {
                if (!confirm('智能處理將自動清除：文章修訂版本、垃圾桶內容、過期暫存資料、快取殘留檔案。確定執行？')) return;
                $('#dsm-loading').show();
                $.post(ajaxUrl, { action: 'dsm_smart_clean', nonce: nonce }, function(res) {
                    $('#dsm-loading').hide();
                    if (res.success) {
                        const d = res.data;
                        let orphanList = d.orphan_candidates.slice(0, 20).map(o => `${o.path} (${formatBytes(o.size)})`).join('<br>');
                        $('#dsm-smart-report').html(`
                            <div class="notice notice-success"><p>
                                已刪除修訂版本：${d.revisions_deleted}&emsp;
                                已清空垃圾桶：${d.trash_deleted}&emsp;
                                已清除過期暫存：${d.expired_transients}&emsp;
                                快取釋放空間：${formatBytes(d.cache_freed)}
                            </p></div>
                            <h3>疑似孤立圖片檔案（共 ${d.orphan_count} 個，僅列出前20，未自動刪除，請人工確認後手動刪除）</h3>
                            <p style="max-height:200px;overflow:auto;">${orphanList || '無'}</p>
                        `);
                        doScan(true);
                    } else {
                        alert('處理失敗：' + res.data);
                    }
                });
            });

            doScan(false);
        })(jQuery);
        </script>
        <?php
    }
}

new DSM_Disk_Space_Manager();
