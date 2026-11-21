<?php
defined('ABSPATH') || exit;

final class WUTM_ATM_Consent_Admin {
    const ACTION = 'wutm_atm_save_privacy';
    const TEXT = [
        'title'=>'橫幅標題', 'description'=>'橫幅說明', 'accept'=>'同意按鈕', 'reject'=>'拒絕按鈕',
        'preferences'=>'偏好設定按鈕', 'save'=>'儲存偏好按鈕',
    ];

    public static function boot(): void {
        add_action('admin_post_' . self::ACTION, [__CLASS__, 'save']);
    }

    public static function save(): void {
        if (!current_user_can('manage_options')) wp_die('權限不足。', '', ['response'=>403]);
        check_admin_referer(self::ACTION);
        $s = WUTM_ATM_Consent::settings();
        $enabled = isset($_POST['enabled']);
        if ($enabled && !WUTM_ATM_Consent_Store::install()) {
            wp_die('無法建立 Cookie 同意紀錄表。請檢查資料庫權限後再試。', '', ['response'=>500,'back_link'=>true]);
        }
        if ((bool) $s['enabled'] !== $enabled || empty($s['policy']) || isset($_POST['renew'])) {
            $s['policy'] = bin2hex(random_bytes(16));
        }
        $s['enabled'] = $enabled;
        $layout = self::posted('layout');
        $s['layout'] = isset(WUTM_ATM_Consent::layouts()[$layout]) ? $layout : 'inline';
        $s['appearance'] = self::posted('appearance') === 'dark' ? 'dark' : 'light';
        $s['first_visit'] = self::posted('first_visit') === 'opt_out' ? 'opt_out' : 'opt_in';
        $s['default_analytics'] = isset($_POST['default_analytics']);
        $s['default_marketing'] = isset($_POST['default_marketing']);
        $s['days'] = max(7, min(365, absint(self::posted('days')) ?: 180));
        $s['url'] = esc_url_raw(self::posted('url'), ['http','https']);
        $gtm = self::posted('gtm');
        $s['gtm'] = in_array($gtm, ['both','analytics','marketing'], true) ? $gtm : 'both';
        foreach (self::TEXT as $key=>$label) {
            $value = $key === 'description' ? sanitize_textarea_field(self::posted($key)) : sanitize_text_field(self::posted($key));
            if ($value !== '') $s[$key] = wp_html_excerpt($value, $key === 'description' ? 700 : 80);
        }
        if (update_option(WUTM_ATM_Consent::OPTION, $s, false) && class_exists('WUTM_Page_Cache')) {
            WUTM_Page_Cache::clear_on_global_change();
        }
        do_action('wutm_atm_tracking_settings_updated', WUTM_ATM_Events::settings()['enabled']);
        wp_safe_redirect(add_query_arg(['page'=>'wu-advanced-tracking-manager','atm_tab'=>'privacy','saved'=>1], admin_url('admin.php')));
        exit;
    }

    private static function posted(string $key): string {
        return is_string($_POST[$key] ?? null) ? wp_unslash($_POST[$key]) : '';
    }

    public static function render(): void {
        $s = WUTM_ATM_Consent::settings();
        ?>
        <?php if (($_GET['atm_tab'] ?? '') === 'privacy' && isset($_GET['saved'])) : ?>
            <div class="notice notice-success"><p>已儲存 Cookie 同意設定。若更改橫幅、啟用或停用功能，請清除舊的頁面與 CDN 快取。</p></div>
        <?php endif; ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="atm-card" data-atm-settings>
            <div class="atm-card-body wutm-atm-privacy-admin">
                <h2>Cookie 與隱私同意</h2>
                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">
                <?php wp_nonce_field(self::ACTION); ?>
                <p><label><input type="checkbox" name="enabled" <?php checked($s['enabled']); ?>> 啟用內建 Cookie 與隱私同意</label></p>
                <p>啟用後，本模組會以訪客的站內偏好判斷追蹤。必要功能永遠可用。請關閉其他同意橫幅，並確認其他外掛獨立載入的追蹤標籤已納入同意管理。</p>
                <fieldset class="wutm-atm-privacy-options">
                    <legend>橫幅外觀</legend>
                    <label>顯示方式
                        <select name="layout">
                            <?php foreach (WUTM_ATM_Consent::layouts() as $id=>$label) : ?>
                                <option value="<?php echo esc_attr($id); ?>" <?php selected($s['layout'], $id); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>色系
                        <select name="appearance">
                            <option value="light" <?php selected($s['appearance'], 'light'); ?>>淺色</option>
                            <option value="dark" <?php selected($s['appearance'], 'dark'); ?>>深色</option>
                        </select>
                    </label>
                    <p class="description">「取得同意後啟用」會使用選定的橫幅，購物車、結帳與會員頁改用頁面內橫幅。「預設啟用，可手動拒絕」只顯示左下角的 Cookie 設定。</p>
                </fieldset>
                <fieldset class="wutm-atm-privacy-options">
                    <legend>首次訪問的追蹤方式</legend>
                    <label><select name="first_visit" aria-label="首次訪問的追蹤方式">
                        <option value="opt_in" <?php selected($s['first_visit'], 'opt_in'); ?>>取得同意後啟用</option>
                        <option value="opt_out" <?php selected($s['first_visit'], 'opt_out'); ?>>預設啟用，可手動拒絕</option>
                    </select></label>
                    <p><label><input type="checkbox" name="default_analytics" <?php checked($s['default_analytics']); ?>> 預設啟用分析</label>
                       <label><input type="checkbox" name="default_marketing" <?php checked($s['default_marketing']); ?>> 預設啟用行銷</label></p>
                    <p class="description">新設定預設先取得同意。預設啟用可能不適用所有法規或網站用途，請依網站的實際需求選擇。上方勾選僅用於「預設啟用」模式。此模式可啟用瀏覽器追蹤，CAPI 和 WooCommerce 購買事件仍需取得並儲存訪客的行銷同意。已儲存的偏好會優先套用。</p>
                </fieldset>
                <div class="wutm-atm-event-grid">
                    <?php foreach (self::TEXT as $key=>$label) : ?>
                        <label><?php echo esc_html($label); ?>
                            <?php if ($key === 'description') : ?>
                                <textarea name="description" rows="3" maxlength="700"><?php echo esc_textarea($s[$key]); ?></textarea>
                            <?php else : ?>
                                <input name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($s[$key]); ?>" maxlength="80" required>
                            <?php endif; ?>
                        </label>
                    <?php endforeach; ?>
                    <label>隱私權政策網址<input type="url" name="url" value="<?php echo esc_attr($s['url']); ?>" placeholder="留空使用 WordPress 隱私權政策"></label>
                    <label>同意保存天數<input type="number" name="days" min="7" max="365" value="<?php echo esc_attr($s['days']); ?>" required></label>
                </div>
                <p><label>GTM 容器的同意條件
                    <select name="gtm">
                        <?php foreach (['both'=>'分析及行銷皆允許','analytics'=>'分析（限純分析容器）','marketing'=>'行銷（限純行銷容器）'] as $id=>$label) : ?>
                            <option value="<?php echo esc_attr($id); ?>" <?php selected($s['gtm'], $id); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label></p>
                <p>GA4 依分析偏好載入，Google Ads 和 Meta Pixel 依行銷偏好載入。GTM 內的標籤仍應各自設定同意條件。</p>
                <p><label><input type="checkbox" name="renew"> 使現有偏好失效，下次訪問重新詢問</label></p>
                <p class="description">僅在政策或用途變更時勾選。使用舊同意紀錄的待發行銷事件也會停止。訪客可在網站左下角修改偏好，或在適合的位置加入 <code>[wu_cookie_preferences]</code>。</p>
                <button class="button button-primary">儲存 Cookie 同意設定</button>
            </div>
        </form>
        <?php
    }
}
