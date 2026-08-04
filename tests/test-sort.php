<?php
/**
 * Column sorting.
 *
 * Server-side, so the two bugs the sibling paid for sorting in JavaScript are not available
 * here: no column indices to shift, and no early return to install the whole thing behind.
 *
 * What is left worth testing is the part a client-side sort gets wrong anyway — ordering on
 * the data rather than on the rendered text — plus the allowlist, since the column name
 * arrives in the URL.
 */

function sanitize_key( $key ) {
	return strtolower( preg_replace( '~[^a-z0-9_\-]~i', '', (string) $key ) );
}

function wp_unslash( $value ) {
	return $value;
}

function add_query_arg( $args, $url ) {
	return $url . '&' . http_build_query( $args );
}

function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . $path;
}

require __DIR__ . '/bootstrap.php';

load_class( 'sort' );

/**
 * WPAQS_Sort reads the column from the request, so the harness writes it there.
 *
 * @param string $table Table identifier.
 * @param mixed  $key   Column, or null to send nothing.
 * @param mixed  $dir   Direction, or null to send nothing.
 * @return void
 */
function asking( $table, $key = null, $dir = null ) {
	$_GET = array();

	if ( null !== $key ) {
		$_GET[ 'wpaqs_' . $table . '_by' ] = $key;
	}

	if ( null !== $dir ) {
		$_GET[ 'wpaqs_' . $table . '_dir' ] = $dir;
	}
}

$allowed = array( 'created', 'last_used', 'name' );

// ------------------------------------------------------------------- the request

asking( 'passwords' );
$none = WPAQS_Sort::requested( 'passwords', $allowed );

check( 'with no request the first column is the default', 'created' === $none['key'], $none['key'] );
check( 'and ascending', 'asc' === $none['dir'] );
check( 'and nothing is reported as active', ! WPAQS_Sort::is_active( 'passwords' ) );

asking( 'passwords', 'last_used', 'desc' );
$asked = WPAQS_Sort::requested( 'passwords', $allowed );

check( 'a column the table offers is honoured', 'last_used' === $asked['key'] );
check( 'and so is the direction', 'desc' === $asked['dir'] );
check( 'and it reports as active', WPAQS_Sort::is_active( 'passwords' ) );

// The column name arrives in the URL, so it is checked against what the table has rather than
// sanitized and trusted: a name the table does not offer is not a column.
asking( 'passwords', 'user_pass', 'asc' );
$refused = WPAQS_Sort::requested( 'passwords', $allowed );

check(
	'a column the table does not offer falls back to the default',
	'created' === $refused['key'],
	'the name comes from the URL, so the allowlist is the check'
);

asking( 'passwords', 'last_used', 'sideways' );

check( 'a direction that is not a direction reads as ascending', 'asc' === WPAQS_Sort::requested( 'passwords', $allowed )['dir'] );

// Two tables on one screen sort separately, or pressing a header in one would reorder both.
asking( 'accounts', 'login', 'desc' );

check( 'one table\'s request does not reach another', 'created' === WPAQS_Sort::requested( 'passwords', $allowed )['key'] );
check( 'and is not reported as active for it', ! WPAQS_Sort::is_active( 'passwords' ) );
check( 'while its own table sees it', 'login' === WPAQS_Sort::requested( 'accounts', array( 'registered', 'login' ) )['key'] );

check( 'a table offering nothing has no column', '' === WPAQS_Sort::requested( 'nothing', array() )['key'] );

// -------------------------------------------------------------------- ordering

$rows = array(
	array( 'name' => 'Zapier', 'used' => 1750000000 ),
	array( 'name' => 'alpha', 'used' => 0 ),
	array( 'name' => 'WPCLI', 'used' => 1740000000 ),
	array( 'name' => 'beta', 'used' => 0 ),
);

$by_used = WPAQS_Sort::apply( $rows, 'asc', function ( $row ) { return $row['used']; } );

// This is the case a client-side sort on rendered text gets wrong: "never" is a zero, and it
// belongs before every real date rather than wherever the word falls in the alphabet.
check(
	'never-used sorts before every real date',
	0 === $by_used[0]['used'] && 0 === $by_used[1]['used'],
	'the sort is on the timestamp, not on the word'
);

check( 'and the oldest real date comes next', 1740000000 === $by_used[2]['used'] );

// Ties keep the order they arrived in, or two keys never used would swap places between one
// page load and the next and the list would look like it was changing.
check(
	'ties are stable',
	'alpha' === $by_used[0]['name'] && 'beta' === $by_used[1]['name'],
	implode( ',', array( $by_used[0]['name'], $by_used[1]['name'] ) )
);

$desc = WPAQS_Sort::apply( $rows, 'desc', function ( $row ) { return $row['used']; } );

check( 'descending puts the newest first', 1750000000 === $desc[0]['used'] );
check( 'and never-used last', 0 === $desc[3]['used'] );
check( 'with ties still in arrival order', 'alpha' === $desc[2]['name'], $desc[2]['name'] );

// Logins and names read as a person reads them, so case does not split the list in two.
$by_name = WPAQS_Sort::apply( $rows, 'asc', function ( $row ) { return $row['name']; } );

check(
	'names sort case-insensitively',
	array( 'alpha', 'beta', 'WPCLI', 'Zapier' ) === array_map( function ( $row ) { return $row['name']; }, $by_name ),
	implode( ',', array_map( function ( $row ) { return $row['name']; }, $by_name ) )
);

check( 'an empty list sorts to an empty list', array() === WPAQS_Sort::apply( array(), 'asc', function ( $row ) { return $row; } ) );

// Numbers compare as numbers. The timestamps above happen to sort the same either way, so
// they proved nothing about it — these do not: as text, "100" comes before "9".
$counts = array(
	array( 'n' => 9 ),
	array( 'n' => 100 ),
	array( 'n' => 25 ),
);

check(
	'numbers do not sort like text',
	array( 9, 25, 100 ) === array_map( function ( $row ) { return $row['n']; }, WPAQS_Sort::apply( $counts, 'asc', function ( $row ) { return $row['n']; } ) ),
	implode( ',', array_map( function ( $row ) { return $row['n']; }, WPAQS_Sort::apply( $counts, 'asc', function ( $row ) { return $row['n']; } ) ) )
);

// Stability is asserted above and cannot fail here: usort has been stable since PHP 8.0 and
// this runs on 8.5. It is load-bearing on 7.4, which is the floor and is never executed on
// this machine — so the explicit position tiebreak stays, and removing it would only show up
// on the version nobody runs locally.
check(
	'the tiebreak is explicit rather than relying on the runtime',
	false !== strpos( file_get_contents( WPAQS_DIR . 'includes/class-sort.php' ), "\$a['position'] - \$b['position']" ),
	'usort is unstable on 7.4, which is the floor'
);

// --------------------------------------------------------------------- the link

asking( 'passwords', 'created', 'asc' );
$current = WPAQS_Sort::requested( 'passwords', $allowed );

check(
	'pressing the sorted column reverses it',
	false !== strpos( WPAQS_Sort::url( 'passwords', 'created', $current, 'wpaqs-passwords' ), 'passwords_dir=desc' ),
	'that is what every table in wp-admin does'
);

check(
	'pressing another column starts it ascending',
	false !== strpos( WPAQS_Sort::url( 'passwords', 'name', $current, 'wpaqs-passwords' ), 'passwords_dir=asc' )
);

// The anchor is not decoration: sorting reloads the page, and landing at the top having lost
// which section was open reads as the header doing something else.
check(
	'the link returns to its own section',
	false !== strpos( WPAQS_Sort::url( 'passwords', 'name', $current, 'wpaqs-passwords' ), '#wpaqs-passwords' )
);

check( 'the active column reports its direction', 'asc' === WPAQS_Sort::indicator( 'created', $current ) );
check( 'and an inactive one reports nothing', '' === WPAQS_Sort::indicator( 'name', $current ) );

$_GET = array();

finish();
