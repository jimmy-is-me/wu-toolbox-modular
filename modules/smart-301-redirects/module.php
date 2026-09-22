<?php
/**
 * Module: smart-301-redirects
 * Lightweight permanent redirect rules with cached matching and hit counters.
 */

defined( 'ABSPATH' ) || exit;

final class WUTM_Smart_301_Redirects {
    private const VERSION = '1.0.0';
    private const OPTION_VERSION = 'wutm_smart_301_version';
    private const CACHE_KEY = 'wutm_smart_301_rules';
    private const PAGE_SLUG = 'wu-smart-301-redirects';

    public function __construct() {
        add_action( 'init', [ $this, 'maybe_install' ], 1 );
        add_action( 'template_redirect', [ $this, 'redirect_request' ], 0 );
        if ( is_admin() ) {
            add_action( 'admin_menu', [ $this, 'menu' ], 20 );
            add_action( 'admin_post_wutm_smart_301_save', [ $this, 'save_rule' ] );
            add_action( 'admin_post_wutm_smart_301_delete', [ $this, 'delete_rule' ] );
        }
    }

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'wutm_smart_301';
    }

    public function maybe_install(): void {
        if ( get_option( self::OPTION_VERSION ) === self::VERSION ) return;
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = $this->table();
        $charset = $wpdb->get_charset_collate();
        dbDelta( "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            source_path varchar(500) NOT NULL,
            target_url varchar(500) NOT NULL,
            is_wildcard tinyint(1) NOT NULL DEFAULT 0,
            status varchar(12) NOT NULL DEFAULT 'active',
            hit_count bigint(20) unsigned NOT NULL DEFAULT 0,
            last_hit_at datetime DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY source_path (source_path(191)),
            KEY status (status)
        ) {$charset};" );
        update_option( self::OPTION_VERSION, self::VERSION, false );
    }

    public function menu(): void {
        add_submenu_page( 'wu-toolbox-modular', '301 指向', '301 指向', 'manage_options', self::PAGE_SLUG, [ $this, 'page' ] );
    }

    private function normalize_path( string $path ): string {
        $path = trim( $path );
        if ( '' === $path ) return '/';
        $parsed = wp_parse_url( $path );
        $path = isset( $parsed['path'] ) ? (string) $parsed['path'] : $path;
        $path = '/' . ltrim( $path, '/' );
        if ( '/' !== $path && '*' !== substr( $path, -1 ) ) $path = untrailingslashit( $path );
        return $path;
    }

    private function normalize_target( string $target ): string {
        $target = trim( $target );
        if ( '' === $target ) return '';
        if ( '/' === substr( $target, 0, 1 ) ) return $this->normalize_path( $target );
        return esc_url_raw( $target, [ 'http', 'https' ] );
    }

    private function clear_cache(): void { delete_transient( self::CACHE_KEY ); }

    private function active_rules(): array {
        $rules = get_transient( self::CACHE_KEY );
        if ( false !== $rules && is_array( $rules ) ) return $rules;
        global $wpdb;
        $rules = $wpdb->get_results( "SELECT id, source_path, target_url, is_wildcard FROM {$this->table()} WHERE status = 'active' ORDER BY is_wildcard ASC, id DESC" );
        set_transient( self::CACHE_KEY, $rules, 5 * MINUTE_IN_SECONDS );
        return is_array( $rules ) ? $rules : [];
    }

    public function redirect_request(): void {
        if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_feed() || is_trackback() ) return;
        $uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
        $request = $this->normalize_path( (string) $uri );
        foreach ( $this->active_rules() as $rule ) {
            $target = '';
            if ( ! empty( $rule->is_wildcard ) ) {
                $prefix = untrailingslashit( str_replace( '*', '', (string) $rule->source_path ) );
                if ( '' !== $prefix && 0 === strpos( $request, $prefix . '/' ) ) {
                    $remainder = ltrim( substr( $request, strlen( $prefix ) ), '/' );
                    $target = false !== strpos( $rule->target_url, '*' ) ? str_replace( '*', $remainder, $rule->target_url ) : untrailingslashit( $rule->target_url ) . '/' . $remainder;
                }
            } elseif ( $request === $rule->source_path ) {
                $target = (string) $rule->target_url;
            }
            if ( '' === $target ) continue;
            $url = '/' === substr( $target, 0, 1 ) ? home_url( $target ) : $target;
            $current = home_url( $request );
            if ( untrailingslashit( $url ) === untrailingslashit( $current ) ) continue;
            global $wpdb;
            $wpdb->query( $wpdb->prepare( "UPDATE {$this->table()} SET hit_count = hit_count + 1, last_hit_at = %s WHERE id = %d", current_time( 'mysql' ), $rule->id ) );
            nocache_headers();
            wp_redirect( $url, 301, 'WU Toolbox Smart 301' );
            exit;
        }
    }

    public function save_rule(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( '權限不足。' );
        check_admin_referer( 'wutm_smart_301_save' );
        $id = absint( $_POST['id'] ?? 0 );
        $source = $this->normalize_path( sanitize_text_field( wp_unslash( $_POST['source_path'] ?? '' ) ) );
        $wildcard = ! empty( $_POST['is_wildcard'] ) ? 1 : 0;
        if ( $wildcard && '*' !== substr( $source, -1 ) ) $source = untrailingslashit( $source ) . '/*';
        $target = $this->normalize_target( wp_unslash( $_POST['target_url'] ?? '' ) );
        $status = ( $_POST['status'] ?? 'active' ) === 'inactive' ? 'inactive' : 'active';
        if ( '/' === $source || '' === $target ) $this->redirect_admin( 'invalid' );
        global $wpdb;
        $data = [ 'source_path' => $source, 'target_url' => $target, 'is_wildcard' => $wildcard, 'status' => $status, 'updated_at' => current_time( 'mysql' ) ];
        if ( $id ) $wpdb->update( $this->table(), $data, [ 'id' => $id ] );
        else { $data['created_at'] = current_time( 'mysql' ); $wpdb->insert( $this->table(), $data ); }
        $this->clear_cache();
        $this->redirect_admin( 'saved' );
    }

    public function delete_rule(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( '權限不足。' );
        $id = absint( $_GET['id'] ?? 0 );
        check_admin_referer( 'wutm_smart_301_delete_' . $id );
        if ( $id ) { global $wpdb; $wpdb->delete( $this->table(), [ 'id' => $id ] ); $this->clear_cache(); }
        $this->redirect_admin( 'deleted' );
    }

    private function redirect_admin( string $notice ): void {
        wp_safe_redirect( add_query_arg( [ 'page' => self::PAGE_SLUG, 'wutm301' => $notice ], admin_url( 'admin.php' ) ) );
        exit;
    }

    public function page(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;
        global $wpdb;
        $edit_id = absint( $_GET['edit'] ?? 0 );
        $edit = $edit_id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $edit_id ) ) : null;
        $rules = $wpdb->get_results( "SELECT * FROM {$this->table()} ORDER BY id DESC LIMIT 200" );
        $notice = sanitize_key( $_GET['wutm301'] ?? '' );
        ?>
        <div class="wrap wutm-module-wrap wutm-smart-301"><h1>301 指向</h1><p class="wutm-module-subtitle">建立永久轉址規則。比對結果快取 5 分鐘，只有實際命中規則時才更新統計。</p>
        <?php if ( 'saved' === $notice ) : ?><div class="notice notice-success is-dismissible"><p>轉址規則已儲存。</p></div><?php elseif ( 'deleted' === $notice ) : ?><div class="notice notice-success is-dismissible"><p>轉址規則已刪除。</p></div><?php elseif ( 'invalid' === $notice ) : ?><div class="notice notice-error"><p>請填寫來源路徑（不可為首頁）與有效目標網址。</p></div><?php endif; ?>
        <div class="wutm-smart-301-card"><h2><?php echo $edit ? '編輯轉址規則' : '新增轉址規則'; ?></h2><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="wutm_smart_301_save"><input type="hidden" name="id" value="<?php echo esc_attr( $edit->id ?? 0 ); ?>"><?php wp_nonce_field( 'wutm_smart_301_save' ); ?><div class="wutm-smart-301-grid"><label>來源路徑<input required name="source_path" value="<?php echo esc_attr( $edit->source_path ?? '' ); ?>" placeholder="/old-page 或 /old-folder/*"></label><label>目標網址<input required name="target_url" value="<?php echo esc_attr( $edit->target_url ?? '' ); ?>" placeholder="/new-page 或 https://example.com/new-page"></label><label><input type="checkbox" name="is_wildcard" value="1" <?php checked( ! empty( $edit->is_wildcard ) ); ?>> 萬用字元轉址（保留後段路徑）</label><label>狀態<select name="status"><option value="active" <?php selected( $edit->status ?? 'active', 'active' ); ?>>啟用</option><option value="inactive" <?php selected( $edit->status ?? '', 'inactive' ); ?>>停用</option></select></label></div><p class="description">來源與站內目標請以 <code>/</code> 開頭。萬用字元範例：<code>/old-folder/*</code> 指向 <code>/new-folder/*</code>。</p><p><button class="button button-primary">儲存 301 規則</button><?php if ( $edit ) : ?> <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>">取消編輯</a><?php endif; ?></p></form></div>
        <div class="wutm-smart-301-card"><h2>現有規則</h2><table class="widefat striped"><thead><tr><th>來源</th><th>目標</th><th>狀態</th><th>命中</th><th>最後命中</th><th></th></tr></thead><tbody><?php if ( $rules ) : foreach ( $rules as $rule ) : ?><tr><td><code><?php echo esc_html( $rule->source_path ); ?></code></td><td><code><?php echo esc_html( $rule->target_url ); ?></code></td><td><?php echo 'active' === $rule->status ? '啟用' : '停用'; ?></td><td><?php echo esc_html( $rule->hit_count ); ?></td><td><?php echo $rule->last_hit_at ? esc_html( $rule->last_hit_at ) : '—'; ?></td><td><a href="<?php echo esc_url( add_query_arg( [ 'page' => self::PAGE_SLUG, 'edit' => $rule->id ], admin_url( 'admin.php' ) ) ); ?>">編輯</a> · <a class="wutm-smart-301-delete" href="<?php echo esc_url( wp_nonce_url( add_query_arg( [ 'action' => 'wutm_smart_301_delete', 'id' => $rule->id ], admin_url( 'admin-post.php' ) ), 'wutm_smart_301_delete_' . $rule->id ) ); ?>">刪除</a></td></tr><?php endforeach; else : ?><tr><td colspan="6">尚無轉址規則。</td></tr><?php endif; ?></tbody></table></div></div>
        <style>.wutm-smart-301{max-width:1100px}.wutm-smart-301-card{margin:20px 0;padding:22px;background:#fff;border:1px solid #dcdcde;border-radius:10px}.wutm-smart-301-card h2{margin:0 0 18px}.wutm-smart-301-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}.wutm-smart-301-grid label{font-weight:600}.wutm-smart-301-grid input:not([type=checkbox]),.wutm-smart-301-grid select{display:block;width:100%;min-height:38px;margin-top:7px}@media(max-width:700px){.wutm-smart-301-grid{grid-template-columns:1fr}}</style><script>document.addEventListener('click',function(e){if(e.target.classList.contains('wutm-smart-301-delete')&&!confirm('確定要刪除此 301 規則嗎？'))e.preventDefault()});</script>
        <?php
    }
}

new WUTM_Smart_301_Redirects();
