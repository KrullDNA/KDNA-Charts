<?php
/**
 * Custom post type registration for kdna_chart, the chart library that
 * backs the Elementor widget and the shortcode. Data lives here. The
 * renderers read from these entries and the style engine paints on top.
 *
 * Storage follows the KDNA Tables meta pattern with one deliberate
 * change: every structured part of a chart definition is stored as a
 * JSON encoded string rather than a PHP array. Chart data is nested
 * lists of numbers, and JSON round trips those exactly as authored,
 * which matters when the definition arrives as a JSON file from a
 * Claude conversation and has to come back out unchanged.
 *
 * @package KDNA_Charts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KDNA_Charts_CPT {

	const POST_TYPE = 'kdna_chart';

	/*
	 * Scalar meta, stored as plain strings and integers.
	 */
	const META_TYPE           = '_kdna_chart_type';
	const META_ENGINE         = '_kdna_chart_engine';
	const META_SOURCE         = '_kdna_chart_source';
	const META_CAPTION        = '_kdna_chart_caption';
	const META_SCHEMA         = '_kdna_chart_schema_version';
	const META_CONTENT_HASH   = '_kdna_chart_content_hash';

	/*
	 * Structured meta, stored JSON encoded.
	 */
	const META_OPTIONS  = '_kdna_chart_options';
	const META_AXES     = '_kdna_chart_axes';
	const META_SERIES   = '_kdna_chart_series';
	const META_MARKERS  = '_kdna_chart_markers';
	const META_POINTS   = '_kdna_chart_points';
	const META_CALLOUTS = '_kdna_chart_callouts';
	const META_NOTES    = '_kdna_chart_notes';
	const META_STYLE    = '_kdna_chart_style_overrides';

	/**
	 * Chart definition schema version. Matches the kdna_chart key in the
	 * interchange JSON. KDNA_Charts_Schema owns what the number means,
	 * this constant is the version the storage layer writes.
	 */
	const SCHEMA_VERSION = KDNA_Charts_Schema::VERSION;

	/**
	 * Structured meta parts, mapped to the definition key each one holds
	 * and whether that key is a list or a keyed object. Everything that
	 * walks the definition generically reads this map, so adding a part
	 * later is a one line change.
	 */
	const JSON_PARTS = array(
		self::META_OPTIONS  => array( 'key' => 'options',  'shape' => 'object' ),
		self::META_AXES     => array( 'key' => 'axes',     'shape' => 'object' ),
		self::META_SERIES   => array( 'key' => 'series',   'shape' => 'list' ),
		self::META_MARKERS  => array( 'key' => 'markers',  'shape' => 'list' ),
		self::META_POINTS   => array( 'key' => 'points',   'shape' => 'list' ),
		self::META_CALLOUTS => array( 'key' => 'callouts', 'shape' => 'list' ),
		self::META_NOTES    => array( 'key' => 'notes',    'shape' => 'list' ),
		self::META_STYLE    => array( 'key' => 'style',    'shape' => 'object' ),
	);

	/**
	 * How deep a structure may nest before the sanitiser stops walking.
	 * The deepest legitimate path in the schema is roughly
	 * axes > x > ticks > n > value, so twelve is generous.
	 */
	const MAX_DEPTH = 12;

	/**
	 * Ceiling on a single encoded meta value. A 200 point chart is a few
	 * kilobytes, so anything past this is a runaway or a paste accident
	 * rather than a chart.
	 */
	const MAX_JSON_BYTES = 1048576;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_action( 'init', array( __CLASS__, 'register_meta' ) );
	}

	/*
	 * ====================================================================
	 * Registration
	 * ====================================================================
	 */

	public static function register_post_type() {
		$labels = array(
			'name'               => _x( 'KDNA Charts', 'post type general name', 'kdna-charts' ),
			'singular_name'      => _x( 'KDNA Chart', 'post type singular name', 'kdna-charts' ),
			'menu_name'          => _x( 'KDNA Charts', 'admin menu', 'kdna-charts' ),
			'name_admin_bar'     => _x( 'KDNA Chart', 'add new on admin bar', 'kdna-charts' ),
			'add_new'            => _x( 'Add New', 'kdna_chart', 'kdna-charts' ),
			'add_new_item'       => __( 'Add New Chart', 'kdna-charts' ),
			'new_item'           => __( 'New Chart', 'kdna-charts' ),
			'edit_item'          => __( 'Edit Chart', 'kdna-charts' ),
			'view_item'          => __( 'View Chart', 'kdna-charts' ),
			'all_items'          => __( 'All Charts', 'kdna-charts' ),
			'search_items'       => __( 'Search Charts', 'kdna-charts' ),
			'not_found'          => __( 'No charts found.', 'kdna-charts' ),
			'not_found_in_trash' => __( 'No charts found in Trash.', 'kdna-charts' ),
		);

		$args = array(
			'labels'              => $labels,
			'public'              => false,
			'publicly_queryable'  => false,
			'show_ui'             => true,
			'show_in_menu'        => false,
			'show_in_admin_bar'   => false,
			'show_in_nav_menus'   => false,
			'show_in_rest'        => false,
			'exclude_from_search' => true,
			'hierarchical'        => false,
			'has_archive'         => false,
			'rewrite'             => false,
			'query_var'           => false,
			'capability_type'     => 'post',
			'map_meta_cap'        => true,
			'supports'            => array( 'title' ),
			'menu_icon'           => 'dashicons-chart-line',
		);

		register_post_type( self::POST_TYPE, $args );
	}

	public static function register_meta() {
		$auth = array( __CLASS__, 'meta_auth' );

		$scalars = array(
			self::META_TYPE   => array( 'string', array( __CLASS__, 'sanitize_type' ) ),
			self::META_ENGINE => array( 'string', array( __CLASS__, 'sanitize_engine' ) ),
			self::META_SOURCE => array( 'string', 'sanitize_text_field' ),
			// Captions carry the occasional italic or link, so kses rather
			// than a flat strip.
			self::META_CAPTION => array( 'string', 'wp_kses_post' ),
			self::META_SCHEMA  => array( 'integer', 'absint' ),
			self::META_CONTENT_HASH => array( 'string', 'sanitize_text_field' ),
		);

		foreach ( $scalars as $meta_key => $spec ) {
			register_post_meta(
				self::POST_TYPE,
				$meta_key,
				array(
					'type'              => $spec[0],
					'single'            => true,
					'show_in_rest'      => false,
					'sanitize_callback' => $spec[1],
					'auth_callback'     => $auth,
				)
			);
		}

		/*
		 * Structured parts. The sanitise callback accepts either an array
		 * or an already encoded JSON string, and always returns a JSON
		 * string, so the stored shape is the same whichever way the value
		 * arrived. Style overrides are administrator only, matching the
		 * KDNA Tables rule that only someone who can manage options gets
		 * to change how charts look.
		 */
		foreach ( self::JSON_PARTS as $meta_key => $part ) {
			$part_auth = ( self::META_STYLE === $meta_key )
				? array( __CLASS__, 'style_meta_auth' )
				: $auth;

			register_post_meta(
				self::POST_TYPE,
				$meta_key,
				array(
					'type'              => 'string',
					'single'            => true,
					'default'           => '',
					'show_in_rest'      => false,
					'sanitize_callback' => function ( $value ) use ( $part ) {
						return self::sanitize_json_part( $value, $part['shape'] );
					},
					'auth_callback'     => $part_auth,
				)
			);
		}
	}

	public static function meta_auth( $allowed, $meta_key, $post_id ) {
		return current_user_can( 'edit_post', $post_id );
	}

	public static function style_meta_auth() {
		return current_user_can( 'manage_options' );
	}

	/*
	 * ====================================================================
	 * Sanitisers
	 * ====================================================================
	 */

	/**
	 * Returns a valid chart type, or an empty string when the value is
	 * not one of the seven types in v1.
	 */
	public static function sanitize_type( $value ) {
		$value = is_string( $value ) ? strtolower( trim( $value ) ) : '';
		return KDNA_Charts_Schema::is_type( $value ) ? $value : '';
	}

	/**
	 * Returns svg or chartjs. An empty string is a valid answer and means
	 * "no per chart choice", which resolves to the global default engine.
	 */
	public static function sanitize_engine( $value ) {
		$value = is_string( $value ) ? strtolower( trim( $value ) ) : '';
		if ( 'chart.js' === $value || 'chart_js' === $value ) {
			$value = 'chartjs';
		}
		return KDNA_Charts_Schema::is_engine( $value ) ? $value : '';
	}

	/**
	 * Normalises one structured part and returns it JSON encoded.
	 *
	 * Accepts an array, or a JSON string, or anything else, and never
	 * throws. This is storage safety only: it guarantees the value is a
	 * bounded tree of scalars with sane keys. Whether the tree means
	 * anything as a chart is the schema validator's job from Stage 2.
	 *
	 * @param mixed  $value Raw value.
	 * @param string $shape 'list' or 'object'.
	 * @return string JSON encoded structure, '[]' or '{}' when empty.
	 */
	public static function sanitize_json_part( $value, $shape = 'object' ) {
		$decoded = self::to_array( $value );
		$clean   = self::sanitize_structure( $decoded );
		if ( ! is_array( $clean ) ) {
			$clean = array();
		}

		if ( 'list' === $shape ) {
			$list = array();
			foreach ( $clean as $item ) {
				// A list of anything other than entries is not a list we
				// can render from, so non arrays are dropped rather than
				// kept as noise.
				if ( is_array( $item ) ) {
					$list[] = $item;
				}
			}
			return wp_json_encode( array_values( $list ) );
		}

		// An empty object must encode as {} rather than [], otherwise a
		// round trip turns "no overrides" into "an empty list".
		if ( empty( $clean ) ) {
			return '{}';
		}
		return wp_json_encode( (object) $clean );
	}

	/**
	 * Decodes a value into an array. Handles the three ways a definition
	 * part reaches us: already an array, a JSON string, or an object from
	 * a json_decode that did not ask for associative arrays.
	 */
	public static function to_array( $value ) {
		if ( is_array( $value ) ) {
			return $value;
		}
		if ( is_object( $value ) ) {
			return json_decode( (string) wp_json_encode( $value ), true ) ?: array();
		}
		if ( is_string( $value ) ) {
			$trimmed = trim( $value );
			if ( '' === $trimmed ) {
				return array();
			}
			$decoded = json_decode( $trimmed, true );
			return is_array( $decoded ) ? $decoded : array();
		}
		return array();
	}

	/**
	 * Recursively sanitises a decoded structure. String values are text
	 * sanitised, numbers and booleans pass through, nulls are preserved
	 * because a null y value is a legitimate gap in a series, and
	 * anything deeper than MAX_DEPTH is cut off.
	 *
	 * Integer keys are preserved as list positions. String keys are run
	 * through sanitize_key, which is safe here because every key in the
	 * schema is lower case snake case.
	 *
	 * @param mixed $value Decoded value.
	 * @param int   $depth Current recursion depth.
	 * @return mixed
	 */
	public static function sanitize_structure( $value, $depth = 0 ) {
		if ( $depth > self::MAX_DEPTH ) {
			return null;
		}

		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				$clean_item = self::sanitize_structure( $item, $depth + 1 );
				// Preserve a genuine null, drop something the sanitiser
				// refused (an object, a resource, an over deep branch).
				if ( null === $clean_item && null !== $item ) {
					continue;
				}
				if ( is_int( $key ) ) {
					$out[] = $clean_item;
					continue;
				}
				$clean_key = sanitize_key( (string) $key );
				if ( '' === $clean_key ) {
					continue;
				}
				$out[ $clean_key ] = $clean_item;
			}
			return $out;
		}

		if ( is_bool( $value ) || is_int( $value ) ) {
			return $value;
		}
		if ( is_float( $value ) ) {
			// NAN and INF cannot be JSON encoded, so they never reach storage.
			return is_finite( $value ) ? $value : null;
		}
		if ( is_string( $value ) ) {
			return sanitize_text_field( $value );
		}
		if ( null === $value ) {
			return null;
		}
		return null;
	}

	/*
	 * ====================================================================
	 * Reading and writing a definition
	 * ====================================================================
	 */

	/**
	 * Writes one structured part.
	 *
	 * The wp_slash is not decoration. update_post_meta runs wp_unslash
	 * over the value before it stores it, and JSON escapes quotes and
	 * backslashes with backslashes, so an unslashed JSON string would
	 * come out the far side with its escapes eaten and its labels broken.
	 * Slashing first means the unslash restores exactly what we encoded.
	 *
	 * @param int    $post_id  Chart post ID.
	 * @param string $meta_key One of the JSON_PARTS keys.
	 * @param mixed  $value    Array or JSON string.
	 * @return bool True when written, false when the part is unknown or
	 *              the encoded value is larger than MAX_JSON_BYTES.
	 */
	public static function update_json_meta( $post_id, $meta_key, $value ) {
		if ( ! isset( self::JSON_PARTS[ $meta_key ] ) ) {
			return false;
		}
		$json = self::sanitize_json_part( $value, self::JSON_PARTS[ $meta_key ]['shape'] );
		if ( ! is_string( $json ) || strlen( $json ) > self::MAX_JSON_BYTES ) {
			return false;
		}
		update_post_meta( (int) $post_id, $meta_key, wp_slash( $json ) );
		return true;
	}

	/**
	 * Reads one structured part back as an array. Always an array, never
	 * null, so callers can foreach without checking first.
	 */
	public static function get_json_meta( $post_id, $meta_key ) {
		$raw = get_post_meta( (int) $post_id, $meta_key, true );
		return self::to_array( $raw );
	}

	/**
	 * Writes a whole chart definition and recomputes the content hash.
	 * This is the single write path: the importer at Stage 2 and the
	 * editor at Stage 8 both come through here, so the hash can never
	 * drift from the data it describes.
	 *
	 * The definition is the interchange shape from section 4 of the
	 * brief. Keys that are absent are left untouched rather than blanked,
	 * so a partial update stays partial.
	 *
	 * @param int   $post_id    Chart post ID.
	 * @param array $definition Chart definition, interchange shape.
	 * @return bool True on success, false when the post is not a chart.
	 */
	public static function save_definition( $post_id, array $definition ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 || get_post_type( $post_id ) !== self::POST_TYPE ) {
			return false;
		}

		if ( array_key_exists( 'type', $definition ) ) {
			update_post_meta( $post_id, self::META_TYPE, self::sanitize_type( $definition['type'] ) );
		}
		if ( array_key_exists( 'engine', $definition ) ) {
			update_post_meta( $post_id, self::META_ENGINE, self::sanitize_engine( $definition['engine'] ) );
		}
		if ( array_key_exists( 'source', $definition ) ) {
			update_post_meta( $post_id, self::META_SOURCE, (string) $definition['source'] );
		}
		if ( array_key_exists( 'caption', $definition ) ) {
			update_post_meta( $post_id, self::META_CAPTION, (string) $definition['caption'] );
		}

		foreach ( self::JSON_PARTS as $meta_key => $part ) {
			if ( array_key_exists( $part['key'], $definition ) ) {
				self::update_json_meta( $post_id, $meta_key, $definition[ $part['key'] ] );
			}
		}

		update_post_meta( $post_id, self::META_SCHEMA, self::SCHEMA_VERSION );
		self::refresh_content_hash( $post_id );

		return true;
	}

	/**
	 * Returns the stored chart definition in the interchange shape, ready
	 * to hand to a renderer or to write back out as a JSON file.
	 *
	 * Returns an empty array when the post does not exist or is not a
	 * chart, so the caller can fall back to the placeholder.
	 */
	public static function get_definition( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return array();
		}
		$post = get_post( $post_id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return array();
		}

		$definition = array(
			'kdna_chart' => (int) ( get_post_meta( $post_id, self::META_SCHEMA, true ) ?: self::SCHEMA_VERSION ),
			'title'      => (string) $post->post_title,
			'type'       => (string) get_post_meta( $post_id, self::META_TYPE, true ),
			'engine'     => (string) get_post_meta( $post_id, self::META_ENGINE, true ),
		);

		foreach ( self::JSON_PARTS as $meta_key => $part ) {
			$definition[ $part['key'] ] = self::get_json_meta( $post_id, $meta_key );
		}

		$definition['source']  = (string) get_post_meta( $post_id, self::META_SOURCE, true );
		$definition['caption'] = (string) get_post_meta( $post_id, self::META_CAPTION, true );

		return $definition;
	}

	/*
	 * ====================================================================
	 * Content hash
	 * ====================================================================
	 */

	/**
	 * Hashes the meaningful content of a definition. Internal ids are
	 * stripped first, so two charts that look identical to a reader hash
	 * identically whatever UUIDs their series happen to carry.
	 *
	 * Used by the importer to spot a re-import of the same file, and by
	 * the renderers as a cache key.
	 */
	public static function content_hash( array $definition ) {
		unset( $definition['kdna_chart'] );
		$clean = self::strip_ids( $definition );
		self::ksort_deep( $clean );
		return md5( (string) wp_json_encode( $clean ) );
	}

	public static function refresh_content_hash( $post_id ) {
		$post_id = (int) $post_id;
		$hash    = self::content_hash( self::get_definition( $post_id ) );
		update_post_meta( $post_id, self::META_CONTENT_HASH, $hash );
		return $hash;
	}

	/**
	 * Finds an existing chart carrying a given content hash, or 0.
	 */
	public static function find_by_hash( $hash ) {
		$hash = sanitize_text_field( (string) $hash );
		if ( '' === $hash ) {
			return 0;
		}
		$ids = get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => array( 'publish', 'draft', 'private' ),
				'numberposts'      => 1,
				'fields'           => 'ids',
				'meta_key'         => self::META_CONTENT_HASH,
				'meta_value'       => $hash,
				'suppress_filters' => true,
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	private static function strip_ids( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$out = array();
		foreach ( $value as $key => $item ) {
			if ( 'id' === $key || '_id' === $key ) {
				continue;
			}
			$out[ $key ] = is_array( $item ) ? self::strip_ids( $item ) : $item;
		}
		return $out;
	}

	/**
	 * Sorts keyed arrays so key order cannot change the hash, while
	 * leaving lists in their authored order because the order of points
	 * along a line is the data.
	 */
	private static function ksort_deep( array &$value ) {
		$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( ! $is_list ) {
			ksort( $value );
		}
		foreach ( $value as &$item ) {
			if ( is_array( $item ) ) {
				self::ksort_deep( $item );
			}
		}
		unset( $item );
	}

	/*
	 * ====================================================================
	 * Starter data
	 * ====================================================================
	 */

	/**
	 * The definition written into a freshly created chart, so a new entry
	 * opens on something rather than nothing.
	 *
	 * The shapes themselves live in the schema class, which is the single
	 * source of truth for what a chart may contain. This stays here as
	 * the storage layer's way of asking for one.
	 *
	 * @param string $type One of the schema's chart types.
	 * @return array
	 */
	public static function default_definition( $type ) {
		return KDNA_Charts_Schema::default_definition( self::sanitize_type( $type ) );
	}

	/*
	 * ====================================================================
	 * Convenience readers
	 * ====================================================================
	 */

	/**
	 * Returns the chart type for a post id, or '' when the post is not a
	 * chart or has no type set.
	 */
	public static function get_type( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 || get_post_type( $post_id ) !== self::POST_TYPE ) {
			return '';
		}
		return self::sanitize_type( get_post_meta( $post_id, self::META_TYPE, true ) );
	}

	/**
	 * Every meta key this post type owns, in the order a duplicate should
	 * copy them. The content hash is deliberately absent: a duplicate is
	 * logically a new entry and gets its hash recomputed.
	 */
	public static function all_meta_keys() {
		return array_merge(
			array(
				self::META_TYPE,
				self::META_ENGINE,
				self::META_SOURCE,
				self::META_CAPTION,
				self::META_SCHEMA,
			),
			array_keys( self::JSON_PARTS )
		);
	}
}
