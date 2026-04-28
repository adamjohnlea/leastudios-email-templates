<?php
/**
 * Base branded email template.
 *
 * Variables available:
 *
 * @var string $body_html     The inner email content.
 * @var string $logo_url      URL to the logo image.
 * @var string $primary_color Hex color for branding.
 * @var string $footer_text   Footer text (merge tags already replaced).
 * @var array  $social_links  Social media URLs keyed by platform.
 * @var string $site_name     The WordPress site name.
 *
 * @package LEAStudios\EmailTemplates
 */

declare(strict_types=1);

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

?>
<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta http-equiv="X-UA-Compatible" content="IE=edge">
	<meta name="x-apple-disable-message-reformatting">
	<title><?php echo esc_html( $site_name ); ?></title>
	<!--[if mso]>
	<noscript>
		<xml>
			<o:OfficeDocumentSettings>
				<o:AllowPNG/>
				<o:PixelsPerInch>96</o:PixelsPerInch>
			</o:OfficeDocumentSettings>
		</xml>
	</noscript>
	<![endif]-->
</head>
<body style="margin:0;padding:0;background-color:#f4f4f7;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;">
	<!-- Outer wrapper -->
	<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:#f4f4f7;">
		<tr>
			<td align="center" style="padding:30px 10px;">
				<!--[if mso]><table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600"><tr><td><![endif]-->
				<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:600px;">

					<!-- Header -->
					<tr>
						<td align="center" style="padding:20px 40px;">
							<?php if ( ! empty( $logo_url ) ) : ?>
								<img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $site_name ); ?>" style="max-height:50px;width:auto;border:0;display:block;" />
							<?php else : ?>
								<h1 style="margin:0;font-size:24px;font-weight:700;color:<?php echo esc_attr( $primary_color ); ?>;"><?php echo esc_html( $site_name ); ?></h1>
							<?php endif; ?>
						</td>
					</tr>

					<!-- Body -->
					<tr>
						<td style="background-color:#ffffff;border-radius:8px;padding:40px;font-size:16px;line-height:1.6;color:#1f2937;">
							<?php
							// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Content is already escaped by the sender.
							echo $body_html;
							?>
						</td>
					</tr>

					<!-- Footer -->
					<tr>
						<td style="padding:30px 40px;text-align:center;">
							<?php if ( ! empty( $footer_text ) ) : ?>
								<p style="margin:0 0 15px;font-size:13px;line-height:1.5;color:#6b7280;">
									<?php echo wp_kses_post( $footer_text ); ?>
								</p>
							<?php endif; ?>

							<?php
							$active_socials = array_filter( $social_links ?? [] );
							if ( ! empty( $active_socials ) ) :
								$social_labels = [
									'twitter'   => 'Twitter',
									'facebook'  => 'Facebook',
									'linkedin'  => 'LinkedIn',
									'instagram' => 'Instagram',
								];
								?>
								<p style="margin:0 0 15px;font-size:13px;color:#6b7280;">
									<?php
									$links = [];
									foreach ( $active_socials as $platform => $url ) {
										$label   = $social_labels[ $platform ] ?? ucfirst( $platform );
										$links[] = '<a href="' . esc_url( $url ) . '" style="color:' . esc_attr( $primary_color ) . ';text-decoration:none;">' . esc_html( $label ) . '</a>';
									}
									// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each link is escaped individually above.
									echo implode( ' &middot; ', $links );
									?>
								</p>
							<?php endif; ?>

							<p style="margin:0;font-size:12px;color:#9ca3af;">
								<?php echo esc_html( $site_name ); ?>
							</p>
						</td>
					</tr>

				</table>
				<!--[if mso]></td></tr></table><![endif]-->
			</td>
		</tr>
	</table>
</body>
</html>
