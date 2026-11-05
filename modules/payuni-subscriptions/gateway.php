<?php
if(!defined('ABSPATH'))exit;
final class WUTM_SUB_PAYUNI_Gateway extends WUTMPayuniSubscription\Gateways\CreditSubscription {
 public function get_icon(){return '<span class="wutm-payuni-subscription-label">PAYUNi</span>';}
 public function __construct(){parent::__construct();$this->enabled=$this->get_option('enabled','no');$this->order_button_text='確認訂閱並付款';$this->supports=array_values(array_diff($this->supports,['subscription_payment_method_change','subscription_payment_method_change_customer','subscription_payment_method_change_admin']));}
 public function init_form_fields():void {
  parent::init_form_fields();
  $this->form_fields['enabled']['title']='啟用訂閱付款';
  $this->form_fields['enabled']['label']='啟用 PAYUNi 信用卡定期扣款';
  $this->form_fields['enabled']['default']='no';
  $this->form_fields['title']['title']='結帳頁顯示名稱';
  $this->form_fields['title']['description']='顧客在WU付款頁看到的付款方式名稱。';
  $this->form_fields['title']['default']='信用卡定期付款（PAYUNi）';
  $this->form_fields['description']['title']='結帳頁說明';
  $this->form_fields['description']['description']='顧客在付款方式下方看到的說明。';
  $this->form_fields['testmode']=['title'=>'測試模式','type'=>'checkbox','label'=>'使用 PAYUNi 沙盒','default'=>'yes'];
  $this->form_fields['auth3d']=['title'=>'3D 驗證','type'=>'checkbox','label'=>'啟用信用卡 3D 驗證','default'=>'yes'];
  foreach(['merchant'=>'正式商店代號','hash_key'=>'正式 Hash Key','hash_iv'=>'正式 Hash IV','merchant_test'=>'沙盒商店代號','hash_key_test'=>'沙盒 Hash Key','hash_iv_test'=>'沙盒 Hash IV'] as $key=>$label)$this->form_fields[$key]=['title'=>$label,'type'=>str_contains($key,'hash')?'password':'text','default'=>'','description'=>'請在此設定，勿透過聊天提供。'];
 }
 public function is_available(){
  $test=$this->get_option('testmode','yes')==='yes';$suffix=$test?'_test':'';
  if(get_woocommerce_currency()!=='TWD'||(!$test&&!is_ssl()))return false;
  if(!$this->get_option('merchant'.$suffix)||strlen((string)$this->get_option('hash_key'.$suffix))!==32||strlen((string)$this->get_option('hash_iv'.$suffix))!==16)return false;
  return parent::is_available();
 }
 public function process_payment($order_id):array {
  $order=wc_get_order($order_id);
  if(!$order||$order->get_currency()!=='TWD'||(float)$order->get_total()<10||(float)$order->get_total()!==(float)(int)$order->get_total()){wc_add_notice('訂閱付款需為新台幣整數且至少 NT$10。','error');return ['result'=>'failure'];}
  $token_id=absint(wp_unslash($_POST['wc-payuni-credit-subscription-payment-token']??0));
  if($token_id){$token=WC_Payment_Tokens::get($token_id);if(!$token||(int)$token->get_user_id()!==(int)$order->get_customer_id()||$token->get_gateway_id()!==$this->id){wc_add_notice('所選付款卡片不屬於此帳戶。','error');return ['result'=>'failure'];}}
  if($order->is_paid())return ['result'=>'success','redirect'=>$this->get_return_url($order)];
  $lock='wupet_payuni_first_'.$order_id;if(!add_option($lock,time(),'','no')){wc_add_notice('付款正在處理，請稍候。','error');return ['result'=>'failure'];}
  try{
   if($order->get_meta('_wupet_payuni_first_attempt')){wc_add_notice('此筆付款已送出，請返回方案頁確認狀態；結果不明時請聯繫管理者，避免重複扣款。','error');return ['result'=>'failure'];}
   $order->update_meta_data('_wupet_payuni_first_attempt',time());$order->save();
   $result=parent::process_payment($order_id);
   return $result;
  }finally{delete_option($lock);}
 }
}
