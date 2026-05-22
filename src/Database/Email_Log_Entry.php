<?php
/**
 * Typed value object for one email log row.
 *
 * @package LEAStudios\EmailTemplates\Database
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Database;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Plain immutable bag for log row data — exists so PHPStan can reason
 * about the columns returned by Email_Log_Repository rather than chasing
 * `Access to undefined property object::$status` errors at every consumer.
 */
final class Email_Log_Entry {

	/**
	 * Constructor.
	 *
	 * @param int    $id          Row primary key.
	 * @param string $type        Email type slug (matches an Email_Type case).
	 * @param string $recipient   Recipient email address.
	 * @param string $subject     Subject line.
	 * @param string $body        Rendered body HTML/text.
	 * @param string $headers     Newline-joined headers.
	 * @param string $status      'sent' | 'failed'.
	 * @param string $error       Error string when failed; '' otherwise.
	 * @param string $created_at  MySQL datetime in site timezone.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $type,
		public readonly string $recipient,
		public readonly string $subject,
		public readonly string $body,
		public readonly string $headers,
		public readonly string $status,
		public readonly string $error,
		public readonly string $created_at,
	) {}

	/**
	 * Build an entry from a `$wpdb` row.
	 *
	 * @param object $row stdClass returned by $wpdb->get_row / get_results.
	 * @return self
	 */
	public static function from_row( object $row ): self {
		return new self(
			(int) ( $row->id ?? 0 ),
			(string) ( $row->type ?? '' ),
			(string) ( $row->recipient ?? '' ),
			(string) ( $row->subject ?? '' ),
			(string) ( $row->body ?? '' ),
			(string) ( $row->headers ?? '' ),
			(string) ( $row->status ?? '' ),
			(string) ( $row->error ?? '' ),
			(string) ( $row->created_at ?? '' ),
		);
	}
}
