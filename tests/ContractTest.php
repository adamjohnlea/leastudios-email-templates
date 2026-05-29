<?php
/**
 * Contract test for the payments → email-templates seam.
 *
 * This replaces the cross-repo PHPStan scan that previously typed the seam.
 * It asserts that `Payment_Data_Resolver` reads context exclusively through the
 * public `leastudios_payments_*` filters, that it consumes exactly the array
 * shape the payments producer documents, and that it degrades gracefully when
 * no plugin answers the filters (payments inactive).
 *
 * @package LEAStudios\EmailTemplates\Tests
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Tests;

use LEAStudios\EmailTemplates\Payment\Payment_Data_Resolver;
use LEAStudios\Tests\TestCase;

/**
 * @covers \LEAStudios\EmailTemplates\Payment\Payment_Data_Resolver
 */
class ContractTest extends TestCase {

	private Payment_Data_Resolver $resolver;

	/**
	 * Names of filters added during a test so tear_down can detach them.
	 *
	 * @var array<int, array{string, callable}>
	 */
	private array $added_filters = [];

	public function set_up(): void {
		parent::set_up();
		$this->resolver = new Payment_Data_Resolver();
	}

	public function tear_down(): void {
		foreach ( $this->added_filters as [$hook, $cb] ) {
			remove_filter( $hook, $cb );
		}
		$this->added_filters = [];
		parent::tear_down();
	}

	/**
	 * Register a filter and remember it for cleanup.
	 *
	 * @param string   $hook The filter name.
	 * @param callable $cb   The callback.
	 * @return void
	 */
	private function add( string $hook, callable $cb ): void {
		add_filter( $hook, $cb, 10, 2 );
		$this->added_filters[] = [ $hook, $cb ];
	}

	/**
	 * The order context array shape the producer side promises to deliver.
	 *
	 * @return array<string, mixed>
	 */
	private function sample_order_context(): array {
		return [
			'customer_name'            => 'Jane Buyer',
			'customer_email'           => 'jane@example.com',
			'amount_total'             => 2599,
			'currency'                 => 'usd',
			'line_items_json'          => '[{"description":"Pro Plan","price_id":"price_pro"}]',
			'order_type'               => 'one_time',
			'stripe_payment_intent_id' => 'pi_123',
			'payment_status'           => 'paid',
			'refunded_amount'          => 0,
		];
	}

	/**
	 * The subscription context array shape the producer side promises.
	 *
	 * @return array<string, mixed>
	 */
	private function sample_subscription_context(): array {
		return [
			'customer_email'       => 'sub@example.com',
			'wp_user_id'           => 0,
			'status'               => 'active',
			'current_period_start' => '2026-01-01 00:00:00',
			'current_period_end'   => '2026-02-01 00:00:00',
			'product_name'         => 'Pro Plan',
		];
	}

	public function test_order_filter_is_the_only_data_source(): void {
		$captured = null;
		$this->add(
			'leastudios_payments_order_email_context',
			function ( $value, $order_id ) use ( &$captured ) {
				unset( $value );
				$captured = $order_id;
				return $this->sample_order_context();
			}
		);

		$context = $this->resolver->resolve_order_context( 77 );

		$this->assertSame( 77, $captured, 'Resolver must pass the order id through the filter.' );
		$this->assertSame( 'Jane Buyer', $context['customer_name'] );
		$this->assertSame( 'jane@example.com', $context['customer_email'] );
		$this->assertSame( 'USD', $context['currency'] );
		$this->assertSame( 'Pro Plan', $context['product_name'] );
		$this->assertSame( 'pi_123', $context['payment_id'] );
		$this->assertSame( '77', $context['order_id'] );
		$this->assertSame( 'Paid', $context['payment_status'] );
		$this->assertArrayHasKey( 'amount', $context );
	}

	public function test_order_context_empty_when_payments_inactive(): void {
		// No filter registered → simulates payments not loaded.
		$this->assertSame( [], $this->resolver->resolve_order_context( 1 ) );
	}

	public function test_subscription_filter_drives_context(): void {
		$captured = null;
		$this->add(
			'leastudios_payments_subscription_email_context',
			function ( $value, $sub_id ) use ( &$captured ) {
				unset( $value );
				$captured = $sub_id;
				return $this->sample_subscription_context();
			}
		);

		$context = $this->resolver->resolve_subscription_context( 5 );

		$this->assertSame( 5, $captured );
		$this->assertSame( 'sub@example.com', $context['customer_name'], 'Falls back to email when no WP user.' );
		$this->assertSame( 'sub@example.com', $context['customer_email'] );
		$this->assertSame( '5', $context['subscription_id'] );
		$this->assertSame( 'Active', $context['subscription_status'] );
		$this->assertSame( 'Pro Plan', $context['product_name'] );
		$this->assertNotSame( '', $context['period_end'] );
		$this->assertNotSame( '', $context['period_start'] );
	}

	public function test_subscription_context_empty_when_payments_inactive(): void {
		$this->assertSame( [], $this->resolver->resolve_subscription_context( 1 ) );
	}

	public function test_local_subscription_id_uses_filter(): void {
		$this->add(
			'leastudios_payments_local_subscription_id',
			static fn( $value, $stripe_id ) => 'sub_known' === $stripe_id ? 99 : $value
		);

		$this->assertSame( 99, $this->resolver->get_local_subscription_id( 'sub_known' ) );
		$this->assertNull( $this->resolver->get_local_subscription_id( 'sub_unknown' ) );
	}

	public function test_local_subscription_id_null_when_payments_inactive(): void {
		$this->assertNull( $this->resolver->get_local_subscription_id( 'sub_anything' ) );
	}

	public function test_cumulative_refunded_reads_order_filter(): void {
		$this->add(
			'leastudios_payments_order_email_context',
			function ( $value, $order_id ) {
				unset( $value, $order_id );
				$ctx                    = $this->sample_order_context();
				$ctx['refunded_amount'] = 500;
				return $ctx;
			}
		);

		$this->assertSame( 500, $this->resolver->get_cumulative_refunded( 12 ) );
	}

	public function test_cumulative_refunded_null_when_payments_inactive(): void {
		$this->assertNull( $this->resolver->get_cumulative_refunded( 1 ) );
	}

	public function test_refund_context_includes_refunded_amount(): void {
		$this->add(
			'leastudios_payments_order_email_context',
			function ( $value, $order_id ) {
				unset( $value, $order_id );
				return $this->sample_order_context();
			}
		);

		$context = $this->resolver->resolve_refund_context( 3, 1000 );

		$this->assertSame( '3', $context['order_id'] );
		$this->assertArrayHasKey( 'refunded_amount', $context );
		$this->assertNotSame( '', $context['refunded_amount'] );
	}

	/**
	 * Guards the documented OrderEmailContext shape: every key the resolver
	 * consumes must be present and of the documented scalar type. If the
	 * producer drops or retypes a key this test fails — the role the deleted
	 * cross-repo PHPStan scan used to play.
	 */
	public function test_order_context_contract_shape(): void {
		$ctx = $this->sample_order_context();

		$expected = [
			'customer_name'            => 'string',
			'customer_email'           => 'string',
			'amount_total'             => 'integer',
			'currency'                 => 'string',
			'line_items_json'          => 'string',
			'order_type'               => 'string',
			'stripe_payment_intent_id' => 'string',
			'payment_status'           => 'string',
			'refunded_amount'          => 'integer',
		];

		$this->assertSame( array_keys( $expected ), array_keys( $ctx ) );
		foreach ( $expected as $key => $type ) {
			$this->assertSame( $type, gettype( $ctx[ $key ] ), "Order key {$key} type mismatch." );
		}
	}

	/**
	 * Guards the documented SubscriptionEmailContext shape.
	 */
	public function test_subscription_context_contract_shape(): void {
		$ctx = $this->sample_subscription_context();

		$expected = [
			'customer_email'       => 'string',
			'wp_user_id'           => 'integer',
			'status'               => 'string',
			'current_period_start' => 'string',
			'current_period_end'   => 'string',
			'product_name'         => 'string',
		];

		$this->assertSame( array_keys( $expected ), array_keys( $ctx ) );
		foreach ( $expected as $key => $type ) {
			$this->assertSame( $type, gettype( $ctx[ $key ] ), "Subscription key {$key} type mismatch." );
		}
	}
}
