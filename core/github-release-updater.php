<?php
defined('ABSPATH') || exit;

final class WUTM_GitHub_Release_Updater {
    private const REPOSITORY = 'jimmy-is-me/wu-toolbox-modular';
    private const ASSET_NAME = 'wu-toolbox-modular.zip';
    private const CACHE_KEY = 'wutm_github_release_v1';
    private const CACHE_TTL = 900;

    private string $plugin_basename;

    public function __construct(string $plugin_file) {
        $this->plugin_basename = plugin_basename($plugin_file);
        add_filter('site_transient_update_plugins', [$this, 'inject_update']);
        add_filter('pre_set_site_transient_update_plugins', [$this, 'inject_update']);
        add_filter('plugins_api', [$this, 'plugin_information'], 20, 3);
        add_filter('upgrader_pre_download', [$this, 'download_release_asset'], 20, 3);
        add_action('load-update-core.php', [$this, 'clear_cache_before_update']);
        add_action('load-plugins.php', [$this, 'clear_cache_before_update']);
    }

    public function inject_update($transient) {
        if (!is_object($transient) || empty($transient->checked) || !isset($transient->checked[$this->plugin_basename])) return $transient;
        $release = $this->get_release();
        if (!$release || !version_compare($release['version'], WUTM_VERSION, '>')) return $transient;

        $transient->response = isset($transient->response) && is_array($transient->response) ? $transient->response : [];
        $transient->response[$this->plugin_basename] = (object) [
            'slug' => 'wu-toolbox-modular',
            'plugin' => $this->plugin_basename,
            'new_version' => $release['version'],
            'url' => $release['html_url'],
            'package' => (($release['browser_url'] ?? '') ?: $release['asset_api_url']),
            'icons' => [], 'banners' => [], 'banners_rtl' => [],
            'tested' => get_bloginfo('version'),
            'requires_php' => '7.4',
            'autoupdate' => false,
        ];
        return $transient;
    }

    public function plugin_information($result, $action, $args) {
        if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== 'wu-toolbox-modular') return $result;

        $release = $this->get_release();
        $version = $release['version'] ?? WUTM_VERSION;
        $download = $release['browser_url'] ?? ($release['asset_api_url'] ?? '');
        $release_notes = !empty($release['body']) ? wp_kses_post(wpautop($release['body'])) : '';

        $changelog = '
            <h4>1.7.6</h4>
            <ul>
                <li>新增「常用外掛」分類與 Instant Images 卡片。</li>
                <li>啟用卡片後可從 WordPress.org 一鍵安裝與啟用官方 Instant Images 外掛；WU Toolbox 不會重製或修改其圖片功能。</li>
                <li>補足安裝、啟用、權限與伺服器寫入失敗時的繁體中文提示。</li>
            </ul>
            <h4>1.7.5</h4>
            <ul>
                <li>修正「通知整理工具」可能搬移區塊編輯器或 WooCommerce 動態通知，導致顯示 Connection lost 的問題。</li>
                <li>通知整理現在只處理傳統後台初始通知；模組設定頁、區塊編輯器、網站編輯器與小工具介面不會執行通知 DOM 搬移。</li>
                <li>移除持續監看的 MutationObserver，避免干擾後台儲存、Heartbeat 與 REST API 連線狀態。</li>
            </ul>
            <h4>1.7.4</h4>
            <ul>
                <li>新增「媒體掃描匯入」模組，可安全掃描 uploads 資料夾並將選取的未登錄檔案匯入媒體庫。</li>
                <li>移除 SVG 上傳模組與所有相關設定入口。</li>
                <li>重建「使用者切換」模組：使用可驗證的安全 Cookie 保留原始帳號、支援一鍵返回與 WooCommerce 會話隔離。</li>
                <li>新增「停用所有更新」模組，可停止核心、外掛與佈景主題的更新檢查與自動更新。</li>
                <li>從後台介面管理移除「隱藏後台更新通知」選項，避免與完整更新停用功能混淆。</li>
            </ul>
            <h4>1.7.3</h4>
            <ul>
                <li>內容複製器參考 Duplicate Post 改善，新增「複製為草稿」、批次複製、逐篇權限驗證與更完整的設定正規化。</li>
                <li>SVG 上傳依 SVG Support 的媒體相容作法，補齊 SVG 尺寸、媒體庫預覽與附件 Metadata，且維持嚴格安全檢查。</li>
                <li>新增「預設精選圖片」模組，可在未設定精選圖片時輸出媒體庫指定的備援圖片；不會修改既有文章資料。</li>
                <li>新增「禁用表情符號」模組，移除 WordPress Emoji 資源與 DNS 預先解析；不會移除文章內容中的 Emoji 文字。</li>
                <li>媒體庫管理卡片新增「開發中」標籤。</li>
            </ul>
            <h4>1.7.2</h4>
            <ul>
                <li>修正 SVG 相容影像處理器的 WordPress 核心方法宣告，避免啟用模組時發生致命錯誤。</li>
                <li>SVG 上傳仍會略過不支援的點陣縮圖建立，並保留既有的安全檢查。</li>
            </ul>
            <h4>1.7.1</h4>
            <ul>
                <li>媒體庫管理可直接在 WordPress 原生媒體庫格狀檢視，將媒體拖曳到資料夾完成分類。</li>
                <li>SVG 上傳加入相容的影像處理器與略過縮圖設定，避免上傳時出現伺服器無法處理圖片。</li>
                <li>修正內容複製器儲存設定時，排除欄位傳入陣列會造成致命錯誤的問題。</li>
            </ul>
            <h4>1.7.0</h4>
            <ul>
                <li>新增「SVG 上傳」模組，支援安全檢查後上傳 SVG 圖檔，拒絕腳本、事件程式碼、外部引用與危險樣式。</li>
                <li>新增「媒體庫管理」模組，可建立資料夾與子資料夾、重新命名、刪除、篩選及批次分類媒體檔案。</li>
                <li>媒體庫資料夾為虛擬分類，不會移動實體檔案、改變檔名或影響既有圖片網址。</li>
                <li>兩個新模組均歸入「媒體工具」分類，設定與操作說明採用繁體中文，且不包含廣告或外部請求。</li>
            </ul>
            <h4>1.6.9</h4>
            <ul>
                <li>新增「通知整理工具」模組，將後台通知集中到可展開面板，保留原有通知內容與關閉操作。</li>
                <li>通知整理工具採用繁體中文設定與提示，不包含廣告、外部請求或追蹤；發現重要錯誤時可自動展開面板。</li>
                <li>「經典功能」新增大型尺寸圖片限制開關，可停用 WordPress 自動建立 -scaled 圖片並保留原始尺寸。</li>
                <li>大型尺寸圖片功能不會停用一般縮圖或圖片壓縮設定。</li>
            </ul>
            <h4>1.6.8</h4>
            <ul>
                <li>新增「Discord 通知工具」模組，歸入「電商工具」分類，可將 WooCommerce 訂單與狀態變更通知到 Discord。</li>
                <li>後台設定、通知標題與通知欄位全面採用繁體中文，支援 Webhook、訂單狀態、欄位選擇與拖曳排序。</li>
                <li>支援各訂單狀態使用不同 Webhook 與顏色、商品圖片、付款開始通知、訂單備註、HPOS 及重複發送防護。</li>
                <li>加入 WordPress、PHP、WooCommerce 版本與獨立外掛重複載入防護。</li>
            </ul>
            <h4>1.6.7</h4>
            <ul>
                <li>新增「電商工具」分類，以及 PAYUNi 付款工具與 LINE Pay 付款工具兩個獨立模組卡片。</li>
                <li>PAYUNi 模組支援整合式支付頁、多種付款方式、退款、訂單通知與 HPOS。</li>
                <li>LINE Pay 模組支援台灣 LINE Pay、部分退款、HPOS 與 WooCommerce 區塊結帳。</li>
                <li>付款模組加入 WooCommerce／PHP 版本、OpenSSL 與獨立外掛重複載入防護。</li>
            </ul>
            <h4>1.6.6</h4>
            <ul>
                <li>「經典功能」新增進階編輯器工具，可擴充 TinyMCE 字型、字級、色彩、縮排、上下標及格式工具。</li>
                <li>新增「自動上傳圖片」模組，儲存內容時會將外部圖片安全匯入媒體庫並替換網址。</li>
                <li>自動上傳圖片支援文章、頁面、商品與自訂內容類型，並可排除指定內容類型與網域。</li>
                <li>加入安全網址、圖片 MIME、操作權限與重複處理防護；下載失敗不會中斷內容儲存。</li>
            </ul>
            <h4>1.6.5</h4>
            <ul>
                <li>新增「經典功能」模組，可分別啟用經典編輯器與經典小工具。</li>
                <li>經典編輯器支援文章、頁面、商品與使用編輯器的自訂內容類型，並相容 Gutenberg 外掛。</li>
                <li>經典小工具恢復「外觀 → 小工具」與自訂器的傳統管理介面。</li>
                <li>從後台介面管理移除「移除 WordPress 標誌」及「移除管理列新增項目」設定與執行程式。</li>
            </ul>
            <h4>1.6.4</h4>
            <ul>
                <li>完全隔離 WordPress 與 WooCommerce 新增分類使用的 AJAX 流程，不再註冊排序 SQL 或自訂欄位回呼。</li>
                <li>放寬動態分類欄位與排序 filter 的參數型別，避免與 WooCommerce／第三方分類欄位產生 PHP TypeError。</li>
                <li>保留一般列表頁的文章、商品與分類拖曳排序功能。</li>
                <li>Release 工作流程新增所有 PHP 檔案的語法檢查，通過後才建立 ZIP。</li>
            </ul>
            <h4>1.6.3</h4>
            <ul>
                <li>修正新增文章分類、商品分類及自訂分類法時可能發生的 AJAX 500 錯誤。</li>
                <li>分類排序不再介入新增／編輯分類的 AJAX、POST 與其他後台內部查詢。</li>
                <li>保留原生分類列表拖曳排序與選用的前台分類排序功能。</li>
            </ul>
            <h4>1.6.2</h4>
            <ul>
                <li>將登入頁面標誌與語言切換器設定集中到「隱藏登入頁」模組。</li>
                <li>新增登入頁背景色、背景圖片與登入框圓角設定，圓角預設為 15px。</li>
                <li>修正外掛列表重複顯示「檢視詳細資料」連結。</li>
                <li>詳細資料視窗會附加最新 GitHub Release 說明，讓變更紀錄與發行內容同步。</li>
            </ul>
            <h4>1.6.1</h4>
            <ul>
                <li>在外掛列表加入「設定」快捷按鈕。</li>
                <li>補齊外掛詳細資料、作者、外掛首頁與官方網站連結。</li>
                <li>更新 WordPress 詳細資料視窗的功能說明與變更紀錄。</li>
            </ul>
            <h4>1.6.0</h4>
            <ul>
                <li>重新設計白色科技感維護頁與即時預覽。</li>
                <li>修正隱藏登入頁面的欄位可讀性、網址規則與系統請求相容性。</li>
            </ul>
            <h4>1.5.9</h4>
            <ul>
                <li>新增網站優化模組與 WooCommerce 預設商品分類後台修復。</li>
            </ul>
            <h4>1.5.8</h4>
            <ul>
                <li>改善手機文章目錄的靠左對齊、字級與縮排。</li>
            </ul>
            <h4>1.5.7</h4>
            <ul>
                <li>修正文章、頁面、商品、分類及自訂內容類型的原生列表拖曳排序。</li>
                <li>文章優化模組更名為「文章瀏覽及目錄」。</li>
            </ul>';
        if ($release_notes !== '') {
            $changelog .= '<hr><h4>GitHub Release 說明</h4>' . $release_notes;
        }

        return (object) [
            'name' => 'WU Toolbox Modular',
            'slug' => 'wu-toolbox-modular',
            'version' => $version,
            'author' => '<a href="https://wumetax.com/" target="_blank" rel="noopener noreferrer">WUMETAX</a>',
            'author_profile' => 'https://wumetax.com/',
            'homepage' => 'https://wumetax.com/',
            'short_description' => '依需求開啟獨立功能的 WordPress 模組化工具箱。',
            'requires' => '5.8',
            'tested' => get_bloginfo('version'),
            'requires_php' => '7.4',
            'download_link' => $download,
            'external' => true,
            'sections' => [
                'description' => '
                    <h3>模組化 WordPress 工具箱</h3>
                    <p>WU Toolbox Modular 將常用的內容管理、安全性、效能優化、後台介面、監控及 WooCommerce 工具集中在同一個外掛中。</p>
                    <p>每個模組皆可獨立開啟或關閉；未啟用的模組不會載入功能程式，避免不必要的網站負擔。</p>
                    <h4>主要特色</h4>
                    <ul>
                        <li>從 WU Toolbox 主畫面集中管理所有模組。</li>
                        <li>統一的 WordPress 後台介面與繁體中文說明。</li>
                        <li>支援內容管理、安全防護、監控、匯入匯出與 WooCommerce 工具。</li>
                        <li>透過 GitHub Release 安全取得新版 ZIP 更新。</li>
                    </ul>
                    <p><a href="https://wumetax.com/" target="_blank" rel="noopener noreferrer">造訪 WUMETAX 外掛網站</a></p>',
                'installation' => '
                    <ol>
                        <li>安裝並啟用 WU Toolbox Modular。</li>
                        <li>前往 WordPress 後台的「WU Toolbox」。</li>
                        <li>只開啟網站需要的模組，再點選各卡片的「設定」。</li>
                    </ol>',
                'changelog' => $changelog,
                'faq' => '
                    <h4>關閉的模組會影響網站效能嗎？</h4>
                    <p>不會。未啟用的模組不會載入其功能檔案。</p>
                    <h4>如何檢查更新？</h4>
                    <p>可在 WordPress 外掛列表或「控制台 → 更新」執行檢查；新版由 GitHub Release 提供。</p>',
            ],
        ];
    }

    public function download_release_asset($reply, $package, $upgrader) {
        $release = $this->get_release();
        if (!$release || !in_array($package, [$release['asset_api_url'], ($release['browser_url'] ?? '')], true)) return $reply;
        $response = wp_remote_get($package, ['timeout' => 45, 'redirection' => 5, 'headers' => ['Accept' => 'application/octet-stream', 'User-Agent' => $this->user_agent()]]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return is_wp_error($response) ? $response : new WP_Error('wutm_release_download_failed', __('無法下載 GitHub Release 更新檔。', 'wu-toolbox-modular'));
        }
        $file = wp_tempnam(self::ASSET_NAME);
        if (!$file || false === file_put_contents($file, wp_remote_retrieve_body($response))) {
            return new WP_Error('wutm_release_write_failed', __('無法建立更新暫存檔。', 'wu-toolbox-modular'));
        }
        return $file;
    }

    public function clear_cache_before_update(): void {
        delete_site_transient(self::CACHE_KEY);
    }

    private function get_release(): ?array {
        $cached = get_site_transient(self::CACHE_KEY);
        if (is_array($cached)) return $cached ?: null;
        $response = wp_remote_get('https://api.github.com/repos/' . self::REPOSITORY . '/releases/latest', ['timeout' => 12, 'redirection' => 3, 'headers' => ['Accept' => 'application/vnd.github+json', 'User-Agent' => $this->user_agent()]]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            set_site_transient(self::CACHE_KEY, [], self::CACHE_TTL);
            return null;
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        $tag = is_array($data) ? ltrim((string) ($data['tag_name'] ?? ''), 'vV') : '';
        $asset = null;
        foreach ((array) ($data['assets'] ?? []) as $candidate) {
            if (($candidate['name'] ?? '') === self::ASSET_NAME && !empty($candidate['url'])) {$asset = $candidate; break;}
        }
        if (!$asset || !preg_match('/^\d+(?:\.\d+){1,3}(?:[-+][0-9A-Za-z.-]+)?$/', $tag)) {
            set_site_transient(self::CACHE_KEY, [], self::CACHE_TTL);
            return null;
        }
        $release = ['version' => $tag, 'asset_api_url' => esc_url_raw($asset['url']), 'browser_url' => esc_url_raw((string) ($asset['browser_download_url'] ?? '')), 'html_url' => esc_url_raw((string) ($data['html_url'] ?? 'https://github.com/' . self::REPOSITORY . '/releases')), 'body' => (string) ($data['body'] ?? '')];
        set_site_transient(self::CACHE_KEY, $release, self::CACHE_TTL);
        return $release;
    }

    private function user_agent(): string { return 'WU-Toolbox-Modular/' . WUTM_VERSION . '; ' . home_url('/'); }
}
new WUTM_GitHub_Release_Updater(WUTM_FILE);
