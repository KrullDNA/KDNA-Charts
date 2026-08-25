<?php
/**
 * Shared style-control renderers.
 *
 * Included by templates/admin-style-settings.php (the global defaults
 * page) and templates/admin-style-overrides.php (the per chart panel),
 * and returns the closures they render with, so the two screens are one
 * implementation rather than two that drift.
 *
 * The caller supplies $kdna_context: 'global' for the settings page,
 * 'chart' for the per chart panel. In chart context every field gains an
 * inherit state: the control is replaced by the value coming from the
 * global defaults, greyed, with an Override button, and the panel gains
 * per-section and whole-chart resets.
 *
 * ── Addressing ────────────────────────────────────────────────────────
 *
 * A control is identified to the component by its key, and a device that
 * is empty for a flat control. Two strings are enough to reach any value
 * in state, because this schema has no nested fields: one control writes
 * one custom property, and that is the whole shape.
 *
 * A responsive control renders ONE control bound to the breakpoint
 * currently selected in its switcher, not three stacked rows. The
 * x-model path therefore reads the device out of state,
 * values['frame_padding'][device['frame_padding']]['top'], which is
 * still a plain assignable expression, so binding works exactly as it
 * does for a flat control. Switching breakpoint re-points the same
 * inputs at a different slot.
 *
 * ── Why the select options are printed here ───────────────────────────
 *
 * Alpine applies x-model to a select before an x-for INSIDE that select
 * has produced its options. With nothing to match, the select falls back
 * to its first option and never re-syncs, so the screen shows one value
 * while state holds another, and the moment anybody touches it the lie
 * is committed to the data. Every option list in this file is fixed and
 * known to PHP, so they are printed and x-model has something to bind to
 * from the first paint.
 *
 * @var string $kdna_context 'global' or 'chart'.
 *
 * @package KDNA_Charts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$kdna_context  = isset( $kdna_context ) ? $kdna_context : 'global';
$kdna_is_chart = ( 'chart' === $kdna_context );

/** Dashicon per breakpoint, for the switcher. */
$kdna_device_icons = array(
	'desktop' => 'dashicons-desktop',
	'tablet'  => 'dashicons-tablet',
	'mobile'  => 'dashicons-smartphone',
);

/**
 * The Alpine expression addressing one control's value.
 *
 * @param string $key        Control key.
 * @param bool   $responsive Whether to route through the device switcher.
 */
$kdna_path = static function ( $key, $responsive ) {
	$path = "values['" . $key . "']";
	if ( $responsive ) {
		$path .= "[device['" . $key . "']]";
	}
	return $path;
};

/** The arguments every component call takes: control key, device. */
$kdna_args = static function ( $key, $responsive ) {
	return "'" . $key . "', " . ( $responsive ? "device['" . $key . "']" : "''" );
};

/**
 * The breakpoint switcher, rendered only for responsive controls.
 *
 * The dot on a button marks a breakpoint that already carries a value,
 * so an override at a breakpoint you are not looking at is visible
 * without clicking through all three.
 */
$kdna_switcher = static function ( $key, array $devices, array $icons ) {
	?>
	<span class="kdna-style-devices" role="group" aria-label="<?php esc_attr_e( 'Breakpoint', 'kdna-charts' ); ?>">
		<?php foreach ( $devices as $device => $device_label ) : ?>
			<button
				type="button"
				class="kdna-style-devices__btn"
				:class="{
					'is-active': device['<?php echo esc_attr( $key ); ?>'] === '<?php echo esc_attr( $device ); ?>',
					'has-value': hasDeviceValue( '<?php echo esc_attr( $key ); ?>', '<?php echo esc_attr( $device ); ?>' )
				}"
				@click="device['<?php echo esc_attr( $key ); ?>'] = '<?php echo esc_attr( $device ); ?>'"
				:aria-pressed="device['<?php echo esc_attr( $key ); ?>'] === '<?php echo esc_attr( $device ); ?>' ? 'true' : 'false'"
				title="<?php echo esc_attr( $device_label ); ?>"
			>
				<span class="dashicons <?php echo esc_attr( $icons[ $device ] ); ?>" aria-hidden="true"></span>
				<span class="screen-reader-text"><?php echo esc_html( $device_label ); ?></span>
			</button>
		<?php endforeach; ?>
	</span>
	<?php
};

/**
 * The control itself, by type.
 */
$kdna_leaf = static function ( array $definition, $key, $responsive ) use ( $kdna_path, $kdna_args ) {
	$type  = isset( $definition['type'] ) ? $definition['type'] : '';
	$units = isset( $definition['units'] ) && is_array( $definition['units'] ) ? $definition['units'] : array();
	$path  = $kdna_path( $key, $responsive );
	$args  = $kdna_args( $key, $responsive );
	$min   = isset( $definition['min'] ) ? $definition['min'] : 0;
	$max   = isset( $definition['max'] ) ? $definition['max'] : 100;
	$step  = isset( $definition['step'] ) ? $definition['step'] : 1;

	$clear = static function ( $args ) {
		?>
		<button
			type="button"
			class="kdna-style-clear"
			x-show="hasDeviceValue( <?php echo esc_attr( $args ); ?> )"
			@click="clearLeaf( <?php echo esc_attr( $args ); ?> )"
			title="<?php esc_attr_e( 'Clear', 'kdna-charts' ); ?>"
		>
			<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
			<span class="screen-reader-text"><?php esc_html_e( 'Clear', 'kdna-charts' ); ?></span>
		</button>
		<?php
	};

	/*
	 * ── Colour ────────────────────────────────────────────────────
	 *
	 * The native picker cannot represent "unset": it shows #000000 for
	 * an empty value, and would write one the moment it is focused. So
	 * it is bound one way, through a swatch helper, and only writes
	 * back on a real input event. The text field beside it carries the
	 * actual value, including the rgba() and keyword forms the native
	 * picker cannot show, and transparent is one of those, which is
	 * the whole reason the text field is not optional here.
	 */
	if ( 'colour' === $type ) {
		?>
		<div class="kdna-style-colour">
			<?php
			/*
			 * The is-unset class is not decoration. A native colour input
			 * cannot show "nothing" and cannot show transparency: with no
			 * value it displays black, and six of the controls in this
			 * schema default to transparent. A black swatch beside the
			 * word transparent is a straightforward lie about what the
			 * chart will draw, so an unset swatch is struck through
			 * instead, which is what "no colour" looks like everywhere
			 * else.
			 */
			?>
			<input
				type="color"
				class="kdna-style-colour__picker"
				:class="{ 'is-unset': ! hasDeviceValue( <?php echo esc_attr( $args ); ?> ) }"
				:value="colourSwatch( <?php echo esc_attr( $args ); ?> )"
				@input="setLeaf( <?php echo esc_attr( $args ); ?>, $event.target.value )"
				aria-label="<?php esc_attr_e( 'Colour picker', 'kdna-charts' ); ?>"
			/>
			<input
				type="text"
				class="kdna-style-colour__text"
				x-model="<?php echo esc_attr( $path ); ?>"
				placeholder="<?php echo esc_attr( $definition['placeholder'] ); ?>"
				spellcheck="false"
				aria-label="<?php echo esc_attr( $definition['label'] ); ?>"
			/>
			<?php $clear( $args ); ?>
		</div>
		<?php
		return;
	}

	/*
	 * ── Slider ────────────────────────────────────────────────────
	 *
	 * Range, number, unit. The range is bound one way for the same
	 * reason as the colour picker: an unset value has to park the thumb
	 * somewhere, and parking it must not count as having set a value.
	 */
	if ( 'slider' === $type ) {
		?>
		<div class="kdna-style-slider">
			<input
				type="range"
				class="kdna-style-slider__range"
				min="<?php echo esc_attr( (string) $min ); ?>"
				max="<?php echo esc_attr( (string) $max ); ?>"
				step="<?php echo esc_attr( (string) $step ); ?>"
				:value="sliderPosition( <?php echo esc_attr( $args ); ?>, <?php echo esc_attr( (string) $min ); ?> )"
				@input="setSize( <?php echo esc_attr( $args ); ?>, $event.target.value )"
				aria-label="<?php echo esc_attr( $definition['label'] ); ?>"
			/>
			<input
				type="number"
				class="kdna-style-slider__number"
				min="<?php echo esc_attr( (string) $min ); ?>"
				max="<?php echo esc_attr( (string) $max ); ?>"
				step="<?php echo esc_attr( (string) $step ); ?>"
				x-model="<?php echo esc_attr( $path . "['size']" ); ?>"
				placeholder="&mdash;"
				aria-label="<?php echo esc_attr( $definition['label'] ); ?>"
			/>
			<?php if ( count( $units ) > 1 ) : ?>
				<select class="kdna-style-unit" x-model="<?php echo esc_attr( $path . "['unit']" ); ?>" aria-label="<?php esc_attr_e( 'Unit', 'kdna-charts' ); ?>">
					<?php foreach ( $units as $unit ) : ?>
						<option value="<?php echo esc_attr( $unit ); ?>"><?php echo '' === $unit ? esc_html__( 'none', 'kdna-charts' ) : esc_html( $unit ); ?></option>
					<?php endforeach; ?>
				</select>
			<?php elseif ( ! empty( $units ) ) : ?>
				<span class="kdna-style-unit kdna-style-unit--fixed"><?php echo '' === $units[0] ? esc_html__( 'none', 'kdna-charts' ) : esc_html( $units[0] ); ?></span>
			<?php endif; ?>
			<?php $clear( $args ); ?>
		</div>
		<?php
		return;
	}

	/*
	 * ── Dimensions ────────────────────────────────────────────────
	 *
	 * Four sides, a unit, and a link toggle. Linked, editing any side
	 * writes all four, which is how a page builder's padding behaves.
	 * The link state is stored alongside the value so it survives a
	 * reload; the resolver and the sanitiser both ignore it.
	 */
	if ( 'dimensions' === $type ) {
		?>
		<div class="kdna-style-dimensions">
			<?php
			$sides = array(
				'top'    => __( 'Top', 'kdna-charts' ),
				'right'  => __( 'Right', 'kdna-charts' ),
				'bottom' => __( 'Bottom', 'kdna-charts' ),
				'left'   => __( 'Left', 'kdna-charts' ),
			);
			foreach ( $sides as $side => $side_label ) :
				?>
				<label class="kdna-style-dimensions__side">
					<input
						type="number"
						step="any"
						x-model="<?php echo esc_attr( $path . "['" . $side . "']" ); ?>"
						@input="syncLinked( <?php echo esc_attr( $args ); ?>, $event.target.value )"
						placeholder="&mdash;"
						aria-label="<?php echo esc_attr( $definition['label'] . ', ' . $side_label ); ?>"
					/>
					<span class="kdna-style-dimensions__label"><?php echo esc_html( $side_label ); ?></span>
				</label>
			<?php endforeach; ?>

			<?php if ( count( $units ) > 1 ) : ?>
				<select class="kdna-style-unit" x-model="<?php echo esc_attr( $path . "['unit']" ); ?>" aria-label="<?php esc_attr_e( 'Unit', 'kdna-charts' ); ?>">
					<?php foreach ( $units as $unit ) : ?>
						<option value="<?php echo esc_attr( $unit ); ?>"><?php echo '' === $unit ? esc_html__( 'none', 'kdna-charts' ) : esc_html( $unit ); ?></option>
					<?php endforeach; ?>
				</select>
			<?php endif; ?>

			<button
				type="button"
				class="kdna-style-link"
				:class="{ 'is-linked': isLinked( <?php echo esc_attr( $args ); ?> ) }"
				@click="toggleLink( <?php echo esc_attr( $args ); ?> )"
				:aria-pressed="isLinked( <?php echo esc_attr( $args ); ?> ) ? 'true' : 'false'"
				title="<?php esc_attr_e( 'Link sides', 'kdna-charts' ); ?>"
			>
				<span class="dashicons" :class="isLinked( <?php echo esc_attr( $args ); ?> ) ? 'dashicons-admin-links' : 'dashicons-editor-unlink'" aria-hidden="true"></span>
				<span class="screen-reader-text"><?php esc_html_e( 'Link sides', 'kdna-charts' ); ?></span>
			</button>

			<?php $clear( $args ); ?>
		</div>
		<?php
		return;
	}

	/* ── Number ────────────────────────────────────────────────────── */
	if ( 'number' === $type ) {
		?>
		<div class="kdna-style-number">
			<input
				type="number"
				min="<?php echo esc_attr( (string) $min ); ?>"
				max="<?php echo esc_attr( (string) $max ); ?>"
				step="<?php echo esc_attr( (string) $step ); ?>"
				x-model="<?php echo esc_attr( $path ); ?>"
				placeholder="<?php echo esc_attr( $definition['placeholder'] ); ?>"
				aria-label="<?php echo esc_attr( $definition['label'] ); ?>"
			/>
			<?php $clear( $args ); ?>
		</div>
		<?php
		return;
	}

	/*
	 * ── Select ────────────────────────────────────────────────────
	 *
	 * The empty option is prepended rather than assumed. A select whose
	 * options carry no empty key cannot show the unset state: the
	 * browser falls back to displaying the first one, so an untouched
	 * Alignment control would read as "Left" while nothing is stored.
	 * Choosing the empty option stores nothing, and the value falls
	 * back through the layers as any other unset control does.
	 */
	if ( 'select' === $type && empty( $definition['free_text'] ) ) {
		$options = isset( $definition['options'] ) && is_array( $definition['options'] ) ? $definition['options'] : array();
		if ( ! array_key_exists( '', $options ) ) {
			$options = array(
				/* translators: %s: the value the stylesheet renders when nothing is set. */
				'' => sprintf( __( 'Default (%s)', 'kdna-charts' ), $definition['placeholder'] ),
			) + $options;
		}
		?>
		<select
			class="kdna-style-input"
			x-model="<?php echo esc_attr( $path ); ?>"
			aria-label="<?php echo esc_attr( $definition['label'] ); ?>"
		>
			<?php foreach ( $options as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
		return;
	}

	/*
	 * ── Free text, with suggestions ───────────────────────────────
	 *
	 * A font stack or a dash pattern: open by nature, so a text field
	 * with a datalist rather than an allow-list. The suggestions come
	 * from the schema entry, which leads them with the value that
	 * clears the field.
	 */
	$suggestions = isset( $definition['suggestions'] ) && is_array( $definition['suggestions'] )
		? $definition['suggestions']
		: array();
	$list_id     = 'kdna-style-list-' . sanitize_html_class( $key );
	?>
	<div class="kdna-style-number">
		<input
			type="text"
			class="kdna-style-input"
			x-model="<?php echo esc_attr( $path ); ?>"
			<?php if ( ! empty( $suggestions ) ) : ?>list="<?php echo esc_attr( $list_id ); ?>"<?php endif; ?>
			placeholder="<?php echo esc_attr( $definition['placeholder'] ); ?>"
			spellcheck="false"
			aria-label="<?php echo esc_attr( $definition['label'] ); ?>"
		/>
		<?php if ( ! empty( $suggestions ) ) : ?>
			<datalist id="<?php echo esc_attr( $list_id ); ?>">
				<?php foreach ( $suggestions as $suggestion ) : ?>
					<option value="<?php echo esc_attr( $suggestion ); ?>"></option>
				<?php endforeach; ?>
			</datalist>
		<?php endif; ?>
		<?php $clear( $args ); ?>
	</div>
	<?php
};

/**
 * One field row: label, breakpoint switcher, reset, and the control.
 */
$kdna_field = static function ( array $definition, $key, array $devices, array $icons ) use ( $kdna_leaf, $kdna_switcher, $kdna_is_chart ) {
	$responsive = ! empty( $definition['responsive'] );
	$k          = esc_attr( $key );
	?>
	<div
		class="kdna-style-field"
		x-show="matches( '<?php echo $k; ?>' )"
		<?php if ( $kdna_is_chart ) : ?>:class="{ 'is-inherited': ! isOverridden( '<?php echo $k; ?>' ) }"<?php endif; ?>
	>
		<div class="kdna-style-field__head">
			<span class="kdna-style-field__label"><?php echo esc_html( $definition['label'] ); ?></span>

			<span class="kdna-style-field__tools">
				<?php
				/*
				 * The stylesheet's own value, on the global page only.
				 *
				 * On a chart it would be the wrong fallback to name. What
				 * a chart falls back to is the global default, and the
				 * Inheriting row below already says what that is, so
				 * showing this as well would put two answers to one
				 * question on every row, and one of them would be wrong
				 * whenever the global had been set.
				 */
				?>
				<?php if ( ! $kdna_is_chart && '' !== $definition['placeholder'] ) : ?>
					<span class="kdna-style-field__default">
						<?php
						printf(
							/* translators: %s: the value the stylesheet renders when nothing is set. */
							esc_html__( 'Default: %s', 'kdna-charts' ),
							'<code>' . esc_html( $definition['placeholder'] ) . '</code>'
						);
						?>
					</span>
				<?php endif; ?>

				<?php if ( $responsive ) : ?>
					<span <?php if ( $kdna_is_chart ) : ?>x-show="isOverridden( '<?php echo $k; ?>' )"<?php endif; ?>>
						<?php $kdna_switcher( $key, $devices, $icons ); ?>
					</span>
				<?php endif; ?>

				<?php if ( $kdna_is_chart ) : ?>
					<?php
					/*
					 * Two buttons, never both. Inherited shows Override,
					 * which seeds the control from the value it is
					 * currently inheriting, so the user starts from what
					 * they can see rather than from blank. Overridden
					 * shows Revert, which clears the override and lets
					 * the global show through again.
					 */
					?>
					<button
						type="button"
						class="kdna-style-field__override"
						x-show="! isOverridden( '<?php echo $k; ?>' )"
						@click="override( '<?php echo $k; ?>' )"
						title="<?php esc_attr_e( 'Override the global default for this chart', 'kdna-charts' ); ?>"
					><?php esc_html_e( 'Override', 'kdna-charts' ); ?></button>

					<button
						type="button"
						class="kdna-style-field__reset"
						x-show="isOverridden( '<?php echo $k; ?>' )"
						@click="revert( '<?php echo $k; ?>' )"
						title="<?php esc_attr_e( 'Drop this override and follow the global default again', 'kdna-charts' ); ?>"
					><?php esc_html_e( 'Revert to global', 'kdna-charts' ); ?></button>
				<?php else : ?>
					<button
						type="button"
						class="kdna-style-field__reset"
						x-show="hasValue( '<?php echo $k; ?>' )"
						@click="resetControl( '<?php echo $k; ?>' )"
						title="<?php esc_attr_e( 'Clear this control back to inherit, at every breakpoint', 'kdna-charts' ); ?>"
					><?php esc_html_e( 'Reset', 'kdna-charts' ); ?></button>
				<?php endif; ?>
			</span>
		</div>

		<?php if ( '' !== $definition['description'] ) : ?>
			<p class="kdna-style-field__description"><?php echo esc_html( $definition['description'] ); ?></p>
		<?php endif; ?>

		<?php if ( $kdna_is_chart ) : ?>
			<?php /* The inherited value, greyed, in place of the control. */ ?>
			<p class="kdna-style-inherited" x-show="! isOverridden( '<?php echo $k; ?>' )">
				<span class="kdna-style-inherited__label"><?php esc_html_e( 'Inheriting', 'kdna-charts' ); ?></span>
				<span class="kdna-style-inherited__value" x-text="inheritedLabel( '<?php echo $k; ?>' )"></span>
			</p>
			<div x-show="isOverridden( '<?php echo $k; ?>' )">
				<?php $kdna_leaf( $definition, $key, $responsive ); ?>
			</div>
		<?php else : ?>
			<?php $kdna_leaf( $definition, $key, $responsive ); ?>
		<?php endif; ?>
	</div>
	<?php
};

/**
 * The tab list and control panel, shared by both screens.
 *
 * In chart context each section header gains a reset that clears every
 * override in it, so somebody who has gone too far on one section can
 * back out of that section rather than the whole chart.
 */
$kdna_panel = static function ( array $sections, array $grouped, array $devices ) use ( $kdna_field, $kdna_device_icons, $kdna_is_chart ) {
	?>
	<div class="kdna-style-admin__body">

		<nav class="kdna-style-tabs" aria-label="<?php esc_attr_e( 'Style sections', 'kdna-charts' ); ?>">
			<?php
			foreach ( $sections as $section_key => $section_label ) :
				$count = isset( $grouped[ $section_key ] ) ? count( $grouped[ $section_key ] ) : 0;
				?>
				<button
					type="button"
					class="kdna-style-tab"
					:class="{ 'is-active': section === '<?php echo esc_attr( $section_key ); ?>' }"
					@click="section = '<?php echo esc_attr( $section_key ); ?>'"
					:aria-current="section === '<?php echo esc_attr( $section_key ); ?>' ? 'true' : 'false'"
				>
					<span class="kdna-style-tab__label"><?php echo esc_html( $section_label ); ?></span>
					<?php if ( $count > 0 ) : ?>
						<span class="kdna-style-tab__count"><?php echo esc_html( (string) $count ); ?></span>
					<?php endif; ?>
					<?php
					/*
					 * A dot on the tab for a section that carries a value,
					 * so a setting left behind in a section nobody is
					 * looking at is still visible. With thirteen sections
					 * and a hundred and fifty controls, "why does this
					 * chart look like that" is otherwise a hunt.
					 */
					?>
					<span
						class="kdna-style-tab__dot"
						x-show="sectionHasValues( '<?php echo esc_attr( $section_key ); ?>' )"
						aria-hidden="true"
					></span>
				</button>
			<?php endforeach; ?>
		</nav>

		<div class="kdna-style-panel">

			<div class="kdna-style-search">
				<label class="screen-reader-text" for="kdna-style-search-<?php echo esc_attr( $kdna_is_chart ? 'chart' : 'global' ); ?>">
					<?php esc_html_e( 'Filter controls', 'kdna-charts' ); ?>
				</label>
				<input
					type="search"
					id="kdna-style-search-<?php echo esc_attr( $kdna_is_chart ? 'chart' : 'global' ); ?>"
					class="kdna-style-search__input"
					x-model="query"
					placeholder="<?php esc_attr_e( 'Filter controls, e.g. label size', 'kdna-charts' ); ?>"
					spellcheck="false"
				/>
				<?php
				/*
				 * Filtering searches every section, not the open one, and
				 * says how many it found where. A control you cannot name
				 * the section of is the common case at this size.
				 */
				?>
				<span class="kdna-style-search__count" x-show="query.trim()" x-text="searchSummary()"></span>
			</div>

			<?php foreach ( $sections as $section_key => $section_label ) : ?>
				<section
					class="kdna-style-panel__section"
					x-show="section === '<?php echo esc_attr( $section_key ); ?>'"
					aria-labelledby="kdna-style-heading-<?php echo esc_attr( $section_key ); ?>"
				>
					<div class="kdna-style-panel__header">
						<h2 id="kdna-style-heading-<?php echo esc_attr( $section_key ); ?>" class="kdna-style-panel__heading">
							<?php echo esc_html( $section_label ); ?>
						</h2>
						<button
							type="button"
							class="kdna-style-field__reset"
							x-show="sectionHasValues( '<?php echo esc_attr( $section_key ); ?>' )"
							@click="resetSection( '<?php echo esc_attr( $section_key ); ?>' )"
						>
							<?php
							echo $kdna_is_chart
								? esc_html__( 'Reset this section to inherit', 'kdna-charts' )
								: esc_html__( 'Reset this section', 'kdna-charts' );
							?>
						</button>
					</div>

					<?php if ( empty( $grouped[ $section_key ] ) ) : ?>
						<p class="kdna-style-panel__empty">
							<?php esc_html_e( 'No controls in this section.', 'kdna-charts' ); ?>
						</p>
					<?php else : ?>
						<?php
						$open_group = null;
						foreach ( $grouped[ $section_key ] as $control_key => $definition ) :
							$group = $definition['group'];

							if ( $group !== $open_group ) {
								if ( null !== $open_group ) {
									echo '</div>';
								}
								if ( '' !== $group ) {
									printf(
										'<h3 class="kdna-style-group">%s</h3>',
										esc_html( $group )
									);
								}
								echo '<div class="kdna-style-group__body">';
								$open_group = $group;
							}

							$kdna_field( $definition, $control_key, $devices, $kdna_device_icons );
						endforeach;

						if ( null !== $open_group ) {
							echo '</div>';
						}
						?>

						<p class="kdna-style-panel__empty" x-show="query.trim() && ! sectionMatches( '<?php echo esc_attr( $section_key ); ?>' )">
							<?php esc_html_e( 'No controls in this section match that.', 'kdna-charts' ); ?>
						</p>
					<?php endif; ?>
				</section>
			<?php endforeach; ?>
		</div>
	</div>
	<?php
};

return array(
	'panel'    => $kdna_panel,
	'field'    => $kdna_field,
	'leaf'     => $kdna_leaf,
	'switcher' => $kdna_switcher,
	'icons'    => $kdna_device_icons,
);
