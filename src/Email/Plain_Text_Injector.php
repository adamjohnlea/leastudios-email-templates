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
	 * @return void
	 */
	public function init(): void {
		add_action( 'phpmailer_init', [ $this, 'inject' ] );
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
