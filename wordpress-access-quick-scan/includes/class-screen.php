<?php
/**
 * The screen's own URL, with every control it carries preserved.
 *
 * This exists because sorting and filtering are both links, and the first version of sorting
 * built its link from `admin_url( 'tools.php?page=' . WPAQS_SLUG )` — from scratch. That is
 * correct while sorting is the only control on the screen and silently wrong the moment there
 * is a second one: pressing a column header would have thrown the filter away, and the table
 * would have gone back to showing everything while the filter still looked applied.
 *
 * That is the same shape as the sibling plugin's autostart loop — a control that acts on the
 * query string, and a second thing that rewrites the query string without knowing about it.
 * The answer is that nothing builds this URL by hand.
 *
 * Arguments are read through an allowlist rather than by copying `$_GET`, so a link on this
 * screen can never carry something a request put there.
 *
 * @package WPAQS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds links back to this screen.
 */
class WPAQS_Screen {

	/**
	 * Query arguments this screen owns.
	 *
	 * Everything a control on this screen can set. Anything else in the request is not carried
	 * forward, which includes the action results: a notice arriving from `admin-post.php`
	 * should not be re-shown by pressing a column header.
	 *
	 * @return array
	 */
	public static function args() {
		$args = array();

		foreach ( array( 'accounts', 'passwords', 'code' ) as $table ) {
			$args[] = 'wpaqs_' . $table . '_by';
			$args[] = 'wpaqs_' . $table . '_dir';
			$args[] = 'wpaqs_' . $table . '_show';
		}

		return $args;
	}

	/**
	 * A link to this screen, carrying the controls already applied.
	 *
	 * @param array  $overrides Argument => value to set or, when the value is '', to drop.
	 * @param string $anchor    Element id to return to, without the hash.
	 * @return string
	 */
	public static function url( array $overrides = array(), $anchor = '' ) {
		$carried = array();

		foreach ( self::args() as $arg ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading which view is shown changes nothing.
			if ( isset( $_GET[ $arg ] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading which view is shown changes nothing.
				$carried[ $arg ] = sanitize_key( wp_unslash( $_GET[ $arg ] ) );
			}
		}

		foreach ( $overrides as $arg => $value ) {
			if ( ! in_array( $arg, self::args(), true ) ) {
				// A caller asking for an argument this screen does not own is a mistake, and
				// putting it in the URL anyway would make the allowlist decorative.
				continue;
			}

			if ( '' === $value ) {
				unset( $carried[ $arg ] );

				continue;
			}

			$carried[ $arg ] = $value;
		}

		$url = add_query_arg( $carried, admin_url( 'tools.php?page=' . WPAQS_SLUG ) );

		return '' === $anchor ? $url : $url . '#' . $anchor;
	}
}
