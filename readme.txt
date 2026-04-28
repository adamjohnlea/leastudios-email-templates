=== leaStudios Email Templates ===
Contributors: leastudios
Tags: email templates, branded emails, payment emails, transactional emails, email design
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Branded email templates for all WordPress emails plus payment transactional emails.

== Description ==

leaStudios Email Templates wraps all outgoing WordPress emails in a branded HTML template and adds transactional email support for leaStudios Payments events.

**Key features:**

* **Branded email wrapping** — all HTML emails sent via `wp_mail()` are automatically wrapped in a professional, responsive template with your logo, brand colour, and footer.
* **Payment transactional emails** — automatic emails for payment receipts, subscription confirmations, subscription renewals, payment failures, and refund notifications.
* **Customisable per email type** — enable/disable each email type, customise the subject line and body content with merge tags, or override the recipient for testing.
* **Branding settings** — upload your logo, set a primary colour, add footer text, and configure social media links.
* **Live preview** — preview your branded template from the admin before any emails are sent.
* **Merge tags** — dynamic content like `{customer_name}`, `{amount}`, `{product_name}`, `{date}`, and more.
* **Opt-out header** — any plugin can bypass the template for specific emails by adding an `X-LeaStudios-No-Template` header.

**Payment email types:**

* Payment Receipt — sent when a checkout is completed.
* Subscription Created — sent when a new subscription is activated.
* Subscription Renewed — sent on successful renewal payments.
* Payment Failed — sent when a subscription payment fails.
* Refund Processed — sent when a refund is issued.

**Works with or without leaStudios Mailer:**

When leaStudios Mailer is active, the template wrapping hooks into the mailer's pipeline for maximum compatibility. When the mailer is not active, it falls back to WordPress's `wp_mail` filter.

== Installation ==

1. Upload the `leastudios-email-templates` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins menu in WordPress.
3. Go to Email Templates in the admin menu to configure your branding (logo, colour, footer).
4. Optionally customise individual email types under the Email Types tab.
5. If leaStudios Payments is active, payment emails will be sent automatically on checkout, subscription, and refund events.

== Frequently Asked Questions ==

= Does this require leaStudios Payments? =

No. The branded template wrapping works on all WordPress emails regardless of other plugins. The payment transactional emails only activate when leaStudios Payments is installed and active.

= Can I disable the template for specific emails? =

Yes. Add the header `X-LeaStudios-No-Template: true` to any `wp_mail()` call and the email will be sent without the branded wrapper.

= Does this work with emails from other plugins? =

Yes. Any plugin that sends HTML emails via `wp_mail()` will have its emails wrapped in your branded template.

== Changelog ==

= 1.0.0 =
* Initial release.
