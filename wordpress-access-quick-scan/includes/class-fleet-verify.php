<?php
/**
 * The route the console calls to prove this site is this site.
 *
 * Copied verbatim into the sibling plugin, so it must stay byte-identical except for
 * the class prefix: `sed 's/WPAQS_/WPAQS_/g'` over the copy has to diff clean against
 * this. The namespace is derived from the class name for that reason — sed does not
 * touch lower case, so a literal would break the copy.
 *
 * Public, unauthenticated, and answers exactly two things: the value it was given, and
 * a hash of this install's own nonce.
 *
 * **The nonce itself is never returned.** Anyone on the internet can call this, and the
 * install nonce is what collects the key from the console — handing it out here would
 * mean anyone who could reach the site could enrol as it and take its key. The console
 * stores the hash and compares hashes, so the useful half never leaves.
 *
 * @package WPAQS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One REST route, answering one question.
 */
class WPAQS_Fleet_Verify {

	/**
	 * Register the route.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * The verification route.
	 *
	 * @return void
	 */
	public static function routes() {
		$class = __CLASS__;
		$scope = strtolower( substr( $class, 0, strpos( $class, '_' ) ) );

		register_rest_route(
			$scope . '/v1',
			'/verify',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'verify' ),
				// Public on purpose. It proves the plugin is here, which is only useful
				// to somebody who already knows the domain, and it discloses nothing
				// that could be replayed.
				'permission_callback' => '__return_true',
				'args'                => array(
					'nonce' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Echo the value, and say which install is answering.
	 *
	 * The echo proves the plugin is reachable at this domain. The hash proves it is the
	 * install that asked to enrol rather than a second copy on the same host — and being
	 * a hash, it proves that to the console without telling anyone else anything they
	 * could use.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return array
	 */
	public static function verify( $request ) {
		$fleet = strtoupper( substr( __CLASS__, 0, strpos( __CLASS__, '_' ) ) ) . '_Fleet';

		return array(
			'nonce'   => (string) $request->get_param( 'nonce' ),
			'install' => hash( 'sha256', call_user_func( array( $fleet, 'install_nonce' ) ) ),
		);
	}
}
