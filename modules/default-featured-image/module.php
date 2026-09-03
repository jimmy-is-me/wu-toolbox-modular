<?php
/**
 * Module: default-featured-image
 *
 * Supplies one site-wide fallback featured image without changing the
 * original post meta or assigning an image to existing posts.
 */
defined('ABSPATH') || exit;

final class WUTM_Default_Featured_Image {
    private const SLUG = 'wu-default-featured-image';
    private const OPTION = 'wutm_default_featured_image_id';

    public static function boot(): void {
        add_action('admin_menu', [__CLASS__, 'menu'], 20);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
        add_action('admin_post_wutm_default_featured_image_save', [__CLASS__, 'save']);
        add_filter('post_thumbnail_html', [__CLASS__, 'fallback_thumbnail'], 20, 5);
    }

    public static function menu(): void {
        add_submenu_page(
            'wu-toolbox-modular',
            '預設精選圖片',
            '預設精選圖片',
            'manage_options',
            self::SLUG,
            [__CLASS__, 'page']
        );
    }

    private static function attachment_id(): int {
        $id = absint(get_option(self::OPTION, 0));
        return $id && get_post_type($id) === 'attachment' ? $id : 0;
    }

    private static function valid_attachment_id($id): int {
        $id = absint($id);
        $mime = $id ? (string) get_post_mime_type($id) : '';
        return $id && strpos($mime, 'image/') === 0 ? $id : 0;
    }

    public static function assets(string $hook): void {
        if ($hook !== 'wu-toolbox-modular_page_' . self::SLUG || !current_user_can('manage_options')) {
            return;
        }

        wp_enqueue_media();
        wp_add_inline_script('media-editor', <<<'JS'
jQuery(function($) {
    var frame;
    $(document).on('click', '.wutm-default-image-select', function(event) {
        event.preventDefault();
        if (frame) { frame.open(); return; }
        frame = wp.media({
            title: '選擇預設精選圖片',
            button: { text: '使用這張圖片' },
            multiple: false,
            library: { type: 'image' }
        });
        frame.on('select', function() {
            var attachment = frame.state().get('selection').first().toJSON();
            $('#wutm-default-featured-image-id').val(attachment.id);
            $('#wutm-default-featured-image-preview').html('<img alt="" src="' + attachment.url + '" style="max-width:220px;height:auto;">');
            $('.wutm-default-image-remove').prop('disabled', false);
        });
        frame.open();
    });
    $(document).on('click', '.wutm-default-image-remove', function(event) {
        event.preventDefault();
        $('#wutm-default-featured-image-id').val('0');
        $('#wutm-default-featured-image-preview').empty();
        $(this).prop('disabled', true);
    });
});
JS
        );
    }

    public static function save(): void {
        if (!current_user_can('manage_options')) {
            wp_die('權限不足。', 403);
        }
        check_admin_referer('wutm_default_featured_image_save');

        update_option(self::OPTION, self::valid_attachment_id($_POST['attachment_id'] ?? 0), false);
        wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'updated' => 1], admin_url('admin.php')));
        exit;
    }

    public static function fallback_thumbnail(string $html, $post_id, $post_thumbnail_id, $size, $attr): string {
        if ($html !== '' || (is_admin() && !wp_doing_ajax())) {
            return $html;
        }

        $post = get_post($post_id);
        if (!$post || !post_type_supports($post->post_type, 'thumbnail')) {
            return $html;
        }

        $attachment_id = self::attachment_id();
        if (!$attachment_id) {
            return $html;
        }

        $attr = is_array($attr) ? $attr : [];
        $existing_class = isset($attr['class']) ? (string) $attr['class'] . ' ' : '';
        $attr['class'] = trim($existing_class . 'wutm-default-featured-image');
        $attr['data-wutm-default-featured-image'] = '1';

        return wp_get_attachment_image($attachment_id, $size ?: 'post-thumbnail', false, $attr);
    }

    public static function page(): void {
        if (!current_user_can('manage_options')) {
            wp_die('權限不足。', 403);
        }
        $attachment_id = self::attachment_id();
        ?>
        <div class="wrap wutm-module-wrap">
            <h1>預設精選圖片</h1>
            <p class="wutm-module-subtitle">為尚未設定精選圖片的文章、頁面、商品與支援精選圖片的自訂內容類型顯示同一張預設圖片。</p>

            <?php if (!empty($_GET['updated'])) : ?>
                <div class="notice notice-success is-dismissible"><p>設定已儲存。</p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="card" style="max-width:760px;padding:24px;">
                <input type="hidden" name="action" value="wutm_default_featured_image_save">
                <?php wp_nonce_field('wutm_default_featured_image_save'); ?>
                <input id="wutm-default-featured-image-id" type="hidden" name="attachment_id" value="<?php echo (int) $attachment_id; ?>">

                <h2 style="margin-top:0;">選擇圖片</h2>
                <p id="wutm-default-featured-image-preview" style="min-height:48px;"><?php
                    if ($attachment_id) {
                        echo wp_kses_post(wp_get_attachment_image($attachment_id, 'medium'));
                    }
                ?></p>
                <p>
                    <button type="button" class="button button-secondary wutm-default-image-select">選擇媒體庫圖片</button>
                    <button type="button" class="button wutm-default-image-remove" <?php disabled(!$attachment_id); ?>>清除選擇</button>
                </p>
                <p class="description">此功能只在輸出精選圖片時提供顯示備援，不會批次寫入文章資料、不會取代既有精選圖片，也不會變更原始圖片網址。</p>
                <?php submit_button('儲存設定'); ?>
            </form>

            <div class="card" style="max-width:760px;padding:20px;margin-top:20px;">
                <h2 style="margin-top:0;">使用說明</h2>
                <ul style="list-style:disc;padding-left:22px;">
                    <li>有設定精選圖片的內容維持原狀。</li>
                    <li>清除選擇後，所有內容立即停止顯示預設圖片。</li>
                    <li>預設圖片可以隨時更換，無須重新儲存既有文章。</li>
                </ul>
            </div>
        </div>
        <?php
    }
}

WUTM_Default_Featured_Image::boot();
