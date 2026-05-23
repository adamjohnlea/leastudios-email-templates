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
	 * Replace merge tags in an HTML email body. Values default to HTML-escaping
	 * via esc_html(); passing $escape_map lets specific tags opt into RAW
	 * (insert unescaped — trusted HTML payload only) or URL (esc_url for hrefs).
	 *
	 * @param string                     $content    HTML template containing {tags}.
	 * @param array<string, mixed>       $context    The tag values.
	 * @param array<string, Escape_Mode> $escape_map Map of unbraced tag name => escape mode. Tags absent from the map default to HTML.
	 * @return string HTML with tags replaced and per-tag escapes applied.
	 */
	public function replace_html( string $content, array $context = [], array $escape_map = [] ): string {
		return $this->substitute( $content, $context, $escape_map );
	}

	/**
	 * Replace merge tags in a subject line or other single-line plain-text
	 * field. Strips CR/LF/Tab from values to prevent email-header injection
	 * in case the subject is later concatenated into raw headers.
	 *
	 * @param string               $content Plain-text template containing {tags}.
	 * @param array<string, mixed> $context The tag values.
	 * @return string Plain text with tags replaced and CR/LF/Tab stripped.
	 */
	public function replace_subject( string $content, array $context = [] ): string {
		$context = array_merge( $this->get_global_tags(), $context );

		/** This filter is documented in self::substitute(). */
		$context = (array) apply_filters( 'leastudios_email_templates_merge_tags', $context, $content );

		$search  = [];
		$replace = [];

		foreach ( $context as $key => $value ) {
			$search[]  = '{' . $key . '}';
			$replace[] = preg_replace( '/[\r\n\t]+/', ' ', (string) $value ) ?? '';
		}

		return str_replace( $search, $replace, $content );
	}

	/**
	 * Internal substitution engine. Applies the per-tag escape mode from
	 * $escape_map to each value before inserting into the template. Tags not
	 * in the map default to Escape_Mode::HTML (the safe default).
	 *
	 * Globals (site_name, site_url, date) have their escape modes appended
	 * to $escape_map here so callers don't need to know about them.
	 *
	 * @param string                     $content    The template.
	 * @param array<string, mixed>       $context    The tag values.
	 * @param array<string, Escape_Mode> $escape_map Tag => Escape_Mode (unbraced keys).
	 * @return string
	 */
	private function substitute( string $content, array $context, array $escape_map ): string {
		$context    = array_merge( $this->get_global_tags(), $context );
		$escape_map = array_merge( $this->get_global_escape_modes(), $escape_map );

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
			$mode = $escape_map[ $key ] ?? Escape_Mode::HTML;

			$search[]  = '{' . $key . '}';
			$replace[] = $this->apply_escape( (string) $value, $mode );
		}

		return str_replace( $search, $replace, $content );
	}

	/**
	 * Apply an escape mode to a single value.
	 *
	 * @param string      $value The value to transform.
	 * @param Escape_Mode $mode  The escape mode.
	 * @return string
	 */
	private function apply_escape( string $value, Escape_Mode $mode ): string {
		return match ( $mode ) {
			Escape_Mode::HTML => esc_html( $value ),
			Escape_Mode::RAW  => $value,
			Escape_Mode::URL  => esc_url( $value ),
		};
	}

	/**
	 * Escape modes for the replacer's built-in global tags.
	 *
	 * Includes `unsubscribe_url`, which is a recipient-aware near-global tag
	 * injected into the context by Email_Sender (so Merge_Tag_Replacer stays
	 * recipient-ignorant).
	 *
	 * @return array<string, Escape_Mode>
	 */
	private function get_global_escape_modes(): array {
		return [
			'site_name'       => Escape_Mode::HTML,
			'site_url'        => Escape_Mode::URL,
			'date'            => Escape_Mode::HTML,
			'unsubscribe_url' => Escape_Mode::URL,
		];
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
		$date_format = get_option( 'date_format', 'F j, Y' );
		$rendered    = wp_date( is_string( $date_format ) ? $date_format : 'F j, Y' );

		return [
			'site_name' => (string) get_option( 'blogname', '' ),
			'site_url'  => home_url(),
			'date'      => false !== $rendered ? $rendered : '',
		];
	}
}
