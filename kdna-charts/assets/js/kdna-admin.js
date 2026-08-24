/**
 * The KDNA Charts editor.
 *
 * One Alpine component holding the whole chart definition, four tabs
 * over it, and a preview that asks the server to draw the thing after
 * every change.
 *
 * ── Why the preview is a round trip ────────────────────────────────
 *
 * The renderer is PHP. That is the point of the plugin: charts arrive
 * inside the page HTML and need no JavaScript to appear. So the editor
 * cannot draw its own preview without a second renderer written in
 * JavaScript, and two renderers means two answers to every question of
 * geometry, drifting apart one fix at a time.
 *
 * Instead the editor posts its state and gets back the same markup the
 * front end will serve. It costs a request per edit, debounced, and it
 * cannot lie about what the chart will look like.
 *
 * The state posted to the preview is the state that gets saved, and
 * both go through the importer's validator on the way in, so the
 * editor, an imported file and a hand written definition all come
 * through the same door.
 *
 * @package KDNA_Charts
 */

( function () {
	'use strict';

	var settings = window.KDNAChartsEditor || {};

	/** A short id for a new series, segment or annotation. */
	function uid( prefix ) {
		return prefix + '_' + Math.random().toString( 36 ).slice( 2, 9 );
	}

	/**
	 * Splits pasted text into a grid.
	 *
	 * Tabs first, because that is what a spreadsheet puts on the
	 * clipboard and a tab is never part of a value.
	 *
	 * Commas are the awkward case. A single column of figures with
	 * thousands separators looks exactly like two columns of CSV, and
	 * splitting "1,234" into 1 and 234 turns a paste of real data into
	 * nonsense. So a comma split is only accepted when the result is
	 * the shape of a table: every row the same width. Ragged rows mean
	 * the commas were inside the values, and the lines are taken whole.
	 */
	function parseGrid( text ) {
		var rows = String( text ).replace( /\r\n?/g, '\n' ).replace( /\n+$/, '' ).split( '\n' );

		var hasTabs = rows.some( function ( row ) {
			return row.indexOf( '\t' ) !== -1;
		} );
		if ( hasTabs ) {
			return rows.map( function ( row ) {
				return row.split( '\t' );
			} );
		}

		var split = rows.map( splitCsvRow );
		var width = split[ 0 ].length;
		var rectangular = split.every( function ( cells ) {
			return cells.length === width;
		} );

		if ( rectangular && width > 1 ) {
			return split;
		}

		return rows.map( function ( row ) {
			return [ row ];
		} );
	}

	/** A comma split that respects quotes, so "1,234" stays one cell. */
	function splitCsvRow( row ) {
		var cells = [];
		var cell = '';
		var quoted = false;
		var i;

		for ( i = 0; i < row.length; i++ ) {
			var ch = row[ i ];
			if ( '"' === ch ) {
				if ( quoted && '"' === row[ i + 1 ] ) {
					cell += '"';
					i++;
					continue;
				}
				quoted = ! quoted;
				continue;
			}
			if ( ',' === ch && ! quoted ) {
				cells.push( cell );
				cell = '';
				continue;
			}
			cell += ch;
		}
		cells.push( cell );
		return cells;
	}

	/**
	 * Reads a number out of a cell.
	 *
	 * Thousands separators and a trailing unit are stripped, because a
	 * figure copied out of a spreadsheet arrives as "1,234" or "42%"
	 * far more often than as 1234, and refusing those would make paste
	 * useless on exactly the data people have.
	 */
	function toNumber( value ) {
		if ( 'number' === typeof value ) {
			return isFinite( value ) ? value : null;
		}
		var text = String( value == null ? '' : value ).trim();
		if ( '' === text ) {
			return null;
		}
		text = text.replace( /[\s,]/g, '' ).replace( /[%£$€]/g, '' );
		var parsed = parseFloat( text );
		return isFinite( parsed ) ? parsed : null;
	}

	function clone( value ) {
		return JSON.parse( JSON.stringify( value ) );
	}

	window.kdnaChartEditor = function ( initial ) {
		return {
			/* ------------------------------------------------------------
			 * State
			 * --------------------------------------------------------- */

			postId: initial.post_id || 0,
			tab: 'data',
			chart: initial.chart || {},
			schema: settings.schema || {},

			preview: '',
			previewing: false,
			previewError: '',
			dirty: false,

			booted: false,
			pasteNotice: '',

			init: function () {
				var self = this;

				// Anything missing from a chart written before a key
				// existed is filled in here rather than guarded at every
				// point of use.
				this.chart.options = this.chart.options || {};
				this.chart.axes = this.chart.axes || { x: {}, y: {} };
				this.chart.axes.x = this.chart.axes.x || {};
				this.chart.axes.y = this.chart.axes.y || {};
				this.chart.series = this.chart.series || [];
				[ 'markers', 'points', 'callouts', 'notes' ].forEach( function ( key ) {
					self.chart[ key ] = self.chart[ key ] || [];
				} );

				if ( ! this.chart.series.length ) {
					this.addSeries();
				}

				this.booted = true;
				this.refresh();

				/*
				 * Every change to the definition redraws and marks the
				 * form dirty. Watching the whole object rather than each
				 * field means a key added later is watched without
				 * anybody remembering to add it here.
				 */
				this.$watch( 'chart', function () {
					self.dirty = true;
					self.queuePreview();
				} );
			},

			/* ------------------------------------------------------------
			 * What kind of chart this is
			 * --------------------------------------------------------- */

			get isPlotted() {
				return [ 'line', 'area' ].indexOf( this.chart.type ) !== -1;
			},

			get isCategorical() {
				return [ 'bar', 'column', 'pie', 'donut', 'stat' ].indexOf( this.chart.type ) !== -1;
			},

			get usesAxes() {
				return [ 'line', 'area', 'bar', 'column' ].indexOf( this.chart.type ) !== -1;
			},

			get drawsAnnotations() {
				return [ 'line', 'area', 'bar', 'column' ].indexOf( this.chart.type ) !== -1;
			},

			get optionSpec() {
				return ( this.schema.options || {} )[ this.chart.type ] || {};
			},

			get optionKeys() {
				return Object.keys( this.optionSpec );
			},

			/* ------------------------------------------------------------
			 * Series
			 * --------------------------------------------------------- */

			addSeries: function () {
				var series = {
					id: uid( 'series' ),
					label: settings.i18n.seriesNumber.replace( '%d', this.chart.series.length + 1 )
				};

				if ( this.isPlotted ) {
					series.segments = [ this.blankSegment() ];
				} else {
					series.data = this.categories().map( function ( name ) {
						return { label: name, value: 0 };
					} );
					if ( ! series.data.length ) {
						series.data = [ { label: '', value: 0 } ];
					}
				}

				this.chart.series.push( series );
			},

			removeSeries: function ( index ) {
				this.chart.series.splice( index, 1 );
				if ( ! this.chart.series.length ) {
					this.addSeries();
				}
			},

			blankSegment: function () {
				return { style: 'solid', emphasis: 'normal', points: [ [ 0, 0 ] ] };
			},

			/* ------------------------------------------------------------
			 * Plotted data: segments and points
			 * --------------------------------------------------------- */

			addSegment: function ( series ) {
				series.segments = series.segments || [];
				series.segments.push( this.blankSegment() );
			},

			removeSegment: function ( series, index ) {
				series.segments.splice( index, 1 );
				if ( ! series.segments.length ) {
					series.segments.push( this.blankSegment() );
				}
			},

			addPoint: function ( segment, after ) {
				var at = 'number' === typeof after ? after + 1 : segment.points.length;
				var previous = segment.points[ at - 1 ] || [ 0, 0 ];
				segment.points.splice( at, 0, [ toNumber( previous[ 0 ] ) + 1, 0 ] );
			},

			removePoint: function ( segment, index ) {
				segment.points.splice( index, 1 );
				if ( ! segment.points.length ) {
					segment.points.push( [ 0, 0 ] );
				}
			},

			/**
			 * Splits a segment in two at a point.
			 *
			 * This is how a line changes character partway along, and it
			 * is the reason segments exist at all. The point split on
			 * belongs to both halves, because two segments that share an
			 * endpoint join seamlessly and two that do not leave a gap.
			 */
			splitSegment: function ( series, segmentIndex, pointIndex ) {
				var segment = series.segments[ segmentIndex ];
				if ( pointIndex < 1 || pointIndex > segment.points.length - 2 ) {
					return;
				}

				var head = segment.points.slice( 0, pointIndex + 1 );
				var tail = segment.points.slice( pointIndex );

				var second = {
					style: segment.style,
					emphasis: segment.emphasis,
					points: tail
				};

				segment.points = head;
				series.segments.splice( segmentIndex + 1, 0, second );
			},

			/** True when this point can be split on: not either end. */
			canSplit: function ( segment, index ) {
				return index > 0 && index < segment.points.length - 1;
			},

			/**
			 * Joins a segment back onto the one before it, dropping the
			 * shared endpoint so the run of points is not doubled.
			 */
			mergeSegment: function ( series, index ) {
				if ( index < 1 ) {
					return;
				}
				var previous = series.segments[ index - 1 ];
				var current = series.segments[ index ];
				var points = current.points.slice();

				var last = previous.points[ previous.points.length - 1 ];
				var first = points[ 0 ];
				if ( last && first && toNumber( last[ 0 ] ) === toNumber( first[ 0 ] ) && toNumber( last[ 1 ] ) === toNumber( first[ 1 ] ) ) {
					points.shift();
				}

				previous.points = previous.points.concat( points );
				series.segments.splice( index, 1 );
			},

			/* ------------------------------------------------------------
			 * Categorical data: categories and values
			 * --------------------------------------------------------- */

			categories: function () {
				var declared = this.chart.axes.x.categories;
				if ( declared && declared.length ) {
					return declared;
				}
				var longest = [];
				this.chart.series.forEach( function ( series ) {
					if ( series.data && series.data.length > longest.length ) {
						longest = series.data;
					}
				} );
				return longest.map( function ( datum, index ) {
					return datum.label || String( index + 1 );
				} );
			},

			get rowCount() {
				var longest = 0;
				this.chart.series.forEach( function ( series ) {
					if ( series.data ) {
						longest = Math.max( longest, series.data.length );
					}
				} );
				return longest;
			},

			get rows() {
				var out = [];
				for ( var i = 0; i < this.rowCount; i++ ) {
					out.push( i );
				}
				return out;
			},

			/**
			 * The datum at a row and series, created on demand.
			 *
			 * Series can be ragged, so asking for a cell that does not
			 * exist yet fills the gap rather than failing. That is what
			 * lets a paste land anywhere in the grid.
			 */
			cell: function ( series, row ) {
				series.data = series.data || [];
				while ( series.data.length <= row ) {
					series.data.push( { label: '', value: null } );
				}
				return series.data[ row ];
			},

			/**
			 * @param {number|null} value What a new cell holds. Zero for
			 *   the Add row button, null for a row a paste is making
			 *   room in, since nothing has been put there yet.
			 */
			addRow: function ( value ) {
				var start = ( undefined === value ) ? 0 : value;
				this.chart.series.forEach( function ( series ) {
					series.data = series.data || [];
					series.data.push( { label: '', value: start } );
				} );
			},

			removeRow: function ( row ) {
				this.chart.series.forEach( function ( series ) {
					if ( series.data ) {
						series.data.splice( row, 1 );
					}
				} );
				if ( this.chart.axes.x.categories ) {
					this.chart.axes.x.categories.splice( row, 1 );
				}
			},

			/**
			 * The row label lives on every series so a chart stays
			 * readable if one is deleted, and on the axis so the
			 * category order is explicit. Writing it once writes it
			 * everywhere.
			 */
			rowLabel: function ( row ) {
				var first = this.chart.series[ 0 ];
				if ( first && first.data && first.data[ row ] ) {
					return first.data[ row ].label || '';
				}
				return '';
			},

			setRowLabel: function ( row, value ) {
				this.chart.series.forEach( function ( series ) {
					if ( series.data && series.data[ row ] ) {
						series.data[ row ].label = value;
					}
				} );
				this.chart.axes.x.categories = this.chart.series.length && this.chart.series[ 0 ].data
					? this.chart.series[ 0 ].data.map( function ( datum ) {
						return datum.label || '';
					} )
					: [];
			},

			/* ------------------------------------------------------------
			 * Paste
			 * --------------------------------------------------------- */

			/**
			 * Fills the grid from the clipboard, starting where the
			 * cursor is.
			 *
			 * A paste of one cell is left to the browser, because that
			 * is somebody correcting a typo and taking it over would be
			 * rude. Anything with more than one cell is a block of data
			 * and gets laid into the grid.
			 */
			pasteCategorical: function ( event, row, seriesIndex ) {
				var text = ( event.clipboardData || window.clipboardData ).getData( 'text' );
				var grid = parseGrid( text );

				if ( grid.length === 1 && grid[ 0 ].length === 1 ) {
					return;
				}
				event.preventDefault();

				var self = this;
				var filled = 0;

				grid.forEach( function ( cells, rowOffset ) {
					var target = row + rowOffset;
					while ( self.rowCount <= target ) {
						self.addRow( null );
					}
					cells.forEach( function ( raw, columnOffset ) {
						var index = seriesIndex + columnOffset;
						while ( self.chart.series.length <= index ) {
							self.addSeries();
						}
						var datum = self.cell( self.chart.series[ index ], target );
						var number = toNumber( raw );

						/*
						 * A pasted cell that is not a number blanks the
						 * cell rather than leaving what was there. The
						 * paste said what should be in it, and quietly
						 * keeping an old figure under a new label is how
						 * a chart ends up lying.
						 */
						datum.value = number;
						if ( null !== number ) {
							filled++;
						}
					} );
				} );

				this.pasted( filled, grid.length );
			},

			/**
			 * Paste into the label column: one column of names, which
			 * also becomes the category list.
			 */
			pasteLabels: function ( event, row ) {
				var text = ( event.clipboardData || window.clipboardData ).getData( 'text' );
				var grid = parseGrid( text );

				if ( grid.length === 1 && grid[ 0 ].length === 1 ) {
					return;
				}
				event.preventDefault();

				var self = this;
				grid.forEach( function ( cells, offset ) {
					var target = row + offset;
					while ( self.rowCount <= target ) {
						self.addRow( null );
					}
					self.setRowLabel( target, String( cells[ 0 ] ).trim() );

					// A pasted block that carries its values alongside
					// its names is laid in whole rather than having its
					// numbers thrown away.
					cells.slice( 1 ).forEach( function ( raw, columnOffset ) {
						while ( self.chart.series.length <= columnOffset ) {
							self.addSeries();
						}
						var number = toNumber( raw );
						if ( null !== number ) {
							self.cell( self.chart.series[ columnOffset ], target ).value = number;
						}
					} );
				} );

				this.pasted( grid.length, grid.length );
			},

			/**
			 * Paste into a point grid. Two columns, x and y.
			 */
			pastePoints: function ( event, segment, row, column ) {
				var text = ( event.clipboardData || window.clipboardData ).getData( 'text' );
				var grid = parseGrid( text );

				if ( grid.length === 1 && grid[ 0 ].length === 1 ) {
					return;
				}
				event.preventDefault();

				var filled = 0;

				grid.forEach( function ( cells, rowOffset ) {
					var target = row + rowOffset;
					while ( segment.points.length <= target ) {
						var previous = segment.points[ segment.points.length - 1 ] || [ 0, 0 ];
						segment.points.push( [ toNumber( previous[ 0 ] ) + 1, 0 ] );
					}
					cells.forEach( function ( raw, columnOffset ) {
						var axis = column + columnOffset;
						if ( axis > 1 ) {
							return;
						}
						var number = toNumber( raw );
						if ( null === number ) {
							return;
						}
						segment.points[ target ][ axis ] = number;
						filled++;
					} );
				} );

				this.pasted( filled, grid.length );
			},

			pasted: function ( cells, rows ) {
				var self = this;
				this.pasteNotice = settings.i18n.pasted
					.replace( '%1$d', cells )
					.replace( '%2$d', rows );
				window.setTimeout( function () {
					self.pasteNotice = '';
				}, 4000 );
			},

			/* ------------------------------------------------------------
			 * Annotations
			 * --------------------------------------------------------- */

			addMarker: function () {
				this.chart.markers.push( {
					type: 'vertical', x: 0, label: '', label_position: 'top', style: 'dashed'
				} );
			},

			addEmphasisedPoint: function () {
				this.chart.points.push( {
					x: 0, y: 0, style: 'filled', label: '', label_position: 'top'
				} );
			},

			addCallout: function () {
				this.chart.callouts.push( {
					value: '', caption: '', size: 'large',
					anchor: { x: 0, y: 0 }, leader: 'none'
				} );
			},

			addNote: function () {
				this.chart.notes.push( { text: '', at: { x: 0, y: 0 }, align: 'left' } );
			},

			remove: function ( list, index ) {
				this.chart[ list ].splice( index, 1 );
			},

			/** Turns a callout anchor between a point and a span. */
			toggleSpan: function ( callout ) {
				if ( this.isSpan( callout ) ) {
					callout.anchor = clone( callout.anchor.from );
					return;
				}
				var point = clone( callout.anchor );
				callout.anchor = { from: point, to: { x: toNumber( point.x ) + 1, y: point.y } };
			},

			isSpan: function ( callout ) {
				return !! ( callout.anchor && callout.anchor.from );
			},

			get annotationCount() {
				return this.chart.markers.length + this.chart.points.length
					+ this.chart.callouts.length + this.chart.notes.length;
			},

			/* ------------------------------------------------------------
			 * Preview
			 * --------------------------------------------------------- */

			queuePreview: function () {
				var self = this;
				window.clearTimeout( this._previewTimer );
				this._previewTimer = window.setTimeout( function () {
					self.refresh();
				}, 400 );
			},

			refresh: function () {
				var self = this;

				if ( ! this.booted ) {
					return;
				}

				/*
				 * One request at a time. A fast typist can outrun the
				 * server, and out of order replies would show a preview
				 * of a state that has already been edited past.
				 */
				if ( this._inFlight ) {
					this._inFlight.abort();
				}

				this.previewing = true;
				this.previewError = '';

				var body = new window.FormData();
				body.append( 'action', settings.previewAction );
				body.append( 'nonce', settings.nonce );
				body.append( 'post_id', this.postId );
				body.append( 'state', JSON.stringify( this.state() ) );

				var controller = window.AbortController ? new window.AbortController() : null;
				this._inFlight = controller;

				window.fetch( settings.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					body: body,
					signal: controller ? controller.signal : undefined
				} )
					.then( function ( response ) {
						return response.json();
					} )
					.then( function ( result ) {
						self._inFlight = null;
						self.previewing = false;
						if ( ! result || ! result.success ) {
							self.previewError = ( result && result.data && result.data.message )
								? result.data.message
								: settings.i18n.previewFailed;
							return;
						}
						self.preview = result.data.html;
						self.report = result.data.report || [];
					} )
					.catch( function ( error ) {
						if ( error && 'AbortError' === error.name ) {
							return;
						}
						self._inFlight = null;
						self.previewing = false;
						self.previewError = settings.i18n.previewFailed;
					} );
			},

			/**
			 * The definition as it will be saved.
			 *
			 * The post id travels with it. The server refuses a state
			 * carrying the wrong one, which is what stops a form that
			 * failed to seed itself from writing its empty defaults over
			 * a real chart.
			 */
			state: function () {
				var chart = clone( this.chart );

				// Keys the current type cannot use are kept rather than
				// stripped, so changing type and changing back does not
				// cost the author their work.
				return {
					post_id: this.postId,
					chart: chart
				};
			},

			/** Called just before the form submits. */
			serialise: function () {
				this.$refs.state.value = JSON.stringify( this.state() );
				this.dirty = false;
				return true;
			}
		};
	};

	/*
	 * A chart with unsaved changes should not be closed by accident.
	 * Bound at the document rather than inside the component so it
	 * survives Alpine re-rendering the form.
	 */
	window.addEventListener( 'beforeunload', function ( event ) {
		var form = document.querySelector( '[data-kdna-chart-editor]' );
		if ( ! form || 'true' !== form.getAttribute( 'data-dirty' ) ) {
			return;
		}
		event.preventDefault();
		event.returnValue = '';
	} );
}() );
