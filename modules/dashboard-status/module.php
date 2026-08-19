<?php
/**
 * Module: dashboard-status
 * Loaded only when enabled in WU Toolbox Modular.
 */
?>
if (!defined('ABSPATH')) exit;

/*
 * WumetaxToolkit - Professional Client Dashboard
 * Version: 12.2 - Layout Width Fix
 * 
 * CHANGELOG:
 * - Removed width 100% for postbox-container-1 on desktop
 * - Kept all features including referral section toggle
 */

// ===== Menu Registration =====

add_action('admin_menu', function() {
	add_submenu_page(
		'wumetax-toolkit',
		'儀表板設定',
		'儀表板設定',
		'manage_options',
		'wu-dashboard-status',
		'wu_dashboard_settings_page'
	);
}, 999);

// ===== Options Initialization =====

add_action('admin_init', function() {
	add_option('wu_dashboard_enabled', 1);
	add_option('wu_dashboard_site_status', 'normal');
	add_option('wu_dashboard_status_note', '');
	add_option('wu_dashboard_backup_status', 'normal');
	add_option('wu_dashboard_security_status', 'normal');
	add_option('wu_dashboard_recent_work', array());
	add_option('wu_dashboard_services', array(
		'定期備份採每日備份,保留三份,緊急還原使用',
		'效能與速度優化:圖片最佳化 WebP、快取設定',
		'24 小時網站狀態監測',
		'網站異常處理與救援',
		'SEO 與基本分析支援(Google Search Console 錯誤排除、網站結構與索引問題檢查)',
		'使用 Cloudflare CDN 加速全球訪問',
		'99% 正常運轉時間保證',
		'企業級防火牆防護(7G Firewall / AI Bot Protection)'
	));
	add_option('wu_dashboard_value_services', array());
	add_option('wu_dashboard_hosting_plan', 'image');
	add_option('wu_dashboard_hosting_rating', '優良運作');
	add_option('wu_dashboard_disk_quota', 5120);
	add_option('wu_dashboard_payments', array());
	add_option('wu_dashboard_referrals', array());
	add_option('wu_dashboard_referral_enabled', 0);
	add_option('wu_dashboard_referral_content', '');
	add_option('wu_dashboard_advanced_plan', 0);
	add_option('wu_dashboard_support_tickets', array());
	add_option('wu_dashboard_contact_company', 'WUMETAX 末特數位科技');
	add_option('wu_dashboard_contact_email', 'contact@wumetax.com');
	add_option('wu_dashboard_contact_line', 'https://lin.ee/Lut7wCe');
});

// ===== Dashboard Widget =====

add_action('wp_dashboard_setup', function() {
	if (!get_option('wu_dashboard_enabled', 1)) {
		return;
	}
	
	wp_add_dashboard_widget(
		'wu_unified_dashboard',
		'網站維運管理儀表板',
		'wu_render_unified_dashboard',
		null,
		null,
		'normal',
		'high'
	);
});

// ===== Unified Dashboard Renderer =====

function wu_render_unified_dashboard() {
	$status = get_option('wu_dashboard_site_status', 'normal');
	$status_note = get_option('wu_dashboard_status_note', '');
	$backup_status = get_option('wu_dashboard_backup_status', 'normal');
	$security_status = get_option('wu_dashboard_security_status', 'normal');
	$ssl_info = wu_get_ssl_info();
	$php_info = wu_get_php_info();
	$hosting_plan = get_option('wu_dashboard_hosting_plan', 'image');
	$hosting_rating = get_option('wu_dashboard_hosting_rating', '優良運作');
	$disk_info = wu_get_disk_info();
	$services = get_option('wu_dashboard_services', array());
	$value_services = get_option('wu_dashboard_value_services', array());
	$recent_work = get_option('wu_dashboard_recent_work', array());
	$payments = get_option('wu_dashboard_payments', array());
	$referrals = get_option('wu_dashboard_referrals', array());
	$referral_enabled = get_option('wu_dashboard_referral_enabled', 0);
	$referral_content = get_option('wu_dashboard_referral_content', '');
	$advanced_plan = get_option('wu_dashboard_advanced_plan', 0);
	$support_tickets = get_option('wu_dashboard_support_tickets', array());
	$contact_company = get_option('wu_dashboard_contact_company', 'WUMETAX 末特數位科技');
	$contact_email = get_option('wu_dashboard_contact_email', 'contact@wumetax.com');
	$contact_line = get_option('wu_dashboard_contact_line', 'https://lin.ee/Lut7wCe');
	$domain_name = parse_url(home_url(), PHP_URL_HOST);
	
	$status_config = array(
		'normal' => array('label' => '正常運作', 'color' => '#46b450'),
		'watching' => array('label' => '觀察中', 'color' => '#f0b849'),
		'handling' => array('label' => '處理中', 'color' => '#00a0d2')
	);
	$current_status = $status_config[$status] ?? $status_config['normal'];
	
	$plan_names = array(
		'onepage' => '一頁式主機',
		'image' => '形象網站主機',
		'ecommerce' => '電商主機'
	);
	$plan_name = $plan_names[$hosting_plan] ?? '標準主機';
	
	if (!empty($recent_work)) {
		usort($recent_work, function($a, $b) {
			return strtotime($b['date']) - strtotime($a['date']);
		});
	}
	
	if (!empty($payments)) {
		usort($payments, function($a, $b) {
			return strtotime($b['date']) - strtotime($a['date']);
		});
	}
	
	if (!empty($support_tickets)) {
		usort($support_tickets, function($a, $b) {
			return $b['timestamp'] - $a['timestamp'];
		});
	}
	
	?>
	<div class="wu-dashboard-container">
		
		<!-- 網站狀態總覽 -->
		<div class="wu-section">
			<h3 class="wu-section-title">
				網站狀態總覽
				<span class="wu-monitoring-badge">系統自動監控中</span>
			</h3>
			<div class="wu-status-card" style="border-left-color:<?php echo $current_status['color']; ?>;">
				<div class="wu-status-main">
					<div class="wu-status-badge" style="background:<?php echo $current_status['color']; ?>;">
						<?php echo esc_html($current_status['label']); ?>
					</div>
					<?php if (!empty($status_note)): ?>
					<div class="wu-status-note"><?php echo nl2br(esc_html($status_note)); ?></div>
					<?php endif; ?>
				</div>
			</div>
			
			<table class="wu-info-table">
				<tbody>
					<tr>
						<th>網域名稱</th>
						<td><strong><?php echo esc_html($domain_name); ?></strong></td>
						<td class="wu-info-meta">DNS 託管:Cloudflare 管理</td>
					</tr>
					<tr>
						<th>SSL 安全憑證</th>
						<td>
							<span class="wu-ssl-status" style="color:<?php echo $ssl_info['color']; ?>;">
								<?php echo esc_html($ssl_info['status']); ?>
							</span>
						</td>
						<td class="wu-info-meta"><?php echo esc_html($ssl_info['description']); ?></td>
					</tr>
					<tr>
						<th>PHP 版本</th>
						<td>
							<strong><?php echo esc_html($php_info['version']); ?></strong>
							<span class="<?php echo esc_attr($php_info['badge_class']); ?>">
								<?php echo esc_html($php_info['badge_text']); ?>
							</span>
						</td>
						<td class="wu-info-meta"><?php echo esc_html($php_info['description']); ?></td>
					</tr>
					<tr>
						<th>主機方案</th>
						<td><strong><?php echo esc_html($plan_name); ?></strong></td>
						<td class="wu-info-meta">評估:<?php echo esc_html($hosting_rating); ?></td>
					</tr>
					<tr>
						<th>備份狀態</th>
						<td>
							<span class="wu-status-indicator" style="color:<?php echo $backup_status === 'normal' ? '#46b450' : '#dc3232'; ?>;">
								<?php echo $backup_status === 'normal' ? '正常' : '異常'; ?>
							</span>
						</td>
						<td class="wu-info-meta">每日自動備份,保留 3 份</td>
					</tr>
					<tr>
						<th>安全狀態</th>
						<td>
							<span class="wu-status-indicator" style="color:<?php echo $security_status === 'normal' ? '#46b450' : '#dc3232'; ?>;">
								<?php echo $security_status === 'normal' ? '正常' : '異常'; ?>
							</span>
						</td>
						<td class="wu-info-meta">防火牆與安全監控運作中</td>
					</tr>
				</tbody>
			</table>
		</div>
		
		<!-- 磁碟使用狀況 -->
		<div class="wu-section">
			<h3 class="wu-section-title">磁碟使用狀況</h3>
			<div class="wu-disk-grid">
				<div class="wu-disk-item">
					<div class="wu-disk-label">已使用</div>
					<div class="wu-disk-value" style="color:<?php echo $disk_info['color']; ?>;">
						<?php echo esc_html($disk_info['used_formatted']); ?>
					</div>
				</div>
				<div class="wu-disk-item">
					<div class="wu-disk-label">總配額</div>
					<div class="wu-disk-value"><?php echo esc_html($disk_info['quota_formatted']); ?></div>
				</div>
				<div class="wu-disk-item">
					<div class="wu-disk-label">剩餘空間</div>
					<div class="wu-disk-value" style="color:#46b450;">
						<?php echo esc_html($disk_info['remaining_formatted']); ?>
					</div>
				</div>
				<div class="wu-disk-item">
					<div class="wu-disk-label">使用率</div>
					<div class="wu-disk-value" style="color:<?php echo $disk_info['color']; ?>;">
						<?php echo esc_html($disk_info['percentage']); ?>%
					</div>
				</div>
			</div>
			<div class="wu-disk-bar">
				<div class="wu-disk-bar-fill" style="width:<?php echo min($disk_info['percentage'], 100); ?>%;background:<?php echo $disk_info['color']; ?>;"></div>
			</div>
			<div class="wu-disk-status <?php echo esc_attr($disk_info['status_class']); ?>">
				<?php echo esc_html($disk_info['status_text']); ?>
			</div>
			
			<?php if ($disk_info['percentage'] >= 100): ?>
			<div class="wu-notice wu-notice-error">
				<strong><?php echo $disk_info['percentage'] == 100 ? '磁碟已滿' : '磁碟容量超出配額'; ?></strong><br>
				立即聯繫升級 NVMe SSD 硬碟空間:<br>
				• +5 GB:NT$ 2,000 / 年<br>
				• +10 GB:NT$ 3,500 / 年
				<div style="margin-top:10px;">
					<button type="button" class="wu-button wu-button-danger" onclick="wuRequestDiskUpgrade()">
						立即申請磁碟升級
					</button>
				</div>
			</div>
			<?php endif; ?>
		</div>
		
		<!-- 基礎維護方案 -->
		<?php if (!empty($services)): ?>
		<div class="wu-section wu-section-basic-plan">
			<h3 class="wu-section-title">基礎維護方案</h3>
			<ul class="wu-basic-plan-list">
				<?php foreach ($services as $service): ?>
				<li>
					<span class="wu-check-icon"></span>
					<?php echo esc_html($service); ?>
				</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php endif; ?>
		
		<!-- 加值服務 -->
		<?php if (!empty($value_services)): ?>
		<div class="wu-section">
			<h3 class="wu-section-title">
				加值服務
				<span class="wu-section-label" style="background:#00a32a;">已訂購</span>
			</h3>
			<ul class="wu-service-list">
				<?php foreach ($value_services as $service): ?>
				<li><?php echo esc_html($service); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php endif; ?>
		
		<!-- 進階維護方案 -->
		<?php if ($advanced_plan): ?>
		<div class="wu-section wu-section-highlight">
			<h3 class="wu-section-title">進階維護方案(已啟用)</h3>
			<ul class="wu-feature-list">
				<li>
					<strong>Object Storage 異地資料備援</strong>
					<span class="wu-feature-desc">最多保留 30 份系統備份,僅作系統還原使用</span>
				</li>
				<li>
					<strong>定期網站垃圾清理與資料庫基礎優化</strong>
					<span class="wu-feature-desc">維持網站高效運作</span>
				</li>
				<li>
					<strong>主機與網站狀態定期檢視</strong>
					<span class="wu-feature-desc">屬內部維運作業,未另行提供書面檢測報告</span>
				</li>
				<li>
					<strong>定期更新、漏洞修補</strong>
					<span class="wu-feature-desc">確保系統安全性</span>
				</li>
				<li>
					<strong>網站問題諮詢與技術回覆</strong>
					<span class="wu-feature-desc">於工作日 24 小時內回覆</span>
				</li>
				<li>
					<strong>提供所需模組授權金鑰並協助定期更新</strong>
					<span class="wu-feature-desc">保持功能最新狀態</span>
				</li>
			</ul>
		</div>
		<?php else: ?>
		<div class="wu-section wu-section-promo">
			<h3 class="wu-section-title">升級進階維護方案</h3>
			<div class="wu-promo-box">
				<div class="wu-promo-header">
					<div class="wu-promo-title">進階維護方案</div>
					<div class="wu-promo-price">NT$ 8,000 <span>/年(未稅)</span></div>
				</div>
				<ul class="wu-promo-list">
					<li>Object Storage 異地資料備援(保留 30 份備份)</li>
					<li>定期網站垃圾清理與資料庫基礎優化</li>
					<li>主機與網站狀態定期檢視</li>
					<li>定期更新、漏洞修補</li>
					<li>網站問題諮詢與技術回覆(工作日 24 小時內)</li>
					<li>提供所需模組授權金鑰並協助定期更新</li>
				</ul>
				<p class="wu-promo-note">升級進階維護方案,享受更完整的技術支援與資料安全保障</p>
				<button type="button" class="wu-button" onclick="wuRequestAdvancedPlan()">立即諮詢升級</button>
			</div>
		</div>
		<?php endif; ?>
		
		<!-- 推薦回饋專區 -->
		<?php if ($referral_enabled): ?>
		<div class="wu-section">
			<h3 class="wu-section-title">推薦回饋專區</h3>
			
			<?php if (!empty($referral_content)): ?>
			<!-- 自訂內容 -->
			<div class="wu-referral-custom-content">
				<?php echo wp_kses_post(wpautop($referral_content)); ?>
			</div>
			<?php else: ?>
			<!-- 預設內容 -->
			<div class="wu-referral-rules">
				<p><strong>推薦好友,共享優惠</strong></p>
				<div style="margin:15px 0;font-size:13px;color:#50575e;line-height:2;">
					<div style="margin-bottom:12px;">
						<span class="wu-referral-step">步驟 1</span>
						<strong>當您介紹朋友加入並確認開始使用我們的主機服務後,</strong><br>
						<span style="margin-left:20px;">→ 您可先獲得 <strong style="color:#2271b1;">1 個月</strong>的免費主機使用回饋。</span>
					</div>
					<div style="margin-bottom:12px;">
						<span class="wu-referral-step">步驟 2</span>
						<strong>之後只要該朋友持續使用滿一年,</strong><br>
						<span style="margin-left:20px;">→ 您將再多獲得 <strong style="color:#2271b1;">1 個月</strong>免費使用時間。</span>
					</div>
					<div style="margin-top:15px;padding:10px;background:#f0f6fc;border-left:3px solid #2271b1;">
						<strong>朋友用得越久,您免費使用時間也會越多。</strong>
					</div>
				</div>
				<p style="margin:10px 0 0 0;font-size:11px;color:#787c82;font-style:italic;">*回饋狀態由管理端確認後更新於此頁</p>
			</div>
			<?php endif; ?>
			
			<?php if (!empty($referrals)): ?>
			<div class="wu-card-grid">
				<?php foreach ($referrals as $referral): ?>
				<div class="wu-referral-card">
					<div class="wu-referral-name"><?php echo esc_html($referral['name']); ?></div>
					<div class="wu-referral-date"><?php echo esc_html(date('Y/m/d', strtotime($referral['date']))); ?></div>
					<div class="wu-referral-status">
						<?php if ($referral['rewarded']): ?>
							<span class="wu-badge wu-badge-success">已發放</span>
						<?php else: ?>
							<span class="wu-badge wu-badge-pending">處理中</span>
						<?php endif; ?>
					</div>
				</div>
				<?php endforeach; ?>
			</div>
			<?php else: ?>
			<div class="wu-empty-state">
				<p>目前尚無推薦紀錄</p>
				<p>歡迎推薦您的朋友,一起享受長期合作的優惠!</p>
			</div>
			<?php endif; ?>
		</div>
		<?php endif; ?>
		
		<!-- 最近處理紀錄(橫排) -->
		<?php if (!empty($recent_work)): ?>
		<div class="wu-section">
			<h3 class="wu-section-title">最近處理紀錄</h3>
			<div class="wu-card-grid">
				<?php foreach (array_slice($recent_work, 0, 6) as $work): ?>
				<div class="wu-record-card">
					<div class="wu-record-date"><?php echo esc_html(date('Y/m/d', strtotime($work['date']))); ?></div>
					<div class="wu-record-title"><?php echo esc_html($work['title']); ?></div>
					<?php if (!empty($work['note'])): ?>
					<div class="wu-record-note"><?php echo nl2br(esc_html($work['note'])); ?></div>
					<?php endif; ?>
				</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php endif; ?>
		
		<!-- 款項紀錄(橫排) -->
		<?php if (current_user_can('manage_options') && !empty($payments)): ?>
		<div class="wu-section">
			<h3 class="wu-section-title">款項紀錄</h3>
			<div class="wu-card-grid">
				<?php foreach (array_slice($payments, 0, 6) as $payment): ?>
				<div class="wu-payment-card">
					<div class="wu-payment-date"><?php echo esc_html(date('Y/m/d', strtotime($payment['date']))); ?></div>
					<div class="wu-payment-item"><?php echo esc_html($payment['item']); ?></div>
					<div class="wu-payment-amount">NT$ <?php echo number_format($payment['amount']); ?></div>
					<div class="wu-payment-status">
						<?php if ($payment['status'] === 'paid'): ?>
							<span class="wu-badge wu-badge-success">已付款</span>
						<?php else: ?>
							<span class="wu-badge wu-badge-warning">待付款</span>
						<?php endif; ?>
					</div>
				</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php endif; ?>
		
		<!-- 技術支援工單 -->
		<div class="wu-section">
			<h3 class="wu-section-title">技術支援工單</h3>
			<form id="wu-support-form" class="wu-support-form">
				<?php wp_nonce_field('wu_support_ticket', 'wu_support_nonce'); ?>
				<div class="wu-form-group">
					<label for="wu_support_email">您的 Email <span class="required">*</span></label>
					<input type="email" id="wu_support_email" name="email" class="wu-input" required 
						value="<?php echo esc_attr(wp_get_current_user()->user_email); ?>" placeholder="your@email.com">
				</div>
				<div class="wu-form-group">
					<label for="wu_support_subject">問題類型 <span class="required">*</span></label>
					<select id="wu_support_subject" name="subject" class="wu-select" required>
						<option value="">請選擇問題類型</option>
						<option value="網站異常">網站異常</option>
						<option value="功能諮詢">功能諮詢</option>
						<option value="備份還原">備份還原</option>
						<option value="其他問題">其他問題</option>
					</select>
				</div>
				<div class="wu-form-group">
					<label for="wu_support_message">問題描述 <span class="required">*</span></label>
					<textarea id="wu_support_message" name="message" class="wu-textarea" rows="6" required placeholder="請詳細描述您遇到的問題..."></textarea>
				</div>
				<div class="wu-form-actions">
					<button type="submit" class="wu-button wu-button-primary">
						<span class="wu-button-text">提交工單</span>
						<span class="wu-button-loading" style="display:none;">處理中...</span>
					</button>
				</div>
				<div id="wu-support-result"></div>
			</form>
			<div class="wu-support-note">
				<p><strong>注意事項:</strong></p>
				<ul>
					<li>收到工單後,我們將盡快安排處理(工作日 7 天內回覆)</li>
					<li>若問題超出現有服務範疇,將另行報價</li>
					<li>如長時間無回覆,請聯繫 <a href="https://lin.ee/Lut7wCe" target="_blank">LINE 官方帳號</a></li>
				</ul>
			</div>
			
			<!-- 工單提交紀錄 -->
			<?php if (!empty($support_tickets)): ?>
			<div class="wu-ticket-history">
				<h4 class="wu-ticket-history-title">工單提交紀錄</h4>
				<div class="wu-card-grid">
					<?php foreach (array_slice($support_tickets, 0, 9) as $ticket): ?>
					<div class="wu-ticket-card">
						<div class="wu-ticket-date"><?php echo esc_html(date('Y/m/d H:i', $ticket['timestamp'])); ?></div>
						<div class="wu-ticket-subject"><?php echo esc_html($ticket['subject']); ?></div>
						<div class="wu-ticket-id">
							<code class="wu-code">#<?php echo esc_html($ticket['ticket_id']); ?></code>
						</div>
					</div>
					<?php endforeach; ?>
				</div>
			</div>
			<?php endif; ?>
		</div>
		
		<!-- 聯絡資訊 -->
		<div class="wu-section wu-contact-section">
			<h3 class="wu-section-title">聯絡資訊</h3>
			<div class="wu-contact-list">
				<div class="wu-contact-item">
					<div class="wu-contact-label">公司名稱</div>
					<div class="wu-contact-value"><?php echo esc_html($contact_company); ?></div>
				</div>
				<div class="wu-contact-item">
					<div class="wu-contact-label">Email</div>
					<div class="wu-contact-value">
						<a href="mailto:<?php echo esc_attr($contact_email); ?>"><?php echo esc_html($contact_email); ?></a>
					</div>
				</div>
				<div class="wu-contact-item">
					<div class="wu-contact-label">LINE 官方帳號</div>
					<div class="wu-contact-value">
						<a href="<?php echo esc_url($contact_line); ?>" target="_blank"><?php echo esc_html($contact_line); ?></a>
					</div>
				</div>
			</div>
		</div>
		
	</div>
	
	<div class="wu-footer-meta">
		統計資料每 6-12 小時自動更新 | 資料更新不影響後台載入速度
	</div>
	
	<script>
	jQuery(document).ready(function($) {
		// 工單提交
		$('#wu-support-form').on('submit', function(e) {
			e.preventDefault();
			
			var $form = $(this);
			var $button = $form.find('button[type="submit"]');
			var $buttonText = $button.find('.wu-button-text');
			var $buttonLoading = $button.find('.wu-button-loading');
			var $result = $('#wu-support-result');
			
			$button.prop('disabled', true);
			$buttonText.hide();
			$buttonLoading.show();
			$result.html('');
			
			$.ajax({
				url: ajaxurl,
				type: 'POST',
				data: {
					action: 'wu_submit_support_ticket',
					nonce: $form.find('#wu_support_nonce').val(),
					email: $form.find('[name="email"]').val(),
					subject: $form.find('[name="subject"]').val(),
					message: $form.find('[name="message"]').val(),
					domain: '<?php echo esc_js(home_url()); ?>'
				},
				success: function(response) {
					if (response.success) {
						$result.html('<div class="wu-notice wu-notice-success">' + response.data.message + '</div>');
						$form[0].reset();
						setTimeout(function() {
							location.reload();
						}, 2000);
					} else {
						$result.html('<div class="wu-notice wu-notice-error">' + response.data.message + '</div>');
					}
				},
				error: function() {
					$result.html('<div class="wu-notice wu-notice-error">提交失敗,請稍後再試或聯繫 LINE 官方帳號</div>');
				},
				complete: function() {
					$button.prop('disabled', false);
					$buttonText.show();
					$buttonLoading.hide();
				}
			});
		});
	});
	
	// 進階方案諮詢
	function wuRequestAdvancedPlan() {
		if (!confirm('確定要發送進階維護方案諮詢申請嗎?')) {
			return;
		}
		
		jQuery.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'wu_request_advanced_plan',
				domain: '<?php echo esc_js(home_url()); ?>',
				site_name: '<?php echo esc_js(get_bloginfo('name')); ?>'
			},
			success: function(response) {
				if (response.success) {
					alert('諮詢申請已送出,我們將盡快與您聯繫!');
				} else {
					alert('提交失敗,請聯繫 LINE 官方帳號:https://lin.ee/Lut7wCe');
				}
			},
			error: function() {
				alert('提交失敗,請聯繫 LINE 官方帳號:https://lin.ee/Lut7wCe');
			}
		});
	}
	
	// 磁碟升級申請
	function wuRequestDiskUpgrade() {
		if (!confirm('確定要發送磁碟升級申請嗎?')) {
			return;
		}
		
		jQuery.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'wu_request_disk_upgrade',
				domain: '<?php echo esc_js(home_url()); ?>',
				site_name: '<?php echo esc_js(get_bloginfo('name')); ?>',
				current_usage: '<?php echo esc_js($disk_info['used_formatted']); ?>',
				quota: '<?php echo esc_js($disk_info['quota_formatted']); ?>',
				percentage: '<?php echo esc_js($disk_info['percentage']); ?>'
			},
			success: function(response) {
				if (response.success) {
					alert('磁碟升級申請已送出,我們將盡快與您聯繫!');
				} else {
					alert('提交失敗,請聯繫 LINE 官方帳號:https://lin.ee/Lut7wCe');
				}
			},
			error: function() {
				alert('提交失敗,請聯繫 LINE 官方帳號:https://lin.ee/Lut7wCe');
			}
		});
	}
	</script>
	<?php
}

// ===== Support Ticket Handler =====

add_action('wp_ajax_wu_submit_support_ticket', 'wu_handle_support_ticket');

function wu_handle_support_ticket() {
	check_ajax_referer('wu_support_ticket', 'nonce');
	
	if (!current_user_can('read')) {
		wp_send_json_error(array('message' => '權限不足'));
	}
	
	$email = sanitize_email($_POST['email'] ?? '');
	$subject = sanitize_text_field($_POST['subject'] ?? '');
	$message = sanitize_textarea_field($_POST['message'] ?? '');
	$domain = sanitize_text_field($_POST['domain'] ?? '');
	
	if (empty($email) || empty($subject) || empty($message)) {
		wp_send_json_error(array('message' => '請填寫所有必填欄位'));
	}
	
	// 生成工單編號
	$ticket_id = strtoupper(substr(md5($domain . time()), 0, 8));
	
	// 發送到 Discord
	$webhook_url = 'https://discordapp.com/api/webhooks/1456920175335968858/p6yPCrxqVwTozOEJwIiXkxS8lSe4K4xq1noRLPeYsLXYT8AOqUjllca2rsiClzbamJF2';
	
	$discord_message = array(
		'embeds' => array(
			array(
				'title' => '🎫 新的技術支援工單',
				'color' => 3447003,
				'fields' => array(
					array(
						'name' => '工單編號',
						'value' => '#' . $ticket_id,
						'inline' => true
					),
					array(
						'name' => '網站',
						'value' => $domain,
						'inline' => false
					),
					array(
						'name' => 'Email',
						'value' => $email,
						'inline' => true
					),
					array(
						'name' => '問題類型',
						'value' => $subject,
						'inline' => true
					),
					array(
						'name' => '問題描述',
						'value' => $message,
						'inline' => false
					),
					array(
						'name' => '提交時間',
						'value' => current_time('Y-m-d H:i:s'),
						'inline' => false
					)
				)
			)
		)
	);
	
	$response = wp_remote_post($webhook_url, array(
		'headers' => array('Content-Type' => 'application/json'),
		'body' => json_encode($discord_message),
		'timeout' => 15
	));
	
	if (is_wp_error($response)) {
		wp_send_json_error(array('message' => '提交失敗,請稍後再試或聯繫 LINE 官方帳號'));
	}
	
	// 儲存工單紀錄
	$tickets = get_option('wu_dashboard_support_tickets', array());
	$tickets[] = array(
		'ticket_id' => $ticket_id,
		'subject' => $subject,
		'timestamp' => current_time('timestamp')
	);
	update_option('wu_dashboard_support_tickets', $tickets);
	
	wp_send_json_success(array(
		'message' => '工單已成功提交!工單編號:#' . $ticket_id . '。我們收到後將盡快安排處理。'
	));
}

// ===== Delete Support Ticket Handler =====

add_action('wp_ajax_wu_delete_support_ticket', 'wu_handle_delete_support_ticket');

function wu_handle_delete_support_ticket() {
	if (!current_user_can('manage_options')) {
		wp_send_json_error(array('message' => '權限不足'));
	}
	
	$ticket_id = sanitize_text_field($_POST['ticket_id'] ?? '');
	
	if (empty($ticket_id)) {
		wp_send_json_error(array('message' => '無效的工單編號'));
	}
	
	$tickets = get_option('wu_dashboard_support_tickets', array());
	$tickets = array_filter($tickets, function($ticket) use ($ticket_id) {
		return $ticket['ticket_id'] !== $ticket_id;
	});
	
	update_option('wu_dashboard_support_tickets', array_values($tickets));
	
	wp_send_json_success(array('message' => '工單已刪除'));
}

// ===== Advanced Plan Request Handler =====

add_action('wp_ajax_wu_request_advanced_plan', 'wu_handle_advanced_plan_request');

function wu_handle_advanced_plan_request() {
	if (!current_user_can('read')) {
		wp_send_json_error(array('message' => '權限不足'));
	}
	
	$domain = sanitize_text_field($_POST['domain'] ?? '');
	$site_name = sanitize_text_field($_POST['site_name'] ?? '');
	
	$webhook_url = 'https://discordapp.com/api/webhooks/1456931726247858190/nqtBoz3Io5j-JJbnVoYfS8waJ8lynYgj-3BuZ3TH_EiXIga5iF14GL5BI-tamrXccAKD';
	
	$discord_message = array(
		'embeds' => array(
			array(
				'title' => '🚀 進階維護方案諮詢申請',
				'color' => 3066993,
				'fields' => array(
					array(
						'name' => '網站名稱',
						'value' => $site_name,
						'inline' => false
					),
					array(
						'name' => '網站網址',
						'value' => $domain,
						'inline' => false
					),
					array(
						'name' => '申請時間',
						'value' => current_time('Y-m-d H:i:s'),
						'inline' => false
					),
					array(
						'name' => '方案內容',
						'value' => 'NT$ 8,000 / 年(未稅)',
						'inline' => false
					)
				)
			)
		)
	);
	
	$response = wp_remote_post($webhook_url, array(
		'headers' => array('Content-Type' => 'application/json'),
		'body' => json_encode($discord_message),
		'timeout' => 15
	));
	
	if (is_wp_error($response)) {
		wp_send_json_error(array('message' => '提交失敗'));
	}
	
	wp_send_json_success(array('message' => '已成功送出'));
}

// ===== Disk Upgrade Request Handler =====

add_action('wp_ajax_wu_request_disk_upgrade', 'wu_handle_disk_upgrade_request');

function wu_handle_disk_upgrade_request() {
	if (!current_user_can('read')) {
		wp_send_json_error(array('message' => '權限不足'));
	}
	
	$domain = sanitize_text_field($_POST['domain'] ?? '');
	$site_name = sanitize_text_field($_POST['site_name'] ?? '');
	$current_usage = sanitize_text_field($_POST['current_usage'] ?? '');
	$quota = sanitize_text_field($_POST['quota'] ?? '');
	$percentage = sanitize_text_field($_POST['percentage'] ?? '');
	
	$webhook_url = 'https://discordapp.com/api/webhooks/1456932781689929759/RLZBDmug38qCPtsFbqH_Imc50TqkaeV18lQpF1kLSJxyqfz6ZZ-e7T7TH2hOF-yIv_Rz';
	
	$discord_message = array(
		'embeds' => array(
			array(
				'title' => '💾 磁碟升級申請(緊急)',
				'color' => 15158332,
				'fields' => array(
					array(
						'name' => '網站名稱',
						'value' => $site_name,
						'inline' => false
					),
					array(
						'name' => '網站網址',
						'value' => $domain,
						'inline' => false
					),
					array(
						'name' => '目前使用量',
						'value' => $current_usage,
						'inline' => true
					),
					array(
						'name' => '配額',
						'value' => $quota,
						'inline' => true
					),
					array(
						'name' => '使用率',
						'value' => $percentage . '%',
						'inline' => true
					),
					array(
						'name' => '申請時間',
						'value' => current_time('Y-m-d H:i:s'),
						'inline' => false
					),
					array(
						'name' => '升級方案',
						'value' => "• +5 GB:NT$ 2,000 / 年\n• +10 GB:NT$ 3,500 / 年",
						'inline' => false
					)
				)
			)
		)
	);
	
	$response = wp_remote_post($webhook_url, array(
		'headers' => array('Content-Type' => 'application/json'),
		'body' => json_encode($discord_message),
		'timeout' => 15
	));
	
	if (is_wp_error($response)) {
		wp_send_json_error(array('message' => '提交失敗'));
	}
	
	wp_send_json_success(array('message' => '已成功送出'));
}

// ===== Helper Functions =====

function wu_get_ssl_info() {
	$cache_key = 'wu_ssl_info';
	$cached = get_transient($cache_key);
	
	if ($cached !== false) {
		return $cached;
	}
	
	$is_ssl = is_ssl();
	
	if ($is_ssl) {
		$info = array(
			'status' => 'HTTPS 已啟用',
			'color' => '#46b450',
			'description' => 'SSL 憑證確保資料傳輸加密,保護用戶隱私與網站信譽,提升 SEO 排名'
		);
	} else {
		$info = array(
			'status' => 'HTTP 未加密',
			'color' => '#dc3232',
			'description' => '建議啟用 SSL 憑證以確保資料安全'
		);
	}
	
	set_transient($cache_key, $info, DAY_IN_SECONDS);
	
	return $info;
}

function wu_get_php_info() {
	$version = PHP_VERSION;
	$major = (int) PHP_MAJOR_VERSION;
	$minor = (int) PHP_MINOR_VERSION;
	
	$stable_versions = array('8.1', '8.2', '8.3');
	$current_version = $major . '.' . $minor;
	
	if (in_array($current_version, $stable_versions)) {
		$badge_text = '穩定版本';
		$badge_class = 'wu-badge-stable';
		$description = '目前使用的 PHP 版本為長期支援的穩定版本';
	} elseif ($major >= 8) {
		$badge_text = '最新版本';
		$badge_class = 'wu-badge-latest';
		$description = '目前使用的是最新版本的 PHP';
	} else {
		$badge_text = '建議升級';
		$badge_class = 'wu-badge-upgrade';
		$description = '建議升級至 PHP 8.1 以上以獲得更好的效能與安全性';
	}
	
	return array(
		'version' => $version,
		'badge_text' => $badge_text,
		'badge_class' => $badge_class,
		'description' => $description
	);
}

function wu_get_disk_info() {
	$cache_key = 'wu_disk_info';
	$cached = get_transient($cache_key);
	
	if ($cached !== false) {
		return $cached;
	}
	
	$quota_mb = get_option('wu_dashboard_disk_quota', 5120);
	$used_mb = wu_calculate_site_size();
	$percentage = ($used_mb / $quota_mb) * 100;
	$remaining_mb = $quota_mb - $used_mb;
	$over_quota = $percentage > 100;
	
	if ($percentage < 70) {
		$status_text = '磁碟空間充足';
		$status_class = 'wu-disk-status-normal';
		$color = '#46b450';
	} elseif ($percentage < 90) {
		$status_text = '磁碟空間即將達上限,建議清理或升級配額';
		$status_class = 'wu-disk-status-warning';
		$color = '#f0b849';
	} elseif ($percentage < 100) {
		$status_text = '磁碟空間已接近上限,請盡快處理';
		$status_class = 'wu-disk-status-danger';
		$color = '#dc3232';
	} elseif ($percentage == 100) {
		$status_text = '磁碟已滿';
		$status_class = 'wu-disk-status-full';
		$color = '#dc3232';
	} else {
		$status_text = '磁碟容量超出配額';
		$status_class = 'wu-disk-status-exceeded';
		$color = '#a00';
	}
	
	$info = array(
		'used_mb' => $used_mb,
		'quota_mb' => $quota_mb,
		'remaining_mb' => $remaining_mb,
		'percentage' => number_format(min($percentage, 999), 1),
		'used_formatted' => number_format($used_mb, 0) . ' MB',
		'quota_formatted' => number_format($quota_mb, 0) . ' MB',
		'remaining_formatted' => number_format(max($remaining_mb, 0), 0) . ' MB',
		'status_text' => $status_text,
		'status_class' => $status_class,
		'color' => $color,
		'over_quota' => $over_quota
	);
	
	set_transient($cache_key, $info, HOUR_IN_SECONDS * 12);
	
	return $info;
}

function wu_calculate_site_size() {
	$cache_key = 'wu_site_size_mb';
	$cached = get_transient($cache_key);
	
	if ($cached !== false) {
		return $cached;
	}
	
	$size = 0;
	$site_path = ABSPATH;
	
	try {
		if (function_exists('exec') && @exec('du -sm ' . escapeshellarg($site_path) . ' 2>/dev/null', $output)) {
			$size = intval($output[0]);
		} else {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($site_path, RecursiveDirectoryIterator::SKIP_DOTS),
				RecursiveIteratorIterator::CATCH_GET_CHILD
			);
			
			$bytes = 0;
			foreach ($iterator as $file) {
				try {
					if ($file->isFile()) {
						$bytes += $file->getSize();
					}
				} catch (Exception $e) {
					continue;
				}
			}
			$size = round($bytes / 1024 / 1024, 2);
		}
	} catch (Exception $e) {
		$size = 0;
	}
	
	set_transient($cache_key, $size, HOUR_IN_SECONDS * 12);
	
	return $size;
}

// ===== Settings Page =====

function wu_dashboard_settings_page() {
	if (!current_user_can('manage_options')) {
		wp_die('權限不足');
	}
	
	if (isset($_POST['wu_save'])) {
		check_admin_referer('wu_dash_settings');
		
		update_option('wu_dashboard_enabled', isset($_POST['enabled']) ? 1 : 0);
		update_option('wu_dashboard_referral_enabled', isset($_POST['referral_enabled']) ? 1 : 0);
		update_option('wu_dashboard_referral_content', wp_kses_post($_POST['referral_content'] ?? ''));
		update_option('wu_dashboard_site_status', sanitize_text_field($_POST['status'] ?? 'normal'));
		update_option('wu_dashboard_status_note', sanitize_textarea_field($_POST['status_note'] ?? ''));
		update_option('wu_dashboard_backup_status', sanitize_text_field($_POST['backup_status'] ?? 'normal'));
		update_option('wu_dashboard_security_status', sanitize_text_field($_POST['security_status'] ?? 'normal'));
		
		$recent_work = array();
		if (!empty($_POST['work_titles'])) {
			foreach ($_POST['work_titles'] as $i => $title) {
				if (!empty($title)) {
					$recent_work[] = array(
						'title' => sanitize_text_field($title),
						'date' => sanitize_text_field($_POST['work_dates'][$i] ?? ''),
						'note' => sanitize_textarea_field($_POST['work_notes'][$i] ?? '')
					);
				}
			}
		}
		update_option('wu_dashboard_recent_work', $recent_work);
		
		$services = array();
		if (!empty($_POST['services'])) {
			$services = array_values(array_filter(array_map('sanitize_text_field', $_POST['services'])));
		}
		update_option('wu_dashboard_services', $services);
		
		// 加值服務
		$value_services = array();
		if (!empty($_POST['value_services'])) {
			$value_services = array_values(array_filter(array_map('sanitize_text_field', $_POST['value_services'])));
		}
		update_option('wu_dashboard_value_services', $value_services);
		
		update_option('wu_dashboard_hosting_plan', sanitize_text_field($_POST['hosting_plan'] ?? 'image'));
		update_option('wu_dashboard_hosting_rating', sanitize_text_field($_POST['hosting_rating'] ?? ''));
		update_option('wu_dashboard_disk_quota', intval($_POST['disk_quota'] ?? 5120));
		
		$payments = array();
		if (!empty($_POST['payment_items'])) {
			foreach ($_POST['payment_items'] as $i => $item) {
				if (!empty($item)) {
					$payments[] = array(
						'date' => sanitize_text_field($_POST['payment_dates'][$i] ?? ''),
						'item' => sanitize_text_field($item),
						'amount' => intval($_POST['payment_amounts'][$i] ?? 0),
						'status' => sanitize_text_field($_POST['payment_statuses'][$i] ?? 'pending')
					);
				}
			}
		}
		update_option('wu_dashboard_payments', $payments);
		
		$referrals = array();
		if (!empty($_POST['referral_names'])) {
			foreach ($_POST['referral_names'] as $i => $name) {
				if (!empty($name)) {
					$referrals[] = array(
						'name' => sanitize_text_field($name),
						'date' => sanitize_text_field($_POST['referral_dates'][$i] ?? ''),
						'rewarded' => isset($_POST['referral_rewarded'][$i]) ? 1 : 0
					);
				}
			}
		}
		update_option('wu_dashboard_referrals', $referrals);
		
		update_option('wu_dashboard_advanced_plan', isset($_POST['advanced_plan']) ? 1 : 0);
		
		// 聯絡資訊
		update_option('wu_dashboard_contact_company', sanitize_text_field($_POST['contact_company'] ?? ''));
		update_option('wu_dashboard_contact_email', sanitize_email($_POST['contact_email'] ?? ''));
		update_option('wu_dashboard_contact_line', esc_url_raw($_POST['contact_line'] ?? ''));
		
		delete_transient('wu_ssl_info');
		delete_transient('wu_disk_info');
		delete_transient('wu_site_size_mb');
		
		echo '<div class="notice notice-success is-dismissible"><p><strong>設定已儲存</strong></p></div>';
	}
	
	$enabled = get_option('wu_dashboard_enabled', 1);
	$referral_enabled = get_option('wu_dashboard_referral_enabled', 0);
	$referral_content = get_option('wu_dashboard_referral_content', '');
	$status = get_option('wu_dashboard_site_status', 'normal');
	$status_note = get_option('wu_dashboard_status_note', '');
	$backup_status = get_option('wu_dashboard_backup_status', 'normal');
	$security_status = get_option('wu_dashboard_security_status', 'normal');
	$recent_work = get_option('wu_dashboard_recent_work', array());
	$services = get_option('wu_dashboard_services', array());
	$value_services = get_option('wu_dashboard_value_services', array());
	$hosting_plan = get_option('wu_dashboard_hosting_plan', 'image');
	$hosting_rating = get_option('wu_dashboard_hosting_rating', '優良運作');
	$disk_quota = get_option('wu_dashboard_disk_quota', 5120);
	$payments = get_option('wu_dashboard_payments', array());
	$referrals = get_option('wu_dashboard_referrals', array());
	$advanced_plan = get_option('wu_dashboard_advanced_plan', 0);
	$support_tickets = get_option('wu_dashboard_support_tickets', array());
	$contact_company = get_option('wu_dashboard_contact_company', 'WUMETAX 末特數位科技');
	$contact_email = get_option('wu_dashboard_contact_email', 'contact@wumetax.com');
	$contact_line = get_option('wu_dashboard_contact_line', 'https://lin.ee/Lut7wCe');
	$disk_info = wu_get_disk_info();
	
	?>
	<div class="wrap">
		<h1>儀表板設定</h1>
		
		<div class="notice notice-info" style="padding:15px;">
			<p style="margin:0;"><strong>系統說明</strong></p>
			<ul style="margin:8px 0 0 20px;line-height:1.8;">
				<li>儀表板採用 WordPress 原生風格設計,單欄式佈局</li>
				<li>網域名稱自動抓取當前網站網址</li>
				<li>磁碟使用僅計算 WordPress 網站本身,不影響後台載入速度</li>
				<li>所有統計資料使用快取機制,每 6-12 小時自動更新</li>
				<li>技術支援工單會自動發送到 Discord 通知</li>
			</ul>
		</div>
		
		<div style="background:#fff;padding:20px;border:1px solid #ddd;margin-top:20px;border-left:4px solid #0073aa;">
			<h2 style="margin-top:0;">當前磁碟使用狀態</h2>
			<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:15px;">
				<div style="padding:15px;background:#f9f9f9;border-left:3px solid <?php echo $disk_info['color']; ?>;">
					<div style="font-size:11px;color:#666;margin-bottom:5px;">已使用</div>
					<div style="font-size:22px;font-weight:700;color:<?php echo $disk_info['color']; ?>;"><?php echo esc_html($disk_info['used_formatted']); ?></div>
				</div>
				<div style="padding:15px;background:#f9f9f9;border-left:3px solid #0073aa;">
					<div style="font-size:11px;color:#666;margin-bottom:5px;">配額</div>
					<div style="font-size:22px;font-weight:700;"><?php echo esc_html($disk_info['quota_formatted']); ?></div>
				</div>
				<div style="padding:15px;background:#f9f9f9;border-left:3px solid #46b450;">
					<div style="font-size:11px;color:#666;margin-bottom:5px;">剩餘</div>
					<div style="font-size:22px;font-weight:700;color:#46b450;"><?php echo esc_html($disk_info['remaining_formatted']); ?></div>
				</div>
				<div style="padding:15px;background:#f9f9f9;border-left:3px solid <?php echo $disk_info['color']; ?>;">
					<div style="font-size:11px;color:#666;margin-bottom:5px;">使用率</div>
					<div style="font-size:22px;font-weight:700;color:<?php echo $disk_info['color']; ?>;"><?php echo esc_html($disk_info['percentage']); ?>%</div>
				</div>
			</div>
		</div>
		
		<form method="post" style="background:#fff;padding:25px;border:1px solid #ddd;margin-top:20px;">
			<?php wp_nonce_field('wu_dash_settings'); ?>
			
			<table class="form-table">
				
				<tr>
					<th><label>啟用儀表板</label></th>
					<td>
						<label>
							<input type="checkbox" name="enabled" value="1" <?php checked(1, $enabled); ?>>
							<strong>啟用客戶儀表板</strong>
						</label>
					</td>
				</tr>
				
				<tr>
					<th><label>網站狀態</label></th>
					<td>
						<select name="status">
							<option value="normal" <?php selected($status, 'normal'); ?>>正常運作</option>
							<option value="watching" <?php selected($status, 'watching'); ?>>觀察中</option>
							<option value="handling" <?php selected($status, 'handling'); ?>>處理中</option>
						</select>
						<br>
						<textarea name="status_note" rows="2" class="large-text" style="margin-top:10px;" placeholder="狀態說明 (選填)"><?php echo esc_textarea($status_note); ?></textarea>
					</td>
				</tr>
				
				<tr>
					<th><label>備份狀態</label></th>
					<td>
						<select name="backup_status">
							<option value="normal" <?php selected($backup_status, 'normal'); ?>>正常</option>
							<option value="abnormal" <?php selected($backup_status, 'abnormal'); ?>>異常</option>
						</select>
					</td>
				</tr>
				
				<tr>
					<th><label>安全狀態</label></th>
					<td>
						<select name="security_status">
							<option value="normal" <?php selected($security_status, 'normal'); ?>>正常</option>
							<option value="abnormal" <?php selected($security_status, 'abnormal'); ?>>異常</option>
						</select>
					</td>
				</tr>
				
				<tr>
					<th><label>主機方案</label></th>
					<td>
						<select name="hosting_plan">
							<option value="onepage" <?php selected($hosting_plan, 'onepage'); ?>>一頁式主機</option>
							<option value="image" <?php selected($hosting_plan, 'image'); ?>>形象網站主機</option>
							<option value="ecommerce" <?php selected($hosting_plan, 'ecommerce'); ?>>電商主機</option>
						</select>
						<br>
						<input type="text" name="hosting_rating" class="regular-text" style="margin-top:10px;" 
							value="<?php echo esc_attr($hosting_rating); ?>" placeholder="主機運作評估">
					</td>
				</tr>
				
				<tr>
					<th><label>磁碟配額 (MB)</label></th>
					<td>
						<input type="number" name="disk_quota" class="regular-text" 
							value="<?php echo esc_attr($disk_quota); ?>" min="0" step="1">
						<p class="description">設定磁碟配額上限 (單位:MB)</p>
					</td>
				</tr>
				
				<tr>
					<th><label>進階維護方案</label></th>
					<td>
						<label>
							<input type="checkbox" name="advanced_plan" value="1" <?php checked(1, $advanced_plan); ?>>
							<strong>已啟用進階維護方案</strong>
						</label>
					</td>
				</tr>
				
				<tr>
					<th><label>基礎維護服務項目</label></th>
					<td>
						<div id="services-list">
							<?php if (!empty($services)): ?>
								<?php foreach ($services as $i => $service): ?>
								<div class="wu-dynamic-item" style="margin-bottom:10px;">
									<input type="text" name="services[]" class="large-text" value="<?php echo esc_attr($service); ?>">
									<button type="button" class="button wu-remove-item">移除</button>
								</div>
								<?php endforeach; ?>
							<?php endif; ?>
						</div>
						<button type="button" class="button" onclick="wuAddService()">+ 新增服務項目</button>
					</td>
				</tr>
				
				<tr>
					<th><label>加值服務項目</label></th>
					<td>
						<div id="value-services-list">
							<?php if (!empty($value_services)): ?>
								<?php foreach ($value_services as $i => $service): ?>
								<div class="wu-dynamic-item" style="margin-bottom:10px;">
									<input type="text" name="value_services[]" class="large-text" value="<?php echo esc_attr($service); ?>">
									<button type="button" class="button wu-remove-item">移除</button>
								</div>
								<?php endforeach; ?>
							<?php endif; ?>
						</div>
						<button type="button" class="button" onclick="wuAddValueService()">+ 新增加值服務</button>
					</td>
				</tr>
				
				<!-- 推薦回饋專區設定 -->
				<tr>
					<th><label>推薦回饋專區</label></th>
					<td>
						<label style="display:block;margin-bottom:15px;">
							<input type="checkbox" name="referral_enabled" value="1" <?php checked(1, $referral_enabled); ?>>
							<strong>啟用推薦回饋專區</strong>
							<span class="description" style="display:block;margin-top:5px;">勾選後,儀表板將顯示推薦回饋專區</span>
						</label>
						
						<label style="display:block;margin-top:15px;">
							<strong>自訂推薦內容 (選填)</strong>
							<span class="description" style="display:block;margin-bottom:10px;">留空則顯示預設內容。支援 HTML 標籤。</span>
							<?php 
							wp_editor($referral_content, 'referral_content', array(
								'textarea_name' => 'referral_content',
								'textarea_rows' => 10,
								'media_buttons' => false,
								'teeny' => true,
								'quicktags' => true
							)); 
							?>
						</label>
					</td>
				</tr>
				
				<tr>
					<th><label>推薦回饋紀錄</label></th>
					<td>
						<div id="referrals-list">
							<?php if (!empty($referrals)): ?>
								<?php foreach ($referrals as $i => $referral): ?>
								<div class="wu-dynamic-item" style="margin-bottom:15px;padding:15px;background:#f9f9f9;border-left:3px solid #0073aa;">
									<div style="margin-bottom:8px;">
										<input type="text" name="referral_names[]" class="regular-text" placeholder="推薦人姓名" value="<?php echo esc_attr($referral['name']); ?>">
										<input type="date" name="referral_dates[]" value="<?php echo esc_attr($referral['date']); ?>">
									</div>
									<div>
										<label>
											<input type="checkbox" name="referral_rewarded[<?php echo $i; ?>]" value="1" <?php checked(1, $referral['rewarded']); ?>>
											<strong>已發放回饋</strong>
										</label>
										<button type="button" class="button wu-remove-item" style="margin-left:10px;">移除</button>
									</div>
								</div>
								<?php endforeach; ?>
							<?php endif; ?>
						</div>
						<button type="button" class="button" onclick="wuAddReferral()">+ 新增推薦紀錄</button>
					</td>
				</tr>
				
				<tr>
					<th><label>最近處理紀錄</label></th>
					<td>
						<div id="recent-work-list">
							<?php if (!empty($recent_work)): ?>
								<?php foreach ($recent_work as $i => $work): ?>
								<div class="wu-dynamic-item" style="margin-bottom:15px;padding:15px;background:#f9f9f9;border-left:3px solid #00a32a;">
									<div style="margin-bottom:8px;">
										<input type="date" name="work_dates[]" value="<?php echo esc_attr($work['date']); ?>" style="margin-right:10px;">
										<input type="text" name="work_titles[]" class="regular-text" placeholder="處理項目標題" value="<?php echo esc_attr($work['title']); ?>">
									</div>
									<textarea name="work_notes[]" class="large-text" rows="2" placeholder="處理說明 (選填)"><?php echo esc_textarea($work['note']); ?></textarea>
									<button type="button" class="button wu-remove-item" style="margin-top:8px;">移除</button>
								</div>
								<?php endforeach; ?>
							<?php endif; ?>
						</div>
						<button type="button" class="button" onclick="wuAddWork()">+ 新增處理紀錄</button>
					</td>
				</tr>
				
				<tr>
					<th><label>款項紀錄</label></th>
					<td>
						<div id="payments-list">
							<?php if (!empty($payments)): ?>
								<?php foreach ($payments as $i => $payment): ?>
								<div class="wu-dynamic-item" style="margin-bottom:15px;padding:15px;background:#f9f9f9;border-left:3px solid #d63638;">
									<div style="margin-bottom:8px;">
										<input type="date" name="payment_dates[]" value="<?php echo esc_attr($payment['date']); ?>" style="margin-right:10px;">
										<input type="text" name="payment_items[]" class="regular-text" placeholder="款項項目" value="<?php echo esc_attr($payment['item']); ?>">
									</div>
									<div style="margin-bottom:8px;">
										<input type="number" name="payment_amounts[]" class="regular-text" placeholder="金額" value="<?php echo esc_attr($payment['amount']); ?>" step="1">
										<select name="payment_statuses[]" style="margin-left:10px;">
											<option value="pending" <?php selected($payment['status'], 'pending'); ?>>待付款</option>
											<option value="paid" <?php selected($payment['status'], 'paid'); ?>>已付款</option>
										</select>
									</div>
									<button type="button" class="button wu-remove-item">移除</button>
								</div>
								<?php endforeach; ?>
							<?php endif; ?>
						</div>
						<button type="button" class="button" onclick="wuAddPayment()">+ 新增款項紀錄</button>
						<p class="description">僅管理員可見</p>
					</td>
				</tr>
				
				<tr>
					<th><label>工單提交紀錄</label></th>
					<td>
						<?php if (!empty($support_tickets)): ?>
						<div style="max-height:300px;overflow-y:auto;border:1px solid #ddd;padding:10px;background:#f9f9f9;">
							<?php foreach ($support_tickets as $ticket): ?>
							<div style="padding:10px;background:#fff;margin-bottom:10px;border-left:3px solid #2271b1;display:flex;justify-content:space-between;align-items:center;">
								<div>
									<strong>#<?php echo esc_html($ticket['ticket_id']); ?></strong> - 
									<?php echo esc_html($ticket['subject']); ?> 
									<span style="color:#666;font-size:12px;">(<?php echo date('Y/m/d H:i', $ticket['timestamp']); ?>)</span>
								</div>
								<button type="button" class="button button-small" onclick="wuDeleteTicket('<?php echo esc_js($ticket['ticket_id']); ?>')">刪除</button>
							</div>
							<?php endforeach; ?>
						</div>
						<?php else: ?>
						<p>尚無工單紀錄</p>
						<?php endif; ?>
					</td>
				</tr>
				
				<tr>
					<th><label>聯絡資訊</label></th>
					<td>
						<div style="margin-bottom:10px;">
							<label style="display:block;margin-bottom:5px;"><strong>公司名稱</strong></label>
							<input type="text" name="contact_company" class="large-text" value="<?php echo esc_attr($contact_company); ?>">
						</div>
						<div style="margin-bottom:10px;">
							<label style="display:block;margin-bottom:5px;"><strong>Email</strong></label>
							<input type="email" name="contact_email" class="large-text" value="<?php echo esc_attr($contact_email); ?>">
						</div>
						<div style="margin-bottom:10px;">
							<label style="display:block;margin-bottom:5px;"><strong>LINE 官方帳號</strong></label>
							<input type="url" name="contact_line" class="large-text" value="<?php echo esc_attr($contact_line); ?>">
						</div>
					</td>
				</tr>
				
			</table>
			
			<?php submit_button('儲存設定', 'primary large', 'wu_save'); ?>
		</form>
	</div>
	
	<script>
	function wuAddService() {
		var html = '<div class="wu-dynamic-item" style="margin-bottom:10px;">' +
			'<input type="text" name="services[]" class="large-text">' +
			'<button type="button" class="button wu-remove-item">移除</button>' +
			'</div>';
		document.getElementById('services-list').insertAdjacentHTML('beforeend', html);
	}
	
	function wuAddValueService() {
		var html = '<div class="wu-dynamic-item" style="margin-bottom:10px;">' +
			'<input type="text" name="value_services[]" class="large-text">' +
			'<button type="button" class="button wu-remove-item">移除</button>' +
			'</div>';
		document.getElementById('value-services-list').insertAdjacentHTML('beforeend', html);
	}
	
	function wuAddReferral() {
		var index = document.querySelectorAll('#referrals-list .wu-dynamic-item').length;
		var html = '<div class="wu-dynamic-item" style="margin-bottom:15px;padding:15px;background:#f9f9f9;border-left:3px solid #0073aa;">' +
			'<div style="margin-bottom:8px;">' +
			'<input type="text" name="referral_names[]" class="regular-text" placeholder="推薦人姓名">' +
			'<input type="date" name="referral_dates[]">' +
			'</div>' +
			'<div>' +
			'<label><input type="checkbox" name="referral_rewarded[' + index + ']" value="1"><strong>已發放回饋</strong></label>' +
			'<button type="button" class="button wu-remove-item" style="margin-left:10px;">移除</button>' +
			'</div>' +
			'</div>';
		document.getElementById('referrals-list').insertAdjacentHTML('beforeend', html);
	}
	
	function wuAddWork() {
		var html = '<div class="wu-dynamic-item" style="margin-bottom:15px;padding:15px;background:#f9f9f9;border-left:3px solid #00a32a;">' +
			'<div style="margin-bottom:8px;">' +
			'<input type="date" name="work_dates[]" style="margin-right:10px;">' +
			'<input type="text" name="work_titles[]" class="regular-text" placeholder="處理項目標題">' +
			'</div>' +
			'<textarea name="work_notes[]" class="large-text" rows="2" placeholder="處理說明 (選填)"></textarea>' +
			'<button type="button" class="button wu-remove-item" style="margin-top:8px;">移除</button>' +
			'</div>';
		document.getElementById('recent-work-list').insertAdjacentHTML('beforeend', html);
	}
	
	function wuAddPayment() {
		var html = '<div class="wu-dynamic-item" style="margin-bottom:15px;padding:15px;background:#f9f9f9;border-left:3px solid #d63638;">' +
			'<div style="margin-bottom:8px;">' +
			'<input type="date" name="payment_dates[]" style="margin-right:10px;">' +
			'<input type="text" name="payment_items[]" class="regular-text" placeholder="款項項目">' +
			'</div>' +
			'<div style="margin-bottom:8px;">' +
			'<input type="number" name="payment_amounts[]" class="regular-text" placeholder="金額" step="1">' +
			'<select name="payment_statuses[]" style="margin-left:10px;">' +
			'<option value="pending">待付款</option>' +
			'<option value="paid">已付款</option>' +
			'</select>' +
			'</div>' +
			'<button type="button" class="button wu-remove-item">移除</button>' +
			'</div>';
		document.getElementById('payments-list').insertAdjacentHTML('beforeend', html);
	}
	
	function wuDeleteTicket(ticketId) {
		if (!confirm('確定要刪除此工單紀錄嗎?')) return;
		
		jQuery.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'wu_delete_support_ticket',
				ticket_id: ticketId
			},
			success: function(response) {
				if (response.success) {
					alert('工單已刪除');
					location.reload();
				} else {
					alert(response.data.message);
				}
			}
		});
	}
	
	jQuery(document).ready(function($) {
		$(document).on('click', '.wu-remove-item', function() {
			$(this).closest('.wu-dynamic-item').remove();
		});
	});
	</script>
	
	<style>
	.wu-dynamic-item {
		position: relative;
	}
	</style>
	<?php
}

// ===== Dashboard Styles =====

add_action('admin_head', function() {
	if (!get_option('wu_dashboard_enabled', 1)) {
		return;
	}
	
	?>
	<style>
	/* Widget Container */
	#wu_unified_dashboard {
		width: 100% !important;
		grid-column: 1 / -1 !important;
	}
	
	#wu_unified_dashboard .inside {
		padding: 0 !important;
		margin: 0 !important;
	}
	
	.wu-dashboard-container {
		font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
	}
	
	/* Section */
	.wu-section {
		background: #fff;
		border: 1px solid #ddd;
		padding: 20px;
		margin-bottom: 15px;
	}
	
	.wu-section-title {
		margin: 0 0 15px 0;
		font-size: 16px;
		font-weight: 600;
		color: #1d2327;
		display: flex;
		align-items: center;
		justify-content: space-between;
	}
	
	.wu-monitoring-badge {
		font-size: 11px;
		background: #f0f6fc;
		color: #2271b1;
		padding: 4px 10px;
		border-radius: 3px;
		font-weight: 500;
	}
	
	.wu-section-label {
		font-size: 11px;
		padding: 4px 10px;
		border-radius: 3px;
		font-weight: 500;
		color: #fff;
	}
	
	/* Status Card */
	.wu-status-card {
		background: #f6f7f7;
		border-left: 4px solid #0073aa;
		padding: 15px;
		margin-bottom: 15px;
	}
	
	.wu-status-badge {
		display: inline-block;
		padding: 6px 14px;
		border-radius: 4px;
		color: #fff;
		font-weight: 600;
		font-size: 13px;
	}
	
	.wu-status-note {
		margin-top: 10px;
		font-size: 13px;
		color: #50575e;
		line-height: 1.6;
	}
	
	/* Info Table */
	.wu-info-table {
		width: 100%;
		border-collapse: collapse;
	}
	
	.wu-info-table th {
		width: 140px;
		padding: 12px 15px;
		text-align: left;
		font-weight: 600;
		color: #1d2327;
		background: #f6f7f7;
		border-top: 1px solid #ddd;
		font-size: 13px;
	}
	
	.wu-info-table td {
		padding: 12px 15px;
		border-top: 1px solid #ddd;
		font-size: 13px;
		color: #1d2327;
	}
	
	.wu-info-meta {
		color: #646970 !important;
		font-size: 12px !important;
	}
	
	.wu-ssl-status, .wu-status-indicator {
		font-weight: 600;
	}
	
	.wu-badge-stable, .wu-badge-latest, .wu-badge-upgrade {
		font-size: 11px;
		padding: 3px 8px;
		border-radius: 3px;
		margin-left: 8px;
	}
	
	.wu-badge-stable {
		background: #00a32a;
		color: #fff;
	}
	
	.wu-badge-latest {
		background: #2271b1;
		color: #fff;
	}
	
	.wu-badge-upgrade {
		background: #f0b849;
		color: #1d2327;
	}
	
	/* Disk Usage */
	.wu-disk-grid {
		display: grid;
		grid-template-columns: repeat(4, 1fr);
		gap: 15px;
		margin-bottom: 15px;
	}
	
	.wu-disk-item {
		text-align: center;
		padding: 15px;
		background: #f6f7f7;
	}
	
	.wu-disk-label {
		font-size: 11px;
		color: #646970;
		margin-bottom: 8px;
		text-transform: uppercase;
		font-weight: 600;
		letter-spacing: 0.5px;
	}
	
	.wu-disk-value {
		font-size: 24px;
		font-weight: 700;
		color: #1d2327;
	}
	
	.wu-disk-bar {
		height: 12px;
		background: #f0f0f1;
		border-radius: 6px;
		overflow: hidden;
		margin-bottom: 10px;
	}
	
	.wu-disk-bar-fill {
		height: 100%;
		transition: width 0.3s ease;
		border-radius: 6px;
	}
	
	.wu-disk-status {
		text-align: center;
		font-size: 13px;
		font-weight: 600;
	}
	
	.wu-disk-status-normal { color: #00a32a; }
	.wu-disk-status-warning { color: #f0b849; }
	.wu-disk-status-danger { color: #d63638; }
	.wu-disk-status-full { color: #d63638; }
	.wu-disk-status-exceeded { color: #a00; }
	
	/* Notice */
	.wu-notice {
		padding: 15px;
		border-left: 4px solid;
		margin-top: 15px;
		background: #fff;
	}
	
	.wu-notice-error {
		border-color: #d63638;
		background: #fcf0f1;
	}
	
	.wu-notice-success {
		border-color: #00a32a;
		background: #edfaef;
	}
	
	/* Basic Plan */
	.wu-section-basic-plan {
		background: #f0f6fc;
		border-color: #2271b1;
	}
	
	.wu-basic-plan-list {
		margin: 0;
		padding: 0;
		list-style: none;
	}
	
	.wu-basic-plan-list li {
		padding: 10px 0;
		border-bottom: 1px solid #ddd;
		display: flex;
		align-items: center;
		font-size: 13px;
		color: #1d2327;
	}
	
	.wu-basic-plan-list li:last-child {
		border-bottom: none;
	}
	
	.wu-check-icon {
		width: 18px;
		height: 18px;
		background: #00a32a;
		border-radius: 50%;
		margin-right: 12px;
		flex-shrink: 0;
		position: relative;
	}
	
	.wu-check-icon::after {
		content: "✓";
		position: absolute;
		top: 50%;
		left: 50%;
		transform: translate(-50%, -50%);
		color: #fff;
		font-size: 12px;
		font-weight: 700;
	}
	
	/* Service List */
	.wu-service-list {
		margin: 0;
		padding: 0 0 0 20px;
		list-style: disc;
	}
	
	.wu-service-list li {
		padding: 8px 0;
		font-size: 13px;
		color: #1d2327;
	}
	
	/* Advanced Plan */
	.wu-section-highlight {
		background: #edfaef;
		border-color: #00a32a;
	}
	
	.wu-feature-list {
		margin: 0;
		padding: 0;
		list-style: none;
	}
	
	.wu-feature-list li {
		padding: 12px 0;
		border-bottom: 1px solid #d4edda;
		font-size: 13px;
	}
	
	.wu-feature-list li:last-child {
		border-bottom: none;
	}
	
	.wu-feature-desc {
		display: block;
		color: #646970;
		font-size: 12px;
		margin-top: 5px;
	}
	
	/* Promo Box */
	.wu-section-promo {
		background: #fcf9e8;
		border-color: #dba617;
	}
	
	.wu-promo-box {
		background: #fff;
		border: 2px solid #f0b849;
		border-radius: 8px;
		padding: 20px;
	}
	
	.wu-promo-header {
		display: flex;
		justify-content: space-between;
		align-items: center;
		margin-bottom: 15px;
		padding-bottom: 15px;
		border-bottom: 2px solid #f0b849;
	}
	
	.wu-promo-title {
		font-size: 18px;
		font-weight: 700;
		color: #1d2327;
	}
	
	.wu-promo-price {
		font-size: 24px;
		font-weight: 700;
		color: #d63638;
	}
	
	.wu-promo-price span {
		font-size: 14px;
		color: #646970;
		font-weight: 400;
	}
	
	.wu-promo-list {
		margin: 0 0 15px 0;
		padding: 0 0 0 20px;
		list-style: disc;
	}
	
	.wu-promo-list li {
		padding: 8px 0;
		font-size: 13px;
		color: #1d2327;
	}
	
	.wu-promo-note {
		font-size: 13px;
		color: #646970;
		margin-bottom: 15px;
	}
	
	/* Referral */
	.wu-referral-rules {
		background: #f6f7f7;
		padding: 15px;
		border-radius: 4px;
		margin-bottom: 15px;
	}
	
	.wu-referral-step {
		display: inline-block;
		background: #2271b1;
		color: #fff;
		padding: 3px 10px;
		border-radius: 3px;
		font-size: 11px;
		font-weight: 700;
		margin-right: 8px;
	}
	
	.wu-referral-custom-content {
		padding: 15px;
		background: #f6f7f7;
		border-left: 4px solid #2271b1;
		margin-bottom: 15px;
		font-size: 13px;
		line-height: 1.6;
		color: #50575e;
	}
	
	.wu-referral-custom-content p {
		margin: 0 0 10px 0;
	}
	
	.wu-referral-custom-content strong {
		color: #1d2327;
	}
	
	/* Card Grid */
	.wu-card-grid {
		display: grid;
		grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
		gap: 15px;
	}
	
	.wu-referral-card, .wu-record-card, .wu-payment-card, .wu-ticket-card {
		background: #f6f7f7;
		padding: 15px;
		border-radius: 4px;
		border-left: 4px solid;
	}
	
	.wu-referral-card {
		border-color: #2271b1;
	}
	
	.wu-record-card {
		border-color: #00a32a;
	}
	
	.wu-payment-card {
		border-color: #d63638;
	}
	
	.wu-ticket-card {
		border-color: #f0b849;
	}
	
	.wu-referral-name, .wu-record-title, .wu-payment-item, .wu-ticket-subject {
		font-weight: 600;
		font-size: 14px;
		color: #1d2327;
		margin-bottom: 8px;
	}
	
	.wu-referral-date, .wu-record-date, .wu-payment-date, .wu-ticket-date {
		font-size: 12px;
		color: #646970;
		margin-bottom: 8px;
	}
	
	.wu-record-note {
		font-size: 12px;
		color: #646970;
		margin-top: 8px;
		line-height: 1.5;
	}
	
	.wu-payment-amount {
		font-size: 16px;
		font-weight: 700;
		color: #d63638;
		margin-bottom: 8px;
	}
	
	.wu-ticket-id {
		margin-top: 8px;
	}
	
	.wu-code {
		background: #1d2327;
		color: #f0b849;
		padding: 3px 8px;
		border-radius: 3px;
		font-size: 11px;
		font-family: monospace;
	}
	
	.wu-badge {
		display: inline-block;
		padding: 4px 10px;
		border-radius: 3px;
		font-size: 11px;
		font-weight: 600;
	}
	
	.wu-badge-success {
		background: #00a32a;
		color: #fff;
	}
	
	.wu-badge-pending {
		background: #f0b849;
		color: #1d2327;
	}
	
	.wu-badge-warning {
		background: #d63638;
		color: #fff;
	}
	
	.wu-empty-state {
		text-align: center;
		padding: 30px;
		color: #646970;
		font-size: 13px;
	}
	
	.wu-empty-state p {
		margin: 5px 0;
	}
	
	/* Support Form */
	.wu-support-form {
		background: #f6f7f7;
		padding: 20px;
		border-radius: 4px;
		margin-bottom: 15px;
	}
	
	.wu-form-group {
		margin-bottom: 15px;
	}
	
	.wu-form-group label {
		display: block;
		font-weight: 600;
		margin-bottom: 8px;
		font-size: 13px;
		color: #1d2327;
	}
	
	.required {
		color: #d63638;
	}
	
	.wu-input, .wu-select, .wu-textarea {
		width: 100%;
		padding: 8px 12px;
		border: 1px solid #8c8f94;
		border-radius: 4px;
		font-size: 13px;
	}
	
	.wu-textarea {
		resize: vertical;
	}
	
	.wu-form-actions {
		margin-top: 15px;
	}
	
	.wu-button {
		background: #2271b1;
		color: #fff;
		padding: 10px 20px;
		border: none;
		border-radius: 4px;
		cursor: pointer;
		font-size: 13px;
		font-weight: 600;
	}
	
	.wu-button:hover {
		background: #135e96;
	}
	
	.wu-button-primary {
		background: #2271b1;
	}
	
	.wu-button-danger {
		background: #d63638;
	}
	
	.wu-button-danger:hover {
		background: #b32d2e;
	}
	
	.wu-support-note {
		margin-top: 15px;
		font-size: 12px;
		color: #646970;
	}
	
	.wu-support-note ul {
		margin: 8px 0 0 20px;
		padding: 0;
	}
	
	.wu-support-note li {
		margin-bottom: 5px;
	}
	
	.wu-ticket-history {
		margin-top: 20px;
		padding-top: 20px;
		border-top: 1px solid #ddd;
	}
	
	.wu-ticket-history-title {
		font-size: 14px;
		font-weight: 600;
		margin-bottom: 15px;
		color: #1d2327;
	}
	
	/* Contact Section */
	.wu-contact-section {
		background: #f6f7f7;
	}
	
	.wu-contact-list {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
		gap: 15px;
	}
	
	.wu-contact-item {
		background: #fff;
		padding: 15px;
		border-radius: 4px;
		border-left: 4px solid #2271b1;
	}
	
	.wu-contact-label {
		font-size: 11px;
		color: #646970;
		margin-bottom: 8px;
		text-transform: uppercase;
		font-weight: 600;
		letter-spacing: 0.5px;
	}
	
	.wu-contact-value {
		font-size: 13px;
		color: #1d2327;
		font-weight: 600;
	}
	
	.wu-contact-value a {
		color: #2271b1;
		text-decoration: none;
	}
	
	.wu-contact-value a:hover {
		text-decoration: underline;
	}
	
	/* Footer */
	.wu-footer-meta {
		text-align: center;
		padding: 15px;
		font-size: 11px;
		color: #646970;
		background: #f6f7f7;
		border-top: 1px solid #ddd;
	}
	
	/* Responsive */
	@media (max-width: 782px) {
		.wu-disk-grid {
			grid-template-columns: repeat(2, 1fr);
		}
		
		.wu-card-grid {
			grid-template-columns: 1fr;
		}
		
		.wu-contact-list {
			grid-template-columns: 1fr;
		}
		
		.wu-promo-header {
			flex-direction: column;
			align-items: flex-start;
		}
		
		.wu-promo-price {
			margin-top: 10px;
		}
	}
	</style>
	<?php
});
