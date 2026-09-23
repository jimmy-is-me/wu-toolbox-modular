<?php
/**
 * WooCommerce 商品問答 FAQ
 *
 * 資料欄位：
 * _custom_faq_data
 * _custom_faq_behavior
 *
 * 特點：
 * - 後台維持簡潔表格式設定
 * - 前台 FAQ 不限制寬度，完全跟隨商品頁籤容器
 * - 手機板不額外限制 FAQ 寬度或調整樣式
 * - 不載入外部字型、圖片、第三方 JS
 * - CSS / JS 僅在商品編輯頁與有 FAQ 的單一商品頁載入
 *
 * 貼到 Code Snippets：請刪除最上方 <?php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* =========================================================
 * 1. 後台：商品 FAQ Meta Box
 * ========================================================= */

add_action( 'add_meta_boxes_product', 'wutm_product_faq_add_meta_box' );
add_action( 'add_meta_boxes_product', 'wutm_product_faq_remove_legacy_meta_box', PHP_INT_MAX );
function wutm_product_faq_remove_legacy_meta_box() {
    // Keep the old Code Snippets copy from appearing as a second FAQ panel.
    remove_meta_box( 'custom_product_faq', 'product', 'normal' );
}
add_action( 'admin_init', function () {
    if ( function_exists( 'custom_faq_save_data' ) ) {
        remove_action( 'save_post_product', 'custom_faq_save_data' );
    }
}, PHP_INT_MAX );
add_action( 'init', function () {
    if ( function_exists( 'custom_faq_add_product_tab' ) ) {
        remove_filter( 'woocommerce_product_tabs', 'custom_faq_add_product_tab' );
    }
    if ( function_exists( 'custom_faq_front_assets' ) ) {
        remove_action( 'wp_enqueue_scripts', 'custom_faq_front_assets' );
    }
}, PHP_INT_MAX );

function wutm_product_faq_add_meta_box() {
    add_meta_box(
        'wutm_product_faq_box',
        '商品問答（FAQ）設定',
        'wutm_product_faq_render_meta_box',
        'product',
        'normal',
        'default'
    );
}

/**
 * 後台 FAQ 表格的一列。
 */
function wutm_product_faq_render_admin_row( $question = '', $answer = '', $editor_id = '' ) {
    if ( '' === $editor_id ) {
        $editor_id = wp_unique_id( 'wutm-product-faq-answer-' );
    }
    ?>
    <tr>
        <td>
            <input
                type="text"
                name="faq_question[]"
                value="<?php echo esc_attr( $question ); ?>"
                placeholder="例如：有現貨嗎？"
            >
        </td>

        <td>
            <div class="wutm-faq-editor" data-editor-id="<?php echo esc_attr( $editor_id ); ?>">
                <div class="wutm-faq-toolbar" role="toolbar" aria-label="答案格式">
                    <button type="button" class="button wutm-faq-format" data-command="bold" aria-label="粗體"><strong>B</strong></button>
                    <label>文字顏色 <input type="color" class="wutm-faq-color" value="#333333" aria-label="文字顏色"></label>
                    <label>字級 <select class="wutm-faq-size" aria-label="字級"><option value="3">一般</option><option value="2">較小</option><option value="4">較大</option><option value="5">大</option></select></label>
                    <button type="button" class="button wutm-faq-format" data-command="insertParagraph">換行</button>
                </div>
                <div id="<?php echo esc_attr( $editor_id ); ?>" class="wutm-faq-editable" contenteditable="true" role="textbox" aria-multiline="true" data-placeholder="例如：目前皆有現貨，下單後依配送時程出貨。"><?php echo wp_kses_post( $answer ); ?></div>
                <textarea class="wutm-faq-answer-value" name="faq_answer[]" hidden><?php echo esc_textarea( $answer ); ?></textarea>
            </div>
        </td>

        <td class="wutm-product-faq-admin-action">
            <button
                type="button"
                class="button wutm-product-faq-remove-row"
            >
                刪除
            </button>
        </td>
    </tr>
    <?php
}

/**
 * 輸出商品編輯頁 Meta Box。
 */
function wutm_product_faq_render_meta_box( $post ) {
    wp_nonce_field(
        'wutm_product_faq_save',
        'wutm_product_faq_nonce'
    );

    $faq_data = get_post_meta(
        $post->ID,
        '_custom_faq_data',
        true
    );

    $faq_behavior = get_post_meta(
        $post->ID,
        '_custom_faq_behavior',
        true
    );

    if ( ! is_array( $faq_data ) ) {
        $faq_data = array();
    }

    if (
        ! in_array(
            $faq_behavior,
            array( 'accordion', 'toggle' ),
            true
        )
    ) {
        $faq_behavior = 'accordion';
    }
    ?>

    <div class="wutm-product-faq-admin">
        <p class="wutm-product-faq-admin-setting">
            <label for="wutm-product-faq-behavior">
                <strong>問答展開模式：</strong>
            </label>

            <select
                name="faq_behavior"
                id="wutm-product-faq-behavior"
            >
                <option
                    value="accordion"
                    <?php selected( $faq_behavior, 'accordion' ); ?>
                >
                    一次只能打開一個（手風琴模式）
                </option>

                <option
                    value="toggle"
                    <?php selected( $faq_behavior, 'toggle' ); ?>
                >
                    每個問題可獨立打開
                </option>
            </select>
        </p>

        <div class="wutm-product-faq-table-wrap">
            <table
                id="wutm-product-faq-table"
                class="wutm-product-faq-admin-table"
            >
                <thead>
                    <tr>
                        <th scope="col">問題（Question）</th>
                        <th scope="col">答案（Answer）</th>
                        <th scope="col">操作</th>
                    </tr>
                </thead>

                <tbody>
                    <?php
                    $has_row = false;

                    foreach ( $faq_data as $row ) {
                        if ( ! is_array( $row ) ) {
                            continue;
                        }

                        $question = isset( $row['question'] )
                            ? $row['question']
                            : '';

                        $answer = isset( $row['answer'] )
                            ? $row['answer']
                            : '';

                        wutm_product_faq_render_admin_row(
                            $question,
                            $answer
                        );

                        $has_row = true;
                    }

                    if ( ! $has_row ) {
                        wutm_product_faq_render_admin_row();
                    }
                    ?>
                </tbody>
            </table>
        </div>

        <p class="wutm-product-faq-admin-footer">
            <button
                type="button"
                class="button button-primary"
                id="wutm-product-faq-add-row"
            >
                ＋ 新增一則問答
            </button>

            <span>只有填寫問題的項目會顯示於前台。</span>
        </p>

        <template id="wutm-product-faq-row-template">
            <?php wutm_product_faq_render_admin_row(); ?>
        </template>
    </div>

    <?php
}

/* =========================================================
 * 2. 後台：儲存 FAQ 資料
 * ========================================================= */

add_action( 'save_post_product', 'wutm_product_faq_save_data' );

function wutm_product_faq_save_data( $post_id ) {
    /*
     * 非商品編輯表單送出的儲存動作，不修改 FAQ：
     * 可避免快速編輯、排程或其他程式更新商品時誤清空資料。
     */
    if (
        ! isset( $_POST['wutm_product_faq_nonce'] ) ||
        ! is_scalar( $_POST['wutm_product_faq_nonce'] )
    ) {
        return;
    }

    $nonce = sanitize_text_field(
        wp_unslash( $_POST['wutm_product_faq_nonce'] )
    );

    if (
        ! wp_verify_nonce(
            $nonce,
            'wutm_product_faq_save'
        )
    ) {
        return;
    }

    if (
        ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ||
        wp_is_post_autosave( $post_id ) ||
        wp_is_post_revision( $post_id )
    ) {
        return;
    }

    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }

    /* 儲存 FAQ 展開模式 */
    $faq_behavior = 'accordion';

    if (
        isset( $_POST['faq_behavior'] ) &&
        is_scalar( $_POST['faq_behavior'] )
    ) {
        $faq_behavior = sanitize_key(
            wp_unslash( $_POST['faq_behavior'] )
        );
    }

    if (
        ! in_array(
            $faq_behavior,
            array( 'accordion', 'toggle' ),
            true
        )
    ) {
        $faq_behavior = 'accordion';
    }

    $old_behavior = get_post_meta(
        $post_id,
        '_custom_faq_behavior',
        true
    );

    if ( $old_behavior !== $faq_behavior ) {
        update_post_meta(
            $post_id,
            '_custom_faq_behavior',
            $faq_behavior
        );
    }

    /* 取得、清理與儲存 FAQ 陣列 */
    $questions = (
        isset( $_POST['faq_question'] ) &&
        is_array( $_POST['faq_question'] )
    )
        ? wp_unslash( $_POST['faq_question'] )
        : array();

    $answers = (
        isset( $_POST['faq_answer'] ) &&
        is_array( $_POST['faq_answer'] )
    )
        ? wp_unslash( $_POST['faq_answer'] )
        : array();

    $faq_data = array();

    foreach ( $questions as $index => $question ) {
        if ( ! is_scalar( $question ) ) {
            continue;
        }

        $question = sanitize_text_field( $question );

        if ( '' === trim( $question ) ) {
            continue;
        }

        $answer = '';

        if (
            isset( $answers[ $index ] ) &&
            is_scalar( $answers[ $index ] )
        ) {
            $answer = wp_kses_post(
                $answers[ $index ]
            );
        }

        $faq_data[] = array(
            'question' => $question,
            'answer'   => $answer,
        );
    }

    $old_faq_data = get_post_meta(
        $post_id,
        '_custom_faq_data',
        true
    );

    if ( empty( $faq_data ) ) {
        if (
            metadata_exists(
                'post',
                $post_id,
                '_custom_faq_data'
            )
        ) {
            delete_post_meta(
                $post_id,
                '_custom_faq_data'
            );
        }

        return;
    }

    /*
     * 新舊資料不同才更新資料庫，
     * 避免每次按「更新商品」都寫入相同 Meta。
     */
    if ( $old_faq_data !== $faq_data ) {
        update_post_meta(
            $post_id,
            '_custom_faq_data',
            $faq_data
        );
    }
}

/* =========================================================
 * 3. 前台：新增「產品問答」商品頁籤
 * ========================================================= */

add_filter(
    'woocommerce_product_tabs',
    'wutm_product_faq_add_product_tab'
);

function wutm_product_faq_add_product_tab( $tabs ) {
    global $product;

    if (
        ! $product ||
        ! is_a( $product, 'WC_Product' )
    ) {
        return $tabs;
    }

    $faq_data = get_post_meta(
        $product->get_id(),
        '_custom_faq_data',
        true
    );

    if (
        ! is_array( $faq_data ) ||
        empty( $faq_data )
    ) {
        return $tabs;
    }

    foreach ( $faq_data as $row ) {
        if (
            is_array( $row ) &&
            isset( $row['question'] ) &&
            is_scalar( $row['question'] ) &&
            '' !== trim( (string) $row['question'] )
        ) {
            $tabs['faq_tab'] = array(
                'title'    => '產品問答',
                'priority' => 60,
                'callback' => 'wutm_product_faq_render_tab_content',
            );

            break;
        }
    }

    return $tabs;
}

/**
 * 輸出前台 FAQ 頁籤內容。
 */
function wutm_product_faq_render_tab_content() {
    global $product;

    if (
        ! $product ||
        ! is_a( $product, 'WC_Product' )
    ) {
        return;
    }

    $product_id = $product->get_id();

    $faq_data = get_post_meta(
        $product_id,
        '_custom_faq_data',
        true
    );

    if (
        ! is_array( $faq_data ) ||
        empty( $faq_data )
    ) {
        return;
    }

    $faq_behavior = get_post_meta(
        $product_id,
        '_custom_faq_behavior',
        true
    );

    if (
        ! in_array(
            $faq_behavior,
            array( 'accordion', 'toggle' ),
            true
        )
    ) {
        $faq_behavior = 'accordion';
    }

    $items = array();

    foreach ( $faq_data as $row ) {
        if (
            ! is_array( $row ) ||
            ! isset( $row['question'] ) ||
            ! is_scalar( $row['question'] )
        ) {
            continue;
        }

        $question = trim(
            (string) $row['question']
        );

        if ( '' === $question ) {
            continue;
        }

        $answer = '';

        if (
            isset( $row['answer'] ) &&
            is_scalar( $row['answer'] )
        ) {
            $answer = (string) $row['answer'];
        }

        $items[] = array(
            'question' => $question,
            'answer'   => $answer,
        );
    }

    if ( empty( $items ) ) {
        return;
    }
    ?>

    <div class="wutm-product-faq-front">
        <h2 class="wutm-product-faq-title">產品問答</h2>

        <div
            class="wutm-product-faq-container"
            data-behavior="<?php echo esc_attr( $faq_behavior ); ?>"
        >
            <?php foreach ( $items as $index => $item ) : ?>
                <?php
                $answer_id =
                    'wutm-product-faq-answer-' .
                    $product_id .
                    '-' .
                    ( $index + 1 );
                ?>

                <div class="wutm-product-faq-item">
                    <h3 class="wutm-product-faq-heading">
                        <button
                            type="button"
                            class="wutm-product-faq-question"
                            aria-expanded="false"
                            aria-controls="<?php echo esc_attr( $answer_id ); ?>"
                        >
                            <span class="wutm-product-faq-question-text">
                                <?php echo esc_html( $item['question'] ); ?>
                            </span>

                            <span
                                class="wutm-product-faq-icon"
                                aria-hidden="true"
                            ></span>
                        </button>
                    </h3>

                    <div
                        id="<?php echo esc_attr( $answer_id ); ?>"
                        class="wutm-product-faq-answer"
                        hidden
                    >
                        <?php
                        echo wp_kses_post( wpautop( $item['answer'] ) );
                        ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <?php
}

/* =========================================================
 * 4. 後台：只在商品編輯頁加入 FAQ CSS / JS
 * ========================================================= */

add_action(
    'admin_enqueue_scripts',
    'wutm_product_faq_admin_assets'
);

function wutm_product_faq_admin_assets( $hook ) {
    if (
        ! in_array(
            $hook,
            array( 'post.php', 'post-new.php' ),
            true
        )
    ) {
        return;
    }

    $screen = get_current_screen();

    if (
        ! $screen ||
        'product' !== $screen->post_type
    ) {
        return;
    }

    wp_register_style(
        'wutm-product-faq-admin-style',
        false,
        array(),
        '5.0.0'
    );

    wp_enqueue_style(
        'wutm-product-faq-admin-style'
    );

    $css = <<<'CSS'
#wutm_product_faq_box .inside {
    margin: 0;
    padding: 0;
}

#wutm_product_faq_box .wutm-product-faq-admin,
#wutm_product_faq_box .wutm-product-faq-admin * {
    box-sizing: border-box;
}

.wutm-product-faq-admin {
    padding: 16px;
}

.wutm-product-faq-admin-setting {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
    margin: 0 0 14px;
}

.wutm-product-faq-admin-setting label {
    font-size: 13px;
}

.wutm-product-faq-admin-setting select {
    max-width: 100%;
}

.wutm-product-faq-table-wrap {
    width: 100%;
    overflow-x: auto;
}

.wutm-product-faq-admin-table {
    width: 100%;
    border-collapse: collapse;
    text-align: left;
}

.wutm-product-faq-admin-table th,
.wutm-product-faq-admin-table td {
    padding: 10px 8px;
    border-bottom: 1px solid #e5e7eb;
    vertical-align: top;
}

.wutm-product-faq-admin-table th {
    color: #374151;
    font-size: 13px;
    font-weight: 600;
}

.wutm-product-faq-admin-table th:first-child {
    width: 35%;
}

.wutm-product-faq-admin-table th:nth-child(2) {
    width: 55%;
}

.wutm-product-faq-admin-table th:last-child {
    width: 10%;
    min-width: 75px;
}

.wutm-product-faq-admin-table input,
.wutm-product-faq-admin-table textarea {
    display: block;
    width: 100%;
    max-width: none;
    margin: 0;
    font-size: 13px;
}

.wutm-faq-toolbar {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 6px;
    padding: 6px;
    border: 1px solid #c3c4c7;
    border-bottom: 0;
    background: #f6f7f7;
}

.wutm-faq-toolbar label {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    color: #50575e;
    font-size: 12px;
}

.wutm-faq-toolbar input[type="color"] {
    width: 30px;
    height: 28px;
    padding: 2px;
}

.wutm-faq-toolbar select {
    min-height: 28px;
    width: auto;
}

.wutm-faq-editable {
    min-height: 100px;
    padding: 10px;
    border: 1px solid #8c8f94;
    background: #fff;
    line-height: 1.6;
    overflow-wrap: anywhere;
}

.wutm-faq-editable:focus {
    border-color: #2271b1;
    box-shadow: 0 0 0 1px #2271b1;
    outline: 2px solid transparent;
}

.wutm-faq-editable:empty::before {
    color: #8c8f94;
    content: attr(data-placeholder);
}

.wutm-faq-answer-value[hidden] {
    display: none !important;
}

.wutm-product-faq-admin-table textarea {
    min-height: 78px;
    resize: vertical;
}

.wutm-product-faq-admin-action {
    white-space: nowrap;
}

.wutm-product-faq-admin-footer {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
    margin: 16px 0 0;
}

.wutm-product-faq-admin-footer span {
    color: #646970;
    font-size: 12px;
}
CSS;

    wp_add_inline_style(
        'wutm-product-faq-admin-style',
        $css
    );

    add_action(
        'admin_print_footer_scripts',
        'wutm_product_faq_admin_script',
        99
    );
}

function wutm_product_faq_admin_script() {
    ?>
    <script>
    (function () {
        'use strict';

        var box = document.getElementById('wutm_product_faq_box');

        if (!box) {
            return;
        }

        var tableBody = box.querySelector(
            '#wutm-product-faq-table tbody'
        );

        var addButton = box.querySelector(
            '#wutm-product-faq-add-row'
        );

        var template = box.querySelector(
            '#wutm-product-faq-row-template'
        );

        if (!tableBody || !addButton || !template) {
            return;
        }

        var editorCounter = 0;
        function saveSelection(editor) {
            var selection = window.getSelection();
            if (!selection || !selection.rangeCount) return;
            var range = selection.getRangeAt(0);
            if (editor.contains(range.commonAncestorContainer)) {
                editor.closest('.wutm-faq-editor').savedRange = range.cloneRange();
            }
        }
        function restoreSelection(editor) {
            var wrapper = editor.closest('.wutm-faq-editor');
            if (!wrapper || !wrapper.savedRange) return;
            editor.focus();
            var selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(wrapper.savedRange);
        }
        function syncEditor(editor) {
            var wrapper = editor.closest('.wutm-faq-editor');
            var value = wrapper && wrapper.querySelector('.wutm-faq-answer-value');
            if (value) value.value = editor.innerHTML;
        }

        addButton.addEventListener('click', function () {
            var rowFragment = template.content.cloneNode(true);
            rowFragment.querySelectorAll('.wutm-faq-editable').forEach(function (editor) {
                editorCounter += 1;
                editor.id = 'wutm-product-faq-answer-new-' + Date.now() + '-' + editorCounter;
                editor.closest('.wutm-faq-editor').dataset.editorId = editor.id;
            });
            tableBody.appendChild(rowFragment);

            var rows = tableBody.querySelectorAll('tr');
            var lastRow = rows[rows.length - 1];

            if (lastRow) {
                var input = lastRow.querySelector(
                    'input[name="faq_question[]"]'
                );

                if (input) {
                    input.focus();
                }
            }
        });

        tableBody.addEventListener('keyup', function (event) {
            if (event.target.matches('.wutm-faq-editable')) saveSelection(event.target);
        });
        tableBody.addEventListener('mouseup', function (event) {
            if (event.target.matches('.wutm-faq-editable')) saveSelection(event.target);
        });
        tableBody.addEventListener('mousedown', function (event) {
            if (event.target.closest('.wutm-faq-format')) event.preventDefault();
        });

        tableBody.addEventListener('click', function (event) {
            var formatButton = event.target.closest('.wutm-faq-format');
            if (formatButton && tableBody.contains(formatButton)) {
                event.preventDefault();
                var editor = formatButton.closest('.wutm-faq-editor').querySelector('.wutm-faq-editable');
                restoreSelection(editor);
                document.execCommand(formatButton.dataset.command, false, null);
                syncEditor(editor);
                return;
            }

            var button = event.target.closest(
                '.wutm-product-faq-remove-row'
            );

            if (!button || !tableBody.contains(button)) {
                return;
            }

            var row = button.closest('tr');

            if (row) {
                row.remove();
            }
        });

        tableBody.addEventListener('input', function (event) {
            if (event.target.matches('.wutm-faq-editable')) syncEditor(event.target);
        });
        tableBody.addEventListener('change', function (event) {
            var wrapper = event.target.closest('.wutm-faq-editor');
            if (!wrapper) return;
            var editor = wrapper.querySelector('.wutm-faq-editable');
            restoreSelection(editor);
            if (event.target.matches('.wutm-faq-color')) {
                document.execCommand('foreColor', false, event.target.value);
            } else if (event.target.matches('.wutm-faq-size')) {
                document.execCommand('fontSize', false, event.target.value);
            }
            syncEditor(editor);
        });
        var productForm = box.closest('form');
        if (productForm) productForm.addEventListener('submit', function () {
            box.querySelectorAll('.wutm-faq-editable').forEach(syncEditor);
        });
    })();
    </script>
    <?php
}

/* =========================================================
 * 5. 前台：只在有 FAQ 的單一商品頁加入 CSS / JS
 * ========================================================= */

add_action(
    'wp_enqueue_scripts',
    'wutm_product_faq_front_assets'
);

function wutm_product_faq_front_assets() {
    if (
        ! function_exists( 'is_product' ) ||
        ! is_product()
    ) {
        return;
    }

    $product_id = get_queried_object_id();

    if ( ! $product_id ) {
        return;
    }

    $faq_data = get_post_meta(
        $product_id,
        '_custom_faq_data',
        true
    );

    if (
        ! is_array( $faq_data ) ||
        empty( $faq_data )
    ) {
        return;
    }

    $has_question = false;

    foreach ( $faq_data as $row ) {
        if (
            is_array( $row ) &&
            isset( $row['question'] ) &&
            is_scalar( $row['question'] ) &&
            '' !== trim( (string) $row['question'] )
        ) {
            $has_question = true;
            break;
        }
    }

    if ( ! $has_question ) {
        return;
    }

    wp_register_style(
        'wutm-product-faq-front-style',
        false,
        array(),
        '5.0.0'
    );

    wp_enqueue_style(
        'wutm-product-faq-front-style'
    );

    /*
     * 注意：
     * - 沒有 max-width
     * - 沒有手機板 media query
     * - FAQ 寬度直接跟隨 WooCommerce 商品頁籤內容容器
     */
    $css = <<<'CSS'
.wutm-product-faq-front {
    width: 100%;
    margin: 0 0 30px;
    color: #333;
}

.wutm-product-faq-front,
.wutm-product-faq-front * {
    box-sizing: border-box;
}

.wutm-product-faq-title {
    margin: 0 0 16px !important;
    color: #333;
    font-size: 1.3em !important;
    font-weight: 700;
    line-height: 1.4;
}

.wutm-product-faq-container {
    width: 100%;
    border-top: 1px solid #eaeaea;
}

.wutm-product-faq-item {
    border-bottom: 1px solid #eaeaea;
}

.wutm-product-faq-heading {
    margin: 0 !important;
    padding: 0 !important;
    font-size: inherit !important;
    line-height: normal !important;
}

.wutm-product-faq-question {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
    width: 100%;
    min-height: 58px;
    margin: 0;
    padding: 14px 10px;
    border: 0;
    border-radius: 0;
    background: #fff;
    box-shadow: none;
    color: #333;
    font: inherit;
    text-align: left;
    cursor: pointer;
    appearance: none;
    -webkit-appearance: none;
}

.wutm-product-faq-question:hover,
.wutm-product-faq-question:focus,
.wutm-product-faq-question:active {
    border: 0;
    background: #fafafa;
    box-shadow: none;
    color: #333;
}

.wutm-product-faq-question:focus-visible {
    outline: 2px solid #8f3030;
    outline-offset: -2px;
}

.wutm-product-faq-question-text {
    min-width: 0;
    font-size: 1.05em;
    font-weight: 600;
    line-height: 1.5;
    overflow-wrap: anywhere;
}

.wutm-product-faq-icon {
    position: relative;
    display: block;
    flex: 0 0 22px;
    width: 22px;
    height: 22px;
    color: #888;
}

.wutm-product-faq-icon::before,
.wutm-product-faq-icon::after {
    position: absolute;
    top: 10px;
    left: 4px;
    width: 14px;
    height: 2px;
    border-radius: 2px;
    background: currentColor;
    content: "";
}

.wutm-product-faq-icon::after {
    transform: rotate(90deg);
    transition: transform .2s ease;
}

.wutm-product-faq-item.active .wutm-product-faq-icon::after {
    transform: rotate(0);
}

.wutm-product-faq-answer[hidden] {
    display: none !important;
}

.wutm-product-faq-answer {
    padding: 0 42px 17px 10px;
    color: #555;
    font-size: 1em;
    line-height: 1.7;
    overflow-wrap: anywhere;
}

@media (prefers-reduced-motion: reduce) {
    .wutm-product-faq-icon::after {
        transition: none;
    }
}
CSS;

    wp_add_inline_style(
        'wutm-product-faq-front-style',
        $css
    );

    add_action(
        'wp_footer',
        'wutm_product_faq_front_script',
        99
    );
}

function wutm_product_faq_front_script() {
    ?>
    <script>
    (function () {
        'use strict';

        var containers = document.querySelectorAll(
            '.wutm-product-faq-container'
        );

        if (!containers.length) {
            return;
        }

        function setOpen(item, isOpen) {
            var button = item.querySelector(
                '.wutm-product-faq-question'
            );

            var answer = item.querySelector(
                '.wutm-product-faq-answer'
            );

            if (!button || !answer) {
                return;
            }

            item.classList.toggle('active', isOpen);

            button.setAttribute(
                'aria-expanded',
                isOpen ? 'true' : 'false'
            );

            answer.hidden = !isOpen;
        }

        containers.forEach(function (container) {
            container.addEventListener('click', function (event) {
                var button = event.target.closest(
                    '.wutm-product-faq-question'
                );

                if (
                    !button ||
                    !container.contains(button)
                ) {
                    return;
                }

                var item = button.closest(
                    '.wutm-product-faq-item'
                );

                if (!item) {
                    return;
                }

                var shouldOpen = !item.classList.contains(
                    'active'
                );

                if (
                    shouldOpen &&
                    container.getAttribute(
                        'data-behavior'
                    ) === 'accordion'
                ) {
                    container
                        .querySelectorAll(
                            '.wutm-product-faq-item.active'
                        )
                        .forEach(function (otherItem) {
                            setOpen(
                                otherItem,
                                false
                            );
                        });
                }

                setOpen(
                    item,
                    shouldOpen
                );
            });
        });
    })();
    </script>
    <?php
}

/* Unified Toolbox module overview page. FAQ details are edited per product. */
add_action( 'admin_menu', 'wutm_product_faq_menu', 30 );
function wutm_product_faq_menu() {
    add_submenu_page( 'wu-toolbox-modular', '商品問答 FAQ', '商品問答 FAQ', 'manage_woocommerce', 'wu-product-faq', 'wutm_product_faq_settings_page' );
}
function wutm_product_faq_settings_page() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) return;
    ?>
    <div class="wrap wutm-module-wrap wutm-product-faq-overview">
        <header class="wutm-header"><div><h1>商品問答 FAQ</h1><p>在 WooCommerce 商品編輯頁管理問答，並於商品頁籤顯示。</p></div><span>v<?php echo esc_html( WUTM_VERSION ); ?></span></header>
        <section class="wutm-panel"><h2>快速開始</h2><ol><li>開啟 WooCommerce 商品編輯頁。</li><li>在「商品問答（FAQ）設定」新增問題與答案。</li><li>選擇一次展開一題或每題獨立展開，然後更新商品。</li></ol><p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'edit.php?post_type=product' ) ); ?>">前往商品列表</a></p></section>
        <section class="wutm-panel"><h2>答案格式</h2><p>答案編輯器支援粗體、文字顏色、字級與換行。只有填寫問題的項目會顯示在前台。</p></section>
    </div>
    <?php
}


