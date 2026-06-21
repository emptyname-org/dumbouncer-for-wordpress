<?php
/**
 * Integrations. Same model for every host form, mirroring the standalone:
 *
 *   1. Tag the form with a marker (a hidden dumbouncer_gate field). The browser
 *      watches for it and, on submit, solves a fresh challenge and injects the
 *      proof before the host's real submission goes out - so the host always
 *      submits WITH a valid proof, in one request.
 *   2. Gate the host's submit endpoint server-side: a submission with no valid,
 *      unused proof gets the self-describing puzzle back and is not processed;
 *      a valid proof is spent (single use) and passed through untouched.
 *
 * Because the browser proves before the host submits, the puzzle round-trip is
 * only ever taken by a client that submits blind - i.e. an automated agent,
 * which reads the puzzle, solves it, and resubmits. That is the self-announcing
 * "agent friendly" path, for free.
 *
 * Comments, Contact Form 7, and WPForms default ON. Login and registration
 * default OFF (gating real authentication on client-side work can lock people
 * out if their JavaScript fails).
 */

if (!defined('ABSPATH')) {
    exit;
}

class Dumbouncer_Integrations {

    public static function on($key, $default = '1') {
        return get_option('dumbouncer_int_' . $key, $default) === '1';
    }

    public static function init() {

        /* ---- Comments (WordPress core) ---------------------------------- */
        if (self::on('comments')) {
            add_action('comment_form_top', array(__CLASS__, 'echo_marker'));
            add_action('pre_comment_on_post', array(__CLASS__, 'comment_gate'));
        }

        /* ---- Contact Form 7 (REST + fetch) ------------------------------ */
        if (self::on('cf7')) {
            add_filter('wpcf7_form_hidden_fields', array(__CLASS__, 'cf7_marker'));
            add_filter('wpcf7_form_elements', array(__CLASS__, 'mark_assets'));
            add_filter('rest_pre_dispatch', array(__CLASS__, 'cf7_gate'), 10, 3);
        }

        /* ---- WPForms (admin-ajax) --------------------------------------- */
        if (self::on('wpforms')) {
            add_action('wpforms_display_submit_before', array(__CLASS__, 'echo_marker'));
            add_action('wp_ajax_nopriv_wpforms_submit', array(__CLASS__, 'wpforms_gate'), 1);
            add_action('wp_ajax_wpforms_submit', array(__CLASS__, 'wpforms_gate'), 1);
        }

        /* ---- Login (default OFF) ---------------------------------------- */
        if (self::on('login', '')) {
            add_action('login_form', array(__CLASS__, 'echo_marker'));
            add_filter('authenticate', array(__CLASS__, 'check_login'), 30, 1);
        }

        /* ---- Registration (default OFF) --------------------------------- */
        if (self::on('register', '')) {
            add_action('register_form', array(__CLASS__, 'echo_marker'));
            add_filter('registration_errors', array(__CLASS__, 'check_register'), 10, 1);
        }
    }

    /* ----------------------------------------------------------- markers */

    public static function echo_marker() {
        echo Dumbouncer::instance()->marker(); // phpcs:ignore WordPress.Security.EscapeOutput
    }

    public static function mark_assets($elements) {
        Dumbouncer::instance()->need_assets();
        return $elements;
    }

    public static function cf7_marker($fields) {
        Dumbouncer::instance()->need_assets();
        $fields['dumbouncer_gate'] = '1';
        return $fields;
    }

    /* --------------------------------------------------------- proof check */

    /** True if $_POST carries a valid, unused proof (and spends it). */
    private static function post_proof_ok() {
        return Dumbouncer_PoW::passes_from_post();
    }

    /* ----------------------------------------------------------- comments */

    public static function comment_gate($comment_post_id) {
        // Only gate real front-end comment POSTs.
        if (is_admin() || empty($_POST['dumbouncer_gate'])) {
            return;
        }
        if (self::post_proof_ok()) {
            return; // valid -> let WordPress process the comment
        }
        // No proof -> hand back the puzzle and stop. A browser never reaches
        // here (it solved first); an agent reads this and resubmits.
        wp_send_json(array('dumbouncer' => Dumbouncer_PoW::puzzle()));
    }

    /* --------------------------------------------------------------- CF7 */

    public static function cf7_gate($result, $server, $request) {
        if ($result !== null) {
            return $result;
        }
        $route = $request->get_route();
        if ($request->get_method() !== 'POST'
            || strpos($route, '/contact-form-7/v1/contact-forms/') !== 0
            || substr($route, -9) !== '/feedback') {
            return $result;
        }
        $p = $request->get_params();
        $challenge = isset($p['dumbouncer_challenge']) ? (string) $p['dumbouncer_challenge'] : '';
        $sig       = isset($p['dumbouncer_sig'])       ? (string) $p['dumbouncer_sig']       : '';
        $nonce     = isset($p['dumbouncer_nonce'])     ? (string) $p['dumbouncer_nonce']     : '';
        if (Dumbouncer_PoW::verify($challenge, $sig, $nonce) && Dumbouncer_PoW::spend($challenge)) {
            return $result; // valid -> let CF7 handle it
        }
        return new WP_REST_Response(array('dumbouncer' => Dumbouncer_PoW::puzzle()), 200);
    }

    /* ----------------------------------------------------------- WPForms */

    public static function wpforms_gate() {
        // Runs before WPForms' own ajax handler (priority 1). Valid proof ->
        // return and let WPForms process. No proof -> send the puzzle and stop.
        if (empty($_POST['dumbouncer_gate'])) {
            return;
        }
        if (self::post_proof_ok()) {
            return;
        }
        wp_send_json(array('dumbouncer' => Dumbouncer_PoW::puzzle()));
    }

    /* ------------------------------------------------------- login / reg */

    public static function check_login($user) {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || empty($_POST['dumbouncer_gate'])) {
            return $user;
        }
        if (!self::post_proof_ok()) {
            return new WP_Error('dumbouncer_pow', __('Proof-of-work check failed. Reload the login page and try again.', 'dumbouncer'));
        }
        return $user;
    }

    public static function check_register($errors) {
        if (!self::post_proof_ok()) {
            $errors->add('dumbouncer_pow', __('Proof-of-work check failed. Reload the page and try again.', 'dumbouncer'));
        }
        return $errors;
    }
}
