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
	 * @param int   $order_id     The local order ID.
	 * @param array $session_data The Stripe session data.
	 * @return void
	 */
	public function on_order_created( int $order_id, array $session_data ): void {
		$context = $this->resolver->resolve_order_context( $order_id );

		if ( empty( $context['customer_email'] ) ) {
			return;
		}

		$this->sender->send( Email_Type::PAYMENT_RECEIPT, $context['customer_email'], $context );
	}

	/**
	 * Handle subscription sync — send subscription created email for new subscriptions only.
	 *
	 * @param string $stripe_sub_id The Stripe subscription ID.
	 * @param string $local_status  The mapped local status.
	 * @param array  $subscription  The full Stripe subscription object.
	 * @return void
	 */
	public function on_subscription_synced( string $stripe_sub_id, string $local_status, array $subscription ): void {
		// Only send for genuinely new subscriptions.
		if ( 'active' !== $local_status && 'trialing' !== $local_status ) {
			return;
		}

		// Check if the subscription was just created (within the last 60 seconds).
		$created_at = $subscription['created'] ?? 0;
		if ( abs( time() - (int) $created_at ) > 60 ) {
			return;
		}

		// Need to find the local subscription to get context.
		$sub_repo = new \LEAStudios\Payments\Database\Subscription_Repository();
		$local    = $sub_repo->get_by_stripe_id( $stripe_sub_id );

		if ( null === $local ) {
			return;
		}

		$context = $this->resolver->resolve_subscription_context( (int) $local->id );

		if ( empty( $context['customer_email'] ) ) {
			return;
		}

		// Add amount from the subscription's initial invoice if available.
		if ( ! empty( $subscription['items']['data'][0]['price']['unit_amount'] ) ) {
			$amount   = (int) $subscription['items']['data'][0]['price']['unit_amount'];
			$currency = $subscription['currency'] ?? 'usd';

			$context['amount']   = \LEAStudios\EmailTemplates\Email\Merge_Tag_Replacer::format_amount( $amount, $currency );
			$context['currency'] = strtoupper( $currency );
		}

		$this->sender->send( Email_Type::SUBSCRIPTION_CREATED, $context['customer_email'], $context );
	}

	/**
	 * Handle invoice paid — send renewal email (skip initial invoice).
	 *
	 * @param int   $subscription_id The local subscription ID.
	 * @param array $invoice         The Stripe invoice data.
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
	 * @param int   $subscription_id The local subscription ID.
	 * @param array $invoice         The Stripe invoice data.
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
	 * @param int   $order_id        The local order ID.
	 * @param int   $amount_refunded The total refunded amount.
	 * @param array $charge          The Stripe charge data.
	 * @return void
	 */
	public function on_refund_processed( int $order_id, int $amount_refunded, array $charge ): void {
		$this->send_refund_email( $order_id, $amount_refunded );
	}

	/**
	 * Handle admin refund — send refund email.
	 *
	 * @param int    $order_id   The local order ID.
	 * @param int    $amount     The refund amount.
	 * @param string $new_status The new payment status.
	 * @return void
	 */
	public function on_refund_issued( int $order_id, int $amount, string $new_status ): void {
		$this->send_refund_email( $order_id, $amount );
	}

	/**
	 * Send a refund email with deduplication.
	 *
	 * Both webhook and REST hooks can fire for the same refund.
	 * A transient lock prevents sending duplicate emails.
	 *
	 * @param int $order_id        The local order ID.
	 * @param int $refunded_amount The refunded amount.
	 * @return void
	 */
	private function send_refund_email( int $order_id, int $refunded_amount ): void {
		$lock_key = 'leastudios_email_templates_refund_sent_' . $order_id;

		if ( get_transient( $lock_key ) ) {
			return;
		}

		$context = $this->resolver->resolve_refund_context( $order_id, $refunded_amount );

		if ( empty( $context['customer_email'] ) ) {
			return;
		}

		set_transient( $lock_key, true, 30 );

		$this->sender->send( Email_Type::REFUND_PROCESSED, $context['customer_email'], $context );
	}
}
