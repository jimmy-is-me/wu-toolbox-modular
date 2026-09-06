(function() {
	'use strict';
	
	function sparCopyVoucherCode() {
		const codeElement = document.getElementById("voucher-code");
		const text = codeElement.textContent;
		
		if (navigator.clipboard) {
			navigator.clipboard.writeText(text).then(function() {
				sparShowCopyFeedback();
			});
		} else {
			// Fallback for older browsers
			const textArea = document.createElement("textarea");
			textArea.value = text;
			document.body.appendChild(textArea);
			textArea.select();
			document.execCommand("copy");
			document.body.removeChild(textArea);
			sparShowCopyFeedback();
		}
	}
	
	function sparShowCopyFeedback() {
		const btn = document.querySelector(".spar-copy-btn");
		if (!btn) return;
		
		const originalText = btn.innerHTML;
		btn.innerHTML = "✅";
		btn.style.background = "rgba(255, 255, 255, 0.4)";
		setTimeout(function() {
			btn.innerHTML = originalText;
			btn.style.background = "rgba(255, 255, 255, 0.2)";
		}, 2000);
	}
	
	function sparDismissNotice() {
		const notice = document.querySelector(".spar-voucher-success-notice");
		if (!notice) return;
		
		notice.style.opacity = "0";
		notice.style.transform = "translateY(-20px)";
		setTimeout(function() {
			notice.remove();
			// Remove the redeemed parameter from URL
			const url = new URL(window.location);
			url.searchParams.delete("redeemed");
			window.history.replaceState({}, document.title, url.pathname + url.search);
		}, 300);
	}
	
	// Make functions globally available
	window.sparCopyVoucherCode = sparCopyVoucherCode;
	window.sparDismissNotice = sparDismissNotice;
	
	// Initialize when DOM is ready
	document.addEventListener('DOMContentLoaded', function() {
		// Setup copy button click handler
		const copyBtn = document.querySelector('.spar-copy-btn');
		if (copyBtn) {
			copyBtn.addEventListener('click', sparCopyVoucherCode);
		}
		
		// Setup dismiss button click handler
		const dismissBtn = document.querySelector('.spar-dismiss-btn');
		if (dismissBtn) {
			dismissBtn.addEventListener('click', sparDismissNotice);
		}
		
		// Setup apply to cart button click handler
		const applyBtn = document.querySelector('.spar-apply-to-cart-btn');
		if (applyBtn) {
			applyBtn.addEventListener('click', function() {
				const voucherCode = this.getAttribute('data-voucher');
				if (voucherCode) {
					// Use the dedicated voucher success AJAX functionality if available
					if (typeof jQuery !== 'undefined' && typeof sparVoucherSuccess !== 'undefined') {
						applyVoucherToCart(voucherCode, this);
					} else if (typeof jQuery !== 'undefined' && typeof sparRewardsWidget !== 'undefined') {
						// Fallback to rewards widget if available
						applyVoucherToCartRewardsWidget(voucherCode, this);
					} else {
						// Fallback to page redirect
						const cartUrl = new URL(window.location.origin + '/cart');
						cartUrl.searchParams.set('apply_coupon', voucherCode);
						window.location.href = cartUrl.toString();
					}
				}
			});
		}
	});
	
	function applyVoucherToCart(voucherCode, button) {
		const originalText = button.textContent;
		button.disabled = true;
		button.textContent = 'Applying...';
		
		jQuery.ajax({
			url: sparVoucherSuccess.ajaxUrl,
			type: 'POST',
			data: {
				action: 'spar_apply_voucher_to_cart',
				voucher_code: voucherCode,
				nonce: sparVoucherSuccess.applyVoucherNonce
			},
			success: function(response) {
				if (response.success) {
					button.textContent = 'Applied!';
					button.style.background = '#28a745';
					
					// Reload page to show updated cart status
					setTimeout(function() {
						window.location.reload();
					}, 1000);
				} else {
					button.disabled = false;
					button.textContent = originalText;
					alert(response.data || 'Could not apply voucher. Please try again.');
				}
			},
			error: function() {
				button.disabled = false;
				button.textContent = originalText;
				alert('Could not apply voucher. Please try again.');
			}
		});
	}
	
	function applyVoucherToCartRewardsWidget(voucherCode, button) {
		const originalText = button.textContent;
		button.disabled = true;
		button.textContent = 'Applying...';
		
		jQuery.ajax({
			url: sparRewardsWidget.ajaxUrl,
			type: 'POST',
			data: {
				action: 'spar_apply_voucher_to_cart',
				voucher_code: voucherCode,
				nonce: sparRewardsWidget.applyVoucherNonce
			},
			success: function(response) {
				if (response.success) {
					button.textContent = 'Applied!';
					button.style.background = '#28a745';
					
					// Reload page to show updated cart status
					setTimeout(function() {
						window.location.reload();
					}, 1000);
				} else {
					button.disabled = false;
					button.textContent = originalText;
					alert(response.data || 'Could not apply voucher. Please try again.');
				}
			},
			error: function() {
				button.disabled = false;
				button.textContent = originalText;
				alert('Could not apply voucher. Please try again.');
			}
		});
	}
})();
