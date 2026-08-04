<?php
/**
 * Shared harness bootstrap.
 *
 * Every suite defines the plugin's constants, pulls in the WordPress stubs, and gets the
 * same `check()`. What each suite does *not* share is its WordPress stubs beyond
 * wp-stubs.php: a suite that needs a richer `get_users()` declares its own before
 * requiring this, which is the arrangement wp-stubs.php's `function_exists` guards exist
 * for.
 *
 * @package WPAQS
 */

define( 'ABSPATH', sys_get_temp_dir() . '/wpaqs-test/' );
define( 'WPAQS_VERSION', '0.1.0' );
define( 'WPAQS_DIR', dirname( __DIR__ ) . '/wordpress-access-quick-scan/' );
define( 'WPAQS_URL', 'https://example.test/wp-content/plugins/wordpress-access-quick-scan/' );
define( 'WPAQS_SLUG', 'wordpress-access-quick-scan' );
define( 'WPAQS_NONCE', 'wpaqs_access' );
define( 'WPAQS_MAX_USERS', 500 );
define( 'WPAQS_RECENT_DAYS', 30 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

require __DIR__ . '/wp-stubs.php';

$GLOBALS['wpaqs_failures'] = 0;

/**
 * Assert, and print the result either way.
 *
 * @param string $label  What is being checked.
 * @param bool   $ok     Whether it holds.
 * @param string $detail Shown after the label, whatever the outcome.
 * @return void
 */
function check( $label, $ok, $detail = '' ) {
	if ( ! $ok ) {
		$GLOBALS['wpaqs_failures']++;
	}

	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, '' === $detail ? '' : ' — ' . $detail );
}

/**
 * Print the tally and exit with a status the runner can read.
 *
 * @return void
 */
function finish() {
	printf( "\n%d failure(s)\n", $GLOBALS['wpaqs_failures'] );

	exit( $GLOBALS['wpaqs_failures'] > 0 ? 1 : 0 );
}

/**
 * Load one plugin class by name.
 *
 * @param string $name Class name without the prefix, lower case with hyphens.
 * @return void
 */
function load_class( $name ) {
	require_once WPAQS_DIR . 'includes/class-' . $name . '.php';
}
