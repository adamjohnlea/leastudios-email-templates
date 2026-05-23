<?php
/**
 * Built-in: payment_receipt.
 *
 * @package LEAStudios\EmailTemplates\Email\Built_In
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Email\Built_In;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use LEAStudios\EmailTemplates\Email\Abstract_Email_Type;
use LEAStudios\EmailTemplates\Email\Escape_Mode;

/**
 * Transactional email sent after a successful one-time or subscription payment.
 */
final class Payment_Receipt extends Abstract_Email_Type {

	/**
	 * Stable identifier for this email type.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'payment_receipt';
	}

	/**
	 * Human-readable label, translated.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Payment Receipt', 'leastudios-email-templates' );
	}

	/**
	 * Default subject line.
	 *
	 * @return string
	 */
	public function default_subject(): string {
		return __( 'Your receipt for {product_name}', 'leastudios-email-templates' );
	}

	/**
	 * Default body HTML.
	 *
	 * @return string
	 */
	public function default_body(): string {
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
	 * Merge tags this type advertises.
	 *
	 * @return array<string, array{description: string, escape: Escape_Mode}>
	 */
	public function available_tags(): array {
		return [
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
			'{amount}'         => [
				'description' => __( 'Payment amount', 'leastudios-email-templates' ),
				'escape'      => Escape_Mode::HTML,
			],
			'{currency}'       => [
				'description' => __( 'Currency code', 'leastudios-email-templates' ),
				'escape'      => Escape_Mode::HTML,
			],
			'{product_name}'   => [
				'description' => __( 'Product name', 'leastudios-email-templates' ),
				'escape'      => Escape_Mode::HTML,
			],
			'{order_type}'     => [
				'description' => __( 'Order type (one-time or subscription)', 'leastudios-email-templates' ),
				'escape'      => Escape_Mode::HTML,
			],
			'{payment_id}'     => [
				'description' => __( 'Stripe Payment Intent ID', 'leastudios-email-templates' ),
				'escape'      => Escape_Mode::HTML,
			],
			'{order_id}'       => [
				'description' => __( 'Local order ID', 'leastudios-email-templates' ),
				'escape'      => Escape_Mode::HTML,
			],
		];
	}

	/**
	 * Sample context for previews and self-tests.
	 *
	 * @return array<string, string>
	 */
	public function sample_context(): array {
		return [
			'customer_name'  => 'Jane Customer',
			'customer_email' => 'jane@example.com',
			'amount'         => '$29.99',
			'currency'       => 'USD',
			'product_name'   => 'Sample Product',
			'order_type'     => 'One-time payment',
			'payment_id'     => 'pi_sample_1234567890',
			'order_id'       => '42',
		];
	}

	/**
	 * Payment receipts must always be sent.
	 *
	 * @return bool
	 */
	public function is_transactional_required(): bool {
		return true;
	}
}
