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
define( 'SAC_OPTION_KEYS', 'sac_api_keys' );
define( 'SAC_OPTION_UPLOAD_TOKENS', 'sac_upload_tokens' );
define( 'SAC_LOG_OPTION', 'sac_operation_log' );
define( 'SAC_OPTION_IP_ALLOWLIST', 'sac_ip_allowlist' );
define( 'SAC_OPTION_ALLOW_PUBLISH', 'sac_ai_allow_publish' );
define( 'SAC_FORM_CPT', 'sac_form_entry' );
define( 'SAC_CACHE_TTL', 60 ); // 唯讀查詢快取秒數，降低重複對話造成的資料庫負擔
define( 'SAC_OPTION_FEATURES', 'sac_features' );
define( 'SAC_OPTION_REDIRECTS', 'sac_redirects' );
define( 'SAC_LOG_MAX', 200 );  // 操作紀錄只保留寫入動作，上限更小即可


function sac_on_activate() {
    sac_migrate_key_format();
    if ( ! wp_next_scheduled( 'sac_cleanup_tokens' ) ) {
        wp_schedule_event( time(), 'daily', 'sac_cleanup_tokens' );
    }
}

// Modules do not receive standalone plugin activation hooks. Initialize after
// loading and flush the new upload rewrite exactly once when first enabled.
sac_on_activate();
add_action( 'init', function () {
    if ( get_option( 'wutm_sac_rewrite_flushed_256', false ) ) return;
    flush_rewrite_rules( false );
    update_option( 'wutm_sac_rewrite_flushed_256', 1, false );
}, 99 );



/**
 * 舊版金鑰格式是 key => 'read'/'write' 字串。
 * 新版格式是 key => ['level'=>..., 'created'=>...]，這裡自動搬移，不會讓你原本的金鑰失效。
 */
function sac_migrate_key_format() {
    $keys = get_option( SAC_OPTION_KEYS, [] );
    if ( empty( $keys ) ) return;
    $changed = false;
    foreach ( $keys as $k => $v ) {
        if ( is_string( $v ) ) {
            $keys[ $k ] = [ 'level' => $v, 'created' => '未記錄（舊版金鑰）' ];
            $changed = true;
        }
    }
    if ( $changed ) update_option( SAC_OPTION_KEYS, $keys );
}

function sac_get_key_level( $key_data ) {
    return is_array( $key_data ) ? ( $key_data['level'] ?? '' ) : $key_data;
}

/**
 * ============================================================
 * 0B. 註冊 sac_form_entry 自訂文章類型（表單送件儲存位置）
 * ============================================================
 */
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
 * 0C. 排程清理過期上傳 Token，避免 wp_options 肥大
 * ============================================================
 */
add_action( 'sac_cleanup_tokens', function () {
    $tokens = get_option( SAC_OPTION_UPLOAD_TOKENS, [] );
    $now = time();
    $new_tokens = array_filter( $tokens, fn( $t ) => $t['expires'] > $now );
    if ( count( $new_tokens ) !== count( $tokens ) ) {
        update_option( SAC_OPTION_UPLOAD_TOKENS, $new_tokens );
    }
} );

/**
 * ============================================================
 * 1. 後台選單
 * ============================================================
 */
add_action( 'admin_menu', function () {
    add_submenu_page( 'wu-toolbox-modular', 'AI 連接器設定', 'AI 連接器', 'manage_options', 'site-ai-connector', 'sac_render_admin_page' );
} );

/** Hidden AI tool pages do not have a parent menu title for WordPress to resolve. */
function sac_prepare_tools_page_title() {
    $GLOBALS['title'] = 'AI 功能工具';
}

/**
 * ============================================================
 * 2. 功能定義（單一來源：畫面顯示 + MCP + OpenAPI + Gemini schema）
 * ============================================================
 */
function sac_get_tool_definitions() {
    $has_wc = class_exists( 'WooCommerce' );

    return [
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
            'summary' => '修改文章與 SEO', 'description' => '修改文章標題、正文、摘要或 SEO 欄位。生成內容須符合搜尋意圖、以自然語句呈現主要關鍵字、使用清楚的小標題及原創資訊；勿堆砌關鍵字或編造事實。既有欄位只更新有帶入的項目（需讀寫金鑰）。',
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
            'summary' => '新增 SEO 友善文章草稿', 'description' => '建立文章時先確認搜尋意圖與目標讀者；撰寫原創、準確且有用的內容，以自然方式使用主要關鍵字、清楚的小標題與具體資訊，同時提供摘要與獨特 SEO 標題／描述。勿堆砌關鍵字、編造資訊或保證排名；預設草稿（需讀寫金鑰）。',
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
            'summary' => '整組覆蓋 FAQ', 'description' => '整組覆蓋指定文章的 FAQ，會自動同步 FAQPage 結構化資料（需先讀取現況，再回傳完整陣列，需讀寫金鑰）。',
            'scenario' => '想幫文章補上常見問題，並自動產生結構化資料', 'prompt' => '幫文章 123 整理 3 題讀者常問的問題，先讀取原有 FAQ，保留舊題後再更新',
            'need_write' => true, 'available' => true,
            'params' => [
                'post_id' => [ 'type' => 'integer', 'in' => 'path', 'required' => true ],
                'faqs'    => [ 'type' => 'array', 'in' => 'body', 'required' => true, 'description' => '完整問答陣列，每項含 question 與 answer' ],
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
            'summary' => '修改 SEO 核心欄位', 'description' => '修改指定文章的 SEO 標題、描述、Canonical、社群分享與索引設定；啟用 SEO 核心時會直接寫入 SEO 核心欄位（需讀寫金鑰）。',
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
            'summary' => '產生一次性上傳連結', 'description' => '產生一次性圖片上傳連結，供手機或其他裝置上傳圖片，有時效與張數限制（需讀寫金鑰）。',
            'scenario' => '圖片只在手機裡，想直接讓 AI 幫忙處理不用先傳到電腦', 'prompt' => '我要用手機上傳商品照片，請給我一個 30 分鐘有效的上傳連結',
            'need_write' => true, 'available' => true,
            'params' => [
                'ttl_minutes' => [ 'type' => 'integer', 'in' => 'body', 'description' => '有效分鐘數，預設 30，最大 180' ],
                'max_files'   => [ 'type' => 'integer', 'in' => 'body', 'description' => '最多張數，預設 5，最大 20' ],
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
            'summary' => '設定精選圖片', 'description' => '將指定媒體 ID 設為文章精選圖片（需讀寫金鑰）。',
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
            'summary' => '更新圖片替代文字', 'description' => '更新指定圖片的替代文字；寫入前應先用 list_media 核對媒體 ID、檔名與所屬文章或商品（需讀寫金鑰）。',
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
            'summary' => '修改選單項目文字或網址', 'description' => '更新既有選單項目的文字或自訂網址，保留原項目編號（需讀寫金鑰）。',
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
            'summary' => '新增 SEO 友善商品', 'description' => '建立 WooCommerce 商品時先確認真實規格與適用情境，撰寫獨特、清楚且可掃讀的商品說明與簡短說明，自然使用主要關鍵字，提供 SEO 標題、描述及圖片替代文字；勿捏造功效或堆砌關鍵字。預設草稿（需讀寫金鑰）。',
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
            'summary' => '修改商品與 SEO', 'description' => '修改商品資料與 SEO 欄位；若重寫商品內容，須以真實規格、買家疑問與自然語言為核心，避免重複或誇大。只更新有帶入的欄位（需讀寫金鑰）。',
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
            'summary' => '刪除商品（移入垃圾桶）', 'description' => '將指定商品移入垃圾桶，可還原，不會直接永久刪除（需讀寫金鑰）。',
            'scenario' => '商品下架不再銷售，想從商品列表移除', 'prompt' => '商品 88 不賣了，先確認商品名稱，再移到垃圾桶',
            'need_write' => true, 'available' => $has_wc,
            'params' => [ 'product_id' => [ 'type' => 'integer', 'in' => 'path', 'required' => true ] ],
        ],
        [
            'name' => 'set_product_stock', 'group' => 'WooCommerce 商品', 'method' => 'POST', 'path' => '/wc/products/{product_id}/stock',
            'summary' => '快速調整庫存', 'description' => '單獨調整指定商品的庫存數量與庫存狀態，不影響其他欄位（需讀寫金鑰）。',
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
            'name' => 'get_unread_form_submissions', 'group' => '營運資料', 'method' => 'GET', 'path' => '/form-submissions/unread',
            'summary' => '查看未讀表單送件', 'description' => '列出未讀的聯絡表單送件，個資預設遮蔽。',
            'scenario' => '想知道有沒有新的聯絡表單還沒處理', 'prompt' => '有新的聯絡表單嗎？列出未讀送件，客戶資料先遮蔽',
            'need_write' => false, 'available' => true,
            'params' => [ 'reveal_pii' => [ 'type' => 'boolean', 'in' => 'query' ] ],
        ],
        [
            'name' => 'mark_form_submission_read', 'group' => '營運資料', 'method' => 'POST', 'path' => '/form-submissions/{entry_id}/mark-read',
            'summary' => '標記表單送件為已讀', 'description' => '把指定表單送件標記為已處理（需讀寫金鑰）。',
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
            'summary' => '列出優惠券', 'description' => '列出優惠券代碼、折扣與使用狀況。',
            'scenario' => '檢查目前優惠券', 'prompt' => '列出目前的優惠券代碼、折扣金額和到期日',
            'need_write' => false, 'available' => $has_wc && sac_feature_enabled( 'coupons' ),
            'params' => [ 'limit' => [ 'type' => 'integer', 'in' => 'query', 'description' => '1 至 100，預設 20' ] ],
        ],
        [
            'name' => 'create_coupon', 'group' => '優惠券管理', 'method' => 'POST', 'path' => '/wc/coupons',
            'summary' => '新增優惠券', 'description' => '新增 WooCommerce 優惠券。',
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
            'summary' => '修改優惠券', 'description' => '修改金額、到期日或使用上限。到期日給空字串可清除。',
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
    ];
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
            'create_upload_link' => '先確認需要上傳的張數，再使用 create_upload_link，參數 ttl_minutes=30、max_files=5；回報連結、到期時間與張數上限。',
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

function sac_build_gemini_tools_schema() {
    $defs = array_filter( sac_get_tool_definitions(), fn( $d ) => $d['available'] );
    $functions = [];
    foreach ( $defs as $d ) {
        $properties = []; $required = [];
        foreach ( $d['params'] as $key => $p ) {
            $properties[ $key ] = [ 'type' => $p['type'], 'description' => $p['description'] ?? $d['summary'] ];
            if ( ! empty( $p['required'] ) ) $required[] = $key;
        }
        $functions[] = [ 'name' => $d['name'], 'description' => $d['description'], 'parameters' => [ 'type' => 'object', 'properties' => $properties, 'required' => $required ] ];
    }
    return [ 'function_declarations' => $functions ];
}

function sac_build_openapi_schema() {
    $rest_base = rest_url( SAC_NAMESPACE );
    $defs = array_filter( sac_get_tool_definitions(), fn( $d ) => $d['available'] );
    $paths = [];
    foreach ( $defs as $d ) {
        $method = strtolower( $d['method'] );
        $op = [ 'operationId' => $d['name'], 'summary' => $d['summary'], 'description' => $d['description'], 'parameters' => [], 'responses' => [ '200' => [ 'description' => '成功' ] ] ];
        $body_props = []; $body_required = [];
        foreach ( $d['params'] as $key => $p ) {
            if ( $p['in'] === 'body' ) {
                $body_props[ $key ] = [ 'type' => $p['type'], 'description' => $p['description'] ?? $d['summary'] ];
                if ( ! empty( $p['required'] ) ) $body_required[] = $key;
            } else {
                $op['parameters'][] = [ 'name' => $key, 'in' => $p['in'], 'required' => ! empty( $p['required'] ), 'schema' => [ 'type' => $p['type'] ], 'description' => $p['description'] ?? '' ];
            }
        }
        if ( ! empty( $body_props ) ) {
            $op['requestBody'] = [ 'required' => true, 'content' => [ 'application/json' => [ 'schema' => [ 'type' => 'object', 'properties' => $body_props, 'required' => $body_required ] ] ] ];
        }
        $paths[ $d['path'] ][ $method ] = $op;
    }
    return [
        'openapi' => '3.1.0',
        'info'    => [ 'title' => get_bloginfo( 'name' ) . ' AI Connector', 'version' => WUTM_VERSION ],
        'servers' => [ [ 'url' => untrailingslashit( $rest_base ) ] ],
        'paths'   => $paths,
        'components' => [ 'securitySchemes' => [ 'ApiKeyAuth' => [ 'type' => 'apiKey', 'in' => 'header', 'name' => 'X-SAC-Key' ] ] ],
        'security' => [ [ 'ApiKeyAuth' => [] ] ],
    ];
}

/**
 * ============================================================
 * 3. IP 白名單（可選，預設關閉）
 * ============================================================
 */
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

// Every connector REST route, including public schemas and MCP transport, passes this gate first.
add_filter( 'rest_pre_dispatch', function ( $result, $server, $request ) {
    if ( strpos( $request->get_route(), '/' . SAC_NAMESPACE . '/' ) === 0 && ! sac_check_ip_allowlist() ) {
        return new WP_Error( 'sac_ip_blocked', '此 IP 不在白名單內', [ 'status' => 403 ] );
    }
    return $result;
}, 1, 3 );

function sac_checked_publication_status( $value ) {
    $status = sanitize_key( $value ?: 'draft' );
    if ( ! in_array( $status, [ 'draft', 'pending', 'private', 'publish' ], true ) ) {
        return new WP_Error( 'sac_invalid_status', '不允許的發布狀態', [ 'status' => 400 ] );
    }
    if ( 'publish' === $status && ! get_option( SAC_OPTION_ALLOW_PUBLISH, false ) ) {
        return new WP_Error( 'sac_publish_disabled', 'AI 直接發布已停用，請先儲存草稿並由管理員審核', [ 'status' => 403 ] );
    }
    return $status;
}

/**
 * ============================================================
 * 3B. 輕量查詢快取：避免 AI 短時間內重複查詢同樣資料反覆打資料庫
 * 僅套用在唯讀（GET）請求，TTL 很短（預設 60 秒），寫入動作不受影響。
 * ============================================================
 */
function sac_cache_get( $cache_key ) {
    return get_transient( 'sac_c_' . md5( $cache_key ) );
}
function sac_cache_set( $cache_key, $value ) {
    set_transient( 'sac_c_' . md5( $cache_key ), $value, SAC_CACHE_TTL );
}

/**
 * ============================================================
 * 4. 真正的 MCP 端點
 * ============================================================
 */
function sac_mcp_tools_list( $auth_level ) {
    $defs = array_filter( sac_get_tool_definitions(), fn( $d ) => $d['available'] && ( $auth_level === 'write' || ! $d['need_write'] ) );
    $tools = [];
    foreach ( $defs as $d ) {
        $properties = []; $required = [];
        foreach ( $d['params'] as $key => $p ) {
            $properties[ $key ] = [ 'type' => $p['type'], 'description' => $p['description'] ?? $d['summary'] ];
            if ( ! empty( $p['required'] ) ) $required[] = $key;
        }
        $tools[] = [
            'name' => $d['name'],
            'description' => $d['description'] . ( $d['need_write'] ? '（此操作會修改網站內容，需讀寫權限）' : '（唯讀查詢）' ),
            'inputSchema' => [ 'type' => 'object', 'properties' => $properties, 'required' => $required ],
            // Honest risk hints help clients distinguish a lookup from a write.
            // They never override a client's own confirmation requirements.
            'annotations' => [
                'title' => $d['summary'],
                'readOnlyHint' => ! $d['need_write'],
                'destructiveHint' => $d['need_write'],
                'idempotentHint' => ! $d['need_write'],
            ],
        ];
    }
    return $tools;
}

function sac_mcp_call_tool( $tool_name, $args, $auth_level, $actor_key = '' ) {
    if ( ! is_array( $args ) ) return new WP_Error( 'sac_invalid_arguments', '工具參數格式錯誤' );
    $defs = sac_get_tool_definitions();
    $def = null;
    foreach ( $defs as $d ) { if ( $d['name'] === $tool_name ) { $def = $d; break; } }
    if ( ! $def ) return new WP_Error( 'sac_unknown_tool', "未知的工具：{$tool_name}" );
    if ( empty( $def['available'] ) ) return new WP_Error( 'sac_unavailable_tool', '此工具目前不可用' );
    if ( $def['need_write'] && $auth_level !== 'write' ) return new WP_Error( 'sac_forbidden', '此金鑰為唯讀，無法執行寫入操作' );

    $path = $def['path'];
    foreach ( $def['params'] as $key => $p ) {
        if ( $p['in'] === 'path' && isset( $args[ $key ] ) ) {
            if ( ! is_scalar( $args[ $key ] ) || ! preg_match( '/^[a-zA-Z0-9_-]+$/', (string) $args[ $key ] ) ) return new WP_Error( 'sac_invalid_path', '路徑參數格式錯誤' );
            $path = str_replace( '{' . $key . '}', (string) $args[ $key ], $path );
        }
    }

    $request = new WP_REST_Request( $def['method'], '/' . SAC_NAMESPACE . $path );
    foreach ( $args as $k => $v ) $request->set_param( $k, $v );
    $request->set_header( 'x-sac-key', sac_internal_key() );
    $request->set_header( 'x-sac-audit-key', $actor_key );

    $response = rest_do_request( $request );
    if ( $response->is_error() ) return $response->as_error();
    return $response->get_data();
}

add_action( 'rest_api_init', function () {

    register_rest_route( SAC_NAMESPACE, '/mcp', [
        'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => 'sac_handle_mcp_request',
    ] );

    // This stateless JSON endpoint does not offer an unsolicited SSE stream.
    register_rest_route( SAC_NAMESPACE, '/mcp', [
        'methods' => 'GET', 'permission_callback' => '__return_true',
        'callback' => function () { return new WP_Error( 'sac_no_sse', '此端點使用 Streamable HTTP JSON，不提供 SSE 串流。', [ 'status' => 405 ] ); },
    ] );

    register_rest_route( SAC_NAMESPACE, '/capabilities', [
        'methods' => 'GET', 'permission_callback' => '__return_true',
        'callback' => function () { return rest_ensure_response( sac_get_tool_definitions() ); },
    ] );

    register_rest_route( SAC_NAMESPACE, '/gemini-tools-schema', [
        'methods' => 'GET', 'permission_callback' => '__return_true',
        'callback' => function () { return rest_ensure_response( sac_build_gemini_tools_schema() ); },
    ] );

    register_rest_route( SAC_NAMESPACE, '/openapi.json', [
        'methods' => 'GET', 'permission_callback' => '__return_true',
        'callback' => function () { return rest_ensure_response( sac_build_openapi_schema() ); },
    ] );
} );

function sac_handle_mcp_request( WP_REST_Request $request ) {
    if ( ! sac_check_ip_allowlist() ) {
        return rest_ensure_response( [ 'jsonrpc' => '2.0', 'id' => null, 'error' => [ 'code' => 403, 'message' => '此 IP 不在白名單內' ] ] );
    }

    $body = json_decode( $request->get_body(), true );
    if ( ! is_array( $body ) ) return new WP_Error( 'sac_invalid_json', '請提供有效的 JSON 物件', [ 'status' => 400 ] );
    $id = $body['id'] ?? null;
    $method = $body['method'] ?? '';
    if ( ! is_string( $method ) ) return new WP_Error( 'sac_invalid_method', 'MCP 方法格式錯誤', [ 'status' => 400 ] );

    // Streamable HTTP notifications have no JSON-RPC response body.
    if ( strpos( $method, 'notifications/' ) === 0 ) {
        return new WP_REST_Response( null, 202 );
    }

    $auth_header = $request->get_header( 'authorization' );
    $key = $request->get_header( 'x-sac-key' );
    if ( ! $key && $auth_header && stripos( $auth_header, 'bearer ' ) === 0 ) {
        $key = substr( $auth_header, 7 );
    }
    $keys = get_option( SAC_OPTION_KEYS, [] );
    $auth_level = isset( $keys[ $key ] ) ? sac_get_key_level( $keys[ $key ] ) : null;

    if ( $method === 'initialize' ) {
        $requested_version = $body['params']['protocolVersion'] ?? '';
        $supported_versions = [ '2024-11-05', '2025-03-26', '2025-06-18', '2025-11-25' ];
        $protocol_version = in_array( $requested_version, $supported_versions, true ) ? $requested_version : '2025-03-26';
        return rest_ensure_response( [
            'jsonrpc' => '2.0', 'id' => $id,
            'result'  => [
                'protocolVersion' => $protocol_version,
                'capabilities' => [ 'tools' => new stdClass() ],
                'serverInfo' => [ 'name' => get_bloginfo( 'name' ) . ' AI Connector', 'version' => WUTM_VERSION ],
                'instructions' => '唯讀工具可查詢網站資料。任何寫入操作都必須先用唯讀工具核對現況，列出預計修改的對象、欄位與內容，取得使用者明確確認後才呼叫寫入工具；完成後回報成功筆數、失敗筆數與失敗原因。修改網站內容需讀寫金鑰，且仍須遵循連接平台的確認流程。',
            ],
        ] );
    }

    if ( $method === 'tools/list' ) {
        if ( ! $auth_level ) return rest_ensure_response( [ 'jsonrpc' => '2.0', 'id' => $id, 'error' => [ 'code' => 401, 'message' => '缺少或無效的 API 金鑰' ] ] );
        return rest_ensure_response( [ 'jsonrpc' => '2.0', 'id' => $id, 'result' => [ 'tools' => sac_mcp_tools_list( $auth_level ) ] ] );
    }

    if ( $method === 'tools/call' ) {
        if ( ! $auth_level ) return rest_ensure_response( [ 'jsonrpc' => '2.0', 'id' => $id, 'error' => [ 'code' => 401, 'message' => '缺少或無效的 API 金鑰' ] ] );
        if ( ! is_array( $body['params'] ?? null ) || ! is_string( $body['params']['name'] ?? null ) ) {
            return rest_ensure_response( [ 'jsonrpc' => '2.0', 'id' => $id, 'error' => [ 'code' => -32602, 'message' => '缺少有效的工具名稱' ] ] );
        }
        $tool_name = $body['params']['name'] ?? '';
        $args = $body['params']['arguments'] ?? [];
        $result = sac_mcp_call_tool( $tool_name, $args, $auth_level, $key );
        if ( is_wp_error( $result ) ) {
            return rest_ensure_response( [
                'jsonrpc' => '2.0', 'id' => $id,
                'result' => [ 'content' => [ [ 'type' => 'text', 'text' => $result->get_error_message() ] ], 'isError' => true ],
            ] );
        }
        // The underlying write endpoint logs successful writes once.
        return rest_ensure_response( [ 'jsonrpc' => '2.0', 'id' => $id, 'result' => [ 'content' => [ [ 'type' => 'text', 'text' => wp_json_encode( $result, JSON_UNESCAPED_UNICODE ) ] ] ] ] );
    }

    return rest_ensure_response( [ 'jsonrpc' => '2.0', 'id' => $id, 'error' => [ 'code' => 404, 'message' => "未知的方法：{$method}" ] ] );
}

// 把參數陣列整理成一行好讀的摘要文字，取代原本直接顯示原始 JSON
function sac_summarize_args( $args ) {
    if ( empty( $args ) ) return '（無參數）';
    $parts = [];
    foreach ( $args as $k => $v ) {
        if ( is_array( $v ) ) $v = wp_json_encode( $v, JSON_UNESCAPED_UNICODE );
        $v = is_bool( $v ) ? ( $v ? 'true' : 'false' ) : $v;
        $v = mb_substr( (string) $v, 0, 60 );
        $parts[] = "{$k}={$v}";
    }
    return implode( '，', $parts );
}

/**
 * ============================================================
 * 5. 後台設定頁
 * ============================================================
 */
function sac_render_admin_page() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( '權限不足' );

    if ( isset( $_POST['sac_action'] ) && check_admin_referer( 'sac_admin_action' ) ) {
        $keys = get_option( SAC_OPTION_KEYS, [] );
        if ( $_POST['sac_action'] === 'add_key' ) {
            $level = ( $_POST['sac_level'] ?? 'read' ) === 'write' ? 'write' : 'read';
            $prefix = $level === 'write' ? 'sac_rw_' : 'sac_ro_';
            $new_key = $prefix . wp_generate_password( 32, false );
            $keys[ $new_key ] = [ 'level' => $level, 'created' => current_time( 'mysql' ) ];
            update_option( SAC_OPTION_KEYS, $keys );
            echo '<div class="notice notice-success"><p>已新增金鑰：<code>' . esc_html( $new_key ) . '</code>（請立即複製保存，離開頁面後不再顯示明碼）</p></div>';
        }
        if ( $_POST['sac_action'] === 'revoke_key' && ! empty( $_POST['sac_key'] ) ) {
            unset( $keys[ sanitize_text_field( $_POST['sac_key'] ) ] );
            update_option( SAC_OPTION_KEYS, $keys );
            echo '<div class="notice notice-success"><p>已撤銷金鑰</p></div>';
        }
        if ( $_POST['sac_action'] === 'save_ip_allowlist' ) {
            $enabled = ! empty( $_POST['sac_ip_enabled'] );
            $ips_raw = sanitize_textarea_field( $_POST['sac_ip_list'] ?? '' );
            $ips = array_values( array_filter( array_map( 'trim', explode( "\n", $ips_raw ) ), fn( $ip ) => filter_var( $ip, FILTER_VALIDATE_IP ) !== false ) );
            if ( $enabled && ! $ips ) {
                echo '<div class="notice notice-error"><p>啟用 IP 白名單前，請先填寫至少一個有效的 IP 位址。</p></div>';
            } else {
                update_option( SAC_OPTION_IP_ALLOWLIST, [ 'enabled' => $enabled, 'ips' => $ips ] );
                echo '<div class="notice notice-success"><p>已更新 IP 白名單設定</p></div>';
            }
        }
        if ( $_POST['sac_action'] === 'save_publish_policy' ) {
            update_option( SAC_OPTION_ALLOW_PUBLISH, ! empty( $_POST['sac_allow_publish'] ) );
            echo '<div class="notice notice-success"><p>已更新 AI 發布限制</p></div>';
        }
        if ( $_POST['sac_action'] === 'clear_log' ) {
            update_option( SAC_LOG_OPTION, [] );
            echo '<div class="notice notice-success"><p>已清空操作紀錄</p></div>';
        }
    }

    $keys = get_option( SAC_OPTION_KEYS, [] );
    $logs = array_slice( array_reverse( get_option( SAC_LOG_OPTION, [] ) ), 0, 50 );
    $rest_base = esc_url( rest_url( SAC_NAMESPACE ) );
    $mcp_url = esc_url( rest_url( SAC_NAMESPACE . '/mcp' ) );
    $openapi_url = esc_url( rest_url( SAC_NAMESPACE . '/openapi.json' ) );
    $gemini_schema = wp_json_encode( sac_build_gemini_tools_schema(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
    $defs = sac_get_tool_definitions();
    $prompt_templates = sac_get_prompt_templates();
    $has_write_key = false;
    $first_write_key_masked = '';
    foreach ( $keys as $k => $data ) {
        if ( sac_get_key_level( $data ) === 'write' ) { $has_write_key = true; break; }
    }
    $ip_allowlist = get_option( SAC_OPTION_IP_ALLOWLIST, [ 'enabled' => false, 'ips' => [] ] );
    $tool_display_names = [];
    foreach ( $defs as $d ) { $tool_display_names[ $d['name'] ] = $d['summary']; }
    ?>
    <div class="wrap wutm-module-wrap sac-connector-core">
        <h1>AI 連接器設定</h1>

        <h2>API 金鑰管理</h2>
        <p>模組不會自動產生連線金鑰。請依需要新增唯讀或讀寫金鑰；讀寫金鑰可修改網站內容，請只交給信任的服務。</p>
        <table class="widefat striped" style="max-width:1000px;">
            <thead><tr><th>金鑰</th><th>權限</th><th>建立時間</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ( $keys as $k => $data ) :
                $level = sac_get_key_level( $data );
                $created = is_array( $data ) ? ( $data['created'] ?? '—' ) : '—（舊版金鑰，無記錄）';
            ?>
                <tr>
                    <td><code><?php echo esc_html( substr( $k, 0, 12 ) . '...' ); ?></code></td>
                    <td><?php echo $level === 'write' ? '讀寫' : '唯讀'; ?></td>
                    <td><?php echo esc_html( $created ); ?></td>
                    <td>
                        <form method="post" style="display:inline;">
                            <?php wp_nonce_field( 'sac_admin_action' ); ?>
                            <input type="hidden" name="sac_action" value="revoke_key">
                            <input type="hidden" name="sac_key" value="<?php echo esc_attr( $k ); ?>">
                            <button class="button button-small" onclick="return confirm('確定撤銷此金鑰？');">撤銷</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <form method="post" style="margin-top:15px;">
            <?php wp_nonce_field( 'sac_admin_action' ); ?>
            <input type="hidden" name="sac_action" value="add_key">
            <select name="sac_level"><option value="read">唯讀</option><option value="write">讀寫</option></select>
            <button class="button button-primary">新增金鑰</button>
        </form>
        <?php if ( ! $has_write_key ) : ?>
        <p style="color:#a00;margin-top:10px;">⚠ 你目前還沒有「讀寫」金鑰，只能查詢不能修改。新增一把讀寫金鑰後才能使用標示「需要讀寫」的功能。</p>
        <?php endif; ?>

        <hr style="margin:30px 0;">

        <h2>🚀 最快連接方式（推薦）</h2>
        <div style="background:#eefaf0;border:1px solid #34a853;border-radius:8px;padding:18px;max-width:1000px;">
            <p>如果你用 <strong>Claude</strong> 或 <strong>Perplexity</strong>（訂閱 Pro 以上），這是最快的方式，只需要「一個網址＋一把金鑰」，貼上去就能用，不用寫任何程式：</p>
            <table class="widefat" style="max-width:900px;background:#fff;">
                <tbody>
                    <tr><td style="width:140px;"><strong>連線網址</strong></td><td><code><?php echo $mcp_url; ?></code></td></tr>
                    <tr><td><strong>金鑰</strong></td><td>用上方「讀寫」金鑰（可修改內容）或「唯讀」金鑰（只能查詢，較安全）</td></tr>
                </tbody>
            </table>
            <p style="margin-top:10px;">下方分頁選你要用的平台，照著 3～5 個步驟複製貼上即可。若你用 ChatGPT 或 Gemini，也一併整理在分頁裡。</p>
        </div>

        <hr style="margin:30px 0;">

        <h2>🔌 依平台設定步驟</h2>

        <div style="display:flex;gap:10px;margin-bottom:0;border-bottom:2px solid #ccd0d4;">
            <button type="button" class="sac-tab-btn button" data-tab="claude" style="border-radius:6px 6px 0 0;">Claude</button>
            <button type="button" class="sac-tab-btn button" data-tab="perplexity" style="border-radius:6px 6px 0 0;">Perplexity</button>
            <button type="button" class="sac-tab-btn button" data-tab="chatgpt" style="border-radius:6px 6px 0 0;">ChatGPT</button>
            <button type="button" class="sac-tab-btn button" data-tab="gemini" style="border-radius:6px 6px 0 0;">Gemini</button>
        </div>

        <div id="tab-claude" class="sac-tab-panel" style="border:1px solid #ccd0d4;padding:20px;max-width:1000px;">
            <h3>Claude Desktop / Claude Code（3 步驟）</h3>
            <ol style="line-height:2;">
                <li>打開設定檔：Mac 是 <code>~/Library/Application Support/Claude/claude_desktop_config.json</code>，Windows 是 <code>%APPDATA%\Claude\claude_desktop_config.json</code></li>
                <li>貼入下方內容，把 <code>你的讀寫金鑰</code> 換成上方複製的金鑰。</li>
                <li>存檔後完全結束 Claude（工作列圖示右鍵「結束」）再重新開啟。</li>
            </ol>
            <textarea readonly class="sac-copy-box" style="width:100%;height:190px;font-family:monospace;font-size:12px;">{
  "mcpServers": {
    "<?php echo esc_js( get_bloginfo( 'name' ) ); ?>": {
      "url": "<?php echo $mcp_url; ?>",
      "headers": {
        "X-SAC-Key": "你的讀寫金鑰"
      }
    }
  }
}</textarea>
            <p><button type="button" class="button sac-copy-btn">📋 複製設定</button></p>
        </div>

        <div id="tab-perplexity" class="sac-tab-panel" style="border:1px solid #ccd0d4;padding:20px;max-width:1000px;display:none;">
            <h3>Perplexity（Pro / Max / Enterprise，5 步驟）</h3>
            <ol style="line-height:2;">
                <li>登入 Perplexity，前往「帳號設定 → Connectors（連接器）」。</li>
                <li>點右上角「+ Custom connector」，選擇「Remote」。</li>
                <li>Name 填：<code><?php echo esc_html( get_bloginfo( 'name' ) ); ?></code>，MCP Server URL 填：<code><?php echo $mcp_url; ?></code></li>
                <li>Authentication 選「API Key」貼上金鑰，Transport 選「Streamable HTTP」。</li>
                <li>按「Add」，完成後在對話的 Sources 裡啟用這個連接器。連上後可先問「What tools do you have access to?」確認。</li>
            </ol>
            <p><strong>連線後驗證：</strong>先請 Perplexity 使用 <code>get_post</code> 讀取指定文章 ID，確認回傳標題與狀態。只有讀寫金鑰會列出 <code>update_post</code> 等寫入工具；若更新後仍看到舊工具清單，請重新連接。若能讀取但寫入被要求確認，請在 Perplexity 完成確認；本站無法略過平台的安全確認。若確認後仍失敗，檢查下方「最近操作紀錄」是否出現寫入紀錄。只有看見工具名稱，不代表寫入呼叫已送達本站。</p>
        </div>

        <div id="tab-chatgpt" class="sac-tab-panel" style="border:1px solid #ccd0d4;padding:20px;max-width:1000px;display:none;">
            <h3>ChatGPT（需要 Plus 以上，建立 Custom GPT，5 步驟）</h3>
            <ol style="line-height:2;">
                <li>前往 <a href="https://chat.openai.com/gpts/editor" target="_blank">GPT 編輯器</a>，切到「Configure」分頁。</li>
                <li>滑到「Actions」按「Create new action」。</li>
                <li>Schema 來源選「Import from URL」，貼上：<code><?php echo $openapi_url; ?></code></li>
                <li>Authentication 選「API Key」→ Custom → Header Name 填 <code>X-SAC-Key</code> → 貼上金鑰。</li>
                <li>儲存後即可在對話中使用。</li>
            </ol>
        </div>

        <div id="tab-gemini" class="sac-tab-panel" style="border:1px solid #ccd0d4;padding:20px;max-width:1000px;display:none;">
            <h3>Gemini（機制不同，僅供測試判斷邏輯）</h3>
            <p>Gemini 沒有隨插即用機制，需另寫轉接程式才能全自動對話。先在 <a href="https://aistudio.google.com/" target="_blank">Google AI Studio</a> 的 Tools 設定貼上下方 JSON 測試。</p>
            <textarea readonly class="sac-copy-box" style="width:100%;height:200px;font-family:monospace;font-size:12px;"><?php echo esc_textarea( $gemini_schema ); ?></textarea>
            <p><button type="button" class="button sac-copy-btn">📋 複製 JSON</button></p>
        </div>

        <script>
        (function(){
            var btns = document.querySelectorAll('.sac-tab-btn');
            var panels = document.querySelectorAll('.sac-tab-panel');
            function activate(tab){
                panels.forEach(function(p){ p.style.display = (p.id === 'tab-' + tab) ? 'block' : 'none'; });
                btns.forEach(function(b){ b.classList.toggle('button-primary', b.dataset.tab === tab); });
            }
            btns.forEach(function(b){ b.addEventListener('click', function(){ activate(b.dataset.tab); }); });
            activate('claude');
            document.querySelectorAll('.sac-copy-btn').forEach(function(btn){
                btn.addEventListener('click', function(){
                    var ta = btn.closest('div').querySelector('.sac-copy-box');
                    navigator.clipboard.writeText(ta.value);
                    var old = btn.innerText; btn.innerText = '已複製！';
                    setTimeout(function(){ btn.innerText = old; }, 1200);
                });
            });
        })();
        </script>

        <hr style="margin:30px 0;">

        <h2>🔒 IP 白名單（進階安全性，選用）</h2>
        <p>預設關閉，不限制任何來源。若你的 AI 工具是固定 IP 執行（例如自架伺服器），可啟用只允許清單內 IP 呼叫。<strong>Claude／Perplexity 這類雲端服務 IP 會變動，開啟可能導致連不上，不建議一般情況啟用。</strong></p>
        <form method="post">
            <?php wp_nonce_field( 'sac_admin_action' ); ?>
            <input type="hidden" name="sac_action" value="save_ip_allowlist">
            <label><input type="checkbox" name="sac_ip_enabled" value="1" <?php checked( ! empty( $ip_allowlist['enabled'] ) ); ?>> 啟用 IP 白名單限制</label>
            <p>允許的 IP（一行一個）：</p>
            <textarea name="sac_ip_list" style="width:100%;max-width:500px;height:80px;"><?php echo esc_textarea( implode( "\n", $ip_allowlist['ips'] ?? [] ) ); ?></textarea>
            <p><button class="button button-primary">儲存設定</button></p>
        </form>

        <h2>AI 寫入限制</h2>
        <p>新增文章與商品預設為草稿；關閉直接發布時，寫入工具指定 publish 會在伺服器端被拒絕。此設定沿用現有唯讀／讀寫金鑰權限。</p>
        <form method="post">
            <?php wp_nonce_field( 'sac_admin_action' ); ?>
            <input type="hidden" name="sac_action" value="save_publish_policy">
            <label><input type="checkbox" name="sac_allow_publish" value="1" <?php checked( (bool) get_option( SAC_OPTION_ALLOW_PUBLISH, false ) ); ?>> 允許 AI 工具直接發布文章與商品</label>
            <p><button class="button button-primary">儲存設定</button></p>
        </form>

        <div class="sac-moved-features" hidden>
        <h2>常用指令：直接複製貼給 AI</h2>
        <p>每份指令都已包含實際工具名稱、查詢條件、內容規格、人工確認與完成回報。複製後可直接貼到 Claude、ChatGPT 或 Perplexity；需要改主題、文章 ID 或商品 ID 時，再替換指令中的範例值。</p>
        <p><strong>SEO 寫作原則：</strong>先確認讀者需求與實際資料，再產出獨特標題、可讀的段落與小標題、自然摘要、SEO 標題與描述；避免關鍵字堆砌、重複內容、無依據的功效或排名保證。新內容預設先存草稿，由管理員審核。</p>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:12px;max-width:1100px;">
        <?php foreach ( $prompt_templates['quick'] as $key => $item ) : ?>
            <div style="background:#fff;border:1px solid #ccd0d4;border-radius:6px;padding:14px;">
                <strong><?php echo esc_html( $item['label'] ); ?></strong>
                <span style="display:inline-block;margin-left:6px;padding:1px 6px;border-radius:10px;background:#eef1f4;color:#50575e;font-size:11px;"><?php echo esc_html( $item['category'] ); ?></span>
                <p style="margin-bottom:8px;color:#50575e;">包含查詢、內容規格、確認後寫入及結果回報。</p>
                <button type="button" class="button sac-quick-copy" data-command-key="<?php echo esc_attr( $key ); ?>">複製完整指令</button>
                <textarea class="sac-command-source" data-command-key="<?php echo esc_attr( $key ); ?>" hidden><?php echo esc_textarea( $item['prompt'] ); ?></textarea>
            </div>
        <?php endforeach; ?>
        </div>
        <script>
        document.querySelectorAll('.sac-quick-copy').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var key = btn.getAttribute('data-command-key');
                var source = document.querySelector('.sac-command-source[data-command-key="' + key + '"]');
                if (!source) return;
                navigator.clipboard.writeText(source.value).then(function() {
                    var old = btn.textContent; btn.textContent = '已複製完整指令';
                    setTimeout(function() { btn.textContent = old; }, 1200);
                });
            });
        });
        </script>
        <hr style="margin:30px 0;">

        <h2>📋 功能總覽與範例指令</h2>
        <p>接上任一平台後，點擊下方範例指令即可複製，貼進對話並換成你實際要處理的對象。</p>
        <?php
        $grouped = [];
        foreach ( $defs as $d ) { $grouped[ $d['group'] ][] = $d; }
        foreach ( $grouped as $group_name => $items ) :
        ?>
            <h3 style="margin-top:22px;"><?php echo esc_html( $group_name ); ?></h3>
            <table class="widefat striped" style="max-width:1100px;">
                <thead><tr><th style="width:16%;">功能</th><th style="width:10%;">狀態</th><th style="width:28%;">適合情境</th><th>範例指令（點擊複製）</th></tr></thead>
                <tbody>
                <?php foreach ( $items as $d ) : ?>
                    <tr<?php echo $d['available'] ? '' : ' style="opacity:0.45;"'; ?>>
                        <td>
                            <strong><?php echo esc_html( $d['summary'] ); ?></strong>
                            <?php if ( $d['need_write'] ) : ?>
                                <br><span style="font-size:11px;background:#fff3cd;color:#7a5c00;padding:1px 6px;border-radius:3px;">需要讀寫</span>
                            <?php else : ?>
                                <br><span style="font-size:11px;background:#e6f4ea;color:#1e7e34;padding:1px 6px;border-radius:3px;">唯讀可用</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $d['available'] ? '✅ 可用' : '未啟用<br><span style="font-size:11px;">(需 WooCommerce)</span>'; ?></td>
                        <td><?php echo esc_html( $d['scenario'] ); ?></td>
                        <td>
                            <?php if ( $d['available'] ) : ?>
                            <?php $tool_prompt = $prompt_templates['tool_prompts'][ $d['name'] ] ?? $d['prompt']; ?>
                            <button type="button" class="sac-tool-prompt-copy" style="width:100%;text-align:left;cursor:pointer;display:block;padding:8px 10px;background:#f6f7f7;border:1px dashed #ccc;border-radius:4px;color:#1d2327;" data-tool="<?php echo esc_attr( $d['name'] ); ?>">📋 <?php echo esc_html( $tool_prompt ); ?></button>
                            <textarea class="sac-tool-prompt-source" data-tool="<?php echo esc_attr( $d['name'] ); ?>" hidden><?php echo esc_textarea( $tool_prompt ); ?></textarea>
                            <?php else : ?>
                                <span style="color:#999;">此功能尚未啟用</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endforeach; ?>

        <script>
        document.querySelectorAll('.sac-tool-prompt-copy').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var tool = btn.getAttribute('data-tool');
                var source = document.querySelector('.sac-tool-prompt-source[data-tool="' + tool + '"]');
                if (!source) return;
                navigator.clipboard.writeText(source.value).then(function() {
                    var old = btn.textContent; btn.textContent = '✅ 已複製到剪貼簿';
                    setTimeout(function() { btn.textContent = old; }, 1200);
                });
            });
        });
        </script>

        <hr style="margin:30px 0;">

        <h2>📜 最近操作紀錄（寫入工具）</h2>
        <p style="font-size:13px;color:#666;">記錄寫入工具的成功與失敗結果；單純查詢不會顯示在這裡。</p>
        <p class="notice notice-warning" style="padding:10px;">安全性提醒：v3.1.6 升級時已永久清除舊版紀錄中的明文摘要與金鑰前綴，無法還原。這是刻意保護敏感資料的單向遷移，並非資料異常。</p>
        <table class="widefat striped" style="max-width:1100px;">
            <thead><tr><th>時間</th><th>工具／動作</th><th>目標</th><th>結果／失敗原因</th><th>SEO 欄位</th><th>金鑰識別碼</th></tr></thead>
            <tbody>
            <?php if ( empty( $logs ) ) : ?>
                <tr><td colspan="6" style="color:#999;">目前沒有任何寫入操作紀錄</td></tr>
            <?php else : foreach ( $logs as $log ) : ?>
                <tr>
                    <td><?php echo esc_html( $log['time'] ); ?></td>
                    <td><strong><?php echo esc_html( $log['tool'] ?? $log['action'] ?? '' ); ?></strong></td>
                    <td><?php echo esc_html( ( $log['target_type'] ?? 'legacy' ) . ' #' . ( $log['target_id'] ?? 0 ) ); ?></td>
                    <td><?php echo esc_html( isset( $log['success'] ) ? ( $log['success'] ? '成功' : '失敗：' . ( $log['failure_reason'] ?? 'request_failed' ) ) : '舊版紀錄' ); ?></td>
                    <td><?php echo esc_html( implode( ', ', $log['seo_fields'] ?? [] ) ); ?></td>
                    <td><code><?php echo esc_html( $log['key_id'] ?? '舊版無識別碼' ); ?></code></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        <form method="post" style="margin-top:10px;">
            <?php wp_nonce_field( 'sac_admin_action' ); ?>
            <input type="hidden" name="sac_action" value="clear_log">
            <button class="button" onclick="return confirm('確定清空所有操作紀錄？此動作無法復原。');">清空操作紀錄</button>
        </form>
        </div>
    </div>
    <?php
}

/**
 * 各 AI 功能卡片共用的獨立頁面。連線與權限仍由 AI 連接器統一管理，
 * 這裡只依工作類型顯示既有工具與指令，避免全部堆在設定首頁。
 */
function sac_render_tools_page() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( '權限不足' );

    $pages = [
        'content' => [ 'title' => 'AI 文章與 FAQ', 'groups' => [ '文章與 FAQ' ], 'categories' => [ '文章與 FAQ' ] ],
        'seo' => [ 'title' => 'AI SEO 工具', 'groups' => [ 'SEO' ], 'categories' => [ 'SEO' ] ],
        'media' => [ 'title' => 'AI 圖片工具', 'groups' => [ '圖片上傳' ], 'categories' => [ '圖片上傳' ] ],
        'commerce' => [ 'title' => 'AI 電商工具', 'groups' => [ 'WooCommerce 商品', '營運資料', '優惠券管理' ], 'categories' => [ 'WooCommerce 商品', '營運資料', '優惠券管理' ] ],
        'site' => [ 'title' => 'AI 網站工具', 'groups' => [ '選單管理', '轉址管理' ], 'categories' => [ '選單管理', '轉址管理' ] ],
        'logs' => [ 'title' => 'AI 操作紀錄', 'groups' => [], 'categories' => [] ],
    ];
    $group_key = isset( $_GET['group'] ) ? sanitize_key( wp_unslash( $_GET['group'] ) ) : 'content'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if ( ! isset( $pages[ $group_key ] ) ) $group_key = 'content';
    $page = $pages[ $group_key ];

    if ( 'logs' === $group_key && isset( $_POST['sac_action'] ) && 'clear_log' === $_POST['sac_action'] ) {
        check_admin_referer( 'sac_admin_action' );
        update_option( SAC_LOG_OPTION, [] );
        echo '<div class="notice notice-success"><p>已清空操作紀錄</p></div>';
    }

    $connector_url = admin_url( 'admin.php?page=site-ai-connector' );
    ?>
    <div class="wrap wutm-module-wrap sac-tools-page">
        <h1><?php echo esc_html( $page['title'] ); ?></h1>
        <p class="wutm-module-subtitle">工具由 AI 連接器統一提供；金鑰、平台串接、IP 白名單與權限請到 <a href="<?php echo esc_url( $connector_url ); ?>">AI 連接器設定</a> 管理。</p>
        <?php if ( 'logs' === $group_key ) :
            $logs = array_slice( array_reverse( get_option( SAC_LOG_OPTION, [] ) ), 0, 100 ); ?>
            <div class="sac-tools-card"><h2>最近寫入操作</h2><p>記錄寫入工具的成功與失敗結果；唯讀查詢不會列入。</p><p class="notice notice-warning" style="padding:10px;">安全性提醒：v3.1.6 升級時已永久清除舊版紀錄中的明文摘要與金鑰前綴，無法還原。這是刻意保護敏感資料的單向遷移，並非資料異常。</p>
            <table class="widefat striped"><thead><tr><th>時間</th><th>工具／動作</th><th>目標</th><th>結果／失敗原因</th><th>SEO 欄位</th><th>金鑰識別碼</th></tr></thead><tbody>
            <?php if ( ! $logs ) : ?><tr><td colspan="6">目前沒有任何寫入操作紀錄</td></tr><?php else : foreach ( $logs as $log ) : ?>
                <tr><td><?php echo esc_html( $log['time'] ?? '' ); ?></td><td><strong><?php echo esc_html( $log['tool'] ?? $log['action'] ?? '' ); ?></strong></td><td><?php echo esc_html( ( $log['target_type'] ?? 'legacy' ) . ' #' . ( $log['target_id'] ?? 0 ) ); ?></td><td><?php echo esc_html( isset( $log['success'] ) ? ( $log['success'] ? '成功' : '失敗：' . ( $log['failure_reason'] ?? 'request_failed' ) ) : '舊版紀錄' ); ?></td><td><?php echo esc_html( implode( ', ', $log['seo_fields'] ?? [] ) ); ?></td><td><code><?php echo esc_html( $log['key_id'] ?? '舊版無識別碼' ); ?></code></td></tr>
            <?php endforeach; endif; ?></tbody></table>
            <form method="post" style="margin-top:14px;"><?php wp_nonce_field( 'sac_admin_action' ); ?><input type="hidden" name="sac_action" value="clear_log"><button class="button" onclick="return confirm('確定清空所有操作紀錄？此動作無法復原。');">清空操作紀錄</button></form></div>
        <?php else :
            $defs = array_values( array_filter( sac_get_tool_definitions(), static function ( $definition ) use ( $page ) { return in_array( $definition['group'], $page['groups'], true ); } ) );
            $templates = sac_get_prompt_templates();
            $quick = array_filter( $templates['quick'], static function ( $item ) use ( $page ) { return in_array( $item['category'], $page['categories'], true ); } );
            if ( $quick ) : ?>
                <div class="sac-tools-card"><h2>AI 指令（一鍵複製）</h2><p>先閱讀指令，再將其中的範例主題、文章 ID 或商品 ID 換成實際內容。按「複製指令」後貼到已連接本站工具的 Claude、ChatGPT 或 Perplexity；需要寫入的操作會先請你確認。</p><div class="sac-prompt-list">
                <?php foreach ( $quick as $key => $item ) : ?><section class="sac-prompt-card"><h3><?php echo esc_html( $item['label'] ); ?></h3><p>用途：<?php echo esc_html( $item['category'] ); ?> · 可先檢查完整步驟再複製</p><label class="screen-reader-text" for="quick-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $item['label'] ); ?>完整指令</label><textarea id="quick-<?php echo esc_attr( $key ); ?>" class="sac-prompt-text" readonly rows="11"><?php echo esc_textarea( $item['prompt'] ); ?></textarea><p class="sac-prompt-actions"><button type="button" class="button button-primary sac-copy" data-copy="quick-<?php echo esc_attr( $key ); ?>">複製指令</button></p></section><?php endforeach; ?>
                </div></div>
            <?php endif; ?>
            <div class="sac-tools-card"><h2>可使用工具</h2>
            <?php if ( ! $defs ) : ?><p>目前沒有可顯示的工具。</p><?php else : ?><table class="widefat striped"><thead><tr><th>功能</th><th>權限</th><th>適合情境</th><th>範例指令</th></tr></thead><tbody>
            <?php foreach ( $defs as $definition ) : $prompt = $templates['tool_prompts'][ $definition['name'] ] ?? $definition['prompt']; ?>
                <tr<?php echo $definition['available'] ? '' : ' style="opacity:.5"'; ?>><td><strong><?php echo esc_html( $definition['summary'] ); ?></strong><br><code><?php echo esc_html( $definition['name'] ); ?></code></td><td><?php echo $definition['need_write'] ? '需要讀寫' : '唯讀可用'; ?></td><td><?php echo esc_html( $definition['scenario'] ); ?></td><td><?php if ( $definition['available'] ) : ?><details class="sac-tool-prompt"><summary>查看範例指令</summary><label class="screen-reader-text" for="tool-<?php echo esc_attr( $definition['name'] ); ?>"><?php echo esc_html( $definition['summary'] ); ?>範例指令</label><textarea id="tool-<?php echo esc_attr( $definition['name'] ); ?>" readonly rows="5"><?php echo esc_textarea( $prompt ); ?></textarea></details><button type="button" class="button sac-copy" data-copy="tool-<?php echo esc_attr( $definition['name'] ); ?>">複製指令</button><?php else : ?>尚未啟用或缺少 WooCommerce<?php endif; ?></td></tr>
            <?php endforeach; ?></tbody></table><?php endif; ?></div>
        <?php endif; ?>
    </div>
    <style>.sac-tools-page{max-width:1240px}.sac-tools-card{margin:20px 0;padding:22px 24px;border:1px solid #dcdcde;border-radius:8px;background:#fff}.sac-tools-card h2{margin-top:0}.sac-prompt-list{display:grid;gap:14px}.sac-prompt-card{padding:20px;border:1px solid #c3d8ee;border-radius:6px;background:#f2f8ff}.sac-prompt-card h3{margin:0 0 10px;font-size:16px}.sac-prompt-card p{color:#50575e}.sac-prompt-card .sac-prompt-text{display:block;width:100%;min-height:190px;resize:vertical;font-family:inherit;font-size:14px;line-height:1.6;background:#fff}.sac-prompt-actions{margin:14px 0 0}.sac-tool-prompt{margin-bottom:8px}.sac-tool-prompt summary{color:#2271b1;cursor:pointer}.sac-tool-prompt textarea{display:block;width:100%;min-width:230px;margin-top:8px;font-family:inherit;line-height:1.5}.sac-tools-page .widefat{margin-top:14px}.sac-tools-page .widefat th,.sac-tools-page .widefat td{vertical-align:top}@media(max-width:782px){.sac-tools-page .widefat{display:block;overflow-x:auto}.sac-tools-card{padding:16px}.sac-prompt-card{padding:14px}}</style>
    <script>document.querySelectorAll('.sac-copy').forEach(function(button){button.addEventListener('click',function(){var source=document.getElementById(button.dataset.copy);if(!source)return;navigator.clipboard.writeText(source.value).then(function(){var old=button.textContent;button.textContent='已複製';setTimeout(function(){button.textContent=old},1200)})})});</script>
    <?php
}

/** Render a separately registered AI tool module while reusing the connector's data layer. */
function sac_render_tools_group( $group_key ) {
    $_GET['group'] = sanitize_key( $group_key );
    sac_render_tools_page();
}

function sac_audit_key_id( $key ) {
    if ( ! is_string( $key ) || '' === $key || $key === sac_internal_key() ) return 'unknown';
    return substr( hash_hmac( 'sha256', $key, wp_salt( 'auth' ) ), 0, 16 );
}

function sac_audit_write( $response, $server, $request ) {
    if ( 'POST' !== $request->get_method() ) return $response;
    $route = $request->get_route();
    $prefix = '/' . SAC_NAMESPACE;
    if ( strpos( $route, $prefix . '/' ) !== 0 || $route === $prefix . '/mcp' ) return $response;
    $path = substr( $route, strlen( $prefix ) );
    $tool = null;
    foreach ( sac_get_tool_definitions() as $definition ) {
        if ( ! $definition['need_write'] || 'POST' !== $definition['method'] ) continue;
        $parts = preg_split( '/\{[^}]+\}/', $definition['path'] );
        $pattern = '~^' . implode( '[^/]+', array_map( static function ( $part ) { return preg_quote( $part, '~' ); }, $parts ) ) . '$~';
        if ( preg_match( $pattern, $path ) ) { $tool = $definition['name']; break; }
    }
    if ( ! $tool ) return $response;
    $data = is_wp_error( $response ) ? [ 'code' => $response->get_error_code() ] : $response->get_data();
    $success = ! is_wp_error( $response ) && ! $response->is_error() && $response->get_status() < 400 && ! ( is_array( $data ) && isset( $data['success'] ) && false === $data['success'] );
    $params = $request->get_params();
    $raw_id = $params['id'] ?? $params['post_id'] ?? $params['product_id'] ?? $params['media_id'] ?? $params['entry_id'] ?? '';
    $id = is_scalar( $raw_id ) && preg_match( '/^(?:\d+|r_[a-zA-Z0-9]+)$/', (string) $raw_id ) ? (string) $raw_id : '';
    if ( '' === $id && $success && is_array( $data ) ) $id = (string) absint( $data['post_id'] ?? $data['product_id'] ?? $data['coupon_id'] ?? $data['id'] ?? 0 );
    $target_type = 'other';
    if ( strpos( $path, '/posts' ) === 0 ) $target_type = 'post';
    elseif ( strpos( $path, '/wc/products' ) === 0 ) $target_type = 'product';
    elseif ( strpos( $path, '/wc/coupons' ) === 0 ) $target_type = 'coupon';
    elseif ( strpos( $path, '/media' ) === 0 ) $target_type = 'media';
    elseif ( strpos( $path, '/menu' ) === 0 ) $target_type = 'menu';
    elseif ( strpos( $path, '/redirect' ) === 0 ) $target_type = 'redirect';
    elseif ( strpos( $path, '/form-submissions' ) === 0 ) $target_type = 'form_submission';
    $seo_keys = [ 'seo_title', 'seo_description', 'focus_keyword', 'canonical', 'noindex', 'nofollow', 'og_title', 'og_description', 'og_image_id' ];
    if ( 'update_post_seo' === $tool ) $seo_keys = array_merge( $seo_keys, [ 'title', 'description' ] );
    $seo_fields = ( 'update_post_seo' === $tool || in_array( $tool, [ 'update_post', 'update_product', 'create_post', 'create_product' ], true ) ) ? array_values( array_intersect( array_keys( $params ), $seo_keys ) ) : [];
    $key = $request->get_header( 'x-sac-key' );
    if ( $key === sac_internal_key() ) $key = $request->get_header( 'x-sac-audit-key' );
    $reason = '';
    if ( ! $success ) {
        // Error codes only: never log free-form messages, request values, PII, or response bodies.
        $reason = is_array( $data ) && isset( $data['code'] ) ? sanitize_key( $data['code'] ) : 'request_failed';
    }
    $logs = get_option( SAC_LOG_OPTION, [] );
    $logs[] = [ 'time' => current_time( 'mysql' ), 'tool' => $tool, 'target_type' => $target_type, 'target_id' => $id, 'action' => $tool, 'success' => $success, 'failure_reason' => $reason, 'key_id' => sac_audit_key_id( $key ), 'seo_fields' => $seo_fields ];
    update_option( SAC_LOG_OPTION, array_slice( $logs, -SAC_LOG_MAX ), false );
    return $response;
}
add_filter( 'rest_post_dispatch', 'sac_audit_write', 10, 3 );

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
 * 6. 認證
 * ============================================================
 */
function sac_authenticate( WP_REST_Request $request ) {
    if ( ! sac_check_ip_allowlist() ) {
        return new WP_Error( 'sac_ip_blocked', '此 IP 不在白名單內', [ 'status' => 403 ] );
    }
    $key = $request->get_header( 'x-sac-key' );
    if ( $key === sac_internal_key() ) {
        return 'write';
    }
    if ( ! $key ) return new WP_Error( 'sac_no_key', '缺少 API 金鑰', [ 'status' => 401 ] );
    $keys = get_option( SAC_OPTION_KEYS, [] );
    if ( ! isset( $keys[ $key ] ) ) return new WP_Error( 'sac_invalid_key', 'API 金鑰無效', [ 'status' => 403 ] );
    return sac_get_key_level( $keys[ $key ] );
}

function sac_permission_read( WP_REST_Request $request ) {
    $level = sac_authenticate( $request );
    return is_wp_error( $level ) ? $level : true;
}

function sac_permission_write( WP_REST_Request $request ) {
    $level = sac_authenticate( $request );
    if ( is_wp_error( $level ) ) return $level;
    if ( $level !== 'write' ) return new WP_Error( 'sac_forbidden', '此金鑰為唯讀，無法執行寫入操作', [ 'status' => 403 ] );
    return true;
}

/**
 * ============================================================
 * 7. REST 路由
 * ============================================================
 */
add_action( 'rest_api_init', function () {

    register_rest_route( SAC_NAMESPACE, '/posts/(?P<id>\d+)/faq', [
        'methods' => 'GET', 'permission_callback' => 'sac_permission_read',
        'callback' => function ( WP_REST_Request $req ) {
            $post_id = (int) $req['id'];
            if ( ! get_post( $post_id ) ) return new WP_Error( 'sac_not_found', '文章不存在', [ 'status' => 404 ] );
            $cache_key = "faq_{$post_id}";
            $cached = sac_cache_get( $cache_key );
            if ( $cached !== false ) return rest_ensure_response( $cached );
            $faqs = get_post_meta( $post_id, '_sac_faq_list', true );
            $result = [ 'post_id' => $post_id, 'title' => get_the_title( $post_id ), 'status' => get_post_status( $post_id ), 'faqs' => $faqs ?: [] ];
            sac_cache_set( $cache_key, $result );
            return rest_ensure_response( $result );
        },
    ] );

    register_rest_route( SAC_NAMESPACE, '/posts/(?P<id>\d+)/faq', [
        'methods' => 'POST', 'permission_callback' => 'sac_permission_write', 'args' => [ 'faqs' => [ 'required' => true ] ],
        'callback' => function ( WP_REST_Request $req ) {
            $post_id = (int) $req['id'];
            if ( ! get_post( $post_id ) ) return new WP_Error( 'sac_not_found', '文章不存在', [ 'status' => 404 ] );
            $clean = [];
            foreach ( (array) $req->get_param( 'faqs' ) as $item ) {
                if ( ! empty( $item['question'] ) && isset( $item['answer'] ) ) {
                    $clean[] = [ 'question' => sanitize_text_field( $item['question'] ), 'answer' => wp_kses_post( $item['answer'] ) ];
                }
            }
            update_post_meta( $post_id, '_sac_faq_list', $clean );
            sac_sync_faq_schema( $post_id, $clean );
            delete_transient( 'sac_c_' . md5( "faq_{$post_id}" ) );
            return rest_ensure_response( [ 'success' => true, 'post_id' => $post_id, 'faqs' => $clean ] );
        },
    ] );

    register_rest_route( SAC_NAMESPACE, '/posts', [
        'methods' => 'GET', 'permission_callback' => 'sac_permission_read',
        'callback' => function ( WP_REST_Request $req ) {
            $status = $req->get_param( 'status' ) ?: 'any';
            $search = $req->get_param( 'search' ) ?: '';
            $limit  = min( (int) ( $req->get_param( 'limit' ) ?: 10 ), 50 );
            $cache_key = "posts_{$status}_{$search}_{$limit}";
            $cached = sac_cache_get( $cache_key );
            if ( $cached !== false ) return rest_ensure_response( $cached );
            $query = new WP_Query( [
                'post_type' => 'post', 'post_status' => $status, 's' => $search, 'posts_per_page' => $limit,
                'orderby' => 'modified', 'order' => 'DESC', 'no_found_rows' => true, 'update_post_meta_cache' => false, 'update_post_term_cache' => false,
            ] );
            $result = array_map( fn( $p ) => [ 'id' => $p->ID, 'title' => get_the_title( $p ), 'status' => get_post_status( $p ), 'modified' => $p->post_modified ], $query->posts );
            sac_cache_set( $cache_key, $result );
            return rest_ensure_response( $result );
        },
    ] );

    register_rest_route( SAC_NAMESPACE, '/posts', [
        'methods' => 'POST', 'permission_callback' => 'sac_permission_write', 'args' => [ 'title' => [ 'required' => true ] ],
        'callback' => function ( WP_REST_Request $req ) {
            $content = wp_kses_post( $req->get_param( 'content' ) ?: '' );
            $status = sac_checked_publication_status( $req->get_param( 'status' ) );
            if ( is_wp_error( $status ) ) return $status;
            $excerpt = $req->get_param( 'excerpt' ) !== null
                ? sanitize_textarea_field( $req->get_param( 'excerpt' ) )
                : sac_seo_summary( $content );
            $post_id = wp_insert_post( [
                'post_title'   => sanitize_text_field( $req->get_param( 'title' ) ),
                'post_content' => $content,
                'post_excerpt' => $excerpt,
                'post_name'    => $req->get_param( 'slug' ) !== null ? sanitize_title( $req->get_param( 'slug' ) ) : '',
                'post_status'  => $status,
                'post_type'    => 'post',
            ], true );
            if ( is_wp_error( $post_id ) ) return $post_id;
            sac_apply_seo_request( $post_id, $req, true, $req->get_param( 'title' ), $excerpt );
            return rest_ensure_response( [ 'success' => true, 'post_id' => $post_id, 'seo' => sac_get_seo_fields( $post_id ) ] );
        },
    ] );

    register_rest_route( SAC_NAMESPACE, '/posts/(?P<id>\d+)', [
        'methods' => 'GET', 'permission_callback' => 'sac_permission_read',
        'callback' => function ( WP_REST_Request $req ) {
            $post = get_post( (int) $req['id'] );
            if ( ! $post ) return new WP_Error( 'sac_not_found', '文章不存在', [ 'status' => 404 ] );
            return rest_ensure_response( [ 'id' => $post->ID, 'title' => $post->post_title, 'content' => $post->post_content, 'status' => $post->post_status, 'modified' => $post->post_modified ] );
        },
    ] );

    register_rest_route( SAC_NAMESPACE, '/posts/(?P<id>\d+)', [
        'methods' => 'POST', 'permission_callback' => 'sac_permission_write',
        'callback' => function ( WP_REST_Request $req ) {
            $post_id = (int) $req['id'];
            if ( ! get_post( $post_id ) ) return new WP_Error( 'sac_not_found', '文章不存在', [ 'status' => 404 ] );
            $update = [ 'ID' => $post_id ];
            $changed_fields = [];
            if ( $req->get_param( 'title' ) !== null ) { $update['post_title'] = sanitize_text_field( $req->get_param( 'title' ) ); $changed_fields[] = '標題'; }
            if ( $req->get_param( 'content' ) !== null ) { $update['post_content'] = wp_kses_post( $req->get_param( 'content' ) ); $changed_fields[] = '內容'; }
            if ( $req->get_param( 'excerpt' ) !== null ) { $update['post_excerpt'] = sanitize_textarea_field( $req->get_param( 'excerpt' ) ); $changed_fields[] = '摘要'; }
            if ( $req->get_param( 'slug' ) !== null ) { $update['post_name'] = sanitize_title( $req->get_param( 'slug' ) ); $changed_fields[] = '網址代稱'; }
            if ( $req->get_param( 'status' ) !== null ) { $status = sac_checked_publication_status( $req->get_param( 'status' ) ); if ( is_wp_error( $status ) ) return $status; $update['post_status'] = $status; $changed_fields[] = '狀態'; }
            $result = wp_update_post( $update, true );
            if ( is_wp_error( $result ) ) return $result;
            if ( sac_apply_seo_request( $post_id, $req ) ) $changed_fields[] = 'SEO';
            return rest_ensure_response( [ 'success' => true, 'post_id' => $post_id, 'seo' => sac_get_seo_fields( $post_id ) ] );
        },
    ] );

    register_rest_route( SAC_NAMESPACE, '/posts/(?P<id>\d+)/seo', [
        'methods' => 'GET', 'permission_callback' => 'sac_permission_read',
        'callback' => function ( WP_REST_Request $req ) {
            $post_id = (int) $req['id'];
            if ( ! get_post( $post_id ) ) return new WP_Error( 'sac_not_found', '文章不存在', [ 'status' => 404 ] );
            return rest_ensure_response( sac_get_seo_fields( $post_id ) );
        },
    ] );

    register_rest_route( SAC_NAMESPACE, '/posts/(?P<id>\d+)/seo', [
        'methods' => 'POST', 'permission_callback' => 'sac_permission_write',
        'callback' => function ( WP_REST_Request $req ) {
            $post_id = (int) $req['id'];
            if ( ! get_post( $post_id ) ) return new WP_Error( 'sac_not_found', '文章不存在', [ 'status' => 404 ] );
            sac_update_seo_fields( $post_id, $req->get_params() );
            return rest_ensure_response( [ 'success' => true, 'post_id' => $post_id, 'seo' => sac_get_seo_fields( $post_id ) ] );
        },
    ] );

    // 效能優化：只查詢 ID 與必要欄位，不載入完整 post 物件與 meta/term cache
    register_rest_route( SAC_NAMESPACE, '/seo/missing', [
        'methods' => 'GET', 'permission_callback' => 'sac_permission_read',
        'callback' => function ( WP_REST_Request $req ) {
            $limit = min( (int) ( $req->get_param( 'limit' ) ?: 20 ), 100 );
            $cache_key = "seo_missing_{$limit}";
            $cached = sac_cache_get( $cache_key );
            if ( $cached !== false ) return rest_ensure_response( $cached );

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
            sac_cache_set( $cache_key, $missing );
            return rest_ensure_response( $missing );
        },
    ] );

    register_rest_route( SAC_NAMESPACE, '/upload-link', [
        'methods' => 'POST', 'permission_callback' => 'sac_permission_write',
        'callback' => function ( WP_REST_Request $req ) {
            $ttl_minutes = max( 1, min( (int) ( $req->get_param( 'ttl_minutes' ) ?: 30 ), 180 ) );
            $max_files = max( 1, min( (int) ( $req->get_param( 'max_files' ) ?: 5 ), 20 ) );
            $token = wp_generate_password( 40, false );
            $tokens = get_option( SAC_OPTION_UPLOAD_TOKENS, [] );
            $tokens[ $token ] = [ 'expires' => time() + $ttl_minutes * 60, 'max_files' => $max_files, 'used' => 0, 'media_ids' => [] ];
            update_option( SAC_OPTION_UPLOAD_TOKENS, $tokens );
            return rest_ensure_response( [ 'upload_url' => add_query_arg( 'sac_token', $token, home_url( '/sac-upload/' ) ), 'expires_in_minutes' => $ttl_minutes, 'max_files' => $max_files ] );
        },
    ] );

    register_rest_route( SAC_NAMESPACE, '/upload-link/(?P<token>[a-zA-Z0-9]+)', [
        'methods' => 'GET', 'permission_callback' => 'sac_permission_read',
        'callback' => function ( WP_REST_Request $req ) {
            $tokens = get_option( SAC_OPTION_UPLOAD_TOKENS, [] );
            if ( empty( $tokens[ $req['token'] ] ) ) return new WP_Error( 'sac_invalid_token', '連結不存在或已失效', [ 'status' => 404 ] );
            return rest_ensure_response( $tokens[ $req['token'] ] );
        },
    ] );

    register_rest_route( SAC_NAMESPACE, '/posts/(?P<id>\d+)/featured-image', [
        'methods' => 'POST', 'permission_callback' => 'sac_permission_write',
        'callback' => function ( WP_REST_Request $req ) {
            $post_id = (int) $req['id']; $media_id = (int) $req->get_param( 'media_id' );
            if ( ! get_post( $post_id ) || ! get_post( $media_id ) ) return new WP_Error( 'sac_not_found', '文章或媒體不存在', [ 'status' => 404 ] );
            set_post_thumbnail( $post_id, $media_id );
            return rest_ensure_response( [ 'success' => true ] );
        },
    ] );

    register_rest_route( SAC_NAMESPACE, '/media', [
        'methods' => 'GET', 'permission_callback' => 'sac_permission_read',
        'callback' => function ( WP_REST_Request $req ) {
            $limit = min( (int) ( $req->get_param( 'limit' ) ?: 10 ), 50 );
            $search = $req->get_param( 'search' ) ?: '';
            $missing_alt = filter_var( $req->get_param( 'missing_alt' ), FILTER_VALIDATE_BOOLEAN );
            $cache_key = "media_{$search}_{$limit}_" . ( $missing_alt ? 'missing' : 'all' );
            if ( ! $missing_alt ) {
                $cached = sac_cache_get( $cache_key );
                if ( $cached !== false ) return rest_ensure_response( $cached );
            }
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
            $result = array_map( function ( $a ) {
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
            }, $query->posts );
            if ( ! $missing_alt ) sac_cache_set( $cache_key, $result );
            return rest_ensure_response( $result );
        },
    ] );

    register_rest_route( SAC_NAMESPACE, '/media/(?P<id>\d+)/alt', [
        'methods' => 'POST', 'permission_callback' => 'sac_permission_write',
        'args' => [ 'alt_text' => [ 'required' => true, 'type' => 'string' ] ],
        'callback' => function ( WP_REST_Request $req ) {
            $media_id = (int) $req['id'];
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
            $item_id = (int) $req['id'];
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
            $cached = sac_cache_get( $cache_key );
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
            sac_cache_set( $cache_key, $result );
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
            $summary = $req->get_param( 'short_description' ) ?: sac_seo_summary( $req->get_param( 'description' ) ?: '' );
            sac_apply_seo_request( $product_id, $req, true, $req->get_param( 'name' ), $summary );
            sac_apply_image_alt( $req );
            return rest_ensure_response( [ 'success' => true, 'product_id' => $product_id, 'seo' => sac_get_seo_fields( $product_id ) ] );
        },
    ] );

    register_rest_route( SAC_NAMESPACE, '/wc/products/(?P<id>\d+)', [
        'methods' => 'GET', 'permission_callback' => 'sac_permission_read',
        'callback' => function ( WP_REST_Request $req ) {
            if ( ! class_exists( 'WooCommerce' ) ) return new WP_Error( 'sac_no_wc', 'WooCommerce 未啟用', [ 'status' => 400 ] );
            $product = wc_get_product( (int) $req['id'] );
            if ( ! $product ) return new WP_Error( 'sac_not_found', '商品不存在', [ 'status' => 404 ] );
            return rest_ensure_response( sac_format_product( $product ) );
        },
    ] );

    register_rest_route( SAC_NAMESPACE, '/wc/products/(?P<id>\d+)', [
        'methods' => 'POST', 'permission_callback' => 'sac_permission_write',
        'callback' => function ( WP_REST_Request $req ) {
            if ( ! class_exists( 'WooCommerce' ) ) return new WP_Error( 'sac_no_wc', 'WooCommerce 未啟用', [ 'status' => 400 ] );
            $product = wc_get_product( (int) $req['id'] );
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
            $product_id = (int) $req['id'];
            if ( ! wc_get_product( $product_id ) ) return new WP_Error( 'sac_not_found', '商品不存在', [ 'status' => 404 ] );
            wp_trash_post( $product_id );
            return rest_ensure_response( [ 'success' => true, 'product_id' => $product_id, 'note' => '已移入垃圾桶，可在後台還原' ] );
        },
    ] );

    register_rest_route( SAC_NAMESPACE, '/wc/products/(?P<id>\d+)/stock', [
        'methods' => 'POST', 'permission_callback' => 'sac_permission_write', 'args' => [ 'stock_quantity' => [ 'required' => true ] ],
        'callback' => function ( WP_REST_Request $req ) {
            if ( ! class_exists( 'WooCommerce' ) ) return new WP_Error( 'sac_no_wc', 'WooCommerce 未啟用', [ 'status' => 400 ] );
            $product = wc_get_product( (int) $req['id'] );
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
            $cached = sac_cache_get( 'product_categories' );
            if ( $cached !== false ) return rest_ensure_response( $cached );
            $terms = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false ] );
            if ( is_wp_error( $terms ) ) return $terms;
            $result = array_map( fn( $t ) => [ 'id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug, 'count' => $t->count ], $terms );
            sac_cache_set( 'product_categories', $result );
            return rest_ensure_response( $result );
        },
    ] );

    register_rest_route( SAC_NAMESPACE, '/wc/orders-summary', [
        'methods' => 'GET', 'permission_callback' => 'sac_permission_read',
        'callback' => function ( WP_REST_Request $req ) {
            if ( ! class_exists( 'WooCommerce' ) ) return new WP_Error( 'sac_no_wc', 'WooCommerce 未啟用', [ 'status' => 400 ] );
            $reveal = in_array( $req->get_param( 'reveal_pii' ), [ '1', 1, true, 'true' ], true );
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
            $cached = sac_cache_get( $cache_key );
            if ( $cached !== false ) return rest_ensure_response( $cached );
            $products = wc_get_products( [ 'limit' => -1, 'stock_status' => 'instock' ] );
            $low = [];
            foreach ( $products as $p ) {
                $qty = $p->get_stock_quantity();
                if ( $qty !== null && $qty <= $threshold ) $low[] = [ 'id' => $p->get_id(), 'name' => $p->get_name(), 'stock' => $qty ];
            }
            sac_cache_set( $cache_key, $low );
            return rest_ensure_response( $low );
        },
    ] );

    register_rest_route( SAC_NAMESPACE, '/wc/revenue', [
        'methods' => 'GET', 'permission_callback' => 'sac_permission_read',
        'callback' => function ( WP_REST_Request $req ) {
            if ( ! class_exists( 'WooCommerce' ) ) return new WP_Error( 'sac_no_wc', 'WooCommerce 未啟用', [ 'status' => 400 ] );
            $days = (int) ( $req->get_param( 'days' ) ?: 7 );
            $cache_key = "revenue_{$days}";
            $cached = sac_cache_get( $cache_key );
            if ( $cached !== false ) return rest_ensure_response( $cached );
            $orders = wc_get_orders( [ 'status' => [ 'completed', 'processing' ], 'date_created' => '>' . ( time() - $days * DAY_IN_SECONDS ), 'limit' => -1 ] );
            $total = 0; $count = 0;
            foreach ( $orders as $o ) { $total += (float) $o->get_total(); $count++; }
            $result = [ 'days' => $days, 'order_count' => $count, 'total_revenue' => round( $total, 2 ) ];
            sac_cache_set( $cache_key, $result );
            return rest_ensure_response( $result );
        },
    ] );

    register_rest_route( SAC_NAMESPACE, '/form-submissions/unread', [
        'methods' => 'GET', 'permission_callback' => 'sac_permission_read',
        'callback' => function ( WP_REST_Request $req ) {
            $reveal = in_array( $req->get_param( 'reveal_pii' ), [ '1', 1, true, 'true' ], true );
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
            $entry_id = (int) $req['id'];
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
function sac_apply_seo_request( $post_id, WP_REST_Request $request, $creating = false, $title = '', $summary = '' ) {
    $params = [];
    foreach ( [ 'seo_title' => 'title', 'seo_description' => 'description', 'focus_keyword' => 'focus_keyword' ] as $input => $field ) {
        if ( $request->get_param( $input ) !== null ) $params[ $field ] = $request->get_param( $input );
    }
    if ( $creating ) {
        if ( ! isset( $params['title'] ) && $title !== '' ) $params['title'] = $title;
        if ( ! isset( $params['description'] ) && $summary !== '' ) $params['description'] = sac_seo_summary( $summary );
    }
    if ( ! $params ) return false;
    sac_update_seo_fields( $post_id, $params );
    return true;
}

function sac_apply_image_alt( WP_REST_Request $request ) {
    if ( $request->get_param( 'image_alt' ) === null || ! $request->get_param( 'image_id' ) ) return false;
    $image_id = (int) $request->get_param( 'image_id' );
    if ( ! wp_attachment_is_image( $image_id ) ) return false;
    update_post_meta( $image_id, '_wp_attachment_image_alt', sanitize_text_field( $request->get_param( 'image_alt' ) ) );
    return true;
}

/** SEO Core is the first-party source of truth when its module is enabled. */
function sac_has_wumetax_seo_core() {
    return class_exists( 'Wumetax_SEO_Core_v120' );
}

function sac_get_seo_fields( $post_id ) {
    if ( sac_has_wumetax_seo_core() ) {
        return [
            'post_id'        => $post_id,
            'title'          => (string) get_post_meta( $post_id, '_wu_seo_title', true ),
            'description'    => (string) get_post_meta( $post_id, '_wu_seo_description', true ),
            'focus_keyword'  => (string) get_post_meta( $post_id, '_sac_focus_keyword', true ),
            'canonical'      => (string) get_post_meta( $post_id, '_wu_seo_canonical', true ),
            'noindex'        => (bool) get_post_meta( $post_id, '_wu_seo_noindex', true ),
            'nofollow'       => (bool) get_post_meta( $post_id, '_wu_seo_nofollow', true ),
            'og_title'       => (string) get_post_meta( $post_id, '_wu_seo_og_title', true ),
            'og_description' => (string) get_post_meta( $post_id, '_wu_seo_og_desc', true ),
            'og_image_id'    => (int) get_post_meta( $post_id, '_wu_seo_og_image', true ),
            'source'         => 'wumetax_seo_core',
        ];
    }

    $has_seo_plugin = defined( 'WPSEO_VERSION' ) || class_exists( 'RankMath' );
    $description = get_post_meta( $post_id, '_yoast_wpseo_metadesc', true ) ?: get_post_meta( $post_id, 'rank_math_description', true );
    if ( ! $has_seo_plugin && empty( $description ) ) {
        $post = get_post( $post_id );
        $description = $post ? $post->post_excerpt : '';
    }
    return [
        'post_id'       => $post_id,
        'title'         => get_post_meta( $post_id, '_yoast_wpseo_title', true ) ?: get_post_meta( $post_id, 'rank_math_title', true ),
        'description'   => $description,
        'focus_keyword' => get_post_meta( $post_id, '_yoast_wpseo_focuskw', true ) ?: get_post_meta( $post_id, 'rank_math_focus_keyword', true ),
        'og_image_id'   => get_post_meta( $post_id, '_yoast_wpseo_opengraph-image-id', true ),
        'source'        => $has_seo_plugin ? 'seo_plugin' : 'post_excerpt_fallback',
    ];
}

function sac_update_seo_fields( $post_id, $params ) {
    if ( sac_has_wumetax_seo_core() ) {
        $fields = [
            'title'          => [ '_wu_seo_title', 'sanitize_text_field' ],
            'description'    => [ '_wu_seo_description', 'sanitize_textarea_field' ],
            'canonical'      => [ '_wu_seo_canonical', 'esc_url_raw' ],
            'og_title'       => [ '_wu_seo_og_title', 'sanitize_text_field' ],
            'og_description' => [ '_wu_seo_og_desc', 'sanitize_textarea_field' ],
        ];
        foreach ( $fields as $field => $definition ) {
            if ( ! array_key_exists( $field, $params ) ) continue;
            $value = call_user_func( $definition[1], $params[ $field ] );
            if ( '' === $value ) delete_post_meta( $post_id, $definition[0] );
            else update_post_meta( $post_id, $definition[0], $value );
        }
        foreach ( [ 'noindex' => '_wu_seo_noindex', 'nofollow' => '_wu_seo_nofollow' ] as $field => $meta_key ) {
            if ( array_key_exists( $field, $params ) ) update_post_meta( $post_id, $meta_key, ! empty( $params[ $field ] ) ? 1 : 0 );
        }
        if ( array_key_exists( 'og_image_id', $params ) ) update_post_meta( $post_id, '_wu_seo_og_image', absint( $params['og_image_id'] ) );
        if ( array_key_exists( 'focus_keyword', $params ) ) update_post_meta( $post_id, '_sac_focus_keyword', sanitize_text_field( $params['focus_keyword'] ) );
        return;
    }

    $has_seo_plugin = defined( 'WPSEO_VERSION' ) || class_exists( 'RankMath' );
    if ( isset( $params['title'] ) ) {
        update_post_meta( $post_id, '_yoast_wpseo_title', sanitize_text_field( $params['title'] ) );
        update_post_meta( $post_id, 'rank_math_title', sanitize_text_field( $params['title'] ) );
    }
    if ( isset( $params['description'] ) ) {
        $desc = sanitize_text_field( $params['description'] );
        update_post_meta( $post_id, '_yoast_wpseo_metadesc', $desc );
        update_post_meta( $post_id, 'rank_math_description', $desc );
        if ( ! $has_seo_plugin && get_post_type( $post_id ) === 'post' && ! get_post_field( 'post_excerpt', $post_id ) ) {
            wp_update_post( [ 'ID' => $post_id, 'post_excerpt' => $desc ] );
        }
    }
    if ( isset( $params['focus_keyword'] ) ) {
        update_post_meta( $post_id, '_yoast_wpseo_focuskw', sanitize_text_field( $params['focus_keyword'] ) );
        update_post_meta( $post_id, 'rank_math_focus_keyword', sanitize_text_field( $params['focus_keyword'] ) );
    }
    if ( isset( $params['og_image_id'] ) ) update_post_meta( $post_id, '_yoast_wpseo_opengraph-image-id', (int) $params['og_image_id'] );
}

function sac_sync_faq_schema( $post_id, $faqs ) {
    if ( empty( $faqs ) ) { delete_post_meta( $post_id, '_sac_faq_schema' ); return; }
    $schema = [ '@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map( fn( $f ) => [ '@type' => 'Question', 'name' => $f['question'], 'acceptedAnswer' => [ '@type' => 'Answer', 'text' => wp_strip_all_tags( $f['answer'] ) ] ], $faqs ) ];
    update_post_meta( $post_id, '_sac_faq_schema', wp_json_encode( $schema ) );
}

add_action( 'wp_head', function () {
    if ( ! is_singular( 'post' ) ) return;
    $schema = get_post_meta( get_the_ID(), '_sac_faq_schema', true );
    if ( $schema ) echo '<script type="application/ld+json">' . $schema . '</script>' . "\n";
} );

add_filter( 'the_content', function ( $content ) {
    if ( ! is_singular( 'post' ) || ! in_the_loop() || ! is_main_query() ) return $content;
    $faqs = get_post_meta( get_the_ID(), '_sac_faq_list', true );
    if ( empty( $faqs ) ) return $content;
    $html = '<div class="sac-faq-block"><h2>常見問題</h2>';
    foreach ( $faqs as $f ) $html .= '<div class="sac-faq-item"><h3>' . esc_html( $f['question'] ) . '</h3><div>' . wp_kses_post( $f['answer'] ) . '</div></div>';
    return $content . $html . '</div>';
} );

/**
 * ============================================================
 * 9. 一次性圖片上傳頁面
 * ============================================================
 */
add_action( 'init', function () {
    add_rewrite_rule( '^sac-upload/?$', 'index.php?sac_upload_page=1', 'top' );
    add_rewrite_tag( '%sac_upload_page%', '1' );
} );

add_filter( 'query_vars', function ( $vars ) { $vars[] = 'sac_upload_page'; return $vars; } );

add_action( 'template_redirect', function () {
    if ( ! get_query_var( 'sac_upload_page' ) ) return;

    $token = sanitize_text_field( $_GET['sac_token'] ?? '' );
    $tokens = get_option( SAC_OPTION_UPLOAD_TOKENS, [] );
    if ( empty( $tokens[ $token ] ) || $tokens[ $token ]['expires'] < time() ) {
        wp_die( '上傳連結已失效或不存在，請重新向 AI 索取新連結。' );
    }
    $info = $tokens[ $token ];

    if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) === 'POST' && ! empty( $_FILES['sac_files'] ) ) {
        if ( ! isset( $_POST['sac_upload_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['sac_upload_nonce'] ) ), 'sac_upload_' . $token ) ) {
            wp_die( '上傳驗證失敗，請重新開啟連結。' );
        }
        if ( $info['used'] >= $info['max_files'] ) wp_die( '此連結上傳次數已達上限。' );
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $uploaded_ids = [];
        $files = $_FILES['sac_files'];
        $count = min( count( (array) $files['name'] ), $info['max_files'] - $info['used'] );
        for ( $i = 0; $i < $count; $i++ ) {
            if ( empty( $files['name'][ $i ] ) ) continue;
            $checked = wp_check_filetype_and_ext( $files['tmp_name'][ $i ], $files['name'][ $i ] );
            if ( empty( $checked['type'] ) || strpos( $checked['type'], 'image/' ) !== 0 ) continue;
            $_FILES['sac_single'] = [ 'name' => $files['name'][ $i ], 'type' => $checked['type'], 'tmp_name' => $files['tmp_name'][ $i ], 'error' => $files['error'][ $i ], 'size' => $files['size'][ $i ] ];
            $attachment_id = media_handle_upload( 'sac_single', 0 );
            if ( ! is_wp_error( $attachment_id ) ) $uploaded_ids[] = $attachment_id;
        }
        $tokens[ $token ]['used'] += count( $uploaded_ids );
        $tokens[ $token ]['media_ids'] = array_merge( $info['media_ids'], $uploaded_ids );
        update_option( SAC_OPTION_UPLOAD_TOKENS, $tokens );
        echo '<h2>上傳完成，共 ' . count( $uploaded_ids ) . ' 張圖片</h2><p>可以關閉此頁面，回到 AI 對話繼續操作。</p>';
        exit;
    }
    ?>
    <!DOCTYPE html>
    <html lang="zh-Hant"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>圖片上傳</title></head>
    <body style="font-family:sans-serif;max-width:480px;margin:40px auto;padding:0 16px;">
        <h2>一次性圖片上傳</h2>
        <p>剩餘可上傳張數：<?php echo (int) ( $info['max_files'] - $info['used'] ); ?></p>
        <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field( 'sac_upload_' . $token, 'sac_upload_nonce' ); ?>
            <input type="file" name="sac_files[]" accept="image/*" multiple required style="display:block;margin-bottom:12px;">
            <button type="submit" style="padding:10px 20px;">上傳</button>
        </form>
    </body></html>
    <?php
    exit;
} );

/** AI 工具已拆分為獨立卡片，連接器只管理連線設定；既有功能一律保持可用。 */
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
            $ids = get_posts( [ 'post_type' => 'shop_coupon', 'post_status' => 'publish', 'posts_per_page' => $limit, 'fields' => 'ids', 'no_found_rows' => true ] );
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
            if ( wc_get_coupon_id_by_code( $code ) ) return sac_coupon_error( '優惠券代碼已存在' );
            try {
                $coupon = new WC_Coupon();
                $coupon->set_code( $code );
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
            $id = (int) $req['id'];
            if ( get_post_type( $id ) !== 'shop_coupon' || get_post_status( $id ) === 'trash' ) return new WP_Error( 'sac_not_found', '優惠券不存在', [ 'status' => 404 ] );
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
            $id = (int) $req['id'];
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

function sac_internal_key() {
    static $key = null;
    if ( $key === null ) $key = 'sac_internal_' . wp_generate_password( 64, false, false );
    return $key;
}
