# Dumbouncer — automated test plan

Run this for every release. It exercises all integrations across all client
types (browser with JS, browser without JS, automated agent, blind bot) and the
proof-of-work core, and asserts both that the gate **blocks** what it should and
that it is **transparent** to each host's own validation.

## Layers

| Layer | File | Needs a WP site? | What it covers |
| --- | --- | --- | --- |
| PoW core | `pow-core.php` | no (stubs WP) | hashcash issue/verify/spend/expiry/tamper/single-use |
| Server gate (agent/bot) | `agent.php` | yes | per-integration server enforcement over HTTP, incl. bypass attempts |
| Browser (human) | `browser.mjs` | yes + Playwright | real submit through each host's JS, JS-on/off, spinner, messages |

`run.sh` runs all three and prints a combined pass/fail.

## Prerequisites

- A throwaway WordPress site with **wp-cli**, reachable over HTTP. Pass its path
  and URL to `setup.sh`.
- Plugins available to install: **Contact Form 7**, **WPForms Lite**.
  - WPForms requires the **`curl` and `dom`** PHP extensions. If the site's PHP
    lacks them, WPForms will not load and its rows are reported **SKIP** (not
    fail). Install `php-curl php-xml` to include WPForms.
- For the browser layer: **Node** + **Playwright** with **Firefox**
  (`npm i playwright && npx playwright install firefox`).
- A mail catcher is optional; the suite uses each host's own success signal
  (HTTP status / AJAX `success` / confirmation element), not delivered mail.

## Running

```sh
# 1. provision the site (idempotent): installs plugins, creates test content,
#    enables every integration, writes tests/config.env with URLs + IDs.
bash tests/setup.sh /path/to/wordpress http://127.0.0.1:8088

# 2. run everything
bash tests/run.sh
```

Individual layers:
```sh
php  tests/pow-core.php
php  tests/agent.php          # reads tests/config.env
node tests/browser.mjs        # reads tests/config.env
```

## The matrix

For each protected form, three client types are tested:

- **Browser, JS on** — the solver runs; the host submits with a valid proof.
- **Browser, JS off** — no proof is produced; the gate must block.
- **Agent / bot (HTTP)** — no browser; tests the server gate directly.

### Per-integration scenarios

Legend: PASS = expected behaviour verified. "host-rejects" = the gate let the
request through and the *host* applied its own rule (proves transparency).

| # | Scenario | CF7 | Comments | WPForms | Login | Register |
|---|---|---|---|---|---|---|
| 1 | valid + JS on -> accepted | ✓ | ✓ | ✓ | ✓ (real creds) | ✓ (new user) |
| 2 | JS off (marker present, no proof) -> blocked | ✓ | ✓ | n/a¹ | ✓ | ✓ |
| 3 | **agent two-phase**: blind -> puzzle -> solve -> accepted | ✓ | ✓ | ✓ | — | — |
| 4 | **bypass: marker omitted, no proof -> blocked** | ✓² | ✓ | ✓ | ✓ | ✓ |
| 5 | replay same proof -> blocked | ✓ | ✓ | ✓ | — | — |
| 6 | forged sig / wrong nonce / tampered challenge -> blocked | ✓ | ✓ | ✓ | — | — |
| 7 | host's own validation is transparent (gate passed, host rejects) | invalid email, empty field | empty comment³ | required field | wrong password | duplicate user |
| 8 | spinner / feedback shown during the solve | spinner | (none⁴) | spinner+disabled | (none⁴) | (none⁴) |
| 9 | success/confirmation message is visible after submit | ✓ | n/a | confirmation | n/a | n/a |
| 10 | double-click during solve -> exactly one submission | ✓ | ✓ | ✓ | — | — |

¹ WPForms itself requires JavaScript and refuses no-JS submits, so a JS-off
WPForms POST never processes — there is no separate path to block.
² CF7's gate keys on the proof, never on the marker, so there is no marker to
omit; scenario 4 confirms a blind CF7 feedback POST returns the puzzle.
³ The default comment textarea is `required`, so the browser blocks an empty
comment natively before submit (verify: not submitted, no stuck state).
⁴ Native-POST forms (comments, login, register) reload the page; there is no
in-page async spinner. The busy flag still applies.

### PoW core (layer 1)

- issue() returns challenge/sig/target/bits + issued_at/expires_at/ttl (window correct).
- verify(correct) true; wrong nonce / forged sig / tampered challenge -> false.
- passes() true the first time, false on replay (single-use).
- a challenge older than the window is rejected (expiry).

## What "thorough" means here

Every cell of the matrix is a real client action with an asserted outcome, on a
real WordPress install, against the real host plugins — not mocks. The two
failure modes we most care about are both asserted for every integration:

1. **Bypass** — can an unauthenticated client get a submission through without a
   valid proof? (scenarios 2, 3-blind, 4, 5, 6). Must be NO.
2. **Regression / opacity** — does the gate break the host's own behaviour?
   (scenarios 1, 7, 9). Must be NO — valid submissions and the host's own
   validation/auth must work unchanged.

## Updating for new versions

- New integration -> add a column and fill scenarios 1-10.
- New host plugin version -> re-run; if a selector/endpoint changed, update the
  relevant block in `agent.php` / `browser.mjs` only.
- Keep `setup.sh` the single source of truth for IDs (it writes `config.env`);
  never hard-code page/form IDs in the test scripts.
