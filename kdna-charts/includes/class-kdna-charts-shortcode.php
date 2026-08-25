<?php
/**
 * [kdna_chart] shortcode (Stage 10) for non-Elementor contexts: the classic
 * editor, theme templates, the Gutenberg shortcode block, JetEngine meta
 * fields, Elementor popups, anywhere.
 *
 * Attributes:
 *   id           int     Required. The chart to render.
 *   engine       string  svg | chartjs. Default: the global default engine.
 *   height       string  A CSS length that floors the figure height.
 *   style_id     int     Optional. Borrow another chart's style overrides.
 *   show_source  string  yes | no. Default yes.
 *   show_caption string  yes | no. Default yes.
 *   animate      string  yes | no. Default: the global default, off.
 *   a11y_table   string  yes | no. Adds a visually hidden data table.
 *   thin_below   string  px width below which alternate x labels drop.
 *
 * Anything unrecognised falls back to the default rather than failing.
 *
 * ── How it renders ────────────────────────────────────────────────────
 *
 * The drawing is delegated to the shared renderer factory
 * (KDNA_Charts_Renderer::create), so the shortcode, the Elementor widget and
 * the admin preview all produce the exact same markup, and the renderer
 * resolves the global and per-chart style layers itself and paints them onto
 * the figure as an inline custom-property block. An inline attribute works
 * wherever the shortcode lands — inside a JetEngine repeater has_shortcode()
 * cannot see it — and because the custom properties inherit, one block on the
 * figure reaches every mark drawn inside it.
 *
 * @package KDNA_Charts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KDNA_Charts_Shortcode {

	/** Plugin behaviour settings, separate from the style values. */
	const SETTINGS_OPTION = 'kdna_charts_settings';

	/** Front-end enhancement script + stylesheet handles (the plugin's own). */
	const FRONTEND_SCRIPT_HANDLE = 'kdna-charts';
	const FRONTEND_STYLE_HANDLE  = 'kdna-charts';

	const VALID_ENGINES = array( 'svg', 'chartjs' );

	public static function init() {
		add_shortcode( 'kdna_chart', array( __CLASS__, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	/* ─── Assets ────────────────────────────────────────────────────── */

	public static function register_assets() {
		self::register_frontend_script();
		self::register_frontend_style();

		if ( self::always_load_css() || self::post_has_shortcode() ) {
			wp_enqueue_style( self::FRONTEND_STYLE_HANDLE );
		}
	}

	private static function always_load_css() {
		$settings = get_option( self::SETTINGS_OPTION, array() );
		$enabled  = true;
		if ( is_array( $settings ) && array_key_exists( 'always_load_shortcode_css', $settings ) ) {
			$enabled = ! empty( $settings['always_load_shortcode_css'] );
		}
		/**
		 * Filter whether the chart stylesheet loads on every page.
		 *
		 * @param bool $enabled Current setting.
		 */
		return (bool) apply_filters( 'kdna_charts_always_load_shortcode_css', $enabled );
	}

	private static function post_has_shortcode() {
		if ( ! is_singular() ) {
			return false;
		}
		$post = get_post();
		if ( ! $post instanceof WP_Post || '' === $post->post_content ) {
			return false;
		}
		return has_shortcode( $post->post_content, 'kdna_chart' );
	}

	/**
	 * Register the front-end stylesheet if some earlier hook has not. This is
	 * the plugin's kdna-charts.css, the single stylesheet that reads every
	 * custom property the style engine writes.
	 */
	public static function register_frontend_style() {
		if ( wp_style_is( self::FRONTEND_STYLE_HANDLE, 'registered' ) ) {
			return;
		}
		if ( file_exists( KDNA_CHARTS_PATH . 'assets/css/kdna-charts.css' ) ) {
			wp_register_style(
				self::FRONTEND_STYLE_HANDLE,
				KDNA_CHARTS_URL . 'assets/css/kdna-charts.css',
				array(),
				KDNA_CHARTS_VERSION
			);
		}
	}

	/**
	 * Register the front-end enhancement script (draw-in animation, mobile
	 * label thinning, screen-reader table). GSAP is optional and detected at
	 * runtime.
	 */
	public static function register_frontend_script() {
		if ( wp_script_is( self::FRONTEND_SCRIPT_HANDLE, 'registered' ) ) {
			return;
		}
		if ( file_exists( KDNA_CHARTS_PATH . 'assets/js/kdna-charts.js' ) ) {
			wp_register_script(
				self::FRONTEND_SCRIPT_HANDLE,
				KDNA_CHARTS_URL . 'assets/js/kdna-charts.js',
				array(),
				KDNA_CHARTS_VERSION,
				true
			);
		}
	}

	/**
	 * Enqueue the front-end enhancement script when a chart needs it: any SVG
	 * chart (label thinning), any animated chart, or any chart with the
	 * screen-reader table. Public so the widget can call it from its render.
	 * Also enqueues the stylesheet, for the late (footer) render case.
	 */
	public static function enqueue_frontend_script( $animate = false, $engine = 'svg', $a11y_table = false ) {
		self::register_frontend_style();
		wp_enqueue_style( self::FRONTEND_STYLE_HANDLE );

		if ( 'svg' !== $engine && ! $animate && ! $a11y_table ) {
			return;
		}
		self::register_frontend_script();
		if ( wp_script_is( self::FRONTEND_SCRIPT_HANDLE, 'registered' ) ) {
			wp_enqueue_script( self::FRONTEND_SCRIPT_HANDLE );
		}
	}

	/* ─── Render ────────────────────────────────────────────────────── */

	public static function render( $atts ) {
		$given = is_array( $atts ) ? $atts : array();

		$atts = shortcode_atts(
			array(
				'id'           => 0,
				'engine'       => '',
				'height'       => '',
				'style_id'     => 0,
				'show_source'  => 'yes',
				'show_caption' => 'yes',
				'animate'      => '',
				'a11y_table'   => '',
				'thin_below'   => '',
			),
			$atts,
			'kdna_chart'
		);

		$chart_id = (int) $atts['id'];
		if ( $chart_id <= 0 || ! self::is_renderable_chart( $chart_id ) ) {
			return '';
		}

		if ( ! class_exists( 'KDNA_Charts_Renderer' ) || ! method_exists( 'KDNA_Charts_Renderer', 'create' ) ) {
			return '';
		}

		$definition = self::get_definition( $chart_id );
		if ( empty( $definition ) ) {
			return '';
		}

		// engine: an explicit attribute wins, else the definition's own, else
		// the global default. An unknown value falls back to the default.
		if ( array_key_exists( 'engine', $given ) && '' !== trim( (string) $atts['engine'] ) ) {
			$definition['engine'] = self::one_of( $atts['engine'], self::VALID_ENGINES, self::default_engine() );
		}
		$engine = self::one_of( isset( $definition['engine'] ) ? $definition['engine'] : '', self::VALID_ENGINES, self::default_engine() );

		$height       = self::sanitize_length( $atts['height'] );
		$show_source  = self::is_yes( $atts['show_source'] );
		$show_caption = self::is_yes( $atts['show_caption'] );
		$animate      = array_key_exists( 'animate', $given )
			? self::is_yes( $atts['animate'] )
			: self::default_animate();
		$a11y_table   = self::is_yes( $atts['a11y_table'] );
		$thin_below   = ( '' !== $atts['thin_below'] && is_numeric( $atts['thin_below'] ) )
			? (int) $atts['thin_below']
			: self::default_thin_below();

		self::enqueue_frontend_script( $animate, $engine, $a11y_table );

		$classes = array( 'kdna-chart--shortcode' );
		if ( $animate ) {
			$classes[] = 'kdna-chart--animate';
		}

		$atts_out = array(
			'data-animate'    => $animate ? 'yes' : 'no',
			'data-a11y-table' => $a11y_table ? 'yes' : 'no',
			'data-thin-below' => (string) $thin_below,
		);
		if ( '' !== $height ) {
			// A CSS length that floors the figure height, alongside the
			// resolved custom properties the renderer paints.
			$atts_out['style'] = 'min-height: ' . $height . ';';
		}

		$args = array(
			'chart_id'     => $chart_id,
			'show_caption' => $show_caption,
			'show_source'  => $show_source,
			'classes'      => $classes,
			'a11y_table'   => $a11y_table,
			'animate'      => $animate,
			'atts'         => $atts_out,
		);

		// style_id borrows another chart's overrides as the top style layer,
		// while this chart's own data is what renders. A bad id is ignored.
		$style_id = (int) $atts['style_id'];
		if ( $style_id > 0 && $style_id !== $chart_id && self::is_renderable_chart( $style_id ) ) {
			$borrowed = self::chart_overrides( $style_id );
			if ( ! empty( $borrowed ) ) {
				$args['style'] = $borrowed;
			}
		}

		$renderer = KDNA_Charts_Renderer::create( $definition, $args );
		return $renderer ? (string) $renderer->render() : '';
	}

	/* ─── Chart data ────────────────────────────────────────────────── */

	public static function get_definition( $chart_id ) {
		if ( class_exists( 'KDNA_Charts_Data' ) && method_exists( 'KDNA_Charts_Data', 'get_definition_for_render' ) ) {
			$def = KDNA_Charts_Data::get_definition_for_render( (int) $chart_id );
			if ( is_array( $def ) && ! empty( $def ) ) {
				return $def;
			}
		}
		if ( class_exists( 'KDNA_Charts_CPT' ) && method_exists( 'KDNA_Charts_CPT', 'get_definition' ) ) {
			return (array) KDNA_Charts_CPT::get_definition( (int) $chart_id );
		}
		return array();
	}

	/** A chart's own style overrides, always an array. */
	public static function chart_overrides( $chart_id ) {
		if ( class_exists( 'KDNA_Charts_CPT' ) && method_exists( 'KDNA_Charts_CPT', 'get_json_meta' ) ) {
			$v = KDNA_Charts_CPT::get_json_meta( (int) $chart_id, KDNA_Charts_CPT::META_STYLE );
			return is_array( $v ) ? $v : array();
		}
		return array();
	}

	public static function chart_type( $chart_id ) {
		if ( class_exists( 'KDNA_Charts_CPT' ) && method_exists( 'KDNA_Charts_CPT', 'get_type' ) ) {
			return (string) KDNA_Charts_CPT::get_type( $chart_id );
		}
		return (string) get_post_meta( (int) $chart_id, '_kdna_chart_type', true );
	}

	/* ─── Settings-backed defaults ──────────────────────────────────── */

	public static function default_engine() {
		if ( class_exists( 'KDNA_Charts_Data' ) && method_exists( 'KDNA_Charts_Data', 'default_engine' ) ) {
			$engine = (string) KDNA_Charts_Data::default_engine();
			if ( in_array( $engine, self::VALID_ENGINES, true ) ) {
				return $engine;
			}
		}
		return 'svg';
	}

	public static function default_animate() {
		$settings = get_option( self::SETTINGS_OPTION, array() );
		$enabled  = is_array( $settings ) && ! empty( $settings['default_animate'] );
		/**
		 * Filter whether charts animate by default.
		 *
		 * @param bool $enabled Default false.
		 */
		return (bool) apply_filters( 'kdna_charts_default_animate', $enabled );
	}

	public static function default_thin_below() {
		$settings = get_option( self::SETTINGS_OPTION, array() );
		$below    = 480;
		if ( is_array( $settings ) && ! empty( $settings['thin_below'] ) && is_numeric( $settings['thin_below'] ) ) {
			$below = (int) $settings['thin_below'];
		}
		/**
		 * Filter the default width below which axis labels are thinned.
		 *
		 * @param int $below Width in pixels.
		 */
		return (int) apply_filters( 'kdna_charts_thin_below', $below );
	}

	/* ─── Attribute validation ──────────────────────────────────────── */

	public static function one_of( $value, array $allowed, $default ) {
		$value = strtolower( trim( (string) $value ) );
		return in_array( $value, $allowed, true ) ? $value : $default;
	}

	private static function is_yes( $value ) {
		$value = strtolower( trim( (string) $value ) );
		return in_array( $value, array( 'yes', 'y', 'true', '1', 'on' ), true );
	}

	private static function sanitize_length( $value ) {
		$value = strtolower( trim( (string) $value ) );
		if ( '' === $value ) {
			return '';
		}
		if ( is_numeric( $value ) ) {
			$number = (float) $value;
			return $number > 0 ? self::number( $number ) . 'px' : '';
		}
		if ( preg_match( '/^(\d+(?:\.\d+)?)(px|rem|em|vh|%)$/', $value, $m ) ) {
			return (float) $m[1] > 0 ? self::number( (float) $m[1] ) . $m[2] : '';
		}
		return '';
	}

	private static function number( $value ) {
		$float = (float) $value;
		if ( (float) (int) $float === $float ) {
			return (string) (int) $float;
		}
		return rtrim( rtrim( number_format( $float, 4, '.', '' ), '0' ), '.' );
	}

	public static function is_renderable_chart( $post_id ) {
		if ( class_exists( 'KDNA_Charts_Data' ) && method_exists( 'KDNA_Charts_Data', 'chart_exists' ) ) {
			return (bool) KDNA_Charts_Data::chart_exists( (int) $post_id );
		}
		$post = get_post( (int) $post_id );
		return $post instanceof WP_Post
			&& self::post_type() === $post->post_type
			&& 'publish' === $post->post_status;
	}

	public static function post_type() {
		if ( class_exists( 'KDNA_Charts_CPT' ) ) {
			return KDNA_Charts_CPT::POST_TYPE;
		}
		return 'kdna_chart';
	}
}
