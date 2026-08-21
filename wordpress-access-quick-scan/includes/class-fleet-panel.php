<?php
/**
 * The one place a person can see whether this site reached the fleet console.
 *
 * Copied verbatim into the sibling, so it must stay byte-identical except for the
 * prefix and derives every name it uses. See class-fleet.php for why.
 *
 * It exists because the alternative was worse: enrolment happens on the daily run, and
 * a site that had asked, been refused, and would ask again tomorrow looked exactly like
 * a site that had never tried. Four states that read the same is the fault this project
 * keeps finding in other people's screens.
 *
 * @package WPAQS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fleet status, and the two buttons that move it along.
 */
class WPAQS_Fleet_Panel {

	/**
	 * Register the endpoints the buttons post to.
	 *
	 * @return void
	 */
	public static function register() {
		$prefix = self::prefix();

		add_action( 'admin_post_' . $prefix . '_fleet_enrol', array( __CLASS__, 'handle_enrol' ) );
		add_action( 'admin_post_' . $prefix . '_fleet_poll', array( __CLASS__, 'handle_poll' ) );
		add_action( 'admin_post_' . $prefix . '_fleet_send', array( __CLASS__, 'handle_send' ) );
	}

	/**
	 * The lower-case prefix, derived so the shared copy stays byte-identical.
	 *
	 * @return string
	 */
	private static function prefix() {
		return strtolower( substr( __CLASS__, 0, strpos( __CLASS__, '_' ) ) );
	}

	/**
	 * The fleet class for whichever plugin this is.
	 *
	 * @return string
	 */
	private static function fleet() {
		return strtoupper( self::prefix() ) . '_Fleet';
	}

	/**
	 * Ask to be enrolled, now rather than on the next daily run.
	 *
	 * @return void
	 */
	public static function handle_enrol() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'wpaqs' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::prefix() . '-fleet-enrol' );

		$result = call_user_func( array( self::fleet(), 'enrol' ) );

		self::back( '' === $result['error'] ? 'fleet-asked' : 'fleet-failed', $result['error'] );
	}

	/**
	 * Ask whether the request was approved yet.
	 *
	 * @return void
	 */
	public static function handle_poll() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'wpaqs' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::prefix() . '-fleet-poll' );

		// Pressed by a person, so the interval that stops the daily run hammering the
		// console does not apply: somebody watching a screen is allowed to ask again.
		$state = call_user_func( array( self::fleet(), 'state' ) );

		unset( $state['polled_at'] );
		update_option( constant( self::fleet() . '::OPTION' ), $state, false );

		$result = call_user_func( array( self::fleet(), 'poll' ) );

		self::back( '' === $result['error'] ? 'fleet-checked' : 'fleet-failed', $result['error'] );
	}

	/**
	 * Send a report now, without waiting for the schedule.
	 *
	 * Calls the cron class's own method rather than assembling anything: each plugin
	 * reports a different thing — one a finished scan, the other a live read — and the
	 * shared panel must not know which it is in.
	 *
	 * @return void
	 */
	public static function handle_send() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'wpaqs' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::prefix() . '-fleet-send' );

		call_user_func( array( strtoupper( self::prefix() ) . '_Cron', 'report_to_fleet_if_enrolled' ) );

		$state = call_user_func( array( self::fleet(), 'state' ) );

		self::back(
			empty( $state['last_error'] ) ? 'fleet-sent' : 'fleet-failed',
			isset( $state['last_error'] ) ? (string) $state['last_error'] : ''
		);
	}

	/**
	 * Back to the report with a notice.
	 *
	 * @param string $notice Notice key.
	 * @param string $why    Reason, when there is one.
	 * @return void
	 */
	private static function back( $notice, $why ) {
		$page = strtoupper( self::prefix() ) . '_Admin_Page';
		$args = array( self::prefix() . '-notice' => $notice );

		if ( '' !== $why ) {
			$args[ self::prefix() . '-why' ] = rawurlencode( $why );
		}

		wp_safe_redirect(
			add_query_arg( $args, admin_url( 'tools.php?page=' . constant( $page . '::SLUG' ) ) ) . '#' . self::prefix() . '-fleet'
		);

		exit;
	}

	/**
	 * The panel.
	 *
	 * States are named rather than inferred from an empty value, because "never asked",
	 * "asked and waiting", "refused" and "enrolled" are four different things a person
	 * does four different things about — and a screen that showed one blank line for all
	 * of them would be the fault this plugin exists to report.
	 *
	 * @return void
	 */
	public static function render() {
		$fleet  = self::fleet();
		$prefix = self::prefix();
		$state  = call_user_func( array( $fleet, 'state' ) );
		$key    = call_user_func( array( $fleet, 'enrolled' ) );

		echo '<div class="wpaqs-card" id="' . esc_attr( $prefix ) . '-fleet">';
		echo '<h2>' . esc_html__( 'Fleet console', 'wpaqs' ) . '</h2>';

		// Rendered here rather than handed to the screen's own notice system: the two
		// plugins render notices differently, and a shared panel whose result appeared
		// in one and vanished in the other would be a button that looks broken in
		// exactly one place — the hardest kind to notice.
		self::notice();

		if ( $key ) {
			echo '<p>' . esc_html__( 'This site is enrolled and reports after every scheduled scan.', 'wpaqs' ) . '</p>';

			if ( ! empty( $state['pushed_at'] ) ) {
				$sent     = (int) $state['pushed_at'];
				$describes = isset( $state['pushed_finished'] ) ? (int) $state['pushed_finished'] : 0;

				// Names what was sent rather than only when. The two lines this screen
				// already carried — "sent 13 hours ago" and "last completed scan 6:02
				// am" — are one moment rendered twice, relative in one place and
				// absolute in the other, and a reader has to do arithmetic across two
				// formats to find that out. Reported as "the job ran but did not send",
				// which is the correct thing to conclude from what it said.
				if ( $describes > 0 ) {
					printf(
						'<p>%s</p>',
						esc_html(
							sprintf(
								/* translators: 1: how long ago the report was sent. 2: when the scan it described finished. */
								__( 'Last report sent %1$s ago — the scan that finished %2$s.', 'wpaqs' ),
								human_time_diff( $sent ),
								self::site_time( $describes )
							)
						)
					);
				} else {
					// Nothing stored to describe: this plugin reads live state, so the
					// read and the send are one act and there is no scan to name.
					printf(
						'<p>%s</p>',
						esc_html(
							sprintf(
								/* translators: %s: how long ago the last report was sent. */
								__( 'Last report sent %s ago — a live read of the site at that moment.', 'wpaqs' ),
								human_time_diff( $sent )
							)
						)
					);
				}

				self::unsent( $describes );
			} else {
				// Enrolled and never having reported is its own state: the next scan will
				// send, and saying nothing here would read as a failure.
				echo '<p>' . esc_html__( 'Nothing has been sent yet. The next scheduled run will report, or send one now.', 'wpaqs' ) . '</p>';
			}

			self::button( $prefix . '_fleet_send', $prefix . '-fleet-send', __( 'Send a report now', 'wpaqs' ) );
		} elseif ( empty( $state['requested_at'] ) ) {
			echo '<p>' . esc_html__( 'This site has not asked to join a fleet console. Asking does not send anything about the site — somebody has to approve it there first, and only then is this site contacted.', 'wpaqs' ) . '</p>';
			self::button( $prefix . '_fleet_enrol', $prefix . '-fleet-enrol', __( 'Ask to enrol', 'wpaqs' ) );
		} else {
			echo '<p>' . esc_html__( 'This site has asked to enrol and is waiting for somebody to approve it in the console. It asks again on every scheduled scan.', 'wpaqs' ) . '</p>';
			self::button( $prefix . '_fleet_poll', $prefix . '-fleet-poll', __( 'Check now', 'wpaqs' ) );
		}

		if ( ! empty( $state['last_error'] ) ) {
			// Kept visible after success too. A site that is enrolled but whose last push
			// failed is not a site with nothing to say.
			printf(
				'<p class="wpaqs-muted">%s</p>',
				esc_html(
					sprintf(
						/* translators: %s: the reason the last attempt gave. */
						__( 'Last attempt reported: %s', 'wpaqs' ),
						$state['last_error']
					)
				)
			);
		}

		echo '</div>';
	}

	/**
	 * What just happened, if anything did.
	 *
	 * Read from the query string this class put there. A key the screen does not
	 * recognise renders nothing, and a button that renders nothing is indistinguishable
	 * from one that did nothing — which is the fault this plugin exists to report, not
	 * to commit.
	 *
	 * @return void
	 */
	private static function notice() {
		$prefix = self::prefix();
		$key    = isset( $_GET[ $prefix . '-notice' ] ) ? sanitize_key( wp_unslash( $_GET[ $prefix . '-notice' ] ) ) : '';

		if ( 0 !== strpos( $key, 'fleet-' ) ) {
			return;
		}

		$why = isset( $_GET[ $prefix . '-why' ] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET[ $prefix . '-why' ] ) ) ) : '';

		$messages = array(
			'fleet-asked'   => __( 'Asked to enrol. Nothing about this site has been sent — somebody has to approve it in the console, and only then is this site contacted.', 'wpaqs' ),
			'fleet-checked' => __( 'Asked the console. If it had been approved, the key is now stored and this panel says so.', 'wpaqs' ),
			'fleet-sent'    => __( 'Report sent.', 'wpaqs' ),
			'fleet-failed'  => __( 'That did not work.', 'wpaqs' ),
		);

		if ( ! isset( $messages[ $key ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%s inline"><p>%s%s</p></div>',
			'fleet-failed' === $key ? 'error' : 'success',
			esc_html( $messages[ $key ] ),
			'' === $why ? '' : ' ' . esc_html( $why )
		);
	}

	/**
	 * A scan the console has not been given.
	 *
	 * The state the reader was actually worried about, and the one the screen had no
	 * words for: a scan finished, its push failed, and the panel went on saying when the
	 * *previous* report was sent. Nothing distinguished that from a site up to date.
	 *
	 * It resolves itself — `pushed_at` records only a send that worked, so the hourly
	 * fleet check retries — and saying so is the difference between a screen reporting a
	 * problem and a screen reporting a problem somebody has to act on.
	 *
	 * @param int $described When the scan the last report described finished.
	 * @return void
	 */
	private static function unsent( $described ) {
		$cron = strtoupper( self::prefix() ) . '_Cron';

		if ( ! is_callable( array( $cron, 'last_report_finished' ) ) ) {
			return;
		}

		$latest = (int) call_user_func( array( $cron, 'last_report_finished' ) );

		if ( $latest <= $described ) {
			return;
		}

		printf(
			'<div class="notice notice-warning inline"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: when the unsent scan finished. */
					__( 'The scan that finished %s has not reached the console. The hourly fleet check will try again, or send one now.', 'wpaqs' ),
					self::site_time( $latest )
				)
			)
		);
	}

	/**
	 * A stored UTC timestamp as the site's own clock shows it.
	 *
	 * Matched to the rest of the screen deliberately. This panel sits beside a card
	 * printing "Last completed scan: August 21, 2026 6:02 am" in site time, and the same
	 * moment in two zones is the fault this change exists to remove, not to move.
	 *
	 * @param int $timestamp UTC timestamp.
	 * @return string
	 */
	private static function site_time( $timestamp ) {
		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}

	/**
	 * One posting form.
	 *
	 * Its own form, never nested: HTML terminates an outer form at an inner one, which
	 * is what once left a bulk action holding a single item.
	 *
	 * @param string $action Admin-post action.
	 * @param string $nonce  Nonce action.
	 * @param string $label  Button text.
	 * @return void
	 */
	private static function button( $action, $nonce, $label ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />';
		wp_nonce_field( $nonce );
		echo '<button type="submit" class="button button-primary">' . esc_html( $label ) . '</button>';
		echo '</form>';
	}
}
