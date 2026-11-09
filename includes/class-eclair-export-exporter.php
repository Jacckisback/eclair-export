<?php
/**
 * The export, in short steps that can be resumed: each call works for a few seconds, writes what it has done, and the
 * next call starts again from there. A step cut in the middle (timeout, closed tab, lost connection) leaves nothing half
 * written: the files it touched are cut back to their last saved size, and the archive is only rewritten once complete.
 *
 * Stages: prepare (settings, authors, terms, menus) → content (by batches, per post type) → media (by batches, added to
 * the archive in parts) → redirects → finalize (JSON files, report, manifest with checksums).
 *
 * @package EclairExport
 */

defined( 'ABSPATH' ) || exit;

final class Eclair_Export_Exporter {

	const OPTION        = 'eclair_export_job';
	const LOCK          = 'eclair_export_lock';
	const STAGES        = array( 'prepare', 'content', 'media', 'redirects', 'finalize', 'done' );
	const BATCH_CONTENT = 25;
	const BATCH_MEDIA   = 50;
	/** Bytes of media added to the archive by one step, so that rewriting it stays within the time of a request. */
	const STEP_BYTES = 48 * 1048576;
	/** Part sizes offered (MB, 0 = a single archive). */
	const PARTS = array( 50, 100, 250, 500, 1000, 0 );

	public static function job() {
		$job = get_option( self::OPTION );
		return is_array( $job ) && isset( $job['id'] ) ? $job : null;
	}

	private static function save( array $job ) {
		$job['updated'] = time();
		update_option( self::OPTION, $job, false );
		return $job;
	}

	/** Options as sent by the screen or the command line, checked. */
	public static function options( array $raw ) {
		$types = Eclair_Export_Util::content_types();
		$want  = isset( $raw['types'] ) ? array_map( 'sanitize_key', (array) $raw['types'] ) : array_keys( $types );
		$want  = array_values( array_intersect( $want, array_keys( $types ) ) );
		$part  = isset( $raw['part_mb'] ) ? (int) $raw['part_mb'] : 100;
		return array(
			'media'   => isset( $raw['media'] ) && 'remote' === $raw['media'] ? 'remote' : 'include',
			// The screen offers PARTS; the command line accepts any size up to 2 GB.
			'part_mb' => $part >= 0 && $part <= 2048 ? $part : 100,
			'types'   => $want ? $want : array_keys( $types ),
			'emails'  => ! empty( $raw['emails'] ),
		);
	}

	/** New export (an unfinished one is cancelled first). */
	public static function start( array $options ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			throw new RuntimeException( __( 'The PHP "zip" extension is missing on this server: ask your host to enable it.', 'eclair-export' ) );
		}
		self::cancel();
		$id  = bin2hex( random_bytes( 16 ) );
		$job = array(
			'id'        => $id,
			'version'   => ECLAIR_EXPORT_VERSION,
			'created'   => time(),
			'updated'   => time(),
			'status'    => 'running',
			'error'     => '',
			'failures'  => 0,
			'options'   => self::options( $options ),
			'stage'     => 'prepare',
			'cursor'    => array( 'type' => 0, 'last' => 0, 'media' => 0 ),
			'totals'    => array( 'content' => 0, 'media' => 0 ),
			'done'      => array( 'content' => 0, 'media' => 0 ),
			'archive'   => Eclair_Export_Storage::archive_name(),
			'parts'     => array(),
			'offsets'   => array(),
			'counts'    => array(),
			'report'    => array( 'shortcodes' => array(), 'converted' => array(), 'widgets' => array(), 'dropped' => array(), 'formats' => array(), 'protected' => 0, 'media_missing' => array(), 'media_missing_count' => 0, 'redirects_skipped' => array(), 'errors' => array() ),
			'downloaded'=> array(),
		);
		Eclair_Export_Storage::job_dir( $id );
		return self::save( $job );
	}

	/** Stops the export and deletes its files. */
	public static function cancel() {
		$job = self::job();
		if ( $job ) {
			Eclair_Export_Storage::delete_tree( Eclair_Export_Storage::job_dir( $job['id'] ) );
		}
		delete_option( self::OPTION );
		delete_option( self::LOCK );
	}

	/** Works for about $budget seconds. Returns the job as saved. */
	public static function step( $budget = 0 ) {
		$job = self::job();
		if ( ! $job || 'running' !== $job['status'] ) {
			return $job;
		}
		if ( ! self::lock() ) {
			return $job;
		}
		$until = microtime( true ) + ( $budget > 0 ? $budget : Eclair_Export_Util::budget() );
		$dir   = Eclair_Export_Storage::job_dir( $job['id'] );
		try {
			if ( function_exists( 'wp_raise_memory_limit' ) ) {
				wp_raise_memory_limit( 'admin' );
			}
			Eclair_Export_Multilang::all_languages();
			self::rewind( $job, $dir );
			do {
				$stage = $job['stage'];
				switch ( $stage ) {
					case 'prepare':
						$job = self::prepare( $job, $dir );
						break;
					case 'content':
						$job = self::content( $job, $dir, $until );
						break;
					case 'media':
						$job = self::media( $job, $dir, $until );
						break;
					case 'redirects':
						$job = self::redirects( $job, $dir );
						break;
					case 'finalize':
						$job = self::finalize( $job, $dir );
						break;
				}
				self::commit( $job, $dir );
				$job['failures'] = 0;
				$job             = self::save( $job );
			} while ( 'done' !== $job['stage'] && microtime( true ) < $until );
		} catch ( Throwable $e ) {
			$job             = self::job();
			$job['failures'] = (int) $job['failures'] + 1;
			$job['error']    = $e->getMessage();
			if ( $job['failures'] >= 3 ) {
				$job['status'] = 'error';
			}
			$job = self::save( $job );
		} finally {
			delete_option( self::LOCK );
		}
		return $job;
	}

	/** One step at a time: a second request arriving while the first works waits for the next turn. */
	private static function lock() {
		if ( add_option( self::LOCK, time(), '', 'no' ) ) {
			return true;
		}
		$since = (int) get_option( self::LOCK );
		if ( $since > 0 && $since < time() - 120 ) {
			delete_option( self::LOCK );
			return add_option( self::LOCK, time(), '', 'no' );
		}
		return false;
	}

	/* ---------------------------------------------------------------- Files written line by line */

	/** Files written line by line during the export (relative to the work folder). */
	private static function appendables( array $job ) {
		$files = array( 'seo-posts.jsonl', 'urls.csv', 'media/index.jsonl' );
		foreach ( $job['options']['types'] as $type ) {
			$files[] = substr( Eclair_Export_Util::content_file( $type ), 0, -5 ) . '.jsonl';
		}
		return $files;
	}

	/** Cuts every line file back to the size saved by the last complete step (a cut step leaves no duplicate). */
	private static function rewind( array $job, $dir ) {
		foreach ( self::appendables( $job ) as $rel ) {
			$file = $dir . '/work/' . $rel;
			$keep = isset( $job['offsets'][ $rel ] ) ? (int) $job['offsets'][ $rel ] : 0;
			clearstatcache( true, $file );
			if ( is_file( $file ) && filesize( $file ) > $keep ) {
				$h = fopen( $file, 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				if ( $h ) {
					ftruncate( $h, $keep );
					fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				}
			}
		}
	}

	private static function commit( array &$job, $dir ) {
		foreach ( self::appendables( $job ) as $rel ) {
			$file = $dir . '/work/' . $rel;
			clearstatcache( true, $file );
			$job['offsets'][ $rel ] = is_file( $file ) ? (int) filesize( $file ) : 0;
		}
	}

	private static function append( $dir, $rel, $line ) {
		$file = $dir . '/work/' . $rel;
		if ( ! is_dir( dirname( $file ) ) ) {
			wp_mkdir_p( dirname( $file ) );
		}
		if ( false === file_put_contents( $file, $line . "\n", FILE_APPEND | LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			throw new RuntimeException( __( 'Cannot write the export files: the disk may be full.', 'eclair-export' ) );
		}
	}

	private static function write( $dir, $rel, $content ) {
		$file = $dir . '/work/' . $rel;
		if ( ! is_dir( dirname( $file ) ) ) {
			wp_mkdir_p( dirname( $file ) );
		}
		if ( false === file_put_contents( $file, $content ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			throw new RuntimeException( __( 'Cannot write the export files: the disk may be full.', 'eclair-export' ) );
		}
	}

	/* ---------------------------------------------------------------- Stages */

	private static function prepare( array $job, $dir ) {
		global $wpdb;
		$types    = $job['options']['types'];
		$statuses = Eclair_Export_Util::statuses();
		$in_types = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$in_stat  = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$job['totals']['content'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ($in_types) AND post_status IN ($in_stat)", array_merge( $types, $statuses ) ) ); // phpcs:ignore WordPress.DB
		$job['totals']['media']   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'" ); // phpcs:ignore WordPress.DB
		$media_bytes = Eclair_Export_Diagnostic::media_bytes( 5 );
		if ( 'include' === $job['options']['media'] && function_exists( 'disk_free_space' ) ) {
			$free = @disk_free_space( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( false !== $free && $free < $media_bytes['bytes'] * 1.2 + 50 * 1048576 ) {
				/* translators: 1: space needed, 2: free space */
				throw new RuntimeException( sprintf( __( 'Not enough disk space for the archive (%1$s needed, %2$s free). Choose "media downloaded from the old site" or free some space.', 'eclair-export' ), Eclair_Export_Util::size( $media_bytes['bytes'] * 1.2 ), Eclair_Export_Util::size( $free ) ) );
			}
		}
		list( $terms, $term_seo ) = Eclair_Export_Collector::terms( $types );
		$authors                  = Eclair_Export_Collector::authors( $types, $job['options']['emails'] );
		$menus                    = Eclair_Export_Collector::menus();
		self::write( $dir, 'content/settings.json', Eclair_Export_Util::json( Eclair_Export_Collector::settings(), true ) );
		self::write( $dir, 'content/taxonomies.json', Eclair_Export_Util::json( $terms ) );
		self::write( $dir, 'content/authors.json', Eclair_Export_Util::json( $authors, true ) );
		self::write( $dir, 'content/menus.json', Eclair_Export_Util::json( $menus, true ) );
		self::write( $dir, 'seo-terms.json', Eclair_Export_Util::json( (object) $term_seo ) );
		$job['counts']['terms']   = count( $terms );
		$job['counts']['authors'] = count( $authors );
		$job['counts']['menus']   = count( $menus );
		self::append( $dir, 'urls.csv', 'url,kind,ref' );
		$job['stage'] = 'content';
		return $job;
	}

	private static function content( array $job, $dir, $until ) {
		global $wpdb;
		$types    = $job['options']['types'];
		$statuses = Eclair_Export_Util::statuses();
		$in_stat  = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		while ( microtime( true ) < $until ) {
			if ( $job['cursor']['type'] >= count( $types ) ) {
				$job['stage'] = 'media';
				return $job;
			}
			$type = $types[ $job['cursor']['type'] ];
			$ids  = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ($in_stat) AND ID > %d ORDER BY ID ASC LIMIT %d", array_merge( array( $type ), $statuses, array( (int) $job['cursor']['last'], self::BATCH_CONTENT ) ) ) ); // phpcs:ignore WordPress.DB
			if ( ! $ids ) {
				$job['cursor']['type']++;
				$job['cursor']['last'] = 0;
				continue;
			}
			$file = substr( Eclair_Export_Util::content_file( $type ), 0, -5 ) . '.jsonl';
			foreach ( $ids as $id ) {
				$job['cursor']['last'] = (int) $id;
				$job['done']['content']++;
				$post = get_post( (int) $id );
				if ( ! $post instanceof WP_Post ) {
					continue;
				}
				try {
					list( $record, $seo, $report ) = Eclair_Export_Collector::item( $post );
				} catch ( Throwable $e ) {
					self::error( $job, sprintf( '#%d %s: %s', $post->ID, $post->post_title, $e->getMessage() ) );
					continue;
				}
				self::append( $dir, $file, Eclair_Export_Util::json( $record ) );
				self::append( $dir, 'seo-posts.jsonl', Eclair_Export_Util::json( array( 'id' => (int) $post->ID, 'seo' => $seo ) ) );
				// La page des articles de WordPress est listée à part (« blog ») : Éclair la remplace par son blog.
				if ( 'publish' === $post->post_status && '' === $post->post_password && (int) $post->ID !== (int) get_option( 'page_for_posts' ) ) {
					self::append( $dir, 'urls.csv', self::csv( array( $record['url'], 'content', $type . ':' . $post->ID ) ) );
				}
				self::merge( $job, $report, $record );
				$job['counts'][ $type ] = ( isset( $job['counts'][ $type ] ) ? $job['counts'][ $type ] : 0 ) + 1;
				if ( $record['protected'] ) {
					$job['report']['protected']++;
				}
				if ( microtime( true ) >= $until ) {
					break 2;
				}
			}
			self::free_memory();
		}
		return $job;
	}

	/** Report of one content added to the job report (counts, and up to 10 example contents per item). */
	private static function merge( array &$job, array $report, array $record ) {
		$ref = array( 'id' => $record['wp_id'], 'title' => mb_substr( $record['title'], 0, 120 ), 'url' => $record['url'] );
		foreach ( array( 'shortcodes', 'widgets' ) as $kind ) {
			foreach ( $report[ $kind ] as $tag => $n ) {
				if ( ! isset( $job['report'][ $kind ][ $tag ] ) ) {
					$job['report'][ $kind ][ $tag ] = array( 'count' => 0, 'contents' => 0, 'examples' => array() );
				}
				$job['report'][ $kind ][ $tag ]['count']    += $n;
				$job['report'][ $kind ][ $tag ]['contents'] += 1;
				if ( count( $job['report'][ $kind ][ $tag ]['examples'] ) < 10 ) {
					$job['report'][ $kind ][ $tag ]['examples'][] = $ref;
				}
			}
		}
		foreach ( array( 'converted', 'dropped' ) as $kind ) {
			foreach ( $report[ $kind ] as $tag => $n ) {
				Eclair_Export_Converter::bump( $job['report'][ $kind ], $tag, $n );
			}
		}
		Eclair_Export_Converter::bump( $job['report']['formats'], $record['source_format'] );
	}

	private static function error( array &$job, $message ) {
		$job['report']['errors'][] = mb_substr( (string) $message, 0, 300 );
		$job['report']['errors']   = array_slice( $job['report']['errors'], -50 );
	}

	private static function media( array $job, $dir, $until ) {
		global $wpdb;
		$include = 'include' === $job['options']['media'];
		$limit   = (int) $job['options']['part_mb'] * 1048576;
		$zip     = null;
		$parts   = $job['parts'];
		$added   = 0;
		$stored  = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'application/zip', 'application/pdf' );
		$done    = false;
		$pending = array();
		try {
		while ( microtime( true ) < $until && $added < self::STEP_BYTES && ! $done ) {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND ID > %d ORDER BY ID ASC LIMIT %d", (int) $job['cursor']['media'], self::BATCH_MEDIA ) ); // phpcs:ignore WordPress.DB
			if ( ! $ids ) {
				$done = true;
				break;
			}
			foreach ( $ids as $id ) {
				$attachment = get_post( (int) $id );
				$job['cursor']['media'] = (int) $id;
				$job['done']['media']++;
				if ( ! $attachment instanceof WP_Post ) {
					continue;
				}
				list( $record, $path ) = Eclair_Export_Collector::media( $attachment );
				if ( $record['missing'] ) {
					$job['report']['media_missing_count']++;
					if ( count( $job['report']['media_missing'] ) < 20 ) {
						$job['report']['media_missing'][] = $record['url'];
					}
				}
				if ( $include && '' !== $path ) {
					$size = (int) filesize( $path );
					$last = count( $parts ) - 1;
					if ( $last < 0 || ( $limit > 0 && $parts[ $last ]['bytes'] > 0 && $parts[ $last ]['bytes'] + $size > $limit ) ) {
						if ( $zip ) {
							self::close( $zip );
						}
						$parts[] = array( 'file' => $job['archive'] . ( $last < 0 ? '' : '-part' . ( $last + 2 ) ) . '.zip', 'bytes' => 0, 'files' => 0 );
						$last    = count( $parts ) - 1;
						$zip     = null;
					}
					if ( ! $zip ) {
						$zip = self::open( $dir . '/' . $parts[ $last ]['file'] );
						if ( $last > 0 && 0 === $parts[ $last ]['files'] ) {
							$zip->addFromString( 'part.json', Eclair_Export_Util::json( array( 'format' => 'eclair-export', 'format_version' => ECLAIR_EXPORT_FORMAT, 'package' => $job['archive'], 'part' => $last + 1 ), true ) );
						}
					}
					if ( ! $zip->addFile( $path, $record['file'] ) ) {
						throw new RuntimeException( 'ZIP: ' . $record['file'] );
					}
					if ( in_array( $record['mime'], $stored, true ) || 0 === strpos( $record['mime'], 'video/' ) || 0 === strpos( $record['mime'], 'audio/' ) ) {
						$zip->setCompressionName( $record['file'], ZipArchive::CM_STORE );
					}
					$record['sha256']   = (string) hash_file( 'sha256', $path );
					$record['included'] = true;
					$parts[ $last ]['bytes'] += $size;
					$parts[ $last ]['files']++;
					$added += $size;
				}
				$pending[] = Eclair_Export_Util::json( $record );
				if ( microtime( true ) >= $until || $added >= self::STEP_BYTES ) {
					break 2;
				}
			}
		}
		} catch ( Throwable $e ) {
			// Nothing of this step is kept: the archive stays as the last complete step left it.
			if ( $zip ) {
				$zip->unchangeAll();
				$zip->close();
			}
			throw $e;
		}
		// The archive is written first; the index lines only once it is safely closed.
		if ( $zip ) {
			self::close( $zip );
		}
		foreach ( $pending as $line ) {
			self::append( $dir, 'media/index.jsonl', $line );
		}
		$job['parts'] = $parts;
		if ( $done ) {
			$job['stage'] = 'redirects';
		}
		return $job;
	}

	private static function open( $file ) {
		$zip = new ZipArchive();
		$res = $zip->open( $file, ZipArchive::CREATE );
		if ( true !== $res ) {
			/* translators: %s: error code */
			throw new RuntimeException( sprintf( __( 'Cannot create the archive (ZIP error %s).', 'eclair-export' ), (string) $res ) );
		}
		return $zip;
	}

	private static function close( ZipArchive $zip ) {
		if ( ! $zip->close() ) {
			throw new RuntimeException( __( 'Cannot write the archive: the disk may be full.', 'eclair-export' ) );
		}
	}

	private static function redirects( array $job, $dir ) {
		$existing             = Eclair_Export_Redirects::existing();
		list( $migration, $urls ) = Eclair_Export_Redirects::migration( $job['options']['types'] );
		$seen                 = array();
		$lines                = array( implode( ',', Eclair_Export_Redirects::HEADER ) );
		foreach ( array_merge( $existing['rows'], $migration ) as $row ) {
			$key = strtolower( untrailingslashit( (string) $row[0] ) ) . '|' . (int) $row[4];
			if ( '' === (string) $row[0] || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$lines[]      = self::csv( $row );
		}
		self::write( $dir, 'redirects.csv', implode( "\n", $lines ) . "\n" );
		foreach ( $urls as $url ) {
			self::append( $dir, 'urls.csv', self::csv( $url ) );
		}
		$job['counts']['redirects']          = count( $lines ) - 1;
		$job['counts']['redirects_existing'] = count( $existing['rows'] );
		$job['report']['redirects_skipped']  = array_slice( $existing['skipped'], 0, 200 );
		$job['stage']                        = 'finalize';
		return $job;
	}

	private static function finalize( array $job, $dir ) {
		$work  = $dir . '/work';
		$files = array();
		foreach ( $job['options']['types'] as $type ) {
			$target = Eclair_Export_Util::content_file( $type );
			self::lines_to_array( $work . '/' . substr( $target, 0, -5 ) . '.jsonl', $work . '/' . $target );
			$files[] = $target;
		}
		foreach ( array( 'content/taxonomies.json', 'content/authors.json', 'content/menus.json', 'content/settings.json' ) as $rel ) {
			$files[] = $rel;
		}
		self::lines_to_array( $work . '/media/index.jsonl', $work . '/media/index.json' );
		$files[] = 'media/index.json';
		self::seo( $work );
		$files[] = 'seo.json';
		$files[] = 'redirects.csv';
		$files[] = 'urls.csv';
		$media_bytes = 0;
		foreach ( $job['parts'] as $part ) {
			$media_bytes += $part['bytes'];
		}
		$job['counts']['media']       = (int) $job['done']['media'];
		$job['counts']['media_bytes'] = $media_bytes;
		self::write( $dir, 'report.json', Eclair_Export_Util::json( $job['report'], true ) );
		$files[] = 'report.json';
		$checksums = array();
		foreach ( $files as $rel ) {
			$checksums[ $rel ] = array( 'bytes' => (int) filesize( $work . '/' . $rel ), 'sha256' => (string) hash_file( 'sha256', $work . '/' . $rel ) );
		}
		if ( ! $job['parts'] ) {
			$job['parts'][] = array( 'file' => $job['archive'] . '.zip', 'bytes' => 0, 'files' => 0 );
		}
		self::write( $dir, 'manifest.json', Eclair_Export_Util::json( self::manifest( $job, $checksums ), true ) );
		$files[] = 'manifest.json';
		$zip = self::open( $dir . '/' . $job['parts'][0]['file'] );
		foreach ( $files as $rel ) {
			$zip->addFile( $work . '/' . $rel, $rel );
		}
		self::close( $zip );
		foreach ( $job['parts'] as $i => $part ) {
			$job['parts'][ $i ]['size'] = (int) filesize( $dir . '/' . $part['file'] );
		}
		Eclair_Export_Storage::delete_tree( $work );
		$job['offsets'] = array();
		$job['stage']   = 'done';
		$job['status']  = 'done';
		$job['error']   = '';
		return $job;
	}

	/** A file of JSON lines → a JSON array, without loading it all in memory. */
	private static function lines_to_array( $source, $target ) {
		$out = fopen( $target, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $out ) {
			throw new RuntimeException( __( 'Cannot write the export files: the disk may be full.', 'eclair-export' ) );
		}
		fwrite( $out, '[' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$first = true;
		if ( is_file( $source ) ) {
			$in = fopen( $source, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			while ( false !== ( $line = fgets( $in ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
				$line = rtrim( $line, "\n" );
				if ( '' === $line ) {
					continue;
				}
				fwrite( $out, ( $first ? "\n" : ",\n" ) . $line ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				$first = false;
			}
			fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		fwrite( $out, "\n]\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/** seo.json: {"global": …, "posts": {wp_id: …}, "terms": {"taxonomy:id": …}}. */
	private static function seo( $work ) {
		$out = fopen( $work . '/seo.json', 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fwrite( $out, '{"global":' . Eclair_Export_Util::json( Eclair_Export_Seo::globals() ) . ",\n\"posts\":{" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$first = true;
		if ( is_file( $work . '/seo-posts.jsonl' ) ) {
			$in = fopen( $work . '/seo-posts.jsonl', 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			while ( false !== ( $line = fgets( $in ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
				$row = json_decode( $line, true );
				if ( is_array( $row ) ) {
					fwrite( $out, ( $first ? "\n" : ",\n" ) . '"' . (int) $row['id'] . '":' . Eclair_Export_Util::json( $row['seo'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
					$first = false;
				}
			}
			fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		$terms = is_file( $work . '/seo-terms.json' ) ? (string) file_get_contents( $work . '/seo-terms.json' ) : '{}'; // phpcs:ignore WordPress.WP.AlternativeFunctions
		fwrite( $out, "\n},\n\"terms\":" . ( '' !== $terms ? $terms : '{}' ) . "}\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	private static function manifest( array $job, array $checksums ) {
		global $wp_version;
		$detected = Eclair_Export_Detector::detected();
		$counts   = $job['counts'];
		$content  = array();
		foreach ( $job['options']['types'] as $type ) {
			$content[ $type ] = isset( $counts[ $type ] ) ? (int) $counts[ $type ] : 0;
		}
		$unconverted = 0;
		foreach ( $job['report']['shortcodes'] as $row ) {
			$unconverted += $row['count'];
		}
		return array(
			'format'         => 'eclair-export',
			'format_version' => ECLAIR_EXPORT_FORMAT,
			'generator'      => array( 'name' => 'Eclair Export', 'version' => ECLAIR_EXPORT_VERSION ),
			'package'        => $job['archive'],
			'created_at'     => gmdate( 'c' ),
			'source'         => array(
				'url'                 => Eclair_Export_Util::home(),
				'site_url'            => untrailingslashit( site_url() ),
				'name'                => html_entity_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ),
				'language'            => get_locale(),
				'timezone'            => wp_timezone_string(),
				'permalink_structure' => (string) get_option( 'permalink_structure' ),
				'wordpress'           => (string) $wp_version,
				'php'                 => PHP_VERSION,
				'multisite'           => is_multisite(),
				'theme'               => Eclair_Export_Detector::theme(),
			),
			'plugins'        => array( 'detected' => (object) $detected, 'active' => Eclair_Export_Detector::active_plugins() ),
			'multilingual'   => array( 'provider' => Eclair_Export_Multilang::provider(), 'languages' => Eclair_Export_Multilang::languages() ),
			'options'        => $job['options'],
			'counts'         => array(
				'content'                 => (object) $content,
				'terms'                   => isset( $counts['terms'] ) ? (int) $counts['terms'] : 0,
				'authors'                 => isset( $counts['authors'] ) ? (int) $counts['authors'] : 0,
				'menus'                   => isset( $counts['menus'] ) ? (int) $counts['menus'] : 0,
				'media'                   => isset( $counts['media'] ) ? (int) $counts['media'] : 0,
				'media_bytes'             => isset( $counts['media_bytes'] ) ? (int) $counts['media_bytes'] : 0,
				'redirects'               => isset( $counts['redirects'] ) ? (int) $counts['redirects'] : 0,
				'shortcodes_unconverted'  => $unconverted,
			),
			'parts'          => array_map( static function ( $part, $i ) {
				return array( 'n' => $i + 1, 'file' => $part['file'], 'media_files' => (int) $part['files'] );
			}, $job['parts'], array_keys( $job['parts'] ) ),
			'files'          => (object) $checksums,
		);
	}

	private static function csv( array $fields ) {
		$h = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fputcsv( $h, $fields, ',', '"', '\\' );
		rewind( $h );
		$line = rtrim( (string) stream_get_contents( $h ), "\n" );
		fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return $line;
	}

	/** Frees the object cache kept in memory between batches (large sites), never a persistent cache. */
	private static function free_memory() {
		if ( function_exists( 'wp_cache_flush_runtime' ) && ! wp_using_ext_object_cache() ) {
			wp_cache_flush_runtime();
		}
	}

	/* ---------------------------------------------------------------- For the screen and the command line */

	/** Progress for display: percent, stage label, numbers. */
	public static function progress( $job ) {
		if ( ! $job ) {
			return array( 'status' => 'none', 'percent' => 0 );
		}
		$media   = 'include' === $job['options']['media'] ? 40 : 10;
		$weights = array( 'prepare' => 3, 'content' => 92 - $media, 'media' => $media, 'redirects' => 2, 'finalize' => 3 );
		$percent = 0;
		foreach ( $weights as $stage => $weight ) {
			$index = array_search( $stage, self::STAGES, true );
			$now   = array_search( $job['stage'], self::STAGES, true );
			if ( $index < $now ) {
				$percent += $weight;
			} elseif ( $index === $now ) {
				$total    = 'content' === $stage ? $job['totals']['content'] : ( 'media' === $stage ? $job['totals']['media'] : 0 );
				$done     = 'content' === $stage ? $job['done']['content'] : ( 'media' === $stage ? $job['done']['media'] : 0 );
				$percent += $total > 0 ? $weight * min( 1, $done / $total ) : 0;
			}
		}
		$labels = array(
			'prepare'   => __( 'Preparing (settings, authors, categories, menus)…', 'eclair-export' ),
			/* translators: 1: contents done, 2: total */
			'content'   => sprintf( __( 'Contents: %1$s of %2$s', 'eclair-export' ), number_format_i18n( $job['done']['content'] ), number_format_i18n( $job['totals']['content'] ) ),
			/* translators: 1: media done, 2: total */
			'media'     => sprintf( __( 'Media: %1$s of %2$s', 'eclair-export' ), number_format_i18n( $job['done']['media'] ), number_format_i18n( $job['totals']['media'] ) ),
			'redirects' => __( 'Redirections…', 'eclair-export' ),
			'finalize'  => __( 'Writing the archive…', 'eclair-export' ),
			'done'      => __( 'Export finished.', 'eclair-export' ),
		);
		return array(
			'status'  => $job['status'],
			'stage'   => $job['stage'],
			'percent' => 'done' === $job['stage'] ? 100 : (int) floor( $percent ),
			'label'   => isset( $labels[ $job['stage'] ] ) ? $labels[ $job['stage'] ] : '',
			'error'   => (string) $job['error'],
			'parts'   => 'done' === $job['status'] ? array_values( array_filter( $job['parts'], static function ( $p ) use ( $job ) {
				return ! in_array( $p['file'], $job['downloaded'], true );
			} ) ) : array(),
			'counts'  => $job['counts'],
		);
	}

	/** Archive file of the finished job, by name (null if it is not one of its parts). */
	public static function part_path( $name ) {
		$job = self::job();
		if ( ! $job || 'done' !== $job['status'] || ! preg_match( '~^eclair-export-[a-z0-9-]+\.zip$~', (string) $name ) ) {
			return null;
		}
		foreach ( $job['parts'] as $part ) {
			if ( $part['file'] === $name ) {
				$file = Eclair_Export_Storage::job_dir( $job['id'] ) . '/' . $name;
				return is_file( $file ) ? $file : null;
			}
		}
		return null;
	}

	/** A part left the server: it is deleted; once every part is downloaded, the whole export is. */
	public static function downloaded( $name ) {
		$job = self::job();
		if ( ! $job ) {
			return;
		}
		$file = self::part_path( $name );
		if ( $file ) {
			@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$job['downloaded'][] = $name;
		$job['downloaded']   = array_values( array_unique( $job['downloaded'] ) );
		if ( count( $job['downloaded'] ) >= count( $job['parts'] ) ) {
			self::cancel();
			return;
		}
		self::save( $job );
	}
}
