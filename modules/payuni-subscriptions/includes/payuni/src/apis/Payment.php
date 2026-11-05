<?php
namespace WUTMPayuniSubscription\APIs;

defined('ABSPATH') || exit;

/** PAYUNi V2 request cryptography. Never logs card data or transaction tokens. */
final class Payment {
 public static function log($message, $level='info'): void {}

 public static function encrypt($info): string {
  $test=\wc_string_to_bool(\get_option('wutm_sub_payuni_payment_testmode'));
  $key=trim((string)\get_option($test?'wutm_sub_payuni_payment_hash_key_test':'wutm_sub_payuni_payment_hash_key'));
  $iv=trim((string)\get_option($test?'wutm_sub_payuni_payment_hash_iv_test':'wutm_sub_payuni_payment_hash_iv'));
  $tag='';
  $encrypted=\openssl_encrypt(\http_build_query($info),'aes-256-gcm',$key,0,$iv,$tag);
  if ($encrypted===false) throw new \RuntimeException('PAYUNi 加密失敗');
  return \bin2hex($encrypted.':::'.\base64_encode($tag));
 }

 public static function hash_info(string $encrypted=''): string {
  $test=\wc_string_to_bool(\get_option('wutm_sub_payuni_payment_testmode'));
  $key=(string)\get_option($test?'wutm_sub_payuni_payment_hash_key_test':'wutm_sub_payuni_payment_hash_key');
  $iv=(string)\get_option($test?'wutm_sub_payuni_payment_hash_iv_test':'wutm_sub_payuni_payment_hash_iv');
  return \strtoupper(\hash('sha256',$key.$encrypted.$iv));
 }

 public static function decrypt(string $encrypted=''): array {
  if ($encrypted==='' || !\ctype_xdigit($encrypted)) return [];
  $packed=\hex2bin($encrypted);
  if ($packed===false || !\str_contains($packed,':::')) return [];
  [$cipher,$tag]=\explode(':::',$packed,2);
  $test=\wc_string_to_bool(\get_option('wutm_sub_payuni_payment_testmode'));
  $key=trim((string)\get_option($test?'wutm_sub_payuni_payment_hash_key_test':'wutm_sub_payuni_payment_hash_key'));
  $iv=trim((string)\get_option($test?'wutm_sub_payuni_payment_hash_iv_test':'wutm_sub_payuni_payment_hash_iv'));
  $plain=\openssl_decrypt($cipher,'aes-256-gcm',$key,0,$iv,\base64_decode($tag,true));
  if ($plain===false) return [];
  \parse_str($plain,$result);
  return \is_array($result)?$result:[];
 }

 public static function get_bank_name($type): string {
  return ['004'=>'台灣銀行','812'=>'台新銀行','822'=>'中信銀行'][(string)$type]??'';
 }
}
