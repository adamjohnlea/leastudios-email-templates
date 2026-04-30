<?php
/**
 * Wraps all outgoing emails in a branded HTML template.
 *
 * @package LEAStudios\EmailTemplates\Email
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Email;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Hooks into the email pipeline to wrap content in the branded template.
 */
class Template_Wrapper {

	/**
	 * Constructor.
	 *
	 * @param Merge_Tag_Replacer $replacer The merge tag replacer.
	 */
	public function __construct(
		private readonly Merge_Tag_Replacer $replacer,
	) {}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		if ( $this->is_mailer_active() ) {
			// Hook the mailer's pre-send filter (after the mailer processes at priority 10).
			add_filter( 'leastudios_mailer_pre_send', [ $this, 'wrap_mailer_email' ], 20, 2 );
		} else {
			// Fallback: hook wp_mail directly.
			add_filter( 'wp_mail', [ $this, 'wrap_wp_mail' ] );
		}
	}

	/**
	 * Wrap email content via the leastudios_mailer_pre_send filter.
	 *
	 * @param array<string, mixed>|null $args          The processed email args.
	 * @param array<string, mixed>      $original_atts The original wp_mail arguments. Required by the filter signature; not currently consulted.
	 * @return array<string, mixed>|null The modified args.
	 */
	public function wrap_mailer_email( ?array $args, array $original_atts ): ?array {
		unset( $original_atts ); // Required by the leastudios_mailer_pre_send filter signature; not consulted.
		if ( null === $args ) {
			return null;
		}

		// Check for opt-out header.
		if ( $this->has_opt_out_header( $args['headers'] ?? [] ) ) {
			return $args;
		}

		// Only wrap HTML emails.
		if ( empty( $args['body_html'] ) ) {
			return $args;
		}

		$args['body_html'] = $this->wrap( $args['body_html'] );

		return $args;
	}

	/**
	 * Wrap email content via the wp_mail filter (fallback when mailer is inactive).
	 *
	 * @param array<string, mixed> $args The wp_mail arguments.
	 * @return array<string, mixed> Modified arguments.
	 */
	public function wrap_wp_mail( array $args ): array {
		// Check for opt-out header.
		$headers = $args['headers'] ?? [];
		if ( is_string( $headers ) ) {
			$headers = explode( "\n", $headers );
		}

		if ( $this->has_opt_out_header( $headers ) ) {
			return $args;
		}

		if ( ! $this->is_html_email( $headers ) ) {
			return $args;
		}

		$args['message'] = $this->wrap( $args['message'] );

		return $args;
	}

	/**
	 * Decide whether an email is HTML.
	 *
	 * Mirrors WordPress core's resolution order: an explicit `Content-Type`
	 * header in $args wins, otherwise the `wp_mail_content_type` filter is
	 * consulted. Many plugins (and themes) opt every outgoing email into
	 * HTML by filtering `wp_mail_content_type` rather than by setting a
	 * per-message header, so checking only the headers misses those.
	 *
	 * @param array<int, mixed> $headers Already-split headers array.
	 * @return bool
	 */
	private function is_html_email( array $headers ): bool {
		foreach ( $headers as $header ) {
			if ( is_string( $header ) && stripos( $header, 'content-type:' ) !== false && stripos( $header, 'text/html' ) !== false ) {
				return true;
			}
		}

		/** This filter is documented in wp-includes/pluggable.php. */
		$filtered = (string) apply_filters( 'wp_mail_content_type', 'text/plain' );

		return 'text/html' === strtolower( trim( $filtered ) );
	}

	/**
	 * Wrap content in the branded email template.
	 *
	 * @param string $body_html The inner HTML content.
	 * @return string The wrapped HTML.
	 */
	public function wrap( string $body_html ): string {
		$branding = get_option( 'leastudios_email_templates_branding', [] );

		if ( empty( $branding['enabled'] ) ) {
			return $body_html;
		}

		$logo_url      = $branding['logo_url'] ?? '';
		$primary_color = $branding['primary_color'] ?? '#4f46e5';
		$footer_text   = $branding['footer_text'] ?? '';
		$social_links  = $branding['social_links'] ?? [];
		$site_name     = get_option( 'blogname', '' );

		// Process merge tags in footer text. Footer is HTML-rendered.
		$footer_text = $this->replacer->replace_html( $footer_text );

		$template_path = LEASTUDIOS_EMAIL_TEMPLATES_DIR . 'templates/email/base.php';

		/**
		 * Filters the email template file path.
		 *
		 * Allows themes or plugins to override the base email template.
		 *
		 * @param string $template_path Full path to the template file.
		 */
		$template_path = (string) apply_filters( 'leastudios_email_templates_template_path', $template_path );

		if ( ! file_exists( $template_path ) ) {
			return $body_html;
		}

		ob_start();
		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- Template variables are controlled internally.
		extract(
			[
				'body_html'     => $body_html,
				'logo_url'      => $logo_url,
				'primary_color' => $primary_color,
				'footer_text'   => $footer_text,
				'social_links'  => $social_links,
				'site_name'     => $site_name,
			]
		);
		include $template_path;
		return (string) ob_get_clean();
	}

	/**
	 * Check if the mailer plugin is active.
	 *
	 * @return bool
	 */
	private function is_mailer_active(): bool {
		return defined( 'LEASTUDIOS_MAILER_VERSION' );
	}

	/**
	 * Check for opt-out header.
	 *
	 * The array form is loosely-typed because the entry point is the wp_mail
	 * filter — third-party plugins can put arbitrary values in $args['headers'].
	 * The runtime is_string() check below is defensive against that.
	 *
	 * @param array<int, mixed>|string $headers The email headers (CRLF-joined string or list of header lines).
	 * @return bool
	 */
	private function has_opt_out_header( array|string $headers ): bool {
		if ( is_string( $headers ) ) {
			$headers = explode( "\n", $headers );
		}

		foreach ( $headers as $header ) {
			if ( is_string( $header ) && stripos( $header, 'X-LeaStudios-No-Template' ) !== false ) {
				return true;
			}
		}

		return false;
	}
}
