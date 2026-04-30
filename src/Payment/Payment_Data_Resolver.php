<?php
/**
 * Resolves payment data into merge tag context arrays.
 *
 * @package LEAStudios\EmailTemplates\Payment
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Payment;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use LEAStudios\EmailTemplates\Email\Merge_Tag_Replacer;
use LEAStudios\Payments\Database\Order_Repository;
use LEAStudios\Payments\Database\Subscription_Repository;

/**
 * Builds merge tag context arrays from payment data.
 */
class Payment_Data_Resolver {

	/**
	 * The order repository.
	 *
	 * @var Order_Repository
	 */
	private Order_Repository $orders;

	/**
	 * The subscription repository.
	 *
	 * @var Subscription_Repository
	 */
	private Subscription_Repository $subscriptions;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->orders        = new Order_Repository();
		$this->subscriptions = new Subscription_Repository();
	}

	/**
	 * Resolve context for an order.
	 *
	 * @param int $order_id The local order ID.
	 * @return array<string, string> Merge tag context.
	 */
	public function resolve_order_context( int $order_id ): array {
		$order = $this->orders->get( $order_id );

		if ( null === $order ) {
			return [];
		}

		$product_name = $this->extract_product_name( $order->line_items_json ?? '[]' );
		$order_type   = 'subscription' === ( $order->order_type ?? '' )
			? __( 'Subscription', 'leastudios-email-templates' )
			: __( 'One-time payment', 'leastudios-email-templates' );

		return [
			'customer_name'  => $order->customer_name ?? '',
			'customer_email' => $order->customer_email ?? '',
			'amount'         => Merge_Tag_Replacer::format_amount( (int) ( $order->amount_total ?? 0 ), $order->currency ?? 'usd' ),
			'currency'       => strtoupper( $order->currency ?? 'USD' ),
			'product_name'   => $product_name,
			'order_type'     => $order_type,
			'payment_id'     => $order->stripe_payment_intent_id ?? '',
			'order_id'       => (string) $order_id,
			'payment_status' => ucfirst( $order->payment_status ?? 'paid' ),
		];
	}

	/**
	 * Resolve context for a subscription.
	 *
	 * @param int $subscription_id The local subscription ID.
	 * @return array<string, string> Merge tag context.
	 */
	public function resolve_subscription_context( int $subscription_id ): array {
		$sub = $this->subscriptions->get( $subscription_id );

		if ( null === $sub ) {
			return [];
		}

		// Resolve customer name from WP user if available.
		$customer_name = '';
		if ( ! empty( $sub->wp_user_id ) ) {
			$user = get_userdata( (int) $sub->wp_user_id );
			if ( $user ) {
				$customer_name = $user->display_name;
			}
		}

		if ( '' === $customer_name ) {
			$customer_name = $sub->customer_email ?? '';
		}

		// Try to get product name from price ID via the order.
		$product_name = $this->resolve_product_from_subscription( $sub );

		$period_end = '';
		if ( ! empty( $sub->current_period_end ) ) {
			$formatted  = wp_date( get_option( 'date_format', 'F j, Y' ), strtotime( $sub->current_period_end ) );
			$period_end = false !== $formatted ? $formatted : '';
		}

		return [
			'customer_name'       => $customer_name,
			'customer_email'      => $sub->customer_email ?? '',
			'subscription_id'     => (string) $subscription_id,
			'subscription_status' => ucfirst( $sub->status ?? '' ),
			'period_end'          => $period_end,
			'period_start'        => $this->format_date( $sub->current_period_start ?? '' ),
			'product_name'        => $product_name,
		];
	}

	/**
	 * Resolve context from a Stripe invoice.
	 *
	 * @param array<string, mixed> $invoice The Stripe invoice data.
	 * @return array<string, string> Merge tag context.
	 */
	public function resolve_invoice_context( array $invoice ): array {
		$amount   = (int) ( $invoice['amount_paid'] ?? $invoice['amount_due'] ?? 0 );
		$currency = $invoice['currency'] ?? 'usd';

		return [
			'invoice_amount' => Merge_Tag_Replacer::format_amount( $amount, $currency ),
			'currency'       => strtoupper( $currency ),
		];
	}

	/**
	 * Resolve context for a refund.
	 *
	 * @param int $order_id        The local order ID.
	 * @param int $refunded_amount The refunded amount in smallest currency unit.
	 * @return array<string, string> Merge tag context.
	 */
	public function resolve_refund_context( int $order_id, int $refunded_amount ): array {
		$order_context = $this->resolve_order_context( $order_id );

		$currency = 'usd';
		$order    = $this->orders->get( $order_id );
		if ( null !== $order ) {
			$currency = $order->currency ?? 'usd';
		}

		$order_context['refunded_amount'] = Merge_Tag_Replacer::format_amount( $refunded_amount, $currency );

		return $order_context;
	}

	/**
	 * Extract the first product name from line items JSON.
	 *
	 * @param string $json The line items JSON string.
	 * @return string The product name or empty string.
	 */
	private function extract_product_name( string $json ): string {
		$items = json_decode( $json, true );

		if ( ! is_array( $items ) || empty( $items ) ) {
			return '';
		}

		return $items[0]['description'] ?? '';
	}

	/**
	 * Format a date string.
	 *
	 * @param string $date_string The date string to format.
	 * @return string Formatted date or empty string.
	 */
	private function format_date( string $date_string ): string {
		if ( '' === $date_string ) {
			return '';
		}

		$formatted = wp_date( get_option( 'date_format', 'F j, Y' ), strtotime( $date_string ) );

		return false !== $formatted ? $formatted : '';
	}

	/**
	 * Try to resolve product name from subscription data.
	 *
	 * @param object $sub The subscription record.
	 * @return string
	 */
	private function resolve_product_from_subscription( object $sub ): string {
		// Look for an order with this subscription's customer to get the product name.
		if ( ! empty( $sub->stripe_customer_id ) ) {
			global $wpdb;
			$table = $wpdb->prefix . 'leastudios_payments_orders';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$order = $wpdb->get_row(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT line_items_json FROM {$table} WHERE stripe_customer_id = %s AND order_type = 'subscription' ORDER BY id DESC LIMIT 1",
					$sub->stripe_customer_id
				)
			);

			if ( $order && ! empty( $order->line_items_json ) ) {
				return $this->extract_product_name( $order->line_items_json );
			}
		}

		return '';
	}
}
