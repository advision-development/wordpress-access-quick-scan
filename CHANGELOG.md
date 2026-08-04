# Changelog

Where a version fixes a false positive, the false positive is named: each one becomes a
regression test, and that list is the most useful thing in this file.

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
