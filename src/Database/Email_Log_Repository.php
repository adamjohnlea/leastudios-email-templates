<?php
/**
 * Repository for the email send log.
 *
 * @package LEAStudios\EmailTemplates\Database
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Database;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Wraps the custom `leastudios_email_templates_log` table.
 *
 * Plain `$wpdb` access — no ORM, no object cache — because the read patterns
 * (paginated admin list, prune by date) are already well-served by the
 * MySQL indexes defined in install().
 */
class Email_Log_Repository {

	/**
	 * Bumped when the schema changes so install() knows to re-run dbDelta.
	 */
	private const SCHEMA_VERSION = '1.0.0';

	/**
	 * Option key holding the installed schema version.
	 */
	private const SCHEMA_OPTION = 'leastudios_email_templates_log_schema_version';

	/**
	 * Fully-qualified table name (with prefix).
	 */
	public function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'leastudios_email_templates_log';
	}

	/**
	 * Create or upgrade the table. Safe to call repeatedly — dbDelta is
	 * idempotent and the option short-circuits the no-op case.
	 *
	 * @return void
	 */
	public function install(): void {
		if ( get_option( self::SCHEMA_OPTION ) === self::SCHEMA_VERSION ) {
			return;
		}

		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = $this->table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			type varchar(64) NOT NULL DEFAULT '',
			recipient varchar(255) NOT NULL DEFAULT '',
			subject text NOT NULL,
			body longtext NOT NULL,
			headers longtext NOT NULL,
			status varchar(16) NOT NULL DEFAULT 'sent',
			error text NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY type_idx (type),
			KEY status_idx (status),
			KEY created_at_idx (created_at)
		) {$charset_collate};";

		dbDelta( $sql );

		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
	}

	/**
	 * Insert one row.
	 *
	 * @param array{type:string,recipient:string,subject:string,body:string,headers:string,status:string,error:?string} $data Row data.
	 * @return int Inserted ID, or 0 on failure.
	 */
	public function create( array $data ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = $wpdb->insert(
			$this->table_name(),
			[
				'type'       => $data['type'],
				'recipient'  => $data['recipient'],
				'subject'    => $data['subject'],
				'body'       => $data['body'],
				'headers'    => $data['headers'],
				'status'     => $data['status'],
				'error'      => $data['error'],
				'created_at' => current_time( 'mysql' ),
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		return false === $ok ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * Fetch one row by id.
	 *
	 * @param int $id Row ID.
	 * @return Email_Log_Entry|null
	 */
	public function get( int $id ): ?Email_Log_Entry {
		global $wpdb;
		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );

		return $row instanceof \stdClass ? Email_Log_Entry::from_row( $row ) : null;
	}

	/**
	 * Paginated list.
	 *
	 * @param array{type?:string, status?:string, since?:string, until?:string} $filters  Filter set.
	 * @param int                                                               $per_page Per-page count.
	 * @param int                                                               $page     1-based page number.
	 * @return array{rows: array<int, Email_Log_Entry>, total: int}
	 */
	public function paginate( array $filters, int $per_page, int $page ): array {
		global $wpdb;
		$table = $this->table_name();
		$where = [ '1=1' ];
		$args  = [];

		if ( ! empty( $filters['type'] ) ) {
			$where[] = 'type = %s';
			$args[]  = $filters['type'];
		}
		if ( ! empty( $filters['status'] ) ) {
			$where[] = 'status = %s';
			$args[]  = $filters['status'];
		}
		if ( ! empty( $filters['since'] ) ) {
			$where[] = 'created_at >= %s';
			$args[]  = $filters['since'];
		}
		if ( ! empty( $filters['until'] ) ) {
			$where[] = 'created_at <= %s';
			$args[]  = $filters['until'];
		}

		$where_sql = implode( ' AND ', $where );
		$offset    = max( 0, ( $page - 1 ) * $per_page );

		// Table name and WHERE clause are built from internal vocabulary
		// (constant filter keys mapping to '%s' placeholders), so the SQL
		// remains parameterised even though the WHERE clause itself is
		// interpolated. Table name is the wpdb prefix + a fixed string.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		$sql_args = array_merge( $args, [ $per_page, $offset ] );

		if ( empty( $args ) ) {
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}" );
		} else {
			$total = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $args )
			);
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d", $sql_args )
		);

		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		$entries = [];
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( $row instanceof \stdClass ) {
					$entries[] = Email_Log_Entry::from_row( $row );
				}
			}
		}

		return [
			'rows'  => $entries,
			'total' => $total,
		];
	}

	/**
	 * Delete rows older than N days.
	 *
	 * @param int $days Retention window.
	 * @return int Number of rows deleted.
	 */
	public function prune_older_than( int $days ): int {
		global $wpdb;
		$table = $this->table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$count = $wpdb->query(
			$wpdb->prepare( "DELETE FROM {$table} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)", $days )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		return false === $count ? 0 : (int) $count;
	}

	/**
	 * Truncate the table. Used by tests; not exposed elsewhere.
	 *
	 * @return void
	 */
	public function delete_all(): void {
		global $wpdb;
		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "TRUNCATE TABLE {$table}" );
	}

	/**
	 * Drop the table (used on uninstall).
	 *
	 * @return void
	 */
	public function drop(): void {
		global $wpdb;
		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		delete_option( self::SCHEMA_OPTION );
	}
}
