<?php
/**
 * Tests for Suppressions_List_Table.
 *
 * @package LEAStudios\EmailTemplates\Tests
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Tests;

use LEAStudios\EmailTemplates\Admin\Suppressions_List_Table;
use LEAStudios\EmailTemplates\Database\Suppression_Entry;
use LEAStudios\EmailTemplates\Database\Suppression_Repository;
use LEAStudios\Tests\TestCase;

/**
 * @covers \LEAStudios\EmailTemplates\Admin\Suppressions_List_Table
 */
class SuppressionsListTableTest extends TestCase {

	public function set_up(): void {
		parent::set_up();

		if ( ! function_exists( 'convert_to_screen' ) ) {
			require_once ABSPATH . 'wp-admin/includes/template.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
			require_once ABSPATH . 'wp-admin/includes/screen.php';
		}
		set_current_screen( 'toplevel_page_leastudios-email-templates-suppressions' );
	}

	public function test_column_definitions(): void {
		$repo  = new Suppression_Repository();
		$table = new Suppressions_List_Table( $repo );

		$columns = $table->get_columns();
		$this->assertArrayHasKey( 'cb', $columns );
		$this->assertArrayHasKey( 'email', $columns );
		$this->assertArrayHasKey( 'suppressed_at', $columns );
		$this->assertArrayHasKey( 'source', $columns );
	}

	public function test_email_column_renders_value_and_remove_action(): void {
		$repo  = new Suppression_Repository();
		$table = new Suppressions_List_Table( $repo );

		$entry  = new Suppression_Entry( 7, 'jane@example.com', '2026-05-22 10:00:00', 'link' );
		$output = $table->column_email_test_shim( $entry );

		$this->assertStringContainsString( 'jane@example.com', $output );
		$this->assertStringContainsString( 'Remove', $output );
		$this->assertStringContainsString( 'leastudios_email_templates_remove_suppression', $output );
	}

	public function test_source_column_renders_label(): void {
		$repo  = new Suppression_Repository();
		$table = new Suppressions_List_Table( $repo );

		$this->assertSame(
			'link',
			$table->column_default_test_shim(
				new Suppression_Entry( 1, 'a@b.c', '2026-05-22 10:00:00', 'link' ),
				'source'
			)
		);
	}
}
