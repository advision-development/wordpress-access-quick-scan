<?php
/**
 * Accounts whose own content predates them.
 *
 * `wp_insert_post()` requires an author that already exists, so a post cannot be older
 * than the account that wrote it. When one is, the row did not arrive through WordPress:
 * something wrote the database directly, which is the vector that makes rotating a
 * password useless.
 *
 * This is the only rule in the plugin that is not a heuristic. Everything else here is a
 * shortlist to confirm; this one is arithmetic.
 *
 * Read-only.
 *
 * @package WPAQS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compares registration dates against authorship.
 */
class WPAQS_Authorship {

	/**
	 * Post types that say nothing about when an account started writing.
	 *
	 * An auto-draft is created before anybody types, and a revision inherits its parent's
	 * author, so neither is evidence about this account.
	 */
	const IGNORED_TYPES = array( 'auto-draft', 'revision' );

	/**
	 * Seconds of slack before a post counts as older than its author.
	 *
	 * Registration and a first post can land in the same second on an import, and a server
	 * whose clock stepped while both were written can put them a moment out of order. A
	 * minute is far below any real gap and far above either of those.
	 */
	const TOLERANCE = 60;

	/**
	 * The earliest post each account wrote, as a UTC timestamp.
	 *
	 * One grouped query rather than one per account: a membership site has thousands of
	 * users and this screen loads in a single request.
	 *
	 * Both date columns are read. WordPress fills `post_date_gmt`, but a row written
	 * straight into the database frequently carries a local `post_date` and leaves the GMT
	 * column at zero — and those are precisely the rows this check exists to catch, so
	 * reading only the GMT column would make them invisible.
	 *
	 * @return array User id => earliest post timestamp.
	 */
	public static function earliest_posts() {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return array();
		}

		$excluded     = self::IGNORED_TYPES;
		$placeholders = implode( ', ', array_fill( 0, count( $excluded ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- a reporting read with no cache to invalidate.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders are generated above, values are bound.
				"SELECT post_author, MIN( post_date_gmt ) AS oldest_gmt, MIN( post_date ) AS oldest_local
				 FROM {$wpdb->posts}
				 WHERE post_author > 0
				   AND post_type NOT IN ( {$placeholders} )
				 GROUP BY post_author",
				$excluded
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$earliest = array();

		foreach ( $rows as $row ) {
			$stamp = self::usable_stamp( $row );

			if ( $stamp > 0 ) {
				$earliest[ (int) $row->post_author ] = $stamp;
			}
		}

		return $earliest;
	}

	/**
	 * The earlier of the two date columns, ignoring a zero GMT date.
	 *
	 * @param object $row Row with oldest_gmt and oldest_local.
	 * @return int Unix timestamp, or 0 when neither column is usable.
	 */
	private static function usable_stamp( $row ) {
		$stamps = array();

		foreach ( array( 'oldest_gmt', 'oldest_local' ) as $field ) {
			$value = isset( $row->$field ) ? (string) $row->$field : '';

			if ( '' === $value || 0 === strpos( $value, '0000-00-00' ) ) {
				continue;
			}

			$stamp = strtotime( $value . ' UTC' );

			if ( $stamp ) {
				$stamps[] = $stamp;
			}
		}

		return empty( $stamps ) ? 0 : min( $stamps );
	}

	/**
	 * Findings for accounts whose content predates them.
	 *
	 * @param array $accounts Result of WPAQS_Accounts::all().
	 * @param array $earliest User id => earliest post timestamp.
	 * @return array
	 */
	public static function findings( array $accounts, array $earliest ) {
		$findings = array();

		foreach ( $accounts['rows'] as $row ) {
			if ( ! isset( $earliest[ $row['id'] ] ) ) {
				continue;
			}

			$registered = strtotime( $row['registered'] . ' UTC' );

			if ( ! $registered ) {
				continue;
			}

			$oldest = (int) $earliest[ $row['id'] ];

			if ( $oldest >= ( $registered - self::TOLERANCE ) ) {
				continue;
			}

			$findings[] = WPAQS_Findings::make(
				'registered_after_first_post',
				'user:' . $row['id'],
				sprintf(
					'login=%1$s registered=%2$s oldest_post=%3$s gap=%4$s',
					$row['login'],
					gmdate( 'Y-m-d H:i', $registered ) . ' UTC',
					gmdate( 'Y-m-d H:i', $oldest ) . ' UTC',
					self::readable_gap( $registered - $oldest )
				)
			);
		}

		return $findings;
	}

	/**
	 * A duration in whole days or hours, for a sentence rather than a log line.
	 *
	 * @param int $seconds Seconds.
	 * @return string
	 */
	public static function readable_gap( $seconds ) {
		$seconds = max( 0, (int) $seconds );

		if ( $seconds >= DAY_IN_SECONDS ) {
			$days = (int) floor( $seconds / DAY_IN_SECONDS );

			return sprintf(
				/* translators: %d: number of days. */
				_n( '%d day', '%d days', $days, 'wpaqs' ),
				$days
			);
		}

		$hours = max( 1, (int) floor( $seconds / HOUR_IN_SECONDS ) );

		return sprintf(
			/* translators: %d: number of hours. */
			_n( '%d hour', '%d hours', $hours, 'wpaqs' ),
			$hours
		);
	}
}
