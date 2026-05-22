<?php
/**
 * Listens to payment events and triggers transactional emails.
 *
 * @package LEAStudios\EmailTemplates\Payment
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Payment;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use LEAStudios\EmailTemplates\Email\Email_Sender;
use LEAStudios\EmailTemplates\Email\Email_Type;

/**
 * Hooks payment actions and dispatches emails.
 */
class Payment_Email_Listener {

	/**
	 * Constructor.
	 *
	 * @param Email_Sender          $sender   The email sender.
	 * @param Payment_Data_Resolver $resolver The data resolver.
	 */
	public function __construct(
		private readonly Email_Sender $sender,
		private readonly Payment_Data_Resolver $resolver,
	) {}

	/**
	 * Register all payment hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'leastudios_payments_order_created', [ $this, 'on_order_created' ], 10, 2 );
		add_action( 'leastudios_payments_subscription_synced', [ $this, 'on_subscription_synced' ], 10, 3 );
		add_action( 'leastudios_payments_subscription_invoice_paid', [ $this, 'on_invoice_paid' ], 10, 2 );
		add_action( 'leastudios_payments_subscription_payment_failed', [ $this, 'on_payment_failed' ], 10, 2 );
		add_action( 'leastudios_payments_webhook_refund_processed', [ $this, 'on_refund_processed' ], 10, 3 );
		add_action( 'leastudios_payments_refund_issued', [ $this, 'on_refund_issued' ], 10, 3 );
	}

	/**
	 * Handle order creation — send payment receipt.
	 *
	 * @param int                  $order_id     The local order ID.
	 * @param array<string, mixed> $session_data The Stripe session data. Required by the action signature; the resolver re-derives everything from `$order_id`.
	 * @return void
	 */
	public function on_order_created( int $order_id, array $session_data ): void {
		unset( $session_data ); // Required by the leastudios_payments_order_created action signature; not consulted.
		$context = $this->resolver->resolve_order_context( $order_id );

		if ( empty( $context['customer_email'] ) ) {
			return;
		}

		$this->sender->send( Email_Type::PAYMENT_RECEIPT, $context['customer_email'], $context );
	}

	/**
	 * Handle subscription sync — send the welcome email exactly once per
	 * Stripe subscription, the first time we see it in an active/trialing
	 * state.
	 *
	 * The `subscription_synced` action fires on many lifecycle events
	 * (creation, renewal, status changes), so we use a persistent per-sub
	 * flag rather than a time window: this avoids dropping the welcome email
	 * for delayed webhooks and prevents duplicates on replays.
	 *
	 * @param string               $stripe_sub_id The Stripe subscription ID.
	 * @param string               $local_status  The mapped local status.
	 * @param array<string, mixed> $subscription  The full Stripe subscription object.
	 * @return void
	 */
	public function on_subscription_synced( string $stripe_sub_id, string $local_status, array $subscription ): void {
		if ( 'active' !== $local_status && 'trialing' !== $local_status ) {
			return;
		}

		$flag_key = 'leastudios_email_templates_welcomed_' . md5( $stripe_sub_id );

		if ( get_option( $flag_key ) ) {
			return;
		}

		$local_id = $this->resolver->get_local_subscription_id( $stripe_sub_id );

		if ( null === $local_id ) {
			return;
		}

		$context = $this->resolver->resolve_subscription_context( $local_id );

		if ( empty( $context['customer_email'] ) ) {
			return;
		}

		if ( ! empty( $subscription['items']['data'][0]['price']['unit_amount'] ) ) {
			$amount   = (int) $subscription['items']['data'][0]['price']['unit_amount'];
			$currency = $subscription['currency'] ?? 'usd';

			$context['amount']   = \LEAStudios\EmailTemplates\Email\Merge_Tag_Replacer::format_amount( $amount, $currency );
			$context['currency'] = strtoupper( $currency );
		}

		// Mark *before* sending so a synchronous re-entry (e.g. a hook chain
		// firing `subscription_synced` again inside `wp_mail`) can't slip
		// through. A failed send still leaves the flag set — preferable to
		// risking duplicate welcome emails for transient SMTP errors.
		update_option( $flag_key, time(), false );

		$this->sender->send( Email_Type::SUBSCRIPTION_CREATED, $context['customer_email'], $context );
	}

	/**
	 * Handle invoice paid — send renewal email (skip initial invoice).
	 *
	 * @param int                  $subscription_id The local subscription ID.
	 * @param array<string, mixed> $invoice         The Stripe invoice data.
	 * @return void
	 */
	public function on_invoice_paid( int $subscription_id, array $invoice ): void {
		// Only send for renewal invoices, not the initial payment.
		$billing_reason = $invoice['billing_reason'] ?? '';
		if ( 'subscription_cycle' !== $billing_reason ) {
			return;
		}

		$context = $this->resolver->resolve_subscription_context( $subscription_id );
		$context = array_merge( $context, $this->resolver->resolve_invoice_context( $invoice ) );

		if ( empty( $context['customer_email'] ) ) {
			return;
		}

		$this->sender->send( Email_Type::SUBSCRIPTION_RENEWED, $context['customer_email'], $context );
	}

	/**
	 * Handle payment failure.
	 *
	 * @param int                  $subscription_id The local subscription ID.
	 * @param array<string, mixed> $invoice         The Stripe invoice data.
	 * @return void
	 */
	public function on_payment_failed( int $subscription_id, array $invoice ): void {
		$context = $this->resolver->resolve_subscription_context( $subscription_id );
		$context = array_merge( $context, $this->resolver->resolve_invoice_context( $invoice ) );

		if ( empty( $context['customer_email'] ) ) {
			return;
		}

		$this->sender->send( Email_Type::PAYMENT_FAILED, $context['customer_email'], $context );
	}

	/**
	 * Handle refund webhook — send refund email.
	 *
	 * The webhook action passes the cumulative refunded total from the Stripe
	 * charge object, so this is already the canonical dedupe key value.
	 *
	 * @param int                  $order_id        The local order ID.
	 * @param int                  $amount_refunded Cumulative refunded amount (from `$charge['amount_refunded']`).
	 * @param array<string, mixed> $charge          The Stripe charge data. Required by the action signature; not consulted here.
	 * @return void
	 */
	public function on_refund_processed( int $order_id, int $amount_refunded, array $charge ): void {
		unset( $charge ); // Required by the leastudios_payments_refund_processed action signature; not consulted.
		$this->send_refund_email( $order_id, $amount_refunded );
	}

	/**
	 * Handle admin refund — send refund email.
	 *
	 * The REST refund action fires with the *delta* amount of this single
	 * refund, but the order's `refunded_amount` has already been updated to
	 * the new cumulative total before the action runs (see
	 * `LEAStudios\Payments\REST\Refund_Controller`). Reading the order gives
	 * us the same cumulative key the webhook path uses, so the two paths
	 * actually dedupe against each other.
	 *
	 * @param int    $order_id   The local order ID.
	 * @param int    $amount     Delta amount for this single refund (unused — superseded by the cumulative lookup).
	 * @param string $new_status The new payment status. Required by the action signature; not consulted here.
	 * @return void
	 */
	public function on_refund_issued( int $order_id, int $amount, string $new_status ): void {
		unset( $amount, $new_status ); // Required by the action signature; we use the cumulative from the order record instead.

		$cumulative = $this->resolver->get_cumulative_refunded( $order_id );

		if ( null === $cumulative ) {
			return;
		}

		$this->send_refund_email( $order_id, $cumulative );
	}

	/**
	 * Send a refund email with deduplication.
	 *
	 * Both webhook and REST hooks can fire for the same refund. The dedupe
	 * key uses the cumulative refunded amount, so a partial-then-full refund
	 * flow sends two distinct emails (one per cumulative state) while a
	 * webhook redelivery for the same cumulative still dedupes.
	 *
	 * @param int $order_id            The local order ID.
	 * @param int $cumulative_refunded Cumulative refunded amount in smallest currency unit.
	 * @return void
	 */
	private function send_refund_email( int $order_id, int $cumulative_refunded ): void {
		$lock_key = sprintf( 'leastudios_email_templates_refund_sent_%d_%d', $order_id, $cumulative_refunded );

		if ( get_transient( $lock_key ) ) {
			return;
		}

		$context = $this->resolver->resolve_refund_context( $order_id, $cumulative_refunded );

		if ( empty( $context['customer_email'] ) ) {
			return;
		}

		// 10-minute window: long enough for a webhook to be retried after a
		// slow request, short enough that distinct refunds (which produce a
		// different cumulative key) are unlikely to collide.
		set_transient( $lock_key, true, 10 * MINUTE_IN_SECONDS );

		$this->sender->send( Email_Type::REFUND_PROCESSED, $context['customer_email'], $context );
	}
}
