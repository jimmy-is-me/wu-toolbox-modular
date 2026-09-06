(function($){
	'use strict';

	$(function(){
		var $list = $('#spar-earn-accordion-list');
		if ( ! $list.length ) { return; }

		/**
		 * Move every child accordion (data-parent-key) so it sits
		 * immediately after its parent accordion (data-key).
		 */
		function repositionChildAccordions() {
			$list.find('.spar-earn-accordion-child[data-parent-key]').each( function() {
				var $child  = $(this);
				var $parent = $list.find('.spar-earn-accordion[data-key="' + $child.data('parent-key') + '"]');
				if ( $parent.length ) {
					$parent.after( $child );
				}
			});
		}

		// Reorder accordion DOM elements to match saved order on page load.
		var savedOrder = window.sparEarnOrder || [];
		if ( savedOrder.length ) {
			savedOrder.forEach( function( key ) {
				var $item = $list.find( '.spar-earn-accordion[data-key="' + key + '"]' );
				if ( $item.length ) {
					$list.append( $item );
				}
			} );
		}
		// Move PRO-disabled stubs (no data-key) to the end so free items always appear first.
		$list.find( '.spar-premium-disabled' ).each( function() {
			$list.append( $(this) );
		} );
		// Snap child accordions into place after initial reorder.
		repositionChildAccordions();

		// Init jQuery UI sortable on the accordion list.
		// Only data-key accordions are sortable items; child accordions are excluded.
		if ( $.fn.sortable ) {
			$list.sortable({
				items: '.spar-earn-accordion[data-key]',
				handle: '.spar-drag-handle',
				axis: 'y',
				tolerance: 'pointer',
				placeholder: 'spar-earn-sortable-placeholder',
				forcePlaceholderSize: true,
				update: function() {
					repositionChildAccordions();
					$('#spar-settings-form').trigger('spar-save-settings');
				}
			});
		}

		// Disabled ways to earn remain draggable so their display order can
		// still be changed; the drag handle is intentionally never disabled.
	});
})(jQuery);
