# wordpress-access-quick-scan — what is still owed

Companion to `WPSEC-HANDOFF.md`, which is how the fleet transport works. This is what is
left. Ordered by what blocks what, not by size.

The console's own list is in `../hawkeye/WPSEC-PENDING.md` and is not repeated here.

**Last reviewed:** 2026-08-25 (0.11.0 built and installed on two sites, not tagged)

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

### 0.11.0 is built and installed, and not tagged

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
