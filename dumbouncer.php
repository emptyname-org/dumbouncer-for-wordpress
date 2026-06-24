<?php
/**
 * Plugin Name:       Dumbouncer
 * Plugin URI:        https://github.com/emptyname-org/dumbouncer-for-wordpress
 * Description:       Intelligent agent friendly proof-of-work spam gate. Protects comments, Contact Form 7, WPForms, and login/registration.
 * Version:           1.0.6
 * Requires at least: 5.6
 * Requires PHP:      7.0
 * Author:            emptyname
 * License:           CC0-1.0
 * License URI:       https://creativecommons.org/publicdomain/zero/1.0/
 * Text Domain:       dumbouncer
 */

if (!defined('ABSPATH')) {
    exit;
}

define('DUMBOUNCER_VERSION', '1.0.6');
define('DUMBOUNCER_FILE', __FILE__);
define('DUMBOUNCER_DIR', plugin_dir_path(__FILE__));
define('DUMBOUNCER_URL', plugin_dir_url(__FILE__));
define('DUMBOUNCER_HOME', 'https://github.com/emptyname-org/dumbouncer-for-wordpress');

require_once DUMBOUNCER_DIR . 'includes/class-dumbouncer-pow.php';
require_once DUMBOUNCER_DIR . 'includes/class-dumbouncer.php';
require_once DUMBOUNCER_DIR . 'includes/class-dumbouncer-integrations.php';
require_once DUMBOUNCER_DIR . 'includes/class-dumbouncer-settings.php';

add_action('plugins_loaded', function () {
    Dumbouncer::instance()->init();
    if (is_admin()) {
        Dumbouncer_Settings::init();
    }
});

/* ---- activation / deactivation ---------------------------------------- */

register_activation_hook(__FILE__, function () {
    // Generate the HMAC secret now so the first request never has to.
    Dumbouncer_PoW::secret();
    // Seed defaults without overwriting anything an admin already set.
    add_option('dumbouncer_bits', 20);
    add_option('dumbouncer_int_comments', '1');
    add_option('dumbouncer_int_cf7', '1');
    add_option('dumbouncer_int_wpforms', '1');
    add_option('dumbouncer_int_login', '');
    add_option('dumbouncer_int_register', '');
    if (!wp_next_scheduled('dumbouncer_gc')) {
        wp_schedule_event(time() + 3600, 'hourly', 'dumbouncer_gc');
    }
});

register_deactivation_hook(__FILE__, function () {
    $ts = wp_next_scheduled('dumbouncer_gc');
    if ($ts) {
        wp_unschedule_event($ts, 'dumbouncer_gc');
    }
});
