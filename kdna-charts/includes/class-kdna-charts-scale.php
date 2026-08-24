<?php
/**
 * The maths every plotted chart type depends on.
 *
 * KDNA_Charts_Scale turns data values into SVG coordinates. It works
 * out the plot area inside the canvas, infers axis domains from the
 * data when a chart does not state them, generates readable ticks when
 * a chart does not supply them, and converts a run of points into the
 * path geometry a line or an area is drawn from.
 *
 * It emits nothing. No markup, no echo, no enqueue. Everything it
 * returns is a number, an array of numbers, or a path string, and the
 * renderer at Stage 4 decides what to do with any of it. Keeping it
 * pure is what makes it testable before there is anything to look at.
 *
 * ── The coordinate system ──────────────────────────────────────────
 *
 * The canvas is a fixed 1000 units wide, with the height following
 * from the aspect ratio. The SVG carries a viewBox and width 100 per
 * cent, so those units are proportions rather than pixels and the
 * chart scales fluidly with no JavaScript at all.
 *
 * SVG's y axis grows downward, so every y mapping is inverted. That
 * inversion lives here and nowhere else.
 *
 * ── Deliberately not clamped ───────────────────────────────────────
 *
 * A value outside the domain maps to a coordinate outside the plot
 * area, in the padding, rather than being pinned to the edge. This is
 * on purpose: the collagen chart's note sits at y 111 against a domain
 * that stops at 108, because the note belongs above the plot, and
 * clamping it would drop it onto the frame.
 *
 * The companion class at the bottom of this file is the diagnostic
 * dump, reachable at ?kdna_debug=1. It is the only thing here that
 * produces output, it is separate from the scale itself, and it is
 * gated behind the manage options capability.
 *
 * @package KDNA_Charts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KDNA_Charts_Scale {

	/**
	 * Canvas width in SVG user units. Fixed, because the viewBox makes
	 * the whole coordinate system proportional. A thousand gives every
	 * coordinate two useful decimal places without needing floats in
	 * the markup.
	 */
	const CANVAS_WIDTH = 1000;

	/** Coordinates are rounded to this many decimals in path output. */
	const PRECISION = 2;

	/**
	 * The least padding an edge ever gets, in canvas units. Edges that
	 * carry labels or a title work out how much more they need.
	 */
	const PADDING_MINIMUM = array(
		'top'    => 40,
		'right'  => 32,
		'bottom' => 40,
		'left'   => 32,
	);

	/** Room along an edge that carries an axis title. */
	const AXIS_TITLE_SPACE = 30;

	/** Breathing room between anything and the edge of the canvas. */
	const EDGE_INSET = 10;

	/** Gap between the plot edge and its tick labels. */
	const TICK_LABEL_GAP_X = 20;
	const TICK_LABEL_GAP_Y = 14;

	/**
	 * What the padding assumes a tick label costs.
	 *
	 * ── The one place PHP and CSS have to agree ────────────────────
	 *
	 * Label sizes live in the stylesheet as custom properties, so this
	 * class cannot measure a label: it does not know what size the
	 * browser will draw it at, and there is no text metric available in
	 * PHP anyway. But padding decides the plot geometry and has to be
	 * settled here.
	 *
	 * So the padding reserves room for a label at 24 units, which is the
	 * largest size the shipped stylesheet ever grows one to in a narrow
	 * container. At the 18 unit default there is room to spare, and at
	 * the narrow end it fits exactly.
	 *
	 * A site that raises the label size past 24 has to raise the padding
	 * with it, which is what the padding controls at Stage 9 are for.
	 * The character width is for tabular digits, which is what the axis
	 * labels are set in.
	 */
	const ASSUMED_LABEL_SIZE = 24;
	const LABEL_CHAR_WIDTH   = 0.62;
	const LABEL_LINE_HEIGHT  = 1.25;

	/** How many ticks nice number generation aims for. */
	const DEFAULT_TICK_TARGET = 6;

	/**
	 * Headroom added beyond the data extent when an axis does not state
	 * its bounds. None on x, because a line should reach both ends of
	 * the plot. A little on y, because a line that touches the frame
	 * reads as clipped.
	 */
	const HEADROOM_X = 0.0;
	const HEADROOM_Y = 0.08;

	/*
	 * ====================================================================
	 * State
	 * ====================================================================
	 */

	/** @var string Chart type. */
	private $type;

	/** @var array Chart definition. */
	private $definition;

	/** @var array Canvas dimensions, keys width and height. */
	private $canvas;

	/** @var array Padding, keys top right bottom left. */
	private $padding;

	/** @var array Plot area, keys x y width height right bottom. */
	private $plot;

	/** @var array x domain, keys min and max. */
	private $x_domain;

	/** @var array y domain, keys min and max. */
	private $y_domain;

	/** @var float Where an area fill or a bar is measured from, in data units. */
	private $baseline;

	/** @var array Resolved x ticks. */
	private $x_ticks;

	/** @var array Resolved y ticks. */
	private $y_ticks;

	/** @var array Category names, for the band scale. */
	private $categories;

	/** @var int How many ticks to aim for when generating them. */
	private $tick_target;

	private function __construct() {}

	/*
	 * ====================================================================
	 * Construction
	 * ====================================================================
	 */

	/**
	 * True when a chart type is drawn against x and y axes at all. Pie,
	 * donut and stat are not, and asking for a scale on one of those is
	 * a caller mistake rather than a runtime condition.
	 */
	public static function is_applicable( $type ) {
		return KDNA_Charts_Schema::uses_axes( $type );
	}

	/**
	 * Builds a scale for a chart definition.
	 *
	 * @param array $definition Chart definition, interchange shape.
	 * @param array $overrides  Optional. Any of:
	 *                          canvas_width  int    Canvas width in units.
	 *                          aspect_ratio  string Overrides the chart's own.
	 *                          padding       array  Any of top right bottom left.
	 *                          tick_target   int    Ticks to aim for when generating.
	 * @return self|null Null when the chart type has no axes.
	 */
	public static function for_chart( array $definition, array $overrides = array() ) {
		$type = isset( $definition['type'] ) ? (string) $definition['type'] : '';
		if ( ! self::is_applicable( $type ) ) {
			return null;
		}

		$scale             = new self();
		$scale->type       = $type;
		$scale->definition = $definition;
		$scale->tick_target = isset( $overrides['tick_target'] )
			? max( 2, (int) $overrides['tick_target'] )
			: self::DEFAULT_TICK_TARGET;

		/*
		 * The order matters, and it is a loop that has to be cut in the
		 * right place. Padding depends on how wide the tick labels are,
		 * tick positions depend on the plot area, and the plot area
		 * depends on the padding.
		 *
		 * It is cut by separating what a tick says from where it sits.
		 * Values and labels need only the domain, so they are settled
		 * first; the padding is sized from them; the plot area follows;
		 * and the positions are filled in last.
		 */
		$scale->canvas     = $scale->resolve_canvas( $overrides );
		$scale->categories = $scale->resolve_categories();

		$scale->x_domain = $scale->resolve_domain( 'x' );
		$scale->y_domain = $scale->resolve_domain( 'y' );
		$scale->baseline = $scale->resolve_baseline();

		$scale->x_ticks = $scale->resolve_tick_values( 'x' );
		$scale->y_ticks = $scale->resolve_tick_values( 'y' );

		$scale->padding = $scale->resolve_padding( $overrides );
		$scale->plot    = $scale->resolve_plot_area();

		$scale->position_ticks();

		return $scale;
	}

	/**
	 * Builds a scale from bare numbers, for tests and for callers that
	 * have a domain but no chart.
	 *
	 * @param array $args Keys: x_min, x_max, y_min, y_max, and any of the
	 *                    override keys for_chart() accepts.
	 */
	public static function from_bounds( array $args ) {
		$definition = array(
			'type'    => isset( $args['type'] ) ? $args['type'] : 'line',
			'options' => isset( $args['options'] ) ? $args['options'] : array(),
			'axes'    => array(
				'x' => array_filter(
					array(
						'min' => $args['x_min'] ?? null,
						'max' => $args['x_max'] ?? null,
					),
					static function ( $v ) {
						return null !== $v;
					}
				),
				'y' => array_filter(
					array(
						'min' => $args['y_min'] ?? null,
						'max' => $args['y_max'] ?? null,
					),
					static function ( $v ) {
						return null !== $v;
					}
				),
			),
			'series'  => isset( $args['series'] ) ? $args['series'] : array(),
		);

		return self::for_chart( $definition, $args );
	}

	private function resolve_canvas( array $overrides ) {
		$width = isset( $overrides['canvas_width'] )
			? max( 100, (int) $overrides['canvas_width'] )
			: self::CANVAS_WIDTH;

		$ratio = isset( $overrides['aspect_ratio'] )
			? (string) $overrides['aspect_ratio']
			: (string) ( $this->definition['options']['aspect_ratio'] ?? KDNA_Charts_Schema::DEFAULT_ASPECT_RATIO );

		$parts = self::parse_ratio( $ratio );

		return array(
			'width'  => (float) $width,
			'height' => round( $width * ( $parts[1] / $parts[0] ), self::PRECISION ),
			'ratio'  => $parts[0] . ':' . $parts[1],
		);
	}

	/**
	 * Turns "16:9" into array( 16, 9 ), falling back to the schema
	 * default for anything unreadable. The importer already validates
	 * this, so a bad value here means a chart written before the check
	 * existed, or one built by hand in code.
	 */
	public static function parse_ratio( $ratio ) {
		if ( is_string( $ratio ) && preg_match( '/^\s*(\d{1,3})\s*[:\/]\s*(\d{1,3})\s*$/', $ratio, $matches ) ) {
			$width  = (int) $matches[1];
			$height = (int) $matches[2];
			if ( $width > 0 && $height > 0 ) {
				return array( $width, $height );
			}
		}
		$default = explode( ':', KDNA_Charts_Schema::DEFAULT_ASPECT_RATIO );
		return array( (int) $default[0], (int) $default[1] );
	}

	/**
	 * Padding, sized from what each edge actually has to hold.
	 *
	 * An edge with no labels and no title keeps the minimum. An edge
	 * carrying tick labels reserves room for the longest of them, and
	 * one carrying a title reserves room for that too. A chart with no
	 * axis titles gets the space back rather than every chart carrying
	 * a margin for text that may not be there.
	 */
	private function resolve_padding( array $overrides ) {
		$padding = self::PADDING_MINIMUM;

		$has_y_title = '' !== (string) ( $this->definition['axes']['y']['label'] ?? '' );
		$has_x_title = '' !== (string) ( $this->definition['axes']['x']['label'] ?? '' );

		$y_label_width = $this->widest_label( $this->y_ticks );
		if ( $y_label_width > 0 ) {
			$padding['left'] = max(
				$padding['left'],
				self::EDGE_INSET + ( $has_y_title ? self::AXIS_TITLE_SPACE : 0 ) + $y_label_width + self::TICK_LABEL_GAP_X
			);
		} elseif ( $has_y_title ) {
			$padding['left'] += self::AXIS_TITLE_SPACE;
		}

		$label_height = self::ASSUMED_LABEL_SIZE * self::LABEL_LINE_HEIGHT;
		if ( ! empty( $this->x_ticks ) ) {
			$padding['bottom'] = max(
				$padding['bottom'],
				self::EDGE_INSET + ( $has_x_title ? self::AXIS_TITLE_SPACE : 0 ) + $label_height + self::TICK_LABEL_GAP_Y
			);
		} elseif ( $has_x_title ) {
			$padding['bottom'] += self::AXIS_TITLE_SPACE;
		}

		/*
		 * The last x tick label is centred on the right edge of the plot,
		 * so half of it hangs past. Without this the final label on a
		 * chart running to its own maximum is cut off by the canvas.
		 */
		$x_label_width = $this->widest_label( $this->x_ticks );
		if ( $x_label_width > 0 ) {
			$padding['right'] = max( $padding['right'], self::EDGE_INSET + $x_label_width / 2 );
			$padding['left']  = max( $padding['left'], self::EDGE_INSET + $x_label_width / 2 );
		}

		if ( isset( $overrides['padding'] ) && is_array( $overrides['padding'] ) ) {
			foreach ( array( 'top', 'right', 'bottom', 'left' ) as $edge ) {
				if ( isset( $overrides['padding'][ $edge ] ) && is_numeric( $overrides['padding'][ $edge ] ) ) {
					$padding[ $edge ] = (float) $overrides['padding'][ $edge ];
				}
			}
		}

		foreach ( $padding as $edge => $value ) {
			$padding[ $edge ] = round( max( 0.0, (float) $value ), self::PRECISION );
		}

		return $padding;
	}

	/**
	 * An estimate of how wide the longest label in a tick list will be
	 * drawn. See ASSUMED_LABEL_SIZE for why this is an estimate.
	 */
	private function widest_label( array $ticks ) {
		$longest = 0;
		foreach ( $ticks as $tick ) {
			$label = (string) ( $tick['label'] ?? '' );
			if ( '' === $label ) {
				continue;
			}
			// Multibyte aware, because a label may carry a currency
			// symbol or an accented category name.
			$length  = function_exists( 'mb_strlen' ) ? mb_strlen( $label ) : strlen( $label );
			$longest = max( $longest, $length );
		}
		if ( 0 === $longest ) {
			return 0.0;
		}
		return $longest * self::LABEL_CHAR_WIDTH * self::ASSUMED_LABEL_SIZE;
	}

	/**
	 * The rectangle the data is drawn inside.
	 *
	 * Padding that would leave no room at all is scaled back rather
	 * than producing a negative width, because a chart with a silly
	 * padding override should look wrong, not break the geometry.
	 */
	private function resolve_plot_area() {
		$width  = $this->canvas['width'] - $this->padding['left'] - $this->padding['right'];
		$height = $this->canvas['height'] - $this->padding['top'] - $this->padding['bottom'];

		$left = $this->padding['left'];
		$top  = $this->padding['top'];

		if ( $width <= 0 ) {
			$width = $this->canvas['width'] * 0.5;
			$left  = $this->canvas['width'] * 0.25;
		}
		if ( $height <= 0 ) {
			$height = $this->canvas['height'] * 0.5;
			$top    = $this->canvas['height'] * 0.25;
		}

		return array(
			'x'      => round( $left, self::PRECISION ),
			'y'      => round( $top, self::PRECISION ),
			'width'  => round( $width, self::PRECISION ),
			'height' => round( $height, self::PRECISION ),
			'right'  => round( $left + $width, self::PRECISION ),
			'bottom' => round( $top + $height, self::PRECISION ),
		);
	}

	private function resolve_categories() {
		$categories = $this->definition['axes']['x']['categories'] ?? array();
		if ( is_array( $categories ) && ! empty( $categories ) ) {
			return array_values( array_map( 'strval', $categories ) );
		}

		// A categorical chart with no category list takes its names from
		// the labels of the longest series, which is what a person means
		// when they write the data out and leave the axis implicit.
		if ( ! KDNA_Charts_Schema::uses_data( $this->type ) ) {
			return array();
		}

		$longest = array();
		foreach ( $this->series() as $series ) {
			$data = isset( $series['data'] ) && is_array( $series['data'] ) ? $series['data'] : array();
			if ( count( $data ) > count( $longest ) ) {
				$longest = $data;
			}
		}

		$names = array();
		foreach ( $longest as $index => $datum ) {
			$names[] = (string) ( $datum['label'] ?? ( $index + 1 ) );
		}
		return $names;
	}

	/*
	 * ====================================================================
	 * Domains
	 * ====================================================================
	 */

	/**
	 * Settles an axis domain, in this order: what the chart states, then
	 * what the data and any explicit ticks need, then headroom.
	 *
	 * @param string $axis 'x' or 'y'.
	 * @return array Keys min, max, inferred (bool).
	 */
	private function resolve_domain( $axis ) {
		$declared = $this->definition['axes'][ $axis ] ?? array();
		$min      = isset( $declared['min'] ) && is_numeric( $declared['min'] ) ? (float) $declared['min'] : null;
		$max      = isset( $declared['max'] ) && is_numeric( $declared['max'] ) ? (float) $declared['max'] : null;

		if ( null !== $min && null !== $max ) {
			if ( $min === $max ) {
				return self::expand_flat_domain( $min, false );
			}
			// A chart that states its bounds the wrong way round meant
			// the range, not the order.
			return array(
				'min'      => min( $min, $max ),
				'max'      => max( $min, $max ),
				'inferred' => false,
			);
		}

		$values = $this->collect_values( $axis );

		// Explicit ticks are part of what the axis has to cover, or a
		// tick would be drawn outside the plot.
		foreach ( $this->declared_tick_values( $axis ) as $tick_value ) {
			$values[] = $tick_value;
		}

		// A categorical x axis is indexed by slot, not by value.
		if ( 'x' === $axis && $this->is_categorical() ) {
			$count = max( 1, count( $this->categories ) );
			return array(
				'min'      => 0.0,
				'max'      => (float) $count,
				'inferred' => true,
			);
		}

		if ( empty( $values ) ) {
			return array(
				'min'      => null === $min ? 0.0 : $min,
				'max'      => null === $max ? 1.0 : $max,
				'inferred' => true,
			);
		}

		$data_min = min( $values );
		$data_max = max( $values );

		if ( $data_min === $data_max ) {
			$flat = self::expand_flat_domain( $data_min, true );
			$data_min = $flat['min'];
			$data_max = $flat['max'];
		}

		$headroom = ( 'y' === $axis ) ? self::HEADROOM_Y : self::HEADROOM_X;
		$span     = $data_max - $data_min;

		$inferred_min = $data_min - ( $span * $headroom );
		$inferred_max = $data_max + ( $span * $headroom );

		/*
		 * A bar chart measured from zero must include zero, or the bars
		 * start partway up and every length in the chart lies about its
		 * proportion.
		 */
		if ( 'y' === $axis && $this->is_categorical() ) {
			$inferred_min = min( $inferred_min, 0.0 );
			$inferred_max = max( $inferred_max, 0.0 );
		}

		// A generated y axis reads better ending on a round number,
		// since its ticks will be round numbers too.
		if ( 'y' === $axis ) {
			$nice = self::nice_domain( $inferred_min, $inferred_max, $this->tick_target );
			$inferred_min = $nice['min'];
			$inferred_max = $nice['max'];
		}

		return array(
			'min'      => null === $min ? $inferred_min : $min,
			'max'      => null === $max ? $inferred_max : $max,
			'inferred' => true,
		);
	}

	/**
	 * A domain where every value is the same has no range to scale
	 * against, so it gets one. Ten per cent either side of the value,
	 * or a unit either side of zero.
	 */
	private static function expand_flat_domain( $value, $as_pair = false ) {
		$pad = ( 0.0 === (float) $value ) ? 1.0 : abs( $value ) * 0.1;
		$out = array(
			'min'      => $value - $pad,
			'max'      => $value + $pad,
			'inferred' => true,
		);
		return $as_pair ? $out : $out;
	}

	/**
	 * Every value on one axis, across every series, from both the
	 * plotted and the categorical data shapes.
	 *
	 * Annotations are deliberately absent. A note or a callout placed
	 * above the data is placed above the data on purpose, and letting
	 * it stretch the domain would flatten the line it is annotating.
	 */
	private function collect_values( $axis ) {
		$values = array();

		foreach ( $this->series() as $series ) {
			if ( ! empty( $series['segments'] ) && is_array( $series['segments'] ) ) {
				foreach ( $series['segments'] as $segment ) {
					$points = ( is_array( $segment ) && ! empty( $segment['points'] ) && is_array( $segment['points'] ) )
						? $segment['points']
						: array();
					foreach ( $points as $point ) {
						if ( ! is_array( $point ) || count( $point ) < 2 ) {
							continue;
						}
						$value = ( 'x' === $axis ) ? $point[0] : $point[1];
						if ( is_numeric( $value ) ) {
							$values[] = (float) $value;
						}
					}
				}
			}

			if ( ! empty( $series['data'] ) && is_array( $series['data'] ) ) {
				foreach ( $series['data'] as $datum ) {
					if ( 'y' === $axis && is_array( $datum ) && isset( $datum['value'] ) && is_numeric( $datum['value'] ) ) {
						$values[] = (float) $datum['value'];
					}
				}
			}
		}

		return $values;
	}

	private function declared_tick_values( $axis ) {
		$ticks  = $this->definition['axes'][ $axis ]['ticks'] ?? array();
		$values = array();
		if ( ! is_array( $ticks ) ) {
			return $values;
		}
		foreach ( $ticks as $tick ) {
			if ( is_array( $tick ) && isset( $tick['value'] ) && is_numeric( $tick['value'] ) ) {
				$values[] = (float) $tick['value'];
			}
		}
		return $values;
	}

	/**
	 * Where an area fill or a bar is measured from, in data units.
	 *
	 * A chart that states a baseline gets it. Otherwise a domain that
	 * crosses zero is measured from zero, because a bar chart with
	 * negative values has to hang from the zero line, and a domain that
	 * does not is measured from its floor.
	 */
	private function resolve_baseline() {
		$declared = $this->definition['axes']['y']['baseline'] ?? null;
		if ( is_numeric( $declared ) ) {
			return (float) $declared;
		}
		if ( $this->y_domain['min'] <= 0 && $this->y_domain['max'] >= 0 ) {
			return 0.0;
		}
		return (float) $this->y_domain['min'];
	}

	private function series() {
		$series = $this->definition['series'] ?? array();
		return is_array( $series ) ? $series : array();
	}

	private function is_categorical() {
		return KDNA_Charts_Schema::uses_data( $this->type );
	}

	/*
	 * ====================================================================
	 * Ticks
	 * ====================================================================
	 */

	/**
	 * The ticks for one axis, each resolved to an SVG position.
	 *
	 * A chart that states its ticks gets exactly those, in the order it
	 * gave them, because which values to darken and which to mute is an
	 * editorial decision the generator has no business overruling.
	 *
	 * @param string $axis 'x' or 'y'.
	 * @return array List of arrays with keys value, label, emphasis, rule, position, generated.
	 */
	private function resolve_tick_values( $axis ) {
		$declared = $this->definition['axes'][ $axis ]['ticks'] ?? array();
		$out      = array();

		if ( is_array( $declared ) && ! empty( $declared ) ) {
			foreach ( $declared as $tick ) {
				if ( ! is_array( $tick ) || ! isset( $tick['value'] ) || ! is_numeric( $tick['value'] ) ) {
					continue;
				}
				$value = (float) $tick['value'];
				$out[] = array(
					'value'     => $value,
					'label'     => isset( $tick['label'] ) && '' !== $tick['label']
						? (string) $tick['label']
						: self::format_number( $value ),
					'emphasis'  => isset( $tick['emphasis'] ) ? (string) $tick['emphasis'] : 'normal',
					'rule'      => isset( $tick['rule'] ) ? (string) $tick['rule'] : 'none',
					'position'  => null,
					'generated' => false,
				);
			}
			return $out;
		}

		// A categorical x axis ticks once per category, at the middle of
		// its slot, and the label is the category name.
		if ( 'x' === $axis && $this->is_categorical() ) {
			foreach ( $this->categories as $index => $name ) {
				$out[] = array(
					'value'     => $index + 0.5,
					'label'     => $name,
					'emphasis'  => 'normal',
					'rule'      => 'none',
					'position'  => null,
					'generated' => true,
				);
			}
			return $out;
		}

		$domain = ( 'x' === $axis ) ? $this->x_domain : $this->y_domain;
		foreach ( self::nice_ticks( $domain['min'], $domain['max'], $this->tick_target ) as $value ) {
			$out[] = array(
				'value'     => $value,
				'label'     => self::format_number( $value ),
				'emphasis'  => 'normal',
				'rule'      => ( 'y' === $axis ) ? 'dotted' : 'none',
				'position'  => null,
				'generated' => true,
			);
		}

		return $out;
	}

	/**
	 * Fills in where each tick sits, once the plot area is known.
	 */
	private function position_ticks() {
		foreach ( $this->x_ticks as $index => $tick ) {
			$this->x_ticks[ $index ]['position'] = $this->x( $tick['value'] );
		}
		foreach ( $this->y_ticks as $index => $tick ) {
			$this->y_ticks[ $index ]['position'] = $this->y( $tick['value'] );
		}
	}

	/**
	 * Heckbert's nice numbers. Returns the tick values covering a range,
	 * spaced at a step a person would have chosen: 1, 2, 5 or 10 times
	 * a power of ten, never 3.7.
	 *
	 * @param float $min    Lower bound.
	 * @param float $max    Upper bound.
	 * @param int   $target How many ticks to aim for. Actual count varies.
	 * @return float[]
	 */
	public static function nice_ticks( $min, $max, $target = self::DEFAULT_TICK_TARGET ) {
		$min    = (float) $min;
		$max    = (float) $max;
		$target = max( 2, (int) $target );

		if ( $min > $max ) {
			list( $min, $max ) = array( $max, $min );
		}
		if ( $min === $max ) {
			return array( $min );
		}

		$spacing = self::tick_spacing( $min, $max, $target );
		if ( $spacing <= 0 ) {
			return array( $min, $max );
		}

		/*
		 * The epsilon is not fussiness. 0.6 / 0.2 evaluates to
		 * 2.9999999999999996, so a plain floor loses the top tick and the
		 * axis quietly stops one short of where the data ends.
		 */
		$epsilon = 1e-9;
		$first   = ceil( $min / $spacing - $epsilon );
		$last    = floor( $max / $spacing + $epsilon );

		// A guard rather than a feature. Nothing in the schema can reach
		// it, but a hand built definition with a domain of 1e12 and a
		// spacing of 1 should not try to build a trillion ticks.
		$count = (int) ( $last - $first );
		if ( $count > 1000 ) {
			return array( $min, $max );
		}

		$ticks = array();
		for ( $i = $first; $i <= $last; $i++ ) {
			/*
			 * Multiplying the index by the spacing rather than adding the
			 * spacing repeatedly. Accumulating 0.1 six times gives
			 * 0.6000000000000001, and that ends up printed on an axis.
			 */
			$ticks[] = self::snap( $i * $spacing, $spacing );
		}

		return $ticks;
	}

	/**
	 * The domain rounded outward to whole ticks, so an axis ends on a
	 * round number rather than partway between two.
	 *
	 * @return array Keys min and max.
	 */
	public static function nice_domain( $min, $max, $target = self::DEFAULT_TICK_TARGET ) {
		$min = (float) $min;
		$max = (float) $max;

		if ( $min > $max ) {
			list( $min, $max ) = array( $max, $min );
		}
		if ( $min === $max ) {
			$pad = ( 0.0 === $min ) ? 1.0 : abs( $min ) * 0.1;
			return array(
				'min' => $min - $pad,
				'max' => $max + $pad,
			);
		}

		$spacing = self::tick_spacing( $min, $max, $target );
		if ( $spacing <= 0 ) {
			return array( 'min' => $min, 'max' => $max );
		}

		return array(
			'min' => self::snap( floor( $min / $spacing ) * $spacing, $spacing ),
			'max' => self::snap( ceil( $max / $spacing ) * $spacing, $spacing ),
		);
	}

	private static function tick_spacing( $min, $max, $target ) {
		$range = self::nice_number( $max - $min, false );
		if ( $range <= 0 ) {
			return 0.0;
		}
		return self::nice_number( $range / ( $target - 1 ), true );
	}

	/**
	 * The nearest nice number to a range: 1, 2, 5 or 10 times a power
	 * of ten. Rounding picks the closest, otherwise it rounds up.
	 */
	public static function nice_number( $range, $round ) {
		$range = abs( (float) $range );
		if ( 0.0 === $range ) {
			return 0.0;
		}

		$exponent = floor( log10( $range ) );
		$fraction = $range / pow( 10, $exponent );

		if ( $round ) {
			if ( $fraction < 1.5 ) {
				$nice = 1;
			} elseif ( $fraction < 3 ) {
				$nice = 2;
			} elseif ( $fraction < 7 ) {
				$nice = 5;
			} else {
				$nice = 10;
			}
		} else {
			if ( $fraction <= 1 ) {
				$nice = 1;
			} elseif ( $fraction <= 2 ) {
				$nice = 2;
			} elseif ( $fraction <= 5 ) {
				$nice = 5;
			} else {
				$nice = 10;
			}
		}

		return $nice * pow( 10, $exponent );
	}

	/**
	 * Rounds a value to the precision its own spacing implies, so a
	 * tick at two tenths reads as 0.2 rather than 0.2000000000000000111.
	 */
	private static function snap( $value, $spacing ) {
		$spacing = abs( (float) $spacing );
		if ( 0.0 === $spacing ) {
			return self::normalise_zero( (float) $value );
		}
		$decimals = max( 0, (int) ceil( -log10( $spacing ) ) + 1 );
		return self::normalise_zero( round( (float) $value, min( 12, $decimals ) ) );
	}

	/**
	 * Turns negative zero into zero.
	 *
	 * Floats have two of them, and the tick generator produces the
	 * negative one whenever the first tick index rounds down through
	 * zero. It compares equal to zero, so nothing catches it, and it
	 * arrives on the axis printed as -0.
	 */
	private static function normalise_zero( $value ) {
		return ( 0.0 == $value ) ? 0.0 : $value; // phpcs:ignore Universal.Operators.StrictComparisons
	}

	/**
	 * A number written the way an axis label should read: no trailing
	 * zeros, no exponent, thousands separated.
	 */
	public static function format_number( $value, $decimals = null ) {
		$value = (float) $value;

		if ( null === $decimals ) {
			$decimals = 0;
			$scaled   = $value;
			// Enough decimals to distinguish the value, up to four.
			while ( $decimals < 4 && abs( $scaled - round( $scaled ) ) > 1e-9 ) {
				$decimals++;
				$scaled = $value * pow( 10, $decimals );
			}
		}

		$value     = self::normalise_zero( $value );
		$formatted = number_format( $value, (int) $decimals, '.', ',' );

		// number_format keeps the zeros it was asked for, and an axis
		// does not want them.
		if ( false !== strpos( $formatted, '.' ) ) {
			$formatted = rtrim( rtrim( $formatted, '0' ), '.' );
		}

		return '' === $formatted ? '0' : $formatted;
	}

	/*
	 * ====================================================================
	 * Mapping
	 * ====================================================================
	 */

	/**
	 * A data value on the x axis, as an SVG x coordinate.
	 *
	 * Not clamped. A value outside the domain lands in the padding,
	 * which is where a note or a marker label placed beyond the data
	 * belongs.
	 */
	public function x( $value, $clamp = false ) {
		$span = $this->x_domain['max'] - $this->x_domain['min'];
		if ( 0.0 === (float) $span ) {
			return round( $this->plot['x'] + $this->plot['width'] / 2, self::PRECISION );
		}
		$fraction = ( (float) $value - $this->x_domain['min'] ) / $span;
		if ( $clamp ) {
			$fraction = max( 0.0, min( 1.0, $fraction ) );
		}
		return round( $this->plot['x'] + $fraction * $this->plot['width'], self::PRECISION );
	}

	/**
	 * A data value on the y axis, as an SVG y coordinate.
	 *
	 * This is where the inversion lives. SVG counts downward, charts
	 * count upward, and every other part of the plugin gets to forget
	 * about it.
	 */
	public function y( $value, $clamp = false ) {
		$span = $this->y_domain['max'] - $this->y_domain['min'];
		if ( 0.0 === (float) $span ) {
			return round( $this->plot['y'] + $this->plot['height'] / 2, self::PRECISION );
		}
		$fraction = ( (float) $value - $this->y_domain['min'] ) / $span;
		if ( $clamp ) {
			$fraction = max( 0.0, min( 1.0, $fraction ) );
		}
		return round( $this->plot['bottom'] - $fraction * $this->plot['height'], self::PRECISION );
	}

	/**
	 * A data pair as an SVG coordinate pair.
	 *
	 * @return array array( x, y )
	 */
	public function point( $x, $y, $clamp = false ) {
		return array( $this->x( $x, $clamp ), $this->y( $y, $clamp ) );
	}

	/**
	 * Projects a list of [x, y] data pairs into SVG coordinates.
	 *
	 * A point whose y is null becomes a null entry rather than being
	 * dropped, because it is a deliberate gap in the line and the path
	 * builder needs to know where the line stops and starts again.
	 *
	 * @param array $points List of [x, y] pairs.
	 * @return array List of array( x, y ) or null.
	 */
	public function project( array $points ) {
		$out = array();
		foreach ( $points as $point ) {
			if ( ! is_array( $point ) || count( $point ) < 2 ) {
				continue;
			}
			$x = $point[0];
			$y = $point[1];
			if ( ! is_numeric( $x ) ) {
				continue;
			}
			if ( null === $y || ! is_numeric( $y ) ) {
				$out[] = null;
				continue;
			}
			$out[] = array( $this->x( $x ), $this->y( $y ) );
		}
		return $out;
	}

	/**
	 * Reverses the mapping, turning an SVG x back into a data value.
	 * Needed by the annotation layer when it nudges a label and has to
	 * know what the nudged position means.
	 */
	public function invert_x( $svg_x ) {
		if ( 0.0 === (float) $this->plot['width'] ) {
			return $this->x_domain['min'];
		}
		$fraction = ( (float) $svg_x - $this->plot['x'] ) / $this->plot['width'];
		return $this->x_domain['min'] + $fraction * ( $this->x_domain['max'] - $this->x_domain['min'] );
	}

	public function invert_y( $svg_y ) {
		if ( 0.0 === (float) $this->plot['height'] ) {
			return $this->y_domain['min'];
		}
		$fraction = ( $this->plot['bottom'] - (float) $svg_y ) / $this->plot['height'];
		return $this->y_domain['min'] + $fraction * ( $this->y_domain['max'] - $this->y_domain['min'] );
	}

	/**
	 * The slot one bar occupies, for bar and column charts.
	 *
	 * Categories divide the plot into equal slots. Each slot holds a
	 * group, each group holds one bar per series. Both gaps are
	 * fractions of what they sit inside, so the arithmetic holds at any
	 * category count.
	 *
	 * @param int $index        Which category.
	 * @param int $series_index Which series within the group.
	 * @param int $series_count How many series share the group.
	 * @param array $options    Optional bar_gap and group_gap overrides.
	 * @return array Keys x, width, slot_x, slot_width, centre.
	 */
	public function band( $index, $series_index = 0, $series_count = 1, array $options = array() ) {
		$count        = max( 1, count( $this->categories ) );
		$series_count = max( 1, (int) $series_count );
		$series_index = max( 0, min( $series_count - 1, (int) $series_index ) );

		$defaults  = KDNA_Charts_Schema::default_options( $this->type );
		$group_gap = isset( $options['group_gap'] ) ? (float) $options['group_gap'] : (float) ( $this->definition['options']['group_gap'] ?? $defaults['group_gap'] ?? 0.3 );
		$bar_gap   = isset( $options['bar_gap'] ) ? (float) $options['bar_gap'] : (float) ( $this->definition['options']['bar_gap'] ?? $defaults['bar_gap'] ?? 0.2 );

		$group_gap = max( 0.0, min( 0.9, $group_gap ) );
		$bar_gap   = max( 0.0, min( 0.9, $bar_gap ) );

		$slot_width  = $this->plot['width'] / $count;
		$slot_x      = $this->plot['x'] + $index * $slot_width;
		$group_width = $slot_width * ( 1 - $group_gap );
		$group_x     = $slot_x + ( $slot_width - $group_width ) / 2;

		$share = $group_width / $series_count;
		$width = $share * ( 1 - $bar_gap );
		$x     = $group_x + $series_index * $share + ( $share - $width ) / 2;

		return array(
			'x'          => round( $x, self::PRECISION ),
			'width'      => round( $width, self::PRECISION ),
			'slot_x'     => round( $slot_x, self::PRECISION ),
			'slot_width' => round( $slot_width, self::PRECISION ),
			'centre'     => round( $slot_x + $slot_width / 2, self::PRECISION ),
		);
	}

	/*
	 * ====================================================================
	 * Path geometry
	 * ====================================================================
	 */

	/**
	 * A path d string through a run of projected points.
	 *
	 * Null entries break the line: the path lifts and starts again,
	 * rather than drawing a straight segment across a gap that the data
	 * says nothing about.
	 *
	 * @param array  $points List of array( x, y ) or null, already projected.
	 * @param string $curve  linear, smooth or step.
	 * @return string A d attribute value, or '' when there is nothing to draw.
	 */
	public static function path_d( array $points, $curve = 'linear' ) {
		$runs = self::split_runs( $points );
		$out  = array();

		foreach ( $runs as $run ) {
			$d = self::run_to_path( $run, $curve );
			if ( '' !== $d ) {
				$out[] = $d;
			}
		}

		return implode( ' ', $out );
	}

	/**
	 * A closed path for an area fill: the line, then down to the
	 * baseline and back along it.
	 *
	 * Each run is closed separately, so a line with a gap in it fills
	 * as two shapes rather than one shape spanning the gap.
	 *
	 * @param array  $points     Projected points.
	 * @param float  $baseline_y SVG y of the baseline.
	 * @param string $curve      linear, smooth or step.
	 * @return string
	 */
	public static function area_d( array $points, $baseline_y, $curve = 'linear' ) {
		$runs = self::split_runs( $points );
		$out  = array();

		foreach ( $runs as $run ) {
			if ( count( $run ) < 2 ) {
				continue;
			}
			$line = self::run_to_path( $run, $curve );
			if ( '' === $line ) {
				continue;
			}
			$first = $run[0];
			$last  = $run[ count( $run ) - 1 ];
			$out[] = $line
				. ' L ' . self::round_coord( $last[0] ) . ' ' . self::round_coord( $baseline_y )
				. ' L ' . self::round_coord( $first[0] ) . ' ' . self::round_coord( $baseline_y )
				. ' Z';
		}

		return implode( ' ', $out );
	}

	/**
	 * Splits a projected list into unbroken runs at every null.
	 */
	private static function split_runs( array $points ) {
		$runs    = array();
		$current = array();

		foreach ( $points as $point ) {
			if ( null === $point || ! is_array( $point ) || count( $point ) < 2 ) {
				if ( ! empty( $current ) ) {
					$runs[]  = $current;
					$current = array();
				}
				continue;
			}
			$current[] = array( (float) $point[0], (float) $point[1] );
		}

		if ( ! empty( $current ) ) {
			$runs[] = $current;
		}

		return $runs;
	}

	private static function run_to_path( array $run, $curve ) {
		$count = count( $run );
		if ( 0 === $count ) {
			return '';
		}

		$start = 'M ' . self::round_coord( $run[0][0] ) . ' ' . self::round_coord( $run[0][1] );

		if ( 1 === $count ) {
			// A single point still needs to exist on the path, so a dot
			// marker or a one point series has something to sit on.
			return $start;
		}

		switch ( $curve ) {
			case 'smooth':
				return $start . ' ' . self::catmull_rom_to_bezier( $run );

			case 'step':
				return $start . ' ' . self::step_segments( $run );
		}

		$parts = array( $start );
		for ( $i = 1; $i < $count; $i++ ) {
			$parts[] = 'L ' . self::round_coord( $run[ $i ][0] ) . ' ' . self::round_coord( $run[ $i ][1] );
		}
		return implode( ' ', $parts );
	}

	/**
	 * Converts a run of points into cubic Bezier segments through a
	 * Catmull Rom spline.
	 *
	 * A Catmull Rom spline passes through every point it is given,
	 * which is the property a chart needs: the curve must touch the
	 * data, not approximate it. Each span between two points becomes
	 * one cubic Bezier whose control points are derived from the
	 * neighbours on either side, so the curve leaves each point in the
	 * direction the surrounding data is heading.
	 *
	 *   c1 = p1 + (p2 - p0) * tension / 6
	 *   c2 = p2 - (p3 - p1) * tension / 6
	 *
	 * The ends have no outside neighbour, so the endpoint stands in for
	 * it, which makes the curve leave the first point and arrive at the
	 * last one along the line to its neighbour.
	 *
	 * ── Why the overshoot clamp is on by default ───────────────────
	 *
	 * A plain Catmull Rom spline can bulge past the points it connects.
	 * On a decorative curve that is fine. On a chart it is a lie.
	 *
	 * The shape that does it is a change of pace in one direction: a
	 * gentle rise that suddenly steepens is drawn dipping below its own
	 * starting value first, so the chart shows a fall that the data
	 * never contained. The steeper the change, the worse it gets.
	 *
	 * So where three consecutive points move in one direction, the
	 * control points are held inside the span they belong to. Real
	 * inflections are untouched, because the clamp only applies where
	 * the data itself is monotone, and a genuine peak still curves
	 * through its apex.
	 *
	 * Evenly paced data is unaffected either way. The collagen chart is
	 * an example: its control points land exactly on the boundary, so
	 * the clamp changes nothing there. It earns its place on the charts
	 * that are not so evenly paced.
	 *
	 * @param array $run             Points, each array( x, y ).
	 * @param float $tension         0 gives straight lines, 1 gives a full spline.
	 * @param bool  $clamp_overshoot Hold the curve inside monotone spans.
	 * @return string The C segments, without the leading M.
	 */
	public static function catmull_rom_to_bezier( array $run, $tension = 1.0, $clamp_overshoot = true ) {
		$count = count( $run );
		if ( $count < 2 ) {
			return '';
		}

		$tension = max( 0.0, min( 1.0, (float) $tension ) );
		$factor  = $tension / 6.0;
		$parts   = array();

		for ( $i = 0; $i < $count - 1; $i++ ) {
			$p0 = $run[ max( 0, $i - 1 ) ];
			$p1 = $run[ $i ];
			$p2 = $run[ $i + 1 ];
			$p3 = $run[ min( $count - 1, $i + 2 ) ];

			$c1x = $p1[0] + ( $p2[0] - $p0[0] ) * $factor;
			$c1y = $p1[1] + ( $p2[1] - $p0[1] ) * $factor;
			$c2x = $p2[0] - ( $p3[0] - $p1[0] ) * $factor;
			$c2y = $p2[1] - ( $p3[1] - $p1[1] ) * $factor;

			if ( $clamp_overshoot ) {
				$low  = min( $p1[1], $p2[1] );
				$high = max( $p1[1], $p2[1] );

				// Only where the data either side is heading the same way.
				if ( self::is_monotone( $p0[1], $p1[1], $p2[1] ) ) {
					$c1y = max( $low, min( $high, $c1y ) );
				}
				if ( self::is_monotone( $p1[1], $p2[1], $p3[1] ) ) {
					$c2y = max( $low, min( $high, $c2y ) );
				}

				// x is monotone by construction on a time series, and a
				// control point that runs backwards along it would fold
				// the curve over itself.
				$x_low  = min( $p1[0], $p2[0] );
				$x_high = max( $p1[0], $p2[0] );
				$c1x    = max( $x_low, min( $x_high, $c1x ) );
				$c2x    = max( $x_low, min( $x_high, $c2x ) );
			}

			$parts[] = 'C ' . self::round_coord( $c1x ) . ' ' . self::round_coord( $c1y )
				. ', ' . self::round_coord( $c2x ) . ' ' . self::round_coord( $c2y )
				. ', ' . self::round_coord( $p2[0] ) . ' ' . self::round_coord( $p2[1] );
		}

		return implode( ' ', $parts );
	}

	/**
	 * True when three values move in one direction, flat included.
	 */
	private static function is_monotone( $a, $b, $c ) {
		return ( $b - $a ) * ( $c - $b ) >= 0;
	}

	/**
	 * Step segments: the value holds until the next x, then jumps. The
	 * shape for a quantity that changes at a moment rather than over an
	 * interval, a price or a rate.
	 */
	private static function step_segments( array $run ) {
		$parts = array();
		$count = count( $run );
		for ( $i = 1; $i < $count; $i++ ) {
			$parts[] = 'H ' . self::round_coord( $run[ $i ][0] );
			$parts[] = 'V ' . self::round_coord( $run[ $i ][1] );
		}
		return implode( ' ', $parts );
	}

	/**
	 * Coordinates are rounded before they reach a path string. Two
	 * decimals on a thousand unit canvas is finer than any screen can
	 * show, and it keeps the markup small.
	 */
	public static function round_coord( $value ) {
		$rounded = self::normalise_zero( round( (float) $value, self::PRECISION ) );
		// Drop the decimal point when there is nothing after it, so a
		// path reads 240 rather than 240.00.
		return ( (float) (int) $rounded === $rounded ) ? (string) (int) $rounded : (string) $rounded;
	}

	/*
	 * ====================================================================
	 * Readers
	 * ====================================================================
	 */

	public function type() {
		return $this->type;
	}

	public function canvas() {
		return $this->canvas;
	}

	public function padding() {
		return $this->padding;
	}

	public function plot_area() {
		return $this->plot;
	}

	public function viewbox() {
		return array( 0, 0, $this->canvas['width'], $this->canvas['height'] );
	}

	public function viewbox_string() {
		return '0 0 ' . self::round_coord( $this->canvas['width'] ) . ' ' . self::round_coord( $this->canvas['height'] );
	}

	public function x_domain() {
		return $this->x_domain;
	}

	public function y_domain() {
		return $this->y_domain;
	}

	public function baseline() {
		return $this->baseline;
	}

	/** The baseline as an SVG y coordinate, which is what a fill needs. */
	public function baseline_y() {
		return $this->y( $this->baseline );
	}

	public function x_ticks() {
		return $this->x_ticks;
	}

	public function y_ticks() {
		return $this->y_ticks;
	}

	public function categories() {
		return $this->categories;
	}

	/**
	 * Everything the scale worked out, as a plain array. The diagnostic
	 * dump reads this, and so does anything that wants to log a chart's
	 * geometry without reaching into private state.
	 */
	public function to_array() {
		$series = array();
		foreach ( $this->series() as $index => $entry ) {
			$segments = array();
			if ( ! empty( $entry['segments'] ) && is_array( $entry['segments'] ) ) {
				foreach ( $entry['segments'] as $segment_index => $segment ) {
					$points    = ( is_array( $segment ) && ! empty( $segment['points'] ) ) ? $segment['points'] : array();
					$projected = $this->project( $points );
					$segments[] = array(
						'index'     => $segment_index,
						'style'     => $segment['style'] ?? 'solid',
						'emphasis'  => $segment['emphasis'] ?? 'normal',
						'count'     => count( $points ),
						'data'      => $points,
						'projected' => $projected,
						'path'      => self::path_d( $projected, (string) ( $this->definition['options']['curve'] ?? 'linear' ) ),
					);
				}
			}
			$series[] = array(
				'index'    => $index,
				'id'       => $entry['id'] ?? '',
				'label'    => $entry['label'] ?? '',
				'segments' => $segments,
			);
		}

		return array(
			'type'       => $this->type,
			'canvas'     => $this->canvas,
			'padding'    => $this->padding,
			'plot_area'  => $this->plot,
			'viewbox'    => $this->viewbox_string(),
			'x_domain'   => $this->x_domain,
			'y_domain'   => $this->y_domain,
			'baseline'   => array(
				'value'    => $this->baseline,
				'svg_y'    => $this->baseline_y(),
				'declared' => isset( $this->definition['axes']['y']['baseline'] ),
			),
			'x_ticks'    => $this->x_ticks,
			'y_ticks'    => $this->y_ticks,
			'categories' => $this->categories,
			'series'     => $series,
		);
	}
}

/**
 * The diagnostic dump, reachable by adding ?kdna_debug=1 to any admin
 * URL, or to a front end URL, with a chart id.
 *
 * This exists because Stage 3 computes everything a chart needs and
 * draws none of it. Without a way to look at the numbers, the first
 * sight of whether the maths is right would be a wrong looking picture
 * at Stage 4, which is a poor place to start debugging arithmetic.
 *
 * Administrators only. A chart definition is not secret, but a debug
 * endpoint that anybody can call on any post id is a fishing tool.
 */
class KDNA_Charts_Scale_Debug {

	const QUERY_FLAG = 'kdna_debug';

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_render_admin' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render_front' ) );
	}

	/**
	 * True when the current request is asking for a dump and is allowed
	 * to have one.
	 */
	private static function requested() {
		if ( ! isset( $_GET[ self::QUERY_FLAG ] ) ) {
			return false;
		}
		if ( '1' !== (string) $_GET[ self::QUERY_FLAG ] ) {
			return false;
		}
		return current_user_can( 'manage_options' );
	}

	/**
	 * The chart to dump. Named explicitly with chart, or taken from the
	 * post being edited, so appending the flag to a chart's edit screen
	 * URL is enough.
	 */
	private static function requested_chart_id() {
		foreach ( array( 'chart', 'chart_id', 'post', 'p' ) as $key ) {
			if ( isset( $_GET[ $key ] ) ) {
				$id = absint( $_GET[ $key ] );
				if ( $id > 0 && KDNA_Charts_CPT::POST_TYPE === get_post_type( $id ) ) {
					return $id;
				}
			}
		}
		return 0;
	}

	public static function maybe_render_admin() {
		/*
		 * admin_init fires on admin-ajax.php and admin-post.php too, and
		 * replacing an AJAX response with a diagnostic page would break
		 * whatever made the request rather than help anybody debug it.
		 */
		if ( wp_doing_ajax() ) {
			return;
		}
		global $pagenow;
		if ( in_array( $pagenow, array( 'admin-post.php', 'admin-ajax.php' ), true ) ) {
			return;
		}
		if ( ! self::requested() ) {
			return;
		}
		self::render( self::requested_chart_id() );
	}

	public static function maybe_render_front() {
		if ( ! self::requested() ) {
			return;
		}
		$chart_id = self::requested_chart_id();
		if ( $chart_id <= 0 ) {
			// On the front end an unresolvable id means the flag was not
			// meant for this plugin, so the page renders as normal.
			return;
		}
		self::render( $chart_id );
	}

	/**
	 * Renders the dump and stops. Deliberately terminal: this is a
	 * diagnostic, not a page, and half a dump inside a theme layout is
	 * harder to read than a plain one.
	 */
	private static function render( $chart_id ) {
		$format = isset( $_GET['format'] ) ? sanitize_key( wp_unslash( $_GET['format'] ) ) : 'html';

		$payload = self::build_payload( $chart_id );

		if ( 'json' === $format ) {
			header( 'Content-Type: application/json; charset=utf-8' );
			echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			exit;
		}

		self::render_html( $payload );
		exit;
	}

	private static function build_payload( $chart_id ) {
		if ( $chart_id <= 0 ) {
			return array(
				'error'   => __( 'No chart named. Add chart=ID to the URL, or open a chart edit screen and add the flag there.', 'kdna-charts' ),
				'library' => KDNA_Charts_Data::get_library(),
			);
		}

		$definition = KDNA_Charts_CPT::get_definition( $chart_id );
		if ( empty( $definition ) ) {
			return array(
				'error' => sprintf(
					/* translators: %d: post ID */
					__( 'Post %d is not a chart.', 'kdna-charts' ),
					$chart_id
				),
			);
		}

		$type = (string) ( $definition['type'] ?? '' );

		if ( ! KDNA_Charts_Scale::is_applicable( $type ) ) {
			return array(
				'chart_id'   => $chart_id,
				'title'      => $definition['title'] ?? '',
				'type'       => $type,
				'error'      => sprintf(
					/* translators: %s: chart type label, lower case */
					__( 'A %s chart is not drawn against axes, so it has no scale to compute.', 'kdna-charts' ),
					strtolower( KDNA_Charts_Schema::type_label( $type ) )
				),
				'definition' => $definition,
			);
		}

		$scale = KDNA_Charts_Scale::for_chart( $definition );

		return array_merge(
			array(
				'chart_id' => $chart_id,
				'title'    => $definition['title'] ?? '',
				'engine'   => KDNA_Charts_Data::resolve_engine( $definition['engine'] ?? '' ),
				'options'  => array_merge(
					KDNA_Charts_Schema::default_options( $type ),
					is_array( $definition['options'] ?? null ) ? $definition['options'] : array()
				),
			),
			$scale->to_array()
		);
	}

	private static function render_html( array $payload ) {
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( str_replace( '_', '-', get_locale() ) ); ?>">
<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<meta name="robots" content="noindex, nofollow" />
	<title><?php esc_html_e( 'KDNA Charts scale diagnostic', 'kdna-charts' ); ?></title>
	<style>
		body { margin: 0; padding: 32px; background: #f6f7f7; color: #1d2327;
			font: 14px/1.6 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
		.wrap { max-width: 1100px; margin: 0 auto; }
		h1 { font-size: 20px; margin: 0 0 4px; }
		h2 { font-size: 15px; margin: 32px 0 8px; text-transform: uppercase;
			letter-spacing: 0.06em; color: #50575e; }
		p.lede { margin: 0 0 24px; color: #50575e; }
		table { width: 100%; border-collapse: collapse; background: #fff;
			border: 1px solid #dcdcde; border-radius: 6px; overflow: hidden; }
		th, td { padding: 7px 12px; text-align: left; border-bottom: 1px solid #f0f0f1;
			font-variant-numeric: tabular-nums; }
		th { background: #f6f7f7; font-weight: 600; font-size: 12px;
			text-transform: uppercase; letter-spacing: 0.04em; color: #50575e; }
		tr:last-child td { border-bottom: 0; }
		code, pre { font-family: Consolas, Monaco, monospace; font-size: 12px; }
		pre { background: #fff; border: 1px solid #dcdcde; border-radius: 6px;
			padding: 12px; overflow-x: auto; }
		.error { padding: 12px 16px; background: #fcf0f1; border: 1px solid #d63638;
			border-radius: 6px; color: #8a2424; }
		.muted { color: #787c82; }
	</style>
</head>
<body>
<div class="wrap">
	<h1><?php esc_html_e( 'KDNA Charts scale diagnostic', 'kdna-charts' ); ?></h1>

	<?php if ( ! empty( $payload['error'] ) ) : ?>
		<p class="error"><?php echo esc_html( $payload['error'] ); ?></p>
		<?php if ( ! empty( $payload['library'] ) ) : ?>
			<h2><?php esc_html_e( 'Charts in the library', 'kdna-charts' ); ?></h2>
			<table>
				<tr><th><?php esc_html_e( 'ID', 'kdna-charts' ); ?></th><th><?php esc_html_e( 'Title', 'kdna-charts' ); ?></th></tr>
				<?php foreach ( $payload['library'] as $id => $title ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( add_query_arg( array( 'kdna_debug' => 1, 'chart' => $id ), admin_url( 'admin.php' ) ) ); ?>"><?php echo (int) $id; ?></a></td>
						<td><?php echo esc_html( $title ); ?></td>
					</tr>
				<?php endforeach; ?>
			</table>
		<?php endif; ?>
		</div></body></html>
		<?php
		return;
	endif;
	?>

	<p class="lede">
		<?php
		printf(
			/* translators: 1: chart title, 2: chart type, 3: post ID */
			esc_html__( '%1$s, a %2$s chart, post %3$d.', 'kdna-charts' ),
			'<strong>' . esc_html( (string) $payload['title'] ) . '</strong>',
			esc_html( (string) $payload['type'] ),
			(int) $payload['chart_id']
		);
		?>
		<span class="muted">
			<a href="<?php echo esc_url( add_query_arg( 'format', 'json' ) ); ?>"><?php esc_html_e( 'View as JSON', 'kdna-charts' ); ?></a>
		</span>
	</p>

	<h2><?php esc_html_e( 'Canvas and plot area', 'kdna-charts' ); ?></h2>
	<table>
		<tr><th><?php esc_html_e( 'Property', 'kdna-charts' ); ?></th><th><?php esc_html_e( 'Value', 'kdna-charts' ); ?></th></tr>
		<tr><td><?php esc_html_e( 'viewBox', 'kdna-charts' ); ?></td><td><code><?php echo esc_html( $payload['viewbox'] ); ?></code></td></tr>
		<tr><td><?php esc_html_e( 'Aspect ratio', 'kdna-charts' ); ?></td><td><?php echo esc_html( $payload['canvas']['ratio'] ); ?></td></tr>
		<tr><td><?php esc_html_e( 'Canvas', 'kdna-charts' ); ?></td><td><?php echo esc_html( $payload['canvas']['width'] . ' x ' . $payload['canvas']['height'] ); ?></td></tr>
		<tr><td><?php esc_html_e( 'Padding', 'kdna-charts' ); ?></td><td>
			<?php
			$pad = $payload['padding'];
			echo esc_html( sprintf( 'top %s, right %s, bottom %s, left %s', $pad['top'], $pad['right'], $pad['bottom'], $pad['left'] ) );
			?>
		</td></tr>
		<tr><td><?php esc_html_e( 'Plot area', 'kdna-charts' ); ?></td><td>
			<?php
			$plot = $payload['plot_area'];
			echo esc_html( sprintf( 'x %s, y %s, %s wide, %s high, right edge %s, bottom edge %s', $plot['x'], $plot['y'], $plot['width'], $plot['height'], $plot['right'], $plot['bottom'] ) );
			?>
		</td></tr>
	</table>

	<h2><?php esc_html_e( 'Domains', 'kdna-charts' ); ?></h2>
	<table>
		<tr>
			<th><?php esc_html_e( 'Axis', 'kdna-charts' ); ?></th>
			<th><?php esc_html_e( 'Min', 'kdna-charts' ); ?></th>
			<th><?php esc_html_e( 'Max', 'kdna-charts' ); ?></th>
			<th><?php esc_html_e( 'Source', 'kdna-charts' ); ?></th>
		</tr>
		<?php foreach ( array( 'x' => $payload['x_domain'], 'y' => $payload['y_domain'] ) as $axis => $domain ) : ?>
			<tr>
				<td><?php echo esc_html( $axis ); ?></td>
				<td><?php echo esc_html( (string) $domain['min'] ); ?></td>
				<td><?php echo esc_html( (string) $domain['max'] ); ?></td>
				<td class="muted"><?php echo esc_html( $domain['inferred'] ? __( 'inferred from the data', 'kdna-charts' ) : __( 'stated by the chart', 'kdna-charts' ) ); ?></td>
			</tr>
		<?php endforeach; ?>
		<tr>
			<td><?php esc_html_e( 'baseline', 'kdna-charts' ); ?></td>
			<td colspan="2"><?php echo esc_html( (string) $payload['baseline']['value'] ); ?>
				<span class="muted"><?php echo esc_html( sprintf( 'at SVG y %s', $payload['baseline']['svg_y'] ) ); ?></span></td>
			<td class="muted"><?php echo esc_html( $payload['baseline']['declared'] ? __( 'stated by the chart', 'kdna-charts' ) : __( 'inferred', 'kdna-charts' ) ); ?></td>
		</tr>
	</table>

	<?php foreach ( array( 'x' => $payload['x_ticks'], 'y' => $payload['y_ticks'] ) as $axis => $ticks ) : ?>
		<h2>
			<?php
			printf(
				/* translators: 1: axis name, x or y, 2: number of ticks */
				esc_html__( '%1$s ticks (%2$d)', 'kdna-charts' ),
				esc_html( $axis ),
				count( $ticks )
			);
			?>
		</h2>
		<table>
			<tr>
				<th><?php esc_html_e( 'Value', 'kdna-charts' ); ?></th>
				<th><?php esc_html_e( 'Label', 'kdna-charts' ); ?></th>
				<th><?php esc_html_e( 'Emphasis', 'kdna-charts' ); ?></th>
				<th><?php esc_html_e( 'Rule', 'kdna-charts' ); ?></th>
				<th><?php echo esc_html( 'x' === $axis ? __( 'SVG x', 'kdna-charts' ) : __( 'SVG y', 'kdna-charts' ) ); ?></th>
				<th><?php esc_html_e( 'Source', 'kdna-charts' ); ?></th>
			</tr>
			<?php foreach ( $ticks as $tick ) : ?>
				<tr>
					<td><?php echo esc_html( (string) $tick['value'] ); ?></td>
					<td><?php echo esc_html( (string) $tick['label'] ); ?></td>
					<td><?php echo esc_html( (string) $tick['emphasis'] ); ?></td>
					<td><?php echo esc_html( (string) $tick['rule'] ); ?></td>
					<td><?php echo esc_html( (string) $tick['position'] ); ?></td>
					<td class="muted"><?php echo esc_html( $tick['generated'] ? __( 'generated', 'kdna-charts' ) : __( 'stated', 'kdna-charts' ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		</table>
	<?php endforeach; ?>

	<?php foreach ( $payload['series'] as $series ) : ?>
		<h2>
			<?php
			printf(
				/* translators: 1: series index, 2: series label or id */
				esc_html__( 'Series %1$d, %2$s', 'kdna-charts' ),
				(int) $series['index'],
				esc_html( '' !== $series['label'] ? $series['label'] : ( '' !== $series['id'] ? $series['id'] : __( 'unnamed', 'kdna-charts' ) ) )
			);
			?>
		</h2>
		<?php if ( empty( $series['segments'] ) ) : ?>
			<p class="muted"><?php esc_html_e( 'No plotted segments on this series.', 'kdna-charts' ); ?></p>
		<?php endif; ?>
		<?php foreach ( $series['segments'] as $segment ) : ?>
			<table>
				<tr>
					<th colspan="3">
						<?php
						printf(
							/* translators: 1: segment index, 2: line style, 3: emphasis, 4: point count */
							esc_html__( 'Segment %1$d, %2$s, %3$s, %4$d points', 'kdna-charts' ),
							(int) $segment['index'],
							esc_html( (string) $segment['style'] ),
							esc_html( (string) $segment['emphasis'] ),
							(int) $segment['count']
						);
						?>
					</th>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Data', 'kdna-charts' ); ?></th>
					<th><?php esc_html_e( 'SVG x', 'kdna-charts' ); ?></th>
					<th><?php esc_html_e( 'SVG y', 'kdna-charts' ); ?></th>
				</tr>
				<?php foreach ( $segment['data'] as $index => $pair ) : ?>
					<?php $projected = $segment['projected'][ $index ] ?? null; ?>
					<tr>
						<td><?php echo esc_html( wp_json_encode( $pair ) ); ?></td>
						<td><?php echo esc_html( null === $projected ? '-' : (string) $projected[0] ); ?></td>
						<td><?php echo esc_html( null === $projected ? __( 'gap', 'kdna-charts' ) : (string) $projected[1] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</table>
			<p><strong><?php esc_html_e( 'Path', 'kdna-charts' ); ?></strong></p>
			<pre><?php echo esc_html( (string) $segment['path'] ); ?></pre>
		<?php endforeach; ?>
	<?php endforeach; ?>

	<h2><?php esc_html_e( 'Resolved options', 'kdna-charts' ); ?></h2>
	<pre><?php echo esc_html( (string) wp_json_encode( $payload['options'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></pre>
</div>
</body>
</html>
		<?php
	}
}
