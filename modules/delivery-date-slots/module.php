<?php
/**
 * 中文交貨日期與時段模組。
 *
 * 支援 WooCommerce 傳統與區塊結帳頁的日期、時段選擇、訂單儲存與通知顯示。
 */
defined('ABSPATH') || exit;

final class WUTM_Delivery_Date_Slots {
    private const OPTION = 'wutm_delivery_date_slots';

    public function __construct() {
        if (!class_exists('WooCommerce')) return;

        add_action('admin_menu', [$this, 'register_menu'], 20);
        add_action('woocommerce_init', [$this, 'register_block_checkout_fields']);
        add_filter('woocommerce_checkout_fields', [$this, 'add_checkout_fields']);
        add_action('woocommerce_after_checkout_validation', [$this, 'validate_checkout'], 10, 2);
        add_action('woocommerce_checkout_create_order', [$this, 'save_order_fields'], 10, 2);
        add_action('woocommerce_admin_order_data_after_shipping_address', [$this, 'show_in_admin']);
        add_filter('woocommerce_email_order_meta_fields', [$this, 'show_in_emails'], 10, 3);
    }

    private function defaults(): array {
        return [
            'enabled' => 1,
            'lead_days' => 1,
            'max_days' => 30,
            'disabled_weekdays' => ['0'],
            'slots' => "上午 09:00 - 12:00\n下午 13:00 - 17:00\n晚上 18:00 - 21:00",
        ];
    }

    private function settings(): array {
        return wp_parse_args((array) get_option(self::OPTION, []), $this->defaults());
    }

    private function slots(array $settings = []): array {
        $settings = $settings ?: $this->settings();
        $slots = preg_split('/\r\n|\r|\n/', (string) $settings['slots']);
        return array_values(array_filter(array_map('trim', $slots)));
    }

    public function register_menu(): void {
        add_submenu_page('wu-toolbox-modular', '交貨日期和時段', '交貨日期和時段', 'manage_woocommerce', 'wu-delivery-date-slots', [$this, 'render_settings']);
    }

    public function render_settings(): void {
        if (!current_user_can('manage_woocommerce')) wp_die(esc_html__('您沒有管理此設定的權限。', 'wu-toolbox-modular'));
        $settings = $this->settings();
        if (isset($_POST['wutm_delivery_save'])) {
            check_admin_referer('wutm_delivery_date_slots');
            $settings = [
                'enabled' => isset($_POST['enabled']) ? 1 : 0,
                'lead_days' => min(90, max(0, absint($_POST['lead_days'] ?? 1))),
                'max_days' => min(365, max(1, absint($_POST['max_days'] ?? 30))),
                'disabled_weekdays' => array_values(array_intersect(range(0, 6), array_map('absint', (array) ($_POST['disabled_weekdays'] ?? [])))),
                'slots' => sanitize_textarea_field(wp_unslash($_POST['slots'] ?? '')),
            ];
            update_option(self::OPTION, $settings, false);
            echo '<div class="notice notice-success"><p>交貨日期和時段設定已儲存。</p></div>';
        }

        $weekdays = ['日', '一', '二', '三', '四', '五', '六'];
        echo '<div class="wrap"><h1>交貨日期和時段</h1><p>讓顧客在結帳時選擇可交貨日期與時段。日期限制會依「最早交貨天數」及停用星期自動套用。</p><form method="post">';
        wp_nonce_field('wutm_delivery_date_slots');
        echo '<table class="form-table"><tr><th>啟用交貨選擇</th><td><label><input type="checkbox" name="enabled" value="1" ' . checked((bool) $settings['enabled'], true, false) . '> 在結帳頁顯示交貨日期與時段</label></td></tr>';
        echo '<tr><th><label for="lead_days">最早交貨天數</label></th><td><input type="number" id="lead_days" name="lead_days" min="0" max="90" value="' . esc_attr((string) $settings['lead_days']) . '"> 天 <p class="description">例如設為 1，今天下單最快明天交貨。</p></td></tr>';
        echo '<tr><th><label for="max_days">可預約天數</label></th><td><input type="number" id="max_days" name="max_days" min="1" max="365" value="' . esc_attr((string) $settings['max_days']) . '"> 天</td></tr>';
        echo '<tr><th>不交貨星期</th><td>';
        foreach ($weekdays as $number => $label) {
            echo '<label style="margin-right:12px"><input type="checkbox" name="disabled_weekdays[]" value="' . esc_attr((string) $number) . '" ' . checked(in_array($number, array_map('intval', (array) $settings['disabled_weekdays']), true), true, false) . '> 週' . esc_html($label) . '</label>';
        }
        echo '</td></tr><tr><th><label for="slots">交貨時段</label></th><td><textarea class="large-text" rows="5" id="slots" name="slots">' . esc_textarea((string) $settings['slots']) . '</textarea><p class="description">每行一個時段，例如：上午 09:00 - 12:00。</p></td></tr></table>';
        submit_button('儲存設定', 'primary', 'wutm_delivery_save');
        echo '</form></div>';
    }

    private function first_available_date(array $settings): string {
        $date = new DateTimeImmutable('today', wp_timezone());
        $date = $date->modify('+' . absint($settings['lead_days']) . ' days');
        $blocked = array_map('intval', (array) $settings['disabled_weekdays']);
        for ($attempt = 0; $attempt < 14; $attempt++) {
            if (!in_array((int) $date->format('w'), $blocked, true)) return $date->format('Y-m-d');
            $date = $date->modify('+1 day');
        }
        return $date->format('Y-m-d');
    }

    /** Register native Checkout Block fields (WooCommerce 8.9+). */
    public function register_block_checkout_fields(): void {
        if (!function_exists('woocommerce_register_additional_checkout_field')) return;
        $settings = $this->settings();
        if (empty($settings['enabled'])) return;

        $dates = [];
        $cursor = new DateTimeImmutable($this->first_available_date($settings), wp_timezone());
        $last = $cursor->modify('+' . absint($settings['max_days']) . ' days');
        $blocked = array_map('intval', (array) $settings['disabled_weekdays']);
        while ($cursor <= $last) {
            if (!in_array((int) $cursor->format('w'), $blocked, true)) {
                $value = $cursor->format('Y-m-d');
                $dates[] = ['value' => $value, 'label' => $cursor->format('Y/m/d')];
            }
            $cursor = $cursor->modify('+1 day');
        }

        $slots = [];
        foreach ($this->slots($settings) as $slot) $slots[] = ['value' => $slot, 'label' => $slot];

        woocommerce_register_additional_checkout_field([
            'id' => 'wutm/delivery-date',
            'label' => '交貨日期',
            'location' => 'order',
            'type' => 'select',
            'required' => true,
            'options' => $dates,
        ]);
        woocommerce_register_additional_checkout_field([
            'id' => 'wutm/delivery-slot',
            'label' => '交貨時段',
            'location' => 'order',
            'type' => 'select',
            'required' => true,
            'options' => $slots,
        ]);
    }

    public function add_checkout_fields(array $fields): array {
        $settings = $this->settings();
        if (empty($settings['enabled'])) return $fields;
        $min = $this->first_available_date($settings);
        $max = (new DateTimeImmutable($min, wp_timezone()))->modify('+' . absint($settings['max_days']) . ' days')->format('Y-m-d');
        $options = ['' => '請選擇交貨時段'];
        foreach ($this->slots($settings) as $slot) $options[$slot] = $slot;

        $fields['order']['wutm_delivery_date'] = [
            'type' => 'date', 'label' => '交貨日期', 'required' => true, 'class' => ['form-row-first'],
            'priority' => 120, 'custom_attributes' => ['min' => $min, 'max' => $max],
        ];
        $fields['order']['wutm_delivery_slot'] = [
            'type' => 'select', 'label' => '交貨時段', 'required' => true, 'class' => ['form-row-last'],
            'priority' => 121, 'options' => $options,
        ];
        return $fields;
    }

    public function validate_checkout(array $data, WP_Error $errors): void {
        $settings = $this->settings();
        if (empty($settings['enabled'])) return;
        $date = sanitize_text_field(wp_unslash($_POST['wutm_delivery_date'] ?? ''));
        $slot = sanitize_text_field(wp_unslash($_POST['wutm_delivery_slot'] ?? ''));
        if (!$date || !$slot) {
            $errors->add('wutm_delivery_required', '請選擇交貨日期與時段。');
            return;
        }
        $selected = DateTimeImmutable::createFromFormat('!Y-m-d', $date, wp_timezone());
        $minimum = new DateTimeImmutable($this->first_available_date($settings), wp_timezone());
        if (!$selected || $selected < $minimum || in_array((int) $selected->format('w'), array_map('intval', (array) $settings['disabled_weekdays']), true)) {
            $errors->add('wutm_delivery_date_invalid', '請選擇可交貨的日期。');
        }
        if (!in_array($slot, $this->slots($settings), true)) $errors->add('wutm_delivery_slot_invalid', '請選擇有效的交貨時段。');
    }

    public function save_order_fields(WC_Order $order, array $data): void {
        foreach (['wutm_delivery_date' => '交貨日期', 'wutm_delivery_slot' => '交貨時段'] as $field => $label) {
            if (!empty($_POST[$field])) $order->update_meta_data('_' . $field, sanitize_text_field(wp_unslash($_POST[$field])));
        }
    }

    public function show_in_admin(WC_Order $order): void {
        [$date, $slot] = $this->order_values($order);
        if (!$date && !$slot) return;
        echo '<p><strong>交貨安排：</strong><br>' . esc_html($date) . ($slot ? '　' . esc_html($slot) : '') . '</p>';
    }

    public function show_in_emails(array $fields, bool $sent_to_admin, WC_Order $order): array {
        [$date, $slot] = $this->order_values($order);
        if ($date) $fields['wutm_delivery_date'] = ['label' => '交貨日期', 'value' => $date];
        if ($slot) $fields['wutm_delivery_slot'] = ['label' => '交貨時段', 'value' => $slot];
        return $fields;
    }

    private function order_values(WC_Order $order): array {
        $date = (string) $order->get_meta('_wutm_delivery_date');
        $slot = (string) $order->get_meta('_wutm_delivery_slot');
        // Additional Checkout Fields store order-location values with this key.
        if (!$date) $date = (string) $order->get_meta('_wc_other/wutm/delivery-date');
        if (!$slot) $slot = (string) $order->get_meta('_wc_other/wutm/delivery-slot');
        return [$date, $slot];
    }
}

new WUTM_Delivery_Date_Slots();
