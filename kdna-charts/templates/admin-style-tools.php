<?php
/**
 * Preset export and import.
 *
 * Shared by both screens, with one difference: the global page can
 * import, and the per chart panel cannot.
 *
 * A preset is a whole layer of styles, and a chart's overrides are the
 * same shape as the global set, so exporting either produces a file the
 * other can read. That is on purpose: a treatment worked out on one
 * chart should be able to become the site's default, or to be carried to
 * a second chart, without anybody retyping a hundred values.
 *
 * Import is global only because importing INTO a chart would mean
 * replacing that chart's overrides with a full set of values, which
 * turns a chart that inherits almost everything into one that inherits
 * nothing. A preset says "these are the styles"; a chart's overrides say
 * "these are the differences", and only the first of those is safe to
 * apply wholesale.
 *
 * @package KDNA_Charts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$kdna_can_import = ! isset( $kdna_context ) || 'chart' !== $kdna_context;
?>
<div class="kdna-style-tools">

	<div class="kdna-style-tools__row">
		<button type="button" class="button" @click="exportPreset()">
			<span class="dashicons dashicons-download" aria-hidden="true"></span>
			<?php esc_html_e( 'Export preset', 'kdna-charts' ); ?>
		</button>

		<?php if ( $kdna_can_import ) : ?>
			<button
				type="button"
				class="button"
				@click="importOpen = ! importOpen"
				:aria-expanded="importOpen ? 'true' : 'false'"
				aria-controls="kdna-style-import"
			>
				<span class="dashicons dashicons-upload" aria-hidden="true"></span>
				<?php esc_html_e( 'Import preset', 'kdna-charts' ); ?>
			</button>
		<?php endif; ?>
	</div>

	<?php if ( $kdna_can_import ) : ?>
		<div class="kdna-style-tools__panel" id="kdna-style-import" x-show="importOpen" x-cloak>

			<p class="kdna-style-tools__hint">
				<?php esc_html_e( 'Paste a preset, or choose an exported .json file. Importing replaces every global style; charts with their own overrides keep them.', 'kdna-charts' ); ?>
			</p>

			<input
				type="file"
				accept="application/json,.json"
				class="kdna-style-tools__file"
				@change="readPresetFile( $event )"
			/>

			<textarea
				class="kdna-style-tools__textarea"
				rows="6"
				spellcheck="false"
				x-model="importText"
				aria-label="<?php esc_attr_e( 'Preset JSON', 'kdna-charts' ); ?>"
				placeholder="<?php echo esc_attr( '{ "kdna_charts_preset": true, "values": { … } }' ); ?>"
			></textarea>

			<div class="kdna-style-tools__actions">
				<button
					type="button"
					class="button button-primary"
					@click="importPreset()"
					:disabled="importing || ! importText.trim()"
					x-text="importing ? strings.importing : '<?php echo esc_js( __( 'Import', 'kdna-charts' ) ); ?>'"
				></button>
			</div>

			<?php
			/*
			 * What did not survive. An import that quietly dropped half a
			 * preset and reported success would be worse than one that
			 * failed outright, so the keys are named.
			 */
			?>
			<div class="kdna-style-tools__discarded" x-show="discarded.length">
				<p x-text="strings.discardedIntro"></p>
				<ul>
					<template x-for="item in discarded" :key="item.key">
						<li>
							<code x-text="item.key"></code>
							<span x-text="': ' + item.reason"></span>
						</li>
					</template>
				</ul>
			</div>
		</div>
	<?php endif; ?>
</div>
