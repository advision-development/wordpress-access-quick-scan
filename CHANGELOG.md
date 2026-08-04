# Changelog

Where a version fixes a false positive, the false positive is named: each one becomes a
regression test, and that list is the most useful thing in this file.

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
