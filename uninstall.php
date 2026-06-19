<?php
/**
 * Uninstall: remove every option Dumbouncer created, including the secret and
 * any leftover single-use markers.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

$options = array(
    'dumbouncer_secret',
    'dumbouncer_bits',
    'dumbouncer_int_comments',
    'dumbouncer_int_cf7',
    'dumbouncer_int_wpforms',
    'dumbouncer_int_login',
    'dumbouncer_int_register',
);
foreach ($options as $o) {
    delete_option($o);
}

// Drop any spent-challenge markers that have not yet been pruned.
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'dumbouncer_spent_%'");

$ts = wp_next_scheduled('dumbouncer_gc');
if ($ts) {
    wp_unschedule_event($ts, 'dumbouncer_gc');
}
