<?php
/**
 * Finding catalog.
 *
 * Every rule this plugin can emit is declared once, here, with its severity and its
 * wording. The readers only supply a target and an evidence string, so a severity cannot
 * drift from the sentence that explains it.
 *
 * @package WPAQS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds normalized finding arrays.
 */
class WPAQS_Findings {

	const SEVERITIES = array( 'critical', 'high', 'medium', 'info' );

	/**
	 * Rule slug => severity, title, detail, recommendation.
	 *
	 * @return array
	 */
	public static function catalog() {
		return array(
			'capability_outside_role'           => array(
				'severity'       => 'high',
				'title'          => __( 'Account holds capabilities that come from no role', 'wpaqs' ),
				'detail'         => __( 'This account has capabilities written directly against it rather than inherited from any role registered on this site. The Users screen shows only roles, so an account like this reads as ordinary there while holding more than its role allows.', 'wpaqs' ),
				'recommendation' => __( 'This is not proof of anything on its own: add_cap() writes to the same place and legitimate plugins use it for their own permissions. Confirm each capability is one you or a plugin you installed granted deliberately. What matters is whether the list contains anything that lets the account publish, install code, or edit other users.', 'wpaqs' ),
			),
			'open_registration_privileged_role' => array(
				'severity'       => 'critical',
				'title'          => __( 'Anyone can register, and new accounts arrive privileged', 'wpaqs' ),
				'detail'         => __( 'Registration is open to the public and the role every new account receives can do more than read. Neither setting is unusual by itself — open registration is how membership sites work, and a custom default role is a normal choice — but together they mean a stranger can hold that role by filling in a form.', 'wpaqs' ),
				'recommendation' => __( 'Set the default role to Subscriber under Settings then General, or close registration. Then look at the accounts that already arrived this way: check the registration dates against when the default role was changed.', 'wpaqs' ),
			),
			'non_browser_session'               => array(
				'severity'       => 'high',
				'title'          => __( 'Live session was not opened by a browser', 'wpaqs' ),
				'detail'         => __( 'WordPress records the user agent of each login. This session carries one that no browser sends — a command-line HTTP client, a scripting library, or nothing at all. A person signing in through a login form does not produce this.', 'wpaqs' ),
				'recommendation' => __( 'End the account\'s sessions from this screen, then change its password. A session opened by a script means either an integration nobody documented, or credentials being used by something that is not the account holder.', 'wpaqs' ),
			),
			'app_password_unused'               => array(
				'severity'       => 'medium',
				'title'          => __( 'Application password has never been used', 'wpaqs' ),
				'detail'         => __( 'This password was issued and no request has ever authenticated with it. A key nobody uses is a key nobody notices, and it grants the same access as the account it belongs to.', 'wpaqs' ),
				'recommendation' => __( 'If you cannot name the integration it was created for, revoke it. Nothing stops working when an unused credential is removed.', 'wpaqs' ),
			),
			'app_password_foreign_ip'           => array(
				'severity'       => 'high',
				'title'          => __( 'Application password was last used from an unfamiliar address', 'wpaqs' ),
				'detail'         => __( 'The address that last authenticated with this password matches none of the addresses the account has an open session from. That is expected for a server-to-server integration, and it is also what a stolen credential looks like.', 'wpaqs' ),
				'recommendation' => __( 'Decide which it is by naming the integration and the host it runs on. If you cannot, revoke the password: an integration that breaks tells you what it was, and a thief does not.', 'wpaqs' ),
			),
			'recent_administrator'              => array(
				'severity'       => 'info',
				'title'          => __( 'Administrator account registered recently', 'wpaqs' ),
				'detail'         => __( 'This administrator was created inside the last month. The account may be entirely expected — this is context for reading the list above, not an accusation.', 'wpaqs' ),
				'recommendation' => __( 'Confirm with whoever runs this site that the account was expected. WordPress Malware Quick Scan reports the same account alongside file and database evidence, which is the better place to judge it if this site is already under suspicion.', 'wpaqs' ),
			),
		);
	}

	/**
	 * Build one finding.
	 *
	 * @param string $rule     Rule slug.
	 * @param string $target   What the finding is about.
	 * @param string $evidence Short, untrusted-ish detail. Escaped at render.
	 * @param string $extra    Optional sentence appended to the catalog detail.
	 * @return array
	 */
	public static function make( $rule, $target, $evidence = '', $extra = '' ) {
		$catalog = self::catalog();

		if ( ! isset( $catalog[ $rule ] ) ) {
			// A rule with no catalog entry would render as a card with no severity and no
			// wording. Better to fail loudly here than to ship a blank finding.
			$catalog[ $rule ] = array(
				'severity'       => 'info',
				'title'          => $rule,
				'detail'         => '',
				'recommendation' => '',
			);
		}

		$entry  = $catalog[ $rule ];
		$detail = $entry['detail'];

		if ( '' !== $extra ) {
			$detail = '' === $detail ? $extra : $detail . ' ' . $extra;
		}

		return array(
			'rule'           => $rule,
			'severity'       => $entry['severity'],
			'title'          => $entry['title'],
			'detail'         => $detail,
			'recommendation' => $entry['recommendation'],
			'target'         => $target,
			'evidence'       => $evidence,
		);
	}

	/**
	 * Sort order weight for a severity.
	 *
	 * @param string $severity Severity slug.
	 * @return int
	 */
	public static function weight( $severity ) {
		$order = array_flip( self::SEVERITIES );

		return isset( $order[ $severity ] ) ? $order[ $severity ] : count( $order );
	}

	/**
	 * Findings ordered worst first, then by target so the list is stable.
	 *
	 * @param array $findings Findings.
	 * @return array
	 */
	public static function sorted( array $findings ) {
		usort(
			$findings,
			function ( $a, $b ) {
				$weight = self::weight( $a['severity'] ) - self::weight( $b['severity'] );

				return 0 !== $weight ? $weight : strcmp( (string) $a['target'], (string) $b['target'] );
			}
		);

		return $findings;
	}
}
