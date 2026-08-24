<?php
/**
 * The chart definition schema, version 1. This class is the single
 * source of truth for what a KDNA Charts definition may contain.
 *
 * Everything that needs to know the shape of a chart reads it from
 * here: the importer validates against it, the admin editor builds its
 * controls from it, the renderers read their option defaults from it,
 * and docs/chart-schema.md is the prose mirror of it.
 *
 * The vocabulary is declarative. Each node is an array with a 'kind'
 * and whatever that kind needs:
 *
 *   kind        meaning
 *   ----------  --------------------------------------------------------
 *   int         Whole number.
 *   number      Any finite number. Integers stay integers.
 *   text        Plain text, sanitised on storage.
 *   html        Text allowing the inline markup wp_kses_post permits.
 *   bool        True or false.
 *   enum        One of 'values'. 'allow_empty' permits '' as "inherit".
 *   list        An ordered list of 'of' nodes.
 *   object      A keyed object, keys declared in 'keys'.
 *   map         A keyed object with free form keys and scalar values.
 *   point       An [x, y] pair.
 *   anchor      Either a single {x, y} or a {from, to} span.
 *   options     Type specific options, resolved by options_spec().
 *
 * Optional extras on any node: 'required', 'default', 'min', 'max',
 * 'label' (the one line description the docs and the admin use).
 *
 * @package KDNA_Charts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KDNA_Charts_Schema {

	/**
	 * Schema version. Written into every chart as the kdna_chart key, and
	 * the only version this plugin accepts on import.
	 */
	const VERSION = 1;

	const TYPES   = array( 'line', 'area', 'bar', 'column', 'pie', 'donut', 'stat' );
	const ENGINES = array( 'svg', 'chartjs' );

	/**
	 * Types the SVG renderer plots against x and y axes. The rest are
	 * categorical or typographic and ignore the axes block.
	 */
	const PLOTTED_TYPES = array( 'line', 'area', 'bar', 'column' );

	/** Types drawn from a flat list of label and value pairs. */
	const CATEGORICAL_TYPES = array( 'bar', 'column', 'pie', 'donut', 'stat' );

	/** Types that use axes at all. */
	const AXIS_TYPES = array( 'line', 'area', 'bar', 'column' );

	/*
	 * Shared vocabularies. Every enum in the schema comes from one of
	 * these, so a value that is legal in one place reads the same way
	 * everywhere it appears.
	 */
	const EMPHASIS        = array( 'strong', 'normal', 'muted' );
	const LINE_STYLES     = array( 'solid', 'dashed', 'dotted' );
	const RULE_STYLES     = array( 'none', 'solid', 'dotted', 'dashed' );
	const POINT_STYLES    = array( 'filled', 'hollow', 'none' );
	const LEADERS         = array( 'none', 'straight', 'elbow' );
	const CALLOUT_SIZES   = array( 'large', 'small' );
	const MARKER_TYPES    = array( 'vertical', 'horizontal' );
	const CURVES          = array( 'linear', 'smooth', 'step' );
	const ARRANGEMENTS    = array( 'grouped', 'stacked' );
	const VALUE_LABELS    = array( 'none', 'inside', 'above' );
	const PIE_LABELS      = array( 'none', 'inside', 'outside', 'legend' );
	const LEGEND_POSITIONS = array( 'none', 'top', 'bottom', 'left', 'right' );
	const ALIGNMENTS      = array( 'left', 'centre', 'right' );

	/**
	 * Where a label sits relative to the thing it labels. The four sides
	 * suit markers, the corners are there for data point labels that
	 * would otherwise sit on the line.
	 */
	const LABEL_POSITIONS = array(
		'top',
		'bottom',
		'left',
		'right',
		'top-left',
		'top-right',
		'bottom-left',
		'bottom-right',
	);

	/** Marker labels only ever sit at one end of the line or the other. */
	const MARKER_LABEL_POSITIONS = array( 'top', 'bottom', 'left', 'right' );

	/** The default frame proportion when a chart does not ask for one. */
	const DEFAULT_ASPECT_RATIO = '16:9';

	/*
	 * ====================================================================
	 * Top level
	 * ====================================================================
	 */

	/**
	 * The top level keys of a chart definition, in the order section 4.1
	 * of the brief lists them.
	 *
	 * @return array<string,array>
	 */
	public static function definition_spec() {
		return array(
			'kdna_chart' => array(
				'kind'     => 'int',
				'required' => true,
				'label'    => 'Schema version. Required. 1 for v1.0.0.',
			),
			'title'      => array(
				'kind'     => 'text',
				'required' => true,
				'label'    => 'Post title in the library. Required.',
			),
			'type'       => array(
				'kind'     => 'enum',
				'values'   => self::TYPES,
				'required' => true,
				'label'    => 'line, area, bar, column, pie, donut, stat. Required.',
			),
			'engine'     => array(
				'kind'        => 'enum',
				'values'      => self::ENGINES,
				'allow_empty' => true,
				'default'     => '',
				'label'       => 'svg or chartjs. Optional, falls back to the global default.',
			),
			'options'    => array(
				'kind'  => 'options',
				'label' => 'Type specific rendering options.',
			),
			'axes'       => array(
				'kind'  => 'object',
				'keys'  => array(
					'x' => self::axis_spec( 'x' ),
					'y' => self::axis_spec( 'y' ),
				),
				'label' => 'x and y definitions. Unused by pie, donut and stat.',
			),
			'series'     => array(
				'kind'  => 'list',
				'of'    => self::series_spec(),
				'label' => 'The data.',
			),
			'markers'    => array(
				'kind'  => 'list',
				'of'    => self::marker_spec(),
				'label' => 'Vertical or horizontal event lines.',
			),
			'points'     => array(
				'kind'  => 'list',
				'of'    => self::emphasised_point_spec(),
				'label' => 'Emphasised individual data points.',
			),
			'callouts'   => array(
				'kind'  => 'list',
				'of'    => self::callout_spec(),
				'label' => 'Large number annotations with optional leader lines.',
			),
			'notes'      => array(
				'kind'  => 'list',
				'of'    => self::note_spec(),
				'label' => 'Small free floating labels.',
			),
			'source'     => array(
				'kind'    => 'text',
				'default' => '',
				'label'   => 'Attribution line rendered beneath the chart.',
			),
			'caption'    => array(
				'kind'    => 'html',
				'default' => '',
				'label'   => 'Optional caption above or below the chart.',
			),
			'style'      => array(
				'kind'  => 'map',
				'label' => 'Per chart overrides. Any key omitted inherits global.',
			),
		);
	}

	/*
	 * ====================================================================
	 * Axes
	 * ====================================================================
	 */

	/**
	 * One axis. min, max and baseline are all optional: when they are
	 * absent the scale engine infers them from the data with headroom.
	 *
	 * @param string $which 'x' or 'y'.
	 */
	public static function axis_spec( $which = 'x' ) {
		return array(
			'kind' => 'object',
			'keys' => array(
				'label'      => array(
					'kind'    => 'text',
					'default' => '',
					'label'   => 'Axis title.',
				),
				'min'        => array(
					'kind'  => 'number',
					'label' => 'Lower bound. Omit to infer from the data.',
				),
				'max'        => array(
					'kind'  => 'number',
					'label' => 'Upper bound. Omit to infer from the data.',
				),
				'baseline'   => array(
					'kind'  => 'number',
					'label' => 'Where an area fill or a bar is measured from. Defaults to min.',
				),
				'categories' => array(
					'kind'  => 'list',
					'of'    => array( 'kind' => 'text' ),
					'label' => 'Category names, for bar and column charts with a categorical axis.',
				),
				'ticks'      => array(
					'kind'  => 'list',
					'of'    => self::tick_spec(),
					'label' => 'Explicit ticks. Omit to generate nice numbers automatically.',
				),
			),
			'meta' => array( 'axis' => $which ),
		);
	}

	/**
	 * One axis tick. emphasis is what darkens the values the argument
	 * depends on and mutes the rest. rule is the gridline drawn at it.
	 */
	public static function tick_spec() {
		return array(
			'kind' => 'object',
			'keys' => array(
				'value'    => array(
					'kind'     => 'number',
					'required' => true,
					'label'    => 'Where on the axis the tick sits.',
				),
				'label'    => array(
					'kind'    => 'text',
					'default' => '',
					'label'   => 'What the tick reads. Defaults to the value.',
				),
				'emphasis' => array(
					'kind'    => 'enum',
					'values'  => self::EMPHASIS,
					'default' => 'normal',
					'label'   => 'strong, normal or muted.',
				),
				'rule'     => array(
					'kind'    => 'enum',
					'values'  => self::RULE_STYLES,
					'default' => 'none',
					'label'   => 'Gridline at this tick: none, solid, dotted or dashed.',
				),
			),
		);
	}

	/*
	 * ====================================================================
	 * Series
	 * ====================================================================
	 */

	/**
	 * One series.
	 *
	 * Both shapes are declared on every series, because both are real
	 * data and neither should be thrown away when a chart changes type.
	 * segments carry plotted points for line and area. data carries flat
	 * label and value pairs for bar, column, pie, donut and stat. A
	 * renderer reads whichever its type uses.
	 */
	public static function series_spec() {
		return array(
			'kind' => 'object',
			'keys' => array(
				'id'       => array(
					'kind'    => 'text',
					'default' => '',
					'label'   => 'Stable identifier, generated when absent.',
				),
				'label'    => array(
					'kind'    => 'text',
					'default' => '',
					'label'   => 'Series name, used in the legend and the screen reader table.',
				),
				'emphasis' => array(
					'kind'    => 'enum',
					'values'  => self::EMPHASIS,
					'default' => 'normal',
					'label'   => 'Series wide emphasis, overridden per segment.',
				),
				'segments' => array(
					'kind'  => 'list',
					'of'    => self::segment_spec(),
					'label' => 'Plotted line segments. Line and area.',
				),
				'data'     => array(
					'kind'  => 'list',
					'of'    => self::datum_spec(),
					'label' => 'Flat label and value pairs. Bar, column, pie, donut and stat.',
				),
			),
		);
	}

	/**
	 * One segment of a line. This is what allows a single line to change
	 * character partway along, the dotted projection before year zero
	 * becoming a solid emphasised line after it. Segments sharing an
	 * endpoint join seamlessly.
	 */
	public static function segment_spec() {
		return array(
			'kind' => 'object',
			'keys' => array(
				'style'    => array(
					'kind'    => 'enum',
					'values'  => self::LINE_STYLES,
					'default' => 'solid',
					'label'   => 'solid, dashed or dotted.',
				),
				'emphasis' => array(
					'kind'    => 'enum',
					'values'  => self::EMPHASIS,
					'default' => 'normal',
					'label'   => 'strong, normal or muted. Maps to a CSS variable, not a fixed colour.',
				),
				'points'   => array(
					'kind'     => 'list',
					'of'       => array( 'kind' => 'point' ),
					'required' => true,
					'label'    => 'An ordered list of [x, y] pairs.',
				),
			),
		);
	}

	/**
	 * One label and value pair, for the categorical and typographic
	 * types. suffix is what turns 30 into 30% on a stat block.
	 */
	public static function datum_spec() {
		return array(
			'kind' => 'object',
			'keys' => array(
				'label'    => array(
					'kind'    => 'text',
					'default' => '',
					'label'   => 'What this value is called.',
				),
				'value'    => array(
					'kind'     => 'number',
					'required' => true,
					'label'    => 'The figure itself.',
				),
				'suffix'   => array(
					'kind'    => 'text',
					'default' => '',
					'label'   => 'Trailing unit, for example a per cent sign. Stat blocks mainly.',
				),
				'emphasis' => array(
					'kind'    => 'enum',
					'values'  => self::EMPHASIS,
					'default' => 'normal',
					'label'   => 'Per bar or per segment emphasis.',
				),
			),
		);
	}

	/*
	 * ====================================================================
	 * Annotation layer
	 * ====================================================================
	 */

	/**
	 * A marker is an event line across the plot with a heading on it.
	 * A vertical marker needs an x, a horizontal marker needs a y.
	 */
	public static function marker_spec() {
		return array(
			'kind' => 'object',
			'keys' => array(
				'type'           => array(
					'kind'     => 'enum',
					'values'   => self::MARKER_TYPES,
					'required' => true,
					'label'    => 'vertical or horizontal.',
				),
				'x'              => array(
					'kind'  => 'number',
					'label' => 'Where a vertical marker sits.',
				),
				'y'              => array(
					'kind'  => 'number',
					'label' => 'Where a horizontal marker sits.',
				),
				'label'          => array(
					'kind'    => 'text',
					'default' => '',
					'label'   => 'The heading carried by the line.',
				),
				'label_position' => array(
					'kind'    => 'enum',
					'values'  => self::MARKER_LABEL_POSITIONS,
					'default' => 'top',
					'label'   => 'Which end of the line the heading sits at.',
				),
				'style'          => array(
					'kind'    => 'enum',
					'values'  => self::LINE_STYLES,
					'default' => 'dashed',
					'label'   => 'solid, dashed or dotted.',
				),
			),
		);
	}

	/**
	 * An emphasised data point. This exists separately from the series
	 * data because emphasising a point is a design decision, not a data
	 * one, and the two should be editable apart from each other.
	 */
	public static function emphasised_point_spec() {
		return array(
			'kind' => 'object',
			'keys' => array(
				'x'              => array(
					'kind'     => 'number',
					'required' => true,
					'label'    => 'Point position on the x axis.',
				),
				'y'              => array(
					'kind'     => 'number',
					'required' => true,
					'label'    => 'Point position on the y axis.',
				),
				'style'          => array(
					'kind'    => 'enum',
					'values'  => self::POINT_STYLES,
					'default' => 'filled',
					'label'   => 'filled, hollow or none.',
				),
				'label'          => array(
					'kind'    => 'text',
					'default' => '',
					'label'   => 'Optional label beside the point.',
				),
				'label_position' => array(
					'kind'    => 'enum',
					'values'  => self::LABEL_POSITIONS,
					'default' => 'top',
					'label'   => 'Where the label sits relative to the point.',
				),
				'series'         => array(
					'kind'    => 'text',
					'default' => '',
					'label'   => 'Series id this point belongs to, when a chart has several.',
				),
			),
		);
	}

	/**
	 * A callout is the large number annotation, the thing that makes an
	 * editorial chart argue rather than report. Its anchor is either a
	 * single point or a from and to span; a span draws a leader that
	 * brackets the range the number describes.
	 */
	public static function callout_spec() {
		return array(
			'kind' => 'object',
			'keys' => array(
				'value'   => array(
					'kind'     => 'text',
					'required' => true,
					'label'    => 'The figure, written as it should read, for example -30%.',
				),
				'caption' => array(
					'kind'    => 'text',
					'default' => '',
					'label'   => 'The small line beneath the figure.',
				),
				'size'    => array(
					'kind'    => 'enum',
					'values'  => self::CALLOUT_SIZES,
					'default' => 'large',
					'label'   => 'large or small.',
				),
				'anchor'  => array(
					'kind'     => 'anchor',
					'required' => true,
					'label'    => 'Either {x, y} or {from: {x, y}, to: {x, y}}.',
				),
				'leader'  => array(
					'kind'    => 'enum',
					'values'  => self::LEADERS,
					'default' => 'none',
					'label'   => 'none, straight or elbow.',
				),
			),
		);
	}

	/**
	 * A note is a small free floating label placed in data coordinates.
	 */
	public static function note_spec() {
		return array(
			'kind' => 'object',
			'keys' => array(
				'text'  => array(
					'kind'     => 'text',
					'required' => true,
					'label'    => 'What the note says.',
				),
				'at'    => array(
					'kind'     => 'point_object',
					'required' => true,
					'label'    => 'Where it sits, as {x, y} in data coordinates.',
				),
				'align' => array(
					'kind'    => 'enum',
					'values'  => self::ALIGNMENTS,
					'default' => 'left',
					'label'   => 'Text alignment within the note.',
				),
				'width' => array(
					'kind'  => 'number',
					'min'   => 0,
					'label' => 'Wrapping width in SVG units. Omit to let it run on one line.',
				),
			),
		);
	}

	/*
	 * ====================================================================
	 * Options, resolved per chart type
	 * ====================================================================
	 */

	/**
	 * Options every type understands.
	 */
	public static function common_options_spec() {
		return array(
			'aspect_ratio' => array(
				'kind'    => 'ratio',
				'default' => self::DEFAULT_ASPECT_RATIO,
				'label'   => 'Frame proportion, written width:height, for example 16:9.',
			),
			'legend'       => array(
				'kind'    => 'enum',
				'values'  => self::LEGEND_POSITIONS,
				'default' => 'none',
				'label'   => 'Where the legend sits, or none.',
			),
			'data_table'   => array(
				'kind'    => 'bool',
				'default' => false,
				'label'   => 'Output a visually hidden data table for screen readers.',
			),
		);
	}

	/**
	 * The options a given chart type understands, common options
	 * included. An option outside this set is an unknown key and gets
	 * discarded and reported on import.
	 *
	 * @param string $type One of TYPES.
	 * @return array<string,array>
	 */
	public static function options_spec( $type ) {
		$common = self::common_options_spec();

		switch ( $type ) {
			case 'line':
			case 'area':
				return array_merge(
					$common,
					array(
						'area_fill' => array(
							'kind'    => 'bool',
							'default' => ( 'area' === $type ),
							'label'   => 'Fill the space beneath the line with a gradient.',
						),
						'curve'     => array(
							'kind'    => 'enum',
							'values'  => self::CURVES,
							'default' => 'linear',
							'label'   => 'linear, smooth or step.',
						),
					)
				);

			case 'bar':
			case 'column':
				return array_merge(
					$common,
					array(
						'arrangement'   => array(
							'kind'    => 'enum',
							'values'  => self::ARRANGEMENTS,
							'default' => 'grouped',
							'label'   => 'grouped or stacked, for charts with several series.',
						),
						'value_labels'  => array(
							'kind'    => 'enum',
							'values'  => self::VALUE_LABELS,
							'default' => 'none',
							'label'   => 'none, inside or above the bar.',
						),
						'corner_radius' => array(
							'kind'    => 'number',
							'min'     => 0,
							'max'     => 100,
							'default' => 0,
							'label'   => 'Bar corner radius in SVG units.',
						),
						'bar_gap'       => array(
							'kind'    => 'number',
							'min'     => 0,
							'max'     => 0.9,
							'default' => 0.2,
							'label'   => 'Gap between bars within a group, as a fraction of the slot.',
						),
						'group_gap'     => array(
							'kind'    => 'number',
							'min'     => 0,
							'max'     => 0.9,
							'default' => 0.3,
							'label'   => 'Gap between groups, as a fraction of the slot.',
						),
					)
				);

			case 'pie':
			case 'donut':
				return array_merge(
					$common,
					array(
						'start_angle'  => array(
							'kind'    => 'number',
							'min'     => -360,
							'max'     => 360,
							'default' => -90,
							'label'   => 'Where the first segment begins. -90 is twelve o\'clock.',
						),
						'inner_radius' => array(
							'kind'    => 'number',
							'min'     => 0,
							'max'     => 0.95,
							'default' => ( 'donut' === $type ) ? 0.6 : 0,
							'label'   => 'Hole size as a fraction of the radius. 0 is a pie.',
						),
						'segment_gap'  => array(
							'kind'    => 'number',
							'min'     => 0,
							'max'     => 20,
							'default' => 0,
							'label'   => 'Gap between segments in degrees.',
						),
						'labels'       => array(
							'kind'    => 'enum',
							'values'  => self::PIE_LABELS,
							'default' => 'outside',
							'label'   => 'none, inside, outside with leader lines, or legend.',
						),
					)
				);

			case 'stat':
				return array_merge(
					$common,
					array(
						'columns' => array(
							'kind'    => 'int',
							'min'     => 1,
							'max'     => 6,
							'default' => 3,
							'label'   => 'How many figures sit across a row on desktop.',
						),
						'align'   => array(
							'kind'    => 'enum',
							'values'  => self::ALIGNMENTS,
							'default' => 'left',
							'label'   => 'left, centre or right.',
						),
					)
				);
		}

		return $common;
	}

	/*
	 * ====================================================================
	 * Defaults
	 * ====================================================================
	 */

	/**
	 * Every option for a type, at its default value. The renderers merge
	 * a chart's stored options over the top of this, so a definition
	 * never has to state an option it does not care about.
	 *
	 * @param string $type One of TYPES.
	 * @return array
	 */
	public static function default_options( $type ) {
		$out = array();
		foreach ( self::options_spec( $type ) as $key => $node ) {
			if ( array_key_exists( 'default', $node ) ) {
				$out[ $key ] = $node['default'];
			}
		}
		return $out;
	}

	/**
	 * A complete, valid, empty chart definition of a given type. This is
	 * what Add New writes, and what the importer starts from before it
	 * lays a file over the top.
	 *
	 * @param string $type One of TYPES.
	 * @return array
	 */
	public static function default_definition( $type ) {
		$type = self::is_type( $type ) ? $type : 'line';

		$definition = array(
			'kdna_chart' => self::VERSION,
			'type'       => $type,
			// Empty means inherit the global default engine.
			'engine'     => '',
			'options'    => array(),
			'axes'       => array(),
			'series'     => array(),
			'markers'    => array(),
			'points'     => array(),
			'callouts'   => array(),
			'notes'      => array(),
			'source'     => '',
			'caption'    => '',
			// Empty means inherit every value from the global styling page.
			'style'      => array(),
		);

		if ( in_array( $type, array( 'pie', 'donut' ), true ) ) {
			$definition['series'] = array(
				array(
					'id'   => 'segments',
					'data' => array(
						array( 'label' => __( 'First', 'kdna-charts' ), 'value' => 50 ),
						array( 'label' => __( 'Second', 'kdna-charts' ), 'value' => 30 ),
						array( 'label' => __( 'Third', 'kdna-charts' ), 'value' => 20 ),
					),
				),
			);
			return $definition;
		}

		if ( 'stat' === $type ) {
			$definition['series'] = array(
				array(
					'id'   => 'stats',
					'data' => array(
						array( 'label' => __( 'First figure', 'kdna-charts' ), 'value' => 30, 'suffix' => '%' ),
						array( 'label' => __( 'Second figure', 'kdna-charts' ), 'value' => 12, 'suffix' => '%' ),
					),
				),
			);
			return $definition;
		}

		if ( in_array( $type, array( 'bar', 'column' ), true ) ) {
			$definition['axes']   = array(
				'x' => array( 'label' => '', 'categories' => array() ),
				'y' => array( 'label' => '' ),
			);
			$definition['series'] = array(
				array(
					'id'    => 'series_1',
					'label' => __( 'Series 1', 'kdna-charts' ),
					'data'  => array(
						array( 'label' => __( 'First', 'kdna-charts' ), 'value' => 0 ),
						array( 'label' => __( 'Second', 'kdna-charts' ), 'value' => 0 ),
					),
				),
			);
			return $definition;
		}

		// line and area.
		$definition['axes']   = array(
			'x' => array( 'label' => '' ),
			'y' => array( 'label' => '' ),
		);
		$definition['series'] = array(
			array(
				'id'       => 'series_1',
				'label'    => __( 'Series 1', 'kdna-charts' ),
				'segments' => array(
					array(
						'style'    => 'solid',
						'emphasis' => 'strong',
						'points'   => array( array( 0, 0 ), array( 1, 0 ) ),
					),
				),
			),
		);

		return $definition;
	}

	/*
	 * ====================================================================
	 * Small queries the rest of the plugin asks
	 * ====================================================================
	 */

	public static function is_type( $type ) {
		return is_string( $type ) && in_array( $type, self::TYPES, true );
	}

	public static function is_engine( $engine ) {
		return is_string( $engine ) && in_array( $engine, self::ENGINES, true );
	}

	/** True when this type is drawn against x and y axes. */
	public static function uses_axes( $type ) {
		return in_array( $type, self::AXIS_TYPES, true );
	}

	/** True when this type reads series[].segments rather than series[].data. */
	public static function uses_segments( $type ) {
		return in_array( $type, array( 'line', 'area' ), true );
	}

	/** True when this type reads series[].data rather than series[].segments. */
	public static function uses_data( $type ) {
		return in_array( $type, self::CATEGORICAL_TYPES, true );
	}

	/**
	 * True when the annotation layer can draw against this type. Pie,
	 * donut and stat have no plot coordinates for a marker to sit on.
	 * Annotations are still stored for those types, they are simply not
	 * drawn, in keeping with the rule that changing how a chart renders
	 * never destroys what it holds.
	 */
	public static function draws_annotations( $type ) {
		return in_array( $type, self::PLOTTED_TYPES, true );
	}

	/**
	 * The top level keys, in schema order. Used by the importer to walk
	 * a definition and by the exporter to write one back out.
	 */
	public static function top_level_keys() {
		return array_keys( self::definition_spec() );
	}

	/**
	 * Human readable label for a chart type.
	 */
	public static function type_label( $type ) {
		$labels = array(
			'line'   => __( 'Line', 'kdna-charts' ),
			'area'   => __( 'Area', 'kdna-charts' ),
			'bar'    => __( 'Bar', 'kdna-charts' ),
			'column' => __( 'Column', 'kdna-charts' ),
			'pie'    => __( 'Pie', 'kdna-charts' ),
			'donut'  => __( 'Donut', 'kdna-charts' ),
			'stat'   => __( 'Stat block', 'kdna-charts' ),
		);
		return isset( $labels[ $type ] ) ? $labels[ $type ] : '';
	}

	/**
	 * Path to the prose mirror of this schema, the reference that gets
	 * pasted into a Claude conversation alongside an article.
	 */
	public static function reference_path() {
		return KDNA_CHARTS_PATH . 'docs/chart-schema.md';
	}

	/**
	 * The schema reference as text, or '' when the file is missing.
	 */
	public static function reference_text() {
		$path = self::reference_path();
		if ( ! is_readable( $path ) ) {
			return '';
		}
		$text = file_get_contents( $path );
		return is_string( $text ) ? $text : '';
	}

	/**
	 * The authoring prompt from section 5 of the brief, with the schema
	 * reference folded in, ready to paste into a Claude conversation
	 * along with the article text.
	 */
	public static function authoring_prompt() {
		$rules = array(
			__( 'Use only figures stated or directly implied by the text. Never invent data.', 'kdna-charts' ),
			__( 'Where the text describes a rate rather than points, derive the points from the rate and record that derivation in a notes entry.', 'kdna-charts' ),
			__( 'Use segments to change line character where the text describes a change in behaviour.', 'kdna-charts' ),
			__( 'Add a marker for any named turning point the text identifies.', 'kdna-charts' ),
			__( 'Add a callout for the single most important number in the text, and at most one secondary callout.', 'kdna-charts' ),
			__( 'Use emphasis on axis ticks to darken the values the argument depends on and mute the rest.', 'kdna-charts' ),
			__( 'Leave style as an empty object so the site global styling applies.', 'kdna-charts' ),
			__( 'Set source to the study or publication named in the text.', 'kdna-charts' ),
		);

		$prompt  = __( 'You are producing a chart definition file for the KDNA Charts WordPress plugin. Read the article text below and return a single JSON object matching the KDNA Charts schema version 1, and nothing else, no preamble, no markdown fences.', 'kdna-charts' );
		$prompt .= "\n\n" . __( 'Rules:', 'kdna-charts' ) . "\n";
		foreach ( $rules as $rule ) {
			$prompt .= '- ' . $rule . "\n";
		}

		$reference = self::reference_text();
		$prompt   .= "\n" . __( 'Schema reference:', 'kdna-charts' ) . "\n\n";
		$prompt   .= '' !== $reference ? $reference : __( '[schema reference unavailable, docs/chart-schema.md is missing]', 'kdna-charts' );
		$prompt   .= "\n\n" . __( 'Article text:', 'kdna-charts' ) . "\n[paste article]\n";

		return $prompt;
	}
}
