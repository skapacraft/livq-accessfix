<?php
/**
 * Plugin action links.
 *
 * Adds a "Settings" shortcut link in the plugin row on plugins.php.
 * Hook: plugin_action_links_{basename}  (dynamic, resolved at init time
 * using LIVQACEA_PLUGIN_FILE so it works even if the folder is renamed).
 *
 * The review call-to-action card that used to live here was removed in 1.1.1:
 * asking for reviews of a plugin that is no longer maintained would be asking
 * people to recommend something they should be planning to replace.
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
}
