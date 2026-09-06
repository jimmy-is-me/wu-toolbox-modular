/**
 * Referral System JavaScript
 * 
 * Handles copy functionality and interactions for the referral system
 */
(function($) {
	'use strict';
	
	$(document).ready(function() {
		// Copy referral link functionality
		const copyBtn = document.getElementById('spar-copy-referral-link');
		const linkInput = document.getElementById('spar-referral-link');
		
		if (copyBtn && linkInput) {
			copyBtn.addEventListener('click', function() {
				// Try modern clipboard API first
				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(linkInput.value).then(function() {
						showCopySuccess(copyBtn);
					}).catch(function() {
						// Fall back to legacy method
						fallbackCopyToClipboard(linkInput, copyBtn);
					});
				} else {
					// Use legacy method
					fallbackCopyToClipboard(linkInput, copyBtn);
				}
			});
		}
		
		// Copy referral coupon functionality - only if element exists
		const copyCouponBtn = document.getElementById('spar-copy-referral-coupon');
		const couponInput = document.getElementById('spar-referral-coupon');
		
		if (copyCouponBtn && couponInput) {
			copyCouponBtn.addEventListener('click', function() {
				// Try modern clipboard API first
				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(couponInput.value).then(function() {
						showCopySuccess(copyCouponBtn);
					}).catch(function() {
						// Fall back to legacy method
						fallbackCopyToClipboard(couponInput, copyCouponBtn);
					});
				} else {
					// Use legacy method
					fallbackCopyToClipboard(couponInput, copyCouponBtn);
				}
			});
		}
		
		// Generate referral coupon functionality - only if element exists
		$('#spar-generate-referral-coupon').on('click', function() {
			const $button = $(this);
			const $generateText = $button.find('.spar-generate-text');
			const $loadingText = $button.find('.spar-loading-text');
			
			if (!sparReferralSystem.referralOfferEnabled) {
				return false;
			}
			
			// Disable button and show loading state
			$button.prop('disabled', true).addClass('is-loading');
			
			$.ajax({
				url: sparReferralSystem.ajaxurl || spar_ajax.ajax_url || '/wp-admin/admin-ajax.php',
				type: 'POST',
				data: {
					action: 'spar_generate_referral_coupon',
					nonce: sparReferralSystem.referralNonce || spar_ajax.nonce
				},
				success: function(response) {
					console.log('AJAX Response:', response);
					if (response.success) {
						// Create the coupon display HTML
						const couponHtml = `
							<div class="spar-referral-coupon-input-group">
								<input type="text" id="spar-referral-coupon" value="${response.data.coupon_code}" readonly />
								<button type="button" id="spar-copy-referral-coupon" class="spar-copy-button">
									<span class="spar-copy-text">${sparReferralSystem.strings.copy}</span>
								</button>
							</div>
						`;
						
						// Replace the button with the coupon display
						$button.closest('.spar-referral-coupon-wrapper').html(couponHtml);
						
						// Reinitialize copy functionality for the new button
						const newCopyBtn = document.getElementById('spar-copy-referral-coupon');
						const newCouponInput = document.getElementById('spar-referral-coupon');
						
						if (newCopyBtn && newCouponInput) {
							newCopyBtn.addEventListener('click', function() {
								if (navigator.clipboard && navigator.clipboard.writeText) {
									navigator.clipboard.writeText(newCouponInput.value).then(function() {
										showCopySuccess(newCopyBtn);
									}).catch(function() {
										fallbackCopyToClipboard(newCouponInput, newCopyBtn);
									});
								} else {
									fallbackCopyToClipboard(newCouponInput, newCopyBtn);
								}
							});
						}
					} else {
						console.error('AJAX Error:', response.data);
						alert(response.data || 'Failed to generate coupon. Please try again.');
						// Re-enable button
						$button.prop('disabled', false).removeClass('is-loading');
					}
				},
				error: function(xhr, status, error) {
					console.error('AJAX Error:', status, error);
					console.error('Response Text:', xhr.responseText);
					alert('Failed to generate coupon. Please try again.');
					// Re-enable button
	 				$button.prop('disabled', false).removeClass('is-loading');
				}
			});
		});
		
		/**
		 * Show copy success feedback
		 */
		function showCopySuccess(button) {
			const originalText = button.textContent;
			button.textContent = sparReferralSystem.strings.copied;
			button.classList.add('spar-copy-success');
			
			setTimeout(function() {
				button.textContent = originalText;
				button.classList.remove('spar-copy-success');
			}, 2000);
		}
		
		/**
		 * Fallback copy method for older browsers
		 */
		function fallbackCopyToClipboard(input, button) {
			try {
				input.select();
				input.setSelectionRange(0, 99999); // For mobile devices
				document.execCommand('copy');
				showCopySuccess(button);
			} catch (err) {
				console.error('Failed to copy: ', err);
				// Show error feedback
				button.textContent = sparReferralSystem.strings.copyError;
				setTimeout(function() {
					button.textContent = sparReferralSystem.strings.copy;
				}, 2000);
			}
		}
	});
	
})(jQuery);
