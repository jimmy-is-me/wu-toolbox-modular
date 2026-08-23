<?php
namespace WUTM\GoLive;

use WUTM\GoLive\Traits\Singleton;

class Admin {
    use Singleton;

    public const NAME = 'wu-go-live-update-urls';
    public const PARENT_MENU = 'wu-toolbox-modular';
    public const OLD_URL = 'old_url';
    public const NEW_URL = 'new_url';
    public const TABLE_INPUT_NAME = 'wutm-go-live-update-urls/input/database-table';
    public const SUBMIT = 'wutm-go-live-update-urls/input/submit';
    public const NONCE = 'wutm-go-live-update-urls/nonce/update-tables';
    protected const CAPABILITY = 'manage_options';

    protected function hook(): void {
        add_action( 'admin_menu', [ $this, 'register_admin_page' ] );
        if ( isset( $_POST[ static::SUBMIT ] ) ) {
            add_action( 'init', [ $this, 'validate_update_submission' ] );
        }
    }

    public function register_admin_page(): void {
        add_submenu_page(
            self::PARENT_MENU,
            '網站網址更新',
            '網站網址更新',
            self::CAPABILITY,
            self::NAME,
            [ $this, 'admin_page' ]
        );
    }

    public function get_admin_capability(): string {
        return self::CAPABILITY;
    }

    public function get_url(): string {
        return admin_url( 'admin.php?page=' . self::NAME );
    }

    public function validate_update_submission(): void {
        if ( ! isset( $_POST[ static::NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ static::NONCE ] ) ), static::NONCE ) ) {
            wp_die( esc_html__( '驗證失敗，請重新整理頁面後再試。', 'wutm-go-live-update-urls' ) );
        }
        $old_url = wutm_go_live_update_urls_sanitize_field( (string) ( $_POST[ static::OLD_URL ] ?? '' ) );
        $new_url = wutm_go_live_update_urls_sanitize_field( (string) ( $_POST[ static::NEW_URL ] ?? '' ) );
        $tables = array_filter( array_map( 'wutm_go_live_update_urls_sanitize_field', (array) ( $_POST[ static::TABLE_INPUT_NAME ] ?? [] ) ) );
        if ( '' === $old_url || '' === $new_url || empty( $tables ) ) {
            add_action( 'admin_notices', static function (): void {
                echo '<div class="notice notice-error"><p>' . esc_html__( '請選擇資料表，並填寫舊網址與新網址。', 'wutm-go-live-update-urls' ) . '</p></div>';
            } );
            return;
        }
        $updated = \\WUTM\\GoLive\\Database::instance()->update_the_database( $old_url, $new_url, $tables );
        $changed = array_sum( array_map( 'intval', (array) $updated ) );
        if ( $changed > 0 ) {
            add_action( 'admin_notices', static function () use ( $changed ): void {
                echo '<div class="notice notice-success"><p>' . sprintf( esc_html__( '網址更新已完成，共修改 %d 筆資料。', 'wutm-go-live-update-urls' ), $changed ) . '</p></div>';
            } );
        } else {
            add_action( 'admin_notices', static function (): void {
                echo '<div class="notice notice-warning"><p>' . esc_html__( '未找到符合的舊網址，資料庫沒有變更。請確認網址、結尾斜線與資料表選項。', 'wutm-go-live-update-urls' ) . '</p></div>';
            } );
        }
    }

    public function admin_page(): void {
        wp_enqueue_script( 'wutm-go-live-update-urls-js', WUTM_GO_LIVE_URLS_URL . 'assets/go-live-update-urls.js', [ 'jquery' ], WUTM_GO_LIVE_URLS_VERSION, true );
        wp_enqueue_style( 'wutm-go-live-update-urls-css', WUTM_GO_LIVE_URLS_URL . 'assets/go-live-update-urls.css', [], WUTM_GO_LIVE_URLS_VERSION );
        ?>
        <div class="wutm-module-wrap wutm-go-live-page">
            <h1>網站網址更新</h1>
            <p class="wutm-module-subtitle">將資料庫中的舊網址安全替換為新網址，支援序列化資料。</p>
            <form method="post">
                <?php wp_nonce_field( static::NONCE, static::NONCE ); ?>
                <h2>WordPress 核心資料表</h2>
                <p class="description">勾選要更新的資料表。更新前請先備份資料庫。</p>
                <?php $this->render_check_boxes( \WUTM\GoLive\Database::instance()->get_core_tables(), 'wp-core' ); ?>
                <table class="form-table">
                    <tr><th><label for="old_url">舊網址</label></th><td><input class="regular-text" type="url" id="old_url" name="<?php echo esc_attr( self::OLD_URL ); ?>" required></td></tr>
                    <tr><th><label for="new_url">新網址</label></th><td><input class="regular-text" type="url" id="new_url" name="<?php echo esc_attr( self::NEW_URL ); ?>" required></td></tr>
                </table>
                <?php submit_button( '更新網址', 'primary', self::SUBMIT ); ?>
            </form>
        </div>
        <?php
    }

    public function render_check_boxes( array $tables, string $list_id, bool $checked = true ): void {
        echo '<div class="go-live-section"><label><input type="checkbox" data-list="' . esc_attr( $list_id ) . '" data-js="wutm-go-live-update-urls/checkboxes/check-all" checked> 只會更新勾選的資料表</label><ul data-list="' . esc_attr( $list_id ) . '">';
        foreach ( $tables as $table ) {
            printf( '<li><label><input name="%s[]" type="checkbox" value="%s" %s> %s</label></li>', esc_attr( self::TABLE_INPUT_NAME ), esc_attr( $table ), checked( $checked, true, false ), esc_html( $table ) );
        }
        echo '</ul></div>';
    }
}
