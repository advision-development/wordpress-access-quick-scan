# wordpress-access-quick-scan — what is still owed

Companion to `WPSEC-HANDOFF.md`, which is how the fleet transport works. This is what is
left. Ordered by what blocks what, not by size.

The console's own list is in `../hawkeye/WPSEC-PENDING.md` and is not repeated here.

**Last reviewed:** 2026-08-22

---

## 1. Blocks installing this anywhere else

### A site that failed its first enrolment never asks again

**Found 2026-08-21 23:00–00:15, not fixed.** This is the first thing to do on Monday: it is
the difference between "install and forget" working and not, and it affects every site that
has ever had a bad minute.

`WPMQS_Fleet::enrol()` / `WPAQS_Fleet::enrol()` record `requested_at` **whether the POST
succeeded or not**:

```php
$response = self::post( '/enroll', ..., false );

self::remember( array(
    'requested_at' => time(),          // ← unconditional
    'last_error'   => $response['error'],
) );
```

One timeout, one DNS blip, one host firewall, one 500 — and `keep_up_with_fleet()` takes the
other branch for ever:

```php
if ( empty( $state['requested_at'] ) ) { self::enrol(); return; }
self::poll();                                   // for an enrolment that was never created
```

The console answers that poll honestly — `handleEnrolmentStatus` returns **404
`no-enrolment`** for a domain it has never heard of — and the site records the error and
polls again on the next check. For ever. Nothing on either side ever goes back to asking.

**Deactivating and reactivating does not clear it.** `requested_at` lives in the
`wpmqs_fleet` / `wpaqs_fleet` option, and deactivation only clears scheduled hooks. So the
usual remedy does nothing, which is what made this hard to see.

**It is the same fault as `pushed_at`**, which was fixed in 0.28.6 for exactly this reason —
recording an attempt as though it were a success. It was fixed in `push()` and left in
`enrol()`.

#### The fix, in two halves

The second half matters more than the first, because it is what unsticks sites that are
already stuck without anybody touching them.

1. **`enrol()` records `requested_at` only when the POST succeeded**, the way `push()`
   records `pushed_at`. A site that could not reach the console has not asked.
2. **A poll answered `no-enrolment` clears `requested_at`.** The console is stating a fact
   the site can act on: there is no such enrolment, so asking again is the correct next
   move. Every site currently stuck heals itself on its next fleet check.

Both halves need assertions that can fail. The shape to copy is the `pushed_at` block in
`tests/test-fleet.php`.

#### What has not been confirmed

Zero enrol requests reached the console in the three hours after five sites were updated by
hand and deactivated/reactivated. That is consistent with the bug above, and also with
WP-Cron never having fired on those sites at all.

**One check on one site settles it.** Open `Tools → Malware Quick Scan` and read the fleet
panel:

| What it says | Which it is |
|---|---|
| *"has asked to enrol and is waiting for somebody to approve it"* | The bug above. `requested_at` is set and the enrolment does not exist |
| *"has not asked to join a fleet console"* | Cron never ran. `Ask to enrol` will say why in the moment |

Press `Ask to enrol` either way: it is synchronous, it bypasses cron entirely, and it
reports its own error.

### Nothing, as of 2026-08-21

Header and published release both read **0.8.7**. They must stay that way: the updater
serves the release zip, so a merge without a tag leaves every site on the previous build
while every screen says they are current. That happened once and cost five versions.

```
./build.sh
git tag v0.8.7 && git push origin v0.8.7
```

Both plugins are released together. A fleet running one half of the pair reports half a
site, and the console shows the missing half as a plugin that was never installed.

### Auto-update is decided but not implemented### Auto-update is decided but not implemented

`WPAQS_Updater::never_automatically()` and `explain_no_auto_update()` still return the old
policy: a person presses every update. `CLAUDE.md` records why that is being reversed for
this fleet and what replaces it — 2FA on accounts that can publish, a protected
environment on the release workflow, required review before publishing.

**The two functions are replaced together**, or a screen keeps explaining a policy that
no longer holds. Neither the controls nor the code exist yet.

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

- **A removed site recovers on its own**, but only on its next fleet check — up to an
  hour, and longer on a site with no traffic. **WP-Cron only fires on requests**, so
  nothing here is predictable across 162 sites until a system cron runs
  `wp cron event run --due-now`. Nothing sets that up.
- **This repository is public.** Nothing here may name an affected site, in a commit
  message or a comment no less than in a document.
