# Phase 2 — PHPStan level 7 + asset cache-busting

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:executing-plans.

**Goal:** Bump static-analysis strictness and use `filemtime` for asset cache-busting so editing CSS/JS doesn't require a plugin version bump.

**Architecture:** Two independent micro-changes. PHPStan bump is a config edit + whatever fixes the new errors demand. Cache-busting is a one-line change in `enqueue_assets`.

---

## Task 1: Bump PHPStan to level 7

**Files:**
- Modify: `phpstan.neon`
- Possibly: `src/**` (whatever errors surface)

- [ ] **Step 1.1: Edit phpstan.neon**

Change `level: 6` to `level: 7`.

- [ ] **Step 1.2: Run phpstan**

```bash
composer phpstan
```

Triage the resulting errors. Common level-7 additions: nullable returns, mixed-type narrowing, array-shape strictness.

- [ ] **Step 1.3: Fix each error**

For each error: read it, fix it, re-run. Do not add `ignoreErrors`. Common fixes:
- Add explicit `?type` to docblocks where mixed leaks in.
- Narrow array-shape returns with `array{key: type, ...}`.
- Replace `$arr['k'] ?? null` patterns with proper guards if PHPStan can't prove the key exists.

- [ ] **Step 1.4: Lint + full test suite**

```bash
composer test && composer lint
```

Expected: all green.

- [ ] **Step 1.5: Commit**

```bash
git add phpstan.neon src/
git commit -m "Bump PHPStan to level 7 and tighten types in the surfaced sites"
```

---

## Task 2: Asset cache-busting via filemtime

**Files:**
- Modify: `src/Admin/Settings_Page.php` (`enqueue_assets`)

- [ ] **Step 2.1: Replace plugin-version versioning with filemtime**

In `enqueue_assets`, change the third argument of `wp_enqueue_style`/`wp_enqueue_script` from `LEASTUDIOS_EMAIL_TEMPLATES_VERSION` to `(string) filemtime( LEASTUDIOS_EMAIL_TEMPLATES_DIR . '<relative path>' )`.

Example for the CSS:

```php
wp_enqueue_style(
    'leastudios-email-templates-admin',
    LEASTUDIOS_EMAIL_TEMPLATES_URL . 'assets/css/admin.css',
    [],
    (string) filemtime( LEASTUDIOS_EMAIL_TEMPLATES_DIR . 'assets/css/admin.css' )
);
```

Same pattern for the JS.

- [ ] **Step 2.2: Lint**

```bash
composer lint
```

- [ ] **Step 2.3: Smoke-verify**

```bash
cd /Users/adamlea/Herd/leastudios-plugins && wp eval '
require_once ABSPATH . "wp-admin/includes/admin.php";
wp_set_current_user(1);
do_action("admin_enqueue_scripts", "toplevel_page_leastudios-email-templates");
$css = wp_styles()->registered["leastudios-email-templates-admin"];
$js = wp_scripts()->registered["leastudios-email-templates-admin"];
echo "CSS ver: " . $css->ver . "\n";
echo "JS  ver: " . $js->ver . "\n";
'
```

Both versions should be a unix timestamp (10-digit number), not the plugin version `1.0.0`. Verify changing CSS bumps the CSS ver only.

- [ ] **Step 2.4: Commit**

```bash
git add src/Admin/Settings_Page.php
git commit -m "Use filemtime() for admin asset cache-busting"
```

---

## Self-review

- [ ] `composer phpstan` clean at level 7
- [ ] `composer test` 52/52 still green
- [ ] CSS/JS versions show timestamps, not plugin version
