# Handoff — fleet transport

**Last worked:** 2026-08-20 (first cut)
**This repo's part:** phase 3's plugin half — **written, not yet installed anywhere.**

## Status

**Branch:** `feat/fleet-transport` · **version 0.8.1** · **665 assertions, 0 failures**
· `./build.sh` produces a zip.

The plugin can be enrolled with the fleet console in `../hawkeye` and report to it. It
does nothing until a person approves the enrolment there, and nothing at all until the
console is deployed — which it is not.

## What a fresh reader needs to know first

- **`class-fleet.php` and `class-fleet-verify.php` are copied to `../wordpress-malware-quick-scan`** and must
  stay byte-identical after prefix normalisation. `tests/test-shared.php` proves it by
  hash, and both repositories carry the same list, so changing one and not the other
  fails *this* repository's build. Change a shared file in both, then regenerate the
  hash list in both.
- **Those files derive every name they use.** A hardcoded `WPMQS_Report` survives the
  copy pointing at the wrong plugin, which is worse than not compiling.
- **The normalisation strips the bare prefix**, not the underscored one. `@package
  WPMQS` carries no underscore and survived the first copy naming the wrong plugin —
  consistently wrong in both, which a hash cannot see.

## Three properties the transport must keep

Pinned by `test-fleet.php`, and none of them is a preference:

- the key never reaches a URL — it would survive in every access log on the way
- TLS verification is never relaxed
- redirects are never followed

A site with no key cannot report; a console that cannot be reached records the error
and stops.

## The one that was nearly a hole

`/wp-json/wpaqs/v1/verify` — the verification route is public and
unauthenticated, and it very nearly returned the install nonce in the clear. That nonce
is what collects the key from the console, so anyone able to reach a site could have
enrolled as it. It returns a **hash**; the console stores and compares hashes.

## Resuming

Nothing outstanding in this repo for phase 3. What comes next is phase 5, where the
plugin starts polling for signed commands — and `WPMQS_Actions` / `WPAQS_Actions` are
already the shape those commands call, needing no further change.

**Still outstanding from earlier:** `never_automatically()` and
`explain_no_auto_update()` are unchanged and must be replaced together when the fleet
work makes auto-update real. `CLAUDE.md` records the decision; the code does not yet.
