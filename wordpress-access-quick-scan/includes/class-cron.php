<?php
/**
 * The only scheduled work this plugin does.
 *
 * There was none until the fleet console existed, and the README said so: somebody
 * opens the screen. That is still true for a single site — this exists because 162 of
 * them cannot be opened one at a time, and a console that waited for somebody to visit
 * each site would have nothing to show.
 *
 * It reports and nothing else. No scan runs here, because there is no scan: this plugin
 * reads live state, so gathering *is* the read.
 *
 * @package WPAQS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Daily: enrol or ask about enrolment, then report.
 */
class WPAQS_Cron {

	/** The scheduled event. */
	const HOOK = 'wpaqs_daily_report';

	/**
	 * Register the handler.
	 *
	 * Unconditional, because cron requests are neither admin nor front end.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
	}

	/**
	 * Put the event on the schedule.
	 *
	 * In UTC, deliberately, for the reason the sibling records: a site-local hour moves
	 * whenever somebody edits the timezone setting, and a fleet then reports at
	 * unrelated times.
	 *
	 * @return void
	 */
	public static function schedule() {
		if ( wp_next_scheduled( self::HOOK ) ) {
			return;
		}

		wp_schedule_event( self::next_run(), 'daily', self::HOOK );
	}

	/**
	 * Take it off the schedule.
	 *
	 * @return void
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * The next 04:00 UTC.
	 *
	 * @return int
	 */
	public static function next_run() {
		$now  = time();
		$next = gmmktime( 4, 0, 0, (int) gmdate( 'n', $now ), (int) gmdate( 'j', $now ), (int) gmdate( 'Y', $now ) );

		return $next > $now ? $next : $next + DAY_IN_SECONDS;
	}

	/**
	 * Enrol or poll, then report.
	 *
	 * @return void
	 */
	public static function run() {
		self::keep_up_with_fleet();

		if ( ! class_exists( 'WPAQS_Fleet' ) || ! WPAQS_Fleet::enrolled() ) {
			return;
		}

		$gathered = WPAQS_Report::gather();

		// Derived from the day rather than generated, so a cron that fires twice — which
		// WP-Cron does on a busy site — is one report to the console instead of two
		// readings that never both happened.
		WPAQS_Fleet::push( $gathered, gmdate( 'Y-m-d' ) );
	}

	/**
	 * Ask to enrol, or ask whether the request was approved yet.
	 *
	 * Enrolment waits on a person, so this is the site checking back rather than
	 * retrying something that failed.
	 *
	 * @return void
	 */
	private static function keep_up_with_fleet() {
		if ( ! class_exists( 'WPAQS_Fleet' ) || WPAQS_Fleet::enrolled() ) {
			return;
		}

		$state = WPAQS_Fleet::state();

		if ( empty( $state['requested_at'] ) ) {
			WPAQS_Fleet::enrol();

			return;
		}

		WPAQS_Fleet::poll();
	}
}
