# Phase 7 — Email-Type Registry Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the closed `Email_Type` enum with an open registry so third-party plugins can register their own transactional email types (subject/body/tags/sample) and have them appear in the Email Types admin tab, the log filter dropdown, and the WP-CLI/preview/test surfaces (Phase 8).

**Architecture:** A new `Email_Type_Definition` interface declares the contract — `id()`, `label()`, `default_subject()`, `default_body()`, `available_tags()`, `escape_map()`, `sample_context()`, `is_transactional_required()`. An `Abstract_Email_Type` base class implements `escape_map()` (projection from `available_tags()`) and a safe `is_transactional_required()` default of `false`. An `Email_Type_Registry` holds a map `string $id => Email_Type_Definition` with `register/get/all/has`. The five existing types become classes under `src/Email/Built_In/` extending `Abstract_Email_Type` and opting in to `is_transactional_required(): true`. `Plugin::init()` constructs the registry, registers the five built-ins, then fires `do_action('leastudios_email_templates_register_types', $registry)` so other plugins can add their own. `Email_Sender::send` and `compose` take a `string $type_id` and consult the injected registry; `Settings_Page`, `Email_Log_List_Table`, and `Send_Logger` iterate / accept string ids. The old `Email_Type` enum and the standalone `Sample_Context` helper are deleted — sample data lives on each definition. `leastudios_email_templates_email_sent` action's first arg changes from `Email_Type` to `string` (release note).

**Tech Stack:** PHP 8.1+ (interfaces with default methods via abstract class), WordPress action hooks, PHPUnit 9.6, PHPStan level 7. No new dependencies.

---

## File Structure

| File | Action | Responsibility |
|---|---|---|
| `src/Email/Email_Type_Definition.php` | Create | Interface — the public contract every email type must implement. |
| `src/Email/Abstract_Email_Type.php` | Create | Abstract base providing `escape_map()` projection and `is_transactional_required(): false` default. |
| `src/Email/Email_Type_Registry.php` | Create | In-memory `string => Email_Type_Definition` map with `register()`, `get(string)`, `has(string)`, `all()`. |
| `src/Email/Built_In/Payment_Receipt.php` | Create | Built-in definition for `payment_receipt`. |
| `src/Email/Built_In/Subscription_Created.php` | Create | Built-in definition for `subscription_created`. |
| `src/Email/Built_In/Subscription_Renewed.php` | Create | Built-in definition for `subscription_renewed`. |
| `src/Email/Built_In/Payment_Failed.php` | Create | Built-in definition for `payment_failed`. |
| `src/Email/Built_In/Refund_Processed.php` | Create | Built-in definition for `refund_processed`. |
| `src/Email/Email_Type.php` | Delete | Subsumed by the registry + per-type classes. |
| `src/Email/Sample_Context.php` | Delete | Subsumed by `Email_Type_Definition::sample_context()`. |
| `src/Email/Email_Sender.php` | Modify | Constructor takes `Email_Type_Registry`. `send(string $type_id, ...)` and `compose(string $type_id, ...)` resolve via registry; emit `string` (not enum) in the `_email_sent` action. |
| `src/Plugin.php` | Modify | Construct registry, register five built-ins, fire `leastudios_email_templates_register_types` action, inject registry into sender / settings / log page. |
| `src/Payment/Payment_Email_Listener.php` | Modify | Replace five `Email_Type::FOO` references with string literals. |
| `src/Admin/Settings_Page.php` | Modify | Constructor takes registry. Iterate `$registry->all()`. `resolve_posted_type()` returns `?Email_Type_Definition`. `handle_preview_type` / `handle_send_test` use the definition's `sample_context()`. |
| `src/Admin/Email_Log_List_Table.php` | Modify | Constructor takes registry. Filter dropdown iterates `$registry->all()`. |
| `src/Admin/Email_Log_Page.php` | Modify | Constructor takes registry, passes it to the list table. |
| `src/Log/Send_Logger.php` | Modify | `record()` first parameter type changes from `Email_Type` to `string`. |
| `tests/EmailTypeDefinitionInterfaceTest.php` | Create | Smoke test that the interface declares the expected methods. |
| `tests/AbstractEmailTypeTest.php` | Create | Verify `escape_map()` projection and `is_transactional_required()` default via a test fixture subclass. |
| `tests/EmailTypeRegistryTest.php` | Create | `register/get/has/all`, unknown-id behaviour, duplicate-id overwrites with last-write-wins (deliberately permissive so third parties can override built-ins). |
| `tests/BuiltInTypesTest.php` | Create | Exhaustive loop over the five built-ins asserting they keep their identifier strings, their `is_transactional_required(): true` flag, advertise the common tags, and yield a non-empty `sample_context()`. |
| `tests/RegistryFixturesTest.php` | Create | Acceptance test: a fixture definition registered via the action appears in `$registry->all()` and dispatches end-to-end through `Email_Sender::send('fixture_id', ...)`. |
| `tests/EmailTypeTest.php` | Delete | The enum is gone. |
| `tests/SampleContextTest.php` | Delete | Sample logic moved onto each definition; covered by `BuiltInTypesTest`. |
| `tests/EmailSenderTest.php` | Modify | All `Email_Type::FOO` → `'foo'`. Constructor now takes a registry — tests build a registry with the five built-ins registered. |
| `tests/PaymentEmailListenerTest.php` | Modify | All `Email_Type::FOO` → `'foo'`. `expects()->with(...)` matchers update to string. |
| `tests/SendLoggerTest.php` | Modify | All `Email_Type::FOO` → `'foo'`. |

---

## Background-context cheatsheet (for the implementer)

- **No back-compat shim.** The `leastudios_email_templates_email_sent` action's first parameter changes from `Email_Type` to `string`. The internal `Email_Sender::send()` signature changes. The `Email_Type` enum is **deleted**, not deprecated. Per project CLAUDE.md, the codebase rejects backwards-compat for code that hasn't been shipped externally — and we explicitly chose "break freely" for this phase.
- **Type-id strings are frozen.** The five ids stay exactly `payment_receipt`, `subscription_created`, `subscription_renewed`, `payment_failed`, `refund_processed`. These are persisted as keys in the `leastudios_email_templates_emails` WordPress option (customer-saved subject/body overrides) and as values in the `wp_leastudios_email_templates_log.type` column. Any rename would orphan existing data.
- **Bootstrap timing.** `Plugin::init()` runs on `plugins_loaded` priority 10. Third parties should call `add_action('leastudios_email_templates_register_types', ...)` at file scope in their own plugin's entry file (which is included before any `plugins_loaded` callback fires). Registering after `plugins_loaded:10` is *technically* allowed by the registry — the map is mutable — but late entries will not appear in the Email Types tab or list-table filter for pages already rendering.
- **Why an interface + abstract, not just an abstract class.** PHPStan level 7 prefers explicit contracts. The interface is what `Email_Sender`, `Settings_Page`, and tests typehint against (`Email_Type_Definition`). The abstract supplies the boilerplate so built-ins and third parties don't repeat `escape_map()` projection logic. Third parties **may** implement the interface directly without extending the abstract — they just have to write `escape_map()` themselves.
- **Registry overwrite semantics.** `register()` is last-write-wins by `id()`. This is deliberate: a third party that wants to replace a built-in (e.g. supply a completely different `Refund_Processed`) registers theirs on the `leastudios_email_templates_register_types` action — which fires *after* built-ins are registered — and their version wins. Tests assert this.
- **`Email_Sender` constructor change.** Previously `__construct(Merge_Tag_Replacer $replacer)`. Now `__construct(Merge_Tag_Replacer $replacer, Email_Type_Registry $registry)`. Every constructor site updates in lockstep (Plugin.php, Settings_Page's `handle_send_test` ad-hoc instantiation, all tests).
- **Log page caveat.** `Email_Log_Page::maybe_handle_resend()` uses `$row->type` (a string from the DB column) directly when calling `wp_mail` — no enum involved. Resend logic is untouched. The only change in `Email_Log_Page` is the constructor accepting and forwarding the registry to the list table.
- **`Settings_Page::handle_preview_type` and `handle_send_test` ad-hoc Sender.** `handle_send_test` currently does `new Email_Sender(new Merge_Tag_Replacer())`. After Phase 7 it must accept the registry too. Easiest: have `Settings_Page` store the registry as a `readonly` property and instantiate the ad-hoc sender from there. (Avoid resolving from a global; tests need to inject.)
- **PHPStan return shape for `available_tags()`.** Keep `array<string, array{description: string, escape: Escape_Mode}>` — identical to Phase 6.

---

## Acceptance criteria (verify before claiming complete)

1. All existing tests pass (with their `Email_Type::FOO` references rewritten as `'foo'` strings).
2. `tests/RegistryFixturesTest.php` proves a fixture class registered via the action appears in the registry **and** can be dispatched via `Email_Sender::send('fake_welcome', ...)`.
3. `composer phpcs` is clean.
4. `composer phpstan` is clean at level 7.
5. `composer test` reports 100+ tests, 0 failures.
6. Manual smoke check via WP-CLI: `wp eval` to instantiate the plugin's registry, register a one-off type, and confirm it appears in `$registry->all()` — wording given in Task 10 step 4.

---

## Task 1: Create the `Email_Type_Definition` interface

**Files:**
- Create: `src/Email/Email_Type_Definition.php`
- Test: `tests/EmailTypeDefinitionInterfaceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
/**
 * Smoke test for the Email_Type_Definition interface.
 *
 * @package LEAStudios\EmailTemplates\Tests
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Tests;

use LEAStudios\EmailTemplates\Email\Email_Type_Definition;
use LEAStudios\Tests\TestCase;

/**
 * @covers \LEAStudios\EmailTemplates\Email\Email_Type_Definition
 */
class EmailTypeDefinitionInterfaceTest extends TestCase {

	public function test_interface_declares_the_expected_methods(): void {
		$reflection = new \ReflectionClass( Email_Type_Definition::class );

		$this->assertTrue( $reflection->isInterface(), 'Email_Type_Definition must be an interface.' );

		$expected = [
			'id',
			'label',
			'default_subject',
			'default_body',
			'available_tags',
			'escape_map',
			'sample_context',
			'is_transactional_required',
		];

		foreach ( $expected as $method ) {
			$this->assertTrue(
				$reflection->hasMethod( $method ),
				"Email_Type_Definition must declare {$method}()"
			);
		}
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run from the plugin dir: `vendor/bin/phpunit tests/EmailTypeDefinitionInterfaceTest.php -v`
Expected: FAIL — class `LEAStudios\EmailTemplates\Email\Email_Type_Definition` not found.

- [ ] **Step 3: Create the interface**

Write `src/Email/Email_Type_Definition.php`:

```php
<?php
/**
 * Contract every transactional email type must implement.
 *
 * @package LEAStudios\EmailTemplates\Email
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Email;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Defines a transactional email type — its identifier, label, default
 * subject/body, advertised merge tags, escape contract, sample data for
 * previews, and whether it is "transactional-required" (i.e. not eligible
 * for unsubscribe in Phase 9).
 */
interface Email_Type_Definition {

	/**
	 * Stable identifier — used as the option-array key, the log table column,
	 * the wp_ajax POST `type` field, and the registry map key. Must match
	 * `^[a-z][a-z0-9_]*$`.
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Human-readable label, translated.
	 *
	 * @return string
	 */
	public function label(): string;

	/**
	 * Default subject line (may contain merge tags). Used when the admin
	 * leaves the custom subject blank.
	 *
	 * @return string
	 */
	public function default_subject(): string;

	/**
	 * Default body HTML (may contain merge tags). Used when the admin leaves
	 * the custom body blank.
	 *
	 * @return string
	 */
	public function default_body(): string;

	/**
	 * Merge tags this type advertises in the admin UI, keyed by braced tag
	 * name (e.g. `{customer_name}`). Each entry carries a description and an
	 * Escape_Mode.
	 *
	 * @return array<string, array{description: string, escape: Escape_Mode}>
	 */
	public function available_tags(): array;

	/**
	 * Per-tag escape modes keyed by *unbraced* tag name (matching the
	 * runtime `$context` keys). Built-ins derive this from `available_tags()`
	 * via the projection in Abstract_Email_Type.
	 *
	 * @return array<string, Escape_Mode>
	 */
	public function escape_map(): array;

	/**
	 * Realistic sample merge-tag values, keyed by *unbraced* tag name,
	 * suitable for previews and self-tests. Should populate every
	 * non-global tag the type advertises (globals like `{site_name}` are
	 * injected by Merge_Tag_Replacer).
	 *
	 * @return array<string, string>
	 */
	public function sample_context(): array;

	/**
	 * Whether this type is transactional-required and must always be sent
	 * regardless of unsubscribe preferences. Phase 9 (unsubscribe) consults
	 * this flag. Defaults to `false` for custom types — receipts, payment
	 * failures, refunds, etc. opt in by returning `true`.
	 *
	 * @return bool
	 */
	public function is_transactional_required(): bool;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/EmailTypeDefinitionInterfaceTest.php -v`
Expected: PASS, 1 test, 9 assertions.

- [ ] **Step 5: Commit**

```bash
git add src/Email/Email_Type_Definition.php tests/EmailTypeDefinitionInterfaceTest.php
git commit -m "Add Email_Type_Definition interface for the registry contract

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: Create `Abstract_Email_Type` base class

**Files:**
- Create: `src/Email/Abstract_Email_Type.php`
- Test: `tests/AbstractEmailTypeTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
/**
 * Tests for Abstract_Email_Type.
 *
 * @package LEAStudios\EmailTemplates\Tests
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Tests;

use LEAStudios\EmailTemplates\Email\Abstract_Email_Type;
use LEAStudios\EmailTemplates\Email\Escape_Mode;
use LEAStudios\Tests\TestCase;

/**
 * @covers \LEAStudios\EmailTemplates\Email\Abstract_Email_Type
 */
class AbstractEmailTypeTest extends TestCase {

	public function test_escape_map_projects_available_tags_to_unbraced_keys(): void {
		$fixture = new class() extends Abstract_Email_Type {
			public function id(): string { return 'fixture'; }
			public function label(): string { return 'Fixture'; }
			public function default_subject(): string { return 'Hi'; }
			public function default_body(): string { return '<p>Hi</p>'; }
			public function available_tags(): array {
				return [
					'{customer_name}' => [ 'description' => 'name', 'escape' => Escape_Mode::HTML ],
					'{site_url}'      => [ 'description' => 'url', 'escape' => Escape_Mode::URL ],
				];
			}
			public function sample_context(): array { return [ 'customer_name' => 'Jane' ]; }
		};

		$map = $fixture->escape_map();

		$this->assertSame(
			[
				'customer_name' => Escape_Mode::HTML,
				'site_url'      => Escape_Mode::URL,
			],
			$map
		);
	}

	public function test_is_transactional_required_defaults_to_false(): void {
		$fixture = new class() extends Abstract_Email_Type {
			public function id(): string { return 'opt_in'; }
			public function label(): string { return 'Opt-in'; }
			public function default_subject(): string { return 'Hi'; }
			public function default_body(): string { return 'Hi'; }
			public function available_tags(): array { return []; }
			public function sample_context(): array { return []; }
		};

		$this->assertFalse( $fixture->is_transactional_required() );
	}

	public function test_subclass_can_override_is_transactional_required(): void {
		$fixture = new class() extends Abstract_Email_Type {
			public function id(): string { return 'tx_required'; }
			public function label(): string { return 'Required'; }
			public function default_subject(): string { return 'Hi'; }
			public function default_body(): string { return 'Hi'; }
			public function available_tags(): array { return []; }
			public function sample_context(): array { return []; }
			public function is_transactional_required(): bool { return true; }
		};

		$this->assertTrue( $fixture->is_transactional_required() );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/AbstractEmailTypeTest.php -v`
Expected: FAIL — class `LEAStudios\EmailTemplates\Email\Abstract_Email_Type` not found.

- [ ] **Step 3: Create the abstract class**

Write `src/Email/Abstract_Email_Type.php`:

```php
<?php
/**
 * Convenience base class for Email_Type_Definition implementations.
 *
 * @package LEAStudios\EmailTemplates\Email
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Email;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Default implementation of the boilerplate parts of Email_Type_Definition.
 *
 * Subclasses must implement: id(), label(), default_subject(),
 * default_body(), available_tags(), sample_context().
 *
 * Subclasses get for free: escape_map() (projected from available_tags()),
 * is_transactional_required() (defaults to false — override to true for
 * receipts, refunds, payment-failure notices, etc.).
 *
 * Implementing the interface directly (without extending this class) is
 * also supported; you just have to write escape_map() yourself.
 */
abstract class Abstract_Email_Type implements Email_Type_Definition {

	/**
	 * Project available_tags() into the unbraced `name => Escape_Mode` map
	 * that Merge_Tag_Replacer::replace_html() consumes.
	 *
	 * @return array<string, Escape_Mode>
	 */
	public function escape_map(): array {
		$map = [];
		foreach ( $this->available_tags() as $tag => $meta ) {
			$map[ trim( $tag, '{}' ) ] = $meta['escape'];
		}
		return $map;
	}

	/**
	 * Default: not transactional-required. Override in subclasses that
	 * must always send regardless of unsubscribe preferences.
	 *
	 * @return bool
	 */
	public function is_transactional_required(): bool {
		return false;
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/AbstractEmailTypeTest.php -v`
Expected: PASS, 3 tests.

- [ ] **Step 5: Commit**

```bash
git add src/Email/Abstract_Email_Type.php tests/AbstractEmailTypeTest.php
git commit -m "Add Abstract_Email_Type with escape_map() projection and safe default

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: Create `Email_Type_Registry`

**Files:**
- Create: `src/Email/Email_Type_Registry.php`
- Test: `tests/EmailTypeRegistryTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
/**
 * Tests for Email_Type_Registry.
 *
 * @package LEAStudios\EmailTemplates\Tests
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Tests;

use LEAStudios\EmailTemplates\Email\Abstract_Email_Type;
use LEAStudios\EmailTemplates\Email\Email_Type_Registry;
use LEAStudios\Tests\TestCase;

/**
 * @covers \LEAStudios\EmailTemplates\Email\Email_Type_Registry
 */
class EmailTypeRegistryTest extends TestCase {

	private function make_definition( string $id, string $label = 'Stub' ): Abstract_Email_Type {
		return new class( $id, $label ) extends Abstract_Email_Type {
			public function __construct( private string $stub_id, private string $stub_label ) {}
			public function id(): string { return $this->stub_id; }
			public function label(): string { return $this->stub_label; }
			public function default_subject(): string { return 'Stub subject'; }
			public function default_body(): string { return '<p>Stub body</p>'; }
			public function available_tags(): array { return []; }
			public function sample_context(): array { return []; }
		};
	}

	public function test_register_and_get_round_trip(): void {
		$registry = new Email_Type_Registry();
		$def      = $this->make_definition( 'foo' );

		$registry->register( $def );

		$this->assertSame( $def, $registry->get( 'foo' ) );
	}

	public function test_get_returns_null_for_unknown_id(): void {
		$registry = new Email_Type_Registry();

		$this->assertNull( $registry->get( 'does_not_exist' ) );
	}

	public function test_has_returns_true_when_registered(): void {
		$registry = new Email_Type_Registry();
		$registry->register( $this->make_definition( 'foo' ) );

		$this->assertTrue( $registry->has( 'foo' ) );
		$this->assertFalse( $registry->has( 'bar' ) );
	}

	public function test_all_returns_a_map_keyed_by_id(): void {
		$registry = new Email_Type_Registry();
		$a        = $this->make_definition( 'a' );
		$b        = $this->make_definition( 'b' );

		$registry->register( $a );
		$registry->register( $b );

		$this->assertSame(
			[ 'a' => $a, 'b' => $b ],
			$registry->all()
		);
	}

	public function test_register_is_last_write_wins(): void {
		$registry = new Email_Type_Registry();
		$first    = $this->make_definition( 'foo', 'First' );
		$second   = $this->make_definition( 'foo', 'Second' );

		$registry->register( $first );
		$registry->register( $second );

		$this->assertSame( $second, $registry->get( 'foo' ) );
		$this->assertCount( 1, $registry->all(), 'Duplicate id should not multiply entries.' );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/EmailTypeRegistryTest.php -v`
Expected: FAIL — class `LEAStudios\EmailTemplates\Email\Email_Type_Registry` not found.

- [ ] **Step 3: Create the registry**

Write `src/Email/Email_Type_Registry.php`:

```php
<?php
/**
 * Registry of email type definitions.
 *
 * @package LEAStudios\EmailTemplates\Email
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Email;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

/**
 * In-memory map of `id => Email_Type_Definition`.
 *
 * Built-in definitions are registered by Plugin::init() immediately before
 * firing the `leastudios_email_templates_register_types` action. Third
 * parties hook that action to register their own types.
 *
 * Registration is last-write-wins by id — a third party can replace a
 * built-in (e.g. a custom Refund_Processed) by registering on the same id
 * from their `leastudios_email_templates_register_types` callback.
 */
class Email_Type_Registry {

	/**
	 * @var array<string, Email_Type_Definition>
	 */
	private array $definitions = [];

	/**
	 * Register (or replace) a definition.
	 *
	 * @param Email_Type_Definition $definition The definition to register.
	 * @return void
	 */
	public function register( Email_Type_Definition $definition ): void {
		$this->definitions[ $definition->id() ] = $definition;
	}

	/**
	 * Look up a definition by id.
	 *
	 * @param string $id The type id.
	 * @return Email_Type_Definition|null
	 */
	public function get( string $id ): ?Email_Type_Definition {
		return $this->definitions[ $id ] ?? null;
	}

	/**
	 * Check whether a definition is registered for the given id.
	 *
	 * @param string $id The type id.
	 * @return bool
	 */
	public function has( string $id ): bool {
		return isset( $this->definitions[ $id ] );
	}

	/**
	 * Return all registered definitions keyed by id, in registration order.
	 *
	 * @return array<string, Email_Type_Definition>
	 */
	public function all(): array {
		return $this->definitions;
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/EmailTypeRegistryTest.php -v`
Expected: PASS, 5 tests.

- [ ] **Step 5: Commit**

```bash
git add src/Email/Email_Type_Registry.php tests/EmailTypeRegistryTest.php
git commit -m "Add Email_Type_Registry with last-write-wins register/get/has/all

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: Create the five built-in type definitions

This task creates one PHP file per built-in. Each is a near-mechanical translation of the corresponding match-arm in the current `Email_Type.php` plus the corresponding entry in `Sample_Context.php`. The body strings and subject lines must be **byte-identical** to the current enum's output — customers already have the rendered versions in their inboxes and we are not changing copy.

**Files:**
- Create: `src/Email/Built_In/Payment_Receipt.php`
- Create: `src/Email/Built_In/Subscription_Created.php`
- Create: `src/Email/Built_In/Subscription_Renewed.php`
- Create: `src/Email/Built_In/Payment_Failed.php`
- Create: `src/Email/Built_In/Refund_Processed.php`
- Test: `tests/BuiltInTypesTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
/**
 * Exhaustive tests for the five built-in email type definitions.
 *
 * @package LEAStudios\EmailTemplates\Tests
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Tests;

use LEAStudios\EmailTemplates\Email\Built_In\Payment_Failed;
use LEAStudios\EmailTemplates\Email\Built_In\Payment_Receipt;
use LEAStudios\EmailTemplates\Email\Built_In\Refund_Processed;
use LEAStudios\EmailTemplates\Email\Built_In\Subscription_Created;
use LEAStudios\EmailTemplates\Email\Built_In\Subscription_Renewed;
use LEAStudios\EmailTemplates\Email\Email_Type_Definition;
use LEAStudios\EmailTemplates\Email\Escape_Mode;
use LEAStudios\EmailTemplates\Email\Merge_Tag_Replacer;
use LEAStudios\Tests\TestCase;

class BuiltInTypesTest extends TestCase {

	/**
	 * @return array<string, array{0: Email_Type_Definition, 1: string}>
	 */
	public function built_in_provider(): array {
		return [
			'payment_receipt'      => [ new Payment_Receipt(), 'payment_receipt' ],
			'subscription_created' => [ new Subscription_Created(), 'subscription_created' ],
			'subscription_renewed' => [ new Subscription_Renewed(), 'subscription_renewed' ],
			'payment_failed'       => [ new Payment_Failed(), 'payment_failed' ],
			'refund_processed'     => [ new Refund_Processed(), 'refund_processed' ],
		];
	}

	/**
	 * @dataProvider built_in_provider
	 */
	public function test_id_is_frozen( Email_Type_Definition $type, string $expected_id ): void {
		$this->assertSame( $expected_id, $type->id() );
	}

	/**
	 * @dataProvider built_in_provider
	 */
	public function test_label_is_translated_string( Email_Type_Definition $type ): void {
		$this->assertNotSame( '', $type->label() );
	}

	/**
	 * @dataProvider built_in_provider
	 */
	public function test_default_subject_and_body_non_empty( Email_Type_Definition $type ): void {
		$this->assertNotSame( '', $type->default_subject() );
		$this->assertNotSame( '', $type->default_body() );
	}

	/**
	 * @dataProvider built_in_provider
	 */
	public function test_advertises_common_tags( Email_Type_Definition $type ): void {
		$common = [ '{customer_name}', '{customer_email}', '{site_name}', '{site_url}', '{date}' ];
		$keys   = array_keys( $type->available_tags() );

		foreach ( $common as $tag ) {
			$this->assertContains( $tag, $keys, $type->id() . " missing common tag {$tag}" );
		}
	}

	/**
	 * @dataProvider built_in_provider
	 */
	public function test_escape_map_unbraces_keys( Email_Type_Definition $type ): void {
		$map = $type->escape_map();

		$this->assertSame(
			count( $type->available_tags() ),
			count( $map ),
			$type->id() . ' escape_map count differs from available_tags count'
		);

		foreach ( array_keys( $map ) as $key ) {
			$this->assertDoesNotMatchRegularExpression( '/[{}]/', $key, "Key {$key} should be unbraced" );
		}
	}

	/**
	 * @dataProvider built_in_provider
	 */
	public function test_is_transactional_required_returns_true( Email_Type_Definition $type ): void {
		$this->assertTrue( $type->is_transactional_required(), $type->id() . ' must be transactional-required' );
	}

	/**
	 * @dataProvider built_in_provider
	 */
	public function test_sample_context_populates_every_non_global_tag( Email_Type_Definition $type ): void {
		$globals = [ 'site_name', 'site_url', 'date' ];
		$sample  = $type->sample_context();

		foreach ( array_keys( $type->available_tags() ) as $tag ) {
			$key = trim( $tag, '{}' );
			if ( in_array( $key, $globals, true ) ) {
				continue;
			}
			$this->assertArrayHasKey( $key, $sample, $type->id() . " sample_context missing {$tag}" );
			$this->assertNotSame( '', $sample[ $key ], $type->id() . " sample_context value for {$tag} must be non-empty" );
		}
	}

	/**
	 * @dataProvider built_in_provider
	 */
	public function test_sample_context_renders_with_no_unresolved_tags( Email_Type_Definition $type ): void {
		$replacer = new Merge_Tag_Replacer();
		$template = '';
		foreach ( array_keys( $type->available_tags() ) as $tag ) {
			$template .= $tag . "\n";
		}

		$rendered = $replacer->replace_html( $template, $type->sample_context(), $type->escape_map() );

		$this->assertStringNotContainsString( '{', $rendered, $type->id() . ' has unresolved tags after render' );
	}

	public function test_site_url_advertised_with_url_escape_mode_across_built_ins(): void {
		foreach ( $this->built_in_provider() as [ $type ] ) {
			$tags = $type->available_tags();
			$this->assertSame( Escape_Mode::URL, $tags['{site_url}']['escape'], $type->id() . ' site_url must be URL-escaped' );
		}
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/BuiltInTypesTest.php -v`
Expected: FAIL — `LEAStudios\EmailTemplates\Email\Built_In\Payment_Receipt` not found.

- [ ] **Step 3: Create `Payment_Receipt`**

Write `src/Email/Built_In/Payment_Receipt.php`:

```php
<?php
/**
 * Built-in: payment_receipt.
 *
 * @package LEAStudios\EmailTemplates\Email\Built_In
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Email\Built_In;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use LEAStudios\EmailTemplates\Email\Abstract_Email_Type;
use LEAStudios\EmailTemplates\Email\Escape_Mode;

final class Payment_Receipt extends Abstract_Email_Type {

	public function id(): string {
		return 'payment_receipt';
	}

	public function label(): string {
		return __( 'Payment Receipt', 'leastudios-email-templates' );
	}

	public function default_subject(): string {
		return __( 'Your receipt for {product_name}', 'leastudios-email-templates' );
	}

	public function default_body(): string {
		return '<h2>' . __( 'Thank you for your purchase!', 'leastudios-email-templates' ) . '</h2>'
			. '<p>' . __( 'Hi {customer_name},', 'leastudios-email-templates' ) . '</p>'
			. '<p>' . __( 'We\'ve received your payment. Here are the details:', 'leastudios-email-templates' ) . '</p>'
			. '<table style="width:100%;border-collapse:collapse;margin:20px 0;">'
			. '<tr><td style="padding:8px;border:1px solid #e5e7eb;font-weight:bold;">' . __( 'Product', 'leastudios-email-templates' ) . '</td><td style="padding:8px;border:1px solid #e5e7eb;">{product_name}</td></tr>'
			. '<tr><td style="padding:8px;border:1px solid #e5e7eb;font-weight:bold;">' . __( 'Amount', 'leastudios-email-templates' ) . '</td><td style="padding:8px;border:1px solid #e5e7eb;">{amount}</td></tr>'
			. '<tr><td style="padding:8px;border:1px solid #e5e7eb;font-weight:bold;">' . __( 'Date', 'leastudios-email-templates' ) . '</td><td style="padding:8px;border:1px solid #e5e7eb;">{date}</td></tr>'
			. '<tr><td style="padding:8px;border:1px solid #e5e7eb;font-weight:bold;">' . __( 'Payment ID', 'leastudios-email-templates' ) . '</td><td style="padding:8px;border:1px solid #e5e7eb;">{payment_id}</td></tr>'
			. '</table>';
	}

	public function available_tags(): array {
		return [
			'{customer_name}'  => [ 'description' => __( 'Customer name', 'leastudios-email-templates' ), 'escape' => Escape_Mode::HTML ],
			'{customer_email}' => [ 'description' => __( 'Customer email', 'leastudios-email-templates' ), 'escape' => Escape_Mode::HTML ],
			'{site_name}'      => [ 'description' => __( 'Site name', 'leastudios-email-templates' ), 'escape' => Escape_Mode::HTML ],
			'{site_url}'       => [ 'description' => __( 'Site URL', 'leastudios-email-templates' ), 'escape' => Escape_Mode::URL ],
			'{date}'           => [ 'description' => __( 'Current date', 'leastudios-email-templates' ), 'escape' => Escape_Mode::HTML ],
			'{amount}'         => [ 'description' => __( 'Payment amount', 'leastudios-email-templates' ), 'escape' => Escape_Mode::HTML ],
			'{currency}'       => [ 'description' => __( 'Currency code', 'leastudios-email-templates' ), 'escape' => Escape_Mode::HTML ],
			'{product_name}'   => [ 'description' => __( 'Product name', 'leastudios-email-templates' ), 'escape' => Escape_Mode::HTML ],
			'{order_type}'     => [ 'description' => __( 'Order type (one-time or subscription)', 'leastudios-email-templates' ), 'escape' => Escape_Mode::HTML ],
			'{payment_id}'     => [ 'description' => __( 'Stripe Payment Intent ID', 'leastudios-email-templates' ), 'escape' => Escape_Mode::HTML ],
			'{order_id}'       => [ 'description' => __( 'Local order ID', 'leastudios-email-templates' ), 'escape' => Escape_Mode::HTML ],
		];
	}

	public function sample_context(): array {
		return [
			'customer_name'  => 'Jane Customer',
			'customer_email' => 'jane@example.com',
			'amount'         => '$29.99',
			'currency'       => 'USD',
			'product_name'   => 'Sample Product',
			'order_type'     => 'One-time payment',
			'payment_id'     => 'pi_sample_1234567890',
			'order_id'       => '42',
		];
	}

	public function is_transactional_required(): bool {
		return true;
	}
}
```

- [ ] **Step 4: Create `Subscription_Created`**

Write `src/Email/Built_In/Subscription_Created.php`:

```php
<?php
/**
 * Built-in: subscription_created.
 *
 * @package LEAStudios\EmailTemplates\Email\Built_In
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Email\Built_In;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use LEAStudios\EmailTemplates\Email\Abstract_Email_Type;
use LEAStudios\EmailTemplates\Email\Escape_Mode;

final class Subscription_Created extends Abstract_Email_Type {

	public function id(): string {
		return 'subscription_created';
	}

	public function label(): string {
		return __( 'Subscription Created', 'leastudios-email-templates' );
	}

	public function default_subject(): string {
		return __( 'Subscription confirmed — {product_name}', 'leastudios-email-templates' );
	}

	public function default_body(): string {
		return '<h2>' . __( 'Subscription confirmed!', 'leastudios-email-templates' ) . '</h2>'
			. '<p>' . __( 'Hi {customer_name},', 'leastudios-email-templates' ) . '</p>'
			. '<p>' . __( 'Your subscription to {product_name} is now active.', 'leastudios-email-templates' ) . '</p>'
			. '<table style="width:100%;border-collapse:collapse;margin:20px 0;">'
			. '<tr><td style="padding:8px;border:1px solid #e5e7eb;font-weight:bold;">' . __( 'Plan', 'leastudios-email-templates' ) . '</td><td style="padding:8px;border:1px solid #e5e7eb;">{product_name}</td></tr>'
			. '<tr><td style="padding:8px;border:1px solid #e5e7eb;font-weight:bold;">' . __( 'Amount', 'leastudios-email-templates' ) . '</td><td style="padding:8px;border:1px solid #e5e7eb;">{amount}</td></tr>'
			. '<tr><td style="padding:8px;border:1px solid #e5e7eb;font-weight:bold;">' . __( 'Next billing date', 'leastudios-email-templates' ) . '</td><td style="padding:8px;border:1px solid #e5e7eb;">{period_end}</td></tr>'
			. '</table>';
	}

	public function available_tags(): array {
		return [
			'{customer_name}'  => [ 'description' => __( 'Customer name', 'leastudios-email-templates' ), 'escape' => Escape_Mode::HTML ],
			'{customer_email}' => [ 'description' => __( 'Customer email', 'leastudios-email-templates' ), 'escape' => Escape_Mode::HTML ],
			'{site_name}'      => [ 'description' => __( 'Site name', 'leastudios-email-templates' ), 'escape' => Escape_Mode::HTML ],
			'{site_url}'       => [ 'description' => __( 'Site URL', 'leastudios-email-templates' ), 'escape' => Escape_Mode::URL ],
			'{date}'           => [ 'description' => __( 'Current date', 'leastudios-email-templates' ), 'escape' => Escape_Mode::HTML ],
			'{product_name}'   => [ 'description' => __( 'Product name', 'leastudios-email-templates' ), 'escape' => Escape_Mode::HTML ],
			'{amount}'         => [ 'description' => __( 'Payment amount', 'leastudios-email-templates' ), 'escape' => Escape_Mode::HTML ],
			'{currency}'       => [ 'description' => __( 'Currency code', 'leastudios-email-templates' ), 'escape' => Escape_Mode::HTML ],
			'{period_end}'     => [ 'description' => __( 'Current period end date', 'leastudios-email-templates' ), 'escape' => Escape_Mode::HTML ],
		];
	}

	public function sample_context(): array {
		return [
			'customer_name'  => 'Jane Customer',
			'customer_email' => 'jane@example.com',
			'product_name'   => 'Sample Plan',
			'amount'         => '$9.99',
			'currency'       => 'USD',
			'period_end'     => 'June 22, 2026',
		];
	}

	public function is_transactional_required(): bool {
		return true;
	}
}
```

- [ ] **Step 5: Create `Subscription_Renewed`**

Write `src/Email/Built_In/Subscription_Renewed.php`:

```php
<?php
/**
 * Built-in: subscription_renewed.
 *
 * @package LEAStudios\EmailTemplates\Email\Built_In
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Email\Built_In;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use LEAStudios\EmailTemplates\Email\Abstract_Email_Type;
use LEAStudios\EmailTemplates\Email\Escape_Mode;

final class Subscription_Renewed extends Abstract_Email_Type {

	public function id(): string {
		return 'subscription_renewed';
	}

	public function label(): string {
		return __( 'Subscription Renewed', 'leastudios-email-templates' );
	}

	public function default_subject(): string {
		return __( 'Payment received for {product_name}', 'leastudios-email-templates' );
	}

	public function default_body(): string {
		return '<h2>' . __( 'Payment received', 'leastudios-email-templates' ) . '</h2>'
			. '<p>' . __( 'Hi {customer_name},', 'leastudios-email-templates' ) . '</p>'
			. '<p>' . __( 'We\'ve processed your subscription renewal payment for {product_name}.', 'leastudios-email-templates' ) . '</p>'
			. '<table style="width:100%;border-collapse:collapse;margin:20px 0;">'
			. '<tr><td style="padding:8px;border:1px solid #e5e7eb;font-weight:bold;">' . __( 'Amount', 'leastudios-email-templates' ) . '</td><td style="padding:8px;border:1px solid #e5e7eb;">{invoice_amount}</td></tr>'
			. '<tr><td style="padding:8px;border:1px solid #e5e7eb;font-weight:bold;">' . __( 'Next billing date', 'leastudios-email-templates' ) . '</td><td style="padding:8px;border:1px solid #e5e7eb;">{period_end}</td></tr>'
			. '</table>';
	}

	public function available_tags(): array {
		return [
			'{customer_name}'  => [ 'description' => __( 'Customer name', 'leastudios-email-templates' ), 'escape' => Escape_Mode::HTML ],
			'{customer_email}' => [ 'description' => __( 'Customer email', 'leastudios-email-templates' ), 'escape' => Escape_Mode::HTML ],
			'{site_name}'      => [ 'description' => __( 'Site name', 'leastudios-email-templates' ), 'escape' => Escape_Mode::HTML ],
			'{site_url}'       => [ 'description' => __( 'Site URL', 'leastudios-email-templates' ), 'escape' => Escape_Mode::URL ],
			'{date}'           => [ 'description' => __( 'Current date', 'leastudios-email-templates' ), 'escape' => Escape_Mode::HTML ],
			'{product_name}'   => [ 'description' => __( 'Product name', 'leastudios-email-templates' ), 'escape' => Escape_Mode::HTML ],
			'{invoice_amount}' => [ 'description' => __( 'Invoice amount', 'leastudios-email-templates' ), 'escape' => Escape_Mode::HTML ],
			'{currency}'       => [ 'description' => __( 'Currency code', 'leastudios-email-templates' ), 'escape' => Escape_Mode::HTML ],
			'{period_end}'     => [ 'description' => __( 'Next billing date', 'leastudios-email-templates' ), 'escape' => Escape_Mode::HTML ],
		];
	}

	public function sample_context(): array {
		return [
			'customer_name'  => 'Jane Customer',
			'customer_email' => 'jane@example.com',
			'product_name'   => 'Sample Plan',
			'invoice_amount' => '$9.99',
			'currency'       => 'USD',
			'period_end'     => 'July 22, 2026',
		];
	}

	public function is_transactional_required(): bool {
		return true;
	}
}
```

- [ ] **Step 6: Create `Payment_Failed`**

Write `src/Email/Built_In/Payment_Failed.php`:

```php
<?php
/**
 * Built-in: payment_failed.
 *
 * @package LEAStudios\EmailTemplates\Email\Built_In
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Email\Built_In;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use LEAStudios\EmailTemplates\Email\Abstract_Email_Type;
use LEAStudios\EmailTemplates\Email\Escape_Mode;

final class Payment_Failed extends Abstract_Email_Type {

	public function id(): string {
		return 'payment_failed';
	}

	public function label(): string {
		return __( 'Payment Failed', 'leastudios-email-templates' );
	}

	public function default_subject(): string {
		return __( 'Payment failed for your subscription', 'leastudios-email-templates' );
	}

	public function default_body(): string {
		return '<h2>' . __( 'Payment failed', 'leastudios-email-templates' ) . '</h2>'
			. '<p>' . __( 'Hi {customer_name},', 'leastudios-email-templates' ) . '</p>'
			. '<p>' . __( 'We were unable to process your payment of {invoice_amount} for {product_name}. Please update your payment method to avoid any interruption to your subscription.', 'leastudios-email-templates' ) . '</p>';
	}

	public function available_tags(): array {
		return [
			'{customer_name}'  => [ 'description' => __( 'Customer name', 'leastudios-email-templates' ), 'escape' => Escape_Mode::HTML ],
			'{customer_email}' => [ 'description' => __( 'Customer email', 'leastudios-email-templates' ), 'escape' => Escape_Mode::HTML ],
			'{site_name}'      => [ 'description' => __( 'Site name', 'leastudios-email-templates' ), 'escape' => Escape_Mode::HTML ],
			'{site_url}'       => [ 'description' => __( 'Site URL', 'leastudios-email-templates' ), 'escape' => Escape_Mode::URL ],
			'{date}'           => [ 'description' => __( 'Current date', 'leastudios-email-templates' ), 'escape' => Escape_Mode::HTML ],
			'{product_name}'   => [ 'description' => __( 'Product name', 'leastudios-email-templates' ), 'escape' => Escape_Mode::HTML ],
			'{invoice_amount}' => [ 'description' => __( 'Invoice amount', 'leastudios-email-templates' ), 'escape' => Escape_Mode::HTML ],
			'{currency}'       => [ 'description' => __( 'Currency code', 'leastudios-email-templates' ), 'escape' => Escape_Mode::HTML ],
		];
	}

	public function sample_context(): array {
		return [
			'customer_name'  => 'Jane Customer',
			'customer_email' => 'jane@example.com',
			'product_name'   => 'Sample Plan',
			'invoice_amount' => '$9.99',
			'currency'       => 'USD',
		];
	}

	public function is_transactional_required(): bool {
		return true;
	}
}
```

- [ ] **Step 7: Create `Refund_Processed`**

Write `src/Email/Built_In/Refund_Processed.php`:

```php
<?php
/**
 * Built-in: refund_processed.
 *
 * @package LEAStudios\EmailTemplates\Email\Built_In
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Email\Built_In;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use LEAStudios\EmailTemplates\Email\Abstract_Email_Type;
use LEAStudios\EmailTemplates\Email\Escape_Mode;

final class Refund_Processed extends Abstract_Email_Type {

	public function id(): string {
		return 'refund_processed';
	}

	public function label(): string {
		return __( 'Refund Processed', 'leastudios-email-templates' );
	}

	public function default_subject(): string {
		return __( 'Your refund of {refunded_amount} has been processed', 'leastudios-email-templates' );
	}

	public function default_body(): string {
		return '<h2>' . __( 'Refund processed', 'leastudios-email-templates' ) . '</h2>'
			. '<p>' . __( 'Hi {customer_name},', 'leastudios-email-templates' ) . '</p>'
			. '<p>' . __( 'A refund of {refunded_amount} has been issued for your order. It may take 5-10 business days to appear on your statement.', 'leastudios-email-templates' ) . '</p>'
			. '<table style="width:100%;border-collapse:collapse;margin:20px 0;">'
			. '<tr><td style="padding:8px;border:1px solid #e5e7eb;font-weight:bold;">' . __( 'Refunded', 'leastudios-email-templates' ) . '</td><td style="padding:8px;border:1px solid #e5e7eb;">{refunded_amount}</td></tr>'
			. '<tr><td style="padding:8px;border:1px solid #e5e7eb;font-weight:bold;">' . __( 'Original amount', 'leastudios-email-templates' ) . '</td><td style="padding:8px;border:1px solid #e5e7eb;">{amount}</td></tr>'
			. '</table>';
	}

	public function available_tags(): array {
		return [
			'{customer_name}'   => [ 'description' => __( 'Customer name', 'leastudios-email-templates' ), 'escape' => Escape_Mode::HTML ],
			'{customer_email}'  => [ 'description' => __( 'Customer email', 'leastudios-email-templates' ), 'escape' => Escape_Mode::HTML ],
			'{site_name}'       => [ 'description' => __( 'Site name', 'leastudios-email-templates' ), 'escape' => Escape_Mode::HTML ],
			'{site_url}'        => [ 'description' => __( 'Site URL', 'leastudios-email-templates' ), 'escape' => Escape_Mode::URL ],
			'{date}'            => [ 'description' => __( 'Current date', 'leastudios-email-templates' ), 'escape' => Escape_Mode::HTML ],
			'{refunded_amount}' => [ 'description' => __( 'Refund amount', 'leastudios-email-templates' ), 'escape' => Escape_Mode::HTML ],
			'{amount}'          => [ 'description' => __( 'Original payment amount', 'leastudios-email-templates' ), 'escape' => Escape_Mode::HTML ],
			'{currency}'        => [ 'description' => __( 'Currency code', 'leastudios-email-templates' ), 'escape' => Escape_Mode::HTML ],
			'{product_name}'    => [ 'description' => __( 'Product name', 'leastudios-email-templates' ), 'escape' => Escape_Mode::HTML ],
			'{order_id}'        => [ 'description' => __( 'Local order ID', 'leastudios-email-templates' ), 'escape' => Escape_Mode::HTML ],
		];
	}

	public function sample_context(): array {
		return [
			'customer_name'   => 'Jane Customer',
			'customer_email'  => 'jane@example.com',
			'refunded_amount' => '$10.00',
			'amount'          => '$29.99',
			'currency'        => 'USD',
			'product_name'    => 'Sample Product',
			'order_id'        => '42',
		];
	}

	public function is_transactional_required(): bool {
		return true;
	}
}
```

- [ ] **Step 8: Confirm PSR-4 autoload picks up the new subdirectory**

Open `composer.json` and verify the autoload block. The existing config is `"LEAStudios\\EmailTemplates\\": "src/"`. PSR-4 covers subnamespaces automatically — `LEAStudios\EmailTemplates\Email\Built_In\` resolves to `src/Email/Built_In/`. No `composer.json` change is needed.

If you've added classes since the last `composer install`, regenerate the optimized autoloader:

Run: `composer dump-autoload`

Expected output: `Generating autoload files` followed by `Generated optimized autoload files containing X classes`.

- [ ] **Step 9: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/BuiltInTypesTest.php -v`
Expected: PASS, ~40 assertions across the 5 dataProvider rows.

- [ ] **Step 10: Commit**

```bash
git add src/Email/Built_In/ tests/BuiltInTypesTest.php
git commit -m "Add five Built_In email type definitions extending Abstract_Email_Type

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 5: Refactor `Email_Sender` to consume the registry and accept string ids

This is the first cutover step. `Email_Sender` is the choke-point — once its signature changes, every caller (Payment_Email_Listener, Settings_Page, Send_Logger via the action) must update. We do them all in the following tasks.

**Files:**
- Modify: `src/Email/Email_Sender.php`
- Modify: `tests/EmailSenderTest.php`

- [ ] **Step 1: Rewrite `Email_Sender`**

Replace the contents of `src/Email/Email_Sender.php` with:

```php
<?php
/**
 * Sends transactional emails by registered type id.
 *
 * @package LEAStudios\EmailTemplates\Email
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Email;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Composes and sends emails for any type registered in Email_Type_Registry.
 */
class Email_Sender {

	/**
	 * @var array<string, array<string, mixed>>|null
	 */
	private ?array $type_settings_cache = null;

	/**
	 * @var bool
	 */
	private bool $cache_hooks_registered = false;

	/**
	 * Constructor.
	 *
	 * @param Merge_Tag_Replacer    $replacer The merge tag replacer.
	 * @param Email_Type_Registry   $registry The type registry.
	 */
	public function __construct(
		private readonly Merge_Tag_Replacer $replacer,
		private readonly Email_Type_Registry $registry,
	) {}

	/**
	 * Send an email of the specified type.
	 *
	 * @param string               $type_id The registered email type id.
	 * @param string               $to      Recipient address.
	 * @param array<string, mixed> $context Merge-tag values.
	 * @return bool Whether wp_mail returned true. Returns false if the id is
	 *              unknown or the type is disabled.
	 */
	public function send( string $type_id, string $to, array $context = [] ): bool {
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
		 */
		do_action(
			'leastudios_email_templates_email_sent',
			$type_id,
			$args['to'],
			$args['subject'],
			$result,
			(string) $args['message'],
			(array) $args['headers']
		);

		return $result;
	}

	/**
	 * Compose subject/body/headers without sending.
	 *
	 * @param string               $type_id Registered email type id.
	 * @param array<string, mixed> $context Merge-tag values.
	 * @return array{subject:string, body:string, headers:array<int,string>}|null
	 */
	public function compose( string $type_id, array $context = [] ): ?array {
		$definition = $this->registry->get( $type_id );

		if ( null === $definition ) {
			return null;
		}

		$settings = $this->get_type_settings( $type_id );

		if ( empty( $settings['enabled'] ) ) {
			return null;
		}

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

	/**
	 * Get settings for a specific type id. Memoizes the option array.
	 *
	 * @param string $type_id The registered type id.
	 * @return array{enabled: bool, subject: string, body: string, recipient_override: string}
	 */
	private function get_type_settings( string $type_id ): array {
		if ( ! $this->cache_hooks_registered ) {
			$this->cache_hooks_registered = true;
			$invalidate                   = function (): void {
				$this->type_settings_cache = null;
			};
			add_action( 'update_option_leastudios_email_templates_emails', $invalidate );
			add_action( 'add_option_leastudios_email_templates_emails', $invalidate );
			add_action( 'delete_option_leastudios_email_templates_emails', $invalidate );
		}

		if ( null === $this->type_settings_cache ) {
			$this->type_settings_cache = (array) get_option( 'leastudios_email_templates_emails', [] );
		}

		$defaults = [
			'enabled'            => true,
			'subject'            => '',
			'body'               => '',
			'recipient_override' => '',
		];

		$settings = $this->type_settings_cache[ $type_id ] ?? [];

		return array_merge( $defaults, $settings );
	}
}
```

- [ ] **Step 2: Update `tests/EmailSenderTest.php`**

Replace every `Email_Type::PAYMENT_RECEIPT` with the string `'payment_receipt'`. Replace the constructor call so the test builds a registry with the five built-ins. Concretely, swap the `set_up()` and remove the `use Email_Type;` line.

Replace the top of the file (lines 1–30) with:

```php
<?php
/**
 * Tests for Email_Sender.
 *
 * @package LEAStudios\EmailTemplates\Tests
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Tests;

use LEAStudios\EmailTemplates\Email\Built_In\Payment_Failed;
use LEAStudios\EmailTemplates\Email\Built_In\Payment_Receipt;
use LEAStudios\EmailTemplates\Email\Built_In\Refund_Processed;
use LEAStudios\EmailTemplates\Email\Built_In\Subscription_Created;
use LEAStudios\EmailTemplates\Email\Built_In\Subscription_Renewed;
use LEAStudios\EmailTemplates\Email\Email_Sender;
use LEAStudios\EmailTemplates\Email\Email_Type_Registry;
use LEAStudios\EmailTemplates\Email\Merge_Tag_Replacer;
use LEAStudios\Tests\TestCase;

/**
 * @covers \LEAStudios\EmailTemplates\Email\Email_Sender
 */
class EmailSenderTest extends TestCase {

	private Email_Sender $sender;

	public function set_up(): void {
		parent::set_up();

		$registry = new Email_Type_Registry();
		$registry->register( new Payment_Receipt() );
		$registry->register( new Subscription_Created() );
		$registry->register( new Subscription_Renewed() );
		$registry->register( new Payment_Failed() );
		$registry->register( new Refund_Processed() );

		$this->sender = new Email_Sender( new Merge_Tag_Replacer(), $registry );

		reset_phpmailer_instance();
	}
```

Then in the rest of the test methods (line 41 onwards), replace every occurrence of `Email_Type::PAYMENT_RECEIPT` with `'payment_receipt'`. There are 9 such occurrences.

- [ ] **Step 3: Run the sender test to verify it passes**

Run: `vendor/bin/phpunit tests/EmailSenderTest.php -v`
Expected: PASS — all existing assertions still hold; behaviour is unchanged, only the dispatch identifier changed.

- [ ] **Step 4: Add one test for the unknown-id path**

Append to `tests/EmailSenderTest.php` (before the closing `}`):

```php
	public function test_send_returns_false_for_unknown_type_id(): void {
		$result = $this->sender->send( 'does_not_exist', 'test@example.com', [] );

		$this->assertFalse( $result );
	}

	public function test_compose_returns_null_for_unknown_type_id(): void {
		$this->assertNull( $this->sender->compose( 'does_not_exist', [] ) );
	}
```

- [ ] **Step 5: Run the test for the new cases**

Run: `vendor/bin/phpunit tests/EmailSenderTest.php -v`
Expected: PASS, including the two new tests.

- [ ] **Step 6: Commit**

```bash
git add src/Email/Email_Sender.php tests/EmailSenderTest.php
git commit -m "Refactor Email_Sender to accept string type ids via Email_Type_Registry

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 6: Migrate `Send_Logger` to the new action signature

`leastudios_email_templates_email_sent`'s first arg is now `string`. Send_Logger's record method must accept `string` instead of `Email_Type`.

**Files:**
- Modify: `src/Log/Send_Logger.php`
- Modify: `tests/SendLoggerTest.php`

- [ ] **Step 1: Update `Send_Logger::record`**

Edit `src/Log/Send_Logger.php`:

Replace the `use LEAStudios\EmailTemplates\Email\Email_Type;` line (line 16) with nothing — delete that whole line.

Replace the `record()` method (lines 56–68) with:

```php
	/**
	 * Record a single send.
	 *
	 * @param string             $type_id The registered email type id.
	 * @param string             $to      The recipient.
	 * @param string             $subject The subject line.
	 * @param bool               $result  Whether wp_mail returned true.
	 * @param string             $body    The rendered body that was sent.
	 * @param array<int, string> $headers Headers that were sent.
	 * @return void
	 */
	public function record( string $type_id, string $to, string $subject, bool $result, string $body = '', array $headers = [] ): void {
		$this->repo->create(
			[
				'type'      => $type_id,
				'recipient' => $to,
				'subject'   => $subject,
				'body'      => $body,
				'headers'   => implode( "\n", $headers ),
				'status'    => $result ? 'sent' : 'failed',
				'error'     => null,
			]
		);
	}
```

- [ ] **Step 2: Update `tests/SendLoggerTest.php`**

Open the file and:
- Remove the `use LEAStudios\EmailTemplates\Email\Email_Type;` line.
- Replace `Email_Type::PAYMENT_RECEIPT` (line ~35) with `'payment_receipt'`.
- Replace `Email_Type::PAYMENT_FAILED` (line ~53) with `'payment_failed'`.

- [ ] **Step 3: Run the logger test**

Run: `vendor/bin/phpunit tests/SendLoggerTest.php -v`
Expected: PASS, unchanged count.

- [ ] **Step 4: Commit**

```bash
git add src/Log/Send_Logger.php tests/SendLoggerTest.php
git commit -m "Migrate Send_Logger to string type ids from the _email_sent action

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 7: Migrate `Payment_Email_Listener` to string ids

**Files:**
- Modify: `src/Payment/Payment_Email_Listener.php`
- Modify: `tests/PaymentEmailListenerTest.php`

- [ ] **Step 1: Update the listener**

Edit `src/Payment/Payment_Email_Listener.php`:

- Remove the `use LEAStudios\EmailTemplates\Email\Email_Type;` line (line 16).
- Replace `Email_Type::PAYMENT_RECEIPT` (line 63) with `'payment_receipt'`.
- Replace `Email_Type::SUBSCRIPTION_CREATED` (line 118) with `'subscription_created'`.
- Replace `Email_Type::SUBSCRIPTION_RENEWED` (line 142) with `'subscription_renewed'`.
- Replace `Email_Type::PAYMENT_FAILED` (line 160) with `'payment_failed'`.
- Replace `Email_Type::REFUND_PROCESSED` (line 236) with `'refund_processed'`.

- [ ] **Step 2: Update `tests/PaymentEmailListenerTest.php`**

Open the file and:
- Remove the `use LEAStudios\EmailTemplates\Email\Email_Type;` line.
- Replace `Email_Type::PAYMENT_RECEIPT` (line 67) with `'payment_receipt'`.
- Replace `Email_Type::SUBSCRIPTION_RENEWED` (line 151) with `'subscription_renewed'`.
- Replace `Email_Type::PAYMENT_FAILED` (line 175) with `'payment_failed'`.
- Replace `Email_Type::REFUND_PROCESSED` (lines 206 and 236) with `'refund_processed'`.

The PHPUnit `->with(...)` matchers still work — they now match string literals against the string first argument.

- [ ] **Step 3: Run the listener test**

Run: `vendor/bin/phpunit tests/PaymentEmailListenerTest.php -v`
Expected: PASS, unchanged count.

- [ ] **Step 4: Commit**

```bash
git add src/Payment/Payment_Email_Listener.php tests/PaymentEmailListenerTest.php
git commit -m "Migrate Payment_Email_Listener dispatch calls to string type ids

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 8: Migrate `Settings_Page` to the registry

This task is the trickiest because `Settings_Page` reads from `Email_Type` in many places. After this task, the only remaining `Email_Type` reference in the codebase is `Email_Log_List_Table`'s filter dropdown (Task 9) plus the soon-to-be-deleted enum file itself.

**Files:**
- Modify: `src/Admin/Settings_Page.php`

- [ ] **Step 1: Update the imports and add the registry dependency**

Edit `src/Admin/Settings_Page.php`:

Replace the `use` block at lines 15–21 with:

```php
use LEAStudios\EmailTemplates\Email\Email_Sender;
use LEAStudios\EmailTemplates\Email\Email_Type_Definition;
use LEAStudios\EmailTemplates\Email\Email_Type_Registry;
use LEAStudios\EmailTemplates\Email\Merge_Tag_Replacer;
use LEAStudios\EmailTemplates\Email\Template_Wrapper;
use LEAStudios\EmailTemplates\Email\Theme;
use LEAStudios\EmailTemplates\Security\Nonce;
```

(We drop `Email_Type` and `Sample_Context`, add `Email_Type_Definition` and `Email_Type_Registry`.)

Replace the class header opening with a constructor that takes the registry. Find the existing `private string $hook_suffix = '';` (around line 48) and immediately before it insert the constructor; the resulting block should read:

```php
	/**
	 * The settings page hook suffix.
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * Constructor.
	 *
	 * @param Email_Type_Registry $registry The shared type registry.
	 */
	public function __construct(
		private readonly Email_Type_Registry $registry,
	) {}
```

- [ ] **Step 2: Update `sanitize_emails` to iterate the registry**

Replace the body of `sanitize_emails()` (lines 143–159) with:

```php
	public function sanitize_emails( array $input ): array {
		$sanitized = [];

		foreach ( $this->registry->all() as $type_id => $_definition ) {
			$data = $input[ $type_id ] ?? [];

			$sanitized[ $type_id ] = [
				'enabled'            => ! empty( $data['enabled'] ),
				'subject'            => sanitize_text_field( $data['subject'] ?? '' ),
				'body'               => wp_kses_post( $data['body'] ?? '' ),
				'recipient_override' => sanitize_email( $data['recipient_override'] ?? '' ),
			];
		}

		return $sanitized;
	}
```

- [ ] **Step 3: Update `render_email_types_tab` to iterate the registry**

In `render_email_types_tab()`, find `foreach ( Email_Type::cases() as $type ) :` (around line 376) and replace with:

```php
				<?php foreach ( $this->registry->all() as $type ) : ?>
```

Then update the loop body: replace `$type->value` with `$type->id()` throughout (around lines 378, 396, 404, 415, 447, 450, 451, 459, 460, 463 — every `esc_attr( $key )` that uses `$key = $type->value` keeps working because `$key` is recomputed from `$type->id()`; just replace the `$type->value` assignment at line 378 with `$type->id()`).

Concretely, the `$key` assignment at line 378 becomes:

```php
				$key      = $type->id();
```

Everything else in the loop body that operates on `$type` calls methods (`->label()`, `->default_subject()`, `->available_tags()`) — those are interface methods, so they keep working unchanged.

- [ ] **Step 4: Update `handle_preview_type`**

Replace the body of `handle_preview_type()` (lines 514–554) with:

```php
	public function handle_preview_type(): void {
		Nonce::check_ajax( 'preview' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( __( 'Permission denied.', 'leastudios-email-templates' ) );
		}

		$definition = $this->resolve_posted_definition();

		if ( null === $definition ) {
			wp_send_json_error( __( 'Unknown email type.', 'leastudios-email-templates' ) );
		}

		$replacer = new Merge_Tag_Replacer();
		$wrapper  = new Template_Wrapper( $replacer );
		$sample   = $definition->sample_context();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked above via Nonce::check_ajax.
		$subject_tpl = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['subject'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked above via Nonce::check_ajax.
		$body_tpl = isset( $_POST['body'] ) ? wp_kses_post( wp_unslash( (string) $_POST['body'] ) ) : '';

		if ( '' === $subject_tpl ) {
			$subject_tpl = $definition->default_subject();
		}
		if ( '' === $body_tpl ) {
			$body_tpl = $definition->default_body();
		}

		$rendered_subject = $replacer->replace_subject( $subject_tpl, $sample );
		$rendered_body    = $replacer->replace_html( $body_tpl, $sample, $definition->escape_map() );

		$html = $wrapper->wrap( $rendered_body );

		wp_send_json_success(
			[
				'subject' => $rendered_subject,
				'html'    => $html,
			]
		);
	}
```

- [ ] **Step 5: Update `handle_send_test`**

Replace the body of `handle_send_test()` (lines 563–601) with:

```php
	public function handle_send_test(): void {
		Nonce::check_ajax( 'preview' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( __( 'Permission denied.', 'leastudios-email-templates' ) );
		}

		$definition = $this->resolve_posted_definition();

		if ( null === $definition ) {
			wp_send_json_error( __( 'Unknown email type.', 'leastudios-email-templates' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked above via Nonce::check_ajax.
		$to = isset( $_POST['to'] ) ? sanitize_email( wp_unslash( (string) $_POST['to'] ) ) : '';

		if ( '' === $to || ! is_email( $to ) ) {
			wp_send_json_error( __( 'A valid email address is required.', 'leastudios-email-templates' ) );
		}

		$sender = new Email_Sender( new Merge_Tag_Replacer(), $this->registry );
		$result = $sender->send( $definition->id(), $to, $definition->sample_context() );

		if ( ! $result ) {
			wp_send_json_error(
				__( 'Email could not be sent. Check that this email type is enabled and that wp_mail is configured.', 'leastudios-email-templates' )
			);
		}

		wp_send_json_success(
			[
				'message' => sprintf(
					/* translators: %s is the recipient email address. */
					__( 'Test email sent to %s.', 'leastudios-email-templates' ),
					$to
				),
			]
		);
	}
```

- [ ] **Step 6: Replace `resolve_posted_type` with `resolve_posted_definition`**

Replace the existing `resolve_posted_type()` method (lines 630–641) with:

```php
	/**
	 * Map a POSTed `type` key to its registered Email_Type_Definition.
	 *
	 * @return Email_Type_Definition|null
	 */
	private function resolve_posted_definition(): ?Email_Type_Definition {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Callers verify the nonce before invoking this helper.
		$raw = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['type'] ) ) : '';

		return $this->registry->get( $raw );
	}
```

- [ ] **Step 7: Manual sanity check — open the file and grep for stale references**

Run: `grep -n 'Email_Type\|Sample_Context\|resolve_posted_type' src/Admin/Settings_Page.php`
Expected: No matches. If any remain, fix them before continuing.

- [ ] **Step 8: Run any tests that touch Settings_Page**

There are no dedicated unit tests for Settings_Page (it's mostly a render layer). The full test suite gets run in Task 11; this task's verification is the grep above plus PHPStan in the final task.

- [ ] **Step 9: Commit**

```bash
git add src/Admin/Settings_Page.php
git commit -m "Migrate Settings_Page to iterate the registry instead of Email_Type::cases()

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 9: Migrate `Email_Log_List_Table` and `Email_Log_Page`

The list table's type filter dropdown is the last `Email_Type::cases()` consumer. The page needs to be constructed with the registry and forward it.

**Files:**
- Modify: `src/Admin/Email_Log_List_Table.php`
- Modify: `src/Admin/Email_Log_Page.php`

- [ ] **Step 1: Add the registry dependency to `Email_Log_List_Table`**

Open `src/Admin/Email_Log_List_Table.php`. Read the constructor and existing imports (use lines and the `__construct` method) so the existing wiring is preserved.

Replace the `use LEAStudios\EmailTemplates\Email\Email_Type;` line with:

```php
use LEAStudios\EmailTemplates\Email\Email_Type_Registry;
```

If the class has no `__construct` yet (or one that only takes the repo), add the registry to it:

```php
	/**
	 * Constructor.
	 *
	 * @param Email_Log_Repository $repo     The log repository.
	 * @param Email_Type_Registry  $registry The shared type registry.
	 */
	public function __construct( Email_Log_Repository $repo, Email_Type_Registry $registry ) {
		parent::__construct(
			[
				'singular' => 'leastudios_email_log',
				'plural'   => 'leastudios_email_logs',
				'ajax'     => false,
			]
		);

		$this->repo     = $repo;
		$this->registry = $registry;
	}
```

Add the `private Email_Type_Registry $registry;` property declaration alongside `private Email_Log_Repository $repo;`.

- [ ] **Step 2: Update the filter dropdown**

In `Email_Log_List_Table`, replace `foreach ( Email_Type::cases() as $case ) :` (around line 171) and its body with:

```php
				<?php foreach ( $this->registry->all() as $case ) : ?>
					<option value="<?php echo esc_attr( $case->id() ); ?>" <?php selected( $case->id(), $type ); ?>>
						<?php echo esc_html( $case->label() ); ?>
					</option>
				<?php endforeach; ?>
```

`$case->id()` replaces `$case->value`, and `$case->label()` is the same method name on the new interface.

- [ ] **Step 3: Update `Email_Log_Page` to take and pass the registry**

Open `src/Admin/Email_Log_Page.php`. The existing constructor uses promoted properties:

```php
	public function __construct( private readonly Email_Log_Repository $repo ) {}
```

Add the registry import:

```php
use LEAStudios\EmailTemplates\Email\Email_Type_Registry;
```

Extend the promoted-property constructor to add `Email_Type_Registry`:

```php
	public function __construct(
		private readonly Email_Log_Repository $repo,
		private readonly Email_Type_Registry $registry,
	) {}
```

Find the `new Email_Log_List_Table( $this->repo )` instantiation (search the file) and change it to `new Email_Log_List_Table( $this->repo, $this->registry )`.

- [ ] **Step 4: Manual grep check**

Run: `grep -rn 'Email_Type::\|Email_Type ' src/`
Expected: zero results.

Run: `grep -rn 'Sample_Context' src/`
Expected: zero results.

(If any non-zero results, fix before continuing.)

- [ ] **Step 5: Commit**

```bash
git add src/Admin/Email_Log_List_Table.php src/Admin/Email_Log_Page.php
git commit -m "Migrate Email_Log_List_Table and Email_Log_Page to consume the registry

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 10: Wire `Plugin::init` to construct the registry and register built-ins

**Files:**
- Modify: `src/Plugin.php`
- Delete: `src/Email/Email_Type.php`
- Delete: `src/Email/Sample_Context.php`
- Delete: `tests/EmailTypeTest.php`
- Delete: `tests/SampleContextTest.php`

- [ ] **Step 1: Update `Plugin::init`**

Open `src/Plugin.php` and add these imports alongside the existing ones (insert between the existing `use` lines, alphabetised):

```php
use LEAStudios\EmailTemplates\Email\Built_In\Payment_Failed;
use LEAStudios\EmailTemplates\Email\Built_In\Payment_Receipt;
use LEAStudios\EmailTemplates\Email\Built_In\Refund_Processed;
use LEAStudios\EmailTemplates\Email\Built_In\Subscription_Created;
use LEAStudios\EmailTemplates\Email\Built_In\Subscription_Renewed;
use LEAStudios\EmailTemplates\Email\Email_Type_Registry;
```

Then update the `init()` method body. Find the existing `// Core services.` block (around line 39) and replace through to the end of the method with:

```php
	public function init(): void {
		add_action( 'init', [ $this, 'load_textdomain' ] );

		// Core services.
		$replacer = new Merge_Tag_Replacer();

		// Type registry — populated with built-ins and then opened up to
		// third parties via the leastudios_email_templates_register_types
		// action. Third parties must register their callback at file scope
		// in their own plugin (i.e. before plugins_loaded:10 fires) for
		// their types to appear in the admin UI on first render.
		$registry = new Email_Type_Registry();
		$registry->register( new Payment_Receipt() );
		$registry->register( new Subscription_Created() );
		$registry->register( new Subscription_Renewed() );
		$registry->register( new Payment_Failed() );
		$registry->register( new Refund_Processed() );

		/**
		 * Fires once during Plugin::init, after built-in email types are
		 * registered. Third-party plugins call $registry->register() to
		 * add their own Email_Type_Definition implementations.
		 *
		 * @param Email_Type_Registry $registry The registry to mutate.
		 */
		do_action( 'leastudios_email_templates_register_types', $registry );

		// Template wrapping for all emails.
		$wrapper = new Template_Wrapper( $replacer );
		$wrapper->init();

		// Plain-text alternative body for every HTML wp_mail.
		$injector = new Plain_Text_Injector();
		$injector->init();

		// Persistent send log for every transactional email.
		$log_repo = new Email_Log_Repository();
		$logger   = new Send_Logger( $log_repo );
		$logger->init();

		add_action(
			'leastudios_email_templates_log_prune',
			static function () use ( $log_repo ): void {
				/**
				 * Filters the log retention window in days.
				 *
				 * @param int $days Default 30.
				 */
				$days = (int) apply_filters( 'leastudios_email_templates_log_retention_days', 30 );
				$log_repo->prune_older_than( max( 1, $days ) );
			}
		);

		// Email sender for transactional emails.
		$sender = new Email_Sender( $replacer, $registry );

		// Payment integration (only when payments plugin is active).
		if ( $this->is_payments_active() ) {
			$resolver = new Payment_Data_Resolver();
			$listener = new Payment_Email_Listener( $sender, $resolver );
			$listener->init();
		}

		// Admin settings.
		if ( is_admin() ) {
			$settings = new Settings_Page( $registry );
			$settings->init();

			$log_page = new Email_Log_Page( $log_repo, $registry );
			$log_page->init();
		}
	}
```

- [ ] **Step 2: Delete the old enum and helper**

Run: `rm src/Email/Email_Type.php src/Email/Sample_Context.php tests/EmailTypeTest.php tests/SampleContextTest.php`

- [ ] **Step 3: Regenerate the autoloader**

Run: `composer dump-autoload`
Expected: `Generated optimized autoload files containing X classes`.

- [ ] **Step 4: Final-state grep — confirm no orphaned references**

Run: `grep -rn 'Email_Type::\|use LEAStudios\\\\EmailTemplates\\\\Email\\\\Email_Type;\|Sample_Context' src/ tests/`
Expected: zero results. If any remain, fix before continuing.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "Wire Plugin::init to the registry; delete Email_Type enum and Sample_Context

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 11: Add the third-party registration integration test

This is the acceptance criterion: a third-party plugin can register a custom type via the `leastudios_email_templates_register_types` action and have it dispatch end-to-end.

**Files:**
- Create: `tests/RegistryFixturesTest.php`

- [ ] **Step 1: Write the test**

Write `tests/RegistryFixturesTest.php`:

```php
<?php
/**
 * Integration test: a third-party plugin can register a custom email type
 * via the leastudios_email_templates_register_types action and have it
 * dispatch through Email_Sender::send() end-to-end.
 *
 * @package LEAStudios\EmailTemplates\Tests
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Tests;

use LEAStudios\EmailTemplates\Email\Abstract_Email_Type;
use LEAStudios\EmailTemplates\Email\Email_Sender;
use LEAStudios\EmailTemplates\Email\Email_Type_Registry;
use LEAStudios\EmailTemplates\Email\Escape_Mode;
use LEAStudios\EmailTemplates\Email\Merge_Tag_Replacer;
use LEAStudios\Tests\TestCase;

/**
 * Fixture standing in for a third-party plugin's custom email type.
 */
final class Fake_Welcome extends Abstract_Email_Type {
	public function id(): string { return 'fake_welcome'; }
	public function label(): string { return 'Fake Welcome'; }
	public function default_subject(): string { return 'Welcome to {site_name}, {customer_name}!'; }
	public function default_body(): string { return '<h1>Hi {customer_name}!</h1>'; }
	public function available_tags(): array {
		return [
			'{customer_name}' => [ 'description' => 'Customer name', 'escape' => Escape_Mode::HTML ],
			'{site_name}'     => [ 'description' => 'Site name', 'escape' => Escape_Mode::HTML ],
		];
	}
	public function sample_context(): array {
		return [ 'customer_name' => 'Jane' ];
	}
	// is_transactional_required() defaults to false — that's the third-party expectation.
}

class RegistryFixturesTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		reset_phpmailer_instance();

		delete_option( 'leastudios_email_templates_emails' );
	}

	public function test_fixture_appears_in_registry_after_action_fires(): void {
		$registry = new Email_Type_Registry();

		add_action(
			'leastudios_email_templates_register_types',
			static function ( Email_Type_Registry $r ): void {
				$r->register( new Fake_Welcome() );
			}
		);

		do_action( 'leastudios_email_templates_register_types', $registry );

		$this->assertTrue( $registry->has( 'fake_welcome' ) );
		$this->assertInstanceOf( Fake_Welcome::class, $registry->get( 'fake_welcome' ) );

		remove_all_actions( 'leastudios_email_templates_register_types' );
	}

	public function test_fixture_is_not_transactional_required_by_default(): void {
		$def = new Fake_Welcome();
		$this->assertFalse( $def->is_transactional_required() );
	}

	public function test_fixture_dispatches_through_sender_end_to_end(): void {
		$registry = new Email_Type_Registry();
		$registry->register( new Fake_Welcome() );

		$sender = new Email_Sender( new Merge_Tag_Replacer(), $registry );

		$result = $sender->send( 'fake_welcome', 'jane@example.com', [ 'customer_name' => 'Jane' ] );

		$this->assertTrue( $result, 'wp_mail should have succeeded for the fixture type' );

		$mailer = tests_retrieve_phpmailer_instance();
		$sent   = $mailer->get_sent();

		$this->assertSame( 'jane@example.com', $sent->to[0][0] );
		$this->assertStringContainsString( 'Jane', $sent->subject );
		$this->assertStringContainsString( 'Jane', $sent->body );
	}

	public function test_third_party_can_override_a_built_in_via_last_write_wins(): void {
		$registry = new Email_Type_Registry();

		// Simulate the Plugin::init order: built-ins registered first, then
		// the action lets third parties override.
		$registry->register( new \LEAStudios\EmailTemplates\Email\Built_In\Payment_Receipt() );

		$override = new class() extends Abstract_Email_Type {
			public function id(): string { return 'payment_receipt'; }
			public function label(): string { return 'Overridden Receipt'; }
			public function default_subject(): string { return 'OVERRIDE'; }
			public function default_body(): string { return '<p>OVERRIDE</p>'; }
			public function available_tags(): array { return []; }
			public function sample_context(): array { return []; }
		};

		$registry->register( $override );

		$this->assertSame( 'Overridden Receipt', $registry->get( 'payment_receipt' )->label() );
	}
}
```

- [ ] **Step 2: Run the test**

Run: `vendor/bin/phpunit tests/RegistryFixturesTest.php -v`
Expected: PASS, 4 tests.

- [ ] **Step 3: Commit**

```bash
git add tests/RegistryFixturesTest.php
git commit -m "Add registry integration tests covering third-party registration and override

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 12: Full-suite verification and release-note commit

**Files:**
- (Optionally) Create: `docs/release-notes/phase-7.md` if the project keeps release notes; otherwise the commit message below is the record.

- [ ] **Step 1: Run PHPCS**

Run: `composer phpcs`
Expected: zero violations.

If any violations appear, fix them inline (typically import ordering or whitespace), then re-run.

- [ ] **Step 2: Run PHPStan at level 7**

Run: `composer phpstan`
Expected: `[OK] No errors`.

PHPStan errors most likely to appear:
- "Method X has no return typehint specified" — fix by adding the typehint.
- "Property has no typehint specified" — fix by adding the typehint.
- "Cannot call method `id()` on null" inside Settings_Page handlers — verify the early `null` check on `resolve_posted_definition()` is intact.

Fix any reported errors, re-run until clean.

- [ ] **Step 3: Run the full PHPUnit suite**

Run: `composer test`
Expected: PASS, ≥100 tests, 0 failures, 0 errors.

The exact test count before this phase was 100. After Phase 7:
- Removed: `EmailTypeTest` (6 tests), `SampleContextTest` (4 tests) → −10
- Added: `EmailTypeDefinitionInterfaceTest` (1), `AbstractEmailTypeTest` (3), `EmailTypeRegistryTest` (5), `BuiltInTypesTest` (~40 via dataProvider rows × 8 methods + 1 standalone = ~41), `RegistryFixturesTest` (4) → +54
- Modified: `EmailSenderTest` adds 2 tests (unknown-id, compose-unknown-id)

Expected new total: ~146 tests (exact count may vary slightly by dataProvider expansion).

- [ ] **Step 4: WP-CLI smoke check**

Run from the WordPress install root (`/Users/adamlea/Herd/leastudios-plugins/`):

```bash
wp eval '
$replacer = new \LEAStudios\EmailTemplates\Email\Merge_Tag_Replacer();
$registry = new \LEAStudios\EmailTemplates\Email\Email_Type_Registry();
$registry->register( new \LEAStudios\EmailTemplates\Email\Built_In\Payment_Receipt() );
echo "Registered: " . implode(", ", array_keys( $registry->all() )) . PHP_EOL;
echo "Label: " . $registry->get("payment_receipt")->label() . PHP_EOL;
'
```

Expected output:
```
Registered: payment_receipt
Label: Payment Receipt
```

- [ ] **Step 5: Final summary commit**

If anything trivial fell out of the previous tasks (e.g. CLAUDE.md needs a reference update — check the "Architecture map" section that mentions `Email_Type.php` as the single source of truth), bundle it into a final commit:

```bash
git add -A
git status   # verify only doc / tidy changes are staged
git commit -m "Phase 7: registry-driven email types, release notes

- Email_Type enum replaced with Email_Type_Definition + Email_Type_Registry
- Five built-ins moved to src/Email/Built_In/, each implementing
  is_transactional_required() = true (Phase 9 input)
- Sample_Context subsumed by Definition::sample_context()
- New leastudios_email_templates_register_types action lets third parties
  register custom email types; last-write-wins so they can also replace
  built-ins
- Breaking: leastudios_email_templates_email_sent's first argument is now
  string (the type id) instead of Email_Type. Send_Logger updated.
- Breaking: Email_Sender::send and ::compose accept string \$type_id
  instead of Email_Type.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

If there is nothing additional to stage, skip this step — the per-task commits are the record.

---

## Self-review pass

After completing all tasks, verify:

1. **No stale references**:
   `grep -rn 'Email_Type\b\|Sample_Context' src/ tests/`
   Expected: only the new types (`Email_Type_Definition`, `Email_Type_Registry`) and `tests/EmailTypeDefinitionInterfaceTest.php` / `tests/EmailTypeRegistryTest.php` appear. No raw `Email_Type` (the old enum) anywhere.

2. **`leastudios_email_templates_emails` option key stability**:
   After all changes, register all five built-ins via a `wp eval` REPL and confirm that an admin save still writes the option with keys exactly `payment_receipt`, `subscription_created`, etc. — same as before Phase 7. Existing customer data must remain valid.

3. **Acceptance check** (from the roadmap):
   "A test fixture plugin can register `my_plugin_welcome` via the action and have it appear in the Email Types tab with editable subject/body." — Covered by `tests/RegistryFixturesTest.php::test_fixture_dispatches_through_sender_end_to_end`. The "appear in Email Types tab" half is covered indirectly by `Settings_Page::render_email_types_tab` iterating `$this->registry->all()` (Task 8 Step 3) — no automated test for the admin render, but a manual smoke in the browser is sufficient confirmation.

4. **Release-note item to surface to the user when handing back**:
   - `Email_Type` enum is gone. Third-party hooks on `leastudios_email_templates_email_sent` that typed the first arg as `Email_Type` will fatal — the new type is `string`.
   - `Email_Sender::send` and `::compose` signatures changed. Both internal callers have been updated; no external code in the suite consumes these directly.
   - `Email_Sender` constructor now requires `Email_Type_Registry`.
   - `Sample_Context` is deleted; each definition exposes its own `sample_context()`.

5. **Phase-9 hand-off note**:
   `is_transactional_required(): bool` is now on every definition. Phase 9's unsubscribe logic should consult it via `$registry->get($type_id)?->is_transactional_required()` before honouring an unsubscribe preference.
