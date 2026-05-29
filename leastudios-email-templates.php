<?php
/**
 * Plugin Name:       leaStudios Email Templates
 * Plugin URI:        https://leastudios.com/plugins/email-templates
 * Description:       Branded email templates for all WordPress emails plus payment transactional emails.
 * Version:           1.2.1
 * Requires at least: 6.4
 * Requires PHP:      8.2
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
// Derive the version from the plugin header so the runtime constant can
// never drift from the version shipped in the release zip.
define(
	'LEASTUDIOS_EMAIL_TEMPLATES_VERSION',
	get_file_data( __FILE__, [ 'Version' => 'Version' ] )['Version']
);
define( 'LEASTUDIOS_EMAIL_TEMPLATES_FILE', __FILE__ );
define( 'LEASTUDIOS_EMAIL_TEMPLATES_DIR', plugin_dir_path( __FILE__ ) );
define( 'LEASTUDIOS_EMAIL_TEMPLATES_URL', plugin_dir_url( __FILE__ ) );

/**
 * Run on plugin activation.
 *
 * Registered above the vendor-autoload check so a botched install
 * (composer not yet run) still gets a working activation hook for
 * default-option seeding.
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
			'theme'         => 'modern-light',
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

	// Install the email log table. The repository's install() is idempotent
	// (option-versioned) so this is safe across re-activations.
	if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
		require_once __DIR__ . '/vendor/autoload.php';
		( new \LEAStudios\EmailTemplates\Database\Email_Log_Repository() )->install();
	}

	// Schedule a daily prune of old log rows.
	if ( ! wp_next_scheduled( 'leastudios_email_templates_log_prune' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'leastudios_email_templates_log_prune' );
	}
}
register_activation_hook( __FILE__, 'leastudios_email_templates_activate' );

/**
 * Run on plugin deactivation.
 *
 * @return void
 */
function leastudios_email_templates_deactivate(): void {
	$timestamp = wp_next_scheduled( 'leastudios_email_templates_log_prune' );
	if ( false !== $timestamp ) {
		wp_unschedule_event( $timestamp, 'leastudios_email_templates_log_prune' );
	}
}
register_deactivation_hook( __FILE__, 'leastudios_email_templates_deactivate' );

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
	if ( version_compare( PHP_VERSION, '8.2', '<' ) ) {
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
		esc_html__( 'leaStudios Email Templates requires PHP 8.2 or higher.', 'leastudios-email-templates' )
	);
}
