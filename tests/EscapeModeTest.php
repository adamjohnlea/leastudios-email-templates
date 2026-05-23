<?php
/**
 * Tests for Escape_Mode.
 *
 * @package LEAStudios\EmailTemplates\Tests
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Tests;

use LEAStudios\EmailTemplates\Email\Escape_Mode;
use LEAStudios\Tests\TestCase;

/**
 * @covers \LEAStudios\EmailTemplates\Email\Escape_Mode
 */
class EscapeModeTest extends TestCase {

	public function test_html_case_has_value_html(): void {
		$this->assertSame( 'html', Escape_Mode::HTML->value );
	}

	public function test_raw_case_has_value_raw(): void {
		$this->assertSame( 'raw', Escape_Mode::RAW->value );
	}

	public function test_url_case_has_value_url(): void {
		$this->assertSame( 'url', Escape_Mode::URL->value );
	}

	public function test_enum_has_exactly_three_cases(): void {
		$this->assertCount( 3, Escape_Mode::cases() );
	}
}
