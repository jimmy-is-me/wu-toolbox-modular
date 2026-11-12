<?php
/** On-demand technical checks. Never called while rendering a public sitemap. */
defined( 'ABSPATH' ) || exit;

trait WUTM_SEO_Technical {
    private static function technical_init() {
        add_filter( 'wp_sitemaps_stylesheet_url', [ __CLASS__, 'sitemap_style_url' ] );
        add_filter( 'wp_sitemaps_stylesheet_index_url', [ __CLASS__, 'sitemap_style_url' ] );
        add_filter( 'wp_sitemaps_posts_query_args', [ __CLASS__, 'sitemap_canonical_query' ], 20, 2 );
        add_filter( 'wp_sitemaps_taxonomies_query_args', [ __CLASS__, 'sitemap_canonical_terms' ], 20, 2 );
        add_action( 'admin_menu', [ __CLASS__, 'seo_tools_menu' ] );
        add_action( 'edited_term', [ __CLASS__, 'invalidate_sitemap_terms' ], 10, 3 );
        add_action( 'deleted_term', [ __CLASS__, 'invalidate_sitemap_terms' ], 10, 3 );
        add_action( 'updated_term_meta', [ __CLASS__, 'invalidate_sitemap_term_meta' ], 10, 4 );
        add_action( 'added_term_meta', [ __CLASS__, 'invalidate_sitemap_term_meta' ], 10, 4 );
        add_action( 'deleted_term_meta', [ __CLASS__, 'invalidate_sitemap_term_meta' ], 10, 4 );
    }

    public static function invalidate_sitemap_terms( $id, $tt_id, $taxonomy ) { delete_transient( 'wu_seo_terms_' . md5( $taxonomy ) ); }
    public static function invalidate_sitemap_term_meta( $meta_id, $id, $key, $value ) {
        if ( '_wu_seo_canonical' === $key ) { $term = get_term( $id ); if ( $term && ! is_wp_error( $term ) ) { delete_transient( 'wu_seo_terms_' . md5( $term->taxonomy ) ); } }
    }

    public static function sitemap_url() {
        return function_exists( 'get_sitemap_url' ) ? get_sitemap_url( 'index' ) : home_url( '/wp-sitemap.xml' );
    }

    public static function sitemap_style_url( $url ) {
        return self::output_is_owned_elsewhere() || 'xsl' === get_option( 'wu_seo_sitemap_display', 'xml' ) ? $url : '';
    }

    /** Local URL comparisons only; redirects can be supplied by a redirect plugin. */
    public static function sitemap_canonical_query( $args, $type ) {
        if ( self::output_is_owned_elsewhere() || ! function_exists( 'get_current_blog_id' ) ) { return $args; }
        $key = 'wu_seo_excluded_' . md5( $type );
        $excluded = get_transient( $key );
        if ( false === $excluded ) {
            $excluded = []; $page = 1;
            do {
                $posts = get_posts( [ 'post_type' => $type, 'post_status' => 'publish', 'posts_per_page' => 250, 'paged' => $page++, 'orderby' => 'ID', 'order' => 'ASC', 'meta_key' => '_wu_seo_canonical', 'meta_compare' => 'EXISTS' ] );
                foreach ( $posts as $post ) {
                    $canonical = get_post_meta( $post->ID, '_wu_seo_canonical', true );
                    if ( $canonical && untrailingslashit( $canonical ) !== untrailingslashit( get_permalink( $post ) ) ) { $excluded[] = $post->ID; }
                }
            } while ( count( $posts ) === 250 );
            set_transient( $key, $excluded, 300 );
        }
        $args['post__not_in'] = array_values( array_unique( array_merge( $args['post__not_in'] ?? [], $excluded, (array) apply_filters( 'wu_seo_redirected_post_ids', [], $type ) ) ) );
        return $args;
    }

    public static function sitemap_canonical_terms( $args, $taxonomy ) {
        if ( self::output_is_owned_elsewhere() ) { return $args; }
        $key = 'wu_seo_terms_' . md5( $taxonomy ); $excluded = get_transient( $key );
        if ( false === $excluded ) {
            $excluded = []; $offset = 0;
            do {
                $terms = get_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => false, 'number' => 250, 'offset' => $offset, 'orderby' => 'term_id', 'meta_key' => '_wu_seo_canonical', 'meta_compare' => 'EXISTS' ] );
                if ( is_wp_error( $terms ) ) { break; }
                foreach ( $terms as $term ) {
                    $url = get_term_link( $term ); $canonical = get_term_meta( $term->term_id, '_wu_seo_canonical', true );
                    if ( $canonical && ! is_wp_error( $url ) && untrailingslashit( $canonical ) !== untrailingslashit( $url ) ) { $excluded[] = $term->term_id; }
                }
                $offset += 250;
            } while ( count( $terms ) === 250 );
            set_transient( $key, $excluded, 300 );
        }
        $args['exclude'] = array_unique( array_merge( (array) ( $args['exclude'] ?? [] ), $excluded ) );
        return $args;
    }

    public static function seo_tools_menu() {
        add_submenu_page( 'wu-toolbox-modular', 'SEO 管理與診斷', 'SEO 管理與診斷', 'manage_options', 'wu-seo-tools', [ __CLASS__, 'seo_tools_page' ] );
    }

    /** Same-origin only, WP safe HTTP transport, redirects validated individually. */
    public static function diagnostic_request( $url ) {
        $origin = wp_parse_url( home_url( '/' ) );
        $seen = []; $chain = [];
        for ( $i = 0; $i < 5; $i++ ) {
            $parts = wp_parse_url( $url );
            if ( ! $parts || ! in_array( $parts['scheme'] ?? '', [ 'http', 'https' ], true ) || preg_replace( '/^www\./i', '', strtolower( $parts['host'] ?? '' ) ) !== preg_replace( '/^www\./i', '', strtolower( $origin['host'] ) ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || ( $parts['port'] ?? 0 ) !== ( $origin['port'] ?? 0 ) ) {
                return [ 'url' => $url, 'error' => '網址或轉址不屬於目前網站；為避免存取其他主機已停止。', 'chain' => $chain ];
            }
            if ( isset( $seen[ $url ] ) ) { return [ 'url' => $url, 'error' => '偵測到轉址循環。', 'chain' => $chain ]; }
            $seen[ $url ] = true;
            $response = wp_safe_remote_get( $url, [ 'timeout' => 8, 'redirection' => 0, 'limit_response_size' => 2097152, 'cookies' => [], 'user-agent' => 'WuSEO-Diagnostic/1.0; ' . home_url( '/' ) ] );
            if ( is_wp_error( $response ) ) { return [ 'url' => $url, 'error' => $response->get_error_message(), 'chain' => $chain ]; }
            $status = wp_remote_retrieve_response_code( $response );
            $chain[] = [ 'url' => $url, 'status' => $status ];
            if ( in_array( $status, [ 301, 302, 303, 307, 308 ], true ) ) {
                $next = wp_remote_retrieve_header( $response, 'location' );
                if ( ! $next ) { return [ 'url' => $url, 'error' => '轉址缺少 Location。', 'chain' => $chain ]; }
                $url = WP_Http::make_absolute_url( $next, $url ); continue;
            }
            $body = wp_remote_retrieve_body( $response );
            $result = [ 'url' => $url, 'status' => $status, 'chain' => $chain, 'type' => (string) wp_remote_retrieve_header( $response, 'content-type' ), 'body' => $body, 'robots' => (string) wp_remote_retrieve_header( $response, 'x-robots-tag' ) ];
            $result['challenge'] = (bool) preg_match( '/cf-chl-|challenge-platform|captcha|verify you are human/i', $body );
            $result['cloudflare'] = (bool) wp_remote_retrieve_header( $response, 'cf-ray' );
            if ( strlen( $body ) >= 2097152 ) { $result['error'] = '回應超過 2 MB 診斷上限，未驗證完整內容；請另用伺服器工具驗證。'; }
            return $result;
        }
        return [ 'url' => $url, 'error' => '轉址超過 5 次，已停止。', 'chain' => $chain ];
    }

    public static function inspect_xml( $body ) {
        $out = [ 'valid' => false, 'count' => 0, 'urls' => [], 'issues' => [] ];
        if ( '' === trim( $body ) ) { $out['issues'][] = '回應沒有 XML 內容，請檢查主機與快取。'; return $out; }
        if ( ! preg_match( '//u', $body ) || preg_match( '/encoding=["\'](?!UTF-8)[^"\']+/i', $body ) ) { $out['issues'][] = '內容不是 UTF-8。'; }
        if ( stripos( $body, '<!DOCTYPE' ) !== false || stripos( $body, '<!ENTITY' ) !== false ) { $out['issues'][] = 'XML 包含不允許的 DTD／實體宣告。'; return $out; }
        if ( ! class_exists( 'DOMDocument' ) ) { $out['issues'][] = '主機缺少 DOM XML 擴充，XML 格式尚未驗證。'; return $out; }
        $previous = libxml_use_internal_errors( true );
        $dom = new DOMDocument();
        $loaded = $dom->loadXML( $body, LIBXML_NONET );
        libxml_clear_errors(); libxml_use_internal_errors( $previous );
        if ( ! $loaded ) { $out['issues'][] = 'XML 無法解析；檢查 PHP 警告、HTML 錯誤頁、快取或輸出前空白。'; return $out; }
        $root = $dom->documentElement;
        if ( ! in_array( $root->localName, [ 'sitemapindex', 'urlset' ], true ) || $root->namespaceURI !== 'http://www.sitemaps.org/schemas/sitemap/0.9' ) { $out['issues'][] = 'Sitemap 根元素或 Namespace 不正確。'; return $out; }
        $xp = new DOMXPath( $dom ); $xp->registerNamespace( 's', $root->namespaceURI );
        $out['index'] = 'sitemapindex' === $root->localName;
        $rows = $xp->query( $out['index'] ? '/s:sitemapindex/s:sitemap' : '/s:urlset/s:url' );
        foreach ( $rows as $row ) {
            $loc = trim( $xp->evaluate( 'string(s:loc)', $row ) );
            $out['urls'][] = $loc;
            if ( ! filter_var( $loc, FILTER_VALIDATE_URL ) || ! preg_match( '~^https?://~i', $loc ) ) { $out['issues'][] = '含非絕對 HTTP(S) 網址。'; }
            $lastmod = trim( $xp->evaluate( 'string(s:lastmod)', $row ) );
            if ( $lastmod && ( ! preg_match( '/^\d{4}-\d{2}-\d{2}(?:T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2}))?$/', $lastmod ) || false === strtotime( $lastmod ) || strtotime( $lastmod ) > time() + 86400 ) ) { $out['issues'][] = 'lastmod 格式不正確或位於未來。'; }
        }
        $out['count'] = count( $out['urls'] );
        if ( $out['count'] !== count( array_unique( $out['urls'] ) ) ) { $out['issues'][] = 'XML 含重複網址。'; }
        $out['issues'] = array_values( array_unique( $out['issues'] ) );
        $out['valid'] = ! $out['issues'];
        return $out;
    }

    public static function run_sitemap_diagnostic( $selected = '' ) {
        $url = $selected ?: self::sitemap_url();
        $key = 'wu_seo_http_' . md5( $url );
        $cached = get_transient( $key );
        if ( false !== $cached ) { return $cached; }
        $result = self::diagnostic_request( $url );
        if ( isset( $result['body'] ) ) {
            if ( ! isset( $result['error'] ) ) { $result['xml'] = self::inspect_xml( $result['body'] ); }
            unset( $result['body'] );
        }
        $result['checked_at'] = time();
        set_transient( $key, $result, 300 );
        return $result;
    }

    private static function render_diagnostic( $result ) {
        echo '<section class="wutm-ui-panel"><h3>' . esc_html( $result['url'] ) . '</h3><p>HTTP：' . esc_html( $result['status'] ?? '未完成' ) . '；Content-Type：' . esc_html( $result['type'] ?? '無' ) . '</p>';
        $issues = $result['xml']['issues'] ?? [];
        if ( isset( $result['error'] ) ) { $issues[] = $result['error']; }
        if ( 200 !== ( $result['status'] ?? 0 ) ) { $issues[] = '回應不是 HTTP 200；請檢查主機、轉址及存取限制。'; }
        if ( empty( $result['type'] ) || ! preg_match( '~(?:application|text)/(?:[\w.+-]*\+)?xml(?:;|$)~i', $result['type'] ) ) { $issues[] = '回應類型不是 XML；檢查錯誤頁及快取規則。'; }
        if ( ! empty( $result['challenge'] ) ) { $issues[] = '回應含驗證挑戰；檢查防火牆放行規則。'; }
        if ( ! empty( $result['robots'] ) && stripos( $result['robots'], 'noindex' ) !== false ) { $issues[] = '回應有 X-Robots-Tag: noindex；Sitemap 本身無需建立搜尋索引，請另外檢查其中的內容網址。'; }
        echo '<p>XML 項目數：' . esc_html( $result['xml']['count'] ?? 0 ) . '；檢查時間：' . esc_html( wp_date( 'Y-m-d H:i:s', $result['checked_at'] ) ) . '（結果快取 5 分鐘）</p>';
        echo '<p>' . esc_html( $issues ? implode( ' ', $issues ) : '本次一般 HTTP 與 XML 格式檢查通過。尚未驗證 Googlebot 存取及 Google 收錄。' ) . '</p>';
        if ( ! empty( $result['cloudflare'] ) ) { echo '<p>偵測到 Cloudflare 回應標頭；若外部檢索失敗，請比對安全事件及快取規則。</p>'; }
        foreach ( $result['chain'] ?? [] as $step ) { echo '<p>' . esc_html( $step['status'] . ' ' . $step['url'] ) . '</p>'; }
        if ( ! empty( $result['xml']['index'] ) ) {
            echo '<p>以下是 XML 索引的 HTML 預覽，最多顯示 50 個子檔；請逐一按需檢查，沒有自動連線的子檔不會標示成功。</p>';
            foreach ( array_slice( $result['xml']['urls'], 0, 50 ) as $child ) {
                echo '<form method="post">'; wp_nonce_field( 'wu_seo_tools' );
                echo '<input type="hidden" name="check_sitemap" value="' . esc_attr( $child ) . '"><button class="button">檢查子 Sitemap</button> <a href="' . esc_url( $child ) . '" target="_blank" rel="noopener">' . esc_html( $child ) . '</a></form>';
            }
        } else {
            echo '<details><summary>HTML 網址預覽（最多 100 個）</summary><ul>';
            foreach ( array_slice( $result['xml']['urls'] ?? [], 0, 100 ) as $loc ) { echo '<li><a href="' . esc_url( $loc ) . '" target="_blank" rel="noopener">' . esc_html( $loc ) . '</a></li>'; }
            echo '</ul></details>';
        }
        echo '</section>';
    }

    public static function seo_tools_page() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        if ( ! empty( $_POST ) ) { check_admin_referer( 'wu_seo_tools' ); }
        wutm_admin_page_open( 'SEO 管理與技術診斷', '網站設定、內容、技術檢查、Sitemap 一致性與外部搜尋狀態分別呈現。所有網路診斷皆由您手動啟動。' );
        echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . self::SETTINGS_PAGE ) ) . '">全站 SEO 設定</a></p>';
        self::management_dashboard();
        echo '<h2>Sitemap 與爬蟲存取</h2><p>保留 WordPress 原生 Sitemap。一般 HTTP 測試不等於 Googlebot 測試；外部搜尋／索引狀態：尚未驗證。HTML 預覽只供管理員閱讀，請提交 XML 網址至 Search Console。</p>';
        if ( isset( $_POST['display_mode'] ) && in_array( $_POST['display_mode'], [ 'xml', 'xsl', 'html' ], true ) ) { update_option( 'wu_seo_sitemap_display', sanitize_key( $_POST['display_mode'] ), false ); }
        echo '<form method="post">'; wp_nonce_field( 'wu_seo_tools' );
        echo '<label>Sitemap 顯示 <select name="display_mode">';
        foreach ( [ 'xml' => '原生 XML（預設）', 'xsl' => 'XSLT 美化（依瀏覽器支援）', 'html' => '原生 XML ＋ 獨立 HTML 預覽' ] as $key => $name ) { echo '<option value="' . esc_attr( $key ) . '" ' . selected( get_option( 'wu_seo_sitemap_display', 'xml' ), $key, false ) . '>' . esc_html( $name ) . '</option>'; }
        echo '</select></label> <button class="button">儲存顯示方式</button></form><form method="post">'; wp_nonce_field( 'wu_seo_tools' );
        echo '<p><button class="button button-primary" name="check_sitemap" value="' . esc_attr( self::sitemap_url() ) . '">檢查 Sitemap 索引並預覽</button></p></form>';
        if ( isset( $_POST['check_sitemap'] ) && is_string( $_POST['check_sitemap'] ) ) { self::render_diagnostic( self::run_sitemap_diagnostic( esc_url_raw( wp_unslash( $_POST['check_sitemap'] ) ) ) ); }
        echo '<h2>網址與 robots.txt 檢查</h2><form method="post">'; wp_nonce_field( 'wu_seo_tools' );
        echo '<p><label>目前網站的網址 <input type="url" name="inspect_url" class="large-text" value="' . esc_attr( home_url( '/' ) ) . '" required></label></p><button class="button">檢查轉址、Canonical 與 Robots</button></form><form method="post">'; wp_nonce_field( 'wu_seo_tools' );
        echo '<p><button class="button" name="robots_check" value="1">讀取並檢查 robots.txt</button></p></form>';
        if ( isset( $_POST['inspect_url'] ) && is_string( $_POST['inspect_url'] ) ) { self::inspect_page_report( esc_url_raw( wp_unslash( $_POST['inspect_url'] ) ) ); }
        if ( isset( $_POST['robots_check'] ) ) { self::robots_report(); }
        self::management_forms();
        self::search_console_page();
        wutm_admin_page_close();
    }

    private static function robots_report() {
        $physical = file_exists( ABSPATH . 'robots.txt' );
        echo '<p>' . esc_html( $physical ? '偵測到 WordPress 安裝目錄的實體 robots.txt；不會覆蓋檔案。' : '安裝目錄未找到實體檔案；可能為 WordPress 虛擬檔、主機或網站根目錄提供（子目錄安裝請另確認網域根目錄）。' ) . '</p>';
        $parts = wp_parse_url( home_url( '/' ) );
        $url = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . '/robots.txt';
        $r = self::diagnostic_request( $url ); $body = $r['body'] ?? '';
        echo '<p>HTTP：' . esc_html( $r['status'] ?? $r['error'] ?? '尚未驗證' ) . '</p><pre style="white-space:pre-wrap">' . esc_html( substr( $body, 0, 50000 ) ) . '</pre>';
        $warnings = [];
        if ( ! get_option( 'blog_public' ) ) { $warnings[] = 'WordPress 設定不允許建立索引。'; }
        if ( stripos( $body, 'Sitemap:' ) === false ) { $warnings[] = '沒有 Sitemap 宣告，建議確認原生 Sitemap 是否啟用。'; }
        if ( preg_match( '~^\s*Disallow:\s*/\s*$~mi', $body ) ) { $warnings[] = '有封鎖根路徑規則，請核對所屬 User-agent（含 Googlebot 及 *）；封鎖會使爬蟲無法讀取頁面 noindex。'; }
        if ( preg_match( '~^\s*Noindex:~mi', $body ) ) { $warnings[] = 'robots.txt 的 Noindex 不是受支援的索引控制方式，請使用頁面 robots meta。'; }
        echo '<p>' . esc_html( implode( ' ', $warnings ) ?: '未發現上述常見問題；這不是完整 robots 規則或 Googlebot 存取驗證。' ) . '</p>';
    }

    private static function inspect_page_report( $url ) {
        $key = 'wu_seo_page_' . md5( $url ); $r = get_transient( $key );
        if ( false === $r ) { $r = self::diagnostic_request( $url ); set_transient( $key, $r, 300 ); }
        $body = $r['body'] ?? ''; $warnings = [];
        if ( isset( $r['error'] ) ) { $warnings[] = $r['error']; }
        echo '<p>一般 HTTP：' . esc_html( $r['status'] ?? '尚未驗證' ) . '；最終網址：' . esc_html( $r['url'] ) . '</p>';
        if ( ! class_exists( 'DOMDocument' ) || ! $body ) { echo '<p>沒有可解析的 HTML，內容檢查尚未驗證。</p>'; return; }
        $old = libxml_use_internal_errors( true ); $dom = new DOMDocument(); $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $body, LIBXML_NONET ); libxml_clear_errors(); libxml_use_internal_errors( $old );
        $xp = new DOMXPath( $dom );
        $canonicals = $xp->query( '//link[contains(concat(" ",normalize-space(translate(@rel,"CANONICAL","canonical"))," ")," canonical ")]' );
        if ( $canonicals->length > 1 ) { $warnings[] = '重複 Canonical，請檢查 SEO 外掛或佈景主題重複輸出。'; }
        foreach ( $canonicals as $node ) { echo '<p>Canonical：' . esc_html( $node->getAttribute( 'href' ) ) . '</p>'; if ( $node->getAttribute( 'href' ) !== $r['url'] ) { $warnings[] = 'Canonical 與最終網址不同；請確認語言版本、正式網址及轉址設定。'; } }
        $robots = $xp->query( '//meta[translate(@name,"ROBOTS","robots")="robots"]' );
        if ( $robots->length > 1 ) { $warnings[] = '偵測到重複 robots meta。'; }
        foreach ( $robots as $node ) { echo '<p>Robots：' . esc_html( $node->getAttribute( 'content' ) ) . '</p>'; }
        $id = url_to_postid( $url );
        if ( $id ) {
            $local_noindex = (bool) get_post_meta( $id, '_wu_seo_noindex', true );
            echo '<p>' . esc_html( $local_noindex ? '本機 noindex 已設定：由本模組排除 Sitemap。' : '本機允許索引；實際是否納入由公開狀態、密碼、Canonical 與 Sitemap 提供者決定。' ) . '</p>';
        }
        $h1 = $dom->getElementsByTagName( 'h1' )->length;
        if ( $h1 !== 1 ) { $warnings[] = 'H1 數量為 ' . $h1 . '，請依內容結構人工確認。'; }
        $warnings[] = '缺少 Alt 的圖片：' . $xp->query( '//img[not(@alt)]' )->length . '；裝飾圖可使用空白 Alt。';
        $warnings[] = 'hreflang 關聯：' . $xp->query( '//link[@hreflang]' )->length . '；由多語系外掛維護，本工具不重複產生。';
        $links = [];
        foreach ( $dom->getElementsByTagName( 'a' ) as $link ) {
            $href = $link->getAttribute( 'href' ); if ( ! $href || '#' === $href[0] ) { continue; }
            $target = WP_Http::make_absolute_url( $href, $r['url'] );
            if ( wp_parse_url( $target, PHP_URL_HOST ) === wp_parse_url( home_url(), PHP_URL_HOST ) ) { $links[ preg_replace( '/#.*$/', '', $target ) ] = true; }
            if ( count( $links ) >= 3 ) { break; }
        }
        foreach ( array_keys( $links ) as $target ) {
            $link_key = 'wu_seo_link_' . md5( $target ); $check = get_transient( $link_key );
            if ( false === $check ) { $check = self::diagnostic_request( $target ); unset( $check['body'] ); set_transient( $link_key, $check, 300 ); }
            $warnings[] = '內連抽樣 ' . $target . '：' . ( $check['status'] ?? $check['error'] ?? '尚未驗證' ) . ( count( $check['chain'] ?? [] ) > 1 ? '（有轉址，建議改成最終正式網址）' : '' );
        }
        if ( ! empty( $r['challenge'] ) ) { $warnings[] = '發現驗證挑戰；此內容分析可能是挑戰頁，請先處理存取限制。'; }
        echo '<p>' . esc_html( implode( ' ', $warnings ) ) . '</p><p>僅分析本次伺服器 HTML 及最多 3 個內連；JavaScript 動態內容、全站失效內連及真正 Googlebot 存取尚未驗證。外部索引狀態尚未驗證。</p>';
    }
}
