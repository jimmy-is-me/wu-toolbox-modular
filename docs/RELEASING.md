# 發布與 WordPress 一鍵更新

外掛只從 GitHub 正式 Release 取得更新；一般 Push 不會讓網站自動安裝新程式碼。

## 發布步驟

1. 請 AI 修改並推送程式碼。
2. 將 wu-toolbox-modular.php 內的 Version 標頭與 WUTM_VERSION 更新為相同版本，例如 1.0.1。
3. Push 版本修改。
4. 建立並推送相同版本的 Tag：git tag v1.0.1；git push origin v1.0.1。
5. GitHub Actions 會驗證版本、建立 wu-toolbox-modular.zip、建立 Release 並上傳 ZIP。
6. 到 WordPress 控制台 → 更新檢查更新，或等待最多 12 小時的 API 快取到期。
7. 在外掛或控制台 → 更新按「立即更新」。

## 保護

### v4.1.6 升級

- Discord 頻道管理員應在 Discord 停用或輪替曾隨舊版公開的 Webhook。更新外掛以後，由 WordPress 管理員到「Wu Toolbox → 儀表板設定」填入新 Webhook。欄位留空時沿用本站已儲存的設定，首次升級尚未設定時不發送通知。工單與申請需要網站設定管理權限，同站同類通知間隔一分鐘。
- 公開 AI 問答在呼叫供應商前扣除當日額度。已發出的供應商請求即使失敗仍占用額度，因供應商可能已處理請求。每站同時處理上限為三筆。使用反向代理時，來源 IP 由主機的受信任代理設定提供。
- 使用者、商品及訂單 CSV 預設使用試算表格式。需要由程式匯入或保留原始欄位時，選擇「原始 CSV」。
- EZPAY 改用 WordPress 的 HTTP 傳輸，保留發票設定與簽章驗證。正式 ZIP 排除發票與綠界 SDK 的範例。

- 不讀取 main 或 master。
- ZIP 根目錄固定是 wu-toolbox-modular/，不會改變外掛資料夾名稱或設定。
- 不包含 .git、.github、node_modules、測試或開發暫存檔。
- GitHub API 失敗時安全略過，不會造成 Fatal Error。
- 公開 repo 不需要 GitHub Token。
