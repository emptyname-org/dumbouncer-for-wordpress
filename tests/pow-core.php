<?php
/**
 * Layer 1: proof-of-work core. No WordPress - stubs the few functions the PoW
 * class uses (options in-memory, atomic add_option via the array). Run: php tests/pow-core.php
 */
define('ABSPATH', sys_get_temp_dir() . '/');
$GLOBALS['__opt'] = array();
function get_option($k, $d = false) { return array_key_exists($k, $GLOBALS['__opt']) ? $GLOBALS['__opt'][$k] : $d; }
function add_option($k, $v, $d = '', $a = 'yes') { if (array_key_exists($k, $GLOBALS['__opt'])) return false; $GLOBALS['__opt'][$k] = $v; return true; }
function delete_option($k) { unset($GLOBALS['__opt'][$k]); return true; }
function wp_unslash($v) { return $v; }

require __DIR__ . '/../includes/class-dumbouncer-pow.php';

$pass = 0; $fail = 0;
function ck($n, $c) { global $pass, $fail; echo ($c ? 'PASS' : 'FAIL') . "  $n\n"; $c ? $pass++ : $fail++; }
function solve($ch, $t) { for ($n = 0; $n < 80000000; $n++) { if (hexdec(substr(hash('sha256', $ch . ':' . $n), 0, 8)) <= $t) return (string) $n; } return null; }

$c = Dumbouncer_PoW::issue();
ck('issue() has challenge/sig/target/bits', isset($c['challenge'], $c['sig'], $c['target'], $c['bits']));
ck('issue() has issued_at/expires_at/ttl, window correct',
   isset($c['issued_at'], $c['expires_at'], $c['ttl']) && ($c['expires_at'] - $c['issued_at']) === Dumbouncer_PoW::WINDOW);
ck('target = 2^(32-bits)-1', $c['target'] === (2 ** (32 - $c['bits'])) - 1);

$nonce = solve($c['challenge'], $c['target']);
ck('solved a nonce', $nonce !== null);
ck('verify(correct) = true', Dumbouncer_PoW::verify($c['challenge'], $c['sig'], $nonce) === true);
ck('verify(wrong nonce) = false', Dumbouncer_PoW::verify($c['challenge'], $c['sig'], '1') === false);
ck('verify(forged sig) = false', Dumbouncer_PoW::verify($c['challenge'], str_repeat('0', 64), $nonce) === false);
ck('verify(tampered challenge) = false', Dumbouncer_PoW::verify($c['challenge'] . 'X', $c['sig'], $nonce) === false);
ck('verify(non-numeric nonce) = false', Dumbouncer_PoW::verify($c['challenge'], $c['sig'], 'abc') === false);

ck('passes() first time = true', Dumbouncer_PoW::passes($c['challenge'], $c['sig'], $nonce) === true);
ck('passes() replay = false (single-use)', Dumbouncer_PoW::passes($c['challenge'], $c['sig'], $nonce) === false);

// expiry: forge a challenge with an old timestamp but a valid sig
$old = bin2hex(random_bytes(8)) . ':' . (time() - Dumbouncer_PoW::WINDOW - 60);
$oldSig = Dumbouncer_PoW::sign($old);
$oldNonce = solve($old, Dumbouncer_PoW::target());
ck('verify(expired challenge) = false', Dumbouncer_PoW::verify($old, $oldSig, $oldNonce) === false);

// future skew guard
$future = bin2hex(random_bytes(8)) . ':' . (time() + 120);
$fSig = Dumbouncer_PoW::sign($future);
$fNonce = solve($future, Dumbouncer_PoW::target());
ck('verify(far-future challenge) = false', Dumbouncer_PoW::verify($future, $fSig, $fNonce) === false);

echo "\npow-core: $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
