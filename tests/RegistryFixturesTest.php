<?php
/**
 * Integration test: a third-party plugin can register a custom email type
 * via the leastudios_email_templates_register_types action and have it
 * dispatch through Email_Sender::send() end-to-end.
 *
 * @package LEAStudios\EmailTemplates\Tests
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Tests;

use LEAStudios\EmailTemplates\Email\Abstract_Email_Type;
use LEAStudios\EmailTemplates\Email\Email_Sender;
use LEAStudios\EmailTemplates\Email\Email_Type_Registry;
use LEAStudios\EmailTemplates\Email\Escape_Mode;
use LEAStudios\EmailTemplates\Email\Merge_Tag_Replacer;
use LEAStudios\Tests\TestCase;

/**
 * Fixture standing in for a third-party plugin's custom email type.
 */
final class Fake_Welcome extends Abstract_Email_Type {
	public function id(): string {
		return 'fake_welcome';
	}

	public function label(): string {
		return 'Fake Welcome';
	}

	public function default_subject(): string {
		return 'Welcome to {site_name}, {customer_name}!';
	}

	public function default_body(): string {
		return '<h1>Hi {customer_name}!</h1>';
	}

	public function available_tags(): array {
		return [
			'{customer_name}' => [
				'description' => 'Customer name',
				'escape'      => Escape_Mode::HTML,
			],
			'{site_name}'     => [
				'description' => 'Site name',
				'escape'      => Escape_Mode::HTML,
			],
		];
	}

	public function sample_context(): array {
		return [ 'customer_name' => 'Jane' ];
	}
	// is_transactional_required() defaults to false — that's the third-party expectation.
}

/**
 * @coversNothing
 */
class RegistryFixturesTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		reset_phpmailer_instance();

		delete_option( 'leastudios_email_templates_emails' );
	}

	public function test_fixture_appears_in_registry_after_action_fires(): void {
		$registry = new Email_Type_Registry();

		add_action(
			'leastudios_email_templates_register_types',
			static function ( Email_Type_Registry $r ): void {
				$r->register( new Fake_Welcome() );
			}
		);

		do_action( 'leastudios_email_templates_register_types', $registry );

		$this->assertTrue( $registry->has( 'fake_welcome' ) );
		$this->assertInstanceOf( Fake_Welcome::class, $registry->get( 'fake_welcome' ) );

		remove_all_actions( 'leastudios_email_templates_register_types' );
	}

	public function test_fixture_is_not_transactional_required_by_default(): void {
		$def = new Fake_Welcome();
		$this->assertFalse( $def->is_transactional_required() );
	}

	public function test_fixture_dispatches_through_sender_end_to_end(): void {
		$registry = new Email_Type_Registry();
		$registry->register( new Fake_Welcome() );

		$sender = new Email_Sender( new Merge_Tag_Replacer(), $registry );

		$result = $sender->send( 'fake_welcome', 'jane@example.com', [ 'customer_name' => 'Jane' ] );

		$this->assertTrue( $result, 'wp_mail should have succeeded for the fixture type' );

		$mailer = tests_retrieve_phpmailer_instance();
		$sent   = $mailer->get_sent();

		$this->assertSame( 'jane@example.com', $sent->to[0][0] );
		$this->assertStringContainsString( 'Jane', $sent->subject );
		$this->assertStringContainsString( 'Jane', $sent->body );
	}

	public function test_third_party_can_override_a_built_in_via_last_write_wins(): void {
		$registry = new Email_Type_Registry();

		// Simulate the Plugin::init order: built-ins registered first, then
		// the action lets third parties override.
		$registry->register( new \LEAStudios\EmailTemplates\Email\Built_In\Payment_Receipt() );

		$override = new class() extends Abstract_Email_Type {
			public function id(): string {
				return 'payment_receipt';
			}

			public function label(): string {
				return 'Overridden Receipt';
			}

			public function default_subject(): string {
				return 'OVERRIDE';
			}

			public function default_body(): string {
				return '<p>OVERRIDE</p>';
			}

			public function available_tags(): array {
				return [];
			}

			public function sample_context(): array {
				return [];
			}
		};

		$registry->register( $override );

		$this->assertSame( 'Overridden Receipt', $registry->get( 'payment_receipt' )->label() );
	}
}
