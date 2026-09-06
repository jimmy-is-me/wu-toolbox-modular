(function($) {
	'use strict';

	let sideCartObserver = null;

	function getSideCartConfig() {
		if (typeof window.sparFunnelKitSideCart === 'undefined') {
			return {};
		}
		return window.sparFunnelKitSideCart || {};
	}

	function injectSideCartDiscountPanel($scope) {
		try {
			var config = getSideCartConfig();
			if (!config.enabled || !config.html) {
				return;
			}
			var selectors = config.selectors || [];
			if (!selectors.length) {
				return;
			}
			var targets = config.targetSelectors || [];
			var $context = ($scope && $scope.length) ? $scope : $(document);
			if ($context.find('.spar-side-cart-discount').length) {
				return;
			}
			var selectorList = selectors.join(',');
			var $containers = $context.find(selectorList);
			if (!$containers.length && $context.is(selectorList)) {
				$containers = $context;
			}
			if ($containers.length > 1) {
				$containers = $containers.filter(function() {
					return $(this).parents(selectorList).length === 0;
				});
			}
			if (!$containers.length) {
				return;
			}
			var $container = $containers.first();
			var $target = $container;
			if (targets.length) {
				var $found = $container.find(targets.join(',')).first();
				if ($found.length) {
					$target = $found;
				} else {
					var $globalTarget = $(targets.join(',')).first();
					if ($globalTarget.length) {
						$target = $globalTarget;
					}
				}
			}
			if (config.requireTarget && $target.is($container)) {
				return;
			}
			if ($target.find('.spar-side-cart-discount').length) {
				return;
			}
			var $wrap = $('<div class="spar-side-cart-discount"></div>').html(config.html);
			if (config.position === 'before') {
				$target.before($wrap);
			} else if (config.position === 'prepend') {
				$target.prepend($wrap);
			} else {
				$target.append($wrap);
			}
		} catch (e) {}
	}

	function initSideCartObserver() {
		if (sideCartObserver || !window.MutationObserver) {
			return;
		}
		sideCartObserver = new MutationObserver(function(mutations) {
			mutations.forEach(function(mutation) {
				if (mutation.type !== 'childList' || !mutation.addedNodes.length) {
					return;
				}
				injectSideCartDiscountPanel($(document));
			});
		});
		sideCartObserver.observe(document.body, { childList: true, subtree: true });
	}

	$(document).ready(function() {
		setTimeout(function() { injectSideCartDiscountPanel($(document)); }, 150);
		initSideCartObserver();

		$(document.body).on('wc_fragments_refreshed added_to_cart removed_from_cart updated_cart_totals', function() {
			setTimeout(function() { injectSideCartDiscountPanel($(document)); }, 50);
		});
	});
})(jQuery);
