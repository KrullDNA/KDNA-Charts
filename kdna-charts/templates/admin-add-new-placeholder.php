<?php
/**
 * Add New Chart, placeholder screen.
 *
 * Stage 8 replaces this with the Alpine type chooser modal, and Stage 2
 * adds the Import screen alongside it. Until then this picks a type,
 * creates the entry with its starter definition, and sends the user to
 * the edit screen.
 *
 * Variables expected from the caller:
 * - $create_action_url  string    Admin URL for the kdna_charts_create action
 * - $nonce_field_name   string    Nonce action name, the same constant the handler checks
 * - $cancel_url         string    URL the Cancel link returns to, the All Charts list
 * - $types              string[]  Valid chart type slugs
 *
 * @package KDNA_Charts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * One card per chart type. The description is what the type is for
 * editorially, not what it looks like, since picking a chart type is an
 * argument decision before it is a drawing one.
 */
$kdna_type_cards = array(
	'line'   => array(
		'icon' => 'dashicons-chart-line',
		'desc' => __( 'A trend over time. Segments let one line change character partway along, so a projection can run dotted and the measured part solid.', 'kdna-charts' ),
	),
	'area'   => array(
		'icon' => 'dashicons-chart-area',
		'desc' => __( 'A line with the space beneath it filled. Use it when the size of the quantity matters as much as its direction.', 'kdna-charts' ),
	),
	'bar'    => array(
		'icon' => 'dashicons-chart-bar',
		'desc' => __( 'Horizontal bars. Best when the category names are long enough to need the room.', 'kdna-charts' ),
	),
	'column' => array(
		'icon' => 'dashicons-chart-bar',
		'desc' => __( 'Vertical columns. Best for comparing a handful of values, or the same value across a few periods.', 'kdna-charts' ),
	),
	'pie'    => array(
		'icon' => 'dashicons-chart-pie',
		'desc' => __( 'Parts of a whole. Keep it to a few segments, since a reader cannot compare thin slices by eye.', 'kdna-charts' ),
	),
	'donut'  => array(
		'icon' => 'dashicons-marker',
		'desc' => __( 'A pie with the centre open, so a total or a headline figure can sit inside it.', 'kdna-charts' ),
	),
	'stat'   => array(
		'icon' => 'dashicons-editor-bold',
		'desc' => __( 'Typographic figures rather than a plot. For when the number is the whole point and a chart would only decorate it.', 'kdna-charts' ),
	),
);
?>
<div class="wrap kdna-add-new">
	<h1><?php esc_html_e( 'Add New Chart', 'kdna-charts' ); ?></h1>

	<div class="notice notice-info inline kdna-add-new__notice">
		<p>
			<?php esc_html_e( 'This is the Stage 1 placeholder. Picking a type creates the chart entry with its starter data, so the library and the data model can be tested. The full data editor arrives at Stage 8, and the JSON importer at Stage 2.', 'kdna-charts' ); ?>
		</p>
	</div>

	<p class="kdna-add-new__intro">
		<?php esc_html_e( 'Pick a chart type. The type is fixed once the chart is created. To change it later, duplicate the chart from the All Charts list and pick the other type.', 'kdna-charts' ); ?>
	</p>

	<div class="kdna-add-new__cards" role="list">
		<?php foreach ( $types as $kdna_type ) : ?>
			<?php
			$kdna_card  = isset( $kdna_type_cards[ $kdna_type ] ) ? $kdna_type_cards[ $kdna_type ] : array( 'icon' => 'dashicons-chart-line', 'desc' => '' );
			$kdna_label = KDNA_Charts_Data::type_label( $kdna_type );
			?>
			<form
				method="post"
				action="<?php echo esc_url( $create_action_url ); ?>"
				class="kdna-add-new__card-form"
				role="listitem"
			>
				<?php wp_nonce_field( $nonce_field_name ); ?>
				<input type="hidden" name="kdna_chart_type" value="<?php echo esc_attr( $kdna_type ); ?>" />
				<button
					type="submit"
					class="kdna-add-new__card kdna-add-new__card--<?php echo esc_attr( $kdna_type ); ?>"
					data-kdna-type-card="<?php echo esc_attr( $kdna_type ); ?>"
					aria-labelledby="kdna-card-<?php echo esc_attr( $kdna_type ); ?>-label kdna-card-<?php echo esc_attr( $kdna_type ); ?>-desc"
				>
					<span class="kdna-add-new__card-icon" aria-hidden="true">
						<span class="dashicons <?php echo esc_attr( $kdna_card['icon'] ); ?>"></span>
					</span>
					<span id="kdna-card-<?php echo esc_attr( $kdna_type ); ?>-label" class="kdna-add-new__card-title">
						<?php echo esc_html( $kdna_label ); ?>
					</span>
					<span id="kdna-card-<?php echo esc_attr( $kdna_type ); ?>-desc" class="kdna-add-new__card-desc">
						<?php echo esc_html( $kdna_card['desc'] ); ?>
					</span>
					<span class="kdna-add-new__card-cta button button-primary">
						<?php esc_html_e( 'Choose', 'kdna-charts' ); ?>
					</span>
				</button>
			</form>
		<?php endforeach; ?>
	</div>

	<p class="kdna-add-new__cancel">
		<a href="<?php echo esc_url( $cancel_url ); ?>">
			<?php esc_html_e( 'Cancel and return to All Charts', 'kdna-charts' ); ?>
		</a>
	</p>
</div>
