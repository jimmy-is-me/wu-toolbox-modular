<?php
/**
 * Module: site-ai-connector
 * Site AI Connector; loaded only when enabled in WU Toolbox.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
// The standalone connector owns these names and endpoints if it is installed.
if ( defined( 'SAC_NAMESPACE' ) ) {
    return;
}

/**
 * ============================================================
 * 0. 常數
 * ============================================================
 */
define( 'SAC_NAMESPACE', 'site-ai/v1' );
define( 'SAC_LOG_OPTION', 'sac_operation_log' );
define( 'SAC_OPTION_IP_ALLOWLIST', 'sac_ip_allowlist' );
define( 'SAC_OPTION_ALLOW_PUBLISH', 'sac_ai_allow_publish' );
define( 'SAC_OPTION_ALLOW_COUPON_PUBLISH', 'sac_ai_allow_coupon_publish' );
define( 'SAC_FORM_CPT', 'sac_form_entry' );
define( 'SAC_CACHE_TTL', 60 ); // 唯讀查詢快取秒數，降低重複對話造成的資料庫負擔
define( 'SAC_OPTION_REDIRECTS', 'sac_redirects' );
define( 'SAC_LOG_MAX', 200 );
foreach ( [ 'oauth-store', 'oauth-server', 'oauth-tokens', 'oauth-authorize', 'oauth-permissions',
    'confirmation', 'mcp-server', 'media', 'seo', 'content', 'lab-adapter', 'connection' ] as $component ) {
    require_once __DIR__ . '/' . $component . '.php';
}
if ( is_admin() ) require_once __DIR__ . '/admin.php';

add_action( 'init', function () {
    register_post_type( SAC_FORM_CPT, [
        'labels'          => [ 'name' => 'AI 表單送件', 'singular_name' => '表單送件' ],
        'public'          => false,
        'show_ui'         => true,
        'show_in_menu'    => false,
        'capability_type' => 'post',
        'capabilities'    => [ 'create_posts' => 'do_not_allow' ],
        'map_meta_cap'    => true,
        'supports'        => [ 'title', 'custom-fields' ],
    ] );
} );

/**
 * ============================================================
 * 1. 後台選單
 * ============================================================
 */
add_action( 'admin_menu', function () {
    add_submenu_page( 'wu-toolbox-modular', 'AI 連接器設定', 'AI 連接器', 'manage_options', 'site-ai-connector', 'sac_render_admin_page' );
} );

/**
 * ============================================================
 * 2. MCP 與 REST 共用工具定義
 * ============================================================
 */
function sac_get_tool_definitions() {
    $has_wc = class_exists( 'WooCommerce' );

    return array_merge( sac_content_tool_definitions(), sac_connection_tool_definitions(), array_map( static function ( $tool ) {
        return $tool + [ 'available' => true, 'group' => '圖片上傳', 'scenario' => $tool['summary'], 'prompt' => $tool['description'] ];
    }, sac_media_definitions() ), [
        [
            'name' => 'list_posts', 'group' => '文章與 FAQ', 'method' => 'GET', 'path' => '/posts',
            'summary' => '列出所有文章及狀態', 'description' => '列出文章（含草稿），只回傳 ID、標題、狀態、修改時間，不含內文，查詢速度快。',
            'scenario' => '想快速看整體文章清單與狀態，不需要載入內文', 'prompt' => '列出最近 10 篇草稿文章，給我標題和文章編號',
            'need_write' => false, 'available' => true,
            'params' => [
                'status' => [ 'type' => 'string', 'in' => 'query', 'description' => '文章狀態，例如 publish、draft、any' ],
                'search' => [ 'type' => 'string', 'in' => 'query', 'description' => '關鍵字搜尋' ],
                'limit'  => [ 'type' => 'integer', 'in' => 'query', 'description' => '回傳數量上限，預設 10，最大 50' ],
            ],
        ],
        [
            'name' => 'get_post', 'group' => '文章與 FAQ', 'method' => 'GET', 'path' => '/posts/{post_id}',
            'summary' => '讀取單篇文章正文', 'description' => '讀取指定文章 ID 的標題、正文、狀態。',
            'scenario' => '要改文章標題或內容，先確認 ID 與現況再交辦', 'prompt' => '幫我看文章 123 的完整內容，先不要修改',
            'need_write' => false, 'available' => true,
            'params' => [ 'post_id' => [ 'type' => 'integer', 'in' => 'path', 'required' => true ] ],
        ],
        [
            'name' => 'update_post', 'group' => '文章與 FAQ', 'method' => 'POST', 'path' => '/posts/{post_id}',
            'summary' => '修改文章與 SEO', 'description' => '修改文章標題、正文、摘要或 SEO 欄位。生成內容須符合搜尋意圖、以自然語句呈現主要關鍵字、使用清楚的小標題及原創資訊；勿堆砌關鍵字或編造事實。既有欄位只更新有帶入的項目（需OAuth 寫入授權）。',
            'scenario' => '確認現況後要更新內容或發佈草稿', 'prompt' => '把文章 123 的標題改成「中秋送禮推薦」，內文先不要動',
            'need_write' => true, 'available' => true,
            'params' => [
                'post_id' => [ 'type' => 'integer', 'in' => 'path', 'required' => true ],
                'title'   => [ 'type' => 'string', 'in' => 'body' ],
                'content' => [ 'type' => 'string', 'in' => 'body' ],
                'excerpt' => [ 'type' => 'string', 'in' => 'body', 'description' => '供列表與無 SEO 外掛時使用的自然摘要' ],
                'slug' => [ 'type' => 'string', 'in' => 'body', 'description' => '簡短、可讀且與主題相關的網址代稱' ],
                'seo_title' => [ 'type' => 'string', 'in' => 'body', 'description' => '獨特且自然的搜尋標題，避免關鍵字堆砌' ],
                'seo_description' => [ 'type' => 'string', 'in' => 'body', 'description' => '簡明描述讀者可獲得的資訊，不要保證排名' ],
                'focus_keyword' => [ 'type' => 'string', 'in' => 'body', 'description' => '符合讀者搜尋意圖的主要關鍵字' ],
                'status'  => [ 'type' => 'string', 'in' => 'body', 'description' => 'publish 或 draft，不填則維持原狀態' ],
            ],
        ],
        [
            'name' => 'create_post', 'group' => '文章與 FAQ', 'method' => 'POST', 'path' => '/posts',
            'summary' => '新增 SEO 友善文章草稿', 'description' => '建立文章時先確認搜尋意圖與目標讀者；撰寫原創、準確且有用的內容，以自然方式使用主要關鍵字、清楚的小標題與具體資訊，同時提供摘要與獨特 SEO 標題／描述。勿堆砌關鍵字、編造資訊或保證排名；預設草稿（需OAuth 寫入授權）。',
            'scenario' => '想請 AI 先擬一篇兼顧讀者與 SEO 的文章草稿，之後自己審核發佈', 'prompt' => '先確認「中秋節送禮推薦」的搜尋意圖，再寫原創繁體中文文章，包含清楚小標題、自然摘要與獨特 SEO 標題／描述；不要堆砌關鍵字，先存草稿讓我審核',
            'need_write' => true, 'available' => true,
            'params' => [
                'title'   => [ 'type' => 'string', 'in' => 'body', 'required' => true ],
                'content' => [ 'type' => 'string', 'in' => 'body' ],
                'excerpt' => [ 'type' => 'string', 'in' => 'body', 'description' => '摘要；未提供時由正文擷取，供列表及無 SEO 外掛時使用' ],
                'slug' => [ 'type' => 'string', 'in' => 'body', 'description' => '簡短、可讀且與主題相關的網址代稱' ],
                'seo_title' => [ 'type' => 'string', 'in' => 'body', 'description' => '獨特且自然的搜尋標題；未提供時使用文章標題' ],
                'seo_description' => [ 'type' => 'string', 'in' => 'body', 'description' => '搜尋摘要；未提供時使用文章摘要' ],
                'focus_keyword' => [ 'type' => 'string', 'in' => 'body', 'description' => '符合讀者搜尋意圖的主要關鍵字' ],
                'status'  => [ 'type' => 'string', 'in' => 'body', 'description' => '預設 draft；publish 需先由管理員啟用 AI 直接發布' ],
            ],
        ],
        [
            'name' => 'get_post_faq', 'group' => '文章與 FAQ', 'method' => 'GET', 'path' => '/posts/{post_id}/faq',
            'summary' => '查看現有 FAQ', 'description' => '讀取指定文章目前的 FAQ 問答清單。',
            'scenario' => '修改前先確認目前有哪些題目，避免覆蓋掉不該動的內容', 'prompt' => '文章 123 現在有哪些常見問題？先列出來，不要修改',
            'need_write' => false, 'available' => true,
            'params' => [ 'post_id' => [ 'type' => 'integer', 'in' => 'path', 'required' => true ] ],
        ],
        [
            'name' => 'update_post_faq', 'group' => '文章與 FAQ', 'method' => 'POST', 'path' => '/posts/{post_id}/faq',
            'summary' => '整組覆蓋 FAQ', 'description' => '整組覆蓋指定文章的 FAQ，會自動同步 FAQPage 結構化資料（需先讀取現況，再回傳完整陣列，需OAuth 寫入授權）。',
            'scenario' => '想幫文章補上常見問題，並自動產生結構化資料', 'prompt' => '幫文章 123 整理 3 題讀者常問的問題，先讀取原有 FAQ，保留舊題後再更新',
            'need_write' => true, 'available' => true,
            'params' => [
                'post_id' => [ 'type' => 'integer', 'in' => 'path', 'required' => true ],
                'faqs'    => [ 'type' => 'array', 'in' => 'body', 'required' => true, 'description' => '完整問答陣列，每項含 question 與 answer', 'items' => [ 'type' => 'object', 'properties' => [ 'question' => [ 'type' => 'string' ], 'answer' => [ 'type' => 'string' ] ], 'required' => [ 'question', 'answer' ] ] ],
            ],
        ],
        [
            'name' => 'get_post_seo', 'group' => 'SEO', 'method' => 'GET', 'path' => '/posts/{post_id}/seo',
            'summary' => '檢查 SEO 設定', 'description' => '讀取指定文章的 SEO 標題、描述、Canonical、社群分享與索引設定。啟用 SEO 核心時，直接讀取 SEO 核心欄位。',
            'scenario' => '想知道某篇文章的搜尋標題、描述、焦點關鍵字', 'prompt' => '文章 123 的 SEO 標題和描述目前寫什麼？',
            'need_write' => false, 'available' => true,
            'params' => [ 'post_id' => [ 'type' => 'integer', 'in' => 'path', 'required' => true ] ],
        ],
        [
            'name' => 'update_post_seo', 'group' => 'SEO', 'method' => 'POST', 'path' => '/posts/{post_id}/seo',
            'summary' => '修改 SEO 核心欄位', 'description' => '修改指定文章的 SEO 標題、描述、Canonical、社群分享與索引設定；啟用 SEO 核心時會直接寫入 SEO 核心欄位（需OAuth 寫入授權）。',
            'scenario' => '要更新搜尋標題或描述，提高點擊率', 'prompt' => '幫文章 123 寫一段適合台灣讀者的 SEO 描述，先讓我看內容，再更新',
            'need_write' => true, 'available' => true,
            'params' => [
                'post_id'       => [ 'type' => 'integer', 'in' => 'path', 'required' => true ],
                'title'         => [ 'type' => 'string', 'in' => 'body' ],
                'description'   => [ 'type' => 'string', 'in' => 'body' ],
                'focus_keyword' => [ 'type' => 'string', 'in' => 'body' ],
                'canonical'     => [ 'type' => 'string', 'in' => 'body', 'description' => '正式網址；一般留空即可' ],
                'noindex'       => [ 'type' => 'boolean', 'in' => 'body', 'description' => '設為 true 時不收錄於搜尋結果' ],
                'nofollow'      => [ 'type' => 'boolean', 'in' => 'body', 'description' => '設為 true 時不追蹤頁面連結' ],
                'og_title'      => [ 'type' => 'string', 'in' => 'body', 'description' => '社群分享標題；留空時使用 SEO 標題' ],
                'og_description'=> [ 'type' => 'string', 'in' => 'body', 'description' => '社群分享描述；留空時使用 SEO 描述' ],
                'og_image_id'   => [ 'type' => 'integer', 'in' => 'body', 'description' => '社群分享圖片媒體 ID' ],
            ],
        ],
        [
            'name' => 'find_posts_missing_seo', 'group' => 'SEO', 'method' => 'GET', 'path' => '/seo/missing',
            'summary' => '找出缺少 SEO 描述的文章', 'description' => '掃描已發佈文章，列出尚未設定 SEO 描述的文章 ID 與標題（僅查詢輕量欄位，不載入全文）。',
            'scenario' => '想整批盤點哪些文章還沒補 SEO', 'prompt' => '列出目前已發佈、但還沒填 SEO 描述的文章',
            'need_write' => false, 'available' => true,
            'params' => [ 'limit' => [ 'type' => 'integer', 'in' => 'query', 'description' => '最多回傳筆數，預設 20' ] ],
        ],
        [
            'name' => 'create_upload_link', 'group' => '圖片上傳', 'method' => 'POST', 'path' => '/upload-link',
            'summary' => '產生一次性上傳連結', 'description' => '產生一次性圖片上傳連結，供手機或其他裝置上傳圖片，有時效與張數限制（需OAuth 寫入授權）。',
            'scenario' => '圖片只在手機裡，想直接讓 AI 幫忙處理不用先傳到電腦', 'prompt' => '我要用手機上傳商品照片，請給我一個 30 分鐘有效的上傳連結',
            'need_write' => true, 'available' => true,
            'params' => [
            ],
        ],
        [
            'name' => 'check_upload_link', 'group' => '圖片上傳', 'method' => 'GET', 'path' => '/upload-link/{token}',
            'summary' => '查詢上傳連結目前狀態', 'description' => '查詢一次性上傳連結目前已上傳的媒體 ID 清單。',
            'scenario' => '上傳完想知道拿到的媒體編號，接著設定精選圖片', 'prompt' => '我剛才用手機上傳的照片到了嗎？請告訴我媒體編號',
            'need_write' => false, 'available' => true,
            'params' => [ 'token' => [ 'type' => 'string', 'in' => 'path', 'required' => true ] ],
        ],
        [
            'name' => 'set_featured_image', 'group' => '圖片上傳', 'method' => 'POST', 'path' => '/posts/{post_id}/featured-image',
            'summary' => '設定精選圖片', 'description' => '將指定媒體 ID 設為文章精選圖片（需OAuth 寫入授權）。',
            'scenario' => '圖片上傳完成後，指定要用在哪一篇文章', 'prompt' => '把剛上傳的照片設為文章 123 的精選圖片',
            'need_write' => true, 'available' => true,
            'params' => [
                'post_id'  => [ 'type' => 'integer', 'in' => 'path', 'required' => true ],
                'media_id' => [ 'type' => 'integer', 'in' => 'body', 'required' => true ],
            ],
        ],
        [
            'name' => 'list_media', 'group' => '圖片上傳', 'method' => 'GET', 'path' => '/media',
            'summary' => '搜尋媒體庫與缺少替代文字的圖片', 'description' => '依關鍵字搜尋媒體庫圖片，或只列出替代文字為空的圖片；回傳媒體 ID、標題、檔名、替代文字、所屬內容與網址。',
            'scenario' => '搜尋現有圖片，或盤點哪些圖片尚未設定替代文字', 'prompt' => '請使用 list_media，設定 missing_alt=true、limit=20，列出缺少替代文字的圖片 ID、檔名與所屬內容；先不要修改。',
            'need_write' => false, 'available' => true,
            'params' => [
                'search' => [ 'type' => 'string', 'in' => 'query', 'description' => '關鍵字搜尋（比對檔名與標題）' ],
                'limit'  => [ 'type' => 'integer', 'in' => 'query', 'description' => '回傳數量上限，預設 10，最大 50' ],
                'missing_alt' => [ 'type' => 'boolean', 'in' => 'query', 'description' => 'true 時只回傳替代文字為空的圖片' ],
            ],
        ],
        [
            'name' => 'update_media_alt', 'group' => '圖片上傳', 'method' => 'POST', 'path' => '/media/{media_id}/alt',
            'summary' => '更新圖片替代文字', 'description' => '更新指定圖片的替代文字；寫入前應先用 list_media 核對媒體 ID、檔名與所屬文章或商品（需OAuth 寫入授權）。',
            'scenario' => '為缺少替代文字的圖片補上精確、可讀且符合 SEO 的描述', 'prompt' => '請先使用 list_media 取得 missing_alt=true 的前 20 張圖片，列出媒體 ID、檔名、所屬內容與建議的繁體中文替代文字（20 字內）；等我確認後，才逐筆使用 update_media_alt 寫入，最後回報成功、失敗筆數與原因。',
            'need_write' => true, 'available' => true,
            'params' => [
                'media_id' => [ 'type' => 'integer', 'in' => 'path', 'required' => true ],
                'alt_text' => [ 'type' => 'string', 'in' => 'body', 'required' => true, 'description' => '精確描述圖片內容的繁體中文替代文字，建議 20 字內' ],
            ],
        ],
        [
            'name' => 'list_menu', 'group' => '選單管理', 'method' => 'GET', 'path' => '/menu',
            'summary' => '列出導覽選單項目', 'description' => '列出網站主選單目前的項目、順序與網址。',
            'scenario' => '想調整選單前先確認現況與項目編號', 'prompt' => '列出網站目前的主選單，我想確認有哪些連結',
            'need_write' => false, 'available' => true,
            'params' => [ 'menu_name' => [ 'type' => 'string', 'in' => 'query', 'description' => '選單名稱，留空則用第一個選單' ] ],
        ],
        [
            'name' => 'update_menu_item', 'group' => '選單管理', 'method' => 'POST', 'path' => '/menu/{item_id}',
            'summary' => '修改選單項目文字或網址', 'description' => '更新既有選單項目的文字或自訂網址，保留原項目編號（需OAuth 寫入授權）。',
            'scenario' => '小幅調整選單文字或連結，不想重建整個選單', 'prompt' => '先列出主選單，再把「聯絡」那一項改成「聯絡我們」',
            'need_write' => true, 'available' => true,
            'params' => [
                'item_id' => [ 'type' => 'integer', 'in' => 'path', 'required' => true ],
                'title'   => [ 'type' => 'string', 'in' => 'body' ],
                'url'     => [ 'type' => 'string', 'in' => 'body', 'description' => '僅自訂連結項目可調整網址' ],
            ],
        ],
        [
            'name' => 'list_products', 'group' => 'WooCommerce 商品', 'method' => 'GET', 'path' => '/wc/products',
            'summary' => '列出商品含價格與庫存', 'description' => '列出 WooCommerce 商品，可用關鍵字、分類、狀態篩選，回傳商品 ID、名稱、價格、庫存、狀態。',
            'scenario' => '想先確認目前有哪些商品與庫存價格，再決定要新增或調整哪一筆', 'prompt' => '列出最近 10 個商品的售價和庫存，我想檢查哪些需要補貨',
            'need_write' => false, 'available' => $has_wc,
            'params' => [
                'search'   => [ 'type' => 'string', 'in' => 'query', 'description' => '關鍵字搜尋商品名稱' ],
                'category' => [ 'type' => 'string', 'in' => 'query', 'description' => '商品分類名稱或 slug' ],
                'status'   => [ 'type' => 'string', 'in' => 'query', 'description' => 'publish、draft、any，預設 any' ],
                'limit'    => [ 'type' => 'integer', 'in' => 'query', 'description' => '回傳數量上限，預設 10，最大 50' ],
            ],
        ],
        [
            'name' => 'get_product', 'group' => 'WooCommerce 商品', 'method' => 'GET', 'path' => '/wc/products/{product_id}',
            'summary' => '讀取單一商品詳情', 'description' => '讀取指定商品 ID 的名稱、說明、價格、庫存、狀態、分類、圖片。',
            'scenario' => '要修改商品前先確認目前完整資料', 'prompt' => '商品 88 現在賣多少錢？還有多少庫存？',
            'need_write' => false, 'available' => $has_wc,
            'params' => [ 'product_id' => [ 'type' => 'integer', 'in' => 'path', 'required' => true ] ],
        ],
        [
            'name' => 'create_product', 'group' => 'WooCommerce 商品', 'method' => 'POST', 'path' => '/wc/products',
            'summary' => '新增 SEO 友善商品', 'description' => '建立 WooCommerce 商品時先確認真實規格與適用情境，撰寫獨特、清楚且可掃讀的商品說明與簡短說明，自然使用主要關鍵字，提供 SEO 標題、描述及圖片替代文字；勿捏造功效或堆砌關鍵字。預設草稿（需OAuth 寫入授權）。',
            'scenario' => '想請 AI 幫忙快速建立一筆新商品，之後自己審核上架', 'prompt' => '幫我新增「中秋綜合禮盒」，售價 880 元、庫存 20 盒，先存成草稿',
            'need_write' => true, 'available' => $has_wc,
            'params' => [
                'name'              => [ 'type' => 'string', 'in' => 'body', 'required' => true, 'description' => '商品名稱' ],
                'description'       => [ 'type' => 'string', 'in' => 'body', 'description' => '完整說明（支援 HTML）' ],
                'short_description' => [ 'type' => 'string', 'in' => 'body', 'description' => '簡短說明' ],
                'slug'              => [ 'type' => 'string', 'in' => 'body', 'description' => '簡短、與商品相關的網址代稱' ],
                'seo_title'         => [ 'type' => 'string', 'in' => 'body', 'description' => '獨特且自然的商品搜尋標題' ],
                'seo_description'   => [ 'type' => 'string', 'in' => 'body', 'description' => '準確描述商品特點與適用情境的搜尋摘要' ],
                'focus_keyword'     => [ 'type' => 'string', 'in' => 'body', 'description' => '主要商品搜尋關鍵字' ],
                'image_alt'         => [ 'type' => 'string', 'in' => 'body', 'description' => '主圖的真實、具體替代文字；僅當同時提供 image_id 時使用' ],
                'regular_price'     => [ 'type' => 'string', 'in' => 'body', 'description' => '原價，例如 1000' ],
                'sale_price'        => [ 'type' => 'string', 'in' => 'body', 'description' => '特價（選填）' ],
                'stock_quantity'    => [ 'type' => 'integer', 'in' => 'body', 'description' => '庫存數量' ],
                'manage_stock'      => [ 'type' => 'boolean', 'in' => 'body', 'description' => '是否啟用庫存管理，預設 true' ],
                'status'            => [ 'type' => 'string', 'in' => 'body', 'description' => '預設 draft，可填 publish' ],
                'categories'        => [ 'type' => 'array', 'in' => 'body', 'description' => '分類名稱陣列，不存在會自動建立' ],
                'image_id'          => [ 'type' => 'integer', 'in' => 'body', 'description' => '媒體庫圖片 ID，設為商品主圖' ],
            ],
        ],
        [
            'name' => 'update_product', 'group' => 'WooCommerce 商品', 'method' => 'POST', 'path' => '/wc/products/{product_id}',
            'summary' => '修改商品與 SEO', 'description' => '修改商品資料與 SEO 欄位；若重寫商品內容，須以真實規格、買家疑問與自然語言為核心，避免重複或誇大。只更新有帶入的欄位（需OAuth 寫入授權）。',
            'scenario' => '確認現況後要調整價格、補貨或上下架', 'prompt' => '商品 88 補貨 20 件了，請先確認現有資料，再把庫存設為 20',
            'need_write' => true, 'available' => $has_wc,
            'params' => [
                'product_id'        => [ 'type' => 'integer', 'in' => 'path', 'required' => true ],
                'name'              => [ 'type' => 'string', 'in' => 'body' ],
                'description'       => [ 'type' => 'string', 'in' => 'body' ],
                'short_description' => [ 'type' => 'string', 'in' => 'body' ],
                'slug'              => [ 'type' => 'string', 'in' => 'body' ],
                'seo_title'         => [ 'type' => 'string', 'in' => 'body' ],
                'seo_description'   => [ 'type' => 'string', 'in' => 'body' ],
                'focus_keyword'     => [ 'type' => 'string', 'in' => 'body' ],
                'image_alt'         => [ 'type' => 'string', 'in' => 'body', 'description' => '主圖替代文字；須同時提供 image_id' ],
                'regular_price'     => [ 'type' => 'string', 'in' => 'body' ],
                'sale_price'        => [ 'type' => 'string', 'in' => 'body' ],
                'stock_quantity'    => [ 'type' => 'integer', 'in' => 'body' ],
                'status'            => [ 'type' => 'string', 'in' => 'body', 'description' => 'publish、draft 或 private' ],
                'categories'        => [ 'type' => 'array', 'in' => 'body' ],
                'image_id'          => [ 'type' => 'integer', 'in' => 'body' ],
            ],
        ],
        [
            'name' => 'delete_product', 'group' => 'WooCommerce 商品', 'method' => 'POST', 'path' => '/wc/products/{product_id}/delete',
            'summary' => '刪除商品（移入垃圾桶）', 'description' => '將指定商品移入垃圾桶，可還原，不會直接永久刪除（需OAuth 寫入授權）。',
            'scenario' => '商品下架不再銷售，想從商品列表移除', 'prompt' => '商品 88 不賣了，先確認商品名稱，再移到垃圾桶',
            'need_write' => true, 'available' => $has_wc,
            'params' => [ 'product_id' => [ 'type' => 'integer', 'in' => 'path', 'required' => true ] ],
        ],
        [
            'name' => 'set_product_stock', 'group' => 'WooCommerce 商品', 'method' => 'POST', 'path' => '/wc/products/{product_id}/stock',
            'summary' => '快速調整庫存', 'description' => '單獨調整指定商品的庫存數量與庫存狀態，不影響其他欄位（需OAuth 寫入授權）。',
            'scenario' => '盤點後要快速更新單一商品庫存，不想動到其他資料', 'prompt' => '商品 88 已售完，請把庫存設為 0',
            'need_write' => true, 'available' => $has_wc,
            'params' => [
                'product_id'     => [ 'type' => 'integer', 'in' => 'path', 'required' => true ],
                'stock_quantity' => [ 'type' => 'integer', 'in' => 'body', 'required' => true ],
                'stock_status'   => [ 'type' => 'string', 'in' => 'body', 'description' => 'instock、outofstock 或 onbackorder，不填則依數量自動判斷' ],
            ],
        ],
        [
            'name' => 'list_product_categories', 'group' => 'WooCommerce 商品', 'method' => 'GET', 'path' => '/wc/product-categories',
            'summary' => '列出商品分類', 'description' => '列出目前所有商品分類名稱、slug 與商品數量。',
            'scenario' => '新增商品前想知道有哪些分類可以套用', 'prompt' => '網站目前有哪些商品分類？',
            'need_write' => false, 'available' => $has_wc,
            'params' => [],
        ],
        [
            'name' => 'get_orders_summary', 'group' => '營運資料', 'method' => 'GET', 'path' => '/wc/orders-summary',
            'summary' => '查看訂單摘要', 'description' => 'WooCommerce 訂單摘要，個資預設遮蔽，需 reveal_pii=true 才顯示完整資料。',
            'scenario' => '每天早上想快速掌握訂單狀況', 'prompt' => '整理目前待處理的訂單摘要，先遮蔽客戶個資',
            'need_write' => false, 'available' => $has_wc,
            'params' => [
                'status'     => [ 'type' => 'string', 'in' => 'query', 'description' => '訂單狀態，例如 processing、completed' ],
                'reveal_pii' => [ 'type' => 'boolean', 'in' => 'query', 'description' => '是否顯示完整個資，預設否' ],
            ],
        ],
        [
            'name' => 'get_low_stock_products', 'group' => '營運資料', 'method' => 'GET', 'path' => '/wc/low-stock',
            'summary' => '查看低庫存商品', 'description' => '取得低庫存商品清單。',
            'scenario' => '想知道哪些商品快要缺貨，提早補貨', 'prompt' => '哪些商品庫存剩 5 件以下？請列出商品名稱和數量',
            'need_write' => false, 'available' => $has_wc,
            'params' => [ 'threshold' => [ 'type' => 'integer', 'in' => 'query', 'description' => '庫存門檻，預設 5' ] ],
        ],
        [
            'name' => 'get_revenue_report', 'group' => '營運資料', 'method' => 'GET', 'path' => '/wc/revenue',
            'summary' => '查看區間營收', 'description' => '統計指定天數內已完成訂單的總營收與筆數。',
            'scenario' => '想快速掌握這週或這個月的銷售表現', 'prompt' => '幫我看最近 7 天的訂單數和營收總額',
            'need_write' => false, 'available' => $has_wc,
            'params' => [ 'days' => [ 'type' => 'integer', 'in' => 'query', 'description' => '回溯天數，預設 7' ] ],
        ],
        [
            'name' => 'get_unread_form_submissions', 'group' => '表單送件', 'method' => 'GET', 'path' => '/form-submissions/unread',
            'summary' => '查看未讀表單送件', 'description' => '列出未讀的聯絡表單送件，個資預設遮蔽。',
            'scenario' => '想知道有沒有新的聯絡表單還沒處理', 'prompt' => '有新的聯絡表單嗎？列出未讀送件，客戶資料先遮蔽',
            'need_write' => false, 'available' => true,
            'params' => [ 'reveal_pii' => [ 'type' => 'boolean', 'in' => 'query' ] ],
        ],
        [
            'name' => 'mark_form_submission_read', 'group' => '表單送件', 'method' => 'POST', 'path' => '/form-submissions/{entry_id}/mark-read',
            'summary' => '標記表單送件為已讀', 'description' => '把指定表單送件標記為已處理（需OAuth 寫入授權）。',
            'scenario' => '處理完一筆表單後標記，避免重複查看', 'prompt' => '表單送件 45 已經處理完，幫我標記已讀',
            'need_write' => true, 'available' => true,
            'params' => [ 'entry_id' => [ 'type' => 'integer', 'in' => 'path', 'required' => true ] ],
        ],
        [
            'name' => 'list_redirects', 'group' => '轉址管理', 'method' => 'GET', 'path' => '/redirects',
            'summary' => '列出轉址規則', 'description' => '列出本外掛管理的轉址規則。',
            'scenario' => '確認舊網址的導向設定', 'prompt' => '列出網站目前所有舊網址轉址設定',
            'need_write' => false, 'available' => sac_feature_enabled( 'redirects' ), 'params' => [],
        ],
        [
            'name' => 'create_redirect', 'group' => '轉址管理', 'method' => 'POST', 'path' => '/redirects',
            'summary' => '新增轉址規則', 'description' => '建立站內舊路徑到目標網址的 301 或 302 轉址。',
            'scenario' => '文章網址更改後避免舊網址顯示 404', 'prompt' => '舊文章網址 /old-post 已改成 /new-post，請設定 301 轉址',
            'need_write' => true, 'available' => sac_feature_enabled( 'redirects' ),
            'params' => [
                'source_path' => [ 'type' => 'string', 'in' => 'body', 'required' => true, 'description' => '站內舊路徑，例如 /old-page' ],
                'target_url' => [ 'type' => 'string', 'in' => 'body', 'required' => true, 'description' => '站內路徑或 http/https 網址' ],
                'status_code' => [ 'type' => 'integer', 'in' => 'body', 'description' => '301 或 302，預設 301' ],
            ],
        ],
        [
            'name' => 'delete_redirect', 'group' => '轉址管理', 'method' => 'POST', 'path' => '/redirects/{redirect_id}/delete',
            'summary' => '刪除轉址規則', 'description' => '依規則 ID 刪除轉址。',
            'scenario' => '移除不再需要的導向', 'prompt' => '先列出轉址規則，再刪除我指定的那一筆',
            'need_write' => true, 'available' => sac_feature_enabled( 'redirects' ),
            'params' => [ 'redirect_id' => [ 'type' => 'string', 'in' => 'path', 'required' => true ] ],
        ],
        [
            'name' => 'list_coupons', 'group' => '優惠券管理', 'method' => 'GET', 'path' => '/wc/coupons',
            'summary' => '列出優惠券', 'description' => '列出已啟用及草稿優惠券的代碼、狀態、折扣與使用狀況。',
            'scenario' => '檢查目前優惠券', 'prompt' => '列出目前的優惠券代碼、折扣金額和到期日',
            'need_write' => false, 'available' => $has_wc && sac_feature_enabled( 'coupons' ),
            'params' => [ 'limit' => [ 'type' => 'integer', 'in' => 'query', 'description' => '1 至 100，預設 20' ] ],
        ],
        [
            'name' => 'create_coupon', 'group' => '優惠券管理', 'method' => 'POST', 'path' => '/wc/coupons',
            'summary' => '新增優惠券', 'description' => '新增 WooCommerce 優惠券；預設存為草稿，僅管理員開啟 AI 優惠券直接生效權限後才會立即啟用。',
            'scenario' => '建立活動折扣碼', 'prompt' => '建立優惠券 MID100，訂單滿 1000 元折 100 元，總共限用 50 次',
            'need_write' => true, 'available' => $has_wc && sac_feature_enabled( 'coupons' ),
            'params' => [
                'code' => [ 'type' => 'string', 'in' => 'body', 'required' => true ],
                'discount_type' => [ 'type' => 'string', 'in' => 'body', 'description' => 'percent、fixed_cart 或 fixed_product' ],
                'amount' => [ 'type' => 'string', 'in' => 'body', 'required' => true ],
                'expiry_date' => [ 'type' => 'string', 'in' => 'body', 'description' => 'YYYY-MM-DD' ],
                'usage_limit' => [ 'type' => 'integer', 'in' => 'body' ],
                'min_spend' => [ 'type' => 'string', 'in' => 'body' ],
            ],
        ],
        [
            'name' => 'update_coupon', 'group' => '優惠券管理', 'method' => 'POST', 'path' => '/wc/coupons/{coupon_id}',
            'summary' => '修改優惠券', 'description' => '修改金額、到期日或使用上限。到期日給空字串可清除；修改已啟用的優惠券須先由管理員開啟直接生效權限。',
            'scenario' => '延長活動或調整折扣', 'prompt' => '優惠券 123 的使用上限改成 50 次，其他設定不變',
            'need_write' => true, 'available' => $has_wc && sac_feature_enabled( 'coupons' ),
            'params' => [
                'coupon_id' => [ 'type' => 'integer', 'in' => 'path', 'required' => true ],
                'amount' => [ 'type' => 'string', 'in' => 'body' ],
                'expiry_date' => [ 'type' => 'string', 'in' => 'body' ],
                'usage_limit' => [ 'type' => 'integer', 'in' => 'body' ],
            ],
        ],
        [
            'name' => 'delete_coupon', 'group' => '優惠券管理', 'method' => 'POST', 'path' => '/wc/coupons/{coupon_id}/delete',
            'summary' => '停用優惠券', 'description' => '將優惠券移入垃圾桶，可以在後台還原。',
            'scenario' => '活動結束後停用折扣碼', 'prompt' => '活動結束了，先確認優惠券 123 的代碼，再停用它',
            'need_write' => true, 'available' => $has_wc && sac_feature_enabled( 'coupons' ),
            'params' => [ 'coupon_id' => [ 'type' => 'integer', 'in' => 'path', 'required' => true ] ],
        ],
    ] );
}

/**
 * 後台指令模板集中管理。prompt 給常用指令卡片，tool_prompts 給功能總覽。
 * 所有名稱均使用本連接器實際透過 tools/list 公開的 MCP 工具名稱。
 */
function sac_get_prompt_templates() {
    return [
        'quick' => [
            'create_seo_post' => [
                'label' => '撰寫 SEO 文章草稿',
                'category' => '文章與 FAQ',
                'prompt' => <<<'PROMPT'
請使用本站 AI 連接器的 MCP 工具建立一篇 SEO 友善文章草稿，主題為「中秋節送禮推薦」。

1. 先確認搜尋意圖為資訊比較，目標讀者為台灣正在挑選送禮商品的消費者；若網站已有相近文章，先使用 list_posts（search=中秋送禮、status=any、limit=10）列出文章 ID、標題與狀態，避免內容重複。
2. 以繁體中文規劃原創內容：標題 60 字內、至少 3 個清楚小標題、正文資訊具體且不編造資料；自然使用「中秋送禮推薦」及相關關鍵字，不堆砌關鍵字或保證排名。
3. 同時準備 excerpt（2 至 3 句）、seo_title（60 字內）、seo_description（120 至 160 字）、focus_keyword 與簡短可讀的 slug。
4. 先列出文章架構、標題、摘要與 SEO 欄位給我確認，不要直接寫入。
5. 我確認後才使用 create_post，status=draft 建立草稿；完成後回報文章 ID、預覽資訊，若失敗請回報原因。
PROMPT,
            ],
            'optimize_post_seo' => [
                'label' => '批次補齊文章 SEO',
                'category' => 'SEO',
                'prompt' => <<<'PROMPT'
請使用本站 AI 連接器的 MCP 工具補齊已發佈文章的 SEO 欄位。

1. 先使用 find_posts_missing_seo（limit=10）找出 SEO 描述為空的已發佈文章，列出文章 ID 與標題。
2. 逐篇使用 get_post 與 get_post_seo 讀取正文、目前 SEO 欄位；不要改動已存在且內容合理的欄位。
3. 依文章真實內容產生繁體中文 seo_title（60 字內、含主要關鍵字）、description（120 至 160 字）、focus_keyword 與 excerpt（2 至 3 句），避免誇大與關鍵字堆砌。
4. 每篇先列出「文章 ID／欄位名稱／目前舊值／預計新值」預覽表，包含所有準備寫入的 SEO 欄位與 excerpt；未列出的欄位不得寫入。等我明確確認，不要先執行。
5. 我確認後才逐篇使用 update_post_seo 寫入確認過的欄位；需要補 excerpt 時再使用 update_post。完成後回報每篇實際變更欄位、成功或失敗及原因。
PROMPT,
            ],
            'refresh_old_posts' => [
                'label' => '更新舊文章內容',
                'category' => '文章與 FAQ',
                'prompt' => <<<'PROMPT'
請使用本站 AI 連接器的 MCP 工具更新與「送禮」相關的舊文章。

1. 使用 list_posts（search=送禮、status=publish、limit=10）列出文章 ID、標題與修改時間。
2. 先讓我選擇文章；選定後使用 get_post 與 get_post_seo 讀取完整內容和 SEO 現況。
3. 保留原有正確重點與網址代稱，只提出需要更新、刪除過時資訊或補充讀者疑問的內容；使用繁體中文、清楚小標題與自然關鍵字，不編造年份、價格或規格。
4. 先列出修改摘要及完整草稿供我確認，不要直接寫入。
5. 我確認後才使用 update_post 更新指定欄位；完成後回報文章 ID、實際更新欄位，失敗時說明原因。
PROMPT,
            ],
            'update_faq' => [
                'label' => '補文章常見問題',
                'category' => '文章與 FAQ',
                'prompt' => <<<'PROMPT'
請使用本站 AI 連接器的 MCP 工具為文章 ID 123 補齊 FAQ。

1. 使用 get_post（post_id=123）讀取文章內容，再使用 get_post_faq（post_id=123）取得現有 FAQ。
2. 依文章內容與讀者搜尋意圖產生 3 題繁體中文問答；答案務必能由文章或已知事實支持，不編造資料。
3. 必須保留原有有效題目，整理成 update_post_faq 所需的完整 faqs 陣列，每項包含 question 與 answer。
4. 先列出完整新舊 FAQ 清單給我確認，不要寫入。
5. 我確認後才使用 update_post_faq；完成後回報文章 ID、保留與新增題數，失敗時說明原因。
PROMPT,
            ],
            'optimize_products' => [
                'label' => '批次優化商品 SEO',
                'category' => 'WooCommerce 商品',
                'prompt' => <<<'PROMPT'
請使用本站 AI 連接器的 MCP 工具檢查並優化已發佈商品的 SEO 內容。

1. 使用 list_products（status=publish、limit=15）列出商品 ID、名稱、分類、價格與庫存，再逐筆使用 get_product 讀取說明、簡短說明、圖片與 SEO 現況。
2. 只處理 SEO 標題、SEO 描述或簡短說明缺漏的商品；所有規格、價格與功效必須以現有真實資料為準。
3. 產生繁體中文 seo_title（60 字內）、seo_description（120 至 160 字）、focus_keyword，以及僅在原欄位空白時提供 100 字內 short_description；自然使用商品名稱與核心關鍵字。
4. 每個商品先列出「商品 ID／欄位名稱／目前舊值／預計新值」預覽表，包含所有準備寫入的 SEO 與簡短說明欄位；未列出的欄位不得寫入。等我明確確認，不要先執行。
5. 我確認後才逐筆使用 update_product，且只傳確認過的欄位。完成後回報每個商品實際變更欄位、成功或失敗及原因。
PROMPT,
            ],
            'create_product' => [
                'label' => '建立 SEO 商品草稿',
                'category' => 'WooCommerce 商品',
                'prompt' => <<<'PROMPT'
請使用本站 AI 連接器的 MCP 工具建立「中秋綜合禮盒」商品草稿，售價 880 元、庫存 20 件。

1. 先使用 list_products（search=中秋綜合禮盒、status=any、limit=10）確認沒有重複商品，再使用 list_product_categories 列出可用分類。
2. 依我提供的真實規格撰寫繁體中文 name、description、100 字內 short_description、seo_title（60 字內）、seo_description（120 至 160 字）、focus_keyword 與 slug；不可捏造成分、功效、尺寸或配送承諾。
3. 若需要圖片，先使用 list_media 搜尋現有圖片，並提出 image_id 與 20 字內 image_alt 建議。
4. 先列出準備寫入的全部欄位與分類給我確認，不要建立商品。
5. 我確認後才使用 create_product，status=draft；完成後回報商品 ID、草稿狀態，失敗時回報原因。
PROMPT,
            ],
            'media_alt' => [
                'label' => '批次補圖片替代文字',
                'category' => '圖片上傳',
                'prompt' => <<<'PROMPT'
請使用本站 AI 連接器的 MCP 工具補齊媒體庫圖片替代文字。

1. 使用 list_media（missing_alt=true、limit=20）列出替代文字為空的圖片，包含媒體 ID、檔名、標題、所屬文章或商品。
2. 依檔名、媒體標題及所屬內容判斷圖片用途；資訊不足時標記「需要人工確認」，不要猜測畫面內容。
3. 為可判斷的圖片產生繁體中文 alt_text，20 字內、具描述性，不堆砌關鍵字，也不要使用「圖片」或「照片」等多餘開頭。
4. 先列出「媒體 ID／檔名／所屬內容／建議替代文字」給我確認，不要寫入。
5. 我確認後才逐筆使用 update_media_alt。完成後回報成功、略過、失敗筆數及原因。
PROMPT,
            ],
            'low_stock' => [
                'label' => '低庫存盤點與補貨',
                'category' => '營運資料',
                'prompt' => <<<'PROMPT'
請使用本站 AI 連接器的 MCP 工具盤點低庫存商品。

1. 使用 get_low_stock_products（threshold=5）列出庫存 5 件以下的商品 ID、名稱與目前庫存。
2. 以表格列出結果並標示售罄商品；先不要修改任何庫存。
3. 請我逐筆提供確認後的新庫存數量，不要自行假設補貨數量。
4. 我確認後才逐筆使用 set_product_stock，傳入 product_id、stock_quantity，以及 stock_status（數量大於 0 為 instock，等於 0 為 outofstock）。
5. 完成後回報成功、失敗筆數、更新前後數量與失敗原因。
PROMPT,
            ],
            'coupon_campaign' => [
                'label' => '建立促銷優惠券',
                'category' => '優惠券管理',
                'prompt' => <<<'PROMPT'
請使用本站 AI 連接器的 MCP 工具建立促銷優惠券：代碼 MID100，購物車滿 1000 元折 100 元，總使用上限 50 次。

1. 先使用 list_coupons（limit=100）確認 MID100 尚未存在，並列出任何相似代碼。
2. 準備 create_coupon 參數：code=MID100、discount_type=fixed_cart、amount=100、min_spend=1000、usage_limit=50；若未提供到期日，請明確標示「無到期日」。
3. 先將完整設定列給我確認，不要直接建立。
4. 我確認後才使用 create_coupon；不可自行增加免運、限會員或其他未指定條件。
5. 完成後回報優惠券 ID、代碼與實際設定；失敗時回報原因。
PROMPT,
            ],
        ],
        'tool_prompts' => [
            'list_posts' => '請使用 list_posts，參數 status=draft、limit=10，列出文章 ID、標題、狀態與修改時間；只回傳清單，不要修改。',
            'get_post' => '請使用 get_post，參數 post_id=123，完整列出標題、狀態與正文；不要修改，找不到時回報原因。',
            'update_post' => '先使用 get_post 讀取 post_id=123，列出預計修改欄位給我確認；確認後才使用 update_post，只傳需要變更的欄位，完成後回報結果。',
            'create_post' => '先產生繁體中文文章、excerpt、seo_title、seo_description、focus_keyword 與 slug 給我確認；確認後才使用 create_post，status=draft，並回報文章 ID。',
            'get_post_faq' => '請使用 get_post_faq，參數 post_id=123，列出全部 question 與 answer；只查詢，不要修改。',
            'update_post_faq' => '先使用 get_post_faq 讀取 post_id=123，保留有效舊題並列出完整 faqs 陣列；我確認後才使用 update_post_faq，完成後回報題數。',
            'get_post_seo' => '請使用 get_post_seo，參數 post_id=123，列出 SEO 標題、描述與焦點關鍵字；只查詢，不要修改。',
            'update_post_seo' => '先使用 get_post 與 get_post_seo 讀取 post_id=123。列出每個預計修改的 SEO 欄位名稱、目前舊值與預計新值，請我明確確認；確認前不得寫入。確認後才使用 update_post_seo 且只傳已確認欄位，最後回報實際結果與失敗原因。',
            'find_posts_missing_seo' => '請使用 find_posts_missing_seo，參數 limit=20，列出缺少 SEO 描述的已發佈文章 ID 與標題；只查詢，不要修改。',
            'create_upload_link' => '使用 create_upload_link 取得一張圖片的短效連結，由授權者登入 WordPress 上傳。',
            'check_upload_link' => '請使用 check_upload_link，傳入我提供的 token，列出已上傳媒體 ID 與狀態；不要修改。',
            'set_featured_image' => '先使用 get_post 與 list_media 核對文章和圖片；列出 post_id、media_id 給我確認後才使用 set_featured_image，完成後回報結果。',
            'list_media' => '請使用 list_media，參數 missing_alt=true、limit=20，列出媒體 ID、檔名、替代文字與所屬內容；只查詢，不要修改。',
            'update_media_alt' => '先使用 list_media 核對 media_id 與所屬內容，提出 20 字內繁體中文 alt_text；我確認後才使用 update_media_alt，完成後回報結果。',
            'list_menu' => '請使用 list_menu，先列出主選單項目 ID、文字、網址、順序與父項目；只查詢，不要修改。',
            'update_menu_item' => '先使用 list_menu 找出正確 item_id，列出舊值與新值給我確認；確認後才使用 update_menu_item，完成後回報結果。',
            'list_products' => '請使用 list_products，參數 status=publish、limit=10，列出商品 ID、名稱、價格、庫存與狀態；只查詢，不要修改。',
            'get_product' => '請使用 get_product，參數 product_id=88，列出名稱、說明、價格、庫存、分類、圖片與 SEO 欄位；只查詢，不要修改。',
            'create_product' => '先列出商品全部欄位與 SEO 內容給我確認；確認後才使用 create_product，status=draft，完成後回報商品 ID 與失敗原因。',
            'update_product' => '先使用 get_product 核對 product_id=88 與目前 SEO 欄位，列出每個預計修改的 SEO 欄位名稱、目前舊值與預計新值，請我明確確認；確認前不得寫入。確認後才使用 update_product 且只傳已確認欄位，最後回報實際結果與失敗原因。',
            'delete_product' => '先使用 get_product 核對 product_id=88 的名稱與狀態；我明確確認後才使用 delete_product 移入垃圾桶，完成後回報結果。',
            'set_product_stock' => '先使用 get_product 核對 product_id=88 與目前庫存；我確認新數量後才使用 set_product_stock，完成後回報更新前後數量。',
            'list_product_categories' => '請使用 list_product_categories，列出分類 ID、名稱、slug 與商品數；只查詢，不要修改。',
            'get_orders_summary' => '請使用 get_orders_summary，參數 status=processing、reveal_pii=false，以表格回報訂單摘要並遮蔽個資；不要修改訂單。',
            'get_low_stock_products' => '請使用 get_low_stock_products，參數 threshold=5，列出商品 ID、名稱與庫存；只查詢，不要修改。',
            'get_revenue_report' => '請使用 get_revenue_report，參數 days=7，回報期間、完成訂單數與營收總額；只查詢，不要修改。',
            'get_unread_form_submissions' => '請使用 get_unread_form_submissions，參數 reveal_pii=false，列出未讀送件 ID、日期與遮蔽後聯絡資料；不要標記已讀。',
            'mark_form_submission_read' => '先列出 entry_id=45 的處理確認；我確認後才使用 mark_form_submission_read，完成後回報結果。',
            'list_redirects' => '請使用 list_redirects，列出規則 ID、來源路徑、目標網址與狀態碼；只查詢，不要修改。',
            'create_redirect' => '先使用 list_redirects 確認來源路徑未重複，列出 source_path、target_url、status_code=301 給我確認；確認後才使用 create_redirect。',
            'delete_redirect' => '先使用 list_redirects 核對 redirect_id 與規則內容；我明確確認後才使用 delete_redirect，完成後回報結果。',
            'list_coupons' => '請使用 list_coupons，參數 limit=20，列出優惠券 ID、代碼、類型、金額、到期日與使用狀況；只查詢，不要修改。',
            'create_coupon' => '先使用 list_coupons 確認代碼未重複，列出 create_coupon 的全部設定給我確認；確認後才建立並回報優惠券 ID。',
            'update_coupon' => '先使用 list_coupons 核對 coupon_id=123，列出舊值與新值給我確認；確認後才使用 update_coupon，只傳需要修改的欄位。',
            'delete_coupon' => '先使用 list_coupons 核對 coupon_id=123 與代碼；我明確確認後才使用 delete_coupon 移入垃圾桶，完成後回報結果。',
        ],
    ];
}

function sac_parameter_schema( $parameter ) {
    $schema = array_diff_key( $parameter, [ 'in' => true ] );
    if ( ! is_array( $schema['required'] ?? null ) ) unset( $schema['required'] );
    if ( $schema['type'] === 'array' && ! isset( $schema['items'] ) ) {
        $schema['items'] = [ 'type' => 'string' ];
    }
    return $schema;
}

function sac_check_ip_allowlist() {
    $allowlist = get_option( SAC_OPTION_IP_ALLOWLIST, [ 'enabled' => false, 'ips' => [] ] );
    if ( empty( $allowlist['enabled'] ) ) {
        return true;
    }
    if ( empty( $allowlist['ips'] ) || ! is_array( $allowlist['ips'] ) ) return false;
    // Forwarded headers are client-controlled unless a trusted proxy is configured.
    $remote_ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return in_array( $remote_ip, $allowlist['ips'], true );
}

function sac_checked_publication_status( $value ) {
    if ( $value !== null && ! is_string( $value ) ) return sac_oauth_error( 'sac_invalid_status', '狀態格式錯誤。' );
    $status = $value ?? 'draft';
    if ( ! in_array( $status, [ 'draft', 'pending' ], true ) ) {
        return sac_oauth_error( 'sac_publish_workflow', '請先儲存草稿，並使用獨立發佈流程或 WordPress 編輯器。', 403 );
    }
    return $status;
}

/**
 * ============================================================
 * 3B. 輕量查詢快取：避免 AI 短時間內重複查詢同樣資料反覆打資料庫
 * 僅套用在唯讀（GET）請求，TTL 很短（預設 60 秒），寫入動作不受影響。
 * ============================================================
 */
function sac_cache_id( WP_REST_Request $request, $cache_key ) {
    $grant = sac_authenticate( $request );
    return 'sac_c_' . hash( 'sha256', serialize( [
        get_current_blog_id(), get_option( 'sac_cache_generation', '' ),
        is_wp_error( $grant ) ? [] : $grant, sac_site_content_policy(), $request->get_route(), $cache_key,
    ] ) );
}

function sac_cache_get( WP_REST_Request $request, $cache_key ) {
    return get_transient( sac_cache_id( $request, $cache_key ) );
}

function sac_cache_set( WP_REST_Request $request, $cache_key, $value ) {
    set_transient( sac_cache_id( $request, $cache_key ), $value, SAC_CACHE_TTL );
}

function sac_invalidate_cache() {
    update_option( 'sac_cache_generation', wp_generate_uuid4(), false );
}

/**
 * ============================================================
 * 4. 歷史操作紀錄遷移
 * ============================================================
 */
add_action( 'init', function () {
    if ( get_option( 'sac_log_safe_migration_v316', false ) ) return;
    $logs = get_option( SAC_LOG_OPTION, [] );
    if ( is_array( $logs ) ) {
        foreach ( $logs as &$entry ) {
            if ( ! is_array( $entry ) ) { $entry = []; continue; }
            // Remove historical free-form details and partial API keys permanently.
            unset( $entry['detail'], $entry['target'], $entry['key_prefix'], $entry['display_name'] );
        }
        unset( $entry );
        update_option( SAC_LOG_OPTION, $logs, false );
    }
    update_option( 'sac_log_safe_migration_v316', true, false );
} );

/**
 * ============================================================
 * 6. 相容 REST 路由
 * ============================================================
 */
add_action( 'rest_api_init', function () {

    foreach ( [ 'GET', 'POST' ] as $method ) {
        register_rest_route( SAC_NAMESPACE, '/posts', [
            'methods' => $method, 'permission_callback' => $method === 'GET' ? 'sac_permission_read' : 'sac_permission_write',
            'callback' => 'sac_handle_legacy_content',
        ] );
        foreach ( [ '', '/faq', '/seo' ] as $suffix ) {
            register_rest_route( SAC_NAMESPACE, '/posts/(?P<id>\d+)' . $suffix, [
                'methods' => $method, 'permission_callback' => $method === 'GET' ? 'sac_permission_read' : 'sac_permission_write',
                'callback' => 'sac_handle_legacy_content',
            ] );
        }
    }

    // 效能優化：只查詢 ID 與必要欄位，不載入完整 post 物件與 meta/term cache
    register_rest_route( SAC_NAMESPACE, '/seo/missing', [
        'methods' => 'GET', 'permission_callback' => 'sac_permission_read',
        'callback' => function ( WP_REST_Request $req ) {
            $target = sac_content_legacy_target( $req );
            if ( is_wp_error( $target ) ) return $target;
            $limit = max( 1, min( (int) ( $req->get_param( 'limit' ) ?: 20 ), 100 ) );

            $has_seo_core   = sac_has_wumetax_seo_core();
            $has_seo_plugin = defined( 'WPSEO_VERSION' ) || class_exists( 'RankMath' );
            $query = new WP_Query( [
                'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 100,
                'no_found_rows' => true, 'update_post_term_cache' => false,
                'update_post_meta_cache' => $has_seo_core || $has_seo_plugin,
                'fields' => 'all',
            ] );
            $missing = [];
            foreach ( $query->posts as $p ) {
                if ( ! sac_content_can_access_post( $target['user'], $target['type'], $p, 'read' ) ) continue;
                $desc = $has_seo_core
                    ? get_post_meta( $p->ID, '_wu_seo_description', true )
                    : ( $has_seo_plugin
                        ? ( get_post_meta( $p->ID, '_yoast_wpseo_metadesc', true ) ?: get_post_meta( $p->ID, 'rank_math_description', true ) )
                        : $p->post_excerpt );
                if ( empty( $desc ) ) {
                    $missing[] = [ 'id' => $p->ID, 'title' => get_the_title( $p ) ];
                    if ( count( $missing ) >= $limit ) break;
                }
            }
            return rest_ensure_response( $missing );
        },
    ] );

    register_rest_route( SAC_NAMESPACE, '/upload-link', [
        'methods' => 'POST', 'permission_callback' => 'sac_permission_write', 'callback' => 'sac_media_upload_link',
    ] );
    register_rest_route( SAC_NAMESPACE, '/upload-link/(?P<token>[a-f0-9]{64})', [
        'methods' => 'GET', 'permission_callback' => 'sac_permission_read',
        'callback' => static function ( $request ) {
            $grant = sac_authenticate( $request );
            $row = sac_oauth_row( 'upload', $request->get_url_params()['token'] );
            if ( ! $row || (int) $row['expires'] <= time() || (int) $row['family'] !== $grant['id'] ) {
                return sac_oauth_error( 'sac_not_found', '找不到此授權下的有效連結。', 404 );
            }
            return $row['payload'] ?: [ 'status' => (int) $row['state'] ? 'processing' : 'waiting' ];
        },
    ] );

    register_rest_route( SAC_NAMESPACE, '/posts/(?P<id>\d+)/featured-image', [
        'methods' => 'POST', 'permission_callback' => 'sac_permission_write',
        'callback' => function ( WP_REST_Request $req ) {
            $target = sac_content_legacy_target( $req );
            if ( is_wp_error( $target ) ) return $target;
            $media_id = filter_var( $req->get_param( 'media_id' ), FILTER_VALIDATE_INT, [ 'options' => [ 'min_range' => 1 ] ] );
            $media_id = sac_content_thumbnail( $media_id, $target );
            if ( is_wp_error( $media_id ) ) return $media_id;
            $review = sac_content_review( $req, $target );
            if ( is_wp_error( $review ) ) return $review;
            $post_id = $target['post']->ID;
            if ( (int) get_post_thumbnail_id( $post_id ) !== $media_id && ! set_post_thumbnail( $post_id, $media_id ) ) {
                return sac_content_save_error( $post_id, 'featured_image_id', $target['post'] );
            }
            return rest_ensure_response( [ 'success' => true ] );
        },
    ] );

    register_rest_route( SAC_NAMESPACE, '/media', [
        'methods' => 'GET', 'permission_callback' => 'sac_permission_read',
        'callback' => function ( WP_REST_Request $req ) {
            $limit = min( (int) ( $req->get_param( 'limit' ) ?: 10 ), 50 );
            $search = $req->get_param( 'search' ) ?: '';
            $missing_alt = filter_var( $req->get_param( 'missing_alt' ), FILTER_VALIDATE_BOOLEAN );
            $query_args = [
                'post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'image',
                's' => $search, 'posts_per_page' => $limit, 'orderby' => 'date', 'order' => 'DESC', 'no_found_rows' => true,
            ];
            if ( $missing_alt ) {
                $query_args['meta_query'] = [
                    'relation' => 'OR',
                    [ 'key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS' ],
                    [ 'key' => '_wp_attachment_image_alt', 'value' => '', 'compare' => '=' ],
                ];
            }
            $query = new WP_Query( $query_args );
            $allowed = array_filter( $query->posts, static function ( $post ) { return current_user_can( 'read_post', $post->ID ); } );
            $result = array_values( array_map( function ( $a ) {
                $parent_id = (int) $a->post_parent;
                $file = get_attached_file( $a->ID );
                return [
                    'id' => $a->ID,
                    'title' => get_the_title( $a ),
                    'filename' => $file ? wp_basename( $file ) : '',
                    'alt_text' => (string) get_post_meta( $a->ID, '_wp_attachment_image_alt', true ),
                    'parent_id' => $parent_id,
                    'parent_title' => $parent_id ? get_the_title( $parent_id ) : '',
                    'url' => wp_get_attachment_url( $a->ID ),
                    'thumbnail' => wp_get_attachment_image_url( $a->ID, 'thumbnail' ),
                    'uploaded' => $a->post_date,
                ];
            }, $allowed ) );
            // Media visibility may change independently of the connector.
            return rest_ensure_response( $result );
        },
    ] );

    register_rest_route( SAC_NAMESPACE, '/media/(?P<id>\d+)/alt', [
        'methods' => 'POST', 'permission_callback' => 'sac_permission_write',
        'args' => [ 'alt_text' => [ 'required' => true, 'type' => 'string' ] ],
        'callback' => function ( WP_REST_Request $req ) {
            $media_id = (int) $req->get_url_params()['id'];
            if ( ! current_user_can( 'edit_post', $media_id ) ) return sac_oauth_error( 'sac_capability', '無法編輯此圖片。', 403 );
            if ( get_post_type( $media_id ) !== 'attachment' || strpos( (string) get_post_mime_type( $media_id ), 'image/' ) !== 0 ) {
                return new WP_Error( 'sac_not_found', '找不到指定圖片', [ 'status' => 404 ] );
            }
            $alt_text = trim( wp_strip_all_tags( (string) $req->get_param( 'alt_text' ) ) );
            if ( $alt_text === '' ) return new WP_Error( 'sac_invalid_alt', '替代文字不可為空', [ 'status' => 400 ] );
            $alt_text = mb_substr( $alt_text, 0, 160 );
            update_post_meta( $media_id, '_wp_attachment_image_alt', $alt_text );
            return rest_ensure_response( [ 'success' => true, 'media_id' => $media_id, 'alt_text' => $alt_text ] );
        },
    ] );

    register_rest_route( SAC_NAMESPACE, '/menu', [
        'methods' => 'GET', 'permission_callback' => 'sac_permission_read',
        'callback' => function ( WP_REST_Request $req ) {
            $menu_name = $req->get_param( 'menu_name' );
            $menus = wp_get_nav_menus();
            $target = null;
            if ( $menu_name ) { foreach ( $menus as $m ) if ( $m->name === $menu_name ) { $target = $m; break; } }
            elseif ( ! empty( $menus ) ) { $target = $menus[0]; }
            if ( ! $target ) return new WP_Error( 'sac_not_found', '找不到選單', [ 'status' => 404 ] );
            $items = wp_get_nav_menu_items( $target->term_id );
            return rest_ensure_response( array_map( fn( $i ) => [ 'id' => $i->ID, 'title' => $i->title, 'url' => $i->url, 'order' => $i->menu_order, 'parent' => $i->menu_item_parent ], $items ) );
        },
    ] );

    register_rest_route( SAC_NAMESPACE, '/menu/(?P<id>\d+)', [
        'methods' => 'POST', 'permission_callback' => 'sac_permission_write',
        'callback' => function ( WP_REST_Request $req ) {
            $item_id = (int) $req->get_url_params()['id'];
            if ( get_post_type( $item_id ) !== 'nav_menu_item' ) return new WP_Error( 'sac_not_found', '選單項目不存在', [ 'status' => 404 ] );
            if ( $req->get_param( 'title' ) !== null ) wp_update_post( [ 'ID' => $item_id, 'post_title' => sanitize_text_field( $req->get_param( 'title' ) ) ] );
            if ( $req->get_param( 'url' ) !== null ) update_post_meta( $item_id, '_menu_item_url', esc_url_raw( $req->get_param( 'url' ) ) );
            return rest_ensure_response( [ 'success' => true ] );
        },
    ] );

    register_rest_route( SAC_NAMESPACE, '/wc/products', [
        'methods' => 'GET', 'permission_callback' => 'sac_permission_read',
        'callback' => function ( WP_REST_Request $req ) {
            if ( ! class_exists( 'WooCommerce' ) ) return new WP_Error( 'sac_no_wc', 'WooCommerce 未啟用', [ 'status' => 400 ] );
            $limit = min( (int) ( $req->get_param( 'limit' ) ?: 10 ), 50 );
            $search = $req->get_param( 'search' ) ?: '';
            $category = $req->get_param( 'category' ) ?: '';
            $status = $req->get_param( 'status' ) ?: '';
            $cache_key = "products_{$search}_{$category}_{$status}_{$limit}";
            $cached = sac_cache_get( $req, $cache_key );
            if ( $cached !== false ) return rest_ensure_response( $cached );
            $args = [ 'limit' => $limit, 'orderby' => 'date', 'order' => 'DESC' ];
            if ( $status && $status !== 'any' ) $args['status'] = $status;
            if ( $search ) $args['s'] = $search;
            if ( $category ) $args['category'] = [ sanitize_title( $category ) ];
            $products = wc_get_products( $args );
            $result = array_map( fn( $p ) => [
                'id' => $p->get_id(), 'name' => $p->get_name(), 'status' => $p->get_status(),
                'regular_price' => $p->get_regular_price(), 'sale_price' => $p->get_sale_price(),
                'stock_quantity' => $p->get_stock_quantity(), 'stock_status' => $p->get_stock_status(),
                'categories' => wp_list_pluck( wc_get_product_terms( $p->get_id(), 'product_cat' ), 'name' ),
            ], $products );
            sac_cache_set( $req, $cache_key, $result );
            return rest_ensure_response( $result );
        },
    ] );

    register_rest_route( SAC_NAMESPACE, '/wc/products', [
        'methods' => 'POST', 'permission_callback' => 'sac_permission_write', 'args' => [ 'name' => [ 'required' => true ] ],
        'callback' => function ( WP_REST_Request $req ) {
            if ( ! class_exists( 'WooCommerce' ) ) return new WP_Error( 'sac_no_wc', 'WooCommerce 未啟用', [ 'status' => 400 ] );
            $product = new WC_Product_Simple();
            $status = sac_checked_publication_status( $req->get_param( 'status' ) );
            if ( is_wp_error( $status ) ) return $status;
            $product->set_name( sanitize_text_field( $req->get_param( 'name' ) ) );
            if ( $req->get_param( 'slug' ) !== null ) $product->set_slug( sanitize_title( $req->get_param( 'slug' ) ) );
            if ( $req->get_param( 'description' ) !== null ) $product->set_description( wp_kses_post( $req->get_param( 'description' ) ) );
            if ( $req->get_param( 'short_description' ) !== null ) $product->set_short_description( wp_kses_post( $req->get_param( 'short_description' ) ) );
            if ( $req->get_param( 'regular_price' ) !== null ) $product->set_regular_price( sanitize_text_field( $req->get_param( 'regular_price' ) ) );
            if ( $req->get_param( 'sale_price' ) !== null ) $product->set_sale_price( sanitize_text_field( $req->get_param( 'sale_price' ) ) );
            $stock_qty = $req->get_param( 'stock_quantity' );
            $manage_stock = $req->get_param( 'manage_stock' );
            if ( $stock_qty !== null || $manage_stock ) {
                $product->set_manage_stock( true );
                if ( $stock_qty !== null ) {
                    $product->set_stock_quantity( (int) $stock_qty );
                    $product->set_stock_status( (int) $stock_qty > 0 ? 'instock' : 'outofstock' );
                }
            }
            $product->set_status( $status );
            if ( $req->get_param( 'image_id' ) ) $product->set_image_id( (int) $req->get_param( 'image_id' ) );
            $product_id = $product->save();
            if ( ! $product_id ) return new WP_Error( 'sac_create_failed', '商品建立失敗', [ 'status' => 500 ] );
            $categories = $req->get_param( 'categories' );
            if ( ! empty( $categories ) && is_array( $categories ) ) sac_set_product_categories( $product_id, $categories );
            sac_apply_seo_request( $product_id, $req );
            sac_apply_image_alt( $req );
            return rest_ensure_response( [ 'success' => true, 'product_id' => $product_id, 'seo' => sac_get_seo_fields( $product_id ) ] );
        },
    ] );

    register_rest_route( SAC_NAMESPACE, '/wc/products/(?P<id>\d+)', [
        'methods' => 'GET', 'permission_callback' => 'sac_permission_read',
        'callback' => function ( WP_REST_Request $req ) {
            if ( ! class_exists( 'WooCommerce' ) ) return new WP_Error( 'sac_no_wc', 'WooCommerce 未啟用', [ 'status' => 400 ] );
            $product = wc_get_product( (int) $req->get_url_params()['id'] );
            if ( ! $product ) return new WP_Error( 'sac_not_found', '商品不存在', [ 'status' => 404 ] );
            return rest_ensure_response( sac_format_product( $product ) );
        },
    ] );

    register_rest_route( SAC_NAMESPACE, '/wc/products/(?P<id>\d+)', [
        'methods' => 'POST', 'permission_callback' => 'sac_permission_write',
        'callback' => function ( WP_REST_Request $req ) {
            if ( ! class_exists( 'WooCommerce' ) ) return new WP_Error( 'sac_no_wc', 'WooCommerce 未啟用', [ 'status' => 400 ] );
            $product = wc_get_product( (int) $req->get_url_params()['id'] );
            if ( ! $product ) return new WP_Error( 'sac_not_found', '商品不存在', [ 'status' => 404 ] );
            $changed_fields = [];
            if ( $req->get_param( 'name' ) !== null ) { $product->set_name( sanitize_text_field( $req->get_param( 'name' ) ) ); $changed_fields[] = '名稱'; }
            if ( $req->get_param( 'slug' ) !== null ) { $product->set_slug( sanitize_title( $req->get_param( 'slug' ) ) ); $changed_fields[] = '網址代稱'; }
            if ( $req->get_param( 'description' ) !== null ) $product->set_description( wp_kses_post( $req->get_param( 'description' ) ) );
            if ( $req->get_param( 'short_description' ) !== null ) $product->set_short_description( wp_kses_post( $req->get_param( 'short_description' ) ) );
            if ( $req->get_param( 'regular_price' ) !== null ) { $product->set_regular_price( sanitize_text_field( $req->get_param( 'regular_price' ) ) ); $changed_fields[] = '價格'; }
            if ( $req->get_param( 'sale_price' ) !== null ) $product->set_sale_price( sanitize_text_field( $req->get_param( 'sale_price' ) ) );
            if ( $req->get_param( 'stock_quantity' ) !== null ) {
                $product->set_manage_stock( true );
                $qty = (int) $req->get_param( 'stock_quantity' );
                $product->set_stock_quantity( $qty );
                $product->set_stock_status( $qty > 0 ? 'instock' : 'outofstock' );
                $changed_fields[] = '庫存';
            }
            if ( $req->get_param( 'status' ) !== null ) { $status = sac_checked_publication_status( $req->get_param( 'status' ) ); if ( is_wp_error( $status ) ) return $status; $product->set_status( $status ); $changed_fields[] = '狀態'; }
            if ( $req->get_param( 'image_id' ) !== null ) $product->set_image_id( (int) $req->get_param( 'image_id' ) );
            $product->save();
            $categories = $req->get_param( 'categories' );
            if ( ! empty( $categories ) && is_array( $categories ) ) sac_set_product_categories( $product->get_id(), $categories );
            if ( sac_apply_seo_request( $product->get_id(), $req ) ) $changed_fields[] = 'SEO';
            if ( sac_apply_image_alt( $req ) ) $changed_fields[] = '圖片替代文字';
            return rest_ensure_response( [ 'success' => true, 'product' => sac_format_product( wc_get_product( $product->get_id() ) ), 'seo' => sac_get_seo_fields( $product->get_id() ) ] );
        },
    ] );

    register_rest_route( SAC_NAMESPACE, '/wc/products/(?P<id>\d+)/delete', [
        'methods' => 'POST', 'permission_callback' => 'sac_permission_write',
        'callback' => function ( WP_REST_Request $req ) {
            if ( ! class_exists( 'WooCommerce' ) ) return new WP_Error( 'sac_no_wc', 'WooCommerce 未啟用', [ 'status' => 400 ] );
            $product_id = (int) $req->get_url_params()['id'];
            if ( ! wc_get_product( $product_id ) ) return new WP_Error( 'sac_not_found', '商品不存在', [ 'status' => 404 ] );
            wp_trash_post( $product_id );
            return rest_ensure_response( [ 'success' => true, 'product_id' => $product_id, 'note' => '已移入垃圾桶，可在後台還原' ] );
        },
    ] );

    register_rest_route( SAC_NAMESPACE, '/wc/products/(?P<id>\d+)/stock', [
        'methods' => 'POST', 'permission_callback' => 'sac_permission_write', 'args' => [ 'stock_quantity' => [ 'required' => true ] ],
        'callback' => function ( WP_REST_Request $req ) {
            if ( ! class_exists( 'WooCommerce' ) ) return new WP_Error( 'sac_no_wc', 'WooCommerce 未啟用', [ 'status' => 400 ] );
            $product = wc_get_product( (int) $req->get_url_params()['id'] );
            if ( ! $product ) return new WP_Error( 'sac_not_found', '商品不存在', [ 'status' => 404 ] );
            $qty = (int) $req->get_param( 'stock_quantity' );
            $product->set_manage_stock( true );
            $product->set_stock_quantity( $qty );
            $status = $req->get_param( 'stock_status' ) ?: ( $qty > 0 ? 'instock' : 'outofstock' );
            $product->set_stock_status( sanitize_text_field( $status ) );
            $product->save();
            return rest_ensure_response( [ 'success' => true, 'product_id' => $product->get_id(), 'stock_quantity' => $qty, 'stock_status' => $status ] );
        },
    ] );

    register_rest_route( SAC_NAMESPACE, '/wc/product-categories', [
        'methods' => 'GET', 'permission_callback' => 'sac_permission_read',
        'callback' => function ( WP_REST_Request $req ) {
            if ( ! class_exists( 'WooCommerce' ) ) return new WP_Error( 'sac_no_wc', 'WooCommerce 未啟用', [ 'status' => 400 ] );
            $cached = sac_cache_get( $req, 'product_categories' );
            if ( $cached !== false ) return rest_ensure_response( $cached );
            $terms = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false ] );
            if ( is_wp_error( $terms ) ) return $terms;
            $result = array_map( fn( $t ) => [ 'id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug, 'count' => $t->count ], $terms );
            sac_cache_set( $req, 'product_categories', $result );
            return rest_ensure_response( $result );
        },
    ] );

    register_rest_route( SAC_NAMESPACE, '/wc/orders-summary', [
        'methods' => 'GET', 'permission_callback' => 'sac_permission_read',
        'callback' => function ( WP_REST_Request $req ) {
            if ( ! class_exists( 'WooCommerce' ) ) return new WP_Error( 'sac_no_wc', 'WooCommerce 未啟用', [ 'status' => 400 ] );
            $reveal = false;
            $orders = wc_get_orders( [ 'status' => $req->get_param( 'status' ) ?: 'processing', 'limit' => 20, 'orderby' => 'date', 'order' => 'DESC' ] );
            return rest_ensure_response( array_map( function ( $o ) use ( $reveal ) {
                $name = $o->get_billing_first_name() . ' ' . $o->get_billing_last_name();
                return [ 'id' => $o->get_id(), 'total' => $o->get_total(), 'status' => $o->get_status(),
                    'date' => $o->get_date_created() ? $o->get_date_created()->date( 'Y-m-d H:i' ) : '',
                    'name' => $reveal ? $name : sac_mask_name( $name ),
                    'email' => $reveal ? $o->get_billing_email() : sac_mask_contact( $o->get_billing_email() ),
                    'phone' => $reveal ? $o->get_billing_phone() : sac_mask_contact( $o->get_billing_phone() ) ];
            }, $orders ) );
        },
    ] );

    register_rest_route( SAC_NAMESPACE, '/wc/low-stock', [
        'methods' => 'GET', 'permission_callback' => 'sac_permission_read',
        'callback' => function ( WP_REST_Request $req ) {
            if ( ! class_exists( 'WooCommerce' ) ) return new WP_Error( 'sac_no_wc', 'WooCommerce 未啟用', [ 'status' => 400 ] );
            $threshold = (int) ( $req->get_param( 'threshold' ) ?: 5 );
            $cache_key = "low_stock_{$threshold}";
            $cached = sac_cache_get( $req, $cache_key );
            if ( $cached !== false ) return rest_ensure_response( $cached );
            $products = wc_get_products( [ 'limit' => -1, 'stock_status' => 'instock' ] );
            $low = [];
            foreach ( $products as $p ) {
                $qty = $p->get_stock_quantity();
                if ( $qty !== null && $qty <= $threshold ) $low[] = [ 'id' => $p->get_id(), 'name' => $p->get_name(), 'stock' => $qty ];
            }
            sac_cache_set( $req, $cache_key, $low );
            return rest_ensure_response( $low );
        },
    ] );

    register_rest_route( SAC_NAMESPACE, '/wc/revenue', [
        'methods' => 'GET', 'permission_callback' => 'sac_permission_read',
        'callback' => function ( WP_REST_Request $req ) {
            if ( ! class_exists( 'WooCommerce' ) ) return new WP_Error( 'sac_no_wc', 'WooCommerce 未啟用', [ 'status' => 400 ] );
            $days = (int) ( $req->get_param( 'days' ) ?: 7 );
            $cache_key = "revenue_{$days}";
            $cached = sac_cache_get( $req, $cache_key );
            if ( $cached !== false ) return rest_ensure_response( $cached );
            $orders = wc_get_orders( [ 'status' => [ 'completed', 'processing' ], 'date_created' => '>' . ( time() - $days * DAY_IN_SECONDS ), 'limit' => -1 ] );
            $total = 0; $count = 0;
            foreach ( $orders as $o ) { $total += (float) $o->get_total(); $count++; }
            $result = [ 'days' => $days, 'order_count' => $count, 'total_revenue' => round( $total, 2 ) ];
            sac_cache_set( $req, $cache_key, $result );
            return rest_ensure_response( $result );
        },
    ] );

    register_rest_route( SAC_NAMESPACE, '/form-submissions/unread', [
        'methods' => 'GET', 'permission_callback' => 'sac_permission_read',
        'callback' => function ( WP_REST_Request $req ) {
            $reveal = false;
            $query = new WP_Query( [ 'post_type' => SAC_FORM_CPT, 'post_status' => 'publish', 'meta_key' => '_sac_read', 'meta_value' => '0', 'posts_per_page' => 20, 'no_found_rows' => true ] );
            return rest_ensure_response( array_map( function ( $p ) use ( $reveal ) {
                $name = get_post_meta( $p->ID, '_name', true ); $email = get_post_meta( $p->ID, '_email', true );
                return [ 'id' => $p->ID, 'date' => $p->post_date, 'name' => $reveal ? $name : sac_mask_name( $name ), 'email' => $reveal ? $email : sac_mask_contact( $email ) ];
            }, $query->posts ) );
        },
    ] );

    register_rest_route( SAC_NAMESPACE, '/form-submissions/(?P<id>\d+)/mark-read', [
        'methods' => 'POST', 'permission_callback' => 'sac_permission_write',
        'callback' => function ( WP_REST_Request $req ) {
            $entry_id = (int) $req->get_url_params()['id'];
            if ( get_post_type( $entry_id ) !== SAC_FORM_CPT ) return new WP_Error( 'sac_not_found', '表單送件不存在', [ 'status' => 404 ] );
            update_post_meta( $entry_id, '_sac_read', '1' );
            return rest_ensure_response( [ 'success' => true ] );
        },
    ] );

} );

/**
 * ============================================================
 * 8. 輔助函式
 * ============================================================
 */
function sac_set_product_categories( $product_id, $category_names ) {
    $term_ids = [];
    foreach ( $category_names as $name ) {
        $name = sanitize_text_field( $name );
        if ( $name === '' ) continue;
        $term = term_exists( $name, 'product_cat' );
        if ( ! $term ) $term = wp_insert_term( $name, 'product_cat' );
        if ( ! is_wp_error( $term ) ) $term_ids[] = (int) $term['term_id'];
    }
    if ( ! empty( $term_ids ) ) wp_set_object_terms( $product_id, $term_ids, 'product_cat' );
}

function sac_format_product( $product ) {
    if ( ! $product ) return null;
    return [
        'id'                => $product->get_id(),
        'name'              => $product->get_name(),
        'description'       => $product->get_description(),
        'short_description' => $product->get_short_description(),
        'status'            => $product->get_status(),
        'regular_price'     => $product->get_regular_price(),
        'sale_price'        => $product->get_sale_price(),
        'stock_quantity'    => $product->get_stock_quantity(),
        'stock_status'      => $product->get_stock_status(),
        'manage_stock'      => $product->get_manage_stock(),
        'categories'        => wp_list_pluck( wc_get_product_terms( $product->get_id(), 'product_cat' ), 'name' ),
        'image_id'          => $product->get_image_id(),
        'image_url'         => $product->get_image_id() ? wp_get_attachment_url( $product->get_image_id() ) : '',
        'permalink'         => $product->get_permalink(),
    ];
}

function sac_mask_name( $name ) {
    if ( empty( $name ) ) return '';
    $name = trim( $name ); $len = mb_strlen( $name );
    return $len <= 1 ? $name : mb_substr( $name, 0, 1 ) . str_repeat( '*', $len - 1 );
}

function sac_mask_contact( $value ) {
    if ( empty( $value ) ) return '';
    if ( strpos( $value, '@' ) !== false ) {
        [ $user, $domain ] = explode( '@', $value, 2 );
        return mb_substr( $user, 0, 2 ) . str_repeat( '*', max( 1, mb_strlen( $user ) - 2 ) ) . '@' . $domain;
    }
    $len = strlen( $value );
    return $len <= 4 ? str_repeat( '*', $len ) : substr( $value, 0, 3 ) . str_repeat( '*', $len - 5 ) . substr( $value, -2 );
}

/** Build a concise, plain-language fallback; this is not a ranking guarantee. */
function sac_seo_summary( $content ) {
    $plain = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $content ) ) );
    return mb_substr( $plain, 0, 155 );
}

/** Apply only explicitly provided SEO fields on updates; add safe defaults on creation. */
function sac_apply_seo_request( $post_id, WP_REST_Request $request ) {
    $grant = sac_authenticate( $request );
    if ( is_wp_error( $grant ) || is_wp_error( sac_oauth_require_scope( $grant, 'seo:write' ) ) ) return false;
    $params = [];
    foreach ( [ 'seo_title' => 'title', 'seo_description' => 'description', 'focus_keyword' => 'focus_keyword' ] as $input => $field ) {
        if ( $request->get_param( $input ) !== null ) $params[ $field ] = $request->get_param( $input );
    }
    return $params && ! is_wp_error( sac_update_seo_fields( $post_id, $params ) );
}

function sac_apply_image_alt( WP_REST_Request $request ) {
    $grant = sac_authenticate( $request );
    if ( is_wp_error( $grant ) || is_wp_error( sac_oauth_require_scope( $grant, 'media:update' ) )
        || ! is_string( $request->get_param( 'image_alt' ) ) ) return false;
    $image_id = (int) $request->get_param( 'image_id' );
    if ( ! wp_attachment_is_image( $image_id ) || ! current_user_can( 'edit_post', $image_id ) ) return false;
    update_post_meta( $image_id, '_wp_attachment_image_alt', sanitize_text_field( $request->get_param( 'image_alt' ) ) );
    return true;
}

function sac_faq_block_exists( $post_id ) {
    return has_block( 'yoast/faq-block', $post_id ) || has_block( 'rank-math/faq-block', $post_id );
}

function sac_sync_faq_schema( $post_id, $faqs ) {
    if ( empty( $faqs ) ) {
        delete_post_meta( $post_id, '_sac_faq_schema' );
        return ! metadata_exists( 'post', $post_id, '_sac_faq_schema' );
    }
    $schema = [ '@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map( fn( $f ) => [ '@type' => 'Question', 'name' => $f['question'], 'acceptedAnswer' => [ '@type' => 'Answer', 'text' => wp_strip_all_tags( $f['answer'] ) ] ], $faqs ) ];
    $json = wp_json_encode( $schema, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
    if ( $json === false ) return false;
    update_post_meta( $post_id, '_sac_faq_schema', wp_slash( $json ) );
    return get_post_meta( $post_id, '_sac_faq_schema', true ) === $json;
}

add_action( 'wp_head', function () {
    if ( ! is_singular() || ! isset( sac_content_types()[ get_post_type() ] ) || ! sac_content_extensions( get_post_type() )['faq'] ) return;
    if ( sac_faq_block_exists( get_the_ID() ) ) return;
    $stored = get_post_meta( get_the_ID(), '_sac_faq_schema', true );
    $schema = is_string( $stored ) ? json_decode( $stored, true ) : null;
    if ( $schema ) echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . '</script>' . "\n";
} );

add_filter( 'the_content', function ( $content ) {
    if ( ! is_singular() || ! in_the_loop() || ! is_main_query() || ! isset( sac_content_types()[ get_post_type() ] ) || ! sac_content_extensions( get_post_type() )['faq'] ) return $content;
    $faqs = get_post_meta( get_the_ID(), '_sac_faq_list', true );
    if ( empty( $faqs ) ) return $content;
    $html = '<div class="sac-faq-block"><h2>常見問題</h2>';
    foreach ( $faqs as $f ) $html .= '<div class="sac-faq-item"><h3>' . esc_html( $f['question'] ) . '</h3><div>' . wp_kses_post( $f['answer'] ) . '</div></div>';
    return $content . $html . '</div>';
} );

/**
 * ============================================================
 * 9. 轉址與優惠券
 * ============================================================
 */
/** Legacy redirect and coupon tools remain available through the connector. */
function sac_feature_enabled( $feature ) {
    return in_array( $feature, [ 'redirects', 'coupons' ], true );
}

function sac_feature_permission( $feature, $write, WP_REST_Request $request ) {
    if ( ! sac_feature_enabled( $feature ) ) {
        return new WP_Error( 'sac_feature_disabled', '此功能已在後台停用', [ 'status' => 403 ] );
    }
    return $write ? sac_permission_write( $request ) : sac_permission_read( $request );
}

function sac_redirects_option() {
    $value = get_option( SAC_OPTION_REDIRECTS, [] );
    return is_array( $value ) ? $value : [];
}

function sac_normalize_path( $value ) {
    if ( ! is_string( $value ) || $value === '' || preg_match( '/[\x00-\x1F\x7F]/', $value ) ) return false;
    $parts = wp_parse_url( $value );
    if ( ! is_array( $parts ) || isset( $parts['scheme'] ) || isset( $parts['host'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) return false;
    $path = '/' . trim( $parts['path'] ?? '', '/' );
    if ( $path === '/' || strpos( $path, '//' ) !== false || strpos( $path, '\\' ) !== false ) return false;
    return $path;
}

function sac_redirect_target( $value ) {
    if ( ! is_string( $value ) || trim( $value ) === '' || preg_match( '/[\x00-\x1F\x7F]/', $value ) ) return false;
    if ( $value[0] === '/' && substr( $value, 0, 2 ) !== '//' && strpos( $value, '\\' ) === false ) {
        return esc_url_raw( home_url( $value ), [ 'http', 'https' ] );
    }
    $parts = wp_parse_url( $value );
    if ( ! is_array( $parts ) || ! in_array( strtolower( $parts['scheme'] ?? '' ), [ 'http', 'https' ], true ) || empty( $parts['host'] ) ) return false;
    return esc_url_raw( $value, [ 'http', 'https' ] );
}

function sac_redirect_makes_loop( $source, $target, $redirects ) {
    $home_host = wp_parse_url( home_url(), PHP_URL_HOST );
    $host = wp_parse_url( $target, PHP_URL_HOST );
    if ( strcasecmp( (string) $host, (string) $home_host ) !== 0 ) return false;
    $next = '/' . trim( (string) wp_parse_url( $target, PHP_URL_PATH ), '/' );
    $seen = [];
    for ( $i = 0; $i <= count( $redirects ); $i++ ) {
        if ( $next === $source ) return true;
        if ( isset( $seen[ $next ] ) ) return false;
        $seen[ $next ] = true;
        $found = false;
        foreach ( $redirects as $rule ) {
            if ( ( $rule['source_path'] ?? '' ) !== $next ) continue;
            $rule_target = $rule['target_url'] ?? '';
            if ( strcasecmp( (string) wp_parse_url( $rule_target, PHP_URL_HOST ), (string) $home_host ) !== 0 ) return false;
            $next = '/' . trim( (string) wp_parse_url( $rule_target, PHP_URL_PATH ), '/' );
            $found = true;
            break;
        }
        if ( ! $found ) return false;
    }
    return true;
}

function sac_coupon_error( $message ) {
    return new WP_Error( 'sac_invalid_coupon', $message, [ 'status' => 400 ] );
}

function sac_apply_coupon_fields( WC_Coupon $coupon, WP_REST_Request $req, $create = false ) {
    if ( $create || $req->get_param( 'discount_type' ) !== null ) {
        $type = (string) ( $req->get_param( 'discount_type' ) ?: 'fixed_cart' );
        if ( ! in_array( $type, [ 'percent', 'fixed_cart', 'fixed_product' ], true ) ) return sac_coupon_error( '折扣類型無效' );
        $coupon->set_discount_type( $type );
    }
    if ( $req->get_param( 'amount' ) !== null ) {
        $raw = (string) $req->get_param( 'amount' );
        if ( ! is_numeric( $raw ) || (float) $raw < 0 || ( $coupon->get_discount_type() === 'percent' && (float) $raw > 100 ) ) return sac_coupon_error( '折扣金額無效' );
        $coupon->set_amount( wc_format_decimal( $raw ) );
    }
    if ( $req->get_param( 'expiry_date' ) !== null ) {
        $date = trim( (string) $req->get_param( 'expiry_date' ) );
        if ( $date === '' ) {
            $coupon->set_date_expires( null );
        } else {
            $dt = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, wp_timezone() );
            if ( ! $dt || $dt->format( 'Y-m-d' ) !== $date ) return sac_coupon_error( '到期日需為 YYYY-MM-DD' );
            $coupon->set_date_expires( $date . ' 23:59:59' );
        }
    }
    if ( $req->get_param( 'usage_limit' ) !== null ) {
        $limit = filter_var( $req->get_param( 'usage_limit' ), FILTER_VALIDATE_INT );
        if ( $limit === false || $limit < 0 ) return sac_coupon_error( '使用上限需為非負整數' );
        $coupon->set_usage_limit( $limit );
    }
    if ( $req->get_param( 'min_spend' ) !== null ) {
        $minimum = (string) $req->get_param( 'min_spend' );
        if ( ! is_numeric( $minimum ) || (float) $minimum < 0 ) return sac_coupon_error( '最低消費額無效' );
        $coupon->set_minimum_amount( wc_format_decimal( $minimum ) );
    }
    return true;
}

function sac_coupon_result( WC_Coupon $coupon ) {
    return [
        'id' => $coupon->get_id(), 'code' => $coupon->get_code(),
        'status' => $coupon->get_status(),
        'discount_type' => $coupon->get_discount_type(), 'amount' => $coupon->get_amount(),
        'expiry_date' => $coupon->get_date_expires() ? $coupon->get_date_expires()->date( 'Y-m-d' ) : null,
        'usage_count' => $coupon->get_usage_count(), 'usage_limit' => $coupon->get_usage_limit(),
        'min_spend' => $coupon->get_minimum_amount(),
    ];
}

add_action( 'rest_api_init', function () {
    register_rest_route( SAC_NAMESPACE, '/redirects', [
        'methods' => 'GET', 'permission_callback' => function ( $req ) { return sac_feature_permission( 'redirects', false, $req ); },
        'callback' => function () { return rest_ensure_response( array_values( sac_redirects_option() ) ); },
    ] );
    register_rest_route( SAC_NAMESPACE, '/redirects', [
        'methods' => 'POST', 'permission_callback' => function ( $req ) { return sac_feature_permission( 'redirects', true, $req ); },
        'args' => [ 'source_path' => [ 'required' => true ], 'target_url' => [ 'required' => true ] ],
        'callback' => function ( WP_REST_Request $req ) {
            $source = sac_normalize_path( $req->get_param( 'source_path' ) );
            $target = sac_redirect_target( $req->get_param( 'target_url' ) );
            $status = $req->get_param( 'status_code' ) === null ? 301 : (int) $req->get_param( 'status_code' );
            if ( ! $source || ! $target || ! in_array( $status, [ 301, 302 ], true ) ) return new WP_Error( 'sac_invalid_redirect', '來源路徑、目標網址或狀態碼無效', [ 'status' => 400 ] );
            $redirects = sac_redirects_option();
            foreach ( $redirects as $rule ) {
                if ( $rule['source_path'] === $source ) return new WP_Error( 'sac_duplicate_redirect', '此來源路徑已有轉址規則', [ 'status' => 409 ] );
            }
            if ( sac_redirect_makes_loop( $source, $target, $redirects ) ) return new WP_Error( 'sac_redirect_loop', '此規則會造成轉址循環', [ 'status' => 400 ] );
            $id = 'r_' . wp_generate_password( 12, false, false );
            $redirects[ $id ] = [ 'id' => $id, 'source_path' => $source, 'target_url' => $target, 'status_code' => $status, 'created' => current_time( 'mysql' ) ];
            update_option( SAC_OPTION_REDIRECTS, $redirects, false );
            return rest_ensure_response( [ 'success' => true, 'redirect' => $redirects[ $id ] ] );
        },
    ] );
    register_rest_route( SAC_NAMESPACE, '/redirects/(?P<id>r_[a-zA-Z0-9]+)/delete', [
        'methods' => 'POST', 'permission_callback' => function ( $req ) { return sac_feature_permission( 'redirects', true, $req ); },
        'callback' => function ( WP_REST_Request $req ) {
            $redirects = sac_redirects_option();
            $id = $req['id'];
            if ( ! isset( $redirects[ $id ] ) ) return new WP_Error( 'sac_not_found', '轉址規則不存在', [ 'status' => 404 ] );
            $source = $redirects[ $id ]['source_path'];
            unset( $redirects[ $id ] );
            update_option( SAC_OPTION_REDIRECTS, $redirects, false );
            return rest_ensure_response( [ 'success' => true ] );
        },
    ] );

    register_rest_route( SAC_NAMESPACE, '/wc/coupons', [
        'methods' => 'GET', 'permission_callback' => function ( $req ) { return sac_feature_permission( 'coupons', false, $req ); },
        'callback' => function ( WP_REST_Request $req ) {
            if ( ! class_exists( 'WooCommerce' ) ) return new WP_Error( 'sac_no_wc', 'WooCommerce 未啟用', [ 'status' => 400 ] );
            $limit = max( 1, min( 100, (int) ( $req->get_param( 'limit' ) ?: 20 ) ) );
            $ids = get_posts( [ 'post_type' => 'shop_coupon', 'post_status' => [ 'publish', 'draft' ], 'posts_per_page' => $limit, 'fields' => 'ids', 'no_found_rows' => true ] );
            $result = [];
            foreach ( $ids as $id ) {
                $coupon = new WC_Coupon( $id );
                if ( $coupon->get_id() ) $result[] = sac_coupon_result( $coupon );
            }
            return rest_ensure_response( $result );
        },
    ] );
    register_rest_route( SAC_NAMESPACE, '/wc/coupons', [
        'methods' => 'POST', 'permission_callback' => function ( $req ) { return sac_feature_permission( 'coupons', true, $req ); },
        'args' => [ 'code' => [ 'required' => true ], 'amount' => [ 'required' => true ] ],
        'callback' => function ( WP_REST_Request $req ) {
            if ( ! class_exists( 'WooCommerce' ) ) return new WP_Error( 'sac_no_wc', 'WooCommerce 未啟用', [ 'status' => 400 ] );
            $code = wc_format_coupon_code( (string) $req->get_param( 'code' ) );
            if ( $code === '' ) return sac_coupon_error( '優惠券代碼不可為空' );
            // WooCommerce only looks up published coupons; also prevent duplicate draft codes.
            global $wpdb;
            $existing_id = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'shop_coupon' AND post_status <> 'trash' AND LOWER(post_title) = LOWER(%s) LIMIT 1", $code ) );
            if ( $existing_id ) return sac_coupon_error( '優惠券代碼已存在' );
            try {
                $coupon = new WC_Coupon();
                $coupon->set_code( $code );
                $coupon->set_status( get_option( SAC_OPTION_ALLOW_COUPON_PUBLISH, false ) ? 'publish' : 'draft' );
                $valid = sac_apply_coupon_fields( $coupon, $req, true );
                if ( is_wp_error( $valid ) ) return $valid;
                $id = $coupon->save();
            } catch ( Exception $e ) { return sac_coupon_error( $e->getMessage() ); }
            if ( ! $id ) return sac_coupon_error( '優惠券建立失敗' );
            return rest_ensure_response( [ 'success' => true, 'coupon' => sac_coupon_result( $coupon ) ] );
        },
    ] );
    register_rest_route( SAC_NAMESPACE, '/wc/coupons/(?P<id>\d+)', [
        'methods' => 'POST', 'permission_callback' => function ( $req ) { return sac_feature_permission( 'coupons', true, $req ); },
        'callback' => function ( WP_REST_Request $req ) {
            if ( ! class_exists( 'WooCommerce' ) ) return new WP_Error( 'sac_no_wc', 'WooCommerce 未啟用', [ 'status' => 400 ] );
            $id = (int) $req->get_url_params()['id'];
            if ( get_post_type( $id ) !== 'shop_coupon' || get_post_status( $id ) === 'trash' ) return new WP_Error( 'sac_not_found', '優惠券不存在', [ 'status' => 404 ] );
            if ( get_post_status( $id ) === 'publish' && ! get_option( SAC_OPTION_ALLOW_COUPON_PUBLISH, false ) ) {
                return new WP_Error( 'sac_coupon_publish_blocked', '修改已啟用的優惠券前，須由管理員在後台開啟 AI 優惠券直接生效權限', [ 'status' => 403 ] );
            }
            try {
                $coupon = new WC_Coupon( $id );
                $valid = sac_apply_coupon_fields( $coupon, $req );
                if ( is_wp_error( $valid ) ) return $valid;
                $coupon->save();
            } catch ( Exception $e ) { return sac_coupon_error( $e->getMessage() ); }
            return rest_ensure_response( [ 'success' => true, 'coupon' => sac_coupon_result( $coupon ) ] );
        },
    ] );
    register_rest_route( SAC_NAMESPACE, '/wc/coupons/(?P<id>\d+)/delete', [
        'methods' => 'POST', 'permission_callback' => function ( $req ) { return sac_feature_permission( 'coupons', true, $req ); },
        'callback' => function ( WP_REST_Request $req ) {
            if ( ! class_exists( 'WooCommerce' ) ) return new WP_Error( 'sac_no_wc', 'WooCommerce 未啟用', [ 'status' => 400 ] );
            $id = (int) $req->get_url_params()['id'];
            if ( get_post_type( $id ) !== 'shop_coupon' || get_post_status( $id ) === 'trash' ) return new WP_Error( 'sac_not_found', '優惠券不存在', [ 'status' => 404 ] );
            $code = get_the_title( $id );
            if ( ! wp_trash_post( $id ) ) return sac_coupon_error( '優惠券無法移入垃圾桶' );
            return rest_ensure_response( [ 'success' => true, 'coupon_id' => $id ] );
        },
    ] );
} );

add_action( 'template_redirect', function () {
    if ( ! sac_feature_enabled( 'redirects' ) || is_admin() || wp_doing_ajax() ) return;
    $request_path = (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );
    $current_path = '/' . trim( $request_path, '/' );
    foreach ( sac_redirects_option() as $rule ) {
        if ( ( $rule['source_path'] ?? '' ) !== $current_path ) continue;
        $target = $rule['target_url'] ?? '';
        $status = (int) ( $rule['status_code'] ?? 301 );
        if ( ! $target || ! in_array( $status, [ 301, 302 ], true ) ) return;
        if ( sac_redirect_makes_loop( $current_path, $target, sac_redirects_option() ) ) return;
        wp_redirect( $target, $status, 'Site AI Connector' );
        exit;
    }
}, 1 );
