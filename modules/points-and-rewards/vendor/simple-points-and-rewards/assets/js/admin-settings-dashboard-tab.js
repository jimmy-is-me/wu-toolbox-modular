(function($){
	'use strict';
	$(function(){
		var $list = $('#spar-dashboard-tabs-sortable');
		if ($list.length && typeof $list.sortable === 'function') {
			$list.sortable({
				axis: 'y',
				handle: '.dashicons-move',
				placeholder: 'spar-sortable-placeholder',
				update: function() {
					$('#spar-settings-form').trigger('spar-save-settings');
				}
			});
		}

		// Visual state for disabled (unchecked)
		$(document).on('change', '#spar-dashboard-tabs-sortable input[type="checkbox"]', function(){
			var $li = $(this).closest('li');
			$li.toggleClass('is-disabled', !this.checked);
			$li.find('input[type="text"]').prop('disabled', !this.checked);
		});

		// Initialize current states
		$('#spar-dashboard-tabs-sortable input[type="checkbox"]').each(function(){
			var $li = $(this).closest('li');
			$li.toggleClass('is-disabled', !this.checked);
			$li.find('input[type="text"]').prop('disabled', !this.checked);
		});
	});
})(jQuery);
