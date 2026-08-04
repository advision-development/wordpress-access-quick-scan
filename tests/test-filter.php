<?php
/**
 * Showing only the rows that are doing something.
 *
 * Two properties matter and neither is the filtering itself, which is a loop. The first is
 * that a filter hiding rows reports how many — a list of two where a moment ago there were
 * 140 is indistinguishable from a site that lost 138 accounts. The second is that sorting and
 * filtering preserve each other, which the first version of sorting could not do: it built
 * its link from scratch, so pressing a column header would have dropped the filter and left a
 * table showing everything under a control saying otherwise.
 */

function sanitize_key( $key ) {
	return strtolower( preg_replace( '~[^a-z0-9_\-]~i', '', (string) $key ) );
}

function wp_unslash( $value ) {
	return $value;
}

function add_query_arg( $args, $url ) {
	return empty( $args ) ? $url : $url . '&' . http_build_query( $args );
}

function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . $path;
}

require __DIR__ . '/bootstrap.php';

load_class( 'screen' );
load_class( 'sort' );
load_class( 'filter' );

/**
 * Put a request on the screen.
 *
 * @param array $get Query arguments.
 * @return void
 */
function requesting( array $get ) {
	$_GET = $get;
}

// ------------------------------------------------------------------- the request

requesting( array() );

check( 'with no request every row shows', WPAQS_Filter::ALL === WPAQS_Filter::requested( 'accounts' ) );
check( 'and nothing reports as filtered', ! WPAQS_Filter::is_active( 'accounts' ) );

requesting( array( 'wpaqs_accounts_show' => 'active' ) );

check( 'the active view is honoured', WPAQS_Filter::ACTIVE === WPAQS_Filter::requested( 'accounts' ) );
check( 'and reports as filtered', WPAQS_Filter::is_active( 'accounts' ) );

// The value arrives in the URL, so anything the screen does not offer is not a view.
requesting( array( 'wpaqs_accounts_show' => 'administrators' ) );

check(
	'a view the screen does not offer shows every row',
	WPAQS_Filter::ALL === WPAQS_Filter::requested( 'accounts' ),
	'the value comes from the URL, so the allowlist is the check'
);

// Two tables filter separately, or a control on one would empty the other.
requesting( array( 'wpaqs_passwords_show' => 'active' ) );

check( "one table's view does not reach another", ! WPAQS_Filter::is_active( 'accounts' ) );
check( 'while its own table sees it', WPAQS_Filter::is_active( 'passwords' ) );

// ------------------------------------------------------------------ the filtering

$rows = array(
	array( 'login' => 'owner', 'live' => true ),
	array( 'login' => 'dormant', 'live' => false ),
	array( 'login' => 'bot', 'live' => true ),
	array( 'login' => 'lapsed', 'live' => false ),
);

/**
 * The predicate under test.
 *
 * @param array $row Row.
 * @return bool
 */
function is_live( array $row ) {
	return $row['live'];
}

$all = WPAQS_Filter::apply( $rows, WPAQS_Filter::ALL, 'is_live' );

check( 'the unfiltered view keeps every row', 4 === count( $all['rows'] ) );
check( 'and hides nothing', 0 === $all['hidden'] );

$active = WPAQS_Filter::apply( $rows, WPAQS_Filter::ACTIVE, 'is_live' );

check( 'the active view keeps the live rows', 2 === count( $active['rows'] ), (string) count( $active['rows'] ) );
check( 'and they are the right ones', 'owner' === $active['rows'][0]['login'] && 'bot' === $active['rows'][1]['login'] );

// A filter that hides rows says how many. This is the assertion that matters: without the
// count, two rows where there were 140 reads as a site that lost 138 accounts.
check(
	'it reports how many it hid',
	2 === $active['hidden'],
	'a list of two where there were 140 otherwise reads as 138 accounts gone'
);

// Order survives the filter, or the table would reorder itself when the view changed.
$reversed = WPAQS_Filter::apply( array_reverse( $rows ), WPAQS_Filter::ACTIVE, 'is_live' );

check( 'the filter does not reorder', 'bot' === $reversed['rows'][0]['login'], $reversed['rows'][0]['login'] );

// Nothing matching is a real answer, and it must not report as unfiltered.
$none = WPAQS_Filter::apply(
	array( array( 'login' => 'dormant', 'live' => false ) ),
	WPAQS_Filter::ACTIVE,
	'is_live'
);

check( 'no row matching is an empty list', array() === $none['rows'] );
check( 'and it still reports what it hid', 1 === $none['hidden'], 'an empty filtered table must not read as an empty site' );
check( 'and reports the view it applied', WPAQS_Filter::ACTIVE === $none['view'] );

check( 'an empty list filters to an empty list', array() === WPAQS_Filter::apply( array(), WPAQS_Filter::ACTIVE, 'is_live' )['rows'] );

// --------------------------------------------------------- the controls preserve each other

// This is the fault the first version of sorting would have had the moment a filter arrived:
// WPAQS_Sort::url() built its link from admin_url() and added only the sort arguments, so
// pressing a column header dropped everything else in the address.
requesting(
	array(
		'wpaqs_accounts_show' => 'active',
		'wpaqs_accounts_by'   => 'login',
		'wpaqs_accounts_dir'  => 'asc',
	)
);

$sort      = WPAQS_Sort::requested( 'accounts', array( 'registered', 'login' ) );
$sort_link = WPAQS_Sort::url( 'accounts', 'registered', $sort, 'wpaqs-accounts' );

check(
	'a sort link keeps the filter',
	false !== strpos( $sort_link, 'wpaqs_accounts_show=active' ),
	'otherwise pressing a column header silently shows every row again'
);

check( 'and still sorts', false !== strpos( $sort_link, 'wpaqs_accounts_by=registered' ) );

$filter_link = WPAQS_Filter::url( 'accounts', WPAQS_Filter::ACTIVE, 'wpaqs-accounts' );

check(
	'a filter link keeps the sort',
	false !== strpos( $filter_link, 'wpaqs_accounts_by=login' )
	&& false !== strpos( $filter_link, 'wpaqs_accounts_dir=asc' ),
	'otherwise changing the view reorders the table'
);

check(
	'and pressing it while filtered drops the filter rather than setting all',
	false === strpos( $filter_link, 'wpaqs_accounts_show' ),
	'the address of an unfiltered screen is the address of the screen'
);

$applying = WPAQS_Filter::url( 'accounts', WPAQS_Filter::ALL, 'wpaqs-accounts' );

check( 'and pressing it while unfiltered applies the filter', false !== strpos( $applying, 'wpaqs_accounts_show=active' ) );

// Both links return to their section: a reload landing at the top of the page, with the
// section shut, reads as the control having done something else.
check( 'the filter link returns to its section', false !== strpos( $filter_link, '#wpaqs-accounts' ) );
check( 'and so does the sort link', false !== strpos( $sort_link, '#wpaqs-accounts' ) );

// One table's control must not carry another's away, but it must not drop it either.
requesting( array( 'wpaqs_accounts_show' => 'active', 'wpaqs_passwords_by' => 'created' ) );

check(
	"a link keeps the other table's controls too",
	false !== strpos( WPAQS_Filter::url( 'accounts', WPAQS_Filter::ACTIVE, 'x' ), 'wpaqs_passwords_by=created' ),
	'both tables are on one screen and one reload draws both'
);

// ------------------------------------------------------- what the screen refuses to carry

// The allowlist is the point: a request can put anything in the query string, and every link
// this screen prints is one somebody may follow.
requesting(
	array(
		'wpaqs_accounts_show' => 'active',
		'redirect_to'         => 'https://evil.test',
		'_wpnonce'            => 'abc123',
		'wpaqs_notice'        => 'suspended',
	)
);

$link = WPAQS_Screen::url( array(), 'wpaqs-accounts' );

check( 'the screen carries its own arguments', false !== strpos( $link, 'wpaqs_accounts_show=active' ) );
check( 'and not an arbitrary one from the request', false === strpos( $link, 'evil.test' ) );
check( 'nor a nonce', false === strpos( $link, '_wpnonce' ) );

// An action result should not be re-shown by pressing a column header.
check( 'nor the result of an action', false === strpos( $link, 'wpaqs_notice' ) );

// A caller naming an argument the screen does not own is a mistake, and honouring it would
// make the allowlist decorative.
check(
	'and an override the screen does not own is ignored',
	false === strpos( WPAQS_Screen::url( array( 'redirect_to' => 'https://evil.test' ), '' ), 'evil.test' )
);

requesting( array() );

check( 'an unfiltered screen has a clean address', false === strpos( WPAQS_Screen::url( array(), '' ), 'wpaqs_' ) );

$_GET = array();

finish();
