<?php
/**
 * Tests for Email_Log_List_Table column rendering.
 *
 * @package LEAStudios\EmailTemplates\Tests
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Tests;

use LEAStudios\EmailTemplates\Admin\Email_Log_List_Table;
use LEAStudios\EmailTemplates\Database\Email_Log_Entry;
use LEAStudios\EmailTemplates\Database\Email_Log_Repository;
use LEAStudios\EmailTemplates\Email\Built_In\Payment_Receipt;
use LEAStudios\EmailTemplates\Email\Email_Type_Registry;
use LEAStudios\Tests\TestCase;

/**
 * @covers \LEAStudios\EmailTemplates\Admin\Email_Log_List_Table
 */
class EmailLogListTableTest extends TestCase {

	private Email_Log_List_Table $table;

	public function set_up(): void {
		parent::set_up();

		if ( ! function_exists( 'convert_to_screen' ) ) {
			require_once ABSPATH . 'wp-admin/includes/template.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
			require_once ABSPATH . 'wp-admin/includes/screen.php';
		}
		set_current_screen( 'toplevel_page_leastudios-email-templates-log' );

		$registry = new Email_Type_Registry();
		$registry->register( new Payment_Receipt() );

		$this->table = new Email_Log_List_Table( new Email_Log_Repository(), $registry );
	}

	public function test_recipient_column_does_not_show_cli_badge_for_web_source(): void {
		$entry = new Email_Log_Entry(
			1,
			'payment_receipt',
			'a@example.test',
			'Receipt',
			'<p>body</p>',
			'',
			'sent',
			'',
			'2026-05-22 00:00:00',
			'web'
		);

		$html = $this->table->column_recipient( $entry );

		$this->assertStringContainsString( 'a@example.test', $html );
		$this->assertStringNotContainsString( '(cli)', $html );
	}

	public function test_recipient_column_shows_cli_badge_for_cli_test_source(): void {
		$entry = new Email_Log_Entry(
			2,
			'payment_receipt',
			'b@example.test',
			'Receipt',
			'<p>body</p>',
			'',
			'sent',
			'',
			'2026-05-22 00:00:00',
			'cli-test'
		);

		$html = $this->table->column_recipient( $entry );

		$this->assertStringContainsString( 'b@example.test', $html );
		$this->assertStringContainsString( '(cli)', $html );
	}
}
