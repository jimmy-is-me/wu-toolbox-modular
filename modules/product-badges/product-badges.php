<?php
/**
 * Plugin Name: WC 商品徽章
 * Description: 商品徽章管理、多選套用、快速編輯、商品列表及單一商品頁顯示。
 * Version: 1.1.0
 * Requires PHP: 7.4
 * Author: Site Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WCRM_Product_Marks' ) ) {

    final class WCRM_Product_Marks {

        // 保留上一版識別名稱，沿用既有資料。
        const TAX     = 'wcrm_product_mark';
        const VERSION = WUTM_VERSION;

        public static function boot() {

            add_action( 'init', array( __CLASS__, 'register' ), 20 );
            add_action( 'admin_init', array( __CLASS__, 'seed' ) );
            add_action( 'admin_init', array( __CLASS__, 'refresh_archive_rules' ) );
            add_filter( 'default_hidden_meta_boxes', array( __CLASS__, 'show_menu_box' ), 10, 2 );

            add_action(
                self::TAX . '_add_form_fields',
                array( __CLASS__, 'add_fields' )
            );

            add_action(
                self::TAX . '_edit_form_fields',
                array( __CLASS__, 'edit_fields' )
            );

            add_action(
                'created_' . self::TAX,
                array( __CLASS__, 'save_fields' )
            );

            add_action(
                'edited_' . self::TAX,
                array( __CLASS__, 'save_fields' )
            );

            add_filter(
                'manage_edit-' . self::TAX . '_columns',
                array( __CLASS__, 'term_columns' )
            );

            add_filter(
                'manage_' . self::TAX . '_custom_column',
                array( __CLASS__, 'term_column' ),
                10,
                3
            );

            add_action(
                'add_meta_boxes_product',
                array( __CLASS__, 'add_box' )
            );

            add_action(
                'save_post_product',
                array( __CLASS__, 'save_product' ),
                30,
                2
            );

            add_filter(
                'manage_edit-product_columns',
                array( __CLASS__, 'product_columns' ),
                30
            );

            add_action(
                'manage_product_posts_custom_column',
                array( __CLASS__, 'product_column' ),
                20,
                2
            );

            add_action(
                'quick_edit_custom_box',
                array( __CLASS__, 'quick_edit_box' ),
                10,
                2
            );

            add_action(
                'woocommerce_product_quick_edit_save',
                array( __CLASS__, 'save_quick_edit' )
            );

            add_action(
                'admin_enqueue_scripts',
                array( __CLASS__, 'admin_assets' )
            );

            add_action(
                'wp_enqueue_scripts',
                array( __CLASS__, 'frontend_assets' )
            );

            add_action(
                'woocommerce_before_shop_loop_item_title',
                array( __CLASS__, 'loop' ),
                9
            );

            add_action(
                'woocommerce_single_product_summary',
                array( __CLASS__, 'single' ),
                4
            );

            add_shortcode(
                'wcrm_marks',
                array( __CLASS__, 'shortcode' )
            );
        }

        public static function register() {

            if ( ! class_exists( 'WooCommerce' ) ) {
                return;
            }

            register_taxonomy(
                self::TAX,
                array( 'product' ),
                array(
                    'labels' => array(
                        'name'          => '商品徽章',
                        'singular_name' => '商品徽章',
                        'menu_name'     => '商品徽章',
                        'all_items'     => '所有商品徽章',
                        'search_items'  => '搜尋商品徽章',
                        'edit_item'     => '編輯商品徽章',
                        'update_item'   => '更新商品徽章',
                        'add_new_item'  => '新增商品徽章',
                        'new_item_name' => '新商品徽章名稱',
                        'not_found'     => '尚無商品徽章',
                        'back_to_items' => '返回商品徽章',
                    ),
                    'hierarchical'       => false,
                    'public'             => true,
                    'publicly_queryable' => true,
                    'show_ui'            => true,
                    'show_in_menu'       => true,
                    'show_in_nav_menus'  => true,
                    'show_tagcloud'      => false,
                    'show_in_rest'       => false,
                    'show_in_quick_edit' => false,
                    'show_admin_column'  => false,
                    'meta_box_cb'        => false,
                    'rewrite'            => array('slug' => 'product-badge', 'with_front' => false),
                    'query_var'          => self::TAX,
                    'capabilities'       => array(
                        'manage_terms' => 'manage_woocommerce',
                        'edit_terms'   => 'manage_woocommerce',
                        'delete_terms' => 'manage_woocommerce',
                        'assign_terms' => 'edit_products',
                    ),
                )
            );
        }

        public static function refresh_archive_rules() {
            // Refresh only once after this archive format is introduced, never on visitor requests.
            if (wp_doing_ajax() || !current_user_can('manage_options') || !taxonomy_exists(self::TAX)
                || get_option('wcrm_archive_rules_version') === '1') return;
            flush_rewrite_rules(false);
            update_option('wcrm_archive_rules_version', '1', false);
        }

        public static function show_menu_box($hidden, $screen) {
            if (isset($screen->id) && $screen->id === 'nav-menus') {
                return array_values(array_diff($hidden, array('add-' . self::TAX)));
            }
            return $hidden;
        }

        private static function nonce_valid( $field, $action ) {

            return isset( $_POST[ $field ] )
                && is_string( $_POST[ $field ] )
                && wp_verify_nonce(
                    sanitize_text_field(
                        wp_unslash( $_POST[ $field ] )
                    ),
                    $action
                );
        }

        public static function seed() {
            if (wp_doing_ajax()) return; // Seed on regular admin screens, never slow every AJAX request.

            if (
                ! taxonomy_exists( self::TAX )
                || ! current_user_can( 'manage_woocommerce' )
                || get_option( 'wcrm_seeded_v1' )
            ) {
                return;
            }

            $defaults = array(
                array( '新上架', 'new-arrival', '#2563eb', 10 ),
                array( '熱賣商品', 'hot-buy', '#ea580c', 20 ),
                array( '優惠商品', 'special-offer', '#dc2626', 30 ),
            );

            $ok = true;

            foreach ( $defaults as $row ) {

                if ( term_exists( $row[1], self::TAX ) ) {
                    continue;
                }

                $result = wp_insert_term(
                    $row[0],
                    self::TAX,
                    array( 'slug' => $row[1] )
                );

                if ( is_wp_error( $result ) ) {
                    $ok = false;
                    continue;
                }

                $id = $result['term_id'];

                update_term_meta( $id, '_wcrm_bg', $row[2] );
                update_term_meta( $id, '_wcrm_fg', '#ffffff' );
                update_term_meta( $id, '_wcrm_order', $row[3] );
                update_term_meta( $id, '_wcrm_enabled', '1' );
            }

            if ( $ok ) {
                update_option( 'wcrm_seeded_v1', 1, false );
            }
        }

        private static function values( $id = 0 ) {

            $bg = $id
                ? sanitize_hex_color(
                    get_term_meta( $id, '_wcrm_bg', true )
                )
                : '';

            $fg = $id
                ? sanitize_hex_color(
                    get_term_meta( $id, '_wcrm_fg', true )
                )
                : '';

            return array(
                'bg'      => $bg ?: '#2563eb',
                'fg'      => $fg ?: '#ffffff',
                'order'   => $id
                    ? absint( get_term_meta( $id, '_wcrm_order', true ) )
                    : 10,
                'enabled' => ! $id
                    || '0' !== get_term_meta(
                        $id,
                        '_wcrm_enabled',
                        true
                    ),
            );
        }

        private static function terms( $product_id = 0 ) {

            if ( ! taxonomy_exists( self::TAX ) ) {
                return array();
            }

            $terms = $product_id
                ? get_the_terms( $product_id, self::TAX )
                : get_terms(
                    array(
                        'taxonomy'   => self::TAX,
                        'hide_empty' => false,
                    )
                );

            if ( ! $terms || is_wp_error( $terms ) ) {
                return array();
            }

            // Prime shared badge metadata in one batch instead of one query per badge.
            update_termmeta_cache(wp_list_pluck($terms, 'term_id'));
            $orders = array();

            foreach ( $terms as $term ) {
                $orders[ $term->term_id ] = absint(
                    get_term_meta(
                        $term->term_id,
                        '_wcrm_order',
                        true
                    )
                );
            }

            usort(
                $terms,
                static function ( $a, $b ) use ( $orders ) {

                    $compare = $orders[ $a->term_id ]
                        <=> $orders[ $b->term_id ];

                    return $compare
                        ?: strcmp( $a->name, $b->name );
                }
            );

            return $terms;
        }

        private static function product_ids( $product_id ) {

            return array_map(
                'intval',
                wp_list_pluck(
                    self::terms( $product_id ),
                    'term_id'
                )
            );
        }

        private static function set_product_terms( $product_id, $raw ) {

            $ids = array();

            if ( is_array( $raw ) ) {

                foreach ( $raw as $value ) {

                    if ( ! is_scalar( $value ) ) {
                        continue;
                    }

                    $id = absint( $value );

                    if ( ! $id ) {
                        continue;
                    }

                    $term = get_term( $id, self::TAX );

                    if ( $term && ! is_wp_error( $term ) ) {
                        $ids[] = $id;
                    }
                }
            }

            if (!is_array($raw)) return; // Malformed input must not clear existing selections.
            wp_set_object_terms(
                $product_id,
                array_values( array_unique( $ids ) ),
                self::TAX,
                false
            );
        }

        private static function controls( $id = 0, $table = false ) {

            $v = self::values( $id );

            wp_nonce_field(
                'wcrm_term_fields',
                'wcrm_term_nonce',
                false
            );

            $fields = array(
                'wcrm_bg' => array(
                    '背景顏色',
                    'color',
                    $v['bg'],
                ),
                'wcrm_fg' => array(
                    '文字顏色',
                    'color',
                    $v['fg'],
                ),
                'wcrm_order' => array(
                    '顯示順序',
                    'number',
                    $v['order'],
                ),
            );

            foreach ( $fields as $key => $field ) {

                $label = '<label for="' . esc_attr( $key ) . '">'
                    . esc_html( $field[0] )
                    . '</label>';

                $input = '<input type="' . esc_attr( $field[1] )
                    . '" id="' . esc_attr( $key )
                    . '" name="' . esc_attr( $key )
                    . '" value="' . esc_attr( $field[2] ) . '"'
                    . (
                        'number' === $field[1]
                            ? ' min="0" max="9999" step="1"'
                            : ''
                    )
                    . '>';

                if ( 'wcrm_order' === $key ) {
                    $input .= '<p class="description">'
                        . '數字越小越先顯示。'
                        . '</p>';
                }

                if ( $table ) {
                    echo '<tr class="form-field">'
                        . '<th scope="row">' . $label . '</th>'
                        . '<td>' . $input . '</td></tr>';
                } else {
                    echo '<div class="form-field">'
                        . $label . $input . '</div>';
                }
            }

            $toggle = '<input type="hidden"'
                . ' name="wcrm_enabled" value="0">'
                . '<label><input type="checkbox"'
                . ' name="wcrm_enabled" value="1" '
                . checked( $v['enabled'], true, false )
                . '> 前台顯示此徽章</label>'
                . '<p class="description">'
                . '停用只會隱藏前台徽章，不會取消商品已勾選的設定。'
                . '</p>';

            if ( $table ) {
                echo '<tr class="form-field">'
                    . '<th scope="row">顯示狀態</th>'
                    . '<td>' . $toggle . '</td></tr>';
            } else {
                echo '<div class="form-field">'
                    . $toggle . '</div>';
            }
        }

        public static function add_fields() {
            echo '<p class="description">每個徽章都有商品集合頁，可在「外觀 → 選單 → 商品徽章」加入選單；若未看到此區塊，可在顯示項目設定勾選「商品徽章」。停用僅隱藏圖片徽章，集合頁仍保留。</p>';
            self::controls();
        }

        public static function edit_fields( $term ) {
            self::controls( $term->term_id, true );
        }

        public static function save_fields( $term_id ) {

            if (
                ! current_user_can( 'manage_woocommerce' )
                || ! self::nonce_valid(
                    'wcrm_term_nonce',
                    'wcrm_term_fields'
                )
            ) {
                return;
            }

            foreach ( array( 'bg', 'fg' ) as $key ) {

                $field = 'wcrm_' . $key;

                $raw = isset( $_POST[ $field ] )
                    && is_string( $_POST[ $field ] )
                        ? wp_unslash( $_POST[ $field ] )
                        : '';

                $color = sanitize_hex_color( $raw );

                if ( $color ) {
                    update_term_meta(
                        $term_id,
                        '_wcrm_' . $key,
                        $color
                    );
                }
            }

            $order = isset( $_POST['wcrm_order'] )
                && is_scalar( $_POST['wcrm_order'] )
                    ? min( 9999, absint( $_POST['wcrm_order'] ) )
                    : 10;

            update_term_meta(
                $term_id,
                '_wcrm_order',
                $order
            );

            update_term_meta(
                $term_id,
                '_wcrm_enabled',
                isset( $_POST['wcrm_enabled'] )
                    && '1' === $_POST['wcrm_enabled']
                        ? '1'
                        : '0'
            );
        }

        private static function badge( $term, $admin = false ) {

            $v = self::values( $term->term_id );

            $label = $term->name;

            if ( $admin && ! $v['enabled'] ) {
                $label .= '（停用）';
            }

            return '<span class="wcrm-mark'
                . ( $admin ? ' wcrm-mark--admin' : '' )
                . '" style="--wcrm-bg:' . esc_attr( $v['bg'] )
                . ';--wcrm-fg:' . esc_attr( $v['fg'] )
                . ';">' . esc_html( $label ) . '</span>';
        }

        public static function term_columns( $columns ) {

            $columns['wcrm_preview'] = '徽章預覽';
            $columns['wcrm_order']   = '順序';
            $columns['wcrm_status']  = '前台狀態';
            $columns['wcrm_archive'] = '商品集合頁';

            return $columns;
        }

        public static function term_column(
            $content,
            $column,
            $term_id
        ) {

            $term = get_term( $term_id, self::TAX );

            if ( ! $term || is_wp_error( $term ) ) {
                return $content;
            }

            $v = self::values( $term_id );

            switch ( $column ) {

                case 'wcrm_preview':
                    return self::badge( $term, true );

                case 'wcrm_order':
                    return (string) $v['order'];

                case 'wcrm_status':
                    return $v['enabled'] ? '顯示' : '停用';
                case 'wcrm_archive':
                    $url = get_term_link($term, self::TAX);
                    return is_wp_error($url) ? '' : '<a href="' . esc_url($url) . '">查看商品集合</a>';
            }

            return $content;
        }

        public static function add_box() {

            if (
                taxonomy_exists( self::TAX )
                && current_user_can( 'edit_products' )
            ) {
                add_meta_box(
                    'wcrm_product_marks_box',
                    '商品徽章',
                    array( __CLASS__, 'box' ),
                    'product',
                    'side',
                    'default'
                );
            }
        }

        private static function checkboxes(
            $name,
            $selected = array()
        ) {

            $terms = self::terms();

            if ( ! $terms ) {
                echo '<p>尚未建立商品徽章。</p>';
                return;
            }

            foreach ( $terms as $term ) {

                $v = self::values( $term->term_id );

                echo '<label class="wcrm-choice">'
                    . '<input type="checkbox" name="'
                    . esc_attr( $name ) . '[]" value="'
                    . esc_attr( $term->term_id ) . '" '
                    . checked(
                        in_array(
                            (int) $term->term_id,
                            $selected,
                            true
                        ),
                        true,
                        false
                    )
                    . '> <span>'
                    . esc_html( $term->name )
                    . ( $v['enabled'] ? '' : '（前台停用）' )
                    . '</span></label>';
            }
        }

        public static function box( $post ) {

            wp_nonce_field(
                'wcrm_product_' . $post->ID,
                'wcrm_product_nonce',
                false
            );

            echo '<div class="wcrm-product-choices">';

            self::checkboxes(
                'wcrm_marks',
                self::product_ids( $post->ID )
            );

            echo '</div>'
                . '<p class="description">'
                . '可勾選多個；全部取消即不顯示徽章。'
                . '</p>';

            if ( current_user_can( 'manage_woocommerce' ) ) {

                echo '<p><a href="'
                    . esc_url(
                        admin_url(
                            'edit-tags.php?taxonomy='
                            . self::TAX
                            . '&post_type=product'
                        )
                    )
                    . '">管理／新增商品徽章</a></p>';
            }
        }

        public static function save_product( $post_id, $post ) {

            if (
                ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
                || wp_is_post_revision( $post_id )
                || ! taxonomy_exists( self::TAX )
                || ! current_user_can( 'edit_post', $post_id )
                || ! current_user_can( 'edit_products' )
                || ! self::nonce_valid(
                    'wcrm_product_nonce',
                    'wcrm_product_' . $post_id
                )
            ) {
                return;
            }

            self::set_product_terms(
                $post_id,
                isset( $_POST['wcrm_marks'] )
                    ? $_POST['wcrm_marks']
                    : array()
            );
        }

        public static function product_columns( $columns ) {

            if ( ! taxonomy_exists( self::TAX ) ) {
                return $columns;
            }

            unset( $columns['wcrm_marks'] );

            $result = array();

            foreach ( $columns as $key => $label ) {

                $result[ $key ] = $label;

                if ( 'name' === $key ) {
                    $result['wcrm_marks'] = '商品徽章';
                }
            }

            if ( ! isset( $result['wcrm_marks'] ) ) {
                $result['wcrm_marks'] = '商品徽章';
            }

            return $result;
        }

        public static function product_column(
            $column,
            $post_id
        ) {

            // 放在名稱欄，避免隱藏徽章欄後無法帶入快速編輯。
            if ( 'name' === $column ) {

                echo '<span class="wcrm-row-data" hidden'
                    . ' data-marks="'
                    . esc_attr(
                        wp_json_encode(
                            self::product_ids( $post_id )
                        )
                    )
                    . '"></span>';

                return;
            }

            if ( 'wcrm_marks' !== $column ) {
                return;
            }

            $terms = self::terms( $post_id );

            if ( ! $terms ) {
                echo '<span class="wcrm-empty">—</span>';
                return;
            }

            echo '<div class="wcrm-admin-badges">';

            foreach ( $terms as $term ) {
                echo self::badge( $term, true );
            }

            echo '</div>';
        }

        public static function quick_edit_box(
            $column,
            $post_type
        ) {

            if (
                'product' !== $post_type
                || 'wcrm_marks' !== $column
                || ! taxonomy_exists( self::TAX )
                || ! current_user_can( 'edit_products' )
            ) {
                return;
            }

            echo '<fieldset class="wcrm-quick-fieldset">'
                . '<div class="inline-edit-col">'
                . '<h4>商品徽章</h4>';

            wp_nonce_field(
                'wcrm_quick_edit',
                'wcrm_quick_nonce',
                false
            );

            echo '<input type="hidden"'
                . ' name="wcrm_quick_ready" value="0">'
                . '<div class="wcrm-quick-choices">';

            self::checkboxes( 'wcrm_quick_marks' );

            echo '</div>'
                . '<p class="description">'
                . '可勾選多個；全部取消後更新，即移除商品徽章。'
                . '</p>'
                . '<p class="wcrm-quick-error" hidden>'
                . '無法讀取原本的徽章設定，本次不會修改徽章。'
                . '請重新整理或使用完整商品編輯頁。'
                . '</p>'
                . '</div></fieldset>';
        }

        public static function save_quick_edit( $product ) {

            if ( ! $product instanceof WC_Product ) {
                return;
            }

            $id = $product->get_id();

            if (
                ! taxonomy_exists( self::TAX )
                || ! current_user_can( 'edit_post', $id )
                || ! current_user_can( 'edit_products' )
                || ! isset( $_POST['wcrm_quick_ready'] )
                || '1' !== $_POST['wcrm_quick_ready']
                || ! self::nonce_valid(
                    'wcrm_quick_nonce',
                    'wcrm_quick_edit'
                )
            ) {
                return;
            }

            self::set_product_terms(
                $id,
                isset( $_POST['wcrm_quick_marks'] )
                    ? $_POST['wcrm_quick_marks']
                    : array()
            );
        }

        public static function admin_assets( $hook ) {

            $screen = get_current_screen();

            if (
                ! $screen
                || ! taxonomy_exists( self::TAX )
                || (
                    'product' !== $screen->post_type
                    && self::TAX !== $screen->taxonomy
                )
            ) {
                return;
            }

            wp_register_style(
                'wcrm-admin',
                false,
                array(),
                self::VERSION
            );

            wp_enqueue_style( 'wcrm-admin' );

            wp_add_inline_style(
                'wcrm-admin',
                '
                .wcrm-mark--admin {
                    display: inline-block;
                    background: var(--wcrm-bg, #2563eb);
                    color: var(--wcrm-fg, #ffffff);
                    padding: 3px 8px;
                    border-radius: 4px;
                    font-size: 12px;
                    font-weight: 500;
                    line-height: 1.6;
                    white-space: nowrap;
                    word-break: normal;
                    overflow-wrap: normal;
                    box-sizing: border-box;
                }

                .wcrm-admin-badges {
                    display: flex;
                    flex-wrap: wrap;
                    align-items: flex-start;
                    gap: 5px;
                }

                .wp-list-table .column-wcrm_marks {
                    width: 150px;
                    white-space: normal;
                    word-break: normal;
                }

                .wp-list-table th.column-wcrm_marks {
                    white-space: nowrap;
                }

                .wcrm-product-choices {
                    max-height: 280px;
                    overflow-y: auto;
                }

                .wcrm-product-choices .wcrm-choice {
                    display: flex;
                    align-items: flex-start;
                    gap: 5px;
                    margin: 9px 0;
                }

                .wcrm-choice input[type="checkbox"] {
                    flex-shrink: 0;
                    margin-top: 2px;
                }

                .inline-edit-row fieldset.wcrm-quick-fieldset {
                    float: none;
                    clear: both;
                    display: block;
                    width: 100%;
                    padding: 12px 0 0;
                    margin: 12px 0 0;
                    border-top: 1px solid #dcdcde;
                    box-sizing: border-box;
                }

                .wcrm-quick-fieldset .inline-edit-col {
                    margin: 0 10px;
                }

                .wcrm-quick-choices {
                    display: flex;
                    flex-wrap: wrap;
                    align-items: flex-start;
                    gap: 10px 18px;
                    max-height: 200px;
                    overflow-y: auto;
                    padding: 6px 0;
                }

                .inline-edit-row .wcrm-quick-choices .wcrm-choice {
                    display: flex;
                    align-items: flex-start;
                    gap: 5px;
                    float: none;
                    width: auto;
                    margin: 0;
                    padding: 0;
                    line-height: 1.6;
                }

                .wcrm-quick-error {
                    color: #b32d2e;
                }

                .wcrm-admin-table-scroll {
                    width: 100%;
                    overflow-x: auto;
                }

                @media (min-width: 783px) {
                    .wcrm-admin-table-scroll > table {
                        min-width: 1450px;
                    }

                    .wp-list-table .column-wcrm_marks {
                        width: 150px !important;
                    }
                }

                @media (max-width: 782px) {
                    .wp-list-table td.column-wcrm_marks {
                        width: auto;
                    }
                }
                '
            );

            if (
                'edit.php' !== $hook
                || 'product' !== $screen->post_type
            ) {
                if (self::TAX === $screen->taxonomy) {
                    wp_add_inline_style('wcrm-admin', '
                    body.taxonomy-wcrm_product_mark .wrap>h1{background:#1d2327;color:#fff;padding:22px 24px;border-radius:10px;margin:16px 0 24px}
                    body.taxonomy-wcrm_product_mark #col-left .col-wrap,body.taxonomy-wcrm_product_mark .term-php .form-table{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:20px;box-sizing:border-box}
                    body.taxonomy-wcrm_product_mark #col-left input:not([type=checkbox]):not([type=color]),body.taxonomy-wcrm_product_mark #col-left textarea{max-width:100%;box-sizing:border-box}
                    body.taxonomy-wcrm_product_mark input[type=color]{width:64px;height:38px;padding:3px;border-radius:6px}
                    @media(max-width:782px){body.taxonomy-wcrm_product_mark #col-left .col-wrap{padding:16px}.wcrm-mark--admin{max-width:100%;white-space:normal;overflow-wrap:anywhere}}
                    ');
                }
                return;
            }

            wp_enqueue_script( 'inline-edit-post' );

            wp_add_inline_script(
                'inline-edit-post',
                <<<'JS'
jQuery(function ($) {

    var $table = $('table.wp-list-table.products');

    if (
        $table.length &&
        !$table.parent().hasClass('wcrm-admin-table-scroll')
    ) {
        $table.wrap('<div class="wcrm-admin-table-scroll"></div>');
    }

    if (
        typeof inlineEditPost === 'undefined' ||
        inlineEditPost.wcrmWrapped
    ) {
        return;
    }

    var originalEdit = inlineEditPost.edit;

    inlineEditPost.edit = function (id) {

        originalEdit.apply(this, arguments);

        var postId = typeof id === 'object'
            ? parseInt(this.getId(id), 10)
            : parseInt(id, 10);

        if (!postId) {
            return;
        }

        var $edit = $('#edit-' + postId);
        var $box = $edit.find('.wcrm-quick-fieldset');

        if (!$box.length) {
            return;
        }

        var $checks = $box.find(
            'input[name="wcrm_quick_marks[]"]'
        );

        var $ready = $box.find(
            'input[name="wcrm_quick_ready"]'
        );

        var $error = $box.find('.wcrm-quick-error');

        $checks.prop('checked', false).prop('disabled', false);
        $ready.val('0');
        $error.prop('hidden', true);

        var $data = $('#post-' + postId)
            .find('.wcrm-row-data')
            .first();

        var selected;

        try {

            if (!$data.length) {
                throw new Error('Missing badge data');
            }

            selected = JSON.parse(
                $data.attr('data-marks') || '[]'
            );

            if (!Array.isArray(selected)) {
                throw new Error('Invalid badge data');
            }

        } catch (error) {

            $checks.prop('disabled', true);
            $error.prop('hidden', false);

            return;
        }

        selected = selected.map(function (value) {
            return String(value);
        });

        $checks.each(function () {
            this.checked = selected.indexOf(this.value) !== -1;
        });

        $ready.val('1');
    };

    inlineEditPost.wcrmWrapped = true;
});
JS
            );
        }

        public static function html(
            $product_id,
            $overlay = true
        ) {

            if (
                ! taxonomy_exists( self::TAX )
                || ! function_exists( 'wc_get_product' )
            ) {
                return '';
            }

            $product = wc_get_product(
                absint( $product_id )
            );

            if ( ! $product ) {
                return '';
            }

            $id = $product->is_type( 'variation' )
                ? $product->get_parent_id()
                : $product->get_id();

            $items = '';

            foreach ( self::terms( $id ) as $term ) {

                $v = self::values( $term->term_id );

                if ( $v['enabled'] ) {
                    $items .= self::badge( $term );
                }
            }

            if ( '' === $items ) {
                return '';
            }

            return self::ensure_style() . '<span class="wcrm-marks '
                . (
                    $overlay
                        ? 'wcrm-marks--overlay'
                        : 'wcrm-marks--inline'
                )
                . '">' . $items . '</span>';
        }

        public static function loop() {

            global $product;

            if (
                $product instanceof WC_Product
                && apply_filters(
                    'wcrm_auto_loop_display',
                    true,
                    $product
                )
            ) {
                echo self::html(
                    $product->get_id(),
                    true
                );
            }
        }

        public static function single() {

            global $product;

            if (
                ! $product instanceof WC_Product
                || ! apply_filters(
                    'wcrm_single_display',
                    true,
                    $product
                )
            ) {
                return;
            }

            $html = self::html(
                $product->get_id(),
                false
            );

            if ( '' !== $html ) {

                // JS 移至圖片區；無圖片區或停用 JS 時留在標題上方。
                echo '<div class="wcrm-single-source">'
                    . $html
                    . '</div>';
            }
        }

        public static function shortcode( $atts ) {

            $atts = shortcode_atts(
                array(
                    'product_id' => 0,
                    'position'   => 'inline',
                ),
                $atts,
                'wcrm_marks'
            );

            $id = absint( $atts['product_id'] );

            if ( ! $id ) {

                global $product;

                $id = $product instanceof WC_Product
                    ? $product->get_id()
                    : get_the_ID();
            }

            return self::html(
                $id,
                'overlay' === $atts['position']
            );
        }

        private static $style_emitted = false;

        private static function ensure_style() {
            if (self::$style_emitted) return '';
            self::$style_emitted = true;
            $css = self::frontend_css();
            if (did_action('wp_print_styles')) return '<style id="wcrm-product-marks-inline">' . $css . '</style>';
            wp_register_style('wcrm-product-marks', false, array(), self::VERSION);
            wp_enqueue_style('wcrm-product-marks');
            wp_add_inline_style('wcrm-product-marks', $css);
            return '';
        }

        private static function frontend_css() {
            return <<<'CSS'
ul.products li.product,
                .wcrm-mark-anchor {
                    position: relative;
                }

                .wcrm-marks {
                    display: flex;
                    gap: var(--wcrm-gap, 6px);
                    margin: 0;
                    padding: 0;
                    pointer-events: none;
                    box-sizing: border-box;
                }

                .wcrm-marks--overlay {
                    position: absolute;
                    top: var(--wcrm-top, 12px);
                    right: var(--wcrm-right, 12px);
                    z-index: 30;
                    flex-direction: column;
                    align-items: flex-end;
                    max-width: calc(100% - 24px);
                }

                .wcrm-marks--inline {
                    position: static;
                    flex-wrap: wrap;
                    align-items: center;
                }

                .wcrm-mark {
                    display: inline-block;
                    background: var(--wcrm-bg, #2563eb);
                    color: var(--wcrm-fg, #ffffff);
                    padding: 5px 9px;
                    border-radius: var(--wcrm-radius, 4px);
                    font-size: var(--wcrm-font-size, 12px);
                    line-height: 1.4;
                    font-weight: 600;
                    font-style: normal;
                    text-align: center;
                    letter-spacing: .02em;
                    max-width: 100%;
                    overflow-wrap: anywhere;
                    box-sizing: border-box;
                    box-shadow: 0 2px 6px rgba(0,0,0,.12);
                }

                .wcrm-single-source {
                    margin: 0 0 12px;
                    clear: both;
                }

                @media (max-width: 480px) {
                    .wcrm-marks--overlay {
                        top: var(--wcrm-mobile-top, 8px);
                        right: var(--wcrm-mobile-right, 8px);
                    }

                    .wcrm-mark {
                        font-size: var(--wcrm-mobile-font-size, 11px);
                        padding: 4px 7px;
                    }
                }

                .wcrm-marks--single {
                    top: var(--wcrm-single-top, 12px);
                    right: var(--wcrm-single-right, 52px);
                    max-width: calc(100% - 64px);
                }
CSS;
        }

        public static function frontend_assets() {

            if ( ! taxonomy_exists( self::TAX ) ) {
                return;
            }


            if (
                ! function_exists( 'is_product' )
                || ! is_product()
            ) {
                return;
            }

            // Product-only script; no markup/asset work for products without enabled badges.
            $enabled = false;
            foreach (self::terms(get_queried_object_id()) as $term) {
                if (self::values($term->term_id)['enabled']) { $enabled = true; break; }
            }
            if (!$enabled) return;
            self::ensure_style();

            wp_register_script(
                'wcrm-single-badges',
                false,
                array( 'jquery' ),
                self::VERSION,
                true
            );

            wp_enqueue_script( 'wcrm-single-badges' );

            wp_add_inline_script(
                'wcrm-single-badges',
                <<<'JS'
jQuery(function ($) {

    $('.wcrm-single-source').each(function () {

        var $source = $(this);
        var $product = $source.closest('.product');

        if (!$product.length) {
            return;
        }

        var $gallery = $product
            .find('.woocommerce-product-gallery')
            .first();

        if (!$gallery.length) {
            return;
        }

        var $badges = $source.children('.wcrm-marks');

        if (!$badges.length) {
            return;
        }

        // Late-rendered badge CSS must survive removal of the fallback container.
        $source.children('style#wcrm-product-marks-inline').appendTo(document.head);

        if ($gallery.children('.wcrm-marks--single').length) {
            $source.remove();
            return;
        }

        $gallery.addClass('wcrm-mark-anchor');

        $badges
            .removeClass('wcrm-marks--inline')
            .addClass('wcrm-marks--overlay wcrm-marks--single')
            .appendTo($gallery);

        $source.remove();
    });
});
JS
            );
        }
    }

    WCRM_Product_Marks::boot();
}
