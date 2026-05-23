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
	 * @return array<string, array{description: string, escape: Escape_Mode}>
	 */
	public function available_tags(): array {
		$common = [
			'{customer_name}'  => [
				'description' => __( 'Customer name', 'leastudios-email-templates' ),
				'escape'      => Escape_Mode::HTML,
			],
			'{customer_email}' => [
				'description' => __( 'Customer email', 'leastudios-email-templates' ),
				'escape'      => Escape_Mode::HTML,
			],
			'{site_name}'      => [
				'description' => __( 'Site name', 'leastudios-email-templates' ),
				'escape'      => Escape_Mode::HTML,
			],
			'{site_url}'       => [
				'description' => __( 'Site URL', 'leastudios-email-templates' ),
				'escape'      => Escape_Mode::URL,
			],
			'{date}'           => [
				'description' => __( 'Current date', 'leastudios-email-templates' ),
				'escape'      => Escape_Mode::HTML,
			],
		];

		$specific = match ( $this ) {
			self::PAYMENT_RECEIPT      => [
				'{amount}'       => [
					'description' => __( 'Payment amount', 'leastudios-email-templates' ),
					'escape'      => Escape_Mode::HTML,
				],
				'{currency}'     => [
					'description' => __( 'Currency code', 'leastudios-email-templates' ),
					'escape'      => Escape_Mode::HTML,
				],
				'{product_name}' => [
					'description' => __( 'Product name', 'leastudios-email-templates' ),
					'escape'      => Escape_Mode::HTML,
				],
				'{order_type}'   => [
					'description' => __( 'Order type (one-time or subscription)', 'leastudios-email-templates' ),
					'escape'      => Escape_Mode::HTML,
				],
				'{payment_id}'   => [
					'description' => __( 'Stripe Payment Intent ID', 'leastudios-email-templates' ),
					'escape'      => Escape_Mode::HTML,
				],
				'{order_id}'     => [
					'description' => __( 'Local order ID', 'leastudios-email-templates' ),
					'escape'      => Escape_Mode::HTML,
				],
			],
			self::SUBSCRIPTION_CREATED => [
				'{product_name}' => [
					'description' => __( 'Product name', 'leastudios-email-templates' ),
					'escape'      => Escape_Mode::HTML,
				],
				'{amount}'       => [
					'description' => __( 'Payment amount', 'leastudios-email-templates' ),
					'escape'      => Escape_Mode::HTML,
				],
				'{currency}'     => [
					'description' => __( 'Currency code', 'leastudios-email-templates' ),
					'escape'      => Escape_Mode::HTML,
				],
				'{period_end}'   => [
					'description' => __( 'Current period end date', 'leastudios-email-templates' ),
					'escape'      => Escape_Mode::HTML,
				],
			],
			self::SUBSCRIPTION_RENEWED => [
				'{product_name}'   => [
					'description' => __( 'Product name', 'leastudios-email-templates' ),
					'escape'      => Escape_Mode::HTML,
				],
				'{invoice_amount}' => [
					'description' => __( 'Invoice amount', 'leastudios-email-templates' ),
					'escape'      => Escape_Mode::HTML,
				],
				'{currency}'       => [
					'description' => __( 'Currency code', 'leastudios-email-templates' ),
					'escape'      => Escape_Mode::HTML,
				],
				'{period_end}'     => [
					'description' => __( 'Next billing date', 'leastudios-email-templates' ),
					'escape'      => Escape_Mode::HTML,
				],
			],
			self::PAYMENT_FAILED       => [
				'{product_name}'   => [
					'description' => __( 'Product name', 'leastudios-email-templates' ),
					'escape'      => Escape_Mode::HTML,
				],
				'{invoice_amount}' => [
					'description' => __( 'Invoice amount', 'leastudios-email-templates' ),
					'escape'      => Escape_Mode::HTML,
				],
				'{currency}'       => [
					'description' => __( 'Currency code', 'leastudios-email-templates' ),
					'escape'      => Escape_Mode::HTML,
				],
			],
			self::REFUND_PROCESSED     => [
				'{refunded_amount}' => [
					'description' => __( 'Refund amount', 'leastudios-email-templates' ),
					'escape'      => Escape_Mode::HTML,
				],
				'{amount}'          => [
					'description' => __( 'Original payment amount', 'leastudios-email-templates' ),
					'escape'      => Escape_Mode::HTML,
				],
				'{currency}'        => [
					'description' => __( 'Currency code', 'leastudios-email-templates' ),
					'escape'      => Escape_Mode::HTML,
				],
				'{product_name}'    => [
					'description' => __( 'Product name', 'leastudios-email-templates' ),
					'escape'      => Escape_Mode::HTML,
				],
				'{order_id}'        => [
					'description' => __( 'Local order ID', 'leastudios-email-templates' ),
					'escape'      => Escape_Mode::HTML,
				],
			],
		};

		return array_merge( $common, $specific );
	}

	/**
	 * Return a map of unbraced-tag-name => Escape_Mode, suitable for passing
	 * to Merge_Tag_Replacer::replace_html() as its $escape_map argument.
	 *
	 * @return array<string, Escape_Mode>
	 */
	public function escape_map(): array {
		$map = [];
		foreach ( $this->available_tags() as $tag => $meta ) {
			$map[ trim( $tag, '{}' ) ] = $meta['escape'];
		}
		return $map;
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
