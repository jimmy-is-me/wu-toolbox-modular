<?php
defined('ABSPATH') || exit;

// Keep historical order details readable without loading the retired payment gateway.
add_action('woocommerce_admin_order_data_after_order_details', function ($order): void {
    if (!($order instanceof WC_Order)) return;
    if (!in_array($order->get_payment_method(), ['esun-credit', 'esun-installment'], true)) return;

    $fields = [
        '交易狀態' => '_esun_credit_trans_status',
        '交易日期' => '_esun_credit_trade_date',
        '交易時間' => '_esun_credit_trade_time',
        '交易序號' => '_esun_credit_rrn',
        '授權碼' => '_esun_credit_air',
        '卡號末碼資料' => '_esun_credit_an',
    ];
    if ($order->get_payment_method() === 'esun-installment') {
        $fields += [
            '分期期數' => '_esun_credit_installment_number',
            '分期總金額' => '_esun_credit_installment_total_amount',
            '每期金額' => '_esun_credit_installment_each_amount',
            '首期金額' => '_esun_credit_installment_first_amount',
        ];
    }
    $fields += [
        '錯誤代碼' => '_esun_credit_error_no',
        '錯誤說明' => '_esun_credit_error_desc',
    ];

    echo '<div class="wutm-legacy-esun-order"><h3>歷史玉山交易資料（唯讀）</h3>';
    echo '<p>玉山付款方式已下架；既有訂單及付款紀錄不受影響。</p>';
    echo '<table class="widefat striped"><tbody>';
    foreach ($fields as $label => $meta_key) {
        $value = $order->get_meta($meta_key);
        if ($value === '' || $value === null) continue;
        echo '<tr><th scope="row">' . esc_html($label) . '</th><td>' . esc_html((string) $value) . '</td></tr>';
    }
    echo '</tbody></table></div>';
}, 10, 1);
