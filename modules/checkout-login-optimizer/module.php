<?php
/** WooCommerce 結帳登入介面與帳號設定指引。 */
defined('ABSPATH') || exit;

final class WUTM_Checkout_Login_Optimizer {
    private const PAGE = 'wu-checkout-login-optimizer';
    private const OPTION = 'wutm_checkout_login_optimizer_options';
    private $options;

    public function __construct() {
        if (!class_exists('WooCommerce')) return;

        $this->options = wp_parse_args((array) get_option(self::OPTION, array()), array(
            'expand_login' => true,
            'login_message' => '已有會員帳號？請直接輸入帳號密碼登入',
            'required_message' => '完成結帳前請先登入會員帳號。',
            'password_label' => '設定會員密碼',
            'password_placeholder' => '請設定您的登入密碼',
            'admin_hints' => true,
        ));

        add_action('admin_menu', array($this, 'menu'));
        add_action('admin_init', array($this, 'settings'));
        add_action('admin_footer', array($this, 'account_setting_hints'));
        add_action('wp_enqueue_scripts', array($this, 'styles'));
        add_filter('woocommerce_checkout_login_message', array($this, 'login_message'));
        add_filter('woocommerce_checkout_must_be_logged_in_message', array($this, 'must_login_message'));
        add_filter('woocommerce_checkout_fields', array($this, 'checkout_fields'));
    }

    public function menu(): void {
        add_submenu_page(
            'wu-toolbox-modular',
            '結帳登入優化',
            '結帳登入優化',
            'manage_woocommerce',
            self::PAGE,
            array($this, 'settings_page')
        );
    }

    public function settings(): void {
        register_setting('wutm_checkout_login_optimizer_group', self::OPTION, array(
            'sanitize_callback' => array($this, 'sanitize'),
        ));
    }

    public function sanitize($input): array {
        $input = is_array($input) ? $input : array();
        return array(
            'expand_login' => !empty($input['expand_login']),
            'login_message' => sanitize_text_field(wp_unslash($input['login_message'] ?? '')),
            'required_message' => sanitize_text_field(wp_unslash($input['required_message'] ?? '')),
            'password_label' => sanitize_text_field(wp_unslash($input['password_label'] ?? '')),
            'password_placeholder' => sanitize_text_field(wp_unslash($input['password_placeholder'] ?? '')),
            'admin_hints' => !empty($input['admin_hints']),
        );
    }

    public function settings_page(): void {
        if (!current_user_can('manage_woocommerce')) wp_die('您沒有管理結帳登入優化的權限。');
        $o = $this->options;
        $account_url = admin_url('admin.php?page=wc-settings&tab=account');
        ?>
        <div class="wrap wutm-clo-admin">
            <div class="wutm-clo-hero"><h1>🔐 結帳登入優化</h1><p>讓傳統 WooCommerce 結帳頁的會員登入介面更清楚，並在相關設定旁提供中文指引。</p></div>
            <form method="post" action="options.php">
                <?php settings_fields('wutm_checkout_login_optimizer_group'); ?>
                <section class="wutm-clo-card">
                    <h2>前台登入介面</h2>
                    <table class="form-table">
                        <tr><th>展開登入表單</th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[expand_login]" value="1" <?php checked(!empty($o['expand_login'])); ?>> 結帳頁直接展開 WooCommerce 原生登入表單</label><p class="description">只調整顯示方式，帳號密碼、Nonce 與登入流程仍由 WooCommerce 處理。</p></td></tr>
                        <tr><th><label for="wutm-clo-login-message">登入標題</label></th><td><input id="wutm-clo-login-message" type="text" class="large-text" name="<?php echo esc_attr(self::OPTION); ?>[login_message]" value="<?php echo esc_attr($o['login_message']); ?>"></td></tr>
                        <tr><th><label for="wutm-clo-required-message">禁止訪客結帳提示</label></th><td><input id="wutm-clo-required-message" type="text" class="large-text" name="<?php echo esc_attr(self::OPTION); ?>[required_message]" value="<?php echo esc_attr($o['required_message']); ?>"><p class="description">不會移除必要提示，並會保留可用的登入按鈕，避免結帳頁變成空白。</p></td></tr>
                        <tr><th>建立帳號密碼欄位</th><td><label for="wutm-clo-password-label">欄位名稱</label><br><input id="wutm-clo-password-label" type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[password_label]" value="<?php echo esc_attr($o['password_label']); ?>"><br><label for="wutm-clo-password-placeholder">提示文字</label><br><input id="wutm-clo-password-placeholder" type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[password_placeholder]" value="<?php echo esc_attr($o['password_placeholder']); ?>"></td></tr>
                    </table>
                </section>
                <section class="wutm-clo-card">
                    <h2>後台設定指引</h2>
                    <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[admin_hints]" value="1" <?php checked(!empty($o['admin_hints'])); ?>> 在 WooCommerce「帳號及隱私權」設定旁顯示用途建議</label>
                    <p><a class="button" href="<?php echo esc_url($account_url); ?>">前往帳號及隱私權設定</a></p>
                </section>
                <?php submit_button('儲存結帳登入設定'); ?>
            </form>
        </div>
        <style>
            .wutm-clo-admin{max-width:980px}.wutm-clo-hero{margin:18px 0;padding:24px 28px;border-radius:10px;background:#17202a;color:#fff}.wutm-clo-hero h1{margin:0 0 8px;color:#fff}.wutm-clo-hero p{margin:0;color:#dfe6e9;font-size:15px}.wutm-clo-card{margin:18px 0;padding:22px 26px;background:#fff;border:1px solid #dcdcde;border-radius:10px;box-shadow:0 3px 12px rgba(0,0,0,.05)}.wutm-clo-card h2{margin-top:0;border-bottom:1px solid #edf0f2;padding-bottom:14px}
        </style>
        <?php
    }

    public function login_message($message): string {
        $custom = trim((string) $this->options['login_message']);
        return $custom !== '' ? $custom : (string) $message;
    }

    public function must_login_message($message): string {
        $custom = trim((string) $this->options['required_message']);
        if ($custom === '') $custom = wp_strip_all_tags((string) $message);
        $checkout_url = function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : home_url('/');
        $account_url = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : wp_login_url();
        $login_url = add_query_arg('redirect_to', $checkout_url, $account_url);
        return '<span class="wutm-clo-required-text">' . esc_html($custom) . '</span> <a class="button wutm-clo-required-button" href="' . esc_url($login_url) . '">前往登入</a>';
    }

    public function checkout_fields($fields): array {
        if (!is_array($fields) || empty($fields['account']['account_password'])) return is_array($fields) ? $fields : array();
        $label = trim((string) $this->options['password_label']);
        $placeholder = trim((string) $this->options['password_placeholder']);
        if ($label !== '') $fields['account']['account_password']['label'] = $label;
        if ($placeholder !== '') $fields['account']['account_password']['placeholder'] = $placeholder;
        return $fields;
    }

    public function styles(): void {
        if (!function_exists('is_checkout') || !is_checkout() || (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-received'))) return;
        wp_register_style('wutm-checkout-login-optimizer', false, array(), WUTM_VERSION);
        wp_enqueue_style('wutm-checkout-login-optimizer');
        $expand = !empty($this->options['expand_login']) ? 'display:block!important;' : '';
        $css = '
            .woocommerce-form-login-toggle .woocommerce-info{border:0!important;border-radius:10px!important;background:#f4f7fb!important;color:#17202a!important;padding:18px 22px!important;box-shadow:none!important;font-weight:600}
            .woocommerce-form-login-toggle .woocommerce-info:before{display:none!important}
            .woocommerce-form-login-toggle .showlogin{color:#2563eb!important;text-underline-offset:3px}
            form.woocommerce-form-login.login{' . $expand . 'margin:14px 0 28px!important;padding:24px!important;border:1px solid #d9e0e8!important;border-radius:12px!important;background:#fff!important;box-shadow:0 8px 28px rgba(15,23,42,.07)!important}
            form.woocommerce-form-login.login>p:first-child{margin-top:0;color:#52606d}
            form.woocommerce-form-login.login .form-row-first,form.woocommerce-form-login.login .form-row-last{width:48%!important;float:left!important}
            form.woocommerce-form-login.login .form-row-last{float:right!important}
            form.woocommerce-form-login.login input.input-text{min-height:46px;border:1px solid #cbd5e1!important;border-radius:7px!important;background:#fff!important;box-shadow:none!important}
            form.woocommerce-form-login.login .woocommerce-form-login__submit{min-height:44px;padding:0 24px!important;border-radius:7px!important;background:#17202a!important;color:#fff!important}
            form.woocommerce-form-login.login .lost_password{clear:both;margin-bottom:0}
            .woocommerce-error .wutm-clo-required-text,.woocommerce-info .wutm-clo-required-text{display:inline-block;margin:6px 12px 6px 0;font-weight:600}
            .wutm-clo-required-button{margin:4px 0!important}
            @media(max-width:680px){form.woocommerce-form-login.login .form-row-first,form.woocommerce-form-login.login .form-row-last{width:100%!important;float:none!important}form.woocommerce-form-login.login{padding:18px!important}}
        ';
        wp_add_inline_style('wutm-checkout-login-optimizer', $css);
    }

    public function account_setting_hints(): void {
        if (empty($this->options['admin_hints']) || !current_user_can('manage_woocommerce')) return;
        $page = sanitize_key(wp_unslash($_GET['page'] ?? ''));
        $tab = sanitize_key(wp_unslash($_GET['tab'] ?? ''));
        if ($page !== 'wc-settings' || $tab !== 'account') return;

        $hints = array(
            'woocommerce_enable_guest_checkout' => '關閉時，訪客必須先登入或建立帳號才能結帳。',
            'woocommerce_enable_checkout_login_reminder' => '建議啟用，結帳頁才會顯示會員登入表單。',
            'woocommerce_enable_signup_and_login_from_checkout' => '新版 WooCommerce 使用此項控制結帳頁建立帳號與登入。',
            'woocommerce_enable_signup_from_checkout' => '允許顧客在傳統結帳流程中建立帳號。',
            'woocommerce_enable_myaccount_registration' => '允許顧客在「我的帳號」頁面註冊。',
            'woocommerce_registration_generate_password' => '關閉時可讓顧客自行設定密碼；啟用時由系統產生。',
            'woocommerce_registration_generate_username' => '啟用時會以顧客的電子郵件自動建立使用者名稱。',
        );
        ?>
        <style>.wutm-clo-hint{display:inline-block;margin:7px 0 0 8px;padding:4px 9px;border-radius:999px;background:#e8f2ff;color:#135e96;font-size:12px;font-weight:600}.wutm-clo-hint:before{content:"指引："}</style>
        <script>
        (function(){
            var hints=<?php echo wp_json_encode($hints, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
            Object.keys(hints).forEach(function(id){
                var field=document.getElementById(id), row=field&&field.closest('tr');
                if(!row||row.querySelector('.wutm-clo-hint')) return;
                var cell=row.querySelector('td')||row;
                var badge=document.createElement('span'); badge.className='wutm-clo-hint'; badge.textContent=hints[id]; cell.appendChild(badge);
            });
        }());
        </script>
        <?php
    }
}

new WUTM_Checkout_Login_Optimizer();
