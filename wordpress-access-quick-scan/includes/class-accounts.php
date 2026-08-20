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
	 * Capabilities that let an account put code on the site.
	 *
	 * Kept apart from NOTABLE_CAPS because the question is different. Notable is "worth
	 * mentioning"; this is "can run code", which is the blast radius on a compromised site
	 * and the list an operator wants first.
	 */
	const CODE_CAPS = array(
		'install_plugins',
		'install_themes',
		'edit_plugins',
		'edit_themes',
		'edit_files',
		'update_plugins',
		'update_themes',
		'update_core',
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
				// A pending reset comes off the user row rather than a query: WP_User carries
				// every wp_users column, and user_activation_key is one of them.
				'reset_requested' => self::reset_requested_at( isset( $user->user_activation_key ) ? $user->user_activation_key : '' ),
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

		foreach ( self::duplicate_emails( $accounts ) as $email => $logins ) {
			$findings[] = WPAQS_Findings::make(
				'duplicate_account_email',
				'email:' . $email,
				sprintf( 'email=%1$s logins=%2$s', $email, implode( ',', $logins ) )
			);
		}

		foreach ( self::lookalike_logins( $accounts ) as $pair ) {
			$findings[] = WPAQS_Findings::make(
				'lookalike_login',
				'user:' . $pair['row']['id'],
				sprintf( 'login=%1$s resembles=%2$s', $pair['row']['login'], $pair['privileged'] )
			);
		}

		foreach ( $accounts['rows'] as $row ) {
			if ( empty( $row['reset_requested'] ) ) {
				continue;
			}

			$findings[] = WPAQS_Findings::make(
				'pending_password_reset',
				'user:' . $row['id'] . ':reset',
				sprintf(
					'login=%1$s roles=%2$s requested=%3$s',
					$row['login'],
					implode( ',', $row['roles'] ),
					$row['reset_requested'] > 0 ? gmdate( 'Y-m-d H:i', (int) $row['reset_requested'] ) . ' UTC' : 'unknown'
				)
			);
		}

		if ( self::file_editing_allowed() ) {
			$holders = self::code_holders( $accounts );

			$findings[] = WPAQS_Findings::make(
				'file_editing_enabled',
				'option:file_edit',
				sprintf(
					/* translators: %d: how many accounts can reach the editors. */
					'accounts_that_can_run_code=%d',
					count( $holders )
				)
			);
		}

		return $findings;
	}

	/**
	 * Accounts that can put code on this site, and how.
	 *
	 * Effective capabilities, not roles: a capability granted straight to the account counts
	 * exactly as much as one that came with Administrator, and the Users screen shows
	 * neither.
	 *
	 * @param array $accounts Result of all().
	 * @return array Rows of array( account, caps, via_role ).
	 */
	public static function code_holders( array $accounts ) {
		$holders = array();

		foreach ( $accounts['rows'] as $row ) {
			$from_roles = array();

			foreach ( $row['roles'] as $role ) {
				$from_roles = array_merge( $from_roles, self::role_code_caps( $role ) );
			}

			$direct = array_values( array_intersect( $row['direct'], self::CODE_CAPS ) );
			$all    = array_values( array_unique( array_merge( $from_roles, $direct ) ) );

			if ( empty( $all ) ) {
				continue;
			}

			sort( $all );

			$holders[] = array(
				'account'  => $row,
				'caps'     => $all,
				'direct'   => $direct,
				'via_role' => ! empty( $from_roles ),
			);
		}

		return $holders;
	}

	/**
	 * Which code capabilities a role carries.
	 *
	 * @param string $role Role name.
	 * @return array
	 */
	private static function role_code_caps( $role ) {
		if ( ! function_exists( 'get_role' ) ) {
			return array();
		}

		$object = get_role( (string) $role );

		if ( ! $object || ! isset( $object->capabilities ) ) {
			return array();
		}

		$held = array();

		foreach ( self::CODE_CAPS as $cap ) {
			if ( ! empty( $object->capabilities[ $cap ] ) ) {
				$held[] = $cap;
			}
		}

		return $held;
	}

	/**
	 * Whether the built-in file editors are reachable.
	 *
	 * @return bool
	 */
	public static function file_editing_allowed() {
		if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
			return false;
		}

		if ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) {
			return false;
		}

		return true;
	}

	/**
	 * Accounts sharing one email address.
	 *
	 * WordPress refuses to create a second account on an address already in use, so a
	 * duplicate did not arrive through WordPress. Deterministic, like the authorship check.
	 *
	 * @param array $accounts Result of all().
	 * @return array Email => list of logins, only where there is more than one.
	 */
	public static function duplicate_emails( array $accounts ) {
		$seen = array();

		foreach ( $accounts['rows'] as $row ) {
			$email = strtolower( trim( $row['email'] ) );

			if ( '' === $email ) {
				continue;
			}

			$seen[ $email ][] = $row['login'];
		}

		$duplicates = array();

		foreach ( $seen as $email => $logins ) {
			if ( count( $logins ) > 1 ) {
				$duplicates[ $email ] = $logins;
			}
		}

		return $duplicates;
	}

	/**
	 * A login reduced to one representative per set of characters that look alike.
	 *
	 * Substituting digits for letters is not enough, because a digit usually imitates more
	 * than one letter: `1` stands in for both `i` and `l`, so mapping it to either leaves
	 * `adm1n` and `admin` apart. Every member of a confusable set therefore folds to the same
	 * character — letters included — which is what makes the comparison symmetric.
	 *
	 * Over-collapsing is the accepted cost. It is why the rule only fires when one side of
	 * the pair can change the site: on a site with several brands, near-collisions between
	 * ordinary logins are normal and silent.
	 *
	 * @param string $login Login.
	 * @return string
	 */
	public static function fold_login( $login ) {
		$folded = strtolower( trim( (string) $login ) );

		return strtr(
			$folded,
			array(
				'0' => 'o',
				'1' => 'i',
				'l' => 'i',
				'3' => 'e',
				'4' => 'a',
				'@' => 'a',
				'5' => 's',
				'$' => 's',
				'7' => 't',
			)
		);
	}

	/**
	 * Pairs of logins that read the same, where at least one can change the site.
	 *
	 * The privilege condition is what keeps this quiet. A site with several brands has
	 * near-identical logins for honest reasons; a near-identical login next to an
	 * administrator is the case worth a look.
	 *
	 * @param array $accounts Result of all().
	 * @return array Rows of array( login, twin, privileged_login ).
	 */
	public static function lookalike_logins( array $accounts ) {
		$folded = array();

		foreach ( $accounts['rows'] as $row ) {
			$key = self::fold_login( $row['login'] );

			// WordPress will not create two accounts with one login, so any collision here is
			// between different spellings of the same word rather than a duplicate.
			$folded[ $key ][] = $row;
		}

		$pairs = array();

		foreach ( $folded as $rows ) {
			if ( count( $rows ) < 2 ) {
				continue;
			}

			$privileged = array();

			foreach ( $rows as $row ) {
				if ( $row['is_admin'] || ! empty( self::notable( $row['direct'] ) ) ) {
					$privileged[] = $row['login'];
				}
			}

			if ( empty( $privileged ) ) {
				continue;
			}

			foreach ( $rows as $row ) {
				if ( in_array( $row['login'], $privileged, true ) ) {
					continue;
				}

				$pairs[] = array(
					'row'        => $row,
					'privileged' => $privileged[0],
				);
			}
		}

		return $pairs;
	}

	/**
	 * Take a directly-granted capability off an account.
	 *
	 * Only a capability the account holds **directly**, and the role is not touched: removing
	 * something a role grants would be undone the moment WordPress read the role again, so
	 * this would be a button that appears to work.
	 *
	 * Reversible by granting it again, which is the whole reason it is offered — the finding
	 * it clears asks the operator to confirm a grant, and confirming sometimes ends in
	 * removing it.
	 *
	 * @param int    $user_id User id.
	 * @param string $cap     Capability name.
	 * @return array array( error )
	 */
	public static function remove_direct_capability( $user_id, $cap, $actor ) {
		$user_id = (int) $user_id;
		$cap     = (string) $cap;
		$actor   = (int) $actor;

		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return array( 'error' => __( 'That account no longer exists.', 'wpaqs' ) );
		}

		// No actor means no screen to lose access to, so the guard has nothing to protect.
		if ( 0 !== $actor && $actor === $user_id ) {
			return array( 'error' => __( 'That is the account you are signed in with. Taking a capability off it could remove your own access to this screen — use another administrator account.', 'wpaqs' ) );
		}

		// Asked of the actor. current_user_can() is false for everything under cron, which
		// would refuse every command rather than the ones that should be refused.
		if ( 0 !== $actor && ! user_can( $actor, 'edit_user', $user_id ) ) {
			return array( 'error' => __( 'This site does not let you edit that account.', 'wpaqs' ) );
		}

		// Live, not from a report. A capability removed by somebody else since this screen was
		// drawn is not something to act on, and one that only ever came from a role was never
		// this button's to take away.
		$direct = self::direct_capabilities( $user, self::registered_roles() );

		if ( ! in_array( $cap, $direct, true ) ) {
			return array( 'error' => __( 'That capability is not granted directly to that account any more. If the account still holds it, the grant comes from its role — change the role, or the role itself, rather than the account.', 'wpaqs' ) );
		}

		if ( ! in_array( $cap, self::NOTABLE_CAPS, true ) ) {
			// The screen only reports notable capabilities, so a request for anything else did
			// not come from a button on it.
			return array( 'error' => __( 'That capability is not one this screen offers to change.', 'wpaqs' ) );
		}

		$user->remove_cap( $cap );

		return array( 'error' => '' );
	}

	/**
	 * When a pending reset was requested, from the stored key.
	 *
	 * `retrieve_password()` writes `time():hash` into `user_activation_key` and
	 * `reset_password()` clears it, so a key that is still there means a reset was asked for
	 * and never completed — with the hour attached. Older WordPress stored the hash alone, so
	 * a key with no numeric prefix still says a reset is pending and says nothing about when.
	 *
	 * @param string $key Stored activation key.
	 * @return int Timestamp, or 0 when there is no key. -1 when a reset is pending undated.
	 */
	public static function reset_requested_at( $key ) {
		$key = trim( (string) $key );

		if ( '' === $key ) {
			return 0;
		}

		$parts = explode( ':', $key, 2 );

		if ( 2 === count( $parts ) && ctype_digit( $parts[0] ) ) {
			return (int) $parts[0];
		}

		return -1;
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
	public static function registered_roles() {
		if ( ! function_exists( 'wp_roles' ) ) {
			return array();
		}

		$roles = wp_roles();

		return ( $roles && isset( $roles->roles ) ) ? array_keys( (array) $roles->roles ) : array();
	}
}
