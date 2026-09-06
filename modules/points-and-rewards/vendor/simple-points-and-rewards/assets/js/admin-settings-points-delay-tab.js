(function($) {
	'use strict';

	function isFieldChecked(fieldName) {
		var checked = false;
		$('input[name="' + fieldName + '"]').each(function() {
			if ($(this).is(':checked')) {
				checked = true;
				return false;
			}
		});
		return checked;
	}

	function updateNoDelayState($row) {
		var noDelay = $row.find('[data-no-delay-toggle]').is(':checked');
		$row.toggleClass('is-no-delay', noDelay);
		$row.find('[data-delay-controls] input[type="number"]').prop('readonly', noDelay);
		$row.find('[data-delay-controls] select').attr('aria-disabled', noDelay ? 'true' : 'false');
	}

	function updatePointsDelayTab() {
		var $settings = $('[data-points-delay-settings]');
		if (!$settings.length) {
			return;
		}

		var enabled = $settings.find('[data-points-delay-master]').is(':checked');
		$settings.find('[data-points-delay-body]').toggleClass('is-disabled', !enabled);

		var pendingCount = parseInt( $settings.attr('data-pending-count') || '0', 10 );
		$settings.find('[data-points-delay-pending-warning]').toggle( !enabled && pendingCount > 0 );

		var visibleRows = 0;
		$settings.find('[data-delay-method]').each(function() {
			var $row = $(this);
			var methodKey = String($row.attr('data-delay-method') || '');
			var fields = String($row.attr('data-enabled-fields') || '').split(',').filter(Boolean);
			var visible = fields.length === 0;

			fields.forEach(function(fieldName) {
				if (isFieldChecked(fieldName)) {
					visible = true;
				}
			});

			// Product Offers & Bonuses uses a bracketed checkbox name that cannot be
			// expressed via data-enabled-fields, so resolve its state directly.
			if (methodKey === 'buy_products') {
				visible = $('input[name="buy_products[enabled]"]').is(':checked');
			} else if (methodKey === 'custom_earn') {
				// Custom ways to earn are code-registered with no live control on this
				// page, so preserve the server-rendered visibility.
				visible = !$row.hasClass('spar-delay-hidden');
			}

			$row.toggleClass('spar-delay-hidden', !visible);
			if (visible) {
				visibleRows++;
			}

			updateNoDelayState($row);
		});

		$settings.find('[data-delay-empty-message]').toggle(visibleRows === 0);
	}

	$(document).ready(function() {
		updatePointsDelayTab();
	});

	$(document).on('change', '#spar-settings-form input[type="checkbox"], #spar-settings-form select', function() {
		updatePointsDelayTab();
	});

})(jQuery);
