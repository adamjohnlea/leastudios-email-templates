# Phase 10 — Final Cleanup + 1.1.0 Release Design

**Date:** 2026-05-23
**Status:** Approved, ready for implementation plan
**Scope:** Four lingering bugs from Phase 9 + full user-facing docs rewrite + version bump to 1.1.0
**Out of scope:** Any new feature work. Any schema change. Any backwards-compat shim.

This is the closing batch for the 2026-05-22 roadmap. After Phase 10 lands, every roadmap item is shipped and the plugin is at 1.1.0.

---

## Background

Phases 1-9 are complete and pushed to `origin/main`. The roadmap (`docs/superpowers/plans/2026-05-22-roadmap.md`) defined ten phases of work; the first nine are shipped. Phase 10 was originally scoped as "final review pass" — re-run lint/tests, refresh `CLAUDE.md`, update `README.md` + `readme.txt`, bump version. Two of those items are already done in earlier sessions:

- **PHPStan level 7** — landed in Phase 2, currently clean.
- **CLAUDE.md refresh** — landed as part of Phase 9, Task 18.

That leaves three roadmap items plus four deferred items from Phase 9 execution.

---

## Decisions

### D1 — Suppression gate evaluates the resolved delivery address

**Problem:** `Email_Sender::send($type_id, $to, ...)` runs the suppression gate against `$to` *before* resolving any per-type `recipient_override`. If an admin sets `recipient_override = ops@example.com` on `subscription_created` and the caller passes `$to = jane@example.com` (suppressed), the email is gated against Jane and never reaches ops — even though Jane was never going to receive it.

**Decision:** Move the gate so it runs *after* `recipient_override` resolution. Gate against the final delivery address (`recipient_override ?: $to`). The `_email_suppressed` action's `$to` argument is the resolved address, not the original — the log row records who would actually have received the mail.

**Rationale:** The override exists precisely so the type can be redirected to an internal recipient. Gating against the unrelated original address is a bug, not a feature. The override target is the one whose opt-out preference is relevant.

**Test:** Red test asserting the email lands at the override target when the original `$to` is suppressed but the override isn't. Red test asserting the gate fires (and logs) when the override target itself is suppressed.

---

### D2 — `array_merge` order in `compose()` so the injected `unsubscribe_url` wins

**Problem:** `src/Email/Email_Sender.php:170-173` reads:

```php
$context = array_merge(
    [ 'unsubscribe_url' => $this->resolve_unsubscribe_url( $to, $definition ) ],
    $context
);
```

PHP's `array_merge` lets the *second* argument win on key collision. So a caller passing `unsubscribe_url` in `$context` silently overrides our recipient-aware resolution.

**Decision:** Swap the argument order to `array_merge($context, [injection])` so our resolved URL wins. The documented override surface is the filter `leastudios_email_templates_unsubscribe_url`; callers should not have a hidden side channel via `$context`.

**Rationale:** The recipient-aware resolution knows what the caller can't: whether the type is required (returns empty), whether `$to` is empty (returns empty), and the HMAC secret. Letting a caller override that with an arbitrary string defeats the gate semantics.

**Test:** Red test that passes `unsubscribe_url = 'https://attacker.example'` in `$context` and asserts the rendered body contains the resolved REST URL, not the caller's value.

---

### D3 — Landing-page resubscribe button reads `branding[primary_color]`

**Problem:** `templates/unsubscribe/landing-unsubscribed.php` hard-codes the resubscribe button at `background: #4f46e5` (indigo-600) with hover `#4338ca`. Sites with non-indigo branding get a button that doesn't match their email branding.

**Decision:**

- The resubscribe button background reads from `branding[primary_color]`.
- Fallback to `#4f46e5` (current indigo) when the option is empty.
- Hover state is `opacity: 0.9` rather than a computed darker shade. Avoids hex-darkening math and degrades gracefully for any brand color.
- Text color is computed per background via a luminance check (D4).
- Resolution happens in `Unsubscribe_Controller` (or the same render path that loads the template), passed to the template as locals (`$button_bg`, `$button_text`). The template stays logic-free.

**Rationale:** Opacity-based hover is cheap, looks polished, and works for any background color. Computing darker hex would need 10+ lines of math we don't otherwise need and creates edge cases when the brand color is already very dark.

**Test:** No browser test. The luminance helper (D4) is unit-tested; the wiring is exercised by the REST smoke during the verification gate (eyeball with `primary_color = #ff0000`).

---

### D4 — Text contrast computed from background luminance

**Problem:** Hard-coding `color: #fff` on the brand-colored button means a near-white `primary_color` (e.g., `#f0f0f0`) yields an unreadable button.

**Decision:** Pick button text color from a YIQ-style luminance check on the resolved background:

```
luminance = 0.299*R + 0.587*G + 0.114*B
text = luminance > 186 ? '#111827' : '#ffffff'
```

The threshold (`186`) is the conventional WCAG-derived cutoff for switching between dark and light text. Lives in a small static method:

```php
public static function pick_button_text_color(string $hex): string
```

placed on `Unsubscribe_Controller` (it's the only consumer).

**Rationale:** ~10 lines, dependency-free, well-known formula. Lets the admin choose any brand color without subtly breaking the resubscribe landing.

**Test:** Unit-test the picker — assert `#ffffff` for dark backgrounds (`#000000`, `#4f46e5`, `#0000ff`), `#111827` for light (`#ffffff`, `#f0f0f0`, `#ffff00`, an off-white like `#eaeaea`). Cover invalid/short hex (`''`, `'#fff'`, `'red'`) — should return `#ffffff` as the safe default.

---

### D5 — Full README.md + readme.txt rewrite at 1.1.0 fidelity

**Problem:** `README.md` is 2.6KB dated April 28, predating all of Phases 1-9. `readme.txt` is 3.3KB dated May 22, probably picked up some of Phases 1-8 but not Phase 9.

**Decision:** Full rewrite of both files. Content parity between them; format adapted to each audience.

`README.md` (developer-facing, GitHub):
- Lead paragraph: three responsibilities (wrapper / payment emails / opt-out).
- Quick start: install + activate.
- Features: branded wrapper, payment transactional emails, per-type preview + send-test, send log + retention, plain-text alt body, theme variants, escape contract, type registry, WP-CLI, unsubscribe + suppression.
- WP-CLI commands list (all 6).
- Public extension points: every action/filter/header listed in `CLAUDE.md`.
- Database tables: log + suppressions.
- Compatibility: WP 6.4+, PHP 8.1+, falls back gracefully without `leastudios-payments`.
- Changelog: 1.1.0 entry + retained 1.0.0 entry.

`readme.txt` (WP.org plugin directory format):
- `=== Header ===` block: Contributors, Tags, Requires at least 6.4, Tested up to 6.9, Stable tag 1.1.0, License GPL-2.0-or-later.
- `== Description ==` mirrors README.md prose, trimmed.
- `== Frequently Asked Questions ==`:
  - Does it work without leastudios-payments? (Yes — the wrapper and suppression machinery run independently; payment emails just don't dispatch.)
  - Does it support unsubscribes? (Yes — Phase 9 details, one-click GET / two-click POST.)
  - How do I rotate the HMAC unsubscribe secret? (Delete the `leastudios_email_templates_unsubscribe_secret` option, or filter `leastudios_email_templates_unsubscribe_token_secret`.)
  - How do I disable the auto-appended unsubscribe footer? (Filter `leastudios_email_templates_unsubscribe_footer_html` to return `''`.)
- `== Changelog ==` with 1.1.0 entry.
- `== Upgrade Notice ==` 1.1.0 entry: one sentence calling out the new suppression behavior so site owners notice it.

**Rationale:** Both files have been stale long enough that piecemeal patches would leave the prose internally inconsistent (e.g., a feature list missing half of Phases 1-9). A full pass is less work than a careful delta.

---

### D6 — One commit per item

**Decision:** Each bugfix is one commit; each docs file is one commit; version bump is one commit. Estimated 7 commits:

| # | Commit subject | Files |
|---|---|---|
| 1 | Fix array_merge order so resolved unsubscribe_url wins | `Email_Sender.php`, `EmailSenderTest.php` |
| 2 | Gate suppression against resolved delivery address | `Email_Sender.php`, `EmailSenderTest.php` |
| 3 | Theme unsubscribe landing button with branding primary_color | `Unsubscribe_Controller.php`, `templates/unsubscribe/landing-unsubscribed.php`, `UnsubscribeControllerTest.php` |
| 4 | Move 1000-row cap rationale into build_suppression_rows docblock | `Commands.php` |
| 5 | Rewrite README.md for 1.1.0 | `README.md` |
| 6 | Rewrite readme.txt for WP.org directory at 1.1.0 | `readme.txt` |
| 7 | Bump plugin version to 1.1.0 | `leastudios-email-templates.php`, `readme.txt` (Stable tag) |

**Rationale:** Same pattern as Phase 9. Easy to revert any single piece. Easy to read in `git log`. The version bump is the last commit so a `git log --oneline` reader can tell at a glance "everything before that commit was 1.0.0-era work; everything after it is shipped 1.1.0."

---

### D7 — Work ordering: bugs → docs → version bump

**Decision:** Land all bugfixes first (red→green TDD for the three testable ones; trivial comment move for the fourth). Then both README rewrites. Then the version bump as the last commit. The final verification gate runs *before* the version-bump commit, so the bump is the last green commit.

**Rationale:** Bugs are bounded code changes with tests proving them — they belong first so the docs that follow describe the actually-shipping behavior. The version bump is the "ship" signal and should follow everything else.

---

## Acceptance criteria

Before this batch is considered complete:

- All 4 bugfixes landed with passing tests.
- README.md fully describes the 1.1.0 feature set including unsubscribe/suppression.
- readme.txt is valid WordPress.org plugin directory format with stable tag `1.1.0`.
- Plugin header version, `LEASTUDIOS_EMAIL_TEMPLATES_VERSION` constant, and readme.txt Stable tag all read `1.1.0`.
- `composer phpcs` clean.
- `composer phpstan` clean at level 7.
- `composer test` all green (currently 214 tests; expect ~217-220 after the new bugfix tests land).
- `bash ../leastudios-dev-tools/bin/check-shared.sh` reports 17 shared files in sync.
- WP-CLI smoke against Herd: list-types, preview, send-test, list-suppressions, add-suppression, remove-suppression all work.
- REST smoke: `GET /unsubscribe?token=...` and `POST /resubscribe` work end-to-end; brand color renders.
- All 7 commits pushed to `origin/main`; CI green.

---

## What is explicitly NOT in scope

- New features, new merge tags, new built-in types, new database tables.
- Backwards-compat shims for any Phase 1-9 behavior (per project CLAUDE.md).
- Refactoring Phase 9 code beyond the four named bugs.
- Theme-variants work in the landing pages beyond the single resubscribe button.
- Email body brand-color overrides (the email base template already handles this).
- Performance work, caching, autoload-options audits.
- Tagging a git release / cutting a GitHub release — the user does that manually.
