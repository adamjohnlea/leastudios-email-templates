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
