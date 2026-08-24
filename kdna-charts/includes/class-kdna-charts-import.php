<?php
/**
 * The JSON importer. Takes a chart definition file, checks it against
 * the schema, and creates a chart post from it.
 *
 * The governing idea, from section 4.4 of the brief, is that an import
 * should almost never fail. A file with a key the plugin does not know
 * about is a file with one extra key, not a broken file. A file with
 * three malformed points is a chart with three fewer points. Either
 * way the user gets a chart and a plain English account of what
 * happened to their file.
 *
 * Exactly two things are fatal, and both mean the file is not a KDNA
 * Charts definition at all: no schema version, and no usable chart
 * type.
 *
 * @package KDNA_Charts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The account of what an import did.
 *
 * Lives in this file rather than its own because it exists only to
 * serve the importer and has no meaning apart from it.
 *
 * Four buckets, because the four things that can happen to a key are
 * genuinely different and a user reading the summary needs them apart:
 *
 *   discarded  A key the schema does not know. Removed.
 *   dropped    A known key whose value was unusable. Removed.
 *   repaired   A known key whose value was fixable. Kept, changed.
 *   ignored    A valid key this chart type will not draw. Kept as is.
 */
class KDNA_Charts_Import_Report {

	private $discarded = array();
	private $dropped   = array();
	private $repaired  = array();
	private $ignored   = array();

	/** How many example paths a grouped line names before it stops. */
	const MAX_EXAMPLES = 4;

	public function discard( $path, $reason = '' ) {
		$this->discarded[] = array(
			'path'   => (string) $path,
			'reason' => (string) $reason,
		);
	}

	public function drop( $path, $reason ) {
		$this->dropped[] = array(
			'path'   => (string) $path,
			'reason' => (string) $reason,
		);
	}

	public function repair( $path, $note ) {
		$this->repaired[] = array(
			'path' => (string) $path,
			'note' => (string) $note,
		);
	}

	public function ignore( $note ) {
		$this->ignored[] = (string) $note;
	}

	public function count_discarded() {
		return count( $this->discarded );
	}

	public function count_dropped() {
		return count( $this->dropped );
	}

	public function count_repaired() {
		return count( $this->repaired );
	}

	public function is_clean() {
		return empty( $this->discarded ) && empty( $this->dropped )
			&& empty( $this->repaired ) && empty( $this->ignored );
	}

	/**
	 * The report as a plain array, safe to put in a transient.
	 */
	public function to_array() {
		return array(
			'discarded' => $this->discarded,
			'dropped'   => $this->dropped,
			'repaired'  => $this->repaired,
			'ignored'   => $this->ignored,
		);
	}

	public static function from_array( $data ) {
		$report = new self();
		if ( ! is_array( $data ) ) {
			return $report;
		}
		foreach ( array( 'discarded', 'dropped', 'repaired' ) as $bucket ) {
			if ( empty( $data[ $bucket ] ) || ! is_array( $data[ $bucket ] ) ) {
				continue;
			}
			foreach ( $data[ $bucket ] as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				$report->{$bucket}[] = $entry;
			}
		}
		if ( ! empty( $data['ignored'] ) && is_array( $data['ignored'] ) ) {
			foreach ( $data['ignored'] as $note ) {
				$report->ignored[] = (string) $note;
			}
		}
		return $report;
	}

	/*
	 * ====================================================================
	 * Plain English
	 * ====================================================================
	 */

	/**
	 * The lines of the import summary, in reading order. Returned as a
	 * list of strings rather than one blob so the notice can render them
	 * as a list and the first line can be emphasised.
	 *
	 * @param array $definition The definition as saved.
	 * @return string[]
	 */
	public function lines( array $definition ) {
		$lines = array();

		if ( $this->is_clean() ) {
			$lines[] = __( 'The file imported exactly as written. Nothing was ignored, dropped or repaired.', 'kdna-charts' );
			return $lines;
		}

		if ( ! empty( $this->discarded ) ) {
			$lines[] = $this->group_line(
				$this->discarded,
				'path',
				__( 'Keys the schema does not recognise, left out:', 'kdna-charts' )
			);
		}

		if ( ! empty( $this->dropped ) ) {
			$lines[] = $this->group_line(
				$this->dropped,
				'reason',
				__( 'Entries dropped because their values could not be used:', 'kdna-charts' )
			);
		}

		if ( ! empty( $this->repaired ) ) {
			$lines[] = $this->group_line(
				$this->repaired,
				'note',
				__( 'Values repaired:', 'kdna-charts' )
			);
		}

		foreach ( $this->ignored as $note ) {
			$lines[] = $note;
		}

		return $lines;
	}

	/**
	 * The one line version, for a list table or a log.
	 */
	public function one_line() {
		if ( $this->is_clean() ) {
			return __( 'Imported cleanly.', 'kdna-charts' );
		}
		return sprintf(
			/* translators: 1: discarded key count, 2: dropped entry count, 3: repaired value count */
			__( '%1$d unrecognised keys, %2$d entries dropped, %3$d values repaired.', 'kdna-charts' ),
			$this->count_discarded(),
			$this->count_dropped(),
			$this->count_repaired()
		);
	}

	/**
	 * Groups a bucket by one of its fields and renders it as one line,
	 * so a chart with forty numeric strings reads as one repair with a
	 * count of forty rather than forty near identical sentences.
	 */
	private function group_line( array $bucket, $group_by, $heading ) {
		$groups = array();

		foreach ( $bucket as $entry ) {
			$key = isset( $entry[ $group_by ] ) ? (string) $entry[ $group_by ] : '';
			// Collapse list indices, so series[0].colour and series[3].colour
			// are one finding about colour rather than two about positions.
			$key = self::generalise_path( $key );
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'count'    => 0,
					'examples' => array(),
				);
			}
			$groups[ $key ]['count']++;
			$path = isset( $entry['path'] ) ? (string) $entry['path'] : '';
			if ( '' !== $path && count( $groups[ $key ]['examples'] ) < self::MAX_EXAMPLES ) {
				$groups[ $key ]['examples'][] = $path;
			}
		}

		$parts = array();
		foreach ( $groups as $key => $group ) {
			if ( $group['count'] > 1 ) {
				$parts[] = sprintf(
					/* translators: 1: what happened or the key path, 2: how many times */
					__( '%1$s (%2$d times)', 'kdna-charts' ),
					$key,
					$group['count']
				);
				continue;
			}
			$parts[] = $key;
		}

		return $heading . ' ' . implode( '; ', $parts ) . '.';
	}

	/**
	 * Turns series[3].segments[1].colour into series[].segments[].colour.
	 */
	public static function generalise_path( $path ) {
		return preg_replace( '/\[\d+\]/', '[]', (string) $path );
	}
}

/**
 * The importer itself.
 */
class KDNA_Charts_Import {

	const MENU_SLUG = 'kdna-charts-import';

	const NONCE_IMPORT   = 'kdna_charts_import';
	const ACTION_IMPORT  = 'kdna_charts_import';
	const FILE_FIELD     = 'kdna_chart_file';
	const PASTE_FIELD    = 'kdna_chart_json';

	/** Transient prefix the edit screen reads its summary back from. */
	const REPORT_TRANSIENT = 'kdna_charts_import_report_';
	const REPORT_TTL       = 900;

	/** Largest file or paste the importer will look at. */
	const MAX_BYTES = 1048576;

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 11 );
		add_action( 'admin_post_' . self::ACTION_IMPORT, array( __CLASS__, 'handle_import' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_import_notice' ) );
	}

	public static function register_menu() {
		add_submenu_page(
			KDNA_Charts_Admin::MENU_SLUG_LIST,
			__( 'Import Chart', 'kdna-charts' ),
			__( 'Import', 'kdna-charts' ),
			'edit_posts',
			self::MENU_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function get_import_url() {
		return admin_url( 'admin.php?page=' . self::MENU_SLUG );
	}

	/*
	 * ====================================================================
	 * Decoding
	 * ====================================================================
	 */

	/**
	 * Turns raw text into a decoded array, or a WP_Error explaining why
	 * it could not.
	 *
	 * Tolerates two things the authoring prompt asks Claude not to do,
	 * because a person copying an answer out of a conversation will hit
	 * both: a UTF-8 byte order mark, and markdown code fences around the
	 * JSON.
	 *
	 * @param string                        $raw    Raw file or paste contents.
	 * @param KDNA_Charts_Import_Report|null $report Optional, to record repairs.
	 * @return array|WP_Error
	 */
	public static function decode( $raw, $report = null ) {
		if ( ! is_string( $raw ) ) {
			return new WP_Error(
				'kdna_charts_no_content',
				__( 'There was nothing to import.', 'kdna-charts' )
			);
		}

		if ( strlen( $raw ) > self::MAX_BYTES ) {
			return new WP_Error(
				'kdna_charts_too_large',
				sprintf(
					/* translators: %s: size limit, already formatted, for example 1 MB */
					__( 'That file is larger than %s. A chart definition is normally a few kilobytes, so this is almost certainly not one.', 'kdna-charts' ),
					size_format( self::MAX_BYTES )
				)
			);
		}

		$text = trim( $raw );

		// Strip a UTF-8 byte order mark, which json_decode will not accept.
		if ( 0 === strpos( $text, "\xEF\xBB\xBF" ) ) {
			$text = substr( $text, 3 );
			if ( $report ) {
				$report->repair( '', __( 'a byte order mark at the start of the file was removed', 'kdna-charts' ) );
			}
		}

		// Strip markdown code fences, ```json at the top and ``` at the end.
		if ( 0 === strpos( $text, '```' ) ) {
			$stripped = preg_replace( '/^```[a-zA-Z]*\s*\n?/', '', $text );
			$stripped = preg_replace( '/\n?```\s*$/', '', (string) $stripped );
			if ( is_string( $stripped ) && $stripped !== $text ) {
				$text = trim( $stripped );
				if ( $report ) {
					$report->repair( '', __( 'markdown code fences around the JSON were removed', 'kdna-charts' ) );
				}
			}
		}

		if ( '' === $text ) {
			return new WP_Error(
				'kdna_charts_no_content',
				__( 'There was nothing to import. Choose a file, or paste a chart definition into the box.', 'kdna-charts' )
			);
		}

		$decoded = json_decode( $text, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error(
				'kdna_charts_bad_json',
				sprintf(
					/* translators: %s: the JSON parser's own error message */
					__( 'That is not valid JSON. The parser said: %s. Check for a trailing comma, a missing bracket, or curly quotation marks pasted in from a word processor.', 'kdna-charts' ),
					json_last_error_msg()
				)
			);
		}

		/*
		 * A JSON list decodes to a PHP array just as an object does, so
		 * is_array alone would wave through [1, 2, 3]. A definition is a
		 * single object, and saying so plainly beats failing later on a
		 * missing version key.
		 */
		$is_list = is_array( $decoded ) && array_keys( $decoded ) === range( 0, count( $decoded ) - 1 );
		if ( ! is_array( $decoded ) || ( $is_list && ! empty( $decoded ) ) ) {
			return new WP_Error(
				'kdna_charts_not_an_object',
				__( 'That JSON is valid but it is not a chart. A chart definition is a single JSON object, starting with a curly brace, not a list.', 'kdna-charts' )
			);
		}

		return $decoded;
	}

	/*
	 * ====================================================================
	 * Validation
	 * ====================================================================
	 */

	/**
	 * Validates a decoded chart definition against schema version 1.
	 *
	 * @param array                          $raw    Decoded definition.
	 * @param KDNA_Charts_Import_Report|null $report Optional existing report to add to.
	 * @return array|WP_Error array( 'definition' => array, 'report' => KDNA_Charts_Import_Report )
	 */
	public static function validate( array $raw, $report = null ) {
		if ( ! $report instanceof KDNA_Charts_Import_Report ) {
			$report = new KDNA_Charts_Import_Report();
		}

		/*
		 * ── The two fatal cases ────────────────────────────────────────
		 *
		 * Both say the same thing in different ways: this file is not a
		 * KDNA Charts definition. Everything else the importer can work
		 * around, and does.
		 */
		if ( ! array_key_exists( 'kdna_chart', $raw ) ) {
			return new WP_Error(
				'kdna_charts_missing_version',
				sprintf(
					/* translators: %d: the schema version this plugin understands */
					__( 'This file has no kdna_chart key, so it does not say which version of the chart schema it was written for. Add "kdna_chart": %d as the first key and import it again.', 'kdna-charts' ),
					KDNA_Charts_Schema::VERSION
				)
			);
		}

		$version = $raw['kdna_chart'];
		if ( ! is_numeric( $version ) || (int) $version !== KDNA_Charts_Schema::VERSION ) {
			return new WP_Error(
				'kdna_charts_unsupported_version',
				sprintf(
					/* translators: 1: version found in the file, 2: version this plugin understands */
					__( 'This file says it is chart schema version %1$s. This plugin understands version %2$d. Nothing has been imported.', 'kdna-charts' ),
					is_scalar( $version ) ? (string) $version : __( 'unreadable', 'kdna-charts' ),
					KDNA_Charts_Schema::VERSION
				)
			);
		}

		$type = isset( $raw['type'] ) && is_string( $raw['type'] ) ? strtolower( trim( $raw['type'] ) ) : '';
		if ( ! KDNA_Charts_Schema::is_type( $type ) ) {
			return new WP_Error(
				'kdna_charts_missing_type',
				sprintf(
					/* translators: 1: the type found in the file, or a note that there was none, 2: the list of valid types */
					__( 'This file does not say what kind of chart it is. It has type %1$s, and the schema allows %2$s. Nothing has been imported.', 'kdna-charts' ),
					'' === $type ? __( 'nothing', 'kdna-charts' ) : '"' . esc_html( $type ) . '"',
					implode( ', ', KDNA_Charts_Schema::TYPES )
				)
			);
		}

		$spec       = KDNA_Charts_Schema::definition_spec();
		$definition = array(
			'kdna_chart' => KDNA_Charts_Schema::VERSION,
			'type'       => $type,
		);

		// Title is required by the schema but a missing one is a repair,
		// not a refusal. A chart with no name is still a chart.
		$title = isset( $raw['title'] ) && is_scalar( $raw['title'] ) ? trim( (string) $raw['title'] ) : '';
		if ( '' === $title ) {
			$title = sprintf(
				/* translators: %s: chart type label, lower case, for example line */
				__( 'Untitled %s chart', 'kdna-charts' ),
				strtolower( KDNA_Charts_Schema::type_label( $type ) )
			);
			$report->repair( 'title', __( 'the file had no title, so one was generated', 'kdna-charts' ) );
		}
		$definition['title'] = $title;

		foreach ( $raw as $key => $value ) {
			$key = (string) $key;

			// Already handled above.
			if ( in_array( $key, array( 'kdna_chart', 'type', 'title' ), true ) ) {
				continue;
			}

			if ( ! isset( $spec[ $key ] ) ) {
				$report->discard( $key );
				continue;
			}

			if ( 'options' === $spec[ $key ]['kind'] ) {
				$definition['options'] = self::coerce_options( $value, $type, $report );
				continue;
			}

			$result = self::coerce( $value, $spec[ $key ], $key, $report );
			if ( $result['ok'] ) {
				$definition[ $key ] = $result['value'];
			}
		}

		self::add_type_notes( $definition, $type, $report );

		return array(
			'definition' => $definition,
			'report'     => $report,
		);
	}

	/**
	 * Records the things a definition holds that this chart type will
	 * keep but not draw.
	 *
	 * Nothing is removed here. Section 3.1's rule that switching engine
	 * never destroys data reads the same way for switching type: the
	 * plugin should always be able to hand back what it was given.
	 */
	private static function add_type_notes( array $definition, $type, KDNA_Charts_Import_Report $report ) {
		if ( ! KDNA_Charts_Schema::uses_axes( $type ) && ! empty( $definition['axes'] ) ) {
			$report->ignore(
				sprintf(
					/* translators: %s: chart type label, lower case */
					__( 'The axes block has been kept, but a %s chart does not draw axes.', 'kdna-charts' ),
					strtolower( KDNA_Charts_Schema::type_label( $type ) )
				)
			);
		}

		if ( ! KDNA_Charts_Schema::draws_annotations( $type ) ) {
			$annotations = 0;
			foreach ( array( 'markers', 'points', 'callouts', 'notes' ) as $key ) {
				$annotations += empty( $definition[ $key ] ) ? 0 : count( $definition[ $key ] );
			}
			if ( $annotations > 0 ) {
				$report->ignore(
					sprintf(
						/* translators: 1: number of annotations, 2: chart type label, lower case */
						_n(
							'%1$d annotation has been kept, but a %2$s chart has no plot coordinates to place it on. It will draw again if the chart is changed to a line, area, bar or column type.',
							'%1$d annotations have been kept, but a %2$s chart has no plot coordinates to place them on. They will draw again if the chart is changed to a line, area, bar or column type.',
							$annotations,
							'kdna-charts'
						),
						$annotations,
						strtolower( KDNA_Charts_Schema::type_label( $type ) )
					)
				);
			}
		}

		if ( KDNA_Charts_Schema::uses_segments( $type ) ) {
			$has_segments = false;
			foreach ( (array) ( $definition['series'] ?? array() ) as $series ) {
				if ( ! empty( $series['segments'] ) ) {
					$has_segments = true;
					break;
				}
			}
			if ( ! $has_segments ) {
				$report->ignore( __( 'No plotted points were found. A line or area chart needs at least one series with a segment of points.', 'kdna-charts' ) );
			}
		}

		if ( KDNA_Charts_Schema::uses_data( $type ) ) {
			$has_data = false;
			foreach ( (array) ( $definition['series'] ?? array() ) as $series ) {
				if ( ! empty( $series['data'] ) ) {
					$has_data = true;
					break;
				}
			}
			if ( ! $has_data ) {
				$report->ignore(
					sprintf(
						/* translators: %s: chart type label, lower case */
						__( 'No label and value pairs were found. A %s chart reads its figures from series[].data.', 'kdna-charts' ),
						strtolower( KDNA_Charts_Schema::type_label( $type ) )
					)
				);
			}
		}
	}

	/**
	 * Options are the one part of the schema that depends on the chart
	 * type, so they get their own pass rather than a node in the tree.
	 */
	private static function coerce_options( $value, $type, KDNA_Charts_Import_Report $report ) {
		if ( ! is_array( $value ) ) {
			if ( null !== $value ) {
				$report->drop( 'options', __( 'options was not an object', 'kdna-charts' ) );
			}
			return array();
		}

		$spec = KDNA_Charts_Schema::options_spec( $type );
		$out  = array();

		foreach ( $value as $key => $option ) {
			$key = (string) $key;
			if ( ! isset( $spec[ $key ] ) ) {
				$report->discard(
					'options.' . $key,
					sprintf(
						/* translators: %s: chart type label, lower case */
						__( 'not an option a %s chart understands', 'kdna-charts' ),
						strtolower( KDNA_Charts_Schema::type_label( $type ) )
					)
				);
				continue;
			}
			$result = self::coerce( $option, $spec[ $key ], 'options.' . $key, $report );
			if ( $result['ok'] ) {
				$out[ $key ] = $result['value'];
			}
		}

		return $out;
	}

	/*
	 * ====================================================================
	 * The coercion engine
	 * ====================================================================
	 */

	/**
	 * Coerces one value against one schema node.
	 *
	 * Never throws and never fails the import. Returns ok false when the
	 * value cannot be used at all, and it is the caller's business
	 * whether that means dropping a list entry or leaving a key out.
	 *
	 * @param mixed                     $value  Raw value.
	 * @param array                     $node   Schema node.
	 * @param string                    $path   Dotted path, for the report.
	 * @param KDNA_Charts_Import_Report $report Report to write findings into.
	 * @return array array( 'ok' => bool, 'value' => mixed )
	 */
	private static function coerce( $value, array $node, $path, KDNA_Charts_Import_Report $report ) {
		$kind = isset( $node['kind'] ) ? $node['kind'] : 'text';

		switch ( $kind ) {
			case 'int':
				return self::coerce_int( $value, $node, $path, $report );

			case 'number':
				return self::coerce_number( $value, $node, $path, $report );

			case 'bool':
				return self::coerce_bool( $value, $node, $path, $report );

			case 'text':
			case 'html':
				return self::coerce_string( $value, $node, $path, $report );

			case 'enum':
				return self::coerce_enum( $value, $node, $path, $report );

			case 'ratio':
				return self::coerce_ratio( $value, $node, $path, $report );

			case 'list':
				return self::coerce_list( $value, $node, $path, $report );

			case 'object':
				return self::coerce_object( $value, $node, $path, $report );

			case 'map':
				return self::coerce_map( $value, $node, $path, $report );

			case 'point':
				return self::coerce_point( $value, $path, $report );

			case 'point_object':
				return self::coerce_point_object( $value, $path, $report );

			case 'anchor':
				return self::coerce_anchor( $value, $path, $report );
		}

		return self::fail();
	}

	private static function ok( $value ) {
		return array( 'ok' => true, 'value' => $value );
	}

	private static function fail() {
		return array( 'ok' => false, 'value' => null );
	}

	private static function coerce_int( $value, array $node, $path, $report ) {
		$number = self::coerce_number( $value, $node, $path, $report );
		if ( ! $number['ok'] ) {
			return self::fail();
		}
		$out = (int) round( $number['value'] );
		if ( isset( $node['min'] ) && $out < $node['min'] ) {
			$report->repair( $path, self::clamped_note( $path, $node ) );
			$out = (int) $node['min'];
		}
		if ( isset( $node['max'] ) && $out > $node['max'] ) {
			$report->repair( $path, self::clamped_note( $path, $node ) );
			$out = (int) $node['max'];
		}
		return self::ok( $out );
	}

	/**
	 * Numbers keep the form they arrived in. An integer stays an integer
	 * so a chart exported after import reads the same as the file that
	 * came in.
	 */
	private static function coerce_number( $value, array $node, $path, $report ) {
		if ( is_bool( $value ) || is_array( $value ) || null === $value ) {
			return self::fail();
		}

		if ( is_string( $value ) ) {
			$trimmed = trim( $value );
			// A trailing per cent sign or unit is a common way to write a
			// figure, and the number in front of it is still the number.
			$stripped = rtrim( $trimmed, '% ' );
			if ( ! is_numeric( $stripped ) ) {
				return self::fail();
			}
			$report->repair( $path, __( 'a number written as text was read as a number', 'kdna-charts' ) );
			$value = $stripped + 0;
		}

		if ( ! is_int( $value ) && ! is_float( $value ) ) {
			return self::fail();
		}
		if ( is_float( $value ) && ! is_finite( $value ) ) {
			return self::fail();
		}

		if ( isset( $node['min'] ) && $value < $node['min'] ) {
			$report->repair( $path, self::clamped_note( $path, $node ) );
			$value = $node['min'];
		}
		if ( isset( $node['max'] ) && $value > $node['max'] ) {
			$report->repair( $path, self::clamped_note( $path, $node ) );
			$value = $node['max'];
		}

		return self::ok( $value );
	}

	private static function clamped_note( $path, array $node ) {
		$min = isset( $node['min'] ) ? $node['min'] : '';
		$max = isset( $node['max'] ) ? $node['max'] : '';
		return sprintf(
			/* translators: 1: schema key path, 2: lowest allowed value, 3: highest allowed value */
			__( '%1$s was outside the range the schema allows and was brought back to between %2$s and %3$s', 'kdna-charts' ),
			KDNA_Charts_Import_Report::generalise_path( $path ),
			'' === $min ? '?' : (string) $min,
			'' === $max ? '?' : (string) $max
		);
	}

	private static function coerce_bool( $value, array $node, $path, $report ) {
		if ( is_bool( $value ) ) {
			return self::ok( $value );
		}
		if ( is_int( $value ) && in_array( $value, array( 0, 1 ), true ) ) {
			return self::ok( 1 === $value );
		}
		if ( is_string( $value ) ) {
			$normal = strtolower( trim( $value ) );
			if ( in_array( $normal, array( 'true', 'yes', '1', 'on' ), true ) ) {
				$report->repair( $path, __( 'a true or false value written as text was read as true or false', 'kdna-charts' ) );
				return self::ok( true );
			}
			if ( in_array( $normal, array( 'false', 'no', '0', 'off', '' ), true ) ) {
				$report->repair( $path, __( 'a true or false value written as text was read as true or false', 'kdna-charts' ) );
				return self::ok( false );
			}
		}
		return self::fail();
	}

	private static function coerce_string( $value, array $node, $path, $report ) {
		if ( is_array( $value ) || is_object( $value ) ) {
			return self::fail();
		}
		if ( null === $value ) {
			return self::fail();
		}
		if ( is_bool( $value ) ) {
			return self::fail();
		}
		return self::ok( trim( (string) $value ) );
	}

	private static function coerce_enum( $value, array $node, $path, $report ) {
		$values      = isset( $node['values'] ) ? $node['values'] : array();
		$allow_empty = ! empty( $node['allow_empty'] );

		$normal = is_scalar( $value ) ? strtolower( trim( (string) $value ) ) : '';

		// The plugin writes UK English, and takes American in.
		if ( 'center' === $normal ) {
			$normal = 'centre';
		}
		if ( in_array( $normal, array( 'chart.js', 'chart_js' ), true ) ) {
			$normal = 'chartjs';
		}
		if ( 'doughnut' === $normal ) {
			$normal = 'donut';
		}

		if ( '' === $normal && $allow_empty ) {
			return self::ok( '' );
		}
		if ( in_array( $normal, $values, true ) ) {
			return self::ok( $normal );
		}

		if ( array_key_exists( 'default', $node ) ) {
			$report->repair(
				$path,
				sprintf(
					/* translators: 1: schema key path, 2: the list of allowed values, 3: the value used instead */
					__( '%1$s was not one of %2$s, so %3$s was used instead', 'kdna-charts' ),
					KDNA_Charts_Import_Report::generalise_path( $path ),
					implode( ', ', $values ),
					'' === $node['default'] ? __( 'the site default', 'kdna-charts' ) : (string) $node['default']
				)
			);
			return self::ok( $node['default'] );
		}

		$report->drop(
			$path,
			sprintf(
				/* translators: 1: schema key path, 2: the list of allowed values */
				__( '%1$s was not one of %2$s', 'kdna-charts' ),
				KDNA_Charts_Import_Report::generalise_path( $path ),
				implode( ', ', $values )
			)
		);
		return self::fail();
	}

	private static function coerce_ratio( $value, array $node, $path, $report ) {
		$default = isset( $node['default'] ) ? $node['default'] : KDNA_Charts_Schema::DEFAULT_ASPECT_RATIO;

		if ( is_string( $value ) && preg_match( '/^\s*(\d{1,3})\s*[:\/]\s*(\d{1,3})\s*$/', $value, $matches ) ) {
			$width  = (int) $matches[1];
			$height = (int) $matches[2];
			if ( $width > 0 && $height > 0 ) {
				$normalised = $width . ':' . $height;
				if ( $normalised !== trim( $value ) ) {
					$report->repair( $path, __( 'an aspect ratio was tidied into the width:height form', 'kdna-charts' ) );
				}
				return self::ok( $normalised );
			}
		}

		$report->repair(
			$path,
			sprintf(
				/* translators: 1: schema key path, 2: the fallback ratio, for example 16:9 */
				__( '%1$s was not a width:height ratio, so %2$s was used instead', 'kdna-charts' ),
				KDNA_Charts_Import_Report::generalise_path( $path ),
				$default
			)
		);
		return self::ok( $default );
	}

	private static function coerce_list( $value, array $node, $path, KDNA_Charts_Import_Report $report ) {
		if ( ! is_array( $value ) ) {
			if ( null !== $value ) {
				$report->drop(
					$path,
					sprintf(
						/* translators: %s: schema key path */
						__( '%s was not a list', 'kdna-charts' ),
						KDNA_Charts_Import_Report::generalise_path( $path )
					)
				);
			}
			return self::fail();
		}

		$item_node = isset( $node['of'] ) ? $node['of'] : array( 'kind' => 'text' );
		$out       = array();
		$index     = 0;

		foreach ( $value as $item ) {
			$item_path = $path . '[' . $index . ']';
			$index++;
			$result = self::coerce( $item, $item_node, $item_path, $report );
			if ( $result['ok'] ) {
				$out[] = $result['value'];
			}
		}

		return self::ok( $out );
	}

	private static function coerce_object( $value, array $node, $path, KDNA_Charts_Import_Report $report ) {
		if ( ! is_array( $value ) ) {
			if ( null !== $value ) {
				$report->drop(
					$path,
					sprintf(
						/* translators: %s: schema key path */
						__( '%s was not an object', 'kdna-charts' ),
						KDNA_Charts_Import_Report::generalise_path( $path )
					)
				);
			}
			return self::fail();
		}

		$keys = isset( $node['keys'] ) ? $node['keys'] : array();
		$out  = array();

		/*
		 * A series given a bare point list rather than segments is a
		 * perfectly clear thing to have meant, so it gets wrapped rather
		 * than refused. This is the one structural repair the importer
		 * makes, and it is here because writing it out by hand is the
		 * commonest way to get a simple line chart slightly wrong.
		 *
		 * It runs before the unknown key sweep so the points it consumes
		 * are not also reported as a key nobody recognised.
		 */
		$consumed = array();
		if ( isset( $keys['segments'] ) && empty( $value['segments'] ) && ! empty( $value['points'] ) && is_array( $value['points'] ) ) {
			$wrapped = self::coerce_list(
				$value['points'],
				array( 'kind' => 'list', 'of' => array( 'kind' => 'point' ) ),
				$path . '.points',
				$report
			);
			if ( $wrapped['ok'] && ! empty( $wrapped['value'] ) ) {
				$out['segments'] = array(
					array(
						'style'    => 'solid',
						'emphasis' => 'strong',
						'points'   => $wrapped['value'],
					),
				);
				$consumed[] = 'points';
				$report->repair( $path . '.points', __( 'a series given a bare point list was wrapped into a single solid segment', 'kdna-charts' ) );
			}
		}

		// Keys that were present but whose values could not be used. They
		// are already reported by whichever coercion refused them, so the
		// required check below must not report them a second time as
		// missing.
		$rejected = array();

		foreach ( $value as $key => $item ) {
			$key       = (string) $key;
			$item_path = '' === $path ? $key : $path . '.' . $key;

			if ( in_array( $key, $consumed, true ) ) {
				continue;
			}

			if ( ! isset( $keys[ $key ] ) ) {
				$report->discard( $item_path );
				continue;
			}

			$result = self::coerce( $item, $keys[ $key ], $item_path, $report );
			if ( $result['ok'] ) {
				$out[ $key ] = $result['value'];
				continue;
			}
			$rejected[] = $key;
		}

		// A required child missing means the entry cannot be used. The
		// caller decides what that costs: a list drops the entry, a
		// declared key is simply left out.
		foreach ( $keys as $key => $child ) {
			if ( empty( $child['required'] ) ) {
				continue;
			}
			if ( array_key_exists( $key, $out ) ) {
				continue;
			}
			if ( ! in_array( $key, $rejected, true ) ) {
				$report->drop(
					'' === $path ? $key : $path,
					sprintf(
						/* translators: 1: schema key path, 2: the missing key */
						__( '%1$s is missing its required %2$s', 'kdna-charts' ),
						KDNA_Charts_Import_Report::generalise_path( '' === $path ? $key : $path ),
						$key
					)
				);
			}
			return self::fail();
		}

		// A marker needs the coordinate its orientation runs against.
		if ( isset( $keys['type'] ) && isset( $out['type'] ) && in_array( $out['type'], KDNA_Charts_Schema::MARKER_TYPES, true ) ) {
			$needed = ( 'vertical' === $out['type'] ) ? 'x' : 'y';
			if ( ! array_key_exists( $needed, $out ) ) {
				$report->drop(
					$path,
					sprintf(
						/* translators: 1: marker orientation, vertical or horizontal, 2: the coordinate key it needs */
						__( 'a %1$s marker has no %2$s to sit at', 'kdna-charts' ),
						$out['type'],
						$needed
					)
				);
				return self::fail();
			}
		}

		return self::ok( $out );
	}

	/**
	 * A free form map of scalars. Style overrides land here, because the
	 * style schema is Stage 9's business and this stage should neither
	 * invent it nor throw away a chart's overrides for not matching one
	 * that does not exist yet.
	 */
	private static function coerce_map( $value, array $node, $path, KDNA_Charts_Import_Report $report ) {
		if ( ! is_array( $value ) ) {
			if ( null !== $value ) {
				$report->drop(
					$path,
					sprintf(
						/* translators: %s: schema key path */
						__( '%s was not an object', 'kdna-charts' ),
						KDNA_Charts_Import_Report::generalise_path( $path )
					)
				);
			}
			return self::fail();
		}

		$out = array();
		foreach ( $value as $key => $item ) {
			$key       = sanitize_key( (string) $key );
			$item_path = $path . '.' . $key;
			if ( '' === $key ) {
				continue;
			}
			if ( is_scalar( $item ) ) {
				$out[ $key ] = is_string( $item ) ? trim( $item ) : $item;
				continue;
			}
			// One level of nesting, for responsive values keyed by device.
			if ( is_array( $item ) ) {
				$nested = array();
				foreach ( $item as $sub_key => $sub_value ) {
					$sub_key = sanitize_key( (string) $sub_key );
					if ( '' !== $sub_key && is_scalar( $sub_value ) ) {
						$nested[ $sub_key ] = is_string( $sub_value ) ? trim( $sub_value ) : $sub_value;
					}
				}
				if ( ! empty( $nested ) ) {
					$out[ $key ] = $nested;
					continue;
				}
			}
			$report->drop( $item_path, __( 'a style override was not a value the style engine can hold', 'kdna-charts' ) );
		}

		return self::ok( $out );
	}

	/**
	 * A plotted point, [x, y].
	 *
	 * A null y is kept, and means a deliberate gap in the line. An x has
	 * to be a real number, because a point with no position on the x
	 * axis has nowhere to go.
	 */
	private static function coerce_point( $value, $path, KDNA_Charts_Import_Report $report ) {
		// The {x, y} object form, which is how a person writes a point
		// when they are thinking about the annotation layer.
		if ( is_array( $value ) && ( isset( $value['x'] ) || isset( $value['y'] ) ) ) {
			if ( is_string( $value['x'] ?? null ) || is_string( $value['y'] ?? null ) ) {
				$report->repair( $path, __( 'a coordinate written as text was read as a number', 'kdna-charts' ) );
			}
			$x = self::plain_number( $value['x'] ?? null );
			$y = array_key_exists( 'y', $value ) && null === $value['y'] ? null : self::plain_number( $value['y'] ?? null );
			if ( null === $x ) {
				$report->drop( $path, __( 'a point had no usable x value', 'kdna-charts' ) );
				return self::fail();
			}
			$report->repair( $path, __( 'a point written as {x, y} was read as an [x, y] pair', 'kdna-charts' ) );
			return self::ok( array( $x, $y ) );
		}

		if ( ! is_array( $value ) ) {
			$report->drop( $path, __( 'a point was not an [x, y] pair', 'kdna-charts' ) );
			return self::fail();
		}

		$pair = array_values( $value );

		if ( count( $pair ) < 2 ) {
			$report->drop( $path, __( 'a point had fewer than two values', 'kdna-charts' ) );
			return self::fail();
		}

		if ( count( $pair ) > 2 ) {
			$report->repair( $path, __( 'a point with more than two values was cut back to [x, y]', 'kdna-charts' ) );
		}

		if ( is_string( $pair[0] ) || is_string( $pair[1] ) ) {
			$report->repair( $path, __( 'a coordinate written as text was read as a number', 'kdna-charts' ) );
		}

		$x = self::plain_number( $pair[0] );
		$y = ( null === $pair[1] ) ? null : self::plain_number( $pair[1] );

		if ( null === $x ) {
			$report->drop( $path, __( 'a point had no usable x value', 'kdna-charts' ) );
			return self::fail();
		}
		if ( null === $y && null !== $pair[1] ) {
			$report->drop( $path, __( 'a point had no usable y value', 'kdna-charts' ) );
			return self::fail();
		}

		return self::ok( array( $x, $y ) );
	}

	/**
	 * A point written as an object, {x, y}. Used by note.at.
	 */
	private static function coerce_point_object( $value, $path, KDNA_Charts_Import_Report $report ) {
		if ( is_array( $value ) && ! isset( $value['x'] ) && ! isset( $value['y'] ) ) {
			$pair = array_values( $value );
			if ( count( $pair ) >= 2 ) {
				$x = self::plain_number( $pair[0] );
				$y = self::plain_number( $pair[1] );
				if ( null !== $x && null !== $y ) {
					$report->repair( $path, __( 'a position written as [x, y] was read as {x, y}', 'kdna-charts' ) );
					return self::ok( array( 'x' => $x, 'y' => $y ) );
				}
			}
			$report->drop( $path, __( 'a position was not an {x, y} pair', 'kdna-charts' ) );
			return self::fail();
		}

		if ( ! is_array( $value ) ) {
			$report->drop( $path, __( 'a position was not an {x, y} pair', 'kdna-charts' ) );
			return self::fail();
		}

		if ( is_string( $value['x'] ?? null ) || is_string( $value['y'] ?? null ) ) {
			$report->repair( $path, __( 'a coordinate written as text was read as a number', 'kdna-charts' ) );
		}

		$x = self::plain_number( $value['x'] ?? null );
		$y = self::plain_number( $value['y'] ?? null );
		if ( null === $x || null === $y ) {
			$report->drop( $path, __( 'a position was missing its x or its y', 'kdna-charts' ) );
			return self::fail();
		}

		return self::ok( array( 'x' => $x, 'y' => $y ) );
	}

	/**
	 * A callout anchor. Either a single {x, y}, or a {from, to} span
	 * that brackets the range the number describes.
	 */
	private static function coerce_anchor( $value, $path, KDNA_Charts_Import_Report $report ) {
		if ( ! is_array( $value ) ) {
			$report->drop( $path, __( 'a callout anchor was not a point or a span', 'kdna-charts' ) );
			return self::fail();
		}

		if ( isset( $value['from'] ) || isset( $value['to'] ) ) {
			$from = self::coerce_point_object( $value['from'] ?? null, $path . '.from', $report );
			$to   = self::coerce_point_object( $value['to'] ?? null, $path . '.to', $report );
			if ( ! $from['ok'] || ! $to['ok'] ) {
				$report->drop( $path, __( 'a callout span was missing one of its ends', 'kdna-charts' ) );
				return self::fail();
			}
			return self::ok(
				array(
					'from' => $from['value'],
					'to'   => $to['value'],
				)
			);
		}

		return self::coerce_point_object( $value, $path, $report );
	}

	/**
	 * A finite number, or null. No reporting, for use inside the point
	 * handlers which report in their own words.
	 */
	private static function plain_number( $value ) {
		if ( is_bool( $value ) || is_array( $value ) || null === $value ) {
			return null;
		}
		if ( is_string( $value ) ) {
			$value = trim( rtrim( trim( $value ), '% ' ) );
			if ( ! is_numeric( $value ) ) {
				return null;
			}
			$value = $value + 0;
		}
		if ( ! is_int( $value ) && ! is_float( $value ) ) {
			return null;
		}
		if ( is_float( $value ) && ! is_finite( $value ) ) {
			return null;
		}
		return $value;
	}

	/*
	 * ====================================================================
	 * Creating the chart
	 * ====================================================================
	 */

	/**
	 * Creates a chart post from a validated definition.
	 *
	 * @param array                     $definition Validated definition.
	 * @param KDNA_Charts_Import_Report $report     Report to add findings to.
	 * @return int|WP_Error New post ID.
	 */
	public static function create_chart( array $definition, KDNA_Charts_Import_Report $report ) {
		/*
		 * Style overrides change how charts look site wide once a preset
		 * is saved from them, so they follow the same rule as the style
		 * admin: an editor can import a chart, an administrator can
		 * import its styling.
		 */
		if ( ! empty( $definition['style'] ) && ! current_user_can( 'manage_options' ) ) {
			$count = count( $definition['style'] );
			$definition['style'] = array();
			$report->ignore(
				sprintf(
					/* translators: %d: number of style overrides */
					_n(
						'%d style override in the file was not applied. Per chart styling needs the manage options capability.',
						'%d style overrides in the file were not applied. Per chart styling needs the manage options capability.',
						$count,
						'kdna-charts'
					),
					$count
				)
			);
		}

		$existing = KDNA_Charts_CPT::find_by_hash( KDNA_Charts_CPT::content_hash( $definition ) );
		if ( $existing ) {
			$report->ignore(
				sprintf(
					/* translators: 1: existing chart title, 2: edit link URL */
					__( 'This is the same chart as %1$s, which is already in the library. A second copy has been created anyway, so you can compare them and delete one. <a href="%2$s">Open the original</a>.', 'kdna-charts' ),
					esc_html( get_the_title( $existing ) ),
					esc_url( (string) get_edit_post_link( $existing, 'url' ) )
				)
			);
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => KDNA_Charts_CPT::POST_TYPE,
				'post_status' => 'draft',
				'post_title'  => $definition['title'],
				'post_author' => get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		KDNA_Charts_CPT::save_definition( $post_id, $definition );

		return (int) $post_id;
	}

	/*
	 * ====================================================================
	 * The Import screen
	 * ====================================================================
	 */

	public static function render_page() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to import charts.', 'kdna-charts' ) );
		}

		$import_action_url = admin_url( 'admin-post.php' );
		$action_name       = self::ACTION_IMPORT;
		$nonce_action      = self::NONCE_IMPORT;
		$file_field        = self::FILE_FIELD;
		$paste_field       = self::PASTE_FIELD;
		$max_bytes         = self::MAX_BYTES;
		$schema_reference  = KDNA_Charts_Schema::reference_text();
		$authoring_prompt  = KDNA_Charts_Schema::authoring_prompt();
		$error             = self::get_flash_error();

		$template = KDNA_CHARTS_PATH . 'templates/admin-editor-import.php';
		if ( ! file_exists( $template ) ) {
			return;
		}
		include $template;
	}

	/**
	 * Handles both forms. They differ only in where the text comes from,
	 * so everything after that is one path.
	 */
	public static function handle_import() {
		check_admin_referer( self::NONCE_IMPORT );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to import charts.', 'kdna-charts' ) );
		}

		$report = new KDNA_Charts_Import_Report();
		$raw    = self::read_submitted_text();

		if ( is_wp_error( $raw ) ) {
			self::fail_back( $raw );
		}

		$decoded = self::decode( $raw, $report );
		if ( is_wp_error( $decoded ) ) {
			self::fail_back( $decoded );
		}

		$validated = self::validate( $decoded, $report );
		if ( is_wp_error( $validated ) ) {
			self::fail_back( $validated );
		}

		$post_id = self::create_chart( $validated['definition'], $validated['report'] );
		if ( is_wp_error( $post_id ) ) {
			self::fail_back( $post_id );
		}

		set_transient(
			self::REPORT_TRANSIENT . $post_id,
			$validated['report']->to_array(),
			self::REPORT_TTL
		);

		wp_safe_redirect( get_edit_post_link( $post_id, 'redirect' ) );
		exit;
	}

	/**
	 * Returns the submitted JSON text, from whichever of the two forms
	 * was used, or a WP_Error explaining what went wrong with the upload.
	 *
	 * The file is read straight from its temporary path and never moved
	 * into the uploads directory. A chart definition is data the plugin
	 * consumes on the spot, not a media item, and leaving it out of the
	 * library means no publicly reachable copy of it exists.
	 *
	 * @return string|WP_Error
	 */
	private static function read_submitted_text() {
		$has_upload = isset( $_FILES[ self::FILE_FIELD ] )
			&& is_array( $_FILES[ self::FILE_FIELD ] )
			&& UPLOAD_ERR_NO_FILE !== (int) ( $_FILES[ self::FILE_FIELD ]['error'] ?? UPLOAD_ERR_NO_FILE );

		if ( $has_upload ) {
			return self::read_uploaded_file( $_FILES[ self::FILE_FIELD ] );
		}

		$pasted = isset( $_POST[ self::PASTE_FIELD ] ) ? wp_unslash( $_POST[ self::PASTE_FIELD ] ) : '';
		if ( ! is_string( $pasted ) || '' === trim( $pasted ) ) {
			return new WP_Error(
				'kdna_charts_no_content',
				__( 'There was nothing to import. Choose a file, or paste a chart definition into the box.', 'kdna-charts' )
			);
		}

		return $pasted;
	}

	private static function read_uploaded_file( array $file ) {
		$error = (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE );

		if ( UPLOAD_ERR_OK !== $error ) {
			$messages = array(
				UPLOAD_ERR_INI_SIZE   => __( 'The file is larger than this server allows for uploads.', 'kdna-charts' ),
				UPLOAD_ERR_FORM_SIZE  => __( 'The file is larger than the form allows.', 'kdna-charts' ),
				UPLOAD_ERR_PARTIAL    => __( 'The file only uploaded partway. Try again.', 'kdna-charts' ),
				UPLOAD_ERR_NO_TMP_DIR => __( 'This server has no temporary folder to receive uploads.', 'kdna-charts' ),
				UPLOAD_ERR_CANT_WRITE => __( 'This server could not write the uploaded file to disk.', 'kdna-charts' ),
				UPLOAD_ERR_EXTENSION  => __( 'A PHP extension stopped the upload.', 'kdna-charts' ),
			);
			return new WP_Error(
				'kdna_charts_upload_failed',
				isset( $messages[ $error ] ) ? $messages[ $error ] : __( 'The upload did not complete.', 'kdna-charts' )
			);
		}

		$tmp_name = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
		if ( '' === $tmp_name || ! is_uploaded_file( $tmp_name ) ) {
			return new WP_Error(
				'kdna_charts_upload_failed',
				__( 'That upload could not be read.', 'kdna-charts' )
			);
		}

		$name      = isset( $file['name'] ) ? sanitize_file_name( (string) $file['name'] ) : '';
		$extension = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( ! in_array( $extension, array( 'json', 'txt' ), true ) ) {
			return new WP_Error(
				'kdna_charts_wrong_file_type',
				sprintf(
					/* translators: %s: the file extension the user uploaded */
					__( 'A chart definition is a .json file. That one is a .%s file.', 'kdna-charts' ),
					'' === $extension ? __( 'no extension', 'kdna-charts' ) : $extension
				)
			);
		}

		$size = isset( $file['size'] ) ? (int) $file['size'] : 0;
		if ( $size > self::MAX_BYTES ) {
			return new WP_Error(
				'kdna_charts_too_large',
				sprintf(
					/* translators: %s: size limit, already formatted */
					__( 'That file is larger than %s. A chart definition is normally a few kilobytes, so this is almost certainly not one.', 'kdna-charts' ),
					size_format( self::MAX_BYTES )
				)
			);
		}

		$contents = file_get_contents( $tmp_name );
		if ( false === $contents ) {
			return new WP_Error(
				'kdna_charts_upload_failed',
				__( 'That upload could not be read.', 'kdna-charts' )
			);
		}

		return $contents;
	}

	/*
	 * ====================================================================
	 * Telling the user what happened
	 * ====================================================================
	 */

	/**
	 * Sends the user back to the Import screen with the reason showing.
	 * A failed import returns to the form, never to a white page.
	 */
	private static function fail_back( WP_Error $error ) {
		set_transient(
			self::flash_key(),
			array(
				'code'    => $error->get_error_code(),
				'message' => $error->get_error_message(),
			),
			60
		);
		wp_safe_redirect( self::get_import_url() );
		exit;
	}

	private static function flash_key() {
		return 'kdna_charts_import_error_' . get_current_user_id();
	}

	private static function get_flash_error() {
		$flash = get_transient( self::flash_key() );
		if ( ! is_array( $flash ) ) {
			return null;
		}
		delete_transient( self::flash_key() );
		return $flash;
	}

	/**
	 * Prints the import summary on the chart edit screen the importer
	 * redirected to, then clears it, so a refresh does not repeat it.
	 */
	public static function render_import_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->base || KDNA_Charts_CPT::POST_TYPE !== $screen->post_type ) {
			return;
		}

		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		if ( ! $post_id ) {
			return;
		}

		$stored = get_transient( self::REPORT_TRANSIENT . $post_id );
		if ( ! is_array( $stored ) ) {
			return;
		}
		delete_transient( self::REPORT_TRANSIENT . $post_id );

		$report     = KDNA_Charts_Import_Report::from_array( $stored );
		$definition = KDNA_Charts_CPT::get_definition( $post_id );
		$lines      = $report->lines( $definition );
		$clean      = $report->is_clean();

		$headline = sprintf(
			/* translators: 1: chart title, 2: chart type label, lower case, 3: number of data points, 4: number of annotations */
			__( 'Imported %1$s as a %2$s chart, with %3$d data points and %4$d annotations.', 'kdna-charts' ),
			'<strong>' . esc_html( (string) ( $definition['title'] ?? '' ) ) . '</strong>',
			esc_html( strtolower( KDNA_Charts_Schema::type_label( $definition['type'] ?? '' ) ) ),
			KDNA_Charts_Data::count_points( $definition ),
			KDNA_Charts_Data::count_annotations( $definition )
		);
		?>
		<div class="notice <?php echo $clean ? 'notice-success' : 'notice-warning'; ?> kdna-import-summary">
			<p><?php echo wp_kses_post( $headline ); ?></p>
			<?php if ( ! empty( $lines ) ) : ?>
				<ul class="kdna-import-summary__list">
					<?php foreach ( $lines as $line ) : ?>
						<li><?php echo wp_kses_post( $line ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<p class="description">
				<?php esc_html_e( 'The chart has been saved as a draft. Nothing draws yet, the SVG renderer arrives at Stage 4.', 'kdna-charts' ); ?>
			</p>
		</div>
		<?php
	}
}
