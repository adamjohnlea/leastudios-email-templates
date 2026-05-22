<?php
/**
 * Tests for Sample_Context.
 *
 * @package LEAStudios\EmailTemplates\Tests
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Tests;

use LEAStudios\EmailTemplates\Email\Email_Type;
use LEAStudios\EmailTemplates\Email\Sample_Context;
use LEAStudios\Tests\TestCase;

/**
 * @covers \LEAStudios\EmailTemplates\Email\Sample_Context
 */
class SampleContextTest extends TestCase {

	public function test_for_payment_receipt_provides_all_non_global_tags(): void {
		// Global tags (site_name, site_url, date) are filled by Merge_Tag_Replacer
		// at render time. Sample_Context is responsible for the type-specific
		// and customer-identifying tags only.
		$globals = [ 'site_name', 'site_url', 'date' ];
		$context = Sample_Context::for_type( Email_Type::PAYMENT_RECEIPT );

		foreach ( array_keys( Email_Type::PAYMENT_RECEIPT->available_tags() ) as $tag ) {
			$key = trim( $tag, '{}' );
			if ( in_array( $key, $globals, true ) ) {
				continue;
			}
			$this->assertArrayHasKey( $key, $context, "Missing sample value for {$tag}" );
		}
	}

	public function test_for_payment_receipt_resolves_every_tag_after_replacer_globals(): void {
		// End-to-end: after Sample_Context + Merge_Tag_Replacer's globals merge,
		// no advertised tag should remain unrendered in the output.
		$replacer = new \LEAStudios\EmailTemplates\Email\Merge_Tag_Replacer();
		$template = '';
		foreach ( array_keys( Email_Type::PAYMENT_RECEIPT->available_tags() ) as $tag ) {
			$template .= $tag . "\n";
		}

		$rendered = $replacer->replace_html( $template, Sample_Context::for_type( Email_Type::PAYMENT_RECEIPT ) );

		$this->assertStringNotContainsString( '{', $rendered, 'Unresolved tag in rendered template' );
	}

	public function test_for_refund_processed_provides_refunded_amount(): void {
		$context = Sample_Context::for_type( Email_Type::REFUND_PROCESSED );

		$this->assertArrayHasKey( 'refunded_amount', $context );
		$this->assertNotSame( '', $context['refunded_amount'] );
	}

	public function test_every_email_type_has_a_sample_context(): void {
		foreach ( Email_Type::cases() as $type ) {
			$context = Sample_Context::for_type( $type );
			$this->assertIsArray( $context, "No sample context for {$type->value}" );
			$this->assertNotEmpty( $context, "Empty sample context for {$type->value}" );
		}
	}
}
