<?php
/**
 * The Import screen. Two ways in, a file and a paste, and the schema
 * reference printed beneath them so the authoring prompt can be
 * assembled without leaving WordPress.
 *
 * Variables expected from the caller:
 * - $import_action_url  string  admin-post.php
 * - $action_name        string  The admin_post action name
 * - $nonce_action       string  Nonce action the handler checks
 * - $file_field         string  Name of the file input
 * - $paste_field        string  Name of the textarea
 * - $max_bytes          int     Largest file the importer will read
 * - $schema_reference   string  Contents of docs/chart-schema.md
 * - $authoring_prompt   string  The full prompt, schema reference folded in
 * - $error              array|null  Flash error, keys code and message
 *
 * @package KDNA_Charts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap kdna-import">
	<h1><?php esc_html_e( 'Import Chart', 'kdna-charts' ); ?></h1>

	<?php if ( is_array( $error ) && ! empty( $error['message'] ) ) : ?>
		<div class="notice notice-error">
			<p><?php echo esc_html( $error['message'] ); ?></p>
		</div>
	<?php endif; ?>

	<p class="kdna-import__intro">
		<?php esc_html_e( 'A chart definition is a single JSON file. Write one in a Claude conversation from a paragraph of article text, then bring it in here. The importer never rejects a file for having a key it does not recognise, it takes what it understands and tells you what it left behind.', 'kdna-charts' ); ?>
	</p>

	<div class="kdna-import__panels">

		<div class="kdna-import__panel">
			<h2><?php esc_html_e( 'Upload a file', 'kdna-charts' ); ?></h2>
			<form method="post" action="<?php echo esc_url( $import_action_url ); ?>" enctype="multipart/form-data">
				<?php wp_nonce_field( $nonce_action ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( $action_name ); ?>" />

				<p>
					<label class="screen-reader-text" for="<?php echo esc_attr( $file_field ); ?>">
						<?php esc_html_e( 'Chart definition file', 'kdna-charts' ); ?>
					</label>
					<input
						type="file"
						id="<?php echo esc_attr( $file_field ); ?>"
						name="<?php echo esc_attr( $file_field ); ?>"
						accept=".json,application/json,text/plain"
						required
					/>
				</p>
				<p class="description">
					<?php
					printf(
						/* translators: %s: maximum file size, already formatted */
						esc_html__( 'A .json file, up to %s.', 'kdna-charts' ),
						esc_html( size_format( $max_bytes ) )
					);
					?>
				</p>
				<p>
					<button type="submit" class="button button-primary">
						<?php esc_html_e( 'Import file', 'kdna-charts' ); ?>
					</button>
				</p>
			</form>
		</div>

		<div class="kdna-import__panel">
			<h2><?php esc_html_e( 'Paste a definition', 'kdna-charts' ); ?></h2>
			<form method="post" action="<?php echo esc_url( $import_action_url ); ?>">
				<?php wp_nonce_field( $nonce_action ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( $action_name ); ?>" />

				<p>
					<label class="screen-reader-text" for="<?php echo esc_attr( $paste_field ); ?>">
						<?php esc_html_e( 'Chart definition JSON', 'kdna-charts' ); ?>
					</label>
					<textarea
						id="<?php echo esc_attr( $paste_field ); ?>"
						name="<?php echo esc_attr( $paste_field ); ?>"
						class="large-text code kdna-import__textarea"
						rows="12"
						spellcheck="false"
						placeholder="<?php esc_attr_e( '{ "kdna_chart": 1, "title": "...", "type": "line", ... }', 'kdna-charts' ); ?>"
					></textarea>
				</p>
				<p class="description">
					<?php esc_html_e( 'Code fences around the JSON are stripped automatically, so pasting straight out of a conversation is fine.', 'kdna-charts' ); ?>
				</p>
				<p>
					<button type="submit" class="button button-primary">
						<?php esc_html_e( 'Import pasted JSON', 'kdna-charts' ); ?>
					</button>
				</p>
			</form>
		</div>

	</div>

	<hr class="kdna-import__rule" />

	<h2><?php esc_html_e( 'Authoring a chart in a Claude conversation', 'kdna-charts' ); ?></h2>
	<p class="kdna-import__intro">
		<?php esc_html_e( 'Copy the prompt below, paste it into a Claude conversation, and put the article text where it asks for it. The schema reference is already folded into the prompt, so nothing else needs assembling. What comes back can be pasted straight into the box above.', 'kdna-charts' ); ?>
	</p>

	<p class="kdna-import__actions">
		<button
			type="button"
			class="button button-primary kdna-copy"
			data-kdna-copy="kdna-authoring-prompt"
			data-kdna-copied="<?php esc_attr_e( 'Prompt copied', 'kdna-charts' ); ?>"
		>
			<?php esc_html_e( 'Copy the full authoring prompt', 'kdna-charts' ); ?>
		</button>
		<button
			type="button"
			class="button kdna-copy"
			data-kdna-copy="kdna-schema-reference"
			data-kdna-copied="<?php esc_attr_e( 'Reference copied', 'kdna-charts' ); ?>"
		>
			<?php esc_html_e( 'Copy the schema reference only', 'kdna-charts' ); ?>
		</button>
	</p>

	<?php
	/*
	 * Both blocks live in read only textareas rather than pre elements.
	 * A textarea gives the copy button something to select from when the
	 * asynchronous clipboard API is unavailable, which is the case on any
	 * site still served over plain HTTP.
	 */
	?>
	<textarea
		id="kdna-authoring-prompt"
		class="kdna-import__hidden-source"
		readonly
		aria-hidden="true"
		tabindex="-1"
	><?php echo esc_textarea( $authoring_prompt ); ?></textarea>

	<details class="kdna-import__reference">
		<summary><?php esc_html_e( 'Read the schema reference', 'kdna-charts' ); ?></summary>
		<?php if ( '' === $schema_reference ) : ?>
			<p class="notice notice-warning">
				<?php esc_html_e( 'The schema reference file is missing from the plugin. Reinstall the plugin to restore docs/chart-schema.md.', 'kdna-charts' ); ?>
			</p>
		<?php else : ?>
			<label class="screen-reader-text" for="kdna-schema-reference">
				<?php esc_html_e( 'Chart definition schema reference', 'kdna-charts' ); ?>
			</label>
			<textarea
				id="kdna-schema-reference"
				class="kdna-import__reference-text code"
				readonly
				rows="24"
				spellcheck="false"
			><?php echo esc_textarea( $schema_reference ); ?></textarea>
		<?php endif; ?>
	</details>
</div>
