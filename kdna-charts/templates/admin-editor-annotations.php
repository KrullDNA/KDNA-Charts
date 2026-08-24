<?php
/**
 * The Annotations tab.
 *
 * Four lists of cards: markers, emphasised points, callouts and notes.
 * Each card carries only the fields the schema gives that kind of
 * annotation, and nothing here asks where anything should sit on the
 * chart. That is the renderer's job, and asking an author to position
 * a callout in pixel coordinates is the thing this plugin exists to
 * avoid.
 *
 * A callout says what it means and what it points at. Where it goes is
 * worked out from what is already on the chart.
 *
 * @package KDNA_Charts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="kdna-editor__section">

	<template x-if="! drawsAnnotations">
		<p class="notice notice-info inline">
			<?php esc_html_e( 'A pie, donut or stat chart has no plot coordinates to place an annotation on, so nothing here will be drawn. Anything you add is still kept, and appears again if the chart is changed to a line, area, bar or column type.', 'kdna-charts' ); ?>
		</p>
	</template>

	<!-- ── Markers ──────────────────────────────────────────────── -->
	<section class="kdna-editor__cards">
		<header class="kdna-editor__cards-head">
			<h3><?php esc_html_e( 'Markers', 'kdna-charts' ); ?></h3>
			<p class="description"><?php esc_html_e( 'A line across the chart with a heading, for a turning point the text names.', 'kdna-charts' ); ?></p>
			<button type="button" class="button button-small" x-on:click="addMarker()"><?php esc_html_e( 'Add marker', 'kdna-charts' ); ?></button>
		</header>

		<template x-for="( marker, index ) in chart.markers" x-bind:key="'marker-' + index">
			<div class="kdna-editor__card">
				<div class="kdna-editor__field-row">
					<label class="kdna-editor__field">
						<span><?php esc_html_e( 'Heading', 'kdna-charts' ); ?></span>
						<input type="text" x-model="marker.label" />
					</label>
					<label class="kdna-editor__field kdna-editor__field--narrow">
						<span><?php esc_html_e( 'Axis', 'kdna-charts' ); ?></span>
						<select x-model="marker.type">
							<option value="vertical"><?php esc_html_e( 'At an x value', 'kdna-charts' ); ?></option>
							<option value="horizontal"><?php esc_html_e( 'At a y value', 'kdna-charts' ); ?></option>
						</select>
					</label>
					<label class="kdna-editor__field kdna-editor__field--narrow" x-show="marker.type === 'vertical'">
						<span><?php esc_html_e( 'x', 'kdna-charts' ); ?></span>
						<input type="text" inputmode="decimal" x-model.number="marker.x" />
					</label>
					<label class="kdna-editor__field kdna-editor__field--narrow" x-show="marker.type === 'horizontal'">
						<span><?php esc_html_e( 'y', 'kdna-charts' ); ?></span>
						<input type="text" inputmode="decimal" x-model.number="marker.y" />
					</label>
					<label class="kdna-editor__field kdna-editor__field--narrow">
						<span><?php esc_html_e( 'Heading at', 'kdna-charts' ); ?></span>
						<select x-model="marker.label_position">
							<?php echo KDNA_Charts_Editor::enum_options( KDNA_Charts_Schema::MARKER_LABEL_POSITIONS ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</select>
					</label>
					<label class="kdna-editor__field kdna-editor__field--narrow">
						<span><?php esc_html_e( 'Line', 'kdna-charts' ); ?></span>
						<select x-model="marker.style">
							<?php echo KDNA_Charts_Editor::enum_options( KDNA_Charts_Schema::LINE_STYLES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</select>
					</label>
					<button type="button" class="button-link kdna-editor__remove" x-on:click="remove( 'markers', index )">&times;</button>
				</div>
			</div>
		</template>
	</section>

	<!-- ── Emphasised points ───────────────────────────────────── -->
	<section class="kdna-editor__cards">
		<header class="kdna-editor__cards-head">
			<h3><?php esc_html_e( 'Emphasised points', 'kdna-charts' ); ?></h3>
			<p class="description"><?php esc_html_e( 'A dot on a reading worth stopping at. Separate from the data, because emphasising a point is a design decision rather than a data one.', 'kdna-charts' ); ?></p>
			<button type="button" class="button button-small" x-on:click="addEmphasisedPoint()"><?php esc_html_e( 'Add point', 'kdna-charts' ); ?></button>
		</header>

		<template x-for="( point, index ) in chart.points" x-bind:key="'point-' + index">
			<div class="kdna-editor__card">
				<div class="kdna-editor__field-row">
					<label class="kdna-editor__field kdna-editor__field--narrow">
						<span><?php esc_html_e( 'x', 'kdna-charts' ); ?></span>
						<input type="text" inputmode="decimal" x-model.number="point.x" />
					</label>
					<label class="kdna-editor__field kdna-editor__field--narrow">
						<span><?php esc_html_e( 'y', 'kdna-charts' ); ?></span>
						<input type="text" inputmode="decimal" x-model.number="point.y" />
					</label>
					<label class="kdna-editor__field kdna-editor__field--narrow">
						<span><?php esc_html_e( 'Dot', 'kdna-charts' ); ?></span>
						<select x-model="point.style">
							<?php echo KDNA_Charts_Editor::enum_options( KDNA_Charts_Schema::POINT_STYLES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</select>
					</label>
					<label class="kdna-editor__field">
						<span><?php esc_html_e( 'Label', 'kdna-charts' ); ?></span>
						<input type="text" x-model="point.label" />
					</label>
					<label class="kdna-editor__field kdna-editor__field--narrow">
						<span><?php esc_html_e( 'Label at', 'kdna-charts' ); ?></span>
						<select x-model="point.label_position">
							<?php echo KDNA_Charts_Editor::enum_options( KDNA_Charts_Schema::LABEL_POSITIONS ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</select>
					</label>
					<button type="button" class="button-link kdna-editor__remove" x-on:click="remove( 'points', index )">&times;</button>
				</div>
			</div>
		</template>
	</section>

	<!-- ── Callouts ────────────────────────────────────────────── -->
	<section class="kdna-editor__cards">
		<header class="kdna-editor__cards-head">
			<h3><?php esc_html_e( 'Callouts', 'kdna-charts' ); ?></h3>
			<p class="description"><?php esc_html_e( 'The large number that makes the argument. Say what it means and what it points at; where it sits is worked out from what is already on the chart.', 'kdna-charts' ); ?></p>
			<button type="button" class="button button-small" x-on:click="addCallout()"><?php esc_html_e( 'Add callout', 'kdna-charts' ); ?></button>
		</header>

		<template x-for="( callout, index ) in chart.callouts" x-bind:key="'callout-' + index">
			<div class="kdna-editor__card">
				<div class="kdna-editor__field-row">
					<label class="kdna-editor__field kdna-editor__field--narrow">
						<span><?php esc_html_e( 'Figure', 'kdna-charts' ); ?></span>
						<input type="text" x-model="callout.value" placeholder="-30%" />
					</label>
					<label class="kdna-editor__field">
						<span><?php esc_html_e( 'Caption', 'kdna-charts' ); ?></span>
						<input type="text" x-model="callout.caption" placeholder="<?php esc_attr_e( 'in the first five years', 'kdna-charts' ); ?>" />
					</label>
					<label class="kdna-editor__field kdna-editor__field--narrow">
						<span><?php esc_html_e( 'Size', 'kdna-charts' ); ?></span>
						<select x-model="callout.size">
							<?php echo KDNA_Charts_Editor::enum_options( KDNA_Charts_Schema::CALLOUT_SIZES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</select>
					</label>
					<label class="kdna-editor__field kdna-editor__field--narrow">
						<span><?php esc_html_e( 'Leader', 'kdna-charts' ); ?></span>
						<select x-model="callout.leader">
							<?php echo KDNA_Charts_Editor::enum_options( KDNA_Charts_Schema::LEADERS ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</select>
					</label>
					<button type="button" class="button-link kdna-editor__remove" x-on:click="remove( 'callouts', index )">&times;</button>
				</div>

				<div class="kdna-editor__field-row kdna-editor__anchor">
					<span class="kdna-editor__anchor-label"><?php esc_html_e( 'Points at', 'kdna-charts' ); ?></span>

					<template x-if="! isSpan( callout )">
						<span class="kdna-editor__field-row">
							<label class="kdna-editor__field kdna-editor__field--narrow">
								<span><?php esc_html_e( 'x', 'kdna-charts' ); ?></span>
								<input type="text" inputmode="decimal" x-model.number="callout.anchor.x" />
							</label>
							<label class="kdna-editor__field kdna-editor__field--narrow">
								<span><?php esc_html_e( 'y', 'kdna-charts' ); ?></span>
								<input type="text" inputmode="decimal" x-model.number="callout.anchor.y" />
							</label>
						</span>
					</template>

					<template x-if="isSpan( callout )">
						<span class="kdna-editor__field-row">
							<label class="kdna-editor__field kdna-editor__field--narrow">
								<span><?php esc_html_e( 'from x', 'kdna-charts' ); ?></span>
								<input type="text" inputmode="decimal" x-model.number="callout.anchor.from.x" />
							</label>
							<label class="kdna-editor__field kdna-editor__field--narrow">
								<span><?php esc_html_e( 'from y', 'kdna-charts' ); ?></span>
								<input type="text" inputmode="decimal" x-model.number="callout.anchor.from.y" />
							</label>
							<label class="kdna-editor__field kdna-editor__field--narrow">
								<span><?php esc_html_e( 'to x', 'kdna-charts' ); ?></span>
								<input type="text" inputmode="decimal" x-model.number="callout.anchor.to.x" />
							</label>
							<label class="kdna-editor__field kdna-editor__field--narrow">
								<span><?php esc_html_e( 'to y', 'kdna-charts' ); ?></span>
								<input type="text" inputmode="decimal" x-model.number="callout.anchor.to.y" />
							</label>
						</span>
					</template>

					<button type="button" class="button button-small" x-on:click="toggleSpan( callout )">
						<span x-show="! isSpan( callout )"><?php esc_html_e( 'Make it a range', 'kdna-charts' ); ?></span>
						<span x-show="isSpan( callout )" x-cloak><?php esc_html_e( 'Make it a single point', 'kdna-charts' ); ?></span>
					</button>
				</div>

				<p class="description" x-show="isSpan( callout )" x-cloak>
					<?php esc_html_e( 'A range draws a bracket across the two points, so the figure reads as describing the span rather than a moment.', 'kdna-charts' ); ?>
				</p>
			</div>
		</template>
	</section>

	<!-- ── Notes ───────────────────────────────────────────────── -->
	<section class="kdna-editor__cards">
		<header class="kdna-editor__cards-head">
			<h3><?php esc_html_e( 'Notes', 'kdna-charts' ); ?></h3>
			<p class="description"><?php esc_html_e( 'A small aside placed in data coordinates. A long note wraps itself, and moves if something is already where it asked to sit.', 'kdna-charts' ); ?></p>
			<button type="button" class="button button-small" x-on:click="addNote()"><?php esc_html_e( 'Add note', 'kdna-charts' ); ?></button>
		</header>

		<template x-for="( note, index ) in chart.notes" x-bind:key="'note-' + index">
			<div class="kdna-editor__card">
				<div class="kdna-editor__field-row">
					<label class="kdna-editor__field kdna-editor__field--wide">
						<span><?php esc_html_e( 'Text', 'kdna-charts' ); ?></span>
						<input type="text" x-model="note.text" />
					</label>
					<label class="kdna-editor__field kdna-editor__field--narrow">
						<span><?php esc_html_e( 'x', 'kdna-charts' ); ?></span>
						<input type="text" inputmode="decimal" x-model.number="note.at.x" />
					</label>
					<label class="kdna-editor__field kdna-editor__field--narrow">
						<span><?php esc_html_e( 'y', 'kdna-charts' ); ?></span>
						<input type="text" inputmode="decimal" x-model.number="note.at.y" />
					</label>
					<label class="kdna-editor__field kdna-editor__field--narrow">
						<span><?php esc_html_e( 'Align', 'kdna-charts' ); ?></span>
						<select x-model="note.align">
							<?php echo KDNA_Charts_Editor::enum_options( KDNA_Charts_Schema::ALIGNMENTS ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</select>
					</label>
					<button type="button" class="button-link kdna-editor__remove" x-on:click="remove( 'notes', index )">&times;</button>
				</div>
			</div>
		</template>
	</section>
</div>
