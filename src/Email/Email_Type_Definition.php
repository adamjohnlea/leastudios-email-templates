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
	 * Contract: the returned map must include an entry for every tag
	 * returned by `available_tags()` (using the unbraced key). Missing
	 * entries cause `Merge_Tag_Replacer::replace_html()` to fall back to
	 * the default HTML-escape mode, so an under-populated map is a safety
	 * gap rather than an obvious failure.
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
