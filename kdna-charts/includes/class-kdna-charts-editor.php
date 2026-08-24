<?php
/**
 * The chart edit screen.
 *
 * ── Temporary, and deliberately so ─────────────────────────────────
 *
 * At Stage 4 this is one preview meta box and nothing else. There is
 * no way to edit a chart's data from the admin yet: charts arrive
 * through the importer, and the Alpine.js data editor lands at Stage 8
 * and replaces everything in this file.
 *
 * It exists now because a renderer nobody can look at is a renderer
 * nobody can check. The preview turns "the maths says the line ends at
 * 438.67" into "the line ends where it should".
 *
 * @package KDNA_Charts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KDNA_Charts_Editor {

	const STYLE_HANDLE_FRONTEND = 'kdna-charts';

	public static function init() {
		add_action( 'add_meta_boxes_' . KDNA_Charts_CPT::POST_TYPE, array( __CLASS__, 'register_meta_boxes' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_preview_styles' ) );
	}

	public static function register_meta_boxes() {
		add_meta_box(
			'kdna_charts_preview',
			__( 'Preview', 'kdna-charts' ),
			array( __CLASS__, 'render_preview_meta_box' ),
			KDNA_Charts_CPT::POST_TYPE,
			'normal',
			'high'
		);

		add_meta_box(
			'kdna_charts_shortcode',
			__( 'Shortcode', 'kdna-charts' ),
			array( __CLASS__, 'render_shortcode_meta_box' ),
			KDNA_Charts_CPT::POST_TYPE,
			'side',
			'default'
		);
	}

	/**
	 * The chart, rendered exactly as the front end will render it.
	 *
	 * Drafts are allowed here, and only here. A chart is a draft until
	 * somebody has looked at it, so a preview that refused to draw one
	 * would refuse to draw the only charts anybody needs to preview.
	 */
	public static function render_preview_meta_box( $post ) {
		$definition = KDNA_Charts_Data::get_definition_for_render( $post->ID, true );

		if ( empty( $definition ) ) {
			printf(
				'<p class="description">%s</p>',
				esc_html__( 'Nothing to preview yet. Import a chart definition, or pick a type from Add New.', 'kdna-charts' )
			);
			return;
		}

		$renderer = KDNA_Charts_Renderer::create(
			$definition,
			array(
				'chart_id' => (int) $post->ID,
				'classes'  => array( 'kdna-chart--preview' ),
			)
		);

		echo '<div class="kdna-preview">';
		echo '<div class="kdna-preview__stage">';
		// The renderer escapes as it builds; wp_kses would strip the SVG.
		echo $renderer->render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';

		self::render_preview_footer( $post->ID, $definition, $renderer );
		echo '</div>';
	}

	/**
	 * What the preview cannot show by looking: which engine drew it,
	 * what the engine cannot do, and a way through to the geometry.
	 */
	private static function render_preview_footer( $post_id, array $definition, KDNA_Charts_Renderer $renderer ) {
		$debug_url = add_query_arg(
			array(
				'kdna_debug' => 1,
				'chart'      => (int) $post_id,
			),
			admin_url( 'admin.php' )
		);

		echo '<p class="kdna-preview__meta description">';
		printf(
			/* translators: 1: engine name, 2: number of data points, 3: number of annotations */
			esc_html__( 'Drawn by the %1$s engine. %2$d data points, %3$d annotations.', 'kdna-charts' ),
			esc_html( KDNA_Charts_Data::engine_label( $renderer->engine() ) ),
			(int) KDNA_Charts_Data::count_points( $definition ),
			(int) KDNA_Charts_Data::count_annotations( $definition )
		);
		echo ' ';

		$annotations = KDNA_Charts_Data::count_annotations( $definition );
		if ( $annotations > 0 && ! empty( $renderer->supports()['annotations'] ) ) {
			echo '<br />';
			esc_html_e( 'Annotations are stored but not drawn yet. The annotation layer arrives at Stage 5.', 'kdna-charts' );
		}

		if ( current_user_can( 'manage_options' ) ) {
			echo '<br />';
			printf(
				'<a href="%1$s">%2$s</a>',
				esc_url( $debug_url ),
				esc_html__( 'Inspect the computed geometry', 'kdna-charts' )
			);
		}
		echo '</p>';
	}

	public static function render_shortcode_meta_box( $post ) {
		$shortcode = sprintf( '[kdna_chart id="%d"]', (int) $post->ID );
		printf(
			'<p><code class="kdna-shortcode">%1$s</code> <button type="button" class="button-link kdna-copy-shortcode" data-clipboard-text="%2$s">%3$s</button></p>
			<p class="description">%4$s</p>',
			esc_html( $shortcode ),
			esc_attr( $shortcode ),
			esc_html__( 'Copy', 'kdna-charts' ),
			esc_html__( 'The shortcode is registered at Stage 10. Until then, use the preview above.', 'kdna-charts' )
		);
	}

	/**
	 * The preview has to look like the front end, so it loads the front
	 * end stylesheet rather than an admin approximation of it. Anything
	 * else and the preview would be a picture of a different chart.
	 */
	public static function enqueue_preview_styles() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || KDNA_Charts_CPT::POST_TYPE !== $screen->post_type ) {
			return;
		}

		wp_enqueue_style(
			self::STYLE_HANDLE_FRONTEND,
			KDNA_CHARTS_URL . 'assets/css/kdna-charts.css',
			array(),
			KDNA_CHARTS_VERSION
		);
	}
}
