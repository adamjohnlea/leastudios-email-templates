<?php
/**
 * Main plugin class.
 *
 * @package LEAStudios\EmailTemplates
 */

declare(strict_types=1);

namespace LEAStudios\EmailTemplates;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use LEAStudios\EmailTemplates\Admin\Settings_Page;
use LEAStudios\EmailTemplates\Email\Email_Sender;
use LEAStudios\EmailTemplates\Email\Merge_Tag_Replacer;
use LEAStudios\EmailTemplates\Email\Plain_Text_Injector;
use LEAStudios\EmailTemplates\Email\Template_Wrapper;
use LEAStudios\EmailTemplates\Payment\Payment_Data_Resolver;
use LEAStudios\EmailTemplates\Payment\Payment_Email_Listener;

/**
 * Plugin bootstrap class.
 */
final class Plugin {

	/**
	 * Initialize the plugin components.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'init', [ $this, 'load_textdomain' ] );

		// Core services.
		$replacer = new Merge_Tag_Replacer();

		// Template wrapping for all emails.
		$wrapper = new Template_Wrapper( $replacer );
		$wrapper->init();

		// Plain-text alternative body for every HTML wp_mail.
		$injector = new Plain_Text_Injector();
		$injector->init();

		// Email sender for transactional emails.
		$sender = new Email_Sender( $replacer );

		// Payment integration (only when payments plugin is active).
		if ( $this->is_payments_active() ) {
			$resolver = new Payment_Data_Resolver();
			$listener = new Payment_Email_Listener( $sender, $resolver );
			$listener->init();
		}

		// Admin settings.
		if ( is_admin() ) {
			$settings = new Settings_Page();
			$settings->init();
		}
	}

	/**
	 * Load plugin text domain for translations.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'leastudios-email-templates',
			false,
			dirname( plugin_basename( LEASTUDIOS_EMAIL_TEMPLATES_FILE ) ) . '/languages'
		);
	}

	/**
	 * Check if the payments plugin is active.
	 *
	 * @return bool
	 */
	private function is_payments_active(): bool {
		return defined( 'LEASTUDIOS_PAYMENTS_VERSION' );
	}
}
