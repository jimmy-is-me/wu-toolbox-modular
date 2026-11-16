# WU Toolbox Modular

WordPress 工具箱的模組化版本。每個功能都是獨立檔案，只有在後台啟用後才會載入；停用功能不會註冊 hooks、載入設定頁或消耗前台資源。

## 結構

- `wu-toolbox-modular.php`：極輕量外掛入口
- `core/`：模組註冊、延遲載入與設定總覽
- `modules/<slug>/module.php`：一個功能一個模組，保留原功能的設定與相容性
- `docs/MODULES.md`：功能與相依性清單

## 安裝

將整個 repository 放入 `wp-content/plugins/wu-toolbox-modular/`，於 WordPress 後台啟用外掛，然後從「WU Toolbox」逐一開啟需要的功能。

## 相容性

使用與舊版相同的 `wumetax_module_*` 設定鍵，已啟用的功能可延續設定；新核心同時讀取新舊鍵，避免搬遷後意外啟用所有模組。

License: GPL-2.0-or-later.

v4.0.1 新增 SEO 列表、快速編輯、批次預覽、修改歷史、CSV 與按需技術診斷，完整操作與驗證界線見 [SEO 管理與診斷](docs/SEO-4.0.1.md)。

SEO 核心的功能、設定順序與發佈後驗證方式見 [SEO 核心使用說明](docs/SEO-CORE.md)。

商品預計時間、不同訂單批次及客人查詢頁的設定見 [出貨進度與通知信](docs/SHIPPING-PROGRESS.md)。

商品規格表的後台操作及商品編輯隱藏開關見 [電商後台介面](docs/COMMERCE-ADMIN.md)。

頁面快取的狀態、排除原因及訪客驗證方式見 [頁面快取與網站速度](docs/PAGE-CACHE.md)。

後台 Tech Precision 共用呈現架構、驗證與範圍見 [後台外觀](docs/ADMIN-DESIGN.md)。

## v4.0.2 實際交付範圍

新增子 Sitemap 分批人工續查與結果保存（每份最多 500 個網址、每次一個、至少間隔 5 秒），檢查 HTTP、轉址、Canonical、noindex 及本機非公開狀態。原生 Sitemap 引擎不變。SEO 對話框可即時同步列表並顯示字數。

URL Inspection API、重要網址中心、品牌分析、逐頁成效與綜合報告仍屬後續功能，尚未交付。HTTP 診斷不代表 Googlebot 或 Google 索引證據。

## v4.0.5 會員模組交付範圍

保留會員資料、既有短碼與 my-points／vip-tier。加入統一路由刷新生命週期、後台共用頁首與限定範圍樣式、到期優先使用順序，以及預設關閉的數位會員卡。從會員點數與階級 → 數位會員卡啟用，使用 [wu_member_card]；QR 5 分鐘有效，只能由具會員管理權限的人員查驗，不能登入或扣點。

本版不是 Member Loyalty 2.0 全需求交付。交易並發、退款／部分退款冪等、寫入失敗回復的統一交易服務、完整搜尋／會員明細與營運總覽仍待開發。會員卡及樣式尚需正式網站、手機和佈景主題驗證；未宣稱 Checkout Blocks 或所有主機組合完整相容。無資料庫重建或點數清空。回復時停用會員卡並安裝前版；不應回寫舊會員資料覆蓋新版期間的交易。
