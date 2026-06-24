/* Dumbouncer browser solver.
   Any form tagged with the hidden dumbouncer_gate field is gated. On submit we
   intercept, fetch a fresh challenge, solve the hashcash proof, inject the proof
   fields, and re-fire the form's own submit - so the host (Contact Form 7,
   WPForms, comments, login) submits once, WITH a valid proof, through its own
   normal flow. The challenge is minted and solved at submit time: no pre-solve
   race, no stale challenge. Same scheme as the standalone solver. No library. */
(function () {
  "use strict";

  if (typeof window.DUMBOUNCER === "undefined") { return; }
  var cfg = window.DUMBOUNCER;

  /* --- compact synchronous SHA-256, first 32 bits of the digest --- */
  var K = new Uint32Array([
    0x428a2f98,0x71374491,0xb5c0fbcf,0xe9b5dba5,0x3956c25b,0x59f111f1,0x923f82a4,0xab1c5ed5,
    0xd807aa98,0x12835b01,0x243185be,0x550c7dc3,0x72be5d74,0x80deb1fe,0x9bdc06a7,0xc19bf174,
    0xe49b69c1,0xefbe4786,0x0fc19dc6,0x240ca1cc,0x2de92c6f,0x4a7484aa,0x5cb0a9dc,0x76f988da,
    0x983e5152,0xa831c66d,0xb00327c8,0xbf597fc7,0xc6e00bf3,0xd5a79147,0x06ca6351,0x14292967,
    0x27b70a85,0x2e1b2138,0x4d2c6dfc,0x53380d13,0x650a7354,0x766a0abb,0x81c2c92e,0x92722c85,
    0xa2bfe8a1,0xa81a664b,0xc24b8b70,0xc76c51a3,0xd192e819,0xd6990624,0xf40e3585,0x106aa070,
    0x19a4c116,0x1e376c08,0x2748774c,0x34b0bcb5,0x391c0cb3,0x4ed8aa4a,0x5b9cca4f,0x682e6ff3,
    0x748f82ee,0x78a5636f,0x84c87814,0x8cc70208,0x90befffa,0xa4506ceb,0xbef9a3f7,0xc67178f2
  ]);
  var W = new Uint32Array(64);
  function sha256_first32(msg) {
    var len = msg.length, bitLen = len * 8;
    var total = ((len + 8) >> 6) * 64 + 64;
    var bytes = new Uint8Array(total);
    for (var i = 0; i < len; i++) { bytes[i] = msg.charCodeAt(i) & 0xff; }
    bytes[len] = 0x80;
    var hi = Math.floor(bitLen / 0x100000000), lo = bitLen >>> 0;
    bytes[total-8]=(hi>>>24)&255; bytes[total-7]=(hi>>>16)&255; bytes[total-6]=(hi>>>8)&255; bytes[total-5]=hi&255;
    bytes[total-4]=(lo>>>24)&255; bytes[total-3]=(lo>>>16)&255; bytes[total-2]=(lo>>>8)&255; bytes[total-1]=lo&255;
    var h0=0x6a09e667,h1=0xbb67ae85,h2=0x3c6ef372,h3=0xa54ff53a,h4=0x510e527f,h5=0x9b05688c,h6=0x1f83d9ab,h7=0x5be0cd19;
    for (var b = 0; b < total; b += 64) {
      for (var t = 0; t < 16; t++) { var j = b + t*4; W[t] = (bytes[j]<<24)|(bytes[j+1]<<16)|(bytes[j+2]<<8)|bytes[j+3]; }
      for (var t2 = 16; t2 < 64; t2++) {
        var x = W[t2-15], y = W[t2-2];
        var s0 = ((x>>>7)|(x<<25)) ^ ((x>>>18)|(x<<14)) ^ (x>>>3);
        var s1 = ((y>>>17)|(y<<15)) ^ ((y>>>19)|(y<<13)) ^ (y>>>10);
        W[t2] = (W[t2-16] + s0 + W[t2-7] + s1) | 0;
      }
      var a=h0,bb=h1,c=h2,d=h3,e=h4,f=h5,g=h6,hh=h7;
      for (var k = 0; k < 64; k++) {
        var S1 = ((e>>>6)|(e<<26)) ^ ((e>>>11)|(e<<21)) ^ ((e>>>25)|(e<<7));
        var chh = (e & f) ^ (~e & g);
        var t1 = (hh + S1 + chh + K[k] + W[k]) | 0;
        var S0 = ((a>>>2)|(a<<30)) ^ ((a>>>13)|(a<<19)) ^ ((a>>>22)|(a<<10));
        var maj = (a & bb) ^ (a & c) ^ (bb & c);
        var t22 = (S0 + maj) | 0;
        hh=g; g=f; f=e; e=(d+t1)|0; d=c; c=bb; bb=a; a=(t1+t22)|0;
      }
      h0=(h0+a)|0; h1=(h1+bb)|0; h2=(h2+c)|0; h3=(h3+d)|0; h4=(h4+e)|0; h5=(h5+f)|0; h6=(h6+g)|0; h7=(h7+hh)|0;
    }
    return h0 >>> 0;
  }

  function fetchChallenge(cb) {
    var url = cfg.challenge_url;
    // Prefer fetch; fall back to XMLHttpRequest where fetch is unavailable.
    if (typeof window.fetch === "function") {
      window.fetch(url, { credentials: "same-origin", cache: "no-store" })
        .then(function (r) { return r.json(); })
        .then(function (j) { cb(j && j.challenge ? j : null); })
        .catch(function () { cb(null); });
      return;
    }
    try {
      var xhr = new XMLHttpRequest();
      xhr.open("GET", url, true);
      xhr.withCredentials = true;
      xhr.onreadystatechange = function () {
        if (xhr.readyState !== 4) { return; }
        var j = null;
        try { j = JSON.parse(xhr.responseText); } catch (e) {}
        cb(j && j.challenge ? j : null);
      };
      xhr.send();
    } catch (e) { cb(null); }
  }

  function solve(ch, cb) {
    var target = ch.target >>> 0;
    var prefix = ch.challenge + ":";
    var nonce = 0;
    (function chunk() {
      var end = nonce + 5000;
      for (; nonce < end; nonce++) {
        if (sha256_first32(prefix + nonce) <= target) { cb(String(nonce)); return; }
      }
      setTimeout(chunk, 0);
    })();
  }

  function field(form, name) {
    var el = form.querySelector('input[name="' + name + '"]');
    if (!el) {
      el = document.createElement("input");
      el.type = "hidden"; el.name = name;
      form.appendChild(el);
    }
    return el;
  }
  function setProof(form, ch, nonce) {
    field(form, "dumbouncer_challenge").value = ch.challenge;
    field(form, "dumbouncer_sig").value = ch.sig;
    field(form, "dumbouncer_nonce").value = nonce;
  }

  function reSubmit(form, submitter) {
    if (typeof form.requestSubmit === "function") {
      try { form.requestSubmit(submitter || undefined); return; } catch (e) {}
    }
    // Native fallback (skips the host's submit listeners, but works for native
    // forms). Call the prototype method: a control named "submit" (e.g. the
    // comment form's button) shadows form.submit, so form.submit() would throw.
    try {
      if (window.HTMLFormElement && HTMLFormElement.prototype.submit) {
        HTMLFormElement.prototype.submit.call(form);
      } else {
        form.submit();
      }
    } catch (e) {}
  }

  // Put the host form into its own "submitting" state during the solve, so its
  // native spinner shows from the click. Returns a function that undoes it
  // (used only if the challenge fetch fails; otherwise the host takes over on
  // the re-fire and manages its own teardown).
  function showWorking(form) {
    var cls = form.className || "";
    // Contact Form 7: spinner shown purely by the "submitting" class on the form.
    if (/(^|\s)wpcf7-form(\s|$)/.test(cls)) {
      var prev = form.getAttribute("data-status");
      if (prev) { form.classList.remove(prev); }
      form.classList.add("submitting");
      form.setAttribute("data-status", "submitting");
      return function () {
        form.classList.remove("submitting");
        if (prev === null) { form.removeAttribute("data-status"); }
        else { form.classList.add(prev); form.setAttribute("data-status", prev); }
      };
    }
    // WPForms: mirror its full processing state from the click - swap the submit
    // text to the "Sending..." label (data-alt-text), disable the button, and
    // reveal the spinner. Otherwise the spinner shows but the text only changes
    // once WPForms takes over after the solve, which looks jerky.
    if (/(^|\s)wpforms-form(\s|$)/.test(cls)) {
      var btn = form.querySelector(".wpforms-submit");
      var spin = form.querySelector(".wpforms-submit-spinner");
      var prevText = null;
      if (btn) {
        var alt = btn.getAttribute("data-alt-text");
        if (alt) { prevText = btn.textContent; btn.textContent = alt; }
        btn.disabled = true;
      }
      if (spin) { spin.style.display = "inline-block"; }
      return function () {
        if (btn) { btn.disabled = false; if (prevText !== null) { btn.textContent = prevText; } }
        if (spin) { spin.style.display = "none"; }
      };
    }
    return function () {}; // native forms (comments, login): no async UI to show
  }

  // The host UI above is best-effort and built on third-party class/attribute
  // names that may change. These wrappers make sure that if any of it is absent
  // or throws, the gate still works - the form just submits without the spinner.
  function noop() {}
  function beginHostUi(form) {
    try { return showWorking(form) || noop; } catch (e) { return noop; }
  }
  function runTeardown(fn) {
    try { fn(); } catch (e) {}
  }

  function onSubmit(e) {
    var form = e.target;
    if (!form || form.nodeName !== "FORM" || !form.querySelector) { return; }
    if (!form.querySelector('input[name="dumbouncer_gate"]')) { return; } // not gated

    if (form.getAttribute("data-dumbouncer-ready") === "1") {
      // our own re-fire: hand control to the host and stop guarding
      form.removeAttribute("data-dumbouncer-ready");
      form.removeAttribute("data-dumbouncer-busy");
      return;
    }

    // stop the host from submitting this round
    e.preventDefault();
    if (e.stopImmediatePropagation) { e.stopImmediatePropagation(); }

    // busy flag: ignore extra clicks while a solve is already running
    if (form.getAttribute("data-dumbouncer-busy") === "1") { return; }
    form.setAttribute("data-dumbouncer-busy", "1");

    // show the host's own "submitting" UI (spinner) during the solve - best-effort
    var undoWorking = beginHostUi(form);

    var submitter = e.submitter;
    fetchChallenge(function (ch) {
      if (!ch) {
        // could not get a challenge: undo the working UI, let the user retry
        runTeardown(undoWorking);
        form.removeAttribute("data-dumbouncer-busy");
        return;
      }
      solve(ch, function (nonce) {
        setProof(form, ch, nonce);
        form.setAttribute("data-dumbouncer-ready", "1");
        reSubmit(form, submitter); // re-fires; the ready branch above lets it through to the host
      });
    });
  }

  // Capture phase so we run before the host's own (bubble-phase) submit handler.
  document.addEventListener("submit", onSubmit, true);
})();
