<?php
/**
 * Tests for Payment_Data_Resolver.
 *
 * @package LEAStudios\EmailTemplates\Tests
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Tests;

use LEAStudios\EmailTemplates\Payment\Payment_Data_Resolver;
use LEAStudios\Tests\TestCase;

/**
 * @covers \LEAStudios\EmailTemplates\Payment\Payment_Data_Resolver
 */
class PaymentDataResolverTest extends TestCase {

	public function test_find_product_name_for_price_picks_matching_price_id(): void {
		$line_items = [
			'[{"description":"Pro Plan","price_id":"price_pro","amount":2000}]',
			'[{"description":"Starter Plan","price_id":"price_starter","amount":500}]',
		];

		$this->assertSame(
			'Starter Plan',
			Payment_Data_Resolver::find_product_name_for_price( $line_items, 'price_starter' )
		);

		$this->assertSame(
			'Pro Plan',
			Payment_Data_Resolver::find_product_name_for_price( $line_items, 'price_pro' )
		);
	}

	public function test_find_product_name_for_price_returns_empty_when_no_match(): void {
		$line_items = [
			'[{"description":"Pro Plan","price_id":"price_pro"}]',
		];

		$this->assertSame(
			'',
			Payment_Data_Resolver::find_product_name_for_price( $line_items, 'price_other' )
		);
	}

	public function test_find_product_name_for_price_handles_multi_item_orders(): void {
		// Single order with multiple line items, target price is the second one.
		$line_items = [
			'[{"description":"Setup","price_id":"price_setup"},{"description":"Monthly","price_id":"price_monthly"}]',
		];

		$this->assertSame(
			'Monthly',
			Payment_Data_Resolver::find_product_name_for_price( $line_items, 'price_monthly' )
		);
	}

	public function test_find_product_name_for_price_skips_invalid_json(): void {
		$line_items = [
			'{not json',
			'[{"description":"Real","price_id":"price_real"}]',
		];

		$this->assertSame(
			'Real',
			Payment_Data_Resolver::find_product_name_for_price( $line_items, 'price_real' )
		);
	}

	public function test_find_product_name_for_price_empty_input(): void {
		$this->assertSame( '', Payment_Data_Resolver::find_product_name_for_price( [], 'price_x' ) );
	}
}
