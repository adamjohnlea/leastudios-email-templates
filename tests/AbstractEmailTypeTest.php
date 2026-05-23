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
			public function id(): string {
				return 'fixture'; }
			public function label(): string {
				return 'Fixture'; }
			public function default_subject(): string {
				return 'Hi'; }
			public function default_body(): string {
				return '<p>Hi</p>'; }
			public function available_tags(): array {
				return [
					'{customer_name}' => [
						'description' => 'name',
						'escape'      => Escape_Mode::HTML,
					],
					'{site_url}'      => [
						'description' => 'url',
						'escape'      => Escape_Mode::URL,
					],
				];
			}
			public function sample_context(): array {
				return [ 'customer_name' => 'Jane' ]; }
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
			public function id(): string {
				return 'opt_in'; }
			public function label(): string {
				return 'Opt-in'; }
			public function default_subject(): string {
				return 'Hi'; }
			public function default_body(): string {
				return 'Hi'; }
			public function available_tags(): array {
				return []; }
			public function sample_context(): array {
				return []; }
		};

		$this->assertFalse( $fixture->is_transactional_required() );
	}

	public function test_subclass_can_override_is_transactional_required(): void {
		$fixture = new class() extends Abstract_Email_Type {
			public function id(): string {
				return 'tx_required'; }
			public function label(): string {
				return 'Required'; }
			public function default_subject(): string {
				return 'Hi'; }
			public function default_body(): string {
				return 'Hi'; }
			public function available_tags(): array {
				return []; }
			public function sample_context(): array {
				return []; }
			public function is_transactional_required(): bool {
				return true; }
		};

		$this->assertTrue( $fixture->is_transactional_required() );
	}
}
