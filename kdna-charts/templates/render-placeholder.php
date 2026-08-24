<?php
/**
 * What appears when a chart cannot be drawn.
 *
 * Never nothing. A chart that does not appear reads as a broken page,
 * and one that says why reads as a chart waiting for data. Only
 * visible to someone who can edit, so a reader never sees the plumbing.
 *
 * Variables expected from the caller:
 * - $reason   string  Plain English explanation
 * - $title    string  The chart's title, if it has one
 * - $block    string  The BEM block name
 * - $classes  string  Wrapper classes
 *
 * @package KDNA_Charts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'edit_posts' ) ) {
	return;
}
?>
<div class="<?php echo esc_attr( $classes ); ?>">
	<div class="<?php echo esc_attr( $block . '__placeholder-inner' ); ?>">
		<?php if ( '' !== $title ) : ?>
			<p class="<?php echo esc_attr( $block . '__placeholder-title' ); ?>"><?php echo esc_html( $title ); ?></p>
		<?php endif; ?>
		<p class="<?php echo esc_attr( $block . '__placeholder-reason' ); ?>"><?php echo esc_html( $reason ); ?></p>
		<p class="<?php echo esc_attr( $block . '__placeholder-note' ); ?>">
			<?php esc_html_e( 'Only editors see this message.', 'kdna-charts' ); ?>
		</p>
	</div>
</div>
