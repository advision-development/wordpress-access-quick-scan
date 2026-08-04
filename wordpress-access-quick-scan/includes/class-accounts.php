<?php
/**
 * Accounts, with the capabilities they actually hold.
 *
 * The Users screen shows a role column, and a role column is not the same thing as what
 * an account can do. `$user->add_cap()` writes capabilities straight into the
 * `wp_capabilities` meta alongside the role names, so a Subscriber granted `edit_users`
 * still reads as Subscriber there. This reads the meta instead and reports whatever is in
 * it that no registered role accounts for.
 *
 * Read-only.
 *
 * @package WPAQS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads the account list.
 */
class WPAQS_Accounts {

	/**
	 * Capabilities worth naming in a finding, because they change the site.
	 *
	 * A direct grant of `read` is noise. A direct grant of `install_plugins` is somebody
	 * handing out the ability to run code.
	 */
	const NOTABLE_CAPS = array(
		'install_plugins',
		'install_themes',
		'edit_plugins',
		'edit_themes',
		'edit_files',
		'update_plugins',
		'update_themes',
		'update_core',
		'edit_users',
		'create_users',
		'delete_users',
		'promote_users',
		'manage_options',
		'unfiltered_html',
		'edit_others_posts',
		'publish_posts',
		'edit_published_posts',
	);

	/**
	 * Every account, capped.
	 *
	 * @return array array( rows, total, capped )
	 */
	public static function all() {
		$total = self::count_users();
		$users = get_users(
			array(
				'number'  => WPAQS_MAX_USERS,
				'orderby' => 'registered',
				'order'   => 'DESC',
			)
		);

		$roles = self::registered_roles();
		$rows  = array();

		foreach ( (array) $users as $user ) {
			$rows[] = array(
				'id'         => (int) $user->ID,
				'login'      => (string) $user->user_login,
				'email'      => (string) $user->user_email,
				'registered' => (string) $user->user_registered,
				'roles'      => array_values( (array) $user->roles ),
				'is_admin'   => in_array( 'administrator', (array) $user->roles, true ),
				'direct'     => self::direct_capabilities( $user, $roles ),
			);
		}

		return array(
			'rows'   => $rows,
			'total'  => $total,
			'capped' => $total > count( $rows ),
		);
	}

	/**
	 * Capabilities granted to the account itself rather than through a role.
	 *
	 * Computed, not guessed: the keys of `wp_capabilities` are role names plus any
	 * capability added directly. Drop every key that names a registered role and what is
	 * left was written against the account.
	 *
	 * @param object $user  User object with a `caps` property.
	 * @param array  $roles Registered role names.
	 * @return array Capability names, granted ones only.
	 */
	public static function direct_capabilities( $user, array $roles ) {
		$caps   = isset( $user->caps ) ? (array) $user->caps : array();
		$direct = array();

		foreach ( $caps as $name => $granted ) {
			if ( ! $granted ) {
				// A capability explicitly set to false is a denial, not a grant.
				continue;
			}

			if ( in_array( (string) $name, $roles, true ) ) {
				continue;
			}

			$direct[] = (string) $name;
		}

		sort( $direct );

		return $direct;
	}

	/**
	 * Which of a capability list changes the site.
	 *
	 * @param array $caps Capability names.
	 * @return array
	 */
	public static function notable( array $caps ) {
		return array_values( array_intersect( $caps, self::NOTABLE_CAPS ) );
	}

	/**
	 * Findings for the account list.
	 *
	 * @param array $accounts Result of all().
	 * @return array
	 */
	public static function findings( array $accounts ) {
		$findings  = array();
		$threshold = time() - ( WPAQS_RECENT_DAYS * DAY_IN_SECONDS );

		foreach ( $accounts['rows'] as $row ) {
			$notable = self::notable( $row['direct'] );

			if ( ! empty( $notable ) ) {
				$findings[] = WPAQS_Findings::make(
					'capability_outside_role',
					'user:' . $row['id'],
					sprintf( 'login=%1$s roles=%2$s direct=%3$s', $row['login'], implode( ',', $row['roles'] ), implode( ',', $notable ) ),
					sprintf(
						/* translators: %s: comma separated capability names. */
						__( 'Granted directly: %s.', 'wpaqs' ),
						implode( ', ', $notable )
					)
				);
			}

			if ( ! $row['is_admin'] ) {
				continue;
			}

			$registered = strtotime( $row['registered'] . ' UTC' );

			if ( $registered && $registered >= $threshold ) {
				$findings[] = WPAQS_Findings::make(
					'recent_administrator',
					'user:' . $row['id'],
					sprintf( 'login=%1$s registered=%2$s', $row['login'], $row['registered'] )
				);
			}
		}

		return $findings;
	}

	/**
	 * How many accounts the site has.
	 *
	 * @return int
	 */
	private static function count_users() {
		if ( ! function_exists( 'count_users' ) ) {
			return 0;
		}

		$counts = count_users();

		return isset( $counts['total_users'] ) ? (int) $counts['total_users'] : 0;
	}

	/**
	 * Role names registered on this site.
	 *
	 * @return array
	 */
	private static function registered_roles() {
		if ( ! function_exists( 'wp_roles' ) ) {
			return array();
		}

		$roles = wp_roles();

		return ( $roles && isset( $roles->roles ) ) ? array_keys( (array) $roles->roles ) : array();
	}
}
