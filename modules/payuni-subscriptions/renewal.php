<?php
if (!defined('ABSPATH')) exit;

/** Charge an existing PAYUNi V2 CreditHash only once per renewal order. */
final class WUTM_SUB_PAYUNI_Renewal {
 public static function run($amount, $order): void {
  if (!$order instanceof WC_Order || $order->is_paid() || $order->get_payment_method() !== 'payuni-credit-subscription' || $order->get_meta('_wupet_payuni_v2_attempted')) return;
  $subscriptions = wcs_get_subscriptions_for_renewal_order($order);
  if (!$subscriptions) return;
  $lock = 'wupet_payuni_v2_renewal_'.$order->get_id();
  if (!add_option($lock, time(), '', 'no')) return;
  try {
   if ($order->get_meta('_wupet_payuni_v2_attempted')) return;
   if ($order->get_currency() !== 'TWD' || (float)$amount !== (float)$order->get_total() || (float)$amount < 10 || floor((float)$amount) !== (float)$amount) throw new RuntimeException('續扣金額或幣別不正確');
   foreach ($subscriptions as $subscription) {
    if (!$subscription->has_status('active') || (int)$subscription->get_customer_id() !== (int)$order->get_customer_id()) throw new RuntimeException('訂閱狀態或付款帳戶不符');
   }
   $bound=[];foreach($subscriptions as $subscription){$id=(int)$subscription->get_meta('_wutm_payuni_bound_token');if($id)$bound[]=$id;}
   $bound=array_values(array_unique($bound));if(count($bound)>1)throw new RuntimeException('訂閱綁定的卡片不一致');
   $token=$bound?WC_Payment_Tokens::get($bound[0]):false;
   if(!$bound){$tokens=WC_Payment_Tokens::get_customer_tokens($order->get_customer_id(),'payuni-credit-subscription');if(count($tokens)===1)$token=reset($tokens);}
   if($token&&((int)$token->get_user_id()!==(int)$order->get_customer_id()||$token->get_gateway_id()!=='payuni-credit-subscription'))throw new RuntimeException('訂閱卡片不屬於付款帳戶');
   if (!$token || !$token->get_token()) throw new RuntimeException('找不到可用的訂閱卡片');
   $gateway = new WUTM_SUB_PAYUNI_Gateway();
   if ($gateway->enabled !== 'yes' || ! $gateway->is_available()) throw new RuntimeException('PAYUNi 訂閱付款尚未啟用');
   $merchant = $gateway->get_mer_id();
   $trade_no = (string)$order->get_id().'-renewal';
   $order->update_meta_data('_payuni_mer_trade_no', $trade_no);
   $order->update_meta_data('_wupet_payuni_v2_attempted', time());
   $order->save(); // The marker precedes the network request: uncertain results cannot trigger a second debit.
   $payload = ['MerID'=>$merchant,'MerTradeNo'=>$trade_no,'TradeAmt'=>(int)$amount,'Timestamp'=>time(),'UsrMail'=>$order->get_billing_email(),'ProdDesc'=>'WU訂閱續扣','CreditToken'=>$order->get_billing_email(),'CreditHash'=>$token->get_token()];
   $encrypted = WUTMPayuniSubscription\APIs\Payment::encrypt($payload);
   $response = wp_remote_post($gateway->get_api_url().$gateway->get_api_endpoint_url(), ['timeout'=>60,'body'=>['MerID'=>$merchant,'Version'=>'1.0','EncryptInfo'=>$encrypted,'HashInfo'=>WUTMPayuniSubscription\APIs\Payment::hash_info($encrypted)]]);
   if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) throw new RuntimeException('付款結果未確認');
   $raw = json_decode(wp_remote_retrieve_body($response), true);
   if (!is_array($raw) || empty($raw['EncryptInfo']) || empty($raw['HashInfo']) || !hash_equals(WUTMPayuniSubscription\APIs\Payment::hash_info($raw['EncryptInfo']), (string)$raw['HashInfo'])) throw new RuntimeException('付款回應簽章無效');
   $data = WUTMPayuniSubscription\APIs\Payment::decrypt($raw['EncryptInfo']);
   if ((isset($data['MerID']) && $data['MerID'] !== $merchant) || ($data['MerTradeNo'] ?? '') !== $trade_no || (int)($data['TradeAmt'] ?? -1) !== (int)$amount) throw new RuntimeException('付款回應與訂單不符');
   $formatted = WUTMPayuniSubscription\Gateways\Response::get_formatted_decrypted_data($data);
   if (($data['Status'] ?? '') === 'SUCCESS') {
    WUTMPayuniSubscription\Gateways\Response::complete_paid_order($order, $formatted, '續扣');
    return;
   }
   $order->update_status('failed', 'PAYUNi 已明確回報續扣失敗：'.sanitize_text_field((string)($data['Status'] ?? 'UNKNOWN')));
  } catch (Throwable $e) {
   $order->update_status('on-hold', 'PAYUNi 續扣待管理員核對：'.$e->getMessage().'；確認前請勿再次扣款。');
   foreach ($subscriptions as $subscription) if ($subscription->has_status('active')) $subscription->update_status('on-hold', '續扣結果待確認。');
  } finally {
   delete_option($lock);
  }
 }
}
