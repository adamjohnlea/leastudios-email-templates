# Phase 10 — Final Cleanup + 1.1.0 Release Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Land the four lingering bugs from Phase 9, rewrite README.md + readme.txt to reflect everything Phases 1-9 shipped, and bump the plugin to 1.1.0.

**Architecture:** Zero new modules, zero schema changes. Three bugfixes mutate existing files in place with TDD-driven tests. The fourth bug is a comment move (no test). Two doc rewrites and one version bump close out the batch.

**Tech Stack:** PHP 8.1+, WordPress 6.8.2, PHPUnit 9.6, PHPStan level 7, PHPCS via WPCS.

**Reference spec:** [`docs/superpowers/specs/2026-05-23-phase-10-final-cleanup-design.md`](../specs/2026-05-23-phase-10-final-cleanup-design.md). Re-read decisions D1–D7 before starting.

---

## File layout overview

**Modified:**
- `src/Email/Email_Sender.php` (Tasks 1, 2 — array_merge order + gate target)
- `src/REST/Unsubscribe_Controller.php` (Task 3 — luminance helper + template locals)
- `templates/unsubscribe/landing-unsubscribed.php` (Task 3 — replace hard-coded colors with template locals)
- `src/CLI/Commands.php` (Task 4 — move 1000-row cap rationale into docblock)
- `tests/EmailSenderTest.php` (Tasks 1, 2 — new tests)
- `tests/UnsubscribeControllerTest.php` (Task 3 — luminance picker tests)
- `README.md` (Task 5 — full rewrite)
- `readme.txt` (Tasks 6, 7 — full rewrite + Stable tag bump)
- `leastudios-email-templates.php` (Task 7 — Version header + constant bump)

**Created:** none.

---

## Conventions reminder

- Every PHP file: `<?php` header docblock, `declare(strict_types=1);`, namespace, `defined('ABSPATH') || exit;` — none of the modifications touch headers.
- Run `composer phpcs && composer phpstan && composer test` at the end of each task. Don't move on until clean.
- Commits use the project's plain-descriptive style (no conventional-commit prefix).
- One commit per task per D6.

---

## Task 1: Fix `array_merge` order so resolved `unsubscribe_url` wins

**Spec reference:** D2.

**Files:**
- Modify: `src/Email/Email_Sender.php:170-173`
- Test: `tests/EmailSenderTest.php`

- [ ] **Step 1: Write the failing test**

Append to `tests/EmailSenderTest.php`, inside the `EmailSenderTest` class (alongside the other `test_unsubscribe_url_*` methods):

```php
public function test_resolved_unsubscribe_url_wins_over_caller_supplied_context(): void {
	$this->register_phase9_fixture();

	$composed = $this->sender->compose(
		'phase9_fixture',
		[ 'unsubscribe_url' => 'https://attacker.example/evil' ],
		'jane@example.com'
	);

	$this->assertNotNull( $composed );
	$this->assertStringNotContainsString(
		'attacker.example',
		(string) $composed['body'],
		'caller-supplied unsubscribe_url must not override our resolved value'
	);
	$this->assertStringContainsString(
		'/wp-json/leastudios-email-templates/v1/unsubscribe',
		(string) $composed['body'],
		'our recipient-aware resolution must win'
	);
}
```

The `register_phase9_fixture` helper is already defined on the test class from Phase 9 — reuse it.

- [ ] **Step 2: Run the test, see it fail**

Run: `vendor/bin/phpunit tests/EmailSenderTest.php --filter test_resolved_unsubscribe_url_wins_over_caller_supplied_context`

Expected: FAIL. The rendered body contains `attacker.example/evil` because the current `array_merge([injection], $context)` lets `$context` win.

- [ ] **Step 3: Swap the merge order**

In `src/Email/Email_Sender.php`, find the block at lines 170-173:

```php
		$context = array_merge(
			[ 'unsubscribe_url' => $this->resolve_unsubscribe_url( $to, $definition ) ],
			$context
		);
```

Replace with:

```php
		// Our recipient-aware resolution always wins over caller-supplied
		// $context values. The documented override surface is the
		// leastudios_email_templates_unsubscribe_url filter, not $context.
		$context = array_merge(
			$context,
			[ 'unsubscribe_url' => $this->resolve_unsubscribe_url( $to, $definition ) ]
		);
```

- [ ] **Step 4: Run the test, see it pass**

Run: `vendor/bin/phpunit tests/EmailSenderTest.php --filter test_resolved_unsubscribe_url_wins_over_caller_supplied_context`

Expected: PASS.

- [ ] **Step 5: Full suite + lint + static analysis**

Run: `composer phpcs && composer phpstan && composer test`

Expected: all clean. Test count goes from 214 → 215.

- [ ] **Step 6: Commit**

```bash
git add src/Email/Email_Sender.php tests/EmailSenderTest.php
git commit -m "$(cat <<'EOF'
Fix array_merge order so resolved unsubscribe_url wins over context

PHP's array_merge lets the SECOND argument win on key collision. The
Phase 9 injection was passing [injection] as the first arg and $context
as the second, so a caller passing unsubscribe_url in their $context
silently overrode our recipient-aware resolution.

The documented override surface is the leastudios_email_templates_
unsubscribe_url filter, which knows the recipient and type id. Callers
should not have a hidden side channel via $context — that defeats the
gate semantics (required types get empty, empty recipients get empty).

Swap the arguments so [injection] is the merge winner.
EOF
)"
```

---

## Task 2: Gate suppression against the resolved delivery address

**Spec reference:** D1.

**Files:**
- Modify: `src/Email/Email_Sender.php:61-77` (move the gate after `recipient_override` resolution)
- Test: `tests/EmailSenderTest.php`

This task restructures the top of `send()`. Read the current method (lines 61-138) end-to-end before editing.

- [ ] **Step 1: Write the failing tests**

Append two tests to `tests/EmailSenderTest.php`:

```php
public function test_gate_evaluates_resolved_delivery_address_when_override_is_set(): void {
	// Suppress the original $to. Set a recipient_override that is NOT
	// suppressed. The email must still send to the override target.
	$this->manager->suppress( 'jane@example.com', 'cli' );

	update_option(
		'leastudios_email_templates_emails',
		[
			'phase9_fixture' => [
				'enabled'            => true,
				'subject'            => '',
				'body'               => '',
				'recipient_override' => 'ops@example.com',
			],
		]
	);
	$this->register_phase9_fixture();

	$captured_to = null;
	add_filter(
		'pre_wp_mail',
		static function ( $value, $atts ) use ( &$captured_to ) {
			$captured_to = $atts['to'];
			return true; // short-circuit wp_mail
		},
		10,
		2
	);

	$result = $this->sender->send( 'phase9_fixture', 'jane@example.com', [], 'web' );

	remove_all_filters( 'pre_wp_mail' );
	delete_option( 'leastudios_email_templates_emails' );

	$this->assertTrue( $result, 'send must succeed when only the original $to is suppressed' );
	$this->assertSame( 'ops@example.com', $captured_to, 'wp_mail must receive the override address' );
}

public function test_gate_fires_when_override_target_itself_is_suppressed(): void {
	$this->manager->suppress( 'ops@example.com', 'cli' );

	update_option(
		'leastudios_email_templates_emails',
		[
			'phase9_fixture' => [
				'enabled'            => true,
				'subject'            => '',
				'body'               => '',
				'recipient_override' => 'ops@example.com',
			],
		]
	);
	$this->register_phase9_fixture();

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

	$result = $this->sender->send( 'phase9_fixture', 'jane@example.com', [], 'web' );

	remove_all_filters( 'pre_wp_mail' );
	remove_all_actions( 'leastudios_email_templates_email_suppressed' );
	delete_option( 'leastudios_email_templates_emails' );

	$this->assertFalse( $result );
	$this->assertFalse( $mail_called );
	$this->assertNotNull( $suppressed_args );
	$this->assertSame( 'ops@example.com', $suppressed_args['to'], '_email_suppressed must record the resolved delivery address' );
}
```

- [ ] **Step 2: Run, see them fail**

Run: `vendor/bin/phpunit tests/EmailSenderTest.php --filter test_gate_`

Expected: BOTH tests fail. The first because the current gate fires against Jane and never reaches the override-resolution code (returns false from `fire_suppressed`). The second because the current gate also fires against Jane (not ops), so `$suppressed_args['to']` is `'jane@example.com'`, not `'ops@example.com'`.

- [ ] **Step 3: Refactor `send()` to resolve override before gating**

In `src/Email/Email_Sender.php`, replace the entire `send()` method body (lines 61-138 — the method opening brace through the closing brace including the existing `return $result;` near the bottom).

The new shape resolves the delivery address first, then gates, then composes, then sends. Replace with:

```php
	public function send( string $type_id, string $to, array $context = [], string $source = 'web' ): bool {
		$definition = $this->registry->get( $type_id );

		if ( null === $definition ) {
			return false;
		}

		// Resolve recipient_override BEFORE gating — the suppression check is
		// against who would actually receive the mail, not the original $to.
		$settings = $this->get_type_settings( $type_id );
		$delivery = $to;
		if ( ! empty( $settings['recipient_override'] ) && is_email( $settings['recipient_override'] ) ) {
			$delivery = (string) $settings['recipient_override'];
		}

		// Phase 9 — suppression gate. Required types bypass. The gate target
		// is the resolved delivery address, so a redirect via recipient_override
		// is checked against the override target's opt-out preference, not the
		// original caller-supplied address.
		if ( ! $definition->is_transactional_required() && '' !== $delivery && $this->manager->is_suppressed( $delivery ) ) {
			return $this->fire_suppressed( $type_id, $delivery, $context, $source );
		}

		$composed = $this->compose( $type_id, $context, $delivery );

		if ( null === $composed ) {
			return false;
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
				'to'      => $delivery,
				'subject' => $composed['subject'],
				'message' => $composed['body'],
				'headers' => $composed['headers'],
			],
			$type_id,
			$context
		);

		// Phase 9 — auto-append the unsubscribe footer for non-required types
		// with a real recipient. Required types and empty recipients skip
		// this; the wrapper (Template_Wrapper) downstream stays type-ignorant.
		if ( ! $definition->is_transactional_required() && '' !== $delivery && is_email( $delivery ) ) {
			$args['message'] .= $this->render_unsubscribe_footer( $delivery, $type_id );
		}

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

Key differences from the original:
- A new `$delivery` local is computed up front from `recipient_override` (or `$to`).
- The gate runs against `$delivery`, not `$to`.
- `compose()` is called with `$delivery` so `{unsubscribe_url}` resolves for the delivery address.
- The auto-footer check uses `$delivery`.
- The old block at the original lines 79-83 that re-assigned `$to = $settings['recipient_override']` after `compose()` is removed (its job is now done up front).

The unchanged `fire_suppressed`, `compose`, `render_unsubscribe_footer`, and `get_type_settings` methods stay as they are. Only `send()` is replaced.

- [ ] **Step 4: Run the new tests, see them pass**

Run: `vendor/bin/phpunit tests/EmailSenderTest.php --filter test_gate_`

Expected: both pass.

- [ ] **Step 5: Run the full suite**

Run: `composer test`

Expected: all green. Test count 215 → 217. Pay particular attention to the existing `test_send_applies_recipient_override` test — the refactor must keep it green because the override semantics for non-suppressed sends are unchanged.

- [ ] **Step 6: Lint + static analysis**

Run: `composer phpcs && composer phpstan`

Expected: clean.

- [ ] **Step 7: Commit**

```bash
git add src/Email/Email_Sender.php tests/EmailSenderTest.php
git commit -m "$(cat <<'EOF'
Gate suppression against the resolved delivery address

Before this change, Email_Sender::send ran the suppression gate
against the original $to argument and only later resolved any per-type
recipient_override. Result: if an admin set recipient_override on a
non-required type and the original $to was suppressed, the email
never reached the override target — even though the override target's
opt-out preference was never consulted.

Resolve the delivery address up front (recipient_override ?: $to), gate
against that, then compose and dispatch with the resolved address.
The _email_suppressed action's $to argument is now the resolved
delivery address, so log rows record who would actually have received
the mail. This is the audit signal that matters; the override target
is the entity whose opt-out is relevant.

Two new tests pin both directions: gate-evaluates-resolved-when-only-
original-is-suppressed and gate-fires-when-override-target-itself-is-
suppressed.
EOF
)"
```

---

## Task 3: Theme unsubscribe landing button with `branding[primary_color]`

**Spec reference:** D3 + D4.

**Files:**
- Modify: `src/REST/Unsubscribe_Controller.php` (add luminance helper + pass locals to template)
- Modify: `templates/unsubscribe/landing-unsubscribed.php` (use template locals for the button)
- Test: `tests/UnsubscribeControllerTest.php` (luminance picker tests)

- [ ] **Step 1: Write the failing tests**

Append to `tests/UnsubscribeControllerTest.php`, inside the class:

```php
public function test_pick_button_text_color_returns_white_for_dark_backgrounds(): void {
	$this->assertSame( '#ffffff', Unsubscribe_Controller::pick_button_text_color( '#000000' ) );
	$this->assertSame( '#ffffff', Unsubscribe_Controller::pick_button_text_color( '#4f46e5' ) );
	$this->assertSame( '#ffffff', Unsubscribe_Controller::pick_button_text_color( '#0000ff' ) );
	$this->assertSame( '#ffffff', Unsubscribe_Controller::pick_button_text_color( '#1a1a1a' ) );
}

public function test_pick_button_text_color_returns_dark_for_light_backgrounds(): void {
	$this->assertSame( '#111827', Unsubscribe_Controller::pick_button_text_color( '#ffffff' ) );
	$this->assertSame( '#111827', Unsubscribe_Controller::pick_button_text_color( '#f0f0f0' ) );
	$this->assertSame( '#111827', Unsubscribe_Controller::pick_button_text_color( '#ffff00' ) );
	$this->assertSame( '#111827', Unsubscribe_Controller::pick_button_text_color( '#eaeaea' ) );
}

public function test_pick_button_text_color_defaults_white_for_invalid_input(): void {
	$this->assertSame( '#ffffff', Unsubscribe_Controller::pick_button_text_color( '' ) );
	$this->assertSame( '#ffffff', Unsubscribe_Controller::pick_button_text_color( '#fff' ) );        // 3-char shorthand not supported
	$this->assertSame( '#ffffff', Unsubscribe_Controller::pick_button_text_color( 'red' ) );        // CSS keyword
	$this->assertSame( '#ffffff', Unsubscribe_Controller::pick_button_text_color( '#xyz123' ) );    // invalid hex
}
```

- [ ] **Step 2: Run, see them fail**

Run: `vendor/bin/phpunit tests/UnsubscribeControllerTest.php --filter test_pick_button_text_color`

Expected: FAIL — `Unsubscribe_Controller::pick_button_text_color` does not exist.

- [ ] **Step 3: Add the luminance picker to `Unsubscribe_Controller`**

In `src/REST/Unsubscribe_Controller.php`, add this static method just below the existing `render_template` method (i.e. as a new method before the closing class brace):

```php
	/**
	 * Pick a readable text color for a brand-colored button background.
	 *
	 * Uses the YIQ-style luminance formula (0.299*R + 0.587*G + 0.114*B)
	 * with a 186 threshold to switch between dark text (#111827) on light
	 * backgrounds and white text (#ffffff) on dark backgrounds. This is the
	 * conventional WCAG-derived cutoff and keeps the button readable for
	 * any 6-digit hex an admin might set.
	 *
	 * Returns the safe default (#ffffff) for malformed input (empty, 3-char
	 * shorthand, CSS keywords, non-hex characters). Three-char shorthand is
	 * intentionally not supported — the branding option always stores
	 * full 6-digit hex.
	 *
	 * @param string $hex The button background as a 6-digit hex (#RRGGBB).
	 * @return string Either '#111827' (dark) or '#ffffff' (light).
	 */
	public static function pick_button_text_color( string $hex ): string {
		if ( 1 !== preg_match( '/^#([0-9a-fA-F]{6})$/', $hex, $matches ) ) {
			return '#ffffff';
		}

		$r = (int) hexdec( substr( $matches[1], 0, 2 ) );
		$g = (int) hexdec( substr( $matches[1], 2, 2 ) );
		$b = (int) hexdec( substr( $matches[1], 4, 2 ) );

		$luminance = ( 0.299 * $r ) + ( 0.587 * $g ) + ( 0.114 * $b );

		return $luminance > 186 ? '#111827' : '#ffffff';
	}
```

- [ ] **Step 4: Run the new tests, see them pass**

Run: `vendor/bin/phpunit tests/UnsubscribeControllerTest.php --filter test_pick_button_text_color`

Expected: PASS — all three tests green.

- [ ] **Step 5: Wire the template locals at render time**

In `src/REST/Unsubscribe_Controller.php`, find the `unsubscribe()` method (around line 106) and replace its body so it computes the button colors and passes them to the template:

Replace:

```php
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
```

With:

```php
	public function unsubscribe( WP_REST_Request $request ): WP_REST_Response {
		$token = (string) $request->get_param( 'token' );
		$email = $this->manager->verify_token( $token );

		if ( null === $email ) {
			return $this->html_response( 400, $this->render_template( 'landing-error.php', [] ) );
		}

		$this->manager->suppress( $email, 'link' );

		$branding  = (array) get_option( 'leastudios_email_templates_branding', [] );
		$button_bg = ! empty( $branding['primary_color'] ) && is_string( $branding['primary_color'] )
			? (string) $branding['primary_color']
			: '#4f46e5';

		return $this->html_response(
			200,
			$this->render_template(
				'landing-unsubscribed.php',
				[
					'email'       => $email,
					'token'       => $token,
					'button_bg'   => $button_bg,
					'button_text' => self::pick_button_text_color( $button_bg ),
				]
			)
		);
	}
```

- [ ] **Step 6: Use the locals in the template**

In `templates/unsubscribe/landing-unsubscribed.php`, replace the existing `<style>` block (lines 21-30) with:

```php
	<style>
		body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f3f4f6; margin: 0; padding: 40px 20px; color: #111827; }
		.card { max-width: 480px; margin: 0 auto; background: #fff; border-radius: 8px; padding: 32px; box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
		h1 { font-size: 20px; margin: 0 0 12px; }
		p { font-size: 14px; line-height: 1.5; color: #4b5563; margin: 0 0 16px; }
		.email { font-weight: 600; color: #111827; }
		button { font: inherit; cursor: pointer; background: <?php echo esc_attr( $button_bg ); ?>; color: <?php echo esc_attr( $button_text ); ?>; border: 0; border-radius: 6px; padding: 10px 16px; font-weight: 600; transition: opacity 0.15s ease; }
		button:hover { opacity: 0.9; }
		.muted { color: #6b7280; font-size: 12px; margin-top: 24px; }
	</style>
```

Two changes:
- `button { background: …; color: …; }` reads the new `$button_bg` / `$button_text` locals (escaped via `esc_attr` since they go into a CSS context — strict 6-digit hex passes esc_attr untouched).
- `button:hover { opacity: 0.9; }` replaces the hard-coded `#4338ca` darker shade.

Also add the new variables to the docblock at the top of the file. Replace the existing docblock (lines 2-10):

```php
<?php
/**
 * Landing page shown after one-click unsubscribe.
 *
 * Variables in scope:
 *   string $email       Suppressed email (normalized).
 *   string $token       Signed token (for the resubscribe form).
 *   string $button_bg   Resolved brand color for the resubscribe button (6-digit hex).
 *   string $button_text Resolved text color for the button (#111827 or #ffffff).
 *
 * @package LEAStudios\EmailTemplates
 */
```

- [ ] **Step 7: Full test suite**

Run: `composer test`

Expected: all green. Test count 217 → 220 (3 new tests). The existing REST tests must still pass — the new locals are added; nothing else about the response shape changes.

- [ ] **Step 8: Lint + static analysis**

Run: `composer phpcs && composer phpstan`

Expected: clean. PHPStan will type-check the `is_string` guard on `$branding['primary_color']`.

- [ ] **Step 9: Commit**

```bash
git add src/REST/Unsubscribe_Controller.php templates/unsubscribe/landing-unsubscribed.php tests/UnsubscribeControllerTest.php
git commit -m "$(cat <<'EOF'
Theme unsubscribe landing button with branding primary_color

The resubscribe button on the post-unsubscribe landing page was
hard-coded to indigo (#4f46e5 background, #4338ca hover). Sites with
non-indigo branding got a button that didn't match the email styling.

Read branding[primary_color] from the option, fall back to #4f46e5
when it's empty. The button text color is computed at render time
from a YIQ-style luminance check on the background (threshold 186)
so any 6-digit hex stays readable — admins who pick a near-white
brand color get dark text rather than an unreadable white-on-white
button.

The hover state uses opacity 0.9 instead of a computed darker shade.
Avoids hex-darkening math and degrades gracefully for any color,
including ones that are already very dark.

Unsubscribe_Controller::pick_button_text_color is a public static
helper so it can be unit-tested without spinning up REST. The picker
returns its safe default (#ffffff) for malformed input — empty, 3-char
shorthand, CSS keywords, non-hex characters — even though the option
always stores full 6-digit hex.
EOF
)"
```

---

## Task 4: Move 1000-row cap rationale into `build_suppression_rows` docblock

**Spec reference:** D4 in spec ("Task 14 docblock cap" deferral). Trivial — no test, no behavior change.

**Files:**
- Modify: `src/CLI/Commands.php` (the `build_suppression_rows` method, around lines 375-390)

- [ ] **Step 1: Move the inline comment into the docblock**

In `src/CLI/Commands.php`, find the existing `build_suppression_rows` method (the one with the inline `// 1000 is a generous ceiling …` comment). Replace the entire method with:

```php
	/**
	 * Return one row per suppressed address — used by `list-suppressions`
	 * and by tests. Public so the data shape can be asserted without
	 * mocking the WP_CLI output.
	 *
	 * Capped at 1000 rows. The CLI list is for support/ops, not bulk
	 * export — the page is filterable from the admin side and the
	 * suppressions table is expected to stay well below the cap on
	 * any real site. Sites that need bulk export should query the
	 * table directly via wp db query or the repository class.
	 *
	 * @return array<int, array{email:string, suppressed_at:string, source:string}>
	 */
	public function build_suppression_rows(): array {
		$page = $this->manager->paginate( [], 1000, 1 );

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

Two changes from the current version:
- The docblock gains the explanatory paragraph about the 1000 cap.
- The inline `// 1000 is a generous ceiling …` comment line is removed (the docblock covers it).

- [ ] **Step 2: Lint + tests**

Run: `composer phpcs && composer test`

Expected: clean. No behavior change, so test count stays at 220.

- [ ] **Step 3: Commit**

```bash
git add src/CLI/Commands.php
git commit -m "$(cat <<'EOF'
Move 1000-row cap rationale into build_suppression_rows docblock

The rationale for the cap on wp leastudios-email-templates list-
suppressions belongs in the method's contract (visible to IDE
tooltips, doc generators, and code review), not in an inline comment
that only the next person editing the method body will see.

No behavior change; the cap is still 1000.
EOF
)"
```

---

## Task 5: Rewrite `README.md` for 1.1.0

**Spec reference:** D5.

**Files:**
- Modify: `README.md` (full rewrite)

- [ ] **Step 1: Read the current README so the rewrite preserves any project-specific phrasing**

Run: `cat README.md`

Take note of: the existing intro tone, any author/contributor lines, any badges, any installation specifics that should be preserved.

- [ ] **Step 2: Rewrite the file end-to-end**

Replace the entire content of `README.md` with the structure below. Adapt the prose to match the existing voice, but every section must be present and accurate.

```markdown
# leaStudios Email Templates

Wraps every outgoing WordPress email in a branded HTML template and adds a transactional-email pipeline for leaStudios Payments events (receipts, subscription created/renewed, payment failed, refund processed) with full opt-out / suppression support.

- **Requires WordPress:** 6.4+
- **Tested up to:** 6.9
- **Requires PHP:** 8.1+
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
- PHP 8.1 minimum (`composer.json` pins `config.platform.php` to 8.2 for CI).
- Works standalone. The payment transactional emails activate when `LEASTUDIOS_PAYMENTS_VERSION` is defined.

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
```

- [ ] **Step 3: Lint check (markdown only — no tools wired)**

Skim the file visually. Ensure no broken Markdown (mismatched backticks, missing blank lines between sections). The plugin has no markdown linter wired so this is an eyeball check.

- [ ] **Step 4: Commit**

```bash
git add README.md
git commit -m "$(cat <<'EOF'
Rewrite README.md for 1.1.0

The README dated to April 28 — it predated all of Phases 1-9 and
described the 1.0.0 feature surface only (branded wrapper + payment
transactional emails). Replace with a current pass at 1.1.0 fidelity.

Sections: lead paragraph (three responsibilities), quick start,
features (every shipped feature from Phases 1-9), WP-CLI commands
(all six), public extension points (actions, filters, headers),
database tables, compatibility, changelog.

The changelog keeps the 1.0.0 entry as the previous release marker
and adds a 1.1.0 entry summarizing what shipped.
EOF
)"
```

---

## Task 6: Rewrite `readme.txt` for the WordPress.org plugin directory at 1.1.0

**Spec reference:** D5.

**Files:**
- Modify: `readme.txt` (full rewrite)

Note: this task does NOT bump the `Stable tag:` line yet — that happens in Task 7 alongside the constant/header bump. This task sets up everything else.

- [ ] **Step 1: Read the current file to preserve Contributors / Tags lines**

Run: `cat readme.txt`

Note the existing `Contributors:` (`leastudios`), `Tags:`, and any project-specific phrasing. Preserve these.

- [ ] **Step 2: Rewrite the file end-to-end**

Replace the entire content of `readme.txt` with:

```
=== leaStudios Email Templates ===
Contributors: leastudios
Tags: email templates, branded emails, payment emails, transactional emails, unsubscribe
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 1.0.0
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

= 1.1.0 =
Adds a suppression / unsubscribe gate: non-required transactional types (e.g., subscription_created) now skip recipients who have opted out via the auto-appended footer link. Required types (receipts, refunds, payment-failure, renewal receipts) bypass the gate. Review the new Email Templates → Suppressions admin page after upgrade.
```

Note the `Stable tag:` line stays at `1.0.0` in this task — Task 7 bumps it.

- [ ] **Step 3: Eyeball the formatting**

WordPress.org expects strict header formatting on the first lines and uses `==` / `=` heading levels. Re-read the file end-to-end to confirm no leading whitespace issues, no missed blank lines between sections.

- [ ] **Step 4: Commit**

```bash
git add readme.txt
git commit -m "$(cat <<'EOF'
Rewrite readme.txt for the WP.org plugin directory at 1.1.0

The previous readme.txt described 1.0.0 plus partial Phase 1-8 work.
Replace with a current pass covering everything Phases 1-9 shipped.

Sections: Description (three responsibilities), Features (full
Phase 1-9 list), Frequently Asked Questions (no payments plugin
required, unsubscribe support, secret rotation, footer disable,
extending), Changelog (1.1.0 + retained 1.0.0), Upgrade Notice
(suppression-gate callout for site owners).

The Stable tag stays at 1.0.0 in this commit — the version bump
lands in the next commit so the tree is at a consistent 1.1.0 state
across header, constant, and Stable tag in one diff.
EOF
)"
```

---

## Task 7: Bump plugin version to 1.1.0

**Spec reference:** D6/D7 (last commit, the "ship" signal).

**Files:**
- Modify: `leastudios-email-templates.php` (Plugin Header `Version:` line + `LEASTUDIOS_EMAIL_TEMPLATES_VERSION` constant)
- Modify: `readme.txt` (`Stable tag:` line)

- [ ] **Step 1: Bump the Plugin Header version**

In `leastudios-email-templates.php`, find the `Version:` line in the Plugin Header (around line 6) and change `1.0.0` → `1.1.0`. The exact diff:

```
- * Version:           1.0.0
+ * Version:           1.1.0
```

- [ ] **Step 2: Bump the version constant**

In the same file, find the `define( 'LEASTUDIOS_EMAIL_TEMPLATES_VERSION', '1.0.0' );` line (around line 25) and change to:

```php
define( 'LEASTUDIOS_EMAIL_TEMPLATES_VERSION', '1.1.0' );
```

- [ ] **Step 3: Bump the Stable tag in `readme.txt`**

In `readme.txt`, find the `Stable tag:` line and change `1.0.0` → `1.1.0`.

- [ ] **Step 4: Verification gate — run the whole suite**

Run from inside the plugin directory:

```bash
composer phpcs
composer phpstan
composer test
bash ../leastudios-dev-tools/bin/check-shared.sh
```

Expected: all four clean. Test count at this point: approximately 220 (214 baseline + 1 from Task 1 + 2 from Task 2 + 3 from Task 3).

- [ ] **Step 5: WP-CLI smoke against Herd**

From `/Users/adamlea/Herd/leastudios-plugins`:

```bash
wp plugin status leastudios-email-templates | head -3
wp leastudios-email-templates list-types --format=json | jq 'map(select(.transactional_required == "no"))'
# Expected: one row, id=subscription_created.

wp leastudios-email-templates add-suppression test+ph10@example.com
wp leastudios-email-templates list-suppressions | grep test+ph10
# Expected: appears with source=cli.

wp leastudios-email-templates send-test subscription_created test+ph10@example.com
# Expected: warning that the recipient is suppressed; exit code 0; log row written.

wp db query "SELECT type, recipient, status, source FROM wp_leastudios_email_templates_log ORDER BY id DESC LIMIT 1"
# Expected: status=suppressed, source=cli-test.

wp leastudios-email-templates remove-suppression test+ph10@example.com
```

- [ ] **Step 6: REST smoke against Herd (brand-color verification)**

Set a non-indigo brand color temporarily:

```bash
wp option update leastudios_email_templates_branding '{"enabled":true,"primary_color":"#ff0000","logo_url":"","footer_text":"","social_links":{"twitter":"","facebook":"","linkedin":"","instagram":""}}' --format=json

TOKEN=$(wp eval '
    $repo = new \LEAStudios\EmailTemplates\Database\Suppression_Repository();
    $mgr = new \LEAStudios\EmailTemplates\Subscription\Unsubscribe_Manager( $repo );
    $url = $mgr->url_for( "smoke@example.com" );
    parse_str( parse_url( $url, PHP_URL_QUERY ), $q );
    echo $q["token"];
')

curl -sS "https://leastudios-plugins.test/wp-json/leastudios-email-templates/v1/unsubscribe?token=${TOKEN}" \
    | grep -oE 'background: #[0-9a-fA-F]{6}'
# Expected: 'background: #ff0000' (or whatever color was set).

# Restore default branding so the smoke doesn't leave the dev site in a weird state.
wp option update leastudios_email_templates_branding '{"enabled":true,"primary_color":"#4f46e5","logo_url":"","footer_text":"","social_links":{"twitter":"","facebook":"","linkedin":"","instagram":""}}' --format=json

# Clean up the smoke suppression so the dev tree is clean.
curl -sS -X POST -d "token=${TOKEN}" "https://leastudios-plugins.test/wp-json/leastudios-email-templates/v1/resubscribe" > /dev/null
```

- [ ] **Step 7: Commit**

```bash
git add leastudios-email-templates.php readme.txt
git commit -m "$(cat <<'EOF'
Bump plugin version to 1.1.0

Closes out the 2026-05-22 roadmap. Phase 1-9 features all shipped,
the four Phase 9 cleanups landed earlier in this batch, and README
plus readme.txt are current. The three version surfaces — the
Plugin Header field, the LEASTUDIOS_EMAIL_TEMPLATES_VERSION
constant, and the readme.txt Stable tag — all read 1.1.0 in one
diff so a casual reader can't see them out of sync.

The release notes live in the Changelog and Upgrade Notice sections
of README.md and readme.txt (landed in the previous two commits).
EOF
)"
```

- [ ] **Step 8: Push to origin/main**

```bash
git push origin main
```

Watch CI complete:

```bash
gh run watch
```

Expected: green on PHP 8.2 and PHP 8.4 lint + test jobs.

---

## Self-review checklist (executed before declaring this plan ready)

- **Spec coverage:** Every D1–D7 decision maps to at least one task.
  - D1 (gate target) → Task 2
  - D2 (array_merge order) → Task 1
  - D3 (button background) → Task 3
  - D4 (luminance text picker) → Task 3
  - D5 (README + readme.txt rewrites) → Tasks 5 + 6
  - D6 (one commit per item) → all 7 task commits
  - D7 (bugs → docs → version order) → task ordering itself
- **Deferred-item coverage:** All four Phase 9 deferred items are covered.
  - Landing-page brand color → Task 3
  - Gate `$to` order → Task 2
  - `array_merge` order in `compose()` → Task 1
  - Task 14 docblock cap → Task 4
- **Roadmap coverage:** Remaining Phase 10 roadmap items are covered.
  - Re-run lint/test → Task 7 step 4 (verification gate)
  - CLAUDE.md refresh → already shipped in Phase 9
  - README.md + readme.txt → Tasks 5 + 6
  - PHPStan level 7 → already shipped in Phase 2; Task 7 verifies continued cleanliness
  - Version bump → Task 7
- **Placeholder scan:** No TODO, no "implement later", no "TBD". Every step shows the exact code or command.
- **Type consistency:**
  - `Unsubscribe_Controller::pick_button_text_color` signature matches across Task 3 tests and implementation.
  - The `$button_bg` / `$button_text` template locals match between the controller (Task 3 step 5) and the template (Task 3 step 6).
  - `$delivery` is the consistent local-variable name across the Task 2 refactor.
- **Order safety:** Task 1 (array_merge) is fully independent of Task 2 (gate). Task 3 only touches the REST surface and templates. Task 4 is a comment move. Tasks 5/6 touch only docs. Task 7 touches only version surfaces. Reordering Tasks 1–4 would be safe; the docs (5/6) come after the code so they describe what actually ships. The version bump (7) is correctly last.

---

**Plan complete and saved to `docs/superpowers/plans/2026-05-23-phase-10-final-cleanup.md`. Two execution options:**

1. **Subagent-Driven (recommended)** — fresh subagent per task, review between tasks, fast iteration.
2. **Inline Execution** — execute tasks in this session using executing-plans, batch execution with checkpoints.

Which approach?
