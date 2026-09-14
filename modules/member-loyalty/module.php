<?php
/**
 * WU Toolbox Modular：會員點數與階級。
 *
 * 以單一模組及分頁介面載入會員點數、會員階級、CRM 與 VIP 隱密賣場。
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/points.php';
require_once __DIR__ . '/tiers.php';

add_action('admin_menu', function (): void {
    add_submenu_page('wu-toolbox-modular', '會員點數與階級', '會員點數與階級', 'manage_woocommerce', 'wu-member-loyalty', 'wutm_render_member_loyalty_page');
}, 20);

function wutm_member_loyalty_url(string $section = 'points', array $args = []): string {
    return add_query_arg(array_merge(['page' => 'wu-member-loyalty', 'section' => $section], $args), admin_url('admin.php'));
}

function wutm_render_member_loyalty_page(): void {
    if (!current_user_can('manage_woocommerce')) wp_die(esc_html__('權限不足', 'wu-toolbox-modular'));

    $section = isset($_GET['section']) ? sanitize_key(wp_unslash($_GET['section'])) : 'points';
    $sections = [
        'points' => '點數管理',
        'tiers' => '階級與會員',
        'vip-store' => 'VIP 隱密賣場',
    ];
    if (!isset($sections[$section])) $section = 'points';
    ?>
    <div class="wrap wutm-loyalty-wrap">
        <h1>會員點數與階級</h1>
        <p class="description">會員點數、階級規則、會員名單與 VIP 商品限制都集中在這裡；階級設定的點數回饋倍率會自動套用至訂單點數回饋。</p>
        <nav class="nav-tab-wrapper wutm-loyalty-tabs" aria-label="會員點數與階級設定">
            <?php foreach ($sections as $key => $label) : ?>
                <a class="nav-tab <?php echo $section === $key ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(wutm_member_loyalty_url($key)); ?>"><?php echo esc_html($label); ?></a>
            <?php endforeach; ?>
        </nav>
    </div>
    <?php
    if ($section === 'points') {
        WC_Member_Points_Rewards::instance()->wcmp_render_admin_page();
    } elseif ($section === 'tiers') {
        $view = isset($_GET['view']) ? sanitize_key(wp_unslash($_GET['view'])) : 'rules';
        $tier_views = ['rules' => '階級規則', 'members' => '會員名單', 'settings' => '全站設定'];
        if (!isset($tier_views[$view])) $view = 'rules';
        echo '<div class="wrap wutm-loyalty-subnav"><nav class="nav-tab-wrapper" aria-label="階級與會員設定">';
        foreach ($tier_views as $key => $label) {
            $active = $view === $key ? ' nav-tab-active' : '';
            echo '<a class="nav-tab' . esc_attr($active) . '" href="' . esc_url(wutm_member_loyalty_url('tiers', ['view' => $key])) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav></div>';
        if ($view === 'members') WC_Membership_Tiers::instance()->wmt_page_members();
        elseif ($view === 'settings') WC_Membership_Tiers::instance()->wmt_page_settings();
        else WC_Membership_Tiers::instance()->wmt_page_tiers();
    } else {
        wutm_render_member_loyalty_vip_store_tab();
    }
    ?>
    <style>
        .wutm-loyalty-wrap{max-width:none;margin-bottom:0}.wutm-loyalty-tabs{margin-top:20px}.wutm-loyalty-tabs .nav-tab{font-weight:600}.wutm-loyalty-subnav{margin-top:16px}.wutm-loyalty-subnav .nav-tab{font-size:13px}.wutm-loyalty-vip{max-width:1180px}.wutm-vip-guide{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin:18px 0}.wutm-vip-guide>div{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px}.wutm-vip-guide h3{margin-top:0}.wutm-vip-guide p{margin-bottom:0;line-height:1.7}.wutm-vip-flow{background:#f0f6fc;border-left:4px solid #2271b1;padding:14px 16px;margin:16px 0}.wutm-loyalty-wrap+.wrap>h1:first-child,.wutm-loyalty-subnav+.wrap>h1:first-child{display:none}@media(max-width:782px){.wutm-loyalty-tabs,.wutm-loyalty-subnav .nav-tab-wrapper{display:flex;overflow-x:auto;padding-bottom:1px}.wutm-loyalty-tabs .nav-tab,.wutm-loyalty-subnav .nav-tab{flex:0 0 auto;margin-left:0}.wutm-vip-guide{grid-template-columns:1fr}}
    </style>
    <?php
}

function wutm_render_member_loyalty_vip_store_tab(): void {
    $tier_names = [];
    foreach (WC_Membership_Tiers::instance()->wmt_get_all_tiers() as $tier) $tier_names[(int) $tier->id] = (string) $tier->name;

    $product_ids = get_posts([
        'post_type' => 'product',
        'post_status' => ['publish', 'draft', 'pending', 'future', 'private'],
        'posts_per_page' => 50,
        'fields' => 'ids',
        'orderby' => 'modified',
        'order' => 'DESC',
        'meta_query' => [
            'relation' => 'OR',
            ['key' => '_wmt_public_release_date', 'value' => '', 'compare' => '!='],
            ['key' => '_wmt_early_days', 'value' => '0', 'compare' => '>', 'type' => 'NUMERIC'],
            ['key' => '_wmt_min_tier_id', 'value' => '0', 'compare' => '>', 'type' => 'NUMERIC'],
        ],
    ]);
    ?>
    <div class="wrap wutm-loyalty-vip">
        <h2>VIP 隱密賣場設定</h2>
        <p>此功能是會員階級的一部分，設定位置在每一項商品的編輯畫面右側「VIP 隱密賣場設定」。可同時控制公開日期、VIP 提前購買期間，以及最低可見／可購買階級。</p>
        <div class="wutm-vip-guide">
            <div><h3>公開上架日期時間</h3><p>留空代表不啟用限時上架。設定日期後，商品在公開時間以前會依 VIP 條件限制；到達公開時間後恢復一般商品規則，所有符合商店條件的顧客皆可瀏覽與購買。</p></div>
            <div><h3>VIP 早鳥天數</h3><p>填入公開日前幾天開放給 VIP，例如公開日為 10 日、早鳥天數為 3，符合階級的會員會從 7 日起看到並購買商品。填 0 代表不提供提前開放。</p></div>
            <div><h3>最低可見／可購買階級</h3><p>選定後，只有該階級與更高階會員能在早鳥期間瀏覽及購買。未登入或階級不足的顧客不會在商店列表看到商品，直接開啟商品網址也會收到尚未開放提示。</p></div>
        </div>
        <div class="wutm-vip-flow"><strong>實際判斷順序：</strong>尚未到早鳥日 → 所有人隱藏；進入早鳥期間 → 只開放指定階級以上會員；到達公開日期 → 解除 VIP 限制並公開販售。</div>
        <p><a class="button button-primary" href="<?php echo esc_url(admin_url('edit.php?post_type=product')); ?>">前往商品列表設定</a></p>
        <h2>目前已設定 VIP 規則的商品</h2>
        <?php if (!$product_ids) : ?>
            <p>目前沒有商品設定 VIP 隱密賣場規則。</p>
        <?php else : ?>
            <table class="widefat striped">
                <thead><tr><th>商品</th><th>公開日期</th><th>早鳥天數</th><th>最低階級</th><th>操作</th></tr></thead>
                <tbody>
                <?php foreach ($product_ids as $product_id) :
                    $release = (string) get_post_meta($product_id, '_wmt_public_release_date', true);
                    $days = (int) get_post_meta($product_id, '_wmt_early_days', true);
                    $tier_id = (int) get_post_meta($product_id, '_wmt_min_tier_id', true);
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html(get_the_title($product_id)); ?></strong></td>
                        <td><?php echo esc_html($release ?: '未設定'); ?></td>
                        <td><?php echo esc_html($days > 0 ? $days . ' 天' : '未設定'); ?></td>
                        <td><?php echo esc_html($tier_id ? ($tier_names[$tier_id] ?? '階級 #' . $tier_id) : '不限制'); ?></td>
                        <td><a href="<?php echo esc_url(get_edit_post_link($product_id)); ?>">編輯商品</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="description">依最近修改時間顯示最多 50 項商品。</p>
        <?php endif; ?>
    </div>
    <?php
}
