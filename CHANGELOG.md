# Changelog

Where a version fixes a false positive, the false positive is named: each one becomes a
regression test, and that list is the most useful thing in this file.

## 0.12.0

**A finding names what it is about, not only what it points at.** Every exported finding now
carries a `subject`: the coarser noun behind its target. Five of this plugin's target shapes —
`user:1`, `user:1:sessions`, `user:1:networks`, `user:1:app-password:…`, `user:1:reset` — all
describe one person, and nothing in the export said so.

Measured across a real fleet: eight sites in ten had one account named by more than one rule.
On the worst of them five findings spanning both plugins described a single administrator:
application passwords, one used from a foreign address, sessions from many networks, one never
used. The console drew them as five unrelated cards in two tabs, because it splits by scanner
and the coincidence does not.

Sent from here for the reason `offers()` exists. The target grammar is this plugin's,
`offers()` already reads it, and a console taught to read it too would be a second place to
update when it changes. The console groups on the identifier and never scores it: five medium
findings about one account are not a high.

## 0.11.0

**A finding names the actions it supports.** Every exported finding now carries an `actions`
array: the id the controller dispatches on, a label, and the parameters already built — plus
a refusal, in this plugin's own words, where an action it would otherwise offer is
unavailable on that target.

This exists because the console guessed and was wrong three ways at once. It drew
"quarantine this file" on every target: on `user:1`, where quarantine means nothing and the
action is to suspend the account; on a modified core file, where quarantining breaks the
site and the repair is to reinstall core; and on `option:file_edit`, where there is no
action at all because the next step is a line in wp-config.php. Deciding that needs the
target vocabulary, the eligibility rules and the site itself, and none of the three live in
a console.

The parameters go with the name deliberately. A console holding
`{ id, params }` can sign that intent and send it back without parsing a target or
constructing an argument, which is what the command channel will need — and an intent it
cannot construct is an intent it cannot get wrong.

**No action offers to end one named session.** `end_session()` takes the verifier, and the
verifier is the field this plugin refuses to send because it names a live session. Only
`end_sessions()` — every session on an account — is offered, because it needs the account
alone. A console that could end one named session would be a console holding the name.

## 0.10.1

**Nothing changes on a site running this.** The shared `class-fleet.php` forwards a `malware`
block alongside the `access` one when the export offers it, and this plugin's export has no
such key — the field goes out as `null` and the console stores nothing.

The change is here because that file is copied byte-for-byte into the sibling, where the
export has begun carrying one. A shared file edited in one repository and not the other
fails that repository's own build, which is the point of the checksum.

Versioned rather than merged quietly, so the header and the published zip keep saying the
same thing.

## 0.10.0

**The console can see who has access.** Until now the fleet push carried findings and
nothing else, so the console could say "an application password has never been used" and
could not say which account held it, what else that account could do, or whether anybody was
signed in as it. It raised the question and had no way to answer it.

The export now carries the account list, the live sessions, the application passwords and
the effective code capabilities — **and the line about what never leaves is unchanged.**
That line is specific: the password hash, the session verifier, and the raw activation key
behind a pending reset are the material that would let somebody *become* one of these
accounts. A login, a role, a capability, an address, a user agent and a date are what let
somebody *recognise* one.

The verifier is the one worth naming twice, because `WPAQS_Sessions::for_user()` carries it
on purpose — this plugin's own screen needs it to name a session it can end — and any copy
of that row that forgets to drop it ships live session identifiers to a console holding 162
sites at once. It is stripped, and asserted stripped in both directions: the value and the
key. Ending a session remotely will be a signed command resolved on the site, not a token
sent to a browser.

`recommendation` leaves with each finding now. The console prints it and writes none of its
own — a second wording of what to do about a finding drifts from this one the first time
either moves.

**A fleet report is identified by what it found, not by the hour it ran.** The run id was
`gmdate( 'Y-m-d-H' )`, and the console derives a scan's document id from it, so two reads in
one hour wrote to a single document: the first answer was overwritten and gone. Worse, the
console refuses a review of any scan but the one the site currently points at, and that
refusal compares ids — a second read replacing the first *in place* left the id unchanged,
the refusal silent, and somebody signing off findings they had never seen while reading the
ones they had. The id is now derived from when the read ran and what it found. The sibling
plugin has always done this.

## 0.9.0

**This plugin keeps itself current.** The rule it shipped with was the project's own
discipline turned on itself: a person presses every update, because the pinned repository
answers a tampered response and nothing answers a release genuinely published by somebody
who should not have been able to publish it.

Across a fleet that inverts. Updating 162 sites by hand per release is how the project
dies, and **an out-of-date scanner reports green** — which is worse than no scanner,
because a green dashboard is read as an answer.

The compensating control moves to where publishing happens rather than disappearing: 2FA on
accounts that can publish, a protected environment on the release workflow, and required
review before publishing. Those are settings on the repository, not code, and this release
does not create them.

**The toggle is replaced by a sentence, not left in place.** `automatically()` answers the
filter unconditionally, so the checkbox WordPress would print could be switched off and
change nothing — a control that looks like it works and does not, which is the fault this
plugin exists to report rather than commit.

**The way out is a filter**, `wpaqs_auto_update`, so a site that must not take unattended
updates can refuse from its own mu-plugin. A fleet-wide policy with no way out is a policy
somebody escapes by editing the plugin.

**And the stub that made the escape hatch testable was itself untestable.** `apply_filters`
in `tests/wp-stubs.php` returned its value unconditionally, so an assertion that a filter is
consulted passed whether or not the code consulted it — the hatch could have been deleted
and every suite would still have agreed it worked. It honours a value the harness plants
now, and the mutation that stops consulting the filter fails.

## 0.8.8

**A site that failed its first enrolment never asked again.** One timeout, one DNS blip, one
host firewall — and the site spent the rest of its life polling about an enrolment nobody
had created.

`enrol()` wrote `requested_at` on every attempt, and `keep_up_with_fleet()` reads that field
to decide between asking and polling. So a single failed POST moved a site permanently onto
the polling branch. The console answered `no-enrolment` every time, correctly, and neither
side ever went back to asking.

**Deactivating and reactivating did not clear it.** The field lives in an option and
deactivation only clears scheduled hooks, so the usual remedy did nothing — which is what
made this hard to see from either end.

It is the same fault as `pushed_at`, fixed in 0.28.6 for exactly this reason: a timestamp
named for an event, written next to the error saying the event did not happen. Fixed there
and left here.

**The half that repairs sites already stuck is the second one.** A poll answered
`no-enrolment` now forgets the request and when it last polled, so the next fleet check
takes the enrol branch again. Nobody has to visit the site. The console has been answering
that sentence to those sites for as long as they have been polling; nothing was listening.

Every other refusal leaves the request standing. A `not-your-enrolment`, a 500 or an
unreachable console are the console being unhappy with one poll, not saying the site never
asked — and forgetting on those would have a site re-enrol over a transient error.

## 0.8.7

**A site that received this plugin as an *update* never joined the fleet.**
`register_activation_hook` does not run when WordPress updates a plugin, and the fleet
event was scheduled only from there. So a site that already had this plugin active and got
the fleet version by update never scheduled it: it never asked to enrol, never reported,
and its own panel said it had not asked to join a fleet console — while the console showed
nothing at all, which is indistinguishable from the plugin never having been installed.

Reported as *"I installed it on several sites and no enrolment request has arrived"*, which
is the correct thing to conclude from what both screens said.

The schedule is ensured on `init` now as well as on activation. It is idempotent —
`wp_next_scheduled()` reads the cron option WordPress has already loaded — so a site that
was updated heals itself on its next page load, without anybody deactivating anything.

**And a 2xx answer is no longer taken as success on its own.** Every path under the
console's prefix that is not a function is rewritten to its single-page app, which answers
`200` with HTML. One wrong character in a path, a rewrite dropped from a deploy, or an
endpoint renamed on the far side, and this plugin would have recorded success on every
request while the console received nothing. A firewall's block page does the same thing.

Every endpoint answers JSON, so anything else did not come from them. The transport says so
and quotes the first of what it got instead — found by mistyping a path by one letter and
receiving `200 <!doctype html>`.

## 0.8.6

**"Last report sent 13 hours ago" beside "Last completed scan: 6:02 am" was read as a
site that scanned and did not send.** Reported from a real install, and the correct thing
to conclude from what the screen said. Both lines were the same moment — rendered
relative in one place and absolute in the other, in different formats — so finding out
they agreed meant doing arithmetic across two zones and two notations.

The panel names what it sent now: *"Last report sent 13 hours ago — the scan that
finished August 21, 2026 6:02 am."* On the site's own clock, in the screen's own format,
because the same moment in two zones is the fault being removed rather than moved.
This plugin reads live state, so there is no scan to name and the panel
says *"a live read of the site at that moment"*. `last_report_finished()` returns 0 here
deliberately: the read and the send are one act, there is never a finished report sitting
unsent, and any other answer would make the panel warn about a scan that does not
exist.

**And the state the screen had no words for at all is now one of them.** A scan that
finished after the last successful push is a scan the console has not been given, and the
panel used to go on reporting when the *previous* report was sent — indistinguishable
from a site that is up to date. It says so, and says the hourly fleet check will try
again, because a problem nobody has to act on must not read like one that does.

`push()` records which scan a report described and which run it was, on success only. A
failed push recording it would have the panel name a scan the console was never given,
which is the original fault with a new cause. Forgetting a revoked enrolment drops both,
for the same reason.

## 0.8.5

**A site that joined the fleet then sat silent until the next scheduled run.** The
handshake collected a key and stopped. Nothing was sent until a scan happened, so a site
approved in the console showed as never having reported for up to a day — which is
indistinguishable from a site where the plugin was never installed, and the person who
had just approved it had no way to tell which.

A handshake that gets in now reports immediately. There is no
scan to start — reading live state *is* the read — so the handshake and the first report
are one step. The sibling has to run a scan at that point, which is the only reason it
does more.

**A push that failed claimed the report had been sent.** `pushed_at` was recorded on
every attempt rather than on success, and the fleet check asks exactly that question
before deciding whether to retry. One failed attempt meant that report was never sent
again — silently, and on the run that matters most.

**A site removed from the console kept believing it was enrolled.** Its key no longer
works, so every push comes back 401, and nothing acted on that: the site would retry a
key nothing will ever accept, for ever, while the console had forgotten it existed.
A 401 now clears the enrolment so the site asks again on its next fleet check, which
puts it back in the approval queue where a person can decide. The install nonce
survives — it identifies the installation rather than the enrolment, and a fresh one
would make a re-approval indistinguishable from a different install at the same address.

Every other refusal leaves the site enrolled. A 400 is the console being unhappy with
one report, and leaving the fleet over that would be a removal for a reason unrelated to
whether the site belongs in it.

**The screen has a shape now.** Three groups matching the sibling's: what is wrong, who has
access, and the settings. The fleet panel sat between the application passwords and the
coverage list — one configuration card wedged between two readings of the site.

The section headings are not decoration: they are what stops the next section being
appended wherever the last one ended, which is how the old order was arrived at.

## 0.8.4

**A site could enrol and then silently drop out of the fleet.** Enrolment rode on the
scan's own cron event, and `reschedule()` clears that event when somebody turns the
daily scan off. A site with the schedule disabled would never enrol — or would stop
reporting if it already had — and in the console that reads as a site nobody has heard
from, which is indistinguishable from one where the plugin was never installed.

Whether a site scans on a schedule and whether it belongs to a fleet are two different
questions, and one was answering the other. Enrolment now has its own event, scheduled
unconditionally.

**It runs hourly rather than daily, and that is about waiting rather than cost.** Both
things it does are waiting on something: a person approving, and a report a manual scan
may have produced. Asking once a day meant a site approved five minutes after its daily
run waited most of another day to find out — which reads as approving having not worked.

**A report the console never saw is now sent.** A site enrolled after a scan had already
run held a report nobody received, and the console showed it as never having reported.

## 0.8.3

**A manual scan reports too, and there is a button that does not wait for the
schedule.** Both were gaps in 0.8.2.

The push was hooked to the cron path only, so a scan somebody ran by hand finished,
stored its report, and the console never heard about it. The reasoning copied from the
mailer — that somebody watching a run does not need it in their inbox — does not carry:
a console is a record, not an inbox, and leaving it stale while the site holds a fresher
answer is two sources disagreeing, which is the thing this plugin exists to notice
rather than cause.

**The panel's result is rendered by the panel.** It redirected with a notice key the
screen did not recognise, so pressing a button rendered nothing — and a button that
renders nothing is indistinguishable from one that did nothing. The two plugins render
notices differently, so a shared panel handing its result to either would have looked
broken in exactly one of them, which is the hardest kind to notice.

## 0.8.2

**A panel on the screen, and a button that does not wait for tomorrow.** Enrolment
happened on the daily run and nowhere else, so a site that had asked, been refused, and
would ask again looked exactly like a site that had never tried. Four states reading
the same is the fault this plugin keeps finding in other people's screens.

The panel names which of them it is — never asked, waiting for somebody to approve,
enrolled and yet to report, enrolled and reporting — and carries **Ask to enrol** or
**Check now** depending. Pressing Check now ignores the interval that stops the daily
run hammering the console: somebody watching a screen is allowed to ask again.

The last error stays visible after a success, because a site that is enrolled but whose
last report failed is not a site with nothing to say.

## 0.8.1

**This plugin reports to a fleet console, and three things it never did before are now
true of it.** Each was absent by design and its own documentation said so, which is why
each statement is corrected in the same version that makes it untrue.

**It stores something.** A key, the nonce identifying this install, and when the last
report went. `uninstall.php` names them, and deleting the key is the point: an
uninstalled plugin that left one behind would leave a credential on a site nobody
watches any more.

**It schedules something.** There was no scheduler, and the reason was sound — somebody
opens the screen. That holds for one site and fails for 162, which cannot be opened one
at a time. The event reports and nothing else: there is no scan here, because reading
live state *is* the read. In UTC, because a site-local hour moves whenever somebody
edits the timezone setting and a fleet then reports at unrelated times.

**It makes network requests.** The README said it did not, in the section about
holding no opinion on any IP address. It still holds none and still asks nothing about
one — but it talks to the update check and, once enrolled, to the console.

**The findings moved out of the screen.** `render()` assembled them inline, which was
fine while the screen was the only thing that wanted them. `WPAQS_Report::gather()`
does it now and the screen calls that, so the console reads the same answer by the same
route. Two routes to one question is how a finding shown here stops matching the
finding shown there.

The export is deliberately narrow. This data is more sensitive than the sibling's —
logins, email addresses, the addresses people sign in from — and those are the point: a
console hiding them could not answer the question it exists for. What never leaves is
what would let somebody *become* an account rather than recognise it: the password
hash, session tokens, and the raw activation key behind a pending reset. That finding
carries when the reset was requested, which is the part a person can act on.

**Enrolment waits for a person**, and the verification route returns a hash of the
install nonce rather than the nonce, because that route is public and the nonce is what
collects the key.

**The rule that shared files may not drift is now a test.** `test-shared.php` compares
hashes with the plugin prefix and directory normalised away, and both plugins carry the
same list, so a shared file changed in one repository fails that repository's own
build.

665 assertions, up from 623.

## 0.8.0

**Nothing here changes what the plugin does**, and unlike the sibling not one visible
string moved either. It is recorded because the next version is where that stops being
true.

All six corrective actions moved out of the endpoint into `WPAQS_Actions`, and
`WPAQS_Controller` became a translator: capability, nonce, parse, delegate, redirect.
It performs nothing now, and the tests fail if it starts to.

The reason is the fleet console being built alongside this: a later version runs these
same actions from a signed remote command, with no logged-in user and no nonce. A
refusal that lived in the HTTP handler would apply to the button and not to that
caller.

**The guards that read the session break in opposite directions under cron.**
`get_current_user_id()` is 0 there and `current_user_can()` is false for everything, so
`self` compares against user 0 and silently stops applying, while `nocap` becomes true
for every target and would refuse a command outright — the more dangerous of the two,
because it looks like a working control. Both now take the acting user, required with
no default.

**One of the two places was not the endpoint.**
`WPAQS_Accounts::remove_direct_capability()` carried its own copy of both guards, which
auditing the six endpoints would have missed. The sibling had already found the same
shape in a collaborating class and said to look there.

`revoke_password` still checks live state rather than anything stored, which is this
plugin's advantage over the sibling: a password that is not on the account right now is
not something to act on. That check moved with the action rather than being left behind.

623 assertions, up from 554. Among them, two that fail if the controller ever performs
an action itself or stops delegating one. They were written after the harness's exit
call the first time and never ran — passing green while proving nothing, which is this
file's own lesson about assertions met while writing the assertions meant to prevent
it. Mutation found it.

## 0.7.1

**There was no way to tell whether the update check had run**, the same fault the sibling was
reported with. A plugin row showing no update could mean the check has not run yet, the check
failed, the check ran before the release was published, or the plugin is current. Four states,
one appearance — which is the same fault as a control that silently never initialises.

The plugin row now says which, and offers **Check for a new release now**:

- **Each failure names its own cause.** Unreachable, refused, no published release, an unexpected
  status, and an answer naming nothing installable are five different sentences, and
  `test-updater.php` asserts no two read the same. The rate-limit wording says it is usually
  another site on the same address, because on shared hosting it is.
- **When a release is available the sentence explains why the row may not show it yet.**
  WordPress decides that row from its own `update_plugins` transient, which it refreshes twice a
  day, so "available" beside a row with no update link reads as broken when it is the wait.
- **Check now clears both caches**, this plugin's and WordPress's own. Clearing one leaves a
  button that changes nothing anybody can see. It takes `update_plugins` and a nonce, because it
  makes a network request and clears site-wide state.

**`test-uninstall.php`, which this plugin did not have.** It was built storing nothing — every
screen reads live and throws the result away — and `uninstall.php` said exactly that, ending
"the moment anything is written, its name belongs here". Then the updater wrote one, and the only
thing between that note and being wrong was somebody remembering to read it.

Names are discovered from the source rather than listed, because a hand-maintained list is what
went stale in the sibling. The `site_` call variants are in the pattern from the start, since the
one name this plugin stores is a site transient and would otherwise be invisible. It also asserts
the deletion uses `delete_site_transient` rather than the plain one, that nothing outside the
`wpaqs_` prefix is deleted, and that uninstalling touches no account, session, application
password or role — removing the release cache, or adding a stray `delete_option`, both fail it.

## 0.7.0

**Updates arrive in the Plugins screen.** Neither plugin is on wordpress.org, so WordPress had
nowhere to ask whether a newer version existed and the row showed nothing however many releases
were published — every update meant downloading a zip and uploading it by hand. `WPAQS_Updater` tells
WordPress where to ask: this repository's own releases.

This is the most dangerous code in the plugin and is written that way. It hands WordPress a URL
that WordPress downloads, unzips over the plugin directory and runs on the next request:

- **The package URL is checked against a pinned host, owner and repository**, never taken from
  the response. If the API answer is tampered with, or the repository moves, the answer is to
  install nothing.
- **A prefix check was not enough**, which a test case written to prove it was found instead.
  HTTP clients resolve `..` out of a path before sending it, so a URL starting with the pinned
  prefix and continuing `../../../../someone/their-repo/…` downloads from another account —
  still `github.com`, still a 200, not this plugin. Any `..` is refused.
- **The asset must be this plugin's own zip.** Both plugins are released from one account and
  their names differ by a word, so a release carrying the sibling's zip must not install it.
- **TLS verification is never turned off**, not as a fallback and not behind a filter. A plugin
  that would rather install something than nothing is a delivery mechanism.
- **Versions are padded to three components** before comparing. `version_compare( '1.2',
  '1.2.0' )` reports less-than, which hides an available update; as text `0.10` sorts before
  `0.9`, which offers a downgrade. Both look like the updater merely not working.
- **A tag that is not a plain version is refused** rather than interpreted, so a branch or a
  pre-release is never installed as a release.
- **Failures are cached, not only successes.** GitHub allows 60 unauthenticated requests an
  hour per IP and a hosting provider's sites share one, so retrying on every admin page load
  is how one rate-limited site becomes every site on that host.

**It offers the update and refuses to apply it unattended.** WordPress shows an "Enable
auto-updates" toggle for anything reporting update information, and turning it on would mean a
release installs itself on the next cron run with nobody present.

That is the one risk none of the checks above reduce. Every one of them assumes the danger is a
*tampered answer*. None helps if the release is genuinely published from the pinned repository by
somebody who should not have been able to publish it: such a release is correctly hosted,
correctly named, correctly signed, and passes every check. What is left is this project's own
rule turned on itself — **a person presses it**. That makes a compromised release account mean
every site whose operator pressed a button rather than every site at once.

The toggle is replaced with a sentence saying why, because a control that silently does nothing
reads as broken.

`plugins_api` is filtered too, or the *View details* link beside the update opens a modal
saying the plugin does not exist — a control that looks like it works and does not.


The update cache is the first thing this plugin stores. `uninstall.php` said it stored nothing
and now names it, deleted as a site transient — on multisite the plain function looks in the
wrong place and leaves the row behind.

## 0.6.0

**Show only the accounts signed in right now.** A real site's table opened on 140 rows of which
nearly every one read "none open". The information was right and there was too much of it: the
accounts with a live session are the answer to "is anybody in there", and they were somewhere in
the middle of six screens of scrolling.

A link, not a checkbox, because this plugin has no JavaScript and the screen holds no state.
Open means open — an account whose every session has lapsed is exactly what the view exists to
get out of the way.

**The filter says how many rows it hid**, and an empty filtered table says it is a view rather
than a site with no accounts. A list of two where a moment ago there were 140 is
indistinguishable from a site that lost 138, and "nothing here" has to mean that.

**`WPAQS_Screen` now builds every link to this screen.** This is the part that would have broken
quietly: `WPAQS_Sort::url()` built its link from `admin_url( 'tools.php?page=' . WPAQS_SLUG )`
and added only the two sort arguments. Correct while sorting was the only control, and wrong the
moment a second one existed — pressing a column header would have dropped the filter, leaving a
table showing every row under a button saying otherwise. The same shape as the sibling's
autostart loop: one control acting on the query string, another rewriting it without knowing.

Arguments are carried through an allowlist rather than by copying `$_GET`, so no link this
screen prints can carry something a request put there — not a `redirect_to`, not a nonce, and
not the result of an action that would then be re-shown by pressing a column header.

The cap notice counts what the screen *read*, which the filter does not change. Off the filtered
list it would have said the screen read two of the site's 141 newest.

**CI, and a release workflow.** The suite now runs on 7.4 and 8.3 on every push and pull
request. Until this, the 7.4 floor rested entirely on a grep for PHP 8 syntax in `build.sh` and
had never been executed. The release job asserts the tag matches **both** version strings — the
header and `WPAQS_VERSION` — because the sibling checks only the header and its two have already
drifted a patch apart.

## 0.5.1

**Sessions that had expired were shown as open.** A real site's list carried a sign-in from
2024-08-29 under a heading reading live sessions. `session_tokens` does not hold only unexpired
sessions, which `class-sessions.php` claimed in its own docblock for four versions:
`WP_User_Meta_Session_Tokens` prunes expired tokens when it next *writes* the meta — on a
login, on a session being destroyed — so an account that stopped signing in keeps its lapsed
tokens indefinitely. `expiration` was read into every row and then used by nothing.

The rows stay, marked `expired`, because they are the closest thing to login history core
keeps and an old sign-in from an address nobody recognises is worth reading even dead. What
changed is every claim made about them:

- **The false negative, which is the reason this is a fix and not a polish.** `addresses()`
  is the set of addresses an account is known to work from, and `WPAQS_App_Passwords::findings()`
  *suppresses* its unfamiliar-address finding on a match. A lapsed session vouched for its
  address forever, so an application password used from that address today read as familiar.
  Expired sessions no longer vouch for anything.
- `sessions_many_networks` and `non_browser_session` read `open()`. The catalog says "live"
  and "at once"; three lapsed sessions across three networks is neither.
- No button to end an expired session — a press that changes nothing anybody can see. The
  bulk control counts the open ones.
- An account whose every session has lapsed says so **above the list rather than instead of
  it**, since "none open" alone would throw away the addresses and dates.

A zero or missing expiry is *not* read as expired: that is meta this class could not
understand, and "closed" is a claim as much as "open" is.

**A fixture had rotted.** `test-sessions.php` wrote `expiration => 1760000000`, in the future
when it was written and in the past now, so every session in it had silently become expired and
five assertions failed for a reason unrelated to the code. Fixtures are relative to `time()`.

## 0.5.0

**A timeline.** Somebody who opens this screen because their site is behaving oddly is not
asking who has access — they are asking what is different. The tables answer the first
question; the timeline answers the second, out of the same data, newest first: accounts
created, sign-ins, application passwords created and used, resets requested. No new queries —
every timestamp in it was already read to build the tables.

The value is the ordering rather than any single line. "Session from an address nobody
recognises" is thin alone; the same line followed twenty minutes later by "application
password created" and then "that password used from somewhere else" is a takeover with its
steps in order. It is capped at 100 entries and **says so when it is** — a truncated list that
looks complete tells the reader the oldest entry is the oldest event.

The window excludes two things the account row cannot: a session still open but opened before
it, since WordPress keeps a session until it expires rather than for thirty days, and an
account registered outside it. Both are mutation-tested.

**Pending password resets, at medium.** `retrieve_password()` writes `time():hash` into
`user_activation_key` and `reset_password()` clears it, so a key still sitting there means a
reset link was issued and never used — with the hour attached. On an administrator that is
either a locked-out colleague or an attempt, and it is one of the very few dated events core
keeps. Older WordPress stored the hash with no timestamp: that still reports as pending, with
the hour shown as unknown rather than as 1970. The benign case — an account with no key, which
is nearly every account — stays silent.

**Your own session is marked.** The sessions column now says *this is you* beside the row you
are reading the screen from, matched on the SHA-256 of the current token, which is what the
`session_tokens` meta is keyed by. Without it the first thing anybody does with a list of live
sessions is wonder which one they would be ending.

**One button where there was one press.** With a single session, "End this session" and "End
these sessions" did the same thing side by side. Now exactly one appears: the per-session
button, the bulk one when the site replaces the session manager and per-session ending is
unavailable, and neither when there are no sessions — where the plural would have read "End
all 0 sessions". The bulk label names the count.

The first attempt at this gated **both** controls on the count, so a single session got no
button at all — the same class of fault as a control installed behind an early return, and one
no assertion could see, because all four assertions read the template for the condition rather
than counting what it drew. Two conditions being false together is invisible to a test that
reads either one. The decision now lives in `WPAQS_Sessions::controls()`, which returns which
controls a row carries, and `test-sessions.php` counts them for zero, one and three sessions
with the default session manager and without. Reintroducing either bug fails the same
assertion.

## 0.4.0

**The tables sort, on the server.** Application passwords by name, account, created or last
used; the account list by login or registration date; who-can-run-code by login. Each table
sorts independently, and pressing the column already sorting reverses it.

No JavaScript, and that is the point rather than an economy. The sibling sorts client-side and
paid for it twice — column indices shifted by one because the script read `thead th` while the
body row starts with a `td`, and sorting installed behind an early return that fired on any
table shorter than one page. Neither bug exists without a script, and this screen can afford a
reload because it has no state to lose: no scan, no stored report, everything read live on
every request.

Sorting is on the **data**, not the rendered text, which is the part a client-side sort gets
wrong: a password never used carries a zero timestamp, so it lands before every real date
rather than wherever the word "never" falls in the alphabet. Ties keep the order they arrived
in, or two keys never used would swap places between page loads and the list would look like it
was changing.

The registration date moved out of the account cell into a column of its own, because a
sortable header has to point at a column. **Which is how the assertion that counts headers
against cells caught a genuine off-by-one** — four headers and three cells, the sibling's exact
column-shift shape, in code written minutes after describing that bug.

A sort reloads the page, so each link carries an anchor back to its own section, and a section
that is closed by default opens when its own table is the one sorting.

## 0.3.0

**Every rule that can be cleared from this screen now has the control that clears it.** The
question asked of each one was what resolves it, and three answers were missing.

**Open registration handing out a privileged role** — the only critical rule — had no button
at all. Two now: make new accounts Subscribers, or close registration. Both are settings
rather than deletions, Settings then General puts either back, and no account already created
is touched. The confirmations say which is which, and closing registration warns that it stops
signup on a site that sells memberships.

**A capability granted straight to an account** can be taken off, one button per capability
because confirming a list of grants often ends in keeping some. The role is not touched:
removing something a role grants would be undone the moment WordPress read the role again, so
that request is refused and the wording says where the grant actually comes from.

**One session** can be ended rather than all of them, so an administrator with a browser
session and one opened by a script does not have to sign themselves out to close the script's.
`WP_Session_Tokens::destroy()` wants the raw token and only its hash is stored, so this writes
the session meta the way core does — and is therefore offered **only** when the default session
manager is in use. A site that replaces it keeps sessions elsewhere, where the write would
appear to work and change nothing.

**And a correctness fix found while building the first of those.** On multisite, WordPress does
not consult `users_can_register` at all — the network option `registration` decides — so the
rule was reading a setting nothing honours and would report a closed network as open, or the
reverse. It reads the right one now, and refuses to close registration from a per-site screen
because that setting governs every site.

## 0.2.2

**`content_predates_account` is gone**, two versions after it arrived. Demoting and rewording
it in 0.2.1 addressed the false positive and left the real problem: **the dates never change,
so the finding never clears.** Any site that has ever deleted a user and kept their posts would
carry it on every scan with nothing to do about it, and a findings list with a permanent
unresolvable entry is a list people learn to skip.

Its reach was also narrower than it looked. A post written straight into the database usually
reuses an author that already exists and carries the date it was written, so the rule would not
have fired on it — and Malware Quick Scan's `orphan_post_author` and `post_without_gmt_date`
catch that case on the evidence rather than on a date comparison.

What stays is the lesson, in the contributor notes: when a rule cannot decide, it hands over
what it read; and before adding one, ask what clears it.

## 0.2.1

**The rule shipped yesterday as arithmetic was a heuristic, and a noisy one.** Reported from
a real site: `wp_delete_user( $id, $reassign )` moves a deleted account's posts to another
account and the posts keep their original dates, so deleting a colleague and reassigning their
work produces content older than the account that now owns it. That is an ordinary thing to
have done, and 0.2.0 called it critical while saying there was no ordinary explanation.

WordPress records nothing about a reassignment — no marker, no meta, no log — so the rule
cannot tell one from a row planted in the database. It no longer pretends to. Renamed to
`content_predates_account`, dropped to medium, and the wording names reassignment as the
likely cause first.

What it does now is hand over the discriminator it cannot apply itself: the evidence carries
the **newest** post date and the **number** of posts as well as the oldest. Four years of
content across four hundred posts is somebody else's work inherited; one post a fortnight
before the account is the shape worth opening. Both shapes are in `test-authorship.php`.

**Who can run code opens closed.** On a healthy site that list is the same every visit, and
the count in the summary is the part that changes.

## 0.2.0

**An account cannot be younger than its own oldest post.** `wp_insert_post()` requires an
author that already exists, so this is arithmetic rather than a heuristic — and it is the one
rule here that is. When it fires, the account row or the post row was written straight into
the database, which is the vector that makes rotating a password useless and is the confirmed
vector behind the sibling plugin's two incidents. Reported at critical, with the gap named.

Both date columns are read. WordPress fills `post_date_gmt`, but a row inserted directly
often carries a local `post_date` and leaves the GMT column at zero — reading only the GMT
column would have made exactly the rows this exists to catch invisible. A minute of tolerance
absorbs an import that lands both rows in the same moment.

**Who can run code is its own section.** Installing or editing a plugin or theme means
running code, so that list is the blast radius if any one of those accounts is taken — and no
wp-admin screen answers it, because the Users list shows roles and a role is neither the whole
story nor obviously mapped to code execution. Effective capabilities: a grant made straight
against an account counts the same as one that arrived with Administrator.

Beside the list, whether the built-in editors are reachable. With neither
`DISALLOW_FILE_EDIT` nor `DISALLOW_FILE_MODS` set, every account on it can run code without
uploading anything, which changes what the list means.

**Two accounts sharing one email address.** WordPress refuses to create the second one, so a
duplicate did not arrive through WordPress either.

**A login that imitates a privileged one.** `adm1n` beside `admin`. Folding digits to letters
was not enough — a digit imitates more than one letter, and mapping `1` to `l` left `adm1n`
and `admin` apart — so every character in a confusable set folds to one representative,
letters included. Over-collapsing is the accepted cost, which is why the rule only fires when
one side of the pair can change the site: near-collisions between ordinary logins are normal
on a site with several brands, and stay silent.

**One account signed in from three or more networks at once.** Two is a laptop and a phone;
several addresses on one network are still one network, so an office with a changing address
does not read as somebody signed in from everywhere.

## 0.1.3

**The screen states its own version.** A report that the sections do not fold and a report
that the installed zip predates the change that made them fold look identical over a
screenshot, and only one of them is a bug. The version sits beside the heading so that is a
glance rather than an investigation. Found the hard way: the first evidence that folding was
missing was a screenshot of a build that did not have it, and `dist/` had already been
overwritten, so the zip could not settle it either.

## 0.1.2

**Every section folds now, and so does every card inside the findings.** With five rules
firing the screen ran long enough that reading it meant scrolling, and the header of a shut
card — badge, title, count — is enough to decide whether to open it. The findings section
opens by default and says how many findings sit in how many groups even when shut, because a
fold that hides the count hides whether anything was found at all.

**One fold per card, not two.** The entry list used to collapse on its own at six or more,
inside a card that was always open. That toggle is gone and the threshold moved up to the
card: you open a card precisely to see its entries, so an inner toggle only adds a click to
the same intent. A card holding six or more starts closed, which is what stops a long screen
opening on a wall.

## 0.1.1

**Application passwords have their own section.** They had a column in the accounts table,
and that was the wrong home: one authenticates the REST API as its owner and never touches
the login form, so it is a key to the site rather than a detail about a person. The question
an operator asks is how many exist and whether they recognise them all, which a column
repeated down a table of hundreds of rows cannot answer. Newest first, the owner named, an
administrator's flagged as one, and a key never used flagged as that.

Moving them also removed **a second Revoke button for the same password on the same
screen**, and the section says the thing the column had no room for: an application password
survives a password change and survives every session being ended, so revoking is the only
thing that stops one.

**The long sections fold.** The inventory and the application passwords open by default —
they are the answer, not reference material — and the list of what the plugin cannot check is
closed. It is still a section rather than a footnote, because "nothing found" and "nothing
checked" are different answers and only that list tells them apart. A summary with
`display: flex` loses the browser's own disclosure marker, so the arrow is drawn and rotated
in CSS; the sibling shipped three collapsible sections with no arrow at all.

## 0.1.0

First version. One screen, read live, no scan.

**A role is not what an account can do.** `$user->add_cap()` writes capabilities into the
same `wp_capabilities` meta that holds role names, so the Users screen shows Subscriber for
an account that can edit users. The account list reports capabilities granted directly,
dropping every key that names a registered role — and only the ones that change the site,
because a direct grant of `read` is noise on a screen whose value is being short.

That rule is **not deterministic and says so**: legitimate plugins use `add_cap()` for their
own permissions, so it is a shortlist to confirm rather than an accusation.

**Repeated findings render as one card per rule and severity.** Five application passwords
used from unfamiliar addresses produced five cards carrying the same title, the same
paragraph and the same next step, differing only in the evidence line — and scrolling past
four copies of a paragraph is how the fifth finding gets missed. The wording every entry
shares sits in the header once; each entry keeps its own evidence and whatever is left of
its own sentence, cut at a sentence boundary rather than at the last matching character. Six
or more and the list opens collapsed. The counters still count findings, not cards.

**Live sessions are the only access history WordPress keeps.** `session_tokens` records the
IP, the user agent, the login time and the expiry of each session still open. A session
opened by `curl`, a scripting library, or with no user agent at all is reported: a person
filling in a login form does not produce one.

**Application passwords carry `created`, `last_used` and `last_ip`.** One never used is
reported at medium; one last used from an address the account has no open session from is
reported at high, with the evidence naming both sides of the comparison so the operator can
see what it was measured against. A used password with no recorded address is left alone
rather than described as unfamiliar.

**Open registration plus a privileged default role is one finding, not two.** Either alone is
ordinary — open registration is how membership sites work — so neither is reported alone.

**Two actions, both pressed by a person.** Ending an account's sessions is reversible and
refused on your own account, because it would sign you out of the screen you are working
from. Revoking an application password is not reversible and the confirmation says so.
Neither deletes an account or anything it created: those posts are the record of what it did.

**Four things it cannot check are stated on the screen** rather than omitted: failed logins,
which core does not record at all; login history beyond sessions still open; whether any
address is suspicious, since the plugin makes no network requests; and files, core and
hardening settings, which belong to the sibling plugin.
