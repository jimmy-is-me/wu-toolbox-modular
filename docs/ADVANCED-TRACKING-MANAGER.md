# Advanced Tracking Manager

## v4.1.9 安裝、使用及回復

更新 ZIP 前備份資料庫及外掛。升級後到 **Wu Toolbox → 進階追蹤管理 → 總覽與驗收**。事件表新增診斷欄位，原有事件 ID、付款條件、排隊資料、Token、選項與同意收據沿用。新啟用的內建同意設定先取得同意；已啟用且未儲存模式的網站延續 v4.1.8 預設行為。

1. **背景排程**顯示 WP-Cron 與兩個 Hook 的時間、實際執行摘要、停用狀態及等待或逾期分類。可使用「立即檢查待發事件」執行同一套到期工作，保留付款與收據驗證。附帶清理過期紀錄。請確認主機的 PHP 請求時間可容納限量批次。
2. **CAPI**分開檢查設定、讀取資料集資訊與合成 PageView 寫入。HTTP 成功且回傳正數的 events_received 表示該 API 確認接收。測試模式使用 Meta 測試代碼，在 Meta 的測試事件頁核對。清除代碼後，新入列事件才採正式模式。
3. **瀏覽器證據**於事件設定選用。彙整少量站內回報，保存事件 ID 的準備、佇列、Pixel 交接或載入錯誤。這項觀測需要瀏覽器執行且可回報，Pixel 交接只代表網站把事件交給程式庫；實際 Meta 接收、跨通道去重和廣告歸因須在平台核對。具備雙通道證據的事件可由管理員記錄已在 Meta 核對，頁面會標示人工核對時間。可在完成驗收後關閉交接取樣。獨立 Pixel／GTM 程式仍須另行檢查。
4. **訂單檢查**輸入 WooCommerce 內部訂單 ID，可比較付款狀態、金額幣別、同意收據與 Purchase 事件。只見 Server Purchase 是常見的非同步情境。舊紀錄受保存期限限制，近期 Purchase 的防重複識別在清除紀錄時暫時保留。
5. **驗收報告**可複製文字或下載 JSON。報告列出環境、站內證據及需外部核對的項目。輸出省略 Token、測試碼、顧客資料與原始事件內容。

需要回復時先備份，再停用及替換為 v4.1.8 安裝包。舊版會重新初始化其資料表版本並沿用原事件紀錄。請保留資料庫和已使用的同意設定，清除公開 HTML／CDN 快取後驗證訪客偏好、付款與排程；勿把舊備份覆蓋到仍在營運的訂單資料庫。降版後使用舊版的診斷畫面，進階證據欄位與報告需新版。

## 開始設定

1. 在 Wu Toolbox 開啟「進階追蹤管理」。在「追蹤碼設定」設定所需平台的 ID，可分別啟用 GA4、Google Ads、GTM 和 Meta Pixel。升級會沿用舊選項，事件管理預設關閉。
2. 在「Cookie 與隱私同意」啟用內建橫幅，或保留網站既有的 Cookie 同意工具並接上文末兩個 Filter。兩者使用其中一種。內建模式的首次訪問規則及付款事件條件見下方。
3. 在「事件管理」啟用事件模式，選擇所需事件與發送通道。使用 WooCommerce 時可另外開啟商品、加購物車、結帳、付款成功等接點。WordPress 提供 PageView、搜尋和註冊接點，Lead 與 Contact 由網站在確認操作成功後呼叫。
4. 使用 CAPI 時啟用伺服器通道，填入 Meta 資料集 ID、Access Token 和支援的 Graph API 版本。雙通道須使用同一個 Meta ID。也可在網站私有設定檔以 `WUTM_ATM_CAPI_TOKEN` 設定憑證，此值優先於後台。後台更新時可留空以保留原值。
5. 可先使用「檢查資料集連線」。要驗證寫入，從 Meta 取得測試事件代碼、儲存後點「發送測試 PageView」，並在 Events Manager 的測試事件頁核對。測試使用合成匹配資料。準備上線時清除測試代碼，已排入的事件保存其原始測試模式。

## 內建 Cookie 與隱私同意（v4.1.4）

### v4.1.8 顯示方式與首次訪問

- 「取得同意後啟用」模式可在「Cookie 與隱私同意 → 橫幅外觀」選擇頁面內橫幅、底部滿版橫幅、角落浮動卡片、中央視窗或右側面板，也可切換淺色與深色。購物車、結帳和會員頁會使用頁面內橫幅。
- 選擇「預設啟用，可手動拒絕」時，左下角只顯示小型「Cookie 設定」按鈕。管理員可調整分析及行銷的預設值。訪客仍可立即拒絕全部非必要追蹤，或分別調整兩個分類。已儲存的偏好會優先套用。
- 預設啟用只影響瀏覽器標籤與瀏覽事件。Google 的廣告使用者資料及個人化信號、Meta CAPI、WooCommerce 購買事件仍依明確儲存的同意處理。已儲存的管理員模式會沿用。

1. 從「Wu Toolbox → 進階追蹤管理 → Cookie 與隱私同意」啟用。可設定標題、說明、按鈕名稱與隱私權政策網址。網址留空使用 WordPress 的隱私權政策頁。
2. 訪客可直接同意全部或拒絕非必要，也可個別選擇分析與行銷。必要功能永遠開啟。若要修改，使用左下角的「Cookie 設定」，或把 `[wu_cookie_preferences]` 放在網站適合的位置。
3. GA4 依分析同意載入。Google Ads、Meta Pixel 和 CAPI 依行銷同意載入或發送。GTM 預設需兩種同意，純分析或純行銷容器可在分頁調整。GTM 仍須為每個標籤設定對應的同意與觸發條件。 [Google 的同意模式](https://developers.google.com/tag-platform/security/guides/consent) · [GTM Consent API](https://developers.google.com/tag-platform/tag-manager/templates/consent-apis)
4. 同意紀錄預設保留 180 天，可設 7–365 天。瀏覽器使用站內 HttpOnly Cookie，資料庫保存匿名雜湊、選項、期限和隨機同意收據。WordPress 排程分批清理過期紀錄。改變保留天數會套用到下一次儲存，政策或用途變更可勾選「使現有偏好失效」要求重新選擇。
5. 顧客結帳時，WooCommerce 訂單僅記下適用的隨機收據。客戶撤回、收據逾期或政策重置後，背景付款及佇列發送會重新查驗並擋下失效的轉換。重新同意會發給新收據。已由平台接收的請求無法由本模組撤回。

### 快取與驗證

內建模式的公開 HTML 不含訪客偏好、收據或待送事件。偏好和事件由同站的 `admin-ajax.php?action=wutm_atm_privacy` 以 POST 取得，標記為 `private, no-store`。因此公開頁面可維持 WU 頁面快取或 CDN 快取。購物車、結帳、訂單付款及完成頁維持原有排除。在 Cloudflare 已設定 Cache Everything、強制 Edge TTL 或自訂 Worker 時，確認 AJAX 和個人頁面有明確的略過規則。切換內建模式或更改橫幅後，清除 CDN 舊的公開頁面。 [Cloudflare Cache-Control](https://developers.cloudflare.com/cache/concepts/cache-control/)

測試可用兩個無痕工作階段︰先在一個視窗只允許分析，核對只載入 GA4。另一個視窗開同一個快取頁時應使用該站的預設模式。再允許、拒絕並刷新，檢查選擇是否保留。GTM 容器、主題及其他外掛獨立安裝的標籤由各自管理。任意的舊版自訂結帳程式碼仍在原 WooCommerce 完成頁接點執行，進入時須已有行銷同意。

內建模式直接使用既有行銷同意與收據驗證介面。關閉後回到原有設定及第三方同意 Filters，先前的內建收據會失效。

## 追蹤、付款與快取

- 一筆已付款 WooCommerce 訂單的瀏覽器與伺服器 Purchase 使用同一 event_id。伺服器在付款確認後排程，顧客可關閉網頁。確認付款前的訂單與失敗、取消、退款訂單不會新送出 Purchase。伺服器送出前會再確認狀態。訂單金額、幣別及商品代號由 WooCommerce 訂單取得。
- 瀏覽器端需持有有效的訂單完成頁授權。事件紀錄的「已準備瀏覽器事件」僅指出本機準備完成。伺服器的「Meta 已確認接收」以 API 確認為依據。廣告歸因可在平台上另行查閱。GTM 容器、主題及其他外掛的獨立追蹤請在各自設定中確認，診斷提供基本重複安裝提示。
- 沿用第三方同意工具且啟用事件模式時，會使用舊版 WordPress 禁快取訊號。若服務在 WordPress 前直接提供整頁，請依該第三方整合設定頁面排除。內建模式的公開頁面可照常快取。
- 使用 WP-Cron，正式站若停用內建 Cron 請由主機定時執行。傳輸錯誤與暫時性回應最多嘗試四次，重試沿用同一 ID。預設事件紀錄保存 14 天，可設 7–60 天，待送資料最多保留兩天，終止後移除待送內容。管理介面只顯示診斷摘要。測試事件與真正的 Meta 事件可使用 Meta 後台分辨。
- CAPI 匹配資料只在確認同意後組裝。可使用雜湊電子郵件、有效的 `_fbp` 和 User-Agent，IP 預設不傳送。來源網址會限制在網站本身並移除查詢參數。傳入自訂欄位前請排除個資。

## 第三方 Cookie 工具與開發介面

| 入口 | 行為 |
| --- | --- |
| `wutm_atm_marketing_consent` | 由同意工具回傳行銷狀態。瀏覽器通道可回傳 `true`。伺服器通道需要 `['granted' => true, 'reference' => 'opaque-receipt']`。 |
| `wutm_atm_consent_still_valid` | 參數為預設 `false`、同意識別碼、事件資料。請以目前仍有效的同意紀錄回傳布林值。於入隊及發送前皆會確認，排程不依賴訪客 Cookie。 |
| `wutm_atm_custom_events` | 回傳額外事件名稱清單，再由管理員依需打開並儲存。 |
| `wutm_atm_track` | 參數為名稱、資料、情境。PHP 亦可呼叫 `WUTM_ATM_Events::track()`，回傳已準備或已排程事件 ID，或 `null`。 |
| `wutm_atm_event_parameters` | 參數為資料、事件名稱、情境，用於調整業務事件參數。WooCommerce Purchase 的金額及幣別以實際訂單資料為準。 |
| `wutm_atm_order_paid` | 參數為已付款判斷與 `WC_Order`，供非標準金流加入經驗證的付款狀態。 |
| `wutm_atm_order_consent` | 參數為訂單保存的同意紀錄與 `WC_Order`，供特殊結帳流程提供可驗證的同意紀錄。 |
| `wutm_atm_allow_ip_matching` | 預設 `false`，在網站政策與同意均允許時可選用 IP 匹配。 |
| `wutm_atm_tracking_settings_updated` | 參數為事件模式啟用狀態，可供既有快取整合程式清理網站快取。 |

第三方同意工具應使用自己可查證的收據儲存。收到撤回時，先更新該記錄，再從前台執行 `document.dispatchEvent(new CustomEvent('wutm-atm-consent', { detail: { granted: false } }))`。本模組會停止後續瀏覽器事件並更新已載入的 Meta、Google 同意狀態。GTM 內其他標籤仍需在容器設定自己的同意條件。第三方工具同意後重新整理，讓 WordPress 重新確認狀態。

自訂事件範例。在已驗證並持久化的表單、課程或預約完成後呼叫。使用穩定的業務識別值，同一個操作的重送會使用相同 ID。

```php
add_filter('wutm_atm_custom_events', static function (array $events): array {
    $events[] = 'BookingConfirmed';
    return $events;
});

// 由網站自己的預約程式在真實操作成功後執行。
do_action('wutm_atm_track', 'BookingConfirmed', [
    'value' => $booking_total,
    'currency' => $booking_currency,
    'custom' => ['seats' => $confirmed_seats],
], [
    'source' => 'site-bookings',
    'key' => (string) $confirmed_booking_id,
]);
```

站外或非同步流程可傳入 `recorded_consent` 及 `server_only`。透過同意工具保存可撤回的識別碼，請勿把公開表單欄位直接當作同意證明。公眾 AJAX 只可讀取自身 WooCommerce 工作階段已發生的加購物車事件，沒有接受付款或建立任意事件的入口。

## 停用與驗證

- 停用「事件管理」會停止新增與發送伺服器事件，並回到原有追蹤設定。要停止所有平台標籤，亦可關閉個別平台或整個 Toolbox 模組。要清除待送資料與紀錄，可先關閉事件管理，再於事件紀錄頁執行清除。
- 瀏覽器回歸測試針對重複執行、重新載入與撤回。WordPress 整合測試使用一般站、WooCommerce 傳統／HPOS 訂單和模擬的 Meta API 回應。實際 Meta 資料集、網站選用的同意工具、第三方付款流程及站外快取需在各自環境驗證。
- 官方參考文件：[Meta CAPI 參數](https://developers.facebook.com/docs/marketing-api/conversions-api/parameters/v25.0/)、[WooCommerce 區塊結帳接點](https://developer.woocommerce.com/docs/block-development/reference/hooks/hook-alternatives/)。
