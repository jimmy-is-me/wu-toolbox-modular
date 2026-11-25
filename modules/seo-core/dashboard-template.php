<?php
defined( 'ABSPATH' ) || exit;
$tabs = ['performance'=>'搜尋成效','index'=>'索引狀態','sitemap'=>'Sitemap','keywords'=>'關鍵字追蹤','technical'=>'技術診斷','indexnow'=>'IndexNow'];
if ( ! isset( $tabs[$tab] ) ) $tab = 'performance';
?>
<div id="wu-seo-dashboard" data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php', 'relative' ) ); ?>"
    data-nonce="<?php echo esc_attr( wp_create_nonce( 'wu_seo_dashboard' ) ); ?>" data-tab="<?php echo esc_attr( $tab ); ?>"
    data-post="<?php echo esc_attr( $target ? $target->ID : 0 ); ?>"
    data-page="<?php echo esc_url( $target ? get_permalink( $target ) : '' ); ?>"
    data-analytics="<?php echo $scope ? '1' : '0'; ?>">
    <?php if ( ! in_array( $tab, ['technical','indexnow'], true ) ) : ?>
    <form class="wus-toolbar wutm-ui-panel" data-filters>
        <label>起日 <input type="date" name="start" value="<?php echo esc_attr( $start->format( 'Y-m-d' ) ); ?>" required></label>
        <label>迄日 <input type="date" name="end" value="<?php echo esc_attr( $end->format( 'Y-m-d' ) ); ?>" required></label>
        <label><input type="checkbox" name="compare" checked> 前期比較</label>
        <label>國家 <input name="country" maxlength="3" size="5" pattern="[A-Za-z]{3}" placeholder="全部" list="wus-countries" title="三碼國家代碼"></label>
        <datalist id="wus-countries"><option value="TWN">台灣</option><option value="USA">美國</option><option value="JPN">日本</option><option value="HKG">香港</option><option value="SGP">新加坡</option></datalist>
        <label>裝置 <select name="device"><option value="">全部</option><option value="DESKTOP">電腦</option><option value="MOBILE">手機</option><option value="TABLET">平板</option></select></label>
        <button type="submit" class="button button-primary">更新</button>
        <small data-sync>資料截止日 —　上次同步 —</small>
    </form>
    <p class="wus-state" role="status" data-state><?php echo $scope ? '正在讀取已儲存的資料…' : '尚未連結 Search Console'; ?></p>
    <section class="wus-metrics" aria-label="搜尋成效四項指標" data-kpis></section>
    <div class="wus-primary">
        <section class="wutm-ui-panel wus-card">
            <div class="wus-row"><h2>搜尋成效</h2><label><span class="screen-reader-text">圖表指標</span><select data-metric><option value="impressions">曝光</option><option value="clicks">點擊</option><option value="ctr">CTR</option><option value="position">平均排名</option></select></label></div>
            <div data-chart><p>尚無趨勢資料</p></div>
            <p class="wus-chart-note" data-point role="status">選取資料點以查看數字</p>
            <p class="wus-legend"><span>● 本期</span> <span data-compare>○ 前期</span></p>
        </section>
        <section class="wutm-ui-panel wus-card">
            <h2>Google 索引狀態</h2>
            <div class="wus-index" data-donut></div>
            <p class="wus-muted">範圍為目前內容清單，最多 30 個公開網址</p>
        </section>
    </div>
    <div class="wus-summary">
        <section class="wutm-ui-panel wus-card"><h2><a href="<?php echo esc_url( add_query_arg( 'seo_tab','sitemap',$page ) ); ?>" data-tab="sitemap">Sitemap 健康狀態</a></h2><div data-sitemap-summary>尚未檢查</div></section>
        <section class="wutm-ui-panel wus-card"><h2><a href="<?php echo esc_url( add_query_arg( 'seo_tab','keywords',$page ) ); ?>" data-tab="keywords">關鍵字進步／下滑</a></h2><div data-movers>尚無比較資料</div></section>
        <section class="wutm-ui-panel wus-card"><h2><a href="<?php echo esc_url( add_query_arg( 'seo_tab','performance',$page ) ); ?>" data-tab="performance">值得查看的頁面</a></h2><div data-watch>尚無頁面資料</div></section>
    </div>
    <?php endif; ?>
    <nav class="wus-tabs" aria-label="SEO 明細">
        <?php foreach ( $tabs as $key=>$label ) : ?><a href="<?php echo esc_url( add_query_arg( 'seo_tab',$key,$page ) ); ?>" data-tab="<?php echo esc_attr( $key ); ?>" <?php if ($tab === $key) echo 'aria-current="page"'; ?>><?php echo esc_html( $label ); ?></a><?php endforeach; ?>
    </nav>
    <?php if ( $tab === 'technical' ) : ?>
        <?php self::dashboard_technical_tools(); ?>
    <?php elseif ( $tab === 'indexnow' ) : ?>
        <?php self::indexnow_panel(); ?>
    <?php else : ?>
    <section class="wutm-ui-panel wus-card" data-panel="performance" <?php if ($tab !== 'performance') echo 'hidden'; ?>>
        <div class="wus-row"><h2>搜尋成效明細</h2><button type="button" class="button" data-reset hidden>回到全站</button></div>
        <p data-detail-scope><?php echo esc_html( $target ? '單頁 · ' . get_the_title( $target ) : '全站 · Google Search Console' ); ?></p>
        <div data-tables></div>
        <details><summary>進階設定與 Google 授權</summary><?php self::search_console_page(); ?></details>
        <details data-errors hidden><summary>查詢錯誤與回應摘要</summary><pre></pre></details>
    </section>
    <section class="wutm-ui-panel wus-card" data-panel="index" <?php if ($tab !== 'index') echo 'hidden'; ?>>
        <div class="wus-row"><h2>索引紀錄</h2><a href="https://search.google.com/search-console" target="_blank" rel="noopener">開啟 Search Console</a></div>
        <form class="wus-inline" data-find><label>搜尋公開內容 <input name="search" type="search" maxlength="150"></label><button class="button">搜尋</button></form>
        <div class="wus-inline"><button class="button" data-inspect>查詢所選網址</button><label>狀態 <select data-index-filter><option value="">全部</option><option value="indexed">已收錄</option><option value="excluded">未收錄</option><option value="unknown">未能判定</option><option value="pending">待查</option><option value="failed">查詢失敗</option></select></label></div>
        <p class="wus-muted">每批最多 20 個。Google 索引紀錄、WordPress 設定與公開 HTTP 分別顯示。</p>
        <div data-index-table></div>
    </section>
    <section class="wutm-ui-panel wus-card" data-panel="sitemap" <?php if ($tab !== 'sitemap') echo 'hidden'; ?>>
        <div class="wus-row"><h2>Sitemap 狀態</h2><button class="button" data-sitemap-update>更新 Sitemap 狀態</button></div>
        <p><a href="<?php echo esc_url( self::sitemap_url() ); ?>">開啟原生 Sitemap</a>　<a href="https://search.google.com/search-console/sitemaps" target="_blank" rel="noopener">在 Search Console 管理提交</a></p>
        <div data-sitemap-table></div>
        <h3>首次發布檢查</h3><div data-publication></div>
    </section>
    <section class="wutm-ui-panel wus-card" data-panel="keywords" <?php if ($tab !== 'keywords') echo 'hidden'; ?>>
        <h2>關鍵字追蹤</h2><p class="wus-muted">Google Search Console · 沿用上方日期、國家及裝置篩選</p>
        <form class="wus-inline" data-keyword-form><label>關鍵字 <input name="term" required maxlength="100"></label><label>目標頁 <select name="target"><option value="">不指定</option></select></label><label>或輸入目標頁 ID <input type="number" min="1" name="target_id" inputmode="numeric"></label><button class="button">加入追蹤</button></form>
        <div data-keywords></div><div data-keyword-data></div>
    </section>
    <?php endif; ?>
</div>
