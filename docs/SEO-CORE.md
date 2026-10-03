# SEO 核心（v3.5.0）

本模組專注搜尋資訊、索引一致性與內容可信度基礎，不包含 301 轉址、爬蟲、外部評分服務或自動產生評價。所有既有 SEO 設定與內容欄位保留。

## 設定順序

1. 在 WU Toolbox 開啟「SEO 核心」，進入「SEO 全站設定」。
2. 補齊品牌名稱、官方社群、公開聯絡資料與品牌說明。電商網站可選「線上商店」；法定名稱與地址為選填，請僅填真實且願意公開的資料。
3. 指定已發佈的關於我們與聯絡我們頁面，分別產生 AboutPage、ContactPage 結構化資料。未發佈或密碼保護的頁面不會被當作公開品牌頁。
4. 在「使用者 → 個人資料」補齊作者顯示名稱、個人網站與介紹；文章 Schema 會連結實際作者。前台也應提供與這些資料一致的作者與品牌介紹。
5. 按內容類型設定搜尋標題範本：支援 `{title}`、`{site}`、`{tagline}`。內容已有自訂 SEO 標題時不覆寫，首頁沿用既有標題，分頁自動標示頁碼。
6. 在文章／頁面／商品編輯器檢查搜尋預覽、分享圖、摘要與內容品質。主要主題只供編輯參考，不輸出 meta keywords，也不計算關鍵字密度。
7. 在文章分類、商品分類或標籤編輯頁設定分類 SEO；留空則沿用名稱與說明。Canonical 通常留空，除非確實存在另一個應作為標準版本的網址。

## 索引與 Sitemap

- 沿用 WordPress 原生 `/wp-sitemap.xml`，不另建排程或 Sitemap 引擎。
- 手動 noindex 與密碼保護內容不列入 Sitemap；作者／標籤封存的索引開關同步影響 Sitemap。
- 作者與日期封存預設 noindex，標籤索引沿用原設定；若作者頁具備完整介紹及獨特內容，可自行開放索引。
- `lastmod` 使用內容實際修改時間，不以每次瀏覽時間冒充更新。
- 可控制 `max-image-preview:large`，是否呈現大型預覽仍由搜尋引擎決定。
- 網站整體是否可索引仍由 WordPress「設定 → 閱讀 → 搜尋引擎可見度」控制，本模組不覆寫網站的 noindex 保護。

## 內容品質與結構化資料

區塊與傳統編輯器均提供本機檢查：搜尋標題／摘要、小標題、相關站內連結、圖片 alt、外部參考連結與主要主題。短頁面不一定需要小標題，裝飾圖可使用空 alt；參考來源、事實、原創經驗與作者專業仍需人工確認，不應為了檢查提示堆疊關鍵字。

Schema 包含組織／線上商店、網站、文章與真實作者、頁面、關於／聯絡頁、分類集合頁及階層麵包屑。WooCommerce 原生商品價格、庫存與評論 Schema 不被覆寫，也不虛構評分、認證或專業資格。

## 相容性與效能

預設偵測 Yoast、Rank Math、All in One SEO、The SEO Framework、SEOPress 的啟用狀態；若已有其他 SEO 外掛負責輸出，本核心會暫停前台 Meta／Schema／索引調整，保留編輯與既有匯入功能。正式使用時請選擇單一主要 SEO 輸出來源。

內容健檢只在後台使用，最多抽樣最近更新的 200 篇，快取 10 分鐘，相關內容／SEO 欄位儲存後更新；數字不是全站總數。新內容分析腳本只在支援的後台編輯器載入，不新增前台腳本、背景掃描或外部請求。

## 發佈後檢查

請以代表性的文章、商品分類、關於頁和聯絡頁測試：

- [Google 複合式搜尋結果測試](https://search.google.com/test/rich-results)：檢查 Google 支援的結構化資料。
- [Schema Markup Validator](https://validator.schema.org/)：檢查完整 Schema 圖譜（不是每種 Schema 都有 Google 複合式搜尋結果）。
- [Google Search Console](https://search.google.com/search-console)：提交 Sitemap，查看網址檢查及索引狀態。

SEO 核心改善的是搜尋引擎理解內容與站內設定的基礎，不能保證排名、流量或權威評分。內容應符合 [Google 以使用者為優先的內容指引](https://developers.google.com/search/docs/fundamentals/creating-helpful-content)；組織及作者資料應遵循 [Organization](https://developers.google.com/search/docs/appearance/structured-data/organization) 與 [Article](https://developers.google.com/search/docs/appearance/structured-data/article) 官方文件，並與前台公開內容一致。
