<?php
/**
 * Registration posture: who can create an account, and what they get.
 *
 * Two options, read together on purpose. `users_can_register` on its own is how every
 * membership site works. A `default_role` other than subscriber on its own is a normal
 * choice. The pair is the finding: with both, a stranger holds that role by filling in a
 * form.
 *
 * This is the plugin's one deterministic rule, and it is deterministic because it asks
 * about configuration rather than about behaviour.
 *
 * Read-only.
 *
 * @package WPAQS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads the registration settings.
 */
class WPAQS_Registration {

	/**
	 * A role holding any of these can do more than read.
	 *
	 * `edit_posts` is the floor deliberately: a Contributor can put content on the site,
	 * which is what the campaigns behind the sibling plugin were there to do.
	 */
	const PRIVILEGED_CAPS = array(
		'edit_posts',
		'upload_files',
		'publish_posts',
		'edit_pages',
		'manage_options',
		'install_plugins',
		'edit_users',
	);

	/**
	 * Current state.
	 *
	 * On multisite `users_can_register` is not consulted by WordPress at all — the network
	 * option `registration` decides, and it carries four values rather than a flag. Reading
	 * the single-site option there would report a closed site as open, or the reverse,
	 * depending on a setting nothing honours.
	 *
	 * @return array array( open, role, caps, network )
	 */
	public static function state() {
		$role    = (string) get_option( 'default_role', 'subscriber' );
		$network = is_multisite();

		if ( $network ) {
			$setting = (string) get_site_option( 'registration', 'none' );
			$open    = in_array( $setting, array( 'user', 'all' ), true );
		} else {
			$open = (bool) get_option( 'users_can_register', false );
		}

		return array(
			'open'    => $open,
			'role'    => $role,
			'caps'    => self::privileged_caps( $role ),
			'network' => $network,
		);
	}

	/**
	 * Set the role a new account receives to the one that can only read.
	 *
	 * A setting, not a deletion: Settings then General puts it back, and no account already
	 * created is touched. What it closes is the path where the next stranger to fill in the
	 * form holds a role that can publish.
	 *
	 * @return array array( error )
	 */
	public static function park_default_role() {
		$state = self::state();

		if ( ! $state['open'] || empty( $state['caps'] ) ) {
			return array( 'error' => __( 'Registration on this site no longer hands out a privileged role, so there is nothing to change.', 'wpaqs' ) );
		}

		update_option( 'default_role', 'subscriber' );

		return array( 'error' => '' );
	}

	/**
	 * Close public registration.
	 *
	 * @return array array( error )
	 */
	public static function close() {
		$state = self::state();

		if ( $state['network'] ) {
			// The network option governs every site, so a per-site screen is the wrong place
			// to change it. Naming where it lives is more use than a button that lies.
			return array( 'error' => __( 'On a network, registration is a network setting rather than a per-site one. Change it under Network Admin then Settings, where it applies to every site at once.', 'wpaqs' ) );
		}

		if ( ! $state['open'] ) {
			return array( 'error' => __( 'Registration is already closed on this site.', 'wpaqs' ) );
		}

		update_option( 'users_can_register', 0 );

		return array( 'error' => '' );
	}

	/**
	 * The notable capabilities a role holds.
	 *
	 * @param string $role Role name.
	 * @return array
	 */
	public static function privileged_caps( $role ) {
		if ( ! function_exists( 'get_role' ) ) {
			return array();
		}

		$object = get_role( (string) $role );

		if ( ! $object || ! isset( $object->capabilities ) ) {
			// A default_role naming a role that is not registered is its own oddity, but it
			// grants nothing, so it is not this rule's business.
			return array();
		}

		$held = array();

		foreach ( self::PRIVILEGED_CAPS as $cap ) {
			if ( ! empty( $object->capabilities[ $cap ] ) ) {
				$held[] = $cap;
			}
		}

		return $held;
	}

	/**
	 * The finding, when both halves are true.
	 *
	 * @return array Zero or one finding.
	 */
	public static function findings() {
		$state = self::state();

		if ( ! $state['open'] || empty( $state['caps'] ) ) {
			return array();
		}

		return array(
			WPAQS_Findings::make(
				'open_registration_privileged_role',
				'option:users_can_register',
				sprintf(
					'%1$s default_role=%2$s grants=%3$s',
					$state['network'] ? 'network_registration=open' : 'users_can_register=1',
					$state['role'],
					implode( ',', $state['caps'] )
				)
			),
		);
	}
}
