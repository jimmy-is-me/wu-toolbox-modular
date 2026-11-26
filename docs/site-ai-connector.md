# AI 連接器

在 **Wu Toolbox Modular → AI 功能 → AI 連接器** 啟用模組並選擇 **開啟 AI 連接器**。原有的站點分類和獨立模組依各自的開關與依賴運作。

## 連線並建立草稿

1. 在「連線設定」選擇平台，建立唯讀或讀寫金鑰。金鑰明碼只在新增時顯示。請填入平台的認證設定。
2. 複製該平台的端點或工具定義。先按「測試本站端點」，再產生平台驗證指令並到選定平台執行。本站收到帶有正確金鑰與短效驗證碼的請求後，會記錄時間。這個驗證由指定平台的呼叫完成，平台名稱取自管理員的選擇。
3. 在「內容類型與權限」開放本站的文章、頁面或 CPT，儲存後展開金鑰並設定該類型的操作範圍及 WordPress 使用者。只有站點、金鑰等級、金鑰範圍和 WordPress 使用者共同允許的操作才會成功。
4. 在「功能與指令」選擇「建立內容草稿」，選定類型並填寫需求。把指令貼到已連線的 AI，核對必填欄位及草稿內容，確認後才建立。成功結果包含類型、標題、ID、狀態及有權使用的後台編輯連結。
5. 更新時用相同頁面的「更新既有內容」指定類型及 ID。AI 應先讀取現況並列出原值和變更，再送出確認過的欄位。可到「操作紀錄」確認實際結果。

| 平台 | 適用條件 |
| --- | --- |
| ChatGPT Plugins／自訂 MCP | 依帳號及工作區是否提供新增自訂 MCP 的入口。OpenAI 文件列出的連線認證為 OAuth、無認證及混合模式。本站連接器使用 API 金鑰。若平台只提供這些選項，須由既有 OAuth 橋接服務轉送本站金鑰。 |
| GPT Actions | 適用仍可編輯 Actions 的 GPT。匯入 OpenAPI 並設定自訂認證標頭 `X-SAC-Key`。若帳號已遷移至 Plugins，請依該入口的認證方式設定。 |
| Claude Messages API | 使用遠端 MCP 支援的 API，設定 `mcp_servers` 和 `mcp_toolset`，以 `authorization_token` 傳送金鑰。Claude 網頁與桌面版依產品實際提供的認證選項。 |
| Gemini API | 取得函式定義，由呼叫 Gemini API 的應用程式依 OpenAPI 呼叫本站 REST，將結果回傳給模型。使用 `X-SAC-Key`。 |
| 其他 MCP 用戶端 | 支援 Streamable HTTP，並可傳送 `X-SAC-Key` 或 `Authorization: Bearer`。WordPress 端點需能從 AI 平台存取。 |

[OpenAI 自訂 MCP](https://developers.openai.com/api/docs/guides/custom-mcp-server) · [GPT Actions](https://help.openai.com/en/articles/9442513-configuring-actions-in-gpts) · [Claude MCP](https://platform.claude.com/docs/en/agents-and-tools/mcp-connector) · [Gemini 函式呼叫](https://ai.google.dev/gemini-api/docs/function-calling)

## 內容類型與欄位

一般內容依 WordPress 已註冊且有後台介面的類型判定，支援 `show_in_rest=false`。商品、附件、訂單、修訂、工具箱表單送件等資料使用各自的專用流程。站點可用 `sac_excluded_content_types` 增列應排除的內部類型。精選圖片須是可讀取的圖片附件。分類使用該類型所綁定且使用者可指派的 taxonomy，值為現有的分類項目 ID。使用 `list_content_terms` 查詢 ID。僅指定的 taxonomy 會替換其項目。

自訂欄位由站點外掛在 `sac_content_field_definitions` 登錄。連接器會列出 ACF 與 Pods 已知的未綁定欄位與處理方式。若站點外掛有另外的條件欄位或驗證，請在回呼中帶入該條件。對取決於既有文章的欄位，可在 `describe_content_type` 指定 `id` 取得該筆定義。

每個欄位可設定 `label`、JSON Schema `type`、額外的 `schema`、`required`、`authorize`、`validate`、`read`、`write`。`authorize` 接收操作、WordPress 使用者、現有文章或 null、文章類型物件。驗證應回傳已正規化的值或 `WP_Error`，並且在驗證階段維持資料唯讀。儲存回呼收到內容 ID、已驗證值、使用者，成功回傳 `true`。現有不做寫入的必要欄位可提供 `is_present` 回呼檢查是否已填。若複雜欄位仍需原外掛處理，可登錄 `unsupported` 文字指引。

以下示例由維護者加入站點的 MU 外掛。更換類型 slug、欄位名稱和 ACF 欄位 key，並依本站的欄位權限實作 `authorize`。

```php
add_filter( 'sac_content_field_definitions', function ( $fields, $type ) {
    if ( $type !== 'field_report' || ! function_exists( 'update_field' ) ) return $fields;
    $acf_key = 'field_replace_with_the_field_key';
    if ( ! acf_get_field( $acf_key ) ) return $fields;

    $fields['source_note'] = [
        'label' => '資料來源',
        'type' => 'string',
        'required' => true,
        'authorize' => function ( $operation, $user, $post, $post_type ) {
            if ( ! user_can( $user, 'manage_options' ) ) return false;
            return ! $post || user_can( $user, $post_type->cap->edit_post, $post->ID );
        },
        'validate' => function ( $value ) {
            return sanitize_text_field( $value );
        },
        'read' => function ( $post ) use ( $acf_key ) {
            $value = get_field( $acf_key, $post->ID, false );
            return $value === false ? null : $value;
        },
        'write' => function ( $id, $value ) use ( $acf_key ) {
            update_field( $acf_key, $value, $id );
            return get_field( $acf_key, $id, false ) === $value;
        },
    ];
    return $fields;
}, 10, 2 );
```

Pods 欄位可將讀寫回呼改為使用該 Pod 的 `field()`、`save()`。自製欄位可接到站點自己的儲存函式。第三方讀寫失敗時會回報欄位與處理方式。若建立過程發生欄位儲存錯誤，連接器會嘗試移除這次新增的內容。若更新已部分寫入，錯誤會回傳目標 ID 供後台核對。

[ACF update_field](https://www.advancedcustomfields.com/resources/update_field/) · [Pods save](https://docs.pods.io/code/pods/save/)

## 升級與舊 API

- 六個舊 AI 子頁開關控制後台入口，原本的工具端點由主連接器載入。新版把六頁搬進主入口，舊書籤導向對應分頁。既有子頁選項保留在資料庫。
- 保留金鑰、IP 白名單、發佈設定、操作紀錄和原有專用工具。舊金鑰無需重建。首次載入會在舊金鑰登錄站點管理員帳號作為內容操作者，優先使用對應站點管理員電子郵件且具權限的本站使用者，否則選用最早的本站管理員。舊金鑰只保留文章的內容範圍。新增頁面或 CPT 範圍時，請一併設定站點與金鑰。
- 舊 `/posts`、單篇文章、FAQ、SEO、精選圖片路由及 MCP 工具名稱仍可使用原本的文章參數和成功回傳。以其他類型的 ID 呼叫舊文章路由會拒絕。所有內容操作使用原始呼叫金鑰判定授權。
- 一般內容請使用 `list_content_types`、`describe_content_type`、`list_content`、`get_content`、`create_content`、`update_content`。類型與 ID 放在 REST 路徑，JSON 回應欄位及 MCP 工具參數可由工具定義取得。
- 金鑰應只經認證標頭或平台的秘密憑證欄位傳送。操作紀錄儲存不可還原的縮短識別碼、結果及錯誤代碼。站點端點測試若碰到自己的 IP 限制，請查核來源 IP 或主機回連政策。

## 測試

在啟用 AI 連接器的暫用 WordPress 測試站執行 `php tests/site-ai-connector-wordpress.php /path/to/wordpress`。可在未啟用及已啟用 WooCommerce 的測試站各執行一次。
