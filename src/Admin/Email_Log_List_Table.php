<?php
/**
 * WP_List_Table for the email log.
 *
 * @package LEAStudios\EmailTemplates\Admin
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Admin;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

use LEAStudios\EmailTemplates\Database\Email_Log_Entry;
use LEAStudios\EmailTemplates\Database\Email_Log_Repository;
use LEAStudios\EmailTemplates\Email\Email_Type_Registry;

/**
 * Renders the log list with type/status filters and per-row View/Resend actions.
 */
class Email_Log_List_Table extends \WP_List_Table {

	/**
	 * Repository.
	 *
	 * @var Email_Log_Repository
	 */
	private Email_Log_Repository $repo;

	/**
	 * Email type registry.
	 *
	 * @var Email_Type_Registry
	 */
	private Email_Type_Registry $registry;

	/**
	 * Constructor.
	 *
	 * @param Email_Log_Repository $repo     Repository instance.
	 * @param Email_Type_Registry  $registry Email type registry.
	 */
	public function __construct( Email_Log_Repository $repo, Email_Type_Registry $registry ) {
		parent::__construct(
			[
				'singular' => 'log_entry',
				'plural'   => 'log_entries',
				'ajax'     => false,
			]
		);
		$this->repo     = $repo;
		$this->registry = $registry;
	}

	/**
	 * Define the columns we render.
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return [
			'created_at' => __( 'Date', 'leastudios-email-templates' ),
			'type'       => __( 'Type', 'leastudios-email-templates' ),
			'recipient'  => __( 'Recipient', 'leastudios-email-templates' ),
			'subject'    => __( 'Subject', 'leastudios-email-templates' ),
			'status'     => __( 'Status', 'leastudios-email-templates' ),
			'actions'    => __( 'Actions', 'leastudios-email-templates' ),
		];
	}

	/**
	 * Load the items based on the current query string.
	 *
	 * @return void
	 */
	public function prepare_items(): void {
		$per_page = 25;
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filter inputs on a capability-gated admin page.
		$paged   = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( (string) $_GET['paged'] ) ) ) : 1;
		$filters = [
			'type'   => isset( $_GET['type'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['type'] ) ) : '',
			'status' => isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['status'] ) ) : '',
		];
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$page = $this->repo->paginate( $filters, $per_page, $paged );

		$this->items           = $page['rows'];
		$this->_column_headers = [ $this->get_columns(), [], [] ];

		$this->set_pagination_args(
			[
				'total_items' => $page['total'],
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $page['total'] / $per_page ),
			]
		);
	}

	/**
	 * Default column renderer.
	 *
	 * @param Email_Log_Entry $item        Row.
	 * @param string          $column_name Column slug.
	 * @return string
	 */
	public function column_default( $item, $column_name ): string {
		return isset( $item->{$column_name} ) ? esc_html( (string) $item->{$column_name} ) : '';
	}

	/**
	 * Status column — colour-coded.
	 *
	 * @param Email_Log_Entry $item Row.
	 * @return string
	 */
	public function column_status( $item ): string {
		$color = 'sent' === $item->status ? '#1d8a1d' : '#b32d2e';
		return sprintf( '<span style="color:%s;font-weight:600;">%s</span>', esc_attr( $color ), esc_html( $item->status ) );
	}

	/**
	 * Recipient column — appends `(cli)` when the row was created by the
	 * `wp leastudios-email-templates send-test` command.
	 *
	 * @param Email_Log_Entry $item Row.
	 * @return string
	 */
	public function column_recipient( $item ): string {
		$html = esc_html( $item->recipient );

		if ( 'web' !== $item->source ) {
			$html .= sprintf(
				' <span class="leastudios-source-badge" style="color:#646970;font-size:11px;">(%s)</span>',
				esc_html( 'cli-test' === $item->source ? 'cli' : $item->source )
			);
		}

		return $html;
	}

	/**
	 * Actions column — View and Resend.
	 *
	 * @param Email_Log_Entry $item Row.
	 * @return string
	 */
	public function column_actions( $item ): string {
		$view_url   = add_query_arg(
			[
				'page' => 'leastudios-email-templates-log',
				'view' => $item->id,
			],
			admin_url( 'admin.php' )
		);
		$resend_url = wp_nonce_url(
			add_query_arg(
				[
					'page'   => 'leastudios-email-templates-log',
					'resend' => $item->id,
				],
				admin_url( 'admin.php' )
			),
			'leastudios_email_templates_resend_' . $item->id
		);

		return sprintf(
			'<a href="%s">%s</a> | <a href="%s">%s</a>',
			esc_url( $view_url ),
			esc_html__( 'View', 'leastudios-email-templates' ),
			esc_url( $resend_url ),
			esc_html__( 'Resend', 'leastudios-email-templates' )
		);
	}

	/**
	 * Render filter dropdowns above the table.
	 *
	 * @param string $which 'top' or 'bottom'.
	 * @return void
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filter inputs.
		$type   = sanitize_text_field( wp_unslash( (string) ( $_GET['type'] ?? '' ) ) );
		$status = sanitize_text_field( wp_unslash( (string) ( $_GET['status'] ?? '' ) ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		?>
		<div class="alignleft actions">
			<select name="type">
				<option value=""><?php esc_html_e( 'All types', 'leastudios-email-templates' ); ?></option>
				<?php foreach ( $this->registry->all() as $case ) : ?>
					<option value="<?php echo esc_attr( $case->id() ); ?>" <?php selected( $case->id(), $type ); ?>>
						<?php echo esc_html( $case->label() ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<select name="status">
				<option value=""><?php esc_html_e( 'All statuses', 'leastudios-email-templates' ); ?></option>
				<option value="sent" <?php selected( 'sent', $status ); ?>><?php esc_html_e( 'Sent', 'leastudios-email-templates' ); ?></option>
				<option value="failed" <?php selected( 'failed', $status ); ?>><?php esc_html_e( 'Failed', 'leastudios-email-templates' ); ?></option>
			</select>
			<?php submit_button( __( 'Filter', 'leastudios-email-templates' ), '', 'filter', false ); ?>
		</div>
		<?php
	}
}
