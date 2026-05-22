<?php
/**
 * Admin page for the email send log.
 *
 * @package LEAStudios\EmailTemplates\Admin
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Admin;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use LEAStudios\EmailTemplates\Database\Email_Log_Repository;

/**
 * Registers the submenu, renders list + detail views, and handles resend.
 */
class Email_Log_Page {

	private const CAPABILITY = 'manage_options';
	private const SLUG       = 'leastudios-email-templates-log';

	/**
	 * Constructor.
	 *
	 * @param Email_Log_Repository $repo Repository.
	 */
	public function __construct( private readonly Email_Log_Repository $repo ) {}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_init', [ $this, 'maybe_handle_resend' ] );
	}

	/**
	 * Add the submenu under Email Templates.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_submenu_page(
			'leastudios-email-templates',
			__( 'Email Log', 'leastudios-email-templates' ),
			__( 'Log', 'leastudios-email-templates' ),
			self::CAPABILITY,
			self::SLUG,
			[ $this, 'render' ]
		);
	}

	/**
	 * Render either the list or the detail view based on the query string.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter input on a capability-gated page.
		$view_id = isset( $_GET['view'] ) ? (int) $_GET['view'] : 0;

		if ( $view_id > 0 ) {
			$this->render_detail( $view_id );
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice flag.
		$resent = isset( $_GET['resent'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['resent'] ) ) : '';

		$table = new Email_Log_List_Table( $this->repo );
		$table->prepare_items();

		echo '<div class="wrap"><h1>' . esc_html__( 'Email Log', 'leastudios-email-templates' ) . '</h1>';

		if ( '1' === $resent ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Resent successfully.', 'leastudios-email-templates' ) . '</p></div>';
		} elseif ( '0' === $resent ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Resend failed. wp_mail returned false.', 'leastudios-email-templates' ) . '</p></div>';
		}

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" />';
		$table->display();
		echo '</form></div>';
	}

	/**
	 * Render the detail view for a single log row.
	 *
	 * @param int $id Row ID.
	 * @return void
	 */
	private function render_detail( int $id ): void {
		$row = $this->repo->get( $id );
		if ( null === $row ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Not found', 'leastudios-email-templates' ) . '</h1></div>';
			return;
		}

		$back = add_query_arg( [ 'page' => self::SLUG ], admin_url( 'admin.php' ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Log Entry', 'leastudios-email-templates' ); ?></h1>
			<p><a href="<?php echo esc_url( $back ); ?>">&larr; <?php esc_html_e( 'Back to log', 'leastudios-email-templates' ); ?></a></p>
			<table class="form-table">
				<tr><th><?php esc_html_e( 'Date', 'leastudios-email-templates' ); ?></th><td><?php echo esc_html( $row->created_at ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Type', 'leastudios-email-templates' ); ?></th><td><?php echo esc_html( $row->type ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Recipient', 'leastudios-email-templates' ); ?></th><td><?php echo esc_html( $row->recipient ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Subject', 'leastudios-email-templates' ); ?></th><td><?php echo esc_html( $row->subject ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Status', 'leastudios-email-templates' ); ?></th><td><?php echo esc_html( $row->status ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Headers', 'leastudios-email-templates' ); ?></th><td><pre style="white-space:pre-wrap;background:#f6f7f7;padding:8px;border-radius:4px;"><?php echo esc_html( $row->headers ); ?></pre></td></tr>
			</table>
			<h2><?php esc_html_e( 'Body', 'leastudios-email-templates' ); ?></h2>
			<iframe srcdoc="<?php echo esc_attr( $row->body ); ?>" style="width:100%;height:600px;border:1px solid #ccd0d4;background:#fff;"></iframe>
		</div>
		<?php
	}

	/**
	 * Handle ?resend=N when the nonce matches.
	 *
	 * Creates a fresh wp_mail call from the stored body + headers and writes
	 * a new log row prefixed with `[Resend]` so the original entry stays
	 * intact alongside the retry attempt.
	 *
	 * @return void
	 */
	public function maybe_handle_resend(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce is verified below via check_admin_referer.
		if ( empty( $_GET['resend'] ) || empty( $_GET['_wpnonce'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified two lines down.
		$id = (int) $_GET['resend'];
		check_admin_referer( 'leastudios_email_templates_resend_' . $id );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$row = $this->repo->get( $id );
		if ( null === $row ) {
			return;
		}

		$headers = array_values( array_filter( explode( "\n", $row->headers ) ) );
		$subject = '[Resend] ' . $row->subject;
		$result  = wp_mail( $row->recipient, $subject, $row->body, $headers );

		$this->repo->create(
			[
				'type'      => $row->type,
				'recipient' => $row->recipient,
				'subject'   => $subject,
				'body'      => $row->body,
				'headers'   => $row->headers,
				'status'    => $result ? 'sent' : 'failed',
				'error'     => null,
			]
		);

		wp_safe_redirect(
			add_query_arg(
				[
					'page'   => self::SLUG,
					'resent' => $result ? '1' : '0',
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
