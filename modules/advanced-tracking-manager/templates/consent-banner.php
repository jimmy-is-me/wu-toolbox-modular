<?php
defined('ABSPATH') || exit;
$layout = $layout ?? 'inline';
$appearance = $appearance ?? 'light';
$compact = $compact ?? false;
$banner_tag = in_array($layout, ['center','side'], true) ? 'dialog' : 'section';
?>
<button type="button" class="wutm-atm-privacy-launcher wutm-atm-privacy--<?php echo esc_attr($appearance); ?>" data-wutm-privacy-open aria-controls="wutm-atm-privacy-dialog" aria-haspopup="dialog" hidden>Cookie 設定</button>
<<?php echo $banner_tag; ?> id="wutm-atm-privacy" class="wutm-atm-privacy wutm-atm-privacy--<?php echo esc_attr($layout); ?> wutm-atm-privacy--<?php echo esc_attr($appearance); ?>" aria-labelledby="wutm-atm-privacy-title"<?php if ($compact) echo ' data-wutm-privacy-compact'; ?> hidden>
    <div class="wutm-atm-privacy-copy">
        <h2 id="wutm-atm-privacy-title"><?php echo esc_html($settings['title']); ?></h2>
        <p><?php echo nl2br(esc_html($settings['description'])); ?>
            <?php if ($url) : ?><a href="<?php echo esc_url($url); ?>">隱私權政策</a><?php endif; ?>
        </p>
    </div>
    <div class="wutm-atm-privacy-actions">
        <button type="button" data-wutm-privacy-choice="reject"><?php echo esc_html($settings['reject']); ?></button>
        <button type="button" data-wutm-privacy-choice="accept"><?php echo esc_html($settings['accept']); ?></button>
        <button type="button" data-wutm-privacy-open><?php echo esc_html($settings['preferences']); ?></button>
    </div>
    <p class="wutm-atm-privacy-status" role="status" hidden></p>
</<?php echo $banner_tag; ?>>
<dialog id="wutm-atm-privacy-dialog" class="wutm-atm-privacy-dialog wutm-atm-privacy--<?php echo esc_attr($appearance); ?>" aria-labelledby="wutm-atm-privacy-dialog-title">
    <header class="wutm-atm-privacy-dialog-header">
        <h2 id="wutm-atm-privacy-dialog-title"><?php echo esc_html($settings['title']); ?></h2>
        <button type="button" data-wutm-privacy-close aria-label="關閉偏好設定">關閉</button>
    </header>
    <p class="wutm-atm-privacy-description"><?php echo nl2br(esc_html($settings['description'])); ?>
        <?php if ($url) : ?><a href="<?php echo esc_url($url); ?>">隱私權政策</a><?php endif; ?>
    </p>
    <p class="wutm-atm-privacy-default" data-wutm-privacy-default hidden>下方顯示本站的預設偏好。您可立即拒絕，或分別調整分析與行銷。</p>
    <div class="wutm-atm-privacy-category">
        <strong>必要</strong><span>永遠啟用</span>
        <p>用於登入、購物車、結帳及保存您的偏好。</p>
    </div>
    <label class="wutm-atm-privacy-category">
        <strong>分析</strong><input type="checkbox" name="analytics">
        <span>讓網站了解流量與使用方式。</span>
    </label>
    <label class="wutm-atm-privacy-category">
        <strong>行銷</strong><input type="checkbox" name="marketing">
        <span>讓網站衡量廣告及轉換成效。</span>
    </label>
    <div class="wutm-atm-privacy-actions">
        <button type="button" data-wutm-privacy-choice="reject"><?php echo esc_html($settings['reject']); ?></button>
        <button type="button" data-wutm-privacy-choice="accept"><?php echo esc_html($settings['accept']); ?></button>
        <button type="button" data-wutm-privacy-choice="custom" class="wutm-atm-privacy-primary"><?php echo esc_html($settings['save']); ?></button>
    </div>
    <p class="wutm-atm-privacy-status" role="status" hidden></p>
</dialog>
