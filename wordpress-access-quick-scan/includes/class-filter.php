<?php
/**
 * Showing only the rows that are doing something.
 *
 * A real site's account table opened on 140 rows of which nearly every one read "none open".
 * The information is correct and there is too much of it to read: the two accounts with a live
 * session are the answer to "is anybody in there right now", and they were somewhere in the
 * middle of a list that takes six screens to scroll.
 *
 * Filtering rather than sorting because the question is which rows to *stop* showing.
 *
 * **A filter that hides rows says how many it hid.** That is the same rule the coverage panel
 * and the account cap follow: a list showing two rows where a moment ago there were 140 is
 * indistinguishable from a list where 138 accounts were deleted, and "nothing here" has to
 * mean "nothing here" rather than "nothing here in this view".
 *
 * @package WPAQS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads a filter request and applies it.
 */
class WPAQS_Filter {

	/** Every row. */
	const ALL = 'all';

	/** Only rows with something open. */
	const ACTIVE = 'active';

	/**
	 * Which view the request asks for.
	 *
	 * Read through an allowlist for the same reason the sort column is: it arrives in the URL,
	 * and a value this screen does not offer is not a view.
	 *
	 * @param string $table Table identifier, so two tables filter separately.
	 * @return string
	 */
	public static function requested( $table ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading which view is shown changes nothing.
		$asked = isset( $_GET[ self::arg( $table ) ] ) ? sanitize_key( wp_unslash( $_GET[ self::arg( $table ) ] ) ) : '';

		return self::ACTIVE === $asked ? self::ACTIVE : self::ALL;
	}

	/**
	 * Keep the rows a predicate says are active.
	 *
	 * @param array    $rows   Rows.
	 * @param string   $view   Result of requested().
	 * @param callable $active Given a row, returns whether it is active.
	 * @return array array( rows, hidden, view )
	 */
	public static function apply( array $rows, $view, $active ) {
		if ( self::ACTIVE !== $view ) {
			return array(
				'rows'   => $rows,
				'hidden' => 0,
				'view'   => self::ALL,
			);
		}

		$kept = array();

		foreach ( $rows as $row ) {
			if ( call_user_func( $active, $row ) ) {
				$kept[] = $row;
			}
		}

		return array(
			'rows'   => $kept,
			// Counted rather than recomputed later: the caller has the filtered list from here
			// on and cannot tell what went missing.
			'hidden' => count( $rows ) - count( $kept ),
			'view'   => self::ACTIVE,
		);
	}

	/**
	 * The link that switches a table between the two views.
	 *
	 * @param string $table  Table identifier.
	 * @param string $view   The view currently shown.
	 * @param string $anchor Element id to return to.
	 * @return string
	 */
	public static function url( $table, $view, $anchor ) {
		// Dropped rather than set to 'all', so the address of an unfiltered screen is the
		// address of the screen.
		$next = self::ACTIVE === $view ? '' : self::ACTIVE;

		return WPAQS_Screen::url( array( self::arg( $table ) => $next ), $anchor );
	}

	/**
	 * Whether a table was asked to filter.
	 *
	 * A section holding a filtered table opens even when it is closed by default: arriving back
	 * at a shut section after pressing its own control looks like nothing happened.
	 *
	 * @param string $table Table identifier.
	 * @return bool
	 */
	public static function is_active( $table ) {
		return self::ACTIVE === self::requested( $table );
	}

	/**
	 * Query argument holding the view.
	 *
	 * @param string $table Table identifier.
	 * @return string
	 */
	private static function arg( $table ) {
		return 'wpaqs_' . sanitize_key( $table ) . '_show';
	}
}
