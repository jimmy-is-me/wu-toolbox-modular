<?php
/** Manual, resumable review of actual child-sitemap URLs. No frontend hooks. */
defined( 'ABSPATH' ) || exit;
final class WUTM_SEO_Sitemap_Review {
    const OPTION = 'wu_seo_sitemap_review';

    public static function evidence( $response ) {
        $issues = []; $status = (int) ( $response['status'] ?? 0 );
        if ( ! empty( $response['error'] ) ) { $issues[] = $response['error']; }
        if ( 200 !== $status ) { $issues[] = 'HTTP ' . $status . '：無法確認頁面正常公開。'; }
        if ( count( $response['chain'] ?? [] ) > 1 ) { $issues[] = 'Sitemap 網址有轉址，請確認應改成最終正式網址。'; }
        if ( ! empty( $response['challenge'] ) ) { $issues[] = '偵測到安全驗證挑戰，尚未取得可靠頁面內容。'; }
        $robots = $response['robots'] ?? ''; $canonical = '';
        $body = $response['body'] ?? '';
        if ( $body && empty( $response['challenge'] ) && empty( $response['error'] ) && class_exists( 'DOMDocument' ) ) {
            $previous = libxml_use_internal_errors( true ); $dom = new DOMDocument();
            $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $body, LIBXML_NONET );
            libxml_clear_errors(); libxml_use_internal_errors( $previous );
            foreach ( $dom->getElementsByTagName( 'meta' ) as $meta ) {
                if ( in_array( strtolower( $meta->getAttribute( 'name' ) ), [ 'robots', 'googlebot' ], true ) ) { $robots .= ' ' . $meta->getAttribute( 'content' ); }
            }
            $canonicals = [];
            foreach ( $dom->getElementsByTagName( 'link' ) as $link ) {
                if ( in_array( 'canonical', preg_split( '/\s+/', strtolower( trim( $link->getAttribute( 'rel' ) ) ) ), true ) ) { $canonicals[] = $link->getAttribute( 'href' ); }
            }
            $canonical = $canonicals[0] ?? '';
            if ( count( $canonicals ) > 1 ) { $issues[] = '重複 Canonical。'; }
            if ( $canonical && rtrim( $canonical, '/' ) !== rtrim( $response['url'] ?? '', '/' ) ) { $issues[] = 'Canonical 與最終網址不同，請確認正式或語言版本。'; }
        }
        if ( preg_match( '/\bnoindex\b/i', $robots ) ) { $issues[] = '頁面有 noindex，應確認是否排除 Sitemap。'; }
        return [ 'status' => $status, 'final_url' => $response['url'] ?? '', 'canonical' => $canonical, 'robots' => trim( $robots ), 'issues' => array_values( array_unique( $issues ) ), 'checked_at' => time(), 'google' => '尚未驗證：此結果不是 Googlebot 或 Google 索引證據' ];
    }

    public static function start( $url ) {
        $r = Wumetax_SEO_Core_v120::run_sitemap_diagnostic( $url );
        if ( 200 !== ( $r['status'] ?? 0 ) || empty( $r['xml']['valid'] ) || ! empty( $r['xml']['index'] ) || ! empty( $r['error'] ) ) { return new WP_Error( 'sitemap', '請提供本網站可讀取的有效子 Sitemap（urlset），不是 Sitemap 索引。' ); }
        $urls = $r['xml']['urls'];
        $job = [ 'source' => $url, 'xml_checked_at' => $r['checked_at'], 'xml_count' => count( $urls ), 'urls' => array_slice( array_values( array_unique( $urls ) ), 0, 500 ), 'results' => [], 'cursor' => 0, 'last_request' => 0 ];
        update_option( self::OPTION, $job, false ); return $job;
    }

    public static function step() {
        // Atomic short lease prevents concurrent browser tabs from duplicating requests.
        $lock = 'wu_seo_sitemap_review_lock';
        $lease = (int) get_option( $lock, 0 );
        if ( $lease && $lease < time() - 90 ) { delete_option( $lock ); }
        if ( ! add_option( $lock, time(), '', false ) ) { return new WP_Error( 'busy', '另一個檢查正在執行，請稍後再試。' ); }
        try {
            $job = get_option( self::OPTION, [] );
            if ( ! $job || $job['cursor'] >= count( $job['urls'] ) ) { return new WP_Error( 'done', '沒有待檢查網址。' ); }
            if ( time() - $job['last_request'] < 5 ) { return new WP_Error( 'rate', '每次檢查至少間隔 5 秒。' ); }
            $url = $job['urls'][ $job['cursor'] ];
            $result = self::evidence( Wumetax_SEO_Core_v120::diagnostic_request( $url ) );
            $id = url_to_postid( $url );
            if ( $id ) {
                $post = get_post( $id );
                if ( 'publish' !== $post->post_status || $post->post_password ) { $result['issues'][] = '本機內容非公開或受密碼保護，不應列入公開 Sitemap。'; }
                if ( get_post_meta( $id, '_wu_seo_noindex', true ) ) { $result['issues'][] = '本機已設定 noindex，請比對 Sitemap 提供者與快取。'; }
            }
            $job['results'][ $url ] = $result; $job['cursor']++; $job['last_request'] = time();
            update_option( self::OPTION, $job, false ); return $job;
        } finally { delete_option( $lock ); }
    }

    public static function panel() {
        // Called only after the parent page's capability and POST nonce checks.
        $error = null;
        if ( isset( $_POST['wu_review_start'] ) && is_string( $_POST['wu_review_source'] ?? null ) ) { $error = self::start( esc_url_raw( wp_unslash( $_POST['wu_review_source'] ) ) ); }
        if ( isset( $_POST['wu_review_next'] ) ) { $error = self::step(); }
        if ( is_wp_error( $error ) ) { echo '<p role="alert">' . esc_html( $error->get_error_message() ) . '</p>'; }
        echo '<h2>Sitemap 一致性：分開查看證據</h2><p>本機：原生 Sitemap 與內容設定。HTTP：以下手動檢查結果。Google：尚未透過 URL Inspection API 驗證；不能由 HTTP 200 推論已收錄。</p><form method="post">';
        wp_nonce_field( 'wu_seo_tools' );
        echo '<label>子 Sitemap 完整網址 <input class="large-text" type="url" name="wu_review_source" required></label><p><button class="button" name="wu_review_start" value="1">建立／取代本次檢查清單</button></p></form>';
        $job = get_option( self::OPTION, [] ); if ( ! $job ) { return; }
        echo '<p>來源：' . esc_html( $job['source'] ) . '；XML 項目 ' . esc_html( $job['xml_count'] ) . '；已檢查 ' . esc_html( $job['cursor'] ) . ' / ' . esc_html( count( $job['urls'] ) ) . '。每份清單最多 500 個網址，不代表已檢查全站；每次手動處理一個網址，結果保存在目前網站。</p>';
        echo '<form method="post">'; wp_nonce_field( 'wu_seo_tools' );
        echo '<button class="button" name="wu_review_next" value="1">檢查下一個網址</button></form><div style="overflow:auto"><table class="widefat"><thead><tr><th>網址</th><th>HTTP</th><th>問題／建議</th><th>檢查時間</th></tr></thead><tbody>';
        foreach ( $job['results'] as $url => $row ) {
            echo '<tr><td>' . esc_html( $url ) . '</td><td>' . esc_html( $row['status'] ) . '</td><td>' . esc_html( implode( '；', $row['issues'] ) ?: '本次檢查未發現上述問題；Google 索引仍未驗證。' ) . '</td><td>' . esc_html( wp_date( 'Y-m-d H:i:s', $row['checked_at'] ) ) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
}
