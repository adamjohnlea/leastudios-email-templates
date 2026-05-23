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

	public function set_up(): void {
		parent::set_up();

		$registry = new Email_Type_Registry();
		$registry->register( new Payment_Receipt() );
		$registry->register( new Subscription_Created() );
		$registry->register( new Subscription_Renewed() );
		$registry->register( new Payment_Failed() );
		$registry->register( new Refund_Processed() );

		$this->sender = new Email_Sender( new Merge_Tag_Replacer(), $registry );

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
}
