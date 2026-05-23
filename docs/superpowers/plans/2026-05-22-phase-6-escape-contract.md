# Phase 6 — Per-Tag Escape Contract Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the implicit "every merge-tag value is HTML-escaped" behaviour with an explicit per-tag escape contract — `html` (default), `raw`, or `url` — so future tags can render embedded HTML (e.g. `{order_table}`) or link URLs (e.g. `{unsubscribe_url}`) safely.

**Architecture:** A new `Escape_Mode` string-backed enum names the modes. `Email_Type::available_tags()` returns the richer shape `array<string, array{description: string, escape: Escape_Mode}>` — keys keep braces (existing callers use `array_keys()` only, so they're unaffected). A new `Email_Type::escape_map()` helper exposes a `string => Escape_Mode` map keyed by the unbraced tag name (matching `$context` keys). `Merge_Tag_Replacer::replace_html()` gains an optional `$escape_map` parameter; when a tag isn't in the map, it falls back to `Escape_Mode::HTML` (= today's behaviour). `Email_Sender::compose()` and `Settings_Page::handle_preview_type()` build the map from the type and pass it through. `replace_subject()` is **not** changed — subjects stay plain text with CR/LF stripping only.

**Tech Stack:** PHP 8.1+ backed enums, WordPress `esc_html`/`esc_url`, PHPUnit 9.6, PHPStan level 7. No new dependencies.

---

## File Structure

| File | Action | Responsibility |
|---|---|---|
| `src/Email/Escape_Mode.php` | Create | Backed string enum: `HTML = 'html'`, `RAW = 'raw'`, `URL = 'url'`. |
| `src/Email/Email_Type.php` | Modify | `available_tags()` returns the new shape. New `escape_map()` returns `string => Escape_Mode`. |
| `src/Email/Merge_Tag_Replacer.php` | Modify | `replace_html()` gains optional `array<string, Escape_Mode> $escape_map`. Internal `substitute()` dispatches per-tag. Global tags get their own escape modes (`site_url` becomes `URL`). |
| `src/Email/Email_Sender.php` | Modify | `compose()` passes `$type->escape_map()` to `replace_html()`. |
| `src/Admin/Settings_Page.php` | Modify | `handle_preview_type()` passes `$type->escape_map()` to `replace_html()`. |
| `tests/EscapeModeTest.php` | Create | Enum case existence + string values. |
| `tests/EmailTypeTest.php` | Create | New shape of `available_tags()`, presence of `escape_map()`, exhaustive coverage of cases. |
| `tests/MergeTagReplacerTest.php` | Modify | Add raw + url mode tests; back-compat path (no map) keeps existing assertions green. |
| `tests/SampleContextTest.php` | Modify | Update one assertion that currently expects no remaining `{` in the rendered preview — verify the assertion still holds with the new contract. |

`Sample_Context` itself is **not** modified — values stay plain text; whether they're escaped HTML or raw HTML is decided by the new contract at render time.

---

## Background-context cheatsheet (for the implementer)

- The codebase uses PHPStan level 7. Inline array shapes like `array{description: string, escape: Escape_Mode}` are supported. Use them.
- `Email_Type::available_tags()` is currently called in three places (`Settings_Page.php:437`, `tests/SampleContextTest.php:28`, `tests/SampleContextTest.php:42`) — all of them use `array_keys()` only. The new shape is invisible to them.
- `Merge_Tag_Replacer::replace_html(string, array)` is currently called in:
  - `Email_Sender::compose()` at `src/Email/Email_Sender.php:132`.
  - `Settings_Page::handle_preview_type()` at `src/Admin/Settings_Page.php:515`.
  - `Template_Wrapper::wrap()` at `src/Email/Template_Wrapper.php:143` (renders footer text). Footer text is the branding admin's free-form HTML field — it has no per-tag escape contract, so this caller passes no map and gets the default (all HTML-escaped). Leave it alone.
- `replace_subject()` keeps its current signature and behaviour. CR/LF stripping is a header-injection mitigation, not an escape mode.
- Global tags (`site_name`, `site_url`, `date`) live in `Merge_Tag_Replacer::get_global_tags()`. The replacer owns their escape modes — it adds them into the effective escape map before substituting.

---

## Task 1: Create `Escape_Mode` enum

**Files:**
- Create: `src/Email/Escape_Mode.php`
- Test: `tests/EscapeModeTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
/**
 * Tests for Escape_Mode.
 *
 * @package LEAStudios\EmailTemplates\Tests
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Tests;

use LEAStudios\EmailTemplates\Email\Escape_Mode;
use LEAStudios\Tests\TestCase;

/**
 * @covers \LEAStudios\EmailTemplates\Email\Escape_Mode
 */
class EscapeModeTest extends TestCase {

    public function test_html_case_has_value_html(): void {
        $this->assertSame( 'html', Escape_Mode::HTML->value );
    }

    public function test_raw_case_has_value_raw(): void {
        $this->assertSame( 'raw', Escape_Mode::RAW->value );
    }

    public function test_url_case_has_value_url(): void {
        $this->assertSame( 'url', Escape_Mode::URL->value );
    }

    public function test_enum_has_exactly_three_cases(): void {
        $this->assertCount( 3, Escape_Mode::cases() );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

From `/Users/adamlea/Herd/leastudios-plugins/wp-content/plugins/leastudios-email-templates`:

```
vendor/bin/phpunit --filter EscapeModeTest
```

Expected: FAIL with `Class "LEAStudios\EmailTemplates\Email\Escape_Mode" not found`.

- [ ] **Step 3: Implement `Escape_Mode`**

Write `src/Email/Escape_Mode.php`:

```php
<?php
/**
 * Escape modes for merge-tag values.
 *
 * @package LEAStudios\EmailTemplates\Email
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Email;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

/**
 * How a merge-tag value should be escaped when inserted into an HTML email.
 *
 * - HTML: plain-text value, HTML-escaped via esc_html(). The safe default.
 * - RAW: trusted HTML payload, inserted unescaped. Only use when the value
 *   source is server-side and cannot include user input.
 * - URL: link href; passed through esc_url(). Empty when the input is not
 *   a recognisable URL.
 */
enum Escape_Mode: string {

    case HTML = 'html';
    case RAW  = 'raw';
    case URL  = 'url';
}
```

- [ ] **Step 4: Run test to verify it passes**

```
vendor/bin/phpunit --filter EscapeModeTest
```

Expected: 4 tests pass.

- [ ] **Step 5: Lint**

```
composer lint
```

Expected: PHPCS + PHPStan clean.

- [ ] **Step 6: Commit**

```
git add src/Email/Escape_Mode.php tests/EscapeModeTest.php
git commit -m "Add Escape_Mode enum for per-tag merge-tag escape contract"
```

---

## Task 2: Update `Email_Type::available_tags()` to new shape + add `escape_map()`

**Files:**
- Modify: `src/Email/Email_Type.php`
- Create: `tests/EmailTypeTest.php`

### Step 1: Write the failing tests

Create `tests/EmailTypeTest.php`:

```php
<?php
/**
 * Tests for Email_Type.
 *
 * @package LEAStudios\EmailTemplates\Tests
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Tests;

use LEAStudios\EmailTemplates\Email\Email_Type;
use LEAStudios\EmailTemplates\Email\Escape_Mode;
use LEAStudios\Tests\TestCase;

/**
 * @covers \LEAStudios\EmailTemplates\Email\Email_Type
 */
class EmailTypeTest extends TestCase {

    public function test_available_tags_returns_description_and_escape_per_tag(): void {
        $tags = Email_Type::PAYMENT_RECEIPT->available_tags();

        $this->assertArrayHasKey( '{customer_name}', $tags );
        $this->assertArrayHasKey( 'description', $tags['{customer_name}'] );
        $this->assertArrayHasKey( 'escape', $tags['{customer_name}'] );
        $this->assertIsString( $tags['{customer_name}']['description'] );
        $this->assertSame( Escape_Mode::HTML, $tags['{customer_name}']['escape'] );
    }

    public function test_available_tags_keys_keep_braces(): void {
        $tags = Email_Type::PAYMENT_RECEIPT->available_tags();

        foreach ( array_keys( $tags ) as $tag ) {
            $this->assertMatchesRegularExpression( '/^\{[a-z_]+\}$/', $tag, "Tag key {$tag} should be {braced_snake_case}" );
        }
    }

    public function test_every_case_advertises_at_least_the_common_tags(): void {
        $common = [ '{customer_name}', '{customer_email}', '{site_name}', '{site_url}', '{date}' ];

        foreach ( Email_Type::cases() as $case ) {
            $keys = array_keys( $case->available_tags() );
            foreach ( $common as $tag ) {
                $this->assertContains( $tag, $keys, "{$case->value} missing common tag {$tag}" );
            }
        }
    }

    public function test_every_case_returns_html_escape_mode_for_every_tag_by_default(): void {
        // Phase 6 introduces the contract but no production tag opts into RAW
        // or URL yet. This asserts that the migration kept the safe default
        // across the board.
        foreach ( Email_Type::cases() as $case ) {
            foreach ( $case->available_tags() as $tag => $meta ) {
                $this->assertSame( Escape_Mode::HTML, $meta['escape'], "{$case->value} tag {$tag} should default to HTML escape" );
            }
        }
    }

    public function test_escape_map_returns_unbraced_keys(): void {
        $map = Email_Type::PAYMENT_RECEIPT->escape_map();

        $this->assertArrayHasKey( 'customer_name', $map );
        $this->assertArrayNotHasKey( '{customer_name}', $map );
        $this->assertSame( Escape_Mode::HTML, $map['customer_name'] );
    }

    public function test_escape_map_covers_every_advertised_tag(): void {
        foreach ( Email_Type::cases() as $case ) {
            $tags_count = count( $case->available_tags() );
            $map_count  = count( $case->escape_map() );
            $this->assertSame( $tags_count, $map_count, "{$case->value} escape_map count differs from available_tags count" );
        }
    }
}
```

### Step 2: Run tests to verify they fail

```
vendor/bin/phpunit --filter EmailTypeTest
```

Expected: all six tests fail. The first three fail because `$tags['{customer_name}']` is still a plain string (no `description`/`escape` keys). The last three fail because `escape_map()` doesn't exist yet.

### Step 3: Rewrite `available_tags()` to the new shape

In `src/Email/Email_Type.php`, replace the entire `available_tags()` method (currently lines 74-123) with this version. The old `$common`/`$specific` tag descriptions move into the new shape; every entry gets `'escape' => Escape_Mode::HTML`.

```php
	/**
	 * Get available merge tags for this email type.
	 *
	 * @return array<string, array{description: string, escape: Escape_Mode}>
	 */
	public function available_tags(): array {
		$common = [
			'{customer_name}'  => [
				'description' => __( 'Customer name', 'leastudios-email-templates' ),
				'escape'      => Escape_Mode::HTML,
			],
			'{customer_email}' => [
				'description' => __( 'Customer email', 'leastudios-email-templates' ),
				'escape'      => Escape_Mode::HTML,
			],
			'{site_name}'      => [
				'description' => __( 'Site name', 'leastudios-email-templates' ),
				'escape'      => Escape_Mode::HTML,
			],
			'{site_url}'       => [
				'description' => __( 'Site URL', 'leastudios-email-templates' ),
				'escape'      => Escape_Mode::URL,
			],
			'{date}'           => [
				'description' => __( 'Current date', 'leastudios-email-templates' ),
				'escape'      => Escape_Mode::HTML,
			],
		];

		$specific = match ( $this ) {
			self::PAYMENT_RECEIPT      => [
				'{amount}'       => [
					'description' => __( 'Payment amount', 'leastudios-email-templates' ),
					'escape'      => Escape_Mode::HTML,
				],
				'{currency}'     => [
					'description' => __( 'Currency code', 'leastudios-email-templates' ),
					'escape'      => Escape_Mode::HTML,
				],
				'{product_name}' => [
					'description' => __( 'Product name', 'leastudios-email-templates' ),
					'escape'      => Escape_Mode::HTML,
				],
				'{order_type}'   => [
					'description' => __( 'Order type (one-time or subscription)', 'leastudios-email-templates' ),
					'escape'      => Escape_Mode::HTML,
				],
				'{payment_id}'   => [
					'description' => __( 'Stripe Payment Intent ID', 'leastudios-email-templates' ),
					'escape'      => Escape_Mode::HTML,
				],
				'{order_id}'     => [
					'description' => __( 'Local order ID', 'leastudios-email-templates' ),
					'escape'      => Escape_Mode::HTML,
				],
			],
			self::SUBSCRIPTION_CREATED => [
				'{product_name}' => [
					'description' => __( 'Product name', 'leastudios-email-templates' ),
					'escape'      => Escape_Mode::HTML,
				],
				'{amount}'       => [
					'description' => __( 'Payment amount', 'leastudios-email-templates' ),
					'escape'      => Escape_Mode::HTML,
				],
				'{currency}'     => [
					'description' => __( 'Currency code', 'leastudios-email-templates' ),
					'escape'      => Escape_Mode::HTML,
				],
				'{period_end}'   => [
					'description' => __( 'Current period end date', 'leastudios-email-templates' ),
					'escape'      => Escape_Mode::HTML,
				],
			],
			self::SUBSCRIPTION_RENEWED => [
				'{product_name}'   => [
					'description' => __( 'Product name', 'leastudios-email-templates' ),
					'escape'      => Escape_Mode::HTML,
				],
				'{invoice_amount}' => [
					'description' => __( 'Invoice amount', 'leastudios-email-templates' ),
					'escape'      => Escape_Mode::HTML,
				],
				'{currency}'       => [
					'description' => __( 'Currency code', 'leastudios-email-templates' ),
					'escape'      => Escape_Mode::HTML,
				],
				'{period_end}'     => [
					'description' => __( 'Next billing date', 'leastudios-email-templates' ),
					'escape'      => Escape_Mode::HTML,
				],
			],
			self::PAYMENT_FAILED       => [
				'{product_name}'   => [
					'description' => __( 'Product name', 'leastudios-email-templates' ),
					'escape'      => Escape_Mode::HTML,
				],
				'{invoice_amount}' => [
					'description' => __( 'Invoice amount', 'leastudios-email-templates' ),
					'escape'      => Escape_Mode::HTML,
				],
				'{currency}'       => [
					'description' => __( 'Currency code', 'leastudios-email-templates' ),
					'escape'      => Escape_Mode::HTML,
				],
			],
			self::REFUND_PROCESSED     => [
				'{refunded_amount}' => [
					'description' => __( 'Refund amount', 'leastudios-email-templates' ),
					'escape'      => Escape_Mode::HTML,
				],
				'{amount}'          => [
					'description' => __( 'Original payment amount', 'leastudios-email-templates' ),
					'escape'      => Escape_Mode::HTML,
				],
				'{currency}'        => [
					'description' => __( 'Currency code', 'leastudios-email-templates' ),
					'escape'      => Escape_Mode::HTML,
				],
				'{product_name}'    => [
					'description' => __( 'Product name', 'leastudios-email-templates' ),
					'escape'      => Escape_Mode::HTML,
				],
				'{order_id}'        => [
					'description' => __( 'Local order ID', 'leastudios-email-templates' ),
					'escape'      => Escape_Mode::HTML,
				],
			],
		};

		return array_merge( $common, $specific );
	}
```

Note that `{site_url}` in `$common` is intentionally `Escape_Mode::URL` — the only non-HTML default tag. This matches its semantic (it's a URL produced by `home_url()`). The other site-related tag, `{site_name}`, remains HTML.

### Step 4: Add the `escape_map()` helper

Append immediately after `available_tags()` (still inside the enum body, before the private body helpers):

```php
	/**
	 * Return a map of unbraced-tag-name => Escape_Mode, suitable for passing
	 * to Merge_Tag_Replacer::replace_html() as its $escape_map argument.
	 *
	 * @return array<string, Escape_Mode>
	 */
	public function escape_map(): array {
		$map = [];
		foreach ( $this->available_tags() as $tag => $meta ) {
			$map[ trim( $tag, '{}' ) ] = $meta['escape'];
		}
		return $map;
	}
```

### Step 5: Run all tests to verify EmailType is green AND nothing else broke

```
composer test
```

Expected: all tests pass. Critically:
- The 6 new `EmailTypeTest` cases pass.
- `SampleContextTest::test_for_payment_receipt_provides_all_non_global_tags` and `::test_for_payment_receipt_resolves_every_tag_after_replacer_globals` still pass — both use `array_keys()` on the new shape, which still returns the same `{tag}` strings.
- `Settings_Page` still renders the available-tags list (covered indirectly by any admin-page request — not a unit test, just confirm `composer lint` is clean for that file in Step 6).

If any test fails outside `EmailTypeTest`, do not "fix" it by silencing the failure — investigate. The new shape should be invisible to existing `array_keys()` callers.

### Step 6: Lint

```
composer lint
```

Expected: PHPCS clean, PHPStan level 7 clean.

### Step 7: Commit

```
git add src/Email/Email_Type.php tests/EmailTypeTest.php
git commit -m "Add per-tag escape mode to Email_Type::available_tags()"
```

---

## Task 3: Wire `Merge_Tag_Replacer` to dispatch by escape mode

**Files:**
- Modify: `src/Email/Merge_Tag_Replacer.php`
- Modify: `tests/MergeTagReplacerTest.php`

### Step 1: Write the failing tests

Append these test methods to `tests/MergeTagReplacerTest.php` inside the existing class. Also add a `use` for `Escape_Mode` at the top of the file (alongside the existing `use LEAStudios\EmailTemplates\Email\Merge_Tag_Replacer;`).

Add at the top of the file:

```php
use LEAStudios\EmailTemplates\Email\Escape_Mode;
```

And inside the class:

```php
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
```

### Step 2: Run new tests to verify they fail

```
vendor/bin/phpunit --filter MergeTagReplacerTest
```

Expected: the six new tests fail (the third argument doesn't exist; raw/url/global-url assertions don't match). All 14 existing tests should still pass — the replacer signature is backward-compatible until you change it in Step 3.

### Step 3: Update `replace_html()` and `substitute()`

In `src/Email/Merge_Tag_Replacer.php`:

**3a.** Add a `use` statement isn't needed (same namespace). Just reference `Escape_Mode` directly.

**3b.** Change `replace_html()` to:

```php
	/**
	 * Replace merge tags in an HTML email body. Values default to HTML-escaping
	 * via esc_html(); passing $escape_map lets specific tags opt into RAW
	 * (insert unescaped — trusted HTML payload only) or URL (esc_url for hrefs).
	 *
	 * @param string                       $content    HTML template containing {tags}.
	 * @param array<string, mixed>         $context    The tag values.
	 * @param array<string, Escape_Mode>   $escape_map Map of unbraced tag name => escape mode. Tags absent from the map default to HTML.
	 * @return string HTML with tags replaced and per-tag escapes applied.
	 */
	public function replace_html( string $content, array $context = [], array $escape_map = [] ): string {
		return $this->substitute( $content, $context, $escape_map );
	}
```

**3c.** Replace `substitute()` entirely. The old version took a `callable $sanitize`; the new one takes `array<string, Escape_Mode> $escape_map` and dispatches per-tag. `replace_subject()` no longer goes through `substitute()` — its CR/LF logic is small enough to inline.

```php
	/**
	 * Internal substitution engine. Applies the per-tag escape mode from
	 * $escape_map to each value before inserting into the template. Tags not
	 * in the map default to Escape_Mode::HTML (the safe default).
	 *
	 * Globals (site_name, site_url, date) have their escape modes appended
	 * to $escape_map here so callers don't need to know about them.
	 *
	 * @param string                       $content    The template.
	 * @param array<string, mixed>         $context    The tag values.
	 * @param array<string, Escape_Mode>   $escape_map Tag => Escape_Mode (unbraced keys).
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
	 * @param string      $value The unsafe value.
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
	 * @return array<string, Escape_Mode>
	 */
	private function get_global_escape_modes(): array {
		return [
			'site_name' => Escape_Mode::HTML,
			'site_url'  => Escape_Mode::URL,
			'date'      => Escape_Mode::HTML,
		];
	}
```

**3d.** Update `replace_subject()` so it no longer routes through the old `substitute()`. Replace it with this self-contained version (CR/LF stripping only — no escape map dispatch, because subjects are always plain text):

```php
	/**
	 * Replace merge tags in a subject line or other single-line plain-text
	 * field. Strips CR/LF/Tab from values to prevent email-header injection
	 * in case the subject is later concatenated into raw headers.
	 *
	 * @param string               $content Plain-text template containing {tags}.
	 * @param array<string, mixed> $context The tag values.
	 * @return string Plain text with tags replaced and CR/LF stripped.
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
```

### Step 4: Run MergeTagReplacer tests to verify green

```
vendor/bin/phpunit --filter MergeTagReplacerTest
```

Expected: all 20 tests pass (14 original + 6 new).

### Step 5: Run the full suite

```
composer test
```

Expected: every test passes. The Email_Sender and Settings_Page integration tests still use `replace_html()` without an escape map (yet) — they get the default-HTML behaviour, which matches their existing expectations.

### Step 6: Lint

```
composer lint
```

Expected: clean.

### Step 7: Commit

```
git add src/Email/Merge_Tag_Replacer.php tests/MergeTagReplacerTest.php
git commit -m "Add escape_map argument to replace_html with per-mode dispatch"
```

---

## Task 4: Pass `escape_map()` through from `Email_Sender` and `Settings_Page`

**Files:**
- Modify: `src/Email/Email_Sender.php`
- Modify: `src/Admin/Settings_Page.php`

There's no new unit test for this task — the wiring is mechanical, the replacer's own tests already prove escape dispatch works, and the Email_Sender / Settings_Page test suites already exercise the path end-to-end (the wired `escape_map()` produces identical output today because every tag is `Escape_Mode::HTML`).

### Step 1: Update `Email_Sender::compose()`

Open `src/Email/Email_Sender.php`. Find this line (currently line 132):

```php
		$body    = $this->replacer->replace_html( $body, $context );
```

Replace it with:

```php
		$body    = $this->replacer->replace_html( $body, $context, $type->escape_map() );
```

### Step 2: Update `Settings_Page::handle_preview_type()`

Open `src/Admin/Settings_Page.php`. Find this line (currently line 515):

```php
		$rendered_body    = $replacer->replace_html( $body_tpl, $sample );
```

Replace it with:

```php
		$rendered_body    = $replacer->replace_html( $body_tpl, $sample, $type->escape_map() );
```

### Step 3: Run full suite to verify nothing regressed

```
composer test
```

Expected: every test passes. The end-to-end paths are now passing the escape map but every advertised tag's mode is still `HTML`, so output is byte-identical to before.

### Step 4: Lint

```
composer lint
```

Expected: clean.

### Step 5: Commit

```
git add src/Email/Email_Sender.php src/Admin/Settings_Page.php
git commit -m "Wire Email_Sender and preview AJAX to pass per-type escape_map"
```

---

## Self-Review (run before declaring Phase 6 done)

- **Spec coverage:**
  - "Change `Email_Type::available_tags()` to return `[tag => ['description' => …, 'escape' => …]]`" — Task 2.
  - "Update `Merge_Tag_Replacer::replace_html` to look up the escape mode per tag (with a default of `html`)" — Task 3.
  - "New tests for each escape mode" — Task 3 adds raw, url, default-html, mixed-tags, and the global-url tests.
  - "Document the contract in the public-extension-points block" — out of scope; per roadmap, all doc updates land in Phase 10. Phase 6 only adds source-level docblocks (already in Tasks 1 & 3).
- **Type consistency:** `Escape_Mode` (singular, snake-case-PHP-class-style) appears in: `src/Email/Escape_Mode.php`, `Email_Type::available_tags()` shape, `Email_Type::escape_map()` value type, `Merge_Tag_Replacer::replace_html()` parameter, `Merge_Tag_Replacer::substitute()` parameter, `Merge_Tag_Replacer::apply_escape()` parameter, the test files. No drift.
- **No placeholders:** every code block is complete and ready to paste.
- **Backwards-compatibility:**
  - `replace_html($content, $context)` (2-arg) still works — `$escape_map` defaults to `[]`, every tag defaults to HTML.
  - `available_tags()` keys are unchanged (still `{braced}`), so the three existing `array_keys()` callers don't break.
  - `replace_subject()` signature and behaviour unchanged.

---

## Out of scope (defer)

- A `{cta_url}` or `{order_table}` production tag — Phase 6 ships infrastructure; specific tag additions belong to whoever needs them.
- README / CLAUDE.md updates documenting the new contract — Phase 10.
- A sanitize_branding test for the unknown-theme-id whitelist (carried over from Phase 5 follow-up) — Phase 10.
- Changing the admin "Available Tags" UI to display descriptions and escape modes — would be nice, but Phase 6 keeps the existing `<code>{tag}</code>` chip list. Future polish.
