<?php
defined( 'ABSPATH' ) || exit;

// Preserve settings when the reference snippet was previously installed.
foreach ( array(
	'wutm_wc_onepage_checkout'           => 'mysite_enable_onepage_checkout',
	'wutm_wc_attr_enter_add'              => 'mysite_enable_attr_enter_add',
	'wutm_wc_variation_tags'              => 'mysite_enable_variation_tags',
	'wutm_wc_virtual_order_autocomplete'  => 'mysite_enable_virtual_order_autocomplete',
) as $wutm_wc_new_option => $wutm_wc_legacy_option ) {
	if ( null === get_option( $wutm_wc_new_option, null ) ) {
		$wutm_wc_legacy_value = get_option( $wutm_wc_legacy_option, null );
		if ( null !== $wutm_wc_legacy_value ) {
			update_option( $wutm_wc_new_option, 'yes' === $wutm_wc_legacy_value || (bool) $wutm_wc_legacy_value, false );
		}
	}
}
unset( $wutm_wc_new_option, $wutm_wc_legacy_option, $wutm_wc_legacy_value );

/* =========================================================
 * 一、一頁式結帳 (One Page Checkout)
 * ========================================================= */
function wutm_wc_custom_is_onepage_checkout_enabled() {
	return (bool) get_option( 'wutm_wc_onepage_checkout', false );
}

add_action( 'template_redirect', 'wutm_wc_custom_redirect_cart_to_checkout' );
function wutm_wc_custom_redirect_cart_to_checkout() {
	if ( ! wutm_wc_custom_is_onepage_checkout_enabled() ) {
		return;
	}
	if ( ! function_exists( 'is_cart' ) || ! is_cart() ) {
		return;
	}
	if ( WC()->cart && WC()->cart->is_empty() ) {
		return;
	}
	wp_safe_redirect( wc_get_checkout_url() );
	exit;
}

// 掛載在結帳表單「之前」，確保順序為：購物車 -> 資訊 -> 明細...
add_action( 'woocommerce_before_checkout_form', 'wutm_wc_custom_render_cart_on_checkout', 5 );
function wutm_wc_custom_render_cart_on_checkout() {
	if ( ! wutm_wc_custom_is_onepage_checkout_enabled() ) {
		return;
	}
	if ( is_admin() || ! WC()->cart || WC()->cart->is_empty() ) {
		return;
	}
	?>
	<div class="wutm-wc-custom-onepage-cart-wrap">
        <!-- Form 表單包覆，確保可以送出更新 -->
        <form class="wutm-wc-custom-cart-form" action="<?php echo esc_url( wc_get_checkout_url() ); ?>" method="post">
            <?php wp_nonce_field( 'woocommerce-cart', 'woocommerce-cart-nonce' ); ?>
            <table class="shop_table wutm-wc-custom-onepage-cart-table" cellspacing="0">
                <thead>
                    <tr>
                        <th class="product-name"><?php esc_html_e( '商品', 'woocommerce' ); ?></th>
                        <th class="product-quantity"><?php esc_html_e( '數量', 'woocommerce' ); ?></th>
                        <th class="product-subtotal"><?php esc_html_e( '小計', 'woocommerce' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    do_action( 'woocommerce_before_cart_contents' );
                    foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
                        $_product = apply_filters( 'woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key );
                        if ( ! $_product || ! $_product->exists() || $cart_item['quantity'] <= 0 ) {
                            continue;
                        }
                        if ( ! apply_filters( 'woocommerce_cart_item_visible', true, $cart_item, $cart_item_key ) ) {
                            continue;
                        }
                        $product_permalink = apply_filters(
                            'woocommerce_cart_item_permalink',
                            $_product->is_visible() ? $_product->get_permalink( $cart_item ) : '',
                            $cart_item,
                            $cart_item_key
                        );
                        ?>
                        <tr class="wutm-wc-custom-onepage-cart-item">
                            <td class="product-name">
                                <?php
                                echo wp_kses_post( $_product->get_image( 'thumbnail', array( 'class' => 'wutm-wc-custom-cart-thumb' ) ) );
                                if ( $product_permalink ) {
                                    printf( '<a href="%s">%s</a>', esc_url( $product_permalink ), wp_kses_post( $_product->get_name() ) );
                                } else {
                                    echo wp_kses_post( $_product->get_name() );
                                }
                                echo wc_get_formatted_cart_item_data( $cart_item );
                                ?>
                            </td>
                            <td class="product-quantity">
                                <?php
                                if ( $_product->is_sold_individually() ) {
                                    $product_quantity = sprintf( '1 <input type="hidden" name="cart[%s][qty]" value="1" />', $cart_item_key );
                                } else {
                                    $product_quantity = woocommerce_quantity_input(
                                        array(
                                            'input_name'  => "cart[{$cart_item_key}][qty]",
                                            'input_value' => $cart_item['quantity'],
                                            'max_value'   => $_product->get_max_purchase_quantity(),
                                            'min_value'   => '0',
                                        ),
                                        $_product,
                                        false
                                    );
                                }
                                echo apply_filters( 'woocommerce_cart_item_quantity', $product_quantity, $cart_item_key, $cart_item );
                                ?>
                            </td>
                            <td class="product-subtotal">
                                <?php echo wp_kses_post( apply_filters( 'woocommerce_cart_item_subtotal', WC()->cart->get_product_subtotal( $_product, $cart_item['quantity'] ), $cart_item, $cart_item_key ) ); ?>
                            </td>
                        </tr>
                        <?php
                    }
                    do_action( 'woocommerce_cart_contents' );
                    ?>
                </tbody>
            </table>
            <div class="wutm-wc-custom-onepage-cart-actions">
                <button type="submit" class="button wutm-wc-custom-update-cart" name="update_cart" value="<?php esc_attr_e( '更新購物車', 'woocommerce' ); ?>">
                    <?php esc_html_e( '更新購物車', 'woocommerce' ); ?>
                </button>
            </div>
        </form>
		<?php do_action( 'woocommerce_after_cart_contents' ); ?>
	</div>
	<script>
	jQuery( function ( $ ) {
        // 點擊更新時，加上遮罩
		$( document ).on( 'click', '.wutm-wc-custom-update-cart', function () {
			$( '.wutm-wc-custom-cart-form' ).addClass( 'processing' ).block( {
				message: null,
				overlayCSS: { background: '#fff', opacity: 0.6 }
			} );
		} );
        // 數量變更時，自動觸發「更新購物車」以實現良好體驗
        var cart_timer;
		$( document ).on( 'change', '.wutm-wc-custom-onepage-cart-table input.qty', function () {
            clearTimeout( cart_timer );
            cart_timer = setTimeout(function() {
                $( '.wutm-wc-custom-update-cart' ).trigger('click');
            }, 500);
		} );
	} );
	</script>
	<?php
}

add_action( 'template_redirect', 'wutm_wc_custom_handle_onepage_cart_actions', 5 );
function wutm_wc_custom_handle_onepage_cart_actions() {
	if ( ! wutm_wc_custom_is_onepage_checkout_enabled() ) {
		return;
	}
	if ( is_admin() || ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
		return;
	}
	if ( isset( $_POST['update_cart'] ) && isset( $_POST['cart'] ) && is_array( $_POST['cart'] ) ) {
		if ( empty( $_POST['woocommerce-cart-nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['woocommerce-cart-nonce'] ) ), 'woocommerce-cart' ) ) {
			return;
		}
		$cart_updated = false;
		foreach ( wc_clean( wp_unslash( $_POST['cart'] ) ) as $cart_item_key => $values ) {
			$cart_item = WC()->cart->get_cart_item( $cart_item_key );
			$quantity  = isset( $values['qty'] ) ? absint( $values['qty'] ) : 0;
			if ( ! $cart_item || $quantity === $cart_item['quantity'] ) {
				continue;
			}
			WC()->cart->set_quantity( $cart_item_key, $quantity, false );
			$cart_updated = true;
		}
		if ( $cart_updated ) {
			WC()->cart->calculate_totals();
			wc_add_notice( __( '購物車已更新。', 'woocommerce' ) );
		}
	}
}

add_action( 'wp_head', 'wutm_wc_custom_onepage_checkout_style' );
function wutm_wc_custom_onepage_checkout_style() {
	if ( ! wutm_wc_custom_is_onepage_checkout_enabled() ) {
		return;
	}
	if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
		return;
	}
	?>
	<style>
	.wutm-wc-custom-onepage-cart-wrap { margin-bottom: 40px; padding-bottom: 20px; border-bottom: 2px solid #e5e5e5; }
    .wutm-wc-custom-cart-form { display: block; width: 100%; }
	.wutm-wc-custom-onepage-cart-table { width: 100%; border-collapse: collapse; }
	.wutm-wc-custom-onepage-cart-table th,
	.wutm-wc-custom-onepage-cart-table td { padding: 10px; border-bottom: 1px solid #e5e5e5; text-align: left; vertical-align: middle; }
	.wutm-wc-custom-cart-thumb { width: 48px; height: 48px; vertical-align: middle; margin-right: 8px; }
	.wutm-wc-custom-onepage-cart-actions { display: flex; justify-content: flex-start; margin-top: 15px; }
	</style>
	<?php
}

/* =========================================================
 * 二、可變商品屬性（自訂屬性，非全域屬性）
 * ========================================================= */
function wutm_wc_custom_is_attr_enter_add_enabled() {
	return (bool) get_option( 'wutm_wc_attr_enter_add', true );
}

function wutm_wc_custom_is_variation_tags_enabled() {
	return (bool) get_option( 'wutm_wc_variation_tags', true );
}

/**
 * 2-1A. 使用 PHP 底層過濾器替換屬性提示文字 (最穩定做法)
 */
add_filter( 'gettext', 'wutm_wc_custom_change_attribute_description_text', 20, 3 );
function wutm_wc_custom_change_attribute_description_text( $translated_text, $text, $domain ) {
    if ( 'woocommerce' === $domain ) {
        // 抓取 WooCommerce 原生的英文字串進行比對替換
        if ( 'Enter some text, or some attributes by "|" separating values.' === $text ) {
            $translated_text = '輸入可供顧客選擇的選項，例如：「藍色」或「大」。按 Enter 會自動補上分隔符號並開新的一組，不用手動打 | 符號';
        }
    }
    return $translated_text;
}

/**
 * 2-1B. 商品編輯頁：注入 JS 支援按 Enter 直接補分隔符號。
 */
add_action( 'admin_footer', 'wutm_wc_custom_attribute_enter_to_add_script' );
function wutm_wc_custom_attribute_enter_to_add_script() {
	if ( ! wutm_wc_custom_is_attr_enter_add_enabled() ) {
		return;
	}
	global $post_type;
	if ( 'product' !== $post_type ) {
		return;
	}
	?>
	<script>
	jQuery( function ( $ ) {
        // 綁定 Enter 快捷鍵
		$( document ).on( 'keydown', '.woocommerce_attribute textarea[name^="attribute_values"]', function ( e ) {
			if ( e.key !== 'Enter' || e.shiftKey ) {
				return;
			}
			e.preventDefault();
			var $textarea = $( this );
			var val = $textarea.val();
			val = val.replace( /\s*\|\s*$/, '' );
			if ( val.length && ! /\|\s*$/.test( val ) ) {
				val = val + ' | ';
			}
			$textarea.val( val ).trigger( 'change' ).focus();
			var len = $textarea.val().length;
			this.setSelectionRange( len, len );
		} );
	} );
	</script>
	<?php
}

/**
 * 2-2. 前台：把可變商品的下拉選單（select）換成標籤按鈕
 */
add_filter( 'woocommerce_dropdown_variation_attribute_options_html', 'wutm_wc_custom_render_attribute_as_tags', 20, 2 );
function wutm_wc_custom_render_attribute_as_tags( $html, $args ) {
	if ( ! wutm_wc_custom_is_variation_tags_enabled() ) {
		return $html;
	}
	$attribute = isset( $args['attribute'] ) ? $args['attribute'] : '';
	$product   = isset( $args['product'] ) ? $args['product'] : null;
	$options   = isset( $args['options'] ) ? $args['options'] : array();
	$name      = isset( $args['name'] ) ? $args['name'] : 'attribute_' . sanitize_title( $attribute );
	$id        = isset( $args['id'] ) ? $args['id'] : sanitize_title( $attribute );
	$selected  = isset( $args['selected'] ) ? $args['selected'] : ( isset( $_REQUEST[ $name ] ) ? wc_clean( wp_unslash( $_REQUEST[ $name ] ) ) : '' );
	$is_taxonomy = 0 === strpos( $attribute, 'pa_' );

	if ( empty( $options ) && $product instanceof WC_Product ) {
		$attributes = $product->get_variation_attributes();
		$options    = isset( $attributes[ $attribute ] ) ? $attributes[ $attribute ] : array();
	}
	if ( empty( $options ) ) {
		return $html;
	}

	ob_start();
	?>
	<div class="wutm-wc-custom-variation-tags" data-attribute_name="<?php echo esc_attr( $name ); ?>">
		<?php foreach ( $options as $option ) :
			if ( $is_taxonomy ) {
				$term      = get_term_by( 'slug', $option, $attribute );
				$term_name = $term ? apply_filters( 'woocommerce_variation_option_name', $term->name, $term, $attribute, $product ) : $option;
				$value     = $option;
			} else {
				$term_name = apply_filters( 'woocommerce_variation_option_name', $option, null, $attribute, $product );
				$value     = $option;
			}
			$tag_id = $id . '_' . sanitize_title( $value );
			?>
			<span class="wutm-wc-custom-variation-tag-item">
				<input type="radio"
					class="wutm-wc-custom-variation-tag-radio"
					name="<?php echo esc_attr( $name ); ?>"
					id="<?php echo esc_attr( $tag_id ); ?>"
					value="<?php echo esc_attr( $value ); ?>"
					<?php checked( $selected, $value ); ?> />
				<label for="<?php echo esc_attr( $tag_id ); ?>" class="wutm-wc-custom-variation-tag-label">
					<?php echo esc_html( $term_name ); ?>
				</label>
			</span>
		<?php endforeach; ?>
	</div>
	<?php
	$tags_html = ob_get_clean();
	$hidden_select = preg_replace( '/<select/', '<select style="display:none;" aria-hidden="true"', $html, 1 );
	return $tags_html . $hidden_select;
}

/**
 * 2-3. 前台 JS：標籤按鈕與隱藏 select 同步 + 缺貨樣式。
 * 修復說明：大幅強化 DOM 關聯尋找邏輯，確保選項變更會正確通知 WooCommerce 原生腳本
 */
add_action( 'wp_enqueue_scripts', 'wutm_wc_custom_enqueue_variation_tag_script' );
function wutm_wc_custom_enqueue_variation_tag_script() {
	if ( ! wutm_wc_custom_is_variation_tags_enabled() ) {
		return;
	}
	if ( ! function_exists( 'is_product' ) || ! is_product() ) {
		return;
	}

    // 加上 \$ 避免 PHP 將 JS 變數當作 PHP 變數報錯
    $script = "
    jQuery( function ( $ ) {
        // 1. 點擊按鈕變更時
        $( document ).on( 'change', '.wutm-wc-custom-variation-tag-radio', function () {
            var \$radio   = $( this );
            var \$wrap    = \$radio.closest( '.wutm-wc-custom-variation-tags' );
            var value    = \$radio.val();
            // 尋找同一個層級下的 select (WooCommerce 原生下拉選單)
            var \$select  = \$wrap.parent().find( 'select' );

            if ( \$select.length ) {
                \$select.val( value ).trigger( 'change' );
            }

            \$wrap.find( '.wutm-wc-custom-variation-tag-item' ).removeClass( 'is-selected' );
            \$radio.closest( '.wutm-wc-custom-variation-tag-item' ).addClass( 'is-selected' );
        } );

        // 2. 監聽清除按鈕 (reset_data)
        $( document ).on( 'reset_data', '.variations_form', function () {
            $( this ).find( '.wutm-wc-custom-variation-tag-radio' ).prop( 'checked', false );
            $( this ).find( '.wutm-wc-custom-variation-tag-item' ).removeClass( 'is-selected' );
        } );

        // 3. 監聽 WooCommerce 屬性狀態更新 (缺貨、停用等)
        $( document ).on( 'woocommerce_update_variation_values', function ( e ) {
            $( e.target ).find( '.wutm-wc-custom-variation-tags' ).each( function () {
                var \$wrap   = $( this );
                var \$select = \$wrap.parent().find( 'select' );

                \$wrap.find( '.wutm-wc-custom-variation-tag-radio' ).each( function () {
                    var \$radio  = $( this );
                    var val     = \$radio.val();
                    var \$option = \$radio.closest( '.wutm-wc-custom-variation-tag-item' );

                    // 檢查對應的 option 是否存在且沒有被 disable
                    var \$selectOption = \$select.find( 'option[value=\"' + val + '\"]' );
                    if ( \$selectOption.length === 0 || \$selectOption.prop('disabled') || \$selectOption.hasClass('disabled') ) {
                        \$radio.prop( 'disabled', true );
                        \$option.addClass( 'is-disabled' );
                    } else {
                        \$radio.prop( 'disabled', false );
                        \$option.removeClass( 'is-disabled' );
                    }
                } );
            } );
        } );
    } );
    ";

	wp_add_inline_script( 'wc-add-to-cart-variation', $script, 'after' );
}

add_action( 'wp_head', 'wutm_wc_custom_variation_tag_style' );
function wutm_wc_custom_variation_tag_style() {
	if ( ! wutm_wc_custom_is_variation_tags_enabled() ) {
		return;
	}
	if ( ! function_exists( 'is_product' ) || ! is_product() ) {
		return;
	}
	?>
	<style>
	.wutm-wc-custom-variation-tags { display: flex; flex-wrap: wrap; gap: 8px; margin: 6px 0 12px; }
	.wutm-wc-custom-variation-tag-item { position: relative; }
	.wutm-wc-custom-variation-tag-item input[type="radio"] { position: absolute; opacity: 0; width: 0; height: 0; }
	.wutm-wc-custom-variation-tag-label {
		display: inline-block;
		padding: 6px 16px;
		border: 1px solid #ccc;
		border-radius: 4px;
		cursor: pointer;
		font-size: 14px;
		transition: all 0.15s ease-in-out;
	}
	.wutm-wc-custom-variation-tag-item.is-selected .wutm-wc-custom-variation-tag-label {
		border-color: #333;
		background: #333;
		color: #fff;
	}
	.wutm-wc-custom-variation-tag-item.is-disabled .wutm-wc-custom-variation-tag-label {
		opacity: 0.4;
		text-decoration: line-through;
		cursor: not-allowed;
	}
	</style>
	<?php
}

/* =========================================================
 * 三、虛擬商品自動完成訂單
 * ========================================================= */
function wutm_wc_custom_is_virtual_order_autocomplete_enabled() {
	return (bool) get_option( 'wutm_wc_virtual_order_autocomplete', false );
}

add_filter( 'woocommerce_order_item_needs_processing', 'wutm_wc_custom_auto_complete_virtual_orders', 10, 3 );
function wutm_wc_custom_auto_complete_virtual_orders( $needs_processing, $product, $order_id ) {
	// 判斷：開關有開啟 + 該項目是有效商品
	if ( wutm_wc_custom_is_virtual_order_autocomplete_enabled() && $product && is_callable( array( $product, 'is_virtual' ) ) ) {
		// 只要是虛擬商品，就告訴 WooCommerce 該項目不需要額外做實體出貨（processing）處理
		if ( $product->is_virtual() ) {
			return false;
		}
	}

	// 其他常態實體商品回傳原本的值
	return $needs_processing;
}
