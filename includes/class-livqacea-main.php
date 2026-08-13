<?php
/**
 * Core bootstrap - Singleton pattern.
 *
 * Instantiates sub-modules and loads the text domain.
 * Using Singleton ensures hooks are registered exactly once regardless of
 * how many times get_instance() is called (safe for unit-test environments).
 *
 * @package LivQ_AccessFix
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class LIVQACEA_Main
 *
 * Central orchestrator. Sub-classes are passed the settings array so they
 * never need to hit the database individually.
 */
final class LIVQACEA_Main {

	/**
	 * Singleton instance.
	 *
	 * @var LIVQACEA_Main|null
	 */
	private static $instance = null;

	/**
	 * Plugin settings fetched once from the database.
	 *
	 * @var array<string, bool>
	 */
	private $options = array();

	/**
	 * Private constructor - use get_instance().
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'load_textdomain' ), 0 );
		$this->load_options();
		$this->init_modules();
	}

	/**
	 * Returns (and creates if necessary) the singleton instance.
	 *
	 * @return LIVQACEA_Main
	 */
	public static function get_instance(): LIVQACEA_Main {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Prevent cloning of the singleton.
	 */
	private function __clone() {}

	/**
	 * Prevent deserialization - would bypass the singleton guarantee.
	 *
	 * @throws \RuntimeException Always.
	 */
	public function __wakeup() {
		throw new \RuntimeException( 'Cannot unserialize singleton.' );
	}

	// -----------------------------------------------------------------------
	// Public hooks
	// -----------------------------------------------------------------------

	/**
	 * Loads the plugin text domain.
	 *
	 * Uses load_textdomain() directly (not load_plugin_textdomain()) to avoid
	 * the PluginCheck warning while still supporting bundled .mo files on local
	 * installs. On WP.org installs WordPress auto-loads translations earlier
	 * from wp-content/languages/plugins/ and load_textdomain() becomes a no-op.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		$locale = determine_locale();
		$domain = 'livq-accessfix';
		$mofile = plugin_dir_path( LIVQACEA_PLUGIN_FILE ) . 'languages/' . $domain . '-' . $locale . '.mo';
		load_textdomain( $domain, $mofile );
	}

	// -----------------------------------------------------------------------
	// Private helpers
	// -----------------------------------------------------------------------

	/**
	 * Loads plugin options from the database with sane defaults.
	 *
	 * Defaults are all-true so a fresh install is immediately helpful
	 * without any admin configuration required.
	 *
	 * @return void
	 */
	private function load_options(): void {
		$defaults = array(
			// Moduli base (v1.0.0).
			'fix_external_links'      => true,
			'inject_skip_link'        => true,
			'fix_image_alt'           => true,
			'inject_focus_css'        => true,
			// Moduli avanzati (v1.1.0).
			'menu_aria_helper'        => true,
			'heading_hierarchy_check' => true,
			'gutenberg_prepublish'    => true,
			// HTML Output Remediations (v1.0.0 - EAA).
			'fix_nameless_links'      => true,
			'fix_iframe_titles'       => true,
			'fix_input_labels'        => true,
			// HTML Output Remediations (v1.1.0 - EAA).
			'fix_button_labels'       => true,
			'fix_autocomplete'        => true,
			// WooCommerce (v1.0.0).
			'woocommerce_a11y'        => true,
			// Accessibility Statement (v1.0.0).
			'a11y_statement'          => true,
			// Configuration (v1.0.0).
			'skip_link_target'        => '',
			'delete_on_uninstall'     => false,
		);

		$stored = get_option( 'livqacea_options', array() );

		// Merge so new options added in future versions are automatically enabled.
		$this->options = wp_parse_args( $stored, $defaults );
	}

	/**
	 * Initialises frontend and backend sub-modules.
	 *
	 * @return void
	 */
	private function init_modules(): void {
		new LIVQACEA_Frontend( $this->options );
		new LIVQACEA_Advanced( $this->options );
		new LIVQACEA_Backend( $this->options );
		if ( class_exists( 'WooCommerce' ) ) {
			new LIVQACEA_WooCommerce( $this->options );
		}
		LIVQACEA_Plugin_Links::init();
		LIVQACEA_Statement::init();
		LIVQACEA_Scanner::init();
		LIVQACEA_Contrast::init();

		add_action( 'wp_ajax_livqacea_detect_skip_target', array( 'LIVQACEA_Backend', 'ajax_detect_skip_target' ) );
	}
}
