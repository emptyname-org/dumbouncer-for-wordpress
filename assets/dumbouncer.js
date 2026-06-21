/* Dumbouncer browser solver.
   For any form carrying Dumbouncer's hidden fields (Contact Form 7, WPForms,
   comments, login, register): when the user starts interacting, fetch a
   challenge, solve it, and fill the hidden fields, so the host form submits a
   valid proof with its own request. No work happens until the user engages, so
   the page stays cache-friendly. Hashcash, SHA-256 partial preimage. No library. */
(function () {
  "use strict";

  if (typeof window.DUMBOUNCER === "undefined") { return; }
  var cfg = window.DUMBOUNCER;
  var WINDOW = 300; // must match Dumbouncer_PoW::WINDOW (seconds)

  function each(list, fn) { Array.prototype.forEach.call(list || [], fn); }

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
    fetch(cfg.challenge_url, { credentials: "same-origin", cache: "no-store" })
      .then(function (r) { return r.json(); })
      .then(function (j) { cb(j && j.challenge ? j : null); })
      .catch(function () { cb(null); });
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

  function setFields(form, challenge, sig, nonce) {
    var c = form.querySelector('[name="dumbouncer_challenge"]');
    var s = form.querySelector('[name="dumbouncer_sig"]');
    var n = form.querySelector('[name="dumbouncer_nonce"]');
    if (c) { c.value = challenge; }
    if (s) { s.value = sig; }
    if (n) { n.value = nonce; }
  }

  /* Keep a freshly solved, unused proof in a form's hidden fields. */
  function manage(form) {
    var solving = false, ready = false, ts = 0;
    function ensure() {
      if (solving) { return; }
      if (ready && (Date.now() - ts) < (WINDOW - 30) * 1000) { return; }
      solving = true;
      fetchChallenge(function (ch) {
        if (!ch) { solving = false; return; }
        solve(ch, function (nonce) {
          setFields(form, ch.challenge, ch.sig, nonce);
          ready = true; ts = Date.now(); solving = false;
        });
      });
    }
    form.addEventListener("focusin", ensure);
    form.addEventListener("input", ensure);
    form.addEventListener("mousedown", function (e) {
      if (e.target && (e.target.type === "submit" || e.target.type === "button")) { ensure(); }
    });
    // a submitted proof is single-use, so prepare a fresh one for any retry
    form.addEventListener("submit", function () { ready = false; setTimeout(ensure, 50); });
  }

  /* Challenge-on-submit for fetch-based host forms (Contact Form 7, etc.):
     when a POST comes back as our puzzle, solve it and resubmit the SAME request
     with the proof appended, transparently to the host plugin's own client. */
  function installFetchGate() {
    if (!window.fetch || window.__dumbouncerFetch) { return; }
    window.__dumbouncerFetch = true;
    var orig = window.fetch;
    window.fetch = function (input, init) {
      return orig.apply(this, arguments).then(function (resp) {
        var method = (init && init.method) || (input && input.method) || "GET";
        if (String(method).toUpperCase() !== "POST") { return resp; }
        var ct = (resp.headers && resp.headers.get && resp.headers.get("content-type")) || "";
        if (ct.indexOf("application/json") < 0) { return resp; }
        return resp.clone().json().then(function (j) {
          var pz = j && j.dumbouncer;
          if (!pz || !pz.need_proof) { return resp; }
          var body = init && init.body;
          if (!body || typeof body.append !== "function") { return resp; } // need a FormData body
          return new Promise(function (resolve) {
            solve(pz, function (nonce) {
              body.append("dumbouncer_challenge", pz.challenge);
              body.append("dumbouncer_sig", pz.sig);
              body.append("dumbouncer_nonce", nonce);
              resolve(orig.call(window, input, init)); // retry once, with the proof
            });
          });
        }).catch(function () { return resp; });
      });
    };
  }
  installFetchGate();

  function boot() {
    var seen = [];
    each(document.querySelectorAll('input[name="dumbouncer_challenge"]'), function (inp) {
      var f = inp.form;
      if (!f || seen.indexOf(f) >= 0) { return; }
      seen.push(f); manage(f);
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot);
  } else {
    boot();
  }
})();
