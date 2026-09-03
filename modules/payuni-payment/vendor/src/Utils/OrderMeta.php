<?php
/**
 * OrderMeta class file
 *
 * @package payuni
 */

namespace WPBrewer\Payuni\Payment\Utils;

/**
 * Class OrderMeta
 */
class OrderMeta {

    const PAYUNI_ORDER_NO      = '_wpbr_payuni_upp_order_no'; // PAYUNi 紀錄的商家訂單編號.
    const UNI_NO               = '_wpbr_payuni_upp_trade_no'; // UNi序號.
    const TRADE_STATUS         = '_wpbr_payuni_upp_trade_status'; // 交易狀態.
    const TRADE_AMOUNT         = '_wpbr_payuni_upp_trade_amount'; // 交易金額.
    const STATUS               = '_wpbr_payuni_upp_status'; // 狀態代碼.
    const MESSAGE              = '_wpbr_payuni_upp_message'; // 狀態說明.
    const PAY_TYPE             = '_wpbr_payuni_upp_payment_type'; // 支付工具

    const CREDIT_REST_CODE     = '_wpbr_payuni_upp_credit_rescode'; // 信用卡授權碼.
    const CREDIT_REST_CODE_MSG = '_wpbr_payuni_upp_credit_rescode_msg'; // 信用卡授權碼訊息.
    const CREDIT_AUTH_TYPE     = '_wpbr_payuni_upp_credit_authtype'; // 信用卡授權類型.
    const CREDIT_CARD_4NO      = '_wpbr_payuni_upp_credit_card4no'; // 信用卡後四碼.
    const CREDIT_AUTH_DAY      = '_wpbr_payuni_upp_credit_authday'; // 信用卡授權日期.
    const CREDIT_AUTH_TIME     = '_wpbr_payuni_upp_credit_authtime'; // 信用卡授權時間.
    const CREDIT_INSTALL       = '_wpbr_payuni_upp_credit_cardinst'; // 信用卡分期期數.
    const CREDIT_FIRST_AMT     = '_wpbr_payuni_upp_credit_firstamt'; // 信用卡首期金額.
    const CREDIT_EACH_AMT      = '_wpbr_payuni_upp_credit_eachamt'; // 信用卡每期金額.
    const AMT_PAY_NO           = '_wpbr_payuni_upp_atm_payno'; // 付款序號.
    const AMT_BANK_TYPE        = '_wpbr_payuni_upp_atm_banktype'; // 銀行類型.
    const AMT_PAY_TIME         = '_wpbr_payuni_upp_atm_paytime'; // 付款時間.
    const AMT_ACCOUNT_5NO      = '_wpbr_payuni_upp_atm_account5no'; // 付款帳號後五碼.
    const AMT_PAY_SET          = '_wpbr_payuni_upp_atm_payset'; // 繳費設定. 1=一次性 2=重覆性.
    const AMT_EXPIRE_DATE      = '_wpbr_payuni_upp_atm_expiredate'; // 到期日.
    const CVS_PAY_NO           = '_wpbr_payuni_upp_cvs_payno'; // 付款序號.
    const CVS_STORE            = '_wpbr_payuni_upp_cvs_store'; // 付款超商.
    const CVS_EXPIRE_DATE      = '_wpbr_payuni_upp_cvs_expiredate'; // 到期日.
    const CVS_PAY_TIME         = '_wpbr_payuni_upp_cvs_paytime'; // 付款時間.
    const AFTEE_PAY_NO         = '_wpbr_payuni_upp_aftee_payno'; // 付款序號.
    const AFTEE_PAY_TIME       = '_wpbr_payuni_upp_aftee_paytime'; // 付款時間.
    const LINE_PAY_NO          = '_wpbr_payuni_upp_linepay_payno'; // 付款序號.

    const EINVOICE_NO          = '_wpbr_payuni_einvoice_no'; // 發票編號.
    const EINVOICE_AMT         = '_wpbr_payuni_einvoice_amt'; // 發票金額.
    const EINVOICE_TIME        = '_wpbr_payuni_einvoice_time'; // 發票時間.
    const EINVOICE_TYPE        = '_wpbr_payuni_einvoice_type'; // 發票類型.(C0401=開立發票,C0501=作廢發票)
    const EINVOICE_INFO        = '_wpbr_payuni_einvoice_info'; // 發票資訊.(開立方式和載具類型)
    const EINVOICE_STATUS      = '_wpbr_payuni_einvoice_status'; // 發票狀態.

    const PLUGIN_VERSION       = '_wpbr_payuni_upp_plugin_version'; // 外掛版本.

}
