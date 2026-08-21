<?php
/**
 * Admin page template
 * Advanced Tracking Manager v2.4.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$ga4_id             = get_option( 'atm_ga4_id', '' );
$ga4_enabled        = (int) get_option( 'atm_ga4_enabled', 0 );
$google_ads_id      = get_option( 'atm_google_ads_id', '' );
$google_ads_enabled = (int) get_option( 'atm_google_ads_enabled', 0 );
$gtm_id             = get_option( 'atm_gtm_id', '' );
$gtm_enabled        = (int) get_option( 'atm_gtm_enabled', 0 );
$fb_pixel_id        = get_option( 'atm_fb_pixel_id', '' );
$fb_pixel_enabled   = (int) get_option( 'atm_fb_pixel_enabled', 0 );
$fb_capi_token      = get_option( 'atm_fb_capi_token', '' );
$fb_capi_enabled    = (int) get_option( 'atm_fb_capi_enabled', 0 );
$checkout_code      = get_option( 'atm_checkout_code', '' );
$checkout_enabled   = (int) get_option( 'atm_checkout_enabled', 0 );
$exclude_admin      = (int) get_option( 'atm_exclude_admin', 1 );
$debug_mode         = (int) get_option( 'atm_debug_mode', 0 );
$last_saved_at      = get_option( 'atm_last_saved_at', '' );
$last_saved_by      = get_option( 'atm_last_saved_by', '' );
$last_saved_ip      = get_option( 'atm_last_saved_ip', '' );
$last_diagnostic    = get_option( 'atm_last_diagnostic', array() );
?>
<div class="wrap wutm-atm-wrap">
<h1>🎯 進階追蹤程式碼管理器 <span class="atm-version">v<?php echo esc_html( WUTM_ATM_VERSION ); ?></span></h1>

<?php if ( $last_saved_at ) : ?>
<div class="atm-meta">
  最後儲存：<?php echo esc_html( $last_saved_at ); ?>
  <?php if ( $last_saved_by ) echo '｜管理員 ID：' . esc_html( $last_saved_by ); ?>
  <?php if ( $last_saved_ip )  echo '｜IP：'       . esc_html( $last_saved_ip );  ?>
</div>
<?php endif; ?>

<!-- Tabs -->
<div class="atm-tabs">
  <button class="atm-tab active" data-tab="tracking">📡 追蹤設定</button>
  <button class="atm-tab" data-tab="diagnostic">🔍 自動診斷</button>
  <button class="atm-tab" data-tab="guide">📖 操作說明</button>
</div>

<!-- ======================================================
     TAB 1: tracking
     ====================================================== -->
<div id="atm-tab-tracking" class="atm-tab-content active">
<form method="post" action="">
  <?php wp_nonce_field( 'atm_save_settings', 'atm_nonce' ); ?>

  <!-- GA4 -->
  <div class="atm-card">
    <div class="atm-card-head">
      <span>📊 Google Analytics 4 (GA4)</span>
      <label class="atm-switch">
        <input type="checkbox" name="atm_ga4_enabled" value="1" <?php checked( $ga4_enabled, 1 ); ?>>
        <span class="atm-slider"></span>
      </label>
    </div>
    <div class="atm-card-body">
      <label>GA4 Measurement ID</label>
      <input type="text" name="atm_ga4_id" value="<?php echo esc_attr( $ga4_id ); ?>" placeholder="G-XXXXXXXXXX" class="regular-text">
      <p class="description">格式：<code>G-XXXXXXXXXX</code>。在 GA4 後台 → 管理 → 資料串流 → 點選你的網站 → 複製 Measurement ID。</p>
    </div>
  </div>

  <!-- Google Ads -->
  <div class="atm-card">
    <div class="atm-card-head">
      <span>📣 Google Ads 轉換追蹤</span>
      <label class="atm-switch">
        <input type="checkbox" name="atm_google_ads_enabled" value="1" <?php checked( $google_ads_enabled, 1 ); ?>>
        <span class="atm-slider"></span>
      </label>
    </div>
    <div class="atm-card-body">
      <label>Google Ads Conversion ID</label>
      <input type="text" name="atm_google_ads_id" value="<?php echo esc_attr( $google_ads_id ); ?>" placeholder="AW-XXXXXXXXX" class="regular-text">
      <p class="description">格式：<code>AW-XXXXXXXXX</code>。在 Google Ads 後台 → 目標 → 轉換 → 轉換動作 → 標記設定。<br>啟用後會隨 GA4 gtag 一起載入，不需要單獨的 gtag.js。</p>
    </div>
  </div>

  <!-- GTM -->
  <div class="atm-card">
    <div class="atm-card-head">
      <span>🏷️ Google Tag Manager (GTM)</span>
      <label class="atm-switch">
        <input type="checkbox" name="atm_gtm_enabled" value="1" <?php checked( $gtm_enabled, 1 ); ?>>
        <span class="atm-slider"></span>
      </label>
    </div>
    <div class="atm-card-body">
      <label>GTM Container ID</label>
      <input type="text" name="atm_gtm_id" value="<?php echo esc_attr( $gtm_id ); ?>" placeholder="GTM-XXXXXXX" class="regular-text">
      <p class="description">格式：<code>GTM-XXXXXXX</code>。在 GTM 後台右上角可以找到。<br>⚠️ 若你已在 GTM 容器裡安裝 GA4，請勿同時啟用上方的 GA4，否則會重複追蹤。</p>
    </div>
  </div>

  <!-- Meta Pixel -->
  <div class="atm-card">
    <div class="atm-card-head">
      <span>📘 Meta Pixel (Facebook / Instagram 廣告)</span>
      <label class="atm-switch">
        <input type="checkbox" name="atm_fb_pixel_enabled" value="1" <?php checked( $fb_pixel_enabled, 1 ); ?>>
        <span class="atm-slider"></span>
      </label>
    </div>
    <div class="atm-card-body">
      <label>Meta Pixel ID</label>
      <input type="text" name="atm_fb_pixel_id" value="<?php echo esc_attr( $fb_pixel_id ); ?>" placeholder="1234567890123456" class="regular-text">
      <p class="description">一串純數字。在 Meta Events Manager（事件管理工具）→ 選擇資料來源 → 複製 Pixel ID。<br>安裝後自動送出 <code>PageView</code> 事件，這是所有 Meta 廣告追蹤的基礎。</p>
    </div>
  </div>

  <!-- Meta CAPI -->
  <div class="atm-card">
    <div class="atm-card-head">
      <span>🔒 Facebook Conversions API (CAPI)</span>
      <label class="atm-switch">
        <input type="checkbox" name="atm_fb_capi_enabled" value="1" <?php checked( $fb_capi_enabled, 1 ); ?>>
        <span class="atm-slider"></span>
      </label>
    </div>
    <div class="atm-card-body">
      <label>Access Token</label>
      <input type="password" name="atm_fb_capi_token" value="<?php echo esc_attr( $fb_capi_token ); ?>" placeholder="貼上 CAPI Access Token" class="regular-text">
      <p class="description">CAPI 讓你從伺服器端傳送事件到 Meta，避免廣告阻擋工具影響資料準確性。<br>在 Events Manager → 設定 → Conversions API → 產生 Access Token。需同時填入上方的 Pixel ID 才能運作。</p>
    </div>
  </div>

  <!-- WooCommerce Checkout -->
  <div class="atm-card">
    <div class="atm-card-head">
      <span>🛒 WooCommerce 結帳成功追蹤碼</span>
      <label class="atm-switch">
        <input type="checkbox" name="atm_checkout_enabled" value="1" <?php checked( $checkout_enabled, 1 ); ?>>
        <span class="atm-slider"></span>
      </label>
    </div>
    <div class="atm-card-body">
      <label>追蹤碼（輸出在 thank-you 頁面）</label>
      <textarea name="atm_checkout_code" rows="6" style="width:100%;font-family:monospace;background:#1e1e1e;color:#d4d4d4;padding:10px;border-radius:4px;"><?php echo esc_textarea( $checkout_code ); ?></textarea>
      <p class="description">可使用 <code>{{order_id}}</code> 自動替換為訂單號碼。此程式碼只會在結帳完成頁輸出，不影響其他頁面。</p>
    </div>
  </div>

  <!-- General Options -->
  <div class="atm-card">
    <div class="atm-card-head"><span>⚙️ 一般選項</span></div>
    <div class="atm-card-body">
      <label style="display:flex;align-items:center;gap:10px;margin-bottom:12px;">
        <input type="checkbox" name="atm_exclude_admin" value="1" <?php checked( $exclude_admin, 1 ); ?>>
        <span>排除管理員流量（建議勾選，避免自己的操作影響分析數據）</span>
      </label>
      <label style="display:flex;align-items:center;gap:10px;">
        <input type="checkbox" name="atm_debug_mode" value="1" <?php checked( $debug_mode, 1 ); ?>>
        <span>除錯模式（在前台 HTML 底部輸出追蹤狀態的 HTML 註解，僅供開發用途）</span>
      </label>
    </div>
  </div>

  <p><input type="submit" name="atm_submit" class="button button-primary button-large" value="💾 儲存設定"></p>
</form>
</div>

<!-- ======================================================
     TAB 2: diagnostic
     ====================================================== -->
<div id="atm-tab-diagnostic" class="atm-tab-content">
  <div class="atm-card">
    <div class="atm-card-head"><span>🔍 自動診斷</span></div>
    <div class="atm-card-body">
      <p>點擊下方按鈕，系統會自動讀取網站前台 HTML，檢查所有已啟用的追蹤碼是否正確輸出。</p>
      <p>
        <button id="atm-run-diag" class="button button-primary">▶ 開始診斷</button>
        <button id="atm-clear-diag" class="button" style="margin-left:8px;">🗑 清除結果</button>
        <span id="atm-diag-spinner" style="display:none;margin-left:10px;">⏳ 診斷中...</span>
      </p>
      <div id="atm-diagnostic-output">
        <?php
        if ( ! empty( $last_diagnostic ) && ! empty( $last_diagnostic['items'] ) ) {
          $atm_instance = Advanced_Tracking_Manager::get_instance();
          echo '<div class="atm-diag-meta">上次診斷：' . esc_html( $last_diagnostic['checked_at'] ?? '' )
            . '｜前台：' . esc_html( $last_diagnostic['frontend_url'] ?? '' )
            . '｜狀態碼：' . esc_html( (string)($last_diagnostic['status_code'] ?? '') ) . '</div>';
          foreach ( $last_diagnostic['items'] as $item ) {
            $icon = $item['status'] === 'success' ? '✅' : ( $item['status'] === 'warning' ? '⚠️' : ( $item['status'] === 'error' ? '❌' : 'ℹ️' ) );
            echo '<div class="atm-diag-item atm-diag-' . esc_attr( $item['status'] ) . '">';
            echo '<strong>' . esc_html( $icon . ' ' . $item['title'] ) . '</strong><br>';
            echo esc_html( $item['message'] );
            if ( ! empty( $item['details'] ) ) echo '<br><span class="atm-diag-detail">' . esc_html( $item['details'] ) . '</span>';
            echo '</div>';
          }
        } else {
          echo '<p style="color:#888;">尚未執行診斷。</p>';
        }
        ?>
      </div>
    </div>
  </div>
</div>

<!-- ======================================================
     TAB 3: guide
     ====================================================== -->
<div id="atm-tab-guide" class="atm-tab-content">
  <div class="atm-card">
    <div class="atm-card-head"><span>📖 操作說明</span></div>
    <div class="atm-card-body atm-guide">

      <h3>📊 GA4 — 適合大多數網站</h3>
      <ol>
        <li>前往 <a href="https://analytics.google.com" target="_blank" rel="noopener">Google Analytics</a>。</li>
        <li>後台左下角 → 管理 → 資料串流 → 點選你的網站串流。</li>
        <li>複製 <strong>Measurement ID</strong>（格式 <code>G-XXXXXXXXXX</code>）。</li>
        <li>貼回此頁面的「GA4 Measurement ID」欄位，開啟開關，儲存。</li>
      </ol>

      <h3>🏷️ GTM — 適合進階多標籤管理</h3>
      <ol>
        <li>前往 <a href="https://tagmanager.google.com" target="_blank" rel="noopener">Google Tag Manager</a>。</li>
        <li>選擇容器，右上角找到 <code>GTM-XXXXXXX</code>。</li>
        <li>貼回「GTM Container ID」欄位，開啟開關，儲存。</li>
        <li>⚠️ 如果你在 GTM 裡也安裝了 GA4，請<strong>不要</strong>同時啟用本外掛的 GA4，以免重複。</li>
      </ol>

      <h3>📘 Meta Pixel — 投放 FB / IG 廣告必備</h3>
      <ol>
        <li>前往 <a href="https://www.facebook.com/events_manager" target="_blank" rel="noopener">Meta Events Manager</a>。</li>
        <li>選擇資料來源 → 複製 <strong>Pixel ID</strong>（一串純數字）。</li>
        <li>貼回「Meta Pixel ID」欄位，開啟開關，儲存。</li>
        <li>安裝後自動送出 PageView，可用 <a href="https://chromewebstore.google.com/detail/meta-pixel-helper/fdgfkebogiimcoedlicjlajpkdmockpc" target="_blank" rel="noopener">Meta Pixel Helper</a> 確認。</li>
      </ol>

      <h3>🔍 診斷工具使用方式</h3>
      <ol>
        <li>切換到「自動診斷」分頁。</li>
        <li>點「開始診斷」，等待 10–15 秒。</li>
        <li>若出現 ❌，請依說明修正；若出現 ⚠️ 排除管理員，請暫時關閉排除設定或改用無痕視窗測試。</li>
        <li>修正後重新儲存設定、清除快取，再執行一次診斷。</li>
      </ol>

      <h3>✅ 常見問題</h3>
      <ul>
        <li><strong>找不到追蹤碼</strong>：請先確認「排除管理員」是否已關閉，並清除頁面快取。</li>
        <li><strong>Tag Assistant 看不到 GTM</strong>：確認主題的 header.php 有 <code>&lt;?php wp_head(); ?&gt;</code>，body 開頭有 <code>&lt;?php wp_body_open(); ?&gt;</code>。</li>
        <li><strong>GTM 在 Tag Assistant 顯示多個容器</strong>：前往瀏覽器 DevTools → Network → 搜尋 gtm.js，確認載入次數。</li>
        <li><strong>Meta Pixel 偵測不到</strong>：部分廣告阻擋外掛會阻止 Pixel 載入，請用無痕模式測試。</li>
      </ul>
    </div>
  </div>
</div>
</div><!-- .wutm-atm-wrap -->
