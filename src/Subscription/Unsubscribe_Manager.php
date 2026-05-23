<?php
/**
 * Mints/verifies stateless unsubscribe tokens and wraps the suppression
 * repository with normalized-email semantics.
 *
 * @package LEAStudios\EmailTemplates\Subscription
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Subscription;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use LEAStudios\EmailTemplates\Database\Suppression_Repository;

/**
 * Stateless unsubscribe token + suppression facade.
 *
 * Tokens have the form `<base64url(email)>.<hex hmac-sha256(email,secret)>`.
 * Verification is constant-time via hash_equals. No expiry, no single-use.
 * Rotating the secret (delete the option) invalidates all outstanding tokens.
 */
final class Unsubscribe_Manager {

	/**
	 * Option key holding the HMAC secret. autoload=no.
	 */
	private const SECRET_OPTION = 'leastudios_email_templates_unsubscribe_secret';

	/**
	 * Constructor.
	 *
	 * @param Suppression_Repository $repo Repository for the suppressions table.
	 */
	public function __construct(
		private readonly Suppression_Repository $repo,
	) {}

	/**
	 * Build the public unsubscribe URL for a recipient.
	 *
	 * @param string $email Recipient email address.
	 * @return string Full URL with the signed token in the query string.
	 */
	public function url_for( string $email ): string {
		return add_query_arg(
			[ 'token' => $this->mint_token( $email ) ],
			rest_url( 'leastudios-email-templates/v1/unsubscribe' )
		);
	}

	/**
	 * Verify a token and return the normalized email on success.
	 *
	 * @param string $token The token from the query string or POST body.
	 * @return string|null Normalized email, or null on any failure.
	 */
	public function verify_token( string $token ): ?string {
		if ( '' === $token || ! str_contains( $token, '.' ) ) {
			return null;
		}

		$parts = explode( '.', $token, 2 );
		if ( count( $parts ) !== 2 ) {
			return null;
		}

		[ $payload, $sig ] = $parts;

		// base64url decode of the email payload (not obfuscation).
		$decoded = base64_decode( strtr( $payload, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $decoded || '' === $decoded ) {
			return null;
		}

		$email    = strtolower( trim( $decoded ) );
		$expected = hash_hmac( 'sha256', $email, $this->get_or_create_secret() );

		if ( ! hash_equals( $expected, $sig ) ) {
			return null;
		}

		return $email;
	}

	/**
	 * Mark an email as suppressed.
	 *
	 * @param string $email  Recipient email.
	 * @param string $source Origin marker ('link' | 'admin' | 'cli').
	 * @return void
	 */
	public function suppress( string $email, string $source ): void {
		$this->repo->upsert( $email, $source );
	}

	/**
	 * Remove a recipient's suppression.
	 *
	 * @param string $email Recipient email.
	 * @return void
	 */
	public function unsuppress( string $email ): void {
		$this->repo->delete_by_email( $email );
	}

	/**
	 * Whether an email is currently suppressed.
	 *
	 * @param string $email Recipient email.
	 * @return bool
	 */
	public function is_suppressed( string $email ): bool {
		return $this->repo->exists_by_email( $email );
	}

	/**
	 * Paginate the suppression list — exposed on the facade so CLI/admin
	 * consumers don't have to construct their own `Suppression_Repository`.
	 *
	 * @param array<string, mixed> $filters  Optional filter spec passed through to the repository.
	 * @param int                  $per_page Items per page.
	 * @param int                  $page     1-based page index.
	 * @return array{rows: array<int, \LEAStudios\EmailTemplates\Database\Suppression_Entry>, total: int}
	 */
	public function paginate( array $filters, int $per_page, int $page ): array {
		return $this->repo->paginate( $filters, $per_page, $page );
	}

	/**
	 * Generate the URL-safe token for an email.
	 *
	 * @param string $email Recipient email.
	 * @return string
	 */
	private function mint_token( string $email ): string {
		$email = strtolower( trim( $email ) );
		// base64url encode the email for use in a URL query string (not obfuscation).
		$payload = rtrim( strtr( base64_encode( $email ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$sig     = hash_hmac( 'sha256', $email, $this->get_or_create_secret() );

		return $payload . '.' . $sig;
	}

	/**
	 * Read the HMAC secret, generating and persisting one on first use.
	 *
	 * The `leastudios_email_templates_unsubscribe_token_secret` filter lets
	 * sites source the secret from a constant or environment variable. When
	 * the filter returns a non-empty string the option is NOT touched.
	 *
	 * @return string
	 */
	private function get_or_create_secret(): string {
		/**
		 * Filters the unsubscribe-token HMAC secret before falling back to
		 * the wp_option. Return a non-empty string to source the secret
		 * from a constant or env var without persisting anything to the DB.
		 *
		 * @param string $secret Empty string by default.
		 */
		$filtered = (string) apply_filters( 'leastudios_email_templates_unsubscribe_token_secret', '' );

		if ( '' !== $filtered ) {
			return $filtered;
		}

		$stored = get_option( self::SECRET_OPTION, '' );

		if ( is_string( $stored ) && '' !== $stored ) {
			return $stored;
		}

		$generated = wp_generate_password( 64, true, true );
		update_option( self::SECRET_OPTION, $generated, false );

		return $generated;
	}
}
