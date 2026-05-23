<?php
/**
 * Tests for Merge_Tag_Replacer.
 *
 * @package LEAStudios\EmailTemplates\Tests
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Tests;

use LEAStudios\EmailTemplates\Email\Escape_Mode;
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
		$result = $this->replacer->replace_html(
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

		$result = $this->replacer->replace_html( 'Welcome to {site_name}' );

		$this->assertStringContainsString( 'Test Blog', $result );
	}

	public function test_missing_tags_remain_in_content(): void {
		$result = $this->replacer->replace_html( 'Hi {unknown_tag}!' );

		$this->assertStringContainsString( '{unknown_tag}', $result );
	}

	public function test_context_overrides_global_tags(): void {
		update_option( 'blogname', 'Original Name' );

		$result = $this->replacer->replace_html(
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

	/**
	 * @dataProvider zero_decimal_currencies_provider
	 *
	 * @param string $currency Lowercase 3-letter currency code.
	 * @param int    $amount   Amount in whole units (Stripe zero-decimal currencies pass whole-unit values).
	 * @param string $expected The expected formatted string.
	 */
	public function test_format_amount_zero_decimal_currencies( string $currency, int $amount, string $expected ): void {
		$this->assertSame( $expected, Merge_Tag_Replacer::format_amount( $amount, $currency ) );
	}

	/**
	 * Stripe's full zero-decimal currency list (BIF, CLP, DJF, GNF, JPY, KMF,
	 * KRW, MGA, PYG, RWF, UGX, VND, VUV, XAF, XOF, XPF). The amount is the
	 * full unit count — no /100 division.
	 *
	 * @return array<string, array{0:string, 1:int, 2:string}>
	 */
	public static function zero_decimal_currencies_provider(): array {
		return [
			'KRW 1,000 = ₩1,000'        => [ 'krw', 1000, "\xe2\x82\xa9" . '1,000' ],
			'VND 50,000 = ₫50,000'      => [ 'vnd', 50000, "\xe2\x82\xab" . '50,000' ],
			'CLP 5,000 = CLP 5,000'     => [ 'clp', 5000, 'CLP 5,000' ],
			'UGX 100,000 = UGX 100,000' => [ 'ugx', 100000, 'UGX 100,000' ],
		];
	}

	public function test_merge_tags_filter(): void {
		add_filter(
			'leastudios_email_templates_merge_tags',
			function ( array $context ) {
				$context['custom_tag'] = 'custom_value';
				return $context;
			}
		);

		$result = $this->replacer->replace_html( '{custom_tag}' );

		$this->assertSame( 'custom_value', $result );
	}

	public function test_replace_html_escapes_html_in_values(): void {
		$result = $this->replacer->replace_html(
			'<p>Hi {name}!</p>',
			[ 'name' => '<script>alert(1)</script>' ]
		);

		$this->assertStringNotContainsString( '<script>', $result );
		$this->assertStringContainsString( '&lt;script&gt;', $result );
	}

	public function test_replace_html_preserves_template_html(): void {
		$result = $this->replacer->replace_html(
			'<strong>{label}</strong>',
			[ 'label' => 'Plain' ]
		);

		// Template <strong> stays; only the value is escaped (and contains no HTML).
		$this->assertSame( '<strong>Plain</strong>', $result );
	}

	public function test_replace_subject_strips_crlf_from_values(): void {
		$result = $this->replacer->replace_subject(
			'Order from {name}',
			[ 'name' => "Mallory\r\nBcc: attacker@evil.example" ]
		);

		$this->assertStringNotContainsString( "\r", $result );
		$this->assertStringNotContainsString( "\n", $result );
		$this->assertStringContainsString( 'Mallory', $result );
		$this->assertStringContainsString( 'attacker@evil.example', $result );
	}

	public function test_raw_escape_mode_inserts_html_unchanged(): void {
		$result = $this->replacer->replace_html(
			'<p>Pricing: {plans_table}</p>',
			[ 'plans_table' => '<table><tr><td>Pro</td></tr></table>' ],
			[ 'plans_table' => Escape_Mode::RAW ]
		);

		$this->assertStringContainsString( '<table><tr><td>Pro</td></tr></table>', $result );
		$this->assertStringNotContainsString( '&lt;table&gt;', $result );
	}

	public function test_url_escape_mode_runs_value_through_esc_url(): void {
		$result = $this->replacer->replace_html(
			'<a href="{cta_url}">Click</a>',
			[ 'cta_url' => 'https://example.com/path?a=1&b=2' ],
			[ 'cta_url' => Escape_Mode::URL ]
		);

		$this->assertStringContainsString( 'href="https://example.com/path?a=1', $result );
		$this->assertStringContainsString( '&#038;b=2', $result, 'esc_url should encode bare ampersands as &#038;' );
	}

	public function test_url_escape_mode_rejects_invalid_scheme(): void {
		$result = $this->replacer->replace_html(
			'<a href="{cta_url}">Click</a>',
			[ 'cta_url' => 'javascript:alert(1)' ],
			[ 'cta_url' => Escape_Mode::URL ]
		);

		$this->assertStringNotContainsString( 'javascript:alert(1)', $result );
	}

	public function test_html_escape_mode_is_default_when_map_omits_tag(): void {
		$result = $this->replacer->replace_html(
			'<p>{name}</p>',
			[ 'name' => '<script>alert(1)</script>' ],
			[]
		);

		$this->assertStringNotContainsString( '<script>', $result );
		$this->assertStringContainsString( '&lt;script&gt;', $result );
	}

	public function test_escape_map_does_not_affect_other_tags(): void {
		$result = $this->replacer->replace_html(
			'<p>{name}: {payload}</p>',
			[
				'name'    => '<b>Bold</b>',
				'payload' => '<em>Italic</em>',
			],
			[ 'payload' => Escape_Mode::RAW ]
		);

		// Name uses default HTML escape; payload is RAW.
		$this->assertStringContainsString( '&lt;b&gt;Bold&lt;/b&gt;', $result );
		$this->assertStringContainsString( '<em>Italic</em>', $result );
	}

	public function test_global_site_url_is_url_escaped_by_default(): void {
		update_option( 'home', 'https://example.com/wp?x=1&y=2' );

		$result = $this->replacer->replace_html( 'Visit {site_url}' );

		$this->assertStringContainsString( 'https://example.com/wp?x=1', $result );
		$this->assertStringContainsString( '&#038;y=2', $result );
	}

	public function test_unsubscribe_url_is_url_escaped_globally(): void {
		$replacer = new \LEAStudios\EmailTemplates\Email\Merge_Tag_Replacer();
		$html     = '<a href="{unsubscribe_url}">opt out</a>';

		$result = $replacer->replace_html(
			$html,
			[ 'unsubscribe_url' => 'javascript:alert(1)' ]
		);

		$this->assertStringNotContainsString( 'javascript:', $result, 'must strip dangerous schemes via esc_url' );
	}
}
