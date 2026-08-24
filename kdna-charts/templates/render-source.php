<?php
/**
 * The attribution line beneath a chart.
 *
 * A chart that makes an argument has to say where its figures came
 * from, so this is not optional decoration. The small element carries
 * the meaning rather than the styling: it is side commentary on the
 * figure, which is exactly what small is for.
 *
 * Variables expected from the caller:
 * - $source  string  The attribution line
 * - $block   string  The BEM block name
 *
 * @package KDNA_Charts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<p class="<?php echo esc_attr( $block . '__source' ); ?>"><small><?php echo wp_kses_post( $source ); ?></small></p>
