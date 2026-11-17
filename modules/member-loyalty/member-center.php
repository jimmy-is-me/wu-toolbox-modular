<?php
/** Presentation only. Never changes balances, tier qualifications or coupons. */
defined('ABSPATH') || exit;
function wutm_loyalty_center_assets(): void {
    wp_enqueue_style('wutm-loyalty-account', WUTM_URL.'modules/member-loyalty/account.css', [], WUTM_VERSION);
    wp_enqueue_script('wutm-loyalty-center', WUTM_URL.'modules/member-loyalty/member-center.js', [], WUTM_VERSION, true);
}
function wutm_loyalty_center_nav(): string {
    return ''; // WooCommerce already provides account navigation.
}
function wutm_loyalty_expiry(string $date): string {
    if (!$date) return '<span class="wutm-expiry">永久有效</span>';
    $invalid='<span>請聯絡客服確認會員效期</span>';
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $date)) return $invalid;
    $end=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $date, wp_timezone());
    if (!$end || $end->format('Y-m-d H:i:s') !== $date) return $invalid;
    $seconds=$end->getTimestamp()-time();
    $days=max(0,(int)ceil($seconds/86400));
    return '<span class="wutm-expiry'.($days<=7?' is-soon':'').'" data-member-expires="'.esc_attr($end->getTimestamp()).'">'.esc_html($seconds<=0?'效期已到，等待系統確認會員資格':'會員效期剩 '.$days.' 天').'</span>';
}
