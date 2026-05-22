<?php
/**
 * Merge tag replacement engine.
 *
 * @package LEAStudios\EmailTemplates\Email
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Email;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Replaces {tag} placeholders with contextual values.
 */
class Merge_Tag_Replacer {

	/**
	 * Currency symbols for formatting.
	 *
	 * @var array<string, string>
	 */
	private const CURRENCY_SYMBOLS = [
		'usd' => '$',
		'gbp' => "\xc2\xa3",
		'eur' => "\xe2\x82\xac",
		'cad' => 'CA$',
		'aud' => 'A$',
		'nzd' => 'NZ$',
		'chf' => 'CHF ',
		'jpy' => "\xc2\xa5",
		'krw' => "\xe2\x82\xa9",
		'vnd' => "\xe2\x82\xab",
	];

	/**
	 * Stripe's zero-decimal currencies. Amounts are passed in whole units
	 * rather than the smallest currency unit, so they must not be divided
	 * by 100 when formatting for display.
	 *
	 * @link https://stripe.com/docs/currencies#zero-decimal
	 *
	 * @var array<int, string>
	 */
	private const ZERO_DECIMAL_CURRENCIES = [
		'bif',
		'clp',
		'djf',
		'gnf',
		'jpy',
		'kmf',
		'krw',
		'mga',
		'pyg',
		'rwf',
		'ugx',
		'vnd',
		'vuv',
		'xaf',
		'xof',
		'xpf',
	];

	/**
	 * Replace merge tags in an HTML email body. Values are HTML-escaped before
	 * substitution so user-controlled context (e.g. customer names) cannot
	 * inject markup or scripts into the rendered email.
	 *
	 * @param string               $content HTML template containing {tags}.
	 * @param array<string, mixed> $context The tag values.
	 * @return string HTML with tags replaced by escaped values.
	 */
	public function replace_html( string $content, array $context = [] ): string {
		return $this->substitute(
			$content,
			$context,
			static fn( string $value ): string => esc_html( $value )
		);
	}

	/**
	 * Replace merge tags in a subject line or other single-line plain-text
	 * field. Strips CR/LF from values to prevent email-header injection in
	 * case the subject is later concatenated into raw headers.
	 *
	 * @param string               $content Plain-text template containing {tags}.
	 * @param array<string, mixed> $context The tag values.
	 * @return string Plain text with tags replaced and CR/LF stripped.
	 */
	public function replace_subject( string $content, array $context = [] ): string {
		return $this->substitute(
			$content,
			$context,
			static fn( string $value ): string => preg_replace( '/[\r\n\t]+/', ' ', $value ) ?? ''
		);
	}

	/**
	 * Internal substitution engine. Applies $sanitize to each value before
	 * inserting into the template via str_replace.
	 *
	 * @param string                  $content  The template.
	 * @param array<string, mixed>    $context  The tag values.
	 * @param callable(string):string $sanitize Per-value sanitiser.
	 * @return string
	 */
	private function substitute( string $content, array $context, callable $sanitize ): string {
		$context = array_merge( $this->get_global_tags(), $context );

		/**
		 * Filters the merge tag context before replacement.
		 *
		 * @param array<string, mixed> $context The tag values.
		 * @param string               $content The content being processed.
		 */
		$context = (array) apply_filters( 'leastudios_email_templates_merge_tags', $context, $content );

		$search  = [];
		$replace = [];

		foreach ( $context as $key => $value ) {
			$search[]  = '{' . $key . '}';
			$replace[] = $sanitize( (string) $value );
		}

		return str_replace( $search, $replace, $content );
	}

	/**
	 * Format an amount from smallest currency unit for display.
	 *
	 * @param int    $amount   Amount in smallest currency unit (e.g. cents).
	 * @param string $currency Currency code.
	 * @return string Formatted amount string.
	 */
	public static function format_amount( int $amount, string $currency ): string {
		$cur    = strtolower( $currency );
		$symbol = self::CURRENCY_SYMBOLS[ $cur ] ?? strtoupper( $currency ) . ' ';

		if ( in_array( $cur, self::ZERO_DECIMAL_CURRENCIES, true ) ) {
			return $symbol . number_format( $amount );
		}

		return $symbol . number_format( $amount / 100, 2 );
	}

	/**
	 * Get globally available merge tags.
	 *
	 * @return array<string, string>
	 */
	private function get_global_tags(): array {
		return [
			'site_name' => get_option( 'blogname', '' ),
			'site_url'  => home_url(),
			'date'      => wp_date( get_option( 'date_format', 'F j, Y' ) ),
		];
	}
}
