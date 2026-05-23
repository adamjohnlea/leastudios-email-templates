<?php
/**
 * Tests for Unsubscribe_Controller.
 *
 * @package LEAStudios\EmailTemplates\Tests
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Tests;

use LEAStudios\EmailTemplates\Database\Suppression_Repository;
use LEAStudios\EmailTemplates\REST\Unsubscribe_Controller;
use LEAStudios\EmailTemplates\Subscription\Unsubscribe_Manager;
use LEAStudios\Tests\TestCase;
use WP_REST_Request;

class UnsubscribeControllerTest extends TestCase {

	private Suppression_Repository $repo;
	private Unsubscribe_Manager $manager;
	private Unsubscribe_Controller $controller;

	public function set_up(): void {
		parent::set_up();
		delete_option( 'leastudios_email_templates_suppressions_schema_version' );
		$this->repo = new Suppression_Repository();
		$this->repo->install();
		$this->repo->delete_all();
		delete_option( 'leastudios_email_templates_unsubscribe_secret' );

		$this->manager    = new Unsubscribe_Manager( $this->repo );
		$this->controller = new Unsubscribe_Controller( $this->manager );
	}

	private function mint_token( string $email ): string {
		$url = $this->manager->url_for( $email );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		return (string) $query['token'];
	}

	public function test_routes_register_on_rest_api_init(): void {
		add_action( 'rest_api_init', [ $this->controller, 'register_routes' ] );
		// Force re-init of the server so our routes get picked up.
		do_action( 'rest_api_init' );

		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/leastudios-email-templates/v1/unsubscribe', $routes );
		$this->assertArrayHasKey( '/leastudios-email-templates/v1/resubscribe', $routes );
	}

	public function test_get_unsubscribe_with_valid_token_creates_suppression_and_renders_landing(): void {
		$request = new WP_REST_Request( 'GET', '/leastudios-email-templates/v1/unsubscribe' );
		$request->set_param( 'token', $this->mint_token( 'jane@example.com' ) );

		$response = $this->controller->unsubscribe( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'text/html; charset=utf-8', $response->get_headers()['Content-Type'] ?? '' );
		$body = (string) $response->get_data();
		$this->assertStringContainsString( 'You&#039;re unsubscribed', $body );
		$this->assertStringContainsString( 'jane@example.com', $body );
		$this->assertTrue( $this->repo->exists_by_email( 'jane@example.com' ) );
	}

	public function test_get_unsubscribe_with_missing_token_returns_error_landing(): void {
		$request = new WP_REST_Request( 'GET', '/leastudios-email-templates/v1/unsubscribe' );
		$request->set_param( 'token', '' );

		$response = $this->controller->unsubscribe( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertStringContainsString( 'invalid', strtolower( (string) $response->get_data() ) );
		$this->assertFalse( $this->repo->exists_by_email( 'jane@example.com' ) );
	}

	public function test_get_unsubscribe_with_tampered_token_returns_error_landing(): void {
		$request = new WP_REST_Request( 'GET', '/leastudios-email-templates/v1/unsubscribe' );
		$request->set_param( 'token', 'not-a-real-token.deadbeef' );

		$response = $this->controller->unsubscribe( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertFalse( $this->repo->exists_by_email( 'jane@example.com' ) );
	}

	public function test_post_resubscribe_with_valid_token_removes_suppression(): void {
		$this->manager->suppress( 'jane@example.com', 'link' );

		$request = new WP_REST_Request( 'POST', '/leastudios-email-templates/v1/resubscribe' );
		$request->set_param( 'token', $this->mint_token( 'jane@example.com' ) );

		$response = $this->controller->resubscribe( $request );

		$this->assertSame( 200, $response->get_status() );
		$body = (string) $response->get_data();
		$this->assertStringContainsString( 'Welcome back', $body );
		$this->assertFalse( $this->repo->exists_by_email( 'jane@example.com' ) );
	}

	public function test_post_resubscribe_with_invalid_token_returns_error_landing(): void {
		$request = new WP_REST_Request( 'POST', '/leastudios-email-templates/v1/resubscribe' );
		$request->set_param( 'token', 'garbage' );

		$response = $this->controller->resubscribe( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_responses_carry_nocache_headers(): void {
		$request = new WP_REST_Request( 'GET', '/leastudios-email-templates/v1/unsubscribe' );
		$request->set_param( 'token', $this->mint_token( 'jane@example.com' ) );

		$response = $this->controller->unsubscribe( $request );
		$headers  = $response->get_headers();

		$this->assertArrayHasKey( 'Cache-Control', $headers );
		$this->assertStringContainsString( 'no-cache', (string) $headers['Cache-Control'] );
	}

	public function test_pick_button_text_color_returns_white_for_dark_backgrounds(): void {
		$this->assertSame( '#ffffff', Unsubscribe_Controller::pick_button_text_color( '#000000' ) );
		$this->assertSame( '#ffffff', Unsubscribe_Controller::pick_button_text_color( '#4f46e5' ) );
		$this->assertSame( '#ffffff', Unsubscribe_Controller::pick_button_text_color( '#0000ff' ) );
		$this->assertSame( '#ffffff', Unsubscribe_Controller::pick_button_text_color( '#1a1a1a' ) );
	}

	public function test_pick_button_text_color_returns_dark_for_light_backgrounds(): void {
		$this->assertSame( '#111827', Unsubscribe_Controller::pick_button_text_color( '#ffffff' ) );
		$this->assertSame( '#111827', Unsubscribe_Controller::pick_button_text_color( '#f0f0f0' ) );
		$this->assertSame( '#111827', Unsubscribe_Controller::pick_button_text_color( '#ffff00' ) );
		$this->assertSame( '#111827', Unsubscribe_Controller::pick_button_text_color( '#eaeaea' ) );
	}

	public function test_pick_button_text_color_defaults_white_for_invalid_input(): void {
		$this->assertSame( '#ffffff', Unsubscribe_Controller::pick_button_text_color( '' ) );
		$this->assertSame( '#ffffff', Unsubscribe_Controller::pick_button_text_color( '#fff' ) );        // 3-char shorthand not supported.
		$this->assertSame( '#ffffff', Unsubscribe_Controller::pick_button_text_color( 'red' ) );        // CSS keyword.
		$this->assertSame( '#ffffff', Unsubscribe_Controller::pick_button_text_color( '#xyz123' ) );    // Invalid hex.
	}
}
