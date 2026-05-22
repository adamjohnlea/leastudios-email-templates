<?php
/**
 * Convert HTML email bodies to a readable plain-text alternative.
 *
 * @package LEAStudios\EmailTemplates\Email
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Email;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Pure helper — no I/O, no WordPress runtime dependencies — so it can be
 * invoked from a `phpmailer_init` callback or unit-tested without
 * bootstrapping the mail pipeline.
 *
 * The output is intentionally lossy: tables flatten to whitespace-separated
 * cells, images become their alt text, complex layouts collapse. It is a
 * readability fallback for clients that show the text/plain part — not a
 * faithful rendering of the HTML.
 */
final class Plain_Text_Generator {

	/**
	 * Convert HTML to readable plain text.
	 *
	 * @param string $html The HTML body.
	 * @return string Plain-text representation.
	 */
	public static function from_html( string $html ): string {
		if ( '' === $html ) {
			return '';
		}

		// Strip <script>/<style> blocks (and their contents) before any other transform.
		$clean = (string) ( preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', '', $html ) ?? $html );

		// Anchor tags: "text (url)" — collapse to just the URL when text equals href.
		$clean = (string) (
			preg_replace_callback(
				'#<a\b[^>]*href\s*=\s*["\']([^"\']+)["\'][^>]*>(.*?)</a>#is',
				static function ( array $m ): string {
					$href = trim( $m[1] );
					$text = trim( wp_strip_all_tags( $m[2] ) );
					if ( '' === $text || $text === $href ) {
						return $href;
					}
					return $text . ' (' . $href . ')';
				},
				$clean
			) ?? $clean
		);

		// Images → their alt text (or empty if absent).
		$clean = (string) (
			preg_replace_callback(
				'#<img\b[^>]*alt\s*=\s*["\']([^"\']*)["\'][^>]*/?>#is',
				static fn( array $m ): string => trim( $m[1] ),
				$clean
			) ?? $clean
		);
		$clean = (string) ( preg_replace( '#<img\b[^>]*/?>#i', '', $clean ) ?? $clean );

		// Block-level breaks: turn each closing block tag into a newline so
		// paragraph structure survives the tag strip.
		$clean = (string) ( preg_replace( '#</(p|div|h[1-6]|tr|li|blockquote)\s*>#i', "\n", $clean ) ?? $clean );

		// List items: prefix with "- " on their opening tag.
		$clean = (string) ( preg_replace( '#<li\b[^>]*>#i', '- ', $clean ) ?? $clean );

		// Table cells: separate with a tab.
		$clean = (string) ( preg_replace( '#</t[dh]\s*>#i', "\t", $clean ) ?? $clean );

		// <br> → newline.
		$clean = (string) ( preg_replace( '#<br\s*/?>#i', "\n", $clean ) ?? $clean );

		// Strip remaining tags.
		$clean = wp_strip_all_tags( $clean );

		// Decode entities, then normalise whitespace.
		$clean = html_entity_decode( $clean, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// Collapse runs of spaces/tabs but keep newlines.
		$clean = (string) ( preg_replace( '#[\t ]+#', ' ', $clean ) ?? $clean );

		// Collapse 3+ newlines into a double newline (paragraph break).
		$clean = (string) ( preg_replace( "#\n{3,}#", "\n\n", $clean ) ?? $clean );

		// Trim per-line trailing whitespace.
		$lines = array_map( 'rtrim', explode( "\n", $clean ) );
		$clean = implode( "\n", $lines );

		// Drop a leading whitespace-only line that block-newline insertion
		// can produce when the input starts with a block-level tag.
		$clean = (string) ( preg_replace( "#^\s*\n+#", '', $clean ) ?? $clean );

		return trim( $clean );
	}
}
