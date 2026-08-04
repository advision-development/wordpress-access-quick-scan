<?php
/**
 * Uninstall cleanup.
 *
 * There is nothing to clean. This plugin stores no options, no transients and no
 * scheduled events: every screen reads the database live and throws the result away when
 * the request ends.
 *
 * The file ships anyway, and says so. An absent uninstall.php reads as an oversight, and
 * the next person to add a stored option to this plugin should find this note rather than
 * an empty directory — the moment anything is written, its name belongs here.
 *
 * @package WPAQS
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) || ! defined( 'ABSPATH' ) ) {
	exit;
}

// Deliberately empty. See the note above before changing that.
