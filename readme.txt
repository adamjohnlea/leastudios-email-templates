=== leaStudios Email Templates ===
Contributors: leastudios
Tags: email templates, branded emails, payment emails, transactional emails, unsubscribe
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 1.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Branded HTML wrapper for every outgoing WordPress email, plus a transactional pipeline for leaStudios Payments with full unsubscribe / suppression support.

== Description ==

leaStudios Email Templates does three things:

1. Wraps every outgoing HTML `wp_mail()` in a branded base template (logo, primary color, footer, social links). Bypass per-message via the `X-LeaStudios-No-Template` header.
2. Dispatches transactional emails for leaStudios Payments order, subscription, payment-failure, and refund events.
3. Adds compliant opt-out support — HMAC-signed unsubscribe URLs, public landing pages, admin management, and a suppression gate that lets recipients quietly opt out of non-required mail while continuing to receive legally-required transactional messages (receipts, refunds, payment-failure alerts, renewal receipts).

The plugin runs standalone. Without leaStudios Payments installed, the wrapper, send log, plain-text alternative body, and opt-out machinery all still work.

= Features =

* Branded wrapper for every outgoing HTML email
* Transactional emails for leaStudios Payments events
* Per-type preview + send-test from the admin
* Send log with filterable list, retention prune cron, and CLI inspection
* Plain-text alternative body for every HTML send
* Light + dark theme variants
* Per-tag escape contract (html / raw / url modes)
* Third-party email-type registry
* WP-CLI subcommands for every surface
* HMAC-signed unsubscribe links with public landing pages
* Per-recipient suppression with admin management
* Auto-appended unsubscribe footer on non-required types
* Required-type bypass for receipts, refunds, payment-failure alerts, renewal receipts

== Frequently Asked Questions ==

= Does this plugin work without leaStudios Payments installed? =

Yes. The branded wrapper, send log, plain-text alternative body, opt-out machinery, and admin pages all run independently. The payment-driven transactional emails (`payment_receipt`, `subscription_created`, `subscription_renewed`, `payment_failed`, `refund_processed`) only dispatch when `LEASTUDIOS_PAYMENTS_VERSION` is defined, and the integration degrades gracefully when the sibling plugin is inactive.

= Does this plugin support unsubscribes? =

Yes. Every non-required transactional email gets an auto-appended unsubscribe footer with a unique HMAC-signed link. Clicking the link suppresses that address immediately (one-click GET to `/wp-json/leastudios-email-templates/v1/unsubscribe`). The post-unsubscribe landing page offers a one-click resubscribe form (POST to `/resubscribe`). Required types — payment receipts, refund confirmations, payment-failure alerts, and renewal receipts — bypass the suppression gate so legally-required mail continues to flow regardless of opt-out state.

= How do I rotate the HMAC unsubscribe secret? =

Delete the `leastudios_email_templates_unsubscribe_secret` option. A new secret is minted lazily on the next `Unsubscribe_Manager::url_for()` call. Rotating the secret invalidates every outstanding unsubscribe link. Alternatively, hook the `leastudios_email_templates_unsubscribe_token_secret` filter to source the secret from a constant or environment variable — when the filter returns a non-empty string the option is never touched.

= How do I disable the auto-appended unsubscribe footer? =

Hook the `leastudios_email_templates_unsubscribe_footer_html` filter and return an empty string. The filter receives `(string $default_html, string $to, string $type_id)` so you can disable selectively per type or recipient.

= How do I expose this plugin's email types to my own plugin? =

Hook `leastudios_email_templates_register_types` at file scope (before `plugins_loaded:10` fires) and register your own `Email_Type_Definition` implementations. Your type appears in the Email Types tab, the send log, the WP-CLI subcommands, and the suppression gate — for free.

== Changelog ==

= 1.1.1 — 2026-05-23 =

* Fixed: fatal error on the Email Templates → Suppressions admin page (`Call to undefined function convert_to_screen()`). The list table is now built lazily inside the page-render flow instead of at `plugins_loaded`, where `wp-admin/includes/template.php` has not yet been loaded.
* Docs: corrected the `Email_Sender::compose()` docblock — the recipient IS reflected in the composed body via the `{unsubscribe_url}` merge tag added in 1.1.0.
* Docs: `Email_Sender::send()` now documents that the per-type `recipient_override` setting silently supersedes the caller-supplied `$to` for delivery, the suppression gate, the `{unsubscribe_url}` merge tag, and the auto-appended footer.

= 1.1.0 — 2026-05-23 =

* Added: per-type preview + send-test from the admin Email Types tab
* Added: send log with filterable admin list, retention prune cron, and CLI inspection
* Added: plain-text alternative body for every transactional HTML send
* Added: light + dark theme variants for the email base template
* Added: per-tag escape contract — merge tags declare html / raw / url modes
* Added: third-party email-type registry via the `leastudios_email_templates_register_types` action
* Added: WP-CLI subcommands — list-types, preview, send-test, list-suppressions, add-suppression, remove-suppression
* Added: unsubscribe / suppression — HMAC-signed URLs, public REST landing pages, admin management, auto-appended footer on non-required types
* Changed: PHPStan baseline raised to level 7

= 1.0.0 — Initial release =

* Branded HTML wrapper for every outgoing `wp_mail()`
* Transactional emails for leaStudios Payments order, subscription, payment-failure, and refund events

== Upgrade Notice ==

= 1.1.1 =
Fixes a fatal error on the Email Templates → Suppressions admin page introduced in 1.1.0. Recommended upgrade for anyone running 1.1.0.

= 1.1.0 =
Adds a suppression / unsubscribe gate: non-required transactional types (e.g., subscription_created) now skip recipients who have opted out via the auto-appended footer link. Required types (receipts, refunds, payment-failure, renewal receipts) bypass the gate. Review the new Email Templates → Suppressions admin page after upgrade.
