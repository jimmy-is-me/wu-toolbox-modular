# AI 連接器｜OAuth 與 MCP

WU Toolbox Modular v4.2.9 將 AI 連接器改為 WordPress 原生 OAuth。新版以登入本站的帳號授權，既有固定金鑰會失效，站內文章、自訂欄位、SEO、媒體及歷史操作紀錄會保留。

## 使用 ChatGPT 連線

1. 到 **WU Toolbox Modular → AI 功能 → AI 連接器**，啟用模組。
2. 在 **內容類型與權限** 開放需要的文章、頁面或 CPT。在 **進階安全設定** 開放需要的 Scope。一般內容先用草稿，正式發佈額外需要本站開關、該類型的發佈操作、OAuth 授權、WordPress 發佈權限以及一次性人工確認。
3. 到 **連線總覽** 按 **測試本站端點**。這一步只驗證本站的 Metadata、OAuth 挑戰和 Token 端點。
4. 在 **平台連線設定** 複製 MCP Server URL。到 ChatGPT 的 Plugins 新增自訂 MCP 伺服器，填入網址，選 **OAuth**。啟用動態註冊時可使用平台的 DCR 選項。若平台要求預先註冊，在 **已授權的應用程式** 登錄畫面提供的完整回呼網址，使用生成的 PKCE 客戶端 ID。
5. 登入本站 WordPress，核對應用程式名稱、回呼網址、內容類型和權限後按 **同意授權**。動態註冊的名稱由客戶端提供。
6. 在對話選用該連線並要求呼叫 verify_connection。再從 **功能與指令** 複製查詢或建立草稿的指令。需要補充權限時重新授權。寫入功能依 ChatGPT 方案、帳號及工作區政策而定。
7. 在 **操作紀錄** 核對結果與編輯連結。若要終止存取，管理員可在 **已授權的應用程式** 撤銷指定授權、客戶端或所有授權。授權者也可在個人資料頁撤銷自己的授權。

這個流程使用 ChatGPT 的自訂 MCP 客戶端與本站 WordPress 帳號。參考 [OpenAI 自訂 MCP](https://developers.openai.com/api/docs/guides/custom-mcp-server)、[OAuth 認證](https://developers.openai.com/plugins/build/auth)及 [ChatGPT 檔案參照](https://developers.openai.com/plugins/reference#file-apis)。

## 網址與主機設定

以根目錄安裝於 example.com、使用 WordPress 一般 REST 路徑為例。實際值從後台複製。

| 項目 | 路徑 |
| --- | --- |
| MCP Server／Resource | /wp-json/site-ai/v1/mcp |
| OAuth Issuer | /wutm-site-ai |
| Authorization | /wp-admin/admin-post.php?action=sac_oauth_authorize |
| Token | /wp-json/site-ai/v1/oauth/token |
| Registration | /wp-json/site-ai/v1/oauth/register |
| Revocation | /wp-json/site-ai/v1/oauth/revoke |
| Authorization Server Metadata | /.well-known/oauth-authorization-server/wutm-site-ai |
| Protected Resource Metadata | /.well-known/oauth-protected-resource/wp-json/site-ai/v1/mcp |

- OAuth 使用 Authorization Code、PKCE S256、HTTPS、RFC 8707 Resource 與 RFC 9207 回呼 Issuer。支援 DCR 與預先註冊。DCR 僅接受設定中精確允許的回呼，預設為 https://chatgpt.com/connector_platform_oauth_redirect 。若平台顯示不同回呼，先核對平台連線管理頁，再加入完整網址。CIMD 未啟用。
- WordPress 位於子目錄時，Metadata 仍從網域根目錄的 /.well-known/ 服務，後面加上實際 Issuer 或 MCP 路徑。主機必須將對應的兩條路徑交給該 WordPress 的 index.php，並保留原始 REQUEST_URI。若主機已有其他 OAuth 外掛，檢查命名空間專用路徑及回傳的 issuer 值。已有外部路由接手時，可用 sac_oauth_external_metadata 過濾器將相應的探索路徑交給主機處理。
- Apache 或 Nginx 讓 OAuth 端點及 MCP 收到標準 Authorization 標頭。PHP-FPM 可在該站 FastCGI 設定保留 HTTP_AUTHORIZATION，避免代理移除標頭。
- Cloudflare 只針對本站實際的兩條探索路徑、REST 命名空間 /wp-json/site-ai/v1/，以及 /wp-admin/admin-post.php 的 sac_oauth_authorize、sac_confirm_change、sac_review_changes 和 sac_upload_image 動作套用 Bypass cache。使用預設永久連結時同樣涵蓋 rest_route=/site-ai/v1/ 的請求。
- 若 WAF 或 Bot 防護攔到官方呼叫，以以上路徑及所需方法縮小例外。站點使用 HTTPS，反向代理由主機轉送可信的 HTTPS 狀態，避免讓任意外部 Forwarded 標頭決定安全連線。
- 先用匿名 GET 核對兩個 Metadata 檔，再以匿名 POST 確認 MCP 回傳 401 及 WWW-Authenticate resource_metadata。完成授權後測試工具呼叫。若回傳 CDN 挑戰 HTML，依實際規則及事件紀錄排查。

## 內容、SEO、FAQ 與圖片

- **內容**：先列出內容類型和欄位，用分類查詢取得現有項目 ID，再建立或更新。一般文章、頁面和多種 CPT 共用同一套權限。支援原生標題、正文、摘要、slug、作者、上層頁面、分類和精選圖片。Gutenberg 可傳送有效的 WordPress 區塊 HTML。
- **Lab**：偵測到已知 Wumetax Lab System 與 wulab_type、wulab_tech 時，顯示由該外掛維護的英文標題、網址、排序、狀態及 Challenge／Approach／Result。正式站仍應透過 describe_content_type 與 list_content_terms 核對當前設定。
- **SEO**：依啟用狀態選定 WUMETAX SEO Core、Yoast、Rank Math 或 SEOPress 的單一適配器。可用欄位依所選外掛而定。沒有啟用適配器時回報原因。FAQ 使用既有 AI 連接器規則，請在更新前檢查既有頁面是否已有 FAQ 區塊或獨立 Schema。
- **圖片**：支援 multipart 的 image 欄位、Base64、可直接取得的 HTTPS 網址及 ChatGPT 的 file 參照物件。若 ChatGPT 只提供平台內部路徑，請使用 create_upload_link 取得 10 分鐘的一次性連結，由同一 WordPress 帳號登入後上傳，再查詢 Attachment ID 並設定精選圖片。檔案大小以 8 MiB 和主機上傳上限較低者為準。僅接受以 WordPress 圖片編輯器能安全解碼和重新儲存的 PNG、JPEG 或 WebP。重名上傳由 WordPress 產生新檔名。
- **已發佈內容**：伺服器回傳 WordPress 審核連結。授權者核對原內容與預計變更，親自確認後才可重送相同操作。一旦權限或目標狀態變更，請重新產生審核。部分寫入失敗會回報目標 ID。
- **刪除**：一般內容由站長在 WordPress 處理。已開放的電商垃圾桶操作需要使用者確認及 WooCommerce 權限，且 WordPress 必須啟用垃圾桶。

### v4.3.1 批次審核

1. 用 `list_content_types`、`describe_content_type` 確認本站授權及欄位。用 `analyze_content` 取得需要的資料，例如 `include: ["fields", "outline", "seo", "faq"]`。正文或公開 HTTP 診斷請按需加入。
2. 修改 Gutenberg 時先呼叫 `list_content_blocks`。用回傳的正文 `version`、區塊 `path` 和 `hash` 指定插入、取代、刪除或移動位置。提供 `block_path` 可查單個區塊原始標記。區塊路徑依原始資料排序，內容變更後要重新查詢。
3. 呼叫 `prepare_content_changes`，提供內容類型、文章 ID、變更內容和 16 至 80 字元的 `idempotency_key`。同一次網路重試沿用原識別碼。一般欄位放在 `content` 物件，SEO 放在 `seo`，完整 FAQ 放在 `faqs`。用 `featured_image_id` 和 `seo.og_image_id` 關聯媒體庫圖片。若要一併更新同一張圖片的標題、ALT、Caption 或 Description，可把媒體 ID 與要改的欄位放在 `media`。
4. 開啟回傳的 `confirmation_url`，以原授權帳號登入。展開需要核對的項目，可取消勾選單個原生內容欄位、整組 SEO 或整組 FAQ。按「核准並套用」後由 WordPress 儲存及核對，無須再送一次正文。審核連結有效 30 分鐘。
5. 用 `get_content_change_status` 查詢狀態，用 `verify_content_changes` 重新核對實際資料。選用 `frontend: true` 核對匿名 HTTP 回應。已儲存資料與匿名前台可能因快取而有差異，請用兩組結果各自判讀。
6. 用 `list_content_operations` 查詢套用紀錄，用 `cancel_content_changes` 取消待審核變更。若需要還原，用 `rollback_content_changes` 和新的識別碼準備另一筆審核。只有現有欄位仍等於前次保存結果，且原值可透過同一適配器安全寫回時，才能準備還原。部分失敗會報告已復原或須手動核對。

**AI 操作範例**

> 請先核對這篇自訂內容的欄位與 Gutenberg 版本。把新摘要、SEO 描述及 15 組 FAQ 放入同一批審核。比較表插在指定區塊後，保留既有分類、自訂欄位和正文。準備完成後提供 WordPress 審核連結。等我核准，再用操作編號核對已儲存的資料。

- 一般內容請求上限為 768 KiB，變更及伺服器預覽合計依流程最多 1.5 MiB。較大的原始內容可用精準區塊操作減少傳輸量，仍受預覽儲存上限保護。批次變更快照與收據保留 7 天，精簡操作紀錄保留 30 天。資料皆按站點儲存，原有 OAuth 資料表沿用。
- 既有單筆操作與舊版確認流程仍可使用。同一文章的標題、獨立 SEO 欄位、FAQ 各自比對核准時的資料。發佈仍需既有單筆確認與站點權限。
- 手動上傳的連結有效 10 分鐘。已完成收據可在連結失效後查詢，工作階段保留 24 小時。舊版 `check_upload_link.status` 已完成仍為 `uploaded`，新版程式可讀 `upload_status: "completed"`。直接上傳可選擇最大寬、高和 WebP 轉換，依 WordPress 主機的圖片編輯器能力而定。
- `audit_content_seo` 查看公開頁面的 HTTP、轉址、標題、Canonical、Robots、社群標記、標題層級、Schema 類型及圖片 ALT 統計。Sitemap 工具只驗證本次已讀取的 XML 範圍，搜尋引擎收錄須另由 Search Console 查詢。
- Search Console 預設關閉。先在 **WU SEO → 搜尋成效** 連接 Google，再在 **AI 連接器 → 進階安全設定** 開放 AI 讀取。OAuth 使用者需具備 WordPress 站點管理權限與 SEO 查詢授權。用 `analyze_content` 選擇 `gsc`，選用 7、28 或 90 天、三碼國家與裝置，稍後重查背景同步。使用既有 Google 帳號和屬性。
- OAuth 無須重新連接。刷新 MCP 工具清單即可。已撤銷授權或需要新增 Scope 時，依原流程重新授權。

### 目前範圍

- All in One SEO 透過公開 HTML 與 Sitemap 診斷。該外掛的資料庫欄位寫入、第三方 FAQ 編輯器、外站連結逐一驗證、分段上傳及未關聯媒體自動清理仍待後續支援。
- 還原範圍涵蓋可透過現有適配器精確回寫的批次欄位。第三方的儲存附帶動作須由管理員在 WordPress 檢查。
- 仍需在有 Google Search Console 授權、外部 CDN 或正式 ChatGPT 連線的暫用站驗證各自的實際回應。

### v4.3.2 驗收與快取

- 在審核頁核對文章、風險、適用欄位、搜尋／社群分享模擬與圖片來源。可要求重新調整，由 AI 重新準備一筆審核。
- 更新後呼叫 `verify_content_changes` 並提供 `frontend: true`。分別閱讀資料庫、快取、公開 HTML、Sitemap、結構化資料與圖片結果。前台重新取匿名 HTML、重新讀取 Sitemap。偵測到快取標頭且有實際內容差異時回報 `stale_cache`。HTTP 200、未授權、挑戰頁和無法對應的版型輸出各有獨立狀態。
- `retry_content_cache_purge` 只重跑已套用變更的快取清除。它沿用 WU Page Cache 的文章、類型、分類及裝置／語系變體配對，並帶入原網址與原分類。也會清除對應的 SEO 診斷暫存。有啟用 LiteSpeed 外掛時呼叫既有文章清除接點。本站已有 WU 快取功能的版本沒有 Cloudflare API 設定，Cloudflare 回報 `unavailable`。站長可在 CDN 按 URL 清除並再次驗收。
- **AI 連接器 → 操作紀錄**可查看最近 60 筆 Change Set 的核准、快取與前台驗收狀態。詳細逐項比對以 MCP 回傳為準。
- 用 `get_capabilities` 核對版本、可用工具和工具清單雜湊。若客戶端仍顯示舊清單，重新整理工具即可。Scope 名稱和既有 OAuth 授權沿用。

### v4.3.2 圖片傳輸

1. 可使用既有 HTTPS、Base64、multipart、平台可讀取的檔案參照和一次性上傳連結。平台只提供內部附件識別碼時，改用一次性連結。
2. 分段仍呼叫 `upload_media`。每次提供相同的 `upload_id`（16 至 80 字元）、`sha256`（原始檔案）、`chunk_count`（1 至 24），並附零起算的 `chunk_index` 與單段 `base64`。每段最多 512 KiB，檔案合計受 8 MiB 與主機上限較低者限制。分段會保存 30 分鐘。
3. 全段到齊後，省略 `chunk_index` 和 `base64`，送出 `complete_upload: true` 與希望保留的檔名、圖片欄位、品質、尺寸及來源資料。組合後重新驗證 SHA-256、尺寸、實際格式，並由 WordPress 解碼及另存。完成回傳 Media ID。重送相同工作階段和參數時會沿用同一附件。
4. 單次傳輸可傳 `idempotency_key` 或 `deduplicate: true`。去重依同一 OAuth 授權、原始檔案 SHA-256 和上傳設定。選填 `source_url`、`source_type`、`creator`、`license`、`attribution`、`generated_by_ai`。審核資料呈現提供的來源聲明。請填寫可確認的資料。

本版在傳輸完成時建立附件，由另一筆 Change Set 審核後綁定精選圖片及 OG Image。待擴充項目包括核准前的私有圖片預覽、Cloudflare API 指定網址清除、較完整的第三方版型與連結診斷，以及在操作紀錄中心直接檢視全部欄位差異。正式 ChatGPT 檔案傳輸、實際 CDN 與已授權 Google Property 須在可用的暫用站驗收。

### v4.3.3 草稿更新與重新連線

- 先用 `get_content` 核對 ID、CPT 與目前狀態，再呼叫 `prepare_content_changes` 並設定 `expected_status: "draft"`、`dry_run: true`。Dry Run 回傳欄位路徑與錯誤，保留所有文章資料，也不建立審核。
- 相同的 `content.status` 只會回傳提示。`content.custom_fields.status` 由本站 CPT 欄位設定驗證。正式發佈仍走 `publish_content` 的既有人工審核。
- Dry Run 通過後，移除 `dry_run`，加入 `idempotency_key`，提交一次完整變更。在 WordPress 核准後用 `verify_content_changes` 核對草稿與欄位。公開驗收與 Sitemap 對草稿顯示 `not_applicable`。
- 同一站點、OAuth 客戶端與授權帳號可使用相同識別碼取回既有變更。若平台建立了新的 OAuth 客戶端，先用原連線查詢先前操作狀態，再決定是否提交新變更。既有授權與 Scope 可沿用。升級後請重新探索工具清單。
- 從 **AI 連接器 → 操作紀錄 → AI MCP 診斷** 複製請求編號、時間和錯誤分類。最多每小時保存 90 筆，保留 3 天。若請求先由 CDN 或 WAF 拒絕，請對照該服務的回應與事件紀錄。
- Change Set JSON 上限為 768 KiB。請求或快照超限時依錯誤拆分正文，或改用 Gutenberg 區塊操作。工具驗證錯誤以 MCP 工具結果回傳。真正失效的權杖和可補授權的 Scope 使用 HTTP 授權挑戰。

### v4.3.4 追加 OAuth 權限

1. 升級後在 ChatGPT 重新整理工具清單，呼叫 `verify_connection`。`scopes` 是目前權杖實際可用的權限。`scope_report` 分列本站、客戶端與使用者同意的權限。
2. 以 `prepare_content_changes` 搭配 `dry_run: true` 檢查完整欄位。若缺少 `taxonomy:write` 等權限，查看 `missing_scopes` 和 `next_action`。Dry Run 保留文章原狀，也不建立審核。
3. 若回傳 `update_client_scopes`，從 **AI 連接器 → 已授權的應用程式 → OAuth 客戶端** 選擇相同 Client ID，勾選需要的權限並儲存。升級前的註冊範圍會保留。新版註冊時省略 Scope 的客戶端依本站開放清單決定可請求權限。明確指定 Scope 的客戶端可由站長按需追加。
4. 若回傳 `authorize_required_scope`，從原應用程式重新發起 OAuth 授權，使用者在 WordPress 核對並同意所需範圍。Refresh Token 只更新同一授權的權杖。再次呼叫 `verify_connection` 確認新增 Scope 已生效，再提交正式 Change Set 並人工審核。
5. 重新授權若失敗，從 **操作紀錄 → AI MCP 診斷** 查看 `oauth_authorize`、`oauth_token` 和請求範圍。遇到 `check_site_policy` 時，由站長先核對進階安全設定。現有客戶端仍可使用時可保留 Client ID。

### 自訂欄位適配器

使用 sac_content_field_definitions 登錄要公開的欄位。適配器提供 label、type、schema、required、authorize、validate、read、write。authorize 接收操作、使用者、文章或 null、文章類型。validate 必須唯讀，回傳正規化值或 WP_Error。write 接收 ID、值及使用者，成功回傳 true 並自行核對實際儲存結果。必填但由編輯器管理的欄位可提供 is_present。

ACF、Pods 及原生 Post Meta 由維護者依型別和權限註冊適配器。已明確登錄 show_in_rest 的單值原生 Meta，也可透過 sac_oauth_registered_meta 明確加入允許清單，讀寫仍經過 WordPress Meta 權限。可在適配器中使用 ACF 的 get_field／update_field、Pods 的 field／save，或固定已知 Meta Key。未綁定的第三方欄位會明確提示需由網站維護者設定。請在暫用站對照實際欄位設定，再把適配器安裝到正式站。

## 安全與資料庫升級

- 連接器第一次需要 OAuth 資料庫時建立各站獨立的 InnoDB 資料表 {prefix}sac_oauth，由 sac_oauth_schema 記錄結構版本。用 WordPress dbDelta 建立及驗證資料表，既有建表版本不重複執行。停用模組即移除服務入口，重新啟用可讀取尚有效的授權。
- 權杖為 PHP 安全亂數產生的 Opaque Token。資料庫僅保留雜湊，交換在 InnoDB 交易內鎖定客戶端、一次性憑證和授權。Refresh Token 輪替後再次使用舊值會撤銷該授權。變更密碼、角色或能力也會使既有憑證失效。其他停權機制可接 sac_oauth_account_allowed。
- 伺服器驗證 Issuer、Resource、WordPress 帳號、Client、Grant、Scope、內容類型及物件能力。發佈與修改公開內容還需短效、一次性確認。服務不採用 MCP Session ID。
- 遠端圖片只接受公開 IPv4 上的 HTTPS 直接下載。DNS 驗證後固定連線 IP，保持 TLS 憑證檢查，拒絕重導、私有網路、Loopback、特殊位址、非允許格式和超限內容。
- 過期資料每日分批清理。客戶端註冊、OAuth 交換及工具請求有分開的頻率上限。操作紀錄僅保存工具、時間、狀態、授權識別與目標，新增紀錄保存 30 天。舊式單筆審核預覽於核准後移除。批次審核快照依上述期限保存。
- OAuth 使用 RFC 8414、RFC 9728、RFC 7636、RFC 8707、RFC 9207、RFC 7591 與 RFC 7009 的相關流程，以及 RFC 9700 的安全要求。此版使用 PHP 安全亂數、雜湊及 WordPress／InnoDB，採 Opaque Token 避免引入 JWT 簽章金鑰或全站 Composer 依賴。MCP 支援 2026-07-28 的無狀態 HTTP 流程，並保留 2025-11-25、2025-06-18 和 2025-03-26 的握手相容性。

## 測試與正式站驗收

- PHP 整合測試在已啟用 AI 連接器的暫用 WordPress 執行下列指令。
  `php tests/site-ai-connector-wordpress.php /path/to/wordpress`。如暫用站使用自訂公開網域，執行前明確設定 `WUTM_SAC_DISPOSABLE_FIXTURE=1`。
- Chrome 與 MCP SDK 的 HTTP 整合測試需安裝 playwright-core 及 @modelcontextprotocol/sdk。設定 SAC_TEST_URL、SAC_TEST_LOGIN、SAC_TEST_PASSWORD、SAC_TEST_CHROME。測試站需允許指定 ChatGPT 回呼及測試 Scope，開啟草稿發佈確認。選用 SAC_TEST_IMAGE 可測試圖片連結，SAC_TEST_SEO=1 可測試啟用中的 SEO 適配器。執行 node tests/site-ai-connector-browser.cjs。測試會建立、寫入及發佈暫用內容，只能在可棄置的站點使用。
- 測試站的本機 HTTP 模式需同時定義 WUTM_SAC_LOCAL_TEST 與 WordPress local 環境，且域名必須是 localhost 或 127.0.0.1。正式站維持 HTTPS。
- GitHub CI 重跑 WordPress 整合測試及全站既有檢查。原生 ChatGPT 連線使用自有帳號與可達 HTTPS 測試站驗收。請先測查詢，再依方案測草稿和實際檔案參照。正式站的 Cloudflare 設定、外部 SEO 外掛的前台輸出，以及 ACF／Pods 的站點專屬欄位需在各自環境核對。

### v4.2.9 驗證紀錄

| 項目 | 結果 |
| --- | --- |
| OAuth 瀏覽器流程 | 暫用 WordPress 通過 Metadata、DCR、登入、拒絕與同意、授權碼單次使用、權杖撤銷，並以本機 HTTPS 反向代理與子目錄重跑 |
| OAuth 整合 | 通過錯誤 Redirect URI、Resource、PKCE、Client 與 Scope 拒絕，亦驗證過期／撤銷、Refresh 輪替與重用撤銷 |
| MCP | TypeScript SDK 實測握手與工具呼叫，HTTP 實測 2026-07-28 探索及標頭與訊息比對 |
| 內容與媒體 | 驗證文章、頁面、多種 CPT、實際 Lab 外掛欄位、SEO Core、FAQ、上傳連結、精選圖片、人工確認發佈與操作紀錄。另測試公開 HTTPS 圖片匯入，在 ACF 和 Pods 啟用時驗證 ACF 未綁定欄位提示 |
| ChatGPT 帳號及正式環境 | 尚待原生 ChatGPT 建立私人連線、平台提供的圖片交付及正式站代理規則驗證 |
