<?php
/**
 * Tests for Email_Log_Repository.
 *
 * @package LEAStudios\EmailTemplates\Tests
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Tests;

use LEAStudios\EmailTemplates\Database\Email_Log_Repository;
use LEAStudios\Tests\TestCase;

/**
 * @covers \LEAStudios\EmailTemplates\Database\Email_Log_Repository
 */
class EmailLogRepositoryTest extends TestCase {

	private Email_Log_Repository $repo;

	public function set_up(): void {
		parent::set_up();
		$this->repo = new Email_Log_Repository();
		$this->repo->install();
		$this->repo->delete_all();
	}

	public function test_create_and_get(): void {
		$id = $this->repo->create(
			[
				'type'      => 'payment_receipt',
				'recipient' => 'a@example.com',
				'subject'   => 'Hi',
				'body'      => '<p>Body</p>',
				'headers'   => 'Content-Type: text/html',
				'status'    => 'sent',
				'error'     => null,
			]
		);

		$this->assertGreaterThan( 0, $id );

		$row = $this->repo->get( $id );
		$this->assertNotNull( $row );
		$this->assertSame( 'payment_receipt', $row->type );
		$this->assertSame( 'a@example.com', $row->recipient );
		$this->assertSame( 'sent', $row->status );
		$this->assertSame( '<p>Body</p>', $row->body );
	}

	public function test_paginated_query_with_filters(): void {
		foreach ( [ 'payment_receipt', 'refund_processed', 'payment_receipt' ] as $i => $type ) {
			$this->repo->create(
				[
					'type'      => $type,
					'recipient' => "user{$i}@example.com",
					'subject'   => "S{$i}",
					'body'      => '',
					'headers'   => '',
					'status'    => 0 === $i % 2 ? 'sent' : 'failed',
					'error'     => null,
				]
			);
		}

		$page = $this->repo->paginate( [ 'type' => 'payment_receipt' ], 10, 1 );
		$this->assertCount( 2, $page['rows'] );
		$this->assertSame( 2, $page['total'] );

		$page = $this->repo->paginate( [ 'status' => 'failed' ], 10, 1 );
		$this->assertCount( 1, $page['rows'] );
	}

	public function test_create_persists_source_column(): void {
		$id = $this->repo->create(
			[
				'type'      => 'payment_receipt',
				'recipient' => 'a@example.test',
				'subject'   => 'Hi',
				'body'      => '<p>Hi</p>',
				'headers'   => 'Content-Type: text/html',
				'status'    => 'sent',
				'error'     => null,
				'source'    => 'cli-test',
			]
		);

		$entry = $this->repo->get( $id );
		$this->assertNotNull( $entry );
		$this->assertSame( 'cli-test', $entry->source );
	}

	public function test_create_defaults_source_to_web_when_omitted(): void {
		$id = $this->repo->create(
			[
				'type'      => 'payment_receipt',
				'recipient' => 'a@example.test',
				'subject'   => 'Hi',
				'body'      => '<p>Hi</p>',
				'headers'   => '',
				'status'    => 'sent',
				'error'     => null,
			]
		);

		$entry = $this->repo->get( $id );
		$this->assertNotNull( $entry );
		$this->assertSame( 'web', $entry->source );
	}

	public function test_prune_removes_rows_older_than_cutoff(): void {
		global $wpdb;

		$this->repo->create(
			[
				'type'      => 'payment_receipt',
				'recipient' => 'old@example.com',
				'subject'   => 'old',
				'body'      => '',
				'headers'   => '',
				'status'    => 'sent',
				'error'     => null,
			]
		);

		$table = $this->repo->table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "UPDATE {$table} SET created_at = DATE_SUB(NOW(), INTERVAL 60 DAY)" );

		$this->repo->create(
			[
				'type'      => 'payment_receipt',
				'recipient' => 'new@example.com',
				'subject'   => 'new',
				'body'      => '',
				'headers'   => '',
				'status'    => 'sent',
				'error'     => null,
			]
		);

		$deleted = $this->repo->prune_older_than( 30 );

		$this->assertSame( 1, $deleted );

		$page = $this->repo->paginate( [], 10, 1 );
		$this->assertCount( 1, $page['rows'] );
		$this->assertSame( 'new@example.com', $page['rows'][0]->recipient );
	}
}
