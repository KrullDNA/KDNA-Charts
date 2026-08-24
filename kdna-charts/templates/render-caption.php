<?php
/**
 * The caption beneath a chart.
 *
 * A figcaption rather than a paragraph, because it is the accessible
 * name of the figure and belongs to it structurally, not just visually.
 * Whether it sits above or below is a CSS order, set at Stage 9.
 *
 * Variables expected from the caller:
 * - $caption  string  The caption text, may carry inline markup
 * - $block    string  The BEM block name
 *
 * @package KDNA_Charts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<figcaption class="<?php echo esc_attr( $block . '__caption' ); ?>"><?php echo wp_kses_post( $caption ); ?></figcaption>
