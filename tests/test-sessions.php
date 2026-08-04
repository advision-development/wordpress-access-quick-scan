<?php
/**
 * Live sessions, and telling a browser from a script.
 *
 * The classification is the whole rule, so it is tested in both directions: every scripted
 * client fires, and real browser strings — including ones that contain substrings a lazy
 * matcher would catch — stay silent.
 */

function get_user_meta( $user_id, $key, $single = false ) {
	if ( 'session_tokens' !== $key ) {
		return $single ? '' : array();
	}

	return isset( $GLOBALS['tokens'][ (int) $user_id ] ) ? $GLOBALS['tokens'][ (int) $user_id ] : '';
}

require __DIR__ . '/bootstrap.php';

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
		'aaa' => array( 'ip' => '203.0.113.9', 'ua' => 'Mozilla/5.0 (Macintosh) Chrome/126.0', 'login' => 1750000000, 'expiration' => 1760000000 ),
		'bbb' => array( 'ip' => '198.51.100.4', 'ua' => 'curl/8.4.0', 'login' => 1750000100, 'expiration' => 1760000100 ),
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
 * @param array $addresses Addresses.
 * @return array
 */
function sessions_from( array $addresses ) {
	$sessions = array();

	foreach ( $addresses as $address ) {
		$sessions[] = array(
			'ip'         => $address,
			'ua'         => 'Mozilla/5.0 (Macintosh) Chrome/126.0',
			'login'      => 1750000000,
			'expiration' => 1760000000,
			'readable'   => true,
		);
	}

	return $sessions;
}

$account = array( 'id' => 5, 'login' => 'shared' );

// The benign case, and the reason the threshold is three rather than two: a laptop on an
// office connection and a phone on mobile data are two networks and entirely ordinary.
$two = WPAQS_Sessions::findings( $account, sessions_from( array( '203.0.113.9', '198.51.100.4' ) ) );

check(
	'two networks is silent',
	array() === $two,
	'a laptop and a phone are two networks on a healthy site'
);

$three = WPAQS_Sessions::findings( $account, sessions_from( array( '203.0.113.9', '198.51.100.4', '192.0.2.7' ) ) );

check( 'three networks is reported', 1 === count( $three ), (string) count( $three ) );
check( 'at high', 1 === count( $three ) && 'high' === $three[0]['severity'] );
check( 'and the evidence names them', 1 === count( $three ) && false !== strpos( $three[0]['evidence'], '192.0' ) );

// Several addresses on one network are one network: an office with a changing address must
// not read as somebody signed in from everywhere.
$same = WPAQS_Sessions::findings( $account, sessions_from( array( '203.0.113.9', '203.0.113.40', '203.0.99.1', '203.0.5.5' ) ) );

check(
	'many addresses on one network stay one network',
	array() === $same,
	'a changing office address is not three places at once'
);

// A session with no address recorded cannot place anybody anywhere.
$blank = WPAQS_Sessions::findings( $account, sessions_from( array( '', '', '' ) ) );

check( 'sessions with no addresses report no networks', array() === $blank );

finish();
