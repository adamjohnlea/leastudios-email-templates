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

// Minimal WP_CLI stub for tests that exercise the CLI Commands class.
// Records calls into static arrays so tests can assert on output without
// requiring the real WP-CLI binary to be present at test time.
if ( ! class_exists( 'WP_CLI' ) ) {
	eval( <<<'PHP'
		class WP_CLI {
			public static array $log_calls = [];
			public static array $success_calls = [];
			public static array $warning_calls = [];
			public static array $error_calls = [];
			public static ?string $last_error = null;

			public static function log( string $message ): void {
				self::$log_calls[] = $message;
			}
			public static function success( string $message ): void {
				self::$success_calls[] = $message;
			}
			public static function warning( string $message ): void {
				self::$warning_calls[] = $message;
			}
			public static function error( string $message, bool $exit = true ): void {
				self::$error_calls[] = $message;
				self::$last_error = $message;
				throw new \RuntimeException( 'WP_CLI::error: ' . $message );
			}
			public static function add_command( string $name, $callable ): void {
				// No-op for tests.
			}
			public static function reset(): void {
				self::$log_calls = [];
				self::$success_calls = [];
				self::$warning_calls = [];
				self::$error_calls = [];
				self::$last_error = null;
			}
		}
PHP
	);
}

// Minimal WP_CLI\Utils\format_items stub. The real implementation lives in
// the wp-cli/wp-cli package which is not a Composer dep here. We mirror the
// public signature: format_items(string $format, array $items, array|string $fields)
// and emit one of table|csv|json|yaml. Tests assert on the printed output.
if ( ! function_exists( 'WP_CLI\\Utils\\format_items' ) ) {
	eval( <<<'PHP'
		namespace WP_CLI\Utils;
		function format_items( string $format, array $items, $fields ): void {
			$field_list = is_array( $fields ) ? $fields : array_map( 'trim', explode( ',', (string) $fields ) );
			if ( 'json' === $format ) {
				\WP_CLI::log( (string) wp_json_encode( $items ) );
				return;
			}
			if ( 'csv' === $format ) {
				\WP_CLI::log( implode( ',', $field_list ) );
				foreach ( $items as $item ) {
					$row = [];
					foreach ( $field_list as $f ) {
						$row[] = (string) ( $item[ $f ] ?? '' );
					}
					\WP_CLI::log( implode( ',', $row ) );
				}
				return;
			}
			// Default to a plain "field: value" line per row for table-like output.
			foreach ( $items as $item ) {
				$parts = [];
				foreach ( $field_list as $f ) {
					$parts[] = $f . '=' . ( $item[ $f ] ?? '' );
				}
				\WP_CLI::log( implode( ' ', $parts ) );
			}
		}
PHP
	);
}

require_once __DIR__ . '/TestCase.php';
