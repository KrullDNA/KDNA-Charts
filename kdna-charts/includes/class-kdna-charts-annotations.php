<?php
/**
 * The annotation layer. The editorial vocabulary, and the reason this
 * plugin exists rather than a Chart.js wrapper.
 *
 * Markers with headings, emphasised points with labels, large number
 * callouts with leader lines, and free floating notes. Every other
 * charting plugin makes these somebody's job to position by hand in
 * pixel coordinates. Here they are placed by the renderer, from a
 * definition that says what a callout means and what it points at,
 * never where it should sit.
 *
 * ── Two problems worth naming ──────────────────────────────────────
 *
 * The first is that PHP cannot measure text. Font sizes live in CSS as
 * custom properties, and there is no metric available server side, so
 * every box here is an estimate from a character count and an assumed
 * size. The assumed sizes match the stylesheet's defaults exactly, and
 * they are listed in one constant so the two can be checked against
 * each other. This is the same bargain the scale engine makes for
 * padding, and it is the only place the two languages have to agree.
 *
 * The second is that annotations collide. A note and a marker heading
 * both want the space above the plot; a callout wants the empty
 * quarter of the chart, and so does the next callout. So nothing is
 * placed blindly: labels whose position the author fixed get nudged
 * clear, and callouts, which carry a leader line and can therefore sit
 * anywhere, are placed by looking for the emptiest room near what they
 * point at.
 *
 * Nothing is ever dropped for want of space. A label that cannot find
 * a clear position is drawn in the least bad one, because a missing
 * annotation is a missing argument and silence is the worst outcome.
 *
 * @package KDNA_Charts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KDNA_Charts_Annotations {

	/**
	 * What the placement maths assumes each kind of text is drawn at,
	 * in canvas units.
	 *
	 * These are not the sizes. The sizes are in kdna-charts.css, as
	 * custom properties a site can change. These are what the geometry
	 * reserves room for, and they are set to the stylesheet's defaults.
	 * Raise a size well past its assumption and the collision maths
	 * starts guessing low, which shows up as labels sitting closer
	 * together than they should rather than as anything breaking.
	 */
	const ASSUMED_SIZES = array(
		'marker'         => 20,
		'point'          => 20,
		'callout_large'  => 56,
		'callout_small'  => 34,
		'callout_caption' => 20,
		'note'           => 20,
	);

	/**
	 * Width of one character as a fraction of the font size.
	 *
	 * Two figures, because bold text is wider and the difference is
	 * enough to matter. A heading measured at the lighter figure
	 * reserves a box narrower than the words drawn in it, and the next
	 * label tucks in against it and clips the last letter.
	 */
	const CHAR_WIDTH      = 0.6;
	const CHAR_WIDTH_BOLD = 0.68;

	/**
	 * Breathing room added around every reserved box, in canvas units.
	 *
	 * Every width here is an estimate, so two boxes that merely touch
	 * are two labels that might actually overlap. This buys back the
	 * error, and gives labels that sit near each other a little air
	 * even when the estimate was right.
	 */
	const BOX_PADDING = 6;

	/** Line height as a multiple of the font size. */
	const LINE_HEIGHT = 1.25;

	/** Gap between a marker line and its heading. */
	const MARKER_LABEL_GAP = 12;

	/** Gap between an emphasised point and its label. */
	const POINT_LABEL_GAP = 16;

	/** How far a callout sits from what it points at, before nudging. */
	const CALLOUT_DISTANCE_NEAR = 90;
	const CALLOUT_DISTANCE_FAR  = 170;

	/** How far a bracket stands off the span it brackets. */
	const BRACKET_OFFSET = 26;

	/** The tick at each end of a span bracket. */
	const BRACKET_CAP = 9;

	/** How many times a colliding label is nudged before it gives up. */
	const MAX_NUDGES = 8;

	/** How far one nudge moves a label, as a multiple of its line height. */
	const NUDGE_STEP = 0.75;

	/**
	 * The widest a note is allowed to run before it wraps itself, as a
	 * fraction of the plot width.
	 */
	const NOTE_WRAP_FRACTION = 0.42;

	/** @var array Chart definition. */
	private $definition;

	/** @var KDNA_Charts_Scale */
	private $scale;

	/** @var string Unique id stem for this render. */
	private $uid;

	/**
	 * Boxes already spoken for. Seeded with the axis labels, so an
	 * annotation never lands on top of a tick.
	 *
	 * @var array List of array( x1, y1, x2, y2 ).
	 */
	private $occupied = array();

	/**
	 * Projected series points, used to keep callouts off the line they
	 * are describing.
	 *
	 * @var array List of array( x, y ).
	 */
	private $series_points = array();

	public function __construct( array $definition, KDNA_Charts_Scale $scale, $uid ) {
		$this->definition = $definition;
		$this->scale      = $scale;
		$this->uid        = (string) $uid;

		$this->seed_occupied();
		$this->collect_series_points();
	}

	/*
	 * ====================================================================
	 * The two layers
	 * ====================================================================
	 */

	/**
	 * What sits beneath the data: marker lines only.
	 *
	 * A marker crosses the whole plot, so drawing it over the series
	 * would put a dashed line through the very shape the reader is
	 * following. Its heading goes in the layer above, where nothing can
	 * cover it.
	 */
	public function render_under() {
		$markers = $this->marker_lines();
		if ( '' === $markers ) {
			return '';
		}
		return KDNA_Charts_Renderer::tag(
			'g',
			array(
				'class'       => KDNA_Charts_Renderer::css( 'markers' ),
				'aria-hidden' => 'true',
			),
			$markers
		);
	}

	/**
	 * Everything that must not be covered: marker headings, emphasised
	 * points and their labels, callouts with their leaders, and notes.
	 *
	 * Placed in that order deliberately. A marker heading belongs at
	 * the end of its line and moving it breaks the association, so it
	 * claims its space first. A point label sits beside its point for
	 * the same reason. A callout has a leader line and can therefore
	 * sit anywhere and still be understood, so it goes last of the
	 * important things and takes whatever room is left. A note is an
	 * aside and yields to all of them.
	 */
	public function render_over() {
		$parts = array(
			$this->marker_labels(),
			$this->points(),
			$this->callouts(),
			$this->notes(),
		);

		$parts = array_filter( $parts );
		if ( empty( $parts ) ) {
			return '';
		}

		return KDNA_Charts_Renderer::tag(
			'g',
			array(
				'class'       => KDNA_Charts_Renderer::css( 'annotations' ),
				'aria-hidden' => 'true',
			),
			implode( '', $parts )
		);
	}

	/**
	 * The annotations written out as a sentence, for the accessible
	 * description. A screen reader gets the argument the chart is
	 * making, not just the numbers it is making it from.
	 */
	public function describe() {
		$out = array();

		foreach ( $this->valid_markers() as $marker ) {
			$label = trim( (string) ( $marker['label'] ?? '' ) );
			if ( '' === $label ) {
				continue;
			}
			$out[] = sprintf(
				/* translators: 1: marker heading, 2: the value it sits at */
				__( 'Marked at %2$s: %1$s.', 'kdna-charts' ),
				$label,
				KDNA_Charts_Scale::format_number(
					'vertical' === $marker['type'] ? $marker['x'] : $marker['y']
				)
			);
		}

		foreach ( $this->list( 'callouts' ) as $callout ) {
			$value = trim( (string) ( $callout['value'] ?? '' ) );
			if ( '' === $value ) {
				continue;
			}
			$caption = trim( (string) ( $callout['caption'] ?? '' ) );
			$out[]   = '' === $caption
				? $value . '.'
				: sprintf(
					/* translators: 1: the callout figure, 2: its caption */
					__( '%1$s, %2$s.', 'kdna-charts' ),
					$value,
					$caption
				);
		}

		foreach ( $this->list( 'notes' ) as $note ) {
			$text = trim( (string) ( $note['text'] ?? '' ) );
			if ( '' !== $text ) {
				$out[] = rtrim( $text, '.' ) . '.';
			}
		}

		return implode( ' ', $out );
	}

	/*
	 * ====================================================================
	 * Markers
	 * ====================================================================
	 */

	/**
	 * Marker lines.
	 *
	 * A marker names an axis and a value on it, and is drawn
	 * perpendicular to that axis. On a column chart a marker at a value
	 * runs across the plot; turn the chart on its side and the same
	 * marker runs down it, still at the same value. The names vertical
	 * and horizontal describe how a marker looks on an upright chart,
	 * and which axis it belongs to on any chart, which is the part that
	 * has to survive a change of type.
	 */
	private function marker_lines() {
		$plot  = $this->scale->plot_area();
		$lines = array();

		foreach ( $this->valid_markers() as $marker ) {
			$style  = $this->one_of( $marker['style'] ?? '', KDNA_Charts_Schema::LINE_STYLES, 'dashed' );
			$axis   = ( 'vertical' === $marker['type'] ) ? 'x' : 'y';
			$at     = $this->scale->position( $axis, $marker[ $axis ] );
			$across = 'across' === $this->scale->axis_direction( $axis );

			$lines[] = KDNA_Charts_Renderer::tag(
				'line',
				array(
					'class' => KDNA_Charts_Renderer::css( 'marker', array( $marker['type'], $style ) ),
					'x1'    => $across ? $at : $plot['x'],
					'y1'    => $across ? $plot['y'] : $at,
					'x2'    => $across ? $at : $plot['right'],
					'y2'    => $across ? $plot['bottom'] : $at,
				)
			);
		}

		return implode( '', $lines );
	}

	private function marker_labels() {
		$plot   = $this->scale->plot_area();
		$size   = self::ASSUMED_SIZES['marker'];
		$labels = array();

		foreach ( $this->valid_markers() as $marker ) {
			$label = trim( (string) ( $marker['label'] ?? '' ) );
			if ( '' === $label ) {
				continue;
			}

			$axis   = ( 'vertical' === $marker['type'] ) ? 'x' : 'y';
			$at     = $this->scale->position( $axis, $marker[ $axis ] );
			$across = 'across' === $this->scale->axis_direction( $axis );

			$position = $this->one_of(
				$marker['label_position'] ?? '',
				KDNA_Charts_Schema::MARKER_LABEL_POSITIONS,
				$across ? 'top' : 'right'
			);

			if ( $across ) {
				/*
				 * The line runs down the plot, so its ends are above and
				 * below it. A heading asked to sit left or right of such
				 * a line has nothing to sit against, so it takes the top.
				 */
				$at_bottom = 'bottom' === $position;
				$x         = $at;
				$y         = $at_bottom
					? $plot['bottom'] + self::MARKER_LABEL_GAP
					: $plot['y'] - self::MARKER_LABEL_GAP;
				$anchor    = 'middle';
				$baseline  = $at_bottom ? 'hanging' : 'text-after-edge';
				$nudge     = array( 0, $at_bottom ? 1 : -1 );
			} else {
				// The line runs across the plot, so its ends are its sides.
				$at_left  = in_array( $position, array( 'left', 'bottom' ), true );
				$x        = $at_left ? $plot['x'] + self::MARKER_LABEL_GAP : $plot['right'] - self::MARKER_LABEL_GAP;
				$y        = $at - self::MARKER_LABEL_GAP;
				$anchor   = $at_left ? 'start' : 'end';
				$baseline = 'text-after-edge';
				$nudge    = array( 0, -1 );
			}

			$placed = $this->place_text( $x, $y, $label, $size, $anchor, $baseline, $nudge, true );

			$labels[] = KDNA_Charts_Renderer::tag(
				'text',
				array(
					'class'             => KDNA_Charts_Renderer::css( 'marker-label', array( $marker['type'], $position ) ),
					'x'                 => $placed['x'],
					'y'                 => $placed['y'],
					'text-anchor'       => $anchor,
					'dominant-baseline' => $baseline,
				),
				esc_html( $label )
			);
		}

		return implode( '', $labels );
	}

	/**
	 * Markers with the coordinate their orientation needs. The importer
	 * already drops the rest, but a chart written straight into the
	 * database has not been through it.
	 */
	private function valid_markers() {
		$out = array();
		foreach ( $this->list( 'markers' ) as $marker ) {
			$type = $this->one_of( $marker['type'] ?? '', KDNA_Charts_Schema::MARKER_TYPES, '' );
			if ( '' === $type ) {
				continue;
			}
			$needed = ( 'vertical' === $type ) ? 'x' : 'y';
			if ( ! isset( $marker[ $needed ] ) || ! is_numeric( $marker[ $needed ] ) ) {
				continue;
			}
			$marker['type'] = $type;
			$out[]          = $marker;
		}
		return $out;
	}

	/*
	 * ====================================================================
	 * Emphasised points
	 * ====================================================================
	 */

	private function points() {
		$size  = self::ASSUMED_SIZES['point'];
		$parts = array();

		foreach ( $this->list( 'points' ) as $point ) {
			if ( ! isset( $point['x'], $point['y'] ) || ! is_numeric( $point['x'] ) || ! is_numeric( $point['y'] ) ) {
				continue;
			}

			list( $x, $y ) = $this->scale->point( $point['x'], $point['y'] );
			$style         = $this->one_of( $point['style'] ?? '', KDNA_Charts_Schema::POINT_STYLES, 'filled' );

			if ( 'none' !== $style ) {
				/*
				 * The radius is in the stylesheet, as a geometry property,
				 * so a site can size its points. It is also here as an
				 * attribute, because a circle with no radius is nothing at
				 * all and a stylesheet that failed to load should cost a
				 * chart its polish rather than its data. CSS wins whenever
				 * it is present.
				 */
				$parts[] = KDNA_Charts_Renderer::tag(
					'circle',
					array(
						'class' => KDNA_Charts_Renderer::css( 'point', array( $style ) ),
						'cx'    => $x,
						'cy'    => $y,
						'r'     => 9,
					)
				);
				// A drawn point occupies its own space.
				$this->reserve( array( $x - 12, $y - 12, $x + 12, $y + 12 ) );
			}

			$label = trim( (string) ( $point['label'] ?? '' ) );
			if ( '' === $label ) {
				continue;
			}

			$position = $this->one_of(
				$point['label_position'] ?? '',
				KDNA_Charts_Schema::LABEL_POSITIONS,
				'top'
			);

			$offset   = $this->label_offset( $position, self::POINT_LABEL_GAP );
			$anchor   = $offset['anchor'];
			$baseline = $offset['baseline'];

			$placed = $this->place_text(
				$x + $offset['dx'],
				$y + $offset['dy'],
				$label,
				$size,
				$anchor,
				$baseline,
				$offset['nudge']
			);

			$parts[] = KDNA_Charts_Renderer::tag(
				'text',
				array(
					'class'             => KDNA_Charts_Renderer::css( 'point-label', array( $position ) ),
					'x'                 => $placed['x'],
					'y'                 => $placed['y'],
					'text-anchor'       => $anchor,
					'dominant-baseline' => $baseline,
				),
				esc_html( $label )
			);
		}

		return implode( '', $parts );
	}

	/**
	 * Turns one of the eight label positions into an offset, a text
	 * anchor, a baseline and the direction to nudge if it collides.
	 */
	private function label_offset( $position, $gap ) {
		$diagonal = $gap * 0.72;

		$map = array(
			'top'          => array( 0, -$gap, 'middle', 'text-after-edge', array( 0, -1 ) ),
			'bottom'       => array( 0, $gap, 'middle', 'hanging', array( 0, 1 ) ),
			'left'         => array( -$gap, 0, 'end', 'central', array( -1, 0 ) ),
			'right'        => array( $gap, 0, 'start', 'central', array( 1, 0 ) ),
			'top-left'     => array( -$diagonal, -$diagonal, 'end', 'text-after-edge', array( -1, -1 ) ),
			'top-right'    => array( $diagonal, -$diagonal, 'start', 'text-after-edge', array( 1, -1 ) ),
			'bottom-left'  => array( -$diagonal, $diagonal, 'end', 'hanging', array( -1, 1 ) ),
			'bottom-right' => array( $diagonal, $diagonal, 'start', 'hanging', array( 1, 1 ) ),
		);

		$entry = isset( $map[ $position ] ) ? $map[ $position ] : $map['top'];

		return array(
			'dx'       => $entry[0],
			'dy'       => $entry[1],
			'anchor'   => $entry[2],
			'baseline' => $entry[3],
			'nudge'    => $entry[4],
		);
	}

	/*
	 * ====================================================================
	 * Callouts
	 * ====================================================================
	 */

	private function callouts() {
		$parts = array();

		foreach ( $this->list( 'callouts' ) as $callout ) {
			$value = trim( (string) ( $callout['value'] ?? '' ) );
			if ( '' === $value ) {
				continue;
			}

			$anchor = $this->resolve_anchor( $callout['anchor'] ?? null );
			if ( null === $anchor ) {
				continue;
			}

			$caption    = trim( (string) ( $callout['caption'] ?? '' ) );
			$size       = $this->one_of( $callout['size'] ?? '', KDNA_Charts_Schema::CALLOUT_SIZES, 'large' );
			$leader     = $this->one_of( $callout['leader'] ?? '', KDNA_Charts_Schema::LEADERS, 'none' );
			$value_size = ( 'large' === $size ) ? self::ASSUMED_SIZES['callout_large'] : self::ASSUMED_SIZES['callout_small'];
			$caption_size = self::ASSUMED_SIZES['callout_caption'];

			$width = max(
				$this->text_width( $value, $value_size, true ),
				$this->text_width( $caption, $caption_size )
			);
			$height = $value_size * self::LINE_HEIGHT
				+ ( '' !== $caption ? $caption_size * self::LINE_HEIGHT : 0 );

			$box = $this->find_room( $anchor['centre'], $width, $height );
			$this->reserve( $box );

			/*
			 * The leader is drawn first so the text sits over it rather
			 * than under it, which matters where a bracket stem passes
			 * close to the figure it belongs to.
			 */
			if ( 'none' !== $leader ) {
				$line = $this->leader( $box, $anchor, $leader );
				if ( '' !== $line ) {
					$parts[] = $line;
				}
			}

			$centre_x = ( $box[0] + $box[2] ) / 2;

			$parts[] = KDNA_Charts_Renderer::tag(
				'text',
				array(
					'class'             => KDNA_Charts_Renderer::css( 'callout-value', array( $size ) ),
					'x'                 => $centre_x,
					'y'                 => $box[1],
					'text-anchor'       => 'middle',
					'dominant-baseline' => 'hanging',
				),
				esc_html( $value )
			);

			if ( '' !== $caption ) {
				$parts[] = KDNA_Charts_Renderer::tag(
					'text',
					array(
						'class'             => KDNA_Charts_Renderer::css( 'callout-caption', array( $size ) ),
						'x'                 => $centre_x,
						'y'                 => $box[1] + $value_size * self::LINE_HEIGHT,
						'text-anchor'       => 'middle',
						'dominant-baseline' => 'hanging',
					),
					esc_html( $caption )
				);
			}
		}

		if ( empty( $parts ) ) {
			return '';
		}

		return KDNA_Charts_Renderer::tag(
			'g',
			array( 'class' => KDNA_Charts_Renderer::css( 'callouts' ) ),
			implode( '', $parts )
		);
	}

	/**
	 * Turns a callout anchor into SVG coordinates.
	 *
	 * @return array|null Keys: centre, from, to, is_span.
	 */
	private function resolve_anchor( $anchor ) {
		if ( ! is_array( $anchor ) ) {
			return null;
		}

		if ( isset( $anchor['from'], $anchor['to'] ) && is_array( $anchor['from'] ) && is_array( $anchor['to'] ) ) {
			$from = $this->point_of( $anchor['from'] );
			$to   = $this->point_of( $anchor['to'] );
			if ( null === $from || null === $to ) {
				return null;
			}
			return array(
				'centre'  => array( ( $from[0] + $to[0] ) / 2, ( $from[1] + $to[1] ) / 2 ),
				'from'    => $from,
				'to'      => $to,
				'is_span' => true,
			);
		}

		$point = $this->point_of( $anchor );
		if ( null === $point ) {
			return null;
		}

		return array(
			'centre'  => $point,
			'from'    => $point,
			'to'      => $point,
			'is_span' => false,
		);
	}

	private function point_of( $pair ) {
		if ( ! is_array( $pair ) || ! isset( $pair['x'], $pair['y'] ) ) {
			return null;
		}
		if ( ! is_numeric( $pair['x'] ) || ! is_numeric( $pair['y'] ) ) {
			return null;
		}
		return $this->scale->point( $pair['x'], $pair['y'] );
	}

	/**
	 * The leader line from a callout to what it points at.
	 *
	 * A single point gets a line to the point, straight or elbowed. A
	 * span gets a bracket: a rule standing off the two anchors with a
	 * tick turned in at each end, and a stem running from the middle of
	 * it to the callout. That is what makes the figure read as
	 * describing a range rather than a moment.
	 */
	private function leader( array $box, array $anchor, $style ) {
		$edge = $this->nearest_edge_point( $box, $anchor['centre'] );

		if ( $anchor['is_span'] ) {
			return $this->bracket( $box, $anchor, $edge, $style );
		}

		$target = $anchor['centre'];
		$d      = ( 'elbow' === $style )
			? $this->elbow_path( $edge, $target )
			: 'M ' . $this->xy( $edge ) . ' L ' . $this->xy( $target );

		return KDNA_Charts_Renderer::tag(
			'path',
			array(
				'class' => KDNA_Charts_Renderer::css( 'leader', array( $style ) ),
				'd'     => $d,
			)
		);
	}

	/**
	 * A bracket spanning two anchor points, offset toward the callout.
	 */
	private function bracket( array $box, array $anchor, array $edge, $style ) {
		$from = $anchor['from'];
		$to   = $anchor['to'];

		// The unit vector along the span, and the perpendicular to it.
		$dx     = $to[0] - $from[0];
		$dy     = $to[1] - $from[1];
		$length = sqrt( $dx * $dx + $dy * $dy );

		if ( $length < 1 ) {
			// The two ends are the same point, so there is no span to
			// bracket. A plain leader says the same thing without
			// pretending to a range.
			$target = $anchor['centre'];
			return KDNA_Charts_Renderer::tag(
				'path',
				array(
					'class' => KDNA_Charts_Renderer::css( 'leader', array( $style ) ),
					'd'     => 'M ' . $this->xy( $edge ) . ' L ' . $this->xy( $target ),
				)
			);
		}

		$nx = -$dy / $length;
		$ny = $dx / $length;

		/*
		 * The perpendicular has two directions. The bracket takes the
		 * one facing the callout, so the stem never has to cross the
		 * span it is bracketing.
		 */
		$centre  = $anchor['centre'];
		$towards = array( $edge[0] - $centre[0], $edge[1] - $centre[1] );
		if ( ( $nx * $towards[0] + $ny * $towards[1] ) < 0 ) {
			$nx = -$nx;
			$ny = -$ny;
		}

		$offset = self::BRACKET_OFFSET;
		$cap    = self::BRACKET_CAP;

		$from_out = array( $from[0] + $nx * $offset, $from[1] + $ny * $offset );
		$to_out   = array( $to[0] + $nx * $offset, $to[1] + $ny * $offset );
		$mid_out  = array( ( $from_out[0] + $to_out[0] ) / 2, ( $from_out[1] + $to_out[1] ) / 2 );

		// The rule, with a tick turned back in at each end.
		$rule = 'M ' . $this->xy( array( $from[0] + $nx * ( $offset - $cap ), $from[1] + $ny * ( $offset - $cap ) ) )
			. ' L ' . $this->xy( $from_out )
			. ' L ' . $this->xy( $to_out )
			. ' L ' . $this->xy( array( $to[0] + $nx * ( $offset - $cap ), $to[1] + $ny * ( $offset - $cap ) ) );

		$stem = ( 'elbow' === $style )
			? $this->elbow_path( $edge, $mid_out )
			: 'M ' . $this->xy( $edge ) . ' L ' . $this->xy( $mid_out );

		return KDNA_Charts_Renderer::tag(
			'path',
			array(
				'class' => KDNA_Charts_Renderer::css( 'bracket' ),
				'd'     => $rule,
			)
		)
		. KDNA_Charts_Renderer::tag(
			'path',
			array(
				'class' => KDNA_Charts_Renderer::css( 'leader', array( $style ) ),
				'd'     => $stem,
			)
		);
	}

	/**
	 * An L shaped path between two points.
	 *
	 * It turns along the longer axis first, so the elbow reads as one
	 * decisive run and one short approach rather than two similar legs
	 * meeting at an arbitrary corner.
	 */
	private function elbow_path( array $from, array $to ) {
		$dx = abs( $to[0] - $from[0] );
		$dy = abs( $to[1] - $from[1] );

		$corner = ( $dx >= $dy )
			? array( $to[0], $from[1] )
			: array( $from[0], $to[1] );

		return 'M ' . $this->xy( $from ) . ' L ' . $this->xy( $corner ) . ' L ' . $this->xy( $to );
	}

	/**
	 * The point on a box's edge closest to a target, so a leader leaves
	 * the callout from the side facing what it points at.
	 */
	private function nearest_edge_point( array $box, array $target ) {
		$x = max( $box[0], min( $box[2], $target[0] ) );
		$y = max( $box[1], min( $box[3], $target[1] ) );

		// A target inside the box has no meaningful nearest edge, so the
		// leader leaves from the centre and is mostly hidden anyway.
		if ( $x > $box[0] && $x < $box[2] && $y > $box[1] && $y < $box[3] ) {
			return array( ( $box[0] + $box[2] ) / 2, ( $box[1] + $box[3] ) / 2 );
		}

		return array( $x, $y );
	}

	/*
	 * ====================================================================
	 * Notes
	 * ====================================================================
	 */

	private function notes() {
		$size  = self::ASSUMED_SIZES['note'];
		$parts = array();

		foreach ( $this->list( 'notes' ) as $note ) {
			$text = trim( (string) ( $note['text'] ?? '' ) );
			if ( '' === $text ) {
				continue;
			}

			$at = $this->point_of( $note['at'] ?? null );
			if ( null === $at ) {
				continue;
			}

			$align  = $this->one_of( $note['align'] ?? '', KDNA_Charts_Schema::ALIGNMENTS, 'left' );
			$anchor = array(
				'left'   => 'start',
				'centre' => 'middle',
				'right'  => 'end',
			)[ $align ];

			/*
			 * A note wraps at the width it was given, and failing that at
			 * a width that keeps it from running across the chart.
			 *
			 * The automatic case is not tidiness. A long note is a wide
			 * box, a wide box collides with everything, and a collision
			 * the nudger cannot solve puts the note somewhere worse than
			 * where it started. Two short lines have somewhere to go
			 * where one long one does not.
			 */
			$plot  = $this->scale->plot_area();
			$wrap  = isset( $note['width'] ) && is_numeric( $note['width'] ) && $note['width'] > 0
				? (float) $note['width']
				: 0.0;

			if ( 0.0 === $wrap && $this->text_width( $text, $size ) > $plot['width'] * self::NOTE_WRAP_FRACTION ) {
				$wrap = $plot['width'] * self::NOTE_WRAP_FRACTION;
			}

			$lines = $wrap > 0 ? $this->wrap_text( $text, $size, $wrap ) : array( $text );

			$width = 0.0;
			foreach ( $lines as $line ) {
				$width = max( $width, $this->text_width( $line, $size ) );
			}
			$height = count( $lines ) * $size * self::LINE_HEIGHT;

			$placed = $this->place_box(
				$at[0],
				$at[1],
				$width,
				$height,
				$anchor,
				'hanging',
				array( 0, -1 ),
				true
			);

			$parts[] = KDNA_Charts_Renderer::tag(
				'text',
				array(
					'class'             => KDNA_Charts_Renderer::css( 'note', array( $align ) ),
					'x'                 => $placed['x'],
					'y'                 => $placed['y'],
					'text-anchor'       => $anchor,
					'dominant-baseline' => 'hanging',
				),
				$this->tspans( $lines, $placed['x'] )
			);
		}

		return implode( '', $parts );
	}

	/**
	 * Wrapped lines as tspans.
	 *
	 * The line spacing is in em rather than canvas units, so it resolves
	 * against whatever size the browser actually drew the text at. Every
	 * other measurement here is an estimate; this one is exact, because
	 * it can be.
	 */
	private function tspans( array $lines, $x ) {
		if ( count( $lines ) < 2 ) {
			return esc_html( $lines[0] );
		}

		$out = '';
		foreach ( $lines as $index => $line ) {
			$out .= KDNA_Charts_Renderer::tag(
				'tspan',
				array(
					'x'  => $x,
					'dy' => 0 === $index ? null : self::LINE_HEIGHT . 'em',
				),
				esc_html( $line )
			);
		}
		return $out;
	}

	/**
	 * Breaks text into lines no wider than a given width. Estimated,
	 * like every other width here, so a long word is left to overrun
	 * rather than broken mid word.
	 */
	private function wrap_text( $text, $size, $width ) {
		$words = preg_split( '/\s+/', trim( $text ) );
		if ( empty( $words ) ) {
			return array( '' );
		}

		$lines   = array();
		$current = '';

		foreach ( $words as $word ) {
			$candidate = '' === $current ? $word : $current . ' ' . $word;
			if ( '' !== $current && $this->text_width( $candidate, $size ) > $width ) {
				$lines[]  = $current;
				$current = $word;
				continue;
			}
			$current = $candidate;
		}

		if ( '' !== $current ) {
			$lines[] = $current;
		}

		return $lines;
	}

	/*
	 * ====================================================================
	 * Placement
	 * ====================================================================
	 */

	/**
	 * Places a line of text, nudging it clear of anything already there.
	 *
	 * @return array Keys x and y, the position to draw at.
	 */
	private function place_text( $x, $y, $text, $size, $anchor, $baseline, array $nudge, $bold = false ) {
		return $this->place_box(
			$x,
			$y,
			$this->text_width( $text, $size, $bold ),
			$size * self::LINE_HEIGHT,
			$anchor,
			$baseline,
			$nudge
		);
	}

	/**
	 * Places a box of known size at a preferred position, moving it
	 * clear of anything already there.
	 *
	 * It tries the preferred direction first, then the opposite one.
	 * Trying only one was not enough: a note above the plot that
	 * collides with a marker heading has nowhere to go upward, and a
	 * search that gives up at the canvas edge leaves it sitting on the
	 * heading, which is the exact outcome this is here to prevent.
	 *
	 * Where neither direction finds clear air, the least overlapping
	 * position wins rather than the original one. A label with a corner
	 * clipped is worth having; a label lying across a heading is not.
	 *
	 * The box is reserved either way, because a label that settled for
	 * second best still occupies the space it landed in and the next
	 * one needs to know.
	 */
	private function place_box( $x, $y, $width, $height, $anchor, $baseline, array $nudge, $sideways = false ) {
		$step = max( 1.0, $height * self::NUDGE_STEP );

		$directions = array( $nudge );
		if ( 0 !== $nudge[0] || 0 !== $nudge[1] ) {
			$directions[] = array( -$nudge[0], -$nudge[1] );
		}

		/*
		 * Some labels may also step sideways. A note is free to, because
		 * nothing ties it to a particular column; a marker heading is
		 * not, because sliding it along the top detaches it from the
		 * line it names, and a heading over the wrong line is worse than
		 * a heading with a clipped corner.
		 */
		if ( $sideways ) {
			$directions[] = array( -$nudge[1], $nudge[0] );
			$directions[] = array( $nudge[1], -$nudge[0] );
		}

		$clear     = null;
		$fallback  = null;
		$least     = INF;

		foreach ( $directions as $direction ) {
			for ( $attempt = 0; $attempt <= self::MAX_NUDGES; $attempt++ ) {
				$try_x = $x + $direction[0] * $step * $attempt;
				$try_y = $y + $direction[1] * $step * $attempt;
				$box   = $this->box_from( $try_x, $try_y, $width, $height, $anchor, $baseline );

				$off_canvas = ! $this->within_canvas( $box );
				$overlap    = $this->overlap_area( $box );

				if ( ! $off_canvas && 0.0 === $overlap ) {
					// The first clear position in this direction. Keep it
					// only if it took fewer moves than one already found.
					if ( null === $clear || $attempt < $clear['attempt'] ) {
						$clear = array(
							'attempt' => $attempt,
							'x'       => $try_x,
							'y'       => $try_y,
							'box'     => $box,
						);
					}
					break;
				}

				// Movement is a small cost of its own, so a label does not
				// travel a long way to save a sliver of overlap.
				$score = $overlap + ( $off_canvas ? 1e6 : 0 ) + $attempt * 40;
				if ( $score < $least ) {
					$least    = $score;
					$fallback = array(
						'x'   => $try_x,
						'y'   => $try_y,
						'box' => $box,
					);
				}

				/*
				 * No break on an off canvas candidate. A label whose
				 * preferred position already hangs over the edge needs
				 * the search to keep walking, because the position that
				 * brings it back on is further along, not behind it.
				 * Breaking here left two marker headings sitting on top
				 * of each other, both of them half off the canvas.
				 */
			}
		}

		$chosen = null !== $clear ? $clear : $fallback;

		if ( null === $chosen ) {
			$chosen = array(
				'x'   => $x,
				'y'   => $y,
				'box' => $this->box_from( $x, $y, $width, $height, $anchor, $baseline ),
			);
		}

		$this->reserve( $chosen['box'] );

		return array(
			'x' => round( $chosen['x'], 2 ),
			'y' => round( $chosen['y'], 2 ),
		);
	}

	/**
	 * How much of a box is already spoken for, in square canvas units.
	 * Zero means clear air.
	 */
	private function overlap_area( array $box ) {
		$total = 0.0;
		foreach ( $this->occupied as $taken ) {
			$width  = min( $box[2], $taken[2] ) - max( $box[0], $taken[0] );
			$height = min( $box[3], $taken[3] ) - max( $box[1], $taken[1] );
			if ( $width > 0 && $height > 0 ) {
				$total += $width * $height;
			}
		}
		return $total;
	}

	/**
	 * Finds room for a callout near what it points at.
	 *
	 * Unlike a label, a callout carries a leader line, so it can sit
	 * anywhere and still be understood. That freedom is what lets this
	 * search happen at all: it tries positions all around the anchor at
	 * two distances, scores each on what it would cover, and takes the
	 * best. Sitting on the line the callout is describing is the worst
	 * outcome, sitting on another annotation is nearly as bad, and
	 * sitting far from the anchor is a mild cost that breaks ties.
	 *
	 * @return array The chosen box, array( x1, y1, x2, y2 ).
	 */
	private function find_room( array $anchor, $width, $height ) {
		$directions = array(
			array( 1, -1 ),
			array( 1, 0 ),
			array( 0, -1 ),
			array( -1, -1 ),
			array( 1, 1 ),
			array( -1, 0 ),
			array( 0, 1 ),
			array( -1, 1 ),
		);

		$best       = null;
		$best_score = INF;

		foreach ( array( self::CALLOUT_DISTANCE_NEAR, self::CALLOUT_DISTANCE_FAR ) as $distance ) {
			foreach ( $directions as $direction ) {
				$length = sqrt( $direction[0] * $direction[0] + $direction[1] * $direction[1] );
				$cx     = $anchor[0] + ( $direction[0] / $length ) * ( $distance + $width / 2 );
				$cy     = $anchor[1] + ( $direction[1] / $length ) * ( $distance + $height / 2 );

				$box = array(
					$cx - $width / 2,
					$cy - $height / 2,
					$cx + $width / 2,
					$cy + $height / 2,
				);

				$score = $this->score_box( $box, $anchor );
				if ( $score < $best_score ) {
					$best_score = $score;
					$best       = $box;
				}
			}
		}

		// Every position was scored, so there is always a best one. The
		// fallback is here for the impossible case rather than a likely
		// one.
		return null === $best
			? array( $anchor[0], $anchor[1], $anchor[0] + $width, $anchor[1] + $height )
			: $best;
	}

	/**
	 * How bad a position is. Lower is better.
	 */
	private function score_box( array $box, array $anchor ) {
		$score = 0.0;

		if ( ! $this->within_canvas( $box ) ) {
			$score += 5000;
		}

		foreach ( $this->occupied as $taken ) {
			if ( $this->intersects( $box, $taken ) ) {
				$score += 900;
			}
		}

		// Covering the line the callout is talking about is the one thing
		// worth avoiding above all else.
		foreach ( $this->series_points as $point ) {
			if ( $this->contains( $box, $point ) ) {
				$score += 260;
			}
		}

		// Everything else equal, closer to the anchor reads better.
		$centre = array( ( $box[0] + $box[2] ) / 2, ( $box[1] + $box[3] ) / 2 );
		$score += hypot( $centre[0] - $anchor[0], $centre[1] - $anchor[1] ) * 0.35;

		return $score;
	}

	/*
	 * ====================================================================
	 * Geometry helpers
	 * ====================================================================
	 */

	/**
	 * The bounding box of text drawn at a position, given how it is
	 * anchored and which baseline it sits on.
	 */
	private function box_from( $x, $y, $width, $height, $anchor, $baseline ) {
		switch ( $anchor ) {
			case 'end':
				$x1 = $x - $width;
				break;
			case 'middle':
				$x1 = $x - $width / 2;
				break;
			default:
				$x1 = $x;
		}

		switch ( $baseline ) {
			case 'hanging':
				$y1 = $y;
				break;
			case 'central':
			case 'middle':
				$y1 = $y - $height / 2;
				break;
			default:
				// text-after-edge and the alphabetic default both put the
				// glyphs above the point.
				$y1 = $y - $height;
		}

		$pad = self::BOX_PADDING;

		return array( $x1 - $pad, $y1 - $pad, $x1 + $width + $pad, $y1 + $height + $pad );
	}

	private function intersects( array $a, array $b ) {
		return $a[0] < $b[2] && $a[2] > $b[0] && $a[1] < $b[3] && $a[3] > $b[1];
	}

	private function contains( array $box, array $point ) {
		return $point[0] >= $box[0] && $point[0] <= $box[2]
			&& $point[1] >= $box[1] && $point[1] <= $box[3];
	}

	private function collides( array $box ) {
		foreach ( $this->occupied as $taken ) {
			if ( $this->intersects( $box, $taken ) ) {
				return true;
			}
		}
		return false;
	}

	private function within_canvas( array $box ) {
		$canvas = $this->scale->canvas();
		return $box[0] >= 0 && $box[1] >= 0
			&& $box[2] <= $canvas['width'] && $box[3] <= $canvas['height'];
	}

	private function reserve( array $box ) {
		$this->occupied[] = $box;
	}

	/**
	 * The axis labels are on the canvas before any annotation is, so
	 * they go into the occupied list first. Without this, a note
	 * drifting toward the foot of the chart lands on a tick.
	 */
	private function seed_occupied() {
		$plot = $this->scale->plot_area();
		$size = KDNA_Charts_Scale::ASSUMED_LABEL_SIZE;

		foreach ( array( 'x', 'y' ) as $axis ) {
			$ticks  = ( 'x' === $axis ) ? $this->scale->x_ticks() : $this->scale->y_ticks();
			$across = 'across' === $this->scale->axis_direction( $axis );

			foreach ( $ticks as $tick ) {
				$label = (string) ( $tick['label'] ?? '' );
				if ( '' === $label ) {
					continue;
				}
				$this->reserve(
					$this->box_from(
						$across ? $tick['position'] : $plot['x'] - KDNA_Charts_Scale::TICK_LABEL_GAP_X,
						$across ? $plot['bottom'] + KDNA_Charts_Scale::TICK_LABEL_GAP_Y : $tick['position'],
						$this->text_width( $label, $size ),
						$size * self::LINE_HEIGHT,
						$across ? 'middle' : 'end',
						$across ? 'hanging' : 'central'
					)
				);
			}
		}
	}

	private function collect_series_points() {
		$series = $this->definition['series'] ?? array();
		if ( ! is_array( $series ) ) {
			return;
		}

		/*
		 * On a bar chart there is no line to keep clear of, there are
		 * bars, and a callout dropped on one is just as unreadable. Each
		 * is reserved as occupied space, which keeps callouts off them
		 * and keeps notes off the figures printed on them.
		 */
		if ( in_array( (string) ( $this->definition['type'] ?? '' ), array( 'bar', 'column' ), true ) ) {
			$this->reserve_bars( $series );
			return;
		}

		foreach ( $series as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['segments'] ) || ! is_array( $entry['segments'] ) ) {
				continue;
			}
			foreach ( $entry['segments'] as $segment ) {
				if ( ! is_array( $segment ) || empty( $segment['points'] ) || ! is_array( $segment['points'] ) ) {
					continue;
				}
				foreach ( $this->scale->project( $segment['points'] ) as $point ) {
					if ( is_array( $point ) ) {
						$this->series_points[] = $point;
					}
				}
			}
		}

		/*
		 * The plotted points alone leave long gaps between readings, and
		 * a callout can slip through one and land on the line anyway. So
		 * the run is sampled between them as well.
		 */
		$sampled = array();
		$count   = count( $this->series_points );
		for ( $i = 0; $i < $count - 1; $i++ ) {
			$a = $this->series_points[ $i ];
			$b = $this->series_points[ $i + 1 ];
			for ( $step = 1; $step < 6; $step++ ) {
				$t         = $step / 6;
				$sampled[] = array(
					$a[0] + ( $b[0] - $a[0] ) * $t,
					$a[1] + ( $b[1] - $a[1] ) * $t,
				);
			}
		}

		$this->series_points = array_merge( $this->series_points, $sampled );
	}

	/**
	 * Marks every bar as occupied space.
	 *
	 * Stacked series share a slot, so the reservation is the whole slot
	 * from the baseline to the furthest end reached in it, rather than
	 * one box per segment.
	 */
	private function reserve_bars( array $series ) {
		$categories = $this->scale->categories();
		if ( empty( $categories ) ) {
			return;
		}

		$with_data = array();
		foreach ( $series as $entry ) {
			if ( is_array( $entry ) && ! empty( $entry['data'] ) && is_array( $entry['data'] ) ) {
				$with_data[] = array_values( $entry['data'] );
			}
		}
		if ( empty( $with_data ) ) {
			return;
		}

		$count    = count( $with_data );
		$stacked  = $this->scale->is_stacked();
		$baseline = $this->scale->position( 'y', $this->scale->baseline() );

		foreach ( array_keys( $categories ) as $index ) {
			$reach_up   = 0.0;
			$reach_down = 0.0;

			foreach ( $with_data as $position => $data ) {
				$datum = isset( $data[ $index ] ) && is_array( $data[ $index ] ) ? $data[ $index ] : null;
				if ( null === $datum || ! isset( $datum['value'] ) || ! is_numeric( $datum['value'] ) ) {
					continue;
				}
				$value = (float) $datum['value'];

				if ( $stacked ) {
					if ( $value >= 0 ) {
						$reach_up += $value;
					} else {
						$reach_down += $value;
					}
					continue;
				}

				// Grouped bars each stand alone, so each is reserved on
				// its own rather than as part of a slot.
				$band = $this->scale->band( $index, $position, $count );
				$this->reserve( $this->bar_box( $band, $baseline, $this->scale->position( 'y', $value ) ) );
			}

			if ( ! $stacked ) {
				continue;
			}

			$band = $this->scale->band( $index, 0, 1 );
			foreach ( array( $reach_up, $reach_down ) as $reach ) {
				if ( 0.0 === $reach ) {
					continue;
				}
				$this->reserve( $this->bar_box( $band, $baseline, $this->scale->position( 'y', $reach ) ) );
			}
		}
	}

	/**
	 * One bar as a box, in whichever direction this chart runs.
	 */
	private function bar_box( array $band, $near, $far ) {
		$start  = min( $near, $far );
		$length = abs( $far - $near );

		return $this->scale->is_horizontal()
			? array( $start, $band['start'], $start + $length, $band['start'] + $band['thickness'] )
			: array( $band['start'], $start, $band['start'] + $band['thickness'], $start + $length );
	}

	/**
	 * An estimate of how wide a string will be drawn.
	 *
	 * @param string $text Text to measure.
	 * @param float  $size Font size in canvas units.
	 * @param bool   $bold Whether the stylesheet sets this text bold.
	 */
	private function text_width( $text, $size, $bold = false ) {
		$text = (string) $text;
		if ( '' === $text ) {
			return 0.0;
		}
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
		return $length * ( $bold ? self::CHAR_WIDTH_BOLD : self::CHAR_WIDTH ) * $size;
	}

	private function xy( array $point ) {
		return KDNA_Charts_Scale::round_coord( $point[0] ) . ' ' . KDNA_Charts_Scale::round_coord( $point[1] );
	}

	private function list( $key ) {
		$list = $this->definition[ $key ] ?? array();
		if ( ! is_array( $list ) ) {
			return array();
		}
		$out = array();
		foreach ( $list as $entry ) {
			if ( is_array( $entry ) ) {
				$out[] = $entry;
			}
		}
		return $out;
	}

	private function one_of( $value, array $allowed, $fallback ) {
		$value = is_string( $value ) ? strtolower( trim( $value ) ) : '';
		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}
}
