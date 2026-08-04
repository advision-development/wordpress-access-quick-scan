<?php
/**
 * An account younger than its own oldest post.
 *
 * The only rule in this plugin that is arithmetic rather than a heuristic:
 * `wp_insert_post()` requires an author that already exists, so a post cannot predate the
 * account that wrote it. When one does, something wrote the database directly.
 *
 * Which makes the benign cases matter more than usual, not less — a false positive here
 * carries a sentence saying there is no ordinary explanation.
 */

/**
 * A stand-in for $wpdb with the two calls this reader makes.
 */
class Stub_Wpdb {

	public $posts = 'wp_posts';

	public function prepare( $query, $args ) {
		// The reader passes an array. Substitution is irrelevant here: the stub answers from
		// $GLOBALS['post_rows'] rather than from SQL.
		return $query;
	}

	public function get_results( $query ) {
		return $GLOBALS['post_rows'];
	}
}

require __DIR__ . '/bootstrap.php';

load_class( 'findings' );
load_class( 'authorship' );

$GLOBALS['wpdb'] = new Stub_Wpdb();

/**
 * One grouped row as the query returns it.
 *
 * @param int    $author Author id.
 * @param string $gmt    MIN( post_date_gmt ).
 * @param string $local  MIN( post_date ).
 * @return object
 */
function post_row( $author, $gmt, $local ) {
	return (object) array(
		'post_author'   => $author,
		'oldest_gmt'    => $gmt,
		'oldest_local'  => $local,
	);
}

/**
 * An account row as WPAQS_Accounts::all() returns it.
 *
 * @param int    $id         Id.
 * @param string $login      Login.
 * @param string $registered Registration date, UTC.
 * @return array
 */
function account( $id, $login, $registered ) {
	return array(
		'id'         => $id,
		'login'      => $login,
		'email'      => $login . '@example.test',
		'registered' => $registered,
		'roles'      => array( 'author' ),
		'is_admin'   => false,
		'direct'     => array(),
	);
}

$accounts = array(
	'rows'   => array(
		account( 1, 'honest', '2020-01-01 00:00:00' ),
		account( 2, 'planted', '2026-07-01 00:00:00' ),
		account( 3, 'imported', '2021-05-05 12:00:00' ),
		account( 4, 'no-posts', '2020-01-01 00:00:00' ),
		account( 5, 'zero-gmt', '2026-07-01 00:00:00' ),
		account( 6, 'same-second', '2022-02-02 10:00:00' ),
	),
	'total'  => 6,
	'capped' => false,
);

$GLOBALS['post_rows'] = array(
	// Wrote after registering. Ordinary.
	post_row( 1, '2020-06-01 09:00:00', '2020-06-01 09:00:00' ),
	// Content two years older than the account that owns it. Impossible through WordPress.
	post_row( 2, '2024-03-04 08:00:00', '2024-03-04 08:00:00' ),
	// Registered the same day it started writing, an hour later. Ordinary.
	post_row( 3, '2021-05-05 13:00:00', '2021-05-05 13:00:00' ),
	// A zero GMT date with a usable local one — the exact shape a direct insert leaves, so
	// reading only the GMT column would make this invisible.
	post_row( 5, '0000-00-00 00:00:00', '2025-01-01 00:00:00' ),
	// Inside the tolerance: registration and first post in the same minute.
	post_row( 6, '2022-02-02 09:59:30', '2022-02-02 09:59:30' ),
);

$earliest = WPAQS_Authorship::earliest_posts();

check( 'a usable GMT date is read', isset( $earliest[1] ) && $earliest[1] === strtotime( '2020-06-01 09:00:00 UTC' ) );
check( 'an account with no posts is absent', ! isset( $earliest[4] ) );

check(
	'a zero GMT date falls back to the local column',
	isset( $earliest[5] ) && $earliest[5] === strtotime( '2025-01-01 00:00:00 UTC' ),
	'a row written straight into the database often leaves post_date_gmt at zero'
);

$findings = array();

foreach ( WPAQS_Authorship::findings( $accounts, $earliest ) as $finding ) {
	$findings[ $finding['target'] ] = $finding;
}

// ------------------------------------------------------------------- it fires

check( 'an account younger than its content is reported', isset( $findings['user:2'] ) );
check( 'at critical', isset( $findings['user:2'] ) && 'critical' === $findings['user:2']['severity'] );

check(
	'and the evidence names both dates and the gap',
	isset( $findings['user:2'] )
		&& false !== strpos( $findings['user:2']['evidence'], '2026-07-01' )
		&& false !== strpos( $findings['user:2']['evidence'], '2024-03-04' )
		&& false !== strpos( $findings['user:2']['evidence'], 'gap=' ),
	'an operator has to see the size of the impossibility'
);

check(
	'the zero-GMT account is reported too',
	isset( $findings['user:5'] ),
	'this is the shape a direct insert leaves and it must not be the shape that hides'
);

// The recommendation has to say what the finding means, because the meaning is the point.
check(
	'the wording says a password change will not close it',
	false !== stripos( $findings['user:2']['recommendation'], 'will not close it' )
);

// ------------------------------------------------------------- benign cases

check( 'an account that wrote after registering is silent', ! isset( $findings['user:1'] ) );
check( 'one that started the same day is silent', ! isset( $findings['user:3'] ) );
check( 'one with no posts is silent', ! isset( $findings['user:4'] ) );

check(
	'a gap inside the tolerance is silent',
	! isset( $findings['user:6'] ),
	'an import can land both rows in the same minute'
);

// ------------------------------------------------------------------ the gap

check( 'a gap of days reads in days', '2 days' === WPAQS_Authorship::readable_gap( 2 * DAY_IN_SECONDS + 100 ), WPAQS_Authorship::readable_gap( 2 * DAY_IN_SECONDS + 100 ) );
check( 'a gap of hours reads in hours', '3 hours' === WPAQS_Authorship::readable_gap( 3 * HOUR_IN_SECONDS + 60 ), WPAQS_Authorship::readable_gap( 3 * HOUR_IN_SECONDS + 60 ) );
check( 'a gap under an hour still reads as one', '1 hour' === WPAQS_Authorship::readable_gap( 90 ), WPAQS_Authorship::readable_gap( 90 ) );

// ------------------------------------------------------------- no database

// A reader that cannot reach $wpdb must return nothing rather than warning, so the screen
// renders and the other sections still answer.
unset( $GLOBALS['wpdb'] );

check( 'no database means no rows rather than a crash', array() === WPAQS_Authorship::earliest_posts() );

finish();
