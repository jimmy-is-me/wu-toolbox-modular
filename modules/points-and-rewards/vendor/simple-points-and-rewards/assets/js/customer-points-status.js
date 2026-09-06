/* global jQuery, sparCustomerStatus */
	jQuery( function( $ ) {
	// Removed debug alert; script now runs silently.
	function showFeedback( $wrap, type ) {
		var $icon = $wrap.find( '.spar-status-feedback' );
		if ( ! $icon.length ) { return; }
		$icon.stop(true,true).css({ color: type === 'error' ? '#cc0000' : '#46b450' }).fadeIn(150);
		setTimeout( function(){ $icon.fadeOut(300); }, 1200 );
	}

	$( document ).on( 'change', 'select.spar-user-status', function() {
		var $select = $( this );
		var $wrap   = $select.closest( '.spar-user-status-wrapper' );
		var userId  = $wrap.data( 'user-id' );
		var nonce   = $wrap.data( 'nonce' );
		var status  = $select.val();
		if ( ! userId || ! nonce ) { return; }

		$select.prop( 'disabled', true );
		showFeedback( $wrap, 'updating' );

		$.ajax( {
			url: ( window.sparCustomerStatus && window.sparCustomerStatus.ajaxurl ) ? window.sparCustomerStatus.ajaxurl : ( window.ajaxurl || '' ),
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'spar_update_user_status',
				user_id: userId,
				status: status,
				nonce: nonce
			}
		} ).done( function( resp ) {
			if ( resp && resp.success ) {
				showFeedback( $wrap, 'success' );
			} else {
				showFeedback( $wrap, 'error' );
			}
		} ).fail( function() {
			showFeedback( $wrap, 'error' );
		} ).always( function() {
			$select.prop( 'disabled', false );
		} );
	} );
} );
