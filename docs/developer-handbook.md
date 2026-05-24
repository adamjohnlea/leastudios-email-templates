# leaStudios Email Templates — Developer Handbook

leaStudios Email Templates wraps every outgoing WordPress HTML email in a branded
template and adds a transactional-email pipeline for payment events — with a
type registry, merge-tag engine, suppression/unsubscribe system, send log, and
WP-CLI tooling. Extension authors can register new email types, inject custom
merge tags, override the template, react to every send, and manage suppressions
from the command line.

---

## Table of Contents

1. [Overview](#1-overview)
2. [Architecture](#2-architecture)
3. [Development Setup](#3-development-setup)
4. [Concepts](#4-concepts)
5. [Data Model](#5-data-model)
6. [Hooks Reference](#6-hooks-reference)
7. [Hook Execution Order](#7-hook-execution-order)
8. [REST API Reference](#8-rest-api-reference)
9. [WP-CLI Commands](#9-wp-cli-commands)
10. [Public PHP API](#10-public-php-api)
11. [Extension Recipes](#11-extension-recipes)
12. [Testing](#12-testing)
13. [Release Process](#13-release-process)
14. [Where to Read More](#14-where-to-read-more)

---

## 1. Overview

leaStudios Email Templates gives WordPress site owners branded, trackable,
opt-out-respecting transactional email — without configuring a third-party ESP
themselves.

**Branded wrapper.** Every HTML `wp_mail()` call is wrapped in
`templates/email/base.php` with site branding (logo, primary colour, footer,
social links). Any plugin can opt a single message out by adding an
`X-LeaStudios-No-Template` header.

**Transactional email pipeline.** Five built-in email types (`payment_receipt`,
`subscription_created`, `subscription_renewed`, `payment_failed`,
`refund_processed`) are dispatched in response to actions emitted by
`leastudios-payments`. Third-party plugins register their own types via the
`leastudios_email_templates_register_types` action and receive the admin UI, send
log, and CLI tooling for free.

**Merge tags.** Each type declares its available `{tags}` with per-tag escape
modes (`html`, `raw`, `url`). The filter `leastudios_email_templates_merge_tags`
lets extension authors inject additional tags into every render pass.

**Suppression / opt-out.** HMAC-signed one-click unsubscribe URLs are
auto-appended to non-required-type emails. The REST endpoint writes the
suppression row; `Email_Sender` gates future non-required sends against the table.
Legally-required types (receipts, refunds, payment-failure, renewal receipts)
bypass the gate.

**Send log.** Every transactional send is recorded (type, recipient, subject,
status, source, timestamp) and pruned daily with a configurable retention window.

For extension authors the two most important entry points are:

- `leastudios_email_templates_register_types` — register a new `Email_Type_Definition`.
- `leastudios_email_templates_merge_tags` — inject custom `{tags}` into every render.

---

## 2. Architecture

### Component map

```
leastudios-email-templates.php
    └── Plugin::init()  (on plugins_loaded)
            |
            ├── Template_Wrapper            wraps every HTML wp_mail
            ├── Plain_Text_Injector         auto multipart/alternative
            ├── Send_Logger                 writes log rows on email_sent/suppressed
            |
            ├── Merge_Tag_Replacer          {tag} substitution engine
            ├── Email_Type_Registry         id => Email_Type_Definition map
            |       ├── Built_In/Payment_Receipt
            |       ├── Built_In/Subscription_Created
            |       ├── Built_In/Subscription_Renewed
            |       ├── Built_In/Payment_Failed
            |       └── Built_In/Refund_Processed
            |
            ├── [action] leastudios_email_templates_register_types
            |       └── (third-party types register here)
            |
            ├── Email_Sender                compose + send per type id
            ├── Unsubscribe_Manager         token mint/verify + suppression facade
            |
            ├── REST\Unsubscribe_Controller  GET /unsubscribe, POST /resubscribe
            |
            ├── CLI\Commands                6 wp-cli subcommands (WP_CLI only)
            |
            ├── Payment\Payment_Email_Listener  reacts to leastudios-payments actions
            |                                   (boots only when payments plugin active)
            |
            └── Admin\{Settings,Email_Log,Suppressions}_Page
```

### Send flow

```
Email_Sender::send( $type_id, $to, $context )
    |
    +-- recipient_override resolution (admin setting may redirect $to)
    |
    +-- suppression gate (non-required types check Suppression_Repository)
    |       if suppressed: [action] leastudios_email_templates_email_suppressed → false
    |
    +-- Email_Sender::compose()
    |       +-- [filter] leastudios_email_templates_merge_tags (context)
    |       +-- [filter] leastudios_email_templates_unsubscribe_url
    |
    +-- [filter] leastudios_email_templates_send_args (wp_mail args)
    |
    +-- auto-append unsubscribe footer for non-required types
    |       +-- [filter] leastudios_email_templates_unsubscribe_footer_html
    |
    +-- wp_mail()
    |       +-- Template_Wrapper wraps HTML body
    |
    +-- [action] leastudios_email_templates_email_sent
```

### Template_Wrapper integration

When `leastudios-mailer` is active, `Template_Wrapper` hooks
`leastudios_mailer_pre_send` at priority 20 (after the mailer processes at 10)
and wraps `$args['body_html']`. When the mailer is absent, it falls back to the
`wp_mail` filter. Either way the branded wrapper fires for every HTML email on
the site, not only transactional types.

See `CLAUDE.md` for deeper internals, the PHPStan cross-plugin scan setup, and
the payment-integration guard (`Plugin::is_payments_active()`).

---

## 3. Development Setup

```bash
cd wp-content/plugins/leastudios-email-templates
composer install
composer lint              # phpcs + phpstan
composer test              # PHPUnit 9.6
```

Install the shared WordPress test library once (required before `composer test`):

```bash
bash ../leastudios-dev-tools/bin/install-wp-tests.sh \
    wordpress_test root '' 127.0.0.1 latest
```

**Sibling plugins needed for integrations:**

- `leastudios-payments` — required by `composer phpstan` (scanned for type
  resolution) and to exercise `Payment_Email_Listener` at runtime. Must be
  checked out at `../leastudios-payments`.
- `leastudios-mailer` — optional; when active, `Template_Wrapper` hooks the
  mailer's `leastudios_mailer_pre_send` filter instead of `wp_mail`.

---

## 4. Concepts

### Email type

A registered transactional email flavour, identified by a stable string id (e.g.
`payment_receipt`). Every type implements `Email_Type_Definition`: an id, a
human-readable label, default subject and body templates, the set of `{tags}` it
advertises, and whether it is "transactional-required". The admin Email Types tab
shows one row per registered type; the send log, CLI, and suppression gate all
operate on type ids.

### Merge tag

A `{braced_name}` placeholder in a subject or body template that `Merge_Tag_Replacer`
substitutes at render time. Each tag in a type's `available_tags()` declaration
carries an `Escape_Mode`: `HTML` (default — `esc_html()`), `RAW` (trusted HTML
payload, inserted verbatim), or `URL` (`esc_url()`). Global tags (`{site_name}`,
`{site_url}`, `{date}`, `{unsubscribe_url}`) are injected automatically.

### Transactional-required type

A type whose `is_transactional_required()` returns `true` bypasses the
suppression gate — it is always sent regardless of the recipient's opt-out
preference. Built-in required types: `payment_receipt`, `subscription_renewed`,
`payment_failed`, `refund_processed`. `subscription_created` is also required.
Custom types default to `false` (suppressible) unless overridden.

### Template wrapper

The branded HTML shell rendered from `templates/email/base.php`. Wrapping is
skipped when branding is disabled in settings, when the email carries the
`X-LeaStudios-No-Template` header, or when the message is plain text.

### Suppression

A database record (in `wp_leastudios_email_templates_suppressions`) for a recipient
who has opted out. `Email_Sender` checks the suppression table before every
non-required-type send and short-circuits with the
`leastudios_email_templates_email_suppressed` action. Suppressions can be added
via unsubscribe link, the admin Suppressions page, or WP-CLI.

---

## 5. Data Model

### Custom tables

#### `wp_leastudios_email_templates_log` (schema 1.1.0)

One row per transactional send attempt. Dropped on uninstall.

| Column | Type | Description |
|---|---|---|
| `id` | `bigint unsigned` | Auto-increment primary key. |
| `type` | `varchar(64)` | Registered email type id. |
| `recipient` | `varchar(255)` | Delivery address (post-override resolution). |
| `subject` | `text` | Rendered subject. |
| `body` | `longtext` | Rendered HTML body (including appended footer). |
| `headers` | `longtext` | Serialized headers array. |
| `status` | `varchar(16)` | `'sent'`, `'failed'`, or `'suppressed'`. |
| `error` | `text` | NULL unless `wp_mail` returned false. |
| `source` | `varchar(16)` | `'web'` (default) or `'cli-test'`. |
| `created_at` | `datetime` | UTC timestamp; indexed for date-range queries and prune. |

**Access:** `Email_Log_Repository` (`src/Database/Email_Log_Repository.php`) — `create()`, `get()`, `paginate()`, `prune_older_than()`. Read the repository directly for custom reporting; never write to the table outside the repository.

**Schema version option:** `leastudios_email_templates_log_schema_version` (autoload no).

---

#### `wp_leastudios_email_templates_suppressions` (schema 1.0.0)

One row per opted-out email address. `UNIQUE` on `email`. Dropped on uninstall.

| Column | Type | Description |
|---|---|---|
| `id` | `bigint unsigned` | Auto-increment primary key. |
| `email` | `varchar(255) UNIQUE` | Lowercased, trimmed recipient. Idempotent on re-suppress. |
| `suppressed_at` | `datetime` | Timestamp of the most-recent suppression event. |
| `source` | `varchar(16)` | `'link'` (one-click URL), `'admin'` (admin UI), `'cli'` (WP-CLI). |

**Access:** `Unsubscribe_Manager` (`src/Subscription/Unsubscribe_Manager.php`) exposes `suppress()`, `unsuppress()`, `is_suppressed()`, `paginate()`. Use the manager facade rather than `Suppression_Repository` directly — the manager normalizes email casing.

**Schema version option:** `leastudios_email_templates_suppressions_schema_version` (autoload yes).

---

### Options

| Option key | Type | Description |
|---|---|---|
| `leastudios_email_templates_branding` | `array` | `enabled`, `logo_url`, `primary_color`, `footer_text`, `theme`, `social_links`. Seeded on activation. |
| `leastudios_email_templates_emails` | `array` | Per-type overrides keyed by type id: `{enabled, subject, body, recipient_override}`. Missing keys fall through to the type definition's defaults. |
| `leastudios_email_templates_unsubscribe_secret` | `string` | 64-char HMAC key. autoload=no. Minted lazily on first `url_for()`. Delete to rotate (invalidates all outstanding unsubscribe links). |

---

## 6. Hooks Reference

### Registration & Rendering Hooks

#### `leastudios_email_templates_merge_tags`

- **Type:** Filter
- **Location:** `src/Email/Merge_Tag_Replacer.php`
- **Since:** 1.1.0
- **Description:** Filters the full merge-tag context array immediately before
  `{tag}` substitution. Fires on every HTML body render via `replace_html()` and
  on every subject render via `replace_subject()`. Use this to inject custom
  `{tags}` that should be available globally (across all types) or to override
  a built-in global tag (e.g. `{site_name}`). The `$content` argument is the
  template string being processed — you can use it to apply tags conditionally
  based on what placeholders are present.

**Parameters:**

| Parameter | Type | Description |
|---|---|---|
| `$context` | `array<string, mixed>` | Current tag values keyed by unbraced tag name. |
| `$content` | `string` | The template string being rendered. |

**Returns:** `array<string, mixed>` — The filtered context. Add, remove, or
replace entries as needed. Tag values are escaped according to the type's
`escape_map()`; tags not in the map default to `esc_html()`.

**Example:**

```php
add_filter(
    'leastudios_email_templates_merge_tags',
    function ( array $context, string $content ): array {
        // Inject a {support_url} tag available in every email template.
        $context['support_url'] = home_url( '/support/' );
        return $context;
    },
    10,
    2
);
```

#### `leastudios_email_templates_template_path`

- **Type:** Filter
- **Location:** `src/Email/Template_Wrapper.php`
- **Since:** 1.0.0
- **Description:** Filters the absolute filesystem path to the HTML wrapper
  template before it is loaded via `include`. The default path is
  `{plugin_dir}/templates/email/base.php`. If the filtered path does not exist
  (`file_exists()` returns false), the inner body is returned unwrapped — the
  email still sends, just without the branded shell. Use this to substitute a
  fully custom template from your theme or another plugin.

**Parameters:**

| Parameter | Type | Description |
|---|---|---|
| `$template_path` | `string` | Absolute path to the email base template. |

**Returns:** `string` — Path to the template file to load.

**Example:**

```php
add_filter(
    'leastudios_email_templates_template_path',
    function ( string $template_path ): string {
        // Use a template from the active theme's template-parts directory.
        $theme_template = get_stylesheet_directory() . '/template-parts/email/base.php';

        return file_exists( $theme_template ) ? $theme_template : $template_path;
    }
);
```

---

#### `leastudios_email_templates_register_types`

- **Type:** Action
- **Location:** `src/Plugin.php`
- **Since:** 1.1.0
- **Description:** Fires once during `Plugin::init()`, immediately after the five
  built-in email types are registered. Third-party plugins hook this action to
  register their own `Email_Type_Definition` implementations. The `$registry`
  instance is passed as the sole argument — call `$registry->register()` with
  your definition. Because `Plugin::init()` runs on `plugins_loaded` at priority
  10, your callback must be registered at file scope (before `plugins_loaded:10`
  fires) to be queued in time.

**Parameters:**

| Parameter | Type | Description |
|---|---|---|
| `$registry` | `Email_Type_Registry` | The registry to mutate. Call `$registry->register()`. |

**Example:**

```php
add_action(
    'leastudios_email_templates_register_types',
    function ( \LEAStudios\EmailTemplates\Email\Email_Type_Registry $registry ): void {
        $registry->register( new My_Plugin\Email\Welcome_Email() );
    }
);
```

---

### Send Pipeline & Log Hooks

#### `leastudios_email_templates_send_args`

- **Type:** Filter
- **Location:** `src/Email/Email_Sender.php`
- **Since:** 1.1.0
- **Description:** Filters the `wp_mail()` argument array just before the email
  is dispatched. The array has keys `to`, `subject`, `message`, and `headers`.
  Use this to add custom headers (CC, BCC, Reply-To), swap the recipient, or
  append content to the body. The filter fires after the suppression gate passes,
  after `compose()` runs, and before the unsubscribe footer is appended — so
  `$args['message']` at this point is the rendered body without the footer.
  Modifying `$args['to']` here does NOT affect the suppression gate (which
  already resolved the recipient earlier in `send()`).

**Parameters:**

| Parameter | Type | Description |
|---|---|---|
| `$args` | `array<string, mixed>` | `wp_mail` args: `to`, `subject`, `message`, `headers`. |
| `$type_id` | `string` | Registered email type id, e.g. `'payment_receipt'`. |
| `$context` | `array<string, mixed>` | Merge-tag context used to compose the email. |

**Returns:** `array<string, mixed>` — The filtered args passed to `wp_mail()`.

**Example:**

```php
add_filter(
    'leastudios_email_templates_send_args',
    function ( array $args, string $type_id, array $context ): array {
        // Add a Reply-To header to all outgoing transactional emails.
        $args['headers'][] = 'Reply-To: support@example.com';

        // For payment_receipt only, CC the accounts team.
        if ( 'payment_receipt' === $type_id ) {
            $args['headers'][] = 'Cc: accounts@example.com';
        }

        return $args;
    },
    10,
    3
);
```

---

#### `leastudios_email_templates_log_retention_days`

- **Type:** Filter
- **Location:** `src/Plugin.php`
- **Since:** 1.1.0
- **Description:** Filters the number of days the email send log retains rows
  before the daily prune cron (`leastudios_email_templates_log_prune`) deletes
  them. The default is 30 days. The cron fires once per day via
  `wp_schedule_event()`; the filter is applied on each prune run. Values less
  than 1 are clamped to 1 to prevent accidentally truncating the entire log.

**Parameters:**

| Parameter | Type | Description |
|---|---|---|
| `$days` | `int` | Retention window in days. Default `30`. |

**Returns:** `int` — The number of days to retain rows.

**Example:**

```php
add_filter(
    'leastudios_email_templates_log_retention_days',
    function ( int $days ): int {
        // Keep log rows for 90 days for GDPR audit purposes.
        return 90;
    }
);
```

---

#### `leastudios_email_templates_email_sent`

- **Type:** Action
- **Location:** `src/Email/Email_Sender.php`
- **Since:** 1.1.0
- **Description:** Fires immediately after `wp_mail()` returns, whether the send
  succeeded or failed. `Send_Logger` hooks this action at priority 10 to write
  the log row; your callback can hook at any other priority to react to sends
  from any plugin. `$result` is the raw `wp_mail()` return value — `false`
  indicates a failure at the transport layer. Note that `$to` reflects the final
  resolved delivery address (post-`recipient_override`), which may differ from the
  original address passed to `Email_Sender::send()`.

**Parameters:**

| Parameter | Type | Description |
|---|---|---|
| `$type_id` | `string` | Registered email type id. |
| `$to` | `string` | Final delivery address (post-override resolution). |
| `$subject` | `string` | Rendered subject line. |
| `$result` | `bool` | `true` if `wp_mail()` returned true, `false` on failure. |
| `$body` | `string` | Rendered HTML body passed to `wp_mail()` (including footer). |
| `$headers` | `array<int, string>` | Headers array passed to `wp_mail()`. |
| `$source` | `string` | `'web'` or `'cli-test'`. |

**Example:**

```php
add_action(
    'leastudios_email_templates_email_sent',
    function (
        string $type_id,
        string $to,
        string $subject,
        bool   $result,
        string $body,
        array  $headers,
        string $source
    ): void {
        if ( ! $result ) {
            // Alert the team on failed transactional sends.
            error_log( sprintf(
                'leaStudios Email Templates: %s send to %s failed (source=%s)',
                esc_attr( $type_id ),
                esc_attr( $to ),
                esc_attr( $source )
            ) );
        }
    },
    10,
    7
);
```

---

#### `leastudios_email_templates_email_suppressed`

- **Type:** Action
- **Location:** `src/Email/Email_Sender.php`
- **Since:** 1.1.0
- **Description:** Fires when `Email_Sender::send()` short-circuits because the
  resolved delivery address is in the suppressions table and the email type is
  not transactional-required. `Send_Logger` hooks this at priority 10 to write a
  log row with `status='suppressed'`. The `$body` includes the rendered email
  body plus the auto-appended unsubscribe footer — the row is a faithful audit
  trail of what would have been delivered. `Email_Sender::send()` returns `false`
  after firing this action.

**Parameters:**

| Parameter | Type | Description |
|---|---|---|
| `$type_id` | `string` | Registered email type id. |
| `$to` | `string` | Suppressed delivery address. |
| `$subject` | `string` | Composed subject (may be empty if the type is also disabled). |
| `$body` | `string` | Composed body with auto-appended footer. |
| `$headers` | `array<int, string>` | Composed headers. |
| `$source` | `string` | `'web'` or `'cli-test'`. |

**Example:**

```php
add_action(
    'leastudios_email_templates_email_suppressed',
    function (
        string $type_id,
        string $to,
        string $subject,
        string $body,
        array  $headers,
        string $source
    ): void {
        // Forward suppressed send data to an analytics pipeline.
        wp_remote_post( 'https://analytics.example.com/suppressed', [
            'body'    => wp_json_encode( [
                'type'   => sanitize_key( $type_id ),
                'email'  => sanitize_email( $to ),
                'source' => sanitize_key( $source ),
            ] ),
            'headers' => [ 'Content-Type' => 'application/json' ],
            'blocking' => false,
        ] );
    },
    10,
    6
);
```

---

### Suppression / Unsubscribe Hooks

#### `leastudios_email_templates_unsubscribe_footer_html`

- **Type:** Filter
- **Location:** `src/Email/Email_Sender.php`
- **Since:** 1.1.0
- **Description:** Filters the unsubscribe footer HTML that is automatically
  appended to the body of every non-required-type email with a real, valid
  recipient. The default footer is a horizontal rule followed by a small paragraph
  containing a signed unsubscribe link. This filter fires after the unsubscribe
  URL is minted but before the footer is concatenated to `$args['message']`.
  Return an empty string to suppress the footer entirely (the unsubscribe URL is
  still embedded via `{unsubscribe_url}` in the body if the type uses it).

**Parameters:**

| Parameter | Type | Description |
|---|---|---|
| `$default_html` | `string` | The default footer markup. |
| `$to` | `string` | Recipient address. |
| `$type_id` | `string` | Registered email type id. |

**Returns:** `string` — The HTML to append to the body.

**Example:**

```php
add_filter(
    'leastudios_email_templates_unsubscribe_footer_html',
    function ( string $default_html, string $to, string $type_id ): string {
        // Use a custom branded footer with a different message.
        $url = esc_url( rest_url( 'leastudios-email-templates/v1/unsubscribe' ) );

        return '<div style="text-align:center;padding:16px;font-size:11px;color:#9ca3af;">'
            . sprintf(
                /* translators: 1: opening anchor tag, 2: closing anchor tag */
                esc_html__( 'To stop receiving emails like this, %1$sunsubscribe here%2$s.', 'my-plugin' ),
                '<a href="' . esc_url( rest_url( 'leastudios-email-templates/v1/unsubscribe?token=' . rawurlencode( '' ) ) ) . '" style="color:#6b7280;">',
                '</a>'
            )
            . '</div>';
    },
    10,
    3
);
```

---

#### `leastudios_email_templates_unsubscribe_token_secret`

- **Type:** Filter
- **Location:** `src/Subscription/Unsubscribe_Manager.php`
- **Since:** 1.1.0
- **Description:** Filters the HMAC-SHA256 secret used to sign and verify
  unsubscribe tokens. By default, the secret is generated once and stored in the
  option `leastudios_email_templates_unsubscribe_secret` (autoload=no). Return a
  non-empty string from this filter to source the secret from a constant or
  environment variable instead — in that case, the secret is never written to the
  database. Rotating the secret (either by deleting the option or changing the
  returned constant) invalidates all outstanding unsubscribe links immediately.

**Parameters:**

| Parameter | Type | Description |
|---|---|---|
| `$secret` | `string` | The current secret (empty string when the option does not exist yet). |

**Returns:** `string` — The HMAC secret to use. A non-empty return skips the
option entirely.

**Example:**

```php
add_filter(
    'leastudios_email_templates_unsubscribe_token_secret',
    function ( string $secret ): string {
        // Source the secret from a constant defined in wp-config.php.
        if ( defined( 'MY_UNSUBSCRIBE_SECRET' ) && '' !== MY_UNSUBSCRIBE_SECRET ) {
            return MY_UNSUBSCRIBE_SECRET;
        }
        return $secret;
    }
);
```

---

#### `leastudios_email_templates_unsubscribe_url`

- **Type:** Filter
- **Location:** `src/Email/Email_Sender.php`
- **Since:** 1.1.0
- **Description:** Filters the unsubscribe URL minted for the `{unsubscribe_url}`
  merge tag. The default URL points to the plugin's public REST endpoint
  (`/wp-json/leastudios-email-templates/v1/unsubscribe?token=…`). Use this to
  route the link through a CDN, add tracking parameters, or redirect to a custom
  landing page (as long as that page eventually hits the REST endpoint with the
  token). This filter fires per-compose call and resolves to an empty string for
  required-type sends and empty/invalid recipients — check before rewriting.

**Parameters:**

| Parameter | Type | Description |
|---|---|---|
| `$url` | `string` | The default signed unsubscribe URL, or empty string for required types. |
| `$email` | `string` | Recipient address. |
| `$type_id` | `string` | Registered email type id. |

**Returns:** `string` — The final unsubscribe URL to embed in the email.

**Example:**

```php
add_filter(
    'leastudios_email_templates_unsubscribe_url',
    function ( string $url, string $email, string $type_id ): string {
        if ( '' === $url ) {
            return $url; // Required-type or invalid recipient — leave empty.
        }

        // Route through a CDN subdomain for click tracking.
        return str_replace(
            home_url(),
            'https://email.example.com',
            $url
        );
    },
    10,
    3
);
```

---

## 7. Hook Execution Order

For a typical successful transactional send (`Email_Sender::send()`), hooks fire
in this order:

```
(plugins_loaded:10 — Plugin::init runs)
    |
    +-- [action] leastudios_email_templates_register_types
    |
(payment action — e.g. leastudios_payments_order_created)
    |
    +-- Payment_Email_Listener calls Email_Sender::send()
    |
    +-- [filter] leastudios_email_templates_merge_tags
    |            (context built; unsubscribe_url injected)
    |
    +-- [filter] leastudios_email_templates_unsubscribe_url
    |
    +-- [filter] leastudios_email_templates_send_args
    |
    +-- [filter] leastudios_email_templates_unsubscribe_footer_html
    |            (footer appended to body for non-required types)
    |
    +-- wp_mail() → Template_Wrapper wraps HTML body
    |       +-- [filter] leastudios_email_templates_template_path
    |
    +-- [action] leastudios_email_templates_email_sent
```

| Order | Hook | Type | Trigger |
|---|---|---|---|
| 1 | `leastudios_email_templates_register_types` | Action | `plugins_loaded:10` — third-party type registration |
| 2 | `leastudios_email_templates_merge_tags` | Filter | Every `compose()` call, before `{tag}` substitution |
| 3 | `leastudios_email_templates_unsubscribe_url` | Filter | Per-compose, after context is built |
| 4 | `leastudios_email_templates_send_args` | Filter | After `compose()`, before footer append |
| 5 | `leastudios_email_templates_unsubscribe_footer_html` | Filter | Before footer is concatenated to body |
| 6 | `leastudios_email_templates_template_path` | Filter | Inside `wp_mail()` → `Template_Wrapper::wrap()` |
| 7 | `leastudios_email_templates_email_sent` | Action | Immediately after `wp_mail()` returns |

### Suppressed send path

When the recipient is suppressed (non-required type), the path short-circuits
after step 2:

```
Email_Sender::send()
    |
    +-- [filter] leastudios_email_templates_merge_tags  (context for composed body)
    |
    +-- [filter] leastudios_email_templates_unsubscribe_footer_html  (footer in log body)
    |
    +-- [action] leastudios_email_templates_email_suppressed
    |
    └── returns false  (wp_mail never called)
```

### Log prune (daily cron)

```
(WP Cron — leastudios_email_templates_log_prune)
    |
    +-- [filter] leastudios_email_templates_log_retention_days
    |
    +-- Email_Log_Repository::prune_older_than( $days )
```

---

## 8. REST API Reference

Namespace: `leastudios-email-templates/v1`

These two routes are public (no WordPress authentication required). The signed
token embedded in the URL IS the authentication mechanism — `permission_callback`
returns `true` and the handler verifies the token via `Unsubscribe_Manager::verify_token()`.

Responses are raw HTML pages (not JSON) because the routes are user-facing
landing pages. The plugin installs a `rest_pre_serve_request` hook that
short-circuits WordPress's JSON serializer for these two routes.

| Method | Route | Description | Capability |
|---|---|---|---|
| GET | `/unsubscribe` | One-click unsubscribe | Public (token-authenticated) |
| POST | `/resubscribe` | Undo unsubscribe | Public (token-authenticated) |

---

### `GET /unsubscribe`

- **Endpoint:** `/wp-json/leastudios-email-templates/v1/unsubscribe`
- **Controller:** `src/REST/Unsubscribe_Controller.php`
- **Capability:** Public — `permission_callback` returns `true`. Token is the auth.
- **Query parameters:**

  | Name | Type | Required | Description |
  |---|---|---|---|
  | `token` | `string` | yes | HMAC-SHA256 signed token minted by `Unsubscribe_Manager::url_for()`. Format: `<base64url(email)>.<hex-hmac>`. |

- **Behaviour:** Verifies the token. On success, writes a suppression row
  (`source='link'`) and renders the `landing-unsubscribed.php` template with a
  "Resubscribe" button. On token failure, renders `landing-error.php` with a 400
  status.

- **Response (200):** HTML page with `Content-Type: text/html; charset=utf-8`,
  `Cache-Control: no-cache`, `X-Robots-Tag: noindex, nofollow`.

- **Example:**

  ```bash
  # The token is embedded in the unsubscribe link in the email footer.
  curl "https://example.com/wp-json/leastudios-email-templates/v1/unsubscribe?token=dXNlckBleGFtcGxlLmNvbQ.abc123hex"
  ```

---

### `POST /resubscribe`

- **Endpoint:** `/wp-json/leastudios-email-templates/v1/resubscribe`
- **Controller:** `src/REST/Unsubscribe_Controller.php`
- **Capability:** Public — `permission_callback` returns `true`. Token is the auth.
- **Request body:**

  | Name | Type | Required | Description |
  |---|---|---|---|
  | `token` | `string` | yes | The same signed token from the unsubscribe URL. |

- **Behaviour:** Verifies the token. On success, removes the suppression row and
  renders the `landing-resubscribed.php` confirmation page. On token failure,
  renders `landing-error.php` with a 400 status.

- **Response (200):** HTML page with same headers as `GET /unsubscribe`.

- **Example:**

  ```bash
  curl -X POST "https://example.com/wp-json/leastudios-email-templates/v1/resubscribe" \
       --data-urlencode "token=dXNlckBleGFtcGxlLmNvbQ.abc123hex"
  ```

---

## 9. WP-CLI Commands

All commands are registered under the `leastudios-email-templates` namespace
and are available whenever `WP_CLI` is defined.

---

### `wp leastudios-email-templates list-types`

- **File:** `src/CLI/Commands.php`
- **Synopsis:** `wp leastudios-email-templates list-types [--format=<format>]`
- **Options:**

  | Name | Type | Required | Description |
  |---|---|---|---|
  | `--format` | `string` | no | Output format. Default `table`. Options: `table`, `csv`, `json`, `yaml`, `count`, `ids`. |

- **Description:** Lists every email type registered in the `Email_Type_Registry`,
  including built-in and third-party types. Columns: `id`, `label`,
  `transactional_required` (yes/no), `source` (built-in/third-party). Useful for
  discovering valid type ids before running `preview` or `send-test`.

- **Example:**

  ```bash
  wp leastudios-email-templates list-types
  wp leastudios-email-templates list-types --format=json
  ```

---

### `wp leastudios-email-templates preview`

- **File:** `src/CLI/Commands.php`
- **Synopsis:** `wp leastudios-email-templates preview <type> [--data=<json>] [--subject]`
- **Options:**

  | Name | Type | Required | Description |
  |---|---|---|---|
  | `<type>` | `string` | yes | Registered email type id (e.g. `payment_receipt`). |
  | `--data` | `string` | no | JSON-encoded merge-tag context overrides (e.g. `'{"customer_name":"Ada Lovelace"}'`). Keys are unbraced tag names. Merged over the type's `sample_context()`. |
  | `--subject` | `flag` | no | Print only the rendered subject line. Skips HTML body output. |

- **Description:** Renders the email subject and full branded HTML body for the
  given type using its `sample_context()` (with optional overrides), then prints
  them to stdout. The output is pipeable to a file for browser inspection. The
  render goes through `Email_Sender::compose()` and `Template_Wrapper::wrap()` —
  identical to the admin preview — so it exercises the same merge-tag and
  template paths. Errors (unknown type, disabled type) call `WP_CLI::error()` and
  exit non-zero.

- **Example:**

  ```bash
  wp leastudios-email-templates preview payment_receipt
  wp leastudios-email-templates preview payment_receipt --subject
  wp leastudios-email-templates preview payment_receipt \
      --data='{"customer_name":"Ada Lovelace","amount":"$99.00"}' > /tmp/preview.html
  open /tmp/preview.html
  ```

---

### `wp leastudios-email-templates send-test`

- **File:** `src/CLI/Commands.php`
- **Synopsis:** `wp leastudios-email-templates send-test <type> <email> [--dry-run]`
- **Options:**

  | Name | Type | Required | Description |
  |---|---|---|---|
  | `<type>` | `string` | yes | Registered email type id. |
  | `<email>` | `string` | yes | Recipient address. Validated with `is_email()`. |
  | `--dry-run` | `flag` | no | Compose and print the `wp_mail` args without dispatching or writing a log row. |

- **Description:** Sends a real email of the given type to the supplied address
  using the type's `sample_context()`. The send goes through the identical code
  path as a production send (wrapper, plain-text injection, suppression gate,
  `email_sent` action, log row) except that the log row is tagged
  `source=cli-test`. If the recipient is suppressed and the type is not
  transactional-required, the command prints a warning and no email is sent (the
  suppression log row is still written). Use `--dry-run` to inspect the composed
  body without touching the mail transport.

- **Example:**

  ```bash
  wp leastudios-email-templates send-test payment_receipt support@example.test
  wp leastudios-email-templates send-test subscription_created me@example.test --dry-run
  ```

---

### `wp leastudios-email-templates list-suppressions`

- **File:** `src/CLI/Commands.php`
- **Synopsis:** `wp leastudios-email-templates list-suppressions [--format=<format>]`
- **Options:**

  | Name | Type | Required | Description |
  |---|---|---|---|
  | `--format` | `string` | no | Output format. Default `table`. Options: `table`, `csv`, `json`, `yaml`, `count`, `ids`. |

- **Description:** Lists all suppressed email addresses from the suppressions
  table. Columns: `email`, `suppressed_at`, `source` (`link`, `admin`, `cli`).
  Capped at 1000 rows. For bulk export beyond the cap, query the table directly
  via `wp db query` or the `Suppression_Repository` class.

- **Example:**

  ```bash
  wp leastudios-email-templates list-suppressions
  wp leastudios-email-templates list-suppressions --format=csv > suppressions.csv
  ```

---

### `wp leastudios-email-templates add-suppression`

- **File:** `src/CLI/Commands.php`
- **Synopsis:** `wp leastudios-email-templates add-suppression <email> [--source=<source>]`
- **Options:**

  | Name | Type | Required | Description |
  |---|---|---|---|
  | `<email>` | `string` | yes | Email address to suppress. Validated with `is_email()`. |
  | `--source` | `string` | no | Source marker for the new row. Default `cli`. Any string up to 16 chars is accepted. |

- **Description:** Writes a suppression row for the given address. Idempotent —
  if the address is already suppressed, the `suppressed_at` timestamp and `source`
  are refreshed. Email is normalized to lowercase before storage, so
  `Jane@Example.com` and `jane@example.com` deduplicate correctly. Useful for
  bulk migrations (pass `--source=migration`) or honouring opt-out requests
  received outside the normal email flow.

- **Example:**

  ```bash
  wp leastudios-email-templates add-suppression jane@example.com
  wp leastudios-email-templates add-suppression jane@example.com --source=migration
  ```

---

### `wp leastudios-email-templates remove-suppression`

- **File:** `src/CLI/Commands.php`
- **Synopsis:** `wp leastudios-email-templates remove-suppression <email>`
- **Options:**

  | Name | Type | Required | Description |
  |---|---|---|---|
  | `<email>` | `string` | yes | Email address to un-suppress. Validated with `is_email()`. |

- **Description:** Deletes the suppression row for the given address, allowing
  future non-required-type sends to proceed. If the address is not currently
  suppressed, the command exits with a warning (non-zero exit). This is the
  CLI equivalent of clicking "Remove" in the admin Suppressions page and is also
  called by the REST `POST /resubscribe` endpoint (via `Unsubscribe_Manager`).

- **Example:**

  ```bash
  wp leastudios-email-templates remove-suppression jane@example.com
  ```

---

## 10. Public PHP API

### `LEAStudios\EmailTemplates\Email\Email_Type_Definition` *(interface)*

- **File:** `src/Email/Email_Type_Definition.php`
- **Since:** 1.1.0
- **Purpose:** The contract every transactional email type must implement. Register
  implementations via `leastudios_email_templates_register_types`. The interface
  is the stable extension surface — the built-in class `Abstract_Email_Type` is a
  convenience base that is NOT part of the public contract.

**Methods:**

| Method | Signature | Description |
|---|---|---|
| `id` | `(): string` | Stable, unique slug matching `^[a-z][a-z0-9_]*$`. Used as the option key, log column, and registry map key. |
| `label` | `(): string` | Human-readable translated label shown in admin UI. |
| `default_subject` | `(): string` | Default subject template (may contain `{tags}`). Used when admin leaves the custom subject blank. |
| `default_body` | `(): string` | Default body HTML template (may contain `{tags}`). |
| `available_tags` | `(): array<string, array{description: string, escape: Escape_Mode}>` | Tags this type advertises in the admin UI. Keyed by braced name (`{customer_name}`). |
| `escape_map` | `(): array<string, Escape_Mode>` | Per-tag escape mode by unbraced key. Built-ins derive this from `available_tags()` via `Abstract_Email_Type`. |
| `sample_context` | `(): array<string, string>` | Realistic sample values for preview and `send-test`. |
| `is_transactional_required` | `(): bool` | `true` to bypass the suppression gate. Default `false`. |

**Example implementation:** see "How do I add a new email type?" in Extension Recipes.

---

### `LEAStudios\EmailTemplates\Email\Abstract_Email_Type` *(abstract class)*

- **File:** `src/Email/Abstract_Email_Type.php`
- **Since:** 1.1.0
- **Purpose:** Convenience base class providing default implementations of
  `escape_map()` (projected from `available_tags()`) and
  `is_transactional_required()` (returns `false`). Extend this rather than
  implementing `Email_Type_Definition` directly to avoid writing boilerplate.

See the recipe "How do I add a new email type?" for a full worked example.

---

## 11. Extension Recipes

### How do I add a new email type?

**Goal:** Register a custom transactional email type that appears in the admin UI,
gets send-logged, and is available via CLI.

**Hooks used:** `leastudios_email_templates_register_types`.

**Walkthrough:** Implement `Email_Type_Definition` (or extend `Abstract_Email_Type`)
in your own plugin. Define a stable `id()`, a translated `label()`, default
subject and body templates, and the `{tags}` your type uses (with their escape
modes). In your plugin's main file — at file scope so the callback is queued
before `plugins_loaded:10` fires — hook `leastudios_email_templates_register_types`
and call `$registry->register( new My_Type() )`.

Once registered, the type appears automatically in the Email Types admin tab
(with subject/body/recipient-override fields), in the send log filter dropdown,
and in `wp leastudios-email-templates list-types`. To dispatch the email, call
`Email_Sender::send( 'my_welcome_email', $to, $context )` from your own code
or hook a WordPress action.

**Complete example:**

```php
<?php
// In my-plugin/my-plugin.php, at file scope:

use LEAStudios\EmailTemplates\Email\Abstract_Email_Type;
use LEAStudios\EmailTemplates\Email\Email_Type_Registry;
use LEAStudios\EmailTemplates\Email\Escape_Mode;

class My_Welcome_Email extends Abstract_Email_Type {
    public function id(): string { return 'my_welcome_email'; }

    public function label(): string {
        return __( 'Welcome Email', 'my-plugin' );
    }

    public function default_subject(): string {
        return __( 'Welcome to {site_name}, {first_name}!', 'my-plugin' );
    }

    public function default_body(): string {
        return '<p>' . esc_html__( 'Hi {first_name}, your account is ready.', 'my-plugin' ) . '</p>';
    }

    public function available_tags(): array {
        return [
            '{first_name}' => [
                'description' => __( 'Customer first name.', 'my-plugin' ),
                'escape'      => Escape_Mode::HTML,
            ],
            '{login_url}' => [
                'description' => __( 'Direct login URL.', 'my-plugin' ),
                'escape'      => Escape_Mode::URL,
            ],
        ];
    }

    public function sample_context(): array {
        return [
            'first_name' => 'Ada',
            'login_url'  => wp_login_url(),
        ];
    }
}

add_action(
    'leastudios_email_templates_register_types',
    function ( Email_Type_Registry $registry ): void {
        $registry->register( new My_Welcome_Email() );
    }
);

// Dispatch when a user registers:
add_action( 'user_register', function ( int $user_id ): void {
    $user = get_userdata( $user_id );
    if ( false === $user ) {
        return;
    }

    // Email_Sender is not directly injectable; fire via do_action or
    // use the global approach shown here. A better architecture is to
    // inject Email_Sender into your own service class and call send() there.
    do_action( 'my_plugin_send_welcome_email', $user->user_email, [
        'first_name' => $user->first_name ?: $user->display_name,
        'login_url'  => wp_login_url(),
    ] );
} );
```

---

### How do I add a custom merge tag?

**Goal:** Inject a `{support_url}` tag globally available in every email type's
subject and body without modifying each type's `available_tags()`.

**Hooks used:** `leastudios_email_templates_merge_tags`.

**Walkthrough:** The `leastudios_email_templates_merge_tags` filter fires on every
render call (both `replace_html()` and `replace_subject()`). Add your tag to the
`$context` array with the unbraced key. Because `Merge_Tag_Replacer` HTML-escapes
any key absent from the escape map, add your global tag to the escape map via a
separate filter or accept the default HTML-escaping behaviour. For URL-type tags
you should source the URL through `esc_url()` directly in your callback rather
than relying on the escape map.

**Complete example:**

```php
add_filter(
    'leastudios_email_templates_merge_tags',
    function ( array $context, string $content ): array {
        // Global {support_url} tag — always URL-escaped at use site.
        $context['support_url'] = esc_url( home_url( '/support/' ) );

        // Global {account_url} — conditional on whether WooCommerce is active.
        if ( function_exists( 'wc_get_account_endpoint_url' ) ) {
            $context['account_url'] = esc_url( wc_get_account_endpoint_url( 'dashboard' ) );
        }

        return $context;
    },
    10,
    2
);
```

To use the tag, add `{support_url}` anywhere in an email's subject or body
template. The tag resolves globally, even in third-party types that don't declare
it in `available_tags()`.

---

### How do I send a transactional email from another plugin?

**Goal:** Dispatch a registered email type (e.g. a welcome email) from your own
plugin's code without coupling to `Email_Sender`'s constructor.

**Hooks used:** `leastudios_email_templates_email_sent` (to observe the result).

**Walkthrough:** The cleanest integration point is to hook a WordPress action
from your plugin and let `Email_Sender` dispatch it. Since `Email_Sender` is
internal (constructed by `Plugin::init()` and not available via a static accessor),
the recommended pattern is to fire a custom action that your own listener — which
does have a reference to the sender — handles. This mirrors how
`Payment_Email_Listener` works: it receives an injected `Email_Sender` instance
in its constructor and calls `send()` from action callbacks.

Alternatively, call `do_action( 'my_plugin_dispatch_email', $type_id, $to, $context )`
from your code, and hook that action in a class that receives `Email_Sender` via
dependency injection in your plugin's bootstrap.

**Complete example:**

```php
<?php
// In my-plugin/src/Email/Welcome_Email_Listener.php

namespace My_Plugin\Email;

use LEAStudios\EmailTemplates\Email\Email_Sender;

class Welcome_Email_Listener {
    public function __construct(
        private readonly Email_Sender $sender,
    ) {}

    public function init(): void {
        add_action( 'user_register', [ $this, 'on_user_register' ] );
    }

    public function on_user_register( int $user_id ): void {
        $user = get_userdata( $user_id );
        if ( false === $user ) {
            return;
        }

        $this->sender->send(
            'my_welcome_email',
            sanitize_email( $user->user_email ),
            [
                'first_name' => sanitize_text_field( $user->first_name ?: $user->display_name ),
                'login_url'  => wp_login_url(),
            ]
        );
    }
}

// In my-plugin/my-plugin.php — hook before plugins_loaded:10 resolves:
add_action( 'leastudios_email_templates_register_types', function ( $registry ) use ( &$sender_ref ) {
    // Capture the sender instance once it is available (it is constructed
    // inside Plugin::init() on the same plugins_loaded:10 call, so we must
    // store our listener initialization until after Plugin::init() completes).
} );

// Hook at priority 20 (after Plugin::init at 10):
add_action( 'plugins_loaded', function (): void {
    if ( ! class_exists( \LEAStudios\EmailTemplates\Email\Email_Sender::class ) ) {
        return;
    }
    // Plugin::init() has completed. Build your listener using the same
    // composition root approach: hook email_sent to confirm send results.
    add_action(
        'leastudios_email_templates_email_sent',
        function ( string $type_id, string $to, string $subject, bool $result ): void {
            if ( 'my_welcome_email' === $type_id && ! $result ) {
                error_log( 'Welcome email failed for: ' . sanitize_email( $to ) );
            }
        },
        10,
        4
    );
}, 20 );
```

---

### How do I override the default template wrapper?

**Goal:** Substitute a custom branded email base template from your theme or
plugin, replacing `templates/email/base.php`.

**Hooks used:** `leastudios_email_templates_template_path`.

**Walkthrough:** The `leastudios_email_templates_template_path` filter fires
inside `Template_Wrapper::wrap()` with the absolute path to the default template
as the only argument. Return a different absolute path to load your template
instead. If your returned path does not exist (`file_exists()` returns false),
the inner body is returned unwrapped — so always fall back to the original path
when your override is absent.

Your template file receives the same extracted variables as the built-in:
`$body_html`, `$logo_url`, `$primary_color`, `$footer_text`, `$social_links`,
`$site_name`, `$colors`, and `$prefers_dark`. All values originate from the
`leastudios_email_templates_branding` option and are already resolved by the time
the template is included.

**Complete example:**

```php
// In my-theme/functions.php or my-plugin/my-plugin.php:

add_filter(
    'leastudios_email_templates_template_path',
    function ( string $template_path ): string {
        // Prefer a template in the active child theme, then parent theme.
        $child_template  = get_stylesheet_directory() . '/email/base.php';
        $parent_template = get_template_directory() . '/email/base.php';

        if ( file_exists( $child_template ) ) {
            return $child_template;
        }

        if ( file_exists( $parent_template ) ) {
            return $parent_template;
        }

        return $template_path; // Fall back to the plugin's default.
    }
);
```

Inside your `email/base.php`, render the branded wrapper. Available variables:

```php
<?php
// email/base.php — all variables are pre-resolved, escape at render time.
// $body_html     (string)  — inner HTML; may contain trusted HTML (RAW-escaped tags).
// $logo_url      (string)  — full URL to the logo image.
// $primary_color (string)  — hex colour, e.g. '#4f46e5'.
// $footer_text   (string)  — already merge-tag processed; may contain HTML.
// $social_links  (array)   — associative: twitter, facebook, linkedin, instagram.
// $site_name     (string)  — plain text.
// $colors        (object)  — theme colour palette from Theme::from_id().
// $prefers_dark  (bool)    — whether the theme supports prefers-color-scheme.
?>
<!doctype html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head><meta charset="UTF-8"></head>
<body>
<div style="background:<?php echo esc_attr( $colors->background ?? '#ffffff' ); ?>">
    <?php if ( $logo_url ) : ?>
        <img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $site_name ); ?>">
    <?php endif; ?>
    <div><?php echo $body_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped at source ?></div>
    <footer><?php echo wp_kses_post( $footer_text ); ?></footer>
</div>
</body>
</html>
```

---

### How do I preview a rendered template programmatically?

**Goal:** Render and inspect a type's composed subject and HTML body from your
own code (e.g. for a settings-page AJAX handler or an automated test assertion).

**Hooks used:** `leastudios_email_templates_merge_tags` (fires during compose),
`leastudios_email_templates_template_path` (fires during wrap).

**Walkthrough:** `Email_Sender::compose()` returns an array with `subject`,
`body`, and `headers` without dispatching — no `wp_mail()` call, no log row.
Pass a custom `$context` array to override the type's `sample_context()`. To
include the full branded wrapper, call `Template_Wrapper::wrap( $composed['body'] )`
on the result. This is exactly what `Commands::render_preview()` does. Since
both classes are internal (not in the public API), use this pattern only in
code that runs inside the same WordPress process after `Plugin::init()` has
completed (i.e. after `plugins_loaded:10`).

**Complete example:**

```php
// In a settings-page AJAX handler:

add_action( 'wp_ajax_my_plugin_preview_email', function (): void {
    check_ajax_referer( 'my_plugin_preview', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Forbidden', 403 );
    }

    $type_id = sanitize_key( wp_unslash( $_POST['type_id'] ?? '' ) );

    // Email_Sender is not a static singleton — access it via your own
    // service locator or by resolving via WP's global function (if your
    // plugin stores the instance on a hook-accessible object).
    // For testing, instantiate directly with the required dependencies.
    global $wpdb;
    $replacer  = new \LEAStudios\EmailTemplates\Email\Merge_Tag_Replacer();
    $registry  = apply_filters( 'my_plugin_email_registry', null );

    if ( null === $registry ) {
        wp_send_json_error( 'Registry not available — ensure leastudios-email-templates is active.' );
    }

    $suppression_repo = new \LEAStudios\EmailTemplates\Database\Suppression_Repository();
    $manager          = new \LEAStudios\EmailTemplates\Subscription\Unsubscribe_Manager( $suppression_repo );
    $sender           = new \LEAStudios\EmailTemplates\Email\Email_Sender( $replacer, $registry, $manager );

    $definition = $registry->get( $type_id );
    if ( null === $definition ) {
        wp_send_json_error( esc_html( "Unknown type: {$type_id}" ) );
    }

    $composed = $sender->compose( $type_id, $definition->sample_context() );
    if ( null === $composed ) {
        wp_send_json_error( 'Type is disabled in settings.' );
    }

    $wrapper  = new \LEAStudios\EmailTemplates\Email\Template_Wrapper( $replacer );
    $html     = $wrapper->wrap( $composed['body'] );

    wp_send_json_success( [
        'subject' => esc_html( $composed['subject'] ),
        'body'    => $html,
    ] );
} );
```

---

### How do I bulk-import suppressions from the CLI?

**Goal:** Migrate an opt-out list from a previous ESP into the suppressions table
without manual admin UI entry.

**Hooks used:** None (CLI command path).

**Walkthrough:** Use `wp leastudios-email-templates add-suppression` in a shell
loop, or pipe a CSV column to the command. The command validates each address
with `is_email()`, normalizes to lowercase, and writes an idempotent upsert —
re-running the migration is safe. Use `--source=migration` to tag the rows
distinctly from link and admin origins, which makes audit queries easier. After
the import, use `list-suppressions --format=count` to confirm the expected row
count.

**Complete example:**

```bash
#!/usr/bin/env bash
# bulk-suppress.sh — import a newline-delimited opt-out list.
# Usage: bash bulk-suppress.sh opt-outs.txt

set -euo pipefail

FILE="${1:?Usage: $0 <opt-out-list.txt>}"
COUNT=0

while IFS= read -r email; do
    # Strip whitespace and skip empty lines.
    email="$(echo "$email" | tr -d '[:space:]')"
    [[ -z "$email" ]] && continue

    wp leastudios-email-templates add-suppression "$email" --source=migration \
        && (( COUNT++ )) \
        || echo "SKIP: $email (invalid or error)" >&2
done < "$FILE"

echo "Imported $COUNT suppressions."
wp leastudios-email-templates list-suppressions --format=count
```

---

## 12. Testing

```bash
cd wp-content/plugins/leastudios-email-templates
composer test                                              # run the full suite
vendor/bin/phpunit --filter MergeTagReplacer               # one class
vendor/bin/phpunit tests/PaymentEmailListenerTest.php      # one file
```

The suite uses PHPUnit 9.6 against the WordPress test library (`/tmp/wordpress-tests-lib`).
Install it once with:

```bash
bash ../leastudios-dev-tools/bin/install-wp-tests.sh wordpress_test root '' 127.0.0.1 latest
```

**Writing tests for an extension that loads this plugin:**

1. Extend the local `tests/TestCase.php` (or WordPress's `WP_UnitTestCase`) and
   ensure `leastudios-email-templates` is active in the test environment.
2. The type registry is populated during `Plugin::init()` (which runs on
   `plugins_loaded`). If you need to assert registry state after registering a
   custom type, hook `leastudios_email_templates_register_types` in your test's
   `setUp()` before calling `do_action( 'plugins_loaded' )`.
3. `Email_Sender` accepts its three dependencies via constructor injection —
   instantiate it directly in tests rather than retrieving it from the global
   scope.
4. To test the suppression gate, use `Suppression_Repository::upsert()` to write
   a row, call `Email_Sender::send()`, and assert the `leastudios_email_templates_email_suppressed`
   action fired via `did_action()`.

---

## 13. Release Process

This plugin uses a tag-triggered release workflow (`.github/workflows/release.yml`)
that auto-generates release notes from the commit log between the previous and
current tag.

**To cut a release:** bump the `Version:` header in `leastudios-email-templates.php`,
commit, then:

```bash
git tag vX.Y.Z && git push origin vX.Y.Z
```

The workflow verifies the tag matches the `Version:` header, builds the zip with
`composer install --no-dev`, and publishes the GitHub release.

**Commit-prefix → release-notes section:**

- `feat:` → `## Added`
- `fix:` → `## Fixed`
- `refactor:` → `## Changed`
- `perf:` → `## Performance`

**Hidden from release notes:** `ci:`, `chore:`, `docs:`, `test:`, `style:`, `build:`, `release:`.

---

## 14. Where to Read More

- [`CLAUDE.md`](../CLAUDE.md) — this plugin's repo conventions, architecture map, option keys, cross-plugin coupling details, and gotchas.
- [`README.md`](../README.md) — user-facing overview, feature list, WP-CLI quick reference.
- [`leastudios-dev-tools/CLAUDE.md`](../../leastudios-dev-tools/CLAUDE.md) — suite-wide coding standards, security checklist (escape / sanitize / nonce / capability), REST and i18n conventions inherited by every plugin.
- [`leastudios-mailer — Developer Handbook`](../../leastudios-mailer/docs/developer-handbook.md) — the Amazon SES transport layer this plugin integrates with (`leastudios_mailer_pre_send` filter).
- [`leastudios-payments — Developer Handbook`](../../leastudios-payments/docs/developer-handbook.md) — the payments plugin whose actions (`leastudios_payments_order_created`, `…subscription_synced`, etc.) trigger the five built-in transactional email types.
