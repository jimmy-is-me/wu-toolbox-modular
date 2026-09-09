<?php
/** WooCommerce 折價券展示、套用與推廣工具。 */
defined('ABSPATH') || exit;

final class WUTM_Coupon_Optimizer {
    private const PAGE = 'wu-coupon-optimizer';
    private const OPTION = 'wutm_coupon_optimizer_options';
    private const ENDPOINT = 'my-coupons';
    private const RULES_VERSION = '1';
    private $options;

    public function __construct() {
        if (!class_exists('WooCommerce')) return;
        $this->options = wp_parse_args((array) get_option(self::OPTION, array()), array(
            'show_cart' => true,
            'show_checkout' => true,
            'show_guest_notice' => true,
            'guest_notice' => '登入或註冊會員，即可查看可使用的專屬折價券！',
            'show_account' => true,
            'show_share_tools' => true,
            'max_coupons' => 12,
        ));
        add_action('admin_menu', array($this, 'menu'));
        add_action('admin_init', array($this, 'settings'));
        add_action('woocommerce_before_cart_table', array($this, 'render_offer'), 5);
        add_action('woocommerce_before_checkout_form', array($this, 'render_offer'), 8);
        add_action('wp_loaded', array($this, 'apply_from_url'), 30);
        add_action('woocommerce_coupon_options', array($this, 'coupon_share_tools'), 10, 2);
        add_filter('manage_edit-shop_coupon_columns', array($this, 'coupon_columns'));
        add_action('manage_shop_coupon_posts_custom_column', array($this, 'coupon_column'), 10, 2);
        add_action('init', array($this, 'endpoint'));
        add_action('init', array($this, 'maybe_flush_rules'), 99);
        add_filter('query_vars', array($this, 'query_vars'));
        add_filter('woocommerce_account_menu_items', array($this, 'account_menu'));
        add_action('woocommerce_account_' . self::ENDPOINT . '_endpoint', array($this, 'account_page'));
        add_action('wp_enqueue_scripts', array($this, 'styles'));
    }

    public function menu(): void {
        add_submenu_page('wu-toolbox-modular', '折價券優化', '折價券優化', 'manage_woocommerce', self::PAGE, array($this, 'settings_page'));
    }

    public function settings(): void {
        register_setting('wutm_coupon_optimizer_group', self::OPTION, array('sanitize_callback' => array($this, 'sanitize')));
    }

    public function sanitize($input): array {
        $input = is_array($input) ? $input : array();
        return array(
            'show_cart' => !empty($input['show_cart']),
            'show_checkout' => !empty($input['show_checkout']),
            'show_guest_notice' => !empty($input['show_guest_notice']),
            'guest_notice' => sanitize_text_field(wp_unslash($input['guest_notice'] ?? '')),
            'show_account' => !empty($input['show_account']),
            'show_share_tools' => !empty($input['show_share_tools']),
            'max_coupons' => max(1, min(50, absint($input['max_coupons'] ?? 12))),
        );
    }

    public function settings_page(): void {
        if (!current_user_can('manage_woocommerce')) wp_die('您沒有管理折價券優化的權限。');
        $o = $this->options;
        ?>
        <div class="wrap"><h1>🏷️ 折價券優化</h1><p>集中設定前台可用折價券、一鍵套用連結、推廣 QR Code 與會員中心折價券頁面。</p>
            <form method="post" action="options.php"><div style="max-width:860px;padding:20px 24px;background:#fff;border:1px solid #c3c4c7;border-radius:7px"><?php settings_fields('wutm_coupon_optimizer_group'); ?>
                <table class="form-table"><tr><th>顯示位置</th><td><?php foreach (array('show_cart'=>'購物車頁面顯示可用折價券','show_checkout'=>'結帳頁面顯示可用折價券','show_account'=>'會員中心加入「我的折價券」','show_share_tools'=>'折價券後台顯示推廣連結、QR Code 與統計') as $key=>$label) : ?><label style="display:block;margin-bottom:9px"><input type="checkbox" name="<?php echo esc_attr(self::OPTION . '[' . $key . ']'); ?>" value="1" <?php checked(!empty($o[$key])); ?>> <?php echo esc_html($label); ?></label><?php endforeach; ?></td></tr>
                    <tr><th>訪客提示</th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[show_guest_notice]" value="1" <?php checked(!empty($o['show_guest_notice'])); ?>> 未登入時顯示登入／註冊提示</label><br><input type="text" class="large-text" style="margin-top:9px" name="<?php echo esc_attr(self::OPTION); ?>[guest_notice]" value="<?php echo esc_attr($o['guest_notice']); ?>"></td></tr>
                    <tr><th><label for="wutm-coupon-limit">最多顯示張數</label></th><td><input id="wutm-coupon-limit" type="number" min="1" max="50" name="<?php echo esc_attr(self::OPTION); ?>[max_coupons]" value="<?php echo esc_attr((string) $o['max_coupons']); ?>"><p class="description">避免優惠券很多時影響購物車載入速度，預設顯示 12 張。</p></td></tr>
                </table><?php submit_button('儲存折價券設定'); ?></div></form>
        </div>
        <?php
    }

    public function render_offer(): void {
        static $rendered = false;
        if ($rendered || (is_cart() && empty($this->options['show_cart'])) || (is_checkout() && empty($this->options['show_checkout']))) return;
        if (!is_cart() && !is_checkout()) return;
        $rendered = true;
        if (!is_user_logged_in()) {
            if (empty($this->options['show_guest_notice'])) return;
            echo '<div class="wutm-coupon-guest"><span>🎁 ' . esc_html($this->options['guest_notice']) . '</span><a class="wutm-coupon-login" href="' . esc_url(wc_get_page_permalink('myaccount')) . '">前往登入／註冊</a></div>';
            return;
        }
        $coupons = $this->available_coupons(true);
        if (!$coupons) return;
        $applied = WC()->cart ? array_map('wc_format_coupon_code', WC()->cart->get_applied_coupons()) : array();
        $destination = is_checkout() ? wc_get_checkout_url() : wc_get_cart_url();
        echo '<section class="wutm-coupon-box"><h3>🏷️ 點擊即可套用折價券</h3><div class="wutm-coupon-grid">';
        foreach ($coupons as $coupon) {
            $code = $coupon->get_code();
            $is_applied = in_array(wc_format_coupon_code($code), $applied, true);
            echo '<article class="wutm-coupon-card' . ($is_applied ? ' is-applied' : '') . '"><div><strong>' . esc_html($code) . '</strong><span>' . wp_kses_post($this->discount_text($coupon)) . '</span>';
            if ((float) $coupon->get_minimum_amount() > 0) echo '<small>滿 ' . wp_kses_post(wc_price($coupon->get_minimum_amount())) . ' 可用</small>';
            if ($coupon->get_description()) echo '<small>' . esc_html($coupon->get_description()) . '</small>';
            echo '</div>';
            if ($is_applied) echo '<span class="wutm-coupon-applied">已套用</span>';
            else echo '<a class="wutm-coupon-apply" href="' . esc_url($this->share_url($coupon, $destination)) . '">點擊套用</a>';
            echo '</article>';
        }
        echo '</div></section>';
    }

    public function apply_from_url(): void {
        if (empty($_GET['wutm_apply_coupon']) || is_admin()) return;
        if (!function_exists('WC') || !WC()->cart) return;
        $code = wc_format_coupon_code(wp_unslash($_GET['wutm_apply_coupon']));
        if ($code === '') return;
        if (!WC()->cart->has_discount($code)) {
            $applied = WC()->cart->apply_coupon($code);
            if ($applied) wc_add_notice('折價券「' . $code . '」已成功套用。', 'success');
        }
        $redirect = remove_query_arg('wutm_apply_coupon');
        wp_safe_redirect($redirect ?: wc_get_cart_url());
        exit;
    }

    private function available_coupons(bool $validate_cart = false): array {
        $limit = (int) $this->options['max_coupons'];
        $scan_limit = min(200, max(50, $limit * 5));
        $ids = get_posts(array('post_type'=>'shop_coupon','post_status'=>'publish','posts_per_page'=>$scan_limit,'orderby'=>'date','order'=>'DESC','fields'=>'ids','no_found_rows'=>true));
        $valid = array();
        $discounts = $validate_cart && WC()->cart ? new WC_Discounts(WC()->cart) : null;
        foreach ($ids as $id) {
            try {
                $coupon = new WC_Coupon($id);
                if (!$coupon->get_id() || ($coupon->get_date_expires() && $coupon->get_date_expires()->getTimestamp() < time())) continue;
                if ($coupon->get_usage_limit() > 0 && $coupon->get_usage_count() >= $coupon->get_usage_limit()) continue;
                if (!$this->email_allowed($coupon)) continue;
                if ($this->used_up_by_current_user($coupon)) continue;
                if ($discounts) {
                    $result = $discounts->is_coupon_valid($coupon);
                    if (is_wp_error($result) || $result !== true) continue;
                }
                $valid[] = $coupon;
                if (count($valid) >= $limit) break;
            } catch (Throwable $error) {
                continue;
            }
        }
        return $valid;
    }

    private function email_allowed(WC_Coupon $coupon): bool {
        $restrictions = array_filter(array_map('strtolower', $coupon->get_email_restrictions()));
        if (!$restrictions) return true;
        $user = wp_get_current_user();
        if (!$user->exists()) return false;
        $email = strtolower((string) $user->user_email);
        foreach ($restrictions as $pattern) {
            if ($email === $pattern) return true;
            $regex = '/^' . str_replace('\\*', '.*', preg_quote($pattern, '/')) . '$/i';
            if (preg_match($regex, $email)) return true;
        }
        return false;
    }

    private function used_up_by_current_user(WC_Coupon $coupon): bool {
        $limit = absint($coupon->get_usage_limit_per_user());
        $user = wp_get_current_user();
        if (!$limit || !$user->exists()) return false;
        $user_id = (string) $user->ID;
        $email = strtolower((string) $user->user_email);
        $used = 0;
        foreach ($coupon->get_used_by() as $identifier) {
            $identifier = strtolower((string) $identifier);
            if ($identifier === $user_id || $identifier === $email) $used++;
        }
        return $used >= $limit;
    }

    private function discount_text(WC_Coupon $coupon): string {
        if ($coupon->get_discount_type() === 'percent') return esc_html(wc_format_decimal($coupon->get_amount())) . '% 折扣';
        return '折抵 ' . wc_price($coupon->get_amount());
    }

    private function share_url(WC_Coupon $coupon, string $destination = ''): string {
        return add_query_arg('wutm_apply_coupon', $coupon->get_code(), $destination ?: wc_get_cart_url());
    }

    public function coupon_share_tools($coupon_id, $coupon): void {
        if (empty($this->options['show_share_tools']) || !($coupon instanceof WC_Coupon)) return;
        $url = $this->share_url($coupon);
        $qr = 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=' . rawurlencode($url);
        echo '<div class="options_group"><p class="form-field"><label>一鍵套用網址</label><input type="text" readonly value="' . esc_attr($url) . '" style="width:60%;background:#f6f7f7" onclick="this.select()"><span class="description">顧客開啟後會進入購物車並套用這張折價券。</span></p><p class="form-field"><label>活動 QR Code</label><img loading="lazy" referrerpolicy="no-referrer" src="' . esc_url($qr) . '" width="150" height="150" alt="折價券 QR Code" style="display:block;margin:5px 0 10px;border:1px solid #ccd0d4;padding:4px;background:#fff"><span class="description">QR Code 由 api.qrserver.com 產生，適合用於印刷或活動宣傳。</span></p></div>';
    }

    public function coupon_columns(array $columns): array {
        if (empty($this->options['show_share_tools'])) return $columns;
        $columns['wutm_coupon_link'] = '套用連結';
        $columns['wutm_coupon_qr'] = 'QR Code';
        $columns['wutm_coupon_usage'] = '使用統計';
        return $columns;
    }

    public function coupon_column(string $column, $post_id): void {
        if (empty($this->options['show_share_tools'])) return;
        $coupon = new WC_Coupon($post_id);
        if (!$coupon->get_id()) return;
        $url = $this->share_url($coupon);
        if ($column === 'wutm_coupon_link') echo '<input type="text" readonly value="' . esc_attr($url) . '" style="width:100%;font-size:11px" onclick="this.select()">';
        if ($column === 'wutm_coupon_qr') {
            $qr = 'https://api.qrserver.com/v1/create-qr-code/?size=160x160&data=' . rawurlencode($url);
            echo '<a href="' . esc_url($qr) . '" target="_blank" rel="noopener"><img loading="lazy" referrerpolicy="no-referrer" src="' . esc_url($qr) . '" width="44" height="44" alt="QR Code"></a>';
        }
        if ($column === 'wutm_coupon_usage') {
            $used = absint($coupon->get_usage_count());
            $limit = absint($coupon->get_usage_limit());
            echo $limit ? '<strong>' . esc_html($used . '／' . $limit) . '</strong>（' . esc_html((string) round(($used / $limit) * 100, 1)) . '%）' : '已用 <strong>' . esc_html((string) $used) . '</strong> 次（無上限）';
        }
    }

    public function endpoint(): void {
        add_rewrite_endpoint(self::ENDPOINT, EP_ROOT | EP_PAGES);
    }

    public function maybe_flush_rules(): void {
        if (get_option('wutm_coupon_optimizer_rules_version') === self::RULES_VERSION) return;
        flush_rewrite_rules(false);
        update_option('wutm_coupon_optimizer_rules_version', self::RULES_VERSION, false);
    }

    public function query_vars(array $vars): array {
        $vars[] = self::ENDPOINT;
        return $vars;
    }

    public function account_menu(array $items): array {
        if (empty($this->options['show_account'])) return $items;
        $new = array();
        foreach ($items as $key => $label) {
            $new[$key] = $label;
            if ($key === 'orders') $new[self::ENDPOINT] = '我的折價券';
        }
        if (!isset($new[self::ENDPOINT])) $new[self::ENDPOINT] = '我的折價券';
        return $new;
    }

    public function account_page(): void {
        if (empty($this->options['show_account'])) return;
        $coupons = $this->available_coupons(false);
        echo '<h2>我的可用折價券</h2>';
        if (!$coupons) { echo '<p>目前沒有可用的折價券。</p>'; return; }
        echo '<div class="wutm-coupon-grid wutm-account-coupons">';
        foreach ($coupons as $coupon) {
            $expiry = $coupon->get_date_expires() ? $coupon->get_date_expires()->date_i18n('Y-m-d') : '永久有效';
            echo '<article class="wutm-coupon-card"><div><strong>' . esc_html($coupon->get_code()) . '</strong><span>' . wp_kses_post($this->discount_text($coupon)) . '</span><small>最低消費：' . ((float)$coupon->get_minimum_amount()>0 ? wp_kses_post(wc_price($coupon->get_minimum_amount())) : '無門檻') . '</small><small>有效期限：' . esc_html($expiry) . '</small></div><a class="wutm-coupon-apply" href="' . esc_url($this->share_url($coupon)) . '">立即使用</a></article>';
        }
        echo '</div>';
    }

    public function styles(): void {
        if (!(is_cart() || is_checkout() || is_account_page())) return;
        wp_register_style('wutm-coupon-optimizer', false, array(), WUTM_VERSION);
        wp_enqueue_style('wutm-coupon-optimizer');
        wp_add_inline_style('wutm-coupon-optimizer', '.wutm-coupon-guest{display:flex;justify-content:space-between;align-items:center;gap:16px;margin:0 0 22px;padding:14px 18px;border:1px solid #fde68a;border-radius:8px;background:#fffbeb;color:#854d0e}.wutm-coupon-login,.wutm-coupon-apply{display:inline-block;padding:7px 13px;border-radius:5px;background:#8b1d1d;color:#fff!important;text-decoration:none!important;font-size:13px;font-weight:700;white-space:nowrap}.wutm-coupon-box{margin:0 0 26px;padding:18px 20px;border:1px dashed #cbd5e1;border-radius:9px;background:#f8fafc}.wutm-coupon-box h3{margin:0 0 14px;font-size:15px}.wutm-coupon-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:13px}.wutm-coupon-card{display:flex;justify-content:space-between;align-items:center;gap:14px;padding:13px 15px;border:1px solid #e2e8f0;border-radius:7px;background:#fff;transition:.2s}.wutm-coupon-card:hover{border-color:#8b1d1d;box-shadow:0 2px 7px rgba(15,23,42,.06)}.wutm-coupon-card.is-applied{border-color:#86efac;background:#f0fdf4}.wutm-coupon-card>div{display:flex;flex-direction:column}.wutm-coupon-card strong{color:#8b1d1d;font-size:16px;letter-spacing:.3px}.wutm-coupon-card span{color:#15803d;font-size:13px;font-weight:700}.wutm-coupon-card small{margin-top:2px;color:#64748b;font-size:11.5px}.wutm-coupon-applied{padding:7px 12px;border-radius:5px;background:#16a34a;color:#fff!important;font-size:12px!important}.wutm-account-coupons{margin-top:18px}@media(max-width:600px){.wutm-coupon-guest{align-items:flex-start;flex-direction:column}.wutm-coupon-grid{grid-template-columns:1fr}}');
    }
}

new WUTM_Coupon_Optimizer();
