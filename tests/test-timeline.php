<?php
/**
 * The chronological view.
 *
 * The timeline's whole value is the ordering, so that is what is pinned: newest first,
 * nothing from outside the window, and a cap that says when it was reached. A truncated
 * list that looks complete is worse than a short one — somebody reading it concludes the
 * oldest entry is the oldest event.
 *
 * The reset parser gets its own section because it reads a WordPress column format:
 * `time():hash` for anything current, a bare hash on older installs.
 */

function is_multisite() {
	return false;
}

require __DIR__ . '/bootstrap.php';

load_class( 'sort' );
load_class( 'sessions' );
load_class( 'accounts' );
load_class( 'timeline' );

$now    = time();
$inside = $now - ( 2 * DAY_IN_SECONDS );
$older  = $now - ( 5 * DAY_IN_SECONDS );
$beyond = $now - ( 90 * DAY_IN_SECONDS );

/**
 * One account row, with only the keys the timeline reads.
 *
 * @param int    $id         User id.
 * @param string $login      Login.
 * @param string $registered Registration stamp, MySQL format in UTC.
 * @param array  $extra      Anything to override.
 * @return array
 */
function account( $id, $login, $registered, array $extra = array() ) {
	return array_merge(
		array(
			'id'              => $id,
			'login'           => $login,
			'registered'      => $registered,
			'roles'           => array( 'subscriber' ),
			'is_admin'        => false,
			'reset_requested' => 0,
		),
		$extra
	);
}

// ------------------------------------------------------------------ the ordering

$accounts = array(
	'rows' => array(
		account( 1, 'owner', gmdate( 'Y-m-d H:i:s', $beyond ), array( 'is_admin' => true, 'roles' => array( 'administrator' ) ) ),
		account( 2, 'newcomer', gmdate( 'Y-m-d H:i:s', $older ) ),
	),
);

$sessions = array(
	1 => array(
		array( 'ip' => '203.0.113.9', 'ua' => 'Mozilla/5.0', 'login' => $now - 600, 'expiration' => $now + 100, 'verifier' => 'aaa' ),
		// A long-lived session opened before the window. WordPress keeps a session until it
		// expires, so a site with a long cookie lifetime has these — and a timeline that
		// showed them would put a three-month-old sign-in among this week's events.
		array( 'ip' => '203.0.113.9', 'ua' => 'Mozilla/5.0', 'login' => $beyond, 'expiration' => $now + 100, 'verifier' => 'bbb' ),
	),
);

$passwords = array(
	1 => array(
		array( 'uuid' => 'u-1', 'name' => 'Zapier', 'created' => $inside, 'last_used' => $now - 300, 'last_ip' => '198.51.100.4' ),
	),
);

$built = WPAQS_Timeline::build( $accounts, $sessions, $passwords );

check( 'the window keeps what is inside it', 4 === count( $built['entries'] ), (string) count( $built['entries'] ) );

/**
 * How many entries of one kind the timeline holds.
 *
 * @param array  $built Result of build().
 * @param string $kind  Entry kind.
 * @return int
 */
function kinds( array $built, $kind ) {
	$found = 0;

	foreach ( $built['entries'] as $entry ) {
		if ( $kind === $entry['kind'] ) {
			$found++;
		}
	}

	return $found;
}

// Two accounts, one registered 90 days ago and one inside the window: exactly one entry.
check(
	'an account registered before the window is not an entry',
	1 === kinds( $built, 'account' ),
	'the window is ' . WPAQS_RECENT_DAYS . ' days and one of them registered 90 days ago'
);

// A session still open but opened before the window is the same exclusion, and the one the
// account row cannot make: WordPress keeps a session until it expires, not for 30 days.
check(
	'a session opened before the window is not an entry either',
	1 === kinds( $built, 'session' ),
	'a three-month-old sign-in among this week\'s events reads as this week\'s'
);

// Newest first. This is the ordering the reader is here for: a session followed by a
// password created and then used is a takeover with its steps in order.
$stamps = array_map( function ( $entry ) {
	return $entry['at'];
}, $built['entries'] );

$descending = true;

foreach ( $stamps as $position => $stamp ) {
	if ( $position > 0 && $stamp > $stamps[ $position - 1 ] ) {
		$descending = false;
	}
}

check( 'entries come newest first', $descending, implode( ',', $stamps ) );

check( 'and the most recent is the password use', 'password_used' === $built['entries'][0]['kind'], $built['entries'][0]['kind'] );
check( 'then the sign-in', 'session' === $built['entries'][1]['kind'], $built['entries'][1]['kind'] );
check( 'then the password being created', 'password' === $built['entries'][2]['kind'], $built['entries'][2]['kind'] );
check( 'and the account creation last', 'account' === $built['entries'][3]['kind'], $built['entries'][3]['kind'] );

check( 'every entry names its account', 'owner' === $built['entries'][0]['login'] );
check( 'and carries a label', '' !== $built['entries'][0]['label'] );

// ---------------------------------------------------------------- what each kind says

$scripted = WPAQS_Timeline::build(
	array( 'rows' => array( account( 1, 'owner', gmdate( 'Y-m-d H:i:s', $beyond ) ) ) ),
	array( 1 => array( array( 'ip' => '10.0.0.1', 'ua' => 'python-requests/2.31', 'login' => $inside, 'expiration' => $now, 'verifier' => 'b' ) ) ),
	array()
);

check(
	'a sign-in by something that is not a browser is marked as one',
	'scripted' === $scripted['entries'][0]['kind'],
	'the same timestamp reads very differently depending on what opened it'
);

$anonymous = WPAQS_Timeline::build(
	array( 'rows' => array( account( 1, 'owner', gmdate( 'Y-m-d H:i:s', $beyond ) ) ) ),
	array( 1 => array( array( 'ip' => '', 'ua' => 'Mozilla/5.0', 'login' => $inside, 'expiration' => $now, 'verifier' => 'b' ) ) ),
	array()
);

check(
	'a session with no address says so rather than showing a blank',
	'' !== $anonymous['entries'][0]['detail'],
	'an empty cell reads as a rendering fault'
);

// A password with no name is identified by its uuid, or the entry names nothing at all.
$unnamed = WPAQS_Timeline::build(
	array( 'rows' => array( account( 1, 'owner', gmdate( 'Y-m-d H:i:s', $beyond ) ) ) ),
	array(),
	array( 1 => array( array( 'uuid' => 'u-9', 'name' => '', 'created' => $inside, 'last_used' => 0, 'last_ip' => '' ) ) )
);

check(
	'an unnamed password is identified by its uuid',
	false !== strpos( $unnamed['entries'][0]['detail'], 'u-9' ),
	'otherwise the entry names nothing'
);

check( 'and a password never used produces no use entry', 1 === count( $unnamed['entries'] ), (string) count( $unnamed['entries'] ) );

// An administrator account appearing is not the same event as a subscriber appearing.
$admin = WPAQS_Timeline::build(
	array( 'rows' => array( account( 3, 'newadmin', gmdate( 'Y-m-d H:i:s', $inside ), array( 'is_admin' => true, 'roles' => array( 'administrator' ) ) ) ) ),
	array(),
	array()
);

check(
	'an administrator account created says administrator',
	false !== stripos( $admin['entries'][0]['label'], 'administrator' ),
	'a new administrator is not the same event as a new subscriber'
);

// ------------------------------------------------------------------ the empty case

$empty = WPAQS_Timeline::build( array( 'rows' => array() ), array(), array() );

check( 'no accounts is an empty timeline', array() === $empty['entries'] );
check( 'and it is not reported as capped', false === $empty['capped'] );
check( 'and the total is zero', 0 === $empty['total'] );

// ----------------------------------------------------------------------- the cap

// A busy site produces one entry per login, so the list is bounded — and has to say so.
// A truncated list that looks complete tells the reader the oldest entry is the oldest event.
$many = array();

for ( $i = 0; $i < WPAQS_Timeline::MAX_ENTRIES + 20; $i++ ) {
	$many[] = array( 'ip' => '10.0.0.1', 'ua' => 'Mozilla/5.0', 'login' => $now - $i, 'expiration' => $now, 'verifier' => 'v' . $i );
}

$capped = WPAQS_Timeline::build(
	array( 'rows' => array( account( 1, 'owner', gmdate( 'Y-m-d H:i:s', $beyond ) ) ) ),
	array( 1 => $many ),
	array()
);

check( 'the list is bounded', WPAQS_Timeline::MAX_ENTRIES === count( $capped['entries'] ), (string) count( $capped['entries'] ) );
check( 'it reports being capped', true === $capped['capped'], 'a truncated list that looks complete is worse than a short one' );
check( 'and how many there were', WPAQS_Timeline::MAX_ENTRIES + 20 === $capped['total'], (string) $capped['total'] );

// The cap keeps the newest, not whichever the loop reached first: the reason for the cap is
// the volume, and the recent end is the part somebody is looking at.
check(
	'and it keeps the newest end of the list',
	$capped['entries'][0]['at'] === $now,
	'dropping the recent end would defeat the point of the screen'
);

// ------------------------------------------------------------ the pending reset

$pending = WPAQS_Timeline::build(
	array( 'rows' => array( account( 1, 'owner', gmdate( 'Y-m-d H:i:s', $beyond ), array( 'reset_requested' => $inside ) ) ) ),
	array(),
	array()
);

check( 'a dated pending reset is an entry', 'reset' === $pending['entries'][0]['kind'] );
check( 'at the hour it was requested', $inside === $pending['entries'][0]['at'] );

// Undated is -1, which is before every window: there is nowhere to put it on a timeline.
// It is still reported as a finding, which is where an undated one belongs.
$undated = WPAQS_Timeline::build(
	array( 'rows' => array( account( 1, 'owner', gmdate( 'Y-m-d H:i:s', $beyond ), array( 'reset_requested' => -1 ) ) ) ),
	array(),
	array()
);

check(
	'an undated pending reset is not placed on the timeline',
	array() === $undated['entries'],
	'there is no hour to place it at'
);

$old_reset = WPAQS_Timeline::build(
	array( 'rows' => array( account( 1, 'owner', gmdate( 'Y-m-d H:i:s', $beyond ), array( 'reset_requested' => $beyond ) ) ) ),
	array(),
	array()
);

check( 'and a reset requested before the window is outside it', array() === $old_reset['entries'] );

// ------------------------------------------------------------------ the parser

// retrieve_password() writes time():hash and reset_password() clears it, so a key that is
// still there means a reset was asked for and never completed.
check( 'a dated key yields its timestamp', 1750000000 === WPAQS_Accounts::reset_requested_at( '1750000000:$P$Babc' ) );
check( 'no key is no pending reset', 0 === WPAQS_Accounts::reset_requested_at( '' ) );
check( 'and neither is whitespace', 0 === WPAQS_Accounts::reset_requested_at( "  \n" ) );

// Older WordPress stored the hash alone. That still says a reset is pending, and says
// nothing about when — reading it as zero would report the account as clear.
check(
	'a key with no timestamp is pending rather than absent',
	-1 === WPAQS_Accounts::reset_requested_at( '$P$Babcdefghijklmno' ),
	'older WordPress stored the hash alone'
);

check(
	'and a key whose prefix is not a number is pending rather than parsed',
	-1 === WPAQS_Accounts::reset_requested_at( 'abc:def' ),
	'casting that prefix would yield zero, which reads as no reset at all'
);

// A hash containing a colon must not be mistaken for a second field.
check( 'only the first colon splits the key', 1750000000 === WPAQS_Accounts::reset_requested_at( '1750000000:a:b:c' ) );

check( 'a key that is only a timestamp is pending undated', -1 === WPAQS_Accounts::reset_requested_at( '1750000000' ) );

// -------------------------------------------------------------- no queries here

// The timeline is assembled from what the tables already read. A query in here would make
// opening the screen cost more the longer the site has been running.
$source = file_get_contents( WPAQS_DIR . 'includes/class-timeline.php' );

foreach ( array( 'get_users', 'get_user_meta', '$wpdb', 'get_posts', 'WP_Query' ) as $call ) {
	check(
		'the timeline does not call ' . $call,
		false === strpos( $source, $call ),
		'everything here was read to build the tables'
	);
}

finish();
