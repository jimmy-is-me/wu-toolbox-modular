# WC 運送溫層限制 2.1.0 功能對照

提供文字 SHA256：647f782a1657de28478d1baf162e703f718c272743537427ea2bfbdbda5c3e17。
整合採獨立類別，保留 `_shipping_temperature`、`stt_room_methods`、`stt_cold_methods`、`_stt_bulk` 資料鍵，不刪除或搬移既有資料。

| 原始章節 | 整合入口 |
| --- | --- |
| 1 商品運送欄位及儲存 | product_field / save_product |
| 2 商品列表溫層欄位 | columns / column |
| 3 後台樣式及快速編輯回填 | assets / admin.css / admin.js |
| 4 Quick Edit | quick_field / save_quick |
| 5 Bulk Edit 及不變更 | bulk_field / save_bulk |
| 6 設定頁、已啟用運送區域與預設區域 | page / methods |
| 7 皆可跟隨及整體溫層計算 | temp / cart_type |
| 8 溫層物流篩選 | filter_rates |
| 9 常溫與冷藏加入衝突 | validate_add / validate_store_add |
| 10 購物車及結帳提示 | cart_notice / check_cart / store_cart_errors |
| 11 商品頁及商品列表標籤 | badge |

原本空白允許清單代表不限制物流，此行為保留並在設定頁明確說明。所有顯示文字移除 emoji。
優化：加入編輯權限及專屬 nonce；未提交欄位不覆寫舊值；物流子費率繼承相同實例；溫層與允許清單納入快取指紋；快速編輯只回填指定商品；原程式共存時停用本模組業務處理。
安全修正：原程式只在加入時阻擋混合溫層、既有衝突反而回傳所有物流；本模組也在傳統及 Store API 結帳檢查，衝突時不提供費率，防止匯入或還原購物車繞過規則。
