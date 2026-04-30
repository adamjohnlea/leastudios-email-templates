<?php
/**
 * Sends transactional emails by type.
 *
 * @package LEAStudios\EmailTemplates\Email
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Email;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Composes and sends emails for a given Email_Type.
 */
class Email_Sender {

	/**
	 * Per-request memoized copy of the email-types option array, or null
	 * when not yet populated / freshly invalidated.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private ?array $type_settings_cache = null;

	/**
	 * Whether we've already registered the option-update invalidation hooks.
	 *
	 * @var bool
	 */
	private bool $cache_hooks_registered = false;

	/**
	 * Constructor.
	 *
	 * @param Merge_Tag_Replacer $replacer The merge tag replacer.
	 */
	public function __construct(
		private readonly Merge_Tag_Replacer $replacer,
	) {}

	/**
	 * Send an email of the specified type.
	 *
	 * @param Email_Type           $type    The email type to send.
	 * @param string               $to      The recipient email address.
	 * @param array<string, mixed> $context The merge tag values.
	 * @return bool Whether the email was sent successfully.
	 */
	public function send( Email_Type $type, string $to, array $context = [] ): bool {
		$settings = $this->get_type_settings( $type );

		if ( empty( $settings['enabled'] ) ) {
			return false;
		}

		$subject = '' !== $settings['subject'] ? $settings['subject'] : $type->default_subject();
		$body    = '' !== $settings['body'] ? $settings['body'] : $type->default_body();

		// Replace merge tags. Subject strips CR/LF; body escapes for HTML.
		$subject = $this->replacer->replace_subject( $subject, $context );
		$body    = $this->replacer->replace_html( $body, $context );

		// Allow recipient override.
		if ( ! empty( $settings['recipient_override'] ) && is_email( $settings['recipient_override'] ) ) {
			$to = $settings['recipient_override'];
		}

		$headers = [ 'Content-Type: text/html; charset=UTF-8' ];

		/**
		 * Filters the email arguments before sending.
		 *
		 * @param array      $args    The wp_mail arguments.
		 * @param Email_Type $type    The email type.
		 * @param array      $context The merge tag context.
		 */
		$args = (array) apply_filters(
			'leastudios_email_templates_send_args',
			[
				'to'      => $to,
				'subject' => $subject,
				'message' => $body,
				'headers' => $headers,
			],
			$type,
			$context
		);

		$result = wp_mail( $args['to'], $args['subject'], $args['message'], $args['headers'] );

		/**
		 * Fires after a transactional email is sent.
		 *
		 * @param Email_Type $type    The email type.
		 * @param string     $to      The recipient.
		 * @param string     $subject The subject line.
		 * @param bool       $result  Whether wp_mail returned true.
		 */
		do_action( 'leastudios_email_templates_email_sent', $type, $args['to'], $args['subject'], $result );

		return $result;
	}

	/**
	 * Get settings for a specific email type.
	 *
	 * Memoizes the option read for the lifetime of the request so a batch
	 * of emails sent in one PHP process (e.g. bulk-refund webhooks) does not
	 * re-query the options table once per send. WordPress's options cache
	 * already keeps the value warm, but this skips a function-call layer
	 * and the array_key resolution per call too. Hook
	 * `update_option_leastudios_email_templates_emails` to bust mid-request
	 * if a settings save lands during the same PHP process.
	 *
	 * @param Email_Type $type The email type.
	 * @return array{enabled: bool, subject: string, body: string, recipient_override: string}
	 */
	private function get_type_settings( Email_Type $type ): array {
		if ( ! $this->cache_hooks_registered ) {
			$this->cache_hooks_registered = true;
			$invalidate                   = function (): void {
				$this->type_settings_cache = null;
			};
			add_action( 'update_option_leastudios_email_templates_emails', $invalidate );
			add_action( 'add_option_leastudios_email_templates_emails', $invalidate );
			add_action( 'delete_option_leastudios_email_templates_emails', $invalidate );
		}

		if ( null === $this->type_settings_cache ) {
			$this->type_settings_cache = (array) get_option( 'leastudios_email_templates_emails', [] );
		}

		$defaults = [
			'enabled'            => true,
			'subject'            => '',
			'body'               => '',
			'recipient_override' => '',
		];

		$settings = $this->type_settings_cache[ $type->value ] ?? [];

		return array_merge( $defaults, $settings );
	}
}
