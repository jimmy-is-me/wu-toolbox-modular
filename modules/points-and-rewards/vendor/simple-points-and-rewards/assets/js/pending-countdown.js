/**
 * Pending points countdown timer.
 *
 * Finds every .spar-pending-countdown element, reads its data-available-at
 * Unix timestamp and renders a live "Xd Yh Zm" countdown. The full date is
 * stored in the element's title attribute and shown by the browser on hover.
 */
( function () {
	'use strict';

	/**
	 * Format a Unix timestamp into a countdown string.
	 *
	 * @param {number} ts Unix timestamp (seconds).
	 * @return {string}
	 */
	function formatCountdown( ts ) {
		var now  = Math.floor( Date.now() / 1000 );
		var diff = ts - now;

		if ( diff <= 0 ) {
			return '✅ Available now';
		}

		var days    = Math.floor( diff / 86400 );
		var hours   = Math.floor( ( diff % 86400 ) / 3600 );
		var minutes = Math.floor( ( diff % 3600 ) / 60 );
		var seconds = diff % 60;
		var parts   = [];

		if ( days > 0 )    { parts.push( days + 'd' ); }
		if ( hours > 0 )   { parts.push( hours + 'h' ); }
		if ( minutes > 0 || days > 0 || hours > 0 ) { parts.push( minutes + 'm' ); }
		parts.push( seconds + 's' );

		return 'Available in ' + parts.join( ' ' );
	}

	/**
	 * Update all pending countdown elements on the page.
	 */
	function updatePendingCountdowns() {
		var elements = document.querySelectorAll( '.spar-pending-countdown' );
		elements.forEach( function ( el ) {
			var ts = parseInt( el.getAttribute( 'data-available-at' ), 10 );
			if ( ! isNaN( ts ) ) {
				el.textContent = formatCountdown( ts );
			}
		} );
	}

	// Run on DOM ready and refresh every second. When this script is injected
	// dynamically (e.g. the lazy-loaded rewards widget) DOMContentLoaded has
	// already fired, so start immediately in that case.
	function start() {
		updatePendingCountdowns();
		setInterval( updatePendingCountdowns, 1000 );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}

	// Expose so rewards-widget.js can trigger an update after dynamic rendering.
	window.sparUpdatePendingCountdowns = updatePendingCountdowns;
} )();
