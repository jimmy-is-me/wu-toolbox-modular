<?php
defined('ABSPATH') || exit;

function wutm_modules(): array {
    return [
        'missing-product-images' => ['name' => '遺失商品圖片修復', 'description' => '掃描商品描述，從來源站補回本站遺失的同路徑圖片。', 'group' => '特殊工具', 'icon' => '🩹', 'requires' => 'woocommerce', 'settings_page' => 'wu-missing-product-images'],
        'wc-coming-soon-customizer' => ['name' => 'WC 即將推出修改', 'description' => '翻譯 WooCommerce 原生即將推出頁面的標題與說明，不改變網站原有字體或版面。', 'group' => '特殊工具', 'icon' => '🚀', 'requires' => 'woocommerce', 'settings_url' => 'admin.php?page=wc-settings&tab=site-visibility'],
        'wc-confetti' => ['name' => 'WC 紙片動畫', 'description' => '在購物車、結帳與訂單完成頁播放可自訂的雙側紙片動畫。', 'group' => '電商優化工具', 'icon' => '🎉', 'requires' => 'woocommerce', 'settings_page' => 'wu-wc-confetti'],
        'layout-inspector' => ['name' => '版面檢查器', 'description' => '讓管理員從前台管理列掃描 Section 與 Container 寬度、標示異常區塊並複製完整報告，可設定版面基準寬度與容許誤差。', 'group' => '特殊工具', 'icon' => '📐', 'settings_page' => 'wu-layout-inspector'],
        'wp-downgrade' => ['name' => 'WP Downgrade', 'description' => '快速安裝與啟用官方 WP Downgrade 外掛。', 'group' => '特殊工具', 'icon' => '⏪', 'tag' => '第三方外掛', 'settings_page' => 'wu-wp-downgrade'],
        'wordfence' => ['name' => 'Wordfence', 'description' => '快速安裝與啟用官方 Wordfence 外掛。', 'group' => '安全性', 'icon' => '🛡️', 'tag' => '第三方外掛', 'settings_page' => 'wu-wordfence'],
        'marquee-announcements' => ['name' => '跑馬燈／公告輪播', 'description' => '建立可拖曳排序的多則公告，支援全站、首頁、指定頁面或短代碼顯示與多種輪播效果。', 'group' => '內容管理', 'icon' => '📢', 'settings_page' => 'wu-marquee-announcements'],
        'knowledge-base' => ['name' => '知識庫', 'description' => '建立知識庫文件與分類，提供站內搜尋、精選內容、快捷連結、前台快速支援面板及可選擇的回到最上按鈕。', 'group' => '內容管理', 'icon' => '📚', 'settings_page' => 'wumetax-quick-support', 'related_pages' => ['edit.php?post_type=skb_doc', 'edit-tags.php?taxonomy=skb_category&post_type=skb_doc', 'edit.php?post_type=skb_doc&page=skb-settings']],
        'site-ai-connector' => ['name' => 'AI 連接器', 'description' => '統一管理 MCP、OpenAPI、Gemini 串接方式、唯讀／讀寫金鑰、IP 白名單與連線權限。', 'group' => 'AI功能', 'icon' => '', 'settings_page' => 'site-ai-connector'],
        'ai-content-tools' => ['name' => 'AI 文章與 FAQ', 'description' => '集中查看文章建立、更新、FAQ 與內容操作工具，並複製完整安全指令。', 'group' => 'AI功能', 'icon' => '📝', 'navigation' => true, 'parent_module' => 'site-ai-connector', 'settings_page' => 'wu-ai-content-tools'],
        'ai-seo-tools' => ['name' => 'AI SEO 工具', 'description' => '集中查看 SEO 讀取、缺漏掃描與安全更新工具，以及可直接交給 AI 的 SEO 指令。', 'group' => 'AI功能', 'icon' => '🔎', 'navigation' => true, 'parent_module' => 'site-ai-connector', 'settings_page' => 'wu-ai-seo-tools'],
        'ai-media-tools' => ['name' => 'AI 圖片工具', 'description' => '管理圖片上傳、媒體搜尋、精選圖片與替代文字補齊等 AI 工具。', 'group' => 'AI功能', 'icon' => '🖼️', 'navigation' => true, 'parent_module' => 'site-ai-connector', 'settings_page' => 'wu-ai-media-tools'],
        'ai-commerce-tools' => ['name' => 'AI 電商工具', 'description' => '集中查看商品、庫存、營運資料與優惠券管理工具；未安裝 WooCommerce 時會標示不可用。', 'group' => 'AI功能', 'icon' => '🛒', 'navigation' => true, 'parent_module' => 'site-ai-connector', 'settings_page' => 'wu-ai-commerce-tools'],
        'ai-site-tools' => ['name' => 'AI 網站工具', 'description' => '集中查看選單與轉址管理等網站維護工具，依連接器權限安全操作。', 'group' => 'AI功能', 'icon' => '🧰', 'navigation' => true, 'parent_module' => 'site-ai-connector', 'settings_page' => 'wu-ai-site-tools'],
        'ai-operation-log' => ['name' => 'AI 操作紀錄', 'description' => '獨立查看 AI 實際完成的新增、修改與刪除紀錄，方便追蹤寫入結果。', 'group' => 'AI功能', 'icon' => '📜', 'navigation' => true, 'parent_module' => 'site-ai-connector', 'settings_page' => 'wu-ai-operation-log'],
        'knowledge-base-ai' => ['name' => '知識庫支援 AI 擴充', 'description' => '為知識庫快速支援加入 AI 助理，支援 Gemini、Perplexity、OpenAI、網站內容上下文、額度限制與使用紀錄。', 'group' => 'AI功能', 'icon' => '🧠', 'badge' => '需先開啟知識庫功能', 'requires_module' => 'knowledge-base', 'settings_page' => 'wumetax-quick-support-ai'],
        'ai-translate-translatepress' => ['name' => 'AI 翻譯（TranslatePress）', 'description' => '使用 Gemini、Claude、Perplexity 或 OpenAI 進行 TranslatePress 全站掃描、批次翻譯、進度管理與 CSV 匯入／匯出。', 'group' => 'AI功能', 'icon' => '🌐', 'requires' => 'translatepress', 'settings_url' => 'options-general.php?page=wu-ai-translate'],
        '404-redirector' => ['name' => '404 重新導向', 'description' => '將 404 錯誤導向首頁或指定頁面。', 'group' => '內容管理', 'icon' => '🔀'],
        'smart-301-redirects' => ['name' => '301 指向', 'description' => '建立可快取的 301 永久轉址規則，支援站內／外部目標、萬用字元與命中統計。', 'group' => '內容管理', 'icon' => '↗️', 'settings_page' => 'wu-smart-301-redirects'],
        'admin-bar-cleaner' => ['name' => '後台介面管理', 'description' => '管理 WordPress 管理列、後台頁尾、側邊選單、角色權限與顯示項目。', 'group' => '後台介面', 'icon' => '🎛️'],
        'audit-logger' => ['name' => '操作日誌', 'description' => '記錄使用者登入、文章、設定及其他重要後台活動，方便追查操作紀錄。', 'group' => '安全性', 'icon' => '📋'],
        'advanced-tracking-manager' => ['name' => '進階追蹤管理', 'description' => '管理 GA4、GTM、Google Ads、Meta Pixel 與診斷。', 'group' => '監控追蹤', 'icon' => '🎯'],
        'seo-core' => ['name' => 'SEO 核心', 'description' => '台灣繁中網站用的輕量 SEO 核心，提供編輯器側欄、SEO 健檢、Title、Meta Description、Canonical、Open Graph、Schema 與 Sitemap 控制。', 'group' => 'SEO', 'icon' => '🔎', 'settings_page' => 'wumetax-seo-core', 'related_pages' => ['admin.php?page=wumetax-seo-core-settings']],
        'auto-upload-images' => ['name' => '自動上傳圖片', 'description' => '儲存內容時將外部圖片匯入媒體庫並替換網址。', 'group' => '媒體工具', 'icon' => '🖼️'],
        'default-featured-image' => ['name' => '預設精選圖片', 'description' => '當內容未設定精選圖片時，自動顯示指定的預設圖片。', 'group' => '媒體工具', 'icon' => '🌄', 'settings_page' => 'wu-default-featured-image'],
        'instant-images' => ['name' => 'Instant Images', 'description' => '快速安裝與啟用官方 Instant Images 外掛。', 'group' => '媒體工具', 'icon' => '📷', 'settings_page' => 'wu-instant-images', 'tag' => '第三方外掛'],
        'updraftplus' => ['name' => 'UpdraftPlus', 'description' => '快速安裝與啟用官方 UpdraftPlus 備份還原外掛。', 'group' => '備份還原', 'icon' => '💾', 'settings_page' => 'wu-updraftplus', 'tag' => '第三方外掛'],
        'wpvivid' => ['name' => 'WPvivid', 'description' => '快速安裝與啟用官方 WPvivid 備份還原外掛。', 'group' => '備份還原', 'icon' => '🗃️', 'settings_page' => 'wu-wpvivid', 'tag' => '第三方外掛'],
        'media-library-manager' => ['name' => '媒體庫管理', 'description' => '在 WordPress 媒體庫內以資料夾側欄建立、篩選與拖放整理媒體檔案。', 'group' => '媒體工具', 'icon' => '🗂️', 'settings_page' => 'wu-media-library-manager', 'development' => true],
        'media-sync' => ['name' => '媒體掃描匯入', 'description' => '掃描 uploads 中未登錄的檔案，再選擇匯入媒體庫。', 'group' => '媒體工具', 'icon' => '🔍', 'settings_page' => 'wu-media-sync'],
        'disable-wordpress-updates' => ['name' => '停用所有更新', 'description' => '停止 WordPress 核心、外掛與佈景主題的更新檢查及自動更新。', 'group' => '後台介面', 'icon' => '⏸️', 'settings_page' => 'wu-disable-wordpress-updates'],
        'captcha' => ['name' => '驗證碼', 'description' => '以驗證碼保護登入、註冊與留言表單，降低機器人、垃圾留言與暴力登入。', 'group' => '安全性', 'icon' => '🤖'],
        'classic-features' => ['name' => '經典功能', 'description' => '啟用經典編輯器、小工具、進階工具與原始大圖上傳。', 'group' => '後台介面', 'icon' => '📝'],
        'notice-center' => ['name' => '通知整理工具', 'description' => '將後台通知集中到安全的可展開面板。', 'group' => '後台介面', 'icon' => '🔔', 'settings_page' => 'wu-notice-center'],
        'content-duplicator' => ['name' => '內容複製器', 'description' => '複製文章、頁面與自訂內容。', 'group' => '內容管理', 'icon' => '📄'],
        'content-ordering' => ['name' => '文章及分類排序', 'description' => '集中拖曳排序文章、商品與自訂分類法。', 'group' => '內容管理', 'icon' => '↕️'],
        'contact-widgets' => ['name' => '浮動聯絡按鈕', 'description' => '在網站前台顯示可自訂的聯絡方式按鈕。', 'group' => '前台介面', 'icon' => '💬'],
        'custom-cursor' => ['name' => '自訂網站鼠標', 'description' => '在桌面裝置顯示極簡圓環鼠標，支援連結互動、點擊效果、文字欄位切換及圓環、中心點與互動顏色設定。', 'group' => '前台介面', 'icon' => '🖱️', 'settings_page' => 'wu-custom-cursor'],
        'page-transition' => ['name' => '頁面轉場動畫', 'description' => '站內正常換頁時顯示可自訂品牌色、Logo 與文字的全螢幕轉場動畫；自動略過 WooCommerce AJAX、外部連結、下載與同頁錨點。', 'group' => '前台介面', 'icon' => '✨', 'settings_page' => 'wu-page-transition'],
        'translatepress' => ['name' => 'TranslatePress', 'description' => '快速安裝與啟用官方 TranslatePress 外掛。', 'group' => '網站翻譯', 'icon' => '🌐', 'tag' => '第三方外掛', 'settings_page' => 'wu-translatepress'],
        'translatepress-addons' => ['name' => '多語言管理', 'description' => '解除 TranslatePress 語言數量限制，集中查看目前語言與模組運作狀態。', 'group' => '網站翻譯', 'icon' => '🌐', 'requires' => 'translatepress'],
        'gtranslate' => ['name' => 'GTranslate', 'description' => '快速安裝與啟用官方 GTranslate 外掛。', 'group' => '網站翻譯', 'icon' => '🌍', 'tag' => '第三方外掛', 'settings_page' => 'wu-gtranslate'],
        'loco-translate' => ['name' => 'Loco Translate', 'description' => '快速安裝與啟用官方 Loco Translate 外掛。', 'group' => '網站翻譯', 'icon' => '🌏', 'tag' => '第三方外掛', 'settings_page' => 'wu-loco-translate'],
        'go-live-update-urls' => ['name' => '網站網址更新', 'description' => '將資料庫中的舊網址安全替換為新網址，支援核心資料表與序列化資料。', 'group' => '內容管理', 'icon' => '🔁'],
        'global-text-replace' => ['name' => '全站文字取代', 'description' => '搜尋並安全批次取代文章、商品、設定與 Meta 文字。', 'group' => '特殊工具', 'icon' => '🔎'],
        'homepage-popup' => ['name' => '首頁 Popup 彈出視窗', 'description' => '參考 PopUp Roulette，在首頁顯示可設定圖片、文字、連結、隨機輪播與顯示時機的 Popup。', 'group' => '前台介面', 'icon' => '🖼️'],
        'error-monitor' => ['name' => '錯誤監控', 'description' => '記錄 PHP 錯誤、例外與致命錯誤，協助診斷網站問題。', 'group' => '監控追蹤', 'icon' => '🧯'],
        'disable-comments' => ['name' => '留言停用', 'description' => '停用網站留言、Pingback 與後台留言入口。', 'group' => '安全性', 'icon' => '🚫'],
        'dashboard-status' => ['name' => '儀表板狀態', 'description' => '顯示網域、SSL、PHP、WordPress、磁碟、網站健康與維護服務狀態。', 'group' => '後台介面', 'icon' => '📊'],
        'data-import-export' => ['name' => '資料匯入／匯出', 'description' => '集中管理使用者、WooCommerce 訂單與舊密碼雜湊。', 'group' => '特殊工具', 'icon' => '🔄'],
        'all-fields-overview' => ['name' => '顯示網站所有欄位', 'description' => '集中檢視 ACF、文章、使用者、分類與留言的所有 Meta 欄位名稱。', 'group' => '特殊工具', 'icon' => '🗂️', 'settings_page' => 'wu-all-fields-overview'],
        'order-status-migrator' => ['name' => '訂單狀態遷移工具', 'description' => '預覽並分批遷移 WooCommerce 訂單狀態，支援傳統訂單與 HPOS。', 'group' => '特殊工具', 'icon' => '🔁', 'requires' => 'woocommerce', 'settings_page' => 'wu-order-status-migrator'],
        'page-cache' => ['name' => '頁面快取', 'description' => '為電腦、平板與手機建立獨立 GZIP 頁面快取，支援 TTL、免快取網址、快取狀態與可控制的內容更新自動清除。', 'group' => '效能優化', 'icon' => '⚡', 'settings_page' => 'wu-page-cache'],
        'disk-space-manager' => ['name' => '磁碟空間管理', 'description' => '掃描 wp-content 空間、找出大檔案並安全清理。', 'group' => '效能優化', 'icon' => '💾'],
        'disable-emojis' => ['name' => '禁用表情符號', 'description' => '移除 WordPress Emoji 前後台資源與相關轉換。', 'group' => '效能優化', 'icon' => '🙂', 'settings_page' => 'wu-disable-emojis'],
        'fluent-smtp' => ['name' => 'FluentSMTP', 'description' => '快速安裝與啟用官方 FluentSMTP 外掛。', 'group' => '郵件工具', 'icon' => '✉️', 'settings_page' => 'wu-fluent-smtp', 'tag' => '第三方外掛'],
        'email-tracking' => ['name' => '郵件追蹤', 'description' => '記錄 WordPress 寄件時間、多個收件地址、主旨與實際寄送結果，並顯示網站發信健康狀態、最近檢查時間與測試寄信。', 'group' => '郵件工具', 'icon' => '📧'],
        'enhanced-downloader' => ['name' => '增強下載器', 'description' => '提供媒體檔下載、附件網址與檔案存取權限控制。', 'group' => '後台介面', 'icon' => '⬇️'],
        'enhanced-user-list' => ['name' => '增強使用者列表', 'description' => '在使用者列表顯示更多會員資訊，並提供搜尋、欄位與篩選功能。', 'group' => '後台介面', 'icon' => '👥'],
        'wpcode' => ['name' => 'WPCode', 'description' => '快速安裝與啟用官方 WPCode 外掛。', 'group' => '代碼注入', 'icon' => '💻', 'tag' => '第三方外掛', 'settings_page' => 'wu-wpcode'],
        'head-footer-code' => ['name' => 'Header/Footer 代碼', 'description' => '插入自訂 HTML、JS 或 CSS。', 'group' => '代碼注入', 'icon' => '💻'],
        'homepage-products' => ['name' => '首頁商品區塊設定', 'description' => '以短代碼顯示輪播圖、中間內容與可篩選的 WooCommerce 商品網格。', 'group' => '短代碼注入', 'icon' => '🏠', 'requires' => 'woocommerce', 'settings_page' => 'wu-homepage-products'],
        'faq-shortcode' => ['name' => '常見問題設定', 'description' => '建立可展開的常見問題清單，並透過短代碼插入頁面。', 'group' => '短代碼注入', 'icon' => '❓', 'settings_page' => 'wu-faq-shortcode'],
        'refund-policy' => ['name' => '退換貨政策', 'description' => '管理退換貨條款並以美觀卡片短代碼顯示。', 'group' => '短代碼注入', 'icon' => '🔄', 'settings_page' => 'wu-refund-policy'],
        'privacy-policy' => ['name' => '隱私權政策', 'description' => '管理隱私權前言與條款，並透過短代碼插入頁面。', 'group' => '短代碼注入', 'icon' => '🔐', 'settings_page' => 'wu-privacy-policy'],
        'product-category-menu' => ['name' => '商品分類選單', 'description' => '以手風琴短代碼顯示 WooCommerce 多層商品分類。', 'group' => '短代碼注入', 'icon' => '🗂️', 'requires' => 'woocommerce', 'settings_page' => 'wu-product-category-menu'],
        'hide-login-page' => ['name' => '隱藏登入頁', 'description' => '變更 wp-login.php 登入網址，使用自訂登入 URL 隱藏預設入口。', 'group' => '安全性', 'icon' => '🔒'],
        'login-limiter' => ['name' => '登入限制', 'description' => '限制登入失敗次數並暫時封鎖來源，防止密碼暴力破解。', 'group' => '安全性', 'icon' => '🛡️'],
        'woocommerce' => ['name' => 'WooCommerce', 'description' => '快速安裝與啟用官方 WooCommerce 外掛。', 'group' => '電商工具', 'icon' => '🛒', 'tag' => '第三方外掛', 'settings_page' => 'wu-woocommerce'],
        'simple-message-board' => ['name' => '簡易留言板', 'description' => '以短代碼建立聯絡留言表單，並在後台集中回覆與管理客戶留言。', 'group' => '電商工具', 'icon' => '💬', 'settings_page' => 'wu-simple-message-board', 'related_pages' => ['edit.php?post_type=wutm_message']],
        'shipping-notification-email' => ['name' => '出貨通知信', 'description' => '從訂單編輯頁預覽、修改並寄出出貨通知信，同步保留寄送時間與訂單備註。', 'group' => '電商工具', 'icon' => '📦', 'requires' => 'woocommerce', 'settings_page' => 'wu-shipping-notification-email'],
        'atm-transfer-optimizer' => ['name' => 'ATM 轉帳優化', 'description' => '強化銀行轉帳付款資訊、顧客匯款回報、後台對帳狀態與催繳通知。', 'group' => '電商工具', 'icon' => '🏧', 'requires' => 'woocommerce', 'settings_url' => 'admin.php?page=wc-bacs-dashboard'],
        'order-receipt' => ['name' => '列印訂單收據', 'description' => '為管理員與顧客提供安全、美觀且適合列印的繁體中文訂單收據。', 'group' => '電商工具', 'icon' => '🧾', 'requires' => 'woocommerce', 'settings_page' => 'wu-order-receipt'],
        'coupon-optimizer' => ['name' => '折價券優化', 'description' => '顯示可用折價券、一鍵套用、折價券免運、會員 Email 下拉限制、推廣連結、QR Code、使用統計與會員專區。', 'group' => '電商工具', 'icon' => '🏷️', 'requires' => 'woocommerce', 'settings_page' => 'wu-coupon-optimizer'],
        'checkout-login-optimizer' => ['name' => '結帳登入優化', 'description' => '美化並展開結帳登入介面，調整會員密碼欄位並提供帳號設定指引。', 'group' => '電商工具', 'icon' => '🔐', 'requires' => 'woocommerce', 'settings_page' => 'wu-checkout-login-optimizer'],
        'payuni-payment' => ['name' => 'PAYUNi 付款工具', 'description' => '整合 PAYUNi 統一金流付款、退款與訂單通知。', 'group' => '電商支付工具', 'icon' => '💳', 'requires' => 'woocommerce', 'settings_page' => 'wc-settings&tab=payuni&section=payment'],
        'linepay-payment' => ['name' => 'LINE Pay 付款工具', 'description' => '整合台灣 LINE Pay 付款、退款及區塊結帳。', 'group' => '電商支付工具', 'icon' => '🟢', 'requires' => 'woocommerce', 'settings_page' => 'wc-settings&tab=linepay-tw'],
        'discord-notifications' => ['name' => 'Discord 通知工具', 'description' => '將 WooCommerce 訂單與狀態變更即時通知到 Discord。', 'group' => '電商工具', 'icon' => '🔔', 'requires' => 'woocommerce', 'settings_page' => 'wu-discord-notifications'],
        'order-status-manager' => ['name' => '訂單狀態管理', 'description' => '新增、檢視、改名或停用訂單狀態，同步後台、帳號訂單與通知標題。', 'group' => '電商工具', 'icon' => '🏷️', 'requires' => 'woocommerce', 'settings_page' => 'wu-order-status-manager'],
        'order-bulk-actions-manager' => ['name' => '訂單批次操作管理', 'description' => '集中管理傳統與 HPOS 訂單列表的批次操作，可逐項顯示、隱藏或快速全部切換。', 'group' => '電商工具', 'icon' => '🎛️', 'requires' => 'woocommerce', 'settings_page' => 'wu-order-bulk-actions-manager'],
        'media-encoder' => ['name' => '媒體編碼器', 'description' => '圖片上傳縮圖、WebP 自動或單張轉換、品質設定、記憶體檢查與縮圖尺寸停用管理。', 'group' => '媒體工具', 'icon' => '🎬'],
        'moving-mode' => ['name' => '維護模式', 'description' => '網站維護期間暫停訪客存取，向前台顯示維護頁面。', 'group' => '效能優化', 'icon' => '🚧'],
        'post-optimization' => ['name' => '文章瀏覽及目錄', 'description' => '統計文章瀏覽量、顯示熱門文章，並依標題自動建立文章目錄。', 'group' => '內容管理', 'icon' => '👁️'],
        'revision-manager' => ['name' => '版本管理', 'description' => '設定文章與頁面的修訂版本數量，控制及清理歷史版本。', 'group' => '內容管理', 'icon' => '🗂️'],
        'system-monitor' => ['name' => '系統監控', 'description' => '監控 PHP、伺服器、資料庫、記憶體與外掛效能，協助找出網站異常。', 'group' => '監控追蹤', 'icon' => '🖥️'],
        'site-optimization' => ['name' => '網站修復工具', 'description' => '集中啟用網站後台相容性修復與最佳化功能。', 'group' => '特殊工具', 'icon' => '🛠️'],
        'role-cleanup-manager' => ['name' => '使用者角色管理與清理', 'description' => '檢查網站角色、遷移角色成員，並安全刪除無人使用的自訂角色。', 'group' => '特殊工具', 'icon' => '👤', 'settings_page' => 'wu-role-cleanup-manager'],
        'spam-cleaner' => ['name' => '垃圾帳號清除', 'description' => '依使用者名稱關鍵字預覽並清理垃圾機器人帳號。', 'group' => '安全性', 'icon' => '🧹'],
        'transients-manager' => ['name' => 'Transients 管理', 'description' => '查看、搜尋及清理 WordPress Transients 過期暫存資料。', 'group' => '效能優化', 'icon' => '🧹'],
        'user-switcher' => ['name' => '使用者切換', 'description' => '管理員可快速切換會員帳號進行權限、訂單與前台功能測試。', 'group' => '後台介面', 'icon' => '🔄'],
        'wc-optimization-tools' => ['name' => 'WC優化工具', 'description' => '台灣地址、訂單備註、電子發票、一頁式結帳、Enter 新增商品屬性、按鈕標籤規格及虛擬商品自動完成。', 'group' => '電商優化工具', 'icon' => '🛠️', 'requires' => 'woocommerce'],
        'product-size-chart' => ['name' => '商品規格表', 'description' => '為每項商品自訂規格表欄位名稱與內容，可自由新增或移除欄位，並在前台以響應式表格呈現。', 'group' => '電商優化工具', 'icon' => '📋', 'requires' => 'woocommerce', 'settings_page' => 'wu-product-size-chart'],
        'product-faq' => ['name' => '商品問答 FAQ', 'description' => '在商品編輯頁管理問答與展開模式，並在商品頁籤以易讀的手風琴或獨立展開方式顯示。', 'group' => '電商優化工具', 'icon' => '❔', 'requires' => 'woocommerce', 'settings_page' => 'wu-product-faq'],
        'shipping-optimization-tools' => ['name' => '運送優化功能', 'description' => '管理台灣離島配送與 7-11 超商物流，支援運費、免運門檻、免運折價券、手動填寫、綠界電子地圖、門市資料及訂單辨識。', 'group' => '電商工具', 'icon' => '🚚', 'requires' => 'woocommerce', 'settings_page' => 'wu-shipping-optimization-tools'],
        'free-shipping-notice' => ['name' => '免運門檻提示', 'description' => '統一設定單一費率、自行取貨與 7-11 超商物流的免運門檻；支援免運折價券、尚差金額與已達免運提示，折價券與新會員優惠都會計入小計。', 'group' => '電商工具', 'icon' => '🚚', 'requires' => 'woocommerce', 'settings_page' => 'wu-free-shipping-notice'],
        'product-shipping-restrict' => ['name' => '商品限制物流', 'description' => '為主商品或個別商品規格限制允許的物流方式，並依購物車商品自動顯示共同可用配送選項。', 'group' => '電商工具', 'icon' => '📍', 'requires' => 'woocommerce', 'settings_page' => 'wu-product-shipping-restrict'],
        'woocommerce-optimizer' => ['name' => '隱藏WC工具', 'description' => '整理 WooCommerce 後台選單、推廣區與頁尾。', 'group' => '電商工具', 'icon' => '🛒', 'requires' => 'woocommerce'],
        'product-sales-count' => ['name' => '商品購買量', 'description' => '顯示商品實際銷售量，並可由管理員設定顯示調整值。', 'group' => '電商工具', 'icon' => '📊', 'requires' => 'woocommerce', 'settings_page' => 'wu-product-sales-count'],
        'new-member-discount' => ['name' => '新會員優惠', 'description' => '新會員首次消費滿額自動折抵，折扣會計入免運門檻小計，並記錄使用狀態與取消解鎖。', 'group' => '電商工具', 'icon' => '🎁', 'requires' => 'woocommerce', 'settings_page' => 'wu-new-member-discount'],
        'member-loyalty' => ['name' => '會員點數與階級', 'description' => '整合消費點數、結帳折抵、會員分級、專屬權益與 CRM；階級點數倍率會自動套用至點數回饋。', 'group' => '電商工具', 'icon' => '🏆', 'requires' => 'woocommerce', 'settings_page' => 'wu-member-loyalty', 'legacy_sources' => ['member-points', 'member-tiers']],
        'product-scheduled-unpublish' => ['name' => '商品排程下架', 'description' => '在商品編輯頁設定下架時間，到期後自動轉為草稿，並於商品列表顯示及排序排程。', 'group' => '電商工具', 'icon' => '🕒', 'requires' => 'woocommerce', 'settings_url' => 'edit.php?post_type=product'],
        'ecpay-tools' => ['name' => '綠界金流/物流/電子發票工具', 'description' => '整合綠界付款、超商與宅配物流及電子發票。需設定商店資料並完成測試。', 'group' => '電商支付工具', 'icon' => '💳', 'requires' => 'woocommerce'],
        'esun-payment' => ['name' => '玉山銀行金流工具', 'description' => '玉山信用卡一次付清與分期付款，沿用銀行交易驗證流程。', 'group' => '電商支付工具', 'icon' => '🏦', 'requires' => 'woocommerce'],
        'shopcom-integration' => ['name' => '美安串接', 'description' => '整合 SHOP.COM RID／Click_ID 追蹤、訂單成立與取消回傳、佣金計算、商品 XML Feed 與 HPOS 訂單狀態。', 'group' => '專業工具', 'icon' => '🔗', 'requires' => 'woocommerce', 'settings_url' => 'admin.php?page=wc-settings&tab=shopcom'],
    ];
}

function wutm_grouped_modules(): array {
    $groups = [];
    foreach (wutm_modules() as $key => $module) {
        $groups[$module['group']][$key] = $module;
    }

    $ordered = [];
    foreach (['後台介面', '前台介面', '內容管理', 'SEO', 'AI功能', '效能優化'] as $group) {
        if (!isset($groups[$group])) continue;
        $ordered[$group] = $groups[$group];
        unset($groups[$group]);
    }

    $payments = $groups['電商支付工具'] ?? null;
    $commerce_optimization = $groups['電商優化工具'] ?? null;
    $professional = $groups['專業工具'] ?? null;
    $special = $groups['特殊工具'] ?? null;
    unset($groups['電商支付工具'], $groups['電商優化工具'], $groups['專業工具'], $groups['特殊工具']);

    if ($commerce_optimization !== null && isset($commerce_optimization['wc-optimization-tools'])) {
        $wc_optimization = $commerce_optimization['wc-optimization-tools'];
        unset($commerce_optimization['wc-optimization-tools']);
        $commerce_optimization = ['wc-optimization-tools' => $wc_optimization] + $commerce_optimization;
    }

    foreach ($groups as $group => $items) {
        $ordered[$group] = $items;
        if ($group === '電商工具' && $payments !== null) {
            $ordered['電商支付工具'] = $payments;
            $payments = null;
            if ($commerce_optimization !== null) {
                $ordered['電商優化工具'] = $commerce_optimization;
                $commerce_optimization = null;
            }
        }
    }
    if ($payments !== null) $ordered['電商支付工具'] = $payments;
    if ($commerce_optimization !== null) $ordered['電商優化工具'] = $commerce_optimization;
    if ($professional !== null) $ordered['專業工具'] = $professional;
    if ($special !== null) $ordered['特殊工具'] = $special;

    return $ordered;
}

function wutm_get_module(string $key): ?array { $all = wutm_modules(); return $all[$key] ?? null; }
function wutm_module_option(string $key): string { return 'wutm_module_' . str_replace('-', '_', $key); }
function wutm_is_enabled(string $key): bool {
    $new = get_option(wutm_module_option($key), null);
    if ($new !== null) return (bool) $new;
    $module = wutm_get_module($key) ?: [];
    $legacy_keys = $module['legacy_sources'] ?? [$module['source'] ?? $key];
    foreach ($legacy_keys as $legacy_key) {
        $previous = get_option(wutm_module_option($legacy_key), null);
        if ($previous !== null) {
            if ((bool) $previous) return true;
            continue;
        }
        $legacy = 'wumetax_module_' . str_replace('-', '_', $legacy_key);
        if ((bool) get_option($legacy, false)) return true;
    }
    return false;
}

// Merge the former points and tiers cards without changing their stored data.
// Enabling either former card keeps the new unified module enabled after update.
add_action('plugins_loaded', function (): void {
    if (get_option('wutm_member_loyalty_merged_240', false)) return;
    if (get_option(wutm_module_option('member-loyalty'), null) === null) {
        update_option(wutm_module_option('member-loyalty'), wutm_is_enabled('member-loyalty') ? 1 : 0, false);
    }
    update_option('wutm_member_loyalty_merged_240', 1, false);
}, 18);

// One-time split migration: preserve the old module's enabled state and existing settings.
add_action('plugins_loaded', function (): void {
    if (get_option('wutm_wc_tools_split_182', false)) return;
    if (get_option(wutm_module_option('wc-optimization-tools'), null) === null) {
        update_option(wutm_module_option('wc-optimization-tools'), wutm_is_enabled('woocommerce-optimizer') ? 1 : 0);
    }
    update_option('wutm_wc_tools_split_182', 1);
}, 19);

// Merge the former no-cache card into Page Cache and retain its saved paths.
add_action('plugins_loaded', function (): void {
    if (get_option('wutm_page_cache_merged_247', false)) return;

    $legacy_enabled = (bool) get_option(wutm_module_option('no-cache-pages'), false)
        || (bool) get_option('wumetax_module_no_cache_pages', false);
    if (get_option(wutm_module_option('page-cache'), null) === null && $legacy_enabled) {
        update_option(wutm_module_option('page-cache'), 1, false);
    }

    $legacy = get_option('wu_no_cache_pages_options', []);
    if (is_array($legacy) && !empty($legacy['pages'])) {
        $current = get_option('wutm_page_cache_settings', []);
        $current = is_array($current) ? $current : [];
        $paths = preg_split('/\R/', (string) ($current['excluded_uri'] ?? "/cart/\n/checkout/\n/my-account/"));
        $paths = array_merge($paths ?: [], preg_split('/\R/', (string) $legacy['pages']) ?: []);
        $paths = array_values(array_unique(array_filter(array_map('trim', $paths))));
        $current['excluded_uri'] = implode("\n", $paths);
        update_option('wutm_page_cache_settings', $current, false);
    }

    update_option('wutm_page_cache_merged_247', 1, false);
}, 18);

add_action('plugins_loaded', function (): void {
    if (get_option('wutm_product_sales_count_split_212', false)) return;
    if (get_option(wutm_module_option('product-sales-count'), null) === null && get_option('wu_woo_show_sales_with_offset', false)) update_option(wutm_module_option('product-sales-count'), 1, false);
    delete_option('wu_woo_show_sales_with_offset');
    update_option('wutm_product_sales_count_split_212', 1, false);
}, 19);

// Preserve existing island/7-11 users when those settings move to the new
// shipping card. Sites that never enabled either feature remain off.
add_action('plugins_loaded', function (): void {
    if (get_option('wutm_shipping_tools_split_197', false)) return;
    if (get_option(wutm_module_option('shipping-optimization-tools'), null) === null) {
        $had_shipping_feature = get_option('wu_woo_enable_island_shipping', false) || get_option('wu_woo_enable_711_shipping', false);
        update_option(wutm_module_option('shipping-optimization-tools'), $had_shipping_feature ? 1 : 0, false);
    }
    update_option('wutm_shipping_tools_split_197', 1, false);
}, 19);

// The points module was removed in v1.8.8. Remove only its loader flags so an
// old enabled value cannot survive in caches or be revived by legacy code.
add_action('plugins_loaded', function (): void {
    if (get_option('wutm_points_rewards_removed_188', false)) return;
    delete_option('wutm_module_points_and_rewards');
    delete_option('wumetax_module_points_and_rewards');
    delete_option('wutm_points_rewards_initialized');
    update_option('wutm_points_rewards_removed_188', 1, false);
}, 18);

// Preserve either former module's enabled state and its stored thresholds.
add_action('plugins_loaded', function (): void {
    if (get_option('wutm_shipping_merged_251')) return;
    if (wutm_is_enabled('free-shipping-notice')) update_option(wutm_module_option('shipping-optimization-tools'), 1, false);
    if (class_exists('WC_Cache_Helper')) WC_Cache_Helper::get_transient_version('shipping', true);
    update_option('wutm_shipping_merged_251', 1, false);
}, 19);
