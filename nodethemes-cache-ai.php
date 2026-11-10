<?php
/**
 * Plugin Name:       NodeThemes Cache AI
 * Description:       Page cache, HTML/CSS minify, script defer and lazy loading for WordPress and WooCommerce, with a safe preview test in your browser and one-click rollback, so speed changes never break your live site.
 * Version:           1.1.3
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            NodeThemes
 * Author URI:        https://nodethemes.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:        https://nodethemes.com/nodethemes-cache-ai
 * Text Domain:       nodethemes-cache-ai
 *
 * @package NodeThemes_Cache_AI
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'NTCA_VERSION' ) ) {
	return;
}

define( 'NTCA_VERSION', '1.1.3' );
define( 'NTCA_FILE', __FILE__ );
define( 'NTCA_DIR', plugin_dir_path( __FILE__ ) );
define( 'NTCA_URL', plugin_dir_url( __FILE__ ) );
define( 'NTCA_BASENAME', plugin_basename( __FILE__ ) );
if ( ! defined( 'NTCA_CACHE_DIR' ) ) {
	define( 'NTCA_CACHE_DIR', WP_CONTENT_DIR . '/cache/nodethemes-cache-ai' );
}
if ( ! defined( 'NTCA_PRO_URL' ) ) {
	define( 'NTCA_PRO_URL', 'https://nodethemes.com/product/nodethemes-cache-ai-pro/' );
}

// Serve a cached page right away when the advanced-cache.php drop-in is not installed.
require_once NTCA_DIR . 'includes/serve.php';
if ( ! defined( 'NTCA_SERVED' ) && ! is_admin() && ! wp_doing_ajax() && ! wp_doing_cron() ) {
	ntca_serve();
}

require_once NTCA_DIR . 'includes/class-ntca-fs.php';
require_once NTCA_DIR . 'includes/class-ntca-settings.php';
require_once NTCA_DIR . 'includes/class-ntca-optimizer.php';
require_once NTCA_DIR . 'includes/class-ntca-cache.php';
require_once NTCA_DIR . 'includes/class-ntca-preview.php';
require_once NTCA_DIR . 'includes/class-ntca-fonts.php';
require_once NTCA_DIR . 'includes/class-ntca-db.php';
require_once NTCA_DIR . 'includes/class-ntca-tweaks.php';
require_once NTCA_DIR . 'includes/class-ntca-admin.php';
require_once NTCA_DIR . 'includes/class-ntca-updater.php';
NTCA_Free_Updater::boot( NTCA_FILE, NTCA_VERSION, NTCA_PRO_URL );
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once NTCA_DIR . 'includes/class-ntca-cli.php';
	WP_CLI::add_command( 'ntca', 'NTCA_CLI' );
}

/**
 * Is the Pro add-on installed (active or not)?
 *
 * @return bool
 */
function ntca_pro_installed() {
	return (bool) apply_filters( 'ntca_pro_installed', false );
}

/**
 * Are the Pro features unlocked (add-on active with a valid license)?
 *
 * @return bool
 */
function ntca_is_pro() {
	return (bool) apply_filters( 'ntca_is_pro', false );
}

/**
 * Where a locked PRO button should go.
 *
 * @return string
 */
function ntca_upgrade_url() {
	if ( ntca_pro_installed() ) {
		return admin_url( 'admin.php?page=ntca-license' );
	}
	return NTCA_PRO_URL;
}

register_activation_hook( __FILE__, array( 'NTCA_Cache', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'NTCA_Cache', 'deactivate' ) );

add_action(
	'plugins_loaded',
	function () {
		NTCA_Cache::init();
		NTCA_Preview::init();
		NTCA_Fonts::init();
		NTCA_Tweaks::init();
		NTCA_Admin::init();
		do_action( 'ntca_loaded' );
	},
	5
);
