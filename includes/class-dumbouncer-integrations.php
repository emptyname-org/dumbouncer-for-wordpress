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

        /* ---- Contact Form 7 --------------------------------------------- */
        // These hooks only fire when the host plugin renders or processes a
        // form, so registering them on the toggle alone is safe and avoids
        // plugin-load-order races (the host may define itself after us).
        if (self::on('cf7')) {
            add_filter('wpcf7_form_hidden_fields', array(__CLASS__, 'cf7_hidden_fields'));
            add_filter('wpcf7_spam', array(__CLASS__, 'cf7_spam'), 9);
            // Make sure our assets load wherever a CF7 form is rendered.
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

    public static function cf7_hidden_fields($fields) {
        Dumbouncer::instance()->need_assets();
        $fields['dumbouncer_challenge'] = '';
        $fields['dumbouncer_sig']       = '';
        $fields['dumbouncer_nonce']     = '';
        return $fields;
    }

    public static function mark_assets($elements) {
        Dumbouncer::instance()->need_assets();
        return $elements;
    }

    public static function cf7_spam($spam) {
        if ($spam) {
            return $spam; // already flagged by something else
        }
        return !Dumbouncer_PoW::passes_from_post();
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
