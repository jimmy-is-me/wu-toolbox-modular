<?php
/** Shared SEO data service, native list integration and reviewed bulk changes. */
defined( 'ABSPATH' ) || exit;

trait WUTM_SEO_Management {
    private static function management_init() {
        add_action( 'init', [ __CLASS__, 'seo_list_hooks' ], 40 );
        add_action( 'quick_edit_custom_box', [ __CLASS__, 'seo_quick_fields' ], 10, 2 );
        add_action( 'save_post', [ __CLASS__, 'save_seo_quick_edit' ], 30, 2 );
        add_action( 'restrict_manage_posts', [ __CLASS__, 'seo_list_filter' ] );
        add_action( 'pre_get_posts', [ __CLASS__, 'seo_filter_query' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'seo_management_assets' ] );
        add_action( 'admin_footer-edit.php', [ __CLASS__, 'seo_side_panel' ] );
        add_action( 'wp_ajax_wu_seo_edit', [ __CLASS__, 'ajax_seo_edit' ] );
        add_action( 'admin_post_wu_seo_export', [ __CLASS__, 'seo_csv_export' ] );
        add_action( 'updated_post_meta', [ __CLASS__, 'seo_meta_history' ], 20, 4 );
        add_action( 'added_post_meta', [ __CLASS__, 'seo_meta_history' ], 20, 4 );
        add_action( 'deleted_post_meta', [ __CLASS__, 'seo_meta_history' ], 20, 4 );
        add_filter( 'update_post_metadata', [ __CLASS__, 'capture_seo_before' ], 10, 5 );
        add_filter( 'add_post_metadata', [ __CLASS__, 'capture_seo_before' ], 10, 5 );
        add_filter( 'delete_post_metadata', [ __CLASS__, 'capture_seo_before' ], 10, 5 );
    }

    private static function seo_field_labels() {
        return [ 'title' => 'SEO 標題', 'description' => '搜尋摘要', 'topic' => 'SEO 主題（多組以逗號分隔）', 'canonical' => 'Canonical', 'noindex' => '不建立索引', 'nofollow' => '不跟隨連結', 'og_title' => '社群標題', 'og_desc' => '社群摘要', 'og_image' => '社群圖片 ID' ];
    }

    public static function seo_list_hooks() {
        foreach ( self::supported_post_types() as $type ) {
            add_filter( 'manage_' . $type . '_posts_columns', [ __CLASS__, 'seo_columns' ], 50 );
            add_action( 'manage_' . $type . '_posts_custom_column', [ __CLASS__, 'seo_column_value' ], 10, 2 );
        }
    }

    public static function seo_columns( $columns ) {
        return array_merge( $columns, [ 'wu_seo' => 'SEO 狀態', 'wu_seo_title' => 'SEO 標題', 'wu_seo_description' => 'SEO 摘要', 'wu_seo_topic' => 'SEO 主題', 'wu_seo_canonical' => 'Canonical', 'wu_seo_noindex' => '索引規則', 'wu_seo_modified' => 'SEO 最後更新' ] );
    }

    public static function seo_summary( $id ) {
        $post = get_post( $id ); $data = self::get_post_meta_data( $id );
        $title = $data['title'] ?: self::automatic_title( get_the_title( $id ), in_array( $post->post_type, [ 'page', 'product' ], true ) ? $post->post_type : 'post' );
        $description = $data['description'] ?: self::clean_text( $post->post_excerpt ?: strip_shortcodes( $post->post_content ), 160 );
        $status = $data['noindex'] ? 'noindex' : ( $data['title'] || $data['description'] ? 'manual' : 'template' );
        $issues = [];
        if ( ! $description ) { $issues[] = '缺少摘要'; }
        if ( $data['canonical'] && ! wp_http_validate_url( $data['canonical'] ) ) { $issues[] = 'Canonical 需檢查'; }
        return [ 'data' => $data, 'title' => $title, 'description' => $description, 'status' => $status, 'issues' => $issues, 'url' => get_permalink( $id ), 'modified' => get_post_meta( $id, '_wu_seo_modified', true ) ];
    }

    public static function seo_column_value( $column, $id ) {
        if ( strpos( $column, 'wu_seo' ) !== 0 ) { return; }
        $row = self::seo_summary( $id );
        if ( 'wu_seo' === $column ) {
            $labels = [ 'noindex' => '不建立索引', 'manual' => '已手動設定', 'template' => '使用自動範本' ];
            echo esc_html( $labels[ $row['status'] ] . ( $row['issues'] ? '；' . implode( '；', $row['issues'] ) : '' ) );
            if ( current_user_can( 'edit_post', $id ) ) { echo '<p><button type="button" class="button-link wu-seo-open" data-id="' . esc_attr( $id ) . '">SEO 快速編輯</button></p>'; }
            echo '<span hidden class="wu-seo-row-data" data-seo="' . esc_attr( wp_json_encode( $row['data'] ) ) . '"></span>';
        } elseif ( 'wu_seo_modified' === $column ) { echo esc_html( $row['modified'] ?: '尚無修改記錄' );
        } elseif ( 'wu_seo_noindex' === $column ) { echo esc_html( $row['data']['noindex'] ? 'noindex' : '允許索引（不保證收錄）' );
        } else { $key = substr( $column, 7 ); echo esc_html( $row[ $key ] ?? $row['data'][ $key ] ?? '' ); }
    }

    public static function seo_quick_fields( $column, $type ) {
        if ( 'wu_seo' !== $column || ! in_array( $type, self::supported_post_types(), true ) ) { return; }
        echo '<fieldset class="inline-edit-col"><legend>SEO 設定</legend>'; wp_nonce_field( 'wu_seo_quick', 'wu_seo_quick_nonce' );
        foreach ( array_slice( self::seo_field_labels(), 0, 6, true ) as $key => $label ) {
            echo '<label><span class="title">' . esc_html( $label ) . '</span><input name="wu_seo_quick[' . esc_attr( $key ) . ']" type="' . ( in_array( $key, [ 'noindex', 'nofollow' ], true ) ? 'checkbox' : 'text' ) . '" value="' . ( in_array( $key, [ 'noindex', 'nofollow' ], true ) ? '1' : '' ) . '"></label>';
        }
        echo '</fieldset>';
    }

    public static function save_seo_quick_edit( $id, $post ) {
        if ( ! isset( $_POST['wu_seo_quick_nonce'], $_POST['wu_seo_quick'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wu_seo_quick_nonce'] ) ), 'wu_seo_quick' ) || wp_is_post_revision( $id ) || wp_is_post_autosave( $id ) ) { return; }
        $data = wp_unslash( $_POST['wu_seo_quick'] );
        if ( ! is_array( $data ) ) { return; }
        foreach ( [ 'noindex', 'nofollow' ] as $key ) { $data[ $key ] = empty( $data[ $key ] ) ? 0 : 1; }
        self::write_seo_data( $id, $data );
    }

    public static function write_seo_data( $id, $data, $only_empty = false ) {
        $post = get_post( $id );
        if ( ! $post || ! current_user_can( 'edit_post', $id ) || ! in_array( $post->post_type, self::supported_post_types(), true ) || wp_is_post_revision( $id ) ) { return new WP_Error( 'forbidden', '無權限修改此內容。' ); }
        if ( isset( $data['canonical'] ) && is_scalar( $data['canonical'] ) && $data['canonical'] && ! preg_match( '~^https?://[^\s]+$~i', $data['canonical'] ) ) { return new WP_Error( 'canonical', 'Canonical 必須是完整 HTTP(S) 網址。' ); }
        $changed = [];
        foreach ( self::seo_field_labels() as $key => $label ) {
            if ( ! array_key_exists( $key, $data ) || ! is_scalar( $data[ $key ] ) ) { continue; }
            $meta_key = '_wu_seo_' . $key; $old = get_post_meta( $id, $meta_key, true );
            // A stored zero is an explicit robots choice, not an empty field.
            if ( $only_empty && metadata_exists( 'post', $id, $meta_key ) && '' !== (string) $old ) { continue; }
            if ( in_array( $key, [ 'noindex', 'nofollow' ], true ) ) { $value = empty( $data[ $key ] ) ? 0 : 1;
            } elseif ( 'og_image' === $key ) { $value = absint( $data[ $key ] );
            } elseif ( 'canonical' === $key ) {
                $value = esc_url_raw( $data[ $key ], [ 'http', 'https' ] );
                if ( $data[ $key ] && ! $value ) { return new WP_Error( 'canonical', 'Canonical 必須是完整 HTTP(S) 網址。' ); }
            } elseif ( 'topic' === $key ) { $value = wutm_seo_keywords( $data[ $key ] );
            } else { $value = sanitize_text_field( $data[ $key ] ); }
            if ( (string) $old === (string) $value && ( metadata_exists( 'post', $id, $meta_key ) || '' === (string) $value ) ) { continue; }
            $result = update_post_meta( $id, $meta_key, wp_slash( $value ) );
            if ( false === $result ) { return new WP_Error( 'write_failed', '部分欄位儲存失敗，請查看歷史再重試。' ); }
            $changed[] = $key;
        }
        return $changed;
    }

    public static function capture_seo_before( $check, $id, $key, $value, $extra = null ) {
        if ( 0 === strpos( $key, '_wu_seo_' ) && isset( self::seo_field_labels()[ substr( $key, 8 ) ] ) ) {
            $GLOBALS['wu_seo_before'][ get_current_blog_id() ][ $id ][ $key ] = get_post_meta( $id, $key, true );
        }
        return $check;
    }

    public static function seo_meta_history( $meta_id, $id, $key, $value ) {
        if ( 0 !== strpos( $key, '_wu_seo_' ) || ! isset( self::seo_field_labels()[ substr( $key, 8 ) ] ) ) { return; }
        $before = $GLOBALS['wu_seo_before'][ get_current_blog_id() ][ $id ][ $key ] ?? '';
        unset( $GLOBALS['wu_seo_before'][ get_current_blog_id() ][ $id ][ $key ] );
        $history = get_post_meta( $id, '_wu_seo_history', true );
        $history = is_array( $history ) ? $history : [];
        $history[] = [ 'user' => get_current_user_id(), 'time' => current_time( 'mysql' ), 'field' => substr( $key, 8 ), 'before' => $before, 'after' => get_post_meta( $id, $key, true ) ];
        update_post_meta( $id, '_wu_seo_history', array_slice( $history, -50 ) );
        update_post_meta( $id, '_wu_seo_modified', current_time( 'mysql' ) );
        delete_transient( 'wu_seo_excluded_' . md5( get_post_type( $id ) ) );
    }

    public static function seo_list_filter( $type ) {
        if ( ! in_array( $type, self::supported_post_types(), true ) ) { return; }
        echo '<select name="wu_seo_filter"><option value="">全部 SEO 狀態</option>';
        foreach ( self::seo_filters() as $key => $label ) { echo '<option value="' . esc_attr( $key ) . '" ' . selected( sanitize_key( $_GET['wu_seo_filter'] ?? '' ), $key, false ) . '>' . esc_html( $label ) . '</option>'; }
        echo '</select>';
    }

    private static function seo_filters() {
        return [ 'manual' => '已手動設定', 'template' => '使用自動範本', 'missing' => '缺少自訂描述', 'technical' => 'Canonical 指向其他網址／待檢查', 'noindex' => 'noindex', 'review' => '需要人工檢查（未設定主題）' ];
    }

    public static function seo_filter_query( $query ) {
        if ( ! is_admin() || ! $query->is_main_query() || ! in_array( $query->get( 'post_type' ), self::supported_post_types(), true ) ) { return; }
        $filter = sanitize_key( $_GET['wu_seo_filter'] ?? '' );
        if ( ! isset( self::seo_filters()[ $filter ] ) ) { return; }
        if ( 'technical' === $filter ) {
            $args = self::sitemap_canonical_query( [], $query->get( 'post_type' ) );
            $query->set( 'post__in', $args['post__not_in'] ?: [ 0 ] ); return;
        }
        $empty = static function( $key ) { return [ 'relation' => 'OR', [ 'key' => $key, 'compare' => 'NOT EXISTS' ], [ 'key' => $key, 'value' => '' ] ]; };
        if ( 'noindex' === $filter ) { $rule = [ 'key' => '_wu_seo_noindex', 'value' => '1' ];
        } elseif ( 'missing' === $filter ) { $rule = $empty( '_wu_seo_description' );
        } elseif ( 'review' === $filter ) { $rule = $empty( '_wu_seo_topic' );
        } elseif ( 'template' === $filter ) { $rule = [ 'relation' => 'AND', $empty( '_wu_seo_title' ), $empty( '_wu_seo_description' ) ];
        } else { $rule = [ 'relation' => 'OR', [ 'key' => '_wu_seo_title', 'value' => '', 'compare' => '!=' ], [ 'key' => '_wu_seo_description', 'value' => '', 'compare' => '!=' ] ]; }
        $existing = $query->get( 'meta_query' ); $query->set( 'meta_query', $existing ? [ 'relation' => 'AND', $existing, $rule ] : [ $rule ] );
    }

    public static function seo_management_assets() {
        $screen = get_current_screen();
        if ( ! $screen || ! in_array( $screen->post_type, self::supported_post_types(), true ) || 'edit' !== $screen->base ) { return; }
        wp_enqueue_script( 'wu-seo-management', WUTM_URL . 'assets/js/seo-management.js', [ 'jquery', 'inline-edit-post' ], WUTM_VERSION, true );
        wp_localize_script( 'wu-seo-management', 'wuSeoManagement', [ 'nonce' => wp_create_nonce( 'wu_seo_edit' ), 'url' => admin_url( 'admin-ajax.php' ) ] );
    }

    public static function seo_side_panel() {
        $screen = get_current_screen();
        if ( ! $screen || ! in_array( $screen->post_type, self::supported_post_types(), true ) ) { return; }
        echo '<dialog id="wu-seo-dialog" style="width:min(620px,90vw);max-height:85vh;overflow:auto"><form id="wu-seo-editor"><h2>SEO 快速編輯</h2><input type="hidden" name="id">';
        foreach ( self::seo_field_labels() as $key => $label ) {
            echo '<p><label>' . esc_html( $label ) . '<br><input style="width:100%" name="' . esc_attr( $key ) . '" type="' . ( in_array( $key, [ 'noindex', 'nofollow' ], true ) ? 'checkbox' : ( 'og_image' === $key ? 'number' : 'text' ) ) . '" value="1"></label></p>';
        }
        echo '<section aria-label="預覽"><h3>搜尋與社群預覽</h3><strong id="wu-seo-search-title"></strong><p id="wu-seo-search-desc"></p><p id="wu-seo-social-preview"></p><img id="wu-seo-social-image" style="max-width:240px" alt="社群圖片預覽" hidden></section><p id="wu-seo-save-status" role="status"></p><button class="button button-primary" type="submit">儲存</button> <button class="button" type="button" id="wu-seo-close">取消</button></form><details><summary>最近 SEO 修改歷史</summary><div id="wu-seo-history"></div></details></dialog>';
    }

    public static function ajax_seo_edit() {
        check_ajax_referer( 'wu_seo_edit', 'nonce' );
        $id = absint( $_POST['id'] ?? 0 ); $post = get_post( $id );
        if ( ! $post || ! current_user_can( 'edit_post', $id ) || ! in_array( $post->post_type, self::supported_post_types(), true ) ) { wp_send_json_error( '沒有編輯權限。', 403 ); }
        if ( isset( $_POST['data'] ) ) {
            $data = wp_unslash( $_POST['data'] );
            if ( ! is_array( $data ) ) { wp_send_json_error( '欄位格式不正確。', 400 ); }
            $result = self::write_seo_data( $id, $data );
            if ( is_wp_error( $result ) ) { wp_send_json_error( $result->get_error_message(), 400 ); }
        }
        if ( isset( $_POST['restore'] ) ) {
            $history = (array) get_post_meta( $id, '_wu_seo_history', true ); $entry = $history[ absint( $_POST['restore'] ) ] ?? null;
            if ( ! $entry || empty( $_POST['confirm'] ) ) { wp_send_json_error( '請確認要還原的 SEO 欄位。', 400 ); }
            $result = self::write_seo_data( $id, [ $entry['field'] => $entry['before'] ] );
            if ( is_wp_error( $result ) ) { wp_send_json_error( $result->get_error_message(), 400 ); }
        }
        $row = self::seo_summary( $id ); $row['history'] = get_post_meta( $id, '_wu_seo_history', true ) ?: [];
        $row['image'] = self::attachment_url( $row['data']['og_image'] ?: self::settings()['default_og_image'] );
        wp_send_json_success( $row );
    }

    private static function management_dashboard() {
        echo '<h2>內容管理總覽</h2><p>每個類型連到原生列表，可使用「顯示項目設定」控制 SEO 欄位。統計依 WordPress 內容數量；重複檢查採下方按需分批分析，不在進入後台時掃描全站。</p><ul>';
        foreach ( self::supported_post_types() as $type ) {
            $counts = wp_count_posts( $type ); $base = admin_url( 'edit.php?post_type=' . $type );
            echo '<li><a href="' . esc_url( $base ) . '">' . esc_html( self::post_type_label( $type ) . '：已公開 ' . ( $counts->publish ?? 0 ) ) . '</a> ';
            foreach ( self::seo_filters() as $key => $label ) { echo '<a href="' . esc_url( add_query_arg( 'wu_seo_filter', $key, $base ) ) . '">' . esc_html( $label ) . '</a> ｜ '; }
            echo '</li>';
        }
        echo '</ul><p>技術 SEO、Sitemap 可擷取及外部搜尋狀態，請以下方實際診斷為準。自動範本不是錯誤；缺少自訂描述不代表沒有自動摘要。</p>';
        $snapshot = get_option( 'wu_seo_analysis_snapshot', [] );
        if ( $snapshot ) {
            echo '<p>最近按需分析：' . esc_html( $snapshot['time'] ) . '；' . esc_html( empty( $snapshot['complete'] ) ? '尚未完成所有批次，以下為已處理部分' : '當次公開內容清單分析完成（網站後續變動需重掃）' ) . '</p><pre>' . esc_html( wp_json_encode( $snapshot['counts'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) ) . '</pre>';
        }
    }

    /** User-bound, expiring previews; commit only data already reviewed, with optimistic checks. */
    public static function build_seo_preview( $rows, $only_empty ) {
        $preview = [];
        foreach ( array_slice( $rows, 0, 50 ) as $row ) {
            if ( ( $row['_wu_csv_encoding'] ?? '' ) === 'spreadsheet-quoted-v1' ) {
                foreach ( $row as &$cell ) { if ( is_string( $cell ) && preg_match( "/^'(?=[=+@'\\-\\t\\r])/", $cell ) ) { $cell = substr( $cell, 1 ); } } unset( $cell );
            }
            $id = absint( $row['id'] ?? 0 ); $post = get_post( $id );
            if ( ! $post || ! current_user_can( 'edit_post', $id ) || ! in_array( $post->post_type, self::supported_post_types(), true ) ) { $preview[] = [ 'id' => $id, 'error' => '內容不存在或沒有權限' ]; continue; }
            $before = self::get_post_meta_data( $id ); $data = [];
            foreach ( self::seo_field_labels() as $key => $label ) {
                if ( ! array_key_exists( $key, $row ) || ! is_scalar( $row[ $key ] ) ) { continue; }
                if ( $only_empty && metadata_exists( 'post', $id, '_wu_seo_' . $key ) && '' !== (string) $before[ $key ] ) { continue; }
                $data[ $key ] = $row[ $key ];
            }
            $preview[] = [ 'id' => $id, 'before' => $before, 'data' => $data ];
        }
        return $preview;
    }

    public static function commit_seo_preview( $preview ) {
        $results = [];
        foreach ( $preview as $row ) {
            $id = $row['id'];
            if ( isset( $row['error'] ) ) { $results[ $id ] = $row['error']; continue; }
            if ( self::get_post_meta_data( $id ) !== $row['before'] ) { $results[ $id ] = '資料已變更，略過以避免覆寫，請重新預覽。'; continue; }
            $result = self::write_seo_data( $id, $row['data'] );
            $results[ $id ] = is_wp_error( $result ) ? $result->get_error_message() : '已修改欄位數：' . count( $result );
        }
        return $results;
    }

    private static function management_forms() {
        $preview_key = 'wu_seo_preview_' . get_current_user_id();
        if ( isset( $_POST['commit_seo'], $_POST['confirm_seo'] ) ) {
            $preview = get_transient( $preview_key ); delete_transient( $preview_key );
            echo '<pre>' . esc_html( wp_json_encode( $preview ? self::commit_seo_preview( $preview ) : [ '預覽已過期或已使用，請重新預覽。' ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) ) . '</pre>';
        }
        if ( isset( $_POST['preview_seo'] ) ) {
            $rows = [];
            if ( ! empty( $_FILES['seo_csv']['name'] ) && ( ! empty( $_FILES['seo_csv']['error'] ) || $_FILES['seo_csv']['size'] > 1048576 ) ) { echo '<p>CSV 上傳失敗或超過 1 MB，尚未修改任何資料。請檢查檔案大小後重試。</p>'; return; }
            if ( ! empty( $_FILES['seo_csv']['tmp_name'] ) && is_uploaded_file( $_FILES['seo_csv']['tmp_name'] ) && $_FILES['seo_csv']['size'] <= 1048576 ) {
                $fp = fopen( $_FILES['seo_csv']['tmp_name'], 'r' ); $header = fgetcsv( $fp, 0, ',', '"', '' );
                if ( $header ) {
                    $header[0] = ltrim( $header[0], "\xEF\xBB\xBF" );
                    $mapping = sanitize_text_field( wp_unslash( $_POST['csv_mapping'] ?? '' ) ); $map = [];
                    foreach ( explode( ',', $mapping ) as $pair ) { $parts = array_map( 'trim', explode( '=', $pair, 2 ) ); if ( count( $parts ) === 2 ) { $map[ $parts[0] ] = $parts[1]; } }
                    $header = array_map( static function( $key ) use ( $map ) { return $map[ $key ] ?? $key; }, $header );
                    while ( false !== ( $line = fgetcsv( $fp, 0, ',', '"', '' ) ) && count( $rows ) < 51 ) { if ( count( $line ) === count( $header ) ) { $rows[] = array_combine( $header, $line ); } }
                }
                fclose( $fp );
                if ( count( $rows ) > 50 ) { echo '<p>CSV 超過 50 筆，請拆成每批最多 50 筆後重新上傳；本次未變更。</p>'; $rows = []; }
            } else {
                $ids = array_slice( array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_POST['seo_ids'] ?? '' ) ) ) ) ), 0, 50 );
                $field = sanitize_key( $_POST['seo_field'] ?? '' ); $value = sanitize_text_field( wp_unslash( $_POST['seo_value'] ?? '' ) );
                if ( isset( self::seo_field_labels()[ $field ] ) ) { foreach ( $ids as $id ) { $rows[] = [ 'id' => $id, $field => $value ]; } }
            }
            $preview = self::build_seo_preview( $rows, ! empty( $_POST['only_empty'] ) );
            set_transient( $preview_key, $preview, 600 );
            echo '<h3>修改前預覽（10 分鐘內有效）</h3><pre style="max-height:400px;overflow:auto;white-space:pre-wrap">' . esc_html( wp_json_encode( $preview, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) ) . '</pre><form method="post">'; wp_nonce_field( 'wu_seo_tools' );
            echo '<label><input type="checkbox" name="confirm_seo" value="1" required> 我已檢查以上 SEO 欄位修改，確認套用</label> <button class="button" name="commit_seo" value="1">套用本次預覽</button></form>';
        }
        echo '<h2>批次 SEO 修改／CSV 匯入</h2><p>每批最多 50 筆，只修改指定 SEO 欄位；不改正文。先預覽，再確認。保留每篇最近 50 個欄位修改紀錄，可在列表的 SEO 快速編輯面板還原。</p><form method="post" enctype="multipart/form-data">'; wp_nonce_field( 'wu_seo_tools' );
        echo '<p><label>內容 ID（逗號分隔） <input name="seo_ids"></label> <label>欄位 <select name="seo_field">';
        foreach ( self::seo_field_labels() as $key => $label ) { echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</option>'; }
        echo '</select></label> <label>值 <input name="seo_value"></label></p><p>或上傳 CSV（1 MB／50 筆）：<input type="file" name="seo_csv" accept=".csv"><br><label>欄位對應（來源欄名=目標欄名，逗號分隔；例如 編號=id,標題=title）<input name="csv_mapping" class="large-text"></label></p><p><label><input type="checkbox" name="only_empty" value="1" checked> 僅補空白，保留既有自訂值</label></p><button class="button" name="preview_seo" value="1">預覽修改</button></form>';
        echo '<h2>CSV 匯出與分批內容分析</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'; wp_nonce_field( 'wu_seo_export' );
        echo '<input type="hidden" name="action" value="wu_seo_export"><label>內容類型 <select name="seo_type">';
        foreach ( self::supported_post_types() as $type ) { echo '<option>' . esc_html( $type ) . '</option>'; }
        echo '</select></label> <label>批次頁碼（每批 50 筆） <input name="seo_page" type="number" value="1" min="1"></label> <button class="button">匯出 SEO CSV</button></form><form method="post">'; wp_nonce_field( 'wu_seo_tools' );
        echo '<p><label>分析批次頁碼 <input type="number" name="analysis_page" value="1" min="1"></label> <button class="button">分析這一批公開內容（50 筆）</button></p></form>';
        if ( isset( $_POST['analysis_page'] ) ) { self::content_batch_report( max( 1, absint( $_POST['analysis_page'] ) ) ); }
    }

    public static function seo_csv_export() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( '沒有權限。' ); }
        check_admin_referer( 'wu_seo_export' );
        $type = sanitize_key( $_POST['seo_type'] ?? '' ); if ( ! in_array( $type, self::supported_post_types(), true ) ) { wp_die( '不支援的內容類型。' ); }
        $posts = get_posts( [ 'post_type' => $type, 'post_status' => 'publish', 'posts_per_page' => 50, 'paged' => max( 1, absint( $_POST['seo_page'] ?? 1 ) ), 'orderby' => 'ID', 'order' => 'ASC' ] );
        nocache_headers(); header( 'Content-Type: text/csv; charset=UTF-8' ); header( 'Content-Disposition: attachment; filename="wu-seo.csv"' );
        $fp = fopen( 'php://output', 'w' ); fwrite( $fp, "\xEF\xBB\xBF" ); fputcsv( $fp, array_merge( [ 'id' ], array_keys( self::seo_field_labels() ), [ '_wu_csv_encoding' ] ), ',', '"', '' );
        foreach ( $posts as $post ) {
            if ( ! current_user_can( 'edit_post', $post->ID ) ) { continue; }
            $meta = self::get_post_meta_data( $post->ID ); $data = [];
            foreach ( self::seo_field_labels() as $key => $label ) { $data[ $key ] = $meta[ $key ]; }
            // Quote formula-like text for spreadsheet safety. Import removes this explicit marker.
            foreach ( $data as &$value ) { if ( preg_match( "/^[=+@'\\-\\t\\r]/", (string) $value ) ) { $value = "'" . $value; } } unset( $value );
            fputcsv( $fp, array_merge( [ $post->ID ], array_values( $data ), [ 'spreadsheet-quoted-v1' ] ), ',', '"', '' );
        }
        fclose( $fp ); exit;
    }

    private static function content_batch_report( $page ) {
        $scan_key = 'wu_seo_analysis_' . get_current_user_id();
        $scan = 1 === $page ? [ 'next' => 1, 'titles' => [], 'descriptions' => [], 'canonical' => [], 'ids' => [], 'incoming' => [], 'unknown' => [], 'counts' => [] ] : get_transient( $scan_key );
        if ( ! $scan || $page !== $scan['next'] ) { echo '<p>請從第 1 批開始，或使用前一次顯示的下一批頁碼。進度保留 1 小時。</p>'; return; }
        $posts = get_posts( [ 'post_type' => self::supported_post_types(), 'post_status' => 'publish', 'has_password' => false, 'posts_per_page' => 50, 'paged' => $page, 'orderby' => 'ID', 'order' => 'ASC' ] );
        $titles = []; $descriptions = []; $counts = [ '公開內容（本批）' => count( $posts ), '已自訂' => 0, '使用範本' => 0, 'noindex' => 0, '摘要缺漏' => 0, 'Canonical 需檢查' => 0 ];
        echo '<table class="widefat"><thead><tr><th>內容</th><th>本批分析</th></tr></thead><tbody>';
        foreach ( $posts as $post ) {
            $row = self::seo_summary( $post->ID ); $counts[ 'manual' === $row['status'] ? '已自訂' : '使用範本' ]++;
            if ( $row['data']['noindex'] ) { $counts['noindex']++; }
            if ( ! $row['description'] ) { $counts['摘要缺漏']++; }
            $titles[ $row['title'] ][] = $post->ID; if ( $row['description'] ) { $descriptions[ $row['description'] ][] = $post->ID; }
            $notes = $row['issues'];
            $scan['ids'][] = $post->ID;
            foreach ( [ 'titles' => $row['title'], 'descriptions' => $row['description'], 'canonical' => ( $row['data']['canonical'] ?: $row['url'] ) ] as $bucket => $text ) {
                if ( '' !== $text ) { $scan[ $bucket ][ hash( 'sha256', $text ) ][] = $post->ID; }
            }
            $content = $post->post_content;
            $dynamic = (bool) preg_match( '/\[[a-zA-Z_]|<!--\s*wp:(?:query|shortcode|template-part|navigation)/', $content ) || (bool) get_post_meta( $post->ID, '_elementor_edit_mode', true );
            if ( $dynamic ) { $scan['unknown'][] = $post->ID; $notes[] = '含動態／編輯器內容，靜態分析不完整，請用網址檢查'; }
            if ( class_exists( 'DOMDocument' ) && $content ) {
                $previous = libxml_use_internal_errors( true ); $dom = new DOMDocument(); $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $content, LIBXML_NONET ); libxml_clear_errors(); libxml_use_internal_errors( $previous );
                $xp = new DOMXPath( $dom );
                $notes[] = '正文 H1 ' . $dom->getElementsByTagName( 'h1' )->length . '（標題可能由佈景主題輸出，非錯誤判定）／缺 Alt ' . $xp->query( '//img[not(@alt)]' )->length;
                $internal = 0;
                foreach ( $dom->getElementsByTagName( 'a' ) as $a ) {
                    $href = $a->getAttribute( 'href' ); if ( ! $href || '#' === $href[0] ) { continue; }
                    $url = WP_Http::make_absolute_url( $href, $row['url'] );
                    if ( wp_parse_url( $url, PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) { continue; }
                    $internal++; $target = url_to_postid( $url );
                    if ( $target && $target !== $post->ID ) { $scan['incoming'][ $target ][ $post->ID ] = 1; }
                }
                $notes[] = '正文內部連結 ' . $internal . ' 個（實際 HTTP 請用網址檢查）';
            }
            if ( $row['data']['canonical'] && $row['url'] !== $row['data']['canonical'] ) { $notes[] = 'Canonical 與內容網址不同，請確認語言／正式網址'; $counts['Canonical 需檢查']++; }
            $notes[] = '標題 ' . self::char_count( $row['title'] ) . ' 字／摘要 ' . self::char_count( $row['description'] ) . ' 字；長度依搜尋版面與語言人工確認';
            echo '<tr><td><a href="' . esc_url( get_edit_post_link( $post->ID ) ) . '">' . esc_html( get_the_title( $post ) ) . '</a></td><td>' . esc_html( implode( '；', $notes ) ) . '</td></tr>';
        }
        echo '</tbody></table><pre>' . esc_html( wp_json_encode( $counts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) ) . '</pre>';
        foreach ( $counts as $label => $number ) { $scan['counts'][ $label ] = ( $scan['counts'][ $label ] ?? 0 ) + $number; }
        $scan['next'] = $page + 1;
        $complete = count( $posts ) < 50;
        $totals = $scan['counts'];
        foreach ( [ '重複標題群組' => 'titles', '重複摘要群組' => 'descriptions', '重複 Canonical 群組' => 'canonical' ] as $label => $bucket ) {
            $duplicates = array_filter( $scan[ $bucket ], static function( $ids ) { return count( $ids ) > 1; } );
            $totals[ $label ] = count( $duplicates );
            echo '<p>' . esc_html( $label . '（本次累計批次）：' . wp_json_encode( array_values( $duplicates ) ) ) . '</p>';
        }
        if ( $complete ) {
            $orphan = array_values( array_diff( $scan['ids'], array_keys( $scan['incoming'] ), [ self::homepage_id() ] ) );
            $totals['正文無入站內連的候選頁（需人工確認）'] = count( $orphan );
            echo '<p>正文無入站內連的候選 ID：' . esc_html( implode( ', ', $orphan ) ) . '。選單、動態區塊及佈景主題連結未計入，不可直接認定孤立頁面。</p>';
        }
        $totals['含動態內容、需另行檢查'] = count( $scan['unknown'] );
        set_transient( $scan_key, $scan, 3600 );
        update_option( 'wu_seo_analysis_snapshot', [ 'time' => current_time( 'mysql' ), 'complete' => $complete, 'counts' => $totals ], false );
        foreach ( [ '重複標題' => $titles, '重複描述' => $descriptions ] as $label => $groups ) {
            $duplicates = array_filter( $groups, static function( $ids ) { return count( $ids ) > 1; } );
            echo '<p>' . esc_html( $label . '（只限本批，非全站）：' . wp_json_encode( $duplicates, JSON_UNESCAPED_UNICODE ) ) . '</p>';
        }
        echo '<p>下一批請輸入 ' . esc_html( $page + 1 ) . '。內容 H1、Alt、Canonical、hreflang 及 robots 可使用上方「網址檢查」讀取實際頁面；孤立頁面與全站連結關係尚未驗證。</p>';
    }
}
