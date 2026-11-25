<?php
defined('ABSPATH') || exit;
$config = WUTM_ATM_Events::test_configuration();
$inspected = $check ? wc_get_order($order_id) : null;
$rows = $inspected ? WUTM_ATM_Purchase::rows([$order_id]) : [];
?>
<div class="atm-card"><div class="atm-card-body" id="wutm-atm-test-center">
    <h2>測試工具</h2>
    <p>正式資料集 <?php echo esc_html($report['dataset'] ?: '—'); ?>　測試資料集 <?php echo esc_html($report['test_dataset'] ?: '—'); ?>
       　<a href="<?php echo esc_url(add_query_arg('atm_tab','events',$screen)); ?>">設定資料集與測試代碼</a>　<a href="<?php echo esc_url(add_query_arg(['atm_tab'=>'history','event_mode'=>'test'],$screen)); ?>">測試事件紀錄</a></p>
    <details><summary>通道、同意與 Meta 後台核對</summary>
        <p>Graph API <?php echo esc_html($report['version'] . ' · ' . $report['version_warning']); ?></p>
        <p>現用通道 <?php echo esc_html(['browser'=>'瀏覽器','server'=>'伺服器','both'=>'雙通道'][$report['mode']] ?? '未設定'); ?>　
            <?php echo $report['privacy']['built_in'] ? '使用內建同意管理。' : '使用外部同意整合。'; ?></p>
        <p>雙通道請在隔離站使用相同的獨立 Pixel／資料集，於 <a href="https://business.facebook.com/events_manager2/" target="_blank" rel="noopener noreferrer">Meta Events Manager</a>
            核對事件、來源及去重結果，再到事件詳情記錄人工核對。</p>
    </details>
    <?php if ($config && $config['shared']) : ?>
        <p><strong>目前測試使用正式資料集，Meta 可能接收並使用這些事件，影響廣告報表。</strong></p>
    <?php endif; ?>
    <?php foreach (['connection'=>'檢查正式資料集連線','test'=>'發送合成 PageView'] as $kind=>$label) : $meta = $report['meta'][$kind] ?? []; ?>
    <form method="post" action="<?php echo esc_url($post); ?>">
        <?php wp_nonce_field('wutm_atm_event_diagnostic'); ?>
        <input type="hidden" name="action" value="wutm_atm_event_diagnostic">
        <?php if ($kind === 'test' && $config) : ?>
            <input type="hidden" name="reviewed_dataset" value="<?php echo esc_attr($config['dataset']); ?>">
            <?php if ($config['shared']) : ?><p><label><input name="shared_target" type="checkbox" value="yes" required> 確認使用正式資料集測試 PageView</label></p><?php endif; ?>
        <?php endif; ?>
        <p><button class="button" name="diagnostic" value="<?php echo esc_attr($kind); ?>" <?php disabled($kind === 'test' && !$config); ?>><?php echo esc_html($label); ?></button>
        <?php if ($meta) echo esc_html(' ' . $meta['message'] . ' · ' . WUTM_ATM_Observability::date($meta['time']) .
            ' · HTTP ' . $meta['http'] . ' · 接收 ' . $meta['received'] . (empty($meta['current_config']) ? ' · 設定已變更' : '')); ?></p>
    </form>
    <?php endforeach; ?>
    <?php if ($report['test_mode']) : ?>
        <form method="post" action="<?php echo esc_url($post); ?>" data-atm-confirm="清除後，已排入的測試仍使用其原始代碼。">
            <?php wp_nonce_field('wutm_atm_clear_test_code'); ?><input type="hidden" name="action" value="wutm_atm_clear_test_code">
            <p><button class="button">清除測試代碼</button></p>
        </form>
    <?php endif; ?>

    <?php if ($report['woocommerce']) : ?>
    <h3>訂單追蹤檢查器</h3>
    <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
        <input type="hidden" name="page" value="wu-advanced-tracking-manager"><input type="hidden" name="atm_tab" value="overview">
        <p><label>WooCommerce 訂單 ID <input type="number" name="atm_order" min="1" value="<?php echo esc_attr($order_id ?: ''); ?>" required></label>
        <button class="button">核對訂單</button></p>
    </form>
    <?php if ($order_id && !$check) : ?><p>找不到這筆訂單。</p><?php endif; ?>
    <?php if ($inspected) : ?>
        <dl class="wutm-atm-audit-definition">
            <?php foreach (['訂單總額','商品 ID 與數量','交易同意收據'] as $label) : ?><dt><?php echo esc_html($label); ?></dt><dd><?php echo esc_html($check[$label]); ?></dd><?php endforeach; ?>
        </dl>
        <details><summary>技術詳情與測試紀錄</summary>
            <dl class="wutm-atm-audit-definition">
                <?php foreach ($check as $label=>$value) : ?><dt><?php echo esc_html($label); ?></dt><dd><?php echo esc_html($value); ?></dd><?php endforeach; ?>
            </dl>
            <?php foreach (array_slice($rows, 0, 10) as $row) : ?>
                <p><a href="<?php echo esc_url(WUTM_ATM_Testing_Tools::history_url($row)); ?>"><?php echo (int) $row->test_mode === 1 ? '測試事件' : '正式事件'; ?>
                <?php echo esc_html($row->event_id); ?></a> · <?php echo esc_html(WUTM_ATM_Event_Admin::labels()[$row->status] ?? $row->status); ?>
                <?php echo esc_html($row->due ? ' · 下次處理 ' . WUTM_ATM_Observability::date($row->due) : ''); ?></p>
            <?php endforeach; ?>
        </details>
        <?php foreach ($rows as $row) :
            if ((int) $row->test_mode === 1 && isset($_GET['atm_test']) && $_GET['atm_test'] === $row->event_id) : ?>
                <p><a href="<?php echo esc_url(WUTM_ATM_Testing_Tools::history_url($row)); ?>">查看這次模擬的事件詳情</a> · <?php echo esc_html(WUTM_ATM_Event_Admin::labels()[$row->status] ?? $row->status); ?></p>
            <?php endif; endforeach; ?>
        <?php [$production] = WUTM_ATM_Purchase::channels($inspected, $rows); ?>
        <?php if (WUTM_ATM_Purchase::active() && WUTM_ATM_Events::server_active() && WUTM_ATM_Purchase::retryable($production, $inspected)) : ?>
            <form method="post" action="<?php echo esc_url($post); ?>">
                <?php wp_nonce_field('wutm_atm_technical_retry_' . $order_id); ?>
                <input type="hidden" name="action" value="wutm_atm_technical_retry">
                <input type="hidden" name="atm_order" value="<?php echo (int) $order_id; ?>">
                <p><button class="button">重試原有正式事件</button></p>
            </form>
        <?php endif; ?>
        <?php if ($config && WUTM_ATM_Purchase::active() && WUTM_ATM_Purchase::consent_valid($inspected) && WUTM_ATM_Purchase::valid_total($inspected)) : ?>
        <form method="post" action="<?php echo esc_url($post); ?>">
            <?php wp_nonce_field('wutm_atm_simulation_' . $order_id); ?>
            <input type="hidden" name="action" value="wutm_atm_simulation">
            <input type="hidden" name="atm_order" value="<?php echo (int) $order_id; ?>">
            <input type="hidden" name="reviewed_settings" value="<?php echo esc_attr(WUTM_ATM_Purchase::review_key($inspected, $config)); ?>">
            <p>模擬僅驗證伺服器事件的參數及傳送。金流與雙通道去重需另行驗證。</p>
            <p><label><input type="checkbox" name="verified" value="yes" required> 確認以此訂單進行模擬 Purchase</label></p>
            <?php if ($config['shared']) : ?><p><label><input type="checkbox" name="shared_target" value="yes" required> 確認使用正式資料集發送測試</label></p><?php endif; ?>
            <p><button class="button">模擬 Purchase 測試</button></p>
        </form>
        <?php endif; ?>
    <?php endif; ?>
    <?php endif; ?>
</div></div>
