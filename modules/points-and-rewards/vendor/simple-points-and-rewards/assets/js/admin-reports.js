/**
 * Admin Reports charts.
 *
 * Adds hover (and keyboard) tooltips to the Points Trend line chart. The chart
 * itself is server-rendered inline SVG; this only reads the values already
 * present on each hover band, so it degrades to a static chart without JS.
 */
( function () {
	'use strict';

	// Must match the viewBox used in spar_reports_render_trend_chart().
	var VIEW_W = 1000;

	function initChart( wrap ) {
		var svg = wrap.querySelector( '.spar-trend-chart' );
		var tip = wrap.querySelector( '.spar-chart-tooltip' );

		if ( ! svg || ! tip ) {
			return;
		}

		var bands = Array.prototype.slice.call( svg.querySelectorAll( '.spar-chart-band' ) );

		if ( ! bands.length ) {
			return;
		}

		var guide       = svg.querySelector( '.spar-chart-guide' );
		var markEarned  = svg.querySelector( '.spar-chart-marker.is-earned' );
		var markRedeem  = svg.querySelector( '.spar-chart-marker.is-redeemed' );
		var tipLabel    = tip.querySelector( '.spar-tooltip-label' );
		var tipEarned   = tip.querySelector( '[data-role="earned"]' );
		var tipRedeemed = tip.querySelector( '[data-role="redeemed"]' );
		var current     = -1;

		function show( index ) {
			var band = bands[ index ];

			if ( ! band ) {
				return;
			}

			var svgRect = svg.getBoundingClientRect();
			var scale   = svgRect.width ? svgRect.width / VIEW_W : 0;

			if ( ! scale ) {
				return;
			}

			// Unhide before writing, so the live region announces the change.
			tip.hidden = false;

			if ( index !== current ) {
				current              = index;
				tipLabel.textContent = band.getAttribute( 'data-label' ) || '';

				if ( tipEarned ) {
					tipEarned.textContent = band.getAttribute( 'data-earned' ) || '';
				}

				if ( tipRedeemed ) {
					tipRedeemed.textContent = band.getAttribute( 'data-redeemed' ) || '';
				}
			}

			var x  = parseFloat( band.getAttribute( 'data-x' ) ) || 0;
			var ey = parseFloat( band.getAttribute( 'data-earned-y' ) ) || 0;
			var ry = parseFloat( band.getAttribute( 'data-redeemed-y' ) ) || 0;

			if ( guide ) {
				guide.setAttribute( 'x1', x );
				guide.setAttribute( 'x2', x );
			}

			if ( markEarned ) {
				markEarned.setAttribute( 'cx', x );
				markEarned.setAttribute( 'cy', ey );
			}

			if ( markRedeem ) {
				markRedeem.setAttribute( 'cx', x );
				markRedeem.setAttribute( 'cy', ry );
			}

			svg.classList.add( 'is-hovered' );

			// Position the tooltip over the higher of the two data points,
			// keeping it inside the chart wrapper.
			var wrapRect = wrap.getBoundingClientRect();
			var pointX   = ( svgRect.left - wrapRect.left ) + ( x * scale );
			var pointY   = ( svgRect.top - wrapRect.top ) + ( Math.min( ey, ry ) * scale );
			var left     = pointX - ( tip.offsetWidth / 2 );
			var top      = pointY - tip.offsetHeight - 12;
			var below    = top < 0;

			if ( below ) {
				top = pointY + 14;
			}

			left = Math.max( 0, Math.min( left, wrapRect.width - tip.offsetWidth ) );

			tip.classList.toggle( 'is-below', below );
			tip.style.left = Math.round( left ) + 'px';
			tip.style.top  = Math.round( top ) + 'px';
		}

		function hide() {
			current    = -1;
			tip.hidden = true;
			svg.classList.remove( 'is-hovered' );
		}

		function bandFromEvent( event ) {
			var target = event.target;

			return target && target.closest ? target.closest( '.spar-chart-band' ) : null;
		}

		function onPointer( event ) {
			var band = bandFromEvent( event );

			if ( band ) {
				show( bands.indexOf( band ) );
			}
		}

		svg.addEventListener( 'pointermove', onPointer );
		svg.addEventListener( 'pointerdown', onPointer );
		svg.addEventListener( 'pointerleave', hide );

		svg.addEventListener( 'keydown', function ( event ) {
			var next;

			if ( 'Escape' === event.key ) {
				hide();
				return;
			}

			if ( 'Home' === event.key ) {
				next = 0;
			} else if ( 'End' === event.key ) {
				next = bands.length - 1;
			} else if ( 'ArrowLeft' === event.key ) {
				next = ( current < 0 ? bands.length : current ) - 1;
			} else if ( 'ArrowRight' === event.key ) {
				next = ( current < 0 ? -1 : current ) + 1;
			} else {
				return;
			}

			event.preventDefault();
			show( Math.max( 0, Math.min( next, bands.length - 1 ) ) );
		} );

		svg.addEventListener( 'blur', hide );
		window.addEventListener( 'resize', hide );
	}

	function init() {
		var wraps = document.querySelectorAll( '.spar-chart-wrap' );

		Array.prototype.forEach.call( wraps, initChart );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
