<?php
/**
 * Tests for Payment_Email_Listener.
 *
 * @package LEAStudios\EmailTemplates\Tests
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Tests;

use LEAStudios\EmailTemplates\Email\Email_Sender;
use LEAStudios\EmailTemplates\Email\Email_Type;
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
				Email_Type::PAYMENT_RECEIPT,
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

		$this->listener->on_subscription_synced( 'sub_123', 'canceled', [] );
	}

	public function test_on_subscription_synced_skips_old_subscriptions(): void {
		$this->sender->expects( $this->never() )->method( 'send' );

		// Subscription created 2 minutes ago — not new.
		$this->listener->on_subscription_synced(
			'sub_123',
			'active',
			[ 'created' => time() - 120 ]
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
				Email_Type::SUBSCRIPTION_RENEWED,
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
				Email_Type::PAYMENT_FAILED,
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

		// Should only send once despite two calls.
		$this->sender->expects( $this->once() )
			->method( 'send' )
			->with(
				Email_Type::REFUND_PROCESSED,
				'refund@example.com',
				$this->anything()
			);

		$this->listener->on_refund_processed( 1, 500, [] );
		$this->listener->on_refund_issued( 1, 500, 'refunded' );
	}
}
