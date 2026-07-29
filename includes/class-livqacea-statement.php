<?php
/**
 * Accessibility Statement Generator - EAA & WCAG 2.2 AA
 *
 * Provides:
 *   1. An admin page (Settings > Accessibility Statement) to configure the
 *      statement details (organization name, contact, date of evaluation).
 *   2. The shortcode [livqacea_accessibility_statement] to embed the statement on
 *      any public page - typically "Accessibility Statement" in the footer menu.
 *   3. A one-click "Create Page" helper that auto-creates the WP page and
 *      inserts the shortcode so agencies can deploy in seconds.
 *
 * Why this matters legally
 * ------------------------
 * The European Accessibility Act (EAA, Directive 2019/882, in force June 2025)
 * and the EU Web Accessibility Directive (2016/2102) require organizations to
 * publish a machine-readable Accessibility Statement specifying:
 *   - The standard the site targets (WCAG 2.2 Level AA).
 *   - Which criteria are met, partially met, or not met.
 *   - A mechanism to report inaccessibility.
 *   - An enforcement / escalation contact.
 *
 * This generator auto-populates the statement from live plugin data so it
 * stays accurate as modules are enabled or disabled.
 *
 * @package LivQ_AccessFix
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class LIVQACEA_Statement
 */
class LIVQACEA_Statement {

	/**
	 * Option name for statement configuration in wp_options.
	 *
	 * @var string
	 */
	const OPTION_NAME    = 'livqacea_statement_config';
	const CONFIRM_OPTION = 'livqacea_statement_confirmed';

	/**
	 * Admin menu page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'livqacea-statement';

	/**
	 * Shortcode tag.
	 *
	 * @var string
	 */
	const SHORTCODE = 'livqacea_accessibility_statement';

	/**
	 * Hook suffix of this page, captured from add_submenu_page().
	 * Used to scope asset enqueueing to this screen only.
	 *
	 * @var string
	 */
	private static $page_hook = '';

	/**
	 * Registers all hooks. Called from LIVQACEA_Main::init_modules().
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_post_livqacea_create_statement_page', array( __CLASS__, 'handle_create_page' ) );
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render_shortcode' ) );
	}

	// -----------------------------------------------------------------------
	// Admin - menu & settings
	// -----------------------------------------------------------------------

	/**
	 * Registers the Statement admin page under Settings.
	 *
	 * @return void
	 */
	public static function register_menu(): void {
		self::$page_hook = (string) add_submenu_page(
			LIVQACEA_Backend::PAGE_SLUG,
			__( 'Accessibility Statement', 'livq-accessfix' ),
			__( 'A11y Statement', 'livq-accessfix' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_admin_page' )
		);
	}

	/**
	 * Enqueues the Statement page JS - scoped to this screen only.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public static function enqueue_assets( string $hook ): void {
		if ( $hook !== self::$page_hook ) {
			return;
		}

		wp_enqueue_style( 'livqacea-statement', LIVQACEA_PLUGIN_URL . 'assets/css/livqacea-statement.css', array(), LIVQACEA_VERSION );

		wp_enqueue_script( 'livqacea-statement', LIVQACEA_PLUGIN_URL . 'assets/js/livqacea-statement.js', array(), LIVQACEA_VERSION, true );
		wp_localize_script(
			'livqacea-statement',
			'livqaceaStatement',
			array(
				'notices' => array(
					'private'   => array(
						'bg'     => '#e8f5e9',
						'border' => '#388e3c',
						'text'   => __( 'Private sector: governed by Directive (EU) 2019/882 (EAA), applicable from 28 June 2025. The statement follows the W3C Accessibility Statement model as best practice.', 'livq-accessfix' ),
					),
					'public'    => array(
						'bg'     => '#fff8e1',
						'border' => '#dba617',
						'text'   => __( 'Public Administration: governed by Directive (EU) 2016/2102 + Implementing Decision 2018/1523 (since 2018/2019). You must also submit this statement annually via the official national channel (e.g. form.agid.gov.it for Italy).', 'livq-accessfix' ),
					),
					'nonprofit' => array(
						'bg'     => '#e3f2fd',
						'border' => '#1976d2',
						'text'   => __( 'Non-profit / Third sector: if your organization offers services to the public (even for free), the EAA (Directive 2019/882) may apply. The statement will reference EAA where applicable and W3C best practice as a reference model.', 'livq-accessfix' ),
					),
					'micro'     => array(
						'bg'     => '#fce4ec',
						'border' => '#c62828',
						'text'   => __( 'Microenterprise / Freelancer: organizations with fewer than 10 employees AND annual turnover ≤ €2M are exempt from EAA obligations (Art. 4(5) Directive 2019/882). A statement is not mandatory but is recommended as good practice.', 'livq-accessfix' ),
					),
					'personal'  => array(
						'bg'     => '#f3e5f5',
						'border' => '#7b1fa2',
						'text'   => __( 'Personal website / Private individual: personal websites with no commercial activity are outside the scope of both the EAA and Directive 2016/2102. An accessibility statement is entirely voluntary.', 'livq-accessfix' ),
					),
				),
			)
		);
	}

	/**
	 * Registers the statement config option with the Settings API.
	 *
	 * @return void
	 */
	public static function register_settings(): void {
		register_setting(
			'livqacea_statement_group',
			self::OPTION_NAME,
			array(
				'sanitize_callback' => array( __CLASS__, 'sanitize_config' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * Sanitises the statement configuration fields.
	 *
	 * @param mixed $raw Raw POST input.
	 * @return array<string, string> Sanitised config.
	 */
	public static function sanitize_config( $raw ): array {
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		// Save confirmation record when the admin explicitly checks the box.
		if ( ! empty( $raw['_confirm'] ) && current_user_can( 'manage_options' ) ) {
			$user = wp_get_current_user();
			update_option(
				self::CONFIRM_OPTION,
				array(
					'confirmed_at'   => current_time( 'mysql' ),
					'confirmed_by'   => get_current_user_id(),
					'user_email'     => $user->user_email,
					'user_name'      => $user->display_name,
					'plugin_version' => LIVQACEA_VERSION,
					'site_url'       => get_site_url(),
				)
			);
		}

		$allowed_measures = array_keys( self::org_measures_list() );
		$raw_measures     = is_array( $raw['org_measures'] ?? null ) ? $raw['org_measures'] : array();
		$org_measures     = array_values( array_intersect( $raw_measures, $allowed_measures ) );

		return array(
			'sector'                  => in_array( $raw['sector'] ?? '', array( 'private', 'public', 'nonprofit', 'micro', 'personal' ), true )
										? $raw['sector']
										: 'private',
			'org_name'                => sanitize_text_field( wp_unslash( $raw['org_name'] ?? '' ) ),
			'website_url'             => esc_url_raw( wp_unslash( $raw['website_url'] ?? '' ) ),
			'contact_email'           => sanitize_email( wp_unslash( $raw['contact_email'] ?? '' ) ),
			'contact_url'             => esc_url_raw( wp_unslash( $raw['contact_url'] ?? '' ) ),
			'contact_phone'           => sanitize_text_field( wp_unslash( $raw['contact_phone'] ?? '' ) ),
			'responsible_name'        => sanitize_text_field( wp_unslash( $raw['responsible_name'] ?? '' ) ),
			'eval_date'               => sanitize_text_field( wp_unslash( $raw['eval_date'] ?? '' ) ),
			'statement_date'          => sanitize_text_field( wp_unslash( $raw['statement_date'] ?? '' ) ),
			'next_review_date'        => sanitize_text_field( wp_unslash( $raw['next_review_date'] ?? '' ) ),
			'eval_method'             => in_array( $raw['eval_method'] ?? '', array( 'self', 'external', 'automated' ), true )
										? $raw['eval_method']
										: 'automated',
			'conformance'             => in_array( $raw['conformance'] ?? '', array( 'full', 'partial', 'non' ), true )
										? $raw['conformance']
										: 'partial',
			'org_measures'            => $org_measures,
			'notes'                   => wp_kses_post( wp_unslash( $raw['notes'] ?? '' ) ),
			'disproportionate_burden' => wp_kses_post( wp_unslash( $raw['disproportionate_burden'] ?? '' ) ),
			'enforcement_url'         => esc_url_raw( wp_unslash( $raw['enforcement_url'] ?? '' ) ),
		);
	}

	/**
	 * Renders the admin configuration page.
	 *
	 * @return void
	 */
	public static function render_admin_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$cfg         = self::get_config();
		$page_exists = self::get_statement_page_id();
		$create_url  = wp_nonce_url(
			admin_url( 'admin-post.php?action=livqacea_create_statement_page' ),
			'livqacea_create_statement_page'
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Accessibility Statement - EAA / WCAG 2.2 AA', 'livq-accessfix' ); ?></h1>

			<div class="livqacea-st-intro">
				<p class="livqacea-st-intro-title">
					<?php esc_html_e( 'EAA / WCAG 2.2 AA - Accessibility Statement Generator', 'livq-accessfix' ); ?>
				</p>
				<p class="livqacea-st-intro-text">
					<?php esc_html_e( 'The European Accessibility Act (EAA, Directive 2019/882) requires every website to publish an Accessibility Statement listing its conformance level, active remediations, and a contact channel for users to report barriers. Fill in the fields below, then embed the statement on any page with:', 'livq-accessfix' ); ?>
					<code class="livqacea-st-shortcode">[<?php echo esc_html( self::SHORTCODE ); ?>]</code>
				</p>
			</div>

			<?php if ( isset( $_GET['livqacea-page-created'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible">
					<p>
						<strong><?php esc_html_e( 'Page created!', 'livq-accessfix' ); ?></strong>
						<?php
						$pid = absint( $_GET['livqacea-page-created'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
						printf(
							'<a href="%s">%s</a> · <a href="%s" target="_blank">%s</a>',
							esc_url( get_edit_post_link( $pid, 'display' ) ?? '' ),
							esc_html__( 'Edit page', 'livq-accessfix' ),
							esc_url( get_permalink( $pid ) ? get_permalink( $pid ) : '' ),
							esc_html__( 'View page ↗', 'livq-accessfix' )
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<div class="livqacea-st-row">

				<!-- Config form -->
				<div class="livqacea-st-col-config">
					<div class="livqacea-st-card">
						<form method="post" action="options.php">
							<?php settings_fields( 'livqacea_statement_group' ); ?>

							<table class="form-table livqacea-st-table">

								<!-- Type -->
								<tr><th colspan="2" class="livqacea-st-section-th-first">
									<span class="livqacea-st-section-label">
										<?php esc_html_e( 'Type', 'livq-accessfix' ); ?>
									</span>
								</th></tr>
								<tr>
									<th scope="row" class="livqacea-st-th">
										<label for="livqacea_sector"><?php esc_html_e( 'Organization Type', 'livq-accessfix' ); ?></label>
									</th>
									<td>
										<select id="livqacea_sector" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[sector]">
											<option value="private" <?php selected( $cfg['sector'], 'private' ); ?>><?php esc_html_e( 'Private company / e-commerce / digital service', 'livq-accessfix' ); ?></option>
											<option value="public" <?php selected( $cfg['sector'], 'public' ); ?>><?php esc_html_e( 'Public Administration (PA)', 'livq-accessfix' ); ?></option>
											<option value="nonprofit" <?php selected( $cfg['sector'], 'nonprofit' ); ?>><?php esc_html_e( 'Non-profit / Association / Third sector', 'livq-accessfix' ); ?></option>
											<option value="micro" <?php selected( $cfg['sector'], 'micro' ); ?>><?php esc_html_e( 'Microenterprise / Freelancer (fewer than 10 employees, turnover ≤ €2M)', 'livq-accessfix' ); ?></option>
											<option value="personal" <?php selected( $cfg['sector'], 'personal' ); ?>><?php esc_html_e( 'Personal website / Private individual (no VAT)', 'livq-accessfix' ); ?></option>
										</select>
										<div id="livqacea_sector_notice" class="livqacea-st-notice"></div>
									</td>
								</tr>

								<!-- Organization -->
								<tr><th colspan="2" class="livqacea-st-section-th">
									<span class="livqacea-st-section-label">
										<?php esc_html_e( 'Organization', 'livq-accessfix' ); ?>
									</span>
								</th></tr>
								<tr>
									<th scope="row" class="livqacea-st-th">
										<label for="livqacea_org_name"><?php esc_html_e( 'Organization Name', 'livq-accessfix' ); ?></label>
									</th>
									<td>
										<input type="text" id="livqacea_org_name" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[org_name]"
											value="<?php echo esc_attr( $cfg['org_name'] ); ?>" class="regular-text"
											placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" />
										<p class="description"><?php esc_html_e( 'Defaults to the site title if left empty.', 'livq-accessfix' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row" class="livqacea-st-th">
										<label for="livqacea_website_url"><?php esc_html_e( 'Website URL', 'livq-accessfix' ); ?></label>
									</th>
									<td>
										<input type="url" id="livqacea_website_url" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[website_url]"
											value="<?php echo esc_attr( $cfg['website_url'] ); ?>" class="regular-text"
											placeholder="<?php echo esc_attr( get_site_url() ); ?>" />
										<p class="description"><?php esc_html_e( 'The URL this statement covers. Required by EAA Art. 13 to identify the scope of the declaration.', 'livq-accessfix' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row" class="livqacea-st-th">
										<label for="livqacea_responsible_name"><?php esc_html_e( 'Accessibility Responsible (optional)', 'livq-accessfix' ); ?></label>
									</th>
									<td>
										<input type="text" id="livqacea_responsible_name" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[responsible_name]"
											value="<?php echo esc_attr( $cfg['responsible_name'] ); ?>" class="regular-text"
											placeholder="<?php esc_attr_e( 'e.g. Web Accessibility Manager', 'livq-accessfix' ); ?>" />
										<p class="description"><?php esc_html_e( 'Name or role of the person responsible for accessibility. Some national EAA implementations require this.', 'livq-accessfix' ); ?></p>
									</td>
								</tr>

								<!-- Feedback channels -->
								<tr><th colspan="2" class="livqacea-st-section-th">
									<span class="livqacea-st-section-label">
										<?php esc_html_e( 'Feedback Channels', 'livq-accessfix' ); ?>
									</span>
								</th></tr>
								<tr>
									<th scope="row" class="livqacea-st-th">
										<label for="livqacea_contact_email"><?php esc_html_e( 'Accessibility Contact Email', 'livq-accessfix' ); ?></label>
									</th>
									<td>
										<input type="email" id="livqacea_contact_email" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[contact_email]"
											value="<?php echo esc_attr( $cfg['contact_email'] ); ?>" class="regular-text"
											placeholder="accessibility@example.com" />
										<p class="description"><?php esc_html_e( 'Required by EAA: users must be able to report accessibility barriers.', 'livq-accessfix' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row" class="livqacea-st-th">
										<label for="livqacea_contact_url"><?php esc_html_e( 'Contact Form URL (optional)', 'livq-accessfix' ); ?></label>
									</th>
									<td>
										<input type="url" id="livqacea_contact_url" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[contact_url]"
											value="<?php echo esc_attr( $cfg['contact_url'] ); ?>" class="regular-text"
											placeholder="https://example.com/contact" />
									</td>
								</tr>
								<tr>
									<th scope="row" class="livqacea-st-th">
										<label for="livqacea_contact_phone"><?php esc_html_e( 'Phone / Oral Channel (optional)', 'livq-accessfix' ); ?></label>
									</th>
									<td>
										<input type="text" id="livqacea_contact_phone" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[contact_phone]"
											value="<?php echo esc_attr( $cfg['contact_phone'] ); ?>" class="regular-text"
											placeholder="+39 02 1234567" />
										<p class="description"><?php esc_html_e( 'EAA Art. 13 requires information available in written and oral format. Add a phone number or alternative oral channel.', 'livq-accessfix' ); ?></p>
									</td>
								</tr>

								<!-- Evaluation -->
								<tr><th colspan="2" class="livqacea-st-section-th">
									<span class="livqacea-st-section-label">
										<?php esc_html_e( 'Evaluation', 'livq-accessfix' ); ?>
									</span>
								</th></tr>
								<tr>
									<th scope="row" class="livqacea-st-th">
										<label for="livqacea_eval_method"><?php esc_html_e( 'Evaluation Method', 'livq-accessfix' ); ?></label>
									</th>
									<td>
										<select id="livqacea_eval_method" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[eval_method]">
											<option value="automated" <?php selected( $cfg['eval_method'], 'automated' ); ?>><?php esc_html_e( 'Automated tools (LivQ AccessFix)', 'livq-accessfix' ); ?></option>
											<option value="self" <?php selected( $cfg['eval_method'], 'self' ); ?>><?php esc_html_e( 'Self-assessment', 'livq-accessfix' ); ?></option>
											<option value="external" <?php selected( $cfg['eval_method'], 'external' ); ?>><?php esc_html_e( 'External audit by a third party', 'livq-accessfix' ); ?></option>
										</select>
										<p class="description"><?php esc_html_e( 'Required by EN 301 549: how this accessibility evaluation was conducted.', 'livq-accessfix' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row" class="livqacea-st-th">
										<label for="livqacea_eval_date"><?php esc_html_e( 'Last Evaluation Date', 'livq-accessfix' ); ?></label>
									</th>
									<td>
										<input type="date" id="livqacea_eval_date" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[eval_date]"
											value="<?php echo esc_attr( $cfg['eval_date'] ? $cfg['eval_date'] : gmdate( 'Y-m-d' ) ); ?>" />
										<p class="description"><?php esc_html_e( 'Date of the last formal accessibility audit.', 'livq-accessfix' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row" class="livqacea-st-th">
										<label for="livqacea_statement_date"><?php esc_html_e( 'Statement Preparation Date', 'livq-accessfix' ); ?></label>
									</th>
									<td>
										<input type="date" id="livqacea_statement_date" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[statement_date]"
											value="<?php echo esc_attr( $cfg['statement_date'] ? $cfg['statement_date'] : gmdate( 'Y-m-d' ) ); ?>" />
										<p class="description"><?php esc_html_e( 'Date this statement was prepared or last reviewed. Distinct from the evaluation date - required by Directive 2016/2102 and applied to EAA as best practice.', 'livq-accessfix' ); ?></p>
									</td>
								</tr>

								<tr>
									<th scope="row" class="livqacea-st-th">
										<label for="livqacea_next_review_date"><?php esc_html_e( 'Next Review Date', 'livq-accessfix' ); ?></label>
									</th>
									<td>
										<input type="date" id="livqacea_next_review_date" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[next_review_date]"
											value="<?php echo esc_attr( $cfg['next_review_date'] ? $cfg['next_review_date'] : gmdate( 'Y-m-d', strtotime( '+1 year' ) ) ); ?>" />
										<p class="description"><?php esc_html_e( 'Date when this statement will be reviewed. Annual review is recommended by both the W3C and Directive 2016/2102 (applied as best practice to EAA).', 'livq-accessfix' ); ?></p>
									</td>
								</tr>

								<!-- Conformance -->
								<tr><th colspan="2" class="livqacea-st-section-th">
									<span class="livqacea-st-section-label">
										<?php esc_html_e( 'Conformance', 'livq-accessfix' ); ?>
									</span>
								</th></tr>
								<tr>
									<th scope="row" class="livqacea-st-th">
										<label for="livqacea_conformance"><?php esc_html_e( 'Conformance Status', 'livq-accessfix' ); ?></label>
									</th>
									<td>
										<select id="livqacea_conformance" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[conformance]">
											<option value="full" <?php selected( $cfg['conformance'], 'full' ); ?>><?php esc_html_e( 'Fully conformant', 'livq-accessfix' ); ?></option>
											<option value="partial" <?php selected( $cfg['conformance'], 'partial' ); ?>><?php esc_html_e( 'Partially conformant', 'livq-accessfix' ); ?></option>
											<option value="non" <?php selected( $cfg['conformance'], 'non' ); ?>><?php esc_html_e( 'Non-conformant', 'livq-accessfix' ); ?></option>
										</select>
										<p class="description"><?php esc_html_e( '"Partially conformant" is correct for most sites - it means known issues exist and are being addressed.', 'livq-accessfix' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row" class="livqacea-st-th">
										<label for="livqacea_notes"><?php esc_html_e( 'Known Limitations / Notes', 'livq-accessfix' ); ?></label>
									</th>
									<td>
										<textarea id="livqacea_notes" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[notes]"
											rows="4" class="large-text"><?php echo esc_textarea( $cfg['notes'] ); ?></textarea>
										<p class="description"><?php esc_html_e( 'Describe any known barriers not yet resolved (optional).', 'livq-accessfix' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row" class="livqacea-st-th">
										<label for="livqacea_disproportionate_burden"><?php esc_html_e( 'Disproportionate Burden (optional)', 'livq-accessfix' ); ?></label>
									</th>
									<td>
										<textarea id="livqacea_disproportionate_burden" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[disproportionate_burden]"
											rows="3" class="large-text"><?php echo esc_textarea( $cfg['disproportionate_burden'] ); ?></textarea>
										<p class="description"><?php esc_html_e( 'EAA allows exemptions for content where full compliance would impose a disproportionate burden. Describe which content qualifies and the justification. Leave empty if not applicable.', 'livq-accessfix' ); ?></p>
									</td>
								</tr>

								<!-- Organizational Measures -->
								<tr><th colspan="2" class="livqacea-st-section-th">
									<span class="livqacea-st-section-label">
										<?php esc_html_e( 'Organizational Measures', 'livq-accessfix' ); ?>
									</span>
								</th></tr>
								<tr>
									<th scope="row" class="livqacea-st-th">
										<?php esc_html_e( 'Accessibility Measures Adopted', 'livq-accessfix' ); ?>
									</th>
									<td>
										<fieldset>
											<legend class="screen-reader-text"><?php esc_html_e( 'Accessibility Measures Adopted', 'livq-accessfix' ); ?></legend>
											<?php
											$selected_measures = is_array( $cfg['org_measures'] ?? null ) ? $cfg['org_measures'] : array();
											foreach ( self::org_measures_list() as $key => $label ) :
												?>
												<label class="livqacea-st-measure-label">
													<input type="checkbox"
														name="<?php echo esc_attr( self::OPTION_NAME ); ?>[org_measures][]"
														value="<?php echo esc_attr( $key ); ?>"
														<?php checked( in_array( $key, $selected_measures, true ) ); ?>>
													<?php echo esc_html( $label ); ?>
												</label>
											<?php endforeach; ?>
										</fieldset>
										<p class="description"><?php esc_html_e( 'Tick the measures your organization actively applies. These are listed in the public statement (W3C recommendation).', 'livq-accessfix' ); ?></p>
									</td>
								</tr>

								<!-- Enforcement -->
								<tr><th colspan="2" class="livqacea-st-section-th">
									<span class="livqacea-st-section-label">
										<?php esc_html_e( 'Enforcement', 'livq-accessfix' ); ?>
									</span>
								</th></tr>
								<tr>
									<th scope="row" class="livqacea-st-th">
										<label for="livqacea_enforcement_url"><?php esc_html_e( 'Enforcement Body URL (optional)', 'livq-accessfix' ); ?></label>
									</th>
									<td>
										<input type="url" id="livqacea_enforcement_url" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[enforcement_url]"
											value="<?php echo esc_attr( $cfg['enforcement_url'] ); ?>" class="regular-text"
											placeholder="https://www.agid.gov.it/it/form/difensore-civico-digitale" />
										<p class="description"><?php esc_html_e( 'Direct URL to the national enforcement body complaint form. Makes it easier for users to escalate. For Italy: Difensore Civico per il Digitale (AgID).', 'livq-accessfix' ); ?></p>
									</td>
								</tr>

							</table>

							<?php
							$confirmed = self::get_confirmation();
							?>
							<div class="livqacea-st-confirm-box">
								<?php if ( $confirmed ) : ?>
									<p class="livqacea-st-confirm-last">
										✓
										<?php
										printf(
											/* translators: 1: user display name, 2: confirmation date */
											esc_html__( 'Last confirmed by %1$s on %2$s.', 'livq-accessfix' ),
											'<strong>' . esc_html( $confirmed['user_name'] ) . '</strong>',
											esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $confirmed['confirmed_at'] ) ) )
										);
										?>
									</p>
								<?php endif; ?>
								<label class="livqacea-st-confirm-label">
									<input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[_confirm]" value="1" class="livqacea-st-confirm-checkbox">
									<span class="livqacea-st-confirm-text">
										<?php esc_html_e( 'I confirm that the information in this statement is accurate and that I take responsibility for its content. This statement was prepared with the assistance of LivQ AccessFix\'s automated tools.', 'livq-accessfix' ); ?>
									</span>
								</label>
							</div>

							<?php submit_button( __( 'Save Statement Config', 'livq-accessfix' ) ); ?>
						</form>
					</div>

					<!-- Create page helper -->
					<div class="livqacea-st-create-box">
						<h3 class="livqacea-st-create-title">
							<?php esc_html_e( '⚡ One-Click Page Creation', 'livq-accessfix' ); ?>
						</h3>
						<?php if ( $page_exists ) : ?>
							<p class="livqacea-st-create-success">
								✓ <?php esc_html_e( 'Accessibility Statement page already exists.', 'livq-accessfix' ); ?>
								<a href="<?php echo esc_url( get_permalink( $page_exists ) ? get_permalink( $page_exists ) : '' ); ?>" target="_blank">
									<?php esc_html_e( 'View page ↗', 'livq-accessfix' ); ?>
								</a>
							</p>
						<?php else : ?>
							<p class="livqacea-st-create-desc">
								<?php esc_html_e( 'Create a draft WP page titled "Accessibility Statement" with the shortcode pre-inserted.', 'livq-accessfix' ); ?>
							</p>
							<a href="<?php echo esc_url( $create_url ); ?>" class="button button-secondary">
								<?php esc_html_e( 'Create Statement Page', 'livq-accessfix' ); ?>
							</a>
						<?php endif; ?>
					</div>
				</div>

				<!-- Live preview -->
				<div class="livqacea-st-col-preview">
					<div class="livqacea-st-card-sm">
						<h3 class="livqacea-st-preview-title">
							<?php esc_html_e( 'Statement Preview', 'livq-accessfix' ); ?>
						</h3>
						<div class="livqacea-st-preview-body">
							<?php
							// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							echo self::build_statement_html( $cfg, false );
							?>
						</div>
					</div>
				</div>

			</div><!-- flex row -->
		</div><!-- .wrap -->
		<?php
	}

	// -----------------------------------------------------------------------
	// Admin-post action - create WP page
	// -----------------------------------------------------------------------

	/**
	 * Handles the one-click "Create Statement Page" form submission.
	 *
	 * @return void
	 */
	public static function handle_create_page(): void {
		if ( ! current_user_can( 'manage_options' ) ||
			! check_admin_referer( 'livqacea_create_statement_page' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'livq-accessfix' ) );
		}

		$existing = self::get_statement_page_id();
		if ( $existing ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'                  => self::PAGE_SLUG,
						'livqacea-page-created' => $existing,
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$page_id = wp_insert_post(
			array(
				'post_title'   => __( 'Accessibility Statement', 'livq-accessfix' ),
				'post_name'    => 'accessibility-statement',
				'post_content' => '<!-- wp:shortcode -->[' . self::SHORTCODE . ']<!-- /wp:shortcode -->',
				'post_status'  => 'draft',
				'post_type'    => 'page',
				'post_author'  => get_current_user_id(),
			)
		);

		if ( is_wp_error( $page_id ) || ! $page_id ) {
			wp_die( esc_html__( 'Could not create page. Please try manually.', 'livq-accessfix' ) );
		}

		update_post_meta( $page_id, '_livqacea_statement_page', '1' );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                  => self::PAGE_SLUG,
					'livqacea-page-created' => $page_id,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	// -----------------------------------------------------------------------
	// Shortcode
	// -----------------------------------------------------------------------

	/**
	 * Renders the [livqacea_accessibility_statement] shortcode.
	 *
	 * @param array<string, string>|string $atts Shortcode attributes (unused).
	 * @return string HTML output.
	 */
	public static function render_shortcode( $atts ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$options = get_option( 'livqacea_options', array() );
		if ( empty( $options['a11y_statement'] ) ) {
			return '';
		}

		$cfg = self::get_config();
		return self::build_statement_html( $cfg, true );
	}

	// -----------------------------------------------------------------------
	// Statement builder
	// -----------------------------------------------------------------------

	/**
	 * Builds the full Accessibility Statement HTML.
	 *
	 * Auto-populates active modules from livqacea_options so the statement always
	 * reflects the current plugin configuration without manual updates.
	 *
	 * @param array<string, string> $cfg       Statement config from wp_options.
	 * @param bool                  $is_public True when rendering on the frontend (adds schema.org markup).
	 * @return string Escaped HTML.
	 */
	private static function build_statement_html( array $cfg, bool $is_public ): string {
		$sector          = $cfg['sector'] ?? 'private';
		$is_public_admin = 'public' === $sector;
		$is_nonprofit    = 'nonprofit' === $sector;
		$is_micro        = 'micro' === $sector;
		$is_personal     = 'personal' === $sector;
		$org_name        = ! empty( $cfg['org_name'] ) ? $cfg['org_name'] : get_bloginfo( 'name' );
		$website_url     = ! empty( $cfg['website_url'] ) ? $cfg['website_url'] : get_site_url();
		$eval_date       = ! empty( $cfg['eval_date'] ) ? $cfg['eval_date'] : gmdate( 'Y-m-d' );
		$statement_date  = ! empty( $cfg['statement_date'] ) ? $cfg['statement_date'] : $eval_date;
		$next_review     = ! empty( $cfg['next_review_date'] ) ? $cfg['next_review_date'] : '';
		$org_measures    = is_array( $cfg['org_measures'] ?? null ) ? $cfg['org_measures'] : array();

		$eval_method_map   = array(
			'automated' => __( 'Automated tools (LivQ AccessFix)', 'livq-accessfix' ),
			'self'      => __( 'Self-assessment', 'livq-accessfix' ),
			'external'  => __( 'External audit by a third party', 'livq-accessfix' ),
		);
		$eval_method_label = $eval_method_map[ $cfg['eval_method'] ?? 'automated' ] ?? $eval_method_map['automated'];

		$conformance_map   = array(
			'full'    => __( 'Fully conformant', 'livq-accessfix' ),
			'partial' => __( 'Partially conformant', 'livq-accessfix' ),
			'non'     => __( 'Non-conformant', 'livq-accessfix' ),
		);
		$conformance_label = $conformance_map[ $cfg['conformance'] ?? 'partial' ] ?? $conformance_map['partial'];

		// Build criteria list from active modules.
		$options  = get_option( 'livqacea_options', array() );
		$criteria = self::get_active_criteria( $options );

		$locale = get_locale();

		ob_start();
		?>
<div class="livqacea-a11y-statement" 
		<?php
		if ( $is_public ) :
			?>
	itemscope itemtype="https://schema.org/WebPage"<?php endif; ?>>

	<h2>
		<?php
		/* translators: %s: organization name */
		echo esc_html( sprintf( __( 'Accessibility Statement for %s', 'livq-accessfix' ), $org_name ) );
		?>
	</h2>

	<p>
		<?php
		echo esc_html(
			sprintf(
			/* translators: 1: organization name, 2: website URL */
				__( '%1$s is committed to ensuring digital accessibility for people with disabilities. We continually improve the user experience for everyone and apply the relevant accessibility standards on %2$s.', 'livq-accessfix' ),
				$org_name,
				$website_url
			)
		);
		?>
	</p>

		<?php if ( ! empty( $org_measures ) ) : ?>
	<h3><?php esc_html_e( 'Organizational Measures to Support Accessibility', 'livq-accessfix' ); ?></h3>
	<p><?php esc_html_e( 'We take the following measures to ensure accessibility of this website:', 'livq-accessfix' ); ?></p>
	<ul>
			<?php
			$all_measures = self::org_measures_list();
			foreach ( $org_measures as $key ) {
				if ( isset( $all_measures[ $key ] ) ) {
					echo '<li>' . esc_html( $all_measures[ $key ] ) . '</li>';
				}
			}
			?>
	</ul>
	<?php endif; ?>

	<h3><?php esc_html_e( 'Legal Framework and Applicable Standards', 'livq-accessfix' ); ?></h3>
		<?php if ( $is_public_admin ) : ?>
	<p><?php esc_html_e( 'This statement is prepared in compliance with Directive (EU) 2016/2102 on the accessibility of the websites and mobile applications of public sector bodies, as implemented by national legislation, and with the model established by EU Implementing Decision 2018/1523. The technical requirements are based on the harmonised standard EN 301 549 v3.2.1, which incorporates Web Content Accessibility Guidelines (WCAG) 2.2, Level AA.', 'livq-accessfix' ); ?></p>
	<ul>
		<li>
			<strong><?php esc_html_e( 'Legal basis:', 'livq-accessfix' ); ?></strong>
			<a href="https://eur-lex.europa.eu/legal-content/EN/TXT/?uri=CELEX%3A32016L2102" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'Directive (EU) 2016/2102 - Web Accessibility Directive (public sector)', 'livq-accessfix' ); ?>
			</a>
		</li>
		<li>
			<strong><?php esc_html_e( 'Statement model:', 'livq-accessfix' ); ?></strong>
			<a href="https://eur-lex.europa.eu/legal-content/EN/TXT/?uri=CELEX%3A32018D1523" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'EU Implementing Decision 2018/1523', 'livq-accessfix' ); ?>
			</a>
		</li>
		<li>
			<strong><?php esc_html_e( 'Technical standard:', 'livq-accessfix' ); ?></strong>
			<a href="https://www.w3.org/TR/WCAG22/" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'Web Content Accessibility Guidelines (WCAG) 2.2, Level AA', 'livq-accessfix' ); ?>
			</a>
			<?php esc_html_e( '(via EN 301 549 v3.2.1)', 'livq-accessfix' ); ?>
		</li>
	</ul>
	<?php elseif ( $is_nonprofit ) : ?>
	<p><?php esc_html_e( 'This statement is prepared on a voluntary basis in compliance with the European Accessibility Act (Directive (EU) 2019/882) where applicable to this organization\'s activities, and following the W3C Accessibility Statement model as best practice. The technical requirements are based on EN 301 549 v3.2.1, which incorporates WCAG 2.2, Level AA.', 'livq-accessfix' ); ?></p>
	<ul>
		<li>
			<strong><?php esc_html_e( 'Reference standard:', 'livq-accessfix' ); ?></strong>
			<a href="https://eur-lex.europa.eu/legal-content/EN/TXT/?uri=CELEX%3A32019L0882" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'Directive (EU) 2019/882 - European Accessibility Act (where applicable)', 'livq-accessfix' ); ?>
			</a>
		</li>
		<li>
			<strong><?php esc_html_e( 'Technical standard:', 'livq-accessfix' ); ?></strong>
			<a href="https://www.w3.org/TR/WCAG22/" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'Web Content Accessibility Guidelines (WCAG) 2.2, Level AA', 'livq-accessfix' ); ?>
			</a>
			<?php esc_html_e( '(via EN 301 549 v3.2.1)', 'livq-accessfix' ); ?>
		</li>
	</ul>
	<?php elseif ( $is_micro ) : ?>
	<p><?php esc_html_e( 'This organization qualifies as a microenterprise (fewer than 10 employees and annual turnover ≤ €2M) and is therefore exempt from mandatory obligations under the European Accessibility Act (Directive (EU) 2019/882, Art. 4(5)). This statement is provided voluntarily as a best practice commitment, following the W3C Accessibility Statement model.', 'livq-accessfix' ); ?></p>
	<ul>
		<li>
			<strong><?php esc_html_e( 'Exemption basis:', 'livq-accessfix' ); ?></strong>
			<a href="https://eur-lex.europa.eu/legal-content/EN/TXT/?uri=CELEX%3A32019L0882" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'Directive (EU) 2019/882, Article 4(5) - Microenterprise exemption', 'livq-accessfix' ); ?>
			</a>
		</li>
		<li>
			<strong><?php esc_html_e( 'Technical reference:', 'livq-accessfix' ); ?></strong>
			<a href="https://www.w3.org/TR/WCAG22/" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'Web Content Accessibility Guidelines (WCAG) 2.2, Level AA', 'livq-accessfix' ); ?>
			</a>
		</li>
	</ul>
	<?php elseif ( $is_personal ) : ?>
	<p><?php esc_html_e( 'This is a personal website with no commercial activity. Personal websites are outside the scope of the European Accessibility Act (Directive (EU) 2019/882) and Directive (EU) 2016/2102. This statement is provided voluntarily as a commitment to inclusive design, following W3C Accessibility Guidelines.', 'livq-accessfix' ); ?></p>
	<ul>
		<li>
			<strong><?php esc_html_e( 'Technical reference:', 'livq-accessfix' ); ?></strong>
			<a href="https://www.w3.org/TR/WCAG22/" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'Web Content Accessibility Guidelines (WCAG) 2.2, Level AA', 'livq-accessfix' ); ?>
			</a>
		</li>
	</ul>
	<?php else : ?>
	<p><?php esc_html_e( 'This statement is prepared in compliance with the European Accessibility Act (Directive (EU) 2019/882, applicable from 28 June 2025) and applicable national implementing legislation. The technical requirements are based on the harmonised standard EN 301 549 v3.2.1, which incorporates Web Content Accessibility Guidelines (WCAG) 2.2, Level AA. The structure of this statement follows the W3C Accessibility Statement best practice as reference model.', 'livq-accessfix' ); ?></p>
	<ul>
		<li>
			<strong><?php esc_html_e( 'Legal basis:', 'livq-accessfix' ); ?></strong>
			<a href="https://eur-lex.europa.eu/legal-content/EN/TXT/?uri=CELEX%3A32019L0882" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'Directive (EU) 2019/882 - European Accessibility Act', 'livq-accessfix' ); ?>
			</a>
		</li>
		<li>
			<strong><?php esc_html_e( 'Technical standard:', 'livq-accessfix' ); ?></strong>
			<a href="https://www.w3.org/TR/WCAG22/" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'Web Content Accessibility Guidelines (WCAG) 2.2, Level AA', 'livq-accessfix' ); ?>
			</a>
			<?php esc_html_e( '(via EN 301 549 v3.2.1)', 'livq-accessfix' ); ?>
		</li>
	</ul>
	<?php endif; ?>

	<h3><?php esc_html_e( 'Conformance Status', 'livq-accessfix' ); ?></h3>
	<p>
		<?php
		if ( $is_public_admin ) {
			printf(
				wp_kses(
					/* translators: %s: conformance status label (e.g. "fully conformant") */
					__( 'This website is <strong>%s</strong> with WCAG 2.2 Level AA and the requirements of Directive (EU) 2016/2102. "Partially conformant" means that some parts of the content do not yet fully conform to the accessibility standard.', 'livq-accessfix' ),
					array( 'strong' => array() )
				),
				esc_html( $conformance_label )
			);
		} elseif ( $is_micro || $is_personal ) {
			printf(
				wp_kses(
					/* translators: %s: conformance status label (e.g. "fully conformant") */
					__( 'This website is <strong>%s</strong> with WCAG 2.2 Level AA. This statement is provided voluntarily. "Partially conformant" means that some parts of the content do not yet fully conform to the accessibility guidelines.', 'livq-accessfix' ),
					array( 'strong' => array() )
				),
				esc_html( $conformance_label )
			);
		} elseif ( $is_nonprofit ) {
			printf(
				wp_kses(
					/* translators: %s: conformance status label (e.g. "fully conformant") */
					__( 'This website is <strong>%s</strong> with WCAG 2.2 Level AA and the requirements of Directive (EU) 2019/882 where applicable. "Partially conformant" means that some parts of the content do not yet fully conform to the accessibility standard.', 'livq-accessfix' ),
					array( 'strong' => array() )
				),
				esc_html( $conformance_label )
			);
		} else {
			printf(
				wp_kses(
					/* translators: %s: conformance status label (e.g. "fully conformant") */
					__( 'This website is <strong>%s</strong> with WCAG 2.2 Level AA and the requirements of Directive (EU) 2019/882. "Partially conformant" means that some parts of the content do not yet fully conform to the accessibility standard.', 'livq-accessfix' ),
					array( 'strong' => array() )
				),
				esc_html( $conformance_label )
			);
		}
		?>
	</p>
	<ul>
		<li>
			<strong><?php esc_html_e( 'Evaluation method:', 'livq-accessfix' ); ?></strong>
			<?php echo esc_html( $eval_method_label ); ?>
		</li>
		<li>
			<strong><?php esc_html_e( 'Last evaluation date:', 'livq-accessfix' ); ?></strong>
			<?php echo esc_html( $eval_date ); ?>
		</li>
	</ul>

	<h3><?php esc_html_e( 'Technical Specifications', 'livq-accessfix' ); ?></h3>
	<p><?php esc_html_e( 'This website relies on the following technologies for conformance with the accessibility standard:', 'livq-accessfix' ); ?></p>
	<ul>
		<li>HTML</li>
		<li>CSS</li>
		<li>JavaScript</li>
		<li>WAI-ARIA</li>
		<li><?php esc_html_e( 'WordPress (CMS)', 'livq-accessfix' ); ?></li>
	</ul>

	<h3><?php esc_html_e( 'Technical Accessibility Features (Automated Remediation)', 'livq-accessfix' ); ?></h3>
	<p>
		<?php
		printf(
			/* translators: %s: plugin name */
			wp_kses( __( 'The following technical accessibility features are automatically managed and remediated on this website server-side by <strong>%s</strong>:', 'livq-accessfix' ), array( 'strong' => array() ) ),
			'LivQ AccessFix'
		);
		?>
	</p>
		<?php if ( ! empty( $criteria ) ) : ?>
	<ul>
			<?php foreach ( $criteria as $item ) : ?>
			<li>
				<strong><?php echo esc_html( $item['criterion'] ); ?></strong>
				- <?php echo esc_html( $item['description'] ); ?>
			</li>
		<?php endforeach; ?>
	</ul>
	<?php else : ?>
	<p><?php esc_html_e( 'No automated remediation modules are currently active.', 'livq-accessfix' ); ?></p>
	<?php endif; ?>

	<h3><?php esc_html_e( 'Non-accessible Content', 'livq-accessfix' ); ?></h3>
		<?php if ( ! empty( $cfg['notes'] ) ) : ?>
		<p><?php echo wp_kses_post( $cfg['notes'] ); ?></p>
	<?php else : ?>
		<p><?php esc_html_e( 'Despite our best efforts, some content elements may have temporary limitations. We are actively working to audit and manually remediate these components.', 'livq-accessfix' ); ?></p>
	<?php endif; ?>

		<?php if ( ! $is_micro && ! $is_personal ) : ?>
	<p><strong>
			<?php
			if ( $is_public_admin ) {
				esc_html_e( 'Content exempt under Directive (EU) 2016/2102 Art. 1(4):', 'livq-accessfix' );
			} else {
				esc_html_e( 'Content exempt under Article 2(4) of Directive (EU) 2019/882:', 'livq-accessfix' );
			}
			?>
	</strong></p>
	<?php endif; ?>
		<?php if ( $is_micro || $is_personal ) : ?>
	<p><strong><?php esc_html_e( 'Known limitations:', 'livq-accessfix' ); ?></strong></p>
	<?php endif; ?>
	<ul>
		<li><?php esc_html_e( 'Pre-recorded time-based media (audio/video) published before 28 June 2025.', 'livq-accessfix' ); ?></li>
		<li><?php esc_html_e( 'Office file formats (PDF, DOCX, XLSX) published before 28 June 2025 and not used for active administrative processes.', 'livq-accessfix' ); ?></li>
		<li><?php esc_html_e( 'Online maps and mapping services, where essential navigational information is provided in an accessible digital alternative.', 'livq-accessfix' ); ?></li>
		<li><?php esc_html_e( 'Third-party content that is neither funded, developed by, nor under the direct control of this organization.', 'livq-accessfix' ); ?></li>
		<li><?php esc_html_e( 'Content from archived websites that has not been updated or restructured after 28 June 2025.', 'livq-accessfix' ); ?></li>
	</ul>

		<?php if ( ! empty( $cfg['disproportionate_burden'] ) ) : ?>
	<p><strong><?php esc_html_e( 'Disproportionate Burden:', 'livq-accessfix' ); ?></strong></p>
	<p><?php echo wp_kses_post( $cfg['disproportionate_burden'] ); ?></p>
	<?php endif; ?>

	<h3><?php esc_html_e( 'Feedback and Contact Information', 'livq-accessfix' ); ?></h3>
	<p><?php esc_html_e( 'We welcome your feedback on the accessibility of this website. If you encounter any accessibility barriers, please notify us so we can provide an accessible alternative or make the content accessible (EAA Art. 13):', 'livq-accessfix' ); ?></p>
	<ul>
		<?php if ( ! empty( $cfg['responsible_name'] ) ) : ?>
			<li>
				<strong><?php esc_html_e( 'Accessibility responsible:', 'livq-accessfix' ); ?></strong>
				<?php echo esc_html( $cfg['responsible_name'] ); ?>
			</li>
		<?php endif; ?>
		<?php if ( ! empty( $cfg['contact_email'] ) ) : ?>
			<li>
				<strong><?php esc_html_e( 'Email:', 'livq-accessfix' ); ?></strong>
				<a href="mailto:<?php echo esc_attr( $cfg['contact_email'] ); ?>">
					<?php echo esc_html( $cfg['contact_email'] ); ?>
				</a>
			</li>
		<?php endif; ?>
		<?php if ( ! empty( $cfg['contact_phone'] ) ) : ?>
			<li>
				<strong><?php esc_html_e( 'Phone:', 'livq-accessfix' ); ?></strong>
				<?php echo esc_html( $cfg['contact_phone'] ); ?>
			</li>
		<?php endif; ?>
		<?php if ( ! empty( $cfg['contact_url'] ) ) : ?>
			<li>
				<strong><?php esc_html_e( 'Contact form:', 'livq-accessfix' ); ?></strong>
				<a href="<?php echo esc_url( $cfg['contact_url'] ); ?>">
					<?php echo esc_html( $cfg['contact_url'] ); ?>
				</a>
			</li>
		<?php endif; ?>
		<li><?php esc_html_e( 'We acknowledge and aim to respond to accessibility feedback within 2 business days.', 'livq-accessfix' ); ?></li>
	</ul>

	<h3><?php esc_html_e( 'Enforcement Procedure', 'livq-accessfix' ); ?></h3>
	<p>
		<?php
		if ( $is_micro || $is_personal ) {
			esc_html_e( 'As this statement is provided voluntarily, there is no formal enforcement procedure. We welcome your feedback and will do our best to address accessibility issues in a reasonable timeframe.', 'livq-accessfix' );
		} elseif ( 0 === strpos( $locale, 'it' ) ) {
			if ( $is_public_admin ) {
				echo wp_kses(
					__( 'If you experience a persistent accessibility barrier and are unsatisfied with our response, you can file an official complaint with the national enforcement authority. In Italy, the competent supervisory body under Legislative Decree 106/2018 (implementing Directive 2016/2102) is <strong>AgID (Agenzia per l\'Italia Digitale)</strong>. Complaints must be submitted through the <strong>Difensore Civico per il Digitale</strong>.', 'livq-accessfix' ),
					array( 'strong' => array() )
				);
			} elseif ( $is_nonprofit ) {
				echo wp_kses(
					__( 'If you experience a persistent accessibility barrier and are unsatisfied with our response, you may contact the relevant national enforcement authority. In Italy, the competent body under the EAA framework (Legislative Decree 82/2022) is <strong>AgID (Agenzia per l\'Italia Digitale)</strong> through the <strong>Difensore Civico per il Digitale</strong>, where the EAA is applicable to your organization.', 'livq-accessfix' ),
					array( 'strong' => array() )
				);
			} else {
				echo wp_kses(
					__( 'If you experience a persistent accessibility barrier and are unsatisfied with our response, you can file an official complaint with the national enforcement authority. In Italy, the competent body under Legislative Decree 82/2022 (EAA) and Law 4/2004 is <strong>AgID (Agenzia per l\'Italia Digitale)</strong> through the <strong>Difensore Civico per il Digitale</strong>.', 'livq-accessfix' ),
					array( 'strong' => array() )
				);
			}
		} else {
			esc_html_e( 'If you are not satisfied with our response, you can contact the relevant national enforcement authority responsible for the European Accessibility Act (Directive 2019/882) in your country.', 'livq-accessfix' );
		}
		?>
	</p>
		<?php if ( ! empty( $cfg['enforcement_url'] ) ) : ?>
	<p>
		<a href="<?php echo esc_url( $cfg['enforcement_url'] ); ?>" target="_blank" rel="noopener noreferrer">
			<?php esc_html_e( 'File a complaint with the enforcement authority →', 'livq-accessfix' ); ?>
		</a>
	</p>
	<?php endif; ?>

	<hr style="border:none; border-top:1px solid #e0e0e0; margin:20px 0;" />
	<p style="font-size:.8em; color:#666;">
		<?php
		$confirmed = self::get_confirmation();
		printf(
			/* translators: 1: statement date, 2: evaluation date, 3: plugin version */
			esc_html__( 'Statement prepared: %1$s. Last evaluated: %2$s. Prepared with the assistance of LivQ AccessFix v%3$s automated tools. The accuracy and completeness of this statement is the sole responsibility of the website operator.', 'livq-accessfix' ),
			esc_html( $statement_date ),
			esc_html( $eval_date ),
			esc_html( LIVQACEA_VERSION )
		);
		if ( $next_review ) {
			echo ' ';
			printf(
				/* translators: %s: next review date */
				esc_html__( 'Next scheduled review: %s.', 'livq-accessfix' ),
				esc_html( $next_review )
			);
		}
		if ( $confirmed ) {
			echo ' ';
			printf(
				/* translators: 1: confirming user name, 2: confirmation date */
				esc_html__( 'Confirmed by %1$s on %2$s.', 'livq-accessfix' ),
				esc_html( $confirmed['user_name'] ),
				esc_html( wp_date( get_option( 'date_format' ), strtotime( $confirmed['confirmed_at'] ) ) )
			);
		}
		?>
	</p>

</div>
		<?php
		return (string) ob_get_clean();
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	/**
	 * Returns the list of organizational measures (key => translatable label).
	 *
	 * @return array<string, string>
	 */
	private static function org_measures_list(): array {
		return array(
			'mission'     => __( 'Accessibility is included as part of our mission statement and organizational policies.', 'livq-accessfix' ),
			'officer'     => __( 'We have appointed an Accessibility Officer (or equivalent role) responsible for ongoing compliance.', 'livq-accessfix' ),
			'training'    => __( 'We provide accessibility training to all staff who create or manage web content.', 'livq-accessfix' ),
			'procurement' => __( 'We include accessibility requirements in our procurement and vendor evaluation process.', 'livq-accessfix' ),
			'testing'     => __( 'We conduct periodic accessibility testing using automated tools and manual reviews.', 'livq-accessfix' ),
			'feedback'    => __( 'We have a formal process to handle and resolve accessibility complaints from users.', 'livq-accessfix' ),
		);
	}

	/**
	 * Returns the stored confirmation record, or null when it is absent or
	 * malformed (e.g. written by an older version in a different shape).
	 *
	 * @return array<string, string>|null
	 */
	private static function get_confirmation(): ?array {
		$confirmed = get_option( self::CONFIRM_OPTION );

		if ( ! is_array( $confirmed ) || empty( $confirmed['confirmed_at'] ) || empty( $confirmed['user_name'] ) ) {
			return null;
		}

		return $confirmed;
	}

	/**
	 * Returns the configuration array with defaults.
	 *
	 * @return array<string, string>
	 */
	private static function get_config(): array {
		$defaults = array(
			'sector'                  => 'private',
			'org_name'                => '',
			'website_url'             => '',
			'contact_email'           => '',
			'contact_url'             => '',
			'contact_phone'           => '',
			'responsible_name'        => '',
			'eval_date'               => gmdate( 'Y-m-d' ),
			'statement_date'          => gmdate( 'Y-m-d' ),
			'next_review_date'        => gmdate( 'Y-m-d', strtotime( '+1 year' ) ),
			'eval_method'             => 'automated',
			'conformance'             => 'partial',
			'org_measures'            => array(),
			'notes'                   => '',
			'disproportionate_burden' => '',
			'enforcement_url'         => '',
		);
		$stored   = get_option( self::OPTION_NAME, array() );
		return wp_parse_args( (array) $stored, $defaults );
	}

	/**
	 * Returns the list of active WCAG criteria based on enabled plugin modules.
	 *
	 * @param array<string, mixed> $options Plugin options from livqacea_options.
	 * @return array<int, array<string, string>>
	 */
	private static function get_active_criteria( array $options ): array {
		$map = array(
			'fix_external_links'      => array(
				'criterion'   => 'WCAG 2.4.4 - Link Purpose',
				'description' => __( 'All links that open in a new tab/window announce this to screen reader users via a hidden label.', 'livq-accessfix' ),
			),
			'inject_skip_link'        => array(
				'criterion'   => 'WCAG 2.4.1 - Bypass Blocks',
				'description' => __( 'A skip navigation link is provided as the first element in the page, allowing keyboard users to jump directly to the main content.', 'livq-accessfix' ),
			),
			'fix_image_alt'           => array(
				'criterion'   => 'WCAG 1.1.1 - Non-text Content',
				'description' => __( 'Decorative images carry an explicit empty alt attribute so screen readers skip them rather than announcing the file name.', 'livq-accessfix' ),
			),
			'inject_focus_css'        => array(
				'criterion'   => 'WCAG 2.4.11 - Focus Appearance',
				'description' => __( 'A visible, high-contrast focus indicator is applied to all interactive elements for keyboard and switch access users.', 'livq-accessfix' ),
			),
			'menu_aria_helper'        => array(
				'criterion'   => 'WCAG 4.1.2 - Name, Role, Value',
				'description' => __( 'Navigation menus with sub-menus expose aria-haspopup and aria-expanded state to assistive technologies.', 'livq-accessfix' ),
			),
			'heading_hierarchy_check' => array(
				'criterion'   => 'WCAG 1.3.1 - Info and Relationships',
				'description' => __( 'Content heading structure is validated on save to prevent level skips that disorient screen reader users navigating by heading.', 'livq-accessfix' ),
			),
			'fix_nameless_links'      => array(
				'criterion'   => 'WCAG 2.4.4 - Link Purpose / 4.1.2 - Name, Role, Value',
				'description' => __( 'Icon and image links without visible text receive programmatic accessible labels so screen readers announce the link destination.', 'livq-accessfix' ),
			),
			'fix_iframe_titles'       => array(
				'criterion'   => 'WCAG 4.1.2 - Name, Role, Value',
				'description' => __( 'Embedded iframes without a title attribute receive a programmatic title so screen readers can identify the embedded content.', 'livq-accessfix' ),
			),
			'fix_input_labels'        => array(
				'criterion'   => 'WCAG 1.3.1 - Info and Relationships / 4.1.2 - Name, Role, Value',
				'description' => __( 'Form inputs without a visible label receive programmatic labels so assistive technology users can identify each field.', 'livq-accessfix' ),
			),
			'woocommerce_a11y'        => array(
				'criterion'   => 'WCAG 4.1.2 - Name, Role, Value / 4.1.3 - Status Messages',
				'description' => __( 'WooCommerce quantity controls, product gallery, and cart notifications are enhanced with ARIA labels and live region announcements.', 'livq-accessfix' ),
			),
			'gutenberg_prepublish'    => array(
				'criterion'   => 'WCAG 1.3.1 - Info and Relationships / 1.1.1 - Non-text Content',
				'description' => __( 'A pre-publish accessibility panel in the block editor alerts authors about heading skips, missing alt text, and unlabeled form fields before content goes live.', 'livq-accessfix' ),
			),
		);

		$active = array();
		foreach ( $map as $key => $item ) {
			if ( ! empty( $options[ $key ] ) ) {
				$active[] = $item;
			}
		}

		return $active;
	}

	/**
	 * Returns the ID of the dedicated Accessibility Statement page if it exists.
	 *
	 * @return int|null Page ID or null.
	 */
	private static function get_statement_page_id(): ?int {
		$pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => array( 'publish', 'draft', 'pending' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_livqacea_statement_page', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => '1',                   // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		return ! empty( $pages ) ? (int) $pages[0] : null;
	}
}
