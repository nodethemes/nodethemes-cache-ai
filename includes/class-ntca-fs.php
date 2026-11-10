<?php
/**
 * Small file helpers for the cache folder, the drop-in and wp-config.php.
 *
 * @package NodeThemes_Cache_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * File helpers.
 */
class NTCA_FS {

	/**
	 * WordPress filesystem (direct method).
	 *
	 * @return WP_Filesystem_Base|false
	 */
	public static function fs() {
		global $wp_filesystem;
		if ( $wp_filesystem instanceof WP_Filesystem_Direct ) {
			return $wp_filesystem;
		}
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! class_exists( 'WP_Filesystem_Direct' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
		}
		if ( ! defined( 'FS_CHMOD_DIR' ) ) {
			define( 'FS_CHMOD_DIR', ( fileperms( ABSPATH ) & 0777 | 0755 ) );
		}
		if ( ! defined( 'FS_CHMOD_FILE' ) ) {
			define( 'FS_CHMOD_FILE', ( fileperms( ABSPATH . 'index.php' ) & 0777 | 0644 ) );
		}
		static $direct = null;
		if ( null === $direct ) {
			$direct = new WP_Filesystem_Direct( null );
		}
		return $direct;
	}

	/**
	 * Create a folder (and parents) with an index.php inside the cache root.
	 *
	 * @param string $dir Folder.
	 * @return bool
	 */
	public static function mkdir( $dir ) {
		if ( is_dir( $dir ) ) {
			return true;
		}
		$ok = wp_mkdir_p( $dir );
		if ( $ok && NTCA_CACHE_DIR === untrailingslashit( $dir ) ) {
			self::put( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
		}
		return $ok;
	}

	/**
	 * Write a file atomically (temp file + rename).
	 *
	 * @param string $file Path.
	 * @param string $data Contents.
	 * @return bool
	 */
	public static function put( $file, $data ) {
		$fs = self::fs();
		if ( ! $fs ) {
			return false;
		}
		$tmp = $file . '.' . wp_generate_password( 6, false ) . '.tmp';
		if ( ! $fs->put_contents( $tmp, $data, FS_CHMOD_FILE ) ) {
			return false;
		}
		if ( ! $fs->move( $tmp, $file, true ) ) {
			$fs->delete( $tmp );
			return false;
		}
		return true;
	}

	/**
	 * Read a file.
	 *
	 * @param string $file Path.
	 * @return string
	 */
	public static function get( $file ) {
		$fs = self::fs();
		return ( $fs && is_file( $file ) ) ? (string) $fs->get_contents( $file ) : '';
	}

	/**
	 * Delete a file.
	 *
	 * @param string $file Path.
	 */
	public static function delete( $file ) {
		$fs = self::fs();
		if ( $fs && file_exists( $file ) ) {
			$fs->delete( $file );
		}
	}

	/**
	 * Delete a folder of cache files. Only works inside the plugin's cache folder.
	 *
	 * @param string $dir Folder.
	 * @param bool   $keep_root Keep the folder itself.
	 */
	public static function rmdir( $dir, $keep_root = false ) {
		$dir  = untrailingslashit( wp_normalize_path( $dir ) );
		$root = untrailingslashit( wp_normalize_path( NTCA_CACHE_DIR ) );
		if ( '' === $dir || 0 !== strpos( $dir, $root ) || ! is_dir( $dir ) ) {
			return;
		}
		$fs = self::fs();
		if ( ! $fs ) {
			return;
		}
		if ( $keep_root ) {
			foreach ( (array) $fs->dirlist( $dir, true, false ) as $name => $info ) {
				if ( 'index.php' === $name || 0 === strpos( $name, 'config' ) || 0 === strpos( $name, 'fonts' ) ) {
					continue;
				}
				$fs->delete( $dir . '/' . $name, true );
			}
			return;
		}
		$fs->delete( $dir, true );
	}

	/**
	 * Path of wp-config.php.
	 *
	 * @return string
	 */
	public static function wp_config_path() {
		if ( file_exists( ABSPATH . 'wp-config.php' ) ) {
			return ABSPATH . 'wp-config.php';
		}
		$up = dirname( ABSPATH ) . '/wp-config.php';
		if ( file_exists( $up ) && ! file_exists( dirname( ABSPATH ) . '/wp-settings.php' ) ) {
			return $up;
		}
		return '';
	}
}
