<?php
/**
 * Plugin action links & review card.
 *
 * Responsibilities
 * ----------------
 * 1. Adds a "Settings" shortcut link in the plugin row on plugins.php.
 *    Hook: plugin_action_links_{basename}  (dynamic, resolved at init time
 *    using LIVQACEA_PLUGIN_FILE so it works even if the folder is renamed).
 *
 * 2. Renders the review call-to-action card shown at the bottom of the
 *    settings page. Kept here - not in LIVQACEA_Backend - to isolate promotional
 *    UI from settings logic and make it easy to remove or reskin.
 *
 * Architecture note on naming
 * ----------------------------
 * The existing codebase uses a flat LIVQACEA_ prefix without PHP namespaces
 * (consistent with WordPress core conventions). The class is therefore named
 * LIVQACEA_Plugin_Links rather than EAADeveloperGuard\Admin\PluginActionLinks,
 * keeping the codebase uniform and avoiding autoloader requirements.
 *
 * @package LivQ_AccessFix
 * @since   1.1.4
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class LIVQACEA_Plugin_Links
 *
 * All methods are static: the class has no instance state.
 * LIVQACEA_Main::init_modules() calls self::init() once at boot.
 */
class LIVQACEA_Plugin_Links {

	/**
	 * WordPress.org review URL for this plugin.
	 *
	 * @var string
	 */
	const REVIEW_URL = 'https://wordpress.org/support/plugin/livq-accessfix/reviews/#new-post';

	/**
	 * Registers WordPress hooks.
	 *
	 * Called from LIVQACEA_Main::init_modules(). Uses plugin_basename() on the
	 * known plugin file constant to build the dynamic filter name - this is
	 * the WP-recommended approach and survives folder renames.
	 *
	 * @return void
	 */
	public static function init(): void {
		$basename = plugin_basename( LIVQACEA_PLUGIN_FILE );

		// plugin_action_links_{basename} fires only on the matching plugin row.
		add_filter(
			"plugin_action_links_{$basename}",
			array( __CLASS__, 'add_settings_link' )
		);
	}

	// -----------------------------------------------------------------------
	// Plugin action links
	// -----------------------------------------------------------------------

	/**
	 * Prepends a "Settings" link to the plugin's action links row.
	 *
	 * The link is prepended (array_unshift) so it appears first - before
	 * "Deactivate" - which is the standard convention for Settings links.
	 *
	 * The page slug references LIVQACEA_Backend::PAGE_SLUG directly (single
	 * source of truth) rather than a hardcoded literal, so this link can
	 * never drift out of sync with the registered menu slug again.
	 * Registered via add_menu_page() - a top-level menu - so the parent
	 * is admin.php, not options-general.php.
	 *
	 * @param array<int|string, string> $links Existing action links HTML strings.
	 * @return array<int|string, string> Modified links array.
	 */
	public static function add_settings_link( array $links ): array {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . LIVQACEA_Backend::PAGE_SLUG ) ),
			esc_html__( 'Settings', 'livq-accessfix' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	// -----------------------------------------------------------------------
	// Review card
	// -----------------------------------------------------------------------

	/**
	 * Outputs the review call-to-action card.
	 *
	 * Called directly from LIVQACEA_Backend::render_settings_page() after the
	 * main settings form. Uses the native WordPress .card class so it inherits
	 * core admin typography, border, and box-shadow without extra CSS.
	 *
	 * All strings are i18n-ready. The star emoji (⭐) is decorative and aria-hidden
	 * to prevent screen readers from spelling out "star star star star star".
	 *
	 * @return void
	 */
	public static function render_review_card(): void {
		?>
		<div class="livqacea-review-card" style="
			background:#fff;
			border:1px solid #c3c4c7;
			border-radius:8px;
			max-width:800px;
			margin-top:20px;
			display:flex;
			align-items:center;
			overflow:hidden;
		">
			<!-- Icon -->
			<div style="display:flex;align-items:center;justify-content:center;padding:20px 18px;flex-shrink:0;">
				<div style="width:40px;height:40px;border-radius:8px;background:#e8f0fb;display:flex;align-items:center;justify-content:center;">
					<span style="font-size:18px;color:#2271b1;" aria-hidden="true">✓</span>
				</div>
			</div>

			<!-- Text -->
			<div style="flex:1;padding:18px 12px 18px 0;">
				<p style="margin:0 0 4px;font-size:.875rem;font-weight:600;color:#1d2327;">
					<?php esc_html_e( 'Saving you time on EAA compliance?', 'livq-accessfix' ); ?>
				</p>
				<p style="margin:0;font-size:.8rem;color:#50575e;line-height:1.5;">
					<?php esc_html_e( 'A 5-star review takes 30 seconds and helps other developers discover this tool.', 'livq-accessfix' ); ?>
				</p>
			</div>

			<!-- CTA -->
			<div style="display:flex;align-items:center;padding:18px 20px 18px 12px;flex-shrink:0;gap:8px;">
				<span style="font-size:.9rem;letter-spacing:2px;color:#f0b429;" aria-hidden="true">★★★★★</span>
				<a
					href="<?php echo esc_url( self::REVIEW_URL ); ?>"
					target="_blank"
					rel="noopener noreferrer"
					style="
						display:inline-flex;
						align-items:center;
						gap:4px;
						background:#2271b1;
						color:#fff;
						font-size:.8rem;
						font-weight:600;
						padding:8px 14px;
						border-radius:4px;
						text-decoration:none;
						white-space:nowrap;
					"
				>
					<?php esc_html_e( 'Leave a review', 'livq-accessfix' ); ?>
					<span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'livq-accessfix' ); ?></span>
				</a>
			</div>

		</div><!-- .livqacea-review-card -->

		<p style="margin:6px 0 0 4px;font-size:.75rem;color:#8c8f94;">
			<?php
			echo wp_kses_post(
				sprintf(
					/* translators: 1: LivQ brand link (HTML anchor tag), 2: SkapaCraft brand link (HTML anchor tag) */
					__( 'An open source project by %1$s and %2$s.', 'livq-accessfix' ),
					'<a href="https://livq.it" target="_blank" rel="noopener noreferrer" style="color:#2271b1;">LivQ</a>',
					'<a href="https://skapacraft.com" target="_blank" rel="noopener noreferrer" style="color:#2271b1;">SkapaCraft</a>'
				)
			);
			?>
		</p>
		<?php
	}
}
