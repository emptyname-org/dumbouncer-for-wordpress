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

        /* ---- Contact Form 7 --------------------------------------------- */
        if (self::on('cf7')) {
            // cf7_marker adds the gate marker to the form AND loads the solver.
            add_filter('wpcf7_form_hidden_fields', array(__CLASS__, 'cf7_marker'));
            add_filter('rest_pre_dispatch', array(__CLASS__, 'cf7_gate'), 10, 3);
        }

        /* ---- WPForms ---------------------------------------------------- */
        if (self::on('wpforms')) {
            add_action('wpforms_display_submit_before', array(__CLASS__, 'echo_marker'));
            // Gate via wpforms_process, which fires for BOTH the ajax and the
            // non-ajax submit paths. Hooking only the ajax action let a plain
            // (no-JS) POST to the page be processed without any proof.
            add_action('wpforms_process', array(__CLASS__, 'wpforms_gate'), 10, 3);
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
        // Enforce on every front-end comment POST. Do NOT key on the
        // dumbouncer_gate marker - it is client-controlled, so a bot could omit
        // it to skip the gate. The proof is the only thing that decides.
        if (is_admin()) {
            return;
        }
        if (self::post_proof_ok()) {
            return; // valid -> let WordPress process the comment
        }
        // No proof -> hand back the prose challenge as plain text and stop. A
        // browser never reaches here (it solved first); an agent reads the
        // sentence and resubmits with the opaque fields a/b/c.
        header('Content-Type: text/plain; charset=utf-8');
        echo esc_html(Dumbouncer_PoW::puzzle_text());
        exit;
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
        $challenge = isset($p['a']) ? (string) $p['a'] : '';
        $sig       = isset($p['b']) ? (string) $p['b'] : '';
        $nonce     = isset($p['c']) ? (string) $p['c'] : '';
        if (Dumbouncer_PoW::passes($challenge, $sig, $nonce)) {
            return $result; // valid (and single-use spent) -> let CF7 handle it
        }
        // No proof -> return the prose challenge (a bare string, no machine
        // labels). A browser never hits this blind path; an agent reads it.
        return new WP_REST_Response(Dumbouncer_PoW::puzzle_text(), 200);
    }

    /* ----------------------------------------------------------- WPForms */

    public static function wpforms_gate($fields, $entry, $form_data) {
        // Fires inside WPForms processing for BOTH ajax and non-ajax submits.
        // Enforce on every submission (no marker check - it is client-controlled).
        if (self::post_proof_ok()) {
            return; // valid -> let WPForms continue
        }
        // Block via WPForms' own error channel. Resolve the process object
        // defensively (it is a magic __get property, so test with is_object, not
        // isset): if a future WPForms changes this, degrade (no gate) rather than
        // fatal the user's form.
        $proc = function_exists('wpforms') ? wpforms()->process : null;
        if (!is_object($proc)) {
            return;
        }
        $form_id = isset($form_data['id']) ? $form_data['id'] : 0;
        if (!empty($proc->errors[$form_id]['header'])) {
            return;
        }
        // Hand back the prose challenge through WPForms' own error channel: an
        // agent reads the formula and the opaque fields a/b/c here, while a
        // browser solved before submitting and never sees it.
        $proc->errors[$form_id]['header'] = Dumbouncer_PoW::puzzle_text();
    }

    /* ------------------------------------------------------- login / reg */

    public static function check_login($user) {
        // Gate interactive credential logins (the wp-login form sends log/pwd).
        // Do not key on the dumbouncer_gate marker (client-controlled); other
        // auth contexts (cookies, application passwords) do not POST log/pwd.
        if (sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
            return $user;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- pre-auth gate; the proof of work, not a nonce, is the check (see Dumbouncer_PoW::passes_from_post)
        if (!isset($_POST['log']) && !isset($_POST['pwd'])) {
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
