<?php
/**
 * One asking, one run.
 *
 * The console sends a stamp rather than a flag, and this is the comparison that turns a
 * standing request into exactly one report. Without it every site in the fleet walks its own
 * files on every hourly check until the request ages out — which is the difference between
 * asking for a report and asking for six hours of them, multiplied by 165.
 *
 * It lives in its own file because class-cron.php has none, and this is the one decision in
 * it worth holding still: the rest of that class is scheduling, which needs WordPress.
 *
 * @package WPAQS
 */

define( 'ABSPATH', '/tmp/fake-wp/' );
define( 'WPAQS_VERSION', '0.1.0' );

require __DIR__ . '/wp-stubs.php';
require __DIR__ . '/../wordpress-access-quick-scan/includes/class-cron.php';

$failures = 0;

function check( $label, $ok, $detail = '' ) {
	global $failures;

	if ( ! $ok ) {
		$failures++;
	}

	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, '' === $detail ? '' : ' — ' . $detail );
}

$now = 1790000000000;

check( 'a first request runs', true === WPAQS_Cron::asking_is_new( $now, 0 ) );

/*
 * The one that matters. A site asks hourly and the request stands for six hours, so without
 * this the same press produces six runs on every site that heard it.
 */
check( 'the same request does not run twice', false === WPAQS_Cron::asking_is_new( $now, $now ) );
check( 'and does not run a third time', false === WPAQS_Cron::asking_is_new( $now, $now ) );

// A later press is a second request, and should produce a second run.
check( 'a newer request runs again', true === WPAQS_Cron::asking_is_new( $now + 1000, $now ) );

/*
 * An older stamp than the one already answered. Nothing should produce this — the console
 * only ever sends the newest — but a clock moving backwards on either side would, and the
 * safe reading of "this is older than what I did" is that it is already done.
 */
check( 'an older request does not run', false === WPAQS_Cron::asking_is_new( $now - 1000, $now ) );

check( 'nothing asked is nothing run', false === WPAQS_Cron::asking_is_new( 0, 0 ) );
check( 'and a negative stamp is not an instruction', false === WPAQS_Cron::asking_is_new( -1, 0 ) );

printf( "\n%d failure(s)\n", $failures );
exit( $failures > 0 ? 1 : 0 );
