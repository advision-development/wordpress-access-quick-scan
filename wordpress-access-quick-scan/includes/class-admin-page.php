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

		// Assembled by WPAQS_Report so the console reads the same answer by the same
		// route. Two routes to one question is how a finding shown here stops matching
		// the finding shown there.
		$gathered  = WPAQS_Report::gather();
		$accounts  = $gathered['accounts'];
		$sessions  = $gathered['sessions'];
		$passwords = $gathered['passwords'];
		$findings  = $gathered['findings'];
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
			<?php self::render_timeline( WPAQS_Timeline::build( $accounts, $sessions, $passwords ) ); ?>
			<?php self::render_code_holders( $accounts ); ?>
			<?php self::render_accounts( $accounts, $sessions ); ?>
			<?php self::render_passwords( $accounts, $passwords ); ?>
			<?php WPAQS_Fleet_Panel::render(); ?>
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
		<?php elseif ( 'session-ended' === $notice ) : ?>
			<div class="notice notice-success inline">
				<p><?php esc_html_e( 'That one session is closed. Every other session on the account is still open, and application passwords are unaffected.', 'wpaqs' ); ?></p>
			</div>
		<?php elseif ( 'capability-removed' === $notice ) : ?>
			<div class="notice notice-success inline">
				<p>
					<?php
					// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
					$cap = isset( $_GET['wpaqs-cap'] ) ? sanitize_key( wp_unslash( $_GET['wpaqs-cap'] ) ) : '';

					printf(
						/* translators: %s: capability name. */
						esc_html__( 'Removed %s from that account. Its role is untouched, so anything the role grants it still has. Granting the capability again puts this back.', 'wpaqs' ),
						esc_html( '' === $cap ? __( 'the capability', 'wpaqs' ) : $cap )
					);
					?>
				</p>
			</div>
		<?php elseif ( 'default-role-parked' === $notice ) : ?>
			<div class="notice notice-success inline">
				<p><?php esc_html_e( 'New accounts will be Subscribers. Accounts that already exist keep the role they have — this changed what the next one gets, not who can do what today. Settings then General puts it back.', 'wpaqs' ); ?></p>
			</div>
		<?php elseif ( 'registration-closed' === $notice ) : ?>
			<div class="notice notice-success inline">
				<p><?php esc_html_e( 'Registration is closed. Nobody can create an account until it is opened again under Settings then General, and accounts that already exist are unaffected.', 'wpaqs' ); ?></p>
			</div>
		<?php elseif ( 'capability-refused' === $notice || 'registration-refused' === $notice ) : ?>
			<div class="notice notice-error inline">
				<p><?php echo esc_html( '' === $why ? __( 'Nothing was changed.', 'wpaqs' ) : $why ); ?></p>
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
		$mine = WPAQS_Sessions::current_verifier();
		$sort = WPAQS_Sort::requested( 'accounts', array( 'registered', 'login' ) );

		if ( ! WPAQS_Sort::is_active( 'accounts' ) ) {
			// Newest first, which is the order all() already returns.
			$sort['dir'] = 'desc';
		}

		$accounts['rows'] = WPAQS_Sort::apply(
			$accounts['rows'],
			$sort['dir'],
			function ( $row ) use ( $sort ) {
				if ( 'login' === $sort['key'] ) {
					return $row['login'];
				}

				// The stored string, not a timestamp: it is already sortable and parsing it
				// would turn an unreadable date into a zero that sorts to one end.
				return $row['registered'];
			}
		);

		// Filtered after sorting, never before: filtering first would sort a shorter list and
		// give the same answer, but switching back to every row would then need the sort run
		// again, and two places deciding the order is how one of them drifts.
		$view     = WPAQS_Filter::requested( 'accounts' );
		$filtered = WPAQS_Filter::apply(
			$accounts['rows'],
			$view,
			function ( $row ) use ( $sessions ) {
				// Open, not merely stored. An account whose every session has lapsed is exactly
				// what this view exists to get out of the way.
				$rows = isset( $sessions[ $row['id'] ] ) ? $sessions[ $row['id'] ] : array();

				return ! empty( WPAQS_Sessions::open( $rows ) );
			}
		);

		// The number the cap notice talks about is how many accounts this screen *read*, which
		// the filter does not change. Reading it off the filtered list would have the notice
		// say the screen read two of the site's 141 newest.
		$read             = count( $accounts['rows'] );
		$shown            = $filtered['rows'];
		$accounts['rows'] = $shown;
		?>
		<?php // Open by default: this table is the answer, not reference material. It folds
			// because on a membership site it runs to hundreds of rows and somebody reading
			// the findings above wants it out of the way, not hidden. ?>
		<details class="wpaqs-card wpaqs-collapsible" id="wpaqs-accounts" open>
			<summary>
				<h2><?php esc_html_e( 'Who has access', 'wpaqs' ); ?></h2>
				<span class="description">
					<?php
					printf(
						/* translators: %s: number of accounts. */
						esc_html( _n( '%s account', '%s accounts', count( $shown ), 'wpaqs' ) ),
						esc_html( number_format_i18n( count( $shown ) ) )
					);
					?>
				</span>
			</summary>

			<p class="description">
				<?php
				printf(
					/* translators: %s: number of accounts shown. */
					esc_html( _n( '%s account, newest first.', '%s accounts, newest first.', count( $shown ), 'wpaqs' ) ),
					esc_html( number_format_i18n( count( $shown ) ) )
				);
				?>
				<?php esc_html_e( 'Capabilities listed under a login are ones granted to the account directly rather than through its role — the Users screen does not show these.', 'wpaqs' ); ?>
			</p>

			<?php // A filter that hides rows says how many it hid. A list of two where a moment
				// ago there were 140 is indistinguishable from a site that lost 138 accounts,
				// and "nothing here" has to mean that rather than "nothing in this view". ?>
			<p class="wpaqs-filter">
				<a class="button button-small" href="<?php echo esc_url( WPAQS_Filter::url( 'accounts', $view, 'wpaqs-accounts' ) ); ?>">
					<?php
					echo esc_html(
						WPAQS_Filter::ACTIVE === $view
							? __( 'Show every account', 'wpaqs' )
							: __( 'Only accounts signed in right now', 'wpaqs' )
					);
					?>
				</a>
				<?php if ( WPAQS_Filter::ACTIVE === $view ) : ?>
					<span class="description">
						<?php
						printf(
							/* translators: %s: number of accounts hidden by the filter. */
							esc_html( _n( '%s account with no open session is hidden.', '%s accounts with no open session are hidden.', $filtered['hidden'], 'wpaqs' ) ),
							esc_html( number_format_i18n( $filtered['hidden'] ) )
						);
						?>
					</span>
				<?php endif; ?>
			</p>

			<?php if ( WPAQS_Filter::ACTIVE === $view && empty( $shown ) ) : ?>
				<?php // An empty filtered table must not read as an empty site. ?>
				<p class="description">
					<?php esc_html_e( 'No account has a session open right now. That is the normal state of a site nobody is working on, and it is not a statement about the accounts themselves — press the button above to read them.', 'wpaqs' ); ?>
				</p>
			<?php endif; ?>

			<?php if ( $accounts['capped'] ) : ?>
				<div class="notice notice-warning inline">
					<p>
						<?php
						printf(
							/* translators: 1: accounts shown, 2: accounts on the site. */
							esc_html__( 'This site has %2$s accounts and this screen reads the %1$s newest. The rest were not looked at — they are not cleared, nobody checked them.', 'wpaqs' ),
							esc_html( number_format_i18n( $read ) ),
							esc_html( number_format_i18n( $accounts['total'] ) )
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<table class="widefat striped wpaqs-table">
				<thead>
					<tr>
						<?php self::sortable( 'accounts', 'login', __( 'Account', 'wpaqs' ), $sort, 'wpaqs-accounts' ); ?>
						<th scope="col"><?php esc_html_e( 'Role and extra capabilities', 'wpaqs' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Live sessions', 'wpaqs' ); ?></th>
						<?php self::sortable( 'accounts', 'registered', __( 'Registered', 'wpaqs' ), $sort, 'wpaqs-accounts' ); ?>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $accounts['rows'] as $row ) : ?>
						<?php
						$rows_sessions = isset( $sessions[ $row['id'] ] ) ? $sessions[ $row['id'] ] : array();
						// Which controls this row carries is decided in one place, and tested by
						// counting buttons rather than by reading this file.
						$rows_open     = WPAQS_Sessions::open( $rows_sessions );
						$controls      = WPAQS_Sessions::controls( count( $rows_open ) );
						$refusal       = WPAQS_Controller::session_refusal( $row['id'], get_current_user_id() );
						?>
						<tr>
							<td>
								<strong><?php echo esc_html( $row['login'] ); ?></strong>
								<br /><span class="description"><?php echo esc_html( $row['email'] ); ?></span>
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
									<?php // Every stored session has lapsed. Saying only "none open" would throw
										// away the addresses and dates, which are the only login history
										// WordPress keeps — so the note goes above the list rather than
										// replacing it. ?>
									<?php if ( empty( $rows_open ) ) : ?>
										<span class="description"><?php esc_html_e( 'none open — every session below has expired, and is shown because it is the only login history WordPress keeps', 'wpaqs' ); ?></span>
									<?php endif; ?>
									<ul class="wpaqs-sessions">
										<?php foreach ( $rows_sessions as $session ) : ?>
											<li>
												<code><?php echo esc_html( '' === $session['ip'] ? __( 'no address recorded', 'wpaqs' ) : $session['ip'] ); ?></code>
												<?php if ( WPAQS_Sessions::is_scripted( $session['ua'] ) ) : ?>
													<span class="wpaqs-chip"><?php esc_html_e( 'not a browser', 'wpaqs' ); ?></span>
												<?php endif; ?>
												<?php // WordPress prunes expired tokens only when it next writes the
													// meta, so an account that stopped signing in keeps them. Without
													// this chip a sign-in from two years ago reads as open. ?>
												<?php if ( ! empty( $session['expired'] ) ) : ?>
													<span class="wpaqs-chip wpaqs-chip-expired"><?php esc_html_e( 'expired', 'wpaqs' ); ?></span>
												<?php endif; ?>
												<?php // Knowing which one is yours matters before pressing anything that
													// ends a session, and today the only clue is recognising the address. ?>
												<?php if ( '' !== $mine && $session['verifier'] === $mine ) : ?>
													<span class="wpaqs-chip wpaqs-chip-you"><?php esc_html_e( 'this is you', 'wpaqs' ); ?></span>
												<?php endif; ?>
												<br /><span class="description">
													<?php echo esc_html( WPAQS_App_Passwords::stamp( $session['login'] ) ); ?>
													—
													<?php echo esc_html( '' === $session['ua'] ? __( 'no user agent sent', 'wpaqs' ) : $session['ua'] ); ?>
												</span>

												<?php // One session, so an administrator can close a scripted one without
													// signing themselves out. Its own form, a sibling of the others. ?>
												<?php if ( $controls['per_session'] && '' !== $session['verifier'] && empty( $session['expired'] ) ) : ?>
													<br />
													<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
														onsubmit="return confirm( '<?php echo esc_js( __( 'End this one session? Every other session on the account stays open, including your own if this is your account. Whoever held it has to sign in again.', 'wpaqs' ) ); ?>' );">
														<input type="hidden" name="action" value="wpaqs_end_session" />
														<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $row['id'] ); ?>" />
														<input type="hidden" name="verifier" value="<?php echo esc_attr( $session['verifier'] ); ?>" />
														<?php wp_nonce_field( WPAQS_NONCE . '-end-session-' . $row['id'] . '-' . $session['verifier'] ); ?>
														<button type="submit" class="button button-small"><?php esc_html_e( 'End this session', 'wpaqs' ); ?></button>
													</form>
												<?php endif; ?>
											</li>
										<?php endforeach; ?>
									</ul>

									<?php if ( $controls['bulk'] && '' === $refusal ) : ?>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
											onsubmit="return confirm( '<?php echo esc_js( __( 'End every session for this account? It is signed out everywhere and has to sign in again — nothing is deleted and no password changes. Application passwords keep working; revoke those separately.', 'wpaqs' ) ); ?>' );">
											<input type="hidden" name="action" value="wpaqs_end_sessions" />
											<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $row['id'] ); ?>" />
											<?php wp_nonce_field( WPAQS_NONCE . '-end-sessions-' . $row['id'] ); ?>
											<button type="submit" class="button button-small">
												<?php
												printf(
													/* translators: %s: number of sessions. */
													esc_html( _n( 'End this session', 'End all %s sessions', count( $rows_sessions ), 'wpaqs' ) ),
													esc_html( number_format_i18n( count( $rows_sessions ) ) )
												);
												?>
											</button>
										</form>
									<?php elseif ( 'self' === $refusal ) : ?>
										<p class="description"><?php echo esc_html( WPAQS_Controller::refusal_text( $refusal ) ); ?></p>
									<?php endif; ?>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $row['registered'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</details>
		<?php
	}

	/**
	 * The control that clears one finding, when there is one.
	 *
	 * A finding with no way to resolve it is noise however true it is, so every rule that can
	 * be cleared from here carries its own button. The ones that cannot say what to do
	 * instead, in the recommendation, and carry nothing.
	 *
	 * @param array $finding One finding.
	 * @return void
	 */
	private static function render_finding_action( array $finding ) {
		if ( 'open_registration_privileged_role' === $finding['rule'] ) {
			self::render_registration_actions();

			return;
		}

		if ( 'capability_outside_role' === $finding['rule'] ) {
			self::render_capability_actions( $finding );
		}
	}

	/**
	 * Two ways to close open registration handing out a privileged role.
	 *
	 * Both are settings rather than deletions: Settings then General puts either back, and no
	 * account already created is touched. On a network, registration is not a per-site setting
	 * at all, so the screen says where it lives instead of offering a button that would change
	 * nothing.
	 *
	 * @return void
	 */
	private static function render_registration_actions() {
		$state = WPAQS_Registration::state();
		?>
		<p class="wpaqs-actions">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
				onsubmit="return confirm( '<?php echo esc_js( __( 'Set the role new accounts receive to Subscriber? Accounts that already exist keep the role they have — this only changes what the next one gets. Settings then General puts it back.', 'wpaqs' ) ); ?>' );">
				<input type="hidden" name="action" value="wpaqs_park_default_role" />
				<?php wp_nonce_field( WPAQS_NONCE . '-park-default-role' ); ?>
				<button type="submit" class="button button-small"><?php esc_html_e( 'Make new accounts Subscribers', 'wpaqs' ); ?></button>
			</form>

			<?php if ( $state['network'] ) : ?>
				<span class="description">
					<?php esc_html_e( 'Registration itself is a network setting here, so it is changed under Network Admin then Settings rather than from this screen.', 'wpaqs' ); ?>
				</span>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
					onsubmit="return confirm( '<?php echo esc_js( __( 'Close registration to the public? Nobody can create an account until you open it again under Settings then General. Accounts that already exist are unaffected. If this site sells memberships, closing it stops new customers signing up — change the default role instead.', 'wpaqs' ) ); ?>' );">
					<input type="hidden" name="action" value="wpaqs_close_registration" />
					<?php wp_nonce_field( WPAQS_NONCE . '-close-registration' ); ?>
					<button type="submit" class="button button-small"><?php esc_html_e( 'Close registration', 'wpaqs' ); ?></button>
				</form>
			<?php endif; ?>
		</p>
		<?php
	}

	/**
	 * A button per capability granted straight to an account.
	 *
	 * One button per capability rather than one for all of them: the finding asks the operator
	 * to confirm each grant, and confirming often ends in keeping some and removing others.
	 *
	 * @param array $finding One capability_outside_role finding.
	 * @return void
	 */
	private static function render_capability_actions( array $finding ) {
		if ( ! preg_match( '~^user:(\d+)$~', (string) $finding['target'], $matched ) ) {
			return;
		}

		$user_id = (int) $matched[1];

		if ( ! WPAQS_Controller::user_can_act() || get_current_user_id() === $user_id ) {
			return;
		}

		// The capabilities are in the evidence because that is where the reader put them, and
		// re-reading them live keeps the buttons honest if one was removed since.
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return;
		}

		$direct = WPAQS_Accounts::notable( WPAQS_Accounts::direct_capabilities( $user, WPAQS_Accounts::registered_roles() ) );

		if ( empty( $direct ) ) {
			return;
		}
		?>
		<p class="wpaqs-actions">
			<?php foreach ( $direct as $cap ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
					onsubmit="return confirm( '<?php echo esc_js( __( 'Take this capability off the account? Its role is not touched, so anything the role grants it keeps. Granting the capability again puts this back. Confirm first that no plugin depends on it.', 'wpaqs' ) ); ?>' );">
					<input type="hidden" name="action" value="wpaqs_remove_capability" />
					<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $user_id ); ?>" />
					<input type="hidden" name="cap" value="<?php echo esc_attr( $cap ); ?>" />
					<?php wp_nonce_field( WPAQS_NONCE . '-remove-cap-' . $user_id . '-' . $cap ); ?>
					<button type="submit" class="button button-small">
						<?php
						printf(
							/* translators: %s: capability name. */
							esc_html__( 'Remove %s', 'wpaqs' ),
							esc_html( $cap )
						);
						?>
					</button>
				</form>
			<?php endforeach; ?>
		</p>
		<?php
	}

	/**
	 * What changed, newest first.
	 *
	 * Somebody who came here because the site is behaving oddly is asking what is different
	 * rather than who has access, and no single line answers that. The ordering does: a session
	 * from an unfamiliar address, an application password created twenty minutes later, and
	 * that password used from somewhere else is an account takeover with its steps in order,
	 * where each line alone is thin.
	 *
	 * @param array $timeline Result of WPAQS_Timeline::build().
	 * @return void
	 */
	private static function render_timeline( array $timeline ) {
		?>
		<details class="wpaqs-card wpaqs-collapsible" id="wpaqs-timeline" open>
			<summary>
				<h2><?php esc_html_e( 'What changed', 'wpaqs' ); ?></h2>
				<span class="description">
					<?php
					printf(
						/* translators: 1: number of events, 2: number of days. */
						esc_html( _n( '%1$s event in the last %2$s days', '%1$s events in the last %2$s days', count( $timeline['entries'] ), 'wpaqs' ) ),
						esc_html( number_format_i18n( count( $timeline['entries'] ) ) ),
						esc_html( number_format_i18n( WPAQS_RECENT_DAYS ) )
					);
					?>
				</span>
			</summary>

			<p class="description">
				<?php esc_html_e( 'Everything access-related that WordPress dates, in order. No single line here means much on its own — a sign-in followed by an application password being created and then used from somewhere else is the shape worth reading.', 'wpaqs' ); ?>
			</p>

			<?php if ( $timeline['capped'] ) : ?>
				<div class="notice notice-warning inline">
					<p>
						<?php
						printf(
							/* translators: 1: entries shown, 2: entries in the window. */
							esc_html__( 'Showing the %1$s most recent of %2$s. The rest are older, not hidden — but this list is not the whole window.', 'wpaqs' ),
							esc_html( number_format_i18n( WPAQS_Timeline::MAX_ENTRIES ) ),
							esc_html( number_format_i18n( $timeline['total'] ) )
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( empty( $timeline['entries'] ) ) : ?>
				<p>
					<?php
					printf(
						/* translators: %s: number of days. */
						esc_html__( 'Nothing WordPress dates has happened in the last %s days: no account created, no session opened, no application password made or used, no password reset asked for. On a site with people working on it that is itself worth a thought.', 'wpaqs' ),
						esc_html( number_format_i18n( WPAQS_RECENT_DAYS ) )
					);
					?>
				</p>
			<?php else : ?>
				<ul class="wpaqs-timeline">
					<?php foreach ( $timeline['entries'] as $entry ) : ?>
						<li class="wpaqs-event wpaqs-event-<?php echo esc_attr( $entry['kind'] ); ?>">
							<span class="wpaqs-event-when">
								<?php echo esc_html( WPAQS_App_Passwords::stamp( $entry['at'] ) ); ?>
							</span>
							<span class="wpaqs-event-what">
								<?php echo esc_html( $entry['label'] ); ?>
								—
								<strong><?php echo esc_html( $entry['login'] ); ?></strong>
								<?php if ( '' !== $entry['detail'] ) : ?>
									<span class="description"><?php echo esc_html( $entry['detail'] ); ?></span>
								<?php endif; ?>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
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
		$sort    = WPAQS_Sort::requested( 'code', array( 'login' ) );
		$holders = WPAQS_Sort::apply(
			$holders,
			$sort['dir'],
			function ( $holder ) {
				return $holder['account']['login'];
			}
		);
		?>
		<?php // Closed by default. On a healthy site this list is the same every visit, and the
			// count in the summary is the part that changes — a number that moved is the reason
			// to open it. ?>
		<details class="wpaqs-card wpaqs-collapsible" id="wpaqs-code"<?php echo WPAQS_Sort::is_active( 'code' ) ? ' open' : ''; ?>>
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
							<?php self::sortable( 'code', 'login', __( 'Account', 'wpaqs' ), $sort, 'wpaqs-code' ); ?>
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

		// Newest first by default: a key issued five minutes ago is the one worth looking at.
		// Sorted on the timestamp rather than the rendered date, so "never" lands where a zero
		// belongs instead of wherever the word falls in the alphabet.
		$sort = WPAQS_Sort::requested( 'passwords', array( 'created', 'last_used', 'name', 'account' ) );

		if ( ! WPAQS_Sort::is_active( 'passwords' ) ) {
			$sort['dir'] = 'desc';
		}

		$rows = WPAQS_Sort::apply(
			$rows,
			$sort['dir'],
			function ( $row ) use ( $sort ) {
				switch ( $sort['key'] ) {
					case 'last_used':
						return (int) $row['password']['last_used'];
					case 'name':
						return '' === $row['password']['name'] ? $row['password']['uuid'] : $row['password']['name'];
					case 'account':
						return $row['account']['login'];
				}

				return (int) $row['password']['created'];
			}
		);
		?>
		<details class="wpaqs-card wpaqs-collapsible" id="wpaqs-passwords" open>
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
							<?php self::sortable( 'passwords', 'name', __( 'Name', 'wpaqs' ), $sort, 'wpaqs-passwords' ); ?>
							<?php self::sortable( 'passwords', 'account', __( 'Account', 'wpaqs' ), $sort, 'wpaqs-passwords' ); ?>
							<?php self::sortable( 'passwords', 'created', __( 'Created', 'wpaqs' ), $sort, 'wpaqs-passwords' ); ?>
							<?php self::sortable( 'passwords', 'last_used', __( 'Last used', 'wpaqs' ), $sort, 'wpaqs-passwords' ); ?>
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
	 * One sortable column header.
	 *
	 * @param string $table   Table identifier.
	 * @param string $key     Column key.
	 * @param string $label   Visible label.
	 * @param array  $current Result of WPAQS_Sort::requested().
	 * @param string $anchor  Element id the link returns to.
	 * @return void
	 */
	private static function sortable( $table, $key, $label, array $current, $anchor ) {
		$indicator = WPAQS_Sort::indicator( $key, $current );
		?>
		<th scope="col" class="wpaqs-sortable <?php echo esc_attr( '' === $indicator ? '' : 'wpaqs-sorted-' . $indicator ); ?>">
			<a href="<?php echo esc_url( WPAQS_Sort::url( $table, $key, $current, $anchor ) ); ?>">
				<?php echo esc_html( $label ); ?>
				<?php if ( '' !== $indicator ) : ?>
					<span class="wpaqs-arrow" aria-hidden="true"><?php echo 'asc' === $indicator ? '&uarr;' : '&darr;'; ?></span>
					<span class="screen-reader-text">
						<?php echo esc_html( 'asc' === $indicator ? __( 'sorted ascending', 'wpaqs' ) : __( 'sorted descending', 'wpaqs' ) ); ?>
					</span>
				<?php endif; ?>
			</a>
		</th>
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
