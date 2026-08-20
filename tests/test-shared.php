<?php
/**
 * The files copied between this plugin and its sibling have not drifted.
 *
 * Two active plugins declaring a class of the same name is a PHP fatal, so anything
 * shared is copied rather than required — and a copy is a thing that quietly stops
 * matching. Until now the rule was prose in CLAUDE.md, which is not a verification.
 *
 * The check compares a hash of each file with its prefix normalised, recorded in
 * shared-files.sha256. Both repositories carry the same expectations, so a change made
 * in one breaks *that* repository's own build rather than waiting to be noticed in the
 * other. That matters because CI checks out one repository and the sibling is not on
 * disk: a test that needed both would never run where it counts.
 *
 * Changing a shared file on purpose means changing it in both plugins and updating this
 * list in both — which is the point. It cannot be done in one place by accident.
 *
 * @package WPAQS
 */

$failures = 0;

function check( $label, $ok, $detail = '' ) {
	global $failures;

	if ( ! $ok ) {
		$failures++;
	}

	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, '' === $detail ? '' : ' — ' . $detail );
}

/**
 * A file with every trace of which plugin it belongs to removed.
 *
 * Both directions, upper and lower: the class prefix, the text domain, the option
 * names. What is left is the part that has to be identical.
 *
 * @param string $path File to read.
 * @return string
 */
function normalised( $path ) {
	$source = (string) file_get_contents( $path );

	// The bare prefix, not the underscored one: `@package WPAQS` carries no underscore
	// and would otherwise survive into the copy naming the wrong plugin — consistently
	// wrong in both, which is exactly the kind of drift a hash cannot see.
	return str_replace(
		array( 'WPAQS', 'WPAQS', 'wpaqs', 'wpaqs' ),
		array( 'PREFIX', 'PREFIX', 'prefix', 'prefix' ),
		$source
	);
}

$root     = dirname( __DIR__ );
$expected = require __DIR__ . '/shared-files.sha256';

// The plugin directory is named after the plugin, so the list stores PLUGIN/ and each
// repository resolves it to its own. Without that the two lists could not be identical,
// and a list that differs is one more thing to keep in step by hand.
$plugin = basename( (string) current( (array) glob( $root . '/*/includes' ) ) === '' ? '' : dirname( (string) current( (array) glob( $root . '/*/includes' ) ) ) );

check( 'the shared file list is not empty', ! empty( $expected ), 'an empty list would pass forever' );

foreach ( $expected as $relative => $hash ) {
	$path = $root . '/' . str_replace( 'PLUGIN/', $plugin . '/', $relative );

	if ( ! is_readable( $path ) ) {
		check( "$relative exists", false, 'a shared file that vanished is drift too' );

		continue;
	}

	$actual = hash( 'sha256', normalised( $path ) );

	check(
		"$relative matches the sibling",
		$hash === $actual,
		$hash === $actual ? '' : "expected $hash, got $actual — change it in both plugins and update shared-files.sha256 in both"
	);
}

// A file that is copied but not listed is the failure this cannot otherwise see: it
// drifts from the day it is written, and nothing complains.
$shared = glob( $root . '/*/includes/class-fleet*.php' );

foreach ( (array) $shared as $path ) {
	$relative = str_replace( $plugin . '/', 'PLUGIN/', ltrim( str_replace( $root, '', $path ), '/' ) );

	check(
		"$relative is listed as shared",
		isset( $expected[ $relative ] ),
		'a copied file nobody listed drifts from the day it is written'
	);
}

printf( "\n%d failure(s)\n", $failures );
exit( $failures > 0 ? 1 : 0 );
