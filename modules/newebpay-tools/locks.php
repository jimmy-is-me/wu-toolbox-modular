<?php
defined('ABSPATH') || exit;
/** Atomic, non-autoloaded per-order locks. Expired takeover uses compare/delete. */
function wutm_commerce_lock(string $scope,int $id): ?array {
    $key='wutm_commerce_lock_'.sanitize_key($scope).'_'.$id;
    $token=bin2hex(random_bytes(12)).':'.(time()+300);
    if(add_option($key,$token,'',false)) return [$key,$token];
    $old=get_option($key,''); $parts=is_string($old)?explode(':',$old):[];
    if(count($parts)!==2||!preg_match('/^[0-9]+$/D',$parts[1])||(int)$parts[1]>=time()) return null;
    global $wpdb;
    if($wpdb->delete($wpdb->options,['option_name'=>$key,'option_value'=>$old],['%s','%s'])!==1) return null;
    wp_cache_delete($key,'options'); wp_cache_delete('notoptions','options');
    return add_option($key,$token,'',false)?[$key,$token]:null;
}
function wutm_commerce_unlock(array $lock): void {
    global $wpdb;
    $wpdb->delete($wpdb->options,['option_name'=>$lock[0],'option_value'=>$lock[1]],['%s','%s']);
    wp_cache_delete($lock[0],'options'); wp_cache_delete('notoptions','options');
}
