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
	private const SCHEMA_VERSION = '1.1.0';

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
			source varchar(16) NOT NULL DEFAULT 'web',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY type_idx (type),
			KEY status_idx (status),
			KEY source_idx (source),
			KEY created_at_idx (created_at)
		) {$charset_collate};";

		dbDelta( $sql );

		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
	}

	/**
	 * Insert one row.
	 *
	 * @param array{type:string,recipient:string,subject:string,body:string,headers:string,status:string,error:?string,source?:string} $data Row data.
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
				'source'     => $data['source'] ?? 'web',
				'created_at' => current_time( 'mysql' ),
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $id ) );

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
		$table  = $this->table_name();
		$offset = max( 0, ( $page - 1 ) * $per_page );

		$type   = ! empty( $filters['type'] ) ? (string) $filters['type'] : null;
		$status = ! empty( $filters['status'] ) ? (string) $filters['status'] : null;
		$since  = ! empty( $filters['since'] ) ? (string) $filters['since'] : null;
		$until  = ! empty( $filters['until'] ) ? (string) $filters['until'] : null;

		// Each of the 16 filter combinations is enumerated as a fully-static prepare() format string so
		// no WHERE fragment is ever interpolated, satisfying Plugin Check's stricter DB-interpolation sniff.
		// The bitmask is composed in fixed order (type, status, since, until) for readability of the cases.
		$mask = ( null !== $type ? 8 : 0 )
			| ( null !== $status ? 4 : 0 )
			| ( null !== $since ? 2 : 0 )
			| ( null !== $until ? 1 : 0 );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		switch ( $mask ) {
			case 0: // no filters.
				$total = (int) $wpdb->get_var(
					$wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table )
				);
				$rows  = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT * FROM %i ORDER BY id DESC LIMIT %d OFFSET %d',
						$table,
						$per_page,
						$offset
					)
				);
				break;

			case 1: // until.
				$total = (int) $wpdb->get_var(
					$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE created_at <= %s', $table, $until )
				);
				$rows  = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT * FROM %i WHERE created_at <= %s ORDER BY id DESC LIMIT %d OFFSET %d',
						$table,
						$until,
						$per_page,
						$offset
					)
				);
				break;

			case 2: // since.
				$total = (int) $wpdb->get_var(
					$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE created_at >= %s', $table, $since )
				);
				$rows  = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT * FROM %i WHERE created_at >= %s ORDER BY id DESC LIMIT %d OFFSET %d',
						$table,
						$since,
						$per_page,
						$offset
					)
				);
				break;

			case 3: // since + until.
				$total = (int) $wpdb->get_var(
					$wpdb->prepare(
						'SELECT COUNT(*) FROM %i WHERE created_at >= %s AND created_at <= %s',
						$table,
						$since,
						$until
					)
				);
				$rows  = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT * FROM %i WHERE created_at >= %s AND created_at <= %s ORDER BY id DESC LIMIT %d OFFSET %d',
						$table,
						$since,
						$until,
						$per_page,
						$offset
					)
				);
				break;

			case 4: // status.
				$total = (int) $wpdb->get_var(
					$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %s', $table, $status )
				);
				$rows  = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT * FROM %i WHERE status = %s ORDER BY id DESC LIMIT %d OFFSET %d',
						$table,
						$status,
						$per_page,
						$offset
					)
				);
				break;

			case 5: // status + until.
				$total = (int) $wpdb->get_var(
					$wpdb->prepare(
						'SELECT COUNT(*) FROM %i WHERE status = %s AND created_at <= %s',
						$table,
						$status,
						$until
					)
				);
				$rows  = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT * FROM %i WHERE status = %s AND created_at <= %s ORDER BY id DESC LIMIT %d OFFSET %d',
						$table,
						$status,
						$until,
						$per_page,
						$offset
					)
				);
				break;

			case 6: // status + since.
				$total = (int) $wpdb->get_var(
					$wpdb->prepare(
						'SELECT COUNT(*) FROM %i WHERE status = %s AND created_at >= %s',
						$table,
						$status,
						$since
					)
				);
				$rows  = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT * FROM %i WHERE status = %s AND created_at >= %s ORDER BY id DESC LIMIT %d OFFSET %d',
						$table,
						$status,
						$since,
						$per_page,
						$offset
					)
				);
				break;

			case 7: // status + since + until.
				$total = (int) $wpdb->get_var(
					$wpdb->prepare(
						'SELECT COUNT(*) FROM %i WHERE status = %s AND created_at >= %s AND created_at <= %s',
						$table,
						$status,
						$since,
						$until
					)
				);
				$rows  = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT * FROM %i WHERE status = %s AND created_at >= %s AND created_at <= %s ORDER BY id DESC LIMIT %d OFFSET %d',
						$table,
						$status,
						$since,
						$until,
						$per_page,
						$offset
					)
				);
				break;

			case 8: // type.
				$total = (int) $wpdb->get_var(
					$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE type = %s', $table, $type )
				);
				$rows  = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT * FROM %i WHERE type = %s ORDER BY id DESC LIMIT %d OFFSET %d',
						$table,
						$type,
						$per_page,
						$offset
					)
				);
				break;

			case 9: // type + until.
				$total = (int) $wpdb->get_var(
					$wpdb->prepare(
						'SELECT COUNT(*) FROM %i WHERE type = %s AND created_at <= %s',
						$table,
						$type,
						$until
					)
				);
				$rows  = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT * FROM %i WHERE type = %s AND created_at <= %s ORDER BY id DESC LIMIT %d OFFSET %d',
						$table,
						$type,
						$until,
						$per_page,
						$offset
					)
				);
				break;

			case 10: // type + since.
				$total = (int) $wpdb->get_var(
					$wpdb->prepare(
						'SELECT COUNT(*) FROM %i WHERE type = %s AND created_at >= %s',
						$table,
						$type,
						$since
					)
				);
				$rows  = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT * FROM %i WHERE type = %s AND created_at >= %s ORDER BY id DESC LIMIT %d OFFSET %d',
						$table,
						$type,
						$since,
						$per_page,
						$offset
					)
				);
				break;

			case 11: // type + since + until.
				$total = (int) $wpdb->get_var(
					$wpdb->prepare(
						'SELECT COUNT(*) FROM %i WHERE type = %s AND created_at >= %s AND created_at <= %s',
						$table,
						$type,
						$since,
						$until
					)
				);
				$rows  = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT * FROM %i WHERE type = %s AND created_at >= %s AND created_at <= %s ORDER BY id DESC LIMIT %d OFFSET %d',
						$table,
						$type,
						$since,
						$until,
						$per_page,
						$offset
					)
				);
				break;

			case 12: // type + status.
				$total = (int) $wpdb->get_var(
					$wpdb->prepare(
						'SELECT COUNT(*) FROM %i WHERE type = %s AND status = %s',
						$table,
						$type,
						$status
					)
				);
				$rows  = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT * FROM %i WHERE type = %s AND status = %s ORDER BY id DESC LIMIT %d OFFSET %d',
						$table,
						$type,
						$status,
						$per_page,
						$offset
					)
				);
				break;

			case 13: // type + status + until.
				$total = (int) $wpdb->get_var(
					$wpdb->prepare(
						'SELECT COUNT(*) FROM %i WHERE type = %s AND status = %s AND created_at <= %s',
						$table,
						$type,
						$status,
						$until
					)
				);
				$rows  = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT * FROM %i WHERE type = %s AND status = %s AND created_at <= %s ORDER BY id DESC LIMIT %d OFFSET %d',
						$table,
						$type,
						$status,
						$until,
						$per_page,
						$offset
					)
				);
				break;

			case 14: // type + status + since.
				$total = (int) $wpdb->get_var(
					$wpdb->prepare(
						'SELECT COUNT(*) FROM %i WHERE type = %s AND status = %s AND created_at >= %s',
						$table,
						$type,
						$status,
						$since
					)
				);
				$rows  = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT * FROM %i WHERE type = %s AND status = %s AND created_at >= %s ORDER BY id DESC LIMIT %d OFFSET %d',
						$table,
						$type,
						$status,
						$since,
						$per_page,
						$offset
					)
				);
				break;

			default: // 15: type + status + since + until.
				$total = (int) $wpdb->get_var(
					$wpdb->prepare(
						'SELECT COUNT(*) FROM %i WHERE type = %s AND status = %s AND created_at >= %s AND created_at <= %s',
						$table,
						$type,
						$status,
						$since,
						$until
					)
				);
				$rows  = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT * FROM %i WHERE type = %s AND status = %s AND created_at >= %s AND created_at <= %s ORDER BY id DESC LIMIT %d OFFSET %d',
						$table,
						$type,
						$status,
						$since,
						$until,
						$per_page,
						$offset
					)
				);
				break;
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

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

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->query(
			$wpdb->prepare( 'DELETE FROM %i WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)', $table, $days )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $table ) );
	}

	/**
	 * Drop the table (used on uninstall).
	 *
	 * @return void
	 */
	public function drop(): void {
		global $wpdb;
		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		delete_option( self::SCHEMA_OPTION );
	}
}
