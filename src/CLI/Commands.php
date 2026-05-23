<?php
/**
 * WP-CLI commands for leastudios-email-templates.
 *
 * @package LEAStudios\EmailTemplates\CLI
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\CLI;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use LEAStudios\EmailTemplates\Email\Email_Sender;
use LEAStudios\EmailTemplates\Email\Email_Type_Registry;
use LEAStudios\EmailTemplates\Email\Merge_Tag_Replacer;
use LEAStudios\EmailTemplates\Email\Template_Wrapper;

/**
 * Manage and inspect leastudios-email-templates from the command line.
 */
class Commands {

	/**
	 * Built-in definition fully-qualified class names. Anything not in this
	 * list is reported as a third-party registration by `list-types`.
	 *
	 * @var array<int, class-string>
	 */
	private const BUILT_IN_CLASSES = [
		\LEAStudios\EmailTemplates\Email\Built_In\Payment_Receipt::class,
		\LEAStudios\EmailTemplates\Email\Built_In\Subscription_Created::class,
		\LEAStudios\EmailTemplates\Email\Built_In\Subscription_Renewed::class,
		\LEAStudios\EmailTemplates\Email\Built_In\Payment_Failed::class,
		\LEAStudios\EmailTemplates\Email\Built_In\Refund_Processed::class,
	];

	/**
	 * Constructor.
	 *
	 * @param Email_Type_Registry $registry Type registry.
	 * @param Email_Sender        $sender   Email sender.
	 * @param Merge_Tag_Replacer  $replacer Merge-tag replacer (used by preview).
	 */
	public function __construct(
		private readonly Email_Type_Registry $registry,
		private readonly Email_Sender $sender,
		private readonly Merge_Tag_Replacer $replacer,
	) {}

	/**
	 * List all registered transactional email types.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 *   - count
	 *   - ids
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp leastudios-email-templates list-types
	 *     wp leastudios-email-templates list-types --format=json
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function list_types( array $args, array $assoc_args ): void {
		$format = $assoc_args['format'] ?? 'table';
		$rows   = $this->build_type_rows();

		\WP_CLI\Utils\format_items(
			$format,
			$rows,
			[ 'id', 'label', 'transactional_required', 'source' ]
		);
	}

	/**
	 * Return one row per registered type — used by `list-types` and by tests.
	 *
	 * Extracted as a public method so the data shape can be asserted without
	 * mocking WP_CLI output.
	 *
	 * @return array<int, array{id:string,label:string,transactional_required:string,source:string}>
	 */
	public function build_type_rows(): array {
		$rows = [];

		foreach ( $this->registry->all() as $id => $definition ) {
			$rows[] = [
				'id'                     => $id,
				'label'                  => $definition->label(),
				'transactional_required' => $definition->is_transactional_required() ? 'yes' : 'no',
				'source'                 => in_array( get_class( $definition ), self::BUILT_IN_CLASSES, true ) ? 'built-in' : 'third-party',
			];
		}

		return $rows;
	}

	/**
	 * Render a preview of the given email type to stdout.
	 *
	 * Subject is printed first as `Subject: <...>`, followed by a blank line and
	 * the wrapped HTML body — pipeable to a file. Use `--subject` to print only
	 * the subject (useful for subject merge-tag testing).
	 *
	 * ## OPTIONS
	 *
	 * <type>
	 * : The registered email type id (e.g. `payment_receipt`). Run
	 * : `wp leastudios-email-templates list-types` to see all ids.
	 *
	 * [--context=<json>]
	 * : JSON-encoded merge-tag context overrides. Keys are unbraced tag names.
	 *
	 * [--subject]
	 * : Print only the rendered subject.
	 *
	 * ## EXAMPLES
	 *
	 *     wp leastudios-email-templates preview payment_receipt
	 *     wp leastudios-email-templates preview payment_receipt --subject
	 *     wp leastudios-email-templates preview payment_receipt --context='{"customer_name":"Ada"}' > out.html
	 *
	 * @param array<int, string>    $args       Positional arguments: [0] => type id.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function preview( array $args, array $assoc_args ): void {
		$type_id          = (string) ( $args[0] ?? '' );
		$context_json     = $assoc_args['context'] ?? null;
		$subject_only     = isset( $assoc_args['subject'] );
		$context_override = null;

		if ( null !== $context_json ) {
			$decoded = json_decode( (string) $context_json, true );
			if ( ! is_array( $decoded ) ) {
				\WP_CLI::error( '--context must be a JSON object.' );
				return;
			}
			$context_override = $decoded;
		}

		$output = $this->render_preview( $type_id, $context_override, $subject_only );

		\WP_CLI::log( 'Subject: ' . $output['subject'] );

		if ( ! $subject_only ) {
			\WP_CLI::log( '' );
			\WP_CLI::log( $output['body'] );
		}
	}

	/**
	 * Compose the preview output for the given type. Pure-data helper that lets
	 * tests assert the rendered output without mocking the WP_CLI logger.
	 *
	 * @param string                     $type_id          The registered email type id.
	 * @param array<string, string>|null $context_override Optional context overrides; merged over the definition's sample_context.
	 * @param bool                       $subject_only     When true, the returned body is the empty string.
	 * @return array{subject:string, body:string}
	 */
	public function render_preview( string $type_id, ?array $context_override, bool $subject_only ): array {
		$definition = $this->registry->get( $type_id );

		if ( null === $definition ) {
			\WP_CLI::error( sprintf( 'Unknown email type: %s', $type_id ) );
			// WP_CLI::error throws in tests via the stub; in production it exits.
			return [ 'subject' => '', 'body' => '' ];
		}

		$context = array_merge( $definition->sample_context(), $context_override ?? [] );

		$composed = $this->sender->compose( $type_id, $context );

		if ( null === $composed ) {
			\WP_CLI::error( sprintf( 'Email type "%s" is disabled in settings (Email Types tab in wp-admin).', $type_id ) );
			// WP_CLI::error throws in tests via the stub; in production it exits.
			return [ 'subject' => '', 'body' => '' ];
		}

		$body = '';

		if ( ! $subject_only ) {
			$wrapper = new Template_Wrapper( $this->replacer );
			$body    = $wrapper->wrap( $composed['body'] );
		}

		return [
			'subject' => $composed['subject'],
			'body'    => $body,
		];
	}

	/**
	 * Send a real sample email of the given type to the supplied address.
	 *
	 * The send goes through the same `Email_Sender::send()` path as the admin
	 * Email Types tab's "Send test" button — wrapper, plain-text injection,
	 * `_email_sent` action, log row — except that the row is tagged with
	 * `source=cli-test` so support sessions can spot CLI-originated sends in
	 * the log.
	 *
	 * ## OPTIONS
	 *
	 * <type>
	 * : The registered email type id (e.g. `payment_receipt`).
	 *
	 * <email>
	 * : Recipient email address. No confirmation is shown.
	 *
	 * [--dry-run]
	 * : Compose the email and print the wp_mail args without dispatching.
	 * : No log row is created.
	 *
	 * ## EXAMPLES
	 *
	 *     wp leastudios-email-templates send-test payment_receipt support@example.test
	 *     wp leastudios-email-templates send-test payment_receipt support@example.test --dry-run
	 *
	 * @param array<int, string>    $args       Positional arguments: [0] => type id, [1] => email.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function send_test( array $args, array $assoc_args ): void {
		$type_id = (string) ( $args[0] ?? '' );
		$email   = (string) ( $args[1] ?? '' );
		$dry_run = isset( $assoc_args['dry-run'] );

		$result = $this->dispatch_send_test( $type_id, $email, $dry_run );

		if ( $dry_run ) {
			\WP_CLI::log( '[dry-run] No email was sent and no log row was written.' );
			\WP_CLI::log( 'To: ' . $email );
			\WP_CLI::log( 'Subject: ' . $result['subject'] );
			\WP_CLI::log( '' );
			\WP_CLI::log( $result['body'] );
			return;
		}

		if ( $result['sent'] ) {
			\WP_CLI::success( sprintf( 'Sent %s to %s (logged as source=cli-test).', $type_id, $email ) );
		} else {
			\WP_CLI::error( sprintf( 'wp_mail returned false for type "%s" to %s. Check that the type is enabled and that mail is configured.', $type_id, $email ) );
		}
	}

	/**
	 * Validate args and dispatch the send. Pure-logic helper that tests can
	 * call without going through the WP_CLI loggers.
	 *
	 * @param string $type_id Registered email type id.
	 * @param string $email   Recipient address.
	 * @param bool   $dry_run When true, no wp_mail is dispatched and no row is logged.
	 * @return array{sent:bool, subject:string, body:string}
	 */
	public function dispatch_send_test( string $type_id, string $email, bool $dry_run ): array {
		if ( null === $this->registry->get( $type_id ) ) {
			\WP_CLI::error( sprintf( 'Unknown email type: %s', $type_id ) );
			// WP_CLI::error throws in tests via the stub; in production it exits.
			return [ 'sent' => false, 'subject' => '', 'body' => '' ];
		}

		if ( ! is_email( $email ) ) {
			\WP_CLI::error( sprintf( '"%s" is not a valid email address.', $email ) );
			// WP_CLI::error throws in tests via the stub; in production it exits.
			return [ 'sent' => false, 'subject' => '', 'body' => '' ];
		}

		$definition = $this->registry->get( $type_id );
		$context    = $definition->sample_context();

		if ( $dry_run ) {
			$composed = $this->sender->compose( $type_id, $context );
			if ( null === $composed ) {
				\WP_CLI::error( sprintf( 'Email type "%s" is disabled in settings (Email Types tab in wp-admin).', $type_id ) );
				// WP_CLI::error throws in tests via the stub; in production it exits.
				return [ 'sent' => false, 'subject' => '', 'body' => '' ];
			}

			return [
				'sent'    => false,
				'subject' => $composed['subject'],
				'body'    => $composed['body'],
			];
		}

		$sent = $this->sender->send( $type_id, $email, $context, 'cli-test' );

		return [
			'sent'    => $sent,
			'subject' => '',
			'body'    => '',
		];
	}
}
