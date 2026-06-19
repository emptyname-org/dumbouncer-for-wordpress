=== Dumbouncer ===
Contributors: emptyname
Tags: spam, anti-spam, proof of work, hashcash, contact form, comments, captcha alternative
Requires at least: 5.6
Tested up to: 6.5
Requires PHP: 7.0
Stable tag: 1.0.0
License: CC0 1.0 Universal
License URI: https://creativecommons.org/publicdomain/zero/1.0/

Proof-of-work spam gate. Dumb bots bounce. Humans and agents solve the proof. No CAPTCHA, no third party, no tracking.

== Description ==

Dumbouncer makes every sender do a small, provable chunk of CPU work before a submission is accepted. It is cheap for one message and expensive at spam scale.

* Humans: the browser solves the challenge automatically. There is no CAPTCHA and nothing to click.
* Intelligent agents: the challenge states its own rules, so an automated client can read them and solve it too.
* Dumb bots: clients that POST without running the challenge send no proof and are rejected.

The work is hashcash, the scheme used for Bitcoin mining: find a nonce whose SHA-256 hash is below a target. The browser searches for it. The server verifies it in one hash.

No third party is contacted and no data leaves your server. The challenge is issued on submission rather than on page load, so the plugin works behind full-page caches.

= What it protects =

* The built-in contact form, via the shortcode `[dumbouncer_form]`
* Comment forms (WordPress core)
* Contact Form 7
* WPForms
* Login and registration forms (off by default)

Each can be turned on or off under Settings -> Dumbouncer.

= What it is not =

It does not stop an attacker who chooses to run the solver. It imposes a per-message CPU cost. It filters out the cheap, high-volume bots that never run anything.

== Installation ==

1. Upload the `dumbouncer` folder to `/wp-content/plugins/`, or install the zip from Plugins -> Add New -> Upload.
2. Activate the plugin. A signing secret is generated automatically.
3. Optional: visit Settings -> Dumbouncer to set the contact recipient, difficulty, and which forms to protect.
4. Add `[dumbouncer_form title="Contact us"]` to any page for a ready-made contact form.

== Frequently Asked Questions ==

= Does it need an account or API key? =

No. There is no third party. Everything runs on your own server.

= Will it work with my caching plugin? =

Yes. The challenge is requested at submit time, not baked into the cached page.

= How hard is the proof? =

Set the difficulty in bits under Settings -> Dumbouncer. 20 bits is about a million hashes, roughly half a second to a second in a browser.

= Should I protect the login form? =

Only if you understand the trade-off. If a visitor's JavaScript fails, they cannot solve the proof and cannot log in. It is off by default for that reason.

== Changelog ==

= 1.0.0 =
* Initial release: shortcode contact form, comments, Contact Form 7, WPForms, and login/registration integrations.
