<?php
/**
 * Tests for Suppression_Repository.
 *
 * @package LEAStudios\EmailTemplates\Tests
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Tests;

use LEAStudios\EmailTemplates\Database\Suppression_Entry;
use LEAStudios\EmailTemplates\Database\Suppression_Repository;
use LEAStudios\Tests\TestCase;

/**
 * @covers \LEAStudios\EmailTemplates\Database\Suppression_Repository
 */
class SuppressionRepositoryTest extends TestCase {

	private Suppression_Repository $repo;

	public function set_up(): void {
		parent::set_up();
		$this->repo = new Suppression_Repository();
		$this->repo->install();
		$this->repo->delete_all();
	}

	public function test_install_is_idempotent(): void {
		// Calling install twice must not throw and must converge on the
		// SCHEMA_VERSION marker.
		$this->repo->install();
		$this->repo->install();

		$this->assertSame(
			'1.0.0',
			get_option( 'leastudios_email_templates_suppressions_schema_version' )
		);
	}

	public function test_upsert_inserts_then_refreshes(): void {
		$this->repo->upsert( 'jane@example.com', 'link' );
		$this->repo->upsert( 'jane@example.com', 'admin' );

		$rows = $this->repo->paginate( [], 50, 1 );

		$this->assertSame( 1, $rows['total'], 'must dedupe on email' );
		$this->assertInstanceOf( Suppression_Entry::class, $rows['rows'][0] );
		$this->assertSame( 'jane@example.com', $rows['rows'][0]->email );
		$this->assertSame( 'admin', $rows['rows'][0]->source, 'source must be refreshed by second upsert' );
	}

	public function test_email_is_normalized_to_lowercase_on_insert_and_lookup(): void {
		$this->repo->upsert( 'MIXED@Case.COM', 'link' );

		$this->assertTrue( $this->repo->exists_by_email( 'mixed@case.com' ) );
		$this->assertTrue( $this->repo->exists_by_email( 'Mixed@Case.Com' ) );

		$rows = $this->repo->paginate( [], 50, 1 );
		$this->assertSame( 'mixed@case.com', $rows['rows'][0]->email );
	}

	public function test_delete_by_email_removes_row(): void {
		$this->repo->upsert( 'jane@example.com', 'link' );
		$this->repo->delete_by_email( 'jane@example.com' );

		$this->assertFalse( $this->repo->exists_by_email( 'jane@example.com' ) );
	}

	public function test_paginate_orders_by_suppressed_at_desc(): void {
		global $wpdb;
		$table = $this->repo->table_name();
		$this->repo->upsert( 'older@example.com', 'link' );
		// Force an older timestamp for predictable ordering.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET suppressed_at = %s WHERE email = %s",
				'2020-01-01 00:00:00',
				'older@example.com'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->repo->upsert( 'newer@example.com', 'link' );

		$rows = $this->repo->paginate( [], 50, 1 );

		$this->assertSame( 2, $rows['total'] );
		$this->assertSame( 'newer@example.com', $rows['rows'][0]->email );
		$this->assertSame( 'older@example.com', $rows['rows'][1]->email );
	}

	public function test_drop_clears_table_and_schema_option(): void {
		$this->repo->upsert( 'jane@example.com', 'link' );
		$this->repo->drop();

		$this->assertFalse( get_option( 'leastudios_email_templates_suppressions_schema_version' ) );

		// Re-install so tearDown's delete_all can run.
		$this->repo->install();
	}
}
