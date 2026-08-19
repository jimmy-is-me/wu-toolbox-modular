<?php
/**
 * Module: plugin-downloader
 * Loaded only when enabled in WU Toolbox Modular.
 */

if (!defined('ABSPATH')) exit;

// ===== 子選單註冊 =====
// ✅ 修正：slug 改為 plugin-downloader，不與父選單衝突
add_action('admin_menu', function() {
    add_submenu_page(
        'wu-toolbox-modular',
        '常用外掛管理',
        '常用外掛管理',
        'manage_options',
        'wu-plugin-downloader',
        'plugin_manager_settings_page'
    );
}, 5);

// ===== 常用外掛清單 =====
function get_popular_plugins_list() {
    return [
        // 內容管理
        'advanced-custom-fields' => [
            'name'        => 'Advanced Custom Fields',
            'description' => '指定自訂文章類型所需欄位，搭配 CPT 建立完整後台架構。',
            'slug'        => 'advanced-custom-fields',
            'wp_url'      => 'https://wordpress.org/plugins/advanced-custom-fields/',
            'category'    => '內容管理',
        ],
        'classic-editor' => [
            'name'        => 'Classic Editor',
            'description' => '恢復經典編輯器介面，適合習慣舊版編輯器的用戶。',
            'slug'        => 'classic-editor',
            'wp_url'      => 'https://wordpress.org/plugins/classic-editor/',
            'category'    => '內容管理',
        ],
        'classic-widgets' => [
            'name'        => 'Classic Widgets',
            'description' => '恢復經典小工具介面，移除區塊編輯器的小工具功能。',
            'slug'        => 'classic-widgets',
            'wp_url'      => 'https://wordpress.org/plugins/classic-widgets/',
            'category'    => '內容管理',
        ],
        'tinymce-advanced' => [
            'name'        => 'Advanced Editor Tools',
            'description' => '增強經典編輯器功能，提供更多編輯選項和工具列。',
            'slug'        => 'tinymce-advanced',
            'wp_url'      => 'https://wordpress.org/plugins/tinymce-advanced/',
            'category'    => '內容管理',
        ],
        // 用戶管理
        'user-role-editor' => [
            'name'        => 'User Role Editor',
            'description' => '從最基礎的權限（Capabilities）出發，可配置使用者角色與權限。',
            'slug'        => 'user-role-editor',
            'wp_url'      => 'https://wordpress.org/plugins/user-role-editor/',
            'category'    => '用戶管理',
        ],
        'heateor-social-login' => [
            'name'        => 'Heateor Social Login WordPress',
            'description' => '提供社群媒體登入功能，支援多種社群平台。',
            'slug'        => 'heateor-social-login',
            'wp_url'      => 'https://wordpress.org/plugins/heateor-social-login/',
            'category'    => '用戶管理',
        ],
        'nextend-social-login' => [
            'name'        => 'Nextend Social Login',
            'description' => '專業的社群登入外掛，支援多種社群平台登入。',
            'slug'        => 'nextend-social-login',
            'wp_url'      => 'https://wordpress.org/plugins/nextend-social-login/',
            'category'    => '用戶管理',
        ],
        // 效能優化
        'wp-super-cache' => [
            'name'        => 'WP Super Cache',
            'description' => '提升網站速度的快取外掛，減少伺服器負載。',
            'slug'        => 'wp-super-cache',
            'wp_url'      => 'https://wordpress.org/plugins/wp-super-cache/',
            'category'    => '效能優化',
        ],
        'auto-upload-images' => [
            'name'        => 'Auto Upload Images',
            'description' => '自動下載外部圖片到本地媒體庫，提升載入速度。',
            'slug'        => 'auto-upload-images',
            'wp_url'      => 'https://wordpress.org/plugins/auto-upload-images/',
            'category'    => '效能優化',
        ],
        // 郵件管理
        'fluent-smtp' => [
            'name'        => 'FluentSMTP',
            'description' => '專業的 SMTP 郵件發送外掛，確保郵件送達率。',
            'slug'        => 'fluent-smtp',
            'wp_url'      => 'https://wordpress.org/plugins/fluent-smtp/',
            'category'    => '郵件管理',
        ],
        // SEO 優化
        'seo-by-rank-math' => [
            'name'        => 'Rank Math SEO',
            'description' => '全方位 SEO 優化外掛，提升網站搜尋引擎排名。',
            'slug'        => 'seo-by-rank-math',
            'wp_url'      => 'https://wordpress.org/plugins/seo-by-rank-math/',
            'category'    => 'SEO 優化',
        ],
        'breadcrumb-navxt' => [
            'name'        => 'Breadcrumb NavXT',
            'description' => '建立網站麵包屑導航，改善用戶體驗和 SEO。',
            'slug'        => 'breadcrumb-navxt',
            'wp_url'      => 'https://wordpress.org/plugins/breadcrumb-navxt/',
            'category'    => 'SEO 優化',
        ],
        // 電商功能
        'woocommerce' => [
            'name'        => 'WooCommerce',
            'description' => 'WordPress 最受歡迎的電商外掛，建立完整的線上商店。',
            'slug'        => 'woocommerce',
            'wp_url'      => 'https://wordpress.org/plugins/woocommerce/',
            'category'    => '電商功能',
        ],
        'woo-order-export-lite' => [
            'name'        => 'Advanced Order Export For WooCommerce',
            'description' => '匯出 WooCommerce 訂單數據，支援多種格式。',
            'slug'        => 'woo-order-export-lite',
            'wp_url'      => 'https://wordpress.org/plugins/woo-order-export-lite/',
            'category'    => '電商功能',
        ],
        'wc-sale-notifications-for-discord' => [
            'name'        => 'WC Sale Discord Notifications',
            'description' => '將 WooCommerce 銷售通知發送到 Discord 頻道。',
            'slug'        => 'wc-sale-notifications-for-discord',
            'wp_url'      => 'https://wordpress.org/plugins/wc-sale-notifications-for-discord/',
            'category'    => '電商功能',
        ],
        'ajax-search-for-woocommerce' => [
            'name'        => 'FiboSearch - AJAX Search for WooCommerce',
            'description' => '為 WooCommerce 提供即時搜尋功能，提升購物體驗。',
            'slug'        => 'ajax-search-for-woocommerce',
            'wp_url'      => 'https://wordpress.org/plugins/ajax-search-for-woocommerce/',
            'category'    => '電商功能',
        ],
        'flexible-checkout-fields' => [
            'name'        => 'Flexible Checkout Fields',
            'description' => '自訂 WooCommerce 結帳頁面欄位，靈活配置購物流程。',
            'slug'        => 'flexible-checkout-fields',
            'wp_url'      => 'https://wordpress.org/plugins/flexible-checkout-fields/',
            'category'    => '電商功能',
        ],
        // 網站建構
        'elementor' => [
            'name'        => 'Elementor',
            'description' => '專業的頁面建構器，拖拉即可建立美觀的網頁。',
            'slug'        => 'elementor',
            'wp_url'      => 'https://wordpress.org/plugins/elementor/',
            'category'    => '網站建構',
        ],
        'greenshift-animation-and-page-builder-blocks' => [
            'name'        => 'Greenshift',
            'description' => '動畫和頁面建構區塊，增強 Gutenberg 編輯器功能。',
            'slug'        => 'greenshift-animation-and-page-builder-blocks',
            'wp_url'      => 'https://wordpress.org/plugins/greenshift-animation-and-page-builder-blocks/',
            'category'    => '網站建構',
        ],
        // 表單功能
        'fluentform' => [
            'name'        => 'Fluent Forms',
            'description' => '強大的表單建構器，建立各種互動表單。',
            'slug'        => 'fluentform',
            'wp_url'      => 'https://wordpress.org/plugins/fluentform/',
            'category'    => '表單功能',
        ],
        // 翻譯本地化
        'loco-translate' => [
            'name'        => 'Loco Translate',
            'description' => '在 WordPress 後台直接翻譯外掛和主題，支援多語言。',
            'slug'        => 'loco-translate',
            'wp_url'      => 'https://wordpress.org/plugins/loco-translate/',
            'category'    => '翻譯本地化',
        ],
        'translatepress-multilingual' => [
            'name'        => 'TranslatePress',
            'description' => '視覺化多語言外掛，前台即時翻譯網站內容。',
            'slug'        => 'translatepress-multilingual',
            'wp_url'      => 'https://wordpress.org/plugins/translatepress-multilingual/',
            'category'    => '翻譯本地化',
        ],
        // 媒體管理
        'instant-images' => [
            'name'        => 'Instant Images',
            'description' => '快速搜尋和插入免費圖片到 WordPress 媒體庫。',
            'slug'        => 'instant-images',
            'wp_url'      => 'https://wordpress.org/plugins/instant-images/',
            'category'    => '媒體管理',
        ],
        // 備份還原
        'updraftplus' => [
            'name'        => 'UpdraftPlus',
            'description' => '最受歡迎的 WordPress 備份外掛，支援多種雲端儲存。',
            'slug'        => 'updraftplus',
            'wp_url'      => 'https://wordpress.org/plugins/updraftplus/',
            'category'    => '備份還原',
        ],
        'wpvivid-backuprestore' => [
            'name'        => 'WPvivid',
            'description' => '免費的備份和還原外掛，支援網站遷移功能。',
            'slug'        => 'wpvivid-backuprestore',
            'wp_url'      => 'https://wordpress.org/plugins/wpvivid-backuprestore/',
            'category'    => '備份還原',
        ],
        // 系統維護
        'wp-downgrade' => [
            'name'        => 'WP Downgrade',
            'description' => '降級 WordPress 版本，解決相容性問題。',
            'slug'        => 'wp-downgrade',
            'wp_url'      => 'https://wordpress.org/plugins/wp-downgrade/',
            'category'    => '系統維護',
        ],
        // 彈出視窗
        'popup-trigger-url-for-elementor-pro' => [
            'name'        => 'Popup Trigger URL for Elementor Pro',
            'description' => '為 Elementor Pro 彈出視窗添加 URL 觸發功能。',
            'slug'        => 'popup-trigger-url-for-elementor-pro',
            'wp_url'      => 'https://wordpress.org/plugins/popup-trigger-url-for-elementor-pro/',
            'category'    => '彈出視窗',
        ],
    ];
}

// ===== ✅ 優化：取得所有已安裝外掛（靜態快取，避免重複呼叫）=====
function get_all_installed_plugins() {
    static $cache = null;
    if ($cache !== null) return $cache;

    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $update_plugins = get_site_transient('update_plugins');
    $cache          = [];

    foreach (get_plugins() as $plugin_path => $plugin_data) {
        $slug = dirname($plugin_path);
        if ($slug === '.') $slug = basename($plugin_path, '.php');

        $cache[$slug] = [
            'name'         => $plugin_data['Name'],
            'description'  => $plugin_data['Description'] ?: '手動上傳的外掛',
            'slug'         => $slug,
            'wp_url'       => $plugin_data['PluginURI'] ?: '#',
            'category'     => '手動安裝',
            'status'       => is_plugin_active($plugin_path) ? 'active' : 'installed',
            'file'         => $plugin_path,
            'needs_update' => isset($update_plugins->response[$plugin_path]),
            'version'      => $plugin_data['Version'] ?: '',
        ];
    }

    return $cache;
}

// ===== ✅ 優化：檢查外掛狀態（直接從快取取得，不重複跑 get_plugins）=====
function get_plugin_detailed_status($plugin_slug) {
    $all_plugins = get_all_installed_plugins();

    // 直接 slug 命中
    if (isset($all_plugins[$plugin_slug])) {
        return $all_plugins[$plugin_slug];
    }

    // 模糊比對（外掛主檔名含 slug）
    foreach ($all_plugins as $slug => $data) {
        if (strpos($data['file'], $plugin_slug . '/') === 0 ||
            strpos($data['file'], $plugin_slug . '.php') !== false) {
            return $data;
        }
    }

    return ['status' => 'not_installed', 'file' => '', 'needs_update' => false, 'version' => ''];
}

// ===== 安裝外掛（WordPress.org）=====
function install_plugin_from_repo($plugin_slug) {
    if (!current_user_can('install_plugins')) {
        return ['success' => false, 'message' => '權限不足'];
    }

    require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/misc.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

    $api = plugins_api('plugin_information', ['slug' => $plugin_slug]);
    if (is_wp_error($api)) {
        return ['success' => false, 'message' => '無法取得外掛資訊：' . $api->get_error_message()];
    }

    $upgrader  = new Plugin_Upgrader();
    $installed = $upgrader->install($api->download_link);

    if (is_wp_error($installed)) {
        return ['success' => false, 'message' => '安裝失敗：' . $installed->get_error_message()];
    }

    return ['success' => true, 'message' => '安裝成功'];
}

// ===== 上傳並安裝外掛 =====
function install_uploaded_plugin($file_data) {
    if (!current_user_can('install_plugins')) {
        return ['success' => false, 'message' => '權限不足'];
    }
    if ($file_data['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => '檔案上傳失敗'];
    }

    $file_type = wp_check_filetype($file_data['name']);
    if ($file_type['ext'] !== 'zip') {
        return ['success' => false, 'message' => '只支援 ZIP 檔案格式'];
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/misc.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

    $movefile = wp_handle_upload($file_data, ['test_form' => false]);

    if (empty($movefile['error'])) {
        $upgrader  = new Plugin_Upgrader();
        $installed = $upgrader->install($movefile['file']);
        @unlink($movefile['file']);

        if (is_wp_error($installed)) {
            return ['success' => false, 'message' => '安裝失敗：' . $installed->get_error_message()];
        }
        return ['success' => true, 'message' => '檔案上傳並安裝成功'];
    }

    return ['success' => false, 'message' => '檔案處理失敗：' . ($movefile['error'] ?? '未知錯誤')];
}

// ===== 刪除外掛 =====
function delete_plugin_from_site($plugin_file) {
    if (!current_user_can('delete_plugins')) {
        return ['success' => false, 'message' => '權限不足'];
    }
    if (is_plugin_active($plugin_file)) {
        return ['success' => false, 'message' => '外掛仍在啟用中，請先停用再刪除'];
    }

    $deleted = delete_plugins([$plugin_file]);
    if (is_wp_error($deleted)) {
        return ['success' => false, 'message' => '刪除失敗：' . $deleted->get_error_message()];
    }
    return ['success' => true, 'message' => '外掛已刪除'];
}

// ===== 後台設定頁 =====
function plugin_manager_settings_page() {
    if (!current_user_can('manage_options')) wp_die('權限不足');

    // ✅ 修正：所有 URL 都用正確的 page slug
    $page_base = admin_url('admin.php?page=plugin-downloader');

    $messages            = [];
    $activated_plugins   = [];
    $deactivated_plugins = [];
    $deleted_plugins     = [];

    // 處理檔案上傳
    if (isset($_POST['upload_plugin'], $_FILES['plugin_file'])
        && check_admin_referer('upload_plugin_action', 'upload_plugin_nonce')) {
        $result     = install_uploaded_plugin($_FILES['plugin_file']);
        $messages[] = ['type' => $result['success'] ? 'success' : 'error', 'message' => $result['message']];
    }

    // 處理 GET 操作
    if (isset($_GET['action'], $_GET['plugin'])
        && check_admin_referer('plugin_action_' . $_GET['plugin'])) {

        $plugin_slug   = sanitize_key($_GET['plugin']);
        $action        = sanitize_key($_GET['action']);
        $plugin_status = get_plugin_detailed_status($plugin_slug);

        switch ($action) {
            case 'install':
                $result     = install_plugin_from_repo($plugin_slug);
                $messages[] = ['type' => $result['success'] ? 'success' : 'error', 'message' => $result['message']];
                break;

            case 'activate':
                if (!empty($plugin_status['file'])) {
                    $result = activate_plugin($plugin_status['file']);
                    if (is_wp_error($result)) {
                        $messages[] = ['type' => 'error', 'message' => '啟用失敗：' . $result->get_error_message()];
                    } else {
                        $activated_plugins[] = $plugin_slug;
                        $messages[]          = ['type' => 'success', 'message' => '外掛已啟用'];
                    }
                }
                break;

            case 'deactivate':
                if (!empty($plugin_status['file'])) {
                    deactivate_plugins($plugin_status['file']);
                    $deactivated_plugins[] = $plugin_slug;
                    $messages[]            = ['type' => 'success', 'message' => '外掛已停用'];
                }
                break;

            case 'delete':
                if (!empty($plugin_status['file'])) {
                    $result = delete_plugin_from_site($plugin_status['file']);
                    if ($result['success']) $deleted_plugins[] = $plugin_slug;
                    $messages[] = ['type' => $result['success'] ? 'success' : 'error', 'message' => $result['message']];
                }
                break;

            case 'update':
                if (!empty($plugin_status['file'])) {
                    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
                    $upgrader = new Plugin_Upgrader();
                    $result   = $upgrader->upgrade($plugin_status['file']);
                    if (is_wp_error($result)) {
                        $messages[] = ['type' => 'error', 'message' => '更新失敗：' . $result->get_error_message()];
                    } else {
                        $messages[] = ['type' => 'success', 'message' => '外掛已更新'];
                    }
                }
                break;
        }
    }

    // ── 顯示操作提醒 ──
    if (!empty($activated_plugins)) {
        $c = count($activated_plugins);
        echo '<div class="notice notice-info is-dismissible" style="border-left:4px solid #2196F3;"><p><strong>🔄 重要提醒：</strong>' .
            esc_html($c > 1 ? "已成功啟用 {$c} 個外掛！建議重新整理前台頁面或清除快取。" : '外掛已成功啟用！建議重新整理前台頁面或清除快取。') .
            '</p></div>';
    }
    if (!empty($deactivated_plugins)) {
        $c = count($deactivated_plugins);
        echo '<div class="notice notice-warning is-dismissible" style="border-left:4px solid #ff6900;"><p><strong>⏸️ 停用提醒：</strong>' .
            esc_html($c > 1 ? "已成功停用 {$c} 個外掛！建議重新整理前台或清除快取。" : '外掛已成功停用！建議重新整理前台或清除快取。') .
            '</p></div>';
    }
    if (!empty($deleted_plugins)) {
        $c = count($deleted_plugins);
        echo '<div class="notice notice-error is-dismissible" style="border-left:4px solid #dc3545;"><p><strong>🗑️ 刪除提醒：</strong>' .
            esc_html($c > 1 ? "已成功刪除 {$c} 個外掛！" : '外掛已成功刪除！') .
            '</p></div>';
    }
    foreach ($messages as $msg) {
        echo '<div class="notice notice-' . esc_attr($msg['type']) . ' is-dismissible"><p>' . esc_html($msg['message']) . '</p></div>';
    }

    // ── 取得外掛清單 ──
    $popular_list     = get_popular_plugins_list();
    $installed_list   = get_all_installed_plugins();
    $current_category = isset($_GET['category']) ? sanitize_text_field($_GET['category']) : '';
    $order_by         = in_array($_GET['order'] ?? '', ['name', 'status', 'category']) ? $_GET['order'] : 'name';
    $order_dir        = ($_GET['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

    // 合併清單
    $all_plugins = [];
    foreach ($popular_list as $slug => $plugin) {
        $info              = get_plugin_detailed_status($slug);
        $all_plugins[$slug] = array_merge($plugin, ['slug' => $slug], $info);
        // 若 status 從 popular_list 沒有，補預設
        if (!isset($all_plugins[$slug]['status'])) {
            $all_plugins[$slug]['status'] = 'not_installed';
        }
    }
    foreach ($installed_list as $slug => $plugin) {
        if (!isset($all_plugins[$slug])) {
            $all_plugins[$slug] = $plugin;
        }
    }

    // 取得所有分類
    $categories = [];
    foreach ($all_plugins as $plugin) {
        if (!in_array($plugin['category'], $categories, true)) {
            $categories[] = $plugin['category'];
        }
    }
    sort($categories);

    // 篩選
    $filtered_plugins = [];
    foreach ($all_plugins as $slug => $plugin) {
        if (empty($current_category) || $plugin['category'] === $current_category) {
            $filtered_plugins[$slug] = $plugin;
        }
    }

    // ✅ 修正：排序邏輯真正使用 $order_by
    usort($filtered_plugins, function($a, $b) use ($order_by, $order_dir) {
        switch ($order_by) {
            case 'status':   $cmp = strcmp($a['status'] ?? '', $b['status'] ?? ''); break;
            case 'category': $cmp = strcmp($a['category'] ?? '', $b['category'] ?? ''); break;
            default:         $cmp = strcasecmp($a['name'] ?? '', $b['name'] ?? ''); break;
        }
        return $order_dir === 'desc' ? -$cmp : $cmp;
    });

    // ✅ 修正：category 篩選 URL 帶回排序參數
    $sort_toggle  = $order_dir === 'asc' ? 'desc' : 'asc';
    $cat_param    = $current_category ? '&category=' . urlencode($current_category) : '';

    ?>
    <div class="wrap">
        <h1>常用外掛管理</h1>
        <p>精選的常用 WordPress 外掛，可直接安裝、啟用或停用外掛。</p>

        <!-- 上傳外掛 -->
        <div style="max-width:500px;margin:20px 0;">
            <div style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:20px;">
                <h3 style="margin-top:0;">📁 上傳外掛檔案</h3>
                <form method="post" enctype="multipart/form-data">
                    <?php wp_nonce_field('upload_plugin_action', 'upload_plugin_nonce'); ?>
                    <p>選擇本地的外掛 ZIP 檔案進行安裝：</p>
                    <input type="file" name="plugin_file" accept=".zip" required style="width:100%;margin:10px 0;">
                    <input type="submit" name="upload_plugin" class="button button-primary" value="上傳並安裝" style="width:100%;">
                    <p style="color:#666;font-size:12px;margin:10px 0 0;">支援格式：ZIP 檔案，上傳後會顯示於下方外掛列表</p>
                </form>
            </div>
        </div>

        <hr style="margin:30px 0;">
        <h2>外掛清單</h2>

        <div class="tablenav top">
            <div class="alignleft actions">
                <!-- ✅ 修正：select onchange URL 帶回排序參數 -->
                <select onchange="location.href='<?php echo esc_js($page_base . '&order=' . $order_by . '&dir=' . $order_dir); ?>&category=' + encodeURIComponent(this.value);">
                    <option value="">所有分類</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo esc_attr($cat); ?>" <?php selected($current_category, $cat); ?>>
                            <?php echo esc_html($cat); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="alignright">
                <span class="displaying-num">共 <?php echo count($filtered_plugins); ?> 個外掛</span>
            </div>
            <br class="clear">
        </div>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th class="manage-column column-primary">
                        <a href="<?php echo esc_url($page_base . '&order=name&dir=' . ($order_by === 'name' ? $sort_toggle : 'asc') . $cat_param); ?>">
                            外掛名稱
                            <?php if ($order_by === 'name'): ?>
                                <span class="sorting-indicator"><?php echo $order_dir === 'asc' ? '▲' : '▼'; ?></span>
                            <?php endif; ?>
                        </a>
                    </th>
                    <th class="manage-column">
                        <a href="<?php echo esc_url($page_base . '&order=status&dir=' . ($order_by === 'status' ? $sort_toggle : 'asc') . $cat_param); ?>">
                            狀態
                            <?php if ($order_by === 'status'): ?><span class="sorting-indicator"><?php echo $order_dir === 'asc' ? '▲' : '▼'; ?></span><?php endif; ?>
                        </a>
                    </th>
                    <th class="manage-column">操作</th>
                    <th class="manage-column">版本</th>
                    <th class="manage-column">
                        <a href="<?php echo esc_url($page_base . '&order=category&dir=' . ($order_by === 'category' ? $sort_toggle : 'asc') . $cat_param); ?>">
                            分類
                            <?php if ($order_by === 'category'): ?><span class="sorting-indicator"><?php echo $order_dir === 'asc' ? '▲' : '▼'; ?></span><?php endif; ?>
                        </a>
                    </th>
                    <th class="manage-column">描述</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($filtered_plugins as $plugin):
                    $status      = $plugin['status'] ?? 'not_installed';
                    $needs_update = !empty($plugin['needs_update']);
                    $version     = $plugin['version'] ?? '';
                    $is_manual   = ($plugin['category'] === '手動安裝');

                    $row_class = '';
                    if ($status === 'active' || $status === 'installed') {
                        $row_class = $is_manual ? 'manual-installed' : 'installed-plugin';
                    }

                    if ($status === 'active') {
                        $status_text  = $needs_update ? '已啟用 (有更新)' : '已啟用';
                        $status_style = $needs_update ? 'background:#ff6900;color:#fff;' : 'background:#46b450;color:#fff;';
                    } elseif ($status === 'installed') {
                        $status_text  = '已安裝';
                        $status_style = 'background:#ffb900;color:#fff;';
                    } else {
                        $status_text  = '尚未安裝';
                        $status_style = 'background:#ddd;color:#666;';
                    }

                    $nonce    = wp_create_nonce('plugin_action_' . $plugin['slug']);
                    $base_url = $page_base . '&plugin=' . rawurlencode($plugin['slug']) . '&_wpnonce=' . $nonce . '&action=';
                ?>
                <tr class="<?php echo esc_attr($row_class); ?>">
                    <td class="plugin-title column-primary">
                        <strong>
                            <?php if (!empty($plugin['wp_url']) && $plugin['wp_url'] !== '#'): ?>
                                <a href="<?php echo esc_url($plugin['wp_url']); ?>" target="_blank" rel="noopener">
                                    <?php echo esc_html($plugin['name']); ?>
                                </a>
                            <?php else: ?>
                                <?php echo esc_html($plugin['name']); ?>
                            <?php endif; ?>
                        </strong>
                    </td>
                    <td>
                        <span style="display:inline-block;padding:3px 8px;border-radius:3px;font-size:11px;font-weight:700;<?php echo $status_style; ?>">
                            <?php echo esc_html($status_text); ?>
                        </span>
                    </td>
                    <td class="plugin-actions">
                        <?php if ($status === 'not_installed'): ?>
                            <a href="<?php echo esc_url($base_url . 'install'); ?>" class="button button-primary button-small">安裝</a>
                        <?php elseif ($status === 'installed'): ?>
                            <a href="<?php echo esc_url($base_url . 'activate'); ?>" class="button button-secondary button-small">啟用</a>
                            <a href="<?php echo esc_url($base_url . 'delete'); ?>" class="button button-small button-link-delete"
                               onclick="return confirm('確定要刪除「<?php echo esc_js($plugin['name']); ?>」嗎？')">刪除</a>
                        <?php elseif ($status === 'active'): ?>
                            <a href="<?php echo esc_url($base_url . 'deactivate'); ?>" class="button button-small">停用</a>
                            <?php if ($needs_update): ?>
                                <a href="<?php echo esc_url($base_url . 'update'); ?>" class="button button-primary button-small">更新</a>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td><?php echo $version ? 'v' . esc_html($version) : '<span style="color:#999;">—</span>'; ?></td>
                    <td>
                        <span style="background:<?php echo $is_manual ? '#e3f2fd' : '#f0f0f1'; ?>;color:<?php echo $is_manual ? '#1565c0' : '#444'; ?>;padding:2px 6px;border-radius:3px;font-size:11px;">
                            <?php echo esc_html($plugin['category']); ?>
                        </span>
                    </td>
                    <td style="font-size:13px;color:#555;"><?php echo esc_html($plugin['description']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div style="margin-top:20px;padding:15px;background:#f1f1f1;border-left:4px solid #0073aa;">
            <h3 style="margin-top:0;">使用說明</h3>
            <ul style="margin:0;padding-left:20px;">
                <li><strong>檔案上傳：</strong>上傳本地 ZIP 檔案直接安裝外掛</li>
                <li><strong>安裝：</strong>從 WordPress.org 安裝外掛</li>
                <li><strong>啟用 / 停用：</strong>控制外掛執行狀態（操作後建議清除快取）</li>
                <li><strong>刪除：</strong>移除已停用的外掛檔案</li>
                <li><strong>更新：</strong>外掛有新版本時顯示更新按鈕</li>
                <li><strong>分類篩選：</strong>使用下拉選單快速篩選特定分類</li>
                <li><strong>醒目標記：</strong>
                    <span style="background:#f0fff4;padding:1px 5px;border-radius:3px;">淺綠色 = 已安裝</span>　
                    <span style="background:#f3f8ff;padding:1px 5px;border-radius:3px;">淺藍色 = 手動安裝</span>
                </li>
            </ul>
        </div>
    </div>

    <style>
    .wp-list-table .column-primary { width: 22%; }
    .wp-list-table th:nth-child(2)  { width: 12%; }
    .wp-list-table .plugin-actions  { width: 14%; }
    .wp-list-table th:nth-child(4)  { width: 7%; }
    .wp-list-table th:nth-child(5)  { width: 10%; }
    .wp-list-table th:nth-child(6)  { width: 28%; }
    .plugin-actions .button { margin: 1px 2px; }
    .button-link-delete { color: #d63638 !important; }
    .button-link-delete:hover { color: #d63638 !important; text-decoration: underline; }
    .wp-list-table tr.installed-plugin { background-color: #f0fff4 !important; }
    .wp-list-table tr.manual-installed  { background-color: #f3f8ff !important; }
    </style>
    <?php
}
