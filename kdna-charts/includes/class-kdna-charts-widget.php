<?php
/**
 * KDNA Chart Elementor widget (Stage 11).
 *
 * Registers into the shared KDNA Tools category, offers a Content tab that
 * picks a chart from the library and toggles its parts, and a Style tab whose
 * sections and controls are generated from KDNA_Charts_Style_Schema — the
 * same single source of truth the global styling page and the resolver read.
 *
 * ── The cascade, and why the widget resolves in PHP ───────────────────
 *
 * The widget is the fourth and top layer of the style cascade:
 *
 *   stylesheet fallback -> global option -> per-chart overrides -> widget
 *
 * Rather than write CSS custom properties through Elementor selectors (which
 * only a canvas-blind SVG chart could read), the widget reads its Style-tab
 * values in render() and hands them to the shared renderer as the `style`
 * argument, in the same storage shape the schema uses. The renderer merges
 * that layer over the global and per-chart layers and paints the result on
 * the figure for the SVG engine, and bakes it into the config for the
 * Chart.js engine. One code path, both engines, and a control the user
 * leaves untouched simply is not in the array, so the layer beneath shows
 * through — which is what "inherit" means everywhere in this plugin.
 *
 * ── Markup rules ──────────────────────────────────────────────────────
 *
 * has_widget_inner_wrapper() returns false when the e_optimized_markup
 * experiment is active, so the widget output is the single figure the shared
 * renderer produces, with no .elementor-widget-container around it. No
 * selector anywhere references that legacy class.
 *
 * @package KDNA_Charts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KDNA_Charts_Widget extends \Elementor\Widget_Base {

	/** Prefix for every generated Style-tab control id. */
	const CTRL_PREFIX = 'kc_';

	/** Sections the Chart.js engine cannot honour (the annotation layer). */
	const ANNOTATION_SECTIONS = array( 'markers', 'callouts', 'notes' );

	public function get_name() {
		return 'kdna-chart';
	}

	public function get_title() {
		return esc_html__( 'KDNA Chart', 'kdna-charts' );
	}

	public function get_icon() {
		return 'eicon-line-chart';
	}

	public function get_categories() {
		return array( KDNA_Charts_Plugin::CATEGORY_SLUG );
	}

	public function get_keywords() {
		return array( 'chart', 'graph', 'plot', 'data', 'kdna' );
	}

	/**
	 * The shared stylesheet carries the var() consumers every SVG mark reads,
	 * so the widget depends on it. Registered here in case the front-end
	 * enqueue pass has not run for this request.
	 */
	public function get_style_depends() {
		if ( class_exists( 'KDNA_Charts_Shortcode' ) ) {
			KDNA_Charts_Shortcode::register_frontend_style();
			return array( KDNA_Charts_Shortcode::FRONTEND_STYLE_HANDLE );
		}
		return array();
	}

	/*
	 * Under the e_optimized_markup experiment Elementor drops the
	 * .elementor-widget-container inner wrapper, so the widget output is the
	 * single figure the renderer produces and no CSS targets the legacy
	 * container class.
	 */
	public function has_widget_inner_wrapper(): bool {
		if (
			class_exists( '\Elementor\Plugin' )
			&& isset( \Elementor\Plugin::$instance->experiments )
			&& \Elementor\Plugin::$instance->experiments->is_feature_active( 'e_optimized_markup' )
		) {
			return false;
		}
		return true;
	}

	protected function register_controls() {
		$this->register_content_controls();
		$this->register_style_controls();
	}

	/* ─── Content tab ───────────────────────────────────────────────── */

	protected function register_content_controls() {
		$this->start_controls_section(
			'section_chart',
			array(
				'label' => esc_html__( 'Chart', 'kdna-charts' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'chart_id',
			array(
				'label'       => esc_html__( 'Chart', 'kdna-charts' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'options'     => $this->get_chart_options(),
				'default'     => '',
				'label_block' => true,
				'description' => esc_html__( 'Pick a chart from your library. Edit it once and every widget using it updates.', 'kdna-charts' ),
			)
		);

		$this->add_control(
			'engine',
			array(
				'label'       => esc_html__( 'Rendering Engine', 'kdna-charts' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'default'     => '',
				'options'     => array(
					''        => esc_html__( 'Site default', 'kdna-charts' ),
					'svg'     => esc_html__( 'SVG (editorial)', 'kdna-charts' ),
					'chartjs' => esc_html__( 'Chart.js (interactive)', 'kdna-charts' ),
				),
				'description' => esc_html__( 'SVG carries the full annotation layer. Chart.js is for large datasets and hover tooltips.', 'kdna-charts' ),
			)
		);

		$this->add_control(
			'show_caption',
			array(
				'label'        => esc_html__( 'Show Caption', 'kdna-charts' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Show', 'kdna-charts' ),
				'label_off'    => esc_html__( 'Hide', 'kdna-charts' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_source',
			array(
				'label'        => esc_html__( 'Show Source Line', 'kdna-charts' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Show', 'kdna-charts' ),
				'label_off'    => esc_html__( 'Hide', 'kdna-charts' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => esc_html__( 'An editorial chart without attribution is an assertion.', 'kdna-charts' ),
			)
		);

		$this->add_control(
			'animate',
			array(
				'label'        => esc_html__( 'Animate on Scroll', 'kdna-charts' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'On', 'kdna-charts' ),
				'label_off'    => esc_html__( 'Off', 'kdna-charts' ),
				'return_value' => 'yes',
				'default'      => '',
				'description'  => esc_html__( 'Draws the chart in as it scrolls into view, honouring reduced-motion settings.', 'kdna-charts' ),
			)
		);

		$this->add_control(
			'a11y_table',
			array(
				'label'        => esc_html__( 'Screen-reader Data Table', 'kdna-charts' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'On', 'kdna-charts' ),
				'label_off'    => esc_html__( 'Off', 'kdna-charts' ),
				'return_value' => 'yes',
				'default'      => '',
				'description'  => esc_html__( 'Adds a visually hidden table of the chart data for assistive technology.', 'kdna-charts' ),
			)
		);

		$this->add_control(
			'thin_below',
			array(
				'label'       => esc_html__( 'Thin Axis Labels Below (px)', 'kdna-charts' ),
				'type'        => \Elementor\Controls_Manager::NUMBER,
				'min'         => 0,
				'max'         => 2000,
				'step'        => 10,
				'placeholder' => (string) ( class_exists( 'KDNA_Charts_Shortcode' ) ? KDNA_Charts_Shortcode::default_thin_below() : 480 ),
				'description' => esc_html__( 'Below this width every other x-axis label is dropped so the axis stays legible on a phone. Leave blank for the site default.', 'kdna-charts' ),
			)
		);

		/*
		 * Editor-only Preview State. render_type 'none' keeps Elementor from
		 * regenerating CSS for it, and render() reads it ONLY in edit mode, so
		 * it never touches the published page.
		 */
		$this->add_control(
			'preview_state',
			array(
				'label'       => esc_html__( 'Preview State (editor only)', 'kdna-charts' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'default'     => 'default',
				'options'     => array(
					'default' => esc_html__( 'Default', 'kdna-charts' ),
					'hover'   => esc_html__( 'Hover', 'kdna-charts' ),
					'animate' => esc_html__( 'Animated in', 'kdna-charts' ),
				),
				'render_type' => 'none',
				'description' => esc_html__( 'Affects only this editor preview, never the published page.', 'kdna-charts' ),
			)
		);

		$this->add_control(
			'manage_charts_link',
			array(
				'type'            => \Elementor\Controls_Manager::RAW_HTML,
				'raw'             => sprintf(
					'<a href="%1$s" target="_blank" rel="noopener">%2$s</a>',
					esc_url( admin_url( 'edit.php?post_type=' . KDNA_Charts_Shortcode::post_type() ) ),
					esc_html__( 'Manage charts', 'kdna-charts' )
				),
				'content_classes' => 'elementor-descriptor',
			)
		);

		$this->end_controls_section();
	}

	/** Published charts as an id => title option list. */
	private function get_chart_options() {
		$options = array( '' => esc_html__( '— Select a chart —', 'kdna-charts' ) );

		$posts = get_posts(
			array(
				'post_type'        => KDNA_Charts_Shortcode::post_type(),
				'post_status'      => 'publish',
				'numberposts'      => 100,
				'orderby'          => 'modified',
				'order'            => 'DESC',
				'suppress_filters' => false,
			)
		);

		foreach ( (array) $posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			$title = trim( (string) $post->post_title );
			/* translators: %d: chart post id. */
			$options[ (string) $post->ID ] = '' === $title ? sprintf( esc_html__( 'Chart %d', 'kdna-charts' ), $post->ID ) : $title;
		}

		return $options;
	}

	/* ─── Style tab, generated from the schema ──────────────────────── */

	protected function register_style_controls() {
		$sections = KDNA_Charts_Style_Schema::get_sections();
		$grouped  = KDNA_Charts_Style_Schema::get_by_section();

		foreach ( $sections as $section_key => $section_label ) {
			$controls = isset( $grouped[ $section_key ] ) ? $grouped[ $section_key ] : array();
			if ( empty( $controls ) ) {
				continue;
			}

			$this->start_controls_section(
				'style_' . $section_key,
				array(
					'label' => $section_label,
					'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
				)
			);

			/*
			 * The annotation layer (markers, callouts, notes) is SVG-only.
			 * When the engine is Chart.js these controls are hidden behind a
			 * note explaining why, rather than removed: Elementor keeps a
			 * hidden control's stored value, so switching back to SVG restores
			 * the chart exactly. The controls carry a matching condition so
			 * they reappear the moment the engine is SVG again.
			 */
			$is_annotation = in_array( $section_key, self::ANNOTATION_SECTIONS, true );
			$condition     = $is_annotation ? array( 'engine!' => 'chartjs' ) : array();

			if ( $is_annotation ) {
				$this->add_control(
					self::CTRL_PREFIX . $section_key . '_chartjs_note',
					array(
						'type'            => \Elementor\Controls_Manager::RAW_HTML,
						'raw'             => esc_html__( 'The Chart.js engine cannot draw the editorial annotation layer, so these controls do not apply while it is selected. Your values are kept, and return when you switch back to SVG.', 'kdna-charts' ),
						'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
						'condition'       => array( 'engine' => 'chartjs' ),
					)
				);
			}

			$last_group = null;
			foreach ( $controls as $key => $def ) {
				$group = isset( $def['group'] ) ? (string) $def['group'] : '';
				if ( '' !== $group && $group !== $last_group ) {
					$heading = array(
						'label'     => $group,
						'type'      => \Elementor\Controls_Manager::HEADING,
						'separator' => 'before',
					);
					if ( ! empty( $condition ) ) {
						$heading['condition'] = $condition;
					}
					$this->add_control( self::CTRL_PREFIX . $key . '_group', $heading );
					$last_group = $group;
				}

				$this->add_leaf_control( $key, $def, $condition );
			}

			$this->end_controls_section();
		}
	}

	/**
	 * One schema control mapped to its Elementor control. No `selectors`: the
	 * value is read in render() and handed to the renderer, so it styles both
	 * engines rather than only the SVG one. Responsive controls use
	 * add_responsive_control, so every dimensional control is per-breakpoint.
	 */
	private function add_leaf_control( $key, array $def, array $condition ) {
		$id         = self::CTRL_PREFIX . $key;
		$type       = isset( $def['type'] ) ? $def['type'] : '';
		$label      = isset( $def['label'] ) ? $def['label'] : $key;
		$responsive = ! empty( $def['responsive'] );

		$args = array( 'label' => $label );
		if ( ! empty( $def['description'] ) ) {
			$args['description'] = $def['description'];
		}
		if ( ! empty( $condition ) ) {
			$args['condition'] = $condition;
		}

		switch ( $type ) {
			case 'colour':
				$args['type'] = \Elementor\Controls_Manager::COLOR;
				break;

			case 'slider':
				$args['type']       = \Elementor\Controls_Manager::SLIDER;
				$args['size_units'] = $this->unit_map( $def );
				$args['range']      = $this->slider_range( $def, $args['size_units'] );
				break;

			case 'dimensions':
				$args['type']       = \Elementor\Controls_Manager::DIMENSIONS;
				$args['size_units'] = $this->unit_map( $def );
				break;

			case 'number':
				$args['type'] = \Elementor\Controls_Manager::NUMBER;
				$args['min']  = isset( $def['min'] ) ? $def['min'] : 0;
				$args['max']  = isset( $def['max'] ) ? $def['max'] : 100;
				$args['step'] = isset( $def['step'] ) ? $def['step'] : 1;
				break;

			case 'select':
				if ( ! empty( $def['free_text'] ) ) {
					$args['type']        = \Elementor\Controls_Manager::TEXT;
					$args['placeholder'] = isset( $def['placeholder'] ) ? (string) $def['placeholder'] : '';
					break;
				}
				$options = isset( $def['options'] ) && is_array( $def['options'] ) ? $def['options'] : array();
				if ( ! array_key_exists( '', $options ) ) {
					$options = array( '' => esc_html__( '— Default —', 'kdna-charts' ) ) + $options;
				}
				$args['type']    = \Elementor\Controls_Manager::SELECT;
				$args['default'] = '';
				$args['options'] = $options;
				break;

			default:
				return;
		}

		$responsive ? $this->add_responsive_control( $id, $args ) : $this->add_control( $id, $args );
	}

	/**
	 * Map a control's schema units to Elementor size_units, falling back to
	 * px when the schema lists none Elementor's slider can represent.
	 */
	private function unit_map( array $def ) {
		$allowed = array( 'px', '%', 'em', 'rem', 'vh', 'vw' );
		$units   = array();
		$source  = isset( $def['units'] ) && is_array( $def['units'] ) ? $def['units'] : array();
		foreach ( $source as $unit ) {
			if ( in_array( $unit, $allowed, true ) ) {
				$units[] = $unit;
			}
		}
		return empty( $units ) ? array( 'px' ) : $units;
	}

	/** A slider range keyed per unit, from the schema's min/max/step. */
	private function slider_range( array $def, array $units ) {
		$range = array(
			'min'  => isset( $def['min'] ) ? $def['min'] : 0,
			'max'  => isset( $def['max'] ) ? $def['max'] : 100,
			'step' => isset( $def['step'] ) ? $def['step'] : 1,
		);
		$out = array();
		foreach ( $units as $unit ) {
			$out[ $unit ] = $range;
		}
		return $out;
	}

	/* ─── Render ────────────────────────────────────────────────────── */

	protected function render() {
		try {
			$settings = $this->get_settings_for_display();
		} catch ( \Throwable $e ) {
			$settings = array();
		}
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$chart_id = isset( $settings['chart_id'] ) ? (int) $settings['chart_id'] : 0;

		if ( $chart_id <= 0 || ! KDNA_Charts_Shortcode::is_renderable_chart( $chart_id ) ) {
			$this->render_notice(
				0 === $chart_id
					? esc_html__( 'Select a chart from the dropdown.', 'kdna-charts' )
					: esc_html__( 'The selected chart no longer exists.', 'kdna-charts' )
			);
			return;
		}

		if ( ! class_exists( 'KDNA_Charts_Renderer' ) || ! method_exists( 'KDNA_Charts_Renderer', 'create' ) ) {
			$this->render_notice( esc_html__( 'The chart renderer is unavailable.', 'kdna-charts' ) );
			return;
		}

		$definition = KDNA_Charts_Shortcode::get_definition( $chart_id );
		if ( empty( $definition ) ) {
			$this->render_notice( esc_html__( 'This chart has no data yet.', 'kdna-charts' ) );
			return;
		}

		// engine: an explicit choice wins, else the site default.
		$engine = isset( $settings['engine'] ) ? (string) $settings['engine'] : '';
		if ( ! in_array( $engine, KDNA_Charts_Shortcode::VALID_ENGINES, true ) ) {
			$engine = KDNA_Charts_Shortcode::default_engine();
		}
		$definition['engine'] = $engine;

		$show_caption = ! isset( $settings['show_caption'] ) || 'yes' === $settings['show_caption'];
		$show_source  = ! isset( $settings['show_source'] ) || 'yes' === $settings['show_source'];
		$animate      = isset( $settings['animate'] ) && 'yes' === $settings['animate'];
		$a11y_table   = isset( $settings['a11y_table'] ) && 'yes' === $settings['a11y_table'];
		$thin_below   = ( isset( $settings['thin_below'] ) && is_numeric( $settings['thin_below'] ) && '' !== $settings['thin_below'] )
			? (int) $settings['thin_below']
			: KDNA_Charts_Shortcode::default_thin_below();

		KDNA_Charts_Shortcode::enqueue_frontend_script( $animate, $engine, $a11y_table );

		$classes = array( 'kdna-chart--widget' );
		if ( $animate ) {
			$classes[] = 'kdna-chart--animate';
		}

		$atts = array(
			'data-animate'    => $animate ? 'yes' : 'no',
			'data-a11y-table' => $a11y_table ? 'yes' : 'no',
			'data-thin-below' => (string) $thin_below,
		);

		if ( $this->is_edit_mode() ) {
			$state = isset( $settings['preview_state'] ) ? (string) $settings['preview_state'] : 'default';
			if ( in_array( $state, array( 'default', 'hover', 'animate' ), true ) ) {
				$atts['data-preview-state'] = $state;
			}
		}

		$args = array(
			'chart_id'     => $chart_id,
			'show_caption' => $show_caption,
			'show_source'  => $show_source,
			'classes'      => $classes,
			'a11y_table'   => $a11y_table,
			'animate'      => $animate,
			'atts'         => $atts,
		);

		// The widget's Style-tab values become the fourth cascade layer, read
		// here in the schema's storage shape and merged by the renderer over
		// the global and per-chart layers.
		$overrides = $this->collect_style_overrides( $settings );
		if ( ! empty( $overrides ) ) {
			$args['style'] = $overrides;
		}

		$renderer = KDNA_Charts_Renderer::create( $definition, $args );
		if ( ! $renderer ) {
			$this->render_notice( esc_html__( 'This chart could not be drawn.', 'kdna-charts' ) );
			return;
		}

		echo $renderer->render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderer escapes every attribute and value.
	}

	/**
	 * Read the Style-tab settings into the schema's storage shape, so the
	 * resolver can merge them exactly as it merges the global option and the
	 * per-chart overrides. A control left unset is omitted, never stored
	 * empty, so the layer beneath shows through.
	 */
	private function collect_style_overrides( array $settings ) {
		$out    = array();
		$schema = KDNA_Charts_Style_Schema::get();

		foreach ( $schema as $key => $def ) {
			$id   = self::CTRL_PREFIX . $key;
			$type = isset( $def['type'] ) ? $def['type'] : '';

			if ( ! empty( $def['responsive'] ) ) {
				$value = array();
				foreach ( KDNA_Charts_Style_Schema::DEVICES as $device ) {
					$setting_key = ( 'desktop' === $device ) ? $id : $id . '_' . $device;
					$leaf        = isset( $settings[ $setting_key ] ) ? $this->leaf_value( $type, $settings[ $setting_key ] ) : null;
					if ( null !== $leaf ) {
						$value[ $device ] = $leaf;
					}
				}
				if ( ! empty( $value ) ) {
					$out[ $key ] = $value;
				}
				continue;
			}

			$leaf = isset( $settings[ $id ] ) ? $this->leaf_value( $type, $settings[ $id ] ) : null;
			if ( null !== $leaf ) {
				$out[ $key ] = $leaf;
			}
		}

		return $out;
	}

	/**
	 * One Elementor control value in the schema's storage shape, or null when
	 * it contributes nothing. Elementor's slider and dimensions controls
	 * already store the {size,unit} and {top,right,bottom,left,unit} shapes
	 * the schema uses, so those pass through once emptiness is screened out.
	 */
	private function leaf_value( $type, $value ) {
		switch ( $type ) {
			case 'colour':
				$value = is_scalar( $value ) ? trim( (string) $value ) : '';
				return '' === $value ? null : $value;

			case 'select':
				$value = is_scalar( $value ) ? trim( (string) $value ) : '';
				return '' === $value ? null : $value;

			case 'number':
				return ( is_numeric( $value ) ) ? 0 + $value : null;

			case 'slider':
				if ( ! is_array( $value ) || ! isset( $value['size'] ) || '' === $value['size'] || null === $value['size'] || ! is_numeric( $value['size'] ) ) {
					return null;
				}
				return array(
					'size' => 0 + $value['size'],
					'unit' => isset( $value['unit'] ) ? (string) $value['unit'] : 'px',
				);

			case 'dimensions':
				if ( ! is_array( $value ) ) {
					return null;
				}
				$any  = false;
				$clean = array();
				foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
					$side_val = isset( $value[ $side ] ) ? $value[ $side ] : '';
					if ( is_numeric( $side_val ) ) {
						$clean[ $side ] = 0 + $side_val;
						$any            = true;
					} else {
						$clean[ $side ] = '';
					}
				}
				if ( ! $any ) {
					return null;
				}
				$clean['unit'] = isset( $value['unit'] ) ? (string) $value['unit'] : 'px';
				return $clean;
		}

		return null;
	}

	private function is_edit_mode() {
		return class_exists( '\Elementor\Plugin' )
			&& isset( \Elementor\Plugin::$instance->editor )
			&& \Elementor\Plugin::$instance->editor->is_edit_mode();
	}

	/** A single-figure placeholder for the no-chart and missing-chart states. */
	private function render_notice( $message ) {
		printf(
			'<figure class="kdna-chart kdna-chart--placeholder"><span class="kdna-chart__placeholder-message">%s</span></figure>',
			esc_html( $message )
		);
	}
}
