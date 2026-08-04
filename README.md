# WordPress Access Quick Scan

Answers one question: **who has access to this site right now, and does any of it look
wrong?**

It lists every account with the capabilities it actually holds, every live session with the
IP and the user agent it was opened from, and every application password with when and
where it was last used. Reading the screen changes nothing — it reads the database live
every time you open it and keeps nothing.

This is not a security audit. It does not look at files, at WordPress core, or at hardening
settings. Its sibling, **WordPress Malware Quick Scan**, does the first two.

## Install

```bash
./build.sh
```

Produces a versioned zip in `dist/`. Install through **Plugins → Add New → Upload Plugin**,
activate, then open **Tools → Access Check**.

The build fails on a syntax error, on PHP 8-only syntax (the plugin targets PHP 7.4+), or on
a failing test.

## What it shows

**Who has access** — every account, newest first, with its role *and the capabilities
granted to it directly*. That second part is the reason this screen exists: `add_cap()`
writes capabilities into the same place WordPress stores role names, so a Subscriber who can
edit users still shows as Subscriber on the Users screen. Only capabilities that change the
site are called out — a direct grant of `read` is noise.

**Sessions** — WordPress records the IP, the user agent, the login time and the expiry of
every session in `session_tokens`. It is the closest thing to access history core keeps.

Expired ones are marked `expired` rather than hidden. WordPress removes a lapsed token only
when it next writes that meta, so an account that stopped signing in keeps them — which is
how a sign-in from two years ago appears. They are shown because an old sign-in from an
address nobody recognises is worth reading, and they are excluded from every claim about
what is open, including the set of addresses that makes an application password's use look
familiar.

**Application passwords** — core records `created`, `last_used` and `last_ip`. An
application password authenticates the REST API as its owner and bypasses the login form, so
an unaccounted one on an administrator is a way in that no password change closes.

**What changed, in order** — one list, newest first, assembled from the timestamps the
tables already carry: accounts created, sign-ins, application passwords created and used,
resets requested. Capped at 100 entries, and it says so when it is. The ordering is the
point: a sign-in from an unfamiliar address, then a password created, then that password
used somewhere else, is a takeover with its steps in order.

Your own session is marked *this is you*, so a list of live sessions does not leave you
guessing which one you would be ending.

## What stands out

Six findings, at `critical`, `high`, `medium` and `info`:

| Finding | Why |
|---|---|
| Capabilities that come from no role | The Users screen cannot show these. **Not proof of anything** — `add_cap()` is legitimate and plugins use it — so it is a shortlist to confirm |
| Registration open **and** new accounts privileged | Either alone is ordinary. Together, a stranger holds that role by filling in a form |
| A live session opened by something that is not a browser | `curl`, a scripting library, or no user agent at all. A person signing in does not produce this |
| An application password never used, or last used from an address the account has no session from | The first is a key nobody watches; the second is what both a server integration and a stolen credential look like |
| A password reset requested and never completed | `user_activation_key` still holds `time():hash`, so a link was issued and not used. On an administrator, either a locked-out colleague or an attempt — and one of the few dated events core keeps |
| An administrator registered in the last 30 days | Context for reading the list, not an accusation |

## What it cannot check

Stated on the screen too, because "nothing found" is not the same as "nothing there":

- **Failed logins.** WordPress core records none. There is no data to read.
- **Login history.** Only sessions still open. An expired or ended session leaves nothing.
- **Whether an address is suspicious.** Addresses are shown so you can recognise them. The
  plugin makes no network requests and holds no opinion about any of them.
- **Files, core, and hardening settings.** A different question and a different plugin.

## Sorting

Every table sorts by clicking a column header — application passwords by name, account, created
or last used, the account list by login or registration date. It happens on the server, so
sorting is a page reload and each link returns you to the section you were reading.

Sorting is on the underlying value rather than the text: a password that has never been used
sorts before every real date, because it holds no date at all.

## The actions

Both have to be pressed by a person, and both act on something confirmed to exist at that
moment.

**End an account's sessions.** Signs it out everywhere. Reversible by definition — it signs
in again with the same password, so change the password too if that is the concern.
Application passwords keep working. **Refused on your own account:** it would sign you out
of the screen you are working from.

**End one session.** Leaves every other session open, including your own. For an
administrator with a browser session and one opened by a script.

**Revoke an application password.** Not reversible; the secret is deleted. Anything using it
stops working until somebody issues a new one. Browser sessions are unaffected.

**Take a directly granted capability off an account.** The role is untouched — removing what a
role grants would be undone the moment WordPress read the role again. Reversible by granting it
again.

**Make new accounts Subscribers**, or **close registration**. Settings, not deletions;
Settings → General puts either back. On a network, registration is a network setting and the
screen says so rather than offering a button that would change nothing.

**Nothing here deletes an account, or anything it created.** `wp_delete_user()` reassigns or
destroys the account's posts, and those posts are the record of what it did.

## Tests

```bash
./tests/run.sh
```

Six harnesses, no WordPress install needed — the functions it touches are stubbed in
`tests/wp-stubs.php`.

Every rule ships with the benign case that must stay silent. A rule without a
false-positive test is not finished.

## Known limitations

- **Multisite is read but not fully modelled.** Both actions require
  `manage_network_users` there, but the account list is per-site.
- **The account list is capped** at 500, newest first. When the cap is reached the screen
  says so and names both numbers rather than truncating quietly.
- **No scheduling and no email.** Somebody opens the screen. If that turns out to be the
  gap, it is a small addition — but a daily message saying all is well is how people learn
  to filter the one that matters.
