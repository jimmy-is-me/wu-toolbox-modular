<?php

namespace WPBrewer\ESun\Payment\Utils;

class OrderMeta {

    const TRANS_SERIAL_NO = '_esun_order_trans_no'; //交易序號

    const TRADE_DATE = '_esun_credit_trade_date'; //交易日期
    const TRADE_TIME = '_esun_credit_trade_time'; //交易時間
    const RRN        = '_esun_credit_rrn'; //交易序號
    const AIR        = '_esun_credit_air'; //授權碼
    const AN         = '_esun_credit_an'; //授權號碼

    const INSTALLMENT_NUMBER       = '_esun_credit_installment_number'; //分期期數
    const INSTALLMENT_TOTAL_AMOUNT = '_esun_credit_installment_total_amount'; //分期總金額
    const INSTALLMENT_EACH_AMOUNT  = '_esun_credit_installment_each_amount'; //每期金額
    const INSTALLMENT_FIRST_AMOUNT = '_esun_credit_installment_first_amount'; //頭期款金額

    const TRANS_STATUS        = '_esun_credit_trans_status'; //交易狀態
    const ERROR_NO            = '_esun_credit_error_no'; //錯誤代碼
    const ERROR_DESC          = '_esun_credit_error_desc'; //錯誤訊息
}