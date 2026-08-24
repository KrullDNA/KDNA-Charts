<?php
/**
 * Settings > KDNA Charts: the global style defaults.
 *
 * Rendered by KDNA_Charts_Style_Admin::render_page(), which supplies
 * every variable below. Everything under the intro comes from
 * templates/admin-style-controls.php, which the per chart panel includes
 * too, so the two screens are one implementation rather than two.
 *
 * @var array      $sections Section key => label.
 * @var array      $grouped  Section key => (control key => definition).
 * @var array      $devices  Device key => label.
 * @var array|null $preview  Preview configuration, null when there is
 *                           nothing published to preview.
 *
 * @package KDNA_Charts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$kdna_context   = 'global';
$kdna_renderers = require KDNA_CHARTS_PATH . 'templates/admin-style-controls.php';
?>
<div class="wrap kdna-style-admin" x-data="kdnaChartsStyleAdmin()" x-init="init()">

	<h1 class="wp-heading-inline"><?php esc_html_e( 'KDNA Charts Styles', 'kdna-charts' ); ?></h1>

	<p class="kdna-style-admin__intro">
		<?php esc_html_e( 'These are the defaults every chart on this site renders with, wherever it is placed. An individual chart can override any of them on its own edit screen, under Style.', 'kdna-charts' ); ?>
	</p>

	<?php
	/*
	 * The warning that shows when the component never started.
	 *
	 * Not x-cloak'd and not x-show'd on a condition: it is hidden by
	 * Alpine the moment Alpine runs, and stays visible if it does not.
	 * A cloaked warning would be hidden at exactly the moment it needs
	 * to be seen.
	 */
	?>
	<div class="notice notice-error kdna-style-admin__boot" x-show="false">
		<p><?php esc_html_e( 'The style editor did not start. Its script may have been blocked, deferred or combined by another plugin. Nothing here will save until that is resolved.', 'kdna-charts' ); ?></p>
	</div>

	<div class="kdna-style-admin__layout" x-cloak>

		<?php require KDNA_CHARTS_PATH . 'templates/admin-style-tools.php'; ?>

		<?php require KDNA_CHARTS_PATH . 'templates/admin-style-preview.php'; ?>

		<?php $kdna_renderers['panel']( $sections, $grouped, $devices ); ?>

		<div class="kdna-style-savebar">
			<button
				type="button"
				class="button button-primary"
				@click="save()"
				:disabled="saving"
				x-text="saving ? strings.saving : '<?php echo esc_js( __( 'Save Styles', 'kdna-charts' ) ); ?>'"
			></button>

			<span class="kdna-style-savebar__status" :class="statusClass" x-text="status" aria-live="polite"></span>

			<span class="kdna-style-savebar__dirty" x-show="dirty && ! saving" x-text="strings.unsaved"></span>

			<?php
			/*
			 * Reset sits at the far end of the bar, away from Save, and
			 * asks before it fires. It is the only action on this page
			 * that cannot be undone by clicking something else.
			 */
			?>
			<button
				type="button"
				class="button kdna-style-savebar__reset"
				x-show="anyValues()"
				@click="resetAll()"
			><?php esc_html_e( 'Reset every style to the plugin defaults', 'kdna-charts' ); ?></button>
		</div>
	</div>
</div>
