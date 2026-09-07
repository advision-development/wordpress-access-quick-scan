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

			/*
			 * Checked before the used/unused branch below, and outside it, because the two
			 * rules are about different things and this one holds whatever the answer to the
			 * other is. The credential this exists for was in daily use.
			 *
			 * On the real site `panel-auto-infect` was created and first used in the same
			 * minute, and both of the checks below correctly stayed quiet about it: it had a
			 * `last_used`, so it was not unused, and it was last used from an address the
			 * account already had open sessions from, so the address was not unfamiliar. The
			 * name was the only field that said anything, and it said all of it.
			 */
			if ( self::hostile_name( $password['name'] ) ) {
				$findings[] = WPAQS_Findings::make(
					'application_password_suspicious_name',
					'user:' . $account['id'] . ':app-password:' . $password['uuid'],
					sprintf( 'login=%1$s name=%2$s created=%3$s last_used=%4$s', $account['login'], $label, self::stamp( $password['created'] ), self::stamp( $password['last_used'] ) ),
					sprintf(
						/* translators: %s: the label given to the application password. */
						__( 'Named "%s".', 'wpaqs' ),
						$password['name']
					)
				);
			}

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
	 * Whether the label on a credential names what it was for.
	 *
	 * The same expression as the sibling's `hostile_credential_name()`, deliberately, and the
	 * reasoning is worth carrying with it rather than leaving next door.
	 *
	 * **The list is narrow, and narrower than the one it was cut from.** `auto` and `panel`
	 * were both proposed — the credential this rule exists for was called
	 * `panel-auto-infect` — and both were rejected: an automation integration and a hosting
	 * control panel are ordinary things to name a credential after, and escalating those to
	 * critical would put this plugin's loudest severity on a shop's own order sync. `infect`
	 * is the word in that name that appears in a credential label for one reason.
	 *
	 * **Matched on word boundaries rather than as substrings**, because "Nutshell CRM sync"
	 * contains "shell" and is somebody's actual integration.
	 *
	 * High-entropy names were also proposed and are not implemented: a UUID-named integration
	 * and a person mashing the keyboard both look like one, and neither is a compromise.
	 *
	 * @param string $name The label given to the application password.
	 * @return bool
	 */
	public static function hostile_name( $name ) {
		return 1 === preg_match( '~\b(?:infect|shell|backdoor|webshell|hack|exploit|bypass|c99|r57)\b~i', (string) $name );
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
