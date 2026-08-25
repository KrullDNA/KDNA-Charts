<?php
/**
 * Chart.js rendering engine (Stage 12).
 *
 * The opt-in second engine, for the jobs the SVG engine is genuinely poor
 * at: datasets beyond a couple of hundred points, cursor-following
 * tooltips, and clickable legends that toggle series. It honours the shared
 * style controls but NOT the editorial annotation layer — markers, callouts
 * and notes are SVG-only, which is why the admin greys those sections out
 * for a Chart.js chart rather than pretending to apply them.
 *
 * It extends the shared renderer base, so the figure, caption, source line,
 * accessible name and description, the screen-reader data table and the
 * unique-id handling are all the base's, exactly as for the SVG engine.
 * This class supplies only what is particular to canvas: the type map, the
 * data translation, and the styled config.
 *
 * ── How the styling reaches the canvas ────────────────────────────────
 *
 * A canvas cannot read CSS custom properties, so the SVG engine's approach
 * — every mark reading a var() — does not translate. Instead this renderer
 * resolves the same style values in PHP (the merged global + per-chart set
 * the SVG engine would have consumed) and bakes them into the Chart.js
 * config: colours, widths, fonts, gridlines. The data comes from the same
 * stored definition the SVG engine reads, so switching engine changes how
 * the chart is drawn, never what it shows.
 *
 * ── One config per canvas, via wp_localize_script ─────────────────────
 *
 * Each instance localises its config to a uniquely named JS global and
 * names that global on the canvas's data attribute; kdna-chartjs.js reads
 * it back and constructs the chart. Per-instance globals rather than one
 * shared object means several charts on a page never overwrite each
 * other's config, and the localise attaches to the footer script that has
 * not printed yet.
 *
 * ── Conditional assets ────────────────────────────────────────────────
 *
 * Chart.js and this initialiser are registered on wp_enqueue_scripts but
 * enqueued only inside render_chart(), which runs only when a Chart.js
 * chart is actually on the page. A site using only SVG charts never
 * downloads the library.
 *
 * @package KDNA_Charts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KDNA_Charts_Renderer_ChartJS extends KDNA_Charts_Renderer {

	/** Bundled Chart.js library handle. */
	const LIB_HANDLE = 'kdna-chartjs-lib';

	/** Initialiser script handle. */
	const SCRIPT_HANDLE = 'kdna-chartjs';

	/** Chart types this engine can draw. Stat blocks stay typographic. */
	const SUPPORTED = array( 'line', 'area', 'bar', 'column', 'pie', 'donut' );

	/** Per-request instance counter, for unique canvas ids and config globals. */
	private static $counter = 0;

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	/**
	 * Register (not enqueue) the library and initialiser. render_chart()
	 * enqueues them on demand, so a page with no Chart.js chart never loads
	 * the library.
	 */
	public static function register_assets() {
		if ( ! wp_script_is( self::LIB_HANDLE, 'registered' ) ) {
			wp_register_script(
				self::LIB_HANDLE,
				KDNA_CHARTS_URL . 'assets/js/chart.umd.min.js',
				array(),
				'4.5.1',
				true
			);
		}
		if ( ! wp_script_is( self::SCRIPT_HANDLE, 'registered' ) ) {
			wp_register_script(
				self::SCRIPT_HANDLE,
				KDNA_CHARTS_URL . 'assets/js/kdna-chartjs.js',
				array( self::LIB_HANDLE ),
				KDNA_CHARTS_VERSION,
				true
			);
		}
	}

	/*
	 * ====================================================================
	 * The engine's contract
	 * ====================================================================
	 */

	public function engine() {
		return 'chartjs';
	}

	public function supports() {
		return array(
			// The editorial vocabulary is the SVG engine's, not this one's.
			'annotations'   => false,
			'segments'      => false,
			'gradient_fill' => false,
			'tick_emphasis' => false,
			'no_javascript' => false,
			// The things canvas is genuinely better at.
			'tooltips'       => true,
			'legend_toggle'  => true,
			'large_datasets' => true,
		);
	}

	public function supports_type( $type ) {
		return in_array( (string) $type, self::SUPPORTED, true );
	}

	/*
	 * ====================================================================
	 * The chart
	 * ====================================================================
	 */

	/**
	 * The canvas and its localised config. The base wraps this in the
	 * figure, adds the caption, source line and screen-reader table, and
	 * has already refused a type this engine cannot draw or a chart with no
	 * data, so this only runs when there is something to draw.
	 */
	protected function render_chart() {
		$type = $this->type();

		// Resolve the same style values the SVG figure would paint, including
		// the Elementor widget's 4th-layer overrides (args['style']) so the
		// widget's Style tab reaches the canvas too, not only the SVG engine.
		$extra = ( isset( $this->args['style'] ) && is_array( $this->args['style'] ) ) ? $this->args['style'] : array();
		$props = KDNA_Charts_Style_Resolver::resolve( $this->chart_id, $extra );

		$config = $this->build_config( $type, $props, $this->wants_animation() );

		if ( empty( $config['data']['datasets'] ) ) {
			// No usable datasets after translation. Returning '' lets the
			// figure render empty rather than a broken canvas; in practice
			// has_data() has already screened this out.
			return '';
		}

		self::register_assets();
		wp_enqueue_script( self::LIB_HANDLE );
		wp_enqueue_script( self::SCRIPT_HANDLE );

		self::$counter++;
		$uid       = ( $this->chart_id > 0 ? $this->chart_id : 'x' ) . '_' . self::$counter;
		$canvas_id = $this->id( 'canvas-' . self::$counter );
		$var       = 'kdnaChartsCfg_' . preg_replace( '/[^A-Za-z0-9]/', '', $uid );

		// One config per canvas, handed to JS as data rather than read from
		// the DOM. Attaches to the not-yet-printed footer script.
		wp_localize_script( self::SCRIPT_HANDLE, $var, $config );

		$canvas = self::tag(
			'canvas',
			array(
				'id'               => $canvas_id,
				'class'            => self::css( 'canvas' ),
				'data-kdna-chartjs' => $var,
				'role'             => 'img',
				'aria-label'       => $this->accessible_title(),
			),
			// A canvas is not a void element; give it a closing tag with a
			// text fallback for a browser that cannot draw it.
			esc_html( $this->accessible_description() )
		);

		return self::tag(
			'div',
			array( 'class' => self::css( 'plot', array( 'chartjs' ) ) ),
			$canvas
		);
	}

	/**
	 * Whether this render should animate. The shortcode and the widget pass
	 * their animate flag through as the data-animate attribute; honour it so
	 * a Chart.js chart draws in on the same terms as an SVG one.
	 */
	private function wants_animation() {
		if ( ! empty( $this->args['animate'] ) ) {
			return true;
		}
		if ( isset( $this->args['atts']['data-animate'] ) ) {
			return 'yes' === $this->args['atts']['data-animate'];
		}
		return false;
	}

	/* ─── Config building ───────────────────────────────────────────── */

	private function build_config( $type, array $props, $animate ) {
		$options = isset( $this->definition['options'] ) && is_array( $this->definition['options'] )
			? $this->definition['options']
			: array();

		$config = array(
			'type'    => self::chart_type( $type ),
			'data'    => $this->build_data( $type, $props ),
			'options' => $this->build_options( $type, $props, $options, $animate ),
		);

		/**
		 * Filter the Chart.js config before it is localised.
		 *
		 * @param array $config The config array.
		 * @param array $def    The chart definition.
		 */
		return apply_filters( 'kdna_charts_chartjs_config', $config, $this->definition );
	}

	/** Map a KDNA chart type to a Chart.js type. */
	private static function chart_type( $type ) {
		switch ( $type ) {
			case 'area':
			case 'line':
				return 'line';
			case 'bar':
			case 'column':
				return 'bar';
			case 'donut':
				return 'doughnut';
			case 'pie':
				return 'pie';
		}
		return 'line';
	}

	/** The data block: labels and datasets, styled from $props. */
	private function build_data( $type, array $props ) {
		if ( in_array( $type, array( 'pie', 'donut' ), true ) ) {
			return $this->build_pie_data( $props );
		}
		if ( in_array( $type, array( 'bar', 'column' ), true ) ) {
			return $this->build_category_data( $props );
		}
		return $this->build_line_data( $type, $props );
	}

	/**
	 * Line and area: one dataset per series, points concatenated across
	 * segments on a linear x scale. Reuses the base's series_points(), which
	 * de-duplicates the shared endpoint where two segments join.
	 */
	private function build_line_data( $type, array $props ) {
		$options   = isset( $this->definition['options'] ) && is_array( $this->definition['options'] ) ? $this->definition['options'] : array();
		$is_area   = ( 'area' === $type ) || ! empty( $options['area_fill'] );
		$tension   = ( isset( $options['curve'] ) && in_array( $options['curve'], array( 'smooth', 'monotone' ), true ) ) ? 0.4 : 0;

		$width    = self::px( $props, '--kdna-chart-line-width-normal', 2.5 );
		$p_radius = self::px( $props, '--kdna-chart-point-radius', 9 );
		$p_fill   = self::sv( $props, '--kdna-chart-point-colour', '#14332c' );
		$p_stroke = self::sv( $props, '--kdna-chart-point-fill-hollow', '#ffffff' );
		$p_width  = self::px( $props, '--kdna-chart-point-stroke-width', 3.5 );
		$area_bg  = self::rgba( self::sv( $props, '--kdna-chart-area-colour-normal', '#4a5f58' ), 0.16 );

		$palette  = $this->palette( $props );
		$datasets = array();

		foreach ( array_values( $this->series() ) as $i => $series ) {
			$points = $this->series_points( $series );
			if ( empty( $points ) ) {
				continue;
			}
			$data = array();
			foreach ( $points as $pt ) {
				if ( is_array( $pt ) && array_key_exists( 0, $pt ) && array_key_exists( 1, $pt ) && null !== $pt[1] ) {
					$data[] = array( 'x' => 0 + $pt[0], 'y' => 0 + $pt[1] );
				}
			}
			if ( empty( $data ) ) {
				continue;
			}

			$colour     = $palette[ $i % count( $palette ) ];
			$datasets[] = array(
				'label'                => $this->series_label( $series, $i ),
				'data'                 => $data,
				'borderColor'          => $colour,
				'backgroundColor'      => $is_area ? $area_bg : $colour,
				'borderWidth'          => $width,
				'tension'              => $tension,
				'fill'                 => $is_area ? 'origin' : false,
				'pointRadius'          => $p_radius,
				'pointHoverRadius'     => $p_radius + 2,
				'pointBackgroundColor' => $p_fill,
				'pointBorderColor'     => $p_stroke,
				'pointBorderWidth'     => $p_width,
			);
		}

		return array( 'datasets' => $datasets );
	}

	/**
	 * Bar and column: category labels with a value per series, read from the
	 * series' categorical `data` array of { label, value }.
	 */
	private function build_category_data( array $props ) {
		$palette  = $this->palette( $props );
		$labels   = array();
		$datasets = array();

		foreach ( array_values( $this->series() ) as $i => $series ) {
			$data   = isset( $series['data'] ) && is_array( $series['data'] ) ? array_values( $series['data'] ) : array();
			$values = array();
			foreach ( $data as $datum ) {
				if ( ! is_array( $datum ) || ! isset( $datum['value'] ) || ! is_numeric( $datum['value'] ) ) {
					continue;
				}
				if ( 0 === $i ) {
					$labels[] = isset( $datum['label'] ) ? (string) $datum['label'] : '';
				}
				$values[] = 0 + $datum['value'];
			}
			if ( empty( $values ) ) {
				continue;
			}

			$colour = $palette[ $i % count( $palette ) ];
			// One colour per series when there are several; the palette
			// across the bars when there is a single series.
			$bar_colours = ( count( $this->series() ) > 1 )
				? $colour
				: self::cycle( $palette, count( $values ) );

			$datasets[] = array(
				'label'           => $this->series_label( $series, $i ),
				'data'            => $values,
				'backgroundColor' => $bar_colours,
				'borderColor'     => self::sv( $props, '--kdna-chart-bar-stroke-colour', $colour ),
				'borderWidth'     => self::px( $props, '--kdna-chart-bar-stroke-width', 0 ),
			);
		}

		return array( 'labels' => $labels, 'datasets' => $datasets );
	}

	/**
	 * Pie and donut: one dataset of slices from the first series'
	 * categorical data, one palette colour each.
	 */
	private function build_pie_data( array $props ) {
		$series = $this->series();
		$first  = ! empty( $series ) ? reset( $series ) : array();
		$data   = isset( $first['data'] ) && is_array( $first['data'] ) ? array_values( $first['data'] ) : array();

		$labels = array();
		$values = array();
		foreach ( $data as $datum ) {
			if ( ! is_array( $datum ) || ! isset( $datum['value'] ) || ! is_numeric( $datum['value'] ) ) {
				continue;
			}
			$labels[] = isset( $datum['label'] ) ? (string) $datum['label'] : '';
			$values[] = 0 + $datum['value'];
		}

		if ( empty( $values ) ) {
			return array( 'labels' => array(), 'datasets' => array() );
		}

		return array(
			'labels'   => $labels,
			'datasets' => array(
				array(
					'data'            => $values,
					'backgroundColor' => self::cycle( $this->palette( $props ), count( $values ) ),
					'borderColor'     => self::sv( $props, '--kdna-chart-background', '#ffffff' ),
					'borderWidth'     => 2,
				),
			),
		);
	}

	/** The Chart.js options block, styled from $props. */
	private function build_options( $type, array $props, array $options, $animate ) {
		$font_family  = self::sv( $props, '--kdna-chart-font-family', 'Montserrat, Helvetica, Arial, sans-serif' );
		$axis_size    = self::px( $props, '--kdna-chart-axis-label-size', 18 );
		$legend_size  = self::px( $props, '--kdna-chart-legend-label-size', 20 );
		$legend_colour = self::sv( $props, '--kdna-chart-legend-label-colour', '#4a5f58' );

		$out = array(
			'responsive'          => true,
			'maintainAspectRatio' => true,
			'aspectRatio'         => self::aspect_ratio( $options ),
			'font'                => array(
				'family' => $font_family,
				'size'   => $axis_size,
			),
			'plugins'             => array(
				// Chart.js's default legend onClick already toggles a series
				// or slice, so a clickable legend needs no extra wiring.
				'legend'  => array(
					'display'  => true,
					'position' => in_array( $type, array( 'pie', 'donut' ), true ) ? 'right' : 'bottom',
					'labels'   => array(
						'color'         => $legend_colour,
						'font'          => array( 'family' => $font_family, 'size' => $legend_size ),
						'usePointStyle' => true,
					),
				),
				'tooltip' => array(
					'enabled'   => true,
					'titleFont' => array( 'family' => $font_family ),
					'bodyFont'  => array( 'family' => $font_family ),
				),
			),
		);

		// Chart.js wants false to disable animation and an object (or
		// nothing) to enable it, never true. Omit it when animating so the
		// library's own defaults apply.
		if ( ! $animate ) {
			$out['animation'] = false;
		}

		if ( ! in_array( $type, array( 'pie', 'donut' ), true ) ) {
			$out['scales'] = $this->build_scales( $type, $props, $font_family, $axis_size );
		}

		if ( 'donut' === $type ) {
			$inner = isset( $options['inner_radius'] ) && is_numeric( $options['inner_radius'] )
				? max( 0, min( 0.95, (float) $options['inner_radius'] ) )
				: 0.6;
			$out['cutout'] = round( $inner * 100 ) . '%';
		}
		if ( in_array( $type, array( 'pie', 'donut' ), true ) && isset( $options['start_angle'] ) && is_numeric( $options['start_angle'] ) ) {
			$out['rotation'] = 0 + $options['start_angle'];
		}
		if ( in_array( $type, array( 'bar', 'column' ), true ) ) {
			// 'bar' is horizontal in this plugin's vocabulary, 'column' vertical.
			$out['indexAxis'] = ( 'bar' === $type ) ? 'y' : 'x';
			if ( isset( $options['bar_radius'] ) && is_numeric( $options['bar_radius'] ) ) {
				$out['elements'] = array( 'bar' => array( 'borderRadius' => 0 + $options['bar_radius'] ) );
			}
		}

		return $out;
	}

	/** Cartesian scales for line, area, bar and column. */
	private function build_scales( $type, array $props, $font_family, $font_size ) {
		$grid_colour  = self::sv( $props, '--kdna-chart-gridline-colour', '#d5ddd9' );
		$tick_colour  = self::sv( $props, '--kdna-chart-axis-label-colour', '#4a5f58' );
		$title_colour = self::sv( $props, '--kdna-chart-axis-title-colour', '#6b7a75' );
		$title_size   = self::px( $props, '--kdna-chart-axis-title-size', 16 );

		$axes    = isset( $this->definition['axes'] ) && is_array( $this->definition['axes'] ) ? $this->definition['axes'] : array();
		$x_title = isset( $axes['x']['title'] ) ? (string) $axes['x']['title'] : '';
		$y_title = isset( $axes['y']['title'] ) ? (string) $axes['y']['title'] : '';

		// Line and area plot arbitrary x values, so their x axis is linear;
		// bar and column plot named categories.
		$x_is_linear = in_array( $type, array( 'line', 'area' ), true );

		$tick_font  = array( 'family' => $font_family, 'size' => $font_size );
		$title_font = array( 'family' => $font_family, 'size' => $title_size );

		$make = static function ( $title, $is_linear ) use ( $grid_colour, $tick_colour, $title_colour, $tick_font, $title_font ) {
			$scale = array(
				'grid'  => array( 'color' => $grid_colour ),
				'ticks' => array( 'color' => $tick_colour, 'font' => $tick_font ),
				'title' => array(
					'display' => ( '' !== $title ),
					'text'    => $title,
					'color'   => $title_colour,
					'font'    => $title_font,
				),
			);
			if ( $is_linear ) {
				$scale['type'] = 'linear';
			}
			return $scale;
		};

		return array(
			'x' => $make( $x_title, $x_is_linear ),
			'y' => $make( $y_title, false ),
		);
	}

	/* ─── Helpers ───────────────────────────────────────────────────── */

	private function series_label( $series, $index ) {
		$label = isset( $series['label'] ) ? trim( (string) $series['label'] ) : '';
		if ( '' !== $label ) {
			return $label;
		}
		/* translators: %d: series number */
		return sprintf( __( 'Series %d', 'kdna-charts' ), $index + 1 );
	}

	/**
	 * The categorical palette, resolved. Six slots, each with a default that
	 * matches the stylesheet's own fallback.
	 */
	private function palette( array $props ) {
		$defaults = array( '#14332c', '#4a5f58', '#6b7a75', '#9aa8a2', '#c2cdc8', '#8a635a' );
		$palette  = array();
		for ( $i = 1; $i <= 6; $i++ ) {
			$palette[] = self::sv( $props, '--kdna-chart-series-colour-' . $i, $defaults[ $i - 1 ] );
		}
		return $palette;
	}

	/** Cycle a list to exactly $n entries. */
	private static function cycle( array $list, $n ) {
		if ( empty( $list ) ) {
			return array();
		}
		$out = array();
		for ( $i = 0; $i < $n; $i++ ) {
			$out[] = $list[ $i % count( $list ) ];
		}
		return $out;
	}

	/** A resolved custom-property value, or a fallback. */
	private static function sv( array $props, $var, $fallback ) {
		return ( isset( $props[ $var ] ) && '' !== $props[ $var ] ) ? $props[ $var ] : $fallback;
	}

	/**
	 * A resolved length as a number, dropping its unit. Chart.js wants
	 * numbers for sizes and widths, not "4.5px".
	 */
	private static function px( array $props, $var, $fallback ) {
		if ( ! isset( $props[ $var ] ) || '' === $props[ $var ] ) {
			return $fallback;
		}
		if ( preg_match( '/-?\d+(?:\.\d+)?/', (string) $props[ $var ], $m ) ) {
			return 0 + $m[0];
		}
		return $fallback;
	}

	/**
	 * A hex colour as an rgba() string at a given alpha, for the area fill.
	 * A value that is not a plain hex is returned unchanged, so an already
	 * translucent colour is respected.
	 */
	private static function rgba( $colour, $alpha ) {
		$colour = trim( (string) $colour );
		if ( preg_match( '/^#([0-9a-fA-F]{6})$/', $colour, $m ) ) {
			$r = hexdec( substr( $m[1], 0, 2 ) );
			$g = hexdec( substr( $m[1], 2, 2 ) );
			$b = hexdec( substr( $m[1], 4, 2 ) );
			return sprintf( 'rgba(%d, %d, %d, %s)', $r, $g, $b, rtrim( rtrim( number_format( (float) $alpha, 3, '.', '' ), '0' ), '.' ) );
		}
		if ( preg_match( '/^#([0-9a-fA-F]{3})$/', $colour, $m ) ) {
			$r = hexdec( str_repeat( substr( $m[1], 0, 1 ), 2 ) );
			$g = hexdec( str_repeat( substr( $m[1], 1, 1 ), 2 ) );
			$b = hexdec( str_repeat( substr( $m[1], 2, 1 ), 2 ) );
			return sprintf( 'rgba(%d, %d, %d, %s)', $r, $g, $b, rtrim( rtrim( number_format( (float) $alpha, 3, '.', '' ), '0' ), '.' ) );
		}
		return $colour;
	}

	/** The aspect ratio number from an "16:9"-style option. */
	private static function aspect_ratio( array $options ) {
		$ratio = isset( $options['aspect_ratio'] ) ? (string) $options['aspect_ratio'] : '16:9';
		if ( preg_match( '/^(\d+(?:\.\d+)?)\s*:\s*(\d+(?:\.\d+)?)$/', $ratio, $m ) && (float) $m[2] > 0 ) {
			return round( (float) $m[1] / (float) $m[2], 4 );
		}
		return 1.7778;
	}
}
