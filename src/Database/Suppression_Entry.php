<?php
/**
 * Value object for a row in the suppressions table.
 *
 * @package LEAStudios\EmailTemplates\Database
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Database;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Immutable, typed view of a row in `wp_leastudios_email_templates_suppressions`.
 *
 * Mirrors the shape of Email_Log_Entry so list-table consumers can read
 * properties directly without an array_key_exists dance.
 */
final class Suppression_Entry {

	/**
	 * Constructor.
	 *
	 * @param int    $id            Row id.
	 * @param string $email         Suppressed email (already lowercased).
	 * @param string $suppressed_at MySQL datetime string in UTC.
	 * @param string $source        Origin marker: 'link' | 'admin' | 'cli'.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $email,
		public readonly string $suppressed_at,
		public readonly string $source,
	) {}

	/**
	 * Build an entry from a $wpdb stdClass row.
	 *
	 * @param \stdClass $row One row from $wpdb->get_results().
	 * @return self
	 */
	public static function from_row( \stdClass $row ): self {
		return new self(
			(int) ( $row->id ?? 0 ),
			(string) ( $row->email ?? '' ),
			(string) ( $row->suppressed_at ?? '' ),
			(string) ( $row->source ?? 'link' ),
		);
	}
}
