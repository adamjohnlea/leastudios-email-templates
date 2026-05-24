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
 * @var string               $site_name     The WordPress site name.
 * @var array<string, string> $colors        Theme colour tokens: outer_bg, card_bg, text, muted, subtle.
 * @var bool                  $prefers_dark  Whether to emit a prefers-color-scheme:dark override.
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
	<?php if ( $prefers_dark ) : ?>
	<style type="text/css">
	@media (prefers-color-scheme: dark) {
		body, .leastudios-outer { background-color: #0f172a !important; }
		.leastudios-card { background-color: #1e293b !important; color: #e2e8f0 !important; }
		.leastudios-muted { color: #94a3b8 !important; }
		.leastudios-subtle { color: #64748b !important; }
	}
	</style>
	<?php endif; ?>
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
<body style="margin:0;padding:0;background-color:<?php echo esc_attr( $colors['outer_bg'] ); ?>;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;">
	<!-- Outer wrapper -->
	<table class="leastudios-outer" role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:<?php echo esc_attr( $colors['outer_bg'] ); ?>;">
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
						<td class="leastudios-card" style="background-color:<?php echo esc_attr( $colors['card_bg'] ); ?>;border-radius:8px;padding:40px;font-size:16px;line-height:1.6;color:<?php echo esc_attr( $colors['text'] ); ?>;">
							<?php
							// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $body_html is intentional HTML; merge-tag values were escaped by Merge_Tag_Replacer::replace_html before being substituted in.
							echo $body_html;
							?>
						</td>
					</tr>

					<!-- Footer -->
					<tr>
						<td style="padding:30px 40px;text-align:center;">
							<?php if ( ! empty( $footer_text ) ) : ?>
								<p class="leastudios-muted" style="margin:0 0 15px;font-size:13px;line-height:1.5;color:<?php echo esc_attr( $colors['muted'] ); ?>;">
									<?php echo wp_kses_post( $footer_text ); ?>
								</p>
							<?php endif; ?>

							<?php
							$let_active_socials = array_filter( $social_links ?? [] );
							if ( ! empty( $let_active_socials ) ) :
								$let_social_labels = [
									'twitter'   => 'Twitter',
									'facebook'  => 'Facebook',
									'linkedin'  => 'LinkedIn',
									'instagram' => 'Instagram',
								];
								?>
								<p class="leastudios-muted" style="margin:0 0 15px;font-size:13px;color:<?php echo esc_attr( $colors['muted'] ); ?>;">
									<?php
									$let_links = [];
									foreach ( $let_active_socials as $let_platform => $let_url ) {
										$let_label   = $let_social_labels[ $let_platform ] ?? ucfirst( $let_platform );
										$let_links[] = '<a href="' . esc_url( $let_url ) . '" style="color:' . esc_attr( $primary_color ) . ';text-decoration:none;">' . esc_html( $let_label ) . '</a>';
									}
									// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each link is escaped individually above.
									echo implode( ' &middot; ', $let_links );
									?>
								</p>
							<?php endif; ?>

							<p class="leastudios-subtle" style="margin:0;font-size:12px;color:<?php echo esc_attr( $colors['subtle'] ); ?>;">
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
