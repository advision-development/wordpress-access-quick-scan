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

		if ( $key ) {
			echo '<p>' . esc_html__( 'This site is enrolled and reports after every scheduled scan.', 'wpaqs' ) . '</p>';

			if ( ! empty( $state['pushed_at'] ) ) {
				printf(
					'<p>%s</p>',
					esc_html(
						sprintf(
							/* translators: %s: how long ago the last report was sent. */
							__( 'Last report sent %s ago.', 'wpaqs' ),
							human_time_diff( (int) $state['pushed_at'] )
						)
					)
				);
			} else {
				// Enrolled and never having reported is its own state: the next scan will
				// send, and saying nothing here would read as a failure.
				echo '<p>' . esc_html__( 'Nothing has been sent yet. The next scheduled scan will report.', 'wpaqs' ) . '</p>';
			}
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
