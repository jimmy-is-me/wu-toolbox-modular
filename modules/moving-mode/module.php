<?php
/**
 * Module: moving-mode
 *
 * White, responsive maintenance page with a reliable live preview.
 */
defined('ABSPATH') || exit;

final class WU_Moving_Mode {
    private const SLUG = 'wu-moving-mode';
    private const NONCE_ACTION = 'wu_moving_mode_save_action';
    private const NONCE_FIELD = 'wu_moving_mode_nonce';

    public function __construct() {
        if (is_admin()) {
            add_action('admin_menu', [$this, 'add_admin_menu'], 60);
            add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        }

        add_action('template_redirect', [$this, 'check_maintenance_mode'], 1);
        add_action('wp_login_failed', [$this, 'handle_login_failed']);
        add_filter('authenticate', [$this, 'handle_authenticate_error'], 30, 3);

        foreach (['status', 'title', 'message', 'copyright'] as $option) {
            add_action('update_option_wu_moving_mode_' . $option, [$this, 'clear_cache']);
        }
    }

    public function add_admin_menu() {
        add_submenu_page(
            'wu-toolbox-modular',
            '維護模式設定',
            '維護模式設定',
            'manage_options',
            self::SLUG,
            [$this, 'render_settings_page']
        );
    }

    public function enqueue_admin_assets() {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($page !== self::SLUG) return;

        wp_enqueue_style(
            'wu-moving-mode-admin',
            WUTM_URL . 'assets/css/moving-mode.css',
            ['wutm-admin'],
            WUTM_VERSION
        );
        wp_enqueue_script(
            'wu-moving-mode-admin',
            WUTM_URL . 'assets/js/moving-mode.js',
            [],
            WUTM_VERSION,
            true
        );
    }

    private function defaults() {
        return [
            'status' => 'off',
            'title' => '網站維護中',
            'message' => '我們正在為您帶來更好的體驗，請稍後再回來。',
            'copyright' => 'Copyright © ' . wp_date('Y') . ' Wumetax All rights reserved ｜ 網站建置與維護',
        ];
    }

    private function values() {
        $defaults = $this->defaults();
        return [
            'status' => get_option('wu_moving_mode_status', $defaults['status']),
            'title' => get_option('wu_moving_mode_title', $defaults['title']),
            'message' => get_option('wu_moving_mode_message', $defaults['message']),
            'copyright' => get_option('wu_moving_mode_copyright', $defaults['copyright']),
        ];
    }

    private function save_settings() {
        if (!current_user_can('manage_options')) wp_die('權限不足', 403);
        check_admin_referer(self::NONCE_ACTION, self::NONCE_FIELD);

        $defaults = $this->defaults();
        $status = isset($_POST['wu_moving_mode_status'])
            ? sanitize_key(wp_unslash($_POST['wu_moving_mode_status']))
            : 'off';
        if (!in_array($status, ['on', 'off'], true)) $status = 'off';

        $title = isset($_POST['wu_moving_mode_title'])
            ? sanitize_text_field(wp_unslash($_POST['wu_moving_mode_title']))
            : '';
        $message = isset($_POST['wu_moving_mode_message'])
            ? sanitize_textarea_field(wp_unslash($_POST['wu_moving_mode_message']))
            : '';
        $copyright = isset($_POST['wu_moving_mode_copyright'])
            ? wp_kses_post(wp_unslash($_POST['wu_moving_mode_copyright']))
            : '';

        update_option('wu_moving_mode_status', $status);
        update_option('wu_moving_mode_title', $title !== '' ? $title : $defaults['title']);
        update_option('wu_moving_mode_message', $message !== '' ? $message : $defaults['message']);
        update_option('wu_moving_mode_copyright', $copyright !== '' ? $copyright : $defaults['copyright']);
        $this->clear_cache();
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) wp_die('權限不足', 403);

        $saved = false;
        if (isset($_POST['wu_moving_mode_save'])) {
            $this->save_settings();
            $saved = true;
        }
        $values = $this->values();
        ?>
        <div class="wrap wutm-module-wrap wu-moving-admin">
            <h1>
                維護模式設定
                <span class="wu-moving-status is-<?php echo esc_attr($values['status']); ?>">
                    <span aria-hidden="true"></span>
                    <?php echo $values['status'] === 'on' ? '運作中' : '已關閉'; ?>
                </span>
            </h1>
            <p class="wutm-module-subtitle">使用簡潔的白色維護頁暫時接待訪客，管理員登入後仍可正常瀏覽網站。</p>

            <?php if ($saved) : ?>
                <div class="notice notice-success is-dismissible"><p>維護模式設定已儲存，相關快取已清除。</p></div>
            <?php endif; ?>

            <div class="wu-moving-settings-wrap">
                <form method="post" class="wu-moving-form">
                    <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>

                    <section class="wu-moving-section">
                        <h2>功能開關</h2>
                        <div class="wu-moving-mode-options">
                            <label>
                                <input type="radio" name="wu_moving_mode_status" value="on" <?php checked($values['status'], 'on'); ?>>
                                <span><strong>開啟維護模式</strong><small>訪客顯示維護頁，具管理權限的登入者可正常瀏覽。</small></span>
                            </label>
                            <label>
                                <input type="radio" name="wu_moving_mode_status" value="off" <?php checked($values['status'], 'off'); ?>>
                                <span><strong>關閉維護模式</strong><small>網站內容正常顯示給所有訪客。</small></span>
                            </label>
                        </div>
                    </section>

                    <section class="wu-moving-section">
                        <h2>維護頁面內容</h2>
                        <label class="wu-moving-field" for="wu_moving_mode_title">
                            <span>標題</span>
                            <input type="text" id="wu_moving_mode_title" name="wu_moving_mode_title"
                                value="<?php echo esc_attr($values['title']); ?>" class="regular-text">
                        </label>

                        <label class="wu-moving-field" for="wu_moving_mode_message">
                            <span>描述</span>
                            <textarea id="wu_moving_mode_message" name="wu_moving_mode_message"
                                rows="4" class="large-text"><?php echo esc_textarea($values['message']); ?></textarea>
                        </label>

                        <label class="wu-moving-field" for="wu_moving_mode_copyright">
                            <span>頁尾文字</span>
                            <textarea id="wu_moving_mode_copyright" name="wu_moving_mode_copyright"
                                rows="3" class="large-text"><?php echo esc_textarea($values['copyright']); ?></textarea>
                            <small>可使用基本連結、粗體與斜體 HTML；即時預覽會以純文字安全呈現。</small>
                        </label>
                    </section>

                    <p class="submit">
                        <input type="submit" name="wu_moving_mode_save" class="button button-primary" value="儲存設定">
                    </p>
                </form>

                <aside class="wu-moving-preview">
                    <div class="wu-moving-preview-heading">
                        <h2>即時預覽</h2>
                        <span>輸入時立即更新</span>
                    </div>
                    <div class="wu-preview-stage">
                        <div class="wu-preview-orb wu-preview-orb-one"></div>
                        <div class="wu-preview-orb wu-preview-orb-two"></div>
                        <div class="wu-preview-card">
                            <div class="wu-preview-badge"><i></i> 系統維護中</div>
                            <h3 id="preview-title"><?php echo esc_html($values['title']); ?></h3>
                            <p id="preview-message"><?php echo nl2br(esc_html($values['message'])); ?></p>
                            <div class="wu-preview-code" aria-hidden="true">
                                <span></span><span></span><span></span><span></span>
                            </div>
                            <div class="wu-preview-progress"><i></i></div>
                            <button type="button" tabindex="-1">管理員登入</button>
                            <div id="preview-copyright" class="wu-preview-copyright">
                                <?php echo esc_html(wp_strip_all_tags($values['copyright'])); ?>
                            </div>
                        </div>
                    </div>
                </aside>
            </div>
        </div>
        <?php
    }

    public function check_maintenance_mode() {
        if (current_user_can('manage_options')) return;
        if (get_option('wu_moving_mode_status', 'off') !== 'on') return;
        if ($this->is_excluded_request()) return;
        $this->render_maintenance_page();
    }

    private function is_excluded_request() {
        if ((defined('REST_REQUEST') && REST_REQUEST)
            || (function_exists('wp_doing_ajax') && wp_doing_ajax())
            || (defined('DOING_AJAX') && DOING_AJAX)
            || (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST)
            || (defined('DOING_CRON') && DOING_CRON)) {
            return true;
        }

        $request_uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '';
        $path = (string) wp_parse_url($request_uri, PHP_URL_PATH);
        foreach (['/wp-json/', '/wp-admin/admin-ajax.php', '/wp-cron.php', '/xmlrpc.php', '/wp-login.php'] as $excluded) {
            if (strpos($path, $excluded) !== false) return true;
        }

        $hide_options = (array) get_option('wu_hide_login_page_options', []);
        if (!empty($hide_options['enabled']) && !empty($hide_options['custom_slug'])) {
            $login_path = '/' . trim(sanitize_title($hide_options['custom_slug']), '/') . '/';
            if (trailingslashit($path) === $login_path) return true;
        }
        return false;
    }

    public function handle_login_failed() {
        if (get_option('wu_moving_mode_status', 'off') !== 'on') return;
        $this->set_login_error_cookie(true);
        wp_safe_redirect(home_url('/'));
        exit;
    }

    public function handle_authenticate_error($user) {
        if (get_option('wu_moving_mode_status', 'off') === 'on' && is_wp_error($user)) {
            $this->set_login_error_cookie(true);
        }
        return $user;
    }

    private function set_login_error_cookie($enabled) {
        if (headers_sent()) return;
        setcookie(
            'wu_login_error',
            $enabled ? '1' : '',
            $enabled ? time() + 300 : time() - HOUR_IN_SECONDS,
            COOKIEPATH ?: '/',
            COOKIE_DOMAIN,
            is_ssl(),
            true
        );
    }

    private function render_maintenance_page() {
        if (get_option('wu_moving_mode_status', 'off') !== 'on') return;

        $values = $this->values();
        $has_login_error = isset($_COOKIE['wu_login_error']) && $_COOKIE['wu_login_error'] === '1';
        if ($has_login_error) $this->set_login_error_cookie(false);

        nocache_headers();
        status_header(503);
        header('Retry-After: 3600');
        $login_url = wp_login_url(home_url('/'));
        ?>
        <!doctype html>
        <html lang="zh-Hant">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width,initial-scale=1">
            <meta name="robots" content="noindex,nofollow">
            <title><?php echo esc_html($values['title']); ?></title>
            <style>
            :root{--wu-bg:#f4f7fb;--wu-card:#fff;--wu-border:#dce3ec;--wu-text:#172033;--wu-muted:#647086;--wu-accent:#2271b1;--wu-soft:#eef6fc}
            *{box-sizing:border-box}html,body{min-height:100%;margin:0}body{display:grid;place-items:center;padding:32px 20px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:var(--wu-text);background:var(--wu-bg);overflow-x:hidden}
            .wu-bg{position:fixed;inset:0;pointer-events:none;overflow:hidden}.wu-orb{position:absolute;border-radius:50%;filter:blur(75px);opacity:.2}.wu-orb:first-child{width:420px;height:420px;right:-130px;top:-150px;background:#7db9e8}.wu-orb:last-child{width:340px;height:340px;left:-120px;bottom:-150px;background:#a7d8c5}
            .wu-maintenance{position:relative;width:min(100%,560px);padding:clamp(34px,7vw,64px);text-align:center;background:rgba(255,255,255,.96);border:1px solid var(--wu-border);border-radius:24px;box-shadow:0 18px 55px rgba(42,57,82,.13)}
            .wu-badge{display:inline-flex;align-items:center;gap:9px;margin-bottom:24px;padding:8px 15px;color:var(--wu-accent);background:var(--wu-soft);border:1px solid #c9e1f2;border-radius:999px;font-size:13px;font-weight:700;letter-spacing:.05em}.wu-badge i{width:8px;height:8px;border-radius:50%;background:var(--wu-accent);animation:wu-pulse 2.4s ease-in-out infinite}
            h1{margin:0 0 16px;font-size:clamp(34px,7vw,58px);line-height:1.12;letter-spacing:-.035em}p{max-width:42ch;margin:0 auto;color:var(--wu-muted);font-size:clamp(16px,2.5vw,18px);line-height:1.75;white-space:pre-line}
            .wu-code{display:grid;gap:8px;width:220px;margin:30px auto 24px;padding:18px 20px;background:#f8fafc;border:1px solid var(--wu-border);border-radius:14px}.wu-code span{display:block;height:7px;border-radius:4px;background:#dce5ee;transform-origin:left;animation:wu-code 4.8s ease-in-out infinite}.wu-code span:nth-child(1){width:82%}.wu-code span:nth-child(2){width:56%;animation-delay:.35s}.wu-code span:nth-child(3){width:92%;animation-delay:.7s}.wu-code span:nth-child(4){width:68%;animation-delay:1.05s}
            .wu-progress{height:5px;margin:0 auto 28px;overflow:hidden;background:#e8edf3;border-radius:999px}.wu-progress i{display:block;width:42%;height:100%;background:linear-gradient(90deg,transparent,var(--wu-accent),transparent);animation:wu-progress 2.2s ease-in-out infinite}
            #login-btn{padding:11px 18px;color:#fff;background:var(--wu-accent);border:1px solid #135e96;border-radius:8px;font-weight:700;cursor:pointer}.wu-copyright{margin-top:28px;padding-top:18px;border-top:1px solid var(--wu-border);color:#8a94a6;font-size:12px;line-height:1.6}.wu-copyright a{color:var(--wu-accent)}
            #wu-backdrop{display:none;position:fixed;inset:0;z-index:10;background:rgba(22,32,51,.38);backdrop-filter:blur(5px)}#wu-login{display:none;position:fixed;z-index:11;left:50%;top:50%;width:min(calc(100% - 32px),380px);padding:30px;transform:translate(-50%,-50%);background:#fff;border:1px solid var(--wu-border);border-radius:16px;box-shadow:0 22px 70px rgba(22,32,51,.25);text-align:left}#wu-login h2{margin:0 0 20px;text-align:center}#wu-login label{display:block;margin:12px 0 5px;font-size:13px;font-weight:600}#wu-login input{width:100%;padding:11px 12px;color:var(--wu-text);background:#fff;border:1px solid #b8c2cf;border-radius:7px;font-size:15px}#wu-login input:focus{outline:2px solid #72aee6;outline-offset:1px;border-color:var(--wu-accent)}#wu-login button[type=submit]{width:100%;margin-top:20px;padding:11px;color:#fff;background:var(--wu-accent);border:0;border-radius:7px;font-weight:700;cursor:pointer}.wu-close{position:absolute;right:14px;top:12px;padding:4px 7px;background:transparent;border:0;color:#6b7280;font-size:20px;cursor:pointer}.wu-error{display:none;margin:0 0 14px;padding:10px 12px;color:#8a2424;background:#fcf0f1;border-left:4px solid #d63638;border-radius:5px;font-size:13px}.wu-error.show{display:block}
            @keyframes wu-pulse{50%{opacity:.45;box-shadow:0 0 0 7px rgba(34,113,177,.12)}}@keyframes wu-code{0%,100%{transform:scaleX(.12);opacity:.35}45%,70%{transform:scaleX(1);opacity:1}}@keyframes wu-progress{from{transform:translateX(-120%)}to{transform:translateX(340%)}}@media(max-width:520px){body{padding:18px 14px}.wu-maintenance{padding:34px 22px;border-radius:18px}.wu-code{width:190px}}@media(prefers-reduced-motion:reduce){*{animation:none!important;scroll-behavior:auto!important}}
            </style>
        </head>
        <body>
            <div class="wu-bg" aria-hidden="true"><span class="wu-orb"></span><span class="wu-orb"></span></div>
            <main class="wu-maintenance">
                <div class="wu-badge"><i></i> 系統維護中</div>
                <h1><?php echo esc_html($values['title']); ?></h1>
                <p><?php echo esc_html($values['message']); ?></p>
                <div class="wu-code" aria-hidden="true"><span></span><span></span><span></span><span></span></div>
                <div class="wu-progress" role="progressbar" aria-label="維護作業進行中"><i></i></div>
                <button type="button" id="login-btn">管理員登入</button>
                <div class="wu-copyright"><?php echo wp_kses_post($values['copyright']); ?></div>
            </main>

            <div id="wu-backdrop"></div>
            <section id="wu-login" role="dialog" aria-modal="true" aria-labelledby="wu-login-title">
                <button type="button" class="wu-close" aria-label="關閉">×</button>
                <h2 id="wu-login-title">管理員登入</h2>
                <div id="wu-error" class="wu-error <?php echo $has_login_error ? 'show' : ''; ?>">
                    帳號或密碼錯誤，請重新輸入。
                </div>
                <form action="<?php echo esc_url($login_url); ?>" method="post">
                    <label for="user_login">帳號或電子郵件</label>
                    <input type="text" name="log" id="user_login" autocomplete="username" required>
                    <label for="user_pass">密碼</label>
                    <input type="password" name="pwd" id="user_pass" autocomplete="current-password" required>
                    <input type="hidden" name="redirect_to" value="<?php echo esc_url(home_url('/')); ?>">
                    <button type="submit">登入</button>
                </form>
            </section>
            <script>
            (function(){var open=document.getElementById('login-btn'),box=document.getElementById('wu-login'),backdrop=document.getElementById('wu-backdrop'),close=box.querySelector('.wu-close'),field=document.getElementById('user_login');function show(){box.style.display='block';backdrop.style.display='block';field.focus()}function hide(){box.style.display='none';backdrop.style.display='none'}open.addEventListener('click',show);close.addEventListener('click',hide);backdrop.addEventListener('click',hide);document.addEventListener('keydown',function(e){if(e.key==='Escape')hide()});<?php if ($has_login_error) : ?>show();<?php endif; ?>})();
            </script>
        </body>
        </html>
        <?php
        exit;
    }

    public function clear_cache() {
        if (function_exists('rocket_clean_domain')) rocket_clean_domain();
        if (function_exists('w3tc_flush_all')) w3tc_flush_all();
        if (function_exists('wp_cache_clear_cache')) wp_cache_clear_cache();
        if (class_exists('LiteSpeed_Cache_API')) LiteSpeed_Cache_API::purge_all();
        wp_cache_flush();
    }
}

new WU_Moving_Mode();
