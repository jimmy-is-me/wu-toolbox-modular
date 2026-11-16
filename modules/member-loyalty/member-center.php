<?php
/** Presentation only. Never changes balances, tier qualifications or coupons. */
defined('ABSPATH') || exit;
function wutm_loyalty_center_assets(): void {
    wp_enqueue_style('wutm-loyalty-account', WUTM_URL.'modules/member-loyalty/account.css', [], WUTM_VERSION);
    wp_enqueue_script('wutm-loyalty-center', WUTM_URL.'modules/member-loyalty/member-center.js', [], WUTM_VERSION, true);
}
function wutm_loyalty_center_nav(): string {
    wutm_loyalty_center_assets();
    $items=['dashboard'=>'會員總覽','my-points'=>'點數與紀錄','vip-tier'=>'權益與優惠券'];
    if ((get_option('wcmp_settings', [])['enable_points'] ?? 'yes') === 'no') unset($items['my-points']);
    if ((get_option('wmt_settings', [])['enable'] ?? 'yes') === 'no') unset($items['vip-tier']);
    $html='<nav class="wutm-member-nav" aria-label="會員中心導覽">';
    foreach($items as $endpoint=>$label){
        $current=$endpoint==='dashboard' ? !is_wc_endpoint_url() : is_wc_endpoint_url($endpoint);
        $html.='<a href="'.esc_url(wc_get_account_endpoint_url($endpoint)).'"'.($current?' aria-current="page"':'').'>'.esc_html($label).'</a>';
    }
    return $html.'</nav>';
}
function wutm_loyalty_expiry(string $date): string {
    if (!$date) return '<span class="wutm-expiry">永久有效</span>';
    try { $end=new DateTimeImmutable($date, wp_timezone()); } catch(Exception $e){return '<span>請聯絡客服確認會員效期</span>';}
    $seconds=$end->getTimestamp()-time();
    $days=max(0,(int)ceil($seconds/86400));
    return '<span class="wutm-expiry'.($days<=7?' is-soon':'').'" data-member-expires="'.esc_attr($end->getTimestamp()).'">'.esc_html($seconds<=0?'效期已到，等待系統確認會員資格':'會員效期剩 '.$days.' 天').'</span>';
}
