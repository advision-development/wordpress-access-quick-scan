<?php
/**
 * Live sessions, and telling a browser from a script.
 *
 * The classification is the whole rule, so it is tested in both directions: every scripted
 * client fires, and real browser strings — including ones that contain substrings a lazy
 * matcher would catch — stay silent.
 */

function update_user_meta( $user_id, $key, $value ) {
	$GLOBALS['tokens'][ (int) $user_id ] = $value;

	return true;
}

function delete_user_meta( $user_id, $key ) {
	unset( $GLOBALS['tokens'][ (int) $user_id ] );

	return true;
}

function apply_filters( $hook, $value ) {
	return isset( $GLOBALS['filtered'][ $hook ] ) ? $GLOBALS['filtered'][ $hook ] : $value;
}

function get_user_meta( $user_id, $key, $single = false ) {
	if ( 'session_tokens' !== $key ) {
		return $single ? '' : array();
	}

	return isset( $GLOBALS['tokens'][ (int) $user_id ] ) ? $GLOBALS['tokens'][ (int) $user_id ] : '';
}

require __DIR__ . '/bootstrap.php';

// Fixtures are relative to now, never absolute. The first version of this harness wrote
// expiration => 1760000000, which was in the future when it was written and is in the past
// today — so every session in it silently became expired and the assertions started failing
// for a reason that had nothing to do with the code.
$live_until = time() + ( 14 * DAY_IN_SECONDS );
$lapsed_at  = time() - ( 30 * DAY_IN_SECONDS );

load_class( 'findings' );
load_class( 'sessions' );

// ------------------------------------------------------ user agent classification

$scripted = array(
	'curl/8.4.0',
	'Wget/1.21.3',
	'python-requests/2.31.0',
	'Python-urllib/3.11',
	'Go-http-client/1.1',
	'GuzzleHttp/7',
	'okhttp/4.9.0',
	'axios/1.6.2',
	'node-fetch/1.0',
	'libwww-perl/6.67',
	'Java/17.0.1',
	'Apache-HttpClient/4.5',
	'PostmanRuntime/7.35.0',
	'insomnia/8.4.5',
	'',
	'   ',
);

foreach ( $scripted as $agent ) {
	check(
		sprintf( 'scripted: %s', '' === trim( $agent ) ? '(empty)' : $agent ),
		WPAQS_Sessions::is_scripted( $agent )
	);
}

// The benign side. These are real strings from real browsers, and none of them may fire.
$browsers = array(
	'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36',
	'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1',
	'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:127.0) Gecko/20100101 Firefox/127.0',
	'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36 Edg/125.0',
	// Contains "java" inside "JavaScript"-adjacent vendor text, which a substring match on
	// "java" alone would wrongly catch. The rule matches "java/" for that reason.
	'Mozilla/5.0 (compatible; JavaBrowser 3.0; Windows NT 10.0)',
);

foreach ( $browsers as $agent ) {
	check(
		sprintf( 'browser: %s…', substr( $agent, 0, 44 ) ),
		! WPAQS_Sessions::is_scripted( $agent )
	);
}

// ------------------------------------------------------------------ reading meta

$GLOBALS['tokens'] = array(
	1 => array(
		'aaa' => array( 'ip' => '203.0.113.9', 'ua' => 'Mozilla/5.0 (Macintosh) Chrome/126.0', 'login' => 1750000000, 'expiration' => $live_until ),
		'bbb' => array( 'ip' => '198.51.100.4', 'ua' => 'curl/8.4.0', 'login' => 1750000100, 'expiration' => $live_until ),
	),
	// Meta written by something that is not WordPress.
	2 => array( 'ccc' => 'not-an-array' ),
	// No meta at all.
	3 => '',
);

$one = WPAQS_Sessions::for_user( 1 );

check( 'both sessions are read', 2 === count( $one ), (string) count( $one ) );
check( 'the address is kept', '203.0.113.9' === $one[0]['ip'] );
check( 'the login time is an integer', 1750000000 === $one[0]['login'] );

$addresses = WPAQS_Sessions::addresses( $one );
sort( $addresses );

check( 'both addresses are collected', array( '198.51.100.4', '203.0.113.9' ) === $addresses, implode( ',', $addresses ) );

// An unreadable session is still a session. Dropping it would undercount access.
$two = WPAQS_Sessions::for_user( 2 );

check( 'an unreadable session is reported, not skipped', 1 === count( $two ) );
check( 'and marked unreadable', isset( $two[0]['readable'] ) && false === $two[0]['readable'] );

check( 'an account with no session meta has none', array() === WPAQS_Sessions::for_user( 3 ) );
check( 'an account nobody has heard of has none', array() === WPAQS_Sessions::for_user( 99 ) );

// ---------------------------------------------------------------------- findings

$account  = array( 'id' => 1, 'login' => 'owner' );
$findings = WPAQS_Sessions::findings( $account, $one );

check( 'exactly the scripted session is reported', 1 === count( $findings ), (string) count( $findings ) );
check( 'at high', 1 === count( $findings ) && 'high' === $findings[0]['severity'] );
check( 'and the evidence carries the agent', 1 === count( $findings ) && false !== strpos( $findings[0]['evidence'], 'curl/8.4.0' ) );
check( 'and the address it came from', 1 === count( $findings ) && false !== strpos( $findings[0]['evidence'], '198.51.100.4' ) );

// An account whose only session is a browser produces nothing at all.
$clean = WPAQS_Sessions::findings( $account, array( $one[0] ) );

check( 'a browser-only account is silent', array() === $clean );

// ------------------------------------------------------------------- networks

check( 'an IPv4 network is the first two octets', '203.0' === WPAQS_Sessions::network_of( '203.0.113.9' ) );
check( 'an IPv6 network is the first three groups', '2001:db8:1' === WPAQS_Sessions::network_of( '2001:db8:1:2::5' ), WPAQS_Sessions::network_of( '2001:db8:1:2::5' ) );
check( 'a malformed address has no network', '' === WPAQS_Sessions::network_of( 'nonsense' ) );
check( 'and neither does an empty one', '' === WPAQS_Sessions::network_of( '' ) );

/**
 * Sessions from a list of addresses, all with an ordinary browser agent.
 *
 * `$live_until` is passed in rather than reached for. It is a file-scope variable and this
 * is a function, so the earlier version read an undefined name and every session it built
 * carried `'expiration' => null` — four assertions that describe live sessions were
 * exercising the expired path instead, silently, and PHP said so in a warning nobody read.
 *
 * @param array $addresses Addresses.
 * @param int   $live_until When these sessions expire.
 * @return array
 */
function sessions_from( array $addresses, $live_until ) {
	$sessions = array();

	foreach ( $addresses as $address ) {
		$sessions[] = array(
			'ip'         => $address,
			'ua'         => 'Mozilla/5.0 (Macintosh) Chrome/126.0',
			'login'      => 1750000000,
			'expiration' => $live_until,
			'readable'   => true,
		);
	}

	return $sessions;
}

$account = array( 'id' => 5, 'login' => 'shared' );

// The benign case, and the reason the threshold is three rather than two: a laptop on an
// office connection and a phone on mobile data are two networks and entirely ordinary.
$two = WPAQS_Sessions::findings( $account, sessions_from( array( '203.0.113.9', '198.51.100.4' ), $live_until ) );

check(
	'two networks is silent',
	array() === $two,
	'a laptop and a phone are two networks on a healthy site'
);

$three = WPAQS_Sessions::findings( $account, sessions_from( array( '203.0.113.9', '198.51.100.4', '192.0.2.7' ), $live_until ) );

check( 'three networks is reported', 1 === count( $three ), (string) count( $three ) );
check( 'at high', 1 === count( $three ) && 'high' === $three[0]['severity'] );
check( 'and the evidence names them', 1 === count( $three ) && false !== strpos( $three[0]['evidence'], '192.0' ) );

// Several addresses on one network are one network: an office with a changing address must
// not read as somebody signed in from everywhere.
$same = WPAQS_Sessions::findings( $account, sessions_from( array( '203.0.113.9', '203.0.113.40', '203.0.99.1', '203.0.5.5' ), $live_until ) );

check(
	'many addresses on one network stay one network',
	array() === $same,
	'a changing office address is not three places at once'
);

// A session with no address recorded cannot place anybody anywhere.
$blank = WPAQS_Sessions::findings( $account, sessions_from( array( '', '', '' ), $live_until ) );

check( 'sessions with no addresses report no networks', array() === $blank );

// ------------------------------------------------- ending one session

// The verifier is the meta key. WordPress stores sessions keyed by a hash of the token it
// gave the browser, so it names one session without being the secret that authenticates it —
// which is also why destroy() cannot be used: it wants the raw token, and this only has the
// hash.
$GLOBALS['tokens'] = array(
	1 => array(
		'hash-a' => array( 'ip' => '203.0.113.9', 'ua' => 'Mozilla/5.0 Chrome/126.0', 'login' => 1750000000, 'expiration' => $live_until ),
		'hash-b' => array( 'ip' => '198.51.100.4', 'ua' => 'curl/8.4.0', 'login' => 1750000100, 'expiration' => $live_until ),
	),
	4 => array(
		'only-one' => array( 'ip' => '203.0.113.9', 'ua' => 'Mozilla/5.0 Chrome/126.0', 'login' => 1750000000, 'expiration' => $live_until ),
	),
);

$read = WPAQS_Sessions::for_user( 1 );

check( 'the verifier is kept so a session can be named', 'hash-a' === $read[0]['verifier'], $read[0]['verifier'] );

check( 'ending one is available with the usual storage', WPAQS_Sessions::can_end_one() );

$ended = WPAQS_Sessions::end_one( 1, 'hash-b' );

check( 'the named session is ended', '' === $ended['error'], $ended['error'] );
check( 'and it is gone from the meta', ! isset( $GLOBALS['tokens'][1]['hash-b'] ) );
check( 'while the other one stays open', isset( $GLOBALS['tokens'][1]['hash-a'] ), 'ending one must not end them all' );

// Checked live: a session already gone is not something to act on, and saying so beats
// reporting success for a write that changed nothing.
$again = WPAQS_Sessions::end_one( 1, 'hash-b' );

check( 'ending it twice is refused', '' !== $again['error'], $again['error'] );
check( 'a verifier nobody has is refused', '' !== WPAQS_Sessions::end_one( 1, 'invented' )['error'] );
check( 'an empty verifier is refused', '' !== WPAQS_Sessions::end_one( 1, '' )['error'] );
check( 'an account with no sessions is refused', '' !== WPAQS_Sessions::end_one( 99, 'hash-a' )['error'] );

// The last session leaves the meta deleted rather than an empty array, which is what core
// does and what stops a stale empty row lingering.
$last = WPAQS_Sessions::end_one( 4, 'only-one' );

check( 'ending the last session succeeds', '' === $last['error'], $last['error'] );
check( 'and removes the meta rather than leaving it empty', ! isset( $GLOBALS['tokens'][4] ) );

// A site that replaces the session manager keeps sessions somewhere this cannot write, so
// writing user meta there would appear to work and change nothing.
$GLOBALS['filtered'] = array( 'session_token_manager' => 'Custom_Session_Tokens' );

check( 'a custom session manager disables the control', ! WPAQS_Sessions::can_end_one() );
check(
	'and refuses the action rather than writing anyway',
	'' !== WPAQS_Sessions::end_one( 1, 'hash-a' )['error'],
	'a write that changes nothing is worse than a refusal'
);
check( 'and the other session is untouched', isset( $GLOBALS['tokens'][1]['hash-a'] ) );

$GLOBALS['filtered'] = array();

// -------------------------------------------------------------- which controls show

/**
 * How many ending controls a row with this many sessions carries.
 *
 * Counting them is the assertion that matters. Reading the template for the condition
 * cannot catch the case where both conditions are false — which is exactly what happened:
 * "End this session" and "End these sessions" appeared side by side for a single session,
 * both were then gated on the count, and a single session got no button at all.
 *
 * @param int  $sessions How many sessions.
 * @param bool $default  Whether the site uses the default session manager.
 * @return int
 */
function controls_for( $sessions, $default = true ) {
	$GLOBALS['filtered'] = $default ? array() : array( 'session_token_manager' => 'Acme_Redis_Session_Tokens' );

	$controls = WPAQS_Sessions::controls( $sessions );

	// The per-row control is drawn once per session; the bulk one once for the account.
	return ( $controls['per_session'] ? $sessions : 0 ) + ( $controls['bulk'] ? 1 : 0 );
}

check(
	'one session gets exactly one control',
	1 === controls_for( 1 ),
	'both is a reader wondering what the difference is; neither is no way to end it'
);

$GLOBALS['filtered'] = array();

check(
	'and it is the per-session one',
	true === WPAQS_Sessions::controls( 1 )['per_session'] && false === WPAQS_Sessions::controls( 1 )['bulk']
);

// A site that replaces the session manager cannot end one session, so the bulk control is
// the only route and has to appear even for a single session.
check(
	'one session still gets one control without the default manager',
	1 === controls_for( 1, false ),
	'ending a single session needs the default manager; ending all of them does not'
);

$GLOBALS['filtered'] = array( 'session_token_manager' => 'Acme_Redis_Session_Tokens' );

check(
	'and it is the bulk one',
	false === WPAQS_Sessions::controls( 1 )['per_session'] && true === WPAQS_Sessions::controls( 1 )['bulk']
);

// Three sessions: one button each, plus the bulk one, which now does something the others
// cannot.
check( 'three sessions get four controls', 4 === controls_for( 3 ), (string) controls_for( 3 ) );
check( 'three sessions without the default manager get one', 1 === controls_for( 3, false ) );

// Nothing to end is a reason not to offer anything: the plural would read "End all 0
// sessions", and there is nothing behind it.
check( 'no sessions get no controls', 0 === controls_for( 0 ), (string) controls_for( 0 ) );
check( 'and neither does a negative count', 0 === controls_for( -1 ) );

$GLOBALS['filtered'] = array();

// ---------------------------------------------------------- expired but still stored

// WP_User_Meta_Session_Tokens prunes expired tokens only when it next writes the meta, so an
// account that stopped signing in keeps them indefinitely. A login from two years ago
// therefore appears in session_tokens, and reading it as open is how a screen headed "live
// sessions" comes to show one.
$GLOBALS['tokens'] = array(
	7 => array(
		'live' => array( 'ip' => '203.0.113.9', 'ua' => 'Mozilla/5.0 Chrome/126.0', 'login' => time() - 3600, 'expiration' => $live_until ),
		'dead' => array( 'ip' => '198.51.100.77', 'ua' => 'Mozilla/5.0 Chrome/109.0', 'login' => time() - ( 700 * DAY_IN_SECONDS ), 'expiration' => $lapsed_at ),
	),
);

$mixed = WPAQS_Sessions::for_user( 7 );

check( 'both stored sessions are read', 2 === count( $mixed ), (string) count( $mixed ) );

$by_verifier = array();

foreach ( $mixed as $session ) {
	$by_verifier[ $session['verifier'] ] = $session;
}

check( 'an expiry in the past reads as expired', true === $by_verifier['dead']['expired'] );
check( 'and one in the future does not', false === $by_verifier['live']['expired'] );

// The row survives rather than being dropped: it is the only login history WordPress keeps,
// and an old sign-in from an address nobody recognises is worth reading even dead.
check(
	'an expired session is kept rather than hidden',
	isset( $by_verifier['dead'] ),
	'it is the only login history core keeps'
);

check( 'only the live one counts as open', 1 === count( WPAQS_Sessions::open( $mixed ) ) );
check( 'and it is the live one', 'live' === WPAQS_Sessions::open( $mixed )[0]['verifier'] );

// A zero or missing expiry is meta this class could not read. Calling it expired is a claim,
// and "not checked" is not "closed".
$GLOBALS['tokens'] = array(
	8 => array( 'odd' => array( 'ip' => '10.0.0.1', 'ua' => 'Mozilla/5.0', 'login' => time() ) ),
);

check(
	'a missing expiry is not read as expired',
	false === WPAQS_Sessions::for_user( 8 )[0]['expired'],
	'that is meta this class could not read, and claiming it is closed is a claim'
);

// ---- the false negative this flag exists to close

// addresses() is what WPAQS_App_Passwords::findings() treats as addresses the account is
// known to work from, and a match there suppresses a finding. A dead session vouching for
// its address forever means a password used from that address today reads as familiar.
$dead_only = array(
	array( 'verifier' => 'd', 'ip' => '198.51.100.77', 'ua' => 'Mozilla/5.0', 'login' => 0, 'expiration' => $lapsed_at, 'expired' => true, 'readable' => true ),
);

check(
	'an expired session does not vouch for its address',
	array() === WPAQS_Sessions::addresses( $dead_only ),
	'otherwise a password used from that address today reads as familiar'
);

check(
	'and a live one does',
	array( '203.0.113.9' ) === WPAQS_Sessions::addresses(
		array( array( 'verifier' => 'l', 'ip' => '203.0.113.9', 'ua' => 'Mozilla/5.0', 'login' => 0, 'expiration' => $live_until, 'expired' => false, 'readable' => true ) )
	)
);

// The rules say "live" and "at once", so they read the open ones. Three dead sessions across
// three networks is not an account signed in from three networks at once.
$three_dead = array();

foreach ( array( '203.0.113.9', '198.51.100.4', '192.0.2.7' ) as $index => $ip ) {
	$three_dead[] = array( 'verifier' => 'd' . $index, 'ip' => $ip, 'ua' => 'Mozilla/5.0', 'login' => 0, 'expiration' => $lapsed_at, 'expired' => true, 'readable' => true );
}

check(
	'three expired sessions are not three networks at once',
	array() === WPAQS_Sessions::findings( array( 'id' => 7, 'login' => 'stale' ), $three_dead ),
	'the catalog wording says live and at once, and neither would be true'
);

// A scripted session that has expired is history rather than something running now, and the
// finding tells the reader to end it.
$dead_script = array(
	array( 'verifier' => 'd', 'ip' => '10.0.0.1', 'ua' => 'curl/8.4.0', 'login' => 0, 'expiration' => $lapsed_at, 'expired' => true, 'readable' => true ),
);

check(
	'an expired scripted session is not reported as one running',
	array() === WPAQS_Sessions::findings( array( 'id' => 7, 'login' => 'stale' ), $dead_script ),
	'the recommendation is to end it, and there is nothing to end'
);

finish();
