<?php
/**
 * What a corrective action does, with no request around it.
 *
 * Every action that changes the site lives here, and the controller only translates.
 * That is not tidiness: a second caller — a signed remote command — runs these with no
 * logged-in user and no nonce, and a refusal that lived in the HTTP handler would apply
 * to the web path and not to that one.
 *
 * Nothing here authorizes. The web handler checks a capability and a nonce; a remote
 * caller checks a signature, the site it is addressed to, and the fleet's own policy.
 * An action asked to do something only decides whether the *target* allows it.
 *
 * This plugin validates against live state rather than a stored report, which is the
 * stronger arrangement for a command: an application password is either on the account
 * right now or it is not. Do not introduce a report to mirror the sibling.
 *
 * @package WPAQS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The corrective actions, callable without a request.
 */
class WPAQS_Actions {

	/**
	 * End every session an account has.
	 *
	 * Reversible by definition: the person signs in again. That is what makes it the
	 * cheap first move on a suspicious session — nothing is destroyed and nothing needs
	 * reissuing. Application passwords keep working, which the confirmation says,
	 * because an operator who thinks this covers them leaves a key working.
	 *
	 * @param int $user_id The account whose sessions end.
	 * @param int $actor   The user asking, or 0 when nobody is.
	 * @return array The result contract: ok, changed, code, message, data.
	 */
	public static function end_sessions( $user_id, $actor ) {
		$user_id = (int) $user_id;

		$refusal = WPAQS_Controller::session_refusal( $user_id, $actor );

		if ( '' !== $refusal ) {
			return self::result( false, false, $refusal, WPAQS_Controller::refusal_text( $refusal ) );
		}

		$count = count( WPAQS_Sessions::for_user( $user_id ) );

		if ( class_exists( 'WP_Session_Tokens' ) ) {
			WP_Session_Tokens::get_instance( $user_id )->destroy_all();
		}

		// Ending sessions for an account that had none is not a failure — the answer to
		// "is anybody in there" is no — but the site did not change, and one boolean
		// cannot say both.
		return self::result(
			true,
			$count > 0,
			'sessions-ended',
			sprintf(
				/* translators: %s: number of sessions ended. */
				_n(
					'%s session ended. Application passwords are unaffected and still authenticate.',
					'%s sessions ended. Application passwords are unaffected and still authenticate.',
					$count,
					'wpaqs'
				),
				number_format_i18n( $count )
			),
			array( 'count' => $count )
		);
	}

	/**
	 * End one session and leave the rest of the account's alone.
	 *
	 * There is deliberately no self-refusal here, unlike ending every session. An
	 * administrator with a browser session and one opened by a script should be able to
	 * close the script's without signing themselves out, and what protects them is that
	 * the session is named rather than the account.
	 *
	 * @param int    $user_id  The account holding it.
	 * @param string $verifier The session's verifier.
	 * @param int    $actor    The user asking, or 0 when nobody is.
	 * @return array The result contract.
	 */
	public static function end_session( $user_id, $verifier, $actor ) {
		$user_id = (int) $user_id;
		$actor   = (int) $actor;

		if ( ! get_userdata( $user_id ) ) {
			return self::result( false, false, 'missing', WPAQS_Controller::refusal_text( 'missing' ) );
		}

		// Asked of the actor. Through current_user_can() this is false for everything
		// under cron and no command would ever close a session.
		if ( 0 !== $actor && ! user_can( $actor, 'edit_user', $user_id ) ) {
			return self::result( false, false, 'nocap', WPAQS_Controller::refusal_text( 'nocap' ) );
		}

		$result = WPAQS_Sessions::end_one( $user_id, $verifier );

		if ( '' !== $result['error'] ) {
			return self::result( false, false, 'session-refused', $result['error'] );
		}

		return self::result(
			true,
			true,
			'session-ended',
			__( 'That session was ended. Every other session on the account is still open, including your own.', 'wpaqs' )
		);
	}

	/**
	 * Revoke one application password.
	 *
	 * Not reversible: the secret is deleted rather than hidden. It ships anyway because
	 * revoking is what actually stops REST authentication, and the cost of a mistake is
	 * an integration that stops working until somebody issues a new password — not lost
	 * data. Browser sessions are unaffected, which the confirmation says: an operator
	 * who thinks one covers the other leaves a way in open.
	 *
	 * @param int    $user_id The account holding it.
	 * @param string $uuid    The password's uuid.
	 * @param int    $actor   The user asking, or 0 when nobody is.
	 * @return array The result contract.
	 */
	public static function revoke_password( $user_id, $uuid, $actor ) {
		$user_id = (int) $user_id;
		$uuid    = (string) $uuid;
		$actor   = (int) $actor;

		// Asked of the actor. Read through current_user_can() this is false for
		// everything under cron and no command would ever revoke anything.
		if ( 0 !== $actor && ! user_can( $actor, 'edit_user', $user_id ) ) {
			return self::result( false, false, 'nocap', WPAQS_Controller::refusal_text( 'nocap' ) );
		}

		if ( ! WPAQS_App_Passwords::available() ) {
			return self::result(
				false,
				false,
				'unsupported',
				__( 'This WordPress version does not support application passwords.', 'wpaqs' )
			);
		}

		// Checked live. Nothing is trusted beyond the pair being named, and a pair that
		// is not on the account right now is not something to act on. This plugin has no
		// stored report to check against, which is the stronger arrangement.
		if ( ! WPAQS_App_Passwords::exists( $user_id, $uuid ) ) {
			return self::result(
				false,
				false,
				'gone',
				__( 'That application password is not on that account any more, so nothing was revoked. Reload the screen to see the current list.', 'wpaqs' )
			);
		}

		$result  = WP_Application_Passwords::delete_application_password( $user_id, $uuid );
		$revoked = ( true === $result || ( ! is_wp_error( $result ) && $result ) );

		if ( ! $revoked ) {
			return self::result(
				false,
				false,
				'revoke-failed',
				__( 'WordPress refused to delete that application password, and nothing was changed.', 'wpaqs' )
			);
		}

		return self::result(
			true,
			true,
			'revoked',
			__( 'The application password was revoked. Anything using it stops working until somebody issues a new one; browser sessions are unaffected.', 'wpaqs' )
		);
	}

	/**
	 * Take a directly granted capability off an account.
	 *
	 * The role is untouched on purpose: removing what a role grants would be undone the
	 * moment WordPress read the role again, so that request is refused and the wording
	 * says where the grant comes from.
	 *
	 * @param int    $user_id The account.
	 * @param string $cap     The capability.
	 * @param int    $actor   The user asking, or 0 when nobody is.
	 * @return array The result contract.
	 */
	public static function remove_capability( $user_id, $cap, $actor ) {
		$cap    = (string) $cap;
		$result = WPAQS_Accounts::remove_direct_capability( (int) $user_id, $cap, $actor );

		// Only two codes. remove_direct_capability() reports its five refusals as prose
		// and nothing else, so finer codes mean changing its contract — worth its own
		// commit rather than being buried in an extraction.
		if ( '' !== $result['error'] ) {
			return self::result( false, false, 'capability-refused', $result['error'], array( 'cap' => $cap ) );
		}

		return self::result(
			true,
			true,
			'capability-removed',
			__( 'The capability was taken off the account. Its role is unchanged, and granting it again puts it back.', 'wpaqs' ),
			array( 'cap' => $cap )
		);
	}

	/**
	 * Set the role new accounts receive to the one that can only read.
	 *
	 * A setting rather than a deletion: Settings → General puts it back. Paired with
	 * closing registration, because either alone is ordinary — a membership site has
	 * registration open, and a custom default role is a normal choice. The pair is the
	 * finding.
	 *
	 * @param int $actor The user asking, or 0 when nobody is. No refusal here depends on
	 *                   it: the target is a site option, not an account.
	 * @return array The result contract.
	 */
	public static function park_default_role( $actor ) {
		unset( $actor );

		$result = WPAQS_Registration::park_default_role();

		if ( '' !== $result['error'] ) {
			return self::result( false, false, 'registration-refused', $result['error'] );
		}

		return self::result(
			true,
			true,
			'default-role-parked',
			__( 'New accounts are Subscribers now. Existing accounts keep the roles they already have.', 'wpaqs' )
		);
	}

	/**
	 * Close public registration on this site.
	 *
	 * On multisite this is a network option and WPAQS_Registration refuses, pointing at
	 * Network Settings — a button that appeared to work and changed nothing would be
	 * worse than no button.
	 *
	 * @param int $actor The user asking, or 0 when nobody is. No refusal here depends on
	 *                   it: the target is a site option, not an account.
	 * @return array The result contract.
	 */
	public static function close_registration( $actor ) {
		unset( $actor );

		$result = WPAQS_Registration::close();

		if ( '' !== $result['error'] ) {
			return self::result( false, false, 'registration-refused', $result['error'] );
		}

		return self::result(
			true,
			true,
			'registration-closed',
			__( 'Public registration is closed. Settings → General opens it again.', 'wpaqs' )
		);
	}

	/**
	 * Assemble the result contract.
	 *
	 * ok answers "did what was asked happen in full"; changed answers "did the site
	 * change at all". Both are needed because one boolean cannot say that an action
	 * succeeded and changed nothing — ending sessions for an account that had none is
	 * an answer, not a failure, and a command layer reading a lone false would record
	 * it as one.
	 *
	 * message is the only human-facing string. Nothing outside this plugin may map a
	 * code to a sentence, or the wording ends up living in two repositories.
	 *
	 * @param bool   $ok      Did the action complete as asked.
	 * @param bool   $changed Did the site change at all.
	 * @param string $code    Machine-readable outcome.
	 * @param string $message The wording a human reads.
	 * @param array  $data    Counts and anything the caller needs.
	 * @return array
	 */
	private static function result( $ok, $changed, $code, $message, array $data = array() ) {
		return array(
			'ok'      => (bool) $ok,
			'changed' => (bool) $changed,
			'code'    => $code,
			'message' => $message,
			'data'    => $data,
		);
	}
}
