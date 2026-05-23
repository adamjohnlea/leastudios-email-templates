<?php
/**
 * Admin page for managing email suppressions.
 *
 * @package LEAStudios\EmailTemplates\Admin
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Admin;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use LEAStudios\EmailTemplates\Subscription\Unsubscribe_Manager;

/**
 * Suppressions sub-page under "Email Templates". Adds, lists, and removes
 * rows in wp_leastudios_email_templates_suppressions.
 */
final class Suppressions_Page {

	/**
	 * Capability required to view/manage the page.
	 */
	private const CAPABILITY = 'manage_options';

	/**
	 * Constructor.
	 *
	 * @param Unsubscribe_Manager     $manager    Token/suppression facade.
	 * @param Suppressions_List_Table $list_table List table renderer.
	 */
	public function __construct(
		private readonly Unsubscribe_Manager $manager,
		private readonly Suppressions_List_Table $list_table,
	) {}

	/**
	 * Register menus + admin-post handlers.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_post_leastudios_email_templates_add_suppression', [ $this, 'handle_add' ] );
		add_action( 'admin_post_leastudios_email_templates_remove_suppression', [ $this, 'handle_remove' ] );
	}

	/**
	 * Register the sub-menu.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		add_submenu_page(
			'leastudios-email-templates',
			__( 'Suppressions', 'leastudios-email-templates' ),
			__( 'Suppressions', 'leastudios-email-templates' ),
			self::CAPABILITY,
			'leastudios-email-templates-suppressions',
			[ $this, 'render' ]
		);
	}

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'leastudios-email-templates' ) );
		}

		$this->list_table->prepare_items();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice flag on a capability-gated page.
		$notice = isset( $_GET['notice'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['notice'] ) ) : '';

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Email Suppressions', 'leastudios-email-templates' ); ?></h1>
			<?php if ( 'added' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Suppression added.', 'leastudios-email-templates' ); ?></p></div>
			<?php elseif ( 'removed' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Suppression removed.', 'leastudios-email-templates' ); ?></p></div>
			<?php elseif ( 'invalid_email' === $notice ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'That is not a valid email address.', 'leastudios-email-templates' ); ?></p></div>
			<?php endif; ?>

			<p><?php esc_html_e( 'Recipients listed here have opted out of all non-required transactional email. Required emails (receipts, refunds, payment-failure alerts, and renewal receipts) are still sent.', 'leastudios-email-templates' ); ?></p>

			<h2 class="title"><?php esc_html_e( 'Add suppression', 'leastudios-email-templates' ); ?></h2>
			<form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="leastudios_email_templates_add_suppression">
				<?php wp_nonce_field( 'leastudios_email_templates_add_suppression' ); ?>
				<input type="email" name="email" required placeholder="<?php esc_attr_e( 'jane@example.com', 'leastudios-email-templates' ); ?>" class="regular-text">
				<button type="submit" class="button button-secondary"><?php esc_html_e( 'Suppress', 'leastudios-email-templates' ); ?></button>
			</form>

			<h2 class="title"><?php esc_html_e( 'Suppressed addresses', 'leastudios-email-templates' ); ?></h2>
			<form method="GET">
				<input type="hidden" name="page" value="leastudios-email-templates-suppressions">
				<?php $this->list_table->search_box( __( 'Search', 'leastudios-email-templates' ), 'suppression-search' ); ?>
			</form>
			<?php $this->list_table->display(); ?>
		</div>
		<?php
	}

	/**
	 * Handle the Add form submission.
	 *
	 * @return void
	 */
	public function handle_add(): void {
		check_admin_referer( 'leastudios_email_templates_add_suppression' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'leastudios-email-templates' ) );
		}

		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( (string) $_POST['email'] ) ) : '';
		if ( '' === $email || ! is_email( $email ) ) {
			wp_safe_redirect( $this->redirect_url( 'invalid_email' ) );
			exit;
		}

		$this->manager->suppress( $email, 'admin' );
		wp_safe_redirect( $this->redirect_url( 'added' ) );
		exit;
	}

	/**
	 * Handle the Remove row action.
	 *
	 * @return void
	 */
	public function handle_remove(): void {
		check_admin_referer( 'leastudios_email_templates_remove_suppression' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'leastudios-email-templates' ) );
		}

		$email = isset( $_GET['email'] ) ? sanitize_email( wp_unslash( (string) $_GET['email'] ) ) : '';
		if ( '' === $email || ! is_email( $email ) ) {
			wp_safe_redirect( $this->redirect_url( 'invalid_email' ) );
			exit;
		}

		$this->manager->unsuppress( $email );
		wp_safe_redirect( $this->redirect_url( 'removed' ) );
		exit;
	}

	/**
	 * Build the page URL with a notice query var.
	 *
	 * @param string $notice Notice slug.
	 * @return string
	 */
	private function redirect_url( string $notice ): string {
		return add_query_arg(
			[
				'page'   => 'leastudios-email-templates-suppressions',
				'notice' => $notice,
			],
			admin_url( 'admin.php' )
		);
	}
}
