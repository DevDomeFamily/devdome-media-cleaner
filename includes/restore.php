<?php
/**
 * Restore + permanent delete.
 *
 * Restore: move every file of a trash item back to its EXACT original relative path (verified
 * against the manifest with a sha1 integrity check), restore the attachment metadata flag, mark
 * restored_at. One-click undo restores a whole batch. Every restore target is realpath-validated
 * to land inside the uploads basedir.
 *
 * Permanent delete: only after explicit user confirmation OR the auto-delete cron past
 * permanent_delete_after. Removes the trashed copies (wp_delete_file) and the attachment record
 * (wp_delete_attachment), marks permanently_deleted_at. Auto-delete is OFF by default.
 */

defined('ABSPATH') || exit;
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- every query targets this plugin's own fixed-name tables ($wpdb->prefix . 'devdsame_*') or core tables filtered by hardcoded key allowlists; table names never contain user input and all values are bound through $wpdb->prepare() (IN() lists use counted %s placeholders built with array_fill()).

/** Restore ONE trash item. Returns true or WP_Error. */
function devdsame_restore_item($trash_item_id)
{
    global $wpdb;
    $ti = $wpdb->prefix . 'devdsame_trash_items';
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal trash_items read; id bound via prepare.
    $item = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$ti} WHERE id = %d", (int) $trash_item_id));
    if (!$item) {
        return new WP_Error('devdsame_no_item', __('Trash item not found.', 'devdome-safe-media-cleaner'));
    }
    if ($item->status !== 'trashed') {
        return new WP_Error('devdsame_not_trashed', __('Item is not restorable.', 'devdome-safe-media-cleaner'));
    }

    $meta = json_decode((string) $item->meta_json, true);
    $files = (is_array($meta) && !empty($meta['files'])) ? $meta['files'] : array();
    $fs = devdsame_fs();
    $basedir = devdsame_uploads_basedir();
    $restored_any = false;

    foreach ($files as $f) {
        $rel = isset($f['rel']) ? ltrim((string) $f['rel'], '/') : '';
        $trash = isset($f['trash']) ? (string) $f['trash'] : '';
        if ($rel === '' || $trash === '' || !$fs->exists($trash)) {
            continue;
        }
        // Integrity check: the trashed file must match its recorded sha1.
        if (!empty($f['sha1'])) {
            $now_hash = @sha1_file($trash);
            if ($now_hash && $now_hash !== $f['sha1']) {
                // Corrupted/partial — skip this file rather than restore bad data.
                continue;
            }
        }
        $dest = $basedir . '/' . $rel;
        // Path-traversal validation on the restore target.
        if (devdsame_validate_in_uploads($dest) === false) {
            continue;
        }
        $dest_dir = dirname($dest);
        if (!is_dir($dest_dir)) {
            wp_mkdir_p($dest_dir);
        }
        if ($fs->move($trash, $dest, true)) {
            $restored_any = true;
        }
    }

    if (!$restored_any) {
        // Fall back to the single primary path if the manifest files list was empty.
        if ($item->original_path && $fs->exists($item->trash_path)) {
            $dest = (string) $item->original_path;
            if (devdsame_validate_in_uploads($dest) !== false) {
                $dd = dirname($dest);
                if (!is_dir($dd)) {
                    wp_mkdir_p($dd);
                }
                if ($fs->move($item->trash_path, $dest, true)) {
                    $restored_any = true;
                }
            }
        }
    }

    if (!$restored_any) {
        return new WP_Error('devdsame_restore_failed', __('Could not restore the files.', 'devdome-safe-media-cleaner'));
    }

    // Restore the attachment metadata + clear our trashed flag.
    $attach_id = (int) $item->attachment_id;
    if ($attach_id > 0) {
        delete_post_meta($attach_id, '_devdsame_trashed');
        if (is_array($meta) && !empty($meta['attachment_meta'])) {
            wp_update_attachment_metadata($attach_id, $meta['attachment_meta']);
        }
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal trash_items update.
    $wpdb->update($ti, array('status' => 'restored', 'restored_at' => current_time('mysql')), array('id' => (int) $trash_item_id), array('%s', '%s'), array('%d'));

    // Flip the scan item back so dashboard counts stay honest without a rescan.
    $si = $wpdb->prefix . 'devdsame_scan_items';
    if ($attach_id > 0) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal scan_items update.
        $wpdb->update($si, array('status' => 'unused'), array('attachment_id' => $attach_id, 'status' => 'trashed'), array('%s'), array('%d', '%s'));
    } elseif ($item->original_path) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal scan_items update.
        $wpdb->update($si, array('status' => 'orphan'), array('file_path' => (string) $item->original_path, 'status' => 'trashed'), array('%s'), array('%s', '%s'));
    }

    return true;
}

/** Restore an entire batch (synchronous; the queue runner uses devdsame_restore_item per slice). */
function devdsame_restore_batch($batch_id)
{
    $rows = devdsame_batch_items_slice($batch_id, 'trashed', 100000);
    $ok = 0;
    $err = 0;
    foreach ((array) $rows as $r) {
        $res = devdsame_restore_item((int) $r->id);
        is_wp_error($res) ? $err++ : $ok++;
    }
    devdsame_finalize_restore_batch($batch_id);
    return array('restored' => $ok, 'errors' => $err);
}

/** Mark a batch restored when nothing trashed remains. */
function devdsame_finalize_restore_batch($batch_id)
{
    global $wpdb;
    $tb = $wpdb->prefix . 'devdsame_trash_batches';
    if (devdsame_batch_item_count($batch_id, 'trashed') === 0) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal trash_batches update.
        $wpdb->update($tb, array('status' => 'restored', 'restore_available' => 0), array('id' => (int) $batch_id), array('%s', '%d'), array('%d'));
    }
}

/** Permanently delete ONE trash item: remove the trashed copies + the attachment record. */
function devdsame_permanent_delete_item($trash_item_id)
{
    global $wpdb;
    $ti = $wpdb->prefix . 'devdsame_trash_items';
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal trash_items read; id bound via prepare.
    $item = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$ti} WHERE id = %d", (int) $trash_item_id));
    if (!$item || $item->status !== 'trashed') {
        return new WP_Error('devdsame_not_trashed', __('Item is not pending deletion.', 'devdome-safe-media-cleaner'));
    }

    $meta = json_decode((string) $item->meta_json, true);
    $files = (is_array($meta) && !empty($meta['files'])) ? $meta['files'] : array();

    foreach ($files as $f) {
        $trash = isset($f['trash']) ? (string) $f['trash'] : '';
        // Only ever delete inside the Recycle Bin folder.
        if ($trash !== '' && devdsame_validate_in_uploads($trash) !== false && strpos(str_replace('\\', '/', $trash), str_replace('\\', '/', devdsame_safe_trash_dir())) === 0) {
            if (is_file($trash)) {
                wp_delete_file($trash);
            }
        }
    }
    // Primary copy fallback.
    if ($item->trash_path && is_file($item->trash_path) && strpos(str_replace('\\', '/', $item->trash_path), str_replace('\\', '/', devdsame_safe_trash_dir())) === 0) {
        wp_delete_file($item->trash_path);
    }

    // Remove the attachment DB record (its physical files are already in trash / now deleted).
    $attach_id = (int) $item->attachment_id;
    if ($attach_id > 0 && get_post($attach_id)) {
        wp_delete_attachment($attach_id, true);
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal trash_items update.
    $wpdb->update($ti, array('status' => 'deleted', 'permanently_deleted_at' => current_time('mysql')), array('id' => (int) $trash_item_id), array('%s', '%s'), array('%d'));

    return true;
}

/** Permanently delete a whole batch (synchronous helper; queue runner does it per slice). */
function devdsame_permanent_delete_batch($batch_id)
{
    $rows = devdsame_batch_items_slice($batch_id, 'trashed', 100000);
    $ok = 0;
    $err = 0;
    foreach ((array) $rows as $r) {
        $res = devdsame_permanent_delete_item((int) $r->id);
        is_wp_error($res) ? $err++ : $ok++;
    }
    devdsame_finalize_delete_batch($batch_id);
    return array('deleted' => $ok, 'errors' => $err);
}

/** Mark a batch deleted + remove its (now-empty) trash dir. */
function devdsame_finalize_delete_batch($batch_id)
{
    global $wpdb;
    $tb = $wpdb->prefix . 'devdsame_trash_batches';
    if (devdsame_batch_item_count($batch_id, 'trashed') === 0) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal trash_batches update.
        $wpdb->update($tb, array('status' => 'deleted', 'restore_available' => 0), array('id' => (int) $batch_id), array('%s', '%d'), array('%d'));
        // Best-effort remove the empty per-batch trash directory.
        $dir = devdsame_safe_trash_dir($batch_id);
        if (is_dir($dir)) {
            $fs = devdsame_fs();
            $fs->delete($dir, true);
        }
    }
}

/**
 * Auto-delete sweep cron (disabled by default). Permanently deletes batches whose
 * permanent_delete_after has elapsed, in bounded slices, only when auto-delete is enabled.
 */
function devdsame_autodelete_sweep()
{
    $days = devdsame_get_int('auto_delete_after_days', 0);
    if ($days <= 0) {
        return; // OFF by default — never silently destroys user files.
    }
    global $wpdb;
    $tb = $wpdb->prefix . 'devdsame_trash_batches';
    $now = current_time('mysql');
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal trash_batches read for due batches; values bound via prepare.
    $batches = $wpdb->get_col($wpdb->prepare(
        "SELECT id FROM {$tb} WHERE status = 'trashed' AND permanent_delete_after IS NOT NULL AND permanent_delete_after <= %s ORDER BY id ASC LIMIT 5",
        $now
    ));
    foreach ((array) $batches as $bid) {
        $rows = devdsame_batch_items_slice((int) $bid, 'trashed', 200);
        foreach ((array) $rows as $r) {
            devdsame_permanent_delete_item((int) $r->id);
        }
        devdsame_finalize_delete_batch((int) $bid);
    }
    if (function_exists('devdsame_refresh_summary')) {
        devdsame_refresh_summary(devdsame_latest_scan_id(), (int) devdsame_get_int('cleanliness_score', 0));
    }
}
add_action('devdsame_autodelete_sweep', 'devdsame_autodelete_sweep');
