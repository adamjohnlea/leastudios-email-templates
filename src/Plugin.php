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

use LEAStudios\EmailTemplates\Admin\Email_Log_Page;
use LEAStudios\EmailTemplates\Admin\Settings_Page;
use LEAStudios\EmailTemplates\Admin\Suppressions_Page;
use LEAStudios\EmailTemplates\Database\Email_Log_Repository;
use LEAStudios\EmailTemplates\Database\Suppression_Repository;
use LEAStudios\EmailTemplates\REST\Unsubscribe_Controller;
use LEAStudios\EmailTemplates\Subscription\Unsubscribe_Manager;
use LEAStudios\EmailTemplates\Email\Built_In\Payment_Failed;
use LEAStudios\EmailTemplates\Email\Built_In\Payment_Receipt;
use LEAStudios\EmailTemplates\Email\Built_In\Refund_Processed;
use LEAStudios\EmailTemplates\Email\Built_In\Subscription_Created;
use LEAStudios\EmailTemplates\Email\Built_In\Subscription_Renewed;
use LEAStudios\EmailTemplates\Email\Email_Sender;
use LEAStudios\EmailTemplates\Email\Email_Type_Registry;
use LEAStudios\EmailTemplates\Email\Merge_Tag_Replacer;
use LEAStudios\EmailTemplates\Email\Plain_Text_Injector;
use LEAStudios\EmailTemplates\Email\Template_Wrapper;
use LEAStudios\EmailTemplates\CLI\Commands;
use LEAStudios\EmailTemplates\Log\Send_Logger;
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

		// Persistent send log for every transactional email.
		$log_repo = new Email_Log_Repository();
		$logger   = new Send_Logger( $log_repo );
		$logger->init();

		// Daily prune of old log rows. Retention window is filterable.
		add_action(
			'leastudios_email_templates_log_prune',
			static function () use ( $log_repo ): void {
				/**
				 * Filters the log retention window in days.
				 *
				 * @param int $days Default 30.
				 */
				$days = (int) apply_filters( 'leastudios_email_templates_log_retention_days', 30 );
				$log_repo->prune_older_than( max( 1, $days ) );
			}
		);

		// Persistent suppression store for Phase 9. install() is idempotent
		// (a schema-version option short-circuits the no-op case) so it can
		// run on every request without measurable cost.
		$suppression_repo = new Suppression_Repository();
		$suppression_repo->install();

		// Unsubscribe / suppression facade — a single instance is shared by
		// Email_Sender (gate), the REST controller (token mint/verify), the
		// admin page, and the WP-CLI commands so all surfaces see the same
		// state and use the same HMAC secret.
		$unsubscribe = new Unsubscribe_Manager( $suppression_repo );

		// Public REST endpoints for one-click unsubscribe and POST resubscribe.
		// Registered on rest_api_init so the routes only spin up as part of
		// the REST bootstrap rather than for every page load.
		add_action(
			'rest_api_init',
			static function () use ( $unsubscribe ): void {
				( new Unsubscribe_Controller( $unsubscribe ) )->register_routes();
			}
		);

		// Type registry — populated with built-ins and then opened up to
		// third parties via the leastudios_email_templates_register_types
		// action. Third parties must register their callback at file scope
		// in their own plugin (i.e. before plugins_loaded:10 fires) for
		// their types to appear in the admin UI on first render.
		$registry = new Email_Type_Registry();
		$registry->register( new Payment_Receipt() );
		$registry->register( new Subscription_Created() );
		$registry->register( new Subscription_Renewed() );
		$registry->register( new Payment_Failed() );
		$registry->register( new Refund_Processed() );

		/**
		 * Fires once during Plugin::init, after built-in email types are
		 * registered. Third-party plugins call $registry->register() to
		 * add their own Email_Type_Definition implementations.
		 *
		 * Hook this at file scope in your own plugin (i.e. before
		 * plugins_loaded:10 fires) so your callback is queued in time
		 * to receive the registry. Registrations made after Plugin::init
		 * has completed are still accepted by the registry but will not
		 * appear in admin UI surfaces that render once per page load.
		 *
		 * @param Email_Type_Registry $registry The registry to mutate.
		 */
		do_action( 'leastudios_email_templates_register_types', $registry );

		// Email sender for transactional emails.
		$sender = new Email_Sender( $replacer, $registry, $unsubscribe );

		// Register WP-CLI commands. Guarded so the class is only required when
		// the CLI is actually running, keeping the autoload graph quiet for
		// web requests.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$cli_commands = new Commands( $registry, $sender, $replacer, $unsubscribe );
			\WP_CLI::add_command( 'leastudios-email-templates list-types', [ $cli_commands, 'list_types' ] );
			\WP_CLI::add_command( 'leastudios-email-templates preview', [ $cli_commands, 'preview' ] );
			\WP_CLI::add_command( 'leastudios-email-templates send-test', [ $cli_commands, 'send_test' ] );
			\WP_CLI::add_command( 'leastudios-email-templates list-suppressions', [ $cli_commands, 'list_suppressions' ] );
			\WP_CLI::add_command( 'leastudios-email-templates add-suppression', [ $cli_commands, 'add_suppression' ] );
			\WP_CLI::add_command( 'leastudios-email-templates remove-suppression', [ $cli_commands, 'remove_suppression' ] );
		}

		// Payment integration (only when payments plugin is active).
		if ( $this->is_payments_active() ) {
			$resolver = new Payment_Data_Resolver();
			$listener = new Payment_Email_Listener( $sender, $resolver );
			$listener->init();
		}

		// Admin settings.
		if ( is_admin() ) {
			$settings = new Settings_Page( $registry );
			$settings->init();

			$log_page = new Email_Log_Page( $log_repo, $registry );
			$log_page->init();

			$suppressions_page = new Suppressions_Page( $unsubscribe, $suppression_repo );
			$suppressions_page->init();
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
