<?php
/**
 * PHPUnit bootstrap for leaStudios Email Templates.
 *
 * @package LEAStudios\EmailTemplates
 */

declare(strict_types=1);

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( "{$_tests_dir}/includes/functions.php" ) ) {
	echo "Could not find {$_tests_dir}/includes/functions.php\n";
	echo "Run: bash ../leastudios-dev-tools/bin/install-wp-tests.sh wordpress_test root '' localhost latest\n";
	exit( 1 );
}

require_once "{$_tests_dir}/includes/functions.php";

tests_add_filter(
	'muplugins_loaded',
	function () {
		require __DIR__ . '/../leastudios-email-templates.php';
	}
);

require "{$_tests_dir}/includes/bootstrap.php";

// Install the plugin's custom tables so tests that don't explicitly call
// install() (e.g. EmailSenderTest, which triggers the email_sent action
// and therefore the Send_Logger subscriber) don't blow up on missing
// schema. Safe to call repeatedly — install() is idempotent.
( new \LEAStudios\EmailTemplates\Database\Email_Log_Repository() )->install();

require_once __DIR__ . '/TestCase.php';
