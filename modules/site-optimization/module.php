<?php
/**
 * Module: site-optimization
 *
 * Optional, narrowly scoped compatibility fixes for WordPress administration.
 */
defined('ABSPATH') || exit;

final class WUTM_Site_Optimization {
    private const SLUG = 'wu-site-optimization';
    private const OPTION = 'wutm_site_optimization_settings';

    public static function boot(): void {
        add_action('admin_menu', [__CLASS__, 'menu'], 20);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
        add_action('admin_post_wutm_site_optimization_save', [__CLASS__, 'save']);

        if (self::is_enabled('fix_wc_default_product_cat')) {
            add_action('admin_footer-edit-tags.php', [__CLASS__, 'fix_wc_default_product_cat_row'], 9999);
        }
    }

    private static function settings(): array {
        return wp_parse_args((array) get_option(self::OPTION, []), [
            'fix_wc_default_product_cat' => false,
        ]);
    }

    private static function is_enabled(string $key): bool {
        $settings = self::settings();
        return !empty($settings[$key]);
    }

    public static function menu(): void {
        add_submenu_page(
            'wu-toolbox-modular',
            '網站優化設定',
            '網站優化',
            'manage_options',
            self::SLUG,
            [__CLASS__, 'page']
        );
    }

    public static function assets(): void {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($page !== self::SLUG) return;

        wp_enqueue_style(
            'wutm-site-optimization',
            WUTM_URL . 'assets/css/site-optimization.css',
            ['wutm-admin'],
            WUTM_VERSION
        );
    }

    public static function save(): void {
        if (!current_user_can('manage_options')) {
            wp_die('權限不足', 403);
        }
        check_admin_referer('wutm_site_optimization_save');

        update_option(self::OPTION, [
            'fix_wc_default_product_cat' => !empty($_POST['fix_wc_default_product_cat']),
        ], false);

        wp_safe_redirect(add_query_arg([
            'page' => self::SLUG,
            'updated' => '1',
        ], admin_url('admin.php')));
        exit;
    }

    public static function page(): void {
        if (!current_user_can('manage_options')) {
            wp_die('權限不足', 403);
        }

        $settings = self::settings();
        $woocommerce_available = class_exists('WooCommerce') || defined('WC_VERSION');
        ?>
        <div class="wrap wutm-module-wrap wutm-site-optimization">
            <h1>網站優化設定</h1>
            <p class="wutm-module-subtitle">依需要開啟個別修復功能；未啟用的功能不會執行。</p>

            <?php if (!empty($_GET['updated'])) : ?>
                <div class="notice notice-success is-dismissible"><p>網站優化設定已儲存。</p></div>
            <?php endif; ?>

            <section class="wutm-so-summary" aria-label="功能狀態">
                <div>
                    <strong>目前提供 1 項修復功能</strong>
                    <span>只會在符合條件的 WordPress 後台頁面執行。</span>
                </div>
                <span class="wutm-so-status <?php echo !empty($settings['fix_wc_default_product_cat']) ? 'is-on' : 'is-off'; ?>">
                    <?php echo !empty($settings['fix_wc_default_product_cat']) ? '運作中' : '未啟用'; ?>
                </span>
            </section>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="wutm_site_optimization_save">
                <?php wp_nonce_field('wutm_site_optimization_save'); ?>

                <section class="wutm-so-panel">
                    <div class="wutm-so-panel-heading">
                        <div>
                            <h2>預設商品分類後台修復</h2>
                            <p>修復 WooCommerce 預設商品分類名稱，以及「編輯／快速編輯／檢視」操作消失的情況。</p>
                        </div>
                        <span class="wutm-so-dependency <?php echo $woocommerce_available ? 'is-ready' : 'is-missing'; ?>">
                            <?php echo $woocommerce_available ? 'WooCommerce 已就緒' : '未偵測到 WooCommerce'; ?>
                        </span>
                    </div>

                    <label class="wutm-so-choice">
                        <input type="checkbox" name="fix_wc_default_product_cat" value="1"
                            <?php checked(!empty($settings['fix_wc_default_product_cat'])); ?>
                            <?php disabled(!$woocommerce_available); ?>>
                        <span>
                            <strong>啟用預設商品分類後台修復</strong>
                            <small>僅在「商品 → 分類」頁面載入；正常顯示時不修改畫面內容。</small>
                        </span>
                    </label>

                    <ul class="wutm-so-details">
                        <li>只處理 WooCommerce 設定的預設商品分類，不修改其他分類。</li>
                        <li>只在 WordPress 後台執行，不影響前台、商品頁、購物車或結帳。</li>
                        <li>只有名稱或列操作真的遺失時才修復，不覆蓋正常的 WordPress 輸出。</li>
                    </ul>
                </section>

                <?php submit_button('儲存設定'); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Restore the default WooCommerce product category row only when its
     * native name/actions markup is unexpectedly missing.
     */
    public static function fix_wc_default_product_cat_row(): void {
        if (!is_admin() || !current_user_can('manage_product_terms')) return;
        if (!function_exists('get_current_screen')) return;

        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'edit-product_cat' || $screen->taxonomy !== 'product_cat') return;

        $term_id = absint(get_option('default_product_cat'));
        if (!$term_id) return;

        $term = get_term($term_id, 'product_cat');
        if (!$term || is_wp_error($term)) return;

        $edit_link = get_edit_term_link($term_id, 'product_cat', 'product');
        if (!$edit_link) return;

        $view_link = get_term_link($term_id, 'product_cat');
        if (is_wp_error($view_link)) $view_link = '';

        $term_name = wp_strip_all_tags($term->name);
        ?>
        <script>
        (function($) {
            'use strict';

            $(function() {
                var $row = $('#tag-<?php echo (int) $term_id; ?>');
                if (!$row.length) return;

                var $cell = $row.find('> .column-name');
                if (!$cell.length || $cell.find('strong > a').length) return;

                var $helpTip = $cell.find('.woocommerce-help-tip').detach();
                var $strong = $('<strong>');
                var $actions = $('<div>', { class: 'row-actions' });

                $cell.empty();
                $('<a>', {
                    href: <?php echo wp_json_encode(esc_url($edit_link)); ?>,
                    text: <?php echo wp_json_encode($term_name); ?>
                }).appendTo($strong);
                $cell.append($strong);

                if ($helpTip.length) {
                    $cell.append(' ').append($helpTip);
                }

                $('<span>', { class: 'edit' })
                    .append($('<a>', {
                        href: <?php echo wp_json_encode(esc_url($edit_link)); ?>,
                        text: '編輯'
                    }))
                    .appendTo($actions);

                $actions.append(' | ');

                $('<span>', { class: 'inline hide-if-no-js' })
                    .append($('<button>', {
                        type: 'button',
                        class: 'button-link editinline',
                        text: '快速編輯'
                    }))
                    .appendTo($actions);

                <?php if (!empty($view_link)) : ?>
                $actions.append(' | ');
                $('<span>', { class: 'view' })
                    .append($('<a>', {
                        href: <?php echo wp_json_encode(esc_url($view_link)); ?>,
                        text: '檢視',
                        target: '_blank',
                        rel: 'noopener'
                    }))
                    .appendTo($actions);
                <?php endif; ?>

                $cell.append($actions);
            });
        })(jQuery);
        </script>
        <?php
    }
}

WUTM_Site_Optimization::boot();
