<?php
/**
 * Preview test: the admin's browser opens pages with test settings that only
 * the admin sees. Visitors never get untested settings from here.
 *
 * @package NodeThemes_Cache_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Preview mode.
 */
class NTCA_Preview {

	/**
	 * Settings for this preview request (false = not a preview).
	 *
	 * @var array|false|null
	 */
	private static $current = null;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'setup' ), 0 );
	}

	/**
	 * Hide the admin bar inside previews so both versions of the page look the same.
	 */
	public static function setup() {
		if ( self::current() ) {
			add_filter( 'show_admin_bar', '__return_false', 99 );
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true ); // Tell other caches not to store previews.
			}
			nocache_headers();
		}
	}

	/**
	 * Store test settings for the current admin and return the preview ID.
	 *
	 * @param array $settings Settings to test.
	 * @return string
	 */
	public static function create( $settings ) {
		$id = strtolower( wp_generate_password( 16, false ) );
		set_transient(
			'ntca_pv_' . $id,
			array(
				'user'     => get_current_user_id(),
				'settings' => NTCA_Settings::normalize( $settings ),
			),
			HOUR_IN_SECONDS
		);
		return $id;
	}

	/**
	 * Settings of the current preview, or false.
	 *
	 * @return array|false
	 */
	public static function current() {
		if ( null !== self::$current ) {
			return self::$current;
		}
		if ( empty( $_GET['ntca_preview'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only; the ID is a random token tied to the user below.
			self::$current = false;
			return false;
		}
		if ( ! did_action( 'init' ) ) {
			return false; // The user is not known yet: ask again later.
		}
		self::$current = false;
		$id = preg_replace( '/[^a-z0-9]/', '', strtolower( sanitize_text_field( wp_unslash( $_GET['ntca_preview'] ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $id || ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		$t = get_transient( 'ntca_pv_' . $id );
		if ( ! is_array( $t ) || (int) $t['user'] !== get_current_user_id() ) {
			return false;
		}
		self::$current = $t['settings'];
		return self::$current;
	}

	/**
	 * Add the measuring scripts: an error catcher at the top of the head and the probe at the end.
	 *
	 * @param string $html Page HTML.
	 * @return string
	 */
	public static function inject( $html ) {
		$catch = '<script data-no-defer="1" id="ntca-catch">' . self::catcher_js() . '</script>';
		$html  = preg_replace( '/<head\b[^>]*>/i', '$0' . $catch, $html, 1 );
		$probe = '<script data-no-defer="1" id="ntca-probe" src="' . esc_url( NTCA_URL . 'assets/probe.js?ver=' . NTCA_VERSION ) . '"></script>';
		$probe = (string) apply_filters( 'ntca_preview_scripts', '' ) . $probe;
		$pos   = strripos( $html, '</body>' );
		if ( false === $pos ) {
			return $html . $probe;
		}
		return substr( $html, 0, $pos ) . $probe . substr( $html, $pos );
	}

	/**
	 * Small inline script that records JavaScript errors and files that fail to load.
	 *
	 * @return string
	 */
	private static function catcher_js() {
		return 'window.__ntca={e:[],r:[]};(function(){var N=window.__ntca;function p(a,x){if(a.length<40){a.push(x)}}'
			. 'addEventListener("error",function(ev){var t=ev.target;if(t&&t!==window&&(t.src||t.href)){p(N.r,{tag:(t.tagName||"").toLowerCase(),url:String(t.src||t.href).slice(0,300)});return}'
			. 'p(N.e,{m:String(ev.message||"Error").slice(0,300),f:String(ev.filename||"").slice(0,300),l:ev.lineno||0})},true);'
			. 'addEventListener("unhandledrejection",function(ev){var r=ev.reason;p(N.e,{m:String(r&&r.message?r.message:r).slice(0,300),f:"",l:0,promise:1})});'
			. 'var ce=console.error;console.error=function(){try{p(N.e,{m:Array.prototype.map.call(arguments,function(a){return String(a&&a.message?a.message:a)}).join(" ").slice(0,300),f:"",l:0,console:1})}catch(x){}return ce.apply(console,arguments)}})();';
	}
}
