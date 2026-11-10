<?php
/**
 * HTML optimizations. Each one only adds attributes or removes whitespace and
 * comments, so it can be switched off again at any time.
 *
 * @package NodeThemes_Cache_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Page optimizer.
 */
class NTCA_Optimizer {

	/**
	 * Hooks for optimizations that work through WordPress instead of the HTML.
	 *
	 * @param array $s Settings in effect for this request.
	 */
	public static function hooks( $s ) {
		if ( ! empty( $s['opts']['disable_emojis'] ) ) {
			remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
			remove_action( 'wp_print_styles', 'print_emoji_styles' );
			remove_action( 'wp_enqueue_scripts', 'wp_enqueue_emoji_styles' );
			add_filter( 'emoji_svg_url', '__return_false' );
		}
	}

	/**
	 * Run the switched-on optimizations on a page.
	 *
	 * @param string $html Page HTML.
	 * @param array  $s    Settings.
	 * @return string
	 */
	public static function run( $html, $s, $preview = false ) {
		if ( defined( 'NTCA_DISABLE_OPTIMIZE' ) && NTCA_DISABLE_OPTIMIZE ) {
			return $html;
		}
		$o = $s['opts'];
		if ( ! empty( $o['font_swap'] ) ) {
			$html = self::font_swap( $html );
		}
		if ( ! empty( $o['local_fonts'] ) ) {
			$html = NTCA_Fonts::strip_preconnect( NTCA_Fonts::rewrite( $html, $preview ) );
		}
		if ( ! empty( $o['defer_js'] ) ) {
			$html = self::defer_js( $html, $s['defer_exclude'] );
		}
		if ( ! empty( $o['lazy_images'] ) ) {
			$html = self::lazy_images( $html, (int) $s['lazy_skip'] );
		}
		if ( ! empty( $o['lazy_iframes'] ) ) {
			$html = self::lazy_iframes( $html );
		}
		if ( ! empty( $o['minify_css'] ) ) {
			$html = self::minify_inline_css( $html );
		}
		$html = (string) apply_filters( 'ntca_optimize', $html, $s, $preview );
		if ( ! empty( $o['minify_html'] ) ) {
			$html = self::minify_html( $html );
		}
		return $html;
	}

	/**
	 * Read one attribute from a tag.
	 *
	 * @param string $tag  Tag HTML.
	 * @param string $name Attribute.
	 * @return string|null
	 */
	public static function attr( $tag, $name ) {
		if ( preg_match( '/\s' . preg_quote( $name, '/' ) . '\s*=\s*(["\'])(.*?)\1/is', $tag, $m ) ) {
			return $m[2];
		}
		if ( preg_match( '/\s' . preg_quote( $name, '/' ) . '\s*=\s*([^\s>]+)/is', $tag, $m ) ) {
			return $m[1];
		}
		if ( preg_match( '/\s' . preg_quote( $name, '/' ) . '(?=[\s>\/])/i', $tag ) ) {
			return '';
		}
		return null;
	}

	/**
	 * Add an attribute right after the tag name.
	 *
	 * @param string $tag  Tag HTML.
	 * @param string $name Tag name.
	 * @param string $add  Attribute text.
	 * @return string
	 */
	private static function add_attr( $tag, $name, $add ) {
		return preg_replace( '/^<' . $name . '\b/i', '<' . $name . ' ' . $add, $tag, 1 );
	}

	/**
	 * Run a replacement only on the page's own HTML, never inside <script>, <style>, <template> or <textarea>.
	 * Scripts often hold HTML in strings (for example "<img src=\\"...\\">" in JSON); changing a tag there
	 * would break the script. For <script> blocks, $on_script (if given) receives only the opening tag.
	 *
	 * @param string        $html      HTML.
	 * @param callable      $outside   Callback for the parts outside these blocks.
	 * @param callable|null $on_script Callback for the opening tag of each <script> block.
	 * @return string
	 */
	private static function outside_code( $html, $outside, $on_script = null ) {
		$parts = preg_split( '#(<script\b[^>]*>.*?</script\s*>|<style\b[^>]*>.*?</style\s*>|<template\b[^>]*>.*?</template\s*>|<textarea\b[^>]*>.*?</textarea\s*>)#is', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( ! is_array( $parts ) ) {
			return $html; // The page is too large or not valid UTF-8 for the pattern: leave it as it is.
		}
		$out = '';
		foreach ( $parts as $i => $part ) {
			if ( 0 === $i % 2 ) {
				$out .= call_user_func( $outside, $part );
			} elseif ( $on_script && preg_match( '#^(<script\b[^>]*>)(.*)$#is', $part, $m ) ) {
				$out .= call_user_func( $on_script, $m[1] ) . $m[2];
			} else {
				$out .= $part;
			}
		}
		return $out;
	}

	/**
	 * Add defer to external scripts.
	 *
	 * @param string $html    HTML.
	 * @param array  $exclude Parts of script URLs or IDs to leave alone.
	 * @return string
	 */
	public static function defer_js( $html, $exclude ) {
		return self::outside_code(
			$html,
			function ( $part ) {
				return $part;
			},
			function ( $tag ) use ( $exclude ) {
				$src = self::attr( $tag, 'src' );
				if ( null === $src || '' === $src ) {
					return $tag;
				}
				if ( null !== self::attr( $tag, 'defer' ) || null !== self::attr( $tag, 'async' ) || null !== self::attr( $tag, 'data-no-defer' ) || null !== self::attr( $tag, 'nomodule' ) ) {
					return $tag;
				}
				$type = self::attr( $tag, 'type' );
				if ( null !== $type && '' !== $type && ! in_array( strtolower( $type ), array( 'text/javascript', 'application/javascript', 'module' ), true ) ) {
					return $tag;
				}
				if ( 'module' === strtolower( (string) $type ) ) {
					return $tag; // Modules are deferred by the browser already.
				}
				$id = (string) self::attr( $tag, 'id' );
				foreach ( (array) $exclude as $ex ) {
					if ( '' !== $ex && ( false !== stripos( $src, $ex ) || false !== stripos( $id, $ex ) ) ) {
						return $tag;
					}
				}
				return self::add_attr( $tag, 'script', 'defer' );
			}
		);
	}

	/**
	 * Lazy-load images below the first few.
	 *
	 * @param string $html HTML.
	 * @param int    $skip How many images at the top of the page to leave alone.
	 * @return string
	 */
	public static function lazy_images( $html, $skip ) {
		$body = stripos( $html, '<body' );
		if ( false === $body ) {
			return $html;
		}
		$head = substr( $html, 0, $body );
		$rest = substr( $html, $body );
		$n    = 0;
		$rest = self::outside_code(
			$rest,
			function ( $part ) use ( &$n, $skip ) {
				return preg_replace_callback(
					'#<img\b[^>]*>#i',
					function ( $m ) use ( &$n, $skip ) {
				$tag = $m[0];
				++$n;
				if ( $n <= $skip ) {
					return $tag;
				}
				if ( null !== self::attr( $tag, 'loading' ) || null !== self::attr( $tag, 'data-no-lazy' ) || 'high' === strtolower( (string) self::attr( $tag, 'fetchpriority' ) ) ) {
					return $tag;
				}
				$class = (string) self::attr( $tag, 'class' );
				if ( preg_match( '/\b(no-lazy|skip-lazy)\b/i', $class ) ) {
					return $tag;
				}
				$add = 'loading="lazy"';
				if ( null === self::attr( $tag, 'decoding' ) ) {
					$add .= ' decoding="async"';
				}
				return self::add_attr( $tag, 'img', $add );
					},
					$part
				);
			}
		);
		return $head . $rest;
	}

	/**
	 * Lazy-load iframes.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function lazy_iframes( $html ) {
		return self::outside_code(
			$html,
			function ( $part ) {
				return preg_replace_callback(
					'#<iframe\b[^>]*>#i',
					function ( $m ) {
						$tag = $m[0];
						if ( null !== self::attr( $tag, 'loading' ) || null !== self::attr( $tag, 'data-no-lazy' ) ) {
							return $tag;
						}
						return self::add_attr( $tag, 'iframe', 'loading="lazy"' );
					},
					$part
				);
			}
		);
	}

	/**
	 * Google Fonts with display=swap.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function font_swap( $html ) {
		return preg_replace_callback(
			'#(https?:)?//fonts\.googleapis\.com/css2?\?[^"\'\s>]+#i',
			function ( $m ) {
				$u = $m[0];
				if ( false !== stripos( $u, 'display=' ) ) {
					return $u;
				}
				return $u . ( false !== strpos( $u, '&#038;' ) || false !== strpos( $u, '&amp;' ) ? '&#038;' : '&' ) . 'display=swap';
			},
			$html
		);
	}

	/**
	 * Minify one CSS string.
	 *
	 * @param string $css CSS.
	 * @return string
	 */
	public static function minify_css( $css ) {
		$str = array();
		$css = preg_replace_callback(
			'/("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\')/s',
			function ( $m ) use ( &$str ) {
				$str[] = $m[0];
				return '%%NTCS' . ( count( $str ) - 1 ) . '%%';
			},
			$css
		);
		$css = preg_replace( '#/\*.*?\*/#s', '', $css );
		$css = preg_replace( '/\s+/', ' ', $css );
		$css = preg_replace( '/\s*([{};,>])\s*/', '$1', $css );
		$css = preg_replace( '/:\s+/', ':', $css ); // Spaces before ":" are kept ("a :hover" is not "a:hover").
		$css = str_replace( ';}', '}', $css );
		$css = preg_replace_callback(
			'/%%NTCS(\d+)%%/',
			function ( $m ) use ( $str ) {
				return $str[ (int) $m[1] ];
			},
			$css
		);
		return trim( $css );
	}

	/**
	 * Minify the contents of inline style blocks.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function minify_inline_css( $html ) {
		return preg_replace_callback(
			'#(<style\b[^>]*>)(.*?)(</style>)#is',
			function ( $m ) {
				// Keep ": " inside selectors such as a:hover safe: minify_css only removes spaces around ":".
				return $m[1] . self::minify_css( $m[2] ) . $m[3];
			},
			$html
		);
	}

	/**
	 * Remove HTML comments and collapse whitespace between tags.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function minify_html( $html ) {
		$keep = array();
		$html = preg_replace_callback(
			'#<(pre|textarea|script|style|code)\b[^>]*>.*?</\1>#is',
			function ( $m ) use ( &$keep ) {
				$keep[] = $m[0];
				return '%%NTCA' . ( count( $keep ) - 1 ) . '%%';
			},
			$html
		);
		// Comments, except IE conditionals and markers other tools rely on.
		$html = preg_replace( '/<!--(?!\[|\s*\/?noindex|\s*ntca|\s*more\s*-->|\s*wp:)(.*?)-->/s', '', $html );
		$html = preg_replace( '/>\s{2,}</', '> <', $html );
		$html = preg_replace( '/[ \t]*\n\s*/', "\n", $html );
		$html = preg_replace( '/[ \t]{2,}/', ' ', $html );
		return preg_replace_callback(
			'/%%NTCA(\d+)%%/',
			function ( $m ) use ( $keep ) {
				return $keep[ (int) $m[1] ];
			},
			$html
		);
	}
}
