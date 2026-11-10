<?php
/**
 * WP-CLI commands: wp ntca ...
 *
 * @package NodeThemes_Cache_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manage NodeThemes Cache AI from the command line.
 */
class NTCA_CLI {

	/**
	 * Show the cache status.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ntca status
	 *
	 * @param array $args  Arguments.
	 * @param array $assoc Options.
	 */
	public function status( $args, $assoc ) {
		$s     = NTCA_Settings::get();
		$stats = NTCA_Cache::stats();
		$on    = array_keys( array_filter( $s['opts'] ) );
		WP_CLI::line( 'Page cache:    ' . ( $s['cache'] ? 'on' : 'off' ) );
		WP_CLI::line( 'Mode:          ' . ( NTCA_Cache::fast_mode() ? 'fast (advanced-cache.php)' : 'normal' ) );
		WP_CLI::line( 'Cached pages:  ' . $stats[0] . ' (' . size_format( $stats[1] ) . ')' );
		WP_CLI::line( 'Lifetime:      ' . $s['lifetime'] . ' hours' );
		WP_CLI::line( 'Optimizations: ' . ( $on ? implode( ', ', $on ) : 'none' ) );
		WP_CLI::line( 'Pro:           ' . ( ntca_is_pro() ? 'active' : ( ntca_pro_installed() ? 'installed, license not active' : 'not installed' ) ) );
	}

	/**
	 * Clear the cache: everything, or one address.
	 *
	 * ## OPTIONS
	 *
	 * [--url=<url>]
	 * : Only clear this page.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ntca purge
	 *     wp ntca purge --url=https://example.com/shop/
	 *
	 * @param array $args  Arguments.
	 * @param array $assoc Options.
	 */
	public function purge( $args, $assoc ) {
		if ( ! empty( $assoc['url'] ) ) {
			NTCA_Cache::purge_url( esc_url_raw( $assoc['url'] ) );
			WP_CLI::success( 'Cleared ' . $assoc['url'] );
			return;
		}
		NTCA_Cache::purge_all();
		WP_CLI::success( 'All cached pages were cleared.' );
	}

	/**
	 * Turn fast mode (advanced-cache.php + WP_CACHE) on or off.
	 *
	 * ## OPTIONS
	 *
	 * <state>
	 * : on or off
	 * ---
	 * options:
	 *   - on
	 *   - off
	 * ---
	 *
	 * @subcommand fast-mode
	 * @param array $args  Arguments.
	 * @param array $assoc Options.
	 */
	public function fast_mode( $args, $assoc ) {
		if ( 'on' === $args[0] ) {
			$r = NTCA_Cache::enable_fast_mode();
			if ( is_wp_error( $r ) ) {
				WP_CLI::error( $r->get_error_message() );
			}
			WP_CLI::success( 'Fast mode is on.' );
			return;
		}
		NTCA_Cache::disable_fast_mode();
		WP_CLI::success( 'Fast mode is off.' );
	}

	/**
	 * Switch off every HTML optimization (the page cache stays on). Use it if the site looks wrong.
	 *
	 * @subcommand all-off
	 * @param array $args  Arguments.
	 * @param array $assoc Options.
	 */
	public function all_off( $args, $assoc ) {
		$s = NTCA_Settings::get();
		foreach ( $s['opts'] as $k => $v ) {
			$s['opts'][ $k ] = 0;
		}
		NTCA_Settings::apply( $s, 'Switched off all optimizations (WP-CLI)' );
		WP_CLI::success( 'All optimizations are off. The page cache still works.' );
	}

	/**
	 * List the saved settings history, or restore one entry.
	 *
	 * ## OPTIONS
	 *
	 * [<number>]
	 * : The number shown in the list. Leave it out to list the history.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ntca history
	 *     wp ntca history 0
	 *
	 * @param array $args  Arguments.
	 * @param array $assoc Options.
	 */
	public function history( $args, $assoc ) {
		$h = NTCA_Settings::history();
		if ( isset( $args[0] ) ) {
			if ( ! NTCA_Settings::restore( absint( $args[0] ) ) ) {
				WP_CLI::error( 'No history entry with that number.' );
			}
			WP_CLI::success( 'Restored. The cache was cleared.' );
			return;
		}
		$rows = array();
		foreach ( $h as $i => $row ) {
			$rows[] = array(
				'number'  => $i,
				'changed' => wp_date( 'Y-m-d H:i', (int) $row['time'] ),
				'what'    => $row['note'],
				'before'  => implode( ',', array_keys( array_filter( (array) $row['settings']['opts'] ) ) ),
			);
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'number', 'changed', 'what', 'before' ) );
	}

	/**
	 * Clean the database.
	 *
	 * ## OPTIONS
	 *
	 * [--items=<items>]
	 * : Comma-separated: revisions, auto_drafts, trash_posts, spam_comments, trash_comments, transients, wc_sessions. Default: all.
	 *
	 * [--dry-run]
	 * : Only count.
	 *
	 * @subcommand db-clean
	 * @param array $args  Arguments.
	 * @param array $assoc Options.
	 */
	public function db_clean( $args, $assoc ) {
		$items = ! empty( $assoc['items'] ) ? array_map( 'trim', explode( ',', $assoc['items'] ) ) : array_keys( NTCA_DB::items() );
		foreach ( $items as $k ) {
			if ( ! isset( NTCA_DB::items()[ $k ] ) ) {
				WP_CLI::warning( 'Unknown item: ' . $k );
				continue;
			}
			$n = NTCA_DB::count( $k );
			if ( ! empty( $assoc['dry-run'] ) ) {
				WP_CLI::line( $k . ': ' . $n );
				continue;
			}
			WP_CLI::line( $k . ': removed ' . NTCA_DB::clean( $k ) );
		}
		WP_CLI::success( 'Done.' );
	}
}
