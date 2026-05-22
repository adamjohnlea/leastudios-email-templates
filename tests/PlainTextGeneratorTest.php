<?php
/**
 * Tests for Plain_Text_Generator.
 *
 * @package LEAStudios\EmailTemplates\Tests
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Tests;

use LEAStudios\EmailTemplates\Email\Plain_Text_Generator;
use LEAStudios\Tests\TestCase;

/**
 * @covers \LEAStudios\EmailTemplates\Email\Plain_Text_Generator
 */
class PlainTextGeneratorTest extends TestCase {

	public function test_strips_html_tags(): void {
		$text = Plain_Text_Generator::from_html( '<p>Hello <strong>world</strong>!</p>' );
		$this->assertSame( 'Hello world!', $text );
	}

	public function test_removes_scripts_and_styles(): void {
		$html = '<style>.a{color:red}</style><script>alert(1)</script><p>Visible</p>';
		$text = Plain_Text_Generator::from_html( $html );
		$this->assertStringNotContainsString( 'color:red', $text );
		$this->assertStringNotContainsString( 'alert', $text );
		$this->assertStringContainsString( 'Visible', $text );
	}

	public function test_preserves_links_as_text_url_pairs(): void {
		$html = '<p>Visit <a href="https://example.com/x">our site</a> today.</p>';
		$text = Plain_Text_Generator::from_html( $html );
		$this->assertStringContainsString( 'our site (https://example.com/x)', $text );
	}

	public function test_collapses_anchor_when_text_equals_url(): void {
		$html = '<a href="https://example.com">https://example.com</a>';
		$text = Plain_Text_Generator::from_html( $html );
		$this->assertSame( 'https://example.com', $text );
	}

	public function test_renders_headings_as_lines_with_blank_separation(): void {
		$html = '<h1>Title</h1><p>Body</p>';
		$text = Plain_Text_Generator::from_html( $html );
		$this->assertStringContainsString( "Title\n", $text );
		$this->assertStringContainsString( 'Body', $text );
	}

	public function test_renders_lists_as_dashes(): void {
		$html = '<ul><li>One</li><li>Two</li></ul>';
		$text = Plain_Text_Generator::from_html( $html );
		$this->assertStringContainsString( '- One', $text );
		$this->assertStringContainsString( '- Two', $text );
	}

	public function test_decodes_html_entities(): void {
		$text = Plain_Text_Generator::from_html( '<p>Caf&eacute; &amp; Co.</p>' );
		$this->assertSame( 'Café & Co.', $text );
	}

	public function test_collapses_excess_whitespace_but_keeps_paragraph_breaks(): void {
		$html = "<p>Para one.</p>\n\n\n<p>Para two.</p>";
		$text = Plain_Text_Generator::from_html( $html );
		$this->assertStringContainsString( "Para one.\n\nPara two.", $text );
		$this->assertStringNotContainsString( "\n\n\n", $text );
	}

	public function test_handles_empty_input(): void {
		$this->assertSame( '', Plain_Text_Generator::from_html( '' ) );
	}
}
