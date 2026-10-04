# 頁面快取與網站速度（v3.5.3）

WU Toolbox 的頁面快取只處理可公開共享的完整 HTML。登入、購物車、結帳、會員、搜尋、預覽、REST、帶查詢參數及 TranslatePress 次要語言頁都略過。網站原有的 `/en/` 免快取設定可保留；外掛也會自行識別 TranslatePress 的語言路徑。

快取以 GZIP 儲存在磁碟上，但在 PHP 命中時解壓並輸出 HTML。這避免輸出緩衝修改位元組後與預設的 `Content-Length` 不符。Nginx 或 Cloudflare 負責傳輸 GZIP；不要再由 PHP 重複壓縮。同一頁依桌面、平板、手機分開快取，內容更新與外掛更新會清除相關或全部快取。

參考 xSpeed 的速度功能時，本站應避免疊加功能造成重複處理：

| 項目 | v3.5.3 採用方式 |
| --- | --- |
| 頁面快取 | 本模組快取完整、公開的原始語言 HTML；翻譯與動態頁略過。 |
| HTML／CSS／JS 壓縮 | 不在頁面快取中重寫輸出。主題、外掛或既有資產流程若已壓縮，重複壓縮可能破壞程式碼或增加 CPU。 |
| GZIP | 磁碟快取使用 GZIP；網路回應沿用 Nginx／Cloudflare 的壓縮。 |
| 圖片／iframe 延遲載入 | 沿用 WordPress 原生 `loading` 規則及既有圖片模組，不對首屏圖片強制 lazy load。影片維持既有頁面設定。 |
| 瀏覽器快取 | 靜態圖片、CSS、JS 的期限應由 Nginx／Cloudflare 設定；PHP 的 HTML 快取無法替靜態檔案設定回應標頭。 |
| 字型最佳化 | 只預載確定用於首屏的字型；避免全站自動預載造成競爭下載。`font-display` 應在實際字型 CSS 中設定。 |

更新後請在管理頁清除 WU Toolbox 頁面快取，並確認原始語言首頁首次為 `X-WUTM-Page-Cache: MISS`、再次為 `HIT`，英文 `/en/` 不帶該標記且正常顯示。若網站同時使用其他全頁快取，請避免對相同 HTML 疊加不同的快取規則。
