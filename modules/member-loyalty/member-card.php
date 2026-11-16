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
        $s=self::settings();
        echo '<form method="post">';wp_nonce_field('wutm_card_save');
        echo '<p><label><input name="enabled" type="checkbox" value="1" '.checked($s['enabled'],true,false).'> 啟用數位會員卡</label></p><p><label>卡片名稱 <input name="title" value="'.esc_attr($s['title']).'"></label></p><p>短碼：<code>[wu_member_card]</code>；啟用後也會在我的帳戶總覽顯示。QR 僅供有權限的管理員驗證，不會直接扣點。有效 5 分鐘，過期請重新載入會員卡。</p><button class="button button-primary" name="wutm_card_save" value="1">儲存設定</button></form>';
    }
    public static function account(): void { echo self::render(); }
    public static function render(): string {
        $settings=self::settings();
        if (!$settings['enabled']) return '';
        if (!is_user_logged_in()) return '<p>請先登入以查看自己的會員卡。</p>';
        $id=get_current_user_id();$user=wp_get_current_user();
        $token=wp_generate_password(48,false,false);
        set_transient('wutm_card_'.hash('sha256',$token), ['id'=>$id,'expires'=>time()+300],300);
        $url=add_query_arg('token',$token,rest_url('wu-loyalty/v1/card'));
        wp_enqueue_script('wutm-card-qr', WUTM_URL.'modules/wu-ai-card/assets/qrcode.min.js', [], WUTM_VERSION,true);
        wp_enqueue_script('wutm-member-card', WUTM_URL.'modules/member-loyalty/member-card.js', ['wutm-card-qr'],WUTM_VERSION,true);
        $tier=class_exists('WC_Membership_Tiers') ? WC_Membership_Tiers::instance()->wmt_get_user_current_tier($id) : null;
        $balance=WC_Member_Points_Rewards::instance()->wcmp_get_balance($id);
        return '<section class="wutm-member-card"><h2>'.esc_html($settings['title']).'</h2><p>'.esc_html($user->display_name).'</p><p>會員編號：'.esc_html($id).'</p><p>階級：'.esc_html($tier ? $tier->name : '尚無階級').'</p><p>可用點數：<strong>'.esc_html($balance).'</strong></p><div class="wutm-member-card-qr" data-qr="'.esc_attr($url).'" aria-label="限授權人員驗證的短效會員 QR"></div><p>QR 於 5 分鐘後失效，不能用來登入或直接扣點。</p></section>';
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
