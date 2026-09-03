<?php
/**
 * Module: media-library-manager
 *
 * Adds lightweight virtual folders for attachments. Folder assignments are
 * stored as taxonomy terms and never move, rename, or duplicate real files.
 */
defined('ABSPATH') || exit;

final class WUTM_Media_Library_Manager {
    private const SLUG = 'wu-media-library-manager';
    private const TAXONOMY = 'wutm_media_folder';

    public static function boot(): void {
        add_action('init', [__CLASS__, 'register_taxonomy']);
        add_action('admin_menu', [__CLASS__, 'menu'], 20);
        add_action('admin_post_wutm_media_folder_create', [__CLASS__, 'create_folder']);
        add_action('admin_post_wutm_media_folder_rename', [__CLASS__, 'rename_folder']);
        add_action('admin_post_wutm_media_folder_delete', [__CLASS__, 'delete_folder']);
        add_action('admin_post_wutm_media_folder_move', [__CLASS__, 'move_attachments']);
        add_action('restrict_manage_posts', [__CLASS__, 'media_filter']);
        add_action('pre_get_posts', [__CLASS__, 'filter_media_query']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'media_grid_assets']);
        add_action('wp_ajax_wutm_media_folder_assign', [__CLASS__, 'ajax_assign_attachment']);
        add_filter('attachment_fields_to_edit', [__CLASS__, 'attachment_folder_field'], 10, 2);
        add_filter('attachment_fields_to_save', [__CLASS__, 'save_attachment_folder'], 10, 2);
    }

    public static function register_taxonomy(): void {
        register_taxonomy(self::TAXONOMY, ['attachment'], [
            'labels' => [
                'name' => '媒體資料夾',
                'singular_name' => '媒體資料夾',
            ],
            'public' => false,
            'publicly_queryable' => false,
            'show_ui' => false,
            'show_in_menu' => false,
            'show_in_nav_menus' => false,
            'show_tagcloud' => false,
            'show_in_rest' => false,
            'hierarchical' => true,
            'rewrite' => false,
            'query_var' => false,
            'update_count_callback' => '_update_generic_term_count',
        ]);
    }

    public static function menu(): void {
        add_submenu_page(
            'wu-toolbox-modular',
            '媒體庫管理',
            '媒體庫管理',
            'manage_options',
            self::SLUG,
            [__CLASS__, 'page']
        );
    }

    private static function folder_terms(): array {
        $terms = get_terms([
            'taxonomy' => self::TAXONOMY,
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC',
        ]);
        return is_wp_error($terms) ? [] : $terms;
    }

    private static function valid_folder_id($folder_id): int {
        $folder_id = absint($folder_id);
        if (!$folder_id || !term_exists($folder_id, self::TAXONOMY)) {
            return 0;
        }
        return $folder_id;
    }

    private static function redirect(array $args = []): void {
        $args = array_merge(['page' => self::SLUG], $args);
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    public static function create_folder(): void {
        if (!current_user_can('manage_options')) {
            wp_die('權限不足', 403);
        }
        check_admin_referer('wutm_media_folder_create');

        $name = isset($_POST['folder_name']) ? sanitize_text_field(wp_unslash($_POST['folder_name'])) : '';
        $parent = self::valid_folder_id($_POST['parent_folder'] ?? 0);
        if ($name === '') {
            self::redirect(['result' => 'empty_name']);
        }

        $result = wp_insert_term($name, self::TAXONOMY, ['parent' => $parent]);
        if (is_wp_error($result)) {
            self::redirect(['result' => 'create_failed']);
        }
        self::redirect(['result' => 'created', 'folder' => absint($result['term_id'])]);
    }

    public static function rename_folder(): void {
        if (!current_user_can('manage_options')) {
            wp_die('權限不足', 403);
        }
        check_admin_referer('wutm_media_folder_rename');

        $folder_id = self::valid_folder_id($_POST['folder_id'] ?? 0);
        $name = isset($_POST['folder_name']) ? sanitize_text_field(wp_unslash($_POST['folder_name'])) : '';
        if (!$folder_id || $name === '') {
            self::redirect(['result' => 'rename_failed']);
        }

        $result = wp_update_term($folder_id, self::TAXONOMY, ['name' => $name]);
        self::redirect(['result' => is_wp_error($result) ? 'rename_failed' : 'renamed', 'folder' => $folder_id]);
    }

    public static function delete_folder(): void {
        if (!current_user_can('manage_options')) {
            wp_die('權限不足', 403);
        }
        check_admin_referer('wutm_media_folder_delete');

        $folder_id = self::valid_folder_id($_POST['folder_id'] ?? 0);
        if (!$folder_id) {
            self::redirect(['result' => 'delete_failed']);
        }

        $children = get_terms([
            'taxonomy' => self::TAXONOMY,
            'hide_empty' => false,
            'parent' => $folder_id,
            'number' => 1,
        ]);
        if (!is_wp_error($children) && !empty($children)) {
            self::redirect(['result' => 'has_children', 'folder' => $folder_id]);
        }

        $deleted = wp_delete_term($folder_id, self::TAXONOMY);
        self::redirect(['result' => $deleted ? 'deleted' : 'delete_failed']);
    }

    public static function move_attachments(): void {
        if (!current_user_can('manage_options')) {
            wp_die('權限不足', 403);
        }
        check_admin_referer('wutm_media_folder_move');

        $folder_id = self::valid_folder_id($_POST['destination_folder'] ?? 0);
        $raw_ids = isset($_POST['attachment_ids']) && is_array($_POST['attachment_ids'])
            ? wp_unslash($_POST['attachment_ids'])
            : [];
        $moved = 0;
        foreach ($raw_ids as $attachment_id) {
            $attachment_id = absint($attachment_id);
            if (!$attachment_id || get_post_type($attachment_id) !== 'attachment' || !current_user_can('edit_post', $attachment_id)) {
                continue;
            }
            wp_set_object_terms($attachment_id, $folder_id ? [$folder_id] : [], self::TAXONOMY, false);
            $moved++;
        }
        self::redirect(['result' => $moved ? 'moved' : 'no_media', 'moved' => $moved, 'folder' => $folder_id]);
    }

    public static function media_filter(string $post_type): void {
        if ($post_type !== 'attachment' || !current_user_can('upload_files')) {
            return;
        }
        $selected = isset($_GET['wutm_media_folder_filter']) ? sanitize_text_field(wp_unslash($_GET['wutm_media_folder_filter'])) : '';
        echo '<label class="screen-reader-text" for="wutm-media-folder-filter">媒體資料夾</label>';
        echo '<select id="wutm-media-folder-filter" name="wutm_media_folder_filter">';
        echo '<option value="">所有媒體資料夾</option>';
        echo '<option value="unassigned" ' . selected($selected, 'unassigned', false) . '>未分類</option>';
        foreach (self::folder_terms() as $term) {
            $prefix = str_repeat('— ', max(0, count(get_ancestors($term->term_id, self::TAXONOMY))));
            echo '<option value="' . esc_attr($term->term_id) . '" ' . selected($selected, (string) $term->term_id, false) . '>' . esc_html($prefix . $term->name) . '</option>';
        }
        echo '</select>';
    }

    public static function filter_media_query($query): void {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!is_admin() || !($query instanceof WP_Query) || !$query->is_main_query() || !$screen || $screen->base !== 'upload') {
            return;
        }
        $selected = isset($_GET['wutm_media_folder_filter']) ? sanitize_text_field(wp_unslash($_GET['wutm_media_folder_filter'])) : '';
        if ($selected === '') {
            return;
        }
        $tax_query = (array) $query->get('tax_query');
        if ($selected === 'unassigned') {
            $tax_query[] = ['taxonomy' => self::TAXONOMY, 'operator' => 'NOT EXISTS'];
        } else {
            $folder_id = self::valid_folder_id($selected);
            if (!$folder_id) {
                return;
            }
            $tax_query[] = ['taxonomy' => self::TAXONOMY, 'field' => 'term_id', 'terms' => [$folder_id], 'include_children' => true];
        }
        $query->set('tax_query', $tax_query);
    }

    public static function media_grid_assets(string $hook): void {
        if ($hook !== 'upload.php' || !current_user_can('upload_files')) {
            return;
        }

        $folders = array_map(static function ($term): array {
            return [
                'id' => (int) $term->term_id,
                'name' => $term->name,
                'prefix' => str_repeat('— ', max(0, count(get_ancestors($term->term_id, self::TAXONOMY)))),
            ];
        }, self::folder_terms());

        wp_enqueue_script('media-views');
        wp_add_inline_style('common', '#wutm-media-folder-dnd{display:flex;flex-wrap:wrap;gap:8px;align-items:center;padding:10px 16px;background:#fff;border-bottom:1px solid #dcdcde}.wutm-media-folder-dnd-title{font-weight:600;margin-right:4px}.wutm-media-folder-drop{padding:6px 10px;border:1px dashed #2271b1;border-radius:6px;background:#f6fbff;color:#135e96;cursor:grab}.wutm-media-folder-drop:hover,.wutm-media-folder-drop.is-over{background:#dbeeff;border-style:solid}.wutm-media-folder-drop.is-busy{opacity:.55;pointer-events:none}.attachments .attachment[draggable=true]{cursor:grab}.attachments .attachment[draggable=true]:active{cursor:grabbing}');
        $script = 'window.WUTMMediaFolders = ' . wp_json_encode([
            'folders' => $folders,
            'nonce' => wp_create_nonce('wutm_media_folder_assign'),
            'ajaxUrl' => admin_url('admin-ajax.php'),
        ]) . ';' . "\n" . <<<'JS'
(function ($) {
    'use strict';
    var config = window.WUTMMediaFolders || {};

    function attachmentId(element) {
        var value = $(element).data('id') || $(element).attr('data-id');
        return parseInt(value, 10) || 0;
    }

    function prepareTiles() {
        $('.attachments .attachment').attr('draggable', 'true').attr('title', '拖曳至上方資料夾即可分類');
    }

    function assign(id, folderId, $target) {
        if (!id || $target.hasClass('is-busy')) {
            return;
        }
        $target.addClass('is-busy');
        $.post(config.ajaxUrl, {
            action: 'wutm_media_folder_assign',
            nonce: config.nonce,
            attachment_id: id,
            folder_id: folderId
        }).done(function (response) {
            if (response && response.success) {
                $target.addClass('is-saved');
                window.setTimeout(function () { $target.removeClass('is-saved'); }, 900);
            } else {
                window.alert((response && response.data && response.data.message) || '無法更新媒體資料夾。');
            }
        }).fail(function () {
            window.alert('無法更新媒體資料夾，請重新整理頁面後再試。');
        }).always(function () {
            $target.removeClass('is-busy is-over');
        });
    }

    function addPanel() {
        if ($('#wutm-media-folder-dnd').length || !$('.media-frame').length) {
            return;
        }
        var $panel = $('<div>', { id: 'wutm-media-folder-dnd', 'class': 'wutm-media-folder-dnd' });
        $panel.append($('<span>', { 'class': 'wutm-media-folder-dnd-title', text: '媒體資料夾：' }));
        $panel.append($('<span>', { text: '把圖片拖曳到資料夾即可分類' }));
        $panel.append($('<button>', { type: 'button', 'class': 'wutm-media-folder-drop', 'data-folder': '0', text: '未分類' }));
        (config.folders || []).forEach(function (folder) {
            $panel.append($('<button>', {
                type: 'button',
                'class': 'wutm-media-folder-drop',
                'data-folder': String(folder.id),
                text: String(folder.prefix || '') + String(folder.name || '')
            }));
        });
        $('.media-frame').first().prepend($panel);
    }

    function bindObserver() {
        var root = document.querySelector('.attachments');
        if (!root || root.dataset.wutmFolderObserver) {
            return;
        }
        root.dataset.wutmFolderObserver = '1';
        new MutationObserver(prepareTiles).observe(root, { childList: true, subtree: true });
    }

    function initialize() {
        addPanel();
        prepareTiles();
        bindObserver();
    }

    $(initialize);
    window.setTimeout(initialize, 500);
    window.setTimeout(initialize, 1500);

    $(document).on('dragstart.wutmMediaFolders', '.attachments .attachment', function (event) {
        var id = attachmentId(this);
        if (!id || !event.originalEvent.dataTransfer) {
            return;
        }
        event.originalEvent.dataTransfer.setData('text/plain', String(id));
        event.originalEvent.dataTransfer.effectAllowed = 'move';
    });
    $(document).on('dragover.wutmMediaFolders', '.wutm-media-folder-drop', function (event) {
        event.preventDefault();
        event.originalEvent.dataTransfer.dropEffect = 'move';
        $(this).addClass('is-over');
    });
    $(document).on('dragleave.wutmMediaFolders', '.wutm-media-folder-drop', function () {
        $(this).removeClass('is-over');
    });
    $(document).on('drop.wutmMediaFolders', '.wutm-media-folder-drop', function (event) {
        event.preventDefault();
        var id = parseInt(event.originalEvent.dataTransfer.getData('text/plain'), 10) || 0;
        assign(id, parseInt($(this).data('folder'), 10) || 0, $(this));
    });
}(jQuery));
JS;
        wp_add_inline_script('media-views', $script, 'after');
    }

    public static function ajax_assign_attachment(): void {
        if (!current_user_can('upload_files')) {
            wp_send_json_error(['message' => '權限不足。'], 403);
        }
        check_ajax_referer('wutm_media_folder_assign', 'nonce');

        $attachment_id = absint($_POST['attachment_id'] ?? 0);
        if (!$attachment_id || get_post_type($attachment_id) !== 'attachment' || !current_user_can('edit_post', $attachment_id)) {
            wp_send_json_error(['message' => '找不到可編輯的媒體檔案。'], 404);
        }

        $folder_id = self::valid_folder_id($_POST['folder_id'] ?? 0);
        wp_set_object_terms($attachment_id, $folder_id ? [$folder_id] : [], self::TAXONOMY, false);
        wp_send_json_success(['folder_id' => $folder_id]);
    }

    private static function attachment_folder_id(int $attachment_id): int {
        $terms = wp_get_object_terms($attachment_id, self::TAXONOMY, ['fields' => 'ids']);
        return is_wp_error($terms) || empty($terms) ? 0 : absint(reset($terms));
    }

    public static function attachment_folder_field(array $form_fields, $post): array {
        if (!current_user_can('upload_files') || !current_user_can('edit_post', $post->ID)) {
            return $form_fields;
        }
        ob_start();
        wp_dropdown_categories([
            'taxonomy' => self::TAXONOMY,
            'hide_empty' => false,
            'hierarchical' => true,
            'name' => 'attachments[' . (int) $post->ID . '][wutm_media_folder]',
            'id' => 'attachments-' . (int) $post->ID . '-wutm-media-folder',
            'selected' => self::attachment_folder_id((int) $post->ID),
            'show_option_none' => '未分類',
            'option_none_value' => '0',
        ]);
        $form_fields['wutm_media_folder'] = [
            'label' => '媒體資料夾',
            'input' => 'html',
            'html' => ob_get_clean(),
            'helps' => '選擇此媒體檔案的虛擬資料夾；不會變更實體檔案位置或網址。',
        ];
        return $form_fields;
    }

    public static function save_attachment_folder(array $post, array $attachment): array {
        if (!isset($post['ID']) || !array_key_exists('wutm_media_folder', $attachment) || !current_user_can('edit_post', (int) $post['ID'])) {
            return $post;
        }
        $folder_id = self::valid_folder_id($attachment['wutm_media_folder']);
        wp_set_object_terms((int) $post['ID'], $folder_id ? [$folder_id] : [], self::TAXONOMY, false);
        return $post;
    }

    private static function result_message(string $result): string {
        $messages = [
            'created' => '媒體資料夾已建立。',
            'renamed' => '媒體資料夾已重新命名。',
            'deleted' => '媒體資料夾已刪除；媒體檔案本身不會被刪除。',
            'moved' => '選取的媒體檔案已更新資料夾。',
            'empty_name' => '請輸入資料夾名稱。',
            'create_failed' => '無法建立資料夾，名稱可能已存在。',
            'rename_failed' => '無法重新命名資料夾。',
            'delete_failed' => '無法刪除資料夾。',
            'has_children' => '請先處理子資料夾，再刪除此資料夾。',
            'no_media' => '請至少選取一個媒體檔案。',
        ];
        return $messages[$result] ?? '';
    }

    public static function page(): void {
        if (!current_user_can('manage_options')) {
            wp_die('權限不足', 403);
        }
        $folder = self::valid_folder_id($_GET['folder'] ?? 0);
        $paged = max(1, absint($_GET['paged'] ?? 1));
        $query_args = [
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => 40,
            'paged' => $paged,
            'orderby' => 'date',
            'order' => 'DESC',
        ];
        if ($folder) {
            $query_args['tax_query'] = [[
                'taxonomy' => self::TAXONOMY,
                'field' => 'term_id',
                'terms' => [$folder],
                'include_children' => true,
            ]];
        }
        $attachments = new WP_Query($query_args);
        $folders = self::folder_terms();
        $result = isset($_GET['result']) ? sanitize_key(wp_unslash($_GET['result'])) : '';
        ?>
        <div class="wrap wutm-module-wrap wutm-media-library-manager">
            <h1>媒體庫管理</h1>
            <p class="wutm-module-subtitle">以虛擬資料夾分類媒體庫。資料夾只儲存在 WordPress 資料庫，不會移動實體檔案、改變檔名或影響既有圖片網址。</p>

            <?php if ($message = self::result_message($result)) : ?>
                <div class="notice notice-<?php echo in_array($result, ['created', 'renamed', 'deleted', 'moved'], true) ? 'success' : 'warning'; ?> is-dismissible"><p><?php echo esc_html($message); ?></p></div>
            <?php endif; ?>

            <div class="card" style="max-width:100%;margin:20px 0;">
                <h2 style="margin-top:0;">新增媒體資料夾</h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;flex-wrap:wrap;gap:10px;align-items:end;">
                    <input type="hidden" name="action" value="wutm_media_folder_create">
                    <?php wp_nonce_field('wutm_media_folder_create'); ?>
                    <p style="margin:0;min-width:220px;"><label for="wutm-folder-name">資料夾名稱</label><br><input id="wutm-folder-name" class="regular-text" type="text" name="folder_name" required></p>
                    <p style="margin:0;min-width:220px;"><label for="wutm-folder-parent">上層資料夾</label><br><?php wp_dropdown_categories(['taxonomy' => self::TAXONOMY, 'hide_empty' => false, 'hierarchical' => true, 'name' => 'parent_folder', 'id' => 'wutm-folder-parent', 'show_option_none' => '無（最上層）', 'option_none_value' => '0']); ?></p>
                    <?php submit_button('新增資料夾', 'secondary', 'submit', false); ?>
                </form>
            </div>

            <div class="card" style="max-width:100%;margin:20px 0;">
                <h2 style="margin-top:0;">資料夾管理</h2>
                <?php if (empty($folders)) : ?>
                    <p>尚未建立媒體資料夾。</p>
                <?php else : ?>
                    <table class="widefat striped"><thead><tr><th>資料夾</th><th>媒體數量</th><th>重新命名</th><th>刪除</th></tr></thead><tbody>
                    <?php foreach ($folders as $term) : ?>
                        <tr>
                            <td><a href="<?php echo esc_url(add_query_arg(['page' => self::SLUG, 'folder' => $term->term_id], admin_url('admin.php'))); ?>"><?php echo esc_html(str_repeat('— ', max(0, count(get_ancestors($term->term_id, self::TAXONOMY)))) . $term->name); ?></a></td>
                            <td><?php echo (int) $term->count; ?></td>
                            <td><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="wutm_media_folder_rename"><input type="hidden" name="folder_id" value="<?php echo (int) $term->term_id; ?>"><?php wp_nonce_field('wutm_media_folder_rename'); ?><input type="text" name="folder_name" value="<?php echo esc_attr($term->name); ?>"> <?php submit_button('儲存', 'secondary small', 'submit', false); ?></form></td>
                            <td><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('刪除資料夾不會刪除媒體檔案，但檔案會回到未分類。確定要刪除嗎？');"><input type="hidden" name="action" value="wutm_media_folder_delete"><input type="hidden" name="folder_id" value="<?php echo (int) $term->term_id; ?>"><?php wp_nonce_field('wutm_media_folder_delete'); ?><?php submit_button('刪除', 'delete small', 'submit', false); ?></form></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody></table>
                <?php endif; ?>
            </div>

            <div class="card" style="max-width:100%;margin-top:20px;">
                <h2 style="margin-top:0;">媒體檔案分類</h2>
                <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="margin-bottom:16px;">
                    <input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>">
                    <label for="wutm-media-folder-view">檢視資料夾</label>
                    <?php wp_dropdown_categories(['taxonomy' => self::TAXONOMY, 'hide_empty' => false, 'hierarchical' => true, 'name' => 'folder', 'id' => 'wutm-media-folder-view', 'selected' => $folder, 'show_option_none' => '所有媒體', 'option_none_value' => '0']); ?>
                    <?php submit_button('篩選', 'secondary', 'submit', false); ?>
                </form>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="wutm_media_folder_move">
                    <?php wp_nonce_field('wutm_media_folder_move'); ?>
                    <p><label for="wutm-media-destination">將選取媒體移至</label> <?php wp_dropdown_categories(['taxonomy' => self::TAXONOMY, 'hide_empty' => false, 'hierarchical' => true, 'name' => 'destination_folder', 'id' => 'wutm-media-destination', 'show_option_none' => '未分類', 'option_none_value' => '0']); ?> <?php submit_button('更新資料夾', 'secondary', 'submit', false); ?></p>
                    <table class="widefat striped"><thead><tr><td class="check-column"><input type="checkbox" onclick="var checked=this.checked;document.querySelectorAll('.wutm-attachment-checkbox').forEach(function(item){item.checked=checked;});"></td><th>檔案</th><th>目前資料夾</th><th>上傳日期</th></tr></thead><tbody>
                    <?php if ($attachments->have_posts()) : while ($attachments->have_posts()) : $attachments->the_post(); $attachment_id = get_the_ID(); $attachment_folder = self::attachment_folder_id($attachment_id); $attachment_term = $attachment_folder ? get_term($attachment_folder, self::TAXONOMY) : null; ?>
                        <tr>
                            <th class="check-column"><input class="wutm-attachment-checkbox" type="checkbox" name="attachment_ids[]" value="<?php echo (int) $attachment_id; ?>"></th>
                            <td><a href="<?php echo esc_url(get_edit_post_link($attachment_id)); ?>" style="display:flex;align-items:center;gap:10px;"><?php echo wp_kses_post(wp_get_attachment_image($attachment_id, [60, 60], true)); ?><span><?php echo esc_html(get_the_title() ?: basename((string) get_attached_file($attachment_id))); ?></span></a></td>
                            <td><?php echo $attachment_term && !is_wp_error($attachment_term) ? esc_html($attachment_term->name) : '未分類'; ?></td>
                            <td><?php echo esc_html(get_the_date('Y-m-d H:i')); ?></td>
                        </tr>
                    <?php endwhile; wp_reset_postdata(); else : ?>
                        <tr><td colspan="4">找不到媒體檔案。</td></tr>
                    <?php endif; ?>
                    </tbody></table>
                    <?php if ($attachments->max_num_pages > 1) : ?><p><?php echo wp_kses_post(paginate_links(['base' => add_query_arg('paged', '%#%'), 'format' => '', 'current' => $paged, 'total' => $attachments->max_num_pages])); ?></p><?php endif; ?>
                </form>
            </div>

            <div class="card" style="max-width:100%;margin-top:20px;">
                <h2 style="margin-top:0;">使用說明</h2>
                <ul style="list-style:disc;padding-left:22px;">
                    <li>可建立不限數量的資料夾與子資料夾；每個媒體檔案同時只能位於一個虛擬資料夾。</li>
                    <li>媒體庫列表會出現資料夾篩選器；開啟單一媒體檔案的詳細資料時，也可直接選擇資料夾。</li>
                    <li>刪除資料夾不會刪除任何媒體檔案，檔案只會回到未分類。</li>
                    <li>資料夾只用於後台管理，不會建立實體目錄、修改網址或影響前台顯示。</li>
                </ul>
            </div>
        </div>
        <?php
    }
}

WUTM_Media_Library_Manager::boot();
