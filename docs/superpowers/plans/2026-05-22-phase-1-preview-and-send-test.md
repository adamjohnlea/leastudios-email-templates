# Phase 1 — Per-email-type preview + send-test Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let admins preview each transactional email type with realistic sample data and send a real test message to any address, directly from the Email Types tab.

**Architecture:** Extract a "compose email args" method from `Email_Sender` so the preview AJAX can render the same HTML the sender would produce (subject + body + branding wrapper) without dispatch. A second AJAX handler reuses `Email_Sender::send` with a sample context for the real test send. Both handlers gate on `manage_options` + nonce.

**Tech Stack:** PHP 8.2, WordPress AJAX, jQuery (already loaded), existing `Email_Sender` / `Template_Wrapper` / `Merge_Tag_Replacer`.

---

## File structure

- **Modify** `src/Email/Email_Sender.php` — extract `compose( Email_Type $type, array $context ): array` returning `[subject, body, headers]`. `send()` calls `compose()` then `wp_mail()`. Pure composition is now testable in isolation.
- **Modify** `src/Admin/Settings_Page.php` — register two new AJAX actions (`…_preview_type`, `…_send_test`), implement handlers, render Preview + Send Test buttons in the existing accordion in `render_email_types_tab`.
- **Modify** `assets/js/admin.js` — wire the new buttons (per-type, gathering the in-form subject/body so unsaved changes preview correctly).
- **Modify** `assets/css/admin.css` — small additions for button row + per-section preview iframe.
- **Modify** `tests/EmailSenderTest.php` — tests for `compose()`.
- **Create** `src/Email/Sample_Context.php` — pure helper returning realistic sample merge-tag context per `Email_Type`. Used by both AJAX handlers (preview and send-test).
- **Create** `tests/SampleContextTest.php`.

No new DB tables, no new options.

---

## Task 1: Extract `Email_Sender::compose()` (pure composition)

**Files:**
- Modify: `src/Email/Email_Sender.php`
- Test: `tests/EmailSenderTest.php`

- [ ] **Step 1.1: Write the failing test**

Append to `tests/EmailSenderTest.php` (inside the class):

```php
public function test_compose_returns_subject_body_headers_without_sending(): void {
    update_option(
        'leastudios_email_templates_emails',
        [
            'payment_receipt' => [
                'enabled' => true,
                'subject' => 'Receipt for {product_name}',
                'body'    => '<p>Thanks, {customer_name}.</p>',
            ],
        ]
    );

    reset_phpmailer_instance();

    $args = $this->sender->compose(
        Email_Type::PAYMENT_RECEIPT,
        [
            'customer_name' => 'Alice',
            'product_name'  => 'Widget',
        ]
    );

    $this->assertSame( 'Receipt for Widget', $args['subject'] );
    $this->assertStringContainsString( 'Thanks, Alice.', $args['body'] );
    $this->assertContains( 'Content-Type: text/html; charset=UTF-8', $args['headers'] );

    // compose() must not have sent anything.
    $mailer = tests_retrieve_phpmailer_instance();
    $this->assertEmpty( $mailer->mock_sent );
}

public function test_compose_returns_null_when_type_disabled(): void {
    update_option(
        'leastudios_email_templates_emails',
        [
            'payment_receipt' => [ 'enabled' => false ],
        ]
    );

    $args = $this->sender->compose( Email_Type::PAYMENT_RECEIPT, [] );

    $this->assertNull( $args );
}
```

- [ ] **Step 1.2: Run test to verify it fails**

```bash
vendor/bin/phpunit --filter test_compose tests/EmailSenderTest.php
```

Expected: errors with `Call to undefined method LEAStudios\EmailTemplates\Email\Email_Sender::compose()`.

- [ ] **Step 1.3: Implement `compose()` and refactor `send()` to use it**

In `src/Email/Email_Sender.php`, replace the body of `send()` with:

```php
public function send( Email_Type $type, string $to, array $context = [] ): bool {
    $args = $this->compose( $type, $context );

    if ( null === $args ) {
        return false;
    }

    $settings = $this->get_type_settings( $type );

    if ( ! empty( $settings['recipient_override'] ) && is_email( $settings['recipient_override'] ) ) {
        $to = $settings['recipient_override'];
    }

    /**
     * Filters the email arguments before sending.
     *
     * @param array<string, mixed> $args    The wp_mail arguments.
     * @param Email_Type           $type    The email type.
     * @param array<string, mixed> $context The merge tag context.
     */
    $args = (array) apply_filters(
        'leastudios_email_templates_send_args',
        [
            'to'      => $to,
            'subject' => $args['subject'],
            'message' => $args['body'],
            'headers' => $args['headers'],
        ],
        $type,
        $context
    );

    $result = wp_mail( $args['to'], $args['subject'], $args['message'], $args['headers'] );

    /**
     * Fires after a transactional email is sent.
     *
     * @param Email_Type $type    The email type.
     * @param string     $to      The recipient.
     * @param string     $subject The subject line.
     * @param bool       $result  Whether wp_mail returned true.
     */
    do_action( 'leastudios_email_templates_email_sent', $type, $args['to'], $args['subject'], $result );

    return $result;
}

/**
 * Compose subject/body/headers for an email type without sending.
 *
 * Returns null when the type is disabled. Recipient is not part of the
 * composed output because subject/body/headers don't depend on it; the
 * sender resolves recipient at send time so previews and tests can reuse
 * this method.
 *
 * @param Email_Type           $type    The email type.
 * @param array<string, mixed> $context Merge-tag values.
 * @return array{subject:string, body:string, headers:array<int,string>}|null
 */
public function compose( Email_Type $type, array $context = [] ): ?array {
    $settings = $this->get_type_settings( $type );

    if ( empty( $settings['enabled'] ) ) {
        return null;
    }

    $subject = '' !== $settings['subject'] ? $settings['subject'] : $type->default_subject();
    $body    = '' !== $settings['body'] ? $settings['body'] : $type->default_body();

    $subject = $this->replacer->replace_subject( $subject, $context );
    $body    = $this->replacer->replace_html( $body, $context );

    return [
        'subject' => $subject,
        'body'    => $body,
        'headers' => [ 'Content-Type: text/html; charset=UTF-8' ],
    ];
}
```

- [ ] **Step 1.4: Run tests to verify GREEN**

```bash
composer test
```

Expected: 48 tests, 0 failures (2 new compose tests + 46 existing).

- [ ] **Step 1.5: Run lint**

```bash
composer lint
```

Expected: 0 errors.

- [ ] **Step 1.6: Commit**

```bash
git add src/Email/Email_Sender.php tests/EmailSenderTest.php
git commit -m "Extract Email_Sender::compose() for reuse by preview/send-test"
```

---

## Task 2: Sample context helper

**Files:**
- Create: `src/Email/Sample_Context.php`
- Test: `tests/SampleContextTest.php`

- [ ] **Step 2.1: Write the failing test**

Create `tests/SampleContextTest.php`:

```php
<?php
/**
 * Tests for Sample_Context.
 *
 * @package LEAStudios\EmailTemplates\Tests
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Tests;

use LEAStudios\EmailTemplates\Email\Email_Type;
use LEAStudios\EmailTemplates\Email\Sample_Context;
use LEAStudios\Tests\TestCase;

/**
 * @covers \LEAStudios\EmailTemplates\Email\Sample_Context
 */
class SampleContextTest extends TestCase {

    public function test_for_payment_receipt_provides_all_advertised_tags(): void {
        $context = Sample_Context::for_type( Email_Type::PAYMENT_RECEIPT );

        foreach ( array_keys( Email_Type::PAYMENT_RECEIPT->available_tags() ) as $tag ) {
            $key = trim( $tag, '{}' );
            $this->assertArrayHasKey( $key, $context, "Missing sample value for {$tag}" );
        }
    }

    public function test_for_refund_processed_provides_refunded_amount(): void {
        $context = Sample_Context::for_type( Email_Type::REFUND_PROCESSED );

        $this->assertArrayHasKey( 'refunded_amount', $context );
        $this->assertNotSame( '', $context['refunded_amount'] );
    }

    public function test_every_email_type_has_a_sample_context(): void {
        foreach ( Email_Type::cases() as $type ) {
            $context = Sample_Context::for_type( $type );
            $this->assertIsArray( $context, "No sample context for {$type->value}" );
            $this->assertNotEmpty( $context, "Empty sample context for {$type->value}" );
        }
    }
}
```

- [ ] **Step 2.2: Run test to verify it fails**

```bash
vendor/bin/phpunit tests/SampleContextTest.php
```

Expected: errors — class doesn't exist.

- [ ] **Step 2.3: Implement `Sample_Context`**

Create `src/Email/Sample_Context.php`:

```php
<?php
/**
 * Realistic sample merge-tag context per Email_Type, used by the preview
 * and send-test admin features.
 *
 * @package LEAStudios\EmailTemplates\Email
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Email;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Pure helper that returns a populated context array suitable for rendering
 * a preview or sending a self-test, covering every merge tag the type
 * advertises in `available_tags()`. Values are intentionally generic so the
 * preview is recognisable as a sample, not real customer data.
 */
final class Sample_Context {

    /**
     * Return a sample context populated with every tag the type advertises.
     *
     * @param Email_Type $type The email type.
     * @return array<string, string>
     */
    public static function for_type( Email_Type $type ): array {
        $common = [
            'customer_name'  => 'Jane Customer',
            'customer_email' => 'jane@example.com',
        ];

        $specific = match ( $type ) {
            Email_Type::PAYMENT_RECEIPT => [
                'amount'         => '$29.99',
                'currency'       => 'USD',
                'product_name'   => 'Sample Product',
                'order_type'     => 'One-time payment',
                'payment_id'     => 'pi_sample_1234567890',
                'order_id'       => '42',
                'payment_status' => 'Paid',
            ],
            Email_Type::SUBSCRIPTION_CREATED => [
                'product_name' => 'Sample Plan',
                'amount'       => '$9.99',
                'currency'     => 'USD',
                'period_end'   => 'June 22, 2026',
            ],
            Email_Type::SUBSCRIPTION_RENEWED => [
                'product_name'   => 'Sample Plan',
                'invoice_amount' => '$9.99',
                'currency'       => 'USD',
                'period_end'     => 'July 22, 2026',
            ],
            Email_Type::PAYMENT_FAILED => [
                'product_name'   => 'Sample Plan',
                'invoice_amount' => '$9.99',
                'currency'       => 'USD',
            ],
            Email_Type::REFUND_PROCESSED => [
                'refunded_amount' => '$10.00',
                'amount'          => '$29.99',
                'currency'        => 'USD',
                'product_name'    => 'Sample Product',
                'order_id'        => '42',
            ],
        };

        return array_merge( $common, $specific );
    }
}
```

- [ ] **Step 2.4: Run tests**

```bash
vendor/bin/phpunit tests/SampleContextTest.php
```

Expected: 3 tests pass.

- [ ] **Step 2.5: Full suite + lint**

```bash
composer test && composer lint
```

Expected: all green.

- [ ] **Step 2.6: Commit**

```bash
git add src/Email/Sample_Context.php tests/SampleContextTest.php
git commit -m "Add Sample_Context helper for email preview and test sends"
```

---

## Task 3: Per-type preview AJAX endpoint

**Files:**
- Modify: `src/Admin/Settings_Page.php`

- [ ] **Step 3.1: Register the new AJAX action**

In `src/Admin/Settings_Page.php`, locate `init()` and append a new `add_action` line:

```php
public function init(): void {
    add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
    add_action( 'admin_init', [ $this, 'register_settings' ] );
    add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    add_action( 'wp_ajax_leastudios_email_templates_preview', [ $this, 'handle_preview' ] );
    add_action( 'wp_ajax_leastudios_email_templates_preview_type', [ $this, 'handle_preview_type' ] );
    add_action( 'wp_ajax_leastudios_email_templates_send_test', [ $this, 'handle_send_test' ] );
}
```

- [ ] **Step 3.2: Implement `handle_preview_type`**

Append this method to `Settings_Page` (just below the existing `handle_preview` method):

```php
/**
 * AJAX handler: render a specific Email_Type with sample (or user-supplied)
 * subject/body and wrap it in the branded template.
 *
 * Accepts optional `subject` and `body` POST fields so the admin can preview
 * unsaved edits without round-tripping through Save first.
 *
 * @return void
 */
public function handle_preview_type(): void {
    Nonce::check_ajax( 'preview' );

    if ( ! current_user_can( self::CAPABILITY ) ) {
        wp_send_json_error( __( 'Permission denied.', 'leastudios-email-templates' ) );
    }

    $type = $this->resolve_posted_type();

    if ( null === $type ) {
        wp_send_json_error( __( 'Unknown email type.', 'leastudios-email-templates' ) );
    }

    $replacer    = new Merge_Tag_Replacer();
    $wrapper     = new Template_Wrapper( $replacer );
    $sample      = Sample_Context::for_type( $type );

    // Live preview of unsaved subject/body fields when supplied.
    $subject_tpl = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['subject'] ) ) : '';
    $body_tpl    = isset( $_POST['body'] ) ? wp_kses_post( wp_unslash( (string) $_POST['body'] ) ) : '';

    if ( '' === $subject_tpl ) {
        $subject_tpl = $type->default_subject();
    }
    if ( '' === $body_tpl ) {
        $body_tpl = $type->default_body();
    }

    $rendered_subject = $replacer->replace_subject( $subject_tpl, $sample );
    $rendered_body    = $replacer->replace_html( $body_tpl, $sample );

    $html = $wrapper->wrap( $rendered_body );

    wp_send_json_success(
        [
            'subject' => $rendered_subject,
            'html'    => $html,
        ]
    );
}

/**
 * Map a POSTed `type` key to its Email_Type case.
 *
 * @return Email_Type|null
 */
private function resolve_posted_type(): ?Email_Type {
    $raw = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['type'] ) ) : '';

    foreach ( Email_Type::cases() as $case ) {
        if ( $case->value === $raw ) {
            return $case;
        }
    }

    return null;
}
```

Note: also add `use LEAStudios\EmailTemplates\Email\Sample_Context;` near the other `use` lines at the top of the file.

- [ ] **Step 3.3: Smoke-test by hand**

Boot the Herd site and post to the AJAX endpoint via curl using a known admin cookie + nonce, or skip and confirm visually in Task 5.

- [ ] **Step 3.4: Lint**

```bash
composer lint
```

Expected: 0 errors. (PHPStan should be happy; if PHPCS flags `_POST` sanitization, double-check the `wp_unslash → sanitize_*` chain.)

- [ ] **Step 3.5: Commit**

```bash
git add src/Admin/Settings_Page.php
git commit -m "Add per-email-type preview AJAX endpoint"
```

---

## Task 4: Send-test AJAX endpoint

**Files:**
- Modify: `src/Admin/Settings_Page.php`

- [ ] **Step 4.1: Implement `handle_send_test`**

Append to `Settings_Page`:

```php
/**
 * AJAX handler: send a real sample email of the given type to a chosen
 * address. Uses Sample_Context so all merge tags resolve to recognisable
 * placeholder values.
 *
 * @return void
 */
public function handle_send_test(): void {
    Nonce::check_ajax( 'preview' );

    if ( ! current_user_can( self::CAPABILITY ) ) {
        wp_send_json_error( __( 'Permission denied.', 'leastudios-email-templates' ) );
    }

    $type = $this->resolve_posted_type();

    if ( null === $type ) {
        wp_send_json_error( __( 'Unknown email type.', 'leastudios-email-templates' ) );
    }

    $to = isset( $_POST['to'] ) ? sanitize_email( wp_unslash( (string) $_POST['to'] ) ) : '';

    if ( '' === $to || ! is_email( $to ) ) {
        wp_send_json_error( __( 'A valid email address is required.', 'leastudios-email-templates' ) );
    }

    $sender = new Email_Sender( new Merge_Tag_Replacer() );
    $result = $sender->send( $type, $to, Sample_Context::for_type( $type ) );

    if ( ! $result ) {
        wp_send_json_error(
            __( 'Email could not be sent. Check your email type is enabled and that wp_mail is configured.', 'leastudios-email-templates' )
        );
    }

    wp_send_json_success(
        [
            'message' => sprintf(
                /* translators: %s is the recipient email address. */
                __( 'Test email sent to %s.', 'leastudios-email-templates' ),
                $to
            ),
        ]
    );
}
```

Add `use LEAStudios\EmailTemplates\Email\Email_Sender;` to the use-block if it isn't already there.

- [ ] **Step 4.2: Lint**

```bash
composer lint
```

- [ ] **Step 4.3: Commit**

```bash
git add src/Admin/Settings_Page.php
git commit -m "Add send-test-email AJAX endpoint for email types"
```

---

## Task 5: UI buttons in the Email Types accordion

**Files:**
- Modify: `src/Admin/Settings_Page.php` (`render_email_types_tab`)
- Modify: `assets/js/admin.js`
- Modify: `assets/css/admin.css`

- [ ] **Step 5.1: Add Preview + Send Test rows inside each accordion body**

In `render_email_types_tab`, locate the `<tr>` row containing "Available Tags" and add two more rows AFTER it (still inside the `<table class="form-table">`):

```php
<tr>
    <th scope="row"><?php esc_html_e( 'Preview', 'leastudios-email-templates' ); ?></th>
    <td>
        <button type="button" class="button leastudios-preview-type" data-type="<?php echo esc_attr( $key ); ?>">
            <?php esc_html_e( 'Preview This Email', 'leastudios-email-templates' ); ?>
        </button>
        <p class="description leastudios-preview-subject" data-type="<?php echo esc_attr( $key ); ?>" style="display:none;"></p>
        <div class="leastudios-preview-frame" data-type="<?php echo esc_attr( $key ); ?>" style="margin-top:10px;border:1px solid #ccd0d4;background:#fff;display:none;">
            <iframe style="width:100%;height:500px;border:0;"></iframe>
        </div>
    </td>
</tr>
<tr>
    <th scope="row"><?php esc_html_e( 'Send Test', 'leastudios-email-templates' ); ?></th>
    <td>
        <input type="email" class="regular-text leastudios-send-test-to" data-type="<?php echo esc_attr( $key ); ?>" placeholder="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" />
        <button type="button" class="button leastudios-send-test" data-type="<?php echo esc_attr( $key ); ?>">
            <?php esc_html_e( 'Send Test Email', 'leastudios-email-templates' ); ?>
        </button>
        <p class="description leastudios-send-test-result" data-type="<?php echo esc_attr( $key ); ?>"></p>
    </td>
</tr>
```

- [ ] **Step 5.2: Wire the buttons in admin.js**

Append to `assets/js/admin.js` (inside the existing `$(function () { … })` block, before the closing brace):

```javascript
// Per-type preview.
$(document).on('click', '.leastudios-preview-type', function () {
    var $btn = $(this);
    var type = $btn.data('type');

    // Gather unsaved subject/body from inputs in the same accordion.
    var $section = $btn.closest('.leastudios-email-type-section');
    var subject = $section.find('input[name$="[subject]"]').val();
    // wp_editor textareas are referred to by ID; fall back via name.
    var body = $section.find('textarea[name$="[body]"]').val();

    $btn.prop('disabled', true);

    $.post(leastudiosEmailTemplates.ajaxUrl, {
        action: 'leastudios_email_templates_preview_type',
        _wpnonce: leastudiosEmailTemplates.previewNonce,
        type: type,
        subject: subject || '',
        body: body || ''
    }, function (response) {
        $btn.prop('disabled', false);

        if (!response.success) {
            window.alert(response.data || 'Preview failed.');
            return;
        }

        var $subjectLine = $('.leastudios-preview-subject[data-type="' + type + '"]');
        $subjectLine.text('Subject: ' + response.data.subject).show();

        var $frameWrap = $('.leastudios-preview-frame[data-type="' + type + '"]');
        var $iframe = $frameWrap.find('iframe');
        $frameWrap.show();

        var doc = $iframe[0].contentDocument || $iframe[0].contentWindow.document;
        doc.open();
        doc.write(response.data.html);
        doc.close();
    }).fail(function () {
        $btn.prop('disabled', false);
    });
});

// Send-test.
$(document).on('click', '.leastudios-send-test', function () {
    var $btn = $(this);
    var type = $btn.data('type');
    var $input = $('.leastudios-send-test-to[data-type="' + type + '"]');
    var to = ($input.val() || $input.attr('placeholder') || '').trim();
    var $result = $('.leastudios-send-test-result[data-type="' + type + '"]').text('');

    if (!to) {
        $result.text('Enter an email address first.').css('color', '#b32d2e');
        return;
    }

    $btn.prop('disabled', true);

    $.post(leastudiosEmailTemplates.ajaxUrl, {
        action: 'leastudios_email_templates_send_test',
        _wpnonce: leastudiosEmailTemplates.previewNonce,
        type: type,
        to: to
    }, function (response) {
        $btn.prop('disabled', false);
        if (response.success) {
            $result.text(response.data.message).css('color', '#1d8a1d');
        } else {
            $result.text(response.data || 'Send failed.').css('color', '#b32d2e');
        }
    }).fail(function () {
        $btn.prop('disabled', false);
        $result.text('Network error.').css('color', '#b32d2e');
    });
});
```

- [ ] **Step 5.3: Small CSS for spacing**

Append to `assets/css/admin.css`:

```css
.leastudios-email-type-section .leastudios-preview-frame iframe { background: #fff; }
.leastudios-email-type-section .leastudios-send-test-result { margin-top: 6px; font-weight: 500; }
.leastudios-email-type-section .leastudios-send-test-to { margin-right: 6px; }
```

- [ ] **Step 5.4: Manual UI verification (golden path)**

Boot the Herd site, log in, navigate to **Email Templates → Email Types**, expand any section:

- Click **Preview This Email**: the rendered HTML appears in an iframe; the subject line shows above it.
- Type your email into the test input, click **Send Test Email**: receive the actual email in your inbox (or whatever mailer is configured).
- Edit the Subject input *without saving*, click Preview again: the rendered subject reflects the unsaved edit.

If anything fails, debug and fix before continuing.

- [ ] **Step 5.5: Run lint + full test suite**

```bash
composer lint && composer test
```

Expected: all green.

- [ ] **Step 5.6: Commit**

```bash
git add src/Admin/Settings_Page.php assets/js/admin.js assets/css/admin.css
git commit -m "Add per-type preview and send-test buttons to Email Types tab"
```

---

## Self-review checklist (before declaring Phase 1 done)

- [ ] `composer lint` clean
- [ ] `composer test` all green
- [ ] Manually verified preview + send-test for at least two different `Email_Type` cases (e.g. `PAYMENT_RECEIPT` and `REFUND_PROCESSED`)
- [ ] Unsaved subject/body changes flow through the preview
- [ ] Invalid email in the send-test input shows an inline error rather than a console crash
- [ ] Pre-existing branding-tab preview (the old `Preview Email Template` button) still works
- [ ] No new TODOs / placeholders left in the code

Once all boxes checked, Phase 1 is shippable. Move on to Phase 2.
