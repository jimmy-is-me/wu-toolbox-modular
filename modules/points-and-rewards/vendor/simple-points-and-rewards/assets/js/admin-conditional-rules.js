/* global jQuery, sparConditionalRules */
(function($) {
	'use strict';
	var didInit = false;

	function initHelpTips($scope) {
		$scope = ($scope && $scope.length) ? $scope : $(document.body);
		var $tips = $scope.find('.woocommerce-help-tip');
		if (!$tips.length) {
			return;
		}

		// Preferred: WooCommerce tipTip popovers.
		if (typeof $.fn.tipTip === 'function') {
			$tips.each(function() {
				var $tip = $(this);
				if ($tip.data('sparTipTipInit')) {
					return;
				}
				$tip.tipTip({
					attribute: 'data-tip',
					fadeIn: 50,
					fadeOut: 50,
					delay: 200,
					keepAlive: true
				});
				$tip.data('sparTipTipInit', true);
			});
			return;
		}

		// Fallback: let the browser show a native tooltip.
		$tips.each(function() {
			var $tip = $(this);
			if ($tip.attr('title')) {
				return;
			}
			var tip = $tip.attr('data-tip');
			if (tip) {
				$tip.attr('title', tip);
			}
		});
	}

	function generateRuleId() {
		if (window.crypto && typeof window.crypto.randomUUID === 'function') {
			return window.crypto.randomUUID();
		}
		// Fallback (not RFC4122, but unique enough for admin-only IDs).
		return 'spar-cr-' + String(Date.now()) + '-' + String(Math.random()).slice(2);
	}

	function ensureRuleId($item) {
		var $id = $item.find('input[name$="[id]"]').first();
		if (!$id.length) {
			return;
		}
		var current = $.trim($id.val() || '');
		if (!current) {
			$id.val(generateRuleId());
		}
	}

	function setupSelectWoo($el, action, nonceKey) {
		if (!$el || !$el.length) {
			return;
		}
		if (typeof $.fn.selectWoo !== 'function') {
			return;
		}
		if ($el.hasClass('select2-hidden-accessible')) {
			return;
		}

		var ajaxUrl = (sparConditionalRules && sparConditionalRules.ajaxUrl) ? sparConditionalRules.ajaxUrl : '';
		var nonce = (sparConditionalRules && sparConditionalRules[nonceKey]) ? sparConditionalRules[nonceKey] : '';

		$el.selectWoo({
			allowClear: true,
			width: 'resolve',
			minimumInputLength: 1,
			ajax: {
				url: ajaxUrl,
				dataType: 'json',
				delay: 250,
				data: function(params) {
					return {
						action: action,
						q: params.term || '',
						term: params.term || '',
						nonce: nonce
					};
				},
				processResults: function(data) {
					var results = [];
					if (data && data.success) {
						if ($.isArray(data.data)) {
							results = data.data;
						} else if (data.data && data.data.results && $.isArray(data.data.results)) {
							results = data.data.results;
						}
					}
					return { results: results };
				},
				cache: true
			}
		});
	}

	function getConditionTypes($item) {
		var types = [];
		$item.find('.spar-cr-condition-group').each(function() {
			var type = $(this).find('.spar-cr-condition-type').val() || 'product';
			if (type) {
				types.push(type);
			}
		});
		return types;
	}

	function updateConditionGroupVisibility($group) {
		var type = $group.find('.spar-cr-condition-type').val() || 'product';
		$group.find('.spar-cr-condition').hide();
		$group.find('.spar-cr-condition[data-condition="' + type + '"]').show();
	}

	function updateConditionVisibility($item) {
		$item.find('.spar-cr-condition-group').each(function() {
			updateConditionGroupVisibility($(this));
		});
		var types = getConditionTypes($item);
		var isProductScoped = ($.inArray('product', types) !== -1 || $.inArray('product_category', types) !== -1 || $.inArray('product_item', types) !== -1 || $.inArray('product_item_category', types) !== -1);
		$item.find('.spar-cr-product-actions-notice').toggle(isProductScoped);
		updateActionsGrouping($item, types);
		updateWaysVisibility($item, types);
		updateHideDisableVisibility($item);
	}

	function updateActionsGrouping($item, type) {
		var types = $.isArray(type) ? type : [type || ($item.find('.spar-cr-condition-type').val() || 'product')];
		var isProductScoped = ($.inArray('product', types) !== -1 || $.inArray('product_category', types) !== -1 || $.inArray('product_item', types) !== -1 || $.inArray('product_item_category', types) !== -1);
		var $primaryTitle = $item.find('.spar-cr-actions-primary-title').first();
		if ($primaryTitle.length) {
			var productLabel = $primaryTitle.data('product-label') || 'Product Actions';
			var orderLabel = $primaryTitle.data('order-label') || 'Order Actions';
			$primaryTitle.text(isProductScoped ? productLabel : orderLabel);
		}

		var $deltaWrap = $item.find('.spar-cr-fixed-delta-wrap').first();
		if (!$deltaWrap.length) {
			return;
		}

		var $primaryBody = $item.find('.spar-cr-actions-primary-body').first();
		var $orderOnlyGroup = $item.find('.spar-cr-actions-orderonly').first();
		var $orderOnlyBody = $item.find('.spar-cr-actions-orderonly-body').first();

		if (isProductScoped) {
			if ($orderOnlyGroup.length) {
				$orderOnlyGroup.show();
			}
			if ($orderOnlyBody.length) {
				$orderOnlyBody.append($deltaWrap);
			}
		} else {
			if ($primaryBody.length) {
				$primaryBody.append($deltaWrap);
			}
			if ($orderOnlyGroup.length) {
				$orderOnlyGroup.hide();
			}
		}
	}

	function updateHideDisableVisibility($item) {
		var $toggle = $item.find('.spar-cr-hide-disable-toggle').first();
		var $details = $item.find('.spar-cr-actions-details').first();
		if (!$toggle.length || !$details.length) {
			return;
		}
		$details.toggle(!$toggle.is(':checked'));
	}

	function updateWaysVisibility($item, type) {
		var types = $.isArray(type) ? type : [type || ($item.find('.spar-cr-condition-type').val() || 'product')];
		var hasOrderOnly = false;
		$.each(types, function(_, t) {
			if (t !== 'customer' && t !== 'user_role' && t !== 'customer_total_points' && t !== 'customer_available_points') {
				hasOrderOnly = true;
				return false;
			}
		});
		var showAllWays = !hasOrderOnly;
		var $wraps = $item.find('.spar-cr-way-wrap');
		if (!$wraps.length) {
			return;
		}

		$item.attr('data-spar-cr-scope', showAllWays ? 'all' : 'order');

		if (showAllWays) {
			$wraps.show();
			return;
		}

		// Product/Product Category/Cart Total rules only make sense for order-context earning methods.
		$wraps.each(function() {
			var $wrap = $(this);
			var scope = $wrap.attr('data-spar-cr-way-scope') || ($wrap.hasClass('spar-cr-way-nonorder') ? 'nonorder' : 'order');
			var isAllowed = (scope === 'order');
			if (!isAllowed) {
				$wrap.find('input.spar-cr-way').prop('checked', false);
				$wrap.hide();
				return;
			}
			$wrap.show();
		});
	}

	function updateRuleTitle($item) {
		var label = $.trim($item.find('input[name$="[label]"]').val() || '');
		var $firstGroup = $item.find('.spar-cr-condition-group').first();
		var typeText = $firstGroup.find('.spar-cr-condition-type option:selected').text();
		var typeKey = $firstGroup.find('.spar-cr-condition-type').val() || 'product';
		if ($item.find('.spar-cr-condition-group').length > 1 && typeText) {
			typeText = typeText + ' +';
		}
		var mult = $item.find('input[name$="[multiplier]"]').val();
		var title = '';
		if (label) {
			title = label;
		}
		if (typeText) {
			title = title ? (title + ' — ' + typeText) : typeText;
		}
		if (mult !== '' && typeof mult !== 'undefined') {
			title = title ? (title + ' — ' + mult + 'x') : (mult + 'x');
		}
		$item.find('.spar-cr-title').text(title || (sparConditionalRules && sparConditionalRules.i18n && sparConditionalRules.i18n.ruleTitle ? sparConditionalRules.i18n.ruleTitle : 'Conditional Rule'));

		var emojiMap = {
			product: '📦',
			product_category: '🗂️',
			cart_total: '💰',
			cart_coupon: '🏷️',
			product_item: '🏷',
			product_item_category: '🗃',
			customer: '👤',
			user_role: '🛡️',
			customer_total_points: '⭐',
			customer_available_points: '💳'
		};
		$item.find('.spar-cr-type-emoji').text(emojiMap[typeKey] || '⚙️');
	}

	function getNextConditionIndex($item) {
		var maxIndex = -1;
		$item.find('.spar-cr-condition-group').each(function() {
			var idx = parseInt($(this).attr('data-condition-index'), 10);
			if (!isNaN(idx) && idx > maxIndex) {
				maxIndex = idx;
			}
		});
		return maxIndex + 1;
	}

	function updateConditionGroupIndex($group, newIndex) {
		$group.attr('data-condition-index', String(newIndex));
		$group.find('[name]').each(function() {
			var $field = $(this);
			var name = $field.attr('name');
			if (!name) {
				return;
			}
			name = name.replace(/\[conditions\]\[\d+\]/, '[conditions][' + String(newIndex) + ']');
			$field.attr('name', name);
		});
	}

	function resetConditionGroupInputs($group) {
		$group.find('input[type="text"]').val('');
		// Coupon type checkboxes default to all enabled.
		$group.find('input.spar-cr-coupon-type').prop('checked', true);
		$group.find('input[type="number"]').each(function() {
			var $input = $(this);
			$input.val($input.attr('min') === '0' ? '0' : '');
		});
		$group.find('select').each(function() {
			var $select = $(this);
			if ($select.is('[multiple]')) {
				$select.empty();
				return;
			}
			if ($select.hasClass('spar-cr-condition-type')) {
				return;
			}
			$select.prop('selectedIndex', 0);
		});
		$group.find('.select2-hidden-accessible').each(function() {
			var $select = $(this);
			$select.removeClass('select2-hidden-accessible').removeAttr('aria-hidden');
			$select.next('.select2').remove();
		});
	}

	function initRuleItem($item) {
		if (!$item || !$item.length) {
			return;
		}

		ensureRuleId($item);

		// Prevent global accordion header click handlers from hijacking action clicks.
		$item.on('mousedown', '.spar-cr-remove, .spar-cr-toggle', function(e) {
			e.stopPropagation();
		});
		$item.on('mousedown click', '.spar-reward-actions', function(e) {
			e.stopPropagation();
		});

		// Header click toggles settings (like Rewards/Vouchers UI), except when clicking actions.
		$item.on('click', '.spar-accordion-header', function(e) {
			// If a button/action inside the header was clicked, don't toggle.
			var target = e.target && e.target.nodeType === 3 ? e.target.parentNode : e.target;
			if ($(target).closest('button, a, .spar-reward-actions, .spar-cr-remove, .spar-cr-toggle').length) {
				return;
			}
			var $body = $item.find('.spar-accordion-body').first();
			var $btn = $item.find('.spar-cr-toggle').first();
			if (!$body.length || !$btn.length) {
				return;
			}
			$btn.trigger('click');
		});

		$item.on('click', '.spar-cr-toggle', function(e) {
			e.preventDefault();
			e.stopPropagation();
			var $btn = $(this);
			var $body = $item.find('.spar-accordion-body').first();
			if (!$body.length) {
				return;
			}
			var isOpen = $body.is(':visible');
			var editLabel = (sparConditionalRules && sparConditionalRules.i18n && sparConditionalRules.i18n.edit) ? sparConditionalRules.i18n.edit : 'Edit';
			var closeLabel = (sparConditionalRules && sparConditionalRules.i18n && sparConditionalRules.i18n.close) ? sparConditionalRules.i18n.close : 'Close';
			if (isOpen) {
				$body.slideUp(150, function() {
					$body.addClass('spar-hidden').css('display', 'none');
				});
				$btn.attr('aria-expanded', 'false').text(editLabel);
			} else {
				$body.removeClass('spar-hidden').css('display', 'block');
				$btn.attr('aria-expanded', 'true').text(closeLabel);
			}
		});

		updateConditionVisibility($item);
		updateRuleTitle($item);
		initHelpTips($item);

		// Ensure Edit/Close text matches initial visibility.
		(function syncToggleState(){
			var $btn = $item.find('.spar-cr-toggle').first();
			var $body = $item.find('.spar-accordion-body').first();
			if (!$btn.length || !$body.length) {
				return;
			}
			var editLabel = (sparConditionalRules && sparConditionalRules.i18n && sparConditionalRules.i18n.edit) ? sparConditionalRules.i18n.edit : 'Edit';
			var closeLabel = (sparConditionalRules && sparConditionalRules.i18n && sparConditionalRules.i18n.close) ? sparConditionalRules.i18n.close : 'Close';
			if ($body.is(':visible')) {
				$btn.attr('aria-expanded', 'true').text(closeLabel);
			} else {
				$btn.attr('aria-expanded', 'false').text(editLabel);
			}
		})();

		$item.on('change', '.spar-cr-condition-type', function() {
			updateConditionVisibility($item);
			updateRuleTitle($item);
			initSelects($item);
			initHelpTips($item);
		});

		$item.on('change', '.spar-cr-hide-disable-toggle', function() {
			updateHideDisableVisibility($item);
			if (typeof jQuery !== 'undefined') {
				jQuery('#spar-settings-form').trigger('spar-save-settings');
			}
		});

		$item.on('click', '.spar-cr-add-and', function(e) {
			e.preventDefault();
			var $groups = $item.find('.spar-cr-condition-group');
			if (!$groups.length) {
				return;
			}
			var $source = $groups.first();
			var newIndex = getNextConditionIndex($item);
			var $clone = $source.clone();
			updateConditionGroupIndex($clone, newIndex);
			if (!$clone.find('.spar-cr-and-label').length) {
				$clone.prepend('<p class="spar-cr-and-label"><strong>AND</strong></p>');
			}
			var $actions = $clone.find('.spar-cr-and-actions').first();
			if ($actions.length) {
				if (!$actions.find('.spar-cr-remove-and').length) {
					$actions.append('<button type="button" class="button-link spar-cr-remove-and">Remove</button>');
				}
			}
			resetConditionGroupInputs($clone);
			var $footer = $item.find('.spar-cr-and-footer');
			if ($footer.length) {
				$clone.insertBefore($footer.first());
			} else {
				$item.find('.spar-cr-conditions').append($clone);
			}
			updateConditionGroupVisibility($clone);
			initSelects($clone);
			initHelpTips($clone);
			updateConditionVisibility($item);
			updateRuleTitle($item);
			if (typeof jQuery !== 'undefined') {
				jQuery('#spar-settings-form').trigger('spar-save-settings');
			}
		});

		$item.on('click', '.spar-cr-remove-and', function(e) {
			e.preventDefault();
			var $group = $(this).closest('.spar-cr-condition-group');
			if (!$group.length) {
				return;
			}
			$group.remove();
			updateConditionVisibility($item);
			updateRuleTitle($item);
			if (typeof jQuery !== 'undefined') {
				jQuery('#spar-settings-form').trigger('spar-save-settings');
			}
		});

		$item.on('input change', 'input[name$="[label]"], input[name$="[multiplier]"]', function() {
			updateRuleTitle($item);
		});

		$item.on('click', '.spar-cr-remove', function(e) {
			e.preventDefault();
			e.stopPropagation();
			var confirmMsg = (sparConditionalRules && sparConditionalRules.i18n && sparConditionalRules.i18n.confirmDelete) ? sparConditionalRules.i18n.confirmDelete : 'Are you sure you want to delete this rule?';
			if (!window.confirm(confirmMsg)) {
				return;
			}
			var ruleId = $.trim($item.find('input[name$="[id]"]').first().val() || '');
			if (ruleId) {
				// Post deleted rule IDs so the server can reliably remove them.
				var $form = jQuery('#spar-settings-form');
				if ($form.length) {
					if (!$form.find('input.spar-cr-deleted-id[value="' + ruleId.replace(/"/g, '\\"') + '"]').length) {
						$form.prepend(
							jQuery('<input>', {
								type: 'hidden',
								class: 'spar-cr-deleted-id',
								name: 'earn_conditional_rules_deleted_ids[]',
								value: ruleId
							})
						);
					}
				}
			}
			$item.remove();
			if (typeof jQuery !== 'undefined') {
				jQuery('#spar-settings-form').trigger('spar-save-settings');
			}
		});

		initSelects($item);
	}

	function initSelects($scope) {
		setupSelectWoo($scope.find('.spar-cr-products'), 'spar_search_products', 'searchProductsNonce');
		setupSelectWoo($scope.find('.spar-cr-categories'), 'spar_cr_search_categories', 'searchCategoriesNonce');
		setupSelectWoo($scope.find('.spar-cr-customers'), 'spar_cr_search_customers', 'searchCustomersNonce');
	}

	function initRepeater() {
		var $root = $('#spar-conditional-rules');
		if (!$root.length) {
			return;
		}
		if (didInit) {
			return;
		}
		didInit = true;

		var $list = $('#spar-conditional-rules-list');
		var $tpl = $('#spar-conditional-rule-template');
		var index = $list.find('.spar-cr-item').length;

		$list.find('.spar-cr-item').each(function() {
			initRuleItem($(this));
		});
		initHelpTips($root);

		$('#spar-add-conditional-rule').on('click', function(e) {
			e.preventDefault();
			var html = $tpl.html();
			if (!html) {
				return;
			}
			var newIndex = index;
			index++;
			html = html.replace(/__INDEX__/g, String(newIndex));
			var $newItem = $(html);
			// Template inputs are disabled to prevent autosave serializing the template itself.
			// Enable them for the cloned rule so it can be saved.
			$newItem.find('fieldset.spar-cr-template-fields').prop('disabled', false);
			$list.append($newItem);
			// Remove "no rules" text if present.
			$list.find('p.description').remove();
			initRuleItem($newItem);
			initHelpTips($newItem);
			if (typeof jQuery !== 'undefined') {
				jQuery('#spar-settings-form').trigger('spar-save-settings');
			}
		});
	}

	function maybeInitWhenVisible() {
		var $panel = $('#spar-settings-tabs .spar-settings-tab[data-tab="conditional-rules"]');
		if ($panel.length && $panel.is(':visible')) {
			initRepeater();
		}
	}

	function observeTabVisibility() {
		var panel = document.querySelector('#spar-settings-tabs .spar-settings-tab[data-tab="conditional-rules"]');
		var root = document.getElementById('spar-settings-tabs');
		if (!panel || !root || typeof window.MutationObserver === 'undefined') {
			return;
		}
		var observer = new MutationObserver(function() {
			// Handles tab switching implementations that toggle `style.display` directly.
			if (panel.offsetParent !== null) {
				initRepeater();
			}
		});
		observer.observe(root, { attributes: true, subtree: true, attributeFilter: ['style', 'class'] });
	}

	// Before form submit, disable inputs inside hidden condition divs so they
	// don't post stale values and cause duplicate category/product IDs to accumulate.
	$(document).on('submit', '#spar-settings-form', function() {
		$('#spar-conditional-rules-list .spar-cr-condition:hidden').find('input, select, textarea').prop('disabled', true).addClass('spar-cr-disabled-for-submit');
	});

	$(document).ready(function() {
		maybeInitWhenVisible();
		observeTabVisibility();
		$(document).on('click', '.nav-tab[data-tab="conditional-rules"]', function() {
			// Allow tab switch to complete.
			setTimeout(function() {
				initRepeater();
			}, 0);
		});
	});
})(jQuery);
