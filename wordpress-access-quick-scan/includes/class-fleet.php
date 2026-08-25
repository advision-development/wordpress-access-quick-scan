<?php
/**
 * Talking to the fleet console.
 *
 * This file is copied verbatim into the sibling plugin, so it must stay byte-identical
 * except for the class prefix: `sed 's/WPAQS_/WPAQS_/g'` over the copy has to diff clean
 * against this. Two active plugins declaring a class of the same name is a PHP fatal,
 * and two copies that have quietly drifted is worse than either.
 *
 * That rule is why nothing here writes its own name. The REST namespace is derived from
 * the class prefix rather than spelled out, because sed does not touch lower case and a
 * literal would break the copy the moment somebody ran the check.
 *
 * Nothing here decides anything about the site. It carries a report that was already
 * produced, and it asks a question it is not allowed to answer for itself.
 *
 * @package WPAQS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The site's half of enrolment and reporting.
 */
class WPAQS_Fleet {

	/** Where the console lives. Overridable for a staging console. */
	const DEFAULT_ENDPOINT = 'https://hawkeye-advision.web.app/wpsec';

	/** Everything this stores lives under one option. */
	const OPTION = 'wpaqs_fleet';

	/** Ask about a pending enrolment no more often than this. */
	const POLL_INTERVAL = 900;

	/**
	 * The lower-case prefix this plugin uses, derived rather than written.
	 *
	 * WPAQS_Fleet -> wpaqs. Deriving it is what keeps this file byte-identical to the
	 * sibling's copy: `sed 's/WPAQS_/WPAQS_/g'` rewrites the class name and this follows,
	 * where a literal 'wpaqs' would not.
	 *
	 * @return string
	 */
	public static function prefix() {
		$class = __CLASS__;

		return strtolower( substr( $class, 0, strpos( $class, '_' ) ) );
	}

	/**
	 * The console's base URL.
	 *
	 * @return string
	 */
	public static function endpoint() {
		/**
		 * Filter the fleet console endpoint.
		 *
		 * @param string $endpoint Base URL with no trailing slash.
		 */
		return untrailingslashit( (string) apply_filters( self::prefix() . '_fleet_endpoint', self::DEFAULT_ENDPOINT ) );
	}

	/**
	 * Everything this plugin stores about the fleet.
	 *
	 * @return array
	 */
	public static function state() {
		$stored = get_option( self::OPTION );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Drop everything that says this site belongs to a fleet.
	 *
	 * The install nonce survives on purpose. It identifies this installation rather than
	 * this enrolment, and keeping it is what lets the console verify the site is the same
	 * one when it asks again — a fresh nonce would make a re-approval indistinguishable
	 * from a different install at the same address.
	 *
	 * @return void
	 */
	public static function forget() {
		$state = self::state();

		foreach ( array( 'key', 'enrolled_at', 'requested_at', 'polled_at', 'pushed_at', 'pushed_run', 'pushed_finished' ) as $gone ) {
			unset( $state[ $gone ] );
		}

		update_option( self::OPTION, $state, false );
	}

	/**
	 * Merge into the stored state.
	 *
	 * @param array $changes Keys to set.
	 * @return void
	 */
	private static function remember( array $changes ) {
		update_option( self::OPTION, array_merge( self::state(), $changes ), false );
	}

	/**
	 * This install's own nonce, created once.
	 *
	 * Proves to the console that the plugin answering a verification is the same install
	 * that asked to enrol — not merely a copy of the plugin at that domain. Without it,
	 * re-installing elsewhere on the same host would collect the key.
	 *
	 * @return string
	 */
	public static function install_nonce() {
		$state = self::state();

		if ( ! empty( $state['install_nonce'] ) ) {
			return (string) $state['install_nonce'];
		}

		$nonce = wp_generate_password( 43, false, false );

		self::remember( array( 'install_nonce' => $nonce ) );

		return $nonce;
	}

	/**
	 * The bearer key, or '' before one has been issued.
	 *
	 * @return string
	 */
	public static function key() {
		$state = self::state();

		return isset( $state['key'] ) ? (string) $state['key'] : '';
	}

	/**
	 * Whether this site has a key and may report.
	 *
	 * @return bool
	 */
	public static function enrolled() {
		return '' !== self::key();
	}

	/**
	 * Ask to be enrolled.
	 *
	 * Answers nothing by itself: the console records the request and waits for a person.
	 * That wait is the point — a site joining the fleet is how a compromise map starts
	 * being written about it, and that should be somebody's decision.
	 *
	 * @return array array( error )
	 */
	public static function enrol() {
		if ( self::enrolled() ) {
			return array( 'error' => '' );
		}

		$response = self::post(
			'/enroll',
			array(
				'plugin'        => self::prefix(),
				'siteUrl'       => home_url(),
				'pluginVersion' => self::version(),
				'installNonce'  => self::install_nonce(),
			),
			false
		);

		$changes = array( 'last_error' => $response['error'] );

		/*
		 * Only when the console actually received it.
		 *
		 * It was written on every attempt, and that turned one bad minute into a permanent
		 * state: keep_up_with_fleet() reads this field to decide between asking and
		 * polling, so a site whose first POST timed out spent the rest of its life polling
		 * about an enrolment nobody had created. The console answered `no-enrolment` every
		 * time, correctly, and nothing on either side ever went back to asking.
		 *
		 * Deactivating and reactivating does not clear it either — this lives in an option
		 * and deactivation only clears scheduled hooks — so the usual remedy did nothing,
		 * which is what made it hard to see.
		 *
		 * The same fault as `pushed_at`, fixed in 0.28.6 and left here.
		 */
		if ( '' === $response['error'] ) {
			$changes['requested_at'] = time();
		}

		self::remember( $changes );

		return array( 'error' => $response['error'] );
	}

	/**
	 * Ask whether the request was approved, and collect the key if it was.
	 *
	 * The key comes back exactly once. Anything that loses it enrols again, which is
	 * another decision by a person rather than a lookup.
	 *
	 * @return array array( error, status )
	 */
	public static function poll() {
		if ( self::enrolled() ) {
			return array( 'error' => '', 'status' => 'collected' );
		}

		$state = self::state();
		$last  = isset( $state['polled_at'] ) ? (int) $state['polled_at'] : 0;

		if ( time() - $last < self::POLL_INTERVAL ) {
			return array( 'error' => '', 'status' => 'waiting' );
		}

		$response = self::post(
			'/enrolment-status',
			array(
				'plugin'       => self::prefix(),
				'siteUrl'      => home_url(),
				'installNonce' => self::install_nonce(),
			),
			false
		);

		$changes = array( 'polled_at' => time(), 'last_error' => $response['error'] );
		$status  = isset( $response['body']['status'] ) ? (string) $response['body']['status'] : '';
		$refused = isset( $response['body']['error'] ) ? (string) $response['body']['error'] : '';

		/*
		 * The console has no record of this site asking, and says so. That is a fact this
		 * plugin can act on: ask again.
		 *
		 * This is the half that matters, because it repairs sites that are already stuck
		 * without anybody visiting them. Forgetting the request sends the next fleet check
		 * back down the enrol branch, and the console has been answering `no-enrolment`
		 * to those sites for as long as they have been polling — the sentence was always
		 * there and nothing was listening.
		 */
		if ( 'no-enrolment' === $refused ) {
			$state = self::state();

			unset( $state['requested_at'], $state['polled_at'] );
			update_option( self::OPTION, array_merge( $state, array( 'last_error' => $response['error'] ) ), false );

			return array( 'error' => $response['error'], 'status' => 'forgotten' );
		}

		if ( ! empty( $response['body']['key'] ) ) {
			$changes['key']         = (string) $response['body']['key'];
			$changes['enrolled_at'] = time();
		}

		self::remember( $changes );

		return array( 'error' => $response['error'], 'status' => $status );
	}

	/**
	 * Send a finished report.
	 *
	 * The export is used as-is. It is the same array the download button produces, and
	 * the suite already asserts it leaks no absolute paths, salts, passwords or the scan
	 * token — building a second shape here would be a second thing to keep honest.
	 *
	 * @param array $record  The stored report.
	 * @param string $run_id Identifies this scan execution, so a retry is not a new scan.
	 * @return array array( error )
	 */
	public static function push( array $record, $run_id ) {
		if ( ! self::enrolled() ) {
			return array( 'error' => __( 'This site is not enrolled with the fleet console.', 'wpaqs' ) );
		}

		$export = self::export( $record );

		$response = self::post(
			'/ingest',
			array(
				'v'             => 1,
				'plugin'        => self::prefix(),
				'siteUrl'       => home_url(),
				'scanRunId'     => (string) $run_id,
				'pluginVersion' => self::version(),
				'startedAt'     => isset( $record['started_at'] ) ? (int) $record['started_at'] : 0,
				'finishedAt'    => isset( $record['completed_at'] ) ? (int) $record['completed_at'] : time(),
				'findings'      => isset( $export['findings'] ) ? $export['findings'] : array(),
				/*
				 * Whatever else this plugin's export offers, forwarded rather than named.
				 *
				 * This file is byte-identical in both plugins and has to stay that way, so
				 * it may not know that one of them sends an access inventory and the other
				 * does not. `to_export_array()` decides what leaves the site; this decides
				 * only that it leaves.
				 */
				'access'        => isset( $export['access'] ) ? $export['access'] : null,
				'malware'       => isset( $export['malware'] ) ? $export['malware'] : null,
			),
			true
		);

		$changes = array( 'last_error' => $response['error'] );

		// What the report described, so the screen can name it. "Last report sent 13
		// hours ago" beside "last completed scan 6:02 am" is two renderings of one
		// moment, in different formats, and reads as two moments — which is how a
		// working site gets reported as one that scanned and did not send.
		$finished = isset( $record['completed_at'] ) ? (int) $record['completed_at'] : 0;

		// Only on success. It used to record every attempt, which made a site whose
		// first push failed look like a site that had reported — and the fleet check
		// asks exactly that question before deciding whether to retry, so one failed
		// attempt meant the report was never sent again.
		if ( '' === $response['error'] ) {
			$changes['pushed_at']       = time();
			$changes['pushed_run']      = (string) $run_id;
			$changes['pushed_finished'] = $finished;
		}

		self::remember( $changes );

		// 401 is the console saying it does not know this key: the site was removed from
		// the fleet, or its key was revoked. Retrying is pointless and staying enrolled
		// is a lie — this site would sit for ever believing it reports to something.
		// Forgetting puts it back in the console's approval queue, where a person can
		// decide again, which is the only recovery that does not need somebody to log
		// into the site.
		if ( isset( $response['code'] ) && 401 === (int) $response['code'] ) {
			self::forget();
		}

		return array( 'error' => $response['error'] );
	}

	/**
	 * POST JSON to the console.
	 *
	 * TLS verification is never relaxed. This is the second place in the plugin that
	 * talks to a server the site does not control, and the updater's reasoning applies
	 * here too: the answer is treated as untrusted, and nothing it says is executed.
	 *
	 * @param string $path       Path under the endpoint.
	 * @param array  $payload    Body.
	 * @param bool   $authorised Whether to send the bearer key.
	 * @return array array( error, body )
	 */
	private static function post( $path, array $payload, $authorised ) {
		$args = array(
			'timeout'     => 15,
			'redirection' => 0,
			'sslverify'   => true,
			'headers'     => array( 'Content-Type' => 'application/json' ),
			'body'        => wp_json_encode( $payload ),
		);

		if ( $authorised ) {
			// In a header, never a query string: a key in a URL survives in every access
			// log between here and the console.
			$args['headers']['Authorization'] = 'Bearer ' . self::key();
		}

		$response = wp_remote_post( self::endpoint() . $path, $args );

		if ( is_wp_error( $response ) ) {
			return array( 'error' => $response->get_error_message(), 'body' => array(), 'code' => 0 );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$body = json_decode( $raw, true );

		if ( $code < 200 || $code > 299 ) {
			$reason = is_array( $body ) && isset( $body['error'] ) ? (string) $body['error'] : (string) $code;

			return array(
				/* translators: %s: the reason the console gave. */
				'error' => sprintf( __( 'The fleet console refused this: %s', 'wpaqs' ), $reason ),
				'body'  => is_array( $body ) ? $body : array(),
				'code'  => $code,
			);
		}

		/*
		 * A 2xx is not success on its own, and treating it as one is how this fails
		 * silently. Every path under the console's prefix that is not a function is
		 * rewritten to its single-page app, which answers 200 with HTML — so one wrong
		 * character in a path, a rewrite dropped from a deploy, or an endpoint renamed on
		 * the far side would leave this plugin recording success on every request while
		 * the console received nothing. A firewall's block page does the same.
		 *
		 * Every endpoint here answers JSON. Anything else did not come from them.
		 */
		if ( ! is_array( $body ) ) {
			return array(
				/* translators: %s: the first characters of what the server sent instead. */
				'error' => sprintf(
					__( 'The fleet console answered, but not with JSON: %s', 'wpaqs' ),
					trim( preg_replace( '~\s+~', ' ', substr( $raw, 0, 80 ) ) )
				),
				'body'  => array(),
				'code'  => $code,
			);
		}

		return array( 'error' => '', 'body' => $body, 'code' => $code );
	}

	/**
	 * This plugin's version, read from its own constant.
	 *
	 * Derived so the shared copy stays byte-identical.
	 *
	 * @return string
	 */
	private static function version() {
		$constant = strtoupper( self::prefix() ) . '_VERSION';

		return defined( $constant ) ? (string) constant( $constant ) : '';
	}

	/**
	 * The report as the console should see it.
	 *
	 * Derived so the shared copy stays byte-identical: each plugin has its own report
	 * class, and both expose to_export_array().
	 *
	 * @param array $record The stored report.
	 * @return array
	 */
	private static function export( array $record ) {
		$class = strtoupper( self::prefix() ) . '_Report';

		if ( ! class_exists( $class ) || ! method_exists( $class, 'to_export_array' ) ) {
			return array();
		}

		return (array) call_user_func( array( $class, 'to_export_array' ), $record );
	}
}
