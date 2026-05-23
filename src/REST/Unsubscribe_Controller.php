<?php
/**
 * Public REST endpoints for one-click unsubscribe and two-click resubscribe.
 *
 * @package LEAStudios\EmailTemplates\REST
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\REST;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use LEAStudios\EmailTemplates\Subscription\Unsubscribe_Manager;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Renders signed-token-driven HTML landing pages and writes the suppression
 * side-effect. Permission callbacks return true intentionally — the token is
 * the authentication mechanism.
 */
final class Unsubscribe_Controller extends WP_REST_Controller {

	/**
	 * REST namespace shared by both routes.
	 */
	private const ROUTE_NAMESPACE = 'leastudios-email-templates/v1';

	/**
	 * REST namespace exposed to WP_REST_Controller.
	 *
	 * @var string
	 */
	protected $namespace = self::ROUTE_NAMESPACE;

	/**
	 * Constructor.
	 *
	 * @param Unsubscribe_Manager $manager Token + suppression facade.
	 */
	public function __construct(
		private readonly Unsubscribe_Manager $manager,
	) {}

	/**
	 * Register the public routes.
	 *
	 * Also installs a one-time `rest_pre_serve_request` listener that
	 * short-circuits JSON serialization for our two routes so the HTML
	 * body lands raw in the response.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/unsubscribe',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'unsubscribe' ],
					'permission_callback' => '__return_true',
					'args'                => [
						'token' => [
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						],
					],
				],
			]
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/resubscribe',
			[
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'resubscribe' ],
					'permission_callback' => '__return_true',
					'args'                => [
						'token' => [
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						],
					],
				],
			]
		);

		add_filter( 'rest_pre_serve_request', [ $this, 'serve_html_response' ], 10, 4 );
	}

	/**
	 * Handle GET /unsubscribe. One-click — writes the suppression row before
	 * rendering the confirmation landing.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function unsubscribe( WP_REST_Request $request ): WP_REST_Response {
		$token = (string) $request->get_param( 'token' );
		$email = $this->manager->verify_token( $token );

		if ( null === $email ) {
			return $this->html_response( 400, $this->render_template( 'landing-error.php', [] ) );
		}

		$this->manager->suppress( $email, 'link' );

		return $this->html_response(
			200,
			$this->render_template(
				'landing-unsubscribed.php',
				[
					'email' => $email,
					'token' => $token,
				]
			)
		);
	}

	/**
	 * Handle POST /resubscribe. Deletes the suppression row and shows the
	 * "welcome back" landing.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function resubscribe( WP_REST_Request $request ): WP_REST_Response {
		$token = (string) $request->get_param( 'token' );
		$email = $this->manager->verify_token( $token );

		if ( null === $email ) {
			return $this->html_response( 400, $this->render_template( 'landing-error.php', [] ) );
		}

		$this->manager->unsuppress( $email );

		return $this->html_response(
			200,
			$this->render_template( 'landing-resubscribed.php', [ 'email' => $email ] )
		);
	}

	/**
	 * Short-circuit JSON serialization for our two HTML routes. WordPress's
	 * default REST server json_encodes the response body; we want raw HTML.
	 *
	 * @param bool             $served  Whether the request has already been served.
	 * @param WP_REST_Response $result  Result to send.
	 * @param WP_REST_Request  $request Incoming request.
	 * @param \WP_REST_Server  $server  REST server instance.
	 * @return bool
	 */
	public function serve_html_response( bool $served, WP_REST_Response $result, WP_REST_Request $request, \WP_REST_Server $server ): bool {
		unset( $server );

		$route = $request->get_route();
		if ( '/leastudios-email-templates/v1/unsubscribe' !== $route && '/leastudios-email-templates/v1/resubscribe' !== $route ) {
			return $served;
		}

		$data = $result->get_data();
		if ( ! is_string( $data ) ) {
			return $served;
		}

		status_header( $result->get_status() );

		foreach ( $result->get_headers() as $name => $value ) {
			header( "{$name}: {$value}" );
		}

		// nocache_headers() also sets some via header() directly.
		nocache_headers();

		echo $data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Template was escaped at render time.

		return true;
	}

	/**
	 * Build an HTML-bearing REST response with the right Content-Type and
	 * Cache-Control headers staged.
	 *
	 * @param int    $status HTTP status code.
	 * @param string $html   Rendered HTML body.
	 * @return WP_REST_Response
	 */
	private function html_response( int $status, string $html ): WP_REST_Response {
		$response = new WP_REST_Response( $html, $status );
		$response->header( 'Content-Type', 'text/html; charset=utf-8' );
		$response->header( 'Cache-Control', 'no-cache, no-store, must-revalidate' );
		$response->header( 'X-Robots-Tag', 'noindex, nofollow' );

		return $response;
	}

	/**
	 * Render a template from templates/unsubscribe/ with the given vars.
	 *
	 * @param string               $template Filename inside templates/unsubscribe.
	 * @param array<string, mixed> $vars     Variables to extract into scope.
	 * @return string Rendered HTML.
	 */
	private function render_template( string $template, array $vars ): string {
		$path = LEASTUDIOS_EMAIL_TEMPLATES_DIR . 'templates/unsubscribe/' . $template;

		if ( ! file_exists( $path ) ) {
			return '<p>Template missing.</p>';
		}

		ob_start();
		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- Template variables are controlled internally.
		extract( $vars );
		include $path;

		return (string) ob_get_clean();
	}
}
