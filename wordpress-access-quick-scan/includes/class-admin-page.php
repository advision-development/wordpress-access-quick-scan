<?php
/**
 * The screen.
 *
 * Everything is read live on page load. There is no scan to start, no progress to watch
 * and no stored report, because the data is four database reads rather than a filesystem
 * walk.
 *
 * Evidence strings hold logins, email addresses and user agents — all of them supplied by
 * somebody else. They are escaped here at render, on the one path that renders them, and
 * nowhere else.
 *
 * @package WPAQS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders Tools -> Access Check.
 */
class WPAQS_Admin_Page {

	const SLUG = 'wordpress-access-quick-scan';

	/**
	 * Screen hook, so assets load nowhere else.
	 *
	 * @var string
	 */
	private static $hook = '';

	/**
	 * Register the Tools submenu page.
	 *
	 * @return void
	 */
	public static function add_menu() {
		self::$hook = (string) add_management_page(
			__( 'Access Check', 'wpaqs' ),
			__( 'Access Check', 'wpaqs' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Load the stylesheet on this screen only.
	 *
	 * @param string $hook Current screen hook suffix.
	 * @return void
	 */
	public static function enqueue( $hook ) {
		if ( $hook !== self::$hook ) {
			return;
		}

		wp_enqueue_style( 'wpaqs', WPAQS_URL . 'assets/access.css', array(), WPAQS_VERSION );
	}

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$accounts = WPAQS_Accounts::all();
		$findings = WPAQS_Accounts::findings( $accounts );
		$findings = array_merge( $findings, WPAQS_Registration::findings() );

		$sessions  = array();
		$passwords = array();

		foreach ( $accounts['rows'] as $row ) {
			$sessions[ $row['id'] ]  = WPAQS_Sessions::for_user( $row['id'] );
			$passwords[ $row['id'] ] = WPAQS_App_Passwords::for_user( $row['id'] );

			$findings = array_merge( $findings, WPAQS_Sessions::findings( $row, $sessions[ $row['id'] ] ) );
			$findings = array_merge(
				$findings,
				WPAQS_App_Passwords::findings(
					$row,
					$passwords[ $row['id'] ],
					WPAQS_Sessions::addresses( $sessions[ $row['id'] ] )
				)
			);
		}

		$findings = WPAQS_Findings::sorted( $findings );
		?>
		<div class="wrap wpaqs">
			<h1><?php esc_html_e( 'Access Check', 'wpaqs' ); ?></h1>

			<p class="description">
				<?php esc_html_e( 'Who has access to this site right now, read live from the database every time you open this screen. It does not look at files or WordPress core — that is what Malware Quick Scan is for.', 'wpaqs' ); ?>
			</p>

			<?php self::render_notice(); ?>
			<?php self::render_findings( $findings ); ?>
			<?php self::render_accounts( $accounts, $sessions, $passwords ); ?>
			<?php self::render_coverage(); ?>
		</div>
		<?php
	}

	/**
	 * The result of the last action.
	 *
	 * At the top, before the tables, and not inside any section: a notice rendered from a
	 * section inherits every condition that section has, and the result of pressing
	 * something belongs where the eye already is.
	 *
	 * @return void
	 */
	private static function render_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$notice = isset( $_GET['wpaqs-notice'] ) ? sanitize_key( wp_unslash( $_GET['wpaqs-notice'] ) ) : '';

		if ( '' === $notice ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$why = isset( $_GET['wpaqs-why'] ) ? rawurldecode( sanitize_text_field( wp_unslash( $_GET['wpaqs-why'] ) ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$count = isset( $_GET['wpaqs-count'] ) ? absint( $_GET['wpaqs-count'] ) : 0;
		?>
		<?php if ( 'sessions-ended' === $notice ) : ?>
			<div class="notice notice-success inline">
				<p>
					<?php
					printf(
						/* translators: %s: number of sessions ended. */
						esc_html( _n( 'Ended %s session. That account is signed out everywhere.', 'Ended %s sessions. That account is signed out everywhere.', $count, 'wpaqs' ) ),
						esc_html( number_format_i18n( $count ) )
					);
					?>
					<?php esc_html_e( 'It can sign in again with the same password, so change the password too if the concern is that somebody else knows it. Application passwords are not affected — those are revoked separately.', 'wpaqs' ); ?>
				</p>
			</div>
		<?php elseif ( 'revoked' === $notice ) : ?>
			<div class="notice notice-success inline">
				<p><?php esc_html_e( 'Application password revoked. Anything holding it can no longer authenticate. Existing browser sessions are unaffected.', 'wpaqs' ); ?></p>
			</div>
		<?php elseif ( 'sessions-refused' === $notice || 'revoke-refused' === $notice ) : ?>
			<div class="notice notice-error inline">
				<p><?php echo esc_html( '' === $why ? __( 'Nothing was changed.', 'wpaqs' ) : $why ); ?></p>
			</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * The findings, worst first.
	 *
	 * @param array $findings Sorted findings.
	 * @return void
	 */
	private static function render_findings( array $findings ) {
		?>
		<div class="wpaqs-card">
			<h2><?php esc_html_e( 'What stood out', 'wpaqs' ); ?></h2>

			<?php if ( empty( $findings ) ) : ?>
				<p><?php esc_html_e( 'Nothing stood out in what this screen can see. That is not the same as the site being fine — read what it does not check, at the bottom.', 'wpaqs' ); ?></p>
			<?php else : ?>
				<?php foreach ( $findings as $finding ) : ?>
					<div class="wpaqs-finding wpaqs-sev-<?php echo esc_attr( $finding['severity'] ); ?>">
						<div class="wpaqs-finding-head">
							<span class="wpaqs-badge wpaqs-sev-<?php echo esc_attr( $finding['severity'] ); ?>">
								<?php echo esc_html( self::severity_label( $finding['severity'] ) ); ?>
							</span>
							<strong><?php echo esc_html( $finding['title'] ); ?></strong>
							<span class="wpaqs-rule"><?php echo esc_html( $finding['rule'] ); ?></span>
						</div>

						<p><?php echo esc_html( $finding['detail'] ); ?></p>

						<?php if ( '' !== $finding['evidence'] ) : ?>
							<p class="wpaqs-evidence-label"><?php esc_html_e( 'What was read:', 'wpaqs' ); ?></p>
							<pre class="wpaqs-evidence"><?php echo esc_html( $finding['evidence'] ); ?></pre>
						<?php endif; ?>

						<?php if ( '' !== $finding['recommendation'] ) : ?>
							<p class="wpaqs-recommendation">
								<strong><?php esc_html_e( 'Next step:', 'wpaqs' ); ?></strong>
								<?php echo esc_html( $finding['recommendation'] ); ?>
							</p>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The inventory: every account, what it can do, and how it is being used.
	 *
	 * This table is the product. The findings above it are a shortcut to the rows worth
	 * reading first.
	 *
	 * @param array $accounts  Result of WPAQS_Accounts::all().
	 * @param array $sessions  User id => sessions.
	 * @param array $passwords User id => application passwords.
	 * @return void
	 */
	private static function render_accounts( array $accounts, array $sessions, array $passwords ) {
		?>
		<div class="wpaqs-card">
			<h2><?php esc_html_e( 'Who has access', 'wpaqs' ); ?></h2>

			<p class="description">
				<?php
				printf(
					/* translators: %s: number of accounts shown. */
					esc_html( _n( '%s account, newest first.', '%s accounts, newest first.', count( $accounts['rows'] ), 'wpaqs' ) ),
					esc_html( number_format_i18n( count( $accounts['rows'] ) ) )
				);
				?>
				<?php esc_html_e( 'Capabilities listed under a login are ones granted to the account directly rather than through its role — the Users screen does not show these.', 'wpaqs' ); ?>
			</p>

			<?php if ( $accounts['capped'] ) : ?>
				<div class="notice notice-warning inline">
					<p>
						<?php
						printf(
							/* translators: 1: accounts shown, 2: accounts on the site. */
							esc_html__( 'This site has %2$s accounts and this screen reads the %1$s newest. The rest were not looked at — they are not cleared, nobody checked them.', 'wpaqs' ),
							esc_html( number_format_i18n( count( $accounts['rows'] ) ) ),
							esc_html( number_format_i18n( $accounts['total'] ) )
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<table class="widefat striped wpaqs-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Account', 'wpaqs' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Role and extra capabilities', 'wpaqs' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Live sessions', 'wpaqs' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Application passwords', 'wpaqs' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $accounts['rows'] as $row ) : ?>
						<?php
						$rows_sessions  = isset( $sessions[ $row['id'] ] ) ? $sessions[ $row['id'] ] : array();
						$rows_passwords = isset( $passwords[ $row['id'] ] ) ? $passwords[ $row['id'] ] : array();
						$refusal        = WPAQS_Controller::session_refusal( $row['id'] );
						?>
						<tr>
							<td>
								<strong><?php echo esc_html( $row['login'] ); ?></strong>
								<br /><span class="description"><?php echo esc_html( $row['email'] ); ?></span>
								<br /><span class="description">
									<?php
									printf(
										/* translators: %s: registration date. */
										esc_html__( 'registered %s', 'wpaqs' ),
										esc_html( $row['registered'] )
									);
									?>
								</span>
							</td>
							<td>
								<code><?php echo esc_html( empty( $row['roles'] ) ? __( 'no role', 'wpaqs' ) : implode( ', ', $row['roles'] ) ); ?></code>
								<?php if ( ! empty( $row['direct'] ) ) : ?>
									<br /><span class="wpaqs-direct">
										<?php echo esc_html( implode( ', ', $row['direct'] ) ); ?>
									</span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( empty( $rows_sessions ) ) : ?>
									<span class="description"><?php esc_html_e( 'none open', 'wpaqs' ); ?></span>
								<?php else : ?>
									<ul class="wpaqs-sessions">
										<?php foreach ( $rows_sessions as $session ) : ?>
											<li>
												<code><?php echo esc_html( '' === $session['ip'] ? __( 'no address recorded', 'wpaqs' ) : $session['ip'] ); ?></code>
												<?php if ( WPAQS_Sessions::is_scripted( $session['ua'] ) ) : ?>
													<span class="wpaqs-chip"><?php esc_html_e( 'not a browser', 'wpaqs' ); ?></span>
												<?php endif; ?>
												<br /><span class="description">
													<?php echo esc_html( WPAQS_App_Passwords::stamp( $session['login'] ) ); ?>
													—
													<?php echo esc_html( '' === $session['ua'] ? __( 'no user agent sent', 'wpaqs' ) : $session['ua'] ); ?>
												</span>
											</li>
										<?php endforeach; ?>
									</ul>

									<?php if ( '' === $refusal ) : ?>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
											onsubmit="return confirm( '<?php echo esc_js( __( 'End every session for this account? It is signed out everywhere and has to sign in again — nothing is deleted and no password changes. Application passwords keep working; revoke those separately.', 'wpaqs' ) ); ?>' );">
											<input type="hidden" name="action" value="wpaqs_end_sessions" />
											<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $row['id'] ); ?>" />
											<?php wp_nonce_field( WPAQS_NONCE . '-end-sessions-' . $row['id'] ); ?>
											<button type="submit" class="button button-small"><?php esc_html_e( 'End these sessions', 'wpaqs' ); ?></button>
										</form>
									<?php elseif ( 'self' === $refusal ) : ?>
										<p class="description"><?php echo esc_html( WPAQS_Controller::refusal_text( $refusal ) ); ?></p>
									<?php endif; ?>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( ! WPAQS_App_Passwords::available() ) : ?>
									<span class="description"><?php esc_html_e( 'not supported by this WordPress', 'wpaqs' ); ?></span>
								<?php elseif ( empty( $rows_passwords ) ) : ?>
									<span class="description"><?php esc_html_e( 'none', 'wpaqs' ); ?></span>
								<?php else : ?>
									<?php foreach ( $rows_passwords as $password ) : ?>
										<p class="wpaqs-password">
											<strong><?php echo esc_html( '' === $password['name'] ? $password['uuid'] : $password['name'] ); ?></strong>
											<br /><span class="description">
												<?php
												printf(
													/* translators: 1: last used, 2: last address. */
													esc_html__( 'last used %1$s from %2$s', 'wpaqs' ),
													esc_html( WPAQS_App_Passwords::stamp( $password['last_used'] ) ),
													esc_html( '' === $password['last_ip'] ? __( 'no address recorded', 'wpaqs' ) : $password['last_ip'] )
												);
												?>
											</span>
										</p>

										<?php // Its own form, a sibling of the sessions one. Never nested: HTML terminates an outer form at an inner one. ?>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
											onsubmit="return confirm( '<?php echo esc_js( __( 'Revoke this application password? This cannot be undone — the secret is deleted, not hidden. Anything using it stops working until somebody issues a new one. Browser sessions are unaffected.', 'wpaqs' ) ); ?>' );">
											<input type="hidden" name="action" value="wpaqs_revoke_password" />
											<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $row['id'] ); ?>" />
											<input type="hidden" name="uuid" value="<?php echo esc_attr( $password['uuid'] ); ?>" />
											<?php wp_nonce_field( WPAQS_NONCE . '-revoke-' . $row['id'] . '-' . $password['uuid'] ); ?>
											<button type="submit" class="button button-small"><?php esc_html_e( 'Revoke', 'wpaqs' ); ?></button>
										</form>
									<?php endforeach; ?>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * What this screen cannot check.
	 *
	 * Stated, never omitted. A findings list cannot tell "checked and clean" from "never
	 * checked", and every one of these is the second thing.
	 *
	 * @return void
	 */
	private static function render_coverage() {
		?>
		<div class="wpaqs-card">
			<h2><?php esc_html_e( 'What this does not check', 'wpaqs' ); ?></h2>

			<ul class="wpaqs-coverage">
				<li>
					<strong><?php esc_html_e( 'Failed logins.', 'wpaqs' ); ?></strong>
					<?php esc_html_e( 'WordPress core does not record them at all. Not a gap in this plugin — there is no data to read.', 'wpaqs' ); ?>
				</li>
				<li>
					<strong><?php esc_html_e( 'Login history.', 'wpaqs' ); ?></strong>
					<?php esc_html_e( 'Only sessions that are still open appear above. A session that expired, or that somebody ended, leaves nothing behind.', 'wpaqs' ); ?>
				</li>
				<li>
					<strong><?php esc_html_e( 'Whether an address is suspicious.', 'wpaqs' ); ?></strong>
					<?php esc_html_e( 'Addresses are shown so you can recognise them. This screen makes no network requests, so it holds no opinion about any of them.', 'wpaqs' ); ?>
				</li>
				<li>
					<strong><?php esc_html_e( 'Files, WordPress core, and hardening settings.', 'wpaqs' ); ?></strong>
					<?php esc_html_e( 'A different question and a different plugin: Malware Quick Scan reads the filesystem and the database for signs of compromise.', 'wpaqs' ); ?>
				</li>
			</ul>
		</div>
		<?php
	}

	/**
	 * Translated severity label.
	 *
	 * @param string $severity Severity slug.
	 * @return string
	 */
	private static function severity_label( $severity ) {
		switch ( $severity ) {
			case 'critical':
				return __( 'Critical', 'wpaqs' );
			case 'high':
				return __( 'High', 'wpaqs' );
			case 'medium':
				return __( 'Medium', 'wpaqs' );
		}

		return __( 'Info', 'wpaqs' );
	}
}
