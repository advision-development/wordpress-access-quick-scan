# Handoff — not started yet

**Last worked:** 2026-08-20 — read only. **No code in this repo has changed.**
**This repo's part:** phase 2b, then phases 3–6 alongside the sibling.

## Where this fits

162 self-owned WordPress sites run this plugin and its sibling
`wordpress-malware-quick-scan` (WPMQS). Both are per-site tools today. A console in
the `hawkeye` repo is being built to read all 162 in one screen, triage what changed,
and eventually run corrective actions remotely.

**Spec:** `../hawkeye/docs/superpowers/specs/2026-08-19-wp-fleet-security-console-design.md`
**Phase 1 (done):** `../hawkeye/HANDOFF.md`
**Phase 2 (done, sibling):** `../wordpress-malware-quick-scan/HANDOFF.md`

Read the sibling's handoff before starting here. This plugin gets the same treatment
and goes **second on purpose**: it has six actions rather than ten, they are shorter,
and they already express most refusals as a redirect rather than `wp_die()`. It is the
easier half and benefits from a pattern already proven.

## Status: untouched

`main`, clean. Nothing to resume mid-flight.

## What this repo will need, in order

### Phase 2b — the action logic extraction

Mirror of what the sibling **has now finished**. Read its `WPMQS_Actions` and its
`tests/test-actions.php` rather than only the plan — the pattern is settled and the
plan is one revision behind it in two places, noted below. The plan is still the
template for the reasoning:
`../wordpress-malware-quick-scan/docs/superpowers/plans/2026-08-19-action-logic-extraction.md`

`WPAQS_Controller` (`includes/class-controller.php`) registers six actions:

```
end_sessions  revoke_password  end_session
remove_capability  park_default_role  close_registration
```

Each becomes a method on a new `WPAQS_Actions`, returning the sibling's result
contract — `ok`, `changed`, `code`, `message`, `data`. The controller keeps
capability, nonce and request parsing, then delegates and translates. **The web path
must behave identically.**

**Three things the sibling learned that apply directly here:**

- **Group by the helper, not by the action.** The sibling's plan split actions in a way
  that would have left a report-membership helper copied across a commit boundary. Look
  at which of the six share a private helper and move those together.
- **The actor problem is wider than an audit of the handlers finds.** In the sibling it
  turned out three more places read the session than the plan named, two of them inside
  a collaborating class rather than in the controller. Grep for `current_user_can` and
  `get_current_user_id` in everything an action touches, not just in the controller.
- **Lock the pattern in the same branch.** The sibling's last commit makes
  `test-actions.php` fail if the controller performs an action itself, and fail if an
  endpoint stops delegating. Both were checked by mutation. Without that pair the
  refactor is a convention, and the next person to add an action will not know it.

Two things already known about this half:

- **`$actor` matters here too.** `end_session` checks
  `current_user_can( 'edit_user', $user_id )`, and `end_sessions` refuses the current
  user. Under cron those read 0 and break in opposite directions — one guard
  evaporates, the other blocks everything. Same semantics as the sibling settled:
  actor-dependent guards do not apply when `$actor === 0`; guards that never depended
  on the actor still do. `$actor` is required, never defaulted.
- **This plugin validates against live state, not a stored report.** That is the
  stronger arrangement for remote execution and needs no change: an application
  password is either on the account right now or it is not. Do not introduce a stored
  report to mirror the sibling.

### Phase 3 — transport

Three things this plugin does not have and will need:

1. **Cron.** There is no scheduler at all. One is needed for the scan-and-push and
   for the command poll.
2. **An export.** WPMQS has `to_export_array()` with assertions that it leaks no
   absolute paths, salts or passwords. This has no export function. Build one with
   the same assertions — and its data is *more* sensitive: logins, email addresses,
   session IPs.
3. **Storage and outbound network access.** Both are currently absent by design.

**Two statements in this repo stop being true and must be corrected in the same
change that makes them untrue:**

- `uninstall.php` removes nothing and says so. Once a key, a last-push marker and a
  replay cache are stored, their names belong there — and `test-uninstall.php`
  discovers names from the source, so it will fail until they are listed. That test
  failing is the guard working.
- `README.md` states the plugin makes no network requests, in the section about not
  holding an opinion on any IP address.

### The shared-file rule

Two active plugins declaring a class of the same name is a PHP fatal. Anything copied
from the sibling **stays byte-identical except for the prefix**:
`sed 's/WPAQS_/WPMQS_/g'` over the copy must `diff` clean against the original.

Today `tests/wp-stubs.php` is the only such file. Phase 3 adds the fleet transport
classes, which triples that surface. **A test must run that `sed | diff` and fail** —
until now the rule has been prose in `CLAUDE.md`, not a verification.

The two controllers are *not* shared files, so 2b is not bound by this. Only genuinely
copied files carry the rule.

## Decisions from the spec that land here

**Auto-update is enabled for this fleet**, reversing this repo's deliberate rule that
a person presses every update. At 162 self-owned sites, manual updating per release is
how the project dies and an out-of-date scanner reports green; the compensating
controls move to GitHub — 2FA, protected release environment, required review.

**`CLAUDE.md` must record the reversal rather than lose the original rule.** The
reasoning for "a person presses it" is sound and the next reader needs to see it was
changed knowingly. `never_automatically()` and `explain_no_auto_update()` are replaced
together, so no screen keeps explaining a policy that no longer holds. **Not done.**

**Nothing may name an affected site** — not in a commit, a comment or a pull request.
Site-specific data lives in Firestore and the console.

**PHP 7.4 is the floor** and is never executed on the dev machine; compatibility rests
entirely on the grep gate in `build.sh`. No `match`, enums, `?->`, `str_contains`.

## Verifying

```bash
./build.sh       # lint + PHP 7.4 gate + tests + zip
./tests/run.sh   # every harness
```

`build.sh` refuses to produce a zip if anything fails. Do not work around it.

## One gotcha inherited from the sibling's execution

**PHP silently ignores extra arguments** to user-defined functions. Adding `$actor` at
a call site does not make a test fail, so it cannot drive the implementation. When
threading the actor through, the forcing assertion has to be about behaviour — for
example that `$actor === 0` no longer produces the `self` refusal — not about the
call shape.
