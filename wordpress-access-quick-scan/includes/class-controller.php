<?php
/**
 * The actions, and the guards on them.
 *
 * Every one is pressed by a person and acts on something confirmed to exist at the moment of
 * pressing. There is no stored report to check a target against, and that is the stronger
 * arrangement rather than a shortcut: a report is a snapshot, and the sibling plugin shipped
 * a button that offered to unschedule an event already gone because the row it rendered from
 * was stale.
 *
 * Each one exists because a finding needs it. A finding nobody can resolve is noise however
 * true it is, so the question asked before adding a rule here is what clears it — and the
 * answer is one of these or an instruction the operator can follow.
 *
 * **Nothing here deletes an account or anything it created.** `wp_delete_user()` reassigns or
 * destroys the account's posts, and those posts are the record of what it did.
 *
 * @package WPAQS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the admin-post endpoints.
 */
class WPAQS_Controller {

	/**
	 * Hook the endpoints.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_post_wpaqs_end_sessions', array( __CLASS__, 'end_sessions' ) );
		add_action( 'admin_post_wpaqs_revoke_password', array( __CLASS__, 'revoke_password' ) );
		add_action( 'admin_post_wpaqs_end_session', array( __CLASS__, 'end_session' ) );
		add_action( 'admin_post_wpaqs_remove_capability', array( __CLASS__, 'remove_capability' ) );
		add_action( 'admin_post_wpaqs_park_default_role', array( __CLASS__, 'park_default_role' ) );
		add_action( 'admin_post_wpaqs_close_registration', array( __CLASS__, 'close_registration' ) );
	}

	/**
	 * End one session and leave the rest of the account's alone.
	 *
	 * @return void
	 */
	public static function end_session() {
		if ( ! self::user_can_act() ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'wpaqs' ), '', array( 'response' => 403 ) );
		}

		$user_id  = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		$verifier = isset( $_POST['verifier'] ) ? sanitize_text_field( wp_unslash( $_POST['verifier'] ) ) : '';

		check_admin_referer( WPAQS_NONCE . '-end-session-' . $user_id . '-' . $verifier );

		$result = WPAQS_Actions::end_session( $user_id, $verifier, get_current_user_id() );

		if ( $result['ok'] ) {
			self::redirect( 'session-ended' );
		}

		self::redirect( 'sessions-refused', $result['message'] );
	}

	/**
	 * Take a directly-granted capability off an account.
	 *
	 * @return void
	 */
	public static function remove_capability() {
		if ( ! self::user_can_act() ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'wpaqs' ), '', array( 'response' => 403 ) );
		}

		$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		$cap     = isset( $_POST['cap'] ) ? sanitize_key( wp_unslash( $_POST['cap'] ) ) : '';

		check_admin_referer( WPAQS_NONCE . '-remove-cap-' . $user_id . '-' . $cap );

		$result = WPAQS_Accounts::remove_direct_capability( $user_id, $cap, get_current_user_id() );

		if ( '' === $result['error'] ) {
			self::redirect( 'capability-removed', '', array( 'wpaqs-cap' => $cap ) );
		}

		self::redirect( 'capability-refused', $result['error'] );
	}

	/**
	 * Set the role new accounts receive to the one that can only read.
	 *
	 * @return void
	 */
	public static function park_default_role() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'wpaqs' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( WPAQS_NONCE . '-park-default-role' );

		$result = WPAQS_Registration::park_default_role();

		if ( '' === $result['error'] ) {
			self::redirect( 'default-role-parked' );
		}

		self::redirect( 'registration-refused', $result['error'] );
	}

	/**
	 * Close public registration on this site.
	 *
	 * @return void
	 */
	public static function close_registration() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'wpaqs' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( WPAQS_NONCE . '-close-registration' );

		$result = WPAQS_Registration::close();

		if ( '' === $result['error'] ) {
			self::redirect( 'registration-closed' );
		}

		self::redirect( 'registration-refused', $result['error'] );
	}

	/**
	 * Whether the current user may act on other people's access.
	 *
	 * On multisite, the network capability. Every subsite administrator holds
	 * `manage_options`, and a user is a network-wide object: ending sessions or revoking a
	 * password affects every site that account can reach, not just this one.
	 *
	 * @return bool
	 */
	public static function user_can_act() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		if ( is_multisite() ) {
			return current_user_can( 'manage_network_users' );
		}

		return true;
	}

	/**
	 * Why an account's sessions may not be ended, or '' when they may.
	 *
	 * Two of these guards are about who is asking, and a signed remote command runs under
	 * cron with nobody logged in. Passing the actor makes that explicit instead of leaving
	 * the guards to read a global that is 0 there: `self` would compare against user 0 and
	 * silently stop applying, and `nocap` would be true for everything and refuse the
	 * remote path outright. `$actor` is required, with no default, so every caller decides.
	 *
	 * @param int $user_id User id.
	 * @param int $actor   Acting user id, or 0 when no user is acting.
	 * @return string One of '', 'missing', 'self', 'nocap'.
	 */
	public static function session_refusal( $user_id, $actor ) {
		$user_id = (int) $user_id;
		$actor   = (int) $actor;

		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			return 'missing';
		}

		// Ending your own sessions signs you out of the screen you are working from, in the
		// middle of whatever brought you here. A command has no screen to lose, so with no
		// actor there is nothing here to protect.
		if ( 0 !== $actor && $actor === $user_id ) {
			return 'self';
		}

		// Asked of the actor rather than of whoever happens to be signed in. That coupling
		// is what breaks under cron, where current_user_can() answers false for everything.
		if ( 0 !== $actor && ! user_can( $actor, 'edit_user', $user_id ) ) {
			return 'nocap';
		}

		return '';
	}

	/**
	 * Wording for a refusal.
	 *
	 * @param string $reason Reason key.
	 * @return string
	 */
	public static function refusal_text( $reason ) {
		switch ( $reason ) {
			case 'missing':
				return __( 'That account no longer exists.', 'wpaqs' );
			case 'self':
				return __( 'That is the account you are signed in with. Ending its sessions would sign you out of this screen. Use another administrator account, or sign out and back in yourself.', 'wpaqs' );
			case 'nocap':
				return __( 'This site does not let you edit that account.', 'wpaqs' );
		}

		return '';
	}

	/**
	 * End every session an account has.
	 *
	 * Reversible by definition: the person signs in again. That is what makes this the
	 * cheap first move on a suspicious session — nothing is destroyed and nothing needs
	 * reissuing.
	 *
	 * @return void
	 */
	public static function end_sessions() {
		if ( ! self::user_can_act() ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'wpaqs' ), '', array( 'response' => 403 ) );
		}

		$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;

		check_admin_referer( WPAQS_NONCE . '-end-sessions-' . $user_id );

		$result = WPAQS_Actions::end_sessions( $user_id, get_current_user_id() );

		if ( ! $result['ok'] ) {
			self::redirect( 'sessions-refused', $result['message'] );
		}

		self::redirect( 'sessions-ended', '', array( 'wpaqs-count' => (int) $result['data']['count'] ) );
	}

	/**
	 * Revoke one application password.
	 *
	 * Not reversible: the secret is deleted rather than hidden. It ships anyway because
	 * revoking is what actually stops REST publishing, and the cost of a mistake is an
	 * integration that stops working until somebody issues it a new password — not lost
	 * data. The confirmation says so before the press.
	 *
	 * @return void
	 */
	public static function revoke_password() {
		if ( ! self::user_can_act() ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'wpaqs' ), '', array( 'response' => 403 ) );
		}

		$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		$uuid    = isset( $_POST['uuid'] ) ? sanitize_text_field( wp_unslash( $_POST['uuid'] ) ) : '';

		check_admin_referer( WPAQS_NONCE . '-revoke-' . $user_id . '-' . $uuid );

		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			wp_die( esc_html__( 'This site does not let you edit that account.', 'wpaqs' ), '', array( 'response' => 403 ) );
		}

		if ( ! WPAQS_App_Passwords::available() ) {
			wp_die( esc_html__( 'This WordPress version does not support application passwords.', 'wpaqs' ), '', array( 'response' => 400 ) );
		}

		// Checked live. Nothing is trusted from the request beyond the pair being named,
		// and a pair that is not on the account right now is not something to act on.
		if ( ! WPAQS_App_Passwords::exists( $user_id, $uuid ) ) {
			self::redirect( 'revoke-refused', __( 'That application password is not on that account any more, so nothing was revoked. Reload the screen to see the current list.', 'wpaqs' ) );
		}

		$result  = WP_Application_Passwords::delete_application_password( $user_id, $uuid );
		$revoked = ( true === $result || ( ! is_wp_error( $result ) && $result ) );

		if ( $revoked ) {
			self::redirect( 'revoked' );
		}

		self::redirect( 'revoke-refused', __( 'WordPress refused to delete that application password, and nothing was changed.', 'wpaqs' ) );
	}

	/**
	 * Back to the screen with a notice.
	 *
	 * @param string $notice Notice key.
	 * @param string $why    Optional reason, shown verbatim.
	 * @param array  $extra  Extra query arguments.
	 * @return void
	 */
	private static function redirect( $notice, $why = '', array $extra = array() ) {
		$args = array_merge( array( 'wpaqs-notice' => $notice ), $extra );

		if ( '' !== $why ) {
			$args['wpaqs-why'] = rawurlencode( $why );
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'tools.php?page=' . WPAQS_Admin_Page::SLUG ) ) );

		exit;
	}
}
