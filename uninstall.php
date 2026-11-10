<?php
/**
 * Uninstall: remove settings, history, the cache folder, and our drop-in and wp-config line if they are still there.
 *
 * @package NodeThemes_Cache_AI
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( array( 'ntca_settings', 'ntca_history', 'ntca_last_test' ) as $ntca_opt ) {
	delete_option( $ntca_opt );
}
wp_clear_scheduled_hook( 'ntca_cleanup' );

if ( ! defined( 'NTCA_CACHE_DIR' ) ) {
	define( 'NTCA_CACHE_DIR', WP_CONTENT_DIR . '/cache/nodethemes-cache-ai' );
}
require_once __DIR__ . '/includes/class-ntca-fs.php';
require_once __DIR__ . '/includes/class-ntca-cache.php';
NTCA_Cache::disable_fast_mode();
NTCA_FS::rmdir( NTCA_CACHE_DIR );
