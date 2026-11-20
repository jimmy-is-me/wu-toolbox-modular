# Advanced Tracking Manager 3.0

## 開始設定

1. 在 Wu Toolbox 開啟「進階追蹤管理」。在「追蹤碼設定」設定所需平台的 ID，可分別啟用 GA4、Google Ads、GTM 和 Meta Pixel。升級會沿用舊選項，事件管理預設關閉。
2. 由網站的 Cookie 同意工具接上文末兩個同意 Filter。沒有可靠的行銷同意時，事件模式不載入本模組的行銷標籤或送出事件。
3. 在「事件管理」啟用事件模式，選擇所需事件與發送通道。使用 WooCommerce 時可另外開啟商品、加購物車、結帳、付款成功等接點。WordPress 提供 PageView、搜尋和註冊接點，Lead 與 Contact 由網站在確認操作成功後呼叫。
4. 使用 CAPI 時啟用伺服器通道，填入 Meta 資料集 ID、Access Token 和支援的 Graph API 版本。雙通道須使用同一個 Meta ID。也可在網站私有設定檔以 `WUTM_ATM_CAPI_TOKEN` 設定憑證，此值優先於後台。後台更新時可留空以保留原值。
5. 可先使用「檢查資料集連線」。要驗證寫入，從 Meta 取得測試事件代碼、儲存後點「發送測試 PageView」，並在 Events Manager 的測試事件頁核對。測試使用合成匹配資料。準備上線時清除測試代碼，已排入的事件保存其原始測試模式。

## 追蹤、付款與快取

- 一筆已付款 WooCommerce 訂單的瀏覽器與伺服器 Purchase 使用同一 event_id。伺服器在付款確認後排程，顧客可關閉網頁。確認付款前的訂單與失敗、取消、退款訂單不會新送出 Purchase。伺服器送出前會再確認狀態。訂單金額、幣別及商品代號由 WooCommerce 訂單取得。
- 瀏覽器端需持有有效的訂單完成頁授權。事件紀錄的「已準備瀏覽器事件」僅指出本機準備完成。伺服器的「Meta 已確認接收」以 API 確認為依據。廣告歸因可在平台上另行查閱。GTM 容器、主題及其他外掛的獨立追蹤請在各自設定中確認，診斷提供基本重複安裝提示。
- 事件模式送出 WordPress 禁快取訊號。若 CDN、反向代理或快取外掛在 WordPress 前直接提供整頁，請為事件模式的網站頁面設定略過整頁快取。啟用、停用或變更標籤後清除各層舊快取。靜態檔案仍可快取。
- 使用 WP-Cron，正式站若停用內建 Cron 請由主機定時執行。傳輸錯誤與暫時性回應最多嘗試四次，重試沿用同一 ID。預設事件紀錄保存 14 天，可設 7–60 天，待送資料最多保留兩天，終止後移除待送內容。管理介面只顯示診斷摘要。測試事件與真正的 Meta 事件可使用 Meta 後台分辨。
- CAPI 匹配資料只在確認同意後組裝。可使用雜湊電子郵件、有效的 `_fbp` 和 User-Agent，IP 預設不傳送。來源網址會限制在網站本身並移除查詢參數。傳入自訂欄位前請排除個資。

## Cookie 同意與開發介面

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

同意工具應使用自己可查證的收據儲存。收到撤回時，先更新該記錄，再從前台執行 `document.dispatchEvent(new CustomEvent('wutm-atm-consent', { detail: { granted: false } }))`。本模組會停止後續瀏覽器事件並更新已載入的 Meta、Google 同意狀態。GTM 內其他標籤仍需在容器設定自己的同意條件。同意後重新整理，讓 WordPress 重新確認狀態。

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
