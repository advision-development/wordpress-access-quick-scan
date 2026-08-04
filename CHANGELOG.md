# Changelog

Where a version fixes a false positive, the false positive is named: each one becomes a
regression test, and that list is the most useful thing in this file.

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
