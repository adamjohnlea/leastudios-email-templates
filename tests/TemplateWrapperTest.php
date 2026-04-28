<?php
/**
 * Tests for Template_Wrapper.
 *
 * @package LEAStudios\EmailTemplates\Tests
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Tests;

use LEAStudios\EmailTemplates\Email\Merge_Tag_Replacer;
use LEAStudios\EmailTemplates\Email\Template_Wrapper;
use LEAStudios\Tests\TestCase;

/**
 * @covers \LEAStudios\EmailTemplates\Email\Template_Wrapper
 */
class TemplateWrapperTest extends TestCase {

	private Template_Wrapper $wrapper;

	public function set_up(): void {
		parent::set_up();
		$this->wrapper = new Template_Wrapper( new Merge_Tag_Replacer() );
	}

	public function test_wrap_adds_html_structure(): void {
		update_option(
			'leastudios_email_templates_branding',
			[
				'enabled'       => true,
				'logo_url'      => '',
				'primary_color' => '#4f46e5',
				'footer_text'   => '',
				'social_links'  => [],
			]
		);

		$result = $this->wrapper->wrap( '<p>Hello World</p>' );

		$this->assertStringContainsString( '<!DOCTYPE html>', $result );
		$this->assertStringContainsString( '<p>Hello World</p>', $result );
		$this->assertStringContainsString( '#4f46e5', $result );
	}

	public function test_wrap_returns_original_when_disabled(): void {
		update_option(
			'leastudios_email_templates_branding',
			[ 'enabled' => false ]
		);

		$content = '<p>Hello World</p>';
		$result  = $this->wrapper->wrap( $content );

		$this->assertSame( $content, $result );
	}

	public function test_wrap_includes_logo_when_set(): void {
		update_option(
			'leastudios_email_templates_branding',
			[
				'enabled'       => true,
				'logo_url'      => 'https://example.com/logo.png',
				'primary_color' => '#000000',
				'footer_text'   => '',
				'social_links'  => [],
			]
		);

		$result = $this->wrapper->wrap( '<p>Content</p>' );

		$this->assertStringContainsString( 'https://example.com/logo.png', $result );
	}

	public function test_wrap_includes_footer_text(): void {
		update_option(
			'leastudios_email_templates_branding',
			[
				'enabled'       => true,
				'logo_url'      => '',
				'primary_color' => '#000000',
				'footer_text'   => 'Copyright 2026 Test Company',
				'social_links'  => [],
			]
		);

		$result = $this->wrapper->wrap( '<p>Content</p>' );

		$this->assertStringContainsString( 'Copyright 2026 Test Company', $result );
	}

	public function test_wrap_includes_social_links(): void {
		update_option(
			'leastudios_email_templates_branding',
			[
				'enabled'       => true,
				'logo_url'      => '',
				'primary_color' => '#000000',
				'footer_text'   => '',
				'social_links'  => [
					'twitter'  => 'https://twitter.com/test',
					'facebook' => '',
				],
			]
		);

		$result = $this->wrapper->wrap( '<p>Content</p>' );

		$this->assertStringContainsString( 'https://twitter.com/test', $result );
		$this->assertStringNotContainsString( 'Facebook', $result );
	}

	public function test_opt_out_header_skips_wrapping(): void {
		update_option(
			'leastudios_email_templates_branding',
			[
				'enabled'       => true,
				'logo_url'      => '',
				'primary_color' => '#000000',
				'footer_text'   => '',
				'social_links'  => [],
			]
		);

		$args = [
			'body_html' => '<p>Raw content</p>',
			'headers'   => [ 'X-LeaStudios-No-Template: true' ],
		];

		$result = $this->wrapper->wrap_mailer_email( $args, [] );

		$this->assertSame( '<p>Raw content</p>', $result['body_html'] );
	}

	public function test_null_args_pass_through(): void {
		$result = $this->wrapper->wrap_mailer_email( null, [] );

		$this->assertNull( $result );
	}

	public function test_empty_body_html_not_wrapped(): void {
		$args = [
			'body_html' => '',
			'body_text' => 'Plain text email',
			'headers'   => [],
		];

		$result = $this->wrapper->wrap_mailer_email( $args, [] );

		$this->assertSame( '', $result['body_html'] );
	}
}
