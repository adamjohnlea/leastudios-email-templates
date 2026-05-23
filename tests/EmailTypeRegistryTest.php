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
			public function id(): string {
				return $this->stub_id;
			}
			public function label(): string {
				return $this->stub_label;
			}
			public function default_subject(): string {
				return 'Stub subject';
			}
			public function default_body(): string {
				return '<p>Stub body</p>';
			}
			public function available_tags(): array {
				return [];
			}
			public function sample_context(): array {
				return [];
			}
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
			[
				'a' => $a,
				'b' => $b,
			],
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
