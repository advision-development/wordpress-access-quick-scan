<?php
/**
 * Accounts and the capabilities they actually hold.
 *
 * The point of this reader is that the Users screen shows roles and a role is not what an
 * account can do. So the assertions that matter are: a capability inherited from a role is
 * *not* reported, one written against the account *is*, and a capability explicitly denied
 * is not mistaken for a grant.
 *
 * The rule is deliberately not deterministic — add_cap() is legitimate — so the benign
 * case is a real one rather than a formality.
 */

/**
 * Roles registered on this fake site.
 *
 * @return object
 */
function wp_roles() {
	return (object) array(
		'roles' => array(
			'administrator' => array( 'name' => 'Administrator' ),
			'editor'        => array( 'name' => 'Editor' ),
			'subscriber'    => array( 'name' => 'Subscriber' ),
			'shop_manager'  => array( 'name' => 'Shop Manager' ),
		),
	);
}

function count_users() {
	return array( 'total_users' => count( $GLOBALS['users'] ) );
}

function get_users( $args = array() ) {
	$users = array_values( $GLOBALS['users'] );
	$limit = isset( $args['number'] ) ? (int) $args['number'] : count( $users );

	return array_slice( $users, 0, $limit );
}

require __DIR__ . '/bootstrap.php';

load_class( 'findings' );
load_class( 'accounts' );

/**
 * A user with the shape WPAQS_Accounts reads.
 *
 * @param int    $id         Id.
 * @param string $login      Login.
 * @param array  $roles      Role names.
 * @param array  $caps       wp_capabilities contents.
 * @param string $registered Registration date.
 * @return object
 */
function user( $id, $login, array $roles, array $caps, $registered = '2020-01-01 00:00:00' ) {
	return (object) array(
		'ID'              => $id,
		'user_login'      => $login,
		'user_email'      => $login . '@example.test',
		'user_registered' => $registered,
		'roles'           => $roles,
		'caps'            => $caps,
	);
}

$recent = gmdate( 'Y-m-d H:i:s', time() - ( 3 * DAY_IN_SECONDS ) );
$old    = gmdate( 'Y-m-d H:i:s', time() - ( 400 * DAY_IN_SECONDS ) );

$GLOBALS['users'] = array(
	// Ordinary: capabilities come from the role, and the meta holds only the role name.
	1 => user( 1, 'owner', array( 'administrator' ), array( 'administrator' => true ), $old ),
	// The case this reader exists for: reads as Subscriber, can edit users.
	7 => user( 7, 'quiet', array( 'subscriber' ), array( 'subscriber' => true, 'edit_users' => true ), $old ),
	// A plugin's own role. Registered, so it is not a direct grant.
	9 => user( 9, 'shopkeeper', array( 'shop_manager' ), array( 'shop_manager' => true ), $old ),
	// A denial, not a grant.
	11 => user( 11, 'limited', array( 'editor' ), array( 'editor' => true, 'publish_posts' => false ), $old ),
	// Direct grant of something that does not change the site.
	13 => user( 13, 'reader', array( 'subscriber' ), array( 'subscriber' => true, 'read_private_pages' => true ), $old ),
	// An administrator that appeared this month.
	17 => user( 17, 'bot', array( 'administrator' ), array( 'administrator' => true ), $recent ),
);

$accounts = WPAQS_Accounts::all();
$byid     = array();

foreach ( $accounts['rows'] as $row ) {
	$byid[ $row['id'] ] = $row;
}

// ------------------------------------------------------------- direct capabilities

check( 'every account is read', 6 === count( $accounts['rows'] ), (string) count( $accounts['rows'] ) );
check( 'the cap is not reported when it was not reached', ! $accounts['capped'] );

check( 'a role name is not a direct capability', array() === $byid[1]['direct'], implode( ',', $byid[1]['direct'] ) );
check( 'a capability written against the account is', array( 'edit_users' ) === $byid[7]['direct'], implode( ',', $byid[7]['direct'] ) );
check( 'a plugin-registered role is not a direct capability', array() === $byid[9]['direct'], implode( ',', $byid[9]['direct'] ) );

// A capability set to false is a denial. Reporting it as granted would be backwards.
check( 'a denied capability is not a grant', array() === $byid[11]['direct'], implode( ',', $byid[11]['direct'] ) );

check( 'a harmless direct capability is still listed', array( 'read_private_pages' ) === $byid[13]['direct'] );

// ------------------------------------------------------------------- what is notable

check( 'edit_users is notable', array( 'edit_users' ) === WPAQS_Accounts::notable( array( 'edit_users' ) ) );
check( 'read_private_pages is not', array() === WPAQS_Accounts::notable( array( 'read_private_pages' ) ) );
check( 'install_plugins is notable', array( 'install_plugins' ) === WPAQS_Accounts::notable( array( 'install_plugins' ) ) );

// ------------------------------------------------------------------------- findings

$findings = WPAQS_Accounts::findings( $accounts );
$byrule   = array();

foreach ( $findings as $finding ) {
	$byrule[ $finding['rule'] . '|' . $finding['target'] ] = $finding;
}

check(
	'the subscriber who can edit users is reported',
	isset( $byrule['capability_outside_role|user:7'] ),
	'this is the whole reason the reader exists'
);

check(
	'and the evidence names the capability',
	isset( $byrule['capability_outside_role|user:7'] )
		&& false !== strpos( $byrule['capability_outside_role|user:7']['evidence'], 'edit_users' )
);

// The benign cases. A rule without these is not finished.
check( 'the plain administrator is not reported', ! isset( $byrule['capability_outside_role|user:1'] ) );
check( 'the plugin role holder is not reported', ! isset( $byrule['capability_outside_role|user:9'] ) );
check( 'the denied capability is not reported', ! isset( $byrule['capability_outside_role|user:11'] ) );
check( 'a harmless direct capability is not reported', ! isset( $byrule['capability_outside_role|user:13'] ), 'read_private_pages changes nothing' );

check( 'the recent administrator is reported', isset( $byrule['recent_administrator|user:17'] ) );
check( 'and only as context', isset( $byrule['recent_administrator|user:17'] ) && 'info' === $byrule['recent_administrator|user:17']['severity'] );
check( 'the long-standing administrator is not', ! isset( $byrule['recent_administrator|user:1'] ) );

// A recent *subscriber* is not an administrator finding.
check( 'a recent non-administrator is not reported as one', ! isset( $byrule['recent_administrator|user:7'] ) );

finish();
