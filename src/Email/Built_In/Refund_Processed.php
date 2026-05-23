<?php
/**
 * Built-in: refund_processed.
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
 * Transactional email sent when a refund is issued for an order.
 */
final class Refund_Processed extends Abstract_Email_Type {

	/**
	 * Stable identifier for this email type.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'refund_processed';
	}

	/**
	 * Human-readable label, translated.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Refund Processed', 'leastudios-email-templates' );
	}

	/**
	 * Default subject line.
	 *
	 * @return string
	 */
	public function default_subject(): string {
		return __( 'Your refund of {refunded_amount} has been processed', 'leastudios-email-templates' );
	}

	/**
	 * Default body HTML.
	 *
	 * @return string
	 */
	public function default_body(): string {
		return '<h2>' . __( 'Refund processed', 'leastudios-email-templates' ) . '</h2>'
			. '<p>' . __( 'Hi {customer_name},', 'leastudios-email-templates' ) . '</p>'
			. '<p>' . __( 'A refund of {refunded_amount} has been issued for your order. It may take 5-10 business days to appear on your statement.', 'leastudios-email-templates' ) . '</p>'
			. '<table style="width:100%;border-collapse:collapse;margin:20px 0;">'
			. '<tr><td style="padding:8px;border:1px solid #e5e7eb;font-weight:bold;">' . __( 'Refunded', 'leastudios-email-templates' ) . '</td><td style="padding:8px;border:1px solid #e5e7eb;">{refunded_amount}</td></tr>'
			. '<tr><td style="padding:8px;border:1px solid #e5e7eb;font-weight:bold;">' . __( 'Original amount', 'leastudios-email-templates' ) . '</td><td style="padding:8px;border:1px solid #e5e7eb;">{amount}</td></tr>'
			. '</table>';
	}

	/**
	 * Merge tags this type advertises.
	 *
	 * @return array<string, array{description: string, escape: Escape_Mode}>
	 */
	public function available_tags(): array {
		return [
			'{customer_name}'   => [
				'description' => __( 'Customer name', 'leastudios-email-templates' ),
				'escape'      => Escape_Mode::HTML,
			],
			'{customer_email}'  => [
				'description' => __( 'Customer email', 'leastudios-email-templates' ),
				'escape'      => Escape_Mode::HTML,
			],
			'{site_name}'       => [
				'description' => __( 'Site name', 'leastudios-email-templates' ),
				'escape'      => Escape_Mode::HTML,
			],
			'{site_url}'        => [
				'description' => __( 'Site URL', 'leastudios-email-templates' ),
				'escape'      => Escape_Mode::URL,
			],
			'{date}'            => [
				'description' => __( 'Current date', 'leastudios-email-templates' ),
				'escape'      => Escape_Mode::HTML,
			],
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
		];
	}

	/**
	 * Sample context for previews and self-tests.
	 *
	 * @return array<string, string>
	 */
	public function sample_context(): array {
		return [
			'customer_name'   => 'Jane Customer',
			'customer_email'  => 'jane@example.com',
			'refunded_amount' => '$10.00',
			'amount'          => '$29.99',
			'currency'        => 'USD',
			'product_name'    => 'Sample Product',
			'order_id'        => '42',
		];
	}

	/**
	 * Refund notices must always be sent.
	 *
	 * @return bool
	 */
	public function is_transactional_required(): bool {
		return true;
	}
}
