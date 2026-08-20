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

function get_userdata( $id ) {
	return isset( $GLOBALS['users'][ (int) $id ] ) ? $GLOBALS['users'][ (int) $id ] : false;
}

function get_current_user_id() {
	return (int) $GLOBALS['acting'];
}

function current_user_can( $cap, $object_id = null ) {
	return ! empty( $GLOBALS['acting_caps'][ $cap ] );
}
/**
 * Asked about a named user rather than the session. False for user 0, as WordPress
 * answers, so a lenient stub cannot let an implementation that forgot to skip the
 * check for a caller with no user pass anyway.
 */
function user_can( $user, $cap, $object_id = null ) {
	return 0 === (int) $user ? false : ! empty( $GLOBALS['acting_caps'][ $cap ] );
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
	return new Stub_User( $id, $login, $roles, $caps, $registered );
}

/**
 * A stand-in for WP_User with the surface the reader and the action touch.
 */
class Stub_User {

	public $ID;
	public $user_login;
	public $user_email;
	public $user_registered;
	public $roles;
	public $caps;
	public $user_activation_key = '';

	public function __construct( $id, $login, array $roles, array $caps, $registered ) {
		$this->ID              = $id;
		$this->user_login      = $login;
		$this->user_email      = $login . '@example.test';
		$this->user_registered = $registered;
		$this->roles           = $roles;
		$this->caps            = $caps;
	}

	public function remove_cap( $cap ) {
		unset( $this->caps[ $cap ] );
	}
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

// ------------------------------------------------- removing a direct capability

$GLOBALS['acting']      = 1;
$GLOBALS['acting_caps'] = array( 'manage_options' => true, 'edit_user' => true );

// The subscriber that can edit users: the finding asks the operator to confirm the grant, and
// this is what confirming sometimes ends in.
$removed = WPAQS_Accounts::remove_direct_capability( 7, 'edit_users', 1 );

check( 'a directly granted capability can be taken off', '' === $removed['error'], $removed['error'] );
check( 'and it is gone from the account', ! isset( $GLOBALS['users'][7]->caps['edit_users'] ) );
check( 'while the role stays', isset( $GLOBALS['users'][7]->caps['subscriber'] ), 'removing the role was never this button\'s job' );

check(
	'which clears the finding',
	! isset( array_flip( array_map( function ( $f ) { return $f['rule'] . '|' . $f['target']; }, WPAQS_Accounts::findings( WPAQS_Accounts::all() ) ) )['capability_outside_role|user:7'] ),
	'a finding with no resolution is noise'
);

// Live, not from a report: a capability already removed is not something to act on.
check( 'removing it twice is refused', '' !== WPAQS_Accounts::remove_direct_capability( 7, 'edit_users', 1 )['error'] );

// A capability that comes from the role was never this button's to take: removing it from the
// account changes nothing, because WordPress reads it from the role again.
// Account 17 rather than account 1: acting as 1, asking about 1 hits the self refusal and
// answers a different question — which is what the first version of this assertion did.
check(
	'a capability that comes from the role is refused',
	'' !== WPAQS_Accounts::remove_direct_capability( 17, 'install_plugins', 1 )['error'],
	'it would appear to work and change nothing'
);

check(
	'and the refusal says where the grant comes from',
	false !== stripos( WPAQS_Accounts::remove_direct_capability( 17, 'install_plugins', 1 )['error'], 'comes from its role' )
);

// Your own account: stripping your own manage_options locks you out of the screen.
$GLOBALS['acting'] = 13;
$GLOBALS['users'][13]->caps['edit_users'] = true;

check(
	'your own account is refused',
	'' !== WPAQS_Accounts::remove_direct_capability( 13, 'edit_users', 13 )['error'],
	'you can remove your own access to this screen'
);

// The same question a command asks: with nobody signed in there is no self to protect,
// and the guard has to stop applying rather than compare against user 0.
check(
	'with no acting user there is no self to protect',
	'' === WPAQS_Accounts::remove_direct_capability( 13, 'edit_users', 0 )['error'],
	WPAQS_Accounts::remove_direct_capability( 13, 'edit_users', 0 )['error']
);
$GLOBALS['users'][13]->caps['edit_users'] = true;

check( 'and the capability is still there', isset( $GLOBALS['users'][13]->caps['edit_users'] ) );

$GLOBALS['acting'] = 1;

check( 'an account that does not exist is refused', '' !== WPAQS_Accounts::remove_direct_capability( 404, 'edit_users', 1 )['error'] );

// Only capabilities the screen reports get buttons, so a request for anything else did not
// come from one.
$GLOBALS['users'][13]->caps['read_private_pages'] = true;

check(
	'a capability the screen never offers is refused',
	'' !== WPAQS_Accounts::remove_direct_capability( 13, 'read_private_pages', 1 )['error'],
	'the screen only reports notable ones, so only those have buttons'
);

$GLOBALS['acting_caps'] = array( 'manage_options' => true );

check( 'an account you cannot edit is refused', '' !== WPAQS_Accounts::remove_direct_capability( 13, 'edit_users', 1 )['error'] );

// The mirror-image failure: through current_user_can() under cron this is false for
// everything and no command would ever remove a capability.
check(
	'a caller with no user is not refused for capabilities',
	false === stripos( WPAQS_Accounts::remove_direct_capability( 13, 'edit_users', 0 )['error'], 'does not let you edit' ),
	WPAQS_Accounts::remove_direct_capability( 13, 'edit_users', 0 )['error']
);

// ------------------------------------------------------- pending password reset

/**
 * The pending-reset findings for one activation key.
 *
 * @param string $key Value of user_activation_key.
 * @return array
 */
function resets( $key ) {
	$GLOBALS['users'][1]->user_activation_key = $key;

	$found = array();

	foreach ( WPAQS_Accounts::findings( WPAQS_Accounts::all() ) as $finding ) {
		if ( 'pending_password_reset' === $finding['rule'] ) {
			$found[] = $finding;
		}
	}

	$GLOBALS['users'][1]->user_activation_key = '';

	return $found;
}

// retrieve_password() writes the key and reset_password() clears it, so a key still sitting
// there means somebody asked for a reset link on an administrator account and it was never
// used. On a site behaving oddly that is either a locked-out colleague or an attempt.
$asked = resets( ( time() - HOUR_IN_SECONDS ) . ':$P$Babcdefgh' );

check( 'a pending reset on an administrator is reported', 1 === count( $asked ), (string) count( $asked ) );
check( 'and the evidence names the account', 1 === count( $asked ) && false !== strpos( $asked[0]['evidence'], 'owner' ) );
check( 'and when it was asked for', 1 === count( $asked ) && false !== strpos( $asked[0]['evidence'], 'UTC' ) );

// The benign case, and the one that matters: almost every site has nobody mid-reset, and a
// rule that fires on all of them is a rule nobody reads.
check(
	'an account with no pending reset is silent',
	array() === resets( '' ),
	'this is the state of every account on a site where nobody is resetting anything'
);

// Older WordPress stored the hash with no timestamp. That still says a reset is pending, so
// reporting nothing would clear an account that is not clear.
$undated = resets( '$P$Babcdefghijklmnop' );

check( 'an undated key is still reported', 1 === count( $undated ), (string) count( $undated ) );
check(
	'and the evidence says the hour is unknown rather than showing 1970',
	1 === count( $undated ) && false !== strpos( $undated[0]['evidence'], 'unknown' ),
	'gmdate( 0 ) would read as a reset requested in 1970'
);

finish();
