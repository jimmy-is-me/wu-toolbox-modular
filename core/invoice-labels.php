<?php
/** Traditional Chinese invoice labels; never translate API keys or order types. */
defined('ABSPATH') || exit;

function wutm_invoice_carrier($value) {
    if (!is_string($value)) return '';
    return ['Mobile Code'=>'手機條碼','Citizen Digital Certificate'=>'自然人憑證'][$value] ?? $value;
}

function wutm_invoice_label($translated, $text, $domain = '') {
    static $labels = [
        'Issue Mode'=>'開立模式', 'Issue Manual'=>'手動開立', 'Issue automatic'=>'自動開立',
        'Invalid mode'=>'作廢模式', 'Invalid manual'=>'手動作廢', 'Invalid automatic'=>'自動作廢',
        'Carrier Type'=>'載具類型', 'Mobile Code'=>'手機條碼',
        'Citizen Digital Certificate'=>'自然人憑證', 'Donated Organization'=>'捐贈機構',
        'Allowed invoice carrier type'=>'允許使用的發票載具類型',
        'Allowed Order Status for issue'=>'自動開立的訂單狀態',
        'Allowed Order Status for invalid'=>'自動作廢的訂單狀態',
        'Order number prefix'=>'訂單編號前綴', 'Invoice options'=>'電子發票設定',
        'The prefix string of order number. Only letters and numbers allowed.'=>'訂單編號前綴僅可使用英文字母及數字。',
        'You can issue the e-invoice manually even if you choose Automatic mode'=>'選擇自動開立後，仍可於訂單中手動開立發票。',
        'When order status changes to the status, the e-invoice will be issued automatically.'=>'訂單變更為所選狀態時，自動開立電子發票。',
        'When order status changes to the status, the e-invoice will be invalid automatically.'=>'訂單變更為所選狀態時，自動作廢電子發票。',
        'Invoice Type'=>'發票類型', 'individual'=>'個人', 'company'=>'公司', 'donate'=>'捐贈',
        'Individual Invoice Type'=>'個人發票載具', 'Carrier Number'=>'載具號碼',
        'TaxID'=>'統一編號', 'Company Name'=>'公司名稱', 'Donate Number'=>'捐贈碼',
        'Invoice number'=>'發票號碼', 'Generate invoice'=>'開立發票', 'Invalid invoice'=>'作廢發票',
        'Order no prefix'=>'訂單編號前綴',
        'The prefix string of order no. Only letters and numbers allowed allowed.'=>'訂單編號前綴僅可使用英文字母及數字。',
        'API credentials'=>'商店串接資料', 'NewebPay gateway sandbox'=>'藍新測試模式',
        'Enable NewebPay gateway sandbox'=>'啟用藍新測試模式',
        'Log NewebPay gateway events/message, inside %s'=>'記錄藍新金流事件及訊息於 %s',
        'Minimum order amount'=>'最低訂單金額', 'Maximum order amount'=>'最高訂單金額',
        '0 to disable minimum amount limit.'=>'設為 0 表示不限制最低金額。',
        '0 to disable maximum amount limit.'=>'設為 0 表示不限制最高金額。',
        'Payment deadline'=>'付款期限', 'Enable number of periods'=>'啟用的分期期數', '%d periods'=>'%d 期',
        'NewebPay'=>'藍新金流', 'NewebPay Credit'=>'藍新信用卡',
        'NewebPay Credit(installment)'=>'藍新信用卡分期', 'NewebPay ATM'=>'藍新 ATM 轉帳',
        'NewebPay WebATM'=>'藍新網路 ATM', 'NewebPay CVS'=>'藍新超商代碼繳費',
        'NewebPay BARCODE'=>'藍新超商條碼繳費', 'NewebPay shipping CVS'=>'藍新超商取貨',
        'Pay via Credit'=>'使用信用卡付款', 'Pay via NewebPay Credit'=>'使用藍新信用卡付款',
        'Pay via Credit(installment)'=>'使用信用卡分期付款', 'Pay via NewebPay Credit(installment)'=>'使用藍新信用卡分期付款',
        'Pay via ATM'=>'使用 ATM 轉帳', 'Pay via NewebPay ATM'=>'使用藍新 ATM 轉帳',
        'Pay via WebATM'=>'使用網路 ATM 付款', 'Pay via NewebPay WebATM'=>'使用藍新網路 ATM 付款',
        'Pay via CVS'=>'使用超商代碼繳費', 'Pay via NewebPay CVS'=>'使用藍新超商代碼繳費',
        'Pay via BARCODE'=>'使用超商條碼繳費', 'Pay via NewebPay BARCODE'=>'使用藍新超商條碼繳費',
        'Because NewebPay limit, the shipping note no or shipping status can not show in site admin.'=>'因藍新服務限制，網站後台無法顯示物流單號及物流狀態，請至服務商後台查詢。',
        'cvs remove billing address'=>'超商取貨隱藏帳單地址',
        'Remove billing address when shipping mode is cvs.'=>'選擇超商取貨時隱藏帳單地址欄位。',
        'The billing address still will show in order details.'=>'訂單明細仍會顯示帳單地址。',
    ];
    return $labels[$text] ?? $translated;
}
// Only our invoice domains. Never intercept WooCommerce or the default domain.
foreach (['wu','paynow-einvoice','wu-newebpay-tools'] as $wutm_invoice_domain) {
    add_filter('gettext_'.$wutm_invoice_domain, 'wutm_invoice_label', 20, 3);
}
unset($wutm_invoice_domain);
// Older installations stored untranslated carrier names. Normalize on read only;
// do not rewrite saved settings or existing orders merely by opening a page.
add_filter('option_wc_woomp_ezpay_invoice_carrier_type', static function ($value) {
    return is_array($value) ? array_values(array_unique(array_map('wutm_invoice_carrier', $value))) : $value;
});
