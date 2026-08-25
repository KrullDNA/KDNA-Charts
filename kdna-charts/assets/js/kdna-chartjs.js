/*
 * KDNA Charts, Chart.js initialiser.
 *
 * Each Chart.js chart on the page is a <canvas> carrying a
 * data-kdna-chartjs attribute that names the global its config was
 * localised to (see KDNA_Charts_Renderer_Chartjs). This script finds those
 * canvases, reads each config back, and constructs the chart — one config
 * per canvas, so several charts never share or overwrite state.
 *
 * The config is fully baked in PHP from the resolved style values, because
 * a canvas cannot read CSS custom properties. Nothing here reaches into
 * the DOM for styling; it only wires data to library.
 *
 * It also listens for kdna:content-added, so charts injected after load —
 * a JetEngine load-more, an AJAX tab — initialise too. Each canvas is
 * marked once so a re-scan never double-constructs.
 */

( function () {
	'use strict';

	var MARK = 'kdnaChartjsReady';

	function configFor( canvas ) {
		var name = canvas.getAttribute( 'data-kdna-chartjs' );
		if ( ! name ) { return null; }
		var cfg = window[ name ];
		return ( cfg && 'object' === typeof cfg ) ? cfg : null;
	}

	function initCanvas( canvas ) {
		if ( ! canvas || canvas.dataset[ MARK ] ) { return; }
		if ( ! window.Chart ) { return; }

		var config = configFor( canvas );
		if ( ! config ) { return; }

		// Mark before constructing, so a construction error does not leave a
		// canvas that a later scan retries forever.
		canvas.dataset[ MARK ] = '1';

		try {
			// eslint-disable-next-line no-new
			new window.Chart( canvas, config );
		} catch ( e ) {
			if ( window.console && window.console.error ) {
				window.console.error( 'KDNA Charts: Chart.js failed to render', e );
			}
		}
	}

	function initAll( root ) {
		var scope = root && root.querySelectorAll ? root : document;
		var canvases = scope.querySelectorAll( 'canvas[data-kdna-chartjs]' );
		for ( var i = 0; i < canvases.length; i++ ) {
			initCanvas( canvases[ i ] );
		}
	}

	function boot() {
		initAll( document );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}

	// Charts injected after page load. The detail may carry the added
	// subtree; fall back to a full re-scan, which is cheap because marked
	// canvases are skipped.
	document.addEventListener( 'kdna:content-added', function ( event ) {
		var root = event && event.detail && event.detail.element ? event.detail.element : document;
		initAll( root );
	} );
}() );
