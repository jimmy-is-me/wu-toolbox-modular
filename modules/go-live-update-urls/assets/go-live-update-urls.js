/**
 * Check-all functionality on tools page.
 *
 * @package wutm-go-live-update-urls
 */

jQuery( function ( $ ) {
	$( '[data-js="wutm-go-live-update-urls/checkboxes/check-all"]' ).on( 'click', function () {
		var el = $( this );
		if ( el.prop( 'checked' ) ) {
			$( '[data-list="' + el.data( 'list' ) + '"] input' ).prop( 'checked', true );
		} else {
			$( '[data-list="' + el.data( 'list' ) + '"] input' ).prop( 'checked', false );
		}
	} )
} )
