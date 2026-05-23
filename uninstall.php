<?php
/**
 * Uninstall handler — runs when the plugin is deleted via WP admin.
 *
 * @package LEAStudios\EmailTemplates
 */

// Exit if not called by WordPress.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'leastudios_email_templates_branding' );
delete_option( 'leastudios_email_templates_emails' );
delete_option( 'leastudios_email_templates_unsubscribe_secret' );

// Drop custom tables if the autoloader is reachable.
// Suppression_Repository::drop() also deletes its schema-version option,
// matching Email_Log_Repository's behavior.
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
	( new \LEAStudios\EmailTemplates\Database\Email_Log_Repository() )->drop();
	( new \LEAStudios\EmailTemplates\Database\Suppression_Repository() )->drop();
}

// Unschedule the prune cron in case deactivation didn't run.
$leastudios_email_templates_cron_ts = wp_next_scheduled( 'leastudios_email_templates_log_prune' );
if ( false !== $leastudios_email_templates_cron_ts ) {
	wp_unschedule_event( $leastudios_email_templates_cron_ts, 'leastudios_email_templates_log_prune' );
}
unset( $leastudios_email_templates_cron_ts );
