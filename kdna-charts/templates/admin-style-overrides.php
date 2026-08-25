<?php
/**
 * Per chart Styles panel, rendered inside the Style tab of the chart
 * editor by KDNA_Charts_Style_Admin::render_overrides_panel().
 *
 * The controls come from templates/admin-style-controls.php, the same
 * file the global settings page uses, rendered in 'chart' context: every
 * field shows what it is inheriting until it is explicitly overridden,
 * and the panel gains per-section and whole-chart resets.
 *
 * ── Why this saves on its own button ──────────────────────────────────
 *
 * The chart editor around it posts the definition through the ordinary
 * post save, and this panel posts overrides through a REST route. Two
 * buttons on one screen is not ideal, and the alternative is worse: the
 * style meta is administrator-gated while the definition is not, so
 * folding the styles into the post save would mean an editor's ordinary
 * Update either silently dropped them or silently needed a capability
 * they do not have.
 *
 * @var array $sections Section key => label.
 * @var array $grouped  Section key => (control key => definition).
 * @var array $devices  Device key => label.
 * @var int   $chart_id The chart being edited.
 *
 * @package KDNA_Charts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$kdna_context   = 'chart';
$kdna_renderers = require KDNA_CHARTS_PATH . 'templates/admin-style-controls.php';
?>
<div class="kdna-style-admin kdna-style-admin--panel" x-data="kdnaChartsStyleAdmin()" x-init="init()">

	<div class="notice notice-error kdna-style-admin__boot" x-show="false">
		<p><?php esc_html_e( 'The style editor did not start. Its script may have been blocked, deferred or combined by another plugin. Nothing here will save until that is resolved.', 'kdna-charts' ); ?></p>
	</div>

	<div class="kdna-style-admin__layout" x-cloak>

		<p class="kdna-style-admin__intro">
			<?php
			printf(
				/* translators: %s: link to the global styles settings page. */
				esc_html__( 'This chart follows the %s until you override something here. Overrides apply wherever this chart is rendered.', 'kdna-charts' ),
				'<a href="' . esc_url( KDNA_Charts_Style_Admin::page_url() ) . '">'
					. esc_html__( 'global chart styles', 'kdna-charts' ) . '</a>'
			);
			?>
		</p>

		<?php require KDNA_CHARTS_PATH . 'templates/admin-style-tools.php'; ?>

		<?php $kdna_renderers['panel']( $sections, $grouped, $devices ); ?>

		<div class="kdna-style-savebar kdna-style-savebar--panel">
			<button
				type="button"
				class="button button-primary"
				@click="save()"
				:disabled="saving"
				x-text="saving ? strings.saving : '<?php echo esc_js( __( 'Save Chart Styles', 'kdna-charts' ) ); ?>'"
			></button>

			<span class="kdna-style-savebar__status" :class="statusClass" x-text="status" aria-live="polite"></span>

			<span class="kdna-style-savebar__dirty" x-show="dirty && ! saving" x-text="strings.unsaved"></span>

			<button
				type="button"
				class="button kdna-style-savebar__reset"
				x-show="anyValues()"
				@click="resetAll()"
			><?php esc_html_e( 'Reset this chart to inherit everything', 'kdna-charts' ); ?></button>
		</div>
	</div>
</div>
