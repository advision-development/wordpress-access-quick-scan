# wordpress-access-quick-scan — what is still owed

Companion to `WPSEC-HANDOFF.md`, which is how the fleet transport works. This is what is
left. Ordered by what blocks what, not by size.

The console's own list is in `../hawkeye/WPSEC-PENDING.md` and is not repeated here.

**Last reviewed:** 2026-09-07 (0.12.0 tagged and released; 0.13.0 on a branch, four new rules)

---

## 1. Blocks installing this anywhere else

### A site that failed its first enrolment never asks again

**Fixed in 0.8.8.** `enrol()` records `requested_at` only when the console received the
request, and a poll answered `no-enrolment` forgets the request so the next fleet check asks
again — which repairs sites already stuck without anybody visiting them.

Kept here because the shape recurs and the remedy is not obvious: **a timestamp named for an
event, written next to the error saying the event did not happen.** It was `pushed_at` in
0.28.6 and `requested_at` in 0.8.8, both in the same file. If a field means "this happened",
write it only where it happened.

And the reason it was invisible from both ends: `requested_at` lives in an option, so
deactivating and reactivating the plugin does not clear it. The usual remedy did nothing.

### 0.13.0 carries four new rules and is not tagged

**No site in the fleet runs any of these four rules until a tag is cut.** The updater serves
the release zip, so every screen will go on saying it is up to date while none of the four
runs.

0.13.0 adds `hidden_account_discrepancy`, `excessive_sessions`,
`application_password_suspicious_name` and `client_ip_not_recorded`. The first is the one that
matters most: on the site they come from, this plugin held the contradiction in its own
payload — `total: 11` beside ten rows — and rendered it as `capped`.

**Both plugins release together**, so this tag goes with WPMQS v0.38.0, which carries the
sibling's half of the same compromise. A fleet running one half of the pair reports half a
site.

```
./build.sh
git tag v0.13.0 && git push origin v0.13.0
```

### The session threshold lives in two repositories and only one of them checks it

`WPAQS_Sessions::MAX_SESSIONS` is ten because `WPMQS_Database_Scanner::MAX_SESSIONS` is ten,
and the two have to agree: both reports are read side by side, about the same account, on the
same day. `test-sessions.php` asserts **this** side is ten and cannot see the other side at
all, so somebody raising the sibling's threshold gets a green build in both repositories and a
fleet where one plugin calls eleven sessions excessive and the other calls it ordinary.

The mechanism that already solves this shape here is `tests/test-shared.php`, which proves two
files identical across the pair by hash. A constant is not a file, and the obvious fix —
moving the number into one of the shared files — would put a detection threshold inside the
fleet transport, where it does not belong. Recorded rather than solved.

### Released through 0.12.0

**v0.11.0 and v0.12.0 are tagged.** 0.11.0 carried `access_inventory()` and the per-finding
`offers()`; 0.12.0 carried `subject()`, which is what lets the console see that several
findings describe one account.

**The subject has not been observed arriving.** At the last measurement no site in the fleet
was reporting on 0.12.0 yet, so the console's correlation band — the whole point of that
field — has never had data to draw. Close this when a site reports on 0.12.0 and the band
shows an account.

Both plugins release together. A fleet running one half of the pair reports half a site, and
the correlation band in particular needs both halves sending subjects before it can group
anything across them.

### What the console reads of this export, and what it drops

Worth knowing before adding a field: **exporting something is not the console receiving it.**
hawkeye's `ingest-handler.ts` writes a fixed set of keys and silently drops the rest.

**It stores** `findings` — with `title`, `detail`, `recommendation`, `evidence`, `actions` and
`subject` — plus `access` (the accounts, sessions and application passwords inventory),
`counts`, `rejected`, `dropped` and `truncated`.

**It drops** anything else the report carries at the top level.

Nothing errors when a field lands in the second list; it simply is not there on the other
side. The sibling plugin is currently in exactly that position with its coverage block, which
is why this note exists in both repositories.

### 0.11.0 was built and installed before it was tagged

**The header reads 0.11.0 and the newest tag is v0.10.0.** Two sites run a zip installed by
hand; the rest of the fleet is on the tagged release and stays there until a tag is cut.

0.11.0 adds the two things the console's access tab is built on: `access_inventory()`, which
sends the accounts, sessions and application passwords with the hash, verifier and activation
key stripped, and `offers()`, which names per finding what can be done about it with the
parameters already built.

**Both plugins are released together**, so this tag goes with WPMQS v0.32.0 — a fleet running
one half of the pair reports half a site. **Read the compensating-controls item below first.**

```
./build.sh
git tag v0.11.0 && git push origin v0.11.0
```

### The tag and the header must agree — v0.9.0

Header and published release both read **0.9.0**, tagged 2026-08-24. The release
workflow refuses a tag that disagrees with the plugin header, which is the guard that
exists because a zip once said one version and contained another.

They must stay in step: the updater serves the release zip, so a merge without a tag leaves
every site on the previous build while every screen says they are current. That happened
once and cost five versions.

```
./build.sh
git tag v0.9.0 && git push origin v0.9.0
```

Both plugins are released together. A fleet running one half of the pair reports half a
site, and the console shows the missing half as a plugin that was never installed.
WPMQS 0.29.0 shipped alongside this one.


### Auto-update ships, and the controls that were supposed to come with it do not

**Shipped in 0.9.0 and live on every site that has taken the update.**
`WPAQS_Updater::automatically()` answers WordPress's `auto_update_plugin` filter with `true`,
and the escape hatch is the `wpaqs_auto_update` filter.

**This is now the most urgent unfinished thing in either plugin repository, and it is not
code.** The compensating controls named in `CLAUDE.md` when the reversal was decided still
do not exist:

- 2FA required on every account that can publish a release
- a protected `release` environment on the workflow
- required review before a merge to `main` that a tag can be cut from

Until those are set, **whatever lands in a release runs on 162 sites without anybody
approving it.** The policy this plugin shipped with — a person presses every update — was
the control; reversing it moved the control to the repository, and the repository has not
received it. Nobody can do this from a terminal: they are settings on the GitHub
repository.


---

## 2. Comes back to this repo later

### Phase 5 — polling for signed commands

The plugin will ask the console whether anything has been queued for it, verify a
signature, and run it. `WPAQS_Actions` is already the shape those commands call and needs
no change: the acting user is passed in rather than read from the session, precisely so a
command arriving with no logged-in user behaves the same way.

Two things settled on the console side that this repo has to match when it lands:

- **The signature is made at delivery, not at enqueue**, and lives ten minutes. The
  intent behind it lives 24 hours. A plugin that treats the two as one clock will
  reject every command on a site whose cron lags.
- **An action returns `ok`, `changed`, `code`, `message`, `data`.** `message` is the only
  human-facing string, and nothing outside this plugin may map a `code` to a sentence.

### Phase 6 — running those actions remotely

`actionsEnabled` already exists on every site document in the console and defaults to
`false`. Nothing here reads it yet.

---

## 3. Smaller, and not blocking

### `auto_update_blocked` ships informational, and is owed a second decision

0.15.0 reports why a site will not take an unattended update, at `info`, so the number could
be read before the severity was chosen. The first day of data did not answer that question;
it found a broken gate instead, removed in 0.18.0, which had fired on every install that
could report it. **The measurement has not happened** — every reading so far was of a rule
that was wrong, so do not raise the severity on the strength of them. Read it again once a
fleet has reported against the corrected rule.

The comparison worth making is against this plugin's own `file_editing_enabled`, which is
`medium` for a smaller consequence — and which recommends the very constant, `DISALLOW_FILE_MODS`,
that is one of the reasons this new rule fires. A site that took that advice and thereby
stopped updating its access scanner is a real outcome, and `info` does not ask anybody
anything about it.

The sibling carries the identical decision in its own `WPSEC-PENDING.md`; whichever is
resolved first should settle both, because a fleet seeing one at `info` and the other at
`medium` for the same site condition is a console disagreeing with itself.

### Four session assertions do not test what they say they test

`sessions_from()` in `tests/test-sessions.php` read `$live_until` without receiving it — a
file-scope variable used inside a function — so every session it built carried
`'expiration' => null`. Four assertions that describe live sessions were exercising the
expired path instead, and PHP said so in a warning on every run that nobody read.

The variable is passed in now and the warning is gone. **The assertions still pass with the
sessions forced expired**, which is the part worth recording: they never depended on the
expiration in the first place, so what looked like a fixture bug is really a gap in
coverage. Whatever `findings()` does differently for a live session versus an expired one
is untested from here.


- **A removed site recovers on its own**, but only on its next fleet check — up to an
  hour, and longer on a site with no traffic. **WP-Cron only fires on requests**, so
  nothing here is predictable across 162 sites until a system cron runs
  `wp cron event run --due-now`. Nothing sets that up.
- **This repository is public.** Nothing here may name an affected site, in a commit
  message or a comment no less than in a document.
