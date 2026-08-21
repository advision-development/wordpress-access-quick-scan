# Handoff — the fleet transport

**Last worked:** 2026-08-21
**This repo's part:** everything this plugin does to reach the console in `../hawkeye`.

What is still owed is in `WPSEC-PENDING.md`. This file is how it works and why.

## Status

**Version 0.8.7** on `main` · **697 assertions, 0 failures** · `./build.sh` produces a
zip.

Installed and confirmed working end to end on one site, `wpsec.advision-dev.com`, on
2026-08-20: the plugin asked to enrol, it was approved in the console, it collected its
key, it reported, and the report arrived.

**Released as `v0.8.7`**, and the release workflow asserts the tag equals the header before
it publishes. The updater serves that zip, so the fleet is only as current as the last tag:
a merge without one leaves 162 sites on the previous build while every screen says they are
up to date.

## The whole flow, from a person's side

Install the plugin. That is all.

```
activation          schedules two events: the scan, and the fleet check
within the hour     the site asks to enrol, by itself
you approve         once, in the console
within the hour     it collects its key and reports
```

The two buttons on the fleet panel do nothing the schedule does not. They exist so
nobody has to wait.

**WP-Cron only fires on traffic.** A site with no visitors can take much longer than the
above, and a system cron running `wp cron event run --due-now` is what makes it
predictable across a fleet.

## What a fresh reader needs to know first

- **`class-fleet.php` and `class-fleet-verify.php` are copied to `../wordpress-malware-quick-scan`** and must
  stay byte-identical after prefix normalisation. `tests/test-shared.php` proves it by
  hash, and both repositories carry the same list, so changing one and not the other
  fails *this* repository's build. Change a shared file in both, then regenerate the
  hash list in both.
- **Those files derive every name they use.** A hardcoded `WPAQS_Report` survives the
  copy pointing at the wrong plugin, which is worse than not compiling.
- **The normalisation strips the bare prefix**, not the underscored one. `@package
  WPAQS` carries no underscore and survived the first copy naming the wrong plugin —
  consistently wrong in both, which a hash cannot see.

## Enrolment and reporting are not the scan's business

`wpaqs_fleet_check` is its own hourly event, scheduled unconditionally. It used to ride on
the scan's own event, and `reschedule()` clears that when somebody turns the daily scan
off — so a site with scanning disabled would never enrol, or would stop reporting if it
already had. In the console that reads as a site nobody has heard from, which is exactly
what a site with no plugin looks like.

Whether a site scans on a schedule and whether it belongs to a fleet are two different
questions. One must not answer the other.

**Hourly rather than daily, and that is about waiting rather than cost.** Both things it
does are waiting on something: a person approving, and a report nobody has received.
Asking once a day meant a site approved five minutes after its daily run waited most of
another day to find out, which reads as approving having not worked.

## Getting in and first reporting are one step

A handshake that gets in reports immediately. There is no scan to start —
reading live state *is* the read — so the handshake and the first report are one step.
The sibling has to run a scan at that point, which is the only reason it does more.

## Three properties the transport must keep

Pinned by `test-fleet.php`, and none of them is a preference:

- the key never reaches a URL — it would survive in every access log on the way
- TLS verification is never relaxed
- redirects are never followed

A site with no key cannot report; a console that cannot be reached records the error
and stops.

## What a refusal means

**`pushed_at` means sent, not attempted.** It used to be written on every attempt, so a
site whose first push failed looked like one that had reported — and the fleet check asks
exactly that before deciding whether to retry, so a single failure meant that report was
never sent again.

**A 401 un-enrols the site.** It is the console saying it does not know this key: the
site was removed from the fleet, or its key was revoked. Retrying is pointless and
staying enrolled is a lie. Forgetting puts the site back in the console's approval queue,
which is the only recovery that does not need somebody to log into the site.

**The install nonce survives that.** It identifies the installation rather than the
enrolment, and a fresh one would make a re-approval indistinguishable from a different
install at the same address.

**Every other refusal leaves the site enrolled.** A 400 is the console being unhappy with
one report, and leaving the fleet over that is a removal for a reason unrelated to
whether the site belongs in it.

## The one that was nearly a hole

`/wp-json/wpaqs/v1/verify` — the verification route is public and unauthenticated, and it
very nearly returned the install nonce in the clear. That nonce is what collects the key
from the console, so anyone able to reach a site could have enrolled as it. It returns a
**hash**; the console stores and compares hashes.

## The screen has a shape, and the shape is asserted

Three groups matching the sibling's: what is wrong, who has access, and the
settings. The fleet panel sat between the application passwords and the coverage list —
one configuration card wedged between two readings of the site. Both plugins are opened
by the same people on the same day, so the two screens are laid out the same way on
purpose.

`test-markup.php` reads the render block **with comments stripped** — the comment
explaining the order names the sections it orders, so an assertion reading the source
would pass on its own justification.

The section headings are not decoration. They are what stops the next section being
appended wherever the last one ended, which is how the old order was arrived at.

## Resuming

Nothing in this repo blocks the console's next step, which is its site detail screen.

What comes back here is **phase 5**: the plugin starts polling for signed commands.
`WPAQS_Actions` is already the shape those commands call and needs no further change —
the acting user is passed in rather than read from the session, precisely so a command
arriving with no logged-in user behaves the same way.

Everything outstanding is in `WPSEC-PENDING.md`.
