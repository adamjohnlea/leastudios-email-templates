<?php
/**
 * Tests for Theme.
 *
 * @package LEAStudios\EmailTemplates\Tests
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Tests;

use LEAStudios\EmailTemplates\Email\Theme;
use LEAStudios\Tests\TestCase;

/**
 * @covers \LEAStudios\EmailTemplates\Email\Theme
 */
class ThemeTest extends TestCase {

	public function test_light_theme_has_expected_tokens(): void {
		$theme = Theme::from_id( 'modern-light' );

		$this->assertSame( 'modern-light', $theme->id );
		$this->assertSame( '#f4f4f7', $theme->colors['outer_bg'] );
		$this->assertSame( '#ffffff', $theme->colors['card_bg'] );
		$this->assertSame( '#1f2937', $theme->colors['text'] );
		$this->assertSame( '#6b7280', $theme->colors['muted'] );
		$this->assertSame( '#9ca3af', $theme->colors['subtle'] );
	}

	public function test_dark_theme_has_expected_tokens(): void {
		$theme = Theme::from_id( 'modern-dark' );

		$this->assertSame( 'modern-dark', $theme->id );
		$this->assertSame( '#0f172a', $theme->colors['outer_bg'] );
		$this->assertSame( '#1e293b', $theme->colors['card_bg'] );
		$this->assertSame( '#e2e8f0', $theme->colors['text'] );
		$this->assertSame( '#94a3b8', $theme->colors['muted'] );
		$this->assertSame( '#64748b', $theme->colors['subtle'] );
	}

	public function test_unknown_id_falls_back_to_light(): void {
		$theme = Theme::from_id( 'no-such-theme' );

		$this->assertSame( 'modern-light', $theme->id );
	}

	public function test_empty_id_falls_back_to_light(): void {
		$theme = Theme::from_id( '' );

		$this->assertSame( 'modern-light', $theme->id );
	}

	public function test_available_lists_both_presets_with_labels(): void {
		$available = Theme::available();

		$this->assertArrayHasKey( 'modern-light', $available );
		$this->assertArrayHasKey( 'modern-dark', $available );
		$this->assertIsString( $available['modern-light'] );
		$this->assertIsString( $available['modern-dark'] );
	}

	public function test_light_theme_supports_prefers_dark_override(): void {
		$light = Theme::from_id( 'modern-light' );
		$dark  = Theme::from_id( 'modern-dark' );

		$this->assertTrue( $light->supports_prefers_dark_override );
		$this->assertFalse( $dark->supports_prefers_dark_override );
	}
}
