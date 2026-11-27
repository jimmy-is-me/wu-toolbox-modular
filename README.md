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

v4.3.3 增加 Change Set 草稿狀態前置檢查、Dry Run、MCP 診斷與同一 OAuth 客戶端的重連重試。詳見 [AI 連接器操作文件](docs/site-ai-connector.md)。

v4.3.2 將 AI 變更的資料庫驗收、關聯快取清除和公開頁實際比對分開，新增分段圖片傳輸與搜尋、社群分享及授權審核預覽。詳見 [AI 連接器操作文件](docs/site-ai-connector.md)。

v4.3.1 擴充 AI 連接器的文章變更流程。長文審核可分段展開，獨立欄位各自檢查衝突，批次變更經 WordPress 核准後直接套用並逐項核對。另改善圖片上傳收據，新增公開 SEO 與 Sitemap 診斷。已授權的 OAuth 連線可沿用，請重新整理 MCP 工具清單。詳見 [AI 連接器操作文件](docs/site-ai-connector.md)。

v4.3.0 更新 AI 連接器後台，以緊湊欄位、固定位置的複製按鈕和分頁工作區呈現連線設定。

v4.2.9 將 [AI 連接器](docs/site-ai-connector.md) 升級為原生 OAuth。新版使用 PKCE S256、個別授權與細項權限，可管理 CPT 草稿、SEO、FAQ 和圖片。舊固定金鑰需要改用 OAuth 授權。

v4.2.7 將內建 LINE Pay 付款工具更新至 1.4.0，整合 CVE-2026-128833 修補與付款狀態查詢。 升級前已開始的付款可能仍使用舊回呼網址。如果顧客被導回購物車，請先在 WooCommerce 訂單的 LINE Pay 區塊查詢交易狀態，再決定是否收款或請顧客重新付款。

v4.2.8 擴充 AI 連接器，集中工具與操作紀錄，支援透過 REST／MCP 管理授權的文章、頁面及自訂內容類型。

v4.2.6 新增 [IndexNow 自動通知與發布健檢](docs/SEO-INDEXNOW.md)。

v4.2.5 簡化進階追蹤總覽、改善 Cookie 偏好視窗，並補強 [GTM 載入與診斷](docs/ADVANCED-TRACKING-MANAGER.md#gtm-與-cookie-偏好-334)。

v4.2.4 新增 [課程與自訂內容的商品瀏覽追蹤](docs/ADVANCED-TRACKING-MANAGER.md#內容商品對應與首次同意-333)，並改善首次行銷同意後的 Browser／Server 配對。

v4.2.3 將 [Meta 追蹤測試與驗收](docs/ADVANCED-TRACKING-MANAGER.md#追蹤測試與驗收-332) 集中到 WU Toolbox，並簡化 WooCommerce 訂單介面。

v4.2.2 新增 [WooCommerce Meta 購買事件管理與模擬測試](docs/ADVANCED-TRACKING-MANAGER.md#woocommerce-訂單)。

v4.2.1 新增 [WU SEO 視覺化儀表板](docs/SEO-DASHBOARD.md)，集中查看搜尋成效、Google 索引狀態、Sitemap 與關鍵字。

SEO 核心的功能、設定順序與發佈後驗證方式見 [SEO 核心使用說明](docs/SEO-CORE.md)。

商品預計時間、不同訂單批次及客人查詢頁的設定見 [出貨進度與通知信](docs/SHIPPING-PROGRESS.md)。

商品規格表的後台操作及商品編輯隱藏開關見 [電商後台介面](docs/COMMERCE-ADMIN.md)。

上傳 MP4 可使用「媒體工具 → 分段上傳」。支援續傳、取消及自動加入媒體庫，主機相容性見 [分段上傳](docs/CHUNK-UPLOAD.md)。

頁面快取的狀態、排除原因及訪客驗證方式見 [頁面快取與網站速度](docs/PAGE-CACHE.md)。

v4.1.9 新增追蹤總覽、WP-Cron 佇列診斷、唯讀訂單檢查及驗收報告。進階追蹤管理的首次訪問預設、WooCommerce 付款追蹤、Meta CAPI 與開發介面見 [Advanced Tracking Manager](docs/ADVANCED-TRACKING-MANAGER.md)。

後台 Tech Precision 共用呈現架構、驗證與範圍見 [後台外觀](docs/ADMIN-DESIGN.md)。

## v4.0.2 實際交付範圍

新增子 Sitemap 分批人工續查與結果保存（每份最多 500 個網址、每次一個、至少間隔 5 秒），檢查 HTTP、轉址、Canonical、noindex 及本機非公開狀態。原生 Sitemap 引擎不變。SEO 對話框可即時同步列表並顯示字數。

URL Inspection API、重要網址中心、品牌分析、逐頁成效與綜合報告仍屬後續功能，尚未交付。HTTP 診斷不代表 Googlebot 或 Google 索引證據。

## v4.0.5 會員模組交付範圍

保留會員資料、既有短碼與 my-points／vip-tier。加入統一路由刷新生命週期、後台共用頁首與限定範圍樣式、到期優先使用順序，以及預設關閉的數位會員卡。從會員點數與階級 → 數位會員卡啟用，使用 [wu_member_card]；QR 5 分鐘有效，只能由具會員管理權限的人員查驗，不能登入或扣點。

本版不是 Member Loyalty 2.0 全需求交付。交易並發、退款／部分退款冪等、寫入失敗回復的統一交易服務、完整搜尋／會員明細與營運總覽仍待開發。會員卡及樣式尚需正式網站、手機和佈景主題驗證；未宣稱 Checkout Blocks 或所有主機組合完整相容。無資料庫重建或點數清空。回復時停用會員卡並安裝前版；不應回寫舊會員資料覆蓋新版期間的交易。


## v4.0.6 會員中心

會員總覽的數位卡、我的點數、我的會員統一使用限定範圍的自適應樣式；不覆寫佈景的帳戶側欄。會員效期以網站時區儲存的真實日期倒數，支援減少動畫設定。優惠券依 WooCommerce 資料顯示可用／已過期／已使用／失效，只有可用券提供複製，實際適用性仍由結帳判斷。

「查看所有會員等級、升級條件與權益」讀取本站啟用的階級，顯示目前等級與實際優惠；升級進度保留既有合格訂單、鑑賞期規則。單筆門檻的階級不再誤顯示為已無可升級等級。點數頁集中列出餘額、30 天到期提醒、折抵規則及最近 20 筆異動。

數位會員卡改用本機 QR 函式庫正確 API，QR 5 分鐘到期後隱藏，提供重新產生入口及失敗提示。工作人員需先登入具 `manage_woocommerce` 權限的帳號才能查驗。QR 不會登入會員或扣點，停用卡片後既有 Token 亦失效。啟用及操作方式見會員後台「數位會員卡」。詳見 [會員中心操作與驗證](docs/MEMBER-CENTER-4.0.6.md)。

本版聚焦會員中心呈現與 QR 修正，v4.0.5 已列出的交易並發與退款服務重構仍未交付；不重建資料庫、不清空點數。

## v4.0.7

SEO 列表欄位預設關閉，可由全站 SEO 設定啟用，之後在內容列表「顯示項目設定」選擇欄位。既有個人顯示偏好保留。SEO 進階區於同一瀏覽分頁保留展開狀態。會員中心移除重複導覽；會員名單可調整階級並選擇保留效期、指定網站時區日期時間、增減天數、從現在起算、階級預設或永久有效。減少天數可使效期提前到期，資格仍依既有規則計算。永久效期不能直接增減天數。所有修改保留權限及 Nonce 檢查。

## v4.0.8

工具箱新增「開放模組」篩選，至少一個模組已勾選開放時顯示；數量與篩選會依儲存結果更新，儲存失敗仍還原原值。此篩選不授予額外權限。媒體資料夾修正選圖視窗包覆層在沒有 has-load-more 標記時的絕對定位寬度，保留媒體庫頁與原生媒體詳細資訊區。測試涵蓋媒體庫、編輯器／自訂器選圖結構、包覆與直接清單、窄螢幕和資料夾抽屜；實際第三方佈景／編輯器及正式網站仍需上站驗收。
