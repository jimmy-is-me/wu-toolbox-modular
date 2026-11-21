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
<?php if ($report['test_mode']) : ?>
    <div class="notice notice-warning"><p><strong>測試模式</strong>　新排入的 CAPI 事件會使用測試代碼。請在 Meta 核對測試及報表表現。</p></div>
<?php endif; ?>
<div class="atm-card"><div class="atm-card-body">
    <h2>追蹤總覽</h2>
    <div class="wutm-atm-event-grid">
        <?php foreach ($report['platforms'] as $platform=>$label) : ?><div><strong><?php echo esc_html($platform); ?></strong><br><?php echo esc_html($label); ?></div><?php endforeach; ?>
        <div><strong>WooCommerce</strong><br><?php echo !$report['woocommerce'] ? '未安裝' : ($report['woo_events'] && $report['events_enabled'] ? '追蹤接點已啟用' : '追蹤接點未啟用'); ?></div>
    </div>
    <p>今天的事件依網站時區統計。最近一次 CAPI 確認接收 <?php echo esc_html(WUTM_ATM_Observability::date($queue['last_success'])); ?></p>
    <?php foreach ($queue['today'] as $row) : ?>
        <span class="wutm-atm-metric"><?php echo esc_html($row['event_name'] . ' ' . $row['total']); ?></span>
    <?php endforeach; ?>
    <?php if (!$queue['today']) : ?><p>今日尚無事件紀錄。</p><?php endif; ?>
    <p>今日 CAPI 確認接收 <?php echo (int) $queue['sent_today']; ?> 筆</p>
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
        <p><button class="button" <?php disabled(!$queue['table_ready']); ?>>立即檢查待發事件</button>　一次最多檢查 10 筆到期事件。</p>
    </form>
</div></div>

<?php if ($report['woocommerce']) : ?>
<div class="atm-card"><div class="atm-card-body">
    <h2>訂單追蹤檢查器</h2>
    <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
        <input type="hidden" name="page" value="wu-advanced-tracking-manager">
        <input type="hidden" name="atm_tab" value="overview">
        <label>WooCommerce 訂單 ID <input type="number" name="atm_order" value="<?php echo esc_attr($order_id ?: ''); ?>" min="1" required></label>
        <button class="button">核對訂單</button>
    </form>
    <?php if ($order_id && !$check) : ?><p>找不到這筆訂單。</p><?php endif; ?>
    <?php if ($check) : ?>
        <dl class="wutm-atm-audit-definition">
            <?php foreach ($check as $label=>$value) : ?><dt><?php echo esc_html($label); ?></dt><dd><?php echo esc_html($value); ?></dd><?php endforeach; ?>
        </dl>
    <?php endif; ?>
</div></div>
<?php endif; ?>

<div class="atm-card"><div class="atm-card-body">
    <h2>追蹤設定與驗收</h2>
    <details open><summary>基本平台設定</summary>
        <p>Pixel <?php echo esc_html($report['pixel'] ?: '尚未設定'); ?>　資料集 <?php echo esc_html($report['dataset'] ?: '尚未設定'); ?>　Token <?php echo $report['token_configured'] ? '已設定' : '尚未設定'; ?></p>
        <p>通道 <?php echo esc_html(['browser'=>'瀏覽器','server'=>'伺服器','both'=>'雙通道'][$report['mode']] ?? '尚未設定'); ?>　
        <?php if ($report['mode'] === 'both') echo $report['pixel'] && $report['pixel'] === $report['dataset'] ? '兩個通道的 Meta ID 一致。' : '請核對兩個通道是否使用同一資料集。'; ?></p>
        <p>前台重複安裝 <?php echo $report['frontend_check']['possible_external_tags'] ? '上次掃描發現疑似獨立或重複標記，請檢查自動診斷。' : '尚需在瀏覽器與標籤管理工具核對。'; ?></p>
    </details>
    <details><summary>Cookie 同意</summary>
        <p><?php echo esc_html(['opt_in'=>'取得同意後啟用','opt_out'=>'預設啟用，可手動拒絕','external'=>'內建同意關閉，請核對網站整合'][$report['privacy']['mode']] ?? '需要檢查'); ?></p>
        <?php if ($report['privacy']['built_in']) : ?>
        <p>分析 <?php echo $report['privacy']['analytics_default'] ? '預設允許' : '等待同意'; ?>　行銷 <?php echo $report['privacy']['marketing_default'] ? '瀏覽器預設允許' : '等待同意'; ?>　紀錄表 <?php echo $report['privacy']['table_ready'] ? '存在' : '尚未建立或需檢查'; ?></p>
        <?php endif; ?>
        <p>CAPI 需要可重新驗證的同意收據。以無痕視窗依序確認同意後載入、拒絕後停止、撤回後既有待送事件受限制。預設啟用可能不適用所有法規或用途。</p>
    </details>
    <details open><summary>Meta CAPI 設定、讀取及發送測試</summary>
        <p>Graph API <?php echo esc_html($report['version']); ?>　<?php echo esc_html($report['version_warning']); ?> <a href="https://developers.facebook.com/docs/graph-api/changelog/versions/" target="_blank" rel="noopener noreferrer">官方版本時程</a></p>
        <dl class="wutm-atm-audit-definition">
            <?php foreach (['connection'=>'資料集資訊讀取','test'=>'合成 PageView 發送'] as $key=>$label) : $meta = $report['meta'][$key] ?? []; ?>
                <dt><?php echo esc_html($label); ?></dt><dd>
                    <?php if ($meta) : ?>
                        <?php echo esc_html($meta['message'] . ' ' . WUTM_ATM_Observability::date($meta['time'])); ?>
                        <?php if (!$meta['current_config']) echo '（測試後設定已變更）'; ?>
                        <?php if ($meta['http']) echo ' HTTP ' . (int) $meta['http']; ?>
                        <?php if ($key === 'test') echo ' · events_received ' . (int) $meta['received']; ?>
                        <?php if ($meta['error']) echo ' · ' . esc_html(WUTM_ATM_Observability::reason($meta['error'])); ?>
                        <?php if (!empty($meta['event_id'])) : ?><br><code><?php echo esc_html($meta['event_id']); ?></code><?php endif; ?>
                    <?php else : ?>尚未驗證<?php endif; ?>
                </dd>
            <?php endforeach; ?>
        </dl>
        <form method="post" action="<?php echo esc_url($post); ?>">
            <?php wp_nonce_field('wutm_atm_event_diagnostic'); ?>
            <input type="hidden" name="action" value="wutm_atm_event_diagnostic">
            <button class="button" name="diagnostic" value="connection">檢查資料集資訊</button>
            <button class="button" name="diagnostic" value="test" <?php disabled(!$report['test_mode']); ?>>發送合成 PageView</button>
        </form>
    </details>
    <details><summary>測試電商事件</summary>
        <p>使用測試站開商品頁核對 ViewContent，加入購物車核對 AddToCart，再進結帳核對 InitiateCheckout。以測試金流付款後在上方訂單檢查器對照 Purchase 的幣別、總額及事件 ID。</p>
        <p>另測試付款未完成、取消、非同步回呼、重複通知及重整完成頁。</p>
    </details>
    <details><summary>核對瀏覽器、伺服器與去重</summary>
        <p>從事件紀錄開啟詳細資料。在 <a href="https://business.facebook.com/events_manager2/" target="_blank" rel="noopener noreferrer">Meta Events Manager</a> 選取相同資料集，開啟「測試事件」，比對事件名稱、時間、來源與事件 ID。若須留存畫面，擷取事件來源與去重結果並遮蔽個資。</p>
    </details>
    <details><summary>正式上線</summary>
        <p>確認站內失敗或逾期項目已處理、購物測試完成、獨立 Purchase 程式已核對，並在 Meta 查看測試結果。上線前請清除測試代碼。</p>
        <p>待送測試事件 <?php echo (int) $queue['waiting_test']; ?> 筆；舊紀錄模式未知 <?php echo (int) $queue['waiting_unknown_mode']; ?> 筆。</p>
        <?php if ($report['test_mode']) : ?>
        <form method="post" action="<?php echo esc_url($post); ?>" data-atm-confirm="確定清除測試代碼？現有待送事件會保留原模式。">
            <?php wp_nonce_field('wutm_atm_clear_test_code'); ?><input type="hidden" name="action" value="wutm_atm_clear_test_code">
            <button class="button">清除測試代碼</button>
        </form>
        <?php endif; ?>
    </details>
</div></div>

<div class="atm-card"><div class="atm-card-body">
    <h2>驗收報告</h2>
    <p>包含站內設定、排程、檢測時間與待人工確認項目。請在 Meta 核對真實接收與去重。</p>
    <form method="post" action="<?php echo esc_url($post); ?>">
        <?php wp_nonce_field('wutm_atm_export_audit'); ?><input type="hidden" name="action" value="wutm_atm_export_audit">
        <button class="button">下載 JSON</button>
        <button class="button" type="button" data-atm-copy>複製摘要</button>
        <span role="status" data-atm-copy-status></span>
    </form>
    <textarea id="wutm-atm-audit-summary" class="large-text" rows="4" readonly aria-label="驗收摘要"><?php
        echo esc_textarea("WU Toolbox · 追蹤驗收\n" . $report['date'] . ' · ' . $report['environment'] . " · v" . $report['module'] . "\n" .
            '資料集 ' . ($report['dataset'] ?: '尚未設定') . ' · 排程 ' . $status . ' · 待發送 ' . $counts['pending'] . ' 筆 · 待重試 ' . $counts['retry'] . " 筆\n" .
            '合成事件 ' . ($report['meta']['test']['message'] ?? '尚未驗證') . "\n" . 'Meta 事件來源與去重、Cookie 操作、實際付款需人工核對。');
    ?></textarea>
</div></div>
