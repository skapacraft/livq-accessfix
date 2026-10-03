<?php
/**
 * End-of-life notice.
 *
 * 1.1.1 is the last release of LivQ AccessFix. Every module keeps working as it
 * does today, but nobody will ship compatibility or security fixes any more, and
 * a site relying on this plugin for its accessibility obligations needs to know
 * that while there is still time to plan a replacement.
 *
 * Where the notice appears
 * ------------------------
 * Only on the Dashboard, the Plugins screen and the plugin's own pages, and only
 * to users who can manage plugins: the people who can act on it. Showing it on
 * every admin screen would be the kind of dashboard hijacking the WordPress.org
 * guidelines forbid. On the Dashboard and Plugins screen it can be dismissed for
 * good, per user. On the plugin's own pages it always stays, because that is
 * where somebody goes to decide what the plugin should keep doing.
 *
 * @package LivQ_AccessFix
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class LIVQACEA_End_Of_Life
 *
 * All methods are static: the class has no instance state.
 * LIVQACEA_Main::init_modules() calls self::init() once at boot.
 */
class LIVQACEA_End_Of_Life {

	/**
	 * Action name for the admin-post.php dismissal request, also the nonce action.
	 *
	 * @var string
	 */
	const DISMISS_ACTION = 'livqacea_dismiss_end_of_life';

	/**
	 * User meta key recording the dismissal. Per user, not per site: one
	 * administrator dismissing it must not hide it from the others.
	 *
	 * @var string
	 */
	const USER_META = 'livqacea_end_of_life_dismissed';

	/**
	 * Registers WordPress hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_notices', array( __CLASS__, 'render_notice' ) );
		add_action( 'admin_post_' . self::DISMISS_ACTION, array( __CLASS__, 'dismiss' ) );
	}

	/**
	 * Prints the notice on the screens listed in the file header.
	 *
	 * @return void
	 */
	public static function render_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		// Every page this plugin registers has its slug in the screen id:
		// toplevel_page_livq-accessfix, and the submenu pages under it.
		$own_page = false !== strpos( $screen->id, LIVQACEA_Backend::PAGE_SLUG );

		if ( ! $own_page ) {
			if ( ! in_array( $screen->id, array( 'dashboard', 'plugins' ), true ) ) {
				return;
			}
			if ( get_user_meta( get_current_user_id(), self::USER_META, true ) ) {
				return;
			}
		}

		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'LivQ AccessFix is no longer maintained.', 'livq-accessfix' ); ?></strong>
				<?php esc_html_e( 'This is its last release. The plugin keeps working as it does today, but it will receive no further updates, compatibility fixes or security fixes. We recommend planning a replacement for your site\'s accessibility work.', 'livq-accessfix' ); ?>
			</p>
			<?php if ( ! $own_page ) : ?>
				<p>
					<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::DISMISS_ACTION ), self::DISMISS_ACTION ) ); ?>">
						<?php esc_html_e( 'Don\'t show this again', 'livq-accessfix' ); ?>
					</a>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Records the dismissal and goes back to the screen it came from.
	 *
	 * A plain link rather than core's is-dismissible button: that one only hides
	 * the notice until the next page load, and a notice that keeps coming back
	 * after being closed is exactly what the guidelines ask plugins not to do.
	 *
	 * @return void
	 */
	public static function dismiss(): void {
		check_admin_referer( self::DISMISS_ACTION );

		if ( current_user_can( 'activate_plugins' ) ) {
			update_user_meta( get_current_user_id(), self::USER_META, 1 );
		}

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}
}
