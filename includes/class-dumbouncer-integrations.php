<?php
/**
 * Integrations. Each one does two things: inject Dumbouncer's hidden fields into
 * a form (the browser solves a challenge and fills them), and verify the proof
 * server-side when that form is submitted. They share Dumbouncer_PoW and the
 * hidden_fields() helper, so adding another host form is a small adapter.
 *
 * Every integration is individually switchable on the settings screen. Comments,
 * Contact Form 7, and WPForms default ON (they only block spam). Login and
 * registration default OFF, because gating real authentication on client-side
 * work can lock people out if their JavaScript fails.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Dumbouncer_Integrations {

    public static function on($key, $default = '1') {
        return get_option('dumbouncer_int_' . $key, $default) === '1';
    }

    public static function fields() {
        echo Dumbouncer::instance()->hidden_fields(); // phpcs:ignore WordPress.Security.EscapeOutput
    }

    public static function init() {

        /* ---- Comments (WordPress core) ---------------------------------- */
        if (self::on('comments')) {
            add_action('comment_form_after_fields', array(__CLASS__, 'fields'));
            add_action('comment_form_logged_in_after', array(__CLASS__, 'fields'));
            add_filter('preprocess_comment', array(__CLASS__, 'check_comment'));
        }

        /* ---- Contact Form 7 (challenge-on-submit) ----------------------- */
        // Gate the CF7 feedback REST route before CF7 runs. A submission with
        // no valid proof gets the puzzle back and is not processed; the solver
        // (browser JS or an agent) solves and resubmits, and the second request
        // carries a valid proof and is let through. Same two-phase exchange as
        // the standalone handler.
        if (self::on('cf7')) {
            add_filter('rest_pre_dispatch', array(__CLASS__, 'cf7_gate'), 10, 3);
            add_filter('wpcf7_form_elements', array(__CLASS__, 'mark_assets'));
        }

        /* ---- WPForms ---------------------------------------------------- */
        if (self::on('wpforms')) {
            add_action('wpforms_display_submit_before', array(__CLASS__, 'fields'));
            add_action('wpforms_process', array(__CLASS__, 'check_wpforms'), 10, 3);
        }

        /* ---- Login (default OFF) ---------------------------------------- */
        if (self::on('login', '')) {
            add_action('login_form', array(__CLASS__, 'fields'));
            add_filter('authenticate', array(__CLASS__, 'check_login'), 30, 1);
        }

        /* ---- Registration (default OFF) --------------------------------- */
        if (self::on('register', '')) {
            add_action('register_form', array(__CLASS__, 'fields'));
            add_filter('registration_errors', array(__CLASS__, 'check_register'), 10, 1);
        }
    }

    /* ----------------------------------------------------------- comments */

    public static function check_comment($commentdata) {
        // Only gate front-end comment POSTs, never programmatic inserts.
        if (is_admin() || empty($_POST['comment_post_ID'])) {
            return $commentdata;
        }
        if (!Dumbouncer_PoW::passes_from_post()) {
            wp_die(
                esc_html__('Spam check failed: missing or invalid proof of work. Please go back, reload, and try again with JavaScript enabled.', 'dumbouncer'),
                esc_html__('Comment blocked', 'dumbouncer'),
                array('response' => 403, 'back_link' => true)
            );
        }
        return $commentdata;
    }

    /* --------------------------------------------------------------- CF7 */

    /** Load the solver wherever a CF7 form is rendered. */
    public static function mark_assets($elements) {
        Dumbouncer::instance()->need_assets();
        return $elements;
    }

    /**
     * Intercept the CF7 feedback submission. No valid, unused proof -> return
     * the self-describing puzzle and let CF7 do nothing. Valid proof -> return
     * null so the request continues to CF7's own handler.
     */
    public static function cf7_gate($result, $server, $request) {
        if ($result !== null) {
            return $result; // a response is already set, leave it
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
            return $result; // valid and single-use -> let CF7 handle it
        }

        // No valid proof: hand back the puzzle. CF7 never runs for this request.
        $c = Dumbouncer_PoW::issue();
        $c['need_proof'] = true;
        $c['howto'] = 'Solve nonce per "formula", then resubmit this same form with '
                    . 'dumbouncer_challenge and dumbouncer_sig unchanged plus dumbouncer_nonce added.';
        return new WP_REST_Response(array('dumbouncer' => $c), 200);
    }

    /* ----------------------------------------------------------- WPForms */

    public static function check_wpforms($fields, $entry, $form_data) {
        if (Dumbouncer_PoW::passes_from_post()) {
            return;
        }
        $form_id = isset($form_data['id']) ? $form_data['id'] : 0;
        wpforms()->process->errors[$form_id]['header'] =
            esc_html__('Spam check failed. Please reload the page and try again.', 'dumbouncer');
    }

    /* ------------------------------------------------------- login / reg */

    public static function check_login($user) {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || empty($_POST['log'])) {
            return $user; // not an interactive login POST
        }
        if (!Dumbouncer_PoW::passes_from_post()) {
            return new WP_Error('dumbouncer_pow', __('Proof-of-work check failed. Reload the login page and try again.', 'dumbouncer'));
        }
        return $user;
    }

    public static function check_register($errors) {
        if (!Dumbouncer_PoW::passes_from_post()) {
            $errors->add('dumbouncer_pow', __('Proof-of-work check failed. Reload the page and try again.', 'dumbouncer'));
        }
        return $errors;
    }
}
