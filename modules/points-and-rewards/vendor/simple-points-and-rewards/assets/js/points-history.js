(function($){
	'use strict';

	function doHistoryRequest( page, statusFilter ) {
		var $container   = $( '#spar-points-history-container' );
		var $tbody       = $( '#spar-points-history-tbody' );
		var $pagination  = $( '#spar-points-history-pagination' );
		var $loading     = $container.find( '.spar-pagination-loading' );
		var ajaxUrl      = ( window.sparPointsHistory && sparPointsHistory.ajaxUrl ) || ( typeof ajaxurl !== 'undefined' ? ajaxurl : '' );
		var nonce        = window.sparPointsHistory && sparPointsHistory.nonce;

		$loading.show();
		$tbody.fadeTo( 150, 0.5 );
		$pagination.find( '.spar-pagination-btn' ).prop( 'disabled', true );

		$.ajax({
			url:  ajaxUrl,
			type: 'POST',
			data: {
				action:        'spar_load_points_history',
				page:          page,
				nonce:         nonce,
				status_filter: statusFilter || '',
			}
		}).done(function( resp ){
			if ( resp && resp.success && resp.data ) {
				if ( resp.data.table_html ) {
					$tbody.html( resp.data.table_html );
				}
				if ( resp.data.pagination_html ) {
					$pagination.html( resp.data.pagination_html ).show();
				} else {
					$pagination.empty().hide();
				}
				if ( $container.length ) {
					$( 'html, body' ).animate({ scrollTop: $container.offset().top - 20 }, 300 );
				}
			} else {
				var msg = ( resp && resp.data ) ? ( resp.data.message || resp.data ) : ( window.sparPointsHistory && sparPointsHistory.i18n && sparPointsHistory.i18n.error ) || 'Error';
				alert( msg );
			}
		}).fail(function(){
			var msg = ( window.sparPointsHistory && sparPointsHistory.i18n && sparPointsHistory.i18n.error ) || 'Error';
			alert( msg );
		}).always(function(){
			$loading.hide();
			$tbody.fadeTo( 150, 1 );
		});
	}

	// Paginate while preserving the active status filter.
	$( document ).on( 'click', '.spar-pagination-btn:not([disabled])', function( e ){
		e.preventDefault();
		var page = parseInt( $( this ).data( 'page' ), 10 );
		if ( ! page || page < 1 ) return;
		var statusFilter = $( '#spar-history-status-filter' ).val() || '';
		doHistoryRequest( page, statusFilter );
	});

	// Filter change always resets to page 1.
	$( document ).on( 'change', '#spar-history-status-filter', function(){
		doHistoryRequest( 1, $( this ).val() || '' );
	});

})(jQuery);
