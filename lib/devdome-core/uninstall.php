<?php
/**
 * Shared-core uninstall coordination. Each DevDome plugin's uninstall.php calls
 * devdcorev1_uninstall_cleanup() with its OWN plugin basename. The shared-core artifacts
 * (the daily feed cron + the cached feed option) are removed only when the plugin being
 * uninstalled is the LAST DevDome plugin still installed — so removing one plugin never
 * orphans the core for the others. (The beacon flood-guard transients auto-expire on their
 * own, so there's nothing to sweep.)
 */

defined('ABSPATH') || exit;

if (!function_exists('devdcorev1_uninstall_cleanup')) {
    function devdcorev1_uninstall_cleanup($self_basename)
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        // Detect suite plugins dynamically (a hardcoded list goes stale as the suite
        // grows): any OTHER installed devdome-*/devdome-*.php plugin that vendors the
        // shared core still needs the shared artifacts.
        foreach (array_keys(get_plugins()) as $p) {
            if ($p === $self_basename) {
                continue;
            }
            if (!preg_match('#^devdome-[^/]+/devdome-[^/]+\.php$#', $p)) {
                continue;
            }
            if (is_dir(WP_PLUGIN_DIR . '/' . dirname($p) . '/lib/devdome-core')) {
                return; // another DevDome plugin still uses the shared core — keep it
            }
        }

        // Last one out — remove the shared-core artifacts.
        wp_clear_scheduled_hook('devdcorev1_refresh_feeds');
        delete_option('devdcorev1_feeds');
        delete_transient('devdcorev1_feed_init');

        // ...and the shared hub/account state written by the bundled hub.
        delete_option('devdcorev1_account_connected');
        delete_option('devdcorev1_connected_at');
        delete_option('devdcorev1_hub_connect_dismissed');
        delete_option('devdcorev1_connect_started');
        // The connection itself (core 1.6.1): identifiers, the site token, the cached account
        // and connection state, and every short-lived connect/beacon transient.
        foreach (array('devdcorev1_site_id', 'devdcorev1_site_token', 'devdcorev1_account_id', 'devdcorev1_account_email', 'devdcorev1_account', 'devdcorev1_conn_state', 'devdcorev1_conn_checked', 'devdcorev1_hub_catalog') as $opt) {
            delete_option($opt);
        }
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall sweep of this library's own transients.
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_devdcorev1\\_%' OR option_name LIKE '\\_transient\\_timeout\\_devdcorev1\\_%'");
    }
}
