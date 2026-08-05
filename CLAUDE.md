# CLAUDE.md

Guidance for Claude Code working in this repository.

## What this is

A WordPress plugin that answers one question: **who has access to this site right now, and
does any of it look wrong.** It lists every account with the capabilities it actually
holds, every live session with its IP and user agent, and every application password with
when and where it was last used.

It is the sibling of `wordpress-malware-quick-scan`, which answers a different question —
whether the files and the database show signs of compromise. Both were built for the same
two real incidents: a hosting provider's malware notice, and a site that kept publishing
spam after being cleaned. The confirmed vector in the second was a **direct database
write**, which is why rotating credentials did not close it, and why this plugin exists to
show what access is actually granted rather than to log what WordPress notices.

```text
wordpress-access-quick-scan/   the plugin — this is what gets zipped
tests/                         CLI harnesses, no WordPress install needed
build.sh                       lint + 7.4 gate + tests + zip
CHANGELOG.md                   what changed and which false positive drove it
dist/                          build output, not committed
```

## Commands

```bash
./build.sh                     # gated build: any failure means no zip
./tests/run.sh                 # full suite
php tests/test-accounts.php    # one suite; run from the repo root
```

`build.sh` refuses to produce a zip if linting, the PHP 7.4 gate or the test suite fails.
Do not work around it.

## Git conventions

Same as the sibling, and for the same reasons.

**Both version strings move together.** The plugin header and `WPAQS_VERSION` in
`wordpress-access-quick-scan.php` are separate literals. In the sibling they drifted a patch
apart and a report recorded a version the release did not have.

**Branches are `type/kebab-case`**, the type matching the commit that lands there. Work
reaches `main` through a pull request.

**Commits are Conventional Commits** with types `feat`, `fix`, `chore` and `docs`. Lower
case subject, no trailing period, and it **names the symptom rather than the patch** —
`fix: a subscriber that could edit users read as an ordinary subscriber`, not `fix: update
accounts class`. The body carries the *why*.

**No trailers.** No `Co-Authored-By`, no generator lines, nothing naming the tool.

## Hard constraints

**Reading the screen changes nothing.** Every reader is read-only. No option is written, no
transient, no scheduled event — which is why `uninstall.php` removes nothing and says so.
The moment anything is stored, its name belongs in that file.

**Six deliberate exceptions**, every one pressed by a person, and every one there because a
rule needs it. **Before adding a rule, ask what clears it** — a true finding with no resolution
costs more attention than it returns, and that is what removed `content_predates_account`:

- **End an account's sessions.** Reversible by definition: the person signs in again.
  **Refuses the current user**, because ending your own sessions signs you out of the
  screen you are working from, mid-incident.
- **End one session**, leaving the rest open, so an administrator can close a scripted session
  without signing themselves out. `WP_Session_Tokens::destroy()` takes the raw token and only
  its hash is stored, so this writes the session meta the way core does — and is offered
  **only** when the default manager is in use. A site that replaces it through
  `session_token_manager` keeps sessions elsewhere, where the write would appear to work and
  change nothing.
- **Revoke an application password.** Not reversible — the secret is deleted. It ships
  because revoking is what actually stops REST authentication, and a mistake costs a broken
  integration rather than lost data. The confirmation says so before the press.
- **Take a directly granted capability off an account.** The role is untouched on purpose:
  removing what a role grants would be undone the moment WordPress read the role again, so that
  request is refused and the wording says where the grant comes from. Refuses your own account
  — you can strip your own `manage_options`.
- **Make new accounts Subscribers**, and **close registration**. Settings rather than
  deletions. On multisite, registration is a **network** option: `users_can_register` is not
  consulted there at all, so the rule reads `registration` from the site options and the close
  button is refused with a pointer at Network Settings.

**No delete, of an account or of anything it created.** `wp_delete_user()` reassigns or
destroys the account's posts, and those posts are the record of what it did. The sibling
made the same call for the same reason. `test-actions.php` asserts the class calls no user
delete, and asserts the file still explains why — a deliberate omission with no comment
reads as an oversight.

**Both actions validate against live state, not a stored report.** There is no report to
check a target against, and that is the stronger arrangement: an application password is
either on the account right now or it is not. The sibling shipped a button that offered to
unschedule a cron event already gone, because the row it rendered from was a snapshot.

**Both actions take `manage_network_options`-class capability on multisite** —
`manage_network_users`. Every subsite administrator holds `manage_options`, and a user is a
network-wide object.

**Escape at render, never at collection.** Logins, email addresses and user agents are all
supplied by somebody else. A user agent in particular is an attacker-controlled string.
They are escaped with `esc_html()` in `class-admin-page.php`, on the one path that renders
them.

**PHP 7.4 is the floor, and it is never executed here.** Local PHP is 8.5, so compatibility
rests on the grep gate in `build.sh`. Forbidden: `match`, enums, constructor promotion,
named arguments, `?->`, readonly, union types, `str_contains` / `str_starts_with` /
`str_ends_with`. Use `strpos` and `substr`.

## Architecture

**One screen, read live, no scan.** The sibling needs a resumable cursor because it walks a
filesystem. This reads four things out of the database — users, user meta, options,
application passwords — so there is no walker, no cursor, no budget, no AJAX loop, no
progress bar, no cron, no mailer and no stored report.

Four readers, each answering one question and testable on its own:

| Class | Reads |
|---|---|
| `WPAQS_Accounts` | Users, and the capabilities granted directly rather than through a role |
| `WPAQS_Sessions` | `session_tokens` meta: IP, user agent, login time, expiry |
| `WPAQS_App_Passwords` | `created`, `last_used`, `last_ip` per password |
| `WPAQS_Registration` | `users_can_register` and `default_role`, together |

`WPAQS_Screen`, `WPAQS_Sort` and `WPAQS_Filter` are the screen's controls. None of them reads the
database and none renders; `WPAQS_Screen` is the only thing that builds a URL.

`WPAQS_Timeline` reads nothing. It is handed what the readers already returned and orders it,
which is why `test-timeline.php` asserts the file contains no `get_users`, `get_user_meta`,
`$wpdb`, `get_posts` or `WP_Query`: a query in there would make opening the screen cost more
the longer the site has been running.

The screen renders five sections: the findings, the timeline, the account inventory, the
application passwords, and what it cannot check. All but the findings and the timeline fold
shut by default.

`WPAQS_Findings::catalog()` declares every rule's severity and wording once, so a severity
cannot drift from the sentence explaining it. Readers supply a target and an evidence
string and nothing else.

## Lessons, most of them inherited

**Four states that look identical.** A plugin row with no update could mean the check has not
run, it failed, it ran before the release was published, or the plugin is current.
`WPAQS_Updater::status()` names which, `status_text()` prints it, and the row offers a re-check
that clears **both** caches — this plugin's and WordPress's own `update_plugins`, refreshed twice
a day. Clearing one is a button that changes nothing anybody can see. Each failure names its own
cause and the suite asserts no two read the same.

**`uninstall.php` promised this plugin stored nothing, and then it stored something.** The note
ending "the moment anything is written, its name belongs here" was the only guard, and a note is
not a guard. `test-uninstall.php` discovers names from the source — including the `site_` call
variants, since the one stored name is a site transient — and asserts the deletion uses
`delete_site_transient`, that nothing outside the prefix is deleted, and that uninstalling
touches no account, session, application password or role.

**The updater is code delivery, and is written as if it were hostile.** It is the only place
either plugin hands WordPress a URL to download, unzip over a plugin directory and run. The
package URL is checked against a pinned host, owner and repository rather than taken from the
API response, TLS verification is never relaxed, and a tag that is not a plain version is
refused rather than interpreted.

**The updater offers, and refuses to apply itself unattended.** The pinning answers a tampered
response and nothing else. A release genuinely published from the pinned repository by somebody
who should not have been able to publish it passes every check in the file, so the remaining
control is the project's own rule turned on itself: a person presses it. `auto_update_plugin`
returns false for this plugin only — returning false unconditionally would switch off automatic
updates site-wide, which is not this plugin's business — and the toggle is replaced with the
reason rather than removed.

**A prefix check does not pin a repository.** The first version checked `strpos( $url, $prefix )`
and parsed the host as a second check. The host check was dead — nothing passing the prefix can
have another host, since a URL's authority ends at the first slash — and the comment justifying
it claimed a lookalike host would slip through, which was false. Removing it failed no
assertion. But the prefix alone was *also* insufficient, for a different reason the comment had
not thought of: HTTP clients resolve `..` out of a path before sending, so
`…/wordpress-x/releases/download/../../../../someone/their-repo/…` starts with the prefix and
downloads from another account. Any `..` is refused now.

Two lessons in one place: dead code justified by a false comment is worse than either alone, and
a test case written to *confirm* a guard is sufficient is how you find out it is not.

**Failures are cached, not only successes.** GitHub allows 60 unauthenticated requests an hour
per IP and a hosting provider's sites share one, so retrying on every admin page load is how one
rate-limited site becomes every site on that host.

**Nothing builds a link to this screen by hand.** `WPAQS_Sort::url()` did — from
`admin_url( 'tools.php?page=' . WPAQS_SLUG )` plus its own two arguments — which is correct while
sorting is the only control and silently wrong the moment there is a second one: a column header
would have dropped the filter and shown every row under a button saying otherwise. `WPAQS_Screen`
owns the URL, carries the screen's arguments through an allowlist, and drops an argument by
setting it to `''` so an unfiltered screen has a clean address. **When adding a control that acts
on the query string, the question is what every *other* control does to that argument** — the same
question the sibling's autostart loop answered wrongly.

**A filter that hides rows says how many, and an empty filtered table says it is a view.** Two
rows where a moment ago there were 140 is indistinguishable from a site that lost 138 accounts.
This is the account cap's rule and the coverage panel's rule in a third place: dropped work is
disclosed, and "not shown" is never "not there".

**`session_tokens` does not hold only unexpired sessions.** This file said it did, and so did
`class-sessions.php`'s own docblock, for four versions.
`WP_User_Meta_Session_Tokens` prunes expired tokens when it next *writes* the meta — on a login,
on a session being destroyed — so an account that stopped signing in keeps its lapsed tokens
indefinitely. A real site showed a 2024 sign-in under a heading reading live sessions.

`expiration` was read into every row and used by nothing, which is the shape to watch for: a
field collected and never consulted is a claim nobody checked. The rows stay, marked, because
they are the closest thing to login history core keeps; what changed is that `addresses()` and
`findings()` read `open()`. The address set is the important one — a match there *suppresses*
the unfamiliar-address finding, so a lapsed session vouching for its address forever is a false
negative, not a cosmetic error.

A zero or missing expiry is **not** expired. That is meta this class could not read, and
"closed" is a claim as much as "open" is.

**Fixtures are relative to `time()`, never absolute.** `test-sessions.php` wrote
`expiration => 1760000000`, in the future when written and in the past now, so every session in
it silently became expired and five assertions began failing for a reason unrelated to the code.
A test that passes only until a date is a test that will mislead somebody mid-incident.

**Two conditions being false together is invisible to an assertion that reads either one.**
The single-session ending control shipped twice broken: two buttons where one press exists,
then — gating both on the count — no button at all. Four assertions read the template for the
string `count( $rows_sessions ) > 1` and all four passed both times. Counting what the row
draws is the only assertion that sees it, and it cannot be written against a template, which
is why `WPAQS_Sessions::controls()` exists and returns which controls a row carries.

The sibling paid for these. Do not undo them here.

**Sharing code between two WordPress plugins is not possible.** Both active, both shipping
a class of the same name, and PHP fatals. So anything copied from the sibling **stays
byte-identical except for the prefix**: `sed 's/WPAQS_/WPMQS_/g'` over the copy must `diff`
clean against the original. That makes divergence detectable rather than inevitable, and
porting a fix mechanical. `tests/wp-stubs.php` is currently the only such file.

**A role is not what an account can do.** `$user->add_cap()` writes capabilities into the
same `wp_capabilities` meta that holds role names, so the Users screen's role column shows
Subscriber for an account that can edit users. `WPAQS_Accounts::direct_capabilities()`
drops every key naming a registered role and reports what is left.

**That rule is not deterministic, and the wording says so.** Legitimate plugins use
`add_cap()` for their own permissions. It is a shortlist to confirm, not an accusation — and
only capabilities that change the site are reported, because a direct grant of `read` is
noise. The sibling shipped a heuristic that matched a football site's own editorial content
and had to narrow it; the lesson is to say what a rule cannot know.

**A finding nobody can resolve is noise, however true it is.** `content_predates_account`
shipped in 0.2.0 and was gone by 0.2.2. It reported an account owning posts older than itself,
which `wp_insert_post()` cannot produce — mistaken for proof, until a real site showed that
`wp_delete_user( $id, $reassign )` produces it every time somebody deletes a colleague and
keeps their work. WordPress records nothing about a reassignment, so the rule could not
separate the two.

It was demoted and reworded first, and removed once the real objection landed: **the dates
never change, so the finding never clears.** Every site that has ever tidied up its users
would carry it on every scan with no action that resolves it, which is how a findings list
teaches people to skip it. Its actual reach was narrow too — a post written straight into the
database usually reuses an author that already exists and is dated now, so it would not fire,
and the sibling's `orphan_post_author` and `post_without_gmt_date` catch that case properly.

Two rules follow. **When a rule cannot decide, its job is to hand over what it read**, not to
pick the more alarming reading. And **before adding a rule, ask what clears it** — a true
finding with no resolution costs more attention than it returns.

**A confusable-character fold has to be symmetric.** Mapping digits onto letters is not
enough: `1` imitates both `i` and `l`, so mapping it to either leaves `adm1n` and `admin`
apart. Every member of a set folds to one representative, letters included. That
over-collapses, which is why `lookalike_login` fires only when one side of the pair can
change the site — a site with several brands has honest near-collisions.

**A rule needs a combination, never a single fact.** `users_can_register` alone is how
membership sites work. A custom `default_role` alone is a normal choice. The pair is the
finding, and `test-registration.php` asserts each half alone stays silent.

**A cap that truncates quietly turns "nothing found" into a sentence that sounds
complete.** `WPAQS_MAX_USERS` bounds the read; when it is reached the screen names both
numbers and says the rest were not looked at. The sibling shipped a silent cap in its
plugin-provenance check and had to go back and fix it.

**"Not checked" is a distinct verdict from "clear."** Failed logins are not recorded by
WordPress at all. Login history does not exist beyond sessions still open. No opinion is
held about any IP address, because the plugin makes no network requests. All four are
stated on the screen, and `test-markup.php` asserts they are rendered.

**Repeated findings are one card, keyed on rule *and* severity.** Five application
passwords used from unfamiliar addresses produced five cards with the same title, the same
paragraph and the same next step — which is how the fifth gets missed. Severity is fixed per
rule in the catalog today, so the pair is redundant today; it is the key anyway because a
card holding two severities would have to lie about one of them in its badge.

**Grouping the cards is not the same as removing the repetition.** The sibling's first
version compared whole `detail` strings, and `make()` builds a detail as the catalog
sentence plus an optional extra one — so entries differing only in their tail shared nothing
and the opened card printed the same paragraph once per entry. `shared_detail()` takes the
leading sentences every entry states identically and cuts **at a sentence boundary**:
"Granted directly: edit_" over one entry and "users." over the next is worse than the
repetition. `test-group.php` pins both, and its mutation runs confirm that comparing whole
strings fails three assertions.

**A shared header means the entry prints its remainder unconditionally.** Gating it on the
header having shared nothing is how the sibling made a modification date vanish from every
grouped card the moment sharing started working.

**Application passwords get their own section, not a column.** One authenticates the REST
API as its owner, survives a password change and survives every session being ended — so it
is a key to the site rather than a detail about a person, and the question is how many exist
rather than which account has one. There is **exactly one Revoke control on the screen**, and
`test-markup.php` counts them: the same password with a button in two places is two chances
to be surprised.

**One fold per thing, never two.** Sections fold, and each findings card folds. What does
*not* fold is the entry list inside a card: you open a card precisely to see its entries, so
a toggle around them adds a click to the same intent. `WPAQS_Findings::GROUP_COLLAPSE`
therefore sits on the **card** — six or more entries and it opens closed, which is what stops
a long screen opening on a wall. `test-markup.php` counts the `<details>` inside
`render_group()` and asserts there is exactly one.

**A card that folds is a `<details>` whose `<summary>` holds the `<h2>`**, so the heading
stays the click target. `display: flex` on a summary removes the browser's own disclosure
marker, so one is drawn and rotated in CSS — the sibling shipped three collapsible sections
with no arrow, which reads as a dead card. The inventory and the passwords open by default;
the coverage list does not, and `test-markup.php` asserts that direction rather than just
asserting they collapse.

**Sibling forms, never nested.** HTML terminates an outer form at an inner one. Both
controls sit in one table cell and each is its own form; `test-markup.php` counts the
`<form` tags inside each.

**Every action states its consequence in the confirmation**, including what it does *not*
touch — ending sessions leaves application passwords working, and revoking one leaves
browser sessions alone. An operator who thinks one covers the other leaves a key working.

**An assertion about what the code does not do must not read the comments.** The comment
recording why something is never called names the very thing being forbidden.
`test-markup.php` has `code_only()`, and `test-actions.php` does the same inline.

**The screen states its own version, and that is not decoration.** "The sections do not
fold" and "you are running last week's zip" are indistinguishable over a screenshot. `dist/`
is no help either: `build.sh` writes one file per version, so rebuilding without bumping
overwrites the artifact that would have settled it. When a report arrives about behaviour
that was changed recently, read the version on the screenshot before reading the code.

**Sorting is server-side, and the absence of JavaScript is the feature.** The sibling sorts in
the browser and paid twice: column indices shifted by one because the script read `thead th`
while the body row starts with a `td`, and sorting installed behind an early return that fired
on any table shorter than a page. Neither is available without a script, and this screen can
afford the reload because it holds no state — no scan, no stored report, live reads only. If
JavaScript is ever added here, that is a decision to argue for, not a convenience.

`WPAQS_Sort` sorts on the **value**, never the rendered string: a zero timestamp is "never" and
belongs before every date. Ties break on arrival order **explicitly** — `usort` has been stable
since PHP 8.0, but 7.4 is the floor and is never executed on this machine, so relying on the
runtime would work everywhere it is tested and nowhere it matters.

**A sortable header must point at a real column.** Moving the registration date out of the
account cell into its own column is what made the header meaningful — and the assertion that
counts headers against `<td>`s immediately caught four headers over three cells, which is the
sibling's column-shift bug reproduced minutes after writing its description. Keep that
assertion honest when adding a column.

## Testing

WordPress functions are stubbed in `tests/wp-stubs.php`, guarded by `function_exists` so a
harness can declare a richer version first — which is how `test-accounts.php` supplies its
own `get_users()`. `tests/bootstrap.php` defines the plugin constants and `check()`.

| Suite | Covers |
|---|---|
| `test-accounts.php` | Direct capabilities against role-inherited ones, denials not read as grants, the notable list, and the benign cases that must stay silent |
| `test-sessions.php` | Scripted-versus-browser classification both ways, malformed session meta reported rather than skipped |
| `test-app-passwords.php` | Unused, foreign address, no recorded address, and a password used from a live session's own IP |
| `test-registration.php` | The combination fires; each half alone does not; `'0'` is not truthy |
| `test-actions.php` | The self refusal, the multisite capability, nonce scoping, live existence, and that nothing calls a user delete |
| `test-filter.php` | The view allowlist, the hidden count, that an empty result still reports the view, and that sorting and filtering preserve each other's arguments while carrying nothing else from the request |
| `test-sort.php` | The allowlist, per-table isolation, numbers not sorting like text, never-used first, stable ties, and the link reversing the active column |
| `test-group.php` | One group per rule and severity, nothing lost or reordered, the shared prefix and its sentence boundary, a lone group left intact |
| `test-timeline.php` | Ordering, both window exclusions, the disclosed cap keeping the newest end, the activation-key parser, and that the class runs no query |
| `test-uninstall.php` | That every name the source stores is named in uninstall.php, deleted as a site transient, that nothing outside the prefix is deleted, and that uninstalling touches no account or session |
| `test-updater.php` | Where an update package may come from, the traversal that defeats a prefix, tags that are not versions, both directions of the padding trap, and that the cache is named in uninstall.php |
| `test-markup.php` | Sibling forms, every action confirming, the four coverage statements, the disclosed cap, grouped rendering, and that no evidence is echoed raw |

When adding a rule, add both a positive case and the benign case that must **not** match. A
rule without a false-positive test is not finished.

## Conventions

Prefix `WPAQS_`, text domain `wpaqs`, one class per `includes/class-*.php`, autoloaded by
name. WordPress coding style: tabs, spaces inside parentheses, Yoda conditions, docblocks
on every method. Comments explain why a thing is the way it is, especially where it looks
wrong.
