<?php
/**
 * Grouping repeated findings into one card.
 *
 * The sibling plugin learned this twice. First that five cards repeating one paragraph is
 * how the fifth finding gets missed. Then that grouping them **did not remove the
 * repetition, it moved it inside one card**, because the first version compared whole
 * detail strings and details that differ only in their tail share nothing.
 *
 * So the assertions here are in two halves: nothing is lost or reordered on the way to the
 * screen, and the shared prefix is actually shared — cut at a sentence boundary rather than
 * at the last matching character.
 */

require __DIR__ . '/bootstrap.php';

load_class( 'findings' );

/**
 * A finding with a chosen detail, without going through the catalog.
 *
 * @param string $rule     Rule slug.
 * @param string $severity Severity.
 * @param string $target   Target.
 * @param string $detail   Detail sentence or sentences.
 * @param string $evidence Evidence line.
 * @param string $rec      Recommendation.
 * @return array
 */
function finding( $rule, $severity, $target, $detail, $evidence = '', $rec = 'Do the thing.' ) {
	return array(
		'rule'           => $rule,
		'severity'       => $severity,
		'title'          => 'Title of ' . $rule,
		'detail'         => $detail,
		'recommendation' => $rec,
		'target'         => $target,
		'evidence'       => $evidence,
	);
}

// ------------------------------------------------- nothing lost, nothing reordered

$findings = array(
	finding( 'app_password_foreign_ip', 'high', 'user:1:a', 'Same sentence.', 'login=one' ),
	finding( 'app_password_foreign_ip', 'high', 'user:1:b', 'Same sentence.', 'login=two' ),
	finding( 'app_password_foreign_ip', 'high', 'user:2:c', 'Same sentence.', 'login=three' ),
	finding( 'non_browser_session', 'high', 'user:3:session', 'Different sentence.', 'ua=curl' ),
	finding( 'recent_administrator', 'info', 'user:4', 'Third sentence.', 'login=four' ),
);

$groups = WPAQS_Findings::group( $findings );

check( 'one group per rule and severity', 3 === count( $groups ), (string) count( $groups ) );

$total   = 0;
$targets = array();

foreach ( $groups as $group ) {
	$total += count( $group['entries'] );

	foreach ( $group['entries'] as $entry ) {
		$targets[] = $entry['target'];
	}
}

check( 'every finding survives grouping', 5 === $total, (string) $total );
check( 'no finding is duplicated', 5 === count( array_unique( $targets ) ) );
check(
	'and the order inside a group is the order they arrived',
	array( 'user:1:a', 'user:1:b', 'user:2:c' ) === array_slice( $targets, 0, 3 ),
	implode( ',', $targets )
);

check( 'the biggest group holds three', 3 === count( $groups[0]['entries'] ) );
check( 'and carries the rule', 'app_password_foreign_ip' === $groups[0]['rule'] );
check( 'and the severity the badge will show', 'high' === $groups[0]['severity'] );

// Two rules at the same severity are two groups, not one.
check( 'same severity does not merge two rules', 'non_browser_session' === $groups[1]['rule'] );

// ------------------------------------------------------- rule and severity, not rule

// A rule that emits two severities gets two cards. A single card would have to lie about
// one of them in its badge.
$split = WPAQS_Findings::group(
	array(
		finding( 'mixed', 'high', 'a', 'Sentence.' ),
		finding( 'mixed', 'medium', 'b', 'Sentence.' ),
	)
);

check( 'a rule with two severities becomes two groups', 2 === count( $split ), (string) count( $split ) );

// ------------------------------------------------------------- the shared paragraph

check(
	'wording every entry repeats moves into the header',
	'Same sentence.' === $groups[0]['detail'],
	$groups[0]['detail']
);

foreach ( $groups[0]['entries'] as $entry ) {
	check( 'and the entry is left with nothing to repeat', '' === $entry['detail'], $entry['detail'] );
}

// This is the case the sibling got wrong: details that agree on a prefix and differ in the
// tail. Comparing whole strings shares nothing and the paragraph prints once per entry.
$tails = WPAQS_Findings::group(
	array(
		finding( 'capability_outside_role', 'high', 'user:1', 'Shared opening sentence. Granted directly: edit_users.' ),
		finding( 'capability_outside_role', 'high', 'user:2', 'Shared opening sentence. Granted directly: install_plugins.' ),
	)
);

check(
	'a common prefix is shared even when the tails differ',
	'Shared opening sentence.' === $tails[0]['detail'],
	$tails[0]['detail']
);

check(
	'and each entry keeps only its own tail',
	'Granted directly: edit_users.' === $tails[0]['entries'][0]['detail'],
	$tails[0]['entries'][0]['detail']
);

// The cut is at a sentence boundary. "Granted directly: edit_" over one entry and "users."
// over the next would be worse than the repetition it replaced.
$mid = WPAQS_Findings::group(
	array(
		finding( 'r', 'high', 'a', 'One sentence. Granted: edit_users.' ),
		finding( 'r', 'high', 'b', 'One sentence. Granted: edit_pages.' ),
	)
);

check(
	'the shared text ends at a sentence, not mid-word',
	'One sentence.' === $mid[0]['detail'],
	$mid[0]['detail']
);

// Nothing in common at all: share nothing rather than a fragment.
$nothing = WPAQS_Findings::group(
	array(
		finding( 'r', 'high', 'a', 'Alpha beta.' ),
		finding( 'r', 'high', 'b', 'Gamma delta.' ),
	)
);

check( 'details with no common opening share nothing', '' === $nothing[0]['detail'] );
check( 'and both entries keep their whole detail', 'Alpha beta.' === $nothing[0]['entries'][0]['detail'] );

// A single-entry group is left alone: it renders as one card and needs its whole sentence.
$lone = WPAQS_Findings::group( array( finding( 'r', 'high', 'a', 'The only sentence.' ) ) );

check( 'a group of one shares nothing', '' === $lone[0]['detail'] );
check( 'and keeps its detail intact', 'The only sentence.' === $lone[0]['entries'][0]['detail'] );

// ------------------------------------------------------------- shared recommendation

check( 'a recommendation every entry agrees on moves into the header', 'Do the thing.' === $groups[0]['recommendation'] );

$differing = WPAQS_Findings::group(
	array(
		finding( 'r', 'high', 'a', 'Sentence.', '', 'Do this.' ),
		finding( 'r', 'high', 'b', 'Sentence.', '', 'Do that.' ),
	)
);

check(
	'one they disagree on is not shared',
	'' === $differing[0]['recommendation'],
	'assuming they match would drop one of them silently'
);

check( 'and each entry keeps its own', 'Do this.' === $differing[0]['entries'][0]['recommendation'] );

// -------------------------------------------------------------------- the threshold

check( 'the collapse threshold is a constant, not a literal', 6 === WPAQS_Findings::GROUP_COLLAPSE );
check( 'and grouping starts at two', 2 === WPAQS_Findings::GROUP_MIN );

finish();
