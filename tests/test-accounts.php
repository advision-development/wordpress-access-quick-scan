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

/**
 * Role capabilities, so the code-capability reader has something to read.
 *
 * @param string $name Role name.
 * @return object|null
 */
function get_role( $name ) {
	$roles = array(
		'administrator' => array( 'install_plugins' => true, 'edit_plugins' => true, 'update_core' => true, 'edit_users' => true ),
		'editor'        => array( 'edit_others_posts' => true, 'publish_posts' => true ),
		'subscriber'    => array( 'read' => true ),
		'shop_manager'  => array( 'edit_others_posts' => true ),
	);

	return isset( $roles[ $name ] ) ? (object) array( 'capabilities' => $roles[ $name ] ) : null;
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

// ------------------------------------------------- who can run code

// Effective capabilities: a grant made straight against the account counts the same as one
// that arrived with a role, and the Users screen shows neither.
$holders = array();

foreach ( WPAQS_Accounts::code_holders( $accounts ) as $holder ) {
	$holders[ $holder['account']['login'] ] = $holder;
}

check( 'an administrator can run code', isset( $holders['owner'] ), implode( ',', array_keys( $holders ) ) );

check(
	'and a subscriber cannot, whatever else was granted to it',
	! isset( $holders['quiet'] ),
	'edit_users is not a code capability'
);

check( 'a reader with a harmless direct grant cannot', ! isset( $holders['reader'] ) );

// A direct grant of a code capability puts a subscriber on this list, which is the whole
// point of reading capabilities rather than roles.
$GLOBALS['users'][21] = user( 21, 'sneaky', array( 'subscriber' ), array( 'subscriber' => true, 'install_plugins' => true ), $old );

$with_direct = array();

foreach ( WPAQS_Accounts::code_holders( WPAQS_Accounts::all() ) as $holder ) {
	$with_direct[ $holder['account']['login'] ] = $holder;
}

check( 'a direct code grant puts a subscriber on the list', isset( $with_direct['sneaky'] ) );
check( 'and it is marked as granted directly', isset( $with_direct['sneaky'] ) && array( 'install_plugins' ) === $with_direct['sneaky']['direct'] );
check( 'while a role holder is not', isset( $with_direct['owner'] ) && array() === $with_direct['owner']['direct'] );

unset( $GLOBALS['users'][21] );

// ------------------------------------------------- file editing posture

check( 'file editing reads as allowed when no constant says otherwise', WPAQS_Accounts::file_editing_allowed() );

// ------------------------------------------------- duplicate emails

// WordPress refuses a second account on an address already in use, so a duplicate did not
// arrive through WordPress.
$GLOBALS['users'][31] = user( 31, 'twin-a', array( 'subscriber' ), array( 'subscriber' => true ), $old );
$GLOBALS['users'][33] = user( 33, 'twin-b', array( 'subscriber' ), array( 'subscriber' => true ), $old );
$GLOBALS['users'][33]->user_email = 'twin-a@example.test';

$dupes = WPAQS_Accounts::duplicate_emails( WPAQS_Accounts::all() );

check( 'two accounts on one address are reported', isset( $dupes['twin-a@example.test'] ) );
check( 'and both logins are named', isset( $dupes['twin-a@example.test'] ) && 2 === count( $dupes['twin-a@example.test'] ) );

unset( $GLOBALS['users'][31], $GLOBALS['users'][33] );

check( 'distinct addresses are silent', array() === WPAQS_Accounts::duplicate_emails( WPAQS_Accounts::all() ) );

// ------------------------------------------------- lookalike logins

// A digit imitates more than one letter, so both the digit and every letter it imitates
// fold to one representative. Mapping only digits left adm1n and admin apart.
check( 'adm1n and admin fold together', WPAQS_Accounts::fold_login( 'adm1n' ) === WPAQS_Accounts::fold_login( 'admin' ), WPAQS_Accounts::fold_login( 'adm1n' ) . ' vs ' . WPAQS_Accounts::fold_login( 'admin' ) );
check( 'and so do admln and admin', WPAQS_Accounts::fold_login( 'admln' ) === WPAQS_Accounts::fold_login( 'admin' ) );
check( 'r00t folds onto root', WPAQS_Accounts::fold_login( 'r00t' ) === WPAQS_Accounts::fold_login( 'root' ) );
check( 'ke5ha folds onto kesha', WPAQS_Accounts::fold_login( 'ke5ha' ) === WPAQS_Accounts::fold_login( 'kesha' ) );
check( 'two unrelated logins do not fold together', WPAQS_Accounts::fold_login( 'editor' ) !== WPAQS_Accounts::fold_login( 'author' ) );

$GLOBALS['users'][41] = user( 41, 'owner', array( 'administrator' ), array( 'administrator' => true ), $old );
$GLOBALS['users'][43] = user( 43, '0wner', array( 'subscriber' ), array( 'subscriber' => true ), $old );

$pairs = WPAQS_Accounts::lookalike_logins( WPAQS_Accounts::all() );

check( 'a login imitating an administrator is reported', 1 === count( $pairs ), (string) count( $pairs ) );
check( 'and it is the imitator that is named, not the administrator', 1 === count( $pairs ) && '0wner' === $pairs[0]['row']['login'] );
check( 'and it says which account it resembles', 1 === count( $pairs ) && 'owner' === $pairs[0]['privileged'] );

unset( $GLOBALS['users'][43] );

// The benign case that keeps this quiet: two similar logins where neither is privileged.
$GLOBALS['users'][45] = user( 45, 'brand1', array( 'subscriber' ), array( 'subscriber' => true ), $old );
$GLOBALS['users'][47] = user( 47, 'brandl', array( 'subscriber' ), array( 'subscriber' => true ), $old );

check(
	'two similar unprivileged logins are silent',
	array() === WPAQS_Accounts::lookalike_logins( WPAQS_Accounts::all() ),
	'a site with several brands has near-collisions for honest reasons'
);

unset( $GLOBALS['users'][45], $GLOBALS['users'][47], $GLOBALS['users'][41] );

finish();
