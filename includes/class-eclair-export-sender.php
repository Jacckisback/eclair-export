<?php
/**
 * Direct transfer: the finished package is sent to the Éclair site by this server, in pieces, instead of being
 * downloaded then uploaded by hand.
 *
 * The Éclair site gives a link key (Import WordPress › Create a link key). The key carries the address of the site and a
 * one-time code; it only allows archives to be dropped there, and stops working once the transfer is finished. The
 * transfer runs in short steps like the export, and resumes where it stopped: the Éclair site says what it already has.
 *
 * @package EclairExport
 */

defined( 'ABSPATH' ) || exit;

final class Eclair_Export_Sender {

	const OPTION = 'eclair_export_send';
	/** Largest piece sent in one request (the Éclair site may ask for less). */
	const CHUNK = 4194304;
	/** A waiting key is pinged this often so that it does not expire during a long export. */
	const PING = 1200;

	public static function state() {
		$state = get_option( self::OPTION );
		return is_array( $state ) && isset( $state['url'], $state['code'] ) ? $state : null;
	}

	private static function save( array $state ) {
		update_option( self::OPTION, $state, false );
		return $state;
	}

	public static function cancel() {
		delete_option( self::OPTION );
	}

	/**
	 * Reads a link key ("eclair1.<address>.<code>"): array( url, code ), or null if it is not one.
	 * The address must be https (http only for a local test site, through the eclair_export_allow_http filter).
	 */
	public static function parse( $key ) {
		$key = preg_replace( '~\s+~', '', (string) $key );
		if ( ! preg_match( '~^eclair1\.([A-Za-z0-9_-]{8,400})\.([a-f0-9]{40})$~', $key, $m ) ) {
			return null;
		}
		$url = base64_decode( strtr( $m[1], '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		$url = is_string( $url ) ? untrailingslashit( esc_url_raw( $url, array( 'http', 'https' ) ) ) : '';
		if ( '' === $url || ! preg_match( '~^https?://[^/\s?#]+(/[^\s?#]*)?$~i', $url ) ) {
			return null;
		}
		if ( 0 !== stripos( $url, 'https://' ) && ! apply_filters( 'eclair_export_allow_http', false, $url ) ) {
			return null;
		}
		return array( $url, $m[2] );
	}

	/**
	 * Checks the key with the Éclair site and keeps it for the transfer. Throws a RuntimeException with a message
	 * for the person when the key is not valid or the site does not answer.
	 */
	public static function connect( $key ) {
		$parsed = self::parse( $key );
		if ( ! $parsed ) {
			throw new RuntimeException( __( 'This is not a link key. Copy it again from Éclair: Import WordPress › Create a link key.', 'eclair-export' ) );
		}
		$state = array(
			'url'     => $parsed[0],
			'code'    => $parsed[1],
			'site'    => '',
			'chunk'   => self::CHUNK,
			'status'  => 'waiting',
			'error'   => '',
			'parts'   => array(),
			'pinged'  => time(),
			'created' => time(),
		);
		$hello = self::request( $state, array( 'op' => 'hello' ) );
		if ( ! empty( $hello['busy'] ) ) {
			throw new RuntimeException( __( 'An import is already running on the Éclair site: finish it or cancel it there first.', 'eclair-export' ) );
		}
		$state['site']  = isset( $hello['site'] ) ? sanitize_text_field( (string) $hello['site'] ) : '';
		$state['chunk'] = max( 65536, min( self::chunk_limit(), isset( $hello['chunk'] ) ? (int) $hello['chunk'] : self::CHUNK ) );
		return self::save( $state );
	}

	/** Pieces are held in memory: smaller on a host with little of it. */
	private static function chunk_limit() {
		$limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		return $limit > 0 && $limit < 134217728 ? 1048576 : self::CHUNK;
	}

	/** During a long export, the waiting key is kept alive. Never throws. */
	public static function keepalive() {
		$state = self::state();
		if ( ! $state || 'waiting' !== $state['status'] || time() - (int) $state['pinged'] < self::PING ) {
			return;
		}
		$state['pinged'] = time();
		self::save( $state );
		try {
			self::request( $state, array( 'op' => 'hello' ) );
		} catch ( Exception $e ) {
			unset( $e ); // Said when the transfer starts.
		}
	}

	/**
	 * Sends pieces for $budget seconds. Returns the state. When the last archive has arrived, the Éclair site is told,
	 * the key stops working and the export is deleted from this server.
	 */
	public static function step( $budget = 0 ) {
		$state = self::state();
		$job   = Eclair_Export_Exporter::job();
		if ( ! $state || 'done' === $state['status'] ) {
			return $state;
		}
		if ( ! $job || 'done' !== $job['status'] ) {
			return $state;
		}
		$until = microtime( true ) + ( $budget > 0 ? $budget : Eclair_Export_Util::budget() );
		$dir   = Eclair_Export_Storage::job_dir( $job['id'] );
		try {
			if ( ! $state['parts'] || 'error' === $state['status'] ) {
				// First step, or resuming after an error: the Éclair site says what it already holds.
				$hello    = self::request( $state, array( 'op' => 'hello' ) );
				$received = isset( $hello['received'] ) && is_array( $hello['received'] ) ? $hello['received'] : array();
				$parts    = array();
				foreach ( $job['parts'] as $part ) {
					$file = $dir . '/' . $part['file'];
					if ( ! is_file( $file ) ) {
						throw new RuntimeException( __( 'A part of the package was already downloaded and deleted: start a new export to send it.', 'eclair-export' ) );
					}
					$size = (int) filesize( $file );
					$got  = isset( $received[ $part['file'] ] ) && is_array( $received[ $part['file'] ] ) ? $received[ $part['file'] ] : array();
					$parts[] = array(
						'file' => $part['file'],
						'size' => $size,
						'sent' => ! empty( $got['complete'] ) ? $size : min( $size, isset( $got['bytes'] ) ? (int) $got['bytes'] : 0 ),
						'done' => ! empty( $got['complete'] ),
					);
				}
				$state['parts'] = $parts;
			}
			$state['status'] = 'running';
			$state['error']  = '';
			foreach ( $state['parts'] as $i => $part ) {
				if ( $part['done'] ) {
					continue;
				}
				$file = $dir . '/' . $part['file'];
				$h    = fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				if ( ! $h ) {
					throw new RuntimeException( __( 'A part of the package can no longer be read: start a new export.', 'eclair-export' ) );
				}
				while ( $part['sent'] < $part['size'] ) {
					if ( microtime( true ) >= $until ) {
						fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions
						$state['parts'][ $i ] = $part;
						return self::save( $state );
					}
					fseek( $h, $part['sent'] );
					$body = (string) fread( $h, $state['chunk'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
					$r    = self::request( $state, array( 'op' => 'chunk', 'name' => $part['file'], 'offset' => $part['sent'] ), $body, true );
					if ( isset( $r['error'] ) && 'offset' === $r['error'] && isset( $r['size'] ) ) {
						$part['sent'] = max( 0, min( $part['size'], (int) $r['size'] ) ); // A piece arrived but its answer was lost.
					} elseif ( ! empty( $r['complete'] ) ) {
						$part['sent'] = $part['size'];
					} else {
						$part['sent'] += strlen( $body );
					}
					$state['parts'][ $i ] = $part;
					self::save( $state );
				}
				fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				if ( function_exists( 'set_time_limit' ) ) {
					@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				}
				self::request( $state, array( 'op' => 'finish', 'name' => $part['file'], 'sha256' => hash_file( 'sha256', $file ) ) );
				$part['done']         = true;
				$state['parts'][ $i ] = $part;
				self::save( $state );
			}
			self::request( $state, array( 'op' => 'done' ) );
			$state['status'] = 'done';
			$state['code']   = ''; // Used: it no longer opens anything.
			self::save( $state );
			Eclair_Export_Exporter::cancel(); // The package has left this server.
		} catch ( Exception $e ) {
			$state['status'] = 'error';
			$state['error']  = $e->getMessage();
			if ( 422 === $e->getCode() ) {
				$state['parts'] = array(); // The damaged archive was deleted over there: it is sent again.
			}
			self::save( $state );
		}
		return $state;
	}

	/**
	 * One request to the Éclair site. Returns its JSON answer; throws a RuntimeException with a message for the person.
	 * With $soft, a refused piece whose answer says where to resume is returned instead of thrown.
	 */
	private static function request( array $state, array $query, $body = '', $soft = false ) {
		$host     = (string) wp_parse_url( $state['url'], PHP_URL_HOST );
		$response = wp_safe_remote_post(
			$state['url'] . '/_eclair/import?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ),
			array(
				'timeout'     => 90,
				'redirection' => 0,
				'headers'     => array(
					'X-Eclair-Code' => $state['code'],
					'Content-Type'  => 'application/octet-stream',
					'Accept'        => 'application/json',
				),
				'body'        => '' === $body ? '-' : $body,
				'user-agent'  => 'EclairExport/' . ECLAIR_EXPORT_VERSION,
			)
		);
		if ( is_wp_error( $response ) ) {
			/* translators: 1: host name, 2: technical error */
			throw new RuntimeException( sprintf( __( 'Could not reach %1$s (%2$s). Check that the site is online, then resume.', 'eclair-export' ), $host, $response->get_error_message() ) );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$json   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( is_array( $json ) && ! empty( $json['ok'] ) ) {
			return $json;
		}
		$error = is_array( $json ) && isset( $json['error'] ) ? (string) $json['error'] : '';
		if ( $soft && 'offset' === $error ) {
			return $json;
		}
		$messages = array(
			'code'    => __( 'The Éclair site refused the key: it has expired or was replaced. Create a new one in Éclair (Import WordPress), then paste it here.', 'eclair-export' ),
			'blocked' => __( 'Too many refused attempts: the Éclair site blocks this server for a few minutes. Wait, then resume with a new key.', 'eclair-export' ),
			'busy'    => __( 'An import is already running on the Éclair site: finish it or cancel it there first.', 'eclair-export' ),
			'space'   => __( 'The Éclair site has no room left on its disk for this package. Free some space there, or export without the media.', 'eclair-export' ),
			'sha256'  => __( 'An archive arrived damaged and was refused: it will be sent again when you resume.', 'eclair-export' ),
			'https'   => __( 'The Éclair site only accepts https: check its address in its settings, then create a new key.', 'eclair-export' ),
		);
		if ( isset( $messages[ $error ] ) ) {
			throw new RuntimeException( $messages[ $error ], 'sha256' === $error ? 422 : 0 );
		}
		if ( '' === $error ) {
			/* translators: %s: host name */
			throw new RuntimeException( sprintf( __( '%s does not accept a transfer: no link key is active there (it lasts two hours and serves once), or the site runs a version of Éclair without direct transfer. Create a new key in Éclair: Import WordPress.', 'eclair-export' ), $host ) );
		}
		/* translators: 1: host name, 2: error code, 3: HTTP status */
		throw new RuntimeException( sprintf( __( '%1$s refused the transfer (%2$s, %3$d).', 'eclair-export' ), $host, $error, $status ) );
	}

	/** Progress for the screen and the command line. Never contains the code. */
	public static function progress( $state ) {
		if ( ! $state ) {
			return array( 'status' => 'none', 'percent' => 0 );
		}
		$total = 0;
		$sent  = 0;
		foreach ( $state['parts'] as $part ) {
			$total += $part['size'];
			$sent  += $part['sent'];
		}
		$host = (string) wp_parse_url( $state['url'], PHP_URL_HOST );
		$name = '' !== $state['site'] ? $state['site'] . ' (' . $host . ')' : $host;
		if ( 'done' === $state['status'] ) {
			/* translators: %s: name and host of the Éclair site */
			$label = sprintf( __( 'Package delivered to %s.', 'eclair-export' ), $name );
		} elseif ( 'waiting' === $state['status'] ) {
			/* translators: %s: name and host of the Éclair site */
			$label = sprintf( __( 'Connected to %s: the package will be sent as soon as it is ready.', 'eclair-export' ), $name );
		} else {
			/* translators: 1: size sent, 2: total size, 3: name and host of the Éclair site */
			$label = sprintf( __( 'Sending to %3$s: %1$s of %2$s', 'eclair-export' ), Eclair_Export_Util::size( $sent ), Eclair_Export_Util::size( $total ), $name );
		}
		return array(
			'status'  => $state['status'],
			'percent' => 'done' === $state['status'] ? 100 : ( $total > 0 ? (int) floor( 100 * $sent / $total ) : 0 ),
			'label'   => $label,
			'error'   => (string) $state['error'],
			'site'    => $name,
			'url'     => $state['url'],
		);
	}
}
