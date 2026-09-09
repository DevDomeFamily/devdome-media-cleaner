<?php
/**
 * Full data removal — runs only when the plugin is DELETED (not on deactivate).
 * Drops the custom tables, deletes options + the cached hub summary, clears scheduled hooks,
 * sweeps transients, loops multisite, coordinates shared-core cleanup, ends with wp_cache_flush().
 *
 * SAFETY: it does NOT silently destroy un-restored Recycle Bin files. The Recycle Bin directory is
 * removed ONLY if it is empty; otherwise it is left in place so a user who still has un-restored
 * media is never surprised by a permanent loss on uninstall.
 */

defined('WP_UNINSTALL_PLUGIN') || exit;
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- every query targets this plugin's own fixed-name tables ($wpdb->prefix . 'devdsame_*') or core tables filtered by hardcoded key allowlists; table names never contain user input and all values are bound through $wpdb->prepare() (IN() lists use counted %s placeholders built with array_fill()).

/** Per-site cleanup (called once per site on multisite, or once on single-site). */
function devdsame_uninstall_site()
{
    global $wpdb;
    $p = $wpdb->prefix . 'devdsame_';

    foreach (array('settings', 'protected', 'scans', 'scan_items', 'trash_batches', 'trash_items') as $t) {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal prefix + hardcoded table name; DROP TABLE cannot be prepared/cached.
        $wpdb->query("DROP TABLE IF EXISTS {$p}{$t}");
    }

    delete_option('devdsame_hub_summary');
    delete_option('devdsame_tick_lock');
    delete_option('devdsame_tick_key');
    delete_option('devdsame_rollback_stalls');

    // Sweep any leftover devdsame_* transients.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time uninstall transient sweep.
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_devdsame\_%' OR option_name LIKE '\_transient\_timeout\_devdsame\_%'");

    wp_clear_scheduled_hook('devdsame_scheduled_scan');
    wp_clear_scheduled_hook('devdsame_autodelete_sweep');
    wp_clear_scheduled_hook('devdsame_summary_refresh');
    wp_clear_scheduled_hook('devdsame_run_job');
    wp_clear_scheduled_hook('devdsame_metrics_send');

    // Remove the Recycle Bin directory ONLY if it is empty (never destroy un-restored files).
    $up = wp_get_upload_dir();
    $trash = trailingslashit($up['basedir']) . 'devdome-safe-trash';
    if (is_dir($trash)) {
        $entries = @scandir($trash);
        $only_guards = true;
        if (is_array($entries)) {
            foreach ($entries as $e) {
                if (in_array($e, array('.', '..', 'index.php', '.htaccess', 'web.config'), true)) {
                    continue;
                }
                $only_guards = false;
                break;
            }
        }
        if ($only_guards) {
            // Remove the guard files then the directory via WP_Filesystem.
            foreach (array('index.php', '.htaccess', 'web.config') as $g) {
                if (file_exists($trash . '/' . $g)) {
                    wp_delete_file($trash . '/' . $g);
                }
            }
            require_once ABSPATH . 'wp-admin/includes/file.php';
            global $wp_filesystem;
            if (WP_Filesystem()) {
                $wp_filesystem->rmdir($trash);
            }
        }
        // else: leave it — the user still has un-restored media in Recycle Bin.
    }
}

global $wpdb;
if (is_multisite()) {
    $devdsame_sites = get_sites(array('fields' => 'ids', 'number' => 0));
    foreach ($devdsame_sites as $blog_id) {
        switch_to_blog((int) $blog_id);
        devdsame_uninstall_site();
        restore_current_blog();
    }
} else {
    devdsame_uninstall_site();
}

// Coordinate shared-core cleanup (removes the core cron/option only if this is the LAST DevDome
// plugin still installed, so removing this one never orphans the core for the others).
require_once __DIR__ . '/lib/devdome-core/uninstall.php';
devdcorev1_uninstall_cleanup('devdome-safe-media-cleaner/devdome-safe-media-cleaner.php');

wp_cache_flush();
