<?php
defined('ABSPATH') || exit;
final class WUTM_Loyalty_Card {
    public static function boot(): void {
        add_shortcode('wu_member_card', [__CLASS__, 'render']);
        add_action('woocommerce_account_dashboard', [__CLASS__, 'account']);
        add_action('rest_api_init', static function(): void {
            register_rest_route('wu-loyalty/v1', '/card', [
                'methods'=>'GET', 'callback'=>[__CLASS__, 'verify'],
                'permission_callback'=>static function(){return current_user_can('manage_woocommerce');},
            ]);
        });
    }
    public static function settings(): array {
        return wp_parse_args((array)get_option('wutm_loyalty_card', []), ['enabled'=>false,'title'=>'數位會員卡']);
    }
    public static function page(): void {
        if (!current_user_can('manage_woocommerce')) return;
        if (isset($_POST['wutm_card_save'])) {
            check_admin_referer('wutm_card_save');
            update_option('wutm_loyalty_card', ['enabled'=>!empty($_POST['enabled']), 'title'=>sanitize_text_field(wp_unslash($_POST['title'] ?? '數位會員卡'))], false);
            echo '<p role="status">會員卡設定已儲存。</p>';
        }
        if (isset($_GET['card_token']) && is_string($_GET['card_token'])) {
            $request = new WP_REST_Request('GET');
            $request->set_param('token', sanitize_text_field(wp_unslash($_GET['card_token'])));
            $result = self::verify($request);
            echo '<section class="wutm-ui-panel"><h3>會員卡查驗</h3><p>'.esc_html(is_wp_error($result) ? $result->get_error_message() : sprintf('會員：%s；編號：%s；目前可用點數：%s', $result->get_data()['name'], $result->get_data()['member_id'], $result->get_data()['points'])).'</p><p>查驗不執行扣點。</p></section>';
        }
        echo '<aside class="wutm-loyalty-guide"><h3>會員卡怎麼使用？</h3><ol><li>啟用後，會員登入「我的帳戶」即可看到卡片，也可將短碼放在指定頁面。</li><li>現場服務人員先登入具有「管理 WooCommerce」權限的帳號，再用手機相機掃描會員卡 QR。</li><li>查驗頁只顯示會員姓名、編號與目前可用點數，不會登入會員帳號、扣點或執行交易。</li><li>QR 每次產生後 5 分鐘到期；過期時由會員按「重新產生會員卡」。</li></ol><p>測試時請使用會員帳號與授權工作人員帳號。會員卡頁面應排除整頁快取。</p></aside>';
        $s=self::settings();
        echo '<form method="post">';wp_nonce_field('wutm_card_save');
        echo '<p><label><input name="enabled" type="checkbox" value="1" '.checked($s['enabled'],true,false).'> 啟用數位會員卡</label></p><p><label>卡片名稱 <input name="title" value="'.esc_attr($s['title']).'"></label></p><p>短碼：<code>[wu_member_card]</code>；啟用後也會在我的帳戶總覽顯示。QR 僅供有權限的管理員驗證，不會直接扣點。有效 5 分鐘，過期請重新載入會員卡。</p><button class="button button-primary" name="wutm_card_save" value="1">儲存設定</button></form>';
    }
    public static function account(): void { echo self::render(); }
    public static function render(): string {
        $settings=self::settings();
        if (!$settings['enabled']) return '';
        if (!is_user_logged_in()) return '<p>請先登入以查看自己的會員卡。</p>';
        if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
        if (function_exists('nocache_headers') && !headers_sent()) nocache_headers();
        if (function_exists('wutm_loyalty_center_assets')) wutm_loyalty_center_assets();
        $id=get_current_user_id();$user=wp_get_current_user();
        $token=wp_generate_password(48,false,false);
        set_transient('wutm_card_'.hash('sha256',$token), ['id'=>$id,'expires'=>time()+300],300);
        $url=add_query_arg(['page'=>'wu-member-loyalty','section'=>'member-card','card_token'=>$token],admin_url('admin.php'));
        wp_enqueue_script('wutm-card-qr', WUTM_URL.'modules/wu-ai-card/assets/qrcode.min.js', [], WUTM_VERSION,true);
        wp_enqueue_script('wutm-member-card', WUTM_URL.'modules/member-loyalty/member-card.js', ['wutm-card-qr'],WUTM_VERSION,true);
        $tier=class_exists('WC_Membership_Tiers') ? WC_Membership_Tiers::instance()->wmt_get_user_current_tier($id) : null;
        $balance=WC_Member_Points_Rewards::instance()->wcmp_get_balance($id);
        return '<section class="wutm-member-card wutm-loyalty-center">'.(function_exists('wutm_loyalty_center_nav') ? wutm_loyalty_center_nav() : '').'<h2>'.esc_html($settings['title']).'</h2><div class="wutm-card-face"><div class="wutm-card-profile"><span class="wutm-card-tier">'.esc_html($tier ? $tier->name : '一般會員').'</span><h3>'.esc_html($user->display_name).'</h3><p>會員編號：'.esc_html($id).'</p><p class="wutm-card-points"><strong>'.esc_html($balance).'</strong><span>目前可用點數</span></p></div><div class="wutm-card-code"><div class="wutm-member-card-qr" data-card-expires="'.esc_attr(time()+300).'" data-qr="'.esc_attr($url).'" aria-label="限授權人員驗證的短效會員 QR"></div><p class="wutm-card-status" role="status">正在產生會員 QR…</p><button type="button" data-card-refresh>重新產生會員卡</button></div></div><p class="wutm-card-help">到店時出示 QR，由已登入的授權人員核對會員身分與點數。QR 於 5 分鐘後失效，不能用來登入或直接扣點。</p></section>';

    }
    public static function verify($request) {
        $token=(string)$request->get_param('token');
        if (!preg_match('/^[A-Za-z0-9]{48}$/',$token)) return new WP_Error('invalid_card','會員卡無效。',['status'=>400]);
        $card=get_transient('wutm_card_'.hash('sha256',$token));
        if (!$card || $card['expires']<time() || !self::settings()['enabled']) return new WP_Error('expired_card','會員卡已過期或停用。',['status'=>410]);
        $user=get_userdata($card['id']);
        if (!$user) return new WP_Error('missing_member','會員不存在。',['status'=>404]);
        $response=new WP_REST_Response(['name'=>$user->display_name,'member_id'=>$user->ID,'points'=>WC_Member_Points_Rewards::instance()->wcmp_get_balance($user->ID),'expires'=>$card['expires']]);
        $response->header('Cache-Control','private, no-store');return $response;
    }
}
WUTM_Loyalty_Card::boot();
