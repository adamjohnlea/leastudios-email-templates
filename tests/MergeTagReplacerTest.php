<?php
/**
 * Tests for Merge_Tag_Replacer.
 *
 * @package LEAStudios\EmailTemplates\Tests
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Tests;

use LEAStudios\EmailTemplates\Email\Merge_Tag_Replacer;
use LEAStudios\Tests\TestCase;

/**
 * @covers \LEAStudios\EmailTemplates\Email\Merge_Tag_Replacer
 */
class MergeTagReplacerTest extends TestCase {

	private Merge_Tag_Replacer $replacer;

	public function set_up(): void {
		parent::set_up();
		$this->replacer = new Merge_Tag_Replacer();
	}

	public function test_replaces_simple_tags(): void {
		$result = $this->replacer->replace(
			'Hello {name}, welcome to {place}!',
			[
				'name'  => 'Alice',
				'place' => 'Wonderland',
			]
		);

		$this->assertSame( 'Hello Alice, welcome to Wonderland!', $result );
	}

	public function test_global_tags_always_available(): void {
		update_option( 'blogname', 'Test Blog' );

		$result = $this->replacer->replace( 'Welcome to {site_name}' );

		$this->assertStringContainsString( 'Test Blog', $result );
	}

	public function test_missing_tags_remain_in_content(): void {
		$result = $this->replacer->replace( 'Hi {unknown_tag}!' );

		$this->assertStringContainsString( '{unknown_tag}', $result );
	}

	public function test_context_overrides_global_tags(): void {
		update_option( 'blogname', 'Original Name' );

		$result = $this->replacer->replace(
			'{site_name}',
			[ 'site_name' => 'Custom Name' ]
		);

		$this->assertSame( 'Custom Name', $result );
	}

	public function test_format_amount_usd(): void {
		$this->assertSame( '$29.99', Merge_Tag_Replacer::format_amount( 2999, 'usd' ) );
	}

	public function test_format_amount_jpy_no_decimals(): void {
		$this->assertSame( "\xc2\xa5" . '1,000', Merge_Tag_Replacer::format_amount( 1000, 'jpy' ) );
	}

	public function test_format_amount_gbp(): void {
		$this->assertSame( "\xc2\xa3" . '10.50', Merge_Tag_Replacer::format_amount( 1050, 'gbp' ) );
	}

	public function test_format_amount_unknown_currency(): void {
		$result = Merge_Tag_Replacer::format_amount( 500, 'xyz' );
		$this->assertSame( 'XYZ 5.00', $result );
	}

	public function test_merge_tags_filter(): void {
		add_filter(
			'leastudios_email_templates_merge_tags',
			function ( array $context ) {
				$context['custom_tag'] = 'custom_value';
				return $context;
			}
		);

		$result = $this->replacer->replace( '{custom_tag}' );

		$this->assertSame( 'custom_value', $result );
	}
}
