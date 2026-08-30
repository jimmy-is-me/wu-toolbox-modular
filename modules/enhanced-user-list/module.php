<?php
/**
 * Module: enhanced-user-list
 * Loaded only when enabled in WU Toolbox Modular.
 */

/**
 * 增強使用者列表模組
 * 在使用者列表中添加上次登入時間、註冊日期等資訊
 */

if (!defined('ABSPATH')) exit;

class WU_Enhanced_User_List {

    private array $settings;

    private const ALLOWED_DATE_FORMATS = [
        'Y-m-d H:i:s',
        'Y-m-d',
        'd/m/Y',
        'm/d/Y',
        'F j, Y',
    ];

    private const MENU_SLUG = 'wumetax-enhanced-user-list';

    public function __construct() {
        $this->settings = $this->load_settings();

        add_action('admin_menu', [$this, 'add_admin_menu'], 15);

        add_action('admin_post_wutm_export_users_csv', [$this, 'export_users_csv']);
        add_action('admin_post_wutm_import_users_csv', [$this, 'import_users_csv']);

        add_filter('manage_users_columns',         [$this, 'add_user_columns']);
        add_filter('manage_users_custom_column',   [$this, 'show_user_column_content'], 10, 3);
        add_filter('manage_users_sortable_columns',[$this, 'make_columns_sortable']);
        add_action('pre_get_users',                [$this, 'handle_column_sorting']);

        add_action('wp_login', [$this, 'record_user_last_login'], 10, 2);

        add_action('restrict_manage_users', [$this, 'add_user_filters']);
        add_filter('pre_get_users',         [$this, 'filter_users_by_login_date']);

        add_action('show_user_profile', [$this, 'show_additional_user_info']);
        add_action('edit_user_profile', [$this, 'show_additional_user_info']);

        add_action('admin_notices', [$this, 'show_user_statistics']);

        if ($this->get_setting('hide_profile_options')) {
            add_action('admin_head', [$this, 'hide_user_profile_options']);
        }

        if ($this->get_setting('enable_user_export')) {
            add_filter('user_row_actions',          [$this, 'add_export_link'], 10, 2);
            add_filter('bulk_actions-users',        [$this, 'add_bulk_export_action']);
            add_filter('handle_bulk_actions-users', [$this, 'handle_bulk_export'], 10, 3);
            add_action('admin_action_wu_export_user', [$this, 'export_single_user']);
        }

        if ($this->get_setting('enable_custom_avatar')) {
            add_action('show_user_profile',        [$this, 'show_custom_avatar_field']);
            add_action('edit_user_profile',        [$this, 'show_custom_avatar_field']);
            add_action('personal_options_update',  [$this, 'save_custom_avatar']);
            add_action('edit_user_profile_update', [$this, 'save_custom_avatar']);
            add_filter('get_avatar',     [$this, 'custom_avatar'], 10, 5);
            add_filter('get_avatar_url', [$this, 'custom_avatar_url'], 10, 3);
            add_action('admin_enqueue_scripts', [$this, 'enqueue_media_assets']);
        }
    }

    // ===== 設定管理 =====

    private function load_settings(): array {
        $saved = get_option('wu_enhanced_user_list_settings', []);
        return array_merge($this->get_default_settings(), (array) $saved);
    }

    private function get_setting(string $key, $default = false) {
        return $this->settings[$key] ?? $default;
    }

    private function get_default_settings(): array {
        return [
            'enabled'                  => false,
            'show_last_login'          => true,
            'show_registration_date'   => true,
            'show_user_id'             => false,
            'show_role_since'          => false,
            'date_format'              => 'Y-m-d H:i:s',
            'show_filters'             => true,
            'show_statistics'          => true,
            'highlight_inactive_users' => 30,
            'hide_profile_options'     => false,
            'enable_user_export'       => false,
            'include_meta'             => true,
            'include_roles'            => true,
            'enable_custom_avatar'     => false,
            'avatar_size_limit'        => 2048,
            'allowed_avatar_types'     => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
        ];
    }

    // ===== 後台選單 =====

    public function add_admin_menu(): void {
        add_submenu_page(
            'wu-toolbox-modular',
            '強化使用者功能',
            '強化使用者功能',
            'manage_options',
            'wu-enhanced-user-list',
            [$this, 'admin_page']  // ✅ 修正：改為呼叫 class 方法，不再使用不存在的全域函數
        );
    }

    // ===== 設定頁面 =====

    public function admin_page(): void {
        if (!current_user_can('manage_options')) wp_die('權限不足');

        if (isset($_POST['submit'])) {
            $this->save_settings();
            $this->settings = $this->load_settings();
        }

        $user_stats = $this->get_user_statistics();
        $s          = $this->settings;
        $import_result = get_transient('wutm_user_import_result_' . get_current_user_id());
        delete_transient('wutm_user_import_result_' . get_current_user_id());
        ?>
        <div class="wrap">
            <h1>增強使用者列表設定</h1>

            <?php if (is_array($import_result)): ?>
                <div class="notice <?php echo !empty($import_result['error']) ? 'notice-error' : 'notice-success'; ?> is-dismissible">
                    <p><strong><?php echo esc_html($import_result['message'] ?? 'CSV 處理完成'); ?></strong></p>
                    <?php if (!empty($import_result['details'])): ?>
                        <ul style="margin:0 0 8px 18px;list-style:disc;">
                            <?php foreach ((array) $import_result['details'] as $detail): ?>
                                <li><?php echo esc_html($detail); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="notice notice-info">
                <h3 style="margin:.5em 0 .3em;">功能說明</h3>
                <p>為 WordPress 後台用戶列表添加更多有用欄位，提升管理效率。</p>
                <details>
                    <summary style="cursor:pointer;color:#0073aa;">查看詳細功能說明 ▼</summary>
                    <h4>新增欄位</h4>
                    <ul>
                        <li><strong>上次登入</strong>：顯示用戶最後一次登入時間</li>
                        <li><strong>註冊日期</strong>：以自訂格式顯示用戶註冊時間</li>
                        <li><strong>用戶 ID</strong>：顯示用戶的資料庫 ID</li>
                        <li><strong>角色指派時間</strong>：顯示用戶獲得當前角色的時間</li>
                    </ul>
                    <h4>增強功能</h4>
                    <ul>
                        <li>所有欄位支援排序、登入日期篩選器、用戶活動統計</li>
                        <li>非活躍用戶高亮顯示、統一隱藏用戶設定選項</li>
                    </ul>
                </details>
            </div>

            <form method="post" action="">
                <?php wp_nonce_field('wu_enhanced_user_list_settings'); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">啟用功能</th>
                        <td>
                            <label>
                                <input type="checkbox" name="enabled" value="1" <?php checked($s['enabled']); ?>>
                                啟用增強使用者列表功能
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">顯示欄位</th>
                        <td>
                            <?php
                            $fields = [
                                'show_last_login'        => ['顯示上次登入時間', true],
                                'show_registration_date' => ['顯示註冊日期',     true],
                                'show_user_id'           => ['顯示用戶 ID',       false],
                                'show_role_since'        => ['顯示角色指派時間', false],
                            ];
                            foreach ($fields as $key => [$label, $default]): ?>
                                <label style="display:block;margin:5px 0;">
                                    <input type="checkbox" name="<?php echo esc_attr($key); ?>" value="1" <?php checked($this->get_setting($key, $default)); ?>>
                                    <?php echo esc_html($label); ?>
                                </label>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">日期格式</th>
                        <td>
                            <select name="date_format">
                                <?php
                                $examples = [
                                    'Y-m-d H:i:s' => '2024-01-15 14:30:00',
                                    'Y-m-d'        => '2024-01-15',
                                    'd/m/Y'        => '15/01/2024',
                                    'm/d/Y'        => '01/15/2024',
                                    'F j, Y'       => 'January 15, 2024',
                                ];
                                foreach ($examples as $fmt => $label): ?>
                                    <option value="<?php echo esc_attr($fmt); ?>" <?php selected($s['date_format'], $fmt); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">顯示篩選器</th>
                        <td>
                            <label>
                                <input type="checkbox" name="show_filters" value="1" <?php checked($s['show_filters']); ?>>
                                在用戶列表頁面顯示篩選器
                            </label>
                            <p class="description">允許按登入日期等條件篩選用戶</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">顯示統計資訊</th>
                        <td>
                            <label>
                                <input type="checkbox" name="show_statistics" value="1" <?php checked($s['show_statistics']); ?>>
                                在用戶列表頁面顯示統計資訊
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">非活躍用戶高亮</th>
                        <td>
                            <input type="number" name="highlight_inactive_users" value="<?php echo esc_attr($s['highlight_inactive_users']); ?>" min="0" max="365" class="small-text">
                            天未登入的用戶高亮顯示
                            <p class="description">設為 0 停用此功能</p>
                        </td>
                    </tr>
                </table>

                <h2>隱藏用戶設定選項</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">統一隱藏設定</th>
                        <td>
                            <label>
                                <input type="checkbox" name="hide_profile_options" value="1" <?php checked($s['hide_profile_options']); ?>>
                                <strong>隱藏所有用戶設定選項</strong>
                            </label>
                            <p class="description">包含：Personal Options、About the user、Application Passwords、Elementor AI、社交媒體設定等</p>
                        </td>
                    </tr>
                </table>

                <h2>用戶匯出功能</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">啟用用戶匯出</th>
                        <td>
                            <label>
                                <input type="checkbox" name="enable_user_export" value="1" <?php checked($s['enable_user_export']); ?>>
                                啟用用戶資料匯出功能（CSV）
                            </label>
                            <p class="description">在用戶列表中新增匯出選項，支援單個和批量匯出</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">匯出選項</th>
                        <td>
                            <label style="display:block;margin:5px 0;">
                                <input type="checkbox" name="include_meta" value="1" <?php checked($s['include_meta']); ?>>
                                包含用戶中繼資料
                            </label>
                            <label style="display:block;margin:5px 0;">
                                <input type="checkbox" name="include_roles" value="1" <?php checked($s['include_roles']); ?>>
                                包含用戶角色資訊
                            </label>
                        </td>
                    </tr>
                </table>

                <h2>自訂頭像功能</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">啟用自訂頭像</th>
                        <td>
                            <label>
                                <input type="checkbox" name="enable_custom_avatar" value="1" <?php checked($s['enable_custom_avatar']); ?>>
                                允許用戶使用 WordPress 媒體庫圖片作為頭像
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">檔案大小限制</th>
                        <td>
                            <input type="number" name="avatar_size_limit" value="<?php echo esc_attr($s['avatar_size_limit']); ?>" min="512" max="10240" class="small-text"> KB
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">允許的檔案類型</th>
                        <td>
                            <?php
                            $allowed = $s['allowed_avatar_types'];
                            foreach (['jpg','jpeg','png','gif','webp'] as $ext): ?>
                                <label style="display:inline-block;margin-right:12px;">
                                    <input type="checkbox" name="allowed_avatar_types[]" value="<?php echo esc_attr($ext); ?>" <?php checked(in_array($ext, $allowed, true)); ?>>
                                    <?php echo strtoupper($ext); ?>
                                </label>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                </table>

                <?php submit_button('儲存設定'); ?>

            </form>

            <section class="wutm-csv-tools" aria-labelledby="wutm-csv-tools-title">
                <h2 id="wutm-csv-tools-title">CSV 使用者匯入／匯出</h2>
                <p>可匯出可再次匯入的使用者資料。匯出檔不包含密碼；匯入既有帳號時，預設不變更角色與密碼。</p>

                <div class="wutm-csv-actions">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('wutm_export_users_csv'); ?>
                        <input type="hidden" name="action" value="wutm_export_users_csv">
                        <label for="wutm-export-role">匯出角色</label>
                        <select id="wutm-export-role" name="role">
                            <option value="">所有角色</option>
                            <?php foreach (get_editable_roles() as $role_key => $role_data): ?>
                                <option value="<?php echo esc_attr($role_key); ?>"><?php echo esc_html(translate_user_role($role_data['name'])); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <label class="wutm-inline-choice"><input type="checkbox" name="include_meta" value="1" checked> 包含安全的自訂欄位</label>
                        <?php submit_button('下載 CSV', 'secondary', 'submit', false); ?>
                    </form>

                    <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('wutm_import_users_csv'); ?>
                        <input type="hidden" name="action" value="wutm_import_users_csv">
                        <label for="wutm-users-csv">匯入 CSV</label>
                        <input id="wutm-users-csv" type="file" name="users_csv" accept=".csv,text/csv" required>
                        <label class="wutm-inline-choice"><input type="checkbox" name="update_existing" value="1"> 更新既有帳號的基本資料</label>
                        <label class="wutm-inline-choice"><input type="checkbox" name="update_credentials" value="1"> 同時更新既有帳號的角色與密碼</label>
                        <label class="wutm-inline-choice"><input type="checkbox" name="import_meta" value="1" checked> 匯入自訂欄位</label>
                        <?php submit_button('開始匯入', 'primary', 'submit', false); ?>
                    </form>
                </div>

                <p class="description"><strong>必要欄位：</strong><code>user_login</code>、<code>user_email</code>。可選欄位：<code>user_pass</code>、<code>first_name</code>、<code>last_name</code>、<code>nickname</code>、<code>display_name</code>、<code>user_url</code>、<code>description</code>、<code>role</code>，以及安全的自訂欄位。支援逗號、分號或 Tab 分隔的 UTF-8 CSV。</p>
            </section>

            <hr>

            <h2>用戶統計資訊</h2>
            <div style="display:flex;gap:16px;margin:20px 0;flex-wrap:wrap;">
                <?php
                $stat_items = [
                    ['總用戶數',        $user_stats['total_users'],    '#0073aa'],
                    ['今日活躍',        $user_stats['active_today'],   '#46b450'],
                    ['本週活躍',        $user_stats['active_week'],    '#0073aa'],
                    ['本月活躍',        $user_stats['active_month'],   '#0073aa'],
                    ['非活躍（30天+）', $user_stats['inactive_users'], '#dc3232'],
                    ['從未登入',        $user_stats['never_logged_in'],'#ff8c00'],
                ];
                foreach ($stat_items as [$label, $val, $color]): ?>
                    <div style="background:#f9f9f9;padding:14px 18px;border-radius:6px;min-width:150px;border:1px solid #e5e5e5;">
                        <div style="font-size:.8em;color:#666;margin-bottom:4px;"><?php echo esc_html($label); ?></div>
                        <div style="font-size:1.8em;font-weight:700;color:<?php echo $color; ?>;"><?php echo number_format((int)$val); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <h2>最近註冊的用戶</h2>
            <?php $recent_users = $this->get_recent_users(); ?>
            <?php if (!empty($recent_users)): ?>
            <table class="wp-list-table widefat fixed striped" style="margin-top:10px;">
                <thead>
                    <tr>
                        <th>用戶名</th><th>顯示名稱</th><th>電子郵件</th>
                        <th>角色</th><th>註冊時間</th><th>上次登入</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_users as $user):
                        $last_login = get_user_meta($user->ID, 'wu_last_login', true);
                        ?>
                        <tr>
                            <td><strong><?php echo esc_html($user->user_login); ?></strong></td>
                            <td><?php echo esc_html($user->display_name); ?></td>
                            <td><?php echo esc_html($user->user_email); ?></td>
                            <td><?php echo esc_html(implode(', ', $user->roles)); ?></td>
                            <td><?php echo esc_html(wp_date($this->get_setting('date_format', 'Y-m-d H:i:s'), strtotime($user->user_registered))); ?></td>
                            <td><?php echo $last_login
                                ? esc_html(wp_date($this->get_setting('date_format', 'Y-m-d H:i:s'), (int)$last_login))
                                : '<span style="color:#ff8c00;">從未登入</span>'; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
                <p>最近沒有新用戶註冊</p>
            <?php endif; ?>
        </div>
        <?php
    }

    private function save_settings(): void {
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'wu_enhanced_user_list_settings')) {
            wp_die('安全驗證失敗');
        }
        if (!current_user_can('manage_options')) {
            wp_die('權限不足');
        }

        $raw_format  = $_POST['date_format'] ?? 'Y-m-d H:i:s';
        $date_format = in_array($raw_format, self::ALLOWED_DATE_FORMATS, true) ? $raw_format : 'Y-m-d H:i:s';

        $allowed_types_raw = isset($_POST['allowed_avatar_types']) ? (array) $_POST['allowed_avatar_types'] : [];
        $allowed_types     = array_values(array_intersect($allowed_types_raw, ['jpg','jpeg','png','gif','webp']));
        if (empty($allowed_types)) $allowed_types = ['jpg', 'jpeg', 'png', 'webp'];

        $settings = array_merge($this->get_default_settings(), [
            'enabled'                  => isset($_POST['enabled']),
            'show_last_login'          => isset($_POST['show_last_login']),
            'show_registration_date'   => isset($_POST['show_registration_date']),
            'show_user_id'             => isset($_POST['show_user_id']),
            'show_role_since'          => isset($_POST['show_role_since']),
            'date_format'              => $date_format,
            'show_filters'             => isset($_POST['show_filters']),
            'show_statistics'          => isset($_POST['show_statistics']),
            'highlight_inactive_users' => max(0, min(365, (int)($_POST['highlight_inactive_users'] ?? 30))),
            'hide_profile_options'     => isset($_POST['hide_profile_options']),
            'enable_user_export'       => isset($_POST['enable_user_export']),
            'include_meta'             => isset($_POST['include_meta']),
            'include_roles'            => isset($_POST['include_roles']),
            'enable_custom_avatar'     => isset($_POST['enable_custom_avatar']),
            'avatar_size_limit'        => max(512, min(10240, (int)($_POST['avatar_size_limit'] ?? 2048))),
            'allowed_avatar_types'     => $allowed_types,
        ]);

        update_option('wu_enhanced_user_list_settings', $settings);
        $this->settings = $settings;

        echo '<div class="notice notice-success is-dismissible"><p>✅ 設定已儲存！</p></div>';
    }

    // ===== 用戶列表欄位 =====

    public function add_user_columns(array $columns): array {
        if (!$this->get_setting('enabled')) return $columns;

        $new_columns = [];
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            if ($key === 'username' && $this->get_setting('show_user_id')) {
                $new_columns['wu_user_id'] = '用戶 ID';
            }
            if ($key === 'email' && $this->get_setting('show_registration_date', true)) {
                $new_columns['wu_registration_date'] = '註冊日期';
            }
        }
        if ($this->get_setting('show_last_login', true)) $new_columns['wu_last_login'] = '上次登入';
        if ($this->get_setting('show_role_since'))       $new_columns['wu_role_since']  = '角色指派時間';

        return $new_columns;
    }

    public function show_user_column_content(string $value, string $column_name, int $user_id): string {
        if (!$this->get_setting('enabled')) return $value;

        $fmt = $this->get_setting('date_format', 'Y-m-d H:i:s');

        switch ($column_name) {
            case 'wu_user_id':
                return (string) $user_id;

            case 'wu_registration_date':
                $user = get_user_by('ID', $user_id);
                return $user ? esc_html(wp_date($fmt, strtotime($user->user_registered))) : '';

            case 'wu_last_login':
                $last_login     = (int) get_user_meta($user_id, 'wu_last_login', true);
                $highlight_days = (int) $this->get_setting('highlight_inactive_users', 30);
                if (!$last_login) {
                    return '<span style="color:#ff8c00;">從未登入</span>';
                }
                $login_text = esc_html(wp_date($fmt, $last_login));
                $days_ago   = (int) floor((time() - $last_login) / DAY_IN_SECONDS);
                $inactive   = ($highlight_days > 0 && $days_ago > $highlight_days);
                $style      = $inactive ? 'color:#dc3232;' : '';
                return '<span style="' . $style . '" data-inactive="' . ($inactive ? '1' : '0') . '">'
                     . $login_text . ' <small>(' . $days_ago . ' 天前)</small></span>';

            case 'wu_role_since':
                $role_since = (int) get_user_meta($user_id, 'wu_role_assigned_date', true);
                if ($role_since) return esc_html(wp_date($fmt, $role_since));
                $user = get_user_by('ID', $user_id);
                return $user ? esc_html(wp_date($fmt, strtotime($user->user_registered))) : '';
        }

        return $value;
    }

    public function make_columns_sortable(array $columns): array {
        if (!$this->get_setting('enabled')) return $columns;
        return array_merge($columns, [
            'wu_user_id'           => 'wu_user_id',
            'wu_registration_date' => 'wu_registration_date',
            'wu_last_login'        => 'wu_last_login',
            'wu_role_since'        => 'wu_role_since',
        ]);
    }

    public function handle_column_sorting(WP_User_Query $user_query): void {
        if (!is_admin() || !$this->get_setting('enabled')) return;
        $screen = get_current_screen();
        if (!$screen || $screen->base !== 'users') return;

        $orderby = $user_query->get('orderby');
        $order   = strtoupper($user_query->get('order') ?: 'ASC');

        switch ($orderby) {
            case 'wu_user_id':
                $user_query->set('orderby', 'ID');
                $user_query->set('order', $order);
                break;
            case 'wu_registration_date':
                $user_query->set('orderby', 'user_registered');
                $user_query->set('order', $order);
                break;
            case 'wu_last_login':
                $user_query->set('meta_key', 'wu_last_login');
                $user_query->set('orderby', 'meta_value_num');
                $user_query->set('order', $order);
                break;
            case 'wu_role_since':
                $user_query->set('meta_key', 'wu_role_assigned_date');
                $user_query->set('orderby', 'meta_value_num');
                $user_query->set('order', $order);
                break;
        }
    }

    public function record_user_last_login(string $user_login, WP_User $user): void {
        update_user_meta($user->ID, 'wu_last_login', time());
    }

    // ===== 篩選器 =====

    public function add_user_filters(): void {
        if (!$this->get_setting('enabled') || !$this->get_setting('show_filters', true)) return;
        $screen = get_current_screen();
        if (!$screen || $screen->base !== 'users') return;

        $login_filter = $_GET['wu_login_filter'] ?? '';
        $reg_filter   = $_GET['wu_registration_filter'] ?? '';
        ?>
        <select name="wu_login_filter">
            <option value="">所有登入狀態</option>
            <?php foreach ([
                'never_logged_in' => '從未登入',
                'logged_in_today' => '今日登入',
                'logged_in_week'  => '本週登入',
                'logged_in_month' => '本月登入',
                'inactive_30'     => '30天未登入',
                'inactive_90'     => '90天未登入',
            ] as $val => $label): ?>
                <option value="<?php echo esc_attr($val); ?>" <?php selected($login_filter, $val); ?>><?php echo esc_html($label); ?></option>
            <?php endforeach; ?>
        </select>

        <select name="wu_registration_filter">
            <option value="">所有註冊時間</option>
            <?php foreach ([
                'registered_today' => '今日註冊',
                'registered_week'  => '本週註冊',
                'registered_month' => '本月註冊',
                'registered_year'  => '本年註冊',
            ] as $val => $label): ?>
                <option value="<?php echo esc_attr($val); ?>" <?php selected($reg_filter, $val); ?>><?php echo esc_html($label); ?></option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    public function filter_users_by_login_date(WP_User_Query $user_query): void {
        if (!is_admin() || !$this->get_setting('enabled')) return;
        $screen = get_current_screen();
        if (!$screen || $screen->base !== 'users') return;

        $login_filter = $_GET['wu_login_filter'] ?? '';
        $reg_filter   = $_GET['wu_registration_filter'] ?? '';

        if ($login_filter) {
            $meta_query = [];
            switch ($login_filter) {
                case 'never_logged_in':
                    $meta_query = [['key' => 'wu_last_login', 'compare' => 'NOT EXISTS']];
                    break;
                case 'logged_in_today':
                    $meta_query = [['key' => 'wu_last_login', 'value' => strtotime('today'), 'compare' => '>=', 'type' => 'NUMERIC']];
                    break;
                case 'logged_in_week':
                    $meta_query = [['key' => 'wu_last_login', 'value' => strtotime('-1 week'), 'compare' => '>=', 'type' => 'NUMERIC']];
                    break;
                case 'logged_in_month':
                    $meta_query = [['key' => 'wu_last_login', 'value' => strtotime('-1 month'), 'compare' => '>=', 'type' => 'NUMERIC']];
                    break;
                case 'inactive_30':
                    $meta_query = [['key' => 'wu_last_login', 'value' => strtotime('-30 days'), 'compare' => '<', 'type' => 'NUMERIC']];
                    break;
                case 'inactive_90':
                    $meta_query = [['key' => 'wu_last_login', 'value' => strtotime('-90 days'), 'compare' => '<', 'type' => 'NUMERIC']];
                    break;
            }
            if ($meta_query) $user_query->set('meta_query', $meta_query);
        }

        if ($reg_filter) {
            $after_map = [
                'registered_today' => 'today',
                'registered_week'  => '1 week ago',
                'registered_month' => '1 month ago',
                'registered_year'  => '1 year ago',
            ];
            if (isset($after_map[$reg_filter])) {
                $user_query->set('date_query', [['after' => $after_map[$reg_filter], 'column' => 'user_registered']]);
            }
        }
    }

    // ===== 用戶資料頁 =====

    public function show_additional_user_info(WP_User $user): void {
        if (!$this->get_setting('enabled')) return;

        $fmt        = $this->get_setting('date_format', 'Y-m-d H:i:s');
        $last_login = (int) get_user_meta($user->ID, 'wu_last_login', true);
        $role_since = (int) get_user_meta($user->ID, 'wu_role_assigned_date', true);
        ?>
        <h3>用戶活動資訊</h3>
        <table class="form-table" role="presentation">
            <tr>
                <th><label>用戶 ID</label></th>
                <td><?php echo (int) $user->ID; ?></td>
            </tr>
            <tr>
                <th><label>註冊日期</label></th>
                <td><?php echo esc_html(wp_date($fmt, strtotime($user->user_registered))); ?></td>
            </tr>
            <tr>
                <th><label>上次登入</label></th>
                <td>
                    <?php if ($last_login): ?>
                        <?php echo esc_html(wp_date($fmt, $last_login)); ?>
                        <small>（<?php echo (int) floor((time() - $last_login) / DAY_IN_SECONDS); ?> 天前）</small>
                    <?php else: ?>
                        <span style="color:#ff8c00;">從未登入</span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th><label>角色指派時間</label></th>
                <td>
                    <?php echo $role_since
                        ? esc_html(wp_date($fmt, $role_since))
                        : '未記錄（可能為註冊時指派）'; ?>
                </td>
            </tr>
        </table>
        <?php
    }

    // ===== 統計資訊 =====

    public function show_user_statistics(): void {
        if (!$this->get_setting('enabled') || !$this->get_setting('show_statistics', true)) return;
        $screen = get_current_screen();
        if (!$screen || $screen->base !== 'users') return;

        $stats = $this->get_user_statistics();
        ?>
        <div class="notice notice-info" style="padding:10px;">
            <strong>用戶統計：</strong>
            總數：<?php echo number_format((int)$stats['total_users']); ?> |
            今日活躍：<?php echo number_format((int)$stats['active_today']); ?> |
            本週活躍：<?php echo number_format((int)$stats['active_week']); ?> |
            本月活躍：<?php echo number_format((int)$stats['active_month']); ?> |
            非活躍（30天+）：<?php echo number_format((int)$stats['inactive_users']); ?> |
            從未登入：<?php echo number_format((int)$stats['never_logged_in']); ?>
        </div>

        <?php if ((int)$this->get_setting('highlight_inactive_users', 30) > 0): ?>
        <style>
        .users-php .wp-list-table tbody tr { background:#fff; }
        .users-php .wp-list-table tbody tr.wu-inactive-user { background:#fff8e1; }
        </style>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.wp-list-table tbody tr').forEach(function(row) {
                if (row.querySelector('[data-inactive="1"]')) {
                    row.classList.add('wu-inactive-user');
                }
            });
        });
        </script>
        <?php endif; ?>
        <?php
    }

    // ===== DB 統計查詢 =====

    private function get_user_statistics(): array {
        $cache_key = 'wu_user_statistics_' . get_current_blog_id();
        $cached    = wp_cache_get($cache_key);
        if ($cached !== false) return $cached;

        $user_count = count_users();
        $stats = [
            'total_users'     => $user_count['total_users'],
            'active_today'    => $this->count_users_by_login_date(strtotime('today')),
            'active_week'     => $this->count_users_by_login_date(strtotime('-1 week')),
            'active_month'    => $this->count_users_by_login_date(strtotime('-1 month')),
            'inactive_users'  => $this->count_inactive_users(30),
            'never_logged_in' => $this->count_never_logged_in_users($user_count['total_users']),
        ];

        wp_cache_set($cache_key, $stats, '', 300);
        return $stats;
    }

    private function count_users_by_login_date(int $since): int {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key='wu_last_login' AND meta_value >= %d",
            $since
        ));
    }

    private function count_inactive_users(int $days): int {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key='wu_last_login' AND meta_value < %d",
            strtotime("-{$days} days")
        ));
    }

    private function count_never_logged_in_users(int $total): int {
        global $wpdb;
        $logged_in = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key='wu_last_login'"
        );
        return max(0, $total - $logged_in);
    }

    private function get_recent_users(int $limit = 10): array {
        return get_users(['orderby' => 'user_registered', 'order' => 'DESC', 'number' => $limit]);
    }

    // ===== 用戶匯出 =====

    public function add_export_link(array $actions, WP_User $user_object): array {
        if (current_user_can('manage_options')) {
            $nonce = wp_create_nonce('wu_export_user_' . $user_object->ID);
            $url   = admin_url('admin.php?action=wu_export_user&user_id=' . $user_object->ID . '&_wpnonce=' . $nonce);
            $actions['wu_export'] = '<a href="' . esc_url($url) . '">下載 CSV</a>';
        }
        return $actions;
    }

    public function add_bulk_export_action(array $bulk_actions): array {
        $bulk_actions['wu_export'] = '下載 CSV';
        return $bulk_actions;
    }

    public function handle_bulk_export(string $redirect_to, string $doaction, array $user_ids): string {
        if ($doaction !== 'wu_export' || !current_user_can('manage_options')) return $redirect_to;
        $this->export_users($user_ids);
        exit;
    }

    public function export_single_user(): void {
        $user_id = (int) ($_GET['user_id'] ?? 0);
        if (!$user_id || !wp_verify_nonce($_GET['_wpnonce'] ?? '', 'wu_export_user_' . $user_id)) {
            wp_die('安全驗證失敗');
        }
        if (!current_user_can('manage_options')) wp_die('權限不足');
        $this->export_users([$user_id]);
        exit;
    }

    private function get_all_user_fields(): array {
        global $wpdb;
        static $fields = null;
        if ($fields !== null) return $fields;
        $results = $wpdb->get_results("DESCRIBE {$wpdb->users}", ARRAY_A);
        $fields  = array_column($results, 'Field');
        return $fields;
    }

    private function get_all_user_meta_fields(): array {
        global $wpdb;
        static $meta_keys = null;
        if ($meta_keys !== null) return $meta_keys;
        $meta_keys = $wpdb->get_col(
            "SELECT DISTINCT meta_key FROM {$wpdb->usermeta}
             WHERE meta_key NOT LIKE '\\_%'
             ORDER BY meta_key LIMIT 20"
        );
        return $meta_keys;
    }

    private function export_users(array $user_ids): void {
        $filename = 'users_export_' . wp_date('Y-m-d_H-i-s') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output      = fopen('php://output', 'w');
        $user_fields = $this->get_all_user_fields();
        $meta_fields = $this->get_setting('include_meta', true) ? $this->get_all_user_meta_fields() : [];
        $fmt         = $this->get_setting('date_format', 'Y-m-d H:i:s');

        fputs($output, "\xEF\xBB\xBF");

        $headers = array_map(fn($f) => ucfirst(str_replace('_', ' ', $f)), $user_fields);
        if ($this->get_setting('include_roles', true)) $headers[] = 'Roles';
        foreach ($meta_fields as $mf) $headers[] = ucfirst(str_replace('_', ' ', $mf));
        fputcsv($output, $headers);

        foreach ($user_ids as $user_id) {
            $user = get_userdata((int)$user_id);
            if (!$user) continue;

            $row = [];
            foreach ($user_fields as $field) {
                $val = $user->$field ?? '';
                if ($field === 'user_registered') $val = wp_date($fmt, strtotime($val));
                $row[] = $val;
            }
            if ($this->get_setting('include_roles', true)) $row[] = implode(', ', $user->roles);
            foreach ($meta_fields as $mf) {
                $mv    = get_user_meta((int)$user_id, $mf, true);
                $row[] = is_array($mv) ? implode(', ', $mv) : $mv;
            }
            fputcsv($output, $row);
        }

        fclose($output);
    }


    // ===== CSV 使用者匯入／匯出 =====

    public function export_users_csv(): void {
        if (!current_user_can('manage_options')) wp_die('權限不足');
        check_admin_referer('wutm_export_users_csv');

        $role = sanitize_key(wp_unslash($_POST['role'] ?? ''));
        $args = [
            'fields'  => 'ID',
            'number'  => -1,
            'orderby' => 'ID',
            'order'   => 'ASC',
        ];
        if ($role && array_key_exists($role, get_editable_roles())) $args['role'] = $role;

        $user_ids = get_users($args);
        $meta_keys = !empty($_POST['include_meta']) ? $this->get_safe_export_meta_keys() : [];
        $this->stream_users_csv($user_ids, $meta_keys);
        exit;
    }

    private function get_safe_export_meta_keys(): array {
        global $wpdb;

        $blocked = $this->protected_user_meta_keys();
        $placeholders = implode(',', array_fill(0, count($blocked), '%s'));
        $query = "SELECT DISTINCT meta_key FROM {$wpdb->usermeta}
            WHERE meta_key NOT LIKE '\\_%' AND meta_key NOT IN ($placeholders)
            ORDER BY meta_key ASC LIMIT 50";
        $keys = $wpdb->get_col($wpdb->prepare($query, ...$blocked));

        return array_values(array_filter(array_map('sanitize_key', (array) $keys)));
    }

    private function stream_users_csv(array $user_ids, array $meta_keys = []): void {
        $filename = 'wu-users-' . wp_date('Y-m-d-His') . '.csv';
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $output = fopen('php://output', 'w');
        fputs($output, "\xEF\xBB\xBF");
        $headers = array_merge(
            ['user_login', 'user_email', 'first_name', 'last_name', 'nickname', 'display_name', 'user_url', 'description', 'role', 'user_registered'],
            $meta_keys
        );
        fputcsv($output, $headers);

        foreach ($user_ids as $user_id) {
            $user = get_userdata((int) $user_id);
            if (!$user) continue;

            $row = [
                $user->user_login,
                $user->user_email,
                $user->first_name,
                $user->last_name,
                $user->nickname,
                $user->display_name,
                $user->user_url,
                $user->description,
                implode(',', (array) $user->roles),
                $user->user_registered,
            ];
            foreach ($meta_keys as $meta_key) {
                $value = get_user_meta($user->ID, $meta_key, true);
                $row[] = is_scalar($value) ? (string) $value : wp_json_encode($value, JSON_UNESCAPED_UNICODE);
            }
            fputcsv($output, $row);
        }
        fclose($output);
    }

    public function import_users_csv(): void {
        if (!current_user_can('manage_options')) wp_die('權限不足');
        check_admin_referer('wutm_import_users_csv');

        $result = ['message' => '', 'details' => [], 'error' => false];
        $file = $_FILES['users_csv'] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($file['tmp_name'])) {
            $this->save_csv_import_result(['message' => '找不到可匯入的 CSV 檔案。', 'details' => [], 'error' => true]);
            $this->redirect_to_settings();
        }
        if ((int) ($file['size'] ?? 0) > 8 * MB_IN_BYTES || !is_uploaded_file($file['tmp_name'])) {
            $this->save_csv_import_result(['message' => 'CSV 檔案無效或超過 8 MB 限制。', 'details' => [], 'error' => true]);
            $this->redirect_to_settings();
        }

        $rows = $this->read_users_csv((string) $file['tmp_name']);
        if (is_wp_error($rows)) {
            $this->save_csv_import_result(['message' => $rows->get_error_message(), 'details' => [], 'error' => true]);
            $this->redirect_to_settings();
        }
        if (count($rows) < 2) {
            $this->save_csv_import_result(['message' => 'CSV 必須包含標題列與至少一筆資料。', 'details' => [], 'error' => true]);
            $this->redirect_to_settings();
        }
        if (count($rows) > 501) {
            $this->save_csv_import_result(['message' => '一次最多匯入 500 筆資料，請分批處理。', 'details' => [], 'error' => true]);
            $this->redirect_to_settings();
        }

        $headers = $this->normalise_csv_headers(array_shift($rows));
        if (!in_array('user_login', $headers, true) || !in_array('user_email', $headers, true)) {
            $this->save_csv_import_result(['message' => 'CSV 缺少必要欄位 user_login 或 user_email。', 'details' => [], 'error' => true]);
            $this->redirect_to_settings();
        }

        $options = [
            'update_existing'    => !empty($_POST['update_existing']),
            'update_credentials' => !empty($_POST['update_credentials']),
            'import_meta'        => !empty($_POST['import_meta']),
        ];
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $details = [];

        foreach ($rows as $index => $row) {
            if (!array_filter($row, static fn($value) => trim((string) $value) !== '')) continue;
            $data = [];
            foreach ($headers as $column => $header) {
                if ($header !== '' && array_key_exists($column, $row)) $data[$header] = trim((string) $row[$column]);
            }
            $row_result = $this->import_one_user($data, $options);
            if ($row_result['status'] === 'created') $created++;
            elseif ($row_result['status'] === 'updated') $updated++;
            else $skipped++;

            if ($row_result['status'] === 'skipped' && count($details) < 10) {
                $details[] = '第 ' . ($index + 2) . ' 列：' . $row_result['message'];
            }
        }

        $result['message'] = sprintf('CSV 匯入完成：新增 %d 位、更新 %d 位、略過 %d 位使用者。', $created, $updated, $skipped);
        $result['details'] = $details;
        $this->save_csv_import_result($result);
        $this->redirect_to_settings();
    }

    private function import_one_user(array $data, array $options): array {
        $login = sanitize_user($data['user_login'] ?? '', true);
        $email = sanitize_email($data['user_email'] ?? '');
        $existing = $login ? get_user_by('login', $login) : false;
        if (!$existing && $email && is_email($email)) $existing = get_user_by('email', $email);

        if ($existing) {
            if (empty($options['update_existing'])) {
                return ['status' => 'skipped', 'message' => sprintf('使用者「%s」已存在，未勾選更新既有帳號。', $existing->user_login)];
            }

            $update = ['ID' => $existing->ID];
            if ($email && is_email($email)) {
                $email_owner = get_user_by('email', $email);
                if (!$email_owner || (int) $email_owner->ID === (int) $existing->ID) $update['user_email'] = $email;
            }
            foreach (['first_name', 'last_name', 'nickname', 'display_name', 'user_url', 'description'] as $field) {
                if (array_key_exists($field, $data)) {
                    $update[$field] = $field === 'user_url' ? esc_url_raw($data[$field]) : sanitize_text_field($data[$field]);
                }
            }
            if (!empty($options['update_credentials']) && !empty($data['user_pass'])) $update['user_pass'] = $data['user_pass'];

            $updated_id = wp_update_user($update);
            if (is_wp_error($updated_id)) return ['status' => 'skipped', 'message' => $updated_id->get_error_message()];

            if (!empty($options['update_credentials'])) $this->assign_import_role((int) $existing->ID, $data['role'] ?? '');
            if (!empty($options['import_meta'])) $this->import_custom_user_meta((int) $existing->ID, $data);
            return ['status' => 'updated', 'message' => ''];
        }

        if (!$login || !validate_username($login)) return ['status' => 'skipped', 'message' => '使用者名稱不正確。'];
        if (!$email || !is_email($email)) return ['status' => 'skipped', 'message' => '電子郵件地址不正確。'];
        if (username_exists($login) || email_exists($email)) return ['status' => 'skipped', 'message' => '使用者名稱或電子郵件已存在。'];

        $insert = [
            'user_login'   => $login,
            'user_email'   => $email,
            'user_pass'    => !empty($data['user_pass']) ? $data['user_pass'] : wp_generate_password(24, true, true),
            'first_name'   => sanitize_text_field($data['first_name'] ?? ''),
            'last_name'    => sanitize_text_field($data['last_name'] ?? ''),
            'nickname'     => sanitize_text_field($data['nickname'] ?? ''),
            'display_name' => sanitize_text_field($data['display_name'] ?? ''),
            'user_url'     => esc_url_raw($data['user_url'] ?? ''),
            'description'  => sanitize_textarea_field($data['description'] ?? ''),
        ];
        $user_id = wp_insert_user($insert);
        if (is_wp_error($user_id)) return ['status' => 'skipped', 'message' => $user_id->get_error_message()];

        $this->assign_import_role((int) $user_id, $data['role'] ?? '');
        if (!empty($options['import_meta'])) $this->import_custom_user_meta((int) $user_id, $data);
        return ['status' => 'created', 'message' => ''];
    }

    private function assign_import_role(int $user_id, string $role_value): void {
        $roles = get_editable_roles();
        $role = sanitize_key(trim(explode(',', $role_value)[0] ?? ''));
        if ($role && isset($roles[$role])) {
            $user = new WP_User($user_id);
            $user->set_role($role);
        }
    }

    private function import_custom_user_meta(int $user_id, array $data): void {
        $known = ['user_login', 'user_email', 'user_pass', 'first_name', 'last_name', 'nickname', 'display_name', 'user_url', 'description', 'role', 'user_registered', 'id'];
        foreach ($data as $key => $value) {
            $meta_key = sanitize_key($key);
            if (!$meta_key || in_array($meta_key, $known, true) || in_array($meta_key, $this->protected_user_meta_keys(), true) || str_starts_with($meta_key, '_')) continue;
            update_user_meta($user_id, $meta_key, sanitize_textarea_field($value));
        }
    }

    private function protected_user_meta_keys(): array {
        return ['wp_capabilities', 'wp_user_level', 'capabilities', 'user_level', 'session_tokens', 'application_passwords', 'dismissed_wp_pointers', 'wp_dashboard_quick_press_last_post_id'];
    }

    private function read_users_csv(string $path) {
        $contents = file_get_contents($path);
        if ($contents === false) return new WP_Error('wutm_csv_read', '無法讀取 CSV 檔案。');
        if (str_starts_with($contents, "\xEF\xBB\xBF")) $contents = substr($contents, 3);

        if (str_starts_with($contents, "\xFF\xFE") || str_starts_with($contents, "\xFE\xFF")) {
            if (!function_exists('mb_convert_encoding')) return new WP_Error('wutm_csv_encoding', '請將 UTF-16 CSV 另存為 UTF-8 後再匯入。');
            $encoding = str_starts_with($contents, "\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE';
            $contents = mb_convert_encoding(substr($contents, 2), 'UTF-8', $encoding);
        }

        $first_line = strtok($contents, "\r\n");
        $delimiters = [',' => substr_count($first_line ?: '', ','), ';' => substr_count($first_line ?: '', ';'), "\t" => substr_count($first_line ?: '', "\t")];
        arsort($delimiters);
        $delimiter = (string) array_key_first($delimiters);

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $contents);
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, 0, $delimiter)) !== false) {
            if ($row !== [null]) $rows[] = $row;
        }
        fclose($stream);
        return $rows;
    }

    private function normalise_csv_headers(array $headers): array {
        $aliases = [
            'username' => 'user_login', 'login' => 'user_login',
            'email' => 'user_email', 'email_address' => 'user_email',
            'password' => 'user_pass', 'website' => 'user_url', 'url' => 'user_url',
            'biography' => 'description', 'roles' => 'role', 'user_role' => 'role',
            'user_id' => 'id',
        ];
        $normalised = [];
        foreach ($headers as $header) {
            $key = strtolower(trim((string) $header));
            $key = preg_replace('/\s+/', '_', $key);
            $key = preg_replace('/[^a-z0-9_-]/', '', $key);
            $normalised[] = $aliases[$key] ?? $key;
        }
        return $normalised;
    }

    private function save_csv_import_result(array $result): void {
        set_transient('wutm_user_import_result_' . get_current_user_id(), $result, 5 * MINUTE_IN_SECONDS);
    }

    private function redirect_to_settings(): void {
        wp_safe_redirect(admin_url('admin.php?page=wu-enhanced-user-list'));
        exit;
    }

    // ===== 自訂頭像 =====

    public function show_custom_avatar_field(WP_User $user): void {
        $custom_avatar = get_user_meta($user->ID, 'wu_custom_avatar', true);
        $avatar_src    = $custom_avatar ?: get_avatar_url($user->ID, ['size' => 96]);
        $size_limit    = (int) $this->get_setting('avatar_size_limit', 2048);
        $allowed_types = (array) $this->get_setting('allowed_avatar_types', ['jpg','jpeg','png','gif','webp']);
        ?>
        <h3>自訂頭像</h3>
        <table class="form-table" role="presentation">
            <tr>
                <th><label for="wu_custom_avatar">選擇頭像</label></th>
                <td>
                    <div id="wu-avatar-preview" style="margin-bottom:10px;">
                        <img src="<?php echo esc_url($avatar_src); ?>" alt="頭像預覽"
                             style="width:96px;height:96px;border-radius:50%;object-fit:cover;border:2px solid #ddd;">
                    </div>
                    <input type="hidden" id="wu_custom_avatar" name="wu_custom_avatar" value="<?php echo esc_attr((string)$custom_avatar); ?>">
                    <button type="button" class="button" id="wu-select-avatar">選擇圖片</button>
                    <button type="button" class="button" id="wu-remove-avatar" <?php echo $custom_avatar ? '' : 'style="display:none;"'; ?>>移除自訂頭像</button>
                    <p class="description">
                        建議尺寸：至少 96×96 px，大小不超過 <?php echo $size_limit; ?> KB<br>
                        支援格式：<?php echo esc_html(implode(', ', array_map('strtoupper', $allowed_types))); ?>
                    </p>
                </td>
            </tr>
        </table>
        <script>
        jQuery(document).ready(function($) {
            var frame;
            var sizeLimitBytes = <?php echo $size_limit * 1024; ?>;
            var allowedTypes   = <?php echo wp_json_encode($allowed_types); ?>;
            var defaultSrc     = <?php echo wp_json_encode(get_avatar_url($user->ID, ['size' => 96])); ?>;

            $('#wu-select-avatar').on('click', function(e) {
                e.preventDefault();
                if (frame) { frame.open(); return; }

                frame = wp.media({
                    title: '選擇頭像',
                    button: { text: '使用此圖片' },
                    library: { type: ['image'] },
                    multiple: false
                });

                frame.on('select', function() {
                    var att = frame.state().get('selection').first().toJSON();

                    if (att.filesizeInBytes > sizeLimitBytes) {
                        alert('檔案太大！請選擇小於 ' + <?php echo $size_limit; ?> + ' KB 的圖片。');
                        return;
                    }
                    var ext = att.filename.split('.').pop().toLowerCase();
                    if (allowedTypes.indexOf(ext) === -1) {
                        alert('不支援的格式！請選擇 ' + allowedTypes.join(', ').toUpperCase() + ' 格式。');
                        return;
                    }

                    $('#wu_custom_avatar').val(att.url);
                    $('#wu-avatar-preview img').attr('src', att.url);
                    $('#wu-remove-avatar').show();
                });

                frame.open();
            });

            $('#wu-remove-avatar').on('click', function(e) {
                e.preventDefault();
                if (!confirm('確定要移除自訂頭像嗎？')) return;
                $('#wu_custom_avatar').val('');
                $('#wu-avatar-preview img').attr('src', defaultSrc);
                $(this).hide();
            });
        });
        </script>
        <?php
    }

    public function save_custom_avatar(int $user_id): void {
        if (!current_user_can('edit_user', $user_id)) return;
        $custom_avatar = isset($_POST['wu_custom_avatar']) ? esc_url_raw($_POST['wu_custom_avatar']) : '';
        if ($custom_avatar) {
            update_user_meta($user_id, 'wu_custom_avatar', $custom_avatar);
        } else {
            delete_user_meta($user_id, 'wu_custom_avatar');
        }
    }

    public function enqueue_media_assets(string $hook): void {
        if (!in_array($hook, ['profile.php', 'user-edit.php'], true)) return;
        wp_enqueue_media();
    }

    private function resolve_user_from_id_or_email($id_or_email): ?WP_User {
        if (is_numeric($id_or_email)) {
            return get_user_by('id', (int)$id_or_email) ?: null;
        }
        if ($id_or_email instanceof WP_User) {
            return $id_or_email;
        }
        if ($id_or_email instanceof WP_Comment) {
            return $id_or_email->user_id ? get_user_by('id', (int)$id_or_email->user_id) ?: null : null;
        }
        if (is_object($id_or_email) && isset($id_or_email->user_id)) {
            return get_user_by('id', (int)$id_or_email->user_id) ?: null;
        }
        if (is_string($id_or_email) && is_email($id_or_email)) {
            return get_user_by('email', $id_or_email) ?: null;
        }
        return null;
    }

    public function custom_avatar(string $avatar, $id_or_email, int $size, string $default, string $alt): string {
        $user = $this->resolve_user_from_id_or_email($id_or_email);
        if ($user) {
            $custom = get_user_meta($user->ID, 'wu_custom_avatar', true);
            if ($custom) {
                return sprintf(
                    '<img alt="%s" src="%s" class="avatar avatar-%d photo" height="%d" width="%d" loading="lazy" />',
                    esc_attr($alt), esc_url($custom), $size, $size, $size
                );
            }
        }
        return $avatar;
    }

    public function custom_avatar_url(string $url, $id_or_email, array $args): string {
        $user = $this->resolve_user_from_id_or_email($id_or_email);
        if ($user) {
            $custom = get_user_meta($user->ID, 'wu_custom_avatar', true);
            if ($custom) return $custom;
        }
        return $url;
    }

    // ===== 隱藏用戶設定選項 =====

    public function hide_user_profile_options(): void {
        global $pagenow;
        if (!in_array($pagenow, ['profile.php', 'user-edit.php'], true)) return;
        ?>
        <style>
        .user-admin-color-wrap, .user-syntax-highlighting-wrap,
        .user-comment-shortcuts-wrap, .user-admin-bar-front-wrap,
        .user-locale-wrap, .user-language-wrap,
        .user-description-wrap,
        .application-passwords, .application-passwords-section,
        .user-url-wrap, .user-facebook-wrap, .user-twitter-wrap,
        .user-linkedin-wrap, .user-mastodon-wrap, .user-tiktok-wrap,
        .user-instagram-wrap, .user-youtube-wrap, .user-github-wrap,
        .user-pinterest-wrap, .user-dribbble-wrap,
        .elementor-ai-wrap, .elementor-ai-settings { display:none !important; }
        </style>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var keywords = [
                'personal options','about the user','application passwords',
                'elementor','個人選項','關於使用者','應用程式密碼',
                '使用者自我介紹','biographical','biography'
            ];
            document.querySelectorAll('h2, h3').forEach(function(el) {
                var text = el.textContent.trim().toLowerCase();
                if (keywords.some(function(k){ return text.includes(k); })) {
                    var next = el.nextElementSibling;
                    while (next && !['H2','H3'].includes(next.tagName)) {
                        next.style.display = 'none';
                        next = next.nextElementSibling;
                    }
                    el.style.display = 'none';
                }
            });
        });
        </script>
        <?php
    }
}

// 初始化
new WU_Enhanced_User_List();
