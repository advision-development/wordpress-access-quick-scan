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

	/** The daily report. */
	const HOOK = 'wpaqs_daily_report';

	/** Enrolment, which waits on a person and so asks more often. */
	const FLEET_HOOK = 'wpaqs_fleet_check';

	/**
	 * Register the handler.
	 *
	 * Unconditional, because cron requests are neither admin nor front end.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( self::FLEET_HOOK, array( __CLASS__, 'keep_up_with_fleet' ) );

		/*
		 * On every load, not only on activation — and that is the fix for a real fault.
		 *
		 * WordPress does not run the activation hook when a plugin is *updated*. A site
		 * that already had this plugin active and received the fleet version by update
		 * therefore never scheduled these events: it never asked to enrol, never reported,
		 * and its own panel said it had not asked to join a fleet. Meanwhile the console
		 * showed nothing at all, which is indistinguishable from the plugin never having
		 * been installed — the exact confusion this project exists to remove.
		 *
		 * Cheap enough to do unconditionally: wp_next_scheduled() reads the cron option,
		 * which WordPress has already loaded.
		 */
		add_action( 'init', array( __CLASS__, 'schedule' ) );
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

		// Hourly, and separate from the report. Enrolment waits on a person, and asking
		// once a day means a site approved five minutes after its daily run waits most of
		// another day to find out — which reads as approving having not worked.
		if ( ! wp_next_scheduled( self::FLEET_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::FLEET_HOOK );
		}
	}

	/**
	 * Take it off the schedule.
	 *
	 * @return void
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
		wp_clear_scheduled_hook( self::FLEET_HOOK );
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

		// Derived from the hour rather than generated, so a cron that fires twice — which
		// WP-Cron does on a busy site — is one report to the console instead of two
		// readings that never both happened.
		self::report_to_fleet_if_enrolled();
	}

	/**
	 * Send a report now, if this site is enrolled.
	 *
	 * Named to match the sibling's so the shared panel can call it without knowing which
	 * plugin it is in. There is no scan to wait for here — reading live state *is* the
	 * read — so this is the whole of what the daily event does.
	 *
	 * @return void
	 */
	public static function report_to_fleet_if_enrolled() {
		if ( ! class_exists( 'WPAQS_Fleet' ) || ! WPAQS_Fleet::enrolled() ) {
			return;
		}

		WPAQS_Fleet::push( WPAQS_Report::gather(), gmdate( 'Y-m-d-H' ) );
	}

	/**
	 * Always 0: there is no stored report here, and that is not a gap.
	 *
	 * The shared fleet panel uses this to warn about a scan the console has not been
	 * given. This plugin reads live state, so the read and the send are one act — there
	 * is never a finished report sitting unsent, and answering with anything else would
	 * make the panel warn about a scan that does not exist.
	 *
	 * @return int
	 */
	public static function last_report_finished() {
		return 0;
	}

	/**
	 * Ask to enrol, or ask whether the request was approved yet.
	 *
	 * Enrolment waits on a person, so this is the site checking back rather than
	 * retrying something that failed.
	 *
	 * @return void
	 */
	public static function keep_up_with_fleet() {
		if ( ! class_exists( 'WPAQS_Fleet' ) ) {
			return;
		}

		// Enrolled and never having reported: the site was approved after its daily run,
		// and waiting for the next one would leave the console showing a site it has
		// heard nothing from.
		if ( WPAQS_Fleet::enrolled() ) {
			$state = WPAQS_Fleet::state();

			if ( empty( $state['pushed_at'] ) ) {
				self::report_to_fleet_if_enrolled();
			}

			return;
		}

		$state = WPAQS_Fleet::state();

		if ( empty( $state['requested_at'] ) ) {
			WPAQS_Fleet::enrol();

			return;
		}

		WPAQS_Fleet::poll();

		// Approved just now: report immediately rather than an hour from now. There is
		// no scan to start here — reading live state *is* the read — so the handshake and
		// the first report are one step. The sibling has to run a scan at this point,
		// which is the only reason it does more than this.
		if ( WPAQS_Fleet::enrolled() ) {
			self::report_to_fleet_if_enrolled();
		}
	}
}
