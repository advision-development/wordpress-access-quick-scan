<?php
/**
 * Plugin Name:       WordPress Access Quick Scan
 * Plugin URI:        https://advisiondevelopment.com/
 * Description:       Answers one question: who has access to this site right now, and does any of it look wrong. Lists every account with the capabilities it actually holds, every live session with its IP and user agent, and every application password with when and where it was last used. Reading the screen changes nothing. Two actions do, and each has to be pressed by a person: end an account's sessions, and revoke an application password.
 * Version:           0.1.0
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

define( 'WPAQS_VERSION', '0.1.0' );
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

add_action( 'admin_menu', array( 'WPAQS_Admin_Page', 'add_menu' ) );
add_action( 'admin_enqueue_scripts', array( 'WPAQS_Admin_Page', 'enqueue' ) );
add_action( 'admin_init', array( 'WPAQS_Controller', 'register' ) );
