# Phase 3 — Plain-text alternative body

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:executing-plans.

**Goal:** Every HTML email sent via `wp_mail()` gets a readable plain-text alternative attached so receivers see `multipart/alternative` with both parts. Improves Gmail spam scoring + makes "view original" useful.

**Architecture:** New pure helper `Plain_Text_Generator` converts HTML → readable text. A new component `Plain_Text_Injector` hooks `phpmailer_init` and, when `$mail->ContentType === 'text/html'` and `$mail->AltBody === ''`, sets `AltBody` from the converted HTML. The injector respects the same `X-LeaStudios-No-Template` header used by `Template_Wrapper` to opt out.

**Scope decision (recorded earlier):** apply to all HTML `wp_mail`, not only transactional sends — consistent with the wrapper.

**Tech Stack:** PHPMailer (already in WP), no external deps.

---

## File structure

- **Create** `src/Email/Plain_Text_Generator.php` — pure helper: HTML string → text string.
- **Create** `src/Email/Plain_Text_Injector.php` — hooks `phpmailer_init`, sets AltBody, opt-out aware.
- **Modify** `src/Plugin.php` — wire the injector in `init()`.
- **Create** `tests/PlainTextGeneratorTest.php` — converter behaviour.
- **Create** `tests/PlainTextInjectorTest.php` — hook integration via fake PHPMailer.

No DB, no UI.

---

## Task 1: HTML → text converter

**Files:**
- Create: `src/Email/Plain_Text_Generator.php`
- Test: `tests/PlainTextGeneratorTest.php`

- [ ] **Step 1.1: Write failing tests**

Create `tests/PlainTextGeneratorTest.php`:

```php
<?php
/**
 * Tests for Plain_Text_Generator.
 *
 * @package LEAStudios\EmailTemplates\Tests
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Tests;

use LEAStudios\EmailTemplates\Email\Plain_Text_Generator;
use LEAStudios\Tests\TestCase;

/**
 * @covers \LEAStudios\EmailTemplates\Email\Plain_Text_Generator
 */
class PlainTextGeneratorTest extends TestCase {

    public function test_strips_html_tags(): void {
        $text = Plain_Text_Generator::from_html( '<p>Hello <strong>world</strong>!</p>' );
        $this->assertSame( 'Hello world!', $text );
    }

    public function test_removes_scripts_and_styles(): void {
        $html = '<style>.a{color:red}</style><script>alert(1)</script><p>Visible</p>';
        $text = Plain_Text_Generator::from_html( $html );
        $this->assertStringNotContainsString( 'color:red', $text );
        $this->assertStringNotContainsString( 'alert', $text );
        $this->assertStringContainsString( 'Visible', $text );
    }

    public function test_preserves_links_as_text_url_pairs(): void {
        $html = '<p>Visit <a href="https://example.com/x">our site</a> today.</p>';
        $text = Plain_Text_Generator::from_html( $html );
        $this->assertStringContainsString( 'our site (https://example.com/x)', $text );
    }

    public function test_collapses_anchor_when_text_equals_url(): void {
        $html = '<a href="https://example.com">https://example.com</a>';
        $text = Plain_Text_Generator::from_html( $html );
        $this->assertSame( 'https://example.com', $text );
    }

    public function test_renders_headings_as_lines_with_blank_separation(): void {
        $html = '<h1>Title</h1><p>Body</p>';
        $text = Plain_Text_Generator::from_html( $html );
        $this->assertStringContainsString( "Title\n", $text );
        $this->assertStringContainsString( 'Body', $text );
    }

    public function test_renders_lists_as_dashes(): void {
        $html = '<ul><li>One</li><li>Two</li></ul>';
        $text = Plain_Text_Generator::from_html( $html );
        $this->assertStringContainsString( '- One', $text );
        $this->assertStringContainsString( '- Two', $text );
    }

    public function test_decodes_html_entities(): void {
        $text = Plain_Text_Generator::from_html( '<p>Caf&eacute; &amp; Co.</p>' );
        $this->assertSame( 'Café & Co.', $text );
    }

    public function test_collapses_excess_whitespace_but_keeps_paragraph_breaks(): void {
        $html = "<p>Para one.</p>\n\n\n<p>Para two.</p>";
        $text = Plain_Text_Generator::from_html( $html );
        $this->assertStringContainsString( "Para one.\n\nPara two.", $text );
        $this->assertStringNotContainsString( "\n\n\n", $text );
    }

    public function test_handles_empty_input(): void {
        $this->assertSame( '', Plain_Text_Generator::from_html( '' ) );
    }
}
```

- [ ] **Step 1.2: Run tests to verify RED**

```bash
vendor/bin/phpunit tests/PlainTextGeneratorTest.php
```

Expected: errors — class doesn't exist.

- [ ] **Step 1.3: Implement the converter**

Create `src/Email/Plain_Text_Generator.php`:

```php
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
 * Pure helper — no I/O, no WordPress dependencies — so it can be invoked
 * from a `phpmailer_init` callback or unit-tested without bootstrapping
 * the mail pipeline.
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

        // Strip script/style blocks (and their contents) before any other transform.
        $clean = preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', '', $html );
        if ( null === $clean ) {
            $clean = $html;
        }

        // Anchor tags: produce "text (url)" or just the URL when text == href.
        $clean = preg_replace_callback(
            '#<a\b[^>]*href\s*=\s*["\']([^"\']+)["\'][^>]*>(.*?)</a>#is',
            static function ( array $m ): string {
                $href = trim( $m[1] );
                $text = trim( wp_strip_all_tags( $m[2] ) );
                if ( '' === $text || $text === $href ) {
                    return $href;
                }
                return $text . ' (' . $href . ')';
            },
            (string) $clean
        );
        if ( null === $clean ) {
            $clean = $html;
        }

        // Images → their alt text (or empty if absent).
        $clean = preg_replace_callback(
            '#<img\b[^>]*alt\s*=\s*["\']([^"\']*)["\'][^>]*/?>#is',
            static fn( array $m ): string => trim( $m[1] ),
            $clean
        );
        if ( null === $clean ) {
            $clean = $html;
        }
        $clean = preg_replace( '#<img\b[^>]*/?>#i', '', $clean ) ?? $clean;

        // Block-level breaks: turn each closing block tag into a newline so
        // paragraph structure survives the tag strip.
        $blocks = '#</(p|div|h[1-6]|tr|li|blockquote)\s*>#i';
        $clean  = preg_replace( $blocks, "\n", $clean ) ?? $clean;

        // List items: prefix with "- " on their opening tag.
        $clean = preg_replace( '#<li\b[^>]*>#i', '- ', $clean ) ?? $clean;

        // Table cells: separate with a tab.
        $clean = preg_replace( '#</t[dh]\s*>#i', "\t", $clean ) ?? $clean;

        // <br> → newline.
        $clean = preg_replace( '#<br\s*/?>#i', "\n", $clean ) ?? $clean;

        // Strip remaining tags.
        $clean = wp_strip_all_tags( (string) $clean );

        // Decode entities, then normalise whitespace.
        $clean = html_entity_decode( $clean, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

        // Collapse runs of spaces/tabs but keep newlines.
        $clean = preg_replace( '#[\t ]+#', ' ', $clean ) ?? $clean;

        // Collapse 3+ newlines into a double newline (paragraph break).
        $clean = preg_replace( "#\n{3,}#", "\n\n", $clean ) ?? $clean;

        // Trim per-line trailing whitespace and the overall result.
        $lines = array_map( 'rtrim', explode( "\n", $clean ) );

        return trim( implode( "\n", $lines ) );
    }
}
```

- [ ] **Step 1.4: Run tests to verify GREEN**

```bash
vendor/bin/phpunit tests/PlainTextGeneratorTest.php
```

If any test fails, iterate on the regex/normalisation until green. Likely candidates: the heading/blank-line expectation, the `collapse anchor when text equals URL` case.

- [ ] **Step 1.5: Lint**

```bash
composer lint
```

- [ ] **Step 1.6: Commit**

```bash
git add src/Email/Plain_Text_Generator.php tests/PlainTextGeneratorTest.php
git commit -m "Add Plain_Text_Generator for HTML → readable text conversion"
```

---

## Task 2: PHPMailer injector

**Files:**
- Create: `src/Email/Plain_Text_Injector.php`
- Test: `tests/PlainTextInjectorTest.php`

- [ ] **Step 2.1: Write failing tests**

Create `tests/PlainTextInjectorTest.php`:

```php
<?php
/**
 * Tests for Plain_Text_Injector.
 *
 * @package LEAStudios\EmailTemplates\Tests
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Tests;

use LEAStudios\EmailTemplates\Email\Plain_Text_Injector;
use LEAStudios\Tests\TestCase;

/**
 * @covers \LEAStudios\EmailTemplates\Email\Plain_Text_Injector
 */
class PlainTextInjectorTest extends TestCase {

    private Plain_Text_Injector $injector;

    public function set_up(): void {
        parent::set_up();
        $this->injector = new Plain_Text_Injector();
    }

    public function test_sets_alt_body_for_html_email_without_existing_alt(): void {
        $mail = new \PHPMailer\PHPMailer\PHPMailer( true );
        $mail->ContentType = 'text/html';
        $mail->Body        = '<p>Hello <strong>world</strong>!</p>';

        $this->injector->inject( $mail );

        $this->assertSame( 'Hello world!', $mail->AltBody );
    }

    public function test_preserves_existing_alt_body(): void {
        $mail = new \PHPMailer\PHPMailer\PHPMailer( true );
        $mail->ContentType = 'text/html';
        $mail->Body        = '<p>Hello</p>';
        $mail->AltBody     = 'Already set';

        $this->injector->inject( $mail );

        $this->assertSame( 'Already set', $mail->AltBody );
    }

    public function test_does_not_touch_plain_text_emails(): void {
        $mail = new \PHPMailer\PHPMailer\PHPMailer( true );
        $mail->ContentType = 'text/plain';
        $mail->Body        = 'Plain message';

        $this->injector->inject( $mail );

        $this->assertSame( '', $mail->AltBody );
    }

    public function test_respects_opt_out_header(): void {
        $mail = new \PHPMailer\PHPMailer\PHPMailer( true );
        $mail->ContentType = 'text/html';
        $mail->Body        = '<p>Hello</p>';
        $mail->addCustomHeader( 'X-LeaStudios-No-Template', 'true' );

        $this->injector->inject( $mail );

        $this->assertSame( '', $mail->AltBody );
    }
}
```

- [ ] **Step 2.2: Run tests to verify RED**

```bash
vendor/bin/phpunit tests/PlainTextInjectorTest.php
```

- [ ] **Step 2.3: Implement injector**

Create `src/Email/Plain_Text_Injector.php`:

```php
<?php
/**
 * Sets PHPMailer's AltBody to a plain-text alternative on every HTML
 * wp_mail send, so receivers get a proper multipart/alternative message.
 *
 * @package LEAStudios\EmailTemplates\Email
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Email;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use PHPMailer\PHPMailer\PHPMailer;

/**
 * Hooks the `phpmailer_init` action — fired after WP populates a PHPMailer
 * instance and before send — and synthesises an `AltBody` from `Body` for
 * HTML emails that don't already carry one.
 */
class Plain_Text_Injector {

    /**
     * The opt-out header that `Template_Wrapper` also recognises.
     */
    private const OPT_OUT_HEADER = 'X-LeaStudios-No-Template';

    /**
     * Register hooks.
     *
     * @return void
     */
    public function init(): void {
        add_action( 'phpmailer_init', [ $this, 'inject' ] );
    }

    /**
     * Populate `AltBody` from `Body` for HTML emails.
     *
     * The check order matters: opt-out first (respects sender intent),
     * then content-type (skip plain-text), then existing AltBody (respect
     * what the sender already provided).
     *
     * @param PHPMailer $mail The PHPMailer instance to mutate.
     * @return void
     */
    public function inject( PHPMailer $mail ): void {
        if ( $this->has_opt_out_header( $mail ) ) {
            return;
        }

        if ( 'text/html' !== strtolower( (string) $mail->ContentType ) ) {
            return;
        }

        if ( '' !== trim( (string) $mail->AltBody ) ) {
            return;
        }

        $mail->AltBody = Plain_Text_Generator::from_html( (string) $mail->Body );
    }

    /**
     * Check whether the PHPMailer instance carries the opt-out header.
     *
     * PHPMailer exposes custom headers via `getCustomHeaders()` which returns
     * an array of [name, value] pairs.
     *
     * @param PHPMailer $mail The PHPMailer instance.
     * @return bool
     */
    private function has_opt_out_header( PHPMailer $mail ): bool {
        foreach ( $mail->getCustomHeaders() as $header ) {
            if ( ! is_array( $header ) || count( $header ) < 1 ) {
                continue;
            }
            if ( 0 === strcasecmp( (string) $header[0], self::OPT_OUT_HEADER ) ) {
                return true;
            }
        }

        return false;
    }
}
```

- [ ] **Step 2.4: Run tests to verify GREEN**

```bash
vendor/bin/phpunit tests/PlainTextInjectorTest.php
```

- [ ] **Step 2.5: Lint**

```bash
composer lint
```

- [ ] **Step 2.6: Commit**

```bash
git add src/Email/Plain_Text_Injector.php tests/PlainTextInjectorTest.php
git commit -m "Add Plain_Text_Injector to attach AltBody on phpmailer_init"
```

---

## Task 3: Wire the injector into Plugin::init

**Files:**
- Modify: `src/Plugin.php`

- [ ] **Step 3.1: Register the injector**

In `src/Plugin.php::init()`, after `$wrapper->init();`, add:

```php
// Plain-text alternative body for every HTML wp_mail.
$injector = new Plain_Text_Injector();
$injector->init();
```

And add the matching `use` line near the top:

```php
use LEAStudios\EmailTemplates\Email\Plain_Text_Injector;
```

- [ ] **Step 3.2: Smoke-verify via wp eval**

```bash
cd /Users/adamlea/Herd/leastudios-plugins && wp eval '
add_action("wp_mail_failed", function ($wp_error) { echo "failed: " . $wp_error->get_error_message(); });
add_action("phpmailer_init", function ($mail) {
    echo "AltBody after init: [" . $mail->AltBody . "]\n";
}, 99);
wp_mail("test@example.com", "Smoke test", "<p>Hello <a href=\"https://example.com\">link</a>!</p>", ["Content-Type: text/html"]);
'
```

Expected: `AltBody after init: [Hello link (https://example.com)!]`.

- [ ] **Step 3.3: Full suite + lint**

```bash
composer test && composer lint
```

- [ ] **Step 3.4: Commit**

```bash
git add src/Plugin.php
git commit -m "Wire Plain_Text_Injector into Plugin::init"
```

---

## Self-review

- [ ] All HTML emails now ship with a populated AltBody (verified by the smoke test).
- [ ] Plain-text-only sends remain untouched.
- [ ] Emails opting out via `X-LeaStudios-No-Template` skip the injector (consistent with the wrapper).
- [ ] `composer lint` and `composer test` both green.
