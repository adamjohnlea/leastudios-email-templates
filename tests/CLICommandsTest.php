<?php
/**
 * Tests for the WP-CLI Commands class.
 *
 * @package LEAStudios\EmailTemplates\Tests
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Tests;

use LEAStudios\EmailTemplates\CLI\Commands;
use LEAStudios\EmailTemplates\Email\Abstract_Email_Type;
use LEAStudios\EmailTemplates\Email\Built_In\Payment_Receipt;
use LEAStudios\EmailTemplates\Email\Built_In\Subscription_Created;
use LEAStudios\EmailTemplates\Email\Email_Sender;
use LEAStudios\EmailTemplates\Email\Email_Type_Registry;
use LEAStudios\EmailTemplates\Email\Merge_Tag_Replacer;
use LEAStudios\Tests\TestCase;

/**
 * @covers \LEAStudios\EmailTemplates\CLI\Commands
 */
class CLICommandsTest extends TestCase {

	private Email_Type_Registry $registry;
	private Email_Sender $sender;
	private Merge_Tag_Replacer $replacer;
	private Commands $commands;

	public function set_up(): void {
		parent::set_up();
		\WP_CLI::reset();
		$this->registry = new Email_Type_Registry();
		$this->registry->register( new Payment_Receipt() );
		$this->registry->register( new Subscription_Created() );
		$this->replacer = new Merge_Tag_Replacer();
		$this->sender   = new Email_Sender( $this->replacer, $this->registry );
		$this->commands = new Commands( $this->registry, $this->sender, $this->replacer );
	}

	public function test_build_type_rows_returns_one_row_per_registered_type(): void {
		$rows = $this->commands->build_type_rows();

		$this->assertCount( 2, $rows );

		$ids = array_column( $rows, 'id' );
		$this->assertContains( 'payment_receipt', $ids );
		$this->assertContains( 'subscription_created', $ids );
	}

	public function test_build_type_rows_emits_the_required_columns(): void {
		$rows = $this->commands->build_type_rows();

		$this->assertArrayHasKey( 'id', $rows[0] );
		$this->assertArrayHasKey( 'label', $rows[0] );
		$this->assertArrayHasKey( 'transactional_required', $rows[0] );
		$this->assertArrayHasKey( 'source', $rows[0] );
	}

	public function test_build_type_rows_marks_built_in_definitions_as_built_in(): void {
		$rows = $this->commands->build_type_rows();

		foreach ( $rows as $row ) {
			$this->assertSame( 'built-in', $row['source'] );
		}
	}

	public function test_build_type_rows_marks_unknown_definitions_as_third_party(): void {
		$stub = new class extends Abstract_Email_Type {
			public function id(): string {
				return 'my_custom_type';
			}
			public function label(): string {
				return 'Custom';
			}
			public function default_subject(): string {
				return 'Subject';
			}
			public function default_body(): string {
				return 'Body';
			}
			public function available_tags(): array {
				return [];
			}
			public function sample_context(): array {
				return [];
			}
		};

		$registry = new Email_Type_Registry();
		$registry->register( $stub );
		$commands = new Commands( $registry, $this->sender, $this->replacer );

		$rows = $commands->build_type_rows();

		$this->assertCount( 1, $rows );
		$this->assertSame( 'third-party', $rows[0]['source'] );
		$this->assertSame( 'my_custom_type', $rows[0]['id'] );
	}

	public function test_render_preview_returns_subject_and_wrapped_html(): void {
		$output = $this->commands->render_preview( 'payment_receipt', null, false );

		$this->assertArrayHasKey( 'subject', $output );
		$this->assertArrayHasKey( 'body', $output );
		$this->assertNotSame( '', $output['subject'] );
		$this->assertStringContainsString( '<', $output['body'] );
	}

	public function test_render_preview_applies_context_override(): void {
		$output = $this->commands->render_preview(
			'payment_receipt',
			[ 'customer_name' => 'Ada Lovelace' ],
			false
		);

		$this->assertStringContainsString( 'Ada Lovelace', $output['body'] );
	}

	public function test_render_preview_with_subject_only_returns_empty_body(): void {
		$output = $this->commands->render_preview( 'payment_receipt', null, true );

		$this->assertNotSame( '', $output['subject'] );
		$this->assertSame( '', $output['body'] );
	}

	public function test_render_preview_throws_on_unknown_type(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Unknown email type/' );

		$this->commands->render_preview( 'nope_does_not_exist', null, false );
	}

	public function test_dispatch_send_test_real_send_creates_log_row_with_cli_test_source(): void {
		// Wire the real log subscriber so we can assert the row.
		// Remove any logger already registered by Plugin::init (bootstrap loads
		// the full plugin, which attaches its own Send_Logger) so only the
		// test-controlled one fires.
		remove_all_actions( 'leastudios_email_templates_email_sent' );
		$repo   = new \LEAStudios\EmailTemplates\Database\Email_Log_Repository();
		$repo->install();
		$repo->delete_all();
		( new \LEAStudios\EmailTemplates\Log\Send_Logger( $repo ) )->init();

		$result = $this->commands->dispatch_send_test( 'payment_receipt', 'support@example.test', false );

		$this->assertTrue( $result['sent'] );
		$page = $repo->paginate( [], 10, 1 );
		$this->assertCount( 1, $page['rows'] );
		$this->assertSame( 'cli-test', $page['rows'][0]->source );
	}

	public function test_dispatch_send_test_dry_run_does_not_log(): void {
		// The dry-run path never calls Email_Sender::send(), so no _email_sent
		// action fires and no logger writes a row. We still install/clear the
		// table so the row count assertion is meaningful.
		$repo = new \LEAStudios\EmailTemplates\Database\Email_Log_Repository();
		$repo->install();
		$repo->delete_all();

		$result = $this->commands->dispatch_send_test( 'payment_receipt', 'support@example.test', true );

		$this->assertFalse( $result['sent'] );
		$this->assertArrayHasKey( 'subject', $result );
		$this->assertArrayHasKey( 'body', $result );

		$page = $repo->paginate( [], 10, 1 );
		$this->assertCount( 0, $page['rows'] );
	}

	public function test_dispatch_send_test_rejects_invalid_email(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/not a valid email/i' );

		$this->commands->dispatch_send_test( 'payment_receipt', 'not-an-email', false );
	}

	public function test_dispatch_send_test_rejects_unknown_type(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Unknown email type/' );

		$this->commands->dispatch_send_test( 'nope_does_not_exist', 'support@example.test', false );
	}
}
