<?php
/**
 * Tests for Email_Sender.
 *
 * @package LEAStudios\EmailTemplates\Tests
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Tests;

use LEAStudios\EmailTemplates\Email\Built_In\Payment_Failed;
use LEAStudios\EmailTemplates\Email\Built_In\Payment_Receipt;
use LEAStudios\EmailTemplates\Email\Built_In\Refund_Processed;
use LEAStudios\EmailTemplates\Email\Built_In\Subscription_Created;
use LEAStudios\EmailTemplates\Email\Built_In\Subscription_Renewed;
use LEAStudios\EmailTemplates\Email\Email_Sender;
use LEAStudios\EmailTemplates\Email\Email_Type_Registry;
use LEAStudios\EmailTemplates\Email\Merge_Tag_Replacer;
use LEAStudios\Tests\TestCase;

/**
 * @covers \LEAStudios\EmailTemplates\Email\Email_Sender
 */
class EmailSenderTest extends TestCase {

	private Email_Sender $sender;

	private Email_Type_Registry $registry;

	private \LEAStudios\EmailTemplates\Database\Suppression_Repository $suppression_repo;

	private \LEAStudios\EmailTemplates\Subscription\Unsubscribe_Manager $manager;

	public function set_up(): void {
		parent::set_up();

		$this->registry = new Email_Type_Registry();
		$this->registry->register( new Payment_Receipt() );
		$this->registry->register( new Subscription_Created() );
		$this->registry->register( new Subscription_Renewed() );
		$this->registry->register( new Payment_Failed() );
		$this->registry->register( new Refund_Processed() );

		// Force a fresh install each test — the schema-version option is
		// cached in wp_alloptions across tests, so the install() short-circuit
		// would otherwise skip recreating the table after a transactional
		// rollback nukes it.
		delete_option( 'leastudios_email_templates_suppressions_schema_version' );
		$this->suppression_repo = new \LEAStudios\EmailTemplates\Database\Suppression_Repository();
		$this->suppression_repo->install();
		$this->suppression_repo->delete_all();
		delete_option( 'leastudios_email_templates_unsubscribe_secret' );
		$this->manager = new \LEAStudios\EmailTemplates\Subscription\Unsubscribe_Manager( $this->suppression_repo );

		$this->sender = new Email_Sender( new Merge_Tag_Replacer(), $this->registry, $this->manager );

		reset_phpmailer_instance();
	}

	public function test_send_returns_false_when_disabled(): void {
		update_option(
			'leastudios_email_templates_emails',
			[
				'payment_receipt' => [ 'enabled' => false ],
			]
		);

		$result = $this->sender->send(
			'payment_receipt',
			'test@example.com',
			[
				'customer_name' => 'Test',
				'product_name'  => 'Widget',
				'amount'        => '$10.00',
			]
		);

		$this->assertFalse( $result );
	}

	public function test_send_uses_default_subject_when_custom_empty(): void {
		update_option(
			'leastudios_email_templates_emails',
			[
				'payment_receipt' => [
					'enabled' => true,
					'subject' => '',
					'body'    => '',
				],
			]
		);

		$this->sender->send(
			'payment_receipt',
			'test@example.com',
			[ 'product_name' => 'My Widget' ]
		);

		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertStringContainsString( 'My Widget', $mailer->get_sent()->subject );
	}

	public function test_send_uses_custom_subject(): void {
		update_option(
			'leastudios_email_templates_emails',
			[
				'payment_receipt' => [
					'enabled' => true,
					'subject' => 'Custom: {product_name} purchased',
					'body'    => '',
				],
			]
		);

		$this->sender->send(
			'payment_receipt',
			'test@example.com',
			[ 'product_name' => 'Gadget' ]
		);

		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertSame( 'Custom: Gadget purchased', $mailer->get_sent()->subject );
	}

	public function test_send_applies_recipient_override(): void {
		update_option(
			'leastudios_email_templates_emails',
			[
				'payment_receipt' => [
					'enabled'            => true,
					'subject'            => 'Test',
					'body'               => 'Body',
					'recipient_override' => 'admin@example.com',
				],
			]
		);

		$this->sender->send(
			'payment_receipt',
			'customer@example.com',
			[]
		);

		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertStringContainsString( 'admin@example.com', $mailer->get_sent()->to[0][0] );
	}

	public function test_send_fires_action(): void {
		update_option(
			'leastudios_email_templates_emails',
			[
				'payment_receipt' => [ 'enabled' => true ],
			]
		);

		$fired = false;

		add_action(
			'leastudios_email_templates_email_sent',
			function () use ( &$fired ) {
				$fired = true;
			}
		);

		$this->sender->send(
			'payment_receipt',
			'test@example.com',
			[ 'product_name' => 'Test' ]
		);

		$this->assertTrue( $fired );
	}

	public function test_defaults_to_enabled_when_no_settings(): void {
		delete_option( 'leastudios_email_templates_emails' );

		$result = $this->sender->send(
			'payment_receipt',
			'test@example.com',
			[ 'product_name' => 'Test' ]
		);

		$this->assertTrue( $result );
	}

	public function test_compose_returns_subject_body_headers_without_sending(): void {
		update_option(
			'leastudios_email_templates_emails',
			[
				'payment_receipt' => [
					'enabled' => true,
					'subject' => 'Receipt for {product_name}',
					'body'    => '<p>Thanks, {customer_name}.</p>',
				],
			]
		);

		reset_phpmailer_instance();

		$args = $this->sender->compose(
			'payment_receipt',
			[
				'customer_name' => 'Alice',
				'product_name'  => 'Widget',
			]
		);

		$this->assertNotNull( $args );
		$this->assertSame( 'Receipt for Widget', $args['subject'] );
		$this->assertStringContainsString( 'Thanks, Alice.', $args['body'] );
		$this->assertContains( 'Content-Type: text/html; charset=UTF-8', $args['headers'] );

		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertEmpty( $mailer->mock_sent );
	}

	public function test_compose_returns_null_when_type_disabled(): void {
		update_option(
			'leastudios_email_templates_emails',
			[
				'payment_receipt' => [ 'enabled' => false ],
			]
		);

		$args = $this->sender->compose( 'payment_receipt', [] );

		$this->assertNull( $args );
	}

	public function test_send_returns_false_for_unknown_type_id(): void {
		$result = $this->sender->send( 'does_not_exist', 'test@example.com', [] );

		$this->assertFalse( $result );
	}

	public function test_compose_returns_null_for_unknown_type_id(): void {
		$this->assertNull( $this->sender->compose( 'does_not_exist', [] ) );
	}

	public function test_send_defaults_source_to_web_in_email_sent_action(): void {
		$captured = null;
		add_action(
			'leastudios_email_templates_email_sent',
			static function ( $type_id, $to, $subject, $result, $body, $headers, $source ) use ( &$captured ): void {
				$captured = $source;
			},
			10,
			7
		);

		$this->sender->send(
			'payment_receipt',
			'buyer@example.test',
			[
				'order_id'      => '12345',
				'amount'        => 4999,
				'customer_name' => 'Test Buyer',
				'product_name'  => 'Test Product',
			]
		);

		$this->assertSame( 'web', $captured );
	}

	public function test_send_propagates_explicit_source_to_email_sent_action(): void {
		$captured = null;
		add_action(
			'leastudios_email_templates_email_sent',
			static function ( $type_id, $to, $subject, $result, $body, $headers, $source ) use ( &$captured ): void {
				$captured = $source;
			},
			10,
			7
		);

		$this->sender->send(
			'payment_receipt',
			'buyer@example.test',
			[
				'order_id'      => '12345',
				'amount'        => 4999,
				'customer_name' => 'Test Buyer',
				'product_name'  => 'Test Product',
			],
			'cli-test'
		);

		$this->assertSame( 'cli-test', $captured );
	}

	public function test_non_required_type_send_to_suppressed_recipient_is_skipped(): void {
		$this->manager->suppress( 'jane@example.com', 'link' );

		$mail_called = false;
		add_filter(
			'pre_wp_mail',
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- $value is the pre_wp_mail signature, not used here.
			static function ( $value ) use ( &$mail_called ) {
				$mail_called = true;
				return false;
			}
		);

		$suppressed_args = null;
		add_action(
			'leastudios_email_templates_email_suppressed',
			static function ( $type_id, $to, $subject, $body, $headers, $source ) use ( &$suppressed_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- compact() reads each variable by name.
				$suppressed_args = compact( 'type_id', 'to', 'subject', 'body', 'headers', 'source' );
			},
			10,
			6
		);

		$result = $this->sender->send( 'subscription_created', 'jane@example.com', [ 'customer_name' => 'Jane' ], 'web' );

		remove_all_filters( 'pre_wp_mail' );
		remove_all_actions( 'leastudios_email_templates_email_suppressed' );

		$this->assertFalse( $result, 'send must return false when gated' );
		$this->assertFalse( $mail_called, 'wp_mail must NOT be invoked when suppressed' );
		$this->assertNotNull( $suppressed_args, '_email_suppressed must fire' );
		$this->assertSame( 'subscription_created', $suppressed_args['type_id'] );
		$this->assertSame( 'jane@example.com', $suppressed_args['to'] );
		$this->assertSame( 'web', $suppressed_args['source'] );
		$this->assertNotSame( '', $suppressed_args['subject'], 'logged subject must be the composed value' );
		$this->assertNotSame( '', $suppressed_args['body'], 'logged body must be the composed value' );
	}

	public function test_required_type_send_to_suppressed_recipient_still_sends(): void {
		$this->manager->suppress( 'jane@example.com', 'link' );

		$mail_called = false;
		add_filter(
			'pre_wp_mail',
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- $value is the pre_wp_mail signature, not used here.
			static function ( $value ) use ( &$mail_called ) {
				$mail_called = true;
				return true; // Short-circuit wp_mail successfully.
			}
		);

		$result = $this->sender->send( 'payment_receipt', 'jane@example.com', [ 'customer_name' => 'Jane' ], 'web' );

		remove_all_filters( 'pre_wp_mail' );

		$this->assertTrue( $result, 'required-type send must bypass the gate' );
		$this->assertTrue( $mail_called );
	}

	private function register_phase9_fixture(): void {
		$this->registry->register(
			new class() extends \LEAStudios\EmailTemplates\Email\Abstract_Email_Type {
				public function id(): string {
					return 'phase9_fixture'; }
				public function label(): string {
					return 'Phase 9 Fixture'; }
				public function default_subject(): string {
					return 'Phase 9'; }
				public function default_body(): string {
					return '<a href="{unsubscribe_url}">opt out</a>'; }
				public function available_tags(): array {
					return [
						'{unsubscribe_url}' => [
							'description' => 'Opt-out URL',
							'escape'      => \LEAStudios\EmailTemplates\Email\Escape_Mode::URL,
						],
					];
				}
				public function sample_context(): array {
					return []; }
				public function is_transactional_required(): bool {
					return false; }
			}
		);
	}

	public function test_unsubscribe_url_resolves_to_real_url_for_non_required_with_recipient(): void {
		$this->register_phase9_fixture();

		$composed = $this->sender->compose( 'phase9_fixture', [], 'jane@example.com' );

		$this->assertNotNull( $composed );
		$body = (string) $composed['body'];
		// Accept either pretty-permalink (/wp-json/...) or default rest_route= form.
		$has_rest_path = str_contains( $body, '/wp-json/leastudios-email-templates/v1/unsubscribe' )
			|| str_contains( $body, 'leastudios-email-templates%2Fv1%2Funsubscribe' );
		$this->assertTrue( $has_rest_path, 'body must contain the unsubscribe REST route' );
		$this->assertStringContainsString( 'token=', $body );
	}

	public function test_unsubscribe_url_resolves_to_empty_for_required_type(): void {
		$this->registry->register(
			new class() extends \LEAStudios\EmailTemplates\Email\Abstract_Email_Type {
				public function id(): string {
					return 'phase9_required_fixture'; }
				public function label(): string {
					return 'Phase 9 Required Fixture'; }
				public function default_subject(): string {
					return 'X'; }
				public function default_body(): string {
					return '<a href="{unsubscribe_url}">opt out</a>'; }
				public function available_tags(): array {
					return [
						'{unsubscribe_url}' => [
							'description' => 'Opt-out URL',
							'escape'      => \LEAStudios\EmailTemplates\Email\Escape_Mode::URL,
						],
					];
				}
				public function sample_context(): array {
					return []; }
				public function is_transactional_required(): bool {
					return true; }
			}
		);

		$composed = $this->sender->compose( 'phase9_required_fixture', [], 'jane@example.com' );

		$this->assertNotNull( $composed );
		$body = (string) $composed['body'];
		$this->assertStringNotContainsString( '/wp-json/leastudios-email-templates/v1/unsubscribe', $body );
		$this->assertStringNotContainsString( 'leastudios-email-templates%2Fv1%2Funsubscribe', $body );
		$this->assertStringContainsString( 'href=""', $body, 'empty URL must be esc_url-empty in href' );
	}

	public function test_unsubscribe_url_resolves_to_empty_when_to_is_empty(): void {
		$this->register_phase9_fixture();
		$composed = $this->sender->compose( 'phase9_fixture', [], '' );

		$this->assertNotNull( $composed );
		$body = (string) $composed['body'];
		$this->assertStringNotContainsString( '/wp-json/leastudios-email-templates/v1/unsubscribe', $body );
		$this->assertStringNotContainsString( 'leastudios-email-templates%2Fv1%2Funsubscribe', $body );
	}

	public function test_unsubscribe_url_filter_is_applied(): void {
		$this->register_phase9_fixture();

		add_filter(
			'leastudios_email_templates_unsubscribe_url',
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- filter signature requires all three params.
			static fn ( $url, $email, $type_id ): string => 'https://example.com/opt-out?u=' . rawurlencode( $email ),
			10,
			3
		);

		$composed = $this->sender->compose( 'phase9_fixture', [], 'jane@example.com' );

		remove_all_filters( 'leastudios_email_templates_unsubscribe_url' );

		$this->assertNotNull( $composed );
		$this->assertStringContainsString( 'example.com/opt-out', (string) $composed['body'] );
	}

	public function test_auto_footer_appended_for_non_required_type(): void {
		add_filter(
			'pre_wp_mail',
			static function ( $value, $atts ): bool {
				global $captured_body;
				$captured_body = $atts['message'];
				return true;
			},
			10,
			2
		);

		$this->sender->send( 'subscription_created', 'jane@example.com', [ 'customer_name' => 'Jane' ], 'web' );

		remove_all_filters( 'pre_wp_mail' );

		global $captured_body;
		$this->assertStringContainsString( 'unsubscribe', strtolower( (string) $captured_body ), 'footer must be appended' );
		// Accept either pretty permalinks or default ?rest_route form (matches Task 7's flexibility).
		$pretty = '/wp-json/leastudios-email-templates/v1/unsubscribe';
		$ugly   = 'leastudios-email-templates%2Fv1%2Funsubscribe';
		$this->assertTrue(
			str_contains( (string) $captured_body, $pretty ) || str_contains( (string) $captured_body, $ugly ),
			'footer must contain the REST unsubscribe URL'
		);
	}

	public function test_auto_footer_NOT_appended_for_required_type(): void {
		add_filter(
			'pre_wp_mail',
			static function ( $value, $atts ): bool {
				global $captured_body;
				$captured_body = $atts['message'];
				return true;
			},
			10,
			2
		);

		$this->sender->send( 'payment_receipt', 'jane@example.com', [ 'customer_name' => 'Jane' ], 'web' );

		remove_all_filters( 'pre_wp_mail' );

		global $captured_body;
		$pretty = '/wp-json/leastudios-email-templates/v1/unsubscribe';
		$ugly   = 'leastudios-email-templates%2Fv1%2Funsubscribe';
		$this->assertFalse(
			str_contains( (string) $captured_body, $pretty ) || str_contains( (string) $captured_body, $ugly ),
			'payment_receipt (required) must NOT include the unsubscribe URL via the auto-footer'
		);
	}

	public function test_resolved_unsubscribe_url_wins_over_caller_supplied_context(): void {
		$this->register_phase9_fixture();

		$composed = $this->sender->compose(
			'phase9_fixture',
			[ 'unsubscribe_url' => 'https://attacker.example/evil' ],
			'jane@example.com'
		);

		$this->assertNotNull( $composed );
		$this->assertStringNotContainsString(
			'attacker.example',
			(string) $composed['body'],
			'caller-supplied unsubscribe_url must not override our resolved value'
		);
		// Accept either pretty-permalink (/wp-json/...) or default rest_route= form.
		$pretty = '/wp-json/leastudios-email-templates/v1/unsubscribe';
		$ugly   = 'leastudios-email-templates%2Fv1%2Funsubscribe';
		$this->assertTrue(
			str_contains( (string) $composed['body'], $pretty ) || str_contains( (string) $composed['body'], $ugly ),
			'our recipient-aware resolution must win'
		);
	}

	public function test_unsubscribe_footer_html_filter_applied(): void {
		add_filter(
			'leastudios_email_templates_unsubscribe_footer_html',
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- filter signature requires all three params.
			static fn ( $html, $to, $type_id ): string => '<!--FOOTER:' . esc_html( $to ) . '-->',
			10,
			3
		);

		add_filter(
			'pre_wp_mail',
			static function ( $value, $atts ): bool {
				global $captured_body;
				$captured_body = $atts['message'];
				return true;
			},
			10,
			2
		);

		$this->sender->send( 'subscription_created', 'jane@example.com', [ 'customer_name' => 'Jane' ], 'web' );

		remove_all_filters( 'leastudios_email_templates_unsubscribe_footer_html' );
		remove_all_filters( 'pre_wp_mail' );

		global $captured_body;
		$this->assertStringContainsString( '<!--FOOTER:jane@example.com-->', (string) $captured_body );
	}
}
