<?php
/**
 * Landing page shown after one-click unsubscribe.
 *
 * Variables in scope:
 *   string $email       Suppressed email (normalized).
 *   string $token       Signed token (for the resubscribe form).
 *   string $button_bg   Resolved brand color for the resubscribe button (6-digit hex).
 *   string $button_text Resolved text color for the button (#111827 or #ffffff).
 *
 * @package LEAStudios\EmailTemplates
 */

defined( 'ABSPATH' ) || exit;
?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="robots" content="noindex,nofollow">
	<title><?php esc_html_e( 'Unsubscribed', 'leastudios-email-templates' ); ?></title>
	<style>
		body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f3f4f6; margin: 0; padding: 40px 20px; color: #111827; }
		.card { max-width: 480px; margin: 0 auto; background: #fff; border-radius: 8px; padding: 32px; box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
		h1 { font-size: 20px; margin: 0 0 12px; }
		p { font-size: 14px; line-height: 1.5; color: #4b5563; margin: 0 0 16px; }
		.email { font-weight: 600; color: #111827; }
		button { font: inherit; cursor: pointer; background: <?php echo esc_attr( $button_bg ); ?>; color: <?php echo esc_attr( $button_text ); ?>; border: 0; border-radius: 6px; padding: 10px 16px; font-weight: 600; transition: opacity 0.15s ease; }
		button:hover { opacity: 0.9; }
		.muted { color: #6b7280; font-size: 12px; margin-top: 24px; }
	</style>
</head>
<body>
	<div class="card">
		<h1><?php esc_html_e( 'You\'re unsubscribed', 'leastudios-email-templates' ); ?></h1>
		<p>
			<?php
			printf(
				// translators: %s is the recipient email address.
				esc_html__( 'We won\'t send any more optional emails to %s.', 'leastudios-email-templates' ),
				'<span class="email">' . esc_html( $email ) . '</span>'
			);
			?>
		</p>
		<p>
			<?php esc_html_e( 'You\'ll still receive transactional notifications you\'re entitled to — receipts for payments, refund confirmations, payment-failure alerts, and renewal receipts.', 'leastudios-email-templates' ); ?>
		</p>
		<form method="POST" action="<?php echo esc_url( rest_url( 'leastudios-email-templates/v1/resubscribe' ) ); ?>">
			<input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>">
			<button type="submit"><?php esc_html_e( 'Resubscribe', 'leastudios-email-templates' ); ?></button>
		</form>
		<p class="muted">
			<?php
			printf(
				// translators: %s is the site name.
				esc_html__( '— %s', 'leastudios-email-templates' ),
				esc_html( (string) get_option( 'blogname', '' ) )
			);
			?>
		</p>
	</div>
</body>
</html>
