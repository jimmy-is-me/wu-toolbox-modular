<?php
/**
 * KH Test Orders - WooCommerce 測試訂單產生器
 * 位置:WooCommerce → 測試訂單產生器
 */

if (!defined('ABSPATH')) exit;

function wutm_to_status_pool() {
    return [
        'on-hold'    => '保留中',
        'processing' => '處理中',
        'completed'  => '已完成',
        'failed'     => '失敗',
        'pending'    => '待付款',
        'cancelled'  => '已取消',
        'refunded'   => '已退款',
    ];
}

add_action('admin_menu', function () {
    if (!class_exists('WooCommerce')) return;
    add_submenu_page('wu-toolbox-modular', '測試訂單產生器', '測試訂單產生器', 'manage_woocommerce', 'wu-test-orders', 'wutm_to_page');
});

function wutm_to_count_test_orders() {
    $ids = wc_get_orders([
        'limit'      => 1, 'paginate'=>true, 'created_via'=>'khto',
        'return'     => 'ids',
        'status'     => array_keys(wc_get_order_statuses()),
        'meta_key'   => '_khto_test',
        'meta_value' => '1',
    ]);
    return (int)$ids->total;
}

function wutm_to_page() {
    if (!current_user_can('manage_woocommerce')) return;
    wutm_admin_page_open('測試訂單產生器', '會建立真實資料庫訂單，請優先在測試站使用。預設不寄信、不扣庫存。');
    $u = wp_get_current_user();
    $pool = wutm_to_status_pool();
    $default_on = ['on-hold', 'processing', 'completed', 'failed'];
    ?>
    <div class="wrap">
        <h1>測試訂單產生器</h1>
        <?php if (!empty($_GET['wutm_to_msg'])): ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html(wp_unslash($_GET['wutm_to_msg'])); ?></p></div>
        <?php endif; ?>
        <p>訂購人:<strong><?php echo esc_html($u->display_name . ' <' . $u->user_email . '>'); ?></strong>(目前登入者)。目前站上有 <strong><?php echo (int) wutm_to_count_test_orders(); ?></strong> 筆測試訂單。</p>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="wutm_to_create">
            <?php wp_nonce_field('wutm_to_create', 'wutm_to_nonce'); ?>
            <table class="form-table">
                <tr><th>訂單筆數</th><td><input type="number" name="count" value="6" min="1" max="50" style="width:80px"></td></tr>
                <tr><th>每筆商品總數量</th><td>
                    最少 <input type="number" name="qty_min" value="5" min="1" max="50" style="width:70px">
                    最多 <input type="number" name="qty_max" value="7" min="1" max="50" style="width:70px">
                    <p class="description">每筆訂單的商品件數總和會落在這個範圍內。</p></td></tr>
                <tr><th>隨機狀態</th><td>
                    <?php foreach ($pool as $k => $v): ?>
                        <label style="margin-right:14px"><input type="checkbox" name="statuses[]" value="<?php echo esc_attr($k); ?>" <?php checked(in_array($k, $default_on, true)); ?>> <?php echo esc_html($v); ?></label>
                    <?php endforeach; ?></td></tr>
                <tr><th>訂單日期</th><td>隨機分散在最近 <input type="number" name="days" value="0" min="0" max="365" style="width:70px"> 天(0 = 都用現在)</td></tr>
                <tr><th>付款方式</th><td>
                    <select name="payment">
                        <option value="random">隨機(轉帳 / 貨到付款)</option>
                        <option value="bacs">銀行轉帳</option>
                        <option value="cod">貨到付款</option>
                    </select></td></tr>
                <tr><th>選項</th><td>
                    <label><input type="checkbox" name="reduce_stock" value="1"> 允許扣庫存(預設不扣)</label><br>
                    <label><input type="checkbox" name="send_mail" value="1"> 允許寄出訂單通知信(預設不寄)</label><br>
                    <label><input type="checkbox" name="add_shipping" value="1"> 加上固定運費 NT$60</label></td></tr>
            </table>
            <?php submit_button('建立測試訂單', 'primary', 'submit', false); ?>
        </form>

        <hr>
        <h2>清除測試訂單</h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('確定永久刪除所有測試訂單?');">
            <input type="hidden" name="action" value="wutm_to_delete">
            <?php wp_nonce_field('wutm_to_delete', 'wutm_to_nonce'); ?>
            <?php submit_button('刪除所有測試訂單', 'delete', 'submit', false); ?>
            <p class="description">只會刪除由本工具建立(帶有測試標記)的訂單,不會動到真實訂單。</p>
        </form>
    </div>
    <?php wutm_admin_page_close();
}

function wutm_to_redirect($msg) {
    wp_safe_redirect(add_query_arg('wutm_to_msg', rawurlencode($msg), admin_url('admin.php?page=wu-test-orders')));
    exit;
}

// 把總數量隨機拆成 n 份
function wutm_to_split_qty($total, $lines) {
    $lines = max(1, min($lines, $total));
    $parts = array_fill(0, $lines, 1);
    for ($i = 0; $i < $total - $lines; $i++) $parts[array_rand($parts)]++;
    return $parts;
}

add_action('admin_post_wutm_to_create', function () {
    if (!current_user_can('manage_woocommerce')) wp_die('權限不足');
    check_admin_referer('wutm_to_create', 'wutm_to_nonce');
    foreach(['count','qty_min','qty_max','days','payment','reduce_stock','send_mail','add_shipping'] as $field)if(isset($_POST[$field])&&!is_scalar($_POST[$field]))wp_die('測試訂單資料格式不正確。');

    $count   = max(1, min(50, (int) ($_POST['count'] ?? 6)));
    $qmin    = max(1, min(50,(int) ($_POST['qty_min'] ?? 5)));
    $qmax    = max($qmin, min(50,(int) ($_POST['qty_max'] ?? 7)));
    $days    = max(0, min(365,(int) ($_POST['days'] ?? 0)));
    $payment = sanitize_key($_POST['payment'] ?? 'random');
    if(!in_array($payment,['random','bacs','cod'],true))$payment='random';
    $pool    = array_keys(wutm_to_status_pool());
    $statuses = array_values(array_intersect($pool, array_map('sanitize_key', array_filter((array) ($_POST['statuses'] ?? []),'is_string'))));
    if (!$statuses) $statuses = ['on-hold', 'processing', 'completed', 'failed'];

    $reduce  = !empty($_POST['reduce_stock']);
    $mail    = !empty($_POST['send_mail']);
    $ship    = !empty($_POST['add_shipping']);

    $products = wc_get_products([
        'type' => ['simple', 'variation'], 'status' => 'publish',
        'limit' => 200, 'orderby' => 'rand',
    ]);
    $products = array_values(array_filter($products, function ($p) {
        return $p->is_purchasable() && (float) $p->get_price() > 0;
    }));
    if (!$products) wutm_to_redirect('找不到可購買的商品(需為已發佈、有價格的一般或變化商品)。');

    $user = wp_get_current_user();
    $first = get_user_meta($user->ID, 'billing_first_name', true) ?: $user->first_name ?: $user->display_name;
    $last  = get_user_meta($user->ID, 'billing_last_name', true) ?: $user->last_name;
    $addr = [
        'first_name' => $first,
        'last_name'  => $last,
        'email'      => $user->user_email,
        'phone'      => get_user_meta($user->ID, 'billing_phone', true) ?: '0900000000',
        'address_1'  => get_user_meta($user->ID, 'billing_address_1', true) ?: '測試地址 1 號',
        'city'       => get_user_meta($user->ID, 'billing_city', true) ?: '高雄市',
        'postcode'   => get_user_meta($user->ID, 'billing_postcode', true) ?: '800',
        'country'    => 'TW',
    ];

    $saved_hooks=wutm_to_isolate();
    try {
    add_filter('woocommerce_webhook_should_deliver','__return_false',PHP_INT_MAX);
    // 預設關閉信件與扣庫存
    if (!$mail) add_filter('pre_wp_mail', '__return_true', 99);
    if (!$reduce) {
        remove_action('woocommerce_order_status_completed', 'wc_maybe_reduce_stock_levels');
        remove_action('woocommerce_order_status_processing', 'wc_maybe_reduce_stock_levels');
        remove_action('woocommerce_order_status_on-hold', 'wc_maybe_reduce_stock_levels');
        add_filter('woocommerce_can_reduce_order_stock', '__return_false', 99);
    }

    $created = 0;
    $summary = [];
    for ($n = 0; $n < $count; $n++) {
        $order = wc_create_order(['customer_id' => $user->ID, 'created_via' => 'khto']);
        if (is_wp_error($order)) continue;
        $order->update_meta_data('_khto_test','1');$order->update_meta_data('_wutm_to_mail',$mail?1:0);$order->update_meta_data('_wutm_to_reduce',$reduce?1:0);$order->save();

        $total_qty = wp_rand($qmin, $qmax);
        $lines = min(count($products), wp_rand(2, 4), $total_qty);
        $qtys = wutm_to_split_qty($total_qty, $lines);
        $picked = (array) array_rand($products, $lines);
        shuffle($picked);
        foreach ($picked as $idx => $key) {
            $order->add_product($products[$key], $qtys[$idx]);
        }

        $order->set_address($addr, 'billing');
        $order->set_address($addr, 'shipping');

        if ($ship) {
            $item = new WC_Order_Item_Shipping();
            $item->set_method_title('固定運費');
            $item->set_method_id('flat_rate');
            $item->set_total(60);
            $order->add_item($item);
        }

        $pm = $payment === 'random' ? (wp_rand(0, 1) ? 'bacs' : 'cod') : $payment;
        $gateway_settings=get_option('woocommerce_'.$pm.'_settings',[]);
        $order->set_payment_method($pm);
        $order->set_payment_method_title($gateway_settings['title']??($pm==='bacs'?'銀行轉帳':'貨到付款'));

        if ($days > 0) {
            $ts = time() - wp_rand(0, $days * DAY_IN_SECONDS);
            $order->set_date_created($ts);
        }

        $order->update_meta_data('_khto_test', '1');
        $order->calculate_totals();

        $status = $statuses[array_rand($statuses)];
        $order->add_order_note('由「測試訂單產生器」建立(觸發者:' . $user->user_login . ')');
        $order->set_status($status);
        $order->save();

        $created++;
        $summary[$status] = ($summary[$status] ?? 0) + 1;
    }

    if (!$mail) remove_filter('pre_wp_mail', '__return_true', 99);
    if (!$reduce) {
        remove_filter('woocommerce_can_reduce_order_stock', '__return_false', 99);
        add_action('woocommerce_order_status_completed', 'wc_maybe_reduce_stock_levels');
        add_action('woocommerce_order_status_processing', 'wc_maybe_reduce_stock_levels');
        add_action('woocommerce_order_status_on-hold', 'wc_maybe_reduce_stock_levels');
    }

    } finally {remove_filter('woocommerce_webhook_should_deliver','__return_false',PHP_INT_MAX);wutm_to_restore($saved_hooks);if(!$mail)remove_filter('pre_wp_mail','__return_true',99);if(!$reduce)remove_filter('woocommerce_can_reduce_order_stock','__return_false',99);}
    $labels = wutm_to_status_pool();
    $parts = [];
    foreach ($summary as $k => $v) $parts[] = ($labels[$k] ?? $k) . ' ' . $v;
    wutm_to_redirect("已建立 $created 筆測試訂單(" . implode('、', $parts) . ')');
});

add_action('admin_post_wutm_to_delete', function () {
    if (!current_user_can('manage_woocommerce')) wp_die('權限不足');
    check_admin_referer('wutm_to_delete', 'wutm_to_nonce');
    add_filter('woocommerce_webhook_should_deliver','__return_false',PHP_INT_MAX);
    try {
    $n=0;
    do {$orders = wc_get_orders([
        'limit' => 100, 'created_via'=>'khto', 'status' => array_keys(wc_get_order_statuses()),
        'meta_key' => '_khto_test', 'meta_value' => '1',
    ]);
    foreach ($orders as $o) {if($o->get_meta('_khto_test')!=='1'||$o->get_created_via()!=='khto')continue; $o->delete(true); $n++; }
    } while(count($orders)===100);
    } finally {remove_filter('woocommerce_webhook_should_deliver','__return_false',PHP_INT_MAX);}
    wutm_to_redirect("已刪除 $n 筆測試訂單");
});

// 訂單列表標記測試單(舊版文章儲存與 HPOS 皆處理)
add_filter('woocommerce_admin_order_preview_get_order_details', function ($d, $order) {
    if ($order->get_meta('_khto_test')) $d['order_number'] = $d['order_number'] . ' [測試]';
    return $d;
}, 10, 2);
