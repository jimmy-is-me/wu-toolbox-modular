<?php
/** Product-led live shipping estimates. No cron, polling or whole-order migrations. */
defined('ABSPATH') || exit;

final class WUTM_Shipping_Progress {
    private const PRODUCT_META = '_wutm_shipping_schedule';
    private const OVERRIDE_META = '_wutm_shipping_schedule_override';
    private const SNAPSHOT_META = '_wutm_shipping_schedule_snapshot';
    private const TRACKING_META = '_wutm_shipping_tracking';
    private const OPTION = 'wutm_shipping_progress_options';
    private $options;
    private $product_plans = array();
    private $verified_orders = array();

    public function __construct() {
        $saved = get_option(self::OPTION, array());
        $this->options = wp_parse_args(is_array($saved) ? $saved : array(), array('page_id' => 0, 'replace_tracking' => 0, 'enable_tracking' => 0));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'admin_assets'));
        add_action('add_meta_boxes', array($this, 'meta_boxes'));
        add_action('woocommerce_admin_process_product_object', array($this, 'save_product'));
        add_action('woocommerce_product_after_variable_attributes', array($this, 'variation_fields'), 10, 3);
        add_action('woocommerce_admin_process_variation_object', array($this, 'save_variation'), 10, 2);
        add_action('woocommerce_process_shop_order_meta', array($this, 'save_order'), 30);
        add_action('admin_post_wutm_sp_save_product', array($this, 'save_product_form'));
        add_action('admin_post_wutm_sp_create_page', array($this, 'create_page'));
        add_action('woocommerce_checkout_create_order_line_item', array($this, 'snapshot'), 20, 4);
        add_action('woocommerce_order_details_after_order_table', array($this, 'order_details'));
        // WooCommerce fires this action only after its order/email verification.
        add_action('woocommerce_track_order', array($this, 'native_tracking_verified'));
        add_shortcode('wutm_shipping_progress', array($this, 'shortcode'));
        add_filter('pre_do_shortcode_tag', array($this, 'replace_tracking'), 10, 2);
        add_action('template_redirect', array($this, 'private_page'));
        add_action('wp_enqueue_scripts', array($this, 'frontend_assets'));
    }

    private function blank(): array {
        return array('ship_from' => '', 'ship_to' => '', 'arrival_from' => '', 'arrival_to' => '', 'status' => 'preparing', 'message' => '', 'updated_at' => '');
    }

    private function statuses(bool $order = false): array {
        $statuses = array('preparing' => '備貨中', 'delayed' => '時程調整／延後');
        if ($order) $statuses += array('shipped' => '已出貨', 'delivered' => '已送達');
        return $statuses;
    }

    private function text($value): string {
        return is_scalar($value) ? (string) wp_unslash($value) : '';
    }

    /** Validate the whole schedule before writing any fields. */
    public function sanitize_plan($input, bool $order = false, bool $unslash = true) {
        if (!is_array($input)) return new WP_Error('invalid_plan', '預計時間格式不正確，原資料未變更。');
        $plan = $this->blank();
        foreach (array('ship_from', 'ship_to', 'arrival_from', 'arrival_to') as $key) {
            $raw = $input[$key] ?? '';
            if (!is_scalar($raw)) return new WP_Error('invalid_date', '日期格式不正確，原預計時間未變更。');
            $value = trim($unslash ? $this->text($raw) : (string) $raw);
            if ($value !== '') {
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) || (int) substr($value, 0, 4) < 1) return new WP_Error('invalid_date', '請使用有效的日期（年／月／日），原預計時間未變更。');
                $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
                if (!$date || $date->format('Y-m-d') !== $value) {
                    return new WP_Error('invalid_date', '請使用有效的日期（年／月／日），原預計時間未變更。');
                }
            }
            $plan[$key] = $value;
        }
        foreach (array('ship', 'arrival') as $range) {
            if ($plan[$range . '_from'] && $plan[$range . '_to'] && $plan[$range . '_from'] > $plan[$range . '_to']) {
                return new WP_Error('invalid_range', '日期區間的結束日不可早於開始日，原預計時間未變更。');
            }
        }
        if ($plan['ship_from'] && $plan['arrival_to'] && $plan['arrival_to'] < $plan['ship_from']) {
            return new WP_Error('invalid_arrival', '預計到貨區間不可完全早於預計出貨開始日，原預計時間未變更。');
        }
        $status = sanitize_key($this->text($input['status'] ?? 'preparing'));
        $plan['status'] = isset($this->statuses($order)[$status]) ? $status : 'preparing';
        $raw_message = $input['message'] ?? '';
        $message = sanitize_textarea_field($unslash ? $this->text($raw_message) : (is_scalar($raw_message) ? (string) $raw_message : ''));
        $plan['message'] = preg_match('/^(.{500})/us', $message, $match) ? $match[1] : $message;
        return $plan;
    }

    private function read_plan($value): array {
        if (!is_array($value) || !$value) return array();
        $plan = $this->sanitize_plan($value, true, false);
        if (is_wp_error($plan)) return array();
        $plan['updated_at'] = isset($value['updated_at']) && is_string($value['updated_at']) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value['updated_at']) ? $value['updated_at'] : '';
        return $plan;
    }

    private function changed_plan(array $plan, array $previous): array {
        $compare = $previous;
        $compare['updated_at'] = '';
        $plan['updated_at'] = $plan === $compare ? ($previous['updated_at'] ?? '') : current_time('mysql');
        return $plan;
    }

    private function commit_product($product, $input): bool {
        if (!$product instanceof WC_Product || !is_array($input) || !current_user_can('edit_post', $product->get_id())) return false;
        if (empty($input['enabled'])) {
            $product->delete_meta_data(self::PRODUCT_META);
            unset($this->product_plans[$product->get_id()]);
            return true;
        }
        $plan = $this->sanitize_plan($input);
        if (is_wp_error($plan)) {
            if (class_exists('WC_Admin_Meta_Boxes')) WC_Admin_Meta_Boxes::add_error($plan->get_error_message());
            return false;
        }
        $previous = $this->read_plan($product->get_meta(self::PRODUCT_META, true));
        $product->update_meta_data(self::PRODUCT_META, $this->changed_plan($plan, $previous));
        unset($this->product_plans[$product->get_id()]);
        return true;
    }

    public function meta_boxes(): void {
        add_meta_box('wutm_sp_product', '預計出貨／到貨時間', array($this, 'product_fields'), 'product', 'normal', 'default');
        $screens = array('shop_order');
        if (function_exists('wc_get_page_screen_id')) $screens[] = wc_get_page_screen_id('shop-order');
        add_meta_box('wutm_sp_order', '商品出貨進度與預計時間', array($this, 'order_fields'), array_unique($screens), 'normal', 'default');
    }

    private function fields(array $plan, string $name, string $prefix, bool $order = false): void {
        $plan = wp_parse_args($plan, $this->blank());
        echo '<div class="wutm-sp-field-grid">';
        foreach (array('ship' => '預計出貨', 'arrival' => '預計到貨') as $key => $label) {
            echo '<div class="wutm-sp-date-range"><strong>' . esc_html($label . '時間') . '</strong><div>';
            foreach (array('from' => '開始日', 'to' => '結束日') as $part => $part_label) {
                $field = $key . '_' . $part;
                echo '<label for="' . esc_attr($prefix . '-' . $field) . '"><span>' . esc_html($part_label) . '</span><input type="date" id="' . esc_attr($prefix . '-' . $field) . '" name="' . esc_attr($name . '[' . $field . ']') . '" value="' . esc_attr($plan[$field]) . '" data-range="' . esc_attr($key) . '" data-part="' . esc_attr($part) . '"></label>';
            }
            echo '</div></div>';
        }
        echo '<label for="' . esc_attr($prefix . '-status') . '"><strong>目前進度</strong><select id="' . esc_attr($prefix . '-status') . '" name="' . esc_attr($name . '[status]') . '">';
        foreach ($this->statuses($order) as $value => $label) echo '<option value="' . esc_attr($value) . '" ' . selected($plan['status'], $value, false) . '>' . esc_html($label) . '</option>';
        echo '</select></label><label for="' . esc_attr($prefix . '-message') . '"><strong>給客人的公開說明</strong><textarea maxlength="500" rows="2" id="' . esc_attr($prefix . '-message') . '" name="' . esc_attr($name . '[message]') . '" placeholder="例如：供應商時程調整，預計於更新後的區間出貨。">' . esc_textarea($plan['message']) . '</textarea></label></div>';
    }

    private function product_controls(int $id, array $plan, string $name): void {
        echo '<div class="wutm-sp-edit">';
        // Unchecked checkboxes and disabled fields are omitted from form submissions.
        // Keep the group present so clearing a schedule is an explicit saved action.
        echo '<input type="hidden" name="' . esc_attr($name . '[present]') . '" value="1">';
        echo '<label class="wutm-sp-switch"><input type="checkbox" class="wutm-sp-toggle" name="' . esc_attr($name . '[enabled]') . '" value="1" ' . checked(!empty($plan), true, false) . '> 指定預計時間（取消勾選：主商品清除設定；規格改為沿用主商品）</label><fieldset>';
        $this->fields($plan, $name, str_replace(array('[', ']'), '-', $name) . $id);
        echo '</fieldset></div>';
    }

    public function product_fields($post): void {
        $id = (int) $post->ID;
        wp_nonce_field('wutm_sp_product_' . $id, 'wutm_sp_product_nonce');
        echo '<div class="wutm-sp-admin"><p>更新一次，所有沿用這項商品時間的訂單都會顯示最新資訊。不會自動寄信。不同批次或已出貨的訂單可在訂單編輯頁單獨設定。</p>';
        $this->product_controls($id, $this->read_plan(get_post_meta($id, self::PRODUCT_META, true)), 'wutm_sp_product');
        echo '</div>';
    }

    public function save_product($product): void {
        if (!$product instanceof WC_Product || !isset($_POST['wutm_sp_product']) || !$this->valid_nonce('wutm_sp_product_nonce', 'wutm_sp_product_' . $product->get_id())) return;
        $this->commit_product($product, $_POST['wutm_sp_product']);
        // WooCommerce saves this product object once after this hook.
    }

    public function variation_fields($loop, $data, $variation): void {
        $id = (int) $variation->ID;
        echo '<div class="wutm-sp-admin wutm-sp-variation"><h4>此規格的預計出貨／到貨時間</h4>';
        wp_nonce_field('wutm_sp_variation_' . $id, 'wutm_sp_variation_nonce[' . $id . ']', false);
        $this->product_controls($id, $this->read_plan(get_post_meta($id, self::PRODUCT_META, true)), 'wutm_sp_variation[' . $id . ']');
        echo '</div>';
    }

    public function save_variation($variation, $loop): void {
        if (!$variation instanceof WC_Product) return;
        $id = $variation->get_id();
        $nonce = $this->text($_POST['wutm_sp_variation_nonce'][$id] ?? '');
        if (!wp_verify_nonce($nonce, 'wutm_sp_variation_' . $id) || !isset($_POST['wutm_sp_variation'][$id]) || !is_array($_POST['wutm_sp_variation'][$id])) return;
        $this->commit_product($variation, $_POST['wutm_sp_variation'][$id]);
    }

    private function valid_nonce(string $field, string $action): bool {
        return isset($_POST[$field]) && wp_verify_nonce($this->text($_POST[$field]), $action);
    }

    /** Prime only the products referenced by this order, never the whole catalog. */
    private function prime_products(array $items): void {
        $ids = array();
        foreach ($items as $item) {
            foreach (array($item->get_product_id(), $item->get_variation_id()) as $id) {
                if ($id && !array_key_exists($id, $this->product_plans)) $ids[$id] = $id;
            }
        }
        if (!$ids) return;
        foreach ($ids as $id) $this->product_plans[$id] = null;
        $posts = get_posts(array('post_type' => array('product', 'product_variation'), 'post_status' => array('publish', 'private', 'draft', 'pending', 'future'), 'include' => array_values($ids), 'numberposts' => count($ids), 'update_post_meta_cache' => true, 'update_post_term_cache' => false, 'no_found_rows' => true));
        foreach ($posts as $post) $this->product_plans[$post->ID] = $this->read_plan(get_post_meta($post->ID, self::PRODUCT_META, true));
    }

    public function item_plan($item): array {
        $override = $this->read_plan($item->get_meta(self::OVERRIDE_META, true));
        if ($override) return array('source' => 'order', 'plan' => $override);
        $this->prime_products(array($item));
        $product_id = $item->get_product_id();
        $variation_id = $item->get_variation_id();
        if ($variation_id && !empty($this->product_plans[$variation_id])) return array('source' => 'variation', 'plan' => $this->product_plans[$variation_id]);
        if (!empty($this->product_plans[$product_id])) return array('source' => 'product', 'plan' => $this->product_plans[$product_id]);
        // Preserve purchase-time information only when the product no longer exists.
        if (null === ($this->product_plans[$product_id] ?? null) && (!$variation_id || null === ($this->product_plans[$variation_id] ?? null))) {
            return array('source' => 'snapshot', 'plan' => $this->read_plan($item->get_meta(self::SNAPSHOT_META, true)));
        }
        return array('source' => 'product', 'plan' => array());
    }

    public function order_plans($order): array {
        $items = $order->get_items('line_item');
        $this->prime_products($items);
        $rows = array();
        foreach ($items as $id => $item) $rows[$id] = array('item' => $item) + $this->item_plan($item);
        return $rows;
    }

    public function snapshot($item, $cart_key, $values, $order): void {
        $result = $this->item_plan($item);
        if ($result['plan']) $item->add_meta_data(self::SNAPSHOT_META, $result['plan'], true);
    }

    public function order_fields($post_or_order): void {
        $order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order($post_or_order->ID ?? 0);
        if (!$order instanceof WC_Order || !current_user_can('edit_shop_orders')) return;
        wp_nonce_field('wutm_sp_order_' . $order->get_id(), 'wutm_sp_order_nonce');
        echo '<div class="wutm-sp-admin"><p><strong>預設：沿用商品最新時間。</strong>如為不同出貨批次或已出貨，改為「此訂單單獨設定」，便不會隨全商品時程更動。以下說明均對客人公開；儲存訂單後生效。</p>';
        if (!empty($this->options['enable_tracking'])) {
            $tracking = $this->tracking($order);
            echo '<section class="wutm-sp-admin-panel"><h3>此訂單的運送資訊</h3><p>選填；儲存訂單後會顯示於客人訂單、出貨進度查詢及通知信。不串接物流服務，也不會自動寄信或改變訂單狀態。</p><input type="hidden" name="wutm_sp_tracking[present]" value="1"><div class="wutm-sp-field-grid">';
            foreach (array('carrier' => '運送商名稱', 'number' => '運送編號') as $key => $label) echo '<label for="wutm-sp-tracking-' . esc_attr($key) . '"><strong>' . esc_html($label) . '</strong><input id="wutm-sp-tracking-' . esc_attr($key) . '" name="wutm_sp_tracking[' . esc_attr($key) . ']" type="text" maxlength="120" value="' . esc_attr($tracking[$key]) . '" placeholder="' . esc_attr($key === 'carrier' ? '例如：黑貓宅急便、7-11' : '輸入物流／運送單號') . '"></label>';
            echo '</div></section>';
        }
        foreach ($this->order_plans($order) as $id => $row) {
            echo '<details class="wutm-sp-admin-card"><summary><span>' . esc_html($row['item']->get_name()) . ' × ' . esc_html($row['item']->get_quantity()) . '</span><small>' . esc_html($this->summary($row['plan'])) . '</small></summary><div class="wutm-sp-edit">';
            echo '<label class="wutm-sp-switch">設定來源<select class="wutm-sp-mode" name="wutm_sp_items[' . (int) $id . '][mode]"><option value="inherit">沿用商品最新時間</option><option value="custom" ' . selected($row['source'], 'order', false) . '>此訂單單獨設定（含已出貨／已送達）</option></select></label><fieldset>';
            $this->fields($row['plan'], 'wutm_sp_items[' . (int) $id . ']', 'wutm-sp-item-' . (int) $id, true);
            echo '</fieldset></div></details>';
        }
        echo '</div>';
    }

    public function save_order($order_id): void {
        if (!current_user_can('edit_shop_orders') || !$this->valid_nonce('wutm_sp_order_nonce', 'wutm_sp_order_' . $order_id)) return;
        if (isset($_POST['wutm_sp_items']) && !is_array($_POST['wutm_sp_items'])) return;
        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) return;
        $tracking = null;
        if (!empty($this->options['enable_tracking']) && isset($_POST['wutm_sp_tracking']) && is_array($_POST['wutm_sp_tracking'])) {
            $tracking = array();
            foreach (array('carrier', 'number') as $key) {
                $raw = $_POST['wutm_sp_tracking'][$key] ?? '';
                if (!is_scalar($raw)) return;
                $value = sanitize_text_field($this->text($raw));
                $tracking[$key] = preg_match('/^(.{120})/us', $value, $match) ? $match[1] : $value;
            }
        }
        $changes = array();
        // Iterate real order items, never arbitrary submitted IDs from another order.
        foreach ($order->get_items('line_item') as $id => $item) {
            $input = $_POST['wutm_sp_items'][$id] ?? null;
            if (!is_array($input)) continue;
            $mode = $this->text($input['mode'] ?? '');
            if (!in_array($mode, array('custom', 'inherit'), true)) continue;
            $plan = $mode === 'custom' ? $this->sanitize_plan($input, true) : array();
            if (is_wp_error($plan)) {
                if (class_exists('WC_Admin_Meta_Boxes')) WC_Admin_Meta_Boxes::add_error($plan->get_error_message());
                return;
            }
            if ($plan) $plan = $this->changed_plan($plan, $this->read_plan($item->get_meta(self::OVERRIDE_META, true)));
            $changes[] = array($item, $plan);
        }
        foreach ($changes as list($item, $plan)) {
            if ($plan === $this->read_plan($item->get_meta(self::OVERRIDE_META, true))) continue;
            if ($plan) $item->update_meta_data(self::OVERRIDE_META, $plan);
            else $item->delete_meta_data(self::OVERRIDE_META);
            $item->save();
        }
        if ($tracking !== null) {
            if ($tracking !== $this->tracking($order)) {
                if ($tracking['carrier'] !== '' || $tracking['number'] !== '') $order->update_meta_data(self::TRACKING_META, $tracking);
                else $order->delete_meta_data(self::TRACKING_META);
                $order->save_meta_data();
            }
        }
    }

    private function tracking($order): array {
        $saved = $order->get_meta(self::TRACKING_META, true);
        $result = array('carrier' => '', 'number' => '');
        foreach ($result as $key => $empty) if (is_array($saved) && isset($saved[$key]) && is_scalar($saved[$key])) $result[$key] = sanitize_text_field((string) $saved[$key]);
        return $result;
    }

    private function tracking_html($order, bool $email = false): string {
        if (empty($this->options['enable_tracking'])) return '';
        $tracking = $this->tracking($order);
        if ($tracking['carrier'] === '' && $tracking['number'] === '') return '';
        $content = '<strong>運送資訊</strong>';
        foreach (array('carrier' => '運送商', 'number' => '運送編號') as $key => $label) if ($tracking[$key] !== '') $content .= '<br>' . esc_html($label) . '：' . esc_html($tracking[$key]);
        return '<div' . ($email ? ' style="margin:14px 0;padding:14px;background:#f4f7fa;"' : ' class="wutm-sp-message wutm-sp-tracking"') . '>' . $content . '</div>';
    }

    private function range(array $plan, string $key): string {
        $from = $plan[$key . '_from'] ?? '';
        $to = $plan[$key . '_to'] ?? '';
        if (!$from && !$to) return '尚未排定';
        $format = static function($date) { return str_replace('-', '/', (string) $date); };
        if ($from && $to && $from !== $to) return $format($from) . ' ～ ' . $format($to);
        return $format($from ?: $to) . ($from && !$to ? ' 起' : (!$from && $to ? ' 前' : ''));
    }

    private function summary(array $plan): string {
        return $plan ? '預計出貨：' . $this->range($plan, 'ship') : '尚未設定預計時間';
    }

    private function can_view($order): bool {
        if (!$order instanceof WC_Order) return false;
        if (!empty($this->verified_orders[$order->get_id()])) return true;
        if (current_user_can('manage_woocommerce')) return true;
        if (get_current_user_id() > 0 && (int) $order->get_customer_id() === get_current_user_id()) return true;
        $key = isset($_GET['key']) ? $this->text($_GET['key']) : '';
        return function_exists('is_order_received_page') && is_order_received_page() && $key !== '' && hash_equals((string) $order->get_order_key(), $key);
    }

    public function native_tracking_verified($order_id): void {
        $this->verified_orders[absint($order_id)] = true;
    }

    public function order_details($order): void {
        if (!$this->can_view($order)) return;
        $this->render_order($order);
    }

    private function style(): void {
        wp_enqueue_style('wutm-shipping-progress', WUTM_URL . 'assets/css/shipping-progress.css', array(), WUTM_VERSION);
        // Also supports shortcodes rendered late by page builders.
        if (did_action('wp_head') && !doing_action('wp_head') && !wp_style_is('wutm-shipping-progress', 'done')) wp_print_styles(array('wutm-shipping-progress'));
    }

    private function render_order($order): void {
        $this->style();
        $closed = $order->has_status(array('cancelled', 'refunded', 'failed'));
        echo '<section class="wutm-sp-progress" aria-label="商品出貨進度"><div class="wutm-sp-heading"><div><p class="wutm-sp-eyebrow">SHIPPING PROGRESS</p><h2>商品出貨進度</h2></div><span class="wutm-sp-order-number">訂單 #' . esc_html($order->get_order_number()) . '</span></div>';
        echo '<p class="wutm-sp-intro">訂單狀態：' . esc_html(wc_get_order_status_name($order->get_status())) . '。預計時間以目前更新為準，非保證送達日期。重新查看本頁即可取得最新資訊。</p>';
        if ($closed) echo '<p class="wutm-sp-alert">此訂單已取消、退款或付款失敗；以下時程僅供歷史參考，不代表仍會安排出貨。</p>';
        echo $this->tracking_html($order);
        foreach ($this->order_plans($order) as $row) {
            $plan = $row['plan'];
            $status = $plan['status'] ?? 'preparing';
            $labels = $this->statuses(true);
            $label = $plan ? ($labels[$status] ?? '備貨中') : '等待店家更新';
            $step = $status === 'delivered' ? 3 : ($status === 'shipped' ? 2 : 1);
            echo '<article class="wutm-sp-card"><header><div><h3>' . esc_html($row['item']->get_name()) . '</h3><p>數量 ' . esc_html($row['item']->get_quantity()) . '</p></div><span class="wutm-sp-badge ' . ($status === 'delayed' ? 'is-delayed' : '') . '">' . esc_html($label) . '</span></header>';
            if ($plan && !$closed) {
                echo '<ol class="wutm-sp-steps" aria-label="目前進度">';
                foreach (array(1 => '備貨', 2 => '出貨', 3 => '送達') as $number => $name) echo '<li class="' . ($number <= $step ? 'is-active' : '') . '" ' . ($number === $step ? 'aria-current="step"' : '') . '><span>' . $number . '</span>' . esc_html($name) . '</li>';
                echo '</ol>';
            }
            echo '<dl class="wutm-sp-estimates"><div><dt>預計出貨時間</dt><dd>' . esc_html($this->range($plan, 'ship')) . '</dd></div><div><dt>預計到貨時間</dt><dd>' . esc_html($this->range($plan, 'arrival')) . '</dd></div></dl>';
            if (!empty($plan['message'])) echo '<p class="wutm-sp-message">' . nl2br(esc_html($plan['message'])) . '</p>';
            if (!empty($plan['updated_at'])) echo '<p class="wutm-sp-updated">更新時間：' . esc_html($plan['updated_at']) . '（網站時區）</p>';
            if ($row['source'] === 'snapshot' && $plan) echo '<p class="wutm-sp-updated">商品已移除，以上為下單時保留的時程，請聯絡店家確認。</p>';
            echo '</article>';
        }
        echo '</section>';
    }

    public function page_url(): string {
        $id = absint($this->options['page_id']);
        return $id && get_post_type($id) === 'page' && get_post_status($id) === 'publish' ? (string) get_permalink($id) : '';
    }

    public function email_html($order): string {
        $html = $this->tracking_html($order, true);
        foreach ($this->order_plans($order) as $row) {
            if (!$row['plan']) continue;
            $plan = $row['plan'];
            $html .= '<p style="margin:12px 0;padding:14px;border-left:3px solid #2271b1;background:#f4f7fa;"><strong>' . esc_html($row['item']->get_name()) . '</strong><br>預計出貨：' . esc_html($this->range($plan, 'ship')) . '<br>預計到貨：' . esc_html($this->range($plan, 'arrival'));
            if ($plan['message']) $html .= '<br>' . nl2br(esc_html($plan['message']));
            $html .= '</p>';
        }
        $url = $this->page_url();
        if ($url) $html .= '<p><a href="' . esc_url($url) . '">查看最新出貨進度</a>（請輸入訂單編號與結帳 Email）</p>';
        return $html ? '<div><h3>商品預計時間</h3>' . $html . '<p>此信為寄送當下的資訊；預計時間可能調整，請以訂單／出貨進度查詢頁的最新資料為準。</p></div>' : '';
    }

    /** Authenticate a single order; no order collection queries or public order IDs. */
    public function lookup_order($number, $email, $nonce) {
        if (!wp_verify_nonce($this->text($nonce), 'wutm_sp_lookup')) return new WP_Error('nonce', '頁面驗證已過期，請重新整理後再查詢。');
        $identity = get_current_user_id() . '|' . $this->text($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $limit = 'wutm_sp_lookup_' . wp_hash($identity);
        if (class_exists('WC_Rate_Limiter')) {
            if (WC_Rate_Limiter::retried_too_soon($limit)) return new WP_Error('rate_limit', '查詢太頻繁，請稍候幾秒再試。');
            WC_Rate_Limiter::set_rate_limit($limit, 3);
        }
        $number = ltrim(sanitize_text_field($this->text($number)), '#');
        $email = sanitize_email($this->text($email));
        $error = new WP_Error('not_found', '找不到符合的訂單。請確認訂單編號與結帳時填寫的 Email，或聯絡店家協助。');
        if ($number === '' || strlen($number) > 80 || !is_email($email)) return $error;
        $id = apply_filters('woocommerce_shortcode_order_tracking_order_id', $number);
        if (!is_scalar($id)) return $error;
        $order = wc_get_order($id);
        if (!$order instanceof WC_Order || !$order->get_id() || !hash_equals(strtolower((string) $order->get_billing_email()), strtolower($email))) return $error;
        $this->verified_orders[$order->get_id()] = true;
        return $order;
    }

    public function shortcode($atts = array()): string {
        ob_start();
        $this->style();
        $error = '';
        $order = false;
        if (isset($_POST['wutm_sp_lookup'])) {
            $order = $this->lookup_order($_POST['wutm_sp_order'] ?? '', $_POST['wutm_sp_email'] ?? '', $_POST['wutm_sp_nonce'] ?? '');
            if (is_wp_error($order)) { $error = $order->get_error_message(); $order = false; }
        }
        ?>
        <section class="wutm-sp-query" aria-label="出貨進度查詢">
            <p class="wutm-sp-eyebrow">ORDER LOOKUP</p><h2>出貨進度查詢</h2>
            <p>輸入訂單編號與結帳時的 Email，即可查看各項商品最新的預計出貨與到貨時間。</p>
            <?php if ($error): ?><p class="wutm-sp-alert" role="alert"><?php echo esc_html($error); ?></p><?php endif; ?>
            <form method="post" class="wutm-sp-lookup-form">
                <label>訂單編號<input type="text" name="wutm_sp_order" maxlength="80" autocomplete="off" placeholder="例如：12345" required value="<?php echo esc_attr($this->text($_POST['wutm_sp_order'] ?? '')); ?>"></label>
                <label>結帳 Email<input type="email" name="wutm_sp_email" maxlength="254" autocomplete="email" required value="<?php echo esc_attr($this->text($_POST['wutm_sp_email'] ?? '')); ?>"></label>
                <?php wp_nonce_field('wutm_sp_lookup', 'wutm_sp_nonce', false); ?>
                <button type="submit" name="wutm_sp_lookup" value="1">查詢出貨進度</button>
            </form><p class="wutm-sp-privacy">查詢不會顯示收件地址、電話或付款資料。預計日期可能隨供貨與物流安排調整。</p>
        </section>
        <?php
        if ($order && $this->can_view($order)) $this->render_order($order);
        return (string) ob_get_clean();
    }

    public function replace_tracking($output, $tag) {
        return $output === false && $tag === 'woocommerce_order_tracking' && !empty($this->options['replace_tracking']) ? $this->shortcode() : $output;
    }

    private function is_query_page(): bool {
        global $post;
        if (is_page(absint($this->options['page_id'])) && !empty($this->options['page_id'])) return true;
        return $post instanceof WP_Post && (has_shortcode($post->post_content, 'wutm_shipping_progress') || has_shortcode($post->post_content, 'woocommerce_order_tracking'));
    }

    public function private_page(): void {
        if (!$this->is_query_page() && !isset($_POST['wutm_sp_lookup']) && !(function_exists('is_account_page') && is_account_page()) && !(function_exists('is_order_received_page') && is_order_received_page())) return;
        if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
        nocache_headers();
    }

    public function frontend_assets(): void {
        if ($this->is_query_page() || (function_exists('is_account_page') && is_account_page() && is_wc_endpoint_url('view-order')) || (function_exists('is_order_received_page') && is_order_received_page())) $this->style();
    }

    public function admin_assets(): void {
        $screen = get_current_screen();
        if (!$screen) return;
        $order_screen = function_exists('wc_get_page_screen_id') ? wc_get_page_screen_id('shop-order') : '';
        if (($screen->post_type ?? '') !== 'product' && !in_array($screen->id, array('shop_order', $order_screen), true) && strpos($screen->id, 'wu-shipping-notification-email') === false) return;
        wp_enqueue_style('wutm-shipping-progress', WUTM_URL . 'assets/css/shipping-progress.css', array(), WUTM_VERSION);
        wp_enqueue_script('wutm-shipping-progress-admin', WUTM_URL . 'assets/js/shipping-progress-admin.js', array(), WUTM_VERSION, true);
    }

    public function register_settings(): void {
        register_setting('wutm_sp_group', self::OPTION, array('type' => 'array', 'sanitize_callback' => array($this, 'sanitize_options')));
        add_filter('option_page_capability_wutm_sp_group', static function() { return 'manage_woocommerce'; });
    }

    public function sanitize_options($input): array {
        $input = is_array($input) ? $input : array();
        $id = absint($input['page_id'] ?? 0);
        return array('page_id' => $id && get_post_type($id) === 'page' ? $id : 0, 'replace_tracking' => !empty($input['replace_tracking']) ? 1 : 0, 'enable_tracking' => !empty($input['enable_tracking']) ? 1 : 0);
    }

    public function create_page(): void {
        if (!current_user_can('manage_woocommerce') || !current_user_can('publish_pages')) wp_die('您沒有建立查詢頁面的權限。');
        check_admin_referer('wutm_sp_create_page');
        $id = absint($this->options['page_id']);
        if (!$id || get_post_type($id) !== 'page' || get_post_status($id) === 'trash') {
            $id = wp_insert_post(array('post_type' => 'page', 'post_title' => '出貨進度查詢', 'post_name' => 'shipping-progress', 'post_status' => 'publish', 'post_content' => '[wutm_shipping_progress]', 'comment_status' => 'closed', 'ping_status' => 'closed'), true);
            if (is_wp_error($id)) wp_die('無法建立查詢頁面，請稍後再試。');
            $this->options['page_id'] = $id;
            update_option(self::OPTION, $this->options, false);
        }
        wp_safe_redirect(admin_url('admin.php?page=wu-shipping-notification-email&wutm_sp_saved=page'));
        exit;
    }

    public function save_product_form(): void {
        $id = absint($_POST['product_id'] ?? 0);
        if (!current_user_can('manage_woocommerce') || !current_user_can('edit_post', $id)) wp_die('您沒有修改此商品的權限。');
        check_admin_referer('wutm_sp_product_' . $id, 'wutm_sp_product_nonce');
        $product = wc_get_product($id);
        $saved = $product && isset($_POST['wutm_sp_product']) && is_array($_POST['wutm_sp_product']) && $this->commit_product($product, $_POST['wutm_sp_product']);
        if ($saved) $product->save();
        $search = sanitize_text_field($this->text($_POST['wutm_sp_search'] ?? ''));
        $page = max(1, absint($_POST['wutm_sp_paged'] ?? 1));
        wp_safe_redirect(add_query_arg(array('page' => 'wu-shipping-notification-email', 'wutm_sp_saved' => $saved ? 'product' : 'error', 'wutm_sp_search' => $search, 'wutm_sp_paged' => $page), admin_url('admin.php')) . '#wutm-sp-products');
        exit;
    }

    public function admin_panel(): void {
        $this->admin_assets();
        $saved = sanitize_key($this->text($_GET['wutm_sp_saved'] ?? ''));
        $search = sanitize_text_field($this->text($_GET['wutm_sp_search'] ?? ''));
        $page = max(1, absint($_GET['wutm_sp_paged'] ?? 1));
        $query = new WP_Query(array('post_type' => 'product', 'post_status' => array('publish', 'private', 'draft', 'pending'), 's' => $search, 'posts_per_page' => 12, 'paged' => $page, 'orderby' => 'modified', 'order' => 'DESC', 'update_post_meta_cache' => true, 'update_post_term_cache' => false));
        ?>
        <div class="wutm-sp-admin">
            <?php if ($saved): ?><p class="wutm-sp-admin-feedback" role="status"><?php echo esc_html($saved === 'error' ? '未能儲存，請檢查日期、開始／結束日順序，以及到貨區間是否早於出貨開始日。原資料未變更。' : '已完成儲存，客人下次查看即可取得最新資訊。'); ?></p><?php endif; ?>
            <section class="wutm-sp-admin-panel"><h2>出貨進度查詢頁</h2><p>會員可在訂單詳細內容查看；非會員可使用訂單編號與結帳 Email 查詢。請避免將這個查詢頁或會員訂單頁加入快取。</p>
                <form method="post" action="options.php"><?php settings_fields('wutm_sp_group'); ?>
                    <label>查詢頁面<?php wp_dropdown_pages(array('name' => self::OPTION . '[page_id]', 'selected' => $this->options['page_id'], 'show_option_none' => '尚未指定', 'option_none_value' => 0, 'post_status' => 'publish')); ?></label>
                    <p>頁面加入短代碼：<code>[wutm_shipping_progress]</code>。指定既有頁面時不會更動原內容，請自行加入此短代碼。</p>
                    <label class="wutm-sp-switch"><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[replace_tracking]" value="1" <?php checked(!empty($this->options['replace_tracking'])); ?>> 將原本 <code>[woocommerce_order_tracking]</code> 改顯示出貨進度查詢（預設不替換）</label>
                    <label class="wutm-sp-switch"><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[enable_tracking]" value="1" <?php checked(!empty($this->options['enable_tracking'])); ?>> 啟用每筆訂單的運送商名稱與運送編號（預設關閉）</label><p>啟用後可在訂單編輯頁填寫，客人訂單、出貨進度查詢與通知信會顯示。關閉只停止顯示與編輯，不刪除已儲存資訊。</p>
                    <?php submit_button('儲存查詢頁設定', 'secondary', 'submit', false); ?>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wutm-sp-page-actions"><input type="hidden" name="action" value="wutm_sp_create_page"><?php wp_nonce_field('wutm_sp_create_page'); ?><button type="submit" class="button button-primary" <?php disabled(!current_user_can('publish_pages')); ?>><?php echo $this->page_url() ? '保留目前查詢頁' : '一鍵建立出貨進度查詢頁'; ?></button><?php if ($this->page_url()): ?> <a class="button" href="<?php echo esc_url($this->page_url()); ?>" target="_blank" rel="noopener noreferrer">查看查詢頁</a><?php endif; ?></form>
            </section>
            <section class="wutm-sp-admin-panel" id="wutm-sp-products"><h2>按商品管理預計時間</h2><p>一次更新商品，所有沿用商品時間的訂單都會顯示新時程；不同規格可在商品編輯頁指定。已出貨或不同批次的訂單，請改用訂單單獨設定。這裡不會自動群發通知信。</p>
                <form method="get" class="wutm-sp-admin-search"><input type="hidden" name="page" value="wu-shipping-notification-email"><label for="wutm-sp-search">搜尋商品名稱</label><input id="wutm-sp-search" name="wutm_sp_search" value="<?php echo esc_attr($search); ?>" placeholder="輸入商品名稱"><button class="button">搜尋</button></form>
                <div class="wutm-sp-product-list">
                <?php foreach ($query->posts as $product): $id = (int) $product->ID; $plan = $this->read_plan(get_post_meta($id, self::PRODUCT_META, true)); ?>
                    <details class="wutm-sp-admin-card"><summary><span><?php echo esc_html(get_the_title($product)); ?></span><small><?php echo esc_html($this->summary($plan)); ?></small></summary>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wutm-sp-product-form"><input type="hidden" name="action" value="wutm_sp_save_product"><input type="hidden" name="product_id" value="<?php echo $id; ?>"><input type="hidden" name="wutm_sp_search" value="<?php echo esc_attr($search); ?>"><input type="hidden" name="wutm_sp_paged" value="<?php echo $page; ?>"><?php wp_nonce_field('wutm_sp_product_' . $id, 'wutm_sp_product_nonce'); $this->product_controls($id, $plan, 'wutm_sp_product'); ?><div class="wutm-sp-save-row"><button class="button button-primary">儲存此商品預計時間</button><a href="<?php echo esc_url(get_edit_post_link($id)); ?>">編輯商品／個別規格</a></div></form>
                    </details>
                <?php endforeach; ?>
                </div>
                <?php if (!$query->posts): ?><p>找不到符合的商品。</p><?php endif; ?>
                <nav class="wutm-sp-pagination" aria-label="商品分頁"><?php echo wp_kses_post(paginate_links(array('base' => str_replace('999999999', '%#%', add_query_arg(array('page' => 'wu-shipping-notification-email', 'wutm_sp_search' => $search, 'wutm_sp_paged' => 999999999), admin_url('admin.php'))), 'format' => '', 'current' => $page, 'total' => $query->max_num_pages))); ?></nav>
            </section>
        </div>
        <?php
    }
}
