<?php
/**
 * Plugin Name:       WordPress Access Quick Scan
 * Plugin URI:        https://advisiondevelopment.com/
 * Description:       Answers one question: who has access to this site right now, and does any of it look wrong. Lists every account with the capabilities it actually holds, every live session with its IP and user agent, and every application password with when and where it was last used. Reading the screen changes nothing. Six actions do, and each has to be pressed by a person against something confirmed to exist at that moment: end one session or all of an account's, revoke an application password, take a directly granted capability off an account, and the two ways to stop open registration handing out a privileged role. None of them deletes an account or anything it created.
 * Version:           0.8.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Advision Development
 * License:           GPL-2.0-or-later
 * Text Domain:       wpaqs
 *
 * @package WPAQS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPAQS_VERSION', '0.8.0' );
define( 'WPAQS_FILE', __FILE__ );
define( 'WPAQS_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPAQS_URL', plugin_dir_url( __FILE__ ) );
define( 'WPAQS_SLUG', 'wordpress-access-quick-scan' );

/** Shared nonce action prefix. */
define( 'WPAQS_NONCE', 'wpaqs_access' );

/**
 * How many accounts the screen reads.
 *
 * A cap, because a membership site can hold a hundred thousand users and this screen
 * loads them in one request. When it is reached the screen says so and names the number:
 * a cap that truncates quietly turns "nothing found" into a sentence that sounds complete.
 */
define( 'WPAQS_MAX_USERS', 500 );

/** An administrator registered inside this many days is worth pointing at. */
define( 'WPAQS_RECENT_DAYS', 30 );

/**
 * Autoload by class name.
 *
 * WPAQS_App_Passwords -> includes/class-app-passwords.php
 */
spl_autoload_register(
	function ( $class ) {
		if ( 0 !== strpos( $class, 'WPAQS_' ) ) {
			return;
		}

		$name = strtolower( str_replace( '_', '-', substr( $class, strlen( 'WPAQS_' ) ) ) );
		$path = WPAQS_DIR . 'includes/class-' . $name . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

// Cron requests are neither admin nor front end, and REST is neither either.
WPAQS_Cron::register();
WPAQS_Fleet_Verify::register();

add_action( 'admin_menu', array( 'WPAQS_Admin_Page', 'add_menu' ) );
add_action( 'admin_enqueue_scripts', array( 'WPAQS_Admin_Page', 'enqueue' ) );
add_action( 'admin_init', array( 'WPAQS_Controller', 'register' ) );

// The plugin is not on wordpress.org, so without this the Plugins screen shows no update
// however many releases are published. See class-updater.php: it is the only thing here that
// hands WordPress a URL to download and run.
WPAQS_Updater::register();

/**
 * Activation: put the daily report on the schedule.
 *
 * Creates no tables and touches nothing the site already has. The only scheduled event
 * registered is this plugin's own.
 */
function wpaqs_activate() {
	WPAQS_Cron::schedule();
}
register_activation_hook( __FILE__, 'wpaqs_activate' );

/**
 * Deactivation: take it off again, and nothing else.
 */
function wpaqs_deactivate() {
	WPAQS_Cron::unschedule();
}
register_deactivation_hook( __FILE__, 'wpaqs_deactivate' );
