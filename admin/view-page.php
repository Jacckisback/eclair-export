<?php
/**
 * Tools › Export to Éclair.
 *
 * @package EclairExport
 * @var array|null $diagnostic
 * @var array|null $job
 * @var array      $branding
 * @var array      $types
 */

defined( 'ABSPATH' ) || exit;

$report_url = static function ( $locale, $print ) {
	return wp_nonce_url( admin_url( 'admin-post.php?action=eclair_export_report&locale=' . rawurlencode( $locale ) . ( $print ? '&print=1' : '' ) ), Eclair_Export_Plugin::NONCE );
};
$user_locale = get_user_locale();
$default     = array_key_exists( $user_locale, Eclair_Export_Plugin::LOCALES ) ? $user_locale : 'fr_FR';
?>
<div class="wrap ee-wrap">
	<h1 class="ee-title"><svg viewBox="0 0 32 32" aria-hidden="true"><rect width="32" height="32" rx="9" fill="#6443cc"/><path d="M18 4 6 18h8.3L13 28l12-15.4h-8.4z" fill="#fff"/></svg><?php esc_html_e( 'Export to Éclair', 'eclair-export' ); ?></h1>
	<p class="ee-lead"><?php esc_html_e( 'Move this site to Éclair CMS without losing a single address: contents, images, SEO and redirections in one package.', 'eclair-export' ); ?></p>

	<section class="ee-step" id="ee-diagnostic">
		<header><span class="ee-num">1</span><div><h2><?php esc_html_e( 'Diagnostic', 'eclair-export' ); ?></h2><p><?php esc_html_e( 'Read only: what the site holds, what moves as is, what needs care, and how long it takes. Nothing is exported.', 'eclair-export' ); ?></p></div></header>
		<p><button type="button" class="button button-primary button-hero" id="ee-run-diagnostic"><?php echo $diagnostic ? esc_html__( 'Run the diagnostic again', 'eclair-export' ) : esc_html__( 'Run the diagnostic', 'eclair-export' ); ?></button></p>
		<div id="ee-diagnostic-result" aria-live="polite"><?php echo $diagnostic ? Eclair_Export_Plugin::summary( $diagnostic ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
		<div class="ee-report" id="ee-report"<?php echo $diagnostic ? '' : ' hidden'; ?>>
			<h3><?php esc_html_e( 'Report for the client', 'eclair-export' ); ?></h3>
			<p class="ee-muted"><?php esc_html_e( 'A page in the Éclair colours, to send or print. To get a PDF, open it then choose "Save as PDF" in the print window.', 'eclair-export' ); ?></p>
			<p class="ee-actions">
				<?php foreach ( Eclair_Export_Plugin::LOCALES as $locale => $label ) : ?>
					<span class="ee-lang<?php echo $locale === $default ? ' is-default' : ''; ?>"><strong><?php echo esc_html( $label ); ?></strong>
					<a class="button" href="<?php echo esc_url( $report_url( $locale, false ) ); ?>"><?php esc_html_e( 'HTML', 'eclair-export' ); ?></a>
					<a class="button" target="_blank" rel="noopener" href="<?php echo esc_url( $report_url( $locale, true ) ); ?>"><?php esc_html_e( 'PDF (print)', 'eclair-export' ); ?></a></span>
				<?php endforeach; ?>
			</p>
			<details class="ee-branding">
				<summary><?php esc_html_e( '"Prepared by" block of the report', 'eclair-export' ); ?></summary>
				<form id="ee-branding-form">
					<div class="ee-grid">
						<label><?php esc_html_e( 'Name', 'eclair-export' ); ?><input type="text" name="name" value="<?php echo esc_attr( $branding['name'] ); ?>"></label>
						<label><?php esc_html_e( 'Company', 'eclair-export' ); ?><input type="text" name="company" value="<?php echo esc_attr( $branding['company'] ); ?>"></label>
						<label><?php esc_html_e( 'E-mail', 'eclair-export' ); ?><input type="email" name="email" value="<?php echo esc_attr( $branding['email'] ); ?>"></label>
						<label><?php esc_html_e( 'Phone', 'eclair-export' ); ?><input type="text" name="phone" value="<?php echo esc_attr( $branding['phone'] ); ?>"></label>
						<label><?php esc_html_e( 'Website', 'eclair-export' ); ?><input type="url" name="website" value="<?php echo esc_attr( $branding['website'] ); ?>"></label>
					</div>
					<label class="ee-wide"><?php esc_html_e( 'Message of the "Next step" block (empty: default text)', 'eclair-export' ); ?><textarea name="note" rows="2"><?php echo esc_textarea( $branding['note'] ); ?></textarea></label>
					<p><button type="submit" class="button"><?php esc_html_e( 'Save', 'eclair-export' ); ?></button> <span class="ee-muted" id="ee-branding-status" aria-live="polite"></span></p>
				</form>
			</details>
		</div>
	</section>

	<section class="ee-step" id="ee-export">
		<header><span class="ee-num">2</span><div><h2><?php esc_html_e( 'Export', 'eclair-export' ); ?></h2><p><?php esc_html_e( 'The package is prepared in short steps: it works on slow shared hosting and resumes by itself after a cut. It is deleted after download, or after 24 hours.', 'eclair-export' ); ?></p></div></header>
		<form id="ee-export-form"<?php echo $job ? ' hidden' : ''; ?>>
			<fieldset>
				<legend><?php esc_html_e( 'Contents', 'eclair-export' ); ?></legend>
				<?php foreach ( $types as $name => $object ) : ?>
					<label class="ee-check"><input type="checkbox" name="types[]" value="<?php echo esc_attr( $name ); ?>" checked> <?php echo esc_html( $object->labels->name ); ?> <code><?php echo esc_html( $name ); ?></code></label>
				<?php endforeach; ?>
			</fieldset>
			<fieldset>
				<legend><?php esc_html_e( 'Media', 'eclair-export' ); ?></legend>
				<label class="ee-check"><input type="radio" name="media" value="include" checked> <?php esc_html_e( 'In the archive (recommended: the package is complete, the old site can be shut down)', 'eclair-export' ); ?></label>
				<label class="ee-check"><input type="radio" name="media" value="remote"> <?php esc_html_e( 'Downloaded from the old site during the import (lighter archive; the old site must stay online until the import is done)', 'eclair-export' ); ?></label>
				<label class="ee-inline"><?php esc_html_e( 'Archive split into parts of', 'eclair-export' ); ?>
					<select name="part_mb">
						<?php foreach ( Eclair_Export_Exporter::PARTS as $mb ) : ?>
							<option value="<?php echo (int) $mb; ?>"<?php selected( 100, $mb ); ?>><?php echo 0 === $mb ? esc_html__( 'no split (one file)', 'eclair-export' ) : esc_html( size_format( $mb * 1048576 ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<p class="ee-muted"><?php esc_html_e( 'Small parts go through the upload limits of shared hosting on the Éclair side.', 'eclair-export' ); ?></p>
			</fieldset>
			<fieldset>
				<legend><?php esc_html_e( 'Authors', 'eclair-export' ); ?></legend>
				<label class="ee-check"><input type="checkbox" name="emails" value="1"> <?php esc_html_e( 'Include the e-mail addresses of the authors (to create their Éclair accounts). Passwords are never exported.', 'eclair-export' ); ?></label>
			</fieldset>
			<p><button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Start the export', 'eclair-export' ); ?></button></p>
		</form>
		<div id="ee-progress"<?php echo $job ? '' : ' hidden'; ?>>
			<div class="ee-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span></span></div>
			<p class="ee-progress-label" aria-live="polite"></p>
			<p class="ee-error" hidden></p>
			<p class="ee-actions">
				<button type="button" class="button button-primary" id="ee-resume" hidden><?php esc_html_e( 'Resume the export', 'eclair-export' ); ?></button>
				<button type="button" class="button" id="ee-cancel"><?php esc_html_e( 'Cancel', 'eclair-export' ); ?></button>
			</p>
			<div id="ee-downloads" hidden>
				<h3><?php esc_html_e( 'Your package is ready', 'eclair-export' ); ?></h3>
				<p class="ee-muted"><?php esc_html_e( 'Download every part: each one is deleted from this server as soon as it is downloaded. Then import them in Éclair (Import WordPress).', 'eclair-export' ); ?></p>
				<ul></ul>
				<p><button type="button" class="button" id="ee-new"><?php esc_html_e( 'New export', 'eclair-export' ); ?></button></p>
			</div>
		</div>
		<p class="ee-muted ee-cli"><?php esc_html_e( 'Many sites to migrate? The same export runs from the command line:', 'eclair-export' ); ?> <code>wp eclair export --output=/path/</code></p>
	</section>
</div>
