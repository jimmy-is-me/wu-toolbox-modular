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
