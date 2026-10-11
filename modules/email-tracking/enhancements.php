<?php
defined('ABSPATH') || exit;

function wu_email_retention_days($value = null) {
    $value = $value === null ? get_option('wu_email_retention_days', 90) : $value;
    return max(1, min(365, (int)$value));
}

function wu_email_content_fields($mail) {
    if (!get_option('wu_email_log_content', 1)) return ['message' => null, 'content_type' => 'text/plain', 'content_truncated' => 0];
    $body = is_string($mail['message'] ?? null) ? $mail['message'] : '';
    $html = (bool)preg_match('/<\s*(?:html|body|table|div|p|br)\b/i', $body);
    $headers = $mail['headers'] ?? '';
    if (is_array($headers)) $headers = implode("\n", array_filter($headers, 'is_string'));
    if (is_string($headers) && preg_match('/Content-Type:\s*text\/html/i', $headers)) $html = true;
    $limit = 262144; // Keep per-message storage and detail responses bounded (256 KiB).
    $truncated = strlen($body) > $limit;
    $body = wp_check_invalid_utf8(substr($body, 0, $limit), true);
    return ['message' => $body, 'content_type' => $html ? 'text/html' : 'text/plain', 'content_truncated' => (int)$truncated];
}

function wu_email_daily_increment($status) {
    global $wpdb;
    if (!in_array($status, ['sent', 'failed', 'blocked'], true)) return;
    $wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->prefix}wu_email_daily (day,status,amount)
        VALUES (%s,%s,1) ON DUPLICATE KEY UPDATE amount=amount+1", current_time('Y-m-d'), $status));
}

function wu_email_quota_counts() {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare("SELECT
        COALESCE(SUM(CASE WHEN day=%s THEN amount ELSE 0 END),0) AS today_count,
        COALESCE(SUM(amount),0) AS month_count
        FROM {$wpdb->prefix}wu_email_daily WHERE day>=%s AND day<=%s AND status='sent'",
        current_time('Y-m-d'), current_time('Y-m-01'), current_time('Y-m-d'))) ?: (object)['today_count'=>0,'month_count'=>0];
}

function wu_email_cleanup() {
    global $wpdb;
    $cutoff = (new DateTimeImmutable(current_time('mysql'), wp_timezone()))
        ->modify('-' . wu_email_retention_days() . ' days')->format('Y-m-d H:i:s');
    // Scheduled, bounded work; never delete the whole table during a mail send/page render.
    $result = $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}wu_email_logs WHERE sent_time < %s ORDER BY sent_time,id LIMIT 1000", $cutoff));
    if ($result === 1000 && !wp_next_scheduled('wu_email_cleanup_batch')) {
        wp_schedule_single_event(time()+60, 'wu_email_cleanup_batch');
    }
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}wu_email_daily WHERE day < %s LIMIT 1000",
        (new DateTimeImmutable(current_time('mysql'), wp_timezone()))->modify('-730 days')->format('Y-m-d')));
}
add_action('wu_email_cleanup_batch', 'wu_email_cleanup');

function wu_email_log_filters($input) {
    $today = current_time('Y-m-d');
    $default_start = (new DateTimeImmutable($today, wp_timezone()))->modify('-29 days')->format('Y-m-d');
    $dates = [];
    foreach (['start'=>$default_start, 'end'=>$today] as $key=>$default) {
        $raw = $input['email_'.$key] ?? $default;
        $raw = is_string($raw) ? $raw : $default;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw, wp_timezone());
        $dates[$key] = $date && $date->format('Y-m-d') === $raw ? $raw : $default;
    }
    if ($dates['start'] > $dates['end']) $dates['start'] = $dates['end'];
    // Maximum one year per analysis request.
    $earliest = (new DateTimeImmutable($dates['end'], wp_timezone()))->modify('-364 days')->format('Y-m-d');
    if ($dates['start'] < $earliest) $dates['start'] = $earliest;
    $status = $input['email_status'] ?? '';
    $search = $input['email_search'] ?? '';
    return $dates + ['status'=>in_array($status,['sent','failed','blocked'],true)?$status:'',
        'search'=>is_string($search)?substr(sanitize_text_field(wp_unslash($search)),0,100):'',
        'page'=>max(1,min(10000,absint($input['email_page']??1)))];
}

function wu_email_log_where($filters) {
    global $wpdb;
    $end = (new DateTimeImmutable($filters['end'],wp_timezone()))->modify('+1 day')->format('Y-m-d');
    $where = $wpdb->prepare('sent_time >= %s AND sent_time < %s', $filters['start'].' 00:00:00', $end.' 00:00:00');
    if ($filters['status']) $where .= $wpdb->prepare(' AND status=%s',$filters['status']);
    if ($filters['search'] !== '') {
        $like = '%'.$wpdb->esc_like($filters['search']).'%';
        $where .= $wpdb->prepare(' AND (subject LIKE %s OR to_email LIKE %s)', $like, $like);
    }
    return $where;
}

function wu_email_preview_document($row) {
    $body = (string)($row->message ?? '');
    if (($row->content_type??'') !== 'text/html') $body = '<pre style="white-space:pre-wrap;overflow-wrap:anywhere">'.esc_html($body).'</pre>';
    else $body = wp_kses_post($body);
    // Sandboxed in admin. CSP blocks external images/fonts/trackers, forms, scripts and links.
    return '<!doctype html><html><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="default-src &#39;none&#39;; style-src &#39;unsafe-inline&#39;; img-src data:; base-uri &#39;none&#39;; form-action &#39;none&#39;"><style>body{font:15px/1.6 Arial;padding:16px;color:#1d2327;overflow-wrap:anywhere}img,table{max-width:100%}a{pointer-events:none}</style></head><body>'.$body.'</body></html>';
}

function wu_email_log_detail() {
    if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'權限不足'],403);
    check_ajax_referer('wu_email_detail','nonce');
    global $wpdb;
    $id = absint($_POST['id']??0);
    $row = $wpdb->get_row($wpdb->prepare("SELECT id,to_email,subject,status,error_message,send_method,sent_time,message,content_type,content_truncated FROM {$wpdb->prefix}wu_email_logs WHERE id=%d",$id));
    nocache_headers();
    if (!$row) wp_send_json_error(['message'=>'紀錄不存在或已超過保留期限'],404);
    wp_send_json_success(['subject'=>$row->subject,'to'=>$row->to_email,'status'=>$row->status,
        'time'=>$row->sent_time,'method'=>$row->send_method,'error'=>$row->error_message,
        'has_content'=>isset($row->message)&&$row->message!=='','truncated'=>(bool)$row->content_truncated,
        'preview'=>wu_email_preview_document($row)]);
}
add_action('wp_ajax_wu_email_log_detail','wu_email_log_detail');

function wu_email_render_logs() {
    global $wpdb;
    $f = wu_email_log_filters($_GET);
    $where = wu_email_log_where($f);
    $table = $wpdb->prefix.'wu_email_logs';
    $summary = $wpdb->get_row("SELECT COUNT(*) total,SUM(status='sent') sent,SUM(status='failed') failed,SUM(status='blocked') blocked FROM $table WHERE $where");
    $total = (int)($summary->total??0);
    $pages = max(1,(int)ceil($total/25));$f['page']=min($f['page'],$pages);
    // Deliberately exclude message bodies from list/analysis queries.
    $rows = $wpdb->get_results($wpdb->prepare("SELECT id,to_email,subject,status,error_message,send_method,sent_time FROM $table WHERE $where ORDER BY sent_time DESC,id DESC LIMIT 25 OFFSET %d",($f['page']-1)*25));
    $daily = $wpdb->get_results("SELECT DATE(sent_time) day,SUM(status='sent') sent,SUM(status='failed') failed,SUM(status='blocked') blocked FROM $table WHERE $where GROUP BY DATE(sent_time) ORDER BY day");
    $methods = $wpdb->get_results("SELECT send_method,COUNT(*) amount FROM $table WHERE $where GROUP BY send_method ORDER BY amount DESC LIMIT 10");
    $labels=['sent'=>'成功交件','failed'=>'失敗','blocked'=>'額度阻擋'];
    $attempted=(int)($summary->sent??0)+(int)($summary->failed??0);
    ?>
    <section class="wu-mail-panel" id="wu-mail-records">
      <h2>電子郵件紀錄與分析</h2>
      <p>成功代表寄送服務接受郵件，不保證投遞至收件匣；不追蹤開信或點擊。分析僅包含目前保留且符合篩選的紀錄。</p>
      <form method="get" class="wu-mail-filters">
        <input type="hidden" name="page" value="wu-email-tracking">
        <label>開始日期<input type="date" name="email_start" value="<?php echo esc_attr($f['start']); ?>"></label>
        <label>結束日期<input type="date" name="email_end" value="<?php echo esc_attr($f['end']); ?>"></label>
        <label>狀態<select name="email_status"><option value="">全部</option><?php foreach($labels as $key=>$label): ?><option value="<?php echo esc_attr($key); ?>" <?php selected($f['status'],$key); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label>
        <label>搜尋主旨／收件者<input name="email_search" maxlength="100" value="<?php echo esc_attr($f['search']); ?>"></label>
        <button class="button button-primary">套用篩選</button>
      </form>
      <div class="wu-mail-metrics">
        <?php foreach(['符合紀錄'=>$total,'成功交件'=>(int)($summary->sent??0),'失敗'=>(int)($summary->failed??0),'額度阻擋'=>(int)($summary->blocked??0),'交件成功率'=>$attempted?round(100*(int)$summary->sent/$attempted,1).'%':'—'] as $label=>$value): ?>
        <div><span><?php echo esc_html($label); ?></span><strong><?php echo esc_html($value); ?></strong></div><?php endforeach; ?>
      </div>
      <details class="wu-mail-trends" open><summary>每日趨勢與發送方式</summary>
        <p class="wu-mail-legend">綠色：成功交件　紅色：失敗　黃色：額度阻擋（完整數字見下表）</p>
        <div class="wu-mail-chart" role="img" aria-label="每日寄信量，成功、失敗與阻擋的詳細數字列於下方表格">
        <?php $maximum=1;foreach($daily as $d)$maximum=max($maximum,(int)$d->sent+(int)$d->failed+(int)$d->blocked);
        foreach($daily as $d): ?><div class="wu-mail-day" title="<?php echo esc_attr($d->day.' 成功 '.$d->sent.'／失敗 '.$d->failed.'／阻擋 '.$d->blocked); ?>"><div class="wu-mail-bar"><?php foreach(['sent','failed','blocked'] as $s): ?><span class="<?php echo esc_attr($s); ?>" style="height:<?php echo round(100*(int)$d->$s/$maximum,2); ?>%"></span><?php endforeach; ?></div><small><?php echo esc_html(substr($d->day,5)); ?></small></div><?php endforeach; ?>
        </div>
        <div class="wu-mail-scroll"><table class="widefat striped"><thead><tr><th>日期</th><th>成功</th><th>失敗</th><th>阻擋</th><th>發送量</th></tr></thead><tbody>
        <?php $maximum=1;foreach($daily as $d)$maximum=max($maximum,(int)$d->sent+(int)$d->failed+(int)$d->blocked);
        foreach($daily as $d):$n=(int)$d->sent+(int)$d->failed+(int)$d->blocked; ?>
        <tr><td><?php echo esc_html($d->day); ?></td><td><?php echo (int)$d->sent; ?></td><td><?php echo (int)$d->failed; ?></td><td><?php echo (int)$d->blocked; ?></td><td><meter min="0" max="<?php echo $maximum; ?>" value="<?php echo $n; ?>"><?php echo $n; ?></meter> <?php echo $n; ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <p><?php foreach($methods as $m)echo esc_html($m->send_method).'：'.(int)$m->amount.'　'; ?></p>
      </details>
      <div class="wu-mail-scroll"><table class="widefat striped wu-mail-table"><thead><tr><th>時間</th><th>主旨</th><th>收件者</th><th>方式</th><th>狀態</th><th>備註／內容</th></tr></thead><tbody>
      <?php foreach($rows as $row): ?>
      <tr><td data-label="時間"><?php echo esc_html($row->sent_time); ?></td><td data-label="主旨"><?php echo esc_html($row->subject); ?></td><td data-label="收件者"><?php echo esc_html($row->to_email); ?></td><td data-label="方式"><?php echo esc_html($row->send_method); ?></td><td data-label="狀態"><span class="wu-mail-status <?php echo esc_attr($row->status); ?>"><?php echo esc_html($labels[$row->status]??$row->status); ?></span></td><td data-label="備註／內容"><small><?php echo esc_html($row->error_message); ?></small><br><button type="button" class="button wu-mail-view" data-id="<?php echo (int)$row->id; ?>">查看信件</button></td></tr>
      <?php endforeach; if(!$rows)echo '<tr><td colspan="6">此範圍沒有紀錄</td></tr>'; ?>
      </tbody></table></div>
      <nav class="wu-mail-pagination" aria-label="郵件紀錄分頁">
      <?php foreach(['上一頁'=>$f['page']-1,'下一頁'=>$f['page']+1] as $label=>$p)if($p>=1&&$p<=$pages)echo '<a class="button" href="'.esc_url(add_query_arg(['page'=>'wu-email-tracking','email_start'=>$f['start'],'email_end'=>$f['end'],'email_status'=>$f['status'],'email_search'=>$f['search'],'email_page'=>$p],admin_url('admin.php'))).'#wu-mail-records">'.esc_html($label).'</a>'; ?>
      <span><?php echo $f['page'].' / '.$pages.' 頁，共 '.$total.' 筆'; ?></span></nav>
    </section>
    <dialog id="wu-mail-dialog" aria-labelledby="wu-mail-detail-title"><div class="wu-mail-dialog-header"><h2 id="wu-mail-detail-title">信件內容</h2><button class="button wu-mail-close" type="button">關閉</button></div><p class="wu-mail-detail-state" role="status"></p><dl class="wu-mail-detail-meta"></dl><iframe title="安全信件預覽" sandbox="" referrerpolicy="no-referrer"></iframe></dialog>
    <?php
}

add_action('admin_enqueue_scripts',function($hook){
    // WordPress derives the hook prefix from the parent menu's title, not its slug.
    // Use the actual registered hook; the unique page suffix also supports menu renaming.
    $registered = $GLOBALS['wu_email_tracking_page_hook'] ?? '';
    $suffix = '_page_wu-email-tracking';
    if (!current_user_can('manage_options') || !is_string($hook)
        || !(($registered && $hook === $registered) || substr($hook, -strlen($suffix)) === $suffix)) return;
    wp_enqueue_style('wu-email-admin',plugins_url('admin.css',__FILE__),[],WUTM_VERSION);
    wp_enqueue_script('wu-email-admin',plugins_url('admin.js',__FILE__),[],WUTM_VERSION,true);
    wp_localize_script('wu-email-admin','wuEmailAdmin',['url'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('wu_email_detail')]);
});
