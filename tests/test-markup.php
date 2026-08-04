<?php
/**
 * Shape of the screen.
 *
 * The sibling plugin's history is that most of its bugs were presentation rather than
 * detection, and that the UI carried almost no assertions while detection carried
 * hundreds. These checks read the template for the shapes that have actually gone wrong
 * there: nested forms, an action with no confirmation, and a promise the code does not
 * keep.
 */

define( 'WPAQS_TEST_MARKUP', true );

$page       = dirname( __DIR__ ) . '/wordpress-access-quick-scan/includes/class-admin-page.php';
$controller = dirname( __DIR__ ) . '/wordpress-access-quick-scan/includes/class-controller.php';

$GLOBALS['wpaqs_failures'] = 0;

/**
 * Assert, and print the result either way.
 *
 * @param string $label  What is being checked.
 * @param bool   $ok     Whether it holds.
 * @param string $detail Shown after the label.
 * @return void
 */
function check( $label, $ok, $detail = '' ) {
	if ( ! $ok ) {
		$GLOBALS['wpaqs_failures']++;
	}

	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, '' === $detail ? '' : ' — ' . $detail );
}

/**
 * The template with its comments removed.
 *
 * Assertions about what the code does *not* do must not read the prose that explains why
 * it does not do it. In the sibling plugin that mistake was made three times in one
 * session, each time failing on the comment written to prevent the fault.
 *
 * @param string $source PHP source.
 * @return string
 */
function code_only( $source ) {
	$code = '';

	foreach ( token_get_all( $source ) as $token ) {
		if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}

		$code .= is_array( $token ) ? $token[1] : $token;
	}

	return $code;
}

/**
 * The span of a form, from its opening tag to its own close.
 *
 * @param string $source Template source.
 * @param string $needle Something inside the form.
 * @return string
 */
function form_body( $source, $needle ) {
	$at = strpos( $source, $needle );

	if ( false === $at ) {
		return '';
	}

	$open  = strrpos( substr( $source, 0, $at ), '<form' );
	$close = strpos( $source, '</form>', $at );

	if ( false === $open || false === $close ) {
		return '';
	}

	return substr( $source, $open, $close - $open );
}

/**
 * The source of one method, from its signature to the next docblock.
 *
 * Enough to answer where a block of markup lives, which is the question behind a control
 * installed inside a branch that does not always run.
 *
 * @param string $source Template source.
 * @param string $name   Method name.
 * @return string
 */
function method_body( $source, $name ) {
	$at = strpos( $source, 'function ' . $name . '(' );

	if ( false === $at ) {
		return '';
	}

	$next = strpos( $source, "\n\t/**", $at );

	return false === $next ? substr( $source, $at ) : substr( $source, $at, $next - $at );
}

$source          = file_get_contents( $page );
$styles          = file_get_contents( dirname( __DIR__ ) . '/wordpress-access-quick-scan/assets/access.css' );
$controller_code = code_only( file_get_contents( $controller ) );
$page_code       = code_only( $source );

check( 'the admin page is readable', '' !== $source );

// The screen states its own version. "The sections do not fold" and "you are running an
// older zip" look the same over a screenshot, and only one of them is a bug.
check(
	'the screen shows which version rendered it',
	false !== strpos( $source, 'esc_html( WPAQS_VERSION )' ),
	'a version on screen turns a guess into a glance'
);

// A form that opens and never closes swallows whatever follows it.
$opens  = preg_match_all( '~<form[\s>]~', $source );
$closes = preg_match_all( '~</form>~', $source );

check( 'every form is closed', $opens === $closes, $opens . ' opened, ' . $closes . ' closed' );

// Both controls sit in the same table row. Nested, the browser terminates the outer one at
// the inner — the fault that once left the sibling's bulk trash action receiving one post.
foreach ( array( 'wpaqs_end_sessions', 'wpaqs_revoke_password', 'wpaqs_end_session', 'wpaqs_remove_capability', 'wpaqs_park_default_role', 'wpaqs_close_registration' ) as $action ) {
	$body = form_body( $source, $action );

	check( 'the ' . $action . ' form exists', '' !== $body );

	check(
		'and it holds no other form',
		'' !== $body && 1 === preg_match_all( '~<form[\s>]~', $body ),
		'a form inside a form is terminated at the inner one'
	);

	check(
		'and it confirms before it acts',
		'' !== $body && false !== strpos( $body, 'onsubmit="return confirm(' ),
		'a change the operator did not agree to'
	);

	check(
		'and it carries a nonce',
		'' !== $body && false !== strpos( $body, 'wp_nonce_field' )
	);

	check(
		'and the endpoint is registered',
		false !== strpos( $controller_code, 'admin_post_' . $action ),
		'the form would post into nothing'
	);
}

// Every finding that can be cleared from here carries its control. A rule with no way to
// resolve it is noise however true it is, and the two that cannot be cleared say what to do
// instead rather than carrying a button.
check(
	'the registration finding carries its fix',
	false !== strpos( $source, "'open_registration_privileged_role' === \$finding['rule']" ),
	'the only critical rule had no button at all'
);

check(
	'the capability finding carries its fix',
	false !== strpos( $source, "'capability_outside_role' === \$finding['rule']" )
);

// Each confirmation names what survives it, because the mistake an operator makes is
// assuming one action covers more than it does.
check(
	'parking the default role says existing accounts keep their role',
	false !== strpos( form_body( $source, 'wpaqs_park_default_role' ), 'keep the role they have' )
);

check(
	'closing registration warns a membership site',
	false !== strpos( form_body( $source, 'wpaqs_close_registration' ), 'sells memberships' ),
	'closing registration on a site that sells memberships breaks signup'
);

check(
	'removing a capability says the role is untouched',
	false !== strpos( form_body( $source, 'wpaqs_remove_capability' ), 'role is not touched' )
);

check(
	'ending one session says the others stay open',
	false !== strpos( form_body( $source, 'wpaqs_end_session' ), 'stays open' )
);

// A single session can only be ended where WordPress keeps sessions in user meta. Offering
// the button on a site with a custom manager would appear to work and change nothing. The
// gate moved into WPAQS_Sessions::controls(), where test-sessions.php counts what it returns
// instead of reading a condition — so this asserts the gate is inside that one decision
// rather than in the template, and nothing here reintroduces a second copy.
check(
	'the single-session control is gated on the storage being the usual one',
	false !== strpos( code_only( file_get_contents( dirname( __DIR__ ) . '/wordpress-access-quick-scan/includes/class-sessions.php' ) ), 'self::can_end_one()' ),
	'a custom session manager keeps them somewhere this cannot write'
);

// One is reversible and one is not, and each confirmation has to say which.
check(
	'ending sessions says the account can sign in again',
	false !== strpos( form_body( $source, 'wpaqs_end_sessions' ), 'sign in again' ),
	'otherwise it reads as a lockout'
);

check(
	'revoking says it cannot be undone',
	false !== strpos( form_body( $source, 'wpaqs_revoke_password' ), 'cannot be undone' ),
	'the secret is deleted, not hidden'
);

// The two actions do different things, and an operator who thinks one covers the other
// leaves a key working. Each confirmation says what it does not touch.
check(
	'ending sessions says application passwords keep working',
	false !== strpos( form_body( $source, 'wpaqs_end_sessions' ), 'Application passwords keep working' )
);

check(
	'revoking says browser sessions are unaffected',
	false !== strpos( form_body( $source, 'wpaqs_revoke_password' ), 'sessions are unaffected' )
);

// "Not checked" is a distinct verdict from "clear", and a findings list cannot express the
// difference. All four statements are rendered, not summarised away.
foreach ( array( 'Failed logins.', 'Login history.', 'Whether an address is suspicious.', 'Files, WordPress core' ) as $statement ) {
	check(
		'the screen states what it cannot check: ' . $statement,
		false !== strpos( $source, $statement )
	);
}

// An empty findings list must not read as a clean bill of health.
check(
	'an empty result points at the coverage list',
	false !== strpos( $source, 'That is not the same as the site being fine' ),
	'nothing found is not the same as nothing there'
);

// A cap that truncates quietly turns "nothing found" into a sentence that sounds complete.
check(
	'the account cap is disclosed when reached',
	false !== strpos( $source, 'were not looked at' ),
	'the sibling shipped a silent cap and had to go back and fix it'
);

// The notice is rendered before the tables: a result that appears below the content it
// refers to is one nobody connects to the press.
$render      = strpos( $source, 'self::render_notice()' );
$findings_at = strpos( $source, 'self::render_findings(' );

check(
	'the action notice renders before the findings',
	false !== $render && false !== $findings_at && $render < $findings_at
);

// Nothing on this screen deletes an account or its content.
check(
	'the screen never calls a user delete',
	false === strpos( $page_code, 'wp_delete_user' )
);

// Evidence is somebody else's text — a login, an email, a user agent. It is escaped on the
// one path that renders it.
check(
	'evidence is escaped at render',
	false !== strpos( $source, "esc_html( \$entry['evidence'] )" ),
	'user agents are attacker-controlled strings'
);

// Every echo of an evidence or detail string goes through esc_html. A raw one would put
// a user agent — somebody else's text — into the page unescaped.
check(
	'no evidence or detail is echoed raw',
	0 === preg_match( '~echo \\$(entry|group|finding)\\[.(evidence|detail|title|recommendation).\\]~', $source ),
	'escaping happens at render, on the one path that renders'
);

// Repeated findings are one card. Five identical ones is how the fifth gets missed.
check(
	'findings are grouped before rendering',
	false !== strpos( $source, 'WPAQS_Findings::group(' ),
	'five cards with one paragraph repeated is the fault this replaced'
);

check(
	'a grouped card says how many it holds',
	false !== strpos( $source, 'wpaqs-count' )
);

// The findings section folds, and so does each card inside it. One fold per card: an inner
// toggle around the entries would add a click to the intent of opening the card.
check(
	'the findings section is a collapsible',
	false !== strpos( substr( $source, 0, strpos( $source, "esc_html_e( 'What stood out'" ) ), 'wpaqs-collapsible' ),
	'the section that answers the question has to fold too'
);

check(
	'and it is open by default',
	false !== strpos( substr( $source, 0, strpos( $source, "esc_html_e( 'What stood out'" ) ), 'wpaqs-collapsible" open' ),
	'the answer should not need a click'
);

check(
	'a closed findings section still says what it holds',
	false !== strpos( $source, "'%1\$s finding in %2\$s group'" ),
	'a fold that hides the count hides whether anything was found at all'
);

$group_body = method_body( $source, 'render_group' );

check(
	'each card is a collapsible of its own',
	'' !== $group_body && false !== strpos( $group_body, '<details class="wpaqs-finding wpaqs-collapsible' )
);

check(
	'its header is the click target',
	'' !== $group_body && false !== strpos( $group_body, '<summary class="wpaqs-finding-head">' ),
	'the badge, title and count stay visible when it is shut'
);

check(
	'a card with many entries starts closed',
	'' !== $group_body && false !== strpos( $group_body, 'WPAQS_Findings::GROUP_COLLAPSE' ),
	'opening on a wall of entries is the thing the threshold exists for'
);

check(
	'and there is no second toggle inside it',
	'' !== $group_body && 1 === preg_match_all( '~<details~', $group_body ),
	'one fold per card: you open a card to see its entries'
);

// The card summary is flexed too, so it needs its own drawn arrow.
check(
	'the card summary draws an arrow',
	false !== strpos( $styles, 'summary.wpaqs-finding-head::before' )
);

check(
	'and it turns when the card is open',
	false !== strpos( $styles, 'details.wpaqs-finding[open] > summary.wpaqs-finding-head::before' )
);

// The entry's own remainder prints unconditionally. Gating it on the header having shared
// nothing is how the sibling made a date vanish from every grouped card.
$entries = method_body( $source, 'render_entries' );

check(
	'the entry detail is not gated on the shared header',
	'' !== $entries && false === strpos( $entries, "'' === \$group['detail']" ),
	'the moment sharing works, a gated remainder disappears'
);

// Who can run code is its own section: the Users screen shows roles, and a role is neither
// the whole story nor obviously mapped to "can put code on this site".
check(
	'the code-capability section exists',
	false !== strpos( $source, "esc_html_e( 'Who can run code'" ),
	'the blast radius deserves one view'
);

check(
	'and it says what the list means',
	false !== strpos( $source, 'blast radius' )
);

// If the editors are reachable, every account on that list can run code without uploading
// anything — which changes what the list means, so the screen says so where the list is.
check(
	'the file-editor posture is stated beside the list',
	false !== strpos( $source, 'DISALLOW_FILE_EDIT' ),
	'the constant is the fix, so name it'
);

// Somebody whose site is behaving oddly is asking what changed, not who has access. The
// tables answer the second question; only the ordering answers the first.
check(
	'the screen has a timeline',
	false !== strpos( $source, "esc_html_e( 'What changed'" )
);

check(
	'and it says no single line means much alone',
	false !== strpos( $source, 'No single line here means much on its own' ),
	'the value is in the sequence, and a reader has to be told that'
);

check(
	'the timeline renders before the tables',
	strpos( $source, 'self::render_timeline(' ) < strpos( $source, 'self::render_code_holders(' )
);

// A truncated list that looks complete is worse than a short one.
check(
	'the timeline discloses its cap',
	false !== strpos( $source, 'not the whole window' )
);

// An empty timeline on a site with people working on it is itself information.
check(
	'and says something useful when it is empty',
	false !== strpos( $source, 'is itself worth a thought' )
);

// Knowing which session is yours matters before pressing anything that ends one.
check(
	'your own session is marked',
	false !== strpos( $source, "esc_html_e( 'this is you'" )
		&& false !== strpos( $source, 'WPAQS_Sessions::current_verifier()' ),
	'the only other clue is recognising your own address'
);

// Sorting is server-side: links in the headers, a usort in PHP. The sibling sorts in
// JavaScript and paid for it twice — column indices shifted by one because the script read
// `thead th` while the body row starts with a `td`, and sorting installed behind an early
// return that fired on any table shorter than a page. Neither bug exists without a script.
check(
	'this plugin ships no JavaScript at all',
	array() === glob( dirname( __DIR__ ) . '/wordpress-access-quick-scan/assets/*.js' ),
	'a client-side sort is where the sibling lost two days'
);

check(
	'sorting goes through one helper',
	false !== strpos( $source, 'WPAQS_Sort::requested(' ) && false !== strpos( $source, 'WPAQS_Sort::apply(' ),
	'three tables with three copies of the logic is three places to get it wrong'
);

check(
	'and every sortable header is drawn by one method',
	false !== strpos( $source, 'private static function sortable(' )
);

// Every header count must match the cells its row renders. A header added without its cell
// is the shape that shifted the sibling's columns.
$accounts_body = method_body( $source, 'render_accounts' );

check(
	'the accounts table headers and cells agree',
	4 === substr_count( $accounts_body, '<th scope="col">' ) + substr_count( $accounts_body, "self::sortable( 'accounts'" )
		&& 4 === substr_count( $accounts_body, '<td>' ),
	'a header with no cell shifts every column after it'
);

// A sort reloads the page, so a section closed by default has to open when its own table is
// the one sorting — otherwise pressing a header looks like it did nothing.
check(
	'a closed section opens when its table is sorted',
	false !== strpos( $source, "WPAQS_Sort::is_active( 'code' ) ? ' open' : ''" ),
	'landing back on a shut section reads as nothing having happened'
);

check(
	'and each sort link returns to its own section',
	false !== strpos( $source, "'wpaqs-passwords' )" ) && false !== strpos( $source, "'wpaqs-accounts' )" ),
	'a reload that lands at the top of the page loses the reader'
);

// Collapsible sections. Each one is a details, each summary holds its h2 so the heading
// stays the click target, and every details closes.
check(
	'every details element closes',
	preg_match_all( '~<details~', $source ) === preg_match_all( '~</details>~', $source ),
	'an unclosed details swallows the rest of the page'
);

// display:flex on a summary removes the browser's own disclosure marker, so one has to be
// drawn — the sibling shipped three collapsible sections with no arrow at all.
$flexed = (bool) preg_match( '~summary \{[^}]*display:\s*flex~s', $styles );
$drawn  = false !== strpos( $styles, 'summary::before' );

check( 'a flexed summary draws its own arrow', ! $flexed || $drawn, 'display:flex hides the native marker' );
check( 'and the arrow turns when open', false !== strpos( $styles, '[open] > summary::before' ) );

// Which sections fold, and which way up. The coverage list must not be open by default —
// it is reference material — and it must not be removable either, so it is still a section.
$accounts_card = strpos( $source, "esc_html_e( 'Who has access'" );
$coverage_card = strpos( $source, "esc_html_e( 'What this does not check'" );
// Anchored on the count in its summary, not on the heading: the heading also appears in the
// one-line fallback rendered when this WordPress has no application passwords, and strpos
// finds that one first. Same shape as a first-occurrence match that reads the wrong branch.
$passwords_card = strpos( $source, "'%s active', '%s active'" );

foreach ( array( 'Who has access' => $accounts_card, 'What this does not check' => $coverage_card, 'Application passwords' => $passwords_card ) as $name => $at ) {
	check( 'the ' . $name . ' section exists', false !== $at );

	if ( false === $at ) {
		continue;
	}

	$before = substr( $source, max( 0, $at - 500 ), 500 );

	check( 'and it is a collapsible card', false !== strpos( $before, 'wpaqs-collapsible' ) );
	check( 'and its heading is the click target', false !== strpos( $before, '<summary>' ) );
}

// The inventory opens; the reference list does not. An operator should not have to hunt for
// the answer, and should not have to scroll past four paragraphs of caveats to reach it.
check(
	'the coverage list is closed by default',
	false === strpos( substr( $source, max( 0, $coverage_card - 500 ), 500 ), 'wpaqs-collapsible" open' ),
	'reference material open by default is what buried the findings in the sibling'
);

// Application passwords have their own section rather than a column, and exactly one Revoke
// control on the screen: two buttons for one password is two chances to be surprised.
check(
	'there is exactly one revoke control',
	1 === substr_count( $source, 'value="wpaqs_revoke_password"' ),
	'the same password had a button in two places'
);

check(
	'the passwords section says why revoking is the only thing that stops one',
	false !== strpos( $source, 'Revoking is the only thing that stops one' ),
	'a password survives a password change and every session being ended'
);

check(
	'and it flags a key that was never used',
	false !== strpos( $source, "esc_html_e( 'never used'" )
);

// Which controls a row carries is decided in WPAQS_Sessions::controls() and counted in
// test-sessions.php. What the template must not do is decide it again here — two copies of
// this rule is how a single session ended up with two buttons and then with none.
check(
	'the template asks for the controls rather than working them out',
	false !== strpos( $page_code, "WPAQS_Sessions::controls( count( \$rows_open ) )" )
	&& false !== strpos( $page_code, "\$controls['per_session']" )
	&& false !== strpos( $page_code, "\$controls['bulk']" ),
	'two copies of the rule is how one session ended up with two buttons and then with none'
);

check(
	'and does not re-derive it from can_end_one',
	false === strpos( $page_code, 'WPAQS_Sessions::can_end_one()' ),
	'that is the condition controls() already folds in'
);

check(
	'the count is in the label rather than the reader counting rows',
	false !== strpos( $source, "_n( 'End this session', 'End all %s sessions'" )
);

// An expired session must be visibly expired. WordPress prunes expired tokens only when it
// next writes the meta, so an account that stopped signing in keeps them — and without the
// chip a sign-in from two years ago reads as an open session on a screen headed live ones.
check(
	'an expired session is marked',
	false !== strpos( $source, "esc_html_e( 'expired'" ),
	'a two-year-old login otherwise reads as open'
);

check(
	'and carries no button to end it',
	false !== strpos( $page_code, 'empty( $session[\'expired\'] )' ),
	'ending it would be a press that changes nothing anybody can see'
);

// Saying only "none open" would throw away the addresses and dates, which are the only login
// history WordPress keeps.
check(
	'an account whose every session has lapsed still shows them',
	false !== strpos( $page_code, 'empty( $rows_open )' )
	&& false !== strpos( $source, 'only login history WordPress keeps' ),
	'the note goes above the list rather than replacing it'
);

// One list, whichever branch drew it: two would leave one unclosed.
check(
	'the session list is opened exactly once',
	1 === substr_count( $source, 'ul class="wpaqs-sessions"' ),
	'two opening tags in two branches is one unclosed list'
);

// The view control, and the two things a filter must not do: hide rows without saying how
// many, and let an empty filtered table read as an empty site.
check(
	'the account table has a view control',
	false !== strpos( $source, "WPAQS_Filter::url( 'accounts'" )
);

check(
	'and it says how many rows it hid',
	false !== strpos( $source, 'accounts with no open session are hidden' ),
	'two rows where there were 140 otherwise reads as 138 accounts gone'
);

check(
	'and an empty filtered table does not read as an empty site',
	false !== strpos( $source, 'That is the normal state of a site nobody is working on' ),
	'"nothing here" has to mean that rather than "nothing in this view"'
);

// The cap notice counts what the screen read, which the filter does not change. Off the
// filtered list it would say the screen read two of the site's 141 newest.
check(
	'the cap notice is not counted off the filtered list',
	false !== strpos( $page_code, '$read             = count( $accounts[\'rows\'] );' )
	&& false !== strpos( $page_code, 'number_format_i18n( $read )' ),
	'it would otherwise say the screen read two of the site\'s 141 newest'
);

// Every link on this screen goes through WPAQS_Screen, which is what stops one control
// dropping another's argument.
check(
	'no link to this screen is built by hand',
	false === strpos( $page_code, "admin_url( 'tools.php?page=' . WPAQS_SLUG )" ),
	'a link built from scratch drops whatever else is in the address'
);

printf( "\n%d failure(s)\n", $GLOBALS['wpaqs_failures'] );
exit( $GLOBALS['wpaqs_failures'] > 0 ? 1 : 0 );
