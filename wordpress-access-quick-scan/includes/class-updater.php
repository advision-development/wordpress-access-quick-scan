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
	public static function release() {
		$cached = get_site_transient( self::CACHE );

		if ( is_array( $cached ) ) {
			// A remembered failure is stored as an empty array, which is also what this returns
			// on failure — so a rate-limited site stops asking rather than asking harder.
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

		set_site_transient(
			self::CACHE,
			empty( $release ) ? array( 'failed' => true ) : $release,
			empty( $release ) ? self::FAILURE_TTL : self::CACHE_TTL
		);

		return $release;
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
