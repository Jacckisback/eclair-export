<?php
/**
 * Tools › Export to Éclair.
 *
 * @package EclairExport
 * @var array|null $diagnostic
 * @var array|null $job
 * @var array      $branding
 * @var array      $types
 * @var bool       $sending
 */

defined( 'ABSPATH' ) || exit;

$report_url = static function ( $locale, $print ) {
	return wp_nonce_url( admin_url( 'admin-post.php?action=eclair_export_report&locale=' . rawurlencode( $locale ) . ( $print ? '&print=1' : '' ) ), Eclair_Export_Plugin::NONCE );
};
$user_locale = get_user_locale();
$default     = array_key_exists( $user_locale, Eclair_Export_Plugin::LOCALES ) ? $user_locale : 'fr_FR';
?>
<div class="wrap ee-wrap">
	<h1 class="screen-reader-text"><?php esc_html_e( 'Export to Éclair', 'eclair-export' ); ?></h1>
	<hr class="wp-header-end">
	<header class="ee-hero">
		<div class="ee-hero-top">
			<svg viewBox="0 0 32 32" aria-hidden="true"><rect width="32" height="32" rx="9" fill="#fff"/><path d="M18 4 6 18h8.3L13 28l12-15.4h-8.4z" fill="#6443cc"/></svg>
			<div>
				<p class="ee-hero-title"><?php esc_html_e( 'Move this site to Éclair', 'eclair-export' ); ?></p>
				<p class="ee-lead"><?php esc_html_e( 'Contents, images, SEO and redirections travel together. Every address is kept or redirected: no link is lost.', 'eclair-export' ); ?></p>
			</div>
		</div>
		<ol class="ee-stepper">
			<li data-ee-stage="diagnostic"><span>1</span><?php esc_html_e( 'Diagnostic', 'eclair-export' ); ?></li>
			<li data-ee-stage="migration"><span>2</span><?php esc_html_e( 'Migration', 'eclair-export' ); ?></li>
			<li data-ee-stage="eclair"><span>3</span><?php esc_html_e( 'Import in Éclair', 'eclair-export' ); ?></li>
		</ol>
	</header>

	<section class="ee-step" id="ee-diagnostic">
		<header><span class="ee-num">1</span><div><h2><?php esc_html_e( 'Diagnostic', 'eclair-export' ); ?></h2><p><?php esc_html_e( 'Read only: what the site holds, what moves as is, what needs care, and how long it takes. Nothing is exported.', 'eclair-export' ); ?></p></div></header>
		<p><button type="button" class="button <?php echo $diagnostic ? '' : 'button-primary button-hero'; ?>" id="ee-run-diagnostic"><?php echo $diagnostic ? esc_html__( 'Run the diagnostic again', 'eclair-export' ) : esc_html__( 'Run the diagnostic', 'eclair-export' ); ?></button></p>
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
		<header><span class="ee-num">2</span><div><h2><?php esc_html_e( 'Migration', 'eclair-export' ); ?></h2><p><?php esc_html_e( 'The package is prepared in short steps, then sent to your Éclair site. It works on slow shared hosting and resumes by itself after a cut. Nothing is changed on this WordPress site.', 'eclair-export' ); ?></p></div></header>
		<form id="ee-export-form"<?php echo $job || $sending ? ' hidden' : ''; ?>>
			<div class="ee-key">
				<label for="ee-key"><?php esc_html_e( 'Link key of your Éclair site', 'eclair-export' ); ?> <span class="ee-pill ee-ok"><?php esc_html_e( 'Recommended', 'eclair-export' ); ?></span></label>
				<input type="text" id="ee-key" name="key" autocomplete="off" spellcheck="false" placeholder="eclair1.…">
				<p class="ee-muted"><?php esc_html_e( 'In Éclair: Administration › Import WordPress › Create a link key, then paste it here. The package goes straight to your new site, whatever its size: nothing to download, nothing to upload. Leave empty to download the package instead.', 'eclair-export' ); ?></p>
			</div>
			<details class="ee-options">
				<summary><?php esc_html_e( 'Options (contents, media, authors)', 'eclair-export' ); ?></summary>
				<fieldset>
					<legend><?php esc_html_e( 'Contents', 'eclair-export' ); ?></legend>
					<div class="ee-chips">
					<?php foreach ( $types as $name => $object ) : ?>
						<label class="ee-chip"><input type="checkbox" name="types[]" value="<?php echo esc_attr( $name ); ?>" checked> <?php echo esc_html( $object->labels->name ); ?></label>
					<?php endforeach; ?>
					</div>
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
					<p class="ee-muted"><?php esc_html_e( 'Only matters if you download the package: small parts go through the upload limits of shared hosting on the Éclair side.', 'eclair-export' ); ?></p>
				</fieldset>
				<fieldset>
					<legend><?php esc_html_e( 'Authors', 'eclair-export' ); ?></legend>
					<label class="ee-check"><input type="checkbox" name="emails" value="1"> <?php esc_html_e( 'Include the e-mail addresses of the authors (to create their Éclair accounts). Passwords are never exported.', 'eclair-export' ); ?></label>
				</fieldset>
			</details>
			<p class="ee-error" id="ee-start-error" hidden></p>
			<p><button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Start the migration', 'eclair-export' ); ?></button></p>
		</form>
		<div id="ee-progress"<?php echo $job || $sending ? '' : ' hidden'; ?>>
			<div class="ee-track" id="ee-track-export">
				<p class="ee-track-title"><?php esc_html_e( 'Preparing the package', 'eclair-export' ); ?></p>
				<div class="ee-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span></span></div>
				<p class="ee-progress-label" aria-live="polite"></p>
			</div>
			<div class="ee-track" id="ee-track-send" hidden>
				<p class="ee-track-title"><?php esc_html_e( 'Sending to Éclair', 'eclair-export' ); ?></p>
				<div class="ee-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span></span></div>
				<p class="ee-progress-label" aria-live="polite"></p>
			</div>
			<p class="ee-error" id="ee-run-error" hidden></p>
			<p class="ee-actions">
				<button type="button" class="button button-primary" id="ee-resume" hidden><?php esc_html_e( 'Resume', 'eclair-export' ); ?></button>
				<button type="button" class="button" id="ee-cancel"><?php esc_html_e( 'Cancel', 'eclair-export' ); ?></button>
			</p>
			<div id="ee-delivered" class="ee-done" hidden>
				<h3><?php esc_html_e( 'Your site has arrived in Éclair', 'eclair-export' ); ?></h3>
				<p id="ee-delivered-label"></p>
				<ol>
					<li><?php esc_html_e( 'Open the administration of your Éclair site, then Import WordPress.', 'eclair-export' ); ?></li>
					<li><?php esc_html_e( 'Click "Simulate": you see what will be created, nothing is written.', 'eclair-export' ); ?></li>
					<li><?php esc_html_e( 'Click "Import", then open the migration report: every old address is checked.', 'eclair-export' ); ?></li>
				</ol>
				<p class="ee-actions"><a class="button button-primary button-hero" id="ee-open-eclair" target="_blank" rel="noopener" href="#"><?php esc_html_e( 'Open my Éclair site', 'eclair-export' ); ?></a> <button type="button" class="button" id="ee-new-2"><?php esc_html_e( 'New export', 'eclair-export' ); ?></button></p>
				<p class="ee-muted"><?php esc_html_e( 'The package was deleted from this server. This WordPress site was not changed: keep it online until the new site is checked.', 'eclair-export' ); ?></p>
			</div>
			<div id="ee-downloads" hidden>
				<h3><?php esc_html_e( 'Your package is ready', 'eclair-export' ); ?></h3>
				<form id="ee-send-form" class="ee-key">
					<label for="ee-key-2"><?php esc_html_e( 'Send it straight to Éclair', 'eclair-export' ); ?> <span class="ee-pill ee-ok"><?php esc_html_e( 'Recommended', 'eclair-export' ); ?></span></label>
					<div class="ee-row"><input type="text" id="ee-key-2" name="key" autocomplete="off" spellcheck="false" placeholder="eclair1.…"> <button type="submit" class="button button-primary"><?php esc_html_e( 'Send to Éclair', 'eclair-export' ); ?></button></div>
					<p class="ee-muted"><?php esc_html_e( 'Paste the link key created in Éclair: Administration › Import WordPress › Create a link key.', 'eclair-export' ); ?></p>
				</form>
				<p class="ee-muted"><?php esc_html_e( 'Or download every part: each one is deleted from this server as soon as it is downloaded. Then import them in Éclair (Import WordPress).', 'eclair-export' ); ?></p>
				<ul></ul>
				<p><button type="button" class="button" id="ee-new"><?php esc_html_e( 'New export', 'eclair-export' ); ?></button></p>
			</div>
		</div>
		<p class="ee-muted ee-cli"><?php esc_html_e( 'Many sites to migrate? The same migration runs from the command line:', 'eclair-export' ); ?> <code>wp eclair export --send=&lt;key&gt;</code></p>
	</section>

	<section class="ee-step ee-next" id="ee-eclair">
		<header><span class="ee-num">3</span><div><h2><?php esc_html_e( 'Import in Éclair', 'eclair-export' ); ?></h2><p><?php esc_html_e( 'What happens on the other side, in three clicks.', 'eclair-export' ); ?></p></div></header>
		<ul class="ee-tiles">
			<li><strong><?php esc_html_e( 'Simulation', 'eclair-export' ); ?></strong><span><?php esc_html_e( 'Éclair shows what it will create and which addresses change, without writing anything.', 'eclair-export' ); ?></span></li>
			<li><strong><?php esc_html_e( 'Import', 'eclair-export' ); ?></strong><span><?php esc_html_e( 'Contents, images, SEO titles and descriptions, menus, redirections. Each address is kept, or redirected in one 301.', 'eclair-export' ); ?></span></li>
			<li><strong><?php esc_html_e( 'Migration report', 'eclair-export' ); ?></strong><span><?php esc_html_e( 'Every old address checked one by one, SEO checklist, speed before and after: a page to hand to your client.', 'eclair-export' ); ?></span></li>
			<li><strong><?php esc_html_e( 'With Claude or ChatGPT', 'eclair-export' ); ?></strong><span><?php esc_html_e( 'Connect your assistant to Éclair (Settings › AI) and ask it to finish the migration: it reads what is left and proposes the fixes.', 'eclair-export' ); ?></span></li>
		</ul>
	</section>
</div>
