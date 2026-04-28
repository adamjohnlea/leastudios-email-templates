# leaStudios Email Templates

Wraps every outgoing WordPress email in a branded HTML template, and adds transactional email support for leaStudios Payments events (receipts, subscription created/renewed, payment failed, refund processed).

- **Requires WordPress:** 6.4+
- **Requires PHP:** 8.1+
- **License:** GPL-2.0-or-later

## Features

- **Branded email wrapping** — every HTML email sent via `wp_mail()` is wrapped in a responsive template with your logo, brand colour, and footer.
- **Payment transactional emails** — automatic emails for receipts, subscription created/renewed, payment failed, and refund processed.
- **Per-type customisation** — enable/disable each email type, customise subject and body content with merge tags.
- **Branding settings** — logo, primary colour, footer text, social links.
- **Live preview** in the admin before sending.
- **Merge tags** — `{customer_name}`, `{amount}`, `{product_name}`, `{date}`, …
- **Opt-out header** — any plugin can bypass the wrapper for a specific email by setting `X-LeaStudios-No-Template`.

## Installation

1. Upload `leastudios-email-templates` to `/wp-content/plugins/`.
2. Activate via Plugins → Installed Plugins.
3. Go to **Email Templates** in the admin menu to set logo, colour, footer.
4. Optionally customise individual email types under the **Email Types** tab.

## Related plugins

This plugin is part of the leaStudios plugin family. It works on its own, and integrates with:

- **[leastudios-payments](../leastudios-payments)** — fires the events that drive the payment transactional emails (receipts, subscription lifecycle, refunds).
- **[leastudios-mailer](../leastudios-mailer)** — when active, the wrapper hooks into the mailer pipeline; otherwise it falls back to the `wp_mail` filter.

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

## License

GPL-2.0-or-later. See `readme.txt` for the WordPress.org-style header.
