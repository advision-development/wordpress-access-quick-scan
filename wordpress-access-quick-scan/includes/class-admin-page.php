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
			<h1>
				<?php esc_html_e( 'Access Check', 'wpaqs' ); ?>
				<?php // On the screen on purpose. "The sections do not fold" and "you are running
					// last week's zip" look identical over a screenshot, and one of them is not a
					// bug. A version an operator can read makes that a single glance. ?>
				<span class="wpaqs-version"><?php echo esc_html( WPAQS_VERSION ); ?></span>
			</h1>

			<p class="description">
				<?php esc_html_e( 'Who has access to this site right now, read live from the database every time you open this screen. It does not look at files or WordPress core — that is what Malware Quick Scan is for.', 'wpaqs' ); ?>
			</p>

			<?php self::render_notice(); ?>
			<?php self::render_findings( $findings ); ?>
			<?php self::render_code_holders( $accounts ); ?>
			<?php self::render_accounts( $accounts, $sessions ); ?>
			<?php self::render_passwords( $accounts, $passwords ); ?>
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
		$groups = WPAQS_Findings::group( $findings );
		?>
		<details class="wpaqs-card wpaqs-collapsible" open>
			<summary>
				<h2><?php esc_html_e( 'What stood out', 'wpaqs' ); ?></h2>
				<span class="description">
					<?php if ( empty( $findings ) ) : ?>
						<?php esc_html_e( 'nothing', 'wpaqs' ); ?>
					<?php else : ?>
						<?php
						printf(
							/* translators: 1: number of findings, 2: number of cards they fold into. */
							esc_html( _n( '%1$s finding in %2$s group', '%1$s findings in %2$s groups', count( $findings ), 'wpaqs' ) ),
							esc_html( number_format_i18n( count( $findings ) ) ),
							esc_html( number_format_i18n( count( $groups ) ) )
						);
						?>
					<?php endif; ?>
				</span>
			</summary>

			<?php if ( empty( $findings ) ) : ?>
				<p><?php esc_html_e( 'Nothing stood out in what this screen can see. That is not the same as the site being fine — read what it does not check, at the bottom.', 'wpaqs' ); ?></p>
			<?php else : ?>
				<?php foreach ( $groups as $group ) : ?>
					<?php self::render_group( $group ); ?>
				<?php endforeach; ?>
			<?php endif; ?>
		</details>
		<?php
	}

	/**
	 * One card per rule, with every target it found inside it.
	 *
	 * The wording every entry shares sits in the header once; each entry keeps its own
	 * evidence and whatever is left of its own detail. That remainder prints
	 * **unconditionally** — gating it on the header having shared nothing is how the
	 * sibling plugin made the modification date vanish from every grouped card the moment
	 * sharing started working.
	 *
	 * @param array $group One group from WPAQS_Findings::group().
	 * @return void
	 */
	private static function render_group( array $group ) {
		$total = count( $group['entries'] );
		$open  = $total < WPAQS_Findings::GROUP_COLLAPSE;
		?>
		<details class="wpaqs-finding wpaqs-collapsible wpaqs-sev-<?php echo esc_attr( $group['severity'] ); ?>" <?php echo $open ? 'open' : ''; ?>>
			<summary class="wpaqs-finding-head">
				<span class="wpaqs-badge wpaqs-sev-<?php echo esc_attr( $group['severity'] ); ?>">
					<?php echo esc_html( self::severity_label( $group['severity'] ) ); ?>
				</span>
				<strong><?php echo esc_html( $group['title'] ); ?></strong>
				<?php if ( $total > 1 ) : ?>
					<span class="wpaqs-count">
						<?php
						printf(
							/* translators: %s: how many accounts or settings the rule found. */
							esc_html( _n( '%s found', '%s found', $total, 'wpaqs' ) ),
							esc_html( number_format_i18n( $total ) )
						);
						?>
					</span>
				<?php endif; ?>
				<span class="wpaqs-rule"><?php echo esc_html( $group['rule'] ); ?></span>
			</summary>

			<?php if ( '' !== $group['detail'] ) : ?>
				<p><?php echo esc_html( $group['detail'] ); ?></p>
			<?php endif; ?>

			<?php self::render_entries( $group ); ?>

			<?php if ( '' !== $group['recommendation'] ) : ?>
				<p class="wpaqs-recommendation">
					<strong><?php esc_html_e( 'Next step:', 'wpaqs' ); ?></strong>
					<?php echo esc_html( $group['recommendation'] ); ?>
				</p>
			<?php endif; ?>
		</details>
		<?php
	}

	/**
	 * The targets inside a grouped card.
	 *
	 * @param array $group One group from WPAQS_Findings::group().
	 * @return void
	 */
	private static function render_entries( array $group ) {
		?>
		<ul class="wpaqs-entries">
			<?php foreach ( $group['entries'] as $entry ) : ?>
				<li>
					<?php if ( '' !== $entry['evidence'] ) : ?>
						<pre class="wpaqs-evidence"><?php echo esc_html( $entry['evidence'] ); ?></pre>
					<?php endif; ?>

					<?php // Whatever the header could not share. Printed always, never gated on it. ?>
					<?php if ( ! empty( $entry['detail'] ) ) : ?>
						<p class="wpaqs-entry-detail"><?php echo esc_html( $entry['detail'] ); ?></p>
					<?php endif; ?>

					<?php if ( ! empty( $entry['recommendation'] ) && '' === $group['recommendation'] ) : ?>
						<p class="wpaqs-recommendation">
							<strong><?php esc_html_e( 'Next step:', 'wpaqs' ); ?></strong>
							<?php echo esc_html( $entry['recommendation'] ); ?>
						</p>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
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
	private static function render_accounts( array $accounts, array $sessions ) {
		?>
		<?php // Open by default: this table is the answer, not reference material. It folds
			// because on a membership site it runs to hundreds of rows and somebody reading
			// the findings above wants it out of the way, not hidden. ?>
		<details class="wpaqs-card wpaqs-collapsible" open>
			<summary>
				<h2><?php esc_html_e( 'Who has access', 'wpaqs' ); ?></h2>
				<span class="description">
					<?php
					printf(
						/* translators: %s: number of accounts. */
						esc_html( _n( '%s account', '%s accounts', count( $accounts['rows'] ), 'wpaqs' ) ),
						esc_html( number_format_i18n( count( $accounts['rows'] ) ) )
					);
					?>
				</span>
			</summary>

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
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $accounts['rows'] as $row ) : ?>
						<?php
						$rows_sessions = isset( $sessions[ $row['id'] ] ) ? $sessions[ $row['id'] ] : array();
						$refusal       = WPAQS_Controller::session_refusal( $row['id'] );
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
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</details>
		<?php
	}

	/**
	 * Who can put code on this site.
	 *
	 * The first question during an incident, and the one no wp-admin screen answers: the
	 * Users list shows roles, and a role is neither the whole story nor obviously mapped to
	 * "can run code". Effective capabilities, so a grant made straight against an account
	 * counts the same as one that arrived with Administrator.
	 *
	 * @param array $accounts Result of WPAQS_Accounts::all().
	 * @return void
	 */
	private static function render_code_holders( array $accounts ) {
		$holders = WPAQS_Accounts::code_holders( $accounts );
		$editing = WPAQS_Accounts::file_editing_allowed();
		?>
		<?php // Closed by default. On a healthy site this list is the same every visit, and the
			// count in the summary is the part that changes — a number that moved is the reason
			// to open it. ?>
		<details class="wpaqs-card wpaqs-collapsible">
			<summary>
				<h2><?php esc_html_e( 'Who can run code', 'wpaqs' ); ?></h2>
				<span class="description">
					<?php
					printf(
						/* translators: %s: number of accounts. */
						esc_html( _n( '%s account', '%s accounts', count( $holders ), 'wpaqs' ) ),
						esc_html( number_format_i18n( count( $holders ) ) )
					);
					?>
				</span>
			</summary>

			<p class="description">
				<?php esc_html_e( 'Installing or editing a plugin or theme means running code on this site, so this list is the blast radius if any one of these accounts is taken. Capabilities are the effective ones: a grant made straight against an account counts the same as one that came with its role, and the Users screen shows neither.', 'wpaqs' ); ?>
			</p>

			<?php if ( $editing ) : ?>
				<div class="notice notice-warning inline">
					<p>
						<?php esc_html_e( 'The built-in theme and plugin editors are available on this site, so every account above can run code without uploading anything. Add define( \'DISALLOW_FILE_EDIT\', true ); to wp-config.php to close the shortest path from a stolen password to code running here — nothing legitimate uses those editors.', 'wpaqs' ); ?>
					</p>
				</div>
			<?php else : ?>
				<p class="description">
					<?php esc_html_e( 'The built-in file editors are switched off by a constant in wp-config.php, so these accounts cannot edit code from inside the admin.', 'wpaqs' ); ?>
				</p>
			<?php endif; ?>

			<?php if ( empty( $holders ) ) : ?>
				<p><?php esc_html_e( 'No account on this site holds a capability that installs or edits code. That is unusual, and worth confirming rather than celebrating: a site nobody can update is a site that does not get patched.', 'wpaqs' ); ?></p>
			<?php else : ?>
				<table class="widefat striped wpaqs-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Account', 'wpaqs' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Role', 'wpaqs' ); ?></th>
							<th scope="col"><?php esc_html_e( 'What it can do', 'wpaqs' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $holders as $holder ) : ?>
							<tr>
								<td>
									<strong><?php echo esc_html( $holder['account']['login'] ); ?></strong>
									<br /><span class="description"><?php echo esc_html( $holder['account']['email'] ); ?></span>
								</td>
								<td>
									<code><?php echo esc_html( empty( $holder['account']['roles'] ) ? __( 'no role', 'wpaqs' ) : implode( ', ', $holder['account']['roles'] ) ); ?></code>
								</td>
								<td>
									<code><?php echo esc_html( implode( ', ', $holder['caps'] ) ); ?></code>
									<?php if ( ! empty( $holder['direct'] ) ) : ?>
										<br /><span class="wpaqs-direct">
											<?php
											printf(
												/* translators: %s: comma separated capability names. */
												esc_html__( 'granted directly, not by a role: %s', 'wpaqs' ),
												esc_html( implode( ', ', $holder['direct'] ) )
											);
											?>
										</span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</details>
		<?php
	}

	/**
	 * Every application password on the site, in one place.
	 *
	 * They had a column in the accounts table and that was the wrong home for them. An
	 * application password authenticates the REST API as its owner and never touches the
	 * login form, so it is a key to the site rather than a detail about a person — and the
	 * question an operator asks is "how many of these exist and do I recognise them all",
	 * which a column repeated down a table of hundreds of accounts cannot answer.
	 *
	 * Moving them here also removed a second Revoke button for the same password on the same
	 * screen.
	 *
	 * @param array $accounts  Result of WPAQS_Accounts::all().
	 * @param array $passwords User id => application passwords.
	 * @return void
	 */
	private static function render_passwords( array $accounts, array $passwords ) {
		if ( ! WPAQS_App_Passwords::available() ) {
			?>
			<div class="wpaqs-card">
				<h2><?php esc_html_e( 'Application passwords', 'wpaqs' ); ?></h2>
				<p class="description"><?php esc_html_e( 'This WordPress version does not support them, so there are none to list.', 'wpaqs' ); ?></p>
			</div>
			<?php

			return;
		}

		$rows = array();

		foreach ( $accounts['rows'] as $account ) {
			$owned = isset( $passwords[ $account['id'] ] ) ? $passwords[ $account['id'] ] : array();

			foreach ( $owned as $password ) {
				$rows[] = array(
					'account'  => $account,
					'password' => $password,
				);
			}
		}

		// Newest first: a key issued five minutes ago is the one worth looking at.
		usort(
			$rows,
			function ( $a, $b ) {
				return (int) $b['password']['created'] - (int) $a['password']['created'];
			}
		);
		?>
		<details class="wpaqs-card wpaqs-collapsible" open>
			<summary>
				<h2><?php esc_html_e( 'Application passwords', 'wpaqs' ); ?></h2>
				<span class="description">
					<?php
					printf(
						/* translators: %s: number of application passwords on the site. */
						esc_html( _n( '%s active', '%s active', count( $rows ), 'wpaqs' ) ),
						esc_html( number_format_i18n( count( $rows ) ) )
					);
					?>
				</span>
			</summary>

			<p class="description">
				<?php esc_html_e( 'Each one authenticates the REST API as its owner and bypasses the login form, so it keeps working after a password change and after every session is ended. Revoking is the only thing that stops one.', 'wpaqs' ); ?>
			</p>

			<?php if ( empty( $rows ) ) : ?>
				<p><?php esc_html_e( 'None on this site.', 'wpaqs' ); ?></p>
			<?php else : ?>
				<table class="widefat striped wpaqs-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Name', 'wpaqs' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Account', 'wpaqs' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Created', 'wpaqs' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Last used', 'wpaqs' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Action', 'wpaqs' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<?php
							$account  = $row['account'];
							$password = $row['password'];
							?>
							<tr>
								<td>
									<strong><?php echo esc_html( '' === $password['name'] ? $password['uuid'] : $password['name'] ); ?></strong>
									<?php if ( 0 === $password['last_used'] ) : ?>
										<br /><span class="wpaqs-chip"><?php esc_html_e( 'never used', 'wpaqs' ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<?php echo esc_html( $account['login'] ); ?>
									<?php if ( $account['is_admin'] ) : ?>
										<br /><span class="wpaqs-chip"><?php esc_html_e( 'administrator', 'wpaqs' ); ?></span>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( WPAQS_App_Passwords::stamp( $password['created'] ) ); ?></td>
								<td>
									<?php echo esc_html( WPAQS_App_Passwords::stamp( $password['last_used'] ) ); ?>
									<br /><span class="description">
										<?php
										printf(
											/* translators: %s: the address it was last used from. */
											esc_html__( 'from %s', 'wpaqs' ),
											esc_html( '' === $password['last_ip'] ? __( 'no address recorded', 'wpaqs' ) : $password['last_ip'] )
										);
										?>
									</span>
								</td>
								<td>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
										onsubmit="return confirm( '<?php echo esc_js( __( 'Revoke this application password? This cannot be undone — the secret is deleted, not hidden. Anything using it stops working until somebody issues a new one. Browser sessions are unaffected.', 'wpaqs' ) ); ?>' );">
										<input type="hidden" name="action" value="wpaqs_revoke_password" />
										<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $account['id'] ); ?>" />
										<input type="hidden" name="uuid" value="<?php echo esc_attr( $password['uuid'] ); ?>" />
										<?php wp_nonce_field( WPAQS_NONCE . '-revoke-' . $account['id'] . '-' . $password['uuid'] ); ?>
										<button type="submit" class="button button-small"><?php esc_html_e( 'Revoke', 'wpaqs' ); ?></button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</details>
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
		<?php // Closed by default. Reference material, and the findings are what the page is
			// for — but never removed, because "nothing found" and "nothing checked" are
			// different answers and only this list tells them apart. ?>
		<details class="wpaqs-card wpaqs-collapsible">
			<summary>
				<h2><?php esc_html_e( 'What this does not check', 'wpaqs' ); ?></h2>
				<span class="description"><?php esc_html_e( 'four things, and why', 'wpaqs' ); ?></span>
			</summary>

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
		</details>
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
