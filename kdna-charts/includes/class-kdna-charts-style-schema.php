<?php
/**
 * Style control schema: the single source of truth for every style
 * control in the plugin.
 *
 * Four consumers read this array and nothing else:
 *
 *   1. KDNA_Charts_Style_Admin renders its controls from it,
 *   2. KDNA_Charts_Style_Admin sanitises saved values against it,
 *   3. KDNA_Charts_Style_Resolver turns stored values into CSS custom
 *      properties from it,
 *   4. the live preview, in JavaScript, resolves from the same array
 *      shipped to the page.
 *
 * Adding a control is therefore one array entry rather than edits across
 * five files.
 *
 * ── One control, one custom property ──────────────────────────────────
 *
 * Every entry writes exactly one custom property, and every custom
 * property declared in assets/css/kdna-charts.css has exactly one entry.
 * That one-to-one rule is checked by a test rather than trusted, because
 * the two failure modes it prevents are both silent: a control that
 * writes a property no rule reads does nothing at all, and a property
 * with no control cannot be reached from the admin.
 *
 * This is where the port from KDNA Tables deliberately diverges. Tables
 * groups its typography, border and background controls, so one control
 * there owns several properties through a fields array, and the
 * resolver, the sanitiser and the editor all carry a nesting level for
 * it. Charts does not need that: the stylesheet already exposes a flat,
 * complete vocabulary of single-purpose properties, so a group would be
 * a second structure laid over a structure that is already right. What
 * groups bought Tables, a readable panel, is bought here by the
 * 'group' key, which is a display heading and nothing more.
 *
 * ── Control definition ────────────────────────────────────────────────
 *
 * Required on every entry:
 *   key         string  Array key, repeated inside the entry so a
 *                       detached entry still knows its own name.
 *   label       string  Admin-facing label.
 *   section     string  One of SECTION_ORDER.
 *   type        string  colour | dimensions | slider | select | number
 *   css_var     string  The custom property it writes.
 *   responsive  bool    Whether it stores per-breakpoint values.
 *   placeholder string  What the stylesheet renders when this control is
 *                       unset. Display only: see the note below.
 *
 * Optional, by type:
 *   group       string  Sub-heading inside the section.
 *   units       array   dimensions, slider. First entry is the default.
 *   options     array   select. Value => label.
 *   value_map   array   select. Option value => CSS value, where the two
 *                       differ (an alignment mapping to a margin, say).
 *   free_text   bool    select. An open field with suggestions rather
 *                       than an allow-list.
 *   suggestions array   select with free_text. Datalist entries.
 *   min/max/step number slider, number.
 *   description string  Shown under the control.
 *
 * ── Why every default is null ─────────────────────────────────────────
 *
 * Not one control here carries a default value, and that is the whole
 * design rather than an omission.
 *
 * The stylesheet already holds every default, once, as the --auto layer
 * described at the top of assets/css/kdna-charts.css. A schema default
 * would be a second copy of a value that already exists, and two copies
 * of a number are two numbers that can disagree, which is exactly the
 * bug Tables carries a comment about, where an upgraded site served a
 * cached string built by the previous version's defaults.
 *
 * So an unset control emits nothing, the stylesheet decides, and
 * "reset to plugin defaults" is literally "store nothing". A later
 * change to a default in the stylesheet reaches every site, including
 * the ones that have saved styles, because saved styles only ever carry
 * what somebody actually set.
 *
 * The 'placeholder' key carries the stylesheet's value so the admin can
 * show it. It is display only: nothing reads it to decide what to
 * render, so a stale placeholder is a cosmetic bug rather than a
 * behavioural one. A test keeps it honest anyway.
 *
 * ── Value shapes ──────────────────────────────────────────────────────
 *
 *   colour       '#ffffff' | 'rgba(0,0,0,.5)' | 'transparent'
 *   select       'left'
 *   number       0.16
 *   slider       array( 'size' => 12, 'unit' => 'px' )
 *   dimensions   array( 'top' => 14, 'right' => 16, 'bottom' => 14,
 *                       'left' => 16, 'unit' => 'px' )
 *   responsive   array( 'desktop' => <shape above>,
 *                       'tablet'  => <shape above>,
 *                       'mobile'  => <shape above> )
 *                Breakpoints left unset are ABSENT from the array rather
 *                than present and empty. The resolver relies on that to
 *                omit the suffixed property entirely.
 *
 * @package KDNA_Charts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KDNA_Charts_Style_Schema {

	/**
	 * Section keys in admin display order.
	 *
	 * These are the sections the brief names, in the order it names them,
	 * which is roughly the order a chart is read: the frame around it,
	 * the words under it, then the plot and everything drawn on it.
	 */
	const SECTION_ORDER = array(
		'frame',
		'caption',
		'plot',
		'gridlines',
		'axis',
		'series',
		'points',
		'markers',
		'callouts',
		'notes',
		'legend',
		'source',
		'stats',
	);

	/**
	 * Breakpoint keys, in cascade order.
	 *
	 * 'desktop' writes the base property name; the others append
	 * '-' . $device. The stylesheet's resolution layer reads them in
	 * reverse, so mobile falls back to tablet and tablet to desktop.
	 */
	const DEVICES = array( 'desktop', 'tablet', 'mobile' );

	/**
	 * Datalist suggestions for a font family.
	 *
	 * Suggestions, not an allow-list: the field stays free text so a
	 * site's own Elementor faces can be typed in by name. 'inherit'
	 * leads the list because clearing a font is the most common thing to
	 * want from it, and the sanitiser treats that value as unset rather
	 * than storing a word that means nothing.
	 */
	const FONT_SUGGESTIONS = array(
		'inherit',
		'Arial, Helvetica, sans-serif',
		'Georgia, serif',
		'"Helvetica Neue", Helvetica, Arial, sans-serif',
		'"Times New Roman", Times, serif',
		'Tahoma, Geneva, sans-serif',
		'Verdana, Geneva, sans-serif',
		'"Courier New", Courier, monospace',
		'system-ui, sans-serif',
	);

	/**
	 * Datalist suggestions for a dash pattern.
	 *
	 * A dash array is two lengths, and the useful values are a handful of
	 * rhythms rather than a continuous range, so this is an open field
	 * with the rhythms offered rather than two sliders. Values are in
	 * user units on the 1000 unit canvas, like everything else drawn
	 * inside the viewBox.
	 */
	const DASH_SUGGESTIONS = array(
		'none',
		'2px 8px',
		'4px 6px',
		'10px 8px',
		'14px 10px',
		'20px 10px',
		'0.1px 9px',
	);

	/** Built schema, memoised per request. */
	private static $controls = null;

	/**
	 * Section key => label.
	 */
	public static function get_sections() {
		return array(
			'frame'     => __( 'Chart Frame', 'kdna-charts' ),
			'caption'   => __( 'Caption', 'kdna-charts' ),
			'plot'      => __( 'Plot Area', 'kdna-charts' ),
			'gridlines' => __( 'Gridlines', 'kdna-charts' ),
			'axis'      => __( 'Axis Labels', 'kdna-charts' ),
			'series'    => __( 'Series', 'kdna-charts' ),
			'points'    => __( 'Data Points', 'kdna-charts' ),
			'markers'   => __( 'Markers', 'kdna-charts' ),
			'callouts'  => __( 'Callouts', 'kdna-charts' ),
			'notes'     => __( 'Notes', 'kdna-charts' ),
			'legend'    => __( 'Legend', 'kdna-charts' ),
			'source'    => __( 'Source Line', 'kdna-charts' ),
			'stats'     => __( 'Stat Blocks', 'kdna-charts' ),
		);
	}

	/**
	 * Breakpoint key => label, in cascade order.
	 */
	public static function get_devices() {
		return array(
			'desktop' => __( 'Desktop', 'kdna-charts' ),
			'tablet'  => __( 'Tablet', 'kdna-charts' ),
			'mobile'  => __( 'Mobile', 'kdna-charts' ),
		);
	}

	/**
	 * The schema: control key => definition.
	 */
	public static function get() {
		if ( null === self::$controls ) {
			self::$controls = self::build();
		}
		return self::$controls;
	}

	/**
	 * One control definition, or null when the key is unknown. Callers
	 * sanitising input use the null return to discard stray keys.
	 */
	public static function get_control( $key ) {
		$controls = self::get();
		return isset( $controls[ $key ] ) ? $controls[ $key ] : null;
	}

	/**
	 * Controls grouped by section, in SECTION_ORDER. A section with no
	 * controls comes back as an empty array rather than being missing.
	 */
	public static function get_by_section() {
		$grouped = array_fill_keys( self::SECTION_ORDER, array() );

		foreach ( self::get() as $key => $control ) {
			$section = isset( $control['section'] ) ? $control['section'] : '';
			if ( ! isset( $grouped[ $section ] ) ) {
				continue;
			}
			$grouped[ $section ][ $key ] = $control;
		}

		return $grouped;
	}

	/**
	 * Every custom property the schema can write.
	 *
	 * The stylesheet test reads this to check that each one is declared,
	 * and the resolver's output check reads nothing else.
	 *
	 * @return string[]
	 */
	public static function css_vars() {
		$vars = array();
		foreach ( self::get() as $control ) {
			$vars[] = $control['css_var'];
		}
		return $vars;
	}

	/**
	 * Whether a control writes per-breakpoint properties.
	 */
	public static function is_responsive( array $control ) {
		return ! empty( $control['responsive'] );
	}

	/**
	 * Read a stored value as a device map, wrapping a bare one as
	 * desktop.
	 *
	 * ── Why this is not just is_array() ───────────────────────────────
	 *
	 * Half the value shapes in this schema ARE arrays. A slider is
	 * array( 'size' => 18, 'unit' => 'px' ) and a dimensions control is
	 * four sides and a unit, so testing whether the incoming value is an
	 * array says nothing about whether it is a device map.
	 *
	 * Treating one as a map is not a harmless mistake either: the reader
	 * then looks for desktop, tablet and mobile keys, finds none, and
	 * resolves the control to nothing. A hand-written preset saying
	 * "axis_label_size": { "size": 18, "unit": "px" }, which is the
	 * obvious way to write it, would import cleanly and then do nothing
	 * at all.
	 *
	 * So the test is whether any DEVICE key is present. The two shapes
	 * cannot collide: no value shape in this schema has a key called
	 * desktop, tablet or mobile.
	 *
	 * @param mixed $value A stored value, in either shape.
	 * @return array Device key => value.
	 */
	public static function as_device_map( $value ) {
		if ( is_array( $value ) ) {
			foreach ( self::DEVICES as $device ) {
				if ( array_key_exists( $device, $value ) ) {
					return $value;
				}
			}
		}

		return array( 'desktop' => $value );
	}

	/* ─── Building ──────────────────────────────────────────────────── */

	private static function build() {
		$controls = array_merge(
			self::frame_controls(),
			self::caption_controls(),
			self::plot_controls(),
			self::gridline_controls(),
			self::axis_controls(),
			self::series_controls(),
			self::point_controls(),
			self::marker_controls(),
			self::callout_controls(),
			self::note_controls(),
			self::legend_controls(),
			self::source_controls(),
			self::stat_controls()
		);

		// Stamp each entry with its own key, so a definition passed around
		// on its own still identifies itself, and normalise the optional
		// keys so no consumer has to test for their presence.
		foreach ( $controls as $key => $control ) {
			$controls[ $key ]              = array_merge(
				array(
					'group'       => '',
					'description' => '',
					'placeholder' => '',
					'responsive'  => false,
				),
				$control
			);
			$controls[ $key ]['key']       = $key;
		}

		/**
		 * Filter the style control schema.
		 *
		 * @param array $controls Control key => definition.
		 */
		return apply_filters( 'kdna_charts_style_schema', $controls );
	}

	/* ─── Control builders ──────────────────────────────────────────── */

	/**
	 * A colour.
	 */
	private static function colour( $label, $section, $css_var, $placeholder, $group = '', $description = '' ) {
		return array(
			'label'       => $label,
			'section'     => $section,
			'group'       => $group,
			'type'        => 'colour',
			'css_var'     => $css_var,
			'responsive'  => false,
			'placeholder' => $placeholder,
			'description' => $description,
		);
	}

	/**
	 * A length, responsive unless told otherwise.
	 *
	 * ── On the default unit ───────────────────────────────────────────
	 *
	 * Most lengths in this plugin are drawn inside the SVG, where px is
	 * a user unit on the 1000 unit canvas rather than a screen pixel.
	 * That is why a marker label defaults to 20px and still looks like
	 * body copy: the viewBox scales it down. Sizes outside the SVG (the
	 * caption, the source line, the stat blocks) are in rem, and their
	 * unit lists say so.
	 */
	private static function size( $label, $section, $css_var, $placeholder, $args = array() ) {
		return array_merge(
			array(
				'label'       => $label,
				'section'     => $section,
				'group'       => '',
				'type'        => 'slider',
				'css_var'     => $css_var,
				'units'       => array( 'px', 'em', 'rem' ),
				'min'         => 0,
				'max'         => 100,
				'step'        => 0.5,
				'responsive'  => true,
				'placeholder' => $placeholder,
				'description' => '',
			),
			$args
		);
	}

	/**
	 * Four sides plus a unit.
	 */
	private static function box( $label, $section, $css_var, $placeholder, $args = array() ) {
		return array_merge(
			array(
				'label'       => $label,
				'section'     => $section,
				'group'       => '',
				'type'        => 'dimensions',
				'css_var'     => $css_var,
				'units'       => array( 'px', 'rem', 'em', '%' ),
				'responsive'  => true,
				'placeholder' => $placeholder,
				'description' => '',
			),
			$args
		);
	}

	/**
	 * A select.
	 */
	private static function choice( $label, $section, $css_var, array $options, $placeholder, $args = array() ) {
		return array_merge(
			array(
				'label'       => $label,
				'section'     => $section,
				'group'       => '',
				'type'        => 'select',
				'css_var'     => $css_var,
				'options'     => $options,
				'responsive'  => false,
				'placeholder' => $placeholder,
				'description' => '',
			),
			$args
		);
	}

	/**
	 * An open text field with suggestions: a font stack, or a dash
	 * pattern. Modelled as a select so the sanitiser has one fewer type
	 * to know about, and flagged free_text so it is not treated as an
	 * allow-list.
	 */
	private static function open_text( $label, $section, $css_var, array $suggestions, $placeholder, $args = array() ) {
		return array_merge(
			array(
				'label'       => $label,
				'section'     => $section,
				'group'       => '',
				'type'        => 'select',
				'css_var'     => $css_var,
				'options'     => array(),
				'free_text'   => true,
				'suggestions' => $suggestions,
				'responsive'  => false,
				'placeholder' => $placeholder,
				'description' => '',
			),
			$args
		);
	}

	/**
	 * A bare number, for the one thing that is neither a length nor a
	 * colour: opacity.
	 */
	private static function amount( $label, $section, $css_var, $placeholder, $args = array() ) {
		return array_merge(
			array(
				'label'       => $label,
				'section'     => $section,
				'group'       => '',
				'type'        => 'number',
				'css_var'     => $css_var,
				'min'         => 0,
				'max'         => 1,
				'step'        => 0.01,
				'responsive'  => false,
				'placeholder' => $placeholder,
				'description' => '',
			),
			$args
		);
	}

	/**
	 * The font-weight vocabulary, shared by every text control.
	 */
	private static function weight_options() {
		return array(
			'300' => '300',
			'400' => __( '400 (Normal)', 'kdna-charts' ),
			'500' => '500',
			'600' => '600',
			'700' => __( '700 (Bold)', 'kdna-charts' ),
			'800' => '800',
			'900' => '900',
		);
	}

	private static function transform_options() {
		return array(
			'none'       => __( 'None', 'kdna-charts' ),
			'uppercase'  => __( 'Uppercase', 'kdna-charts' ),
			'lowercase'  => __( 'Lowercase', 'kdna-charts' ),
			'capitalize' => __( 'Capitalise', 'kdna-charts' ),
		);
	}

	private static function align_options() {
		return array(
			'left'   => __( 'Left', 'kdna-charts' ),
			'center' => __( 'Centre', 'kdna-charts' ),
			'right'  => __( 'Right', 'kdna-charts' ),
		);
	}

	private static function style_options() {
		return array(
			'normal' => __( 'Normal', 'kdna-charts' ),
			'italic' => __( 'Italic', 'kdna-charts' ),
		);
	}

	/**
	 * A weight control, which is the same three lines everywhere.
	 */
	private static function weight( $label, $section, $css_var, $placeholder, $group = '' ) {
		return self::choice( $label, $section, $css_var, self::weight_options(), $placeholder, array( 'group' => $group ) );
	}

	/**
	 * Letter spacing. Its own builder because the range is not a size
	 * range: tracking is small, often negative, and em is the unit that
	 * keeps it proportional to the type it is tracking.
	 */
	private static function tracking( $label, $section, $css_var, $placeholder, $group = '' ) {
		return self::size(
			$label,
			$section,
			$css_var,
			$placeholder,
			array(
				'group' => $group,
				'units' => array( 'em', 'px', 'rem' ),
				'min'   => -0.5,
				'max'   => 1,
				'step'  => 0.005,
			)
		);
	}

	/* ─── Section: Chart Frame ──────────────────────────────────────── */

	private static function frame_controls() {
		return array(
			'frame_background'    => self::colour(
				__( 'Background', 'kdna-charts' ),
				'frame',
				'--kdna-chart-background',
				'transparent'
			),

			'frame_max_width'     => self::size(
				__( 'Maximum Width', 'kdna-charts' ),
				'frame',
				'--kdna-chart-max-width',
				'100%',
				array(
					'units'       => array( '%', 'px', 'rem' ),
					'max'         => 2000,
					'step'        => 1,
					'description' => __( 'How wide the chart may grow. The alignment below only becomes visible once this is narrower than the column it sits in.', 'kdna-charts' ),
				)
			),

			'frame_align'         => self::choice(
				__( 'Alignment', 'kdna-charts' ),
				'frame',
				'--kdna-chart-margin-inline',
				self::align_options(),
				'left',
				array(
					/*
					 * One property carrying the whole inline margin, because
					 * centring is auto on both sides and a select can only
					 * write one property. A separate margin control writing
					 * the same property would mean whichever resolved last
					 * silently threw the other away, so the frame's vertical
					 * space has a property of its own below.
					 */
					'value_map'  => array(
						'left'   => '0 auto 0 0',
						'center' => '0 auto',
						'right'  => '0 0 0 auto',
					),
					'responsive' => true,
				)
			),

			'frame_margin_block'  => self::size(
				__( 'Space Above and Below', 'kdna-charts' ),
				'frame',
				'--kdna-chart-margin-block',
				'2rem',
				array(
					'units'       => array( 'rem', 'px', 'em' ),
					'max'         => 20,
					'step'        => 0.25,
					'description' => __( 'The same gap top and bottom. A chart is a block in a column of text, and an uneven gap above and below reads as a mistake.', 'kdna-charts' ),
				)
			),

			'frame_padding'       => self::box(
				__( 'Padding', 'kdna-charts' ),
				'frame',
				'--kdna-chart-padding',
				'0'
			),

			'frame_radius'        => self::box(
				__( 'Corner Radius', 'kdna-charts' ),
				'frame',
				'--kdna-chart-radius',
				'0',
				array( 'units' => array( 'px', 'rem', '%' ) )
			),

			'frame_border_style'  => self::choice(
				__( 'Border Style', 'kdna-charts' ),
				'frame',
				'--kdna-chart-border-style',
				array(
					'none'   => __( 'None', 'kdna-charts' ),
					'solid'  => __( 'Solid', 'kdna-charts' ),
					'dashed' => __( 'Dashed', 'kdna-charts' ),
					'dotted' => __( 'Dotted', 'kdna-charts' ),
					'double' => __( 'Double', 'kdna-charts' ),
				),
				'solid',
				array( 'group' => __( 'Border', 'kdna-charts' ) )
			),

			'frame_border_width'  => self::box(
				__( 'Border Width', 'kdna-charts' ),
				'frame',
				'--kdna-chart-border-width',
				'0',
				array(
					'group' => __( 'Border', 'kdna-charts' ),
					'units' => array( 'px', 'em' ),
				)
			),

			'frame_border_colour' => self::colour(
				__( 'Border Colour', 'kdna-charts' ),
				'frame',
				'--kdna-chart-border-colour',
				'transparent',
				__( 'Border', 'kdna-charts' )
			),

			'frame_font_family'   => self::open_text(
				__( 'Font Family', 'kdna-charts' ),
				'frame',
				'--kdna-chart-font-family',
				self::FONT_SUGGESTIONS,
				'inherit',
				array(
					'description' => __( 'Inherited by every piece of text in the chart, inside the plot and out. Custom Elementor fonts can be typed in by name.', 'kdna-charts' ),
				)
			),
		);
	}

	/* ─── Section: Caption ──────────────────────────────────────────── */

	private static function caption_controls() {
		return array(
			'caption_size'           => self::size(
				__( 'Size', 'kdna-charts' ),
				'caption',
				'--kdna-chart-caption-size',
				'1rem',
				array(
					'units' => array( 'rem', 'px', 'em' ),
					'max'   => 6,
					'step'  => 0.0625,
				)
			),

			'caption_weight'         => self::weight(
				__( 'Weight', 'kdna-charts' ),
				'caption',
				'--kdna-chart-caption-weight',
				'400'
			),

			'caption_colour'         => self::colour(
				__( 'Colour', 'kdna-charts' ),
				'caption',
				'--kdna-chart-caption-colour',
				'#5f5f5f'
			),

			'caption_align'          => self::choice(
				__( 'Alignment', 'kdna-charts' ),
				'caption',
				'--kdna-chart-caption-align',
				self::align_options(),
				'left',
				array( 'responsive' => true )
			),

			'caption_margin'         => self::box(
				__( 'Margin', 'kdna-charts' ),
				'caption',
				'--kdna-chart-caption-margin',
				'0.75rem 0 0',
				array( 'units' => array( 'rem', 'px', 'em' ) )
			),

			'caption_line_height'    => self::size(
				__( 'Line Height', 'kdna-charts' ),
				'caption',
				'--kdna-chart-caption-line-height',
				'1.5',
				array(
					// The empty unit is a unitless multiplier, which is the
					// right default for line height.
					'units' => array( '', 'px', 'em', 'rem' ),
					'max'   => 4,
					'step'  => 0.05,
				)
			),

			'caption_letter_spacing' => self::tracking(
				__( 'Letter Spacing', 'kdna-charts' ),
				'caption',
				'--kdna-chart-caption-letter-spacing',
				'normal'
			),

			'caption_transform'      => self::choice(
				__( 'Transform', 'kdna-charts' ),
				'caption',
				'--kdna-chart-caption-transform',
				self::transform_options(),
				'none'
			),

			'caption_style'          => self::choice(
				__( 'Style', 'kdna-charts' ),
				'caption',
				'--kdna-chart-caption-style',
				self::style_options(),
				'normal'
			),

			'caption_font_family'    => self::open_text(
				__( 'Font Family', 'kdna-charts' ),
				'caption',
				'--kdna-chart-caption-family',
				self::FONT_SUGGESTIONS,
				__( 'the chart font', 'kdna-charts' )
			),
		);
	}

	/* ─── Section: Plot Area ────────────────────────────────────────── */

	/**
	 * The rectangle the data is drawn inside.
	 *
	 * The plot's SIZE is not here, and cannot be: its padding is computed
	 * in PHP by the scale engine from the width of the longest tick label,
	 * because PHP cannot measure text and has to reserve space from an
	 * assumed size. A CSS control over that padding would move the frame
	 * without moving the geometry inside it, so the labels would collide
	 * with the plot they were measured against. What CSS can own is how
	 * the rectangle is painted, which is what these four controls are.
	 */
	private static function plot_controls() {
		return array(
			'plot_background'   => self::colour(
				__( 'Background', 'kdna-charts' ),
				'plot',
				'--kdna-chart-plot-background',
				'transparent',
				'',
				__( 'Fills the rectangle the data is drawn inside, behind the gridlines.', 'kdna-charts' )
			),

			'plot_border_colour' => self::colour(
				__( 'Border Colour', 'kdna-charts' ),
				'plot',
				'--kdna-chart-plot-border-colour',
				'transparent'
			),

			'plot_border_width'  => self::size(
				__( 'Border Width', 'kdna-charts' ),
				'plot',
				'--kdna-chart-plot-border-width',
				'0',
				array(
					'units' => array( 'px' ),
					'max'   => 20,
					'step'  => 0.5,
				)
			),

			'plot_radius'        => self::size(
				__( 'Corner Radius', 'kdna-charts' ),
				'plot',
				'--kdna-chart-plot-radius',
				'0',
				array(
					'units' => array( 'px' ),
					'max'   => 60,
					'step'  => 1,
				)
			),
		);
	}

	/* ─── Section: Gridlines ────────────────────────────────────────── */

	private static function gridline_controls() {
		return array(
			'gridline_colour'        => self::colour(
				__( 'Colour', 'kdna-charts' ),
				'gridlines',
				'--kdna-chart-gridline-colour',
				'#e1e1e1'
			),

			'gridline_colour_strong' => self::colour(
				__( 'Colour, Emphasised', 'kdna-charts' ),
				'gridlines',
				'--kdna-chart-gridline-colour-strong',
				'#aaaaaa',
				'',
				__( 'Used by a gridline the chart definition marks strong, such as a zero line.', 'kdna-charts' )
			),

			'gridline_colour_muted'  => self::colour(
				__( 'Colour, Muted', 'kdna-charts' ),
				'gridlines',
				'--kdna-chart-gridline-colour-muted',
				'#e4e9e7'
			),

			'gridline_width'         => self::size(
				__( 'Width', 'kdna-charts' ),
				'gridlines',
				'--kdna-chart-gridline-width',
				'1.5px',
				array(
					'units' => array( 'px' ),
					'max'   => 12,
					'step'  => 0.25,
				)
			),

			'gridline_width_strong'  => self::size(
				__( 'Width, Emphasised', 'kdna-charts' ),
				'gridlines',
				'--kdna-chart-gridline-width-strong',
				'2px',
				array(
					'units' => array( 'px' ),
					'max'   => 12,
					'step'  => 0.25,
				)
			),

			'gridline_dash_dashed'   => self::open_text(
				__( 'Dashed Pattern', 'kdna-charts' ),
				'gridlines',
				'--kdna-chart-gridline-dash-dashed',
				self::DASH_SUGGESTIONS,
				'10px 8px',
				array( 'group' => __( 'Dash Patterns', 'kdna-charts' ) )
			),

			'gridline_dash_dotted'   => self::open_text(
				__( 'Dotted Pattern', 'kdna-charts' ),
				'gridlines',
				'--kdna-chart-gridline-dash-dotted',
				self::DASH_SUGGESTIONS,
				'2px 8px',
				array( 'group' => __( 'Dash Patterns', 'kdna-charts' ) )
			),
		);
	}

	/* ─── Section: Axis Labels ──────────────────────────────────────── */

	private static function axis_controls() {
		$labels = __( 'Tick Labels', 'kdna-charts' );
		$titles = __( 'Axis Titles', 'kdna-charts' );

		return array(
			'axis_label_size'           => self::size(
				__( 'Size', 'kdna-charts' ),
				'axis',
				'--kdna-chart-axis-label-size',
				'18px',
				array(
					'group'       => $labels,
					'units'       => array( 'px' ),
					'max'         => 60,
					'step'        => 1,
					'description' => __( 'In user units on the 1000 unit canvas, not screen pixels. Past 24px the scale engine no longer reserves enough room, and a long label will start to clip.', 'kdna-charts' ),
				)
			),

			'axis_label_weight'         => self::weight(
				__( 'Weight', 'kdna-charts' ),
				'axis',
				'--kdna-chart-axis-label-weight',
				'400',
				$labels
			),

			'axis_label_weight_strong'  => self::weight(
				__( 'Weight, Emphasised', 'kdna-charts' ),
				'axis',
				'--kdna-chart-axis-label-weight-strong',
				'600',
				$labels
			),

			'axis_label_colour'         => self::colour(
				__( 'Colour', 'kdna-charts' ),
				'axis',
				'--kdna-chart-axis-label-colour',
				'#5f5f5f',
				$labels
			),

			'axis_label_colour_strong'  => self::colour(
				__( 'Colour, Emphasised', 'kdna-charts' ),
				'axis',
				'--kdna-chart-axis-label-colour-strong',
				'#303030',
				$labels
			),

			'axis_label_colour_muted'   => self::colour(
				__( 'Colour, Muted', 'kdna-charts' ),
				'axis',
				'--kdna-chart-axis-label-colour-muted',
				'#b6b6b6',
				$labels
			),

			'axis_label_letter_spacing' => self::tracking(
				__( 'Letter Spacing', 'kdna-charts' ),
				'axis',
				'--kdna-chart-axis-label-letter-spacing',
				'0',
				$labels
			),

			'axis_title_size'           => self::size(
				__( 'Size', 'kdna-charts' ),
				'axis',
				'--kdna-chart-axis-title-size',
				'18px',
				array(
					'group' => $titles,
					'units' => array( 'px' ),
					'max'   => 60,
					'step'  => 1,
				)
			),

			'axis_title_weight'         => self::weight(
				__( 'Weight', 'kdna-charts' ),
				'axis',
				'--kdna-chart-axis-title-weight',
				'600',
				$titles
			),

			'axis_title_colour'         => self::colour(
				__( 'Colour', 'kdna-charts' ),
				'axis',
				'--kdna-chart-axis-title-colour',
				'#7c7c7c',
				$titles
			),

			'axis_title_letter_spacing' => self::tracking(
				__( 'Letter Spacing', 'kdna-charts' ),
				'axis',
				'--kdna-chart-axis-title-letter-spacing',
				'0.06em',
				$titles
			),

			'axis_title_transform'      => self::choice(
				__( 'Transform', 'kdna-charts' ),
				'axis',
				'--kdna-chart-axis-title-transform',
				self::transform_options(),
				'uppercase',
				array( 'group' => $titles )
			),
		);
	}

	/* ─── Section: Series ───────────────────────────────────────────── */

	/**
	 * Everything that draws the data itself: lines, area fills, bars and
	 * pie segments, plus the figures printed on them.
	 *
	 * The largest section by some way, which is why it carries the most
	 * groups. A chart only ever uses one of these families at a time, so
	 * the groups are how a user of a bar chart gets past the line
	 * controls without reading them.
	 */
	private static function series_controls() {
		$lines    = __( 'Lines', 'kdna-charts' );
		$ramp     = __( 'Series Palette', 'kdna-charts' );
		$areas    = __( 'Area Fills', 'kdna-charts' );
		$bars     = __( 'Bars and Columns', 'kdna-charts' );
		$values   = __( 'Value Labels', 'kdna-charts' );
		$segments = __( 'Pie and Donut', 'kdna-charts' );

		$controls = array(
			'series_colour_strong' => self::colour(
				__( 'Line Colour, Emphasised', 'kdna-charts' ),
				'series',
				'--kdna-chart-series-strong',
				'#303030',
				$lines
			),

			'series_colour_normal' => self::colour(
				__( 'Line Colour', 'kdna-charts' ),
				'series',
				'--kdna-chart-series-normal',
				'#5f5f5f',
				$lines
			),

			'series_colour_muted'  => self::colour(
				__( 'Line Colour, Muted', 'kdna-charts' ),
				'series',
				'--kdna-chart-series-muted',
				'#aaaaaa',
				$lines
			),

			'line_width_strong'    => self::size(
				__( 'Line Width, Emphasised', 'kdna-charts' ),
				'series',
				'--kdna-chart-line-width-strong',
				'3.5px',
				array(
					'group' => $lines,
					'units' => array( 'px' ),
					'max'   => 20,
					'step'  => 0.25,
				)
			),

			'line_width_normal'    => self::size(
				__( 'Line Width', 'kdna-charts' ),
				'series',
				'--kdna-chart-line-width-normal',
				'2.5px',
				array(
					'group' => $lines,
					'units' => array( 'px' ),
					'max'   => 20,
					'step'  => 0.25,
				)
			),

			'line_width_muted'     => self::size(
				__( 'Line Width, Muted', 'kdna-charts' ),
				'series',
				'--kdna-chart-line-width-muted',
				'2px',
				array(
					'group' => $lines,
					'units' => array( 'px' ),
					'max'   => 20,
					'step'  => 0.25,
				)
			),

			'line_dash_dashed'     => self::open_text(
				__( 'Dashed Pattern', 'kdna-charts' ),
				'series',
				'--kdna-chart-dash-dashed',
				self::DASH_SUGGESTIONS,
				'14px 10px',
				array( 'group' => $lines )
			),

			'line_dash_dotted'     => self::open_text(
				__( 'Dotted Pattern', 'kdna-charts' ),
				'series',
				'--kdna-chart-dash-dotted',
				self::DASH_SUGGESTIONS,
				'0.1px 9px',
				array( 'group' => $lines )
			),
		);

		/*
		 * The six-tone ramp.
		 *
		 * A ramp rather than a set of hues, because an editorial chart is
		 * usually making one argument in one colour and its series are
		 * degrees of the same thing. A site that needs distinct hues sets
		 * these six and gets them, which is why they are six plain
		 * controls rather than one generated scale.
		 */
		$ramp_defaults = array( '#303030', '#5b5b5b', '#878787', '#b3b3b3', '#d4d4d4', '#e2e9e6' );
		foreach ( $ramp_defaults as $index => $default ) {
			$number = $index + 1;

			$controls[ 'series_colour_' . $number ] = self::colour(
				/* translators: %d: position in the series palette. */
				sprintf( __( 'Series %d', 'kdna-charts' ), $number ),
				'series',
				'--kdna-chart-series-colour-' . $number,
				$default,
				$ramp,
				1 === $number
					? __( 'Used when a chart has more than one series and the definition does not name an emphasis. Also colours the legend swatches.', 'kdna-charts' )
					: ''
			);
		}

		/* Area fills. */
		foreach ( array( 'strong', 'normal', 'muted' ) as $emphasis ) {
			$labels = array(
				'strong' => __( 'Emphasised', 'kdna-charts' ),
				'normal' => __( 'Normal', 'kdna-charts' ),
				'muted'  => __( 'Muted', 'kdna-charts' ),
			);
			$colours = array(
				'strong' => '#303030',
				'normal' => '#5f5f5f',
				'muted'  => '#aaaaaa',
			);
			$tops    = array(
				'strong' => '0.16',
				'normal' => '0.12',
				'muted'  => '0.07',
			);

			$controls[ 'area_colour_' . $emphasis ] = self::colour(
				/* translators: %s: emphasis level, e.g. Muted. */
				sprintf( __( '%s: Colour', 'kdna-charts' ), $labels[ $emphasis ] ),
				'series',
				'--kdna-chart-area-colour-' . $emphasis,
				$colours[ $emphasis ],
				$areas
			);

			$controls[ 'area_opacity_top_' . $emphasis ] = self::amount(
				/* translators: %s: emphasis level, e.g. Muted. */
				sprintf( __( '%s: Opacity at the Line', 'kdna-charts' ), $labels[ $emphasis ] ),
				'series',
				'--kdna-chart-area-opacity-top-' . $emphasis,
				$tops[ $emphasis ],
				array(
					'group'       => $areas,
					'description' => 'strong' === $emphasis
						? __( 'The fill is a gradient from the line down to the axis. These two numbers are its ends.', 'kdna-charts' )
						: '',
				)
			);

			$controls[ 'area_opacity_bottom_' . $emphasis ] = self::amount(
				/* translators: %s: emphasis level, e.g. Muted. */
				sprintf( __( '%s: Opacity at the Axis', 'kdna-charts' ), $labels[ $emphasis ] ),
				'series',
				'--kdna-chart-area-opacity-bottom-' . $emphasis,
				'0',
				array( 'group' => $areas )
			);
		}

		/* Bars and columns. */
		$controls['bar_colour_strong'] = self::colour(
			__( 'Colour, Emphasised', 'kdna-charts' ),
			'series',
			'--kdna-chart-bar-colour-strong',
			'#303030',
			$bars
		);

		$controls['bar_colour_normal'] = self::colour(
			__( 'Colour', 'kdna-charts' ),
			'series',
			'--kdna-chart-bar-colour-normal',
			'#5f5f5f',
			$bars
		);

		$controls['bar_colour_muted'] = self::colour(
			__( 'Colour, Muted', 'kdna-charts' ),
			'series',
			'--kdna-chart-bar-colour-muted',
			'#c5c5c5',
			$bars
		);

		$controls['bar_opacity'] = self::amount(
			__( 'Opacity', 'kdna-charts' ),
			'series',
			'--kdna-chart-bar-opacity',
			'1',
			array( 'group' => $bars )
		);

		$controls['bar_stroke_colour'] = self::colour(
			__( 'Outline Colour', 'kdna-charts' ),
			'series',
			'--kdna-chart-bar-stroke-colour',
			'transparent',
			$bars
		);

		$controls['bar_stroke_width'] = self::size(
			__( 'Outline Width', 'kdna-charts' ),
			'series',
			'--kdna-chart-bar-stroke-width',
			'0',
			array(
				'group' => $bars,
				'units' => array( 'px' ),
				'max'   => 20,
				'step'  => 0.25,
			)
		);

		/* Value labels, on bars and columns. */
		$controls['value_label_size'] = self::size(
			__( 'Size', 'kdna-charts' ),
			'series',
			'--kdna-chart-value-label-size',
			'20px',
			array(
				'group' => $values,
				'units' => array( 'px' ),
				'max'   => 80,
				'step'  => 1,
			)
		);

		$controls['value_label_weight'] = self::weight(
			__( 'Weight', 'kdna-charts' ),
			'series',
			'--kdna-chart-value-label-weight',
			'600',
			$values
		);

		$controls['value_label_colour'] = self::colour(
			__( 'Colour', 'kdna-charts' ),
			'series',
			'--kdna-chart-value-label-colour',
			'#303030',
			$values
		);

		$controls['value_label_colour_inside'] = self::colour(
			__( 'Colour, Inside the Bar', 'kdna-charts' ),
			'series',
			'--kdna-chart-value-label-colour-inside',
			'#fff',
			$values,
			__( 'A figure printed inside a bar sits on the bar colour, so it needs its own contrast.', 'kdna-charts' )
		);

		/* Pie and donut. */
		$controls['segment_stroke_colour'] = self::colour(
			__( 'Segment Gap Colour', 'kdna-charts' ),
			'series',
			'--kdna-chart-segment-stroke-colour',
			'transparent',
			$segments,
			__( 'Segments are separated by a stroke rather than a gap, so this is normally the page background.', 'kdna-charts' )
		);

		$controls['segment_stroke_width'] = self::size(
			__( 'Segment Gap Width', 'kdna-charts' ),
			'series',
			'--kdna-chart-segment-stroke-width',
			'0',
			array(
				'group' => $segments,
				'units' => array( 'px' ),
				'max'   => 20,
				'step'  => 0.5,
			)
		);

		$controls['segment_label_size'] = self::size(
			__( 'Label Size', 'kdna-charts' ),
			'series',
			'--kdna-chart-segment-label-size',
			'20px',
			array(
				'group' => $segments,
				'units' => array( 'px' ),
				'max'   => 80,
				'step'  => 1,
			)
		);

		$controls['segment_label_weight'] = self::weight(
			__( 'Label Weight', 'kdna-charts' ),
			'series',
			'--kdna-chart-segment-label-weight',
			'600',
			$segments
		);

		$controls['segment_label_colour'] = self::colour(
			__( 'Label Colour', 'kdna-charts' ),
			'series',
			'--kdna-chart-segment-label-colour',
			'#303030',
			$segments
		);

		$controls['segment_label_colour_inside'] = self::colour(
			__( 'Label Colour, Inside the Segment', 'kdna-charts' ),
			'series',
			'--kdna-chart-segment-label-colour-inside',
			'#fff',
			$segments
		);

		$controls['segment_centre_size'] = self::size(
			__( 'Donut Centre Size', 'kdna-charts' ),
			'series',
			'--kdna-chart-segment-centre-size',
			'64px',
			array(
				'group' => $segments,
				'units' => array( 'px' ),
				'max'   => 200,
				'step'  => 2,
			)
		);

		$controls['segment_centre_weight'] = self::weight(
			__( 'Donut Centre Weight', 'kdna-charts' ),
			'series',
			'--kdna-chart-segment-centre-weight',
			'700',
			$segments
		);

		$controls['segment_centre_colour'] = self::colour(
			__( 'Donut Centre Colour', 'kdna-charts' ),
			'series',
			'--kdna-chart-segment-centre-colour',
			'#303030',
			$segments
		);

		$controls['segment_leader_colour'] = self::colour(
			__( 'Leader Colour', 'kdna-charts' ),
			'series',
			'--kdna-chart-segment-leader-colour',
			'#aaaaaa',
			$segments
		);

		$controls['segment_leader_width'] = self::size(
			__( 'Leader Width', 'kdna-charts' ),
			'series',
			'--kdna-chart-segment-leader-width',
			'1.5px',
			array(
				'group' => $segments,
				'units' => array( 'px' ),
				'max'   => 12,
				'step'  => 0.25,
			)
		);

		return $controls;
	}

	/* ─── Section: Data Points ──────────────────────────────────────── */

	private static function point_controls() {
		$labels = __( 'Point Labels', 'kdna-charts' );

		return array(
			'point_radius'       => self::size(
				__( 'Radius', 'kdna-charts' ),
				'points',
				'--kdna-chart-point-radius',
				'9px',
				array(
					'units' => array( 'px' ),
					'max'   => 60,
					'step'  => 0.5,
				)
			),

			'point_colour'       => self::colour(
				__( 'Colour', 'kdna-charts' ),
				'points',
				'--kdna-chart-point-colour',
				'#303030'
			),

			'point_fill_hollow'  => self::colour(
				__( 'Hollow Fill', 'kdna-charts' ),
				'points',
				'--kdna-chart-point-fill-hollow',
				'#fff',
				'',
				__( 'What sits inside a hollow point. Normally the page background, so the line appears to pass behind it.', 'kdna-charts' )
			),

			'point_stroke_width' => self::size(
				__( 'Ring Width', 'kdna-charts' ),
				'points',
				'--kdna-chart-point-stroke-width',
				'3.5px',
				array(
					'units' => array( 'px' ),
					'max'   => 20,
					'step'  => 0.25,
				)
			),

			'point_label_size'   => self::size(
				__( 'Size', 'kdna-charts' ),
				'points',
				'--kdna-chart-point-label-size',
				'20px',
				array(
					'group' => $labels,
					'units' => array( 'px' ),
					'max'   => 80,
					'step'  => 1,
				)
			),

			'point_label_weight' => self::weight(
				__( 'Weight', 'kdna-charts' ),
				'points',
				'--kdna-chart-point-label-weight',
				'500',
				$labels
			),

			'point_label_colour' => self::colour(
				__( 'Colour', 'kdna-charts' ),
				'points',
				'--kdna-chart-point-label-colour',
				'#5f5f5f',
				$labels
			),
		);
	}

	/* ─── Section: Markers ──────────────────────────────────────────── */

	private static function marker_controls() {
		$lines  = __( 'Marker Lines', 'kdna-charts' );
		$labels = __( 'Marker Headings', 'kdna-charts' );

		return array(
			'marker_colour'                => self::colour(
				__( 'Colour', 'kdna-charts' ),
				'markers',
				'--kdna-chart-marker-colour',
				'#7c7c7c',
				$lines
			),

			'marker_width'                 => self::size(
				__( 'Width', 'kdna-charts' ),
				'markers',
				'--kdna-chart-marker-width',
				'2px',
				array(
					'group' => $lines,
					'units' => array( 'px' ),
					'max'   => 20,
					'step'  => 0.25,
				)
			),

			'marker_dash_dashed'           => self::open_text(
				__( 'Dashed Pattern', 'kdna-charts' ),
				'markers',
				'--kdna-chart-marker-dash-dashed',
				self::DASH_SUGGESTIONS,
				'10px 8px',
				array( 'group' => $lines )
			),

			'marker_dash_dotted'           => self::open_text(
				__( 'Dotted Pattern', 'kdna-charts' ),
				'markers',
				'--kdna-chart-marker-dash-dotted',
				self::DASH_SUGGESTIONS,
				'2px 8px',
				array( 'group' => $lines )
			),

			'marker_label_size'            => self::size(
				__( 'Size', 'kdna-charts' ),
				'markers',
				'--kdna-chart-marker-label-size',
				'20px',
				array(
					'group'       => $labels,
					'units'       => array( 'px' ),
					'max'         => 80,
					'step'        => 1,
					'description' => __( 'The annotation layer reserves room for a marker heading from an assumed size of 24px. A larger heading can be nudged by the collision avoidance rather than fitting where it was drawn.', 'kdna-charts' ),
				)
			),

			'marker_label_weight'          => self::weight(
				__( 'Weight', 'kdna-charts' ),
				'markers',
				'--kdna-chart-marker-label-weight',
				'600',
				$labels
			),

			'marker_label_colour'          => self::colour(
				__( 'Colour', 'kdna-charts' ),
				'markers',
				'--kdna-chart-marker-label-colour',
				'#303030',
				$labels
			),

			'marker_label_letter_spacing'  => self::tracking(
				__( 'Letter Spacing', 'kdna-charts' ),
				'markers',
				'--kdna-chart-marker-label-letter-spacing',
				'0.04em',
				$labels
			),

			'marker_label_transform'       => self::choice(
				__( 'Transform', 'kdna-charts' ),
				'markers',
				'--kdna-chart-marker-label-transform',
				self::transform_options(),
				'none',
				array( 'group' => $labels )
			),
		);
	}

	/* ─── Section: Callouts ─────────────────────────────────────────── */

	private static function callout_controls() {
		$value    = __( 'Figure', 'kdna-charts' );
		$caption  = __( 'Caption', 'kdna-charts' );
		$leaders  = __( 'Leaders and Brackets', 'kdna-charts' );

		return array(
			'callout_value_size_large'     => self::size(
				__( 'Size, Large', 'kdna-charts' ),
				'callouts',
				'--kdna-chart-callout-value-size-large',
				'56px',
				array(
					'group' => $value,
					'units' => array( 'px' ),
					'max'   => 200,
					'step'  => 2,
				)
			),

			'callout_value_size_small'     => self::size(
				__( 'Size, Small', 'kdna-charts' ),
				'callouts',
				'--kdna-chart-callout-value-size-small',
				'34px',
				array(
					'group' => $value,
					'units' => array( 'px' ),
					'max'   => 200,
					'step'  => 2,
				)
			),

			'callout_value_weight'         => self::weight(
				__( 'Weight', 'kdna-charts' ),
				'callouts',
				'--kdna-chart-callout-value-weight',
				'700',
				$value
			),

			'callout_value_colour'         => self::colour(
				__( 'Colour', 'kdna-charts' ),
				'callouts',
				'--kdna-chart-callout-value-colour',
				'#303030',
				$value
			),

			'callout_value_letter_spacing' => self::tracking(
				__( 'Letter Spacing', 'kdna-charts' ),
				'callouts',
				'--kdna-chart-callout-value-letter-spacing',
				'-0.02em',
				$value
			),

			'callout_caption_size'         => self::size(
				__( 'Size', 'kdna-charts' ),
				'callouts',
				'--kdna-chart-callout-caption-size',
				'20px',
				array(
					'group' => $caption,
					'units' => array( 'px' ),
					'max'   => 80,
					'step'  => 1,
				)
			),

			'callout_caption_weight'       => self::weight(
				__( 'Weight', 'kdna-charts' ),
				'callouts',
				'--kdna-chart-callout-caption-weight',
				'400',
				$caption
			),

			'callout_caption_colour'       => self::colour(
				__( 'Colour', 'kdna-charts' ),
				'callouts',
				'--kdna-chart-callout-caption-colour',
				'#7c7c7c',
				$caption
			),

			'leader_colour'                => self::colour(
				__( 'Leader Colour', 'kdna-charts' ),
				'callouts',
				'--kdna-chart-leader-colour',
				'#aaaaaa',
				$leaders
			),

			'leader_width'                 => self::size(
				__( 'Leader Width', 'kdna-charts' ),
				'callouts',
				'--kdna-chart-leader-width',
				'1.75px',
				array(
					'group' => $leaders,
					'units' => array( 'px' ),
					'max'   => 12,
					'step'  => 0.25,
				)
			),

			'leader_dash'                  => self::open_text(
				__( 'Leader Dash Pattern', 'kdna-charts' ),
				'callouts',
				'--kdna-chart-leader-dash',
				self::DASH_SUGGESTIONS,
				'none',
				array( 'group' => $leaders )
			),

			'bracket_colour'               => self::colour(
				__( 'Bracket Colour', 'kdna-charts' ),
				'callouts',
				'--kdna-chart-bracket-colour',
				'#aaaaaa',
				$leaders,
				__( 'The bracket a callout draws when it is anchored to a span rather than a single point.', 'kdna-charts' )
			),

			'bracket_width'                => self::size(
				__( 'Bracket Width', 'kdna-charts' ),
				'callouts',
				'--kdna-chart-bracket-width',
				'1.75px',
				array(
					'group' => $leaders,
					'units' => array( 'px' ),
					'max'   => 12,
					'step'  => 0.25,
				)
			),
		);
	}

	/* ─── Section: Notes ────────────────────────────────────────────── */

	private static function note_controls() {
		return array(
			'note_size'   => self::size(
				__( 'Size', 'kdna-charts' ),
				'notes',
				'--kdna-chart-note-size',
				'20px',
				array(
					'units' => array( 'px' ),
					'max'   => 80,
					'step'  => 1,
				)
			),

			'note_weight' => self::weight(
				__( 'Weight', 'kdna-charts' ),
				'notes',
				'--kdna-chart-note-weight',
				'400'
			),

			'note_colour' => self::colour(
				__( 'Colour', 'kdna-charts' ),
				'notes',
				'--kdna-chart-note-colour',
				'#999999'
			),

			'note_style'  => self::choice(
				__( 'Style', 'kdna-charts' ),
				'notes',
				'--kdna-chart-note-style',
				self::style_options(),
				'italic'
			),
		);
	}

	/* ─── Section: Legend ───────────────────────────────────────────── */

	/**
	 * Three controls, and deliberately no swatch size.
	 *
	 * A legend swatch is a rect the renderer positions with x, y, width
	 * and height attributes, and the labels are laid out in PHP from
	 * those numbers. A CSS width would move the swatch without moving the
	 * label beside it, so the size of a swatch belongs to the renderer.
	 * Its colour does not, and comes from the series palette above.
	 */
	private static function legend_controls() {
		return array(
			'legend_label_size'   => self::size(
				__( 'Label Size', 'kdna-charts' ),
				'legend',
				'--kdna-chart-legend-label-size',
				'20px',
				array(
					'units' => array( 'px' ),
					'max'   => 80,
					'step'  => 1,
				)
			),

			'legend_label_weight' => self::weight(
				__( 'Label Weight', 'kdna-charts' ),
				'legend',
				'--kdna-chart-legend-label-weight',
				'400'
			),

			'legend_label_colour' => self::colour(
				__( 'Label Colour', 'kdna-charts' ),
				'legend',
				'--kdna-chart-legend-label-colour',
				'#5f5f5f'
			),
		);
	}

	/* ─── Section: Source Line ──────────────────────────────────────── */

	private static function source_controls() {
		return array(
			'source_size'           => self::size(
				__( 'Size', 'kdna-charts' ),
				'source',
				'--kdna-chart-source-size',
				'0.8125rem',
				array(
					'units' => array( 'rem', 'px', 'em' ),
					'max'   => 4,
					'step'  => 0.0625,
				)
			),

			'source_weight'         => self::weight(
				__( 'Weight', 'kdna-charts' ),
				'source',
				'--kdna-chart-source-weight',
				'400'
			),

			'source_colour'         => self::colour(
				__( 'Colour', 'kdna-charts' ),
				'source',
				'--kdna-chart-source-colour',
				'#999999'
			),

			'source_align'          => self::choice(
				__( 'Alignment', 'kdna-charts' ),
				'source',
				'--kdna-chart-source-align',
				self::align_options(),
				'left',
				array( 'responsive' => true )
			),

			'source_margin'         => self::box(
				__( 'Margin', 'kdna-charts' ),
				'source',
				'--kdna-chart-source-margin',
				'0.5rem 0 0',
				array( 'units' => array( 'rem', 'px', 'em' ) )
			),

			'source_line_height'    => self::size(
				__( 'Line Height', 'kdna-charts' ),
				'source',
				'--kdna-chart-source-line-height',
				'1.5',
				array(
					'units' => array( '', 'px', 'em', 'rem' ),
					'max'   => 4,
					'step'  => 0.05,
				)
			),

			'source_letter_spacing' => self::tracking(
				__( 'Letter Spacing', 'kdna-charts' ),
				'source',
				'--kdna-chart-source-letter-spacing',
				'normal'
			),

			'source_transform'      => self::choice(
				__( 'Transform', 'kdna-charts' ),
				'source',
				'--kdna-chart-source-transform',
				self::transform_options(),
				'none'
			),

			'source_style'          => self::choice(
				__( 'Style', 'kdna-charts' ),
				'source',
				'--kdna-chart-source-style',
				self::style_options(),
				'normal'
			),

			'source_font_family'    => self::open_text(
				__( 'Font Family', 'kdna-charts' ),
				'source',
				'--kdna-chart-source-family',
				self::FONT_SUGGESTIONS,
				__( 'the chart font', 'kdna-charts' )
			),
		);
	}

	/* ─── Section: Stat Blocks ──────────────────────────────────────── */

	private static function stat_controls() {
		$number = __( 'Figure', 'kdna-charts' );
		$suffix = __( 'Suffix', 'kdna-charts' );
		$label  = __( 'Label', 'kdna-charts' );
		$rule   = __( 'Divider', 'kdna-charts' );

		return array(
			'stat_gap'            => self::size(
				__( 'Gap Between Blocks', 'kdna-charts' ),
				'stats',
				'--kdna-chart-stat-gap',
				'2rem',
				array(
					'units' => array( 'rem', 'px', 'em' ),
					'max'   => 12,
					'step'  => 0.25,
				)
			),

			'stat_padding'        => self::box(
				__( 'Padding Inside a Block', 'kdna-charts' ),
				'stats',
				'--kdna-chart-stat-padding',
				'0',
				array( 'units' => array( 'rem', 'px', 'em' ) )
			),

			'stat_number_size'    => self::size(
				__( 'Size', 'kdna-charts' ),
				'stats',
				'--kdna-chart-stat-number-size',
				'clamp( 2.4rem, 6vw, 3.6rem )',
				array(
					'group'       => $number,
					'units'       => array( 'rem', 'px', 'em' ),
					'max'         => 20,
					'step'        => 0.1,
					'description' => __( 'The default is a clamp that grows with the viewport. Setting a size here replaces it with a fixed one, so set the tablet and mobile sizes too.', 'kdna-charts' ),
				)
			),

			'stat_number_weight'  => self::weight(
				__( 'Weight', 'kdna-charts' ),
				'stats',
				'--kdna-chart-stat-number-weight',
				'700',
				$number
			),

			'stat_number_colour'  => self::colour(
				__( 'Colour', 'kdna-charts' ),
				'stats',
				'--kdna-chart-stat-number-colour',
				'#303030',
				$number
			),

			'stat_number_leading' => self::size(
				__( 'Line Height', 'kdna-charts' ),
				'stats',
				'--kdna-chart-stat-number-leading',
				'1',
				array(
					'group' => $number,
					'units' => array( '', 'px', 'em', 'rem' ),
					'max'   => 4,
					'step'  => 0.05,
				)
			),

			'stat_number_tracking' => self::tracking(
				__( 'Letter Spacing', 'kdna-charts' ),
				'stats',
				'--kdna-chart-stat-number-tracking',
				'-0.03em',
				$number
			),

			'stat_suffix_size'    => self::size(
				__( 'Size', 'kdna-charts' ),
				'stats',
				'--kdna-chart-stat-suffix-size',
				'0.5em',
				array(
					'group'       => $suffix,
					'units'       => array( 'em', 'rem', 'px' ),
					'max'         => 8,
					'step'        => 0.05,
					'description' => __( 'In em, so it stays proportional to the figure it follows.', 'kdna-charts' ),
				)
			),

			'stat_suffix_weight'  => self::weight(
				__( 'Weight', 'kdna-charts' ),
				'stats',
				'--kdna-chart-stat-suffix-weight',
				'600',
				$suffix
			),

			'stat_suffix_colour'  => self::colour(
				__( 'Colour', 'kdna-charts' ),
				'stats',
				'--kdna-chart-stat-suffix-colour',
				'#7c7c7c',
				$suffix
			),

			'stat_label_size'     => self::size(
				__( 'Size', 'kdna-charts' ),
				'stats',
				'--kdna-chart-stat-label-size',
				'0.9375rem',
				array(
					'group' => $label,
					'units' => array( 'rem', 'px', 'em' ),
					'max'   => 6,
					'step'  => 0.0625,
				)
			),

			'stat_label_weight'   => self::weight(
				__( 'Weight', 'kdna-charts' ),
				'stats',
				'--kdna-chart-stat-label-weight',
				'500',
				$label
			),

			'stat_label_colour'   => self::colour(
				__( 'Colour', 'kdna-charts' ),
				'stats',
				'--kdna-chart-stat-label-colour',
				'#7c7c7c',
				$label
			),

			'stat_label_tracking' => self::tracking(
				__( 'Letter Spacing', 'kdna-charts' ),
				'stats',
				'--kdna-chart-stat-label-tracking',
				'0.02em',
				$label
			),

			'stat_rule_width'     => self::size(
				__( 'Width', 'kdna-charts' ),
				'stats',
				'--kdna-chart-stat-rule-width',
				'0',
				array(
					'group'       => $rule,
					'units'       => array( 'px', 'em' ),
					'max'         => 12,
					'step'        => 0.5,
					'description' => __( 'A rule between blocks. Zero, the default, is no rule at all.', 'kdna-charts' ),
				)
			),

			'stat_rule_colour'    => self::colour(
				__( 'Colour', 'kdna-charts' ),
				'stats',
				'--kdna-chart-stat-rule-colour',
				'#e1e1e1',
				$rule
			),
		);
	}
}
