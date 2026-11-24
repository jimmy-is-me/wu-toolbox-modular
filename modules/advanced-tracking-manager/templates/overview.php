<?php
defined('ABSPATH') || exit;
$queue = $report['queue'];
$counts = $queue['counts'];
$screen = admin_url('admin.php?page=wu-advanced-tracking-manager');
$post = admin_url('admin-post.php');
$status = ['normal'=>'正常等待','check'=>'需要檢查','overdue'=>'排程逾期','inactive'=>'已停用'][$queue['status']];
$order_id = is_string($_GET['atm_order'] ?? null) ? absint($_GET['atm_order']) : 0;
$check = $order_id ? WUTM_ATM_Observability::order($order_id) : [];
?>
<?php if ($result) : ?><div class="notice notice-info"><p><?php echo esc_html($result); ?></p></div><?php endif; ?>
<div class="atm-card"><div class="atm-card-body">
    <h2>追蹤總覽</h2>
    <div class="wutm-atm-event-grid">
        <?php foreach ($report['platforms'] as $platform=>$label) : ?><div><strong><?php echo esc_html($platform); ?></strong><br><?php echo esc_html($label); ?></div><?php endforeach; ?>
        <div><strong>WooCommerce</strong><br><?php echo !$report['woocommerce'] ? '未安裝' : ($report['woo_events'] && $report['events_enabled'] ? '追蹤接點已啟用' : '追蹤接點未啟用'); ?></div>
    </div>
    <p>今天的事件依網站時區統計。最近一次 CAPI 確認接收 <?php echo esc_html(WUTM_ATM_Observability::date($queue['last_success'])); ?></p>
    <?php foreach ($queue['today'] as $row) : ?>
        <span class="wutm-atm-metric"><?php echo esc_html($row['event_name'] . ' · 正式 ' . $row['total'] . ' · 測試接收 ' . $row['test_confirmed']); ?></span>
    <?php endforeach; ?>
    <?php if (!$queue['today']) : ?><p>今日尚無事件紀錄。</p><?php endif; ?>
    <p>今日正式 CAPI 確認接收 <?php echo (int) $queue['sent_today']; ?> 筆</p>
    <p><a href="<?php echo esc_url(add_query_arg('atm_tab','history',$screen)); ?>">查看事件紀錄</a></p>
</div></div>

<div class="atm-card"><div class="atm-card-body">
    <h2>背景排程 <small><?php echo esc_html($status); ?></small></h2>
    <p>由 WordPress WP-Cron 驅動。<?php echo $queue['spawn_disabled'] ? ' DISABLE_WP_CRON 已啟用，須由主機排程或 WP-CLI 觸發。' : ' DISABLE_WP_CRON 未啟用。'; ?></p>
    <div class="wutm-atm-event-grid">
        <?php foreach (['pending'=>'待發送','retry'=>'待重試','sending'=>'處理中','failed'=>'失敗','sent'=>'確認接收','consent_blocked'=>'同意限制'] as $key=>$label) : ?>
            <div><strong><?php echo esc_html($label); ?></strong><br><?php echo (int) $counts[$key]; ?> 筆</div>
        <?php endforeach; ?>
    </div>
    <p>待送測試事件 <?php echo (int) $queue['waiting_test']; ?> 筆　舊版待送模式未知 <?php echo (int) $queue['waiting_unknown_mode']; ?> 筆</p>
    <p><?php echo esc_html($queue['waiting_reason']); ?>　第一筆到期 <?php echo esc_html(WUTM_ATM_Observability::date($queue['next_due'])); ?></p>
    <?php if ($api = $queue['latest_attempt']) : ?>
        <p>最近 API 回應 <?php echo esc_html($api['event_name'] . ' · ' . WUTM_ATM_Observability::date($api['attempted']) .
            ' · HTTP ' . $api['http_code'] . ' · 接收 ' . $api['received'] . ' · ' . WUTM_ATM_Observability::reason($api['reason'])); ?></p>
    <?php endif; ?>
    <p>下次檢查 <?php echo esc_html(WUTM_ATM_Observability::date($queue['next'])); ?>　最舊待發事件 <?php echo $queue['oldest'] ? esc_html(human_time_diff($queue['oldest'], time()) . '前') : '—'; ?></p>
    <p>上次 WP-Cron 處理 <?php echo esc_html(WUTM_ATM_Observability::date($queue['last_cron']['finished'] ?? 0)); ?></p>
    <p><?php echo esc_html(WUTM_ATM_Observability::run_summary($queue['last_cron'])); ?></p>
    <?php if (($queue['last_run']['trigger'] ?? '') === 'manual') : ?>
        <p>上次手動處理 <?php echo esc_html(WUTM_ATM_Observability::date($queue['last_run']['finished'])); ?>　<?php echo esc_html(WUTM_ATM_Observability::run_summary($queue['last_run'])); ?></p>
    <?php endif; ?>
    <details><summary>排程詳情</summary>
        <ul>
            <li>事件表 <?php echo $queue['table_ready'] ? '已就緒' : '尚未就緒'; ?></li>
            <li>事件處理 <code>wutm_atm_process_events</code>　<?php echo esc_html(WUTM_ATM_Observability::date($queue['process'])); ?>　已排 <?php echo (int) $queue['hook_counts'][WUTM_ATM_Queue::HOOK]; ?> 次</li>
            <li>維護排程 <code>wutm_atm_maintain_events</code>　<?php echo esc_html(WUTM_ATM_Observability::date($queue['maintenance'])); ?>　已排 <?php echo (int) $queue['hook_counts'][WUTM_ATM_Queue::SCHEDULE]; ?> 次</li>
            <li>處理逾時 <?php echo (int) $queue['stale']; ?> 筆</li>
        </ul>
        <p>剛入列的事件通常會先等待約 10 秒。WP-Cron 實際觸發仍依網站或主機排程環境。</p>
    </details>
    <form method="post" action="<?php echo esc_url($post); ?>">
        <?php wp_nonce_field('wutm_atm_process_due'); ?>
        <input type="hidden" name="action" value="wutm_atm_process_due">
        <input type="hidden" name="atm_order" value="<?php echo (int) $order_id; ?>">
        <?php if (is_string($_GET['atm_test'] ?? null) && preg_match('/^atm-[a-f0-9]{64}$/D', $_GET['atm_test'])) : ?>
            <input type="hidden" name="atm_test" value="<?php echo esc_attr($_GET['atm_test']); ?>">
        <?php endif; ?>
        <p><button class="button" <?php disabled(!$queue['table_ready']); ?>>處理到期事件</button>　一次最多檢查 10 筆到期事件。</p>
    </form>
</div></div>

<?php require WUTM_ATM_DIR . 'templates/acceptance-tools.php'; ?>

<div class="atm-card"><div class="atm-card-body">
    <h2>驗收報告</h2>
    <?php if ($report['test_mode']) : ?>
        <form method="post" action="<?php echo esc_url($post); ?>" data-atm-confirm="清除後，已排入的測試仍使用其原始代碼。">
            <?php wp_nonce_field('wutm_atm_clear_test_code'); ?><input type="hidden" name="action" value="wutm_atm_clear_test_code">
            <p><button class="button">清除測試代碼</button></p>
        </form>
    <?php endif; ?>
    <p>包含站內設定、排程、檢測時間與待人工確認項目。請在 Meta 核對真實接收與去重。</p>
    <form method="post" action="<?php echo esc_url($post); ?>">
        <?php wp_nonce_field('wutm_atm_export_audit'); ?><input type="hidden" name="action" value="wutm_atm_export_audit">
        <input type="hidden" name="atm_order" value="<?php echo (int) $order_id; ?>">
        <button class="button" name="format" value="client" formtarget="_blank">客戶交付版（可列印）</button>
        <button class="button" name="format" value="technical" formtarget="_blank">技術維護版（可列印）</button>
        <button class="button" name="format" value="csv">下載事件 CSV</button>
        <button class="button" name="format" value="json">下載 JSON</button>
        <button class="button" type="button" data-atm-copy>複製摘要</button>
        <span role="status" data-atm-copy-status></span>
    </form>
    <textarea id="wutm-atm-audit-summary" class="large-text" rows="4" readonly aria-label="驗收摘要"><?php
        $summary = ['WU Toolbox · 追蹤驗收',get_bloginfo('name') . ' · ' . home_url('/'),
            $report['date'] . ' · Advanced Tracking Manager ' . $report['module'],
            '資料集 ' . ($report['dataset'] ?: '尚未設定') . ' · 排程 ' . $status];
        foreach (WUTM_ATM_Acceptance::items() as $key=>$item) {
            $summary[] = $item[0] . ' · ' . $labels[$checks[$key]['state']];
        }
        echo esc_textarea(implode("\n", $summary));
    ?></textarea>
</div></div>
