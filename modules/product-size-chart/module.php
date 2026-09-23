<?php

// 1. 在 WooCommerce 商品編輯頁面新增「尺寸表」Meta Box
add_action( 'add_meta_boxes', 'add_custom_size_chart_meta_box' );
function add_custom_size_chart_meta_box() {
    add_meta_box(
        'custom_size_chart',      // Meta Box ID
        '商品尺寸表設定',         // 標題
        'render_custom_size_chart_meta_box', // 回呼函式
        'product',                // 顯示於商品頁
        'normal',                 // 位置
        'default'                 // 優先權
    );
}

// 渲染後台 Meta Box 的 HTML 與 JavaScript
function render_custom_size_chart_meta_box( $post ) {
    wp_nonce_field( 'save_custom_size_chart', 'custom_size_chart_nonce' );
    
    // 取得已儲存的尺寸表資料
    $size_chart = get_post_meta( $post->ID, '_custom_size_chart', true );
    if ( ! is_array( $size_chart ) ) {
        $size_chart = array();
    }
    ?>
    <table id="size-chart-table" style="width: 100%; text-align: left; border-collapse: collapse;">
        <thead>
            <tr>
                <th>尺寸</th>
                <th>肩寬</th>
                <th>胸圍</th>
                <th>下擺</th>
                <th>衣長</th>
                <th>操作</th>
            </tr>
        </thead>
        <tbody>
            <?php if ( ! empty( $size_chart ) ) : ?>
                <?php foreach ( $size_chart as $row ) : ?>
                    <tr>
                        <td><input type="text" name="size_chart_size[]" value="<?php echo esc_attr( $row['size'] ); ?>" /></td>
                        <td><input type="text" name="size_chart_shoulder[]" value="<?php echo esc_attr( $row['shoulder'] ); ?>" /></td>
                        <td><input type="text" name="size_chart_chest[]" value="<?php echo esc_attr( $row['chest'] ); ?>" /></td>
                        <td><input type="text" name="size_chart_hem[]" value="<?php echo esc_attr( $row['hem'] ); ?>" /></td>
                        <td><input type="text" name="size_chart_length[]" value="<?php echo esc_attr( $row['length'] ); ?>" /></td>
                        <td><button type="button" class="button remove-size-row">刪除</button></td>
                    </tr>
                <?php endforeach; ?>
            <?php else : ?>
                <!-- 預設留空一列 -->
                <tr>
                    <td><input type="text" name="size_chart_size[]" value="" placeholder="例如: S" /></td>
                    <td><input type="text" name="size_chart_shoulder[]" value="" /></td>
                    <td><input type="text" name="size_chart_chest[]" value="" /></td>
                    <td><input type="text" name="size_chart_hem[]" value="" /></td>
                    <td><input type="text" name="size_chart_length[]" value="" /></td>
                    <td><button type="button" class="button remove-size-row">刪除</button></td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
    <p>
        <button type="button" class="button button-primary" id="add-size-row">新增尺寸列</button>
    </p>

    <script type="text/javascript">
        jQuery(document).ready(function($) {
            // 新增列
            $('#add-size-row').on('click', function(e) {
                e.preventDefault();
                var newRow = '<tr>' +
                    '<td><input type="text" name="size_chart_size[]" value="" /></td>' +
                    '<td><input type="text" name="size_chart_shoulder[]" value="" /></td>' +
                    '<td><input type="text" name="size_chart_chest[]" value="" /></td>' +
                    '<td><input type="text" name="size_chart_hem[]" value="" /></td>' +
                    '<td><input type="text" name="size_chart_length[]" value="" /></td>' +
                    '<td><button type="button" class="button remove-size-row">刪除</button></td>' +
                    '</tr>';
                $('#size-chart-table tbody').append(newRow);
            });

            // 刪除列
            $(document).on('click', '.remove-size-row', function(e) {
                e.preventDefault();
                $(this).closest('tr').remove();
            });
        });
    </script>
    <style>
        #size-chart-table th, #size-chart-table td { padding: 8px; border-bottom: 1px solid #ddd; }
        #size-chart-table input[type="text"] { width: 100%; max-width: 100px; }
    </style>
    <?php
}

// 2. 儲存尺寸表資料
add_action( 'woocommerce_process_product_meta', 'save_custom_size_chart_data' );
function save_custom_size_chart_data( $post_id ) {
    // 檢查 nonce 確保安全性
    if ( ! isset( $_POST['custom_size_chart_nonce'] ) || ! wp_verify_nonce( $_POST['custom_size_chart_nonce'], 'save_custom_size_chart' ) ) {
        return;
    }

    if ( isset( $_POST['size_chart_size'] ) ) {
        $sizes     = $_POST['size_chart_size'];
        $shoulders = $_POST['size_chart_shoulder'];
        $chests    = $_POST['size_chart_chest'];
        $hems      = $_POST['size_chart_hem'];
        $lengths   = $_POST['size_chart_length'];

        $size_chart_data = array();
        
        // 迴圈處理每一列
        for ( $i = 0; $i < count( $sizes ); $i++ ) {
            // 只要「尺寸」欄位有填寫才儲存
            if ( ! empty( trim( $sizes[$i] ) ) ) {
                $size_chart_data[] = array(
                    'size'     => sanitize_text_field( $sizes[$i] ),
                    'shoulder' => sanitize_text_field( $shoulders[$i] ),
                    'chest'    => sanitize_text_field( $chests[$i] ),
                    'hem'      => sanitize_text_field( $hems[$i] ),
                    'length'   => sanitize_text_field( $lengths[$i] )
                );
            }
        }
        // 更新資料
        update_post_meta( $post_id, '_custom_size_chart', $size_chart_data );
    } else {
        // 如果全部清空則刪除資料
        delete_post_meta( $post_id, '_custom_size_chart' );
    }
}

// 3. 前台顯示商品尺寸表 (新增至商品頁籤)
add_filter( 'woocommerce_product_tabs', 'add_size_chart_product_tab' );
function add_size_chart_product_tab( $tabs ) {
    global $post;
    $size_chart = get_post_meta( $post->ID, '_custom_size_chart', true );
    
    // 如果有尺寸表資料，才顯示頁籤
    if ( ! empty( $size_chart ) && is_array( $size_chart ) ) {
        $tabs['size_chart_tab'] = array(
            'title'    => '尺寸表',
            'priority' => 50,
            'callback' => 'render_size_chart_tab_content'
        );
    }
    return $tabs;
}

// 渲染前台尺寸表內容
function render_size_chart_tab_content() {
    global $post;
    $size_chart = get_post_meta( $post->ID, '_custom_size_chart', true );

    if ( ! empty( $size_chart ) ) {
        // 輸出專屬 CSS 樣式
        echo '<style>
            .custom-size-chart-title {
                font-size: 1.3em !important;
                font-weight: 700;
                line-height: 1.4;
                margin: 0 0 16px !important;
                color: #333;
            }
            .custom-size-chart-container {
                overflow-x: auto; /* 支援手機版橫向滑動 */
                margin-top: 1em;
                margin-bottom: 1em;
            }
            .custom-size-chart-table {
                width: 100%;
                border-collapse: collapse;
                text-align: center;
                font-size: 1em;
                background-color: #fff;
                border: 1px solid #eaeaea;
            }
            .custom-size-chart-table th {
                background-color: #f7f7f7;
                color: #333;
                font-weight: 600;
                padding: 12px 15px;
                border: 1px solid #eaeaea;
                white-space: nowrap;
            }
            .custom-size-chart-table td {
                padding: 12px 15px;
                border: 1px solid #eaeaea;
                color: #555;
            }
            .custom-size-chart-table tbody tr:nth-child(even) {
                background-color: #fafafa; /* 斑馬紋效果 */
            }
            .custom-size-chart-table tbody tr:hover {
                background-color: #f1f1f1; /* 滑鼠懸停效果 */
                transition: background-color 0.2s ease;
            }
            .custom-size-chart-note {
                font-size: 0.85em;
                color: #888;
                margin-top: 10px;
                display: block;
            }
        </style>';

        // 應用新的 CSS 類別來縮小標題
        echo '<h2 class="custom-size-chart-title">商品尺寸表</h2>';
        
        echo '<div class="custom-size-chart-container">';
        echo '<table class="custom-size-chart-table">';
        echo '<thead><tr>';
        echo '<th>尺寸</th>';
        echo '<th>肩寬</th>';
        echo '<th>胸圍</th>';
        echo '<th>下擺</th>';
        echo '<th>衣長</th>';
        echo '</tr></thead>';
        echo '<tbody>';
        
        foreach ( $size_chart as $row ) {
            echo '<tr>';
            echo '<td>' . esc_html( $row['size'] ) . '</td>';
            echo '<td>' . esc_html( $row['shoulder'] ) . '</td>';
            echo '<td>' . esc_html( $row['chest'] ) . '</td>';
            echo '<td>' . esc_html( $row['hem'] ) . '</td>';
            echo '<td>' . esc_html( $row['length'] ) . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
        echo '</div>';
        
        // 單位提示
        echo '<span class="custom-size-chart-note">* 尺寸單位為公分(cm)，手工測量可能存在些許誤差。</span>';
    }
}
/* Toolbox overview page. Product-specific size data is managed in each product editor. */
add_action( 'admin_menu', 'wutm_product_size_chart_menu', 30 );
function wutm_product_size_chart_menu() {
    add_submenu_page( 'wu-toolbox-modular', '商品尺寸表', '商品尺寸表', 'manage_woocommerce', 'wu-product-size-chart', 'wutm_product_size_chart_settings_page' );
}
function wutm_product_size_chart_settings_page() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) return;
    ?>
    <div class="wrap wutm-module-wrap wutm-product-size-chart-overview">
        <header class="wutm-header"><div><h1>商品尺寸表</h1><p>在每個 WooCommerce 商品編輯頁建立專屬尺寸資料，前台會自動顯示「尺寸表」頁籤。</p></div><span>v<?php echo esc_html( WUTM_VERSION ); ?></span></header>
        <section class="wutm-panel"><h2>快速開始</h2><ol><li>開啟任一商品的編輯頁。</li><li>在「商品尺寸表設定」填入尺寸、肩寬、胸圍、下擺及衣長。</li><li>更新商品後，顧客會在商品頁看到美化後的尺寸表頁籤。</li></ol><p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'edit.php?post_type=product' ) ); ?>">前往商品列表</a></p></section>
        <section class="wutm-panel"><h2>顯示規則</h2><p>只要商品至少有一列尺寸資料，就會顯示尺寸表。尺寸單位為公分（cm），表格在手機上可左右滑動閱讀。</p></section>
    </div>
    <?php
}
