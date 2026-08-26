<?php
/**
 * What leaves the site, and what deliberately does not.
 *
 * This plugin reads logins, email addresses and the addresses people sign in from, and the
 * console needs them: a console that hid them could not answer the question it exists for.
 * What never leaves is the material that would let somebody *become* one of these accounts
 * rather than recognise it — the password hash, the session verifier, and the raw
 * activation key behind a pending reset.
 *
 * That line is the whole reason the inventory may be exported at all, so it is asserted
 * rather than commented.
 */

require __DIR__ . '/bootstrap.php';

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ) {
		return json_encode( $data );
	}
}

require_once __DIR__ . '/../wordpress-access-quick-scan/includes/class-accounts.php';
require_once __DIR__ . '/../wordpress-access-quick-scan/includes/class-report.php';

function check_export( $ok, $name ) {
	check( $name, $ok, '' );
}


/*
 * The export carries who can get in, and not what would let somebody become them.
 *
 * The distinction is the whole reason this block may exist at all. A login, a role, a
 * capability, an address, a user agent and a date let a person *recognise* an account. The
 * password hash, the session verifier and the raw activation key let somebody *be* it.
 *
 * The verifier is the one that will be got wrong, because `WPAQS_Sessions::for_user()`
 * carries it deliberately — the plugin's own screen needs it to name a session it can end —
 * and any copy of that row that forgets to drop it sends live session identifiers to a
 * console that keeps them for 162 sites at once.
 */
$gathered = array(
	'accounts' => array(
		'total' => 1,
		'rows'  => array(
			array(
				'id'              => 7,
				'login'           => 'advision-admin',
				'email'           => 'k@example.com',
				'registered'      => '2026-08-20 20:20:58',
				'roles'           => array( 'administrator' ),
				'is_admin'        => true,
				'direct'          => array( 'edit_files' ),
				'reset_requested' => 1750000000,
			),
		),
	),
	'sessions'  => array(
		7 => array(
			array(
				'verifier'   => 'a-live-session-identifier',
				'ip'         => '203.0.113.9',
				'ua'         => 'Mozilla/5.0',
				'login'      => 1750000000,
				'expiration' => 1760000000,
				'expired'    => false,
				'readable'   => true,
			),
		),
	),
	'passwords' => array(
		7 => array(
			array(
				'uuid'      => '52ea9b9e-27a2-4951-ad43-cbdcfa0cdae5',
				'name'      => 'testing',
				'created'   => 1750000000,
				'last_used' => 0,
				'last_ip'   => '',
			),
		),
	),
	'findings'  => array(
		array(
			'rule'           => 'file_editing_enabled',
			'target'         => 'option:file_edit',
			'severity'       => 'medium',
			'title'          => 'Code can be edited from inside the admin',
			'detail'         => 'Neither constant is set.',
			'evidence'       => 'accounts_that_can_run_code=2',
			'recommendation' => 'Add define( DISALLOW_FILE_EDIT, true ).',
		),
	),
);

$export = WPAQS_Report::to_export_array( $gathered );
$json   = wp_json_encode( $export );

check_export(
	false === strpos( $json, 'a-live-session-identifier' ),
	'the export never carries a session verifier — it names a live session, and this leaves the site'
);

check_export(
	false === strpos( $json, 'verifier' ),
	'the export never carries the verifier key at all, so a careless copy of a session row is caught here'
);

check_export(
	'Add define( DISALLOW_FILE_EDIT, true ).' === $export['findings'][0]['recommendation'],
	'the export carries the recommendation — the console prints it and writes none of its own'
);

check_export(
	'203.0.113.9' === $export['access']['sessions'][0]['ip'],
	'a session keeps the address it was opened from — that is what lets somebody recognise it'
);

check_export(
	7 === $export['access']['sessions'][0]['account'],
	'a session names the account it belongs to, since the console flattens them into one list'
);

check_export(
	1750000000 === $export['access']['accounts'][0]['resetRequested'],
	'a pending reset carries when it was asked for'
);

check_export(
	false === strpos( $json, 'user_activation_key' ),
	'and never the key that would complete one'
);

check_export(
	'52ea9b9e-27a2-4951-ad43-cbdcfa0cdae5' === $export['access']['passwords'][0]['uuid'],
	'an application password carries the uuid that names it, which is not the password'
);

check_export(
	array( 'edit_files' ) === $export['access']['accounts'][0]['direct'],
	'capabilities written straight against an account are carried — the Users screen shows neither'
);

/*
 * The actions a finding offers, named and parameterised here.
 *
 * The console must not work this out. Over on the sibling it tried and drew "quarantine
 * this file" on an account and on a modified core file, having decided from a target whose
 * vocabulary it does not own. Here the targets are accounts, sessions, credentials and
 * settings, and what each supports is a fact about this plugin's action registry.
 */

function wpaqs_offered( $target ) {
	$export = WPAQS_Report::to_export_array(
		array(
			'findings' => array(
				array( 'rule' => 'r', 'target' => $target, 'severity' => 'high', 'title' => 't' ),
			),
		)
	);

	return $export['findings'][0]['actions'];
}

$password = wpaqs_offered( 'user:2:app-password:52ea9b9e-27a2-4951-ad43-cbdcfa0cdae5' );

check_export(
	1 === count( $password )
		&& 'revoke_password' === $password[0]['id']
		&& 2 === $password[0]['params']['user_id']
		&& '52ea9b9e-27a2-4951-ad43-cbdcfa0cdae5' === $password[0]['params']['uuid'],
	'an application password offers revoking, with the account and the uuid already in the parameters'
);

$sessions = wpaqs_offered( 'user:7:networks' );

check_export(
	1 === count( $sessions )
		&& 'end_sessions' === $sessions[0]['id']
		&& 7 === $sessions[0]['params']['user_id'],
	'a session finding offers ending every session on the account'
);

/*
 * The assertion that keeps the refusal intact through this door.
 *
 * `end_session()` — one session by name — takes the verifier, and the verifier is the field
 * this plugin refuses to send because it names a live session. Offering that action would
 * mean a console holding the name, so only the all-at-once form is offered and no parameter
 * anywhere carries a verifier.
 */
check_export(
	false === strpos( wp_json_encode( wpaqs_offered( 'user:7:session' ) ), 'verifier' ),
	'no action offers to end one named session, because naming one means sending its verifier'
);

$registration = wpaqs_offered( 'registration' );

check_export(
	2 === count( $registration )
		&& 'close_registration' === $registration[0]['id']
		&& 'park_default_role' === $registration[1]['id'],
	'open registration offers closing it or parking the default role'
);

check_export(
	array() === wpaqs_offered( 'option:file_edit' ),
	'a setting with no action of its own offers nothing — the next step is a line in wp-config.php'
);


/*
 * What a finding is about, as the coarser noun several findings can share.
 *
 * The console groups on this rather than learning the target grammar, which is this
 * plugin's and which `offers()` already reads. The assertion that matters is the last one:
 * five findings naming one account through four different target shapes have to arrive at
 * one identifier, because that is the coincidence the console cannot see today.
 */
function wpaqs_subject( $target ) {
	$method = new ReflectionMethod( 'WPAQS_Report', 'subject' );

	// Required on 7.4, a no-op from 8.1, deprecated from 8.5.
	if ( PHP_VERSION_ID < 80100 ) {
		$method->setAccessible( true );
	}

	return $method->invoke( null, $target );
}

$subjects = array(
	'a bare account'                    => array( 'user:1', array( 'id' => 'user:1', 'kind' => 'account' ) ),
	'one of its application passwords'  => array( 'user:1:app-password:52ea9b9e-27a2', array( 'id' => 'user:1', 'kind' => 'account' ) ),
	'its sessions'                      => array( 'user:1:sessions', array( 'id' => 'user:1', 'kind' => 'account' ) ),
	'its networks'                      => array( 'user:1:networks', array( 'id' => 'user:1', 'kind' => 'account' ) ),
	'a pending reset on it'             => array( 'user:1:reset', array( 'id' => 'user:1', 'kind' => 'account' ) ),
	'a different account'               => array( 'user:2', array( 'id' => 'user:2', 'kind' => 'account' ) ),
	'registration, however it is spelt' => array( 'registration', array( 'id' => 'option:users_can_register', 'kind' => 'setting' ) ),
	'and spelt the other way'           => array( 'option:users_can_register', array( 'id' => 'option:users_can_register', 'kind' => 'setting' ) ),
	'another setting'                   => array( 'option:default_role', array( 'id' => 'option:default_role', 'kind' => 'setting' ) ),
	'nothing at all'                    => array( '', array() ),
	'a target this does not know'       => array( 'file:wp-config.php', array() ),
	'an account with no id'             => array( 'user:', array() ),
	'an id that is not one'             => array( 'user:zero', array() ),
);

foreach ( $subjects as $why => $case ) {
	list( $target, $expect ) = $case;

	check_export( wpaqs_subject( $target ) === $expect, 'subject of ' . $why );
}

$shapes = array( 'user:1', 'user:1:sessions', 'user:1:networks', 'user:1:app-password:abc', 'user:1:reset' );
$ids    = array();

foreach ( $shapes as $shape ) {
	$found = wpaqs_subject( $shape );
	$ids[] = isset( $found['id'] ) ? $found['id'] : '';
}

check_export(
	array( 'user:1' ) === array_values( array_unique( $ids ) ),
	'five shapes of one account arrive at one identifier'
);

// And it reaches the export, not only the method.
$exported = WPAQS_Report::to_export_array(
	array(
		'findings' => array(
			array( 'rule' => 'sessions_many_networks', 'target' => 'user:1:networks', 'severity' => 'high' ),
			array( 'rule' => 'app_password_unused', 'target' => 'user:1:app-password:abc', 'severity' => 'medium' ),
		),
	)
);

check_export(
	isset( $exported['findings'][0]['subject']['id'] )
		&& 'user:1' === $exported['findings'][0]['subject']['id']
		&& 'user:1' === $exported['findings'][1]['subject']['id'],
	'two findings on one account leave with one subject between them'
);


finish();
