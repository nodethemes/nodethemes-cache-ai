<?php
/**
 * Admin screens, AJAX for the preview test, admin-bar menu.
 *
 * @package NodeThemes_Cache_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin.
 */
class NTCA_Admin {

	const SLUG = 'ntca';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_config' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 90 );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_filter( 'plugin_action_links_' . NTCA_BASENAME, array( __CLASS__, 'action_links' ) );
		foreach ( array( 'save', 'purge', 'restore', 'fast_on', 'fast_off', 'all_off', 'db_clean', 'tweaks' ) as $a ) {
			add_action( 'admin_post_ntca_' . $a, array( __CLASS__, 'post_' . $a ) );
		}
		add_action( 'wp_ajax_ntca_preview', array( __CLASS__, 'ajax_preview' ) );
		add_action( 'wp_ajax_ntca_test_done', array( __CLASS__, 'ajax_test_done' ) );
		add_action( 'wp_ajax_ntca_apply', array( __CLASS__, 'ajax_apply' ) );
	}

	/**
	 * Rebuild the config file after an update or when it is missing.
	 */
	public static function maybe_config() {
		$cfg = NTCA_Settings::own_config();
		if ( ! is_array( $cfg ) || empty( $cfg['version'] ) || NTCA_VERSION !== $cfg['version'] ) {
			NTCA_Settings::write_config();
		}
	}

	/**
	 * Menu.
	 */
	public static function menu() {
		add_menu_page( __( 'NodeThemes Cache AI', 'nodethemes-cache-ai' ), __( 'Cache AI', 'nodethemes-cache-ai' ), 'manage_options', self::SLUG, array( __CLASS__, 'page' ), 'dashicons-performance', 81 );
		add_submenu_page( self::SLUG, __( 'NodeThemes Cache AI', 'nodethemes-cache-ai' ), __( 'Dashboard', 'nodethemes-cache-ai' ), 'manage_options', self::SLUG, array( __CLASS__, 'page' ) );
	}

	/**
	 * Settings link on the Plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Settings', 'nodethemes-cache-ai' ) . '</a>' );
		if ( ! ntca_pro_installed() ) {
			$links[] = '<a href="' . esc_url( NTCA_PRO_URL ) . '" target="_blank" rel="noopener" style="font-weight:600">' . esc_html__( 'Get Pro', 'nodethemes-cache-ai' ) . '</a>';
		}
		return $links;
	}

	/**
	 * Pages used for the preview test.
	 *
	 * @return array
	 */
	public static function test_urls() {
		$urls = array( home_url( '/' ) );
		$skip = array();
		if ( function_exists( 'wc_get_page_id' ) ) {
			foreach ( array( 'cart', 'checkout', 'myaccount' ) as $p ) {
				$skip[] = wc_get_page_id( $p );
			}
		}
		$skip[] = (int) get_option( 'page_on_front' );
		$post   = get_posts( array( 'numberposts' => 1, 'post_status' => 'publish', 'has_password' => false ) );
		if ( $post ) {
			$urls[] = get_permalink( $post[0] );
		}
		$page = get_posts( array( 'post_type' => 'page', 'numberposts' => 1, 'post_status' => 'publish', 'has_password' => false, 'exclude' => array_filter( $skip ), 'orderby' => 'menu_order date', 'order' => 'ASC' ) );
		if ( $page ) {
			$urls[] = get_permalink( $page[0] );
		}
		if ( function_exists( 'wc_get_page_id' ) ) {
			if ( wc_get_page_id( 'shop' ) > 0 ) {
				$urls[] = get_permalink( wc_get_page_id( 'shop' ) );
			}
			$prod = get_posts( array( 'post_type' => 'product', 'numberposts' => 1, 'post_status' => 'publish' ) );
			if ( $prod ) {
				$urls[] = get_permalink( $prod[0] );
			}
		}
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		foreach ( NTCA_Settings::get()['test_urls'] as $u ) {
			$u = 0 === strpos( $u, '/' ) ? home_url( $u ) : $u;
			if ( wp_parse_url( $u, PHP_URL_HOST ) === $host ) {
				$urls[] = esc_url_raw( $u );
			}
		}
		$urls = array_values( array_unique( array_filter( $urls ) ) );
		return array_slice( (array) apply_filters( 'ntca_test_urls', $urls ), 0, 8 );
	}

	/**
	 * Scripts and styles.
	 *
	 * @param string $hook Screen.
	 */
	public static function assets( $hook ) {
		wp_register_style( 'ntca-admin', NTCA_URL . 'assets/admin.css', array(), NTCA_VERSION );
		$page = 'toplevel_page_' . self::SLUG === $hook;
		if ( ! apply_filters( 'ntca_load_tester', $page, $hook ) ) {
			return;
		}
		wp_register_script( 'ntca-tester', NTCA_URL . 'assets/tester.js', array( 'jquery' ), NTCA_VERSION, true );
		wp_localize_script( 'ntca-tester', 'NTCA', self::js_data() );
		if ( ! $page ) {
			return;
		}
		wp_enqueue_style( 'ntca-admin' );
		wp_enqueue_script( 'ntca-admin', NTCA_URL . 'assets/admin.js', array( 'ntca-tester' ), NTCA_VERSION, true );
	}

	/**
	 * Data for the scripts.
	 *
	 * @return array
	 */
	public static function js_data() {
		$s = NTCA_Settings::get();
		return array(
			'ajax'     => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'ntca_admin' ),
			'urls'     => self::test_urls(),
			'live'     => $s,
			'pro'      => ntca_is_pro() ? 1 : 0,
			'proUrl'   => ntca_upgrade_url(),
			'i18n'     => array(
				'failed'      => __( 'Something went wrong. Reload the page and try again.', 'nodethemes-cache-ai' ),
				'testing'     => __( 'Preview test', 'nodethemes-cache-ai' ),
				'timeout'     => __( 'The page did not finish loading with these settings (35 seconds).', 'nodethemes-cache-ai' ),
				'jsError'     => __( 'New JavaScript error:', 'nodethemes-cache-ai' ),
				'fileFailed'  => __( 'A file failed to load:', 'nodethemes-cache-ai' ),
				'imageBroken' => __( 'An image did not load:', 'nodethemes-cache-ai' ),
				/* translators: %d: number of elements */
				'layout'      => __( '%d parts of the page changed size or disappeared.', 'nodethemes-cache-ai' ),
				/* translators: %1: old height, %2: new height (pixels) */
				'height'      => __( 'The page height changed from %1 px to %2 px.', 'nodethemes-cache-ai' ),
				/* translators: %1: page number, %2: total */
				'page'        => __( 'Testing page %1 of %2:', 'nodethemes-cache-ai' ),
				'mobile'      => __( 'phone width', 'nodethemes-cache-ai' ),
				'running'     => __( 'Testing in your browser. Keep this tab open; it takes about 10 seconds per page.', 'nodethemes-cache-ai' ),
				'passed'      => __( 'All test pages look and work the same with the new settings. You can apply them to your live site.', 'nodethemes-cache-ai' ),
				'failedTest'  => __( 'Some pages changed with the new settings. They were NOT applied. Change the settings and test again.', 'nodethemes-cache-ai' ),
				'noChange'    => __( 'These are already your live settings.', 'nodethemes-cache-ai' ),
				'applied'     => __( 'Applied. Your cache was cleared, and the old settings are saved in History.', 'nodethemes-cache-ai' ),
				'confirmForce' => __( 'The test found problems. Apply these settings to your live site anyway? You can undo it in History.', 'nodethemes-cache-ai' ),
				'ok'          => __( 'No changes', 'nodethemes-cache-ai' ),
				'skipped'     => __( 'Not checked: this page took longer than 35 seconds to load even without the changes. Check it yourself after applying.', 'nodethemes-cache-ai' ),
				'tip_defer'   => __( 'Tip: when "Defer JavaScript files" causes an error, add the file named in the error (or jquery) to "Do not defer" and test again.', 'nodethemes-cache-ai' ),
			),
		);
	}

	/**
	 * Check the request: capability and nonce.
	 *
	 * @param string $action Nonce action.
	 */
	private static function guard_post( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'nodethemes-cache-ai' ), 403 );
		}
		check_admin_referer( $action );
	}

	/**
	 * Back to a tab with a message.
	 *
	 * @param string $tab Tab.
	 * @param string $msg Message code.
	 */
	private static function back( $tab, $msg ) {
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'tab' => $tab, 'ntca_msg' => $msg ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Save cache settings (these do not change the page HTML, so no test is needed).
	 */
	public static function post_save() {
		self::guard_post( 'ntca_save' );
		$s   = NTCA_Settings::get();
		$tab = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : 'dashboard';
		if ( 'exclusions' === $tab ) {
			foreach ( array( 'exclude_urls', 'exclude_cookies', 'ignore_qs', 'test_urls' ) as $k ) {
				$s[ $k ] = isset( $_POST[ $k ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $k ] ) ) : '';
			}
		} else {
			$s['cache']    = empty( $_POST['cache'] ) ? 0 : 1;
			$s['mobile']   = empty( $_POST['mobile'] ) ? 0 : 1;
			$s['lifetime'] = isset( $_POST['lifetime'] ) ? absint( $_POST['lifetime'] ) : 10;
		}
		NTCA_Settings::apply( $s, 'exclusions' === $tab ? __( 'Changed the cache exclusions', 'nodethemes-cache-ai' ) : __( 'Changed the cache settings', 'nodethemes-cache-ai' ) );
		do_action( 'ntca_save_settings', $tab );
		self::back( $tab, 'saved' );
	}

	/**
	 * Clear the cache (from the dashboard or the admin bar).
	 */
	public static function post_purge() {
		self::guard_post( 'ntca_purge' );
		$url = isset( $_GET['url'] ) ? esc_url_raw( wp_unslash( $_GET['url'] ) ) : '';
		if ( $url && wp_parse_url( $url, PHP_URL_HOST ) === wp_parse_url( home_url(), PHP_URL_HOST ) ) {
			NTCA_Cache::purge_url( $url );
			wp_safe_redirect( $url );
			exit;
		}
		NTCA_Cache::purge_all();
		$ref = wp_get_referer();
		if ( $ref && false === strpos( $ref, 'page=' . self::SLUG ) ) {
			wp_safe_redirect( $ref );
			exit;
		}
		self::back( 'dashboard', 'purged' );
	}

	/**
	 * Restore old settings from the history.
	 */
	public static function post_restore() {
		self::guard_post( 'ntca_restore' );
		$i = isset( $_POST['i'] ) ? absint( $_POST['i'] ) : -1;
		self::back( 'history', NTCA_Settings::restore( $i ) ? 'restored' : 'error' );
	}

	/**
	 * Emergency: switch every optimization off (the page cache stays on).
	 */
	public static function post_all_off() {
		self::guard_post( 'ntca_all_off' );
		$s = NTCA_Settings::get();
		foreach ( $s['opts'] as $k => $v ) {
			$s['opts'][ $k ] = 0;
		}
		NTCA_Settings::apply( $s, __( 'Switched off all optimizations', 'nodethemes-cache-ai' ) );
		self::back( 'history', 'alloff' );
	}

	/**
	 * Install the drop-in.
	 */
	public static function post_fast_on() {
		self::guard_post( 'ntca_fast_on' );
		if ( is_multisite() && ! is_super_admin() ) {
			wp_die( esc_html__( 'On a multisite network only a network administrator can change fast mode.', 'nodethemes-cache-ai' ), 403 );
		}
		$r = NTCA_Cache::enable_fast_mode();
		if ( is_wp_error( $r ) ) {
			set_transient( 'ntca_err_' . get_current_user_id(), $r->get_error_message(), 120 );
			self::back( 'dashboard', 'error' );
		}
		self::back( 'dashboard', 'fast' );
	}

	/**
	 * Remove the drop-in.
	 */
	public static function post_fast_off() {
		self::guard_post( 'ntca_fast_off' );
		if ( is_multisite() && ! is_super_admin() ) {
			wp_die( esc_html__( 'On a multisite network only a network administrator can change fast mode.', 'nodethemes-cache-ai' ), 403 );
		}
		NTCA_Cache::disable_fast_mode();
		self::back( 'dashboard', 'normal' );
	}

	/**
	 * Database cleanup.
	 */
	public static function post_db_clean() {
		self::guard_post( 'ntca_db_clean' );
		$item  = isset( $_POST['item'] ) ? sanitize_key( wp_unslash( $_POST['item'] ) ) : '';
		$items = 'all' === $item ? array_keys( NTCA_DB::items() ) : array( $item );
		foreach ( $items as $k ) {
			if ( isset( NTCA_DB::items()[ $k ] ) ) {
				NTCA_DB::clean( $k );
			}
		}
		self::back( 'tools', 'cleaned' );
	}

	/**
	 * Heartbeat and cart fragments.
	 */
	public static function post_tweaks() {
		self::guard_post( 'ntca_tweaks' );
		$s                    = NTCA_Settings::get();
		$s['heartbeat_front'] = empty( $_POST['heartbeat_front'] ) ? 0 : 1;
		$s['heartbeat_admin'] = isset( $_POST['heartbeat_admin'] ) ? absint( $_POST['heartbeat_admin'] ) : 0;
		$s['cart_fragments']  = isset( $_POST['cart_fragments'] ) ? sanitize_key( wp_unslash( $_POST['cart_fragments'] ) ) : 'default';
		NTCA_Settings::apply( $s, __( 'Changed Heartbeat or cart fragments', 'nodethemes-cache-ai' ) );
		self::back( 'tools', 'saved' );
	}

	/**
	 * AJAX check.
	 */
	private static function guard_ajax() {
		check_ajax_referer( 'ntca_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'nodethemes-cache-ai' ) ), 403 );
		}
	}

	/**
	 * Settings sent by the test screen, merged over the live settings (only the optimization fields).
	 *
	 * @return array
	 */
	private static function posted_settings() {
		check_ajax_referer( 'ntca_admin', 'nonce' );
		$raw  = isset( $_POST['settings'] ) ? json_decode( sanitize_textarea_field( wp_unslash( $_POST['settings'] ) ), true ) : array();
		$raw  = is_array( $raw ) ? $raw : array();
		$live = NTCA_Settings::get();
		foreach ( array( 'opts', 'defer_exclude', 'lazy_skip' ) as $k ) {
			if ( isset( $raw[ $k ] ) ) {
				$live[ $k ] = $raw[ $k ];
			}
		}
		return NTCA_Settings::normalize( $live );
	}

	/**
	 * Create a preview for the test.
	 */
	public static function ajax_preview() {
		self::guard_ajax();
		$s = self::posted_settings();
		wp_send_json_success( array( 'id' => NTCA_Preview::create( $s ), 'hash' => NTCA_Settings::opt_hash( $s ) ) );
	}

	/**
	 * Remember the result of a test.
	 */
	public static function ajax_test_done() {
		self::guard_ajax();
		$s    = self::posted_settings();
		$pass = ! empty( $_POST['pass'] );
		$sum  = isset( $_POST['summary'] ) ? sanitize_textarea_field( wp_unslash( $_POST['summary'] ) ) : '';
		$res  = array(
			'hash'    => NTCA_Settings::opt_hash( $s ),
			'pass'    => $pass ? 1 : 0,
			'time'    => time(),
			'pages'   => isset( $_POST['pages'] ) ? absint( $_POST['pages'] ) : 0,
			'summary' => mb_substr( $sum, 0, 2000 ),
		);
		update_option( 'ntca_last_test', $res, false );
		do_action( 'ntca_test_done', $res, $s );
		wp_send_json_success( $res );
	}

	/**
	 * Apply tested settings to the live site.
	 */
	public static function ajax_apply() {
		self::guard_ajax();
		$s     = self::posted_settings();
		$force = ! empty( $_POST['force'] );
		$last  = get_option( 'ntca_last_test', array() );
		$ok    = is_array( $last ) && ! empty( $last['pass'] ) && NTCA_Settings::opt_hash( $s ) === $last['hash'] && time() - (int) $last['time'] < DAY_IN_SECONDS;
		if ( ! $ok && ! $force ) {
			wp_send_json_error( array( 'message' => __( 'Test these settings first. Only settings that passed the test can be applied without confirming.', 'nodethemes-cache-ai' ) ) );
		}
		$note = $ok ? __( 'Applied optimizations that passed the preview test', 'nodethemes-cache-ai' ) : __( 'Applied optimizations without a passed test', 'nodethemes-cache-ai' );
		NTCA_Settings::apply( $s, $note, $ok ? 'test' : 'force' );
		wp_send_json_success( array( 'live' => NTCA_Settings::get() ) );
	}

	/**
	 * Admin bar: clear cache.
	 *
	 * @param WP_Admin_Bar $bar Bar.
	 */
	public static function admin_bar( $bar ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$bar->add_node(
			array(
				'id'    => 'ntca',
				'title' => '<span class="ab-icon dashicons dashicons-performance" style="top:2px"></span><span class="ab-label">' . esc_html__( 'Cache', 'nodethemes-cache-ai' ) . '</span>',
				'href'  => admin_url( 'admin.php?page=' . self::SLUG ),
			)
		);
		$bar->add_node(
			array(
				'parent' => 'ntca',
				'id'     => 'ntca-purge',
				'title'  => esc_html__( 'Clear all cache', 'nodethemes-cache-ai' ),
				'href'   => wp_nonce_url( admin_url( 'admin-post.php?action=ntca_purge' ), 'ntca_purge' ),
			)
		);
		if ( ! is_admin() ) {
			$here = home_url( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/' );
			$bar->add_node(
				array(
					'parent' => 'ntca',
					'id'     => 'ntca-purge-page',
					'title'  => esc_html__( 'Clear this page', 'nodethemes-cache-ai' ),
					'href'   => wp_nonce_url( add_query_arg( array( 'action' => 'ntca_purge', 'url' => rawurlencode( $here ) ), admin_url( 'admin-post.php' ) ), 'ntca_purge' ),
				)
			);
		}
	}

	/**
	 * Notices: other cache plugins.
	 */
	public static function notices() {
		$screen = get_current_screen();
		if ( ! $screen || ! current_user_can( 'manage_options' ) || ! in_array( $screen->id, array( 'dashboard', 'plugins', 'toplevel_page_' . self::SLUG ), true ) ) {
			return;
		}
		$c = NTCA_Cache::conflicts();
		if ( $c ) {
			/* translators: %s: plugin names */
			echo '<div class="notice notice-warning"><p>' . esc_html( sprintf( __( 'NodeThemes Cache AI: %s is also active. Two cache or optimization plugins can serve old pages or optimize the same file twice. Keep only one of them for page cache and minify.', 'nodethemes-cache-ai' ), implode( ', ', $c ) ) ) . '</p></div>';
		}
	}

	/**
	 * Lock button for a Pro feature.
	 *
	 * @param string $label Text.
	 * @return string
	 */
	public static function lock( $label ) {
		return '<a class="button ntca-lock" href="' . esc_url( ntca_upgrade_url() ) . '" target="_blank" rel="noopener"><span class="dashicons dashicons-lock"></span> ' . esc_html( $label ) . ' <span class="ntca-pro">PRO</span></a>';
	}

	/**
	 * Main page.
	 */
	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tabs = array(
			'dashboard'  => __( 'Dashboard', 'nodethemes-cache-ai' ),
			'speed'      => __( 'Speed & safe test', 'nodethemes-cache-ai' ),
			'exclusions' => __( 'Exclusions', 'nodethemes-cache-ai' ),
			'tools'      => __( 'Database & tweaks', 'nodethemes-cache-ai' ),
			'history'    => __( 'History & rollback', 'nodethemes-cache-ai' ),
			'pro'        => __( 'Pro', 'nodethemes-cache-ai' ),
		);
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tab switch only.
		$tab  = isset( $tabs[ $tab ] ) ? $tab : 'dashboard';
		$msg  = isset( $_GET['ntca_msg'] ) ? sanitize_key( wp_unslash( $_GET['ntca_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- message after redirect only.
		?>
		<div class="wrap ntca-wrap">
			<div class="ntca-head">
				<div>
					<h1><?php esc_html_e( 'NodeThemes Cache AI', 'nodethemes-cache-ai' ); ?> <span class="ntca-ver"><?php echo esc_html( NTCA_VERSION ); ?></span><?php echo ntca_is_pro() ? ' <span class="ntca-pro">PRO</span>' : ''; ?></h1>
					<p><?php esc_html_e( 'Faster pages, tested in your own browser before your visitors see them.', 'nodethemes-cache-ai' ); ?></p>
				</div>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ntca_purge' ), 'ntca_purge' ) ); ?>"><?php esc_html_e( 'Clear all cache', 'nodethemes-cache-ai' ); ?></a>
			</div>
			<hr class="wp-header-end">
			<?php self::message( $msg ); ?>
			<nav class="nav-tab-wrapper ntca-tabs">
				<?php foreach ( $tabs as $k => $label ) : ?>
					<a class="nav-tab<?php echo $k === $tab ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => self::SLUG, 'tab' => $k ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<?php
			switch ( $tab ) {
				case 'speed':
					self::tab_speed();
					break;
				case 'exclusions':
					self::tab_exclusions();
					break;
				case 'history':
					self::tab_history();
					break;
				case 'tools':
					self::tab_tools();
					break;
				case 'pro':
					self::tab_pro();
					break;
				default:
					self::tab_dashboard();
			}
			?>
		</div>
		<?php
	}

	/**
	 * Message after a redirect.
	 *
	 * @param string $msg Code.
	 */
	private static function message( $msg ) {
		$m = array(
			'saved'    => array( 'success', __( 'Settings saved. The cache was cleared.', 'nodethemes-cache-ai' ) ),
			'purged'   => array( 'success', __( 'All cached pages were cleared.', 'nodethemes-cache-ai' ) ),
			'restored' => array( 'success', __( 'The old settings are live again. The cache was cleared.', 'nodethemes-cache-ai' ) ),
			'alloff'   => array( 'success', __( 'All optimizations are off. The page cache still works.', 'nodethemes-cache-ai' ) ),
			'fast'     => array( 'success', __( 'Fast mode is on: cached pages are now sent before WordPress loads.', 'nodethemes-cache-ai' ) ),
			'normal'   => array( 'success', __( 'Fast mode is off. The page cache still works in normal mode.', 'nodethemes-cache-ai' ) ),
			'cleaned'  => array( 'success', __( 'Cleaned. Click again if there is more left.', 'nodethemes-cache-ai' ) ),
		);
		if ( 'error' === $msg ) {
			$e = get_transient( 'ntca_err_' . get_current_user_id() );
			delete_transient( 'ntca_err_' . get_current_user_id() );
			$m['error'] = array( 'error', $e ? $e : __( 'That did not work. Please try again.', 'nodethemes-cache-ai' ) );
		}
		if ( isset( $m[ $msg ] ) ) {
			echo '<div class="notice notice-' . esc_attr( $m[ $msg ][0] ) . ' is-dismissible"><p>' . esc_html( $m[ $msg ][1] ) . '</p></div>';
		}
	}

	/**
	 * Dashboard tab.
	 */
	private static function tab_dashboard() {
		$s       = NTCA_Settings::get();
		$stats   = NTCA_Cache::stats();
		$drop    = NTCA_Cache::dropin_state();
		$fast    = NTCA_Cache::fast_mode();
		$opts    = NTCA_Settings::optimizations();
		$on      = array_keys( array_filter( $s['opts'] ) );
		$last    = get_option( 'ntca_last_test', array() );
		$probs   = $s['cache'] ? NTCA_Cache::health_problems() : array();
		foreach ( $probs as $reason => $p ) {
			$where = '' !== $p['url'] ? $p['url'] : '/';
			switch ( $reason ) {
				case 'no-close':
					/* translators: %s: page address */
					$msg = sprintf( __( 'Pages are not being cached: your theme does not finish its pages with </html> (seen on %s). A page without its end could be cut off, so it is never saved. Ask your theme author to end footer.php with </body></html> after wp_footer(), or switch to another theme to check.', 'nodethemes-cache-ai' ), $where );
					break;
				case 'cookie':
					/* translators: 1: cookie name, 2: page address */
					$msg = sprintf( __( 'Pages are not being cached: something on your site sets the cookie "%1$s" on every visit (seen on %2$s). A page that sets a cookie can be personal, so it is never saved. Find the plugin that sets it, or ask its author to set the cookie only when needed.', 'nodethemes-cache-ai' ), '' !== $p['d'] ? $p['d'] : '?', $where );
					break;
				case 'donotcache':
					/* translators: %s: page address */
					$msg = sprintf( __( 'Some pages are not cached because another plugin asks for that (DONOTCACHEPAGE, seen on %s). This is normal for forms or personal pages, but if it happens on every page, check which plugin sets it.', 'nodethemes-cache-ai' ), $where );
					break;
				default:
					$msg = __( 'Pages could not be saved: the folder wp-content/cache/nodethemes-cache-ai is not writable. Ask your host to make wp-content writable.', 'nodethemes-cache-ai' );
			}
			echo '<div class="notice notice-' . ( 'donotcache' === $reason ? 'info' : 'error' ) . ' inline ntca-health"><p><strong>' . esc_html__( 'Page cache:', 'nodethemes-cache-ai' ) . '</strong> ' . esc_html( $msg ) . '</p></div>';
		}
		?>
		<div class="ntca-grid">
			<div class="ntca-card">
				<h2><?php esc_html_e( 'Page cache', 'nodethemes-cache-ai' ); ?></h2>
				<p class="ntca-big <?php echo $s['cache'] ? 'ntca-ok' : 'ntca-bad'; ?>"><?php echo $s['cache'] ? esc_html__( 'On', 'nodethemes-cache-ai' ) : esc_html__( 'Off', 'nodethemes-cache-ai' ); ?></p>
				<p>
					<?php
					/* translators: %1$d: number of pages, %2$s: size */
					echo esc_html( sprintf( __( '%1$d cached pages (%2$s)', 'nodethemes-cache-ai' ), $stats[0], size_format( $stats[1] ) ) );
					?>
				</p>
				<p class="description"><?php esc_html_e( 'Visitors who are not logged in get a saved copy of each page. Logged-in users, carts, checkout and account pages are never cached.', 'nodethemes-cache-ai' ); ?></p>
			</div>
			<div class="ntca-card">
				<h2><?php esc_html_e( 'Mode', 'nodethemes-cache-ai' ); ?></h2>
				<?php if ( $fast ) : ?>
					<p class="ntca-big ntca-ok"><?php esc_html_e( 'Fast', 'nodethemes-cache-ai' ); ?></p>
					<p class="description"><?php esc_html_e( 'Cached pages are sent before WordPress and other plugins load (advanced-cache.php).', 'nodethemes-cache-ai' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'ntca_fast_off' ); ?><input type="hidden" name="action" value="ntca_fast_off"><button class="button"><?php esc_html_e( 'Switch to normal mode', 'nodethemes-cache-ai' ); ?></button></form>
				<?php else : ?>
					<p class="ntca-big"><?php esc_html_e( 'Normal', 'nodethemes-cache-ai' ); ?></p>
					<p class="description"><?php esc_html_e( 'Cached pages are sent as soon as this plugin loads. Fast mode adds wp-content/advanced-cache.php and one WP_CACHE line to wp-config.php, so pages are sent even earlier. Both are removed when you deactivate the plugin.', 'nodethemes-cache-ai' ); ?></p>
					<?php if ( 'other' === $drop ) : ?>
						<p class="ntca-bad"><?php esc_html_e( 'Another plugin already uses advanced-cache.php, so fast mode is not available.', 'nodethemes-cache-ai' ); ?></p>
					<?php else : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'ntca_fast_on' ); ?><input type="hidden" name="action" value="ntca_fast_on"><button class="button button-primary"><?php esc_html_e( 'Turn on fast mode', 'nodethemes-cache-ai' ); ?></button></form>
					<?php endif; ?>
				<?php endif; ?>
			</div>
			<div class="ntca-card">
				<h2><?php esc_html_e( 'Optimizations', 'nodethemes-cache-ai' ); ?></h2>
				<?php if ( $on ) : ?>
					<ul class="ntca-list">
						<?php foreach ( $on as $k ) : ?>
							<li><span class="dashicons dashicons-yes"></span> <?php echo esc_html( $opts[ $k ][0] ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php else : ?>
					<p><?php esc_html_e( 'None yet. Choose them in "Speed & safe test": every change is tested in your browser first.', 'nodethemes-cache-ai' ); ?></p>
				<?php endif; ?>
				<?php if ( ! empty( $last['time'] ) ) : ?>
					<p class="description">
						<?php
						/* translators: %1$s: passed/failed, %2$s: time ago */
						echo esc_html( sprintf( __( 'Last test: %1$s, %2$s ago', 'nodethemes-cache-ai' ), $last['pass'] ? __( 'passed', 'nodethemes-cache-ai' ) : __( 'found problems', 'nodethemes-cache-ai' ), human_time_diff( (int) $last['time'] ) ) );
						?>
					</p>
				<?php endif; ?>
				<p><a class="button button-primary" href="<?php echo esc_url( add_query_arg( array( 'page' => self::SLUG, 'tab' => 'speed' ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Speed & safe test', 'nodethemes-cache-ai' ); ?></a></p>
			</div>
			<?php self::image_card(); ?>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ntca-card ntca-form">
			<h2><?php esc_html_e( 'Cache settings', 'nodethemes-cache-ai' ); ?></h2>
			<?php wp_nonce_field( 'ntca_save' ); ?>
			<input type="hidden" name="action" value="ntca_save"><input type="hidden" name="tab" value="dashboard">
			<p><label><input type="checkbox" name="cache" value="1" <?php checked( $s['cache'] ); ?>> <?php esc_html_e( 'Turn on the page cache', 'nodethemes-cache-ai' ); ?></label></p>
			<p><label><?php esc_html_e( 'Keep cached pages for', 'nodethemes-cache-ai' ); ?> <input type="number" name="lifetime" min="1" max="720" value="<?php echo esc_attr( $s['lifetime'] ); ?>" class="small-text"> <?php esc_html_e( 'hours', 'nodethemes-cache-ai' ); ?></label><br><span class="description"><?php esc_html_e( 'Pages are also cleared when you edit them, when a comment is approved, when stock changes and when you switch theme or update plugins. 10 hours keeps forms and their security codes fresh.', 'nodethemes-cache-ai' ); ?></span></p>
			<p><label><input type="checkbox" name="mobile" value="1" <?php checked( $s['mobile'] ); ?>> <?php esc_html_e( 'Separate cache for phones', 'nodethemes-cache-ai' ); ?></label><br><span class="description"><?php esc_html_e( 'Only needed when your theme shows different HTML to phones. Most themes do not.', 'nodethemes-cache-ai' ); ?></span></p>
			<p><button class="button button-primary"><?php esc_html_e( 'Save', 'nodethemes-cache-ai' ); ?></button></p>
		</form>
		<?php
	}

	/**
	 * NodeThemes Image Optimizer card.
	 */
	private static function image_card() {
		?>
		<div class="ntca-card">
			<h2><?php esc_html_e( 'Images', 'nodethemes-cache-ai' ); ?></h2>
			<?php if ( defined( 'NTIO_VERSION' ) ) : ?>
				<?php
				$st = get_option( 'ntio_stats', array() );
				$n  = is_array( $st ) && isset( $st['images'] ) ? (int) $st['images'] : 0;
				$b  = is_array( $st ) && isset( $st['saved'] ) ? (int) $st['saved'] : 0;
				?>
				<p class="ntca-big ntca-ok"><?php esc_html_e( 'WebP', 'nodethemes-cache-ai' ); ?></p>
				<p>
					<?php
					/* translators: %1$d: images, %2$s: size */
					echo esc_html( sprintf( __( 'NodeThemes Image Optimizer is active: %1$d images converted, %2$s saved.', 'nodethemes-cache-ai' ), $n, size_format( $b ) ) );
					?>
				</p>
				<p class="description"><?php esc_html_e( 'When it converts an image, the cached pages are cleared so they use the new WebP file.', 'nodethemes-cache-ai' ); ?></p>
				<p><a class="button" href="<?php echo esc_url( admin_url( 'upload.php?page=nodethemes-image-optimizer' ) ); ?>"><?php esc_html_e( 'Open Image Optimizer', 'nodethemes-cache-ai' ); ?></a></p>
			<?php else : ?>
				<p><?php esc_html_e( 'Images are often the heaviest part of a page. Our free NodeThemes Image Optimizer converts them to WebP on your own server, and this plugin clears the cache when it does.', 'nodethemes-cache-ai' ); ?></p>
				<p><a class="button" href="https://nodethemes.com/free-plugins/" target="_blank" rel="noopener"><?php esc_html_e( 'Get the free Image Optimizer', 'nodethemes-cache-ai' ); ?></a></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Speed & test tab.
	 */
	private static function tab_speed() {
		$s    = NTCA_Settings::get();
		$opts = NTCA_Settings::optimizations();
		?>
		<div class="ntca-card ntca-form" id="ntca-speed">
			<h2><?php esc_html_e( 'Choose optimizations, then test them safely', 'nodethemes-cache-ai' ); ?></h2>
			<p><?php esc_html_e( 'Nothing changes for your visitors until a test passes and you click Apply. The test opens your pages in this browser twice without the changes and once with them, then compares JavaScript errors, files and images that fail to load, and the size of the main parts of each page.', 'nodethemes-cache-ai' ); ?></p>
			<table class="form-table" role="presentation">
				<?php foreach ( $opts as $k => $o ) : ?>
					<tr>
						<th scope="row"><label for="ntca-o-<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $o[0] ); ?></label></th>
						<td><label class="ntca-switch"><input type="checkbox" class="ntca-opt" id="ntca-o-<?php echo esc_attr( $k ); ?>" data-k="<?php echo esc_attr( $k ); ?>" <?php checked( $s['opts'][ $k ] ); ?>> <span class="description"><?php echo esc_html( $o[1] ); ?></span></label>
						<?php if ( 'defer_js' === $k ) : ?>
							<p><label for="ntca-defer-ex"><?php esc_html_e( 'Do not defer (one part of a file name or script ID per line):', 'nodethemes-cache-ai' ); ?></label><br><textarea id="ntca-defer-ex" rows="3" class="large-text code"><?php echo esc_textarea( implode( "\n", $s['defer_exclude'] ) ); ?></textarea></p>
						<?php elseif ( 'lazy_images' === $k ) : ?>
							<p><label><?php esc_html_e( 'Leave the first', 'nodethemes-cache-ai' ); ?> <input type="number" id="ntca-lazy-skip" min="0" max="10" class="small-text" value="<?php echo esc_attr( $s['lazy_skip'] ); ?>"> <?php esc_html_e( 'images of each page as they are (logo, main picture).', 'nodethemes-cache-ai' ); ?></label></p>
						<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>
			<p class="ntca-actions">
				<button type="button" class="button button-primary button-hero" id="ntca-test"><?php esc_html_e( 'Test these settings', 'nodethemes-cache-ai' ); ?></button>
				<button type="button" class="button button-hero" id="ntca-apply" disabled><?php esc_html_e( 'Apply to my live site', 'nodethemes-cache-ai' ); ?></button>
			</p>
			<p class="description">
				<?php
				/* translators: %d: number of pages */
				echo esc_html( sprintf( _n( 'The test uses %d page of your site:', 'The test uses %d pages of your site:', count( self::test_urls() ), 'nodethemes-cache-ai' ), count( self::test_urls() ) ) );
				?>
				<?php echo esc_html( implode( ', ', array_map( 'wp_make_link_relative', self::test_urls() ) ) ); ?>
				<?php esc_html_e( '(home page also at phone width). Add more pages under Exclusions > Test pages.', 'nodethemes-cache-ai' ); ?>
			</p>
		</div>
		<div class="ntca-card" id="ntca-result" hidden>
			<h2><?php esc_html_e( 'Test result', 'nodethemes-cache-ai' ); ?></h2>
			<div class="ntca-run">
				<div id="ntca-stage-slot"></div>
				<div class="ntca-run-text">
					<p id="ntca-status" role="status"></p>
					<div class="ntca-bar"><span id="ntca-progress"></span></div>
					<div id="ntca-pages"></div>
					<div id="ntca-pro-actions">
						<?php if ( ntca_is_pro() ) : ?>
							<?php do_action( 'ntca_result_actions' ); ?>
						<?php else : ?>
							<p class="ntca-pro-box">
								<?php esc_html_e( 'Pro finds the cause for you: it tests each optimization and each script on its own, keeps everything that works and leaves out only what breaks, then explains the problem in plain words with AI.', 'nodethemes-cache-ai' ); ?><br>
								<?php echo wp_kses_post( self::lock( __( 'Find the cause automatically', 'nodethemes-cache-ai' ) ) ); ?>
								<?php echo wp_kses_post( self::lock( __( 'Explain with AI', 'nodethemes-cache-ai' ) ) ); ?>
							</p>
						<?php endif; ?>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Exclusions tab.
	 */
	private static function tab_exclusions() {
		$s = NTCA_Settings::get();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ntca-card ntca-form">
			<?php wp_nonce_field( 'ntca_save' ); ?>
			<input type="hidden" name="action" value="ntca_save"><input type="hidden" name="tab" value="exclusions">
			<h2><?php esc_html_e( 'Never cache these pages', 'nodethemes-cache-ai' ); ?></h2>
			<p class="description"><?php esc_html_e( 'One per line: a part of the address, such as /members/, or a pattern with *, such as /event/*/tickets/. WooCommerce cart, checkout and account pages are always skipped. Use / for the home page only.', 'nodethemes-cache-ai' ); ?></p>
			<textarea name="exclude_urls" rows="5" class="large-text code"><?php echo esc_textarea( implode( "\n", $s['exclude_urls'] ) ); ?></textarea>
			<h2><?php esc_html_e( 'Skip the cache for visitors with these cookies', 'nodethemes-cache-ai' ); ?></h2>
			<p class="description"><?php esc_html_e( 'One cookie name (or its start) per line. Logged-in, comment, password and WooCommerce cart cookies are always skipped.', 'nodethemes-cache-ai' ); ?></p>
			<textarea name="exclude_cookies" rows="3" class="large-text code"><?php echo esc_textarea( implode( "\n", $s['exclude_cookies'] ) ); ?></textarea>
			<h2><?php esc_html_e( 'Tracking parameters to ignore', 'nodethemes-cache-ai' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Pages opened with only these parameters in the address still get the cached copy. Any other parameter skips the cache.', 'nodethemes-cache-ai' ); ?></p>
			<textarea name="ignore_qs" rows="3" class="large-text code"><?php echo esc_textarea( implode( "\n", $s['ignore_qs'] ) ); ?></textarea>
			<h2><?php esc_html_e( 'Test pages', 'nodethemes-cache-ai' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Extra pages of this site to include in the preview test, one address per line (for example a landing page with a slider or a form). Up to 8 pages are tested.', 'nodethemes-cache-ai' ); ?></p>
			<textarea name="test_urls" rows="3" class="large-text code"><?php echo esc_textarea( implode( "\n", $s['test_urls'] ) ); ?></textarea>
			<p><button class="button button-primary"><?php esc_html_e( 'Save', 'nodethemes-cache-ai' ); ?></button></p>
		</form>
		<?php
	}

	/**
	 * Database & tweaks tab.
	 */
	private static function tab_tools() {
		$s = NTCA_Settings::get();
		?>
		<div class="ntca-card">
			<h2><?php esc_html_e( 'Clean the database', 'nodethemes-cache-ai' ); ?></h2>
			<p><?php esc_html_e( 'Removes data your site no longer needs. Deleted items cannot be restored, so make a backup first (most hosts have one-click backups). Up to 500 items are removed per click.', 'nodethemes-cache-ai' ); ?></p>
			<div style="overflow-x:auto">
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Item', 'nodethemes-cache-ai' ); ?></th><th><?php esc_html_e( 'Found', 'nodethemes-cache-ai' ); ?></th><th></th></tr></thead>
				<tbody>
				<?php
				$total = 0;
				foreach ( NTCA_DB::items() as $k => $item ) :
					$n      = NTCA_DB::count( $k );
					$total += $n;
					?>
					<tr>
						<td><strong><?php echo esc_html( $item[0] ); ?></strong><br><span class="description"><?php echo esc_html( $item[1] ); ?></span></td>
						<td><?php echo esc_html( number_format_i18n( $n ) ); ?></td>
						<td><?php if ( $n ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Delete these items for good?', 'nodethemes-cache-ai' ) ) ); ?>)"><?php wp_nonce_field( 'ntca_db_clean' ); ?><input type="hidden" name="action" value="ntca_db_clean"><input type="hidden" name="item" value="<?php echo esc_attr( $k ); ?>"><button class="button"><?php esc_html_e( 'Clean', 'nodethemes-cache-ai' ); ?></button></form><?php endif; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
			<?php if ( $total ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px" onsubmit="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Delete all of these items for good?', 'nodethemes-cache-ai' ) ) ); ?>)"><?php wp_nonce_field( 'ntca_db_clean' ); ?><input type="hidden" name="action" value="ntca_db_clean"><input type="hidden" name="item" value="all"><button class="button button-primary"><?php esc_html_e( 'Clean all', 'nodethemes-cache-ai' ); ?></button></form>
			<?php endif; ?>
		</div>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ntca-card ntca-form">
			<?php wp_nonce_field( 'ntca_tweaks' ); ?>
			<input type="hidden" name="action" value="ntca_tweaks">
			<h2><?php esc_html_e( 'Lighter server load', 'nodethemes-cache-ai' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr><th scope="row"><?php esc_html_e( 'Heartbeat on the site', 'nodethemes-cache-ai' ); ?></th><td><label><input type="checkbox" name="heartbeat_front" value="1" <?php checked( $s['heartbeat_front'] ); ?>> <?php esc_html_e( 'Turn off for visitors who are not logged in', 'nodethemes-cache-ai' ); ?></label><br><span class="description"><?php esc_html_e( 'Heartbeat sends a request to your server every 15 to 60 seconds. Visitors almost never need it.', 'nodethemes-cache-ai' ); ?></span></td></tr>
				<tr><th scope="row"><label for="ntca-hb-admin"><?php esc_html_e( 'Heartbeat in the dashboard', 'nodethemes-cache-ai' ); ?></label></th><td><select name="heartbeat_admin" id="ntca-hb-admin">
					<option value="0" <?php selected( $s['heartbeat_admin'], 0 ); ?>><?php esc_html_e( 'WordPress default', 'nodethemes-cache-ai' ); ?></option>
					<option value="60" <?php selected( $s['heartbeat_admin'], 60 ); ?>><?php esc_html_e( 'Every 60 seconds', 'nodethemes-cache-ai' ); ?></option>
					<option value="120" <?php selected( $s['heartbeat_admin'], 120 ); ?>><?php esc_html_e( 'Every 120 seconds', 'nodethemes-cache-ai' ); ?></option>
				</select><br><span class="description"><?php esc_html_e( 'The post editor keeps the normal speed, because it saves drafts and shows who is editing.', 'nodethemes-cache-ai' ); ?></span></td></tr>
				<?php if ( class_exists( 'WooCommerce' ) ) : ?>
				<tr><th scope="row"><label for="ntca-cf"><?php esc_html_e( 'WooCommerce cart fragments', 'nodethemes-cache-ai' ); ?></label></th><td><select name="cart_fragments" id="ntca-cf">
					<option value="default" <?php selected( $s['cart_fragments'], 'default' ); ?>><?php esc_html_e( 'WooCommerce default', 'nodethemes-cache-ai' ); ?></option>
					<option value="smart" <?php selected( $s['cart_fragments'], 'smart' ); ?>><?php esc_html_e( 'Only when the cart has items (recommended)', 'nodethemes-cache-ai' ); ?></option>
					<option value="off" <?php selected( $s['cart_fragments'], 'off' ); ?>><?php esc_html_e( 'Off, except on the cart and checkout', 'nodethemes-cache-ai' ); ?></option>
				</select><br><span class="description"><?php esc_html_e( 'Cart fragments keep a mini-cart in the header up to date with an extra request on every page. With "Only when the cart has items", empty-cart visitors skip that request. Use "Off" only if your theme has no mini-cart.', 'nodethemes-cache-ai' ); ?></span></td></tr>
				<?php endif; ?>
			</table>
			<p><button class="button button-primary"><?php esc_html_e( 'Save', 'nodethemes-cache-ai' ); ?></button></p>
		</form>
		<?php
	}

	/**
	 * History tab.
	 */
	private static function tab_history() {
		$h    = NTCA_Settings::history();
		$opts = NTCA_Settings::optimizations();
		?>
		<div class="ntca-card">
			<h2><?php esc_html_e( 'Undo a change with one click', 'nodethemes-cache-ai' ); ?></h2>
			<p><?php esc_html_e( 'Every time the settings change, the previous settings are kept here (the last 10). Restore puts them back and clears the cache.', 'nodethemes-cache-ai' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0 0 14px"><?php wp_nonce_field( 'ntca_all_off' ); ?><input type="hidden" name="action" value="ntca_all_off"><button class="button"><?php esc_html_e( 'Switch off all optimizations now', 'nodethemes-cache-ai' ); ?></button> <span class="description"><?php esc_html_e( 'If your site ever looks wrong, this turns off every HTML change at once. The page cache stays on.', 'nodethemes-cache-ai' ); ?></span></form>
			<?php if ( ! $h ) : ?>
				<p><em><?php esc_html_e( 'No changes yet.', 'nodethemes-cache-ai' ); ?></em></p>
			<?php else : ?>
				<div style="overflow-x:auto">
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'Changed', 'nodethemes-cache-ai' ); ?></th><th><?php esc_html_e( 'What happened', 'nodethemes-cache-ai' ); ?></th><th><?php esc_html_e( 'Optimizations before the change', 'nodethemes-cache-ai' ); ?></th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $h as $i => $row ) : ?>
						<?php
						$before = array();
						foreach ( (array) $row['settings']['opts'] as $k => $v ) {
							if ( $v && isset( $opts[ $k ] ) ) {
								$before[] = $opts[ $k ][0];
							}
						}
						$who = get_userdata( (int) $row['user'] );
						?>
						<tr>
							<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $row['time'] ) ); ?><br><small><?php echo esc_html( in_array( $row['source'], array( 'guard', 'retest' ), true ) ? __( 'Automatic', 'nodethemes-cache-ai' ) : ( $who ? $who->display_name : '' ) ); ?></small></td>
							<td><?php echo esc_html( $row['note'] ); ?></td>
							<td><?php echo esc_html( $before ? implode( ', ', $before ) : __( 'None', 'nodethemes-cache-ai' ) ); ?></td>
							<td><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'ntca_restore' ); ?><input type="hidden" name="action" value="ntca_restore"><input type="hidden" name="i" value="<?php echo esc_attr( $i ); ?>"><button class="button"><?php esc_html_e( 'Restore', 'nodethemes-cache-ai' ); ?></button></form></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Pro tab: settings from the add-on, or what Pro adds.
	 */
	private static function tab_pro() {
		if ( ntca_is_pro() ) {
			do_action( 'ntca_pro_tab' );
			return;
		}
		$f = array(
			array( __( 'Find the cause automatically', 'nodethemes-cache-ai' ), __( 'When a test fails, Pro tests each optimization and each script file on its own, keeps everything that works and leaves out only the part that breaks your page.', 'nodethemes-cache-ai' ) ),
			array( __( 'AI explanations', 'nodethemes-cache-ai' ), __( 'Explains in plain words what broke and why, using your own OpenAI, Anthropic or Gemini key, or the AI service connected in WordPress.', 'nodethemes-cache-ai' ) ),
			array( __( 'Retest after updates', 'nodethemes-cache-ai' ), __( 'After you update or switch a theme or plugin, the next time you open your dashboard the live optimizations are tested again in your browser, and any that now break a page are switched off.', 'nodethemes-cache-ai' ) ),
			array( __( 'Guard', 'nodethemes-cache-ai' ), __( 'For 48 hours after a change, visitors\' browsers report new JavaScript errors (only the error text, file and page, no personal data). If the same new error comes back from several visits, the previous settings are restored automatically.', 'nodethemes-cache-ai' ) ),
		);
		?>
		<div class="ntca-card">
			<h2><?php esc_html_e( 'NodeThemes Cache AI Pro', 'nodethemes-cache-ai' ); ?> <span class="ntca-pro">PRO</span></h2>
			<p><?php esc_html_e( 'Everything in this plugin works without Pro. Pro is a separate add-on that is installed next to this plugin; your settings and history stay.', 'nodethemes-cache-ai' ); ?></p>
			<div class="ntca-grid">
				<?php foreach ( $f as $x ) : ?>
					<div class="ntca-card ntca-feature"><h3><span class="dashicons dashicons-lock"></span> <?php echo esc_html( $x[0] ); ?></h3><p><?php echo esc_html( $x[1] ); ?></p></div>
				<?php endforeach; ?>
			</div>
			<p><a class="button button-primary button-hero" href="<?php echo esc_url( ntca_upgrade_url() ); ?>" target="_blank" rel="noopener"><?php echo ntca_pro_installed() ? esc_html__( 'Activate your Pro license', 'nodethemes-cache-ai' ) : esc_html__( 'Get Cache AI Pro', 'nodethemes-cache-ai' ); ?></a></p>
		</div>
		<?php
	}
}
