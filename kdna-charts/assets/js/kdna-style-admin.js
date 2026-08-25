/*
 * KDNA Charts, the style engine's admin.
 *
 * Drives two screens from one component: Settings > KDNA Charts, which
 * edits the global defaults, and the Style tab of the chart editor,
 * which edits one chart's overrides. It reads its seed from
 * window.KDNAChartsStyles and saves through the kdna-charts/v1 REST
 * routes.
 *
 * ── Shaping ───────────────────────────────────────────────────────────
 *
 * Alpine's x-model needs an assignable path: binding to
 * values['frame_padding']['mobile']['top'] throws if any link in that
 * chain is undefined, and what is stored is deliberately sparse: an
 * unset control is absent, not present and empty, because absent is what
 * inherit means everywhere downstream. So the seed is expanded into a
 * full skeleton before Alpine binds, and collapsed back to a sparse
 * object on save. The empties never reach the server, and the server
 * drops any that do.
 *
 * ── Addressing ────────────────────────────────────────────────────────
 *
 * Everything below takes the same two arguments: a control key, and a
 * device that is empty for a flat control. That pair reaches any value
 * in state, so the markup never hands the component a path to eval.
 *
 * ── One-way bindings ──────────────────────────────────────────────────
 *
 * The native colour input and the range input are bound with :value and
 * @input rather than x-model. Neither can represent "unset": a colour
 * input shows #000000 for an empty value, and a range parks its thumb
 * somewhere regardless. Two-way binding would write those placeholder
 * positions into state on first paint and quietly turn every untouched
 * control into a set one, which, in a system where set and unset are
 * the whole cascade, would mean a hundred and fifty properties written
 * onto every chart the first time anybody opened this page.
 */

( function () {
	'use strict';

	var DEVICES = [ 'desktop', 'tablet', 'mobile' ];
	var SIDES = [ 'top', 'right', 'bottom', 'left' ];
	var MAX_VALUE_LENGTH = 200;

	function boot() {
		return window.KDNAChartsStyles || {
			schema: {}, sections: {}, devices: DEVICES, values: {},
			context: 'global', chartId: 0, inherited: {},
			restUrl: '', nonce: '', strings: {}, preview: null
		};
	}

	/* ── Shaping and collapsing ───────────────────────────────────── */

	/**
	 * KDNA_Charts_Style_Schema::as_device_map()
	 *
	 * Half the value shapes here ARE objects, so testing whether a value
	 * is an object says nothing about whether it is a device map. The
	 * test is whether a device key is present; the two shapes cannot
	 * collide, because no value shape has a key called desktop, tablet
	 * or mobile.
	 */
	function asDeviceMap( value ) {
		if ( value && 'object' === typeof value ) {
			for ( var i = 0; i < DEVICES.length; i++ ) {
				if ( Object.prototype.hasOwnProperty.call( value, DEVICES[ i ] ) ) {
					return value;
				}
			}
		}
		return { desktop: value };
	}

	/** A blank value in this control type's storage shape. */
	function emptyLeaf( definition ) {
		var units = ( definition && definition.units ) || [];

		if ( 'dimensions' === definition.type ) {
			return { top: '', right: '', bottom: '', left: '', unit: units[ 0 ] || '', linked: true };
		}
		if ( 'slider' === definition.type ) {
			return { size: '', unit: units[ 0 ] || '' };
		}
		return '';
	}

	/** Merge a stored leaf value over a blank one, so every key exists. */
	function shapeLeaf( definition, stored ) {
		var blank = emptyLeaf( definition );

		if ( 'object' !== typeof blank || null === blank ) {
			return ( null === stored || undefined === stored ) ? blank : stored;
		}

		var out = Object.assign( {}, blank );
		if ( stored && 'object' === typeof stored ) {
			Object.keys( blank ).forEach( function ( k ) {
				if ( undefined !== stored[ k ] && null !== stored[ k ] ) {
					out[ k ] = stored[ k ];
				}
			} );
		}
		return out;
	}

	function shapeControl( definition, stored ) {
		if ( ! definition.responsive ) {
			return shapeLeaf( definition, stored );
		}

		/*
		 * Through asDeviceMap, because a stored value can legitimately be
		 * bare: a preset written by hand, or an option set by WP-CLI,
		 * neither of which has been through the sanitiser that would
		 * have normalised it. Reading stored.desktop off a bare value
		 * gives undefined, so the form would load blank and the next
		 * save would quietly delete a setting the site was rendering.
		 */
		var source = ( null === stored || undefined === stored ) ? {} : asDeviceMap( stored );

		var byDevice = {};
		DEVICES.forEach( function ( device ) {
			byDevice[ device ] = shapeLeaf( definition, source[ device ] );
		} );
		return byDevice;
	}

	/** Expand the sparse stored set into a fully populated skeleton. */
	function shapeAll( schema, values ) {
		var shaped = {};
		Object.keys( schema ).forEach( function ( key ) {
			shaped[ key ] = shapeControl( schema[ key ], values && values[ key ] );
		} );
		return shaped;
	}

	function leafIsEmpty( definition, value ) {
		if ( null === value || undefined === value ) { return true; }

		if ( 'object' === typeof value ) {
			return ! Object.keys( value ).some( function ( k ) {
				// unit and linked are settings about a value, not a value:
				// a dimensions control holding nothing but a unit and a
				// link state is still unset.
				if ( 'unit' === k || 'linked' === k ) { return false; }
				return '' !== String( value[ k ] ).trim();
			} );
		}

		return '' === String( value ).trim();
	}

	function collapseControl( definition, value ) {
		if ( ! definition.responsive ) {
			return leafIsEmpty( definition, value ) ? undefined : value;
		}

		var byDevice = {};
		DEVICES.forEach( function ( device ) {
			var leaf = value && value[ device ];
			if ( ! leafIsEmpty( definition, leaf ) ) { byDevice[ device ] = leaf; }
		} );
		return Object.keys( byDevice ).length ? byDevice : undefined;
	}

	/** Strip everything blank, so the payload carries only real values. */
	function collapseAll( schema, values ) {
		var out = {};
		Object.keys( schema ).forEach( function ( key ) {
			var collapsed = collapseControl( schema[ key ], values[ key ] );
			if ( undefined !== collapsed ) { out[ key ] = collapsed; }
		} );
		return out;
	}

	/* ── Colour helper ────────────────────────────────────────────── */

	/**
	 * A #rrggbb the native colour input can display.
	 *
	 * Short hex is expanded, since the input rejects the three-digit
	 * form. rgb() and rgba() are converted, dropping the alpha the input
	 * cannot show. Anything else (unset, transparent, or nonsense
	 * mid-typing) parks the swatch on black, without that ever being
	 * written to state.
	 */
	function toSwatch( value ) {
		var v = String( value == null ? '' : value ).trim().toLowerCase();

		if ( /^#[0-9a-f]{3}$/.test( v ) ) {
			return '#' + v[ 1 ] + v[ 1 ] + v[ 2 ] + v[ 2 ] + v[ 3 ] + v[ 3 ];
		}
		if ( /^#[0-9a-f]{6}$/.test( v ) ) {
			return v;
		}

		var rgb = v.match( /^rgba?\(([^)]+)\)$/ );
		if ( rgb ) {
			var parts = rgb[ 1 ].split( ',' ).map( function ( p ) { return parseFloat( p.trim() ); } );
			if ( parts.length >= 3 && parts.slice( 0, 3 ).every( function ( n ) { return ! isNaN( n ); } ) ) {
				return '#' + parts.slice( 0, 3 ).map( function ( n ) {
					var b = Math.max( 0, Math.min( 255, Math.round( n ) ) );
					return ( b < 16 ? '0' : '' ) + b.toString( 16 );
				} ).join( '' );
			}
		}

		return '#000000';
	}

	/**
	 * A short, CSS-shaped token for one value: what the control will
	 * actually contribute, not a description of it. Four equal dimension
	 * sides collapse to one number, because "16px" reads better than
	 * "16 16 16 16px".
	 */
	function leafToken( definition, value ) {
		if ( leafIsEmpty( definition, value ) ) { return ''; }

		if ( 'dimensions' === definition.type ) {
			var unit = value.unit || '';
			var sides = SIDES.map( function ( side ) {
				var part = value[ side ];
				return ( '' === part || null === part || undefined === part ) ? '0' : String( part );
			} );
			var allEqual = sides.every( function ( s ) { return s === sides[ 0 ]; } );
			return allEqual ? sides[ 0 ] + unit : sides.join( ' ' ) + unit;
		}

		if ( 'slider' === definition.type ) {
			return String( value.size ) + ( value.unit || '' );
		}

		return String( value );
	}

	/**
	 * One control's contribution to a summary. A responsive control
	 * shows the first breakpoint that carries a value, with a + when
	 * others do too, which is enough to tell that a breakpoint override
	 * exists without clicking through to find it.
	 */
	function controlToken( definition, value ) {
		if ( ! definition.responsive ) {
			return leafToken( definition, value );
		}

		var set = DEVICES.filter( function ( device ) {
			return value && ! leafIsEmpty( definition, value[ device ] );
		} );
		if ( ! set.length ) { return ''; }

		var token = leafToken( definition, value[ set[ 0 ] ] );
		return set.length > 1 ? token + '+' : token;
	}

	/* ── The resolver, ported ─────────────────────────────────────────
	 *
	 * The live preview writes custom properties straight onto the
	 * wrapper inside the iframe, which means the browser has to be told
	 * the same thing PHP would have written at render time. That is a
	 * second implementation of KDNA_Charts_Style_Resolver, and second
	 * implementations drift.
	 *
	 * Two things hold it in place. It is driven by the same schema
	 * object the PHP reads, so anything expressible in a schema entry
	 * (type, units, responsive, css_var, value_map) needs no code here
	 * at all. And the pair is checked by an executable parity test that
	 * runs both over the same value sets and compares the property maps,
	 * so a divergence is a failing test rather than a preview that
	 * quietly lies.
	 *
	 * Every function below is a transliteration of its PHP counterpart,
	 * named the same, in the same order.
	 */

	function isNumeric( value ) {
		if ( 'number' === typeof value ) { return isFinite( value ); }
		if ( 'string' !== typeof value ) { return false; }
		return /^\s*[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?\s*$/.test( value );
	}

	/** KDNA_Charts_Style_Resolver::is_inherit() */
	function isInherit( value ) {
		if ( null === value || undefined === value ) { return true; }
		if ( 'boolean' === typeof value ) { return true; }

		if ( 'string' === typeof value ) {
			var trimmed = value.trim();
			return '' === trimmed || 'inherit' === trimmed.toLowerCase();
		}

		if ( 'object' === typeof value ) {
			var keys = Object.keys( value );
			if ( ! keys.length ) { return true; }
			return ! keys.some( function ( k ) {
				if ( 'unit' === k || 'linked' === k ) { return false; }
				return ! isInherit( value[ k ] );
			} );
		}

		// Numbers, including 0, are values.
		return false;
	}

	/** KDNA_Charts_Style_Resolver::number() */
	function cssNumber( value ) {
		var f = parseFloat( value );
		if ( ! isFinite( f ) ) { return '0'; }
		// PHP casts an integral float to int; otherwise it formats to
		// four decimal places and trims the trailing zeros, which is what
		// rounding to 1e-4 and stringifying gives here.
		if ( Math.floor( f ) === f ) { return String( 0 === f ? 0 : f ); }
		return String( Math.round( f * 10000 ) / 10000 );
	}

	/** KDNA_Charts_Style_Resolver::resolve_unit() */
	function resolveUnit( definition, value ) {
		var units = ( definition && definition.units ) || [];
		var unit = ( value && 'object' === typeof value && undefined !== value.unit )
			? String( value.unit )
			: null;

		if ( null !== unit && -1 !== units.indexOf( unit ) ) { return unit; }
		return units.length ? String( units[ 0 ] ) : '';
	}

	/** KDNA_Charts_Style_Resolver::dimensions_value() */
	function dimensionsValue( definition, value ) {
		if ( ! value || 'object' !== typeof value ) { return ''; }

		var unit = resolveUnit( definition, value );
		var sides = [];
		var any = false;

		SIDES.forEach( function ( side ) {
			var part = undefined === value[ side ] ? '' : value[ side ];
			if ( 'string' === typeof part ) { part = part.trim(); }
			if ( '' === part || null === part || ! isNumeric( part ) ) {
				// A side left blank counts as 0, so a partly filled
				// control still produces valid CSS.
				sides.push( '0' + unit );
				return;
			}
			any = true;
			sides.push( cssNumber( part ) + unit );
		} );

		return any ? sides.join( ' ' ) : '';
	}

	/** KDNA_Charts_Style_Resolver::slider_value() */
	function sliderValue( definition, value ) {
		if ( isNumeric( value ) ) { value = { size: value }; }
		if ( ! value || 'object' !== typeof value || undefined === value.size ) { return ''; }

		var size = value.size;
		if ( 'string' === typeof size ) { size = size.trim(); }
		if ( '' === size || null === size || ! isNumeric( size ) ) { return ''; }

		return cssNumber( size ) + resolveUnit( definition, value );
	}

	/** KDNA_Charts_Style_Resolver::css_value() */
	function cssValue( definition, value ) {
		if ( isInherit( value ) ) { return ''; }

		switch ( definition.type ) {
			case 'dimensions':
				return dimensionsValue( definition, value );

			case 'slider':
				return sliderValue( definition, value );

			case 'number':
				if ( ! isNumeric( value ) ) { return ''; }
				return cssNumber( value );

			case 'select':
				var key = ( null === value || 'object' === typeof value ) ? '' : String( value ).trim();
				if ( '' === key ) { return ''; }
				// A select can store a key that is not the CSS value, such
				// as an alignment resolving to a margin shorthand.
				if ( definition.value_map && undefined !== definition.value_map[ key ] ) {
					return String( definition.value_map[ key ] );
				}
				return key;

			default:
				return ( null === value || 'object' === typeof value ) ? '' : String( value ).trim();
		}
	}

	/** KDNA_Charts_Style_Resolver::properties_for() */
	function propertiesFor( definition, value, out ) {
		if ( isInherit( value ) ) { return out; }

		var cssVar = definition.css_var || '';
		if ( '' === cssVar ) { return out; }

		if ( ! definition.responsive ) {
			var flat = cssValue( definition, value );
			if ( '' !== flat ) { out[ cssVar ] = flat; }
			return out;
		}

		var byDevice = asDeviceMap( value );
		DEVICES.forEach( function ( device ) {
			if ( ! Object.prototype.hasOwnProperty.call( byDevice, device ) ) { return; }
			var css = cssValue( definition, byDevice[ device ] );
			// Absent, not empty: the stylesheet's fallback chain only
			// falls through on an undefined property.
			if ( '' === css ) { return; }
			out[ 'desktop' === device ? cssVar : cssVar + '-' + device ] = css;
		} );

		return out;
	}

	/** KDNA_Charts_Style_Resolver::merge_value() */
	function mergeValue( current, incoming, definition ) {
		if ( isInherit( incoming ) ) { return null; }

		if ( ! definition.responsive ) { return incoming; }

		incoming = asDeviceMap( incoming );

		var byDevice = ( current && 'object' === typeof current ) ? Object.assign( {}, current ) : {};

		DEVICES.forEach( function ( device ) {
			if ( ! Object.prototype.hasOwnProperty.call( incoming, device ) ) { return; }
			// Skipped, not cleared: inherit means "let the layer beneath
			// show through" at every level.
			if ( isInherit( incoming[ device ] ) ) { return; }
			byDevice[ device ] = incoming[ device ];
		} );

		return Object.keys( byDevice ).length ? byDevice : null;
	}

	/** KDNA_Charts_Style_Resolver::sanitize_css_value() */
	function sanitizeCssValue( value ) {
		if ( null === value || undefined === value || 'object' === typeof value ) { return ''; }

		var out = String( value ).trim();
		if ( '' === out ) { return ''; }

		out = out.replace( /[\x00-\x1F\x7F]/g, '' );
		if ( '' === out ) { return ''; }
		if ( out.length > MAX_VALUE_LENGTH ) { return ''; }
		if ( /[;{}<>\\]|\/\*|\*\//.test( out ) ) { return ''; }
		if ( 0 !== ( out.split( '"' ).length - 1 ) % 2 ) { return ''; }
		if ( 0 !== ( out.split( "'" ).length - 1 ) % 2 ) { return ''; }
		if ( /(url|expression|image-set|-moz-binding|javascript|@import)\s*[:(]/i.test( out ) ) { return ''; }

		return out;
	}

	/** KDNA_Charts_Style_Resolver::resolve_values() then flatten(). */
	function resolveProperties( schema, layers ) {
		var values = {};

		( layers || [] ).forEach( function ( layer ) {
			if ( ! layer || 'object' !== typeof layer ) { return; }
			Object.keys( layer ).forEach( function ( key ) {
				if ( ! schema[ key ] ) { return; }
				var merged = mergeValue(
					undefined === values[ key ] ? null : values[ key ],
					layer[ key ],
					schema[ key ]
				);
				if ( null === merged ) { return; }
				values[ key ] = merged;
			} );
		} );

		var raw = {};
		Object.keys( schema ).forEach( function ( key ) {
			if ( ! Object.prototype.hasOwnProperty.call( values, key ) ) { return; }
			propertiesFor( schema[ key ], values[ key ], raw );
		} );

		// to_style_attribute()'s output check, applied here for the same
		// reason: the preview should show what the front end would
		// render, including a value the front end would drop.
		var properties = {};
		Object.keys( raw ).forEach( function ( name ) {
			if ( ! /^--[A-Za-z0-9_-]+$/.test( name ) ) { return; }
			var clean = sanitizeCssValue( raw[ name ] );
			if ( '' === clean ) { return; }
			properties[ name ] = clean;
		} );

		return properties;
	}

	/* ── Component ────────────────────────────────────────────────── */

	function kdnaChartsStyleAdmin() {
		var seed = boot();

		return {
			schema: seed.schema,
			strings: seed.strings || {},
			section: Object.keys( seed.sections || {} )[ 0 ] || 'frame',
			context: seed.context || 'global',
			values: {},
			device: {},
			query: '',
			/*
			 * Which controls the user has taken off inherit. Mostly this
			 * tracks hasValue, but not always: overriding a control whose
			 * inherited value is itself empty has to leave the inputs
			 * showing even though nothing is stored yet, or Override
			 * would look like it did nothing.
			 */
			overridden: {},
			saving: false,
			dirty: false,
			status: '',
			statusClass: '',
			_baseline: '',
			/*
			 * What was last written into the iframe, so a repaint only
			 * touches what changed. Reset whenever the markup is
			 * replaced: a fresh wrapper carries nothing, and a stale
			 * record here would convince the repaint that every property
			 * was already in place and skip the lot.
			 */
			_painted: {},
			/*
			 * The element _painted describes. Compared by identity rather
			 * than trusted, because the editor replaces its preview
			 * markup wholesale and a stale record would convince the
			 * repaint that a brand new wrapper already had everything.
			 */
			_paintedEl: null,

			preview: seed.preview || null,
			previewChart: 0,
			previewDevice: 'desktop',
			previewLoading: false,
			previewError: '',
			previewEmpty: false,

			importOpen: false,
			importText: '',
			importing: false,
			discarded: [],

			init: function () {
				this.values = shapeAll( this.schema, seed.values );
				this.device = this.initialDevices();
				this.overridden = this.initialOverrides();
				this._baseline = JSON.stringify( collapseAll( this.schema, this.values ) );

				// A deep watch on the whole tree is what keeps the save bar
				// honest without wiring a handler onto every one of a
				// hundred and fifty inputs.
				this.$watch( 'values', function () {
					this.dirty = JSON.stringify( collapseAll( this.schema, this.values ) ) !== this._baseline;
					if ( this.dirty ) { this.status = ''; this.statusClass = ''; }
					// The same watch drives the preview: every edit
					// repaints, and repainting is a few dozen setProperty
					// calls on one element, with no fetch and no reflow of
					// the markup.
					this.paintPreview();
				}.bind( this ) );

				if ( this.preview ) {
					this.restorePreviewPrefs();
					this.$nextTick( function () { this.loadPreview(); }.bind( this ) );

					[ 'previewChart', 'previewDevice' ].forEach( function ( key ) {
						this.$watch( key, function () { this.rememberPreviewPrefs(); }.bind( this ) );
					}.bind( this ) );
				}

				if ( this.isChart() ) {
					this.$nextTick( function () { this.watchHostPreview(); }.bind( this ) );
				}
			},

			/* ── Filtering ───────────────────────────────────────────
			 * A hundred and fifty controls across thirteen sections is
			 * more than anybody scans. The filter searches every
			 * section, not the open one, and the tab counts say where
			 * the matches are, because the common case is knowing what
			 * you want and not which section it is in.
			 */

			matches: function ( key ) {
				var q = this.query.trim().toLowerCase();
				if ( '' === q ) { return true; }

				var definition = this.schema[ key ];
				if ( ! definition ) { return false; }

				var haystack = [
					definition.label || '',
					definition.group || '',
					definition.css_var || '',
					key
				].join( ' ' ).toLowerCase();

				// Every word has to appear somewhere, so "label size"
				// finds a control called Size inside a Tick Labels group.
				return q.split( /\s+/ ).every( function ( word ) {
					return -1 !== haystack.indexOf( word );
				} );
			},

			sectionMatches: function ( section ) {
				var self = this;
				return Object.keys( this.schema ).some( function ( key ) {
					return self.schema[ key ].section === section && self.matches( key );
				} );
			},

			searchSummary: function () {
				var self = this;
				var hits = Object.keys( this.schema ).filter( function ( key ) {
					return self.matches( key );
				} );

				if ( ! hits.length ) { return this.strings.noMatches || 'No controls match that.'; }

				var sections = {};
				hits.forEach( function ( key ) {
					var s = self.schema[ key ].section;
					sections[ s ] = ( sections[ s ] || 0 ) + 1;
				} );

				var names = ( seed.sections || {} );
				return Object.keys( sections ).map( function ( s ) {
					return ( names[ s ] || s ) + ' (' + sections[ s ] + ')';
				} ).join( ', ' );
			},

			/* ── Live preview ──────────────────────────────────────── */

			PREVIEW_PREFS_KEY: 'kdnaChartsStylePreview',

			/**
			 * Which chart and which width you are looking at is a working
			 * position, not a transient one: styling for mobile means
			 * sitting at mobile for a while, and losing it on every
			 * reload means setting it again every time.
			 *
			 * Deliberately not in the saved style values. These say what
			 * you are LOOKING at, not what the site renders, and storing
			 * them on the server would also mean a write on every
			 * dropdown change.
			 */
			restorePreviewPrefs: function () {
				this.previewChart = this.preview.chartId;

				var saved = null;
				try {
					saved = JSON.parse( window.localStorage.getItem( this.PREVIEW_PREFS_KEY ) || 'null' );
				} catch ( e ) {
					saved = null;
				}
				if ( ! saved || 'object' !== typeof saved ) { return; }

				/*
				 * Validated against what this site actually offers. A
				 * remembered chart that has since been deleted must not
				 * leave the pane pointing at something that is not there.
				 *
				 * On a chart edit screen the remembered chart is ignored
				 * outright: the chart being edited is the only sensible
				 * thing to preview, and it is already at the head of the
				 * list.
				 */
				if ( 'chart' !== this.context ) {
					var charts = ( this.preview.charts || [] ).map( function ( c ) { return c.id; } );
					if ( -1 !== charts.indexOf( parseInt( saved.chart, 10 ) ) ) {
						this.previewChart = parseInt( saved.chart, 10 );
					}
				}

				if ( ( this.preview.widths || {} )[ saved.device ] ) {
					this.previewDevice = saved.device;
				}
			},

			rememberPreviewPrefs: function () {
				if ( ! this.preview ) { return; }
				try {
					window.localStorage.setItem( this.PREVIEW_PREFS_KEY, JSON.stringify( {
						chart: parseInt( this.previewChart, 10 ),
						device: this.previewDevice
					} ) );
				} catch ( e ) {
					// Private browsing, or a full quota. Not worth
					// surfacing: the pane still works, it just forgets.
				}
			},

			previewFrame: function () {
				return this.$refs ? this.$refs.previewFrame : null;
			},

			previewDoc: function () {
				var frame = this.previewFrame();
				try {
					return frame ? frame.contentDocument : null;
				} catch ( e ) {
					return null;
				}
			},

			previewWidth: function () {
				var widths = ( this.preview && this.preview.widths ) || {};
				return widths[ this.previewDevice ] || 1200;
			},

			setPreviewDevice: function ( key ) {
				this.previewDevice = key;
				// A narrower viewport is a taller chart, sometimes by a
				// lot: the caption wraps and the stat blocks stack.
				this.fitPreview();
			},

			/**
			 * Match the frame's height to its content, so the preview is
			 * not a letterbox with the source line cut off. Bounded at
			 * both ends: a small chart should still look like a preview
			 * pane, and a tall one should not push the controls off the
			 * screen.
			 */
			fitPreview: function () {
				var self = this;
				window.requestAnimationFrame( function () {
					var frame = self.previewFrame();
					var doc = self.previewDoc();
					if ( ! frame || ! doc || ! doc.body ) { return; }

					/*
					 * The body, not the documentElement. The root
					 * element's scrollHeight is never less than the
					 * viewport, which is the height we are about to set,
					 * so measuring it would ratchet: the frame could grow
					 * and never shrink again.
					 */
					frame.style.height = Math.min( 900, Math.max( 220, doc.body.scrollHeight + 4 ) ) + 'px';
				} );
			},

			previewChartInfo: function () {
				var id = parseInt( this.previewChart, 10 );
				return ( ( this.preview && this.preview.charts ) || [] ).filter( function ( c ) {
					return c.id === id;
				} )[ 0 ] || null;
			},

			/**
			 * Whether the previewed chart has overrides the pane is not
			 * showing. Only meaningful on the global page: the per chart
			 * panel IS the overrides, and shows them.
			 */
			previewHasOverrides: function () {
				if ( 'chart' === this.context ) { return false; }
				var info = this.previewChartInfo();
				return !! ( info && info.hasOverrides );
			},

			/**
			 * The iframe's document shell: the front-end stylesheet and
			 * an empty root to drop markup into.
			 *
			 * Written once and then left alone. Rewriting it per fetch
			 * would throw away the loaded stylesheet and re-request it,
			 * and the new document would paint the chart unstyled until
			 * it came back: a flash on every chart change, for nothing.
			 */
			ensurePreviewShell: function () {
				var doc = this.previewDoc();
				if ( ! doc ) { return null; }
				if ( doc.getElementById( 'kdna-preview-root' ) ) { return doc; }

				var links = ( this.preview.css || [] ).map( function ( href ) {
					return '<link rel="stylesheet" href="' + href.replace( /"/g, '&quot;' ) + '">';
				} ).join( '' );

				doc.open();
				doc.write(
					'<!doctype html><html><head><meta charset="utf-8">' +
					'<meta name="viewport" content="width=device-width, initial-scale=1">' +
					links +
					'<style>html,body{margin:0;padding:16px;background:#fff;' +
					'font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;}</style>' +
					'</head><body><div id="kdna-preview-root"></div></body></html>'
				);
				doc.close();

				return doc;
			},

			/* ── The chart editor's preview ──────────────────────────
			 * A chart panel has no iframe of its own, and does not need
			 * one: the chart editor already has a live preview beside
			 * it, rendered by the same renderer, and two live previews on
			 * one screen is one too many.
			 *
			 * So the panel paints into that one instead. The editor
			 * refreshes it over AJAX whenever the DATA changes, replacing
			 * the markup wholesale, which is why there is an observer
			 * below rather than a one-off paint: without it, editing a
			 * data point would silently revert the preview to the last
			 * SAVED styling while the form still showed the unsaved ones.
			 */
			HOST_PREVIEW_SELECTOR: '.kdna-editor__preview-stage',

			hostStage: function () {
				return this.isChart() ? document.querySelector( this.HOST_PREVIEW_SELECTOR ) : null;
			},

			watchHostPreview: function () {
				var stage = this.hostStage();
				if ( ! stage || ! window.MutationObserver ) { return; }

				var self = this;
				new window.MutationObserver( function () {
					// New markup means a new wrapper carrying the SAVED
					// attribute and none of this form's edits, so the
					// record of what was painted is worthless.
					self._painted = {};
					self._paintedEl = null;
					self.paintPreview();
				} ).observe( stage, { childList: true, subtree: true } );

				this.paintPreview();
			},

			previewWrapper: function () {
				if ( this.isChart() ) {
					var stage = this.hostStage();
					return stage ? stage.querySelector( '.kdna-chart' ) : null;
				}

				var doc = this.previewDoc();
				return doc ? doc.querySelector( '.kdna-chart' ) : null;
			},

			loadPreview: function () {
				if ( ! this.preview ) { return; }

				var id = parseInt( this.previewChart, 10 );
				if ( ! id ) { return; }

				var self = this;

				this.previewLoading = true;
				this.previewError = '';

				window.fetch( this.preview.restUrl + id, {
					method: 'GET',
					credentials: 'same-origin',
					headers: { 'X-WP-Nonce': seed.nonce }
				} ).then( function ( response ) {
					return response.json().then( function ( body ) {
						return { ok: response.ok, body: body };
					} );
				} ).then( function ( result ) {
					self.previewLoading = false;

					if ( ! result.ok || ! result.body || undefined === result.body.html ) {
						self.previewError = ( result.body && result.body.message )
							? result.body.message
							: ( self.strings.previewFailed || 'Could not load the preview.' );
						return;
					}

					self.previewEmpty = !! result.body.empty;

					var doc = self.ensurePreviewShell();
					if ( ! doc ) { return; }

					// The markup is this plugin's own renderer output,
					// fetched from an authenticated route on this origin,
					// and it is exactly what the front end would print.
					doc.getElementById( 'kdna-preview-root' ).innerHTML = result.body.html;

					// A new wrapper element, carrying nothing. Without
					// this the repaint would compare against what it wrote
					// to the PREVIOUS wrapper, conclude everything was
					// already in place, and leave the new one bare.
					self._painted = {};
					self.paintPreview();
				} ).catch( function () {
					self.previewLoading = false;
					self.previewError = self.strings.previewFailed || 'Could not load the preview.';
				} );
			},

			/**
			 * Push the current form state into the iframe.
			 *
			 * Properties that resolve to nothing are REMOVED rather than
			 * set empty, for the same reason the render path omits them:
			 * the stylesheet's fallback chains only fall through on a
			 * property that is not there.
			 */
			paintPreview: function () {
				var wrapper = this.previewWrapper();
				if ( ! wrapper ) { return; }

				/*
				 * A wrapper this paint has not seen before may already
				 * carry a style attribute the server wrote from the SAVED
				 * values, which is exactly what the pane must not show.
				 * Every property the schema can write is stripped from it
				 * first, so what is left is the form and nothing else.
				 *
				 * Only the properties this engine owns are removed. The
				 * wrapper's other inline styles, if a theme or a widget
				 * ever adds any, are not this pane's to touch.
				 */
				if ( this._paintedEl !== wrapper ) {
					this.stripOwnProperties( wrapper );
					this._painted = {};
					this._paintedEl = wrapper;
				}

				var properties = this.previewProperties();
				var previous = this._painted || {};

				Object.keys( previous ).forEach( function ( name ) {
					if ( ! Object.prototype.hasOwnProperty.call( properties, name ) ) {
						wrapper.style.removeProperty( name );
					}
				} );

				Object.keys( properties ).forEach( function ( name ) {
					if ( previous[ name ] !== properties[ name ] ) {
						wrapper.style.setProperty( name, properties[ name ] );
					}
				} );

				this._painted = properties;

				// The iframe has to be resized to its content. The
				// editor's own preview is laid out by the admin page and
				// is not this pane's to move.
				if ( this.preview ) { this.fitPreview(); }
			},

			/** Every property this engine can write, in every device form. */
			ownProperties: function () {
				var self = this;
				var names = [];
				Object.keys( this.schema ).forEach( function ( key ) {
					var definition = self.schema[ key ];
					var cssVar = definition.css_var || '';
					if ( '' === cssVar ) { return; }
					names.push( cssVar );
					if ( definition.responsive ) {
						names.push( cssVar + '-tablet', cssVar + '-mobile' );
					}
				} );
				return names;
			},

			stripOwnProperties: function ( element ) {
				this.ownProperties().forEach( function ( name ) {
					element.style.removeProperty( name );
				} );
			},

			/**
			 * What the preview is about to write, and what the parity
			 * test compares against the PHP resolver.
			 *
			 * On a chart panel the global layer goes underneath, because
			 * that is what the chart will actually render as. On the
			 * global page there is no layer underneath.
			 */
			previewProperties: function () {
				var layers = [];
				if ( 'chart' === this.context ) { layers.push( seed.inherited || {} ); }
				layers.push( collapseAll( this.schema, this.values ) );
				return resolveProperties( this.schema, layers );
			},

			/* ── State plumbing ──────────────────────────────────────
			 * definitionFor and holderFor are the only two places that
			 * know how state is nested. Everything else goes through
			 * them.
			 */

			initialDevices: function () {
				var map = {};
				var schema = this.schema;
				Object.keys( schema ).forEach( function ( key ) {
					if ( schema[ key ].responsive ) { map[ key ] = 'desktop'; }
				} );
				return map;
			},

			isChart: function () {
				return 'chart' === this.context;
			},

			/** Everything already stored counts as overridden on load. */
			initialOverrides: function () {
				var map = {};
				var self = this;
				Object.keys( this.schema ).forEach( function ( key ) {
					if ( self.hasValue( key ) ) { map[ key ] = true; }
				} );
				return map;
			},

			isOverridden: function ( key ) {
				if ( ! this.isChart() ) { return true; }
				return !! this.overridden[ key ] || this.hasValue( key );
			},

			/**
			 * Take a control off inherit, seeded with the value it was
			 * inheriting, so the user starts from what they could see
			 * rather than from blank.
			 */
			override: function ( key ) {
				var definition = this.schema[ key ];
				if ( ! definition ) { return; }

				this.values[ key ] = shapeControl( definition, ( seed.inherited || {} )[ key ] );
				this.overridden[ key ] = true;
			},

			/** Drop the override and let the global show through again. */
			revert: function ( key ) {
				this.resetControl( key );
				delete this.overridden[ key ];
			},

			/** What an inherited control is inheriting, for the grey row. */
			inheritedLabel: function ( key ) {
				var definition = this.schema[ key ];
				if ( ! definition ) { return ''; }

				var token = controlToken( definition, ( seed.inherited || {} )[ key ] );
				if ( token ) { return token; }

				/*
				 * Nothing in the global layer, so this control falls all
				 * the way through to the stylesheet. Showing the value the
				 * stylesheet renders is the difference between reading
				 * blank as "nothing" and reading it as "1.5px, unless I
				 * say otherwise".
				 */
				return definition.placeholder
					? definition.placeholder
					: ( this.strings.stylesheet || 'the plugin default' );
			},

			/* ── Section and whole-set resets ────────────────────────
			 * The same two buttons on both screens, meaning slightly
			 * different things: on the global page a reset clears back to
			 * the stylesheet, on a chart it clears back to the global
			 * defaults. Both are "stop saying anything here", which is
			 * why they are one implementation.
			 */

			sectionHasValues: function ( section ) {
				var self = this;
				return Object.keys( this.schema ).some( function ( key ) {
					return self.schema[ key ].section === section &&
						( self.isChart() ? self.isOverridden( key ) : self.hasValue( key ) );
				} );
			},

			resetSection: function ( section ) {
				var self = this;
				Object.keys( this.schema ).forEach( function ( key ) {
					if ( self.schema[ key ].section !== section ) { return; }
					self.isChart() ? self.revert( key ) : self.resetControl( key );
				} );
			},

			anyValues: function () {
				var self = this;
				return Object.keys( this.schema ).some( function ( key ) {
					return self.isChart() ? self.isOverridden( key ) : self.hasValue( key );
				} );
			},

			/**
			 * Clear everything. Confirmed first: it is the one action on
			 * either screen that another click cannot undo.
			 *
			 * On the global page this stores nothing rather than storing a
			 * copy of the defaults, because an empty stored set IS the
			 * plugin defaults, because the resolver emits nothing and the
			 * stylesheet decides. That also means a later change to a
			 * default in the stylesheet reaches a site that has been
			 * reset, which storing a snapshot would prevent.
			 */
			resetAll: function () {
				var message = this.isChart()
					? ( this.strings.confirmChart || 'Drop every style override on this chart?' )
					: ( this.strings.confirmGlobal || 'Reset every global style?' );

				if ( ! window.confirm( message ) ) { return; }

				this.values = shapeAll( this.schema, {} );
				this.overridden = {};
				this.discarded = [];
			},

			holderFor: function ( key, device ) {
				var container = this.values[ key ];
				if ( undefined === container ) { return null; }
				return device ? [ container, device ] : [ this.values, key ];
			},

			leaf: function ( key, device ) {
				var holder = this.holderFor( key, device );
				return holder ? holder[ 0 ][ holder[ 1 ] ] : undefined;
			},

			setLeaf: function ( key, device, value ) {
				var holder = this.holderFor( key, device );
				if ( holder ) { holder[ 0 ][ holder[ 1 ] ] = value; }
			},

			/** Whether this breakpoint, or this flat control, holds anything. */
			hasDeviceValue: function ( key, device ) {
				var definition = this.schema[ key ];
				if ( ! definition ) { return false; }
				return ! leafIsEmpty( definition, this.leaf( key, device ) );
			},

			/** Whether the control holds anything at any breakpoint. */
			hasValue: function ( key ) {
				var definition = this.schema[ key ];
				if ( ! definition ) { return false; }
				return undefined !== collapseControl( definition, this.values[ key ] );
			},

			/** Clear one breakpoint, or a flat control. */
			clearLeaf: function ( key, device ) {
				var definition = this.schema[ key ];
				if ( ! definition ) { return; }
				this.setLeaf( key, device, emptyLeaf( definition ) );
			},

			/** Clear the control at every breakpoint, back to inherit. */
			resetControl: function ( key ) {
				var definition = this.schema[ key ];
				if ( ! definition ) { return; }
				this.values[ key ] = shapeControl( definition, null );
			},

			/* ── Type-specific bindings ──────────────────────────────── */

			colourSwatch: function ( key, device ) {
				return toSwatch( this.leaf( key, device ) );
			},

			/** Where an unset range parks its thumb: at its minimum. */
			sliderPosition: function ( key, device, min ) {
				var value = this.leaf( key, device );
				var size = ( value && 'object' === typeof value ) ? value.size : value;
				return ( '' === size || null === size || undefined === size ) ? min : size;
			},

			setSize: function ( key, device, size ) {
				var value = this.leaf( key, device );
				if ( value && 'object' === typeof value ) { value.size = size; }
			},

			isLinked: function ( key, device ) {
				var value = this.leaf( key, device );
				return !! ( value && 'object' === typeof value && value.linked );
			},

			toggleLink: function ( key, device ) {
				var value = this.leaf( key, device );
				if ( ! value || 'object' !== typeof value ) { return; }
				value.linked = ! value.linked;
				if ( value.linked ) { this.syncLinked( key, device, value.top ); }
			},

			/**
			 * Copy the edited side across when the sides are linked.
			 *
			 * The value comes from the event target rather than from
			 * state, so this does not depend on whether Alpine's own
			 * x-model listener has run first.
			 */
			syncLinked: function ( key, device, edited ) {
				var value = this.leaf( key, device );
				if ( ! value || 'object' !== typeof value || ! value.linked ) { return; }
				SIDES.forEach( function ( side ) { value[ side ] = edited; } );
			},

			/* ── Presets ─────────────────────────────────────────────── */

			/**
			 * Download the SAVED styles as a preset.
			 *
			 * Fetched from the server rather than serialised from the
			 * form, so what lands in the file is what the site actually
			 * renders with. If there are unsaved edits the user is told,
			 * rather than having them silently folded in or silently left
			 * out.
			 */
			exportPreset: function () {
				var self = this;

				if ( this.dirty ) {
					this.status = this.strings.exportDirty || 'Save first.';
					this.statusClass = 'is-warning';
					return;
				}

				var url = seed.exportUrl + ( this.isChart() ? '?id=' + seed.chartId : '' );

				window.fetch( url, {
					method: 'GET',
					credentials: 'same-origin',
					headers: { 'X-WP-Nonce': seed.nonce }
				} ).then( function ( response ) {
					return response.json();
				} ).then( function ( preset ) {
					var blob = new window.Blob(
						[ JSON.stringify( preset, null, '\t' ) ],
						{ type: 'application/json' }
					);
					var objectUrl = window.URL.createObjectURL( blob );
					var link = document.createElement( 'a' );
					link.href = objectUrl;
					link.download = self.isChart()
						? 'kdna-chart-' + seed.chartId + '-styles.json'
						: 'kdna-charts-styles.json';
					document.body.appendChild( link );
					link.click();
					document.body.removeChild( link );
					// Revoking immediately can cancel the download in some
					// browsers, so it waits for the click to be acted on.
					window.setTimeout( function () { window.URL.revokeObjectURL( objectUrl ); }, 1000 );

					self.status = self.strings.exported || 'Preset downloaded';
					self.statusClass = 'is-ok';
				} ).catch( function () {
					self.status = self.strings.failed || 'Could not save';
					self.statusClass = 'is-error';
				} );
			},

			/** Read a chosen file into the textarea, so both paths are one. */
			readPresetFile: function ( event ) {
				var file = event.target.files && event.target.files[ 0 ];
				if ( ! file ) { return; }

				var self = this;
				var reader = new window.FileReader();
				reader.onload = function () {
					self.importText = String( reader.result || '' );
				};
				reader.readAsText( file );
			},

			importPreset: function () {
				if ( this.importing || ! this.importText.trim() ) { return; }
				if ( ! window.confirm( this.strings.importConfirm || 'Replace every global style?' ) ) { return; }

				var self = this;
				this.importing = true;
				this.discarded = [];
				this.status = this.strings.importing || 'Importing…';
				this.statusClass = '';

				window.fetch( seed.importUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': seed.nonce
					},
					body: JSON.stringify( { preset: this.importText } )
				} ).then( function ( response ) {
					return response.json().then( function ( body ) {
						return { ok: response.ok, body: body };
					} );
				} ).then( function ( result ) {
					self.importing = false;

					if ( ! result.ok || ! result.body || ! result.body.saved ) {
						self.status = ( result.body && result.body.message )
							? result.body.message
							: ( self.strings.importFailed || 'Could not import that preset.' );
						self.statusClass = 'is-error';
						return;
					}

					// Re-seed from what was stored, exactly as save() does,
					// so the form shows the result of the import rather
					// than the file's contents.
					self.reseed( result.body.values || {} );

					self.discarded = result.body.discarded || [];
					self.importOpen = self.discarded.length > 0;
					self.importText = '';

					self.status = ( self.strings.imported || 'Imported' ) +
						', ' + result.body.imported + '/' + result.body.offered;
					self.statusClass = self.discarded.length ? 'is-warning' : 'is-ok';
				} ).catch( function () {
					self.importing = false;
					self.status = self.strings.importFailed || 'Could not import that preset.';
					self.statusClass = 'is-error';
				} );
			},

			/* ── Saving ──────────────────────────────────────────────── */

			reseed: function ( stored ) {
				this.values = shapeAll( this.schema, stored );
				this._baseline = JSON.stringify( collapseAll( this.schema, this.values ) );
				this.dirty = false;
				this.overridden = this.initialOverrides();
				this.paintPreview();
			},

			save: function () {
				if ( this.saving ) { return; }

				var payload = collapseAll( this.schema, this.values );
				var sent = JSON.stringify( payload );
				var self = this;

				this.saving = true;
				this.status = this.strings.saving || 'Saving…';
				this.statusClass = '';

				window.fetch( seed.restUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': seed.nonce
					},
					body: JSON.stringify( { values: payload } )
				} ).then( function ( response ) {
					return response.json().then( function ( body ) {
						return { ok: response.ok, body: body };
					} );
				} ).then( function ( result ) {
					self.saving = false;

					if ( ! result.ok || ! result.body || ! result.body.saved ) {
						self.status = ( result.body && result.body.message )
							? result.body.message
							: ( self.strings.failed || 'Could not save' );
						self.statusClass = 'is-error';
						return;
					}

					// Re-seed from what was actually stored rather than
					// from what was typed, so anything the sanitiser
					// rejected disappears from the form instead of sitting
					// there looking saved.
					var stored = result.body.values || {};
					self.reseed( stored );

					var kept = JSON.stringify( stored );
					self.status = ( kept === sent )
						? ( self.strings.saved || 'Saved' )
						: ( self.strings.discarded || 'Some values were not valid and were discarded.' );
					self.statusClass = ( kept === sent ) ? 'is-ok' : 'is-warning';
				} ).catch( function () {
					self.saving = false;
					self.status = self.strings.failed || 'Could not save';
					self.statusClass = 'is-error';
				} );
			}
		};
	}

	document.addEventListener( 'alpine:init', function () {
		window.Alpine.data( 'kdnaChartsStyleAdmin', kdnaChartsStyleAdmin );
	} );

	window.kdnaChartsStyleAdmin = kdnaChartsStyleAdmin;

	/* ==================================================================
	 * Alpine, loaded by us rather than beside us
	 *
	 * The documented way to pin script order is a dependency list, and
	 * it holds right up until something on the site reorders, defers,
	 * delays or combines admin scripts. When Alpine wins that race it
	 * walks the DOM before this file has registered anything, every
	 * x-data expression throws, and the page renders as an inert
	 * skeleton with a Save button on it.
	 *
	 * Injecting Alpine from here removes the race rather than pinning
	 * it. Registration above has already happened by the time this line
	 * runs, so alpine:init cannot be missed. If this file does not load
	 * at all then neither does Alpine, which is the honest outcome: the
	 * boot warning stays up and nothing half-initialises.
	 * ================================================================ */
	function bootAlpine( url ) {
		if ( ! url || window.__kdnaAlpineBooting ) { return; }
		window.__kdnaAlpineBooting = true;

		/*
		 * Deferred to DOMContentLoaded rather than fired here, and that
		 * is the whole point of it.
		 *
		 * On a chart edit screen TWO of this plugin's scripts want
		 * Alpine: the chart editor and this panel. Injecting immediately
		 * means whichever executes first starts Alpine, and an injected
		 * script can execute in the gap between two classic ones, so
		 * Alpine could still walk the DOM before the second script had
		 * registered its component. That is the same race in a new
		 * costume.
		 *
		 * By DOMContentLoaded every classic script in the document has
		 * executed, so every component is registered before Alpine has
		 * any chance to look for one. The flag above makes it idempotent,
		 * so it does not matter which of the two gets here first.
		 */
		var inject = function () {
			if ( window.Alpine ) { return; }
			var el = document.createElement( 'script' );
			el.src = url;
			( document.head || document.documentElement ).appendChild( el );
		};

		if ( 'loading' === document.readyState ) {
			document.addEventListener( 'DOMContentLoaded', inject );
		} else {
			inject();
		}
	}

	bootAlpine( ( window.KDNAChartsStyles || {} ).alpineUrl );

	// Exposed for the parity tests, which run resolveProperties against
	// the PHP resolver over the same value sets.
	window.kdnaChartsStyleInternals = {
		shapeAll: shapeAll,
		collapseAll: collapseAll,
		shapeControl: shapeControl,
		collapseControl: collapseControl,
		toSwatch: toSwatch,
		leafToken: leafToken,
		controlToken: controlToken,
		resolveProperties: resolveProperties,
		asDeviceMap: asDeviceMap,
		sanitizeCssValue: sanitizeCssValue
	};
}() );
