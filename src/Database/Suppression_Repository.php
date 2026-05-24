<?php
/**
 * Repository for the global per-recipient suppression table.
 *
 * @package LEAStudios\EmailTemplates\Database
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Database;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Wraps the custom `leastudios_email_templates_suppressions` table.
 *
 * One row per opted-out email address. Email is normalized to lowercase at
 * both insert and lookup time; the table's UNIQUE index makes re-suppression
 * idempotent via INSERT … ON DUPLICATE KEY UPDATE.
 */
class Suppression_Repository {

	/**
	 * Bumped when the schema changes so install() knows to re-run dbDelta.
	 */
	private const SCHEMA_VERSION = '1.0.0';

	/**
	 * Option key holding the installed schema version.
	 */
	private const SCHEMA_OPTION = 'leastudios_email_templates_suppressions_schema_version';

	/**
	 * Fully-qualified table name (with prefix).
	 */
	public function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'leastudios_email_templates_suppressions';
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
			email varchar(255) NOT NULL DEFAULT '',
			suppressed_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			source varchar(16) NOT NULL DEFAULT 'link',
			PRIMARY KEY  (id),
			UNIQUE KEY email_unique (email),
			KEY suppressed_at_idx (suppressed_at)
		) {$charset_collate};";

		dbDelta( $sql );

		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, true );
	}

	/**
	 * Normalize an email address the same way at insert and lookup time.
	 *
	 * @param string $email Raw email.
	 * @return string Lowercased, trimmed.
	 */
	private function normalize( string $email ): string {
		return strtolower( trim( $email ) );
	}

	/**
	 * Insert or refresh a suppression row. Idempotent.
	 *
	 * @param string $email  Recipient email.
	 * @param string $source Origin marker ('link' | 'admin' | 'cli').
	 * @return void
	 */
	public function upsert( string $email, string $source ): void {
		global $wpdb;

		$email = $this->normalize( $email );
		$table = $this->table_name();

		// $wpdb->insert doesn't support ON DUPLICATE KEY, so build the
		// statement by hand. Table name uses the %i identifier placeholder
		// and the remaining placeholders cover every user-supplied value.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (email, suppressed_at, source)
				 VALUES (%s, %s, %s)
				 ON DUPLICATE KEY UPDATE suppressed_at = VALUES(suppressed_at), source = VALUES(source)',
				$table,
				$email,
				current_time( 'mysql' ),
				$source
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Whether a suppression row exists for the (normalized) email.
	 *
	 * @param string $email Recipient email.
	 * @return bool
	 */
	public function exists_by_email( string $email ): bool {
		global $wpdb;

		$email = $this->normalize( $email );
		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$id = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE email = %s LIMIT 1', $table, $email ) );

		return null !== $id;
	}

	/**
	 * Remove the row for the (normalized) email, if any.
	 *
	 * @param string $email Recipient email.
	 * @return void
	 */
	public function delete_by_email( string $email ): void {
		global $wpdb;

		$email = $this->normalize( $email );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $this->table_name(), [ 'email' => $email ], [ '%s' ] );
	}

	/**
	 * Look up a single row by id.
	 *
	 * @param int $id Row id.
	 * @return Suppression_Entry|null
	 */
	public function find_by_id( int $id ): ?Suppression_Entry {
		global $wpdb;
		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $id ) );

		return $row instanceof \stdClass ? Suppression_Entry::from_row( $row ) : null;
	}

	/**
	 * Paginated list, newest first.
	 *
	 * @param array{email?:string} $filters  Filter set.
	 * @param int                  $per_page Per-page count.
	 * @param int                  $page     1-based page number.
	 * @return array{rows: array<int, Suppression_Entry>, total: int}
	 */
	public function paginate( array $filters, int $per_page, int $page ): array {
		global $wpdb;
		$table  = $this->table_name();
		$offset = max( 0, ( $page - 1 ) * $per_page );

		$email_filter = ! empty( $filters['email'] )
			? '%' . $wpdb->esc_like( $this->normalize( $filters['email'] ) ) . '%'
			: null;

		// Each filter combination is enumerated as a fully-static prepare() format string so the
		// WHERE fragment is never interpolated, satisfying Plugin Check's stricter DB-interpolation sniff.
		if ( null !== $email_filter ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$total = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE email LIKE %s',
					$table,
					$email_filter
				)
			);
			$rows  = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE email LIKE %s ORDER BY suppressed_at DESC, id DESC LIMIT %d OFFSET %d',
					$table,
					$email_filter,
					$per_page,
					$offset
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		} else {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$total = (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table )
			);
			$rows  = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i ORDER BY suppressed_at DESC, id DESC LIMIT %d OFFSET %d',
					$table,
					$per_page,
					$offset
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		$entries = [];
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( $row instanceof \stdClass ) {
					$entries[] = Suppression_Entry::from_row( $row );
				}
			}
		}

		return [
			'rows'  => $entries,
			'total' => $total,
		];
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
