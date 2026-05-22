<?php
/**
 * Realistic sample merge-tag context per Email_Type, used by the preview
 * and send-test admin features.
 *
 * @package LEAStudios\EmailTemplates\Email
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Email;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Pure helper that returns a populated context array suitable for rendering
 * a preview or sending a self-test, covering every merge tag the type
 * advertises in `available_tags()`. Values are intentionally generic so the
 * preview is recognisable as a sample, not real customer data.
 */
final class Sample_Context {

	/**
	 * Return a sample context populated with every tag the type advertises.
	 *
	 * @param Email_Type $type The email type.
	 * @return array<string, string>
	 */
	public static function for_type( Email_Type $type ): array {
		$common = [
			'customer_name'  => 'Jane Customer',
			'customer_email' => 'jane@example.com',
		];

		$specific = match ( $type ) {
			Email_Type::PAYMENT_RECEIPT      => [
				'amount'         => '$29.99',
				'currency'       => 'USD',
				'product_name'   => 'Sample Product',
				'order_type'     => 'One-time payment',
				'payment_id'     => 'pi_sample_1234567890',
				'order_id'       => '42',
				'payment_status' => 'Paid',
			],
			Email_Type::SUBSCRIPTION_CREATED => [
				'product_name' => 'Sample Plan',
				'amount'       => '$9.99',
				'currency'     => 'USD',
				'period_end'   => 'June 22, 2026',
			],
			Email_Type::SUBSCRIPTION_RENEWED => [
				'product_name'   => 'Sample Plan',
				'invoice_amount' => '$9.99',
				'currency'       => 'USD',
				'period_end'     => 'July 22, 2026',
			],
			Email_Type::PAYMENT_FAILED       => [
				'product_name'   => 'Sample Plan',
				'invoice_amount' => '$9.99',
				'currency'       => 'USD',
			],
			Email_Type::REFUND_PROCESSED     => [
				'refunded_amount' => '$10.00',
				'amount'          => '$29.99',
				'currency'        => 'USD',
				'product_name'    => 'Sample Product',
				'order_id'        => '42',
			],
		};

		return array_merge( $common, $specific );
	}
}
