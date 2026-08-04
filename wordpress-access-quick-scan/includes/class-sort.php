<?php
/**
 * Column sorting, done on the server.
 *
 * The sibling plugin sorts its tables in JavaScript and paid for it twice: column indices
 * shifted by one because the script read `thead th` while the body row starts with a `td`,
 * and sorting installed behind an early return that fired on any table shorter than one
 * page. Neither bug is available here, because none of this runs in a browser.
 *
 * It can be a page reload because this screen has no state to lose: no scan, no stored
 * report, everything read live on every request. Sorting is a `usort` and a link.
 *
 * Sorting is also on the **data** rather than on the rendered text, which is the part a
 * client-side sort gets wrong: "never" sorts before every real date because it is a zero
 * timestamp, not because of where `n` falls in the alphabet.
 *
 * @package WPAQS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads a sort request and applies it.
 */
class WPAQS_Sort {

	/**
	 * What the request asks for, once it has been checked against what the table offers.
	 *
	 * The key is validated against an allowlist rather than sanitized and trusted: it names a
	 * column, and a name the table does not have is not a column.
	 *
	 * @param string $table   Table identifier, so two tables on one screen sort separately.
	 * @param array  $allowed Column keys this table can sort by. The first is the default.
	 * @return array array( key, dir )
	 */
	public static function requested( $table, array $allowed ) {
		$default = array(
			'key' => isset( $allowed[0] ) ? $allowed[0] : '',
			'dir' => 'asc',
		);

		if ( empty( $allowed ) ) {
			return $default;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading a sort order changes nothing.
		$key = isset( $_GET[ self::key_arg( $table ) ] ) ? sanitize_key( wp_unslash( $_GET[ self::key_arg( $table ) ] ) ) : '';

		if ( ! in_array( $key, $allowed, true ) ) {
			return $default;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading a sort order changes nothing.
		$dir = isset( $_GET[ self::dir_arg( $table ) ] ) ? sanitize_key( wp_unslash( $_GET[ self::dir_arg( $table ) ] ) ) : 'asc';

		return array(
			'key' => $key,
			'dir' => 'desc' === $dir ? 'desc' : 'asc',
		);
	}

	/**
	 * Sort rows by a value pulled out of each one.
	 *
	 * Ties keep the order they arrived in. Without that, two accounts registered the same day
	 * would swap places between one page load and the next, which reads as the list changing
	 * when nothing has.
	 *
	 * @param array    $rows    Rows.
	 * @param string   $dir     'asc' or 'desc'.
	 * @param callable $extract Given a row, returns the value to sort on.
	 * @return array
	 */
	public static function apply( array $rows, $dir, $extract ) {
		$indexed = array();

		foreach ( array_values( $rows ) as $position => $row ) {
			$indexed[] = array(
				'position' => $position,
				'value'    => call_user_func( $extract, $row ),
				'row'      => $row,
			);
		}

		$descending = ( 'desc' === $dir );

		usort(
			$indexed,
			function ( $a, $b ) use ( $descending ) {
				$compare = self::compare( $a['value'], $b['value'] );

				if ( 0 === $compare ) {
					// Stable: the original order breaks the tie, in both directions.
					return $a['position'] - $b['position'];
				}

				return $descending ? -$compare : $compare;
			}
		);

		$sorted = array();

		foreach ( $indexed as $entry ) {
			$sorted[] = $entry['row'];
		}

		return $sorted;
	}

	/**
	 * Compare two sort values.
	 *
	 * Numbers compare as numbers so a timestamp of zero — "never" — lands before every real
	 * date rather than wherever its digits fall. Strings compare case-insensitively, because a
	 * list of logins sorted with the capitals first is not sorted as far as a reader is
	 * concerned.
	 *
	 * @param mixed $a First value.
	 * @param mixed $b Second value.
	 * @return int
	 */
	private static function compare( $a, $b ) {
		if ( is_numeric( $a ) && is_numeric( $b ) ) {
			if ( (float) $a === (float) $b ) {
				return 0;
			}

			return ( (float) $a < (float) $b ) ? -1 : 1;
		}

		return strcasecmp( (string) $a, (string) $b );
	}

	/**
	 * The link that sorts a table by one column.
	 *
	 * Pressing the column already sorted reverses it, which is what every table in wp-admin
	 * does. The anchor is part of it: sorting reloads the page, and a reload that lands at the
	 * top having thrown away which section was open reads as the button doing something else.
	 *
	 * @param string $table   Table identifier.
	 * @param string $key     Column key.
	 * @param array  $current Result of requested().
	 * @param string $anchor  Element id to return to.
	 * @return string
	 */
	public static function url( $table, $key, array $current, $anchor ) {
		$active = ( $current['key'] === $key );
		$dir    = ( $active && 'asc' === $current['dir'] ) ? 'desc' : 'asc';

		// Through WPAQS_Screen rather than built here. This used to start from
		// admin_url( 'tools.php?page=' . WPAQS_SLUG ) and add only the two sort arguments,
		// which was correct while sorting was the only control on the screen and silently
		// wrong the moment a filter arrived: pressing a column header would have dropped the
		// filter, leaving a table showing everything under a control that said otherwise.
		return WPAQS_Screen::url(
			array(
				self::key_arg( $table ) => $key,
				self::dir_arg( $table ) => $dir,
			),
			$anchor
		);
	}

	/**
	 * Which way an active column is pointing, for the arrow beside it.
	 *
	 * @param string $key     Column key.
	 * @param array  $current Result of requested().
	 * @return string 'asc', 'desc' or '' when this column is not the one sorting.
	 */
	public static function indicator( $key, array $current ) {
		return $current['key'] === $key ? $current['dir'] : '';
	}

	/**
	 * Whether any table on the screen was asked to sort.
	 *
	 * A section holding a sorted table opens even when it is closed by default: arriving back
	 * at a shut section after pressing one of its headers looks like nothing happened.
	 *
	 * @param string $table Table identifier.
	 * @return bool
	 */
	public static function is_active( $table ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading a sort order changes nothing.
		return isset( $_GET[ self::key_arg( $table ) ] );
	}

	/**
	 * Query argument holding the column.
	 *
	 * @param string $table Table identifier.
	 * @return string
	 */
	public static function key_arg( $table ) {
		return 'wpaqs_' . sanitize_key( $table ) . '_by';
	}

	/**
	 * Query argument holding the direction.
	 *
	 * @param string $table Table identifier.
	 * @return string
	 */
	public static function dir_arg( $table ) {
		return 'wpaqs_' . sanitize_key( $table ) . '_dir';
	}
}
