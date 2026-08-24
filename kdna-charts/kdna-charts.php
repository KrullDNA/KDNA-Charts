<?php
/**
 * Plugin Name: KDNA Charts
 * Description: An editorial charting plugin. A reusable chart library as a custom post type, with a server rendered SVG engine, a full annotation layer, an Elementor widget, a shortcode and a global styling page.
 * Version: 1.0.0
 * Author: KDNA
 * Text Domain: kdna-charts
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 *
 * @package KDNA_Charts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'KDNA_CHARTS_VERSION', '1.0.0' );
define( 'KDNA_CHARTS_FILE', __FILE__ );
define( 'KDNA_CHARTS_PATH', plugin_dir_path( __FILE__ ) );
define( 'KDNA_CHARTS_URL', plugin_dir_url( __FILE__ ) );

require_once KDNA_CHARTS_PATH . 'includes/class-kdna-charts-plugin.php';

/*
 * Elementor hook registration MUST happen at file load time.
 * By the time this plugin file is parsed, the elementor/loaded action has
 * already fired in normal load order, so wrapping these registrations in
 * an elementor/loaded callback would silently never run.
 * The hooks themselves fire later in Elementor's own lifecycle, so it is
 * safe to register them unconditionally here. The callbacks each check
 * whether the thing they register actually exists yet, so the wiring can
 * sit here from Stage 1 while the widget itself arrives at Stage 11.
 */
add_action( 'elementor/elements/categories_registered', array( 'KDNA_Charts_Plugin', 'register_category' ) );
add_action( 'elementor/widgets/register', array( 'KDNA_Charts_Plugin', 'register_widgets' ) );
add_action( 'elementor/frontend/after_register_styles', array( 'KDNA_Charts_Plugin', 'register_frontend_styles' ) );
add_action( 'elementor/frontend/after_register_scripts', array( 'KDNA_Charts_Plugin', 'register_frontend_scripts' ) );
add_action( 'elementor/editor/after_enqueue_styles', array( 'KDNA_Charts_Plugin', 'enqueue_editor_styles' ) );
add_action( 'elementor/editor/after_enqueue_scripts', array( 'KDNA_Charts_Plugin', 'enqueue_editor_scripts' ) );
add_action( 'init', array( 'KDNA_Charts_Plugin', 'load_textdomain' ) );

// Flush rewrite rules once on activation so the private post type registers cleanly.
register_activation_hook( __FILE__, array( 'KDNA_Charts_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'KDNA_Charts_Plugin', 'deactivate' ) );
