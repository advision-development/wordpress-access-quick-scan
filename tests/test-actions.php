<?php
/**
 * The guards on the actions.
 *
 * The refusal that matters is the self one: ending your own sessions signs you out of the
 * screen you are working from, mid-incident. The other things worth pinning are that every
 * nonce is scoped to what its action touches — one lifted from another row must not work —
 * and that nothing in this plugin deletes an account.
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
/**
 * Asked about a named user rather than the session. False for user 0, as WordPress
 * answers, so a lenient stub cannot let an implementation that forgot to skip the
 * check for a caller with no user pass anyway.
 */
function user_can( $user, $cap, $object_id = null ) {
	return 0 === (int) $user ? false : ! empty( $GLOBALS['caps'][ $cap ] );
}

function is_multisite() {
	return (bool) $GLOBALS['multisite'];
}

require __DIR__ . '/bootstrap.php';

/**
 * Sessions, recorded rather than performed.
 */
class WPAQS_Sessions {
	public static function for_user( $user_id ) {
		return isset( $GLOBALS['sessions'][ (int) $user_id ] ) ? $GLOBALS['sessions'][ (int) $user_id ] : array();
	}

	public static function end_one( $user_id, $verifier ) {
		if ( ! empty( $GLOBALS['end_one_error'] ) ) {
			return array( 'error' => $GLOBALS['end_one_error'] );
		}

		$GLOBALS['ended_one'][] = $user_id . ':' . $verifier;

		return array( 'error' => '' );
	}
}

class WP_Session_Tokens {
	private $user_id;

	private function __construct( $user_id ) {
		$this->user_id = $user_id;
	}

	public static function get_instance( $user_id ) {
		return new self( $user_id );
	}

	public function destroy_all() {
		$GLOBALS['destroyed'][] = $this->user_id;
	}
}

/**
 * Application passwords, revoked into globals rather than a database.
 */
class WPAQS_App_Passwords {
	public static function available() {
		return empty( $GLOBALS['app_passwords_unavailable'] );
	}

	public static function exists( $user_id, $uuid ) {
		return in_array( $user_id . ':' . $uuid, $GLOBALS['app_passwords'], true );
	}
}

class WP_Error {}

class WP_Application_Passwords {
	public static function delete_application_password( $user_id, $uuid ) {
		if ( ! empty( $GLOBALS['revoke_fails'] ) ) {
			return new WP_Error();
		}

		$GLOBALS['revoked'][] = $user_id . ':' . $uuid;

		return true;
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

/**
 * The two settings actions, recorded rather than written.
 */
class WPAQS_Registration {
	public static function park_default_role() {
		$GLOBALS['parked'] = true;

		return array( 'error' => isset( $GLOBALS['park_error'] ) ? $GLOBALS['park_error'] : '' );
	}

	public static function close() {
		$GLOBALS['closed'] = true;

		return array( 'error' => isset( $GLOBALS['close_error'] ) ? $GLOBALS['close_error'] : '' );
	}
}

class WPAQS_Accounts {
	public static function remove_direct_capability( $user_id, $cap, $actor ) {
		$GLOBALS['cap_actor_seen'][] = $actor;

		return array( 'error' => isset( $GLOBALS['cap_error'] ) ? $GLOBALS['cap_error'] : '' );
	}
}

load_class( 'controller' );
load_class( 'actions' );

$GLOBALS['app_passwords'] = array( '7:abcd-1234' );
$GLOBALS['revoked']       = array();
$GLOBALS['cap_actor_seen'] = array();

$GLOBALS['sessions']      = array();
$GLOBALS['destroyed']     = array();
$GLOBALS['ended_one']     = array();
$GLOBALS['end_one_error'] = '';

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

check( 'another account can have its sessions ended', '' === WPAQS_Controller::session_refusal( 7, 1 ) );

check(
	'your own account is refused',
	'self' === WPAQS_Controller::session_refusal( 1, 1 ),
	'ending your own sessions signs you out of this screen'
);

// A signed remote command runs under cron with nobody logged in, so every guard that
// reads a session has to be asked what it means when there is none. `self` protects an
// operator from signing themselves out of the screen they are pressing the button on.
// There is no screen behind a command, so it has nothing to protect and must stop
// applying — rather than silently comparing against user 0.
check( 'with no acting user there is no self to protect', 'self' !== WPAQS_Controller::session_refusal( 1, 0 ) );
check( 'and the account is otherwise eligible', '' === WPAQS_Controller::session_refusal( 1, 0 ), WPAQS_Controller::session_refusal( 1, 0 ) );

check( 'and the wording says you would be signed out', false !== stripos( WPAQS_Controller::refusal_text( 'self' ), 'sign you out' ) );

check( 'an account that does not exist is refused', 'missing' === WPAQS_Controller::session_refusal( 404, 1 ) );
check( 'id zero is refused', 'missing' === WPAQS_Controller::session_refusal( 0, 1 ) );
check( 'a negative id is refused', 'missing' === WPAQS_Controller::session_refusal( -5, 1 ) );

acting( 1, array( 'manage_options' => true ) );
check( 'an account you cannot edit is refused', 'nocap' === WPAQS_Controller::session_refusal( 7, 1 ) );

// The mirror-image failure, and the more dangerous one: read through current_user_can()
// under cron this is false for everything, so a command would be refused outright — a
// control that looks like it works and never does.
check( 'a caller with no user is not refused for capabilities', 'nocap' !== WPAQS_Controller::session_refusal( 7, 0 ) );

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

// ------------------------------------------------------------- ending sessions

acting( 1, array( 'manage_options' => true, 'edit_user' => true ) );
$GLOBALS['sessions'] = array( 7 => array( 'a', 'b', 'c' ) );
$GLOBALS['destroyed'] = array();

$r = WPAQS_Actions::end_sessions( 7, 1 );
check( 'an account\'s sessions are ended', true === $r['ok'] && 'sessions-ended' === $r['code'], $r['code'] );
check( 'and the tokens were destroyed', array( 7 ) === $GLOBALS['destroyed'] );
check( 'and the count comes back', 3 === $r['data']['count'] );
check( 'and the message says passwords still work', false !== stripos( $r['message'], 'Application passwords are unaffected' ) );

// Not a failure. The answer to "is anybody in there" is no — but the site did not
// change, and one boolean cannot say both.
$GLOBALS['sessions'] = array( 7 => array() );
$GLOBALS['destroyed'] = array();
$r = WPAQS_Actions::end_sessions( 7, 1 );
check( 'an account with no sessions still succeeds', true === $r['ok'], $r['code'] );
check( 'but reports the site unchanged', false === $r['changed'] );

$r = WPAQS_Actions::end_sessions( 1, 1 );
check( 'your own account is refused', 'self' === $r['code'], $r['code'] );

$r = WPAQS_Actions::end_sessions( 404, 1 );
check( 'an account that does not exist is refused', 'missing' === $r['code'], $r['code'] );

// A command has no screen to be signed out of.
$GLOBALS['sessions'] = array( 1 => array( 'a' ) );
$r = WPAQS_Actions::end_sessions( 1, 0 );
check( 'a caller with no user has no self to protect', true === $r['ok'], $r['code'] );

// -------------------------------------------------------- ending one session

acting( 1, array( 'manage_options' => true, 'edit_user' => true ) );
$GLOBALS['ended_one'] = array();

$r = WPAQS_Actions::end_session( 7, 'v123', 1 );
check( 'one session is ended', true === $r['ok'] && 'session-ended' === $r['code'], $r['code'] );
check( 'and it named the session, not the account', array( '7:v123' ) === $GLOBALS['ended_one'] );
check( 'and the message says the others are open', false !== stripos( $r['message'], 'still open' ) );

// Deliberately no self refusal: an administrator with a browser session and one opened
// by a script should be able to close the script's without signing themselves out.
$GLOBALS['ended_one'] = array();
$r = WPAQS_Actions::end_session( 1, 'v999', 1 );
check( 'ending one of your own sessions is allowed', true === $r['ok'], $r['code'] );

$r = WPAQS_Actions::end_session( 404, 'v1', 1 );
check( 'an account that does not exist is refused', 'missing' === $r['code'], $r['code'] );

acting( 1, array( 'manage_options' => true ) );
$r = WPAQS_Actions::end_session( 7, 'v1', 1 );
check( 'an actor that cannot edit the account is refused', 'nocap' === $r['code'], $r['code'] );

// The mirror-image failure: through current_user_can() under cron this is false for
// everything and no command would ever close a session.
$r = WPAQS_Actions::end_session( 7, 'v1', 0 );
check( 'a caller with no user is not refused for capabilities', 'nocap' !== $r['code'], $r['code'] );

acting( 1, array( 'manage_options' => true, 'edit_user' => true ) );
$GLOBALS['end_one_error'] = 'the default session manager is not in use';
$r = WPAQS_Actions::end_session( 7, 'v1', 1 );
check( 'a session manager that cannot be written is reported', 'session-refused' === $r['code'], $r['code'] );
check( 'and passes the reason through', false !== stripos( $r['message'], 'default session manager' ) );
$GLOBALS['end_one_error'] = '';

// ------------------------------------------------- revoking application passwords

acting( 1, array( 'manage_options' => true, 'edit_user' => true ) );
$GLOBALS['revoked'] = array();

$r = WPAQS_Actions::revoke_password( 7, 'abcd-1234', 1 );
check( 'an application password on the account is revoked', true === $r['ok'] && 'revoked' === $r['code'], $r['code'] );
check( 'and it reached the core class', array( '7:abcd-1234' ) === $GLOBALS['revoked'] );
check( 'and the message says sessions are unaffected', false !== stripos( $r['message'], 'browser sessions are unaffected' ) );

// Live, not from a report: a pair that is not on the account right now is not
// something to act on, and this plugin deliberately has no report to check against.
$GLOBALS['revoked'] = array();
$r = WPAQS_Actions::revoke_password( 7, 'not-on-the-account', 1 );
check( 'a password that is not on the account any more is refused', 'gone' === $r['code'], $r['code'] );
check( 'and nothing was revoked', array() === $GLOBALS['revoked'] );

$r = WPAQS_Actions::revoke_password( 9, 'abcd-1234', 1 );
check( 'the right password on the wrong account is refused', 'gone' === $r['code'], $r['code'] );

acting( 1, array( 'manage_options' => true ) );
$r = WPAQS_Actions::revoke_password( 7, 'abcd-1234', 1 );
check( 'an actor that cannot edit the account is refused', 'nocap' === $r['code'], $r['code'] );

// The mirror-image failure: false for everything under cron, so no command would
// ever revoke anything.
$r = WPAQS_Actions::revoke_password( 7, 'abcd-1234', 0 );
check( 'a caller with no user is not refused for capabilities', 'nocap' !== $r['code'], $r['code'] );
check( 'and it revoked', true === $r['ok'], $r['code'] );

acting( 1, array( 'manage_options' => true, 'edit_user' => true ) );
$GLOBALS['app_passwords_unavailable'] = true;
$r = WPAQS_Actions::revoke_password( 7, 'abcd-1234', 1 );
check( 'a WordPress without application passwords says so', 'unsupported' === $r['code'], $r['code'] );
unset( $GLOBALS['app_passwords_unavailable'] );

$GLOBALS['revoke_fails'] = true;
$r = WPAQS_Actions::revoke_password( 7, 'abcd-1234', 1 );
check( 'a revoke WordPress refuses is reported', 'revoke-failed' === $r['code'], $r['code'] );
check( 'and reports the site unchanged', false === $r['changed'] );
unset( $GLOBALS['revoke_fails'] );

// ------------------------------------------------------- removing a capability

$GLOBALS['cap_actor_seen'] = array();
$r = WPAQS_Actions::remove_capability( 7, 'edit_users', 1 );
check( 'a directly granted capability is taken off', true === $r['ok'] && 'capability-removed' === $r['code'], $r['code'] );
check( 'and the capability comes back in the data', 'edit_users' === $r['data']['cap'] );
check( 'and the message says the role is untouched', false !== stripos( $r['message'], 'role is unchanged' ) );

// The actor is what the whole contract exists for.
check( 'the actor reaches the accounts class', array( 1 ) === $GLOBALS['cap_actor_seen'] );

$GLOBALS['cap_actor_seen'] = array();
WPAQS_Actions::remove_capability( 7, 'edit_users', 0 );
check( 'a caller with no user reaches it as 0', array( 0 ) === $GLOBALS['cap_actor_seen'] );

$GLOBALS['cap_error'] = 'the grant comes from its role';
$r = WPAQS_Actions::remove_capability( 7, 'edit_users', 1 );
check( 'a refusal from the accounts class is reported', 'capability-refused' === $r['code'], $r['code'] );
check( 'and passes the wording through', 'the grant comes from its role' === $r['message'] );
check( 'and still names the capability', 'edit_users' === $r['data']['cap'] );
unset( $GLOBALS['cap_error'] );

// --------------------------------------------------------------- registration

$r = WPAQS_Actions::park_default_role( 1 );
check( 'new accounts can be made subscribers', true === $r['ok'] && 'default-role-parked' === $r['code'], $r['code'] );
check( 'and it reached the registration class', true === $GLOBALS['parked'] );
check( 'and the message says existing accounts keep their roles', false !== stripos( $r['message'], 'keep the roles' ) );

$r = WPAQS_Actions::close_registration( 1 );
check( 'registration can be closed', true === $r['ok'] && 'registration-closed' === $r['code'], $r['code'] );
check( 'and it reached the registration class', true === $GLOBALS['closed'] );

// On multisite this is a network option and the registration class refuses, pointing
// at Network Settings. A button that appeared to work and changed nothing would be
// worse than no button.
$GLOBALS['close_error'] = 'registration is a network setting on this site';
$r = WPAQS_Actions::close_registration( 1 );
check( 'a network install is refused with a pointer', 'registration-refused' === $r['code'], $r['code'] );
check( 'and says where to change it', false !== stripos( $r['message'], 'network setting' ) );
unset( $GLOBALS['close_error'] );

// ------------------------------------------------------------ nothing deletes a user

// Tokenised rather than grepped: the class docblock names wp_delete_user() to record why it
// is never called, and an assertion that cannot tell a mention from a call fails on the
// comment written to prevent the call.
function code_only( $path ) {
	$out = '';

	foreach ( token_get_all( file_get_contents( $path ) ) as $token ) {
		if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}

		$out .= is_array( $token ) ? $token[1] : $token;
	}

	return $out;
}

$code    = code_only( WPAQS_DIR . 'includes/class-controller.php' );
$actions = code_only( WPAQS_DIR . 'includes/class-actions.php' );

check(
	'nothing here calls a user delete',
	false === strpos( $code . $actions, 'wp_delete_user' ) && false === strpos( $code . $actions, 'wpmu_delete_user' ),
	'the account\'s content is the record of what it did'
);

// Read with comments on purpose — this is the one assertion that wants the docblock,
// and it is the counterpart of the one above that must not see it.
check(
	'and the file says why it does not',
	false !== strpos( file_get_contents( WPAQS_DIR . 'includes/class-controller.php' ), 'wp_delete_user' ),
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
	'removing a capability is scoped to the account and the capability',
	false !== strpos( $code, "'-remove-cap-' . \$user_id . '-' . \$cap" )
);

check(
	'ending one session is scoped to the session',
	false !== strpos( $code, "'-end-session-' . \$user_id . '-' . \$verifier" ),
	'a nonce lifted from another row would end the wrong session'
);

// The two settings changes are site-wide, so their nonces have nothing to scope to — but
// they must still have one.
foreach ( array( '-park-default-role', '-close-registration' ) as $action ) {
	check(
		'the ' . $action . ' action carries a nonce',
		false !== strpos( $code, $action )
	);
}

// The check went with the work it guards when revoke_password was extracted. It did
// not go away.
check(
	'the password is checked against live state',
	false !== strpos( $actions, 'WPAQS_App_Passwords::exists' ),
	'a request naming a password that is gone must not be acted on'
);


// ------------------------------------------------------ what may not be here

foreach ( array( '$_POST', '$_GET', 'check_admin_referer', 'current_user_can', 'wp_die', 'wp_safe_redirect' ) as $http ) {
	check(
		"the actions class is free of $http",
		false === strpos( $actions, $http ),
		'authorization and HTTP belong to the caller'
	);
}

// The mirror, and the point of the whole refactor. The guarantee that the web path and
// a remote command enforce identical refusals is not that both are well tested — it is
// that there is one implementation and the controller cannot reach past it. Every entry
// here is a way of performing an action; a controller that calls one has grown a second
// path, and the next refusal added to the action will not apply to it.
$performing = array(
	'WPAQS_Sessions::end_one('                    => 'ending one session',
	'WP_Session_Tokens::get_instance('            => 'ending every session',
	'WPAQS_Accounts::remove_direct_capability('   => 'taking a capability off an account',
	'WPAQS_Registration::park_default_role('      => 'parking the default role',
	'WPAQS_Registration::close('                  => 'closing registration',
	'delete_application_password('                => 'revoking an application password',
	'WPAQS_App_Passwords::exists('                => 'deciding whether a password is still there',
);

foreach ( $performing as $call => $what ) {
	check(
		"the controller does not perform $what itself",
		false === strpos( $code, $call ),
		'it delegates to WPAQS_Actions, or a refusal added there will not apply to it'
	);
}

// And the positive half: every endpoint that changes the site reaches the actions
// class. Forbidding the primitives alone would pass on a controller that quietly
// stopped doing anything at all.
foreach ( array(
	'end_sessions',
	'end_session',
	'revoke_password',
	'remove_capability',
	'park_default_role',
	'close_registration',
) as $action ) {
	check(
		"the controller delegates $action",
		false !== strpos( $code, 'WPAQS_Actions::' . $action . '(' ),
		'a site-changing endpoint that never calls the actions class is a second path'
	);
}

finish();
