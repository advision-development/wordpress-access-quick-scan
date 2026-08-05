<?php
/**
 * Updates from this plugin's own releases.
 *
 * This is the only class that hands WordPress a URL to download, unzip over the plugin
 * directory and run, so the assertions are mostly refusals: a package hosted anywhere but the
 * pinned repository, a tag that is not a version, a release with no zip, and a version that is
 * not newer than the one installed.
 *
 * The version comparison has its own section because the failure is silent in both directions:
 * an unpadded compare either hides an available update or offers a downgrade, and both look
 * like the updater simply not working.
 */

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

/**
 * The bit of WP_Error this file needs.
 */
class WP_Error {

	public $message;

	public function __construct( $message = '' ) {
		$this->message = $message;
	}
}

function wp_remote_retrieve_response_code( $response ) {
	return isset( $response['response']['code'] ) ? $response['response']['code'] : 0;
}

function wp_remote_retrieve_body( $response ) {
	return isset( $response['body'] ) ? $response['body'] : '';
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

function plugin_basename( $file ) {
	return 'wordpress-access-quick-scan/wordpress-access-quick-scan.php';
}

function esc_html__( $text, $domain = '' ) {
	return $text;
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function get_site_transient( $key ) {
	return isset( $GLOBALS['site_transients'][ $key ] ) ? $GLOBALS['site_transients'][ $key ] : false;
}

function set_site_transient( $key, $value, $ttl = 0 ) {
	$GLOBALS['site_transients'][ $key ] = $value;

	return true;
}

function delete_site_transient( $key ) {
	unset( $GLOBALS['site_transients'][ $key ] );

	return true;
}

function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['hooks'][] = $hook;
}

function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['hooks'][] = $hook;
}

$GLOBALS['site_transients'] = array();
$GLOBALS['hooks']           = array();

require __DIR__ . '/bootstrap.php';

define( 'WPAQS_FILE', WPAQS_DIR . 'wordpress-access-quick-scan.php' );

load_class( 'updater' );

/**
 * An API response with a given body.
 *
 * @param mixed $body JSON-encodable body, or a raw string.
 * @param int   $code HTTP status.
 * @return array
 */
function responded( $body, $code = 200 ) {
	return array(
		'response' => array( 'code' => $code ),
		'body'     => is_string( $body ) ? $body : wp_json_encode( $body ),
	);
}

/**
 * A release body shaped like GitHub's.
 *
 * @param string $tag    Tag name.
 * @param array  $assets Assets.
 * @return array
 */
function release_body( $tag, array $assets ) {
	return array(
		'tag_name'     => $tag,
		'body'         => 'Release notes.',
		'published_at' => '2026-08-05T12:00:00Z',
		'assets'       => $assets,
	);
}

/**
 * An asset shaped like GitHub's.
 *
 * @param string $name Filename.
 * @param string $url  Download URL.
 * @return array
 */
function asset( $name, $url ) {
	return array( 'name' => $name, 'browser_download_url' => $url );
}

$good = 'https://github.com/advision-development/wordpress-access-quick-scan/releases/download/v9.0.0/wordpress-access-quick-scan-9.0.0.zip';

// ------------------------------------------------------------------ the happy path

$release = WPAQS_Updater::parse( responded( release_body( 'v9.0.0', array( asset( 'wordpress-access-quick-scan-9.0.0.zip', $good ) ) ) ) );

check( 'a release is read', ! empty( $release ) );
check( 'and its version comes off the tag without the v', '9.0.0' === $release['version'], $release['version'] );
check( 'and the package is the asset URL', $good === $release['package'] );
check( 'and the notes come through', 'Release notes.' === $release['notes'] );

// ------------------------------------------------------- where a package may come from

// The pinning. Everything in the response is remote text, including the URL WordPress is about
// to download and unzip over the plugin directory.
$elsewhere = array(
	'a different host'                 => 'https://evil.test/advision-development/wordpress-access-quick-scan/releases/download/v9.0.0/wordpress-access-quick-scan-9.0.0.zip',
	// Refused by the prefix rather than by a host comparison: a URL's authority ends at the
	// first slash, so a prefix reaching into the path pins the host. This case is the reason
	// that is true and worth keeping even though one check covers it.
	'a host that merely starts the same' => 'https://github.com.evil.test/advision-development/wordpress-access-quick-scan/releases/download/v9.0.0/wordpress-access-quick-scan-9.0.0.zip',
	// The prefix pins the host but not the repository: HTTP clients resolve `..` out of a path
	// before sending it, so this one starts with the prefix and downloads from whatever
	// account the traversal lands on — still github.com, still a 200, not this plugin.
	'another repository reached by traversal' => 'https://github.com/advision-development/wordpress-access-quick-scan/releases/download/../../../../someone-else/their-repo/releases/download/v1/wordpress-access-quick-scan-9.0.0.zip',
	'a different owner'                => 'https://github.com/somebody-else/wordpress-access-quick-scan/releases/download/v9.0.0/wordpress-access-quick-scan-9.0.0.zip',
	'a different repository'           => 'https://github.com/advision-development/something-else/releases/download/v9.0.0/wordpress-access-quick-scan-9.0.0.zip',
	'plain http'                       => 'http://github.com/advision-development/wordpress-access-quick-scan/releases/download/v9.0.0/wordpress-access-quick-scan-9.0.0.zip',
);

foreach ( $elsewhere as $label => $url ) {
	check(
		'a package on ' . $label . ' is refused',
		'' === WPAQS_Updater::package_in( array( asset( 'wordpress-access-quick-scan-9.0.0.zip', $url ) ) ),
		'the URL is checked against the pinned repository, not trusted'
	);
}

// A release carrying several files must not have one of the others installed as the plugin.
check(
	'an asset that is not this plugin\'s zip is refused',
	'' === WPAQS_Updater::package_in(
		array( asset( 'notes.txt', 'https://github.com/advision-development/wordpress-access-quick-scan/releases/download/v9.0.0/notes.txt' ) )
	)
);

check(
	'and one named after another plugin is refused',
	'' === WPAQS_Updater::package_in(
		array( asset( 'wordpress-malware-quick-scan-9.0.0.zip', 'https://github.com/advision-development/wordpress-access-quick-scan/releases/download/v9.0.0/wordpress-malware-quick-scan-9.0.0.zip' ) )
	)
);

// The right asset is found even when it is not the first.
check(
	'the right asset is picked out of several',
	$good === WPAQS_Updater::package_in(
		array(
			asset( 'notes.txt', 'https://github.com/advision-development/wordpress-access-quick-scan/releases/download/v9.0.0/notes.txt' ),
			asset( 'wordpress-access-quick-scan-9.0.0.zip', $good ),
		)
	)
);

check( 'no assets is no package', '' === WPAQS_Updater::package_in( array() ) );
check( 'and a malformed asset list is no package', '' === WPAQS_Updater::package_in( 'not an array' ) );
check( 'and an asset with no URL is skipped', '' === WPAQS_Updater::package_in( array( array( 'name' => 'x.zip' ) ) ) );

// A release with no zip this plugin recognises offers nothing. Installing the tag's source
// archive instead would unzip a directory named after the tag beside the plugin rather than
// over it, leaving two copies installed.
check(
	'a release with no usable asset is not offered',
	array() === WPAQS_Updater::parse( responded( release_body( 'v9.0.0', array() ) ) ),
	'WordPress would otherwise install the source archive beside the plugin'
);

// ------------------------------------------------------------------- what a tag may be

check( 'a plain version tag is read', '1.2.3' === WPAQS_Updater::version_of( '1.2.3' ) );
check( 'a v-prefixed one too', '1.2.3' === WPAQS_Updater::version_of( 'v1.2.3' ) );
check( 'and whitespace is trimmed', '1.2.3' === WPAQS_Updater::version_of( "  v1.2.3\n" ) );

foreach ( array( 'main', 'v1.2.3-beta', 'release-1', '1.2.3.4', '', 'v', '1.2.x' ) as $tag ) {
	check(
		'the tag "' . $tag . '" is not a version',
		'' === WPAQS_Updater::version_of( $tag ),
		'a tag this plugin does not publish is not something to compare against'
	);
}

check( 'a tag that is not a version is not a release', array() === WPAQS_Updater::parse( responded( release_body( 'main', array( asset( 'wordpress-access-quick-scan-9.0.0.zip', $good ) ) ) ) ) );

// ----------------------------------------------------------------- the comparison

// version_compare( '1.2', '1.2.0' ) reports less-than, so an unpadded compare against a
// two-component header clears a site that has an update waiting.
check( '1.2 and 1.2.0 are the same version', ! WPAQS_Updater::is_newer( '1.2', '1.2.0' ) );
check( 'and neither is newer than the other', ! WPAQS_Updater::is_newer( '1.2.0', '1.2' ) );

// The other comparison people get wrong: as text, 0.10 sorts before 0.9.
check( '0.10.0 is newer than 0.9.0', WPAQS_Updater::is_newer( '0.10.0', '0.9.0' ) );
check( 'and 0.9.0 is not newer than 0.10.0', ! WPAQS_Updater::is_newer( '0.9.0', '0.10.0' ) );

check( '0.7.0 is newer than 0.6.0', WPAQS_Updater::is_newer( '0.7.0', '0.6.0' ) );
check( 'the same version is not newer', ! WPAQS_Updater::is_newer( '0.6.0', '0.6.0' ) );

// Never downgrade. A repository whose latest release is behind the installed copy — a release
// deleted, a tag moved — must not roll the site back.
check(
	'an older release is never offered',
	! WPAQS_Updater::is_newer( '0.5.0', '0.6.0' ),
	'a moved or deleted tag must not roll a site back'
);

check( 'a version that is not a version is never newer', ! WPAQS_Updater::is_newer( 'main', '0.6.0' ) );
check( 'and an unreadable installed version offers nothing', ! WPAQS_Updater::is_newer( '9.0.0', 'nonsense' ) );

check( 'padding fills to three components', '1.0.0' === WPAQS_Updater::normalize( '1' ) );
check( 'and leaves three alone', '1.2.3' === WPAQS_Updater::normalize( '1.2.3' ) );
check( 'and rejects what is not a version', '' === WPAQS_Updater::normalize( '1.2.3-rc1' ) );

// ------------------------------------------------------------- bad responses

check( 'a transport error is no release', array() === WPAQS_Updater::parse( new WP_Error( 'down' ) ) );
check( 'a 404 is no release', array() === WPAQS_Updater::parse( responded( release_body( 'v9.0.0', array() ), 404 ) ) );
check( 'a 403 is no release', array() === WPAQS_Updater::parse( responded( '', 403 ) ) );

// A rate-limited GitHub answers 403 with a JSON body that has no tag_name. It must not be read
// as "no update available" in a way that hides one, nor crash.
check( 'a rate-limit body is no release', array() === WPAQS_Updater::parse( responded( array( 'message' => 'API rate limit exceeded' ), 403 ) ) );

check( 'an empty body is no release', array() === WPAQS_Updater::parse( responded( '' ) ) );
check( 'HTML instead of JSON is no release', array() === WPAQS_Updater::parse( responded( '<html>nope</html>' ) ) );
check( 'a JSON array is no release', array() === WPAQS_Updater::parse( responded( array( 1, 2, 3 ) ) ) );
check( 'a body with no tag is no release', array() === WPAQS_Updater::parse( responded( array( 'assets' => array() ) ) ) );

// ------------------------------------------------------- the transient WordPress reads

$_transient           = new stdClass();
$_transient->response = array();
$_transient->no_update = array();

$GLOBALS['site_transients'] = array(
	'wpaqs_release' => array(
		'version'   => '9.9.9',
		'package'   => $good,
		'notes'     => '',
		'published' => '',
	),
);

$offered = WPAQS_Updater::offer( $_transient );
$file    = 'wordpress-access-quick-scan/wordpress-access-quick-scan.php';

check( 'a newer release lands in the transient', isset( $offered->response[ $file ] ) );
check( 'under this plugin\'s own file', $file === $offered->response[ $file ]->plugin );
check( 'with the version to install', '9.9.9' === $offered->response[ $file ]->new_version );
check( 'and the package to install it from', $good === $offered->response[ $file ]->package );

// WordPress reads no_update to decide whether the row offers automatic updates at all, so an
// up-to-date plugin has to say so rather than staying silent.
$GLOBALS['site_transients']['wpaqs_release']['version'] = '0.0.1';

$current = WPAQS_Updater::offer( $_transient );

check( 'an older release is not offered as an update', ! isset( $current->response[ $file ] ) );
check( 'and is reported as no update rather than omitted', isset( $current->no_update[ $file ] ) );

// Something else having filtered this into a different shape must be handed back untouched:
// building the object here would discard whatever that was.
check( 'a non-object transient is returned untouched', false === WPAQS_Updater::offer( false ) );
check( 'and so is a null one', null === WPAQS_Updater::offer( null ) );

// ------------------------------------------------------------- the details panel

$GLOBALS['site_transients']['wpaqs_release']['version'] = '9.9.9';

$info = WPAQS_Updater::details( false, 'plugin_information', (object) array( 'slug' => 'wordpress-access-quick-scan' ) );

check( 'the details panel answers for this plugin', is_object( $info ) && '9.9.9' === $info->version );
check( 'and names the download', $good === $info->download_link );

// Answering another plugin's request would replace its panel with this one's.
check(
	'and not for another plugin',
	false === WPAQS_Updater::details( false, 'plugin_information', (object) array( 'slug' => 'akismet' ) ),
	'answering would replace that plugin\'s panel with this one\'s'
);

check( 'nor for another action', false === WPAQS_Updater::details( false, 'query_plugins', (object) array( 'slug' => 'wordpress-access-quick-scan' ) ) );
check( 'nor a request with no slug', false === WPAQS_Updater::details( false, 'plugin_information', new stdClass() ) );

// Release notes are remote text rendered inside wp-admin, so they are escaped like every other
// untrusted string this plugin prints.
$GLOBALS['site_transients']['wpaqs_release']['notes'] = '<script>alert(1)</script>';

$escaped = WPAQS_Updater::details( false, 'plugin_information', (object) array( 'slug' => 'wordpress-access-quick-scan' ) );

check(
	'release notes are escaped',
	false === strpos( $escaped->sections['changelog'], '<script>' ),
	'the modal is no more trustworthy a place to print raw remote markup than the report is'
);

// ----------------------------------------------------------------- the source itself

$source = file_get_contents( WPAQS_DIR . 'includes/class-updater.php' );
$code   = '';

foreach ( token_get_all( $source ) as $token ) {
	if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
		continue;
	}

	$code .= is_array( $token ) ? $token[1] : $token;
}

// Tokenised, because the docblock says why verification is never turned off and an assertion
// that cannot tell a mention from a call fails on the comment written to prevent the call.
check(
	'TLS verification is never turned off',
	false === strpos( str_replace( ' ', '', $code ), "'sslverify'=>false" ),
	'a plugin that would rather install something than nothing is a delivery mechanism'
);

check( 'and it is turned on explicitly', false !== strpos( str_replace( ' ', '', $code ), "'sslverify'=>true" ) );

check(
	'nothing is fetched over plain http',
	false === strpos( $code, "'http://" ) && false === strpos( $code, '"http://' ),
	'the package is code, and this is the request that finds it'
);

// A failure has to be cached, or a rate-limited site retries on every admin page load and one
// site being rate-limited becomes every site sharing that IP.
check(
	'failures are cached too',
	false !== strpos( $code, 'self::FAILURE_TTL' ),
	'GitHub allows 60 unauthenticated requests an hour per IP, shared across a host'
);

// The cache is a site transient, so uninstall has to delete it as one: on multisite the plain
// function looks in the wrong place and leaves the row behind.
check(
	'the cached answer is named in uninstall.php',
	false !== strpos( file_get_contents( WPAQS_DIR . 'uninstall.php' ), WPAQS_Updater::CACHE ),
	'this is the first thing this plugin stores, and uninstall.php said it stored nothing'
);

check(
	'and deleted as a site transient',
	false !== strpos( file_get_contents( WPAQS_DIR . 'uninstall.php' ), 'delete_site_transient' )
);

// ------------------------------------------------- never updates itself unattended

// The one risk the pinning cannot reduce: a release genuinely published from the pinned
// repository by somebody who should not have been able to publish it is correctly hosted,
// correctly named and correctly signed, and every check in this file passes it. What is left is
// the plugin's own rule applied to itself — a person presses it.
$file = 'wordpress-access-quick-scan/wordpress-access-quick-scan.php';

check(
	'this plugin refuses to update itself unattended',
	false === WPAQS_Updater::never_automatically( true, (object) array( 'plugin' => $file ) ),
	'a compromised release would otherwise install itself on the next cron run'
);

check(
	'and refuses even when WordPress had not intended to',
	false === WPAQS_Updater::never_automatically( null, (object) array( 'plugin' => $file ) )
);

// Returning false for everything would quietly switch off automatic updates site-wide, which
// is a change to how the whole site is maintained and none of this plugin's business.
check(
	'another plugin keeps whatever WordPress decided',
	true === WPAQS_Updater::never_automatically( true, (object) array( 'plugin' => 'akismet/akismet.php' ) ),
	'returning false for everything would switch off automatic updates site-wide'
);

check( 'including when that was false', false === WPAQS_Updater::never_automatically( false, (object) array( 'plugin' => 'akismet/akismet.php' ) ) );
check( 'and a malformed item is left alone', true === WPAQS_Updater::never_automatically( true, 'not an object' ) );
check( 'as is one with no plugin file', true === WPAQS_Updater::never_automatically( true, new stdClass() ) );

// A control that silently does nothing reads as broken, so the missing toggle is explained
// where somebody wondering about it will look.
check(
	'the missing toggle says why it is missing',
	false !== stripos( WPAQS_Updater::explain_no_auto_update( '<toggle />', $file ), 'by hand on purpose' ),
	'a control that silently does nothing reads as broken'
);

check(
	'and another plugin keeps its own toggle',
	'<toggle />' === WPAQS_Updater::explain_no_auto_update( '<toggle />', 'akismet/akismet.php' )
);

finish();
