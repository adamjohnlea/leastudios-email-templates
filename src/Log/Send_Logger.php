<?php
/**
 * Records every transactional send to the email_log table.
 *
 * @package LEAStudios\EmailTemplates\Log
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Log;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use LEAStudios\EmailTemplates\Database\Email_Log_Repository;

/**
 * Subscribes to the `leastudios_email_templates_email_sent` action and
 * writes one row per send to the log table.
 *
 * Kept separate from Email_Sender so the sender stays single-purpose and
 * so logging can be disabled / replaced by detaching the hook.
 */
class Send_Logger {

	/**
	 * Constructor.
	 *
	 * @param Email_Log_Repository $repo The log repository.
	 */
	public function __construct(
		private readonly Email_Log_Repository $repo,
	) {}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'leastudios_email_templates_email_sent', [ $this, 'record' ], 10, 7 );
		add_action( 'leastudios_email_templates_email_suppressed', [ $this, 'record_suppressed' ], 10, 6 );
	}

	/**
	 * Record a single send.
	 *
	 * @param string             $type_id The registered email type id.
	 * @param string             $to      The recipient.
	 * @param string             $subject The subject line.
	 * @param bool               $result  Whether wp_mail returned true.
	 * @param string             $body    The rendered body that was sent.
	 * @param array<int, string> $headers Headers that were sent.
	 * @param string             $source  Send-origin marker: 'web' or 'cli-test'.
	 * @return void
	 */
	public function record( string $type_id, string $to, string $subject, bool $result, string $body = '', array $headers = [], string $source = 'web' ): void {
		$this->repo->create(
			[
				'type'      => $type_id,
				'recipient' => $to,
				'subject'   => $subject,
				'body'      => $body,
				'headers'   => implode( "\n", $headers ),
				'status'    => $result ? 'sent' : 'failed',
				'error'     => null,
				'source'    => $source,
			]
		);
	}

	/**
	 * Record a send that was skipped because the recipient is suppressed.
	 *
	 * Body/headers reflect what would have been sent (including the
	 * auto-appended unsubscribe footer) so the row is a faithful audit
	 * trail of the would-have-been delivery.
	 *
	 * @param string             $type_id The registered email type id.
	 * @param string             $to      The intended recipient.
	 * @param string             $subject The composed subject line.
	 * @param string             $body    The composed body (with footer).
	 * @param array<int, string> $headers The composed headers.
	 * @param string             $source  Send-origin marker.
	 * @return void
	 */
	public function record_suppressed( string $type_id, string $to, string $subject, string $body, array $headers, string $source ): void {
		$this->repo->create(
			[
				'type'      => $type_id,
				'recipient' => $to,
				'subject'   => $subject,
				'body'      => $body,
				'headers'   => implode( "\n", $headers ),
				'status'    => 'suppressed',
				'error'     => null,
				'source'    => $source,
			]
		);
	}
}
