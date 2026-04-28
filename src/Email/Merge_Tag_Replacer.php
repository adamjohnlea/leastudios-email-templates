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
	];

	/**
	 * Replace merge tags in content.
	 *
	 * @param string               $content The content with {tags}.
	 * @param array<string, mixed> $context The tag values.
	 * @return string Content with tags replaced.
	 */
	public function replace( string $content, array $context = [] ): string {
		// Add global tags.
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
			$tag = '{' . $key . '}';

			$search[]  = $tag;
			$replace[] = (string) $value;
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

		if ( 'jpy' === $cur ) {
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
