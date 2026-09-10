<?php
defined('ABSPATH') || exit;

final class WUTM_GitHub_Release_Updater {
    private const REPOSITORY = 'jimmy-is-me/wu-toolbox-modular';
    private const ASSET_NAME = 'wu-toolbox-modular.zip';
    private const CACHE_KEY = 'wutm_github_release_v2';
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
        $current_version = self::normalize_version((string) $transient->checked[$this->plugin_basename]) ?: self::normalize_version(WUTM_VERSION);
        if (isset($transient->response) && is_array($transient->response) && isset($transient->response[$this->plugin_basename])) {
            $stale_item = $transient->response[$this->plugin_basename];
            $stale_version = self::normalize_version((string) (is_object($stale_item) ? ($stale_item->new_version ?? '') : (is_array($stale_item) ? ($stale_item['new_version'] ?? '') : '')));
            if ($stale_version === '' || !version_compare($stale_version, $current_version, '>')) unset($transient->response[$this->plugin_basename]);
        }
        $release = $this->get_release();
        if (!$release || !version_compare(self::normalize_version($release['version']), $current_version, '>')) return $transient;

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
            <h4>2.1.8</h4><ul><li>隱藏行銷概觀時，WooCommerce 行銷主選單會直接前往折價券，不再開啟行銷概觀。</li><li>新增使用者角色管理與清理模組，可檢查角色、遷移成員及刪除無人使用的自訂角色。</li><li>保護 WordPress 與 WooCommerce 核心角色，並以權限、Nonce、操作後轉址及管理員防鎖定機制保護角色異動。</li></ul>
            <h4>2.0.7</h4><ul><li>功能搜尋改為列出全部符合項目，使用者點選結果後才會定位卡片，不再自動跳到第一筆。</li><li>版本標籤新增綠色呼吸狀態燈；頁尾更新 Wumetax 主機管理與網站開發資訊及官方連結。</li><li>子選單分類分隔樣式加強，且只顯示具有已啟用子選單功能的分類。</li><li>外掛清單與更新資訊的作者名稱統一為 Wumetax。</li></ul>
            <h4>2.0.6</h4><ul><li>WU Toolbox 主頁新增功能搜尋列，支援 Enter／搜尋按鈕、名稱優先比對、平滑定位與醒目提示。</li><li>重新設計無圖示的 WU Toolbox 標題區，改善版本標籤、間距、響應式版面與視覺層次。</li><li>通知整理工具現在連錯誤等重要通知也保持收合，仍會顯示重要通知數量。</li><li>WU Toolbox 子選單依模組分類加入分隔標題，並讓銀行轉帳等自訂設定入口排列於正確分類。</li></ul>
            <h4>2.0.5</h4><ul><li>銀行轉帳對帳中心只保留於 WU Toolbox 子選單，移除電商系統下的重複入口。</li><li>完成正式模組的無限迴圈與記憶體風險靜態健檢；使用者匯出改為每批 200 筆串流處理，留言清除改為有進度與次數上限的分批處理。</li><li>ACF 巢狀欄位檢視加入安全深度上限，避免異常循環欄位結構耗盡記憶體。</li></ul>
            <h4>2.0.4</h4><ul><li>修正禁止訪客結帳時 HTML 標籤被直接顯示，移除原生登入表單下方的重複提示。</li><li>銀行轉帳對帳中心新增 WU Toolbox 子選單入口，並保留電商系統下的既有入口。</li></ul>
            <h4>2.0.3</h4><ul><li>新增電商工具「結帳登入優化」，美化傳統 WooCommerce 結帳登入表單並支援直接展開。</li><li>可自訂登入提示、禁止訪客結帳提示及建立帳號密碼欄位文字，保留 WooCommerce 原生登入與安全驗證流程。</li><li>帳號及隱私權設定頁新增繁體中文用途指引，並在禁止訪客結帳時保留可操作的登入入口。</li></ul>
            <h4>2.0.2</h4><ul><li>新增電商工具「折價券優化」，提供購物車與結帳頁可用券卡片及一鍵套用功能。</li><li>新增訪客登入提示、會員中心「我的折價券」、推廣連結、QR Code 與使用率統計。</li><li>可用券優先透過 WooCommerce 原生折扣規則驗證，並限制顯示張數、清理套用網址及補齊安全輸出。</li></ul>
            <h4>2.0.1</h4><ul><li>新增電商工具「列印訂單收據」，提供管理員、會員與訪客訂單的安全列印入口。</li><li>新增繁體中文專業收據版面、響應式顯示與列印專用樣式，完整列出商品、訂單總計、買受人及配送資料。</li><li>支援 HPOS 訂單列表按鈕、收據抬頭與顯示欄位設定，並使用訂單原始幣別與安全權限驗證。</li></ul>
            <h4>2.0.0</h4><ul><li>銀行轉帳對帳明細新增訂單收款帳戶，可由顧客回報時選擇或由管理員指定，並保存當時帳戶資料快照。</li><li>訂單狀態管理新增自訂狀態建立功能，新狀態同步顯示於 WooCommerce 狀態與批次操作選單。</li><li>新增「訂單狀態遷移工具」，支援預覽、HPOS／傳統儲存偵測、安全分批遷移與執行紀錄。</li></ul>
            <h4>1.9.8</h4><ul><li>修正自訂登入頁載入 WordPress 核心登入程式時，PHP 8.x 出現未定義 $error 與 $user_login 變數警告。</li><li>保留登入、登出、忘記密碼與密碼重設網址的完整改寫，並限制只替換真正的 wp-login.php 路徑。</li><li>加入登入入口重新導向防循環及子目錄安裝路徑相容處理。</li></ul>
            <h4>1.9.7</h4><ul><li>ATM 轉帳優化新增美化前台匯款卡片與銀行轉帳對帳中心。</li><li>新增獨立「運送優化功能」模組，接管台灣離島與 7-11 超商取貨設定。</li><li>WC 優化工具列出地址欄位調整內容，並清楚標示電子發票僅顯示資訊、無服務串接。</li></ul>
            <h4>1.9.6</h4><ul><li>新增「顯示網站所有欄位」與「ATM 轉帳優化」模組。</li><li>修正台灣地址下拉選單回填後仍被判定為空值或無效地址的問題。</li><li>移除 Cloudflare Turnstile、樹狀頁面視圖及交貨日期和時段模組。</li></ul>
            <h4>1.9.5</h4><ul><li>交貨日期和時段新增 WooCommerce 區塊結帳支援，傳統與新版結帳皆可使用。</li><li>修正 Cloudflare Turnstile 與樹狀頁面視圖的模組載入時機，完整啟用表單驗證、設定頁、樹狀選單與拖曳功能。</li></ul>
            <h4>1.9.4</h4><ul><li>新增中文「交貨日期和時段」模組，可設定最早交貨日、可預約天數、不交貨星期與自訂時段。</li><li>交貨安排會儲存至 WooCommerce 訂單，並顯示於後台與訂單通知。</li></ul>
            <h4>1.9.3</h4><ul><li>重寫 Cloudflare Turnstile 設定頁，可直接設定金鑰、保護表單與測試連線。</li><li>重寫樹狀頁面視圖設定頁，可選擇要顯示樹狀檢視的內容類型。</li></ul>
            <h4>1.9.2</h4><ul><li>修正 Cloudflare Turnstile 與樹狀頁面視圖設定頁的核心註冊與連結網址。</li><li>WooCommerce 更名為「電商系統」，保留原始子選單並在隱藏首頁時導向訂單。</li><li>確認綠界與 PAYUNi 模組維持原有金流、物流與電子發票載入流程。</li></ul>
            <h4>1.9.1</h4><ul><li>隱藏 WC 首頁時，WooCommerce 主選單會改為直接前往訂單。</li><li>隱藏 WC 工具頁新增一鍵開啟與一鍵關閉全部設定。</li><li>修正樹狀頁面視圖與 Cloudflare Turnstile 的設定頁註冊，確保啟用後可開啟設定。</li></ul>
            <h4>1.9.0</h4><ul><li>整理並移除誤以 Windows 路徑分隔符號建立在專案根目錄的重複檔案。</li><li>移除開發期間遺留的 tests 驗證檔案，發佈包僅保留正式外掛內容。</li></ul>
            <h4>1.8.9</h4><ul><li>修正樹狀頁面視圖設定頁偶發未註冊而顯示權限不足的問題。</li><li>修正台灣地址結帳重新整理時清空既有縣市／鄉鎮值，並避免已儲存公司名稱與鄉鎮市區出現錯誤必填提示。</li><li>完整分頁匯出訂單與全部安全使用者自訂欄位，補齊使用者及訂單核心資料。</li><li>將舊密碼雜湊工具移至 CSV 使用者匯入／匯出區塊下方，並取消還原筆數上限。</li></ul>
            <h4>1.8.8</h4><ul><li>修正樹狀頁面視圖設定入口，改由 WU Toolbox 直接註冊並處理設定頁。</li><li>完整移除紅利點數模組、卡片、載入旗標與內嵌來源程式。</li><li>分類順序固定為後台介面第一、內容管理第二、特殊工具最後。</li><li>檢查綠界付款、物流與電子發票載入流程，並修正區塊結帳電子發票編輯器資源路徑。</li></ul>
            <h4>1.8.7</h4><ul><li>修正預設精選圖片設定頁未載入媒體庫選擇器的問題。</li><li>修正樹狀頁面視圖設定入口，並補齊首次啟用的頁面選單及拖曳權限初始化。</li><li>新增電商工具「紅利點數」模組，整合 Simple Points and Rewards 2.1.0 的購物累積、兌換及會員紀錄功能。</li></ul>
            <h4>1.8.6</h4><ul><li>隱藏 WC 工具的「隱藏銷售通路篩選」現在會同步隱藏訂單列表的「建立來源」篩選器（filter-by-created-via）。</li><li>玉山銀行金流工具新增簽約提醒：本模組僅提供程式串接整合，商家仍須自行與玉山銀行簽約。</li><li>第三方外掛卡片會偵測已啟用的官方外掛並自動標示「已開啟」，不再要求重複啟用 WU 安裝入口。</li><li>新增 Cloudflare Turnstile 模組，支援 WordPress、WooCommerce 與相容表單的驗證設定。</li><li>新增樹狀頁面視圖模組，可於頁面管理中以樹狀結構檢視、編輯及拖曳排序頁面。</li></ul>
            <h4>1.8.5</h4><ul><li>修正綠界工具設定按鈕，直接開啟綠界主要設定頁。</li><li>修正結帳頁寄送到其他地址的舊 HTML 標籤殘留；現在一律顯示「需寄送到其他收件地址」。</li><li>訂單狀態管理新增停用狀態，並同步移除後台訂單篩選與傳統／HPOS 批次操作中的對應項目。</li><li>隱藏 WC 工具新增「隱藏銷售通路篩選」。</li></ul>
            <h4>1.8.4</h4><ul><li>綠界工具改為採用官方 WooCommerce 綠界整合來源，提供付款、物流、電子發票及區塊結帳相容能力。</li><li>新增訂單狀態管理，可檢視所有現有狀態並同步調整後台訂單、我的帳號與顧客訂單通知標題的顯示名稱。</li><li>修正結帳頁「需寄送到其他收件地址」文字被顯示為 HTML，並將 billing_first_name 明確標示為帳單姓名。</li></ul>
            <h4>1.8.3</h4><ul><li>新增電商工具卡片：綠界金流/物流/電子發票工具，整合付款、超商與宅配物流及電子發票設定。</li><li>新增電商工具卡片：玉山銀行金流工具，支援信用卡一次付清及分期付款。</li><li>模組按需載入並保留來源授權。付款、物流與發票尚未實際交易測試，正式使用前請先以測試環境驗證。</li></ul>
            <h4>1.8.2</h4><ul><li>啟用隱藏行銷概觀時，行銷主選單改連至折價券。</li><li>WooCommerce 優化器更名為隱藏WC工具。</li><li>新增電商工具卡片 WC優化工具，獨立管理台灣地址、離島運送、7-11 取貨、訂單備註與電子發票，沿用原設定。</li></ul><h4>1.8.1</h4><ul><li>WooCommerce 選單改為僅隱藏，保留 wc-admin 頁面註冊及存取。</li><li>新增隱藏行銷概觀、整合、嵌入式推廣區、更多付款選項、官方付款推薦、主選單付款與報表。</li><li>WooCommerce 頁尾預設顯示 Woocommerce X Wumetax，可取消。</li><li>第三方安裝卡片不再顯示 WU Toolbox 子選單；修正 WooCommerce 與優化器名稱配對。</li></ul>
            <h4>1.8.0</h4>
            <ul><li>第三方外掛標籤統一採用開發中標籤的圓角樣式。</li><li>WooCommerce 優化器移至電商工具。</li><li>新增獨立隱藏 WC 首頁、擴充功能、狀態、銷售時點情報系統與進階設定分頁的選項，預設關閉，僅整理後台介面。</li></ul>
            <h4>1.7.9</h4>
            <ul>
                <li>新增 WooCommerce、WPCode、TranslatePress、GTranslate、Loco Translate 第三方官方外掛快速安裝卡片。</li>
                <li>WooCommerce 排在電商工具首位；網站翻譯依序為 TranslatePress、多語言管理、GTranslate、Loco Translate。</li>
                <li>新增遺失商品圖片修復：掃描商品完整／簡短描述，從 A 來源站補回本站 uploads 同路徑缺檔，支援僅掃描、修復、停止與選擇登錄媒體庫。</li>
                <li>圖片修復加入安全下載、實際 MIME 與路徑驗證、單圖分批處理、不覆蓋既有檔案及結果紀錄。</li>
                <li>全站文字取代、資料匯入／匯出、網站修復工具移至特殊工具；網站優化設定更名為網站修復工具。</li>
            </ul>
            <h4>1.7.8</h4>
            <ul>
                <li>修正頁面及階層式內容清單重複顯示拖曳排序按鈕。</li>
                <li>修正通知整理漏收 wrap 內通知與 WordPress 搬移通知的時序問題；保留隱藏連線警告與行內提示，關閉通知後同步數量。</li>
                <li>新增 WP Downgrade（特殊工具）與 Wordfence（安全性）第三方官方外掛快速安裝卡片。</li>
                <li>停用所有更新、增強下載器移至後台介面分類。</li>
                <li>更新媒體編碼器卡片說明：上傳時可先等比例縮小圖片，再轉換為 WebP。</li>
            </ul>
            <h4>1.7.7</h4>
            <ul>
                <li>新增 Rank Math SEO、FluentSMTP、UpdraftPlus 與 WPvivid 的第三方官方外掛快速安裝卡片。</li>
                <li>新卡片會透過 WordPress 原生流程從 WordPress.org 安裝及啟用官方外掛，不會內建、重製或修改其功能。</li>
                <li>Instant Images 改列入「媒體工具」；郵件追蹤改列入「郵件工具」；浮動聯絡按鈕與首頁彈出視窗改列入「前台介面」。</li>
                <li>移除「常用外掛」分類與「常用外掛管理」模組。</li>
            </ul>
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
            'author' => '<a href="https://wumetax.com/" target="_blank" rel="noopener noreferrer">Wumetax</a>',
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
                    <p><a href="https://wumetax.com/" target="_blank" rel="noopener noreferrer">造訪 Wumetax 外掛網站</a></p>',
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
        $tag = is_array($data) ? self::normalize_version((string) ($data['tag_name'] ?? '')) : '';
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

    private static function normalize_version(string $version): string {
        $version = trim($version);
        return preg_replace('/^[vV]\s*/', '', $version) ?: '';
    }
}
new WUTM_GitHub_Release_Updater(WUTM_FILE);
