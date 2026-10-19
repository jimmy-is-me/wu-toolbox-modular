# WU Toolbox 共用設定介面規範

## 原則

所有自有設定頁使用相同的淺色頁首、紫色主要操作、白色面板、細框線、分頁、表單字級及間距。模組總覽保留品牌 Hero；不同功能的圖表、資料表、統計數據與操作順序不強制變成相同版型。

只修改呈現類別與樣式，不搬移／複製／重新建立節點，不改 ID、name、action、method、Nonce、權限、required、disabled、value 或原事件。原生 WordPress／WooCommerce／第三方管理頁及前台不載入這套設定頁元件。

## 新模組必用的入口

在正常註冊的自有設定頁 callback 中使用：

```php
if (!current_user_can('manage_options')) return;
wutm_admin_page_open('模組名稱', '簡短功能說明');
?>
<section class="wutm-ui-panel"><div class="wutm-ui-panel-body">
    <form method="post" action="options.php">
        <?php settings_fields('your_existing_settings_group'); ?>
        <!-- 保留真實設定欄位，輸入框可加 wutm-ui-field -->
        <button type="submit" class="button button-primary">儲存設定</button>
    </form>
</div></section>
<?php
wutm_admin_page_close();
```

- 根節點：由 helper 產生的 `.wrap.wutm-ui-root`。
- 頁首：`.wutm-ui-header`；使用一個 H1。不要自行再建立第二個 Hero。
- 面板：`.wutm-ui-panel`；模組自己的 CSS 只處理內部業務布局。
- 分頁：`.wutm-ui-tabs` 搭配原生連結或正確的 tab roles／ARIA，保留切換事件。
- 按鈕：`.button`／`.button-primary`，或明確的 `.wutm-ui-button`／`.wutm-ui-primary`。
- 輸入：`.wutm-ui-field`，不強制欄位寬度、textarea 高度或改動原生核取方塊／滑桿。
- CSS 使用共用 `--wutm-ink`、`--wutm-muted`、`--wutm-line`、`--wutm-paper`、`--wutm-blue` 等變數，不再複製頁首／按鈕配色。
- 動態插入內容後明確呼叫 `window.WUTMAdminUI.adapt(element)`，或發送 `wutm-admin-ui-updated` 事件；不使用持續輪詢／全頁 MutationObserver。

## 舊模組相容

`assets/js/admin-design.js` 適配既有根節點、標題、自訂頁首、面板、分頁、按鈕與文字欄位。`assets/css/admin-components.css` 是最後的共用元件層，讓一般 H1 頁首與媒體工作台等自訂頁首使用同一組呈現規則，不再各自使用深色／淺色兩套標準。

圖表、編輯器、彈窗及媒體選取視窗不自動標記為一般設定元件。前台效果的後台預覽可用 `data-wutm-ui="preserve"` 阻止自動標記；預覽內不要使用設定頁專用元件 class。特殊布局只能擴充模組內部內容，不覆寫共用頁首或控制項。

`core/admin-design.php` 仍依實際註冊 callback 的來源檔判定頁面所有權，而非網址前綴。樣式與腳本使用檔案時間戳快取版本；沒有新增網路分析、業務查詢或前台資源。

## 防止未來再次分歧

`tests/admin-ui-contract.js` 盤點有管理頁註冊的模組，並對不在既有相容基線內的新模組要求共用 helper／root。不要把新模組加入 legacy 基線來繞過規範；這項檢查是基本結構防線，仍需人工審查實際畫面。

```text
php tests/admin-design.php
node tests/admin-ui-contract.js
node tests/admin-components-ui.js
node tests/admin-design-ui.js
```

瀏覽器檢查需 Playwright、Chromium 與 PHP，可設定 WUTM_TEST_CHROME、WUTM_TEST_PHP、WUTM_TEST_PHP_ARGS。測試涵蓋 320／390／768／1440 px 的一般、媒體及未來元件樣板，直接比較頁首 computed styles，確認表單狀態、form owner、原事件、預覽隔離及動態適配；另保留既有模組操作回歸測試。

不把樣板測試視為所有正式網站外掛組合均已驗收。發布前仍需跑完整 CI；正式站安裝後應檢查其外掛組合及個別客製 CSS。本規範與共用元件納入 v3.7.0；v3.7.1 依參考 HTML 最終深藍／青色 skin 修正主面板、淺藍設定標題、低陰影卡片與手機頁籤。不得重新套用 HTML 前段被覆寫的紫色 demo 規則。SIGNAL / UI 為純裝飾，不表示網路或實際效能監測，減少動態效果模式下停止動畫。

v3.7.1 卡片開放開關僅限主帳號管理，使用獨立 AJAX action，不能共用模組啟用開關的選擇器。其他管理員的主選單連結沿用原有設定頁 URL，不新增或搬動模組 callback。這是管理員選單顯示功能，不是非管理員角色授權。
