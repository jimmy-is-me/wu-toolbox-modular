<?php
/** Isolated regression tests for TranslatePress text discovery and dictionary writes. */
define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );

function register_activation_hook( ...$args ) {}
function register_deactivation_hook( ...$args ) {}
function add_filter( ...$args ) {}
function add_action( ...$args ) {}
function get_locale() { return 'zh_TW'; }
function get_option( $key, $default = false ) { return $GLOBALS['options'][ $key ] ?? $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['options'][ $key ] = $value; return true; }
function current_time( $type ) { return '2026-10-04 00:00:00'; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_strip_all_tags( $value ) { return strip_tags( $value ); }
function get_post( $id ) { return (object) array( 'post_title' => ! empty( $GLOBALS['scan_fixture'] ) ? '文章 ' . $id : 'Favicon 工具箱', 'post_excerpt' => '', 'post_content' => '' ); }
function get_the_title( $id ) { return "<trp-post-container data-trp-post-id='1733'>Favicon 工具箱</trp-post-container>"; }
function apply_filters( $hook, $value ) { return $value; }
function get_permalink( $id ) { return ! empty( $GLOBALS['scan_fixture'] ) ? 'https://example.test/' . ( array( 1 => 'first', 2 => 'second', 3 => 'single' )[ $id ] ?? 'other' ) . '/' : false; }
function get_post_types( $args, $output ) { return array( 'post' => 'post' ); }
function wp_http_validate_url( $url ) { return true; }
function add_query_arg( $key, $value, $url ) { return $url . '?scan=' . $value; }
function home_url( $path ) { return 'https://example.test' . $path; }
function is_wp_error( $response ) { return false; }
function wp_remote_retrieve_response_code( $response ) { return $response['code']; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
function wp_remote_get( $url, $args ) {
    $GLOBALS['remote_requests']++;
    if ( false !== strpos( $url, '/single/' ) ) {
        $GLOBALS['wpdb']->rows[] = dictionary_row( 2, '<span>單頁新增字典</span>', '', 0 );
        return array( 'code' => 200, 'body' => '<body><span>單頁新增字典</span></body>' );
    }
    if ( false !== strpos( $url, '/second/' ) ) {
        $GLOBALS['wpdb']->rows[] = dictionary_row( 1, '<span>第一頁動態中文</span>', '', 0 );
        return array( 'code' => 200, 'body' => '<body><p>第二頁內容</p></body>' );
    }
    return array( 'code' => 200, 'body' => '<body><span>第一頁動態中文</span></body>' );
}

class WP_Query {
    public $posts = array( 1, 2 );
    public function __construct( $args ) {}
}
class TRP_Translate_Press {
    public static function get_trp_instance() { return new self(); }
    public function get_component( $name ) {
        if ( 'settings' === $name ) {
            return new class {
                public function get_settings() { return array( 'default-language' => 'zh_TW', 'publish-languages' => array( 'zh_TW', 'en_US' ) ); }
            };
        }
        return new class {
            public function get_table_name( $target, $source ) { return 'wp_trp_dictionary_zh_tw_en_us'; }
        };
    }
}
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }

class WU_AIT_Test_Wpdb {
    public $prefix = 'wp_';
    public $last_error = '';
    public $rows = array();

    public function prepare( $query, ...$args ) {
        return vsprintf( str_replace( '%s', "'%s'", $query ), $args );
    }

    public function get_var( $query ) {
        return 'wp_trp_dictionary_zh_tw_en_us';
    }

    public function get_col( $query, $column = 0 ) {
        if ( false !== strpos( $query, 'SHOW COLUMNS' ) ) {
            return array( 'id', 'original', 'translated', 'status', 'block_type' );
        }
        return array_column( $this->rows, 'original' );
    }

    public function get_results( $query, $format = null ) {
        return array_values( $this->rows );
    }

    public function update( $table, $data, $where, $format = null, $where_format = null ) {
        foreach ( $this->rows as &$row ) {
            if ( (int) $row['id'] === (int) $where['id'] ) {
                $row = array_merge( $row, $data );
                return 1;
            }
        }
        return false;
    }

    public function insert( $table, $data, $format = null ) {
        $ids = array_column( $this->rows, 'id' );
        $data['id'] = $ids ? max( $ids ) + 1 : 1;
        $this->rows[] = $data;
        return 1;
    }
}

$GLOBALS['options'] = array();
$GLOBALS['wpdb'] = new WU_AIT_Test_Wpdb();
require __DIR__ . '/../modules/ai-translate-translatepress/module.php';

function check( $condition, $message ) {
    if ( ! $condition ) {
        throw new RuntimeException( $message );
    }
}

function dictionary_row( $id, $original, $translated, $block_type ) {
    return array(
        'id' => $id,
        'original' => $original,
        'translated' => $translated,
        'status' => '' === $translated ? 0 : 1,
        'block_type' => $block_type,
    );
}

function row_by_id( $id ) {
    foreach ( $GLOBALS['wpdb']->rows as $row ) {
        if ( (int) $row['id'] === $id ) {
            return $row;
        }
    }
    throw new RuntimeException( 'Missing dictionary row ' . $id );
}

// Text split by inline markup remains discoverable as a complete visual unit.
$html = '<main><h1>10GB <span>備援空間</span></h1>'
    . '<img alt="雲端備份"><input placeholder="搜尋網站知識…" aria-label="搜尋文章">'
    . '<input type="submit" value="送出詢問"><input type="text" value="訪客輸入不可收集">'
    . '<script>const label="不可見腳本";</script><style>.x:before{content:"不可見樣式"}</style>'
    . '<svg><text>不可見圖示</text></svg></main>';
$segments = WU_AIT_TranslatePress_Bridge::extract_translatable_segments( $html );
check( in_array( '備援空間', $segments, true ), 'Inline Chinese text must be discovered' );
if ( class_exists( 'DOMDocument' ) ) {
    check( in_array( '10GB 備援空間', $segments, true ), 'Combined inline heading must be discovered' );
    foreach ( array( '雲端備份', '搜尋網站知識…', '搜尋文章', '送出詢問' ) as $attribute_text ) {
        check( in_array( $attribute_text, $segments, true ), 'Visible attribute missing: ' . $attribute_text );
    }
    check( ! in_array( '訪客輸入不可收集', $segments, true ), 'Text input value must not be harvested' );
}
foreach ( array( '不可見腳本', '不可見樣式', '不可見圖示' ) as $non_visible ) {
    check( ! in_array( $non_visible, $segments, true ), 'Non-visible code leaked into discovery: ' . $non_visible );
}
check( WU_AIT_TranslatePress_Bridge::is_translatable_candidate( '您好 {name}: 請查看帳單' ), 'Placeholder followed by a colon is translatable prose' );
check( ! WU_AIT_TranslatePress_Bridge::is_translatable_candidate( '.hero { color: red; }' ), 'CSS declarations must remain excluded' );

$post_segments = WU_AIT_TranslatePress_Bridge::get_translatable_segments_for_post( 1733 );
check( in_array( 'Favicon 工具箱', $post_segments, true ), 'Stored post title must be discovered' );
check( ! in_array( "<trp-post-container data-trp-post-id='1733'>Favicon 工具箱</trp-post-container>", $post_segments, true ), 'Filtered post-title wrapper must not become an original string' );

$plain = 'Favicon 工具箱';
$translation = 'Favicon Toolbox';
$active_wrapper = "<trp-post-container data-trp-post-id='1733'>{$plain}</trp-post-container>";
$regular_wrapper = "<trp-post-container data-trp-post-id='1734'>{$plain}</trp-post-container>";
$already_translated_wrapper = "<trp-post-container data-trp-post-id='1735'>{$plain}</trp-post-container>";
$deprecated_wrapper = "<trp-post-container data-trp-post-id='1736'>{$plain}</trp-post-container>";
$GLOBALS['wpdb']->rows = array(
    dictionary_row( 1, $plain, '', 0 ),
    dictionary_row( 2, $active_wrapper, '', 1 ),
    dictionary_row( 3, $regular_wrapper, '', 0 ),
    dictionary_row( 4, $already_translated_wrapper, '<trp-post-container data-trp-post-id="1735">Existing title</trp-post-container>', 1 ),
    dictionary_row( 5, $deprecated_wrapper, '', 2 ),
);

$written = WU_AIT_TranslatePress_Bridge::upsert_translations_by_original( 'en_US', array( $plain => $translation ) );
check( 1 === $written, 'One source string should be counted as written' );
check( $translation === row_by_id( 1 )['translated'], 'Regular string should receive the translation' );
check( "<trp-post-container data-trp-post-id='1733'>{$translation}</trp-post-container>" === row_by_id( 2 )['translated'], 'Active wrapper must retain its HTML tags and attributes' );
check( "<trp-post-container data-trp-post-id='1734'>{$translation}</trp-post-container>" === row_by_id( 3 )['translated'], 'Regular wrapper must not be replaced by plain text' );
check( '<trp-post-container data-trp-post-id="1735">Existing title</trp-post-container>' === row_by_id( 4 )['translated'], 'Existing wrapper translation must not be overwritten indirectly' );
check( '' === row_by_id( 5 )['translated'], 'Deprecated TranslatePress block must not be updated' );

WU_AIT_TranslatePress_Bridge::upsert_translations_by_original( 'en_US', array(
    $already_translated_wrapper => '<trp-post-container data-trp-post-id="1735">Updated title</trp-post-container>',
) );
check( '<trp-post-container data-trp-post-id="1735">Updated title</trp-post-container>' === row_by_id( 4 )['translated'], 'An explicit exact wrapper translation should update that wrapper' );

// A later front-end render may register a dictionary row for an earlier post.
// The second pass must match against the dictionary after every page is rendered.
$GLOBALS['scan_fixture'] = true;
$GLOBALS['remote_requests'] = 0;
$GLOBALS['wpdb']->rows = array();
$scan = WU_AIT_Discovery_Service::discover_sitewide();
$originals = array_column( $GLOBALS['options'][ WU_AIT_Discovery_Service::OPTION_KEY ], 'original' );
check( 2 === $scan['scanned_posts'], 'Both public posts must be scanned' );
check( 2 === $GLOBALS['remote_requests'], 'Two-pass scan must not fetch each page twice' );
check( in_array( '<span>第一頁動態中文</span>', $originals, true ), 'A dictionary string registered by a later render must match an earlier page' );
$single = WU_AIT_TranslatePress_Bridge::get_dictionary_originals_for_post( 3, 'en_US' );
check( in_array( '<span>單頁新增字典</span>', $single, true ), 'Single-post dictionary matching must follow its front-end render' );

echo "AI TranslatePress regression tests passed.\n";
