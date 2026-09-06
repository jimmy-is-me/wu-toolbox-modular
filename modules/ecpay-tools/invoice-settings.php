<?php
defined('ABSPATH') || exit;
class WUTM_ECPay_Invoice_Settings extends WC_Settings_Page {
    public function __construct() {
        $this->id = 'wutm_ecpay_invoice';
        $this->label = '綠界電子發票';
        parent::__construct();
    }
    public function get_settings($section = '') {
        $prefix = 'wc_woomp_ecpay_invoice_';
        return array(
            array('title'=>'綠界電子發票','type'=>'title','id'=>'wutm_invoice'),
            array('title'=>'啟用電子發票','type'=>'checkbox','id'=>'wc_woomp_enabled_ecpay_invoice','default'=>'no'),
            array('title'=>'測試模式','type'=>'checkbox','id'=>$prefix.'testmode_enabled','default'=>'yes','desc'=>'正式上線前請先驗證測試開立與作廢。'),
            array('title'=>'商家編號','type'=>'text','id'=>$prefix.'merchant_id'),
            array('title'=>'HashKey','type'=>'password','id'=>$prefix.'hashkey'),
            array('title'=>'HashIV','type'=>'password','id'=>$prefix.'hashiv'),
            array('title'=>'訂單編號前綴','type'=>'text','id'=>$prefix.'order_prefix'),
            array('title'=>'開立方式','type'=>'select','id'=>$prefix.'issue_mode','default'=>'manual','options'=>array('manual'=>'手動','auto'=>'自動')),
            array('title'=>'自動開立訂單狀態','type'=>'select','id'=>$prefix.'issue_at','default'=>'wc-completed','options'=>wc_get_order_statuses()),
            array('title'=>'作廢方式','type'=>'select','id'=>$prefix.'invalid_mode','default'=>'manual','options'=>array('manual'=>'手動','auto'=>'自動')),
            array('title'=>'自動作廢訂單狀態','type'=>'select','id'=>$prefix.'invalid_at','default'=>'wc-refunded','options'=>array('wc-refunded'=>'已退款','wc-failed'=>'失敗')),
            array('title'=>'可用載具','type'=>'multiselect','id'=>$prefix.'carrier_type','default'=>array('雲端發票','手機條碼','自然人憑證','紙本發票'),'options'=>array('雲端發票'=>'雲端發票','手機條碼'=>'手機條碼','自然人憑證'=>'自然人憑證','紙本發票'=>'紙本發票')),
            array('title'=>'捐贈機構','type'=>'textarea','id'=>$prefix.'donate_org','desc'=>'每行一筆：愛心碼|機構名稱'),
            array('title'=>'除錯日誌','type'=>'checkbox','id'=>$prefix.'debug_log_enabled','default'=>'no'),
            array('type'=>'sectionend','id'=>'wutm_invoice')
        );
    }
}
