<?php
/**
 * Installable ZIP: php tools/build.php → dist/eclair-export-<version>.zip (folder "eclair-export/" inside, as
 * WordPress expects). Tests, tools, CI and development files are left out.
 */

$root = dirname( __DIR__ );
if ( ! preg_match( '~^\s*\*\s*Version:\s*(\S+)~m', file_get_contents( $root . '/eclair-export.php' ), $m ) ) {
	fwrite( STDERR, "Version not found.\n" );
	exit( 1 );
}
$version = $m[1];
if ( false === strpos( file_get_contents( $root . '/readme.txt' ), 'Stable tag: ' . $version ) ) {
	fwrite( STDERR, "readme.txt: Stable tag is not {$version}.\n" );
	exit( 1 );
}
$keep = array( 'eclair-export.php', 'uninstall.php', 'readme.txt', 'README.md', 'README.en.md', 'CHANGELOG.md', 'LICENSE', 'includes', 'admin', 'assets', 'languages', 'docs' );
@mkdir( $root . '/dist' );
$target = $root . "/dist/eclair-export-{$version}.zip";
@unlink( $target );
$zip = new ZipArchive();
if ( true !== $zip->open( $target, ZipArchive::CREATE ) ) {
	fwrite( STDERR, "Cannot create {$target}.\n" );
	exit( 1 );
}
$count = 0;
foreach ( $keep as $entry ) {
	$path  = $root . '/' . $entry;
	$files = is_dir( $path ) ? new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ) ) : array( new SplFileInfo( $path ) );
	foreach ( $files as $file ) {
		if ( $file->isLink() ) {
			fwrite( STDERR, 'Symbolic link refused: ' . $file->getPathname() . "\n" );
			exit( 1 );
		}
		$relative = substr( $file->getPathname(), strlen( $root ) + 1 );
		$zip->addFile( $file->getPathname(), 'eclair-export/' . str_replace( '\\', '/', $relative ) );
		$count++;
	}
}
$zip->close();
echo "{$target}: {$count} files, " . round( filesize( $target ) / 1024 ) . " KB\n";
