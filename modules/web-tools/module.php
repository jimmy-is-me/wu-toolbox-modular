<?php
defined('ABSPATH') || exit;

add_action('admin_menu', static function (): void {
    add_menu_page('其他網頁工具', '其他網頁工具', 'manage_options', 'wu-web-tools', 'wutm_render_web_tools', '', 99999);
});

function wutm_render_web_tools(): void {
    if (!current_user_can('manage_options')) return;

    $tools = [
        ['網域查詢', 'https://domain-lookup.wumetax.com/', 'https://wumetax.com/lab/domain-lookup/'],
        ['SafeShot 截圖隱私', 'https://safeshot.wumetax.com/', 'https://wumetax.com/lab/safeshot-privacy/'],
        ['圖片最佳化', 'https://image-optimizer.wumetax.com/', 'https://wumetax.com/lab/image-optimizer/'],
        ['轉址檢查', 'https://redirect-checker.wumetax.com/', 'https://wumetax.com/lab/redirect-checker/'],
        ['Quick Record', 'https://quick-record.wumetax.com/', 'https://wumetax.com/lab/quick-record/'],
        ['線上數位時鐘', 'https://digital-clock-online.wumetax.com/', 'https://wumetax.com/lab/digital-clock-online/'],
        ['WuPDF', 'https://wupdf.wumetax.com/', 'https://wumetax.com/lab/wupdf/'],
        ['網站圖示偵測', 'https://favicon-detector.wumetax.com/', 'https://wumetax.com/lab/favicon-detector/'],
        ['HTML Lab', 'https://html-lab.wumetax.com/', 'https://wumetax.com/lab/html-lab-frontend-playground/'],
        ['浮水印工具', 'https://watermark.wumetax.com/watermark-tool', 'https://wumetax.com/lab/watermark-tool/'],
    ];
    wutm_admin_page_open('其他網頁工具', '開啟 Wumetax 網頁工具與使用說明。');
    ?>
        <table class="widefat striped">
            <thead><tr><th scope="col">工具</th><th scope="col">入口</th></tr></thead>
            <tbody>
            <?php foreach ($tools as [$name, $url, $guide]): ?>
                <tr>
                    <th scope="row"><?php echo esc_html($name); ?></th>
                    <td>
                        <a class="button" href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener noreferrer">開啟工具</a>
                        <a class="button" href="<?php echo esc_url($guide); ?>" target="_blank" rel="noopener noreferrer">使用說明</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php
    wutm_admin_page_close();
}
