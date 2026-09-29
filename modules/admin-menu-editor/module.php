<?php
/** Per-site default and per-account visual admin menu configuration. */
defined('ABSPATH') || exit;

final class WUTM_Admin_Menu_Editor {
    private const SLUG = 'wu-admin-menu-editor';
    private const DEFAULT_OPTION = 'wutm_admin_menu_default';
    private const USER_OPTION = 'wutm_admin_menu_override';
    private static array $registered_menu = [];
    private static array $registered_submenu = [];

    public static function boot(): void {
        add_action('admin_menu', [__CLASS__, 'register_page'], 30);
        add_action('admin_menu', [__CLASS__, 'capture_and_apply'], 100000);
        add_action('admin_post_wutm_admin_menu_save', [__CLASS__, 'save']);
        add_action('admin_post_wutm_admin_menu_reset', [__CLASS__, 'reset']);
        add_action('admin_post_wutm_admin_menu_owners', [__CLASS__, 'save_owners']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
    }

    public static function register_page(): void {
        if (wutm_admin_menu_editor_can_edit()) {
            add_submenu_page('wu-toolbox-modular', '後台選單編輯器', '後台選單編輯器', 'manage_options', self::SLUG, [__CLASS__, 'page']);
        }
    }

    private static function item_id(string $parent, string $slug): string {
        return ($parent === '' ? 't:' : 's:') . sha1($parent . "\0" . $slug);
    }

    private static function default_config(): array {
        $value = get_option(self::DEFAULT_OPTION, []);
        return is_array($value) ? $value : [];
    }

    private static function user_config(int $user_id): array {
        $value = get_user_option(self::USER_OPTION, $user_id);
        return is_array($value) ? $value : [];
    }

    private static function combined_config(int $user_id): array {
        $base = self::default_config();
        $override = self::user_config($user_id);
        foreach (($override['items'] ?? []) as $id => $fields) {
            if (!is_array($fields)) continue;
            $base['items'][$id] = array_merge((array) ($base['items'][$id] ?? []), $fields);
        }
        foreach (['top_order', 'sub_order'] as $field) {
            if (isset($override[$field])) $base[$field] = $override[$field];
        }
        return $base;
    }

    private static function sort_entries(array $entries, array $order, string $parent): array {
        if (!$order) return $entries;
        $rank = array_flip($order);
        $known = [];
        foreach ($entries as $entry) {
            $id = self::item_id($parent, (string) ($entry[2] ?? ''));
            if (isset($rank[$id])) $known[] = $entry;
        }
        usort($known, static function ($a, $b) use ($rank, $parent): int {
            return $rank[self::item_id($parent, (string) $a[2])] <=> $rank[self::item_id($parent, (string) $b[2])];
        });
        $next = 0;
        foreach ($entries as &$entry) {
            $id = self::item_id($parent, (string) ($entry[2] ?? ''));
            if (isset($rank[$id])) $entry = $known[$next++];
        }
        unset($entry);
        return $entries;
    }

    public static function capture_and_apply(): void {
        global $menu, $submenu;
        self::$registered_menu = (array) $menu;
        self::$registered_submenu = (array) $submenu;
        // The direct recovery URL displays the native menu without changing stored settings.
        if (!empty($_GET['wutm_menu_recover']) && wutm_admin_menu_editor_can_edit()) return;
        // One owner-edited site configuration is applied to every administrator.
        // Legacy per-user overrides remain stored but are no longer authoritative.
        $config = self::default_config();
        if (!$config) return;
        $items = (array) ($config['items'] ?? []);
        foreach ($menu as $index => &$entry) {
            $slug = (string) ($entry[2] ?? '');
            $id = self::item_id('', $slug);
            $fields = (array) ($items[$id] ?? []);
            // Keep the Toolbox and its editor reachable even if a saved config is malformed.
            if ($slug !== 'wu-toolbox-modular' && !empty($fields['hidden'])) {
                unset($menu[$index]);
                continue;
            }
            if (isset($fields['label']) && $fields['label'] !== '') $entry[0] = esc_html($fields['label']);
        }
        unset($entry);
        $menu = self::sort_entries(array_values($menu), (array) ($config['top_order'] ?? []), '');
        foreach ($submenu as $parent => &$entries) {
            foreach ($entries as $index => &$entry) {
                $slug = (string) ($entry[2] ?? '');
                $id = self::item_id((string) $parent, $slug);
                $fields = (array) ($items[$id] ?? []);
                if ($slug !== self::SLUG && !empty($fields['hidden'])) {
                    unset($entries[$index]);
                    continue;
                }
                if (isset($fields['label']) && $fields['label'] !== '') $entry[0] = esc_html($fields['label']);
            }
            unset($entry);
            $entries = self::sort_entries(array_values($entries), (array) ($config['sub_order'][$parent] ?? []), (string) $parent);
        }
        unset($entries);
    }

    private static function raw_catalog(): array {
        $catalog = [];
        foreach (self::$registered_menu as $entry) {
            $slug = (string) ($entry[2] ?? '');
            if ($slug === '') continue;
            $id = self::item_id('', $slug);
            $catalog[$id] = ['id' => $id, 'parent' => '', 'slug' => $slug, 'label' => wp_strip_all_tags((string) ($entry[0] ?? $slug))];
        }
        foreach (self::$registered_submenu as $parent => $entries) {
            foreach ($entries as $entry) {
                $slug = (string) ($entry[2] ?? '');
                if ($slug === '' || strpos($slug, 'wutm-group-') === 0) continue;
                $id = self::item_id((string) $parent, $slug);
                $catalog[$id] = ['id' => $id, 'parent' => (string) $parent, 'slug' => $slug, 'label' => wp_strip_all_tags((string) ($entry[0] ?? $slug))];
            }
        }
        return $catalog;
    }

    private static function clean_payload($raw): array {
        $input = json_decode((string) $raw, true);
        if (!is_array($input)) return [];
        $items = [];
        foreach ((array) ($input['items'] ?? []) as $id => $fields) {
            if (!is_string($id) || !preg_match('/^[ts]:[a-f0-9]{40}$/', $id) || !is_array($fields)) continue;
            $label = sanitize_text_field((string) ($fields['label'] ?? ''));
            $items[$id] = ['label' => function_exists('mb_substr') ? mb_substr($label, 0, 100) : substr($label, 0, 100), 'hidden' => !empty($fields['hidden'])];
        }
        $clean_order = static function ($values): array {
            if (!is_array($values)) return [];
            $values = array_filter($values, static fn($id) => is_string($id) && preg_match('/^[ts]:[a-f0-9]{40}$/', $id));
            return array_slice(array_values(array_unique($values)), 0, 500);
        };
        $sub_order = [];
        foreach ((array) ($input['sub_order'] ?? []) as $parent => $order) {
            if (!is_string($parent) || strlen($parent) > 200) continue;
            $sub_order[$parent] = $clean_order($order);
        }
        $clean = ['items' => $items];
        if (array_key_exists('top_order', $input)) $clean['top_order'] = $clean_order($input['top_order']);
        if (array_key_exists('sub_order', $input)) $clean['sub_order'] = $sub_order;
        return $clean;
    }

    public static function save(): void {
        if (!wutm_admin_menu_editor_can_edit()) wp_die('權限不足。', '', ['response' => 403]);
        check_admin_referer('wutm_admin_menu_save');
        $config = self::clean_payload(wp_unslash($_POST['config'] ?? ''));
        if (!$config) wp_die('選單資料無效，未儲存。');
        update_option(self::DEFAULT_OPTION, $config, false);
        wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'saved' => 1], admin_url('admin.php')));
        exit;
    }

    public static function reset(): void {
        if (!wutm_admin_menu_editor_can_edit()) wp_die('權限不足。', '', ['response' => 403]);
        check_admin_referer('wutm_admin_menu_reset');
        delete_option(self::DEFAULT_OPTION);
        wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'reset' => 1], admin_url('admin.php')));
        exit;
    }

    public static function save_owners(): void {
        if (!wutm_admin_menu_editor_can_edit()) wp_die('權限不足。', '', ['response' => 403]);
        check_admin_referer('wutm_admin_menu_owners');
        $raw = wp_unslash($_POST['owner_ids'] ?? []);
        if (!is_array($raw)) wp_die('主帳號清單格式無效。', '', ['response' => 400]);
        foreach ($raw as $value) {
            if (!is_scalar($value) || !ctype_digit((string) $value)) {
                wp_die('主帳號 ID 格式無效。', '', ['response' => 400]);
            }
        }
        $ids = array_values(array_unique(array_filter(array_map('absint', $raw))));
        if (!$ids || count($ids) > 20) wp_die('請選擇至少一位、最多二十位主帳號。', '', ['response' => 400]);
        foreach ($ids as $id) {
            $user = get_userdata($id);
            if (!$user || !user_can($user, 'manage_options')) {
                wp_die('主帳號必須是本站具有管理權限的現有帳號。', '', ['response' => 400]);
            }
        }
        update_option('wutm_admin_menu_editor_owner_ids', $ids, false);
        $url = in_array(get_current_user_id(), $ids, true)
            ? add_query_arg(['page' => self::SLUG, 'owners_saved' => 1], admin_url('admin.php'))
            : admin_url('admin.php?page=wu-toolbox-modular');
        wp_safe_redirect($url);
        exit;
    }

    public static function assets($hook): void {
        if (strpos((string) $hook, self::SLUG) === false) return;
        if (!wutm_admin_menu_editor_can_edit()) return;
        wp_enqueue_script('jquery-ui-sortable');
        wp_enqueue_script('wutm-admin-menu-editor', WUTM_URL . 'modules/admin-menu-editor/editor.js', ['jquery', 'jquery-ui-sortable'], WUTM_VERSION, true);
        wp_enqueue_style('wutm-admin-menu-editor', WUTM_URL . 'modules/admin-menu-editor/editor.css', [], WUTM_VERSION);
    }

    public static function page(): void {
        if (!wutm_admin_menu_editor_can_edit()) wp_die('權限不足。', '', ['response' => 403]);
        $owners = wutm_admin_menu_editor_owner_ids();
        $eligible_owners = get_users(['capability' => 'manage_options', 'orderby' => 'login', 'order' => 'ASC']);
        foreach ($owners as $owner_id) {
            $owner = get_userdata($owner_id);
            if ($owner && !in_array($owner_id, array_map(static fn($item) => (int) $item->ID, $eligible_owners), true)) {
                $eligible_owners[] = $owner;
            }
        }
        $catalog = self::raw_catalog();
        $config = self::default_config();
        $stored = $config;
        $obsolete = array_diff(array_keys((array) ($stored['items'] ?? [])), array_keys($catalog));
        $display_menu = self::sort_entries(array_values(self::$registered_menu), (array) ($config['top_order'] ?? []), '');
        $base = [];
        $base_top = self::sort_entries(array_values(self::$registered_menu), (array) ($base['top_order'] ?? []), '');
        $base_order = ['top_order' => [], 'sub_order' => []];
        foreach ($base_top as $entry) $base_order['top_order'][] = self::item_id('', (string) ($entry[2] ?? ''));
        foreach (self::$registered_submenu as $parent => $entries) {
            $sorted = self::sort_entries(array_values($entries), (array) ($base['sub_order'][$parent] ?? []), (string) $parent);
            $base_order['sub_order'][$parent] = [];
            foreach ($sorted as $entry) {
                $slug = (string) ($entry[2] ?? '');
                if ($slug !== '' && strpos($slug, 'wutm-group-') !== 0) $base_order['sub_order'][$parent][] = self::item_id((string) $parent, $slug);
            }
        }
        ?>
        <div class="wrap wutm-menu-editor"><h1>後台選單編輯器</h1>
            <p>調整選單的顯示順序、名稱與可見性。隱藏不會撤銷頁面權限，原網址仍可直接開啟。</p>
            <p><a href="<?php echo esc_url(add_query_arg(['page' => self::SLUG, 'wutm_menu_recover' => 1], admin_url('admin.php'))); ?>">以原生選單開啟恢復入口</a></p>
            <section class="wutm-menu-owners">
                <h2>誰可以編輯後台選單</h2>
                <p>只有下方勾選的主帳號可編輯網站共用選單。儲存後，所有管理員帳號都會套用相同名稱、順序與隱藏設定。</p>
                <?php if (get_option('wutm_admin_menu_editor_owner_ids', false) === false) : ?><p class="description">尚未指定時，預設由本站管理員 Email 對應的管理帳號使用；若該帳號不存在，則由最早建立的管理員帳號使用。儲存後以勾選名單為準。</p><?php endif; ?>
                <?php if (isset($_GET['owners_saved'])) : ?><div class="notice notice-success inline"><p>主帳號名單已儲存。</p></div><?php endif; ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('wutm_admin_menu_owners'); ?>
                    <input type="hidden" name="action" value="wutm_admin_menu_owners">
                    <div class="wutm-menu-owner-list">
                        <?php foreach ($eligible_owners as $candidate) : ?><label><input type="checkbox" name="owner_ids[]" value="<?php echo (int) $candidate->ID; ?>" <?php checked(in_array((int) $candidate->ID, $owners, true)); ?>> <?php echo esc_html($candidate->user_login . ' (#' . $candidate->ID . ')'); ?></label><?php endforeach; ?>
                    </div>
                    <p class="description">請至少保留一位主帳號。若取消自己的權限，儲存後會立即失去編輯入口。</p>
                    <button type="submit" class="button">儲存主帳號名單</button>
                </form>
            </section>
            <?php if (isset($_GET['saved']) || isset($_GET['reset'])) : ?><div class="notice notice-success"><p>選單設定已更新，重新整理後台頁面即可看到結果。</p></div><?php endif; ?>
            <?php if ($obsolete) : ?><div class="notice notice-info"><p>這份配置有 <?php echo (int) count($obsolete); ?> 個已不存在的選單項目；它們不會顯示，儲存目前配置即可清除舊設定。</p></div><?php endif; ?>
            <div class="wutm-menu-target"><strong>網站共用配置</strong><span>此配置會套用至所有管理員帳號。</span></div>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="wutm-menu-editor-form">
                <?php wp_nonce_field('wutm_admin_menu_save'); ?>
                <input type="hidden" name="action" value="wutm_admin_menu_save">
                <input type="hidden" name="config" id="wutm-menu-config">
                <input type="hidden" id="wutm-menu-base-order" value="<?php echo esc_attr(wp_json_encode($base_order)); ?>">
                <p>拖曳項目可調整順序；名稱留空代表使用原名稱。新增外掛的選單會依原位置顯示。</p>
                <div class="wutm-menu-list wutm-menu-top">
                <?php foreach ($display_menu as $top) :
                    $top_slug = (string) ($top[2] ?? '');
                    if ($top_slug === '') continue;
                    $top_id = self::item_id('', $top_slug);
                    $children = self::sort_entries(array_values((array) (self::$registered_submenu[$top_slug] ?? [])), (array) ($config['sub_order'][$top_slug] ?? []), $top_slug);
                    ?><div class="wutm-menu-group"><?php
                    self::render_item($catalog[$top_id], (array) ($config['items'][$top_id] ?? []), $top_slug === 'wu-toolbox-modular');
                    if ($children) : ?><div class="wutm-menu-list wutm-menu-children" data-parent="<?php echo esc_attr($top_slug); ?>"><div class="wutm-menu-section-items">
                    <?php foreach ($children as $child) :
                        $slug = (string) ($child[2] ?? '');
                        if ($slug === '') continue;
                        if (strpos($slug, 'wutm-group-') === 0) {
                            echo '</div><div class="wutm-menu-section">' . esc_html(wp_strip_all_tags((string) ($child[0] ?? ''))) . '</div><div class="wutm-menu-section-items">';
                            continue;
                        }
                        $id = self::item_id($top_slug, $slug);
                        self::render_item($catalog[$id], (array) ($config['items'][$id] ?? []), $slug === self::SLUG);
                    endforeach; ?></div></div><?php endif; ?></div><?php
                endforeach; ?></div>
                <?php submit_button('儲存這份配置'); ?>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wutm-menu-reset" onsubmit="return confirm('確定要恢復這份選單配置？');">
                <?php wp_nonce_field('wutm_admin_menu_reset'); ?><input type="hidden" name="action" value="wutm_admin_menu_reset">
                <button class="button" type="submit">恢復網站共用選單預設</button>
            </form>
        </div><?php
    }

    private static function render_item(array $item, array $settings, bool $protected): void {
        $label = (string) ($settings['label'] ?? '');
        ?><div class="wutm-menu-item" data-id="<?php echo esc_attr($item['id']); ?>">
            <span class="wutm-menu-handle" aria-label="拖曳排序">⋮⋮</span>
            <span class="wutm-menu-original"><?php echo esc_html($item['label']); ?></span>
            <input type="text" class="wutm-menu-label" value="<?php echo esc_attr($label); ?>" placeholder="使用原名稱" aria-label="<?php echo esc_attr($item['label'] . ' 的自訂名稱'); ?>">
            <label><input type="checkbox" class="wutm-menu-hidden" <?php checked(!empty($settings['hidden'])); ?> <?php disabled($protected); ?>> 隱藏</label>
        </div><?php
    }
}

WUTM_Admin_Menu_Editor::boot();
