<?php
/**
 * The type chooser, shown when Add New is clicked.
 *
 * The type is fixed once a chart is created, so nothing is written
 * until it has been picked. Following the KDNA Tables pattern: cards
 * rather than a dropdown, because picking a chart type is an editorial
 * decision and the description is the part that helps make it.
 *
 * Variables expected from the caller:
 * - $create_action_url  string    Admin URL for the kdna_charts_create action
 * - $nonce_field_name   string    Nonce action name the handler checks
 * - $cancel_url         string    URL the Cancel link returns to
 * - $import_url         string    URL of the Import screen
 * - $types              string[]  Valid chart type slugs
 *
 * @package KDNA_Charts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * One card per type. The description says what the type is for
 * editorially, not what it looks like, because picking a chart type is
 * an argument decision before it is a drawing one.
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
		'icon' => 'dashicons-menu-alt',
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
<div class="wrap kdna-type-chooser">
	<h1 class="screen-reader-text"><?php esc_html_e( 'Add New Chart', 'kdna-charts' ); ?></h1>

	<div
		class="kdna-type-chooser__modal"
		role="dialog"
		aria-modal="true"
		aria-labelledby="kdna-type-chooser-heading"
		aria-describedby="kdna-type-chooser-intro"
	>
		<div class="kdna-type-chooser__inner">
			<h2 id="kdna-type-chooser-heading" class="kdna-type-chooser__title">
				<?php esc_html_e( 'Pick a chart type', 'kdna-charts' ); ?>
			</h2>
			<p id="kdna-type-chooser-intro" class="kdna-type-chooser__intro">
				<?php esc_html_e( 'The type is fixed once the chart is created. To change it later, duplicate the chart from the All Charts list and pick another type: nothing is thrown away, so annotations a new type cannot draw are kept and appear again if you change back.', 'kdna-charts' ); ?>
			</p>

			<div class="kdna-type-chooser__cards" role="list">
				<?php foreach ( $types as $kdna_type ) : ?>
					<?php
					$kdna_card  = isset( $kdna_type_cards[ $kdna_type ] )
						? $kdna_type_cards[ $kdna_type ]
						: array( 'icon' => 'dashicons-chart-line', 'desc' => '' );
					$kdna_label = KDNA_Charts_Schema::type_label( $kdna_type );
					?>
					<form
						method="post"
						action="<?php echo esc_url( $create_action_url ); ?>"
						class="kdna-type-chooser__card-form"
						role="listitem"
					>
						<?php wp_nonce_field( $nonce_field_name ); ?>
						<input type="hidden" name="kdna_chart_type" value="<?php echo esc_attr( $kdna_type ); ?>" />
						<button
							type="submit"
							class="kdna-type-chooser__card kdna-type-chooser__card--<?php echo esc_attr( $kdna_type ); ?>"
							data-kdna-chooser-card="<?php echo esc_attr( $kdna_type ); ?>"
							aria-labelledby="kdna-card-<?php echo esc_attr( $kdna_type ); ?>-label kdna-card-<?php echo esc_attr( $kdna_type ); ?>-desc"
						>
							<span class="kdna-type-chooser__card-icon" aria-hidden="true">
								<span class="dashicons <?php echo esc_attr( $kdna_card['icon'] ); ?>"></span>
							</span>
							<span id="kdna-card-<?php echo esc_attr( $kdna_type ); ?>-label" class="kdna-type-chooser__card-title">
								<?php echo esc_html( $kdna_label ); ?>
							</span>
							<span id="kdna-card-<?php echo esc_attr( $kdna_type ); ?>-desc" class="kdna-type-chooser__card-desc">
								<?php echo esc_html( $kdna_card['desc'] ); ?>
							</span>
							<span class="kdna-type-chooser__card-cta button button-primary">
								<?php esc_html_e( 'Choose', 'kdna-charts' ); ?>
							</span>
						</button>
					</form>
				<?php endforeach; ?>
			</div>

			<p class="kdna-type-chooser__alternative">
				<?php
				printf(
					/* translators: %s: link to the Import screen */
					esc_html__( 'Already have a definition file? %s instead.', 'kdna-charts' ),
					'<a href="' . esc_url( $import_url ) . '">' . esc_html__( 'Import it', 'kdna-charts' ) . '</a>'
				);
				?>
			</p>

			<p class="kdna-type-chooser__cancel">
				<a href="<?php echo esc_url( $cancel_url ); ?>">
					<?php esc_html_e( 'Cancel and return to All Charts', 'kdna-charts' ); ?>
				</a>
			</p>
		</div>
	</div>
</div>

<script>
( function () {
	'use strict';
	document.addEventListener( 'DOMContentLoaded', function () {
		var cards = Array.prototype.slice.call(
			document.querySelectorAll( '[data-kdna-chooser-card]' )
		);
		if ( ! cards.length ) {
			return;
		}
		cards[ 0 ].focus();

		/*
		 * The dialog holds focus, so tab cycles the cards rather than
		 * wandering off into the admin menu behind it.
		 */
		document.addEventListener( 'keydown', function ( e ) {
			if ( 'Tab' !== e.key ) {
				return;
			}
			var index = cards.indexOf( document.activeElement );
			e.preventDefault();
			if ( -1 === index ) {
				cards[ 0 ].focus();
				return;
			}
			var next = e.shiftKey
				? ( 0 === index ? cards.length - 1 : index - 1 )
				: ( index + 1 ) % cards.length;
			cards[ next ].focus();
		} );
	} );
}() );
</script>
