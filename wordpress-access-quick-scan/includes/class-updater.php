<?php
/**
 * Updates from this plugin's own GitHub releases.
 *
 * The plugin does not live on wordpress.org, so WordPress has nowhere to ask whether a newer
 * version exists and the Plugins screen shows no update however many releases are published.
 * This tells it where to ask.
 *
 * **This is the most dangerous thing in the plugin, by a distance.** Every other class reads.
 * This one hands WordPress a URL and WordPress downloads it, unzips it over the plugin
 * directory and runs it on the next request. A wrong answer here is arbitrary PHP on the site,
 * which is the thing the sibling plugin exists to find. So:
 *
 * - **The download URL is checked against a pinned host, owner and repository**, not taken from
 *   the response. The response is JSON from a remote server; if it is tampered with or the
 *   repository moves, the answer is to install nothing rather than to install from wherever the
 *   JSON points. This is the same rule the sibling applies to fetched detection rules, and code
 *   deserves it more than rules do.
 * - **TLS verification is never turned off.** Not as a fallback, not behind a filter. A plugin
 *   that would rather install something than nothing is a delivery mechanism.
 * - **A version is only ever offered upwards.** `version_compare( '0.9', '0.10' )` is not the
 *   comparison people expect and `version_compare( '1.2', '1.2.0' )` reports less-than, so both
 *   sides are padded to three components first. Without that a release can read as older than
 *   the copy installed and the update never appears, or worse, a downgrade does.
 *
 * **Failures are cached too.** GitHub allows 60 unauthenticated requests an hour per IP, and a
 * hosting provider's sites share one. Retrying on every admin page load is how one site being
 * rate-limited becomes every site on that host being rate-limited.
 *
 * @package WPAQS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Teaches WordPress where this plugin's releases are.
 */
class WPAQS_Updater {

	/** The only host a package may be downloaded from. */
	const HOST = 'github.com';

	/** The only account whose releases are this plugin's. */
	const OWNER = 'advision-development';

	/** The repository, which is also the plugin's directory name. */
	const REPO = 'wordpress-access-quick-scan';

	/** Where the answer is cached. */
	const CACHE = 'wpaqs_release';

	/** The endpoint that re-checks on request. */
	const CHECK_ACTION = 'wpaqs_check_release';

	/** How long a successful answer is trusted. */
	const CACHE_TTL = 43200;

	/**
	 * How long a failure is remembered.
	 *
	 * Shorter than a success so a transient outage does not hide an update for half a day, and
	 * long enough that a rate-limited site is not what keeps it rate-limited.
	 */
	const FAILURE_TTL = 3600;

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'offer' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'details' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'forget' ), 10, 2 );

		// Applied unattended, and see automatically() for the reasoning and the way out.
		// risk the pinning cannot reduce.
		add_filter( 'auto_update_plugin', array( __CLASS__, 'automatically' ), 10, 2 );
		add_filter( 'plugin_auto_update_setting_html', array( __CLASS__, 'explain_auto_update' ), 10, 2 );
		add_action( 'admin_post_' . self::CHECK_ACTION, array( __CLASS__, 'handle_check' ) );
	}

	/**
	 * Refuse to update this plugin without somebody pressing the button.
	 *
	 * WordPress offers an "Enable auto-updates" toggle for anything that reports update
	 * information, and turning it on would mean a release installs itself on the next cron run
	 * with nobody present.
	 *
	 * That is the one attack the pinning cannot help with. Every check in this file assumes the
	 * danger is a *tampered answer* — a URL pointing somewhere else, a response that is not
	 * GitHub's. None of them help if the release is genuinely published from the pinned
	 * repository by somebody who should not have been able to publish it. A compromised
	 * release is correctly signed, correctly hosted and correctly named, and every check here
	 * passes it.
	 *
	 * What is left is the plugin's own rule, applied to the plugin itself: **a person presses
	 * it.** The update is offered, the row says one is available, and installing it takes a
	 * click by somebody who can look at what changed first. That turns "the release account
	 * was compromised" from every site at once into every site whose operator pressed a button
	 * — which is a much smaller number and a much later one.
	 *
	 * The toggle is not merely disabled, it is explained. A control that silently does nothing
	 * reads as broken, and the next person to wonder why it is missing should find the reason
	 * on the screen rather than in this comment.
	 *
	 * @param bool|null $update Whether WordPress intends to update it.
	 * @param mixed     $item   The plugin being considered.
	 * @return bool|null
	 */
	public static function automatically( $update, $item ) {
		$file = self::basename();

		if ( is_object( $item ) && isset( $item->plugin ) && $file === $item->plugin ) {
			/**
			 * Filter whether this plugin updates itself unattended.
			 *
			 * The escape hatch, and it is code rather than a checkbox on purpose — see
			 * `explain_auto_update()` for why the checkbox is gone. A site that must not
			 * take unattended updates returns false here from its own mu-plugin.
			 *
			 * @param bool $auto Whether to update unattended.
			 */
			return (bool) apply_filters( 'wpaqs_auto_update', true );
		}

		// Not ours. Handing back what arrived leaves every other plugin's setting alone —
		// returning true here would quietly switch automatic updates on site-wide.
		return $update;
	}

	/**
	 * Say why the auto-update toggle is not there.
	 *
	 * @param string $html   The markup WordPress was going to print.
	 * @param string $plugin Plugin file being rendered.
	 * @return string
	 */
	public static function explain_auto_update( $html, $plugin ) {
		if ( self::basename() !== $plugin ) {
			return $html;
		}

		// The state of the last check goes here rather than only the policy. A row showing no
		// update cannot be told apart from a check that never ran or one that failed, and this
		// cell is where somebody wondering is already looking.
		return '<span class="description">'
			. esc_html__( 'Updates install themselves. An out-of-date scanner reports green across a fleet, which is worse than no scanner — so this one keeps itself current.', 'wpaqs' )
			. '<br />' . esc_html( self::status_text() )
			. '<br /><a href="' . esc_url( self::check_url() ) . '">' . esc_html__( 'Check for a new release now', 'wpaqs' ) . '</a>'
			. '</span>';
	}

	/**
	 * This plugin's entry in the plugin list, e.g. `wordpress-access-quick-scan/…php`.
	 *
	 * @return string
	 */
	public static function basename() {
		return plugin_basename( WPAQS_FILE );
	}

	/**
	 * Add this plugin to what WordPress believes has an update.
	 *
	 * @param mixed $transient The update_plugins site transient.
	 * @return mixed
	 */
	public static function offer( $transient ) {
		if ( ! is_object( $transient ) ) {
			// Something else has filtered this into a shape WordPress does not use. Handing it
			// back untouched is the only safe move: building the object here would discard
			// whatever that was.
			return $transient;
		}

		$release = self::release();

		if ( empty( $release ) ) {
			return $transient;
		}

		$file = self::basename();

		if ( ! self::is_newer( $release['version'], WPAQS_VERSION ) ) {
			// No update. WordPress reads `no_update` to decide whether the row offers automatic
			// updates at all, so an up-to-date plugin says so rather than staying silent.
			if ( isset( $transient->response[ $file ] ) ) {
				unset( $transient->response[ $file ] );
			}

			$transient->no_update[ $file ] = self::entry( $release );

			return $transient;
		}

		$transient->response[ $file ] = self::entry( $release );

		return $transient;
	}

	/**
	 * The object WordPress expects per plugin.
	 *
	 * @param array $release Result of release().
	 * @return object
	 */
	private static function entry( array $release ) {
		return (object) array(
			'id'            => self::OWNER . '/' . self::REPO,
			'slug'          => WPAQS_SLUG,
			'plugin'        => self::basename(),
			'new_version'   => $release['version'],
			'url'           => 'https://' . self::HOST . '/' . self::OWNER . '/' . self::REPO,
			'package'       => $release['package'],
			'requires'      => '5.8',
			'requires_php'  => '7.4',
			'tested'        => '',
			'icons'         => array(),
			'banners'       => array(),
			'compatibility' => new stdClass(),
		);
	}

	/**
	 * The "View details" panel, which would otherwise ask wordpress.org.
	 *
	 * Without this, the link WordPress prints beside an available update opens a modal that
	 * reports the plugin does not exist — a control that looks like it works and does not.
	 *
	 * @param mixed  $result The value being filtered.
	 * @param string $action Which plugins_api action was asked for.
	 * @param mixed  $args   Its arguments.
	 * @return mixed
	 */
	public static function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( ! isset( $args->slug ) || WPAQS_SLUG !== $args->slug ) {
			// Another plugin's request. Answering it would replace its panel with this one's.
			return $result;
		}

		$release = self::release();

		if ( empty( $release ) ) {
			return $result;
		}

		return (object) array(
			'name'          => 'WordPress Access Quick Scan',
			'slug'          => WPAQS_SLUG,
			'version'       => $release['version'],
			'author'        => '<a href="https://advisiondevelopment.com/">Advision Development</a>',
			'homepage'      => 'https://' . self::HOST . '/' . self::OWNER . '/' . self::REPO,
			'download_link' => $release['package'],
			'requires'      => '5.8',
			'requires_php'  => '7.4',
			'last_updated'  => $release['published'],
			'sections'      => array(
				// The release notes as GitHub returned them. Escaped, because this is remote
				// text rendered inside wp-admin: the same rule the report screen follows for
				// evidence, and the modal is no more trustworthy a place to print raw markup.
				'changelog' => '<pre>' . esc_html( $release['notes'] ) . '</pre>',
			),
		);
	}

	/**
	 * Throw the cached answer away after an update runs.
	 *
	 * Without this the site carries a cached "newer version available" for up to twelve hours
	 * after installing it, and the row keeps offering an update that is already applied.
	 *
	 * @param object $upgrader The upgrader instance.
	 * @param array  $extra    What it did.
	 * @return void
	 */
	public static function forget( $upgrader, $extra ) {
		if ( ! is_array( $extra ) || ! isset( $extra['type'] ) || 'plugin' !== $extra['type'] ) {
			return;
		}

		delete_site_transient( self::CACHE );
	}

	/**
	 * The latest release, or an empty array when there is not one to be had.
	 *
	 * @return array array( version, package, notes, published ) or array()
	 */
	public static function release( $force = false ) {
		$cached = get_site_transient( self::CACHE );

		if ( ! $force && is_array( $cached ) ) {
			// A remembered failure is stored with its reason, which is also what this returns
			// nothing for — so a rate-limited site stops asking rather than asking harder, and
			// status() can still say what went wrong.
			return isset( $cached['version'] ) ? $cached : array();
		}

		$response = wp_remote_get(
			'https://api.' . self::HOST . '/repos/' . self::OWNER . '/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 10,
				// Never relaxed. A plugin that would rather install something than nothing is a
				// delivery mechanism, and this one downloads code.
				'sslverify' => true,
				'headers' => array(
					'Accept' => 'application/vnd.github+json',
					// GitHub rejects requests without one.
					'User-Agent' => self::REPO . '/' . WPAQS_VERSION,
				),
			)
		);

		$release = self::parse( $response );

		if ( empty( $release ) ) {
			// Why it failed, not merely that it did. The screen prints this: a check that
			// silently found nothing is indistinguishable from one that never ran, which is the
			// question somebody staring at a plugin row with no update actually has.
			$stored = array(
				'failed'  => true,
				'reason'  => self::failure_reason( $response ),
				'checked' => time(),
			);
		} else {
			$stored            = $release;
			$stored['checked'] = time();
		}

		set_site_transient(
			self::CACHE,
			$stored,
			empty( $release ) ? self::FAILURE_TTL : self::CACHE_TTL
		);

		return $release;
	}

	/**
	 * Where pressing "check now" goes.
	 *
	 * @return string
	 */
	public static function check_url() {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::CHECK_ACTION ), self::CHECK_ACTION );
	}

	/**
	 * Re-check on request, then go back to where the press came from.
	 *
	 * Two caches sit between a published release and a row on the Plugins screen: this
	 * plugin's, and WordPress's own `update_plugins`, which it refreshes twice a day. Clearing
	 * only the first leaves somebody pressing a button that changes nothing they can see, so
	 * both go.
	 *
	 * @return void
	 */
	public static function handle_check() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'You do not have permission to check for plugin updates on this site.', 'wpaqs' ) );
		}

		check_admin_referer( self::CHECK_ACTION );

		delete_site_transient( self::CACHE );
		self::release( true );

		// WordPress's own list, or the row keeps showing what it decided this morning.
		delete_site_transient( 'update_plugins' );

		wp_safe_redirect( admin_url( 'plugins.php' ) );
		exit;
	}

	/**
	 * Why a check did not produce a release.
	 *
	 * Named rather than guessed at. The sibling plugin's quarantine row printed one cause for
	 * every reason a read could fail and sent somebody to look in the wrong place; the lesson
	 * written down from that is that an operator told the truth is unknown is better off than
	 * one told a confident wrong answer.
	 *
	 * @param mixed $response Result of wp_remote_get().
	 * @return string
	 */
	private static function failure_reason( $response ) {
		if ( is_wp_error( $response ) ) {
			return __( 'the site could not reach github.com', 'wpaqs' );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 403 === $code || 429 === $code ) {
			// GitHub allows 60 unauthenticated requests an hour per IP, and a hosting
			// provider's sites share one.
			return __( 'github.com refused the request, which on shared hosting is usually its hourly limit being reached by other sites on the same address', 'wpaqs' );
		}

		if ( 404 === $code ) {
			return __( 'github.com has no published release to report', 'wpaqs' );
		}

		if ( 200 !== $code ) {
			/* translators: %d: HTTP status code. */
			return sprintf( __( 'github.com answered with status %d', 'wpaqs' ), $code );
		}

		return __( 'the answer arrived but did not name a release this plugin would install', 'wpaqs' );
	}

	/**
	 * What the last check knows, for printing.
	 *
	 * Exists because a plugin row showing no update cannot be told apart from a check that
	 * never ran, one that failed, or one that ran before the release was published. That is the
	 * same fault as a control that silently never initialises: the screen has to say which.
	 *
	 * @return array array( state, version, reason, checked )
	 */
	public static function status() {
		$cached = get_site_transient( self::CACHE );

		if ( ! is_array( $cached ) ) {
			return array(
				'state'   => 'never',
				'version' => '',
				'reason'  => '',
				'checked' => 0,
			);
		}

		$checked = isset( $cached['checked'] ) ? (int) $cached['checked'] : 0;

		if ( ! isset( $cached['version'] ) ) {
			return array(
				'state'   => 'failed',
				'version' => '',
				'reason'  => isset( $cached['reason'] ) ? (string) $cached['reason'] : '',
				'checked' => $checked,
			);
		}

		return array(
			'state'   => self::is_newer( $cached['version'], WPAQS_VERSION ) ? 'available' : 'current',
			'version' => (string) $cached['version'],
			'reason'  => '',
			'checked' => $checked,
		);
	}

	/**
	 * One sentence describing the last check.
	 *
	 * @return string
	 */
	public static function status_text() {
		$status = self::status();

		switch ( $status['state'] ) {
			case 'never':
				return __( 'This site has not checked for a new release yet.', 'wpaqs' );
			case 'failed':
				return sprintf(
					/* translators: %s: why the check failed. */
					__( 'The last check for a new release did not succeed: %s.', 'wpaqs' ),
					$status['reason']
				);
			case 'available':
				return sprintf(
					/* translators: %s: version number. */
					__( 'Release %s is available. WordPress shows it on the Plugins screen once it next refreshes its own update list, which it does twice a day — pressing Check again on the Updates screen does it now.', 'wpaqs' ),
					$status['version']
				);
		}

		return sprintf(
			/* translators: %s: version number. */
			__( 'Up to date. The newest release is %s.', 'wpaqs' ),
			$status['version']
		);
	}

	/**
	 * Read a release out of the API response.
	 *
	 * Split out from the fetch so it can be tested against a real response body without a
	 * network, which is the only way the pinning below gets exercised.
	 *
	 * @param mixed $response Result of wp_remote_get().
	 * @return array
	 */
	public static function parse( $response ) {
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
			return array();
		}

		$version = self::version_of( $body['tag_name'] );

		if ( '' === $version ) {
			return array();
		}

		$package = self::package_in( isset( $body['assets'] ) ? $body['assets'] : array() );

		if ( '' === $package ) {
			// A release with no zip this plugin recognises. Offering the update anyway would
			// have WordPress download the tag's source archive, whose top-level directory is
			// named after the tag rather than after the plugin — WordPress would install it
			// alongside the copy already there instead of replacing it.
			return array();
		}

		return array(
			'version'   => $version,
			'package'   => $package,
			'notes'     => isset( $body['body'] ) ? (string) $body['body'] : '',
			'published' => isset( $body['published_at'] ) ? (string) $body['published_at'] : '',
		);
	}

	/**
	 * The version a tag names.
	 *
	 * @param string $tag Tag name, with or without the leading v.
	 * @return string Empty when the tag is not a version.
	 */
	public static function version_of( $tag ) {
		$tag = trim( (string) $tag );

		if ( 0 === strpos( $tag, 'v' ) ) {
			$tag = substr( $tag, 1 );
		}

		// Digits and dots only. A tag naming a branch, or carrying a suffix this plugin does
		// not publish, is not a version to compare against.
		if ( ! preg_match( '~^[0-9]+(\.[0-9]+){0,2}$~', $tag ) ) {
			return '';
		}

		return $tag;
	}

	/**
	 * The download URL of the one asset this plugin will install.
	 *
	 * The pinning lives here. Everything in the response is remote text, including the URL
	 * WordPress is about to download and unzip over the plugin directory — so it is checked
	 * against the host, owner and repository compiled into this file rather than trusted.
	 *
	 * @param array $assets The release's assets.
	 * @return string Empty when none of them qualifies.
	 */
	public static function package_in( $assets ) {
		if ( ! is_array( $assets ) ) {
			return '';
		}

		$prefix = 'https://' . self::HOST . '/' . self::OWNER . '/' . self::REPO . '/releases/download/';

		foreach ( $assets as $asset ) {
			if ( ! is_array( $asset ) || empty( $asset['browser_download_url'] ) ) {
				continue;
			}

			$url = (string) $asset['browser_download_url'];

			// One check, and it is enough: a URL's authority ends at the first slash after the
			// scheme, so a prefix that reaches into the path pins the host exactly. A
			// lookalike like https://github.com.evil.test/advision-development/… does not
			// start with this string, and neither does http:// — the scheme is in the prefix
			// too.
			//
			// This was two checks. The second parsed the host and compared it, which read as
			// defence in depth and was unreachable: removing it failed no assertion, because
			// nothing that passes the prefix can have another host. Dead code justified by a
			// comment claiming otherwise is worse than either on its own — the next reader
			// would have believed the claim.
			if ( 0 !== strpos( $url, $prefix ) ) {
				continue;
			}

			// A prefix pins the host but not the repository, because HTTP clients resolve `..`
			// out of a path before sending it — RFC 3986's remove_dot_segments. So
			// …/wordpress-access-quick-scan/releases/download/../../../../someone/their-repo/…
			// starts with the prefix, passes, and downloads from another account's release.
			// Still on github.com, still served 200, and not this plugin.
			//
			// Found by a test case written to prove the prefix was sufficient. It was not.
			if ( false !== strpos( $url, '..' ) ) {
				continue;
			}

			// The zip this plugin builds. A release carrying several files must not have one of
			// the others installed as the plugin.
			$name = isset( $asset['name'] ) ? (string) $asset['name'] : '';

			if ( 0 !== strpos( $name, self::REPO . '-' ) || '.zip' !== substr( $name, -4 ) ) {
				continue;
			}

			return $url;
		}

		return '';
	}

	/**
	 * Whether one version is newer than another.
	 *
	 * Both sides padded to three components first. `version_compare( '1.2', '1.2.0' )` reports
	 * less-than, so an unpadded comparison against a two-component header clears a site that
	 * has an update waiting — the sibling plugin has the same lesson written down about
	 * checking WordPress's own version.
	 *
	 * @param string $remote    The release's version.
	 * @param string $installed The version running.
	 * @return bool
	 */
	public static function is_newer( $remote, $installed ) {
		$remote    = self::normalize( $remote );
		$installed = self::normalize( $installed );

		if ( '' === $remote || '' === $installed ) {
			return false;
		}

		return version_compare( $remote, $installed, '>' );
	}

	/**
	 * A version padded to three numeric components.
	 *
	 * @param string $version Version string.
	 * @return string Empty when it is not a version.
	 */
	public static function normalize( $version ) {
		$version = trim( (string) $version );

		if ( ! preg_match( '~^[0-9]+(\.[0-9]+){0,2}$~', $version ) ) {
			return '';
		}

		$parts = explode( '.', $version );

		while ( count( $parts ) < 3 ) {
			$parts[] = '0';
		}

		return implode( '.', $parts );
	}
}
