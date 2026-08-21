# wordpress-access-quick-scan — what is still owed

Companion to `WPSEC-HANDOFF.md`, which is how the fleet transport works. This is what is
left. Ordered by what blocks what, not by size.

The console's own list is in `../hawkeye/WPSEC-PENDING.md` and is not repeated here.

**Last reviewed:** 2026-08-21

---

## 1. Blocks installing this anywhere else

### Nothing, as of 2026-08-21

Header and published release both read **0.8.6**. They must stay that way: the updater
serves the release zip, so a merge without a tag leaves every site on the previous build
while every screen says they are current. That happened once and cost five versions.

```
./build.sh
git tag v0.8.6 && git push origin v0.8.6
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
