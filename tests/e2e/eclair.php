<?php
/**
 * Pilote d'un site Éclair de test par HTTP, comme un administrateur :
 *   php tests/e2e/eclair.php install <url> <dossier de sortie>   installation, connexion, fonction « Import WordPress » ajoutée
 *   php tests/e2e/eclair.php link    <url> <dossier de sortie>   crée une clé de liaison et l'affiche (envoi direct depuis WordPress)
 *   php tests/e2e/eclair.php report  <url> <dossier de sortie>   rapport de migration (fr, es) et page d'import enregistrés
 */

$cmd  = $argv[1] ?? '';
$base = rtrim( $argv[2] ?? '', '/' );
$out  = rtrim( $argv[3] ?? '.', '/' );
$jar  = $out . '/eclair-cookies.txt';
$req  = static function ( string $method, string $path, array $data = array() ) use ( $base, $jar ): array {
	$ch = curl_init( $base . $path );
	curl_setopt_array( $ch, array( CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
		CURLOPT_TIMEOUT => 60, CURLOPT_PROXY => '' ) );
	if ( $data ) {
		curl_setopt( $ch, CURLOPT_POSTFIELDS, http_build_query( $data ) );
	}
	$raw  = (string) curl_exec( $ch );
	$size = (int) curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
	return array( 'status' => (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE ), 'body' => substr( $raw, $size ) );
};
$token = static function ( string $body ): array {
	return preg_match( '/name="(_ft|_csrf)" value="([a-f0-9]+)"/', $body, $m ) ? array( $m[1] => $m[2] ) : array();
};
$fail = static function ( string $msg ) {
	fwrite( STDERR, "✗ $msg\n" );
	exit( 1 );
};

if ( 'install' === $cmd ) {
	$form = $req( 'GET', '/' );
	$req( 'POST', '/', $token( $form['body'] ) + array( 'ui' => 'fr', 'site_name' => 'Mon site', 'site_tagline' => '', 'site_lang' => 'fr', 'site_type' => 'blog',
		'user_name' => 'Thierry Test', 'user_email' => 'admin@example.test', 'user_pass' => 'Un-mot-de-passe-solide-2026', 'index' => '1', 'ai' => 'open', 'admin_path' => 'gestion' ) );
	$login = $req( 'GET', '/gestion/login' );
	$req( 'POST', '/gestion/login', $token( $login['body'] ) + array( 'email' => 'admin@example.test', 'password' => 'Un-mot-de-passe-solide-2026' ) );
	$ext = $req( 'GET', '/gestion/extensions' );
	if ( 200 !== $ext['status'] ) {
		$fail( 'Connexion à l’administration impossible (' . $ext['status'] . ')' );
	}
	$req( 'POST', '/gestion/extensions', $token( $ext['body'] ) + array( 'action' => 'add', 'id' => 'wordpress' ) );
	$page = $req( 'GET', '/gestion/x/wordpress' );
	if ( 200 !== $page['status'] || false === strpos( $page['body'], 'name="parts[]"' ) ) {
		$fail( 'Fonction Import WordPress indisponible' );
	}
	echo "✓ Éclair installé, Import WordPress ajouté\n";
	exit( 0 );
}

if ( 'report' === $cmd ) {
	foreach ( array( 'fr', 'es' ) as $lang ) {
		$r = $req( 'GET', '/gestion/x/wordpress/report?lang=' . $lang );
		if ( 200 !== $r['status'] ) {
			$fail( "Rapport $lang : " . $r['status'] );
		}
		file_put_contents( "$out/rapport-migration-$lang.html", $r['body'] );
	}
	file_put_contents( "$out/eclair-import.html", $req( 'GET', '/gestion/x/wordpress' )['body'] );
	echo "✓ Rapports de migration enregistrés\n";
	exit( 0 );
}

if ( 'link' === $cmd ) {
	// Clé de liaison créée comme le ferait la personne : Import WordPress › Créer une clé de liaison.
	$page = $req( 'GET', '/gestion/x/wordpress' );
	$req( 'POST', '/gestion/x/wordpress', $token( $page['body'] ) + array( 'action' => 'link' ) );
	$page = $req( 'GET', '/gestion/x/wordpress' );
	if ( ! preg_match( '~value="(eclair1\.[A-Za-z0-9_-]+\.[a-f0-9]{40})"~', $page['body'], $m ) ) {
		$fail( 'Clé de liaison introuvable sur la page Import WordPress' );
	}
	echo $m[1];
	exit( 0 );
}

$fail( 'Usage : php tests/e2e/eclair.php install|link|report <url> <dossier>' );
