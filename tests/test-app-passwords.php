<?php
/**
 * Application passwords: unused, used from somewhere unfamiliar, and the benign case.
 *
 * The foreign-address rule compares against the addresses the account has an open session
 * from, so its benign case is the one that matters most: a password used from the same
 * address as a live session must stay silent, or every integration on a site becomes a
 * finding.
 */

/**
 * Core's class, with only the surface the reader touches.
 */
class WP_Application_Passwords {

	public static function get_user_application_passwords( $user_id ) {
		return isset( $GLOBALS['passwords'][ (int) $user_id ] ) ? $GLOBALS['passwords'][ (int) $user_id ] : array();
	}
}

require __DIR__ . '/bootstrap.php';

load_class( 'findings' );
load_class( 'app-passwords' );

$GLOBALS['passwords'] = array(
	1 => array(
		array( 'uuid' => 'u-1', 'name' => 'Zapier', 'created' => 1740000000, 'last_used' => 1750000000, 'last_ip' => '203.0.113.9' ),
		array( 'uuid' => 'u-2', 'name' => 'Unused key', 'created' => 1740000000, 'last_used' => null, 'last_ip' => null ),
		array( 'uuid' => 'u-3', 'name' => 'Elsewhere', 'created' => 1740000000, 'last_used' => 1750000500, 'last_ip' => '192.0.2.55' ),
		array( 'uuid' => 'u-4', 'name' => 'No address', 'created' => 1740000000, 'last_used' => 1750000600, 'last_ip' => '' ),
	),
	2 => array(),
);

$account   = array( 'id' => 1, 'login' => 'owner' );
$addresses  = array( '203.0.113.9' );

$read = WPAQS_App_Passwords::for_user( 1 );

check( 'every password is read', 4 === count( $read ), (string) count( $read ) );
check( 'a null last_used becomes zero', 0 === $read[1]['last_used'] );
check( 'a null last_ip becomes an empty string', '' === $read[1]['last_ip'] );
check( 'an account with none reads as none', array() === WPAQS_App_Passwords::for_user( 2 ) );

// ------------------------------------------------------------------ live existence

check( 'a password on the account exists', WPAQS_App_Passwords::exists( 1, 'u-1' ) );
check( 'one that is not does not', ! WPAQS_App_Passwords::exists( 1, 'nope' ) );
check( 'an empty uuid never exists', ! WPAQS_App_Passwords::exists( 1, '' ) );
check( 'a uuid from another account does not exist here', ! WPAQS_App_Passwords::exists( 2, 'u-1' ) );

// ----------------------------------------------------------------------- findings

$findings = array();

foreach ( WPAQS_App_Passwords::findings( $account, $read, $addresses ) as $finding ) {
	$findings[ $finding['rule'] . '|' . $finding['target'] ] = $finding;
}

check(
	'the password used from the same address as a session is silent',
	! isset( $findings['app_password_foreign_ip|user:1:app-password:u-1'] ),
	'otherwise every working integration is a finding'
);

check( 'the unused password is reported', isset( $findings['app_password_unused|user:1:app-password:u-2'] ) );
check( 'at medium', isset( $findings['app_password_unused|user:1:app-password:u-2'] ) && 'medium' === $findings['app_password_unused|user:1:app-password:u-2']['severity'] );

// An unused password has no address to compare, so it must not also be reported as
// foreign — that would be two findings about one fact.
check(
	'and not also as a foreign address',
	! isset( $findings['app_password_foreign_ip|user:1:app-password:u-2'] )
);

check( 'the password used from elsewhere is reported', isset( $findings['app_password_foreign_ip|user:1:app-password:u-3'] ) );
check( 'at high', isset( $findings['app_password_foreign_ip|user:1:app-password:u-3'] ) && 'high' === $findings['app_password_foreign_ip|user:1:app-password:u-3']['severity'] );
check(
	'and the evidence names both addresses',
	isset( $findings['app_password_foreign_ip|user:1:app-password:u-3'] )
		&& false !== strpos( $findings['app_password_foreign_ip|user:1:app-password:u-3']['evidence'], '192.0.2.55' )
		&& false !== strpos( $findings['app_password_foreign_ip|user:1:app-password:u-3']['evidence'], '203.0.113.9' ),
	'the operator has to see what it was compared against'
);

// Core did not record an address. Saying "used from somewhere unfamiliar" would be an
// invention.
check(
	'a used password with no recorded address is silent',
	! isset( $findings['app_password_foreign_ip|user:1:app-password:u-4'] )
);

// With no open sessions there is nothing to compare against, so a used password is
// reported — and the evidence has to say the comparison set was empty rather than implying
// a mismatch against something.
$no_sessions = array();
$findings    = array();

foreach ( WPAQS_App_Passwords::findings( $account, $read, $no_sessions ) as $finding ) {
	$findings[ $finding['rule'] . '|' . $finding['target'] ] = $finding;
}

check(
	'with no open sessions a used password is reported',
	isset( $findings['app_password_foreign_ip|user:1:app-password:u-1'] )
);

check(
	'and the evidence says there were none to compare',
	isset( $findings['app_password_foreign_ip|user:1:app-password:u-1'] )
		&& false !== strpos( $findings['app_password_foreign_ip|user:1:app-password:u-1']['evidence'], 'none open' )
);

// -------------------------------------------------------------------- timestamps

check( 'a timestamp renders as UTC', '2025-06-15 15:06 UTC' === WPAQS_App_Passwords::stamp( 1750000000 ), WPAQS_App_Passwords::stamp( 1750000000 ) );
check( 'and zero renders as never', 'never' === WPAQS_App_Passwords::stamp( 0 ) );

finish();
