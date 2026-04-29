<?php
/**
 * Plugin Name:       leaStudios Email Templates
 * Plugin URI:        https://leastudios.com/plugins/email-templates
 * Description:       Branded email templates for all WordPress emails plus payment transactional emails.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            leaStudios
 * Author URI:        https://leastudios.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       leastudios-email-templates
 * Domain Path:       /languages
 *
 * @package LEAStudios\EmailTemplates
 */

declare(strict_types=1);

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

// Plugin constants.
define( 'LEASTUDIOS_EMAIL_TEMPLATES_VERSION', '1.0.0' );
define( 'LEASTUDIOS_EMAIL_TEMPLATES_FILE', __FILE__ );
define( 'LEASTUDIOS_EMAIL_TEMPLATES_DIR', plugin_dir_path( __FILE__ ) );
define( 'LEASTUDIOS_EMAIL_TEMPLATES_URL', plugin_dir_url( __FILE__ ) );

// Autoloader.
if ( ! file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	add_action(
		'admin_notices',
		function () {
			printf(
				'<div class="notice notice-error"><p><strong>%s</strong>: %s</p></div>',
				esc_html__( 'leaStudios Email Templates', 'leastudios-email-templates' ),
				esc_html__( 'Plugin dependencies are missing. Run "composer install" in the plugin directory.', 'leastudios-email-templates' )
			);
		}
	);
	return;
}

require_once __DIR__ . '/vendor/autoload.php';

/**
 * Initialize the plugin.
 *
 * @return void
 */
function leastudios_email_templates_init(): void {
	if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
		add_action( 'admin_notices', 'leastudios_email_templates_php_version_notice' );
		return;
	}

	$plugin = new LEAStudios\EmailTemplates\Plugin();
	$plugin->init();
}
add_action( 'plugins_loaded', 'leastudios_email_templates_init' );

/**
 * Display PHP version notice.
 *
 * @return void
 */
function leastudios_email_templates_php_version_notice(): void {
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__( 'leaStudios Email Templates requires PHP 8.1 or higher.', 'leastudios-email-templates' )
	);
}

/**
 * Run on plugin activation.
 *
 * @return void
 */
function leastudios_email_templates_activate(): void {
	add_option(
		'leastudios_email_templates_branding',
		[
			'enabled'       => true,
			'logo_url'      => '',
			'primary_color' => '#4f46e5',
			'footer_text'   => '',
			'social_links'  => [
				'twitter'   => '',
				'facebook'  => '',
				'linkedin'  => '',
				'instagram' => '',
			],
		]
	);

	add_option( 'leastudios_email_templates_emails', [] );
}
register_activation_hook( __FILE__, 'leastudios_email_templates_activate' );

/**
 * Run on plugin deactivation.
 *
 * @return void
 */
function leastudios_email_templates_deactivate(): void {
	// Nothing to clean up on deactivation.
}
register_deactivation_hook( __FILE__, 'leastudios_email_templates_deactivate' );
