<?php

// 1. WooCommerce 商品規格表設定：欄位可改名、增減。
function wutm_product_size_chart_default_columns() {
    return array(
        'size'     => array( 'label' => '尺寸' ),
        'shoulder' => array( 'label' => '肩寬' ),
        'chest'    => array( 'label' => '胸圍' ),
        'hem'      => array( 'label' => '下擺' ),
        'length'   => array( 'label' => '衣長' ),
    );
}
add_action( 'add_meta_boxes_product', 'wutm_product_size_chart_add_meta_box' );
add_action( 'add_meta_boxes_product', 'wutm_product_size_chart_remove_legacy_meta_box', PHP_INT_MAX );
function wutm_product_size_chart_remove_legacy_meta_box() {
    remove_meta_box( 'custom_size_chart', 'product', 'normal' );
}
add_action( 'init', function () {
    if ( function_exists( 'save_custom_size_chart_data' ) ) {
        remove_action( 'woocommerce_process_product_meta', 'save_custom_size_chart_data' );
    }
    if ( function_exists( 'add_size_chart_product_tab' ) ) {
        remove_filter( 'woocommerce_product_tabs', 'add_size_chart_product_tab' );
    }
}, PHP_INT_MAX );
function wutm_product_size_chart_add_meta_box() {
    add_meta_box(
        'wutm_product_size_chart_box',
        '商品規格表設定',
        'wutm_product_size_chart_render_meta_box',
        'product',
        'normal',
        'default'
    );
}

function wutm_product_size_chart_render_meta_box( $post ) {
    wp_nonce_field( 'wutm_product_size_chart_save', 'wutm_product_size_chart_nonce' );
    $rows = get_post_meta( $post->ID, '_custom_size_chart', true );
    $columns = get_post_meta( $post->ID, '_custom_size_chart_columns', true );
    if ( ! is_array( $rows ) ) $rows = array();
    if ( ! is_array( $columns ) || empty( $columns ) ) $columns = wutm_product_size_chart_default_columns();
    ?>
    <div class="wutm-size-chart-admin">
        <p class="description">欄位名稱可直接修改，也可新增或移除欄位；每列填寫商品對應的規格資料。</p>
        <div class="wutm-size-chart-table-wrap">
            <table id="wutm-size-chart-table" class="widefat striped">
                <thead><tr>
                    <?php foreach ( $columns as $key => $column ) : $label = is_array( $column ) ? ( $column['label'] ?? '' ) : $column; ?>
                        <th class="wutm-size-chart-column" data-column-key="<?php echo esc_attr( $key ); ?>">
                            <label class="screen-reader-text" for="wutm-size-chart-label-<?php echo esc_attr( $key ); ?>">欄位名稱</label>
                            <input id="wutm-size-chart-label-<?php echo esc_attr( $key ); ?>" type="text" name="size_chart_columns[<?php echo esc_attr( $key ); ?>][label]" value="<?php echo esc_attr( $label ); ?>" placeholder="欄位名稱" required>
                            <button type="button" class="button-link-delete wutm-size-chart-remove-column">移除欄位</button>
                        </th>
                    <?php endforeach; ?>
                    <th class="wutm-size-chart-actions">操作</th>
                </tr></thead>
                <tbody>
                    <?php if ( $rows ) : foreach ( $rows as $row_index => $row ) : if ( ! is_array( $row ) ) continue; ?>
                        <tr>
                            <?php foreach ( $columns as $key => $column ) : ?>
                                <td data-column-key="<?php echo esc_attr( $key ); ?>"><input type="text" name="size_chart_rows[<?php echo esc_attr( $row_index ); ?>][<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $row[ $key ] ?? '' ); ?>"></td>
                            <?php endforeach; ?>
                            <td class="wutm-size-chart-actions"><button type="button" class="button wutm-size-chart-remove-row">刪除</button></td>
                        </tr>
                    <?php endforeach; else : ?>
                        <tr>
                            <?php foreach ( $columns as $key => $column ) : ?><td data-column-key="<?php echo esc_attr( $key ); ?>"><input type="text" name="size_chart_rows[0][<?php echo esc_attr( $key ); ?>]" value=""></td><?php endforeach; ?>
                            <td class="wutm-size-chart-actions"><button type="button" class="button wutm-size-chart-remove-row">刪除</button></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <p class="wutm-size-chart-controls"><button type="button" class="button" id="wutm-size-chart-add-column">新增規格欄位</button> <button type="button" class="button" id="wutm-size-chart-add-row">新增資料列</button></p>
    </div>
    <style>
        .wutm-size-chart-table-wrap{max-width:100%;overflow-x:auto}.wutm-size-chart-admin table{width:100%;min-width:900px;table-layout:fixed;border-collapse:collapse}.wutm-size-chart-admin th,.wutm-size-chart-admin td{padding:10px;vertical-align:top;box-sizing:border-box}.wutm-size-chart-admin th input,.wutm-size-chart-admin td input{display:block;width:100%;max-width:100%;min-width:0;box-sizing:border-box}.wutm-size-chart-admin .wutm-size-chart-remove-column{display:block;margin-top:6px;max-width:100%;white-space:nowrap}.wutm-size-chart-admin .wutm-size-chart-actions{width:88px;min-width:88px}.wutm-size-chart-controls{display:flex;gap:8px;flex-wrap:wrap}
    </style>
    <script>
    jQuery(function($){
        var $table=$('#wutm-size-chart-table'), $head=$table.find('thead tr'), $body=$table.find('tbody');
        var rowIndex=$body.find('tr').length, columnIndex=0;
        function keys(){return $head.find('.wutm-size-chart-column').map(function(){return String($(this).data('column-key'));}).get();}
        function reindexRows(){ $body.find('tr').each(function(index){$(this).find('input').each(function(){var key=$(this).closest('td').data('column-key');$(this).attr('name','size_chart_rows['+index+']['+key+']');});});rowIndex=$body.find('tr').length; }
        $('#wutm-size-chart-add-column').on('click',function(){
            columnIndex++; var key='custom_'+Date.now()+'_'+columnIndex;
            var $th=$('<th>',{'class':'wutm-size-chart-column','data-column-key':key});
            $('<input>',{type:'text',name:'size_chart_columns['+key+'][label]',placeholder:'欄位名稱',required:true}).appendTo($th);
            $('<button>',{type:'button','class':'button-link-delete wutm-size-chart-remove-column',text:'移除欄位'}).appendTo($th);
            $th.insertBefore($head.find('.wutm-size-chart-actions'));
            $body.find('tr').each(function(){ $('<td>',{'data-column-key':key}).append($('<input>',{type:'text',name:'size_chart_rows['+$body.find('tr').index(this)+']['+key+']'})).insertBefore($(this).find('.wutm-size-chart-actions')); });
        });
        $('#wutm-size-chart-add-row').on('click',function(){var $row=$('<tr>');keys().forEach(function(key){$('<td>',{'data-column-key':key}).append($('<input>',{type:'text',name:'size_chart_rows['+rowIndex+']['+key+']'})).appendTo($row);});$('<td>',{'class':'wutm-size-chart-actions'}).append($('<button>',{type:'button','class':'button wutm-size-chart-remove-row',text:'刪除'})).appendTo($row);$body.append($row);rowIndex++;});
        $table.on('click','.wutm-size-chart-remove-column',function(){var key=$(this).closest('th').data('column-key');if(keys().length<=1){window.alert('至少保留一個規格欄位。');return;}$(this).closest('th').remove();$body.find('td[data-column-key="'+key+'"]').remove();});
        $table.on('click','.wutm-size-chart-remove-row',function(){$(this).closest('tr').remove();reindexRows();});
    });
    </script>
    <?php
}

// 2. 儲存可編輯欄位與每項商品的規格資料。
add_action( 'woocommerce_process_product_meta', 'wutm_product_size_chart_save_data' );
function wutm_product_size_chart_save_data( $post_id ) {
    if ( ! isset( $_POST['wutm_product_size_chart_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wutm_product_size_chart_nonce'] ) ), 'wutm_product_size_chart_save' ) ) return;
    if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) return;

    $posted_columns = isset( $_POST['size_chart_columns'] ) && is_array( $_POST['size_chart_columns'] ) ? wp_unslash( $_POST['size_chart_columns'] ) : array();
    $columns = array();
    foreach ( $posted_columns as $key => $column ) {
        $key = sanitize_key( $key );
        $label = is_array( $column ) ? sanitize_text_field( $column['label'] ?? '' ) : '';
        if ( '' !== $key && '' !== $label ) $columns[ $key ] = array( 'label' => $label );
    }
    if ( empty( $columns ) ) $columns = array( 'spec' => array( 'label' => '規格' ) );

    $posted_rows = isset( $_POST['size_chart_rows'] ) && is_array( $_POST['size_chart_rows'] ) ? wp_unslash( $_POST['size_chart_rows'] ) : array();
    $rows = array();
    foreach ( $posted_rows as $posted_row ) {
        if ( ! is_array( $posted_row ) ) continue;
        $row = array();
        $has_value = false;
        foreach ( $columns as $key => $column ) {
            $value = isset( $posted_row[ $key ] ) && is_scalar( $posted_row[ $key ] ) ? sanitize_text_field( $posted_row[ $key ] ) : '';
            $row[ $key ] = $value;
            if ( '' !== trim( $value ) ) $has_value = true;
        }
        if ( $has_value ) $rows[] = $row;
    }

    update_post_meta( $post_id, '_custom_size_chart_columns', $columns );
    if ( $rows ) update_post_meta( $post_id, '_custom_size_chart', $rows );
    else delete_post_meta( $post_id, '_custom_size_chart' );
}

// 3. 前台顯示商品尺寸表 (新增至商品頁籤)
add_filter( 'woocommerce_product_tabs', 'wutm_product_size_chart_add_product_tab' );
function wutm_product_size_chart_add_product_tab( $tabs ) {
    global $post;
    $size_chart = get_post_meta( $post->ID, '_custom_size_chart', true );
    
    // 如果有尺寸表資料，才顯示頁籤
    if ( ! empty( $size_chart ) && is_array( $size_chart ) ) {
        $tabs['size_chart_tab'] = array(
            'title'    => '商品規格',
            'priority' => 50,
            'callback' => 'wutm_product_size_chart_render_tab_content'
        );
    }
    return $tabs;
}

// 渲染前台尺寸表內容
function wutm_product_size_chart_render_tab_content() {
    global $post;
    $size_chart = get_post_meta( $post->ID, '_custom_size_chart', true );
    $columns = get_post_meta( $post->ID, '_custom_size_chart_columns', true );
    if ( ! is_array( $columns ) || empty( $columns ) ) $columns = wutm_product_size_chart_default_columns();

    if ( ! empty( $size_chart ) ) {
        // 輸出專屬 CSS 樣式
        echo '<style>
            .wutm-product-specs { width:100%; min-width:0; margin:0 0 30px; color:#333; box-sizing:border-box; }
            .wutm-product-specs, .wutm-product-specs * { box-sizing:border-box; }
            .wutm-product-specs .wutm-product-specs-title { margin:0 0 16px!important; color:#333; font-size:1.3em!important; font-weight:700; line-height:1.4; }
            .custom-size-chart-container {
                width:100%; min-width:0; max-width:100%; overflow-x:auto; /* 僅表格區域左右滑動 */
                margin-top: 1em;
                margin-bottom: 1em;
            }
            .custom-size-chart-table {
                width: max-content;
                min-width: 100%;
                border-collapse: collapse;
                text-align: center;
                font-size: 1em;
                background-color: #fff;
                border: 1px solid #eaeaea;
            }
            .custom-size-chart-table th {
                min-width: 112px;
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

        echo '<div class="wutm-product-specs">';
        echo '<h2 class="wutm-product-specs-title">' . esc_html__( '商品規格表', 'wu-toolbox-modular' ) . '</h2>';
        
        echo '<div class="custom-size-chart-container">';
        echo '<table class="custom-size-chart-table">';
        echo '<thead><tr>';
        foreach ( $columns as $column ) {
            $label = is_array( $column ) ? ( $column['label'] ?? '' ) : $column;
            echo '<th>' . esc_html( $label ) . '</th>';
        }
        echo '</tr></thead>';
        echo '<tbody>';
        
        foreach ( $size_chart as $row ) {
            echo '<tr>';
            foreach ( $columns as $key => $column ) {
                echo '<td>' . esc_html( $row[ $key ] ?? '' ) . '</td>';
            }
            echo '</tr>';
        }
        
        echo '</tbody></table>';
        echo '</div>';
        // 單位提示
        echo '<span class="custom-size-chart-note">商品規格欄位與資料依商品編輯頁設定顯示。</span>';
        echo '</div>';
    }
}
/* Toolbox overview page. Product-specific size data is managed in each product editor. */
add_action( 'admin_menu', 'wutm_product_size_chart_menu', 30 );
function wutm_product_size_chart_menu() {
    add_submenu_page( 'wu-toolbox-modular', '商品規格表', '商品規格表', 'manage_woocommerce', 'wu-product-size-chart', 'wutm_product_size_chart_settings_page' );
}
function wutm_product_size_chart_settings_page() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) return;
    ?>
    <div class="wrap wutm-module-wrap wutm-product-size-chart-overview">
        <header class="wutm-header"><div><h1>商品規格表</h1><p>為每項商品自訂規格欄位與內容，前台以響應式表格呈現。</p></div><span>v<?php echo esc_html( WUTM_VERSION ); ?></span></header>
        <section class="wutm-panel"><h2>快速開始</h2><ol><li>開啟任一 WooCommerce 商品編輯頁。</li><li>修改欄位名稱，或新增、移除要顯示的規格欄位。</li><li>填寫各列資料並更新商品，前台商品頁籤會依設定顯示規格表。</li></ol><p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'edit.php?post_type=product' ) ); ?>">前往商品列表</a></p></section>
        <section class="wutm-panel"><h2>顯示方式</h2><p>欄位與每列內容皆依商品個別設定；表格在手機上可左右滑動閱讀。</p></section>
    </div>
    <?php
}
