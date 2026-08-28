<?php
defined('ABSPATH') || exit;

function wutm_modules(): array {
    return [
        '404-redirector' => ['name' => '404 重新導向', 'description' => '將 404 錯誤導向首頁或指定頁面。', 'group' => '內容管理', 'icon' => '🔀'],
        'admin-bar-cleaner' => ['name' => '後台介面管理', 'description' => '管理列、頁尾、選單與角色設定。', 'group' => '後台介面', 'icon' => '🎛️'],
        'audit-logger' => ['name' => '操作日誌', 'description' => '記錄重要後台活動。', 'group' => '安全性', 'icon' => '📋'],
        'advanced-tracking-manager' => ['name' => '進階追蹤管理', 'description' => '管理 GA4、GTM、Google Ads、Meta Pixel 與診斷。', 'group' => '監控追蹤', 'icon' => '🎯'],
        'captcha' => ['name' => '驗證碼', 'description' => '保護登入、註冊與留言表單。', 'group' => '安全性', 'icon' => '🤖'],
        'content-duplicator' => ['name' => '內容複製器', 'description' => '複製文章、頁面與自訂內容。', 'group' => '內容管理', 'icon' => '📄'],
        'contact-widgets' => ['name' => '浮動聯絡按鈕', 'description' => '在網站前台顯示可自訂的聯絡方式按鈕。', 'group' => '監控追蹤', 'icon' => '💬'],
        'go-live-update-urls' => ['name' => '網站網址更新', 'description' => '將資料庫中的舊網址安全替換為新網址，支援核心資料表與序列化資料。', 'group' => '內容管理', 'icon' => '🔁'],
        'homepage-popup' => ['name' => '首頁彈出視窗', 'description' => '在首頁顯示可設定圖片、文字與連結的彈出幻燈片。', 'group' => '內容管理', 'icon' => '🖼️'],\n        'error-monitor' => ['name' => '錯誤監控', 'description' => '記錄 PHP 錯誤、例外與致命錯誤，協助診斷網站問題。', 'group' => '監控追蹤', 'icon' => '🧯'],
        'dashboard-status' => ['name' => '儀表板狀態', 'description' => '顯示網站健康狀態與系統資訊。', 'group' => '後台介面', 'icon' => '📊'],
        'disk-space-manager' => ['name' => '磁碟空間管理', 'description' => '掃描 wp-content 空間、找出大檔案並安全清理。', 'group' => '效能優化', 'icon' => '💾'],
        'email-tracking' => ['name' => '郵件追蹤', 'description' => '記錄 WordPress 寄出的郵件。', 'group' => '監控追蹤', 'icon' => '📧'],
        'enhanced-downloader' => ['name' => '增強下載器', 'description' => '媒體檔下載與存取控制。', 'group' => '媒體工具', 'icon' => '⬇️'],
        'enhanced-user-list' => ['name' => '增強使用者列表', 'description' => '使用者資訊與篩選功能。', 'group' => '後台介面', 'icon' => '👥'],
        'head-footer-code' => ['name' => 'Header/Footer 代碼', 'description' => '插入自訂 HTML、JS 或 CSS。', 'group' => '代碼注入', 'icon' => '💻'],
        'hide-login-page' => ['name' => '隱藏登入頁', 'description' => '使用自訂登入 URL。', 'group' => '安全性', 'icon' => '🔒'],
        'login-limiter' => ['name' => '登入限制', 'description' => '限制失敗次數，防止暴力破解。', 'group' => '安全性', 'icon' => '🛡️'],
        'media-encoder' => ['name' => '媒體編碼器', 'description' => '媒體格式與縮圖最佳化。', 'group' => '媒體工具', 'icon' => '🎬'],
        'moving-mode' => ['name' => '維護模式', 'description' => '暫時阻止訪客存取網站。', 'group' => '效能優化', 'icon' => '🚧'],
        'no-cache-pages' => ['name' => '免快取頁面', 'description' => '指定頁面略過快取。', 'group' => '效能優化', 'icon' => '⚡', 'source' => 'no_cache_pages'],
        'plugin-downloader' => ['name' => '常用外掛管理', 'description' => '下載已安裝外掛的 ZIP。', 'group' => '媒體工具', 'icon' => '📦'],
        'post-optimization' => ['name' => '文章瀏覽與優化', 'description' => '瀏覽量統計與文章最佳化。', 'group' => '內容管理', 'icon' => '👁️'],
        'revision-manager' => ['name' => '版本管理', 'description' => '控制文章修訂版本。', 'group' => '內容管理', 'icon' => '🗂️'],
        'system-monitor' => ['name' => '系統監控', 'description' => '監控伺服器、資料庫與外掛效能。', 'group' => '監控追蹤', 'icon' => '🖥️'],
        'spam-cleaner' => ['name' => '垃圾帳號清除', 'description' => '依使用者名稱關鍵字預覽並清理垃圾機器人帳號。', 'group' => '安全性', 'icon' => '🧹'],
        'transients-manager' => ['name' => 'Transients 管理', 'description' => '查看與清理暫存資料。', 'group' => '效能優化', 'icon' => '🧹'],
        'user-switcher' => ['name' => '使用者切換', 'description' => '切換帳號進行測試。', 'group' => '後台介面', 'icon' => '🔄'],
        'woocommerce-optimizer' => ['name' => 'WooCommerce 優化器', 'description' => '只在 WooCommerce 存在時載入。', 'group' => '效能優化', 'icon' => '🛒', 'requires' => 'woocommerce'],
    ];
}
function wutm_get_module(string $key): ?array { $all = wutm_modules(); return $all[$key] ?? null; }
function wutm_module_option(string $key): string { return 'wutm_module_' . str_replace('-', '_', $key); }
function wutm_is_enabled(string $key): bool {
    if (function_exists('wutm_license_is_valid') && !wutm_license_is_valid()) return false;
    $new = get_option(wutm_module_option($key), null);
    if ($new !== null) return (bool) $new;
    $module = wutm_get_module($key) ?: [];
    $legacy_key = $module['source'] ?? $key;
    $legacy = 'wumetax_module_' . str_replace('-', '_', $legacy_key);
    return (bool) get_option($legacy, false);
}
