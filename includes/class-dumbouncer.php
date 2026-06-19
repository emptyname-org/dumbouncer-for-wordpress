<?php
/**
 * Dumbouncer plugin bootstrap: REST endpoints, asset loading, the shortcode
 * contact form, and the hidden-field helper that every integration reuses.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Dumbouncer {

    private static $instance = null;
    private $enqueued = false;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init() {
        add_action('rest_api_init', array($this, 'register_routes'));
        add_shortcode('dumbouncer_form', array($this, 'shortcode_form'));

        // Assets: enqueued lazily by whatever needs them (shortcode, integrations,
        // login page) so pages with no protected form stay light.
        add_action('wp_enqueue_scripts', array($this, 'register_assets'));
        add_action('login_enqueue_scripts', array($this, 'register_assets'));

        // Wire the integrations the site has turned on.
        Dumbouncer_Integrations::init();

        // Background cleanup of spent-challenge markers.
        add_action('dumbouncer_gc', array('Dumbouncer_PoW', 'gc'));
    }

    /* ---------------------------------------------------------------- assets */

    public function register_assets() {
        if ($this->enqueued) {
            return;
        }
        $this->enqueued = true;

        wp_register_script(
            'dumbouncer',
            DUMBOUNCER_URL . 'assets/dumbouncer.js',
            array(),
            DUMBOUNCER_VERSION,
            true
        );
        wp_localize_script('dumbouncer', 'DUMBOUNCER', array(
            'challenge_url' => esc_url_raw(rest_url('dumbouncer/v1/challenge')),
            'submit_url'    => esc_url_raw(rest_url('dumbouncer/v1/submit')),
            'sending'       => __('Sending', 'dumbouncer'),
            'sent'          => __('Message sent', 'dumbouncer'),
            'failed'        => __('Could not send your message. Please try again.', 'dumbouncer'),
            'bad_email'     => __('Please enter a real email address.', 'dumbouncer'),
            'missing'       => __('Please enter an email and a message.', 'dumbouncer'),
        ));
        wp_register_style('dumbouncer', DUMBOUNCER_URL . 'assets/dumbouncer.css', array(), DUMBOUNCER_VERSION);
    }

    /** Integrations call this to make sure the script/style are on the page. */
    public function need_assets() {
        $this->register_assets();
        wp_enqueue_script('dumbouncer');
        wp_enqueue_style('dumbouncer');
    }

    /**
     * The three hidden fields every protected form carries. Values start empty.
     * The browser solves a challenge and fills them before submission.
     * dumbouncer_nonce is the hashcash proof-of-work nonce (an integer the
     * browser finds), not a WordPress security nonce.
     */
    public function hidden_fields() {
        $this->need_assets();
        return '<input type="hidden" name="dumbouncer_challenge" value="" autocomplete="off" class="dumbouncer-field">'
             . '<input type="hidden" name="dumbouncer_sig" value="" autocomplete="off" class="dumbouncer-field">'
             . '<input type="hidden" name="dumbouncer_nonce" value="" autocomplete="off" class="dumbouncer-field">';
    }

    /* ------------------------------------------------------------- REST API */

    public function register_routes() {
        // Both routes are intentionally public (permission_callback __return_true):
        // a contact form must work for logged-out visitors. The /submit endpoint
        // is open by design, but every submission is gated by the proof of work
        // (verify + single-use spend) before any mail is sent - that PoW cost is
        // the abuse protection in place of an authentication check.
        register_rest_route('dumbouncer/v1', '/challenge', array(
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => array($this, 'rest_challenge'),
        ));
        register_rest_route('dumbouncer/v1', '/submit', array(
            'methods'             => 'POST',
            'permission_callback' => '__return_true',
            'callback'            => array($this, 'rest_submit'),
        ));
    }

    public function rest_challenge() {
        nocache_headers();
        return rest_ensure_response(Dumbouncer_PoW::issue());
    }

    /** Shortcode-form handler: verify the proof, then mail the message. */
    public function rest_submit(WP_REST_Request $req) {
        nocache_headers();
        $p = $req->get_params();

        $challenge = isset($p['dumbouncer_challenge']) ? (string) $p['dumbouncer_challenge'] : '';
        $sig       = isset($p['dumbouncer_sig'])       ? (string) $p['dumbouncer_sig']       : '';
        $nonce     = isset($p['dumbouncer_nonce'])     ? (string) $p['dumbouncer_nonce']     : '';

        // No valid (unused) proof -> hand back a fresh challenge to solve, like
        // the standalone handler does. The client solves it and re-POSTs.
        if (!Dumbouncer_PoW::passes($challenge, $sig, $nonce)) {
            $c = Dumbouncer_PoW::issue();
            $c['need_proof'] = true;
            $c['howto'] = 'Find nonce per "formula", then re-POST with '
                        . 'dumbouncer_challenge and dumbouncer_sig unchanged plus dumbouncer_nonce.';
            return rest_ensure_response($c);
        }

        $name    = sanitize_text_field((string) ($p['name'] ?? ''));
        $email   = sanitize_email((string) ($p['email'] ?? ''));
        $message = sanitize_textarea_field((string) ($p['message'] ?? ''));

        if ($email === '' || $message === '') {
            return rest_ensure_response(array('code' => 4, 'message' => 'missing-field'));
        }
        if (!is_email($email)) {
            return rest_ensure_response(array('code' => 3, 'message' => 'bad-email'));
        }

        $to      = get_option('dumbouncer_recipient', '');
        if (!is_email($to)) {
            $to = get_option('admin_email');
        }
        $prefix  = (string) get_option('dumbouncer_subject', '[contact] ');
        $subject = $prefix . ($name !== '' ? $name : '(no name)');
        $body    = "Name: {$name}\nEmail: {$email}\n\nMessage:\n\n{$message}\n";
        $headers = array('Reply-To: ' . $email);

        $ok = wp_mail($to, $subject, $body, $headers);
        return rest_ensure_response(array(
            'code'    => $ok ? 1 : 2,
            'message' => $ok ? 'sent' : 'mail-failed',
        ));
    }

    /* ----------------------------------------------------------- shortcode */

    public function shortcode_form($atts = array()) {
        $this->need_assets();
        $atts = shortcode_atts(array(
            'title'  => '',
            'button' => __('Send', 'dumbouncer'),
        ), $atts, 'dumbouncer_form');

        ob_start();
        ?>
        <form class="dumbouncer-form" method="post">
            <?php if ($atts['title'] !== '') : ?>
                <h3 class="dumbouncer-title"><?php echo esc_html($atts['title']); ?></h3>
            <?php endif; ?>
            <input type="text" name="name" placeholder="<?php esc_attr_e('Your name', 'dumbouncer'); ?>">
            <input type="text" name="email" placeholder="<?php esc_attr_e('Your email', 'dumbouncer'); ?>">
            <textarea name="message" placeholder="<?php esc_attr_e('Your message', 'dumbouncer'); ?>"></textarea>
            <?php echo $this->hidden_fields(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
            <button type="submit"><?php echo esc_html($atts['button']); ?></button>
            <div class="dumbouncer-status" aria-live="polite"></div>
        </form>
        <?php
        return ob_get_clean();
    }
}
