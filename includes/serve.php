<?php
/**
 * Early page-cache delivery. This file runs before WordPress is fully loaded
 * (from wp-content/advanced-cache.php, or from the main plugin file when the
 * drop-in is not installed), so it must not use WordPress functions.
 *
 * @package NodeThemes_Cache_AI
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'ntca_cache_file_for_request' ) ) {

	/**
	 * Folder name used for a host (also the name of its config file).
	 *
	 * @param string $host Host, with port.
	 * @return string
	 */
	function ntca_host_dir( $host ) {
		return str_replace( array( ':', '[', ']' ), '_', strtolower( (string) $host ) );
	}

	/**
	 * Load the cache settings the plugin wrote for this host. On a multisite network with
	 * sub-folders, the site whose address matches the longest part of the path is used.
	 *
	 * @return array|false
	 */
	function ntca_serve_config() {
		static $cfg = null;
		if ( null !== $cfg ) {
			return $cfg;
		}
		$cfg  = false;
		$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( (string) $_SERVER['HTTP_HOST'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- checked against a strict character list.
		if ( '' === $host || ! preg_match( '/^[a-z0-9.\-:\[\]]+$/', $host ) ) {
			return $cfg;
		}
		$dir  = defined( 'NTCA_CACHE_DIR' ) ? NTCA_CACHE_DIR : WP_CONTENT_DIR . '/cache/nodethemes-cache-ai';
		$file = $dir . '/config-' . ntca_host_dir( $host ) . '.php';
		$all  = is_file( $file ) ? include $file : false;
		if ( ! is_array( $all ) || empty( $all['sites'] ) ) {
			return $cfg;
		}
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? strtolower( (string) $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- only compared.
		$best = -1;
		foreach ( (array) $all['sites'] as $prefix => $site ) {
			$prefix = (string) $prefix;
			if ( 0 === strpos( $uri, $prefix ) && strlen( $prefix ) > $best ) {
				$best = strlen( $prefix );
				$cfg  = $site;
			}
		}
		return $cfg;
	}

	/**
	 * Is this a phone? Only used when "separate cache for mobile" is on.
	 *
	 * @return bool
	 */
	function ntca_serve_is_mobile() {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- only matched against a fixed pattern.
		return (bool) preg_match( '/Mobile|Android|Silk\/|Kindle|BlackBerry|Opera Mini|Opera Mobi|iPhone|iPod/i', $ua );
	}

	/**
	 * The cache file for the current request, or '' when this request must not be cached.
	 *
	 * @param array $cfg Cache settings.
	 * @return string
	 */
	function ntca_cache_file_for_request( $cfg ) {
		if ( ! is_array( $cfg ) || empty( $cfg['enabled'] ) || empty( $cfg['dir'] ) ) {
			return '';
		}
		if ( PHP_SAPI === 'cli' || ( defined( 'WP_INSTALLING' ) && WP_INSTALLING ) ) {
			return '';
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared with fixed values.
		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return '';
		}
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- checked against a strict character list below.
		$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( (string) $_SERVER['HTTP_HOST'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- checked against a strict character list below.
		if ( '' === $host || ! preg_match( '/^[a-z0-9.\-:\[\]]+$/', $host ) ) {
			return '';
		}
		if ( ! empty( $cfg['hosts'] ) && ! in_array( $host, (array) $cfg['hosts'], true ) ) {
			return '';
		}
		$parts = explode( '?', $uri, 2 );
		$path  = $parts[0];
		if ( '' === $path || '/' !== $path[0] || false !== strpos( $path, '..' ) || ! preg_match( '#^[A-Za-z0-9\-_./%~@+]*$#', $path ) ) {
			return '';
		}
		foreach ( array( '/wp-admin', '/wp-json', '/wp-login.php', '/xmlrpc.php', '/wp-cron.php', '/wp-content/', '/wp-includes/', '/feed', '.xml', '.txt', '.php', 'sitemap' ) as $skip ) {
			if ( false !== stripos( $path, $skip ) ) {
				return '';
			}
		}
		// Query strings: only the tracking keys listed in the settings may be present (they are ignored).
		if ( isset( $parts[1] ) && '' !== $parts[1] ) {
			parse_str( $parts[1], $q );
			$ignore = isset( $cfg['ignore_qs'] ) ? (array) $cfg['ignore_qs'] : array();
			foreach ( array_keys( (array) $q ) as $k ) {
				if ( ! in_array( strtolower( (string) $k ), $ignore, true ) ) {
					return '';
				}
			}
		}
		// Logged-in users, commenters, carts and other personal cookies are never served from cache.
		$cookies = isset( $cfg['cookies'] ) ? (array) $cfg['cookies'] : array();
		foreach ( array_keys( $_COOKIE ) as $name ) {
			foreach ( $cookies as $prefix ) {
				if ( '' !== $prefix && 0 === stripos( (string) $name, $prefix ) ) {
					return '';
				}
			}
		}
		$lpath = strtolower( rawurldecode( $path ) );
		$slash = rtrim( $lpath, '/' ) . '/';
		foreach ( isset( $cfg['exclude'] ) ? (array) $cfg['exclude'] : array() as $ex ) {
			$ex = strtolower( trim( (string) $ex ) );
			if ( '' === $ex ) {
				continue;
			}
			if ( '/' === $ex ) {
				if ( '/' === $lpath ) {
					return '';
				}
				continue;
			}
			if ( false !== strpos( $ex, '*' ) ) {
				$re = '#^' . str_replace( '\*', '.*', preg_quote( $ex, '#' ) ) . '$#';
				if ( preg_match( $re, $lpath ) || preg_match( $re, $slash ) ) {
					return '';
				}
			} elseif ( false !== strpos( $lpath, $ex ) ) {
				return '';
			}
		}
		$host_dir = ntca_host_dir( $host );
		$sub      = trim( $path, '/' );
		$file     = rtrim( $cfg['dir'], '/' ) . '/' . $host_dir . ( '' === $sub ? '' : '/' . $sub ) . '/index';
		if ( ! empty( $cfg['mobile'] ) && ntca_serve_is_mobile() ) {
			$file .= '-mobile';
		}
		return $file . '.html';
	}

	/**
	 * Send the cached copy and stop, when there is a fresh one.
	 */
	function ntca_serve() {
		if ( defined( 'NTCA_SERVED' ) ) {
			return;
		}
		define( 'NTCA_SERVED', true );
		$cfg  = ntca_serve_config();
		$file = ntca_cache_file_for_request( $cfg );
		if ( '' === $file || ! is_file( $file ) ) {
			return;
		}
		$age = time() - (int) filemtime( $file );
		if ( $age > (int) $cfg['lifetime'] ) {
			return;
		}
		$charset = isset( $cfg['charset'] ) ? preg_replace( '/[^A-Za-z0-9\-_]/', '', (string) $cfg['charset'] ) : 'UTF-8';
		header( 'Content-Type: text/html; charset=' . $charset );
		header( 'X-NTCA-Cache: HIT' );
		header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', (int) filemtime( $file ) ) . ' GMT' );
		header( 'Vary: Accept-Encoding, Cookie' );
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'HEAD' === strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared with a fixed value.
			exit;
		}
		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- WordPress is not loaded yet; this is the plugin's own cache file.
		exit;
	}
}
