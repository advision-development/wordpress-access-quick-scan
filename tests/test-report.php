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

finish();
