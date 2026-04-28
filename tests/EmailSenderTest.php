<?php
/**
 * Tests for Email_Sender.
 *
 * @package LEAStudios\EmailTemplates\Tests
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Tests;

use LEAStudios\EmailTemplates\Email\Email_Sender;
use LEAStudios\EmailTemplates\Email\Email_Type;
use LEAStudios\EmailTemplates\Email\Merge_Tag_Replacer;
use LEAStudios\Tests\TestCase;

/**
 * @covers \LEAStudios\EmailTemplates\Email\Email_Sender
 */
class EmailSenderTest extends TestCase {

	private Email_Sender $sender;

	public function set_up(): void {
		parent::set_up();
		$this->sender = new Email_Sender( new Merge_Tag_Replacer() );

		// Reset to allow wp_mail to be captured.
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
			Email_Type::PAYMENT_RECEIPT,
			'test@example.com',
			[ 'customer_name' => 'Test', 'product_name' => 'Widget', 'amount' => '$10.00' ]
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
			Email_Type::PAYMENT_RECEIPT,
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
			Email_Type::PAYMENT_RECEIPT,
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
			Email_Type::PAYMENT_RECEIPT,
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
			Email_Type::PAYMENT_RECEIPT,
			'test@example.com',
			[ 'product_name' => 'Test' ]
		);

		$this->assertTrue( $fired );
	}

	public function test_defaults_to_enabled_when_no_settings(): void {
		delete_option( 'leastudios_email_templates_emails' );

		$result = $this->sender->send(
			Email_Type::PAYMENT_RECEIPT,
			'test@example.com',
			[ 'product_name' => 'Test' ]
		);

		$this->assertTrue( $result );
	}
}
