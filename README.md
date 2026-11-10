# NodeThemes Cache AI

**Free cache plugin for WordPress and WooCommerce that tests every speed change before your visitors see it.**

NodeThemes Cache AI is a page cache and speed plugin for WordPress and WooCommerce. Most speed plugins switch an option on and hope nothing breaks. This one opens your pages in your own browser with and without the new settings, compares them, and only lets you apply settings that pass. Any change can be undone with one click.

- Website: [NodeThemes Cache AI on nodethemes.com](https://nodethemes.com/product/nodethemes-cache-ai-pro/)
- All free plugins: [Free WordPress plugins from NodeThemes](https://nodethemes.com/free-plugins/)

## Features (free version)

The free plugin has no usage limits.

- Page cache for visitors who are not logged in, cleared automatically when you edit content, approve a comment, change stock, switch theme or update plugins
- WooCommerce-safe: cart, checkout and account pages, carts and logged-in users are never cached
- Fast mode (optional): cached pages are sent before WordPress loads (`advanced-cache.php` drop-in)
- Optimizations: minify HTML and inline CSS, defer JavaScript with a "Do not defer" list, lazy-load images and iframes, remove the emoji script, Google Fonts `display=swap`, and host Google Fonts on your own site
- Safe preview test: each test page is loaded twice without and once with the change, at desktop and phone width; new JavaScript errors, broken files and images and missing page parts are reported
- One-click rollback: the last 10 settings are kept in History
- Exclusions for pages, cookies and tracking parameters; separate cache for phones
- Database cleanup, Heartbeat control and WooCommerce cart fragments only when the cart has items
- WP-CLI commands (`wp ntca status | purge | fast-mode | all-off | history | db-clean`) and multisite support

## NodeThemes Cache AI Pro

The Pro add-on, sold at [nodethemes.com](https://nodethemes.com/product/nodethemes-cache-ai-pro/) as a yearly license with updates and support, adds:

- Find the cause: when a test fails, each optimization and each deferred script is tested on its own until the part that breaks is found
- AI explanation of a failed test (OpenAI, Claude, Gemini or the AI connected in WordPress)
- Click test: menus, tabs, sliders and product options are clicked during the preview
- Critical CSS
- Google PageSpeed report with before/after history and real-visitor Core Web Vitals
- Cloudflare cache purge and a CDN address
- Retest after theme and plugin updates, and a guard that restores the previous settings when visitors get a new JavaScript error

Pro is installed next to the free plugin; your settings and data stay when you upgrade. See the full **Free vs Pro** comparison table on the [product page](https://nodethemes.com/product/nodethemes-cache-ai-pro/).

## Installation

1. Download the free plugin from [nodethemes.com/free-plugins](https://nodethemes.com/free-plugins/) (no account needed).
2. In WordPress go to **Plugins → Add New Plugin → Upload Plugin**, choose the zip, click **Install Now**, then **Activate**.
3. The page cache starts right away. Open **Cache AI → Speed & safe test**, choose optimizations, click **Test these settings**, then **Apply to my live site**.

Use only one page cache plugin at a time. Requirements: WordPress 6.0+, PHP 7.4+.

## Guides

- [How to speed up WordPress: a safe step-by-step checklist](https://nodethemes.com/how-to-speed-up-wordpress/)
- [WordPress Core Web Vitals: LCP, CLS and INP explained](https://nodethemes.com/wordpress-core-web-vitals/)
- [Defer JavaScript in WordPress without breaking your site](https://nodethemes.com/defer-javascript-wordpress/)

## Support

- E-mail: nodethemes.com@gmail.com
- Telegram: [t.me/nodethemes](https://t.me/nodethemes)
- Contact page: [nodethemes.com/contact](https://nodethemes.com/contact/)

## License

GPL-2.0-or-later. Made by [NodeThemes](https://nodethemes.com/) – WordPress themes and AI plugins.
