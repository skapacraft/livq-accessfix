<?php
/**
 * Admin dashboard - Settings API integration.
 *
 * Registers the top-level "LivQ AccessFix" menu, hooks into the WordPress
 * Settings API for CSRF-safe form processing, and sanitises every option
 * before it reaches the database.
 *
 * Architecture notes
 * ------------------
 * • We use register_setting() + Settings API rather than manual $_POST
 *   handling. WordPress handles nonce verification internally via
 *   check_admin_referer() called inside options.php - this is the recommended
 *   approach and is fully CSRF-safe.
 * • All output is escaped at the point of echo, never stored pre-escaped.
 *   Storing escaped HTML in the DB is an anti-pattern (breaks i18n/double-esc).
 * • Sanitisation callback converts each checkbox value to a strict boolean
 *   stored as 1/0, preventing any injection through manipulated POST data.
 *
 * @package LivQ_AccessFix
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class LIVQACEA_Backend
 */
class LIVQACEA_Backend {

	/**
	 * The WP Settings API option group name.
	 * Must match the first argument to settings_fields().
	 *
	 * @var string
	 */
	const OPTION_GROUP = 'livqacea_option_group';

	/**
	 * The option name in wp_options.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'livqacea_options';

	/**
	 * Settings section ID - base accessibility modules.
	 *
	 * @var string
	 */
	const SECTION_ID = 'livqacea_main_section';

	/**
	 * Settings section ID - advanced structural modules (v1.1.0).
	 *
	 * @var string
	 */
	const SECTION_ADVANCED_ID = 'livqacea_advanced_section';

	/**
	 * Settings section ID - HTML output remediations (EAA).
	 *
	 * @var string
	 */
	const SECTION_REMEDIATION_ID = 'livqacea_remediation_section';

	/**
	 * The admin page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'livq-accessfix';

	/**
	 * Current options (read-only, for rendering the form state).
	 *
	 * @var array<string, bool>
	 */
	private $options;

	/**
	 * Hook suffix of the settings page, captured from add_menu_page().
	 * Used to scope asset enqueueing to this screen only.
	 *
	 * @var string
	 */
	private $settings_hook = '';

	/**
	 * Constructor.
	 *
	 * @param array<string, bool> $options Plugin options.
	 */
	public function __construct( array $options ) {
		$this->options = $options;
		$this->register_hooks();
	}

	/**
	 * Registers WP actions for the admin interface.
	 *
	 * @return void
	 */
	private function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_notice' ) );
		add_action( 'admin_menu', array( $this, 'add_issues_log_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_settings_assets' ) );
	}

	/**
	 * Enqueues the settings page CSS/JS - scoped to this screen only.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_settings_assets( string $hook ): void {
		if ( $hook !== $this->settings_hook ) {
			return;
		}

		wp_register_style( 'livqacea-admin-inline', false, array(), LIVQACEA_VERSION );
		wp_enqueue_style( 'livqacea-admin-inline' );
		wp_add_inline_style( 'livqacea-admin-inline', $this->get_admin_css() );

		wp_enqueue_script(
			'livqacea-skip-detect',
			LIVQACEA_PLUGIN_URL . 'assets/js/livqacea-skip-detect.js',
			array(),
			LIVQACEA_VERSION,
			true
		);
		wp_localize_script(
			'livqacea-skip-detect',
			'livqaceaSkipDetect',
			array(
				'nonce'   => wp_create_nonce( 'livqacea_detect_skip_target' ),
				'strings' => array(
					'detecting'     => __( 'Detecting…', 'livq-accessfix' ),
					'notFound'      => __( 'Not found - enter manually.', 'livq-accessfix' ),
					'requestFailed' => __( 'Request failed.', 'livq-accessfix' ),
				),
			)
		);
	}

	// -----------------------------------------------------------------------
	// Admin menu
	// -----------------------------------------------------------------------

	/**
	 * Adds the plugin settings page to the WP admin menu.
	 *
	 * A top-level menu (add_menu_page) is used because the plugin ships several
	 * screens - Settings, Issues Log, Scanner, Contrast Checker and the
	 * Accessibility Statement generator - which all hang off this parent.
	 *
	 * @return void
	 */
	public function add_settings_page(): void {
		$this->settings_hook = (string) add_menu_page(
			__( 'LivQ AccessFix – EAA & A11y AutoFix', 'livq-accessfix' ),
			__( 'LivQ AccessFix', 'livq-accessfix' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_settings_page' ),
			'dashicons-universal-access',
			81
		);

		// Rename the auto-created first submenu entry.
		add_submenu_page(
			self::PAGE_SLUG,
			__( 'LivQ AccessFix – Settings', 'livq-accessfix' ),
			__( 'Settings', 'livq-accessfix' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Registers the Issues Log submenu page under the plugin's top-level menu.
	 *
	 * @return void
	 */
	public function add_issues_log_page(): void {
		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Accessibility Issues Log', 'livq-accessfix' ),
			__( 'Issues Log', 'livq-accessfix' ),
			'manage_options',
			'livqacea-issues-log',
			array( $this, 'render_issues_log_page' )
		);
	}

	// -----------------------------------------------------------------------
	// Settings API registration
	// -----------------------------------------------------------------------

	/**
	 * Registers settings, sections, and fields via the WordPress Settings API.
	 *
	 * This approach delegates CSRF protection to options.php (WordPress core)
	 * which verifies the nonce generated by settings_fields() automatically,
	 * meaning we never need to manually verify $_POST['_wpnonce'] in our code.
	 *
	 * @return void
	 */
	public function register_settings(): void {

		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			array(
				'sanitize_callback' => array( $this, 'sanitize_options' ),
				'default'           => array(),
			)
		);

		// Section 1 - base modules.
		add_settings_section(
			self::SECTION_ID,
			esc_html__( 'Accessibility Modules (WCAG 2.2 AA)', 'livq-accessfix' ),
			array( $this, 'render_section_intro' ),
			self::PAGE_SLUG
		);

		foreach ( $this->get_fields() as $field ) {
			add_settings_field(
				$field['id'],
				$field['label'],
				array( $this, 'render_checkbox_field' ),
				self::PAGE_SLUG,
				self::SECTION_ID,
				$field
			);
		}

		// Section 2 - HTML Output Remediations (EAA).
		add_settings_section(
			self::SECTION_REMEDIATION_ID,
			esc_html__( 'HTML Output Remediations (EAA)', 'livq-accessfix' ),
			array( $this, 'render_remediation_section_intro' ),
			self::PAGE_SLUG
		);

		foreach ( $this->get_remediation_fields() as $field ) {
			add_settings_field(
				$field['id'],
				$field['label'],
				array( $this, 'render_checkbox_field' ),
				self::PAGE_SLUG,
				self::SECTION_REMEDIATION_ID,
				$field
			);
		}

		// Section - WooCommerce Accessibility (conditional on WooCommerce being active).
		if ( class_exists( 'WooCommerce' ) ) {
			add_settings_section(
				'livqacea_woocommerce_section',
				esc_html__( 'WooCommerce Accessibility', 'livq-accessfix' ),
				array( $this, 'render_woocommerce_section_intro' ),
				self::PAGE_SLUG
			);

			add_settings_field(
				'woocommerce_a11y',
				esc_html__( 'Enable WooCommerce fixes', 'livq-accessfix' ),
				array( $this, 'render_checkbox_field' ),
				self::PAGE_SLUG,
				'livqacea_woocommerce_section',
				array(
					'id'          => 'woocommerce_a11y',
					'label'       => __( 'WooCommerce accessibility fixes', 'livq-accessfix' ),
					'description' => __( 'Adds aria-label to quantity +/− buttons, the product gallery trigger link, and Add to Cart buttons on archive pages. Injects a JS live region for cart announcements and aria-current for variation selectors. WCAG 4.1.2 / 2.4.4.', 'livq-accessfix' ),
					'wcag'        => 'WCAG 4.1.2',
				)
			);
		}

		// Section 3 - advanced structural modules (v1.1.0).
		add_settings_section(
			self::SECTION_ADVANCED_ID,
			esc_html__( 'Advanced Controls & Structure', 'livq-accessfix' ),
			array( $this, 'render_advanced_section_intro' ),
			self::PAGE_SLUG
		);

		foreach ( $this->get_advanced_fields() as $field ) {
			add_settings_field(
				$field['id'],
				$field['label'],
				array( $this, 'render_checkbox_field' ),
				self::PAGE_SLUG,
				self::SECTION_ADVANCED_ID,
				$field
			);
		}

		// Section 3 - Accessibility Statement (v1.4.0).
		add_settings_section(
			'livqacea_statement_section',
			esc_html__( 'Accessibility Statement (EAA)', 'livq-accessfix' ),
			array( $this, 'render_statement_section_intro' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'a11y_statement',
			esc_html__( 'Enable Accessibility Statement', 'livq-accessfix' ),
			array( $this, 'render_statement_field' ),
			self::PAGE_SLUG,
			'livqacea_statement_section'
		);

		// Section 4 - configuration (v1.3.1).
		add_settings_section(
			'livqacea_config_section',
			esc_html__( 'Configuration', 'livq-accessfix' ),
			array( $this, 'render_config_section_intro' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'skip_link_target',
			esc_html__( 'Skip Link Target', 'livq-accessfix' ),
			array( $this, 'render_skip_target_field' ),
			self::PAGE_SLUG,
			'livqacea_config_section'
		);

		add_settings_field(
			'delete_on_uninstall',
			esc_html__( 'Data on Uninstall', 'livq-accessfix' ),
			array( $this, 'render_delete_on_uninstall_field' ),
			self::PAGE_SLUG,
			'livqacea_config_section'
		);
	}

	/**
	 * Returns the field definitions array.
	 *
	 * Centralising field definitions avoids repetition and makes it trivial
	 * to add new options in future versions.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function get_fields(): array {
		return array(
			array(
				'id'          => 'fix_external_links',
				'label'       => __( 'Fix external links (target="_blank")', 'livq-accessfix' ),
				'description' => __( 'Automatically adds a screen reader notice to every link that opens in a new browser tab. WCAG Technique G201 - Criterion 2.4.4.', 'livq-accessfix' ),
				'wcag'        => 'WCAG 2.4.4',
			),
			array(
				'id'          => 'inject_skip_link',
				'label'       => __( 'Inject Skip Link (Skip to content)', 'livq-accessfix' ),
				'description' => __( 'Inserts an invisible link as the first element in <body>, allowing keyboard users to skip repeated navigation. WCAG Technique G1 - Criterion 2.4.1.', 'livq-accessfix' ),
				'wcag'        => 'WCAG 2.4.1',
			),
			array(
				'id'          => 'fix_image_alt',
				'label'       => __( 'Fix alt attribute on decorative images', 'livq-accessfix' ),
				'description' => __( 'Ensures images without alternative text explicitly receive alt="" instead of no attribute, preventing screen readers from announcing the file name. WCAG Technique H67 - Criterion 1.1.1.', 'livq-accessfix' ),
				'wcag'        => 'WCAG 1.1.1',
			),
			array(
				'id'          => 'inject_focus_css',
				'label'       => __( 'Inject High-Contrast Focus CSS', 'livq-accessfix' ),
				'description' => __( 'Adds a global CSS rule that ensures a visible, high-contrast focus indicator (3px solid #0056b3) on all interactive elements. WCAG Criterion 2.4.11.', 'livq-accessfix' ),
				'wcag'        => 'WCAG 2.4.11',
			),
		);
	}

	/**
	 * Returns the field definitions for the advanced section (v1.1.0).
	 *
	 * @return array<int, array<string, string>>
	 */
	private function get_advanced_fields(): array {
		return array(
			array(
				'id'          => 'menu_aria_helper',
				'label'       => __( 'Menu Accessibility Helper (ARIA)', 'livq-accessfix' ),
				'description' => __( 'Automatically adds aria-haspopup="true" and aria-expanded="false/true" to navigation links that open a sub-menu, with native JavaScript toggle management. WCAG Criterion 4.1.2.', 'livq-accessfix' ),
				'wcag'        => 'WCAG 4.1.2',
			),
			array(
				'id'          => 'heading_hierarchy_check',
				'label'       => __( 'Heading Hierarchy Check (H1–H6)', 'livq-accessfix' ),
				'description' => __( 'On post or page save, analyses the heading structure and shows an admin notice if a hierarchy skip is detected (e.g. H2 → H4) that disorients screen readers. WCAG Criteria 1.3.1 and Technique H42.', 'livq-accessfix' ),
				'wcag'        => 'WCAG 1.3.1',
			),
			array(
				'id'          => 'gutenberg_prepublish',
				'label'       => __( 'Pre-Publish Accessibility Checklist (Gutenberg)', 'livq-accessfix' ),
				'description' => __( 'Adds a panel to the Gutenberg pre-publish drawer that checks in real time: (A) images without alt text, (B) links using a raw URL as clickable text. WCAG Criteria 1.1.1 and 2.4.4.', 'livq-accessfix' ),
				'wcag'        => 'WCAG 1.1.1 / 2.4.4',
			),
		);
	}

	/**
	 * Returns the field definitions for the HTML output remediations section.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function get_remediation_fields(): array {
		return array(
			array(
				'id'          => 'fix_nameless_links',
				'label'       => __( 'Fix nameless links (image & icon links)', 'livq-accessfix' ),
				'description' => __( 'Adds aria-label to links whose only content is an image with empty alt or an SVG icon - common for sponsor logos, partner logos, and social icons. Label is derived from img title, link title, or recognised domain (YouTube, Facebook, etc.). WCAG Criteria 2.4.4 / 4.1.2.', 'livq-accessfix' ),
				'wcag'        => 'WCAG 2.4.4',
			),
			array(
				'id'          => 'fix_iframe_titles',
				'label'       => __( 'Fix untitled iframes', 'livq-accessfix' ),
				'description' => __( 'Adds a descriptive title attribute to every <iframe> that has none. Titles are automatically matched from the src URL: YouTube, Vimeo, Google Maps, Calendly, iubenda, and more. Screen readers announce untitled iframes simply as "frame". WCAG Criterion 4.1.2.', 'livq-accessfix' ),
				'wcag'        => 'WCAG 4.1.2',
			),
			array(
				'id'          => 'fix_input_labels',
				'label'       => __( 'Fix unlabelled form inputs', 'livq-accessfix' ),
				'description' => __( 'Adds aria-label to <input>, <textarea>, and <select> fields that have no associated <label> or aria-label attribute. The label is derived from the placeholder text or name attribute. Only fields with no existing accessible name are modified. WCAG Criteria 1.3.1 / 3.3.2.', 'livq-accessfix' ),
				'wcag'        => 'WCAG 1.3.1',
			),
			array(
				'id'          => 'fix_button_labels',
				'label'       => __( 'Fix nameless buttons (icon-only buttons)', 'livq-accessfix' ),
				'description' => __( 'Adds aria-label to <button> elements that contain only an icon or an SVG, which screen readers otherwise announce as just "button". The label is derived from the purpose words in the button\'s own class or in its icon class - menu, search, close, next, back to top, and more. Buttons whose purpose cannot be recognised are left untouched. WCAG Criterion 4.1.2.', 'livq-accessfix' ),
				'wcag'        => 'WCAG 4.1.2',
			),
			array(
				'id'          => 'fix_autocomplete',
				'label'       => __( 'Identify input purpose (autocomplete)', 'livq-accessfix' ),
				'description' => __( 'Adds the autocomplete attribute to form fields that collect information about the user - name, email, telephone, address, postcode, country - so browsers, password managers and personalisation tools can identify and fill them. Fields that already declare an autocomplete value, including autocomplete="off", are never changed. WCAG Criterion 1.3.5.', 'livq-accessfix' ),
				'wcag'        => 'WCAG 1.3.5',
			),
		);
	}

	/**
	 * Renders the intro text for the HTML Output Remediations section.
	 *
	 * @return void
	 */
	public function render_remediation_section_intro(): void {
		echo '<p class="description">' . esc_html__( 'These modules use the PHP output buffer to detect and patch common EAA non-conformances at render time, covering elements generated by themes, page builders, and widgets - not just post content.', 'livq-accessfix' ) . '</p>';
	}

	/**
	 * Renders the intro text for the WooCommerce section.
	 *
	 * @return void
	 */
	public function render_woocommerce_section_intro(): void {
		echo '<p class="description">' . esc_html__( 'WooCommerce-specific accessibility fixes applied via the PHP output buffer and a lightweight inline script. Only shown when WooCommerce is active.', 'livq-accessfix' ) . '</p>';
	}

	// -----------------------------------------------------------------------
	// Sanitisation
	// -----------------------------------------------------------------------

	/**
	 * Sanitises the raw $_POST data before it is stored in the database.
	 *
	 * Each checkbox either sends its value ('1') or is absent from the POST
	 * array entirely. We normalise to strict integers (0 or 1).
	 * This callback runs inside the nonce-protected options.php pipeline,
	 * so the data has already passed WordPress CSRF validation at this point.
	 *
	 * @param mixed $raw Raw input from $_POST.
	 * @return array<string, int> Sanitised option values.
	 */
	public function sanitize_options( $raw ): array {
		$sanitized     = array();
		$checkbox_keys = array(
			// Base modules (v1.0.0).
			'fix_external_links',
			'inject_skip_link',
			'fix_image_alt',
			'inject_focus_css',
			// HTML Output Remediations (v1.0.0 - EAA).
			'fix_nameless_links',
			'fix_iframe_titles',
			'fix_input_labels',
			// HTML Output Remediations (v1.1.0 - EAA).
			'fix_button_labels',
			'fix_autocomplete',
			// Advanced modules (v1.1.0).
			'menu_aria_helper',
			'heading_hierarchy_check',
			'gutenberg_prepublish',
			// WooCommerce (v1.0.0).
			'woocommerce_a11y',
			// Accessibility Statement (v1.0.0).
			'a11y_statement',
			// Configuration (v1.0.0).
			'delete_on_uninstall',
		);

		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		foreach ( $checkbox_keys as $key ) {
			// A missing key means "unchecked"; any present key means "checked".
			$sanitized[ $key ] = isset( $raw[ $key ] ) ? 1 : 0;
		}

		// The WooCommerce toggle is only rendered while WooCommerce is active.
		// Without this, saving the settings with WooCommerce temporarily
		// deactivated silently turned the module off for good.
		if ( ! class_exists( 'WooCommerce' ) ) {
			$stored                        = get_option( self::OPTION_NAME, array() );
			$sanitized['woocommerce_a11y'] = ( ! is_array( $stored ) || ! isset( $stored['woocommerce_a11y'] ) )
				? 1
				: (int) (bool) $stored['woocommerce_a11y'];
		}

		// skip_link_target: empty = auto-detect; must be a valid CSS #id selector.
		$raw_target = isset( $raw['skip_link_target'] ) ? sanitize_text_field( wp_unslash( $raw['skip_link_target'] ) ) : '';
		if ( '' === $raw_target || preg_match( '/^#[a-zA-Z][a-zA-Z0-9_-]{0,63}$/', $raw_target ) ) {
			$sanitized['skip_link_target'] = $raw_target;
		} else {
			$sanitized['skip_link_target'] = '';
		}

		return $sanitized;
	}

	// -----------------------------------------------------------------------
	// Admin notices
	// -----------------------------------------------------------------------

	/**
	 * Displays an admin notice after successful save.
	 *
	 * WordPress itself sets the 'settings-updated' query arg after options.php
	 * processes a valid form submission - we simply read it.
	 *
	 * @return void
	 */
	public function maybe_show_notice(): void {
		$screen = get_current_screen();

		// Compare against the hook suffix captured from add_menu_page(). The
		// previous hard-coded 'settings_page_' prefix never matched, because this
		// is a top-level menu ('toplevel_page_…'), so no confirmation was shown -
		// and core only auto-prints settings_errors() on its own options pages.
		if ( ! $screen || '' === $this->settings_hook || $screen->id !== $this->settings_hook ) {
			return;
		}

		// wp_unslash() + absint(): satisfies WPCS unslash/sanitize rules.
		// No nonce needed: 'settings-updated' is a read-only display flag written
		// by WordPress core in options.php after its own nonce check.
		if ( isset( $_GET['settings-updated'] ) && absint( wp_unslash( $_GET['settings-updated'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			printf(
				'<div class="notice notice-success is-dismissible"><p><strong>%s</strong></p></div>',
				esc_html__( 'Settings saved successfully.', 'livq-accessfix' )
			);
		}
	}

	// -----------------------------------------------------------------------
	// Rendering
	// -----------------------------------------------------------------------

	/**
	 * Renders the full settings page HTML.
	 *
	 * Capability check is redundant here (WordPress already checked it to
	 * display the menu item) but is included as defence-in-depth per WPTR
	 * recommendations.
	 *
	 * @return void
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">

			<h1 style="display:flex; align-items:center; gap:10px;">
				<?php esc_html_e( 'LivQ AccessFix – EAA & A11y AutoFix', 'livq-accessfix' ); ?>
				<span style="font-size:.55em; font-weight:400; color:#646970; background:#f0f0f1; border:1px solid #dcdcde; border-radius:4px; padding:2px 8px; letter-spacing:.02em;">
					v<?php echo esc_html( LIVQACEA_VERSION ); ?>
				</span>
			</h1>

			<div class="livqacea-admin-intro" style="margin: 12px 0 20px;">
				<p style="color:#50575e; max-width:680px;">
					<?php
					esc_html_e(
						'Enable or disable the automated WCAG 2.2 AA accessibility remediation modules. Changes are applied in real time on the frontend without altering database content.',
						'livq-accessfix'
					);
					?>
				</p>
			</div>

			<div class="livqacea-card" style="
				background: #fff;
				border: 1px solid #c3c4c7;
				border-radius: 8px;
				box-shadow: 0 1px 3px rgba(0,0,0,.05);
				max-width: 800px;
				margin-top: 20px;
				padding: 30px;
			">

				<form method="post" action="options.php">
					<?php
					/*
					 * settings_fields() outputs three hidden fields:
					 * - option_page (the group name)
					 * - action = 'update'
					 * - _wpnonce (the CSRF token)
					 * WordPress core verifies this nonce in options.php before
					 * calling our sanitize callback. We never touch $_POST directly.
					 */
					settings_fields( self::OPTION_GROUP );

					/*
					 * do_settings_sections() calls each add_settings_section()
					 * callback and then each add_settings_field() callback,
					 * outputting a proper <table> structure.
					 */
					do_settings_sections( self::PAGE_SLUG );

					submit_button(
						__( 'Save Settings', 'livq-accessfix' ),
						'primary',
						'submit',
						true,
						array( 'style' => 'background:#2271b1;border-color:#135e96;' )
					);
					?>
				</form>

			</div><!-- .livqacea-card -->

			<?php LIVQACEA_Plugin_Links::render_review_card(); ?>

			<div class="livqacea-footer" style="margin-top:20px; max-width:800px; display:flex; align-items:center; gap:6px; flex-wrap:wrap; font-size:.8rem; color:#8c8f94;">
				<span>v<?php echo esc_html( LIVQACEA_VERSION ); ?></span>
				<?php
				$sep   = '<span aria-hidden="true" style="color:#dcdcde;">·</span>';
				$style = 'color:#646970; text-decoration:none;';

				$email_subject = rawurlencode(
					sprintf( 'LivQ AccessFix v%s – Support – %s', LIVQACEA_VERSION, wp_parse_url( get_site_url(), PHP_URL_HOST ) )
				);

				$links = array(
					array(
						'label' => 'Docs',
						'href'  => 'https://github.com/skapacraft/livq-accessfix#readme',
					),
					array(
						'label' => 'Support',
						'href'  => 'https://wordpress.org/support/plugin/livq-accessfix/',
					),
					array(
						'label' => 'GitHub',
						'href'  => 'https://github.com/skapacraft/livq-accessfix',
					),
					array(
						'label' => 'Email',
						'href'  => 'mailto:support@skapacraft.com?subject=' . $email_subject,
					),
				);

				foreach ( $links as $index => $link ) {
					// Separator between items only - never before the first one.
					if ( $index > 0 ) {
						echo wp_kses_post( $sep );
					}
					printf(
						'<a href="%s" style="%s" title="%s">%s</a>',
						esc_url( $link['href'] ),
						esc_attr( $style ),
						esc_attr( $link['label'] ),
						esc_html( $link['label'] )
					);
				}
				?>
			</div>

		</div><!-- .wrap -->
		<?php
	}

	/**
	 * Renders the section introduction text for base modules.
	 *
	 * @return void
	 */
	public function render_section_intro(): void {
		echo '<p style="color:#50575e;">' .
			esc_html__( 'Each module applies one or more specific WCAG techniques. The reference criterion is shown next to each option.', 'livq-accessfix' ) .
			'</p>';
	}

	/**
	 * Renders the section introduction text for advanced modules (v1.1.0).
	 *
	 * @return void
	 */
	public function render_advanced_section_intro(): void {
		echo '<p style="color:#50575e; margin-top:4px;">' .
			esc_html__( 'Structural analysis and Gutenberg block editor integration modules. They run during content save or publishing.', 'livq-accessfix' ) .
			'</p>';
	}

	/**
	 * Renders the intro for the Configuration section.
	 *
	 * @return void
	 */
	public function render_config_section_intro(): void {
		echo '<p style="color:#50575e; margin-top:4px;">' .
			esc_html__( 'Fine-tune how the plugin behaves on your specific theme. The skip link target is detected automatically on the first visit - you can override it here if needed.', 'livq-accessfix' ) .
			'</p>';
	}

	/**
	 * Renders the skip link target text field.
	 *
	 * @return void
	 */
	public function render_skip_target_field(): void {
		$value = ! empty( $this->options['skip_link_target'] ) ? $this->options['skip_link_target'] : '';
		$name  = self::OPTION_NAME . '[skip_link_target]';
		?>
		<div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
			<input
				type="text"
				id="livqacea_skip_link_target"
				name="<?php echo esc_attr( $name ); ?>"
				value="<?php echo esc_attr( $value ); ?>"
				placeholder="#primary"
				style="width:180px; font-family:monospace; font-size:.875rem;"
			/>
			<button
				type="button"
				id="livqacea-detect-btn"
				class="button"
				style="height:30px; line-height:28px; padding:0 12px; font-size:.8rem;"
			><?php esc_html_e( 'Auto-detect', 'livq-accessfix' ); ?></button>
			<span id="livqacea-detect-status" style="font-size:.8rem; color:#646970;"></span>
		</div>
		<p style="margin:6px 0 0; color:#646970; font-size:.875rem;">
			<?php esc_html_e( 'The element ID the skip link jumps to. Leave empty to use the default (#primary). Click Auto-detect to scan your homepage automatically.', 'livq-accessfix' ); ?>
		</p>
		<?php
	}

	/**
	 * AJAX handler - detects the skip link target by fetching the site homepage server-side.
	 *
	 * Runs in admin context (logged-in, manage_options) so it's reliable regardless
	 * of frontend caching or security plugins that block nopriv AJAX calls.
	 *
	 * @return void
	 */
	public static function ajax_detect_skip_target(): void {
		if ( ! current_user_can( 'manage_options' ) ||
			! isset( $_POST['nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'livqacea_detect_skip_target' ) ) {
			wp_send_json_error( 'unauthorized' );
		}

		$response = wp_remote_get(
			get_site_url(),
			array(
				'timeout'   => 10,
				// Same filter core uses for its own loopback requests, so local
				// and staging certificates keep working without disabling
				// verification unconditionally.
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter, re-applied so a site's local-development override keeps working.
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
				'headers'   => array( 'Cache-Control' => 'no-cache' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( 'request_failed' );
		}

		$html       = wp_remote_retrieve_body( $response );
		$candidates = array( '#primary', '#main', '#content', '#main-content', '#site-content', '#wrapper' );

		foreach ( $candidates as $id ) {
			// Match id="primary" (with or without quotes, case-insensitive).
			$bare = ltrim( $id, '#' );
			if ( preg_match( '/\bid=["\']' . preg_quote( $bare, '/' ) . '["\']/', $html ) ) {
				wp_send_json_success( $id );
			}
		}

		wp_send_json_error( 'not_found' );
	}

	/**
	 * Renders the "delete on uninstall" opt-in checkbox.
	 *
	 * @return void
	 */
	public function render_delete_on_uninstall_field(): void {
		$checked = ! empty( $this->options['delete_on_uninstall'] );
		$name    = self::OPTION_NAME . '[delete_on_uninstall]';
		?>
		<label style="display:flex; align-items:flex-start; gap:8px; cursor:pointer;">
			<input
				type="checkbox"
				id="livqacea_delete_on_uninstall"
				name="<?php echo esc_attr( $name ); ?>"
				value="1"
				<?php checked( $checked ); ?>
				style="margin-top:3px;"
			/>
			<span>
				<?php esc_html_e( 'Remove all plugin settings from the database when this plugin is deleted.', 'livq-accessfix' ); ?>
				<br>
				<span style="color:#8c8f94; font-size:.8rem;">
					<?php esc_html_e( 'Recommended if you are permanently removing the plugin. Leave unchecked to keep your settings if you plan to reinstall.', 'livq-accessfix' ); ?>
				</span>
			</span>
		</label>
		<?php
	}

	/**
	 * Renders a single checkbox field row.
	 *
	 * The $args array is the field definition from get_fields().
	 * All output values are escaped at the point of echo.
	 *
	 * @param array<string, string> $args Field arguments.
	 * @return void
	 */
	public function render_checkbox_field( array $args ): void {
		$id      = $args['id'];
		$checked = ! empty( $this->options[ $id ] );
		$name    = self::OPTION_NAME . '[' . $id . ']';
		?>
		<fieldset style="margin-bottom:4px;">
			<label for="<?php echo esc_attr( 'livqacea_' . $id ); ?>" style="display:flex; align-items:flex-start; gap:10px; cursor:pointer;">
				<input
					type="checkbox"
					id="<?php echo esc_attr( 'livqacea_' . $id ); ?>"
					name="<?php echo esc_attr( $name ); ?>"
					value="1"
					<?php checked( $checked ); ?>
					style="margin-top:3px; width:16px; height:16px; accent-color:#2271b1; flex-shrink:0;"
				/>
				<span>
					<strong style="display:block; color:#1d2327;">
						<?php echo esc_html( $args['label'] ); ?>
					</strong>
					<span style="color:#646970; font-size:.875rem; display:block; margin-top:3px;">
						<?php echo esc_html( $args['description'] ); ?>
					</span>
					<span class="livqacea-badge" style="
						display:inline-block;
						margin-top:6px;
						padding:2px 8px;
						border-radius:4px;
						font-size:.75rem;
						font-weight:600;
						background:#dff0fa;
						color:#135e96;
						border:1px solid #b3d7ed;
					">
						<?php echo esc_html( $args['wcag'] ); ?>
					</span>
				</span>
			</label>
		</fieldset>
		<?php
	}

	/**
	 * Renders the section intro for the Accessibility Statement section.
	 *
	 * @return void
	 */
	public function render_statement_section_intro(): void {
		$statement_url = admin_url( 'admin.php?page=' . LIVQACEA_Statement::PAGE_SLUG );
		echo '<p style="color:#50575e; margin-top:4px;">' .
			esc_html__( 'Generate an EU-compliant Accessibility Statement for your site, required by the European Accessibility Act for public-facing services.', 'livq-accessfix' ) .
			' <a href="' . esc_url( $statement_url ) . '">' .
			esc_html__( 'Configure & Preview Statement →', 'livq-accessfix' ) .
			'</a></p>';
	}

	/**
	 * Renders the Accessibility Statement enable/disable field.
	 *
	 * @return void
	 */
	public function render_statement_field(): void {
		$checked = ! empty( $this->options['a11y_statement'] );
		$name    = self::OPTION_NAME . '[a11y_statement]';
		?>
		<fieldset>
			<label for="livqacea_a11y_statement" style="display:flex; align-items:flex-start; gap:10px; cursor:pointer;">
				<input
					type="checkbox"
					id="livqacea_a11y_statement"
					name="<?php echo esc_attr( $name ); ?>"
					value="1"
					<?php checked( $checked ); ?>
					style="margin-top:3px; width:16px; height:16px; accent-color:#2271b1; flex-shrink:0;"
				/>
				<span>
					<strong style="display:block; color:#1d2327;">
						<?php esc_html_e( 'Activate the [livqacea_accessibility_statement] shortcode', 'livq-accessfix' ); ?>
					</strong>
					<span style="color:#646970; font-size:.875rem; display:block; margin-top:3px;">
						<?php esc_html_e( 'Makes the shortcode available to embed a structured Accessibility Statement on any page. Configure the statement details from the dedicated page in the menu.', 'livq-accessfix' ); ?>
					</span>
					<span class="livqacea-badge" style="display:inline-block;margin-top:6px;padding:2px 8px;border-radius:4px;font-size:.75rem;font-weight:600;background:#dff0fa;color:#135e96;border:1px solid #b3d7ed;">
						EAA 2025 / WCAG 2.2 AA
					</span>
				</span>
			</label>
		</fieldset>
		<?php
	}

	/**
	 * Renders the Issues Log admin page.
	 *
	 * Lists all posts with a persisted accessibility issue and provides a
	 * CSV export link for compliance audit trails.
	 *
	 * @return void
	 */
	public function render_issues_log_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$rows      = LIVQACEA_Advanced::get_all_issues();
		$csv_nonce = wp_create_nonce( 'livqacea_export_csv' );
		$csv_url   = add_query_arg(
			array(
				'action' => 'livqacea_export_issues_csv',
				'nonce'  => $csv_nonce,
			),
			admin_url( 'admin-ajax.php' )
		);
		?>
		<div class="wrap">
			<h1 style="display:flex; align-items:center; gap:10px;">
				<?php esc_html_e( 'Accessibility Issues Log', 'livq-accessfix' ); ?>
				<span style="font-size:.55em; font-weight:400; color:#646970; background:#f0f0f1; border:1px solid #dcdcde; border-radius:4px; padding:2px 8px;">
					v<?php echo esc_html( LIVQACEA_VERSION ); ?>
				</span>
			</h1>

			<p style="color:#50575e; max-width:680px; margin-bottom:16px;">
				<?php esc_html_e( 'Persistent log of accessibility issues detected on save. Use this audit trail to demonstrate EAA compliance progress to stakeholders or auditors.', 'livq-accessfix' ); ?>
			</p>

			<p>
				<a href="<?php echo esc_url( $csv_url ); ?>" class="button button-secondary">
					⬇ <?php esc_html_e( 'Export CSV', 'livq-accessfix' ); ?>
				</a>
			</p>

			<?php if ( empty( $rows ) ) : ?>
				<div class="notice notice-success inline" style="margin-top:16px;">
					<p>
						<strong><?php esc_html_e( 'No issues detected.', 'livq-accessfix' ); ?></strong>
						<?php esc_html_e( 'All scanned posts pass the heading hierarchy check.', 'livq-accessfix' ); ?>
					</p>
				</div>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped" style="margin-top:16px; max-width:900px;">
					<thead>
						<tr>
							<th scope="col" style="width:60px;"><?php esc_html_e( 'ID', 'livq-accessfix' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Post', 'livq-accessfix' ); ?></th>
							<th scope="col" style="width:120px;"><?php esc_html_e( 'Type', 'livq-accessfix' ); ?></th>
							<th scope="col" style="width:90px;"><?php esc_html_e( 'WCAG', 'livq-accessfix' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Issue', 'livq-accessfix' ); ?></th>
							<th scope="col" style="width:140px;"><?php esc_html_e( 'Detected', 'livq-accessfix' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<td><?php echo absint( $row['post_id'] ); ?></td>
								<td>
									<a href="<?php echo esc_url( (string) $row['edit_url'] ); ?>">
										<?php echo esc_html( $row['post_title'] ); ?>
									</a>
								</td>
								<td><code><?php echo esc_html( $row['type'] ); ?></code></td>
								<td>
									<span style="background:#dff0fa;color:#135e96;border:1px solid #b3d7ed;border-radius:4px;padding:2px 6px;font-size:.75rem;font-weight:600;">
										<?php echo esc_html( $row['wcag'] ); ?>
									</span>
								</td>
								<td style="font-size:.875rem;"><?php echo esc_html( $row['message'] ); ?></td>
								<td style="font-size:.8rem; color:#646970;"><?php echo esc_html( $row['time'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p style="margin-top:8px; color:#646970; font-size:.8rem;">
					<?php
					printf(
						/* translators: %d: number of issues found */
						esc_html( _n( '%d issue found.', '%d issues found.', count( $rows ), 'livq-accessfix' ) ),
						count( $rows )
					);
					?>
				</p>
			<?php endif; ?>

			<?php LIVQACEA_Plugin_Links::render_review_card(); ?>
		</div>
		<?php
	}

	/**
	 * Builds the settings page CSS for wp_add_inline_style().
	 *
	 * Attached via the style enqueue API rather than a static file - the
	 * payload is tiny and a separate HTTP request for it would be wasteful.
	 * wp_add_inline_style() still routes through WordPress's dependency
	 * system, so it satisfies the "always enqueue" requirement without the
	 * extra request.
	 *
	 * @return string
	 */
	private function get_admin_css(): string {
		// Scope on the admin body class WordPress derives from the hook suffix
		// ('toplevel_page_livq-accessfix'). The previous '#livq-accessfix' ID
		// selector matched no element at all, so none of these rules applied.
		$slug = sanitize_html_class( $this->settings_hook );

		if ( '' === $slug ) {
			return '';
		}

		return "
			.{$slug} .form-table tr {
				border-bottom: 1px solid #f0f0f1;
			}
			.{$slug} .form-table tr:last-child {
				border-bottom: none;
			}
			.{$slug} .form-table th {
				padding: 16px 10px 16px 0;
				width: 200px;
				vertical-align: top;
				color: #1d2327;
				font-weight: 600;
			}
			.{$slug} .form-table td {
				padding: 14px 10px;
			}
		";
	}
}
