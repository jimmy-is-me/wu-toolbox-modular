# 內建 WooCommerce Subscriptions

此套件原樣包含使用者提供的 WooCommerce Subscriptions 9.3.0，位於 `vendor/woocommerce-subscriptions/`。該程式由 WooCommerce 發布，標示為 GNU GPL 3.0 授權；著作權仍屬原作者。授權全文見 `LICENSE-GPL-3.0.txt`。官方產品與授權資訊：https://woocommerce.com/products/woocommerce-subscriptions/ 、https://www.gnu.org/licenses/gpl-3.0.html 。毛孩日常 PAYUNi 整合程式基於 GPL-2.0-or-later 的 WOOMP 程式，整合後整個套件依 GPL-3.0 發布。

獨立安裝的 WooCommerce Subscriptions 若仍啟用，會優先載入獨立版本，避免重複載入 PHP 類別。停用獨立版本後，下一個請求會載入此套件內建版本；原本的訂閱、訂單、排程與資料表不會由安裝程序刪除。此套件目前固定為 Subscriptions 9.3.0，更新時需發布新版整合外掛並重新測試 WooCommerce 相容性。

上線前須完成 PAYUNi 沙盒的首次付款、通知、續扣、失敗及停止續訂測試。原有獨立外掛停用後，先到「寵物管理 → 訂閱付款設定／訂閱管理」確認內建版本已載入，再開放真實收款。
