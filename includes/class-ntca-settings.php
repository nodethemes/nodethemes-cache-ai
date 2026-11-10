<?php
/**
 * Settings, change history (rollback) and the config file read by the early cache.
 *
 * @package NodeThemes_Cache_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings store.
 */
class NTCA_Settings {

	const OPTION   = 'ntca_settings';
	const HISTORY  = 'ntca_history';
	const MAX_HIST = 10;

	/**
	 * Optimizations that change the page HTML. Each one is tested before it goes live.
	 *
	 * @return array key => [label, help]
	 */
	public static function optimizations() {
		$list = array(
			'minify_html'    => array( __( 'Minify HTML', 'nodethemes-cache-ai' ), __( 'Removes comments and extra spaces from the page HTML. Code inside pre, textarea, script and style is left as it is.', 'nodethemes-cache-ai' ) ),
			'minify_css'     => array( __( 'Minify inline CSS', 'nodethemes-cache-ai' ), __( 'Removes comments and spaces inside <style> blocks of the page.', 'nodethemes-cache-ai' ) ),
			'defer_js'       => array( __( 'Defer JavaScript files', 'nodethemes-cache-ai' ), __( 'Adds "defer" to script files so they stop blocking the page. This is the change most likely to break something, so test it.', 'nodethemes-cache-ai' ) ),
			'lazy_images'    => array( __( 'Lazy-load images', 'nodethemes-cache-ai' ), __( 'Adds loading="lazy" to images that do not have it. The first images of the page are skipped so the top of the page still shows fast.', 'nodethemes-cache-ai' ) ),
			'lazy_iframes'   => array( __( 'Lazy-load iframes and videos', 'nodethemes-cache-ai' ), __( 'Adds loading="lazy" to embedded iframes such as YouTube videos and maps.', 'nodethemes-cache-ai' ) ),
			'disable_emojis' => array( __( 'Remove the WordPress emoji script', 'nodethemes-cache-ai' ), __( 'Browsers show emoji on their own; the extra script and style are not needed.', 'nodethemes-cache-ai' ) ),
			'font_swap'      => array( __( 'Google Fonts: show text while fonts load', 'nodethemes-cache-ai' ), __( 'Adds display=swap to Google Fonts links so text is never invisible.', 'nodethemes-cache-ai' ) ),
			'local_fonts'    => array( __( 'Host Google Fonts on your own site', 'nodethemes-cache-ai' ), __( 'Downloads the Google Fonts your pages use once and serves them from your own site: faster, and visitors\' browsers no longer contact Google for fonts (helps with GDPR).', 'nodethemes-cache-ai' ) ),
		);
		// Add-ons can add their own optimizations; they are tested and rolled back like the others.
		return (array) apply_filters( 'ntca_optimizations', $list );
	}

	/**
	 * Defaults.
	 *
	 * @return array
	 */
	public static function defaults() {
		$opts = array();
		foreach ( array_keys( self::optimizations() ) as $k ) {
			$opts[ $k ] = 0;
		}
		return array(
			'cache'           => 1,
			'lifetime'        => 10,
			'mobile'          => 0,
			'opts'            => $opts,
			'defer_exclude'   => array( 'jquery.min.js', 'jquery-migrate' ),
			'lazy_skip'       => 2,
			'exclude_urls'    => array(),
			'exclude_cookies' => array(),
			'ignore_qs'       => array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid', 'msclkid', '_ga' ),
			'test_urls'       => array(),
			'heartbeat_front' => 0,
			'heartbeat_admin' => 0,
			'cart_fragments'  => 'default',
		);
	}

	/**
	 * Live settings.
	 *
	 * @return array
	 */
	public static function get() {
		$s = get_option( self::OPTION, array() );
		return self::normalize( is_array( $s ) ? $s : array() );
	}

	/**
	 * Fill in missing keys and clean values.
	 *
	 * @param array $s Raw settings.
	 * @return array
	 */
	public static function normalize( $s ) {
		$d = self::defaults();
		$s = wp_parse_args( $s, $d );
		$o = array();
		foreach ( array_keys( $d['opts'] ) as $k ) {
			$o[ $k ] = empty( $s['opts'][ $k ] ) ? 0 : 1;
		}
		$s['opts']     = $o;
		$s['cache']    = empty( $s['cache'] ) ? 0 : 1;
		$s['mobile']   = empty( $s['mobile'] ) ? 0 : 1;
		$s['lifetime'] = max( 1, min( 720, (int) $s['lifetime'] ) );
		$s['lazy_skip'] = max( 0, min( 10, (int) $s['lazy_skip'] ) );
		$s['heartbeat_front'] = empty( $s['heartbeat_front'] ) ? 0 : 1;
		$s['heartbeat_admin'] = in_array( (int) $s['heartbeat_admin'], array( 0, 60, 120 ), true ) ? (int) $s['heartbeat_admin'] : 0;
		$s['cart_fragments']  = in_array( $s['cart_fragments'], array( 'default', 'smart', 'off' ), true ) ? $s['cart_fragments'] : 'default';
		foreach ( array( 'defer_exclude', 'exclude_urls', 'exclude_cookies', 'ignore_qs', 'test_urls' ) as $k ) {
			$s[ $k ] = self::lines( $s[ $k ] );
		}
		return array_intersect_key( $s, $d );
	}

	/**
	 * Turn a textarea or array into a clean list.
	 *
	 * @param mixed $v Value.
	 * @return array
	 */
	public static function lines( $v ) {
		if ( is_string( $v ) ) {
			$v = preg_split( '/[\r\n,]+/', $v );
		}
		$out = array();
		foreach ( (array) $v as $line ) {
			$line = trim( sanitize_text_field( (string) $line ) );
			if ( '' !== $line && ! in_array( $line, $out, true ) ) {
				$out[] = $line;
			}
		}
		return array_slice( $out, 0, 100 );
	}

	/**
	 * A short fingerprint of the optimization part of the settings (what a test covers).
	 *
	 * @param array $s Settings.
	 * @return string
	 */
	public static function opt_hash( $s ) {
		$s = self::normalize( $s );
		return substr( md5( wp_json_encode( array( $s['opts'], $s['defer_exclude'], $s['lazy_skip'] ) ) ), 0, 12 );
	}

	/**
	 * Are any HTML optimizations switched on?
	 *
	 * @param array $s Settings.
	 * @return bool
	 */
	public static function any_opt( $s ) {
		return in_array( 1, array_map( 'intval', (array) $s['opts'] ), true );
	}

	/**
	 * Save new live settings, keep the old ones in the history, rebuild the config and clear the cache.
	 *
	 * @param array  $new    New settings.
	 * @param string $note   What changed (shown in the history).
	 * @param string $source Who made the change: admin, restore, test, guard, retest.
	 * @return array Saved settings.
	 */
	public static function apply( $new, $note = '', $source = 'admin' ) {
		$old = self::get();
		$new = self::normalize( $new );
		if ( $old !== $new ) {
			$hist = get_option( self::HISTORY, array() );
			$hist = is_array( $hist ) ? $hist : array();
			array_unshift(
				$hist,
				array(
					'time'     => time(),
					'user'     => get_current_user_id(),
					'note'     => sanitize_text_field( $note ),
					'source'   => sanitize_key( $source ),
					'settings' => $old,
				)
			);
			update_option( self::HISTORY, array_slice( $hist, 0, self::MAX_HIST ), false );
			update_option( self::OPTION, $new );
			self::write_config( $new );
			NTCA_Cache::purge_all();
			do_action( 'ntca_settings_applied', $new, $old, $source );
		}
		return $new;
	}

	/**
	 * Change history, newest first.
	 *
	 * @return array
	 */
	public static function history() {
		$h = get_option( self::HISTORY, array() );
		return is_array( $h ) ? $h : array();
	}

	/**
	 * Restore a history entry.
	 *
	 * @param int $i Index.
	 * @return bool
	 */
	public static function restore( $i ) {
		$h = self::history();
		if ( ! isset( $h[ $i ]['settings'] ) ) {
			return false;
		}
		/* translators: %s: date and time */
		self::apply( $h[ $i ]['settings'], sprintf( __( 'Restored the settings from %s', 'nodethemes-cache-ai' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $h[ $i ]['time'] ) ), 'restore' );
		return true;
	}

	/**
	 * Cookies that always skip the cache.
	 *
	 * @param array $s Settings.
	 * @return array
	 */
	public static function bypass_cookies( $s ) {
		$c = array( 'wordpress_logged_in_', 'wp-postpass_', 'comment_author_', 'wordpress_no_cache', 'woocommerce_items_in_cart', 'woocommerce_cart_hash', 'wp_woocommerce_session_', 'edd_items_in_cart' );
		return array_values( array_unique( array_merge( $c, $s['exclude_cookies'] ) ) );
	}

	/**
	 * URL parts that always skip the cache (WooCommerce cart, checkout and account pages are added automatically).
	 *
	 * @param array $s Settings.
	 * @return array
	 */
	public static function bypass_urls( $s ) {
		$u = array();
		if ( function_exists( 'wc_get_page_id' ) ) {
			foreach ( array( 'cart', 'checkout', 'myaccount' ) as $p ) {
				$id = wc_get_page_id( $p );
				if ( $id > 0 ) {
					$path = wp_parse_url( get_permalink( $id ), PHP_URL_PATH );
					if ( $path && '/' !== $path ) {
						$u[] = strtolower( untrailingslashit( $path ) ) . '/*';
					}
				}
			}
		}
		foreach ( $s['exclude_urls'] as $x ) {
			$p = wp_parse_url( $x, PHP_URL_PATH );
			$u[] = strtolower( $p ? $p : $x );
		}
		return array_values( array_unique( array_filter( $u ) ) );
	}

	/**
	 * Write the small config file read by the early cache (no WordPress functions there).
	 *
	 * @param array|null $s Settings.
	 * @return bool
	 */
	public static function write_config( $s = null, $remove = false ) {
		$s = $s ? $s : self::get();
		if ( ! NTCA_FS::mkdir( NTCA_CACHE_DIR ) ) {
			return false;
		}
		if ( ! file_exists( NTCA_CACHE_DIR . '/index.php' ) ) {
			NTCA_FS::put( NTCA_CACHE_DIR . '/index.php', "<?php\n// Silence is golden.\n" );
		}
		NTCA_FS::delete( NTCA_CACHE_DIR . '/config.php' ); // Format before 1.1.0.
		list( $host, $prefix ) = self::site_key();
		$file = self::config_file( $host );
		$all  = is_file( $file ) ? include $file : array();
		$all  = is_array( $all ) && isset( $all['sites'] ) ? $all : array( 'sites' => array() );
		if ( $remove ) {
			unset( $all['sites'][ $prefix ] );
			if ( ! $all['sites'] ) {
				NTCA_FS::delete( $file );
				return true;
			}
		} else {
			$all['sites'][ $prefix ] = array(
				'version'   => NTCA_VERSION,
				'enabled'   => (int) $s['cache'],
				'lifetime'  => (int) $s['lifetime'] * HOUR_IN_SECONDS,
				'mobile'    => (int) $s['mobile'],
				'dir'       => NTCA_CACHE_DIR,
				'hosts'     => array( $host ),
				'cookies'   => self::bypass_cookies( $s ),
				'exclude'   => self::bypass_urls( $s ),
				'ignore_qs' => array_map( 'strtolower', $s['ignore_qs'] ),
				'charset'   => get_option( 'blog_charset', 'UTF-8' ),
			);
		}
		$php = "<?php\n// Written by NodeThemes Cache AI. Do not edit: change the settings in WordPress instead.\ndefined( 'ABSPATH' ) || exit;\nreturn " . var_export( $all, true ) . ";\n"; // phpcs:ignore WordPress.PHP.DevelopmentFunctions -- builds the config file.
		return NTCA_FS::put( $file, $php );
	}

	/**
	 * This site's host (with port) and path prefix, e.g. ['example.com', '/'] or ['example.com', '/shop2/'].
	 *
	 * @return array
	 */
	public static function site_key() {
		$u    = home_url( '/' );
		$host = strtolower( (string) wp_parse_url( $u, PHP_URL_HOST ) );
		$port = wp_parse_url( $u, PHP_URL_PORT );
		$path = strtolower( (string) wp_parse_url( $u, PHP_URL_PATH ) );
		return array( $host . ( $port ? ':' . $port : '' ), '' === $path ? '/' : trailingslashit( $path ) );
	}

	/**
	 * Config file of a host.
	 *
	 * @param string $host Host with port.
	 * @return string
	 */
	public static function config_file( $host ) {
		return NTCA_CACHE_DIR . '/config-' . ntca_host_dir( $host ) . '.php';
	}

	/**
	 * This site's entry in the config file (to check it is current).
	 *
	 * @return array|false
	 */
	public static function own_config() {
		list( $host, $prefix ) = self::site_key();
		$file = self::config_file( $host );
		$all  = is_file( $file ) ? include $file : false;
		return is_array( $all ) && isset( $all['sites'][ $prefix ] ) ? $all['sites'][ $prefix ] : false;
	}
}
