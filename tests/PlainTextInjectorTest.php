<?php
/**
 * Tests for Plain_Text_Injector.
 *
 * @package LEAStudios\EmailTemplates\Tests
 */

declare(strict_types=1);

// PHPMailer's public properties (Body, AltBody, ContentType) are PascalCase
// by upstream convention; the test assertions read/write that surface
// directly.
// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

namespace LEAStudios\EmailTemplates\Tests;

use LEAStudios\EmailTemplates\Email\Plain_Text_Injector;
use LEAStudios\Tests\TestCase;

/**
 * @covers \LEAStudios\EmailTemplates\Email\Plain_Text_Injector
 */
class PlainTextInjectorTest extends TestCase {

	private Plain_Text_Injector $injector;

	public function set_up(): void {
		parent::set_up();
		$this->injector = new Plain_Text_Injector();
	}

	public function test_sets_alt_body_for_html_email_without_existing_alt(): void {
		$mail              = new \PHPMailer\PHPMailer\PHPMailer( true );
		$mail->ContentType = 'text/html';
		$mail->Body        = '<p>Hello <strong>world</strong>!</p>';

		$this->injector->inject( $mail );

		$this->assertSame( 'Hello world!', $mail->AltBody );
	}

	public function test_preserves_existing_alt_body(): void {
		$mail              = new \PHPMailer\PHPMailer\PHPMailer( true );
		$mail->ContentType = 'text/html';
		$mail->Body        = '<p>Hello</p>';
		$mail->AltBody     = 'Already set';

		$this->injector->inject( $mail );

		$this->assertSame( 'Already set', $mail->AltBody );
	}

	public function test_does_not_touch_plain_text_emails(): void {
		$mail              = new \PHPMailer\PHPMailer\PHPMailer( true );
		$mail->ContentType = 'text/plain';
		$mail->Body        = 'Plain message';

		$this->injector->inject( $mail );

		$this->assertSame( '', $mail->AltBody );
	}

	public function test_respects_opt_out_header(): void {
		$mail              = new \PHPMailer\PHPMailer\PHPMailer( true );
		$mail->ContentType = 'text/html';
		$mail->Body        = '<p>Hello</p>';
		$mail->addCustomHeader( 'X-LeaStudios-No-Template', 'true' );

		$this->injector->inject( $mail );

		$this->assertSame( '', $mail->AltBody );
	}
}
