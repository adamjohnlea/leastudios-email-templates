<?php
/**
 * Sets PHPMailer's AltBody to a plain-text alternative on every HTML
 * wp_mail send, so receivers get a proper multipart/alternative message.
 *
 * @package LEAStudios\EmailTemplates\Email
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Email;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

// PHPMailer's public properties (Body, AltBody, ContentType) are PascalCase
// by upstream convention; this file talks directly to that surface.
// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

use PHPMailer\PHPMailer\PHPMailer;

/**
 * Hooks the `phpmailer_init` action — fired after WP populates a PHPMailer
 * instance and before send — and synthesises an `AltBody` from `Body` for
 * HTML emails that don't already carry one.
 *
 * Respects the same `X-LeaStudios-No-Template` opt-out header used by
 * Template_Wrapper so a sender that wants raw HTML-only delivery gets it.
 */
class Plain_Text_Injector {

	/**
	 * The opt-out header that `Template_Wrapper` also recognises.
	 */
	private const OPT_OUT_HEADER = 'X-LeaStudios-No-Template';

	/**
	 * Register hooks.
	 *
	 * Mirrors Template_Wrapper's mailer-aware dispatch: when leastudios-mailer
	 * is active we hook its pre-send filter (running *after* the wrapper at
	 * priority 10, so we generate text from the already-wrapped HTML), since
	 * the mailer bypasses PHPMailer entirely. Otherwise we hook phpmailer_init
	 * so the default WP transport still gets a populated AltBody.
	 *
	 * @return void
	 */
	public function init(): void {
		if ( $this->is_mailer_active() ) {
			add_filter( 'leastudios_mailer_pre_send', [ $this, 'inject_mailer_args' ], 30, 2 );
		} else {
			add_action( 'phpmailer_init', [ $this, 'inject' ] );
		}
	}

	/**
	 * Populate `body_text` on the mailer's pre-send args when the email is
	 * HTML and no text body has already been provided.
	 *
	 * @param array<string, mixed>|null $args          The processed email args.
	 * @param array<string, mixed>      $original_atts The original wp_mail arguments. Required by the filter signature; unused.
	 * @return array<string, mixed>|null
	 */
	public function inject_mailer_args( ?array $args, array $original_atts ): ?array {
		unset( $original_atts );

		if ( null === $args ) {
			return null;
		}

		if ( $this->has_opt_out_in_headers( $args['headers'] ?? [] ) ) {
			return $args;
		}

		$html = (string) ( $args['body_html'] ?? '' );
		$text = (string) ( $args['body_text'] ?? '' );

		if ( '' === $html || '' !== trim( $text ) ) {
			return $args;
		}

		$args['body_text'] = Plain_Text_Generator::from_html( $html );

		return $args;
	}

	/**
	 * Check whether the leastudios-mailer plugin is active.
	 *
	 * @return bool
	 */
	private function is_mailer_active(): bool {
		return defined( 'LEASTUDIOS_MAILER_VERSION' );
	}

	/**
	 * Check the opt-out header against an already-parsed headers array
	 * (used on the mailer pre-send path).
	 *
	 * @param array<int, mixed>|string $headers Headers list or CRLF-joined string.
	 * @return bool
	 */
	private function has_opt_out_in_headers( array|string $headers ): bool {
		if ( is_string( $headers ) ) {
			$headers = explode( "\n", $headers );
		}

		foreach ( $headers as $header ) {
			if ( is_string( $header ) && stripos( $header, self::OPT_OUT_HEADER ) !== false ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Populate `AltBody` from `Body` for HTML emails.
	 *
	 * Order matters: opt-out first (respects sender intent), then
	 * content-type (skip plain-text), then existing AltBody (respect what
	 * the sender already provided).
	 *
	 * @param PHPMailer $mail The PHPMailer instance to mutate.
	 * @return void
	 */
	public function inject( PHPMailer $mail ): void {
		if ( $this->has_opt_out_header( $mail ) ) {
			return;
		}

		if ( 'text/html' !== strtolower( (string) $mail->ContentType ) ) {
			return;
		}

		if ( '' !== trim( (string) $mail->AltBody ) ) {
			return;
		}

		$mail->AltBody = Plain_Text_Generator::from_html( (string) $mail->Body );
	}

	/**
	 * Check whether the PHPMailer instance carries the opt-out header.
	 *
	 * @param PHPMailer $mail The PHPMailer instance.
	 * @return bool
	 */
	private function has_opt_out_header( PHPMailer $mail ): bool {
		foreach ( $mail->getCustomHeaders() as $header ) {
			if ( ! is_array( $header ) || count( $header ) < 1 ) {
				continue;
			}
			if ( 0 === strcasecmp( (string) $header[0], self::OPT_OUT_HEADER ) ) {
				return true;
			}
		}

		return false;
	}
}
