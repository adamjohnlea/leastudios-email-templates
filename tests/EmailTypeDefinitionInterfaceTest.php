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
 * @coversNothing
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
