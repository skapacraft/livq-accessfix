<?php
/**
 * Advanced modules - Heading Hierarchy Checker & Gutenberg Pre-Publish Panel.
 *
 * Module 2 - Heading Hierarchy Checker
 * ------------------------------------
 * Hooks into save_post to scan post content for heading-level skips
 * (e.g. H2 → H4 without an intermediate H3). Skipping levels breaks
 * the logical document outline, which disorients screen reader users
 * navigating by heading (WCAG 1.3.1 Info and Relationships, technique H42).
 *
 * We deliberately do NOT block the save - interrupting the editorial flow
 * causes user frustration and data loss risk. Instead we set a short-lived
 * transient keyed to the post ID and display an admin notice on the next
 * request to the edit screen.
 *
 * Module 3 - Gutenberg Pre-Publish Panel
 * ----------------------------------------
 * Registers a block-editor JS asset that inserts a PluginPrePublishPanel.
 * The panel checks in real time (client-side, no AJAX):
 *   A) core/image blocks with an empty alt attribute.
 *   B) Paragraph blocks containing anchor tags whose visible text is a raw URL.
 *
 * @package LivQ_AccessFix
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class LIVQACEA_Advanced
 */
class LIVQACEA_Advanced {

	/**
	 * Post meta key used to persist detected accessibility issues.
	 *
	 * Replaces the former transient-based approach (TRANSIENT_PREFIX / TTL).
	 * post_meta survives across sessions and can be queried for audit reports.
	 *
	 * @var string
	 */
	const META_KEY = '_livqacea_a11y_issues';

	/**
	 * Plugin options passed from LIVQACEA_Main.
	 *
	 * @var array<string, bool>
	 */
	private $options;

	/**
	 * Constructor.
	 *
	 * @param array<string, bool> $options Sanitised plugin options.
	 */
	public function __construct( array $options ) {
		$this->options = $options;
		$this->register_hooks();
	}

	/**
	 * Registers hooks according to active modules.
	 *
	 * @return void
	 */
	private function register_hooks(): void {

		if ( ! empty( $this->options['heading_hierarchy_check'] ) ) {
			// save_post fires after the post is written to the DB and gives us
			// the definitive post ID - safer than wp_insert_post_data for
			// read-only side-effects like persisting issues.
			add_action( 'save_post', array( $this, 'check_heading_hierarchy' ), 10, 2 );

			// admin_notices fires on every admin page load; we gate on screen/post.
			add_action( 'admin_notices', array( $this, 'show_heading_notice' ) );
		}

		// CSV export AJAX handler - logged-in admin only.
		add_action( 'wp_ajax_livqacea_export_issues_csv', array( $this, 'export_issues_csv' ) );

		if ( ! empty( $this->options['gutenberg_prepublish'] ) ) {
			add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_prepublish_script' ) );
		}
	}

	// -----------------------------------------------------------------------
	// Module 2 - Heading Hierarchy Checker
	// -----------------------------------------------------------------------

	/**
	 * Scans the saved post content for heading-level skips.
	 *
	 * Skip conditions that make the check irrelevant:
	 * - Autosaves: content is partial; a false positive would be annoying.
	 * - Revisions: we act on the canonical post, not its history.
	 * - Attachments / nav_menu_items / other non-editorial post types.
	 *
	 * Algorithm:
	 * 1. Extract all <hN> tags in document order via regex.
	 * 2. Walk the sequence: if level[i] > level[i-1] + 1, that is a skip.
	 *    (Going from H3 to H2 is allowed - that's a valid heading reset.)
	 * 3. On the first skip found, store a descriptive message as a transient.
	 *    On a clean save, delete any previously stored transient.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object after save.
	 * @return void
	 */
	public function check_heading_hierarchy( int $post_id, \WP_Post $post ): void {

		// Guard: skip autosaves.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Guard: skip revisions and autosave copies.
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		// Guard: capability check - prevents the hook from acting on a save
		// triggered by a lower-privileged user (e.g. via REST API or XML-RPC).
		//
		// The check is skipped when there is no current user at all: scheduled
		// publications, WP-CLI and cron run userless, and bailing out there left
		// stale issue meta behind on posts that had actually been fixed. Nothing
		// here is privileged - we only persist a read-only analysis result.
		if ( get_current_user_id() > 0 && ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Guard: only check editorial post types (not attachments, menus, etc.).
		$editorial_types = (array) apply_filters( 'livqacea_heading_check_post_types', array( 'post', 'page' ) );
		if ( ! in_array( $post->post_type, $editorial_types, true ) ) {
			return;
		}

		$issue = $this->detect_hierarchy_skip( $post->post_content );

		if ( $issue ) {
			// Persist as post_meta so the issue survives across sessions and is
			// queryable for audit reports and CSV export.
			$log_entry = array(
				'type'    => 'heading_hierarchy',
				'message' => $issue,
				'time'    => current_time( 'mysql' ),
				'wcag'    => '1.3.1',
			);
			update_post_meta( $post_id, self::META_KEY, wp_json_encode( $log_entry ) );
		} else {
			delete_post_meta( $post_id, self::META_KEY );
		}
	}

	/**
	 * Analyses heading levels in content and returns an issue description or ''.
	 *
	 * We use a simple regex rather than DOMDocument here because:
	 * - We only need tag names, not content or attributes.
	 * - The regex is narrow (h[1-6] only) with no risk of catastrophic backtracking.
	 * - DOMDocument requires a full HTML skeleton for reliable parsing of fragments.
	 *
	 * @param string $content Raw post content (may contain block comments).
	 * @return string Human-readable issue description, or empty string if clean.
	 */
	private function detect_hierarchy_skip( string $content ): string {
		if ( empty( $content ) ) {
			return '';
		}

		// Match opening heading tags only; case-insensitive; capture the level digit.
		preg_match_all( '/<h([1-6])[\s>\/]/i', $content, $matches );

		if ( empty( $matches[1] ) || count( $matches[1] ) < 2 ) {
			// Zero or one heading - nothing to compare.
			return '';
		}

		$levels = array_map( 'intval', $matches[1] );
		$prev   = $levels[0];

		for ( $i = 1, $len = count( $levels ); $i < $len; $i++ ) {
			$current = $levels[ $i ];

			// Heading level jumps DOWN (higher number) by more than one step.
			if ( $current > $prev + 1 ) {
				return sprintf(
					/* translators: 1: found heading level, 2: previous level, 3: missing intermediate level */
					__( 'Heading hierarchy skip: found <h%1$d> right after <h%2$d> without a <h%3$d> in between. This disorients screen reader users navigating by headings (WCAG 1.3.1).', 'livq-accessfix' ),
					$current,
					$prev,
					$prev + 1
				);
			}

			$prev = $current;
		}

		return '';
	}

	/**
	 * Shows a warning admin notice if a heading hierarchy issue was detected.
	 *
	 * Runs on every admin page load; we exit early unless we are on the
	 * post edit screen and the relevant transient is set.
	 *
	 * We read the post ID from $_GET['post'] (the standard WP edit URL parameter)
	 * rather than get_the_ID() because the latter is unreliable in admin context
	 * before the post is loaded into the global $post object.
	 *
	 * @return void
	 */
	public function show_heading_notice(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'post' !== $screen->base ) {
			return;
		}

		// Reading $_GET['post'] to look up a transient - no privileged action taken.
		// Nonce verification is not applicable: this is a read-only URL parameter
		// set by WordPress core on the standard post edit screen URL (post.php?post=N).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;
		if ( ! $post_id ) {
			return;
		}

		$raw = get_post_meta( $post_id, self::META_KEY, true );
		if ( ! $raw ) {
			return;
		}

		$entry = json_decode( $raw, true );
		$issue = is_array( $entry ) && ! empty( $entry['message'] ) ? $entry['message'] : (string) $raw;

		printf(
			'<div class="notice notice-warning is-dismissible"><p>' .
			'<strong>%1$s</strong> %2$s</p></div>',
			esc_html__( 'LivQ AccessFix - Heading Hierarchy:', 'livq-accessfix' ),
			esc_html( $issue )
		);
	}

	// -----------------------------------------------------------------------
	// Module 3 - Gutenberg Pre-Publish Panel
	// -----------------------------------------------------------------------

	// -----------------------------------------------------------------------
	// Issues log - query & CSV export
	// -----------------------------------------------------------------------

	/**
	 * Returns all posts that have an accessibility issue stored in post_meta.
	 *
	 * @return array<int, array<string,mixed>> List of issue rows keyed by post_id.
	 */
	public static function get_all_issues(): array {
		$posts = get_posts(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'meta_key'       => self::META_KEY,  // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'fields'         => 'ids',
			)
		);

		$rows = array();
		foreach ( $posts as $pid ) {
			$raw    = get_post_meta( $pid, self::META_KEY, true );
			$entry  = json_decode( $raw, true );
			$rows[] = array(
				'post_id'    => $pid,
				'post_title' => get_the_title( $pid ),
				'edit_url'   => get_edit_post_link( $pid, 'raw' ),
				'type'       => is_array( $entry ) ? ( $entry['type'] ?? 'unknown' ) : 'unknown',
				'wcag'       => is_array( $entry ) ? ( $entry['wcag'] ?? '' ) : '',
				'message'    => is_array( $entry ) ? ( $entry['message'] ?? $raw ) : $raw,
				'time'       => is_array( $entry ) ? ( $entry['time'] ?? '' ) : '',
			);
		}

		return $rows;
	}

	/**
	 * AJAX handler - streams the issues log as a CSV download.
	 *
	 * Capability-gated (manage_options) and nonce-protected.
	 *
	 * @return void
	 */
	public function export_issues_csv(): void {
		if ( ! current_user_can( 'manage_options' ) ||
			! isset( $_GET['nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['nonce'] ) ), 'livqacea_export_csv' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'livq-accessfix' ), 403 );
		}

		$rows = self::get_all_issues();

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="livq-accessfix-issues-' . gmdate( 'Y-m-d' ) . '.csv"' );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$out = fopen( 'php://output', 'w' );
		if ( ! $out ) {
			wp_die( 'Could not open output stream.', 500 );
		}

		// BOM for Excel UTF-8 compatibility - echo avoids WPCS fwrite flag on php://output.
		echo "\xEF\xBB\xBF"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		fputcsv( $out, array( 'Post ID', 'Post Title', 'Issue Type', 'WCAG Criterion', 'Message', 'Detected At', 'Edit URL' ) );

		foreach ( $rows as $row ) {
			fputcsv(
				$out,
				array_map(
					array( __CLASS__, 'csv_escape' ),
					array(
						$row['post_id'],
						$row['post_title'],
						$row['type'],
						$row['wcag'],
						$row['message'],
						$row['time'],
						$row['edit_url'],
					)
				)
			);
		}

		exit;
	}

	/**
	 * Neutralises spreadsheet formula injection in an exported CSV cell.
	 *
	 * A post titled "=HYPERLINK(...)" would otherwise be executed as a formula
	 * when the export is opened in Excel, LibreOffice or Google Sheets. Prefixing
	 * a single quote forces the cell to be treated as text.
	 *
	 * @param mixed $value Raw cell value.
	 * @return string Safe cell value.
	 */
	private static function csv_escape( $value ): string {
		$value = (string) $value;

		if ( '' !== $value && false !== strpos( "=+-@\t\r", $value[0] ) ) {
			return "'" . $value;
		}

		return $value;
	}

	/**
	 * Enqueues the Gutenberg pre-publish accessibility checker.
	 *
	 * Dependencies array uses WP's registered handles for block-editor globals.
	 * All wp.* globals listed here are exposed by Gutenberg automatically when
	 * the block editor is loaded - no npm build step is required.
	 *
	 * wp-set-script-translations() wires up wp.i18n so __() inside the JS
	 * file resolves against our .po translations.
	 *
	 * @return void
	 */
	public function enqueue_prepublish_script(): void {
		wp_enqueue_script(
			'livqacea-prepublish',
			LIVQACEA_PLUGIN_URL . 'assets/js/livqacea-prepublish.js',
			array(
				'wp-plugins',   // Provides the registerPlugin API.
				'wp-edit-post', // Provides PluginPrePublishPanel.
				'wp-element',   // Provides createElement and useState.
				'wp-data',      // Provides useSelect and select.
				'wp-i18n',      // Provides the translation helpers.
				'wp-blocks',    // Provides getBlockType used for block name lookup.
			),
			LIVQACEA_VERSION,
			true // Load in footer - block editor JS must be deferred.
		);

		// Wire up server-side translation catalogue to wp.i18n in the browser.
		wp_set_script_translations( 'livqacea-prepublish', 'livq-accessfix' );
	}
}
