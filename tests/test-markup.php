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
$controller_code = code_only( file_get_contents( $controller ) );
$page_code       = code_only( $source );

check( 'the admin page is readable', '' !== $source );

// A form that opens and never closes swallows whatever follows it.
$opens  = preg_match_all( '~<form[\s>]~', $source );
$closes = preg_match_all( '~</form>~', $source );

check( 'every form is closed', $opens === $closes, $opens . ' opened, ' . $closes . ' closed' );

// Both controls sit in the same table row. Nested, the browser terminates the outer one at
// the inner — the fault that once left the sibling's bulk trash action receiving one post.
foreach ( array( 'wpaqs_end_sessions', 'wpaqs_revoke_password' ) as $action ) {
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

// The entry's own remainder prints unconditionally. Gating it on the header having shared
// nothing is how the sibling made a date vanish from every grouped card.
$entries = method_body( $source, 'render_entries' );

check(
	'the entry detail is not gated on the shared header',
	'' !== $entries && false === strpos( $entries, "'' === \$group['detail']" ),
	'the moment sharing works, a gated remainder disappears'
);

printf( "\n%d failure(s)\n", $GLOBALS['wpaqs_failures'] );
exit( $GLOBALS['wpaqs_failures'] > 0 ? 1 : 0 );
