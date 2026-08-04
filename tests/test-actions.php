<?php
/**
 * The guards on the two actions.
 *
 * The refusal that matters is the self one: ending your own sessions signs you out of the
 * screen you are working from, in the middle of whatever brought you here. The other thing
 * worth pinning is that nothing in this plugin deletes an account.
 */

function get_userdata( $id ) {
	return isset( $GLOBALS['users'][ (int) $id ] ) ? $GLOBALS['users'][ (int) $id ] : false;
}

function get_current_user_id() {
	return (int) $GLOBALS['current'];
}

function current_user_can( $cap, $object_id = null ) {
	return ! empty( $GLOBALS['caps'][ $cap ] );
}

function is_multisite() {
	return (bool) $GLOBALS['multisite'];
}

require __DIR__ . '/bootstrap.php';

load_class( 'controller' );

$GLOBALS['users'] = array(
	1 => (object) array( 'ID' => 1, 'user_login' => 'owner' ),
	7 => (object) array( 'ID' => 7, 'user_login' => 'bot' ),
);

/**
 * Set the acting user and their capabilities.
 *
 * @param int   $current   Signed-in user id.
 * @param array $caps      Capability => true.
 * @param bool  $multisite Whether this is a network.
 * @return void
 */
function acting( $current, array $caps, $multisite = false ) {
	$GLOBALS['current']   = $current;
	$GLOBALS['caps']      = $caps;
	$GLOBALS['multisite'] = $multisite;
}

// --------------------------------------------------------------- session refusals

acting( 1, array( 'manage_options' => true, 'edit_user' => true ) );

check( 'another account can have its sessions ended', '' === WPAQS_Controller::session_refusal( 7 ) );

check(
	'your own account is refused',
	'self' === WPAQS_Controller::session_refusal( 1 ),
	'ending your own sessions signs you out of this screen'
);

check( 'and the wording says you would be signed out', false !== stripos( WPAQS_Controller::refusal_text( 'self' ), 'sign you out' ) );

check( 'an account that does not exist is refused', 'missing' === WPAQS_Controller::session_refusal( 404 ) );
check( 'id zero is refused', 'missing' === WPAQS_Controller::session_refusal( 0 ) );
check( 'a negative id is refused', 'missing' === WPAQS_Controller::session_refusal( -5 ) );

acting( 1, array( 'manage_options' => true ) );
check( 'an account you cannot edit is refused', 'nocap' === WPAQS_Controller::session_refusal( 7 ) );

check( 'every refusal has wording', '' !== WPAQS_Controller::refusal_text( 'missing' )
	&& '' !== WPAQS_Controller::refusal_text( 'self' )
	&& '' !== WPAQS_Controller::refusal_text( 'nocap' ) );

// ------------------------------------------------------------------ capabilities

acting( 1, array( 'manage_options' => true ) );
check( 'a single-site administrator may act', WPAQS_Controller::user_can_act() );

acting( 1, array() );
check( 'somebody without manage_options may not', ! WPAQS_Controller::user_can_act() );

// On a network every subsite administrator holds manage_options, and a user is a
// network-wide object: ending sessions affects every site that account can reach.
acting( 1, array( 'manage_options' => true ), true );
check(
	'on multisite manage_options alone is not enough',
	! WPAQS_Controller::user_can_act(),
	'a subsite administrator would otherwise act on the whole network'
);

acting( 1, array( 'manage_options' => true, 'manage_network_users' => true ), true );
check( 'with the network capability they may', WPAQS_Controller::user_can_act() );

// ------------------------------------------------------------ nothing deletes a user

// Tokenised rather than grepped: the class docblock names wp_delete_user() to record why it
// is never called, and an assertion that cannot tell a mention from a call fails on the
// comment written to prevent the call.
$source = file_get_contents( WPAQS_DIR . 'includes/class-controller.php' );
$code   = '';

foreach ( token_get_all( $source ) as $token ) {
	if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
		continue;
	}

	$code .= is_array( $token ) ? $token[1] : $token;
}

check(
	'nothing here calls a user delete',
	false === strpos( $code, 'wp_delete_user' ) && false === strpos( $code, 'wpmu_delete_user' ),
	'the account\'s content is the record of what it did'
);

check(
	'and the file says why it does not',
	false !== strpos( $source, 'wp_delete_user' ),
	'a deliberate omission with no comment reads as an oversight'
);

// Neither action may be reachable without a nonce, and each nonce is scoped to what it
// acts on: one lifted from another row must not work.
check(
	'ending sessions is scoped to the account',
	false !== strpos( $code, "'-end-sessions-' . \$user_id" ),
	'an unscoped nonce works on every row'
);

check(
	'revoking is scoped to the account and the password',
	false !== strpos( $code, "'-revoke-' . \$user_id . '-' . \$uuid" )
);

check(
	'the password is checked against live state',
	false !== strpos( $code, 'WPAQS_App_Passwords::exists' ),
	'a request naming a password that is gone must not be acted on'
);

finish();
