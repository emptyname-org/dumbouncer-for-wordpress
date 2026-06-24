=== Dumbouncer ===
Contributors: emptyname
Tags: spam, anti-spam, proof of work, captcha alternative, AI
Requires at least: 5.6
Tested up to: 7.0
Requires PHP: 7.0
Stable tag: 1.0.6
License: CC0-1.0
License URI: https://creativecommons.org/publicdomain/zero/1.0/

AI friendly POW spam gate. Protects comments, Contact Form 7, WPForms, and login/registration.

== Description ==

Dumbouncer is an AI friendly spam gate. It makes every sender do a small, provable chunk of CPU work before a submission is accepted - cheap for one message, expensive at spam scale. It does not provide its own form and it never sends mail. It protects the forms you already have.

* Humans: the browser solves the challenge automatically. There is no CAPTCHA and nothing to click.
* Intelligent agents: a submission with no proof is answered with the puzzle and its rules, so an automated client can read them, solve it, and resubmit - no JavaScript to reverse-engineer.
* Dumb bots: clients that POST without running the challenge send no proof and are rejected.

The work is hashcash, the scheme used for Bitcoin mining: find a nonce whose SHA-256 hash is below a target. The browser searches for it. The server verifies it in one hash.

No third party is contacted and no data leaves your server. The challenge is fetched and solved when the form is submitted, not baked into the page, so the plugin works behind full-page caches.

= What it protects =

* Comment forms (WordPress core)
* Contact Form 7
* WPForms
* Login and registration forms (off by default)

Each can be turned on or off under Settings -> Dumbouncer. Dumbouncer only checks the proof of work; every form keeps its own settings and sends its own mail.

= What it is not =

It is not a wall. A determined attacker who runs the solver gets through; it only prices out the cheap, high-volume bots that never run the work.

== Installation ==

1. Upload the `dumbouncer` folder to `/wp-content/plugins/`, or install the zip from Plugins -> Add New -> Upload.
2. Activate the plugin. A signing secret is generated automatically and comments plus contact forms are protected immediately.
3. Optional: visit Settings -> Dumbouncer to set the difficulty and which forms to protect.

== Frequently Asked Questions ==

= Does it need an account or API key? =

No. There is no third party. Everything runs on your own server.

= Can automated agents (AI) submit my forms? =

Yes, by design - that is what "agent friendly" means. A client that submits without a proof is handed the puzzle, which states its own rules, so a well-behaved automated client can solve it and resubmit just as a browser does. The per-submission CPU cost still applies, so it raises the price of spam without locking out legitimate automation the way a CAPTCHA does. Dumb bots that ignore the puzzle send no proof and are rejected.

= Will it work with my caching plugin? =

Yes. The challenge is requested at submit time, not baked into the cached page.

= How hard is the proof? =

Set the difficulty in bits under Settings -> Dumbouncer. 20 bits is about a million hashes, roughly half a second to a second in a browser.

= Should I protect the login form? =

Only if you understand the trade-off. If a visitor's JavaScript fails, they cannot solve the proof and cannot log in. It is off by default for that reason.

= Does it send or redirect any email? =

No. Dumbouncer only verifies the proof of work. Each protected form (Contact Form 7, WPForms, comment notifications) sends its own mail to wherever it is configured.

== Changelog ==

= 1.0.6 =
* Compliance: passes WordPress Plugin Check. Sanitize the proof inputs; annotate the intentional no-nonce gate (the proof of work is the anti-forgery check); prefix uninstall variables; mark the cleanup queries. License declared with the SPDX identifier CC0-1.0. No behaviour change.

= 1.0.5 =
* Robustness: the solver no longer depends on any third-party UI element existing. If a host's spinner or button hooks (e.g. WPForms .wpforms-submit-spinner) are renamed or removed, the gate still works - the form submits with a valid proof, just without the cosmetic spinner. The host UI step is wrapped so it can never abort the submit.
* Add an XMLHttpRequest fallback for the challenge fetch where window.fetch is unavailable.
* Native submit fallback uses HTMLFormElement.prototype.submit so a control named "submit" (the comment form) cannot shadow it.
* WPForms gate resolves the process object defensively, so a future WPForms internals change degrades to no-gate instead of fataling the form.

= 1.0.4 =
* WPForms: swap the submit button to its processing label ("Sending...") at the start of the solve, together with the spinner, so the whole processing state appears at once instead of the text changing only after the proof completes.

= 1.0.3 =
* WPForms: show the form's own spinner (disable submit + reveal spinner) during the proof-of-work solve, matching the Contact Form 7 behaviour, so there is immediate feedback on click.

= 1.0.2 =
* Security: enforce the proof on comments, WPForms, and login based on the proof itself, never on the client-controlled marker field (a bot could omit the marker to skip the gate). Contact Form 7 and registration were already enforced this way.
* WPForms: gate via the wpforms_process hook so both the ajax and any non-ajax processing are covered by one check.

= 1.0.1 =
* Contact Form 7: show the form's spinner during the proof-of-work solve, and fix the success/error message being hidden by a stale status class.
* Add a busy flag so a double-click during the solve cannot submit twice.
* Bump asset version so updated scripts are not served from cache.

= 1.0.0 =
* Initial release: comments, Contact Form 7, WPForms, and login/registration integrations.
