<?php
/**
 * Accessibility Scanner - template-based site audit.
 *
 * Auto-discovers representative page templates (WordPress core + WooCommerce
 * + Easy Digital Downloads + LearnDash) and scans each via wp_remote_get().
 * Issues are analyzed server-side with the same logic used by the output buffer,
 * then cached as a transient for 24 hours. A WP-Cron job refreshes the cache daily.
 *
 * @package LivQ_AccessFix
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class LIVQACEA_Scanner
 */
class LIVQACEA_Scanner {

	// v2: issue records carry a three-state 'status' instead of the old boolean
	// 'auto_fixed'. Bumping the key discards incompatible cached payloads.
	const CACHE_KEY = 'livqacea_scan_results_v2';
	const CACHE_TTL = DAY_IN_SECONDS;
	const PAGE_SLUG = 'livqacea-scanner';
	const NONCE_KEY = 'livqacea_scan_nonce';
	const CRON_HOOK = 'livqacea_daily_scan';

	/**
	 * Per-request timeout for a single template fetch, in seconds.
	 *
	 * @var int
	 */
	const FETCH_TIMEOUT = 10;

	/**
	 * Wall-clock budget for one background scan, in seconds.
	 *
	 * A full run used to be able to sit for 15 templates x 20s = 300s and get
	 * killed halfway by max_execution_time, leaving a half-written cache.
	 *
	 * @var int
	 */
	const CRON_TIME_BUDGET = 60;

	/**
	 * Hook suffix of this page, captured from add_submenu_page().
	 * Used to scope asset enqueueing to this screen only.
	 *
	 * @var string
	 */
	private static $page_hook = '';

	// -----------------------------------------------------------------------
	// Bootstrap
	// -----------------------------------------------------------------------

	/**
	 * Registers WordPress hooks and schedules the daily background scan cron.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_livqacea_scan_url', array( __CLASS__, 'ajax_scan_url' ) );
		add_action( 'wp_ajax_livqacea_scan_clear', array( __CLASS__, 'ajax_clear_cache' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_all_background' ) );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Registers the Accessibility Scanner submenu page.
	 *
	 * @return void
	 */
	public static function register_menu(): void {
		self::$page_hook = (string) add_submenu_page(
			LIVQACEA_Backend::PAGE_SLUG,
			__( 'Accessibility Scanner', 'livq-accessfix' ),
			__( 'A11y Scanner', 'livq-accessfix' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_admin_page' )
		);
	}

	/**
	 * Enqueues the Accessibility Scanner CSS/JS - scoped to this screen only.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public static function enqueue_assets( string $hook ): void {
		if ( $hook !== self::$page_hook ) {
			return;
		}

		$cache = get_transient( self::CACHE_KEY );
		$cache = is_array( $cache ) ? $cache : array();

		wp_enqueue_style( 'livqacea-scanner', LIVQACEA_PLUGIN_URL . 'assets/css/livqacea-scanner.css', array(), LIVQACEA_VERSION );

		wp_enqueue_script( 'livqacea-scanner', LIVQACEA_PLUGIN_URL . 'assets/js/livqacea-scanner.js', array( 'jquery' ), LIVQACEA_VERSION, true );
		wp_localize_script(
			'livqacea-scanner',
			'livqaceaScanner',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( self::NONCE_KEY ),
				'templates'  => array_values( self::discover_templates() ),
				'cachedData' => $cache ? $cache : null,
				'strings'    => array(
					'noIssues'         => __( 'No issues detected', 'livq-accessfix' ),
					'statusManual'     => __( '✗ Manual fix', 'livq-accessfix' ),
					'statusFixable'    => __( '◻ Enable the matching module', 'livq-accessfix' ),
					'statusUnresolved' => __( '⚠ Module active - still detected', 'livq-accessfix' ),
					'severity'         => __( 'Severity', 'livq-accessfix' ),
					'wcag'             => __( 'WCAG', 'livq-accessfix' ),
					'issue'            => __( 'Issue', 'livq-accessfix' ),
					'status'           => __( 'Status', 'livq-accessfix' ),
					'scanning'         => __( 'Scanning', 'livq-accessfix' ),
					'scanningEllipsis' => __( 'Scanning…', 'livq-accessfix' ),
					'startScan'        => __( '▶ Start Full Scan', 'livq-accessfix' ),
				),
			)
		);
	}

	// -----------------------------------------------------------------------
	// Template discovery
	// -----------------------------------------------------------------------

	/**
	 * Auto-discovers page templates present on this WordPress installation.
	 * Detects core WordPress templates plus WooCommerce, EDD, and LearnDash
	 * when those plugins are active. Works on any site regardless of plugins.
	 *
	 * @return array<int, array<string, string>>
	 */
	public static function discover_templates(): array {
		$templates     = array();
		$front_id      = (int) get_option( 'page_on_front' );
		$blog_id       = (int) get_option( 'page_for_posts' );
		$show_on_front = get_option( 'show_on_front', 'posts' );

		// ── WordPress core ──────────────────────────────────────────────────

		$templates[] = array(
			'key'   => 'home',
			'label' => __( 'Homepage', 'livq-accessfix' ),
			'url'   => home_url( '/' ),
			'group' => 'WordPress',
		);

		// Blog index (only when a static front page is set and blog is separate).
		if ( 'page' === $show_on_front && $blog_id > 0 ) {
			$blog_url = get_permalink( $blog_id );
			if ( $blog_url ) {
				$templates[] = array(
					'key'   => 'blog',
					'label' => __( 'Blog Index', 'livq-accessfix' ),
					'url'   => $blog_url,
					'group' => 'WordPress',
				);
			}
		}

		// Most recent published post.
		$posts = get_posts(
			array(
				'numberposts' => 1,
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);
		if ( $posts ) {
			$templates[] = array(
				'key'   => 'single-post',
				'label' => __( 'Single Post', 'livq-accessfix' ),
				'url'   => (string) get_permalink( $posts[0]->ID ),
				'group' => 'WordPress',
			);
		}

		// First static page that is not the front page or the blog index.
		$exclude_ids = array_filter( array( $front_id, $blog_id ) );
		$pages       = get_posts(
			array(
				'numberposts' => 10,
				'post_status' => 'publish',
				'post_type'   => 'page',
				'exclude'     => $exclude_ids, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude
			)
		);
		if ( $pages ) {
			$templates[] = array(
				'key'   => 'page',
				/* translators: %s: page title */
				'label' => sprintf( __( 'Page: %s', 'livq-accessfix' ), $pages[0]->post_title ),
				'url'   => (string) get_permalink( $pages[0]->ID ),
				'group' => 'WordPress',
			);
		}

		// Category archive (first non-empty category).
		$cats = get_categories(
			array(
				'number'     => 1,
				'hide_empty' => true,
			)
		);
		if ( $cats ) {
			$cat_link = get_category_link( $cats[0]->term_id );
			if ( $cat_link && ! is_wp_error( $cat_link ) ) {
				$templates[] = array(
					'key'   => 'archive',
					'label' => __( 'Category Archive', 'livq-accessfix' ),
					'url'   => $cat_link,
					'group' => 'WordPress',
				);
			}
		}

		// Search results.
		$templates[] = array(
			'key'   => 'search',
			'label' => __( 'Search Results', 'livq-accessfix' ),
			'url'   => home_url( '/?s=test' ),
			'group' => 'WordPress',
		);

		// 404 page - use a URL that is guaranteed not to resolve.
		$templates[] = array(
			'key'   => '404',
			'label' => __( '404 Page', 'livq-accessfix' ),
			'url'   => home_url( '/livqacea-nonexistent-' . wp_rand( 1000, 9999 ) . '/' ),
			'group' => 'WordPress',
		);

		// ── WooCommerce ─────────────────────────────────────────────────────

		if ( class_exists( 'WooCommerce' ) ) {
			$shop_id = function_exists( 'wc_get_page_id' ) ? wc_get_page_id( 'shop' ) : -1;
			if ( $shop_id > 0 && 'publish' === get_post_status( $shop_id ) ) {
				$templates[] = array(
					'key'   => 'wc-shop',
					'label' => __( 'Shop', 'livq-accessfix' ),
					'url'   => (string) get_permalink( $shop_id ),
					'group' => 'WooCommerce',
				);
			}

			if ( function_exists( 'wc_get_products' ) ) {
				$products = wc_get_products(
					array(
						'limit'  => 1,
						'status' => 'publish',
					)
				);
				if ( $products ) {
					$templates[] = array(
						'key'   => 'wc-product',
						'label' => __( 'Product', 'livq-accessfix' ),
						'url'   => (string) get_permalink( $products[0]->get_id() ),
						'group' => 'WooCommerce',
					);
				}
			}

			if ( function_exists( 'wc_get_cart_url' ) ) {
				$cart_url = wc_get_cart_url();
				if ( $cart_url ) {
					$templates[] = array(
						'key'   => 'wc-cart',
						'label' => __( 'Cart', 'livq-accessfix' ),
						'url'   => $cart_url,
						'group' => 'WooCommerce',
					);
				}
			}

			if ( function_exists( 'wc_get_checkout_url' ) ) {
				$checkout_url = wc_get_checkout_url();
				if ( $checkout_url ) {
					$templates[] = array(
						'key'   => 'wc-checkout',
						'label' => __( 'Checkout', 'livq-accessfix' ),
						'url'   => $checkout_url,
						'group' => 'WooCommerce',
					);
				}
			}

			$account_id = function_exists( 'wc_get_page_id' ) ? wc_get_page_id( 'myaccount' ) : -1;
			if ( $account_id > 0 ) {
				$templates[] = array(
					'key'   => 'wc-account',
					'label' => __( 'My Account', 'livq-accessfix' ),
					'url'   => (string) get_permalink( $account_id ),
					'group' => 'WooCommerce',
				);
			}

			$wc_cats = get_terms(
				array(
					'taxonomy'   => 'product_cat',
					'number'     => 1,
					'hide_empty' => true,
					'parent'     => 0,
				)
			);
			if ( $wc_cats && ! is_wp_error( $wc_cats ) ) {
				$cat_link = get_term_link( $wc_cats[0] );
				if ( ! is_wp_error( $cat_link ) ) {
					$templates[] = array(
						'key'   => 'wc-category',
						'label' => __( 'Product Category', 'livq-accessfix' ),
						'url'   => $cat_link,
						'group' => 'WooCommerce',
					);
				}
			}
		}

		// ── Easy Digital Downloads ───────────────────────────────────────────

		if ( class_exists( 'Easy_Digital_Downloads' ) && function_exists( 'edd_get_option' ) ) {
			$edd_page = (int) edd_get_option( 'download_page' );
			if ( $edd_page > 0 ) {
				$templates[] = array(
					'key'   => 'edd-store',
					'label' => __( 'Downloads Store', 'livq-accessfix' ),
					'url'   => (string) get_permalink( $edd_page ),
					'group' => 'Easy Digital Downloads',
				);
			}
			$edd_products = get_posts(
				array(
					'numberposts' => 1,
					'post_status' => 'publish',
					'post_type'   => 'download',
				)
			);
			if ( $edd_products ) {
				$templates[] = array(
					'key'   => 'edd-product',
					'label' => __( 'Download Page', 'livq-accessfix' ),
					'url'   => (string) get_permalink( $edd_products[0]->ID ),
					'group' => 'Easy Digital Downloads',
				);
			}
		}

		// ── LearnDash ────────────────────────────────────────────────────────

		if ( class_exists( 'SFWD_LMS' ) ) {
			$courses = get_posts(
				array(
					'numberposts' => 1,
					'post_status' => 'publish',
					'post_type'   => 'sfwd-courses',
				)
			);
			if ( $courses ) {
				$templates[] = array(
					'key'   => 'ld-course',
					'label' => __( 'Course Page', 'livq-accessfix' ),
					'url'   => (string) get_permalink( $courses[0]->ID ),
					'group' => 'LearnDash',
				);
			}
		}

		return $templates;
	}

	// -----------------------------------------------------------------------
	// AJAX handlers
	// -----------------------------------------------------------------------

	/**
	 * Fetches a single URL and returns its accessibility issues as JSON.
	 * Called sequentially from JS for each template to avoid PHP timeout.
	 */
	public static function ajax_scan_url(): void {
		check_ajax_referer( self::NONCE_KEY, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized', 'livq-accessfix' ) ), 403 );
		}

		$url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		$key = isset( $_POST['key'] ) ? sanitize_key( wp_unslash( $_POST['key'] ) ) : '';

		if ( ! $url || wp_parse_url( $url, PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid URL', 'livq-accessfix' ) ) );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 20,
				'user-agent' => 'LivqaceaA11yScanner/1.0 WordPress/' . get_bloginfo( 'version' ),
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter, re-applied so a site's local-development override keeps working.
				'sslverify'  => apply_filters( 'https_local_ssl_verify', false ),
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => $response->get_error_message() ) );
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$html    = wp_remote_retrieve_body( $response );
		$options = get_option( 'livqacea_options', array() );
		$issues  = self::analyze_html( $html, $key, $options );
		$score   = self::calculate_score( $issues );

		// Merge result into the transient cache so previous results are preserved.
		$cache         = get_transient( self::CACHE_KEY );
		$cache         = is_array( $cache ) ? $cache : array();
		$cache[ $key ] = array(
			'url'        => $url,
			'http_code'  => $code,
			'issues'     => $issues,
			'score'      => $score,
			'scanned_at' => current_time( 'mysql' ),
		);
		set_transient( self::CACHE_KEY, $cache, self::CACHE_TTL );

		wp_send_json_success(
			array(
				'key'    => $key,
				'url'    => $url,
				'code'   => $code,
				'issues' => $issues,
				'score'  => $score,
			)
		);
	}

	/**
	 * AJAX handler - clears the scan results transient cache.
	 *
	 * @return void
	 */
	public static function ajax_clear_cache(): void {
		check_ajax_referer( self::NONCE_KEY, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array(), 403 );
		}
		delete_transient( self::CACHE_KEY );
		wp_send_json_success();
	}

	// -----------------------------------------------------------------------
	// Background (WP-Cron) full scan
	// -----------------------------------------------------------------------

	/**
	 * WP-Cron callback - scans all discovered templates and caches the results.
	 *
	 * @return void
	 */
	public static function run_all_background(): void {
		$templates = self::discover_templates();
		$options   = get_option( 'livqacea_options', array() );
		$cache     = array();
		$started   = microtime( true );

		foreach ( $templates as $tpl ) {
			// Stop cleanly before PHP's own execution limit kills the run.
			if ( ( microtime( true ) - $started ) > self::CRON_TIME_BUDGET ) {
				break;
			}

			$response = wp_remote_get(
				$tpl['url'],
				array(
					'timeout'    => self::FETCH_TIMEOUT,
					'user-agent' => 'LivqaceaA11yScanner/1.0',
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter, re-applied so a site's local-development override keeps working.
					'sslverify'  => apply_filters( 'https_local_ssl_verify', false ),
				)
			);
			if ( is_wp_error( $response ) ) {
				continue;
			}
			$html                 = wp_remote_retrieve_body( $response );
			$issues               = self::analyze_html( $html, $tpl['key'], $options );
			$cache[ $tpl['key'] ] = array(
				'url'        => $tpl['url'],
				'http_code'  => (int) wp_remote_retrieve_response_code( $response ),
				'issues'     => $issues,
				'score'      => self::calculate_score( $issues ),
				'scanned_at' => current_time( 'mysql' ),
			);
		}

		set_transient( self::CACHE_KEY, $cache, self::CACHE_TTL );
	}

	// -----------------------------------------------------------------------
	// HTML analyzer
	// -----------------------------------------------------------------------

	/**
	 * Runs a battery of WCAG 2.2 AA checks against fetched HTML.
	 *
	 * Returns structured issue records with severity, WCAG criterion, a sample
	 * element, and whether this plugin already auto-fixes it.
	 *
	 * @param string               $html    Raw HTML of the scanned page.
	 * @param string               $tpl_key Template identifier (e.g. 'wc-product').
	 * @param array<string, mixed> $options Current plugin options.
	 * @return array<int, array<string, mixed>>
	 */
	public static function analyze_html( string $html, string $tpl_key, array $options ): array {
		if ( empty( $html ) ) {
			return array();
		}

		$issues = array();

		// 1. <html lang> missing - WCAG 3.1.1
		if ( ! preg_match( '/<html\b[^>]*\blang=["\'][^"\']+["\'][^>]*>/i', $html ) ) {
			$issues[] = array(
				'type'     => 'html-lang',
				'severity' => 'high',
				'wcag'     => '3.1.1',
				'message'  => __( 'Page language not declared on &lt;html&gt; element.', 'livq-accessfix' ),
				'count'    => 1,
				'sample'   => self::snippet( $html, '<html' ),
				'status'   => self::issue_status( '', $options ),
			);
		}

		// 2. Skip link missing - WCAG 2.4.1
		if ( ! preg_match( '/<a\b[^>]*href=["\']#[^"\']+["\'][^>]*>[^<]*(?:skip|salta|bypass|jump|main|content)[^<]*<\/a>/i', $html ) ) {
			$issues[] = array(
				'type'     => 'skip-link',
				'severity' => 'high',
				'wcag'     => '2.4.1',
				'message'  => __( 'No skip link found. Keyboard users cannot bypass navigation.', 'livq-accessfix' ),
				'count'    => 1,
				'sample'   => '',
				'status'   => self::issue_status( 'inject_skip_link', $options ),
			);
		}

		// 3. Images without alt attribute - WCAG 1.1.1
		preg_match_all( '/<img\b(?![^>]*\balt=)[^>]*>/i', $html, $m );
		if ( $m[0] ) {
			$issues[] = array(
				'type'     => 'img-no-alt',
				'severity' => 'critical',
				'wcag'     => '1.1.1',
				'message'  => sprintf(
					/* translators: %d: number of images */
					_n( '%d image is missing the alt attribute.', '%d images are missing the alt attribute.', count( $m[0] ), 'livq-accessfix' ),
					count( $m[0] )
				),
				'count'    => count( $m[0] ),
				'sample'   => substr( $m[0][0], 0, 200 ),
				'status'   => self::issue_status( 'fix_image_alt', $options ),
			);
		}

		// 4. Links without accessible name - WCAG 2.4.4
		preg_match_all( '/<a\b([^>]*)>(.*?)<\/a>/is', $html, $link_m );
		$nameless_links = array();
		foreach ( $link_m[0] as $i => $full ) {
			$attrs = $link_m[1][ $i ];
			$inner = $link_m[2][ $i ];
			if ( preg_match( '/\baria-(?:label|labelledby)=/i', $attrs ) ) {
				continue;
			}
			if ( preg_match( '/\btitle=["\'][^"\']+["\']/', $attrs ) ) {
				continue;
			}
			if ( '' !== trim( wp_strip_all_tags( $inner ) ) ) {
				continue;
			}
			$nameless_links[] = substr( $full, 0, 200 );
		}
		if ( $nameless_links ) {
			$issues[] = array(
				'type'     => 'link-no-name',
				'severity' => 'critical',
				'wcag'     => '2.4.4',
				'message'  => sprintf(
					/* translators: %d: number of links */
					_n( '%d link has no accessible name (empty or image-only).', '%d links have no accessible name.', count( $nameless_links ), 'livq-accessfix' ),
					count( $nameless_links )
				),
				'count'    => count( $nameless_links ),
				'sample'   => $nameless_links[0],
				'status'   => self::issue_status( 'fix_nameless_links', $options ),
			);
		}

		// 5. Buttons without accessible name - WCAG 4.1.2
		preg_match_all( '/<button\b([^>]*)>(.*?)<\/button>/is', $html, $btn_m );
		$nameless_btns = array();
		foreach ( $btn_m[0] as $i => $full ) {
			$attrs = $btn_m[1][ $i ];
			$inner = $btn_m[2][ $i ];
			if ( preg_match( '/\baria-label=/i', $attrs ) ) {
				continue;
			}
			if ( preg_match( '/\btitle=["\'][^"\']+["\']/', $attrs ) ) {
				continue;
			}
			if ( '' !== trim( wp_strip_all_tags( $inner ) ) ) {
				continue;
			}
			// An image with meaningful alt text names the button; strip_tags()
			// cannot see it, so without this the button is a false positive.
			if ( preg_match( '/<img\b[^>]*\balt=["\'][^"\']+["\']/i', $inner ) ) {
				continue;
			}
			if ( preg_match( '/<svg\b[^>]*\baria-label(?:ledby)?=/i', $inner ) ) {
				continue;
			}
			$nameless_btns[] = substr( $full, 0, 200 );
		}
		if ( $nameless_btns ) {
			$issues[] = array(
				'type'     => 'button-no-name',
				'severity' => 'critical',
				'wcag'     => '4.1.2',
				'message'  => sprintf(
					/* translators: %d: number of buttons */
					_n( '%d button has no accessible name.', '%d buttons have no accessible name.', count( $nameless_btns ), 'livq-accessfix' ),
					count( $nameless_btns )
				),
				'count'    => count( $nameless_btns ),
				'sample'   => $nameless_btns[0],
				'status'   => self::issue_status( 'fix_button_labels', $options ),
			);
		}

		// 6. Inputs without label - WCAG 1.3.1
		preg_match_all( '/<label\b[^>]*\bfor=["\']([^"\']+)["\'][^>]*>/i', $html, $label_m );
		$labeled_ids = array_flip( $label_m[1] ?? array() );
		preg_match_all( '/<input\b([^>]*)>/i', $html, $input_m );
		$unlabeled = array();
		foreach ( $input_m[1] as $attrs ) {
			if ( preg_match( '/\btype=["\'](?:hidden|submit|button|reset|image)["\']/', $attrs ) ) {
				continue;
			}
			if ( preg_match( '/\baria-(?:label|labelledby)=/i', $attrs ) ) {
				continue;
			}
			if ( preg_match( '/\bid=["\']([^"\']+)["\']/', $attrs, $id_m ) && isset( $labeled_ids[ $id_m[1] ] ) ) {
				continue;
			}
			$unlabeled[] = '<input ' . substr( $attrs, 0, 150 ) . '>';
		}
		if ( $unlabeled ) {
			$issues[] = array(
				'type'     => 'input-no-label',
				'severity' => 'critical',
				'wcag'     => '1.3.1',
				'message'  => sprintf(
					/* translators: %d: number of inputs */
					_n( '%d form field has no accessible label.', '%d form fields have no accessible label.', count( $unlabeled ), 'livq-accessfix' ),
					count( $unlabeled )
				),
				'count'    => count( $unlabeled ),
				'sample'   => $unlabeled[0],
				'status'   => self::issue_status( 'fix_input_labels', $options ),
			);
		}

		// 7. iframes without title - WCAG 4.1.2
		preg_match_all( '/<iframe\b(?![^>]*\btitle=["\'][^"\']+["\'])[^>]*>/i', $html, $m );
		if ( $m[0] ) {
			$issues[] = array(
				'type'     => 'iframe-no-title',
				'severity' => 'high',
				'wcag'     => '4.1.2',
				'message'  => sprintf(
					/* translators: %d: number of iframes */
					_n( '%d iframe has no title attribute.', '%d iframes have no title attribute.', count( $m[0] ), 'livq-accessfix' ),
					count( $m[0] )
				),
				'count'    => count( $m[0] ),
				'sample'   => substr( $m[0][0], 0, 200 ),
				'status'   => self::issue_status( 'fix_iframe_titles', $options ),
			);
		}

		// 8. target="_blank" links without screen-reader notice - WCAG 2.4.4
		preg_match_all( '/<a\b[^>]*target=["\']_blank["\'][^>]*>.*?<\/a>/is', $html, $blank_m );
		$blank_no_sr = array();
		foreach ( $blank_m[0] as $lnk ) {
			if ( false === strpos( $lnk, 'screen-reader-text' ) ) {
				$blank_no_sr[] = substr( $lnk, 0, 200 );
			}
		}
		if ( $blank_no_sr ) {
			$issues[] = array(
				'type'     => 'blank-no-sr',
				'severity' => 'high',
				'wcag'     => '2.4.4',
				'message'  => sprintf(
					/* translators: %d: number of links */
					_n( '%d link opening in a new tab lacks a screen-reader notice.', '%d links opening in new tabs lack a screen-reader notice.', count( $blank_no_sr ), 'livq-accessfix' ),
					count( $blank_no_sr )
				),
				'count'    => count( $blank_no_sr ),
				'sample'   => $blank_no_sr[0],
				'status'   => self::issue_status( 'fix_external_links', $options ),
			);
		}

		// 9. Heading hierarchy skips - WCAG 1.3.1
		preg_match_all( '/<h([1-6])\b[^>]*>/i', $html, $h_m );
		$levels = array_map( 'intval', $h_m[1] );
		$skips  = array();
		for ( $i = 1, $len = count( $levels ); $i < $len; $i++ ) {
			if ( $levels[ $i ] - $levels[ $i - 1 ] > 1 ) {
				$skips[] = 'H' . $levels[ $i - 1 ] . '→H' . $levels[ $i ];
			}
		}
		if ( $skips ) {
			$unique_skips = array_unique( $skips );
			$issues[]     = array(
				'type'     => 'heading-skip',
				'severity' => 'high',
				'wcag'     => '1.3.1',
				'message'  => sprintf(
					/* translators: 1: number of skips, 2: list of skip pairs */
					__( 'Heading hierarchy has %1$d skip(s): %2$s', 'livq-accessfix' ),
					count( $skips ),
					implode( ', ', $unique_skips )
				),
				'count'    => count( $skips ),
				'sample'   => implode( ', ', $unique_skips ),
				'status'   => self::issue_status( '', $options ),
			);
		}

		// 10. Multiple <h1> elements - WCAG 1.3.1
		$h1_count = preg_match_all( '/<h1\b/i', $html );
		if ( $h1_count > 1 ) {
			$issues[] = array(
				'type'     => 'multiple-h1',
				'severity' => 'warning',
				'wcag'     => '1.3.1',
				'message'  => sprintf(
					/* translators: %d: number of H1 elements */
					__( '%d H1 elements found - a page should have exactly one.', 'livq-accessfix' ),
					$h1_count
				),
				'count'    => $h1_count,
				'sample'   => '',
				'status'   => self::issue_status( '', $options ),
			);
		}

		// 11. Empty headings - WCAG 2.4.6
		preg_match_all( '/<h[1-6]\b[^>]*>\s*<\/h[1-6]>/i', $html, $m );
		if ( $m[0] ) {
			$issues[] = array(
				'type'     => 'heading-empty',
				'severity' => 'warning',
				'wcag'     => '2.4.6',
				'message'  => sprintf(
					/* translators: %d: number of empty headings */
					_n( '%d empty heading found.', '%d empty headings found.', count( $m[0] ), 'livq-accessfix' ),
					count( $m[0] )
				),
				'count'    => count( $m[0] ),
				'sample'   => substr( $m[0][0], 0, 200 ),
				'status'   => self::issue_status( '', $options ),
			);
		}

		// 12. Tables without header cells - WCAG 1.3.1
		preg_match_all( '/<table\b[^>]*>.*?<\/table>/is', $html, $tbl_m );
		$tables_no_th = array();
		foreach ( $tbl_m[0] as $tbl ) {
			if ( false === stripos( $tbl, '<th' ) && false === stripos( $tbl, 'role="columnheader"' ) ) {
				$tables_no_th[] = substr( $tbl, 0, 200 );
			}
		}
		if ( $tables_no_th ) {
			$issues[] = array(
				'type'     => 'table-no-header',
				'severity' => 'warning',
				'wcag'     => '1.3.1',
				'message'  => sprintf(
					/* translators: %d: number of tables */
					_n( '%d table has no header cells (&lt;th&gt;).', '%d tables have no header cells.', count( $tables_no_th ), 'livq-accessfix' ),
					count( $tables_no_th )
				),
				'count'    => count( $tables_no_th ),
				'sample'   => $tables_no_th[0],
				'status'   => self::issue_status( '', $options ),
			);
		}

		// 13. Missing <main> landmark - WCAG 1.3.6
		if ( ! preg_match( '/<main\b|role=["\']main["\']/i', $html ) ) {
			$issues[] = array(
				'type'     => 'no-main-landmark',
				'severity' => 'warning',
				'wcag'     => '1.3.6',
				'message'  => __( 'No &lt;main&gt; landmark found. Screen reader users cannot jump directly to main content.', 'livq-accessfix' ),
				'count'    => 1,
				'sample'   => '',
				'status'   => self::issue_status( '', $options ),
			);
		}

		// 14. WooCommerce quantity buttons without aria-label - WCAG 4.1.2
		if ( in_array( $tpl_key, array( 'wc-product', 'wc-cart' ), true ) ) {
			preg_match_all( '/<button\b[^>]*class=["\'][^"\']*(?:plus|minus|increase|decrease)[^"\']*["\']\s(?![^>]*aria-label)[^>]*>/i', $html, $m );
			if ( $m[0] ) {
				$issues[] = array(
					'type'     => 'wc-qty-no-label',
					'severity' => 'critical',
					'wcag'     => '4.1.2',
					'message'  => sprintf(
						/* translators: %d: number of quantity buttons */
						_n( '%d WooCommerce quantity button has no aria-label.', '%d WooCommerce quantity buttons have no aria-label.', count( $m[0] ), 'livq-accessfix' ),
						count( $m[0] )
					),
					'count'    => count( $m[0] ),
					'sample'   => substr( $m[0][0], 0, 200 ),
					'status'   => self::issue_status( 'woocommerce_a11y', $options ),
				);
			}
		}

		// 15. Fields collecting user information without autocomplete - WCAG 1.3.5
		preg_match_all( '/<(input|textarea|select)\b([^>]*)>/i', $html, $ac_m );
		$no_autocomplete = array();
		foreach ( $ac_m[2] as $i => $attrs ) {
			if ( preg_match( '/\bautocomplete=/i', $attrs ) ) {
				continue;
			}

			$type = '';
			if ( preg_match( '/\btype=["\']([^"\']*)["\']/i', $attrs, $t ) ) {
				$type = strtolower( trim( $t[1] ) );
			}

			$name = '';
			if ( preg_match( '/\bname=["\']([^"\']*)["\']/i', $attrs, $n ) ) {
				$name = $n[1];
			} elseif ( preg_match( '/\bid=["\']([^"\']*)["\']/i', $attrs, $id_m ) ) {
				$name = $id_m[1];
			}

			// Same derivation the fixer uses, so the scanner never reports a field
			// the module would not have been able to fix anyway.
			if ( '' === LIVQACEA_Frontend::autocomplete_token( $name, $type, strtolower( $ac_m[1][ $i ] ) ) ) {
				continue;
			}

			$no_autocomplete[] = '<' . strtolower( $ac_m[1][ $i ] ) . ' ' . substr( trim( $attrs ), 0, 150 ) . '>';
		}
		if ( $no_autocomplete ) {
			$issues[] = array(
				'type'     => 'input-no-autocomplete',
				'severity' => 'warning',
				'wcag'     => '1.3.5',
				'message'  => sprintf(
					/* translators: %d: number of form fields */
					_n( '%d field collecting user information has no autocomplete attribute.', '%d fields collecting user information have no autocomplete attribute.', count( $no_autocomplete ), 'livq-accessfix' ),
					count( $no_autocomplete )
				),
				'count'    => count( $no_autocomplete ),
				'sample'   => $no_autocomplete[0],
				'status'   => self::issue_status( 'fix_autocomplete', $options ),
			);
		}

		return $issues;
	}

	/**
	 * Classifies an issue against the module that is supposed to cover it.
	 *
	 * The scanner fetches the site's *live* HTML, which the output buffer has
	 * already remediated. An issue that still shows up therefore survived the
	 * module - flagging it "Auto-fixed" (the previous behaviour) told the exact
	 * opposite of the truth. Three honest states instead:
	 *
	 *  - 'manual'     no module covers this check; a human has to fix it.
	 *  - 'fixable'    a module covers it but is currently switched off.
	 *  - 'unresolved' the module is on and the issue is still present.
	 *
	 * @param string               $module  Option key covering the issue, or ''.
	 * @param array<string, mixed> $options Current plugin options.
	 * @return string One of 'manual', 'fixable', 'unresolved'.
	 */
	private static function issue_status( string $module, array $options ): string {
		if ( '' === $module ) {
			return 'manual';
		}

		return empty( $options[ $module ] ) ? 'fixable' : 'unresolved';
	}

	/**
	 * Returns the CSS class and label for an issue status.
	 *
	 * @param string $status One of 'manual', 'fixable', 'unresolved'.
	 * @return array{class:string, text:string}
	 */
	private static function status_label( string $status ): array {
		switch ( $status ) {
			case 'unresolved':
				return array(
					'class' => 'livqacea-tag-unresolved',
					'text'  => __( '⚠ Module active - still detected', 'livq-accessfix' ),
				);

			case 'fixable':
				return array(
					'class' => 'livqacea-tag-fixable',
					'text'  => __( '◻ Enable the matching module', 'livq-accessfix' ),
				);

			default:
				return array(
					'class' => 'livqacea-tag-manual',
					'text'  => __( '✗ Manual fix', 'livq-accessfix' ),
				);
		}
	}

	// -----------------------------------------------------------------------
	// Scoring
	// -----------------------------------------------------------------------

	/**
	 * Calculates an accessibility score (0–100) from a list of issues.
	 *
	 * @param array<int, array<string, mixed>> $issues List of detected issues.
	 * @return int Score from 0 to 100.
	 */
	private static function calculate_score( array $issues ): int {
		$deductions = array(
			'critical' => 10,
			'high'     => 5,
			'warning'  => 2,
		);
		$score      = 100;
		foreach ( $issues as $issue ) {
			$per    = $deductions[ $issue['severity'] ] ?? 0;
			$score -= $per * min( (int) $issue['count'], 5 );
		}
		return max( 0, $score );
	}

	/**
	 * Returns a hex color representing the score band (green/yellow/red).
	 *
	 * @param int $score Accessibility score (0–100).
	 * @return string Hex color code.
	 */
	private static function score_color( int $score ): string {
		if ( $score >= 90 ) {
			return '#46b450';
		}
		if ( $score >= 75 ) {
			return '#7ad03a';
		}
		if ( $score >= 60 ) {
			return '#dba617';
		}
		if ( $score >= 40 ) {
			return '#f56e28';
		}
		return '#dc3232';
	}

	/**
	 * Returns up to 200 characters of HTML around a search string for issue context.
	 *
	 * @param string $html   Full page HTML.
	 * @param string $search String to locate in the HTML.
	 * @return string Snippet or empty string if not found.
	 */
	private static function snippet( string $html, string $search ): string {
		$pos = stripos( $html, $search );
		return false !== $pos ? substr( $html, $pos, 200 ) : '';
	}

	// -----------------------------------------------------------------------
	// Admin page
	// -----------------------------------------------------------------------

	/**
	 * Renders the Accessibility Scanner admin page.
	 *
	 * @return void
	 */
	public static function render_admin_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$templates = self::discover_templates();
		$cache     = get_transient( self::CACHE_KEY );
		$cache     = is_array( $cache ) ? $cache : array();
		$nonce     = wp_create_nonce( self::NONCE_KEY );
		$ajax_url  = admin_url( 'admin-ajax.php' );

		// Group templates by plugin/section.
		$groups = array();
		foreach ( $templates as $tpl ) {
			$groups[ $tpl['group'] ][] = $tpl;
		}
		?>
<div class="wrap livqacea-scanner-wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Accessibility Scanner', 'livq-accessfix' ); ?></h1>
	<hr class="wp-header-end">
	<p class="description" style="margin-top:8px;">
		<?php esc_html_e( 'Scans representative page templates for WCAG 2.2 AA / EAA issues. Each template type is fetched once to cover all similar pages. Results are cached for 24 hours.', 'livq-accessfix' ); ?>
	</p>

	<div id="livqacea-scan-controls" style="margin:16px 0;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
		<button id="livqacea-btn-scan" class="button button-primary"><?php esc_html_e( '▶ Start Full Scan', 'livq-accessfix' ); ?></button>
		<button id="livqacea-btn-clear" class="button"><?php esc_html_e( 'Clear Cache', 'livq-accessfix' ); ?></button>
		<span id="livqacea-progress-msg" style="display:none;font-style:italic;color:#646970;"></span>
	</div>

	<div id="livqacea-summary-strip" style="display:<?php echo $cache ? 'flex' : 'none'; ?>;gap:20px;align-items:center;flex-wrap:wrap;background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px;margin-bottom:16px;">
		<div id="livqacea-badge-score" style="width:72px;height:72px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.1rem;font-weight:700;background:#f0f0f1;color:#1d2327;border:3px solid #c3c4c7;">-</div>
		<div style="display:flex;gap:24px;flex-wrap:wrap;">
			<span><strong id="livqacea-cnt-critical" style="color:#dc3232;">0</strong> <?php esc_html_e( 'Critical', 'livq-accessfix' ); ?></span>
			<span><strong id="livqacea-cnt-high" style="color:#f56e28;">0</strong> <?php esc_html_e( 'High', 'livq-accessfix' ); ?></span>
			<span><strong id="livqacea-cnt-warning" style="color:#dba617;">0</strong> <?php esc_html_e( 'Warnings', 'livq-accessfix' ); ?></span>
			<span><strong id="livqacea-cnt-fixed" style="color:#2271b1;">0</strong> <?php esc_html_e( 'Fixable by enabling a module', 'livq-accessfix' ); ?></span>
		</div>
		<button id="livqacea-btn-csv" class="button" style="margin-left:auto;"><?php esc_html_e( 'Export CSV', 'livq-accessfix' ); ?></button>
	</div>

		<?php foreach ( $groups as $group_name => $group_templates ) : ?>
	<h2 style="margin-top:24px;"><?php echo esc_html( $group_name ); ?></h2>
	<table class="wp-list-table widefat fixed striped" style="margin-bottom:4px;">
		<thead>
			<tr>
				<th style="width:200px;"><?php esc_html_e( 'Template', 'livq-accessfix' ); ?></th>
				<th><?php esc_html_e( 'URL', 'livq-accessfix' ); ?></th>
				<th style="width:90px;"><?php esc_html_e( 'Score', 'livq-accessfix' ); ?></th>
				<th style="width:70px;"><?php esc_html_e( 'Issues', 'livq-accessfix' ); ?></th>
				<th style="width:140px;"><?php esc_html_e( 'Last Scan', 'livq-accessfix' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php
			foreach ( $group_templates as $tpl ) :
				$c        = $cache[ $tpl['key'] ] ?? null;
				$score    = $c ? (int) $c['score'] : null;
				$n_issues = $c ? count( $c['issues'] ) : null;
				$date     = $c ? esc_html( $c['scanned_at'] ) : '-';
				?>
			<tr id="livqacea-row-<?php echo esc_attr( $tpl['key'] ); ?>"
				data-key="<?php echo esc_attr( $tpl['key'] ); ?>"
				data-url="<?php echo esc_url( $tpl['url'] ); ?>">
				<td><strong><?php echo esc_html( $tpl['label'] ); ?></strong></td>
				<td style="word-break:break-all;">
					<a href="<?php echo esc_url( $tpl['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( urldecode( $tpl['url'] ) ); ?></a>
				</td>
				<td>
					<span class="livqacea-score-val" id="livqacea-score-<?php echo esc_attr( $tpl['key'] ); ?>"
						style="font-weight:700;<?php echo null !== $score ? 'color:' . esc_attr( self::score_color( $score ) ) . ';' : ''; ?>">
						<?php echo null !== $score ? esc_html( $score ) . '/100' : '-'; ?>
					</span>
				</td>
				<td>
					<span id="livqacea-issues-<?php echo esc_attr( $tpl['key'] ); ?>">
						<?php echo null !== $n_issues ? esc_html( (string) $n_issues ) : '-'; ?>
					</span>
				</td>
				<td id="livqacea-date-<?php echo esc_attr( $tpl['key'] ); ?>"><?php echo esc_html( $date ); ?></td>
			</tr>
				<?php if ( $c && ! empty( $c['issues'] ) ) : ?>
			<tr class="livqacea-detail-row" id="livqacea-detail-<?php echo esc_attr( $tpl['key'] ); ?>">
				<td colspan="5" style="padding:0;background:#f9f9f9;">
					<?php self::render_issues_table( $c['issues'] ); ?>
				</td>
			</tr>
			<?php elseif ( $c ) : ?>
			<tr class="livqacea-detail-row" id="livqacea-detail-<?php echo esc_attr( $tpl['key'] ); ?>">
				<td colspan="5" style="padding:8px 10px;background:#f9f9f9;color:#46b450;">
					✓ <?php esc_html_e( 'No issues detected', 'livq-accessfix' ); ?>
				</td>
			</tr>
			<?php endif; ?>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php endforeach; ?>
</div>

		<?php
	}

	/**
	 * Renders a server-side issues table (used when page loads with cached data).
	 *
	 * @param array<int, array<string, mixed>> $issues List of detected issues to display.
	 */
	private static function render_issues_table( array $issues ): void {
		if ( empty( $issues ) ) {
			echo '<p style="padding:10px 16px;color:#1e8325;font-weight:600;">✓ ' . esc_html__( 'No issues detected', 'livq-accessfix' ) . '</p>';
			return;
		}
		echo '<table class="livqacea-issues-tbl"><thead><tr>';
		echo '<th style="width:110px;">' . esc_html__( 'Severity', 'livq-accessfix' ) . '</th>';
		echo '<th style="width:100px;">' . esc_html__( 'WCAG', 'livq-accessfix' ) . '</th>';
		echo '<th>' . esc_html__( 'Issue', 'livq-accessfix' ) . '</th>';
		echo '<th style="width:120px;">' . esc_html__( 'Status', 'livq-accessfix' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $issues as $iss ) {
			$sev_key = sanitize_html_class( $iss['severity'] );
			$tag     = self::status_label( isset( $iss['status'] ) ? (string) $iss['status'] : 'manual' );
			$status  = '<span class="' . esc_attr( $tag['class'] ) . '">' . esc_html( $tag['text'] ) . '</span>';
			$sample  = ! empty( $iss['sample'] )
				? '<code class="livqacea-sample">' . esc_html( $iss['sample'] ) . '</code>'
				: '';
			echo '<tr>';
			echo '<td><span class="livqacea-badge livqacea-badge-' . esc_attr( $sev_key ) . '">' . esc_html( ucfirst( $iss['severity'] ) ) . '</span></td>';
			echo '<td><code style="font-size:.8rem;">' . esc_html( $iss['wcag'] ) . '</code></td>';
			echo '<td>' . wp_kses_post( $iss['message'] ) . wp_kses( $sample, array( 'code' => array( 'class' => array() ) ) ) . '</td>';
			echo '<td>' . wp_kses( $status, array( 'span' => array( 'class' => array() ) ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}
}
