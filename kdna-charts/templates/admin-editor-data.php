<?php
/**
 * The Data tab.
 *
 * Two grids, because there are two shapes of data. Line and area
 * charts are runs of x and y points grouped into segments; everything
 * else is a flat table of categories against series.
 *
 * Both take a paste. That is the point of a grid rather than a set of
 * fields: the data almost always already exists in a spreadsheet, and
 * retyping it is where the mistakes come from.
 *
 * @package KDNA_Charts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="kdna-editor__section">

	<p class="kdna-editor__paste-notice" x-show="pasteNotice" x-cloak x-text="pasteNotice"></p>

	<!-- ────────────────────────────────────────────────────────────
	     Line and area: segments of points
	     ──────────────────────────────────────────────────────── -->
	<template x-if="isPlotted">
		<div class="kdna-editor__series-list">
			<template x-for="( series, seriesIndex ) in chart.series" x-bind:key="series.id">
				<div class="kdna-editor__series">
					<div class="kdna-editor__series-head">
						<label class="kdna-editor__field">
							<span><?php esc_html_e( 'Series name', 'kdna-charts' ); ?></span>
							<input type="text" x-model="series.label" />
						</label>
						<button
							type="button"
							class="button-link kdna-editor__remove"
							x-on:click="removeSeries( seriesIndex )"
							x-show="chart.series.length > 1"
						><?php esc_html_e( 'Remove series', 'kdna-charts' ); ?></button>
					</div>

					<template x-for="( segment, segmentIndex ) in series.segments" x-bind:key="seriesIndex + '-' + segmentIndex">
						<div class="kdna-editor__segment">
							<div class="kdna-editor__segment-head">
								<strong x-text="'<?php echo esc_js( __( 'Segment', 'kdna-charts' ) ); ?> ' + ( segmentIndex + 1 )"></strong>

								<label class="kdna-editor__field kdna-editor__field--inline">
									<span><?php esc_html_e( 'Line', 'kdna-charts' ); ?></span>
									<select x-model="segment.style">
										<?php echo KDNA_Charts_Editor::enum_options( KDNA_Charts_Schema::LINE_STYLES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									</select>
								</label>

								<label class="kdna-editor__field kdna-editor__field--inline">
									<span><?php esc_html_e( 'Emphasis', 'kdna-charts' ); ?></span>
									<select x-model="segment.emphasis">
										<?php echo KDNA_Charts_Editor::enum_options( KDNA_Charts_Schema::EMPHASIS ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									</select>
								</label>

								<button
									type="button"
									class="button-link"
									x-show="segmentIndex > 0"
									x-on:click="mergeSegment( series, segmentIndex )"
								><?php esc_html_e( 'Join to the one above', 'kdna-charts' ); ?></button>

								<button
									type="button"
									class="button-link kdna-editor__remove"
									x-show="series.segments.length > 1"
									x-on:click="removeSegment( series, segmentIndex )"
								><?php esc_html_e( 'Remove', 'kdna-charts' ); ?></button>
							</div>

							<table class="kdna-editor__grid kdna-editor__grid--points">
								<thead>
									<tr>
										<th class="kdna-editor__grid-index"></th>
										<th><?php esc_html_e( 'x', 'kdna-charts' ); ?></th>
										<th><?php esc_html_e( 'y', 'kdna-charts' ); ?></th>
										<th class="kdna-editor__grid-actions"></th>
									</tr>
								</thead>
								<tbody>
									<template x-for="( point, pointIndex ) in segment.points" x-bind:key="pointIndex">
										<tr>
											<td class="kdna-editor__grid-index" x-text="pointIndex + 1"></td>
											<td>
												<input
													type="text"
													inputmode="decimal"
													x-model.number="segment.points[ pointIndex ][ 0 ]"
													x-on:paste="pastePoints( $event, segment, pointIndex, 0 )"
												/>
											</td>
											<td>
												<input
													type="text"
													inputmode="decimal"
													x-model.number="segment.points[ pointIndex ][ 1 ]"
													x-on:paste="pastePoints( $event, segment, pointIndex, 1 )"
												/>
											</td>
											<td class="kdna-editor__grid-actions">
												<button
													type="button"
													class="button-link"
													x-show="canSplit( segment, pointIndex )"
													x-on:click="splitSegment( series, segmentIndex, pointIndex )"
													title="<?php esc_attr_e( 'Start a new segment here, so the line can change character at this point', 'kdna-charts' ); ?>"
												><?php esc_html_e( 'Split here', 'kdna-charts' ); ?></button>
												<button type="button" class="button-link" x-on:click="addPoint( segment, pointIndex )">+</button>
												<button
													type="button"
													class="button-link kdna-editor__remove"
													x-on:click="removePoint( segment, pointIndex )"
												>&times;</button>
											</td>
										</tr>
									</template>
								</tbody>
							</table>

							<p class="kdna-editor__grid-foot">
								<button type="button" class="button button-small" x-on:click="addPoint( segment )">
									<?php esc_html_e( 'Add point', 'kdna-charts' ); ?>
								</button>
							</p>
						</div>
					</template>

					<p>
						<button type="button" class="button button-small" x-on:click="addSegment( series )">
							<?php esc_html_e( 'Add segment', 'kdna-charts' ); ?>
						</button>
					</p>
				</div>
			</template>

			<p>
				<button type="button" class="button" x-on:click="addSeries()">
					<?php esc_html_e( 'Add series', 'kdna-charts' ); ?>
				</button>
			</p>

			<p class="description">
				<?php esc_html_e( 'Paste two columns of numbers into any cell to fill the grid from there. Split a segment where the line should change character, a dotted projection becoming a solid measurement for instance, and the point you split on stays in both halves so the two join seamlessly.', 'kdna-charts' ); ?>
			</p>
		</div>
	</template>

	<!-- ────────────────────────────────────────────────────────────
	     Everything else: categories against series
	     ──────────────────────────────────────────────────────── -->
	<template x-if="isCategorical">
		<div>
			<table class="kdna-editor__grid kdna-editor__grid--wide">
				<thead>
					<tr>
						<th class="kdna-editor__grid-index"></th>
						<th><?php esc_html_e( 'Label', 'kdna-charts' ); ?></th>
						<template x-for="( series, seriesIndex ) in chart.series" x-bind:key="series.id">
							<th>
								<input
									type="text"
									class="kdna-editor__series-name"
									x-model="series.label"
									placeholder="<?php esc_attr_e( 'Series', 'kdna-charts' ); ?>"
								/>
								<button
									type="button"
									class="button-link kdna-editor__remove"
									x-show="chart.series.length > 1"
									x-on:click="removeSeries( seriesIndex )"
								>&times;</button>
							</th>
						</template>
						<th class="kdna-editor__grid-actions"></th>
					</tr>
				</thead>
				<tbody>
					<template x-for="row in rows" x-bind:key="row">
						<tr>
							<td class="kdna-editor__grid-index" x-text="row + 1"></td>
							<td>
								<input
									type="text"
									x-bind:value="rowLabel( row )"
									x-on:input="setRowLabel( row, $event.target.value )"
									x-on:paste="pasteLabels( $event, row )"
								/>
							</td>
							<template x-for="( series, seriesIndex ) in chart.series" x-bind:key="series.id">
								<td>
									<input
										type="text"
										inputmode="decimal"
										x-model.number="cell( series, row ).value"
										x-on:paste="pasteCategorical( $event, row, seriesIndex )"
									/>
								</td>
							</template>
							<td class="kdna-editor__grid-actions">
								<button type="button" class="button-link kdna-editor__remove" x-on:click="removeRow( row )">&times;</button>
							</td>
						</tr>
					</template>
				</tbody>
			</table>

			<p class="kdna-editor__grid-foot">
				<button type="button" class="button button-small" x-on:click="addRow()">
					<?php esc_html_e( 'Add row', 'kdna-charts' ); ?>
				</button>
				<button type="button" class="button button-small" x-on:click="addSeries()">
					<?php esc_html_e( 'Add series', 'kdna-charts' ); ?>
				</button>
			</p>

			<p class="description">
				<?php esc_html_e( 'Paste a block from a spreadsheet into any cell and it fills the grid from there, adding rows and series as it needs them. Paste into the Label column to bring the names in with the figures.', 'kdna-charts' ); ?>
			</p>
		</div>
	</template>

	<!-- ────────────────────────────────────────────────────────────
	     Axes, for the types that have them
	     ──────────────────────────────────────────────────────── -->
	<template x-if="usesAxes">
		<div class="kdna-editor__axes">
			<h3><?php esc_html_e( 'Axes', 'kdna-charts' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Leave a bound empty to work it out from the data. The x axis is always the category axis and the y axis always the value axis, whichever way a bar chart points.', 'kdna-charts' ); ?>
			</p>

			<div class="kdna-editor__axis-grid">
				<template x-for="axis in [ 'x', 'y' ]" x-bind:key="axis">
					<fieldset class="kdna-editor__axis">
						<legend x-text="axis.toUpperCase() + ' <?php echo esc_js( __( 'axis', 'kdna-charts' ) ); ?>'"></legend>

						<label class="kdna-editor__field">
							<span><?php esc_html_e( 'Title', 'kdna-charts' ); ?></span>
							<input type="text" x-model="chart.axes[ axis ].label" />
						</label>

						<div class="kdna-editor__field-row">
							<label class="kdna-editor__field">
								<span><?php esc_html_e( 'Min', 'kdna-charts' ); ?></span>
								<input type="text" inputmode="decimal" x-model.number="chart.axes[ axis ].min" placeholder="<?php esc_attr_e( 'auto', 'kdna-charts' ); ?>" />
							</label>
							<label class="kdna-editor__field">
								<span><?php esc_html_e( 'Max', 'kdna-charts' ); ?></span>
								<input type="text" inputmode="decimal" x-model.number="chart.axes[ axis ].max" placeholder="<?php esc_attr_e( 'auto', 'kdna-charts' ); ?>" />
							</label>
							<label class="kdna-editor__field" x-show="axis === 'y'">
								<span><?php esc_html_e( 'Baseline', 'kdna-charts' ); ?></span>
								<input type="text" inputmode="decimal" x-model.number="chart.axes[ axis ].baseline" placeholder="<?php esc_attr_e( 'auto', 'kdna-charts' ); ?>" />
							</label>
						</div>
					</fieldset>
				</template>
			</div>
		</div>
	</template>

	<!-- ────────────────────────────────────────────────────────────
	     Caption and source
	     ──────────────────────────────────────────────────────── -->
	<div class="kdna-editor__field-row kdna-editor__field-row--wide">
		<label class="kdna-editor__field">
			<span><?php esc_html_e( 'Caption', 'kdna-charts' ); ?></span>
			<input type="text" x-model="chart.caption" />
		</label>
		<label class="kdna-editor__field">
			<span><?php esc_html_e( 'Source', 'kdna-charts' ); ?></span>
			<input type="text" x-model="chart.source" placeholder="<?php esc_attr_e( 'Who the figures came from', 'kdna-charts' ); ?>" />
		</label>
	</div>
</div>
