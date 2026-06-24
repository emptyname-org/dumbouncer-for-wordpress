<?php
/**
 * Hashcash proof-of-work core. SHA-256 partial preimage below a target,
 * Bitcoin-style. Issuing a challenge is one HMAC. Verifying a solution is one
 * SHA-256. No bignum, no extensions beyond the always-available hash extension.
 *
 * This is the same scheme as the standalone Dumbouncer handler, adapted to
 * WordPress storage: the secret lives in wp_options and single-use enforcement
 * uses an atomic option insert instead of a locked file.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Dumbouncer_PoW {

    /** Seconds a freshly issued challenge stays valid. */
    const WINDOW = 300;

    /**
     * Max decimal digits accepted for a nonce (bounds verify input).
     * NOTE: "nonce" here is the hashcash proof-of-work nonce - the integer the
     * browser searches for - NOT a WordPress security nonce (wp_create_nonce).
     */
    const MAXNONCE = 19;

    /** Difficulty in leading zero bits, clamped to a sane range. */
    public static function bits() {
        $b = (int) get_option('dumbouncer_bits', 20);
        if ($b < 8)  { $b = 8; }
        if ($b > 32) { $b = 32; }
        return $b;
    }

    /** Largest allowed value of the first 4 bytes of the digest. */
    public static function target() {
        return (2 ** (32 - self::bits())) - 1;
    }

    /** The HMAC secret, generated once on first use and stored autoloaded. */
    public static function secret() {
        $s = get_option('dumbouncer_secret', '');
        if (!is_string($s) || $s === '') {
            $s = bin2hex(random_bytes(32));
            // add_option is a no-op if another request created it first.
            add_option('dumbouncer_secret', $s, '', 'yes');
            $s = get_option('dumbouncer_secret', $s);
        }
        return $s;
    }

    public static function sign($challenge) {
        return hash_hmac('sha256', $challenge, self::secret());
    }

    /** Issue a fresh, signed, self-describing challenge. */
    public static function issue() {
        $now = time();
        $challenge = bin2hex(random_bytes(8)) . ':' . $now;
        return array(
            'challenge'  => $challenge,
            'sig'        => self::sign($challenge),
            'target'     => self::target(),
            'bits'       => self::bits(),
            'scheme'     => 'hashcash-sha256',
            'formula'    => 'find an integer nonce so that the first 4 bytes of '
                          . 'SHA-256(challenge + ":" + nonce), read as a big-endian '
                          . 'integer, are <= target',
            // Validity window, stated explicitly so a client need not guess what
            // the timestamp in "challenge" means. The trailing number there is
            // the issue time; this challenge is accepted until expires_at. These
            // fields are advisory - the server enforces the window itself.
            'issued_at'  => $now,
            'expires_at' => $now + self::WINDOW,
            'ttl'        => self::WINDOW,
        );
    }

    /** The self-describing puzzle returned to a submission that has no proof. */
    public static function puzzle() {
        $c = self::issue();
        $c['need_proof'] = true;
        $c['howto'] = 'Solve nonce per "formula", then resubmit this same form with '
                    . 'dumbouncer_challenge and dumbouncer_sig unchanged plus dumbouncer_nonce added. '
                    . 'This challenge is valid until "expires_at" (unix seconds); you may reuse it until '
                    . 'then, after which request a new one.';
        return $c;
    }

    /**
     * Verify a submitted (challenge, sig, nonce). One SHA-256.
     * Checks: we signed this challenge (timing-safe), it is still fresh, and the
     * first 4 bytes of SHA-256(challenge ":" nonce) are <= target.
     */
    public static function verify($challenge, $sig, $nonce) {
        if (!is_string($challenge) || !is_string($sig) || !is_string($nonce)) {
            return false;
        }
        if ($challenge === '' || $sig === '' || $nonce === '') {
            return false;
        }
        if (!ctype_digit($nonce) || strlen($nonce) > self::MAXNONCE) {
            return false;
        }
        if (!hash_equals(self::sign($challenge), $sig)) {
            return false;
        }
        $parts = explode(':', $challenge);
        $ts    = isset($parts[1]) ? (int) $parts[1] : 0;
        $now   = time();
        if ($ts <= 0 || ($now - $ts) > self::WINDOW || ($ts - $now) > 60) {
            return false;
        }
        $h = hash('sha256', $challenge . ':' . $nonce);
        return hexdec(substr($h, 0, 8)) <= self::target();
    }

    /**
     * Single-use enforcement. Returns true the FIRST time a challenge is seen,
     * false on any replay. add_option relies on the UNIQUE index on option_name,
     * so two concurrent replays cannot both win the INSERT. Markers are stored
     * non-autoloaded and pruned by the gc() cron.
     */
    public static function spend($challenge) {
        if (!is_string($challenge) || $challenge === '') {
            return false;
        }
        $key = 'dumbouncer_spent_' . hash('sha256', $challenge);
        return (bool) add_option($key, time(), '', 'no');
    }

    /** One call: the full server-side gate. */
    public static function passes($challenge, $sig, $nonce) {
        return self::verify($challenge, $sig, $nonce) && self::spend($challenge);
    }

    /** Read the three proof fields from $_POST and run the full gate. */
    public static function passes_from_post() {
        // No WP nonce is used or expected: the proof of work is itself the
        // anti-forgery check (HMAC signature + single-use spend), and a nonce
        // could not survive a full-page cache or an automated client. Inputs are
        // sanitized here, then validated cryptographically in verify().
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $challenge = isset($_POST['dumbouncer_challenge']) ? sanitize_text_field(wp_unslash($_POST['dumbouncer_challenge'])) : '';
        $sig       = isset($_POST['dumbouncer_sig'])       ? sanitize_text_field(wp_unslash($_POST['dumbouncer_sig']))       : '';
        $nonce     = isset($_POST['dumbouncer_nonce'])     ? sanitize_text_field(wp_unslash($_POST['dumbouncer_nonce']))     : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        return self::passes($challenge, $sig, $nonce);
    }

    /** Cron cleanup: drop spent markers older than the freshness window. */
    public static function gc() {
        global $wpdb;
        $cutoff = time() - self::WINDOW - 120;
        // Scheduled cleanup of single-use markers stored as options; a direct
        // LIKE query is the practical way and there is nothing to cache.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'dumbouncer_spent_%'"
        );
        if (!$rows) {
            return;
        }
        foreach ($rows as $r) {
            if ((int) $r->option_value < $cutoff) {
                delete_option($r->option_name);
            }
        }
    }
}
