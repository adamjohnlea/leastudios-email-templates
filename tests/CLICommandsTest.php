<?php
/**
 * Tests for the WP-CLI Commands class.
 *
 * @package LEAStudios\EmailTemplates\Tests
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Tests;

use LEAStudios\EmailTemplates\CLI\Commands;
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
}
