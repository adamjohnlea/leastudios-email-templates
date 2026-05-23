# leaStudios Email Templates

Wraps every outgoing WordPress email in a branded HTML template and adds a transactional-email pipeline for leaStudios Payments events (receipts, subscription created/renewed, payment failed, refund processed) with full opt-out / suppression support.

- **Requires WordPress:** 6.4+
- **Tested up to:** 6.9
- **Requires PHP:** 8.2+
- **License:** GPL-2.0-or-later

## Quick start

1. Drop the plugin folder into `wp-content/plugins/` (or install the packaged zip).
2. Activate via Plugins → Activate.
3. Configure branding under **Email Templates → Settings → Branding**.
4. Customize transactional types under **Email Templates → Email Types**.
5. Review opt-outs under **Email Templates → Suppressions** (only visible once the suppressions feature has been exercised).

The plugin runs standalone — the branded wrapper, send log, plain-text alternative body, opt-out machinery, and admin pages all work without leaStudios Payments installed. The payment-driven transactional emails only dispatch when `LEASTUDIOS_PAYMENTS_VERSION` is defined.

## Features

- **Branded wrapper** — every HTML `wp_mail()` is wrapped in `templates/email/base.php` with site branding (logo, primary color, footer, social links). Bypass per-message via the `X-LeaStudios-No-Template` header.
- **Payment transactional emails** — `payment_receipt`, `subscription_created`, `subscription_renewed`, `payment_failed`, `refund_processed`, dispatched off actions emitted by `leastudios-payments`.
- **Preview + send-test** — per-type live preview and one-click send-to-self from the Email Types tab, plus matching WP-CLI subcommands.
- **Send log** — every transactional send is recorded with type, recipient, subject, status, source, and timestamp. Filterable admin list table; daily prune cron with a filterable retention window.
- **Plain-text alternative body** — automatic `multipart/alternative` so Gmail/Outlook don't dock for HTML-only mail.
- **Theme variants** — light + dark email base presets; `prefers-color-scheme` adapts in clients that support it.
- **Per-tag escape contract** — merge tags declare `html` / `raw` / `url` escape modes so HTML-bearing tags render unescaped and URL tags survive `esc_url`.
- **Type registry** — third-party plugins register their own transactional email types via `leastudios_email_templates_register_types` and gain the admin UI, send log, and CLI for free.
- **WP-CLI** — `list-types`, `preview`, `send-test`, `list-suppressions`, `add-suppression`, `remove-suppression`.
- **Unsubscribe / suppression** — HMAC-signed unsubscribe URLs, public REST landing pages, admin management surface, and a footer auto-appended to non-required types. Legally-required types (receipts, refunds, payment-failed, renewal receipts) bypass the gate.

## WP-CLI commands

```bash
# List every registered email type with its source (built-in / third-party).
wp leastudios-email-templates list-types [--format=table|csv|json|yaml|count|ids]

# Print the rendered preview for a type.
wp leastudios-email-templates preview <type> [--data=<json>] [--subject]

# Send a real sample to an address (logged as source=cli-test).
wp leastudios-email-templates send-test <type> <email> [--dry-run]

# Suppression management.
wp leastudios-email-templates list-suppressions [--format=…]
wp leastudios-email-templates add-suppression <email> [--source=<source>]
wp leastudios-email-templates remove-suppression <email>
```

## Public extension points

Hooks are documented in `CLAUDE.md` and in the inline docblocks. Quick reference:

**Actions**
- `leastudios_email_templates_register_types` — receives `Email_Type_Registry`; register your own type definitions.
- `leastudios_email_templates_email_sent` — fires after every dispatched send.
- `leastudios_email_templates_email_suppressed` — fires when the suppression gate skips a send.

**Filters**
- `leastudios_email_templates_template_path` — override the wrapper template file.
- `leastudios_email_templates_send_args` — mutate `wp_mail()` arguments before send.
- `leastudios_email_templates_log_retention_days` — change the log-prune window (default 30).
- `leastudios_email_templates_unsubscribe_url` — rewrite the unsubscribe URL per send.
- `leastudios_email_templates_unsubscribe_footer_html` — replace the auto-appended footer markup.
- `leastudios_email_templates_unsubscribe_token_secret` — source the HMAC secret from a constant or env var.

**Headers**
- `X-LeaStudios-No-Template` — set on a per-message `wp_mail()` headers value to skip the wrapper.

## Database tables

- `wp_leastudios_email_templates_log` (schema 1.1.0) — one row per transactional send.
- `wp_leastudios_email_templates_suppressions` (schema 1.0.0) — one row per opted-out address; UNIQUE on `email`.

Both tables drop on uninstall.

## Compatibility

- WordPress 6.4 minimum; tested up to 6.9.
- PHP 8.2 minimum (matches the runtime guard in the plugin header and `composer.json` `config.platform.php`).
- Works standalone. The payment transactional emails activate when `LEASTUDIOS_PAYMENTS_VERSION` is defined.

## Development

This plugin is self-contained — it can be cloned, linted, tested, and packaged on its own.

```bash
composer install            # install dependencies (incl. dev tools)
composer lint               # phpcs + phpstan
composer test               # phpunit (requires the WP test library)
composer phpcbf             # auto-fix WPCS issues
```

To run the test suite, install the WordPress test library once:

```bash
bash ../leastudios-dev-tools/bin/install-wp-tests.sh wordpress_test root '' localhost latest
```

The shared scaffold, packaging script, and project-wide development conventions live in **[leastudios-dev-tools](../leastudios-dev-tools)** — start there when bootstrapping a new plugin or making cross-plugin tooling changes.

## Changelog

### 1.1.0 — 2026-05-23

- Added: per-type preview + send-test from the admin Email Types tab.
- Added: send log with filterable admin list, retention prune cron, and CLI inspection.
- Added: plain-text alternative body for every transactional HTML send.
- Added: light + dark theme variants for the email base template.
- Added: per-tag escape contract — merge tags declare html / raw / url modes.
- Added: third-party email-type registry via `leastudios_email_templates_register_types`.
- Added: WP-CLI subcommands — `list-types`, `preview`, `send-test`, `list-suppressions`, `add-suppression`, `remove-suppression`.
- Added: unsubscribe / suppression — HMAC-signed URLs, public REST landing pages, admin management, auto-appended footer on non-required types.
- Changed: PHPStan baseline raised to level 7.

### 1.0.0 — Initial release

- Branded HTML wrapper for every outgoing `wp_mail()`.
- Transactional emails for leaStudios Payments order, subscription, payment-failure, and refund events.

## License

GPL-2.0-or-later. See `readme.txt` for the WordPress.org-style header.
