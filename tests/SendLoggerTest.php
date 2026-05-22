<?php
/**
 * Tests for Send_Logger.
 *
 * @package LEAStudios\EmailTemplates\Tests
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Tests;

use LEAStudios\EmailTemplates\Database\Email_Log_Repository;
use LEAStudios\EmailTemplates\Email\Email_Type;
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
			Email_Type::PAYMENT_RECEIPT,
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
			Email_Type::PAYMENT_FAILED,
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
}
