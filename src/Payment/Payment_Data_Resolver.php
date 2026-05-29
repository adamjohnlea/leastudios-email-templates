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

/**
 * Builds merge tag context arrays from payment data.
 *
 * This class never references leastudios-payments' classes directly. It reads
 * order/subscription context through the public `leastudios_payments_*` filters
 * that the payments plugin answers, so this plugin can lint, test, and package
 * without the payments repository present. When payments is inactive no one
 * answers the filters, the resolver receives its `null` default, and the
 * resolve methods return their empty/default shapes.
 *
 * The two array shapes below mirror the contract documented on the producer
 * side (`LEAStudios\Payments\Support\Email_Context_Provider`). They are the
 * static-typing seam that replaces cross-repo PHPStan scanning; `ContractTest`
 * asserts the runtime shape matches.
 *
 * @phpstan-type OrderEmailContext array{
 *     customer_name: string,
 *     customer_email: string,
 *     amount_total: int,
 *     currency: string,
 *     line_items_json: string,
 *     order_type: string,
 *     stripe_payment_intent_id: string,
 *     payment_status: string,
 *     refunded_amount: int
 * }
 * @phpstan-type SubscriptionEmailContext array{
 *     customer_email: string,
 *     wp_user_id: int,
 *     status: string,
 *     current_period_start: string,
 *     current_period_end: string,
 *     product_name: string
 * }
 */
class Payment_Data_Resolver {

	/**
	 * Resolve context for an order.
	 *
	 * @param int $order_id The local order ID.
	 * @return array<string, string> Merge tag context.
	 */
	public function resolve_order_context( int $order_id ): array {
		$order = $this->fetch_order( $order_id );

		if ( null === $order ) {
			return [];
		}

		$product_name = $this->extract_product_name( $order['line_items_json'] );
		$order_type   = 'subscription' === $order['order_type']
			? __( 'Subscription', 'leastudios-email-templates' )
			: __( 'One-time payment', 'leastudios-email-templates' );

		return [
			'customer_name'  => $order['customer_name'],
			'customer_email' => $order['customer_email'],
			'amount'         => Merge_Tag_Replacer::format_amount( $order['amount_total'], $order['currency'] ),
			'currency'       => strtoupper( '' !== $order['currency'] ? $order['currency'] : 'usd' ),
			'product_name'   => $product_name,
			'order_type'     => $order_type,
			'payment_id'     => $order['stripe_payment_intent_id'],
			'order_id'       => (string) $order_id,
			'payment_status' => ucfirst( '' !== $order['payment_status'] ? $order['payment_status'] : 'paid' ),
		];
	}

	/**
	 * Resolve context for a subscription.
	 *
	 * @param int $subscription_id The local subscription ID.
	 * @return array<string, string> Merge tag context.
	 */
	public function resolve_subscription_context( int $subscription_id ): array {
		$sub = $this->fetch_subscription( $subscription_id );

		if ( null === $sub ) {
			return [];
		}

		// Resolve customer name from WP user if available.
		$customer_name = '';
		if ( 0 !== $sub['wp_user_id'] ) {
			$user = get_userdata( $sub['wp_user_id'] );
			if ( $user ) {
				$customer_name = $user->display_name;
			}
		}

		if ( '' === $customer_name ) {
			$customer_name = $sub['customer_email'];
		}

		$period_end = $this->format_date( $sub['current_period_end'] );

		return [
			'customer_name'       => $customer_name,
			'customer_email'      => $sub['customer_email'],
			'subscription_id'     => (string) $subscription_id,
			'subscription_status' => ucfirst( $sub['status'] ),
			'period_end'          => $period_end,
			'period_start'        => $this->format_date( $sub['current_period_start'] ),
			'product_name'        => $sub['product_name'],
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
	 * Delegates to the payments plugin via the
	 * `leastudios_payments_local_subscription_id` filter so the listener
	 * doesn't need to know how the lookup happens and so tests can mock it
	 * without a real database.
	 *
	 * @param string $stripe_sub_id The Stripe subscription ID.
	 * @return int|null Local subscription ID, or null if unknown.
	 */
	public function get_local_subscription_id( string $stripe_sub_id ): ?int {
		/**
		 * Filters the local subscription ID for a Stripe subscription ID.
		 *
		 * Answered by leastudios-payments. Returns the unchanged default
		 * (`null`) when payments is inactive or the ID is unknown.
		 *
		 * @param int|null $local_id      The local subscription ID, or null.
		 * @param string   $stripe_sub_id The Stripe subscription ID.
		 */
		$local_id = apply_filters( 'leastudios_payments_local_subscription_id', null, $stripe_sub_id );

		return is_int( $local_id ) ? $local_id : null;
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
		$order = $this->fetch_order( $order_id );

		if ( null === $order ) {
			return null;
		}

		return $order['refunded_amount'];
	}

	/**
	 * Resolve context for a refund.
	 *
	 * @param int $order_id        The local order ID.
	 * @param int $refunded_amount The refunded amount in smallest currency unit.
	 * @return array<string, string> Merge tag context.
	 */
	public function resolve_refund_context( int $order_id, int $refunded_amount ): array {
		$order = $this->fetch_order( $order_id );

		if ( null === $order ) {
			return [];
		}

		$order_context = $this->resolve_order_context( $order_id );

		$currency                         = '' !== $order['currency'] ? $order['currency'] : 'usd';
		$order_context['refunded_amount'] = Merge_Tag_Replacer::format_amount( $refunded_amount, $currency );

		return $order_context;
	}

	/**
	 * Fetch an order's plain context array from the payments plugin.
	 *
	 * @param int $order_id The local order ID.
	 * @return array|null The order context, or null when unavailable.
	 *
	 * @phpstan-return OrderEmailContext|null
	 */
	private function fetch_order( int $order_id ): ?array {
		/**
		 * Filters the order email context array.
		 *
		 * Answered by leastudios-payments. Returns the unchanged default
		 * (`null`) when payments is inactive or the order is not found.
		 *
		 * @param array<string, mixed>|null $context  The order context, or null.
		 * @param int                       $order_id The local order ID.
		 */
		$context = apply_filters( 'leastudios_payments_order_email_context', null, $order_id );

		return is_array( $context ) ? $this->normalize_order( $context ) : null;
	}

	/**
	 * Fetch a subscription's plain context array from the payments plugin.
	 *
	 * @param int $subscription_id The local subscription ID.
	 * @return array|null The subscription context, or null when unavailable.
	 *
	 * @phpstan-return SubscriptionEmailContext|null
	 */
	private function fetch_subscription( int $subscription_id ): ?array {
		/**
		 * Filters the subscription email context array.
		 *
		 * Answered by leastudios-payments. Returns the unchanged default
		 * (`null`) when payments is inactive or the subscription is missing.
		 *
		 * @param array<string, mixed>|null $context         The subscription context, or null.
		 * @param int                       $subscription_id The local subscription ID.
		 */
		$context = apply_filters( 'leastudios_payments_subscription_email_context', null, $subscription_id );

		return is_array( $context ) ? $this->normalize_subscription( $context ) : null;
	}

	/**
	 * Coerce a raw order context array into the strict OrderEmailContext shape.
	 *
	 * @param array<string, mixed> $context Raw context from the filter.
	 * @return array The normalized order context.
	 *
	 * @phpstan-return OrderEmailContext
	 */
	private function normalize_order( array $context ): array {
		return [
			'customer_name'            => (string) ( $context['customer_name'] ?? '' ),
			'customer_email'           => (string) ( $context['customer_email'] ?? '' ),
			'amount_total'             => (int) ( $context['amount_total'] ?? 0 ),
			'currency'                 => (string) ( $context['currency'] ?? '' ),
			'line_items_json'          => (string) ( $context['line_items_json'] ?? '[]' ),
			'order_type'               => (string) ( $context['order_type'] ?? '' ),
			'stripe_payment_intent_id' => (string) ( $context['stripe_payment_intent_id'] ?? '' ),
			'payment_status'           => (string) ( $context['payment_status'] ?? '' ),
			'refunded_amount'          => (int) ( $context['refunded_amount'] ?? 0 ),
		];
	}

	/**
	 * Coerce a raw subscription context array into the strict shape.
	 *
	 * @param array<string, mixed> $context Raw context from the filter.
	 * @return array The normalized subscription context.
	 *
	 * @phpstan-return SubscriptionEmailContext
	 */
	private function normalize_subscription( array $context ): array {
		return [
			'customer_email'       => (string) ( $context['customer_email'] ?? '' ),
			'wp_user_id'           => (int) ( $context['wp_user_id'] ?? 0 ),
			'status'               => (string) ( $context['status'] ?? '' ),
			'current_period_start' => (string) ( $context['current_period_start'] ?? '' ),
			'current_period_end'   => (string) ( $context['current_period_end'] ?? '' ),
			'product_name'         => (string) ( $context['product_name'] ?? '' ),
		];
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
	 * Scan a list of `line_items_json` strings (most-recent-first) and return
	 * the description of the first line item whose `price_id` matches.
	 *
	 * Pure helper — no database access — retained so existing tests and any
	 * external callers keep working. Product-name resolution for subscriptions
	 * now happens on the payments side and arrives via the subscription
	 * context's `product_name` key.
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
