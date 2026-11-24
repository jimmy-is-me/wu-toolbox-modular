<?php defined('ABSPATH') || exit; ?>
<!doctype html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo esc_html($title); ?></title>
<style>
body{font:16px/1.6 system-ui,sans-serif;color:#172735;background:#f3f6f8;margin:0}
main{max-width:960px;margin:32px auto;background:white;padding:clamp(16px,4vw,40px)}
h1,h2{line-height:1.25} p{margin:8px 0} table{width:100%;border-collapse:collapse}
td,th{text-align:left;vertical-align:top;border-bottom:1px solid #dce2e8;padding:8px;overflow-wrap:anywhere}
th{font-weight:700} dt{font-weight:700} dd{margin:0 0 8px;overflow-wrap:anywhere}
.table{overflow:auto}
@media print{body{background:white;font-size:11pt} main{margin:0;padding:0} h2{break-after:avoid} tr{break-inside:avoid} .table{overflow:visible}}
</style>
</head>
<body><main>
<h1><?php echo esc_html($title); ?></h1>
<p><?php echo esc_html(get_bloginfo('name') . ' · ' . home_url('/')); ?></p>
<p><?php echo esc_html('產生日期 ' . $report['date'] . ' · WU Toolbox Modular ' . (defined('WUTM_VERSION') ? WUTM_VERSION : '—') . ' · Advanced Tracking Manager ' . $report['module']); ?></p>
<dl>
<dt>追蹤整合</dt><dd><?php echo esc_html(implode('、', array_keys(array_filter($report['platforms'], static function ($status) { return $status === '已設定'; }))) ?: '尚未設定'); ?></dd>
<dt>Pixel／正式資料集</dt><dd><?php echo esc_html(($report['pixel'] ?: '—') . '／' . ($report['dataset'] ?: '—')); ?></dd>
<dt>已啟用事件</dt><dd><?php echo esc_html($report['events_enabled'] ? (implode('、', $report['events']) ?: '未選擇') : '已停用'); ?></dd>
<dt>Cookie 同意</dt><dd><?php echo esc_html(['opt_in'=>'內建，取得同意後啟用','opt_out'=>'內建，依站點配置預設啟用，訪客可拒絕','external'=>'沿用外部同意整合'][$report['privacy']['mode']] ?? '需要檢查'); ?></dd>
<dt>所選樣本的測試金額</dt><dd><?php
    $sample = array_values(array_filter($rows, static function ($row) { return (int) $row->test_mode === 1; }))[0] ?? null;
    echo esc_html($sample && $sample->amount !== '' ? $sample->currency . ' ' . $sample->amount : '尚無所選訂單的測試金額');
?></dd>
</dl>
<h2>驗收項目</h2>
<table><thead><tr><th>項目</th><th>核對時機</th><th>狀態及驗收時間</th></tr></thead><tbody>
<?php foreach (WUTM_ATM_Acceptance::items() as $key=>$item) : $entry = $list[$key]; ?>
<tr><th scope="row"><?php echo esc_html($item[0]); ?></th><td><?php echo esc_html($item[1]); ?></td>
<td><?php echo esc_html($labels[$entry['state']] . (!empty($entry['time']) ? ' · ' . WUTM_ATM_Observability::date($entry['time']) : ''));
    if (!empty($entry['note'])) echo '<br>' . esc_html($entry['note']); ?></td></tr>
<?php endforeach; ?>
</tbody></table>
<h2>使用範圍</h2>
<p>結果依右欄標示的證據成立。合成事件的 API 回應僅代表該次請求獲得接收確認。瀏覽器與伺服器去重、金流、Cookie 與 CDN 需在實際部署環境核對。</p>
<p><?php
$open = [];
foreach ($list as $key=>$entry) if (in_array($entry['state'], ['not_tested','manual','failed','api'], true)) $open[] = WUTM_ATM_Acceptance::items()[$key][0];
echo esc_html('待核對項目　' . ($open ? implode('、', $open) : '目前清單無未完成項目。'));
?></p>
<?php if ($technical) : ?>
<h2>技術設定與排程</h2>
<?php $queue = $report['queue']; ?>
<dl>
<dt>測試資料集</dt><dd><?php echo esc_html($report['test_dataset'] ?: '—'); ?></dd>
<dt>待送／重試</dt><dd><?php echo esc_html($queue['counts']['pending'] . '／' . $queue['counts']['retry'] . ' · ' . $queue['waiting_reason']); ?></dd>
<dt>下次排程／上次執行</dt><dd><?php echo esc_html(WUTM_ATM_Observability::date($queue['next']) . '／' . WUTM_ATM_Observability::date($queue['last_run']['finished'] ?? 0)); ?></dd>
<?php foreach ($report['meta'] as $kind=>$record) : ?>
<dt><?php echo esc_html($kind === 'test' ? '合成 PageView' : '資料集連線'); ?></dt>
<dd><?php echo esc_html(($record['message'] ?? '') . ' · HTTP ' . ($record['http'] ?? 0) . ' · 接收 ' . ($record['received'] ?? 0) . ' · ' . ($record['event_id'] ?? '')); ?></dd>
<?php endforeach; ?>
<?php foreach ($order as $label=>$value) : ?><dt><?php echo esc_html($label); ?></dt><dd><?php echo esc_html($value); ?></dd><?php endforeach; ?>
</dl>
<h2>最近事件（最多 200 筆）</h2>
<div class="table"><table><thead><tr><th>事件識別</th><th>事件與模式</th><th>發送狀態</th><th>API 回應</th></tr></thead><tbody>
<?php foreach (WUTM_ATM_Acceptance::rows() as $row) : ?>
<tr><td><?php echo esc_html($row['event_id']); ?></td>
<td><?php echo esc_html($row['event_name'] . ' · ' . ($row['test_mode'] === null ? '未知' : ((int) $row['test_mode'] ? '測試' : '正式'))); ?></td>
<td><?php echo esc_html((WUTM_ATM_Event_Admin::labels()[$row['status']] ?? $row['status']) . ' · ' . WUTM_ATM_Observability::date($row['updated'])); ?></td>
<td><?php echo esc_html('HTTP ' . $row['http_code'] . ' · ' . $row['received'] . ' 筆 · ' . WUTM_ATM_Observability::reason($row['reason'])); ?></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>
</main></body></html>
