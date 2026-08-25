<?php
/**
 * Settings > KDNA Charts: the global style defaults, the per chart
 * overrides panel, the live preview, and the REST routes all three save
 * and read through.
 *
 * The page renders from KDNA_Charts_Style_Schema and saves back into the
 * single kdna_charts_style_defaults option. One option rather than a
 * hundred and fifty keeps the options table clean, means the whole set
 * loads in one autoloaded read, and makes a preset export a single
 * json_encode.
 *
 * ── Sanitising ────────────────────────────────────────────────────────
 *
 * Everything arriving on a REST route is validated against the schema
 * rather than against a hand-written list, so a control added later is
 * saveable the moment its schema entry exists. A key with no schema
 * entry is discarded outright; each type has its own sanitiser; and a
 * value that sanitises to nothing is dropped from the stored array
 * rather than written as an empty string.
 *
 * That last point matters beyond tidiness. Absent means inherit
 * everywhere in this system: the resolver skips absent values so the
 * layer beneath shows through, and the stylesheet's fallback chains only
 * fall through on a property that was never emitted. An empty string
 * stored here would travel all the way to the front end as an empty
 * custom property and break both.
 *
 * @package KDNA_Charts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KDNA_Charts_Style_Admin {

	const MENU_SLUG     = 'kdna-charts-styles';
	const SCRIPT_HANDLE = 'kdna-charts-style-admin';
	const STYLE_HANDLE  = 'kdna-charts-style-admin';

	const REST_NAMESPACE     = 'kdna-charts/v1';
	const REST_ROUTE         = '/styles';
	const REST_ROUTE_CHART   = '/styles/(?P<id>\d+)';
	const REST_ROUTE_EXPORT  = '/styles/export';
	const REST_ROUTE_IMPORT  = '/styles/import';
	const REST_ROUTE_PREVIEW = '/style-preview/(?P<id>\d+)';

	/** How many charts the preview picker lists. */
	const PREVIEW_CHART_LIMIT = 100;

	/**
	 * Iframe widths for the preview device toggle.
	 *
	 * These are the widths the resolution layer in kdna-charts.css keys
	 * off, one pixel inside each band, so switching the toggle shows
	 * exactly the breakpoint the matching control writes. A preview at
	 * 1024px would sit on the boundary and could show either.
	 */
	const PREVIEW_WIDTHS = array(
		'desktop' => 1200,
		'tablet'  => 900,
		'mobile'  => 390,
	);

	/** Hook suffix returned by add_options_page, for the asset check. */
	private static $hook_suffix = '';

	/**
	 * Boot.
	 *
	 * Called unconditionally rather than inside is_admin(), because the
	 * REST routes register on rest_api_init and a /wp-json/ request is
	 * not an admin request. The invalidation hooks are wired here for the
	 * same reason: a chart's style meta can be written by WP-CLI or by an
	 * import running outside the admin, and a stale cached string with no
	 * way to clear it is worse than the cache is worth.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		/*
		 * Late, and it has to be. This class boots before the editor, so
		 * at priority 10 the editor's Alpine is not registered yet and
		 * there is nothing to attach the ordering to.
		 */
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'pin_script_order' ), 20 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
	}

	/* ─── Menu ──────────────────────────────────────────────────────── */

	/**
	 * Two entries, one page.
	 *
	 * The brief asks for Settings > KDNA Charts, and that is where the
	 * page lives. The second entry is a plain link from the KDNA Charts
	 * menu to the same URL, because that menu is where somebody working
	 * on charts already is, and a styling page they have to remember is
	 * under Settings is a styling page they will not find. A submenu slug
	 * containing .php is treated by WordPress as a link rather than as a
	 * page to register, so this costs one line and registers nothing
	 * twice.
	 */
	public static function register_menu() {
		self::$hook_suffix = add_options_page(
			__( 'KDNA Charts', 'kdna-charts' ),
			__( 'KDNA Charts', 'kdna-charts' ),
			'manage_options',
			self::MENU_SLUG,
			array( __CLASS__, 'render_page' )
		);

		add_submenu_page(
			KDNA_Charts_Admin::MENU_SLUG_LIST,
			__( 'Chart Styles', 'kdna-charts' ),
			__( 'Styles', 'kdna-charts' ),
			'manage_options',
			'options-general.php?page=' . self::MENU_SLUG
		);
	}

	/**
	 * The settings page's admin URL.
	 */
	public static function page_url() {
		return admin_url( 'options-general.php?page=' . self::MENU_SLUG );
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to edit these settings.', 'kdna-charts' ) );
		}

		self::render_template(
			'admin-style-settings.php',
			array(
				'sections' => KDNA_Charts_Style_Schema::get_sections(),
				'grouped'  => KDNA_Charts_Style_Schema::get_by_section(),
				'devices'  => KDNA_Charts_Style_Schema::get_devices(),
				'preview'  => self::preview_data(),
			)
		);
	}

	/**
	 * The per chart panel, rendered inside the editor's Style tab.
	 *
	 * Gated on manage_options rather than on edit_post, deliberately and
	 * to match the meta's own auth callback: the route it saves through
	 * carries the same capability check, so showing the panel to an
	 * editor who could not save from it would be worse than not showing
	 * it at all.
	 *
	 * @param int $chart_id Chart being edited.
	 */
	public static function render_overrides_panel( $chart_id ) {
		$chart_id = (int) $chart_id;

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		self::render_template(
			'admin-style-overrides.php',
			array(
				'sections' => KDNA_Charts_Style_Schema::get_sections(),
				'grouped'  => KDNA_Charts_Style_Schema::get_by_section(),
				'devices'  => KDNA_Charts_Style_Schema::get_devices(),
				'chart_id' => $chart_id,
			)
		);
	}

	/**
	 * Render one of the plugin's templates.
	 *
	 * The variables are passed rather than left in scope, because an
	 * include inside a static method sees THAT method's scope and not the
	 * caller's. A template that quietly rendered nothing because its
	 * variables were not there is a bug this plugin has already had once.
	 */
	private static function render_template( $file, array $vars ) {
		$path = KDNA_CHARTS_PATH . 'templates/' . $file;
		if ( ! file_exists( $path ) ) {
			return;
		}
		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		extract( $vars, EXTR_SKIP );
		include $path;
	}

	/**
	 * The overrides stored against one chart, always an array.
	 *
	 * Through the CPT, because the meta is stored JSON encoded.
	 */
	public static function stored_overrides( $chart_id ) {
		$values = KDNA_Charts_CPT::get_json_meta( (int) $chart_id, KDNA_Charts_CPT::META_STYLE );
		return is_array( $values ) ? $values : array();
	}

	/**
	 * The stored global defaults, always an array.
	 */
	public static function stored_values() {
		$values = get_option( KDNA_Charts_Style_Resolver::OPTION_KEY, array() );
		return is_array( $values ) ? $values : array();
	}

	/* ─── Assets ────────────────────────────────────────────────────── */

	public static function enqueue_assets( $hook ) {
		$chart_id = self::edited_chart_id( $hook );
		$on_page  = ( '' !== self::$hook_suffix && $hook === self::$hook_suffix );

		if ( ! $on_page && 0 === $chart_id ) {
			return;
		}

		wp_enqueue_style( 'dashicons' );

		wp_enqueue_style(
			self::STYLE_HANDLE,
			KDNA_CHARTS_URL . 'assets/css/kdna-style-admin.css',
			array(),
			KDNA_CHARTS_VERSION
		);

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			KDNA_CHARTS_URL . 'assets/js/kdna-style-admin.js',
			array(),
			KDNA_CHARTS_VERSION,
			true
		);

		/*
		 * Alpine is not enqueued here, and the reason is the same one the
		 * chart editor carries.
		 *
		 * Alpine boots the moment its script tag is parsed and walks the
		 * DOM immediately, so every component has to be registered
		 * before then. Declaring an order through wp_enqueue_script's
		 * dependency list pins it, and is only as reliable as nothing
		 * else on the site reordering, deferring or combining admin
		 * scripts. When Alpine wins that race every x-data expression
		 * throws and the page renders as an inert skeleton.
		 *
		 * kdna-style-admin.js loads Alpine itself, once it has
		 * registered, which makes the order structural instead of
		 * declared. The URL travels in the bootstrap object below.
		 *
		 * On a chart edit screen two of this plugin's scripts want
		 * Alpine, so the loader is idempotent and deferred to
		 * DOMContentLoaded: by then every classic script has run and
		 * every component is registered, whichever of the two got there
		 * first.
		 */

		wp_add_inline_script(
			self::SCRIPT_HANDLE,
			'window.KDNAChartsStyles = ' . wp_json_encode( self::bootstrap_data( $chart_id ) ) . ';',
			'before'
		);
	}

	/**
	 * Make sure this script runs before Alpine does.
	 *
	 * On a chart edit screen the editor enqueues Alpine itself, so the
	 * injector at the bottom of kdna-style-admin.js correctly does
	 * nothing. But that leaves the order between this script and the
	 * editor's Alpine decided by nothing more than which class hooked
	 * admin_enqueue_scripts first. It happens to be right, and "happens
	 * to be" is how a working screen becomes a screen of thrown
	 * expressions after an unrelated change.
	 *
	 * Adding this handle to Alpine's dependency list states the order
	 * instead of relying on it. WordPress always prints a dependency
	 * first, so the component is registered before Alpine can look for
	 * it. Guarded, because Alpine is only registered on that one screen.
	 */
	public static function pin_script_order() {
		if ( ! class_exists( 'KDNA_Charts_Editor' ) ) {
			return;
		}

		if ( ! wp_script_is( self::SCRIPT_HANDLE, 'enqueued' ) ) {
			return;
		}

		$alpine = wp_scripts()->query( KDNA_Charts_Editor::SCRIPT_ALPINE, 'registered' );

		if ( ! $alpine || in_array( self::SCRIPT_HANDLE, (array) $alpine->deps, true ) ) {
			return;
		}

		$alpine->deps[] = self::SCRIPT_HANDLE;
	}

	/**
	 * The chart being edited on this screen, or 0 when this is not a
	 * chart edit screen at all.
	 */
	private static function edited_chart_id( $hook ) {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return 0;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return 0;
		}

		$post = get_post();
		if ( ! $post instanceof WP_Post || KDNA_Charts_CPT::POST_TYPE !== $post->post_type ) {
			return 0;
		}

		return (int) $post->ID;
	}

	/**
	 * The seed the page boots from.
	 *
	 * The schema travels with it because the JavaScript needs it for the
	 * same reason the PHP does: to know a control's shape before binding
	 * a value to it, and to resolve the preview's properties without
	 * asking the server on every keystroke.
	 */
	private static function bootstrap_data( $chart_id = 0 ) {
		$chart_id = (int) $chart_id;
		$is_chart = $chart_id > 0;

		return array(
			'schema'    => KDNA_Charts_Style_Schema::get(),
			'sections'  => KDNA_Charts_Style_Schema::get_sections(),
			'devices'   => array_keys( KDNA_Charts_Style_Schema::get_devices() ),
			'context'   => $is_chart ? 'chart' : 'global',
			'chartId'   => $chart_id,
			'values'    => $is_chart ? self::stored_overrides( $chart_id ) : self::stored_values(),
			/*
			 * What the layer beneath contributes, so an inherited control
			 * can show the value it is inheriting rather than a blank.
			 * For a chart that is the global option; on the global page
			 * there is no layer beneath, and the stylesheet's own default
			 * is shown instead, which is what the placeholder carries.
			 */
			'inherited' => $is_chart ? KDNA_Charts_Style_Resolver::resolve_values( 0 ) : array(),
			'restUrl'   => $is_chart
				? rest_url( self::REST_NAMESPACE . '/styles/' . $chart_id )
				: rest_url( self::REST_NAMESPACE . self::REST_ROUTE ),
			'exportUrl' => rest_url( self::REST_NAMESPACE . self::REST_ROUTE_EXPORT ),
			'importUrl' => $is_chart ? '' : rest_url( self::REST_NAMESPACE . self::REST_ROUTE_IMPORT ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'alpineUrl' => KDNA_CHARTS_URL . 'assets/js/alpine.min.js?ver=' . KDNA_CHARTS_VERSION,
			/*
			 * The preview pane is on the settings page only. A chart
			 * panel has no iframe: the chart editor around it already
			 * has a live preview, rendered by the same renderer, and the
			 * panel paints its properties into that one instead of
			 * standing a second preview beside the first.
			 */
			'preview'   => $is_chart ? null : self::preview_data(),
			'strings'   => self::strings(),
		);
	}

	private static function strings() {
		return array(
			'saving'         => __( 'Saving…', 'kdna-charts' ),
			'saved'          => __( 'Saved', 'kdna-charts' ),
			'failed'         => __( 'Could not save', 'kdna-charts' ),
			'unsaved'        => __( 'Unsaved changes', 'kdna-charts' ),
			'discarded'      => __( 'Some values were not valid and were discarded.', 'kdna-charts' ),
			'inherit'        => __( 'Inherit', 'kdna-charts' ),
			'stylesheet'     => __( 'the plugin default', 'kdna-charts' ),
			'confirmChart'   => __( 'Drop every style override on this chart and follow the global defaults again?', 'kdna-charts' ),
			'confirmGlobal'  => __( 'Reset every global style to the plugin defaults? Charts with their own overrides keep them.', 'kdna-charts' ),
			'exported'       => __( 'Preset downloaded', 'kdna-charts' ),
			'exportDirty'    => __( 'Save first. The export is of the saved styles, not of what is on screen.', 'kdna-charts' ),
			'importing'      => __( 'Importing…', 'kdna-charts' ),
			'imported'       => __( 'Imported', 'kdna-charts' ),
			'importFailed'   => __( 'Could not import that preset.', 'kdna-charts' ),
			'importConfirm'  => __( 'Importing replaces every global style with the preset. Continue?', 'kdna-charts' ),
			'discardedIntro' => __( 'These keys were not imported:', 'kdna-charts' ),
			'loading'        => __( 'Loading preview…', 'kdna-charts' ),
			'noPreview'      => __( 'Publish a chart to see it previewed here.', 'kdna-charts' ),
			'previewFailed'  => __( 'Could not load the preview.', 'kdna-charts' ),
			'search'         => __( 'Filter controls', 'kdna-charts' ),
			'noMatches'      => __( 'No controls match that.', 'kdna-charts' ),
		);
	}

	/* ─── Live preview ──────────────────────────────────────────────── */

	/**
	 * Everything the preview pane needs to boot: what it can show, what
	 * to show first, where to fetch markup, and what to load inside the
	 * iframe.
	 *
	 * Returns null when there is nothing to preview, which the pane reads
	 * as "render the empty state" rather than as an error.
	 */
	private static function preview_data() {
		$charts = self::preview_charts();
		if ( empty( $charts ) ) {
			return null;
		}

		return array(
			'charts'  => $charts,
			'chartId' => $charts[0]['id'],
			'restUrl' => rest_url( self::REST_NAMESPACE . '/style-preview/' ),
			'widths'  => self::PREVIEW_WIDTHS,
			/*
			 * The iframe is a document of our own making, so it loads the
			 * front-end stylesheet by URL rather than inheriting the
			 * admin's. That is the point of it: the preview has to be the
			 * same stylesheet resolving the same properties, or it is
			 * only a drawing of what the chart might look like.
			 */
			'css'     => self::preview_stylesheets(),
			'devices' => KDNA_Charts_Style_Schema::get_devices(),
		);
	}

	/**
	 * Charts the preview can render, most recently modified first, with
	 * whether each carries its own overrides so the pane can say the
	 * front end will differ.
	 *
	 */
	private static function preview_charts() {
		$posts = get_posts(
			array(
				'post_type'        => KDNA_Charts_CPT::POST_TYPE,
				'post_status'      => 'publish',
				'numberposts'      => self::PREVIEW_CHART_LIMIT,
				'orderby'          => 'modified',
				'order'            => 'DESC',
				'suppress_filters' => false,
			)
		);

		$charts = array();

		foreach ( (array) $posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			$charts[] = self::preview_chart_entry( $post );
		}

		return $charts;
	}

	private static function preview_chart_entry( WP_Post $post ) {
		$title = trim( (string) $post->post_title );

		return array(
			'id'           => (int) $post->ID,
			/* translators: %d: chart post id. */
			'title'        => '' === $title ? sprintf( __( 'Chart %d', 'kdna-charts' ), (int) $post->ID ) : $title,
			'type'         => (string) KDNA_Charts_CPT::get_type( $post->ID ),
			'hasOverrides' => ! empty( self::stored_overrides( $post->ID ) ),
		);
	}

	/**
	 * Stylesheet URLs the preview iframe loads.
	 */
	private static function preview_stylesheets() {
		$urls = array( KDNA_CHARTS_URL . 'assets/css/kdna-charts.css' );

		$versioned = array();
		foreach ( $urls as $url ) {
			$versioned[] = esc_url_raw( add_query_arg( 'ver', KDNA_CHARTS_VERSION, $url ) );
		}

		return $versioned;
	}

	/**
	 * Markup for the preview iframe.
	 *
	 * The renderer produces it, so the preview is the render path's own
	 * output rather than a second layout that could disagree with the
	 * front end.
	 *
	 * The one thing deliberately withheld is the resolved style
	 * attribute. The pane writes the custom properties itself, from the
	 * values currently in the form, and it can only do that reliably if
	 * the wrapper is not already carrying a saved set underneath: an
	 * unset control has to read as absent in the iframe exactly as it
	 * does on the front end, and a leftover attribute would make it read
	 * as the last saved value instead.
	 *
	 * On a chart edit screen the pane resolves the global layer plus the
	 * form, which is what that chart will render as. On the settings page
	 * it resolves the form alone, and says so when the chart being
	 * previewed carries overrides of its own.
	 */
	public static function handle_preview( $request ) {
		$chart_id = (int) $request->get_param( 'id' );
		$post     = get_post( $chart_id );

		if ( ! $post instanceof WP_Post || KDNA_Charts_CPT::POST_TYPE !== $post->post_type ) {
			return new WP_Error(
				'kdna_charts_unknown_chart',
				__( 'That chart does not exist.', 'kdna-charts' ),
				array( 'status' => 404 )
			);
		}

		$definition = KDNA_Charts_CPT::get_definition( $chart_id );

		$strip = static function () {
			return array();
		};
		add_filter( 'kdna_charts_style_properties', $strip, 99 );

		$renderer = KDNA_Charts_Renderer::create( $definition, array( 'chart_id' => $chart_id ) );
		$html     = $renderer ? $renderer->render() : '';

		remove_filter( 'kdna_charts_style_properties', $strip, 99 );

		return rest_ensure_response(
			array(
				'id'    => $chart_id,
				'type'  => (string) ( $definition['type'] ?? '' ),
				'html'  => $html,
				'empty' => ( '' === trim( (string) $html ) ),
			)
		);
	}

	/* ─── REST ──────────────────────────────────────────────────────── */

	public static function register_rest_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_save' ),
				'permission_callback' => array( __CLASS__, 'permission_check' ),
				'args'                => array(
					'values' => array(
						'required' => true,
						'type'     => 'object',
					),
				),
			)
		);

		/*
		 * Per chart overrides. Same payload shape, same permission
		 * callback and the same sanitiser as the global route; the only
		 * differences are where the result is stored and one extra check
		 * that the id is a chart this user may edit.
		 */
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE_CHART,
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_chart_save' ),
				'permission_callback' => array( __CLASS__, 'permission_check' ),
				'args'                => array(
					'id'     => array(
						'required' => true,
						'type'     => 'integer',
					),
					'values' => array(
						'required' => true,
						'type'     => 'object',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE_EXPORT,
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_export' ),
				'permission_callback' => array( __CLASS__, 'permission_check' ),
				'args'                => array(
					'id' => array(
						'required' => false,
						'type'     => 'integer',
						'default'  => 0,
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE_IMPORT,
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_import' ),
				'permission_callback' => array( __CLASS__, 'permission_check' ),
				'args'                => array(
					'preset' => array( 'required' => true ),
				),
			)
		);

		/*
		 * Preview markup. Read-only, but behind the same permission
		 * callback as the save routes: it renders a chart's content,
		 * including charts that exist but are not linked from anywhere,
		 * and this is a settings-page facility rather than a public one.
		 */
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE_PREVIEW,
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_preview' ),
				'permission_callback' => array( __CLASS__, 'permission_check' ),
				'args'                => array(
					'id' => array(
						'required' => true,
						'type'     => 'integer',
					),
				),
			)
		);
	}

	/**
	 * manage_options, plus an explicit wp_rest nonce check.
	 *
	 * The REST cookie handler already refuses to authenticate a request
	 * whose X-WP-Nonce is missing or stale, so this is belt and braces
	 * rather than the only guard. It is worth having because it fails
	 * loudly with a message naming the nonce, instead of failing as "you
	 * are not allowed", which is a confusing way to discover that a
	 * settings page has been left open past the nonce lifetime.
	 */
	public static function permission_check( $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! $nonce ) {
			$nonce = $request->get_param( '_wpnonce' );
		}

		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error(
				'kdna_charts_bad_nonce',
				__( 'The security token has expired. Reload the page and try again.', 'kdna-charts' ),
				array( 'status' => 403 )
			);
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'kdna_charts_forbidden',
				__( 'You do not have permission to edit these settings.', 'kdna-charts' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Save the global defaults.
	 *
	 * Returns the values as they were actually stored, not as they
	 * arrived, so the page can re-seed itself from the response and show
	 * the result of sanitising rather than the user's own input sitting
	 * there looking saved.
	 */
	public static function handle_save( $request ) {
		$incoming = $request->get_param( 'values' );

		if ( ! is_array( $incoming ) ) {
			return new WP_Error(
				'kdna_charts_bad_payload',
				__( 'Expected an object of style values.', 'kdna-charts' ),
				array( 'status' => 400 )
			);
		}

		$clean = self::sanitize_values( $incoming );

		update_option( KDNA_Charts_Style_Resolver::OPTION_KEY, $clean );

		// Any chart can be affected by a change to the globals, so the
		// whole generation moves on.
		KDNA_Charts_Style_Resolver::invalidate_all();
		self::flush_page_caches();

		return rest_ensure_response(
			array(
				'saved'  => true,
				'values' => $clean,
			)
		);
	}

	/**
	 * Save one chart's overrides.
	 *
	 * Overrides that sanitise to nothing are deleted rather than stored
	 * empty, because an absent override is exactly what inherit means to
	 * the resolver.
	 */
	public static function handle_chart_save( $request ) {
		$chart_id = (int) $request->get_param( 'id' );
		$post     = get_post( $chart_id );

		if ( ! $post instanceof WP_Post || KDNA_Charts_CPT::POST_TYPE !== $post->post_type ) {
			return new WP_Error(
				'kdna_charts_unknown_chart',
				__( 'That chart does not exist.', 'kdna-charts' ),
				array( 'status' => 404 )
			);
		}

		if ( ! current_user_can( 'edit_post', $chart_id ) ) {
			return new WP_Error(
				'kdna_charts_forbidden',
				__( 'You do not have permission to edit this chart.', 'kdna-charts' ),
				array( 'status' => 403 )
			);
		}

		$incoming = $request->get_param( 'values' );

		if ( ! is_array( $incoming ) ) {
			return new WP_Error(
				'kdna_charts_bad_payload',
				__( 'Expected an object of style values.', 'kdna-charts' ),
				array( 'status' => 400 )
			);
		}

		$clean = self::sanitize_values( $incoming );

		/*
		 * Through the CPT both ways, because the meta is stored JSON
		 * encoded and update_metadata() runs wp_unslash() on what it is
		 * given. Writing the array here with update_post_meta() would
		 * store a serialised array the resolver's JSON read cannot see.
		 */
		KDNA_Charts_CPT::update_json_meta( $chart_id, KDNA_Charts_CPT::META_STYLE, $clean );

		// One chart's overrides cannot change what any other chart
		// resolves to, so only this one is invalidated.
		KDNA_Charts_Style_Resolver::invalidate_chart( $chart_id );
		self::flush_page_caches();

		return rest_ensure_response(
			array(
				'saved'  => true,
				'values' => $clean,
			)
		);
	}

	/* ─── Presets ───────────────────────────────────────────────────── */

	/**
	 * The saved styles as a portable preset.
	 *
	 * Exports what is STORED, not what is on screen. A preset that
	 * silently included unsaved edits would be a preset nobody could
	 * reproduce; the page says so when there are unsaved changes rather
	 * than quietly folding them in.
	 *
	 * A chart id exports that chart's overrides instead of the globals,
	 * which is how a treatment worked out on one chart gets carried to
	 * another. The two are the same shape, so either file imports as the
	 * global defaults, which is also how a chart's look is promoted to
	 * being the site's.
	 */
	public static function handle_export( $request ) {
		$chart_id = (int) $request->get_param( 'id' );
		$values   = $chart_id > 0 ? self::stored_overrides( $chart_id ) : self::stored_values();

		return rest_ensure_response(
			array(
				'kdna_charts_preset' => true,
				'plugin_version'     => KDNA_CHARTS_VERSION,
				'schema_version'     => KDNA_Charts_Schema::VERSION,
				'scope'              => $chart_id > 0 ? 'chart' : 'global',
				'chart'              => $chart_id > 0 ? get_the_title( $chart_id ) : '',
				'exported'           => gmdate( 'c' ),
				'site'               => home_url(),
				'values'             => $values,
			)
		);
	}

	/**
	 * Replace the global defaults from a preset.
	 *
	 * Import REPLACES rather than merges. Merging would make the result
	 * depend on what was already there, so importing one preset onto two
	 * sites could produce two different charts, which is the one thing a
	 * preset exists to prevent.
	 *
	 * Anything the schema does not accept is dropped and REPORTED. A
	 * preset from a newer build, or a hand-edited file, should say which
	 * of its values did not survive rather than appearing to work and
	 * quietly rendering something else.
	 */
	public static function handle_import( $request ) {
		$payload = $request->get_param( 'preset' );

		// Accept the file's whole contents as a string, since that is what
		// a paste or a file read produces.
		if ( is_string( $payload ) ) {
			$decoded = json_decode( $payload, true );
			if ( ! is_array( $decoded ) ) {
				return new WP_Error(
					'kdna_charts_bad_preset',
					__( 'That is not valid JSON.', 'kdna-charts' ),
					array( 'status' => 400 )
				);
			}
			$payload = $decoded;
		}

		if ( ! is_array( $payload ) ) {
			return new WP_Error(
				'kdna_charts_bad_preset',
				__( 'Expected a preset object.', 'kdna-charts' ),
				array( 'status' => 400 )
			);
		}

		// A bare map of control keys is accepted as well as a full export,
		// so a preset can be hand-written without ceremony.
		$values = array_key_exists( 'values', $payload ) ? $payload['values'] : $payload;

		if ( ! is_array( $values ) ) {
			return new WP_Error(
				'kdna_charts_bad_preset',
				__( 'The preset carries no style values.', 'kdna-charts' ),
				array( 'status' => 400 )
			);
		}

		$discarded = array();
		$clean     = self::sanitize_values( $values, $discarded );

		update_option( KDNA_Charts_Style_Resolver::OPTION_KEY, $clean );
		KDNA_Charts_Style_Resolver::invalidate_all();
		self::flush_page_caches();

		return rest_ensure_response(
			array(
				'saved'     => true,
				'imported'  => count( $clean ),
				'offered'   => count( $values ),
				'discarded' => array_values( $discarded ),
				'values'    => $clean,
			)
		);
	}

	/* ─── Page caches ───────────────────────────────────────────────── */

	/**
	 * Ask a page cache to drop what it has, after a style change.
	 *
	 * The resolved properties are written into the markup as an inline
	 * style attribute, so a cached page keeps the old styling until it is
	 * regenerated: this plugin's own transient being fresh does not help
	 * if nothing re-renders. WP Rocket is handled by name because it is
	 * what the site this was built for runs; everything else is left to
	 * the action.
	 */
	public static function flush_page_caches() {
		/**
		 * Filter whether a style save flushes page caches.
		 *
		 * @param bool $enabled Default true.
		 */
		if ( ! apply_filters( 'kdna_charts_flush_page_cache', true ) ) {
			return;
		}

		// Guarded, because Rocket may not be installed, may be deactivated,
		// or may have renamed its helpers between versions. A fatal here
		// would take down the save that just succeeded.
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
		if ( function_exists( 'rocket_clean_minify' ) ) {
			rocket_clean_minify( 'css' );
		}

		/**
		 * Fires after a style save, for other page caches to hook.
		 */
		do_action( 'kdna_charts_styles_changed' );
	}

	/* ─── Sanitising ────────────────────────────────────────────────── */

	/**
	 * Sanitise a whole payload against the schema.
	 *
	 * @param array      $incoming  Raw control key => value.
	 * @param array|null $discarded Filled with a report of what was
	 *                              dropped and why. Import shows this to
	 *                              the user; an ordinary save ignores it,
	 *                              because there a dropped value means
	 *                              the user cleared a control.
	 * @return array Control key => value, ready to store.
	 */
	public static function sanitize_values( array $incoming, &$discarded = null ) {
		$schema    = KDNA_Charts_Style_Schema::get();
		$clean     = array();
		$discarded = array();

		foreach ( $incoming as $key => $value ) {
			if ( ! isset( $schema[ $key ] ) ) {
				$discarded[] = array(
					'key'    => (string) $key,
					'label'  => (string) $key,
					'reason' => __( 'not a known control', 'kdna-charts' ),
				);
				continue;
			}

			$sanitized = self::sanitize_control( $schema[ $key ], $value );

			if ( null === $sanitized ) {
				$discarded[] = array(
					'key'    => (string) $key,
					'label'  => isset( $schema[ $key ]['label'] ) ? $schema[ $key ]['label'] : (string) $key,
					'reason' => __( 'no usable value', 'kdna-charts' ),
				);
				continue;
			}

			$clean[ $key ] = $sanitized;
		}

		return $clean;
	}

	/**
	 * Sanitise one control's value, in whatever shape its definition
	 * calls for. Returns null when nothing survives, which the caller
	 * reads as "do not store this key at all".
	 */
	private static function sanitize_control( array $definition, $value ) {
		if ( empty( $definition['responsive'] ) ) {
			return self::sanitize_leaf( $definition, $value );
		}

		// A bare value written without a device map is read as desktop
		// rather than thrown away. See Style_Schema::as_device_map().
		$value = KDNA_Charts_Style_Schema::as_device_map( $value );

		$clean = array();

		foreach ( KDNA_Charts_Style_Schema::DEVICES as $device ) {
			if ( ! array_key_exists( $device, $value ) ) {
				continue;
			}
			$device_clean = self::sanitize_leaf( $definition, $value[ $device ] );
			if ( null === $device_clean ) {
				continue;
			}
			$clean[ $device ] = $device_clean;
		}

		return empty( $clean ) ? null : $clean;
	}

	/**
	 * Sanitise a single value by type.
	 */
	private static function sanitize_leaf( array $definition, $value ) {
		$type = isset( $definition['type'] ) ? $definition['type'] : '';

		switch ( $type ) {
			case 'colour':
				return self::sanitize_colour( $value );

			case 'dimensions':
				return self::sanitize_dimensions( $definition, $value );

			case 'slider':
				return self::sanitize_slider( $definition, $value );

			case 'number':
				return self::sanitize_number( $definition, $value );

			case 'select':
				return self::sanitize_select( $definition, $value );
		}

		return null;
	}

	/**
	 * A hex colour, an rgb()/rgba() colour, or one of the keywords a
	 * chart legitimately needs.
	 *
	 * sanitize_hex_color() rejects rgba outright, so the functional
	 * notations are validated component by component and rebuilt from
	 * the parsed numbers. Rebuilding rather than passing the input
	 * through means nothing unexpected can survive inside the
	 * parentheses.
	 */
	private static function sanitize_colour( $value ) {
		if ( ! is_scalar( $value ) ) {
			return null;
		}

		$value = strtolower( trim( (string) $value ) );
		if ( '' === $value ) {
			return null;
		}

		/*
		 * transparent is not a decoration here. It is the default of six
		 * controls, and the only way to say "draw no fill". A chart
		 * whose plot area or bar outline could not be turned off again
		 * would be a chart with a one-way control.
		 */
		if ( in_array( $value, array( 'transparent', 'currentcolor' ), true ) ) {
			return $value;
		}

		if ( 'inherit' === $value ) {
			return null;
		}

		if ( preg_match( '/^rgba?\(([^)]*)\)$/', $value, $matches ) ) {
			$parts = array_map( 'trim', explode( ',', $matches[1] ) );

			if ( count( $parts ) < 3 || count( $parts ) > 4 ) {
				return null;
			}

			$rgb = array();
			for ( $i = 0; $i < 3; $i++ ) {
				if ( ! is_numeric( $parts[ $i ] ) ) {
					return null;
				}
				$rgb[] = max( 0, min( 255, (int) round( (float) $parts[ $i ] ) ) );
			}

			if ( 3 === count( $parts ) ) {
				return sprintf( 'rgb(%d, %d, %d)', $rgb[0], $rgb[1], $rgb[2] );
			}

			if ( ! is_numeric( $parts[3] ) ) {
				return null;
			}

			$alpha = max( 0, min( 1, (float) $parts[3] ) );
			$alpha = rtrim( rtrim( number_format( $alpha, 3, '.', '' ), '0' ), '.' );

			return sprintf( 'rgba(%d, %d, %d, %s)', $rgb[0], $rgb[1], $rgb[2], '' === $alpha ? '0' : $alpha );
		}

		$hex = sanitize_hex_color( $value );

		return ( null === $hex || '' === $hex ) ? null : $hex;
	}

	/**
	 * Four numeric sides plus a unit from the schema's list. The link
	 * toggle is UI state, kept so it round-trips, and ignored by
	 * everything downstream.
	 */
	private static function sanitize_dimensions( array $definition, $value ) {
		if ( ! is_array( $value ) ) {
			return null;
		}

		$clean = array();
		$any   = false;

		foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
			$side_value = isset( $value[ $side ] ) ? $value[ $side ] : '';
			if ( is_string( $side_value ) ) {
				$side_value = trim( $side_value );
			}
			if ( '' === $side_value || null === $side_value || ! is_numeric( $side_value ) ) {
				$clean[ $side ] = '';
				continue;
			}
			$clean[ $side ] = self::to_number( $side_value );
			$any            = true;
		}

		if ( ! $any ) {
			return null;
		}

		$clean['unit'] = self::sanitize_unit( $definition, $value );

		if ( array_key_exists( 'linked', $value ) ) {
			$clean['linked'] = (bool) $value['linked'];
		}

		return $clean;
	}

	/**
	 * A slider's size plus unit. The empty unit is legitimate, a
	 * unitless line height being the obvious one, so only the size may
	 * be missing.
	 */
	private static function sanitize_slider( array $definition, $value ) {
		if ( is_numeric( $value ) ) {
			$value = array( 'size' => $value );
		}
		if ( ! is_array( $value ) || ! isset( $value['size'] ) ) {
			return null;
		}

		$size = $value['size'];
		if ( is_string( $size ) ) {
			$size = trim( $size );
		}
		if ( '' === $size || null === $size || ! is_numeric( $size ) ) {
			return null;
		}

		return array(
			'size' => self::clamp( self::to_number( $size ), $definition ),
			'unit' => self::sanitize_unit( $definition, $value ),
		);
	}

	private static function sanitize_number( array $definition, $value ) {
		if ( is_string( $value ) ) {
			$value = trim( $value );
		}
		if ( '' === $value || null === $value || ! is_numeric( $value ) ) {
			return null;
		}

		return self::clamp( self::to_number( $value ), $definition );
	}

	/**
	 * A select value has to be one of the option keys. The empty value
	 * means inherit and is stored as nothing at all.
	 */
	private static function sanitize_select( array $definition, $value ) {
		if ( ! is_scalar( $value ) ) {
			return null;
		}

		$value = trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}

		/*
		 * A select flagged free_text is an open field with suggestions
		 * rather than an allow-list: a font stack, or a dash pattern.
		 * Both are open-ended by nature, and both still go through text
		 * sanitising here and the resolver's output check on the way out.
		 */
		if ( ! empty( $definition['free_text'] ) ) {
			$value = sanitize_text_field( $value );

			// 'inherit' is offered as the way to clear the field. Storing
			// it would be storing a word that means nothing, so it is
			// treated as unset, which is what the resolver would do with
			// it anyway, one layer later.
			if ( '' === $value || 'inherit' === strtolower( $value ) ) {
				return null;
			}

			return $value;
		}

		$options = isset( $definition['options'] ) && is_array( $definition['options'] )
			? $definition['options']
			: array();

		return array_key_exists( $value, $options ) ? $value : null;
	}

	/**
	 * A unit from the schema's list, falling back to the first one. An
	 * empty unit is only allowed where the schema lists it.
	 */
	private static function sanitize_unit( array $definition, $value ) {
		$units = isset( $definition['units'] ) && is_array( $definition['units'] )
			? $definition['units']
			: array();

		if ( empty( $units ) ) {
			return '';
		}

		$unit = ( is_array( $value ) && isset( $value['unit'] ) ) ? (string) $value['unit'] : null;

		return ( null !== $unit && in_array( $unit, $units, true ) ) ? $unit : (string) $units[0];
	}

	private static function clamp( $number, array $definition ) {
		if ( isset( $definition['min'] ) && $number < $definition['min'] ) {
			$number = $definition['min'];
		}
		if ( isset( $definition['max'] ) && $number > $definition['max'] ) {
			$number = $definition['max'];
		}

		return self::to_number( $number );
	}

	/**
	 * Keep integers as integers, so the stored option stays readable and
	 * a preset exports cleanly.
	 */
	private static function to_number( $value ) {
		$float = (float) $value;
		return ( (float) (int) $float === $float ) ? (int) $float : $float;
	}
}
