<?php
/**
 * An account holding content older than itself.
 *
 * Shipped in 0.2.0 as arithmetic rather than a heuristic, which was wrong.
 * `wp_delete_user( $id, $reassign )` moves a deleted account's posts to another account and
 * the posts keep their dates, so deleting a colleague and reassigning their work produces
 * this exactly — and that is an ordinary thing to have done.
 *
 * WordPress records nothing about a reassignment, so the rule cannot tell it from a planted
 * row. These tests therefore pin what it reports rather than what it concludes: the span of
 * the content and the number of posts, which is the discriminator the operator has and the
 * rule does not.
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
function post_row( $author, $gmt, $local, $newest_gmt = null, $posts = 1 ) {
	return (object) array(
		'post_author'   => $author,
		'oldest_gmt'    => $gmt,
		'oldest_local'  => $local,
		'newest_gmt'    => null === $newest_gmt ? $gmt : $newest_gmt,
		'newest_local'  => null === $newest_gmt ? $local : $newest_gmt,
		'posts'         => $posts,
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
		account( 7, 'inheritor', '2024-01-01 00:00:00' ),
	),
	'total'  => 7,
	'capped' => false,
);

$GLOBALS['post_rows'] = array(
	// Wrote after registering. Ordinary.
	post_row( 1, '2020-06-01 09:00:00', '2020-06-01 09:00:00' ),
	// One post, two years older than the account that owns it, with nothing around it. The
	// shape worth opening: a reassignment brings a body of work, not a single row.
	post_row( 2, '2024-03-04 08:00:00', '2024-03-04 08:00:00' ),
	// Registered the same day it started writing, an hour later. Ordinary.
	post_row( 3, '2021-05-05 13:00:00', '2021-05-05 13:00:00' ),
	// A zero GMT date with a usable local one — the exact shape a direct insert leaves, so
	// reading only the GMT column would make this invisible.
	post_row( 5, '0000-00-00 00:00:00', '2025-01-01 00:00:00' ),
	// Inside the tolerance: registration and first post in the same minute.
	post_row( 6, '2022-02-02 09:59:30', '2022-02-02 09:59:30' ),
	// The ordinary explanation: a colleague's account was deleted and their four years of
	// posts were reassigned here. Reported, but the span is what says which it is.
	post_row( 7, '2019-01-01 00:00:00', '2019-01-01 00:00:00', '2023-06-01 00:00:00', 412 ),
);

$earliest = WPAQS_Authorship::earliest_posts();

check( 'a usable GMT date is read', isset( $earliest[1] ) && $earliest[1]['oldest'] === strtotime( '2020-06-01 09:00:00 UTC' ) );
check( 'an account with no posts is absent', ! isset( $earliest[4] ) );

check(
	'a zero GMT date falls back to the local column',
	isset( $earliest[5] ) && $earliest[5]['oldest'] === strtotime( '2025-01-01 00:00:00 UTC' ),
	'a row written straight into the database often leaves post_date_gmt at zero'
);

check( 'the newest post is read too', isset( $earliest[7] ) && $earliest[7]['newest'] === strtotime( '2023-06-01 00:00:00 UTC' ) );
check( 'and the post count', isset( $earliest[7] ) && 412 === $earliest[7]['posts'] );

$findings = array();

foreach ( WPAQS_Authorship::findings( $accounts, $earliest ) as $finding ) {
	$findings[ $finding['target'] ] = $finding;
}

// ------------------------------------------------------------------- it fires

check( 'an account younger than its content is reported', isset( $findings['user:2'] ) );

// Medium, not critical. A reassignment produces this and a reassignment is ordinary, so the
// finding asks a question rather than announcing a compromise.
check( 'at medium', isset( $findings['user:2'] ) && 'medium' === $findings['user:2']['severity'], $findings['user:2']['severity'] );

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
// The wording has to name the ordinary cause first, or the finding reads as an accusation
// against a site where somebody merely tidied up their users.
check(
	'the detail names reassignment as the ordinary explanation',
	false !== stripos( $findings['user:2']['detail'], 'reassigned' ),
	'a deleted colleague is the likeliest cause, not an intrusion'
);

check(
	'and the recommendation says what tells the two apart',
	false !== stripos( $findings['user:2']['recommendation'], 'span' ),
	'the rule cannot judge it, so it has to hand over what it read'
);

check(
	'and admits WordPress records nothing that separates them',
	false !== stripos( $findings['user:2']['recommendation'], 'records nothing' )
);

// ------------------------------------------------- the reassignment shape

check( 'a reassignment is reported too', isset( $findings['user:7'] ), 'the rule cannot tell, so it reports and explains' );

check(
	'and the evidence carries the span and the count',
	isset( $findings['user:7'] )
		&& false !== strpos( $findings['user:7']['evidence'], 'newest_post=2023-06-01' )
		&& false !== strpos( $findings['user:7']['evidence'], 'posts=412' ),
	'four years of content and 412 posts is what inheriting looks like'
);

// The single-row shape, which is the one worth opening.
check(
	'a single planted post reports one post',
	isset( $findings['user:2'] ) && false !== strpos( $findings['user:2']['evidence'], 'posts=1' )
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
