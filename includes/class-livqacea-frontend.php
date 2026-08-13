<?php
/**
 * Frontend accessibility remediation.
 *
 * Applies WCAG 2.2 AA / EAA fixes at render time without touching the database
 * content - all corrections are ephemeral output filters/actions.
 *
 * @package LivQ_AccessFix
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class LIVQACEA_Frontend
 */
class LIVQACEA_Frontend {

	/**
	 * Plugin settings passed from LIVQACEA_Main.
	 *
	 * @var array<string, bool>
	 */
	private $options;

	/**
	 * Output buffer nesting level captured when our own buffer is started.
	 *
	 * Zero means we never started one. Storing the level lets end_buffer() close
	 * exactly our buffer instead of blindly flushing whatever sits on top of the
	 * stack - which could belong to another plugin.
	 *
	 * @var int
	 */
	private $buffer_level = 0;

	/**
	 * Constructor - registers hooks according to active options.
	 *
	 * @param array<string, bool> $options Sanitised plugin options.
	 */
	public function __construct( array $options ) {
		$this->options = $options;

		// Only run on the public-facing side.
		if ( is_admin() ) {
			return;
		}

		$this->register_hooks();
	}

	/**
	 * Attaches WordPress actions/filters based on which modules are enabled.
	 *
	 * @return void
	 */
	private function register_hooks(): void {

		// Start the output buffer if any buffer-dependent module is active.
		// Centralising the condition here avoids starting multiple buffers.
		$needs_buffer = ! empty( $this->options['fix_external_links'] )
			|| ! empty( $this->options['fix_nameless_links'] )
			|| ! empty( $this->options['fix_button_labels'] )
			|| ! empty( $this->options['fix_iframe_titles'] )
			|| ! empty( $this->options['fix_input_labels'] )
			|| ! empty( $this->options['fix_autocomplete'] );

		if ( $needs_buffer ) {
			add_action( 'template_redirect', array( $this, 'start_buffer' ), 1 );
			add_action( 'shutdown', array( $this, 'end_buffer' ), 0 );
		}

		// WCAG 2.4.1 - Skip navigation link for keyboard users.
		if ( ! empty( $this->options['inject_skip_link'] ) ) {
			add_action( 'wp_body_open', array( $this, 'inject_skip_link' ), 1 );
		}

		// WCAG 1.1.1 - Decorative images must have empty alt text, not file names.
		if ( ! empty( $this->options['fix_image_alt'] ) ) {
			add_filter( 'wp_get_attachment_image_attributes', array( $this, 'fix_image_alt' ), 10, 2 );
			// Also intercept Core Image blocks rendered in FSE / block themes.
			add_filter( 'render_block_core/image', array( $this, 'fix_block_image' ), 10, 2 );
		}

		// WCAG 2.4.11 - Focus styles, plus the .screen-reader-text utility.
		// The utility class must ship whenever a module *emits* that class, not
		// only when focus CSS is enabled: otherwise disabling focus CSS on a theme
		// that does not define .screen-reader-text would print the hidden
		// "(opens in a new tab)" notice as visible text all over the site.
		if ( ! empty( $this->options['inject_focus_css'] )
			|| ! empty( $this->options['inject_skip_link'] )
			|| ! empty( $this->options['fix_external_links'] ) ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_inline_styles' ) );
		}

		// WCAG 4.1.2 - Menu items with sub-menus need aria-haspopup + aria-expanded.
		if ( ! empty( $this->options['menu_aria_helper'] ) ) {
			add_filter( 'nav_menu_link_attributes', array( $this, 'add_menu_aria_attrs' ), 10, 4 );
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_menu_aria_script' ) );
		}

		// WCAG 3.1.1 - <html lang> must be present. Silent always-on guard.
		add_filter( 'language_attributes', array( $this, 'fix_html_lang' ), 10, 2 );
	}

	// -----------------------------------------------------------------------
	// Output Buffer - global page interception
	// -----------------------------------------------------------------------

	/**
	 * Starts PHP output buffering.
	 *
	 * @return void
	 */
	public function start_buffer(): void {
		if ( is_admin() ) {
			return;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}
		if ( wp_doing_ajax() ) {
			return;
		}
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return;
		}
		if ( wp_doing_cron() ) {
			return;
		}

		// ── Non-HTML responses ─────────────────────────────────────────────────
		// template_redirect fires *before* core branches off to feeds, robots.txt,
		// favicons and XML sitemaps, so without these guards the HTML fixers would
		// rewrite RSS/XML payloads and produce invalid feeds.
		if ( is_feed() || is_robots() || is_trackback() ) {
			return;
		}
		if ( function_exists( 'is_favicon' ) && is_favicon() ) {
			return;
		}

		// ── Page Builder guards ────────────────────────────────────────────────
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['et_fb'] ) || isset( $_GET['et_bfb'] ) ) {
			return; // Divi.
		}
		if ( isset( $_GET['elementor-preview'] ) ) {
			return; // Elementor.
		}
		if ( isset( $_GET['fl_builder'] ) ) {
			return; // Beaver Builder.
		}
		if ( isset( $_GET['bricks'] ) && 'run' === $_GET['bricks'] ) {
			return; // Bricks.
		}
		if ( isset( $_GET['ct_builder'] ) || isset( $_GET['breakdance_editor'] ) ) {
			return; // Oxygen / Breakdance.
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ob_start( array( $this, 'sanitize_entire_page' ) ) ) {
			$this->buffer_level = ob_get_level();
		}
	}

	/**
	 * Closes our own output buffer and flushes it through the sanitize callback.
	 *
	 * Only buffers at or above the level we opened are closed, so a buffer opened
	 * by another plugin before ours is never flushed out from under it.
	 *
	 * @return void
	 */
	public function end_buffer(): void {
		if ( 0 === $this->buffer_level ) {
			return;
		}

		while ( ob_get_level() >= $this->buffer_level ) {
			$level = ob_get_level();

			// Bail out if the buffer refuses to close (or the level does not drop)
			// rather than spinning forever on a non-removable buffer.
			if ( ! ob_end_flush() || ob_get_level() >= $level ) {
				break;
			}
		}

		$this->buffer_level = 0;
	}

	/**
	 * Coordinator - routes the buffered HTML through each enabled fixer.
	 *
	 * Each fixer is a private method that receives and returns the full HTML
	 * string. Order matters: nameless links must run BEFORE external links.
	 * The external-link fixer appends a visible-to-the-parser notice span, which
	 * would make every icon link look like it already has visible text - leaving
	 * "(opens in a new tab)" as its only accessible name.
	 *
	 * @param string $html Full rendered HTML of the page.
	 * @return string Modified HTML.
	 */
	public function sanitize_entire_page( string $html ): string {
		if ( empty( $html ) || ! self::looks_like_html( $html ) ) {
			return $html;
		}

		if ( ! empty( $this->options['fix_nameless_links'] ) ) {
			$html = $this->fix_nameless_links( $html );
		}
		if ( ! empty( $this->options['fix_external_links'] ) ) {
			$html = $this->fix_external_links( $html );
		}
		if ( ! empty( $this->options['fix_button_labels'] ) ) {
			$html = $this->fix_button_labels( $html );
		}
		if ( ! empty( $this->options['fix_iframe_titles'] ) ) {
			$html = $this->fix_iframe_titles( $html );
		}
		if ( ! empty( $this->options['fix_input_labels'] ) ) {
			$html = $this->fix_input_labels( $html );
		}
		// Runs after the label fixer: both rewrite field tags, and autocomplete
		// re-parses the HTML the label pass has already produced.
		if ( ! empty( $this->options['fix_autocomplete'] ) ) {
			$html = $this->fix_autocomplete( $html );
		}

		// Allow other modules (e.g. WooCommerce) to add their own HTML fixes.
		return apply_filters( 'livqacea_sanitized_html', $html );
	}

	// -----------------------------------------------------------------------
	// Buffer helpers
	// -----------------------------------------------------------------------

	/**
	 * Second line of defence against rewriting non-HTML responses.
	 *
	 * The request-level guards in start_buffer() catch feeds, robots.txt and
	 * sitemaps; this catches anything else a theme or plugin may render on
	 * template_redirect (JSON endpoints, XML exports, plain text).
	 *
	 * @param string $html Buffered output.
	 * @return bool True when the payload looks like a full HTML document.
	 */
	private static function looks_like_html( string $html ): bool {
		$head = ltrim( substr( $html, 0, 200 ) );

		if ( '' === $head ) {
			return false;
		}
		if ( 0 === stripos( $head, '<?xml' ) ) {
			return false;
		}
		if ( '{' === $head[0] || '[' === $head[0] ) {
			return false;
		}

		return false !== stripos( $html, '<html' ) || false !== stripos( $html, '<body' );
	}

	/**
	 * Wrapper around preg_replace_callback() that can never blank the page.
	 *
	 * PCRE returns null on failure - typically PREG_BACKTRACK_LIMIT_ERROR on very
	 * large documents. Casting that null to string (the previous behaviour) threw
	 * away the entire page and served an empty response, so we fall back to the
	 * untouched subject instead.
	 *
	 * @param string   $pattern  Regex pattern.
	 * @param callable $callback Replacement callback.
	 * @param string   $subject  Subject string.
	 * @return string Replaced string, or the original subject when PCRE fails.
	 */
	private static function safe_replace( string $pattern, callable $callback, string $subject ): string {
		$result = preg_replace_callback( $pattern, $callback, $subject );

		return is_string( $result ) ? $result : $subject;
	}

	/**
	 * Rebuilds an opening tag with one extra attribute appended.
	 *
	 * Handles XHTML-style self-closing tags: naively appending before the ">"
	 * produced `<input ... / aria-label="…">`, leaving the slash stranded as a
	 * bogus attribute and failing HTML validation.
	 *
	 * @param string $tag_name    Tag name without brackets ('input', 'iframe'…).
	 * @param string $attrs       Raw attribute string captured from the tag.
	 * @param string $extra_attrs Attribute to append, already escaped.
	 * @return string Well-formed opening tag.
	 */
	private static function build_tag( string $tag_name, string $attrs, string $extra_attrs ): string {
		$attrs        = rtrim( $attrs );
		$self_closing = false;

		if ( '' !== $attrs && '/' === substr( $attrs, -1 ) ) {
			$self_closing = true;
			$attrs        = rtrim( substr( $attrs, 0, -1 ) );
		}

		return '<' . $tag_name . $attrs . ' ' . $extra_attrs . ( $self_closing ? ' />' : '>' );
	}

	// -----------------------------------------------------------------------
	// Buffer fixers (private)
	// -----------------------------------------------------------------------

	/**
	 * Adds screen-reader notice and noopener to every target="_blank" link.
	 *
	 * WCAG 2.4.4 (Link Purpose) / Technique G201.
	 *
	 * @param string $html Full page HTML.
	 * @return string Modified HTML.
	 */
	private function fix_external_links( string $html ): string {
		if ( false === stripos( $html, '_blank' ) ) {
			return $html;
		}

		$notice_text = __( '(opens in a new tab)', 'livq-accessfix' );
		$pattern     = '/<a\b([^>]*?target=["\']_blank["\'][^>]*?)>(.*?)<\/a>/is';

		return self::safe_replace(
			$pattern,
			function ( array $matches ) use ( $notice_text ): string {
				$attrs = $matches[1];
				$inner = $matches[2];

				// Idempotency - skip if notice already injected.
				if ( false !== strpos( $inner, 'screen-reader-text' ) ) {
					return $matches[0];
				}

				// Merge rel="noopener noreferrer".
				if ( preg_match( '/\brel=(["\'])([^"\']*)\1/i', $attrs, $rel_match ) ) {
					$rel_values = array_filter( preg_split( '/\s+/', $rel_match[2] ) );
					if ( ! in_array( 'noopener', $rel_values, true ) ) {
						$rel_values[] = 'noopener';
					}
					if ( ! in_array( 'noreferrer', $rel_values, true ) ) {
						$rel_values[] = 'noreferrer';
					}
					$new_rel = 'rel=' . $rel_match[1] . implode( ' ', $rel_values ) . $rel_match[1];
					$attrs   = preg_replace( '/\brel=(["\'])[^"\']*\1/i', $new_rel, $attrs );
				} else {
					$attrs .= ' rel="noopener noreferrer"';
				}

				// An explicit accessible name overrides the link content, so a
				// notice span inside the anchor would never be announced. Merge
				// the notice into the existing aria-label instead.
				if ( preg_match( '/\baria-label=(["\'])(.*?)\1/is', $attrs, $al ) ) {
					$escaped_notice = esc_attr( $notice_text );

					if ( false === strpos( $al[2], $escaped_notice ) ) {
						$merged = trim( $al[2] . ' ' . $escaped_notice );
						$attrs  = str_replace(
							$al[0],
							'aria-label=' . $al[1] . $merged . $al[1],
							$attrs
						);
					}

					return '<a' . $attrs . '>' . $inner . '</a>';
				}

				$span = sprintf(
					' <span class="screen-reader-text">%s</span>',
					esc_html( $notice_text )
				);

				return '<a' . $attrs . '>' . $inner . $span . '</a>';
			},
			$html
		);
	}

	/**
	 * Adds aria-label to links whose accessible name is empty.
	 *
	 * Covers two common patterns:
	 *  - <a href="…"><img alt=""></a>   (sponsor / partner logos)
	 *  - <a href="…"><svg aria-hidden="true">…</svg></a>  (social icon links)
	 *
	 * Label derivation priority:
	 *  1. img[title]        - intentional tooltip set in media library
	 *  2. img[alt] non-empty - meaningful alt already present
	 *  3. a[title]          - tooltip on the link itself
	 *  4. Social domain map - deterministic brand name from href
	 *  5. href hostname     - capitalised first segment (generic fallback)
	 *
	 * WCAG 2.4.4 / 4.1.2
	 *
	 * @param string $html Full page HTML.
	 * @return string Modified HTML.
	 */
	private function fix_nameless_links( string $html ): string {
		if ( false === stripos( $html, '<a' ) ) {
			return $html;
		}

		$new_tab_notice = __( '(opens in a new tab)', 'livq-accessfix' );

		return self::safe_replace(
			'/<a\b([^>]*)>(.*?)<\/a>/is',
			function ( array $m ) use ( $new_tab_notice ): string {
				$attrs = $m[1];
				$inner = $m[2];

				// Skip anchors without href (in-page anchors, JS hooks).
				if ( ! preg_match( '/\bhref=["\']([^"\']*)["\']/', $attrs, $href_m ) ) {
					return $m[0];
				}

				// Skip if an accessible name attribute already exists.
				if ( preg_match( '/\baria-(?:label|labelledby)=/i', $attrs ) ) {
					return $m[0];
				}

				// Skip if the link contains non-empty visible text.
				$visible_text = trim( wp_strip_all_tags( $inner ) );
				if ( '' !== $visible_text ) {
					return $m[0];
				}

				// Derive the best available label.
				$label = '';

				if ( preg_match( '/<img\b[^>]*\btitle=["\']([^"\']+)["\']/', $inner, $img_t ) ) {
					$label = $img_t[1];
				} elseif ( preg_match( '/<img\b[^>]*\balt=["\']([^"\']+)["\']/', $inner, $img_a ) ) {
					$label = $img_a[1];
				} elseif ( preg_match( '/\btitle=["\']([^"\']+)["\']/', $attrs, $a_t ) ) {
					$label = $a_t[1];
				} else {
					$label = self::label_from_href( $href_m[1] );
				}

				if ( '' === $label ) {
					return $m[0];
				}

				// Append "opens in a new tab" for _blank links (aria-label
				// overrides inner content, so the notice span won't be read).
				if ( preg_match( '/\btarget=["\']_blank["\']/', $attrs ) ) {
					$label .= ' ' . $new_tab_notice;
				}

				return '<a' . $attrs . ' aria-label="' . esc_attr( $label ) . '">' . $inner . '</a>';
			},
			$html
		);
	}

	/**
	 * Adds aria-label to icon-only buttons whose accessible name is empty.
	 *
	 * The counterpart of fix_nameless_links() for <button>: hamburger toggles,
	 * search and close controls, carousel arrows and back-to-top buttons are
	 * almost always an icon font or an inline SVG with no text at all, which
	 * screen readers announce as just "button".
	 *
	 * The label is derived from the purpose words found in the button's own
	 * class/id and in the class names of its icon child - "menu-toggle",
	 * "fa fa-search", "swiper-button-next". A button whose purpose cannot be
	 * recognised is left untouched: a wrong name is worse than none, and the
	 * Scanner still reports it.
	 *
	 * WCAG 4.1.2
	 *
	 * @param string $html Full page HTML.
	 * @return string Modified HTML.
	 */
	private function fix_button_labels( string $html ): string {
		if ( false === stripos( $html, '<button' ) ) {
			return $html;
		}

		$map = self::get_button_label_map();

		return self::safe_replace(
			'/<button\b([^>]*)>(.*?)<\/button>/is',
			static function ( array $m ) use ( $map ): string {
				$attrs = $m[1];
				$inner = $m[2];

				// Skip if an accessible name attribute already exists.
				if ( preg_match( '/\baria-(?:label|labelledby)=/i', $attrs ) ) {
					return $m[0];
				}

				// title= is announced when nothing else names the button.
				if ( preg_match( '/\btitle=["\'][^"\']+["\']/i', $attrs ) ) {
					return $m[0];
				}

				// Any text content - including an SVG <title> - already names it.
				if ( '' !== trim( wp_strip_all_tags( $inner ) ) ) {
					return $m[0];
				}

				// An image with meaningful alt text names the button too, and
				// strip_tags() cannot see it because alt is an attribute.
				if ( preg_match( '/<img\b[^>]*\balt=["\'][^"\']+["\']/i', $inner ) ) {
					return $m[0];
				}

				// Same for an SVG that labels itself.
				if ( preg_match( '/<svg\b[^>]*\baria-label(?:ledby)?=/i', $inner ) ) {
					return $m[0];
				}

				$label = self::label_from_button( $attrs, $inner, $map );

				if ( '' === $label ) {
					return $m[0];
				}

				return '<button' . $attrs . ' aria-label="' . esc_attr( $label ) . '">' . $inner . '</button>';
			},
			$html
		);
	}

	/**
	 * The purpose-word to label map used by the button fixer.
	 *
	 * Ordered from most to least specific: "menu-close" has to resolve to Close,
	 * not to Menu, so the close entry is tested first. Filterable so a site can
	 * teach the fixer its theme's own icon vocabulary.
	 *
	 * @return array<string, string> Purpose word => translated label.
	 */
	private static function get_button_label_map(): array {
		$map = array(
			'close'         => __( 'Close', 'livq-accessfix' ),
			'dismiss'       => __( 'Close', 'livq-accessfix' ),
			'search'        => __( 'Search', 'livq-accessfix' ),
			'hamburger'     => __( 'Menu', 'livq-accessfix' ),
			'menu'          => __( 'Menu', 'livq-accessfix' ),
			'cart'          => __( 'Cart', 'livq-accessfix' ),
			'basket'        => __( 'Cart', 'livq-accessfix' ),
			'wishlist'      => __( 'Wishlist', 'livq-accessfix' ),
			'account'       => __( 'Account', 'livq-accessfix' ),
			'login'         => __( 'Log in', 'livq-accessfix' ),
			'previous'      => __( 'Previous', 'livq-accessfix' ),
			'prev'          => __( 'Previous', 'livq-accessfix' ),
			'next'          => __( 'Next', 'livq-accessfix' ),
			'play'          => __( 'Play', 'livq-accessfix' ),
			'pause'         => __( 'Pause', 'livq-accessfix' ),
			'mute'          => __( 'Mute', 'livq-accessfix' ),
			'share'         => __( 'Share', 'livq-accessfix' ),
			'print'         => __( 'Print', 'livq-accessfix' ),
			'filter'        => __( 'Filter', 'livq-accessfix' ),
			'copy'          => __( 'Copy', 'livq-accessfix' ),
			'back to top'   => __( 'Back to top', 'livq-accessfix' ),
			'scroll to top' => __( 'Back to top', 'livq-accessfix' ),
			'go to top'     => __( 'Back to top', 'livq-accessfix' ),
			'scroll top'    => __( 'Back to top', 'livq-accessfix' ),
			'scroll up'     => __( 'Back to top', 'livq-accessfix' ),
			'totop'         => __( 'Back to top', 'livq-accessfix' ),
			'expand'        => __( 'Expand', 'livq-accessfix' ),
			'collapse'      => __( 'Collapse', 'livq-accessfix' ),
			'zoom'          => __( 'Zoom', 'livq-accessfix' ),
			'fullscreen'    => __( 'Full screen', 'livq-accessfix' ),
			'download'      => __( 'Download', 'livq-accessfix' ),
			'edit'          => __( 'Edit', 'livq-accessfix' ),
			'delete'        => __( 'Delete', 'livq-accessfix' ),
			'remove'        => __( 'Remove', 'livq-accessfix' ),
			'quickview'     => __( 'Quick view', 'livq-accessfix' ),
			'compare'       => __( 'Compare', 'livq-accessfix' ),
			'darkmode'      => __( 'Toggle dark mode', 'livq-accessfix' ),
			'lightbox'      => __( 'Open image', 'livq-accessfix' ),
			'subscribe'     => __( 'Subscribe', 'livq-accessfix' ),
			'submit'        => __( 'Submit', 'livq-accessfix' ),
		);

		/**
		 * Filters the purpose-word map used to name icon-only buttons.
		 *
		 * @param array<string, string> $map Purpose word => label.
		 */
		return (array) apply_filters( 'livqacea_button_label_map', $map );
	}

	/**
	 * Derives a label for an icon-only button from its markup.
	 *
	 * Both the button's own attributes and its children are searched: the
	 * purpose word sits on the button ("menu-toggle") about as often as on the
	 * icon inside it ("<i class='fa fa-search'>").
	 *
	 * @param string                $attrs Attribute string of the button tag.
	 * @param string                $inner Inner HTML of the button.
	 * @param array<string, string> $map   Purpose word => label map.
	 * @return string Label, or '' when the purpose cannot be recognised.
	 */
	private static function label_from_button( string $attrs, string $inner, array $map ): string {
		$haystack = '';

		if ( preg_match( '/\bclass=["\']([^"\']*)["\']/i', $attrs, $cls ) ) {
			$haystack .= ' ' . $cls[1];
		}
		if ( preg_match( '/\bid=["\']([^"\']*)["\']/i', $attrs, $id ) ) {
			$haystack .= ' ' . $id[1];
		}
		if ( preg_match_all( '/\bclass=["\']([^"\']*)["\']/i', $inner, $icons ) ) {
			$haystack .= ' ' . implode( ' ', $icons[1] );
		}

		if ( '' === trim( $haystack ) ) {
			return '';
		}

		// Two normalised forms of the same markup: separators turned into spaces,
		// so "swiper-button-next" yields the token "next", and separators removed
		// entirely, so "dark-mode-toggle" still matches the glued word "darkmode".
		$spaced = strtolower( (string) preg_replace( '/[^a-zA-Z0-9]+/', ' ', $haystack ) );
		$glued  = str_replace( ' ', '', $spaced );

		foreach ( $map as $word => $label ) {
			$word = strtolower( trim( (string) $word ) );

			if ( '' === $word ) {
				continue;
			}

			// Whole-token match: "close" must not fire on "disclosure".
			if ( preg_match( '/\b' . preg_quote( $word, '/' ) . '\b/', $spaced ) ) {
				return (string) $label;
			}

			// Glued match, only for words long enough that an accidental substring
			// hit is implausible. Keeps "menu"/"cart"/"edit" strictly token-based.
			$glued_word = str_replace( ' ', '', $word );

			if ( strlen( $glued_word ) > 5 && false !== strpos( $glued, $glued_word ) ) {
				return (string) $label;
			}
		}

		return '';
	}

	/**
	 * Adds a title attribute to every <iframe> that is missing one.
	 *
	 * Screen readers announce untitled iframes as "frame" with no context.
	 * Title derivation uses a src-domain map (YouTube, Maps, Calendly, etc.)
	 * with a generic translatable fallback.
	 *
	 * WCAG 4.1.2
	 *
	 * @param string $html Full page HTML.
	 * @return string Modified HTML.
	 */
	private function fix_iframe_titles( string $html ): string {
		if ( false === stripos( $html, '<iframe' ) ) {
			return $html;
		}

		$src_map = array(
			'youtube.com'     => __( 'YouTube Video', 'livq-accessfix' ),
			'youtu.be'        => __( 'YouTube Video', 'livq-accessfix' ),
			'vimeo.com'       => __( 'Vimeo Video', 'livq-accessfix' ),
			'google.com/maps' => __( 'Interactive Map', 'livq-accessfix' ),
			'maps.google'     => __( 'Interactive Map', 'livq-accessfix' ),
			'calendly.com'    => __( 'Appointment Calendar', 'livq-accessfix' ),
			'iubenda.com'     => __( 'Cookie Policy', 'livq-accessfix' ),
			'facebook.com'    => __( 'Facebook Content', 'livq-accessfix' ),
			'instagram.com'   => __( 'Instagram Content', 'livq-accessfix' ),
			'twitter.com'     => __( 'Twitter / X Content', 'livq-accessfix' ),
			'x.com'           => __( 'Twitter / X Content', 'livq-accessfix' ),
			'spotify.com'     => __( 'Spotify Player', 'livq-accessfix' ),
			'soundcloud.com'  => __( 'SoundCloud Player', 'livq-accessfix' ),
			'paypal.com'      => __( 'PayPal Payment', 'livq-accessfix' ),
			'typeform.com'    => __( 'Form', 'livq-accessfix' ),
			'forms.google'    => __( 'Google Form', 'livq-accessfix' ),
			'docs.google'     => __( 'Google Document', 'livq-accessfix' ),
		);

		$fallback = __( 'Embedded content', 'livq-accessfix' );

		return self::safe_replace(
			'/<iframe\b([^>]*)>/i',
			function ( array $m ) use ( $src_map, $fallback ): string {
				$attrs = $m[1];

				// Already has a title - respect it.
				if ( preg_match( '/\btitle=["\'][^"\']+["\']/', $attrs ) ) {
					return $m[0];
				}

				$title = $fallback;

				if ( preg_match( '/\bsrc=["\']([^"\']*)["\']/', $attrs, $src_m ) ) {
					foreach ( $src_map as $domain => $label ) {
						if ( false !== stripos( $src_m[1], $domain ) ) {
							$title = $label;
							break;
						}
					}
				}

				return self::build_tag( 'iframe', $attrs, 'title="' . esc_attr( $title ) . '"' );
			},
			$html
		);
	}

	/**
	 * Adds aria-label to form fields that lack an accessible label.
	 *
	 * Only fires when ALL of these conditions hold:
	 *  - The field has no aria-label or aria-labelledby attribute.
	 *  - The field's id is not referenced by any <label for="…"> on the page.
	 *  - The field is an interactive type (not hidden/submit/button/reset/image).
	 *
	 * Label derivation: placeholder → name attribute (humanised) → nothing.
	 * If no source is available the field is left untouched.
	 *
	 * WCAG 1.3.1 / 3.3.2
	 *
	 * @param string $html Full page HTML.
	 * @return string Modified HTML.
	 */
	private function fix_input_labels( string $html ): string {
		if ( false === stripos( $html, '<input' )
			&& false === stripos( $html, '<textarea' )
			&& false === stripos( $html, '<select' ) ) {
			return $html;
		}

		// Build set of IDs already covered by <label for="…">.
		$labeled_ids = array();
		if ( preg_match_all( '/<label\b[^>]*\bfor=["\']([^"\']+)["\'][^>]*>/i', $html, $lm ) ) {
			$labeled_ids = array_flip( $lm[1] );
		}

		// Byte ranges of every <label>…</label> block, so wrapped fields can be
		// recognised too. Those already have an accessible name from the label
		// text; adding aria-label would override the *visible* label and break
		// WCAG 2.5.3 (Label in Name).
		$label_ranges = self::collect_label_ranges( $html );

		// All three field types are handled in one pass: the byte offsets used to
		// test containment stay valid only against the string they were computed
		// from, and each rewrite shifts everything after it.
		$found = preg_match_all( '/<(input|textarea|select)\b([^>]*)>/i', $html, $matches, PREG_OFFSET_CAPTURE );

		if ( ! $found ) {
			return $html;
		}

		$out    = '';
		$cursor = 0;

		foreach ( $matches[0] as $i => $match ) {
			$full   = $match[0];
			$offset = $match[1];
			$tag    = strtolower( $matches[1][ $i ][0] );
			$attrs  = $matches[2][ $i ][0];

			$replacement = self::is_inside_label( $offset, $label_ranges )
				? $full
				: $this->add_aria_label_to_field( $full, $attrs, $labeled_ids, $tag );

			$out   .= substr( $html, $cursor, $offset - $cursor ) . $replacement;
			$cursor = $offset + strlen( $full );
		}

		return $out . substr( $html, $cursor );
	}

	/**
	 * Collects the [start, end] byte range of every <label> element on the page.
	 *
	 * @param string $html Full page HTML.
	 * @return array<int, array{0:int, 1:int}> List of ranges.
	 */
	private static function collect_label_ranges( string $html ): array {
		$ranges = array();

		if ( preg_match_all( '/<label\b[^>]*>.*?<\/label>/is', $html, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[0] as $match ) {
				$ranges[] = array( $match[1], $match[1] + strlen( $match[0] ) );
			}
		}

		return $ranges;
	}

	/**
	 * Tells whether a byte offset falls inside one of the given label ranges.
	 *
	 * @param int                             $offset Offset of the field tag.
	 * @param array<int, array{0:int, 1:int}> $ranges Label ranges.
	 * @return bool
	 */
	private static function is_inside_label( int $offset, array $ranges ): bool {
		foreach ( $ranges as $range ) {
			if ( $offset > $range[0] && $offset < $range[1] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Helper - evaluates one field tag and injects aria-label if warranted.
	 *
	 * @param string             $full_tag   The full original tag string.
	 * @param string             $attrs      The attribute string inside the tag.
	 * @param array<string, int> $labeled_ids Set of IDs already covered by a <label>.
	 * @param string             $tag_name   'input', 'textarea', or 'select'.
	 * @return string Modified or original tag.
	 */
	private function add_aria_label_to_field( string $full_tag, string $attrs, array $labeled_ids, string $tag_name ): string {
		// Skip if already has an accessible name.
		if ( preg_match( '/\baria-(?:label|labelledby)=/i', $attrs ) ) {
			return $full_tag;
		}

		// For <input> skip non-interactive types.
		if ( 'input' === $tag_name && preg_match( '/\btype=["\'](?:hidden|submit|button|reset|image)["\']/i', $attrs ) ) {
			return $full_tag;
		}

		// Skip if already associated with a <label>.
		if ( preg_match( '/\bid=["\']([^"\']+)["\']/', $attrs, $id_m ) ) {
			if ( isset( $labeled_ids[ $id_m[1] ] ) ) {
				return $full_tag;
			}
		}

		// Derive label from placeholder first, then name.
		$label = '';

		if ( preg_match( '/\bplaceholder=["\']([^"\']+)["\']/', $attrs, $ph_m ) ) {
			$label = $ph_m[1];
		} elseif ( preg_match( '/\bname=["\']([^"\']+)["\']/', $attrs, $nm_m ) ) {
			$label = self::humanise_field_name( $nm_m[1] );
		}

		if ( '' === $label ) {
			return $full_tag;
		}

		return self::build_tag( $tag_name, $attrs, 'aria-label="' . esc_attr( $label ) . '"' );
	}

	/**
	 * Turns a form field's name attribute into a human-readable label.
	 *
	 * Short cryptic names are mapped explicitly: WordPress's own search field is
	 * name="s", which the previous naive ucfirst() turned into the accessible
	 * name "S". Anything still shorter than three characters is rejected - no
	 * label at all is better than a meaningless one.
	 *
	 * @param string $name Raw name attribute value.
	 * @return string Human-readable label, or '' when none can be derived.
	 */
	private static function humanise_field_name( string $name ): string {
		$known = array(
			's'        => __( 'Search', 'livq-accessfix' ),
			'q'        => __( 'Search', 'livq-accessfix' ),
			'search'   => __( 'Search', 'livq-accessfix' ),
			'email'    => __( 'Email', 'livq-accessfix' ),
			'author'   => __( 'Name', 'livq-accessfix' ),
			'url'      => __( 'Website', 'livq-accessfix' ),
			'comment'  => __( 'Comment', 'livq-accessfix' ),
			'quantity' => __( 'Quantity', 'livq-accessfix' ),
		);

		$key = strtolower( trim( $name ) );

		if ( isset( $known[ $key ] ) ) {
			return $known[ $key ];
		}

		$label = trim( str_replace( array( '_', '-', '[', ']' ), array( ' ', ' ', '', '' ), $name ) );

		if ( strlen( $label ) < 3 ) {
			return '';
		}

		return ucfirst( $label );
	}

	// -----------------------------------------------------------------------
	// Autocomplete (WCAG 1.3.5 Identify Input Purpose)
	// -----------------------------------------------------------------------

	/**
	 * Adds autocomplete attributes to fields that collect information about the
	 * user filling the form.
	 *
	 * WCAG 1.3.5 (AA) requires the purpose of those fields to be programmatically
	 * determinable; the autocomplete attribute is the mechanism browsers, password
	 * managers and personalisation tools actually read.
	 *
	 * Deliberately conservative:
	 *  - any author-set autocomplete is respected, autocomplete="off" included;
	 *  - only names that resolve to a known input purpose are touched, so search
	 *    boxes, quantity spinners and coupon codes are never given one;
	 *  - the optional "billing"/"shipping" section tokens are not emitted. The
	 *    bare field name already satisfies 1.3.5 and cannot produce a value the
	 *    HTML autofill grammar would reject.
	 *
	 * @param string $html Full page HTML.
	 * @return string Modified HTML.
	 */
	private function fix_autocomplete( string $html ): string {
		if ( false === stripos( $html, '<input' )
			&& false === stripos( $html, '<textarea' )
			&& false === stripos( $html, '<select' ) ) {
			return $html;
		}

		return self::safe_replace(
			'/<(input|textarea|select)\b([^>]*)>/i',
			static function ( array $m ): string {
				$tag   = strtolower( $m[1] );
				$attrs = $m[2];

				// Respect the author's intent, including an explicit "off".
				if ( preg_match( '/\bautocomplete=/i', $attrs ) ) {
					return $m[0];
				}

				$type = '';
				if ( preg_match( '/\btype=["\']([^"\']*)["\']/i', $attrs, $t ) ) {
					$type = strtolower( trim( $t[1] ) );
				}

				$name = '';
				if ( preg_match( '/\bname=["\']([^"\']*)["\']/i', $attrs, $n ) ) {
					$name = $n[1];
				} elseif ( preg_match( '/\bid=["\']([^"\']*)["\']/i', $attrs, $i ) ) {
					$name = $i[1];
				}

				$token = self::autocomplete_token( $name, $type, $tag );

				if ( '' === $token ) {
					return $m[0];
				}

				return self::build_tag( $tag, $attrs, 'autocomplete="' . esc_attr( $token ) . '"' );
			},
			$html
		);
	}

	/**
	 * Maps a form field to its HTML autofill field name, or '' when unknown.
	 *
	 * Public because the Scanner reports fields that should carry an autocomplete
	 * attribute and must agree with the fixer on which ones those are.
	 *
	 * @param string $raw_name Raw name (or id) attribute value.
	 * @param string $type     Lowercased type attribute, '' when absent.
	 * @param string $tag      'input', 'textarea' or 'select'.
	 * @return string Autofill field name, or '' when the purpose is unknown.
	 */
	public static function autocomplete_token( string $raw_name, string $type = '', string $tag = 'input' ): string {
		// Types that never carry user information, plus the ones where a guessed
		// autocomplete would actively get in the way (search, file pickers).
		$skip_types = array( 'hidden', 'submit', 'button', 'reset', 'image', 'checkbox', 'radio', 'file', 'range', 'color', 'search' );

		if ( 'input' === $tag && in_array( $type, $skip_types, true ) ) {
			return '';
		}

		$key = strtolower( trim( $raw_name ) );

		// Form builders nest the meaningful name inside brackets - "fields[email]",
		// "data[User][last_name]". Take the innermost segment that is not a bare
		// index, so "wpforms[fields][0]" still falls back to the base name.
		if ( preg_match_all( '/\[([^\]]*)\]/', $key, $segments ) ) {
			$bracket_pos = (int) strpos( $key, '[' );
			$key         = substr( $key, 0, $bracket_pos );

			foreach ( array_reverse( $segments[1] ) as $segment ) {
				$segment = trim( $segment );

				if ( '' !== $segment && ! ctype_digit( $segment ) ) {
					$key = $segment;
					break;
				}
			}
		}

		// Normalise separators, then strip the billing_/shipping_ prefixes that
		// WooCommerce and most checkout plugins put in front of standard names.
		$key = trim( (string) preg_replace( '/[^a-z0-9]+/', '_', $key ), '_' );
		$key = (string) preg_replace( '/^(?:billing|shipping|order)_/', '', $key );

		$map = self::get_autocomplete_map();

		if ( isset( $map[ $key ] ) ) {
			// A country <select> holds ISO codes, not names: "country" is the
			// token for the code, "country-name" the one for the spelled-out name.
			if ( 'country-name' === $map[ $key ] && 'select' === $tag ) {
				return 'country';
			}

			return $map[ $key ];
		}

		// Passwords are matched on intent, never by default: handing a password
		// manager the wrong one makes it overwrite a stored credential.
		if ( 'password' === $type ) {
			if ( preg_match( '/\b(?:new|confirm|repeat|retype|verify|again|2)\b/', str_replace( '_', ' ', $key ) ) ) {
				return 'new-password';
			}
			if ( preg_match( '/\b(?:current|old|login)\b/', str_replace( '_', ' ', $key ) ) ) {
				return 'current-password';
			}
			return '';
		}

		// Fall back to the input type when the name says nothing useful.
		$by_type = array(
			'email' => 'email',
			'tel'   => 'tel',
			'url'   => 'url',
		);

		return $by_type[ $type ] ?? '';
	}

	/**
	 * Field name to HTML autofill field name map.
	 *
	 * Keys are normalised names (lowercase, separators as underscores, any
	 * billing_/shipping_ prefix already removed). Filterable so a site can teach
	 * the module the field names of a form plugin or a non-English form.
	 *
	 * @return array<string, string>
	 */
	private static function get_autocomplete_map(): array {
		static $map = null;

		if ( null !== $map ) {
			return $map;
		}

		$defaults = array(
			// Name.
			'name'           => 'name',
			'full_name'      => 'name',
			'fullname'       => 'name',
			'your_name'      => 'name',
			'author'         => 'name',
			'first_name'     => 'given-name',
			'firstname'      => 'given-name',
			'fname'          => 'given-name',
			'given_name'     => 'given-name',
			'last_name'      => 'family-name',
			'lastname'       => 'family-name',
			'lname'          => 'family-name',
			'surname'        => 'family-name',
			'family_name'    => 'family-name',
			// Contact.
			'email'          => 'email',
			'e_mail'         => 'email',
			'mail'           => 'email',
			'email_address'  => 'email',
			'your_email'     => 'email',
			'user_email'     => 'email',
			'tel'            => 'tel',
			'phone'          => 'tel',
			'telephone'      => 'tel',
			'phone_number'   => 'tel',
			'your_phone'     => 'tel',
			'mobile'         => 'tel',
			// Organisation.
			'company'        => 'organization',
			'company_name'   => 'organization',
			'organization'   => 'organization',
			'organisation'   => 'organization',
			'job_title'      => 'organization-title',
			// Address.
			'address'        => 'street-address',
			'street'         => 'street-address',
			'street_address' => 'street-address',
			'address_1'      => 'address-line1',
			'address_line1'  => 'address-line1',
			'address_2'      => 'address-line2',
			'address_line2'  => 'address-line2',
			'city'           => 'address-level2',
			'town'           => 'address-level2',
			'city_town'      => 'address-level2',
			'state'          => 'address-level1',
			'province'       => 'address-level1',
			'region'         => 'address-level1',
			'county'         => 'address-level1',
			'zip'            => 'postal-code',
			'zipcode'        => 'postal-code',
			'zip_code'       => 'postal-code',
			'postcode'       => 'postal-code',
			'postal_code'    => 'postal-code',
			'country'        => 'country-name',
			// Account.
			'username'       => 'username',
			'user_login'     => 'username',
			'user_name'      => 'username',
			// Other identified purposes.
			'website'        => 'url',
			'url'            => 'url',
			'your_website'   => 'url',
			'birthday'       => 'bday',
			'birthdate'      => 'bday',
			'date_of_birth'  => 'bday',
			'dob'            => 'bday',
		);

		/**
		 * Filters the field name to autocomplete value map.
		 *
		 * @param array<string, string> $defaults Normalised field name => autofill field name.
		 */
		$map = array_map( 'strval', (array) apply_filters( 'livqacea_autocomplete_map', $defaults ) );

		return $map;
	}

	/**
	 * Derives a human-readable label from a URL.
	 *
	 * Checks a curated map of social / service domains first, then falls back
	 * to the capitalised first segment of the hostname.
	 *
	 * @param string $href The href value of the link.
	 * @return string Label string, or empty string if nothing useful is found.
	 */
	private static function label_from_href( string $href ): string {
		static $social_map = array(
			'facebook.com'  => 'Facebook',
			'instagram.com' => 'Instagram',
			'twitter.com'   => 'Twitter',
			'x.com'         => 'X',
			'youtube.com'   => 'YouTube',
			'youtu.be'      => 'YouTube',
			'linkedin.com'  => 'LinkedIn',
			'tiktok.com'    => 'TikTok',
			'pinterest.com' => 'Pinterest',
			'threads.net'   => 'Threads',
			'whatsapp.com'  => 'WhatsApp',
			'telegram.org'  => 'Telegram',
			't.me'          => 'Telegram',
		);

		$host = (string) wp_parse_url( $href, PHP_URL_HOST );
		if ( '' === $host ) {
			return '';
		}

		// Strip leading www.
		$host = preg_replace( '/^www\./i', '', $host );

		foreach ( $social_map as $domain => $name ) {
			if ( false !== stripos( $host, $domain ) ) {
				return $name;
			}
		}

		// Generic fallback: capitalise the first hostname segment.
		$parts = explode( '.', $host );
		return ucfirst( $parts[0] ?? '' );
	}

	// -----------------------------------------------------------------------
	// Skip Link
	// -----------------------------------------------------------------------

	/**
	 * Outputs a skip-navigation link immediately after <body>.
	 *
	 * WCAG 2.4.1 (Bypass Blocks).
	 *
	 * @return void
	 */
	public function inject_skip_link(): void {
		$saved  = ! empty( $this->options['skip_link_target'] ) ? $this->options['skip_link_target'] : '';
		$target = apply_filters( 'livqacea_skip_link_target', $saved ? $saved : '#primary' );

		printf(
			'<a class="skip-link screen-reader-text" href="%s">%s</a>' . "\n",
			esc_url( $target ),
			esc_html__( 'Skip to main content', 'livq-accessfix' )
		);
	}

	// -----------------------------------------------------------------------
	// Image Alt
	// -----------------------------------------------------------------------

	/**
	 * Ensures decorative images carry an explicit empty alt attribute.
	 *
	 * WCAG 1.1.1 / Technique H67.
	 *
	 * The $attachment parameter is intentionally untyped: core passes a WP_Post,
	 * but plugins and page builders are known to run this filter with their own
	 * objects, and a strict type hint would turn that into a fatal TypeError.
	 *
	 * @param array<string, string> $attr       Current image attributes.
	 * @param mixed                 $attachment The attachment post object.
	 * @return array<string, string> Modified attributes.
	 */
	public function fix_image_alt( array $attr, $attachment ): array {
		if ( isset( $attr['alt'] ) && '' !== $attr['alt'] ) {
			return $attr;
		}

		if ( ! isset( $attachment->ID ) ) {
			$attr['alt'] = isset( $attr['alt'] ) ? $attr['alt'] : '';
			return $attr;
		}

		$stored_alt = trim( (string) get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ) );

		if ( '' !== $stored_alt ) {
			$attr['alt'] = $stored_alt;
		} else {
			$attr['alt'] = '';
		}

		return $attr;
	}

	/**
	 * Fixes alt attribute on Core Image blocks in FSE / block themes.
	 *
	 * WCAG 1.1.1 - wp_get_attachment_image_attributes does not fire for the
	 * block renderer.
	 *
	 * @param string               $block_content Rendered HTML of the block.
	 * @param array<string, mixed> $block         Block data.
	 * @return string Modified block HTML.
	 */
	public function fix_block_image( string $block_content, array $block ): string {
		if ( empty( $block_content ) ) {
			return $block_content;
		}

		$block_alt = isset( $block['attrs']['alt'] ) ? trim( (string) $block['attrs']['alt'] ) : null;

		if ( null !== $block_alt && '' !== $block_alt ) {
			return $block_content;
		}

		if ( false !== stripos( $block_content, '<img' ) && false === stripos( $block_content, ' alt=' ) ) {
			$block_content = (string) preg_replace(
				'/(<img\b[^>]*?)(\s*\/?>)/i',
				'$1 alt=""$2',
				$block_content,
				1
			);
		}

		return $block_content;
	}

	// -----------------------------------------------------------------------
	// Focus CSS
	// -----------------------------------------------------------------------

	/**
	 * Enqueues inline CSS for screen-reader-text and focus-visible styles.
	 *
	 * @return void
	 */
	public function enqueue_inline_styles(): void {
		$handle = wp_style_is( 'wp-block-library', 'enqueued' ) ? 'wp-block-library' : 'livqacea-a11y';

		if ( 'livqacea-a11y' === $handle ) {
			wp_register_style( 'livqacea-a11y', false, array(), LIVQACEA_VERSION );
			wp_enqueue_style( 'livqacea-a11y' );
		}

		wp_add_inline_style( $handle, $this->get_inline_css() );
	}

	// -----------------------------------------------------------------------
	// HTML lang guard (WCAG 3.1.1)
	// -----------------------------------------------------------------------

	/**
	 * Ensures the <html> tag carries a lang attribute.
	 *
	 * WordPress's language_attributes() already outputs lang for properly
	 * configured sites. This filter is a safety net for themes or setups that
	 * inadvertently strip the attribute.
	 *
	 * @param string $output  Existing language_attributes() output.
	 * @param string $doctype The doctype ('html' or 'xhtml').
	 * @return string Output with lang guaranteed present.
	 */
	public function fix_html_lang( string $output, string $doctype ): string {
		if ( 'html' !== $doctype ) {
			return $output;
		}
		if ( false !== stripos( $output, 'lang=' ) ) {
			return $output;
		}

		$locale = str_replace( '_', '-', get_locale() );
		return $output . ' lang="' . esc_attr( $locale ) . '"';
	}

	// -----------------------------------------------------------------------
	// Menu ARIA Helper
	// -----------------------------------------------------------------------

	/**
	 * Adds aria-haspopup and aria-expanded to menu items that have children.
	 *
	 * WCAG 4.1.2.
	 *
	 * $item and $args are untyped on purpose: core passes WP_Post and stdClass,
	 * but mega-menu plugins and page builders pass their own objects or plain
	 * arrays, and a strict type hint would turn that into a fatal TypeError.
	 *
	 * @param array<string, string> $atts   Current link attributes.
	 * @param mixed                 $item   The menu item object.
	 * @param mixed                 $args   wp_nav_menu() arguments.
	 * @param int                   $depth  Depth of the current menu item.
	 * @return array<string, string> Modified attributes.
	 */
	public function add_menu_aria_attrs( array $atts, $item = null, $args = null, $depth = 0 ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( ! isset( $item->classes ) ) {
			return $atts;
		}

		if ( in_array( 'menu-item-has-children', (array) $item->classes, true ) ) {
			$atts['aria-haspopup'] = 'true';
			$atts['aria-expanded'] = 'false';
		}
		return $atts;
	}

	/**
	 * Enqueues the Vanilla JS that toggles aria-expanded on sub-menu triggers.
	 *
	 * @return void
	 */
	public function enqueue_menu_aria_script(): void {
		wp_enqueue_script( 'livqacea-menu-aria', LIVQACEA_PLUGIN_URL . 'assets/js/livqacea-menu-aria.js', array(), LIVQACEA_VERSION, true );
	}

	// -----------------------------------------------------------------------
	// Inline CSS
	// -----------------------------------------------------------------------

	/**
	 * Returns the inline CSS string.
	 *
	 * @return string
	 */
	private function get_inline_css(): string {
		$css = self::get_screen_reader_css();

		// Focus styles remain strictly opt-in: the screen-reader-text utility
		// above ships whenever a module emits that class, but the focus outline
		// must never come back for someone who switched it off.
		if ( ! empty( $this->options['inject_focus_css'] ) ) {
			$css .= self::get_focus_css();
		}

		return $css;
	}

	/**
	 * The .screen-reader-text utility used by the skip link and the
	 * "(opens in a new tab)" notice.
	 *
	 * @return string
	 */
	private static function get_screen_reader_css(): string {
		return '
/* LivQ AccessFix - Accessibility Styles v' . LIVQACEA_VERSION . ' */

/* WCAG 2.4.1 skip link & 1.3.1 screen-reader-text utility */
.screen-reader-text {
	border: 0;
	clip: rect(1px, 1px, 1px, 1px);
	clip-path: inset(50%);
	height: 1px;
	margin: -1px;
	overflow: hidden;
	padding: 0;
	position: absolute;
	width: 1px;
	word-wrap: normal !important;
}

.screen-reader-text:focus {
	background-color: #fff;
	border-radius: 4px;
	box-shadow: 0 0 2px 2px rgba(0, 0, 0, 0.6);
	clip: auto !important;
	clip-path: none;
	color: #21759b;
	display: block;
	font-size: 0.875rem;
	font-weight: 700;
	height: auto;
	left: 8px;
	line-height: normal;
	padding: 12px 24px;
	text-decoration: none;
	top: 8px;
	width: auto;
	z-index: 100000;
}
';
	}

	/**
	 * High-contrast focus indicator (WCAG 2.4.11).
	 *
	 * @return string
	 */
	private static function get_focus_css(): string {
		return '
/* WCAG 2.4.11 Focus Appearance - high-contrast visible focus indicator.
   !important overrides aggressive theme resets (outline:none on input, etc.).
   3px solid outline + 5px glow meets the 3:1 contrast ratio requirement. */
a:focus-visible,
button:focus-visible,
input:focus-visible,
select:focus-visible,
textarea:focus-visible,
[tabindex]:focus-visible {
	outline: 3px solid #0056b3 !important;
	outline-offset: 3px !important;
	box-shadow: 0 0 0 5px rgba(0, 86, 179, 0.3) !important;
}
';
	}
}
