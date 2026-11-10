=== NodeThemes Cache AI ===
Contributors: nodethemes
Tags: cache, page cache, performance, minify, lazy load
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Page cache, minify, defer and lazy loading that are tested in your own browser before visitors see them, with one-click rollback.

== Description ==

NodeThemes Cache AI is a page cache and speed plugin for WordPress and WooCommerce. Most speed plugins switch an option on and hope nothing breaks. This one tests each change first: it opens your pages in your own browser with and without the new settings, compares them, and only lets you apply settings that pass.

Everything below is included, with no usage limits:

* **Page cache** for visitors who are not logged in, with automatic clearing when you edit a post, approve a comment, change stock, switch theme, edit menus or update plugins
* **WooCommerce-safe**: cart, checkout and account pages, carts and logged-in users are never cached; responses that set a cookie are never stored
* **Fast mode** (optional): one click adds the advanced-cache.php drop-in and the WP_CACHE line, so cached pages are sent before WordPress loads. Both are removed when you deactivate the plugin
* **Optimizations**: minify HTML, minify inline CSS, defer JavaScript files (with a "Do not defer" list), lazy-load images (the first images of each page are skipped), lazy-load iframes, remove the emoji script, display=swap for Google Fonts, and **host Google Fonts on your own site** (faster, and visitors' browsers no longer contact Google for fonts)
* **Safe preview test**: new settings are shown only to you, in a preview inside the admin screen. The test loads each test page twice without the changes and once with them, at desktop width and at phone width for the home page, and reports new JavaScript errors, files and images that fail to load, and parts of the page that change size or disappear
* **One-click rollback**: the last 10 settings are kept in History; Restore puts any of them back and clears the cache. "Switch off all optimizations" turns every HTML change off at once
* **Exclusions**: pages (with * patterns), cookies, and tracking parameters (utm_*, gclid, fbclid) that still get the cached page
* **Separate cache for phones** (optional), cache lifetime, admin-bar "Clear all cache" and "Clear this page"
* **Database cleanup**: post revisions, old auto-drafts, posts and comments in the trash, spam, expired transients and expired WooCommerce sessions, with a count before you delete
* **Lighter server load**: switch Heartbeat off for visitors, slow it down in the dashboard (the editor keeps its normal speed), and load WooCommerce cart fragments only when the cart has items
* **WP-CLI**: wp ntca status, purge, fast-mode, all-off, history and db-clean
* **Multisite**: each site of a network has its own settings and cache
* Works with the free **NodeThemes Image Optimizer**: when it converts an image to WebP, cached pages are cleared so they use the new file

= Optional Pro add-on =

NodeThemes Cache AI Pro is a separate add-on sold at [nodethemes.com](https://nodethemes.com/product/nodethemes-cache-ai-pro/). When a test fails, it finds the cause for you (each optimization and each deferred script is tested on its own, and only the part that breaks is left out) and explains the problem in plain words with AI. It also adds a click test (menus, tabs, sliders and product options are clicked during the preview test), critical CSS, a Google PageSpeed report with before/after history, real-visitor speed (Core Web Vitals), Cloudflare cache purge and a CDN address, a retest after theme and plugin updates, and a guard that restores the previous settings when visitors get a new JavaScript error. Nothing in this plugin stops working without it, and your settings and history stay when you add it.

== Installation ==

1. Install the plugin from Plugins > Add New Plugin, or upload the zip, and activate it. The page cache starts right away for visitors who are not logged in.
2. Optional: Cache AI > Dashboard > "Turn on fast mode".
3. Cache AI > Speed & safe test: choose optimizations, click "Test these settings", keep the tab open until the result appears, then click "Apply to my live site".
4. If your site ever looks wrong, open Cache AI > History & rollback and click Restore, or "Switch off all optimizations now".

Use only one page cache plugin at a time. If your host has its own page cache (for example LiteSpeed, Varnish or Nginx cache), clear it after changing settings here.

To switch off every HTML optimization without opening the admin, add define( 'NTCA_DISABLE_OPTIMIZE', true ); to wp-config.php.

== Frequently Asked Questions ==

= Do logged-in users and shoppers get cached pages? =

No. Logged-in users, commenters, visitors with a WooCommerce cart, and the cart, checkout and account pages always get a fresh page.

= What does the test check, and what can it miss? =

It checks the test pages (home, a post, a page, the shop and a product, plus any you add) for new JavaScript errors, files and images that fail to load, missing or resized page parts and a large change in page height. It does not click buttons or submit forms, so test important forms yourself after a change.

= Can it break my site? =

Visitors only get settings you applied. Settings that fail the test are not applied unless you confirm. Every change can be undone in History with one click.

= Does it work with my host's cache or a CDN? =

Yes. Clear the host or CDN cache after you change settings here.

= Will my data be lost if I add the Pro add-on? =

No. Pro is installed next to this plugin and uses the same settings and history.

== External services ==

The preview test runs in your own browser against your own site.

= Update check (nodethemes.com) =

The free plugin only contacts nodethemes.com to check for new versions of itself (about twice a day, and when you click "Check again" in Dashboard > Updates). It sends the plugin's package name and its installed version; no site content or visitor data is sent. New versions appear in Dashboard > Updates like any other plugin.

* NodeThemes: [Terms](https://nodethemes.com/terms/), [Privacy Policy](https://nodethemes.com/privacy-policy/)

The plugin connects to one more external service, and only when you switch the option on:

= Google Fonts (only with "Host Google Fonts on your own site") =

When this optimization is on and a page uses a Google Fonts stylesheet, your server downloads that stylesheet from fonts.googleapis.com and its font files from fonts.gstatic.com once, and saves them in wp-content/cache/nodethemes-cache-ai/fonts/. Only the stylesheet address (the font names and styles your theme asks for) is sent; no visitor data is sent. After that your visitors load the fonts from your own site.

* Google Fonts: [FAQ and privacy](https://fonts.google.com/faq), [Google Privacy Policy](https://policies.google.com/privacy)

== Privacy ==

* Cached pages are stored as HTML files in wp-content/cache/nodethemes-cache-ai/. Pages are only cached for visitors who are not logged in and have no cart, so they contain no personal data.
* The settings and the change history are stored in your WordPress database.
* Deactivating the plugin removes the cached pages, the advanced-cache.php drop-in and the WP_CACHE line it added. Uninstalling also removes its settings and history.

== Changelog ==

= 1.1.4 =
* New: the Dashboard explains why pages are not being cached: a theme that does not end its pages with </html>, a cookie set on every visit (with its name), another plugin asking not to cache (DONOTCACHEPAGE), or a cache folder that is not writable.
* Updates: Dashboard > Updates always asks nodethemes.com again when its saved answer is more than a minute old, and the saved answer is cleared whenever WordPress clears its own update list.

= 1.1.3 =
* Fix: lazy loading and defer no longer change <img>, <iframe> or <script> tags that appear inside a script, style, template or textarea (for example HTML stored in a JavaScript string). Before, this could cause "SyntaxError: Unexpected identifier 'lazy'", and the safe test reported the page as broken.

= 1.1.2 =
* Updates are now delivered from nodethemes.com: new versions appear in Dashboard > Updates and can be installed with one click or with auto-updates. The download is checked (SHA-256) before it is installed.

= 1.1.1 =
* The safe test also reports the errors a page already has without the new settings, so the Pro guard can ignore them.

= 1.1.0 =
* New: host Google Fonts on your own site (an optimization, tested like the others).
* New: Database & tweaks tab: database cleanup, Heartbeat control and WooCommerce cart fragments.
* New: WP-CLI commands (wp ntca ...) and multisite support (each site has its own settings and cache).
* New: hooks for the Pro add-on (extra optimizations, extra preview checks, CDN purge).

= 1.0.0 =
* First release: page cache with fast mode, WooCommerce-safe rules, seven optimizations, a preview test in your browser, one-click rollback and exclusions.
