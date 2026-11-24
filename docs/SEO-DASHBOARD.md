# WU SEO 視覺化儀表板

在 **Wu Toolbox → SEO → WU SEO 視覺化儀表板** 開啟。搜尋成效分頁的「進階設定與 Google 授權」沿用既有 OAuth 設定與唯讀授權。尚未授權時可使用 WordPress 本機檢查與公開 Sitemap 診斷。

- **搜尋成效**使用 Search Console 的網頁搜尋與已定稿資料，以太平洋時間選擇 1–90 天。前期為緊鄰、天數相同的區間。全站 KPI 使用 API 個別回傳的彙總，熱門查詢和頁面各取 API 回傳前 25 筆。未回傳的日期保持缺值。點查詢可查看曝光頁面，點頁面可查看對應查詢。文章、頁面與公開自訂內容的列表也有單頁入口。
- **索引狀態**預設以近期公開內容為範圍，可搜尋其他內容。每批最多勾選 20 筆，統計只涵蓋目前清單最多 30 筆。圖表、表格、索引時間及原因都取自同一筆 Google 回應。WordPress noindex 和 canonical、公開 HTTP 結果、Google 索引紀錄分欄顯示。
- **Sitemap**保留原生 XML，並區分 WordPress 狀態、公開 HTTP／XML 與 Search Console 回報的讀取、錯誤及警告。公開內容首次發布後由 WP-Cron 檢查相符的原生子檔，最多檢查 30 個候選子檔。主檔或子檔不可讀時可在明細查看原因。未找到且沒有可驗證原因時標示待確認。
- **關鍵字**可儲存 20 組查詢及選用目標頁。點「看趨勢」後可對照日期、國家、裝置及實際取得曝光的頁面。由於 API 資料與查詢隱私條件，頁面明細只呈現 API 已回傳的項目。進步及下滑卡片只比較兩期前 25 筆內共同出現的查詢。
- **技術診斷**保留舊版原生分析、內容管理、批次修改、robots.txt 與轉址檢查。Cloudflare 挑戰依回應標頭判定。舊 SEO 設定匯入每批預覽 50 篇，一般欄位預設填空，Canonical 和 noindex 分別確認；其他內容與來源插件設定保留。

搜尋成效、索引與 Sitemap API 以 WP-Cron 背景分批查詢。過期、限流、部分失敗及授權問題會顯示在畫面。網站停用內建 WP-Cron 時，主機需定期觸發 WordPress 排程。Sitemap 提交及索引申請仍可使用頁面中的 Google 入口。

資料欄位與彙總方式可參考 [Search Analytics](https://developers.google.com/webmaster-tools/v1/searchanalytics/query)、[URL Inspection](https://developers.google.com/webmaster-tools/v1/urlInspection.index/inspect)及 [Sitemaps API](https://developers.google.com/webmaster-tools/v1/sitemaps/list)。

## 正式站驗收

使用有權限的 Google 帳號連結正式屬性，比對相同日期、裝置和國家的 Search Console 報表。於「關鍵字追蹤」加入「高雄網頁設計」，確認 API 回傳時可分別看到首頁與服務頁曝光。再比對單頁 URL Inspection、已提交 Sitemap 與 Google 的上次下載時間。確認發布新公開內容後，網站排程能讀取實際公開子 Sitemap。

## 第二階段

- Schema 編輯器支援 Service、SoftwareApplication／WebApplication 及 TechArticle。
- 雙語 canonical 和 hreflang 驗證。
- 站內連結建議。
- GA4 自然搜尋到達頁與表單成功送出的轉換報表。
