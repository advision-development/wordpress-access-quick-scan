<?php
/**
 * Registration posture: the combination fires, each half alone does not.
 *
 * Open registration alone is how membership sites work, and a custom default role alone is a
 * normal choice, so reporting either would make the plugin cry wolf on ordinary sites.
 *
 * The two fixes are tested by what they clear rather than by what they write: a finding with
 * no resolution is noise, so each one has to make the rule stop firing.
 */

function get_option( $name, $default = false ) {
	return isset( $GLOBALS['options'][ $name ] ) ? $GLOBALS['options'][ $name ] : $default;
}

function get_site_option( $name, $default = false ) {
	return isset( $GLOBALS['site_options'][ $name ] ) ? $GLOBALS['site_options'][ $name ] : $default;
}

function is_multisite() {
	return ! empty( $GLOBALS['multisite'] );
}

function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['options'][ $name ] = $value;

	return true;
}

function get_role( $name ) {
	return isset( $GLOBALS['roles'][ $name ] ) ? (object) array( 'capabilities' => $GLOBALS['roles'][ $name ] ) : null;
}

require __DIR__ . '/bootstrap.php';

load_class( 'findings' );
load_class( 'registration' );

$GLOBALS['roles'] = array(
	'subscriber'    => array( 'read' => true ),
	'contributor'   => array( 'read' => true, 'edit_posts' => true ),
	'author'        => array( 'read' => true, 'edit_posts' => true, 'publish_posts' => true, 'upload_files' => true ),
	'administrator' => array( 'read' => true, 'edit_posts' => true, 'manage_options' => true, 'install_plugins' => true, 'edit_users' => true ),
	// A role that grants nothing, which some plugins create for tagging accounts.
	'member'        => array( 'read' => true ),
);

/**
 * Run the rule against a pair of settings.
 *
 * @param mixed  $open Value of users_can_register.
 * @param string $role Value of default_role.
 * @return array
 */
function with( $open, $role ) {
	$GLOBALS['options'] = array(
		'users_can_register' => $open,
		'default_role'       => $role,
	);

	return WPAQS_Registration::findings();
}

// ---------------------------------------------------------------- the combination

$fires = with( 1, 'author' );

check( 'open registration plus a privileged role fires', 1 === count( $fires ), (string) count( $fires ) );
check( 'at critical', 1 === count( $fires ) && 'critical' === $fires[0]['severity'] );
check( 'and the evidence names the role', 1 === count( $fires ) && false !== strpos( $fires[0]['evidence'], 'author' ) );
check( 'and what it grants', 1 === count( $fires ) && false !== strpos( $fires[0]['evidence'], 'publish_posts' ) );

// Contributor is the floor on purpose: putting content on the site is what the campaigns
// behind the sibling plugin existed to do.
check( 'contributor counts as privileged', 1 === count( with( 1, 'contributor' ) ) );
check( 'administrator certainly does', 1 === count( with( 1, 'administrator' ) ) );

// ------------------------------------------------------------------ benign halves

check(
	'open registration with subscriber is silent',
	array() === with( 1, 'subscriber' ),
	'this is how every membership site is configured'
);

check(
	'a privileged default role with registration closed is silent',
	array() === with( 0, 'author' ),
	'nobody can reach it'
);

check( 'both off is silent', array() === with( 0, 'subscriber' ) );

// A role that grants nothing beyond read is not privileged, whatever it is called.
check( 'a custom role granting only read is silent', array() === with( 1, 'member' ) );

// A default_role naming a role nobody registered grants nothing, so it is not this rule's
// business — reporting it would be reporting a different problem under this heading.
check( 'an unregistered default role is silent', array() === with( 1, 'ghost-role' ) );

// ----------------------------------------------------------------- option shapes

// WordPress stores this option as a string, and '0' must not read as true.
check( "the string '0' is closed", array() === with( '0', 'author' ), 'a string zero is falsy in WordPress options' );
check( "the string '1' is open", 1 === count( with( '1', 'author' ) ) );
check( 'an absent option is closed', array() === with( false, 'author' ) );

// -------------------------------------------------------------------- state read

$GLOBALS['options'] = array( 'users_can_register' => 1, 'default_role' => 'author' );
$state              = WPAQS_Registration::state();

check( 'state reports the role', 'author' === $state['role'] );
check( 'state reports open', true === $state['open'] );
check( 'state lists the privileged capabilities', in_array( 'publish_posts', $state['caps'], true ) );

// ------------------------------------------------------------------ multisite

// WordPress does not consult users_can_register on a network: the `registration` site option
// decides. Reading the wrong one reports a closed network as open, or the reverse.
$GLOBALS['multisite']    = true;
$GLOBALS['options']      = array( 'users_can_register' => 0, 'default_role' => 'author' );
$GLOBALS['site_options'] = array( 'registration' => 'user' );

check(
	'on a network the site option decides',
	1 === count( WPAQS_Registration::findings() ),
	'users_can_register is 0 here and WordPress ignores it'
);

$GLOBALS['site_options'] = array( 'registration' => 'none' );

check( 'and a closed network is silent', array() === WPAQS_Registration::findings() );

check(
	'closing registration is refused on a network',
	false !== stripos( WPAQS_Registration::close()['error'], 'network setting' ),
	'it governs every site, so a per-site screen must not offer it'
);

$GLOBALS['multisite'] = false;

// ------------------------------------------------------------------- the fixes

$GLOBALS['options'] = array( 'users_can_register' => 1, 'default_role' => 'author' );

$parked = WPAQS_Registration::park_default_role();

check( 'parking the default role succeeds', '' === $parked['error'], $parked['error'] );
check( 'and the option is now subscriber', 'subscriber' === $GLOBALS['options']['default_role'] );
check( 'which clears the finding', array() === WPAQS_Registration::findings(), 'a finding with no resolution is noise' );

// Registration itself is left alone: a membership site needs it open, and the role was the
// problem.
check( 'and registration is still open', 1 === $GLOBALS['options']['users_can_register'] );

$GLOBALS['options'] = array( 'users_can_register' => 1, 'default_role' => 'author' );

$closed = WPAQS_Registration::close();

check( 'closing registration succeeds', '' === $closed['error'], $closed['error'] );
check( 'and the option is off', 0 === $GLOBALS['options']['users_can_register'] );
check( 'which also clears the finding', array() === WPAQS_Registration::findings() );

// Neither fix should claim to have done something when there was nothing to do.
check( 'closing an already closed site is refused', '' !== WPAQS_Registration::close()['error'] );

$GLOBALS['options'] = array( 'users_can_register' => 1, 'default_role' => 'subscriber' );

check(
	'parking a role that is already unprivileged is refused',
	'' !== WPAQS_Registration::park_default_role()['error']
);

finish();
