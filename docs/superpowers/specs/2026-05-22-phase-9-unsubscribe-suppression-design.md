# Phase 9 — Unsubscribe / Suppression Support (Design)

**Date:** 2026-05-22
**Status:** Brainstormed, awaiting plan
**Roadmap reference:** `docs/superpowers/plans/2026-05-22-roadmap.md` (lines 149–164)

---

## Goal

Ship a compliant, audit-recognized opt-out mechanism for outgoing emails. The largest single piece of the 2026-05-22 improvement roadmap.

## Acceptance criteria

1. Visiting `GET /wp-json/leastudios-email-templates/v1/unsubscribe?token=…` with a valid signed token writes a suppression row keyed by the recipient's email address.
2. The next call to `Email_Sender::send($type, $to, ...)` for a non-required type addressed to that recipient is skipped (no `wp_mail` call), and a log row is written with `status='suppressed'`.
3. Calls to required types (`payment_receipt`, `payment_failed`, `refund_processed`, `subscription_renewed`) still send to suppressed recipients.
4. The merge tag `{unsubscribe_url}` resolves to a real URL for non-required-type sends with a real recipient, and to an empty string otherwise.
5. The landing page offers a re-subscribe button that, when posted, removes the suppression row and shows a "you're back" confirmation.
6. Suppressions can be listed, added, and removed via both `wp-admin → Settings → Email Templates → Suppressions` and three new WP-CLI subcommands.
7. `composer phpcs`, `composer phpstan` (level 7), and `composer test` all pass clean.

---

## Foundational design decisions (resolved during brainstorming)

These were chosen against alternatives during the brainstorming session and are now locked. Each is stated as a decision, not a question.

### D1 — Demote `subscription_created` to non-required

`Subscription_Created::is_transactional_required()` returns `false`. The other four built-ins keep returning `true` (receipts and refunds are legally-required transactional records of money movement; `subscription_renewed` IS the receipt for a recurring charge).

**Why:** Gives Phase 9 a real shipped consumer for the suppression gate without entangling the engineering phase with the harder judgment call on renewal receipts. The "welcome / your subscription is active" email is the only built-in that's clearly lifecycle/non-transactional in nature.

### D2 — HMAC-SHA256 token, stateless

Token format: `<base64url(email)>.<hex hmac-sha256(email, secret)>`. Secret stored in a `wp_option` (`leastudios_email_templates_unsubscribe_secret`, autoload=no), generated lazily by `wp_generate_password(64, true, true)`. Verified via `hash_equals` (constant time).

**No expiry. No single-use.** Rotation = delete the option; all outstanding links invalidate.

**Why:** Zero per-send DB writes; standard audit-recognized pattern; WordPress already has the primitives; statelessness composes (link sent today still works in 18 months); revocation is rare and clean when needed. The DB-row alternative only wins for per-recipient token invalidation, which isn't a real-world incident shape for unsubscribe links. Single-use guard is overkill — replaying an unsubscribe is harmless.

### D3 — Global per-recipient suppression

One suppression row per email address. No per-type granularity for Phase 9.

**Why:** Matches the plain-English contract of the "Unsubscribe" footer link. Keeps the table simple (`id, email, suppressed_at, source`). Forward-compatible: a nullable `type_id` column can be added later without breaking existing rows.

### D4 — Footer auto-append: always-on for non-required, no admin toggle

`Email_Sender::send` appends the unsubscribe footer HTML to the body when `!is_transactional_required() && is_email($to)`. No setting. The per-type escape hatch is `is_transactional_required()` itself.

**Why:** Compliance defaults must be safe-by-default. A checkbox someone could leave unchecked is a footgun. No UI to maintain, no test combinations, no support tickets about missing links. The `{unsubscribe_url}` merge tag remains available for admins who want to ALSO place the link inline; both can coexist (industry standard — Mailchimp does this).

**Appended inside `Email_Sender`, not `Template_Wrapper`.** Keeps the wrapper type-ignorant and recipient-ignorant.

### D5 — One-click unsubscribe (GET), two-click resubscribe (POST)

- `GET /unsubscribe?token=…` — immediately writes the suppression row, renders the confirmation landing.
- `POST /resubscribe` (token in body, from the landing-page form) — removes the suppression row, renders the "you're back" landing.

**Why one-click for unsub:** RFC 8058 — Gmail and Apple Mail expect one-click List-Unsubscribe-Post from bulk senders. Two-click flows hurt deliverability. Bot-prefetch unsubscribes are accepted industry-wide as the gateway's problem.

**Why two-click (POST) for resub:** Automated prefetch can't accidentally re-open delivery to a previously opted-out user.

**Why two routes** instead of one with an action param: cleaner REST surface, less branching in the controller, mirrors every major ESP. Token verification is shared via `Unsubscribe_Manager`.

### D6 — Suppressed log row uses new status value, new action

`email_log.status` gains the value `'suppressed'`. No schema change (`varchar(16)` already accommodates). A new action `leastudios_email_templates_email_suppressed` fires when a send is gated; `Send_Logger` listens to it independently of `_email_sent`. Body/headers are recorded on the row (audit trail of "what would have been sent").

**Why a new action:** Conflating failures with deliberate skips on the existing `_email_sent` action would force every listener to disambiguate. Cleaner to have two semantically distinct signals.

### D7 — `{unsubscribe_url}` as a recipient-aware global tag

`Email_Sender` prepends `unsubscribe_url` to `$context` before calling the replacer (so `Merge_Tag_Replacer` stays recipient-ignorant). Value resolves to `''` for required-type sends or empty recipients, else `$manager->url_for($to)`. The escape mode (`Escape_Mode::URL`) is registered in `Merge_Tag_Replacer::get_global_escape_modes()` alongside `site_url`. Wired through filter `leastudios_email_templates_unsubscribe_url`.

### D8 — Secret not encrypted

Stored as a plain `wp_option` (autoload=no). Not run through `Options_Encryptor`.

**Why:** The secret's only role is minting unsubscribe links. Leaking it lets an attacker forge unsubscribe links for known emails — worst case is a targeted unsubscribe. Low stakes. The encryption layer is reserved for true credentials (API keys, PII at rest).

### D9 — WP-CLI parity

Three new subcommands on the existing `src/CLI/Commands.php`:

```
wp leastudios-email-templates list-suppressions [--format=<table|csv|json|yaml|count|ids>]
wp leastudios-email-templates add-suppression <email> [--source=<source>]
wp leastudios-email-templates remove-suppression <email>
```

Same constructor-injection pattern as Phase 8.

### D10 — Uninstall

`uninstall.php` drops the suppressions table and deletes both new options (secret + schema-version).

---

## Architecture

### File layout

```
src/
├── Subscription/                    NEW folder
│   └── Unsubscribe_Manager.php      Token mint/verify, suppress/unsuppress, is_suppressed, url_for
├── Database/
│   ├── Email_Log_Repository.php     (existing; status accepts 'suppressed' — no schema change)
│   ├── Suppression_Repository.php   NEW — $wpdb wrapper, install(), upsert, exists_by_email, delete_by_email, paginate, drop
│   └── Suppression_Entry.php        NEW — value object
├── REST/                            NEW folder (first REST controller in this plugin)
│   └── Unsubscribe_Controller.php   register_routes(); GET /unsubscribe, POST /resubscribe; renders HTML landings
├── Admin/
│   └── Suppressions_Page.php        NEW — list table + add form, capability manage_options
├── Email/
│   ├── Email_Sender.php             +manager ctor arg; +suppression gate; +unsubscribe_url context; +footer append
│   ├── Merge_Tag_Replacer.php       +unsubscribe_url in get_global_escape_modes()
│   └── Built_In/Subscription_Created.php  is_transactional_required(): false
├── Log/
│   └── Send_Logger.php              +listens to leastudios_email_templates_email_suppressed
└── CLI/
    └── Commands.php                 +manager ctor arg; +list_suppressions; +add_suppression; +remove_suppression

templates/
└── unsubscribe/                     NEW folder
    ├── landing-unsubscribed.php     "You're unsubscribed" + resubscribe POST form
    ├── landing-resubscribed.php     "You're back"
    └── landing-error.php            "Invalid or expired link"

uninstall.php                        +drop suppressions table, +delete secret + schema version options
```

### Composition root (`Plugin::init`)

Additions (in order):

```php
// Persistent suppression store.
$suppression_repo = new Suppression_Repository();
$suppression_repo->install();

// Unsubscribe manager + REST controller.
$manager = new Unsubscribe_Manager( $suppression_repo );

add_action(
    'rest_api_init',
    static function () use ( $manager ): void {
        ( new Unsubscribe_Controller( $manager ) )->register_routes();
    }
);

// Sender ctor gains the manager (3rd arg).
$sender = new Email_Sender( $replacer, $registry, $manager );

// CLI ctor gains the manager (4th arg).
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    $cli_commands = new Commands( $registry, $sender, $replacer, $manager );
    // ... existing 3 add_command calls ...
    \WP_CLI::add_command( 'leastudios-email-templates list-suppressions', [ $cli_commands, 'list_suppressions' ] );
    \WP_CLI::add_command( 'leastudios-email-templates add-suppression', [ $cli_commands, 'add_suppression' ] );
    \WP_CLI::add_command( 'leastudios-email-templates remove-suppression', [ $cli_commands, 'remove_suppression' ] );
}

// Admin page (existing is_admin block).
if ( is_admin() ) {
    // ... existing Settings_Page and Email_Log_Page wiring ...
    ( new Suppressions_Page( $suppression_repo ) )->init();
}
```

### Public extension points added in Phase 9

- **Action `leastudios_email_templates_email_suppressed`** — args: `string $type_id, string $to, string $subject, string $body, array $headers, string $source`. Fires when `Email_Sender::send` skips a send due to suppression. `Send_Logger` consumes it.
- **Filter `leastudios_email_templates_unsubscribe_url`** — `(string $url, string $email, string $type_id) => string`. Lets sites swap the URL (e.g., route through a CDN or rebrand the path).
- **Filter `leastudios_email_templates_unsubscribe_footer_html`** — `(string $default_html, string $to, string $type_id) => string`. Lets sites customize the auto-appended footer markup.
- **Filter `leastudios_email_templates_unsubscribe_token_secret`** — `(string $secret) => string`. Escape hatch for sites that want to source the secret from a constant or env var rather than the `wp_option`. Applied inside `get_or_create_secret()` before persistence is consulted.

---

## Database

### New table: `wp_leastudios_email_templates_suppressions`

```sql
CREATE TABLE wp_leastudios_email_templates_suppressions (
    id            bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    email         varchar(255)        NOT NULL DEFAULT '',
    suppressed_at datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    source        varchar(16)         NOT NULL DEFAULT 'link',
    PRIMARY KEY  (id),
    UNIQUE KEY email_unique (email),
    KEY suppressed_at_idx (suppressed_at)
) {$charset_collate};
```

- `email` normalized to `strtolower(trim($email))` at both insert and lookup time. The `UNIQUE` constraint + `INSERT … ON DUPLICATE KEY UPDATE suppressed_at = NOW(), source = VALUES(source)` makes re-suppression idempotent.
- `source` values: `'link'` (REST unsubscribe), `'admin'` (admin add-form), `'cli'` (WP-CLI add-suppression). Mirrors `email_log.source` naming.
- Schema version tracked in `wp_option` `leastudios_email_templates_suppressions_schema_version`, starting at `1.0.0`. Same short-circuit `if (get_option(...) === self::SCHEMA_VERSION) return;` pattern as `Email_Log_Repository::install()`.
- `dbDelta`-safe formatting: two spaces after `PRIMARY KEY`, keys on their own lines, charset from `$wpdb->get_charset_collate()`.

### New options

- `leastudios_email_templates_unsubscribe_secret` — autoload **no**, 64-char string, generated lazily.
- `leastudios_email_templates_suppressions_schema_version` — autoload yes, short string.

### Existing schema impact: none

`email_log.status` is already `varchar(16) NOT NULL DEFAULT 'sent'`. Adding the value `'suppressed'` is a code-side change only; no migration.

---

## Token (`Unsubscribe_Manager`)

### Format

`<payload>.<sig>` where
- `payload = rtrim(strtr(base64_encode($email_lowered), '+/', '-_'), '=')` — URL-safe base64 of the normalized email.
- `sig = hash_hmac('sha256', $email_lowered, $secret)` — 64 hex chars.

Total token length: 80–120 chars depending on email length. Fits in a query string without issue.

### Verification

1. Split on the LAST `.` (emails can contain `.` in the local part; base64url can't, but be defensive).
2. base64url-decode the payload to recover the candidate email.
3. Recompute the HMAC with the candidate email.
4. `hash_equals($recomputed, $provided_sig)` — constant time.
5. On success, return the (already-normalized) email. On any failure, return `null`.

No expiry check; no nonce check; no single-use bookkeeping.

### Secret lifecycle

`get_or_create_secret()`:
1. Apply filter `leastudios_email_templates_unsubscribe_token_secret` with `''` as the initial value. If the filter returns non-empty, use that (escape hatch for env-var sourcing).
2. Otherwise, `get_option(SECRET_OPTION, '')`.
3. If empty, `wp_generate_password(64, true, true)` + `update_option(SECRET_OPTION, $secret, false)`.

---

## REST controller

### Routes

```
GET  /wp-json/leastudios-email-templates/v1/unsubscribe   ?token=…
POST /wp-json/leastudios-email-templates/v1/resubscribe   (token in body)
```

Both have `permission_callback => '__return_true'`. Token IS the auth.

### Handler flow — GET /unsubscribe

```php
public function unsubscribe( WP_REST_Request $request ): WP_REST_Response {
    $token = (string) $request->get_param( 'token' );
    $email = $this->manager->verify_token( $token );

    if ( null === $email ) {
        return $this->html_response( 400, $this->render( 'landing-error.php', [] ) );
    }

    $this->manager->suppress( $email, 'link' );
    return $this->html_response(
        200,
        $this->render( 'landing-unsubscribed.php', [ 'email' => $email, 'token' => $token ] )
    );
}

private function html_response( int $status, string $html ): WP_REST_Response {
    $response = new WP_REST_Response( $html, $status );
    $response->header( 'Content-Type', 'text/html; charset=utf-8' );
    foreach ( wp_get_nocache_headers() as $name => $value ) {
        $response->header( $name, $value );
    }
    return $response;
}
```

### Handler flow — POST /resubscribe

```php
public function resubscribe( WP_REST_Request $request ): WP_REST_Response {
    $token = (string) $request->get_param( 'token' );
    $email = $this->manager->verify_token( $token );

    if ( null === $email ) {
        return $this->html_response( 400, $this->render( 'landing-error.php', [] ) );
    }

    $this->manager->unsuppress( $email );
    return $this->html_response(
        200,
        $this->render( 'landing-resubscribed.php', [ 'email' => $email ] )
    );
}
```

### Landing templates

Live in `templates/unsubscribe/`. Render with a small `Unsubscribe_Controller::render( string $template, array $vars ): string` helper that `ob_start()`s, extracts vars, includes the file, returns the buffer. No enqueued assets — these pages are standalone and have inline `<style>` mirroring the email template's centered-card look.

`landing-unsubscribed.php` contains the resubscribe form:

```html
<form method="POST" action="<?php echo esc_url( rest_url( 'leastudios-email-templates/v1/resubscribe' ) ); ?>">
    <input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>">
    <button type="submit"><?php esc_html_e( 'Resubscribe', 'leastudios-email-templates' ); ?></button>
</form>
```

---

## `Email_Sender` changes

### Constructor — gain `Unsubscribe_Manager`

```php
public function __construct(
    private readonly Merge_Tag_Replacer  $replacer,
    private readonly Email_Type_Registry $registry,
    private readonly Unsubscribe_Manager $manager,
) {}
```

### `send()` — three insertion points

1. **Suppression gate (top of method, after registry lookup):**

```php
if ( ! $definition->is_transactional_required() && $this->manager->is_suppressed( $to ) ) {
    return $this->fire_suppressed( $type_id, $to, $context, $source );
}
```

`fire_suppressed()` calls `compose( $type_id, $context, $to )` to get the would-have-been subject/body/headers (with `{unsubscribe_url}` populated), THEN appends `render_unsubscribe_footer( $to, $type_id )` to the composed body so the logged row matches what would have been sent. It then fires `do_action( 'leastudios_email_templates_email_suppressed', $type_id, $to, $subject, $body, $headers, $source )` and returns `false`. Audit completeness is the reason the footer is included in the suppressed-path body even though `wp_mail` is never called.

2. **`{unsubscribe_url}` injection** — moved into `compose()` so previews and CLI render identically. See below.

3. **Footer auto-append, after compose, before `wp_mail`:**

```php
if ( ! $definition->is_transactional_required() && '' !== $to && is_email( $to ) ) {
    $composed['body'] .= $this->render_unsubscribe_footer( $to, $type_id );
}
```

`render_unsubscribe_footer()` returns a `<p>` tag wrapped via `apply_filters('leastudios_email_templates_unsubscribe_footer_html', $default_html, $to, $type_id)`.

### `compose()` — `{unsubscribe_url}` context injection

`compose()` doesn't currently receive a recipient. **Add an optional `$to = ''` parameter** so the recipient flows through cleanly. Signature becomes:

```php
public function compose( string $type_id, array $context = [], string $to = '' ): ?array
```

Inside `compose()`, after settings lookup:

```php
$context = array_merge(
    [ 'unsubscribe_url' => $this->resolve_unsubscribe_url( $to, $definition ) ],
    $context
);
```

This is backward-compatible (existing callers — settings AJAX preview, CLI preview — pass empty `$to` and get empty `{unsubscribe_url}`, which is the correct behavior for previews). The alternative (injecting `unsubscribe_url` into `$context` from `send()` before calling `compose()`) was rejected because `compose()` is also called standalone from admin preview and CLI preview, and those paths would each need to know to inject — worse encapsulation.

`resolve_unsubscribe_url( string $to, Email_Type_Definition $definition ): string`:

```php
if ( $definition->is_transactional_required() || '' === $to || ! is_email( $to ) ) {
    return '';
}
$url = $this->manager->url_for( $to );
return (string) apply_filters( 'leastudios_email_templates_unsubscribe_url', $url, $to, $definition->id() );
}
```

### Settings/AJAX preview + CLI preview impact

- Admin AJAX preview handler passes `$to = ''` to `compose()` → `{unsubscribe_url}` renders empty. (Acceptable — preview is for layout, not for clicking links.)
- CLI `preview` command also passes empty `$to`. Same outcome.
- CLI `send-test` already has `$to` — passes it through, gets a real URL.

---

## `Merge_Tag_Replacer` changes

Single addition in `get_global_escape_modes()`:

```php
return [
    'site_name'       => Escape_Mode::HTML,
    'site_url'        => Escape_Mode::URL,
    'date'            => Escape_Mode::HTML,
    'unsubscribe_url' => Escape_Mode::URL,   // NEW
];
```

No other replacer changes. The tag's value is injected into `$context` by `Email_Sender` (recipient-aware) rather than by the replacer itself, so the replacer stays recipient-ignorant.

---

## Admin surface

### `Suppressions_Page`

Sub-page under the existing top-level "Email Templates" menu, registered alongside `Settings_Page` and `Email_Log_Page`.

- **Capability:** `manage_options`.
- **Menu slug:** `leastudios-email-templates-suppressions`.
- **List table** (`WP_List_Table` subclass — same pattern as `Email_Log_List_Table`):
  - Columns: `email`, `suppressed_at`, `source`.
  - Row action: `Remove`.
  - Bulk action: `Remove selected`.
  - Pagination: 20 per page.
  - Sortable: `email`, `suppressed_at`.
- **Add-suppression form** above the table:
  - `<input type="email" name="email" required>` + `wp_nonce_field( 'leastudios_email_templates_add_suppression' )`.
  - Submits to `admin-post.php?action=leastudios_email_templates_add_suppression`.
  - Handler: `check_admin_referer()` + `current_user_can( 'manage_options' )` + `sanitize_email()` + `is_email()` validation + `$manager->suppress( $email, 'admin' )` + redirect back with a success/failure notice.

---

## WP-CLI subcommands

Three new methods on `src/CLI/Commands.php`. Constructor gains `Unsubscribe_Manager` (4th arg).

### `wp leastudios-email-templates list-suppressions`

`[--format=<table|csv|json|yaml|count|ids>]` (default `table`).

Outputs columns `email`, `suppressed_at`, `source`. Uses `WP_CLI\Utils\format_items()` exactly like Phase 8's `list-types`.

### `wp leastudios-email-templates add-suppression <email> [--source=<source>]`

`--source` defaults to `'cli'`. Validates with `is_email()`, errors via `WP_CLI::error()` on invalid input. Calls `$manager->suppress( $email, $source )`. Success via `WP_CLI::success()` with a message.

### `wp leastudios-email-templates remove-suppression <email>`

Validates with `is_email()`. Calls `$manager->unsuppress( $email )`. Reports whether the row existed (`WP_CLI::success` "Removed" vs `WP_CLI::warning` "Was not suppressed").

---

## `Send_Logger` extension

Two changes to `Send_Logger::init()`:

1. Subscribe to `leastudios_email_templates_email_suppressed` with `accepted_args=6` (no `$result` arg) via a new handler `record_suppressed()`.
2. `record_suppressed()` calls `$repo->create()` with `status='suppressed'`, `error=null`, body/headers populated from the suppressed-but-composed values.

No new public API surface; `Send_Logger::record()` (the existing private writer) stays as-is. A new private `record_suppressed()` is symmetric to the existing handler.

---

## `Subscription_Created` demotion

```php
public function is_transactional_required(): bool {
    return false;
}
```

Plus a one-paragraph class doc comment noting:

> Demoted from required → optional in Phase 9. The welcome / "your subscription is active" email is lifecycle-flavored, not a receipt for a charge (`payment_receipt` covers that on the initial subscription invoice). Suppressed recipients still receive `payment_receipt`, `subscription_renewed`, `refund_processed`, and `payment_failed`.

`tests/BuiltInTypesTest::built_in_provider()` updated to expect `false` for this row.

---

## Test plan

Following Phase 8's pattern (in-process, no real WP-CLI binary, no real HTTP).

### New test files

- **`tests/UnsubscribeManagerTest.php`**
  - Token round-trip: mint then verify returns the same email.
  - Tampered payload (changed email bytes) rejected.
  - Tampered signature rejected.
  - Empty / malformed tokens rejected (no exception thrown).
  - Secret lazy creation: option is empty before first `url_for`, populated after.
  - `suppress`/`is_suppressed`/`unsuppress` round-trip.
  - Email normalization: `MIXED@Case.com` mints and verifies same as `mixed@case.com`.
  - Filter `leastudios_email_templates_unsubscribe_token_secret` short-circuits the option read.

- **`tests/SuppressionRepositoryTest.php`**
  - `install()` is idempotent (call twice, no error, schema-version option set once).
  - `upsert()` re-suppression refreshes `suppressed_at` and `source` but doesn't create a duplicate row.
  - Email normalized to lowercase at insert; lookup with mixed-case finds the row.
  - `paginate()` returns rows in DESC `suppressed_at` order with correct totals.
  - `drop()` clears table and removes the schema-version option.

- **`tests/UnsubscribeControllerTest.php`**
  - Routes register on `rest_api_init`.
  - GET with valid token → 200, HTML content-type, suppression row created.
  - GET with missing/malformed/tampered token → 400, error landing rendered.
  - POST resubscribe with valid token → 200, suppression row removed.
  - POST resubscribe with invalid token → 400.
  - Both responses carry no-cache headers.

### Updated test files

- **`tests/EmailSenderTest.php`**
  - Non-required type send to suppressed recipient: returns `false`, fires `_email_suppressed` with composed body/headers, does NOT call `wp_mail`.
  - Required type send to suppressed recipient: still calls `wp_mail`, returns its result.
  - `{unsubscribe_url}` in body resolves to a real URL for non-required type with real recipient.
  - `{unsubscribe_url}` resolves to empty string for required type.
  - `{unsubscribe_url}` resolves to empty string when `$to` is empty (preview path).
  - Auto-footer appended to body for non-required type only; absent for required types.
  - Filter `leastudios_email_templates_unsubscribe_footer_html` mutates the appended footer.
  - Filter `leastudios_email_templates_unsubscribe_url` mutates the URL inside `compose()`.

- **`tests/MergeTagReplacerTest.php`**
  - `unsubscribe_url` in `get_global_escape_modes()` (assert via behavior: pass a URL with `<script>` in context → output `esc_url`-cleaned).

- **`tests/BuiltInTypesTest.php`**
  - Provider expects `Subscription_Created::is_transactional_required()` to be `false`.
  - Other four built-ins still `true`.

- **`tests/SendLoggerTest.php`**
  - `_email_suppressed` fires → log row written with `status='suppressed'`, body/headers populated, `error=null`.

- **`tests/CLICommandsTest.php`**
  - `build_suppression_rows()` helper formats rows correctly (mirrors `build_type_rows` test pattern).
  - `add_suppression()` validates email; errors on garbage; calls manager on success.
  - `remove_suppression()` returns success when row existed, warning when not.

### Verification gate (closing Phase 9, mirroring Phase 8)

1. `composer phpcs` — clean.
2. `composer phpstan` — level 7 clean.
3. `composer test` — all green.
4. WP-CLI smoke on Herd:
   - `wp leastudios-email-templates add-suppression test@example.com`
   - `wp leastudios-email-templates list-suppressions`
   - Trigger a `subscription_created` send to `test@example.com` (admin Settings → Email Types → Send test) → expect skip + log row with `status='suppressed'`.
   - Trigger a `payment_receipt` send → expect normal delivery (required type bypasses).
   - `wp leastudios-email-templates remove-suppression test@example.com` + retry → expect normal delivery again.
5. Manual REST smoke: visit `https://leastudios-plugins.test/wp-json/leastudios-email-templates/v1/unsubscribe?token=<minted>` → landing renders, row created, resubscribe button works.
6. `bash leastudios-dev-tools/bin/check-shared.sh` — shared classes still in sync.

---

## Risks and migrations

### Risk: behavioral change to shipped emails

Demoting `subscription_created` to non-required means a customer who unsubscribes will no longer receive the "welcome / your subscription is active" email. This is the intended behavior, but it IS a change to shipped functionality.

**Mitigation:** the change only matters for recipients who have actively suppressed. Suppression requires a deliberate click on an unsubscribe link, which doesn't exist in production today (Phase 9 introduces it). So at ship time, the set of affected recipients is empty.

### Risk: REST route returning HTML

WP REST framework conventionally returns JSON. Returning HTML via `WP_REST_Response` + a manual `Content-Type` header is supported, but some site security plugins (e.g., WordFence's "REST API hardening") may interfere.

**Mitigation:** the routes are anonymous-permission and on a plugin-specific namespace. If a third-party hardening plugin blocks them, that's a per-site config issue. Document the route paths in the README so admins can whitelist if needed.

### Risk: bot-prefetch unsubscribes

Some email-security gateways and "preview link" services GET every URL in a mail. With one-click unsubscribe, this could unsubscribe a user who never clicked.

**Mitigation:** accepted industry-wide. RFC 8058 acknowledges this. The resubscribe path is the safety valve. If a customer reports "I never unsubscribed," support can resubscribe via the admin page or WP-CLI in <30 seconds.

### Risk: existing callers of `Email_Sender::compose($type_id, $context)`

Signature change to `compose( string $type_id, array $context = [], string $to = '' )` is additive (default empty). Existing callers continue to work; they get an empty `{unsubscribe_url}` which is the correct behavior for preview contexts.

**Mitigation:** no shim required (per CLAUDE.md, shims are only for shipped behavior; the recipient-aware merge tag is brand new).

### Risk: secret rotation invalidates outstanding links

If an admin deletes the secret option (manually or via a "regenerate" button — not in Phase 9 scope), every previously-sent unsubscribe link breaks.

**Mitigation:** Phase 9 does NOT expose a rotate-secret UI. Rotation requires direct DB/option access. Documented in the README as a "rare hammer" operation. If we add a rotate button later, it should carry a confirmation modal explaining the consequence.

---

## Out of scope (deferred to a later phase)

- Per-type suppression preferences (one row per `email + type_id`). The current schema is forward-compatible — adding a nullable `type_id` column doesn't break global suppressions.
- Admin "regenerate secret" button with confirmation modal.
- RFC 8058 `List-Unsubscribe-Post` header on outgoing emails (would require adding two headers — `List-Unsubscribe` and `List-Unsubscribe-Post: List-Unsubscribe=One-Click` — and a slightly different POST handler. Worth adding in a follow-up; not strictly required for the Phase 9 acceptance criteria).
- Encrypted secret storage via `Options_Encryptor`.
- Unsubscribe-link analytics (click counts, last-clicked timestamp).
- I18n of landing-page templates beyond `__()` strings (e.g., RTL stylesheet support).
