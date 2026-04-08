<?php
/**
 * File manager class.
 *
 * Provides a static interface for all filesystem operations exposed by the
 * WP Server Terminal file manager: listing directories, reading and writing
 * files, uploading, deleting (to trash), renaming, changing permissions, and
 * cleaning up the trash directory.
 *
 * All path arguments are validated through WST_Security::sanitize_path() before
 * any filesystem operation takes place, preventing directory-traversal attacks.
 * Sensitive operations are recorded in the audit log via WST_Audit_Log::log().
 *
 * @package WP_Server_Terminal
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WST_File_Manager
 *
 * Static helper layer for all file-manager filesystem operations.
 *
 * @since 1.0.0
 */
class WST_File_Manager {

	// ---------------------------------------------------------------------------
	// Constants
	// ---------------------------------------------------------------------------

	/**
	 * Maximum permitted upload file size in bytes (50 MB).
	 *
	 * @since 1.0.0
	 * @var   int
	 */
	const MAX_UPLOAD_SIZE = 52428800;

	/**
	 * Name of the trash directory relative to ABSPATH.
	 *
	 * Files moved to this directory are held for 7 days before being purged by
	 * clean_trash().
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const TRASH_DIR = '.wst-trash';

	/**
	 * Relative basenames that may never be deleted via the file manager.
	 *
	 * Checked against basename($path) before every delete operation.
	 *
	 * @since 1.0.0
	 * @var   string[]
	 */
	const PROTECTED_FILES = array( 'wp-config.php' );

	// ---------------------------------------------------------------------------
	// Directory listing
	// ---------------------------------------------------------------------------

	/**
	 * List the contents of a directory.
	 *
	 * Returns an array containing metadata for every entry inside $path,
	 * sorted with directories first and then alphabetically by name. The
	 * returned array also carries the resolved path, its parent directory, and
	 * whether the directory itself is writable.
	 *
	 * @since  1.0.0
	 *
	 * @param  string $path Absolute path to the directory to list.
	 * @return array|WP_Error {
	 *     Associative array on success.
	 *
	 *     @type string  $path     Resolved canonical path of the listed directory.
	 *     @type string  $parent   Parent directory of $path.
	 *     @type array[] $entries  Array of entry info arrays (see below).
	 *     @type bool    $writable Whether the directory itself is writable.
	 *
	 *     Each entry array contains:
	 *     @type string $name         Filename without path.
	 *     @type string $type         'dir', 'file', or 'link'.
	 *     @type int    $size         File size in bytes; 0 for directories.
	 *     @type string $permissions  Four-digit octal permissions string (e.g. '0755').
	 *     @type int    $modified     Unix timestamp of last modification.
	 *     @type bool   $readable     Whether the entry is readable by the web-server process.
	 *     @type bool   $writable     Whether the entry is writable by the web-server process.
	 *     @type bool   $is_protected Whether the entry appears in PROTECTED_FILES.
	 * }
	 */
	public static function list_directory( $path ) {
		$path = WST_Security::sanitize_path( $path );

		if ( is_wp_error( $path ) ) {
			return $path;
		}

		if ( ! is_dir( $path ) ) {
			return new WP_Error( 'not_a_directory', 'Path is not a directory.' );
		}

		$raw = scandir( $path );

		if ( false === $raw ) {
			return new WP_Error( 'scandir_failed', 'Could not read directory contents.' );
		}

		$entries = array();

		foreach ( $raw as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}

			$full_path = $path . '/' . $name;

			if ( is_dir( $full_path ) ) {
				$type = 'dir';
				$size = 0;
			} elseif ( is_link( $full_path ) ) {
				$type = 'link';
				$size = filesize( $full_path );
			} else {
				$type = 'file';
				$size = filesize( $full_path );
			}

			// filesize() can return false for directories and broken symlinks.
			if ( false === $size ) {
				$size = 0;
			}

			$entries[] = array(
				'name'         => $name,
				'type'         => $type,
				'size'         => $size,
				'permissions'  => sprintf( '%04o', fileperms( $full_path ) & 0777 ),
				'modified'     => filemtime( $full_path ),
				'readable'     => is_readable( $full_path ),
				'writable'     => is_writable( $full_path ),
				'is_protected' => in_array( $name, self::PROTECTED_FILES, true ),
			);
		}

		// Directories first, then alphabetical by name within each group.
		usort(
			$entries,
			function ( $a, $b ) {
				if ( $a['type'] !== $b['type'] ) {
					// 'dir' sorts before everything else.
					return 'dir' === $a['type'] ? -1 : 1;
				}

				return strcasecmp( $a['name'], $b['name'] );
			}
		);

		return array(
			'path'     => $path,
			'parent'   => dirname( $path ),
			'entries'  => $entries,
			'writable' => is_writable( $path ),
		);
	}

	// ---------------------------------------------------------------------------
	// File reading
	// ---------------------------------------------------------------------------

	/**
	 * Read the contents of a text file.
	 *
	 * Enforces a maximum file-size limit (read from plugin settings) and detects
	 * binary files so the caller can present an appropriate UI. The operation is
	 * written to the audit log on success.
	 *
	 * @since  1.0.0
	 *
	 * @param  string $path Absolute path to the file to read.
	 * @return array|WP_Error {
	 *     Associative array on success.
	 *
	 *     @type string $path      Resolved canonical file path.
	 *     @type string $content   File content; empty string for binary files.
	 *     @type bool   $is_binary Whether the file appears to be binary.
	 *     @type string $mime      MIME type detected by mime_content_type().
	 *     @type int    $size      File size in bytes.
	 *     @type string $language  CodeMirror language mode string (text files only).
	 *     @type int    $modified  Unix timestamp of last modification (text files only).
	 * }
	 */
	public static function read_file( $path ) {
		$path = WST_Security::sanitize_path( $path );

		if ( is_wp_error( $path ) ) {
			return $path;
		}

		if ( ! is_file( $path ) ) {
			return new WP_Error( 'not_a_file', 'Path is not a file.' );
		}

		$size = filesize( $path );
		$max  = (int) WST_Settings::get( 'max_output_size', 1048576 );

		if ( $size > $max ) {
			return new WP_Error(
				'file_too_large',
				"File is too large ({$size} bytes). Max: {$max} bytes."
			);
		}

		$content = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( false === $content ) {
			return new WP_Error( 'read_failed', 'Could not read file contents.' );
		}

		$mime = mime_content_type( $path );

		// Detect binary: non-UTF-8 encoding or presence of null bytes.
		if ( ! mb_check_encoding( $content, 'UTF-8' ) || str_contains( $content, "\x00" ) ) {
			return array(
				'path'      => $path,
				'content'   => '',
				'is_binary' => true,
				'mime'      => $mime,
				'size'      => $size,
			);
		}

		WST_Audit_Log::log( WST_Audit_Log::ACTION_FILE_READ, $path );

		return array(
			'path'      => $path,
			'content'   => $content,
			'is_binary' => false,
			'mime'      => $mime,
			'size'      => $size,
			'language'  => self::get_language_mode( $path ),
			'modified'  => filemtime( $path ),
		);
	}

	// ---------------------------------------------------------------------------
	// Language-mode detection
	// ---------------------------------------------------------------------------

	/**
	 * Return the CodeMirror language mode string for a given file path.
	 *
	 * The mode is determined solely from the file extension and defaults to
	 * 'text' when the extension is unrecognised.
	 *
	 * @since  1.0.0
	 *
	 * @param  string $path File path or filename (only the extension is examined).
	 * @return string       CodeMirror language mode identifier.
	 */
	public static function get_language_mode( $path ) {
		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

		$map = array(
			'php'  => 'php',
			'js'   => 'javascript',
			'jsx'  => 'javascript',
			'ts'   => 'javascript',
			'tsx'  => 'javascript',
			'css'  => 'css',
			'scss' => 'css',
			'less' => 'css',
			'html' => 'html',
			'htm'  => 'html',
			'json' => 'json',
			'sql'  => 'sql',
			'sh'   => 'shell',
			'bash' => 'shell',
			'py'   => 'python',
			'yaml' => 'yaml',
			'yml'  => 'yaml',
			'md'   => 'markdown',
			'xml'  => 'xml',
		);

		return isset( $map[ $ext ] ) ? $map[ $ext ] : 'text';
	}

	// ---------------------------------------------------------------------------
	// File writing
	// ---------------------------------------------------------------------------

	/**
	 * Write content to a file, creating a backup of any existing content first.
	 *
	 * The parent directory must be writable (for new files) or the file itself
	 * must be writable (for existing files). A `.wst-backup` copy is made before
	 * overwriting so that accidental overwrites can be recovered.
	 *
	 * @since  1.0.0
	 *
	 * @param  string $path    Absolute path to the file to write.
	 * @param  string $content New file content.
	 * @return array|WP_Error {
	 *     Associative array on success.
	 *
	 *     @type string $path           Resolved canonical file path.
	 *     @type int    $bytes_written  Number of bytes written.
	 *     @type bool   $backup_created Whether a backup file was created.
	 * }
	 */
	public static function write_file( $path, $content ) {
		$path = WST_Security::sanitize_path( $path );

		if ( is_wp_error( $path ) ) {
			return $path;
		}

		// The directory must be writable for new files; the file itself for existing.
		if ( ! is_writable( dirname( $path ) ) && ! is_writable( $path ) ) {
			return new WP_Error( 'not_writable', 'File or parent directory is not writable.' );
		}

		// Create a backup of the existing file if it is present.
		$backup = false;

		if ( file_exists( $path ) ) {
			// Store backups in a non-web-accessible directory outside the plugin's asset path
			$backup_dir = ABSPATH . '.wst-backups' . DIRECTORY_SEPARATOR;
			if ( ! is_dir( $backup_dir ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
				mkdir( $backup_dir, 0700, true );
				// Deny web access
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				file_put_contents( $backup_dir . '.htaccess', 'Deny from all' . PHP_EOL );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				file_put_contents( $backup_dir . 'index.php', '<?php // Silence is golden.' . PHP_EOL );
			}
			$backup_path = $backup_dir . basename( $path ) . '.' . time() . '.wst-backup';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
			$backup = copy( $path, $backup_path );
		}

		$result = file_put_contents( $path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		if ( false === $result ) {
			return new WP_Error( 'write_failed', 'Could not write file contents.' );
		}

		WST_Audit_Log::log( WST_Audit_Log::ACTION_FILE_WRITE, $path, 'success', 'warning' );

		return array(
			'path'           => $path,
			'bytes_written'  => strlen( $content ),
			'backup_created' => (bool) $backup,
		);
	}

	// ---------------------------------------------------------------------------
	// File deletion (soft — moves to trash)
	// ---------------------------------------------------------------------------

	/**
	 * Move a file to the plugin trash directory instead of permanently deleting it.
	 *
	 * Files in PROTECTED_FILES are refused. The trash directory is created
	 * automatically if it does not exist. A timestamp suffix is appended to the
	 * trashed filename to avoid collisions.
	 *
	 * @since  1.0.0
	 *
	 * @param  string $path Absolute path of the file to delete.
	 * @return array|WP_Error {
	 *     Associative array on success.
	 *
	 *     @type string $deleted_path Original path of the deleted file.
	 *     @type string $trash_path   Path where the file now lives inside the trash.
	 * }
	 */
	public static function delete_file( $path ) {
		$path = WST_Security::sanitize_path( $path );

		if ( is_wp_error( $path ) ) {
			return $path;
		}

		if ( in_array( basename( $path ), self::PROTECTED_FILES, true ) ) {
			return new WP_Error( 'protected_file', 'This file is protected and cannot be deleted.' );
		}

		$trash_dir = ABSPATH . self::TRASH_DIR;

		if ( ! is_dir( $trash_dir ) ) {
			if ( ! mkdir( $trash_dir, 0755, true ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
				return new WP_Error( 'trash_dir_failed', 'Could not create trash directory.' );
			}
		}

		$trash_path = $trash_dir . '/' . basename( $path ) . '.' . time();

		if ( ! rename( $path, $trash_path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			return new WP_Error( 'delete_failed', 'Could not move file to trash.' );
		}

		WST_Audit_Log::log( WST_Audit_Log::ACTION_FILE_DELETE, $path, 'success', 'warning' );

		return array(
			'deleted_path' => $path,
			'trash_path'   => $trash_path,
		);
	}

	// ---------------------------------------------------------------------------
	// File upload
	// ---------------------------------------------------------------------------

	/**
	 * Handle a file upload from the $_FILES superglobal.
	 *
	 * Validates the upload for errors, enforces the MAX_UPLOAD_SIZE limit,
	 * checks the MIME type against WordPress's allowed-mime list, and moves the
	 * temporary file to the target directory using move_uploaded_file().
	 *
	 * @since  1.0.0
	 *
	 * @param  string $target_dir Absolute path to the directory that should receive the file.
	 * @param  string $file_key   Key in $_FILES to read. Defaults to 'wst_file'.
	 * @return array|WP_Error {
	 *     Associative array on success.
	 *
	 *     @type string $path Final absolute path of the uploaded file.
	 *     @type int    $size File size in bytes.
	 *     @type string $name Sanitised filename used for storage.
	 * }
	 */
	public static function upload_file( $target_dir, $file_key = 'wst_file' ) {
		$target_dir = WST_Security::sanitize_path( $target_dir );

		if ( is_wp_error( $target_dir ) ) {
			return $target_dir;
		}

		if ( ! isset( $_FILES[ $file_key ] ) ) {
			return new WP_Error( 'no_file', 'No file was uploaded.' );
		}

		$file = $_FILES[ $file_key ]; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		if ( UPLOAD_ERR_OK !== $file['error'] ) {
			return new WP_Error(
				'upload_error',
				'Upload failed with error code: ' . (int) $file['error']
			);
		}

		if ( $file['size'] > self::MAX_UPLOAD_SIZE ) {
			return new WP_Error(
				'file_too_large',
				'Uploaded file exceeds the maximum allowed size of ' . self::MAX_UPLOAD_SIZE . ' bytes.'
			);
		}

		// Validate MIME type. Merge WordPress allowed MIME types with common extras
		// that wp_get_mime_types() may omit (plain text, shell scripts, etc.).
		$allowed_mimes = array_merge(
			get_allowed_mime_types(),
			array(
				'txt'  => 'text/plain',
				'log'  => 'text/plain',
				'sh'   => 'application/x-sh',
				'bash' => 'application/x-sh',
				'py'   => 'text/x-python',
				'rb'   => 'application/x-ruby',
				'php'  => 'application/x-php',
				'json' => 'application/json',
				'yaml' => 'application/x-yaml',
				'yml'  => 'application/x-yaml',
				'xml'  => 'text/xml',
				'sql'  => 'application/sql',
				'md'   => 'text/markdown',
				'csv'  => 'text/csv',
			)
		);

		$file_type = wp_check_filetype( $file['name'], $allowed_mimes );

		if ( ! $file_type['type'] ) {
			return new WP_Error( 'disallowed_type', 'File type is not allowed.' );
		}

		$filename    = sanitize_file_name( $file['name'] );
		$target_path = $target_dir . '/' . $filename;

		if ( ! move_uploaded_file( $file['tmp_name'], $target_path ) ) {
			return new WP_Error( 'move_failed', 'Could not move uploaded file to destination.' );
		}

		WST_Audit_Log::log( WST_Audit_Log::ACTION_FILE_UPLOAD, $target_path );

		return array(
			'path' => $target_path,
			'size' => $file['size'],
			'name' => $filename,
		);
	}

	// ---------------------------------------------------------------------------
	// Directory creation
	// ---------------------------------------------------------------------------

	/**
	 * Create a new directory at the given path.
	 *
	 * Intermediate directories are created automatically (recursive mkdir).
	 * The parent directory is validated through WST_Security::sanitize_path()
	 * before the mkdir call.
	 *
	 * @since  1.0.0
	 *
	 * @param  string $path Absolute path of the directory to create.
	 * @return array|WP_Error {
	 *     Associative array on success.
	 *
	 *     @type string $path Absolute path of the newly created directory.
	 * }
	 */
	public static function create_directory( $path ) {
		// Validate the parent; the new directory itself doesn't exist yet so we
		// sanitize its parent and then append the intended new segment.
		$parent = WST_Security::sanitize_path( dirname( $path ) );

		if ( is_wp_error( $parent ) ) {
			return $parent;
		}

		$new_path = $parent . '/' . basename( $path );

		if ( ! mkdir( $new_path, 0755, true ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
			return new WP_Error( 'mkdir_failed', 'Could not create directory.' );
		}

		return array(
			'path' => $new_path,
		);
	}

	// ---------------------------------------------------------------------------
	// Rename
	// ---------------------------------------------------------------------------

	/**
	 * Rename a file or directory.
	 *
	 * The new name is sanitised with sanitize_file_name() and the rename is
	 * rejected if a file already exists at the target path.
	 *
	 * @since  1.0.0
	 *
	 * @param  string $old_path Absolute path to the existing file or directory.
	 * @param  string $new_name New filename (basename only; must not contain path separators).
	 * @return array|WP_Error {
	 *     Associative array on success.
	 *
	 *     @type string $old_path Original absolute path.
	 *     @type string $new_path Absolute path after the rename.
	 * }
	 */
	public static function rename_file( $old_path, $new_name ) {
		$old_path = WST_Security::sanitize_path( $old_path );

		if ( is_wp_error( $old_path ) ) {
			return $old_path;
		}

		$new_name = sanitize_file_name( $new_name );
		$new_path = dirname( $old_path ) . '/' . $new_name;

		if ( file_exists( $new_path ) ) {
			return new WP_Error( 'already_exists', 'A file with that name already exists.' );
		}

		if ( ! rename( $old_path, $new_path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			return new WP_Error( 'rename_failed', 'Could not rename file.' );
		}

		return array(
			'old_path' => $old_path,
			'new_path' => $new_path,
		);
	}

	// ---------------------------------------------------------------------------
	// Permissions
	// ---------------------------------------------------------------------------

	/**
	 * Change the permissions of a file or directory.
	 *
	 * Accepts the mode as a decimal integer (e.g. 755) and converts it to the
	 * correct octal value before passing it to chmod(). The actual resulting
	 * permissions are read back from the filesystem and returned.
	 *
	 * @since  1.0.0
	 *
	 * @param  string $path Absolute path to the file or directory.
	 * @param  int    $mode Desired permissions expressed as a decimal integer
	 *                      representing the octal value (e.g. 755 for rwxr-xr-x).
	 * @return array|WP_Error {
	 *     Associative array on success.
	 *
	 *     @type string $path Resolved canonical path.
	 *     @type string $mode Four-digit octal permissions string as read back from
	 *                        the filesystem (e.g. '0755').
	 * }
	 */
	public static function chmod_file( $path, $mode ) {
		$path = WST_Security::sanitize_path( $path );

		if ( is_wp_error( $path ) ) {
			return $path;
		}

		// Convert decimal representation of octal (e.g. 755) to the actual octal value.
		$octal_mode = octdec( sprintf( '%04d', (int) $mode ) );

		if ( ! chmod( $path, $octal_mode ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
			return new WP_Error( 'chmod_failed', 'Could not change file permissions.' );
		}

		// Clear the stat cache so fileperms() returns fresh data.
		clearstatcache( true, $path );

		return array(
			'path' => $path,
			'mode' => sprintf( '%04o', fileperms( $path ) & 0777 ),
		);
	}

	// ---------------------------------------------------------------------------
	// Trash maintenance
	// ---------------------------------------------------------------------------

	/**
	 * Delete files from the trash directory that are older than seven days.
	 *
	 * Iterates over every entry in the TRASH_DIR folder and permanently removes
	 * any whose modification time is more than 604 800 seconds (7 days) in the
	 * past. Returns the number of files successfully deleted.
	 *
	 * @since  1.0.0
	 *
	 * @return int Number of files permanently deleted from the trash.
	 */
	public static function clean_trash() {
		$trash_dir = ABSPATH . self::TRASH_DIR;

		if ( ! is_dir( $trash_dir ) ) {
			return 0;
		}

		$entries = scandir( $trash_dir );

		if ( false === $entries ) {
			return 0;
		}

		$cutoff  = time() - ( 7 * DAY_IN_SECONDS );
		$deleted = 0;

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$entry_path = $trash_dir . '/' . $entry;

			if ( filemtime( $entry_path ) < $cutoff ) {
				if ( is_dir( $entry_path ) ) {
					// Remove directory trees that somehow ended up in the trash.
					if ( self::rmdir_recursive( $entry_path ) ) {
						$deleted++;
					}
				} elseif ( unlink( $entry_path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
					$deleted++;
				}
			}
		}

		// Also purge old .wst-backup files from the backup directory (older than 7 days).
		$backup_dir = ABSPATH . '.wst-backups' . DIRECTORY_SEPARATOR;

		if ( is_dir( $backup_dir ) ) {
			$backup_entries = scandir( $backup_dir );

			if ( false !== $backup_entries ) {
				foreach ( $backup_entries as $entry ) {
					if ( '.' === $entry || '..' === $entry ) {
						continue;
					}

					// Only remove .wst-backup files; leave .htaccess and index.php in place.
					if ( substr( $entry, -11 ) !== '.wst-backup' ) {
						continue;
					}

					$entry_path = $backup_dir . $entry;

					if ( is_file( $entry_path ) && filemtime( $entry_path ) < $cutoff ) {
						if ( unlink( $entry_path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
							$deleted++;
						}
					}
				}
			}
		}

		return $deleted;
	}

	// ---------------------------------------------------------------------------
	// Internal helpers
	// ---------------------------------------------------------------------------

	/**
	 * Recursively delete a directory and all its contents.
	 *
	 * Used internally by clean_trash() to handle directory entries that may
	 * have been placed in the trash folder.
	 *
	 * @since  1.0.0
	 * @access private
	 *
	 * @param  string $dir Absolute path to the directory to remove.
	 * @return bool        True on success, false if any removal failed.
	 */
	private static function rmdir_recursive( $dir ) {
		$entries = scandir( $dir );

		if ( false === $entries ) {
			return false;
		}

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$entry_path = $dir . '/' . $entry;

			if ( is_dir( $entry_path ) ) {
				if ( ! self::rmdir_recursive( $entry_path ) ) {
					return false;
				}
			} else {
				if ( ! unlink( $entry_path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
					return false;
				}
			}
		}

		return rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}
}
