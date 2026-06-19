# Dumbouncer (WordPress plugin)

**Dumb bots bounce.** A proof-of-work spam gate for WordPress. No CAPTCHA, no third party, no tracking.

Every sender solves a small hashcash challenge before a submission is accepted:

- **Humans** - the browser solves it automatically. There is no CAPTCHA.
- **Intelligent agents** - the challenge states its own rules, so automated clients can solve it.
- **Dumb bots** - clients that POST without running the challenge are rejected.

It does not stop a client that chooses to run the solver. It imposes a per-message CPU cost that filters out cheap, high-volume bots.

## What it protects

| Target | Default | Notes |
| --- | --- | --- |
| `[dumbouncer_form]` shortcode | on | Built-in contact form, mails the recipient |
| Comments | on | WordPress core comment form |
| Contact Form 7 | on (if active) | Marks failed submissions as spam |
| WPForms | on (if active) | Blocks submission with an error |
| Login | off | Can lock users out if their JavaScript fails |
| Registration | off | Same caution as login |

Toggle each under **Settings -> Dumbouncer**.

## How it works

The work is hashcash: find a nonce so the first 4 bytes of `SHA-256(challenge + ":" + nonce)` are `<= target`. Finding it costs about `2^bits` hashes. Verifying it is one hash.

- The signing secret is generated on activation and stored in `wp_options`.
- The browser fetches a challenge from a REST endpoint (`/wp-json/dumbouncer/v1/challenge`) when the user starts interacting with a form, solves it, and fills three hidden fields. Because the challenge is fetched at interaction time, the plugin works behind full-page caches.
- On submission each integration verifies the proof server-side: the HMAC `sig` proves the challenge was issued here, an embedded timestamp proves freshness, one SHA-256 proves the work, and an atomic single-use marker proves it was not replayed.

## Files

| File | Purpose |
| --- | --- |
| `dumbouncer.php` | Plugin header, bootstrap, activation |
| `includes/class-dumbouncer-pow.php` | Hashcash core: issue, verify, single-use, cleanup |
| `includes/class-dumbouncer.php` | REST endpoints, assets, shortcode |
| `includes/class-dumbouncer-integrations.php` | Comments, CF7, WPForms, login, registration |
| `includes/class-dumbouncer-settings.php` | Settings screen |
| `assets/dumbouncer.js` | Browser solver |
| `assets/dumbouncer.css` | Shortcode form styling |

## Install

1. Copy the `dumbouncer` folder into `wp-content/plugins/` (or upload the zip via Plugins -> Add New -> Upload).
2. Activate. The secret is created automatically.
3. Optional: set the recipient, difficulty, and protected forms under Settings -> Dumbouncer.
4. Add `[dumbouncer_form title="Contact us"]` to any page.

Requirements: WordPress 5.6+, PHP 7.0+ (the `hash` extension, always available).

## License

CC0 1.0 Universal (public domain) - see [LICENSE](LICENSE).
