# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Scope

This file documents only what is specific to **leastudios-email-templates**. Suite-wide conventions (security checklist, WPCS, PSR-4 layout, shared-by-duplication classes, packaging) live in:

- `/Users/adamlea/Herd/leastudios-plugins/CLAUDE.md` — suite overview, where the code lives, cross-plugin integration model.
- `wp-content/plugins/leastudios-dev-tools/CLAUDE.md` — the "mother" CLAUDE.md (escape/sanitize/nonce/capability rules, REST/i18n conventions, etc.).

Read those before doing anything non-trivial here. Do not duplicate them.

## What this plugin does

Three responsibilities, glued together by `src/Plugin.php`:

1. **Branded wrapper for every outgoing HTML email.** `Email/Template_Wrapper` inserts `wp_mail()` body into `templates/email/base.php` along with branding options (logo, primary colour, footer, social links). Wrapping is bypassed when the email has an `X-LeaStudios-No-Template` header or when branding is disabled.
2. **Payment transactional emails.** `Payment/Payment_Email_Listener` subscribes to actions fired by `leastudios-payments` (`leastudios_payments_order_created`, `…subscription_synced`, `…subscription_invoice_paid`, `…subscription_payment_failed`, `…webhook_refund_processed`, `…refund_issued`) and dispatches one of five built-in transactional email types (`payment_receipt`, `subscription_created`, `subscription_renewed`, `payment_failed`, `refund_processed`) via `Email_Sender`.
3. **Opt-out / suppression (Phase 9).** `Subscription/Unsubscribe_Manager` mints HMAC-signed unsubscribe URLs and consults a per-recipient `wp_leastudios_email_templates_suppressions` table. `Email_Sender::send` short-circuits non-required-type sends to suppressed recipients via the `leastudios_email_templates_email_suppressed` action; required types (receipts, refunds, payment-failed, renewal receipts) bypass the gate so legally-required mail still flows. `REST/Unsubscribe_Controller` exposes `GET /unsubscribe` (one-click) and `POST /resubscribe` for the public landing pages; `Admin/Suppressions_Page` is the admin/support surface.

The payment integration only boots when `LEASTUDIOS_PAYMENTS_VERSION` is defined (`Plugin::is_payments_active()`). When the payments plugin is inactive, the wrapper and the suppression machinery still run.

## Architecture map

- **`leastudios-email-templates.php`** — plugin header, constants (`LEASTUDIOS_EMAIL_TEMPLATES_VERSION/_FILE/_DIR/_URL`), activation hook seeds `leastudios_email_templates_branding` defaults, vendor-autoload guard with admin notice, then `Plugin::init()` on `plugins_loaded`.
- **`src/Plugin.php`** — composition root. Instantiates `Merge_Tag_Replacer`, installs the log + suppression schemas, wires `Template_Wrapper` (always), `Send_Logger` (always), `Unsubscribe_Manager` (always — shared across `Email_Sender`, the REST controller, the CLI, and the admin page so all surfaces see the same HMAC secret and DB state), `Unsubscribe_Controller` (on `rest_api_init`), the WP-CLI commands (when running under WP-CLI), `Payment_Email_Listener` (conditionally), and the three admin pages — `Settings_Page`, `Email_Log_Page`, and `Suppressions_Page` (admin only).
- **`src/Email/Email_Type_Definition.php`** — interface every email type must implement (`id`, `label`, `default_subject`, `default_body`, `available_tags`, `escape_map`, `sample_context`, `is_transactional_required`).
- **`src/Email/Abstract_Email_Type.php`** — base class providing default `escape_map()` projection (from `available_tags()`) and `is_transactional_required(): false`. Extend this for new types unless you have a reason to implement the interface directly.
- **`src/Email/Email_Type_Registry.php`** — in-memory `id => Email_Type_Definition` map with `register/get/has/all`. Last-write-wins on duplicate ids so third parties can replace built-ins.
- **`src/Email/Built_In/`** — the five built-in definitions (`Payment_Receipt`, `Subscription_Created`, `Subscription_Renewed`, `Payment_Failed`, `Refund_Processed`), each extending `Abstract_Email_Type` and overriding `is_transactional_required(): true`. Subject/body/tag content is the single source of truth for these types.
- **`src/Email/Template_Wrapper.php`** — registers `leastudios_mailer_pre_send` (priority 20) when `LEASTUDIOS_MAILER_VERSION` is defined, else falls back to the `wp_mail` filter. Detects HTML content from per-message headers and the `wp_mail_content_type` filter (mirrors core's resolution order — many plugins flip everything to HTML via the filter, so header-only checks miss them).
- **`src/Email/Email_Sender.php`** — composes and dispatches one email per registered type id. Takes `Email_Type_Registry` in its constructor; `send(string $type_id, ...)` and `compose(string $type_id, ...)` resolve definitions through the registry. Reads per-type overrides from `leastudios_email_templates_emails` option, falling back to the definition's defaults. The option is memoized per-request and invalidated on `update_option_*` / `add_option_*` / `delete_option_*` for the option key, so a batch of refund webhooks doesn't re-query options per send. **`recipient_override` resolution runs once at the top of `send()`**: the resolved delivery address (override target if set and valid, else the caller-supplied `$to`) is what the Phase 9 suppression gate evaluates, what gets handed to `wp_mail`, what mints the `{unsubscribe_url}` merge tag, and what the auto-appended footer points at. A redirect via `recipient_override` is therefore checked against the override target's opt-out preference, not the original caller-supplied address.
- **`src/Email/Merge_Tag_Replacer.php`** — exposes `replace_html()`, `replace_subject()` (strips CR/LF), and the static `format_amount()` used to render Stripe minor-unit integers as localized currency.
- **`src/Payment/Payment_Email_Listener.php`** — the hook registrations. Important behaviours:
  - **`on_subscription_synced`**: only fires `SUBSCRIPTION_CREATED` when the local status is `active`/`trialing` *and* the Stripe `created` timestamp is within 60 seconds. The sync action is replayed for many lifecycle events; this filters to genuinely-new subscriptions.
  - **`on_invoice_paid`**: only fires `SUBSCRIPTION_RENEWED` for `billing_reason === 'subscription_cycle'` (so the initial invoice does not also trigger a renewal email).
  - **Refund dedupe**: `on_refund_processed` (webhook) and `on_refund_issued` (admin REST refund) can both fire for the same refund. `send_refund_email()` uses a 10-minute transient keyed by `order_id + refunded_amount` to dedupe. The amount is part of the key so a partial-then-full refund sends two distinct emails.
- **`src/Payment/Payment_Data_Resolver.php`** — talks to `LEAStudios\Payments\Database\Order_Repository` and `Subscription_Repository` (sibling plugin classes) to produce the merge-tag context arrays.
- **`src/Admin/Settings_Page.php`** — Settings menu page (`manage_options`), tabs for branding + per-email-type overrides, AJAX live preview via `wp_ajax_leastudios_email_templates_preview`.
- **`src/CLI/Commands.php`** — WP-CLI commands. Registered only when `defined('WP_CLI') && WP_CLI`, via six explicit per-method `add_command` calls in `Plugin::init` (`list-types`, `preview`, `send-test`, `list-suppressions`, `add-suppression`, `remove-suppression`). Constructor-injected with the registry, sender, replacer, and unsubscribe manager that `Plugin::init` already builds, so the CLI cannot drift from admin AJAX. `preview` calls `Email_Sender::compose()`; `send-test` calls `Email_Sender::send($type, $to, $context, 'cli-test')` so the log row is tagged. Each user-facing command delegates to a pure `dispatch_*` helper so tests can exercise the validation/dispatch path without mocking the WP-CLI loggers.
- **`src/Subscription/Unsubscribe_Manager.php`** — stateless HMAC-SHA256 token mint/verify (`url_for`, `verify_token`) plus a thin facade over `Suppression_Repository` for `suppress`/`unsuppress`/`is_suppressed`. Tokens have the form `<base64url(email)>.<hex-hmac>`; verification is constant-time via `hash_equals`. No expiry, no single-use — rotating the secret invalidates every outstanding token. The secret is generated lazily on first use, stored in option `leastudios_email_templates_unsubscribe_secret` (autoload=no). Filter `leastudios_email_templates_unsubscribe_token_secret` lets sites source the secret from a constant or env var.
- **`src/Database/Suppression_Repository.php`** — `$wpdb` wrapper for the suppressions table. `UNIQUE` index on `email` + `INSERT … ON DUPLICATE KEY UPDATE` make re-suppression idempotent. Email is normalized to `strtolower(trim(...))` at both insert and lookup time so case-variant addresses dedupe correctly. `install()` is idempotent and short-circuits on the schema-version option.
- **`src/REST/Unsubscribe_Controller.php`** — anonymous-permission routes `GET /unsubscribe` and `POST /resubscribe` under namespace `leastudios-email-templates/v1`. **The token IS the auth** — `permission_callback` returns `true` and the route handler runs `Unsubscribe_Manager::verify_token`, returning the error landing on any failure. A `rest_pre_serve_request` listener short-circuits JSON serialization so the HTML landing pages land raw with proper `Content-Type`, no-cache, and `X-Robots-Tag: noindex` headers.
- **`src/Admin/Suppressions_Page.php`** + **`src/Admin/Suppressions_List_Table.php`** — admin sub-page under "Email Templates" with `manage_options` capability. Add form (admin-post), paginated list, per-row Remove link, and bulk Remove via the standard `WP_List_Table::current_action()` POST pattern. The list table is constructor-injected with `Suppression_Repository`.

### Options

- `leastudios_email_templates_branding` — assoc array: `enabled`, `logo_url`, `primary_color`, `footer_text`, `social_links{twitter,facebook,linkedin,instagram}`. Seeded on activation.
- `leastudios_email_templates_emails` — assoc array keyed by registered type id (e.g. `payment_receipt`) → `{enabled, subject, body, recipient_override}`. Empty by default; the registered definition's defaults are used when a key is missing or blank. Keys are byte-stable across Phase 7 — pre-Phase-7 customer overrides continue to apply.
- `leastudios_email_templates_unsubscribe_secret` — autoload **no**. 64-char HMAC key minted on first `Unsubscribe_Manager::url_for(...)`. Rotating = deleting the option, which invalidates every outstanding unsubscribe link. Filter `leastudios_email_templates_unsubscribe_token_secret` to source from a constant or env var instead (in which case nothing is ever persisted to the DB).
- `leastudios_email_templates_suppressions_schema_version` — autoload yes; mirrors the log table's schema-version short-circuit. Starts at `1.0.0`.
- **`wp_leastudios_email_templates_log.source`** (varchar(16), default `'web'`) — added in schema 1.1.0. Values: `'web'` (admin/wp-mail-filter triggered) or `'cli-test'` (`wp ... send-test`). Surfaced in the admin log list table as a small `(cli)` badge next to the recipient when non-default.
- **`wp_leastudios_email_templates_log.status`** also accepts the value `'suppressed'` (no schema change — column is already `varchar(16)`). One row is written per gated send, body included, so the audit trail matches what would have been delivered.

### Database tables

- **`wp_leastudios_email_templates_log`** (schema 1.1.0) — one row per transactional send. Dropped on uninstall via `Email_Log_Repository::drop()`.
- **`wp_leastudios_email_templates_suppressions`** (schema 1.0.0) — `id`, `email` (UNIQUE), `suppressed_at`, `source` (`'link'` | `'admin'` | `'cli'`). One row per opted-out address. Dropped on uninstall via `Suppression_Repository::drop()`.

### Public extension points

- Action `leastudios_email_templates_register_types` — fires once during `Plugin::init` after built-in types are registered. Receives the `Email_Type_Registry` so third parties can register their own `Email_Type_Definition` implementations. Hook this at file scope in your own plugin (i.e. before `plugins_loaded:10` fires).
- Filter `leastudios_email_templates_template_path` — override the wrapper template file.
- Filter `leastudios_email_templates_send_args` — mutate `wp_mail()` args before send. Second arg is `string $type_id`.
- Action `leastudios_email_templates_email_sent` — fires after each transactional send. Args: `string $type_id, string $to, string $subject, bool $result, string $body, array $headers, string $source`. `$source` is `'web'` for admin/auto sends, `'cli-test'` for `wp ... send-test`.
- Action `leastudios_email_templates_email_suppressed` — fires when `Email_Sender::send` skips a non-required-type send because the recipient is suppressed. Args: `string $type_id, string $to, string $subject, string $body, array $headers, string $source`. `Send_Logger` writes one log row per fire with `status='suppressed'`. The body includes the auto-appended unsubscribe footer so the row matches what would have been sent.
- Filter `leastudios_email_templates_unsubscribe_url` — `(string $url, string $email, string $type_id) => string`. Rewrite the unsubscribe URL (e.g. route through a CDN). Resolves to empty for required-type sends and empty recipients.
- Filter `leastudios_email_templates_unsubscribe_footer_html` — `(string $default_html, string $to, string $type_id) => string`. Replace the auto-appended footer markup for non-required types.
- Filter `leastudios_email_templates_unsubscribe_token_secret` — `(string $secret) => string`. Source the HMAC secret from a constant or env var rather than the `wp_option`. Returning non-empty skips the option entirely (the secret is never written to the DB in that mode).
- Header `X-LeaStudios-No-Template` — any plugin can set this on a `wp_mail()` headers array/string to skip the wrapper for that one email.

## Cross-plugin coupling (important)

`phpstan.neon` scans `../leastudios-payments/src` so PHPStan can resolve `Order_Repository`, `Subscription_Repository`, and the action signatures used in `Payment_Email_Listener`. This is intentional — the integration is a real typed contract, not hidden behind `ignoreErrors`.

Consequences:

- Running `composer phpstan` locally requires the `leastudios-payments` plugin to be checked out at `../leastudios-payments`.
- The CI `lint` job (`.github/workflows/ci.yml`) explicitly checks out `adamjohnlea/leastudios-payments` alongside this repo for the same reason — do not "fix" that by removing it.
- The runtime guard against missing classes is `Plugin::is_payments_active()` (which checks `LEASTUDIOS_PAYMENTS_VERSION`). Anything in `src/Payment/` may assume the sibling plugin's classes exist because that path is only reached behind the guard.

## Tests

PHPUnit 9.6 against the WordPress test library — no special harness, just the standard suite layout. `tests/bootstrap.php` looks for `WP_TESTS_DIR` or falls back to `/tmp/wordpress-tests-lib`. Install once per machine using the suite-wide script (see parent CLAUDE.md).

Common runs from this plugin's directory:

```bash
composer lint                                # phpcs + phpstan
composer test                                # all tests
vendor/bin/phpunit --filter MergeTagReplacer # one class
vendor/bin/phpunit tests/PaymentEmailListenerTest.php
```

The `tests/TestCase.php` base class is local to this plugin — extend it for new tests.

## CI matrix

`.github/workflows/ci.yml` runs:

- **lint** on PHP 8.2 — checks out `leastudios-payments` for PHPStan (see above).
- **test** on PHP 8.2 and 8.4 against MySQL 8.0; checks out `leastudios-dev-tools` to run `bin/install-wp-tests.sh` with WP 6.8.2.

`composer.json` pins `config.platform.php` to 8.2 — the floor for this plugin.

## When adding a new transactional email type

By default a new built-in or third-party type is **suppression-eligible** (`is_transactional_required(): false`) — meaning it will be skipped for recipients who have unsubscribed. Override to `true` only when the email is legally required and must always be sent regardless of opt-out (receipts, refunds, payment-failure alerts, renewal receipts).

For a new built-in type (one that ships in this plugin):

1. Create `src/Email/Built_In/Your_Type.php` extending `Abstract_Email_Type`. Implement `id()`, `label()`, `default_subject()`, `default_body()`, `available_tags()` (each tag carries an `Escape_Mode`), and `sample_context()`. Override `is_transactional_required(): true` if the type is a legally-required transactional email (receipts, refunds, payment failures).
2. Register the new class in `Plugin::init()` alongside the other five built-ins, before the `leastudios_email_templates_register_types` action fires.
3. If the type is payment-driven: hook the triggering payment action in `src/Payment/Payment_Email_Listener::init()` and add a handler that calls `$this->sender->send( 'your_type_id', $to, $context )`. Resolve the merge-tag context in `src/Payment/Payment_Data_Resolver.php`.
4. Extend `tests/BuiltInTypesTest::built_in_provider()` to include the new type. Add listener/resolver tests as needed.
5. Verify the type appears in the Email Types tab of the settings page and in the log filter dropdown.

For a third-party plugin adding its own type:

1. Implement `Email_Type_Definition` (or extend `Abstract_Email_Type`).
2. In your own plugin's main file, at file scope, hook `leastudios_email_templates_register_types` and register your definition: `add_action( 'leastudios_email_templates_register_types', fn( $r ) => $r->register( new My_Welcome_Email() ) );`.
3. Dispatch via `do_action` or by calling `Email_Sender::send( 'my_welcome_email', $to, $context )` from your own code.

A third-party type registered via `leastudios_email_templates_register_types` works with the CLI for free: `wp ... list-types` will list it (tagged `third-party`), and `preview`/`send-test` accept its id like any other.
