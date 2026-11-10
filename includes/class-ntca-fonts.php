<?php
/**
 * Host Google Fonts on the site itself: the font CSS and files are downloaded once from
 * Google and served from wp-content/cache/nodethemes-cache-ai/fonts/.
 *
 * @package NodeThemes_Cache_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Local Google Fonts.
 */
class NTCA_Fonts {

	const QUEUE = 'ntca_fonts_queue';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'ntca_fonts', array( __CLASS__, 'run_queue' ) );
	}

	/**
	 * Folder of local fonts.
	 *
	 * @return string
	 */
	public static function dir() {
		return NTCA_CACHE_DIR . '/fonts';
	}

	/**
	 * URL of the local fonts folder.
	 *
	 * @return string
	 */
	public static function url() {
		return content_url( 'cache/nodethemes-cache-ai/fonts' );
	}

	/**
	 * Swap Google Fonts stylesheet links for local copies. Missing copies are downloaded
	 * in the background (or right away inside the admin's preview test).
	 *
	 * @param string $html    HTML.
	 * @param bool   $preview Inside the preview test.
	 * @return string
	 */
	public static function rewrite( $html, $preview = false ) {
		return preg_replace_callback(
			'#<link\b[^>]*href=(["\'])((?:https?:)?//fonts\.googleapis\.com/css2?\?[^"\']+)\1[^>]*>#i',
			function ( $m ) use ( $preview ) {
				$remote = html_entity_decode( $m[2] );
				if ( 0 === strpos( $remote, '//' ) ) {
					$remote = 'https:' . $remote;
				}
				$key = substr( md5( $remote ), 0, 16 );
				if ( ! is_file( self::dir() . '/' . $key . '.css' ) ) {
					if ( $preview ) {
						self::download( $remote );
					} else {
						self::queue( $remote );
					}
				}
				if ( ! is_file( self::dir() . '/' . $key . '.css' ) ) {
					return $m[0];
				}
				$local = self::url() . '/' . $key . '.css?ver=' . filemtime( self::dir() . '/' . $key . '.css' );
				return str_replace( $m[2], esc_url( $local ), $m[0] );
			},
			$html
		);
	}

	/**
	 * Remove now-useless preconnect hints to Google Fonts (only when local copies are used).
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function strip_preconnect( $html ) {
		if ( false !== stripos( $html, 'fonts.googleapis.com/css' ) ) {
			return $html; // Some fonts are still remote.
		}
		return preg_replace( '#<link\b[^>]*rel=["\'](?:preconnect|dns-prefetch)["\'][^>]*fonts\.(?:googleapis|gstatic)\.com[^>]*>\s*#i', '', $html );
	}

	/**
	 * Remember a stylesheet to download.
	 *
	 * @param string $url Google Fonts CSS URL.
	 */
	private static function queue( $url ) {
		$q = get_option( self::QUEUE, array() );
		$q = is_array( $q ) ? $q : array();
		if ( in_array( $url, $q, true ) || count( $q ) >= 20 ) {
			return;
		}
		$q[] = $url;
		update_option( self::QUEUE, $q, false );
		if ( ! wp_next_scheduled( 'ntca_fonts' ) ) {
			wp_schedule_single_event( time() + 5, 'ntca_fonts' );
		}
	}

	/**
	 * Cron: download queued fonts, then clear the cache so pages use them.
	 */
	public static function run_queue() {
		$q = get_option( self::QUEUE, array() );
		delete_option( self::QUEUE );
		$done = 0;
		foreach ( (array) $q as $url ) {
			$done += self::download( $url ) ? 1 : 0;
		}
		if ( $done ) {
			NTCA_Cache::purge_all();
		}
	}

	/**
	 * Download one Google Fonts stylesheet and its WOFF2 files.
	 *
	 * @param string $url CSS URL.
	 * @return bool
	 */
	public static function download( $url ) {
		if ( ! preg_match( '#^https://fonts\.googleapis\.com/css2?\?#', $url ) || ! NTCA_FS::mkdir( self::dir() ) ) {
			return false;
		}
		$key = substr( md5( $url ), 0, 16 );
		$ua  = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36'; // Google sends WOFF2 to modern browsers.
		$r   = wp_safe_remote_get( $url, array( 'timeout' => 15, 'user-agent' => $ua ) );
		if ( is_wp_error( $r ) || 200 !== (int) wp_remote_retrieve_response_code( $r ) ) {
			return false;
		}
		$css = (string) wp_remote_retrieve_body( $r );
		if ( '' === $css || strlen( $css ) > 500000 ) {
			return false;
		}
		NTCA_FS::mkdir( self::dir() . '/' . $key );
		$files = 0;
		$ok    = true;
		$css   = preg_replace_callback(
			'#url\((["\']?)(https://fonts\.gstatic\.com/[^)"\']+)\1\)#i',
			function ( $m ) use ( $key, &$files, &$ok ) {
				if ( ++$files > 60 ) {
					$ok = false;
					return $m[0];
				}
				$name = substr( md5( $m[2] ), 0, 12 ) . '-' . preg_replace( '/[^A-Za-z0-9._-]/', '', basename( (string) wp_parse_url( $m[2], PHP_URL_PATH ) ) );
				$path = self::dir() . '/' . $key . '/' . $name;
				if ( ! is_file( $path ) ) {
					$f = wp_safe_remote_get( $m[2], array( 'timeout' => 15 ) );
					$b = is_wp_error( $f ) ? '' : (string) wp_remote_retrieve_body( $f );
					if ( '' === $b || strlen( $b ) > 2000000 || ! NTCA_FS::put( $path, $b ) ) {
						$ok = false;
						return $m[0];
					}
				}
				return 'url(' . $key . '/' . $name . ')';
			},
			$css
		);
		if ( ! $ok || ! $files ) {
			return false;
		}
		return NTCA_FS::put( self::dir() . '/' . $key . '.css', "/* Google Fonts, hosted locally by NodeThemes Cache AI */\n" . $css );
	}
}
