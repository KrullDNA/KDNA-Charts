<?php
/**
 * The abstract base every rendering engine extends.
 *
 * It owns everything the engines agree on: the figure that wraps a
 * chart, the caption and the source line, the accessible name and
 * description, the unique ids that let two charts share a page, and
 * the small markup builder that makes sure nothing reaches the browser
 * unescaped.
 *
 * What it deliberately does not own is any decision about how a chart
 * looks. Not one colour, stroke width or font size appears in the
 * markup either engine produces. Those live in the stylesheet, read
 * from CSS custom properties with a fallback, so the whole style
 * cascade at Stage 9 works by setting properties on a wrapper and
 * nothing has to re-render.
 *
 * @package KDNA_Charts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class KDNA_Charts_Renderer {

	/** The BEM block every class name is built from. */
	const CSS_BLOCK = 'kdna-chart';

	/**
	 * Bumped for every renderer built during a request, so two charts on
	 * one page cannot collide on a gradient id or an aria target.
	 *
	 * @var int
	 */
	private static $instances = 0;

	/** @var array The chart definition, interchange shape. */
	protected $definition;

	/** @var int Post ID, or 0 for a definition that is not stored. */
	protected $chart_id;

	/** @var array Render arguments from the caller. */
	protected $args;

	/** @var string Unique id stem for this render. */
	protected $uid;

	/** @var array Options, chart values merged over the schema defaults. */
	protected $options;

	/**
	 * @param array $definition Chart definition, interchange shape.
	 * @param array $args       Optional. Keys:
	 *                          chart_id      int    Post ID, for ids and classes.
	 *                          show_caption  bool   Default true.
	 *                          show_source   bool   Default true.
	 *                          classes       array  Extra wrapper classes.
	 *                          scale         array  Overrides passed to the scale.
	 */
	public function __construct( array $definition, array $args = array() ) {
		$this->definition = $definition;
		$this->args       = $args;
		$this->chart_id   = isset( $args['chart_id'] ) ? (int) $args['chart_id'] : 0;

		self::$instances++;
		$this->uid = 'kdna-chart-' . ( $this->chart_id > 0 ? $this->chart_id : 'x' ) . '-' . self::$instances;

		$type          = $this->type();
		$this->options = array_merge(
			KDNA_Charts_Schema::default_options( $type ),
			isset( $definition['options'] ) && is_array( $definition['options'] ) ? $definition['options'] : array()
		);
	}

	/**
	 * Builds the right renderer for a definition, or null when the
	 * engine it asks for is not available yet.
	 *
	 * @param array $definition Chart definition with its engine resolved.
	 * @param array $args       Render arguments.
	 * @return self|null
	 */
	public static function create( array $definition, array $args = array() ) {
		$engine = KDNA_Charts_Data::resolve_engine( $definition['engine'] ?? '' );

		if ( 'chartjs' === $engine ) {
			$file = KDNA_CHARTS_PATH . 'includes/class-kdna-charts-renderer-chartjs.php';
			if ( file_exists( $file ) ) {
				require_once $file;
				if ( class_exists( 'KDNA_Charts_Renderer_ChartJS' ) ) {
					$renderer = new KDNA_Charts_Renderer_ChartJS( $definition, $args );
					/*
					 * The Chart.js engine draws the six plotted types but not
					 * the typographic stat block. A type it cannot draw falls
					 * back to SVG rather than showing an error: the engine
					 * choice is the author's convenience, and an apologetic
					 * chart would be the reader's problem.
					 */
					if ( $renderer->supports_type( (string) ( $definition['type'] ?? '' ) ) ) {
						return $renderer;
					}
				}
			}
			/*
			 * If the Chart.js engine is unavailable or cannot draw this type,
			 * the chart renders in SVG rather than not at all, because a
			 * missing engine is the plugin's problem and a blank space on the
			 * page would be the reader's.
			 */
		}

		require_once KDNA_CHARTS_PATH . 'includes/class-kdna-charts-renderer-svg.php';
		return new KDNA_Charts_Renderer_SVG( $definition, $args );
	}

	/*
	 * ====================================================================
	 * What an engine has to say for itself
	 * ====================================================================
	 */

	/** The engine's slug, svg or chartjs. */
	abstract public function engine();

	/**
	 * What this engine can draw.
	 *
	 * Stage 12 reads this to grey out the admin controls an engine
	 * cannot honour, which is the difference between a control that
	 * does nothing and a control that says why.
	 *
	 * @return array<string,bool>
	 */
	abstract public function supports();

	/** True when this engine can draw a given chart type. */
	abstract public function supports_type( $type );

	/**
	 * The chart itself, without the figure, caption or source around it.
	 *
	 * @return string
	 */
	abstract protected function render_chart();

	/*
	 * ====================================================================
	 * The wrapper
	 * ====================================================================
	 */

	/**
	 * The complete chart: figure, chart, caption, source.
	 *
	 * @return string
	 */
	public function render() {
		$type = $this->type();

		if ( ! $this->supports_type( $type ) ) {
			return $this->placeholder(
				sprintf(
					/* translators: 1: chart type label, lower case, 2: engine name */
					__( 'A %1$s chart cannot be drawn by the %2$s engine yet.', 'kdna-charts' ),
					strtolower( KDNA_Charts_Schema::type_label( $type ) ),
					KDNA_Charts_Data::engine_label( $this->engine() )
				)
			);
		}

		if ( ! $this->has_data() ) {
			return $this->placeholder( __( 'This chart has no data yet.', 'kdna-charts' ) );
		}

		$parts = array( $this->render_chart() );

		if ( $this->show_caption() ) {
			$parts[] = $this->render_caption();
		}
		if ( $this->show_source() ) {
			$parts[] = $this->render_source();
		}

		/*
		 * The optional screen-reader data table (Stage 13). The slim data
		 * payload is emitted as a JSON script the front-end layer reads to
		 * build a visually hidden <table>. The server-side aria description
		 * already gives every reader the shape of the chart; this gives the
		 * reader who needs the figures the figures themselves.
		 */
		if ( ! empty( $this->args['a11y_table'] ) ) {
			$parts[] = $this->data_payload_script();
		}

		$attributes = array(
			'class' => $this->wrapper_classes(),
			'id'    => $this->uid,
			'style' => $this->wrapper_style(),
		);

		/*
		 * The front-end layer (Stage 13) hands its data attributes here —
		 * data-animate, data-thin-below, data-a11y-table — so the scroll-in
		 * animation, mobile label thinning and screen-reader table can find
		 * their chart. Set on the figure the renderer already owns, so the
		 * enhancement layer needs no wrapper of its own.
		 */
		if ( ! empty( $this->args['atts'] ) && is_array( $this->args['atts'] ) ) {
			foreach ( $this->args['atts'] as $name => $value ) {
				$attributes[ $name ] = $value;
			}
		}

		return self::tag(
			'figure',
			$attributes,
			implode( '', array_filter( $parts ) )
		);
	}

	/**
	 * The resolved style engine properties, as an inline style
	 * attribute value.
	 *
	 * ── Why the properties are written here ───────────────────────────
	 *
	 * A chart can be rendered from a shortcode inside a repeater field,
	 * or by an Elementor widget in a template, and neither is visible to
	 * has_shortcode(), which only reads post_content. So there is no
	 * reliable point at which a style block could be printed into the
	 * head knowing a chart is on the page. Writing the properties onto
	 * the wrapper at render time works wherever the chart lands, cannot
	 * arrive after the markup, and needs no page scanning.
	 *
	 * The style argument is the fourth layer of the cascade, and exists
	 * for the Elementor widget: its controls resolve to the same control
	 * keys and are merged on top of the global option and the chart's
	 * own overrides.
	 *
	 * Guarded on class_exists rather than assumed, because the renderer
	 * is exercised by tests that load it without the style engine, and a
	 * fatal there would be a test harness failing for a reason that has
	 * nothing to do with what it is testing.
	 */
	protected function wrapper_style() {
		if ( ! class_exists( 'KDNA_Charts_Style_Resolver' ) ) {
			return '';
		}

		$extra = ( isset( $this->args['style'] ) && is_array( $this->args['style'] ) )
			? $this->args['style']
			: array();

		/*
		 * The cached path is only correct when there is no extra layer.
		 * With one, the result is per render rather than per chart, and
		 * caching it under the chart's key would serve one widget's
		 * controls to every other chart on the site.
		 */
		if ( empty( $extra ) ) {
			return KDNA_Charts_Style_Resolver::style_attribute_for( $this->chart_id );
		}

		return KDNA_Charts_Style_Resolver::to_style_attribute(
			KDNA_Charts_Style_Resolver::resolve( $this->chart_id, $extra )
		);
	}

	protected function wrapper_classes() {
		$classes = array(
			self::CSS_BLOCK,
			self::CSS_BLOCK . '--' . $this->type(),
			self::CSS_BLOCK . '--engine-' . $this->engine(),
		);

		if ( ! empty( $this->args['classes'] ) && is_array( $this->args['classes'] ) ) {
			foreach ( $this->args['classes'] as $extra ) {
				$classes[] = sanitize_html_class( (string) $extra );
			}
		}

		return implode( ' ', array_filter( $classes ) );
	}

	protected function render_caption() {
		$caption = trim( (string) ( $this->definition['caption'] ?? '' ) );
		if ( '' === $caption ) {
			return '';
		}
		return $this->template(
			'render-caption.php',
			array(
				'caption' => $caption,
				'block'   => self::CSS_BLOCK,
			)
		);
	}

	protected function render_source() {
		$source = trim( (string) ( $this->definition['source'] ?? '' ) );
		if ( '' === $source ) {
			return '';
		}
		return $this->template(
			'render-source.php',
			array(
				'source' => $source,
				'block'  => self::CSS_BLOCK,
			)
		);
	}

	/**
	 * What shows when there is nothing to draw. Never an empty space:
	 * a chart that does not appear reads as a broken page, and a chart
	 * that says why reads as a chart waiting for data.
	 */
	protected function placeholder( $reason ) {
		return $this->template(
			'render-placeholder.php',
			array(
				'reason'  => (string) $reason,
				'title'   => (string) ( $this->definition['title'] ?? '' ),
				'block'   => self::CSS_BLOCK,
				'classes' => $this->wrapper_classes() . ' ' . self::CSS_BLOCK . '--placeholder',
				// The placeholder is still the chart's frame, so it takes
				// the chart's frame styling. A box that ignored the
				// site's background and padding would read as a broken
				// page rather than as a chart waiting for data.
				'style'   => $this->wrapper_style(),
			)
		);
	}

	/**
	 * Renders one of the plugin's own templates to a string.
	 *
	 * @param string $file Template filename inside templates/.
	 * @param array  $vars Variables the template expects.
	 * @return string
	 */
	protected function template( $file, array $vars ) {
		$path = KDNA_CHARTS_PATH . 'templates/' . $file;
		if ( ! file_exists( $path ) ) {
			return '';
		}
		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		extract( $vars, EXTR_SKIP );
		ob_start();
		include $path;
		return (string) ob_get_clean();
	}

	/*
	 * ====================================================================
	 * Accessibility
	 * ====================================================================
	 */

	/**
	 * The slim data payload the front-end layer (Stage 13) turns into a
	 * visually hidden table, as a JSON script inside the figure.
	 *
	 * The shape matches assets/js/kdna-charts.js: a `series` list of
	 * { label, points:[[x, y], …] } for plotted charts, one column per
	 * series in the reader's table. A caption and an x-axis heading travel
	 * with it so the table reads on its own.
	 *
	 * @return string
	 */
	protected function data_payload_script() {
		$series_out = array();

		foreach ( $this->series() as $index => $series ) {
			$points = $this->series_points( $series );
			if ( empty( $points ) ) {
				continue;
			}

			$label = trim( (string) ( $series['label'] ?? '' ) );
			if ( '' === $label ) {
				$label = sprintf(
					/* translators: %d: series number */
					__( 'Series %d', 'kdna-charts' ),
					$index + 1
				);
			}

			$series_out[] = array(
				'label'  => $label,
				'points' => array_map(
					static function ( $point ) {
						return array( $point[0], $point[1] );
					},
					$points
				),
			);
		}

		if ( empty( $series_out ) ) {
			return '';
		}

		$payload = array(
			'caption' => $this->accessible_title(),
			'x'       => $this->x_axis_heading(),
			'series'  => $series_out,
		);

		$json = wp_json_encode( $payload );
		if ( false === $json ) {
			return '';
		}

		return self::tag(
			'script',
			array(
				'type'  => 'application/json',
				'class' => self::CSS_BLOCK . '__data',
			),
			$json
		);
	}

	/**
	 * The x-axis heading for the data table, from the definition's axis
	 * title when present, else a plain label.
	 */
	protected function x_axis_heading() {
		$axes = isset( $this->definition['axes'] ) && is_array( $this->definition['axes'] ) ? $this->definition['axes'] : array();
		if ( isset( $axes['x']['title'] ) && '' !== trim( (string) $axes['x']['title'] ) ) {
			return trim( (string) $axes['x']['title'] );
		}
		return __( 'X', 'kdna-charts' );
	}

	/**
	 * The chart's accessible name. What a screen reader announces first,
	 * so it is the chart's own title and nothing else.
	 */
	protected function accessible_title() {
		$title = trim( (string) ( $this->definition['title'] ?? '' ) );
		if ( '' !== $title ) {
			return $title;
		}
		return sprintf(
			/* translators: %s: chart type label, lower case */
			__( 'A %s chart', 'kdna-charts' ),
			strtolower( KDNA_Charts_Schema::type_label( $this->type() ) )
		);
	}

	/**
	 * A sentence or two describing what the chart shows.
	 *
	 * Generated from the data rather than written by hand, because a
	 * description nobody has to remember to write is a description that
	 * is actually there. Stage 13 adds the optional full data table for
	 * readers who need the figures rather than the shape.
	 */
	protected function accessible_description() {
		$sentences = array();

		$sentences[] = sprintf(
			/* translators: %s: chart type label */
			__( '%s chart.', 'kdna-charts' ),
			KDNA_Charts_Schema::type_label( $this->type() )
		);

		$series_sentence = $this->describe_series();
		if ( '' !== $series_sentence ) {
			$sentences[] = $series_sentence;
		}

		/*
		 * The annotations are the argument the chart is making, so a
		 * reader who cannot see it should still get it. Without this the
		 * description says a line fell from 108 to 52 and never mentions
		 * that thirty per cent of it went in the first five years.
		 */
		$annotations = $this->describe_annotations();
		if ( '' !== $annotations ) {
			$sentences[] = $annotations;
		}

		$source = trim( (string) ( $this->definition['source'] ?? '' ) );
		if ( '' !== $source ) {
			$sentences[] = sprintf(
				/* translators: %s: the attribution line */
				__( 'Source: %s.', 'kdna-charts' ),
				$source
			);
		}

		return implode( ' ', $sentences );
	}

	/**
	 * The annotation layer in words. Engines that cannot draw
	 * annotations return nothing, so the description never promises
	 * something the picture does not show.
	 */
	protected function describe_annotations() {
		return '';
	}

	/**
	 * Describes the shape of each series: where it starts, where it
	 * ends, and how many readings sit between.
	 */
	protected function describe_series() {
		$out = array();

		foreach ( $this->series() as $index => $series ) {
			$points = $this->series_points( $series );
			if ( empty( $points ) ) {
				continue;
			}

			$label = trim( (string) ( $series['label'] ?? '' ) );
			if ( '' === $label ) {
				$label = sprintf(
					/* translators: %d: series number */
					__( 'Series %d', 'kdna-charts' ),
					$index + 1
				);
			}

			$first = $points[0];
			$last  = $points[ count( $points ) - 1 ];

			$out[] = sprintf(
				/* translators: 1: series name, 2: number of readings, 3: first value, 4: first position, 5: last value, 6: last position */
				__( '%1$s, %2$d readings, from %3$s at %4$s to %5$s at %6$s.', 'kdna-charts' ),
				$label,
				count( $points ),
				KDNA_Charts_Scale::format_number( $last[1] === null ? 0 : $first[1] ),
				KDNA_Charts_Scale::format_number( $first[0] ),
				KDNA_Charts_Scale::format_number( $last[1] === null ? 0 : $last[1] ),
				KDNA_Charts_Scale::format_number( $last[0] )
			);
		}

		return implode( ' ', $out );
	}

	/*
	 * ====================================================================
	 * Reading the definition
	 * ====================================================================
	 */

	protected function type() {
		return (string) ( $this->definition['type'] ?? '' );
	}

	protected function series() {
		$series = $this->definition['series'] ?? array();
		return is_array( $series ) ? $series : array();
	}

	/**
	 * Every point of a series in order, across all its segments, with
	 * the duplicated endpoint dropped where two segments meet.
	 *
	 * This is what the area fill is built from, so that a line made of
	 * a dotted projection and a solid measurement fills as one shape
	 * rather than two abutting ones with a seam down the join.
	 *
	 * @return array List of [x, y] pairs, with null y preserved as a gap.
	 */
	protected function series_points( array $series ) {
		$out = array();

		if ( empty( $series['segments'] ) || ! is_array( $series['segments'] ) ) {
			return $out;
		}

		foreach ( $series['segments'] as $segment ) {
			if ( ! is_array( $segment ) || empty( $segment['points'] ) || ! is_array( $segment['points'] ) ) {
				continue;
			}
			foreach ( array_values( $segment['points'] ) as $index => $point ) {
				if ( ! is_array( $point ) || count( $point ) < 2 ) {
					continue;
				}
				// Segments sharing an endpoint join seamlessly, so the
				// repeated point is dropped rather than drawn twice.
				if ( 0 === $index && ! empty( $out ) ) {
					$previous = $out[ count( $out ) - 1 ];
					if ( is_array( $previous )
						&& (float) $previous[0] === (float) $point[0]
						&& $previous[1] === $point[1] ) {
						continue;
					}
				}
				$out[] = array( $point[0], $point[1] );
			}
		}

		return $out;
	}

	/** True when there is something worth drawing. */
	protected function has_data() {
		foreach ( $this->series() as $series ) {
			if ( ! empty( $this->series_points( $series ) ) ) {
				return true;
			}
			if ( ! empty( $series['data'] ) && is_array( $series['data'] ) ) {
				return true;
			}
		}
		return false;
	}

	protected function option( $key, $default = null ) {
		return array_key_exists( $key, $this->options ) ? $this->options[ $key ] : $default;
	}

	protected function show_caption() {
		if ( array_key_exists( 'show_caption', $this->args ) ) {
			return (bool) $this->args['show_caption'];
		}
		return true;
	}

	protected function show_source() {
		if ( array_key_exists( 'show_source', $this->args ) ) {
			return (bool) $this->args['show_source'];
		}
		return true;
	}

	/*
	 * ====================================================================
	 * Markup building
	 * ====================================================================
	 */

	/**
	 * Builds an element.
	 *
	 * Every attribute value is escaped on the way through, so no caller
	 * has to remember to. A null or false attribute is omitted entirely,
	 * which is how an optional attribute is expressed: emitting nothing
	 * rather than emitting an empty one.
	 *
	 * @param string      $name       Element name.
	 * @param array       $attributes Attribute map.
	 * @param string|null $content    Inner markup, already escaped. Null for a void element.
	 * @return string
	 */
	public static function tag( $name, array $attributes = array(), $content = null ) {
		$name  = preg_replace( '/[^a-zA-Z0-9:-]/', '', (string) $name );
		$parts = array( '<' . $name );

		foreach ( $attributes as $key => $value ) {
			if ( null === $value || false === $value || '' === $value ) {
				continue;
			}
			$key = preg_replace( '/[^a-zA-Z0-9:_-]/', '', (string) $key );
			if ( '' === $key ) {
				continue;
			}
			if ( true === $value ) {
				$parts[] = $key;
				continue;
			}
			if ( is_float( $value ) || is_int( $value ) ) {
				$value = KDNA_Charts_Scale::round_coord( $value );
			}
			$parts[] = $key . '="' . esc_attr( (string) $value ) . '"';
		}

		$open = implode( ' ', $parts );

		if ( null === $content ) {
			return $open . ' />';
		}

		return $open . '>' . $content . '</' . $name . '>';
	}

	/**
	 * A BEM class string for an element, with modifiers.
	 *
	 * @param string $element   Element name, or '' for the block itself.
	 * @param array  $modifiers Modifier names, empty ones skipped.
	 * @return string
	 */
	public static function css( $element, array $modifiers = array() ) {
		$base    = '' === $element ? self::CSS_BLOCK : self::CSS_BLOCK . '__' . $element;
		$classes = array( $base );

		foreach ( $modifiers as $modifier ) {
			$modifier = sanitize_html_class( (string) $modifier );
			if ( '' !== $modifier ) {
				$classes[] = $base . '--' . $modifier;
			}
		}

		return implode( ' ', $classes );
	}

	/** A unique id within this render, for aria targets and gradient references. */
	public function id( $suffix ) {
		return $this->uid . '-' . sanitize_html_class( (string) $suffix );
	}
}
