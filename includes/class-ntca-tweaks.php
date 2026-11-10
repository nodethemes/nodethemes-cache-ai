<?php
/**
 * Heartbeat control and WooCommerce cart fragments.
 *
 * @package NodeThemes_Cache_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Small server-load tweaks.
 */
class NTCA_Tweaks {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'front_scripts' ), 99 );
		add_filter( 'heartbeat_settings', array( __CLASS__, 'heartbeat_settings' ) );
	}

	/**
	 * Front end: Heartbeat and cart fragments.
	 */
	public static function front_scripts() {
		$s = NTCA_Settings::get();
		if ( $s['heartbeat_front'] && ! is_user_logged_in() ) {
			wp_deregister_script( 'heartbeat' );
		}
		if ( 'default' === $s['cart_fragments'] || ! function_exists( 'is_cart' ) ) {
			return;
		}
		if ( is_cart() || is_checkout() ) {
			return;
		}
		$empty = ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty();
		if ( 'off' === $s['cart_fragments'] || ( 'smart' === $s['cart_fragments'] && $empty ) ) {
			wp_dequeue_script( 'wc-cart-fragments' );
		}
	}

	/**
	 * Admin: slower Heartbeat, except in the editor (it saves drafts and post locks).
	 *
	 * @param array $settings Heartbeat settings.
	 * @return array
	 */
	public static function heartbeat_settings( $settings ) {
		$s = NTCA_Settings::get();
		if ( empty( $s['heartbeat_admin'] ) || ! is_admin() ) {
			return $settings;
		}
		global $pagenow;
		if ( in_array( $pagenow, array( 'post.php', 'post-new.php' ), true ) ) {
			return $settings;
		}
		$settings['interval'] = (int) $s['heartbeat_admin'];
		return $settings;
	}
}
