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
