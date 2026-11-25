<?php
defined('ABSPATH') || exit;

final class WUTM_ATM_Testing_Tools {
    public static function boot(): void {
        add_action('admin_post_wutm_atm_simulation', [__CLASS__, 'simulate']);
        add_action('admin_post_wutm_atm_technical_retry', [__CLASS__, 'retry']);
    }

    public static function order_args(): array {
        $id = absint(WUTM_ATM_Event_Admin::posted('atm_order'));
        return $id ? ['atm_order'=>$id] : [];
    }

    public static function simulate(): void {
        $args = self::order_args();
        $id = $args['atm_order'] ?? 0;
        WUTM_ATM_Event_Admin::guard('wutm_atm_simulation_' . $id);
        if (!$id || WUTM_ATM_Event_Admin::posted('verified') !== 'yes') wp_die('請先核對並確認測試訂單。', '', ['response'=>400]);
        require_once __DIR__ . '/class-purchase.php';
        $run = WUTM_ATM_Purchase::simulate($id, WUTM_ATM_Event_Admin::posted('shared_target') === 'yes',
            WUTM_ATM_Event_Admin::posted('reviewed_settings'));
        $messages = [
            'test'=>'模擬 Purchase 已排入測試佇列。','existing'=>'此訂單已有相同測試設定的事件。','consent'=>'此訂單沒有有效的行銷同意。',
            'invalid_total'=>'訂單金額或幣別無效。','unavailable'=>'Purchase、同意或測試設定需要檢查。','busy'=>'訂單目前無法處理，請稍後重試。',
        ];
        set_transient('wutm_atm_admin_result_' . get_current_user_id(), $messages[$run['result']], 120);
        WUTM_ATM_Event_Admin::redirect('overview', isset($run['id']) ? $args + ['atm_test'=>$run['id']] : $args);
    }

    public static function retry(): void {
        $args = self::order_args();
        $id = $args['atm_order'] ?? 0;
        WUTM_ATM_Event_Admin::guard('wutm_atm_technical_retry_' . $id);
        require_once __DIR__ . '/class-purchase.php';
        $result = WUTM_ATM_Purchase::act($id, 'retry');
        set_transient('wutm_atm_admin_result_' . get_current_user_id(),
            $result === 'queued' ? '已將原事件排入重試。' : '此事件目前不符合重試條件。', 90);
        WUTM_ATM_Event_Admin::redirect('overview', $args);
    }

    public static function history_url($row): string {
        return add_query_arg(['page'=>'wu-advanced-tracking-manager','atm_tab'=>'history','event_detail'=>$row->event_id], admin_url('admin.php'));
    }
}
