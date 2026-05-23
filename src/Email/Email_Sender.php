<?php
/**
 * Sends transactional emails by registered type id.
 *
 * @package LEAStudios\EmailTemplates\Email
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Email;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Composes and sends emails for any type registered in Email_Type_Registry.
 */
class Email_Sender {

	/**
	 * Per-request memoized copy of the email-types option array.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private ?array $type_settings_cache = null;

	/**
	 * Whether the option-update invalidation hooks have been registered.
	 *
	 * @var bool
	 */
	private bool $cache_hooks_registered = false;

	/**
	 * Constructor.
	 *
	 * @param Merge_Tag_Replacer  $replacer The merge tag replacer.
	 * @param Email_Type_Registry $registry The type registry.
	 */
	public function __construct(
		private readonly Merge_Tag_Replacer $replacer,
		private readonly Email_Type_Registry $registry,
	) {}

	/**
	 * Send an email of the specified type.
	 *
	 * @param string               $type_id The registered email type id.
	 * @param string               $to      Recipient address.
	 * @param array<string, mixed> $context Merge-tag values.
	 * @return bool Whether wp_mail returned true. Returns false if the id is
	 *              unknown or the type is disabled.
	 */
	public function send( string $type_id, string $to, array $context = [] ): bool {
		$definition = $this->registry->get( $type_id );

		if ( null === $definition ) {
			return false;
		}

		$composed = $this->compose( $type_id, $context );

		if ( null === $composed ) {
			return false;
		}

		$settings = $this->get_type_settings( $type_id );

		if ( ! empty( $settings['recipient_override'] ) && is_email( $settings['recipient_override'] ) ) {
			$to = $settings['recipient_override'];
		}

		/**
		 * Filters the email arguments before sending.
		 *
		 * @param array<string, mixed> $args    The wp_mail arguments.
		 * @param string               $type_id The registered type id.
		 * @param array<string, mixed> $context The merge tag context.
		 */
		$args = (array) apply_filters(
			'leastudios_email_templates_send_args',
			[
				'to'      => $to,
				'subject' => $composed['subject'],
				'message' => $composed['body'],
				'headers' => $composed['headers'],
			],
			$type_id,
			$context
		);

		$result = wp_mail( $args['to'], $args['subject'], $args['message'], $args['headers'] );

		/**
		 * Fires after a transactional email is sent.
		 *
		 * @param string             $type_id The registered type id.
		 * @param string             $to      The recipient.
		 * @param string             $subject The subject line.
		 * @param bool               $result  Whether wp_mail returned true.
		 * @param string             $body    The rendered body that was passed to wp_mail.
		 * @param array<int, string> $headers The headers passed to wp_mail.
		 */
		do_action(
			'leastudios_email_templates_email_sent',
			$type_id,
			$args['to'],
			$args['subject'],
			$result,
			(string) $args['message'],
			(array) $args['headers']
		);

		return $result;
	}

	/**
	 * Compose subject/body/headers without sending.
	 *
	 * @param string               $type_id Registered email type id.
	 * @param array<string, mixed> $context Merge-tag values.
	 * @return array{subject:string, body:string, headers:array<int,string>}|null
	 */
	public function compose( string $type_id, array $context = [] ): ?array {
		$definition = $this->registry->get( $type_id );

		if ( null === $definition ) {
			return null;
		}

		$settings = $this->get_type_settings( $type_id );

		if ( empty( $settings['enabled'] ) ) {
			return null;
		}

		$subject = '' !== $settings['subject'] ? $settings['subject'] : $definition->default_subject();
		$body    = '' !== $settings['body'] ? $settings['body'] : $definition->default_body();

		$subject = $this->replacer->replace_subject( $subject, $context );
		$body    = $this->replacer->replace_html( $body, $context, $definition->escape_map() );

		return [
			'subject' => $subject,
			'body'    => $body,
			'headers' => [ 'Content-Type: text/html; charset=UTF-8' ],
		];
	}

	/**
	 * Get settings for a specific type id. Memoizes the option array.
	 *
	 * @param string $type_id The registered type id.
	 * @return array{enabled: bool, subject: string, body: string, recipient_override: string}
	 */
	private function get_type_settings( string $type_id ): array {
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

		$settings = $this->type_settings_cache[ $type_id ] ?? [];

		return array_merge( $defaults, $settings );
	}
}
