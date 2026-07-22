<?php
/**
 * Static asset storage and zip uploads.
 *
 * @package MrMurphyApps
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles app file storage under uploads/mrmurphy-apps/.
 */
class MRMurphy_Apps_Storage {

	/** @var string[] */
	private const BLOCKED_EXTENSIONS = array(
		'htaccess',
		'ini',
	);

	/** @var int */
	private const MAX_ZIP_BYTES = 52428800; // 50 MB.

	/** @var int */
	private const MAX_UNCOMPRESSED_BYTES = 104857600; // 100 MB.

	/** @var int */
	private const MAX_ENTRY_COUNT = 5000;

	/** @var int */
	private const MAX_SINGLE_FILE_BYTES = 26214400; // 25 MB.

	/** @var int */
	private const MAX_COMPRESSION_RATIO = 100;

	/**
	 * Ensure the uploads directory exists and is protected.
	 */
	public static function ensure_uploads_directory() {
		$dir = self::get_base_directory();

		if ( ! wp_mkdir_p( $dir ) ) {
			return;
		}

		self::write_directory_guard( $dir );
	}

	/**
	 * Get the base storage directory.
	 *
	 * @return string
	 */
	public static function get_base_directory() {
		$uploads = wp_upload_dir();

		return trailingslashit( $uploads['basedir'] ) . 'mrmurphy-apps';
	}

	/**
	 * Get the directory for a specific app slug.
	 *
	 * @param string $slug App slug.
	 * @return string
	 */
	public function get_app_directory( $slug ) {
		$slug = sanitize_title( (string) $slug );

		if ( '' === $slug ) {
			return '';
		}

		return trailingslashit( self::get_base_directory() ) . $slug;
	}

	/**
	 * List relative file paths for an app.
	 *
	 * @param string $slug App slug.
	 * @return string[]
	 */
	public function list_files( $slug ) {
		$dir = $this->get_app_directory( $slug );

		if ( ! is_dir( $dir ) ) {
			return array();
		}

		$files = array();
		$it    = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $it as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}

			$relative = ltrim( str_replace( $dir, '', wp_normalize_path( $file->getPathname() ) ), '/' );
			$files[]  = $relative;
		}

		sort( $files );

		return $files;
	}

	/**
	 * Resolve a requested path to an absolute file within an app directory.
	 *
	 * @param string $slug App slug.
	 * @param string $path Request path relative to the app root.
	 * @param string $entry_file Entry file used for directory requests.
	 * @return string|null Absolute file path or null if not found.
	 */
	public function resolve_file_path( $slug, $path, $entry_file = 'index.html' ) {
		$app_dir = $this->get_app_directory( $slug );

		if ( ! is_dir( $app_dir ) ) {
			return null;
		}

		$path = wp_normalize_path( $path );
		$path = ltrim( $path, '/' );

		if ( '' === $path || '/' === substr( $path, -1 ) ) {
			$path = $entry_file;
		}

		$requested = wp_normalize_path( $app_dir . '/' . $path );

		if ( $this->is_path_within_directory( $requested, $app_dir ) && is_file( $requested ) ) {
			return $requested;
		}

		// SPA fallback: serve the entry HTML for unknown routes without file extensions.
		if ( false === strpos( basename( $path ), '.' ) ) {
			$entry = wp_normalize_path( $app_dir . '/' . $entry_file );
			if ( $this->is_path_within_directory( $entry, $app_dir ) && is_file( $entry ) ) {
				return $entry;
			}
		}

		return null;
	}

	/**
	 * Import a zip archive into an app directory.
	 *
	 * @param int    $post_id App post ID.
	 * @param string $zip_path Absolute path to uploaded zip.
	 * @return true|WP_Error
	 */
	public function import_zip( $post_id, $zip_path ) {
		$post = get_post( $post_id );

		if ( ! $post || 'mrmurphy_app' !== $post->post_type ) {
			return new WP_Error( 'invalid_app', __( 'Invalid app.', 'mrmurphy-apps' ) );
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'missing_zip', __( 'ZipArchive is not available on this server.', 'mrmurphy-apps' ) );
		}

		if ( ! is_readable( $zip_path ) ) {
			return new WP_Error( 'missing_zip_file', __( 'Uploaded zip file could not be read.', 'mrmurphy-apps' ) );
		}

		if ( filesize( $zip_path ) > self::MAX_ZIP_BYTES ) {
			return new WP_Error( 'zip_too_large', __( 'Zip file exceeds the 50 MB limit.', 'mrmurphy-apps' ) );
		}

		$slug    = $post->post_name;
		$app_dir = $this->get_app_directory( $slug );

		self::ensure_uploads_directory();

		$temp_dir = wp_normalize_path( $app_dir . '-tmp-' . wp_generate_password( 8, false ) );

		if ( ! wp_mkdir_p( $temp_dir ) ) {
			return new WP_Error( 'mkdir_failed', __( 'Could not create a temporary directory.', 'mrmurphy-apps' ) );
		}

		$zip = new ZipArchive();
		$opened = $zip->open( $zip_path );

		if ( true !== $opened ) {
			self::delete_directory( $temp_dir );
			return new WP_Error( 'zip_open_failed', __( 'Could not open zip archive.', 'mrmurphy-apps' ) );
		}

		$total_uncompressed = 0;
		$total_compressed   = 0;
		$entry_count        = 0;

		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = wp_normalize_path( (string) $zip->getNameIndex( $i ) );

			if ( self::is_dangerous_archive_path( $name ) ) {
				$zip->close();
				self::delete_directory( $temp_dir );
				return new WP_Error( 'unsafe_zip_entry', sprintf( __( 'Unsafe zip entry blocked: %s', 'mrmurphy-apps' ), $name ) );
			}

			$extension = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );

			if ( in_array( $extension, self::BLOCKED_EXTENSIONS, true ) ) {
				$zip->close();
				self::delete_directory( $temp_dir );
				return new WP_Error( 'blocked_extension', sprintf( __( 'Blocked file type in zip: %s', 'mrmurphy-apps' ), $name ) );
			}

			$stat = $zip->statIndex( $i );

			if ( ! is_array( $stat ) ) {
				$zip->close();
				self::delete_directory( $temp_dir );
				return new WP_Error( 'zip_stat_failed', __( 'Could not inspect zip entry metadata.', 'mrmurphy-apps' ) );
			}

			if ( isset( $stat['external_attr'] ) && ( ( $stat['external_attr'] >> 16 ) & 0x0A ) ) {
				$zip->close();
				self::delete_directory( $temp_dir );
				return new WP_Error( 'symlink_in_zip', __( 'Zip archive contains symlinks, which are not allowed.', 'mrmurphy-apps' ) );
			}

			$uncompressed = isset( $stat['size'] ) ? (int) $stat['size'] : 0;
			$compressed   = isset( $stat['comp_size'] ) ? (int) $stat['comp_size'] : 0;

			if ( $uncompressed > self::MAX_SINGLE_FILE_BYTES ) {
				$zip->close();
				self::delete_directory( $temp_dir );
				return new WP_Error( 'zip_entry_too_large', __( 'A file in the zip exceeds the maximum allowed size.', 'mrmurphy-apps' ) );
			}

			$total_uncompressed += $uncompressed;
			$total_compressed   += $compressed;
			$entry_count++;

			if ( $entry_count > self::MAX_ENTRY_COUNT ) {
				$zip->close();
				self::delete_directory( $temp_dir );
				return new WP_Error( 'zip_too_many_entries', __( 'The zip contains too many files.', 'mrmurphy-apps' ) );
			}

			if ( $total_uncompressed > self::MAX_UNCOMPRESSED_BYTES ) {
				$zip->close();
				self::delete_directory( $temp_dir );
				return new WP_Error( 'zip_uncompressed_too_large', __( 'The zip decompresses to more than the maximum allowed size.', 'mrmurphy-apps' ) );
			}
		}

		if ( $total_compressed > 0 && ( $total_uncompressed / $total_compressed ) > self::MAX_COMPRESSION_RATIO ) {
			$zip->close();
			self::delete_directory( $temp_dir );
			return new WP_Error( 'zip_ratio_too_high', __( 'The zip compression ratio exceeds the maximum allowed.', 'mrmurphy-apps' ) );
		}

		if ( ! $zip->extractTo( $temp_dir ) ) {
			$zip->close();
			self::delete_directory( $temp_dir );
			return new WP_Error( 'extract_failed', __( 'Could not extract zip archive.', 'mrmurphy-apps' ) );
		}

		$zip->close();

		$source_dir = self::find_content_root( $temp_dir );

		if ( '' === $source_dir ) {
			self::delete_directory( $temp_dir );
			return new WP_Error( 'symlink_in_extracted', __( 'Extracted archive contains symlinks, which are not allowed.', 'mrmurphy-apps' ) );
		}

		$backup_dir = '';
		if ( is_dir( $app_dir ) ) {
			$backup_dir = $app_dir . '.old-' . wp_generate_password( 8, false );
			if ( ! @rename( $app_dir, $backup_dir ) ) {
				self::delete_directory( $temp_dir );
				return new WP_Error(
					'backup_failed',
					__( 'Could not back up existing app files. New upload aborted; existing files untouched.', 'mrmurphy-apps' )
				);
			}
		}

		if ( ! rename( $source_dir, $app_dir ) ) {
			if ( $backup_dir ) {
				@rename( $backup_dir, $app_dir );
			}
			self::delete_directory( $temp_dir );
			return new WP_Error( 'move_failed', __( 'Could not move extracted files into place.', 'mrmurphy-apps' ) );
		}

		if ( $backup_dir && is_dir( $backup_dir ) ) {
			self::delete_directory( $backup_dir );
		}

		if ( $source_dir !== $temp_dir && is_dir( $temp_dir ) ) {
			self::delete_directory( $temp_dir );
		}

		self::write_directory_guard( $app_dir );

		$entry = self::detect_entry_file( $app_dir );
		update_post_meta( $post_id, MRMURPHY_APPS_META_ENTRY, $entry );

		// Run the app's server-side init script if present.
		$init_file = trailingslashit( $app_dir ) . 'server/init.php';
		if ( file_exists( $init_file ) ) {
			$mrmurphy_app_slug = $slug;
			try {
				include $init_file;
			} catch ( Throwable $e ) {
				error_log( sprintf(
					'mrmurphy-apps: Fatal in init.php for app "%s": %s',
					$slug,
					$e->getMessage()
				) );
			}
		}

		return true;
	}

	/**
	 * Delete all files for an app.
	 *
	 * @param string $slug App slug.
	 */
	public function delete_app_files( $slug ) {
		$slug = sanitize_title( (string) $slug );

		if ( '' === $slug ) {
			return;
		}

		$dir = $this->get_app_directory( $slug );

		if ( is_dir( $dir ) && $this->is_path_within_directory( $dir, self::get_base_directory() ) ) {
			self::delete_directory( $dir );
		}
	}

	/**
	 * Detect the entry HTML file for an app.
	 *
	 * @param string $dir App directory.
	 * @return string
	 */
	public static function detect_entry_file( $dir ) {
		foreach ( array( 'index.html', 'index.htm' ) as $candidate ) {
			if ( is_file( trailingslashit( $dir ) . $candidate ) ) {
				return $candidate;
			}
		}

		return 'index.html';
	}

	/**
	 * If the zip contains a single top-level folder, use that as the content root.
	 *
	 * @param string $temp_dir Extracted temp directory.
	 * @return string
	 */
	private static function find_content_root( $temp_dir ) {
		$entries = array_values( array_diff( scandir( $temp_dir ), array( '.', '..', '__MACOSX', '.DS_Store' ) ) );

		if ( 1 === count( $entries ) ) {
			$only = wp_normalize_path( trailingslashit( $temp_dir ) . $entries[0] );
			$lstat = @lstat( $only );
			if ( $lstat && ( $lstat['mode'] & 0xA000 ) === 0xA000 ) {
				return '';
			}
			if ( $lstat && ( $lstat['mode'] & 0x4000 ) ) {
				return $only;
			}
		}

		return $temp_dir;
	}

	/**
	 * Determine whether a path stays inside a directory.
	 *
	 * @param string $path Candidate file path.
	 * @param string $directory Directory boundary.
	 * @return bool
	 */
	private function is_path_within_directory( $path, $directory ) {
		$path      = wp_normalize_path( realpath( $path ) ?: $path );
		$directory = wp_normalize_path( realpath( $directory ) ?: $directory );

		return 0 === strpos( trailingslashit( $path ), trailingslashit( $directory ) );
	}

	/**
	 * Reject archive entries with traversal or absolute paths.
	 *
	 * @param string $name Zip entry name.
	 * @return bool
	 */
	private static function is_dangerous_archive_path( $name ) {
		if ( '' === $name || '/' === $name[0] || '\\' === $name[0] ) {
			return true;
		}

		if ( preg_match( '/(^|\/)\.\.(\/|$)/', $name ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Recursively delete a directory.
	 *
	 * @param string $dir Directory path.
	 */
	public static function delete_directory( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $items as $item ) {
			if ( is_link( $item->getPathname() ) ) {
				unlink( $item->getPathname() );
			} elseif ( $item->isDir() ) {
				if ( ! @rmdir( $item->getPathname() ) ) {
					$error = error_get_last();
					if ( $error ) {
						error_log( sprintf( 'Failed to remove directory %s: %s', $item->getPathname(), $error['message'] ) );
					}
				}
			} else {
				if ( ! @unlink( $item->getPathname() ) ) {
					$error = error_get_last();
					if ( $error ) {
						error_log( sprintf( 'Failed to remove file %s: %s', $item->getPathname(), $error['message'] ) );
					}
				}
			}
		}

		if ( ! @rmdir( $dir ) ) {
			$error = error_get_last();
			if ( $error ) {
				error_log( sprintf( 'Failed to remove directory %s: %s', $dir, $error['message'] ) );
			}
		}
	}

	/**
	 * Write guard files to block PHP execution in app storage.
	 *
	 * @param string $dir Directory path.
	 */
	private static function write_directory_guard( $dir ) {
		$htaccess = trailingslashit( $dir ) . '.htaccess';

		if ( ! file_exists( $htaccess ) ) {
			$pattern = implode( '|', array_map( fn( $ext ) => preg_quote( (string) $ext, '/' ), self::BLOCKED_EXTENSIONS ) );
			file_put_contents(
				$htaccess,
				"<IfModule mod_php.c>\nphp_flag engine off\n</IfModule>\n<FilesMatch \"\\.(" . $pattern . ')$">' . "\nRequire all denied\n</FilesMatch>\n"
			);
		}

		$index_html = trailingslashit( $dir ) . 'index.html';

		if ( ! file_exists( $index_html ) ) {
			file_put_contents( $index_html, '' );
		}
	}
}
