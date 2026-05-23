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
	 * Registered definitions, keyed by id.
	 *
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
