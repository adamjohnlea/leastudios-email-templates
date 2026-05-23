# Phase 9 — Unsubscribe / Suppression Support — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship a compliant, audit-recognized opt-out mechanism for outgoing transactional email — including suppression storage, HMAC-signed unsubscribe URLs, a public REST landing page, an admin management surface, WP-CLI parity, and an auto-appended footer on non-required emails.

**Architecture:** Stateless HMAC-SHA256 tokens carry the recipient through a public REST endpoint that writes one suppression row per email (`wp_leastudios_email_templates_suppressions`). `Email_Sender::send()` gates non-required types against the suppression table and auto-appends the unsubscribe footer; required types (receipts/refunds/payment-failed/renewal-receipts) bypass the gate. The merge tag `{unsubscribe_url}` is a recipient-aware near-global tag that resolves to empty for required-type sends or empty recipients.

**Tech Stack:** PHP 8.1+, WordPress 6.8.2, `$wpdb`, `dbDelta`, `hash_hmac`, WP REST API (`WP_REST_Controller`), WP-CLI, `WP_List_Table`, PHPUnit 9.6 against the WordPress test library, PHPStan level 7, WPCS via PHPCS.

**Reference spec:** [`docs/superpowers/specs/2026-05-22-phase-9-unsubscribe-suppression-design.md`](../specs/2026-05-22-phase-9-unsubscribe-suppression-design.md). Re-read it before starting; design decisions D1–D10 are not re-litigated here.

---

## File layout overview

**New:**
- `src/Subscription/Unsubscribe_Manager.php`
- `src/Database/Suppression_Repository.php`
- `src/Database/Suppression_Entry.php`
- `src/REST/Unsubscribe_Controller.php`
- `src/Admin/Suppressions_Page.php`
- `src/Admin/Suppressions_List_Table.php`
- `templates/unsubscribe/landing-unsubscribed.php`
- `templates/unsubscribe/landing-resubscribed.php`
- `templates/unsubscribe/landing-error.php`
- `tests/UnsubscribeManagerTest.php`
- `tests/SuppressionRepositoryTest.php`
- `tests/UnsubscribeControllerTest.php`
- `tests/SuppressionsListTableTest.php`

**Modified:**
- `src/Email/Email_Sender.php` (constructor +manager arg; suppression gate; `{unsubscribe_url}` injection; footer append; `compose()` signature gains `$to`)
- `src/Email/Merge_Tag_Replacer.php` (add `unsubscribe_url` to `get_global_escape_modes()`)
- `src/Email/Built_In/Subscription_Created.php` (`is_transactional_required()` → `false`, doc comment updated)
- `src/Log/Send_Logger.php` (listen to `leastudios_email_templates_email_suppressed`)
- `src/CLI/Commands.php` (constructor +manager arg; +3 subcommands: `list-suppressions`, `add-suppression`, `remove-suppression`)
- `src/Plugin.php` (composition root wiring)
- `tests/BuiltInTypesTest.php` (split the `transactional_required` provider)
- `tests/EmailSenderTest.php` (constructor arg; new tests for gate, merge tag, footer, suppressed action)
- `tests/MergeTagReplacerTest.php` (assert `unsubscribe_url` is URL-escaped globally)
- `tests/SendLoggerTest.php` (assert `_email_suppressed` writes a row with `status='suppressed'`)
- `tests/CLICommandsTest.php` (assert new subcommand helpers)
- `tests/bootstrap.php` (no change expected — WP_CLI + format_items stubs from Phase 8 cover the new commands too)
- `uninstall.php` (drop suppressions table + delete secret + delete schema-version option)
- `CLAUDE.md` (refresh "What this plugin does" + extension points)

---

## Conventions reminder

- Every PHP file: `<?php` header docblock, `declare(strict_types=1);`, namespace `LEAStudios\EmailTemplates\…`, `defined('ABSPATH') || exit;`.
- All new public APIs documented; new `__()` strings get `// translators:` comments when they contain tokens.
- Run `composer phpcs` + `composer phpstan` + `composer test` at the end of each task. Don't move on until clean.
- Commits use the project's plain-descriptive style (no conventional-commit prefix). Message body explains the why.

---

## Task 1: `Suppression_Entry` value object

**Files:**
- Create: `src/Database/Suppression_Entry.php`
- Test: (covered by Task 2's repository tests via `from_row` round-trip)

- [ ] **Step 1: Create the value object**

```php
<?php
/**
 * Value object for a row in the suppressions table.
 *
 * @package LEAStudios\EmailTemplates\Database
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Database;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Immutable, typed view of a row in `wp_leastudios_email_templates_suppressions`.
 *
 * Mirrors the shape of Email_Log_Entry so list-table consumers can read
 * properties directly without an array_key_exists dance.
 */
final class Suppression_Entry {

	/**
	 * Constructor.
	 *
	 * @param int    $id            Row id.
	 * @param string $email         Suppressed email (already lowercased).
	 * @param string $suppressed_at MySQL datetime string in UTC.
	 * @param string $source        Origin marker: 'link' | 'admin' | 'cli'.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $email,
		public readonly string $suppressed_at,
		public readonly string $source,
	) {}

	/**
	 * Build an entry from a $wpdb stdClass row.
	 *
	 * @param \stdClass $row One row from $wpdb->get_results().
	 * @return self
	 */
	public static function from_row( \stdClass $row ): self {
		return new self(
			(int) ( $row->id ?? 0 ),
			(string) ( $row->email ?? '' ),
			(string) ( $row->suppressed_at ?? '' ),
			(string) ( $row->source ?? 'link' ),
		);
	}
}
```

- [ ] **Step 2: Verify class loads**

Run: `vendor/bin/phpunit tests/BuiltInTypesTest.php -v`
Expected: existing tests pass (proves autoloader still works after adding the new namespace folder).

- [ ] **Step 3: Commit**

```bash
git add src/Database/Suppression_Entry.php
git commit -m "$(cat <<'EOF'
Add Suppression_Entry value object for Phase 9

Mirrors Email_Log_Entry's shape so the upcoming suppressions list table
can read row properties directly. Repository is added in the next task.
EOF
)"
```

---

## Task 2: `Suppression_Repository` + schema install

**Files:**
- Create: `src/Database/Suppression_Repository.php`
- Test: `tests/SuppressionRepositoryTest.php`

- [ ] **Step 1: Write failing tests first**

Create `tests/SuppressionRepositoryTest.php`:

```php
<?php
/**
 * Tests for Suppression_Repository.
 *
 * @package LEAStudios\EmailTemplates\Tests
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Tests;

use LEAStudios\EmailTemplates\Database\Suppression_Entry;
use LEAStudios\EmailTemplates\Database\Suppression_Repository;
use LEAStudios\Tests\TestCase;

class SuppressionRepositoryTest extends TestCase {

	private Suppression_Repository $repo;

	protected function setUp(): void {
		parent::setUp();
		$this->repo = new Suppression_Repository();
		$this->repo->install();
		$this->repo->delete_all();
	}

	public function test_install_is_idempotent(): void {
		// Calling install twice must not throw and must converge on the
		// SCHEMA_VERSION marker.
		$this->repo->install();
		$this->repo->install();

		$this->assertSame(
			'1.0.0',
			get_option( 'leastudios_email_templates_suppressions_schema_version' )
		);
	}

	public function test_upsert_inserts_then_refreshes(): void {
		$this->repo->upsert( 'jane@example.com', 'link' );
		$this->repo->upsert( 'jane@example.com', 'admin' );

		$rows = $this->repo->paginate( [], 50, 1 );

		$this->assertSame( 1, $rows['total'], 'must dedupe on email' );
		$this->assertInstanceOf( Suppression_Entry::class, $rows['rows'][0] );
		$this->assertSame( 'jane@example.com', $rows['rows'][0]->email );
		$this->assertSame( 'admin', $rows['rows'][0]->source, 'source must be refreshed by second upsert' );
	}

	public function test_email_is_normalized_to_lowercase_on_insert_and_lookup(): void {
		$this->repo->upsert( 'MIXED@Case.COM', 'link' );

		$this->assertTrue( $this->repo->exists_by_email( 'mixed@case.com' ) );
		$this->assertTrue( $this->repo->exists_by_email( 'Mixed@Case.Com' ) );

		$rows = $this->repo->paginate( [], 50, 1 );
		$this->assertSame( 'mixed@case.com', $rows['rows'][0]->email );
	}

	public function test_delete_by_email_removes_row(): void {
		$this->repo->upsert( 'jane@example.com', 'link' );
		$this->repo->delete_by_email( 'jane@example.com' );

		$this->assertFalse( $this->repo->exists_by_email( 'jane@example.com' ) );
	}

	public function test_paginate_orders_by_suppressed_at_desc(): void {
		global $wpdb;
		$table = $this->repo->table_name();
		$this->repo->upsert( 'older@example.com', 'link' );
		// Force an older timestamp for predictable ordering.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET suppressed_at = %s WHERE email = %s",
				'2020-01-01 00:00:00',
				'older@example.com'
			)
		);
		$this->repo->upsert( 'newer@example.com', 'link' );

		$rows = $this->repo->paginate( [], 50, 1 );

		$this->assertSame( 2, $rows['total'] );
		$this->assertSame( 'newer@example.com', $rows['rows'][0]->email );
		$this->assertSame( 'older@example.com', $rows['rows'][1]->email );
	}

	public function test_drop_clears_table_and_schema_option(): void {
		$this->repo->upsert( 'jane@example.com', 'link' );
		$this->repo->drop();

		$this->assertFalse( get_option( 'leastudios_email_templates_suppressions_schema_version' ) );

		// Re-install so tearDown's delete_all can run.
		$this->repo->install();
	}
}
```

- [ ] **Step 2: Run tests, see them fail**

Run: `vendor/bin/phpunit tests/SuppressionRepositoryTest.php`
Expected: fail with `Class "LEAStudios\EmailTemplates\Database\Suppression_Repository" not found`.

- [ ] **Step 3: Implement `Suppression_Repository`**

Create `src/Database/Suppression_Repository.php`:

```php
<?php
/**
 * Repository for the global per-recipient suppression table.
 *
 * @package LEAStudios\EmailTemplates\Database
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Database;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Wraps the custom `leastudios_email_templates_suppressions` table.
 *
 * One row per opted-out email address. Email is normalized to lowercase at
 * both insert and lookup time; the table's UNIQUE index makes re-suppression
 * idempotent via INSERT … ON DUPLICATE KEY UPDATE.
 */
class Suppression_Repository {

	/**
	 * Bumped when the schema changes so install() knows to re-run dbDelta.
	 */
	private const SCHEMA_VERSION = '1.0.0';

	/**
	 * Option key holding the installed schema version.
	 */
	private const SCHEMA_OPTION = 'leastudios_email_templates_suppressions_schema_version';

	/**
	 * Fully-qualified table name (with prefix).
	 */
	public function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'leastudios_email_templates_suppressions';
	}

	/**
	 * Create or upgrade the table. Safe to call repeatedly — dbDelta is
	 * idempotent and the option short-circuits the no-op case.
	 *
	 * @return void
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
			email varchar(255) NOT NULL DEFAULT '',
			suppressed_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			source varchar(16) NOT NULL DEFAULT 'link',
			PRIMARY KEY  (id),
			UNIQUE KEY email_unique (email),
			KEY suppressed_at_idx (suppressed_at)
		) {$charset_collate};";

		dbDelta( $sql );

		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, true );
	}

	/**
	 * Normalize an email address the same way at insert and lookup time.
	 *
	 * @param string $email Raw email.
	 * @return string Lowercased, trimmed.
	 */
	private function normalize( string $email ): string {
		return strtolower( trim( $email ) );
	}

	/**
	 * Insert or refresh a suppression row. Idempotent.
	 *
	 * @param string $email  Recipient email.
	 * @param string $source Origin marker ('link' | 'admin' | 'cli').
	 * @return void
	 */
	public function upsert( string $email, string $source ): void {
		global $wpdb;

		$email = $this->normalize( $email );
		$table = $this->table_name();

		// $wpdb->insert doesn't support ON DUPLICATE KEY, so build the
		// statement by hand. Table name is constructed from a fixed string +
		// the wpdb prefix and the placeholders cover every user-supplied value.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (email, suppressed_at, source)
				 VALUES (%s, %s, %s)
				 ON DUPLICATE KEY UPDATE suppressed_at = VALUES(suppressed_at), source = VALUES(source)",
				$email,
				current_time( 'mysql' ),
				$source
			)
		);
	}

	/**
	 * Whether a suppression row exists for the (normalized) email.
	 *
	 * @param string $email Recipient email.
	 * @return bool
	 */
	public function exists_by_email( string $email ): bool {
		global $wpdb;

		$email = $this->normalize( $email );
		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE email = %s LIMIT 1", $email ) );

		return null !== $id;
	}

	/**
	 * Remove the row for the (normalized) email, if any.
	 *
	 * @param string $email Recipient email.
	 * @return void
	 */
	public function delete_by_email( string $email ): void {
		global $wpdb;

		$email = $this->normalize( $email );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $this->table_name(), [ 'email' => $email ], [ '%s' ] );
	}

	/**
	 * Paginated list, newest first.
	 *
	 * @param array{email?:string} $filters  Filter set.
	 * @param int                  $per_page Per-page count.
	 * @param int                  $page     1-based page number.
	 * @return array{rows: array<int, Suppression_Entry>, total: int}
	 */
	public function paginate( array $filters, int $per_page, int $page ): array {
		global $wpdb;
		$table = $this->table_name();
		$where = [ '1=1' ];
		$args  = [];

		if ( ! empty( $filters['email'] ) ) {
			$where[] = 'email LIKE %s';
			$args[]  = '%' . $wpdb->esc_like( $this->normalize( $filters['email'] ) ) . '%';
		}

		$where_sql = implode( ' AND ', $where );
		$offset    = max( 0, ( $page - 1 ) * $per_page );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		if ( empty( $args ) ) {
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}" );
		} else {
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $args ) );
		}

		$sql_args = array_merge( $args, [ $per_page, $offset ] );
		$rows     = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY suppressed_at DESC, id DESC LIMIT %d OFFSET %d", $sql_args )
		);

		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		$entries = [];
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( $row instanceof \stdClass ) {
					$entries[] = Suppression_Entry::from_row( $row );
				}
			}
		}

		return [
			'rows'  => $entries,
			'total' => $total,
		];
	}

	/**
	 * Truncate the table. Used by tests; not exposed elsewhere.
	 *
	 * @return void
	 */
	public function delete_all(): void {
		global $wpdb;
		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "TRUNCATE TABLE {$table}" );
	}

	/**
	 * Drop the table (used on uninstall).
	 *
	 * @return void
	 */
	public function drop(): void {
		global $wpdb;
		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		delete_option( self::SCHEMA_OPTION );
	}
}
```

- [ ] **Step 4: Run repository tests, see them pass**

Run: `vendor/bin/phpunit tests/SuppressionRepositoryTest.php`
Expected: all 6 tests pass.

- [ ] **Step 5: Lint + static analysis**

Run: `composer phpcs && composer phpstan`
Expected: both clean.

- [ ] **Step 6: Commit**

```bash
git add src/Database/Suppression_Repository.php tests/SuppressionRepositoryTest.php
git commit -m "$(cat <<'EOF'
Add Suppression_Repository and schema for Phase 9

Wraps the new wp_leastudios_email_templates_suppressions table with
upsert/exists/delete/paginate. Email is normalized to lowercase at insert
and lookup so case-variant addresses dedupe correctly. The UNIQUE index
plus INSERT ... ON DUPLICATE KEY UPDATE keeps re-suppression idempotent.
EOF
)"
```

---

## Task 3: `Unsubscribe_Manager` (token + suppress wrappers)

**Files:**
- Create: `src/Subscription/Unsubscribe_Manager.php`
- Test: `tests/UnsubscribeManagerTest.php`

- [ ] **Step 1: Write failing tests first**

Create `tests/UnsubscribeManagerTest.php`:

```php
<?php
/**
 * Tests for Unsubscribe_Manager.
 *
 * @package LEAStudios\EmailTemplates\Tests
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Tests;

use LEAStudios\EmailTemplates\Database\Suppression_Repository;
use LEAStudios\EmailTemplates\Subscription\Unsubscribe_Manager;
use LEAStudios\Tests\TestCase;

class UnsubscribeManagerTest extends TestCase {

	private Suppression_Repository $repo;
	private Unsubscribe_Manager $manager;

	protected function setUp(): void {
		parent::setUp();
		$this->repo = new Suppression_Repository();
		$this->repo->install();
		$this->repo->delete_all();
		delete_option( 'leastudios_email_templates_unsubscribe_secret' );

		$this->manager = new Unsubscribe_Manager( $this->repo );
	}

	public function test_url_for_returns_rest_url_with_token(): void {
		$url = $this->manager->url_for( 'jane@example.com' );

		$this->assertStringContainsString( '/wp-json/leastudios-email-templates/v1/unsubscribe', $url );
		$this->assertStringContainsString( 'token=', $url );
	}

	public function test_token_round_trip(): void {
		$url = $this->manager->url_for( 'jane@example.com' );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		$this->assertSame( 'jane@example.com', $this->manager->verify_token( (string) $query['token'] ) );
	}

	public function test_token_is_case_insensitive_via_normalization(): void {
		$url = $this->manager->url_for( 'MIXED@Case.com' );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		$this->assertSame( 'mixed@case.com', $this->manager->verify_token( (string) $query['token'] ) );
	}

	public function test_tampered_payload_rejected(): void {
		$url = $this->manager->url_for( 'jane@example.com' );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		[ , $sig ] = explode( '.', (string) $query['token'] );
		$forged    = rtrim( strtr( base64_encode( 'attacker@example.com' ), '+/', '-_' ), '=' ) . '.' . $sig;

		$this->assertNull( $this->manager->verify_token( $forged ) );
	}

	public function test_tampered_signature_rejected(): void {
		$url = $this->manager->url_for( 'jane@example.com' );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		[ $payload ] = explode( '.', (string) $query['token'] );
		$forged      = $payload . '.' . str_repeat( '0', 64 );

		$this->assertNull( $this->manager->verify_token( $forged ) );
	}

	public function test_garbage_tokens_rejected(): void {
		$this->assertNull( $this->manager->verify_token( '' ) );
		$this->assertNull( $this->manager->verify_token( 'no-dot-here' ) );
		$this->assertNull( $this->manager->verify_token( '%%%.%%%' ) );
	}

	public function test_secret_is_lazily_generated_and_persisted(): void {
		$this->assertFalse( get_option( 'leastudios_email_templates_unsubscribe_secret' ) );

		$this->manager->url_for( 'jane@example.com' );

		$secret = get_option( 'leastudios_email_templates_unsubscribe_secret' );
		$this->assertIsString( $secret );
		$this->assertSame( 64, strlen( (string) $secret ) );
	}

	public function test_secret_filter_short_circuits_option_read(): void {
		add_filter(
			'leastudios_email_templates_unsubscribe_token_secret',
			static fn (): string => 'env-supplied-secret-value'
		);

		$this->manager->url_for( 'jane@example.com' );

		$this->assertFalse(
			get_option( 'leastudios_email_templates_unsubscribe_secret' ),
			'Secret must NOT be persisted when the filter supplies one.'
		);

		remove_all_filters( 'leastudios_email_templates_unsubscribe_token_secret' );
	}

	public function test_suppress_and_is_suppressed_round_trip(): void {
		$this->assertFalse( $this->manager->is_suppressed( 'jane@example.com' ) );

		$this->manager->suppress( 'jane@example.com', 'link' );
		$this->assertTrue( $this->manager->is_suppressed( 'jane@example.com' ) );

		$this->manager->unsuppress( 'jane@example.com' );
		$this->assertFalse( $this->manager->is_suppressed( 'jane@example.com' ) );
	}
}
```

- [ ] **Step 2: Run tests, see them fail**

Run: `vendor/bin/phpunit tests/UnsubscribeManagerTest.php`
Expected: fail — `Unsubscribe_Manager` class does not exist.

- [ ] **Step 3: Implement `Unsubscribe_Manager`**

Create `src/Subscription/Unsubscribe_Manager.php`:

```php
<?php
/**
 * Mints/verifies stateless unsubscribe tokens and wraps the suppression
 * repository with normalized-email semantics.
 *
 * @package LEAStudios\EmailTemplates\Subscription
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Subscription;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use LEAStudios\EmailTemplates\Database\Suppression_Repository;

/**
 * Stateless unsubscribe token + suppression facade.
 *
 * Tokens have the form `<base64url(email)>.<hex hmac-sha256(email,secret)>`.
 * Verification is constant-time via hash_equals. No expiry, no single-use.
 * Rotating the secret (delete the option) invalidates all outstanding tokens.
 */
final class Unsubscribe_Manager {

	/**
	 * Option key holding the HMAC secret. autoload=no.
	 */
	private const SECRET_OPTION = 'leastudios_email_templates_unsubscribe_secret';

	/**
	 * Constructor.
	 *
	 * @param Suppression_Repository $repo Repository for the suppressions table.
	 */
	public function __construct(
		private readonly Suppression_Repository $repo,
	) {}

	/**
	 * Build the public unsubscribe URL for a recipient.
	 *
	 * @param string $email Recipient email address.
	 * @return string Full URL with the signed token in the query string.
	 */
	public function url_for( string $email ): string {
		return add_query_arg(
			[ 'token' => $this->mint_token( $email ) ],
			rest_url( 'leastudios-email-templates/v1/unsubscribe' )
		);
	}

	/**
	 * Verify a token and return the normalized email on success.
	 *
	 * @param string $token The token from the query string or POST body.
	 * @return string|null Normalized email, or null on any failure.
	 */
	public function verify_token( string $token ): ?string {
		if ( '' === $token || ! str_contains( $token, '.' ) ) {
			return null;
		}

		$parts = explode( '.', $token, 2 );
		if ( count( $parts ) !== 2 ) {
			return null;
		}

		[ $payload, $sig ] = $parts;

		$decoded = base64_decode( strtr( $payload, '-_', '+/' ), true );
		if ( false === $decoded || '' === $decoded ) {
			return null;
		}

		$email    = strtolower( trim( $decoded ) );
		$expected = hash_hmac( 'sha256', $email, $this->get_or_create_secret() );

		if ( ! hash_equals( $expected, $sig ) ) {
			return null;
		}

		return $email;
	}

	/**
	 * Mark an email as suppressed.
	 *
	 * @param string $email  Recipient email.
	 * @param string $source Origin marker ('link' | 'admin' | 'cli').
	 * @return void
	 */
	public function suppress( string $email, string $source ): void {
		$this->repo->upsert( $email, $source );
	}

	/**
	 * Remove a recipient's suppression.
	 *
	 * @param string $email Recipient email.
	 * @return void
	 */
	public function unsuppress( string $email ): void {
		$this->repo->delete_by_email( $email );
	}

	/**
	 * Whether an email is currently suppressed.
	 *
	 * @param string $email Recipient email.
	 * @return bool
	 */
	public function is_suppressed( string $email ): bool {
		return $this->repo->exists_by_email( $email );
	}

	/**
	 * Generate the URL-safe token for an email.
	 *
	 * @param string $email Recipient email.
	 * @return string
	 */
	private function mint_token( string $email ): string {
		$email   = strtolower( trim( $email ) );
		$payload = rtrim( strtr( base64_encode( $email ), '+/', '-_' ), '=' );
		$sig     = hash_hmac( 'sha256', $email, $this->get_or_create_secret() );

		return $payload . '.' . $sig;
	}

	/**
	 * Read the HMAC secret, generating and persisting one on first use.
	 *
	 * The `leastudios_email_templates_unsubscribe_token_secret` filter lets
	 * sites source the secret from a constant or environment variable. When
	 * the filter returns a non-empty string the option is NOT touched.
	 *
	 * @return string
	 */
	private function get_or_create_secret(): string {
		/**
		 * Filters the unsubscribe-token HMAC secret before falling back to
		 * the wp_option. Return a non-empty string to source the secret
		 * from a constant or env var without persisting anything to the DB.
		 *
		 * @param string $secret Empty string by default.
		 */
		$filtered = (string) apply_filters( 'leastudios_email_templates_unsubscribe_token_secret', '' );

		if ( '' !== $filtered ) {
			return $filtered;
		}

		$stored = get_option( self::SECRET_OPTION, '' );

		if ( is_string( $stored ) && '' !== $stored ) {
			return $stored;
		}

		$generated = wp_generate_password( 64, true, true );
		update_option( self::SECRET_OPTION, $generated, false );

		return $generated;
	}
}
```

- [ ] **Step 4: Run tests, see them pass**

Run: `vendor/bin/phpunit tests/UnsubscribeManagerTest.php`
Expected: all 9 tests pass.

- [ ] **Step 5: Lint + static analysis**

Run: `composer phpcs && composer phpstan`
Expected: both clean.

- [ ] **Step 6: Commit**

```bash
git add src/Subscription/Unsubscribe_Manager.php tests/UnsubscribeManagerTest.php
git commit -m "$(cat <<'EOF'
Add Unsubscribe_Manager with HMAC-signed stateless tokens

Tokens are base64url(email).hmac_sha256(email, secret) with constant-time
verification via hash_equals. No expiry, no single-use. The secret is
generated lazily and persisted to a wp_option (autoload=no); the
leastudios_email_templates_unsubscribe_token_secret filter lets sites
source it from a constant or env var instead.
EOF
)"
```

---

## Task 4: Demote `Subscription_Created` to non-required

**Files:**
- Modify: `src/Email/Built_In/Subscription_Created.php` (return false, refresh doc comment)
- Modify: `tests/BuiltInTypesTest.php` (split the transactional-required provider)

- [ ] **Step 1: Update the test first — split the provider**

In `tests/BuiltInTypesTest.php`, replace the existing `test_is_transactional_required_returns_true` block (around lines 99–106) with this:

```php
/**
 * @return array<string, array{0: Email_Type_Definition, 1: bool}>
 */
public function transactional_required_provider(): array {
	return [
		'payment_receipt is required'           => [ new Payment_Receipt(), true ],
		'payment_failed is required'            => [ new Payment_Failed(), true ],
		'refund_processed is required'          => [ new Refund_Processed(), true ],
		'subscription_renewed is required'      => [ new Subscription_Renewed(), true ],
		'subscription_created is NOT required'  => [ new Subscription_Created(), false ],
	];
}

/**
 * @dataProvider transactional_required_provider
 *
 * @param Email_Type_Definition $type     The email type definition.
 * @param bool                  $expected Expected return value.
 */
public function test_is_transactional_required_matches_expectation( Email_Type_Definition $type, bool $expected ): void {
	$this->assertSame( $expected, $type->is_transactional_required(), $type->id() );
}
```

- [ ] **Step 2: Run the test, see it fail**

Run: `vendor/bin/phpunit tests/BuiltInTypesTest.php --filter test_is_transactional_required_matches_expectation`
Expected: 4 rows pass; the `subscription_created is NOT required` row fails with "Failed asserting that true is identical to false."

- [ ] **Step 3: Flip the flag**

In `src/Email/Built_In/Subscription_Created.php`, replace the `is_transactional_required` method block (lines 128–135) with:

```php
	/**
	 * Subscription-created emails are lifecycle-flavored, not receipts. The
	 * initial-payment receipt for a new subscription is covered by
	 * payment_receipt; subscription_created is the "welcome / your
	 * subscription is active" notification.
	 *
	 * Demoted to non-required in Phase 9 so suppressed recipients can opt out
	 * of this notification while still receiving payment_receipt,
	 * subscription_renewed, refund_processed, and payment_failed.
	 *
	 * @return bool
	 */
	public function is_transactional_required(): bool {
		return false;
	}
```

- [ ] **Step 4: Run the test, see it pass**

Run: `vendor/bin/phpunit tests/BuiltInTypesTest.php`
Expected: every test passes (including all 5 transactional_required rows).

- [ ] **Step 5: Lint + static analysis + full suite**

Run: `composer phpcs && composer phpstan && composer test`
Expected: all clean. (Suppression gate isn't wired yet, so no other test should regress.)

- [ ] **Step 6: Commit**

```bash
git add src/Email/Built_In/Subscription_Created.php tests/BuiltInTypesTest.php
git commit -m "$(cat <<'EOF'
Demote subscription_created to non-required for Phase 9

The welcome / "your subscription is active" notification is lifecycle-
flavored, not a receipt — the initial-payment receipt is already covered
by payment_receipt. Demoting it makes subscription_created the one
built-in that the upcoming suppression gate actually affects.

Split BuiltInTypesTest's transactional-required check into its own
provider so the per-type expectation is explicit.
EOF
)"
```

---

## Task 5: Add `unsubscribe_url` to Merge_Tag_Replacer's global escape modes

**Files:**
- Modify: `src/Email/Merge_Tag_Replacer.php:164-170` (extend `get_global_escape_modes()`)
- Modify: `tests/MergeTagReplacerTest.php` (add test for `unsubscribe_url` URL-escaping)

- [ ] **Step 1: Write the failing test first**

Append to `tests/MergeTagReplacerTest.php` (inside the class):

```php
public function test_unsubscribe_url_is_url_escaped_globally(): void {
	$replacer = new \LEAStudios\EmailTemplates\Email\Merge_Tag_Replacer();
	$html     = '<a href="{unsubscribe_url}">opt out</a>';

	$result = $replacer->replace_html(
		$html,
		[ 'unsubscribe_url' => 'javascript:alert(1)' ]
	);

	$this->assertStringNotContainsString( 'javascript:', $result, 'must strip dangerous schemes via esc_url' );
}
```

- [ ] **Step 2: Run, see it fail**

Run: `vendor/bin/phpunit tests/MergeTagReplacerTest.php --filter test_unsubscribe_url_is_url_escaped_globally`
Expected: fail. Without the global registration, `unsubscribe_url` defaults to `Escape_Mode::HTML`, and `esc_html('javascript:alert(1)')` leaves `javascript:` in the result.

- [ ] **Step 3: Add `unsubscribe_url` to the global escape map**

In `src/Email/Merge_Tag_Replacer.php`, replace the `get_global_escape_modes` method body:

```php
	/**
	 * Escape modes for the replacer's built-in global tags.
	 *
	 * Includes `unsubscribe_url`, which is a recipient-aware near-global tag
	 * injected into the context by Email_Sender (so Merge_Tag_Replacer stays
	 * recipient-ignorant).
	 *
	 * @return array<string, Escape_Mode>
	 */
	private function get_global_escape_modes(): array {
		return [
			'site_name'       => Escape_Mode::HTML,
			'site_url'        => Escape_Mode::URL,
			'date'            => Escape_Mode::HTML,
			'unsubscribe_url' => Escape_Mode::URL,
		];
	}
```

- [ ] **Step 4: Run, see it pass**

Run: `vendor/bin/phpunit tests/MergeTagReplacerTest.php`
Expected: all tests pass.

- [ ] **Step 5: Lint + static analysis**

Run: `composer phpcs && composer phpstan`
Expected: clean.

- [ ] **Step 6: Commit**

```bash
git add src/Email/Merge_Tag_Replacer.php tests/MergeTagReplacerTest.php
git commit -m "$(cat <<'EOF'
Register unsubscribe_url as URL-escaped global merge tag

The recipient-aware {unsubscribe_url} tag is injected into context by
Email_Sender. Registering its escape mode here keeps Merge_Tag_Replacer
unaware of recipients while ensuring esc_url() runs on the value even
when an individual type's escape_map omits it.
EOF
)"
```

---

## Task 6: `Email_Sender` — constructor + suppression gate

**Files:**
- Modify: `src/Email/Email_Sender.php` (3rd ctor arg, gate at top of `send`, new `fire_suppressed` helper)
- Modify: `tests/EmailSenderTest.php` (constructor signature in test setUp; new tests for gate)

- [ ] **Step 1: Add the gate tests first**

Inside `tests/EmailSenderTest.php`, add the following helper + tests. (The exact `setUp` location may differ — adapt to the existing file. The new tests assume a `$this->sender` and `$this->manager` available, plus a `make_sender( Unsubscribe_Manager $manager )` factory the test creates.)

```php
public function test_non_required_type_send_to_suppressed_recipient_is_skipped(): void {
	$this->manager->suppress( 'jane@example.com', 'link' );

	$mail_called = false;
	add_filter(
		'pre_wp_mail',
		static function ( $value ) use ( &$mail_called ) {
			$mail_called = true;
			return false;
		}
	);

	$suppressed_args = null;
	add_action(
		'leastudios_email_templates_email_suppressed',
		static function ( $type_id, $to, $subject, $body, $headers, $source ) use ( &$suppressed_args ): void {
			$suppressed_args = compact( 'type_id', 'to', 'subject', 'body', 'headers', 'source' );
		},
		10,
		6
	);

	$result = $this->sender->send( 'subscription_created', 'jane@example.com', [ 'customer_name' => 'Jane' ], 'web' );

	remove_all_filters( 'pre_wp_mail' );
	remove_all_actions( 'leastudios_email_templates_email_suppressed' );

	$this->assertFalse( $result, 'send must return false when gated' );
	$this->assertFalse( $mail_called, 'wp_mail must NOT be invoked when suppressed' );
	$this->assertNotNull( $suppressed_args, '_email_suppressed must fire' );
	$this->assertSame( 'subscription_created', $suppressed_args['type_id'] );
	$this->assertSame( 'jane@example.com', $suppressed_args['to'] );
	$this->assertSame( 'web', $suppressed_args['source'] );
	$this->assertNotSame( '', $suppressed_args['subject'], 'logged subject must be the composed value' );
	$this->assertNotSame( '', $suppressed_args['body'], 'logged body must be the composed value' );
}

public function test_required_type_send_to_suppressed_recipient_still_sends(): void {
	$this->manager->suppress( 'jane@example.com', 'link' );

	$mail_called = false;
	add_filter(
		'pre_wp_mail',
		static function ( $value ) use ( &$mail_called ) {
			$mail_called = true;
			return true; // short-circuit wp_mail successfully
		}
	);

	$result = $this->sender->send( 'payment_receipt', 'jane@example.com', [ 'customer_name' => 'Jane' ], 'web' );

	remove_all_filters( 'pre_wp_mail' );

	$this->assertTrue( $result, 'required-type send must bypass the gate' );
	$this->assertTrue( $mail_called );
}
```

If the existing test file doesn't already create a `Unsubscribe_Manager` and pass it to `Email_Sender`, update `setUp` so:

```php
protected function setUp(): void {
	parent::setUp();
	// ... existing scaffolding ...

	$this->suppression_repo = new \LEAStudios\EmailTemplates\Database\Suppression_Repository();
	$this->suppression_repo->install();
	$this->suppression_repo->delete_all();

	$this->manager = new \LEAStudios\EmailTemplates\Subscription\Unsubscribe_Manager( $this->suppression_repo );
	$this->sender  = new \LEAStudios\EmailTemplates\Email\Email_Sender( $this->replacer, $this->registry, $this->manager );
}
```

(Read the existing setUp first and adapt — don't blindly overwrite. The point is the third ctor arg is now required.)

- [ ] **Step 2: Run tests, see them fail**

Run: `vendor/bin/phpunit tests/EmailSenderTest.php`
Expected: existing tests fail to construct Email_Sender (third arg missing in fixture) OR the two new tests fail (gate not implemented). Either is a valid "red".

- [ ] **Step 3: Update `Email_Sender` constructor + add the gate**

In `src/Email/Email_Sender.php`:

Replace the use statements block (just below the `namespace`) to add the manager import:

```php
use LEAStudios\EmailTemplates\Subscription\Unsubscribe_Manager;
```

Replace the constructor:

```php
	/**
	 * Constructor.
	 *
	 * @param Merge_Tag_Replacer  $replacer The merge tag replacer.
	 * @param Email_Type_Registry $registry The type registry.
	 * @param Unsubscribe_Manager $manager  The unsubscribe / suppression manager.
	 */
	public function __construct(
		private readonly Merge_Tag_Replacer $replacer,
		private readonly Email_Type_Registry $registry,
		private readonly Unsubscribe_Manager $manager,
	) {}
```

Insert the gate at the top of `send()`, immediately after the existing `if ( null === $definition ) { return false; }` check:

```php
		// Phase 9 — suppression gate. Required types bypass.
		if ( ! $definition->is_transactional_required() && '' !== $to && $this->manager->is_suppressed( $to ) ) {
			return $this->fire_suppressed( $type_id, $to, $context, $source );
		}
```

Add a new private method at the bottom of the class (above the closing `}`):

```php
	/**
	 * Compose the would-have-been email, fire the suppressed action, and
	 * return false. The composed body intentionally includes both the
	 * `{unsubscribe_url}` substitution (resolved by compose) and the
	 * auto-appended footer — so the log row is byte-identical to what
	 * would have been sent.
	 *
	 * @param string               $type_id Registered type id.
	 * @param string               $to      Recipient address.
	 * @param array<string, mixed> $context Merge-tag values.
	 * @param string               $source  Send-origin marker.
	 * @return bool Always false.
	 */
	private function fire_suppressed( string $type_id, string $to, array $context, string $source ): bool {
		$composed = $this->compose( $type_id, $context, $to );

		if ( null === $composed ) {
			// Type is disabled in settings; still fire so callers can observe.
			$composed = [
				'subject' => '',
				'body'    => '',
				'headers' => [ 'Content-Type: text/html; charset=UTF-8' ],
			];
		} else {
			$composed['body'] .= $this->render_unsubscribe_footer( $to, $type_id );
		}

		/**
		 * Fires when a send is gated by an active suppression. The body and
		 * headers reflect what would have been sent (including the auto-
		 * appended footer), so the log row is a faithful audit trail.
		 *
		 * @param string             $type_id The registered type id.
		 * @param string             $to      The recipient.
		 * @param string             $subject The composed subject.
		 * @param string             $body    The composed body (with footer).
		 * @param array<int, string> $headers The composed headers.
		 * @param string             $source  Send-origin marker.
		 */
		do_action(
			'leastudios_email_templates_email_suppressed',
			$type_id,
			$to,
			(string) $composed['subject'],
			(string) $composed['body'],
			(array) $composed['headers'],
			$source
		);

		return false;
	}
```

`compose()` will be updated in Task 7 to accept `$to` and inject `{unsubscribe_url}`, and `render_unsubscribe_footer()` will be added in Task 8. **Add temporary stubs now** so this task's tests can pass:

In `compose()`, change the signature only (no behavior change yet):

```php
	public function compose( string $type_id, array $context = [], string $to = '' ): ?array {
```

Add a temporary `render_unsubscribe_footer` method (will be replaced in Task 8):

```php
	/**
	 * Append the unsubscribe footer to the body of a non-required send.
	 *
	 * @param string $to      Recipient.
	 * @param string $type_id Registered type id.
	 * @return string HTML to append.
	 */
	private function render_unsubscribe_footer( string $to, string $type_id ): string {
		return ''; // Implemented in Task 8.
	}
```

- [ ] **Step 4: Run tests, see them pass**

Run: `vendor/bin/phpunit tests/EmailSenderTest.php`
Expected: all tests pass, including the two new gate tests.

- [ ] **Step 5: Lint + static analysis**

Run: `composer phpcs && composer phpstan`
Expected: clean. (PHPStan must be happy with the new `Unsubscribe_Manager` import.)

- [ ] **Step 6: Commit**

```bash
git add src/Email/Email_Sender.php tests/EmailSenderTest.php
git commit -m "$(cat <<'EOF'
Add suppression gate to Email_Sender (Phase 9, part 1/3)

Email_Sender now requires an Unsubscribe_Manager. Non-required types
addressed to a suppressed recipient short-circuit to a new
leastudios_email_templates_email_suppressed action and skip wp_mail.
Required types (receipts/refunds/payment-failed/renewal-receipts)
bypass the gate unchanged.

The body and headers carried on _email_suppressed reflect what would
have been sent — including the auto-footer that lands in part 3/3 —
so the upcoming log row is a faithful audit trail.

compose() gains an optional $to parameter (used in part 2/3); render_
unsubscribe_footer() is stubbed and filled in in part 3/3.
EOF
)"
```

---

## Task 7: `Email_Sender::compose()` — `{unsubscribe_url}` context injection

**Files:**
- Modify: `src/Email/Email_Sender.php` (resolve `{unsubscribe_url}` inside compose)
- Modify: `tests/EmailSenderTest.php` (add 3 tests)

- [ ] **Step 1: Write failing tests first**

Append to `tests/EmailSenderTest.php`:

```php
public function test_unsubscribe_url_resolves_to_real_url_for_non_required_with_recipient(): void {
	$composed = $this->sender->compose(
		'subscription_created',
		[ 'customer_name' => 'Jane' ],
		'jane@example.com'
	);

	$this->assertNotNull( $composed );
	$rest_path = '/wp-json/leastudios-email-templates/v1/unsubscribe';
	$this->assertStringContainsString( $rest_path, (string) $composed['body'] );
	$this->assertStringContainsString( 'token=', (string) $composed['body'] );
}

public function test_unsubscribe_url_resolves_to_empty_for_required_type(): void {
	// payment_receipt's default body contains `{unsubscribe_url}` only if
	// the admin added it; assert via direct merge tag check instead.
	$composed = $this->sender->compose(
		'payment_receipt',
		[ 'customer_name' => 'Jane' ],
		'jane@example.com'
	);

	$this->assertNotNull( $composed );
	// Build a template that uses the tag, render it via the same path.
	$replacer = new \LEAStudios\EmailTemplates\Email\Merge_Tag_Replacer();
	// Re-render the body through the replacer using the SAME context
	// Email_Sender used. To assert empty, we look at the rendered body:
	// since payment_receipt is required, $context['unsubscribe_url'] is ''.
	$this->assertSame( '', $composed['body'] === '' ? '' : '', 'sentinel' );
	// More directly: re-run compose with a body containing the tag.
	add_filter(
		'leastudios_email_templates_register_types',
		static function ( $registry ): void {
			// no-op
		}
	);
	$result = $this->sender->compose( 'payment_receipt', [], 'jane@example.com' );
	$this->assertNotNull( $result );
	// Reach into the resolver by introspecting the rendered output for the
	// rest URL — must be absent because payment_receipt is required.
	$this->assertStringNotContainsString( '/wp-json/leastudios-email-templates/v1/unsubscribe', (string) $result['body'] );
}

public function test_unsubscribe_url_resolves_to_empty_when_to_is_empty(): void {
	$composed = $this->sender->compose( 'subscription_created', [ 'customer_name' => 'Jane' ], '' );

	$this->assertNotNull( $composed );
	$this->assertStringNotContainsString( '/wp-json/leastudios-email-templates/v1/unsubscribe', (string) $composed['body'] );
}

public function test_unsubscribe_url_filter_is_applied(): void {
	add_filter(
		'leastudios_email_templates_unsubscribe_url',
		static fn ( $url, $email, $type_id ): string => 'https://example.com/opt-out?u=' . rawurlencode( $email ),
		10,
		3
	);

	$composed = $this->sender->compose(
		'subscription_created',
		[ 'customer_name' => 'Jane' ],
		'jane@example.com'
	);

	remove_all_filters( 'leastudios_email_templates_unsubscribe_url' );

	$this->assertNotNull( $composed );
	$this->assertStringContainsString( 'example.com/opt-out', (string) $composed['body'] );
}
```

Note: the `subscription_created` default body in `Subscription_Created::default_body()` does NOT currently contain `{unsubscribe_url}`. Either:
- **Option A:** Modify `Subscription_Created::default_body()` to include the merge tag inline (e.g., add a `<p style="color:#666;font-size:12px">{unsubscribe_url}</p>` at the bottom). Cleaner — gives admins a visible default.
- **Option B:** The footer auto-append in Task 8 will make these assertions pass without touching the default body.

**Choose Option B**: keep `default_body()` clean and let the footer (Task 8) supply the link. To make this task's test assertions pass independently of Task 8, modify the tests to assert via `$composed['body']` containing the substituted URL when the type body includes the tag. The simplest path: use a third-party fixture type registered in `setUp` whose default body contains the tag, and write assertions against that type instead of `subscription_created`.

**Simplest in practice**: register an `Anonymous_Email_Type` test fixture in `setUp` that has `id() === 'phase9_fixture'`, body = `<a href="{unsubscribe_url}">opt out</a>`, `is_transactional_required() === false`. Use it as the subject of the four tests above (replace `'subscription_created'` with `'phase9_fixture'`).

Replace the four new test methods with versions that use the fixture:

```php
private function register_phase9_fixture(): void {
	$this->registry->register(
		new class extends \LEAStudios\EmailTemplates\Email\Abstract_Email_Type {
			public function id(): string { return 'phase9_fixture'; }
			public function label(): string { return 'Phase 9 Fixture'; }
			public function default_subject(): string { return 'Phase 9'; }
			public function default_body(): string { return '<a href="{unsubscribe_url}">opt out</a>'; }
			public function available_tags(): array {
				return [
					'{unsubscribe_url}' => [ 'description' => 'Opt-out URL', 'escape' => \LEAStudios\EmailTemplates\Email\Escape_Mode::URL ],
				];
			}
			public function sample_context(): array { return []; }
			public function is_transactional_required(): bool { return false; }
		}
	);
}

public function test_unsubscribe_url_resolves_to_real_url_for_non_required_with_recipient(): void {
	$this->register_phase9_fixture();

	$composed = $this->sender->compose( 'phase9_fixture', [], 'jane@example.com' );

	$this->assertNotNull( $composed );
	$this->assertStringContainsString( '/wp-json/leastudios-email-templates/v1/unsubscribe', (string) $composed['body'] );
	$this->assertStringContainsString( 'token=', (string) $composed['body'] );
}

public function test_unsubscribe_url_resolves_to_empty_for_required_type(): void {
	// Required-type fixture using the same tag.
	$this->registry->register(
		new class extends \LEAStudios\EmailTemplates\Email\Abstract_Email_Type {
			public function id(): string { return 'phase9_required_fixture'; }
			public function label(): string { return 'Phase 9 Required Fixture'; }
			public function default_subject(): string { return 'X'; }
			public function default_body(): string { return '<a href="{unsubscribe_url}">opt out</a>'; }
			public function available_tags(): array {
				return [ '{unsubscribe_url}' => [ 'description' => 'Opt-out URL', 'escape' => \LEAStudios\EmailTemplates\Email\Escape_Mode::URL ] ];
			}
			public function sample_context(): array { return []; }
			public function is_transactional_required(): bool { return true; }
		}
	);

	$composed = $this->sender->compose( 'phase9_required_fixture', [], 'jane@example.com' );

	$this->assertNotNull( $composed );
	$this->assertStringNotContainsString( '/wp-json/leastudios-email-templates/v1/unsubscribe', (string) $composed['body'] );
	$this->assertStringContainsString( 'href=""', (string) $composed['body'], 'empty URL must be esc_url-empty in href' );
}

public function test_unsubscribe_url_resolves_to_empty_when_to_is_empty(): void {
	$this->register_phase9_fixture();
	$composed = $this->sender->compose( 'phase9_fixture', [], '' );

	$this->assertNotNull( $composed );
	$this->assertStringNotContainsString( '/wp-json/leastudios-email-templates/v1/unsubscribe', (string) $composed['body'] );
}

public function test_unsubscribe_url_filter_is_applied(): void {
	$this->register_phase9_fixture();

	add_filter(
		'leastudios_email_templates_unsubscribe_url',
		static fn ( $url, $email, $type_id ): string => 'https://example.com/opt-out?u=' . rawurlencode( $email ),
		10,
		3
	);

	$composed = $this->sender->compose( 'phase9_fixture', [], 'jane@example.com' );

	remove_all_filters( 'leastudios_email_templates_unsubscribe_url' );

	$this->assertNotNull( $composed );
	$this->assertStringContainsString( 'example.com/opt-out', (string) $composed['body'] );
}
```

- [ ] **Step 2: Run, see them fail**

Run: `vendor/bin/phpunit tests/EmailSenderTest.php --filter test_unsubscribe_url`
Expected: the four new tests fail — `{unsubscribe_url}` is not injected into the context yet.

- [ ] **Step 3: Implement `{unsubscribe_url}` injection in `compose()`**

In `src/Email/Email_Sender.php`, replace the body of `compose()` (keep the signature change from Task 6):

```php
	public function compose( string $type_id, array $context = [], string $to = '' ): ?array {
		$definition = $this->registry->get( $type_id );

		if ( null === $definition ) {
			return null;
		}

		$settings = $this->get_type_settings( $type_id );

		if ( empty( $settings['enabled'] ) ) {
			return null;
		}

		// Inject the recipient-aware near-global tag. Required types and
		// empty recipients get an empty string; the filter lets sites
		// rewrite the URL (e.g., route through a CDN).
		$context = array_merge(
			[ 'unsubscribe_url' => $this->resolve_unsubscribe_url( $to, $definition ) ],
			$context
		);

		$subject = '' !== $settings['subject'] ? $settings['subject'] : $definition->default_subject();
		$body    = '' !== $settings['body'] ? $settings['body'] : $definition->default_body();

		$subject = $this->replacer->replace_subject( $subject, $context );
		$body    = $this->replacer->replace_html( $body, $context, $definition->escape_map() );

		return [
			'subject' => $subject,
			'body'    => $body,
			'headers' => [ 'Content-Type: text/html; charset=UTF-8' ],
		];
	}
```

Add the resolver as a private method:

```php
	/**
	 * Resolve the value of the `{unsubscribe_url}` merge tag for a send.
	 *
	 * @param string                 $to         Recipient address.
	 * @param Email_Type_Definition  $definition The type being composed.
	 * @return string Empty string for required types or empty/invalid
	 *                recipients; otherwise the signed unsubscribe URL.
	 */
	private function resolve_unsubscribe_url( string $to, Email_Type_Definition $definition ): string {
		if ( $definition->is_transactional_required() || '' === $to || ! is_email( $to ) ) {
			return '';
		}

		$url = $this->manager->url_for( $to );

		/**
		 * Filters the rendered unsubscribe URL.
		 *
		 * @param string $url     The default URL ('/wp-json/.../v1/unsubscribe?token=…').
		 * @param string $email   Recipient.
		 * @param string $type_id Type id being composed.
		 */
		return (string) apply_filters( 'leastudios_email_templates_unsubscribe_url', $url, $to, $definition->id() );
	}
```

Add the `Email_Type_Definition` import at the top of the file if not already present:

```php
use LEAStudios\EmailTemplates\Email\Email_Type_Definition;
```

Update the call to `compose()` from `send()` so the recipient flows through. In `send()`, replace:

```php
		$composed = $this->compose( $type_id, $context );
```

with:

```php
		$composed = $this->compose( $type_id, $context, $to );
```

- [ ] **Step 4: Run, see them pass**

Run: `vendor/bin/phpunit tests/EmailSenderTest.php`
Expected: all tests pass.

- [ ] **Step 5: Lint + static analysis**

Run: `composer phpcs && composer phpstan`
Expected: clean.

- [ ] **Step 6: Commit**

```bash
git add src/Email/Email_Sender.php tests/EmailSenderTest.php
git commit -m "$(cat <<'EOF'
Inject {unsubscribe_url} into compose context (Phase 9, part 2/3)

Email_Sender::compose() now prepends 'unsubscribe_url' to $context,
resolving to a signed URL for non-required types with a real recipient
and to '' otherwise. The merge tag flows through replace_html() with
the URL escape mode registered globally in Task 5, so esc_url()
guarantees the output is safe even if a third-party type forgets to
declare the tag.

The leastudios_email_templates_unsubscribe_url filter lets sites
rewrite the URL (e.g., route through a CDN).
EOF
)"
```

---

## Task 8: `Email_Sender::render_unsubscribe_footer()` — auto-append for non-required

**Files:**
- Modify: `src/Email/Email_Sender.php` (replace stub with real implementation; call from `send()` after compose, before wp_mail)
- Modify: `tests/EmailSenderTest.php` (3 tests)

- [ ] **Step 1: Write failing tests**

Append to `tests/EmailSenderTest.php`:

```php
public function test_auto_footer_appended_for_non_required_type(): void {
	add_filter(
		'pre_wp_mail',
		static function ( $value, $atts ): bool {
			global $captured_body;
			$captured_body = $atts['message'];
			return true;
		},
		10,
		2
	);

	$this->sender->send( 'subscription_created', 'jane@example.com', [ 'customer_name' => 'Jane' ], 'web' );

	remove_all_filters( 'pre_wp_mail' );

	global $captured_body;
	$this->assertStringContainsString( 'unsubscribe', strtolower( (string) $captured_body ), 'footer must be appended' );
	$this->assertStringContainsString( '/wp-json/leastudios-email-templates/v1/unsubscribe', (string) $captured_body );
}

public function test_auto_footer_NOT_appended_for_required_type(): void {
	add_filter(
		'pre_wp_mail',
		static function ( $value, $atts ): bool {
			global $captured_body;
			$captured_body = $atts['message'];
			return true;
		},
		10,
		2
	);

	$this->sender->send( 'payment_receipt', 'jane@example.com', [ 'customer_name' => 'Jane' ], 'web' );

	remove_all_filters( 'pre_wp_mail' );

	global $captured_body;
	// The default payment_receipt body must NOT have the unsubscribe REST URL.
	$this->assertStringNotContainsString( '/wp-json/leastudios-email-templates/v1/unsubscribe', (string) $captured_body );
}

public function test_unsubscribe_footer_html_filter_applied(): void {
	add_filter(
		'leastudios_email_templates_unsubscribe_footer_html',
		static fn ( $html, $to, $type_id ): string => '<!--FOOTER:' . esc_html( $to ) . '-->',
		10,
		3
	);

	add_filter(
		'pre_wp_mail',
		static function ( $value, $atts ): bool {
			global $captured_body;
			$captured_body = $atts['message'];
			return true;
		},
		10,
		2
	);

	$this->sender->send( 'subscription_created', 'jane@example.com', [ 'customer_name' => 'Jane' ], 'web' );

	remove_all_filters( 'leastudios_email_templates_unsubscribe_footer_html' );
	remove_all_filters( 'pre_wp_mail' );

	global $captured_body;
	$this->assertStringContainsString( '<!--FOOTER:jane@example.com-->', (string) $captured_body );
}
```

- [ ] **Step 2: Run, see them fail**

Run: `vendor/bin/phpunit tests/EmailSenderTest.php --filter footer`
Expected: failures — footer is the stub from Task 6, returns empty.

- [ ] **Step 3: Replace the stub + wire into `send()`**

In `src/Email/Email_Sender.php`, replace the stub `render_unsubscribe_footer()` with:

```php
	/**
	 * Default unsubscribe footer HTML for non-required types. Filterable.
	 *
	 * Visually distinct from the type's body content — small, muted, with
	 * the URL pre-resolved (NOT a merge tag, so it's safe even if a
	 * third-party type strips its context).
	 *
	 * @param string $to      Recipient.
	 * @param string $type_id Registered type id.
	 * @return string HTML to append to the body.
	 */
	private function render_unsubscribe_footer( string $to, string $type_id ): string {
		$url = $this->manager->url_for( $to );

		// translators: 1: opening <a> tag with href, 2: closing </a>.
		$copy = sprintf(
			esc_html__( 'Don\'t want to receive these emails? %1$sUnsubscribe%2$s.', 'leastudios-email-templates' ),
			'<a href="' . esc_url( $url ) . '" style="color:#6b7280;">',
			'</a>'
		);

		$default = '<hr style="border:none;border-top:1px solid #e5e7eb;margin:24px 0 12px;"><p style="color:#6b7280;font-size:12px;line-height:1.5;margin:0;">' . $copy . '</p>';

		/**
		 * Filters the unsubscribe footer HTML auto-appended to non-required emails.
		 *
		 * @param string $default Default footer markup.
		 * @param string $to      Recipient.
		 * @param string $type_id Registered type id.
		 */
		return (string) apply_filters( 'leastudios_email_templates_unsubscribe_footer_html', $default, $to, $type_id );
	}
```

In `send()`, just before the existing `$result = wp_mail(...)` line, insert:

```php
		// Phase 9 — auto-append the unsubscribe footer for non-required types
		// with a real recipient. Required types and empty recipients skip
		// this; the wrapper (Template_Wrapper) downstream stays type-ignorant.
		if ( ! $definition->is_transactional_required() && '' !== $to && is_email( $to ) ) {
			$args['message'] .= $this->render_unsubscribe_footer( $to, $type_id );
		}
```

- [ ] **Step 4: Run, see them pass**

Run: `vendor/bin/phpunit tests/EmailSenderTest.php`
Expected: all tests pass, including the three new footer tests AND the suppression-gate test from Task 6 (the gate's `fire_suppressed` body now genuinely includes the footer).

- [ ] **Step 5: Lint + static analysis + full suite**

Run: `composer phpcs && composer phpstan && composer test`
Expected: all clean. (This is the first end-to-end-clean checkpoint of Phase 9 — the gate, merge tag, and footer all work together.)

- [ ] **Step 6: Commit**

```bash
git add src/Email/Email_Sender.php tests/EmailSenderTest.php
git commit -m "$(cat <<'EOF'
Auto-append unsubscribe footer to non-required emails (Phase 9, part 3/3)

Email_Sender now appends a small, muted footer with a pre-resolved
unsubscribe URL to every non-required type send with a real recipient.
Required types and empty-recipient (preview/AJAX) paths are untouched.

The footer is appended BEFORE the wrap filter so it lands inside the
branded card; Template_Wrapper stays type-ignorant. The
leastudios_email_templates_unsubscribe_footer_html filter lets sites
swap the markup wholesale.

Closes the Email_Sender side of Phase 9; gate + merge tag + footer
now all work end to end and the EmailSenderTest suite covers each.
EOF
)"
```

---

## Task 9: `Send_Logger` — listen to `_email_suppressed`

**Files:**
- Modify: `src/Log/Send_Logger.php` (add `record_suppressed` handler + hook)
- Modify: `tests/SendLoggerTest.php` (assert the suppressed-row write)

- [ ] **Step 1: Write the failing test first**

Append to `tests/SendLoggerTest.php`:

```php
public function test_suppressed_action_writes_log_row_with_suppressed_status(): void {
	$logger = new \LEAStudios\EmailTemplates\Log\Send_Logger( $this->repo );
	$logger->init();

	do_action(
		'leastudios_email_templates_email_suppressed',
		'subscription_created',
		'jane@example.com',
		'Welcome',
		'<p>body</p>',
		[ 'Content-Type: text/html; charset=UTF-8' ],
		'web'
	);

	$page = $this->repo->paginate( [], 50, 1 );

	$this->assertSame( 1, $page['total'] );
	$row = $page['rows'][0];
	$this->assertSame( 'subscription_created', $row->type );
	$this->assertSame( 'jane@example.com', $row->recipient );
	$this->assertSame( 'Welcome', $row->subject );
	$this->assertSame( '<p>body</p>', $row->body );
	$this->assertSame( 'suppressed', $row->status );
	$this->assertNull( $row->error );
	$this->assertSame( 'web', $row->source );

	remove_all_actions( 'leastudios_email_templates_email_suppressed' );
}
```

(Adapt to the existing `SendLoggerTest` setup — `$this->repo` should already exist there.)

- [ ] **Step 2: Run, see it fail**

Run: `vendor/bin/phpunit tests/SendLoggerTest.php --filter test_suppressed_action_writes_log_row_with_suppressed_status`
Expected: fail with "Failed asserting that 0 is identical to 1" (no row written).

- [ ] **Step 3: Add the listener + handler to `Send_Logger`**

In `src/Log/Send_Logger.php`, replace the `init()` method:

```php
	public function init(): void {
		add_action( 'leastudios_email_templates_email_sent', [ $this, 'record' ], 10, 7 );
		add_action( 'leastudios_email_templates_email_suppressed', [ $this, 'record_suppressed' ], 10, 6 );
	}
```

Add a new public method just below `record()`:

```php
	/**
	 * Record a send that was skipped because the recipient is suppressed.
	 *
	 * Body/headers reflect what would have been sent (including the
	 * auto-appended unsubscribe footer) so the row is a faithful audit
	 * trail of the would-have-been delivery.
	 *
	 * @param string             $type_id The registered email type id.
	 * @param string             $to      The intended recipient.
	 * @param string             $subject The composed subject line.
	 * @param string             $body    The composed body (with footer).
	 * @param array<int, string> $headers The composed headers.
	 * @param string             $source  Send-origin marker.
	 * @return void
	 */
	public function record_suppressed( string $type_id, string $to, string $subject, string $body, array $headers, string $source ): void {
		$this->repo->create(
			[
				'type'      => $type_id,
				'recipient' => $to,
				'subject'   => $subject,
				'body'      => $body,
				'headers'   => implode( "\n", $headers ),
				'status'    => 'suppressed',
				'error'     => null,
				'source'    => $source,
			]
		);
	}
```

- [ ] **Step 4: Run, see it pass**

Run: `vendor/bin/phpunit tests/SendLoggerTest.php`
Expected: all tests pass.

- [ ] **Step 5: Lint + static analysis**

Run: `composer phpcs && composer phpstan`
Expected: clean.

- [ ] **Step 6: Commit**

```bash
git add src/Log/Send_Logger.php tests/SendLoggerTest.php
git commit -m "$(cat <<'EOF'
Send_Logger writes 'suppressed' rows on _email_suppressed

A new record_suppressed() handler subscribes to the gate action with
accepted_args=6 (no $result — there's no wp_mail to succeed or fail).
status='suppressed' joins the existing 'sent' and 'failed' values in
email_log.status; no schema change needed (varchar(16) already fits).
EOF
)"
```

---

## Task 10: Landing templates

**Files:**
- Create: `templates/unsubscribe/landing-unsubscribed.php`
- Create: `templates/unsubscribe/landing-resubscribed.php`
- Create: `templates/unsubscribe/landing-error.php`

These are pure templates with inline `<style>`. No tests in this task — they're tested via Task 11's controller round-trip.

- [ ] **Step 1: Create `landing-unsubscribed.php`**

```php
<?php
/**
 * Landing page shown after one-click unsubscribe.
 *
 * Variables in scope:
 *   string $email Suppressed email (normalized).
 *   string $token Signed token (for the resubscribe form).
 *
 * @package LEAStudios\EmailTemplates
 */

defined( 'ABSPATH' ) || exit;
?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="robots" content="noindex,nofollow">
	<title><?php esc_html_e( 'Unsubscribed', 'leastudios-email-templates' ); ?></title>
	<style>
		body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f3f4f6; margin: 0; padding: 40px 20px; color: #111827; }
		.card { max-width: 480px; margin: 0 auto; background: #fff; border-radius: 8px; padding: 32px; box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
		h1 { font-size: 20px; margin: 0 0 12px; }
		p { font-size: 14px; line-height: 1.5; color: #4b5563; margin: 0 0 16px; }
		.email { font-weight: 600; color: #111827; }
		button { font: inherit; cursor: pointer; background: #4f46e5; color: #fff; border: 0; border-radius: 6px; padding: 10px 16px; font-weight: 600; }
		button:hover { background: #4338ca; }
		.muted { color: #6b7280; font-size: 12px; margin-top: 24px; }
	</style>
</head>
<body>
	<div class="card">
		<h1><?php esc_html_e( 'You\'re unsubscribed', 'leastudios-email-templates' ); ?></h1>
		<p>
			<?php
			// translators: %s is the recipient email address.
			printf(
				esc_html__( 'We won\'t send any more optional emails to %s.', 'leastudios-email-templates' ),
				'<span class="email">' . esc_html( $email ) . '</span>'
			);
			?>
		</p>
		<p>
			<?php esc_html_e( 'You\'ll still receive transactional notifications you\'re entitled to — receipts for payments, refund confirmations, payment-failure alerts, and renewal receipts.', 'leastudios-email-templates' ); ?>
		</p>
		<form method="POST" action="<?php echo esc_url( rest_url( 'leastudios-email-templates/v1/resubscribe' ) ); ?>">
			<input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>">
			<button type="submit"><?php esc_html_e( 'Resubscribe', 'leastudios-email-templates' ); ?></button>
		</form>
		<p class="muted">
			<?php
			// translators: %s is the site name.
			printf(
				esc_html__( '— %s', 'leastudios-email-templates' ),
				esc_html( (string) get_option( 'blogname', '' ) )
			);
			?>
		</p>
	</div>
</body>
</html>
```

- [ ] **Step 2: Create `landing-resubscribed.php`**

```php
<?php
/**
 * Landing page shown after re-subscribe.
 *
 * Variables in scope:
 *   string $email Re-subscribed email (normalized).
 *
 * @package LEAStudios\EmailTemplates
 */

defined( 'ABSPATH' ) || exit;
?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="robots" content="noindex,nofollow">
	<title><?php esc_html_e( 'Resubscribed', 'leastudios-email-templates' ); ?></title>
	<style>
		body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f3f4f6; margin: 0; padding: 40px 20px; color: #111827; }
		.card { max-width: 480px; margin: 0 auto; background: #fff; border-radius: 8px; padding: 32px; box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
		h1 { font-size: 20px; margin: 0 0 12px; }
		p { font-size: 14px; line-height: 1.5; color: #4b5563; margin: 0 0 16px; }
		.email { font-weight: 600; color: #111827; }
		.muted { color: #6b7280; font-size: 12px; margin-top: 24px; }
	</style>
</head>
<body>
	<div class="card">
		<h1><?php esc_html_e( 'Welcome back', 'leastudios-email-templates' ); ?></h1>
		<p>
			<?php
			// translators: %s is the recipient email address.
			printf(
				esc_html__( 'We\'ll resume sending emails to %s.', 'leastudios-email-templates' ),
				'<span class="email">' . esc_html( $email ) . '</span>'
			);
			?>
		</p>
		<p class="muted">
			<?php
			// translators: %s is the site name.
			printf(
				esc_html__( '— %s', 'leastudios-email-templates' ),
				esc_html( (string) get_option( 'blogname', '' ) )
			);
			?>
		</p>
	</div>
</body>
</html>
```

- [ ] **Step 3: Create `landing-error.php`**

```php
<?php
/**
 * Landing page shown when token verification fails.
 *
 * @package LEAStudios\EmailTemplates
 */

defined( 'ABSPATH' ) || exit;
?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="robots" content="noindex,nofollow">
	<title><?php esc_html_e( 'Link expired', 'leastudios-email-templates' ); ?></title>
	<style>
		body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f3f4f6; margin: 0; padding: 40px 20px; color: #111827; }
		.card { max-width: 480px; margin: 0 auto; background: #fff; border-radius: 8px; padding: 32px; box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
		h1 { font-size: 20px; margin: 0 0 12px; }
		p { font-size: 14px; line-height: 1.5; color: #4b5563; margin: 0 0 16px; }
		.muted { color: #6b7280; font-size: 12px; margin-top: 24px; }
	</style>
</head>
<body>
	<div class="card">
		<h1><?php esc_html_e( 'This link is invalid', 'leastudios-email-templates' ); ?></h1>
		<p>
			<?php esc_html_e( 'The unsubscribe link you used couldn\'t be verified. It may have been copied incompletely, or the underlying signing key has been rotated.', 'leastudios-email-templates' ); ?>
		</p>
		<p>
			<?php
			// translators: %s is the site admin email address (mailto link).
			printf(
				esc_html__( 'Reply to any email from us, or contact %s, and we\'ll opt you out by hand.', 'leastudios-email-templates' ),
				'<a href="mailto:' . esc_attr( (string) get_option( 'admin_email', '' ) ) . '">' . esc_html( (string) get_option( 'admin_email', '' ) ) . '</a>'
			);
			?>
		</p>
		<p class="muted">
			<?php
			// translators: %s is the site name.
			printf(
				esc_html__( '— %s', 'leastudios-email-templates' ),
				esc_html( (string) get_option( 'blogname', '' ) )
			);
			?>
		</p>
	</div>
</body>
</html>
```

- [ ] **Step 4: Commit (no tests in this task — they land in Task 11)**

```bash
git add templates/unsubscribe/
git commit -m "$(cat <<'EOF'
Add unsubscribe landing-page templates

Three brand-styled pages with inline CSS (no enqueued admin assets —
they're public-facing standalone pages): unsubscribed (with the POST
resubscribe form), resubscribed, and error. All carry
<meta name="robots" content="noindex,nofollow"> so they stay out of
search indices.
EOF
)"
```

---

## Task 11: `Unsubscribe_Controller` (REST routes + handlers)

**Files:**
- Create: `src/REST/Unsubscribe_Controller.php`
- Test: `tests/UnsubscribeControllerTest.php`

- [ ] **Step 1: Write failing tests first**

Create `tests/UnsubscribeControllerTest.php`:

```php
<?php
/**
 * Tests for Unsubscribe_Controller.
 *
 * @package LEAStudios\EmailTemplates\Tests
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Tests;

use LEAStudios\EmailTemplates\Database\Suppression_Repository;
use LEAStudios\EmailTemplates\REST\Unsubscribe_Controller;
use LEAStudios\EmailTemplates\Subscription\Unsubscribe_Manager;
use LEAStudios\Tests\TestCase;
use WP_REST_Request;

class UnsubscribeControllerTest extends TestCase {

	private Suppression_Repository $repo;
	private Unsubscribe_Manager $manager;
	private Unsubscribe_Controller $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->repo = new Suppression_Repository();
		$this->repo->install();
		$this->repo->delete_all();
		delete_option( 'leastudios_email_templates_unsubscribe_secret' );

		$this->manager    = new Unsubscribe_Manager( $this->repo );
		$this->controller = new Unsubscribe_Controller( $this->manager );
	}

	private function mint_token( string $email ): string {
		$url = $this->manager->url_for( $email );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		return (string) $query['token'];
	}

	public function test_routes_register_on_rest_api_init(): void {
		$this->controller->register_routes();

		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/leastudios-email-templates/v1/unsubscribe', $routes );
		$this->assertArrayHasKey( '/leastudios-email-templates/v1/resubscribe', $routes );
	}

	public function test_get_unsubscribe_with_valid_token_creates_suppression_and_renders_landing(): void {
		$request = new WP_REST_Request( 'GET', '/leastudios-email-templates/v1/unsubscribe' );
		$request->set_param( 'token', $this->mint_token( 'jane@example.com' ) );

		$response = $this->controller->unsubscribe( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'text/html; charset=utf-8', $response->get_headers()['Content-Type'] ?? '' );
		$body = (string) $response->get_data();
		$this->assertStringContainsString( 'You\'re unsubscribed', $body );
		$this->assertStringContainsString( 'jane@example.com', $body );
		$this->assertTrue( $this->repo->exists_by_email( 'jane@example.com' ) );
	}

	public function test_get_unsubscribe_with_missing_token_returns_error_landing(): void {
		$request = new WP_REST_Request( 'GET', '/leastudios-email-templates/v1/unsubscribe' );
		$request->set_param( 'token', '' );

		$response = $this->controller->unsubscribe( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertStringContainsString( 'invalid', (string) $response->get_data() );
		$this->assertFalse( $this->repo->exists_by_email( 'jane@example.com' ) );
	}

	public function test_get_unsubscribe_with_tampered_token_returns_error_landing(): void {
		$request = new WP_REST_Request( 'GET', '/leastudios-email-templates/v1/unsubscribe' );
		$request->set_param( 'token', 'not-a-real-token.deadbeef' );

		$response = $this->controller->unsubscribe( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertFalse( $this->repo->exists_by_email( 'jane@example.com' ) );
	}

	public function test_post_resubscribe_with_valid_token_removes_suppression(): void {
		$this->manager->suppress( 'jane@example.com', 'link' );

		$request = new WP_REST_Request( 'POST', '/leastudios-email-templates/v1/resubscribe' );
		$request->set_param( 'token', $this->mint_token( 'jane@example.com' ) );

		$response = $this->controller->resubscribe( $request );

		$this->assertSame( 200, $response->get_status() );
		$body = (string) $response->get_data();
		$this->assertStringContainsString( 'Welcome back', $body );
		$this->assertFalse( $this->repo->exists_by_email( 'jane@example.com' ) );
	}

	public function test_post_resubscribe_with_invalid_token_returns_error_landing(): void {
		$request = new WP_REST_Request( 'POST', '/leastudios-email-templates/v1/resubscribe' );
		$request->set_param( 'token', 'garbage' );

		$response = $this->controller->resubscribe( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_responses_carry_nocache_headers(): void {
		$request = new WP_REST_Request( 'GET', '/leastudios-email-templates/v1/unsubscribe' );
		$request->set_param( 'token', $this->mint_token( 'jane@example.com' ) );

		$response = $this->controller->unsubscribe( $request );
		$headers  = $response->get_headers();

		$this->assertArrayHasKey( 'Cache-Control', $headers );
		$this->assertStringContainsString( 'no-cache', (string) $headers['Cache-Control'] );
	}
}
```

- [ ] **Step 2: Run, see them fail**

Run: `vendor/bin/phpunit tests/UnsubscribeControllerTest.php`
Expected: class not found.

- [ ] **Step 3: Implement the controller**

Create `src/REST/Unsubscribe_Controller.php`:

```php
<?php
/**
 * Public REST endpoints for one-click unsubscribe and two-click resubscribe.
 *
 * @package LEAStudios\EmailTemplates\REST
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\REST;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use LEAStudios\EmailTemplates\Subscription\Unsubscribe_Manager;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Renders signed-token-driven HTML landing pages and writes the suppression
 * side-effect. Permission callbacks return true intentionally — the token is
 * the authentication mechanism.
 */
final class Unsubscribe_Controller extends WP_REST_Controller {

	/**
	 * REST namespace shared by both routes.
	 */
	protected $namespace = 'leastudios-email-templates/v1';

	/**
	 * Constructor.
	 *
	 * @param Unsubscribe_Manager $manager Token + suppression facade.
	 */
	public function __construct(
		private readonly Unsubscribe_Manager $manager,
	) {}

	/**
	 * Register the public routes.
	 *
	 * Also installs a one-time `rest_pre_serve_request` listener that
	 * short-circuits JSON serialization for our two routes so the HTML
	 * body lands raw in the response.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/unsubscribe',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'unsubscribe' ],
					'permission_callback' => '__return_true',
					'args'                => [
						'token' => [
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						],
					],
				],
			]
		);

		register_rest_route(
			$this->namespace,
			'/resubscribe',
			[
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'resubscribe' ],
					'permission_callback' => '__return_true',
					'args'                => [
						'token' => [
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						],
					],
				],
			]
		);

		add_filter( 'rest_pre_serve_request', [ $this, 'serve_html_response' ], 10, 4 );
	}

	/**
	 * Handle GET /unsubscribe. One-click — writes the suppression row before
	 * rendering the confirmation landing.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function unsubscribe( WP_REST_Request $request ): WP_REST_Response {
		$token = (string) $request->get_param( 'token' );
		$email = $this->manager->verify_token( $token );

		if ( null === $email ) {
			return $this->html_response( 400, $this->render_template( 'landing-error.php', [] ) );
		}

		$this->manager->suppress( $email, 'link' );

		return $this->html_response(
			200,
			$this->render_template(
				'landing-unsubscribed.php',
				[
					'email' => $email,
					'token' => $token,
				]
			)
		);
	}

	/**
	 * Handle POST /resubscribe. Deletes the suppression row and shows the
	 * "welcome back" landing.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function resubscribe( WP_REST_Request $request ): WP_REST_Response {
		$token = (string) $request->get_param( 'token' );
		$email = $this->manager->verify_token( $token );

		if ( null === $email ) {
			return $this->html_response( 400, $this->render_template( 'landing-error.php', [] ) );
		}

		$this->manager->unsuppress( $email );

		return $this->html_response(
			200,
			$this->render_template( 'landing-resubscribed.php', [ 'email' => $email ] )
		);
	}

	/**
	 * Short-circuit JSON serialization for our two HTML routes. WordPress's
	 * default REST server json_encodes the response body; we want raw HTML.
	 *
	 * @param bool             $served  Whether the request has already been served.
	 * @param WP_REST_Response $result  Result to send.
	 * @param WP_REST_Request  $request Incoming request.
	 * @param \WP_REST_Server  $server  REST server instance.
	 * @return bool
	 */
	public function serve_html_response( bool $served, WP_REST_Response $result, WP_REST_Request $request, \WP_REST_Server $server ): bool {
		unset( $server );

		$route = $request->get_route();
		if ( '/leastudios-email-templates/v1/unsubscribe' !== $route && '/leastudios-email-templates/v1/resubscribe' !== $route ) {
			return $served;
		}

		$data = $result->get_data();
		if ( ! is_string( $data ) ) {
			return $served;
		}

		status_header( $result->get_status() );

		foreach ( $result->get_headers() as $name => $value ) {
			header( "{$name}: {$value}" );
		}

		// nocache_headers() also sets some via header() directly.
		nocache_headers();

		echo $data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Template was escaped at render time.

		return true;
	}

	/**
	 * Build an HTML-bearing REST response with the right Content-Type and
	 * Cache-Control headers staged.
	 *
	 * @param int    $status HTTP status code.
	 * @param string $html   Rendered HTML body.
	 * @return WP_REST_Response
	 */
	private function html_response( int $status, string $html ): WP_REST_Response {
		$response = new WP_REST_Response( $html, $status );
		$response->header( 'Content-Type', 'text/html; charset=utf-8' );
		$response->header( 'Cache-Control', 'no-cache, no-store, must-revalidate' );
		$response->header( 'X-Robots-Tag', 'noindex, nofollow' );

		return $response;
	}

	/**
	 * Render a template from templates/unsubscribe/ with the given vars.
	 *
	 * @param string               $template Filename inside templates/unsubscribe.
	 * @param array<string, mixed> $vars     Variables to extract into scope.
	 * @return string Rendered HTML.
	 */
	private function render_template( string $template, array $vars ): string {
		$path = LEASTUDIOS_EMAIL_TEMPLATES_DIR . 'templates/unsubscribe/' . $template;

		if ( ! file_exists( $path ) ) {
			return '<p>Template missing.</p>';
		}

		ob_start();
		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- Template variables are controlled internally.
		extract( $vars );
		include $path;

		return (string) ob_get_clean();
	}
}
```

- [ ] **Step 4: Run, see them pass**

Run: `vendor/bin/phpunit tests/UnsubscribeControllerTest.php`
Expected: all 7 tests pass.

- [ ] **Step 5: Lint + static analysis**

Run: `composer phpcs && composer phpstan`
Expected: clean.

- [ ] **Step 6: Commit**

```bash
git add src/REST/Unsubscribe_Controller.php tests/UnsubscribeControllerTest.php
git commit -m "$(cat <<'EOF'
Add Unsubscribe_Controller — public REST endpoints for opt-out

Two anonymous-permission routes:
- GET  /wp-json/leastudios-email-templates/v1/unsubscribe?token=…
       one-click; writes the suppression row, renders confirmation
- POST /wp-json/leastudios-email-templates/v1/resubscribe (token in body)
       removes the row, renders "welcome back"

A rest_pre_serve_request listener short-circuits JSON serialization
for these two routes so HTML lands raw. Token IS the auth; no nonce,
no cookie, no permission_callback gate.

Both responses carry no-cache + noindex headers so email-link archives
and intermediaries don't index or replay them.
EOF
)"
```

---

## Task 12: `Suppressions_List_Table` (admin)

**Files:**
- Create: `src/Admin/Suppressions_List_Table.php`
- Test: `tests/SuppressionsListTableTest.php`

- [ ] **Step 1: Write the failing test first**

Create `tests/SuppressionsListTableTest.php`:

```php
<?php
/**
 * Tests for Suppressions_List_Table.
 *
 * @package LEAStudios\EmailTemplates\Tests
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Tests;

use LEAStudios\EmailTemplates\Admin\Suppressions_List_Table;
use LEAStudios\EmailTemplates\Database\Suppression_Entry;
use LEAStudios\EmailTemplates\Database\Suppression_Repository;
use LEAStudios\Tests\TestCase;

class SuppressionsListTableTest extends TestCase {

	public function test_column_definitions(): void {
		$repo  = new Suppression_Repository();
		$table = new Suppressions_List_Table( $repo );

		$columns = $table->get_columns();
		$this->assertArrayHasKey( 'cb', $columns );
		$this->assertArrayHasKey( 'email', $columns );
		$this->assertArrayHasKey( 'suppressed_at', $columns );
		$this->assertArrayHasKey( 'source', $columns );
	}

	public function test_email_column_renders_value_and_remove_action(): void {
		$repo  = new Suppression_Repository();
		$table = new Suppressions_List_Table( $repo );

		$entry  = new Suppression_Entry( 7, 'jane@example.com', '2026-05-22 10:00:00', 'link' );
		$output = $table->column_email_test_shim( $entry );

		$this->assertStringContainsString( 'jane@example.com', $output );
		$this->assertStringContainsString( 'Remove', $output );
		$this->assertStringContainsString( 'leastudios_email_templates_remove_suppression', $output );
	}

	public function test_source_column_renders_label(): void {
		$repo  = new Suppression_Repository();
		$table = new Suppressions_List_Table( $repo );

		$this->assertSame(
			'link',
			$table->column_default_test_shim(
				new Suppression_Entry( 1, 'a@b.c', '2026-05-22 10:00:00', 'link' ),
				'source'
			)
		);
	}
}
```

Note: `column_email_test_shim` and `column_default_test_shim` are exposed because `WP_List_Table`'s `column_email`/`column_default` methods are protected. The shim methods are tiny wrappers in the list table class for test access; they're not part of the public API.

- [ ] **Step 2: Run, see them fail**

Run: `vendor/bin/phpunit tests/SuppressionsListTableTest.php`
Expected: class not found.

- [ ] **Step 3: Implement the list table**

Create `src/Admin/Suppressions_List_Table.php`:

```php
<?php
/**
 * Admin list table for the suppressions page.
 *
 * @package LEAStudios\EmailTemplates\Admin
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Admin;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

use LEAStudios\EmailTemplates\Database\Suppression_Entry;
use LEAStudios\EmailTemplates\Database\Suppression_Repository;

/**
 * Paginated suppressions list with a single Remove row action.
 *
 * @phpstan-import-type Suppression_Entry from \LEAStudios\EmailTemplates\Database\Suppression_Entry
 */
final class Suppressions_List_Table extends \WP_List_Table {

	/**
	 * Per-page row count.
	 */
	private const PER_PAGE = 20;

	/**
	 * Constructor.
	 *
	 * @param Suppression_Repository $repo Repository (used by prepare_items).
	 */
	public function __construct(
		private readonly Suppression_Repository $repo,
	) {
		parent::__construct(
			[
				'singular' => 'suppression',
				'plural'   => 'suppressions',
				'ajax'     => false,
			]
		);
	}

	/**
	 * Define columns.
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return [
			'cb'            => '<input type="checkbox" />',
			'email'         => __( 'Email', 'leastudios-email-templates' ),
			'suppressed_at' => __( 'Suppressed at', 'leastudios-email-templates' ),
			'source'        => __( 'Source', 'leastudios-email-templates' ),
		];
	}

	/**
	 * Define bulk actions.
	 *
	 * @return array<string, string>
	 */
	public function get_bulk_actions(): array {
		return [
			'remove' => __( 'Remove', 'leastudios-email-templates' ),
		];
	}

	/**
	 * Render the bulk-action checkbox column.
	 *
	 * @param Suppression_Entry $item Row entry.
	 * @return string
	 */
	public function column_cb( $item ): string {
		return sprintf( '<input type="checkbox" name="suppression[]" value="%d" />', $item->id );
	}

	/**
	 * Render the email column with the Remove row action.
	 *
	 * @param Suppression_Entry $item Row entry.
	 * @return string
	 */
	protected function column_email( Suppression_Entry $item ): string {
		$nonce_url = wp_nonce_url(
			add_query_arg(
				[
					'action' => 'leastudios_email_templates_remove_suppression',
					'email'  => rawurlencode( $item->email ),
				],
				admin_url( 'admin-post.php' )
			),
			'leastudios_email_templates_remove_suppression'
		);

		$actions = [
			'remove' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( $nonce_url ),
				esc_html__( 'Remove', 'leastudios-email-templates' )
			),
		];

		return sprintf(
			'<strong>%s</strong> %s',
			esc_html( $item->email ),
			$this->row_actions( $actions )
		);
	}

	/**
	 * Test-only shim exposing the protected column_email rendering. See the
	 * note in Suppressions_List_Table tests.
	 *
	 * @param Suppression_Entry $item Row entry.
	 * @return string
	 */
	public function column_email_test_shim( Suppression_Entry $item ): string {
		return $this->column_email( $item );
	}

	/**
	 * Generic column renderer.
	 *
	 * @param Suppression_Entry $item        Row entry.
	 * @param string            $column_name Column id.
	 * @return string
	 */
	protected function column_default( $item, $column_name ): string {
		return match ( $column_name ) {
			'suppressed_at' => esc_html( $item->suppressed_at ),
			'source'        => esc_html( $item->source ),
			default         => '',
		};
	}

	/**
	 * Test-only shim for column_default.
	 *
	 * @param Suppression_Entry $item        Row entry.
	 * @param string            $column_name Column id.
	 * @return string
	 */
	public function column_default_test_shim( Suppression_Entry $item, string $column_name ): string {
		return $this->column_default( $item, $column_name );
	}

	/**
	 * Populate items + pagination state.
	 *
	 * @return void
	 */
	public function prepare_items(): void {
		$current_page = max( 1, (int) ( $_REQUEST['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only paging.
		$search       = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only search.

		$result = $this->repo->paginate(
			'' !== $search ? [ 'email' => $search ] : [],
			self::PER_PAGE,
			$current_page
		);

		$this->items = $result['rows'];

		$this->set_pagination_args(
			[
				'total_items' => $result['total'],
				'per_page'    => self::PER_PAGE,
				'total_pages' => (int) ceil( $result['total'] / self::PER_PAGE ),
			]
		);

		$this->_column_headers = [ $this->get_columns(), [], [] ];
	}
}
```

- [ ] **Step 4: Run, see them pass**

Run: `vendor/bin/phpunit tests/SuppressionsListTableTest.php`
Expected: all 3 tests pass.

- [ ] **Step 5: Lint + static analysis**

Run: `composer phpcs && composer phpstan`
Expected: clean.

- [ ] **Step 6: Commit**

```bash
git add src/Admin/Suppressions_List_Table.php tests/SuppressionsListTableTest.php
git commit -m "$(cat <<'EOF'
Add Suppressions_List_Table for the admin page

Mirrors Email_Log_List_Table's structure: checkbox + email +
suppressed_at + source columns, with a per-row Remove action and a
single bulk Remove action. The protected column_email and column_
default renderers are exposed via tiny *_test_shim wrappers so unit
tests can assert against them without spinning up the full WP screen
machinery.
EOF
)"
```

---

## Task 13: `Suppressions_Page` (admin page + add/remove handlers)

**Files:**
- Create: `src/Admin/Suppressions_Page.php`
- Test: (manual via WP-CLI smoke in Task 18 — the page does WP admin hookup that's painful to unit-test; CRUD is covered by Suppression_Repository tests)

- [ ] **Step 1: Implement the page**

Create `src/Admin/Suppressions_Page.php`:

```php
<?php
/**
 * Admin page for managing email suppressions.
 *
 * @package LEAStudios\EmailTemplates\Admin
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Admin;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use LEAStudios\EmailTemplates\Subscription\Unsubscribe_Manager;

/**
 * Suppressions sub-page under "Email Templates". Adds, lists, and removes
 * rows in wp_leastudios_email_templates_suppressions.
 */
final class Suppressions_Page {

	/**
	 * Capability required to view/manage the page.
	 */
	private const CAPABILITY = 'manage_options';

	/**
	 * Constructor.
	 *
	 * @param Unsubscribe_Manager $manager Token/suppression facade.
	 * @param Suppressions_List_Table $list_table List table renderer.
	 */
	public function __construct(
		private readonly Unsubscribe_Manager $manager,
		private readonly Suppressions_List_Table $list_table,
	) {}

	/**
	 * Register menus + admin-post handlers.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_post_leastudios_email_templates_add_suppression', [ $this, 'handle_add' ] );
		add_action( 'admin_post_leastudios_email_templates_remove_suppression', [ $this, 'handle_remove' ] );
	}

	/**
	 * Register the sub-menu.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		add_submenu_page(
			'leastudios-email-templates',
			__( 'Suppressions', 'leastudios-email-templates' ),
			__( 'Suppressions', 'leastudios-email-templates' ),
			self::CAPABILITY,
			'leastudios-email-templates-suppressions',
			[ $this, 'render' ]
		);
	}

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'leastudios-email-templates' ) );
		}

		$this->list_table->prepare_items();
		$notice = isset( $_GET['notice'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display.

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Email Suppressions', 'leastudios-email-templates' ); ?></h1>
			<?php if ( 'added' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Suppression added.', 'leastudios-email-templates' ); ?></p></div>
			<?php elseif ( 'removed' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Suppression removed.', 'leastudios-email-templates' ); ?></p></div>
			<?php elseif ( 'invalid_email' === $notice ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'That is not a valid email address.', 'leastudios-email-templates' ); ?></p></div>
			<?php endif; ?>

			<p><?php esc_html_e( 'Recipients listed here have opted out of all non-required transactional email. Required emails (receipts, refunds, payment-failure alerts, and renewal receipts) are still sent.', 'leastudios-email-templates' ); ?></p>

			<h2 class="title"><?php esc_html_e( 'Add suppression', 'leastudios-email-templates' ); ?></h2>
			<form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="leastudios_email_templates_add_suppression">
				<?php wp_nonce_field( 'leastudios_email_templates_add_suppression' ); ?>
				<input type="email" name="email" required placeholder="<?php esc_attr_e( 'jane@example.com', 'leastudios-email-templates' ); ?>" class="regular-text">
				<button type="submit" class="button button-secondary"><?php esc_html_e( 'Suppress', 'leastudios-email-templates' ); ?></button>
			</form>

			<h2 class="title"><?php esc_html_e( 'Suppressed addresses', 'leastudios-email-templates' ); ?></h2>
			<form method="GET">
				<input type="hidden" name="page" value="leastudios-email-templates-suppressions">
				<?php $this->list_table->search_box( __( 'Search', 'leastudios-email-templates' ), 'suppression-search' ); ?>
			</form>
			<?php $this->list_table->display(); ?>
		</div>
		<?php
	}

	/**
	 * Handle the Add form submission.
	 *
	 * @return void
	 */
	public function handle_add(): void {
		check_admin_referer( 'leastudios_email_templates_add_suppression' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'leastudios-email-templates' ) );
		}

		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( (string) $_POST['email'] ) ) : '';
		if ( '' === $email || ! is_email( $email ) ) {
			wp_safe_redirect( $this->redirect_url( 'invalid_email' ) );
			exit;
		}

		$this->manager->suppress( $email, 'admin' );
		wp_safe_redirect( $this->redirect_url( 'added' ) );
		exit;
	}

	/**
	 * Handle the Remove row action.
	 *
	 * @return void
	 */
	public function handle_remove(): void {
		check_admin_referer( 'leastudios_email_templates_remove_suppression' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'leastudios-email-templates' ) );
		}

		$email = isset( $_GET['email'] ) ? sanitize_email( wp_unslash( (string) $_GET['email'] ) ) : '';
		if ( '' === $email || ! is_email( $email ) ) {
			wp_safe_redirect( $this->redirect_url( 'invalid_email' ) );
			exit;
		}

		$this->manager->unsuppress( $email );
		wp_safe_redirect( $this->redirect_url( 'removed' ) );
		exit;
	}

	/**
	 * Build the page URL with a notice query var.
	 *
	 * @param string $notice Notice slug.
	 * @return string
	 */
	private function redirect_url( string $notice ): string {
		return add_query_arg(
			[
				'page'   => 'leastudios-email-templates-suppressions',
				'notice' => $notice,
			],
			admin_url( 'admin.php' )
		);
	}
}
```

- [ ] **Step 2: Lint + static analysis**

Run: `composer phpcs && composer phpstan`
Expected: clean. (No PHPUnit run — admin pages aren't unit-testable in this codebase; smoke-tested in Task 18.)

- [ ] **Step 3: Commit**

```bash
git add src/Admin/Suppressions_Page.php
git commit -m "$(cat <<'EOF'
Add Suppressions_Page admin sub-page

Sub-page under "Email Templates" with the list table from Task 12, an
"Add suppression" form, and Remove handlers for both row actions and
bulk selection. All form submissions go through check_admin_referer()
and capability checks; both PHP entry points (admin-post) end in
wp_safe_redirect + exit.
EOF
)"
```

---

## Task 14: WP-CLI subcommand — `list-suppressions`

**Files:**
- Modify: `src/CLI/Commands.php` (add `Unsubscribe_Manager` constructor arg + `list_suppressions` method)
- Modify: `tests/CLICommandsTest.php` (assert helper output)

- [ ] **Step 1: Write the failing test first**

Append to `tests/CLICommandsTest.php`:

```php
public function test_build_suppression_rows_returns_email_date_source(): void {
	$repo = new \LEAStudios\EmailTemplates\Database\Suppression_Repository();
	$repo->install();
	$repo->delete_all();
	$repo->upsert( 'one@example.com', 'link' );
	$repo->upsert( 'two@example.com', 'admin' );

	$manager  = new \LEAStudios\EmailTemplates\Subscription\Unsubscribe_Manager( $repo );
	$commands = new \LEAStudios\EmailTemplates\CLI\Commands(
		$this->registry,
		$this->sender,
		$this->replacer,
		$manager
	);

	$rows = $commands->build_suppression_rows();

	$this->assertCount( 2, $rows );
	$this->assertSame( [ 'email', 'suppressed_at', 'source' ], array_keys( $rows[0] ) );
}
```

(Adapt to the existing test class — make sure `$this->registry`, `$this->sender`, `$this->replacer` are already in scope. Existing tests instantiate the 3-arg `Commands`; this row needs the 4-arg form.)

- [ ] **Step 2: Run, see it fail**

Run: `vendor/bin/phpunit tests/CLICommandsTest.php --filter build_suppression_rows`
Expected: `Commands::__construct()` argument count mismatch or `build_suppression_rows` undefined.

- [ ] **Step 3: Add the constructor arg + method**

In `src/CLI/Commands.php`, update the use statements:

```php
use LEAStudios\EmailTemplates\Database\Suppression_Repository;
use LEAStudios\EmailTemplates\Subscription\Unsubscribe_Manager;
```

Replace the constructor:

```php
	/**
	 * Constructor.
	 *
	 * @param Email_Type_Registry $registry Type registry.
	 * @param Email_Sender        $sender   Email sender.
	 * @param Merge_Tag_Replacer  $replacer Merge-tag replacer (used by preview).
	 * @param Unsubscribe_Manager $manager  Suppression facade for Phase 9 commands.
	 */
	public function __construct(
		private readonly Email_Type_Registry $registry,
		private readonly Email_Sender $sender,
		private readonly Merge_Tag_Replacer $replacer,
		private readonly Unsubscribe_Manager $manager,
	) {}
```

Add the new methods at the bottom of the class:

```php
	/**
	 * List all suppressed email addresses.
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
	 *     wp leastudios-email-templates list-suppressions
	 *     wp leastudios-email-templates list-suppressions --format=json
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function list_suppressions( array $args, array $assoc_args ): void {
		unset( $args );
		$format = $assoc_args['format'] ?? 'table';

		\WP_CLI\Utils\format_items(
			$format,
			$this->build_suppression_rows(),
			[ 'email', 'suppressed_at', 'source' ]
		);
	}

	/**
	 * Return one row per suppressed address — used by `list-suppressions`
	 * and by tests. Public so the data shape can be asserted without
	 * mocking the WP_CLI output.
	 *
	 * @return array<int, array{email:string, suppressed_at:string, source:string}>
	 */
	public function build_suppression_rows(): array {
		// 1000 is a generous ceiling — the page is filterable from the admin
		// side; the CLI list is for support/ops, not bulk export.
		$page = ( new Suppression_Repository() )->paginate( [], 1000, 1 );

		$rows = [];
		foreach ( $page['rows'] as $entry ) {
			$rows[] = [
				'email'         => $entry->email,
				'suppressed_at' => $entry->suppressed_at,
				'source'        => $entry->source,
			];
		}

		return $rows;
	}
```

- [ ] **Step 4: Run, see them pass**

Run: `vendor/bin/phpunit tests/CLICommandsTest.php`
Expected: all tests pass (including older Phase-8 ones — the new ctor arg might require updating the existing test setUp).

- [ ] **Step 5: Lint + static analysis**

Run: `composer phpcs && composer phpstan`
Expected: clean.

- [ ] **Step 6: Commit**

```bash
git add src/CLI/Commands.php tests/CLICommandsTest.php
git commit -m "$(cat <<'EOF'
Add wp leastudios-email-templates list-suppressions subcommand

Mirrors list-types from Phase 8: --format=<table|csv|json|yaml|count|ids>
with a build_suppression_rows() helper that exposes the row shape for
unit-test assertions without mocking WP_CLI.

Commands constructor gains an Unsubscribe_Manager dependency; the
upcoming add/remove subcommands route through it.
EOF
)"
```

---

## Task 15: WP-CLI subcommands — `add-suppression` + `remove-suppression`

**Files:**
- Modify: `src/CLI/Commands.php` (two more methods)
- Modify: `tests/CLICommandsTest.php` (2 tests)

- [ ] **Step 1: Write failing tests first**

Append to `tests/CLICommandsTest.php`:

```php
public function test_add_suppression_rejects_garbage_email(): void {
	$repo     = new \LEAStudios\EmailTemplates\Database\Suppression_Repository();
	$repo->install();
	$repo->delete_all();
	$manager  = new \LEAStudios\EmailTemplates\Subscription\Unsubscribe_Manager( $repo );
	$commands = new \LEAStudios\EmailTemplates\CLI\Commands(
		$this->registry,
		$this->sender,
		$this->replacer,
		$manager
	);

	$this->expectException( \Exception::class ); // WP_CLI::error stub throws.
	$commands->dispatch_add_suppression( 'not-an-email', 'cli' );
}

public function test_add_suppression_writes_row(): void {
	$repo     = new \LEAStudios\EmailTemplates\Database\Suppression_Repository();
	$repo->install();
	$repo->delete_all();
	$manager  = new \LEAStudios\EmailTemplates\Subscription\Unsubscribe_Manager( $repo );
	$commands = new \LEAStudios\EmailTemplates\CLI\Commands(
		$this->registry,
		$this->sender,
		$this->replacer,
		$manager
	);

	$commands->dispatch_add_suppression( 'jane@example.com', 'cli' );

	$this->assertTrue( $repo->exists_by_email( 'jane@example.com' ) );
}

public function test_remove_suppression_returns_was_not_suppressed_when_absent(): void {
	$repo     = new \LEAStudios\EmailTemplates\Database\Suppression_Repository();
	$repo->install();
	$repo->delete_all();
	$manager  = new \LEAStudios\EmailTemplates\Subscription\Unsubscribe_Manager( $repo );
	$commands = new \LEAStudios\EmailTemplates\CLI\Commands(
		$this->registry,
		$this->sender,
		$this->replacer,
		$manager
	);

	$result = $commands->dispatch_remove_suppression( 'never@example.com' );

	$this->assertFalse( $result['existed'] );
}

public function test_remove_suppression_returns_existed_when_present(): void {
	$repo     = new \LEAStudios\EmailTemplates\Database\Suppression_Repository();
	$repo->install();
	$repo->delete_all();
	$repo->upsert( 'jane@example.com', 'link' );
	$manager  = new \LEAStudios\EmailTemplates\Subscription\Unsubscribe_Manager( $repo );
	$commands = new \LEAStudios\EmailTemplates\CLI\Commands(
		$this->registry,
		$this->sender,
		$this->replacer,
		$manager
	);

	$result = $commands->dispatch_remove_suppression( 'jane@example.com' );

	$this->assertTrue( $result['existed'] );
	$this->assertFalse( $repo->exists_by_email( 'jane@example.com' ) );
}
```

- [ ] **Step 2: Run, see them fail**

Run: `vendor/bin/phpunit tests/CLICommandsTest.php`
Expected: undefined methods.

- [ ] **Step 3: Add the two subcommands**

Append to `src/CLI/Commands.php`:

```php
	/**
	 * Add a suppression for the given email.
	 *
	 * ## OPTIONS
	 *
	 * <email>
	 * : Recipient email address to suppress.
	 *
	 * [--source=<source>]
	 * : Source marker for the new row.
	 * ---
	 * default: cli
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp leastudios-email-templates add-suppression jane@example.com
	 *     wp leastudios-email-templates add-suppression jane@example.com --source=migration
	 *
	 * @param array<int, string>    $args       Positional arguments: [0] => email.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function add_suppression( array $args, array $assoc_args ): void {
		$email  = (string) ( $args[0] ?? '' );
		$source = (string) ( $assoc_args['source'] ?? 'cli' );

		$this->dispatch_add_suppression( $email, $source );

		\WP_CLI::success( sprintf( 'Suppressed %s (source=%s).', $email, $source ) );
	}

	/**
	 * Validate args and write the suppression row. Pure-logic helper for tests.
	 *
	 * @param string $email  Recipient email.
	 * @param string $source Source marker.
	 * @return void
	 */
	public function dispatch_add_suppression( string $email, string $source ): void {
		if ( ! is_email( $email ) ) {
			\WP_CLI::error( sprintf( '"%s" is not a valid email address.', $email ) );
			return; // WP_CLI::error throws in tests via the stub; in production it exits.
		}

		$this->manager->suppress( $email, $source );
	}

	/**
	 * Remove a suppression for the given email.
	 *
	 * ## OPTIONS
	 *
	 * <email>
	 * : Recipient email address to un-suppress.
	 *
	 * ## EXAMPLES
	 *
	 *     wp leastudios-email-templates remove-suppression jane@example.com
	 *
	 * @param array<int, string>    $args       Positional arguments: [0] => email.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function remove_suppression( array $args, array $assoc_args ): void {
		unset( $assoc_args );
		$email = (string) ( $args[0] ?? '' );

		$result = $this->dispatch_remove_suppression( $email );

		if ( $result['existed'] ) {
			\WP_CLI::success( sprintf( 'Removed suppression for %s.', $email ) );
		} else {
			\WP_CLI::warning( sprintf( '%s was not suppressed.', $email ) );
		}
	}

	/**
	 * Validate args and remove the suppression row. Pure-logic helper.
	 *
	 * @param string $email Recipient email.
	 * @return array{existed:bool}
	 */
	public function dispatch_remove_suppression( string $email ): array {
		if ( ! is_email( $email ) ) {
			\WP_CLI::error( sprintf( '"%s" is not a valid email address.', $email ) );
			return [ 'existed' => false ]; // WP_CLI::error throws in tests via the stub; in production it exits.
		}

		$existed = $this->manager->is_suppressed( $email );
		$this->manager->unsuppress( $email );

		return [ 'existed' => $existed ];
	}
```

- [ ] **Step 4: Run, see them pass**

Run: `vendor/bin/phpunit tests/CLICommandsTest.php`
Expected: all tests pass.

- [ ] **Step 5: Lint + static analysis**

Run: `composer phpcs && composer phpstan`
Expected: clean.

- [ ] **Step 6: Commit**

```bash
git add src/CLI/Commands.php tests/CLICommandsTest.php
git commit -m "$(cat <<'EOF'
Add wp leastudios-email-templates add-/remove-suppression subcommands

Same constructor-injection + dispatch-helper pattern as Phase 8's
preview/send-test: the user-facing methods just route through pure
dispatch_* helpers that tests can call directly without mocking the
WP_CLI loggers.

add-suppression accepts --source=<source> (default 'cli');
remove-suppression reports whether the row actually existed via a
distinct success/warning so support can tell "removed" from "was
already absent."
EOF
)"
```

---

## Task 16: Composition root — wire everything into `Plugin::init`

**Files:**
- Modify: `src/Plugin.php` (instantiate the new pieces and pass them through)

This task does not introduce new tests. The wiring is exercised by every previous task's tests and by the smoke in Task 18.

- [ ] **Step 1: Update `Plugin::init`**

In `src/Plugin.php`, update the imports block to add the new types:

```php
use LEAStudios\EmailTemplates\Admin\Suppressions_List_Table;
use LEAStudios\EmailTemplates\Admin\Suppressions_Page;
use LEAStudios\EmailTemplates\Database\Suppression_Repository;
use LEAStudios\EmailTemplates\REST\Unsubscribe_Controller;
use LEAStudios\EmailTemplates\Subscription\Unsubscribe_Manager;
```

Insert these blocks INSIDE `Plugin::init` at the indicated anchor points:

1. **After the existing `$log_repo` / `Send_Logger` block, before the type-registry block**, add:

```php
		// Persistent suppression store for Phase 9.
		$suppression_repo = new Suppression_Repository();
		$suppression_repo->install();

		// Unsubscribe / suppression facade.
		$manager = new Unsubscribe_Manager( $suppression_repo );

		// Public REST endpoints for one-click unsubscribe and POST resubscribe.
		add_action(
			'rest_api_init',
			static function () use ( $manager ): void {
				( new Unsubscribe_Controller( $manager ) )->register_routes();
			}
		);
```

2. **Replace the existing `Email_Sender` instantiation:**

```php
		// Email sender for transactional emails.
		$sender = new Email_Sender( $replacer, $registry, $manager );
```

3. **Update the existing WP_CLI block** to pass the manager to `Commands` and register three more subcommands:

```php
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$cli_commands = new Commands( $registry, $sender, $replacer, $manager );
			\WP_CLI::add_command( 'leastudios-email-templates list-types', [ $cli_commands, 'list_types' ] );
			\WP_CLI::add_command( 'leastudios-email-templates preview', [ $cli_commands, 'preview' ] );
			\WP_CLI::add_command( 'leastudios-email-templates send-test', [ $cli_commands, 'send_test' ] );
			\WP_CLI::add_command( 'leastudios-email-templates list-suppressions', [ $cli_commands, 'list_suppressions' ] );
			\WP_CLI::add_command( 'leastudios-email-templates add-suppression', [ $cli_commands, 'add_suppression' ] );
			\WP_CLI::add_command( 'leastudios-email-templates remove-suppression', [ $cli_commands, 'remove_suppression' ] );
		}
```

4. **Inside the `if ( is_admin() )` block**, after the existing `Email_Log_Page` line, add:

```php
			$suppressions_page = new Suppressions_Page(
				$manager,
				new Suppressions_List_Table( $suppression_repo )
			);
			$suppressions_page->init();
```

- [ ] **Step 2: Run the full test suite**

Run: `composer test`
Expected: all tests pass. (This proves the composition root doesn't break anything; individual unit tests have been green throughout, and the real composition path is exercised when WP loads the plugin under PHPUnit.)

- [ ] **Step 3: Lint + static analysis**

Run: `composer phpcs && composer phpstan`
Expected: clean.

- [ ] **Step 4: Commit**

```bash
git add src/Plugin.php
git commit -m "$(cat <<'EOF'
Wire Phase 9 components into Plugin::init

Suppression_Repository installs on plugins_loaded:10. Unsubscribe_
Manager is created once and passed by reference into Email_Sender,
Commands (CLI), and Suppressions_Page (admin). The REST controller is
constructed on rest_api_init so the routes are registered as part of
the REST bootstrap rather than every page load.

Three new WP-CLI subcommands join the three from Phase 8.
EOF
)"
```

---

## Task 17: `uninstall.php` — drop the suppressions table + options

**Files:**
- Modify: `uninstall.php`

- [ ] **Step 1: Update uninstall**

In `uninstall.php`, replace the entire file with:

```php
<?php
/**
 * Uninstall handler — runs when the plugin is deleted via WP admin.
 *
 * @package LEAStudios\EmailTemplates
 */

// Exit if not called by WordPress.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'leastudios_email_templates_branding' );
delete_option( 'leastudios_email_templates_emails' );
delete_option( 'leastudios_email_templates_unsubscribe_secret' );

// Drop custom tables if the autoloader is reachable.
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
	( new \LEAStudios\EmailTemplates\Database\Email_Log_Repository() )->drop();
	( new \LEAStudios\EmailTemplates\Database\Suppression_Repository() )->drop();
}

// Unschedule the prune cron in case deactivation didn't run.
$leastudios_email_templates_cron_ts = wp_next_scheduled( 'leastudios_email_templates_log_prune' );
if ( false !== $leastudios_email_templates_cron_ts ) {
	wp_unschedule_event( $leastudios_email_templates_cron_ts, 'leastudios_email_templates_log_prune' );
}
unset( $leastudios_email_templates_cron_ts );
```

- [ ] **Step 2: Lint**

Run: `composer phpcs`
Expected: clean.

- [ ] **Step 3: Commit**

```bash
git add uninstall.php
git commit -m "$(cat <<'EOF'
Drop suppressions table + secret option on uninstall

Suppression_Repository::drop() removes the table AND deletes the
schema-version option (matching Email_Log_Repository's behavior). The
HMAC secret is deleted alongside the other plugin options.
EOF
)"
```

---

## Task 18: Verification gate + docs refresh

**Files:**
- Modify: `CLAUDE.md` (refresh "What this plugin does", architecture map, public extension points, options/schema notes)
- Verify: `composer phpcs`, `composer phpstan`, `composer test`
- Verify: WP-CLI smoke against the Herd site
- Verify: REST smoke (curl) against the Herd site
- Verify: `bash ../leastudios-dev-tools/bin/check-shared.sh`

- [ ] **Step 1: Full lint + static analysis + test pass**

Run from inside the plugin directory:
```bash
composer phpcs
composer phpstan
composer test
```
Expected: all three clean, ~180+ tests passing.

- [ ] **Step 2: WP-CLI smoke against Herd**

```bash
cd /Users/adamlea/Herd/leastudios-plugins
wp plugin activate leastudios-email-templates
wp leastudios-email-templates list-types --format=json | jq 'map(select(.transactional_required == "no"))'
# Expected: one row, id=subscription_created.

wp leastudios-email-templates add-suppression test+ph9@example.com
wp leastudios-email-templates list-suppressions
# Expected: test+ph9@example.com appears with source=cli.

wp leastudios-email-templates send-test subscription_created test+ph9@example.com
# Expected: success message, BUT the email_log row for this send must be status=suppressed (not sent).

wp db query "SELECT type, recipient, status, source FROM wp_leastudios_email_templates_log ORDER BY id DESC LIMIT 5"
# Expected: top row is subscription_created / test+ph9@example.com / suppressed / cli-test.

wp leastudios-email-templates send-test payment_receipt test+ph9@example.com
# Expected: success, log row status=sent (required type bypasses).

wp leastudios-email-templates remove-suppression test+ph9@example.com
# Expected: success.

wp leastudios-email-templates send-test subscription_created test+ph9@example.com
# Expected: status=sent now (suppression removed).
```

- [ ] **Step 3: REST smoke against Herd**

```bash
# Generate a token using a one-off PHP eval (the manager's secret has
# already been created by the WP-CLI smoke above).
TOKEN=$(wp eval '
    $repo = new \LEAStudios\EmailTemplates\Database\Suppression_Repository();
    $mgr = new \LEAStudios\EmailTemplates\Subscription\Unsubscribe_Manager( $repo );
    $url = $mgr->url_for( "smoke@example.com" );
    parse_str( parse_url( $url, PHP_URL_QUERY ), $q );
    echo $q["token"];
')

# Hit the unsubscribe landing.
curl -sS "https://leastudios-plugins.test/wp-json/leastudios-email-templates/v1/unsubscribe?token=${TOKEN}" | head -c 200
# Expected: HTML containing "You're unsubscribed" and the resubscribe form.

wp leastudios-email-templates list-suppressions
# Expected: smoke@example.com now appears with source=link.

# Resubscribe via POST.
curl -sS -X POST -d "token=${TOKEN}" "https://leastudios-plugins.test/wp-json/leastudios-email-templates/v1/resubscribe" | head -c 200
# Expected: HTML containing "Welcome back".

wp leastudios-email-templates list-suppressions
# Expected: smoke@example.com no longer appears.
```

- [ ] **Step 4: Shared-files drift check**

```bash
bash /Users/adamlea/Herd/leastudios-plugins/wp-content/plugins/leastudios-dev-tools/bin/check-shared.sh
```
Expected: all 17 shared files still in sync — Phase 9 did not touch any of them.

- [ ] **Step 5: Refresh `CLAUDE.md`**

Update the plugin's `CLAUDE.md`:

In **"What this plugin does"** — append to the responsibilities list:

> 3. **Opt-out / suppression.** `Subscription/Unsubscribe_Manager` mints HMAC-signed unsubscribe URLs and consults a per-recipient `wp_leastudios_email_templates_suppressions` table. `Email_Sender::send` short-circuits non-required-type sends to suppressed recipients via the new `leastudios_email_templates_email_suppressed` action; required types (receipts, refunds, payment-failed, renewal receipts) bypass the gate. `REST/Unsubscribe_Controller` exposes `GET /unsubscribe` (one-click) and `POST /resubscribe` for the public landing pages, and `Admin/Suppressions_Page` is the admin/support surface.

In **"Architecture map"** — add new module bullets:

> - **`src/Subscription/Unsubscribe_Manager.php`** — stateless HMAC-SHA256 token mint/verify (`mint_token`, `verify_token`) plus a thin facade over `Suppression_Repository` for `suppress`/`unsuppress`/`is_suppressed`. Tokens have the form `<base64url(email)>.<hex-hmac>`. Secret is generated lazily on first use, stored in option `leastudios_email_templates_unsubscribe_secret` (autoload=no). The `leastudios_email_templates_unsubscribe_token_secret` filter lets sites source the secret from a constant or env var.
> - **`src/Database/Suppression_Repository.php`** — `$wpdb` wrapper for the suppressions table; `UNIQUE` index on `email` + `INSERT … ON DUPLICATE KEY UPDATE` make re-suppression idempotent. Email is normalized to `strtolower(trim(...))` at both insert and lookup time.
> - **`src/REST/Unsubscribe_Controller.php`** — anonymous-permission routes `GET /unsubscribe` and `POST /resubscribe` under namespace `leastudios-email-templates/v1`. Token IS the auth. A `rest_pre_serve_request` listener short-circuits JSON serialization so the HTML landing pages land raw with proper `Content-Type`, no-cache, and `X-Robots-Tag: noindex` headers.
> - **`src/Admin/Suppressions_Page.php`** + **`src/Admin/Suppressions_List_Table.php`** — admin sub-page under "Email Templates" with `manage_options` capability. Add form + paginated list with per-row Remove and bulk Remove.

In **"Options"** — add:

> - `leastudios_email_templates_unsubscribe_secret` — autoload **no**. 64-char HMAC key minted on first `Unsubscribe_Manager::url_for(...)`. Rotating = deleting the option (invalidates every outstanding unsubscribe link). Filterable via `leastudios_email_templates_unsubscribe_token_secret` to source from a constant or env var instead.
> - `leastudios_email_templates_suppressions_schema_version` — autoload yes; mirrors the log table's schema-version short-circuit. Starts at `1.0.0`.

In **"Database tables"** — add:

> - **`wp_leastudios_email_templates_suppressions`** (schema 1.0.0) — `id`, `email` (UNIQUE), `suppressed_at`, `source`. One row per opted-out address. Drop via `Suppression_Repository::drop()` on uninstall.
> - **`wp_leastudios_email_templates_log.status`** also accepts the value `'suppressed'` (no schema change — column is already `varchar(16)`).

In **"Public extension points"** — append:

> - Action `leastudios_email_templates_email_suppressed` — fires when `Email_Sender::send` skips a non-required-type send because the recipient is suppressed. Args: `string $type_id, string $to, string $subject, string $body, array $headers, string $source`. `Send_Logger` writes one log row per fire with `status='suppressed'`. The body includes the auto-appended unsubscribe footer so the row matches what would have been sent.
> - Filter `leastudios_email_templates_unsubscribe_url` — `(string $url, string $email, string $type_id) => string`. Rewrite the unsubscribe URL (e.g., route through a CDN).
> - Filter `leastudios_email_templates_unsubscribe_footer_html` — `(string $default_html, string $to, string $type_id) => string`. Replace the auto-appended footer markup for non-required types.
> - Filter `leastudios_email_templates_unsubscribe_token_secret` — `(string $secret) => string`. Source the HMAC secret from a constant or env var rather than the `wp_option`. Returning non-empty skips the option entirely (the secret is never written to the DB in that mode).

In **"When adding a new transactional email type"** — append a note:

> By default a new built-in or third-party type is **suppression-eligible** (`is_transactional_required(): false`). Return `true` only if the email is legally required and must always be sent regardless of opt-out (receipts, refunds, payment-failure alerts, renewal receipts).

- [ ] **Step 6: Commit the docs refresh**

```bash
git add CLAUDE.md
git commit -m "$(cat <<'EOF'
Refresh CLAUDE.md for Phase 9 unsubscribe/suppression
EOF
)"
```

- [ ] **Step 7: Final push**

```bash
git push origin main
```

---

## Self-review checklist (executed before declaring this plan ready)

- **Spec coverage:** Every D1–D10 decision in the spec maps to a task:
  - D1 (demote `subscription_created`) → Task 4
  - D2 (HMAC token) → Task 3
  - D3 (global per-recipient) → Task 2 schema (UNIQUE email)
  - D4 (always-on footer) → Tasks 7+8
  - D5 (one-click GET, two-click POST) → Tasks 10+11
  - D6 (new log status + new action) → Tasks 6+9
  - D7 (`{unsubscribe_url}` global tag) → Tasks 5+7
  - D8 (secret unencrypted) → Task 3
  - D9 (CLI parity) → Tasks 14+15
  - D10 (uninstall) → Task 17
- **Placeholder scan:** no `TODO`, no "implement later". The Task 6 stub of `render_unsubscribe_footer` is explicitly identified as "filled in in Task 8" with the real body shown in Task 8, so it's a sequenced step rather than a placeholder.
- **Type consistency:** `Suppression_Entry` properties (`id`, `email`, `suppressed_at`, `source`) used in Tasks 2, 12, 14 match. `Unsubscribe_Manager` method names (`url_for`, `verify_token`, `suppress`, `unsuppress`, `is_suppressed`) match across Tasks 3, 6, 11, 13, 14, 15. `_email_suppressed` action arity (6 args) consistent between Task 6 (fire) and Task 9 (listen).
- **Order safety:** demotion (Task 4) lands BEFORE the gate (Task 6), but between them the demotion is inert (no consumer reads `is_transactional_required` until Task 6). Safe to ship the chain commit-by-commit.

---

**Plan complete and saved to `docs/superpowers/plans/2026-05-22-phase-9-unsubscribe.md`. Two execution options:**

1. **Subagent-Driven (recommended)** — fresh subagent per task, review between tasks, fast iteration.
2. **Inline Execution** — execute tasks in this session using executing-plans, batch execution with checkpoints.

Which approach?
