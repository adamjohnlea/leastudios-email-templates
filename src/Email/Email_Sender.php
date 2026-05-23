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

use LEAStudios\EmailTemplates\Subscription\Unsubscribe_Manager;

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
	 * @param Unsubscribe_Manager $manager  The unsubscribe / suppression manager.
	 */
	public function __construct(
		private readonly Merge_Tag_Replacer $replacer,
		private readonly Email_Type_Registry $registry,
		private readonly Unsubscribe_Manager $manager,
	) {}

	/**
	 * Send an email of the specified type.
	 *
	 * @param string               $type_id The registered email type id.
	 * @param string               $to      Recipient address.
	 * @param array<string, mixed> $context Merge-tag values.
	 * @param string               $source  Send-origin marker for the log table.
	 *                                      `'web'` (default) for admin AJAX sends,
	 *                                      `'cli-test'` for `wp leastudios-email-templates send-test`.
	 * @return bool Whether wp_mail returned true. Returns false if the id is
	 *              unknown or the type is disabled.
	 */
	public function send( string $type_id, string $to, array $context = [], string $source = 'web' ): bool {
		$definition = $this->registry->get( $type_id );

		if ( null === $definition ) {
			return false;
		}

		// Phase 9 — suppression gate. Required types bypass.
		if ( ! $definition->is_transactional_required() && '' !== $to && $this->manager->is_suppressed( $to ) ) {
			return $this->fire_suppressed( $type_id, $to, $context, $source );
		}

		$composed = $this->compose( $type_id, $context, $to );

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

		// Phase 9 — auto-append the unsubscribe footer for non-required types
		// with a real recipient. Required types and empty recipients skip
		// this; the wrapper (Template_Wrapper) downstream stays type-ignorant.
		if ( ! $definition->is_transactional_required() && '' !== $to && is_email( $to ) ) {
			$args['message'] .= $this->render_unsubscribe_footer( $to, $type_id );
		}

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
		 * @param string             $source  Send-origin marker: 'web' or 'cli-test'.
		 */
		do_action(
			'leastudios_email_templates_email_sent',
			$type_id,
			$args['to'],
			$args['subject'],
			$result,
			(string) $args['message'],
			(array) $args['headers'],
			$source
		);

		return $result;
	}

	/**
	 * Compose subject/body/headers without sending.
	 *
	 * Returns null when the type id is unregistered or the type is disabled.
	 * Recipient is not part of the composed output — subject/body/headers
	 * don't depend on it — so previews and settings-page AJAX can call this
	 * without a real To address.
	 *
	 * @param string               $type_id Registered email type id.
	 * @param array<string, mixed> $context Merge-tag values.
	 * @param string               $to      Recipient address. Used to inject
	 *                                      the `{unsubscribe_url}` merge tag —
	 *                                      empty for required types or empty/invalid
	 *                                      recipients, a real signed URL otherwise.
	 * @return array{subject:string, body:string, headers:array<int,string>}|null
	 */
	public function compose( string $type_id, array $context = [], string $to = '' ): ?array {
		$definition = $this->registry->get( $type_id );

		if ( null === $definition ) {
			return null;
		}

		$settings = $this->get_type_settings( $type_id );

		if ( empty( $settings['enabled'] ) ) {
			return null;
		}

		// Phase 9 — inject the recipient-aware near-global tag. Required types
		// and empty recipients get an empty string; the filter lets sites
		// rewrite the URL (e.g., route through a CDN).
		// Our recipient-aware resolution always wins over caller-supplied
		// $context values. The documented override surface is the
		// leastudios_email_templates_unsubscribe_url filter, not $context.
		$context = array_merge(
			$context,
			[ 'unsubscribe_url' => $this->resolve_unsubscribe_url( $to, $definition ) ]
		);

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
	 * Resolve the value of the `{unsubscribe_url}` merge tag for a send.
	 *
	 * @param string                $to         Recipient address.
	 * @param Email_Type_Definition $definition The type being composed.
	 * @return string Empty string for required types or empty/invalid
	 *                recipients; otherwise the signed unsubscribe URL.
	 */
	private function resolve_unsubscribe_url( string $to, Email_Type_Definition $definition ): string {
		if ( $definition->is_transactional_required() || '' === $to || ! is_email( $to ) ) {
			return '';
		}

		$url = $this->manager->url_for( $to );

		/**
		 * Filters the rendered unsubscribe URL.
		 *
		 * @param string $url     The default URL ('/wp-json/.../v1/unsubscribe?token=…').
		 * @param string $email   Recipient.
		 * @param string $type_id Type id being composed.
		 */
		return (string) apply_filters( 'leastudios_email_templates_unsubscribe_url', $url, $to, $definition->id() );
	}

	/**
	 * Get settings for a specific type id.
	 *
	 * Memoizes the option read for the lifetime of the request so a batch
	 * of emails sent in one PHP process (e.g. bulk-refund webhooks) does not
	 * re-query the options table once per send. WordPress's options cache
	 * already keeps the value warm, but this skips a function-call layer
	 * and the array_key resolution per call too. Hook
	 * `update_option_leastudios_email_templates_emails` to bust mid-request
	 * if a settings save lands during the same PHP process.
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

	/**
	 * Default unsubscribe footer HTML for non-required types. Filterable.
	 *
	 * Visually distinct from the type's body content — small, muted, with
	 * the URL pre-resolved (NOT a merge tag, so it's safe even if a
	 * third-party type strips its context).
	 *
	 * @param string $to      Recipient.
	 * @param string $type_id Registered type id.
	 * @return string HTML to append to the body.
	 */
	private function render_unsubscribe_footer( string $to, string $type_id ): string {
		$url = $this->manager->url_for( $to );

		// translators: 1: opening <a> tag with href, 2: closing </a>.
		$template = esc_html__( 'Don\'t want to receive these emails? %1$sUnsubscribe%2$s.', 'leastudios-email-templates' );
		$copy     = sprintf(
			$template,
			'<a href="' . esc_url( $url ) . '" style="color:#6b7280;">',
			'</a>'
		);

		$default = '<hr style="border:none;border-top:1px solid #e5e7eb;margin:24px 0 12px;"><p style="color:#6b7280;font-size:12px;line-height:1.5;margin:0;">' . $copy . '</p>';

		/**
		 * Filters the unsubscribe footer HTML auto-appended to non-required emails.
		 *
		 * @param string $default Default footer markup.
		 * @param string $to      Recipient.
		 * @param string $type_id Registered type id.
		 */
		return (string) apply_filters( 'leastudios_email_templates_unsubscribe_footer_html', $default, $to, $type_id );
	}

	/**
	 * Compose the would-have-been email, fire the suppressed action, and
	 * return false. The composed body intentionally includes both the
	 * `{unsubscribe_url}` substitution (resolved by compose) and the
	 * auto-appended footer — so the log row is byte-identical to what
	 * would have been sent.
	 *
	 * @param string               $type_id Registered type id.
	 * @param string               $to      Recipient address.
	 * @param array<string, mixed> $context Merge-tag values.
	 * @param string               $source  Send-origin marker.
	 * @return bool Always false.
	 */
	private function fire_suppressed( string $type_id, string $to, array $context, string $source ): bool {
		$composed = $this->compose( $type_id, $context, $to );

		if ( null === $composed ) {
			// Type is disabled in settings; still fire so callers can observe.
			$composed = [
				'subject' => '',
				'body'    => '',
				'headers' => [ 'Content-Type: text/html; charset=UTF-8' ],
			];
		} else {
			$composed['body'] .= $this->render_unsubscribe_footer( $to, $type_id );
		}

		/**
		 * Fires when a send is gated by an active suppression. The body and
		 * headers reflect what would have been sent (including the auto-
		 * appended footer), so the log row is a faithful audit trail.
		 *
		 * @param string             $type_id The registered type id.
		 * @param string             $to      The recipient.
		 * @param string             $subject The composed subject.
		 * @param string             $body    The composed body (with footer).
		 * @param array<int, string> $headers The composed headers.
		 * @param string             $source  Send-origin marker.
		 */
		do_action(
			'leastudios_email_templates_email_suppressed',
			$type_id,
			$to,
			(string) $composed['subject'],
			(string) $composed['body'],
			(array) $composed['headers'],
			$source
		);

		return false;
	}
}
