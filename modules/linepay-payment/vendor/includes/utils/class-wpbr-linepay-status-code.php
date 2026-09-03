<?php
/**
 * WPBR_LINEPay_Const class file
 *
 * @package linepay_tw
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LINEPayStatusCode {

	const UNAUTH            = '0000'; // 授權尚未完成
	const AUTHED            = '0110'; // 授權完成 - 現在可以呼叫Confirm API
	const CANCELLED_EXPIRED = '0121'; // 該交易已被用戶取消，或者超時取消（20分鐘）- 交易已經結束了
	const FAILED            = '0122'; // 付款失敗 - 交易已經結束了
	const COMPLETED         = '0123'; // 付款成功 - 交易已經結束了
	const NO_MERCHANT       = '1104'; // 此商家不存在
	const CANNOT_USE        = '1105'; // 此商家處於無法使用LINE Pay的狀態
	const INTERNAL_ERROR    = '9000'; // 內部錯誤
}
