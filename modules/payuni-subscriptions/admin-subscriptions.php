<?php
if (!defined('ABSPATH')) exit;

/** A small Traditional Chinese dashboard for the pet app's subscriptions. */
final class WUTM_SUB_PAYUNI_Admin_Subscriptions {
 public static function init() {
  add_action('admin_menu', [self::class, 'menu'], 99);
  add_filter('gettext_woocommerce-subscriptions', [self::class, 'translate_admin'], 10, 2);
 }

 public static function hide_storewide_settings($tabs) {
  unset($tabs['subscriptions']);
  return $tabs;
 }

 public static function redirect_storewide_settings() {
  if (!current_user_can('manage_options') || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;
  if (($_GET['page'] ?? '') !== 'wc-settings' || ($_GET['tab'] ?? '') !== 'subscriptions') return;
  wp_safe_redirect(admin_url('admin.php?page=wu-payuni-subscriptions'));
  exit;
 }

 public static function translate_admin($translated, $original) {
  if (!is_admin() || !str_starts_with(determine_locale(), 'zh_') || $translated !== $original) return $translated;
  $labels = [
   'Subscriptions'=>'訂閱', 'Subscription'=>'訂閱', 'Manage Subscriptions'=>'管理訂閱', 'Search Subscriptions'=>'搜尋訂閱',
   'Variable subscription'=>'可變訂閱商品', 'Simple subscription'=>'簡單訂閱商品',
   'Subscription price (%s)'=>'訂閱價格（%s）', 'Stop renewing after'=>'續訂結束時間',
   'Sign-up fee (%s)'=>'開通費（%s）', 'Free trial'=>'免費試用',
   'Free trial length'=>'免費試用長度', 'Free trial period'=>'免費試用週期',
   'Do not stop until cancelled'=>'持續續訂直到取消', 'Do not align'=>'不對齊週期',
  ];
  return $labels[$original] ?? $translated;
 }

 public static function menu() {
  add_submenu_page('wu-toolbox-modular', 'PAYUNi 訂閱管理', 'PAYUNi 訂閱管理', 'manage_woocommerce', 'wu-payuni-subscription-list', [self::class, 'render']);
 }

 public static function hide_native_menu() {
  global $submenu;
  foreach (($submenu['woocommerce'] ?? []) as $index => $item) {
   if (isset($item[2]) && str_contains((string)$item[2], 'shop_subscription')) unset($submenu['woocommerce'][$index]);
  }
 }

 private static function plan($subscription) {
  if($subscription->get_payment_method()!=='payuni-credit-subscription')return '';
  return implode('、',array_map(static fn($item)=>$item->get_name(),$subscription->get_items()))?:'訂閱';
 }
 private static function legacy_plan($subscription) {
  foreach ($subscription->get_items() as $item) {
   $key = (string)$item->get_meta('_wupet_plan');
   if (in_array($key, ['plus'], true)) return $key;
   $product = $item->get_product();
   if ($product && in_array((string)$product->get_meta('_wupet_plan'), ['plus'], true)) return (string)$product->get_meta('_wupet_plan');
  }
  return '';
 }

 private static function date($timestamp) {
  return $timestamp ? wp_date('Y/m/d H:i', $timestamp) : '—';
 }

 private static function detail($id) {
  $subscription = function_exists('wcs_get_subscription') ? wcs_get_subscription($id) : false;
  if (!$subscription || !self::plan($subscription)) {
   echo '<div class="notice notice-error inline"><p>找不到這筆WU訂閱。</p></div>';
   return;
  }
  $labels = ['pending'=>'待付款','active'=>'使用中','pending-cancel'=>'本期結束後停止','on-hold'=>'暫停／待確認','cancelled'=>'已取消','expired'=>'已到期','switched'=>'已切換'];
  $user = get_userdata($subscription->get_customer_id());
  $owner = $user ? $user->display_name.'（'.$user->user_email.'）' : '帳號已移除';
  $status = $subscription->get_status();
  $rows = [
   '訂閱編號'=>'#'.$subscription->get_order_number(),
   '顧客'=>$owner,
   '商品'=>self::plan($subscription),
   '狀態'=>$labels[$status] ?? $status,
   '開始日期'=>self::date($subscription->get_time('start')),
   '下次扣款'=>self::date($subscription->get_time('next_payment')),
   '結束日期'=>self::date($subscription->get_time('end')),
  ];
  echo '<h2>訂閱詳情</h2><table class="widefat striped" style="max-width:760px"><tbody>';
  foreach ($rows as $label=>$value) echo '<tr><th scope="row" style="width:160px">'.esc_html($label).'</th><td>'.esc_html($value).'</td></tr>';
  echo '<tr><th scope="row">每期金額</th><td>'.wp_kses_post(wc_price($subscription->get_total(), ['currency'=>$subscription->get_currency()])).'</td></tr>';
  echo '</tbody></table><h2>訂閱項目</h2><table class="widefat striped" style="max-width:760px"><thead><tr><th>項目</th><th>數量</th></tr></thead><tbody>';
  foreach ($subscription->get_items() as $item) echo '<tr><td>'.esc_html($item->get_name()).'</td><td>'.esc_html($item->get_quantity()).'</td></tr>';
  echo '</tbody></table><p>付款與續扣狀態以 PAYUNi 交易紀錄及 WooCommerce 訂閱紀錄為準；請勿在結果不明時手動重扣。</p>';
 }

 public static function render() {
  if (!current_user_can('manage_woocommerce')) wp_die('沒有權限查看訂閱。');
  $settings = esc_url(admin_url('admin.php?page=wu-payuni-subscriptions'));
  wutm_admin_page_open('PAYUNi 訂閱管理','查看訂閱商品、續扣日期與付款狀態。');
  echo '<p><a class="button button-primary" href="'.$settings.'">PAYUNi 訂閱整合設定</a></p>';
  if (!function_exists('wcs_get_subscriptions')) {
   echo '<div class="notice notice-error inline"><p>訂閱模組尚未載入。請確認 WooCommerce 已啟用，並重新安裝完整的 WUMETAX 寵物管理外掛。</p></div></div>';
   return;
  }
  $detail_id = absint($_GET['subscription'] ?? 0);
  if ($detail_id) {
   echo '<p><a class="button" href="'.esc_url(admin_url('admin.php?page=wu-payuni-subscription-list')).'">返回訂閱清單</a></p>';
   self::detail($detail_id);
   $subscription=wcs_get_subscription($detail_id);if($subscription&&self::plan($subscription))echo '<p><a class="button" href="'.esc_url($subscription->get_edit_order_url()).'">前往 WooCommerce 編輯訂閱／停止續訂</a></p>';wutm_admin_page_close();
   return;
  }
  $page = max(1, absint($_GET['paged'] ?? 1));
  $subscriptions = wcs_get_subscriptions(['payment_method'=>'payuni-credit-subscription','subscriptions_per_page' => 50, 'paged' => $page, 'orderby' => 'start_date', 'order' => 'DESC']);
  $labels = ['pending'=>'待付款','active'=>'使用中','pending-cancel'=>'本期結束後停止','on-hold'=>'暫停／待確認','cancelled'=>'已取消','expired'=>'已到期','switched'=>'已切換'];
  echo '<table class="widefat striped"><thead><tr><th>訂閱</th><th>顧客</th><th>方案</th><th>狀態</th><th>每期金額</th><th>下次扣款</th><th>結束日期</th></tr></thead><tbody>';
  $shown = 0;
  foreach ($subscriptions as $subscription) {
   $plan = self::plan($subscription);
   if (!$plan) continue;
   $shown++;
   $user = get_userdata($subscription->get_customer_id());
   $owner = $user ? ($user->display_name.'（'.$user->user_email.'）') : '帳號已移除';
   $status = $subscription->get_status();
   $amount = wc_price($subscription->get_total(), ['currency'=>$subscription->get_currency()]);
   $detail_url = add_query_arg(['page'=>'wu-payuni-subscription-list','subscription'=>$subscription->get_id()], admin_url('admin.php'));
   echo '<tr><td><a href="'.esc_url($detail_url).'">#'.esc_html($subscription->get_order_number()).'</a></td><td>'.esc_html($owner).'</td><td>'.esc_html($plan).'</td><td>'.esc_html($labels[$status] ?? $status).'</td><td>'.wp_kses_post($amount).'</td><td>'.esc_html(self::date($subscription->get_time('next_payment'))).'</td><td>'.esc_html(self::date($subscription->get_time('end'))).'</td></tr>';
  }
  if (!$shown) echo '<tr><td colspan="7">此頁沒有WU訂閱。</td></tr>';
  echo '</tbody></table>';
  if (count($subscriptions) === 50) echo '<p><a class="button" href="'.esc_url(add_query_arg(['page'=>'wu-payuni-subscription-list','paged'=>$page+1], admin_url('admin.php'))).'">下一頁</a></p>';
  if ($page > 1) echo '<p><a class="button" href="'.esc_url(add_query_arg(['page'=>'wu-payuni-subscription-list','paged'=>$page-1], admin_url('admin.php'))).'">上一頁</a></p>';
  echo '<p>「暫停／待確認」可能表示續扣結果不明。核對 PAYUNi 交易紀錄前，請勿手動重扣，以免重複收款。</p></div>';
 }
}
WUTM_SUB_PAYUNI_Admin_Subscriptions::init();
