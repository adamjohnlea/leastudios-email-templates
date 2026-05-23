<?php
/**
 * Exhaustive tests for the five built-in email type definitions.
 *
 * @package LEAStudios\EmailTemplates\Tests
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Tests;

use LEAStudios\EmailTemplates\Email\Built_In\Payment_Failed;
use LEAStudios\EmailTemplates\Email\Built_In\Payment_Receipt;
use LEAStudios\EmailTemplates\Email\Built_In\Refund_Processed;
use LEAStudios\EmailTemplates\Email\Built_In\Subscription_Created;
use LEAStudios\EmailTemplates\Email\Built_In\Subscription_Renewed;
use LEAStudios\EmailTemplates\Email\Email_Type_Definition;
use LEAStudios\EmailTemplates\Email\Escape_Mode;
use LEAStudios\EmailTemplates\Email\Merge_Tag_Replacer;
use LEAStudios\Tests\TestCase;

class BuiltInTypesTest extends TestCase {

	/**
	 * @return array<string, array{0: Email_Type_Definition, 1: string}>
	 */
	public function built_in_provider(): array {
		return [
			'payment_receipt'      => [ new Payment_Receipt(), 'payment_receipt' ],
			'subscription_created' => [ new Subscription_Created(), 'subscription_created' ],
			'subscription_renewed' => [ new Subscription_Renewed(), 'subscription_renewed' ],
			'payment_failed'       => [ new Payment_Failed(), 'payment_failed' ],
			'refund_processed'     => [ new Refund_Processed(), 'refund_processed' ],
		];
	}

	/**
	 * @dataProvider built_in_provider
	 *
	 * @param Email_Type_Definition $type        The email type definition.
	 * @param string                $expected_id Expected identifier.
	 */
	public function test_id_is_frozen( Email_Type_Definition $type, string $expected_id ): void {
		$this->assertSame( $expected_id, $type->id() );
	}

	/**
	 * @dataProvider built_in_provider
	 *
	 * @param Email_Type_Definition $type The email type definition.
	 */
	public function test_label_is_translated_string( Email_Type_Definition $type ): void {
		$this->assertNotSame( '', $type->label() );
	}

	/**
	 * @dataProvider built_in_provider
	 *
	 * @param Email_Type_Definition $type The email type definition.
	 */
	public function test_default_subject_and_body_non_empty( Email_Type_Definition $type ): void {
		$this->assertNotSame( '', $type->default_subject() );
		$this->assertNotSame( '', $type->default_body() );
	}

	/**
	 * @dataProvider built_in_provider
	 *
	 * @param Email_Type_Definition $type The email type definition.
	 */
	public function test_advertises_common_tags( Email_Type_Definition $type ): void {
		$common = [ '{customer_name}', '{customer_email}', '{site_name}', '{site_url}', '{date}' ];
		$keys   = array_keys( $type->available_tags() );

		foreach ( $common as $tag ) {
			$this->assertContains( $tag, $keys, $type->id() . " missing common tag {$tag}" );
		}
	}

	/**
	 * @dataProvider built_in_provider
	 *
	 * @param Email_Type_Definition $type The email type definition.
	 */
	public function test_escape_map_unbraces_keys( Email_Type_Definition $type ): void {
		$map = $type->escape_map();

		$this->assertSame(
			count( $type->available_tags() ),
			count( $map ),
			$type->id() . ' escape_map count differs from available_tags count'
		);

		foreach ( array_keys( $map ) as $key ) {
			$this->assertDoesNotMatchRegularExpression( '/[{}]/', $key, "Key {$key} should be unbraced" );
		}
	}

	/**
	 * @return array<string, array{0: Email_Type_Definition, 1: bool}>
	 */
	public function transactional_required_provider(): array {
		return [
			'payment_receipt is required'          => [ new Payment_Receipt(), true ],
			'payment_failed is required'           => [ new Payment_Failed(), true ],
			'refund_processed is required'         => [ new Refund_Processed(), true ],
			'subscription_renewed is required'     => [ new Subscription_Renewed(), true ],
			'subscription_created is NOT required' => [ new Subscription_Created(), false ],
		];
	}

	/**
	 * @dataProvider transactional_required_provider
	 *
	 * @param Email_Type_Definition $type     The email type definition.
	 * @param bool                  $expected Expected return value.
	 */
	public function test_is_transactional_required_matches_expectation( Email_Type_Definition $type, bool $expected ): void {
		$this->assertSame( $expected, $type->is_transactional_required(), $type->id() );
	}

	/**
	 * @dataProvider built_in_provider
	 *
	 * @param Email_Type_Definition $type The email type definition.
	 */
	public function test_sample_context_populates_every_non_global_tag( Email_Type_Definition $type ): void {
		$globals = [ 'site_name', 'site_url', 'date' ];
		$sample  = $type->sample_context();

		foreach ( array_keys( $type->available_tags() ) as $tag ) {
			$key = trim( $tag, '{}' );
			if ( in_array( $key, $globals, true ) ) {
				continue;
			}
			$this->assertArrayHasKey( $key, $sample, $type->id() . " sample_context missing {$tag}" );
			$this->assertNotSame( '', $sample[ $key ], $type->id() . " sample_context value for {$tag} must be non-empty" );
		}
	}

	/**
	 * @dataProvider built_in_provider
	 *
	 * @param Email_Type_Definition $type The email type definition.
	 */
	public function test_sample_context_renders_with_no_unresolved_tags( Email_Type_Definition $type ): void {
		$replacer = new Merge_Tag_Replacer();
		$template = '';
		foreach ( array_keys( $type->available_tags() ) as $tag ) {
			$template .= $tag . "\n";
		}

		$rendered = $replacer->replace_html( $template, $type->sample_context(), $type->escape_map() );

		$this->assertStringNotContainsString( '{', $rendered, $type->id() . ' has unresolved tags after render' );
	}

	public function test_site_url_advertised_with_url_escape_mode_across_built_ins(): void {
		foreach ( $this->built_in_provider() as [ $type ] ) {
			$tags = $type->available_tags();
			$this->assertSame( Escape_Mode::URL, $tags['{site_url}']['escape'], $type->id() . ' site_url must be URL-escaped' );
		}
	}
}
