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

	private \LEAStudios\EmailTemplates\Database\Suppression_Repository $suppression_repo;

	private \LEAStudios\EmailTemplates\Subscription\Unsubscribe_Manager $manager;

	public function set_up(): void {
		parent::set_up();

		$registry = new Email_Type_Registry();
		$registry->register( new Payment_Receipt() );
		$registry->register( new Subscription_Created() );
		$registry->register( new Subscription_Renewed() );
		$registry->register( new Payment_Failed() );
		$registry->register( new Refund_Processed() );

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

		$this->sender = new Email_Sender( new Merge_Tag_Replacer(), $registry, $this->manager );

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
}
