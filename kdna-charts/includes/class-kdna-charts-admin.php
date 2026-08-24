<?php
/**
 * Admin surface for the kdna_chart post type. Registers the top level
 * KDNA Charts menu, customises the library list table, adds the
 * Duplicate row action, and owns the Add New screen.
 *
 * The Add New screen is a placeholder for now. Stage 8 replaces it with
 * the type chooser modal that KDNA Tables uses, and Stage 2 adds the
 * Import screen beside it. It creates a real chart in the meantime, so
 * the data model can be exercised before either of those exist.
 *
 * @package KDNA_Charts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KDNA_Charts_Admin {

	const MENU_SLUG_LIST    = 'edit.php?post_type=kdna_chart';
	const MENU_SLUG_ADD_NEW = 'kdna-charts-add-new';

	const NONCE_CREATE    = 'kdna_charts_create_chart';
	const NONCE_DUPLICATE = 'kdna_charts_duplicate_chart';

	const STYLE_HANDLE_ADMIN = 'kdna-charts-admin';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'intercept_default_add_new' ) );

		add_filter( 'manage_' . KDNA_Charts_CPT::POST_TYPE . '_posts_columns', array( __CLASS__, 'list_table_columns' ) );
		add_action( 'manage_' . KDNA_Charts_CPT::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'render_list_column' ), 10, 2 );

		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_action( 'admin_action_kdna_charts_duplicate', array( __CLASS__, 'handle_duplicate' ) );
		add_action( 'admin_action_kdna_charts_create', array( __CLASS__, 'handle_create' ) );

		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );

		add_filter( 'parent_file', array( __CLASS__, 'parent_file' ) );
		add_filter( 'submenu_file', array( __CLASS__, 'submenu_file' ) );
	}

	/*
	 * ====================================================================
	 * Menu
	 * ====================================================================
	 */

	public static function register_menu() {
		add_menu_page(
			__( 'KDNA Charts', 'kdna-charts' ),
			__( 'KDNA Charts', 'kdna-charts' ),
			'edit_posts',
			self::MENU_SLUG_LIST,
			'',
			'dashicons-chart-line',
			26
		);

		add_submenu_page(
			self::MENU_SLUG_LIST,
			__( 'All Charts', 'kdna-charts' ),
			__( 'All Charts', 'kdna-charts' ),
			'edit_posts',
			self::MENU_SLUG_LIST
		);

		add_submenu_page(
			self::MENU_SLUG_LIST,
			__( 'Add New Chart', 'kdna-charts' ),
			__( 'Add New', 'kdna-charts' ),
			'edit_posts',
			self::MENU_SLUG_ADD_NEW,
			array( __CLASS__, 'render_add_new_page' )
		);
	}

	/**
	 * WordPress links Add New to post-new.php for any post type with a UI,
	 * including from the admin bar. Every route into creating a chart has
	 * to pass through our own screen, so the post type gets its starter
	 * definition rather than an empty entry.
	 */
	public static function intercept_default_add_new() {
		if ( ! is_admin() ) {
			return;
		}
		global $pagenow;
		if ( 'post-new.php' !== $pagenow ) {
			return;
		}
		$post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';
		if ( KDNA_Charts_CPT::POST_TYPE !== $post_type ) {
			return;
		}
		wp_safe_redirect( self::get_add_new_url() );
		exit;
	}

	public static function get_add_new_url() {
		return admin_url( 'admin.php?page=' . self::MENU_SLUG_ADD_NEW );
	}

	public static function get_list_url() {
		return admin_url( self::MENU_SLUG_LIST );
	}

	/**
	 * Keeps the KDNA Charts menu open and highlighted while editing a
	 * chart, which show_in_menu false would otherwise lose.
	 */
	public static function parent_file( $parent_file ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && KDNA_Charts_CPT::POST_TYPE === $screen->post_type ) {
			return self::MENU_SLUG_LIST;
		}
		return $parent_file;
	}

	public static function submenu_file( $submenu_file ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && KDNA_Charts_CPT::POST_TYPE === $screen->post_type && in_array( $screen->base, array( 'post', 'edit' ), true ) ) {
			return self::MENU_SLUG_LIST;
		}
		return $submenu_file;
	}

	/*
	 * ====================================================================
	 * Library list table
	 * ====================================================================
	 */

	public static function list_table_columns( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['kdna_type']        = __( 'Type', 'kdna-charts' );
				$new['kdna_engine']      = __( 'Engine', 'kdna-charts' );
				$new['kdna_points']      = __( 'Data points', 'kdna-charts' );
				$new['kdna_annotations'] = __( 'Annotations', 'kdna-charts' );
				$new['kdna_shortcode']   = __( 'Shortcode', 'kdna-charts' );
			}
		}
		return $new;
	}

	public static function render_list_column( $column, $post_id ) {
		switch ( $column ) {
			case 'kdna_type':
				$type  = KDNA_Charts_CPT::get_type( $post_id );
				$label = KDNA_Charts_Data::type_label( $type );
				if ( '' === $label ) {
					self::render_empty_cell( __( 'No type set', 'kdna-charts' ) );
					break;
				}
				printf(
					'<span class="kdna-pill kdna-pill--%1$s">%2$s</span>',
					esc_attr( $type ),
					esc_html( $label )
				);
				break;

			case 'kdna_engine':
				$stored   = (string) get_post_meta( $post_id, KDNA_Charts_CPT::META_ENGINE, true );
				$resolved = KDNA_Charts_Data::resolve_engine( $stored );
				$label    = KDNA_Charts_Data::engine_label( $resolved );
				if ( '' === $stored ) {
					printf(
						'<span class="kdna-engine kdna-engine--inherited">%s</span>',
						esc_html(
							sprintf(
								/* translators: %s: engine name, SVG or Chart.js */
								__( '%s, site default', 'kdna-charts' ),
								$label
							)
						)
					);
					break;
				}
				printf( '<span class="kdna-engine">%s</span>', esc_html( $label ) );
				break;

			case 'kdna_points':
				$definition = KDNA_Charts_CPT::get_definition( $post_id );
				$count      = KDNA_Charts_Data::count_points( $definition );
				if ( $count < 1 ) {
					self::render_empty_cell( __( 'No data yet', 'kdna-charts' ) );
					break;
				}
				printf(
					/* translators: %d: number of data points */
					esc_html( _n( '%d point', '%d points', $count, 'kdna-charts' ) ),
					(int) $count
				);
				break;

			case 'kdna_annotations':
				$definition = KDNA_Charts_CPT::get_definition( $post_id );
				$count      = KDNA_Charts_Data::count_annotations( $definition );
				if ( $count < 1 ) {
					self::render_empty_cell( __( 'None', 'kdna-charts' ) );
					break;
				}
				echo esc_html( (string) $count );
				break;

			case 'kdna_shortcode':
				$shortcode = sprintf( '[kdna_chart id="%d"]', (int) $post_id );
				printf(
					'<code class="kdna-shortcode">%1$s</code> <button type="button" class="button-link kdna-copy-shortcode" data-clipboard-text="%2$s" aria-label="%3$s">%4$s</button>',
					esc_html( $shortcode ),
					esc_attr( $shortcode ),
					esc_attr__( 'Copy shortcode to clipboard', 'kdna-charts' ),
					esc_html__( 'Copy', 'kdna-charts' )
				);
				break;
		}
	}

	/**
	 * An empty list cell that reads as empty to a screen reader too,
	 * rather than as a stray comma.
	 */
	private static function render_empty_cell( $label ) {
		printf(
			'<span aria-hidden="true">&ndash;</span><span class="screen-reader-text">%s</span>',
			esc_html( $label )
		);
	}

	/*
	 * ====================================================================
	 * Row actions
	 * ====================================================================
	 */

	public static function row_actions( $actions, $post ) {
		if ( ! $post instanceof WP_Post || KDNA_Charts_CPT::POST_TYPE !== $post->post_type ) {
			return $actions;
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}
		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'kdna_charts_duplicate',
					'post'   => $post->ID,
				),
				admin_url( 'admin.php' )
			),
			self::NONCE_DUPLICATE . '_' . $post->ID
		);
		$actions['kdna_duplicate'] = sprintf(
			'<a href="%1$s" aria-label="%2$s">%3$s</a>',
			esc_url( $url ),
			esc_attr__( 'Duplicate this chart', 'kdna-charts' ),
			esc_html__( 'Duplicate', 'kdna-charts' )
		);
		return $actions;
	}

	public static function handle_duplicate() {
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		if ( ! $post_id ) {
			wp_die( esc_html__( 'Missing chart id.', 'kdna-charts' ) );
		}
		check_admin_referer( self::NONCE_DUPLICATE . '_' . $post_id );
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'You do not have permission to duplicate this chart.', 'kdna-charts' ) );
		}
		$original = get_post( $post_id );
		if ( ! $original || KDNA_Charts_CPT::POST_TYPE !== $original->post_type ) {
			wp_die( esc_html__( 'Chart not found.', 'kdna-charts' ) );
		}

		$new_id = wp_insert_post(
			array(
				'post_type'   => KDNA_Charts_CPT::POST_TYPE,
				'post_status' => 'draft',
				'post_title'  => $original->post_title . ' ' . __( '(copy)', 'kdna-charts' ),
				'post_author' => get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $new_id ) ) {
			wp_die( esc_html( $new_id->get_error_message() ) );
		}

		/*
		 * Copy through save_definition rather than meta key by meta key,
		 * so the copy is sanitised on the way in and gets its own content
		 * hash rather than inheriting the original's.
		 */
		$definition = KDNA_Charts_CPT::get_definition( $post_id );
		unset( $definition['title'] );
		KDNA_Charts_CPT::save_definition( $new_id, $definition );

		wp_safe_redirect( get_edit_post_link( $new_id, 'redirect' ) );
		exit;
	}

	/*
	 * ====================================================================
	 * Add New
	 * ====================================================================
	 */

	public static function render_add_new_page() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to add charts.', 'kdna-charts' ) );
		}

		$create_action_url = admin_url( 'admin.php?action=kdna_charts_create' );
		$nonce_field_name  = self::NONCE_CREATE;
		$cancel_url        = self::get_list_url();
		$import_url        = KDNA_Charts_Import::get_import_url();
		$types             = KDNA_Charts_Schema::TYPES;

		$template = KDNA_CHARTS_PATH . 'templates/admin-type-chooser-modal.php';
		if ( ! file_exists( $template ) ) {
			return;
		}
		include $template;
	}

	public static function handle_create() {
		check_admin_referer( self::NONCE_CREATE );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to add charts.', 'kdna-charts' ) );
		}

		$type = isset( $_POST['kdna_chart_type'] ) ? sanitize_key( wp_unslash( $_POST['kdna_chart_type'] ) ) : '';
		$type = KDNA_Charts_CPT::sanitize_type( $type );
		if ( '' === $type ) {
			wp_safe_redirect( self::get_add_new_url() );
			exit;
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => KDNA_Charts_CPT::POST_TYPE,
				'post_status' => 'draft',
				'post_title'  => sprintf(
					/* translators: %s: chart type label, for example Line */
					__( 'Untitled %s chart', 'kdna-charts' ),
					strtolower( KDNA_Charts_Data::type_label( $type ) )
				),
				'post_author' => get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			wp_die( esc_html( $post_id->get_error_message() ) );
		}

		KDNA_Charts_CPT::save_definition( $post_id, KDNA_Charts_CPT::default_definition( $type ) );

		wp_safe_redirect( get_edit_post_link( $post_id, 'redirect' ) );
		exit;
	}

	/*
	 * ====================================================================
	 * Assets
	 * ====================================================================
	 */

	public static function enqueue_admin_assets( $hook_suffix ) {
		if ( ! self::is_kdna_screen() ) {
			return;
		}

		wp_enqueue_style(
			self::STYLE_HANDLE_ADMIN,
			KDNA_CHARTS_URL . 'assets/css/kdna-admin.css',
			array(),
			KDNA_CHARTS_VERSION
		);

		/*
		 * Clipboard helper for the Shortcode column. Deliberately inline
		 * and tiny: the real admin JS arrives with the Alpine editor at
		 * Stage 8, and until then this is the only script the screen needs.
		 */
		wp_add_inline_script( 'common', self::clipboard_script() );
	}

	/**
	 * The clipboard helper shared by the Shortcode column and the Import
	 * screen's copy buttons.
	 *
	 * Two sources of text, because the two jobs are different sizes. A
	 * shortcode is short enough to sit in an attribute. The authoring
	 * prompt is several thousand words, so its button names a textarea to
	 * copy from instead.
	 *
	 * The execCommand branch is the fallback for any site still served
	 * over plain HTTP, where the asynchronous clipboard API is unavailable.
	 */
	private static function clipboard_script() {
		$copied = wp_json_encode( __( 'Copied', 'kdna-charts' ) );

		return "(function(){"
			. "function flash(b,msg){var o=b.textContent;b.textContent=msg;setTimeout(function(){b.textContent=o;},1500);}"
			. "function write(t){if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(t);return;}"
			. "var ta=document.createElement('textarea');ta.value=t;ta.setAttribute('readonly','');ta.style.position='fixed';ta.style.opacity='0';"
			. "document.body.appendChild(ta);ta.select();try{document.execCommand('copy');}catch(err){}document.body.removeChild(ta);}"
			. "document.addEventListener('click',function(e){"
			. "var s=e.target.closest('.kdna-copy-shortcode');"
			. "if(s){e.preventDefault();write(s.getAttribute('data-clipboard-text')||'');flash(s," . $copied . ");return;}"
			. "var c=e.target.closest('.kdna-copy');"
			. "if(!c)return;e.preventDefault();"
			. "var src=document.getElementById(c.getAttribute('data-kdna-copy')||'');"
			. "if(!src)return;write(src.value||src.textContent||'');"
			. "flash(c,c.getAttribute('data-kdna-copied')||" . $copied . ");"
			. "});})();";
	}

	/**
	 * The plugin's own admin page slugs, the ones that are not the post
	 * type's own screens. Each stage that adds a page adds it here, so
	 * asset loading stays in one place.
	 */
	public static function plugin_pages() {
		return array(
			self::MENU_SLUG_ADD_NEW,
			KDNA_Charts_Import::MENU_SLUG,
		);
	}

	/**
	 * True on the chart list, the chart editor, and the plugin's own
	 * admin pages. Everywhere else loads none of this plugin's CSS.
	 */
	private static function is_kdna_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && KDNA_Charts_CPT::POST_TYPE === $screen->post_type ) {
			return true;
		}
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		return in_array( $page, self::plugin_pages(), true );
	}
}
