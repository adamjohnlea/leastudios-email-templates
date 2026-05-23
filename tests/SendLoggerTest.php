<?php
/**
 * Tests for Send_Logger.
 *
 * @package LEAStudios\EmailTemplates\Tests
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Tests;

use LEAStudios\EmailTemplates\Database\Email_Log_Repository;
use LEAStudios\EmailTemplates\Log\Send_Logger;
use LEAStudios\Tests\TestCase;

/**
 * @covers \LEAStudios\EmailTemplates\Log\Send_Logger
 */
class SendLoggerTest extends TestCase {

	private Email_Log_Repository $repo;
	private Send_Logger $logger;

	public function set_up(): void {
		parent::set_up();
		$this->repo = new Email_Log_Repository();
		$this->repo->install();
		$this->repo->delete_all();
		$this->logger = new Send_Logger( $this->repo );
	}

	public function test_records_successful_send(): void {
		$this->logger->record(
			'payment_receipt',
			'buyer@example.com',
			'Receipt',
			true,
			'<p>Body</p>',
			[ 'Content-Type: text/html' ]
		);

		$page = $this->repo->paginate( [], 10, 1 );
		$this->assertCount( 1, $page['rows'] );
		$this->assertSame( 'payment_receipt', $page['rows'][0]->type );
		$this->assertSame( 'sent', $page['rows'][0]->status );
		$this->assertSame( '<p>Body</p>', $page['rows'][0]->body );
		$this->assertSame( 'Content-Type: text/html', $page['rows'][0]->headers );
	}

	public function test_records_failed_send(): void {
		$this->logger->record(
			'payment_failed',
			'fail@example.com',
			'Fail',
			false,
			'<p>Body</p>',
			[]
		);

		$page = $this->repo->paginate( [ 'status' => 'failed' ], 10, 1 );
		$this->assertCount( 1, $page['rows'] );
	}

	public function test_init_registers_the_email_sent_subscriber(): void {
		$this->logger->init();

		$this->assertNotFalse(
			has_action( 'leastudios_email_templates_email_sent', [ $this->logger, 'record' ] )
		);
	}

	public function test_record_persists_source_to_log_row(): void {
		$this->logger->record(
			'payment_receipt',
			'buyer@example.com',
			'Receipt',
			true,
			'<p>Body</p>',
			[ 'Content-Type: text/html' ],
			'cli-test'
		);

		$page = $this->repo->paginate( [], 10, 1 );
		$this->assertCount( 1, $page['rows'] );
		$this->assertSame( 'cli-test', $page['rows'][0]->source );
	}

	public function test_record_defaults_source_to_web_in_php_payload(): void {
		$spy = new class extends Email_Log_Repository {
			/** @var array<string, mixed>|null */
			public ?array $last_data = null;

			public function create( array $data ): int {
				$this->last_data = $data;
				return 1;
			}
		};

		$logger = new Send_Logger( $spy );
		$logger->record(
			'payment_receipt',
			'buyer@example.com',
			'Receipt',
			true,
			'<p>Body</p>',
			[ 'Content-Type: text/html' ]
		);

		$this->assertNotNull( $spy->last_data );
		$this->assertArrayHasKey( 'source', $spy->last_data );
		$this->assertSame( 'web', $spy->last_data['source'] );
	}

	public function test_init_registers_subscriber_with_seven_accepted_args(): void {
		$this->logger->init();

		global $wp_filter;
		$callbacks = $wp_filter['leastudios_email_templates_email_sent']->callbacks[10] ?? [];
		$this->assertNotEmpty( $callbacks );

		$first = array_values( $callbacks )[0];
		$this->assertSame( 7, $first['accepted_args'] );
	}
}
