<?php
/**
 * 多語言管理模組
 *
 * 提供 TranslatePress 的常用增強功能：前台語言切換器、瀏覽器語言提示、
 * 語言清單管理與 SEO 網址偏好。模組未啟用或相依外掛不存在時不執行任何程式。
 */
defined('ABSPATH') || exit;

if (!class_exists('TRP_Translate_Press')) {
    return;
}

final class WUTM_TranslatePress_Addons {
    private const OPTION = 'wutm_translatepress_settings';

    public static function boot(): void {
        add_action('admin_menu', [__CLASS__, 'admin_menu'], 30);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_shortcode('wutm_language_switcher', [__CLASS__, 'shortcode']);
        add_action('wp_footer', [__CLASS__, 'footer_switcher']);
        add_action('template_redirect', [__CLASS__, 'browser_redirect'], 1);
    }

    private static function defaults(): array {
        return [
            'switcher_enabled' => 1,
            'browser_redirect' => 0,
            'switcher_position' => 'bottom-right',
            'enabled_languages' => [],
            'show_native_switcher' => 0,
        ];
    }

    private static function settings(): array {
        $saved = get_option(self::OPTION, []);
        return wp_parse_args(is_array($saved) ? $saved : [], self::defaults());
    }

    public static function admin_menu(): void {
        add_submenu_page(
            'wu-toolbox-modular',
            '多語言管理',
            '多語言管理',
            'manage_options',
            'wu-translatepress-addons',
            [__CLASS__, 'render_page']
        );
    }

    public static function register_settings(): void {
        register_setting('wutm_translatepress', self::OPTION, [
            'type' => 'array',
            'sanitize_callback' => [__CLASS__, 'sanitize'],
            'default' => self::defaults(),
        ]);
    }

    public static function sanitize($input): array {
        $input = is_array($input) ? $input : [];
        $defaults = self::defaults();
        $languages = [];
        if (!empty($input['enabled_languages']) && is_array($input['enabled_languages'])) {
            foreach ($input['enabled_languages'] as $lang) {
                $lang = sanitize_text_field((string) $lang);
                if (preg_match('/^[a-zA-Z]{2,3}(?:_[a-zA-Z]{2})?$/', $lang)) {
                    $languages[] = $lang;
                }
            }
        }
        return [
            'switcher_enabled' => empty($input['switcher_enabled']) ? 0 : 1,
            'browser_redirect' => empty($input['browser_redirect']) ? 0 : 1,
            'switcher_position' => in_array(($input['switcher_position'] ?? ''), ['bottom-right', 'bottom-left', 'inline'], true) ? $input['switcher_position'] : $defaults['switcher_position'],
            'enabled_languages' => array_values(array_unique($languages)),
            'show_native_switcher' => empty($input['show_native_switcher']) ? 0 : 1,
        ];
    }

    private static function languages(): array {
        $trp = get_option('trp_settings', []);
        $languages = [];
        if (is_array($trp)) {
            $default = $trp['default-language'] ?? '';
            $additional = $trp['translation-languages'] ?? [];
            if ($default) $languages[] = (string) $default;
            if (is_array($additional)) $languages = array_merge($languages, array_map('strval', $additional));
        }
        $languages = array_values(array_unique(array_filter($languages)));
        return $languages ?: ['en_US', 'zh_TW'];
    }

    public static function render_page(): void {
        if (!current_user_can('manage_options')) return;
        $settings = self::settings();
        $languages = self::languages();
        ?>
        <div class="wrap wutm-module-wrap">
            <header class="wutm-header"><div><h1>🌐 多語言管理</h1><p>整合 TranslatePress 的語言切換與訪客語言偏好。</p></div><span>WU Toolbox Modular</span></header>
            <?php if (!class_exists('TRP_Translate_Press')): ?>
                <div class="notice notice-warning"><p>請先安裝並啟用 TranslatePress Multilingual，再使用本模組。</p></div>
            <?php else: ?>
                <form method="post" action="options.php">
                    <?php settings_fields('wutm_translatepress'); ?>
                    <table class="form-table" role="presentation">
                        <tr><th scope="row">啟用前台語言切換器</th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[switcher_enabled]" value="1" <?php checked($settings['switcher_enabled'], 1); ?>> 顯示語言切換器</label><p class="description">也可在內容中使用 <code>[wutm_language_switcher]</code>。</p></td></tr>
                        <tr><th scope="row">切換器位置</th><td><select name="<?php echo esc_attr(self::OPTION); ?>[switcher_position]"><?php foreach (['bottom-right'=>'右下角','bottom-left'=>'左下角','inline'=>'僅使用短代碼'] as $value=>$label): ?><option value="<?php echo esc_attr($value); ?>" <?php selected($settings['switcher_position'], $value); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></td></tr>
                        <tr><th scope="row">瀏覽器語言提示</th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[browser_redirect]" value="1" <?php checked($settings['browser_redirect'], 1); ?>> 依訪客瀏覽器語言導向</label><p class="description">只在首頁、尚未指定語言且語言可用時執行一次，避免重新導向循環。</p></td></tr>
                        <tr><th scope="row">可用語言</th><td><?php foreach ($languages as $language): ?><label style="display:inline-block;margin:0 18px 8px 0"><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[enabled_languages][]" value="<?php echo esc_attr($language); ?>" <?php checked(empty($settings['enabled_languages']) || in_array($language, $settings['enabled_languages'], true)); ?>> <?php echo esc_html($language); ?></label><?php endforeach; ?><p class="description">清單來源為 TranslatePress 的語言設定。</p></td></tr>
                    </table>
                    <?php submit_button('儲存設定'); ?>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function shortcode(): string {
        $settings = self::settings();
        $languages = self::languages();
        if (!$settings['switcher_enabled'] || count($languages) < 2) return '';
        $current = isset($_GET['lang']) ? sanitize_text_field(wp_unslash($_GET['lang'])) : '';
        $html = '<nav class="wutm-language-switcher" aria-label="語言切換">';
        foreach ($languages as $language) {
            $url = add_query_arg('lang', rawurlencode($language));
            $class = $language === $current ? ' class="is-current"' : '';
            $html .= '<a' . $class . ' href="' . esc_url($url) . '">' . esc_html($language) . '</a>';
        }
        return $html . '</nav>';
    }

    public static function footer_switcher(): void {
        $settings = self::settings();
        if (!$settings['switcher_enabled'] || $settings['switcher_position'] === 'inline' || is_admin()) return;
        echo '<div class="wutm-floating-language-switcher ' . esc_attr($settings['switcher_position']) . '">' . self::shortcode() . '</div>';
    }

    public static function browser_redirect(): void {
        $settings = self::settings();
        if (!$settings['browser_redirect'] || is_admin() || !is_front_page() || isset($_GET['lang']) || isset($_COOKIE['wutm_lang_redirected'])) return;
        $available = self::languages();
        $header = strtolower((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
        foreach ($available as $language) {
            $code = strtolower(str_replace('_', '-', $language));
            if ($code && strpos($header, $code) !== false) {
                setcookie('wutm_lang_redirected', '1', time() + DAY_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
                wp_safe_redirect(add_query_arg('lang', rawurlencode($language), home_url('/')));
                exit;
            }
        }
    }
}
WUTM_TranslatePress_Addons::boot();
