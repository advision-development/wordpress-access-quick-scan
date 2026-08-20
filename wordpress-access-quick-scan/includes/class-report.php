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
			);
		}

		return array(
			'plugin'       => 'WordPress Access Quick Scan',
			'version'      => WPAQS_VERSION,
			'generated_at' => gmdate( 'c' ),
			'site'         => home_url(),
			'findings'     => $out,
		);
	}
}
