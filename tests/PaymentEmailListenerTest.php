<?php
/**
 * Tests for Payment_Email_Listener.
 *
 * @package LEAStudios\EmailTemplates\Tests
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Tests;

use LEAStudios\EmailTemplates\Email\Email_Sender;
use LEAStudios\EmailTemplates\Email\Merge_Tag_Replacer;
use LEAStudios\EmailTemplates\Payment\Payment_Data_Resolver;
use LEAStudios\EmailTemplates\Payment\Payment_Email_Listener;
use LEAStudios\Tests\TestCase;

/**
 * @covers \LEAStudios\EmailTemplates\Payment\Payment_Email_Listener
 */
class PaymentEmailListenerTest extends TestCase {

	private Email_Sender $sender;
	private Payment_Data_Resolver $resolver;
	private Payment_Email_Listener $listener;

	public function set_up(): void {
		parent::set_up();

		$this->sender   = $this->createMock( Email_Sender::class );
		$this->resolver = $this->createMock( Payment_Data_Resolver::class );
		$this->listener = new Payment_Email_Listener( $this->sender, $this->resolver );
	}

	public function test_init_registers_hooks(): void {
		$this->listener->init();

		$this->assertNotFalse(
			has_action( 'leastudios_payments_order_created', [ $this->listener, 'on_order_created' ] )
		);
		$this->assertNotFalse(
			has_action( 'leastudios_payments_subscription_synced', [ $this->listener, 'on_subscription_synced' ] )
		);
		$this->assertNotFalse(
			has_action( 'leastudios_payments_subscription_invoice_paid', [ $this->listener, 'on_invoice_paid' ] )
		);
		$this->assertNotFalse(
			has_action( 'leastudios_payments_subscription_payment_failed', [ $this->listener, 'on_payment_failed' ] )
		);
	}

	public function test_on_order_created_sends_receipt(): void {
		$this->resolver->method( 'resolve_order_context' )
			->with( 42 )
			->willReturn(
				[
					'customer_email' => 'buyer@example.com',
					'customer_name'  => 'Test Buyer',
					'amount'         => '$29.99',
				]
			);

		$this->sender->expects( $this->once() )
			->method( 'send' )
			->with(
				'payment_receipt',
				'buyer@example.com',
				$this->anything()
			);

		$this->listener->on_order_created( 42, [ 'id' => 'cs_123' ] );
	}

	public function test_on_order_created_skips_when_no_email(): void {
		$this->resolver->method( 'resolve_order_context' )
			->willReturn( [ 'customer_email' => '' ] );

		$this->sender->expects( $this->never() )->method( 'send' );

		$this->listener->on_order_created( 42, [] );
	}

	public function test_on_subscription_synced_skips_non_active_status(): void {
		$this->sender->expects( $this->never() )->method( 'send' );

		$this->listener->on_subscription_synced( 'sub_unused_status', 'canceled', [] );
	}

	public function test_on_subscription_synced_dedupes_replays(): void {
		// Calling the sync action twice for the same Stripe sub should send
		// the welcome email exactly once — replays must not double-send.
		$this->sender->expects( $this->once() )->method( 'send' );

		$this->mock_local_subscription_lookup( 7, 'replay_buyer@example.com' );

		$payload = [ 'created' => time() ];
		$this->listener->on_subscription_synced( 'sub_replay_test', 'active', $payload );
		$this->listener->on_subscription_synced( 'sub_replay_test', 'active', $payload );
	}

	public function test_on_subscription_synced_sends_for_delayed_webhook(): void {
		// Stripe-side `created` is hours old (slow webhook delivery or backfill),
		// but we have never sent the welcome email for this sub → must send.
		$this->sender->expects( $this->once() )->method( 'send' );

		$this->mock_local_subscription_lookup( 11, 'delayed@example.com' );

		$this->listener->on_subscription_synced(
			'sub_delayed_test',
			'active',
			[ 'created' => time() - ( 6 * HOUR_IN_SECONDS ) ]
		);
	}

	/**
	 * Stub the resolver lookup chain Payment_Email_Listener uses to load a
	 * local subscription record and build its merge-tag context.
	 *
	 * @param int    $local_id The local subscription ID the lookup should return.
	 * @param string $email    The customer email returned in the resolved context.
	 */
	private function mock_local_subscription_lookup( int $local_id, string $email ): void {
		$this->resolver->method( 'get_local_subscription_id' )
			->willReturn( $local_id );
		$this->resolver->method( 'resolve_subscription_context' )
			->with( $local_id )
			->willReturn(
				[
					'customer_email' => $email,
					'customer_name'  => 'Tester',
				]
			);
	}

	public function test_on_invoice_paid_skips_initial_invoice(): void {
		$this->sender->expects( $this->never() )->method( 'send' );

		$this->listener->on_invoice_paid( 1, [ 'billing_reason' => 'subscription_create' ] );
	}

	public function test_on_invoice_paid_sends_for_renewal(): void {
		$this->resolver->method( 'resolve_subscription_context' )
			->willReturn( [ 'customer_email' => 'sub@example.com' ] );
		$this->resolver->method( 'resolve_invoice_context' )
			->willReturn( [ 'invoice_amount' => '$9.99' ] );

		$this->sender->expects( $this->once() )
			->method( 'send' )
			->with(
				'subscription_renewed',
				'sub@example.com',
				$this->anything()
			);

		$this->listener->on_invoice_paid(
			1,
			[
				'billing_reason' => 'subscription_cycle',
				'amount_paid'    => 999,
				'currency'       => 'usd',
			]
		);
	}

	public function test_on_payment_failed_sends_email(): void {
		$this->resolver->method( 'resolve_subscription_context' )
			->willReturn( [ 'customer_email' => 'fail@example.com' ] );
		$this->resolver->method( 'resolve_invoice_context' )
			->willReturn( [ 'invoice_amount' => '$19.99' ] );

		$this->sender->expects( $this->once() )
			->method( 'send' )
			->with(
				'payment_failed',
				'fail@example.com',
				$this->anything()
			);

		$this->listener->on_payment_failed(
			1,
			[
				'amount_due' => 1999,
				'currency'   => 'usd',
			]
		);
	}

	public function test_refund_deduplication(): void {
		$this->resolver->method( 'resolve_refund_context' )
			->willReturn(
				[
					'customer_email'  => 'refund@example.com',
					'refunded_amount' => '$5.00',
				]
			);
		// REST handler fires with delta; the listener must normalize to cumulative.
		$this->resolver->method( 'get_cumulative_refunded' )
			->with( 1 )
			->willReturn( 500 );

		// Should only send once despite two calls.
		$this->sender->expects( $this->once() )
			->method( 'send' )
			->with(
				'refund_processed',
				'refund@example.com',
				$this->anything()
			);

		$this->listener->on_refund_processed( 1, 500, [] );
		$this->listener->on_refund_issued( 1, 500, 'refunded' );
	}

	public function test_two_distinct_refunds_send_two_emails(): void {
		// Scenario: two consecutive partial refunds of $5 each on the same order.
		// Webhook path passes cumulative ($amount_refunded), REST path passes delta ($amount).
		// Without normalization, the second refund's REST-path email is silently
		// suppressed by the transient set during the first refund.
		$this->resolver->method( 'resolve_refund_context' )
			->willReturn(
				[
					'customer_email'  => 'refund@example.com',
					'refunded_amount' => '$5.00',
				]
			);

		// After refund 1 the order's cumulative is 500; after refund 2 it is 1000.
		$this->resolver->method( 'get_cumulative_refunded' )
			->willReturnOnConsecutiveCalls( 500, 1000 );

		// Refund 1 and refund 2 each produce exactly one email.
		$this->sender->expects( $this->exactly( 2 ) )
			->method( 'send' )
			->with(
				'refund_processed',
				'refund@example.com',
				$this->anything()
			);

		// Refund 1: REST (delta=500) then webhook (cumulative=500).
		$this->listener->on_refund_issued( 1, 500, 'partial_refund' );
		$this->listener->on_refund_processed( 1, 500, [] );

		// Refund 2: REST (delta=500) then webhook (cumulative=1000).
		$this->listener->on_refund_issued( 1, 500, 'refunded' );
		$this->listener->on_refund_processed( 1, 1000, [] );
	}
}
