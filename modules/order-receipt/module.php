<?php
/** WooCommerce 訂單收據列印工具。 */
defined('ABSPATH') || exit;

final class WUTM_Order_Receipt {
    private const PAGE = 'wu-order-receipt';
    private const OPTION = 'wutm_order_receipt_options';
    private $options;

    public function __construct() {
        if (!class_exists('WooCommerce')) return;
        $this->options = wp_parse_args((array) get_option(self::OPTION, array()), array(
            'customer_print' => true,
            'company_name' => get_bloginfo('name'),
            'seller_tax_id' => '',
            'receipt_title' => '銷售收據／明細',
            'show_billing_company' => true,
            'show_payment_method' => true,
            'show_shipping_address' => true,
            'show_customer_note' => true,
            'footer_note' => '感謝您的惠顧！本收據僅作為銷售明細核對使用。',
        ));

        add_action('admin_menu', array($this, 'menu'));
        add_action('admin_init', array($this, 'settings'));
        add_filter('manage_edit-shop_order_columns', array($this, 'add_column'));
        add_action('manage_shop_order_posts_custom_column', array($this, 'legacy_column'), 10, 2);
        add_filter('manage_woocommerce_page_wc-orders_columns', array($this, 'add_column'));
        add_action('manage_woocommerce_page_wc-orders_custom_column', array($this, 'hpos_column'), 10, 2);
        add_action('woocommerce_admin_order_data_after_order_details', array($this, 'admin_order_button'));
        add_filter('woocommerce_my_account_my_orders_actions', array($this, 'account_action'), 10, 2);
        add_action('woocommerce_view_order', array($this, 'view_order_button'), 10);
        add_action('woocommerce_thankyou', array($this, 'thankyou_button'), 30);
        add_action('admin_post_wutm_print_order_receipt', array($this, 'print_receipt'));
        add_action('admin_post_nopriv_wutm_print_order_receipt', array($this, 'print_receipt'));
    }

    public function menu(): void {
        add_submenu_page('wu-toolbox-modular', '列印訂單收據', '列印訂單收據', 'manage_woocommerce', self::PAGE, array($this, 'settings_page'));
    }

    public function settings(): void {
        register_setting('wutm_order_receipt_group', self::OPTION, array('sanitize_callback' => array($this, 'sanitize')));
    }

    public function sanitize($input): array {
        $input = is_array($input) ? $input : array();
        return array(
            'customer_print' => !empty($input['customer_print']),
            'company_name' => sanitize_text_field(wp_unslash($input['company_name'] ?? '')),
            'seller_tax_id' => preg_replace('/[^0-9A-Za-z-]/', '', (string) wp_unslash($input['seller_tax_id'] ?? '')),
            'receipt_title' => sanitize_text_field(wp_unslash($input['receipt_title'] ?? '')),
            'show_billing_company' => !empty($input['show_billing_company']),
            'show_payment_method' => !empty($input['show_payment_method']),
            'show_shipping_address' => !empty($input['show_shipping_address']),
            'show_customer_note' => !empty($input['show_customer_note']),
            'footer_note' => sanitize_textarea_field(wp_unslash($input['footer_note'] ?? '')),
        );
    }

    public function settings_page(): void {
        if (!current_user_can('manage_woocommerce')) wp_die('您沒有管理訂單收據的權限。');
        $o = $this->options;
        ?>
        <div class="wrap"><h1>列印訂單收據</h1><p>設定收據抬頭、顯示內容，以及顧客是否能從會員中心列印自己的訂單收據。</p>
            <form method="post" action="options.php"><div style="max-width:850px;padding:20px 24px;background:#fff;border:1px solid #c3c4c7;border-radius:6px">
                <?php settings_fields('wutm_order_receipt_group'); ?>
                <table class="form-table"><tr><th>開放顧客列印</th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[customer_print]" value="1" <?php checked(!empty($o['customer_print'])); ?>> 顧客可在「我的帳號 → 訂單」列印自己的收據</label></td></tr>
                    <tr><th><label for="wutm-receipt-company">公司／品牌名稱</label></th><td><input id="wutm-receipt-company" class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[company_name]" value="<?php echo esc_attr($o['company_name']); ?>" placeholder="<?php echo esc_attr(get_bloginfo('name')); ?>"></td></tr>
                    <tr><th><label for="wutm-receipt-tax-id">賣方統一編號</label></th><td><input id="wutm-receipt-tax-id" class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[seller_tax_id]" value="<?php echo esc_attr($o['seller_tax_id']); ?>"><p class="description">留空即不顯示。</p></td></tr>
                    <tr><th><label for="wutm-receipt-title">收據標題</label></th><td><input id="wutm-receipt-title" class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[receipt_title]" value="<?php echo esc_attr($o['receipt_title']); ?>"></td></tr>
                    <tr><th>收據顯示內容</th><td><?php foreach (array('show_billing_company' => '買方公司名稱／統編', 'show_payment_method' => '付款方式', 'show_shipping_address' => '完整收件地址', 'show_customer_note' => '顧客訂單備註') as $key => $label) : ?><label style="display:block;margin-bottom:8px"><input type="checkbox" name="<?php echo esc_attr(self::OPTION . '[' . $key . ']'); ?>" value="1" <?php checked(!empty($o[$key])); ?>> <?php echo esc_html($label); ?></label><?php endforeach; ?></td></tr>
                    <tr><th><label for="wutm-receipt-footer">頁尾備註</label></th><td><textarea id="wutm-receipt-footer" class="large-text" rows="4" name="<?php echo esc_attr(self::OPTION); ?>[footer_note]"><?php echo esc_textarea($o['footer_note']); ?></textarea></td></tr>
                </table><?php submit_button('儲存收據設定'); ?>
            </div></form>
        </div>
        <?php
    }

    public function add_column(array $columns): array {
        $columns['wutm_print_receipt'] = '列印收據';
        return $columns;
    }

    public function legacy_column(string $column, $post_id): void {
        if ($column !== 'wutm_print_receipt') return;
        $order = wc_get_order($post_id);
        if ($order) $this->button($order, true, '列印收據');
    }

    public function hpos_column(string $column, $order): void {
        if ($column === 'wutm_print_receipt' && $order instanceof WC_Order) $this->button($order, true, '列印收據');
    }

    public function admin_order_button($order): void {
        if (!($order instanceof WC_Order)) return;
        echo '<p class="form-field form-field-wide"><strong>訂單收據</strong><br>';
        $this->button($order, true, '列印此筆收據');
        echo '</p>';
    }

    public function account_action(array $actions, $order): array {
        if (!empty($this->options['customer_print']) && $order instanceof WC_Order) {
            $actions['wutm_print_receipt'] = array('url' => $this->url($order), 'name' => '列印收據');
        }
        return $actions;
    }

    public function view_order_button($order_id): void {
        if (empty($this->options['customer_print'])) return;
        $order = wc_get_order($order_id);
        if ($order) echo '<p class="wutm-receipt-action"><a class="button" target="_blank" rel="noopener" href="' . esc_url($this->url($order)) . '">列印訂單收據</a></p>';
    }

    public function thankyou_button($order_id): void {
        if (empty($this->options['customer_print'])) return;
        $order = wc_get_order($order_id);
        if ($order) echo '<p class="wutm-receipt-action"><a class="button" target="_blank" rel="noopener" href="' . esc_url($this->url($order, true)) . '">列印訂單收據</a></p>';
    }

    private function button(WC_Order $order, bool $admin, string $label): void {
        echo '<a class="button button-small" target="_blank" rel="noopener" style="white-space:nowrap" href="' . esc_url($this->url($order, false, $admin)) . '">' . esc_html($label) . '</a>';
    }

    private function url(WC_Order $order, bool $with_key = false, bool $admin = false): string {
        $args = array('action' => 'wutm_print_order_receipt', 'order_id' => $order->get_id());
        if ($with_key || (!$admin && !$order->get_user_id())) $args['order_key'] = $order->get_order_key();
        $url = add_query_arg($args, admin_url('admin-post.php'));
        return wp_nonce_url($url, 'wutm_print_receipt_' . $order->get_id(), 'receipt_nonce');
    }

    public function print_receipt(): void {
        $order_id = absint($_GET['order_id'] ?? 0);
        $nonce = sanitize_text_field(wp_unslash($_GET['receipt_nonce'] ?? ''));
        if (!$order_id || !wp_verify_nonce($nonce, 'wutm_print_receipt_' . $order_id)) wp_die('收據連結無效或已過期。', '無法列印收據', array('response' => 403));
        $order = wc_get_order($order_id);
        if (!($order instanceof WC_Order)) wp_die('找不到該筆訂單。', '無法列印收據', array('response' => 404));

        $allowed = current_user_can('manage_woocommerce');
        if (!$allowed && !empty($this->options['customer_print'])) {
            $allowed = $order->get_user_id() > 0
                ? get_current_user_id() === $order->get_user_id()
                : hash_equals($order->get_order_key(), sanitize_text_field(wp_unslash($_GET['order_key'] ?? '')));
        }
        if (!$allowed) wp_die('您沒有權限檢視或列印此收據。', '無法列印收據', array('response' => 403));

        nocache_headers();
        header('Content-Type: text/html; charset=' . get_bloginfo('charset'));
        $this->document($order);
        exit;
    }

    private function document(WC_Order $order): void {
        $o = $this->options;
        $company = $o['company_name'] !== '' ? $o['company_name'] : get_bloginfo('name');
        $date = $order->get_date_created();
        $shipping = $order->get_formatted_shipping_address();
        $buyer_company = $this->buyer_company($order);
        $buyer_tax_id = $this->buyer_tax_id($order);
        ?>
<!doctype html><html lang="zh-Hant"><head><meta charset="<?php echo esc_attr(get_bloginfo('charset')); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title><?php echo esc_html($o['receipt_title'] . ' #' . $order->get_order_number()); ?></title>
<style>*{box-sizing:border-box}body{margin:0;padding:36px 16px;background:#f4f6f8;color:#1e293b;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang TC","Microsoft JhengHei",Arial,sans-serif;-webkit-font-smoothing:antialiased}.actions{text-align:center;margin-bottom:24px}.print-btn{padding:12px 34px;border:1px solid #0f172a;border-radius:4px;background:#0f172a;color:#fff;font:600 15px inherit;cursor:pointer}.print-btn:hover{background:#1e293b}.receipt{max-width:960px;margin:auto;padding:50px 56px;background:#fff;border:1px solid #e2e8f0;border-radius:5px;box-shadow:0 4px 14px rgba(15,23,42,.05)}.header{display:flex;justify-content:space-between;gap:28px;align-items:flex-start;padding-bottom:24px;margin-bottom:30px;border-bottom:2px solid #0f172a}.brand h1{margin:0 0 7px;color:#0f172a;font-size:27px}.muted{color:#64748b;font-size:13px;line-height:1.65}.title{text-align:right}.title h2{margin:0 0 7px;font-size:23px;color:#1e293b;letter-spacing:.5px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:22px;margin-bottom:34px}.info{padding:18px 22px;background:#f8fafc;border:1px solid #eef2f7;border-radius:5px;font-size:14px;line-height:1.8}.info strong{color:#0f172a}table{width:100%;border-collapse:collapse;margin-bottom:28px;font-size:14px}th{padding:13px 15px;background:#f8fafc;border-top:1px solid #e2e8f0;border-bottom:2px solid #cbd5e1;color:#475569;text-align:left}td{padding:15px;border-bottom:1px solid #f1f5f9;vertical-align:top}.right{text-align:right}.center{text-align:center}.item{font-weight:600;color:#0f172a}.meta{margin-top:4px;color:#64748b;font-size:12px}.totals{width:360px;max-width:100%;margin:0 0 34px auto}.total-row{display:flex;justify-content:space-between;gap:18px;padding:7px 0;color:#475569}.total-row.grand{margin-top:7px;padding-top:13px;border-top:2px solid #0f172a;color:#0f172a;font-size:18px;font-weight:700}.note{margin-bottom:28px}.footer{padding-top:24px;border-top:1px dashed #cbd5e1;color:#64748b;text-align:center;font-size:13px;line-height:1.75}@media(max-width:680px){body{padding:16px 8px}.receipt{padding:28px 20px}.header{display:block}.title{text-align:left;margin-top:22px}.grid{grid-template-columns:1fr}table{font-size:12px}th,td{padding:10px 7px}}@media print{@page{margin:14mm}body{padding:0;background:#fff}.actions{display:none}.receipt{width:100%;max-width:none;padding:0;border:0;box-shadow:none}}</style></head><body><div class="actions"><button class="print-btn" onclick="window.print()">列印收據</button></div><main class="receipt">
<header class="header"><div class="brand"><h1><?php echo esc_html($company); ?></h1><?php if ($o['seller_tax_id'] !== '') : ?><div class="muted">統一編號：<?php echo esc_html($o['seller_tax_id']); ?></div><?php endif; ?></div><div class="title"><h2><?php echo esc_html($o['receipt_title']); ?></h2><div class="muted">訂單編號：#<?php echo esc_html($order->get_order_number()); ?><br>開立日期：<?php echo esc_html($date ? wc_format_datetime($date, 'Y-m-d H:i') : '—'); ?></div></div></header>
<section class="grid"><div class="info"><strong>買受人資訊</strong><br>姓名：<?php echo esc_html($order->get_formatted_billing_full_name() ?: '—'); ?><br>電話：<?php echo esc_html($order->get_billing_phone() ?: '—'); ?><br>電子郵件：<?php echo esc_html($order->get_billing_email() ?: '—'); ?><?php if (!empty($o['show_billing_company']) && ($buyer_company || $buyer_tax_id)) : ?><br>買方抬頭：<?php echo esc_html($buyer_company ?: '—'); ?><?php if ($buyer_tax_id) : ?><br>買方統編：<?php echo esc_html($buyer_tax_id); ?><?php endif; ?><?php endif; ?></div><div class="info"><strong>交易與配送詳情</strong><br>訂單狀態：<?php echo esc_html(wc_get_order_status_name($order->get_status())); ?><?php if (!empty($o['show_payment_method'])) : ?><br>付款方式：<?php echo esc_html($order->get_payment_method_title() ?: '—'); ?><?php endif; ?><?php if (!empty($o['show_shipping_address'])) : ?><br>收件地址：<?php echo $shipping ? wp_kses_post($shipping) : '—'; ?><?php endif; ?></div></section>
<table><thead><tr><th style="width:50%">商品名稱</th><th class="right" style="width:17%">單價</th><th class="center" style="width:13%">數量</th><th class="right" style="width:20%">小計</th></tr></thead><tbody><?php foreach ($order->get_items('line_item') as $item) : $quantity=(float)$item->get_quantity(); $line_total=(float)$item->get_total(); $unit=$quantity != 0.0 ? $line_total/$quantity : 0; ?><tr><td><div class="item"><?php echo esc_html($item->get_name()); ?></div><?php $meta=wc_display_item_meta($item,array('echo'=>false)); if($meta): ?><div class="meta"><?php echo wp_kses_post($meta); ?></div><?php endif; ?></td><td class="right"><?php echo wp_kses_post(wc_price($unit,array('currency'=>$order->get_currency()))); ?></td><td class="center"><?php echo esc_html(wc_format_decimal($quantity)); ?></td><td class="right"><?php echo wp_kses_post($order->get_formatted_line_subtotal($item)); ?></td></tr><?php endforeach; ?></tbody></table>
<section class="totals"><?php foreach ($order->get_order_item_totals() as $key => $total) : $total_label=preg_replace('/[:：]\s*$/u','',(string)$total['label']); ?><div class="total-row <?php echo $key === 'order_total' ? 'grand' : ''; ?>"><span><?php echo wp_kses_post($total_label); ?></span><span><?php echo wp_kses_post($total['value']); ?></span></div><?php endforeach; ?></section>
<?php if (!empty($o['show_customer_note']) && $order->get_customer_note()) : ?><section class="info note"><strong>顧客備註</strong><br><?php echo nl2br(esc_html($order->get_customer_note())); ?></section><?php endif; ?><?php if ($o['footer_note'] !== '') : ?><footer class="footer"><?php echo nl2br(esc_html($o['footer_note'])); ?></footer><?php endif; ?></main></body></html>
        <?php
    }

    private function buyer_tax_id(WC_Order $order): string {
        foreach (array('_einvoice_tax_id', '統一編號', '_billing_tax_id', '_billing_uniform_numbers', 'billing_invoice_tax_id', '_billing_vat_number', '_billing_gui_number') as $key) {
            $value = sanitize_text_field((string) $order->get_meta($key));
            if ($value !== '') return $value;
        }
        return '';
    }

    private function buyer_company(WC_Order $order): string {
        $company = sanitize_text_field((string) $order->get_billing_company());
        if ($company !== '') return $company;
        return sanitize_text_field((string) $order->get_meta('_einvoice_company_name'));
    }
}

new WUTM_Order_Receipt();
