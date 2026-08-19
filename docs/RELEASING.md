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

- 不讀取 main 或 master。
- ZIP 根目錄固定是 wu-toolbox-modular/，不會改變外掛資料夾名稱或設定。
- 不包含 .git、.github、node_modules、測試或開發暫存檔。
- GitHub API 失敗時安全略過，不會造成 Fatal Error。
- 公開 repo 不需要 GitHub Token。
