<?php
/**
 * Uninstall completeness.
 *
 * Every option and transient the plugin writes has to be removed when it is deleted.
 *
 * This plugin was built storing nothing at all — every screen reads the database live and
 * throws the result away — and `uninstall.php` said so in a note ending "the moment anything is
 * written, its name belongs here". Then the updater arrived and wrote one, and the only thing
 * standing between that note and being wrong was somebody remembering to read it.
 *
 * The sibling's version of this file exists because its list had already fallen behind once:
 * `uninstall.php` cleaned three names while the plugin had grown to writing eight. It has since
 * caught two more. Names are discovered from the source rather than listed here, because a list
 * maintained by hand is the thing that went stale in the first place.
 */

$plugin = dirname( __DIR__ ) . '/wordpress-access-quick-scan';

$failures = 0;

/**
 * Assert, and print the result either way.
 *
 * @param string $label  What is being checked.
 * @param bool   $ok     Whether it holds.
 * @param string $detail Shown after the label.
 * @return void
 */
function check( $label, $ok, $detail = '' ) {
	global $failures;

	if ( ! $ok ) {
		$failures++;
	}

	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, '' === $detail ? '' : ' — ' . $detail );
}

$uninstall = file_get_contents( $plugin . '/uninstall.php' );

check( 'uninstall.php refuses to run on its own', false !== strpos( $uninstall, 'WP_UNINSTALL_PLUGIN' ) );

// Names the plugin reads or writes, however they are spelled in the source.
$written = array();

// The main plugin file too, where constants are declared — exactly where a blind spot would
// hide the names most likely to go stale.
$sources = array_merge( glob( $plugin . '/includes/*.php' ), glob( $plugin . '/*.php' ) );

foreach ( $sources as $file ) {
	if ( 'uninstall.php' === basename( $file ) ) {
		continue;
	}

	$source = file_get_contents( $file );

	$patterns = array(
		// Direct calls with a literal name. The site_ variants are here from the start: the one
		// name this plugin stores is a site transient, and without them it would be invisible.
		'~(?:update_option|get_option|delete_option|update_site_option|get_site_option|delete_site_option|set_transient|get_transient|delete_transient|set_site_transient|get_site_transient|delete_site_transient)\(\s*\'(wpaqs_[a-z_]+)\'~',
		// Names held in class constants and used through those.
		'~const\s+[A-Z_]+\s*=\s*\'(wpaqs_[a-z_]+)\'~',
		// define( 'WPAQS_SOMETHING', 'wpaqs_something' ) in the main plugin file.
		'~define\(\s*\'[A-Z_]+\',\s*\'(wpaqs_[a-z_]+)\'~',
	);

	foreach ( $patterns as $pattern ) {
		if ( preg_match_all( $pattern, $source, $matches ) ) {
			foreach ( $matches[1] as $name ) {
				$written[ $name ] = true;
			}
		}
	}
}

// Prefixed names that are not storage. Nothing lives under any of these, so uninstall has
// nothing to delete for them — but the discovery above cannot tell a name from a place, so each
// is listed deliberately rather than the pattern being loosened. A name that belongs here costs
// one line; a name wrongly here means something survives uninstall silently, which is exactly
// what this file exists to prevent.
$not_storage = array(
	// The shared nonce action prefix.
	'wpaqs_access',
	// The admin-post action that re-checks for a release.
	'wpaqs_check_release',
);

foreach ( $not_storage as $name ) {
	unset( $written[ $name ] );
}

check( 'the plugin writes a discoverable set of names', count( $written ) >= 1, implode( ', ', array_keys( $written ) ) );

foreach ( array_keys( $written ) as $name ) {
	check( 'uninstall removes ' . $name, false !== strpos( $uninstall, $name ), $name );
}

// The one name this plugin stores is a site transient, and on multisite the plain function
// looks in the wrong place and leaves the row behind — so a passing name check is not enough,
// the call has to be the right one.
check(
	'and does so with the site-transient function',
	false !== strpos( $uninstall, 'delete_site_transient' ),
	'the plain one looks in the wrong place on multisite'
);

// Uninstall must clean up after this plugin and nothing else. A stray delete_option here would
// remove somebody else's settings on the way out, which is not a thing to discover afterwards.
if ( preg_match_all( '~delete_(?:site_)?option\(\s*\'([^\']+)\'~', $uninstall, $matches ) ) {
	foreach ( $matches[1] as $name ) {
		check( 'only deletes its own option: ' . $name, 0 === strpos( $name, 'wpaqs_' ) );
	}
}

if ( preg_match_all( '~delete_(?:site_)?transient\(\s*\'([^\']+)\'~', $uninstall, $matches ) ) {
	foreach ( $matches[1] as $name ) {
		check( 'only deletes its own transient: ' . $name, 0 === strpos( $name, 'wpaqs_' ) );
	}
}

// The hard constraint, at the point where a plugin is most tempted to tidy up: uninstalling
// this one must not touch a single account, session, application password or role. Everything
// it ever reported still belongs to the site, and the record of what an account did is the whole
// reason the plugin refuses to delete users while it is running.
$forbidden = array(
	'wp_delete_user',
	'wpmu_delete_user',
	'delete_user_meta',
	'update_user_meta',
	'wp_destroy_all_sessions',
	'set_role',
	'remove_cap',
	'add_cap',
	'wp_delete_post',
	'wp_trash_post',
);

$code = '';

foreach ( token_get_all( $uninstall ) as $token ) {
	if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
		continue;
	}

	$code .= is_array( $token ) ? $token[1] : $token;
}

foreach ( $forbidden as $call ) {
	check(
		'uninstall never calls ' . $call,
		false === strpos( $code, $call ),
		'deleting this plugin must not touch a single thing it reported on'
	);
}

printf( "\n%d failure(s)\n", $failures );
exit( $failures > 0 ? 1 : 0 );
