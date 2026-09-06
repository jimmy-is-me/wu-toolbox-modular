(function($) {
	'use strict';
	
	$(document).ready(function() {
		
		// Tab switching functionality
		// Bind both direct and delegated to be extra robust
		$('.nav-tab-wrapper .nav-tab').on('click', function(e) {
			e.preventDefault();
			e.stopPropagation();
			
			var tab = $(this).data('tab');
			
			// Check if target tab is disabled
			var $targetNavTab = $('.nav-tab[data-tab="' + tab + '"]');
			if ($targetNavTab.hasClass('spar-tab-disabled') || $targetNavTab.hasClass('spar-premium-disabled')) {
				return; // Don't switch to disabled tabs
			}
			
			// Update active tab
			$('.nav-tab').removeClass('nav-tab-active');
			$(this).addClass('nav-tab-active');

			// Hide save button on PRO sales page tabs (no settings to save)
			if ( $(this).hasClass('spar-pro-tab') ) {
				$('.spar-save-actions').hide();
			} else {
				$('.spar-save-actions').show();
			}

			// Show corresponding tab content (defensive: toggle class and inline style)
			var $allTabs = $('#spar-settings-tabs .spar-settings-tab');
			var $target = $('#spar-settings-tabs .spar-settings-tab[data-tab="' + tab + '"]');
			$allTabs.addClass('spar-hidden').hide();
			if ($target.length) {
				$target.removeClass('spar-hidden').show();
			} else {
				// Fallback: if matching panel not found (e.g., PRO tab without content), keep current visible or show Rewards
				var $current = $('#spar-settings-tabs .spar-settings-tab:visible').first();
				if ($current.length) {
					$current.removeClass('spar-hidden').show();
				} else {
					$('#spar-settings-tabs .spar-settings-tab[data-tab="rewards"]').removeClass('spar-hidden').show();
				}
			}

			// When switching tabs, refresh Email per-type visibility (in case toggles changed)
			if (typeof window.sparRefreshEmailPerTypeVisibility === 'function') {
				window.sparRefreshEmailPerTypeVisibility();
			}
		});
				// Accordion functionality
		$('.spar-accordion-header').on('click', function(e) {
			var $header = $(this);
			// If this accordion header doesn't use the checkbox-driven pattern, don't hijack clicks.
			var $checkbox = $header.find('input[type="checkbox"]').first();
			if (!$checkbox.length) {
				return;
			}
			// Don't toggle if clicking action buttons/controls in header.
			if ($(e.target).closest('button, a, .spar-reward-actions, .spar-level-actions, .spar-earn-actions, .spar-drag-handle').length) {
				return;
			}
			// Don't toggle if clicking on the toggle switch itself
			if ($(e.target).is('input[type="checkbox"]') || $(e.target).hasClass('spar-toggle-slider')) {
				return;
			}

			var $accordion = $header.closest('.spar-accordion');

			// Earn tab accordions: only open/close body, do NOT toggle the enable checkbox.
			// If the accordion is not enabled, clicking the header does nothing.
			if ( $accordion.hasClass('spar-earn-accordion') ) {
				if ( ! $checkbox.is(':checked') ) {
					// Disabled — not clickable; only the toggle switch itself can enable it.
					// Prevent the wrapping <label> from natively toggling the checkbox.
					e.preventDefault();
					return;
				}
				e.preventDefault();
				e.stopPropagation();
				var $body = $accordion.find('.spar-accordion-body').first();
				$body.slideToggle();
				return;
			}

			e.preventDefault();
			e.stopPropagation();
			
			var $body = $accordion.find('.spar-accordion-body');
			
			// Toggle the checkbox state
			$checkbox.prop('checked', !$checkbox.prop('checked')).trigger('change');
		});

		// Earn accordion "Edit" button: open/close the body (only when enabled).
		$(document).on('click', '.spar-earn-accordion .spar-earn-actions .spar-toggle-earn', function(e) {
			e.preventDefault();
			e.stopPropagation();
			var $accordion = $(this).closest('.spar-earn-accordion');
			var $checkbox = $accordion.find('.spar-accordion-header input[type="checkbox"]').first();
			if ( ! $checkbox.is(':checked') ) {
				return;
			}
			$accordion.find('.spar-accordion-body').first().slideToggle();
		});
		
		// Handle checkbox changes for accordion state
		$('.spar-accordion-header input[type="checkbox"]').on('change', function(e) {
			// Do not stop propagation so auto-save can detect the change
			// e.stopPropagation();
			
			var $checkbox = $(this);
			var $accordion = $checkbox.closest('.spar-accordion');
			var $body = $accordion.find('.spar-accordion-body');

			// Earn tab accordions: when toggled off, close the body.
			// When toggled on, add enabled class and open the body.
			if ( $accordion.hasClass('spar-earn-accordion') ) {
				if ( $checkbox.is(':checked') ) {
					$accordion.addClass('spar-earn-enabled');
					$body.slideDown();
				} else {
					$accordion.removeClass('spar-earn-enabled');
					$body.slideUp();
				}
				return;
			}
			
			// Toggle accordion based on checkbox state
			if ($checkbox.is(':checked')) {
				$body.slideDown();
			} else {
				$body.slideUp();
			}
		});
		
		// Initialize accordion states on page load
		$('.spar-accordion-header input[type="checkbox"]').each(function() {
			var $checkbox = $(this);
			var $accordion = $checkbox.closest('.spar-accordion');
			var $body = $accordion.find('.spar-accordion-body');

			// For earn accordions, initialise the enabled class but keep body closed.
			if ( $accordion.hasClass('spar-earn-accordion') ) {
				if ( $checkbox.is(':checked') ) {
					$accordion.addClass('spar-earn-enabled');
				}
				$body.hide();
				return;
			}
			
			if ($checkbox.is(':checked')) {
				$body.show();
			} else {
				$body.hide();
			}
		});
		
		// Settings page specific functionality
		initSettingsPageFeatures();
		initMirrorSync();
		initAutoSave();

		// Ensure correct initial tab content is visible on load
		(function ensureInitialTabVisible(){
			var $active = $('.nav-tab-wrapper .nav-tab.nav-tab-active').first();
			var initTab = $active.data('tab');
			if (!initTab) { return; }
			var $target = $('#spar-settings-tabs .spar-settings-tab[data-tab="' + initTab + '"]');
			if ($target.length) {
				$('#spar-settings-tabs .spar-settings-tab').addClass('spar-hidden').hide();
				$target.removeClass('spar-hidden').show();
			}
			// Hide save button if the initial active tab is a PRO sales page tab
			if ( $active.hasClass('spar-pro-tab') ) {
				$('.spar-save-actions').hide();
			}
		})();

		// Vanilla JS fallback for tab switching (in case jQuery handlers fail/are removed)
		(function bindVanillaTabSwitcher(){
			var wrapper = document.querySelector('.nav-tab-wrapper');
			if (!wrapper) return;
			wrapper.addEventListener('click', function(ev){
				var link = ev.target && ev.target.closest ? ev.target.closest('.nav-tab') : null;
				if (!link || !wrapper.contains(link)) return;
				if (link.classList.contains('spar-tab-disabled') || link.classList.contains('spar-premium-disabled')) return;
				ev.preventDefault();
				ev.stopPropagation();
				var tab = link.getAttribute('data-tab');
				if (!tab) return;
				// Activate nav
				Array.prototype.forEach.call(wrapper.querySelectorAll('.nav-tab'), function(n){ n.classList.remove('nav-tab-active'); });
				link.classList.add('nav-tab-active');
				// Toggle panels
				var all = document.querySelectorAll('.spar-settings-tab');
				Array.prototype.forEach.call(all, function(p){ p.classList.add('spar-hidden'); p.style.display = 'none'; });
				var target = document.querySelector('.spar-settings-tab[data-tab="' + tab + '"]');
				if (target) { target.classList.remove('spar-hidden'); target.style.display = 'block'; }
				// Refresh dependent UI if available
				try { if (typeof window.sparRefreshEmailPerTypeVisibility === 'function') { window.sparRefreshEmailPerTypeVisibility(); } } catch(e){}
			}, true);
		})();

		// Prevent hidden tab required fields from blocking form submission
		(function handleHiddenRequiredFieldsOnSubmit(){
			var $form = $('.spar-settings-wrap form');
			if (!$form.length) { return; }
			$form.on('submit', function(){
				// For each hidden tab, temporarily remove required attributes
				$('.spar-settings-tab').each(function(){
					var $tab = $(this);
					if ($tab.is(':hidden')) {
						$tab.find('input, select, textarea').each(function(){
							var $el = $(this);
							if ($el.prop('required')) {
								$el.data('sparRequired', true);
								$el.prop('required', false);
							}
						});
					}
				});
				// Allow submit to proceed; on next tick, restore attributes so UI remains consistent if validation happens again
				setTimeout(function(){
					$('.spar-settings-tab').each(function(){
						var $tab = $(this);
						if ($tab.is(':hidden')) {
							$tab.find('input, select, textarea').each(function(){
								var $el = $(this);
								if ($el.data('sparRequired')) {
									$el.prop('required', true).removeData('sparRequired');
								}
							});
						}
					});
				}, 0);
			});
		})();

		// Buttons that switch to another settings tab
		$(document).on('click', '.spar-switch-tab', function() {
			var target = $(this).data('target-tab');
			if (target && typeof window.sparSwitchToTab === 'function') {
				window.sparSwitchToTab(target);
			}
		});

		// Permanently hide the Quick Setup Guide for the current user.
		$(document).on('click', '#spar-hide-setup-guide', function() {
			var $btn = $(this);
			$btn.prop('disabled', true);
			$.post(
				ajaxurl,
				{
					action: 'spar_hide_setup_guide',
					nonce: sparSettings.nonce
				},
				function() {
					$('#spar-setup-guide').slideUp(300);
				}
			).fail(function() {
				$btn.prop('disabled', false);
			});
		});

		// Toggle switch click handling for fancy slider/track (supports checkbox and radio)
		$(document).on('click', '.spar-toggle-slider', function(e) {
			// Prevent label default double-toggle and stop bubbling to headers/containers
			e.preventDefault();
			e.stopPropagation();
			var $input = $(this).closest('.spar-toggle-switch').find('input').first();
			if (!$input.length) return;
			if ($input.prop('disabled')) return; // Respect disabled state

			if ($input.is(':checkbox')) {
				$input.prop('checked', !$input.prop('checked')).trigger('change');
			} else if ($input.is(':radio')) {
				// Radios should be set to checked when clicking the slider
				$input.prop('checked', true).trigger('change');
			}
		});

		// Also allow clicking anywhere on the switch track to toggle
		$(document).on('click', '.spar-toggle-switch', function(e) {
			// Ignore direct clicks on the actual input (handled separately)
			if ($(e.target).is('input')) return;
			// If the slider handled it, skip (it already prevented default)
			if ($(e.target).hasClass('spar-toggle-slider')) return;
			var $input = $(this).find('input').first();
			if (!$input.length || $input.prop('disabled')) return;
			// Prevent label default double toggle
			e.preventDefault();
			e.stopPropagation();
			if ($input.is(':checkbox')) {
				$input.prop('checked', !$input.prop('checked')).trigger('change');
			} else if ($input.is(':radio')) {
				$input.prop('checked', true).trigger('change');
			}
		});

		// Copy shortcodes list to clipboard
		$(document).on('click', '.spar-copy-shortcodes', function(e) {
			e.preventDefault();
			var $button = $(this);
			var targetSelector = $button.data('copy-target');
			var copiedText = $button.data('copied-text') || 'Copied!';
			var errorText = $button.data('error-text') || 'Copy failed.';
			var $target = $(targetSelector);
			var $status = $button.closest('.spar-shortcode-copy').find('.spar-copy-status');
			if (!$target.length) {
				$status.text(errorText);
				return;
			}
			var text = $target.val ? $target.val() : '';

			function showStatus(message) {
				if ($status.length) {
					$status.text(message);
				}
			}

			if (navigator.clipboard && window.isSecureContext) {
				navigator.clipboard.writeText(text).then(function() {
					showStatus(copiedText);
				}).catch(function() {
					showStatus(errorText);
				});
				return;
			}

			$target.prop('readonly', false);
			if ($target[0] && $target[0].select) {
				$target[0].select();
				if ($target[0].setSelectionRange) {
					$target[0].setSelectionRange(0, text.length);
				}
			}
			var copied = false;
			try {
				copied = document.execCommand('copy');
			} catch (err) {
				copied = false;
			}
			$target.prop('readonly', true);
			if (copied) {
				showStatus(copiedText);
			} else {
				showStatus(errorText);
			}
		});

		// Shortcodes tab switching
		$(document).on('click', '.spar-shortcode-tabs-nav .spar-shortcode-tab', function(e) {
			e.preventDefault();
			var $button = $(this);
			var target = $button.data('tab');
			var $wrap = $button.closest('.spar-shortcode-tabs');
			if (!target || !$wrap.length) {
				return;
			}

			$wrap.find('.spar-shortcode-tab').removeClass('is-active').attr('aria-selected', 'false');
			$button.addClass('is-active').attr('aria-selected', 'true');
			$wrap.find('.spar-shortcode-panel').removeClass('is-active');
			$wrap.find('#' + target).addClass('is-active');
		});

		// Prevent input clicks from bubbling up when inside custom switch
		$(document).on('click', '.spar-toggle-switch input', function(e) {
			e.stopPropagation();
		});

		// Removed AJAX saving on settings page: options are now saved on full form submit only.
	});
	
	function initSettingsPageFeatures() {
		// Sync all referral offer enabled checkboxes
		syncOfferCheckboxes();

		// Keep duplicated toggles in sync across tabs (including PRO Modules)
		(function initDuplicateToggleSync(){
			// List of setting names that can appear in multiple tabs (canonical field will submit; clones just mirror)
			var names = [
				'first_order_enabled','nth_order_enabled','review_enabled','birthday_enabled', // Earn extras
				'rewards_widget_enabled','referral_gift_widget_enabled','referral_offer_enabled', // Widgets & referral coupons
				'levels_enabled',
				'auto_delete_used','auto_delete_expired','auto_delete_expired_rewards' // General cleanups
			];

			// Ensure only one input actually submits per name (avoid duplicate POST collisions).
			// For 'levels_enabled' prefer an input marked data-canonical-toggle="true" so the Levels tab remains functional.
			// Fallback order: [data-canonical-toggle] > [data-ajax-save] > first input.
			names.forEach(function(name){
				var $inputs = $('input[name="' + name + '"]');
				if ($inputs.length > 1) {
					var $canonical = $inputs.filter('[data-canonical-toggle="true"]').first();
					if (!$canonical.length) { $canonical = $inputs.filter('[data-ajax-save="true"]').first(); }
					if (!$canonical.length) { $canonical = $inputs.first(); }
					$inputs.each(function(){
						var $el = $(this);
						if ($el.is($canonical)) { return; }
						$el.attr('data-name', name).removeAttr('name');
					});
				}
			});

			// Prevent recursive loops while syncing
			var syncing = {};

			// Mirror state across named canonical and any de-named mirrors, and avoid AJAX when toggled from PRO (de-named) clones
			var selector = names.map(function(n){ return 'input[name="' + n + '"], input[data-name="' + n + '"]'; }).join(', ');
			$(document).on('change', selector, function(){
				var $src = $(this);
				var key = $src.attr('name') || $src.data('name');
				if (!key) { return; }
				if (syncing[key]) { return; }
				syncing[key] = true;

				var isChecked = $src.is(':checked');
				var $canonical = $('input[name="' + key + '"]').first();

				// Set canonical value
				if ($canonical.length && !$canonical.is($src)) {
					$canonical.prop('checked', isChecked);
				}
				// Mirror all clones visually
				$('input[name="' + key + '"], input[data-name="' + key + '"]').prop('checked', isChecked);

				var triggeredFromClone = !$src.is($canonical);
				// If the change originated from a clone (e.g., PRO tab), avoid triggering canonical 'change'
				// to prevent AJAX saves; call lightweight UI updaters where applicable.
				if (triggeredFromClone) {
					try {
						if (key === 'rewards_widget_enabled' && typeof handleRewardsWidgetToggle === 'function') {
							handleRewardsWidgetToggle();
						}
						if (key === 'referral_offer_enabled' && typeof handleReferralOffersToggle === 'function') {
							handleReferralOffersToggle();
						}
					} catch(e) { /* no-op */ }
				}
				// Always update UI for levels_enabled when it changes
				if (key === 'levels_enabled' && typeof window.sparToggleLevelsUI === 'function') {
					window.sparToggleLevelsUI();
				}

				syncing[key] = false;
			});
		})();
		
		// Initialize widget settings toggles
		initWidgetToggles();
		initRewardsWidgetIconControls();
		initRewardsWidgetIconUploader();
		initMediaUploadButtons();
		initPointsIconPicker();

		// Initialize color picker syncing
		initColorPickers();
		
		// Add tab switching helper function to global scope
		window.sparSwitchToTab = function(tabName) {
			// Remove active class from all tabs and tab content
			const tabLinks = document.querySelectorAll('.nav-tab');
			const tabContents = document.querySelectorAll('.spar-settings-tab');
			
			tabLinks.forEach(function(tab) {
				tab.classList.remove('nav-tab-active');
			});
			
			tabContents.forEach(function(content) {
				content.classList.add('spar-hidden');
				content.style.display = 'none';
			});
			
			// Activate the target tab
			const targetTabLink = document.querySelector('.nav-tab[data-tab="' + tabName + '"]');
			const targetTabContent = document.querySelector('.spar-settings-tab[data-tab="' + tabName + '"]');
			
			if (targetTabLink && targetTabContent) {
				targetTabLink.classList.add('nav-tab-active');
				targetTabContent.classList.remove('spar-hidden');
				targetTabContent.style.display = 'block';
				
				// Scroll to the top of the tab content
				targetTabContent.scrollIntoView({ behavior: 'smooth' });
			}
		};
		
		// Handle referral earning type dropdown change
		function handleReferralEarningTypeChange() {
			const earningType = $('#referral_earning_type').val();
			const fixedSettings = $('#referral_fixed_settings');
			const percentageSettings = $('#referral_percentage_settings');
			if (!fixedSettings.length || !percentageSettings.length) return;
			if (earningType === 'fixed') {
				fixedSettings.removeClass('spar-hidden');
				percentageSettings.addClass('spar-hidden');
			} else if (earningType === 'percentage') {
				fixedSettings.addClass('spar-hidden');
				percentageSettings.removeClass('spar-hidden');
			}
		}
		
		// Handle referral offer type dropdown change
		function handleReferralOfferTypeChange() {
			const offerType = $('select[name="referral_offer_type"]').val();
			const discountValueField = $('#discount-value-field');
			
			if (offerType === 'discount') {
				discountValueField.show();
			} else {
				discountValueField.hide();
			}
		}
		
		// Handle rewards widget enable/disable
		function handleRewardsWidgetToggle() {
			const isEnabled = $('input[name="rewards_widget_enabled"]').is(':checked');
			const settingsContainer = $('#spar-rewards-widget-settings');
			
			if (isEnabled) {
				settingsContainer.removeClass('spar-hidden').show();
			} else {
				settingsContainer.addClass('spar-hidden').hide();
			}
		}

		// Handle dark mode toggle dependent row
		function handleRewardsWidgetDarkModeToggle() {
			const isEnabled = $('input[name="rewards_widget_dark_mode_toggle"]').is(':checked');
			const $row = $('[data-toggle-input="rewards_widget_dark_mode_toggle"]');
			if (!$row.length) {
				return;
			}
			if (isEnabled) {
				$row.removeClass('spar-hidden').show();
			} else {
				$row.addClass('spar-hidden').hide();
			}
		}
		
		// Handle referral gift offers enable/disable
		function handleReferralOffersToggle() {
			const isEnabled = $('input[name="referral_offer_enabled"]').is(':checked');
			const settingsContainer = $('#spar-offers-coupon-settings');
			
			settingsContainer.toggleClass('spar-hidden', !isEnabled);
		}

		function toggleDependentFieldVisibility($fields, isEnabled) {
			if (!$fields.length) {
				return;
			}

			$fields.each(function() {
				const $field = $(this);
				if (isEnabled) {
					if ($field.hasClass('spar-hidden')) {
						$field.removeClass('spar-hidden').hide();
					}
					if (!$field.is(':visible')) {
						$field.stop(true, true).slideDown();
					} else {
						$field.show();
					}
				} else {
					if ($field.is(':visible')) {
						$field.stop(true, true).slideUp(function() {
							$field.addClass('spar-hidden');
						});
					} else {
						$field.addClass('spar-hidden').hide();
					}
				}
			});
		}

		function initToggleDependentFields(settingName) {
			const $toggle = $('input[name="' + settingName + '"]');
			const $fields = $('[data-toggle-input="' + settingName + '"]');
			if (!$toggle.length || !$fields.length) {
				return;
			}

			const refreshVisibility = function() {
				const isEnabled = $toggle.is(':checked');
				toggleDependentFieldVisibility($fields, isEnabled);
			};

			refreshVisibility();
			$toggle.on('change', refreshVisibility);
		}

		// Show/hide the "Product Pages: Points Display" section based on whether
		// any order-spending earn methods (Points for Spending / Points for Orders) are enabled.
		function initProductPointsDisplaySectionVisibility() {
			var $section = $('#spar-product-points-display-section');
			if (!$section.length) {
				return;
			}

			function isOrderEarningEnabled() {
				return $('input[name="order_enabled"]').is(':checked') ||
					$('input[name="order_fixed_enabled"]').is(':checked');
			}

			function refreshProductPointsSection() {
				var $notice = $('#spar-product-points-earning-notice');
				if (isOrderEarningEnabled()) {
					$section.stop(true, true).slideDown();
					$notice.hide();
				} else {
					$section.stop(true, true).slideUp();
					$notice.show();
				}
			}

			// Run on change of either earn-method toggle.
			$(document).on('change', 'input[name="order_enabled"], input[name="order_fixed_enabled"]', refreshProductPointsSection);
		}

		// Handle inactivity expiry dependent fields visibility
		function handlePointsExpiryToggle() {
			const $container = $('.spar-inactivity-expiry-settings');
			if (!$container.length) {
				return;
			}
			const isEnabled = $('input[name="points_inactivity_expiry_enabled"]').is(':checked');
			if (isEnabled) {
				$container.stop(true, true).slideDown();
			} else {
				$container.stop(true, true).slideUp();
			}
			handlePointsInactivityDashboardNoticeToggle();
		}

		function handlePointsInactivityDashboardNoticeToggle() {
			const $noticeSettings = $('.spar-inactivity-dashboard-notice-settings');
			if (!$noticeSettings.length) {
				return;
			}
			const featureEnabled = $('input[name="points_inactivity_expiry_enabled"]').is(':checked');
			const noticeEnabled = $('input[name="points_inactivity_dashboard_notice_enabled"]').is(':checked');
			if (featureEnabled && noticeEnabled) {
				$noticeSettings.stop(true, true).slideDown();
			} else {
				$noticeSettings.stop(true, true).slideUp();
			}
		}
		
		// Initialize on page load
		handleReferralEarningTypeChange();
		handleReferralOfferTypeChange();
		handleRewardsWidgetToggle();
		handleRewardsWidgetDarkModeToggle();
		handleReferralOffersToggle();
		handlePointsExpiryToggle();
		handlePointsInactivityDashboardNoticeToggle();
		initProductPointsDisplaySectionVisibility();
		initToggleDependentFields('show_on_product');
		initToggleDependentFields('show_on_product_loop');
		initToggleDependentFields('redeem_individual_enabled');
		initToggleDependentFields('rewards_widget_dark_mode_toggle');
		initToggleDependentFields('dashboard_dark_mode_toggle');
		initToggleDependentFields('dashboard_dark_mode_default');
		initToggleDependentFields('terms_enabled');
		initToggleDependentFields('earn_points_text_enabled');

		// ---- Generate Terms and Conditions ----
		(function initGenerateTerms() {
			var $btn     = $('#spar-generate-terms-btn');
			var $spinner = $('#spar-generate-terms-spinner');
			var $msg     = $('#spar-generate-terms-msg');
			if (!$btn.length) return;

			var strings = (typeof sparSettings !== 'undefined' && sparSettings.strings) ? sparSettings.strings : {};
			var ajaxUrl = (typeof sparSettings !== 'undefined') ? sparSettings.ajaxUrl : '';
			var nonce   = (typeof sparSettings !== 'undefined') ? sparSettings.generateTermsNonce : '';

			$btn.on('click', function () {
				if ($btn.prop('disabled')) return;

				$btn.prop('disabled', true).text('⏳ ' + (strings.generatingTerms || 'Generating...'));
				$spinner.css('visibility', 'visible');
				$msg.text('').css('color', '');

				$.ajax({
					url: ajaxUrl,
					type: 'POST',
					data: {
						action: 'spar_generate_terms',
						nonce: nonce,
					},
					success: function (response) {
						if (response && response.success && response.data && response.data.html) {
							var html = response.data.html;
							// Update wp_editor (TinyMCE) if active, otherwise fall back to textarea
							if (typeof tinymce !== 'undefined') {
								var ed = tinymce.get('terms_content');
								if (ed && !ed.isHidden()) {
									ed.setContent(html);
									// Also sync the underlying textarea
									ed.save();
								} else {
									$('#terms_content').val(html);
								}
							} else {
								$('#terms_content').val(html);
							}
							$msg.text('✓ ' + (strings.saved || 'Generated!')).css('color', '#46b450');
						} else {
							$msg.text(strings.generateTermsError || 'Failed to generate terms. Please try again.').css('color', '#dc3232');
						}
					},
					error: function () {
						$msg.text(strings.generateTermsError || 'Failed to generate terms. Please try again.').css('color', '#dc3232');
					},
					complete: function () {
						$btn.prop('disabled', false).html('✨ ' + (strings.generateTerms || 'Generate'));
						$spinner.css('visibility', 'hidden');
						// Clear message after 4 seconds
						setTimeout(function () { $msg.text(''); }, 4000);
					},
				});
			});
		})();

		$('input[name="rewards_widget_dark_mode_toggle"]').on('change', handleRewardsWidgetDarkModeToggle);

		$('input[name="points_inactivity_expiry_enabled"]').on('change', handlePointsExpiryToggle);
		$('input[name="points_inactivity_dashboard_notice_enabled"]').on('change', handlePointsInactivityDashboardNoticeToggle);

		// Initialize visibility linking between Earn toggles and Email per-type sections
		initEmailPerTypeVisibility();

		// Keep refund/cancel deduction toggles in sync between Spend-based and Fixed-per-order sections
		(function initRefundDeductionSync(){
			var $spend = $('input[name="order_deduct_on_refund"]');
			var $fixed = $('input[name="order_fixed_deduct_on_refund"]');
			if (!$spend.length || !$fixed.length) return;
			var syncing = false;
			function mirror(from, to){
				if (syncing) return;
				syncing = true;
				to.prop('checked', from.is(':checked'));
				syncing = false;
			}
			// Initial alignment: mirror Fixed to Spend to avoid mismatched UI on load
			mirror($spend, $fixed);
			// Bidirectional sync on change
			$spend.on('change', function(){ mirror($spend, $fixed); });
			$fixed.on('change', function(){ mirror($fixed, $spend); });
		})();

		// Multi-currency: add/remove rates UI (Premium)
		(function initCurrencyRatesUI(){
			var $list = $('#spar-currency-rates-list');
			var $template = $('#spar-currency-rate-template');
			var $addBtn = $('.spar-add-currency-rate');
			if (!$list.length || !$template.length || !$addBtn.length) return;
			var idx = $list.find('.spar-currency-rate-row').length;
			$addBtn.on('click', function(){
				if ($(this).is(':disabled')) return;
				var html = $template.html().replace(/__INDEX__/g, String(idx));
				var $row = $(html);
				$list.append($row);
				// Initialize new row UI
				updateRowEarnedPerLabel($row);
				// Auto-select a sensible default currency that hasn't been used yet
				try {
					var $select = $row.find('select.spar-currency-code');
					if ($select.length) {
						// Collect already-selected currencies in existing rows (exclude empty values)
						var used = {};
						$list.find('select.spar-currency-code').not($select).each(function(){
							var val = ($(this).val() || '').toString().toUpperCase();
							if (val) { used[val] = true; }
						});
						// Popular currencies preference order
						var popular = ['USD','EUR','GBP','CAD','AUD','NZD','JPY','CHF','SEK','NOK','DKK'];
						var chosen = '';
						for (var i = 0; i < popular.length; i++) {
							var code = popular[i];
							if (!used[code] && $select.find('option[value="' + code + '"]').length) {
								chosen = code;
								break;
							}
						}
						// Fallback to first available option that's not used and not empty
						if (!chosen) {
							$select.find('option').each(function(){
								var v = ($(this).val() || '').toString().toUpperCase();
								if (v && !used[v]) { chosen = v; return false; }
							});
						}
						if (chosen) {
							$select.val(chosen).trigger('change');
						}
					}
				} catch(e) {
					// no-op: safely ignore if any structure isn't present
				}
				// Prefill the new row's amount with the current default amount
				var $defaultAmt = $('#spar-order-default-amount');
				if ($defaultAmt.length) {
					var defVal = $defaultAmt.val();
					$row.find('input[name^="order_currency_rates"][name$="[amount]"]').val(defVal);
				}
				idx++;
				$('#spar-settings-form').trigger('spar-save-settings');
			});
			$list.on('click', '.spar-remove-currency-rate', function(){
				$(this).closest('.spar-currency-rate-row').remove();
				$('#spar-settings-form').trigger('spar-save-settings');
			});

			// Update label when currency changes
			$list.on('change', 'select.spar-currency-code', function(){
				var $row = $(this).closest('.spar-currency-rate-row');
				updateRowEarnedPerLabel($row);
			});
		})();

		// Multi-currency: redemption rates UI (Premium)
		(function initRedeemCurrencyRatesUI(){
			var $list = $('#spar-redeem-currency-rates-list');
			var $template = $('#spar-redeem-currency-rate-template');
			var $addBtn = $('.spar-add-redeem-currency-rate');
			if (!$list.length || !$template.length || !$addBtn.length) return;
			var idx = $list.find('.spar-currency-rate-row').length;
			$addBtn.on('click', function(){
				if ($(this).is(':disabled')) return;
				var html = $template.html().replace(/__INDEX__/g, String(idx));
				var $row = $(html);
				$list.append($row);
				updateRowRedeemedPerLabel($row);
				// Prefill amount with default
				var $defaultAmt = $('#spar-redeem-default-amount');
				if ($defaultAmt.length) {
					var defVal = $defaultAmt.val();
					$row.find('input[name^="redeem_currency_rates"][name$="[amount]"]').val(defVal);
				}
				idx++;
				$('#spar-settings-form').trigger('spar-save-settings');
			});
			$list.on('click', '.spar-remove-redeem-currency-rate', function(){
				$(this).closest('.spar-currency-rate-row').remove();
				$('#spar-settings-form').trigger('spar-save-settings');
			});
			$list.on('change', 'select.spar-currency-code', function(){
				var $row = $(this).closest('.spar-currency-rate-row');
				updateRowRedeemedPerLabel($row);
			});
		})();

		// Points Discount on Checkout: Advanced Settings toggle
		$(document).on('click', '.spar-redeem-advanced-toggle', function(e){
			e.preventDefault();
			var $btn = $(this);
			var $panel = $btn.next('.spar-redeem-advanced-settings');
			if (!$panel.length) return;
			var isOpen = $btn.attr('aria-expanded') === 'true';
			$btn.attr('aria-expanded', isOpen ? 'false' : 'true');
			$btn.find('.spar-redeem-advanced-arrow').css('transform', isOpen ? '' : 'rotate(180deg)');
			$panel.stop(true, true).slideToggle();
		});

		function updateRowRedeemedPerLabel($row){
			if (!$row || !$row.length) return;
			var code = ($row.find('select.spar-currency-code').val() || '--').toString().toUpperCase();
			var $label = $row.find('.spar-redeem-per-label');
			if ($label.length){
				$label.text('Discount in ' + code + ':');
			}
		}

		// Order Fixed Tiers UI: add/remove tiers and per-tier currencies
		(function initOrderFixedTiersUI(){
			var $tiersWrap = $('#spar-order-fixed-tiers');
			var $tierTpl = $('#spar-order-tier-template');
			var $curTpl = $('#spar-tier-currency-template');
			var $addTierBtn = $('.spar-add-order-tier');
			if (!$tiersWrap.length || !$tierTpl.length || !$addTierBtn.length) return;

			function renumberTierHeaders(){
				$tiersWrap.find('.spar-order-tier').each(function(idx){
					var $tier = $(this);
					$tier.attr('data-tier-index', idx);
					// Update header number text if using template format
					$tier.find('strong').each(function(){
						var text = $(this).text();
						text = text.replace(/Tier\s+\d+/, 'Tier ' + (idx + 1));
						$(this).text(text);
					});
					// Show remove button only for tiers after the first
					var $removeBtn = $tier.find('.spar-remove-order-tier');
					if ($removeBtn.length){
						if (idx === 0) { $removeBtn.hide(); } else { $removeBtn.show(); }
					}
				});
			}

			$addTierBtn.on('click', function(){
				var newIdx = $tiersWrap.find('.spar-order-tier').length;
				var html = $tierTpl.html()
					.replace(/__TIER_INDEX__/g, String(newIdx))
					.replace(/__TIER_NUMBER__/g, String(newIdx + 1));
				var $tier = $(html);
				$tiersWrap.append($tier);
				renumberTierHeaders();
				$('#spar-settings-form').trigger('spar-save-settings');
			});

			// Remove a tier
			$tiersWrap.on('click', '.spar-remove-order-tier', function(){
				$(this).closest('.spar-order-tier').remove();
				renumberTierHeaders();
				$('#spar-settings-form').trigger('spar-save-settings');
			});

			// Add currency row inside a tier (PRO only)
			$tiersWrap.on('click', '.spar-add-tier-currency', function(){
				if ($(this).is(':disabled')) return;
				var tierIndex = $(this).data('tier-index');
				var $list = $('#spar-order-fixed-tier-' + tierIndex + '-currencies');
				if (!$list.length || !$curTpl.length) return;
				var curIdx = $list.find('.spar-tier-currency-row').length;
				var html = $curTpl.html()
					.replace(/__TIER_INDEX__/g, String(tierIndex))
					.replace(/__CUR_INDEX__/g, String(curIdx));
				var $row = $(html);
				$list.append($row);
				$('#spar-settings-form').trigger('spar-save-settings');
			});

			// Remove currency row
			$tiersWrap.on('click', '.spar-remove-tier-currency', function(){
				$(this).closest('.spar-tier-currency-row').remove();
				$('#spar-settings-form').trigger('spar-save-settings');
			});

			// Normalize headers/buttons on load
			renumberTierHeaders();
		})();

		// Helpers: update labels and sync amounts
		function updateRowEarnedPerLabel($row){
			if (!$row || !$row.length) return;
			var code = ($row.find('select.spar-currency-code').val() || '--').toString().toUpperCase();
			var $label = $row.find('.spar-earned-per-label');
			if ($label.length){
				$label.text('Earned per ' + code + ' spent:');
			}
		}

		function updateDefaultEarnedPerLabel(){
			var $defaultRow = $('.spar-default-currency-rate');
			if (!$defaultRow.length) return;
			var code = ($defaultRow.find('select').val() || '').toString().toUpperCase();
			var $label = $defaultRow.find('.spar-earned-per-label[data-role="default"]');
			if ($label.length){
				$label.text('Earned per ' + code + ' spent:');
			}
		}

		// Initialize once on load
		updateDefaultEarnedPerLabel();
		$('#spar-currency-rates-list .spar-currency-rate-row').each(function(){
			updateRowEarnedPerLabel($(this));
		});

		
		// Bind event handlers
		$('#referral_earning_type').on('change', handleReferralEarningTypeChange);
		$('select[name="referral_offer_type"]').on('change', handleReferralOfferTypeChange);
		$('input[name="rewards_widget_enabled"]').on('change', handleRewardsWidgetToggle);
		$('input[name="referral_offer_enabled"]').on('change', handleReferralOffersToggle);

		// Rewards Dashboard: update link preview when selecting a page
		$('#spar-rewards-page-select').on('change', function() {
			var pageId = $(this).val();
			var selectNonce = $(this).data('nonce') || (sparSettings && sparSettings.pageLinkNonce);
			var $link = $('#spar-rewards-page-link');
			// Since the dropdown only lists valid pages (containing the shortcode),
			// hide the Create button when a non-zero page is selected; show otherwise.
			var hasSelection = pageId && pageId !== '0';
			$('#spar-generate-rewards-page').toggle(!hasSelection);
			$link.html('<em>…</em>');
			$.ajax({
				url: sparSettings.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'spar_get_page_link',
					page_id: pageId,
					nonce: selectNonce
				}
			}).done(function(resp) {
				if (resp && resp.success && resp.data && resp.data.url) {
					$link.html('<a href="' + resp.data.url + '" target="_blank" rel="noopener noreferrer">' + 'View selected page' + '</a>');
				} else {
					$link.html('<em>No page selected.</em>');
				}
			}).fail(function() {
				$link.html('<em>No page selected.</em>');
			});
		});

		// Initialize button visibility on load
		(function initRewardsCreateButtonVisibility(){
			var $select = $('#spar-rewards-page-select');
			if ($select.length) {
				var pageId = $select.val();
				var hasSelection = pageId && pageId !== '0';
				$('#spar-generate-rewards-page').toggle(!hasSelection);
			}
		})();

		// Rewards Dashboard: create page with shortcode
		$('#spar-generate-rewards-page').on('click', function(e) {
			e.preventDefault();
			var $btn = $(this);
			$btn.prop('disabled', true).text('Creating…');
			var nonce = $btn.data('nonce') || (sparSettings && sparSettings.createPageNonce);
			$.ajax({
				url: sparSettings.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'spar_generate_rewards_page',
					nonce: nonce
				}
			}).done(function(resp) {
				if (resp && resp.success && resp.data && resp.data.id) {
					// Replace existing option for this ID if present, then select it
					var $select = $('#spar-rewards-page-select');
					$select.find('option[value="' + resp.data.id + '"]').remove();
					$select.append('<option value="' + resp.data.id + '">' + (resp.data.title || 'Rewards') + '</option>');
					$select.val(String(resp.data.id)).trigger('change');
				} else {
					var msg = (resp && resp.data) ? (resp.data.message || resp.data) : 'Failed to create page.';
					alert(msg);
				}
			})
			.fail(function(xhr) {
				var msg = 'Request failed.';
				try { if (xhr && xhr.responseJSON && xhr.responseJSON.data) { msg = xhr.responseJSON.data; } } catch(e) {}
				alert(msg);
			})
			.always(function(){
				$btn.prop('disabled', false).text('Create Page with Shortcode');
			});
		});

		// Rewards Dashboard: refresh list of pages that contain the shortcode
		$('#spar-refresh-rewards-pages').on('click', function(e){
			e.preventDefault();
			var $link = $(this);
			if ($link.prop('disabled')) return;
			var originalHtml = $link.html();
			$link.prop('disabled', true).text('Refreshing…');
			var nonce = $link.data('nonce') || (window.sparSettings && sparSettings.listPagesNonce);
			$.ajax({
				url: (window.sparSettings && sparSettings.ajaxUrl) || window.ajaxurl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'spar_list_rewards_pages',
					nonce: nonce
				}
			}).done(function(resp){
				var $select = $('#spar-rewards-page-select');
				if (!$select.length) return;
				var currentVal = $select.val();
				// Rebuild options
				var placeholder = (window.sparSettings && sparSettings.strings && sparSettings.strings.selectAPage) ? sparSettings.strings.selectAPage : '— Select a page —';
				var optionsHtml = '<option value="0">' + $('<div>').text(placeholder).html() + '</option>';
				if (resp && resp.success && resp.data && $.isArray(resp.data.pages)) {
					resp.data.pages.forEach(function(p){
						var id = String(p.id);
						var title = p.title || ('Page ' + id);
						var selected = (id === String(currentVal)) ? ' selected' : '';
						optionsHtml += '<option value="' + id + '"' + selected + '>' + $('<div>').text(title).html() + '</option>';
					});
				}
				$select.html(optionsHtml);
				// If current selection no longer exists, reset to 0
				if (!$select.find('option[value="' + currentVal + '"]').length) {
					$select.val('0');
				}
				$select.trigger('change');
			}).fail(function(){
				alert('Failed to refresh pages.');
			}).always(function(){
				$link.prop('disabled', false).html(originalHtml);
			});
		});

		// Template Coupon: show edit link dynamically when selection changes
		(function initTemplateCouponEditLink(){
			var $select = $('#referral_template_coupon_id');
			var $target = $('#spar-template-coupon-edit-link');
			if (!$select.length || !$target.length) { return; }
			function updateLink(){
				var id = $select.val();
				if (!id || id === '0') {
					$target.html('<em>' + 'No template selected.' + '</em>');
					return;
				}
				$target.html('<em>Loading…</em>');
				$.ajax({
					url: (window.sparSettings && sparSettings.ajaxUrl) || window.ajaxurl,
					type: 'POST',
					dataType: 'json',
					data: {
						action: 'spar_get_coupon_edit_link',
						coupon_id: id,
						nonce: (window.sparSettings && sparSettings.couponEditLinkNonce) || ''
					}
				}).done(function(resp){
					if (resp && resp.success && resp.data && resp.data.url) {
						var safeText = resp.data.text || 'Edit coupon';
						$target.html('<a href="' + resp.data.url + '" target="_blank" rel="noopener noreferrer">' + $('<div>').text(safeText).html() + '</a>');
					} else if (resp && resp.data && resp.data.text) {
						$target.html('<em>' + $('<div>').text(resp.data.text).html() + '</em>');
					} else {
						$target.html('<em>Error.</em>');
					}
				}).fail(function(){
					$target.html('<em>Error.</em>');
				});
			}
			$select.on('change', updateLink);
			updateLink(); // initial
		})();
	}

	// Link Earn tab enable toggles to Email Notifications per-type sections
	function initEmailPerTypeVisibility() {
		var map = {
			'signup_enabled': 'signup',
			'order_enabled': 'order',
			'first_order_enabled': 'first_order',
			'nth_order_enabled': 'nth_order',
			'referral_enabled': 'referral',
			'review_enabled': 'review',
			'birthday_enabled': 'birthday'
		};

		function updateVisibility(key, typeKey) {
			var $toggle = $('input[name="' + key + '"]');
			var $section = $('#spar-email-earned-' + typeKey);
			if (!$section.length) { return; }
			var isOn = $toggle.length ? $toggle.is(':checked') : false;
			$section.toggle(!!isOn);
		}

		// Expose a refresh function globally so other code (like tab switch) can invoke
		window.sparRefreshEmailPerTypeVisibility = function() {
			$.each(map, function(key, typeKey) {
				updateVisibility(key, typeKey);
			});
		};

		// Initial state
		window.sparRefreshEmailPerTypeVisibility();

		// Bind change listeners on earn toggles
		$.each(map, function(key, typeKey) {
			$(document).on('change', 'input[name="' + key + '"]', function(){
				updateVisibility(key, typeKey);
			});
		});
	}
	
	function syncOfferCheckboxes() {
		const earnOfferCheckbox = document.querySelector('input[name="referral_offer_enabled"]');
		const giftOfferCheckbox = document.querySelector('input[name="referral_offer_enabled_sync"]');
		const offersOfferCheckbox = document.querySelector('input[name="referral_offer_enabled_offers"]');
		
		function syncCheckboxes(sourceCheckbox, targetCheckboxes) {
			targetCheckboxes.forEach(function(target) {
				if (target && target !== sourceCheckbox) {
					target.checked = sourceCheckbox.checked;
				}
			});
			
			// Toggle visibility of settings sections
			const giftSettings = document.getElementById('spar-gift-coupon-settings');
			const offersSettings = document.getElementById('spar-offers-coupon-settings');
			
			if (giftSettings) {
				giftSettings.classList.toggle('spar-hidden', !sourceCheckbox.checked);
			}
			if (offersSettings) {
				offersSettings.classList.toggle('spar-hidden', !sourceCheckbox.checked);
			}
		}
		
		if (earnOfferCheckbox) {
			earnOfferCheckbox.addEventListener('change', function() {
				syncCheckboxes(this, [giftOfferCheckbox, offersOfferCheckbox]);
			});
		}
		
		if (giftOfferCheckbox) {
			giftOfferCheckbox.addEventListener('change', function() {
				syncCheckboxes(this, [earnOfferCheckbox, offersOfferCheckbox]);
			});
		}
		
		if (offersOfferCheckbox) {
			offersOfferCheckbox.addEventListener('change', function() {
				syncCheckboxes(this, [earnOfferCheckbox, giftOfferCheckbox]);
			});
			
			// Initial toggle of settings visibility
			const offersSettings = document.getElementById('spar-offers-coupon-settings');
			if (offersSettings) {
				offersSettings.classList.toggle('spar-hidden', !offersOfferCheckbox.checked);
			}
		}
		
		// Toggle discount value field based on offer type
		const offersOfferTypeSelect = document.getElementById('referral_offer_type_offers');
		const offersDiscountField = document.getElementById('discount-value-field-offers');
		
		if (offersOfferTypeSelect && offersDiscountField) {
			offersOfferTypeSelect.addEventListener('change', function() {
				offersDiscountField.style.display = this.value === 'discount' ? 'block' : 'none';
			});
		}
	}
		function initWidgetToggles() {
		// Visibility is handled by handleRewardsWidgetToggle; nothing to do here.
	}
	
	function initColorPickers() {
		// Sync color picker with text input for rewards widget
		const colorPicker = document.querySelector('input[name="rewards_widget_color"]');
		const colorText = document.querySelector('input[name="rewards_widget_color_text"]');
		
		if (colorPicker && colorText) {
			colorPicker.addEventListener('change', function() {
				colorText.value = this.value;
			});
			
			colorText.addEventListener('change', function() {
				if (/^#[0-9A-F]{6}$/i.test(this.value)) {
					colorPicker.value = this.value;
				}
			});
		}
	}

	function initRewardsWidgetIconControls() {
		var select = document.querySelector('select[name="rewards_widget_icon"]');
		if (!select) {
			return;
		}

		var urlRow = document.querySelector('.spar-rewards-widget-icon-url-row');
		var textRow = document.querySelector('.spar-rewards-widget-icon-text-row');

		function toggleRows() {
			var value = select.value || '';
			if (urlRow) {
				urlRow.classList.toggle('spar-hidden', value !== 'custom_url');
				urlRow.style.display = (value === 'custom_url') ? 'block' : 'none';
			}
			if (textRow) {
				textRow.classList.toggle('spar-hidden', value !== 'custom_text');
				textRow.style.display = (value === 'custom_text') ? 'block' : 'none';
			}
		}

		select.addEventListener('change', toggleRows);
		toggleRows();
	}

	function initRewardsWidgetIconUploader() {
		var uploadButtons = document.querySelectorAll('.spar-rewards-widget-icon-upload');
		if (!uploadButtons.length) {
			return;
		}

		uploadButtons.forEach(function(button) {
			button.addEventListener('click', function(e) {
				e.preventDefault();
				if (typeof wp === 'undefined' || !wp.media) {
					return;
				}

				var targetName = button.getAttribute('data-target');
				if (!targetName) {
					return;
				}
				var targetInput = document.querySelector('input[name="' + targetName + '"]');
				if (!targetInput) {
					return;
				}

				var frame = wp.media({
					title: 'Select or Upload Icon',
					button: { text: 'Use this image' },
					multiple: false
				});

				frame.on('select', function() {
					var attachment = frame.state().get('selection').first();
					if (!attachment) {
						return;
					}
					var url = attachment.get('url');
					if (url) {
						targetInput.value = url;
						targetInput.dispatchEvent(new Event('input', { bubbles: true }));
						targetInput.dispatchEvent(new Event('change'));
					}
				});

				frame.open();
			});
		});
	}

	function initMediaUploadButtons() {
		document.addEventListener('click', function(e) {
			var button = e.target.closest('.spar-media-upload-button');
			if (!button) {
				return;
			}
			e.preventDefault();
			if (typeof wp === 'undefined' || !wp.media) {
				return;
			}

			var field = button.closest('.spar-media-upload-field');
			var targetInput = field ? field.querySelector('input[type="url"]') : null;
			if (!targetInput) {
				return;
			}

			var frame = wp.media({
				title: 'Select or Upload Icon',
				button: { text: 'Use this image' },
				multiple: false
			});

			frame.on('select', function() {
				var attachment = frame.state().get('selection').first();
				if (!attachment) {
					return;
				}
				var url = attachment.get('url');
				if (url) {
					targetInput.value = url;
					targetInput.dispatchEvent(new Event('input', { bubbles: true }));
					targetInput.dispatchEvent(new Event('change', { bubbles: true }));
				}
			});

			frame.open();
		});
	}

	// Points Icon picker (Points Label tab): toggle the custom URL row + scope
	// select based on the chosen radio, and keep the custom preview in sync.
	function initPointsIconPicker() {
		var field = document.querySelector('.spar-points-icon-field');
		if (!field) {
			return;
		}
		var customRow  = field.querySelector('.spar-points-icon-custom-row');
		var scopeRow   = field.querySelector('.spar-points-icon-scope-row');
		var urlInput   = field.querySelector('.spar-points-icon-url-input');
		var preview    = field.querySelector('.spar-points-icon-custom-preview');
		var customText = field.querySelector('.spar-points-icon-custom-text');

		function selectedValue() {
			var checked = field.querySelector('input[name="points_icon"]:checked');
			return checked ? checked.value : '';
		}
		function refresh() {
			var val = selectedValue();
			if (customRow) { customRow.style.display = (val === 'custom') ? '' : 'none'; }
			if (scopeRow)  { scopeRow.style.display  = (val !== '') ? '' : 'none'; }
		}
		function refreshPreview() {
			var url = urlInput ? String(urlInput.value || '').trim() : '';
			if (preview) {
				if (url) { preview.src = url; preview.style.display = ''; }
				else { preview.style.display = 'none'; }
			}
			if (customText) { customText.style.display = url ? 'none' : ''; }
		}

		field.addEventListener('change', function(e) {
			if (e.target && e.target.name === 'points_icon') { refresh(); }
		});
		if (urlInput) {
			urlInput.addEventListener('input', function() {
				var customRadio = field.querySelector('input[name="points_icon"][value="custom"]');
				if (String(urlInput.value || '').trim() && customRadio && !customRadio.checked) {
					customRadio.checked = true;
				}
				refresh();
				refreshPreview();
			});
			urlInput.addEventListener('change', refreshPreview);
		}

		refresh();
		refreshPreview();
	}

	function initAutoSave() {
		var $form = $('#spar-settings-form');
		if (!$form.length) return;

		var saveTimeout;
		var pendingXhr = null;
		var manualSaveInProgress = false;
		var nativeSubmitInProgress = false;
		// Add status indicator floating at bottom center
		var $status = $('<div class="spar-save-popup" style="display:none; position: fixed; bottom: 30px; left: 50%; transform: translateX(-50%); background: #32373c; color: #fff; padding: 12px 24px; border-radius: 4px; z-index: 99999; box-shadow: 0 4px 10px rgba(0,0,0,0.2); font-weight: 500; font-size: 14px; transition: all 0.3s ease;"></div>');
		$('body').append($status);

		// Save the manual button through AJAX too. The serialized form is sent as
		// one POST value, then parsed server-side without parse_str(), so hosts
		// with low max_input_vars do not truncate settings before they are saved.
		// If the AJAX transport itself is blocked, manual saves fall back to a
		// normal form submit, which is still guarded by server-side validation.
		$form.on('submit', function(event) {
			if (nativeSubmitInProgress) {
				return;
			}

			event.preventDefault();
			clearTimeout(saveTimeout);
			if (pendingXhr && typeof pendingXhr.abort === 'function') {
				pendingXhr.abort();
				pendingXhr = null;
			}

			setTimeout(function() {
				triggerSave({ force: true, manual: true });
			}, 0);
		});

		// Function to trigger save
		function triggerSave(options) {
			options = options || {};

			if (manualSaveInProgress && !options.force) {
				return;
			}

			if (typeof tinymce !== 'undefined') {
				tinymce.triggerSave();
			}

			function submitFormNormally() {
				if (!options.manual || !$form[0]) {
					return;
				}

				nativeSubmitInProgress = true;
				manualSaveInProgress = false;
				$form.find('.spar-save-btn').prop('disabled', false);
				$status.text('Saving and reloading...').fadeIn(200);
				$form[0].submit();
			}

			function isNonceFailure(jqXHR) {
				var responseText = jqXHR && typeof jqXHR.responseText === 'string' ? jqXHR.responseText.replace(/^\s+|\s+$/g, '') : '';

				return jqXHR && jqXHR.status === 403 && responseText === '-1';
			}

			// Abort any previous pending AJAX save to prevent race conditions
			if (pendingXhr && typeof pendingXhr.abort === 'function') {
				pendingXhr.abort();
				pendingXhr = null;
			}

			if (options.manual) {
				manualSaveInProgress = true;
				$form.find('.spar-save-btn').prop('disabled', true);
			}

			$status.text('Saving...').fadeIn(200);
			
			var formData = $form.serialize();
			var nonce = $form.find('input[name="_wpnonce"]').val();
			var ajaxUrl = (window.sparSettings && window.sparSettings.ajaxUrl) || window.ajaxurl || '';

			if (!ajaxUrl) {
				if (options.manual) {
					submitFormNormally();
				} else {
					$status.stop(true).fadeOut(100);
				}
				return;
			}

			function showSaveError(message) {
				$status.text(message || 'Error saving').css('background', '#d63638').delay(3000).fadeOut(300, function() {
					$(this).css('background', '#32373c');
				});
			}
			
			pendingXhr = $.ajax({
				url: ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'spar_save_settings_ajax',
					nonce: nonce,
					data: formData
				},
				success: function(response) {
					if (response.success) {
						$status.text('Settings Saved').delay(2000).fadeOut(300);
					} else {
						var responseMessage = response && response.data ? response.data : '';
						showSaveError(typeof responseMessage === 'string' ? responseMessage : 'Error saving');
					}
				},
				error: function(jqXHR, textStatus) {
					// Don't show error for intentionally aborted requests
					if (textStatus === 'abort') {
						$status.stop(true).fadeOut(100);
						return;
					}
					var errorMessage = '';
					if (jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.data) {
						errorMessage = jqXHR.responseJSON.data;
					}
					if (options.manual && !isNonceFailure(jqXHR)) {
						submitFormNormally();
						return;
					}
					showSaveError(typeof errorMessage === 'string' ? errorMessage : 'Error saving');
				},
				complete: function() {
					pendingXhr = null;
					if (options.manual) {
						manualSaveInProgress = false;
						$form.find('.spar-save-btn').prop('disabled', false);
					}
				}
			});
		}

		// Debounce function
		function debounce(func, wait) {
			return function() {
				var context = this, args = arguments;
				clearTimeout(saveTimeout);
				saveTimeout = setTimeout(function() {
					func.apply(context, args);
				}, wait);
			};
		}

		// Handle TinyMCE editors
		if (typeof tinymce !== 'undefined') {
			// Listen for new editors being added
			tinymce.on('AddEditor', function(e) {
				e.editor.on('Change KeyUp', debounce(function() {
					e.editor.save(); // Sync to textarea
					triggerSave();
				}, 1000));
			});
			
			// Attach to existing editors
			// Use a timeout to ensure editors are fully initialized
			setTimeout(function() {
				for (var i = 0; i < tinymce.editors.length; i++) {
					tinymce.editors[i].on('Change KeyUp', debounce(function() {
						this.save(); // Sync to textarea
						triggerSave();
					}, 1000));
				}
			}, 1000);
		}

		// Listen for changes on all inputs.
		// Debounce checkbox/select/radio changes briefly (50ms) so that
		// duplicate-toggle sync handlers update canonical inputs before
		// the form is serialized.
		$form.on('change', 'input, select, textarea', function(e) {
			// If it's a text-like input, we rely on the 'input' event handler below (debounced).
			// 'change' is used for checkboxes, selects, radio buttons.
			if ($(this).is('input[type="text"], input[type="number"], textarea, input[type="email"], input[type="url"]')) {
				return;
			}
			clearTimeout(saveTimeout);
			saveTimeout = setTimeout(function() {
				triggerSave();
			}, 50);
		});

		// Listen for input on text fields (debounced)
		$form.on('input', 'input[type="text"], input[type="number"], textarea, input[type="email"], input[type="url"]', debounce(function() {
			triggerSave();
		}, 1000));

		// Listen for custom save event
		$form.on('spar-save-settings', function() {
			triggerSave();
		});
	}

	function initMirrorSync() {
		// Use delegated event to handle dynamic elements and ensure we catch everything
		// Bind to the form directly so this handler runs BEFORE the auto-save handler (which is also bound to the form)
		$('form#spar-settings-form').on('change input', 'input[name], select[name], textarea[name], input[data-name], select[data-name], textarea[data-name]', function(e, flag) {
			// If this change was triggered by sync, stop recursion
			if (flag === 'spar-sync') return;

			var $this = $(this);
			var name = this.name || $this.attr('data-name');
			if (!name) return;
			// Skip array inputs for now
			if (name.indexOf('[]') !== -1) return;

			// Find all other inputs with the same name (or data-name) in the form
			var $mirrors = $('form#spar-settings-form').find('[name="' + name + '"], [data-name="' + name + '"]').not($this);
			
			if ($mirrors.length) {
				var val = $this.val();
				var isChecked = $this.is(':checked');
				var type = this.type;

				$mirrors.each(function() {
					var $mirror = $(this);
					if (type === 'checkbox' || type === 'radio') {
						// For radio buttons, only sync if values match (otherwise we check all options in the group!)
						if (type === 'radio' && $mirror.val() !== val) {
							return;
						}

						if ($mirror.prop('checked') !== isChecked) {
							$mirror.prop('checked', isChecked);
							// Trigger change manually so other scripts react, but pass a flag to ignore in sync
							$mirror.trigger('change', ['spar-sync']);
						}
					} else {
						if ($mirror.val() !== val) {
							$mirror.val(val);
							$mirror.trigger('change', ['spar-sync']);
						}
					}
				});
			}
		});
	}
	
})(jQuery);
