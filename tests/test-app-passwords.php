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

// ------------------------------------------------------- what the name says

/*
 * The name is the one field a person chose, and on the real site it read
 * `panel-auto-infect`. Both address-based checks correctly stayed quiet about it — it was
 * used, and used from an address the account already had sessions from — so the name was
 * the only field that said anything, and it said all of it.
 */
check( 'panel-auto-infect is a hostile name', WPAQS_App_Passwords::hostile_name( 'panel-auto-infect' ) );
check( 'so is anything calling itself a shell', WPAQS_App_Passwords::hostile_name( 'wp shell' ) );
check( 'or a backdoor', WPAQS_App_Passwords::hostile_name( 'Backdoor' ) );
check( 'or an exploit', WPAQS_App_Passwords::hostile_name( 'exploit-kit' ) );
check( 'or the two old shell names', WPAQS_App_Passwords::hostile_name( 'c99' ) && WPAQS_App_Passwords::hostile_name( 'r57' ) );

// The benign side, and it is the reason the list is this short. `auto` and `panel` are both
// in the name this rule exists for and neither is in the list: an automation integration and
// a hosting control panel are ordinary things to name a credential after, and escalating
// those to critical puts the loudest severity on a shop's own order sync.
check(
	'auto is not enough on its own',
	! WPAQS_App_Passwords::hostile_name( 'store-auto-sync' ),
	'every automation integration on the fleet is named something like this'
);

check( 'and neither is panel', ! WPAQS_App_Passwords::hostile_name( 'hosting panel' ) );

// Word boundaries rather than substrings: "Nutshell CRM sync" contains "shell" and is
// somebody's actual integration.
check(
	'a name that merely contains one of the words is not a match',
	! WPAQS_App_Passwords::hostile_name( 'Nutshell CRM sync' ),
	'a substring match makes this rule fire on a real integration'
);

check( 'nor is Zapier', ! WPAQS_App_Passwords::hostile_name( 'Zapier' ) );
check( 'nor an unnamed password', ! WPAQS_App_Passwords::hostile_name( '' ) );

// The credential in the state the real one was in: in use, and used from the same address as
// a live session, so both of the other rules are right to say nothing.
$GLOBALS['passwords'][3] = array(
	array( 'uuid' => 'u-9', 'name' => 'panel-auto-infect', 'created' => 1755000000, 'last_used' => 1755000060, 'last_ip' => '203.0.113.9' ),
	array( 'uuid' => 'u-10', 'name' => 'Nutshell CRM sync', 'created' => 1740000000, 'last_used' => 1750000000, 'last_ip' => '203.0.113.9' ),
);

$named = array();

foreach ( WPAQS_App_Passwords::findings( array( 'id' => 3, 'login' => 'support' ), WPAQS_App_Passwords::for_user( 3 ), array( '203.0.113.9' ) ) as $finding ) {
	$named[ $finding['rule'] . '|' . $finding['target'] ] = $finding;
}

check(
	'the hostile name is reported even though every other check is silent about it',
	isset( $named['application_password_suspicious_name|user:3:app-password:u-9'] ),
	'it was used, and from a familiar address — which is why nothing else fired'
);

check(
	'as critical, which is the sibling plugin\'s severity for the same rule',
	isset( $named['application_password_suspicious_name|user:3:app-password:u-9'] )
		&& 'critical' === $named['application_password_suspicious_name|user:3:app-password:u-9']['severity']
);

check(
	'and the detail quotes the name',
	isset( $named['application_password_suspicious_name|user:3:app-password:u-9'] )
		&& false !== strpos( $named['application_password_suspicious_name|user:3:app-password:u-9']['detail'], 'panel-auto-infect' ),
	'the name is the finding'
);

check(
	'the integration beside it stays silent',
	! isset( $named['application_password_suspicious_name|user:3:app-password:u-10'] ),
	'"Nutshell CRM sync" contains "shell"'
);

check(
	'and nothing else fires on the hostile one either',
	! isset( $named['app_password_unused|user:3:app-password:u-9'] )
		&& ! isset( $named['app_password_foreign_ip|user:3:app-password:u-9'] ),
	'two findings about one fact is what grouping exists to stop'
);

// The name is checked whatever the answer to the other two rules is: a hostile name on a
// credential nobody has used yet is the same credential.
$GLOBALS['passwords'][4] = array(
	array( 'uuid' => 'u-11', 'name' => 'backdoor', 'created' => 1755000000, 'last_used' => 0, 'last_ip' => '' ),
);

$unused = array();

foreach ( WPAQS_App_Passwords::findings( array( 'id' => 4, 'login' => 'support' ), WPAQS_App_Passwords::for_user( 4 ), array() ) as $finding ) {
	$unused[ $finding['rule'] ] = $finding;
}

check(
	'a hostile name on an unused credential is still reported',
	isset( $unused['application_password_suspicious_name'] ),
	'the check sits outside the used/unused branch for exactly this'
);

check( 'and the unused rule reports it too, because that is a second fact', isset( $unused['app_password_unused'] ) );

// -------------------------------------------------------------------- timestamps

check( 'a timestamp renders as UTC', '2025-06-15 15:06 UTC' === WPAQS_App_Passwords::stamp( 1750000000 ), WPAQS_App_Passwords::stamp( 1750000000 ) );
check( 'and zero renders as never', 'never' === WPAQS_App_Passwords::stamp( 0 ) );

finish();
