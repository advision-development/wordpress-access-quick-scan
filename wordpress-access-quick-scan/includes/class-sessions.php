<?php
/**
 * Live sessions — the only access history WordPress core keeps.
 *
 * `session_tokens` user meta holds one entry per session, and each entry records the IP it
 * was opened from, the user agent that opened it, the login time and the expiry. Nothing
 * else in core records who connected from where.
 *
 * **It does not hold only unexpired sessions**, which this file claimed for four versions
 * and which is the assumption that made the screen wrong. `WP_User_Meta_Session_Tokens`
 * prunes expired tokens when it next *writes* the meta — on a login, on a session being
 * destroyed — so an account that stopped signing in keeps its lapsed tokens indefinitely.
 * A real site showed a sign-in from 2024 under a heading reading "live sessions".
 *
 * So every entry carries `expired`, and the callers that make a claim about *now* —
 * `addresses()`, `findings()` — read `open()` rather than the raw list. A destroyed session
 * still leaves nothing behind, so the list is not login history in general; what it holds is
 * whatever WordPress has not got round to deleting, which is more than what is open and far
 * less than everything.
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

	/** Separate networks before one account being signed in from all of them is odd. */
	const MANY_NETWORKS = 3;

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

		foreach ( $stored as $verifier => $session ) {
			if ( ! is_array( $session ) ) {
				// Meta written by something other than WordPress. Reported as unreadable
				// rather than skipped: an unreadable session is still a session.
				$sessions[] = array(
					'verifier'   => (string) $verifier,
					'ip'         => '',
					'ua'         => '',
					'login'      => 0,
					'expiration' => 0,
					'expired'    => false,
					'readable'   => false,
				);

				continue;
			}

			$sessions[] = array
				(
					// The meta key. WordPress stores sessions keyed by a hash of the token it
					// gave the browser, so this identifies one session without being the secret
					// that authenticates it.
					'verifier'   => (string) $verifier,
					'ip'         => isset( $session['ip'] ) ? (string) $session['ip'] : '',
					'ua'         => isset( $session['ua'] ) ? (string) $session['ua'] : '',
					'login'      => isset( $session['login'] ) ? (int) $session['login'] : 0,
					'expiration' => isset( $session['expiration'] ) ? (int) $session['expiration'] : 0,
					// An expiry in the past means WordPress rejects the cookie, so the session
					// is not open — but the row stays, because the meta is only pruned on the
					// next write. See the file docblock.
					//
					// A missing or zero expiry is not read as expired: that is meta this class
					// could not understand, and "closed" is a claim as much as "open" is.
					'expired'    => isset( $session['expiration'] ) && (int) $session['expiration'] > 0
						&& (int) $session['expiration'] < time(),
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
			// Expired sessions are excluded, and this is why the flag exists. This set is what
			// WPAQS_App_Passwords::findings() treats as addresses the account is known to work
			// from, and a match there *suppresses* a finding. A lapsed session WordPress never
			// pruned would vouch for its address forever, so an application password used from
			// that address today would read as familiar — a false negative on the one check
			// this screen makes about a credential being used from somewhere new.
			if ( '' !== $session['ip'] && empty( $session['expired'] ) ) {
				$addresses[ $session['ip'] ] = true;
			}
		}

		return array_keys( $addresses );
	}

	/**
	 * The sessions that are actually open.
	 *
	 * @param array $sessions Result of for_user().
	 * @return array
	 */
	public static function open( array $sessions ) {
		$live = array();

		foreach ( $sessions as $session ) {
			if ( empty( $session['expired'] ) ) {
				$live[] = $session;
			}
		}

		return $live;
	}

	/**
	 * The stored key of the session this request is being made from.
	 *
	 * WordPress hands the browser a raw token and stores sessions keyed by its hash, so
	 * matching them means hashing the current token the same way core does —
	 * `WP_User_Meta_Session_Tokens::hash_token()`, which is sha256 where the hash extension is
	 * available and md5 otherwise. It is reimplemented here rather than reached for because
	 * that method is not public, and getting it wrong is harmless: the marker simply does not
	 * appear.
	 *
	 * @return string Empty when there is no way to tell.
	 */
	public static function current_verifier() {
		if ( ! function_exists( 'wp_get_session_token' ) ) {
			return '';
		}

		$token = (string) wp_get_session_token();

		if ( '' === $token ) {
			return '';
		}

		return function_exists( 'hash' ) ? hash( 'sha256', $token ) : md5( $token );
	}

	/**
	 * Whether one session can be ended on this site.
	 *
	 * `WP_Session_Tokens::destroy()` takes the raw token WordPress handed the browser, and
	 * what is stored — and therefore all this plugin can see — is a hash of it. There is no
	 * public way to end a session you can only identify by its hash, so ending one means
	 * writing the `session_tokens` meta the way core does internally.
	 *
	 * That is only correct while the default manager is in use. A site that replaces it
	 * through the `session_token_manager` filter keeps its sessions somewhere else entirely,
	 * and writing user meta there would appear to work and change nothing. So the control is
	 * offered only when the default manager is the one running.
	 *
	 * @return bool
	 */
	public static function can_end_one() {
		if ( ! function_exists( 'apply_filters' ) ) {
			return false;
		}

		return 'WP_User_Meta_Session_Tokens' === apply_filters( 'session_token_manager', 'WP_User_Meta_Session_Tokens' );
	}

	/**
	 * Which ending controls a row should carry.
	 *
	 * This is a function rather than two conditions in the template because the template got
	 * it wrong twice in one sitting: first offering both controls for a single session, where
	 * they are the same press, and then — fixing that by gating both on the count — offering
	 * neither. Counting rendered buttons is the only assertion that catches the second, and
	 * it cannot be written against a template.
	 *
	 * Exactly one control for one session, whichever of the two can do the job. The per-row
	 * control needs the default session manager; the bulk one always works, which is why it
	 * is the fallback rather than the other way round.
	 *
	 * @param int $count How many sessions the account has.
	 * @return array array( per_session, bulk )
	 */
	public static function controls( $count ) {
		$count = (int) $count;

		if ( $count < 1 ) {
			// Nothing to end. The bulk label would otherwise read "End all 0 sessions".
			return array(
				'per_session' => false,
				'bulk'        => false,
			);
		}

		$per_session = self::can_end_one();

		return array(
			'per_session' => $per_session,
			'bulk'        => $count > 1 || ! $per_session,
		);
	}

	/**
	 * End one session, leaving the others alone.
	 *
	 * @param int    $user_id  User id.
	 * @param string $verifier The stored key identifying the session.
	 * @return array array( error )
	 */
	public static function end_one( $user_id, $verifier ) {
		$user_id  = (int) $user_id;
		$verifier = (string) $verifier;

		if ( ! self::can_end_one() ) {
			return array( 'error' => __( 'This site stores sessions somewhere other than the usual place, so a single one cannot be ended from here. Ending all of the account\'s sessions still works.', 'wpaqs' ) );
		}

		$stored = get_user_meta( $user_id, 'session_tokens', true );

		if ( ! is_array( $stored ) || ! isset( $stored[ $verifier ] ) ) {
			// Checked live rather than trusted from the request: a session that has expired or
			// been ended since the screen was drawn is not something to act on.
			return array( 'error' => __( 'That session is not open any more, so nothing was ended. Reload the screen to see the current list.', 'wpaqs' ) );
		}

		unset( $stored[ $verifier ] );

		if ( empty( $stored ) ) {
			delete_user_meta( $user_id, 'session_tokens' );
		} else {
			update_user_meta( $user_id, 'session_tokens', $stored );
		}

		return array( 'error' => '' );
	}

	/**
	 * How many separate networks an account is signed in from.
	 *
	 * Three or more is the threshold, and the reason is the benign case: a laptop and a
	 * phone on mobile data are two networks and entirely ordinary, so two would report most
	 * of the people on a healthy site.
	 *
	 * A network here is the address with its host part dropped — the first two octets of an
	 * IPv4 address, the first three groups of an IPv6 one. Coarse on purpose: the question is
	 * "several unrelated places at once", and a finer prefix would separate two addresses
	 * from the same office.
	 *
	 * @param array $sessions Result of for_user().
	 * @return array Network prefixes.
	 */
	public static function networks( array $sessions ) {
		$networks = array();

		foreach ( self::addresses( $sessions ) as $address ) {
			$prefix = self::network_of( $address );

			if ( '' !== $prefix ) {
				$networks[ $prefix ] = true;
			}
		}

		return array_keys( $networks );
	}

	/**
	 * The network part of an address.
	 *
	 * @param string $address IPv4 or IPv6 address.
	 * @return string
	 */
	public static function network_of( $address ) {
		$address = trim( (string) $address );

		if ( '' === $address ) {
			return '';
		}

		if ( false !== strpos( $address, ':' ) ) {
			$groups = explode( ':', $address );

			return implode( ':', array_slice( $groups, 0, 3 ) );
		}

		$octets = explode( '.', $address );

		if ( count( $octets ) < 2 ) {
			return '';
		}

		return $octets[0] . '.' . $octets[1];
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

		// Every rule below reads the open sessions. The catalog wording says "live" and "at
		// once", and both would be untrue of an account carrying lapsed tokens WordPress has
		// not pruned.
		$sessions = self::open( $sessions );
		$networks = self::networks( $sessions );

		if ( count( $networks ) >= self::MANY_NETWORKS ) {
			$findings[] = WPAQS_Findings::make(
				'sessions_many_networks',
				'user:' . $account['id'] . ':networks',
				sprintf(
					'login=%1$s sessions=%2$d networks=%3$s',
					$account['login'],
					count( $sessions ),
					implode( ',', $networks )
				)
			);
		}

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
