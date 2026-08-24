<?php
/**
 * The SVG engine. Line and area charts at Stage 4, bar and column at
 * Stage 6, pie, donut and stat blocks at Stage 7, and the annotation
 * layer painted over all of it at Stage 5.
 *
 * Everything is rendered in PHP, so the markup arrives inside the page
 * HTML and needs no JavaScript to appear. Every mark is a real DOM
 * element carrying real classes, which is what lets the style engine
 * work entirely through CSS custom properties.
 *
 * ── The rule about colours ─────────────────────────────────────────
 *
 * Nothing in this file writes a colour, a stroke width or a font size
 * into the markup. Not one. Elements carry geometry and classes, and
 * assets/css/kdna-charts.css reads every appearance value from a CSS
 * custom property with a fallback.
 *
 * That is not tidiness, it is the whole architecture. Because nothing
 * visual is baked into the markup, the three tier cascade at Stage 9
 * can set properties on a wrapper and change how a chart looks without
 * anything re-rendering, and a chart that says nothing about its own
 * colours inherits the site palette automatically.
 *
 * The one apparent exception is fill="url(#gradient)" on an area. That
 * is a reference to a definition, not a colour: the stops inside the
 * gradient read their colours from custom properties like everything
 * else.
 *
 * ── Why text is positioned by baseline and not by arithmetic ───────
 *
 * Since font sizes live in CSS, PHP does not know how tall any label
 * is and cannot offset one by half its height. So text alignment is
 * done with text-anchor and dominant-baseline, which resolve at paint
 * time when the size is known. Gaps between the plot and its labels
 * are geometry, so those stay here as constants.
 *
 * @package KDNA_Charts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KDNA_Charts_Renderer_SVG extends KDNA_Charts_Renderer {

	/** Types this engine can draw today. Stages 6 and 7 add the rest. */
	const DRAWABLE_TYPES = array( 'line', 'area' );

	/*
	 * The gaps between the plot and its labels are not declared here.
	 * They come from KDNA_Charts_Scale, which used the same numbers to
	 * decide how much padding to reserve. Two copies would drift, and
	 * the first sign of it would be a label sitting outside the space
	 * kept for it.
	 */

	/** @var KDNA_Charts_Scale|null */
	protected $scale;

	public function engine() {
		return 'svg';
	}

	public function supports() {
		return array(
			// The editorial vocabulary. This is the engine that has it.
			'annotations'   => true,
			'segments'      => true,
			'gradient_fill' => true,
			'tick_emphasis' => true,
			'no_javascript' => true,
			// The things canvas is better at, which this engine is not.
			'tooltips'         => false,
			'legend_toggle'    => false,
			'large_datasets'   => false,
		);
	}

	public function supports_type( $type ) {
		return in_array( $type, self::DRAWABLE_TYPES, true );
	}

	/*
	 * ====================================================================
	 * The chart
	 * ====================================================================
	 */

	protected function render_chart() {
		$this->scale = KDNA_Charts_Scale::for_chart(
			$this->definition,
			isset( $this->args['scale'] ) && is_array( $this->args['scale'] ) ? $this->args['scale'] : array()
		);

		if ( ! $this->scale instanceof KDNA_Charts_Scale ) {
			return $this->placeholder( __( 'This chart type has no axes to draw against.', 'kdna-charts' ) );
		}

		$title_id = $this->id( 'title' );
		$desc_id  = $this->id( 'desc' );

		$layers = array(
			$this->render_defs(),
			$this->render_gridlines(),
			$this->render_series(),
			$this->render_axis_labels(),
			$this->render_axis_titles(),
		);

		$svg = self::tag(
			'svg',
			array(
				'class'           => self::css( 'svg' ),
				'viewBox'         => $this->scale->viewbox_string(),
				'width'           => '100%',
				'role'            => 'img',
				'aria-labelledby' => $title_id . ' ' . $desc_id,
				'xmlns'           => 'http://www.w3.org/2000/svg',
			),
			self::tag( 'title', array( 'id' => $title_id ), esc_html( $this->accessible_title() ) )
			. self::tag( 'desc', array( 'id' => $desc_id ), esc_html( $this->accessible_description() ) )
			. implode( '', array_filter( $layers ) )
		);

		return self::tag( 'div', array( 'class' => self::css( 'frame' ) ), $svg );
	}

	/**
	 * Gradient definitions for the area fills.
	 *
	 * One gradient per emphasis level rather than one per series, since
	 * the emphasis is what decides how strongly an area reads and two
	 * strong series should fill identically.
	 */
	protected function render_defs() {
		if ( ! $this->area_fill_enabled() ) {
			return '';
		}

		$gradients = array();

		foreach ( $this->area_emphases() as $emphasis ) {
			$gradients[] = self::tag(
				'linearGradient',
				array(
					'id' => $this->gradient_id( $emphasis ),
					'x1' => '0',
					'y1' => '0',
					'x2' => '0',
					'y2' => '1',
				),
				self::tag(
					'stop',
					array(
						'offset' => '0',
						'class'  => self::css( 'area-stop', array( 'top', $emphasis ) ),
					)
				)
				. self::tag(
					'stop',
					array(
						'offset' => '1',
						'class'  => self::css( 'area-stop', array( 'bottom', $emphasis ) ),
					)
				)
			);
		}

		if ( empty( $gradients ) ) {
			return '';
		}

		return self::tag( 'defs', array(), implode( '', $gradients ) );
	}

	/**
	 * Gridlines, one per tick that asks for a rule.
	 *
	 * There is no axis spine. The reference design draws none, and a
	 * rule at a tick the chart chose says more than a line along an
	 * edge nobody is reading values from. A site that wants one sets a
	 * solid rule on the tick it belongs at.
	 */
	protected function render_gridlines() {
		$plot  = $this->scale->plot_area();
		$lines = array();

		foreach ( $this->scale->y_ticks() as $tick ) {
			$rule = (string) ( $tick['rule'] ?? 'none' );
			if ( 'none' === $rule ) {
				continue;
			}
			$lines[] = self::tag(
				'line',
				array(
					'class' => self::css( 'gridline', array( 'horizontal', $rule, $tick['emphasis'] ?? 'normal' ) ),
					'x1'    => $plot['x'],
					'y1'    => $tick['position'],
					'x2'    => $plot['right'],
					'y2'    => $tick['position'],
				)
			);
		}

		foreach ( $this->scale->x_ticks() as $tick ) {
			$rule = (string) ( $tick['rule'] ?? 'none' );
			if ( 'none' === $rule ) {
				continue;
			}
			$lines[] = self::tag(
				'line',
				array(
					'class' => self::css( 'gridline', array( 'vertical', $rule, $tick['emphasis'] ?? 'normal' ) ),
					'x1'    => $tick['position'],
					'y1'    => $plot['y'],
					'x2'    => $tick['position'],
					'y2'    => $plot['bottom'],
				)
			);
		}

		if ( empty( $lines ) ) {
			return '';
		}

		return self::tag(
			'g',
			array(
				'class'       => self::css( 'gridlines' ),
				'aria-hidden' => 'true',
			),
			implode( '', $lines )
		);
	}

	/**
	 * The data.
	 *
	 * Each series contributes at most one area path and one stroked
	 * path per segment. The area is built from the whole series rather
	 * than per segment, so a line made of a dotted projection and a
	 * solid measurement fills as a single shape with no seam at the
	 * join, while each segment keeps its own line character.
	 */
	protected function render_series() {
		$groups = array();
		$curve  = (string) $this->option( 'curve', 'linear' );
		$fill   = $this->area_fill_enabled();

		foreach ( $this->series() as $index => $series ) {
			$segments = $this->segments_of( $series );
			if ( empty( $segments ) ) {
				continue;
			}

			$parts = array();

			if ( $fill ) {
				$area = $this->render_series_area( $series, $curve );
				if ( '' !== $area ) {
					$parts[] = $area;
				}
			}

			foreach ( $segments as $segment_index => $segment ) {
				$line = $this->render_segment( $segment, $curve, $segment_index );
				if ( '' !== $line ) {
					$parts[] = $line;
				}
			}

			if ( empty( $parts ) ) {
				continue;
			}

			$id = trim( (string) ( $series['id'] ?? '' ) );

			$groups[] = self::tag(
				'g',
				array(
					'class'          => self::css( 'series', array( $this->emphasis_of( $series ) ) ),
					'data-series-id' => '' !== $id ? $id : null,
					'data-series'    => (string) $index,
				),
				implode( '', $parts )
			);
		}

		if ( empty( $groups ) ) {
			return '';
		}

		return self::tag(
			'g',
			array(
				'class'       => self::css( 'plot' ),
				'aria-hidden' => 'true',
			),
			implode( '', $groups )
		);
	}

	protected function render_series_area( array $series, $curve ) {
		$points = $this->series_points( $series );
		if ( count( $points ) < 2 ) {
			return '';
		}

		$d = KDNA_Charts_Scale::area_d(
			$this->scale->project( $points ),
			$this->scale->baseline_y(),
			$curve
		);

		if ( '' === $d ) {
			return '';
		}

		$emphasis = $this->emphasis_of( $series );

		return self::tag(
			'path',
			array(
				'class' => self::css( 'area', array( $emphasis ) ),
				'd'     => $d,
				/*
				 * A paint reference, not a colour. The stops inside the
				 * gradient read theirs from custom properties like
				 * everything else does.
				 */
				'fill'  => 'url(#' . $this->gradient_id( $emphasis ) . ')',
			)
		);
	}

	protected function render_segment( array $segment, $curve, $index ) {
		$points = isset( $segment['points'] ) && is_array( $segment['points'] ) ? $segment['points'] : array();
		if ( empty( $points ) ) {
			return '';
		}

		$d = KDNA_Charts_Scale::path_d( $this->scale->project( $points ), $curve );
		if ( '' === $d ) {
			return '';
		}

		$style    = $this->line_style_of( $segment );
		$emphasis = $this->emphasis_of( $segment );

		return self::tag(
			'path',
			array(
				'class'        => self::css( 'line', array( $style, $emphasis ) ),
				'd'            => $d,
				'data-segment' => (string) $index,
			)
		);
	}

	/*
	 * ====================================================================
	 * Axes
	 * ====================================================================
	 */

	/**
	 * Tick labels, each carrying its own emphasis.
	 *
	 * This is how the collagen chart darkens Year 0, 5 and 20 while
	 * fading 10 and 15: the emphasis rides on the class, the class
	 * picks a custom property, and the property picks the colour.
	 */
	protected function render_axis_labels() {
		$plot   = $this->scale->plot_area();
		$labels = array();

		foreach ( $this->scale->y_ticks() as $tick ) {
			$label = (string) ( $tick['label'] ?? '' );
			if ( '' === $label ) {
				continue;
			}
			$labels[] = self::tag(
				'text',
				array(
					'class'             => self::css( 'axis-label', array( 'y', $tick['emphasis'] ?? 'normal' ) ),
					'x'                 => $plot['x'] - KDNA_Charts_Scale::TICK_LABEL_GAP_X,
					'y'                 => $tick['position'],
					'text-anchor'       => 'end',
					'dominant-baseline' => 'central',
				),
				esc_html( $label )
			);
		}

		foreach ( $this->scale->x_ticks() as $tick ) {
			$label = (string) ( $tick['label'] ?? '' );
			if ( '' === $label ) {
				continue;
			}
			$labels[] = self::tag(
				'text',
				array(
					'class'             => self::css( 'axis-label', array( 'x', $tick['emphasis'] ?? 'normal' ) ),
					'x'                 => $tick['position'],
					'y'                 => $plot['bottom'] + KDNA_Charts_Scale::TICK_LABEL_GAP_Y,
					'text-anchor'       => 'middle',
					'dominant-baseline' => 'hanging',
				),
				esc_html( $label )
			);
		}

		if ( empty( $labels ) ) {
			return '';
		}

		return self::tag(
			'g',
			array(
				'class'       => self::css( 'axis-labels' ),
				'aria-hidden' => 'true',
			),
			implode( '', $labels )
		);
	}

	/**
	 * The two axis titles.
	 *
	 * Both are anchored to the edge of the canvas rather than stacked a
	 * fixed distance beyond the tick labels. That is the difference
	 * between a title that always clears the labels and one that
	 * collides with them the moment a site raises the label size: the
	 * canvas edge is a fixed known point, and the far side of a label is
	 * not, because PHP cannot measure text the browser has not drawn.
	 */
	protected function render_axis_titles() {
		$plot   = $this->scale->plot_area();
		$canvas = $this->scale->canvas();
		$inset  = KDNA_Charts_Scale::EDGE_INSET;
		$titles = array();

		$x_title = trim( (string) ( $this->definition['axes']['x']['label'] ?? '' ) );
		if ( '' !== $x_title ) {
			$titles[] = self::tag(
				'text',
				array(
					'class'       => self::css( 'axis-title', array( 'x' ) ),
					'x'           => $plot['x'] + $plot['width'] / 2,
					'y'           => $canvas['height'] - $inset,
					'text-anchor' => 'middle',
				),
				esc_html( $x_title )
			);
		}

		$y_title = trim( (string) ( $this->definition['axes']['y']['label'] ?? '' ) );
		if ( '' !== $y_title ) {
			/*
			 * Rotated a quarter turn anticlockwise about a point on the
			 * left edge, so it reads bottom to top with its middle level
			 * with the middle of the plot. A hanging baseline puts the
			 * glyphs on the inward side of that point, which after the
			 * rotation means to the right of the edge rather than off
			 * the canvas.
			 *
			 * The rotation is geometry, so it belongs here. How the text
			 * looks once rotated is still entirely CSS.
			 */
			$x = $inset;
			$y = $plot['y'] + $plot['height'] / 2;
			$titles[] = self::tag(
				'text',
				array(
					'class'             => self::css( 'axis-title', array( 'y' ) ),
					'x'                 => $x,
					'y'                 => $y,
					'text-anchor'       => 'middle',
					'dominant-baseline' => 'hanging',
					'transform'         => 'rotate(-90 ' . KDNA_Charts_Scale::round_coord( $x ) . ' ' . KDNA_Charts_Scale::round_coord( $y ) . ')',
				),
				esc_html( $y_title )
			);
		}

		if ( empty( $titles ) ) {
			return '';
		}

		return self::tag(
			'g',
			array(
				'class'       => self::css( 'axis-titles' ),
				'aria-hidden' => 'true',
			),
			implode( '', $titles )
		);
	}

	/*
	 * ====================================================================
	 * Small readers
	 * ====================================================================
	 */

	/**
	 * True when this chart fills beneath its lines. An area chart does
	 * unless it says otherwise, a line chart does not unless it says so.
	 * The schema already defaults area_fill per type, so this is simply
	 * reading the resolved answer.
	 */
	protected function area_fill_enabled() {
		return (bool) $this->option( 'area_fill', 'area' === $this->type() );
	}

	/** The emphasis levels actually in use, so unused gradients are not emitted. */
	protected function area_emphases() {
		$emphases = array();
		foreach ( $this->series() as $series ) {
			if ( empty( $this->series_points( $series ) ) ) {
				continue;
			}
			$emphasis = $this->emphasis_of( $series );
			if ( ! in_array( $emphasis, $emphases, true ) ) {
				$emphases[] = $emphasis;
			}
		}
		return $emphases;
	}

	protected function gradient_id( $emphasis ) {
		return $this->id( 'area-' . $emphasis );
	}

	protected function segments_of( array $series ) {
		if ( empty( $series['segments'] ) || ! is_array( $series['segments'] ) ) {
			return array();
		}
		$out = array();
		foreach ( $series['segments'] as $segment ) {
			if ( is_array( $segment ) && ! empty( $segment['points'] ) && is_array( $segment['points'] ) ) {
				$out[] = $segment;
			}
		}
		return $out;
	}

	/**
	 * A series takes the emphasis of its strongest segment when it does
	 * not state one, because the area beneath a line whose measured
	 * half is emphasised should read as emphasised too.
	 */
	protected function emphasis_of( array $thing ) {
		$declared = isset( $thing['emphasis'] ) ? (string) $thing['emphasis'] : '';
		if ( in_array( $declared, KDNA_Charts_Schema::EMPHASIS, true ) ) {
			return $declared;
		}

		if ( ! empty( $thing['segments'] ) && is_array( $thing['segments'] ) ) {
			$found = array();
			foreach ( $thing['segments'] as $segment ) {
				if ( is_array( $segment ) && isset( $segment['emphasis'] ) ) {
					$found[] = (string) $segment['emphasis'];
				}
			}
			foreach ( array( 'strong', 'normal', 'muted' ) as $level ) {
				if ( in_array( $level, $found, true ) ) {
					return $level;
				}
			}
		}

		return 'normal';
	}

	protected function line_style_of( array $segment ) {
		$style = isset( $segment['style'] ) ? (string) $segment['style'] : '';
		return in_array( $style, KDNA_Charts_Schema::LINE_STYLES, true ) ? $style : 'solid';
	}
}
