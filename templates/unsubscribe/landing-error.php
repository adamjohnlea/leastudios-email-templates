<?php
/**
 * Landing page shown when token verification fails.
 *
 * @package LEAStudios\EmailTemplates
 */

defined( 'ABSPATH' ) || exit;
?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="robots" content="noindex,nofollow">
	<title><?php esc_html_e( 'Link expired', 'leastudios-email-templates' ); ?></title>
	<style>
		body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f3f4f6; margin: 0; padding: 40px 20px; color: #111827; }
		.card { max-width: 480px; margin: 0 auto; background: #fff; border-radius: 8px; padding: 32px; box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
		h1 { font-size: 20px; margin: 0 0 12px; }
		p { font-size: 14px; line-height: 1.5; color: #4b5563; margin: 0 0 16px; }
		.muted { color: #6b7280; font-size: 12px; margin-top: 24px; }
	</style>
</head>
<body>
	<div class="card">
		<h1><?php esc_html_e( 'This link is invalid', 'leastudios-email-templates' ); ?></h1>
		<p>
			<?php esc_html_e( 'The unsubscribe link you used couldn\'t be verified. It may have been copied incompletely, or the underlying signing key has been rotated.', 'leastudios-email-templates' ); ?>
		</p>
		<p>
			<?php
			printf(
				// translators: %s is the site admin email address (mailto link).
				esc_html__( 'Reply to any email from us, or contact %s, and we\'ll opt you out by hand.', 'leastudios-email-templates' ),
				'<a href="mailto:' . esc_attr( (string) get_option( 'admin_email', '' ) ) . '">' . esc_html( (string) get_option( 'admin_email', '' ) ) . '</a>'
			);
			?>
		</p>
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
