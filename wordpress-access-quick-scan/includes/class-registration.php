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
	 * @return array array( open, role, caps )
	 */
	public static function state() {
		$role = (string) get_option( 'default_role', 'subscriber' );

		return array(
			'open' => (bool) get_option( 'users_can_register', false ),
			'role' => $role,
			'caps' => self::privileged_caps( $role ),
		);
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
					'users_can_register=1 default_role=%1$s grants=%2$s',
					$state['role'],
					implode( ',', $state['caps'] )
				)
			),
		);
	}
}
