<?php
/**
 * The live preview pane.
 *
 * ── Why an iframe ─────────────────────────────────────────────────────
 *
 * Two of the three breakpoints are viewport media queries, and only a
 * document with a real 390px viewport makes the mobile query fire. An
 * iframe is the one way to give a panel inside a 1400px admin screen a
 * real 390px viewport. The alternative, previewing inline and restating
 * every breakpoint as a container query, is a second copy of the
 * resolution layer that would have to be kept in step with the first for
 * ever, and the whole point of this pane is that it is not a second
 * copy.
 *
 * The chart's own container queries come along for free, since the
 * frame's width inside the iframe is the real one.
 *
 * The frame carries no src, so its document is about:blank and therefore
 * same-origin. Everything after the initial markup fetch is a DOM write
 * through contentDocument: no postMessage plumbing, no re-fetch, and
 * nothing to serialise.
 *
 * ── Why the chart list is rendered here ───────────────────────────────
 *
 * Alpine applies x-model to a select before an x-for inside that select
 * has produced its options, so the select would fall back to its first
 * option and never re-sync. The list is known to PHP, so it is printed.
 *
 * @var array|null $preview Preview configuration, null when there is
 *                          nothing to preview.
 *
 * @package KDNA_Charts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$kdna_preview = isset( $preview ) && is_array( $preview ) ? $preview : array();
$kdna_charts  = isset( $kdna_preview['charts'] ) ? $kdna_preview['charts'] : array();
?>
<div class="kdna-style-preview" x-show="preview">

	<div class="kdna-style-preview__bar">

		<label class="kdna-style-preview__control">
			<span class="kdna-style-preview__label"><?php esc_html_e( 'Chart', 'kdna-charts' ); ?></span>
			<?php
			/*
			 * Changing the chart is the one control here that re-fetches:
			 * a different chart is different markup. Everything else is a
			 * custom property, written straight onto the wrapper.
			 */
			?>
			<select x-model="previewChart" @change="loadPreview()">
				<?php foreach ( $kdna_charts as $kdna_chart ) : ?>
					<option value="<?php echo esc_attr( (string) $kdna_chart['id'] ); ?>">
						<?php echo esc_html( $kdna_chart['title'] ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</label>

		<span class="kdna-style-preview__devices" role="group" aria-label="<?php esc_attr_e( 'Preview width', 'kdna-charts' ); ?>">
			<?php foreach ( ( isset( $kdna_preview['devices'] ) ? $kdna_preview['devices'] : array() ) as $kdna_key => $kdna_label ) : ?>
				<button
					type="button"
					class="kdna-style-preview__device"
					:class="{ 'is-active': previewDevice === '<?php echo esc_attr( $kdna_key ); ?>' }"
					@click="setPreviewDevice( '<?php echo esc_attr( $kdna_key ); ?>' )"
					:aria-pressed="previewDevice === '<?php echo esc_attr( $kdna_key ); ?>' ? 'true' : 'false'"
				>
					<span><?php echo esc_html( $kdna_label ); ?></span>
					<span class="kdna-style-preview__width">
						<?php
						echo esc_html(
							isset( $kdna_preview['widths'][ $kdna_key ] )
								? $kdna_preview['widths'][ $kdna_key ] . 'px'
								: ''
						);
						?>
					</span>
				</button>
			<?php endforeach; ?>
		</span>

		<span class="kdna-style-preview__status" x-show="previewLoading" x-text="strings.loading"></span>
		<span class="kdna-style-preview__status is-error" x-show="previewError" x-text="previewError"></span>
	</div>

	<?php
	/*
	 * The pane renders from the live values, so an edit shows here the
	 * moment it is made and the front end keeps the last saved ones until
	 * Save is pressed. That is the right behaviour, and it is completely
	 * silent: the only other hint is the words "Unsaved changes" by the
	 * save button, well below the fold on a long section, which reads as
	 * a save reminder rather than as an explanation of why the live chart
	 * looks different.
	 */
	?>
	<p class="kdna-style-preview__notice is-warning" x-show="dirty">
		<?php esc_html_e( 'Showing unsaved changes. The live chart keeps its saved styles until you save.', 'kdna-charts' ); ?>
	</p>

	<p class="kdna-style-preview__notice" x-show="previewHasOverrides()">
		<?php esc_html_e( 'This chart has style overrides of its own. The preview shows the global defaults only, so the live chart will differ wherever it overrides them.', 'kdna-charts' ); ?>
	</p>

	<p class="kdna-style-preview__notice" x-show="previewEmpty">
		<?php esc_html_e( 'This chart rendered nothing. It may have no data yet.', 'kdna-charts' ); ?>
	</p>

	<div class="kdna-style-preview__stage">
		<?php
		/*
		 * The width is the whole point of the device toggle, so it is set
		 * on the frame itself rather than on a wrapper: the frame's width
		 * IS the viewport the media queries see.
		 */
		?>
		<iframe
			x-ref="previewFrame"
			class="kdna-style-preview__frame"
			title="<?php esc_attr_e( 'Chart preview', 'kdna-charts' ); ?>"
			:style="'width: ' + previewWidth() + 'px'"
		></iframe>
	</div>
</div>

<?php /* Nothing to preview, so the pane says so rather than sitting empty. */ ?>
<p class="kdna-style-preview__empty" x-show="! preview" x-text="strings.noPreview"></p>
