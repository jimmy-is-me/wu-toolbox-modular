<?php
defined('ABSPATH') || exit;

function wutm_modules(): array {
    return [
        'missing-product-images' => ['name' => '遺失商品圖片修復', 'description' => '掃描商品描述，從來源站補回本站遺失的同路徑圖片。', 'group' => '特殊工具', 'icon' => '🩹', 'requires' => 'woocommerce', 'settings_page' => 'wu-missing-product-images'],
        'wp-downgrade' => ['name' => 'WP Downgrade', 'description' => '快速安裝與啟用官方 WP Downgrade 外掛。', 'group' => '特殊工具', 'icon' => '⏪', 'tag' => '第三方外掛', 'settings_page' => 'wu-wp-downgrade'],
        'wordfence' => ['name' => 'Wordfence', 'description' => '快速安裝與啟用官方 Wordfence 外掛。', 'group' => '安全性', 'icon' => '🛡️', 'tag' => '第三方外掛', 'settings_page' => 'wu-wordfence'],
        '404-redirector' => ['name' => '404 重新導向', 'description' => '將 404 錯誤導向首頁或指定頁面。', 'group' => '內容管理', 'icon' => '🔀'],
        'admin-bar-cleaner' => ['name' => '後台介面管理', 'description' => '管理列、頁尾、選單與角色設定。', 'group' => '後台介面', 'icon' => '🎛️'],
        'audit-logger' => ['name' => '操作日誌', 'description' => '記錄重要後台活動。', 'group' => '安全性', 'icon' => '📋'],
        'advanced-tracking-manager' => ['name' => '進階追蹤管理', 'description' => '管理 GA4、GTM、Google Ads、Meta Pixel 與診斷。', 'group' => '監控追蹤', 'icon' => '🎯'],
        'rank-math-seo' => ['name' => 'Rank Math SEO', 'description' => '快速安裝與啟用官方 Rank Math SEO 外掛。', 'group' => 'SEO', 'icon' => '📈', 'settings_page' => 'wu-rank-math-seo', 'tag' => '第三方外掛'],
        'auto-upload-images' => ['name' => '自動上傳圖片', 'description' => '儲存內容時將外部圖片匯入媒體庫並替換網址。', 'group' => '媒體工具', 'icon' => '🖼️'],
        'default-featured-image' => ['name' => '預設精選圖片', 'description' => '當內容未設定精選圖片時，自動顯示指定的預設圖片。', 'group' => '媒體工具', 'icon' => '🌄', 'settings_page' => 'wu-default-featured-image'],
        'instant-images' => ['name' => 'Instant Images', 'description' => '快速安裝與啟用官方 Instant Images 外掛。', 'group' => '媒體工具', 'icon' => '📷', 'settings_page' => 'wu-instant-images', 'tag' => '第三方外掛'],
        'updraftplus' => ['name' => 'UpdraftPlus', 'description' => '快速安裝與啟用官方 UpdraftPlus 備份還原外掛。', 'group' => '備份還原', 'icon' => '💾', 'settings_page' => 'wu-updraftplus', 'tag' => '第三方外掛'],
        'wpvivid' => ['name' => 'WPvivid', 'description' => '快速安裝與啟用官方 WPvivid 備份還原外掛。', 'group' => '備份還原', 'icon' => '🗃️', 'settings_page' => 'wu-wpvivid', 'tag' => '第三方外掛'],
        'media-library-manager' => ['name' => '媒體庫管理', 'description' => '以虛擬資料夾建立、分類、篩選與批次整理媒體檔案。', 'group' => '媒體工具', 'icon' => '🗂️', 'settings_page' => 'wu-media-library-manager', 'development' => true],
        'media-sync' => ['name' => '媒體掃描匯入', 'description' => '掃描 uploads 中未登錄的檔案，再選擇匯入媒體庫。', 'group' => '媒體工具', 'icon' => '🔍', 'settings_page' => 'wu-media-sync'],
        'disable-wordpress-updates' => ['name' => '停用所有更新', 'description' => '停止 WordPress 核心、外掛與佈景主題的更新檢查及自動更新。', 'group' => '後台介面', 'icon' => '⏸️', 'settings_page' => 'wu-disable-wordpress-updates'],
        'captcha' => ['name' => '驗證碼', 'description' => '保護登入、註冊與留言表單。', 'group' => '安全性', 'icon' => '🤖'],
        'classic-features' => ['name' => '經典功能', 'description' => '啟用經典編輯器、小工具、進階工具與原始大圖上傳。', 'group' => '後台介面', 'icon' => '📝'],
        'notice-center' => ['name' => '通知整理工具', 'description' => '將後台通知集中到安全的可展開面板。', 'group' => '後台介面', 'icon' => '🔔', 'settings_page' => 'wu-notice-center'],
        'content-duplicator' => ['name' => '內容複製器', 'description' => '複製文章、頁面與自訂內容。', 'group' => '內容管理', 'icon' => '📄'],
        'content-ordering' => ['name' => '文章及分類排序', 'description' => '集中拖曳排序文章、商品與自訂分類法。', 'group' => '內容管理', 'icon' => '↕️'],
        'contact-widgets' => ['name' => '浮動聯絡按鈕', 'description' => '在網站前台顯示可自訂的聯絡方式按鈕。', 'group' => '前台介面', 'icon' => '💬'],
        'translatepress' => ['name' => 'TranslatePress', 'description' => '快速安裝與啟用官方 TranslatePress 外掛。', 'group' => '網站翻譯', 'icon' => '🌐', 'tag' => '第三方外掛', 'settings_page' => 'wu-translatepress'],
        'translatepress-addons' => ['name' => '多語言管理', 'description' => '強化 TranslatePress 的語言切換、自動偵測與前台設定。', 'group' => '網站翻譯', 'icon' => '🌐', 'requires' => 'translatepress'],
        'gtranslate' => ['name' => 'GTranslate', 'description' => '快速安裝與啟用官方 GTranslate 外掛。', 'group' => '網站翻譯', 'icon' => '🌍', 'tag' => '第三方外掛', 'settings_page' => 'wu-gtranslate'],
        'loco-translate' => ['name' => 'Loco Translate', 'description' => '快速安裝與啟用官方 Loco Translate 外掛。', 'group' => '網站翻譯', 'icon' => '🌏', 'tag' => '第三方外掛', 'settings_page' => 'wu-loco-translate'],
        'go-live-update-urls' => ['name' => '網站網址更新', 'description' => '將資料庫中的舊網址安全替換為新網址，支援核心資料表與序列化資料。', 'group' => '內容管理', 'icon' => '🔁'],
        'global-text-replace' => ['name' => '全站文字取代', 'description' => '搜尋並安全批次取代文章、商品、設定與 Meta 文字。', 'group' => '特殊工具', 'icon' => '🔎'],
        'homepage-popup' => ['name' => '首頁彈出視窗', 'description' => '在首頁顯示可設定圖片、文字與連結的彈出幻燈片。', 'group' => '前台介面', 'icon' => '🖼️'],
        'error-monitor' => ['name' => '錯誤監控', 'description' => '記錄 PHP 錯誤、例外與致命錯誤，協助診斷網站問題。', 'group' => '監控追蹤', 'icon' => '🧯'],
        'disable-comments' => ['name' => '留言停用', 'description' => '停用網站留言、Pingback 與後台留言入口。', 'group' => '安全性', 'icon' => '🚫'],
        'dashboard-status' => ['name' => '儀表板狀態', 'description' => '顯示網站健康狀態與系統資訊。', 'group' => '後台介面', 'icon' => '📊'],
        'data-import-export' => ['name' => '資料匯入／匯出', 'description' => '集中管理使用者、WooCommerce 訂單與舊密碼雜湊。', 'group' => '特殊工具', 'icon' => '🔄'],
        'disk-space-manager' => ['name' => '磁碟空間管理', 'description' => '掃描 wp-content 空間、找出大檔案並安全清理。', 'group' => '效能優化', 'icon' => '💾'],
        'disable-emojis' => ['name' => '禁用表情符號', 'description' => '移除 WordPress Emoji 前後台資源與相關轉換。', 'group' => '效能優化', 'icon' => '🙂', 'settings_page' => 'wu-disable-emojis'],
        'fluent-smtp' => ['name' => 'FluentSMTP', 'description' => '快速安裝與啟用官方 FluentSMTP 外掛。', 'group' => '郵件工具', 'icon' => '✉️', 'settings_page' => 'wu-fluent-smtp', 'tag' => '第三方外掛'],
        'email-tracking' => ['name' => '郵件追蹤', 'description' => '記錄 WordPress 寄出的郵件。', 'group' => '郵件工具', 'icon' => '📧'],
        'enhanced-downloader' => ['name' => '增強下載器', 'description' => '媒體檔下載與存取控制。', 'group' => '後台介面', 'icon' => '⬇️'],
        'enhanced-user-list' => ['name' => '增強使用者列表', 'description' => '使用者資訊與篩選功能。', 'group' => '後台介面', 'icon' => '👥'],
        'wpcode' => ['name' => 'WPCode', 'description' => '快速安裝與啟用官方 WPCode 外掛。', 'group' => '代碼注入', 'icon' => '💻', 'tag' => '第三方外掛', 'settings_page' => 'wu-wpcode'],
        'head-footer-code' => ['name' => 'Header/Footer 代碼', 'description' => '插入自訂 HTML、JS 或 CSS。', 'group' => '代碼注入', 'icon' => '💻'],
        'hide-login-page' => ['name' => '隱藏登入頁', 'description' => '使用自訂登入 URL。', 'group' => '安全性', 'icon' => '🔒'],
        'login-limiter' => ['name' => '登入限制', 'description' => '限制失敗次數，防止暴力破解。', 'group' => '安全性', 'icon' => '🛡️'],
        'woocommerce' => ['name' => 'WooCommerce', 'description' => '快速安裝與啟用官方 WooCommerce 外掛。', 'group' => '電商工具', 'icon' => '🛒', 'tag' => '第三方外掛', 'settings_page' => 'wu-woocommerce'],
        'payuni-payment' => ['name' => 'PAYUNi 付款工具', 'description' => '整合 PAYUNi 統一金流付款、退款與訂單通知。', 'group' => '電商工具', 'icon' => '💳', 'requires' => 'woocommerce', 'settings_page' => 'wc-settings&tab=payuni&section=payment'],
        'linepay-payment' => ['name' => 'LINE Pay 付款工具', 'description' => '整合台灣 LINE Pay 付款、退款及區塊結帳。', 'group' => '電商工具', 'icon' => '🟢', 'requires' => 'woocommerce', 'settings_page' => 'wc-settings&tab=linepay-tw'],
        'discord-notifications' => ['name' => 'Discord 通知工具', 'description' => '將 WooCommerce 訂單與狀態變更即時通知到 Discord。', 'group' => '電商工具', 'icon' => '🔔', 'requires' => 'woocommerce', 'settings_page' => 'wu-discord-notifications'],
        'media-encoder' => ['name' => '媒體編碼器', 'description' => '上傳時可先等比例縮小圖片，再轉換為 WebP。', 'group' => '媒體工具', 'icon' => '🎬'],
        'moving-mode' => ['name' => '維護模式', 'description' => '暫時阻止訪客存取網站。', 'group' => '效能優化', 'icon' => '🚧'],
        'no-cache-pages' => ['name' => '免快取頁面', 'description' => '指定頁面略過快取。', 'group' => '效能優化', 'icon' => '⚡', 'source' => 'no_cache_pages'],
        'post-optimization' => ['name' => '文章瀏覽及目錄', 'description' => '文章瀏覽量統計與自動文章目錄。', 'group' => '內容管理', 'icon' => '👁️'],
        'revision-manager' => ['name' => '版本管理', 'description' => '控制文章修訂版本。', 'group' => '內容管理', 'icon' => '🗂️'],
        'system-monitor' => ['name' => '系統監控', 'description' => '監控伺服器、資料庫與外掛效能。', 'group' => '監控追蹤', 'icon' => '🖥️'],
        'site-optimization' => ['name' => '網站修復工具', 'description' => '集中啟用網站後台相容性修復與最佳化功能。', 'group' => '特殊工具', 'icon' => '🛠️'],
        'spam-cleaner' => ['name' => '垃圾帳號清除', 'description' => '依使用者名稱關鍵字預覽並清理垃圾機器人帳號。', 'group' => '安全性', 'icon' => '🧹'],
        'transients-manager' => ['name' => 'Transients 管理', 'description' => '查看與清理暫存資料。', 'group' => '效能優化', 'icon' => '🧹'],
        'user-switcher' => ['name' => '使用者切換', 'description' => '切換帳號進行測試。', 'group' => '後台介面', 'icon' => '🔄'],
        'wc-optimization-tools' => ['name' => 'WC優化工具', 'description' => '台灣地址、離島運送、7-11 取貨、訂單備註與電子發票設定。', 'group' => '電商工具', 'icon' => '🛠️', 'requires' => 'woocommerce'],
        'woocommerce-optimizer' => ['name' => '隱藏WC工具', 'description' => '整理 WooCommerce 後台選單、推廣區與頁尾。', 'group' => '電商工具', 'icon' => '🛒', 'requires' => 'woocommerce'],
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

// One-time split migration: preserve the old module's enabled state and existing settings.
add_action('plugins_loaded', function (): void {
    if (get_option('wutm_wc_tools_split_182', false)) return;
    if (get_option(wutm_module_option('wc-optimization-tools'), null) === null) {
        update_option(wutm_module_option('wc-optimization-tools'), wutm_is_enabled('woocommerce-optimizer') ? 1 : 0);
    }
    update_option('wutm_wc_tools_split_182', 1);
}, 19);
