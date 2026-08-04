<?php
/**
 * Accounts holding content older than themselves.
 *
 * `wp_insert_post()` requires an author that already exists, so nothing can *write* a post
 * older than its author. Reported as arithmetic in 0.2.0 on that basis, which was wrong:
 * `wp_delete_user( $id, $reassign )` moves a deleted account's posts to another account and
 * the posts keep their original dates. Deleting a colleague and reassigning their work is an
 * ordinary thing to do, and it produces this exactly.
 *
 * WordPress records nothing about a reassignment — no marker, no meta, no log — so this
 * cannot tell one from a row planted directly in the database. It reports the span of the
 * content instead and leaves the judgement where the knowledge is: a body of posts covering
 * years before the account existed is somebody else's work inherited, and a single post a
 * fortnight before it is not.
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
				"SELECT post_author,
				        MIN( post_date_gmt ) AS oldest_gmt, MIN( post_date ) AS oldest_local,
				        MAX( post_date_gmt ) AS newest_gmt, MAX( post_date ) AS newest_local,
				        COUNT( * ) AS posts
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
			$stamp = self::usable_stamp( $row, 'oldest_gmt', 'oldest_local', 'min' );

			if ( $stamp > 0 ) {
				$earliest[ (int) $row->post_author ] = array(
					'oldest' => $stamp,
					'newest' => self::usable_stamp( $row, 'newest_gmt', 'newest_local', 'max' ),
					'posts'  => isset( $row->posts ) ? (int) $row->posts : 0,
				);
			}
		}

		return $earliest;
	}

	/**
	 * One of the two date columns, ignoring a zero GMT date.
	 *
	 * Both are read because a row written straight into the database frequently carries a
	 * local date and leaves the GMT column at zero — reading only the GMT column would hide
	 * the rows most worth seeing.
	 *
	 * @param object $row   Row from the grouped query.
	 * @param string $gmt   GMT column name.
	 * @param string $local Local column name.
	 * @param string $pick  'min' for the earliest, 'max' for the latest.
	 * @return int Unix timestamp, or 0 when neither column is usable.
	 */
	private static function usable_stamp( $row, $gmt, $local, $pick ) {
		$stamps = array();

		foreach ( array( $gmt, $local ) as $field ) {
			$value = isset( $row->$field ) ? (string) $row->$field : '';

			if ( '' === $value || 0 === strpos( $value, '0000-00-00' ) ) {
				continue;
			}

			$stamp = strtotime( $value . ' UTC' );

			if ( $stamp ) {
				$stamps[] = $stamp;
			}
		}

		if ( empty( $stamps ) ) {
			return 0;
		}

		return 'max' === $pick ? max( $stamps ) : min( $stamps );
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

			$content = $earliest[ $row['id'] ];
			$oldest  = (int) $content['oldest'];

			if ( $oldest >= ( $registered - self::TOLERANCE ) ) {
				continue;
			}

			// The span is the discriminator this rule cannot apply itself. Content covering a
			// stretch of time before the account existed is somebody else's work inherited
			// through a reassignment; a single post shortly before it is not.
			$findings[] = WPAQS_Findings::make(
				'content_predates_account',
				'user:' . $row['id'],
				sprintf(
					'login=%1$s registered=%2$s oldest_post=%3$s newest_post=%4$s posts=%5$d gap=%6$s',
					$row['login'],
					gmdate( 'Y-m-d H:i', $registered ) . ' UTC',
					gmdate( 'Y-m-d H:i', $oldest ) . ' UTC',
					$content['newest'] > 0 ? gmdate( 'Y-m-d H:i', (int) $content['newest'] ) . ' UTC' : 'unknown',
					(int) $content['posts'],
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
