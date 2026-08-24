# WU Toolbox Modular 授權設定

WU Toolbox Modular 1.3.0 會在 WordPress B（使用外掛的網站）第一次輸入授權碼時，向 WordPress A 的 REST API 驗證。成功後會快取結果 30 天；API 暫時無法連線時，仍提供 14 天寬限期，不會造成網站 Fatal Error。

## WordPress A API 回應格式

B 站會送出 POST 請求，欄位包括：

- license_key
- site_url
- plugin_slug（固定為 wu-toolbox-modular）
- version

A 站應回傳 JSON：

```json
{
  "valid": true,
  "expires_at": "2027-12-31",
  "site_url": "https://example.com",
  "message": "License active"
}
```

失敗時回傳 {"valid":false,"message":"Invalid license"} 並使用 4xx 狀態碼。

## 建議的 A 站實作

可用獨立 WordPress 外掛註冊 REST route。驗證時讀取授權碼資料表，檢查 active、到期日與網站綁定，再回傳 valid、expires_at、site_url、message。

正式環境請將授權碼放在專用資料表、限制啟用網站數量，並加入啟用/停用端點與簽章驗證。不要把管理站的資料庫密鑰放進 B 站外掛。

## B 站設定

在「WU Toolbox → 授權設定」輸入：

- 授權碼
- A 站 REST API，例如 https://license.example.com/wp-json/wutm-license/v1/validate

驗證成功後，所有模組才能啟用；模組仍會顯示在主畫面，但未授權時開關會鎖定。
