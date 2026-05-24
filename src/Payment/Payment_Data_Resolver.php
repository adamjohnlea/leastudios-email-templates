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
	 * Look up the local subscription ID for a given Stripe subscription ID.
	 *
	 * Wraps Subscription_Repository so the listener doesn't construct it
	 * inline and so tests can mock the lookup without a real database.
	 *
	 * @param string $stripe_sub_id The Stripe subscription ID.
	 * @return int|null Local subscription ID, or null if unknown.
	 */
	public function get_local_subscription_id( string $stripe_sub_id ): ?int {
		$local = $this->subscriptions->get_by_stripe_id( $stripe_sub_id );

		if ( null === $local || ! isset( $local->id ) ) {
			return null;
		}

		return (int) $local->id;
	}

	/**
	 * Get the cumulative refunded amount currently recorded on an order.
	 *
	 * Returns the integer value stored in `orders.refunded_amount` (smallest
	 * currency unit), or null if the order is missing. Used by the listener
	 * to normalize the REST refund path — which fires with the *delta* amount —
	 * onto the cumulative key the webhook path already uses for dedupe.
	 *
	 * @param int $order_id The local order ID.
	 * @return int|null Cumulative refunded amount, or null if order not found.
	 */
	public function get_cumulative_refunded( int $order_id ): ?int {
		$order = $this->orders->get( $order_id );

		if ( null === $order ) {
			return null;
		}

		return (int) ( $order->refunded_amount ?? 0 );
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

		$timestamp = strtotime( $date_string );

		if ( false === $timestamp ) {
			return '';
		}

		$format    = get_option( 'date_format', 'F j, Y' );
		$formatted = wp_date( is_string( $format ) ? $format : 'F j, Y', $timestamp );

		return false !== $formatted ? $formatted : '';
	}

	/**
	 * Try to resolve product name from subscription data.
	 *
	 * Filters this customer's subscription orders by the subscription's
	 * current `stripe_price_id` so a customer with multiple subscriptions
	 * gets the right product name rather than whichever order was most
	 * recent.
	 *
	 * @param object $sub The subscription record.
	 * @return string
	 */
	private function resolve_product_from_subscription( object $sub ): string {
		if ( empty( $sub->stripe_customer_id ) || empty( $sub->stripe_price_id ) ) {
			return '';
		}

		global $wpdb;
		$table = $wpdb->prefix . 'leastudios_payments_orders';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$orders = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT line_items_json FROM %i WHERE stripe_customer_id = %s AND order_type = 'subscription' ORDER BY id DESC",
				$table,
				$sub->stripe_customer_id
			)
		);

		if ( empty( $orders ) ) {
			return '';
		}

		$json_strings = array_map(
			static fn( object $row ): string => (string) ( $row->line_items_json ?? '[]' ),
			$orders
		);

		return self::find_product_name_for_price( $json_strings, (string) $sub->stripe_price_id );
	}

	/**
	 * Scan a list of `line_items_json` strings (most-recent-first) and return
	 * the description of the first line item whose `price_id` matches.
	 *
	 * Pure helper — no database access — so it can be unit-tested without the
	 * payments plugin loaded. Used by `resolve_product_from_subscription`
	 * to pick the right product when a customer has multiple subscription
	 * orders.
	 *
	 * @param array<int, string> $line_items_json_rows Raw `line_items_json` strings.
	 * @param string             $stripe_price_id      The Stripe price ID to match.
	 * @return string Matching item description, or '' if no match.
	 */
	public static function find_product_name_for_price( array $line_items_json_rows, string $stripe_price_id ): string {
		foreach ( $line_items_json_rows as $json ) {
			$items = json_decode( $json, true );

			if ( ! is_array( $items ) ) {
				continue;
			}

			foreach ( $items as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}

				if ( ( $item['price_id'] ?? '' ) === $stripe_price_id ) {
					return (string) ( $item['description'] ?? '' );
				}
			}
		}

		return '';
	}
}
