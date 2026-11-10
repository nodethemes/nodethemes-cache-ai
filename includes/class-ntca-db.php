<?php
/**
 * Database cleanup.
 *
 * @package NodeThemes_Cache_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Database cleanup. Everything goes through WordPress functions where one exists.
 */
class NTCA_DB {

	const BATCH = 500;

	/**
	 * Items that can be cleaned.
	 *
	 * @return array key => [label, help]
	 */
	public static function items() {
		$i = array(
			'revisions'      => array( __( 'Post revisions', 'nodethemes-cache-ai' ), __( 'Older copies saved each time a post or page was updated. The current content is not touched.', 'nodethemes-cache-ai' ) ),
			'auto_drafts'    => array( __( 'Auto-drafts', 'nodethemes-cache-ai' ), __( 'Empty drafts WordPress creates when you open "Add New" (only those older than 7 days).', 'nodethemes-cache-ai' ) ),
			'trash_posts'    => array( __( 'Posts in the trash', 'nodethemes-cache-ai' ), __( 'Posts, pages and products in the trash for more than 7 days. They are deleted for good.', 'nodethemes-cache-ai' ) ),
			'spam_comments'  => array( __( 'Spam comments', 'nodethemes-cache-ai' ), __( 'Comments marked as spam.', 'nodethemes-cache-ai' ) ),
			'trash_comments' => array( __( 'Comments in the trash', 'nodethemes-cache-ai' ), __( 'Comments you moved to the trash.', 'nodethemes-cache-ai' ) ),
			'transients'     => array( __( 'Expired transients', 'nodethemes-cache-ai' ), __( 'Temporary data that plugins stored and that has already expired.', 'nodethemes-cache-ai' ) ),
		);
		if ( class_exists( 'WooCommerce' ) ) {
			$i['wc_sessions'] = array( __( 'Expired WooCommerce sessions', 'nodethemes-cache-ai' ), __( 'Carts of visitors whose session has expired. Active carts are kept.', 'nodethemes-cache-ai' ) );
		}
		return $i;
	}

	/**
	 * How many rows an item would remove.
	 *
	 * @param string $k Item.
	 * @return int
	 */
	public static function count( $k ) {
		global $wpdb;
		$week = gmdate( 'Y-m-d H:i:s', time() - WEEK_IN_SECONDS );
		switch ( $k ) {
			case 'revisions':
				return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			case 'auto_drafts':
				return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft' AND post_modified_gmt < %s", $week ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			case 'trash_posts':
				return count( self::trash_ids() );
			case 'spam_comments':
				return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			case 'trash_comments':
				return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'trash'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			case 'transients':
				return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d", $wpdb->esc_like( '_transient_timeout_' ) . '%', time() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			case 'wc_sessions':
				$t = $wpdb->prefix . 'woocommerce_sessions';
				if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					return 0;
				}
				return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE session_expiry < %d", time() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WooCommerce's own table name.
		}
		return 0;
	}

	/**
	 * IDs of posts in the trash for more than 7 days.
	 *
	 * @return array
	 */
	private static function trash_ids() {
		global $wpdb;
		$limit = time() - WEEK_IN_SECONDS;
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_trash_meta_time' WHERE p.post_status = 'trash' AND CAST(m.meta_value AS UNSIGNED) < %d LIMIT %d", $limit, self::BATCH ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Remove up to BATCH rows of an item.
	 *
	 * @param string $k Item.
	 * @return int Removed.
	 */
	public static function clean( $k ) {
		global $wpdb;
		$n    = 0;
		$week = gmdate( 'Y-m-d H:i:s', time() - WEEK_IN_SECONDS );
		switch ( $k ) {
			case 'revisions':
				$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision' LIMIT %d", self::BATCH ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				foreach ( $ids as $id ) {
					$n += wp_delete_post_revision( (int) $id ) ? 1 : 0;
				}
				break;
			case 'auto_drafts':
				$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'auto-draft' AND post_modified_gmt < %s LIMIT %d", $week, self::BATCH ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				foreach ( $ids as $id ) {
					$n += wp_delete_post( (int) $id, true ) ? 1 : 0;
				}
				break;
			case 'trash_posts':
				foreach ( self::trash_ids() as $id ) {
					$n += wp_delete_post( $id, true ) ? 1 : 0;
				}
				break;
			case 'spam_comments':
			case 'trash_comments':
				$status = 'spam_comments' === $k ? 'spam' : 'trash';
				$ids    = $wpdb->get_col( $wpdb->prepare( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved = %s LIMIT %d", $status, self::BATCH ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				foreach ( $ids as $id ) {
					$n += wp_delete_comment( (int) $id, true ) ? 1 : 0;
				}
				break;
			case 'transients':
				$n = self::count( 'transients' );
				delete_expired_transients( true );
				break;
			case 'wc_sessions':
				$n = self::count( 'wc_sessions' );
				if ( class_exists( 'WC_Session_Handler' ) ) {
					( new WC_Session_Handler() )->cleanup_sessions();
				}
				$n -= self::count( 'wc_sessions' );
				break;
		}
		return $n;
	}
}
