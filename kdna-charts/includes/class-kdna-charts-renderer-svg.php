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

	/** Types this engine can draw today. Stage 7 adds pie, donut and stat. */
	const DRAWABLE_TYPES = array( 'line', 'area', 'bar', 'column' );

	/** Gap between a bar's end and a value label sitting beyond it. */
	const VALUE_LABEL_GAP = 12;

	/** How far inside a bar's end an inside label sits. */
	const VALUE_LABEL_INSET = 14;

	/** How many series tones the stylesheet ships before they repeat. */
	const PALETTE_SIZE = 6;

	/**
	 * The first tone in the ramp light enough that a reversed label
	 * would disappear on it. Matches where the shipped ramp turns pale.
	 */
	const PALETTE_PALE_FROM = 4;

	/*
	 * The gaps between the plot and its labels are not declared here.
	 * They come from KDNA_Charts_Scale, which used the same numbers to
	 * decide how much padding to reserve. Two copies would drift, and
	 * the first sign of it would be a label sitting outside the space
	 * kept for it.
	 */

	/** @var KDNA_Charts_Scale|null */
	protected $scale;

	/** @var KDNA_Charts_Annotations|null */
	protected $annotations;

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

		$this->annotations = KDNA_Charts_Schema::draws_annotations( $this->type() )
			? new KDNA_Charts_Annotations( $this->definition, $this->scale, $this->uid )
			: null;

		$title_id = $this->id( 'title' );
		$desc_id  = $this->id( 'desc' );

		/*
		 * The order is the drawing order, and two of these are placed
		 * where they are for a reason.
		 *
		 * Marker lines go under the data, because a dashed rule drawn
		 * over the series would cut through the very shape the reader is
		 * following. Everything else in the annotation layer goes last,
		 * over everything, because a callout covered by a gridline is a
		 * callout nobody reads.
		 */
		$layers = array(
			$this->render_defs(),
			$this->render_gridlines(),
			$this->annotations ? $this->annotations->render_under() : '',
			$this->render_series(),
			$this->render_axis_labels(),
			$this->render_axis_titles(),
			$this->annotations ? $this->annotations->render_over() : '',
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
		$lines = array();

		foreach ( array( 'x', 'y' ) as $axis ) {
			$ticks = ( 'x' === $axis ) ? $this->scale->x_ticks() : $this->scale->y_ticks();
			foreach ( $ticks as $tick ) {
				$line = $this->gridline( $axis, $tick );
				if ( '' !== $line ) {
					$lines[] = $line;
				}
			}
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
	 * One gridline, drawn perpendicular to the axis that asked for it.
	 *
	 * That one rule is all the orientation handling this needs. On a
	 * column chart the value axis runs down, so its rules run across;
	 * turn the chart on its side and the same rules run down, without a
	 * word about bars or columns appearing here.
	 */
	protected function gridline( $axis, array $tick ) {
		$rule = (string) ( $tick['rule'] ?? 'none' );
		if ( 'none' === $rule ) {
			return '';
		}

		$plot      = $this->scale->plot_area();
		$at        = $tick['position'];
		$across    = 'across' === $this->scale->axis_direction( $axis );
		$modifiers = array( $across ? 'vertical' : 'horizontal', $rule, $tick['emphasis'] ?? 'normal' );

		return self::tag(
			'line',
			array(
				'class' => self::css( 'gridline', $modifiers ),
				'x1'    => $across ? $at : $plot['x'],
				'y1'    => $across ? $plot['y'] : $at,
				'x2'    => $across ? $at : $plot['right'],
				'y2'    => $across ? $plot['bottom'] : $at,
			)
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
		$marks = in_array( $this->type(), array( 'bar', 'column' ), true )
			? $this->render_bars()
			: $this->render_lines();

		if ( '' === $marks ) {
			return '';
		}

		return self::tag(
			'g',
			array(
				'class'       => self::css( 'plot' ),
				'aria-hidden' => 'true',
			),
			$marks
		);
	}

	/**
	 * Line and area charts.
	 */
	protected function render_lines() {
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

		return implode( '', $groups );
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
	 * Bar and column
	 * ====================================================================
	 *
	 * One body of code draws both. The scale settles which screen
	 * direction each axis runs along, and everything here is written in
	 * terms of the category axis and the value axis rather than of x
	 * and y. Turning a chart on its side then costs nothing, and the
	 * two orientations cannot drift apart, because there is only one of
	 * them.
	 */

	protected function render_bars() {
		$categories = $this->scale->categories();
		if ( empty( $categories ) ) {
			return '';
		}

		$series = $this->bar_series();
		if ( empty( $series ) ) {
			return '';
		}

		$stacked = $this->scale->is_stacked();
		$count   = count( $series );
		$groups  = array();
		$labels  = array();

		/*
		 * Running totals, one pair per category. Positives stack away
		 * from the baseline one way and negatives the other, so each
		 * category needs both and they never meet.
		 */
		$stack_up   = array_fill( 0, count( $categories ), 0.0 );
		$stack_down = array_fill( 0, count( $categories ), 0.0 );

		foreach ( $series as $position => $entry ) {
			$bars = array();
			$data = array_values( $entry['data'] );

			foreach ( $categories as $index => $category ) {
				$datum = isset( $data[ $index ] ) && is_array( $data[ $index ] ) ? $data[ $index ] : null;
				if ( null === $datum || ! isset( $datum['value'] ) || ! is_numeric( $datum['value'] ) ) {
					continue;
				}

				$value = (float) $datum['value'];

				if ( $stacked ) {
					$running = ( $value >= 0 ) ? $stack_up[ $index ] : $stack_down[ $index ];
					$from    = $running;
					$to      = $running + $value;
					if ( $value >= 0 ) {
						$stack_up[ $index ] = $to;
					} else {
						$stack_down[ $index ] = $to;
					}
					$band = $this->scale->band( $index, 0, 1 );
					// The last series to stack in this direction is the
					// one whose end is exposed, so it takes the rounding.
					$outermost = $this->is_outermost_in_stack( $series, $position, $index, $value );
				} else {
					$from      = $this->scale->baseline();
					$to        = $value;
					$band      = $this->scale->band( $index, $position, $count );
					$outermost = true;
				}

				$rect = $this->bar_rect( $band, $from, $to );
				if ( $rect['width'] <= 0 || $rect['height'] <= 0 ) {
					continue;
				}

				/*
				 * Emphasis is only put on a bar when the definition asked
				 * for it. An always present modifier would be a modifier
				 * that always wins, and the series palette below would
				 * never get a look in.
				 *
				 * This is the absent means inherit rule the whole plugin
				 * runs on, applied one level down.
				 */
				$emphasis = $this->declared_emphasis( $datum );
				if ( '' === $emphasis ) {
					$emphasis = $this->declared_emphasis( $entry );
				}

				$modifiers = array( $emphasis, $value < 0 ? 'negative' : 'positive' );

				/*
				 * A chart with one series says what it means through
				 * emphasis. A chart with several has to tell them apart
				 * first, so each takes a tone of its own, and emphasis
				 * still overrides it wherever a definition asks.
				 */
				if ( $count > 1 ) {
					$modifiers[] = 'series-' . ( ( $position % self::PALETTE_SIZE ) + 1 );
				}

				$bars[] = self::tag(
					'path',
					array(
						'class'         => self::css( 'bar', $modifiers ),
						'd'             => $this->bar_path( $rect, $outermost ? $this->corner_radius() : 0, $value >= 0 ),
						'data-category' => (string) $index,
					)
				);

				$label = $this->value_label( $datum, $rect, $value, $stacked, $count > 1 ? $position : -1 );
				if ( '' !== $label ) {
					$labels[] = $label;
				}
			}

			if ( empty( $bars ) ) {
				continue;
			}

			$id = trim( (string) ( $entry['id'] ?? '' ) );

			$groups[] = self::tag(
				'g',
				array(
					'class'          => self::css( 'series', array( $this->emphasis_of( $entry ) ) ),
					'data-series-id' => '' !== $id ? $id : null,
					'data-series'    => (string) $position,
				),
				implode( '', $bars )
			);
		}

		if ( empty( $groups ) ) {
			return '';
		}

		/*
		 * Value labels are collected and emitted after every bar, so a
		 * label never disappears behind a bar drawn later. Inside labels
		 * on a stacked chart would otherwise vanish under the segment
		 * above them.
		 */
		return implode( '', $groups )
			. ( empty( $labels )
				? ''
				: self::tag( 'g', array( 'class' => self::css( 'value-labels' ) ), implode( '', $labels ) ) );
	}

	/**
	 * Series that actually carry categorical data, in order.
	 */
	protected function bar_series() {
		$out = array();
		foreach ( $this->series() as $entry ) {
			if ( is_array( $entry ) && ! empty( $entry['data'] ) && is_array( $entry['data'] ) ) {
				$out[] = $entry;
			}
		}
		return $out;
	}

	/**
	 * True when no later series adds to this category's stack in the
	 * same direction, which makes this segment the exposed end.
	 */
	protected function is_outermost_in_stack( array $series, $position, $index, $value ) {
		$count = count( $series );
		for ( $i = $position + 1; $i < $count; $i++ ) {
			$data = array_values( $series[ $i ]['data'] );
			if ( ! isset( $data[ $index ] ) || ! is_array( $data[ $index ] ) ) {
				continue;
			}
			if ( ! isset( $data[ $index ]['value'] ) || ! is_numeric( $data[ $index ]['value'] ) ) {
				continue;
			}
			$later = (float) $data[ $index ]['value'];
			if ( 0.0 === $later ) {
				continue;
			}
			if ( ( $later >= 0 ) === ( $value >= 0 ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Turns a band and a pair of values into a screen rectangle.
	 *
	 * The band gives the thickness of the bar and where it sits across
	 * the category axis; the two values give the ends along the value
	 * axis. Which of those is a width and which is a height is the only
	 * thing the orientation decides.
	 *
	 * @return array Keys x, y, width, height, far, near, thickness.
	 */
	protected function bar_rect( array $band, $from, $to ) {
		$near = $this->scale->position( 'y', $from );
		$far  = $this->scale->position( 'y', $to );

		$start  = min( $near, $far );
		$length = abs( $far - $near );

		if ( $this->scale->is_horizontal() ) {
			return array(
				'x'         => $start,
				'y'         => $band['start'],
				'width'     => $length,
				'height'    => $band['thickness'],
				'near'      => $near,
				'far'       => $far,
				'thickness' => $band['thickness'],
			);
		}

		return array(
			'x'         => $band['start'],
			'y'         => $start,
			'width'     => $band['thickness'],
			'height'    => $length,
			'near'      => $near,
			'far'       => $far,
			'thickness' => $band['thickness'],
		);
	}

	/**
	 * The path for one bar, with the far end optionally rounded.
	 *
	 * Only the exposed end is rounded. Rounding the end that sits on
	 * the baseline would lift the bar off it, and rounding the join
	 * between two stacked segments would leave a notch, so the corners
	 * that meet something else stay square.
	 *
	 * @param array $rect     From bar_rect().
	 * @param float $radius   Requested corner radius.
	 * @param bool  $positive Whether the value grows away from the baseline.
	 */
	protected function bar_path( array $rect, $radius, $positive ) {
		$x = $rect['x'];
		$y = $rect['y'];
		$w = $rect['width'];
		$h = $rect['height'];

		// A radius larger than the bar is a radius the bar cannot hold.
		$radius = max( 0.0, min( (float) $radius, $rect['thickness'] / 2, ( $this->scale->is_horizontal() ? $w : $h ) ) );

		if ( $radius <= 0 ) {
			return 'M ' . $this->xy( $x, $y )
				. ' h ' . KDNA_Charts_Scale::round_coord( $w )
				. ' v ' . KDNA_Charts_Scale::round_coord( $h )
				. ' h ' . KDNA_Charts_Scale::round_coord( -$w )
				. ' Z';
		}

		$r = $radius;

		if ( $this->scale->is_horizontal() ) {
			if ( $positive ) {
				// Rounded on the right.
				return 'M ' . $this->xy( $x, $y )
					. ' H ' . KDNA_Charts_Scale::round_coord( $x + $w - $r )
					. ' A ' . $this->xy( $r, $r ) . ' 0 0 1 ' . $this->xy( $x + $w, $y + $r )
					. ' V ' . KDNA_Charts_Scale::round_coord( $y + $h - $r )
					. ' A ' . $this->xy( $r, $r ) . ' 0 0 1 ' . $this->xy( $x + $w - $r, $y + $h )
					. ' H ' . KDNA_Charts_Scale::round_coord( $x )
					. ' Z';
			}
			// Rounded on the left.
			return 'M ' . $this->xy( $x + $w, $y )
				. ' H ' . KDNA_Charts_Scale::round_coord( $x + $r )
				. ' A ' . $this->xy( $r, $r ) . ' 0 0 0 ' . $this->xy( $x, $y + $r )
				. ' V ' . KDNA_Charts_Scale::round_coord( $y + $h - $r )
				. ' A ' . $this->xy( $r, $r ) . ' 0 0 0 ' . $this->xy( $x + $r, $y + $h )
				. ' H ' . KDNA_Charts_Scale::round_coord( $x + $w )
				. ' Z';
		}

		if ( $positive ) {
			// Rounded on the top.
			return 'M ' . $this->xy( $x, $y + $h )
				. ' V ' . KDNA_Charts_Scale::round_coord( $y + $r )
				. ' A ' . $this->xy( $r, $r ) . ' 0 0 1 ' . $this->xy( $x + $r, $y )
				. ' H ' . KDNA_Charts_Scale::round_coord( $x + $w - $r )
				. ' A ' . $this->xy( $r, $r ) . ' 0 0 1 ' . $this->xy( $x + $w, $y + $r )
				. ' V ' . KDNA_Charts_Scale::round_coord( $y + $h )
				. ' Z';
		}

		// Rounded on the bottom.
		return 'M ' . $this->xy( $x, $y )
			. ' V ' . KDNA_Charts_Scale::round_coord( $y + $h - $r )
			. ' A ' . $this->xy( $r, $r ) . ' 0 0 0 ' . $this->xy( $x + $r, $y + $h )
			. ' H ' . KDNA_Charts_Scale::round_coord( $x + $w - $r )
			. ' A ' . $this->xy( $r, $r ) . ' 0 0 0 ' . $this->xy( $x + $w, $y + $h - $r )
			. ' V ' . KDNA_Charts_Scale::round_coord( $y )
			. ' Z';
	}

	/**
	 * The figure printed on or beside a bar.
	 *
	 * An inside label needs the bar to be long enough to hold it, and
	 * PHP cannot measure text, so a bar shorter than a rough guess at
	 * the label's length puts its figure outside instead. A number half
	 * hanging off the end of its own bar is worse than one sitting
	 * beyond it.
	 */
	protected function value_label( array $datum, array $rect, $value, $stacked, $series_position = -1 ) {
		$mode = $this->one_of( $this->option( 'value_labels', 'none' ), KDNA_Charts_Schema::VALUE_LABELS, 'none' );
		if ( 'none' === $mode ) {
			return '';
		}

		$text = KDNA_Charts_Scale::format_number( $value )
			. (string) ( $datum['suffix'] ?? '' );
		if ( '' === trim( $text ) ) {
			return '';
		}

		$horizontal = $this->scale->is_horizontal();
		$length     = $horizontal ? $rect['width'] : $rect['height'];
		$positive   = $value >= 0;

		$needed = mb_strlen( $text ) * 0.62 * KDNA_Charts_Scale::ASSUMED_LABEL_SIZE;
		$inside = ( 'inside' === $mode ) && ( $length > $needed + self::VALUE_LABEL_INSET * 2 );

		if ( $horizontal ) {
			$x = $inside
				? ( $positive ? $rect['far'] - self::VALUE_LABEL_INSET : $rect['far'] + self::VALUE_LABEL_INSET )
				: ( $positive ? $rect['far'] + self::VALUE_LABEL_GAP : $rect['far'] - self::VALUE_LABEL_GAP );
			$y        = $rect['y'] + $rect['height'] / 2;
			$anchor   = $positive ? ( $inside ? 'end' : 'start' ) : ( $inside ? 'start' : 'end' );
			$baseline = 'central';
		} else {
			$x = $rect['x'] + $rect['width'] / 2;
			$y = $inside
				? ( $positive ? $rect['far'] + self::VALUE_LABEL_INSET : $rect['far'] - self::VALUE_LABEL_INSET )
				: ( $positive ? $rect['far'] - self::VALUE_LABEL_GAP : $rect['far'] + self::VALUE_LABEL_GAP );
			$anchor   = 'middle';
			$baseline = $inside
				? ( $positive ? 'hanging' : 'text-after-edge' )
				: ( $positive ? 'text-after-edge' : 'hanging' );
		}

		$modifiers = array( $inside ? 'inside' : 'above', $stacked ? 'stacked' : 'grouped' );

		/*
		 * A figure printed inside a bar is drawn in the reversed colour,
		 * which only works while the bar is dark. The lighter half of
		 * the series ramp is told so here rather than guessed at in CSS,
		 * because only the renderer knows which tone a bar took.
		 */
		if ( $inside && $series_position >= 0 && ( ( $series_position % self::PALETTE_SIZE ) + 1 ) >= self::PALETTE_PALE_FROM ) {
			$modifiers[] = 'pale';
		}

		return self::tag(
			'text',
			array(
				'class'             => self::css( 'value-label', $modifiers ),
				'x'                 => $x,
				'y'                 => $y,
				'text-anchor'       => $anchor,
				'dominant-baseline' => $baseline,
			),
			esc_html( $text )
		);
	}

	/**
	 * The emphasis a definition actually stated, or '' when it said
	 * nothing. Unlike emphasis_of(), this never invents one.
	 */
	protected function declared_emphasis( $thing ) {
		if ( ! is_array( $thing ) || ! isset( $thing['emphasis'] ) ) {
			return '';
		}
		$emphasis = strtolower( trim( (string) $thing['emphasis'] ) );
		return in_array( $emphasis, KDNA_Charts_Schema::EMPHASIS, true ) ? $emphasis : '';
	}

	protected function corner_radius() {
		$radius = $this->option( 'corner_radius', 0 );
		return is_numeric( $radius ) ? max( 0.0, (float) $radius ) : 0.0;
	}

	protected function one_of( $value, array $allowed, $fallback ) {
		$value = is_string( $value ) ? strtolower( trim( $value ) ) : '';
		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	protected function xy( $x, $y ) {
		return KDNA_Charts_Scale::round_coord( $x ) . ' ' . KDNA_Charts_Scale::round_coord( $y );
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
		$labels = array();

		foreach ( array( 'x', 'y' ) as $axis ) {
			$ticks = ( 'x' === $axis ) ? $this->scale->x_ticks() : $this->scale->y_ticks();
			foreach ( $ticks as $tick ) {
				$label = $this->axis_label( $axis, $tick );
				if ( '' !== $label ) {
					$labels[] = $label;
				}
			}
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
	 * One tick label. An axis running across the plot is labelled
	 * beneath it, and one running down the plot is labelled to its left,
	 * which is true of both orientations without either being named.
	 */
	protected function axis_label( $axis, array $tick ) {
		$label = (string) ( $tick['label'] ?? '' );
		if ( '' === $label ) {
			return '';
		}

		$plot   = $this->scale->plot_area();
		$across = 'across' === $this->scale->axis_direction( $axis );

		return self::tag(
			'text',
			array(
				'class'             => self::css( 'axis-label', array( $axis, $tick['emphasis'] ?? 'normal' ) ),
				'x'                 => $across ? $tick['position'] : $plot['x'] - KDNA_Charts_Scale::TICK_LABEL_GAP_X,
				'y'                 => $across ? $plot['bottom'] + KDNA_Charts_Scale::TICK_LABEL_GAP_Y : $tick['position'],
				'text-anchor'       => $across ? 'middle' : 'end',
				'dominant-baseline' => $across ? 'hanging' : 'central',
			),
			esc_html( $label )
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

		foreach ( array( 'x', 'y' ) as $axis ) {
			$title = trim( (string) ( $this->definition['axes'][ $axis ]['label'] ?? '' ) );
			if ( '' === $title ) {
				continue;
			}

			if ( 'across' === $this->scale->axis_direction( $axis ) ) {
				$titles[] = self::tag(
					'text',
					array(
						'class'       => self::css( 'axis-title', array( $axis, 'across' ) ),
						'x'           => $plot['x'] + $plot['width'] / 2,
						'y'           => $canvas['height'] - $inset,
						'text-anchor' => 'middle',
					),
					esc_html( $title )
				);
				continue;
			}

			/*
			 * Rotated a quarter turn anticlockwise about a point on the
			 * left edge, so it reads bottom to top with its middle level
			 * with the middle of the plot. A hanging baseline puts the
			 * glyphs on the inward side of that point, which after the
			 * rotation means to the right of the edge rather than off
			 * the canvas.
			 */
			$x = $inset;
			$y = $plot['y'] + $plot['height'] / 2;
			$titles[] = self::tag(
				'text',
				array(
					'class'             => self::css( 'axis-title', array( $axis, 'down' ) ),
					'x'                 => $x,
					'y'                 => $y,
					'text-anchor'       => 'middle',
					'dominant-baseline' => 'hanging',
					'transform'         => 'rotate(-90 ' . KDNA_Charts_Scale::round_coord( $x ) . ' ' . KDNA_Charts_Scale::round_coord( $y ) . ')',
				),
				esc_html( $title )
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

	/**
	 * The annotation layer in words, for the accessible description.
	 *
	 * Built from the definition rather than the annotation object,
	 * because the description is assembled before the chart is drawn.
	 */
	protected function describe_annotations() {
		if ( ! KDNA_Charts_Schema::draws_annotations( $this->type() ) ) {
			return '';
		}
		$scale = KDNA_Charts_Scale::for_chart( $this->definition );
		if ( ! $scale instanceof KDNA_Charts_Scale ) {
			return '';
		}
		$annotations = new KDNA_Charts_Annotations( $this->definition, $scale, $this->uid );
		return $annotations->describe();
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
