<?php
defined('ABSPATH') || exit;

if (!function_exists('wutm_page_binding_sanitize')) {
    /**
     * Validate a selected WordPress page and update only its title.
     * The page content and slug are deliberately left untouched.
     */
    function wutm_page_binding_sanitize($raw_page_id, $raw_title, string $option_name): array {
        $page_id = absint($raw_page_id);
        $title = sanitize_text_field((string) $raw_title);

        if (!$page_id) return ['page_id' => 0, 'page_title' => ''];

        $page = get_post($page_id);
        if (!$page instanceof WP_Post || $page->post_type !== 'page' || $page->post_status === 'trash') {
            add_settings_error($option_name, 'wutm_invalid_bound_page', '選擇的頁面不存在或已移至回收桶，尚未變更頁面標題。', 'error');
            return ['page_id' => 0, 'page_title' => ''];
        }

        $current_title = get_the_title($page);
        if ($title === '') $title = $current_title;

        if (!current_user_can('edit_post', $page_id)) {
            add_settings_error($option_name, 'wutm_bound_page_permission', '目前帳號沒有編輯所選頁面的權限，尚未變更頁面標題。', 'error');
            return ['page_id' => $page_id, 'page_title' => $current_title];
        }

        if ($title !== $current_title) {
            $updated = wp_update_post([
                'ID' => $page_id,
                'post_title' => $title,
                'post_name' => $page->post_name,
            ], true);
            if (is_wp_error($updated)) {
                add_settings_error($option_name, 'wutm_bound_page_update_failed', '頁面標題更新失敗：' . $updated->get_error_message(), 'error');
                return ['page_id' => $page_id, 'page_title' => $current_title];
            }
        }

        return ['page_id' => $page_id, 'page_title' => $title];
    }

    function wutm_page_binding_pages(): array {
        return get_posts([
            'post_type' => 'page',
            'post_status' => ['publish', 'private', 'draft', 'pending', 'future'],
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'no_found_rows' => true,
        ]);
    }

    function wutm_page_binding_render(string $option_name, array $options, string $shortcode): void {
        $page_id = absint($options['page_id'] ?? 0);
        $saved_title = sanitize_text_field((string) ($options['page_title'] ?? ''));
        $bound_page = $page_id ? get_post($page_id) : null;
        $display_title = $bound_page instanceof WP_Post && $bound_page->post_type === 'page' ? get_the_title($bound_page) : $saved_title;
        $pages = wutm_page_binding_pages();
        ?>
        <section class="wutm-page-binding">
            <h2>綁定頁面與標題</h2>
            <div class="wutm-page-binding-grid">
                <label>套用頁面
                    <select class="wutm-page-binding-select" name="<?php echo esc_attr($option_name); ?>[page_id]">
                        <option value="0">— 不綁定頁面 —</option>
                        <?php foreach ($pages as $page):
                            $status = get_post_status_object($page->post_status);
                            $status_label = $status && $page->post_status !== 'publish' ? '（' . $status->label . '）' : '';
                            ?>
                            <option value="<?php echo (int) $page->ID; ?>" data-title="<?php echo esc_attr(get_the_title($page)); ?>" <?php selected($page_id, $page->ID); ?>><?php echo esc_html(get_the_title($page) . $status_label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>頁面標題
                    <input class="wutm-page-binding-title" type="text" name="<?php echo esc_attr($option_name); ?>[page_title]" value="<?php echo esc_attr($display_title); ?>">
                </label>
            </div>
            <p class="description">請選擇放置 <code>[<?php echo esc_html($shortcode); ?>]</code> 的頁面。儲存設定時會同步更新該頁面的正式標題；不會修改頁面內容、短代碼或網址代稱。</p>
            <?php if ($bound_page instanceof WP_Post && $bound_page->post_type === 'page'): ?>
                <p><a href="<?php echo esc_url(get_edit_post_link($bound_page->ID, '')); ?>">前往編輯已綁定頁面</a></p>
            <?php endif; ?>
        </section>
        <script>(function(){const section=document.currentScript.previousElementSibling;if(!section)return;const select=section.querySelector('.wutm-page-binding-select'),title=section.querySelector('.wutm-page-binding-title');if(!select||!title)return;select.addEventListener('change',function(){const option=select.options[select.selectedIndex];title.value=option&&option.value!=='0'?(option.dataset.title||''):'';});})();</script>
        <?php
    }
}
