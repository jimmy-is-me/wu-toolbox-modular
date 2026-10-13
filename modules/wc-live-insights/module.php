<?php
defined('ABSPATH') || exit;
$wutm_insights_legacy = class_exists('JMI_WC_Admin_Insights', false);
require_once __DIR__ . '/insights.php';

add_action('admin_menu', static function () {
    if (!class_exists('WooCommerce')) return;
    add_submenu_page('wu-toolbox-modular', 'Woo即時營運分析設定', 'Woo即時營運分析',
        JMI_WC_Admin_Insights::CAP, 'wu-wc-live-insights', 'wutm_wc_live_insights_settings');
}, 41);

function wutm_wc_live_insights_settings() {
    if (!current_user_can(JMI_WC_Admin_Insights::CAP)) wp_die('沒有存取權限。');
    $reports = ['overview'=>['營運總覽','期間營收、前期比較、每日趨勢、分類營收、退款及全站訂單列表。'],
        'products'=>['商品銷售','商品／變體 SKU 搜尋、零銷售篩選、排序與商品成本編輯入口。'],
        'customers'=>['顧客消費','會員與訪客依 Email 歸戶，歷史消費按需查詢，保留新／舊客判定。'],
        'stock'=>['庫存報表','低庫存、缺貨、預購及未管理數量，處理共用父商品庫存與獨立變體。'],
        'abc'=>['商品貢獻排行','完整期間營收計算主力／次要／低貢獻，保留負營收、退款及成本狀態。'],
        'finance'=>['獲利與行銷','成本、毛利、淨利、轉換率、CAC、ROAS、ROI、放棄率及庫存周轉；缺資料不推估。']];
    ?>
    <div class="wrap wutm-live-settings"><h1>Woo即時營運分析</h1>
      <p>營運分析面板保留在左側主選單「營運管理」。本設定頁不查詢訂單或執行報表；原 2.4.1 的功能與資料格式均保留。</p>
      <p><a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page='.JMI_WC_Admin_Insights::SLUG)); ?>">開啟營運分析面板</a></p>
      <div class="wutm-live-settings-grid"><?php foreach($reports as $tab=>$report): ?>
        <section><h2><?php echo esc_html($report[0]); ?></h2><p><?php echo esc_html($report[1]); ?></p><a class="button" href="<?php echo esc_url(add_query_arg(['page'=>JMI_WC_Admin_Insights::SLUG,'tab'=>$tab],admin_url('admin.php'))); ?>">開啟<?php echo esc_html($report[0]); ?></a></section>
      <?php endforeach; ?></div>
      <section><h2>成本與期間補錄設定</h2>
        <p>商品單位成本在「商品 → 編輯商品 → 商品資料 → 一般」填寫；變體可另外設定成本，空白繼承主商品。新訂單保留成本快照，舊訂單使用目前成本時會標示估算。</p>
        <p>期間成本、營運費用與行銷資料在主面板「獲利與行銷」補錄，沿用原期間資料；空白表示未知，0 表示已確認沒有。報表 CSV 僅匯出已完成的結果／目前頁，不另查資料。</p>
      </section>
      <section><h2>負載與資料保護</h2><ul>
        <li>無背景統計排程、無定期輪詢、無外部 CDN 或圖表套件；離開面板後不繼續刷新。報表仍有查詢負載，建議先使用短期間。</li>
        <li>限定 ID 批次彙總，每批最多 500 張；不逐張載入完整訂單與退款物件。最多 366 天、20,000 張／期間、5,000 商品群組；SQL 單次上限 1.5 秒，請求處理檢查上限 5 秒。</li>
        <li>完成報表快取 5 分鐘並標示資料時間；開啟／切換不自動重算。手動更新每輪最多 20 秒後暫停，可繼續或停止；未完成工作暫存 10 分鐘，不顯示不完整合計。</li>
        <li>僅管理員可查詢，AJAX 與期間補錄均有權限／Nonce 保護；沿用 HPOS、傳統訂單儲存、商店幣別與時區。</li>
        <li>若已使用舊版獨立程式碼／外掛，請停用舊版後再啟用此模組，避免沿用舊版實作。</li>
      </ul></section>
    </div>
    <style>.wutm-live-settings-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}.wutm-live-settings section{padding:22px;background:#fff;border:1px solid #dcdcde;border-radius:12px;margin:16px 0;overflow-wrap:anywhere}.wutm-live-settings section h2{margin-top:0}.wutm-live-settings p,.wutm-live-settings li{line-height:1.8}.wutm-live-settings ul{padding-left:20px;list-style:disc}@media(max-width:1000px){.wutm-live-settings-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:600px){.wutm-live-settings-grid{grid-template-columns:1fr}.wutm-live-settings section{padding:16px}}</style>
    <?php
}

if ($wutm_insights_legacy) {
    add_action('admin_notices', static function () {
        if (current_user_can('manage_options')) echo '<div class="notice notice-warning"><p>Woo即時營運分析已由舊版程式碼載入。請停用原本的獨立外掛／程式碼片段，再使用 WU Toolbox 模組，避免沿用舊版實作。</p></div>';
    });
}
unset($wutm_insights_legacy);
