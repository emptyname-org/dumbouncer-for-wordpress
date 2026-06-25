<?php
/**
 * Layer 2: server-side gate, exercised over HTTP as an agent/bot would. Reads
 * config from environment (see tests/config.env, sourced by run.sh).
 *
 * Asserts, per integration: blind submit is blocked / self-announces, a valid
 * proof is accepted, replay/forged/tampered are rejected, and - crucially - a
 * submission that OMITS the dumbouncer_gate marker cannot bypass the gate.
 */
$BASE = getenv('BASE_URL') ?: 'http://127.0.0.1:8088';
$CF7  = getenv('CF7_FORM_ID');
$CPID = getenv('COMMENT_POST') ?: '1';
$WPF  = getenv('WPF_FORM_ID');
$WPFPAGE = getenv('WPF_PAGE');
$AUSER = getenv('ADMIN_USER') ?: 'admin';
$APASS = getenv('ADMIN_PASS') ?: 'admin12345';
$UA = 'Mozilla/5.0 (compatible; DumbouncerTest/1.0)';

$pass = 0; $fail = 0; $skip = 0;
function ck($n, $c) { global $pass, $fail; echo ($c ? 'PASS' : 'FAIL') . "  $n\n"; $c ? $pass++ : $fail++; }
function sk($n) { global $skip; echo "SKIP  $n\n"; $skip++; }

function http($u, $method = 'GET', $body = null, $ct = null) {
    global $UA;
    $h = "User-Agent: $UA\r\n" . ($ct ? "Content-Type: $ct\r\n" : '');
    $o = array('http' => array('method' => $method, 'header' => $h, 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 30));
    if ($body !== null) $o['http']['content'] = $body;
    $r = @file_get_contents($u, false, stream_context_create($o));
    $code = 0; foreach ($http_response_header ?? array() as $hh) if (preg_match('#^HTTP/\S+\s+(\d+)#', $hh, $m)) $code = (int) $m[1];
    return array($code, $r);
}
function form_post($u, $f) { return http($u, 'POST', http_build_query($f), 'application/x-www-form-urlencoded'); }
function mp_post($u, $f) {
    $b = 'b' . bin2hex(random_bytes(8)); $body = '';
    foreach ($f as $k => $v) { $body .= "--$b\r\nContent-Disposition: form-data; name=\"$k\"\r\n\r\n$v\r\n"; }
    $body .= "--$b--\r\n";
    return http($u, 'POST', $body, "multipart/form-data; boundary=$b");
}

// challenge URL discovered from a rendered page (works for any permalink structure)
$probe = $WPFPAGE ? "$BASE/?page_id=$WPFPAGE" : "$BASE/?p=$CPID";
list(, $pg) = http($probe);
if (!preg_match('#"challenge_url":"([^"]+)"#', $pg, $m)) { echo "FAIL  could not find challenge_url on $probe\n"; echo "\nagent: 0 passed, 1 failed\n"; exit(1); }
$CHURL = str_replace('\\/', '/', $m[1]);
// Shape-parse the prose challenge: token = 16hex:10digits, seal = 64hex, limit
// is the number after "less than" - exactly what dumbouncer.js does. Robust to
// the JSON-string envelope the REST/CF7 responses arrive in.
function challenge_values($body) {
    if (!preg_match('/[0-9a-f]{16}:[0-9]{10}/i', $body, $t)) return null;
    if (!preg_match('/[0-9a-f]{64}/i', $body, $s)) return null;
    if (!preg_match('/less than\s+([0-9]+)/i', $body, $l)) return null;
    return array('token' => $t[0], 'seal' => $s[0], 'limit' => (int) $l[1]);
}
function is_challenge($body) { return challenge_values($body) !== null; }
function solved($extra = array()) {
    global $CHURL;
    list(, $body) = http($CHURL);
    $c = challenge_values($body);
    if (!$c) return $extra;
    for ($n = 0; ; $n++) { if (hexdec(substr(hash('sha256', $c['token'] . ':' . $n), 0, 8)) < $c['limit']) break; }
    return array_merge($extra, array('a' => $c['token'], 'b' => $c['seal'], 'c' => (string) $n));
}

echo "== CF7 (server gate) ==\n";
if ($CF7) {
    $FB = str_replace('dumbouncer/v1/challenge', "contact-form-7/v1/contact-forms/$CF7/feedback", $CHURL);
    list(, $cpage) = http("$BASE/?page_id=" . (getenv('CF7_PAGE') ?: ''));
    $hid = array(); if (preg_match_all('#name="(_wpcf7[^"]*)"\s+value="([^"]*)"#', $cpage, $mm, PREG_SET_ORDER)) foreach ($mm as $x) $hid[$x[1]] = $x[2];
    $base = array_merge($hid, array('your-name' => 'A', 'your-email' => 'a@b.com', 'your-subject' => 'hi', 'your-message' => 'msg'));
    list(, $r) = mp_post($FB, $base);
    ck('CF7 blind submit -> self-announcing prose challenge', is_challenge($r));
    list(, $r) = mp_post($FB, solved($base)); ck('CF7 valid proof -> mail_sent', (json_decode($r, true)['status'] ?? '') === 'mail_sent');
    $s = solved($base); list(, $r1) = mp_post($FB, $s); list(, $r2) = mp_post($FB, $s);
    ck('CF7 replay same proof -> re-challenged', is_challenge($r2));
    $good = solved($base);
    list(, $r) = mp_post($FB, array_merge($good, array('b' => str_repeat('0', 64)))); ck('CF7 forged sig -> rejected', is_challenge($r));
    list(, $r) = mp_post($FB, array_merge($base, array('a' => $good['a'], 'b' => $good['b'], 'c' => '1'))); ck('CF7 wrong nonce -> rejected', is_challenge($r));
} else { sk('CF7 (no form id configured)'); }

echo "== Comments (server gate) ==\n";
$CP = "$BASE/wp-comments-post.php";
$cbase = array('comment_post_ID' => $CPID, 'author' => 'A', 'email' => 'a@b.com');
list($c1, ) = form_post($CP, array_merge($cbase, array('comment' => 'no marker no proof ' . bin2hex(random_bytes(2))))); // marker omitted
ck('comment blind (marker omitted, no proof) -> BLOCKED (not 302)', $c1 !== 302);
list($c2, ) = form_post($CP, solved(array_merge($cbase, array('comment' => 'valid ' . bin2hex(random_bytes(2))))));
ck('comment valid proof (no marker) -> accepted (302)', $c2 === 302);
$cs = solved(array_merge($cbase, array('comment' => 'replay ' . bin2hex(random_bytes(2)))));
form_post($CP, $cs); list($c3, ) = form_post($CP, $cs);
ck('comment replay same proof -> blocked', $c3 !== 302);

echo "== WPForms (server gate) ==\n";
if ($WPF) {
    $AJAX = "$BASE/wp-admin/admin-ajax.php";
    list(, $wpage) = http("$BASE/?page_id=$WPFPAGE");
    $whid = array(); if (preg_match_all('#<input type="hidden" name="(wpforms\[[^"]+\])" value="([^"]*)"#', $wpage, $mm, PREG_SET_ORDER)) foreach ($mm as $x) $whid[$x[1]] = $x[2];
    $sn = 'wpforms[submit]'; $sv = 'wpforms-submit-' . $WPF; if (preg_match('#name="(wpforms\[submit\])" value="([^"]*)"#', $wpage, $sm)) { $sn = $sm[1]; $sv = $sm[2]; }
    $wf = array_merge($whid, array('wpforms[fields][0]' => 'A', 'wpforms[fields][1]' => 'a@b.com', 'wpforms[fields][2]' => 'msg', $sn => $sv, 'action' => 'wpforms_submit'));
    list(, $r) = form_post($AJAX, $wf); ck('WPForms AJAX blind (marker omitted) -> rejected', !(json_decode($r, true)['success'] ?? false));
    list(, $r) = form_post($AJAX, solved($wf)); ck('WPForms AJAX valid proof -> success', (json_decode($r, true)['success'] ?? false) === true);
} else { sk('WPForms (not loaded - needs curl+dom - or no form id)'); }

echo "== Login (server gate) ==\n";
$L = "$BASE/wp-login.php";
list($lc1, $lr1) = form_post($L, array('log' => $AUSER, 'pwd' => $APASS, 'wp-submit' => 'Log In')); // no proof
ck('login correct creds, no proof -> BLOCKED', stripos($lr1, 'Proof-of-work check failed') !== false && $lc1 !== 302);
list($lc2, ) = form_post($L, solved(array('log' => $AUSER, 'pwd' => $APASS, 'wp-submit' => 'Log In')));
ck('login correct creds + valid proof -> 302 (logged in)', $lc2 === 302);
list($lc3, $lr3) = form_post($L, solved(array('log' => $AUSER, 'pwd' => 'WRONG-' . bin2hex(random_bytes(2)), 'wp-submit' => 'Log In')));
ck('login wrong password + valid proof -> WP rejects, not the gate (transparent)',
   $lc3 !== 302 && stripos($lr3, 'Proof-of-work') === false && (bool) preg_match('/incorrect|not valid|unknown|isn.t correct/i', $lr3));

echo "== Registration (server gate) ==\n";
$RG = "$BASE/wp-login.php?action=register"; $u = 'dbo' . substr(bin2hex(random_bytes(4)), 0, 6);
list(, $rr1) = form_post($RG, array('user_login' => $u, 'user_email' => "$u@example.com", 'wp-submit' => 'Register')); // no proof
ck('register no proof -> BLOCKED', stripos($rr1, 'Proof-of-work check failed') !== false);
list($rc2, $rr2) = form_post($RG, solved(array('user_login' => $u, 'user_email' => "$u@example.com", 'wp-submit' => 'Register')));
ck('register valid proof -> success', stripos($rc2 === 302 ? '' : $rr2, 'registration complete') !== false || $rc2 === 302 || stripos($rr2 . '', 'checkemail') !== false);
list($rc3, $rr3) = form_post($RG, solved(array('user_login' => $AUSER, 'user_email' => 'dupe@example.com', 'wp-submit' => 'Register')));
ck('register duplicate username + valid proof -> WP rejects, not the gate (transparent)',
   stripos($rr3, 'Proof-of-work') === false && (bool) preg_match('/already registered|already in use/i', $rr3));

echo "\nagent: $pass passed, $fail failed, $skip skipped\n";
exit($fail ? 1 : 0);
