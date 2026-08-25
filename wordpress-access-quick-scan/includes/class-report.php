<?php
/**
 * Reading the site's access, with nothing rendering it.
 *
 * The screen assembled this inline, which was fine while the screen was the only thing
 * that wanted it. It is not any more: the fleet console needs the same answer and must
 * not get it by a second route, or the two drift and a finding shown on the site stops
 * matching the finding shown in the console.
 *
 * This plugin has no stored report and does not gain one here. Everything is read live
 * every time, which is the arrangement that makes it honest: an application password is
 * either on the account right now or it is not.
 *
 * @package WPAQS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One read of who has access, and what it looks like to somebody else.
 */
class WPAQS_Report {

	/**
	 * Everything the screen and the console both need.
	 *
	 * @return array accounts, sessions, passwords, findings.
	 */
	public static function gather() {
		$started  = time();
		$accounts = WPAQS_Accounts::all();
		$findings = WPAQS_Accounts::findings( $accounts );
		$findings = array_merge( $findings, WPAQS_Registration::findings() );

		$sessions  = array();
		$passwords = array();

		foreach ( $accounts['rows'] as $row ) {
			$sessions[ $row['id'] ]  = WPAQS_Sessions::for_user( $row['id'] );
			$passwords[ $row['id'] ] = WPAQS_App_Passwords::for_user( $row['id'] );

			$findings = array_merge( $findings, WPAQS_Sessions::findings( $row, $sessions[ $row['id'] ] ) );
			$findings = array_merge(
				$findings,
				WPAQS_App_Passwords::findings(
					$row,
					$passwords[ $row['id'] ],
					WPAQS_Sessions::addresses( $sessions[ $row['id'] ] )
				)
			);
		}

		return array(
			// There is no scan, so these bracket the read rather than a run. The console
			// wants to know when the answer was true, and that is what they say.
			'started_at'   => $started,
			'completed_at' => time(),
			'accounts'  => $accounts,
			'sessions'  => $sessions,
			'passwords' => $passwords,
			'findings'  => WPAQS_Findings::sorted( $findings ),
		);
	}

	/**
	 * The findings as the console should see them.
	 *
	 * Deliberately narrow. This plugin's data is more sensitive than the sibling's —
	 * logins, email addresses and the addresses people sign in from — and those are the
	 * point: a console that hid them could not answer the question it exists for. What
	 * never leaves is the material that would let somebody *become* one of these
	 * accounts rather than recognise it: the password hash, the session tokens, and the
	 * raw activation key behind a pending reset. The finding about a pending reset
	 * carries when it was requested, which is the part a person can act on.
	 *
	 * The id is what the console's diff is keyed on, so it has to name the same thing
	 * across two reads of an unchanged site — hence the rule and the target, never a
	 * position in a list.
	 *
	 * @param array $gathered Result of gather().
	 * @return array
	 */
	public static function to_export_array( array $gathered ) {
		$findings = isset( $gathered['findings'] ) ? (array) $gathered['findings'] : array();
		$out      = array();

		foreach ( $findings as $finding ) {
			$rule   = isset( $finding['rule'] ) ? (string) $finding['rule'] : '';
			$target = isset( $finding['target'] ) ? (string) $finding['target'] : '';

			if ( '' === $rule || '' === $target ) {
				continue;
			}

			$out[] = array(
				'id'       => sha1( $rule . '|' . $target ),
				'rule'     => $rule,
				'severity' => isset( $finding['severity'] ) ? (string) $finding['severity'] : 'info',
				'target'   => $target,
				'title'    => isset( $finding['title'] ) ? (string) $finding['title'] : '',
				'detail'   => isset( $finding['detail'] ) ? (string) $finding['detail'] : '',
				'evidence' => isset( $finding['evidence'] ) ? (string) $finding['evidence'] : '',
				// The console prints this and writes none of its own. A second wording of
				// what to do about a finding drifts from this one the first time either
				// moves, and the console would be the copy nobody updates.
				'recommendation' => isset( $finding['recommendation'] ) ? (string) $finding['recommendation'] : '',
			);
		}

		return array(
			'plugin'       => 'WordPress Access Quick Scan',
			'version'      => WPAQS_VERSION,
			'generated_at' => gmdate( 'c' ),
			'site'         => home_url(),
			'findings'     => $out,
			'access'       => self::access_inventory( $gathered ),
		);
	}

	/**
	 * Who can get into this site, for a console that cannot ask the site itself.
	 *
	 * **What this deliberately does not carry, and why the list is short and specific.**
	 * A finding says a thing stood out; it cannot say who else holds the same capability,
	 * which sessions are open, or which credential has never been used — and those are the
	 * questions somebody asks straight after reading a finding. Sending only findings meant
	 * the console could raise the question and never answer it.
	 *
	 * What never leaves is unchanged: the password hash, the session verifier, and the raw
	 * activation key behind a pending reset. Those are the material that would let somebody
	 * *become* one of these accounts. Everything here is the material that lets somebody
	 * *recognise* one — a login, a role, a capability, an address, a user agent, a date.
	 *
	 * The verifier is the one worth naming twice, because it is the field a careless copy
	 * of `WPAQS_Sessions::for_user()` would carry straight through. It identifies a live
	 * session and it is stripped here. Ending a session remotely will need it, and that is
	 * a signed command resolved on the site rather than a token shipped to a browser.
	 *
	 * @param array $gathered Result of gather().
	 * @return array
	 */
	private static function access_inventory( array $gathered ) {
		$accounts  = isset( $gathered['accounts']['rows'] ) ? (array) $gathered['accounts']['rows'] : array();
		$sessions  = isset( $gathered['sessions'] ) ? (array) $gathered['sessions'] : array();
		$passwords = isset( $gathered['passwords'] ) ? (array) $gathered['passwords'] : array();

		$out_accounts = array();

		foreach ( $accounts as $row ) {
			$id = isset( $row['id'] ) ? (int) $row['id'] : 0;

			$out_accounts[] = array(
				'id'         => $id,
				'login'      => isset( $row['login'] ) ? (string) $row['login'] : '',
				'email'      => isset( $row['email'] ) ? (string) $row['email'] : '',
				'registered' => isset( $row['registered'] ) ? (string) $row['registered'] : '',
				'roles'      => array_values( array_map( 'strval', (array) ( isset( $row['roles'] ) ? $row['roles'] : array() ) ) ),
				'isAdmin'    => ! empty( $row['is_admin'] ),
				// Capabilities written straight against the account rather than inherited
				// from a role. The Users screen shows neither, which is the whole point.
				'direct'     => array_values( array_map( 'strval', (array) ( isset( $row['direct'] ) ? $row['direct'] : array() ) ) ),
				// When a reset was asked for, never the key that would complete one.
				'resetRequested' => isset( $row['reset_requested'] ) ? (int) $row['reset_requested'] : 0,
			);
		}

		$out_sessions = array();

		foreach ( $sessions as $user_id => $rows ) {
			foreach ( (array) $rows as $session ) {
				$out_sessions[] = array(
					'account'    => (int) $user_id,
					'ip'         => isset( $session['ip'] ) ? (string) $session['ip'] : '',
					'ua'         => isset( $session['ua'] ) ? (string) $session['ua'] : '',
					'login'      => isset( $session['login'] ) ? (int) $session['login'] : 0,
					'expiration' => isset( $session['expiration'] ) ? (int) $session['expiration'] : 0,
					'expired'    => ! empty( $session['expired'] ),
					// False when the session row could not be read at all, which is a
					// different fact from a session with no address recorded.
					'readable'   => ! empty( $session['readable'] ),
				);
			}
		}

		$out_passwords = array();

		foreach ( $passwords as $user_id => $rows ) {
			foreach ( (array) $rows as $password ) {
				$out_passwords[] = array(
					'account'  => (int) $user_id,
					// The uuid names it for a revoke command later. It is not the password.
					'uuid'     => isset( $password['uuid'] ) ? (string) $password['uuid'] : '',
					'name'     => isset( $password['name'] ) ? (string) $password['name'] : '',
					'created'  => isset( $password['created'] ) ? (int) $password['created'] : 0,
					'lastUsed' => isset( $password['last_used'] ) ? (int) $password['last_used'] : 0,
					'lastIp'   => isset( $password['last_ip'] ) ? (string) $password['last_ip'] : '',
				);
			}
		}

		$code = array();

		foreach ( WPAQS_Accounts::code_holders( isset( $gathered['accounts'] ) ? (array) $gathered['accounts'] : array( 'rows' => array() ) ) as $holder ) {
			$code[] = array(
				'account' => isset( $holder['account']['id'] ) ? (int) $holder['account']['id'] : 0,
				'caps'    => array_values( array_map( 'strval', (array) $holder['caps'] ) ),
			);
		}

		return array(
			'accounts'  => $out_accounts,
			'sessions'  => $out_sessions,
			'passwords' => $out_passwords,
			// Who can run code, and whether the built-in editors make that reachable
			// without uploading anything.
			'code'      => $code,
			'fileEditingAllowed' => ! ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT )
				&& ! ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ),
			// The console counts the fleet's accounts and cannot see past the cap.
			'truncated' => isset( $gathered['accounts']['total'] )
				&& (int) $gathered['accounts']['total'] > count( $out_accounts ),
			'total'     => isset( $gathered['accounts']['total'] ) ? (int) $gathered['accounts']['total'] : count( $out_accounts ),
		);
	}
}
