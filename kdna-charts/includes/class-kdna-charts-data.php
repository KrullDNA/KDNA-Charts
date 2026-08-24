<?php
/**
 * The read layer between the kdna_chart post type and everything that
 * draws a chart. The renderers, the shortcode and the Elementor widget
 * all come through here, so none of them needs to know how a definition
 * is stored, and none of them can accidentally render an unpublished or
 * missing chart.
 *
 * @package KDNA_Charts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KDNA_Charts_Data {

	/**
	 * Global default rendering engine, set in Settings > KDNA Charts and
	 * overridable per chart. The settings page itself arrives at Stage 9,
	 * the option is read from Stage 1 so engine resolution is in one place
	 * from the start.
	 */
	const OPTION_DEFAULT_ENGINE = 'kdna_charts_default_engine';

	/**
	 * Returns the chart definition for rendering, or an empty array when
	 * the chart cannot be rendered.
	 *
	 * Unpublished charts are readable in the admin, where a preview has to
	 * work on a draft, and refused on the front end, where a draft chart
	 * appearing in an article would be a leak.
	 *
	 * @param int  $chart_id      kdna_chart post ID.
	 * @param bool $allow_drafts  True in admin preview contexts.
	 * @return array
	 */
	public static function get_definition_for_render( $chart_id, $allow_drafts = false ) {
		$chart_id = (int) $chart_id;
		if ( $chart_id <= 0 ) {
			return array();
		}

		$post = get_post( $chart_id );
		if ( ! $post || KDNA_Charts_CPT::POST_TYPE !== $post->post_type ) {
			return array();
		}

		$allowed_statuses = $allow_drafts
			? array( 'publish', 'draft', 'private', 'pending' )
			: array( 'publish' );
		if ( ! in_array( $post->post_status, $allowed_statuses, true ) ) {
			return array();
		}

		$definition = KDNA_Charts_CPT::get_definition( $chart_id );
		if ( empty( $definition['type'] ) ) {
			return array();
		}

		// Resolve the engine here so nothing downstream has to think about
		// the global default.
		$definition['engine'] = self::resolve_engine( $definition['engine'] );

		return $definition;
	}

	/**
	 * True when the post id points at a chart that exists.
	 */
	public static function chart_exists( $chart_id ) {
		$chart_id = (int) $chart_id;
		return $chart_id > 0 && KDNA_Charts_CPT::POST_TYPE === get_post_type( $chart_id );
	}

	/**
	 * Resolves a per chart engine value against the global default.
	 * An empty per chart value means inherit.
	 *
	 * @param string $chart_engine Per chart engine, may be ''.
	 * @return string 'svg' or 'chartjs'.
	 */
	public static function resolve_engine( $chart_engine ) {
		$chart_engine = KDNA_Charts_CPT::sanitize_engine( $chart_engine );
		if ( '' !== $chart_engine ) {
			return $chart_engine;
		}
		return self::default_engine();
	}

	/**
	 * The site wide default engine. SVG unless the setting says otherwise,
	 * because SVG is the complete engine and the one that needs no
	 * JavaScript.
	 */
	public static function default_engine() {
		$stored = KDNA_Charts_CPT::sanitize_engine( get_option( self::OPTION_DEFAULT_ENGINE, 'svg' ) );
		return '' === $stored ? 'svg' : $stored;
	}

	/**
	 * Every chart in the library, as id => title, for the widget selector,
	 * the shortcode helper and the admin dropdowns.
	 *
	 * @param bool $published_only Restrict to published charts.
	 * @return array<int,string>
	 */
	public static function get_library( $published_only = false ) {
		$posts = get_posts(
			array(
				'post_type'        => KDNA_Charts_CPT::POST_TYPE,
				'post_status'      => $published_only ? array( 'publish' ) : array( 'publish', 'draft', 'private' ),
				'numberposts'      => -1,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => true,
			)
		);

		$library = array();
		foreach ( $posts as $post ) {
			$title = '' !== trim( $post->post_title )
				? $post->post_title
				: sprintf(
					/* translators: %d: chart post ID */
					__( 'Untitled chart %d', 'kdna-charts' ),
					(int) $post->ID
				);
			$library[ (int) $post->ID ] = $title;
		}
		return $library;
	}

	/**
	 * Counts the plotted data points in a definition, across every series
	 * and every segment. Used by the admin list table, and later by the
	 * engine advice that suggests Chart.js past a couple of hundred points.
	 */
	public static function count_points( array $definition ) {
		$total  = 0;
		$series = isset( $definition['series'] ) && is_array( $definition['series'] ) ? $definition['series'] : array();

		foreach ( $series as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			// Pie, donut and stat carry a flat data list.
			if ( ! empty( $entry['data'] ) && is_array( $entry['data'] ) ) {
				$total += count( $entry['data'] );
			}
			// Plotted types carry segments, each with its own point list.
			if ( ! empty( $entry['segments'] ) && is_array( $entry['segments'] ) ) {
				foreach ( $entry['segments'] as $segment ) {
					if ( is_array( $segment ) && ! empty( $segment['points'] ) && is_array( $segment['points'] ) ) {
						$total += count( $segment['points'] );
					}
				}
			}
			// A series may also carry a bare point list.
			if ( ! empty( $entry['points'] ) && is_array( $entry['points'] ) ) {
				$total += count( $entry['points'] );
			}
		}

		return $total;
	}

	/**
	 * Counts the annotations on a definition, the markers, emphasised
	 * points, callouts and notes taken together. This is the number that
	 * says how editorial a given chart is, so it earns a column in the
	 * library list.
	 */
	public static function count_annotations( array $definition ) {
		$total = 0;
		foreach ( array( 'markers', 'points', 'callouts', 'notes' ) as $key ) {
			if ( ! empty( $definition[ $key ] ) && is_array( $definition[ $key ] ) ) {
				$total += count( $definition[ $key ] );
			}
		}
		return $total;
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
	 * Human readable label for an engine.
	 */
	public static function engine_label( $engine ) {
		return 'chartjs' === $engine
			? __( 'Chart.js', 'kdna-charts' )
			: __( 'SVG', 'kdna-charts' );
	}
}
