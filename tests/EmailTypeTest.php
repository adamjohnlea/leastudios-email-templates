<?php
/**
 * Tests for Email_Type.
 *
 * @package LEAStudios\EmailTemplates\Tests
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Tests;

use LEAStudios\EmailTemplates\Email\Email_Type;
use LEAStudios\EmailTemplates\Email\Escape_Mode;
use LEAStudios\Tests\TestCase;

/**
 * @covers \LEAStudios\EmailTemplates\Email\Email_Type
 */
class EmailTypeTest extends TestCase {

	public function test_available_tags_returns_description_and_escape_per_tag(): void {
		$tags = Email_Type::PAYMENT_RECEIPT->available_tags();

		$this->assertArrayHasKey( '{customer_name}', $tags );
		$this->assertArrayHasKey( 'description', $tags['{customer_name}'] );
		$this->assertArrayHasKey( 'escape', $tags['{customer_name}'] );
		$this->assertIsString( $tags['{customer_name}']['description'] );
		$this->assertSame( Escape_Mode::HTML, $tags['{customer_name}']['escape'] );
	}

	public function test_available_tags_keys_keep_braces(): void {
		$tags = Email_Type::PAYMENT_RECEIPT->available_tags();

		foreach ( array_keys( $tags ) as $tag ) {
			$this->assertMatchesRegularExpression( '/^\{[a-z_]+\}$/', $tag, "Tag key {$tag} should be {braced_snake_case}" );
		}
	}

	public function test_every_case_advertises_at_least_the_common_tags(): void {
		$common = [ '{customer_name}', '{customer_email}', '{site_name}', '{site_url}', '{date}' ];

		foreach ( Email_Type::cases() as $case ) {
			$keys = array_keys( $case->available_tags() );
			foreach ( $common as $tag ) {
				$this->assertContains( $tag, $keys, "{$case->value} missing common tag {$tag}" );
			}
		}
	}

	public function test_every_case_returns_html_escape_mode_for_every_tag_by_default(): void {
		// Phase 6 introduces the contract but no production tag opts into RAW yet.
		// {site_url} is the one tag that defaults to URL escape.
		foreach ( Email_Type::cases() as $case ) {
			foreach ( $case->available_tags() as $tag => $meta ) {
				$expected = '{site_url}' === $tag ? Escape_Mode::URL : Escape_Mode::HTML;
				$this->assertSame( $expected, $meta['escape'], "{$case->value} tag {$tag} has wrong escape mode" );
			}
		}
	}

	public function test_escape_map_returns_unbraced_keys(): void {
		$map = Email_Type::PAYMENT_RECEIPT->escape_map();

		$this->assertArrayHasKey( 'customer_name', $map );
		$this->assertArrayNotHasKey( '{customer_name}', $map );
		$this->assertSame( Escape_Mode::HTML, $map['customer_name'] );
	}

	public function test_escape_map_covers_every_advertised_tag(): void {
		foreach ( Email_Type::cases() as $case ) {
			$tags_count = count( $case->available_tags() );
			$map_count  = count( $case->escape_map() );
			$this->assertSame( $tags_count, $map_count, "{$case->value} escape_map count differs from available_tags count" );
		}
	}
}
