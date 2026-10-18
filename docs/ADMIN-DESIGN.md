# Tech Precision 後台外觀（3.6.7）

本版本統一 WU Toolbox 自有設定頁的呈現方式，不搬移或重建模組表單，不變更設定鍵、資料、AJAX、Nonce、權限、相依條件及模組載入方式。

## 共用結構

- `core/admin-design.php`：檢查目前 WordPress screen 對應的已註冊 callback，其來源必須在本外掛的 `core/` 或 `modules/` 才載入樣式和呈現腳本；不使用網址前綴猜測所有權
- `assets/css/admin.css`：集中色彩、字型、間距、圓角、卡片、表單、按鈕、通知、表格及 RWD，全部限定在 `wutm-admin-screen` 內，保留舊模板使用的變數別名
- `assets/js/admin-design.js`：為既有頂層容器、標題及明確列舉的面板加入 `wutm-ui-*` 類別；不改 ID/name、DOM 位置、form action、事件處理器或提交資料
- `core/admin-page.php`：沿用真實模組清單與原本操作，增加與開關同步的啟用數及比例視覺

新增自有設定頁時使用 WordPress 正常註冊 callback 並以 `.wrap` 作為頁面根節點；標題使用 `h1` 或 `.wutm-header`。面板優先採用 `.wutm-panel`，避免再加全域 `.wrap`、`.card` 或 `#wpcontent` 規則。複雜模組保留原本布局，新增面板名稱時在呈現適配器的明確清單加入對應 class。

## 外觀範圍

總覽、標準 Settings API 表單、自有獨立管理頁、內容總覽、AI 工具、郵件分析及媒體工作台等頁面由共用呈現層處理。媒體與內容等特殊頁保留原有資訊結構及資料載入規則；會員管理保留原有內部導覽和子頁結構。聯絡按鈕採兩個頂層容器的適配方式，不重新包裹原表單。

WordPress 管理列及左側選單維持原生樣式；導向 WooCommerce／第三方外掛的原生設定頁、原生文章商品編輯器及分類編輯器不載入共用樣式。Google 地圖、商品徽章等原生編輯畫面保留模組原有的專屬樣式。前台不載入這次新增資源。

## 動態與可及性

頁首一條訊號線與單一狀態點採低幅度動畫，卡片僅於 hover／狀態改變時更新邊框。沒有每張卡片的持續動畫、輪詢、額外 API 或 MutationObserver。支援 `prefers-reduced-motion`；保留鍵盤 Tab、焦點框及原生核取狀態，模組開關增加可讀名稱。

## 驗證

```bash
php tests/admin-design.php
node --check assets/js/admin-design.js
# Browser suite requires Playwright and an installed Chromium.
WUTM_TEST_CHROME=/path/to/chromium node tests/admin-design-ui.js
```

可選環境變數：`WUTM_TEST_PHP`、JSON 陣列 `WUTM_TEST_PHP_ARGS`、`WUTM_VISUAL_OUTPUT`。字型視覺 QA 可透過 `WUTM_TEST_FONT_DIR` 指向本地 Fontsource Noto Sans TC 檔案；外掛本身不下載字型。

瀏覽器檢查涵蓋 1440、1024、768、390 px：全部真實模組卡片、成功切換與統計、搜尋／篩選、設定欄位與 form owner 不變、色碼文字更新、減少動態、外部畫面隔離，以及內容總覽、媒體編碼器、郵件紀錄的不同結構。

本次未連線登入正式 WordPress，沒有實際改寫正式站設定；實站外掛組合、第三方管理佈景主題及網站個別 CSS 仍需站上驗收。各模組業務邏輯維持原檔不變，沿用原有回歸測試及 GitHub Actions 檢查。

## 發布

外掛 header、`WUTM_VERSION`、更新器 changelog 都為 3.6.7。推送 `v3.6.7` tag 後，現有 `release.yml` 驗證 PHP、共用外觀範圍、版本及 changelog，再建立含單一 `wu-toolbox-modular/` 根目錄的安裝 ZIP，並上傳 GitHub Release。ZIP 排除 `.git`、工作流程、測試及測試產物。
