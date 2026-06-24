# Dumbouncer (AI friendly spam gate plugin for WordPress)

**Dumb bots bounce.** An AI friendly POW spam gate for WordPress. No CAPTCHA, no third party, no tracking.

Every sender solves a small hashcash challenge before a submission is accepted:

- **Humans** - the browser solves it automatically. There is no CAPTCHA.
- **Intelligent agents** - the challenge states its own rules, so automated clients can solve it.
- **Dumb bots** - clients that POST without running the challenge are rejected.

It does not stop a client that chooses to run the solver. It imposes a per-message CPU cost that filters out cheap, high-volume bots. It is a gate only - it provides no form of its own and never sends mail.

## What it protects

| Target | Default | Gated submission |
| --- | --- | --- |
| Comments | on | the core comment POST |
| Contact Form 7 | on (if active) | the REST feedback endpoint |
| WPForms | on (if active) | the admin-ajax submit |
| Login | off | can lock users out if their JavaScript fails |
| Registration | off | same caution as login |

Toggle each under **Settings -> Dumbouncer**. Dumbouncer only checks the proof of work; every form keeps its own settings and sends its own mail.

## How it works

The work is hashcash: find a nonce so the first 4 bytes of `SHA-256(challenge + ":" + nonce)` are `<= target`. Finding it costs about `2^bits` hashes; verifying it is one hash.

It is a challenge-on-submit exchange - the same one a blind client would drive by hand:

1. A form is submitted.
2. With no valid proof, the server answers with a self-describing **puzzle** (the challenge plus the formula to solve it) and does **not** process the submission.
3. The sender solves the puzzle and resubmits with the nonce.
4. The server verifies in O(1) - the HMAC `sig` proves it issued the challenge, an embedded timestamp proves freshness, one SHA-256 proves the work, and an atomic single-use marker proves it was not replayed - then lets the form process normally.

In a browser this is invisible: a small script intercepts the submit, runs steps 2-3 in the background, and re-fires the form's own submission with the proof attached - so the host plugin (Contact Form 7, WPForms, comments, login) submits once, normally, with a valid proof. The challenge is minted and solved at submit time, so the plugin works behind full-page caches and there is no stale-challenge or pre-solve race.

That same exchange is what makes it **agent friendly**: a client that submits without a proof is handed the puzzle and its rules, so any legitimate automated client can comply exactly as a browser does - without reading a line of JavaScript. Dumb bots that ignore the puzzle send no proof and bounce.

The signing secret is generated on activation and stored in `wp_options`.

## Files

| File | Purpose |
| --- | --- |
| `dumbouncer.php` | Plugin header, bootstrap, activation |
| `includes/class-dumbouncer-pow.php` | Hashcash core: issue, verify, single-use, cleanup |
| `includes/class-dumbouncer.php` | Challenge REST endpoint, solver asset, form-marker helper |
| `includes/class-dumbouncer-integrations.php` | Comments, CF7, WPForms, login, registration |
| `includes/class-dumbouncer-settings.php` | Settings screen |
| `assets/dumbouncer.js` | Browser solver |

## Install

1. Copy the `dumbouncer` folder into `wp-content/plugins/` (or upload the zip via Plugins -> Add New -> Upload).
2. Activate. The secret is created automatically, and comments plus contact forms are protected immediately.
3. Optional: set the difficulty and which forms to protect under Settings -> Dumbouncer.

Requirements: WordPress 5.6+, PHP 7.0+ (the `hash` extension, always available).

## License

CC0 1.0 Universal (public domain) - see [LICENSE](LICENSE).
