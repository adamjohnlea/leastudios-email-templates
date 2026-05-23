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
