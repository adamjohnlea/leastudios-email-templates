<?php
/**
 * Admin settings page with branding and email type configuration.
 *
 * @package LEAStudios\EmailTemplates\Admin
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates\Admin;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use LEAStudios\EmailTemplates\Database\Suppression_Repository;
use LEAStudios\EmailTemplates\Email\Email_Sender;
use LEAStudios\EmailTemplates\Email\Email_Type_Definition;
use LEAStudios\EmailTemplates\Email\Email_Type_Registry;
use LEAStudios\EmailTemplates\Email\Merge_Tag_Replacer;
use LEAStudios\EmailTemplates\Email\Template_Wrapper;
use LEAStudios\EmailTemplates\Email\Theme;
use LEAStudios\EmailTemplates\Security\Nonce;
use LEAStudios\EmailTemplates\Subscription\Unsubscribe_Manager;

/**
 * Registers and renders the plugin settings page.
 */
class Settings_Page {

	/**
	 * The branding option name.
	 */
	private const BRANDING_OPTION = 'leastudios_email_templates_branding';

	/**
	 * The emails option name.
	 */
	private const EMAILS_OPTION = 'leastudios_email_templates_emails';

	/**
	 * The required capability.
	 */
	private const CAPABILITY = 'manage_options';

	/**
	 * The settings page hook suffix.
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * Constructor.
	 *
	 * @param Email_Type_Registry $registry The shared type registry.
	 */
	public function __construct(
		private readonly Email_Type_Registry $registry,
	) {}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_leastudios_email_templates_preview', [ $this, 'handle_preview' ] );
		add_action( 'wp_ajax_leastudios_email_templates_preview_type', [ $this, 'handle_preview_type' ] );
		add_action( 'wp_ajax_leastudios_email_templates_send_test', [ $this, 'handle_send_test' ] );
	}

	/**
	 * Add the admin menu page.
	 *
	 * @return void
	 */
	public function add_menu_page(): void {
		$this->hook_suffix = \add_menu_page(
			__( 'Email Templates', 'leastudios-email-templates' ),
			__( 'Email Templates', 'leastudios-email-templates' ),
			self::CAPABILITY,
			'leastudios-email-templates',
			[ $this, 'render_page' ],
			'dashicons-email'
		);
	}

	/**
	 * Register settings.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			'leastudios_email_templates_branding_group',
			self::BRANDING_OPTION,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize_branding' ],
			]
		);

		register_setting(
			'leastudios_email_templates_emails_group',
			self::EMAILS_OPTION,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize_emails' ],
			]
		);
	}

	/**
	 * Sanitize branding options.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return array<string, mixed> Sanitized values.
	 */
	public function sanitize_branding( array $input ): array {
		$sanitized = [];

		$sanitized['enabled']  = ! empty( $input['enabled'] );
		$sanitized['logo_url'] = esc_url_raw( $input['logo_url'] ?? '' );
		// sanitize_hex_color returns string|null (null on invalid input). Fall
		// back to the default when the value is null OR '', covering both the
		// "invalid hex" and "empty string passed in" cases.
		$color                      = sanitize_hex_color( $input['primary_color'] ?? '#4f46e5' );
		$sanitized['primary_color'] = ( null !== $color && '' !== $color ) ? $color : '#4f46e5';
		$sanitized['footer_text']   = wp_kses_post( $input['footer_text'] ?? '' );

		$sanitized['social_links'] = [];
		$platforms                 = [ 'twitter', 'facebook', 'linkedin', 'instagram' ];
		foreach ( $platforms as $platform ) {
			$sanitized['social_links'][ $platform ] = esc_url_raw( $input['social_links'][ $platform ] ?? '' );
		}

		$theme_id           = (string) ( $input['theme'] ?? Theme::DEFAULT_ID );
		$sanitized['theme'] = isset( Theme::available()[ $theme_id ] )
			? $theme_id
			: Theme::DEFAULT_ID;

		return $sanitized;
	}

	/**
	 * Sanitize email type settings.
	 *
	 * @param array<string, mixed> $input Raw input keyed by Email_Type_Definition id.
	 * @return array<string, array{enabled: bool, subject: string, body: string, recipient_override: string}> Sanitized values.
	 */
	public function sanitize_emails( array $input ): array {
		$sanitized = [];

		foreach ( $this->registry->all() as $type_id => $_definition ) {
			$data = $input[ $type_id ] ?? [];

			$sanitized[ $type_id ] = [
				'enabled'            => ! empty( $data['enabled'] ),
				'subject'            => sanitize_text_field( $data['subject'] ?? '' ),
				'body'               => wp_kses_post( $data['body'] ?? '' ),
				'recipient_override' => sanitize_email( $data['recipient_override'] ?? '' ),
			];
		}

		return $sanitized;
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_media();

		wp_enqueue_style(
			'leastudios-email-templates-admin',
			LEASTUDIOS_EMAIL_TEMPLATES_URL . 'assets/css/admin.css',
			[],
			$this->asset_version( 'assets/css/admin.css' )
		);

		wp_enqueue_script(
			'leastudios-email-templates-admin',
			LEASTUDIOS_EMAIL_TEMPLATES_URL . 'assets/js/admin.js',
			[ 'jquery', 'wp-color-picker', 'media-upload' ],
			$this->asset_version( 'assets/js/admin.js' ),
			true
		);

		wp_localize_script(
			'leastudios-email-templates-admin',
			'leastudiosEmailTemplates',
			[
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'previewNonce' => Nonce::create( 'preview' ),
				'strings'      => [
					'selectImage' => __( 'Select Logo', 'leastudios-email-templates' ),
					'useImage'    => __( 'Use this image', 'leastudios-email-templates' ),
					'remove'      => __( 'Remove', 'leastudios-email-templates' ),
				],
			]
		);
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'branding';

		$tabs = [
			'branding'    => __( 'Branding', 'leastudios-email-templates' ),
			'email-types' => __( 'Email Types', 'leastudios-email-templates' ),
		];

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'leaStudios Email Templates', 'leastudios-email-templates' ); ?></h1>

			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a href="?page=leastudios-email-templates&tab=<?php echo esc_attr( $slug ); ?>" class="nav-tab <?php echo $slug === $active_tab ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="leastudios-email-templates-tab-content" style="margin-top: 20px;">
				<?php
				if ( 'email-types' === $active_tab ) {
					$this->render_email_types_tab();
				} else {
					$this->render_branding_tab();
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the branding tab.
	 *
	 * @return void
	 */
	private function render_branding_tab(): void {
		$branding = get_option( self::BRANDING_OPTION, [] );

		?>
		<form action="options.php" method="post">
			<?php settings_fields( 'leastudios_email_templates_branding_group' ); ?>

			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable Templates', 'leastudios-email-templates' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( self::BRANDING_OPTION ); ?>[enabled]" value="1" <?php checked( ! empty( $branding['enabled'] ) ); ?> />
							<?php esc_html_e( 'Wrap all outgoing emails in the branded template.', 'leastudios-email-templates' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Logo', 'leastudios-email-templates' ); ?></th>
					<td>
						<div id="leastudios-logo-preview">
							<?php if ( ! empty( $branding['logo_url'] ) ) : ?>
								<img src="<?php echo esc_url( $branding['logo_url'] ); ?>" style="max-height:50px;margin-bottom:10px;display:block;" />
							<?php endif; ?>
						</div>
						<input type="hidden" id="leastudios-logo-url" name="<?php echo esc_attr( self::BRANDING_OPTION ); ?>[logo_url]" value="<?php echo esc_attr( $branding['logo_url'] ?? '' ); ?>" />
						<button type="button" id="leastudios-upload-logo" class="button"><?php esc_html_e( 'Select Logo', 'leastudios-email-templates' ); ?></button>
						<button type="button" id="leastudios-remove-logo" class="button" <?php echo empty( $branding['logo_url'] ) ? 'style="display:none;"' : ''; ?>><?php esc_html_e( 'Remove', 'leastudios-email-templates' ); ?></button>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="primary-color"><?php esc_html_e( 'Primary Color', 'leastudios-email-templates' ); ?></label></th>
					<td>
						<input type="text" id="primary-color" name="<?php echo esc_attr( self::BRANDING_OPTION ); ?>[primary_color]" value="<?php echo esc_attr( $branding['primary_color'] ?? '#4f46e5' ); ?>" class="leastudios-color-picker" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="theme"><?php esc_html_e( 'Theme', 'leastudios-email-templates' ); ?></label></th>
					<td>
						<?php
						$selected_theme = (string) ( $branding['theme'] ?? Theme::DEFAULT_ID );
						?>
						<select id="theme" name="<?php echo esc_attr( self::BRANDING_OPTION ); ?>[theme]">
							<?php foreach ( Theme::available() as $theme_id => $theme_label ) : ?>
								<option value="<?php echo esc_attr( $theme_id ); ?>" <?php selected( $selected_theme, $theme_id ); ?>>
									<?php echo esc_html( $theme_label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							<?php
							esc_html_e(
								'Modern Light adapts automatically on email clients that support dark mode (Apple Mail, iOS Mail). Modern Dark is always dark.',
								'leastudios-email-templates'
							);
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="footer-text"><?php esc_html_e( 'Footer Text', 'leastudios-email-templates' ); ?></label></th>
					<td>
						<textarea id="footer-text" name="<?php echo esc_attr( self::BRANDING_OPTION ); ?>[footer_text]" rows="3" class="large-text"><?php echo esc_textarea( $branding['footer_text'] ?? '' ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Supports merge tags: {site_name}, {site_url}', 'leastudios-email-templates' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Social Links', 'leastudios-email-templates' ); ?></th>
					<td>
						<?php
						$platforms = [
							'twitter'   => 'Twitter / X',
							'facebook'  => 'Facebook',
							'linkedin'  => 'LinkedIn',
							'instagram' => 'Instagram',
						];
						$socials   = $branding['social_links'] ?? [];
						foreach ( $platforms as $key => $label ) :
							?>
							<p>
								<label><?php echo esc_html( $label ); ?><br />
									<input type="url" name="<?php echo esc_attr( self::BRANDING_OPTION ); ?>[social_links][<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $socials[ $key ] ?? '' ); ?>" class="regular-text" />
								</label>
							</p>
						<?php endforeach; ?>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Save Branding', 'leastudios-email-templates' ) ); ?>
		</form>

		<hr />
		<h2><?php esc_html_e( 'Preview', 'leastudios-email-templates' ); ?></h2>
		<p>
			<button type="button" id="leastudios-preview-email" class="button"><?php esc_html_e( 'Preview Email Template', 'leastudios-email-templates' ); ?></button>
		</p>
		<div id="leastudios-preview-frame" style="margin-top:15px;border:1px solid #ccd0d4;background:#fff;display:none;">
			<iframe id="leastudios-preview-iframe" style="width:100%;height:600px;border:0;"></iframe>
		</div>
		<?php
	}

	/**
	 * Render the email types tab.
	 *
	 * @return void
	 */
	private function render_email_types_tab(): void {
		$email_settings  = get_option( self::EMAILS_OPTION, [] );
		$payments_active = defined( 'LEASTUDIOS_PAYMENTS_VERSION' );

		if ( ! $payments_active ) {
			printf(
				'<div class="notice notice-warning inline"><p>%s</p></div>',
				esc_html__( 'The leaStudios Payments plugin is not active. Payment email types will not trigger until it is activated.', 'leastudios-email-templates' )
			);
		}

		?>
		<form action="options.php" method="post">
			<?php settings_fields( 'leastudios_email_templates_emails_group' ); ?>

			<?php foreach ( $this->registry->all() as $type ) : ?>
				<?php
				$key      = $type->id();
				$settings = $email_settings[ $key ] ?? [];
				$enabled  = $settings['enabled'] ?? true;
				$subject  = $settings['subject'] ?? '';
				$body     = $settings['body'] ?? '';
				$override = $settings['recipient_override'] ?? '';
				?>
				<div class="leastudios-email-type-section" style="margin-bottom:25px;border:1px solid #ccd0d4;border-radius:4px;">
					<div class="leastudios-email-type-header" style="padding:12px 15px;background:#f0f0f1;cursor:pointer;display:flex;justify-content:space-between;align-items:center;">
						<strong><?php echo esc_html( $type->label() ); ?></strong>
						<span class="dashicons dashicons-arrow-down-alt2"></span>
					</div>
					<div class="leastudios-email-type-body" style="padding:15px;display:none;">
						<table class="form-table" style="margin:0;">
							<tr>
								<th scope="row"><?php esc_html_e( 'Enabled', 'leastudios-email-templates' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="<?php echo esc_attr( self::EMAILS_OPTION ); ?>[<?php echo esc_attr( $key ); ?>][enabled]" value="1" <?php checked( $enabled ); ?> />
										<?php esc_html_e( 'Send this email when the event occurs.', 'leastudios-email-templates' ); ?>
									</label>
								</td>
							</tr>
							<tr>
								<th scope="row"><label><?php esc_html_e( 'Subject', 'leastudios-email-templates' ); ?></label></th>
								<td>
									<input type="text" name="<?php echo esc_attr( self::EMAILS_OPTION ); ?>[<?php echo esc_attr( $key ); ?>][subject]" value="<?php echo esc_attr( $subject ); ?>" class="large-text" placeholder="<?php echo esc_attr( $type->default_subject() ); ?>" />
								</td>
							</tr>
							<tr>
								<th scope="row"><label><?php esc_html_e( 'Body', 'leastudios-email-templates' ); ?></label></th>
								<td>
									<?php
									wp_editor(
										$body,
										'email_body_' . $key,
										[
											'textarea_name' => self::EMAILS_OPTION . "[{$key}][body]",
											'textarea_rows' => 10,
											'media_buttons' => false,
											'teeny' => true,
										]
									);
									?>
									<p class="description"><?php esc_html_e( 'Leave blank to use the default template.', 'leastudios-email-templates' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label><?php esc_html_e( 'Recipient Override', 'leastudios-email-templates' ); ?></label></th>
								<td>
									<input type="email" name="<?php echo esc_attr( self::EMAILS_OPTION ); ?>[<?php echo esc_attr( $key ); ?>][recipient_override]" value="<?php echo esc_attr( $override ); ?>" class="regular-text" />
									<p class="description"><?php esc_html_e( 'Send to this address instead of the customer. Useful for testing.', 'leastudios-email-templates' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Available Tags', 'leastudios-email-templates' ); ?></th>
								<td>
									<code style="display:inline-block;margin:2px;">
										<?php
										$tags = $type->available_tags();
										// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
										echo implode( '</code> <code style="display:inline-block;margin:2px;">', array_map( 'esc_html', array_keys( $tags ) ) );
										?>
									</code>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Preview', 'leastudios-email-templates' ); ?></th>
								<td>
									<button type="button" class="button leastudios-preview-type" data-type="<?php echo esc_attr( $key ); ?>">
										<?php esc_html_e( 'Preview This Email', 'leastudios-email-templates' ); ?>
									</button>
									<p class="description leastudios-preview-subject" data-type="<?php echo esc_attr( $key ); ?>" style="display:none;"></p>
									<div class="leastudios-preview-frame" data-type="<?php echo esc_attr( $key ); ?>" style="margin-top:10px;border:1px solid #ccd0d4;background:#fff;display:none;">
										<iframe style="width:100%;height:500px;border:0;"></iframe>
									</div>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Send Test', 'leastudios-email-templates' ); ?></th>
								<td>
									<input type="email" class="regular-text leastudios-send-test-to" data-type="<?php echo esc_attr( $key ); ?>" placeholder="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" />
									<button type="button" class="button leastudios-send-test" data-type="<?php echo esc_attr( $key ); ?>">
										<?php esc_html_e( 'Send Test Email', 'leastudios-email-templates' ); ?>
									</button>
									<p class="description leastudios-send-test-result" data-type="<?php echo esc_attr( $key ); ?>"></p>
								</td>
							</tr>
						</table>
					</div>
				</div>
			<?php endforeach; ?>

			<?php submit_button( __( 'Save Email Settings', 'leastudios-email-templates' ) ); ?>
		</form>
		<?php
	}

	/**
	 * AJAX handler: render email preview.
	 *
	 * @return void
	 */
	public function handle_preview(): void {
		Nonce::check_ajax( 'preview' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( __( 'Permission denied.', 'leastudios-email-templates' ) );
		}

		$replacer = new Merge_Tag_Replacer();
		$wrapper  = new Template_Wrapper( $replacer );

		$sample_body = '<h2>' . __( 'This is a preview', 'leastudios-email-templates' ) . '</h2>'
			. '<p>' . __( 'Hi there! This is how your branded emails will look.', 'leastudios-email-templates' ) . '</p>'
			. '<p>' . __( 'All emails sent from your WordPress site will be wrapped in this template when branding is enabled.', 'leastudios-email-templates' ) . '</p>'
			. '<table style="width:100%;border-collapse:collapse;margin:20px 0;">'
			. '<tr><td style="padding:8px;border:1px solid #e5e7eb;font-weight:bold;">' . __( 'Product', 'leastudios-email-templates' ) . '</td><td style="padding:8px;border:1px solid #e5e7eb;">Example Product</td></tr>'
			. '<tr><td style="padding:8px;border:1px solid #e5e7eb;font-weight:bold;">' . __( 'Amount', 'leastudios-email-templates' ) . '</td><td style="padding:8px;border:1px solid #e5e7eb;">$29.99</td></tr>'
			. '<tr><td style="padding:8px;border:1px solid #e5e7eb;font-weight:bold;">' . __( 'Date', 'leastudios-email-templates' ) . '</td><td style="padding:8px;border:1px solid #e5e7eb;">' . wp_date( get_option( 'date_format' ) ) . '</td></tr>'
			. '</table>';

		$html = $wrapper->wrap( $sample_body );

		wp_send_json_success( [ 'html' => $html ] );
	}

	/**
	 * AJAX handler: render a specific email type with sample (or user-supplied)
	 * subject/body and wrap it in the branded template.
	 *
	 * Accepts optional `subject` and `body` POST fields so the admin can
	 * preview unsaved edits without round-tripping through Save first.
	 *
	 * @return void
	 */
	public function handle_preview_type(): void {
		Nonce::check_ajax( 'preview' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( __( 'Permission denied.', 'leastudios-email-templates' ) );
		}

		$definition = $this->resolve_posted_definition();

		if ( null === $definition ) {
			wp_send_json_error( __( 'Unknown email type.', 'leastudios-email-templates' ) );
		}

		$replacer = new Merge_Tag_Replacer();
		$wrapper  = new Template_Wrapper( $replacer );
		$sample   = $definition->sample_context();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked above via Nonce::check_ajax.
		$subject_tpl = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['subject'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked above via Nonce::check_ajax.
		$body_tpl = isset( $_POST['body'] ) ? wp_kses_post( wp_unslash( (string) $_POST['body'] ) ) : '';

		if ( '' === $subject_tpl ) {
			$subject_tpl = $definition->default_subject();
		}
		if ( '' === $body_tpl ) {
			$body_tpl = $definition->default_body();
		}

		$rendered_subject = $replacer->replace_subject( $subject_tpl, $sample );
		$rendered_body    = $replacer->replace_html( $body_tpl, $sample, $definition->escape_map() );

		$html = $wrapper->wrap( $rendered_body );

		wp_send_json_success(
			[
				'subject' => $rendered_subject,
				'html'    => $html,
			]
		);
	}

	/**
	 * AJAX handler: send a real sample email of the given type to a chosen
	 * address. Uses the definition's sample_context() so all merge tags
	 * resolve to recognisable placeholder values.
	 *
	 * @return void
	 */
	public function handle_send_test(): void {
		Nonce::check_ajax( 'preview' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( __( 'Permission denied.', 'leastudios-email-templates' ) );
		}

		$definition = $this->resolve_posted_definition();

		if ( null === $definition ) {
			wp_send_json_error( __( 'Unknown email type.', 'leastudios-email-templates' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked above via Nonce::check_ajax.
		$to = isset( $_POST['to'] ) ? sanitize_email( wp_unslash( (string) $_POST['to'] ) ) : '';

		if ( '' === $to || ! is_email( $to ) ) {
			wp_send_json_error( __( 'A valid email address is required.', 'leastudios-email-templates' ) );
		}

		$sender = new Email_Sender( new Merge_Tag_Replacer(), $this->registry, new Unsubscribe_Manager( new Suppression_Repository() ) );
		$result = $sender->send( $definition->id(), $to, $definition->sample_context() );

		if ( ! $result ) {
			wp_send_json_error(
				__( 'Email could not be sent. Check that this email type is enabled and that wp_mail is configured.', 'leastudios-email-templates' )
			);
		}

		wp_send_json_success(
			[
				'message' => sprintf(
					/* translators: %s is the recipient email address. */
					__( 'Test email sent to %s.', 'leastudios-email-templates' ),
					$to
				),
			]
		);
	}

	/**
	 * Compute a cache-busting version string for an asset.
	 *
	 * Uses `filemtime` so editing an asset bumps its query-string version
	 * automatically — no plugin version bump required for a CSS/JS-only
	 * fix. Falls back to the plugin version if the file is unreadable.
	 *
	 * @param string $relative_path Path relative to the plugin directory.
	 * @return string
	 */
	private function asset_version( string $relative_path ): string {
		$path = LEASTUDIOS_EMAIL_TEMPLATES_DIR . $relative_path;

		if ( ! file_exists( $path ) ) {
			return LEASTUDIOS_EMAIL_TEMPLATES_VERSION;
		}

		$mtime = filemtime( $path );

		return false !== $mtime ? (string) $mtime : LEASTUDIOS_EMAIL_TEMPLATES_VERSION;
	}

	/**
	 * Map a POSTed `type` key to its registered Email_Type_Definition.
	 *
	 * @return Email_Type_Definition|null
	 */
	private function resolve_posted_definition(): ?Email_Type_Definition {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Callers verify the nonce before invoking this helper.
		$raw = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['type'] ) ) : '';

		return $this->registry->get( $raw );
	}
}
