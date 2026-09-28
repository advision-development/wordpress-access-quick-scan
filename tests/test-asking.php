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

/*
 * Before wp-stubs, whose update_option() returns true and stores nothing. A stub that forgets
 * cannot show the version being recorded before the read, which is the thing under test.
 */
$GLOBALS['options'] = array();

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['options'][ $name ] = $value;

		return true;
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['options'] ) ? $GLOBALS['options'][ $name ] : $default;
	}
}

/**
 * Stands in for the fleet transport so the report can be made to die where a host would kill
 * it, and so reaching it at all is observable. The message is checked rather than the fact of
 * a throw: in the sibling an identical test passed for a while on a *different* missing class,
 * because a Throwable is a Throwable.
 */
class WPAQS_Fleet {

	/** @var bool */
	public static $joined = true;

	/** @var bool */
	public static $reported = false;

	/** @var bool */
	public static $explode = false;

	public static function enrolled() {
		self::$reported = true;

		if ( self::$explode ) {
			throw new RuntimeException( 'the host killed the process mid-report' );
		}

		return self::$joined;
	}
}

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

/*
 * One update, one report.
 *
 * The same decision as the asking stamp above, against the plugin's own version rather than a
 * console press, and it matters for the same reason: when the rules change, the stored report
 * is stale in a way nothing on the site caused. Getting it wrong produces the same fault —
 * a report on every request rather than once.
 */
check( 'a changed version reports', true === WPAQS_Cron::update_is_new( '0.18.0', '0.19.0' ) );
check( 'the same version does not report again', false === WPAQS_Cron::update_is_new( '0.19.0', '0.19.0' ) );
check( 'and does not report a third time', false === WPAQS_Cron::update_is_new( '0.19.0', '0.19.0' ) );

// A fresh install has nothing stored, and enrolment already reports.
check( 'a fresh install does not report twice', false === WPAQS_Cron::update_is_new( '', '0.19.0' ) );

// A press has an order; a build does not, and a rollback moves the rules just as much.
check( 'a rollback is a change too', true === WPAQS_Cron::update_is_new( '0.19.0', '0.18.0' ) );

/*
 * The ordering, asserted rather than asserted-about-in-a-comment. Driven by making the read
 * throw where a timeout would land: with the write before it the version survives, with it
 * after the marker is lost and the site tries again on every request.
 */
$GLOBALS['options']   = array( 'wpaqs_version_seen' => '0.0.9' );
WPAQS_Fleet::$explode = true;
$caught               = '';

try {
	WPAQS_Cron::report_after_update();
} catch ( Throwable $e ) {
	$caught = $e->getMessage();
}

check( 'the report was actually reached', 'the host killed the process mid-report' === $caught, '' === $caught ? '(nothing threw)' : $caught );
check(
	'a report that dies still leaves the version recorded',
	'0.1.0' === get_option( 'wpaqs_version_seen' ),
	var_export( get_option( 'wpaqs_version_seen' ), true )
);

WPAQS_Fleet::$explode = false;
check( 'so it does not run again after dying', false === WPAQS_Cron::update_is_new( get_option( 'wpaqs_version_seen' ), WPAQS_VERSION ) );

// And an unchanged version records nothing new and reads nothing.
$GLOBALS['options']    = array( 'wpaqs_version_seen' => WPAQS_VERSION );
WPAQS_Fleet::$reported = false;

WPAQS_Cron::report_after_update();

check( 'an unchanged version does not report', false === WPAQS_Fleet::$reported );

printf( "\n%d failure(s)\n", $failures );
exit( $failures > 0 ? 1 : 0 );
