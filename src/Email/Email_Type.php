<?php
/**
 * Email type definitions.
 *
 * @package LEAStudios\EmailTemplates\Email
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Email;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Defines the transactional email types available.
 *
 * phpcs:disable PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- PHP 8.1 enums support $this.
 */
enum Email_Type: string {

	case PAYMENT_RECEIPT      = 'payment_receipt';
	case SUBSCRIPTION_CREATED = 'subscription_created';
	case SUBSCRIPTION_RENEWED = 'subscription_renewed';
	case PAYMENT_FAILED       = 'payment_failed';
	case REFUND_PROCESSED     = 'refund_processed';

	/**
	 * Get the display label.
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::PAYMENT_RECEIPT      => __( 'Payment Receipt', 'leastudios-email-templates' ),
			self::SUBSCRIPTION_CREATED => __( 'Subscription Created', 'leastudios-email-templates' ),
			self::SUBSCRIPTION_RENEWED => __( 'Subscription Renewed', 'leastudios-email-templates' ),
			self::PAYMENT_FAILED       => __( 'Payment Failed', 'leastudios-email-templates' ),
			self::REFUND_PROCESSED     => __( 'Refund Processed', 'leastudios-email-templates' ),
		};
	}

	/**
	 * Get the default subject line.
	 *
	 * @return string
	 */
	public function default_subject(): string {
		return match ( $this ) {
			self::PAYMENT_RECEIPT      => __( 'Your receipt for {product_name}', 'leastudios-email-templates' ),
			self::SUBSCRIPTION_CREATED => __( 'Subscription confirmed — {product_name}', 'leastudios-email-templates' ),
			self::SUBSCRIPTION_RENEWED => __( 'Payment received for {product_name}', 'leastudios-email-templates' ),
			self::PAYMENT_FAILED       => __( 'Payment failed for your subscription', 'leastudios-email-templates' ),
			self::REFUND_PROCESSED     => __( 'Your refund of {refunded_amount} has been processed', 'leastudios-email-templates' ),
		};
	}

	/**
	 * Get the default email body HTML.
	 *
	 * @return string
	 */
	public function default_body(): string {
		return match ( $this ) {
			self::PAYMENT_RECEIPT      => $this->receipt_body(),
			self::SUBSCRIPTION_CREATED => $this->subscription_created_body(),
			self::SUBSCRIPTION_RENEWED => $this->subscription_renewed_body(),
			self::PAYMENT_FAILED       => $this->payment_failed_body(),
			self::REFUND_PROCESSED     => $this->refund_body(),
		};
	}

	/**
	 * Get available merge tags for this email type.
	 *
	 * @return array<string, string> Tag => description.
	 */
	public function available_tags(): array {
		$common = [
			'{customer_name}'  => __( 'Customer name', 'leastudios-email-templates' ),
			'{customer_email}' => __( 'Customer email', 'leastudios-email-templates' ),
			'{site_name}'      => __( 'Site name', 'leastudios-email-templates' ),
			'{site_url}'       => __( 'Site URL', 'leastudios-email-templates' ),
			'{date}'           => __( 'Current date', 'leastudios-email-templates' ),
		];

		$specific = match ( $this ) {
			self::PAYMENT_RECEIPT => [
				'{amount}'       => __( 'Payment amount', 'leastudios-email-templates' ),
				'{currency}'     => __( 'Currency code', 'leastudios-email-templates' ),
				'{product_name}' => __( 'Product name', 'leastudios-email-templates' ),
				'{order_type}'   => __( 'Order type (one-time or subscription)', 'leastudios-email-templates' ),
				'{payment_id}'   => __( 'Stripe Payment Intent ID', 'leastudios-email-templates' ),
				'{order_id}'     => __( 'Local order ID', 'leastudios-email-templates' ),
			],
			self::SUBSCRIPTION_CREATED => [
				'{product_name}' => __( 'Product name', 'leastudios-email-templates' ),
				'{amount}'       => __( 'Payment amount', 'leastudios-email-templates' ),
				'{currency}'     => __( 'Currency code', 'leastudios-email-templates' ),
				'{period_end}'   => __( 'Current period end date', 'leastudios-email-templates' ),
			],
			self::SUBSCRIPTION_RENEWED => [
				'{product_name}'   => __( 'Product name', 'leastudios-email-templates' ),
				'{invoice_amount}' => __( 'Invoice amount', 'leastudios-email-templates' ),
				'{currency}'       => __( 'Currency code', 'leastudios-email-templates' ),
				'{period_end}'     => __( 'Next billing date', 'leastudios-email-templates' ),
			],
			self::PAYMENT_FAILED => [
				'{product_name}'   => __( 'Product name', 'leastudios-email-templates' ),
				'{invoice_amount}' => __( 'Invoice amount', 'leastudios-email-templates' ),
				'{currency}'       => __( 'Currency code', 'leastudios-email-templates' ),
			],
			self::REFUND_PROCESSED => [
				'{refunded_amount}' => __( 'Refund amount', 'leastudios-email-templates' ),
				'{amount}'          => __( 'Original payment amount', 'leastudios-email-templates' ),
				'{currency}'        => __( 'Currency code', 'leastudios-email-templates' ),
				'{product_name}'    => __( 'Product name', 'leastudios-email-templates' ),
				'{order_id}'        => __( 'Local order ID', 'leastudios-email-templates' ),
			],
		};

		return array_merge( $common, $specific );
	}

	/**
	 * Payment receipt body.
	 *
	 * @return string
	 */
	private function receipt_body(): string {
		return '<h2>' . __( 'Thank you for your purchase!', 'leastudios-email-templates' ) . '</h2>'
			. '<p>' . __( 'Hi {customer_name},', 'leastudios-email-templates' ) . '</p>'
			. '<p>' . __( 'We\'ve received your payment. Here are the details:', 'leastudios-email-templates' ) . '</p>'
			. '<table style="width:100%;border-collapse:collapse;margin:20px 0;">'
			. '<tr><td style="padding:8px;border:1px solid #e5e7eb;font-weight:bold;">' . __( 'Product', 'leastudios-email-templates' ) . '</td><td style="padding:8px;border:1px solid #e5e7eb;">{product_name}</td></tr>'
			. '<tr><td style="padding:8px;border:1px solid #e5e7eb;font-weight:bold;">' . __( 'Amount', 'leastudios-email-templates' ) . '</td><td style="padding:8px;border:1px solid #e5e7eb;">{amount}</td></tr>'
			. '<tr><td style="padding:8px;border:1px solid #e5e7eb;font-weight:bold;">' . __( 'Date', 'leastudios-email-templates' ) . '</td><td style="padding:8px;border:1px solid #e5e7eb;">{date}</td></tr>'
			. '<tr><td style="padding:8px;border:1px solid #e5e7eb;font-weight:bold;">' . __( 'Payment ID', 'leastudios-email-templates' ) . '</td><td style="padding:8px;border:1px solid #e5e7eb;">{payment_id}</td></tr>'
			. '</table>';
	}

	/**
	 * Subscription created body.
	 *
	 * @return string
	 */
	private function subscription_created_body(): string {
		return '<h2>' . __( 'Subscription confirmed!', 'leastudios-email-templates' ) . '</h2>'
			. '<p>' . __( 'Hi {customer_name},', 'leastudios-email-templates' ) . '</p>'
			. '<p>' . __( 'Your subscription to {product_name} is now active.', 'leastudios-email-templates' ) . '</p>'
			. '<table style="width:100%;border-collapse:collapse;margin:20px 0;">'
			. '<tr><td style="padding:8px;border:1px solid #e5e7eb;font-weight:bold;">' . __( 'Plan', 'leastudios-email-templates' ) . '</td><td style="padding:8px;border:1px solid #e5e7eb;">{product_name}</td></tr>'
			. '<tr><td style="padding:8px;border:1px solid #e5e7eb;font-weight:bold;">' . __( 'Amount', 'leastudios-email-templates' ) . '</td><td style="padding:8px;border:1px solid #e5e7eb;">{amount}</td></tr>'
			. '<tr><td style="padding:8px;border:1px solid #e5e7eb;font-weight:bold;">' . __( 'Next billing date', 'leastudios-email-templates' ) . '</td><td style="padding:8px;border:1px solid #e5e7eb;">{period_end}</td></tr>'
			. '</table>';
	}

	/**
	 * Subscription renewed body.
	 *
	 * @return string
	 */
	private function subscription_renewed_body(): string {
		return '<h2>' . __( 'Payment received', 'leastudios-email-templates' ) . '</h2>'
			. '<p>' . __( 'Hi {customer_name},', 'leastudios-email-templates' ) . '</p>'
			. '<p>' . __( 'We\'ve processed your subscription renewal payment for {product_name}.', 'leastudios-email-templates' ) . '</p>'
			. '<table style="width:100%;border-collapse:collapse;margin:20px 0;">'
			. '<tr><td style="padding:8px;border:1px solid #e5e7eb;font-weight:bold;">' . __( 'Amount', 'leastudios-email-templates' ) . '</td><td style="padding:8px;border:1px solid #e5e7eb;">{invoice_amount}</td></tr>'
			. '<tr><td style="padding:8px;border:1px solid #e5e7eb;font-weight:bold;">' . __( 'Next billing date', 'leastudios-email-templates' ) . '</td><td style="padding:8px;border:1px solid #e5e7eb;">{period_end}</td></tr>'
			. '</table>';
	}

	/**
	 * Payment failed body.
	 *
	 * @return string
	 */
	private function payment_failed_body(): string {
		return '<h2>' . __( 'Payment failed', 'leastudios-email-templates' ) . '</h2>'
			. '<p>' . __( 'Hi {customer_name},', 'leastudios-email-templates' ) . '</p>'
			. '<p>' . __( 'We were unable to process your payment of {invoice_amount} for {product_name}. Please update your payment method to avoid any interruption to your subscription.', 'leastudios-email-templates' ) . '</p>';
	}

	/**
	 * Refund body.
	 *
	 * @return string
	 */
	private function refund_body(): string {
		return '<h2>' . __( 'Refund processed', 'leastudios-email-templates' ) . '</h2>'
			. '<p>' . __( 'Hi {customer_name},', 'leastudios-email-templates' ) . '</p>'
			. '<p>' . __( 'A refund of {refunded_amount} has been issued for your order. It may take 5-10 business days to appear on your statement.', 'leastudios-email-templates' ) . '</p>'
			. '<table style="width:100%;border-collapse:collapse;margin:20px 0;">'
			. '<tr><td style="padding:8px;border:1px solid #e5e7eb;font-weight:bold;">' . __( 'Refunded', 'leastudios-email-templates' ) . '</td><td style="padding:8px;border:1px solid #e5e7eb;">{refunded_amount}</td></tr>'
			. '<tr><td style="padding:8px;border:1px solid #e5e7eb;font-weight:bold;">' . __( 'Original amount', 'leastudios-email-templates' ) . '</td><td style="padding:8px;border:1px solid #e5e7eb;">{amount}</td></tr>'
			. '</table>';
	}
}
