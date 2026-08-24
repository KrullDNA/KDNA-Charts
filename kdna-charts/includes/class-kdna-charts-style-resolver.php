<?php
/**
 * Style resolver: the global option and a chart's own overrides,
 * flattened into CSS custom properties for the wrapper's inline style
 * attribute.
 *
 * ── Resolution order ──────────────────────────────────────────────────
 *
 *   1. the global option kdna_charts_style_defaults
 *   2. the per chart post meta _kdna_chart_style_overrides
 *   3. anything a caller hands in, which is how the Elementor widget
 *      will add its layer at Stage 11
 *
 * Later wins, and the merge happens at the LEAF, not at the control. A
 * global that sets a desktop axis label size and a chart override that
 * sets only the mobile one produce a result carrying both: replacing
 * whole controls would silently drop the global's other breakpoints.
 *
 * There is no schema layer under any of this, and that is deliberate.
 * Every default lives once, in assets/css/kdna-charts.css, as the --auto
 * layer; the schema carries none. So an unset control emits no property
 * at all and the stylesheet decides, which is the same thing "inherit"
 * means at every other level of this system.
 *
 * ── Absent, not empty ─────────────────────────────────────────────────
 *
 * A value in its inherit state is skipped entirely rather than written
 * as an empty value, so the layer beneath shows through. This matters
 * most for the responsive properties. A responsive control emits up to
 * three: the base name for desktop, then the same name suffixed -tablet
 * and -mobile. A breakpoint with no value emits NOTHING, because the
 * stylesheet's fallback chain only falls through on a property that was
 * never defined:
 *
 *   --_x: var( --x-tablet, var( --x, var( --x--auto ) ) );
 *
 * Writing '--x-tablet: ;' there would not be absent. The chain would
 * stop at it and resolve to empty, and the rule reading --_x would be
 * invalid at computed-value time, which paints as unset and inherited, not
 * as the desktop value. One empty string, and a chart loses its axis
 * labels on a tablet.
 *
 * ── Why inline, and not a style block in wp_head ──────────────────────
 *
 * A chart can be rendered from a shortcode inside a JetEngine repeater
 * field, or by an Elementor widget in a template, and neither is visible
 * to has_shortcode(), which only reads post_content. Writing the
 * resolved properties onto the wrapper at render time works wherever the
 * chart lands, cannot arrive after the markup, and needs no page
 * scanning.
 *
 * @package KDNA_Charts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KDNA_Charts_Style_Resolver {

	/** Global defaults, one option holding the whole set. */
	const OPTION_KEY = 'kdna_charts_style_defaults';

	/**
	 * Longest custom property value accepted.
	 *
	 * Values are authored through the settings page, so this is a
	 * backstop against a hand-edited option rather than an expected
	 * limit. The longest legitimate value is a font stack.
	 */
	const MAX_VALUE_LENGTH = 200;

	/**
	 * Generation counter, part of every transient key.
	 *
	 * Bumping this invalidates every cached chart at once. The
	 * alternative, deleting the transients one by one when the global
	 * option changes, means either knowing every chart id or running a
	 * LIKE query across the options table, and on a site with object
	 * caching there are no rows to sweep at all. A counter in the key
	 * sidesteps both: the old entries are never asked for again and
	 * expire on their own.
	 */
	const GENERATION_OPTION = 'kdna_charts_style_generation';

	/** Transient key prefix. */
	const CACHE_PREFIX = 'kdna_chart_style_';

	/**
	 * How long a cached style attribute lives: a week, written as a
	 * literal rather than as WEEK_IN_SECONDS, because a class constant
	 * defined from another constant is resolved when the class is loaded
	 * and would tie this file to WordPress having booted first.
	 *
	 * The TTL is a backstop, not the invalidation mechanism. Saving
	 * moves the generation on immediately.
	 */
	const CACHE_TTL = 604800;

	/** Per-request memo, chart id => properties. */
	private static $memo = array();

	/** Per-request memo of the rendered attribute, chart id => string. */
	private static $attribute_memo = array();

	/**
	 * The per chart meta key.
	 *
	 * Read through KDNA_Charts_CPT rather than declared here, because
	 * the CPT owns the meta and stores it JSON encoded. That encoding is
	 * the reason this class never calls get_post_meta() directly: the
	 * raw value is a string, and treating it as an array would quietly
	 * resolve every chart to the global defaults.
	 */
	public static function meta_key() {
		return KDNA_Charts_CPT::META_STYLE;
	}

	/**
	 * Resolved CSS custom properties for a chart.
	 *
	 * @param int   $chart_id Chart post id, or 0 for the global result
	 *                        with no per chart overrides applied.
	 * @param array $extra    A further layer applied last. Not memoised,
	 *                        since it is per call rather than per chart.
	 * @return array Custom property name => CSS value.
	 */
	public static function resolve( $chart_id = 0, array $extra = array() ) {
		$chart_id = (int) $chart_id;

		if ( empty( $extra ) && isset( self::$memo[ $chart_id ] ) ) {
			return self::$memo[ $chart_id ];
		}

		$properties = self::flatten( self::resolve_values( $chart_id, $extra ) );

		/**
		 * Filter the resolved custom properties before they are rendered.
		 *
		 * @param array $properties Property name => value.
		 * @param int   $chart_id   Chart post id, 0 for the global set.
		 */
		$properties = apply_filters( 'kdna_charts_style_properties', $properties, $chart_id );

		if ( empty( $extra ) ) {
			self::$memo[ $chart_id ] = $properties;
		}

		return $properties;
	}

	/**
	 * The merged values behind resolve(), in storage shape rather than
	 * as CSS.
	 *
	 * Public because the admin needs it: a per chart control has to show
	 * what it is inheriting, and what it is inheriting is this, resolved
	 * one layer down.
	 *
	 * @param int   $chart_id Chart post id, 0 to stop after the global layer.
	 * @param array $extra    A further layer applied last.
	 * @return array Control key => value.
	 */
	public static function resolve_values( $chart_id = 0, array $extra = array() ) {
		$controls = KDNA_Charts_Style_Schema::get();
		$values   = array();

		foreach ( self::layers( (int) $chart_id, $extra ) as $layer ) {
			if ( ! is_array( $layer ) ) {
				continue;
			}
			foreach ( $layer as $key => $incoming ) {
				// A key with no schema entry is discarded. Schema entries
				// get removed between versions; stored values for them do
				// not.
				if ( ! isset( $controls[ $key ] ) ) {
					continue;
				}
				$current = isset( $values[ $key ] ) ? $values[ $key ] : null;
				$merged  = self::merge_value( $current, $incoming, $controls[ $key ] );
				if ( null === $merged ) {
					continue;
				}
				$values[ $key ] = $merged;
			}
		}

		return $values;
	}

	/**
	 * Render properties as the value of an inline style attribute.
	 *
	 * Returns the attribute VALUE, without the style="" wrapper and
	 * without escaping: the caller passes it through esc_attr(). Accepts
	 * a chart id as a convenience, so a caller that only has an id does
	 * not have to resolve first.
	 *
	 * @param array|int $properties Resolved properties, or a chart id.
	 * @return string e.g. '--kdna-chart-background: #fff; --kdna-chart-caption-size: 1.25rem;'
	 */
	public static function to_style_attribute( $properties = array() ) {
		if ( ! is_array( $properties ) ) {
			$properties = self::resolve( (int) $properties );
		}

		$declarations = array();

		foreach ( $properties as $name => $value ) {
			$name  = self::sanitize_property_name( $name );
			$value = self::sanitize_css_value( $value );
			if ( '' === $name || '' === $value ) {
				continue;
			}
			$declarations[] = $name . ': ' . $value . ';';
		}

		return implode( ' ', $declarations );
	}

	/**
	 * The largest size a control is set to at any breakpoint.
	 *
	 * ── Why the renderer needs this ───────────────────────────────────
	 *
	 * The scale engine reserves padding for a tick label from an assumed
	 * size, because PHP cannot measure text and the geometry has to be
	 * settled before the browser sees it. That assumption held while the
	 * only sizes were the stylesheet's own. It stops holding the moment
	 * somebody sets a larger one here, and what they get is a label
	 * running into the axis title with nothing to say why.
	 *
	 * So the renderer asks for this and hands it to the scale. The
	 * LARGEST, not the resolved one, and that is the whole point: which
	 * breakpoint applies is decided by CSS in the browser, at a width
	 * this code will never know. The geometry is settled once and has to
	 * hold at every width, so it reserves room for the biggest label the
	 * chart can ever draw.
	 *
	 * Only px is counted. Both controls this is used for offer px alone,
	 * and a size in em would be a size relative to a font size this
	 * class has no way to resolve, so it is ignored rather than guessed
	 * at, and the floor in the scale takes over.
	 *
	 * @param int    $chart_id Chart post id, 0 for the global set.
	 * @param string $key      Control key.
	 * @return float The largest size in px, or 0 when nothing is set.
	 */
	public static function largest_size( $chart_id, $key ) {
		$control = KDNA_Charts_Style_Schema::get_control( $key );
		if ( ! $control || 'slider' !== $control['type'] ) {
			return 0.0;
		}

		$values = self::resolve_values( (int) $chart_id );
		if ( ! isset( $values[ $key ] ) ) {
			return 0.0;
		}

		$largest = 0.0;

		foreach ( KDNA_Charts_Style_Schema::as_device_map( $values[ $key ] ) as $value ) {
			if ( ! is_array( $value ) || ! isset( $value['size'] ) || ! is_numeric( $value['size'] ) ) {
				continue;
			}
			$unit = isset( $value['unit'] ) ? (string) $value['unit'] : 'px';
			if ( 'px' !== $unit ) {
				continue;
			}
			$largest = max( $largest, (float) $value['size'] );
		}

		return $largest;
	}

	/* ─── Caching ───────────────────────────────────────────────────── */

	/**
	 * The style attribute for a chart, cached.
	 *
	 * This is the render path's entry point, and the reason the cache
	 * exists: resolve() walks a hundred and fifty control definitions,
	 * merges the layers leaf by leaf and formats every value, and it
	 * does that once per chart on the page.
	 *
	 * The string is cached rather than the array because the string is
	 * what the render needs. Caching the array would leave
	 * to_style_attribute()'s per-property validation to run every time.
	 *
	 * @param int $chart_id Chart post id, 0 for the global set.
	 * @return string Style attribute value, unescaped.
	 */
	public static function style_attribute_for( $chart_id = 0 ) {
		$chart_id = (int) $chart_id;

		if ( isset( self::$attribute_memo[ $chart_id ] ) ) {
			return self::$attribute_memo[ $chart_id ];
		}

		$key    = self::cache_key( $chart_id );
		$cached = self::caching_enabled() ? get_transient( $key ) : false;

		/*
		 * A cached empty string is legitimate, since every control on
		 * inherit produces one and that is the state a fresh install is
		 * in, so
		 * the miss is tested by type, not by emptiness.
		 */
		if ( is_string( $cached ) ) {
			self::$attribute_memo[ $chart_id ] = $cached;
			return $cached;
		}

		$attribute = self::to_style_attribute( self::resolve( $chart_id ) );

		if ( self::caching_enabled() ) {
			set_transient( $key, $attribute, self::CACHE_TTL );
		}

		self::$attribute_memo[ $chart_id ] = $attribute;

		return $attribute;
	}

	/**
	 * Whether resolved styles are cached at all.
	 *
	 * On by default. The filter is for debugging a site whose styles
	 * look stale, which is the one situation where turning a cache off
	 * without editing code is worth having.
	 */
	private static function caching_enabled() {
		/**
		 * Filter whether resolved style attributes are cached.
		 *
		 * @param bool $enabled Default true.
		 */
		return (bool) apply_filters( 'kdna_charts_cache_styles', true );
	}

	/*
	 * ── Why the plugin version is part of the key ─────────────────────
	 *
	 * What is cached is the RESOLVED output of the schema. A plugin
	 * update that adds a control, or changes which property one writes,
	 * makes every cached string wrong, and nothing else in the key says
	 * so: the generation only moves when somebody saves, and the TTL is
	 * a week. Without this, an upgraded site could serve a chart that
	 * did not match its own preview, and both would be "working".
	 *
	 * With the version in the key an update misses every entry and each
	 * chart rebuilds once on first view. The old entries expire on their
	 * TTL rather than being swept: a LIKE query over the options table
	 * is expensive, and an external object cache would not see it.
	 */
	private static function cache_key( $chart_id ) {
		$version = defined( 'KDNA_CHARTS_VERSION' ) ? KDNA_CHARTS_VERSION : '0';

		return self::CACHE_PREFIX
			. str_replace( '.', '', (string) $version ) . '_'
			. self::generation() . '_'
			. (int) $chart_id;
	}

	private static function generation() {
		return (int) get_option( self::GENERATION_OPTION, 1 );
	}

	/**
	 * Invalidate everything, by moving the generation on.
	 *
	 * Called when the global defaults change, which can affect any
	 * chart.
	 */
	public static function invalidate_all() {
		update_option( self::GENERATION_OPTION, self::generation() + 1 );
		self::flush_memo();
	}

	/**
	 * Invalidate one chart, whose overrides changed.
	 *
	 * The global set is left alone: one chart's overrides cannot affect
	 * what any other chart resolves to.
	 */
	public static function invalidate_chart( $chart_id ) {
		$chart_id = (int) $chart_id;
		delete_transient( self::cache_key( $chart_id ) );
		self::flush_memo( $chart_id );
	}

	/**
	 * Drop the per-request memo. Called after a save, and by tests.
	 *
	 * This is the in-request half only; the transients are addressed by
	 * invalidate_all() and invalidate_chart().
	 *
	 * @param int|null $chart_id Chart to forget, or null for all.
	 */
	public static function flush_memo( $chart_id = null ) {
		if ( null === $chart_id ) {
			self::$memo           = array();
			self::$attribute_memo = array();
			return;
		}

		$chart_id = (int) $chart_id;
		unset( self::$memo[ $chart_id ], self::$attribute_memo[ $chart_id ] );
	}

	/**
	 * Watch for writes this plugin did not make.
	 *
	 * The settings page invalidates directly after saving, so these
	 * hooks are for everything else: WP-CLI, an importer, a migration,
	 * another plugin writing the option. Without them a site can be left
	 * rendering a stale cached string with no visible cause and no way
	 * to clear it from the admin.
	 */
	public static function register_invalidation() {
		add_action( 'update_option_' . self::OPTION_KEY, array( __CLASS__, 'invalidate_all' ) );
		add_action( 'add_option_' . self::OPTION_KEY, array( __CLASS__, 'invalidate_all' ) );
		add_action( 'delete_option_' . self::OPTION_KEY, array( __CLASS__, 'invalidate_all' ) );

		foreach ( array( 'updated_post_meta', 'added_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'on_meta_change' ), 10, 3 );
		}
	}

	/**
	 * Invalidate a chart whose style meta was written.
	 *
	 * @param int    $meta_id  Unused.
	 * @param int    $post_id  Post the meta belongs to.
	 * @param string $meta_key Meta key written.
	 */
	public static function on_meta_change( $meta_id, $post_id, $meta_key ) {
		unset( $meta_id );

		if ( self::meta_key() !== $meta_key ) {
			return;
		}

		self::invalidate_chart( $post_id );
	}

	/* ─── Merging ───────────────────────────────────────────────────── */

	/**
	 * The stored layers, in application order.
	 */
	private static function layers( $chart_id, array $extra = array() ) {
		$layers = array();

		$global = get_option( self::OPTION_KEY, array() );
		if ( is_array( $global ) ) {
			$layers[] = $global;
		}

		if ( $chart_id > 0 ) {
			/*
			 * Through the CPT, because the meta is stored JSON encoded.
			 * get_post_meta() here would return a string, is_array()
			 * would reject it, and every chart would silently resolve to
			 * the global defaults with its own overrides ignored.
			 */
			$overrides = KDNA_Charts_CPT::get_json_meta( $chart_id, self::meta_key() );
			if ( is_array( $overrides ) ) {
				$layers[] = $overrides;
			}
		}

		if ( ! empty( $extra ) ) {
			$layers[] = $extra;
		}

		return $layers;
	}

	/**
	 * Merge one incoming value over the current one, leaf by leaf, using
	 * the control definition to know the shape. Returns null when the
	 * incoming value contributes nothing, so the caller can leave the
	 * current value untouched.
	 */
	private static function merge_value( $current, $incoming, array $definition ) {
		if ( self::is_inherit( $incoming ) ) {
			return null;
		}

		if ( empty( $definition['responsive'] ) ) {
			return $incoming;
		}

		// Responsive control: recurse per breakpoint, so a per chart
		// mobile override does not discard the global's desktop value.
		// A bare value written without a device map is read as desktop.
		$incoming = KDNA_Charts_Style_Schema::as_device_map( $incoming );

		$merged = is_array( $current ) ? $current : array();

		foreach ( KDNA_Charts_Style_Schema::DEVICES as $device ) {
			if ( ! array_key_exists( $device, $incoming ) ) {
				continue;
			}
			if ( self::is_inherit( $incoming[ $device ] ) ) {
				/*
				 * Skipped, not cleared. Inherit means "let the layer
				 * beneath show through" at every level, so one breakpoint
				 * left on inherit keeps the global's value there while
				 * its siblings still override.
				 *
				 * The consequence is that an override can replace a
				 * global value but cannot subtract one, which is what
				 * makes the admin's revert-to-global button the single,
				 * predictable way back.
				 */
				continue;
			}
			$merged[ $device ] = $incoming[ $device ];
		}

		return empty( $merged ) ? null : $merged;
	}

	/**
	 * Whether a stored value means "inherit", i.e. contributes nothing.
	 *
	 * Covers every state the admin can produce: never set, the literal
	 * word, an emptied text or colour field, and a dimensions or slider
	 * value whose numbers were cleared but whose unit remains.
	 */
	public static function is_inherit( $value ) {
		if ( null === $value ) {
			return true;
		}

		if ( is_bool( $value ) ) {
			return true;
		}

		if ( is_string( $value ) ) {
			$value = trim( $value );
			return '' === $value || 'inherit' === strtolower( $value );
		}

		if ( is_array( $value ) ) {
			if ( empty( $value ) ) {
				return true;
			}
			foreach ( $value as $key => $part ) {
				// 'unit' and the UI-only 'linked' flag are settings about
				// a value, not a value.
				if ( 'unit' === $key || 'linked' === $key ) {
					continue;
				}
				if ( ! self::is_inherit( $part ) ) {
					return false;
				}
			}
			return true;
		}

		// Numbers, including 0, are values.
		return false;
	}

	/* ─── Flattening to CSS ─────────────────────────────────────────── */

	/**
	 * Turn merged values into custom property name => CSS value.
	 *
	 * Walks the schema rather than the values, so the output order is
	 * the schema's order however the stored array happens to be keyed.
	 * A stable order means the cached string for an unchanged set is
	 * byte identical, which is what makes a diff of two resolved charts
	 * readable.
	 */
	private static function flatten( array $values ) {
		$raw = array();

		foreach ( KDNA_Charts_Style_Schema::get() as $key => $control ) {
			if ( ! array_key_exists( $key, $values ) ) {
				continue;
			}
			$raw = array_merge(
				$raw,
				self::properties_for( $control, $values[ $key ] )
			);
		}

		/*
		 * The output check runs here rather than only at the attribute,
		 * so that what resolve() returns is what would actually be
		 * rendered.
		 *
		 * That matters for two callers beyond the render path. The live
		 * preview resolves in JavaScript and writes the properties
		 * straight onto an element, so it has to be able to reproduce
		 * this exact set: a value PHP would drop must not appear in a
		 * preview claiming to show the front end. And an Elementor
		 * widget reading resolve() to inspect what a chart will look
		 * like should see the truth rather than the request.
		 *
		 * to_style_attribute() still checks. The filter below this runs
		 * after the check and could reintroduce anything, so the last
		 * word belongs to the thing that writes the markup.
		 */
		$properties = array();

		foreach ( $raw as $name => $value ) {
			$name  = self::sanitize_property_name( $name );
			$value = self::sanitize_css_value( $value );
			if ( '' === $name || '' === $value ) {
				continue;
			}
			$properties[ $name ] = $value;
		}

		return $properties;
	}

	/**
	 * The properties one control contributes.
	 */
	private static function properties_for( array $definition, $value ) {
		if ( self::is_inherit( $value ) ) {
			return array();
		}

		$css_var = isset( $definition['css_var'] ) ? (string) $definition['css_var'] : '';
		if ( '' === $css_var ) {
			return array();
		}

		if ( empty( $definition['responsive'] ) ) {
			$css = self::css_value( $definition, $value );
			return '' === $css ? array() : array( $css_var => $css );
		}

		$properties = array();
		$devices    = KDNA_Charts_Style_Schema::as_device_map( $value );

		foreach ( KDNA_Charts_Style_Schema::DEVICES as $device ) {
			if ( ! array_key_exists( $device, $devices ) ) {
				continue;
			}
			$css = self::css_value( $definition, $devices[ $device ] );
			if ( '' === $css ) {
				// Absent, not empty: the stylesheet's fallback chain only
				// falls through on an undefined property.
				continue;
			}
			$name                = ( 'desktop' === $device ) ? $css_var : $css_var . '-' . $device;
			$properties[ $name ] = $css;
		}

		return $properties;
	}

	/**
	 * One stored value as a CSS value string. Returns '' when the value
	 * contributes nothing, which the caller reads as "omit the
	 * property".
	 */
	private static function css_value( array $definition, $value ) {
		if ( self::is_inherit( $value ) ) {
			return '';
		}

		$type = isset( $definition['type'] ) ? $definition['type'] : '';

		switch ( $type ) {
			case 'dimensions':
				return self::dimensions_value( $definition, $value );

			case 'slider':
				return self::slider_value( $definition, $value );

			case 'number':
				if ( ! is_numeric( $value ) ) {
					return '';
				}
				return self::number( $value );

			case 'select':
				$value = is_scalar( $value ) ? trim( (string) $value ) : '';
				if ( '' === $value ) {
					return '';
				}
				// A select can store a key that is not the CSS value, such
				// as an alignment resolving to a margin shorthand.
				if ( isset( $definition['value_map'][ $value ] ) ) {
					return (string) $definition['value_map'][ $value ];
				}
				return $value;

			case 'colour':
			default:
				return is_scalar( $value ) ? trim( (string) $value ) : '';
		}
	}

	/**
	 * Four sides plus a unit, as a CSS shorthand. A side left blank
	 * counts as 0, so a partially filled control still produces valid
	 * CSS rather than nothing at all.
	 */
	private static function dimensions_value( array $definition, $value ) {
		if ( ! is_array( $value ) ) {
			return '';
		}

		$unit  = self::resolve_unit( $definition, $value );
		$sides = array();
		$any   = false;

		foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
			$part = isset( $value[ $side ] ) ? $value[ $side ] : '';
			if ( is_string( $part ) ) {
				$part = trim( $part );
			}
			if ( '' === $part || null === $part || ! is_numeric( $part ) ) {
				$sides[] = '0' . ( '' === $unit ? '' : $unit );
				continue;
			}
			$any     = true;
			$sides[] = self::number( $part ) . $unit;
		}

		return $any ? implode( ' ', $sides ) : '';
	}

	/**
	 * A slider's size plus its unit. The empty unit is legitimate, a
	 * unitless line height being the obvious one, so only the size may be
	 * missing.
	 */
	private static function slider_value( array $definition, $value ) {
		if ( is_numeric( $value ) ) {
			$value = array( 'size' => $value );
		}
		if ( ! is_array( $value ) || ! isset( $value['size'] ) ) {
			return '';
		}

		$size = $value['size'];
		if ( is_string( $size ) ) {
			$size = trim( $size );
		}
		if ( '' === $size || null === $size || ! is_numeric( $size ) ) {
			return '';
		}

		return self::number( $size ) . self::resolve_unit( $definition, $value );
	}

	/**
	 * The unit to use: the stored one when the schema allows it,
	 * otherwise the schema's first unit, otherwise none.
	 */
	private static function resolve_unit( array $definition, $value ) {
		$units = isset( $definition['units'] ) && is_array( $definition['units'] )
			? $definition['units']
			: array();

		$unit = ( is_array( $value ) && isset( $value['unit'] ) ) ? (string) $value['unit'] : null;

		if ( null !== $unit && in_array( $unit, $units, true ) ) {
			return $unit;
		}

		return empty( $units ) ? '' : (string) $units[0];
	}

	/**
	 * Format a number without a trailing '.0' or a locale decimal comma.
	 *
	 * The locale part is not hypothetical: on a site running a locale
	 * where the decimal separator is a comma, a plain string cast of a
	 * float would emit '1,5px', which is two values to a CSS parser and
	 * invalid to a property expecting one.
	 */
	private static function number( $value ) {
		$float = (float) $value;

		if ( (float) (int) $float === $float ) {
			return (string) (int) $float;
		}

		return rtrim( rtrim( number_format( $float, 4, '.', '' ), '0' ), '.' );
	}

	/* ─── Output hardening ──────────────────────────────────────────── */

	/**
	 * Property names come from the schema, but the schema is
	 * filterable, so validate rather than trust.
	 */
	private static function sanitize_property_name( $name ) {
		$name = trim( (string) $name );
		return preg_match( '/^--[A-Za-z0-9_-]+$/', $name ) ? $name : '';
	}

	/**
	 * Keep a value safe to sit inside a style attribute.
	 *
	 * The admin sanitises on save against the schema, but post meta can
	 * be written by anything holding the capability, so the render path
	 * checks again rather than assuming. Anything that could close the
	 * declaration or the attribute, or fetch a remote resource, drops
	 * the property entirely rather than being escaped into something
	 * valid: a broken chart is a bug report, a working chart carrying
	 * somebody else's request is not.
	 */
	public static function sanitize_css_value( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}

		// Strip control characters, including the newlines a pasted value
		// can carry.
		$value = preg_replace( '/[\x00-\x1F\x7F]/', '', $value );
		if ( null === $value || '' === $value ) {
			return '';
		}

		if ( strlen( $value ) > self::MAX_VALUE_LENGTH ) {
			return '';
		}

		/*
		 * Declaration and rule breakout, tag characters, and comment
		 * syntax.
		 *
		 * ── Why quotes are NOT in this list ───────────────────────────
		 *
		 * A font stack needs them: "Helvetica Neue", Helvetica, Arial,
		 * sans-serif is the correct way to write that family, and a
		 * filter that dropped it would leave a Font Family control that
		 * silently did nothing for half the values anybody would type.
		 *
		 * They are safe because every consumer escapes. The render path
		 * passes this string through esc_attr(), which turns a double
		 * quote into &quot; and so cannot close the attribute; the live
		 * preview writes it with style.setProperty(), which is not
		 * parsing markup at all. What a quote cannot do in either place
		 * is escape the value it sits in, which is what the characters
		 * below can, and why those are refused outright rather than
		 * escaped into something that looks valid.
		 */
		if ( preg_match( '/[;{}<>\\\\]|\/\*|\*\//', $value ) ) {
			return '';
		}

		/*
		 * An unclosed quote makes a broken declaration rather than a
		 * dangerous one. It is still refused, because a broken
		 * declaration is a control that appears to have worked and did
		 * not, and that is the failure this whole file is arranged to
		 * avoid.
		 */
		if ( 0 !== substr_count( $value, '"' ) % 2 || 0 !== substr_count( $value, "'" ) % 2 ) {
			return '';
		}

		/*
		 * Remote fetches and legacy script vectors. A custom property is
		 * substituted verbatim into a real declaration downstream, so a
		 * url() here would become a live request from every page the
		 * chart appears on.
		 */
		if ( preg_match( '/(url|expression|image-set|-moz-binding|javascript|@import)\s*[:(]/i', $value ) ) {
			return '';
		}

		return $value;
	}
}
