# Phase 5 — Theme Variants Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Allow admins to pick between a light or dark email theme (defaulting to the current light look), and progressively enhance light theme with a `prefers-color-scheme: dark` override for clients that respect it.

**Architecture:**
A `Theme` value object owns the colour-token set for each preset. `Template_Wrapper::wrap()` resolves the active theme from the branding option and passes the tokens into `templates/email/base.php` (alongside the existing variables). The template renders inline colour styles from those tokens, and emits an `@media (prefers-color-scheme: dark)` `<style>` block for the light preset only. The branding tab exposes a dropdown that persists `leastudios_email_templates_branding[theme]`.

**Tech Stack:** PHP 8.1+, WP Settings API, PHPUnit 9.6, existing `Template_Wrapper` pipeline. No new dependencies, no Node build step.

---

## File Structure

| File | Action | Responsibility |
|---|---|---|
| `src/Email/Theme.php` | Create | Value object: id, label, and `colors()` array. Static factory `from_id()` with fallback to light. Static `available()` listing the presets for the dropdown. |
| `templates/email/base.php` | Modify | Replace hardcoded chrome colours with `$colors[...]` lookups. Emit `<style>` block in `<head>` for prefers-color-scheme override when theme is light. |
| `src/Email/Template_Wrapper.php` | Modify | Resolve `Theme::from_id( $branding['theme'] ?? 'modern-light' )` and extract its data into the template render. |
| `src/Admin/Settings_Page.php` | Modify | Add Theme dropdown to branding form. Sanitize the new key with whitelist fallback to `modern-light`. |
| `leastudios-email-templates.php` | Modify | Seed `theme => 'modern-light'` in activation defaults. |
| `tests/ThemeTest.php` | Create | Unit tests for the value object: factory, fallback, token shape, available list. |
| `tests/TemplateWrapperTest.php` | Modify | Add two cases proving the dark theme palette appears in the rendered HTML when selected, and the light palette is used by default. |

---

## Token Set

Both themes expose the **same key set**, so the template can blindly index them:

| Key | Light value | Dark value | Used by |
|---|---|---|---|
| `outer_bg` | `#f4f4f7` | `#0f172a` | Outer wrapper `<body>` + outer table |
| `card_bg` | `#ffffff` | `#1e293b` | Inner body card |
| `text` | `#1f2937` | `#e2e8f0` | Body text colour |
| `muted` | `#6b7280` | `#94a3b8` | Footer text |
| `subtle` | `#9ca3af` | `#64748b` | Site-name micro line |

`primary_color` stays an independent branding setting — themes do not touch it.

---

## Task 1: Create `Theme` value object

**Files:**
- Create: `src/Email/Theme.php`
- Test: `tests/ThemeTest.php`

- [ ] **Step 1: Write the failing test**

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run from the plugin directory:

```
vendor/bin/phpunit --filter ThemeTest
```

Expected: FAIL with `Class "LEAStudios\EmailTemplates\Email\Theme" not found`.

- [ ] **Step 3: Implement `Theme`**

Write `src/Email/Theme.php`:

```php
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
     * @param string                $id                              Stable theme id stored in the branding option.
     * @param string                $label                           Human-readable label for the settings dropdown.
     * @param array<string, string> $colors                          Colour tokens (outer_bg, card_bg, text, muted, subtle).
     * @param bool                  $supports_prefers_dark_override  Whether to emit a `prefers-color-scheme: dark` style block.
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
     */
    public static function from_id( string $id ): self {
        return self::presets()[ $id ] ?? self::presets()[ self::DEFAULT_ID ];
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
            'modern-dark' => new self(
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
```

- [ ] **Step 4: Run test to verify it passes**

Run:

```
vendor/bin/phpunit --filter ThemeTest
```

Expected: 6 tests pass, no warnings.

- [ ] **Step 5: Commit**

```
git add src/Email/Theme.php tests/ThemeTest.php
git commit -m "Add Theme value object with modern-light and modern-dark presets"
```

---

## Task 2: Refactor `base.php` to use theme tokens

**Files:**
- Modify: `templates/email/base.php`

This task changes only the template. Rendered output for the **default** (light) theme must remain byte-equivalent in all visible chrome colours — the existing TemplateWrapperTest cases (`#4f46e5`, logo URL, footer text, social links) all stay green.

- [ ] **Step 1: Update the template header docblock**

In `templates/email/base.php`, update the docblock variables list to include the two new variables. The block currently lists `$body_html, $logo_url, $primary_color, $footer_text, $social_links, $site_name` — add:

```
 * @var array<string, string> $colors        Theme colour tokens: outer_bg, card_bg, text, muted, subtle.
 * @var bool                  $prefers_dark  Whether to emit a prefers-color-scheme:dark override.
```

- [ ] **Step 2: Replace hardcoded colours with token lookups**

In the same file, replace the four hardcoded chrome colours. Each one is exactly one occurrence so a targeted `Edit` works. Concretely:

| Find | Replace with |
|---|---|
| `background-color:#f4f4f7;font-family` | `background-color:<?php echo esc_attr( $colors['outer_bg'] ); ?>;font-family` |
| `<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:#f4f4f7;">` | `<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:<?php echo esc_attr( $colors['outer_bg'] ); ?>;">` |
| `background-color:#ffffff;border-radius:8px;padding:40px;font-size:16px;line-height:1.6;color:#1f2937;` | `background-color:<?php echo esc_attr( $colors['card_bg'] ); ?>;border-radius:8px;padding:40px;font-size:16px;line-height:1.6;color:<?php echo esc_attr( $colors['text'] ); ?>;` |
| `<p style="margin:0 0 15px;font-size:13px;line-height:1.5;color:#6b7280;">` | `<p style="margin:0 0 15px;font-size:13px;line-height:1.5;color:<?php echo esc_attr( $colors['muted'] ); ?>;">` |
| `<p style="margin:0 0 15px;font-size:13px;color:#6b7280;">` | `<p style="margin:0 0 15px;font-size:13px;color:<?php echo esc_attr( $colors['muted'] ); ?>;">` |
| `<p style="margin:0;font-size:12px;color:#9ca3af;">` | `<p style="margin:0;font-size:12px;color:<?php echo esc_attr( $colors['subtle'] ); ?>;">` |

Leave the `$primary_color` references and all non-colour styling untouched.

- [ ] **Step 3: Add prefers-color-scheme `<style>` block after the existing `<title>` line**

Inside the `<head>` block, insert this immediately after the `<title>...</title>` line and before the `<!--[if mso]>` block:

```php
<?php if ( $prefers_dark ) : ?>
<style type="text/css">
@media (prefers-color-scheme: dark) {
    .leastudios-outer { background-color: #0f172a !important; }
    .leastudios-card { background-color: #1e293b !important; color: #e2e8f0 !important; }
    .leastudios-muted { color: #94a3b8 !important; }
    .leastudios-subtle { color: #64748b !important; }
}
</style>
<?php endif; ?>
```

Then add these CSS class names (idempotent — they coexist with the existing inline styles) to four spots, each via `Edit`:

| Find | Replace with |
|---|---|
| `<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:<?php echo esc_attr( $colors['outer_bg'] ); ?>;">` | `<table class="leastudios-outer" role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:<?php echo esc_attr( $colors['outer_bg'] ); ?>;">` |
| `<td style="background-color:<?php echo esc_attr( $colors['card_bg'] ); ?>;border-radius:8px;padding:40px;font-size:16px;line-height:1.6;color:<?php echo esc_attr( $colors['text'] ); ?>;">` | `<td class="leastudios-card" style="background-color:<?php echo esc_attr( $colors['card_bg'] ); ?>;border-radius:8px;padding:40px;font-size:16px;line-height:1.6;color:<?php echo esc_attr( $colors['text'] ); ?>;">` |
| `<p style="margin:0 0 15px;font-size:13px;line-height:1.5;color:<?php echo esc_attr( $colors['muted'] ); ?>;">` | `<p class="leastudios-muted" style="margin:0 0 15px;font-size:13px;line-height:1.5;color:<?php echo esc_attr( $colors['muted'] ); ?>;">` |
| `<p style="margin:0;font-size:12px;color:<?php echo esc_attr( $colors['subtle'] ); ?>;">` | `<p class="leastudios-subtle" style="margin:0;font-size:12px;color:<?php echo esc_attr( $colors['subtle'] ); ?>;">` |

(The second `.leastudios-muted` paragraph — the social links — keeps inline-only since prefers-dark colours bleed through fine via the parent `.leastudios-card` rule; do not add the class there.)

- [ ] **Step 4: No new test needed for this step — Task 3 will exercise the template end-to-end. Skip to Task 3.**

(No commit yet — the template now references `$colors` and `$prefers_dark` which aren't passed in. Committing here breaks the existing tests. The wiring lands in Task 3 and Task 3 commits both together.)

---

## Task 3: Wire `Template_Wrapper` to resolve and pass the theme

**Files:**
- Modify: `src/Email/Template_Wrapper.php`
- Modify: `tests/TemplateWrapperTest.php`

- [ ] **Step 1: Write the two failing tests**

Append to `tests/TemplateWrapperTest.php` inside the existing class:

```php
public function test_default_theme_renders_light_palette(): void {
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

    $result = $this->wrapper->wrap( '<p>Body</p>' );

    $this->assertStringContainsString( '#f4f4f7', $result, 'outer_bg light token missing' );
    $this->assertStringContainsString( '#ffffff', $result, 'card_bg light token missing' );
    $this->assertStringContainsString( 'prefers-color-scheme: dark', $result, 'light theme should emit prefers-dark override' );
}

public function test_dark_theme_renders_dark_palette(): void {
    update_option(
        'leastudios_email_templates_branding',
        [
            'enabled'       => true,
            'logo_url'      => '',
            'primary_color' => '#4f46e5',
            'footer_text'   => '',
            'social_links'  => [],
            'theme'         => 'modern-dark',
        ]
    );

    $result = $this->wrapper->wrap( '<p>Body</p>' );

    $this->assertStringContainsString( '#0f172a', $result, 'outer_bg dark token missing' );
    $this->assertStringContainsString( '#1e293b', $result, 'card_bg dark token missing' );
    $this->assertStringNotContainsString( 'prefers-color-scheme: dark', $result, 'dark theme should NOT emit prefers-dark override' );
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run:

```
vendor/bin/phpunit --filter TemplateWrapperTest
```

Expected: the two new tests fail (extracted template references undefined `$colors` and `$prefers_dark`, so PHP emits a warning and the rendered HTML omits the colour tokens). All other tests in the class also likely fail for the same reason. Confirm this is the failure mode before moving on.

- [ ] **Step 3: Update `Template_Wrapper::wrap()` to resolve theme and pass tokens**

In `src/Email/Template_Wrapper.php`, replace the existing `wrap()` method body. Find this block:

```php
$logo_url      = $branding['logo_url'] ?? '';
$primary_color = $branding['primary_color'] ?? '#4f46e5';
$footer_text   = $branding['footer_text'] ?? '';
$social_links  = $branding['social_links'] ?? [];
$site_name     = get_option( 'blogname', '' );
```

Replace with:

```php
$logo_url      = $branding['logo_url'] ?? '';
$primary_color = $branding['primary_color'] ?? '#4f46e5';
$footer_text   = $branding['footer_text'] ?? '';
$social_links  = $branding['social_links'] ?? [];
$site_name     = get_option( 'blogname', '' );
$theme         = Theme::from_id( (string) ( $branding['theme'] ?? Theme::DEFAULT_ID ) );
```

Then find:

```php
extract(
    [
        'body_html'     => $body_html,
        'logo_url'      => $logo_url,
        'primary_color' => $primary_color,
        'footer_text'   => $footer_text,
        'social_links'  => $social_links,
        'site_name'     => $site_name,
    ]
);
```

Replace with:

```php
extract(
    [
        'body_html'     => $body_html,
        'logo_url'      => $logo_url,
        'primary_color' => $primary_color,
        'footer_text'   => $footer_text,
        'social_links'  => $social_links,
        'site_name'     => $site_name,
        'colors'        => $theme->colors,
        'prefers_dark'  => $theme->supports_prefers_dark_override,
    ]
);
```

- [ ] **Step 4: Run tests to verify they pass**

Run the full suite (this proves no regression in the other 8 TemplateWrapper cases either):

```
vendor/bin/phpunit --filter TemplateWrapperTest
```

Expected: all TemplateWrapper tests pass, including the two new ones.

- [ ] **Step 5: Run the full plugin test suite to confirm no other regressions**

```
composer test
```

Expected: 78 tests pass (the existing 76 plus the two new TemplateWrapper cases; ThemeTest's 6 added in Task 1).

- [ ] **Step 6: Commit**

```
git add templates/email/base.php src/Email/Template_Wrapper.php tests/TemplateWrapperTest.php
git commit -m "Pass theme tokens into base.php and add light/dark wrap tests"
```

---

## Task 4: Add Theme dropdown to the Branding tab

**Files:**
- Modify: `src/Admin/Settings_Page.php`
- Modify: `leastudios-email-templates.php`

- [ ] **Step 1: Seed `theme` in the activation defaults**

In `leastudios-email-templates.php`, find:

```php
add_option(
    'leastudios_email_templates_branding',
    [
        'enabled'       => true,
        'logo_url'      => '',
        'primary_color' => '#4f46e5',
        'footer_text'   => '',
        'social_links'  => [
            'twitter'   => '',
            'facebook'  => '',
            'linkedin'  => '',
            'instagram' => '',
        ],
    ]
);
```

Add `'theme' => 'modern-light',` immediately after the `'primary_color'` line. (Adding it mid-array is fine — `add_option` no-ops on existing options, so this only affects fresh installs.)

- [ ] **Step 2: Add the dropdown to the branding form**

In `src/Admin/Settings_Page.php::render_branding_tab()`, find the `<tr>` block for `Primary Color`. **Immediately after** that `</tr>` and before the `Footer Text` row, insert:

```php
<tr>
    <th scope="row"><label for="leastudios-theme"><?php esc_html_e( 'Theme', 'leastudios-email-templates' ); ?></label></th>
    <td>
        <?php
        $selected_theme = (string) ( $branding['theme'] ?? \LEAStudios\EmailTemplates\Email\Theme::DEFAULT_ID );
        ?>
        <select id="leastudios-theme" name="<?php echo esc_attr( self::BRANDING_OPTION ); ?>[theme]">
            <?php foreach ( \LEAStudios\EmailTemplates\Email\Theme::available() as $theme_id => $theme_label ) : ?>
                <option value="<?php echo esc_attr( $theme_id ); ?>" <?php selected( $selected_theme, $theme_id ); ?>>
                    <?php echo esc_html( $theme_label ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description"><?php esc_html_e( 'Modern Light adapts automatically on email clients that support dark mode (Apple Mail, iOS Mail). Modern Dark is always dark.', 'leastudios-email-templates' ); ?></p>
    </td>
</tr>
```

- [ ] **Step 3: Sanitize the new field**

In `src/Admin/Settings_Page.php::sanitize_branding()`, immediately before the `return $sanitized;` line, add:

```php
$theme_id           = (string) ( $input['theme'] ?? \LEAStudios\EmailTemplates\Email\Theme::DEFAULT_ID );
$sanitized['theme'] = isset( \LEAStudios\EmailTemplates\Email\Theme::available()[ $theme_id ] )
    ? $theme_id
    : \LEAStudios\EmailTemplates\Email\Theme::DEFAULT_ID;
```

(The whitelist check prevents a hand-crafted POST from persisting an arbitrary theme id. Falls back to the default rather than rejecting the save outright.)

- [ ] **Step 4: Run lint and full test suite**

```
composer lint && composer test
```

Expected: PHPCS clean, PHPStan clean at level 7, all 78 tests pass.

- [ ] **Step 5: Smoke-verify in the running site**

```
wp option get leastudios_email_templates_branding --format=json
```

Open `wp-admin → Email Templates → Branding`. The Theme dropdown is visible with "Modern Light" selected. Switch to "Modern Dark", click Save Branding.

Re-run:

```
wp option get leastudios_email_templates_branding --format=json
```

Expected: `"theme":"modern-dark"` present.

Click "Preview Email Template". The iframe renders with the dark palette (slate background, light text).

Switch back to "Modern Light", save, preview again. Light palette returns; the rendered HTML source includes the `prefers-color-scheme: dark` `<style>` block.

Also test a per-type preview (Email Types tab → expand any type → "Preview This Email") under both themes to confirm payment-receipt-style content honours the theme.

- [ ] **Step 6: Commit**

```
git add src/Admin/Settings_Page.php leastudios-email-templates.php
git commit -m "Add Theme dropdown to branding settings with whitelist sanitization"
```

---

## Self-Review (run before declaring Phase 5 done)

- **Spec coverage:** roadmap §"Phase 5" lists four scope items — refactor `base.php` to read tokens (Task 2), two presets (Task 1), `prefers-color-scheme` block (Task 2 step 3 + Task 3 wiring), branding dropdown persisting to `[theme]` (Task 4). All covered.
- **Type consistency:** `Theme::DEFAULT_ID`, `Theme::from_id()`, `Theme::available()`, `$theme->colors`, `$theme->supports_prefers_dark_override` — all referenced consistently across Tasks 1, 3, and 4.
- **No placeholders:** every code block in this plan is complete and ready to paste.
- **Manual visual verification only** (per roadmap) — Task 4 Step 5 covers it.

---

## Out of scope (defer to a later phase)

- Custom user-defined themes (token editor). Phase 5 ships **presets only**.
- Per-email-type theme override. Themes are site-wide.
- Bulk Litmus / Email-on-Acid testing across clients. Manual spot-check in Apple Mail / Gmail web / Outlook is enough for this phase.
