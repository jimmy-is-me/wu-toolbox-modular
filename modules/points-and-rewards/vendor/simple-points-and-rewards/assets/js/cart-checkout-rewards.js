(function($) {
	'use strict';
	
	let rewardsBox;
	let dropdown;
	let isLoading = false;
	let eventsInitialized = false;
	let hasAppliedOpenTabFlag = false; // Prevent duplicate auto-open on repeated inits
	let refreshTimer = null;
	let layoutTimer = null;
	
	function getGlobalLimits() {
		if (typeof window.sparCartRewards === 'undefined' || !window.sparCartRewards.redeem) {
			return { min: 0, max: 0 };
		}
		var limits = window.sparCartRewards.redeem.limits || {};
		var min = parseInt(limits.min, 10);
		var max = parseInt(limits.max, 10);
		min = isNaN(min) ? 0 : min;
		max = isNaN(max) ? 0 : max;
		var userPoints = parseInt(window.sparCartRewards.userPoints || 0, 10) || 0;
		if (max > 0 && userPoints > 0) {
			max = Math.min(max, userPoints);
		}
		return { min: min, max: max };
	}

	function parseLimitAttr($el, attr) {
		if (!$el || !$el.length) return 0;
		var raw = parseInt($el.attr(attr), 10);
		return isNaN(raw) ? 0 : raw;
	}

	function getPointsLimits($box) {
		var $input = $box.find('.spar-redeem-input').first();
		var $slider = $box.find('.spar-redeem-slider').first();
		var min = parseLimitAttr($input, 'min');
		if (!min) {
			min = parseLimitAttr($slider, 'min');
		}
		var max = parseLimitAttr($input, 'max');
		if (!max) {
			max = parseLimitAttr($slider, 'max');
		}
		var globalLimits = getGlobalLimits();
		if (!min && globalLimits.min) {
			min = globalLimits.min;
		}
		if (globalLimits.max > 0) {
			if (!max || max > globalLimits.max) {
				max = globalLimits.max;
			}
		}
		return { min: min, max: max };
	}

	function normalizePointsValue($box, rawValue) {
		var limits = getPointsLimits($box);
		var val = parseInt(rawValue, 10);
		if (isNaN(val)) {
			val = 0;
		}
		if (val < 0) {
			val = 0;
		}
		if (limits.max > 0 && val > limits.max) {
			val = limits.max;
		}
		if (limits.min > 0 && val > 0 && val < limits.min) {
			val = limits.min;
		}
		return {
			value: val,
			min: limits.min,
			max: limits.max
		};
	}

	function syncPointsDiscountPanels($box, useCompact) {
		var $compact = $box.find('.spar-points-discount-panel--compact .spar-redeem-compact');
		var $regular = $box.find('.spar-points-discount-panel--regular .spar-redeem-compact');
		if (!$compact.length || !$regular.length) {
			return;
		}
		var $source = useCompact ? $compact : $regular;
		var $target = useCompact ? $regular : $compact;
		var rawValue = $source.find('.spar-redeem-input').val();
		var normalized = normalizePointsValue($source, rawValue);
		$target.find('.spar-redeem-input').val(normalized.value);
		$target.find('.spar-redeem-slider').val(normalized.value);
		updateRedeemValuePreview($target, normalized.value);
		var targetBlocked = $target.hasClass('spar-redeem-blocked');
		$target.find('.spar-redeem-apply-btn').prop('disabled', targetBlocked || normalized.value <= 0);
	}

	function updatePointsDiscountLayout() {
		$('.spar-cart-rewards-box').each(function() {
			var $box = $(this);
			var $header = $box.find('.spar-cart-rewards-header').first();
			if (!$header.length) {
				return;
			}
			var headerWidth = $header.outerWidth();
			var useCompact = headerWidth > 0 && headerWidth < 1000;
			$box.toggleClass('spar-points-discount-compact', useCompact);
			$box.toggleClass('spar-points-discount-regular', !useCompact);
			syncPointsDiscountPanels($box, useCompact);
		});
	}

	/**
	 * Initialize cart rewards functionality
	 */
	function initCartRewards() {
		rewardsBox = $('.spar-cart-rewards-box');
		dropdown = $('.spar-cart-rewards-dropdown');
		
		if (!rewardsBox.length) {
			// For block checkout, try to inject the rewards box
			if (typeof sparCartRewards !== 'undefined' && sparCartRewards.isBlockCheckout) {
				setTimeout(initCartRewards, 1000);
			}
			return;
		}
		
		// Only bind events once to prevent duplicate handlers
		if (!eventsInitialized) {
			bindEvents();
			eventsInitialized = true;
		}

		if (!(typeof sparCartRewards !== 'undefined' && sparCartRewards.isOrderReceived)) {
			refreshRewardsBox();
		}

		// If there's a flag to auto-open the vouchers tab after reload, do it once
		if (!hasAppliedOpenTabFlag) {
			try {
				if (window.sessionStorage && sessionStorage.getItem('sparOpenVouchersAfterReload') === '1') {
					sessionStorage.removeItem('sparOpenVouchersAfterReload');
					openDropdownAndTab('vouchers');
					hasAppliedOpenTabFlag = true;
					// Highlight the newly redeemed voucher and fire confetti if enabled
					try {
						var highlightCode = sessionStorage.getItem('sparHighlightVoucherCode');
						var fireConfetti = sessionStorage.getItem('sparFireConfettiAfterReload') === '1';
						if (highlightCode) { sessionStorage.removeItem('sparHighlightVoucherCode'); }
						if (fireConfetti) { sessionStorage.removeItem('sparFireConfettiAfterReload'); }
						if (highlightCode || fireConfetti) {
							setTimeout(function() {
								if (highlightCode) {
									$('.spar-voucher-item').each(function() {
										var $item = $(this);
										if ($item.find('.spar-voucher-code').text().trim().toLowerCase() === highlightCode.trim().toLowerCase()) {
											$item.addClass('spar-voucher-new-highlight');
											setTimeout(function() {
												$item.removeClass('spar-voucher-new-highlight');
											}, 5000);
										}
									});
								}
								if (fireConfetti && typeof window.confetti === 'function') {
									try {
										window.confetti({ origin: { y: 0.6 } });
										setTimeout(function() {
											window.confetti({ particleCount: 50, angle: 60, spread: 55, origin: { x: 0 } });
											window.confetti({ particleCount: 50, angle: 120, spread: 55, origin: { x: 1 } });
										}, 300);
									} catch(e) {}
								}
							}, 400);
						}
					} catch (e) {}
				}
			} catch (e) {
				// ignore storage errors
			}
		}

		updatePointsDiscountLayout();
	}
	
	/**
	 * Bind event handlers using event delegation to avoid duplicates
	 */
	function bindEvents() {
		// Remove any existing event handlers to prevent duplicates
		$(document).off('.sparCartRewards');
		$(window).off('resize.sparCartRewards');
		
		// Toggle dropdown using event delegation
		$(document).on('click.sparCartRewards', '.spar-redeem-toggle-btn', function(e) {
			e.preventDefault();
			e.stopPropagation();
			
			var $button = $(this);
			var $dropdown = $button.closest('.spar-cart-rewards-box').find('.spar-cart-rewards-dropdown');
			var isOpen = $dropdown.hasClass('active');
			
			if (isOpen) {
				$dropdown.removeClass('active').slideUp(300);
				$button.text(sparCartRewards.strings.redeemRewards || 'Redeem Rewards');
			} else {
				$dropdown.addClass('active').slideDown(300);
				$button.text(sparCartRewards.strings.close || 'Close');
			}
		});
		
		// Tab switching
		$(document).on('click.sparCartRewards', '.spar-dropdown-tab', function(e) {
			e.preventDefault();
			var $tab = $(this);
			var tabName = $tab.data('tab');
			var $container = $tab.closest('.spar-cart-rewards-dropdown');
			
			$container.find('.spar-dropdown-tab').removeClass('active');
			$tab.addClass('active');
			
			$container.find('.spar-dropdown-tab-content').removeClass('active');
			$container.find('.spar-dropdown-tab-content').filter(function() {
				return $(this).data('tab') === tabName;
			}).addClass('active');
		});
		
		// Redeem reward
		$(document).on('click.sparCartRewards', '.spar-redeem-btn', function(e) {
			e.preventDefault();
			if ($(this).prop('disabled') || isLoading) {
				return;
			}
			
			var $button = $(this);
			var rewardId = $button.data('reward-id');
			var originalText = $button.text();
			
			if (!rewardId) {
				alert('Invalid reward selected');
				return;
			}
			
			isLoading = true;
			$button.prop('disabled', true).text('Redeeming...');
			
			$.ajax({
				url: sparCartRewards.ajaxUrl,
				type: 'POST',
				data: {
					action: 'spar_redeem_reward_cart',
					reward_id: rewardId,
					nonce: sparCartRewards.redeemNonce
				},
				success: function(response) {
					if (response.success) {
						if (response.data.redirect_to_checkout) {
							window.location.href = response.data.checkout_url || sparCartRewards.checkoutUrl;
						} else {
							// Store sessionStorage flags then reload (with optional confetti first)
							var doReload = function() {
								try {
									if (window.sessionStorage) {
										sessionStorage.setItem('sparOpenVouchersAfterReload', '1');
										if (response.data && response.data.voucher_code) {
											sessionStorage.setItem('sparHighlightVoucherCode', response.data.voucher_code);
										}
									}
								} catch (e) {}
								window.location.reload();
							};
							if (sparCartRewards.enableConfetti && typeof window.confetti === 'function') {
								try {
									window.confetti({ origin: { y: 0.6 } });
									setTimeout(function() {
										window.confetti({ particleCount: 50, angle: 60, spread: 55, origin: { x: 0 } });
										window.confetti({ particleCount: 50, angle: 120, spread: 55, origin: { x: 1 } });
									}, 300);
								} catch(e) {}
								setTimeout(doReload, 1200);
							} else {
								doReload();
							}
						}
					} else {
						alert(response.data || sparCartRewards.strings.error);
						$button.prop('disabled', false).text(originalText);
					}
				},
				error: function() {
					alert(sparCartRewards.strings.error);
					$button.prop('disabled', false).text(originalText);
				},
				complete: function() {
					isLoading = false;
				}
			});
		});
		
		// Apply voucher
		$(document).on('click.sparCartRewards', '.spar-apply-voucher-btn', function(e) {
			e.preventDefault();
			if ($(this).prop('disabled') || isLoading) {
				return;
			}
			
			var $button = $(this);
			var voucherCode = $button.data('voucher-code');
			var originalText = $button.text();
			
			if (!voucherCode) {
				alert('Invalid voucher selected');
				return;
			}
			
			isLoading = true;
			$button.prop('disabled', true).text('Applying...');
			
			$.ajax({
				url: sparCartRewards.ajaxUrl,
				type: 'POST',
				data: {
					action: 'spar_apply_voucher_to_cart',
					voucher_code: voucherCode,
					nonce: sparCartRewards.applyVoucherNonce
				},
				success: function(response) {
					if (response.success) {
						window.location.reload();
					} else {
						alert(response.data || sparCartRewards.strings.error);
						$button.prop('disabled', false).text(originalText);
					}
				},
				error: function() {
					alert(sparCartRewards.strings.error);
					$button.prop('disabled', false).text(originalText);
				},
				complete: function() {
					isLoading = false;
				}
			});
		});

		// Redemption: sync number input and slider, and update value preview
		$(document).on('input change.sparCartRewards', '.spar-redeem-input, .spar-redeem-slider', function() {
			var $box = $(this).closest('.spar-redeem-compact');
			var clamped = normalizePointsValue($box, $(this).val());
			var $num = $box.find('.spar-redeem-input');
			var $range = $box.find('.spar-redeem-slider');
			$num.val(clamped.value);
			$range.val(clamped.value);
			updateRedeemValuePreview($box, clamped.value);
			// Toggle buttons enabled state (allow zero to disable redemption; keep
			// disabled when the cart is below the minimum total required to redeem)
			var boxBlocked = $box.hasClass('spar-redeem-blocked');
			$box.find('.spar-redeem-apply-btn').prop('disabled', boxBlocked || clamped.value <= 0);
			var $container = $box.closest('.spar-cart-rewards-box');
			if ($container.length) {
				var isCompactPanel = $box.closest('.spar-points-discount-panel--compact').length > 0;
				syncPointsDiscountPanels($container, isCompactPanel);
			}
		});

		// Redemption: apply
		$(document).on('click.sparCartRewards', '.spar-redeem-apply-btn', function(e) {
			e.preventDefault();
			if (isLoading) return;
			var $btn = $(this);
			var $box = $btn.closest('.spar-redeem-compact');
			// Do not apply when the cart is below the minimum total required to redeem.
			if ($box.hasClass('spar-redeem-blocked')) {
				return;
			}
			var normalized = normalizePointsValue($box, $box.find('.spar-redeem-input').val());
			var points = normalized.value;
			var $num = $box.find('.spar-redeem-input');
			var $range = $box.find('.spar-redeem-slider');
			$num.val(points);
			$range.val(points);
			if (points <= 0) {
				return;
			}
			var nonce = $btn.data('nonce') || (window.sparCartRewards && sparCartRewards.pointsRedeemNonce);
			isLoading = true;
			$btn.prop('disabled', true).text((sparCartRewards.strings && sparCartRewards.strings.add) || 'Add Discount to Cart');
			$.ajax({
				url: sparCartRewards.ajaxUrl,
				type: 'POST',
				data: {
					action: 'spar_apply_points_redemption',
					points: points,
					nonce: nonce
				},
				success: function(resp){
					if (resp && resp.success) {
						// Reload to refresh totals safely in all contexts
						window.location.reload();
					} else {
						alert((resp && resp.data) || (sparCartRewards.strings && sparCartRewards.strings.error) || 'Error');
						$btn.prop('disabled', false);
					}
				},
				error: function(){
					alert((sparCartRewards.strings && sparCartRewards.strings.error) || 'Error');
					$btn.prop('disabled', false);
				},
				complete: function(){
					isLoading = false;
				}
			});
		});

		// Redemption: remove
		$(document).on('click.sparCartRewards', '.spar-redeem-remove-btn', function(e) {
			e.preventDefault();
			if (isLoading) return;
			var $btn = $(this);
			var nonce = $btn.data('nonce') || (window.sparCartRewards && sparCartRewards.pointsRedeemNonce);
			isLoading = true;
			$btn.prop('disabled', true);
			$.ajax({
				url: sparCartRewards.ajaxUrl,
				type: 'POST',
				data: {
					action: 'spar_remove_points_redemption',
					nonce: nonce
				},
				success: function(resp){
					if (resp && resp.success) {
						window.location.reload();
					} else {
						alert((resp && resp.data) || (sparCartRewards.strings && sparCartRewards.strings.error) || 'Error');
						$btn.prop('disabled', false);
					}
				},
				error: function(){
					alert((sparCartRewards.strings && sparCartRewards.strings.error) || 'Error');
					$btn.prop('disabled', false);
				},
				complete: function(){
					isLoading = false;
				}
			});
		});
		
		// Close dropdown when clicking outside
		$(document).on('click.sparCartRewards', function(e) {
			if (!$(e.target).closest('.spar-cart-rewards-box').length) {
				closeDropdown();
			}
		});

		// Compact totals toggle: show/hide the panel under the Redeem button
		$(document).on('click.sparCartRewards', '.spar-compact-toggle-btn', function(e){
			e.preventDefault();
			var $btn = $(this);
			var $wrap = $btn.closest('.spar-redeem-compact');
			var $panel = $wrap.find('.spar-redeem-panel');
			var expanded = $btn.attr('aria-expanded') === 'true';
			$btn.attr('aria-expanded', expanded ? 'false' : 'true');
			$btn.toggleClass('is-open', !expanded);
			$panel.stop(true, true)[expanded ? 'slideUp' : 'slideDown'](200);
			if (!expanded) {
				// ensure preview updates
				$wrap.find('.spar-redeem-input').trigger('change');
			}
		});
		
		// Prevent dropdown from closing when clicking inside
		$(document).on('click.sparCartRewards', '.spar-cart-rewards-dropdown', function(e) {
			e.stopPropagation();
		});

		$(window).on('resize.sparCartRewards', function() {
			if (layoutTimer) {
				clearTimeout(layoutTimer);
			}
			layoutTimer = setTimeout(function() {
				updatePointsDiscountLayout();
			}, 100);
		});

	}
	
	/**
	 * Close dropdown
	 */
	function closeDropdown() {
		var $toggleBtn = $('.spar-redeem-toggle-btn');
		var $dropdown = $('.spar-cart-rewards-dropdown');
		
		$toggleBtn.text(sparCartRewards.strings.redeemRewards || 'Redeem Rewards');
		$dropdown.removeClass('active').slideUp(300);
	}

	/**
	 * Refresh the rewards header (title + messages + points summary) via AJAX.
	 */
	function refreshRewardsHeader() {
		if (typeof sparCartRewards === 'undefined') return;
		// Do not run AJAX header refresh on the Thank You page
		if (sparCartRewards.isOrderReceived) return;
		// Debounce rapid calls
		if (refreshTimer) {
			clearTimeout(refreshTimer);
		}
		refreshTimer = setTimeout(function() {
			var $header = $('#spar-cart-rewards-header');
			if (!$header.length) {
				// If header markup is missing (older markup or different placement), refresh the whole box
				return refreshRewardsBox();
			}
			$.ajax({
				url: sparCartRewards.ajaxUrl,
				type: 'POST',
				data: {
					action: 'spar_get_rewards_header',
					page: (sparCartRewards.isCart ? 'cart' : (sparCartRewards.isBlockCheckout ? 'checkout' : 'cart')),
					nonce: sparCartRewards.nonce
				},
				success: function(response) {
					if (response && response.success && typeof response.data.html !== 'undefined') {
						$header.html(response.data.html || '');
						updatePointsDiscountLayout();
					} else {
						// Fallback to full refresh if fragment request fails
						refreshRewardsBox();
					}
				}
			});
		}, 100);
	}

	/**
	 * Refresh the entire rewards box via AJAX and preserve dropdown/tab state.
	 */
	function refreshRewardsBox() {
		if (typeof sparCartRewards === 'undefined') return;
		// Do not run AJAX box refresh on the Thank You page
		if (sparCartRewards.isOrderReceived) return;
		var $box = $('.spar-cart-rewards-box').first();
		if (!$box.length) return;

		var wasOpen = $box.find('.spar-cart-rewards-dropdown').hasClass('active');
		var activeTab = $box.find('.spar-dropdown-tab.active').data('tab') || 'rewards';

		$.ajax({
			url: sparCartRewards.ajaxUrl,
			type: 'POST',
			data: {
				action: 'spar_get_rewards_box',
				page: (sparCartRewards.isCart ? 'cart' : (sparCartRewards.isBlockCheckout ? 'checkout' : 'cart')),
				nonce: sparCartRewards.nonce
			},
			success: function(response) {
				if (response && response.success && typeof response.data.html !== 'undefined') {
					$box.replaceWith(response.data.html || '');
					var $newBox = $('.spar-cart-rewards-box').first();
					if ($newBox.length && wasOpen) {
						var $toggle = $newBox.find('.spar-redeem-toggle-btn');
						var $dropdownEl = $newBox.find('.spar-cart-rewards-dropdown');
						$dropdownEl.addClass('active').show();
						$toggle.text((sparCartRewards.strings && sparCartRewards.strings.close) || 'Close');
						var $tabBtn = $dropdownEl.find('.spar-dropdown-tab[data-tab="' + activeTab + '"]');
						if ($tabBtn.length) {
							$dropdownEl.find('.spar-dropdown-tab').removeClass('active');
							$tabBtn.addClass('active');
							$dropdownEl.find('.spar-dropdown-tab-content').removeClass('active');
							$dropdownEl.find('.spar-dropdown-tab-content').filter(function(){
								return $(this).data('tab') === activeTab;
							}).addClass('active');
						}
					}
						updatePointsDiscountLayout();
				}
			}
		});
	}

	function getPriceFormatSettings() {
		var defaults = { decimals: 2, decimalSep: '.', thousandSep: ',' };
		if (typeof sparCartRewards === 'undefined') return defaults;
		var decimals = parseInt(sparCartRewards.priceDecimals, 10);
		if (isNaN(decimals)) decimals = defaults.decimals;
		return {
			decimals: decimals,
			decimalSep: sparCartRewards.priceDecimalSeparator || defaults.decimalSep,
			thousandSep: sparCartRewards.priceThousandSeparator || defaults.thousandSep
		};
	}

	function formatCurrencyAmount(amount) {
		var settings = getPriceFormatSettings();
		var decimals = settings.decimals;
		var fixed = Number(amount).toFixed(decimals);
		var parts = fixed.split('.');
		var integerPart = parts[0];
		var decimalPart = parts.length > 1 ? parts[1] : '';
		var rgx = /(\d+)(\d{3})/;
		while (rgx.test(integerPart)) {
			integerPart = integerPart.replace(rgx, '$1' + settings.thousandSep + '$2');
		}
		if (decimals > 0) {
			return integerPart + settings.decimalSep + decimalPart;
		}
		return integerPart;
	}

	function updateRedeemValuePreview($container, points) {
		try {
			var $val = $container.find('.spar-redeem-value');
			if (!$val.length) return;
			var ppp = parseFloat($val.data('ppp') || '0');
			var ppa = parseFloat($val.data('ppa') || '0');
			if (!(ppp > 0 && ppa > 0)) { $val.text('0'); return; }
			var amount = (points * ppa) / ppp;
			var cfg = window.sparCartRewards || {};
			var taxMode = cfg.taxDisplayMode || '';
			var taxRate = parseFloat($val.data('tax-rate') || cfg.taxRate || '0');
			if (!(taxRate >= 0)) taxRate = 0;
			var taxMul = parseFloat($val.data('tax-multiplier') || cfg.taxMultiplier || '1');
			if (!(taxMul > 0)) taxMul = 1;
			if (taxMode !== 'excl') {
				amount = amount * taxMul;
			}
			var settings = getPriceFormatSettings();
			var factor = Math.pow(10, settings.decimals);
			amount = Math.max(0, Math.floor(amount * factor) / factor);
			// Prefix with currency symbol when available
			var symbol = (function(){
				// prefer explicit data on value or wrapper
				var s = $val.data('currency-symbol');
				if (s) return s;
				var $wrap = $container.closest('.spar-redeem-compact');
				if ($wrap.length && $wrap.data('currencySymbol')) return $wrap.data('currencySymbol');
				if ($wrap.length && $wrap.data('currency')) return mapCurrencyToSymbol($wrap.data('currency'));
				return '';
			})();
			var displayText = (symbol || '') + formatCurrencyAmount(amount);
			if (taxMode === 'excl' && taxRate > 0) {
				var taxAmount = Math.max(0, Math.floor(amount * taxRate * factor) / factor);
				var taxLabel = (cfg.strings && cfg.strings.taxSuffix) ? cfg.strings.taxSuffix : 'tax';
				displayText += ' (+' + (symbol || '') + formatCurrencyAmount(taxAmount) + ' ' + taxLabel + ')';
			}
			$val.text(displayText);
		} catch (e) {}
	}

	function mapCurrencyToSymbol(code){
		try {
			code = (code || '').toUpperCase();
			switch(code){
				case 'USD': case 'AUD': case 'CAD': case 'NZD': case 'HKD': case 'SGD': return '$';
				case 'EUR': return '€';
				case 'GBP': return '£';
				case 'JPY': case 'CNY': return '¥';
				case 'INR': return '₹';
				case 'KRW': return '₩';
				case 'RUB': return '₽';
				case 'CHF': return 'CHF ';
				case 'SEK': return 'kr ';
				case 'NOK': return 'kr ';
				case 'DKK': return 'kr ';
				case 'PLN': return 'zł ';
				case 'CZK': return 'Kč ';
				case 'HUF': return 'Ft ';
				case 'TRY': return '₺';
				case 'ILS': return '₪';
				default: return code ? (code + ' ') : '';
			}
		} catch(e){ return ''; }
	}

	/**
	 * Open dropdown and switch to a specific tab (e.g., 'vouchers')
	 * @param {string} tabName
	 */
	function openDropdownAndTab(tabName) {
		var $box = $('.spar-cart-rewards-box');
		if (!$box.length) return;
		var $toggle = $box.find('.spar-redeem-toggle-btn');
		var $dropdownEl = $box.find('.spar-cart-rewards-dropdown');

		if (!$dropdownEl.hasClass('active')) {
			$dropdownEl.addClass('active').slideDown(300);
			$toggle.text((sparCartRewards.strings && sparCartRewards.strings.close) || 'Close');
		}

		// Activate the requested tab
		var $tabBtn = $dropdownEl.find('.spar-dropdown-tab[data-tab="' + tabName + '"]');
		if ($tabBtn.length) {
			$dropdownEl.find('.spar-dropdown-tab').removeClass('active');
			$tabBtn.addClass('active');
			$dropdownEl.find('.spar-dropdown-tab-content').removeClass('active');
			$dropdownEl.find('.spar-dropdown-tab-content').filter(function() {
				return $(this).data('tab') === tabName;
			}).addClass('active');
		}
	}
	
	// Initialize when DOM is ready
	$(document).ready(function() {
		// Bind events even when the rewards box isn't present (e.g., My Account Claim tab UI)
		if (!eventsInitialized) {
			bindEvents();
			eventsInitialized = true;
		}

		// Initialize discount preview for any standalone compact widgets (e.g., account tab)
		setTimeout(function() {
			$('.spar-redeem-compact').each(function() {
				var $box = $(this);
				var $num = $box.find('.spar-redeem-input');
				if ($num.length) {
					var val = parseInt($num.val() || '0', 10) || 0;
					updateRedeemValuePreview($box, val);
					// Ensure apply button enabled/disabled matches value (and stays
					// disabled when the cart is below the minimum redemption total)
					$box.find('.spar-redeem-apply-btn').prop('disabled', $box.hasClass('spar-redeem-blocked') || val <= 0);
				}
			});
		}, 100);

		// Small delay to ensure all WooCommerce scripts have loaded, then init full rewards box behavior
		setTimeout(function() { initCartRewards(); }, 150);
		
		// On the Thank You page, move the rewards box to the top of the content area
		try {
			if (typeof sparCartRewards !== 'undefined' && sparCartRewards.isOrderReceived) {
				const moveToTop = function() {
					var $box = $('.spar-cart-rewards-box');
					if (!$box.length) return false;
					var $container = $('.woocommerce-order');
					if (!$container.length) {
						$container = $('.woocommerce');
					}
					if ($container.length) {
						$box.prependTo($container.first());
						return true;
					}
					return false;
				};

				// Try immediately and then observe DOM changes if not yet present
				if (!moveToTop() && window.MutationObserver) {
					const observer = new MutationObserver(function(mutations) {
						if (moveToTop()) {
							observer.disconnect();
						}
					});
					observer.observe(document.body, { childList: true, subtree: true });
				}
			}
		} catch (e) {}

		// For block checkout, also listen for WooCommerce events
		if (typeof sparCartRewards !== 'undefined' && sparCartRewards.isBlockCheckout) {
			// Re-initialize when the checkout form updates
			$(document.body).on('checkout_error updated_checkout', function() {
				setTimeout(initCartRewards, 500);
			});
			
			// Monitor for DOM changes in block checkout
			if (window.MutationObserver) {
				const observer = new MutationObserver(function(mutations) {
					mutations.forEach(function(mutation) {
						if (mutation.type === 'childList' && mutation.addedNodes.length > 0) {
							// Check if checkout blocks were added
							for (let node of mutation.addedNodes) {
								if (node.nodeType === 1 && (
									node.classList.contains('wc-block-checkout') ||
									node.querySelector('.wc-block-checkout')
								)) {
									setTimeout(initCartRewards, 500);
									break;
								}
							}
						}
					});
				});
				
				observer.observe(document.body, {
					childList: true,
					subtree: true
				});
			}
		}

		// If on the cart page, add robust listeners for both classic and Cart Block
		if (typeof sparCartRewards !== 'undefined' && sparCartRewards.isCart) {
			// Classic cart is already covered by jQuery events below; add block-specific hooks
			var $cartBlock = $('.wc-block-cart');
			if ($cartBlock.length && window.MutationObserver) {
				// Observe cart block DOM changes (items, totals, etc) and refresh box
				const cartObserver = new MutationObserver(function(mutations) {
					// Debounce rapid mutations
					if (refreshTimer) { clearTimeout(refreshTimer); }
					refreshTimer = setTimeout(function(){ refreshRewardsBox(); }, 250);
				});
				cartObserver.observe($cartBlock.get(0), { childList: true, subtree: true, characterData: true });
			}

			// Listen to Cart Block quantity controls and update triggers
			$(document).on('click input change', '.wc-block-components-quantity-selector__button, .wc-block-components-quantity-selector__input', function() {
				if (refreshTimer) { clearTimeout(refreshTimer); }
				refreshTimer = setTimeout(function(){ refreshRewardsBox(); }, 400);
			});

			// When the block updates totals or cart is refreshed, run a refresh
			$(document.body).on('wc-blocks_added_to_cart wc-blocks_removed_from_cart wc-blocks_cart_updated', function(){
				setTimeout(function(){ refreshRewardsBox(); }, 400);
			});
		}
	});
	
	// Re-initialize on AJAX updates (for dynamic cart updates)
	$(document.body).on('updated_wc_div updated_checkout updated_cart_totals applied_coupon_in_cart removed_coupon_in_cart cart_totals_refreshed wc_fragments_refreshed wc_fragments_loaded', function() {
		// Skip on Thank You page
		if (typeof sparCartRewards !== 'undefined' && sparCartRewards.isOrderReceived) return;
		// Don't re-bind events, just refresh the elements
		setTimeout(function() {
			rewardsBox = $('.spar-cart-rewards-box');
			dropdown = $('.spar-cart-rewards-dropdown');
			refreshRewardsBox();
		}, 500);
	});

	// Also listen to quantity field changes to trigger a header refresh sooner
	$(document).on('change input', 'input.qty', function() {
		if (typeof sparCartRewards !== 'undefined' && sparCartRewards.isOrderReceived) return;
		refreshRewardsBox();
	});
	
		/**
		 * For WooCommerce Blocks totals: append a plain Remove link to the Points Redemption fee row label.
		 * Be tolerant to markup variations across WC Blocks versions by probing multiple selectors.
		 */
		function sparAttachRemoveLinkToBlocksFee(){
			try {
				if (typeof sparCartRewards === 'undefined') return;
				var prefix = (sparCartRewards.strings && sparCartRewards.strings.pointsRedemptionPrefix) || 'Points Redemption';
				// Look in totals areas and order summary containers (broadened for new markup variants)
				var containers = document.querySelectorAll('.wc-block-components-totals, .wc-block-components-order-summary, .wc-block-components-totals-wrapper, .wc-block-checkout .wc-block-components-panel');
				// If no explicit containers found, fall back to scanning all fee/total items directly
				if (!containers.length) {
					containers = [ document.body ];
				}
				containers.forEach(function(cont){
					// Each totals row in Blocks
					var items = cont.querySelectorAll('.wc-block-components-totals-item, [class*="wc-block-components-order-summary-item"], li[class*="order-summary-item"], .components-panel__row');
					// Always include rows with a points-redemption class (future-proof)
					var extra = cont.querySelectorAll('[class*="points-redemption"]');
					if (extra && extra.length) {
						items = Array.from(new Set([].concat(Array.from(items), Array.from(extra))));
					}
					items.forEach(function(item){
						try {
							// Try common label selectors first
							var labelEl = item.querySelector('.wc-block-components-totals-item__label, .wc-block-components-totals-item__description, [class*="totals-item__label"], [class*="totals-item__description"], [class*="order-summary-item__label"], [class*="order-summary-item__description"]');
							if (!labelEl) { labelEl = item; }
							var text = ((labelEl.textContent || '').trim() || '').toLowerCase();
							if (!text || text.indexOf(String(prefix).toLowerCase()) === -1) return;
							// Don't add twice
							if (item.querySelector('.spar-redeem-remove-btn')) return;
							// Build link
							var sep = document.createElement('span');
							sep.className = 'spar-sep';
							sep.textContent = ' - ';
							var a = document.createElement('a');
							a.href = '#';
							a.className = 'link-button spar-redeem-remove-btn';
							a.textContent = (sparCartRewards.strings && sparCartRewards.strings.remove) || 'Remove';
							a.setAttribute('data-nonce', (sparCartRewards.pointsRedeemNonce || ''));
							// Prefer appending inside the label element for better alignment
							(labelEl || item).appendChild(sep);
							(labelEl || item).appendChild(a);
						} catch(e){}
					});
				});
			} catch(e){}
		}

		// Run once on ready and also after checkout updates
		$(function(){ setTimeout(sparAttachRemoveLinkToBlocksFee, 300); });
		$(document.body).on('updated_checkout wc-blocks_cart_updated wc_fragments_refreshed wc_fragments_loaded', function(){
			setTimeout(sparAttachRemoveLinkToBlocksFee, 200);
		});
		// Re-run when totals change: observe all totals containers that may render dynamically
		if (window.MutationObserver) {
			try {
				var totalsContainers = document.querySelectorAll('.wc-block-components-totals, .wc-block-components-order-summary, .wc-block-components-totals-wrapper');
				if (!totalsContainers.length) {
					// Observe entire summary region as a fallback
					var summaryFallback = document.querySelector('.wc-block-checkout, .wp-block-woocommerce-checkout');
					if (summaryFallback) {
						var moAll = new MutationObserver(function(){ sparAttachRemoveLinkToBlocksFee(); });
						moAll.observe(summaryFallback, { childList: true, subtree: true });
					}
				}
				else {
					totalsContainers.forEach(function(t){
						try {
							var mo = new MutationObserver(function(){ sparAttachRemoveLinkToBlocksFee(); });
							mo.observe(t, { childList: true, subtree: true, characterData: true });
						} catch(e){}
					});
				}
			} catch(e){}
		}

})(jQuery);
