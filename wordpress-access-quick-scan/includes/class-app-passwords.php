<?php
/**
 * Application passwords, with when and where each was last used.
 *
 * Core records `created`, `last_used` and `last_ip` per password, which is more history
 * than it keeps about anything else. An application password authenticates the REST API
 * as its owner and bypasses the login form entirely, so an unaccounted one on an
 * administrator is a way into the site that no password change closes.
 *
 * Read-only. Revoking happens in WPAQS_Controller, pressed by a person.
 *
 * @package WPAQS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads application passwords.
 */
class WPAQS_App_Passwords {

	/**
	 * Whether this WordPress supports application passwords at all.
	 *
	 * @return bool
	 */
	public static function available() {
		return class_exists( 'WP_Application_Passwords' );
	}

	/**
	 * Passwords for one account.
	 *
	 * @param int $user_id User id.
	 * @return array
	 */
	public static function for_user( $user_id ) {
		if ( ! self::available() ) {
			return array();
		}

		$stored = WP_Application_Passwords::get_user_application_passwords( (int) $user_id );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		$passwords = array();

		foreach ( $stored as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$passwords[] = array(
				'uuid'      => isset( $entry['uuid'] ) ? (string) $entry['uuid'] : '',
				'name'      => isset( $entry['name'] ) ? (string) $entry['name'] : '',
				'created'   => isset( $entry['created'] ) ? (int) $entry['created'] : 0,
				'last_used' => isset( $entry['last_used'] ) ? (int) $entry['last_used'] : 0,
				'last_ip'   => isset( $entry['last_ip'] ) ? (string) $entry['last_ip'] : '',
			);
		}

		return $passwords;
	}

	/**
	 * Whether a password exists right now.
	 *
	 * The guard the revoke endpoint uses. Deliberately live rather than read from a stored
	 * report: a report is a snapshot, and a snapshot can offer to revoke something that is
	 * already gone.
	 *
	 * @param int    $user_id User id.
	 * @param string $uuid    Password uuid.
	 * @return bool
	 */
	public static function exists( $user_id, $uuid ) {
		$uuid = (string) $uuid;

		if ( '' === $uuid ) {
			return false;
		}

		foreach ( self::for_user( $user_id ) as $password ) {
			if ( $password['uuid'] === $uuid ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Findings for one account's passwords.
	 *
	 * @param array $account   One row from WPAQS_Accounts::all().
	 * @param array $passwords Result of for_user().
	 * @param array $addresses IPs the account has a live session from.
	 * @return array
	 */
	public static function findings( array $account, array $passwords, array $addresses ) {
		$findings = array();

		foreach ( $passwords as $password ) {
			$label = '' === $password['name'] ? $password['uuid'] : $password['name'];

			if ( 0 === $password['last_used'] ) {
				$findings[] = WPAQS_Findings::make(
					'app_password_unused',
					'user:' . $account['id'] . ':app-password:' . $password['uuid'],
					sprintf( 'login=%1$s name=%2$s created=%3$s', $account['login'], $label, self::stamp( $password['created'] ) )
				);

				// An unused password has no last_ip to compare, so the second check cannot
				// apply and saying nothing about it is the honest outcome.
				continue;
			}

			if ( '' === $password['last_ip'] ) {
				continue;
			}

			if ( in_array( $password['last_ip'], $addresses, true ) ) {
				continue;
			}

			$findings[] = WPAQS_Findings::make(
				'app_password_foreign_ip',
				'user:' . $account['id'] . ':app-password:' . $password['uuid'],
				sprintf(
					'login=%1$s name=%2$s last_used=%3$s last_ip=%4$s sessions=%5$s',
					$account['login'],
					$label,
					self::stamp( $password['last_used'] ),
					$password['last_ip'],
					empty( $addresses ) ? 'none open' : implode( ',', $addresses )
				)
			);
		}

		return $findings;
	}

	/**
	 * A timestamp as a readable UTC string, or a word when there is none.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string
	 */
	public static function stamp( $timestamp ) {
		$timestamp = (int) $timestamp;

		return $timestamp > 0 ? gmdate( 'Y-m-d H:i', $timestamp ) . ' UTC' : 'never';
	}
}
