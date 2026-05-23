<?php
/**
 * Tests for Unsubscribe_Manager.
 *
 * @package LEAStudios\EmailTemplates\Tests
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Tests;

use LEAStudios\EmailTemplates\Database\Suppression_Repository;
use LEAStudios\EmailTemplates\Subscription\Unsubscribe_Manager;
use LEAStudios\Tests\TestCase;

/**
 * @covers \LEAStudios\EmailTemplates\Subscription\Unsubscribe_Manager
 */
class UnsubscribeManagerTest extends TestCase {

	private Suppression_Repository $repo;
	private Unsubscribe_Manager $manager;

	public function set_up(): void {
		parent::set_up();
		// Pretty permalinks so rest_url() returns the /wp-json/... form.
		global $wp_rewrite;
		update_option( 'permalink_structure', '/%postname%/' );
		$wp_rewrite->init();

		$this->repo = new Suppression_Repository();
		// Force a clean (re)install: a sibling test class can leave the
		// schema option committed while the TEMPORARY table did not survive
		// the cross-class boundary, which would make install() short-circuit
		// against a missing table.
		delete_option( 'leastudios_email_templates_suppressions_schema_version' );
		$this->repo->install();
		$this->repo->delete_all();
		delete_option( 'leastudios_email_templates_unsubscribe_secret' );

		$this->manager = new Unsubscribe_Manager( $this->repo );
	}

	public function tear_down(): void {
		global $wp_rewrite;
		update_option( 'permalink_structure', '' );
		$wp_rewrite->init();
		parent::tear_down();
	}

	public function test_url_for_returns_rest_url_with_token(): void {
		$url = $this->manager->url_for( 'jane@example.com' );

		$this->assertStringContainsString( '/wp-json/leastudios-email-templates/v1/unsubscribe', $url );
		$this->assertStringContainsString( 'token=', $url );
	}

	public function test_token_round_trip(): void {
		$url = $this->manager->url_for( 'jane@example.com' );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		$this->assertSame( 'jane@example.com', $this->manager->verify_token( (string) $query['token'] ) );
	}

	public function test_token_is_case_insensitive_via_normalization(): void {
		$url = $this->manager->url_for( 'MIXED@Case.com' );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		$this->assertSame( 'mixed@case.com', $this->manager->verify_token( (string) $query['token'] ) );
	}

	public function test_tampered_payload_rejected(): void {
		$url = $this->manager->url_for( 'jane@example.com' );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		[ , $sig ] = explode( '.', (string) $query['token'] );
		// base64url-encode an attacker payload to splice with the legit signature.
		$forged = rtrim( strtr( base64_encode( 'attacker@example.com' ), '+/', '-_' ), '=' ) . '.' . $sig; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		$this->assertNull( $this->manager->verify_token( $forged ) );
	}

	public function test_tampered_signature_rejected(): void {
		$url = $this->manager->url_for( 'jane@example.com' );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		[ $payload ] = explode( '.', (string) $query['token'] );
		$forged      = $payload . '.' . str_repeat( '0', 64 );

		$this->assertNull( $this->manager->verify_token( $forged ) );
	}

	public function test_garbage_tokens_rejected(): void {
		$this->assertNull( $this->manager->verify_token( '' ) );
		$this->assertNull( $this->manager->verify_token( 'no-dot-here' ) );
		$this->assertNull( $this->manager->verify_token( '%%%.%%%' ) );
	}

	public function test_secret_is_lazily_generated_and_persisted(): void {
		$this->assertFalse( get_option( 'leastudios_email_templates_unsubscribe_secret' ) );

		$this->manager->url_for( 'jane@example.com' );

		$secret = get_option( 'leastudios_email_templates_unsubscribe_secret' );
		$this->assertIsString( $secret );
		$this->assertSame( 64, strlen( (string) $secret ) );
	}

	public function test_secret_filter_short_circuits_option_read(): void {
		add_filter(
			'leastudios_email_templates_unsubscribe_token_secret',
			static fn (): string => 'env-supplied-secret-value'
		);

		$this->manager->url_for( 'jane@example.com' );

		$this->assertFalse(
			get_option( 'leastudios_email_templates_unsubscribe_secret' ),
			'Secret must NOT be persisted when the filter supplies one.'
		);

		remove_all_filters( 'leastudios_email_templates_unsubscribe_token_secret' );
	}

	public function test_suppress_and_is_suppressed_round_trip(): void {
		$this->assertFalse( $this->manager->is_suppressed( 'jane@example.com' ) );

		$this->manager->suppress( 'jane@example.com', 'link' );
		$this->assertTrue( $this->manager->is_suppressed( 'jane@example.com' ) );

		$this->manager->unsuppress( 'jane@example.com' );
		$this->assertFalse( $this->manager->is_suppressed( 'jane@example.com' ) );
	}
}
