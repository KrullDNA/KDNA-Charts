<?php
/**
 * The Options tab.
 *
 * Every control is built from the schema rather than written out by
 * hand, so an option the importer accepts is an option the editor
 * offers and neither can drift from the other. Adding an option to
 * KDNA_Charts_Schema::options_spec() adds it to this screen.
 *
 * Built in PHP rather than by Alpine, for two reasons. The chart type
 * is fixed at creation, so the set of options never changes while this
 * screen is open and there is nothing to rebuild. And a select whose
 * options are looped by Alpine loses its value, because x-model binds
 * before the loop has rendered anything to select.
 *
 * Variables expected from the caller:
 * - $options_spec  array  Option specs for this chart's type
 *
 * @package KDNA_Charts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! isset( $options_spec ) || ! is_array( $options_spec ) ) {
	$options_spec = array();
}
?>
<div class="kdna-editor__section">
	<h3><?php esc_html_e( 'Options', 'kdna-charts' ); ?></h3>
	<p class="description">
		<?php esc_html_e( 'Leave an option alone to take the default shown. Only the options this chart type understands appear here.', 'kdna-charts' ); ?>
	</p>

	<div class="kdna-editor__options">
		<?php foreach ( $options_spec as $kdna_key => $kdna_node ) : ?>
			<?php
			$kdna_model = 'chart.options[\'' . $kdna_key . '\']';

			/*
			 * The schema's label is a sentence explaining what an option
			 * is for, written for the documentation. That is the right
			 * thing beneath a control and the wrong thing on it, so the
			 * control takes the option's own name and the sentence goes
			 * underneath as help.
			 */
			$kdna_label   = ucfirst( str_replace( '_', ' ', $kdna_key ) );
			$kdna_help    = isset( $kdna_node['label'] ) ? (string) $kdna_node['label'] : '';
			$kdna_default = array_key_exists( 'default', $kdna_node ) ? $kdna_node['default'] : '';
			?>
			<div class="kdna-editor__option">
				<?php if ( 'bool' === $kdna_node['kind'] ) : ?>
					<label class="kdna-editor__field kdna-editor__field--check">
						<input type="checkbox" x-model="<?php echo esc_attr( $kdna_model ); ?>" />
						<span><?php echo esc_html( $kdna_label ); ?></span>
					</label>

				<?php elseif ( 'enum' === $kdna_node['kind'] ) : ?>
					<label class="kdna-editor__field">
						<span><?php echo esc_html( $kdna_label ); ?></span>
						<select x-model="<?php echo esc_attr( $kdna_model ); ?>">
							<?php
							/*
							 * An explicit empty choice, because an option
							 * left alone has to stay alone: the style
							 * cascade at Stage 9 reaches a chart through
							 * the values it does not set. Without this the
							 * select would show its first option and quietly
							 * commit it the moment anybody touched it.
							 */
							?>
							<option value="">
								<?php
								printf(
									/* translators: %s: the value used when nothing is chosen */
									esc_html__( 'Default (%s)', 'kdna-charts' ),
									esc_html( (string) $kdna_default )
								);
								?>
							</option>
							<?php
							// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							echo KDNA_Charts_Editor::enum_options( $kdna_node['values'] );
							?>
						</select>
					</label>

				<?php elseif ( in_array( $kdna_node['kind'], array( 'number', 'int' ), true ) ) : ?>
					<label class="kdna-editor__field">
						<span><?php echo esc_html( $kdna_label ); ?></span>
						<input
							type="number"
							x-model.number="<?php echo esc_attr( $kdna_model ); ?>"
							<?php if ( isset( $kdna_node['min'] ) ) : ?>min="<?php echo esc_attr( (string) $kdna_node['min'] ); ?>"<?php endif; ?>
							<?php if ( isset( $kdna_node['max'] ) ) : ?>max="<?php echo esc_attr( (string) $kdna_node['max'] ); ?>"<?php endif; ?>
							step="<?php echo 'int' === $kdna_node['kind'] ? '1' : 'any'; ?>"
							placeholder="<?php echo esc_attr( (string) $kdna_default ); ?>"
						/>
					</label>

				<?php else : ?>
					<label class="kdna-editor__field">
						<span><?php echo esc_html( $kdna_label ); ?></span>
						<input
							type="text"
							x-model="<?php echo esc_attr( $kdna_model ); ?>"
							placeholder="<?php echo esc_attr( (string) $kdna_default ); ?>"
						/>
					</label>
				<?php endif; ?>

				<?php if ( '' !== $kdna_help ) : ?>
					<p class="kdna-editor__option-help"><?php echo esc_html( $kdna_help ); ?></p>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
	</div>

	<hr />

	<h3><?php esc_html_e( 'Engine', 'kdna-charts' ); ?></h3>
	<p class="description">
		<?php esc_html_e( 'SVG is the complete engine: it needs no JavaScript and carries the whole annotation layer. Chart.js arrives at Stage 12, for large datasets and hover interaction.', 'kdna-charts' ); ?>
	</p>
	<label class="kdna-editor__field kdna-editor__field--inline">
		<span><?php esc_html_e( 'Rendered by', 'kdna-charts' ); ?></span>
		<select x-model="chart.engine">
			<option value=""><?php esc_html_e( 'The site default', 'kdna-charts' ); ?></option>
			<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo KDNA_Charts_Editor::enum_options(
				KDNA_Charts_Schema::ENGINES,
				array(
					'svg'     => __( 'SVG', 'kdna-charts' ),
					'chartjs' => __( 'Chart.js', 'kdna-charts' ),
				)
			);
			?>
		</select>
	</label>
</div>
