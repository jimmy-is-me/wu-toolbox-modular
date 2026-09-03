<?php
/**
 * Module: notice-center
 *
 * Collects ordinary WordPress admin notices into one compact, accessible
 * panel. It intentionally has no advertising, remote requests, or tracking.
 */
defined('ABSPATH') || exit;

final class WUTM_Notice_Center {
    private const SLUG = 'wu-notice-center';
    private const OPTION = 'wutm_notice_center_settings';

    public static function boot(): void {
        add_action('admin_menu', [__CLASS__, 'menu'], 20);
        add_action('admin_post_wutm_notice_center_save', [__CLASS__, 'save']);

        if (!self::is_enabled()) {
            return;
        }

        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
        add_action('in_admin_header', [__CLASS__, 'panel'], 999);
    }

    private static function settings(): array {
        return wp_parse_args((array) get_option(self::OPTION, []), [
            'enabled' => true,
            'open_for_important' => true,
        ]);
    }

    private static function is_enabled(): bool {
        $settings = self::settings();
        return !empty($settings['enabled']);
    }

    public static function menu(): void {
        add_submenu_page(
            'wu-toolbox-modular',
            '通知整理工具設定',
            '通知整理工具',
            'manage_options',
            self::SLUG,
            [__CLASS__, 'page']
        );
    }

    public static function save(): void {
        if (!current_user_can('manage_options')) {
            wp_die('權限不足', 403);
        }

        check_admin_referer('wutm_notice_center_save');
        update_option(self::OPTION, [
            'enabled' => !empty($_POST['enabled']),
            'open_for_important' => !empty($_POST['open_for_important']),
        ], false);

        wp_safe_redirect(add_query_arg([
            'page' => self::SLUG,
            'updated' => '1',
        ], admin_url('admin.php')));
        exit;
    }

    private static function can_collect_notices(): bool {
        if (wp_doing_ajax() || !function_exists('get_current_screen')) {
            return false;
        }

        $screen = get_current_screen();
        if (!$screen) {
            return false;
        }

        // 區塊編輯器、網站編輯器與本模組的設定頁會使用動態通知/連線狀態；
        // 不觸碰其 DOM，避免干擾儲存、Heartbeat 或 REST API 狀態。
        if ((method_exists($screen, 'is_block_editor') && $screen->is_block_editor())
            || in_array($screen->base, ['site-editor', 'widgets'], true)
            || strpos((string) $screen->id, self::SLUG) !== false) {
            return false;
        }

        return true;
    }

    public static function assets(): void {
        if (!self::can_collect_notices()) {
            return;
        }

        $settings = self::settings();
        wp_register_style('wutm-notice-center', false, [], WUTM_VERSION);
        wp_enqueue_style('wutm-notice-center');
        wp_add_inline_style('wutm-notice-center', '
            #wutm-notice-center{display:none;margin:16px 0 12px;max-width:100%;}
            #wutm-notice-center.is-visible{display:block;}
            #wutm-notice-center details{border:1px solid #c3c4c7;border-radius:8px;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.04);}
            #wutm-notice-center summary{display:flex;align-items:center;gap:8px;min-height:44px;padding:0 14px;cursor:pointer;font-weight:600;color:#1d2327;}
            #wutm-notice-center .wutm-notice-count{display:inline-flex;align-items:center;justify-content:center;min-width:22px;height:22px;padding:0 6px;border-radius:11px;background:#2271b1;color:#fff;font-size:12px;}
            #wutm-notice-center .wutm-notice-important{color:#b32d2e;font-size:12px;font-weight:600;}
            #wutm-notice-center .wutm-notice-items{padding:1px 0 12px;}
            #wutm-notice-center .wutm-notice-items>.notice,#wutm-notice-center .wutm-notice-items>.updated,#wutm-notice-center .wutm-notice-items>.error,#wutm-notice-center .wutm-notice-items>.update-nag{display:block;margin:10px 12px 0;}
        ');

        wp_register_script('wutm-notice-center', false, [], WUTM_VERSION, true);
        wp_enqueue_script('wutm-notice-center');
        wp_add_inline_script('wutm-notice-center', 'document.addEventListener("DOMContentLoaded",function(){var panel=document.getElementById("wutm-notice-center");if(!panel)return;var items=panel.querySelector(".wutm-notice-items"),details=panel.querySelector("details"),count=panel.querySelector(".wutm-notice-count"),important=panel.querySelector(".wutm-notice-important"),openForImportant=' . (!empty($settings['open_for_important']) ? 'true' : 'false') . ';var selector="#wpbody-content > .notice:not(.inline):not(#wutm-notice-center),#wpbody-content > .updated,#wpbody-content > .error,#wpbody-content > .update-nag";var found=Array.prototype.slice.call(document.querySelectorAll(selector)).filter(function(node){return !panel.contains(node)&&!node.classList.contains("wutm-notice-center");});found.forEach(function(node){items.appendChild(node);});var all=Array.prototype.slice.call(items.children),total=String(all.length),errors=all.filter(function(node){return node.classList.contains("notice-error")||node.classList.contains("error");}).length,message=errors?"包含 "+errors+" 則重要通知":"";count.textContent=total;important.textContent=message;if(errors&&openForImportant){details.open=true;}panel.classList.toggle("is-visible",all.length>0);});');
    }

    public static function panel(): void {
        if (!self::can_collect_notices()) {
            return;
        }
        ?>
        <section id="wutm-notice-center" class="wutm-notice-center" aria-live="polite">
            <details>
                <summary>
                    <span>通知整理</span>
                    <span class="wutm-notice-count">0</span>
                    <span class="wutm-notice-important"></span>
                </summary>
                <div class="wutm-notice-items"></div>
            </details>
        </section>
        <?php
    }

    public static function page(): void {
        if (!current_user_can('manage_options')) {
            wp_die('權限不足', 403);
        }
        $settings = self::settings();
        ?>
        <div class="wrap wutm-module-wrap wutm-notice-center-settings">
            <h1>通知整理工具設定</h1>
            <p class="wutm-module-subtitle">將後台的系統通知集中至可展開面板，讓工作畫面保持清楚；不會刪除通知內容或變更外掛設定。</p>

            <?php if (!empty($_GET['updated'])) : ?>
                <div class="notice notice-success is-dismissible"><p>通知整理工具設定已儲存。</p></div>
            <?php endif; ?>

            <div class="card" style="max-width:100%;margin:20px 0;">
                <h2 style="margin-top:0;">目前狀態</h2>
                <p><strong><?php echo !empty($settings['enabled']) ? '運作中' : '已暫停'; ?></strong> — 整理只在後台畫面載入完成後執行，不影響網站前台與資料庫。</p>
            </div>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="wutm_notice_center_save">
                <?php wp_nonce_field('wutm_notice_center_save'); ?>
                <table class="form-table" role="presentation"><tbody>
                    <tr>
                        <th scope="row">啟用通知整理</th>
                        <td>
                            <label><input type="checkbox" name="enabled" value="1" <?php checked(!empty($settings['enabled'])); ?>> 將後台一般通知集中到「通知整理」面板</label>
                            <p class="description">通知仍可展開閱讀及使用原本的關閉按鈕；本工具不會攔截或刪除通知。</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">重要通知</th>
                        <td>
                            <label><input type="checkbox" name="open_for_important" value="1" <?php checked(!empty($settings['open_for_important'])); ?>> 發現錯誤通知時自動展開面板</label>
                            <p class="description">建議保持啟用，讓需要處理的錯誤不會被收合。</p>
                        </td>
                    </tr>
                </tbody></table>
                <?php submit_button('儲存設定'); ?>
            </form>

            <div class="card" style="max-width:100%;margin-top:20px;">
                <h2 style="margin-top:0;">使用說明</h2>
                <ul style="list-style:disc;padding-left:22px;">
                    <li>通知內容由 WordPress 或原外掛提供；本工具只調整顯示位置，不會翻譯或改寫第三方通知。</li>
                    <li>外掛更新列、表格中的更新訊息與非標準自訂介面會保留在原位置，避免影響原本操作。</li>
                    <li>通知整理使用瀏覽器端畫面整理，沒有外部請求、不會新增資料庫紀錄，也不會影響網站前台效能。</li>
                </ul>
            </div>
        </div>
        <?php
    }
}

WUTM_Notice_Center::boot();
