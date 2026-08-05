<?php
/**
 * Uninstall cleanup.
 *
 * Almost nothing to clean. Every screen reads the database live and throws the result away
 * when the request ends, so this plugin stores no options and schedules no events.
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
