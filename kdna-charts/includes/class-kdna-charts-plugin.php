<?php
/**
 * Plugin bootstrap. Loads the include files, boots the pieces that need
 * booting, and owns Elementor category and asset registration.
 *
 * @package KDNA_Charts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The schema loads first, because everything else asks it what a chart
// is allowed to contain.
require_once KDNA_CHARTS_PATH . 'includes/class-kdna-charts-schema.php';
require_once KDNA_CHARTS_PATH . 'includes/class-kdna-charts-cpt.php';
require_once KDNA_CHARTS_PATH . 'includes/class-kdna-charts-data.php';
require_once KDNA_CHARTS_PATH . 'includes/class-kdna-charts-scale.php';
require_once KDNA_CHARTS_PATH . 'includes/class-kdna-charts-annotations.php';
require_once KDNA_CHARTS_PATH . 'includes/class-kdna-charts-renderer.php';
require_once KDNA_CHARTS_PATH . 'includes/class-kdna-charts-renderer-svg.php';
require_once KDNA_CHARTS_PATH . 'includes/class-kdna-charts-import.php';
require_once KDNA_CHARTS_PATH . 'includes/class-kdna-charts-admin.php';
require_once KDNA_CHARTS_PATH . 'includes/class-kdna-charts-editor.php';
require_once KDNA_CHARTS_PATH . 'includes/class-kdna-charts-style-schema.php';
require_once KDNA_CHARTS_PATH . 'includes/class-kdna-charts-style-resolver.php';
require_once KDNA_CHARTS_PATH . 'includes/class-kdna-charts-style-admin.php';

KDNA_Charts_CPT::init();
KDNA_Charts_Scale_Debug::init();

/*
 * The style engine boots outside is_admin(), and both halves have to.
 *
 * Style_Admin registers REST routes on rest_api_init, and a /wp-json/
 * request is not an admin request: inside the guard the settings page
 * would render and then have nothing to save through.
 *
 * Style_Resolver's invalidation watches option and meta writes, which
 * can come from WP-CLI, an importer or another plugin, none of which are
 * admin requests either. A cached style string with no way to clear it
 * is worse than the cache is worth.
 */
KDNA_Charts_Style_Admin::init();
KDNA_Charts_Style_Resolver::register_invalidation();

if ( is_admin() ) {
	KDNA_Charts_Admin::init();
	KDNA_Charts_Import::init();
	KDNA_Charts_Editor::init();
}

class KDNA_Charts_Plugin {

	const FRONTEND_STYLE_HANDLE  = 'kdna-charts';
	const FRONTEND_SCRIPT_HANDLE = 'kdna-charts';
	const EDITOR_STYLE_HANDLE    = 'kdna-charts-editor';
	const EDITOR_SCRIPT_HANDLE   = 'kdna-charts-editor';

	/*
	 * Elementor category. The brief places the widget in KDNA Tools, a
	 * shared category rather than a per plugin one, so KDNA Tables and
	 * KDNA Charts sit together in the widget panel.
	 */
	const CATEGORY_SLUG  = 'kdna-tools';
	const CATEGORY_TITLE = 'KDNA Tools';

	public static function load_textdomain() {
		load_plugin_textdomain(
			'kdna-charts',
			false,
			dirname( plugin_basename( KDNA_CHARTS_FILE ) ) . '/languages'
		);
	}

	/**
	 * Runs on activation. Registers the post type first so its rewrite
	 * state is known, then flushes. The post type is private, so this is
	 * cheap insurance rather than a requirement.
	 */
	public static function activate() {
		KDNA_Charts_CPT::register_post_type();
		flush_rewrite_rules();
	}

	public static function deactivate() {
		flush_rewrite_rules();
	}

	/**
	 * Adds the KDNA Tools category unless another KDNA plugin has already
	 * registered it. Elementor keeps categories in an ordered map, so
	 * adding the same key twice would overwrite the first title.
	 */
	public static function register_category( $elements_manager ) {
		if ( method_exists( $elements_manager, 'get_categories' ) ) {
			$existing = $elements_manager->get_categories();
			if ( is_array( $existing ) && isset( $existing[ self::CATEGORY_SLUG ] ) ) {
				return;
			}
		}

		$elements_manager->add_category(
			self::CATEGORY_SLUG,
			array(
				'title' => esc_html__( 'KDNA Tools', 'kdna-charts' ),
				'icon'  => 'eicon-chart-line',
			)
		);
	}

	/**
	 * The widget class arrives at Stage 11. The hook is wired from Stage 1
	 * because Elementor hook registration has to happen at file load time,
	 * so the guard here is what keeps the earlier stages quiet.
	 */
	public static function register_widgets( $widgets_manager ) {
		$widget_file = KDNA_CHARTS_PATH . 'includes/class-kdna-charts-widget.php';
		if ( ! file_exists( $widget_file ) ) {
			return;
		}
		require_once $widget_file;
		if ( ! class_exists( 'KDNA_Charts_Widget' ) ) {
			return;
		}
		$widgets_manager->register( new KDNA_Charts_Widget() );
	}

	/*
	 * Frontend CSS is registered rather than enqueued, so the widget's
	 * get_style_depends() and the shortcode can pull it in only on pages
	 * that actually render a chart. The stylesheets themselves land with
	 * the renderer stages, hence the file_exists guard.
	 */
	public static function register_frontend_styles() {
		self::register_style_if_present( self::FRONTEND_STYLE_HANDLE, 'assets/css/kdna-charts.css' );
	}

	public static function register_frontend_scripts() {
		self::register_script_if_present( self::FRONTEND_SCRIPT_HANDLE, 'assets/js/kdna-charts.js' );
	}

	public static function enqueue_editor_styles() {
		self::enqueue_style_if_present( self::EDITOR_STYLE_HANDLE, 'assets/css/kdna-editor.css' );
		// The editor preview iframe needs the frontend stylesheet too, so the
		// widget renders with parity inside the Elementor canvas.
		self::enqueue_style_if_present( self::FRONTEND_STYLE_HANDLE, 'assets/css/kdna-charts.css' );
	}

	public static function enqueue_editor_scripts() {
		$relative = 'assets/js/kdna-editor.js';
		if ( ! file_exists( KDNA_CHARTS_PATH . $relative ) ) {
			return;
		}
		wp_enqueue_script(
			self::EDITOR_SCRIPT_HANDLE,
			KDNA_CHARTS_URL . $relative,
			array( 'jquery', 'elementor-editor' ),
			KDNA_CHARTS_VERSION,
			true
		);
	}

	/*
	 * --------------------------------------------------------------------
	 * Asset helpers
	 * --------------------------------------------------------------------
	 */

	private static function register_style_if_present( $handle, $relative ) {
		if ( ! file_exists( KDNA_CHARTS_PATH . $relative ) ) {
			return;
		}
		wp_register_style( $handle, KDNA_CHARTS_URL . $relative, array(), KDNA_CHARTS_VERSION );
	}

	private static function enqueue_style_if_present( $handle, $relative ) {
		if ( ! file_exists( KDNA_CHARTS_PATH . $relative ) ) {
			return;
		}
		wp_enqueue_style( $handle, KDNA_CHARTS_URL . $relative, array(), KDNA_CHARTS_VERSION );
	}

	private static function register_script_if_present( $handle, $relative ) {
		if ( ! file_exists( KDNA_CHARTS_PATH . $relative ) ) {
			return;
		}
		wp_register_script( $handle, KDNA_CHARTS_URL . $relative, array(), KDNA_CHARTS_VERSION, true );
	}
}
