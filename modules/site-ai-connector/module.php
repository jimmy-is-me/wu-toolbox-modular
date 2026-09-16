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
    add_menu_page( 'AI 連接器設定', 'AI 連接器', 'manage_options', 'site-ai-connector', 'sac_render_admin_page', 'dashicons-admin-generic', 80 );
} );

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
            'summary' => '修改文章標題／正文／狀態', 'description' => '修改指定文章 ID 的標題、正文或狀態（需讀寫金鑰）。',
            'scenario' => '確認現況後要更新內容或發佈草稿', 'prompt' => '把文章 123 的標題改成「中秋送禮推薦」，內文先不要動',
            'need_write' => true, 'available' => true,
            'params' => [
                'post_id' => [ 'type' => 'integer', 'in' => 'path', 'required' => true ],
                'title'   => [ 'type' => 'string', 'in' => 'body' ],
                'content' => [ 'type' => 'string', 'in' => 'body' ],
                'status'  => [ 'type' => 'string', 'in' => 'body', 'description' => 'publish 或 draft，不填則維持原狀態' ],
            ],
        ],
        [
            'name' => 'create_post', 'group' => '文章與 FAQ', 'method' => 'POST', 'path' => '/posts',
            'summary' => '新增文章草稿', 'description' => '建立一篇新文章，預設狀態為草稿（需讀寫金鑰）。',
            'scenario' => '想請 AI 先擬一篇新文章草稿，之後自己審核發佈', 'prompt' => '幫我寫一篇「中秋節送禮推薦」部落格文章，約 800 字，語氣自然、使用繁體中文；先建立草稿，不要直接發佈',
            'need_write' => true, 'available' => true,
            'params' => [
                'title'   => [ 'type' => 'string', 'in' => 'body', 'required' => true ],
                'content' => [ 'type' => 'string', 'in' => 'body' ],
                'status'  => [ 'type' => 'string', 'in' => 'body', 'description' => '預設 draft，可填 publish' ],
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
            'summary' => '檢查 SEO 設定', 'description' => '讀取指定文章的 SEO 標題、描述、焦點關鍵字。若未安裝 Yoast/RankMath，改用文章摘要作為描述備援。',
            'scenario' => '想知道某篇文章的搜尋標題、描述、焦點關鍵字', 'prompt' => '文章 123 的 SEO 標題和描述目前寫什麼？',
            'need_write' => false, 'available' => true,
            'params' => [ 'post_id' => [ 'type' => 'integer', 'in' => 'path', 'required' => true ] ],
        ],
        [
            'name' => 'update_post_seo', 'group' => 'SEO', 'method' => 'POST', 'path' => '/posts/{post_id}/seo',
            'summary' => '修改 SEO 欄位', 'description' => '修改指定文章的 SEO 標題、描述、焦點關鍵字（需讀寫金鑰）。',
            'scenario' => '要更新搜尋標題或描述，提高點擊率', 'prompt' => '幫文章 123 寫一段適合台灣讀者的 SEO 描述，先讓我看內容，再更新',
            'need_write' => true, 'available' => true,
            'params' => [
                'post_id'       => [ 'type' => 'integer', 'in' => 'path', 'required' => true ],
                'title'         => [ 'type' => 'string', 'in' => 'body' ],
                'description'   => [ 'type' => 'string', 'in' => 'body' ],
                'focus_keyword' => [ 'type' => 'string', 'in' => 'body' ],
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
            'summary' => '搜尋媒體庫現有圖片', 'description' => '依關鍵字搜尋媒體庫中現有的圖片，不需要重新上傳。',
            'scenario' => '想用網站裡已經有的圖片，而不是每次都重新上傳', 'prompt' => '在媒體庫找找看有沒有「中秋禮盒」的照片',
            'need_write' => false, 'available' => true,
            'params' => [
                'search' => [ 'type' => 'string', 'in' => 'query', 'description' => '關鍵字搜尋（比對檔名與標題）' ],
                'limit'  => [ 'type' => 'integer', 'in' => 'query', 'description' => '回傳數量上限，預設 10，最大 50' ],
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
            'summary' => '新增商品', 'description' => '建立新的 WooCommerce 商品，可指定名稱、說明、價格、庫存、狀態、分類（需讀寫金鑰）。預設 draft，可指定 publish 直接上架。',
            'scenario' => '想請 AI 幫忙快速建立一筆新商品，之後自己審核上架', 'prompt' => '幫我新增「中秋綜合禮盒」，售價 880 元、庫存 20 盒，先存成草稿',
            'need_write' => true, 'available' => $has_wc,
            'params' => [
                'name'              => [ 'type' => 'string', 'in' => 'body', 'required' => true, 'description' => '商品名稱' ],
                'description'       => [ 'type' => 'string', 'in' => 'body', 'description' => '完整說明（支援 HTML）' ],
                'short_description' => [ 'type' => 'string', 'in' => 'body', 'description' => '簡短說明' ],
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
            'summary' => '修改商品', 'description' => '修改指定商品的名稱、說明、價格、庫存、狀態、分類、主圖，只更新有帶入的欄位（需讀寫金鑰）。',
            'scenario' => '確認現況後要調整價格、補貨或上下架', 'prompt' => '商品 88 補貨 20 件了，請先確認現有資料，再把庫存設為 20',
            'need_write' => true, 'available' => $has_wc,
            'params' => [
                'product_id'        => [ 'type' => 'integer', 'in' => 'path', 'required' => true ],
                'name'              => [ 'type' => 'string', 'in' => 'body' ],
                'description'       => [ 'type' => 'string', 'in' => 'body' ],
                'short_description' => [ 'type' => 'string', 'in' => 'body' ],
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
                $body_props[ $key ] = [ 'type' => $p['type'] ];
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
        'info'    => [ 'title' => get_bloginfo( 'name' ) . ' AI Connector', 'version' => '2.4.1' ],
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
function sac_mcp_tools_list() {
    $defs = array_filter( sac_get_tool_definitions(), fn( $d ) => $d['available'] );
    $tools = [];
    foreach ( $defs as $d ) {
        $properties = []; $required = [];
        foreach ( $d['params'] as $key => $p ) {
            $properties[ $key ] = [ 'type' => $p['type'], 'description' => $p['description'] ?? $d['summary'] ];
            if ( ! empty( $p['required'] ) ) $required[] = $key;
        }
        $tools[] = [ 'name' => $d['name'], 'description' => $d['description'] . ( $d['need_write'] ? '（此操作會修改網站內容，需讀寫權限）' : '（唯讀查詢）' ), 'inputSchema' => [ 'type' => 'object', 'properties' => $properties, 'required' => $required ] ];
    }
    return $tools;
}

function sac_mcp_call_tool( $tool_name, $args, $auth_level ) {
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

    $response = rest_do_request( $request );
    if ( $response->is_error() ) return $response->as_error();
    return $response->get_data();
}

add_action( 'rest_api_init', function () {

    register_rest_route( SAC_NAMESPACE, '/mcp', [
        'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => 'sac_handle_mcp_request',
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

    $auth_header = $request->get_header( 'authorization' );
    $key = $request->get_header( 'x-sac-key' );
    if ( ! $key && $auth_header && stripos( $auth_header, 'bearer ' ) === 0 ) {
        $key = substr( $auth_header, 7 );
    }
    $keys = get_option( SAC_OPTION_KEYS, [] );
    $auth_level = isset( $keys[ $key ] ) ? sac_get_key_level( $keys[ $key ] ) : null;

    if ( $method === 'initialize' ) {
        return rest_ensure_response( [
            'jsonrpc' => '2.0', 'id' => $id,
            'result'  => [ 'protocolVersion' => '2024-11-05', 'capabilities' => [ 'tools' => new stdClass() ], 'serverInfo' => [ 'name' => get_bloginfo( 'name' ) . ' AI Connector', 'version' => '2.4.1' ] ],
        ] );
    }

    if ( $method === 'tools/list' ) {
        if ( ! $auth_level ) return rest_ensure_response( [ 'jsonrpc' => '2.0', 'id' => $id, 'error' => [ 'code' => 401, 'message' => '缺少或無效的 API 金鑰' ] ] );
        return rest_ensure_response( [ 'jsonrpc' => '2.0', 'id' => $id, 'result' => [ 'tools' => sac_mcp_tools_list() ] ] );
    }

    if ( $method === 'tools/call' ) {
        if ( ! $auth_level ) return rest_ensure_response( [ 'jsonrpc' => '2.0', 'id' => $id, 'error' => [ 'code' => 401, 'message' => '缺少或無效的 API 金鑰' ] ] );
        $tool_name = $body['params']['name'] ?? '';
        $args = $body['params']['arguments'] ?? [];
        $result = sac_mcp_call_tool( $tool_name, $args, $auth_level );
        if ( is_wp_error( $result ) ) return rest_ensure_response( [ 'jsonrpc' => '2.0', 'id' => $id, 'error' => [ 'code' => 400, 'message' => $result->get_error_message() ] ] );

        // 只記錄「寫入」動作，唯讀查詢不寫入紀錄，避免操作紀錄快速膨脹
        $defs = sac_get_tool_definitions();
        foreach ( $defs as $d ) {
            if ( $d['name'] === $tool_name && $d['need_write'] ) {
                sac_write_log( $tool_name, $d['summary'], sac_summarize_args( $args ), $key ?: 'unknown' );
                break;
            }
        }
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
        if ( $_POST['sac_action'] === 'save_features' ) {
            update_option( SAC_OPTION_FEATURES, [
                'redirects' => ! empty( $_POST['sac_enable_redirects'] ),
                'coupons' => ! empty( $_POST['sac_enable_coupons'] ),
            ] );
            echo '<div class="notice notice-success"><p>已更新擴充功能設定</p></div>';
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
    $has_write_key = false;
    $first_write_key_masked = '';
    foreach ( $keys as $k => $data ) {
        if ( sac_get_key_level( $data ) === 'write' ) { $has_write_key = true; break; }
    }
    $ip_allowlist = get_option( SAC_OPTION_IP_ALLOWLIST, [ 'enabled' => false, 'ips' => [] ] );
    $tool_display_names = [];
    foreach ( $defs as $d ) { $tool_display_names[ $d['name'] ] = $d['summary']; }
    ?>
    <div class="wrap">
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

        <hr style="margin:30px 0;">

        <h2>擴充功能設定</h2>
        <form method="post">
            <?php wp_nonce_field( 'sac_admin_action' ); ?>
            <input type="hidden" name="sac_action" value="save_features">
            <p><label><input type="checkbox" name="sac_enable_redirects" value="1" <?php checked( sac_feature_enabled( 'redirects' ) ); ?>> 啟用轉址管理</label></p>
            <p><label><input type="checkbox" name="sac_enable_coupons" value="1" <?php checked( sac_feature_enabled( 'coupons' ) ); ?>> 啟用 WooCommerce 優惠券管理</label></p>
            <p><button class="button button-primary">儲存設定</button></p>
        </form>
        <p>轉址規則可透過 AI 工具新增或刪除。停用功能時，既有規則會保留，但不再執行轉址。</p>
        <hr style="margin:30px 0;">

        <h2>常用指令：直接複製貼給 AI</h2>
        <p>先用生活化的說法交辦即可。涉及文章、商品或優惠券時，先請 AI 查出正確編號；發佈或停用前再確認內容。</p>
        <?php
        $sac_common_prompts = [
            [ 'title' => '寫部落格文章', 'text' => '幫我寫一篇「中秋節送禮推薦」部落格文章，約 800 字，用台灣常用的繁體中文，語氣自然，先建立草稿，不要直接發佈。' ],
            [ 'title' => '更新舊文章', 'text' => '搜尋網站裡關於「送禮」的文章，列出標題和編號；我選好後，幫我補充最新內容，原有重點要保留。' ],
            [ 'title' => '補 SEO 描述', 'text' => '找出已發佈但還沒填 SEO 描述的文章，先列出前 10 篇；我指定文章後，幫我擬適合台灣讀者的描述。' ],
            [ 'title' => '補文章常見問題', 'text' => '先讀文章 123 和原有 FAQ，再幫我新增 3 題讀者常問的問題；保留原有題目。' ],
            [ 'title' => '商品補貨', 'text' => '幫我找出庫存剩 5 件以下的商品，列出名稱和庫存；先不要修改，我確認後再補貨。' ],
            [ 'title' => '促銷優惠券', 'text' => '建立優惠券 MID100，訂單滿 1000 元折 100 元，總共限用 50 次；建立前先幫我確認代碼有沒有重複。' ],
        ];
        ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:12px;max-width:1100px;">
        <?php foreach ( $sac_common_prompts as $item ) : ?>
            <div style="background:#fff;border:1px solid #ccd0d4;border-radius:6px;padding:14px;">
                <strong><?php echo esc_html( $item['title'] ); ?></strong>
                <p style="margin-bottom:8px;"><?php echo esc_html( $item['text'] ); ?></p>
                <button type="button" class="button sac-quick-copy" data-prompt="<?php echo esc_attr( $item['text'] ); ?>">複製指令</button>
            </div>
        <?php endforeach; ?>
        </div>
        <script>
        document.querySelectorAll('.sac-quick-copy').forEach(function(btn) {
            btn.addEventListener('click', function() {
                navigator.clipboard.writeText(btn.dataset.prompt).then(function() {
                    var old = btn.textContent; btn.textContent = '已複製';
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
                            <code style="cursor:pointer;display:block;padding:8px 10px;background:#f6f7f7;border:1px dashed #ccc;border-radius:4px;" onclick="navigator.clipboard.writeText(this.innerText.replace('📋 ','')); var t=this; var old=t.innerText; t.innerText='✅ 已複製到剪貼簿'; setTimeout(function(){t.innerText=old;},1200);">📋 <?php echo esc_html( $d['prompt'] ); ?></code>
                            <?php else : ?>
                                <span style="color:#999;">此功能尚未啟用</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endforeach; ?>

        <hr style="margin:30px 0;">

        <h2>📜 最近操作紀錄（僅記錄寫入動作）</h2>
        <p style="font-size:13px;color:#666;">只有「新增／修改／刪除」這類會改變網站內容的動作才會記錄，單純查詢不會顯示在這裡，讓紀錄更精簡好讀。</p>
        <table class="widefat striped" style="max-width:1100px;">
            <thead><tr><th style="width:16%;">時間</th><th style="width:16%;">動作</th><th>詳細內容</th><th style="width:14%;">使用的金鑰</th></tr></thead>
            <tbody>
            <?php if ( empty( $logs ) ) : ?>
                <tr><td colspan="4" style="color:#999;">目前沒有任何寫入操作紀錄</td></tr>
            <?php else : foreach ( $logs as $log ) : ?>
                <tr>
                    <td><?php echo esc_html( $log['time'] ); ?></td>
                    <td><strong><?php echo esc_html( $log['display_name'] ?? $log['action'] ?? '' ); ?></strong></td>
                    <td><?php echo esc_html( $log['detail'] ?? $log['target'] ?? '' ); ?></td>
                    <td><code><?php echo esc_html( $log['key_prefix'] ); ?></code></td>
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
    <?php
}

// 寫入操作紀錄：只在真正修改資料時呼叫，包含好讀的動作名稱與參數摘要
function sac_write_log( $tool_name, $display_name, $detail, $key ) {
    $logs = get_option( SAC_LOG_OPTION, [] );
    $logs[] = [
        'time'         => current_time( 'Y-m-d H:i:s' ),
        'action'       => $tool_name,
        'display_name' => $display_name,
        'detail'       => $detail,
        'key_prefix'   => substr( $key, 0, 10 ) . '...',
    ];
    if ( count( $logs ) > SAC_LOG_MAX ) $logs = array_slice( $logs, -SAC_LOG_MAX );
    update_option( SAC_LOG_OPTION, $logs );
}

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
            sac_write_log( 'update_post_faq', '整組覆蓋 FAQ', "文章#{$post_id}，共 " . count( $clean ) . ' 題', $req->get_header( 'x-sac-key' ) );
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
            $post_id = wp_insert_post( [
                'post_title'   => sanitize_text_field( $req->get_param( 'title' ) ),
                'post_content' => wp_kses_post( $req->get_param( 'content' ) ?: '' ),
                'post_status'  => sanitize_text_field( $req->get_param( 'status' ) ?: 'draft' ),
                'post_type'    => 'post',
            ], true );
            if ( is_wp_error( $post_id ) ) return $post_id;
            sac_write_log( 'create_post', '新增文章草稿', "文章#{$post_id}：" . sanitize_text_field( $req->get_param( 'title' ) ), $req->get_header( 'x-sac-key' ) );
            return rest_ensure_response( [ 'success' => true, 'post_id' => $post_id ] );
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
            if ( $req->get_param( 'status' ) !== null ) { $update['post_status'] = sanitize_text_field( $req->get_param( 'status' ) ); $changed_fields[] = '狀態'; }
            $result = wp_update_post( $update, true );
            if ( is_wp_error( $result ) ) return $result;
            sac_write_log( 'update_post', '修改文章', "文章#{$post_id}，更新：" . implode( '、', $changed_fields ), $req->get_header( 'x-sac-key' ) );
            return rest_ensure_response( [ 'success' => true, 'post_id' => $post_id ] );
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
            sac_write_log( 'update_post_seo', '修改 SEO', "文章#{$post_id}", $req->get_header( 'x-sac-key' ) );
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

            $has_seo_plugin = defined( 'WPSEO_VERSION' ) || class_exists( 'RankMath' );
            $query = new WP_Query( [
                'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 100,
                'no_found_rows' => true, 'update_post_term_cache' => false,
                'update_post_meta_cache' => $has_seo_plugin, // 只有需要讀 meta 時才預載
                'fields' => $has_seo_plugin ? 'all' : 'all',
            ] );
            $missing = [];
            foreach ( $query->posts as $p ) {
                $desc = $has_seo_plugin
                    ? ( get_post_meta( $p->ID, '_yoast_wpseo_metadesc', true ) ?: get_post_meta( $p->ID, 'rank_math_description', true ) )
                    : $p->post_excerpt;
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
            sac_write_log( 'create_upload_link', '產生上傳連結', "效期 {$ttl_minutes} 分鐘，上限 {$max_files} 張", $req->get_header( 'x-sac-key' ) );
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
            sac_write_log( 'set_featured_image', '設定精選圖片', "文章#{$post_id} → 媒體#{$media_id}", $req->get_header( 'x-sac-key' ) );
            return rest_ensure_response( [ 'success' => true ] );
        },
    ] );

    register_rest_route( SAC_NAMESPACE, '/media', [
        'methods' => 'GET', 'permission_callback' => 'sac_permission_read',
        'callback' => function ( WP_REST_Request $req ) {
            $limit = min( (int) ( $req->get_param( 'limit' ) ?: 10 ), 50 );
            $search = $req->get_param( 'search' ) ?: '';
            $cache_key = "media_{$search}_{$limit}";
            $cached = sac_cache_get( $cache_key );
            if ( $cached !== false ) return rest_ensure_response( $cached );
            $query = new WP_Query( [
                'post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'image',
                's' => $search, 'posts_per_page' => $limit, 'orderby' => 'date', 'order' => 'DESC', 'no_found_rows' => true,
            ] );
            $result = array_map( fn( $a ) => [
                'id' => $a->ID, 'title' => get_the_title( $a ), 'url' => wp_get_attachment_url( $a->ID ),
                'thumbnail' => wp_get_attachment_image_url( $a->ID, 'thumbnail' ), 'uploaded' => $a->post_date,
            ], $query->posts );
            sac_cache_set( $cache_key, $result );
            return rest_ensure_response( $result );
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
            sac_write_log( 'update_menu_item', '修改選單項目', "項目#{$item_id}", $req->get_header( 'x-sac-key' ) );
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
            $product->set_name( sanitize_text_field( $req->get_param( 'name' ) ) );
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
            $product->set_status( sanitize_text_field( $req->get_param( 'status' ) ?: 'draft' ) );
            if ( $req->get_param( 'image_id' ) ) $product->set_image_id( (int) $req->get_param( 'image_id' ) );
            $product_id = $product->save();
            if ( ! $product_id ) return new WP_Error( 'sac_create_failed', '商品建立失敗', [ 'status' => 500 ] );
            $categories = $req->get_param( 'categories' );
            if ( ! empty( $categories ) && is_array( $categories ) ) sac_set_product_categories( $product_id, $categories );
            sac_write_log( 'create_product', '新增商品', "商品#{$product_id}：" . sanitize_text_field( $req->get_param( 'name' ) ), $req->get_header( 'x-sac-key' ) );
            return rest_ensure_response( [ 'success' => true, 'product_id' => $product_id ] );
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
            if ( $req->get_param( 'status' ) !== null ) { $product->set_status( sanitize_text_field( $req->get_param( 'status' ) ) ); $changed_fields[] = '狀態'; }
            if ( $req->get_param( 'image_id' ) !== null ) $product->set_image_id( (int) $req->get_param( 'image_id' ) );
            $product->save();
            $categories = $req->get_param( 'categories' );
            if ( ! empty( $categories ) && is_array( $categories ) ) sac_set_product_categories( $product->get_id(), $categories );
            sac_write_log( 'update_product', '修改商品', "商品#{$product->get_id()}，更新：" . implode( '、', $changed_fields ), $req->get_header( 'x-sac-key' ) );
            return rest_ensure_response( [ 'success' => true, 'product' => sac_format_product( wc_get_product( $product->get_id() ) ) ] );
        },
    ] );

    register_rest_route( SAC_NAMESPACE, '/wc/products/(?P<id>\d+)/delete', [
        'methods' => 'POST', 'permission_callback' => 'sac_permission_write',
        'callback' => function ( WP_REST_Request $req ) {
            if ( ! class_exists( 'WooCommerce' ) ) return new WP_Error( 'sac_no_wc', 'WooCommerce 未啟用', [ 'status' => 400 ] );
            $product_id = (int) $req['id'];
            if ( ! wc_get_product( $product_id ) ) return new WP_Error( 'sac_not_found', '商品不存在', [ 'status' => 404 ] );
            wp_trash_post( $product_id );
            sac_write_log( 'delete_product', '刪除商品（移入垃圾桶）', "商品#{$product_id}", $req->get_header( 'x-sac-key' ) );
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
            sac_write_log( 'set_product_stock', '調整庫存', "商品#{$product->get_id()}，庫存改為 {$qty}", $req->get_header( 'x-sac-key' ) );
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
            sac_write_log( 'mark_form_submission_read', '標記表單已讀', "送件#{$entry_id}", $req->get_header( 'x-sac-key' ) );
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

function sac_get_seo_fields( $post_id ) {
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
    $has_seo_plugin = defined( 'WPSEO_VERSION' ) || class_exists( 'RankMath' );
    if ( isset( $params['title'] ) ) {
        update_post_meta( $post_id, '_yoast_wpseo_title', sanitize_text_field( $params['title'] ) );
        update_post_meta( $post_id, 'rank_math_title', sanitize_text_field( $params['title'] ) );
    }
    if ( isset( $params['description'] ) ) {
        $desc = sanitize_text_field( $params['description'] );
        update_post_meta( $post_id, '_yoast_wpseo_metadesc', $desc );
        update_post_meta( $post_id, 'rank_math_description', $desc );
        if ( ! $has_seo_plugin ) {
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

/** 新功能預設啟用，可在後台停用。 */
function sac_feature_enabled( $feature ) {
    $features = get_option( SAC_OPTION_FEATURES, [ 'redirects' => true, 'coupons' => true ] );
    return ! empty( $features[ $feature ] );
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
            sac_write_log( 'create_redirect', '新增轉址', "{$source} → {$target}", $req->get_header( 'x-sac-key' ) );
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
            sac_write_log( 'delete_redirect', '刪除轉址', $source, $req->get_header( 'x-sac-key' ) );
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
            sac_write_log( 'create_coupon', '新增優惠券', $code, $req->get_header( 'x-sac-key' ) );
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
            sac_write_log( 'update_coupon', '修改優惠券', $coupon->get_code(), $req->get_header( 'x-sac-key' ) );
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
            sac_write_log( 'delete_coupon', '停用優惠券', $code, $req->get_header( 'x-sac-key' ) );
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
