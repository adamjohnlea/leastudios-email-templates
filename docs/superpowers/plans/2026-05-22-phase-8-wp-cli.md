# Phase 8 — WP-CLI Subcommand Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Expose three WP-CLI subcommands — `list-types`, `preview`, `send-test` — under the `wp leastudios-email-templates` namespace. Commands reuse `Email_Type_Registry` and `Email_Sender::compose/send` so there is exactly one composition code path for admin AJAX and CLI. CLI-originated sends are persisted to the email log with a `source='cli-test'` value so support sessions leave a visible trail.

**Architecture:** A new `src/CLI/Commands.php` class is registered with `WP_CLI::add_command( 'leastudios-email-templates', ... )` from `Plugin::init()` when `WP_CLI` is defined. The class is constructor-injected with the registry, sender, and replacer that `Plugin::init()` already builds, so it cannot drift from the admin AJAX surfaces. To persist the new "source" dimension, the log table grows a `source varchar(16) NOT NULL DEFAULT 'web'` column (schema bump 1.0.0 → 1.1.0), `Email_Sender::send()` gains an optional 4th `string $source = 'web'` argument that propagates as the 7th positional arg on the `leastudios_email_templates_email_sent` action, and `Send_Logger::record()` writes the value. The `Email_Log_List_Table` recipient column gets a small `(cli)` suffix when `source !== 'web'` so the trail is visible in the admin log without a new column or filter.

**Tech Stack:** PHP 8.1+, WP-CLI's `\WP_CLI` class and `\WP_CLI\Utils\format_items()` helper, PHPUnit 9.6, PHPStan level 7, dbDelta migration. No new Composer dependencies.

---

## File Structure

| File | Action | Responsibility |
|---|---|---|
| `src/CLI/Commands.php` | Create | WP-CLI command class — `list_types()`, `preview()`, `send_test()`. Thin dispatch over `Email_Type_Registry` + `Email_Sender`. |
| `src/Database/Email_Log_Repository.php` | Modify | Add `source` column to CREATE TABLE, bump `SCHEMA_VERSION` to `1.1.0`, include `source` in `create()` data + column format array. |
| `src/Database/Email_Log_Entry.php` | Modify | Add `public readonly string $source` constructor property, default `'web'`; populate via `from_row()`. |
| `src/Email/Email_Sender.php` | Modify | `send()` gains `string $source = 'web'` as 4th parameter; pass as 7th positional arg to the `leastudios_email_templates_email_sent` action. `compose()` is unchanged. |
| `src/Log/Send_Logger.php` | Modify | `record()` gains `string $source = 'web'` as 7th parameter; forward to `$this->repo->create()`. Bump `add_action` arg count to 7. |
| `src/Admin/Email_Log_List_Table.php` | Modify | Override `column_recipient()` to append `<span class="leastudios-source-badge">(cli)</span>` when `source !== 'web'`. |
| `src/Plugin.php` | Modify | After the sender is built, if `defined('WP_CLI') && WP_CLI`, instantiate and register `Commands`. |
| `tests/EmailLogRepositoryTest.php` | Modify | Add a test that `create()` persists `source` and that reading the row back surfaces it on the `Email_Log_Entry`. |
| `tests/EmailSenderTest.php` | Modify | Add tests asserting (a) `send()` defaults to `source='web'` and propagates it as the 7th action arg; (b) explicit `source='cli-test'` propagates. |
| `tests/SendLoggerTest.php` | Modify | Add a test that `record()` persists the source value on the log row. |
| `tests/EmailLogListTableTest.php` | Create | One test — recipient column shows `(cli)` suffix when source is non-default. |
| `tests/CLICommandsTest.php` | Create | Direct unit tests against `Commands` — `build_type_rows()`, `render_preview()`, `dispatch_send_test()`. Pure-data helpers extracted from each command so we don't need a WP_CLI mock. |
| `tests/bootstrap.php` | Modify | Define a minimal `WP_CLI` stub class (records calls to `log`, `success`, `error`, `warning`) if the real class is not present. Lets CLI Commands run under PHPUnit without WP-CLI being installed. |

---

## Background-context cheatsheet (for the implementer)

- **Subcommand naming.** WP-CLI maps method names to subcommands by converting `snake_case` → `kebab-case`. `list_types()` becomes `wp leastudios-email-templates list-types`, `send_test()` becomes `... send-test`. Use underscores in the method names.
- **PHPDoc is the help text.** WP-CLI parses `/** */` blocks above each method to produce `wp <cmd> --help` output. Use the standard sections: short description, blank line, `## OPTIONS`, `## EXAMPLES`. Keep these accurate — they are user-facing.
- **`WP_CLI\Utils\format_items()` is the right primitive.** It accepts `$format`, `array $items`, `array $fields` and emits `table`, `csv`, `json`, `yaml`, `count`, `ids`. The four values `table|csv|json|yaml` cover every reasonable scripting need; `count` and `ids` come for free.
- **One composition code path.** `send-test` and `preview` MUST call `Email_Sender::compose()` / `send()`. Do not call `Merge_Tag_Replacer` or `Template_Wrapper` directly from `Commands`. This is the project-CLAUDE.md "one code path per action" rule made concrete.
- **Schema migration safety.** `Email_Log_Repository::install()` short-circuits when `SCHEMA_OPTION === SCHEMA_VERSION`. Bumping `SCHEMA_VERSION` from `1.0.0` to `1.1.0` re-runs `dbDelta()`. dbDelta will `ALTER TABLE` to add the new column on existing installs. Existing rows get the column default `'web'`. There is no data-rewrite required.
- **Why the source lives as a column, not a header.** A column is queryable (`WHERE source = 'cli-test'`) and renderable in the admin list table without parsing the `headers` blob string. The user's stated goal — "support sessions leave a trail" — implies a visible, deterministic signal in the log UI, not a needle hidden in a longtext column.
- **Action-arg expansion is non-breaking.** Existing `add_action('leastudios_email_templates_email_sent', ..., $priority, $accepted_args=6)` handlers continue to work — they simply don't receive the 7th positional argument. Send_Logger updates its registration to `accepted_args=7` so it gets the source.
- **`send()` signature placement of `$source`.** Place it as the 4th positional parameter (after `$type_id`, `$to`, `$context = []`). All current callers pass 3 args or fewer, so defaulting `$source = 'web'` is a safe additive change. Avoid making it part of `$context` — context is merge-tag data and conflating delivery metadata with that would be a category error.
- **`Settings_Page::handle_send_test` is unchanged.** It already constructs `new Email_Sender(new Merge_Tag_Replacer(), $this->registry)` (line 591) and calls `$sender->send($definition->id(), $to, $sample_context)`. The default `$source='web'` keeps the existing admin behaviour.
- **`compose()` is the right primitive for `--dry-run`.** Composition does not depend on recipient, log, or source. `wp ... send-test --dry-run foo bar@x.test` resolves the type, composes once via `compose()`, prints the wp_mail args, and returns without touching `send()` (no log row, no dispatch).
- **PHPUnit + WP_CLI.** WP-CLI is not installed as a Composer dep. The test bootstrap defines a minimal `WP_CLI` class stub so any `Commands` method that calls `WP_CLI::log()`, `::success()`, `::warning()` does not fatal under PHPUnit. The stub records calls into a static array tests can inspect. The class-existence guard (`class_exists('WP_CLI')`) gates the stub so the real WP-CLI binary takes precedence when the suite runs under it.
- **No `--format` for `preview`.** Preview output is always the wrapped HTML body — the format is dictated by what an email *is*. (`--subject` to print only the subject is a separate code path, not a format option.)
- **No confirmation prompt on `send-test`.** Per the design discussion, the email address is an explicit positional argument; CLI ergonomics favour "do what I asked." `--dry-run` is the safety valve.
- **`source` validation.** The CLI sends `'cli-test'`. Admin AJAX defaults to `'web'`. Don't expose `$source` as an open-ended user parameter — it's set by the surface, not the user. PHPStan-typed as `string`, no enum; we expect a small handful of distinct values over the lifetime of the plugin and the value's main job is grep-ability in the log table.

---

## Acceptance criteria (verify before claiming complete)

1. `wp leastudios-email-templates list-types` prints a table with one row per registered type, columns `id | label | transactional_required | source` (where `source` is `built-in` for the five built-ins and `third-party` for anything registered via `leastudios_email_templates_register_types`). `--format=json` returns parseable JSON with the same fields.
2. `wp leastudios-email-templates preview payment_receipt` prints the rendered HTML (subject is not in the body — it lives in the `Subject:` header line at the top of stdout). `wp ... preview payment_receipt --subject` prints only the subject. `wp ... preview payment_receipt --context='{"customer_name":"Ada"}'` substitutes the override into the rendered output.
3. `wp leastudios-email-templates send-test payment_receipt support@example.test` dispatches a real `wp_mail` and creates one row in `wp_leastudios_email_templates_log` with `source='cli-test'`.
4. `wp leastudios-email-templates send-test payment_receipt support@example.test --dry-run` prints the composed wp_mail args and does **not** create a log row.
5. The admin Email Log list table shows `(cli)` next to the recipient address for every row where `source !== 'web'`.
6. `composer phpcs` clean.
7. `composer phpstan` clean at level 7.
8. `composer test` reports 150+ tests, 0 failures.
9. `wp leastudios-email-templates --help` and `wp leastudios-email-templates <sub> --help` show accurate usage derived from the PHPDoc.

---

## Task 1: Add `source` column to the email log table

**Files:**
- Modify: `src/Database/Email_Log_Repository.php`
- Modify: `src/Database/Email_Log_Entry.php`
- Modify: `tests/EmailLogRepositoryTest.php`

- [ ] **Step 1: Write the failing test**

Append to `tests/EmailLogRepositoryTest.php`:

```php
public function test_create_persists_source_column(): void {
    $id = $this->repo->create(
        [
            'type'      => 'payment_receipt',
            'recipient' => 'a@example.test',
            'subject'   => 'Hi',
            'body'      => '<p>Hi</p>',
            'headers'   => 'Content-Type: text/html',
            'status'    => 'sent',
            'error'     => null,
            'source'    => 'cli-test',
        ]
    );

    $entry = $this->repo->get( $id );
    $this->assertNotNull( $entry );
    $this->assertSame( 'cli-test', $entry->source );
}

public function test_create_defaults_source_to_web_when_omitted(): void {
    $id = $this->repo->create(
        [
            'type'      => 'payment_receipt',
            'recipient' => 'a@example.test',
            'subject'   => 'Hi',
            'body'      => '<p>Hi</p>',
            'headers'   => '',
            'status'    => 'sent',
            'error'     => null,
        ]
    );

    $entry = $this->repo->get( $id );
    $this->assertNotNull( $entry );
    $this->assertSame( 'web', $entry->source );
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter 'test_create_persists_source_column|test_create_defaults_source_to_web_when_omitted' -v`
Expected: FAIL — `$entry->source` is undefined.

- [ ] **Step 3: Add the `source` property to `Email_Log_Entry`**

Edit `src/Database/Email_Log_Entry.php`. Update the constructor and `from_row()`:

```php
public function __construct(
    public readonly int $id,
    public readonly string $type,
    public readonly string $recipient,
    public readonly string $subject,
    public readonly string $body,
    public readonly string $headers,
    public readonly string $status,
    public readonly string $error,
    public readonly string $created_at,
    public readonly string $source = 'web',
) {}

public static function from_row( object $row ): self {
    return new self(
        (int) ( $row->id ?? 0 ),
        (string) ( $row->type ?? '' ),
        (string) ( $row->recipient ?? '' ),
        (string) ( $row->subject ?? '' ),
        (string) ( $row->body ?? '' ),
        (string) ( $row->headers ?? '' ),
        (string) ( $row->status ?? '' ),
        (string) ( $row->error ?? '' ),
        (string) ( $row->created_at ?? '' ),
        (string) ( $row->source ?? 'web' ),
    );
}
```

Update the constructor PHPDoc to add `@param string $source Send-origin marker: 'web' (default) or 'cli-test'.`

- [ ] **Step 4: Update `Email_Log_Repository`**

Edit `src/Database/Email_Log_Repository.php`.

Bump the schema version constant:

```php
private const SCHEMA_VERSION = '1.1.0';
```

In `install()`, add `source` to the CREATE TABLE statement (insert between `error` and `created_at`):

```php
$sql = "CREATE TABLE {$table} (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    type varchar(64) NOT NULL DEFAULT '',
    recipient varchar(255) NOT NULL DEFAULT '',
    subject text NOT NULL,
    body longtext NOT NULL,
    headers longtext NOT NULL,
    status varchar(16) NOT NULL DEFAULT 'sent',
    error text NULL,
    source varchar(16) NOT NULL DEFAULT 'web',
    created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY  (id),
    KEY type_idx (type),
    KEY status_idx (status),
    KEY source_idx (source),
    KEY created_at_idx (created_at)
) {$charset_collate};";
```

Update the `create()` method to include `source`. Update its PHPDoc:

```php
/**
 * Insert one row.
 *
 * @param array{type:string,recipient:string,subject:string,body:string,headers:string,status:string,error:?string,source?:string} $data Row data.
 * @return int Inserted ID, or 0 on failure.
 */
public function create( array $data ): int {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $ok = $wpdb->insert(
        $this->table_name(),
        [
            'type'       => $data['type'],
            'recipient'  => $data['recipient'],
            'subject'    => $data['subject'],
            'body'       => $data['body'],
            'headers'    => $data['headers'],
            'status'     => $data['status'],
            'error'      => $data['error'],
            'source'     => $data['source'] ?? 'web',
            'created_at' => current_time( 'mysql' ),
        ],
        [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
    );

    return false === $ok ? 0 : (int) $wpdb->insert_id;
}
```

- [ ] **Step 5: Re-run install to apply the migration in the test database**

The test bootstrap calls `install()` once. Inside the test `set_up()` for `EmailLogRepositoryTest`, the repo is freshly instantiated each test; the cached `SCHEMA_OPTION` will be `1.0.0` for an existing test DB, so `install()` will detect a version mismatch and re-run dbDelta. No manual SQL.

Run: `vendor/bin/phpunit tests/EmailLogRepositoryTest.php -v`
Expected: All tests in that file PASS (including the two new ones).

- [ ] **Step 6: Commit**

```bash
git add src/Database/Email_Log_Repository.php src/Database/Email_Log_Entry.php tests/EmailLogRepositoryTest.php
git commit -m "Add source column to email_log table (schema 1.1.0)"
```

---

## Task 2: Propagate `$source` through `Email_Sender::send()`

**Files:**
- Modify: `src/Email/Email_Sender.php`
- Modify: `tests/EmailSenderTest.php`

- [ ] **Step 1: Write the failing test**

Append to `tests/EmailSenderTest.php`:

```php
public function test_send_defaults_source_to_web_in_email_sent_action(): void {
    $captured = null;
    add_action(
        'leastudios_email_templates_email_sent',
        static function ( $type_id, $to, $subject, $result, $body, $headers, $source ) use ( &$captured ): void {
            $captured = $source;
        },
        10,
        7
    );

    $this->sender->send( 'payment_receipt', 'buyer@example.test', $this->sample_context() );

    $this->assertSame( 'web', $captured );
}

public function test_send_propagates_explicit_source_to_email_sent_action(): void {
    $captured = null;
    add_action(
        'leastudios_email_templates_email_sent',
        static function ( $type_id, $to, $subject, $result, $body, $headers, $source ) use ( &$captured ): void {
            $captured = $source;
        },
        10,
        7
    );

    $this->sender->send( 'payment_receipt', 'buyer@example.test', $this->sample_context(), 'cli-test' );

    $this->assertSame( 'cli-test', $captured );
}
```

`sample_context()` is whatever helper the existing test file uses. If there is no helper, inline the sample array. Check `tests/EmailSenderTest.php` first for the convention before adding a helper.

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter 'test_send_defaults_source_to_web|test_send_propagates_explicit_source' -v`
Expected: FAIL — `Undefined argument $source` or `$captured === null` because `send()` does not yet forward it.

- [ ] **Step 3: Update `Email_Sender::send()`**

Edit `src/Email/Email_Sender.php`. Update the method signature and the `do_action` call:

```php
/**
 * Send an email of the specified type.
 *
 * @param string               $type_id The registered email type id.
 * @param string               $to      Recipient address.
 * @param array<string, mixed> $context Merge-tag values.
 * @param string               $source  Send-origin marker for the log table.
 *                                      `'web'` (default) for admin AJAX sends,
 *                                      `'cli-test'` for `wp leastudios-email-templates send-test`.
 * @return bool Whether wp_mail returned true. Returns false if the id is
 *              unknown or the type is disabled.
 */
public function send( string $type_id, string $to, array $context = [], string $source = 'web' ): bool {
    $definition = $this->registry->get( $type_id );

    if ( null === $definition ) {
        return false;
    }

    $composed = $this->compose( $type_id, $context );

    if ( null === $composed ) {
        return false;
    }

    $settings = $this->get_type_settings( $type_id );

    if ( ! empty( $settings['recipient_override'] ) && is_email( $settings['recipient_override'] ) ) {
        $to = $settings['recipient_override'];
    }

    /**
     * Filters the email arguments before sending.
     *
     * @param array<string, mixed> $args    The wp_mail arguments.
     * @param string               $type_id The registered type id.
     * @param array<string, mixed> $context The merge tag context.
     */
    $args = (array) apply_filters(
        'leastudios_email_templates_send_args',
        [
            'to'      => $to,
            'subject' => $composed['subject'],
            'message' => $composed['body'],
            'headers' => $composed['headers'],
        ],
        $type_id,
        $context
    );

    $result = wp_mail( $args['to'], $args['subject'], $args['message'], $args['headers'] );

    /**
     * Fires after a transactional email is sent.
     *
     * @param string             $type_id The registered type id.
     * @param string             $to      The recipient.
     * @param string             $subject The subject line.
     * @param bool               $result  Whether wp_mail returned true.
     * @param string             $body    The rendered body that was passed to wp_mail.
     * @param array<int, string> $headers The headers passed to wp_mail.
     * @param string             $source  Send-origin marker: 'web' or 'cli-test'.
     */
    do_action(
        'leastudios_email_templates_email_sent',
        $type_id,
        $args['to'],
        $args['subject'],
        $result,
        (string) $args['message'],
        (array) $args['headers'],
        $source
    );

    return $result;
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/EmailSenderTest.php -v`
Expected: All tests in that file PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Email/Email_Sender.php tests/EmailSenderTest.php
git commit -m "Propagate source through Email_Sender::send and the _email_sent action"
```

---

## Task 3: Persist `source` from `Send_Logger`

**Files:**
- Modify: `src/Log/Send_Logger.php`
- Modify: `tests/SendLoggerTest.php`

- [ ] **Step 1: Write the failing test**

Append to `tests/SendLoggerTest.php`:

```php
public function test_record_persists_source_to_log_row(): void {
    $this->logger->record(
        'payment_receipt',
        'buyer@example.com',
        'Receipt',
        true,
        '<p>Body</p>',
        [ 'Content-Type: text/html' ],
        'cli-test'
    );

    $page = $this->repo->paginate( [], 10, 1 );
    $this->assertCount( 1, $page['rows'] );
    $this->assertSame( 'cli-test', $page['rows'][0]->source );
}

public function test_record_defaults_source_to_web(): void {
    $this->logger->record(
        'payment_receipt',
        'buyer@example.com',
        'Receipt',
        true,
        '<p>Body</p>',
        [ 'Content-Type: text/html' ]
    );

    $page = $this->repo->paginate( [], 10, 1 );
    $this->assertSame( 'web', $page['rows'][0]->source );
}

public function test_init_registers_subscriber_with_seven_accepted_args(): void {
    $this->logger->init();

    global $wp_filter;
    $callbacks = $wp_filter['leastudios_email_templates_email_sent']->callbacks[10] ?? [];
    $this->assertNotEmpty( $callbacks );

    $first = array_values( $callbacks )[0];
    $this->assertSame( 7, $first['accepted_args'] );
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/SendLoggerTest.php -v`
Expected: FAIL — `record()` doesn't accept a 7th arg, and `accepted_args` is 6.

- [ ] **Step 3: Update `Send_Logger`**

Edit `src/Log/Send_Logger.php`:

```php
public function init(): void {
    add_action( 'leastudios_email_templates_email_sent', [ $this, 'record' ], 10, 7 );
}

/**
 * Record a single send.
 *
 * @param string             $type_id The registered email type id.
 * @param string             $to      The recipient.
 * @param string             $subject The subject line.
 * @param bool               $result  Whether wp_mail returned true.
 * @param string             $body    The rendered body that was sent.
 * @param array<int, string> $headers Headers that were sent.
 * @param string             $source  Send-origin marker: 'web' or 'cli-test'.
 * @return void
 */
public function record( string $type_id, string $to, string $subject, bool $result, string $body = '', array $headers = [], string $source = 'web' ): void {
    $this->repo->create(
        [
            'type'      => $type_id,
            'recipient' => $to,
            'subject'   => $subject,
            'body'      => $body,
            'headers'   => implode( "\n", $headers ),
            'status'    => $result ? 'sent' : 'failed',
            'error'     => null,
            'source'    => $source,
        ]
    );
}
```

- [ ] **Step 4: Run all logger tests**

Run: `vendor/bin/phpunit tests/SendLoggerTest.php -v`
Expected: All PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Log/Send_Logger.php tests/SendLoggerTest.php
git commit -m "Record send source in the email log"
```

---

## Task 4: Add a WP_CLI stub to the test bootstrap

**Files:**
- Modify: `tests/bootstrap.php`

- [ ] **Step 1: Add a `WP_CLI` stub class definition**

Append to `tests/bootstrap.php`, after the `Email_Log_Repository` install line:

```php
// Minimal WP_CLI stub for tests that exercise the CLI Commands class.
// Records calls into static arrays so tests can assert on output without
// requiring the real WP-CLI binary to be present at test time.
if ( ! class_exists( 'WP_CLI' ) ) {
    eval( <<<'PHP'
        class WP_CLI {
            public static array $log_calls = [];
            public static array $success_calls = [];
            public static array $warning_calls = [];
            public static array $error_calls = [];
            public static ?string $last_error = null;

            public static function log( string $message ): void {
                self::$log_calls[] = $message;
            }
            public static function success( string $message ): void {
                self::$success_calls[] = $message;
            }
            public static function warning( string $message ): void {
                self::$warning_calls[] = $message;
            }
            public static function error( string $message, bool $exit = true ): void {
                self::$error_calls[] = $message;
                self::$last_error = $message;
                throw new \RuntimeException( 'WP_CLI::error: ' . $message );
            }
            public static function add_command( string $name, $callable ): void {
                // No-op for tests.
            }
            public static function reset(): void {
                self::$log_calls = [];
                self::$success_calls = [];
                self::$warning_calls = [];
                self::$error_calls = [];
                self::$last_error = null;
            }
        }
PHP
    );
}

// Minimal WP_CLI\Utils\format_items stub. The real implementation lives in
// the wp-cli/wp-cli package which is not a Composer dep here. We mirror the
// public signature: format_items(string $format, array $items, array|string $fields)
// and emit one of table|csv|json|yaml. Tests assert on the printed output.
if ( ! function_exists( 'WP_CLI\\Utils\\format_items' ) ) {
    eval( <<<'PHP'
        namespace WP_CLI\Utils;
        function format_items( string $format, array $items, $fields ): void {
            $field_list = is_array( $fields ) ? $fields : array_map( 'trim', explode( ',', (string) $fields ) );
            if ( 'json' === $format ) {
                \WP_CLI::log( (string) wp_json_encode( $items ) );
                return;
            }
            if ( 'csv' === $format ) {
                \WP_CLI::log( implode( ',', $field_list ) );
                foreach ( $items as $item ) {
                    $row = [];
                    foreach ( $field_list as $f ) {
                        $row[] = (string) ( $item[ $f ] ?? '' );
                    }
                    \WP_CLI::log( implode( ',', $row ) );
                }
                return;
            }
            // Default to a plain "field: value" line per row for table-like output.
            foreach ( $items as $item ) {
                $parts = [];
                foreach ( $field_list as $f ) {
                    $parts[] = $f . '=' . ( $item[ $f ] ?? '' );
                }
                \WP_CLI::log( implode( ' ', $parts ) );
            }
        }
PHP
    );
}
```

The `eval` is used so the bootstrap remains a single file and the stubs only declare classes/functions inside the `! class_exists` and `! function_exists` guards. If WP-CLI itself is loaded (e.g. by running the suite under `wp eval-file`), these stubs are skipped.

- [ ] **Step 2: Verify the suite still bootstraps**

Run: `vendor/bin/phpunit --filter EmailLogRepositoryTest -v`
Expected: All PASS — the stubs don't disturb existing tests.

- [ ] **Step 3: Commit**

```bash
git add tests/bootstrap.php
git commit -m "Stub WP_CLI for tests so CLI Commands can be unit-tested"
```

---

## Task 5: Create the `Commands` class skeleton with `list-types`

**Files:**
- Create: `src/CLI/Commands.php`
- Create: `tests/CLICommandsTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/CLICommandsTest.php`:

```php
<?php
/**
 * Tests for the WP-CLI Commands class.
 *
 * @package LEAStudios\EmailTemplates\Tests
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Tests;

use LEAStudios\EmailTemplates\CLI\Commands;
use LEAStudios\EmailTemplates\Email\Built_In\Payment_Receipt;
use LEAStudios\EmailTemplates\Email\Built_In\Subscription_Created;
use LEAStudios\EmailTemplates\Email\Email_Sender;
use LEAStudios\EmailTemplates\Email\Email_Type_Registry;
use LEAStudios\EmailTemplates\Email\Merge_Tag_Replacer;
use LEAStudios\Tests\TestCase;

/**
 * @covers \LEAStudios\EmailTemplates\CLI\Commands
 */
class CLICommandsTest extends TestCase {

    private Email_Type_Registry $registry;
    private Email_Sender $sender;
    private Merge_Tag_Replacer $replacer;
    private Commands $commands;

    public function set_up(): void {
        parent::set_up();
        \WP_CLI::reset();
        $this->registry = new Email_Type_Registry();
        $this->registry->register( new Payment_Receipt() );
        $this->registry->register( new Subscription_Created() );
        $this->replacer = new Merge_Tag_Replacer();
        $this->sender   = new Email_Sender( $this->replacer, $this->registry );
        $this->commands = new Commands( $this->registry, $this->sender, $this->replacer );
    }

    public function test_build_type_rows_returns_one_row_per_registered_type(): void {
        $rows = $this->commands->build_type_rows();

        $this->assertCount( 2, $rows );

        $ids = array_column( $rows, 'id' );
        $this->assertContains( 'payment_receipt', $ids );
        $this->assertContains( 'subscription_created', $ids );
    }

    public function test_build_type_rows_emits_the_required_columns(): void {
        $rows = $this->commands->build_type_rows();

        $this->assertArrayHasKey( 'id', $rows[0] );
        $this->assertArrayHasKey( 'label', $rows[0] );
        $this->assertArrayHasKey( 'transactional_required', $rows[0] );
        $this->assertArrayHasKey( 'source', $rows[0] );
    }

    public function test_build_type_rows_marks_built_in_definitions_as_built_in(): void {
        $rows = $this->commands->build_type_rows();

        foreach ( $rows as $row ) {
            $this->assertSame( 'built-in', $row['source'] );
        }
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/CLICommandsTest.php -v`
Expected: FAIL — `LEAStudios\EmailTemplates\CLI\Commands` does not exist.

- [ ] **Step 3: Create the `Commands` class with `list-types` support**

Create `src/CLI/Commands.php`:

```php
<?php
/**
 * WP-CLI commands for leastudios-email-templates.
 *
 * @package LEAStudios\EmailTemplates\CLI
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\CLI;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use LEAStudios\EmailTemplates\Email\Email_Sender;
use LEAStudios\EmailTemplates\Email\Email_Type_Registry;
use LEAStudios\EmailTemplates\Email\Merge_Tag_Replacer;
use LEAStudios\EmailTemplates\Email\Template_Wrapper;

/**
 * Manage and inspect leastudios-email-templates from the command line.
 */
class Commands {

    /**
     * Built-in definition fully-qualified class names. Anything not in this
     * list is reported as a third-party registration by `list-types`.
     *
     * @var array<int, class-string>
     */
    private const BUILT_IN_CLASSES = [
        \LEAStudios\EmailTemplates\Email\Built_In\Payment_Receipt::class,
        \LEAStudios\EmailTemplates\Email\Built_In\Subscription_Created::class,
        \LEAStudios\EmailTemplates\Email\Built_In\Subscription_Renewed::class,
        \LEAStudios\EmailTemplates\Email\Built_In\Payment_Failed::class,
        \LEAStudios\EmailTemplates\Email\Built_In\Refund_Processed::class,
    ];

    /**
     * Constructor.
     *
     * @param Email_Type_Registry $registry Type registry.
     * @param Email_Sender        $sender   Email sender.
     * @param Merge_Tag_Replacer  $replacer Merge-tag replacer (used by preview).
     */
    public function __construct(
        private readonly Email_Type_Registry $registry,
        private readonly Email_Sender $sender,
        private readonly Merge_Tag_Replacer $replacer,
    ) {}

    /**
     * List all registered transactional email types.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Render output in a particular format.
     * ---
     * default: table
     * options:
     *   - table
     *   - csv
     *   - json
     *   - yaml
     *   - count
     *   - ids
     * ---
     *
     * ## EXAMPLES
     *
     *     wp leastudios-email-templates list-types
     *     wp leastudios-email-templates list-types --format=json
     *
     * @param array<int, string>    $args       Positional arguments.
     * @param array<string, string> $assoc_args Associative arguments.
     * @return void
     */
    public function list_types( array $args, array $assoc_args ): void {
        $format = $assoc_args['format'] ?? 'table';
        $rows   = $this->build_type_rows();

        \WP_CLI\Utils\format_items(
            $format,
            $rows,
            [ 'id', 'label', 'transactional_required', 'source' ]
        );
    }

    /**
     * Return one row per registered type — used by `list-types` and by tests.
     *
     * Extracted as a public method so the data shape can be asserted without
     * mocking WP_CLI output.
     *
     * @return array<int, array{id:string,label:string,transactional_required:string,source:string}>
     */
    public function build_type_rows(): array {
        $rows = [];

        foreach ( $this->registry->all() as $id => $definition ) {
            $rows[] = [
                'id'                     => $id,
                'label'                  => $definition->label(),
                'transactional_required' => $definition->is_transactional_required() ? 'yes' : 'no',
                'source'                 => in_array( get_class( $definition ), self::BUILT_IN_CLASSES, true ) ? 'built-in' : 'third-party',
            ];
        }

        return $rows;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/CLICommandsTest.php -v`
Expected: All three PASS.

- [ ] **Step 5: Run lint**

Run: `composer phpcs src/CLI/ tests/CLICommandsTest.php`
Expected: clean.

Run: `composer phpstan`
Expected: `[OK] No errors`.

- [ ] **Step 6: Commit**

```bash
git add src/CLI/Commands.php tests/CLICommandsTest.php
git commit -m "Add CLI Commands skeleton with list-types subcommand"
```

---

## Task 6: Add the `preview` subcommand

**Files:**
- Modify: `src/CLI/Commands.php`
- Modify: `tests/CLICommandsTest.php`

- [ ] **Step 1: Write the failing test**

Append to `tests/CLICommandsTest.php`:

```php
public function test_render_preview_returns_subject_and_wrapped_html(): void {
    $output = $this->commands->render_preview( 'payment_receipt', null, false );

    $this->assertArrayHasKey( 'subject', $output );
    $this->assertArrayHasKey( 'body', $output );
    $this->assertNotSame( '', $output['subject'] );
    $this->assertStringContainsString( '<', $output['body'] );
}

public function test_render_preview_applies_context_override(): void {
    $output = $this->commands->render_preview(
        'payment_receipt',
        [ 'customer_name' => 'Ada Lovelace' ],
        false
    );

    $this->assertStringContainsString( 'Ada Lovelace', $output['body'] );
}

public function test_render_preview_with_subject_only_returns_empty_body(): void {
    $output = $this->commands->render_preview( 'payment_receipt', null, true );

    $this->assertNotSame( '', $output['subject'] );
    $this->assertSame( '', $output['body'] );
}

public function test_render_preview_throws_on_unknown_type(): void {
    $this->expectException( \RuntimeException::class );
    $this->expectExceptionMessageMatches( '/Unknown email type/' );

    $this->commands->render_preview( 'nope_does_not_exist', null, false );
}
```

The unknown-type test relies on the WP_CLI stub from Task 4, which throws `RuntimeException` from `WP_CLI::error()`.

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/CLICommandsTest.php --filter render_preview -v`
Expected: FAIL — method does not exist.

- [ ] **Step 3: Implement `preview()` and `render_preview()`**

Append to `src/CLI/Commands.php` (inside the class):

```php
/**
 * Render a preview of the given email type to stdout.
 *
 * Subject is printed first as `Subject: <...>`, followed by a blank line and
 * the wrapped HTML body — pipeable to a file. Use `--subject` to print only
 * the subject (useful for subject merge-tag testing).
 *
 * ## OPTIONS
 *
 * <type>
 * : The registered email type id (e.g. `payment_receipt`). Run
 * : `wp leastudios-email-templates list-types` to see all ids.
 *
 * [--context=<json>]
 * : JSON-encoded merge-tag context overrides. Keys are unbraced tag names.
 *
 * [--subject]
 * : Print only the rendered subject.
 *
 * ## EXAMPLES
 *
 *     wp leastudios-email-templates preview payment_receipt
 *     wp leastudios-email-templates preview payment_receipt --subject
 *     wp leastudios-email-templates preview payment_receipt --context='{"customer_name":"Ada"}' > out.html
 *
 * @param array<int, string>    $args       Positional arguments: [0] => type id.
 * @param array<string, string> $assoc_args Associative arguments.
 * @return void
 */
public function preview( array $args, array $assoc_args ): void {
    $type_id        = (string) ( $args[0] ?? '' );
    $context_json   = $assoc_args['context'] ?? null;
    $subject_only   = isset( $assoc_args['subject'] );
    $context_override = null;

    if ( null !== $context_json ) {
        $decoded = json_decode( (string) $context_json, true );
        if ( ! is_array( $decoded ) ) {
            \WP_CLI::error( '--context must be a JSON object.' );
            return;
        }
        $context_override = $decoded;
    }

    $output = $this->render_preview( $type_id, $context_override, $subject_only );

    \WP_CLI::log( 'Subject: ' . $output['subject'] );

    if ( ! $subject_only ) {
        \WP_CLI::log( '' );
        \WP_CLI::log( $output['body'] );
    }
}

/**
 * Compose the preview output for the given type. Pure-data helper that lets
 * tests assert the rendered output without mocking the WP_CLI logger.
 *
 * @param string                    $type_id          The registered email type id.
 * @param array<string, string>|null $context_override Optional context overrides; merged over the definition's sample_context.
 * @param bool                      $subject_only     When true, the returned body is the empty string.
 * @return array{subject:string, body:string}
 */
public function render_preview( string $type_id, ?array $context_override, bool $subject_only ): array {
    $definition = $this->registry->get( $type_id );

    if ( null === $definition ) {
        \WP_CLI::error( sprintf( 'Unknown email type: %s', $type_id ) );
        // WP_CLI::error throws in tests via the stub; in production it exits.
        return [ 'subject' => '', 'body' => '' ];
    }

    $context = array_merge( $definition->sample_context(), $context_override ?? [] );

    $composed = $this->sender->compose( $type_id, $context );

    if ( null === $composed ) {
        \WP_CLI::error( sprintf( 'Email type "%s" is disabled in settings.', $type_id ) );
        return [ 'subject' => '', 'body' => '' ];
    }

    $body = '';

    if ( ! $subject_only ) {
        $wrapper = new Template_Wrapper( $this->replacer );
        $body    = $wrapper->wrap( $composed['body'] );
    }

    return [
        'subject' => $composed['subject'],
        'body'    => $body,
    ];
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/CLICommandsTest.php -v`
Expected: All PASS.

- [ ] **Step 5: Run lint and PHPStan**

Run: `composer phpcs && composer phpstan`
Expected: both clean.

- [ ] **Step 6: Commit**

```bash
git add src/CLI/Commands.php tests/CLICommandsTest.php
git commit -m "Add CLI preview subcommand"
```

---

## Task 7: Add the `send-test` subcommand

**Files:**
- Modify: `src/CLI/Commands.php`
- Modify: `tests/CLICommandsTest.php`

- [ ] **Step 1: Write the failing test**

Append to `tests/CLICommandsTest.php`:

```php
public function test_dispatch_send_test_real_send_creates_log_row_with_cli_test_source(): void {
    // Wire the real log subscriber so we can assert the row.
    $repo   = new \LEAStudios\EmailTemplates\Database\Email_Log_Repository();
    $repo->install();
    $repo->delete_all();
    ( new \LEAStudios\EmailTemplates\Log\Send_Logger( $repo ) )->init();

    $result = $this->commands->dispatch_send_test( 'payment_receipt', 'support@example.test', false );

    $this->assertTrue( $result['sent'] );
    $page = $repo->paginate( [], 10, 1 );
    $this->assertCount( 1, $page['rows'] );
    $this->assertSame( 'cli-test', $page['rows'][0]->source );
}

public function test_dispatch_send_test_dry_run_does_not_log(): void {
    $repo = new \LEAStudios\EmailTemplates\Database\Email_Log_Repository();
    $repo->install();
    $repo->delete_all();
    ( new \LEAStudios\EmailTemplates\Log\Send_Logger( $repo ) )->init();

    $result = $this->commands->dispatch_send_test( 'payment_receipt', 'support@example.test', true );

    $this->assertFalse( $result['sent'] );
    $this->assertArrayHasKey( 'subject', $result );
    $this->assertArrayHasKey( 'body', $result );

    $page = $repo->paginate( [], 10, 1 );
    $this->assertCount( 0, $page['rows'] );
}

public function test_dispatch_send_test_rejects_invalid_email(): void {
    $this->expectException( \RuntimeException::class );
    $this->expectExceptionMessageMatches( '/not a valid email/i' );

    $this->commands->dispatch_send_test( 'payment_receipt', 'not-an-email', false );
}

public function test_dispatch_send_test_rejects_unknown_type(): void {
    $this->expectException( \RuntimeException::class );
    $this->expectExceptionMessageMatches( '/Unknown email type/' );

    $this->commands->dispatch_send_test( 'nope_does_not_exist', 'support@example.test', false );
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/CLICommandsTest.php --filter dispatch_send_test -v`
Expected: FAIL — method does not exist.

- [ ] **Step 3: Implement `send_test()` and `dispatch_send_test()`**

Append to `src/CLI/Commands.php`:

```php
/**
 * Send a real sample email of the given type to the supplied address.
 *
 * The send goes through the same `Email_Sender::send()` path as the admin
 * Email Types tab's "Send test" button — wrapper, plain-text injection,
 * `_email_sent` action, log row — except that the row is tagged with
 * `source=cli-test` so support sessions can spot CLI-originated sends in
 * the log.
 *
 * ## OPTIONS
 *
 * <type>
 * : The registered email type id (e.g. `payment_receipt`).
 *
 * <email>
 * : Recipient email address. No confirmation is shown.
 *
 * [--dry-run]
 * : Compose the email and print the wp_mail args without dispatching.
 * : No log row is created.
 *
 * ## EXAMPLES
 *
 *     wp leastudios-email-templates send-test payment_receipt support@example.test
 *     wp leastudios-email-templates send-test payment_receipt support@example.test --dry-run
 *
 * @param array<int, string>    $args       Positional arguments: [0] => type id, [1] => email.
 * @param array<string, string> $assoc_args Associative arguments.
 * @return void
 */
public function send_test( array $args, array $assoc_args ): void {
    $type_id = (string) ( $args[0] ?? '' );
    $email   = (string) ( $args[1] ?? '' );
    $dry_run = isset( $assoc_args['dry-run'] );

    $result = $this->dispatch_send_test( $type_id, $email, $dry_run );

    if ( $dry_run ) {
        \WP_CLI::log( '[dry-run] No email was sent and no log row was written.' );
        \WP_CLI::log( 'To: ' . $email );
        \WP_CLI::log( 'Subject: ' . $result['subject'] );
        \WP_CLI::log( '' );
        \WP_CLI::log( $result['body'] );
        return;
    }

    if ( $result['sent'] ) {
        \WP_CLI::success( sprintf( 'Sent %s to %s (logged as source=cli-test).', $type_id, $email ) );
    } else {
        \WP_CLI::error( sprintf( 'wp_mail returned false for type "%s" to %s. Check that the type is enabled and that mail is configured.', $type_id, $email ) );
    }
}

/**
 * Validate args and dispatch the send. Pure-logic helper that tests can
 * call without going through the WP_CLI loggers.
 *
 * @param string $type_id Registered email type id.
 * @param string $email   Recipient address.
 * @param bool   $dry_run When true, no wp_mail is dispatched and no row is logged.
 * @return array{sent:bool, subject:string, body:string}
 */
public function dispatch_send_test( string $type_id, string $email, bool $dry_run ): array {
    if ( null === $this->registry->get( $type_id ) ) {
        \WP_CLI::error( sprintf( 'Unknown email type: %s', $type_id ) );
        return [ 'sent' => false, 'subject' => '', 'body' => '' ];
    }

    if ( ! is_email( $email ) ) {
        \WP_CLI::error( sprintf( '"%s" is not a valid email address.', $email ) );
        return [ 'sent' => false, 'subject' => '', 'body' => '' ];
    }

    $definition = $this->registry->get( $type_id );
    $context    = $definition->sample_context();

    if ( $dry_run ) {
        $composed = $this->sender->compose( $type_id, $context );
        if ( null === $composed ) {
            \WP_CLI::error( sprintf( 'Email type "%s" is disabled in settings.', $type_id ) );
            return [ 'sent' => false, 'subject' => '', 'body' => '' ];
        }

        return [
            'sent'    => false,
            'subject' => $composed['subject'],
            'body'    => $composed['body'],
        ];
    }

    $sent = $this->sender->send( $type_id, $email, $context, 'cli-test' );

    return [
        'sent'    => $sent,
        'subject' => '',
        'body'    => '',
    ];
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/CLICommandsTest.php -v`
Expected: All PASS.

- [ ] **Step 5: Run lint and PHPStan**

Run: `composer phpcs && composer phpstan`
Expected: both clean.

- [ ] **Step 6: Commit**

```bash
git add src/CLI/Commands.php tests/CLICommandsTest.php
git commit -m "Add CLI send-test subcommand with --dry-run and cli-test source tagging"
```

---

## Task 8: Register the CLI commands from `Plugin::init`

**Files:**
- Modify: `src/Plugin.php`

- [ ] **Step 1: Read the current `Plugin::init` method**

Already in memory from the plan's context cheatsheet. The hook to extend is right after the sender is constructed (around line 103).

- [ ] **Step 2: Add the CLI registration block**

Edit `src/Plugin.php`. Add this import to the `use` block at the top:

```php
use LEAStudios\EmailTemplates\CLI\Commands;
```

After the `// Email sender for transactional emails.` block:

```php
// Register WP-CLI commands. Guarded so the class is only required when
// the CLI is actually running, keeping the autoload graph quiet for
// web requests.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    \WP_CLI::add_command(
        'leastudios-email-templates',
        new Commands( $registry, $sender, $replacer )
    );
}
```

Place this block *before* the payment integration block, so the registration order in the file groups runtime composition above optional integrations.

- [ ] **Step 3: Verify the existing test suite still passes**

Run: `composer test`
Expected: All PASS.

- [ ] **Step 4: Smoke check via WP-CLI**

Run from the plugin directory:

```bash
wp leastudios-email-templates list-types
```

Expected output: a table with the five built-in types and their `transactional_required=yes` / `source=built-in` cells.

Run:

```bash
wp leastudios-email-templates list-types --format=json | jq '.[].id'
```

Expected: `"payment_receipt"`, `"subscription_created"`, `"subscription_renewed"`, `"payment_failed"`, `"refund_processed"`.

Run:

```bash
wp leastudios-email-templates preview payment_receipt --subject
```

Expected: a single line `Subject: …` containing rendered merge-tag content (no empty `{customer_name}` braces).

Run:

```bash
wp leastudios-email-templates preview payment_receipt > /tmp/preview.html && wc -c /tmp/preview.html
```

Expected: a non-empty HTML file containing `<html`, `<body`, and the rendered subject as the first line.

Run:

```bash
wp leastudios-email-templates send-test payment_receipt cli-trail@example.test --dry-run
```

Expected: `[dry-run] No email was sent and no log row was written.` plus subject/body lines.

Run:

```bash
wp leastudios-email-templates send-test payment_receipt cli-trail@example.test
```

Expected: `Success: Sent payment_receipt to cli-trail@example.test (logged as source=cli-test).`

Verify the log row landed with the expected source:

```bash
wp db query "SELECT id, type, recipient, source FROM wp_leastudios_email_templates_log ORDER BY id DESC LIMIT 1;"
```

Expected: one row with `source = cli-test`.

Run:

```bash
wp leastudios-email-templates --help
```

Expected: subcommand list including `list-types`, `preview`, `send-test`.

Run:

```bash
wp leastudios-email-templates send-test --help
```

Expected: usage text derived from the PHPDoc, mentioning the `<type>`, `<email>` positional args and `--dry-run`.

- [ ] **Step 5: Commit**

```bash
git add src/Plugin.php
git commit -m "Register WP-CLI commands from Plugin::init"
```

---

## Task 9: Surface the CLI-test source in the admin log list table

**Files:**
- Modify: `src/Admin/Email_Log_List_Table.php`
- Create: `tests/EmailLogListTableTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/EmailLogListTableTest.php`:

```php
<?php
/**
 * Tests for Email_Log_List_Table column rendering.
 *
 * @package LEAStudios\EmailTemplates\Tests
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Tests;

use LEAStudios\EmailTemplates\Admin\Email_Log_List_Table;
use LEAStudios\EmailTemplates\Database\Email_Log_Entry;
use LEAStudios\EmailTemplates\Database\Email_Log_Repository;
use LEAStudios\EmailTemplates\Email\Built_In\Payment_Receipt;
use LEAStudios\EmailTemplates\Email\Email_Type_Registry;
use LEAStudios\Tests\TestCase;

/**
 * @covers \LEAStudios\EmailTemplates\Admin\Email_Log_List_Table
 */
class EmailLogListTableTest extends TestCase {

    private Email_Log_List_Table $table;

    public function set_up(): void {
        parent::set_up();

        if ( ! function_exists( 'convert_to_screen' ) ) {
            require_once ABSPATH . 'wp-admin/includes/template.php';
            require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
            require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
            require_once ABSPATH . 'wp-admin/includes/screen.php';
        }
        set_current_screen( 'toplevel_page_leastudios-email-templates-log' );

        $registry = new Email_Type_Registry();
        $registry->register( new Payment_Receipt() );

        $this->table = new Email_Log_List_Table( new Email_Log_Repository(), $registry );
    }

    public function test_recipient_column_does_not_show_cli_badge_for_web_source(): void {
        $entry = new Email_Log_Entry(
            1,
            'payment_receipt',
            'a@example.test',
            'Receipt',
            '<p>body</p>',
            '',
            'sent',
            '',
            '2026-05-22 00:00:00',
            'web'
        );

        $html = $this->table->column_recipient( $entry );

        $this->assertStringContainsString( 'a@example.test', $html );
        $this->assertStringNotContainsString( '(cli)', $html );
    }

    public function test_recipient_column_shows_cli_badge_for_cli_test_source(): void {
        $entry = new Email_Log_Entry(
            2,
            'payment_receipt',
            'b@example.test',
            'Receipt',
            '<p>body</p>',
            '',
            'sent',
            '',
            '2026-05-22 00:00:00',
            'cli-test'
        );

        $html = $this->table->column_recipient( $entry );

        $this->assertStringContainsString( 'b@example.test', $html );
        $this->assertStringContainsString( '(cli)', $html );
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/EmailLogListTableTest.php -v`
Expected: FAIL — `column_recipient` does not exist.

- [ ] **Step 3: Add `column_recipient()` to the list table**

Edit `src/Admin/Email_Log_List_Table.php`. Insert immediately after `column_status()`:

```php
/**
 * Recipient column — appends `(cli)` when the row was created by the
 * `wp leastudios-email-templates send-test` command.
 *
 * @param Email_Log_Entry $item Row.
 * @return string
 */
public function column_recipient( $item ): string {
    $html = esc_html( $item->recipient );

    if ( 'web' !== $item->source ) {
        $html .= sprintf(
            ' <span class="leastudios-source-badge" style="color:#646970;font-size:11px;">(%s)</span>',
            esc_html( 'cli-test' === $item->source ? 'cli' : $item->source )
        );
    }

    return $html;
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/EmailLogListTableTest.php -v`
Expected: All PASS.

- [ ] **Step 5: Run lint and PHPStan**

Run: `composer phpcs && composer phpstan`
Expected: both clean.

- [ ] **Step 6: Visual smoke check**

Navigate in browser to `https://leastudios-plugins.test/wp-admin/admin.php?page=leastudios-email-templates-log`. The row created by Task 8's smoke check should display `cli-trail@example.test (cli)` in the recipient column.

- [ ] **Step 7: Commit**

```bash
git add src/Admin/Email_Log_List_Table.php tests/EmailLogListTableTest.php
git commit -m "Surface cli-test source in the email log recipient column"
```

---

## Task 10: Full verification gate

**Files:**
- None — verification only.

- [ ] **Step 1: PHPCS clean**

Run: `composer phpcs`
Expected: no errors.

- [ ] **Step 2: PHPStan clean at level 7**

Run: `composer phpstan`
Expected: `[OK] No errors`.

- [ ] **Step 3: Full PHPUnit suite**

Run: `composer test`
Expected: 150+ tests, 0 failures, 0 errors.

- [ ] **Step 4: WP-CLI end-to-end smoke**

From the plugin directory:

```bash
wp leastudios-email-templates list-types
wp leastudios-email-templates list-types --format=json
wp leastudios-email-templates preview payment_receipt --subject
wp leastudios-email-templates preview subscription_created --context='{"plan_name":"Pro Annual"}'
wp leastudios-email-templates send-test refund_processed cli-trail@example.test --dry-run
wp leastudios-email-templates send-test refund_processed cli-trail@example.test
wp db query "SELECT id, type, recipient, source FROM wp_leastudios_email_templates_log WHERE source != 'web' ORDER BY id DESC LIMIT 5;"
wp leastudios-email-templates --help
wp leastudios-email-templates preview --help
wp leastudios-email-templates send-test --help
```

All commands must succeed, with `source=cli-test` visible in the db query output for the real send (and not for the dry-run).

- [ ] **Step 5: Admin log UI smoke**

Open the Email Log page in the browser. Confirm the `(cli)` suffix renders on recipient cells corresponding to the smoke-check sends.

- [ ] **Step 6: Shared-files drift check**

Run from the plugin directory:

```bash
bash ../leastudios-dev-tools/bin/check-shared.sh
```

Expected: no drift reported (this phase did not touch the shared-by-duplication classes, so this is a regression guard).

- [ ] **Step 7: No commit needed**

This is a verification-only task. If everything is green, the branch is ready for review.

---

## Self-review notes

- **Spec coverage:**
  - `list-types` subcommand — Tasks 5, 8, 10.
  - `preview` subcommand — Tasks 6, 8, 10.
  - `send-test` subcommand — Tasks 7, 8, 10.
  - `--format` support — Task 5.
  - `--dry-run` support — Task 7.
  - CLI-test log trail — Tasks 1, 2, 3, 7, 9.
  - One composition code path — Tasks 6 and 7 both delegate to `Email_Sender::compose()` / `send()`.
  - Plugin registration guard — Task 8.
- **Placeholder scan:** No "TBD" / "similar to" / "implement later" / "add appropriate error handling" placeholders. Every code step contains the full snippet.
- **Type consistency:**
  - `source` is `string` everywhere: `Email_Log_Entry::source`, `Email_Sender::send()` 4th param, `Send_Logger::record()` 7th param, `Commands::dispatch_send_test()` writes `'cli-test'`.
  - Method names `build_type_rows`, `render_preview`, `dispatch_send_test` are referenced identically across Tasks 5/6/7 and their tests.
  - The `_email_sent` action signature is consistently 7 args: `string $type_id, string $to, string $subject, bool $result, string $body, array $headers, string $source`.
  - `Email_Log_Repository::create()` row shape adds `source` as an optional key (`source?:string`); the new `from_row()` reads `$row->source` with a `'web'` fallback so old rows pre-migration round-trip safely.
