<?php
/**
 * Plugin Name:       LivQ AccessFix – EAA & A11y AutoFix
 * Plugin URI:        https://skapacraft.com/tools/plugins/livq-accessfix/
 * Description:       Automated WCAG 2.2 AA & European Accessibility Act (EAA) remediation for WordPress: focus styles, external link labels, skip links, alt attributes, heading hierarchy, ARIA menus, and Gutenberg pre-publish checks - zero configuration required.
 * Version:           1.0.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            LivQ
 * Author URI:        https://skapacraft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       livq-accessfix
 * Domain Path:       /languages
 *
 * @package LivQ_AccessFix
 */

// Block direct file execution - fundamental WordPress security rule.
defined( 'ABSPATH' ) || exit;

// ---------------------------------------------------------------------------
// Constants - single point of truth for paths and version.
// ---------------------------------------------------------------------------
define( 'LIVQACEA_VERSION', '1.0.1' );
define( 'LIVQACEA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'LIVQACEA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'LIVQACEA_PLUGIN_FILE', __FILE__ );

// ---------------------------------------------------------------------------
// Autoload class files - keep bootstrap lean; logic lives in includes/.
// ---------------------------------------------------------------------------
require_once LIVQACEA_PLUGIN_DIR . 'includes/class-livqacea-frontend.php';
require_once LIVQACEA_PLUGIN_DIR . 'includes/class-livqacea-advanced.php';
require_once LIVQACEA_PLUGIN_DIR . 'includes/class-livqacea-backend.php';
require_once LIVQACEA_PLUGIN_DIR . 'includes/class-livqacea-plugin-links.php';
require_once LIVQACEA_PLUGIN_DIR . 'includes/class-livqacea-statement.php';
require_once LIVQACEA_PLUGIN_DIR . 'includes/class-livqacea-scanner.php';
require_once LIVQACEA_PLUGIN_DIR . 'includes/class-livqacea-contrast.php';
require_once LIVQACEA_PLUGIN_DIR . 'includes/class-livqacea-woocommerce.php';
require_once LIVQACEA_PLUGIN_DIR . 'includes/class-livqacea-main.php';

// ---------------------------------------------------------------------------
// Kick-off - runs after all plugins are loaded to respect dependency order.
// ---------------------------------------------------------------------------
add_action( 'plugins_loaded', array( 'LIVQACEA_Main', 'get_instance' ) );

// ---------------------------------------------------------------------------
// Deactivation - clear the scanner's scheduled cron so no orphaned event
// lingers in wp_options after the plugin is switched off.
// ---------------------------------------------------------------------------
register_deactivation_hook(
	__FILE__,
	static function () {
		wp_clear_scheduled_hook( LIVQACEA_Scanner::CRON_HOOK );
	}
);
