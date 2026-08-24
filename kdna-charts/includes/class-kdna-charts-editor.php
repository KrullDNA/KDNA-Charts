<?php
/**
 * The chart edit screen.
 *
 * Replaces the WordPress post editor for a kdna_chart with a four tab
 * editor built on Alpine, and a preview panel that shows the chart as
 * the front end will actually serve it.
 *
 * ── One door in ────────────────────────────────────────────────────
 *
 * The editor's state, an imported file and a definition written by
 * hand all reach storage through the same two steps: the importer's
 * validator, then KDNA_Charts_CPT::save_definition(). Nothing here
 * writes meta directly.
 *
 * That is worth the small awkwardness of running a validator over data
 * the editor just produced. It means there is one answer to what a
 * chart may contain, one place where a bad value is repaired, and no
 * way for the editor to save something the importer would have
 * refused.
 *
 * ── Why the preview is drawn on the server ─────────────────────────
 *
 * Because the renderer is PHP, and a second renderer written in
 * JavaScript would be two answers to every question of geometry,
 * drifting apart one fix at a time. The editor posts its state and
 * gets back the same markup the front end will serve, which costs a
 * request per edit and cannot lie about the result.
 *
 * @package KDNA_Charts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KDNA_Charts_Editor {

	const NONCE_ACTION = 'kdna_charts_editor_save';
	const NONCE_FIELD  = 'kdna_charts_editor_nonce';
	const STATE_INPUT  = 'kdna_charts_editor_state';

	const AJAX_PREVIEW = 'kdna_charts_preview';
	const NONCE_AJAX   = 'kdna_charts_editor_ajax';

	const SCRIPT_ALPINE = 'kdna-charts-alpine';
	const SCRIPT_ADMIN  = 'kdna-charts-admin';
	const STYLE_ADMIN   = 'kdna-charts-admin';
	const STYLE_FRONT   = 'kdna-charts';

	/** How long a save refusal is remembered, so the notice can be shown. */
	const SEED_FAILURE_TTL = 3600;

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'remove_post_type_supports' ) );
		add_action( 'add_meta_boxes_' . KDNA_Charts_CPT::POST_TYPE, array( __CLASS__, 'register_meta_boxes' ) );
		add_action( 'edit_form_after_title', array( __CLASS__, 'render_nonce_field' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'save_post_' . KDNA_Charts_CPT::POST_TYPE, array( __CLASS__, 'save_post' ), 10, 3 );
		add_action( 'wp_ajax_' . self::AJAX_PREVIEW, array( __CLASS__, 'ajax_preview' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_seed_failure_notice' ) );
	}

	/**
	 * The post type supports a title only, so this is a safety net for
	 * any theme or plugin that adds the defaults back.
	 */
	public static function remove_post_type_supports() {
		$features = array(
			'editor',
			'thumbnail',
			'custom-fields',
			'revisions',
			'comments',
			'excerpt',
			'trackbacks',
			'page-attributes',
			'author',
		);
		foreach ( $features as $feature ) {
			remove_post_type_support( KDNA_Charts_CPT::POST_TYPE, $feature );
		}
	}

	/*
	 * ====================================================================
	 * Meta boxes
	 * ====================================================================
	 */

	public static function register_meta_boxes( $post ) {
		$type = KDNA_Charts_CPT::POST_TYPE;

		foreach ( array( 'slugdiv', 'authordiv', 'commentstatusdiv', 'commentsdiv', 'postcustom', 'revisionsdiv', 'trackbacksdiv' ) as $box ) {
			remove_meta_box( $box, $type, 'normal' );
		}

		add_meta_box(
			'kdna_charts_editor',
			__( 'Chart', 'kdna-charts' ),
			array( __CLASS__, 'render_editor' ),
			$type,
			'normal',
			'high'
		);

		add_meta_box(
			'kdna_charts_shortcode',
			__( 'Shortcode', 'kdna-charts' ),
			array( __CLASS__, 'render_shortcode_meta_box' ),
			$type,
			'side',
			'default'
		);
	}

	public static function render_nonce_field() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || KDNA_Charts_CPT::POST_TYPE !== $screen->post_type ) {
			return;
		}
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
	}

	/**
	 * The editor.
	 *
	 * Everything below the wrapper is Alpine. If its scripts do not run,
	 * blocked, reordered by an optimiser, missing behind a CDN, the
	 * markup still renders but as an empty editor that looks exactly
	 * like a brand new chart. Someone then presses Update on what looks
	 * like their chart and writes nothing over it.
	 *
	 * So the wrapper says so, out loud, and the save handler refuses a
	 * state that did not come from this post.
	 */
	public static function render_editor( $post ) {
		$definition = KDNA_Charts_CPT::get_definition( $post->ID );
		$type       = (string) ( $definition['type'] ?? '' );

		if ( '' === $type ) {
			printf(
				'<p class="description">%s</p>',
				esc_html__( 'This chart has no type. Delete it and add a new one, or import a definition file.', 'kdna-charts' )
			);
			return;
		}

		$state = array(
			'post_id' => (int) $post->ID,
			'chart'   => self::editor_state( $definition ),
		);

		?>
		<div
			class="kdna-editor"
			data-kdna-chart-editor
			x-data="kdnaChartEditor( <?php echo esc_attr( (string) wp_json_encode( $state ) ); ?> )"
			x-bind:data-dirty="dirty"
		>
			<noscript>
				<p class="notice notice-error">
					<?php esc_html_e( 'The chart editor needs JavaScript. Nothing you type below will be saved without it.', 'kdna-charts' ); ?>
				</p>
			</noscript>

			<?php
			/*
			 * x-show="false" reads oddly until you see what it is for.
			 * Before Alpine runs there is no x-show, so this warning is
			 * visible. The moment Alpine runs it hides. So the message
			 * appears exactly when the editor has failed to start, which
			 * is the only time anybody needs it, and it cannot be hidden
			 * by the same failure it is reporting.
			 */
			?>
			<p class="kdna-editor__boot notice notice-error" x-show="false">
				<?php esc_html_e( 'The chart editor did not start. Do not press Update: your chart is still stored, but this screen cannot see it, and saving now would replace it with an empty one.', 'kdna-charts' ); ?>
			</p>

			<input type="hidden" name="<?php echo esc_attr( self::STATE_INPUT ); ?>" x-ref="state" value="" />

			<div class="kdna-editor__layout" x-cloak>
				<div class="kdna-editor__main">
					<?php self::render_tabs(); ?>

					<div class="kdna-editor__panel" x-show="tab === 'data'">
						<?php self::template( 'admin-editor-data.php' ); ?>
					</div>

					<div class="kdna-editor__panel" x-show="tab === 'annotations'" x-cloak>
						<?php self::template( 'admin-editor-annotations.php' ); ?>
					</div>

					<div class="kdna-editor__panel" x-show="tab === 'options'" x-cloak>
						<?php
						/*
						 * The chart type is fixed at creation, so the set
						 * of options is known here and the tab is printed
						 * rather than assembled at run time.
						 */
						self::template(
							'admin-editor-options.php',
							array( 'options_spec' => KDNA_Charts_Schema::options_spec( $type ) )
						);
						?>
					</div>

					<div class="kdna-editor__panel" x-show="tab === 'style'" x-cloak>
						<?php self::render_style_tab( $post ); ?>
					</div>
				</div>

				<?php self::render_preview_panel(); ?>
			</div>
		</div>
		<?php
	}

	private static function render_tabs() {
		$tabs = array(
			'data'        => __( 'Data', 'kdna-charts' ),
			'annotations' => __( 'Annotations', 'kdna-charts' ),
			'options'     => __( 'Options', 'kdna-charts' ),
			'style'       => __( 'Style', 'kdna-charts' ),
		);
		?>
		<div class="kdna-editor__tabs" role="tablist">
			<?php foreach ( $tabs as $slug => $label ) : ?>
				<button
					type="button"
					class="kdna-editor__tab"
					role="tab"
					x-bind:class="tab === '<?php echo esc_attr( $slug ); ?>' && 'is-active'"
					x-bind:aria-selected="tab === '<?php echo esc_attr( $slug ); ?>'"
					x-on:click="tab = '<?php echo esc_attr( $slug ); ?>'"
				>
					<?php echo esc_html( $label ); ?>
					<?php if ( 'annotations' === $slug ) : ?>
						<span class="kdna-editor__tab-count" x-show="annotationCount > 0" x-text="annotationCount"></span>
					<?php endif; ?>
				</button>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * The preview. Sticky, because the point of it is watching the chart
	 * change as you edit, and a preview you have to scroll to is a
	 * preview you stop looking at.
	 */
	private static function render_preview_panel() {
		?>
		<aside class="kdna-editor__preview">
			<div class="kdna-editor__preview-head">
				<h3><?php esc_html_e( 'Preview', 'kdna-charts' ); ?></h3>
				<span class="kdna-editor__spinner" x-show="previewing" x-cloak aria-hidden="true"></span>
				<button type="button" class="button-link" x-on:click="refresh()">
					<?php esc_html_e( 'Refresh', 'kdna-charts' ); ?>
				</button>
			</div>

			<div class="kdna-editor__preview-error notice notice-error" x-show="previewError" x-cloak>
				<p x-text="previewError"></p>
			</div>

			<div class="kdna-editor__preview-stage" x-html="preview"></div>

			<p class="description">
				<?php esc_html_e( 'This is the chart as the front end will draw it, rendered by the same code.', 'kdna-charts' ); ?>
			</p>
		</aside>
		<?php
	}

	private static function render_style_tab( $post ) {
		$overrides = KDNA_Charts_CPT::get_json_meta( $post->ID, KDNA_Charts_CPT::META_STYLE );
		?>
		<div class="kdna-editor__section">
			<h3><?php esc_html_e( 'Per chart styling', 'kdna-charts' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'The style controls arrive at Stage 9, along with the global styling page they inherit from. Until then a chart takes the plugin defaults, and any style overrides carried in an imported file are stored untouched.', 'kdna-charts' ); ?>
			</p>
			<?php if ( ! empty( $overrides ) ) : ?>
				<p>
					<?php
					printf(
						/* translators: %d: number of stored style overrides */
						esc_html( _n( 'This chart carries %d stored style override.', 'This chart carries %d stored style overrides.', count( $overrides ), 'kdna-charts' ) ),
						count( $overrides )
					);
					?>
				</p>
				<pre class="kdna-editor__raw"><?php echo esc_html( (string) wp_json_encode( $overrides, JSON_PRETTY_PRINT ) ); ?></pre>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function render_shortcode_meta_box( $post ) {
		$shortcode = sprintf( '[kdna_chart id="%d"]', (int) $post->ID );
		printf(
			'<p><code class="kdna-shortcode">%1$s</code> <button type="button" class="button-link kdna-copy-shortcode" data-clipboard-text="%2$s">%3$s</button></p><p class="description">%4$s</p>',
			esc_html( $shortcode ),
			esc_attr( $shortcode ),
			esc_html__( 'Copy', 'kdna-charts' ),
			esc_html__( 'The shortcode is registered at Stage 10.', 'kdna-charts' )
		);
	}

	/**
	 * Option tags for an enum, printed by PHP rather than looped by
	 * Alpine.
	 *
	 * ── Why this is not an x-for ───────────────────────────────────
	 *
	 * x-model binds a select before an x-for inside it has rendered its
	 * options. The browser cannot select a value among options that do
	 * not exist yet, so the select falls back to showing its first
	 * option while the data underneath still holds the real one. The
	 * screen then quietly lies about the chart, and the moment anybody
	 * touches that select the lie is committed.
	 *
	 * The vocabularies are fixed and PHP already knows them, so the
	 * options are simply there before Alpine looks. The values a select
	 * offers still come from the schema; only the loop moves.
	 *
	 * @param array $values Allowed values.
	 * @param array $labels Optional value => label map for the ones
	 *                      whose slug is not what a person should read.
	 * @return string
	 */
	public static function enum_options( array $values, array $labels = array() ) {
		$out = '';
		foreach ( $values as $value ) {
			$out .= sprintf(
				'<option value="%1$s">%2$s</option>',
				esc_attr( $value ),
				esc_html( isset( $labels[ $value ] ) ? $labels[ $value ] : $value )
			);
		}
		return $out;
	}

	/**
	 * Includes one of the plugin's templates.
	 *
	 * Variables are passed in rather than relied on from the caller's
	 * scope. An include inside a method sees that method's variables,
	 * not the caller's, which is a quiet way to hand a template nothing
	 * and watch it render nothing.
	 *
	 * @param string $file Template filename inside templates/.
	 * @param array  $vars Variables the template expects.
	 */
	private static function template( $file, array $vars = array() ) {
		$path = KDNA_CHARTS_PATH . 'templates/' . $file;
		if ( ! file_exists( $path ) ) {
			return;
		}
		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		extract( $vars, EXTR_SKIP );
		include $path;
	}

	/*
	 * ====================================================================
	 * State
	 * ====================================================================
	 */

	/**
	 * The definition, shaped for the editor.
	 *
	 * Only two changes from the stored shape: every series gets an id so
	 * Alpine can key its lists stably, and the annotation lists are
	 * guaranteed to exist so the templates can iterate them without
	 * checking first.
	 */
	private static function editor_state( array $definition ) {
		$chart = array(
			'type'    => (string) ( $definition['type'] ?? 'line' ),
			'engine'  => (string) ( $definition['engine'] ?? '' ),
			'options' => is_array( $definition['options'] ?? null ) ? $definition['options'] : array(),
			'axes'    => is_array( $definition['axes'] ?? null ) ? $definition['axes'] : array(),
			'source'  => (string) ( $definition['source'] ?? '' ),
			'caption' => (string) ( $definition['caption'] ?? '' ),
			'style'   => is_array( $definition['style'] ?? null ) ? $definition['style'] : array(),
		);

		$chart['axes']['x'] = is_array( $chart['axes']['x'] ?? null ) ? $chart['axes']['x'] : array();
		$chart['axes']['y'] = is_array( $chart['axes']['y'] ?? null ) ? $chart['axes']['y'] : array();

		foreach ( array( 'markers', 'points', 'callouts', 'notes' ) as $key ) {
			$chart[ $key ] = is_array( $definition[ $key ] ?? null ) ? array_values( $definition[ $key ] ) : array();
		}

		$series = array();
		foreach ( (array) ( $definition['series'] ?? array() ) as $index => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			if ( '' === trim( (string) ( $entry['id'] ?? '' ) ) ) {
				$entry['id'] = 'series_' . ( $index + 1 );
			}
			if ( ! empty( $entry['segments'] ) && is_array( $entry['segments'] ) ) {
				foreach ( $entry['segments'] as $position => $segment ) {
					$entry['segments'][ $position ] = self::fill_enum_defaults(
						is_array( $segment ) ? $segment : array(),
						KDNA_Charts_Schema::segment_spec()
					);
				}
			}
			$series[] = $entry;
		}
		$chart['series'] = $series;

		$specs = array(
			'markers'  => KDNA_Charts_Schema::marker_spec(),
			'points'   => KDNA_Charts_Schema::emphasised_point_spec(),
			'callouts' => KDNA_Charts_Schema::callout_spec(),
			'notes'    => KDNA_Charts_Schema::note_spec(),
		);
		foreach ( $specs as $key => $spec ) {
			foreach ( $chart[ $key ] as $position => $entry ) {
				$chart[ $key ][ $position ] = self::fill_enum_defaults( $entry, $spec );
			}
		}

		return $chart;
	}

	/**
	 * Writes the schema's default into any enum the definition leaves
	 * unset.
	 *
	 * ── Why the editor materialises these and the renderer does not ──
	 *
	 * A select cannot show "nothing". Given a marker with no style, it
	 * displays its first option, which is not the default the renderer
	 * would use, and the moment anybody touches that field the wrong
	 * value is committed. The screen has to say what will actually be
	 * drawn.
	 *
	 * This is done only for the per annotation enums, where a value
	 * belongs to one marker or one segment and inherits from nothing.
	 * Chart options are left alone, because those do sit in the style
	 * cascade and an unset one has to stay unset for the global setting
	 * to reach it. Their selects carry an explicit "use the default"
	 * choice instead.
	 */
	private static function fill_enum_defaults( $entry, array $spec ) {
		if ( ! is_array( $entry ) ) {
			return $entry;
		}
		$keys = isset( $spec['keys'] ) ? $spec['keys'] : array();

		foreach ( $keys as $key => $node ) {
			if ( 'enum' !== ( $node['kind'] ?? '' ) || ! array_key_exists( 'default', $node ) ) {
				continue;
			}
			$current = isset( $entry[ $key ] ) ? (string) $entry[ $key ] : '';
			if ( in_array( $current, $node['values'], true ) ) {
				continue;
			}
			$entry[ $key ] = $node['default'];
		}

		return $entry;
	}

	/*
	 * ====================================================================
	 * Assets
	 * ====================================================================
	 */

	public static function enqueue( $hook_suffix ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || KDNA_Charts_CPT::POST_TYPE !== $screen->post_type || 'post' !== $screen->base ) {
			return;
		}

		wp_enqueue_style( self::STYLE_ADMIN, KDNA_CHARTS_URL . 'assets/css/kdna-admin.css', array(), KDNA_CHARTS_VERSION );

		// The preview has to look like the front end, so it loads the
		// front end stylesheet rather than an admin approximation of it.
		wp_enqueue_style( self::STYLE_FRONT, KDNA_CHARTS_URL . 'assets/css/kdna-charts.css', array(), KDNA_CHARTS_VERSION );

		/*
		 * The component is registered before Alpine and Alpine is
		 * deferred, so the factory is defined by the time Alpine looks
		 * for it. Loading them the other way round leaves Alpine calling
		 * a function that does not exist yet.
		 */
		wp_enqueue_script( self::SCRIPT_ADMIN, KDNA_CHARTS_URL . 'assets/js/kdna-admin.js', array(), KDNA_CHARTS_VERSION, true );
		wp_enqueue_script( self::SCRIPT_ALPINE, KDNA_CHARTS_URL . 'assets/js/alpine.min.js', array( self::SCRIPT_ADMIN ), KDNA_CHARTS_VERSION, true );
		wp_script_add_data( self::SCRIPT_ALPINE, 'defer', true );

		wp_localize_script( self::SCRIPT_ADMIN, 'KDNAChartsEditor', self::script_settings() );
	}

	/**
	 * What the editor needs to know that it cannot work out.
	 *
	 * The vocabularies come from the schema class rather than being
	 * repeated in JavaScript, so a value the importer accepts is a value
	 * the editor offers, and neither can drift from the other.
	 */
	private static function script_settings() {
		$options = array();
		foreach ( KDNA_Charts_Schema::TYPES as $type ) {
			$spec = array();
			foreach ( KDNA_Charts_Schema::options_spec( $type ) as $key => $node ) {
				$spec[ $key ] = array(
					'kind'    => $node['kind'],
					'values'  => isset( $node['values'] ) ? array_values( $node['values'] ) : array(),
					'default' => array_key_exists( 'default', $node ) ? $node['default'] : null,
					'label'   => isset( $node['label'] ) ? $node['label'] : '',
					'min'     => isset( $node['min'] ) ? $node['min'] : null,
					'max'     => isset( $node['max'] ) ? $node['max'] : null,
				);
			}
			$options[ $type ] = $spec;
		}

		return array(
			'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
			'nonce'         => wp_create_nonce( self::NONCE_AJAX ),
			'previewAction' => self::AJAX_PREVIEW,
			'schema'        => array(
				'types'                => KDNA_Charts_Schema::TYPES,
				'engines'              => KDNA_Charts_Schema::ENGINES,
				'emphasis'             => KDNA_Charts_Schema::EMPHASIS,
				'lineStyles'           => KDNA_Charts_Schema::LINE_STYLES,
				'ruleStyles'           => KDNA_Charts_Schema::RULE_STYLES,
				'pointStyles'          => KDNA_Charts_Schema::POINT_STYLES,
				'leaders'              => KDNA_Charts_Schema::LEADERS,
				'calloutSizes'         => KDNA_Charts_Schema::CALLOUT_SIZES,
				'markerTypes'          => KDNA_Charts_Schema::MARKER_TYPES,
				'labelPositions'       => KDNA_Charts_Schema::LABEL_POSITIONS,
				'markerLabelPositions' => KDNA_Charts_Schema::MARKER_LABEL_POSITIONS,
				'alignments'           => KDNA_Charts_Schema::ALIGNMENTS,
				'options'              => $options,
			),
			'i18n'          => array(
				'seriesNumber'  => __( 'Series %d', 'kdna-charts' ),
				'pasted'        => __( 'Pasted %1$d values across %2$d rows.', 'kdna-charts' ),
				'previewFailed' => __( 'The preview could not be drawn. Your chart is not affected.', 'kdna-charts' ),
			),
		);
	}

	/*
	 * ====================================================================
	 * Preview
	 * ====================================================================
	 */

	public static function ajax_preview() {
		check_ajax_referer( self::NONCE_AJAX, 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You cannot preview this chart.', 'kdna-charts' ) ), 403 );
		}

		$raw = isset( $_POST['state'] ) ? wp_unslash( $_POST['state'] ) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$decoded = json_decode( (string) $raw, true );

		if ( ! is_array( $decoded ) || ! isset( $decoded['chart'] ) || ! is_array( $decoded['chart'] ) ) {
			wp_send_json_error( array( 'message' => __( 'The editor sent something the server could not read.', 'kdna-charts' ) ) );
		}

		$definition = self::validated( $decoded['chart'], $post_id );
		if ( is_wp_error( $definition ) ) {
			wp_send_json_error( array( 'message' => $definition->get_error_message() ) );
		}

		$definition['engine'] = KDNA_Charts_Data::resolve_engine( $definition['engine'] ?? '' );

		$renderer = KDNA_Charts_Renderer::create(
			$definition,
			array(
				'chart_id' => $post_id,
				'classes'  => array( 'kdna-chart--preview' ),
			)
		);

		wp_send_json_success(
			array(
				'html'   => $renderer->render(),
				'points' => KDNA_Charts_Data::count_points( $definition ),
			)
		);
	}

	/**
	 * Runs an editor state through the importer's validator.
	 *
	 * The title and schema version are supplied here rather than by the
	 * editor, because neither is the editor's to decide: the title is
	 * the post's, and the version is the plugin's.
	 *
	 * @return array|WP_Error
	 */
	private static function validated( array $chart, $post_id ) {
		$chart['kdna_chart'] = KDNA_Charts_Schema::VERSION;
		$chart['title']      = get_the_title( $post_id );
		if ( '' === trim( (string) $chart['title'] ) ) {
			$chart['title'] = __( 'Untitled chart', 'kdna-charts' );
		}

		$result = KDNA_Charts_Import::validate( $chart );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $result['definition'];
	}

	/*
	 * ====================================================================
	 * Saving
	 * ====================================================================
	 */

	public static function save_post( $post_id, $post, $update ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$raw = isset( $_POST[ self::STATE_INPUT ] ) ? wp_unslash( $_POST[ self::STATE_INPUT ] ) : '';
		if ( '' === trim( (string) $raw ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$decoded = json_decode( (string) $raw, true );
		if ( ! is_array( $decoded ) || ! isset( $decoded['chart'] ) || ! is_array( $decoded['chart'] ) ) {
			return;
		}

		/*
		 * ── Refuse a state this post did not produce ──────────────────
		 *
		 * The editor seeds itself from the definition printed into the
		 * page. If that never arrives, because the script was blocked,
		 * reordered by an optimiser or lost behind a CDN, Alpine falls
		 * back to an empty chart. The screen then looks like a brand new
		 * chart, and pressing Update writes that emptiness over a real
		 * one. Silent, total, and indistinguishable from "my data has
		 * disappeared".
		 *
		 * The seed carries the post id and the fallback does not, so a
		 * mismatch is an exact statement that this state did not come
		 * from this post, and the only safe thing to do with it is
		 * nothing. A genuinely empty chart posts its own id and saves as
		 * it always did.
		 *
		 * Deliberately not a "did it shrink?" check. Deleting rows is
		 * something people do on purpose, and a guard that second
		 * guesses that is a guard that loses edits instead of saving
		 * them.
		 */
		$claimed = isset( $decoded['post_id'] ) ? (int) $decoded['post_id'] : 0;
		if ( $claimed !== (int) $post_id ) {
			set_transient(
				self::seed_failure_key( $post_id ),
				array(
					'expected' => (int) $post_id,
					'received' => $claimed,
				),
				self::SEED_FAILURE_TTL
			);
			return;
		}

		$definition = self::validated( $decoded['chart'], $post_id );
		if ( is_wp_error( $definition ) ) {
			return;
		}

		KDNA_Charts_CPT::save_definition( $post_id, $definition );
	}

	private static function seed_failure_key( $post_id ) {
		return 'kdna_charts_seed_failure_' . (int) $post_id;
	}

	/**
	 * Tells the user when a save was refused, rather than letting the
	 * screen report success on a save that did not happen.
	 */
	public static function render_seed_failure_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->base || KDNA_Charts_CPT::POST_TYPE !== $screen->post_type ) {
			return;
		}

		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		if ( ! $post_id ) {
			return;
		}

		$failure = get_transient( self::seed_failure_key( $post_id ) );
		if ( ! is_array( $failure ) ) {
			return;
		}
		delete_transient( self::seed_failure_key( $post_id ) );

		?>
		<div class="notice notice-error">
			<p>
				<strong><?php esc_html_e( 'That save was refused, and your chart is untouched.', 'kdna-charts' ); ?></strong>
			</p>
			<p>
				<?php esc_html_e( 'The editor sent a chart that did not come from this post, which happens when its scripts fail to load. Saving it would have replaced your chart with an empty one, so nothing was written.', 'kdna-charts' ); ?>
			</p>
			<p>
				<?php esc_html_e( 'Reload this page. If the editor still does not appear, something on the site is blocking or reordering its scripts.', 'kdna-charts' ); ?>
			</p>
		</div>
		<?php
	}
}
