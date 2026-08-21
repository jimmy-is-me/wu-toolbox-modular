<?php
/**
 * 後台設定頁面模板
 *
 * 由 YSChatAdmin::render_page() 載入，可用變數：
 * - $settings  array 目前設定
 * - $migration ?array 移轉狀態
 *
 * @package WUTM\ContactWidgets
 * @since   1.0.0
 */

use WUTM\ContactWidgets\YSChatApps;

defined( 'ABSPATH' ) || exit;

/** @var array $settings */
/** @var ?array $migration */

$ysch_apps_ready = class_exists( YSChatApps::class );
$ysch_apps_defs = $ysch_apps_ready ? YSChatApps::all() : [];
?>
<!-- Hero Header（在 .wrap 外面，避免 WP notice 注入） -->
<div class="ysch-hero">
    <div class="ysch-hero-content">
        <div class="ysch-hero-title">
            <span class="dashicons dashicons-format-chat"></span>
            <?php echo esc_html__( '浮動聯絡按鈕', 'wu-contact-widgets' ); ?>
        </div>
        <div class="ysch-hero-subtitle"><?php
            printf(
                esc_html__( '浮動聯絡按鈕 — 由 %s 開發與維護', 'wu-contact-widgets' ),
                '<a href="https://yangsheep.com.tw" target="_blank" rel="noopener noreferrer" style="color:rgba(255,255,255,0.95);text-decoration:none;">YANGSHEEP CLOUD</a>'
            );
        ?></div>
    </div>
    <span class="ysch-version-badge">v<?php echo esc_html( WUTM_CONTACT_WIDGETS_VERSION ); ?></span>
</div>

<!-- WP notice 錨點 -->
<div class="wrap"><h2 style="display:none;"></h2></div>

<div class="ysch-admin-wrap">

    <?php if ( ! $ysch_apps_ready ) : ?>
    <div class="notice notice-error"><p><?php echo esc_html__( '浮動聯絡按鈕模組載入不完整，請重新啟用外掛或聯絡管理員。', 'wu-contact-widgets' ); ?></p></div>
    <?php endif; ?>

    <?php if ( $migration && 'migrated' === ( $migration['status'] ?? '' ) ) : ?>
    <div class="ysch-card ysch-card-migrated">
        <p>
            <span class="dashicons dashicons-yes-alt"></span>
            <?php
            printf(
                /* translators: 1: App 數量, 2: 移轉時間 */
                esc_html__( '已於 %2$s（UTC）自動移轉 NinjaTeam Click to Chat 的 %1$d 個 App，LINE 連結格式已修正，舊外掛已停用。確認顯示正常後即可刪除舊外掛。', 'wu-contact-widgets' ),
                (int) ( $migration['apps'] ?? 0 ),
                esc_html( $migration['at'] ?? '' )
            );
            ?>
        </p>
    </div>
    <?php endif; ?>

    <!-- 基本設定 -->
    <div class="ysch-card">
        <h2>
            <span class="dashicons dashicons-admin-appearance"></span>
            <?php echo esc_html__( '按鈕外觀', 'wu-contact-widgets' ); ?>
        </h2>

        <div class="ysch-field ysch-field-inline">
            <label class="ysch-switch">
                <input type="checkbox" id="ysch-enabled" <?php checked( ! empty( $settings['enabled'] ) ); ?> />
                <span class="ysch-switch-track"></span>
            </label>
            <label for="ysch-enabled" class="ysch-inline-label"><?php echo esc_html__( '啟用浮動按鈕', 'wu-contact-widgets' ); ?></label>
        </div>

        <div class="ysch-field">
            <label><?php echo esc_html__( '對齊位置', 'wu-contact-widgets' ); ?></label>
            <?php $pos = $settings['position'] ?? 'right'; ?>
            <div class="ysch-radio-group">
                <label class="ysch-radio-card">
                    <input type="radio" name="ysch-position" value="left" <?php checked( 'left' === $pos ); ?> />
                    <span class="ysch-radio-card-body">
                        <span class="ysch-pos-preview ysch-pos-preview-left"><i></i></span>
                        <?php echo esc_html__( '靠左下', 'wu-contact-widgets' ); ?>
                    </span>
                </label>
                <label class="ysch-radio-card">
                    <input type="radio" name="ysch-position" value="center" <?php checked( 'center' === $pos ); ?> />
                    <span class="ysch-radio-card-body">
                        <span class="ysch-pos-preview ysch-pos-preview-center"><i></i></span>
                        <?php echo esc_html__( '靠中下', 'wu-contact-widgets' ); ?>
                    </span>
                </label>
                <label class="ysch-radio-card">
                    <input type="radio" name="ysch-position" value="right" <?php checked( 'right' === $pos || ! in_array( $pos, [ 'left', 'center', 'right' ], true ) ); ?> />
                    <span class="ysch-radio-card-body">
                        <span class="ysch-pos-preview ysch-pos-preview-right"><i></i></span>
                        <?php echo esc_html__( '靠右下', 'wu-contact-widgets' ); ?>
                    </span>
                </label>
            </div>
        </div>

        <div class="ysch-field-row">
            <div class="ysch-field">
                <label for="ysch-bottom"><?php echo esc_html__( '距離底部（px）', 'wu-contact-widgets' ); ?></label>
                <input type="number" id="ysch-bottom" min="0" max="400" value="<?php echo esc_attr( (string) ( $settings['bottom'] ?? 30 ) ); ?>" />
            </div>
            <div class="ysch-field">
                <label for="ysch-side"><?php echo esc_html__( '距離側邊（px）', 'wu-contact-widgets' ); ?></label>
                <input type="number" id="ysch-side" min="0" max="400" value="<?php echo esc_attr( (string) ( $settings['side'] ?? 20 ) ); ?>" />
                <p class="description"><?php echo esc_html__( '靠中下時此項無作用。', 'wu-contact-widgets' ); ?></p>
            </div>
            <div class="ysch-field">
                <label for="ysch-button-color"><?php echo esc_html__( '按鈕顏色', 'wu-contact-widgets' ); ?></label>
                <input type="color" id="ysch-button-color" value="<?php echo esc_attr( (string) ( $settings['button_color'] ?? '#8fa8b8' ) ); ?>" />
            </div>
        </div>

        <div class="ysch-field-row">
            <div class="ysch-field">
                <label for="ysch-size-outer"><?php echo esc_html__( '主按鈕大小 · 外（px）', 'wu-contact-widgets' ); ?></label>
                <input type="number" id="ysch-size-outer" min="36" max="96" value="<?php echo esc_attr( (string) ( $settings['size_outer'] ?? 56 ) ); ?>" />
                <p class="description"><?php echo esc_html__( '預設 56。', 'wu-contact-widgets' ); ?></p>
            </div>
            <div class="ysch-field">
                <label for="ysch-size-inner"><?php echo esc_html__( '聯絡圖示大小 · 內（px）', 'wu-contact-widgets' ); ?></label>
                <input type="number" id="ysch-size-inner" min="28" max="80" value="<?php echo esc_attr( (string) ( $settings['size_inner'] ?? 46 ) ); ?>" />
                <p class="description"><?php echo esc_html__( '預設 46。', 'wu-contact-widgets' ); ?></p>
            </div>
        </div>

        <div class="ysch-field">
            <label for="ysch-button-icon"><?php echo esc_html__( '自訂按鈕圖示（選填）', 'wu-contact-widgets' ); ?></label>
            <div class="ysch-media-row">
                <input type="url" id="ysch-button-icon" value="<?php echo esc_attr( (string) ( $settings['button_icon'] ?? '' ) ); ?>" placeholder="https://" />
                <button type="button" class="ysch-btn ysch-btn-secondary" id="ysch-button-icon-pick"><?php echo esc_html__( '選擇圖片', 'wu-contact-widgets' ); ?></button>
            </div>
            <p class="description"><?php echo esc_html__( '留空使用內建聊天圖示。', 'wu-contact-widgets' ); ?></p>
        </div>

        <div class="ysch-field" id="ysch-icon-style-wrap">
            <label><?php echo esc_html__( '自訂圖示顯示方式', 'wu-contact-widgets' ); ?></label>
            <select id="ysch-icon-style">
                <option value="contain" <?php selected( 'cover' !== ( $settings['icon_style'] ?? 'contain' ) ); ?>><?php echo esc_html__( '置中縮放（保留按鈕底色，適合去背 icon）', 'wu-contact-widgets' ); ?></option>
                <option value="cover" <?php selected( 'cover' === ( $settings['icon_style'] ?? 'contain' ) ); ?>><?php echo esc_html__( '佔滿整圓（適合完整圓形圖片）', 'wu-contact-widgets' ); ?></option>
            </select>
        </div>

        <div class="ysch-field">
            <label><?php echo esc_html__( '顯示相容模式（防呆）', 'wu-contact-widgets' ); ?></label>
            <?php $sm = $settings['style_mode'] ?? 'auto'; ?>
            <select id="ysch-style-mode">
                <option value="auto"  <?php selected( 'auto', $sm ); ?>><?php echo esc_html__( '自動（推薦）— 偵測到按鈕被佈景主題影響才強制修正', 'wu-contact-widgets' ); ?></option>
                <option value="force" <?php selected( 'force', $sm ); ?>><?php echo esc_html__( '一律強制 — 永遠以最高優先級套用（適合已知主題很強勢）', 'wu-contact-widgets' ); ?></option>
                <option value="off"   <?php selected( 'off', $sm ); ?>><?php echo esc_html__( '關閉 — 不強制（若你想自己用 CSS 微調外觀）', 'wu-contact-widgets' ); ?></option>
            </select>
            <p class="description"><?php echo esc_html__( '若浮動按鈕在前台被佈景主題染色、變形或消失，「自動」會自動偵測並強制套用你的設定顏色與尺寸；問題嚴重時可改「一律強制」。', 'wu-contact-widgets' ); ?></p>
        </div>
    </div>

    <!-- Apps 管理 -->
    <div class="ysch-card">
        <h2>
            <span class="dashicons dashicons-share"></span>
            <?php echo esc_html__( '聯絡方式（Apps）', 'wu-contact-widgets' ); ?>
        </h2>

        <div class="ysch-app-picker">
            <?php foreach ( $ysch_apps_defs as $ysch_key => $ysch_def ) : ?>
                <button type="button" class="ysch-app-add" data-app="<?php echo esc_attr( $ysch_key ); ?>"
                        title="<?php echo esc_attr( sprintf( /* translators: %s: App 名稱 */ __( '加入 %s', 'wu-contact-widgets' ), $ysch_def['title'] ) ); ?>"
                        style="--ysch-app-color:<?php echo esc_attr( $ysch_def['color'] ); ?>;--ysch-app-fg:<?php echo esc_attr( $ysch_def['icon_fg'] ); ?>;">
                    <span class="ysch-app-add-icon"><?php echo YSChatApps::icon( $ysch_key ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                    <span class="ysch-app-add-label"><?php echo esc_html( $ysch_def['title'] ); ?></span>
                </button>
            <?php endforeach; ?>
        </div>

        <ul id="ysch-app-list" class="ysch-app-list"></ul>
        <p id="ysch-app-empty" class="ysch-empty" style="display:none;"></p>
    </div>

    <!-- 顯示條件 -->
    <div class="ysch-card">
        <h2>
            <span class="dashicons dashicons-visibility"></span>
            <?php echo esc_html__( '顯示條件', 'wu-contact-widgets' ); ?>
        </h2>

        <div class="ysch-field ysch-field-inline">
            <label><input type="checkbox" id="ysch-show-desktop" <?php checked( ! empty( $settings['show_desktop'] ) ); ?> /> <?php echo esc_html__( '桌機顯示', 'wu-contact-widgets' ); ?></label>
            <label><input type="checkbox" id="ysch-show-mobile" <?php checked( ! empty( $settings['show_mobile'] ) ); ?> /> <?php echo esc_html__( '手機顯示', 'wu-contact-widgets' ); ?></label>
        </div>

        <div class="ysch-field">
            <label><?php echo esc_html__( '點擊模式', 'wu-contact-widgets' ); ?></label>
            <div class="ysch-radio-group">
                <label class="ysch-radio-card">
                    <input type="radio" name="ysch-mode" value="redirect" <?php checked( 'popup' !== ( $settings['mode'] ?? 'redirect' ) ); ?> />
                    <span class="ysch-radio-card-body">
                        <span class="dashicons dashicons-external" style="font-size:22px;width:22px;height:22px;color:var(--ys-adm-primary-dark);"></span>
                        <?php echo esc_html__( '直接開啟', 'wu-contact-widgets' ); ?>
                    </span>
                </label>
                <label class="ysch-radio-card">
                    <input type="radio" name="ysch-mode" value="popup" <?php checked( 'popup' === ( $settings['mode'] ?? 'redirect' ) ); ?> />
                    <span class="ysch-radio-card-body">
                        <span class="dashicons dashicons-grid-view" style="font-size:22px;width:22px;height:22px;color:var(--ys-adm-primary-dark);"></span>
                        <?php echo esc_html__( 'QR Code 卡片', 'wu-contact-widgets' ); ?>
                    </span>
                </label>
            </div>
            <p class="description"><?php echo esc_html__( 'QR Code 卡片：桌機點擊時彈出本地生成的 QR Code（零 iframe、零外部請求），手機點擊仍直接開啟。', 'wu-contact-widgets' ); ?></p>
        </div>

        <div class="ysch-field">
            <label><?php echo esc_html__( '標籤文字', 'wu-contact-widgets' ); ?></label>
            <select id="ysch-tooltip">
                <option value="appname" <?php selected( 'appname', $settings['tooltip'] ?? 'appname' ); ?>><?php echo esc_html__( '顯示 App 名稱', 'wu-contact-widgets' ); ?></option>
                <option value="content" <?php selected( 'content', $settings['tooltip'] ?? 'appname' ); ?>><?php echo esc_html__( '顯示聯絡內容（ID / 號碼）', 'wu-contact-widgets' ); ?></option>
                <option value="none" <?php selected( 'none', $settings['tooltip'] ?? 'appname' ); ?>><?php echo esc_html__( '不顯示文字（只有圖示）', 'wu-contact-widgets' ); ?></option>
            </select>
        </div>

        <div class="ysch-field">
            <label><?php echo esc_html__( '頁面範圍', 'wu-contact-widgets' ); ?></label>
            <select id="ysch-display">
                <option value="all" <?php selected( 'all', $settings['display'] ?? 'all' ); ?>><?php echo esc_html__( '全站顯示', 'wu-contact-widgets' ); ?></option>
                <option value="include" <?php selected( 'include', $settings['display'] ?? 'all' ); ?>><?php echo esc_html__( '只在指定頁面顯示', 'wu-contact-widgets' ); ?></option>
                <option value="exclude" <?php selected( 'exclude', $settings['display'] ?? 'all' ); ?>><?php echo esc_html__( '在指定頁面隱藏', 'wu-contact-widgets' ); ?></option>
            </select>
        </div>

        <?php
        $ysch_pages = get_pages( [ 'sort_column' => 'menu_order,post_title' ] );
        $ysch_inc   = array_map( 'intval', (array) ( $settings['include_pages'] ?? [] ) );
        $ysch_exc   = array_map( 'intval', (array) ( $settings['exclude_pages'] ?? [] ) );
        ?>
        <div class="ysch-field" id="ysch-include-wrap" style="display:none;">
            <label for="ysch-include-pages"><?php echo esc_html__( '指定顯示的頁面（可多選）', 'wu-contact-widgets' ); ?></label>
            <select id="ysch-include-pages" multiple size="6">
                <?php foreach ( $ysch_pages as $ysch_p ) : ?>
                    <option value="<?php echo esc_attr( (string) $ysch_p->ID ); ?>" <?php selected( in_array( (int) $ysch_p->ID, $ysch_inc, true ) ); ?>><?php echo esc_html( $ysch_p->post_title ); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="ysch-field" id="ysch-exclude-wrap" style="display:none;">
            <label for="ysch-exclude-pages"><?php echo esc_html__( '指定隱藏的頁面（可多選）', 'wu-contact-widgets' ); ?></label>
            <select id="ysch-exclude-pages" multiple size="6">
                <?php foreach ( $ysch_pages as $ysch_p ) : ?>
                    <option value="<?php echo esc_attr( (string) $ysch_p->ID ); ?>" <?php selected( in_array( (int) $ysch_p->ID, $ysch_exc, true ) ); ?>><?php echo esc_html( $ysch_p->post_title ); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <!-- 儲存按鈕 -->
    <button type="button" id="ysch-save-btn" class="ysch-btn ysch-btn-primary">
        <span class="dashicons dashicons-saved"></span>
        <?php echo esc_html__( '儲存設定', 'wu-contact-widgets' ); ?>
    </button>

</div>
