<?php
/**
 * The site's half of the fleet transport.
 *
 * What matters here is not that a POST is made — it is what is in it and what is not.
 * The key must never reach a URL, TLS must never be relaxed, a redirect must never be
 * followed, and a site with no key must not be able to report at all.
 *
 * @package WPAQS
 */

define( 'ABSPATH', sys_get_temp_dir() . '/wpaqs-fleet-test/' );
define( 'WPAQS_DIR', ABSPATH );
define( 'WPAQS_VERSION', '0.8.6' );

$failures = 0;

function check( $label, $ok, $detail = '' ) {
	global $failures;

	if ( ! $ok ) {
		$failures++;
	}

	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, '' === $detail ? '' : ' — ' . $detail );
}

$GLOBALS['options'] = array();
$GLOBALS['posts']   = array();
$GLOBALS['reply']   = array( 'code' => 200, 'body' => array() );

function get_option( $name, $default = false ) {
	return isset( $GLOBALS['options'][ $name ] ) ? $GLOBALS['options'][ $name ] : $default;
}

function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['options'][ $name ] = $value;

	return true;
}

function home_url() {
	return 'https://example.test';
}

function wp_generate_password( $length = 12, $special = true, $extra = false ) {
	return substr( str_repeat( 'aB3-_xY9', 20 ), 0, $length );
}

function apply_filters( $hook, $value ) {
	return $value;
}

function untrailingslashit( $value ) {
	return rtrim( (string) $value, '/' );
}

function wp_json_encode( $value ) {
	return json_encode( $value );
}

function __( $text, $domain = '' ) {
	return $text;
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

class WP_Error {
	public function get_error_message() {
		return 'the site could not reach it';
	}
}

function wp_remote_post( $url, $args ) {
	$GLOBALS['posts'][] = array( 'url' => $url, 'args' => $args );

	if ( ! empty( $GLOBALS['reply']['wp_error'] ) ) {
		return new WP_Error();
	}

	return $GLOBALS['reply'];
}

function wp_remote_retrieve_response_code( $response ) {
	return isset( $response['code'] ) ? $response['code'] : 200;
}

function wp_remote_retrieve_body( $response ) {
	return json_encode( isset( $response['body'] ) ? $response['body'] : array() );
}

/** Stands in for the report class the fleet client asks for its export. */
class WPAQS_Report {
	public static function to_export_array( array $record ) {
		return array( 'findings' => isset( $record['findings'] ) ? $record['findings'] : array() );
	}
}

require dirname( __DIR__ ) . '/wordpress-access-quick-scan/includes/class-fleet.php';

function reset_site() {
	$GLOBALS['options'] = array();
	$GLOBALS['posts']   = array();
	$GLOBALS['reply']   = array( 'code' => 200, 'body' => array() );
}

function last_post() {
	return end( $GLOBALS['posts'] );
}

// ------------------------------------------------------------------ the prefix

// Derived, never written. sed does not rewrite lower case, so a literal 'wpaqs' here
// would break the sibling's byte-identical copy the moment anybody checked it.
check( 'the prefix is derived from the class name', 'wpaqs' === WPAQS_Fleet::prefix() );

// ------------------------------------------------------------ the install nonce

reset_site();

$first = WPAQS_Fleet::install_nonce();

check( 'an install nonce is generated', '' !== $first );
check( 'and it is long enough not to be guessed', strlen( $first ) >= 32, strlen( $first ) );
check( 'and it is safe in a URL', 1 === preg_match( '~^[A-Za-z0-9_-]+$~', $first ) );
check( 'and it does not change between calls', $first === WPAQS_Fleet::install_nonce() );

// ----------------------------------------------------------------- enrolling

reset_site();

check( 'a fresh site is not enrolled', false === WPAQS_Fleet::enrolled() );

$result = WPAQS_Fleet::enrol();
$post   = last_post();
$body   = json_decode( $post['args']['body'], true );

check( 'enrolling posts to /enroll', false !== strpos( $post['url'], '/enroll' ), $post['url'] );
check( 'and names this plugin', 'wpaqs' === $body['plugin'] );
check( 'and names this site', 'https://example.test' === $body['siteUrl'] );
check( 'and carries the install nonce', ! empty( $body['installNonce'] ) );

// There is no key yet, and enrolment is the request for one.
check( 'and sends no authorization header', ! isset( $post['args']['headers']['Authorization'] ) );

// ------------------------------------------------------------------ polling

reset_site();
WPAQS_Fleet::enrol();
$before = count( $GLOBALS['posts'] );

$GLOBALS['reply'] = array( 'code' => 200, 'body' => array( 'status' => 'pending' ) );
WPAQS_Fleet::poll();

check( 'polling asks the console', count( $GLOBALS['posts'] ) > $before );
check( 'and is still not enrolled while pending', false === WPAQS_Fleet::enrolled() );

// A site waiting on a person must not hammer the console once a minute.
$after = count( $GLOBALS['posts'] );
WPAQS_Fleet::poll();
check( 'a second poll inside the interval asks nothing', count( $GLOBALS['posts'] ) === $after );

$GLOBALS['options']['wpaqs_fleet']['polled_at'] = time() - 3600;
$GLOBALS['reply'] = array( 'code' => 200, 'body' => array( 'status' => 'collected', 'key' => 'the-key' ) );
WPAQS_Fleet::poll();

check( 'a key that arrives is kept', 'the-key' === WPAQS_Fleet::key() );
check( 'and the site is enrolled', true === WPAQS_Fleet::enrolled() );

// ---------------------------------------------------------------- reporting

reset_site();

$record = array( 'started_at' => 100, 'completed_at' => 200, 'findings' => array( array( 'id' => 'a' ) ) );
$result = WPAQS_Fleet::push( $record, 'run-1' );

// Without a key there is nothing to authenticate with, and posting anyway would put a
// report where nobody could attribute it.
check( 'a site with no key cannot report', '' !== $result['error'] );
check( 'and nothing was sent', 0 === count( $GLOBALS['posts'] ) );

$GLOBALS['options']['wpaqs_fleet'] = array( 'key' => 'the-key' );
WPAQS_Fleet::push( $record, 'run-1' );

$post = last_post();
$body = json_decode( $post['args']['body'], true );

check( 'an enrolled site reports', false !== strpos( $post['url'], '/ingest' ) );
check( 'and carries the run id', 'run-1' === $body['scanRunId'] );
check( 'and the findings from the export', 1 === count( $body['findings'] ) );
check( 'and the plugin version', '0.8.6' === $body['pluginVersion'] );

// ------------------------------------------------- what must never be in a request

// A key in a query string survives in every access log between here and the console.
foreach ( $GLOBALS['posts'] as $sent ) {
	check(
		'the key is never in a URL',
		false === strpos( $sent['url'], 'the-key' ),
		$sent['url']
	);
}

check( 'the key travels in a header', 'Bearer the-key' === $post['args']['headers']['Authorization'] );

// The updater's reasoning applies here too: this is the second place the plugin talks
// to a server the site does not control.
check( 'TLS verification is never relaxed', true === $post['args']['sslverify'] );
check( 'redirects are never followed', 0 === $post['args']['redirection'] );

// --------------------------------------------------------- refusals are recorded

reset_site();
$GLOBALS['options']['wpaqs_fleet'] = array( 'key' => 'the-key' );
$GLOBALS['reply'] = array( 'code' => 401, 'body' => array( 'error' => 'unauthenticated' ) );

$result = WPAQS_Fleet::push( $record, 'run-1' );

check( 'a refusal is reported back', '' !== $result['error'] );
check( 'and says what the console said', false !== stripos( $result['error'], 'unauthenticated' ), $result['error'] );

$state = WPAQS_Fleet::state();
check( 'and is remembered for the screen', ! empty( $state['last_error'] ) );

reset_site();
$GLOBALS['options']['wpaqs_fleet'] = array( 'key' => 'the-key' );
$GLOBALS['reply']['wp_error'] = true;

$result = WPAQS_Fleet::push( $record, 'run-1' );
check( 'a console that cannot be reached is not a crash', '' !== $result['error'] );

// ------------------------------------------------------ what may not be here

$source = file_get_contents( dirname( __DIR__ ) . '/wordpress-access-quick-scan/includes/class-fleet.php' );
$code   = '';

foreach ( token_get_all( $source ) as $token ) {
	if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
		continue;
	}

	$code .= is_array( $token ) ? $token[1] : $token;
}

// The class does not name its own siblings. WPAQS_Report and WPAQS_VERSION are reached
// through the derived prefix, because sed rewrites the class name and a hardcoded
// reference to another class would survive it pointing at the wrong plugin.
foreach ( array( 'WPAQS_Report', 'WPAQS_VERSION' ) as $literal ) {
	check(
		"the shared file does not name $literal directly",
		false === strpos( $code, $literal ),
		'it is copied to the sibling, so its own name has to be derived'
	);
}

// The text domain stays a literal on purpose: i18n tooling cannot extract a computed
// one, and contorting it to satisfy a sed invocation would be the tail wagging the dog.
// What keeps the two copies honest is tests/shared-files.php, which normalises the
// prefix in both directions and compares recorded hashes.

check(
	'nothing here relaxes TLS',
	false === strpos( $code, 'sslverify\' => false' ) && false === strpos( $code, 'sslverify" => false' )
);

// ------------------------------------------------------- what a failed push means

// pushed_at used to be written on every attempt, which made a site whose first push
// failed indistinguishable from one that had reported. The hourly fleet check asks
// exactly that question before deciding whether to retry, so one failed attempt meant
// the report was never sent again — silently, and on the run that matters most.
reset_site();
$GLOBALS['options']['wpaqs_fleet'] = array( 'key' => 'a-key' );
$GLOBALS['reply']                  = array( 'code' => 500, 'body' => array( 'error' => 'boom' ) );
WPAQS_Fleet::push( array( 'findings' => array() ), 'run-1' );
$state = WPAQS_Fleet::state();
check( 'a failed push does not claim the report was sent', empty( $state['pushed_at'] ) );
check( 'a failed push records why', ! empty( $state['last_error'] ) );

reset_site();
$GLOBALS['options']['wpaqs_fleet'] = array( 'key' => 'a-key' );
WPAQS_Fleet::push( array( 'findings' => array() ), 'run-1' );
$state = WPAQS_Fleet::state();
check( 'a push that worked records when', ! empty( $state['pushed_at'] ) );

// ------------------------------------------------- a key the console no longer knows

// 401 is the console saying it does not know this key: the site was removed from the
// fleet, or its key was revoked. Staying enrolled would leave the site believing for
// ever that it reports to something, while the console has forgotten it exists.
reset_site();
$GLOBALS['options']['wpaqs_fleet'] = array(
	'key'           => 'a-key',
	'enrolled_at'   => 100,
	'requested_at'  => 50,
	'polled_at'     => 90,
	'install_nonce' => 'the-install',
);
$GLOBALS['reply'] = array( 'code' => 401, 'body' => array( 'error' => 'unauthenticated' ) );
WPAQS_Fleet::push( array( 'findings' => array() ), 'run-1' );
check( 'a 401 un-enrols the site', ! WPAQS_Fleet::enrolled() );

$state = WPAQS_Fleet::state();
check( 'and clears the request, so the site asks again', empty( $state['requested_at'] ) );

// Identifies the installation, not the enrolment. A fresh one would make a re-approval
// indistinguishable from a different install at the same address.
check( 'but keeps the install nonce', 'the-install' === $state['install_nonce'] );

// Every other refusal is the console being unhappy with this particular report, and
// throwing the key away over one of those would take the site out of the fleet for a
// reason that has nothing to do with whether it belongs there.
reset_site();
$GLOBALS['options']['wpaqs_fleet'] = array( 'key' => 'a-key' );
$GLOBALS['reply']                  = array( 'code' => 400, 'body' => array( 'error' => 'bad-body' ) );
WPAQS_Fleet::push( array( 'findings' => array() ), 'run-1' );
check( 'a 400 leaves the site enrolled', WPAQS_Fleet::enrolled() );

reset_site();
$GLOBALS['options']['wpaqs_fleet'] = array( 'key' => 'a-key' );
$GLOBALS['reply']                  = array( 'wp_error' => true );
WPAQS_Fleet::push( array( 'findings' => array() ), 'run-1' );
check( 'a console that cannot be reached leaves the site enrolled', WPAQS_Fleet::enrolled() );

// ------------------------------------------------ what the last report described

// The state the screen had no words for. "Last report sent 13 hours ago" beside "last
// completed scan 6:02 am" is one moment rendered twice, relative in one place and
// absolute in the other — reported as "the job ran but did not send", which is the
// correct thing to conclude from what it said. The panel can only name what was sent if
// the transport records it.
reset_site();
$GLOBALS['options']['wpaqs_fleet'] = array( 'key' => 'a-key' );
WPAQS_Fleet::push( array( 'findings' => array(), 'completed_at' => 1787000000 ), 'run-7' );
$state = WPAQS_Fleet::state();
check( 'a push records which scan it described', 1787000000 === $state['pushed_finished'] );
check( 'and which run it was', 'run-7' === $state['pushed_run'] );

// A live read has no scan to name, and inventing one would have the panel print a
// finish time for something that never finished.
reset_site();
$GLOBALS['options']['wpaqs_fleet'] = array( 'key' => 'a-key' );
WPAQS_Fleet::push( array( 'findings' => array() ), 'run-8' );
$state = WPAQS_Fleet::state();
check( 'a report with no scan behind it records no finish time', 0 === $state['pushed_finished'] );

// A failed push must not claim to have described anything, or the panel names a scan the
// console was never given.
reset_site();
$GLOBALS['options']['wpaqs_fleet'] = array( 'key' => 'a-key' );
$GLOBALS['reply']                  = array( 'code' => 500, 'body' => array( 'error' => 'boom' ) );
WPAQS_Fleet::push( array( 'findings' => array(), 'completed_at' => 1787000000 ), 'run-9' );
$state = WPAQS_Fleet::state();
check( 'a failed push records no scan either', ! isset( $state['pushed_finished'] ) );

// Forgetting has to drop these too. A re-enrolled site carrying them would claim to have
// sent a report the console has never seen, which is the original fault with a new cause.
reset_site();
$GLOBALS['options']['wpaqs_fleet'] = array(
	'key'             => 'a-key',
	'pushed_at'       => 100,
	'pushed_run'      => 'run-1',
	'pushed_finished' => 90,
	'install_nonce'   => 'the-install',
);
$GLOBALS['reply'] = array( 'code' => 401, 'body' => array( 'error' => 'unauthenticated' ) );
WPAQS_Fleet::push( array( 'findings' => array() ), 'run-10' );
$state = WPAQS_Fleet::state();
check( 'forgetting drops what was sent as well as the key', ! isset( $state['pushed_run'] ) && ! isset( $state['pushed_finished'] ) );

printf( "\n%d failure(s)\n", $failures );
exit( $failures > 0 ? 1 : 0 );
