<?php
/**
 * WooCommerce Accessibility Module.
 *
 * Hooks into the parent plugin's output buffer via the `livqacea_sanitized_html` filter to fix
 * WooCommerce-specific WCAG 2.2 AA issues that the generic buffer cannot resolve:
 * quantity +/− buttons, product gallery trigger, and add-to-cart buttons on
 * archive pages. Also injects a JS live region for cart update announcements and
 * aria-current state for product variation selectors.
 *
 * Loads only when WooCommerce is active. Controlled by the `woocommerce_a11y`
 * option toggle in Settings → LivQ AccessFix.
 *
 * @package LivQ_AccessFix
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class LIVQACEA_WooCommerce
 */
class LIVQACEA_WooCommerce {

	/**
	 * Plugin options.
	 *
	 * @var array<string, mixed>
	 */
	private $options;

	/**
	 * Cached result of the WooCommerce page check for this request.
	 *
	 * Resolved once on template_redirect, while the main query is unambiguous,
	 * and reused by the output-buffer filter which runs at shutdown.
	 *
	 * @var bool|null
	 */
	private $is_wc_page = null;

	/**
	 * Constructor - registers hooks when the module is enabled.
	 *
	 * @param array<string, mixed> $options Plugin options from LIVQACEA_Main.
	 */
	public function __construct( array $options ) {
		$this->options = $options;

		if ( empty( $options['woocommerce_a11y'] ) ) {
			return;
		}

		// Resolve the WooCommerce context early, before any buffering happens.
		add_action( 'template_redirect', array( $this, 'detect_context' ), 5 );

		// Hook into the output buffer after all generic fixes have run.
		add_filter( 'livqacea_sanitized_html', array( $this, 'fix_woocommerce_html' ) );

		// Live region markup + its script - only on WooCommerce pages.
		add_action( 'wp_footer', array( $this, 'render_announcer' ), 99 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Caches whether the current request is a WooCommerce page.
	 *
	 * @return void
	 */
	public function detect_context(): void {
		$this->is_wc_page = function_exists( 'is_woocommerce' )
			&& ( is_woocommerce() || is_cart() || is_checkout() || is_account_page() );
	}

	// -----------------------------------------------------------------------
	// PHP buffer - HTML fixes
	// -----------------------------------------------------------------------

	/**
	 * Applies WooCommerce-specific HTML fixes to the buffered page output.
	 *
	 * @param string $html Full page HTML.
	 * @return string Modified HTML.
	 */
	public function fix_woocommerce_html( string $html ): string {
		if ( empty( $html ) ) {
			return $html;
		}

		// These rules key off generic class names ("plus", "minus"), so they must
		// never run outside a WooCommerce page - a theme carousel with
		// class="plus" would otherwise be announced as "Increase quantity".
		if ( ! $this->is_woocommerce_page() ) {
			return $html;
		}

		$html = $this->fix_qty_buttons( $html );
		$html = $this->fix_gallery_trigger( $html );
		$html = $this->fix_archive_add_to_cart( $html );

		return $html;
	}

	/**
	 * Adds aria-label to WooCommerce quantity +/− buttons.
	 *
	 * Default WooCommerce renders:
	 *   <button type="button" class="minus">-</button>
	 *   <button type="button" class="plus">+</button>
	 *
	 * These have no accessible name beyond the character "-" / "+" which screen
	 * readers announce as "minus" / "plus" with no product context. WCAG 4.1.2.
	 *
	 * @param string $html Full page HTML.
	 * @return string Modified HTML.
	 */
	private function fix_qty_buttons( string $html ): string {
		// The lookarounds require a real class-token boundary: \bminus\b also
		// matched "qty-minus" or "minus-icon", labelling unrelated buttons.
		$html = self::safe_replace(
			'/<button\b([^>]*\bclass=["\'][^"\']*(?<![\w-])minus(?![\w-])[^"\']*["\'][^>]*)>/i',
			static function ( array $m ): string {
				if ( preg_match( '/\baria-label=/i', $m[1] ) ) {
					return $m[0];
				}
				/* translators: aria-label for WooCommerce quantity decrease button */
				$label = esc_attr( __( 'Decrease quantity', 'livq-accessfix' ) );
				return '<button' . $m[1] . ' aria-label="' . $label . '">';
			},
			$html
		);

		// Plus button.
		$html = self::safe_replace(
			'/<button\b([^>]*\bclass=["\'][^"\']*(?<![\w-])plus(?![\w-])[^"\']*["\'][^>]*)>/i',
			static function ( array $m ): string {
				if ( preg_match( '/\baria-label=/i', $m[1] ) ) {
					return $m[0];
				}
				/* translators: aria-label for WooCommerce quantity increase button */
				$label = esc_attr( __( 'Increase quantity', 'livq-accessfix' ) );
				return '<button' . $m[1] . ' aria-label="' . $label . '">';
			},
			$html
		);

		return $html;
	}

	/**
	 * preg_replace_callback() that falls back to the original subject.
	 *
	 * PCRE returns null on failure (PREG_BACKTRACK_LIMIT_ERROR on large pages);
	 * propagating that null would blank the whole page.
	 *
	 * @param string   $pattern  Regex pattern.
	 * @param callable $callback Replacement callback.
	 * @param string   $subject  Subject string.
	 * @return string
	 */
	private static function safe_replace( string $pattern, callable $callback, string $subject ): string {
		$result = preg_replace_callback( $pattern, $callback, $subject );

		return is_string( $result ) ? $result : $subject;
	}

	/**
	 * Adds aria-label to the WooCommerce product gallery zoom/trigger link.
	 *
	 * The default WooCommerce single-product gallery renders an anchor with
	 * class `woocommerce-product-gallery__trigger` that contains only an SVG
	 * icon - no accessible name. WCAG 2.4.4 / 4.1.2.
	 *
	 * @param string $html Full page HTML.
	 * @return string Modified HTML.
	 */
	private function fix_gallery_trigger( string $html ): string {
		return preg_replace_callback(
			'/<a\b([^>]*\bclass=["\'][^"\']*woocommerce-product-gallery__trigger[^"\']*["\'][^>]*)>/i',
			static function ( array $m ): string {
				if ( preg_match( '/\baria-label=/i', $m[1] ) ) {
					return $m[0];
				}
				/* translators: aria-label for WooCommerce product gallery zoom button */
				$label = esc_attr( __( 'Open product image gallery', 'livq-accessfix' ) );
				return '<a' . $m[1] . ' aria-label="' . $label . '">';
			},
			$html
		) ?? $html;
	}

	/**
	 * Improves "Add to cart" buttons on shop/archive pages.
	 *
	 * On WooCommerce archive pages every product has an "Add to cart" button
	 * with identical visible text, making them indistinguishable for screen
	 * reader users who navigate by form controls. WCAG 2.4.6 / 4.1.2.
	 *
	 * Strategy: each product `<li>` contains both the product title and the
	 * button. We extract the title from `.woocommerce-loop-product__title` and
	 * inject it as an `aria-label` on the button.
	 *
	 * Falls back to the generic "Add to cart" label when the title cannot be
	 * found - which is safe and no worse than the current state.
	 *
	 * @param string $html Full page HTML.
	 * @return string Modified HTML.
	 */
	private function fix_archive_add_to_cart( string $html ): string {
		// Process each WooCommerce product list item individually.
		return preg_replace_callback(
			'/<li\b[^>]*\bproduct\b[^>]*>.*?<\/li>/is',
			static function ( array $m ): string {
				$item = $m[0];

				// Extract product title from the loop title element.
				$title = '';
				if ( preg_match(
					'/<[^>]+\bwoocommerce-loop-product__title\b[^>]*>(.*?)<\//is',
					$item,
					$title_m
				) ) {
					$title = trim( wp_strip_all_tags( $title_m[1] ) );
				}

				if ( '' === $title ) {
					return $item;
				}

				// Add aria-label to the add_to_cart link/button if it lacks one.
				return preg_replace_callback(
					'/<a\b([^>]*\badd_to_cart_button\b[^>]*)>/i',
					static function ( array $btn_m ) use ( $title ): string {
						if ( preg_match( '/\baria-label=/i', $btn_m[1] ) ) {
							return $btn_m[0];
						}
						$label = esc_attr(
							sprintf(
							/* translators: %s: product name */
								__( 'Add %s to cart', 'livq-accessfix' ),
								$title
							)
						);
						return '<a' . $btn_m[1] . ' aria-label="' . $label . '">';
					},
					$item
				) ?? $item;
			},
			$html
		) ?? $html;
	}

	// -----------------------------------------------------------------------
	// JS - live region + variation state
	// -----------------------------------------------------------------------

	/**
	 * Injects a minimal inline script that:
	 *
	 * 1. Creates a screen-reader-only live region (`role="status" aria-live="polite"`)
	 *    and announces cart changes triggered by WooCommerce JS events
	 *    (`added_to_cart`, `removed_from_cart`).
	 *
	 * 2. Adds `aria-current="true"` to the currently selected variation swatch /
	 *    select option on `found_variation` and clears it on `reset_data`.
	 *
	 * Only injected on pages where WooCommerce scripts are enqueued (product,
	 * cart, checkout, shop) - checked via `is_woocommerce()` / `is_cart()` etc.
	 */
	public function render_announcer(): void {
		if ( ! $this->is_woocommerce_page() ) {
			return;
		}
		?>
<div id="livqacea-wc-announcer"
	role="status"
	aria-live="polite"
	aria-atomic="true"
	style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0;">
</div>
		<?php
	}

	/**
	 * Enqueues the cart live-region / variation aria-current script.
	 *
	 * Scoped to WooCommerce pages only (product, cart, checkout, shop).
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {
		if ( ! $this->is_woocommerce_page() ) {
			return;
		}

		wp_enqueue_script( 'livqacea-woocommerce', LIVQACEA_PLUGIN_URL . 'assets/js/livqacea-woocommerce.js', array( 'jquery' ), LIVQACEA_VERSION, true );
		wp_localize_script(
			'livqacea-woocommerce',
			'livqaceaWooCommerce',
			array(
				'strings' => array(
					/* translators: screen-reader cart announcement, followed by product name or button label */
					'addedToCart'         => __( 'added to cart.', 'livq-accessfix' ),
					'itemAddedToCart'     => __( 'Item added to cart.', 'livq-accessfix' ),
					'itemRemovedFromCart' => __( 'Item removed from cart.', 'livq-accessfix' ),
					'quantity'            => __( 'Quantity', 'livq-accessfix' ),
				),
			)
		);
	}

	/**
	 * Checks whether the current request is a WooCommerce-related page.
	 *
	 * @return bool
	 */
	private function is_woocommerce_page(): bool {
		if ( null === $this->is_wc_page ) {
			$this->detect_context();
		}

		return (bool) $this->is_wc_page;
	}
}
