# Phase 4 — Send log with body capture + resend

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:executing-plans.

**Goal:** Persist every transactional send to a custom table including the rendered body; expose a paginated admin list under **Email Templates → Log** with filters, a detail view, and a Resend button; auto-prune old rows on a daily cron.

**Architecture:**

- New table `wp_leastudios_email_templates_log` with one row per send attempt.
- New `Database\Email_Log_Repository` wraps `$wpdb` for create/query/prune.
- `Email_Sender::send` fires the existing `leastudios_email_templates_email_sent` action with a fifth `body` argument and a sixth `headers` argument so a logger subscriber can record everything without re-rendering.
- New `Log\Send_Logger` subscribes to that action and records rows.
- New `Admin\Email_Log_Page` registers a submenu, renders a WP_List_Table-style page with filters, handles row actions (view, resend).
- New `Admin\Email_Log_Resender` handles the resend POST: wp_mail with the stored body + a `[Resend]` subject prefix.
- Activation hook installs the table + schedules `leastudios_email_templates_log_prune` daily; deactivation unschedules.

**Tech Stack:** PHP 8.2, `$wpdb`, `dbDelta`, `WP_List_Table`, WP cron.

---

## File structure

- **Create** `src/Database/Email_Log_Repository.php`
- **Create** `src/Log/Send_Logger.php`
- **Create** `src/Admin/Email_Log_Page.php`
- **Create** `src/Admin/Email_Log_List_Table.php` (extends `WP_List_Table`)
- **Modify** `src/Email/Email_Sender.php` — extend the action signature to include body and headers.
- **Modify** `src/Plugin.php` — wire `Send_Logger` and `Email_Log_Page`.
- **Modify** `leastudios-email-templates.php` — create the table on activation; schedule/unschedule cron.
- **Modify** `uninstall.php` — drop the table.
- **Create** tests: `EmailLogRepositoryTest.php`, `SendLoggerTest.php`.

---

## Task 1: Schema + repository

**Files:**
- Create: `src/Database/Email_Log_Repository.php`
- Test: `tests/EmailLogRepositoryTest.php`

- [ ] **Step 1.1: Decide schema** (recorded here so it's discoverable later)

```sql
CREATE TABLE wp_leastudios_email_templates_log (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    type varchar(64) NOT NULL DEFAULT '',
    recipient varchar(255) NOT NULL DEFAULT '',
    subject text NOT NULL,
    body longtext NOT NULL,
    headers longtext NOT NULL,
    status varchar(16) NOT NULL DEFAULT 'sent',
    error text NULL,
    created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY type (type),
    KEY status (status),
    KEY created_at (created_at)
) {$charset_collate};
```

- [ ] **Step 1.2: Failing tests**

Create `tests/EmailLogRepositoryTest.php`:

```php
<?php
declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Tests;

use LEAStudios\EmailTemplates\Database\Email_Log_Repository;
use LEAStudios\Tests\TestCase;

class EmailLogRepositoryTest extends TestCase {

    private Email_Log_Repository $repo;

    public function set_up(): void {
        parent::set_up();
        $this->repo = new Email_Log_Repository();
        $this->repo->install();
        $this->repo->delete_all();
    }

    public function test_create_and_get(): void {
        $id = $this->repo->create([
            'type'      => 'payment_receipt',
            'recipient' => 'a@example.com',
            'subject'   => 'Hi',
            'body'      => '<p>Body</p>',
            'headers'   => 'Content-Type: text/html',
            'status'    => 'sent',
            'error'     => null,
        ]);

        $this->assertGreaterThan(0, $id);

        $row = $this->repo->get($id);
        $this->assertNotNull($row);
        $this->assertSame('payment_receipt', $row->type);
        $this->assertSame('a@example.com', $row->recipient);
        $this->assertSame('sent', $row->status);
    }

    public function test_paginated_query_with_filters(): void {
        foreach ( [ 'payment_receipt', 'refund_processed', 'payment_receipt' ] as $i => $type ) {
            $this->repo->create([
                'type'      => $type,
                'recipient' => "user{$i}@example.com",
                'subject'   => "S{$i}",
                'body'      => '',
                'headers'   => '',
                'status'    => 0 === $i % 2 ? 'sent' : 'failed',
                'error'     => null,
            ]);
        }

        $page = $this->repo->paginate([ 'type' => 'payment_receipt' ], 10, 1);
        $this->assertCount(2, $page['rows']);
        $this->assertSame(2, $page['total']);

        $page = $this->repo->paginate([ 'status' => 'failed' ], 10, 1);
        $this->assertCount(1, $page['rows']);
    }

    public function test_prune_removes_rows_older_than_cutoff(): void {
        global $wpdb;

        $this->repo->create([
            'type'      => 'payment_receipt',
            'recipient' => 'old@example.com',
            'subject'   => 'old',
            'body'      => '',
            'headers'   => '',
            'status'    => 'sent',
            'error'     => null,
        ]);
        // Backdate it.
        $wpdb->query("UPDATE {$wpdb->prefix}leastudios_email_templates_log SET created_at = DATE_SUB(NOW(), INTERVAL 60 DAY)");

        $this->repo->create([
            'type'      => 'payment_receipt',
            'recipient' => 'new@example.com',
            'subject'   => 'new',
            'body'      => '',
            'headers'   => '',
            'status'    => 'sent',
            'error'     => null,
        ]);

        $deleted = $this->repo->prune_older_than(30);

        $this->assertSame(1, $deleted);
        $page = $this->repo->paginate([], 10, 1);
        $this->assertCount(1, $page['rows']);
        $this->assertSame('new@example.com', $page['rows'][0]->recipient);
    }
}
```

- [ ] **Step 1.3: RED**

```bash
vendor/bin/phpunit tests/EmailLogRepositoryTest.php
```

- [ ] **Step 1.4: Implement the repository**

Create `src/Database/Email_Log_Repository.php`:

```php
<?php
/**
 * Repository for the email send log.
 *
 * @package LEAStudios\EmailTemplates\Database
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps the custom `leastudios_email_templates_log` table.
 *
 * Plain `$wpdb` access — no ORM, no caching — because the read patterns
 * (paginated admin list, prune by date) are already well-served by the
 * MySQL indexes defined in install().
 */
class Email_Log_Repository {

    /**
     * Bumped when the schema changes so install() knows to re-run dbDelta.
     */
    private const SCHEMA_VERSION = '1.0.0';

    /**
     * Option key holding the installed schema version.
     */
    private const SCHEMA_OPTION = 'leastudios_email_templates_log_schema_version';

    /**
     * Fully-qualified table name (with prefix).
     */
    public function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'leastudios_email_templates_log';
    }

    /**
     * Create or upgrade the table. Safe to call repeatedly — dbDelta is idempotent.
     */
    public function install(): void {
        if ( get_option( self::SCHEMA_OPTION ) === self::SCHEMA_VERSION ) {
            return;
        }

        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table           = $this->table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            type varchar(64) NOT NULL DEFAULT '',
            recipient varchar(255) NOT NULL DEFAULT '',
            subject text NOT NULL,
            body longtext NOT NULL,
            headers longtext NOT NULL,
            status varchar(16) NOT NULL DEFAULT 'sent',
            error text NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY type_idx (type),
            KEY status_idx (status),
            KEY created_at_idx (created_at)
        ) {$charset_collate};";

        dbDelta( $sql );

        update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
    }

    /**
     * Insert a row.
     *
     * @param array{type:string,recipient:string,subject:string,body:string,headers:string,status:string,error:?string} $data
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
                'created_at' => current_time( 'mysql' ),
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        return false === $ok ? 0 : (int) $wpdb->insert_id;
    }

    /**
     * Fetch one row by id.
     */
    public function get( int $id ): ?object {
        global $wpdb;
        $table = $this->table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );

        return $row ?: null;
    }

    /**
     * Paginated list.
     *
     * @param array{type?:string, status?:string, since?:string, until?:string} $filters
     * @return array{rows: array<int, object>, total: int}
     */
    public function paginate( array $filters, int $per_page, int $page ): array {
        global $wpdb;
        $table   = $this->table_name();
        $where   = [ '1=1' ];
        $args    = [];

        if ( ! empty( $filters['type'] ) ) {
            $where[] = 'type = %s';
            $args[]  = $filters['type'];
        }
        if ( ! empty( $filters['status'] ) ) {
            $where[] = 'status = %s';
            $args[]  = $filters['status'];
        }
        if ( ! empty( $filters['since'] ) ) {
            $where[] = 'created_at >= %s';
            $args[]  = $filters['since'];
        }
        if ( ! empty( $filters['until'] ) ) {
            $where[] = 'created_at <= %s';
            $args[]  = $filters['until'];
        }

        $where_sql = implode( ' AND ', $where );
        $offset    = max( 0, ( $page - 1 ) * $per_page );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $total = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $args )
        );

        $sql_args = array_merge( $args, [ $per_page, $offset ] );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d", $sql_args )
        );

        return [ 'rows' => $rows ?: [], 'total' => $total ];
    }

    /**
     * Delete rows older than N days. Returns number of rows deleted.
     */
    public function prune_older_than( int $days ): int {
        global $wpdb;
        $table = $this->table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $count = $wpdb->query(
            $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)", $days )
        );

        return false === $count ? 0 : (int) $count;
    }

    /**
     * Truncate (used by tests). Not exposed elsewhere.
     */
    public function delete_all(): void {
        global $wpdb;
        $table = $this->table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
        $wpdb->query( "TRUNCATE TABLE {$table}" );
    }

    /**
     * Drop the table (used on uninstall).
     */
    public function drop(): void {
        global $wpdb;
        $table = $this->table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
        $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
        delete_option( self::SCHEMA_OPTION );
    }
}
```

- [ ] **Step 1.5: GREEN + lint + commit**

```bash
composer test && composer lint
git add src/Database/Email_Log_Repository.php tests/EmailLogRepositoryTest.php
git commit -m "Add Email_Log_Repository with install/paginate/prune"
```

---

## Task 2: Extend the action + add the logger subscriber

**Files:**
- Modify: `src/Email/Email_Sender.php` — append `$composed['body']` and `$composed['headers']` to the action.
- Create: `src/Log/Send_Logger.php`
- Test: `tests/SendLoggerTest.php`

- [ ] **Step 2.1: Extend the action signature**

In `src/Email/Email_Sender.php`, replace the `do_action` call inside `send()` with:

```php
/**
 * Fires after a transactional email is sent.
 *
 * @param Email_Type           $type    The email type.
 * @param string               $to      The recipient.
 * @param string               $subject The subject line.
 * @param bool                 $result  Whether wp_mail returned true.
 * @param string               $body    The rendered body that was passed to wp_mail.
 * @param array<int, string>   $headers The headers passed to wp_mail.
 */
do_action(
    'leastudios_email_templates_email_sent',
    $type,
    $args['to'],
    $args['subject'],
    $result,
    (string) $args['message'],
    (array) $args['headers']
);
```

- [ ] **Step 2.2: Failing test**

Create `tests/SendLoggerTest.php`:

```php
<?php
declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Tests;

use LEAStudios\EmailTemplates\Database\Email_Log_Repository;
use LEAStudios\EmailTemplates\Email\Email_Type;
use LEAStudios\EmailTemplates\Log\Send_Logger;
use LEAStudios\Tests\TestCase;

class SendLoggerTest extends TestCase {

    private Email_Log_Repository $repo;
    private Send_Logger $logger;

    public function set_up(): void {
        parent::set_up();
        $this->repo = new Email_Log_Repository();
        $this->repo->install();
        $this->repo->delete_all();
        $this->logger = new Send_Logger( $this->repo );
    }

    public function test_records_successful_send(): void {
        $this->logger->record(
            Email_Type::PAYMENT_RECEIPT,
            'buyer@example.com',
            'Receipt',
            true,
            '<p>Body</p>',
            [ 'Content-Type: text/html' ]
        );

        $page = $this->repo->paginate([], 10, 1);
        $this->assertCount(1, $page['rows']);
        $this->assertSame('payment_receipt', $page['rows'][0]->type);
        $this->assertSame('sent', $page['rows'][0]->status);
        $this->assertSame('<p>Body</p>', $page['rows'][0]->body);
    }

    public function test_records_failed_send_with_error(): void {
        $this->logger->record(
            Email_Type::PAYMENT_FAILED,
            'fail@example.com',
            'Fail',
            false,
            '<p>Body</p>',
            []
        );

        $page = $this->repo->paginate([ 'status' => 'failed' ], 10, 1);
        $this->assertCount(1, $page['rows']);
    }
}
```

- [ ] **Step 2.3: Implement Send_Logger**

Create `src/Log/Send_Logger.php`:

```php
<?php
/**
 * Records every transactional send to the email_log table.
 *
 * @package LEAStudios\EmailTemplates\Log
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Log;

defined( 'ABSPATH' ) || exit;

use LEAStudios\EmailTemplates\Database\Email_Log_Repository;
use LEAStudios\EmailTemplates\Email\Email_Type;

class Send_Logger {

    public function __construct(
        private readonly Email_Log_Repository $repo,
    ) {}

    public function init(): void {
        add_action( 'leastudios_email_templates_email_sent', [ $this, 'record' ], 10, 6 );
    }

    /**
     * @param Email_Type         $type
     * @param string             $to
     * @param string             $subject
     * @param bool               $result
     * @param string             $body
     * @param array<int, string> $headers
     */
    public function record( Email_Type $type, string $to, string $subject, bool $result, string $body = '', array $headers = [] ): void {
        $this->repo->create(
            [
                'type'      => $type->value,
                'recipient' => $to,
                'subject'   => $subject,
                'body'      => $body,
                'headers'   => implode( "\n", $headers ),
                'status'    => $result ? 'sent' : 'failed',
                'error'     => null,
            ]
        );
    }
}
```

- [ ] **Step 2.4: Wire in Plugin::init**

Add to `src/Plugin.php` after the other component bootstraps (only when we're running with at least the wrapper):

```php
use LEAStudios\EmailTemplates\Database\Email_Log_Repository;
use LEAStudios\EmailTemplates\Log\Send_Logger;
```

And inside `init()`:

```php
$log_repo = new Email_Log_Repository();
$logger   = new Send_Logger( $log_repo );
$logger->init();
```

- [ ] **Step 2.5: GREEN + lint + commit**

```bash
composer test && composer lint
git add src/Email/Email_Sender.php src/Log/Send_Logger.php src/Plugin.php tests/SendLoggerTest.php
git commit -m "Record every transactional send to the new email log table"
```

---

## Task 3: Activation/deactivation/uninstall hooks

**Files:**
- Modify: `leastudios-email-templates.php`
- Modify: `uninstall.php`

- [ ] **Step 3.1: Install the table on activation; schedule daily prune**

In `leastudios-email-templates.php`, replace `leastudios_email_templates_activate()` body so it also installs the table and schedules the prune cron, and add a deactivate handler that unschedules it. Match the existing function-style.

```php
function leastudios_email_templates_activate(): void {
    add_option( 'leastudios_email_templates_branding', [ /* …existing defaults… */ ] );
    add_option( 'leastudios_email_templates_emails', [] );

    if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
        require_once __DIR__ . '/vendor/autoload.php';
        ( new \LEAStudios\EmailTemplates\Database\Email_Log_Repository() )->install();
    }

    if ( ! wp_next_scheduled( 'leastudios_email_templates_log_prune' ) ) {
        wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'leastudios_email_templates_log_prune' );
    }
}

function leastudios_email_templates_deactivate(): void {
    $timestamp = wp_next_scheduled( 'leastudios_email_templates_log_prune' );
    if ( false !== $timestamp ) {
        wp_unschedule_event( $timestamp, 'leastudios_email_templates_log_prune' );
    }
}
```

- [ ] **Step 3.2: Hook the cron**

In `src/Plugin.php::init()`, register the cron handler:

```php
add_action( 'leastudios_email_templates_log_prune', static function () use ( $log_repo ) {
    $days = (int) apply_filters( 'leastudios_email_templates_log_retention_days', 30 );
    $log_repo->prune_older_than( max( 1, $days ) );
} );
```

- [ ] **Step 3.3: Drop on uninstall**

In `uninstall.php`, after the existing `delete_option` calls:

```php
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
    require_once __DIR__ . '/vendor/autoload.php';
    ( new \LEAStudios\EmailTemplates\Database\Email_Log_Repository() )->drop();
}

$timestamp = wp_next_scheduled( 'leastudios_email_templates_log_prune' );
if ( false !== $timestamp ) {
    wp_unschedule_event( $timestamp, 'leastudios_email_templates_log_prune' );
}
```

- [ ] **Step 3.4: Run tests + lint, smoke-deactivate-reactivate**

```bash
composer test && composer lint
cd /Users/adamlea/Herd/leastudios-plugins
wp plugin deactivate leastudios-email-templates && wp plugin activate leastudios-email-templates
wp eval 'echo "scheduled: " . wp_next_scheduled("leastudios_email_templates_log_prune") . "\n";'
```

Expected: a future unix timestamp.

- [ ] **Step 3.5: Commit**

```bash
git add leastudios-email-templates.php uninstall.php src/Plugin.php
git commit -m "Install email log table and schedule daily prune on activation"
```

---

## Task 4: Admin list page

**Files:**
- Create: `src/Admin/Email_Log_Page.php`
- Create: `src/Admin/Email_Log_List_Table.php`

This task has no automated tests — it's UI. Verify by hand.

- [ ] **Step 4.1: Implement Email_Log_List_Table**

Create `src/Admin/Email_Log_List_Table.php` extending `WP_List_Table`:

```php
<?php
declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Admin;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

use LEAStudios\EmailTemplates\Database\Email_Log_Repository;

class Email_Log_List_Table extends \WP_List_Table {

    private Email_Log_Repository $repo;

    public function __construct( Email_Log_Repository $repo ) {
        parent::__construct(
            [
                'singular' => 'log_entry',
                'plural'   => 'log_entries',
                'ajax'     => false,
            ]
        );
        $this->repo = $repo;
    }

    public function get_columns(): array {
        return [
            'created_at' => __( 'Date', 'leastudios-email-templates' ),
            'type'       => __( 'Type', 'leastudios-email-templates' ),
            'recipient'  => __( 'Recipient', 'leastudios-email-templates' ),
            'subject'    => __( 'Subject', 'leastudios-email-templates' ),
            'status'     => __( 'Status', 'leastudios-email-templates' ),
            'actions'    => __( 'Actions', 'leastudios-email-templates' ),
        ];
    }

    public function prepare_items(): void {
        $per_page = 25;
        $paged    = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
        $filters  = [
            'type'   => isset( $_GET['type'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['type'] ) ) : '',
            'status' => isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['status'] ) ) : '',
        ];

        $page = $this->repo->paginate( $filters, $per_page, $paged );

        $this->items           = $page['rows'];
        $this->_column_headers = [ $this->get_columns(), [], [] ];
        $this->set_pagination_args(
            [
                'total_items' => $page['total'],
                'per_page'    => $per_page,
                'total_pages' => (int) ceil( $page['total'] / $per_page ),
            ]
        );
    }

    public function column_default( $item, $column_name ): string {
        return isset( $item->{$column_name} ) ? esc_html( (string) $item->{$column_name} ) : '';
    }

    public function column_status( $item ): string {
        $color = 'sent' === $item->status ? '#1d8a1d' : '#b32d2e';
        return sprintf( '<span style="color:%s;font-weight:600;">%s</span>', esc_attr( $color ), esc_html( $item->status ) );
    }

    public function column_actions( $item ): string {
        $view_url   = add_query_arg(
            [ 'page' => 'leastudios-email-templates-log', 'view' => $item->id ],
            admin_url( 'admin.php' )
        );
        $resend_url = wp_nonce_url(
            add_query_arg(
                [ 'page' => 'leastudios-email-templates-log', 'resend' => $item->id ],
                admin_url( 'admin.php' )
            ),
            'leastudios_email_templates_resend_' . $item->id
        );

        return sprintf(
            '<a href="%s">%s</a> | <a href="%s">%s</a>',
            esc_url( $view_url ),
            esc_html__( 'View', 'leastudios-email-templates' ),
            esc_url( $resend_url ),
            esc_html__( 'Resend', 'leastudios-email-templates' )
        );
    }

    protected function extra_tablenav( $which ): void {
        if ( 'top' !== $which ) {
            return;
        }

        $type   = sanitize_text_field( wp_unslash( (string) ( $_GET['type'] ?? '' ) ) );
        $status = sanitize_text_field( wp_unslash( (string) ( $_GET['status'] ?? '' ) ) );
        ?>
        <div class="alignleft actions">
            <select name="type">
                <option value=""><?php esc_html_e( 'All types', 'leastudios-email-templates' ); ?></option>
                <?php foreach ( \LEAStudios\EmailTemplates\Email\Email_Type::cases() as $case ) : ?>
                    <option value="<?php echo esc_attr( $case->value ); ?>" <?php selected( $case->value, $type ); ?>>
                        <?php echo esc_html( $case->label() ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="status">
                <option value=""><?php esc_html_e( 'All statuses', 'leastudios-email-templates' ); ?></option>
                <option value="sent" <?php selected( 'sent', $status ); ?>><?php esc_html_e( 'Sent', 'leastudios-email-templates' ); ?></option>
                <option value="failed" <?php selected( 'failed', $status ); ?>><?php esc_html_e( 'Failed', 'leastudios-email-templates' ); ?></option>
            </select>
            <?php submit_button( __( 'Filter', 'leastudios-email-templates' ), '', 'filter', false ); ?>
        </div>
        <?php
    }
}
```

- [ ] **Step 4.2: Implement Email_Log_Page**

Create `src/Admin/Email_Log_Page.php`:

```php
<?php
declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Admin;

defined( 'ABSPATH' ) || exit;

use LEAStudios\EmailTemplates\Database\Email_Log_Repository;
use LEAStudios\EmailTemplates\Email\Email_Type;

class Email_Log_Page {

    private const CAPABILITY = 'manage_options';
    private const SLUG       = 'leastudios-email-templates-log';

    public function __construct( private readonly Email_Log_Repository $repo ) {}

    public function init(): void {
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
        add_action( 'admin_init', [ $this, 'maybe_handle_resend' ] );
    }

    public function add_menu(): void {
        add_submenu_page(
            'leastudios-email-templates',
            __( 'Email Log', 'leastudios-email-templates' ),
            __( 'Log', 'leastudios-email-templates' ),
            self::CAPABILITY,
            self::SLUG,
            [ $this, 'render' ]
        );
    }

    public function render(): void {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $view_id = isset( $_GET['view'] ) ? (int) $_GET['view'] : 0;

        if ( $view_id > 0 ) {
            $this->render_detail( $view_id );
            return;
        }

        $table = new Email_Log_List_Table( $this->repo );
        $table->prepare_items();

        echo '<div class="wrap"><h1>' . esc_html__( 'Email Log', 'leastudios-email-templates' ) . '</h1>';
        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" />';
        $table->display();
        echo '</form></div>';
    }

    private function render_detail( int $id ): void {
        $row = $this->repo->get( $id );
        if ( null === $row ) {
            echo '<div class="wrap"><h1>' . esc_html__( 'Not found', 'leastudios-email-templates' ) . '</h1></div>';
            return;
        }

        $back = add_query_arg( [ 'page' => self::SLUG ], admin_url( 'admin.php' ) );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Log Entry', 'leastudios-email-templates' ); ?></h1>
            <p><a href="<?php echo esc_url( $back ); ?>">&larr; <?php esc_html_e( 'Back to log', 'leastudios-email-templates' ); ?></a></p>
            <table class="form-table">
                <tr><th><?php esc_html_e( 'Date', 'leastudios-email-templates' ); ?></th><td><?php echo esc_html( (string) $row->created_at ); ?></td></tr>
                <tr><th><?php esc_html_e( 'Type', 'leastudios-email-templates' ); ?></th><td><?php echo esc_html( (string) $row->type ); ?></td></tr>
                <tr><th><?php esc_html_e( 'Recipient', 'leastudios-email-templates' ); ?></th><td><?php echo esc_html( (string) $row->recipient ); ?></td></tr>
                <tr><th><?php esc_html_e( 'Subject', 'leastudios-email-templates' ); ?></th><td><?php echo esc_html( (string) $row->subject ); ?></td></tr>
                <tr><th><?php esc_html_e( 'Status', 'leastudios-email-templates' ); ?></th><td><?php echo esc_html( (string) $row->status ); ?></td></tr>
                <tr><th><?php esc_html_e( 'Headers', 'leastudios-email-templates' ); ?></th><td><pre style="white-space:pre-wrap;background:#f6f7f7;padding:8px;border-radius:4px;"><?php echo esc_html( (string) $row->headers ); ?></pre></td></tr>
            </table>
            <h2><?php esc_html_e( 'Body', 'leastudios-email-templates' ); ?></h2>
            <iframe srcdoc="<?php echo esc_attr( (string) $row->body ); ?>" style="width:100%;height:600px;border:1px solid #ccd0d4;background:#fff;"></iframe>
        </div>
        <?php
    }

    public function maybe_handle_resend(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( empty( $_GET['resend'] ) || empty( $_GET['_wpnonce'] ) ) {
            return;
        }

        $id = (int) $_GET['resend'];
        check_admin_referer( 'leastudios_email_templates_resend_' . $id );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            return;
        }

        $row = $this->repo->get( $id );
        if ( null === $row ) {
            return;
        }

        $headers = array_filter( explode( "\n", (string) $row->headers ) );
        $subject = '[Resend] ' . (string) $row->subject;
        $result  = wp_mail( (string) $row->recipient, $subject, (string) $row->body, $headers );

        $this->repo->create(
            [
                'type'      => (string) $row->type,
                'recipient' => (string) $row->recipient,
                'subject'   => $subject,
                'body'      => (string) $row->body,
                'headers'   => (string) $row->headers,
                'status'    => $result ? 'sent' : 'failed',
                'error'     => null,
            ]
        );

        wp_safe_redirect(
            add_query_arg(
                [ 'page' => self::SLUG, 'resent' => $result ? '1' : '0' ],
                admin_url( 'admin.php' )
            )
        );
        exit;
    }
}
```

- [ ] **Step 4.3: Wire in Plugin::init**

```php
use LEAStudios\EmailTemplates\Admin\Email_Log_Page;
// ...
if ( is_admin() ) {
    $settings = new Settings_Page();
    $settings->init();

    $log_page = new Email_Log_Page( $log_repo );
    $log_page->init();
}
```

- [ ] **Step 4.4: Smoke-verify**

```bash
cd /Users/adamlea/Herd/leastudios-plugins && wp eval '
require_once ABSPATH . "wp-admin/includes/admin.php";
wp_set_current_user(1);
// Trigger one send to populate the log.
do_action( "leastudios_email_templates_email_sent", \LEAStudios\EmailTemplates\Email\Email_Type::PAYMENT_RECEIPT, "test@example.com", "Subj", true, "<p>Body</p>", ["Content-Type: text/html"] );
$repo = new \LEAStudios\EmailTemplates\Database\Email_Log_Repository();
$repo->install();
$page = $repo->paginate([], 10, 1);
echo "Rows in log: " . count($page["rows"]) . "\n";
foreach ($page["rows"] as $row) {
    echo " - {$row->type} → {$row->recipient} ({$row->status})\n";
}
'
```

- [ ] **Step 4.5: Lint + commit**

```bash
composer lint
git add src/Admin/Email_Log_Page.php src/Admin/Email_Log_List_Table.php src/Plugin.php
git commit -m "Add Email Log admin page with filters, detail view, and resend"
```

---

## Self-review

- [ ] Activating the plugin creates `wp_leastudios_email_templates_log` (verify with `wp db query "DESCRIBE wp_leastudios_email_templates_log"`).
- [ ] A real `wp_mail` of a transactional type lands a row in the log.
- [ ] Filters on the list page narrow the result correctly.
- [ ] Clicking View shows the body in an iframe.
- [ ] Clicking Resend produces a new log row prefixed `[Resend]`.
- [ ] Deactivating the plugin unschedules the cron.
- [ ] Uninstalling the plugin drops the table.
- [ ] `composer test` green, `composer lint` clean.
