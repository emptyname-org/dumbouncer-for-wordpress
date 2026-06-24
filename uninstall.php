<?php
/**
 * Uninstall: remove every option Dumbouncer created, including the secret and
 * any leftover single-use markers.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

$dumbouncer_options = array(
    'dumbouncer_secret',
    'dumbouncer_bits',
    'dumbouncer_int_comments',
    'dumbouncer_int_cf7',
    'dumbouncer_int_wpforms',
    'dumbouncer_int_login',
    'dumbouncer_int_register',
);
foreach ($dumbouncer_options as $dumbouncer_option) {
    delete_option($dumbouncer_option);
}

// Drop any spent-challenge markers not yet pruned (one-time uninstall cleanup).
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'dumbouncer_spent_%'");

$dumbouncer_ts = wp_next_scheduled('dumbouncer_gc');
if ($dumbouncer_ts) {
    wp_unschedule_event($dumbouncer_ts, 'dumbouncer_gc');
}
