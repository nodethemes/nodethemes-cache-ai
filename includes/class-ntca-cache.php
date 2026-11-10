<?php
/**
 * Page cache: output buffer, cache files, clearing, the advanced-cache.php drop-in and WP_CACHE.
 *
 * @package NodeThemes_Cache_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Page cache.
 */
class NTCA_Cache {

	const DROPIN_MARK = 'NodeThemes Cache AI drop-in';
	const CONFIG_MARK = '// Added by NodeThemes Cache AI';

	/**
	 * Why the current request will not be cached ('' = it will be).
	 *
	 * @var string
	 */
	private static $skip = '';

	/**
	 * Settings in effect for this request.
	 *
	 * @var array|null
	 */
	private static $effective = null;

	/**
	 * URLs to clear at the end of the request.
	 *
	 * @var array
	 */
	private static $queue = array();

	/**
	 * Clear everything at the end of the request.
	 *
	 * @var bool
	 */
	private static $queue_all = false;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'early_hooks' ), 1 );
		add_action( 'template_redirect', array( __CLASS__, 'start_buffer' ), 0 );

		// Clearing.
		add_action( 'save_post', array( __CLASS__, 'on_save_post' ), 20, 2 );
		add_action( 'deleted_post', array( __CLASS__, 'on_post_id' ) );
		add_action( 'trashed_post', array( __CLASS__, 'on_post_id' ) );
		add_action( 'transition_comment_status', array( __CLASS__, 'on_comment_status' ), 10, 3 );
		add_action( 'comment_post', array( __CLASS__, 'on_comment' ), 10, 2 );
		add_action( 'edit_comment', array( __CLASS__, 'on_comment' ) );
		add_action( 'woocommerce_product_set_stock', array( __CLASS__, 'on_product' ) );
		add_action( 'woocommerce_variation_set_stock', array( __CLASS__, 'on_product' ) );
		add_action( 'woocommerce_product_set_stock_status', array( __CLASS__, 'on_post_id' ) );
		foreach ( array( 'switch_theme', 'customize_save_after', 'wp_update_nav_menu', 'upgrader_process_complete', 'activated_plugin', 'deactivated_plugin', 'update_option_permalink_structure', 'update_option_blogname', 'update_option_blogdescription', 'update_option_sidebars_widgets', 'update_option_ntio_settings', 'edited_term', 'delete_term' ) as $h ) {
			add_action( $h, array( __CLASS__, 'queue_all' ) );
		}
		// NodeThemes Image Optimizer: a page must be rebuilt once its images have WebP copies.
		add_action( 'added_post_meta', array( __CLASS__, 'on_meta' ), 10, 3 );
		add_action( 'updated_post_meta', array( __CLASS__, 'on_meta' ), 10, 3 );
		add_action( 'shutdown', array( __CLASS__, 'run_queue' ), 0 );

		add_action( 'ntca_cleanup', array( __CLASS__, 'cleanup' ) );
		if ( ! wp_next_scheduled( 'ntca_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'ntca_cleanup' );
		}
	}

	/**
	 * Settings for this request: the test settings in a preview, otherwise the live ones.
	 *
	 * @return array
	 */
	public static function effective() {
		if ( null === self::$effective ) {
			$p               = NTCA_Preview::current();
			self::$effective = $p ? $p : NTCA_Settings::get();
		}
		return self::$effective;
	}

	/**
	 * Optimizations that use WordPress hooks (emoji).
	 */
	public static function early_hooks() {
		if ( is_admin() || wp_doing_ajax() || ( is_user_logged_in() && ! NTCA_Preview::current() ) ) {
			return;
		}
		NTCA_Optimizer::hooks( self::effective() );
	}

	/**
	 * Start the output buffer on public pages.
	 */
	public static function start_buffer() {
		if ( is_admin() || wp_doing_ajax() || is_feed() || is_embed() || is_trackback() || is_robots() || is_customize_preview() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		self::$skip = self::why_not_cacheable();
		ob_start( array( __CLASS__, 'end_buffer' ) );
	}

	/**
	 * Reasons a page is not stored.
	 *
	 * @return string
	 */
	private static function why_not_cacheable() {
		$s = NTCA_Settings::get();
		if ( empty( $s['cache'] ) ) {
			return 'off';
		}
		if ( is_user_logged_in() ) {
			return 'logged-in';
		}
		if ( is_404() || is_search() || is_preview() || post_password_required() ) {
			return 'page-type';
		}
		if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() ) ) {
			return 'woocommerce';
		}
		if ( NTCA_Preview::current() ) {
			return 'preview';
		}
		if ( '' === ntca_cache_file_for_request( ntca_serve_config() ) ) {
			return 'request';
		}
		return '';
	}

	/**
	 * Optimize the page and store it.
	 *
	 * @param string $html Page HTML.
	 * @return string
	 */
	public static function end_buffer( $html ) {
		if ( ! is_string( $html ) || '' === $html || false === stripos( $html, '<html' ) || false === stripos( $html, '</html>' ) ) {
			return $html;
		}
		foreach ( headers_list() as $h ) {
			if ( 0 === stripos( $h, 'content-type:' ) && false === stripos( $h, 'text/html' ) ) {
				return $html;
			}
		}
		$preview = NTCA_Preview::current();
		if ( $preview ) {
			return NTCA_Preview::inject( NTCA_Optimizer::run( $html, $preview, true ) );
		}
		if ( is_user_logged_in() ) {
			return $html;
		}
		$s   = NTCA_Settings::get();
		$out = NTCA_Settings::any_opt( $s ) ? NTCA_Optimizer::run( $html, $s ) : $html;
		$out = (string) apply_filters( 'ntca_output', $out, $s );

		if ( '' === self::$skip && ! ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) && 200 === http_response_code() && ! self::sets_cookie() ) {
			self::store( $out );
		}
		return $out;
	}

	/**
	 * Did this response set a cookie (a session, a cart)? Then it is personal.
	 *
	 * @return bool
	 */
	private static function sets_cookie() {
		foreach ( headers_list() as $h ) {
			if ( 0 === stripos( $h, 'set-cookie:' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Write the cache file for this request.
	 *
	 * @param string $html Final HTML.
	 */
	private static function store( $html ) {
		$file = ntca_cache_file_for_request( ntca_serve_config() );
		if ( '' === $file || ! NTCA_FS::mkdir( NTCA_CACHE_DIR ) || ! NTCA_FS::mkdir( dirname( $file ) ) ) {
			return;
		}
		$stamp = "\n<!-- ntca: cached by NodeThemes Cache AI on " . gmdate( 'Y-m-d H:i:s' ) . ' UTC -->';
		NTCA_FS::put( $file, $html . $stamp );
	}

	/* ------------------------------------------------------------------
	 * Clearing
	 * ------------------------------------------------------------------ */

	/**
	 * Folder that holds the cache of one URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function url_dir( $url ) {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$port = wp_parse_url( $url, PHP_URL_PORT );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( '' === $host || false !== strpos( $path, '..' ) ) {
			return '';
		}
		$host = str_replace( array( ':', '[', ']' ), '_', $host . ( $port ? ':' . $port : '' ) );
		$sub  = trim( $path, '/' );
		return NTCA_CACHE_DIR . '/' . $host . ( '' === $sub ? '' : '/' . $sub );
	}

	/**
	 * Remove the cached copy of one URL (desktop and mobile; sub-pages stay).
	 *
	 * @param string $url URL.
	 */
	public static function purge_url( $url ) {
		$dir = self::url_dir( $url );
		if ( '' === $dir ) {
			return;
		}
		NTCA_FS::delete( $dir . '/index.html' );
		NTCA_FS::delete( $dir . '/index-mobile.html' );
		// Paged archives: /page/2/ and so on.
		if ( is_dir( $dir . '/page' ) ) {
			NTCA_FS::rmdir( $dir . '/page' );
		}
		do_action( 'ntca_purged_url', $url );
	}

	/**
	 * Remove every cached page.
	 */
	public static function purge_all() {
		if ( is_multisite() ) {
			// Only this site: its host folder, or its sub-folder on a sub-folder network.
			$dir = self::url_dir( home_url( '/' ) );
			list( , $prefix ) = NTCA_Settings::site_key();
			if ( '/' === $prefix ) {
				NTCA_FS::rmdir( $dir );
			} else {
				NTCA_FS::delete( $dir . '/index.html' );
				NTCA_FS::delete( $dir . '/index-mobile.html' );
				NTCA_FS::rmdir( $dir );
			}
		} else {
			NTCA_FS::rmdir( NTCA_CACHE_DIR, true );
		}
		do_action( 'ntca_purged_all' );
	}

	/**
	 * Clear everything at the end of the request (many hooks can fire in one save).
	 */
	public static function queue_all() {
		self::$queue_all = true;
	}

	/**
	 * Run the clearing queued during this request.
	 */
	public static function run_queue() {
		if ( self::$queue_all ) {
			self::purge_all();
		} else {
			foreach ( array_unique( self::$queue ) as $u ) {
				self::purge_url( $u );
			}
		}
		self::$queue     = array();
		self::$queue_all = false;
	}

	/**
	 * Pages that show a post: the post, home, blog page, its terms and author.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function queue_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || 'revision' === $post->post_type || 'nav_menu_item' === $post->post_type || ! is_post_type_viewable( $post->post_type ) ) {
			return;
		}
		$u   = array( home_url( '/' ), get_permalink( $post ) );
		$pfp = (int) get_option( 'page_for_posts' );
		if ( $pfp ) {
			$u[] = get_permalink( $pfp );
		}
		$arch = get_post_type_archive_link( $post->post_type );
		if ( $arch ) {
			$u[] = $arch;
		}
		foreach ( get_object_taxonomies( $post->post_type ) as $tax ) {
			$terms = get_the_terms( $post, $tax );
			if ( is_array( $terms ) ) {
				foreach ( $terms as $t ) {
					$l = get_term_link( $t );
					if ( ! is_wp_error( $l ) ) {
						$u[] = $l;
					}
				}
			}
		}
		$u[] = get_author_posts_url( (int) $post->post_author );
		if ( 'product' === $post->post_type && function_exists( 'wc_get_page_id' ) && wc_get_page_id( 'shop' ) > 0 ) {
			$u[] = get_permalink( wc_get_page_id( 'shop' ) );
		}
		foreach ( (array) apply_filters( 'ntca_post_urls', $u, $post ) as $x ) {
			if ( $x ) {
				self::$queue[] = $x;
			}
		}
	}

	/**
	 * Post saved.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 */
	public static function on_save_post( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || 'auto-draft' === $post->post_status ) {
			return;
		}
		self::queue_post( $post_id );
	}

	/**
	 * Post deleted or trashed, stock status changed.
	 *
	 * @param int|object $post_id Post ID or product.
	 */
	public static function on_post_id( $post_id ) {
		self::queue_post( is_object( $post_id ) && method_exists( $post_id, 'get_id' ) ? $post_id->get_id() : (int) $post_id );
	}

	/**
	 * Stock changed.
	 *
	 * @param WC_Product $product Product.
	 */
	public static function on_product( $product ) {
		if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
			$id = method_exists( $product, 'get_parent_id' ) && $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
			self::queue_post( $id );
		}
	}

	/**
	 * Comment approved or unapproved.
	 *
	 * @param string     $new     New status.
	 * @param string     $old     Old status.
	 * @param WP_Comment $comment Comment.
	 */
	public static function on_comment_status( $new, $old, $comment ) {
		self::queue_post( (int) $comment->comment_post_ID );
	}

	/**
	 * New or edited comment.
	 *
	 * @param int        $id       Comment ID.
	 * @param int|string $approved Approval.
	 */
	public static function on_comment( $id, $approved = 1 ) {
		$c = get_comment( $id );
		if ( $c && ( 1 === (int) $approved || '1' === $c->comment_approved ) ) {
			self::queue_post( (int) $c->comment_post_ID );
		}
	}

	/**
	 * NodeThemes Image Optimizer finished an image: pages may now use the WebP copy.
	 *
	 * @param int    $meta_id  Meta ID.
	 * @param int    $post_id  Attachment ID.
	 * @param string $meta_key Key.
	 */
	public static function on_meta( $meta_id, $post_id, $meta_key ) {
		if ( '_ntio' === $meta_key ) {
			self::$queue_all = true;
		}
	}

	/**
	 * Hourly: delete expired files.
	 */
	public static function cleanup() {
		$s   = NTCA_Settings::get();
		$max = (int) $s['lifetime'] * HOUR_IN_SECONDS;
		if ( ! is_dir( NTCA_CACHE_DIR ) ) {
			return;
		}
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( NTCA_CACHE_DIR, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			if ( $f->isFile() && 'html' === $f->getExtension() && time() - $f->getMTime() > $max ) {
				NTCA_FS::delete( $f->getPathname() );
			}
		}
	}

	/**
	 * Number of cached pages and their size.
	 *
	 * @return array [count, bytes]
	 */
	public static function stats() {
		$n = 0;
		$b = 0;
		if ( is_dir( NTCA_CACHE_DIR ) ) {
			$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( NTCA_CACHE_DIR, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $f ) {
				if ( $f->isFile() && 'html' === $f->getExtension() ) {
					++$n;
					$b += $f->getSize();
					if ( $n > 20000 ) {
						break;
					}
				}
			}
		}
		return array( $n, $b );
	}

	/* ------------------------------------------------------------------
	 * Drop-in and WP_CACHE (only when the admin clicks the button)
	 * ------------------------------------------------------------------ */

	/**
	 * Drop-in state: ours, other, none.
	 *
	 * @return string
	 */
	public static function dropin_state() {
		$f = WP_CONTENT_DIR . '/advanced-cache.php';
		if ( ! file_exists( $f ) ) {
			return 'none';
		}
		return false !== strpos( NTCA_FS::get( $f ), self::DROPIN_MARK ) ? 'ours' : 'other';
	}

	/**
	 * Is the fast mode working right now (drop-in loaded by WordPress)?
	 *
	 * @return bool
	 */
	public static function fast_mode() {
		return defined( 'WP_CACHE' ) && WP_CACHE && 'ours' === self::dropin_state();
	}

	/**
	 * Install the drop-in and switch on WP_CACHE.
	 *
	 * @return true|WP_Error
	 */
	public static function enable_fast_mode() {
		if ( 'other' === self::dropin_state() ) {
			return new WP_Error( 'ntca_other', __( 'Another cache plugin has its own advanced-cache.php file. Use only one page cache plugin: deactivate the other one first.', 'nodethemes-cache-ai' ) );
		}
		$serve = wp_normalize_path( NTCA_DIR . 'includes/serve.php' );
		$code  = "<?php\n// " . self::DROPIN_MARK . ". It is removed when you deactivate the plugin.\ndefined( 'ABSPATH' ) || exit;\n"
			. '$ntca_serve_file = ' . var_export( $serve, true ) . ";\n" // phpcs:ignore WordPress.PHP.DevelopmentFunctions -- builds the drop-in.
			. "if ( is_file( \$ntca_serve_file ) ) {\n\trequire_once \$ntca_serve_file;\n\tntca_serve();\n}\n";
		if ( ! NTCA_FS::put( WP_CONTENT_DIR . '/advanced-cache.php', $code ) ) {
			return new WP_Error( 'ntca_write', __( 'Could not write wp-content/advanced-cache.php. Ask your host to make the wp-content folder writable, or keep using the normal mode.', 'nodethemes-cache-ai' ) );
		}
		if ( ! ( defined( 'WP_CACHE' ) && WP_CACHE ) ) {
			$r = self::set_wp_cache( true );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
		}
		return true;
	}

	/**
	 * Remove the drop-in and our WP_CACHE line.
	 */
	public static function disable_fast_mode() {
		if ( 'ours' === self::dropin_state() ) {
			NTCA_FS::delete( WP_CONTENT_DIR . '/advanced-cache.php' );
		}
		self::set_wp_cache( false );
	}

	/**
	 * Add or remove the WP_CACHE line in wp-config.php. Only our own line is ever removed.
	 *
	 * @param bool $on On.
	 * @return true|WP_Error
	 */
	public static function set_wp_cache( $on ) {
		$file = NTCA_FS::wp_config_path();
		if ( '' === $file ) {
			return new WP_Error( 'ntca_config', __( 'wp-config.php was not found.', 'nodethemes-cache-ai' ) );
		}
		$src = NTCA_FS::get( $file );
		if ( '' === $src ) {
			return new WP_Error( 'ntca_config', __( 'wp-config.php could not be read.', 'nodethemes-cache-ai' ) );
		}
		$line = "define( 'WP_CACHE', true ); " . self::CONFIG_MARK;
		if ( $on ) {
			if ( false !== strpos( $src, self::CONFIG_MARK ) ) {
				return true;
			}
			if ( preg_match( '/define\s*\(\s*[\'"]WP_CACHE[\'"]/i', $src ) ) {
				return new WP_Error( 'ntca_config', __( 'wp-config.php already has a WP_CACHE line, probably from another cache plugin. Set it to true yourself, or remove the other plugin.', 'nodethemes-cache-ai' ) );
			}
			if ( ! wp_is_writable( $file ) ) {
				/* translators: %s: code line */
				return new WP_Error( 'ntca_config', sprintf( __( 'wp-config.php is not writable. Add this line near the top of wp-config.php yourself: %s', 'nodethemes-cache-ai' ), $line ) );
			}
			$new = preg_replace( '/^<\?php\s*/', "<?php\n" . $line . "\n", $src, 1 );
		} else {
			if ( false === strpos( $src, self::CONFIG_MARK ) || ! wp_is_writable( $file ) ) {
				return true;
			}
			$new = preg_replace( '/^.*' . preg_quote( self::CONFIG_MARK, '/' ) . '.*\R?/m', '', $src );
		}
		if ( ! $new || $new === $src ) {
			return $on ? new WP_Error( 'ntca_config', __( 'wp-config.php could not be changed.', 'nodethemes-cache-ai' ) ) : true;
		}
		return NTCA_FS::put( $file, $new ) ? true : new WP_Error( 'ntca_config', __( 'wp-config.php could not be saved.', 'nodethemes-cache-ai' ) );
	}

	/**
	 * Other page-cache plugins that are active.
	 *
	 * @return array names
	 */
	public static function conflicts() {
		$known = array(
			'wp-super-cache/wp-cache.php'                 => 'WP Super Cache',
			'w3-total-cache/w3-total-cache.php'           => 'W3 Total Cache',
			'wp-fastest-cache/wpFastestCache.php'         => 'WP Fastest Cache',
			'litespeed-cache/litespeed-cache.php'         => 'LiteSpeed Cache',
			'wp-rocket/wp-rocket.php'                     => 'WP Rocket',
			'sg-cachepress/sg-cachepress.php'             => 'Speed Optimizer (SiteGround)',
			'breeze/breeze.php'                           => 'Breeze',
			'comet-cache/comet-cache.php'                 => 'Comet Cache',
			'hummingbird-performance/wp-hummingbird.php'  => 'Hummingbird',
			'wp-optimize/wp-optimize.php'                 => 'WP-Optimize',
			'cache-enabler/cache-enabler.php'             => 'Cache Enabler',
			'autoptimize/autoptimize.php'                 => 'Autoptimize',
		);
		$out = array();
		foreach ( $known as $file => $name ) {
			if ( is_plugin_active( $file ) ) {
				$out[] = $name;
			}
		}
		return $out;
	}

	/**
	 * Activation: write the config. Nothing outside the cache folder is changed.
	 */
	public static function activate() {
		if ( false === get_option( NTCA_Settings::OPTION, false ) ) {
			add_option( NTCA_Settings::OPTION, NTCA_Settings::defaults() );
		}
		NTCA_Settings::write_config();
	}

	/**
	 * Deactivation: remove the drop-in, our wp-config line and the cached pages.
	 */
	public static function deactivate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			self::disable_fast_mode();
			NTCA_FS::rmdir( NTCA_CACHE_DIR );
		} elseif ( is_multisite() ) {
			// Other sites of the network may still use the cache: remove only this site.
			self::purge_all();
			NTCA_Settings::write_config( null, true );
		} else {
			self::disable_fast_mode();
			NTCA_FS::rmdir( NTCA_CACHE_DIR );
		}
		wp_clear_scheduled_hook( 'ntca_cleanup' );
		wp_clear_scheduled_hook( 'ntca_preload' );
		wp_clear_scheduled_hook( 'ntca_fonts' );
	}
}
