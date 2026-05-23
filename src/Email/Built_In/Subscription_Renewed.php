<?php
/**
 * Built-in: subscription_renewed.
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
 * Transactional email sent when a subscription renewal payment is processed.
 */
final class Subscription_Renewed extends Abstract_Email_Type {

	/**
	 * Stable identifier for this email type.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'subscription_renewed';
	}

	/**
	 * Human-readable label, translated.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Subscription Renewed', 'leastudios-email-templates' );
	}

	/**
	 * Default subject line.
	 *
	 * @return string
	 */
	public function default_subject(): string {
		return __( 'Payment received for {product_name}', 'leastudios-email-templates' );
	}

	/**
	 * Default body HTML.
	 *
	 * @return string
	 */
	public function default_body(): string {
		return '<h2>' . __( 'Payment received', 'leastudios-email-templates' ) . '</h2>'
			. '<p>' . __( 'Hi {customer_name},', 'leastudios-email-templates' ) . '</p>'
			. '<p>' . __( 'We\'ve processed your subscription renewal payment for {product_name}.', 'leastudios-email-templates' ) . '</p>'
			. '<table style="width:100%;border-collapse:collapse;margin:20px 0;">'
			. '<tr><td style="padding:8px;border:1px solid #e5e7eb;font-weight:bold;">' . __( 'Amount', 'leastudios-email-templates' ) . '</td><td style="padding:8px;border:1px solid #e5e7eb;">{invoice_amount}</td></tr>'
			. '<tr><td style="padding:8px;border:1px solid #e5e7eb;font-weight:bold;">' . __( 'Next billing date', 'leastudios-email-templates' ) . '</td><td style="padding:8px;border:1px solid #e5e7eb;">{period_end}</td></tr>'
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
			'product_name'   => 'Sample Plan',
			'invoice_amount' => '$9.99',
			'currency'       => 'USD',
			'period_end'     => 'July 22, 2026',
		];
	}

	/**
	 * Renewal receipts must always be sent.
	 *
	 * @return bool
	 */
	public function is_transactional_required(): bool {
		return true;
	}
}
