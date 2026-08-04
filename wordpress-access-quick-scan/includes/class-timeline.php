<?php
/**
 * What changed, in order.
 *
 * Somebody who opens this screen because their site is behaving oddly is not asking who has
 * access — they are asking what is different. The tables answer the first question and the
 * timeline answers the second, out of the same data: every timestamp the other readers
 * already collected, put in one list newest first.
 *
 * The value is in the ordering rather than in any single line. "Session opened from an
 * address nobody recognises" is thin on its own; the same line followed twenty minutes later
 * by "application password created" and then by "that password used from somewhere else" is
 * an account takeover with its steps in order.
 *
 * No new queries. Everything here was read to build the tables.
 *
 * @package WPAQS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Assembles the chronological view.
 */
class WPAQS_Timeline {

	/**
	 * Entries kept, newest first.
	 *
	 * A busy site produces one per login, so the list is bounded — and says so when it is
	 * reached, because a truncated list that looks complete is worse than a short one.
	 */
	const MAX_ENTRIES = 100;

	/**
	 * Everything access-related that happened inside the window.
	 *
	 * @param array $accounts  Result of WPAQS_Accounts::all().
	 * @param array $sessions  User id => sessions.
	 * @param array $passwords User id => application passwords.
	 * @return array array( entries, capped, total )
	 */
	public static function build( array $accounts, array $sessions, array $passwords ) {
		$since   = time() - ( WPAQS_RECENT_DAYS * DAY_IN_SECONDS );
		$entries = array();

		foreach ( $accounts['rows'] as $row ) {
			$registered = strtotime( $row['registered'] . ' UTC' );

			if ( $registered && $registered >= $since ) {
				$entries[] = self::entry(
					$registered,
					'account',
					$row['is_admin']
						? __( 'Administrator account created', 'wpaqs' )
						: __( 'Account created', 'wpaqs' ),
					$row['login'],
					implode( ', ', $row['roles'] )
				);
			}

			// A pending password reset is one of the very few dated events WordPress keeps.
			if ( ! empty( $row['reset_requested'] ) && $row['reset_requested'] >= $since ) {
				$entries[] = self::entry(
					(int) $row['reset_requested'],
					'reset',
					__( 'Password reset requested and not completed', 'wpaqs' ),
					$row['login'],
					__( 'the link was issued and never used', 'wpaqs' )
				);
			}

			foreach ( ( isset( $sessions[ $row['id'] ] ) ? $sessions[ $row['id'] ] : array() ) as $session ) {
				if ( $session['login'] < $since ) {
					continue;
				}

				$entries[] = self::entry(
					(int) $session['login'],
					WPAQS_Sessions::is_scripted( $session['ua'] ) ? 'scripted' : 'session',
					WPAQS_Sessions::is_scripted( $session['ua'] )
						? __( 'Signed in by something that is not a browser', 'wpaqs' )
						: __( 'Signed in', 'wpaqs' ),
					$row['login'],
					'' === $session['ip'] ? __( 'no address recorded', 'wpaqs' ) : $session['ip']
				);
			}

			foreach ( ( isset( $passwords[ $row['id'] ] ) ? $passwords[ $row['id'] ] : array() ) as $password ) {
				$label = '' === $password['name'] ? $password['uuid'] : $password['name'];

				if ( $password['created'] >= $since ) {
					$entries[] = self::entry(
						(int) $password['created'],
						'password',
						__( 'Application password created', 'wpaqs' ),
						$row['login'],
						$label
					);
				}

				// The last use, not every use: WordPress records one timestamp per password.
				if ( $password['last_used'] >= $since ) {
					$entries[] = self::entry(
						(int) $password['last_used'],
						'password_used',
						__( 'Application password used', 'wpaqs' ),
						$row['login'],
						sprintf(
							/* translators: 1: password name, 2: address. */
							__( '%1$s, from %2$s', 'wpaqs' ),
							$label,
							'' === $password['last_ip'] ? __( 'no address recorded', 'wpaqs' ) : $password['last_ip']
						)
					);
				}
			}
		}

		$total = count( $entries );

		// Newest first, and ties in the order they were collected so the list does not shuffle
		// between page loads.
		$entries = WPAQS_Sort::apply(
			$entries,
			'desc',
			function ( $entry ) {
				return $entry['at'];
			}
		);

		return array(
			'entries' => array_slice( $entries, 0, self::MAX_ENTRIES ),
			'capped'  => $total > self::MAX_ENTRIES,
			'total'   => $total,
		);
	}

	/**
	 * One entry.
	 *
	 * @param int    $at      Unix timestamp.
	 * @param string $kind    Slug, for the marker beside the row.
	 * @param string $label   What happened.
	 * @param string $login   Which account.
	 * @param string $detail  Anything else worth showing.
	 * @return array
	 */
	private static function entry( $at, $kind, $label, $login, $detail = '' ) {
		return array(
			'at'     => (int) $at,
			'kind'   => $kind,
			'label'  => $label,
			'login'  => $login,
			'detail' => $detail,
		);
	}
}
