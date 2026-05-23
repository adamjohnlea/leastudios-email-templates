<?php
/**
 * Admin list table for the suppressions page.
 *
 * @package LEAStudios\EmailTemplates\Admin
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Admin;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

use LEAStudios\EmailTemplates\Database\Suppression_Entry;
use LEAStudios\EmailTemplates\Database\Suppression_Repository;

/**
 * Paginated suppressions list with a single Remove row action.
 */
final class Suppressions_List_Table extends \WP_List_Table {

	/**
	 * Per-page row count.
	 */
	private const PER_PAGE = 20;

	/**
	 * Constructor.
	 *
	 * @param Suppression_Repository $repo Repository (used by prepare_items).
	 */
	public function __construct(
		private readonly Suppression_Repository $repo,
	) {
		parent::__construct(
			[
				'singular' => 'suppression',
				'plural'   => 'suppressions',
				'ajax'     => false,
			]
		);
	}

	/**
	 * Define columns.
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return [
			'cb'            => '<input type="checkbox" />',
			'email'         => __( 'Email', 'leastudios-email-templates' ),
			'suppressed_at' => __( 'Suppressed at', 'leastudios-email-templates' ),
			'source'        => __( 'Source', 'leastudios-email-templates' ),
		];
	}

	/**
	 * Define bulk actions.
	 *
	 * @return array<string, string>
	 */
	public function get_bulk_actions(): array {
		return [
			'remove' => __( 'Remove', 'leastudios-email-templates' ),
		];
	}

	/**
	 * Render the bulk-action checkbox column.
	 *
	 * @param Suppression_Entry $item Row entry.
	 * @return string
	 */
	public function column_cb( $item ): string {
		return sprintf( '<input type="checkbox" name="suppression[]" value="%d" />', $item->id );
	}

	/**
	 * Render the email column with the Remove row action.
	 *
	 * @param Suppression_Entry $item Row entry.
	 * @return string
	 */
	protected function column_email( Suppression_Entry $item ): string {
		$nonce_url = wp_nonce_url(
			add_query_arg(
				[
					'action' => 'leastudios_email_templates_remove_suppression',
					'email'  => rawurlencode( $item->email ),
				],
				admin_url( 'admin-post.php' )
			),
			'leastudios_email_templates_remove_suppression'
		);

		$actions = [
			'remove' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( $nonce_url ),
				esc_html__( 'Remove', 'leastudios-email-templates' )
			),
		];

		return sprintf(
			'<strong>%s</strong> %s',
			esc_html( $item->email ),
			$this->row_actions( $actions )
		);
	}

	/**
	 * Test-only shim exposing the protected column_email rendering.
	 *
	 * @param Suppression_Entry $item Row entry.
	 * @return string
	 */
	public function column_email_test_shim( Suppression_Entry $item ): string {
		return $this->column_email( $item );
	}

	/**
	 * Generic column renderer.
	 *
	 * @param Suppression_Entry $item        Row entry.
	 * @param string            $column_name Column id.
	 * @return string
	 */
	protected function column_default( $item, $column_name ): string {
		return match ( $column_name ) {
			'suppressed_at' => esc_html( $item->suppressed_at ),
			'source'        => esc_html( $item->source ),
			default         => '',
		};
	}

	/**
	 * Test-only shim for column_default.
	 *
	 * @param Suppression_Entry $item        Row entry.
	 * @param string            $column_name Column id.
	 * @return string
	 */
	public function column_default_test_shim( Suppression_Entry $item, string $column_name ): string {
		return $this->column_default( $item, $column_name );
	}

	/**
	 * Populate items + pagination state.
	 *
	 * @return void
	 */
	public function prepare_items(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only paging/search on a capability-gated admin page.
		$current_page = isset( $_REQUEST['paged'] ) ? max( 1, absint( wp_unslash( (string) $_REQUEST['paged'] ) ) ) : 1;
		$search       = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['s'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$result = $this->repo->paginate(
			'' !== $search ? [ 'email' => $search ] : [],
			self::PER_PAGE,
			$current_page
		);

		$this->items = $result['rows'];

		$this->set_pagination_args(
			[
				'total_items' => $result['total'],
				'per_page'    => self::PER_PAGE,
				'total_pages' => (int) ceil( $result['total'] / self::PER_PAGE ),
			]
		);

		$this->_column_headers = [ $this->get_columns(), [], [] ];
	}
}
