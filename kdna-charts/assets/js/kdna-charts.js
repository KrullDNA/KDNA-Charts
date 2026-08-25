/*
 * KDNA Charts, front-end enhancement layer (Stage 13).
 *
 * Three progressive enhancements over the server-rendered SVG chart, each
 * opt-in and each degrading to "the chart still works" when its
 * prerequisites are absent:
 *
 *   1. Scroll-triggered draw-in. When a chart opts in (data-animate="yes"),
 *      it animates as it scrolls into view — via GSAP ScrollTrigger where
 *      the site provides it, or a CSS-class reveal otherwise. Both are
 *      wrapped in a prefers-reduced-motion check: a reader who asks for
 *      less motion gets the finished chart immediately, never a hidden one.
 *
 *   2. A visually hidden data table for screen readers. When a chart opts
 *      in (data-a11y-table="yes") and carries its data as a JSON blob, a
 *      table of that data is built and appended, reachable by assistive
 *      tech while invisible on screen.
 *
 *   3. Mobile label thinning. Below a configurable width (data-thin-below,
 *      default 480px) every other x-axis tick label is hidden, so a crowded
 *      axis stays legible on a phone. It re-evaluates on resize and undoes
 *      itself when the chart grows again.
 *
 * Charts injected after page load — a JetEngine load-more, an AJAX tab —
 * are caught by listening for kdna:content-added. Every wrapper is marked
 * once, so a re-scan never enhances the same chart twice.
 *
 * Nothing here is required for a chart to render or to be styled: it is a
 * layer on top of markup that is already complete and already correct.
 */

( function () {
	'use strict';

	var MARK = 'kdnaEnhanced';
	var DEFAULT_THIN_BELOW = 480;

	var reduceMotion = window.matchMedia
		&& window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	function each( list, fn ) {
		for ( var i = 0; i < list.length; i++ ) { fn( list[ i ], i ); }
	}

	function attrYes( el, name ) {
		return 'yes' === el.getAttribute( name );
	}

	/* ── Data table ──────────────────────────────────────────────────── */

	function chartData( wrapper ) {
		var el = wrapper.querySelector( 'script.kdna-chart__data[type="application/json"]' );
		if ( ! el ) { return null; }
		try {
			return JSON.parse( el.textContent || el.innerHTML || 'null' );
		} catch ( e ) {
			return null;
		}
	}

	function el( tag, text ) {
		var node = document.createElement( tag );
		if ( undefined !== text && null !== text ) { node.textContent = String( text ); }
		return node;
	}

	function buildDataTable( wrapper ) {
		if ( ! attrYes( wrapper, 'data-a11y-table' ) ) { return; }
		if ( wrapper.querySelector( '.kdna-chart__a11y-table' ) ) { return; }

		var data = chartData( wrapper );
		if ( ! data ) { return; }

		var table = renderTable( data );
		if ( ! table ) { return; }

		wrapper.appendChild( table );
	}

	/**
	 * Build a screen-reader table from the slim data payload. Two shapes:
	 * `rows` (categorical: label + value) or `series` (plotted: x with a
	 * column per series).
	 */
	function renderTable( data ) {
		var table = el( 'table' );
		table.className = 'kdna-chart__a11y-table screen-reader-text';

		var caption = el( 'caption', data.caption || 'Chart data' );
		table.appendChild( caption );

		var thead = el( 'thead' );
		var headRow = el( 'tr' );
		var tbody = el( 'tbody' );

		if ( data.rows && data.rows.length ) {
			headRow.appendChild( el( 'th', 'Label' ) );
			headRow.appendChild( el( 'th', 'Value' ) );
			thead.appendChild( headRow );
			each( data.rows, function ( row ) {
				var tr = el( 'tr' );
				tr.appendChild( el( 'th', row.label ) );
				tr.appendChild( el( 'td', row.value ) );
				tbody.appendChild( tr );
			} );
		} else if ( data.series && data.series.length ) {
			// Gather x values in first-seen order across every series.
			var order = [];
			var seen = {};
			each( data.series, function ( s ) {
				each( s.points || [], function ( p ) {
					var x = String( p[ 0 ] );
					if ( ! seen[ x ] ) { seen[ x ] = true; order.push( p[ 0 ] ); }
				} );
			} );

			headRow.appendChild( el( 'th', data.x || 'X' ) );
			each( data.series, function ( s ) {
				headRow.appendChild( el( 'th', s.label || 'Series' ) );
			} );
			thead.appendChild( headRow );

			each( order, function ( xv ) {
				var tr = el( 'tr' );
				tr.appendChild( el( 'th', xv ) );
				each( data.series, function ( s ) {
					var y = '';
					each( s.points || [], function ( p ) {
						if ( String( p[ 0 ] ) === String( xv ) ) { y = p[ 1 ]; }
					} );
					tr.appendChild( el( 'td', y ) );
				} );
				tbody.appendChild( tr );
			} );
		} else {
			return null;
		}

		table.appendChild( thead );
		table.appendChild( tbody );
		return table;
	}

	/* ── Mobile label thinning ───────────────────────────────────────── */

	function setupThinning( wrapper ) {
		var svg = wrapper.querySelector( 'svg' );
		if ( ! svg ) { return; }

		var ticks = svg.querySelectorAll( '.kdna-chart__axis-label--x' );
		if ( ! ticks.length ) { return; }

		var below = parseInt( wrapper.getAttribute( 'data-thin-below' ), 10 );
		if ( ! below || isNaN( below ) ) { below = DEFAULT_THIN_BELOW; }

		var apply = function () {
			var box = wrapper.getBoundingClientRect();
			var width = box.width || ( svg.getBoundingClientRect().width );
			var thin = width > 0 && width < below;
			each( ticks, function ( tick, i ) {
				// Keep the first and last label; drop alternate ones between.
				var drop = thin && ( i % 2 === 1 ) && i !== ticks.length - 1;
				setHidden( tick, drop );
			} );
		};

		apply();

		if ( window.ResizeObserver ) {
			var ro = new window.ResizeObserver( apply );
			ro.observe( wrapper );
		} else {
			window.addEventListener( 'resize', debounce( apply, 150 ) );
		}
	}

	function setHidden( node, hidden ) {
		if ( hidden ) {
			node.setAttribute( 'data-kdna-thinned', '1' );
			node.style.display = 'none';
		} else if ( node.getAttribute( 'data-kdna-thinned' ) ) {
			node.removeAttribute( 'data-kdna-thinned' );
			node.style.display = '';
		}
	}

	function debounce( fn, wait ) {
		var t;
		return function () {
			var ctx = this, args = arguments;
			window.clearTimeout( t );
			t = window.setTimeout( function () { fn.apply( ctx, args ); }, wait );
		};
	}

	/* ── Scroll-triggered draw-in ────────────────────────────────────── */

	function setupAnimation( wrapper ) {
		if ( ! attrYes( wrapper, 'data-animate' ) ) { return; }
		var svg = wrapper.querySelector( 'svg' );
		if ( ! svg ) { return; }

		// A reader who asks for less motion gets the finished chart, never a
		// hidden one: do not arm, do not animate.
		if ( reduceMotion ) { return; }

		wrapper.classList.add( 'kdna-chart--anim-armed' );

		if ( window.gsap && window.ScrollTrigger ) {
			gsapAnimate( wrapper, svg );
		} else {
			cssAnimate( wrapper );
		}

		// Safety net: never leave a chart armed-but-hidden if neither the
		// observer nor ScrollTrigger ever fires (an odd container, a chart
		// already fully in view under an old browser).
		window.setTimeout( function () {
			if ( wrapper.classList.contains( 'kdna-chart--anim-armed' )
				&& ! wrapper.classList.contains( 'kdna-chart--anim-in' ) ) {
				reveal( wrapper, svg );
			}
		}, 3000 );
	}

	function gsapAnimate( wrapper, svg ) {
		try {
			window.gsap.registerPlugin( window.ScrollTrigger );
		} catch ( e ) { /* already registered */ }

		window.ScrollTrigger.create( {
			trigger: wrapper,
			start: 'top 85%',
			once: true,
			onEnter: function () { reveal( wrapper, svg, true ); }
		} );
	}

	function cssAnimate( wrapper ) {
		if ( window.IntersectionObserver ) {
			var io = new window.IntersectionObserver( function ( entries ) {
				each( entries, function ( entry ) {
					if ( entry.isIntersecting ) {
						io.unobserve( entry.target );
						reveal( entry.target, entry.target.querySelector( 'svg' ) );
					}
				} );
			}, { threshold: 0.2, rootMargin: '0px 0px -10% 0px' } );
			io.observe( wrapper );
		} else {
			reveal( wrapper, wrapper.querySelector( 'svg' ) );
		}
	}

	/**
	 * Reveal the chart. The class drives the CSS fade/rise; when GSAP is
	 * present it additionally draws each series line on from its start.
	 */
	function reveal( wrapper, svg, useGsap ) {
		wrapper.classList.remove( 'kdna-chart--anim-armed' );
		wrapper.classList.add( 'kdna-chart--anim-in' );

		if ( ! useGsap || ! window.gsap || ! svg ) { return; }

		var series = svg.querySelectorAll( '.kdna-chart__line' );
		each( series, function ( path ) {
			var length = 0;
			try { length = path.getTotalLength(); } catch ( e ) { length = 0; }
			if ( ! length ) { return; }
			window.gsap.fromTo(
				path,
				{ strokeDasharray: length, strokeDashoffset: length },
				{
					strokeDashoffset: 0,
					duration: 1.1,
					ease: 'power2.out',
					onComplete: function () {
						// Clear the inline dash so hover and print are clean.
						path.style.strokeDasharray = '';
						path.style.strokeDashoffset = '';
					}
				}
			);
		} );
	}

	/* ── Orchestration ───────────────────────────────────────────────── */

	function enhance( wrapper ) {
		if ( ! wrapper || wrapper.dataset[ MARK ] ) { return; }
		wrapper.dataset[ MARK ] = '1';

		buildDataTable( wrapper );
		setupThinning( wrapper );
		setupAnimation( wrapper );
	}

	function enhanceAll( root ) {
		var scope = ( root && root.querySelectorAll ) ? root : document;
		var wrappers = scope.querySelectorAll( '.kdna-chart' );
		each( wrappers, enhance );
		// The root may itself be a chart figure (a single injected chart).
		if ( root && root.classList && root.classList.contains( 'kdna-chart' ) ) {
			enhance( root );
		}
	}

	function boot() { enhanceAll( document ); }

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}

	// Charts injected after page load.
	document.addEventListener( 'kdna:content-added', function ( event ) {
		var root = ( event && event.detail && event.detail.element )
			? event.detail.element
			: document;
		enhanceAll( root );
	} );

	// Exposed for other stages and for tests.
	window.kdnaChartsEnhance = enhanceAll;
	window.kdnaChartsInternals = { renderTable: renderTable };
}() );
