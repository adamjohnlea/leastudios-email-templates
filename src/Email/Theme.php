<?php
/**
 * Email theme value object.
 *
 * @package LEAStudios\EmailTemplates\Email
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Email;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Holds the colour-token set for a single email theme preset.
 *
 * Theme controls only the *chrome* colours (outer background, body card,
 * text, muted text). `primary_color` remains an independent branding
 * setting.
 */
final class Theme {

	public const DEFAULT_ID = 'modern-light';

	/**
	 * Creates a new Theme instance.
	 *
	 * @param string                $id                             Stable theme id stored in the branding option.
	 * @param string                $label                          Human-readable label for the settings dropdown.
	 * @param array<string, string> $colors                         Colour tokens (outer_bg, card_bg, text, muted, subtle).
	 * @param bool                  $supports_prefers_dark_override Whether to emit a `prefers-color-scheme: dark` style block.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $label,
		public readonly array $colors,
		public readonly bool $supports_prefers_dark_override,
	) {}

	/**
	 * Resolve a theme by id, falling back to the default on unknown input.
	 *
	 * @param string $id Theme id from the branding option.
	 * @return self Resolved theme instance.
	 */
	public static function from_id( string $id ): self {
		$presets = self::presets();
		return $presets[ $id ] ?? $presets[ self::DEFAULT_ID ];
	}

	/**
	 * Map of id => label for use in the settings dropdown.
	 *
	 * @return array<string, string>
	 */
	public static function available(): array {
		$map = [];
		foreach ( self::presets() as $theme ) {
			$map[ $theme->id ] = $theme->label;
		}
		return $map;
	}

	/**
	 * Presets registry. Keyed by id.
	 *
	 * @return array<string, self>
	 */
	private static function presets(): array {
		return [
			'modern-light' => new self(
				'modern-light',
				__( 'Modern Light', 'leastudios-email-templates' ),
				[
					'outer_bg' => '#f4f4f7',
					'card_bg'  => '#ffffff',
					'text'     => '#1f2937',
					'muted'    => '#6b7280',
					'subtle'   => '#9ca3af',
				],
				true,
			),
			'modern-dark'  => new self(
				'modern-dark',
				__( 'Modern Dark', 'leastudios-email-templates' ),
				[
					'outer_bg' => '#0f172a',
					'card_bg'  => '#1e293b',
					'text'     => '#e2e8f0',
					'muted'    => '#94a3b8',
					'subtle'   => '#64748b',
				],
				false,
			),
		];
	}
}
