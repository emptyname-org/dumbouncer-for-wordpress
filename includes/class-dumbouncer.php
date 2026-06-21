<?php
/**
 * Dumbouncer plugin bootstrap: the proof-of-work challenge endpoint, the browser
 * solver asset, and the hidden-field helper that every integration reuses.
 *
 * Dumbouncer is a spam gate only. It does not send mail or provide its own form -
 * it verifies a proof of work on forms that already exist (comments, Contact
 * Form 7, WPForms, login/registration).
 */

if (!defined('ABSPATH')) {
    exit;
}

class Dumbouncer {

    private static $instance = null;
    private $registered = false;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init() {
        add_action('rest_api_init', array($this, 'register_routes'));

        // The solver script is enqueued lazily by whatever needs it (an
        // integration rendering a form, or the login page), so pages with no
        // protected form stay light.
        add_action('wp_enqueue_scripts', array($this, 'register_assets'));
        add_action('login_enqueue_scripts', array($this, 'register_assets'));

        // Wire the integrations the site has turned on.
        Dumbouncer_Integrations::init();

        // Background cleanup of spent-challenge markers.
        add_action('dumbouncer_gc', array('Dumbouncer_PoW', 'gc'));
    }

    /* ---------------------------------------------------------------- assets */

    public function register_assets() {
        if ($this->registered) {
            return;
        }
        $this->registered = true;

        wp_register_script(
            'dumbouncer',
            DUMBOUNCER_URL . 'assets/dumbouncer.js',
            array(),
            DUMBOUNCER_VERSION,
            true
        );
        wp_localize_script('dumbouncer', 'DUMBOUNCER', array(
            'challenge_url' => esc_url_raw(rest_url('dumbouncer/v1/challenge')),
        ));
    }

    /** Integrations call this to make sure the solver is on the page. */
    public function need_assets() {
        $this->register_assets();
        wp_enqueue_script('dumbouncer');
    }

    /**
     * A marker that tags a form as gated. The browser watches for forms carrying
     * it and, on submit, solves a fresh challenge and injects the proof fields
     * before the form's real submission goes out. The proof itself is never
     * pre-rendered - it is minted and solved at submit time.
     */
    public function marker() {
        $this->need_assets();
        return '<input type="hidden" name="dumbouncer_gate" value="1" autocomplete="off">';
    }

    /* ------------------------------------------------------------- REST API */

    public function register_routes() {
        // Public by design (permission_callback __return_true): a logged-out
        // visitor's browser needs to fetch a challenge to solve before
        // submitting whatever form it is protecting. Issuing a challenge is one
        // HMAC and reveals nothing - the gate is enforced on the host form's own
        // submission, where verify() + single-use spend() run.
        register_rest_route('dumbouncer/v1', '/challenge', array(
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => array($this, 'rest_challenge'),
        ));
    }

    public function rest_challenge() {
        nocache_headers();
        return rest_ensure_response(Dumbouncer_PoW::issue());
    }
}
