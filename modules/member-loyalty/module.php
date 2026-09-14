<?php
/**
 * WU Toolbox Modular：會員點數與階級。
 *
 * 以單一模組載入會員點數與會員階級，並保留兩套既有設定、資料表、短代碼及會員資料。
 * 會員階級的點數回饋倍率會透過 wmt_points_multiplier 套用至會員點數回饋計算。
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/points.php';
require_once __DIR__ . '/tiers.php';

add_action('admin_menu', function (): void {
    add_submenu_page(
        'wu-toolbox-modular',
        '會員點數與階級',
        '會員點數與階級',
        'manage_woocommerce',
        'wu-member-loyalty',
        'wutm_render_member_loyalty_page'
    );
}, 20);

// Keep only the unified WU Toolbox entry visible. The original page slugs stay
// registered so bookmarks, form redirects and existing integrations keep working.
add_action('admin_menu', function (): void {
    remove_submenu_page('wu-toolbox-modular', 'wcmp-points');
    remove_menu_page('wmt-tiers');
}, 998);

function wutm_render_member_loyalty_page(): void {
    if (!current_user_can('manage_woocommerce')) {
        wp_die(esc_html__('權限不足', 'wu-toolbox-modular'));
    }

    $points_settings = wp_parse_args((array) get_option('wcmp_settings', []), [
        'enable_points' => 'yes',
        'enable_earn' => 'yes',
        'enable_redeem' => 'yes',
    ]);
    $tier_settings = wp_parse_args((array) get_option('wmt_settings', []), [
        'enable' => 'yes',
    ]);
    $points_active = $points_settings['enable_points'] === 'yes';
    $tiers_active = $tier_settings['enable'] === 'yes';
    ?>
    <div class="wrap wutm-loyalty-wrap">
        <h1>會員點數與階級</h1>
        <p class="description">兩項會員制度由同一個 WU Toolbox 模組載入。會員階級設定的點數回饋倍率，會自動套用至會員點數的訂單回饋計算。</p>

        <div class="wutm-loyalty-grid">
            <section class="wutm-loyalty-card">
                <div class="wutm-loyalty-heading">
                    <span class="dashicons dashicons-star-filled" aria-hidden="true"></span>
                    <h2>會員點數</h2>
                    <span class="wutm-loyalty-status <?php echo $points_active ? 'is-active' : 'is-paused'; ?>">
                        <?php echo esc_html($points_active ? '運作中' : '已暫停'); ?>
                    </span>
                </div>
                <p>管理消費回饋、結帳折抵、點數效期、會員餘額與人工調整。</p>
                <p>
                    <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=wcmp-points')); ?>">開啟點數管理</a>
                </p>
            </section>

            <section class="wutm-loyalty-card">
                <div class="wutm-loyalty-heading">
                    <span class="dashicons dashicons-awards" aria-hidden="true"></span>
                    <h2>會員階級</h2>
                    <span class="wutm-loyalty-status <?php echo $tiers_active ? 'is-active' : 'is-paused'; ?>">
                        <?php echo esc_html($tiers_active ? '運作中' : '已暫停'); ?>
                    </span>
                </div>
                <p>管理會員分級、升降級條件、專屬折扣、點數倍率、會員權益與 CRM。</p>
                <p class="wutm-loyalty-actions">
                    <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=wmt-tiers')); ?>">開啟階級管理</a>
                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=wmt-members')); ?>">會員名單</a>
                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=wmt-settings')); ?>">全站設定</a>
                </p>
            </section>
        </div>

        <div class="notice notice-info inline wutm-loyalty-note">
            <p><strong>資料相容性：</strong>整併只調整模組入口與載入方式，不會搬移或清除既有點數帳本、會員階級、訂單紀錄、短代碼或設定。</p>
        </div>
    </div>
    <style>
        .wutm-loyalty-wrap{max-width:1100px}.wutm-loyalty-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;margin:22px 0}.wutm-loyalty-card{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:22px;box-shadow:0 1px 2px rgba(0,0,0,.04)}.wutm-loyalty-heading{display:flex;align-items:center;gap:10px}.wutm-loyalty-heading .dashicons{color:#2271b1;font-size:24px;width:24px;height:24px}.wutm-loyalty-heading h2{margin:0}.wutm-loyalty-status{margin-left:auto;padding:4px 9px;border-radius:20px;font-size:12px;font-weight:700}.wutm-loyalty-status.is-active{background:#edfaef;color:#007017}.wutm-loyalty-status.is-paused{background:#f0f0f1;color:#50575e}.wutm-loyalty-card>p{font-size:14px;line-height:1.7}.wutm-loyalty-actions{display:flex;gap:8px;flex-wrap:wrap}.wutm-loyalty-note{margin-top:0!important}@media(max-width:782px){.wutm-loyalty-grid{grid-template-columns:1fr}.wutm-loyalty-card{padding:18px}.wutm-loyalty-heading{flex-wrap:wrap}.wutm-loyalty-status{margin-left:0}}
    </style>
    <?php
}
