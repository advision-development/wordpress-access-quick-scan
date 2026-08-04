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
			'duplicate_account_email'          => array(
				'severity'       => 'high',
				'title'          => __( 'Two accounts share one email address', 'wpaqs' ),
				'detail'         => __( 'WordPress refuses to create a second account on an address already in use, so two accounts holding one address did not both arrive through WordPress. One of them was written directly into the database.', 'wpaqs' ),
				'recommendation' => __( 'Work out which account is the newer one and what it can do. An address that already receives password resets for a legitimate account is also the address that receives them for this one.', 'wpaqs' ),
			),
			'lookalike_login'                  => array(
				'severity'       => 'medium',
				'title'          => __( 'Account login imitates a privileged one', 'wpaqs' ),
				'detail'         => __( 'Two logins on this site are the same word once digits standing in for letters are read as those letters — admin and adm1n, for instance — and one of the two can change the site. A name chosen to be misread is a name chosen to survive somebody glancing at the user list.', 'wpaqs' ),
				'recommendation' => __( 'Compare the two accounts: registration dates, roles, and what each has written. A brand or a staging account can produce a near-collision honestly, so confirm rather than assume.', 'wpaqs' ),
			),
			'file_editing_enabled'             => array(
				'severity'       => 'medium',
				'title'          => __( 'Code can be edited from inside the admin', 'wpaqs' ),
				'detail'         => __( 'Neither DISALLOW_FILE_EDIT nor DISALLOW_FILE_MODS is set, so the theme and plugin file editors are available. Every account that can reach them can run code on this site without uploading anything, which makes each one a way in rather than just an account.', 'wpaqs' ),
				'recommendation' => __( 'Add define( \'DISALLOW_FILE_EDIT\', true ); to wp-config.php. Nothing legitimate needs the built-in editors — deployments and updates do not use them — and it removes the shortest path from a stolen administrator password to code running on the site.', 'wpaqs' ),
			),
			'sessions_many_networks'           => array(
				'severity'       => 'high',
				'title'          => __( 'One account is signed in from several networks at once', 'wpaqs' ),
				'detail'         => __( 'This account holds live sessions from addresses on three or more separate networks. A laptop and a phone are normally two, and a person travelling is two over time rather than three at once.', 'wpaqs' ),
				'recommendation' => __( 'Ask the account holder how many devices they are signed in on. If the number does not match, end the sessions from this screen and change the password: the sessions are the evidence, so read the addresses before ending them.', 'wpaqs' ),
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

	/** A group needs this many entries before its wording is folded into the header. */
	const GROUP_MIN = 2;

	/**
	 * At this many entries, the card itself opens collapsed.
	 *
	 * The threshold sits on the card rather than on the entry list inside it. There is one
	 * fold per card, because you open a card precisely to see its entries — a second toggle
	 * around them adds a click to the same intent. A card holding this many or more starts
	 * closed so a long screen reads as an index of what was found instead of a wall.
	 */
	const GROUP_COLLAPSE = 6;

	/**
	 * Fold repeated findings into one group per rule and severity.
	 *
	 * Five application passwords used from unfamiliar addresses produced five cards with
	 * the same title, the same paragraph and the same next step, differing only in the
	 * evidence line. Reading that means scrolling past four copies of a paragraph to learn
	 * whether anything else was found — which is how the fifth finding gets missed.
	 *
	 * Keyed on rule **and** severity, never rule alone. Severity is fixed per rule in the
	 * catalog today, so the pair is redundant today; it is the key anyway because the
	 * moment a rule emits two severities, a card holding both would have to lie about one
	 * of them in its badge.
	 *
	 * @param array $findings Findings, already sorted.
	 * @return array Groups, in the order their first member appeared.
	 */
	public static function group( array $findings ) {
		$groups = array();

		foreach ( $findings as $finding ) {
			$rule     = isset( $finding['rule'] ) ? (string) $finding['rule'] : '';
			$severity = isset( $finding['severity'] ) ? (string) $finding['severity'] : 'info';
			$key      = $rule . '|' . $severity;

			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'severity' => $severity,
					'rule'     => $rule,
					'title'    => isset( $finding['title'] ) ? $finding['title'] : '',
					'entries'  => array(),
				);
			}

			$groups[ $key ]['entries'][] = $finding;
		}

		$list = array();

		foreach ( $groups as $group ) {
			// Shared only when every member agrees. Today every entry of a rule carries the
			// same recommendation, and checking rather than assuming is what keeps that true
			// when one of them starts carrying its own.
			$group['recommendation'] = self::shared_field( $group['entries'], 'recommendation' );
			$group['detail']         = '';

			// A group of one renders as a single card, which needs its whole detail:
			// stripping it there would lose the sentence the card is built on.
			if ( count( $group['entries'] ) >= self::GROUP_MIN ) {
				$group['detail'] = self::shared_detail( $group['entries'] );

				if ( '' !== $group['detail'] ) {
					$shared = strlen( $group['detail'] );

					foreach ( $group['entries'] as $index => $entry ) {
						$detail = isset( $entry['detail'] ) ? (string) $entry['detail'] : '';

						$group['entries'][ $index ]['detail'] = trim( substr( $detail, $shared ) );
					}
				}
			}

			$list[] = $group;
		}

		return $list;
	}

	/**
	 * The leading sentences every entry in a group states identically.
	 *
	 * Grouping the cards is not the same as removing the repetition. `make()` builds a
	 * detail as the catalog sentence plus an optional extra one, so entries of the same
	 * rule differ only in their tail — comparing whole strings shares nothing and the
	 * opened card prints the same paragraph once per entry, which is the thing grouping
	 * exists to stop.
	 *
	 * The prefix is cut at a sentence boundary rather than at the last matching character.
	 * "Granted directly: edit_" over one entry and "users." over the next would be worse
	 * than the repetition it replaced. The boundary is ASCII and a UTF-8 continuation byte
	 * never equals an ASCII one, so cutting there cannot split a multibyte character.
	 *
	 * @param array $entries Findings in a group.
	 * @return string Shared leading text, or '' when there is none worth sharing.
	 */
	private static function shared_detail( array $entries ) {
		$common = null;

		foreach ( $entries as $entry ) {
			$detail = isset( $entry['detail'] ) ? (string) $entry['detail'] : '';

			if ( '' === $detail ) {
				return '';
			}

			if ( null === $common ) {
				$common = $detail;

				continue;
			}

			$length = min( strlen( $common ), strlen( $detail ) );
			$at     = 0;

			while ( $at < $length && $common[ $at ] === $detail[ $at ] ) {
				$at++;
			}

			$common = substr( $common, 0, $at );

			if ( '' === $common ) {
				return '';
			}
		}

		if ( null === $common ) {
			return '';
		}

		// Every entry says exactly the same thing: share all of it, boundary or not, and
		// the entries are left with nothing of their own to print.
		$identical = true;

		foreach ( $entries as $entry ) {
			if ( ( isset( $entry['detail'] ) ? (string) $entry['detail'] : '' ) !== $common ) {
				$identical = false;
			}
		}

		if ( $identical ) {
			return $common;
		}

		$cut = strrpos( $common, '. ' );

		if ( false === $cut ) {
			return '';
		}

		return substr( $common, 0, $cut + 1 );
	}

	/**
	 * A field's value when every entry agrees on it, or ''.
	 *
	 * @param array  $entries Findings in a group.
	 * @param string $field   Field name.
	 * @return string
	 */
	private static function shared_field( array $entries, $field ) {
		$first = isset( $entries[0][ $field ] ) ? (string) $entries[0][ $field ] : '';

		if ( '' === $first ) {
			return '';
		}

		foreach ( $entries as $entry ) {
			$value = isset( $entry[ $field ] ) ? (string) $entry[ $field ] : '';

			if ( $value !== $first ) {
				return '';
			}
		}

		return $first;
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
