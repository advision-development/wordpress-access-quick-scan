<?php
/**
 * Live sessions — the only access history WordPress core keeps.
 *
 * `session_tokens` user meta holds one entry per session that has not expired, and each
 * entry records the IP it was opened from, the user agent that opened it, the login time
 * and the expiry. Nothing else in core records who connected from where.
 *
 * What that means for this plugin: a session that expired, or that was destroyed, leaves
 * nothing behind. There is no login history to read, only what is still open. The screen
 * says so rather than letting the list imply completeness.
 *
 * Read-only.
 *
 * @package WPAQS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads live sessions.
 */
class WPAQS_Sessions {

	/**
	 * User agent fragments no browser sends.
	 *
	 * Matched case-insensitively as substrings. Every one of these is a client somebody
	 * scripted: a person filling in a login form does not produce them.
	 */
	const SCRIPTED_AGENTS = array(
		'curl/',
		'wget',
		'python-requests',
		'python-urllib',
		'go-http-client',
		'guzzle',
		'okhttp',
		'axios',
		'node-fetch',
		'libwww-perl',
		'java/',
		'httpclient',
		'postmanruntime',
		'insomnia',
	);

	/**
	 * Sessions for one account.
	 *
	 * @param int $user_id User id.
	 * @return array
	 */
	public static function for_user( $user_id ) {
		$stored = get_user_meta( (int) $user_id, 'session_tokens', true );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		$sessions = array();

		foreach ( $stored as $session ) {
			if ( ! is_array( $session ) ) {
				// Meta written by something other than WordPress. Reported as unreadable
				// rather than skipped: an unreadable session is still a session.
				$sessions[] = array(
					'ip'         => '',
					'ua'         => '',
					'login'      => 0,
					'expiration' => 0,
					'readable'   => false,
				);

				continue;
			}

			$sessions[] = array
				(
					'ip'         => isset( $session['ip'] ) ? (string) $session['ip'] : '',
					'ua'         => isset( $session['ua'] ) ? (string) $session['ua'] : '',
					'login'      => isset( $session['login'] ) ? (int) $session['login'] : 0,
					'expiration' => isset( $session['expiration'] ) ? (int) $session['expiration'] : 0,
					'readable'   => true,
				);
		}

		return $sessions;
	}

	/**
	 * Whether a user agent is a scripted client rather than a browser.
	 *
	 * An empty user agent counts. Browsers always send one; a script often does not
	 * bother.
	 *
	 * @param string $agent User agent string.
	 * @return bool
	 */
	public static function is_scripted( $agent ) {
		$agent = strtolower( trim( (string) $agent ) );

		if ( '' === $agent ) {
			return true;
		}

		foreach ( self::SCRIPTED_AGENTS as $needle ) {
			if ( false !== strpos( $agent, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Every IP an account currently has a session from.
	 *
	 * @param array $sessions Result of for_user().
	 * @return array
	 */
	public static function addresses( array $sessions ) {
		$addresses = array();

		foreach ( $sessions as $session ) {
			if ( '' !== $session['ip'] ) {
				$addresses[ $session['ip'] ] = true;
			}
		}

		return array_keys( $addresses );
	}

	/**
	 * Findings for one account's sessions.
	 *
	 * @param array $account  One row from WPAQS_Accounts::all().
	 * @param array $sessions Result of for_user().
	 * @return array
	 */
	public static function findings( array $account, array $sessions ) {
		$findings = array();

		foreach ( $sessions as $session ) {
			if ( ! self::is_scripted( $session['ua'] ) ) {
				continue;
			}

			$findings[] = WPAQS_Findings::make(
				'non_browser_session',
				'user:' . $account['id'] . ':session',
				sprintf(
					'login=%1$s ip=%2$s ua=%3$s',
					$account['login'],
					'' === $session['ip'] ? 'unknown' : $session['ip'],
					'' === $session['ua'] ? 'none sent' : $session['ua']
				)
			);
		}

		return $findings;
	}
}
