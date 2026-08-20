<?php
/**
 * Uninstall cleanup.
 *
 * Little to clean, and less than there used to be.
 *
 * Every screen still reads the database live and throws the result away when the request
 * ends. What changed is the fleet console: a site that reports to it keeps a key, the nonce
 * that identifies this install, and when it last managed to send — and it schedules one
 * event to do the sending. Those are named below, which is what the last line of this
 * docblock has always asked for.
 *
 * The one exception is the update check. `WPAQS_Updater` caches GitHub's answer about the
 * latest release, including the answer "that failed" — because GitHub allows 60
 * unauthenticated requests an hour per IP and a hosting provider's sites share one, so
 * retrying on every admin page load is how one site being rate-limited becomes every site on
 * that host being rate-limited.
 *
 * It is a site transient, so it is deleted with the site-transient function rather than the
 * plain one: on multisite the plain one looks in the wrong place and leaves the row behind.
 *
 * The moment anything else is written, its name belongs here.
 *
 * @package WPAQS
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) || ! defined( 'ABSPATH' ) ) {
	exit;
}

delete_site_transient( 'wpaqs_release' );

// The key, the install nonce, and when the last report went. Deleting the key is the
// point: an uninstalled plugin that left one behind would leave a credential on a site
// nobody is watching any more.
delete_option( 'wpaqs_fleet' );

// The only event this plugin ever schedules.
wp_clear_scheduled_hook( 'wpaqs_daily_report' );
wp_clear_scheduled_hook( 'wpaqs_fleet_check' );
