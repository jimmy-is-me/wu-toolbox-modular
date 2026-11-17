<?php
defined('ABSPATH') || exit;
/** Resolve a manual expiry without changing data; all dates use the site timezone. */
function wutm_member_manual_expiry($mode, $date, $days, $current, $default_days) {
    $now=current_datetime();
    if ($mode==='keep') return $current ?: null;
    if ($mode==='permanent') return null;
    if ($mode==='date') {
        $date=str_replace('T',' ',(string)$date);
        if (strlen($date)===16) $date.=':00';
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',$date)) return new WP_Error('invalid_expiry','請填寫完整效期日期與時間。');
        $end=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$date,wp_timezone());
        return $end && $end->format('Y-m-d H:i:s')===$date ? $date : new WP_Error('invalid_expiry','效期日期無效。');
    }
    if ($mode==='default') return $default_days>0 ? $now->modify('+'.min(36500,(int)$default_days).' days')->format('Y-m-d H:i:s') : null;
    if (!in_array($mode,['shift','days'],true) || filter_var($days,FILTER_VALIDATE_INT)===false || abs((int)$days)>36500) return new WP_Error('invalid_days','請填寫 -36500 至 36500 的整數天數。');
    if ($mode==='shift' && !$current) return new WP_Error('permanent_expiry','永久會員請先指定效期日期，再增減天數。');
    $base=$mode==='shift' ? new DateTimeImmutable($current,wp_timezone()) : $now;
    return $base->modify(sprintf('%+d days',(int)$days))->format('Y-m-d H:i:s');
}
