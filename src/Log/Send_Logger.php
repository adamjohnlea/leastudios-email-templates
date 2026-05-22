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
use LEAStudios\EmailTemplates\Email\Email_Type;

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
		add_action( 'leastudios_email_templates_email_sent', [ $this, 'record' ], 10, 6 );
	}

	/**
	 * Record a single send.
	 *
	 * @param Email_Type         $type    The email type.
	 * @param string             $to      The recipient.
	 * @param string             $subject The subject line.
	 * @param bool               $result  Whether wp_mail returned true.
	 * @param string             $body    The rendered body that was sent.
	 * @param array<int, string> $headers Headers that were sent.
	 * @return void
	 */
	public function record( Email_Type $type, string $to, string $subject, bool $result, string $body = '', array $headers = [] ): void {
		$this->repo->create(
			[
				'type'      => $type->value,
				'recipient' => $to,
				'subject'   => $subject,
				'body'      => $body,
				'headers'   => implode( "\n", $headers ),
				'status'    => $result ? 'sent' : 'failed',
				'error'     => null,
			]
		);
	}
}
